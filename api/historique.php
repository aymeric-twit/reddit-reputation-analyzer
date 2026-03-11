<?php

declare(strict_types=1);

/**
 * Point d'entree API pour l'historique des analyses.
 *
 * Methode : GET
 * Parametres optionnels : marque_id, page, par_page
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

    $bd = BaseDonnees::instance();

    $marqueId = isset($_GET['marque_id']) ? (int) $_GET['marque_id'] : null;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $parPage = max(1, min(100, (int) ($_GET['par_page'] ?? 20)));
    $offset = ($page - 1) * $parPage;

    // Construction de la requete avec condition optionnelle sur marque_id
    $conditionMarque = '';
    $params = [];

    if ($marqueId !== null) {
        $conditionMarque = 'WHERE a.marque_id = ?';
        $params[] = $marqueId;
    }

    // Compter le total
    $sqlTotal = "SELECT COUNT(*) AS total FROM analyses a {$conditionMarque}";
    $resultatTotal = $bd->selectionnerUn($sqlTotal, $params);
    $total = (int) ($resultatTotal['total'] ?? 0);

    // Recuperer les analyses paginées
    $sqlAnalyses = "
        SELECT a.id, a.marque_id, m.nom AS marque_nom, a.date_lancement,
               a.periode_debut, a.periode_fin, a.statut, a.score_reputation,
               a.stats_globales, a.job_id
        FROM analyses a
        JOIN marques m ON m.id = a.marque_id
        {$conditionMarque}
        ORDER BY a.date_lancement DESC
        LIMIT ? OFFSET ?
    ";

    $paramsAvecPagination = [...$params, $parPage, $offset];
    $analyses = $bd->selectionner($sqlAnalyses, $paramsAvecPagination);

    // Formatter les resultats
    $resultats = [];
    foreach ($analyses as $analyse) {
        $statsGlobales = null;
        $nbPublications = null;

        if (!empty($analyse['stats_globales'])) {
            $statsGlobales = json_decode($analyse['stats_globales'], true);
            $nbPublications = $statsGlobales['nb_publications'] ?? null;
        }

        $resultats[] = [
            'id'               => (int) $analyse['id'],
            'marque_id'        => (int) $analyse['marque_id'],
            'marque_nom'       => $analyse['marque_nom'],
            'date_lancement'   => $analyse['date_lancement'],
            'periode_debut'    => $analyse['periode_debut'],
            'periode_fin'      => $analyse['periode_fin'],
            'statut'           => $analyse['statut'],
            'score_reputation' => $analyse['score_reputation'] !== null
                ? round((float) $analyse['score_reputation'], 1)
                : null,
            'nb_publications'  => $nbPublications,
            'job_id'           => $analyse['job_id'],
        ];
    }

    $totalPages = (int) ceil($total / $parPage);

    echo json_encode([
        'donnees' => $resultats,
        'pagination' => [
            'page'        => $page,
            'par_page'    => $parPage,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
        'message' => 'Historique des analyses recupere avec succes.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
