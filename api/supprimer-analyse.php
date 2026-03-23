<?php

declare(strict_types=1);

/**
 * Point d'entree API pour supprimer une analyse et toutes ses donnees associees.
 *
 * Methode : POST
 * Parametre requis : analyse_id
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(
            construireErreur('Methode non autorisee. Utilisez POST.', 'Method not allowed. Use POST.'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
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

    $analyseId = $donnees['analyse_id'] ?? null;

    if ($analyseId === null || $analyseId === '') {
        http_response_code(400);
        echo json_encode(
            construireErreur('Le parametre analyse_id est requis.', 'The analyse_id parameter is required.'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $analyseId = (int) $analyseId;
    $bd = BaseDonnees::instance();

    // Verifier que l'analyse existe et recuperer le job_id
    $analyse = $bd->selectionnerUn(
        'SELECT id, job_id FROM analyses WHERE id = ?',
        [$analyseId]
    );

    if ($analyse === null) {
        http_response_code(404);
        echo json_encode(
            construireErreur('Analyse introuvable.', 'Analysis not found.'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // Supprimer les donnees associees (les FK ON DELETE CASCADE devraient s'en charger,
    // mais on supprime explicitement par securite)
    $bd->connexion()->beginTransaction();

    try {
        $bd->supprimer('publications', 'analyse_id = ?', [$analyseId]);
        $bd->supprimer('sujets', 'analyse_id = ?', [$analyseId]);
        $bd->supprimer('questions', 'analyse_id = ?', [$analyseId]);
        $bd->supprimer('auteurs', 'analyse_id = ?', [$analyseId]);
        $bd->supprimer('analyses', 'id = ?', [$analyseId]);

        $bd->connexion()->commit();
    } catch (\Throwable $e) {
        $bd->connexion()->rollBack();
        throw $e;
    }

    // Nettoyer le repertoire du job si existant
    $jobId = $analyse['job_id'] ?? null;
    if ($jobId !== null && $jobId !== '') {
        $dossierJob = __DIR__ . '/../data/jobs/' . basename((string) $jobId);
        if (is_dir($dossierJob)) {
            // Supprimer les fichiers du dossier
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
    }

    echo json_encode(array_merge(
        ['succes' => true],
        construireMessage(
            'Analyse et donnees associees supprimees avec succes.',
            'Analysis and associated data deleted successfully.'
        )
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
