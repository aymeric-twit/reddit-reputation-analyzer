<?php

declare(strict_types=1);

/**
 * Point d'entree AJAX pour consulter la progression d'un job d'analyse.
 *
 * Retourne le contenu du fichier progress.json du job demande,
 * ainsi que les nouvelles lignes du journal (log.jsonl) depuis le dernier poll.
 *
 * Parametres GET :
 *   - job_id : identifiant du job (requis)
 *   - log_offset : nombre d'octets deja lus du log (optionnel, defaut 0)
 */

require_once __DIR__ . '/boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $jobId = $_GET['job_id'] ?? null;

    if ($jobId === null || !is_string($jobId) || $jobId === '') {
        http_response_code(400);
        echo json_encode(
            construireErreur('Le parametre job_id est requis', 'The job_id parameter is required'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // Securite : empecher la traversee de repertoires
    $jobId = basename($jobId);

    $dossierJob = __DIR__ . '/data/jobs/' . $jobId;
    $cheminProgression = $dossierJob . '/progress.json';
    $cheminLog = $dossierJob . '/log.jsonl';

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

    /** @var array<string, mixed> $progression */
    $progression = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);

    // Lecture incrementale du journal
    $logOffset = max(0, (int) ($_GET['log_offset'] ?? 0));
    $nouvellesLignes = [];
    $nouveauOffset = $logOffset;

    if (file_exists($cheminLog)) {
        $tailleLog = filesize($cheminLog);

        if ($tailleLog !== false && $tailleLog > $logOffset) {
            $handle = fopen($cheminLog, 'r');
            if ($handle !== false) {
                fseek($handle, $logOffset);
                $contenuLog = fread($handle, $tailleLog - $logOffset);
                fclose($handle);

                if ($contenuLog !== false && $contenuLog !== '') {
                    $lignes = explode("\n", rtrim($contenuLog, "\n"));
                    foreach ($lignes as $ligne) {
                        if ($ligne === '') {
                            continue;
                        }
                        $decodee = json_decode($ligne, true);
                        if (is_array($decodee)) {
                            $nouvellesLignes[] = $decodee;
                        }
                    }
                    $nouveauOffset = $tailleLog;
                }
            }
        }
    }

    $progression['log'] = $nouvellesLignes;
    $progression['log_offset'] = $nouveauOffset;

    echo json_encode($progression, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

} catch (JsonException $e) {
    http_response_code(500);
    echo json_encode(
        construireErreur('Fichier de progression corrompu', 'Corrupted progress file'),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
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
