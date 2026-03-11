<?php

declare(strict_types=1);

/**
 * Point d'entree AJAX pour consulter la progression d'un job d'analyse.
 *
 * Retourne le contenu du fichier progress.json du job demande.
 */

require_once __DIR__ . '/boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $jobId = $_GET['job_id'] ?? null;

    if ($jobId === null || !is_string($jobId) || $jobId === '') {
        http_response_code(400);
        echo json_encode([
            'erreur' => 'Le parametre job_id est requis',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Securite : empecher la traversee de repertoires
    $jobId = basename($jobId);

    $cheminProgression = __DIR__ . '/data/jobs/' . $jobId . '/progress.json';

    if (!file_exists($cheminProgression)) {
        echo json_encode([
            'statut' => 'inconnu',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $contenu = file_get_contents($cheminProgression);

    if ($contenu === false) {
        echo json_encode([
            'statut' => 'inconnu',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Valider que c'est du JSON valide avant de le renvoyer
    json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);

    echo $contenu;

} catch (JsonException $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Fichier de progression corrompu',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
