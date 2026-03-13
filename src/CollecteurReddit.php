<?php

declare(strict_types=1);

/**
 * Client de collecte Reddit via SerpAPI (Google Search API).
 *
 * Recherche des publications Reddit mentionnant une marque via
 * l'API Google Search de SerpAPI (site:reddit.com).
 *
 * Alternative : le mode "navigateur" permet a l'utilisateur de
 * coller directement le JSON Reddit depuis son navigateur.
 * Dans ce cas, cette classe n'est pas utilisee.
 */
class CollecteurReddit
{
    private ?string $cleApiSerp;
    private float $derniereRequete = 0.0;

    private const string URL_SERPAPI = 'https://serpapi.com/search.json';

    /** @var callable|null Callback de progression */
    private $rappelProgression = null;

    /** @var callable|null Callback de journalisation */
    private $rappelJournal = null;

    public function __construct()
    {
        $cleSerp = $_ENV['SERPAPI_KEY'] ?? getenv('SERPAPI_KEY') ?: null;
        $this->cleApiSerp = !empty($cleSerp) ? $cleSerp : null;
    }

    /**
     * Definit le callback de journalisation pour le suivi diagnostic.
     */
    public function definirRappelJournal(?callable $rappel): void
    {
        $this->rappelJournal = $rappel;
    }

    /**
     * Recherche les publications mentionnant une marque via SerpAPI.
     *
     * @param string        $marque     Nom de la marque
     * @param string        $periode    Periode (hour/day/week/month/year/all)
     * @param int           $limite     Nombre max de publications
     * @param array<string> $subreddits Subreddits cibles
     * @param array<string> $motsCles   Mots-cles supplementaires
     * @param callable|null $rappelProgression Callback(int $nbCollectees)
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException Si SERPAPI_KEY n'est pas configuree
     */
    public function rechercherPublications(
        string $marque,
        string $periode = 'month',
        int $limite = 500,
        array $subreddits = [],
        array $motsCles = [],
        ?callable $rappelProgression = null,
        string $domaineGoogle = 'google.com',
    ): array {
        $this->rappelProgression = $rappelProgression;

        if ($this->cleApiSerp === null) {
            throw new \RuntimeException(
                'SERPAPI_KEY non configuree. Configurez la cle dans .env ou utilisez le mode navigateur.'
            );
        }

        return $this->rechercherViaSerpApi($marque, $periode, $limite, $subreddits, $motsCles, $domaineGoogle);
    }

    /**
     * Recupere les commentaires d'une publication.
     *
     * Non disponible via SerpAPI — retourne un tableau vide.
     * Les commentaires ne sont accessibles que via le mode navigateur.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recupererCommentaires(string $redditId, int $limite = 200): array
    {
        return [];
    }

    /* ========================================================================
       SERPAPI : Google Search site:reddit.com
       ======================================================================== */

    /**
     * Recherche via SerpAPI Google Search avec site:reddit.com.
     *
     * Strategie multi-requetes pour maximiser le volume :
     * 1. Requete principale (marque exacte)
     * 2. Variantes par tri Google (pertinence + date)
     * 3. Si subreddits cibles, requete par subreddit
     * 4. Si mots-cles, requete par mot-cle
     *
     * @return array<int, array<string, mixed>>
     */
    private function rechercherViaSerpApi(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
        string $domaineGoogle = 'google.com',
    ): array {
        $publications = [];
        /** @var array<string, true> Index des reddit_id deja collectes */
        $idsVus = [];

        // Langue et pays selon le domaine Google
        [$hl, $gl] = match ($domaineGoogle) {
            'google.fr'  => ['fr', 'fr'],
            'google.de'  => ['de', 'de'],
            'google.es'  => ['es', 'es'],
            'google.it'  => ['it', 'it'],
            'google.co.uk' => ['en', 'uk'],
            default      => ['en', 'us'],
        };

        $this->journaliserCollecte("Domaine Google : {$domaineGoogle} (hl={$hl}, gl={$gl})");

        // Filtre temporel Google (tbs parameter)
        $filtreTemps = match ($periode) {
            'hour'  => 'qdr:h',
            'day'   => 'qdr:d',
            'week'  => 'qdr:w',
            'month' => 'qdr:m',
            'year'  => 'qdr:y',
            default => null,
        };

        // --- Construire la liste des requetes a lancer ---
        $requetes = [];

        // 1. Requete principale (pertinence)
        $requetes[] = [
            'q'    => $this->construireRequeteSerpApi($marque, [], []),
            'label' => 'principale',
        ];

        // 2. Meme requete triee par date (Google : tbs=sbd:1)
        $requetes[] = [
            'q'     => $this->construireRequeteSerpApi($marque, [], []),
            'label' => 'recentes',
            'extra' => ['tbs' => ($filtreTemps !== null ? $filtreTemps . ',sbd:1' : 'sbd:1')],
        ];

        // 3. Par subreddit cible (si fournis)
        foreach (array_slice($subreddits, 0, 5) as $sub) {
            $requetes[] = [
                'q'     => $this->construireRequeteSerpApi($marque, [$sub], []),
                'label' => "r/{$sub}",
            ];
        }

        // 4. Par mot-cle (si fournis)
        foreach (array_slice($motsCles, 0, 3) as $mc) {
            $requetes[] = [
                'q'     => $this->construireRequeteSerpApi($marque, [], [$mc]),
                'label' => "mot-cle:{$mc}",
            ];
        }

        // 5. Sans guillemets (plus large) si marque multi-mots
        if (str_contains($marque, ' ')) {
            $requetes[] = [
                'q'     => 'site:reddit.com ' . $marque,
                'label' => 'sans guillemets',
            ];
        }

        // --- Lancer les requetes avec pagination ---
        $maxAppelsTotal = 15;
        $maxPagesParRequete = 3;
        $appelsTotal = 0;

        foreach ($requetes as $req) {
            if (count($publications) >= $limite || $appelsTotal >= $maxAppelsTotal) {
                break;
            }

            $this->journaliserCollecte("SerpAPI [{$req['label']}] : {$req['q']}");

            // Pagination : jusqu'a $maxPagesParRequete pages par requete
            for ($page = 0; $page < $maxPagesParRequete; $page++) {
                if (count($publications) >= $limite || $appelsTotal >= $maxAppelsTotal) {
                    break;
                }

                $this->respecterRateLimit(0.5);

                $params = [
                    'engine'        => 'google',
                    'q'             => $req['q'],
                    'api_key'       => $this->cleApiSerp,
                    'num'           => 100,
                    'start'         => $page * 100,
                    'hl'            => $hl,
                    'gl'            => $gl,
                    'google_domain' => $domaineGoogle,
                ];

                // Filtre temporel par defaut
                if ($filtreTemps !== null && !isset($req['extra']['tbs'])) {
                    $params['tbs'] = $filtreTemps;
                }

                // Parametres supplementaires
                if (isset($req['extra'])) {
                    $params = array_merge($params, $req['extra']);
                }

                $reponse = $this->requeteSerpApi($params);
                $appelsTotal++;

                if ($reponse === null) {
                    break;
                }

                $resultats = $reponse['organic_results'] ?? [];
                if (empty($resultats)) {
                    $this->journaliserCollecte("SerpAPI [{$req['label']}] page " . ($page + 1) . " : 0 resultats");
                    break;
                }

                $nouveaux = 0;
                foreach ($resultats as $resultat) {
                    $pub = $this->convertirResultatSerpApi($resultat);
                    if ($pub === null) {
                        continue;
                    }

                    // Deduplication par reddit_id
                    if (isset($idsVus[$pub['reddit_id']])) {
                        continue;
                    }

                    $idsVus[$pub['reddit_id']] = true;
                    $publications[] = $pub;
                    $nouveaux++;
                    $this->notifierProgression(count($publications));

                    if (count($publications) >= $limite) {
                        break;
                    }
                }

                $this->journaliserCollecte(
                    "SerpAPI [{$req['label']}] page " . ($page + 1) . " : {$nouveaux} nouveaux posts"
                    . " (total " . count($publications) . ", {$appelsTotal} appels)"
                );

                // Arreter la pagination si pas de page suivante
                if (empty($reponse['serpapi_pagination']['next'])) {
                    break;
                }
            }
        }

        $this->journaliserCollecte(
            count($publications) . " publications collectees via SerpAPI ({$appelsTotal} appels API)",
            count($publications) > 0 ? 'success' : 'warning'
        );

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

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $corps = curl_exec($ch);
        $codeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->derniereRequete = microtime(true);

        if ($corps === false || $codeHttp !== 200) {
            $this->journaliserCollecte("SerpAPI erreur HTTP {$codeHttp}", 'warning');
            return null;
        }

        $donnees = json_decode($corps, true, 512);
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
        $dateSource = $resultat['date'] ?? null;
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

    /* ========================================================================
       UTILITAIRES
       ======================================================================== */

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
