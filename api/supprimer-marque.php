<?php

declare(strict_types=1);

/**
 * Point d'entree API pour supprimer une marque et toutes ses donnees associees.
 *
 * Methode : POST
 * Parametre requis : marque_id
 * Supprime : marque, analyses, publications, sujets, questions, auteurs, alertes
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'erreur' => 'Methode non autorisee. Utilisez POST.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lecture des parametres (formulaire ou JSON)
    $corpsRequete = file_get_contents('php://input');
    $donnees = [];

    if ($corpsRequete !== false && $corpsRequete !== '') {
        $donneesJson = json_decode($corpsRequete, true);
        if (is_array($donneesJson)) {
            $donnees = $donneesJson;
        }
    }

    if (empty($donnees)) {
        $donnees = $_POST;
    }

    $marqueId = $donnees['marque_id'] ?? null;

    if ($marqueId === null || $marqueId === '') {
        http_response_code(400);
        echo json_encode([
            'erreur' => 'Le parametre marque_id est requis.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $marqueId = (int) $marqueId;
    $bd = BaseDonnees::instance();

    // Verifier que la marque existe
    $marque = $bd->selectionnerUn(
        'SELECT id, nom FROM marques WHERE id = ?',
        [$marqueId]
    );

    if ($marque === null) {
        http_response_code(404);
        echo json_encode([
            'erreur' => 'Marque introuvable.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Recuperer toutes les analyses pour nettoyer les dossiers de jobs
    $analyses = $bd->selectionner(
        'SELECT id, job_id FROM analyses WHERE marque_id = ?',
        [$marqueId]
    );

    $bd->connexion()->beginTransaction();

    try {
        // Supprimer les donnees associees a chaque analyse
        foreach ($analyses as $analyse) {
            $analyseId = (int) $analyse['id'];
            $bd->supprimer('publications', 'analyse_id = ?', [$analyseId]);
            $bd->supprimer('sujets', 'analyse_id = ?', [$analyseId]);
            $bd->supprimer('questions', 'analyse_id = ?', [$analyseId]);
            $bd->supprimer('auteurs', 'analyse_id = ?', [$analyseId]);
        }

        // Supprimer les alertes de la marque
        $bd->supprimer('alertes', 'marque_id = ?', [$marqueId]);

        // Supprimer les analyses
        $bd->supprimer('analyses', 'marque_id = ?', [$marqueId]);

        // Supprimer la marque
        $bd->supprimer('marques', 'id = ?', [$marqueId]);

        $bd->connexion()->commit();
    } catch (\Throwable $e) {
        $bd->connexion()->rollBack();
        throw $e;
    }

    // Nettoyer les repertoires de jobs
    foreach ($analyses as $analyse) {
        $jobId = $analyse['job_id'] ?? null;
        if ($jobId === null || $jobId === '') {
            continue;
        }

        $dossierJob = __DIR__ . '/../data/jobs/' . basename((string) $jobId);
        if (!is_dir($dossierJob)) {
            continue;
        }

        $fichiers = glob($dossierJob . '/*');
        if ($fichiers !== false) {
            foreach ($fichiers as $fichier) {
                if (is_file($fichier)) {
                    unlink($fichier);
                }
            }
        }
        rmdir($dossierJob);
    }

    echo json_encode([
        'succes'  => true,
        'message' => sprintf('Marque "%s" et toutes ses donnees supprimees avec succes.', $marque['nom']),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
