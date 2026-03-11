<?php

declare(strict_types=1);

/**
 * Analyseur de texte pour l'extraction de sujets et la detection de questions.
 *
 * Fournit des outils d'analyse textuelle : extraction de sujets par TF + n-grammes,
 * detection de questions avec categorisation, et generation de nuages de mots.
 */
class AnalyseurTexte
{
    /** @var array<string> Mots vides fusionnes (EN + FR) */
    private array $motsvides = [];

    /** @var array<string, string> Correspondance subreddit => region */
    private const array CARTE_SUBREDDITS_REGIONS = [
        'france'             => 'France',
        'french'             => 'France',
        'paris'              => 'France',
        'lyon'               => 'France',
        'marseille'          => 'France',
        'toulouse'           => 'France',
        'de'                 => 'Allemagne',
        'germany'            => 'Allemagne',
        'berlin'             => 'Allemagne',
        'unitedkingdom'      => 'Royaume-Uni',
        'uk'                 => 'Royaume-Uni',
        'london'             => 'Royaume-Uni',
        'canada'             => 'Canada',
        'quebec'             => 'Canada',
        'montreal'           => 'Canada',
        'toronto'            => 'Canada',
        'australia'          => 'Australie',
        'sydney'             => 'Australie',
        'melbourne'          => 'Australie',
        'europe'             => 'Europe',
        'spain'              => 'Espagne',
        'espana'             => 'Espagne',
        'italy'              => 'Italie',
        'italia'             => 'Italie',
        'netherlands'        => 'Pays-Bas',
        'belgium'            => 'Belgique',
        'switzerland'        => 'Suisse',
        'japan'              => 'Japon',
        'india'              => 'Inde',
        'brasil'             => 'Bresil',
        'mexico'             => 'Mexique',
        'usa'                => 'Etats-Unis',
        'americanpolitics'   => 'Etats-Unis',
    ];

    /** @var array<string, string> Patrons de detection de categorie de question */
    private const array PATRONS_CATEGORIES_QUESTIONS = [
        'support_technique' => '/\b(how to fix|how do i|not working|help me|error|bug|issue|crash|problem|broken|troubleshoot|solution|comment faire|ne fonctionne pas|aide|erreur|problème|panne)\b/iu',
        'avis_produit'      => '/\b(review|opinion|thoughts on|experience with|what do you think|worth it|quality|reliable|satisfied|avis|opinion|retour|expérience|qualité|fiable|satisfait)\b/iu',
        'comparaison'       => '/\b(vs|versus|compared to|better than|or should i|alternative|which is better|difference between|comparé à|meilleur que|alternatif|plutôt que|lequel)\b/iu',
        'pre_achat'         => '/\b(should i buy|worth buying|looking to buy|recommend|where to buy|best price|deal|discount|acheter|vaut le coup|je cherche|recommander|prix|promotion|budget)\b/iu',
    ];

    /**
     * Constructeur : charge les mots vides depuis les fichiers JSON.
     */
    public function __construct()
    {
        $this->chargerMotsVides();
    }

    /**
     * Extrait les sujets principaux a partir d'un ensemble de textes.
     *
     * Utilise l'analyse TF (Term Frequency) sur les unigrammes, bigrammes et trigrammes.
     *
     * @param array<string> $textes Liste de textes a analyser
     * @param int           $topN   Nombre de sujets a retourner
     * @return array<int, array{label: string, frequence: int, mots_cles: array<string>}>
     */
    public function extraireSujets(array $textes, int $topN = 10): array
    {
        /** @var array<string, int> $frequences */
        $frequences = [];

        foreach ($textes as $texte) {
            $texteNettoye = $this->nettoyerTexte($texte);
            $mots = preg_split('/\s+/', $texteNettoye, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $motsFiltres = $this->supprimerStopwords($mots);

            if (empty($motsFiltres)) {
                continue;
            }

            // Compter les unigrammes
            foreach ($motsFiltres as $mot) {
                if (mb_strlen($mot) < 3) {
                    continue;
                }
                $frequences[$mot] = ($frequences[$mot] ?? 0) + 1;
            }

            // Compter les bigrammes
            $bigrammes = $this->extraireNgrammes($motsFiltres, 2);
            foreach ($bigrammes as $bigramme) {
                $frequences[$bigramme] = ($frequences[$bigramme] ?? 0) + 1;
            }

            // Compter les trigrammes
            $trigrammes = $this->extraireNgrammes($motsFiltres, 3);
            foreach ($trigrammes as $trigramme) {
                $frequences[$trigramme] = ($frequences[$trigramme] ?? 0) + 1;
            }
        }

        // Filtrer les termes trop rares (au moins 2 occurrences)
        $frequences = array_filter($frequences, fn(int $f): bool => $f >= 2);

        // Trier par frequence decroissante
        arsort($frequences);

        // Construire le resultat
        $sujets = [];
        $compteur = 0;

        foreach ($frequences as $terme => $frequence) {
            if ($compteur >= $topN) {
                break;
            }

            $motsCles = explode(' ', $terme);
            $sujets[] = [
                'label'     => $terme,
                'frequence' => $frequence,
                'mots_cles' => $motsCles,
            ];

            $compteur++;
        }

        return $sujets;
    }

    /**
     * Detecte les questions dans un ensemble de textes.
     *
     * Identifie les phrases interrogatives et les categorise automatiquement.
     *
     * @param array<string> $textes Liste de textes a analyser
     * @return array<int, array{texte: string, categorie: string}>
     */
    public function detecterQuestions(array $textes): array
    {
        $questions = [];
        $patronsInterrogatifs = [
            '/\?/',
            '/\b(is \w+ worth)\b/iu',
            '/\b(does anyone)\b/iu',
            '/\b(how to)\b/iu',
            '/\b(should i)\b/iu',
            '/\b(has anyone)\b/iu',
            '/\b(can someone)\b/iu',
            '/\b(what is the best)\b/iu',
            '/\b(which \w+)\b/iu',
            '/\b(why does)\b/iu',
        ];

        foreach ($textes as $texte) {
            // Decouper en phrases
            $phrases = preg_split('/(?<=[.!?])\s+/', $texte, -1, PREG_SPLIT_NO_EMPTY) ?: [$texte];

            foreach ($phrases as $phrase) {
                $phrase = trim($phrase);
                if (mb_strlen($phrase) < 10) {
                    continue;
                }

                $estQuestion = false;
                foreach ($patronsInterrogatifs as $patron) {
                    if (preg_match($patron, $phrase)) {
                        $estQuestion = true;
                        break;
                    }
                }

                if (!$estQuestion) {
                    continue;
                }

                $categorie = $this->categoriserQuestion($phrase);
                $questions[] = [
                    'texte'     => mb_substr($phrase, 0, 500),
                    'categorie' => $categorie,
                ];
            }
        }

        return $questions;
    }

    /**
     * Genere les donnees pour un nuage de mots.
     *
     * @param array<string> $textes Liste de textes
     * @param int           $limite Nombre maximum de mots
     * @return array<string, int> Mots => frequences, tries par frequence decroissante
     */
    public function genererNuageMots(array $textes, int $limite = 50): array
    {
        /** @var array<string, int> $frequences */
        $frequences = [];

        foreach ($textes as $texte) {
            $texteNettoye = $this->nettoyerTexte($texte);
            $mots = preg_split('/\s+/', $texteNettoye, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $motsFiltres = $this->supprimerStopwords($mots);

            foreach ($motsFiltres as $mot) {
                if (mb_strlen($mot) < 3) {
                    continue;
                }
                $frequences[$mot] = ($frequences[$mot] ?? 0) + 1;
            }
        }

        // Filtrer les mots trop rares
        $frequences = array_filter($frequences, fn(int $f): bool => $f >= 2);

        arsort($frequences);

        return array_slice($frequences, 0, $limite, true);
    }

    /**
     * Detecte la langue dominante d'un texte (methode simplifiee).
     *
     * @return string 'fr' ou 'en'
     */
    public function detecterLangue(string $texte): string
    {
        $texteLower = mb_strtolower($texte);

        $marqueursFrancais = [
            'le', 'la', 'les', 'des', 'une', 'est', 'sont', 'avec',
            'pour', 'dans', 'sur', 'que', 'qui', 'nous', 'vous',
            'mais', 'donc', 'cette', 'ces', 'aussi', 'très', 'être',
            'avoir', 'fait', 'comme', 'peut', 'tout', 'plus', 'bien',
        ];

        $marqueursAnglais = [
            'the', 'is', 'are', 'was', 'were', 'have', 'has', 'with',
            'for', 'that', 'this', 'from', 'they', 'been', 'would',
            'which', 'their', 'will', 'about', 'could', 'should',
            'there', 'these', 'those', 'your', 'just', 'been', 'also',
        ];

        $scoreFr = 0;
        $scoreEn = 0;

        $mots = preg_split('/\s+/', $texteLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($mots as $mot) {
            if (in_array($mot, $marqueursFrancais, true)) {
                $scoreFr++;
            }
            if (in_array($mot, $marqueursAnglais, true)) {
                $scoreEn++;
            }
        }

        return $scoreFr > $scoreEn ? 'fr' : 'en';
    }

    /**
     * Detecte la region geographique a partir du subreddit et du texte.
     *
     * @return string|null Nom de la region ou null si non detectee
     */
    public function detecterRegion(string $subreddit, string $texte): ?string
    {
        // Verifier d'abord le subreddit
        $subredditLower = mb_strtolower(trim($subreddit));
        if (isset(self::CARTE_SUBREDDITS_REGIONS[$subredditLower])) {
            return self::CARTE_SUBREDDITS_REGIONS[$subredditLower];
        }

        // Chercher des mentions de pays/regions dans le texte
        $texteLower = mb_strtolower($texte);
        $mentionsRegions = [
            'france'      => 'France',
            'french'      => 'France',
            'français'    => 'France',
            'germany'     => 'Allemagne',
            'german'      => 'Allemagne',
            'allemagne'   => 'Allemagne',
            'uk'          => 'Royaume-Uni',
            'britain'     => 'Royaume-Uni',
            'british'     => 'Royaume-Uni',
            'england'     => 'Royaume-Uni',
            'canada'      => 'Canada',
            'canadian'    => 'Canada',
            'australia'   => 'Australie',
            'australian'  => 'Australie',
            'spain'       => 'Espagne',
            'espagne'     => 'Espagne',
            'italy'       => 'Italie',
            'italie'      => 'Italie',
            'japan'       => 'Japon',
            'japon'       => 'Japon',
            'india'       => 'Inde',
            'inde'        => 'Inde',
            'united states' => 'Etats-Unis',
            'états-unis'  => 'Etats-Unis',
            'usa'         => 'Etats-Unis',
            'american'    => 'Etats-Unis',
        ];

        foreach ($mentionsRegions as $mention => $region) {
            if (str_contains($texteLower, $mention)) {
                return $region;
            }
        }

        return null;
    }

    /**
     * Nettoie un texte : minuscules, suppression URLs, HTML, caracteres speciaux, formatage Reddit.
     */
    private function nettoyerTexte(string $texte): string
    {
        // Minuscules
        $texte = mb_strtolower($texte);

        // Supprimer les URLs
        $texte = preg_replace('#https?://\S+#', '', $texte) ?? $texte;

        // Supprimer les balises HTML
        $texte = strip_tags($texte);

        // Supprimer le formatage Reddit (**, ~~, >, #, etc.)
        $texte = preg_replace('/[*~>#`\[\]\(\)]+/', ' ', $texte) ?? $texte;

        // Supprimer les mentions Reddit (u/nom, r/subreddit)
        $texte = preg_replace('/[ur]\/\w+/', '', $texte) ?? $texte;

        // Supprimer les caracteres speciaux sauf lettres, chiffres, espaces et apostrophes
        $texte = preg_replace("/[^\p{L}\p{N}\s'-]/u", ' ', $texte) ?? $texte;

        // Normaliser les espaces multiples
        $texte = preg_replace('/\s+/', ' ', $texte) ?? $texte;

        return trim($texte);
    }

    /**
     * Supprime les mots vides d'une liste de mots.
     *
     * @param array<string> $mots
     * @return array<string>
     */
    private function supprimerStopwords(array $mots): array
    {
        return array_values(array_filter(
            $mots,
            fn(string $mot): bool => !in_array($mot, $this->motsvides, true) && mb_strlen($mot) >= 2
        ));
    }

    /**
     * Extrait les n-grammes d'une liste de mots.
     *
     * @param array<string> $mots Liste de mots
     * @param int           $n    Taille du n-gramme (2 = bigramme, 3 = trigramme)
     * @return array<string> Liste de n-grammes joints par des espaces
     */
    private function extraireNgrammes(array $mots, int $n): array
    {
        $ngrammes = [];
        $nbMots = count($mots);

        for ($i = 0; $i <= $nbMots - $n; $i++) {
            $ngramme = implode(' ', array_slice($mots, $i, $n));
            $ngrammes[] = $ngramme;
        }

        return $ngrammes;
    }

    /**
     * Categorise une question selon son contenu.
     *
     * @return string Categorie : 'support_technique', 'avis_produit', 'comparaison', 'pre_achat'
     */
    private function categoriserQuestion(string $question): string
    {
        foreach (self::PATRONS_CATEGORIES_QUESTIONS as $categorie => $patron) {
            if (preg_match($patron, $question)) {
                return $categorie;
            }
        }

        // Categorie par defaut si aucun patron ne correspond
        return 'avis_produit';
    }

    /**
     * Charge les mots vides depuis les fichiers JSON.
     */
    private function chargerMotsVides(): void
    {
        $cheminBase = __DIR__ . '/../data/';

        $fichiers = ['stopwords_en.json', 'stopwords_fr.json'];

        foreach ($fichiers as $fichier) {
            $chemin = $cheminBase . $fichier;
            if (!file_exists($chemin)) {
                continue;
            }

            $contenu = file_get_contents($chemin);
            if ($contenu === false) {
                continue;
            }

            /** @var array<string> $mots */
            $mots = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
            $this->motsvides = array_merge($this->motsvides, $mots);
        }

        // Mots vides de secours si aucun fichier charge
        if (empty($this->motsvides)) {
            $this->motsvides = $this->motsVidesParDefaut();
        }
    }

    /**
     * Retourne une liste minimale de mots vides si les fichiers JSON sont absents.
     *
     * @return array<string>
     */
    private function motsVidesParDefaut(): array
    {
        return [
            // Anglais
            'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'shall', 'can', 'need', 'dare',
            'it', 'its', 'he', 'she', 'they', 'we', 'you', 'i', 'me', 'him',
            'her', 'us', 'them', 'my', 'your', 'his', 'our', 'their', 'this',
            'that', 'these', 'those', 'not', 'no', 'nor', 'so', 'very', 'just',
            'about', 'above', 'after', 'again', 'all', 'also', 'am', 'any',
            'because', 'before', 'between', 'both', 'each', 'few', 'get', 'got',
            'here', 'how', 'if', 'into', 'just', 'more', 'most', 'much', 'now',
            'only', 'other', 'out', 'over', 'own', 'same', 'some', 'still',
            'such', 'than', 'then', 'there', 'through', 'too', 'under', 'until',
            'up', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why',
            // Francais
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou', 'mais',
            'donc', 'car', 'ni', 'ne', 'pas', 'plus', 'en', 'au', 'aux', 'ce',
            'cette', 'ces', 'mon', 'ton', 'son', 'ma', 'ta', 'sa', 'mes', 'tes',
            'ses', 'notre', 'votre', 'leur', 'nos', 'vos', 'leurs', 'je', 'tu',
            'il', 'elle', 'nous', 'vous', 'ils', 'elles', 'on', 'se', 'qui',
            'que', 'quoi', 'dont', 'où', 'si', 'dans', 'sur', 'sous', 'avec',
            'pour', 'par', 'est', 'sont', 'a', 'ont', 'fait', 'été', 'être',
            'avoir', 'très', 'bien', 'aussi', 'comme', 'tout', 'tous', 'toute',
        ];
    }
}
