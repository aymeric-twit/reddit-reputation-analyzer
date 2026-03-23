<?php

declare(strict_types=1);

/**
 * Point d'entree API pour le tableau de bord des marques.
 *
 * Methode : GET
 * Retourne la liste des marques avec leur dernier score, statut, tendance et alertes.
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

    $bd = BaseDonnees::instance();

    // Recuperer toutes les marques
    $marques = $bd->selectionner('SELECT id, nom, slug, date_creation FROM marques ORDER BY nom ASC');

    $resultats = [];

    foreach ($marques as $marque) {
        $marqueId = (int) $marque['id'];

        // Recuperer les 2 dernieres analyses terminees pour calculer la tendance
        $dernieresAnalyses = $bd->selectionner(
            'SELECT id, score_reputation, statut, date_lancement, stats_globales
             FROM analyses
             WHERE marque_id = ? AND statut = ?
             ORDER BY date_lancement DESC
             LIMIT 2',
            [$marqueId, 'termine']
        );

        // Recuperer aussi la derniere analyse quel que soit le statut
        $derniereAnalyse = $bd->selectionnerUn(
            'SELECT id, score_reputation, statut, date_lancement, job_id
             FROM analyses
             WHERE marque_id = ?
             ORDER BY date_lancement DESC
             LIMIT 1',
            [$marqueId]
        );

        $scoreDernier = null;
        $statutDernier = $derniereAnalyse['statut'] ?? null;
        $dateDerniereAnalyse = $derniereAnalyse['date_lancement'] ?? null;
        $tendance = null;
        $nbPublications = null;

        if (!empty($dernieresAnalyses)) {
            $scoreDernier = $dernieresAnalyses[0]['score_reputation'] !== null
                ? round((float) $dernieresAnalyses[0]['score_reputation'], 1)
                : null;

            // Decoder les stats pour le nombre de publications
            $statsGlobales = $dernieresAnalyses[0]['stats_globales'] ?? null;
            if ($statsGlobales !== null) {
                $stats = json_decode($statsGlobales, true);
                $nbPublications = $stats['nb_publications'] ?? null;
            }

            // Calcul de la tendance si on a 2 analyses
            if (count($dernieresAnalyses) >= 2) {
                $scoreActuel = (float) ($dernieresAnalyses[0]['score_reputation'] ?? 0);
                $scorePrecedent = (float) ($dernieresAnalyses[1]['score_reputation'] ?? 0);
                $difference = $scoreActuel - $scorePrecedent;

                if (abs($difference) < 2.0) {
                    $tendance = 'stable';
                } elseif ($difference > 0) {
                    $tendance = 'hausse';
                } else {
                    $tendance = 'baisse';
                }
            }
        }

        // Compter les alertes non lues
        $resultAlerte = $bd->selectionnerUn(
            'SELECT COUNT(*) as nb FROM alertes WHERE marque_id = ? AND lue = 0',
            [$marqueId]
        );
        $nbAlertes = (int) ($resultAlerte['nb'] ?? 0);

        $resultats[] = [
            'id'                      => $marqueId,
            'nom'                     => $marque['nom'],
            'slug'                    => $marque['slug'],
            'date_creation'           => $marque['date_creation'],
            'score'                   => $scoreDernier,
            'score_reputation'        => $scoreDernier,
            'statut_derniere_analyse' => $statutDernier,
            'derniere_analyse'        => $dateDerniereAnalyse,
            'derniere_analyse_id'     => $derniereAnalyse['id'] ?? null,
            'tendance'                => $tendance,
            'nb_publications'         => $nbPublications,
            'nb_alertes'              => $nbAlertes,
        ];
    }

    echo json_encode(array_merge(
        ['donnees' => $resultats],
        construireMessage('Liste des marques recuperee avec succes.', 'Brand list retrieved successfully.')
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
