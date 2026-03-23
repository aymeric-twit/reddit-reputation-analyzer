<?php

declare(strict_types=1);

/**
 * Point d'entree API pour comparer des marques entre elles.
 *
 * Methode : GET
 * Parametre requis : marque_ids (liste d'identifiants separes par des virgules, 2 a 5)
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(
            construireErreur('Methode non autorisee. Utilisez GET.', 'Method not allowed. Use GET.'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $marqueIdsChaine = $_GET['marque_ids'] ?? '';

    if ($marqueIdsChaine === '') {
        http_response_code(400);
        echo json_encode(
            construireErreur(
                'Le parametre marque_ids est requis (ex: 1,2,3).',
                'The marque_ids parameter is required (e.g. 1,2,3).'
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $marqueIds = array_map('intval', array_filter(explode(',', (string) $marqueIdsChaine), fn(string $v): bool => $v !== ''));

    if (count($marqueIds) < 2 || count($marqueIds) > 5) {
        http_response_code(422);
        echo json_encode(
            construireErreur(
                'Veuillez fournir entre 2 et 5 identifiants de marques.',
                'Please provide between 2 and 5 brand identifiers.'
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $bd = BaseDonnees::instance();
    $donneesComparaison = [];

    foreach ($marqueIds as $marqueId) {
        // Recuperer la marque
        $marque = $bd->selectionnerUn('SELECT id, nom, slug FROM marques WHERE id = ?', [$marqueId]);

        if ($marque === null) {
            continue;
        }

        // Recuperer la derniere analyse terminee
        $derniereAnalyse = $bd->selectionnerUn(
            'SELECT id, score_reputation, stats_globales, date_lancement, periode_debut, periode_fin
             FROM analyses
             WHERE marque_id = ? AND statut = ?
             ORDER BY date_lancement DESC
             LIMIT 1',
            [$marqueId, 'termine']
        );

        $scoreReputation = null;
        $statsGlobales = [];
        $distributionSentiment = ['positif' => 0, 'negatif' => 0, 'neutre' => 0];
        $topSujets = [];
        $evolution = [];

        if ($derniereAnalyse !== null) {
            $scoreReputation = $derniereAnalyse['score_reputation'] !== null
                ? round((float) $derniereAnalyse['score_reputation'], 1)
                : null;

            if (!empty($derniereAnalyse['stats_globales'])) {
                $statsGlobales = json_decode($derniereAnalyse['stats_globales'], true) ?? [];
            }

            $distributionSentiment = [
                'positif' => $statsGlobales['sentiment_positif'] ?? 0,
                'negatif' => $statsGlobales['sentiment_negatif'] ?? 0,
                'neutre'  => $statsGlobales['sentiment_neutre'] ?? 0,
            ];

            // Recuperer les top sujets de la derniere analyse
            $analyseId = (int) $derniereAnalyse['id'];
            $topSujets = $bd->selectionner(
                'SELECT label, frequence, sentiment_moyen, tendance
                 FROM sujets
                 WHERE analyse_id = ?
                 ORDER BY frequence DESC
                 LIMIT 5',
                [$analyseId]
            );
        }

        // Recuperer l'evolution temporelle (dernieres analyses)
        $historiqueAnalyses = $bd->selectionner(
            'SELECT score_reputation, date_lancement
             FROM analyses
             WHERE marque_id = ? AND statut = ? AND score_reputation IS NOT NULL
             ORDER BY date_lancement ASC
             LIMIT 12',
            [$marqueId, 'termine']
        );

        foreach ($historiqueAnalyses as $hist) {
            $evolution[] = [
                'date'  => $hist['date_lancement'],
                'score' => round((float) $hist['score_reputation'], 1),
            ];
        }

        $donneesComparaison[] = [
            'marque' => [
                'id'   => (int) $marque['id'],
                'nom'  => $marque['nom'],
                'slug' => $marque['slug'],
            ],
            'score_reputation'       => $scoreReputation,
            'distribution_sentiment' => $distributionSentiment,
            'statistiques' => [
                'nb_publications'  => $statsGlobales['nb_publications'] ?? 0,
                'nb_commentaires'  => $statsGlobales['nb_commentaires_collectes'] ?? 0,
                'engagement_total' => $statsGlobales['engagement_total'] ?? 0,
                'nb_sujets'        => $statsGlobales['nb_sujets'] ?? 0,
            ],
            'top_sujets' => $topSujets,
            'evolution'  => $evolution,
        ];
    }

    echo json_encode(array_merge(
        ['donnees' => $donneesComparaison],
        construireMessage('Comparaison des marques recuperee avec succes.', 'Brand comparison retrieved successfully.')
    ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(
        construireErreur(
            'Erreur interne : ' . $e->getMessage(),
            'Internal error: ' . $e->getMessage()
        ),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
}
