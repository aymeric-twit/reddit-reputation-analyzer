<?php

declare(strict_types=1);

/**
 * Point d'entree API pour lancer une nouvelle analyse de reputation.
 *
 * Methode : POST
 * Parametres : marque (requis), periode, limite, subreddits, mots_cles, marques_concurrentes
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Convertit une chaine en slug URL-friendly.
 * Supprime les accents, remplace les espaces/caracteres speciaux par des tirets.
 */
function slugifier(string $texte): string
{
    // Retirer les accents via transliterator si disponible
    if (function_exists('transliterator_transliterate')) {
        $texte = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texte);
    } else {
        $texte = strtolower($texte);
    }

    // Remplacer tout ce qui n'est pas alphanumeique par un tiret
    $texte = (string) preg_replace('/[^a-z0-9]+/', '-', $texte);
    // Supprimer les tirets en debut et fin
    $texte = trim($texte, '-');
    // Supprimer les tirets multiples
    $texte = (string) preg_replace('/-+/', '-', $texte);

    return $texte;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'erreur' => 'Methode non autorisee. Utilisez POST.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lecture des parametres POST (formulaire ou JSON)
    $corpsRequete = file_get_contents('php://input');
    $donnees = [];

    if ($corpsRequete !== false && $corpsRequete !== '') {
        $donneesJson = json_decode($corpsRequete, true);
        if (is_array($donneesJson)) {
            $donnees = $donneesJson;
        }
    }

    // Fallback sur $_POST si pas de JSON
    if (empty($donnees)) {
        $donnees = $_POST;
    }

    $marque = trim((string) ($donnees['marque'] ?? ''));
    $periode = trim((string) ($donnees['periode'] ?? 'month'));
    $limite = (int) ($donnees['limite'] ?? 500);
    $subredditsChaine = trim((string) ($donnees['subreddits'] ?? ''));
    $motsClesChaine = trim((string) ($donnees['mots_cles'] ?? ''));
    $marquesConcurrentes = trim((string) ($donnees['marques_concurrentes'] ?? ''));

    // --- Validation ---
    if ($marque === '') {
        http_response_code(422);
        echo json_encode([
            'erreur' => 'Le parametre "marque" est requis.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $periodesValides = ['hour', 'day', 'week', 'month', 'year', 'all'];
    if (!in_array($periode, $periodesValides, true)) {
        http_response_code(422);
        echo json_encode([
            'erreur' => 'Periode invalide. Valeurs acceptees : ' . implode(', ', $periodesValides),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($limite < 10 || $limite > 5000) {
        http_response_code(422);
        echo json_encode([
            'erreur' => 'La limite doit etre comprise entre 10 et 5000.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Transformation des chaines CSV en tableaux
    $subreddits = $subredditsChaine !== ''
        ? array_map('trim', explode(',', $subredditsChaine))
        : [];

    $motsCles = $motsClesChaine !== ''
        ? array_map('trim', explode(',', $motsClesChaine))
        : [];

    // --- Creation ou recuperation de la marque ---
    $bd = BaseDonnees::instance();
    $slug = slugifier($marque);

    $marqueExistante = $bd->selectionnerUn(
        'SELECT id FROM marques WHERE slug = ?',
        [$slug]
    );

    if ($marqueExistante !== null) {
        $marqueId = (int) $marqueExistante['id'];
    } else {
        $marqueId = $bd->inserer('marques', [
            'nom'  => $marque,
            'slug' => $slug,
        ]);
    }

    // --- Creation de l'enregistrement d'analyse ---
    $analyseId = $bd->inserer('analyses', [
        'marque_id'        => $marqueId,
        'date_lancement'   => date('Y-m-d H:i:s'),
        'statut'           => 'en_attente',
        'subreddits_cibles' => !empty($subreddits) ? json_encode($subreddits, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
        'mots_cles'        => !empty($motsCles) ? json_encode($motsCles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
    ]);

    // --- Generation de l'identifiant de job unique ---
    $jobId = uniqid('job_', true) . '_' . bin2hex(random_bytes(4));

    // Mettre a jour l'analyse avec le job_id
    $bd->modifier('analyses', ['job_id' => $jobId], 'id = ?', [$analyseId]);

    // --- Creation du repertoire de job ---
    $dossierJob = __DIR__ . '/../data/jobs/' . $jobId;

    if (!is_dir($dossierJob)) {
        mkdir($dossierJob, 0755, true);
    }

    // Ecriture de la configuration du job
    $configJob = [
        'marque'     => $marque,
        'marque_id'  => $marqueId,
        'analyse_id' => $analyseId,
        'periode'    => $periode,
        'limite'     => $limite,
        'subreddits' => $subreddits,
        'mots_cles'  => $motsCles,
    ];

    file_put_contents(
        $dossierJob . '/config.json',
        json_encode($configJob, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    // Ecriture du fichier de progression initial
    file_put_contents(
        $dossierJob . '/progress.json',
        json_encode([
            'statut'      => 'en_attente',
            'pourcentage' => 0,
            'etape'       => 'Initialisation...',
            'details'     => '',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
    );

    // --- Lancement du worker en arriere-plan ---
    $cheminWorker = __DIR__ . '/../worker.php';
    $commande = 'php ' . escapeshellarg($cheminWorker) . ' --job=' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &';
    exec($commande);

    // --- Reponse ---
    http_response_code(201);
    echo json_encode([
        'succes'     => true,
        'job_id'     => $jobId,
        'analyse_id' => $analyseId,
        'message'    => 'Analyse lancee avec succes.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
