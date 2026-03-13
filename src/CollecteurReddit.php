<?php

declare(strict_types=1);

/**
 * Client de collecte Reddit multi-mode.
 *
 * Trois modes de collecte :
 * 1. API OAuth2 (si credentials disponibles)
 * 2. Reddit JSON public (sans auth, 10 req/min)
 * 3. DuckDuckGo site:reddit.com (fallback, scraping des resultats)
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

    /** Chemin du fichier cookie jar pour maintenir la session navigateur */
    private string $cheminCookies;

    /** Indique si la session navigateur a ete initialisee (cookies Reddit obtenus) */
    private bool $sessionInitialisee = false;

    /** URL du proxy HTTP(S) (ex: http://user:pass@proxy:port) */
    private ?string $proxy = null;

    /** Cle API SerpAPI pour la recherche Google site:reddit.com */
    private ?string $cleApiSerp = null;

    private const string URL_AUTHENTIFICATION = 'https://www.reddit.com/api/v1/access_token';
    private const string URL_API = 'https://oauth.reddit.com';
    private const string URL_DDG = 'https://html.duckduckgo.com/html/';
    private const string URL_SERPAPI = 'https://serpapi.com/search.json';

    /** Domaines Reddit a essayer dans l'ordre (old.reddit est moins protege) */
    private const array DOMAINES_REDDIT = [
        'https://old.reddit.com',
        'https://www.reddit.com',
    ];

    /** Domaine Reddit actif (determine lors de l'init session) */
    private string $urlRedditPublic = 'https://old.reddit.com';

    /** Version Chrome emulee — a mettre a jour periodiquement */
    private const string CHROME_VERSION = '131.0.0.0';
    private const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** @var callable|null Callback de progression */
    private $rappelProgression = null;

    /** @var callable|null Callback de journalisation */
    private $rappelJournal = null;

    /**
     * Constructeur : detecte automatiquement le mode disponible.
     *
     * Modes :
     * - 'api' : credentials Reddit API disponibles
     * - 'json_public' : pas de credentials, utilise les endpoints .json publics
     * - 'ddg' : fallback via scraping DuckDuckGo site:reddit.com
     */
    public function __construct(?string $modeSouhaite = null)
    {
        $this->clientId = $_ENV['REDDIT_CLIENT_ID'] ?? getenv('REDDIT_CLIENT_ID') ?: null;
        $this->clientSecret = $_ENV['REDDIT_CLIENT_SECRET'] ?? getenv('REDDIT_CLIENT_SECRET') ?: null;
        $this->userAgent = self::USER_AGENT;

        // Proxy HTTP optionnel (pour les serveurs sur IP datacenter bloques par Reddit)
        $proxy = $_ENV['REDDIT_PROXY'] ?? getenv('REDDIT_PROXY') ?: null;
        $this->proxy = !empty($proxy) ? $proxy : null;

        // SerpAPI (recherche Google site:reddit.com) — contourne le blocage IP datacenter
        $cleSerp = $_ENV['SERPAPI_KEY'] ?? getenv('SERPAPI_KEY') ?: null;
        $this->cleApiSerp = !empty($cleSerp) ? $cleSerp : null;

        // Cookie jar dans le dossier data/ du plugin (persistant entre requetes)
        $dossierData = __DIR__ . '/../data';
        if (!is_dir($dossierData)) {
            @mkdir($dossierData, 0755, true);
        }
        $this->cheminCookies = $dossierData . '/cookies_reddit.txt';

        // Detection automatique du mode
        if ($modeSouhaite !== null) {
            $this->mode = $modeSouhaite;
        } elseif (!empty($this->clientId) && !empty($this->clientSecret)) {
            $this->mode = 'api';
        } else {
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
     * Initialise une session navigateur en visitant Reddit pour obtenir les cookies.
     *
     * Essaie old.reddit.com en priorite (moins de protection anti-bot),
     * puis www.reddit.com en fallback. Le domaine qui repond 200 est retenu
     * pour toutes les requetes suivantes.
     */
    private function initialiserSessionNavigateur(): void
    {
        if ($this->sessionInitialisee) {
            return;
        }

        // Si le cookie jar existe et est recent (< 30 min), reutiliser
        if (file_exists($this->cheminCookies) && (time() - filemtime($this->cheminCookies)) < 1800) {
            $this->sessionInitialisee = true;
            $this->journaliserCollecte("Reutilisation du cookie jar existant ({$this->urlRedditPublic})");
            return;
        }

        // Essayer chaque domaine jusqu'a en trouver un qui repond 200
        foreach (self::DOMAINES_REDDIT as $domaine) {
            $this->journaliserCollecte("Initialisation session navigateur ({$domaine})");

            $resultat = $this->requeteCurl($domaine . '/', $this->headersNavigateurHtml());
            $codeHttp = $resultat['code_http'];

            if ($resultat['corps'] !== false && $codeHttp === 200) {
                $this->urlRedditPublic = $domaine;
                $this->sessionInitialisee = true;
                $this->journaliserCollecte("Session initialisee via {$domaine} (cookies obtenus)", 'success');
                return;
            }

            $this->journaliserCollecte("{$domaine} : HTTP {$codeHttp}", 'warning');

            // Supprimer les cookies invalides avant d'essayer le domaine suivant
            @unlink($this->cheminCookies);
        }

        // Aucun domaine n'a fonctionne — on continue quand meme (DuckDuckGo prendra le relais)
        $this->sessionInitialisee = true;
        $this->journaliserCollecte("Impossible d'initialiser la session Reddit (tous les domaines bloques)", 'warning');
    }

    /**
     * Headers Chrome complets pour les requetes HTML (initialisation session).
     *
     * @return array<string>
     */
    private function headersNavigateurHtml(): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,fr;q=0.8',
            'Cache-Control: max-age=0',
            'Sec-Ch-Ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ];
    }

    /**
     * Headers Chrome complets pour les requetes JSON (API publique Reddit).
     *
     * @return array<string>
     */
    private function headersNavigateurJson(): array
    {
        return [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9,fr;q=0.8',
            'Sec-Ch-Ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            "Referer: {$this->urlRedditPublic}/",
        ];
    }

    /**
     * Execute une requete cURL avec emulation navigateur complete.
     *
     * @param array<string> $headers Headers HTTP
     * @return array{corps: string|false, code_http: int} Reponse et code HTTP
     */
    private function requeteCurl(
        string $url,
        array $headers,
        string $methode = 'GET',
        ?string $corpsPost = null,
    ): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIEJAR      => $this->cheminCookies,
            CURLOPT_COOKIEFILE     => $this->cheminCookies,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_USERAGENT      => $this->userAgent,
        ]);

        // Proxy HTTP/HTTPS/SOCKS5 (pour les IPs datacenter bloquees par Reddit)
        if ($this->proxy !== null) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        if ($methode === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($corpsPost !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $corpsPost);
            }
        }

        $corps = curl_exec($ch);
        $codeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->derniereRequete = microtime(true);

        return ['corps' => $corps, 'code_http' => $codeHttp];
    }

    /**
     * Authentification OAuth2 (mode API uniquement).
     *
     * @throws RuntimeException En cas d'echec
     */
    public function authentifier(): void
    {
        if ($this->mode !== 'api') {
            return;
        }

        $this->respecterRateLimit(1.0);

        $resultat = $this->requeteCurl(
            self::URL_AUTHENTIFICATION,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}"),
            ],
            'POST',
            http_build_query(['grant_type' => 'client_credentials']),
        );

        if ($resultat['corps'] === false) {
            $this->mode = 'json_public';
            return;
        }

        $donnees = json_decode($resultat['corps'], true, 512, JSON_THROW_ON_ERROR);

        if (isset($donnees['error']) || !isset($donnees['access_token'])) {
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
    /**
     * Definit le callback de journalisation pour le suivi diagnostic.
     */
    public function definirRappelJournal(?callable $rappel): void
    {
        $this->rappelJournal = $rappel;
    }

    public function rechercherPublications(
        string $marque,
        string $periode = 'month',
        int $limite = 500,
        array $subreddits = [],
        array $motsCles = [],
        ?callable $rappelProgression = null,
    ): array {
        $this->rappelProgression = $rappelProgression;

        if ($this->mode === 'api') {
            return $this->rechercherViaApi($marque, $periode, $limite, $subreddits, $motsCles);
        }

        // Mode sans credentials : strategie multi-sources pour maximiser les resultats
        return $this->rechercherMultiSources($marque, $periode, $limite, $subreddits, $motsCles);
    }

    /**
     * Strategie de collecte multi-sources (SerpAPI + JSON public + DuckDuckGo).
     *
     * 0. SerpAPI Google Search site:reddit.com (si cle configuree)
     * 1. Reddit JSON public avec la periode demandee (limit=100)
     * 2. Si peu de resultats, elargir a t=all
     * 3. Essayer aussi avec des variantes de requete (sans guillemets)
     * 4. Completer avec DuckDuckGo pour les resultats indexes par Google
     * 5. Fusionner et dedoublonner par reddit_id
     */
    private function rechercherMultiSources(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        $publicationsMap = []; // Indexees par reddit_id pour deduplication
        $redditBloque = false; // Si Reddit renvoie 403/HTML, on skip les passes suivantes

        // --- Passe 0 : SerpAPI Google Search (si cle disponible) ---
        if ($this->cleApiSerp !== null) {
            $this->journaliserCollecte("Passe 0 : SerpAPI Google Search site:reddit.com");
            $pubsSerp = $this->rechercherViaSerpApi($marque, $periode, $limite, $subreddits, $motsCles);
            foreach ($pubsSerp as $pub) {
                $rid = $pub['reddit_id'] ?? '';
                if ($rid !== '') {
                    $publicationsMap[$rid] = $pub;
                }
            }
            $this->journaliserCollecte("Passe 0 : " . count($pubsSerp) . " resultats SerpAPI", count($pubsSerp) > 0 ? 'success' : 'warning');
            $this->notifierProgression(count($publicationsMap));
        }

        // --- Passe 1 : Reddit JSON public, periode demandee ---
        $this->journaliserCollecte("Passe 1 : Reddit JSON public, periode={$periode}");
        $pubs1 = $this->rechercherViaJsonPublic($marque, $periode, $limite, $subreddits, $motsCles);
        foreach ($pubs1 as $pub) {
            $rid = $pub['reddit_id'] ?? '';
            if ($rid !== '') {
                $publicationsMap[$rid] = $pub;
            }
        }
        $this->journaliserCollecte("Passe 1 : " . count($pubs1) . " resultats");
        $this->notifierProgression(count($publicationsMap));

        // Detecter si Reddit bloque les requetes (0 resultats = probablement 403/bloque)
        if (empty($pubs1)) {
            $redditBloque = true;
            $this->journaliserCollecte("Reddit semble bloquer les requetes depuis ce serveur, bascule vers DuckDuckGo", 'warning');
        }

        // --- Passe 2 : si peu de resultats et periode restrictive, elargir a t=all ---
        if (!$redditBloque && count($publicationsMap) < $limite && $periode !== 'all') {
            $this->journaliserCollecte("Passe 2 : Reddit JSON public, periode=all (elargissement)");
            $pubs2 = $this->rechercherViaJsonPublic($marque, 'all', $limite, $subreddits, $motsCles);
            $nouveaux = 0;
            foreach ($pubs2 as $pub) {
                $rid = $pub['reddit_id'] ?? '';
                if ($rid !== '' && !isset($publicationsMap[$rid])) {
                    $publicationsMap[$rid] = $pub;
                    $nouveaux++;
                }
            }
            $this->journaliserCollecte("Passe 2 : {$nouveaux} nouveaux resultats");
            $this->notifierProgression(count($publicationsMap));
        }

        // --- Passe 3 : variantes de requete (tri par new, top, comments) ---
        if (!$redditBloque && count($publicationsMap) < $limite) {
            foreach (['new', 'top', 'comments'] as $tri) {
                if (count($publicationsMap) >= $limite) {
                    break;
                }
                $this->journaliserCollecte("Passe 3 : Reddit JSON public, sort={$tri}, t=all");
                $pubs3 = $this->rechercherViaJsonPublicAvecTri($marque, 'all', $limite, $subreddits, $motsCles, $tri);
                $nouveaux = 0;
                foreach ($pubs3 as $pub) {
                    $rid = $pub['reddit_id'] ?? '';
                    if ($rid !== '' && !isset($publicationsMap[$rid])) {
                        $publicationsMap[$rid] = $pub;
                        $nouveaux++;
                    }
                }
                $this->journaliserCollecte("Passe 3 ({$tri}) : {$nouveaux} nouveaux resultats");
                $this->notifierProgression(count($publicationsMap));
            }
        }

        // --- DuckDuckGo : complement ou source principale si Reddit bloque ---
        if (count($publicationsMap) < $limite) {
            $this->journaliserCollecte("DuckDuckGo site:reddit.com" . ($redditBloque ? " (source principale)" : " (complement)"));
            $pubsDdg = $this->rechercherViaDdg($marque, $periode, $limite - count($publicationsMap), $subreddits, $motsCles);
            $nouveaux = 0;
            foreach ($pubsDdg as $pub) {
                $rid = $pub['reddit_id'] ?? '';
                if ($rid !== '' && !isset($publicationsMap[$rid])) {
                    $publicationsMap[$rid] = $pub;
                    $nouveaux++;
                }
            }
            $this->journaliserCollecte("DuckDuckGo : {$nouveaux} nouveaux resultats");
            $this->notifierProgression(count($publicationsMap));
        }

        $this->journaliserCollecte("Total apres fusion : " . count($publicationsMap) . " publications uniques", 'success');

        return array_values($publicationsMap);
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

        $resultat = $this->requeteCurl($url, [
            "Authorization: Bearer {$this->accessToken}",
            'Accept: application/json',
        ]);

        if ($resultat['corps'] === false) {
            return [];
        }

        return json_decode($resultat['corps'], true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function commentairesViaApi(string $id, int $limite): array
    {
        $this->respecterRateLimit(1.0);

        $url = self::URL_API . "/comments/{$id}.json?" . http_build_query([
            'limit' => $limite,
            'sort'  => 'top',
            'depth' => 10,
        ]);

        $resultat = $this->requeteCurl($url, [
            "Authorization: Bearer {$this->accessToken}",
            'Accept: application/json',
        ]);

        if ($resultat['corps'] === false) {
            return [];
        }

        $donnees = json_decode($resultat['corps'], true, 512, JSON_THROW_ON_ERROR);
        $commentaires = [];
        $listeCommentaires = $donnees[1]['data']['children'] ?? [];
        $this->extraireCommentairesRecursif($listeCommentaires, $commentaires, $limite);

        return $commentaires;
    }

    /* ========================================================================
       MODE 2 : Reddit JSON public (sans auth)
       ======================================================================== */

    /**
     * Recherche via Reddit JSON public avec pagination.
     */
    private function rechercherViaJsonPublic(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
        string $tri = 'relevance',
    ): array {
        return $this->rechercherViaJsonPublicAvecTri($marque, $periode, $limite, $subreddits, $motsCles, $tri);
    }

    /**
     * Recherche JSON public avec tri configurable et pagination.
     */
    private function rechercherViaJsonPublicAvecTri(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
        string $tri = 'relevance',
    ): array {
        $publications = [];
        $apres = null;
        $requete = $this->construireRequete($marque, $motsCles);
        $parPage = min($limite, 100); // Reddit supporte limit=100 en JSON public

        while (count($publications) < $limite) {
            $params = [
                'q'        => $requete,
                'sort'     => $tri,
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
                break;
            }

            $enfants = $reponse['data']['children'] ?? [];
            if (empty($enfants)) {
                break;
            }

            foreach ($enfants as $enfant) {
                $publications[] = $this->formaterPublication($enfant['data'] ?? []);
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
        // Initialiser la session navigateur (cookies) avant la premiere requete
        $this->initialiserSessionNavigateur();

        // Rate limit pour le mode public (~6 req/min)
        $this->respecterRateLimit(10.0);

        $url = $this->urlRedditPublic . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $this->journaliserCollecte("Requete JSON public : {$url}");

        $resultat = $this->requeteCurl($url, $this->headersNavigateurJson());
        $codeHttp = $resultat['code_http'];
        $reponse = $resultat['corps'];

        if ($reponse === false) {
            $this->journaliserCollecte("Requete echouee (pas de reponse, HTTP {$codeHttp})", 'warning');
            return null;
        }

        // Verifier si on a ete bloque (429, 403, page HTML au lieu de JSON)
        if (str_starts_with(trim($reponse), '<') || str_starts_with(trim($reponse), '<!')) {
            $this->journaliserCollecte("Reddit a renvoye du HTML (HTTP {$codeHttp}) — probablement bloque", 'warning');

            // Si 403, invalider les cookies pour forcer une nouvelle session
            if ($codeHttp === 403) {
                @unlink($this->cheminCookies);
                $this->sessionInitialisee = false;
                $this->journaliserCollecte("Cookie jar supprime, nouvelle session au prochain essai");
            }

            return null;
        }

        $donnees = json_decode($reponse, true, 512);
        if ($donnees === null || isset($donnees['error'])) {
            $erreur = $donnees['error'] ?? 'JSON invalide';
            $this->journaliserCollecte("Reponse invalide (HTTP {$codeHttp}) : {$erreur}", 'warning');
            return null;
        }

        $nbResultats = count($donnees['data']['children'] ?? []);
        $this->journaliserCollecte("HTTP {$codeHttp} — {$nbResultats} resultats");

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
       MODE 3 : SerpAPI Google Search (contourne le blocage IP datacenter)
       ======================================================================== */

    /**
     * Recherche via SerpAPI Google Search avec site:reddit.com.
     *
     * Max 3 appels API par analyse (num=100 par appel = 300 URLs max).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rechercherViaSerpApi(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        $publications = [];
        $debut = 0;
        $parPage = 100;
        $maxAppels = 3;
        $appels = 0;

        $requete = $this->construireRequeteSerpApi($marque, $subreddits, $motsCles);
        $this->journaliserCollecte("Requete SerpAPI : {$requete}");

        // Filtre temporel Google (tbs parameter)
        $filtreTemps = match ($periode) {
            'hour'  => 'qdr:h',
            'day'   => 'qdr:d',
            'week'  => 'qdr:w',
            'month' => 'qdr:m',
            'year'  => 'qdr:y',
            default => null,
        };

        while (count($publications) < $limite && $appels < $maxAppels) {
            $params = [
                'engine'  => 'google',
                'q'       => $requete,
                'api_key' => $this->cleApiSerp,
                'num'     => $parPage,
                'start'   => $debut,
                'hl'      => 'en',
                'gl'      => 'us',
            ];
            if ($filtreTemps !== null) {
                $params['tbs'] = $filtreTemps;
            }

            $reponse = $this->requeteSerpApi($params);
            $appels++;

            if ($reponse === null) {
                break;
            }

            $resultats = $reponse['organic_results'] ?? [];
            if (empty($resultats)) {
                $this->journaliserCollecte("SerpAPI : 0 resultats organiques (page " . ($appels) . ")");
                break;
            }

            $nouveaux = 0;
            foreach ($resultats as $resultat) {
                $pub = $this->convertirResultatSerpApi($resultat);
                if ($pub !== null) {
                    $publications[] = $pub;
                    $nouveaux++;
                }
                if (count($publications) >= $limite) {
                    break;
                }
            }
            $this->journaliserCollecte("SerpAPI page {$appels} : {$nouveaux} posts Reddit extraits");

            $debut += $parPage;
            if (empty($reponse['serpapi_pagination']['next'])) {
                break;
            }
        }

        // Tentative d'enrichissement via Reddit JSON (score, commentaires, auteur)
        if (!empty($publications)) {
            $publications = $this->enrichirViaSerpApi($publications);
        }

        return $publications;
    }

    /**
     * Execute une requete vers l'API SerpAPI.
     *
     * @param array<string, mixed> $params Parametres de recherche
     * @return array<string, mixed>|null Reponse JSON decodee
     */
    private function requeteSerpApi(array $params): ?array
    {
        $url = self::URL_SERPAPI . '?' . http_build_query($params);

        $resultat = $this->requeteCurl($url, ['Accept: application/json']);

        if ($resultat['corps'] === false || $resultat['code_http'] !== 200) {
            $this->journaliserCollecte("SerpAPI erreur HTTP {$resultat['code_http']}", 'warning');
            return null;
        }

        $donnees = json_decode($resultat['corps'], true, 512);
        if ($donnees === null) {
            $this->journaliserCollecte("SerpAPI : reponse JSON invalide", 'warning');
            return null;
        }

        if (isset($donnees['error'])) {
            $this->journaliserCollecte("SerpAPI erreur : " . ($donnees['error'] ?? 'inconnue'), 'warning');
            return null;
        }

        return $donnees;
    }

    /**
     * Construit la requete Google pour SerpAPI.
     */
    private function construireRequeteSerpApi(string $marque, array $subreddits, array $motsCles): string
    {
        if (!empty($subreddits)) {
            $subs = array_map(fn(string $s): string => 'site:reddit.com/r/' . trim($s), $subreddits);
            $requete = '"' . $marque . '" (' . implode(' OR ', $subs) . ')';
        } else {
            $requete = 'site:reddit.com "' . $marque . '"';
        }

        if (!empty($motsCles)) {
            $termes = array_map(fn(string $m): string => '"' . trim($m) . '"', $motsCles);
            $requete .= ' (' . implode(' OR ', $termes) . ')';
        }

        return $requete;
    }

    /**
     * Convertit un resultat organique SerpAPI en publication Reddit.
     *
     * @return array<string, mixed>|null Publication formatee ou null si l'URL n'est pas un post Reddit
     */
    private function convertirResultatSerpApi(array $resultat): ?array
    {
        $url = $resultat['link'] ?? '';

        // Seuls les URLs de posts Reddit /r/xxx/comments/xxx sont valides
        if (!preg_match('#reddit\.com/r/([^/]+)/comments/([a-z0-9]+)#i', $url, $matches)) {
            return null;
        }

        $subreddit = $matches[1];
        $postId = $matches[2];

        // Extraction de la date (SerpAPI fournit parfois un champ date)
        $datePublication = null;
        $dateSource = $resultat['date'] ?? $resultat['snippet_highlighted_words'][0] ?? null;
        if ($dateSource !== null) {
            $timestamp = strtotime($dateSource);
            if ($timestamp !== false && $timestamp > 0) {
                $datePublication = date('Y-m-d H:i:s', $timestamp);
            }
        }

        return [
            'reddit_id'        => 't3_' . $postId,
            'titre'            => $resultat['title'] ?? '',
            'contenu'          => $resultat['snippet'] ?? '',
            'url'              => 'https://www.reddit.com/r/' . $subreddit . '/comments/' . $postId . '/',
            'subreddit'        => $subreddit,
            'auteur'           => '[inconnu]',
            'date_publication' => $datePublication,
            'score'            => 0,
            'ratio_upvote'     => 0.5,
            'nb_commentaires'  => 0,
            'awards'           => 0,
            'type'             => 'post',
        ];
    }

    /**
     * Tente d'enrichir les publications SerpAPI avec les metadonnees Reddit.
     *
     * Strategie fail-fast : si le premier post renvoie 403, on arrete
     * l'enrichissement pour ne pas perdre de temps.
     *
     * @param array<int, array<string, mixed>> $publications
     * @return array<int, array<string, mixed>>
     */
    private function enrichirViaSerpApi(array $publications): array
    {
        $this->journaliserCollecte("Tentative d'enrichissement Reddit pour " . count($publications) . " posts SerpAPI");
        $enrichis = 0;
        $echecConsecutifs = 0;

        foreach ($publications as $index => &$pub) {
            $pubEnrichie = $this->extrairePostDepuisUrl($pub['url']);

            if ($pubEnrichie !== null) {
                // Garder le snippet SerpAPI si le selftext Reddit est vide
                $contenuOriginal = $pub['contenu'];
                $pub = $pubEnrichie;
                if (empty($pub['contenu']) && !empty($contenuOriginal)) {
                    $pub['contenu'] = $contenuOriginal;
                }
                $enrichis++;
                $echecConsecutifs = 0;
            } else {
                $echecConsecutifs++;
                // Fail-fast : 2 echecs consecutifs = Reddit bloque, on arrete
                if ($echecConsecutifs >= 2) {
                    $this->journaliserCollecte(
                        "Reddit bloque l'enrichissement, utilisation des donnees SerpAPI seules pour les " . (count($publications) - $index - 1) . " posts restants",
                        'warning'
                    );
                    break;
                }
            }
        }
        unset($pub);

        $this->journaliserCollecte(
            "{$enrichis}/" . count($publications) . " posts enrichis via Reddit JSON",
            $enrichis > 0 ? 'success' : 'warning'
        );

        return $publications;
    }

    /* ========================================================================
       MODE 4 : DuckDuckGo site:reddit.com (fallback)
       ======================================================================== */

    private function rechercherViaDdg(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        $publications = [];
        $requete = $this->construireRequeteDdg($marque, $subreddits, $motsCles);
        $this->journaliserCollecte("Recherche DuckDuckGo : {$requete}");

        // Recuperer toutes les URLs avec pagination DDG (max 3 pages)
        $urlsDdg = $this->scraperDuckDuckGoAvecPagination($requete, 3);
        $this->journaliserCollecte(count($urlsDdg) . " URLs Reddit trouvees via DuckDuckGo");

        foreach ($urlsDdg as $urlReddit) {
            if (count($publications) >= $limite) {
                break;
            }

            $pub = $this->extrairePostDepuisUrl($urlReddit);
            if ($pub !== null) {
                $publications[] = $pub;
                $this->notifierProgression(count($publications));
                $this->journaliserCollecte("  Collecte OK : " . mb_substr($pub['titre'] ?? '', 0, 60));
            } else {
                $this->journaliserCollecte("  Echec extraction : {$urlReddit}", 'warning');
            }
        }

        $this->journaliserCollecte("DuckDuckGo : " . count($publications) . " publications collectees au total", 'success');

        return $publications;
    }

    /**
     * Scrape DuckDuckGo HTML avec pagination automatique.
     *
     * Page 1 = GET, pages suivantes = POST avec les champs caches du formulaire "Next".
     *
     * @param string $requete    Requete de recherche
     * @param int    $maxPages   Nombre max de pages a scraper
     * @return array<string> URLs Reddit uniques trouvees
     */
    private function scraperDuckDuckGoAvecPagination(string $requete, int $maxPages = 3): array
    {
        $toutesUrls = [];

        // --- Page 1 : GET ---
        $this->respecterRateLimit(3.0);
        $url = self::URL_DDG . '?' . http_build_query(['q' => $requete]);

        $html = $this->requeteHttp($url, 'GET');
        if ($html === null) {
            $this->journaliserCollecte("DuckDuckGo : pas de reponse page 1", 'warning');
            return [];
        }

        $urls = $this->extraireUrlsRedditDdg($html);
        $toutesUrls = array_merge($toutesUrls, $urls);
        $this->journaliserCollecte("DuckDuckGo page 1 : " . count($urls) . " URLs Reddit");

        // --- Pages suivantes : POST avec les champs du formulaire "Next" ---
        for ($page = 2; $page <= $maxPages; $page++) {
            $formData = $this->extraireFormulaireSuivantDdg($html);
            if ($formData === null) {
                break; // Plus de pagination
            }

            $this->respecterRateLimit(4.0); // Un peu plus lent pour eviter le rate limit
            $html = $this->requeteHttp(self::URL_DDG, 'POST', $formData);
            if ($html === null) {
                $this->journaliserCollecte("DuckDuckGo : page {$page} echouee (rate limit probable)", 'warning');
                break;
            }

            $urls = $this->extraireUrlsRedditDdg($html);
            if (empty($urls)) {
                break;
            }
            $toutesUrls = array_merge($toutesUrls, $urls);
            $this->journaliserCollecte("DuckDuckGo page {$page} : " . count($urls) . " URLs Reddit");
        }

        return array_values(array_unique($toutesUrls));
    }

    /**
     * Extrait les URLs Reddit des resultats DuckDuckGo HTML.
     *
     * @return array<string>
     */
    private function extraireUrlsRedditDdg(string $html): array
    {
        $urls = [];

        // Pattern 1 : liens avec redirect uddg=
        if (preg_match_all('#href="[^"]*uddg=([^&"]+)[^"]*"#i', $html, $matches)) {
            foreach ($matches[1] as $urlEncodee) {
                $urlDecodee = urldecode($urlEncodee);
                if (str_contains($urlDecodee, 'reddit.com/r/') && str_contains($urlDecodee, '/comments/')) {
                    $urls[] = $urlDecodee;
                }
            }
        }

        // Pattern 2 : liens directs vers Reddit
        if (empty($urls) && preg_match_all('#href="(https?://(?:www\.)?reddit\.com/r/[^"]+/comments/[^"]+)"#i', $html, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        return array_values(array_unique($urls));
    }

    /**
     * Extrait les champs caches du formulaire "Next" de DDG pour la pagination.
     *
     * @return array<string, string>|null Donnees POST ou null si pas de page suivante
     */
    private function extraireFormulaireSuivantDdg(string $html): ?array
    {
        // Trouver le formulaire contenant le bouton "Next"
        if (!preg_match('#<form[^>]*class="[^"]*nav[^"]*"[^>]*>(.*?)</form>#si', $html, $formMatch)) {
            // Essayer un pattern plus large : formulaire contenant input value="Next"
            if (!preg_match('#<form[^>]*>((?:(?!</form>).)*value="Next"(?:(?!</form>).)*)</form>#si', $html, $formMatch)) {
                return null;
            }
        }

        $formHtml = $formMatch[1];
        $donnees = [];

        // Extraire tous les inputs caches
        if (preg_match_all('#<input[^>]*name="([^"]*)"[^>]*value="([^"]*)"#i', $formHtml, $inputs)) {
            for ($i = 0; $i < count($inputs[1]); $i++) {
                $nom = $inputs[1][$i];
                $valeur = $inputs[2][$i];
                if ($nom !== '' && $nom !== 'b') { // 'b' est le bouton Next
                    $donnees[$nom] = html_entity_decode($valeur, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        // Verifier que les donnees de pagination sont valides
        if (!isset($donnees['q']) || !isset($donnees['s'])) {
            return null;
        }

        // Si s=0 et dc=1, il n'y a plus de resultats
        if (($donnees['s'] ?? '0') === '0' && ($donnees['dc'] ?? '1') === '1') {
            return null;
        }

        return $donnees;
    }

    /**
     * Effectue une requete HTTP (GET ou POST).
     *
     * @return string|null Corps de la reponse ou null en cas d'echec
     */
    private function requeteHttp(string $url, string $methode = 'GET', ?array $postData = null): ?string
    {
        $headers = $this->headersNavigateurHtml();

        $corpsPost = null;
        if ($methode === 'POST' && $postData !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $corpsPost = http_build_query($postData);
        }

        $resultat = $this->requeteCurl($url, $headers, $methode, $corpsPost);

        if ($resultat['corps'] === false) {
            return null;
        }

        // Verifier si DDG renvoie un challenge bot (HTTP 202)
        if ($resultat['code_http'] === 202) {
            return null;
        }

        return $resultat['corps'];
    }

    /**
     * Extrait les donnees d'un post Reddit a partir de son URL via l'endpoint .json public.
     *
     * @return array<string, mixed>|null Publication formatee ou null si erreur
     */
    private function extrairePostDepuisUrl(string $url): ?array
    {
        // Initialiser la session navigateur si necessaire
        $this->initialiserSessionNavigateur();

        // Reecrire l'URL vers le domaine actif (old.reddit.com si disponible)
        $urlReecrite = preg_replace('#^https?://(?:www\.|old\.)?reddit\.com#', $this->urlRedditPublic, $url);
        $urlNettoyee = rtrim(preg_replace('#\?.*$#', '', $urlReecrite), '/');
        $urlJson = $urlNettoyee . '.json?raw_json=1&limit=0';

        // Rate limit modere pour les requetes individuelles de posts
        $this->respecterRateLimit(5.0);

        $resultat = $this->requeteCurl($urlJson, $this->headersNavigateurJson());

        if ($resultat['corps'] === false || str_starts_with(trim($resultat['corps']), '<')) {
            return null;
        }

        $donnees = json_decode($resultat['corps'], true, 512);
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
     * Construit la requete DuckDuckGo avec site:reddit.com
     */
    private function construireRequeteDdg(string $marque, array $subreddits, array $motsCles): string
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

    /**
     * Journalise un message diagnostic via le callback si defini.
     */
    private function journaliserCollecte(string $message, string $niveau = 'info'): void
    {
        if ($this->rappelJournal !== null) {
            ($this->rappelJournal)($message, $niveau);
        }
    }
}
