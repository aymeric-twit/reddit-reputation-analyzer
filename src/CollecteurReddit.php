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

    private const string URL_AUTHENTIFICATION = 'https://www.reddit.com/api/v1/access_token';
    private const string URL_API = 'https://oauth.reddit.com';
    private const string URL_REDDIT_PUBLIC = 'https://www.reddit.com';
    private const string URL_DDG = 'https://html.duckduckgo.com/html/';

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
            return; // Pas necessaire en mode public ou DDG
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
     * Strategie de collecte multi-sources (JSON public + DuckDuckGo).
     *
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
        // Rate limit pour le mode public (~6 req/min)
        $this->respecterRateLimit(10.0);

        $url = self::URL_REDDIT_PUBLIC . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $this->journaliserCollecte("Requete JSON public : {$url}");

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

        // Extraire le code HTTP depuis les headers de reponse
        $codeHttp = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                    $codeHttp = (int) $m[1];
                }
            }
        }

        if ($reponse === false) {
            $this->journaliserCollecte("Requete echouee (pas de reponse, HTTP {$codeHttp})", 'warning');
            return null;
        }

        // Verifier si on a ete bloque (429, 403, page HTML au lieu de JSON)
        if (str_starts_with(trim($reponse), '<') || str_starts_with(trim($reponse), '<!')) {
            $this->journaliserCollecte("Reddit a renvoye du HTML (HTTP {$codeHttp}) — probablement bloque", 'warning');
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
       MODE 3 : DuckDuckGo site:reddit.com (fallback)
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
        $headers = [
            "User-Agent: {$this->userAgent}",
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9,fr;q=0.8',
        ];

        $options = [
            'http' => [
                'method'        => $methode,
                'header'        => implode("\r\n", $headers),
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ];

        if ($methode === 'POST' && $postData !== null) {
            $options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
            $options['http']['content'] = http_build_query($postData);
        }

        $contexte = stream_context_create($options);
        $reponse = @file_get_contents($url, false, $contexte);
        $this->derniereRequete = microtime(true);

        if ($reponse === false) {
            return null;
        }

        // Verifier si DDG renvoie un challenge bot (HTTP 202)
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+202#', $header)) {
                    return null; // Bot challenge, on arrete
                }
            }
        }

        return $reponse;
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

        // Rate limit modere pour les requetes individuelles de posts
        $this->respecterRateLimit(5.0);

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
