<?php

declare(strict_types=1);

/**
 * Client API Reddit avec authentification OAuth2 (client_credentials).
 *
 * Gere la collecte de publications et commentaires mentionnant une marque.
 * Respecte le rate limiting de l'API Reddit (1 requete/seconde minimum).
 */
class CollecteurReddit
{
    private string $clientId;
    private string $clientSecret;
    private string $userAgent;
    private ?string $accessToken = null;
    private int $tokenExpiration = 0;
    private float $derniereRequete = 0.0;

    private const string URL_AUTHENTIFICATION = 'https://www.reddit.com/api/v1/access_token';
    private const string URL_API = 'https://oauth.reddit.com';
    private const float DELAI_MINIMUM_REQUETE = 1.0; // secondes

    /**
     * Constructeur : lit les identifiants depuis les variables d'environnement.
     *
     * Variables attendues : REDDIT_CLIENT_ID, REDDIT_CLIENT_SECRET, REDDIT_USER_AGENT
     *
     * @throws RuntimeException Si les identifiants sont manquants
     */
    public function __construct()
    {
        $this->clientId = $this->obtenirEnv('REDDIT_CLIENT_ID');
        $this->clientSecret = $this->obtenirEnv('REDDIT_CLIENT_SECRET');
        $this->userAgent = $this->obtenirEnv(
            'REDDIT_USER_AGENT',
            'RedditReputationAnalyzer/1.0'
        );
    }

    /**
     * Authentification OAuth2 avec le flux client_credentials.
     *
     * @throws RuntimeException En cas d'echec d'authentification
     */
    public function authentifier(): void
    {
        $this->respecterRateLimit();

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}"),
                    "User-Agent: {$this->userAgent}",
                ]),
                'content' => http_build_query(['grant_type' => 'client_credentials']),
                'timeout' => 30,
            ],
        ]);

        $reponse = @file_get_contents(self::URL_AUTHENTIFICATION, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            throw new RuntimeException(
                'Echec de l\'authentification Reddit : impossible de contacter le serveur'
            );
        }

        /** @var array{access_token?: string, expires_in?: int, error?: string} $donnees */
        $donnees = json_decode($reponse, true, 512, JSON_THROW_ON_ERROR);

        if (isset($donnees['error'])) {
            throw new RuntimeException(
                "Echec de l'authentification Reddit : {$donnees['error']}"
            );
        }

        if (!isset($donnees['access_token'])) {
            throw new RuntimeException(
                'Echec de l\'authentification Reddit : token absent de la reponse'
            );
        }

        $this->accessToken = $donnees['access_token'];
        // Renouveler 60 secondes avant l'expiration reelle
        $this->tokenExpiration = time() + ($donnees['expires_in'] ?? 3600) - 60;
    }

    /**
     * Execute une requete GET vers l'API Reddit.
     *
     * @param string              $endpoint Point d'acces API (ex: /search)
     * @param array<string, mixed> $params   Parametres de requete
     * @return array<string, mixed> Donnees de la reponse decodees
     *
     * @throws RuntimeException En cas d'erreur API
     */
    public function requeteApi(string $endpoint, array $params = []): array
    {
        // Renouveler le token si expire ou absent
        if ($this->accessToken === null || time() >= $this->tokenExpiration) {
            $this->authentifier();
        }

        $this->respecterRateLimit();

        $url = self::URL_API . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    "Authorization: Bearer {$this->accessToken}",
                    "User-Agent: {$this->userAgent}",
                ]),
                'timeout' => 30,
            ],
        ]);

        $reponse = @file_get_contents($url, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            throw new RuntimeException(
                "Echec de la requete API Reddit : {$endpoint}"
            );
        }

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($reponse, true, 512, JSON_THROW_ON_ERROR);

        return $donnees;
    }

    /**
     * Recherche les publications mentionnant une marque sur Reddit.
     *
     * @param string        $marque     Nom de la marque a rechercher
     * @param string        $periode    Periode de recherche (hour/day/week/month/year/all)
     * @param int           $limite     Nombre maximum de publications a collecter
     * @param array<string> $subreddits Liste de subreddits cibles (vide = tous)
     * @param array<string> $motsCles   Mots-cles supplementaires
     * @return array<int, array<string, mixed>> Publications formatees
     */
    public function rechercherPublications(
        string $marque,
        string $periode = 'month',
        int $limite = 500,
        array $subreddits = [],
        array $motsCles = [],
    ): array {
        $publications = [];
        $apres = null;
        $requeteRecherche = $this->construireRequeteRecherche($marque, $motsCles);
        $parPage = min($limite, 100); // Reddit limite a 100 par page

        while (count($publications) < $limite) {
            $params = [
                'q'     => $requeteRecherche,
                'sort'  => 'relevance',
                't'     => $periode,
                'limit' => $parPage,
                'type'  => 'link',
            ];

            if ($apres !== null) {
                $params['after'] = $apres;
            }

            // Recherche dans un subreddit specifique ou globale
            if (!empty($subreddits)) {
                $endpoint = '/r/' . implode('+', $subreddits) . '/search.json';
                $params['restrict_sr'] = 'on';
            } else {
                $endpoint = '/search.json';
            }

            $reponse = $this->requeteApi($endpoint, $params);

            $enfants = $reponse['data']['children'] ?? [];
            if (empty($enfants)) {
                break; // Plus de resultats
            }

            foreach ($enfants as $enfant) {
                /** @var array<string, mixed> $donnees */
                $donnees = $enfant['data'] ?? [];

                $publications[] = $this->formaterPublication($donnees);

                if (count($publications) >= $limite) {
                    break;
                }
            }

            $apres = $reponse['data']['after'] ?? null;
            if ($apres === null) {
                break; // Derniere page
            }
        }

        return $publications;
    }

    /**
     * Recupere les commentaires d'une publication Reddit.
     *
     * @param string $redditId Identifiant Reddit de la publication (ex: t3_abc123)
     * @param int    $limite   Nombre maximum de commentaires
     * @return array<int, array<string, mixed>> Commentaires formates
     */
    public function recupererCommentaires(string $redditId, int $limite = 200): array
    {
        // Retirer le prefixe t3_ si present
        $idNettoye = str_starts_with($redditId, 't3_') ? substr($redditId, 3) : $redditId;

        $reponse = $this->requeteApi("/comments/{$idNettoye}.json", [
            'limit' => $limite,
            'sort'  => 'top',
            'depth' => 10,
        ]);

        $commentaires = [];

        // La reponse contient deux listings : [0] = post, [1] = commentaires
        $listeCommentaires = $reponse[1]['data']['children'] ?? [];

        $this->extraireCommentairesRecursif($listeCommentaires, $commentaires, $limite);

        return $commentaires;
    }

    /**
     * Assure un delai minimum entre les requetes pour respecter le rate limiting.
     */
    private function respecterRateLimit(): void
    {
        if ($this->derniereRequete <= 0.0) {
            return;
        }

        $tempsEcoule = microtime(true) - $this->derniereRequete;
        $delaiRestant = self::DELAI_MINIMUM_REQUETE - $tempsEcoule;

        if ($delaiRestant > 0) {
            usleep((int) ($delaiRestant * 1_000_000));
        }
    }

    /**
     * Construit la chaine de requete de recherche Reddit.
     *
     * @param string        $marque   Nom de la marque
     * @param array<string> $motsCles Mots-cles supplementaires
     */
    private function construireRequeteRecherche(string $marque, array $motsCles): string
    {
        // La marque est toujours le terme principal entre guillemets
        $termes = ['"' . $marque . '"'];

        if (!empty($motsCles)) {
            // Ajouter les mots-cles comme termes alternatifs (OR)
            foreach ($motsCles as $motCle) {
                $termes[] = '"' . $motCle . '"';
            }
        }

        return implode(' OR ', $termes);
    }

    /**
     * Formate les donnees brutes d'une publication Reddit.
     *
     * @param array<string, mixed> $donnees Donnees brutes de l'API
     * @return array<string, mixed> Publication formatee
     */
    private function formaterPublication(array $donnees): array
    {
        return [
            'reddit_id'        => $donnees['name'] ?? '',
            'titre'            => $donnees['title'] ?? '',
            'contenu'          => $donnees['selftext'] ?? '',
            'url'              => 'https://www.reddit.com' . ($donnees['permalink'] ?? ''),
            'subreddit'        => $donnees['subreddit'] ?? '',
            'auteur'           => $donnees['author'] ?? '[supprime]',
            'karma_auteur'     => null, // Non disponible dans les resultats de recherche
            'date_publication' => isset($donnees['created_utc'])
                ? date('Y-m-d H:i:s', (int) $donnees['created_utc'])
                : null,
            'score'            => (int) ($donnees['score'] ?? 0),
            'ratio_upvote'     => (float) ($donnees['upvote_ratio'] ?? 0.0),
            'nb_commentaires'  => (int) ($donnees['num_comments'] ?? 0),
            'awards'           => (int) ($donnees['total_awards_received'] ?? 0),
            'type'             => 'post',
        ];
    }

    /**
     * Formate les donnees brutes d'un commentaire Reddit.
     *
     * @param array<string, mixed> $donnees Donnees brutes de l'API
     * @return array<string, mixed> Commentaire formate
     */
    private function formaterCommentaire(array $donnees): array
    {
        return [
            'reddit_id'        => $donnees['name'] ?? '',
            'titre'            => null,
            'contenu'          => $donnees['body'] ?? '',
            'url'              => isset($donnees['permalink'])
                ? 'https://www.reddit.com' . $donnees['permalink']
                : null,
            'subreddit'        => $donnees['subreddit'] ?? '',
            'auteur'           => $donnees['author'] ?? '[supprime]',
            'karma_auteur'     => null,
            'date_publication' => isset($donnees['created_utc'])
                ? date('Y-m-d H:i:s', (int) $donnees['created_utc'])
                : null,
            'score'            => (int) ($donnees['score'] ?? 0),
            'ratio_upvote'     => null, // Non disponible pour les commentaires
            'nb_commentaires'  => 0,
            'awards'           => (int) ($donnees['total_awards_received'] ?? 0),
            'type'             => 'commentaire',
        ];
    }

    /**
     * Extrait recursivement les commentaires depuis l'arbre de reponses Reddit.
     *
     * @param array<int, array<string, mixed>> $enfants      Noeuds enfants
     * @param array<int, array<string, mixed>> $commentaires Accumulateur (par reference)
     * @param int                              $limite       Nombre maximum
     */
    private function extraireCommentairesRecursif(
        array $enfants,
        array &$commentaires,
        int $limite,
    ): void {
        foreach ($enfants as $enfant) {
            if (count($commentaires) >= $limite) {
                return;
            }

            $type = $enfant['kind'] ?? '';
            if ($type !== 't1') {
                continue; // Ignorer les noeuds "more" et autres
            }

            $donnees = $enfant['data'] ?? [];
            $commentaires[] = $this->formaterCommentaire($donnees);

            // Traiter les reponses imbriquees
            $reponses = $donnees['replies']['data']['children'] ?? [];
            if (!empty($reponses)) {
                $this->extraireCommentairesRecursif($reponses, $commentaires, $limite);
            }
        }
    }

    /**
     * Recupere une variable d'environnement avec valeur par defaut optionnelle.
     *
     * @throws RuntimeException Si la variable est requise et absente
     */
    private function obtenirEnv(string $cle, ?string $defaut = null): string
    {
        $valeur = $_ENV[$cle] ?? getenv($cle) ?: null;

        if ($valeur === null && $defaut === null) {
            throw new RuntimeException(
                "Variable d'environnement requise manquante : {$cle}"
            );
        }

        return $valeur ?? $defaut;
    }
}
