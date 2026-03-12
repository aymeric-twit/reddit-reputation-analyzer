<?php

declare(strict_types=1);

/**
 * Point d'entree API pour la gestion des parametres de l'application.
 *
 * GET  : retourne tous les parametres sous forme cle-valeur
 * POST : met a jour les parametres (accepte un corps JSON avec des paires cle/valeur)
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $bd = BaseDonnees::instance();
    $methode = $_SERVER['REQUEST_METHOD'];

    if ($methode === 'GET') {
        // --- Lecture de tous les parametres ---
        $parametres = $bd->selectionner('SELECT cle, valeur FROM parametres ORDER BY cle ASC');

        $resultat = [];
        foreach ($parametres as $param) {
            $valeur = $param['valeur'];
            // Tenter de decoder les valeurs JSON
            $decode = json_decode($valeur, true);
            $resultat[$param['cle']] = ($decode !== null && $valeur !== $decode) ? $decode : $valeur;
        }

        // Ajouter les parametres par defaut s'ils n'existent pas encore
        $parametresDefaut = [
            'reddit_client_id'     => '',
            'reddit_client_secret' => '',
            'reddit_user_agent'    => 'RedditReputationAnalyzer/1.0',
            'periode_defaut'       => 'month',
            'limite_defaut'        => '500',
            'subreddits_favoris'   => '',
            'seuil_alerte_score'   => '40',
            'google_nlp_api_key'   => '',
        ];

        foreach ($parametresDefaut as $cle => $valeurDefaut) {
            if (!isset($resultat[$cle])) {
                $resultat[$cle] = $valeurDefaut;
            }
        }

        echo json_encode([
            'donnees' => $resultat,
            'message' => 'Parametres recuperes avec succes.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    } elseif ($methode === 'POST') {
        // --- Mise a jour des parametres ---
        $corpsRequete = file_get_contents('php://input');

        if ($corpsRequete === false || $corpsRequete === '') {
            http_response_code(400);
            echo json_encode([
                'erreur' => 'Corps de requete vide.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $donnees = json_decode($corpsRequete, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($donnees)) {
            http_response_code(400);
            echo json_encode([
                'erreur' => 'Format JSON invalide. Attendu : objet avec des paires cle/valeur.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Liste des cles autorisees pour eviter l'injection de parametres arbitraires
        $clesAutorisees = [
            'reddit_client_id',
            'reddit_client_secret',
            'reddit_user_agent',
            'periode_defaut',
            'limite_defaut',
            'subreddits_favoris',
            'seuil_alerte_score',
            'google_nlp_api_key',
        ];

        $mises_a_jour = 0;

        foreach ($donnees as $cle => $valeur) {
            if (!is_string($cle) || !in_array($cle, $clesAutorisees, true)) {
                continue;
            }

            $bd->definirParametre($cle, $valeur);
            $mises_a_jour++;
        }

        echo json_encode([
            'succes'  => true,
            'message' => "{$mises_a_jour} parametre(s) mis a jour avec succes.",
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    } else {
        http_response_code(405);
        echo json_encode([
            'erreur' => 'Methode non autorisee. Utilisez GET ou POST.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode([
        'erreur' => 'JSON invalide : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erreur' => 'Erreur interne : ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
