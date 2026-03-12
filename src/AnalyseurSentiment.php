<?php

declare(strict_types=1);

/**
 * Analyseur de sentiment hybride : Google Cloud Natural Language API
 * avec fallback sur un lexique local (approche VADER).
 *
 * Si la cle GOOGLE_NLP_API_KEY est configuree, utilise l'API Google
 * pour une analyse ML multi-langue. Sinon, utilise le lexique bilingue
 * EN/FR avec gestion de la negation et de l'amplification.
 */
class AnalyseurSentiment
{
    /** @var array<string, float> Lexique fusionne (mot => score) */
    private array $lexique = [];

    /** @var array<string> Liste des mots negateurs */
    private readonly array $negateurs;

    /** @var array<string> Liste des mots amplificateurs */
    private readonly array $amplificateurs;

    /** @var string|null Cle API Google NLP */
    private readonly ?string $cleApiGoogle;

    /** @var string Mode actif : 'google_nlp' ou 'lexique' */
    private string $mode;

    /** @var int Compteur d'appels API Google (pour les stats) */
    private int $compteurAppelsApi = 0;

    /** @var int Compteur d'erreurs API consecutives */
    private int $erreursConsecutives = 0;

    private const string URL_API_GOOGLE = 'https://language.googleapis.com/v1/documents:analyzeSentiment';
    private const int MAX_ERREURS_CONSECUTIVES = 3;
    private const int MAX_TAILLE_TEXTE_API = 5000;

    private const float SEUIL_POSITIF = 0.05;
    private const float SEUIL_NEGATIF = -0.05;
    private const float FACTEUR_NEGATION = -0.75;
    private const float FACTEUR_AMPLIFICATION = 1.5;

    /**
     * Constructeur : detecte le mode (Google NLP ou lexique).
     *
     * @param string $langue Langue du lexique ('en' ou 'fr', defaut 'en')
     */
    public function __construct(string $langue = 'en')
    {
        $this->negateurs = [
            // Anglais
            'not', 'no', 'never', 'neither', 'nobody', 'nothing',
            'nowhere', 'nor', 'cannot', "can't", "don't", "won't",
            "isn't", "aren't", "wasn't", "weren't", "hasn't",
            "haven't", "hadn't", "doesn't", "didn't", "shouldn't",
            "wouldn't", "couldn't", "mustn't",
            // Francais
            'pas', 'ne', 'ni', 'jamais', 'rien', 'aucun', 'sans',
        ];

        $this->amplificateurs = [
            // Anglais
            'very', 'really', 'extremely', 'absolutely', 'incredibly',
            'totally', 'completely',
            // Francais
            'très', 'vraiment', 'extrêmement', 'absolument',
        ];

        // Detecter la cle API Google NLP
        $this->cleApiGoogle = $this->detecterCleApi();

        if ($this->cleApiGoogle !== null) {
            $this->mode = 'google_nlp';
        } else {
            $this->mode = 'lexique';
        }

        // Toujours charger le lexique (fallback)
        $this->chargerLexique($langue);
    }

    /**
     * Retourne le mode d'analyse actif.
     */
    public function obtenirMode(): string
    {
        return $this->mode;
    }

    /**
     * Retourne le nombre d'appels API Google effectues.
     */
    public function obtenirCompteurAppelsApi(): int
    {
        return $this->compteurAppelsApi;
    }

    /**
     * Analyse le sentiment d'un texte.
     *
     * @return array{score: float, label: string, details: array<string, mixed>}
     */
    public function analyser(string $texte): array
    {
        if ($this->mode === 'google_nlp' && $this->erreursConsecutives < self::MAX_ERREURS_CONSECUTIVES) {
            $resultat = $this->analyserAvecGoogle($texte);
            if ($resultat !== null) {
                return $resultat;
            }
        }

        return $this->analyserAvecLexique($texte);
    }

    /**
     * Analyse un lot de textes.
     *
     * @param array<string> $textes Liste de textes a analyser
     * @return array<int, array{score: float, label: string, details: array<string, mixed>}>
     */
    public function analyserLot(array $textes): array
    {
        $resultats = [];

        foreach ($textes as $texte) {
            $resultats[] = $this->analyser($texte);
        }

        return $resultats;
    }

    /**
     * Analyse le sentiment via Google Cloud Natural Language API.
     *
     * @return array{score: float, label: string, details: array<string, mixed>}|null
     */
    private function analyserAvecGoogle(string $texte): ?array
    {
        $texteNettoye = strip_tags($texte);
        $texteNettoye = preg_replace('#https?://\S+#', '', $texteNettoye) ?? $texteNettoye;
        $texteNettoye = trim($texteNettoye);

        if (mb_strlen($texteNettoye) < 3) {
            return $this->analyserAvecLexique($texte);
        }

        // Tronquer les textes trop longs pour l'API
        if (mb_strlen($texteNettoye) > self::MAX_TAILLE_TEXTE_API) {
            $texteNettoye = mb_substr($texteNettoye, 0, self::MAX_TAILLE_TEXTE_API);
        }

        $url = self::URL_API_GOOGLE . '?key=' . urlencode($this->cleApiGoogle);

        $corpsRequete = json_encode([
            'document' => [
                'type'    => 'PLAIN_TEXT',
                'content' => $texteNettoye,
            ],
            'encodingType' => 'UTF8',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $contexte = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $corpsRequete,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $reponse = @file_get_contents($url, false, $contexte);

        if ($reponse === false) {
            $this->erreursConsecutives++;
            return null;
        }

        $donnees = json_decode($reponse, true);

        // Verifier les erreurs API
        if (isset($donnees['error'])) {
            $this->erreursConsecutives++;
            error_log('Google NLP API erreur : ' . ($donnees['error']['message'] ?? 'Erreur inconnue'));
            return null;
        }

        if (!isset($donnees['documentSentiment'])) {
            $this->erreursConsecutives++;
            return null;
        }

        $this->erreursConsecutives = 0;
        $this->compteurAppelsApi++;

        $scoreGoogle = (float) ($donnees['documentSentiment']['score'] ?? 0.0);
        $magnitude = (float) ($donnees['documentSentiment']['magnitude'] ?? 0.0);

        // Google retourne un score entre -1 et 1, compatible avec notre format
        $label = match (true) {
            $scoreGoogle > self::SEUIL_POSITIF => 'positif',
            $scoreGoogle < self::SEUIL_NEGATIF => 'negatif',
            default                            => 'neutre',
        };

        // Extraire les sentiments par phrase si disponibles
        $phrasesDetail = [];
        if (!empty($donnees['sentences'])) {
            foreach (array_slice($donnees['sentences'], 0, 10) as $phrase) {
                $phrasesDetail[] = [
                    'texte' => mb_substr($phrase['text']['content'] ?? '', 0, 100),
                    'score' => round((float) ($phrase['sentiment']['score'] ?? 0.0), 4),
                    'magnitude' => round((float) ($phrase['sentiment']['magnitude'] ?? 0.0), 4),
                ];
            }
        }

        return [
            'score'   => round($scoreGoogle, 4),
            'label'   => $label,
            'details' => [
                'methode'          => 'google_nlp',
                'magnitude'        => round($magnitude, 4),
                'nb_phrases'       => count($donnees['sentences'] ?? []),
                'phrases'          => $phrasesDetail,
                'langue_detectee'  => $donnees['language'] ?? null,
                'texte_original'   => mb_substr($texte, 0, 200),
            ],
        ];
    }

    /**
     * Analyse le sentiment via le lexique local (VADER-like).
     *
     * @return array{score: float, label: string, details: array<string, mixed>}
     */
    private function analyserAvecLexique(string $texte): array
    {
        $tokens = $this->tokeniser($texte);
        $score = $this->calculerScoreLexique($tokens);

        // Clamp le score entre -1 et 1
        $score = max(-1.0, min(1.0, $score));

        $label = match (true) {
            $score > self::SEUIL_POSITIF => 'positif',
            $score < self::SEUIL_NEGATIF => 'negatif',
            default                      => 'neutre',
        };

        return [
            'score'   => round($score, 4),
            'label'   => $label,
            'details' => [
                'methode'         => 'lexique',
                'nb_tokens'       => count($tokens),
                'tokens_trouves'  => $this->compterTokensLexique($tokens),
                'texte_original'  => mb_substr($texte, 0, 200),
            ],
        ];
    }

    /**
     * Detecte la cle API Google NLP depuis l'environnement ou la base de donnees.
     */
    private function detecterCleApi(): ?string
    {
        // Priorite 1 : variable d'environnement
        $cle = $_ENV['GOOGLE_NLP_API_KEY'] ?? getenv('GOOGLE_NLP_API_KEY') ?: null;

        // Priorite 2 : parametre en base de donnees
        if ($cle === null || $cle === '') {
            try {
                $bd = BaseDonnees::instance();
                $cle = $bd->obtenirParametre('google_nlp_api_key');
            } catch (\Throwable) {
                // Base pas encore initialisee
            }
        }

        return ($cle !== null && $cle !== '') ? $cle : null;
    }

    /**
     * Decoupe le texte en tokens normalises.
     *
     * @return array<string>
     */
    private function tokeniser(string $texte): array
    {
        // Minuscules
        $texte = mb_strtolower($texte);

        // Supprimer les URLs
        $texte = preg_replace('#https?://\S+#', '', $texte) ?? $texte;

        // Supprimer les balises HTML
        $texte = strip_tags($texte);

        // Conserver les apostrophes dans les contractions (don't, can't)
        // mais supprimer la ponctuation restante
        $texte = preg_replace("/[^\w\s'-]/u", ' ', $texte) ?? $texte;

        // Decouper par espaces
        $mots = preg_split('/\s+/', trim($texte), -1, PREG_SPLIT_NO_EMPTY);

        return $mots ?: [];
    }

    /**
     * Calcule le score de sentiment a partir des tokens.
     *
     * Gere la negation (inverse le score du mot suivant) et
     * l'amplification (augmente le score du mot suivant).
     */
    private function calculerScoreLexique(array $tokens): float
    {
        $scoreTotal = 0.0;
        $nbMotsScores = 0;
        $estNegation = false;
        $estAmplification = false;

        foreach ($tokens as $index => $token) {
            // Verifier si c'est un negateur
            if (in_array($token, $this->negateurs, true)) {
                $estNegation = true;
                continue;
            }

            // Verifier si c'est un amplificateur
            if (in_array($token, $this->amplificateurs, true)) {
                $estAmplification = true;
                continue;
            }

            // Chercher le mot dans le lexique
            if (!isset($this->lexique[$token])) {
                // Reinitialiser les modificateurs apres un mot non trouve
                // seulement si on a depasse la fenetre de 2 mots
                continue;
            }

            $scoreMot = $this->lexique[$token];

            // Appliquer l'amplification
            if ($estAmplification) {
                $scoreMot *= self::FACTEUR_AMPLIFICATION;
                $estAmplification = false;
            }

            // Appliquer la negation (inverse le sentiment)
            if ($estNegation) {
                $scoreMot *= self::FACTEUR_NEGATION;
                $estNegation = false;
            }

            $scoreTotal += $scoreMot;
            $nbMotsScores++;
        }

        // Normaliser par le nombre de mots scores pour eviter les biais de longueur
        if ($nbMotsScores === 0) {
            return 0.0;
        }

        return $scoreTotal / $nbMotsScores;
    }

    /**
     * Compte le nombre de tokens presents dans le lexique.
     */
    private function compterTokensLexique(array $tokens): int
    {
        $compte = 0;
        foreach ($tokens as $token) {
            if (isset($this->lexique[$token])) {
                $compte++;
            }
        }

        return $compte;
    }

    /**
     * Charge le lexique de sentiment depuis les fichiers JSON.
     *
     * Fusionne les lexiques EN et FR. Si les fichiers n'existent pas,
     * utilise un lexique de base integre.
     */
    private function chargerLexique(string $langue): void
    {
        $cheminBase = __DIR__ . '/../data/';

        // Charger le lexique principal (anglais)
        $cheminEn = $cheminBase . 'sentiment_en.json';
        if (file_exists($cheminEn)) {
            $contenu = file_get_contents($cheminEn);
            if ($contenu !== false) {
                /** @var array<string, float> $lexiqueEn */
                $lexiqueEn = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
                $this->lexique = array_merge($this->lexique, $lexiqueEn);
            }
        }

        // Charger le lexique francais
        $cheminFr = $cheminBase . 'sentiment_fr.json';
        if (file_exists($cheminFr)) {
            $contenu = file_get_contents($cheminFr);
            if ($contenu !== false) {
                /** @var array<string, float> $lexiqueFr */
                $lexiqueFr = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
                $this->lexique = array_merge($this->lexique, $lexiqueFr);
            }
        }

        // Si aucun lexique charge, utiliser un lexique minimal integre
        if (empty($this->lexique)) {
            $this->lexique = $this->lexiqueParDefaut();
        }
    }

    /**
     * Retourne un lexique minimal de secours si les fichiers JSON sont absents.
     *
     * @return array<string, float>
     */
    private function lexiqueParDefaut(): array
    {
        return [
            // Positifs anglais
            'good' => 0.7, 'great' => 0.8, 'excellent' => 0.9, 'amazing' => 0.9,
            'awesome' => 0.85, 'fantastic' => 0.9, 'love' => 0.8, 'best' => 0.8,
            'perfect' => 0.95, 'wonderful' => 0.85, 'happy' => 0.7, 'recommend' => 0.6,
            'impressive' => 0.75, 'reliable' => 0.65, 'quality' => 0.55,
            'beautiful' => 0.75, 'outstanding' => 0.85, 'superb' => 0.85,
            'pleased' => 0.65, 'satisfied' => 0.6, 'worth' => 0.5, 'like' => 0.4,
            'nice' => 0.5, 'easy' => 0.4, 'fast' => 0.4, 'smooth' => 0.5,
            // Negatifs anglais
            'bad' => -0.7, 'terrible' => -0.9, 'horrible' => -0.9, 'awful' => -0.85,
            'worst' => -0.9, 'hate' => -0.85, 'poor' => -0.7, 'disappointing' => -0.7,
            'broken' => -0.65, 'useless' => -0.8, 'waste' => -0.7, 'avoid' => -0.6,
            'scam' => -0.9, 'fraud' => -0.9, 'overpriced' => -0.55, 'slow' => -0.4,
            'ugly' => -0.65, 'annoying' => -0.6, 'frustrating' => -0.7,
            'disappointed' => -0.7, 'regret' => -0.65, 'problem' => -0.5,
            'issue' => -0.4, 'bug' => -0.45, 'crash' => -0.6, 'fail' => -0.65,
            // Positifs francais
            'bon' => 0.6, 'bien' => 0.5, 'excellent' => 0.9, 'super' => 0.75,
            'genial' => 0.8, 'parfait' => 0.95, 'formidable' => 0.85,
            'magnifique' => 0.85, 'adore' => 0.8, 'recommande' => 0.6,
            'qualite' => 0.55, 'fiable' => 0.65, 'satisfait' => 0.6,
            // Negatifs francais
            'mauvais' => -0.7, 'nul' => -0.8, 'horrible' => -0.9,
            'terrible' => -0.9, 'deteste' => -0.85, 'arnaque' => -0.9,
            'decevant' => -0.7, 'decu' => -0.7, 'probleme' => -0.5,
            'panne' => -0.6, 'casse' => -0.6, 'lent' => -0.4, 'cher' => -0.3,
        ];
    }
}
