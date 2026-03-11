<?php

declare(strict_types=1);

/**
 * Client de collecte Reddit multi-mode.
 *
 * Trois modes de collecte :
 * 1. API OAuth2 (si credentials disponibles)
 * 2. Reddit JSON public (sans auth, 10 req/min)
 * 3. Bing site:reddit.com (fallback, scraping des résultats)
 *
 * Le mode est selectionne automatiquement selon les credentials disponibles.
 */
class CollecteurReddit
{
    private ?string $clientId;
    private ?string $clientSecret;
    private string $userAgent;
    private ?string $accessToken = null;
    private int $tokenExpiration = 0;
    private float $derniereRequete = 0.0;
    private string $mode;

    private const string URL_AUTHENTIFICATION = 'https://www.reddit.com/api/v1/access_token';
    private const string URL_API = 'https://oauth.reddit.com';
    private const string URL_REDDIT_PUBLIC = 'https://www.reddit.com';
    private const string URL_BING = 'https://www.bing.com/search';

    /** @var callable|null Callback de progression */
    private $rappelProgression = null;

    /**
     * Constructeur : detecte automatiquement le mode disponible.
     *
     * Modes :
     * - 'api' : credentials Reddit API disponibles
     * - 'json_public' : pas de credentials, utilise les endpoints .json publics
     * - 'bing' : fallback via scraping Bing site:reddit.com
     */
    public function __construct(?string $modeSouhaite = null)
    {
        $this->clientId = $_ENV['REDDIT_CLIENT_ID'] ?? getenv('REDDIT_CLIENT_ID') ?: null;
        $this->clientSecret = $_ENV['REDDIT_CLIENT_SECRET'] ?? getenv('REDDIT_CLIENT_SECRET') ?: null;
        $this->userAgent = $_ENV['REDDIT_USER_AGENT'] ?? getenv('REDDIT_USER_AGENT')
            ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Detection automatique du mode
        if ($modeSouhaite !== null) {
            $this->mode = $modeSouhaite;
        } elseif (!empty($this->clientId) && !empty($this->clientSecret)) {
            $this->mode = 'api';
        } else {
            // Tester si Reddit JSON public fonctionne
            $this->mode = 'json_public';
        }
    }

    /**
     * Retourne le mode de collecte actif.
     */
    public function obtenirMode(): string
    {
        return $this->mode;
    }

    /**
     * Authentification OAuth2 (mode API uniquement).
     *
     * @throws RuntimeException En cas d'echec
     */
    public function authentifier(): void
    {
        if ($this->mode !== 'api') {
            return; // Pas necessaire en mode public ou Bing
        }

        $this->respecterRateLimit(1.0);

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
                'ignore_errors' => true,
            ],
        ]);

        $reponse = @file_get_contents(self::URL_AUTHENTIFICATION, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            // Basculer en mode public
            $this->mode = 'json_public';
            return;
        }

        $donnees = json_decode($reponse, true, 512, JSON_THROW_ON_ERROR);

        if (isset($donnees['error']) || !isset($donnees['access_token'])) {
            // Basculer en mode public
            $this->mode = 'json_public';
            return;
        }

        $this->accessToken = $donnees['access_token'];
        $this->tokenExpiration = time() + ($donnees['expires_in'] ?? 3600) - 60;
    }

    /**
     * Recherche les publications mentionnant une marque.
     *
     * @param string        $marque     Nom de la marque
     * @param string        $periode    Periode (hour/day/week/month/year/all)
     * @param int           $limite     Nombre max de publications
     * @param array<string> $subreddits Subreddits cibles
     * @param array<string> $motsCles   Mots-cles supplementaires
     * @param callable|null $rappelProgression Callback(int $nbCollectees)
     * @return array<int, array<string, mixed>>
     */
    public function rechercherPublications(
        string $marque,
        string $periode = 'month',
        int $limite = 500,
        array $subreddits = [],
        array $motsCles = [],
        ?callable $rappelProgression = null,
    ): array {
        $this->rappelProgression = $rappelProgression;

        return match ($this->mode) {
            'api'         => $this->rechercherViaApi($marque, $periode, $limite, $subreddits, $motsCles),
            'json_public' => $this->rechercherViaJsonPublic($marque, $periode, $limite, $subreddits, $motsCles),
            'bing'        => $this->rechercherViaBing($marque, $periode, $limite, $subreddits, $motsCles),
            default       => $this->rechercherViaJsonPublic($marque, $periode, $limite, $subreddits, $motsCles),
        };
    }

    /**
     * Recupere les commentaires d'une publication.
     *
     * @param string $redditId  Identifiant Reddit (t3_xxx ou juste xxx)
     * @param int    $limite    Nombre max de commentaires
     * @return array<int, array<string, mixed>>
     */
    public function recupererCommentaires(string $redditId, int $limite = 200): array
    {
        $idNettoye = str_starts_with($redditId, 't3_') ? substr($redditId, 3) : $redditId;

        return match ($this->mode) {
            'api'    => $this->commentairesViaApi($idNettoye, $limite),
            default  => $this->commentairesViaJsonPublic($idNettoye, $limite),
        };
    }

    /* ========================================================================
       MODE 1 : API OAuth2
       ======================================================================== */

    private function rechercherViaApi(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        if ($this->accessToken === null || time() >= $this->tokenExpiration) {
            $this->authentifier();
            if ($this->mode !== 'api') {
                // Fallback si auth echouee
                return $this->rechercherViaJsonPublic($marque, $periode, $limite, $subreddits, $motsCles);
            }
        }

        $publications = [];
        $apres = null;
        $requete = $this->construireRequete($marque, $motsCles);
        $parPage = min($limite, 100);

        while (count($publications) < $limite) {
            $params = [
                'q'     => $requete,
                'sort'  => 'relevance',
                't'     => $periode,
                'limit' => $parPage,
                'type'  => 'link',
            ];
            if ($apres !== null) {
                $params['after'] = $apres;
            }

            $endpoint = !empty($subreddits)
                ? '/r/' . implode('+', $subreddits) . '/search.json'
                : '/search.json';

            if (!empty($subreddits)) {
                $params['restrict_sr'] = 'on';
            }

            $reponse = $this->requeteApiOauth($endpoint, $params);
            $enfants = $reponse['data']['children'] ?? [];

            if (empty($enfants)) {
                break;
            }

            foreach ($enfants as $enfant) {
                $publications[] = $this->formaterPublication($enfant['data'] ?? []);
                $this->notifierProgression(count($publications));
                if (count($publications) >= $limite) {
                    break;
                }
            }

            $apres = $reponse['data']['after'] ?? null;
            if ($apres === null) {
                break;
            }
        }

        return $publications;
    }

    private function requeteApiOauth(string $endpoint, array $params = []): array
    {
        $this->respecterRateLimit(1.0);

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
                'ignore_errors' => true,
            ],
        ]);

        $reponse = @file_get_contents($url, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            return [];
        }

        return json_decode($reponse, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function commentairesViaApi(string $id, int $limite): array
    {
        $this->respecterRateLimit(1.0);

        $url = self::URL_API . "/comments/{$id}.json?" . http_build_query([
            'limit' => $limite,
            'sort'  => 'top',
            'depth' => 10,
        ]);

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    "Authorization: Bearer {$this->accessToken}",
                    "User-Agent: {$this->userAgent}",
                ]),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $reponse = @file_get_contents($url, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            return [];
        }

        $donnees = json_decode($reponse, true, 512, JSON_THROW_ON_ERROR);
        $commentaires = [];
        $listeCommentaires = $donnees[1]['data']['children'] ?? [];
        $this->extraireCommentairesRecursif($listeCommentaires, $commentaires, $limite);

        return $commentaires;
    }

    /* ========================================================================
       MODE 2 : Reddit JSON public (sans auth)
       ======================================================================== */

    private function rechercherViaJsonPublic(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        $publications = [];
        $apres = null;
        $requete = $this->construireRequete($marque, $motsCles);
        $parPage = min($limite, 25); // JSON public limite a 25 par page plus souvent

        while (count($publications) < $limite) {
            $params = [
                'q'        => $requete,
                'sort'     => 'relevance',
                't'        => $periode,
                'limit'    => $parPage,
                'type'     => 'link',
                'raw_json' => 1,
            ];
            if ($apres !== null) {
                $params['after'] = $apres;
            }

            $endpoint = !empty($subreddits)
                ? '/r/' . implode('+', $subreddits) . '/search.json'
                : '/search.json';

            if (!empty($subreddits)) {
                $params['restrict_sr'] = 'on';
            }

            $reponse = $this->requeteJsonPublic($endpoint, $params);

            if ($reponse === null) {
                // JSON public echoue, basculer vers Bing
                if ($this->mode === 'json_public') {
                    $this->mode = 'bing';
                    $pubsBing = $this->rechercherViaBing($marque, $periode, $limite - count($publications), $subreddits, $motsCles);
                    return array_merge($publications, $pubsBing);
                }
                break;
            }

            $enfants = $reponse['data']['children'] ?? [];
            if (empty($enfants)) {
                break;
            }

            foreach ($enfants as $enfant) {
                $publications[] = $this->formaterPublication($enfant['data'] ?? []);
                $this->notifierProgression(count($publications));
                if (count($publications) >= $limite) {
                    break;
                }
            }

            $apres = $reponse['data']['after'] ?? null;
            if ($apres === null) {
                break;
            }
        }

        return $publications;
    }

    private function requeteJsonPublic(string $endpoint, array $params = []): ?array
    {
        // Rate limit plus conservateur pour le mode public : 6 req/min
        $this->respecterRateLimit(10.0);

        $url = self::URL_REDDIT_PUBLIC . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    "User-Agent: {$this->userAgent}",
                    'Accept: application/json',
                ]),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $reponse = @file_get_contents($url, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            return null;
        }

        // Verifier si on a ete bloque (429, 403, page HTML au lieu de JSON)
        if (str_starts_with(trim($reponse), '<') || str_starts_with(trim($reponse), '<!')) {
            return null;
        }

        $donnees = json_decode($reponse, true, 512);
        if ($donnees === null || isset($donnees['error'])) {
            return null;
        }

        return $donnees;
    }

    private function commentairesViaJsonPublic(string $id, int $limite): array
    {
        $reponse = $this->requeteJsonPublic("/comments/{$id}.json", [
            'limit'    => $limite,
            'sort'     => 'top',
            'depth'    => 10,
            'raw_json' => 1,
        ]);

        if ($reponse === null) {
            return [];
        }

        $commentaires = [];
        // La reponse publique est aussi un tableau [post, commentaires]
        $listeCommentaires = $reponse[1]['data']['children'] ?? [];
        $this->extraireCommentairesRecursif($listeCommentaires, $commentaires, $limite);

        return $commentaires;
    }

    /* ========================================================================
       MODE 3 : Bing site:reddit.com (fallback)
       ======================================================================== */

    private function rechercherViaBing(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        $publications = [];
        $requete = $this->construireRequeteBing($marque, $subreddits, $motsCles);
        $offset = 0;
        $parPage = 10; // Bing renvoie ~10 resultats par page

        while (count($publications) < $limite && $offset < 100) {
            // Bing ne permet pas facilement plus de ~100 resultats
            $urlsBing = $this->scraperBing($requete, $offset);

            if (empty($urlsBing)) {
                break;
            }

            foreach ($urlsBing as $urlReddit) {
                if (count($publications) >= $limite) {
                    break;
                }

                // Extraire les donnees du post Reddit via JSON public
                $pub = $this->extrairePostDepuisUrl($urlReddit);
                if ($pub !== null) {
                    $publications[] = $pub;
                    $this->notifierProgression(count($publications));
                }
            }

            $offset += $parPage;
        }

        return $publications;
    }

    /**
     * Scrape les resultats Bing pour obtenir des URLs Reddit.
     *
     * @return array<string> URLs Reddit trouvees
     */
    private function scraperBing(string $requete, int $offset = 0): array
    {
        $this->respecterRateLimit(3.0);

        $params = [
            'q'     => $requete,
            'first' => $offset + 1,
            'count' => 10,
        ];

        $url = self::URL_BING . '?' . http_build_query($params);

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    "User-Agent: {$this->userAgent}",
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en-US,en;q=0.9,fr;q=0.8',
                ]),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($html === false) {
            return [];
        }

        // Extraire les URLs reddit.com des resultats Bing
        $urls = [];
        // Pattern pour les liens dans les resultats Bing
        if (preg_match_all('#href="(https?://(?:www\.)?reddit\.com/r/[^"]+/comments/[^"]+)"#i', $html, $matches)) {
            $urls = array_unique($matches[1]);
        }

        // Pattern alternatif dans les attributs cite
        if (empty($urls) && preg_match_all('#<cite[^>]*>(https?://(?:www\.)?reddit\.com/r/\w+/comments/\w+[^<]*)</cite>#i', $html, $matches)) {
            foreach ($matches[1] as $urlCite) {
                $urlCite = strip_tags($urlCite);
                $urlCite = html_entity_decode($urlCite, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Nettoyer et normaliser
                if (str_contains($urlCite, 'reddit.com/r/') && str_contains($urlCite, '/comments/')) {
                    $urls[] = 'https://www.reddit.com' . parse_url($urlCite, PHP_URL_PATH);
                }
            }
            $urls = array_unique($urls);
        }

        return array_values($urls);
    }

    /**
     * Extrait les donnees d'un post Reddit a partir de son URL via l'endpoint .json public.
     *
     * @return array<string, mixed>|null Publication formatee ou null si erreur
     */
    private function extrairePostDepuisUrl(string $url): ?array
    {
        // Nettoyer l'URL et ajouter .json
        $urlNettoyee = rtrim(preg_replace('#\?.*$#', '', $url), '/');
        $urlJson = $urlNettoyee . '.json?raw_json=1&limit=0';

        $this->respecterRateLimit(10.0);

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    "User-Agent: {$this->userAgent}",
                    'Accept: application/json',
                ]),
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => true,
                'max_redirects' => 3,
            ],
        ]);

        $reponse = @file_get_contents($urlJson, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false || str_starts_with(trim($reponse), '<')) {
            return null;
        }

        $donnees = json_decode($reponse, true, 512);
        if ($donnees === null || !isset($donnees[0]['data']['children'][0])) {
            return null;
        }

        $postData = $donnees[0]['data']['children'][0]['data'] ?? [];
        if (empty($postData)) {
            return null;
        }

        return $this->formaterPublication($postData);
    }

    /* ========================================================================
       UTILITAIRES COMMUNS
       ======================================================================== */

    /**
     * Construit la requete de recherche Reddit.
     */
    private function construireRequete(string $marque, array $motsCles): string
    {
        $termes = ['"' . $marque . '"'];
        foreach ($motsCles as $motCle) {
            $motCle = trim($motCle);
            if ($motCle !== '') {
                $termes[] = '"' . $motCle . '"';
            }
        }
        return implode(' OR ', $termes);
    }

    /**
     * Construit la requete Bing avec site:reddit.com
     */
    private function construireRequeteBing(string $marque, array $subreddits, array $motsCles): string
    {
        $requete = 'site:reddit.com "' . $marque . '"';

        if (!empty($motsCles)) {
            $requete .= ' (' . implode(' OR ', array_map(fn(string $m): string => '"' . trim($m) . '"', $motsCles)) . ')';
        }

        if (!empty($subreddits)) {
            // Restreindre a certains subreddits
            $subs = array_map(fn(string $s): string => 'site:reddit.com/r/' . trim($s), $subreddits);
            $requete = '"' . $marque . '" (' . implode(' OR ', $subs) . ')';
        }

        return $requete;
    }

    /**
     * Formate une publication Reddit.
     *
     * @param array<string, mixed> $donnees Donnees brutes
     * @return array<string, mixed>
     */
    private function formaterPublication(array $donnees): array
    {
        return [
            'reddit_id'        => $donnees['name'] ?? ('t3_' . ($donnees['id'] ?? '')),
            'titre'            => $donnees['title'] ?? '',
            'contenu'          => $donnees['selftext'] ?? '',
            'url'              => isset($donnees['permalink'])
                ? 'https://www.reddit.com' . $donnees['permalink']
                : ($donnees['url'] ?? ''),
            'subreddit'        => $donnees['subreddit'] ?? '',
            'auteur'           => $donnees['author'] ?? '[supprime]',
            'karma_auteur'     => null,
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
     * Formate un commentaire Reddit.
     */
    private function formaterCommentaire(array $donnees): array
    {
        return [
            'reddit_id'        => $donnees['name'] ?? ('t1_' . ($donnees['id'] ?? '')),
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
            'ratio_upvote'     => null,
            'nb_commentaires'  => 0,
            'awards'           => (int) ($donnees['total_awards_received'] ?? 0),
            'type'             => 'commentaire',
        ];
    }

    /**
     * Extrait recursivement les commentaires.
     */
    private function extraireCommentairesRecursif(array $enfants, array &$commentaires, int $limite): void
    {
        foreach ($enfants as $enfant) {
            if (count($commentaires) >= $limite) {
                return;
            }

            if (($enfant['kind'] ?? '') !== 't1') {
                continue;
            }

            $donnees = $enfant['data'] ?? [];
            $commentaires[] = $this->formaterCommentaire($donnees);

            $reponses = $donnees['replies']['data']['children'] ?? [];
            if (!empty($reponses)) {
                $this->extraireCommentairesRecursif($reponses, $commentaires, $limite);
            }
        }
    }

    /**
     * Respecte un delai minimum entre les requetes.
     */
    private function respecterRateLimit(float $delaiSecondes): void
    {
        if ($this->derniereRequete <= 0.0) {
            return;
        }

        $tempsEcoule = microtime(true) - $this->derniereRequete;
        $delaiRestant = $delaiSecondes - $tempsEcoule;

        if ($delaiRestant > 0) {
            usleep((int) ($delaiRestant * 1_000_000));
        }
    }

    /**
     * Notifie le callback de progression si defini.
     */
    private function notifierProgression(int $nbCollectees): void
    {
        if ($this->rappelProgression !== null) {
            ($this->rappelProgression)($nbCollectees);
        }
    }
}
