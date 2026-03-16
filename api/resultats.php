<?php

declare(strict_types=1);

/**
 * Point d'entree API pour recuperer les resultats complets d'une analyse.
 *
 * Methode : GET
 * Parametre requis : analyse_id
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'erreur' => 'Methode non autorisee. Utilisez GET.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $analyseId = $_GET['analyse_id'] ?? null;

    if ($analyseId === null || $analyseId === '') {
        http_response_code(400);
        echo json_encode([
            'erreur' => 'Le parametre analyse_id est requis.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $analyseId = (int) $analyseId;
    $bd = BaseDonnees::instance();

    // Recuperer l'analyse avec le nom de la marque
    $analyse = $bd->selectionnerUn(
        'SELECT a.*, m.nom AS marque_nom, m.slug AS marque_slug
         FROM analyses a
         JOIN marques m ON m.id = a.marque_id
         WHERE a.id = ?',
        [$analyseId]
    );

    if ($analyse === null) {
        http_response_code(404);
        echo json_encode([
            'erreur' => 'Analyse introuvable.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Recuperer les publications (posts)
    $publications = $bd->selectionner(
        'SELECT id, reddit_id, titre, contenu, url, subreddit, auteur, date_publication,
                score, ratio_upvote, nb_commentaires, awards, score_sentiment,
                label_sentiment, score_engagement, type
         FROM publications
         WHERE analyse_id = ? AND type = ?
         ORDER BY score DESC',
        [$analyseId, 'post']
    );

    // Recuperer les commentaires
    $commentaires = $bd->selectionner(
        'SELECT id, reddit_id, contenu, subreddit, auteur, date_publication,
                score, score_sentiment, label_sentiment, type
         FROM publications
         WHERE analyse_id = ? AND type = ?
         ORDER BY score DESC
         LIMIT 100',
        [$analyseId, 'commentaire']
    );

    // Recuperer les sujets
    $sujets = $bd->selectionner(
        'SELECT id, label, frequence, sentiment_moyen, mots_cles, tendance
         FROM sujets
         WHERE analyse_id = ?
         ORDER BY frequence DESC',
        [$analyseId]
    );

    // Decoder les mots-cles JSON des sujets
    foreach ($sujets as &$sujet) {
        $motsCles = $sujet['mots_cles'] ?? '';
        $decode = json_decode($motsCles, true);
        $sujet['mots_cles'] = is_array($decode) ? $decode : [];
    }
    unset($sujet);

    // Recuperer les questions
    $questions = $bd->selectionner(
        'SELECT id, texte, categorie, nb_occurrences, a_reponse_officielle
         FROM questions
         WHERE analyse_id = ?
         ORDER BY nb_occurrences DESC',
        [$analyseId]
    );

    // Recuperer les auteurs
    $auteurs = $bd->selectionner(
        'SELECT id, nom_reddit, karma, anciennete, score_influence, type, nb_publications
         FROM auteurs
         WHERE analyse_id = ?
         ORDER BY score_influence DESC
         LIMIT 50',
        [$analyseId]
    );

    // Decoder les stats globales
    $statsGlobales = [];
    if (!empty($analyse['stats_globales'])) {
        $statsGlobales = json_decode($analyse['stats_globales'], true) ?? [];
    }

    // Calculer des statistiques agregees
    $distributionSentiment = [
        'positif' => 0,
        'negatif' => 0,
        'neutre'  => 0,
    ];
    $subredditsComptes = [];
    $engagementTotal = 0;

    foreach ($publications as $pub) {
        $label = $pub['label_sentiment'] ?? 'neutre';
        if (isset($distributionSentiment[$label])) {
            $distributionSentiment[$label]++;
        } else {
            $distributionSentiment['neutre']++;
        }

        $sub = $pub['subreddit'] ?? 'inconnu';
        $subredditsComptes[$sub] = ($subredditsComptes[$sub] ?? 0) + 1;

        $engagementTotal += (int) ($pub['score'] ?? 0) + (int) ($pub['nb_commentaires'] ?? 0);
    }

    arsort($subredditsComptes);
    $topSubreddits = array_slice($subredditsComptes, 0, 10, true);

    // Construire la reponse
    $reponse = [
        'donnees' => [
            'analyse' => [
                'id'               => (int) $analyse['id'],
                'marque_id'        => (int) $analyse['marque_id'],
                'marque_nom'       => $analyse['marque_nom'],
                'marque_slug'      => $analyse['marque_slug'],
                'date_lancement'   => $analyse['date_lancement'],
                'periode_debut'    => $analyse['periode_debut'],
                'periode_fin'      => $analyse['periode_fin'],
                'statut'           => $analyse['statut'],
                'score_reputation' => $analyse['score_reputation'] !== null
                    ? round((float) $analyse['score_reputation'], 1)
                    : null,
                'job_id'           => $analyse['job_id'],
            ],
            'publications'  => $publications,
            'commentaires'  => $commentaires,
            'sujets'        => $sujets,
            'questions'     => $questions,
            'auteurs'       => $auteurs,
            'facteurs'      => $statsGlobales['facteurs'] ?? [],
            'statistiques'  => [
                'total_publications' => count($publications),
                'total_commentaires' => count($commentaires),
                'distribution_sentiment' => $distributionSentiment,
                'top_subreddits'    => $topSubreddits,
                'engagement_total'  => $engagementTotal,
                'nb_sujets'         => count($sujets),
                'nb_questions'      => count($questions),
                'nb_auteurs'        => count($auteurs),
                'methode_sentiment' => $statsGlobales['methode_sentiment'] ?? 'lexique',
                'appels_api_nlp'    => $statsGlobales['appels_api_nlp'] ?? 0,
                'mode_collecte'     => $statsGlobales['mode_collecte'] ?? 'serpapi',
            ],
        ],
        'message' => 'Resultats de l\'analyse recuperes avec succes.',
    ];

    echo json_encode($reponse, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
