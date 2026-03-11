<?php

declare(strict_types=1);

/**
 * Gestionnaire de base de donnees SQLite pour Reddit Reputation Analyzer.
 *
 * Cree et gere toutes les tables necessaires a l'application.
 * Implemente le patron Singleton pour une connexion unique.
 */
class BaseDonnees
{
    private PDO $pdo;
    private static ?self $instanceUnique = null;

    /**
     * Constructeur : ouvre ou cree la base SQLite et initialise les tables.
     */
    public function __construct()
    {
        $cheminDossier = __DIR__ . '/../data';
        if (!is_dir($cheminDossier)) {
            mkdir($cheminDossier, 0755, true);
        }

        $cheminBase = $cheminDossier . '/reddit-reputation.sqlite';

        $this->pdo = new PDO(
            "sqlite:{$cheminBase}",
            null,
            null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        // Activer les cles etrangeres pour SQLite
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');

        $this->creerTables();
    }

    /**
     * Retourne l'instance unique de la base de donnees (Singleton).
     */
    public static function instance(): self
    {
        if (self::$instanceUnique === null) {
            self::$instanceUnique = new self();
        }

        return self::$instanceUnique;
    }

    /**
     * Retourne la connexion PDO brute.
     */
    public function connexion(): PDO
    {
        return $this->pdo;
    }

    /**
     * Insere une ligne dans la table specifiee.
     *
     * @param string               $table   Nom de la table
     * @param array<string, mixed> $donnees Colonnes => valeurs
     * @return int L'identifiant de la ligne inseree
     */
    public function inserer(string $table, array $donnees): int
    {
        $colonnes = implode(', ', array_keys($donnees));
        $marqueurs = implode(', ', array_fill(0, count($donnees), '?'));

        $sql = "INSERT INTO {$table} ({$colonnes}) VALUES ({$marqueurs})";
        $requete = $this->pdo->prepare($sql);
        $requete->execute(array_values($donnees));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Met a jour des lignes dans la table specifiee.
     *
     * @param string               $table     Nom de la table
     * @param array<string, mixed> $donnees   Colonnes => nouvelles valeurs
     * @param string               $condition Clause WHERE (ex: "id = ?")
     * @param array<int, mixed>    $params    Parametres pour la clause WHERE
     * @return bool Vrai si au moins une ligne a ete modifiee
     */
    public function modifier(string $table, array $donnees, string $condition, array $params): bool
    {
        $clauses = [];
        foreach (array_keys($donnees) as $colonne) {
            $clauses[] = "{$colonne} = ?";
        }
        $clauseSet = implode(', ', $clauses);

        $sql = "UPDATE {$table} SET {$clauseSet} WHERE {$condition}";
        $requete = $this->pdo->prepare($sql);
        $requete->execute([...array_values($donnees), ...$params]);

        return $requete->rowCount() > 0;
    }

    /**
     * Supprime des lignes dans la table specifiee.
     *
     * @param string            $table     Nom de la table
     * @param string            $condition Clause WHERE
     * @param array<int, mixed> $params    Parametres pour la clause WHERE
     * @return bool Vrai si au moins une ligne a ete supprimee
     */
    public function supprimer(string $table, string $condition, array $params): bool
    {
        $sql = "DELETE FROM {$table} WHERE {$condition}";
        $requete = $this->pdo->prepare($sql);
        $requete->execute($params);

        return $requete->rowCount() > 0;
    }

    /**
     * Execute une requete SELECT et retourne toutes les lignes.
     *
     * @param string            $sql    Requete SQL
     * @param array<int, mixed> $params Parametres de la requete
     * @return array<int, array<string, mixed>>
     */
    public function selectionner(string $sql, array $params = []): array
    {
        $requete = $this->pdo->prepare($sql);
        $requete->execute($params);

        return $requete->fetchAll();
    }

    /**
     * Execute une requete SELECT et retourne une seule ligne.
     *
     * @param string            $sql    Requete SQL
     * @param array<int, mixed> $params Parametres de la requete
     * @return array<string, mixed>|null
     */
    public function selectionnerUn(string $sql, array $params = []): ?array
    {
        $requete = $this->pdo->prepare($sql);
        $requete->execute($params);

        $resultat = $requete->fetch();

        return $resultat !== false ? $resultat : null;
    }

    /**
     * Recupere la valeur d'un parametre stocke en base.
     */
    public function obtenirParametre(string $cle, mixed $defaut = null): mixed
    {
        $resultat = $this->selectionnerUn(
            'SELECT valeur FROM parametres WHERE cle = ?',
            [$cle]
        );

        if ($resultat === null) {
            return $defaut;
        }

        return $resultat['valeur'];
    }

    /**
     * Definit ou met a jour un parametre en base.
     */
    public function definirParametre(string $cle, mixed $valeur): void
    {
        $valeurTexte = is_string($valeur) ? $valeur : json_encode($valeur, JSON_THROW_ON_ERROR);

        $existant = $this->selectionnerUn(
            'SELECT id FROM parametres WHERE cle = ?',
            [$cle]
        );

        if ($existant !== null) {
            $this->modifier('parametres', ['valeur' => $valeurTexte], 'cle = ?', [$cle]);
        } else {
            $this->inserer('parametres', ['cle' => $cle, 'valeur' => $valeurTexte]);
        }
    }

    /**
     * Cree toutes les tables si elles n'existent pas encore.
     */
    private function creerTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS marques (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nom TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                date_creation TEXT DEFAULT CURRENT_TIMESTAMP,
                parametres_defaut TEXT
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS analyses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                marque_id INTEGER NOT NULL,
                date_lancement TEXT,
                periode_debut TEXT,
                periode_fin TEXT,
                statut TEXT DEFAULT \'en_attente\',
                score_reputation REAL,
                stats_globales TEXT,
                subreddits_cibles TEXT,
                mots_cles TEXT,
                job_id TEXT,
                FOREIGN KEY (marque_id) REFERENCES marques(id) ON DELETE CASCADE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS publications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                analyse_id INTEGER NOT NULL,
                reddit_id TEXT UNIQUE,
                titre TEXT,
                contenu TEXT,
                url TEXT,
                subreddit TEXT,
                auteur TEXT,
                karma_auteur INTEGER,
                date_publication TEXT,
                score INTEGER,
                ratio_upvote REAL,
                nb_commentaires INTEGER,
                awards INTEGER DEFAULT 0,
                score_sentiment REAL,
                label_sentiment TEXT,
                score_engagement REAL,
                type TEXT DEFAULT \'post\',
                publication_parent_id INTEGER,
                FOREIGN KEY (analyse_id) REFERENCES analyses(id) ON DELETE CASCADE,
                FOREIGN KEY (publication_parent_id) REFERENCES publications(id) ON DELETE SET NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS sujets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                analyse_id INTEGER NOT NULL,
                label TEXT,
                frequence INTEGER,
                sentiment_moyen REAL,
                mots_cles TEXT,
                tendance TEXT,
                FOREIGN KEY (analyse_id) REFERENCES analyses(id) ON DELETE CASCADE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS questions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                analyse_id INTEGER NOT NULL,
                texte TEXT,
                categorie TEXT,
                publication_id INTEGER,
                nb_occurrences INTEGER DEFAULT 1,
                a_reponse_officielle INTEGER DEFAULT 0,
                FOREIGN KEY (analyse_id) REFERENCES analyses(id) ON DELETE CASCADE,
                FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE SET NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS auteurs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                analyse_id INTEGER NOT NULL,
                nom_reddit TEXT,
                karma INTEGER,
                anciennete TEXT,
                score_influence REAL,
                type TEXT DEFAULT \'neutre\',
                nb_publications INTEGER DEFAULT 0,
                FOREIGN KEY (analyse_id) REFERENCES analyses(id) ON DELETE CASCADE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS alertes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                marque_id INTEGER NOT NULL,
                type TEXT,
                message TEXT,
                date_creation TEXT DEFAULT CURRENT_TIMESTAMP,
                lue INTEGER DEFAULT 0,
                FOREIGN KEY (marque_id) REFERENCES marques(id) ON DELETE CASCADE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS parametres (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cle TEXT UNIQUE NOT NULL,
                valeur TEXT
            )
        ');
    }
}
