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
    ): array {
        $this->rappelProgression = $rappelProgression;

        if ($this->cleApiSerp === null) {
            throw new \RuntimeException(
                'SERPAPI_KEY non configuree. Configurez la cle dans .env ou utilisez le mode navigateur.'
            );
        }

        return $this->rechercherViaSerpApi($marque, $periode, $limite, $subreddits, $motsCles);
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
        $this->journaliserCollecte("SerpAPI Google Search : {$requete}");

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
                $this->journaliserCollecte("SerpAPI : 0 resultats organiques (page {$appels})");
                break;
            }

            $nouveaux = 0;
            foreach ($resultats as $resultat) {
                $pub = $this->convertirResultatSerpApi($resultat);
                if ($pub !== null) {
                    $publications[] = $pub;
                    $nouveaux++;
                    $this->notifierProgression(count($publications));
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

        $this->journaliserCollecte(
            count($publications) . " publications collectees via SerpAPI",
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
