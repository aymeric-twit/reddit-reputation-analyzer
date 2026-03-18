<?php

declare(strict_types=1);

/**
 * Client de collecte Reddit multi-sources.
 *
 * Sources (par ordre de priorite) :
 * 1. PullPush.io — Archive Reddit gratuite, donnees completes (score, auteur, commentaires)
 * 2. SerpAPI — Google Search site:reddit.com, donnees partielles (snippets uniquement)
 * 3. Mode navigateur — L'utilisateur colle le JSON Reddit (non gere ici)
 */
class CollecteurReddit
{
    private ?string $cleApiSerp;
    private float $derniereRequete = 0.0;

    private const string URL_PULLPUSH_SUBMISSIONS = 'https://api.pullpush.io/reddit/search/submission/';
    private const string URL_PULLPUSH_COMMENTS = 'https://api.pullpush.io/reddit/search/comment/';
    private const string URL_SERPAPI = 'https://serpapi.com/search.json';
    private const int PULLPUSH_MAX_PAR_PAGE = 100;

    /** @var callable|null Callback de progression */
    private $rappelProgression = null;

    /** @var callable|null Callback de journalisation */
    private $rappelJournal = null;

    /** @var string Source utilisee pour la derniere collecte */
    private string $sourceUtilisee = 'pullpush';

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
     * Retourne la source utilisee pour la derniere collecte.
     */
    public function obtenirSourceUtilisee(): string
    {
        return $this->sourceUtilisee;
    }

    /**
     * Recherche les publications mentionnant une marque.
     *
     * Strategie : PullPush.io d'abord (gratuit, donnees completes),
     * puis SerpAPI en fallback si PullPush echoue ou retourne peu de resultats.
     *
     * @param string        $marque     Nom de la marque
     * @param string        $periode    Periode (hour/day/week/month/year/all)
     * @param int           $limite     Nombre max de publications
     * @param array<string> $subreddits Subreddits cibles
     * @param array<string> $motsCles   Mots-cles supplementaires
     * @param callable|null $rappelProgression Callback(int $nbCollectees)
     * @param string        $domaineGoogle Domaine Google pour SerpAPI fallback
     * @return array<int, array<string, mixed>>
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

        // 1. Essayer PullPush.io (gratuit, donnees completes)
        $this->journaliserCollecte('Source primaire : PullPush.io (archive Reddit)');
        $publications = $this->rechercherViaPullPush($marque, $periode, $limite, $subreddits, $motsCles);

        if (count($publications) >= 5) {
            $this->sourceUtilisee = 'pullpush';
            return $publications;
        }

        // 2. Fallback SerpAPI si PullPush retourne trop peu
        if ($this->cleApiSerp !== null) {
            $this->journaliserCollecte(
                'PullPush : ' . count($publications) . ' resultats insuffisants, fallback SerpAPI',
                'warning'
            );
            $pubsSerpApi = $this->rechercherViaSerpApi($marque, $periode, $limite, $subreddits, $motsCles, $domaineGoogle);

            if (count($pubsSerpApi) > count($publications)) {
                $this->sourceUtilisee = 'serpapi';
                return $pubsSerpApi;
            }
        }

        // 3. Retourner ce qu'on a (PullPush meme si peu)
        if (empty($publications) && $this->cleApiSerp === null) {
            $this->journaliserCollecte(
                'Aucun resultat. PullPush vide et SERPAPI_KEY non configuree. Utilisez le mode navigateur.',
                'error'
            );
        }

        $this->sourceUtilisee = count($publications) > 0 ? 'pullpush' : 'aucune';
        return $publications;
    }

    /**
     * Recupere les commentaires d'une publication via PullPush.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recupererCommentaires(string $redditId, int $limite = 200): array
    {
        // Extraire l'ID sans le prefixe t3_
        $postId = str_starts_with($redditId, 't3_') ? substr($redditId, 3) : $redditId;

        $params = [
            'link_id' => 't3_' . $postId,
            'size'    => min($limite, 100),
            'sort'    => 'score',
        ];

        $reponse = $this->requeteHttp(self::URL_PULLPUSH_COMMENTS . '?' . http_build_query($params));
        if ($reponse === null) {
            return [];
        }

        $donnees = json_decode($reponse, true, 512);
        $commentaires = $donnees['data'] ?? [];
        $resultats = [];

        foreach ($commentaires as $c) {
            $auteur = $c['author'] ?? '[deleted]';
            if ($auteur === '[deleted]' || $auteur === 'AutoModerator') {
                continue;
            }

            $resultats[] = [
                'reddit_id'        => $c['name'] ?? ('t1_' . ($c['id'] ?? '')),
                'titre'            => '',
                'contenu'          => $c['body'] ?? '',
                'url'              => isset($c['permalink']) ? 'https://www.reddit.com' . $c['permalink'] : '',
                'subreddit'        => $c['subreddit'] ?? '',
                'auteur'           => $auteur,
                'date_publication' => isset($c['created_utc']) ? date('Y-m-d H:i:s', (int) $c['created_utc']) : null,
                'score'            => (int) ($c['score'] ?? 0),
                'ratio_upvote'     => 0.5,
                'nb_commentaires'  => 0,
                'awards'           => (int) ($c['total_awards_received'] ?? 0),
                'type'             => 'commentaire',
            ];
        }

        return $resultats;
    }

    /* ========================================================================
       PULLPUSH.IO : Archive Reddit gratuite
       ======================================================================== */

    /**
     * Recherche via PullPush.io avec pagination et multi-requetes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rechercherViaPullPush(
        string $marque,
        string $periode,
        int $limite,
        array $subreddits,
        array $motsCles,
    ): array {
        $publications = [];
        /** @var array<string, true> Index des reddit_id deja collectes */
        $idsVus = [];

        // Calculer le timestamp de debut selon la periode
        $after = match ($periode) {
            'hour'  => time() - 3600,
            'day'   => time() - 86400,
            'week'  => time() - 7 * 86400,
            'month' => time() - 30 * 86400,
            'year'  => time() - 365 * 86400,
            default => null,
        };

        // --- Construire les requetes ---
        $requetes = [];

        // 1. Recherche exacte (guillemets)
        $requetes[] = ['q' => '"' . $marque . '"', 'label' => 'exacte'];

        // 2. Par subreddit cible
        foreach (array_slice($subreddits, 0, 5) as $sub) {
            $requetes[] = [
                'q'         => '"' . $marque . '"',
                'subreddit' => trim($sub),
                'label'     => 'r/' . trim($sub),
            ];
        }

        // 3. Avec mots-cles
        foreach (array_slice($motsCles, 0, 3) as $mc) {
            $requetes[] = [
                'q'     => '"' . $marque . '" ' . trim($mc),
                'label' => 'mot-cle:' . trim($mc),
            ];
        }

        // 4. Sans guillemets (plus large)
        $requetes[] = ['q' => $marque, 'label' => 'large'];

        // --- Lancer les requetes ---
        $maxAppelsTotal = 15;
        $appelsTotal = 0;

        foreach ($requetes as $req) {
            if (count($publications) >= $limite || $appelsTotal >= $maxAppelsTotal) {
                break;
            }

            $this->journaliserCollecte("PullPush [{$req['label']}] : {$req['q']}");

            // Pagination via le champ before (timestamp du dernier resultat)
            $before = null;
            $maxPages = 5;

            for ($page = 0; $page < $maxPages; $page++) {
                if (count($publications) >= $limite || $appelsTotal >= $maxAppelsTotal) {
                    break;
                }

                $this->respecterRateLimit(1.0);

                $params = [
                    'q'    => $req['q'],
                    'size' => self::PULLPUSH_MAX_PAR_PAGE,
                    'sort' => 'score',
                    'sort_type' => 'desc',
                ];

                if (isset($req['subreddit'])) {
                    $params['subreddit'] = $req['subreddit'];
                }
                if ($after !== null) {
                    $params['after'] = $after;
                }
                if ($before !== null) {
                    $params['before'] = $before;
                }

                $reponse = $this->requeteHttp(self::URL_PULLPUSH_SUBMISSIONS . '?' . http_build_query($params));
                $appelsTotal++;

                if ($reponse === null) {
                    $this->journaliserCollecte("PullPush [{$req['label']}] : erreur HTTP", 'warning');
                    break;
                }

                $donnees = json_decode($reponse, true, 512);
                $resultats = $donnees['data'] ?? [];

                if (empty($resultats)) {
                    break;
                }

                $nouveaux = 0;
                foreach ($resultats as $resultat) {
                    $pub = $this->convertirResultatPullPush($resultat);
                    if ($pub === null) {
                        continue;
                    }

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
                    "PullPush [{$req['label']}] page " . ($page + 1) . " : {$nouveaux} nouveaux"
                    . " (total " . count($publications) . ")"
                );

                // Pagination : utiliser le timestamp du dernier resultat
                $dernier = end($resultats);
                if ($dernier && isset($dernier['created_utc'])) {
                    $before = (int) $dernier['created_utc'];
                } else {
                    break;
                }

                // Si cette page a retourne moins que le max, c'est la derniere
                if (count($resultats) < self::PULLPUSH_MAX_PAR_PAGE) {
                    break;
                }
            }
        }

        $this->journaliserCollecte(
            count($publications) . " publications collectees via PullPush ({$appelsTotal} appels)",
            count($publications) > 0 ? 'success' : 'warning'
        );

        return $publications;
    }

    /**
     * Convertit un resultat PullPush en publication Reddit.
     *
     * @return array<string, mixed>|null
     */
    private function convertirResultatPullPush(array $resultat): ?array
    {
        $id = $resultat['id'] ?? '';
        if ($id === '') {
            return null;
        }

        $auteur = $resultat['author'] ?? '[deleted]';
        if ($auteur === '[deleted]') {
            return null;
        }

        return [
            'reddit_id'        => $resultat['name'] ?? ('t3_' . $id),
            'titre'            => $resultat['title'] ?? '',
            'contenu'          => $resultat['selftext'] ?? '',
            'url'              => isset($resultat['permalink'])
                ? 'https://www.reddit.com' . $resultat['permalink']
                : 'https://www.reddit.com/r/' . ($resultat['subreddit'] ?? '') . '/comments/' . $id . '/',
            'subreddit'        => $resultat['subreddit'] ?? '',
            'auteur'           => $auteur,
            'date_publication' => isset($resultat['created_utc'])
                ? date('Y-m-d H:i:s', (int) $resultat['created_utc'])
                : null,
            'score'            => (int) ($resultat['score'] ?? 0),
            'ratio_upvote'     => (float) ($resultat['upvote_ratio'] ?? 0.5),
            'nb_commentaires'  => (int) ($resultat['num_comments'] ?? 0),
            'awards'           => (int) ($resultat['total_awards_received'] ?? 0),
            'type'             => 'post',
        ];
    }

    /* ========================================================================
       SERPAPI : Google Search site:reddit.com (fallback)
       ======================================================================== */

    /**
     * Recherche via SerpAPI Google Search avec site:reddit.com.
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
        $idsVus = [];

        [$hl, $gl] = match ($domaineGoogle) {
            'google.fr'    => ['fr', 'fr'],
            'google.de'    => ['de', 'de'],
            'google.es'    => ['es', 'es'],
            'google.it'    => ['it', 'it'],
            'google.co.uk' => ['en', 'uk'],
            default        => ['en', 'us'],
        };

        $this->journaliserCollecte("SerpAPI fallback (domaine: {$domaineGoogle})");

        $filtreTemps = match ($periode) {
            'hour'  => 'qdr:h',
            'day'   => 'qdr:d',
            'week'  => 'qdr:w',
            'month' => 'qdr:m',
            'year'  => 'qdr:y',
            default => null,
        };

        $requetes = [];
        $requetes[] = ['q' => $this->construireRequeteSerpApi($marque, [], []), 'label' => 'principale'];
        $requetes[] = [
            'q'     => $this->construireRequeteSerpApi($marque, [], []),
            'label' => 'recentes',
            'extra' => ['tbs' => ($filtreTemps !== null ? $filtreTemps . ',sbd:1' : 'sbd:1')],
        ];

        foreach (array_slice($subreddits, 0, 3) as $sub) {
            $requetes[] = ['q' => $this->construireRequeteSerpApi($marque, [$sub], []), 'label' => "r/{$sub}"];
        }

        $maxAppels = 8;
        $appels = 0;

        foreach ($requetes as $req) {
            if (count($publications) >= $limite || $appels >= $maxAppels) {
                break;
            }

            $this->respecterRateLimit(0.5);

            $params = [
                'engine'        => 'google',
                'q'             => $req['q'],
                'api_key'       => $this->cleApiSerp,
                'num'           => 100,
                'start'         => 0,
                'hl'            => $hl,
                'gl'            => $gl,
                'google_domain' => $domaineGoogle,
            ];

            if ($filtreTemps !== null && !isset($req['extra']['tbs'])) {
                $params['tbs'] = $filtreTemps;
            }
            if (isset($req['extra'])) {
                $params = array_merge($params, $req['extra']);
            }

            $reponse = $this->requeteSerpApi($params);
            $appels++;

            if ($reponse === null) {
                continue;
            }

            foreach (($reponse['organic_results'] ?? []) as $resultat) {
                $pub = $this->convertirResultatSerpApi($resultat);
                if ($pub === null || isset($idsVus[$pub['reddit_id']])) {
                    continue;
                }
                $idsVus[$pub['reddit_id']] = true;
                $publications[] = $pub;
                $this->notifierProgression(count($publications));
                if (count($publications) >= $limite) {
                    break;
                }
            }

            $this->journaliserCollecte("SerpAPI [{$req['label']}] : " . count($publications) . " total");
        }

        $this->journaliserCollecte(
            count($publications) . " publications via SerpAPI ({$appels} appels)",
            count($publications) > 0 ? 'success' : 'warning'
        );

        return $publications;
    }

    private function requeteSerpApi(array $params): ?array
    {
        $url = self::URL_SERPAPI . '?' . http_build_query($params);
        $corps = $this->requeteHttp($url);

        if ($corps === null) {
            return null;
        }

        $donnees = json_decode($corps, true, 512);
        if ($donnees === null || isset($donnees['error'])) {
            $this->journaliserCollecte("SerpAPI erreur : " . ($donnees['error'] ?? 'JSON invalide'), 'warning');
            return null;
        }

        return $donnees;
    }

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

    private function convertirResultatSerpApi(array $resultat): ?array
    {
        $url = $resultat['link'] ?? '';
        if (!preg_match('#reddit\.com/r/([^/]+)/comments/([a-z0-9]+)#i', $url, $matches)) {
            return null;
        }

        $datePublication = null;
        $dateSource = $resultat['date'] ?? null;
        if ($dateSource !== null) {
            $timestamp = strtotime($dateSource);
            if ($timestamp !== false && $timestamp > 0) {
                $datePublication = date('Y-m-d H:i:s', $timestamp);
            }
        }

        return [
            'reddit_id'        => 't3_' . $matches[2],
            'titre'            => $resultat['title'] ?? '',
            'contenu'          => $resultat['snippet'] ?? '',
            'url'              => 'https://www.reddit.com/r/' . $matches[1] . '/comments/' . $matches[2] . '/',
            'subreddit'        => $matches[1],
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
     * Execute une requete HTTP GET et retourne le corps de la reponse.
     */
    private function requeteHttp(string $url): ?string
    {
        // Mode plateforme : client HTTP centralise
        if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\ApiClient')) {
            $client = new \Platform\Http\ApiClient('reddit-reputation');
            $reponse = $client->get($url, [], ['Accept' => 'application/json']);

            $this->derniereRequete = microtime(true);

            if (!$reponse->estSucces()) {
                $this->journaliserCollecte("HTTP erreur {$reponse->statusCode} sur " . parse_url($url, PHP_URL_HOST), 'warning');
                return null;
            }

            return $reponse->body;
        }

        // Mode standalone : curl natif
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'RedditReputation/1.0',
        ]);

        $corps = curl_exec($ch);
        $codeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->derniereRequete = microtime(true);

        if ($corps === false || $codeHttp !== 200) {
            $this->journaliserCollecte("HTTP erreur {$codeHttp} sur " . parse_url($url, PHP_URL_HOST), 'warning');
            return null;
        }

        return $corps;
    }

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

    private function notifierProgression(int $nbCollectees): void
    {
        if ($this->rappelProgression !== null) {
            ($this->rappelProgression)($nbCollectees);
        }
    }

    private function journaliserCollecte(string $message, string $niveau = 'info'): void
    {
        if ($this->rappelJournal !== null) {
            ($this->rappelJournal)($message, $niveau);
        }
    }
}
