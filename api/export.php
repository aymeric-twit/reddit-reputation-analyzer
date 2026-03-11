<?php

declare(strict_types=1);

/**
 * Point d'entree API pour l'export des resultats d'analyse.
 *
 * Methode : GET
 * Parametres : analyse_id (requis), format (csv ou json, defaut: csv)
 */

require_once __DIR__ . '/../boot.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode([
            'erreur' => 'Methode non autorisee. Utilisez GET.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $analyseId = $_GET['analyse_id'] ?? null;
    $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));

    if ($analyseId === null || $analyseId === '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'erreur' => 'Le parametre analyse_id est requis.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($format, ['csv', 'json'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode([
            'erreur' => 'Format invalide. Valeurs acceptees : csv, json.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $analyseId = (int) $analyseId;
    $bd = BaseDonnees::instance();

    // Verifier que l'analyse existe
    $analyse = $bd->selectionnerUn(
        'SELECT a.*, m.nom AS marque_nom
         FROM analyses a
         JOIN marques m ON m.id = a.marque_id
         WHERE a.id = ?',
        [$analyseId]
    );

    if ($analyse === null) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'erreur' => 'Analyse introuvable.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $nomFichier = sprintf(
        'reddit-reputation_%s_%s',
        preg_replace('/[^a-z0-9_-]/i', '_', $analyse['marque_nom']),
        date('Y-m-d', strtotime($analyse['date_lancement'] ?? 'now'))
    );

    if ($format === 'csv') {
        // --- Export CSV ---
        $publications = $bd->selectionner(
            'SELECT titre, subreddit, auteur, score, nb_commentaires, ratio_upvote,
                    score_sentiment, label_sentiment, score_engagement, url,
                    date_publication, type
             FROM publications
             WHERE analyse_id = ?
             ORDER BY score DESC',
            [$analyseId]
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nomFichier . '.csv"');

        $sortie = fopen('php://output', 'w');

        if ($sortie === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le flux de sortie.');
        }

        // BOM UTF-8 pour compatibilite Excel
        fwrite($sortie, "\xEF\xBB\xBF");

        // En-tetes CSV
        fputcsv($sortie, [
            'Titre',
            'Subreddit',
            'Auteur',
            'Score',
            'Commentaires',
            'Ratio Upvote',
            'Score Sentiment',
            'Label Sentiment',
            'Score Engagement',
            'URL',
            'Date Publication',
            'Type',
        ], ';');

        // Donnees
        foreach ($publications as $pub) {
            fputcsv($sortie, [
                $pub['titre'] ?? '',
                $pub['subreddit'] ?? '',
                $pub['auteur'] ?? '',
                $pub['score'] ?? 0,
                $pub['nb_commentaires'] ?? 0,
                $pub['ratio_upvote'] ?? '',
                $pub['score_sentiment'] !== null ? round((float) $pub['score_sentiment'], 3) : '',
                $pub['label_sentiment'] ?? '',
                $pub['score_engagement'] !== null ? round((float) $pub['score_engagement'], 3) : '',
                $pub['url'] ?? '',
                $pub['date_publication'] ?? '',
                $pub['type'] ?? '',
            ], ';');
        }

        fclose($sortie);

    } else {
        // --- Export JSON ---
        $publications = $bd->selectionner(
            'SELECT * FROM publications WHERE analyse_id = ? ORDER BY score DESC',
            [$analyseId]
        );

        $sujets = $bd->selectionner(
            'SELECT * FROM sujets WHERE analyse_id = ? ORDER BY frequence DESC',
            [$analyseId]
        );

        $questions = $bd->selectionner(
            'SELECT * FROM questions WHERE analyse_id = ? ORDER BY nb_occurrences DESC',
            [$analyseId]
        );

        $auteurs = $bd->selectionner(
            'SELECT * FROM auteurs WHERE analyse_id = ? ORDER BY score_influence DESC',
            [$analyseId]
        );

        // Decoder les mots-cles JSON dans les sujets
        foreach ($sujets as &$sujet) {
            $decode = json_decode($sujet['mots_cles'] ?? '', true);
            $sujet['mots_cles'] = is_array($decode) ? $decode : [];
        }
        unset($sujet);

        $donneesExport = [
            'analyse' => [
                'id'               => (int) $analyse['id'],
                'marque'           => $analyse['marque_nom'],
                'date_lancement'   => $analyse['date_lancement'],
                'periode_debut'    => $analyse['periode_debut'],
                'periode_fin'      => $analyse['periode_fin'],
                'score_reputation' => $analyse['score_reputation'] !== null
                    ? round((float) $analyse['score_reputation'], 1)
                    : null,
                'statut'           => $analyse['statut'],
                'stats_globales'   => json_decode($analyse['stats_globales'] ?? '{}', true),
            ],
            'publications' => $publications,
            'sujets'       => $sujets,
            'questions'    => $questions,
            'auteurs'      => $auteurs,
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nomFichier . '.json"');

        echo json_encode($donneesExport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

} catch (\Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
