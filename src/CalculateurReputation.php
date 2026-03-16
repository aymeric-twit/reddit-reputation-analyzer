<?php

declare(strict_types=1);

/**
 * Calculateur de score de reputation pour une marque sur Reddit.
 *
 * Agrege les signaux de sentiment, engagement et influence pour produire
 * un score global de 0 a 100, ainsi que des recommandations actionnables.
 */
class CalculateurReputation
{
    private const float POIDS_SENTIMENT_POSITIF = 0.4;
    private const float POIDS_SENTIMENT_NEGATIF = 0.4;
    private const float POIDS_VOLUME = 0.1;
    private const float POIDS_INFLUENCE = 0.1;

    private const float SEUIL_VIRALITE = 100.0; // Score d'engagement minimum pour considerer comme viral
    private const float SEUIL_CONTROVERSE = 0.55; // Ratio upvote en dessous duquel c'est controverse

    /**
     * Calcule le score de reputation global a partir des publications et commentaires.
     *
     * Formule : Reputation = (pct_positif * 0.4) - (pct_negatif * 0.4)
     *           + (volume_positif_normalise * 0.1) + (influence_positive_normalisee * 0.1)
     * Resultat mis a l'echelle de 0 a 100.
     *
     * @param array<int, array<string, mixed>> $publications  Publications analysees avec sentiment
     * @param array<int, array<string, mixed>> $commentaires  Commentaires analyses avec sentiment
     * @return array{
     *     score_global: float,
     *     repartition: array{positif: int, neutre: int, negatif: int},
     *     pourcentages: array{positif: float, neutre: float, negatif: float},
     *     volume_total: int,
     *     score_engagement_moyen: float,
     *     details: array<string, mixed>
     * }
     */
    public function calculerScore(array $publications, array $commentaires = []): array
    {
        $toutesPublications = array_merge($publications, $commentaires);
        $volumeTotal = count($toutesPublications);

        if ($volumeTotal === 0) {
            return [
                'score_global'          => 50.0,
                'repartition'           => ['positif' => 0, 'neutre' => 0, 'negatif' => 0],
                'pourcentages'          => ['positif' => 0.0, 'neutre' => 0.0, 'negatif' => 0.0],
                'volume_total'          => 0,
                'score_engagement_moyen' => 0.0,
                'mode_donnees'          => 'limite',
                'details'               => [],
            ];
        }

        // Detecter si l'engagement est disponible (mode navigateur vs SerpAPI)
        $engagementDisponible = $this->aEngagementDisponible($toutesPublications);

        // Compter la repartition des sentiments
        $nbPositif = 0;
        $nbNeutre = 0;
        $nbNegatif = 0;
        $engagementTotal = 0.0;
        $influencePositive = 0.0;
        $influenceTotale = 0.0;

        foreach ($toutesPublications as $publication) {
            $labelSentiment = $publication['label_sentiment'] ?? 'neutre';

            match ($labelSentiment) {
                'positif' => $nbPositif++,
                'negatif' => $nbNegatif++,
                default   => $nbNeutre++,
            };

            $engagement = $this->calculerEngagement($publication);
            $engagementTotal += $engagement;

            if ($labelSentiment === 'positif') {
                $influencePositive += $engagement;
            }
            $influenceTotale += $engagement;
        }

        // Pourcentages
        $pctPositif = $nbPositif / $volumeTotal;
        $pctNegatif = $nbNegatif / $volumeTotal;
        $pctNeutre = $nbNeutre / $volumeTotal;

        // Normalisation du volume positif (logarithmique pour eviter les biais)
        $volumePositifNorm = $volumeTotal > 0
            ? min(1.0, log(1 + $nbPositif) / log(1 + $volumeTotal))
            : 0.0;

        // Normalisation de l'influence positive
        $influenceNorm = $influenceTotale > 0
            ? $influencePositive / $influenceTotale
            : 0.0;

        // Poids adaptatifs selon la disponibilite des donnees d'engagement
        if ($engagementDisponible) {
            // Mode complet (navigateur) : poids equilibres
            $pSentPos = 0.40;
            $pSentNeg = 0.40;
            $pVol = 0.10;
            $pInf = 0.10;
        } else {
            // Mode degrade (SerpAPI) : tout sur sentiment + volume
            $pSentPos = 0.45;
            $pSentNeg = 0.45;
            $pVol = 0.10;
            $pInf = 0.0;
        }

        // Calcul du score brut (-1 a +1)
        $scoreBrut = ($pctPositif * $pSentPos)
            - ($pctNegatif * $pSentNeg)
            + ($volumePositifNorm * $pVol)
            + ($influenceNorm * $pInf);

        // Mise a l'echelle 0-100
        $scoreGlobal = round(($scoreBrut + 0.5) * 100, 1);
        $scoreGlobal = max(0.0, min(100.0, $scoreGlobal));

        return [
            'score_global' => $scoreGlobal,
            'repartition'  => [
                'positif' => $nbPositif,
                'neutre'  => $nbNeutre,
                'negatif' => $nbNegatif,
            ],
            'pourcentages' => [
                'positif' => round($pctPositif * 100, 1),
                'neutre'  => round($pctNeutre * 100, 1),
                'negatif' => round($pctNegatif * 100, 1),
            ],
            'volume_total'           => $volumeTotal,
            'score_engagement_moyen' => round($engagementTotal / $volumeTotal, 2),
            'mode_donnees'           => $engagementDisponible ? 'complet' : 'limite',
            'details' => [
                'score_brut'              => round($scoreBrut, 4),
                'volume_positif_normalise' => round($volumePositifNorm, 4),
                'influence_normalisee'    => round($influenceNorm, 4),
                'engagement_disponible'   => $engagementDisponible,
            ],
        ];
    }

    /**
     * Detecte si les donnees d'engagement sont reellement disponibles.
     *
     * En mode SerpAPI, score/nb_commentaires/awards sont toujours 0.
     * En mode navigateur, au moins 10% des publications ont un score > 0.
     */
    private function aEngagementDisponible(array $publications): bool
    {
        if (empty($publications)) {
            return false;
        }

        $nbAvecScore = 0;
        foreach ($publications as $pub) {
            if ((int) ($pub['score'] ?? 0) > 0) {
                $nbAvecScore++;
            }
        }

        return $nbAvecScore > (count($publications) * 0.1);
    }

    /**
     * Calcule le score d'engagement d'une publication.
     *
     * Formule : (upvotes * 0.5) + (commentaires * 0.3) + (awards * 0.2)
     *
     * @param array<string, mixed> $publication Donnees de la publication
     */
    public function calculerEngagement(array $publication): float
    {
        $score = (int) ($publication['score'] ?? 0);
        $nbCommentaires = (int) ($publication['nb_commentaires'] ?? 0);
        $awards = (int) ($publication['awards'] ?? 0);

        return ($score * 0.5) + ($nbCommentaires * 0.3) + ($awards * 0.2);
    }

    /**
     * Calcule le score d'influence d'un auteur.
     *
     * Base sur le karma, la frequence d'activite et l'engagement moyen.
     *
     * @param array<string, mixed> $auteur Donnees de l'auteur
     * @return float Score d'influence normalise entre 0 et 1
     */
    public function calculerInfluence(array $auteur): float
    {
        $karma = (int) ($auteur['karma'] ?? 0);
        $nbPublications = (int) ($auteur['nb_publications'] ?? 0);
        $engagementMoyen = (float) ($auteur['engagement_moyen'] ?? 0.0);

        // Normalisation logarithmique du karma (eviter les valeurs extremes)
        $scoreKarma = min(1.0, log(1 + abs($karma)) / log(1 + 100000));

        // Normalisation de la frequence d'activite
        $scoreActivite = min(1.0, $nbPublications / 50.0);

        // Normalisation de l'engagement
        $scoreEngagement = min(1.0, $engagementMoyen / 100.0);

        // Score d'influence pondere
        $influence = ($scoreKarma * 0.4) + ($scoreActivite * 0.3) + ($scoreEngagement * 0.3);

        return round(max(0.0, min(1.0, $influence)), 4);
    }

    /**
     * Identifie et classifie les discussions influentes.
     *
     * Categories : virale_positive, virale_negative, controversee.
     *
     * @param array<int, array<string, mixed>> $publications
     * @return array<int, array{publication: array<string, mixed>, categorie: string, score_engagement: float}>
     */
    public function identifierDiscussionsInfluentes(array $publications): array
    {
        $discussionsInfluentes = [];

        foreach ($publications as $publication) {
            $engagement = $this->calculerEngagement($publication);

            if ($engagement < self::SEUIL_VIRALITE) {
                continue; // Pas assez d'engagement pour etre influente
            }

            $ratioUpvote = (float) ($publication['ratio_upvote'] ?? 0.5);
            $labelSentiment = $publication['label_sentiment'] ?? 'neutre';

            // Determiner la categorie
            if ($ratioUpvote < self::SEUIL_CONTROVERSE) {
                $categorie = 'controversee';
            } elseif ($labelSentiment === 'positif') {
                $categorie = 'virale_positive';
            } elseif ($labelSentiment === 'negatif') {
                $categorie = 'virale_negative';
            } else {
                $categorie = 'virale_positive'; // Neutre avec fort engagement = plutot positif
            }

            $discussionsInfluentes[] = [
                'publication'      => $publication,
                'categorie'        => $categorie,
                'score_engagement' => round($engagement, 2),
            ];
        }

        // Trier par engagement decroissant
        usort(
            $discussionsInfluentes,
            fn(array $a, array $b): int => $b['score_engagement'] <=> $a['score_engagement']
        );

        return $discussionsInfluentes;
    }

    /**
     * Extrait les facteurs positifs et negatifs de la reputation.
     *
     * @param array<int, array<string, mixed>> $publications Publications avec sentiment
     * @param array<int, array<string, mixed>> $sujets       Sujets extraits
     * @return array{positifs: array<int, array<string, mixed>>, negatifs: array<int, array<string, mixed>>}
     */
    public function extraireFacteurs(array $publications, array $sujets): array
    {
        $facteursPositifs = [];
        $facteursNegatifs = [];

        // Analyser les sujets par sentiment
        foreach ($sujets as $sujet) {
            $sentimentMoyen = (float) ($sujet['sentiment_moyen'] ?? 0.0);
            $label = $sujet['label'] ?? '';
            $frequence = (int) ($sujet['frequence'] ?? 0);

            $facteur = [
                'sujet'     => $label,
                'frequence' => $frequence,
                'sentiment' => round($sentimentMoyen, 3),
                'impact'    => $this->evaluerImpact($frequence, abs($sentimentMoyen)),
            ];

            if ($sentimentMoyen > 0.05) {
                $facteursPositifs[] = $facteur;
            } elseif ($sentimentMoyen < -0.05) {
                $facteursNegatifs[] = $facteur;
            }
        }

        // Analyser les publications a fort engagement (seulement si engagement disponible)
        if ($this->aEngagementDisponible($publications)) {
            foreach ($publications as $publication) {
                $engagement = $this->calculerEngagement($publication);
                if ($engagement < 50.0) {
                    continue;
                }

                $label = $publication['label_sentiment'] ?? 'neutre';
                $subreddit = $publication['subreddit'] ?? '';
                $titre = $publication['titre'] ?? '';

                $facteur = [
                    'sujet'     => mb_substr($titre, 0, 100),
                    'subreddit' => $subreddit,
                    'engagement' => round($engagement, 2),
                    'impact'    => $engagement > 200 ? 'fort' : 'moyen',
                ];

                if ($label === 'positif') {
                    $facteursPositifs[] = $facteur;
                } elseif ($label === 'negatif') {
                    $facteursNegatifs[] = $facteur;
                }
            }
        }

        // Trier par impact
        usort($facteursPositifs, fn(array $a, array $b): int => ($b['frequence'] ?? $b['engagement'] ?? 0) <=> ($a['frequence'] ?? $a['engagement'] ?? 0));
        usort($facteursNegatifs, fn(array $a, array $b): int => ($b['frequence'] ?? $b['engagement'] ?? 0) <=> ($a['frequence'] ?? $a['engagement'] ?? 0));

        return [
            'positifs' => array_slice($facteursPositifs, 0, 10),
            'negatifs' => array_slice($facteursNegatifs, 0, 10),
        ];
    }

    /**
     * Genere des opportunites priorisees pour ameliorer la reputation.
     *
     * @param array<int, array<string, mixed>> $questions    Questions detectees
     * @param array<int, array<string, mixed>> $publications Publications analysees
     * @param array<int, array<string, mixed>> $auteurs      Auteurs identifies
     * @return array<int, array{type: string, description: string, priorite: string, details: array<string, mixed>}>
     */
    public function genererOpportunites(array $questions, array $publications, array $auteurs): array
    {
        $opportunites = [];

        // Opportunite 1 : Questions sans reponse officielle
        $questionsSansReponse = array_filter(
            $questions,
            fn(array $q): bool => !($q['a_reponse_officielle'] ?? false)
        );

        if (!empty($questionsSansReponse)) {
            $nbQuestions = count($questionsSansReponse);
            $opportunites[] = [
                'type'        => 'reponse_questions',
                'description' => "{$nbQuestions} questions de la communaute attendent une reponse officielle",
                'priorite'    => $nbQuestions > 10 ? 'haute' : ($nbQuestions > 3 ? 'moyenne' : 'basse'),
                'details'     => [
                    'nb_questions' => $nbQuestions,
                    'categories'   => $this->compterCategories($questionsSansReponse),
                ],
            ];
        }

        // Opportunite 2 : Discussions negatives (a fort engagement si dispo, sinon par volume)
        $engagementDispo = $this->aEngagementDisponible($publications);
        $discussionsNegatives = array_filter(
            $publications,
            fn(array $p): bool => ($p['label_sentiment'] ?? '') === 'negatif'
                && ($engagementDispo ? $this->calculerEngagement($p) > 50.0 : true)
        );
        // En mode SerpAPI, limiter aux 10 plus recentes pour eviter le bruit
        if (!$engagementDispo && count($discussionsNegatives) > 10) {
            $discussionsNegatives = array_slice($discussionsNegatives, 0, 10);
        }

        if (!empty($discussionsNegatives)) {
            $nbDiscussions = count($discussionsNegatives);
            $opportunites[] = [
                'type'        => 'gestion_crise',
                'description' => "{$nbDiscussions} discussions negatives a fort engagement necessitent une attention immediate",
                'priorite'    => 'haute',
                'details'     => [
                    'nb_discussions'     => $nbDiscussions,
                    'engagement_moyen'   => round(
                        array_sum(array_map(fn(array $p): float => $this->calculerEngagement($p), $discussionsNegatives)) / $nbDiscussions,
                        2
                    ),
                ],
            ];
        }

        // Opportunite 3 : Defenseurs de la marque a fidéliser
        $defenseurs = array_filter(
            $auteurs,
            fn(array $a): bool => ($a['type'] ?? '') === 'defenseur'
        );

        if (!empty($defenseurs)) {
            $nbDefenseurs = count($defenseurs);
            $opportunites[] = [
                'type'        => 'fidelisation_defenseurs',
                'description' => "{$nbDefenseurs} defenseurs actifs de la marque identifies — potentiel d'ambassadeurs",
                'priorite'    => 'moyenne',
                'details'     => [
                    'nb_defenseurs'    => $nbDefenseurs,
                    'influence_moyenne' => round(
                        array_sum(array_map(fn(array $a): float => (float) ($a['score_influence'] ?? 0), $defenseurs)) / $nbDefenseurs,
                        4
                    ),
                ],
            ];
        }

        // Opportunite 4 : Subreddits sous-exploites
        $subredditsActifs = [];
        foreach ($publications as $publication) {
            $sub = $publication['subreddit'] ?? '';
            if ($sub !== '') {
                $subredditsActifs[$sub] = ($subredditsActifs[$sub] ?? 0) + 1;
            }
        }

        $subredditsNiches = array_filter($subredditsActifs, fn(int $n): bool => $n >= 3 && $n <= 15);
        if (!empty($subredditsNiches)) {
            $opportunites[] = [
                'type'        => 'presence_communautaire',
                'description' => count($subredditsNiches) . ' subreddits de niche avec activite moderee — potentiel de presence accrue',
                'priorite'    => 'basse',
                'details'     => [
                    'subreddits' => $subredditsNiches,
                ],
            ];
        }

        // Trier par priorite
        $ordrePriorite = ['haute' => 0, 'moyenne' => 1, 'basse' => 2];
        usort(
            $opportunites,
            fn(array $a, array $b): int => ($ordrePriorite[$a['priorite']] ?? 3) <=> ($ordrePriorite[$b['priorite']] ?? 3)
        );

        return $opportunites;
    }

    /**
     * Genere des recommandations strategiques basees sur les facteurs et opportunites.
     *
     * @param array{positifs: array<int, array<string, mixed>>, negatifs: array<int, array<string, mixed>>} $facteurs
     * @param array<int, array<string, mixed>> $opportunites
     * @return array<int, array{titre: string, description: string, priorite: string, categorie: string}>
     */
    public function genererRecommandations(array $facteurs, array $opportunites): array
    {
        $recommandations = [];

        // Recommandations basees sur les facteurs negatifs
        if (!empty($facteurs['negatifs'])) {
            $sujetsPrincipaux = array_slice(
                array_column($facteurs['negatifs'], 'sujet'),
                0,
                3
            );
            $sujetsTexte = implode(', ', $sujetsPrincipaux);

            $recommandations[] = [
                'titre'       => 'Traiter les irritants principaux',
                'description' => "Les sujets suivants generent le plus de sentiment negatif : {$sujetsTexte}. "
                    . 'Prioriser la resolution de ces problemes et communiquer publiquement sur les actions correctives.',
                'priorite'    => 'haute',
                'categorie'   => 'gestion_reputation',
            ];
        }

        // Recommandation sur l'engagement communautaire
        $opportunitesReponse = array_filter(
            $opportunites,
            fn(array $o): bool => ($o['type'] ?? '') === 'reponse_questions'
        );

        if (!empty($opportunitesReponse)) {
            $recommandations[] = [
                'titre'       => 'Engager la conversation avec la communaute',
                'description' => 'De nombreuses questions restent sans reponse officielle. '
                    . 'Mettre en place une veille Reddit et repondre sous 24h aux questions de support '
                    . 'et pre-achat pour ameliorer la perception de la marque.',
                'priorite'    => 'haute',
                'categorie'   => 'engagement',
            ];
        }

        // Recommandation sur les defenseurs
        $opportunitesDefenseurs = array_filter(
            $opportunites,
            fn(array $o): bool => ($o['type'] ?? '') === 'fidelisation_defenseurs'
        );

        if (!empty($opportunitesDefenseurs)) {
            $recommandations[] = [
                'titre'       => 'Activer un programme ambassadeurs',
                'description' => 'Des utilisateurs defendents activement la marque sur Reddit. '
                    . 'Les identifier, les remercier et leur proposer un statut d\'ambassadeur '
                    . '(acces anticipe, canal prive, goodies) pour amplifier le bouche-a-oreille positif.',
                'priorite'    => 'moyenne',
                'categorie'   => 'fidelisation',
            ];
        }

        // Recommandation sur les facteurs positifs
        if (!empty($facteurs['positifs'])) {
            $pointsForts = array_slice(
                array_column($facteurs['positifs'], 'sujet'),
                0,
                3
            );
            $pointsFortsTexte = implode(', ', $pointsForts);

            $recommandations[] = [
                'titre'       => 'Capitaliser sur les points forts',
                'description' => "Les sujets suivants sont percus positivement : {$pointsFortsTexte}. "
                    . 'Les mettre en avant dans la communication de marque et les utiliser '
                    . 'comme arguments differenciants dans le contenu marketing.',
                'priorite'    => 'moyenne',
                'categorie'   => 'communication',
            ];
        }

        // Recommandation sur la gestion de crise
        $opportunitesCrise = array_filter(
            $opportunites,
            fn(array $o): bool => ($o['type'] ?? '') === 'gestion_crise'
        );

        if (!empty($opportunitesCrise)) {
            $recommandations[] = [
                'titre'       => 'Plan de gestion de crise Reddit',
                'description' => 'Des discussions negatives a fort engagement ont ete detectees. '
                    . 'Preparer des elements de langage, identifier un porte-parole et repondre '
                    . 'de maniere transparente. Eviter le ton corporate — Reddit valorise l\'authenticite.',
                'priorite'    => 'haute',
                'categorie'   => 'gestion_crise',
            ];
        }

        // Recommandation presence communautaire
        $opportunitesPresence = array_filter(
            $opportunites,
            fn(array $o): bool => ($o['type'] ?? '') === 'presence_communautaire'
        );

        if (!empty($opportunitesPresence)) {
            $recommandations[] = [
                'titre'       => 'Developper la presence sur les subreddits de niche',
                'description' => 'Plusieurs subreddits de niche montrent de l\'interet pour la marque. '
                    . 'Participer aux discussions de maniere authentique (pas de promotion directe), '
                    . 'partager de l\'expertise et repondre aux questions pour construire la credibilite.',
                'priorite'    => 'basse',
                'categorie'   => 'strategie_contenu',
            ];
        }

        // Trier par priorite
        $ordrePriorite = ['haute' => 0, 'moyenne' => 1, 'basse' => 2];
        usort(
            $recommandations,
            fn(array $a, array $b): int => ($ordrePriorite[$a['priorite']] ?? 3) <=> ($ordrePriorite[$b['priorite']] ?? 3)
        );

        return $recommandations;
    }

    /**
     * Evalue le niveau d'impact d'un facteur.
     *
     * @return string 'fort', 'moyen' ou 'faible'
     */
    private function evaluerImpact(int $frequence, float $intensiteSentiment): string
    {
        $scoreImpact = $frequence * $intensiteSentiment;

        return match (true) {
            $scoreImpact > 10.0 => 'fort',
            $scoreImpact > 3.0  => 'moyen',
            default             => 'faible',
        };
    }

    /**
     * Compte les occurrences par categorie de question.
     *
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, int>
     */
    private function compterCategories(array $questions): array
    {
        $categories = [];

        foreach ($questions as $question) {
            $categorie = $question['categorie'] ?? 'autre';
            $categories[$categorie] = ($categories[$categorie] ?? 0) + 1;
        }

        arsort($categories);

        return $categories;
    }
}
