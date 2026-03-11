<?php

declare(strict_types=1);

/**
 * Fichier d'amorçage de l'application Reddit Reputation Analyzer.
 *
 * - Demarre la session si non active
 * - Enregistre un autoloader PSR-4 pour le namespace src/
 * - Charge les variables d'environnement depuis .env
 */

// --- Demarrage de la session ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Autoloader PSR-4 pour le repertoire src/ ---
spl_autoload_register(function (string $nomClasse): void {
    // On suppose que toutes les classes sont directement dans src/
    $cheminFichier = __DIR__ . '/src/' . $nomClasse . '.php';

    if (file_exists($cheminFichier)) {
        require_once $cheminFichier;
    }
});

// --- Chargement des variables d'environnement ---
/**
 * Charge un fichier .env et injecte les variables via putenv().
 * Les variables deja presentes dans $_ENV (plateforme) ont priorite.
 */
function chargerEnvironnement(string $cheminFichier): void
{
    if (!file_exists($cheminFichier)) {
        return;
    }

    $lignes = file($cheminFichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lignes === false) {
        return;
    }

    foreach ($lignes as $ligne) {
        // Ignorer les commentaires
        $ligne = trim($ligne);
        if ($ligne === '' || str_starts_with($ligne, '#')) {
            continue;
        }

        // Verifier le format CLE=VALEUR
        $positionEgal = strpos($ligne, '=');
        if ($positionEgal === false) {
            continue;
        }

        $cle = trim(substr($ligne, 0, $positionEgal));
        $valeur = trim(substr($ligne, $positionEgal + 1));

        // Retirer les guillemets englobants si presents
        if (
            (str_starts_with($valeur, '"') && str_ends_with($valeur, '"'))
            || (str_starts_with($valeur, "'") && str_ends_with($valeur, "'"))
        ) {
            $valeur = substr($valeur, 1, -1);
        }

        // Priorite aux variables de plateforme ($_ENV)
        if (isset($_ENV[$cle])) {
            continue;
        }

        // Injection de la variable d'environnement
        putenv("{$cle}={$valeur}");
        $_ENV[$cle] = $valeur;
    }
}

// Charger le fichier .env a la racine du projet
chargerEnvironnement(__DIR__ . '/.env');
