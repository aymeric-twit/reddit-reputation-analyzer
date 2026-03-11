<?php

declare(strict_types=1);

/**
 * Worker d'analyse en arriere-plan.
 *
 * Lance via CLI : php worker.php --job={jobId}
 * Effectue la collecte Reddit, l'analyse de sentiment, l'extraction de sujets,
 * et le calcul du score de reputation.
 */

// --- Gestion des erreurs fatales ---
register_shutdown_function(function (): void {
    $erreur = error_get_last();
    if ($erreur === null || !in_array($erreur['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $jobId = $GLOBALS['jobIdGlobal'] ?? null;
    if ($jobId === null) {
        return;
    }

    $dossierJob = __DIR__ . '/data/jobs/' . $jobId;
    $messageErreur = sprintf('%s dans %s ligne %d', $erreur['message'], $erreur['file'], $erreur['line']);

    ecrireProgression($dossierJob, [
        'statut'      => 'erreur',
        'pourcentage' => 0,
        'etape'       => 'Erreur fatale',
        'details'     => $messageErreur,
    ]);

    // Mettre a jour le statut en base si possible
    try {
        $bd = BaseDonnees::instance();
        $configJob = json_decode(
            (string) file_get_contents($dossierJob . '/config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (isset($configJob['analyse_id'])) {
            $bd->modifier('analyses', ['statut' => 'erreur'], 'id = ?', [$configJob['analyse_id']]);
        }
    } catch (\Throwable) {
        // Impossible de mettre a jour la base, on abandonne silencieusement
    }
});

// --- Verification de l'environnement CLI ---
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['erreur' => 'Acces interdit : ce script est reserve au CLI']);
    exit(1);
}

// --- Analyse des arguments CLI ---
$options = getopt('', ['job:']);
$jobId = $options['job'] ?? null;

if ($jobId === null || !is_string($jobId)) {
    fwrite(STDERR, "Usage : php worker.php --job={jobId}\n");
    exit(1);
}

// Variable globale pour le shutdown handler
$GLOBALS['jobIdGlobal'] = $jobId;

// --- Chargement de l'application ---
require_once __DIR__ . '/boot.php';

// --- Fonctions utilitaires ---

/**
 * Ecrit le fichier de progression de maniere atomique (.tmp + rename).
 *
 * @param string               $dossierJob Chemin du dossier du job
 * @param array<string, mixed> $donnees    Donnees de progression
 */
function ecrireProgression(string $dossierJob, array $donnees): void
{
    $cheminFinal = $dossierJob . '/progress.json';
    $cheminTemp = $dossierJob . '/progress.json.tmp';

    $contenu = json_encode($donnees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($cheminTemp, $contenu);
    rename($cheminTemp, $cheminFinal);
}

/**
 * Met a jour la progression avec un pourcentage et une etape.
 *
 * @param string $dossierJob  Chemin du dossier du job
 * @param int    $pourcentage Pourcentage de progression (0-100)
 * @param string $etape       Description de l'etape en cours
 * @param string $details     Details supplementaires
 */
function mettreAJourProgression(string $dossierJob, int $pourcentage, string $etape, string $details = ''): void
{
    ecrireProgression($dossierJob, [
        'statut'      => 'en_cours',
        'pourcentage' => $pourcentage,
        'etape'       => $etape,
        'details'     => $details,
    ]);
}

// --- Validation du job ---
$dossierJob = __DIR__ . '/data/jobs/' . $jobId;

if (!is_dir($dossierJob)) {
    fwrite(STDERR, "Dossier du job introuvable : {$dossierJob}\n");
    exit(1);
}

$cheminConfig = $dossierJob . '/config.json';

if (!file_exists($cheminConfig)) {
    fwrite(STDERR, "Fichier config.json introuvable dans : {$dossierJob}\n");
    exit(1);
}

// --- Lecture de la configuration ---
try {
    /** @var array{marque: string, marque_id: int, analyse_id: int, periode: string, limite: int, subreddits: string[], mots_cles: string[]} $config */
    $config = json_decode(
        (string) file_get_contents($cheminConfig),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    fwrite(STDERR, "Erreur de lecture de config.json : {$e->getMessage()}\n");
    exit(1);
}

$marque = $config['marque'];
$marqueId = (int) $config['marque_id'];
$analyseId = (int) $config['analyse_id'];
$periode = $config['periode'] ?? 'month';
$limite = (int) ($config['limite'] ?? 500);
$subreddits = $config['subreddits'] ?? [];
$motsCles = $config['mots_cles'] ?? [];

// --- Demarrage de l'analyse ---
$bd = BaseDonnees::instance();

try {
    // Mise a jour du statut en base
    $bd->modifier('analyses', [
        'statut'          => 'en_cours',
        'date_lancement'  => date('Y-m-d H:i:s'),
    ], 'id = ?', [$analyseId]);

    // --- Etape 1 : Connexion Reddit (5%) ---
    mettreAJourProgression($dossierJob, 5, 'Connexion a Reddit...', 'Detection du mode de collecte');

    $collecteur = new CollecteurReddit();
    $collecteur->authentifier();
    $modeCollecte = $collecteur->obtenirMode();

    $labelMode = match ($modeCollecte) {
        'api'         => 'API OAuth2',
        'json_public' => 'JSON public (sans credentials)',
        'bing'        => 'Recherche Bing site:reddit.com',
        default       => $modeCollecte,
    };
    mettreAJourProgression($dossierJob, 8, 'Connexion a Reddit...', "Mode : {$labelMode}");

    // --- Etape 2 : Collecte des publications (10-40%) ---
    mettreAJourProgression($dossierJob, 10, 'Collecte des publications...', '0/' . $limite . ' publications');

    $publications = $collecteur->rechercherPublications(
        marque: $marque,
        periode: $periode,
        limite: $limite,
        subreddits: $subreddits,
        motsCles: $motsCles,
        rappelProgression: function (int $collectees) use ($dossierJob, $limite): void {
            $pourcentage = 10 + (int) (($collectees / max($limite, 1)) * 30);
            $pourcentage = min($pourcentage, 40);
            mettreAJourProgression(
                $dossierJob,
                $pourcentage,
                'Collecte des publications...',
                "{$collectees}/{$limite} publications"
            );
        }
    );

    // Dedoublonnage par reddit_id
    $publicationsUniques = [];
    foreach ($publications as $pub) {
        $redditId = $pub['reddit_id'] ?? '';
        if ($redditId !== '' && !isset($publicationsUniques[$redditId])) {
            $publicationsUniques[$redditId] = $pub;
        }
    }
    $publications = array_values($publicationsUniques);

    // --- Etape 3 : Collecte des commentaires (40-60%) ---
    mettreAJourProgression($dossierJob, 40, 'Collecte des commentaires...', 'Selection des publications principales');

    // Trier par score et prendre les top publications pour les commentaires
    usort($publications, fn(array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $nbTopPubs = min(20, count($publications)); // Limiter pour eviter trop de requetes
    if ($modeCollecte === 'bing') {
        $nbTopPubs = min(10, count($publications)); // Encore moins en mode Bing
    }
    $publicationsTop = array_slice($publications, 0, $nbTopPubs);
    $commentaires = [];

    foreach ($publicationsTop as $index => $pub) {
        $pourcentage = 40 + (int) (($index / max(count($publicationsTop), 1)) * 20);
        mettreAJourProgression(
            $dossierJob,
            min($pourcentage, 60),
            'Collecte des commentaires...',
            ($index + 1) . '/' . count($publicationsTop) . ' publications traitees'
        );

        $redditId = $pub['reddit_id'] ?? '';

        if ($redditId !== '') {
            $comms = $collecteur->recupererCommentaires($redditId);
            $commentaires = array_merge($commentaires, $comms);
        }
    }

    // --- Etape 4 : Analyse de sentiment (60-75%) ---
    mettreAJourProgression($dossierJob, 60, 'Analyse de sentiment...', 'Traitement des textes');

    $analyseurSentiment = new AnalyseurSentiment();
    $totalTextes = count($publications) + count($commentaires);
    $indexGlobal = 0;

    // Analyser le sentiment sur les publications
    foreach ($publications as &$pub) {
        $contenu = ($pub['titre'] ?? '') . ' ' . ($pub['contenu'] ?? '');
        $resultat = $analyseurSentiment->analyser(trim($contenu));
        $pub['score_sentiment'] = $resultat['score'];
        $pub['label_sentiment'] = $resultat['label'];
        $pub['score_engagement'] = ($pub['score'] ?? 0) * 0.5 + ($pub['nb_commentaires'] ?? 0) * 0.3 + ($pub['awards'] ?? 0) * 0.2;

        if ($indexGlobal % 50 === 0) {
            $pourcentage = 60 + (int) (($indexGlobal / max($totalTextes, 1)) * 15);
            mettreAJourProgression($dossierJob, min($pourcentage, 75), 'Analyse de sentiment...', ($indexGlobal + 1) . "/{$totalTextes} textes");
        }
        $indexGlobal++;
    }
    unset($pub);

    // Analyser le sentiment sur les commentaires
    foreach ($commentaires as &$comm) {
        $contenu = $comm['contenu'] ?? '';
        $resultat = $analyseurSentiment->analyser($contenu);
        $comm['score_sentiment'] = $resultat['score'];
        $comm['label_sentiment'] = $resultat['label'];

        if ($indexGlobal % 50 === 0) {
            $pourcentage = 60 + (int) (($indexGlobal / max($totalTextes, 1)) * 15);
            mettreAJourProgression($dossierJob, min($pourcentage, 75), 'Analyse de sentiment...', ($indexGlobal + 1) . "/{$totalTextes} textes");
        }
        $indexGlobal++;
    }
    unset($comm);

    $tousLesTextes = array_merge($publications, $commentaires);

    // --- Etape 5 : Extraction des sujets (75-85%) ---
    mettreAJourProgression($dossierJob, 75, 'Extraction des sujets...', 'Identification des themes recurrents');

    $analyseurTexte = new AnalyseurTexte();
    $sujetsExtraits = $analyseurTexte->extraireSujets($tousLesTextes);

    mettreAJourProgression($dossierJob, 85, 'Extraction des sujets...', count($sujetsExtraits) . ' sujets identifies');

    // --- Etape 6 : Detection des questions (85-90%) ---
    mettreAJourProgression($dossierJob, 85, 'Detection des questions...', 'Recherche des questions frequentes');

    $questionsDetectees = $analyseurTexte->detecterQuestions($tousLesTextes);

    mettreAJourProgression($dossierJob, 90, 'Detection des questions...', count($questionsDetectees) . ' questions trouvees');

    // --- Etape 7 : Calcul du score de reputation (90-95%) ---
    mettreAJourProgression($dossierJob, 90, 'Calcul du score de reputation...', 'Agregation des metriques');

    $calculateurReputation = new CalculateurReputation();
    $scoreReputation = $calculateurReputation->calculerScore($publications, $commentaires);
    $facteurs = $calculateurReputation->extraireFacteurs($publications, $sujetsExtraits);
    $scoreReputation['facteurs'] = $facteurs;

    mettreAJourProgression($dossierJob, 95, 'Calcul du score de reputation...', 'Score : ' . round($scoreReputation['score_global'], 1) . '/100');

    // --- Etape 8 : Finalisation (95-100%) ---
    mettreAJourProgression($dossierJob, 95, 'Finalisation...', 'Enregistrement des resultats en base');

    // Stockage des publications en base
    $bd->connexion()->beginTransaction();

    try {
        // Inserer les publications
        foreach ($publications as $pub) {
            $bd->inserer('publications', [
                'analyse_id'       => $analyseId,
                'reddit_id'        => $pub['reddit_id'] ?? $pub['id'] ?? null,
                'titre'            => $pub['titre'] ?? $pub['title'] ?? null,
                'contenu'          => $pub['contenu'] ?? $pub['selftext'] ?? null,
                'url'              => $pub['url'] ?? null,
                'subreddit'        => $pub['subreddit'] ?? null,
                'auteur'           => $pub['auteur'] ?? $pub['author'] ?? null,
                'date_publication' => $pub['date_publication'] ?? $pub['created_utc'] ?? null,
                'score'            => $pub['score'] ?? 0,
                'ratio_upvote'     => $pub['ratio_upvote'] ?? $pub['upvote_ratio'] ?? null,
                'nb_commentaires'  => $pub['nb_commentaires'] ?? $pub['num_comments'] ?? 0,
                'awards'           => $pub['awards'] ?? $pub['total_awards_received'] ?? 0,
                'score_sentiment'  => $pub['score_sentiment'] ?? null,
                'label_sentiment'  => $pub['label_sentiment'] ?? null,
                'score_engagement' => $pub['score_engagement'] ?? null,
                'type'             => 'post',
            ]);
        }

        // Inserer les commentaires
        foreach ($commentaires as $comm) {
            $bd->inserer('publications', [
                'analyse_id'       => $analyseId,
                'reddit_id'        => $comm['reddit_id'] ?? $comm['id'] ?? null,
                'titre'            => null,
                'contenu'          => $comm['contenu'] ?? $comm['body'] ?? null,
                'url'              => $comm['url'] ?? null,
                'subreddit'        => $comm['subreddit'] ?? null,
                'auteur'           => $comm['auteur'] ?? $comm['author'] ?? null,
                'date_publication' => $comm['date_publication'] ?? $comm['created_utc'] ?? null,
                'score'            => $comm['score'] ?? 0,
                'ratio_upvote'     => null,
                'nb_commentaires'  => 0,
                'awards'           => $comm['awards'] ?? 0,
                'score_sentiment'  => $comm['score_sentiment'] ?? null,
                'label_sentiment'  => $comm['label_sentiment'] ?? null,
                'score_engagement' => $comm['score_engagement'] ?? null,
                'type'             => 'commentaire',
            ]);
        }

        // Inserer les sujets
        foreach ($sujetsExtraits as $sujet) {
            $bd->inserer('sujets', [
                'analyse_id'      => $analyseId,
                'label'           => $sujet['label'] ?? '',
                'frequence'       => $sujet['frequence'] ?? 0,
                'sentiment_moyen' => $sujet['sentiment_moyen'] ?? 0.0,
                'mots_cles'       => is_array($sujet['mots_cles'] ?? null)
                    ? json_encode($sujet['mots_cles'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : ($sujet['mots_cles'] ?? ''),
                'tendance'        => $sujet['tendance'] ?? 'stable',
            ]);
        }

        // Inserer les questions
        foreach ($questionsDetectees as $question) {
            $bd->inserer('questions', [
                'analyse_id'          => $analyseId,
                'texte'               => $question['texte'] ?? '',
                'categorie'           => $question['categorie'] ?? 'general',
                'nb_occurrences'      => $question['nb_occurrences'] ?? 1,
                'a_reponse_officielle' => $question['a_reponse_officielle'] ?? 0,
            ]);
        }

        // Extraire et inserer les auteurs influents
        $auteursMap = [];
        foreach ($tousLesTextes as $item) {
            $nomAuteur = $item['auteur'] ?? $item['author'] ?? '[deleted]';
            if ($nomAuteur === '[deleted]' || $nomAuteur === 'AutoModerator') {
                continue;
            }
            if (!isset($auteursMap[$nomAuteur])) {
                $auteursMap[$nomAuteur] = [
                    'karma'          => $item['karma_auteur'] ?? $item['author_karma'] ?? 0,
                    'nb_publications' => 0,
                    'score_total'    => 0,
                ];
            }
            $auteursMap[$nomAuteur]['nb_publications']++;
            $auteursMap[$nomAuteur]['score_total'] += (int) ($item['score'] ?? 0);
        }

        foreach ($auteursMap as $nomAuteur => $donneesAuteur) {
            $scoreInfluence = min(100.0, (float) ($donneesAuteur['nb_publications'] * 10 + $donneesAuteur['score_total'] * 0.1));
            $typeAuteur = 'neutre';
            if ($scoreInfluence >= 70) {
                $typeAuteur = 'influent';
            } elseif ($scoreInfluence >= 40) {
                $typeAuteur = 'actif';
            }

            $bd->inserer('auteurs', [
                'analyse_id'      => $analyseId,
                'nom_reddit'      => (string) $nomAuteur,
                'karma'           => $donneesAuteur['karma'],
                'score_influence'  => round($scoreInfluence, 2),
                'type'            => $typeAuteur,
                'nb_publications' => $donneesAuteur['nb_publications'],
            ]);
        }

        // Calculer les statistiques globales
        $nbPositif = 0;
        $nbNegatif = 0;
        $nbNeutre = 0;
        $totalEngagement = 0;
        $subredditsComptes = [];

        foreach ($publications as $pub) {
            $label = $pub['label_sentiment'] ?? 'neutre';
            match ($label) {
                'positif' => $nbPositif++,
                'negatif' => $nbNegatif++,
                default   => $nbNeutre++,
            };
            $totalEngagement += (int) ($pub['score'] ?? 0) + (int) ($pub['nb_commentaires'] ?? $pub['num_comments'] ?? 0);
            $sub = $pub['subreddit'] ?? 'inconnu';
            $subredditsComptes[$sub] = ($subredditsComptes[$sub] ?? 0) + 1;
        }

        arsort($subredditsComptes);
        $topSubreddits = array_slice($subredditsComptes, 0, 10, true);

        $statsGlobales = [
            'nb_publications'          => count($publications),
            'nb_commentaires_collectes' => count($commentaires),
            'sentiment_positif'         => $nbPositif,
            'sentiment_negatif'         => $nbNegatif,
            'sentiment_neutre'          => $nbNeutre,
            'engagement_total'          => $totalEngagement,
            'top_subreddits'            => $topSubreddits,
            'nb_sujets'                 => count($sujetsExtraits),
            'nb_questions'              => count($questionsDetectees),
            'nb_auteurs'                => count($auteursMap),
            'facteurs'                  => $scoreReputation['facteurs'] ?? [],
        ];

        // Mettre a jour l'analyse avec les resultats
        $bd->modifier('analyses', [
            'score_reputation' => round($scoreReputation['score_global'], 2),
            'stats_globales'   => json_encode($statsGlobales, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'statut'           => 'termine',
            'periode_debut'    => date('Y-m-d', strtotime("-1 {$periode}")),
            'periode_fin'      => date('Y-m-d'),
        ], 'id = ?', [$analyseId]);

        $bd->connexion()->commit();

        // Verifier si le score necessite une alerte
        $seuilAlerte = (float) ($bd->obtenirParametre('seuil_alerte_score', '40') ?? '40');
        if ($scoreReputation['score_global'] < $seuilAlerte) {
            $bd->inserer('alertes', [
                'marque_id' => $marqueId,
                'type'      => 'score_bas',
                'message'   => sprintf(
                    'Score de reputation bas (%.1f/100) pour "%s" - analyse du %s',
                    $scoreReputation['score_global'],
                    $marque,
                    date('d/m/Y')
                ),
            ]);
        }

    } catch (\Throwable $e) {
        $bd->connexion()->rollBack();
        throw $e;
    }

    // --- Progression finale ---
    ecrireProgression($dossierJob, [
        'statut'      => 'termine',
        'pourcentage' => 100,
        'etape'       => 'Analyse terminee',
        'analyse_id'  => $analyseId,
    ]);

    fwrite(STDOUT, "Analyse #{$analyseId} terminee avec succes. Score : {$scoreReputation['score_global']}/100\n");

} catch (\Throwable $e) {
    // Mise a jour du statut en erreur
    $bd->modifier('analyses', ['statut' => 'erreur'], 'id = ?', [$analyseId]);

    ecrireProgression($dossierJob, [
        'statut'      => 'erreur',
        'pourcentage' => 0,
        'etape'       => 'Erreur',
        'details'     => $e->getMessage(),
    ]);

    fwrite(STDERR, "Erreur lors de l'analyse #{$analyseId} : {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
