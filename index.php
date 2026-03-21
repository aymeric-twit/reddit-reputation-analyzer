<?php require_once __DIR__ . '/boot.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reddit Reputation — Analyse de réputation de marque</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>

<?php if (!defined('PLATFORM_EMBEDDED')): ?>
<nav class="navbar mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand mb-0 h1">Reddit Reputation
            <span class="d-block d-sm-inline ms-sm-2">Analyse de réputation de marque sur Reddit</span>
        </span>
    </div>
</nav>
<?php endif; ?>

<div class="container pb-5" style="max-width:1200px;">

    <!-- Navigation tabs pour les vues -->
    <ul class="nav nav-pills mb-4" id="navigationPrincipale">
        <li class="nav-item"><a class="nav-link active" href="#" data-vue="dashboard"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-vue="nouvelle-analyse"><i class="bi bi-plus-circle me-1"></i> Nouvelle analyse</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-vue="historique"><i class="bi bi-clock-history me-1"></i> Historique</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-vue="parametres"><i class="bi bi-gear me-1"></i> Paramètres</a></li>
    </ul>

    <!-- ===== VUE DASHBOARD ===== -->
    <div id="vue-dashboard">
        <!-- Bandeau d'alertes -->
        <div id="alertesBanner" class="mb-3" style="display:none;"></div>

        <!-- Ligne KPI -->
        <div class="kpi-row mb-4" id="kpiDashboard"></div>

        <!-- Grille des cartes marques -->
        <div class="row g-3" id="grille-marques">
            <!-- JS peuplera les cartes marques ici -->
        </div>

        <!-- État vide -->
        <div id="etat-vide" class="text-center py-5" style="display:none;">
            <div style="font-size:3rem;" class="mb-3">📊</div>
            <h5>Aucune marque suivie</h5>
            <p class="text-muted">Lancez votre première analyse pour commencer à suivre une marque.</p>
            <button class="btn btn-primary" onclick="changerVue('nouvelle-analyse')">
                <i class="bi bi-plus-circle me-1"></i> Nouvelle analyse
            </button>
        </div>
    </div>

    <!-- ===== VUE NOUVELLE ANALYSE ===== -->
    <div id="vue-nouvelle-analyse" style="display:none;">
        <div class="row g-4">
        <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">Nouvelle analyse de réputation</h5>
                <button type="button" class="config-toggle" data-bs-toggle="collapse" data-bs-target="#configBody" aria-expanded="true"><i class="bi bi-chevron-down"></i></button>
            </div>
            <div class="collapse show" id="configBody">
            <div class="card-body">
                <form id="formulaireAnalyse">
                    <div class="row g-3">
                        <!-- Nom de la marque -->
                        <div class="col-md-6">
                            <label for="marque" class="form-label">Nom de la marque *</label>
                            <input type="text" class="form-control" id="marque" name="marque" required
                                   placeholder="Ex: Apple, Samsung, Nike..." autocomplete="off" list="listeMarques">
                            <datalist id="listeMarques"></datalist>
                        </div>
                        <!-- Période -->
                        <div class="col-md-3">
                            <label for="periode" class="form-label">Période</label>
                            <select class="form-select" id="periode" name="periode">
                                <option value="week">7 derniers jours</option>
                                <option value="month" selected>30 derniers jours</option>
                                <option value="year">12 derniers mois</option>
                                <option value="all">Tout</option>
                            </select>
                        </div>
                        <!-- Nombre max de posts -->
                        <div class="col-md-3">
                            <label for="limite" class="form-label">Nombre max de posts</label>
                            <input type="number" class="form-control" id="limite" name="limite"
                                   value="500" min="50" max="2000" step="50">
                        </div>
                        <!-- Domaine Google -->
                        <div class="col-md-3">
                            <label for="domaineGoogle" class="form-label">Google</label>
                            <select class="form-select" id="domaineGoogle" name="domaine_google">
                                <option value="google.com">google.com (EN)</option>
                                <option value="google.fr">google.fr (FR)</option>
                                <option value="google.co.uk">google.co.uk (UK)</option>
                                <option value="google.de">google.de (DE)</option>
                                <option value="google.es">google.es (ES)</option>
                                <option value="google.it">google.it (IT)</option>
                            </select>
                        </div>
                        <!-- Subreddits -->
                        <div class="col-md-6">
                            <label for="subreddits" class="form-label">Subreddits ciblés <small class="text-muted">(optionnel, séparés par des virgules)</small></label>
                            <input type="text" class="form-control" id="subreddits" name="subreddits"
                                   placeholder="Ex: technology, gadgets, apple">
                        </div>
                        <!-- Mots-clés -->
                        <div class="col-md-6">
                            <label for="motsCles" class="form-label">Mots-clés associés <small class="text-muted">(optionnel)</small></label>
                            <input type="text" class="form-control" id="motsCles" name="mots_cles"
                                   placeholder="Ex: iPhone, MacBook, support">
                        </div>
                    </div>

                    <!-- Mode de collecte -->
                    <?php $serpApiDisponible = (getenv('SERPAPI_KEY') ?: '') !== ''; ?>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Mode de collecte</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="mode_collecte" id="modeAuto" value="serpapi"
                                   <?= $serpApiDisponible ? '' : 'disabled' ?>>
                            <label class="btn btn-outline-primary" for="modeAuto">
                                <i class="bi bi-robot me-1"></i> Automatique (SerpAPI)
                                <small class="d-block opacity-75"><?= $serpApiDisponible ? 'Données partielles' : 'Non configuré' ?></small>
                            </label>
                            <input type="radio" class="btn-check" name="mode_collecte" id="modeManuel" value="navigateur" checked>
                            <label class="btn btn-outline-primary" for="modeManuel">
                                <i class="bi bi-window me-1"></i> Manuel (via navigateur)
                            </label>
                        </div>
                    </div>

                    <!-- Bloc mode navigateur (visible par defaut, navigateur est le mode recommande) -->
                    <div id="blocNavigateur" class="mt-3">
                        <div class="alert alert-info mb-3" style="font-size: 14px;">
                            <strong>1.</strong> Cliquez sur un bouton ci-dessous pour ouvrir Reddit (nouvel onglet)<br>
                            <strong>2.</strong> <kbd>Ctrl</kbd>+<kbd>A</kbd> puis <kbd>Ctrl</kbd>+<kbd>C</kbd> pour tout copier<br>
                            <strong>3.</strong> Revenez ici, cliquez <em>Coller</em> — recommencez pour ajouter d'autres pages<br>
                            <strong>4.</strong> Cliquez <em>Lancer l'analyse</em> quand vous avez assez de posts
                        </div>

                        <!-- Boutons de recherche Reddit par tri -->
                        <div class="btn-group w-100 mb-2" role="group" id="boutonsRecherche">
                            <button type="button" class="btn btn-outline-secondary btn-reddit-sort" data-sort="relevance">
                                <i class="bi bi-search me-1"></i> Pertinence
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-reddit-sort" data-sort="new">
                                <i class="bi bi-clock me-1"></i> R&eacute;cents
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-reddit-sort" data-sort="top">
                                <i class="bi bi-arrow-up-circle me-1"></i> Top
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-reddit-sort" data-sort="comments">
                                <i class="bi bi-chat-dots me-1"></i> Comments
                            </button>
                        </div>

                        <!-- Bouton page suivante (masque par defaut) -->
                        <button type="button" id="btnPageSuivante" class="btn btn-outline-teal w-100 mb-2 d-none">
                            <i class="bi bi-arrow-right me-1"></i> Page suivante
                        </button>

                        <!-- Coller les donnees -->
                        <button type="button" id="btnCollerDonnees" class="btn btn-gold w-100 mb-2" disabled>
                            <i class="bi bi-clipboard-check me-1"></i> Coller les donn&eacute;es copi&eacute;es
                        </button>

                        <!-- Apercu / compteur -->
                        <div id="apercuCollage" class="d-none"></div>

                        <!-- Lancer l'analyse (visible apres premier collage) -->
                        <button type="button" id="btnLancerNavigateur" class="btn btn-primary w-100 py-2 fw-semibold d-none">
                            <i class="bi bi-play-fill me-1"></i> Lancer l'analyse
                        </button>
                    </div>

                    <div class="mt-4 d-none" id="blocBtnLancer">
                        <button type="submit" class="btn btn-primary py-2 px-4 fw-semibold" id="btnLancer">
                            <i class="bi bi-play-fill me-1"></i> Lancer l'analyse
                        </button>
                    </div>
                </form>
            </div>
            </div>
        </div>
        </div>
        <div class="col-lg-4" id="helpPanel">
                                <div id="platformCreditsSlot"></div>
        <div class="config-help-panel">
                <div class="help-title mb-2">
                    <i class="bi bi-info-circle me-1"></i> Comment ça marche
                </div>
                <ul>
                    <li><strong>Marque</strong> : ajoutez le nom de votre marque et lancez l'analyse.</li>
                    <li><strong>Reddit</strong> : l'outil parcourt Reddit et applique une analyse de sentiment (NLP).</li>
                    <li><strong>Insights</strong> : sujets récurrents, ton général, opportunités détectées.</li>
                    <li><strong>Comparaison</strong> : comparez plusieurs marques côte à côte.</li>
                    <li><strong>Dashboard</strong> : KPIs de réputation et tendances.</li>
                </ul>
                <hr>
                <div class="help-title mb-2">
                    <i class="bi bi-speedometer2 me-1"></i> Quota
                </div>
                <ul class="mb-0">
                    <li>1 analyse de marque = <strong>1 crédit</strong></li>
                </ul>
            </div>
        </div>
        </div>
    </div>

    <!-- ===== ZONE PROGRESSION (globale, en dehors des vues) ===== -->
    <div id="zoneProgression" class="d-none">
        <!-- Status message -->
        <div id="statusMsg" class="status-msg mb-4">
            <i class="bi bi-hourglass-split me-1"></i>
            <span id="etapeAnalyse">Initialisation...</span>
        </div>

        <!-- KPI en temps réel -->
        <div class="kpi-row mb-4" id="kpiProgression">
            <div class="kpi-card kpi-dark">
                <div class="kpi-value" id="kpiProgressPosts">0</div>
                <div class="kpi-label">Publications</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value" id="kpiProgressComments">0</div>
                <div class="kpi-label">Commentaires</div>
            </div>
            <div class="kpi-card kpi-gold">
                <div class="kpi-value" id="kpiProgressPourcent">0 %</div>
                <div class="kpi-label">Progression</div>
            </div>
            <div class="kpi-card kpi-green">
                <div class="kpi-value" id="kpiProgressDuree">&mdash;</div>
                <div class="kpi-label">Dur&eacute;e</div>
            </div>
        </div>

        <!-- Barre de progression -->
        <div class="progress mb-4" style="height: 8px; border-radius: 4px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="barreProgression"
                 style="width:0%; background-color: var(--brand-teal);" role="progressbar"
                 aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
        </div>

        <!-- Journal d'exécution (style sitemap-killer) -->
        <div class="card mb-4" id="sectionJournal">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-terminal me-1"></i> Journal d'ex&eacute;cution
                    <small class="text-muted ms-2" id="livelog-compteur">0 lignes</small>
                </h6>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnToggleJournal" title="R&eacute;duire / Agrandir">
                    <i class="bi bi-chevron-up"></i>
                </button>
            </div>
            <div class="card-body p-0" id="corpsJournal">
                <pre class="journal-log mb-0" id="livelog"></pre>
            </div>
        </div>
    </div>

    <!-- ===== VUE HISTORIQUE ===== -->
    <div id="vue-historique" style="display:none;">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">Historique des analyses</h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="filtreMarqueHistorique" style="width:auto;">
                        <option value="">Toutes les marques</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="tableHistorique">
                    <thead>
                        <tr>
                            <th>Marque</th>
                            <th>Date</th>
                            <th>Période</th>
                            <th>Score</th>
                            <th>Publications</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="corpsHistorique"></tbody>
                </table>
            </div>
            <div id="paginationHistorique" class="d-flex justify-content-center py-3"></div>
        </div>
    </div>

    <!-- ===== VUE PARAMETRES ===== -->
    <div id="vue-parametres" style="display:none;">
        <div class="row g-4">
            <!-- Configuration API Reddit -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 fw-bold">API Reddit</h5>
                    </div>
                    <div class="card-body">
                        <form id="formulaireParametresApi">
                            <div class="mb-3">
                                <label for="paramClientId" class="form-label">Client ID</label>
                                <input type="text" class="form-control" id="paramClientId" name="reddit_client_id">
                            </div>
                            <div class="mb-3">
                                <label for="paramClientSecret" class="form-label">Client Secret</label>
                                <input type="password" class="form-control" id="paramClientSecret" name="reddit_client_secret">
                            </div>
                            <div class="mb-3">
                                <label for="paramUserAgent" class="form-label">User Agent</label>
                                <input type="text" class="form-control" id="paramUserAgent" name="reddit_user_agent"
                                       value="reddit-reputation-analyzer/1.0">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check-lg me-1"></i> Enregistrer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Configuration Google NLP -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-cloud me-1"></i> Google NLP</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Clé API Google Cloud Natural Language pour l'analyse de sentiment ML.
                            Si absente, le lexique local est utilisé.
                        </p>
                        <form id="formulaireParametresNlp">
                            <div class="mb-3">
                                <label for="paramGoogleNlpKey" class="form-label">Clé API Google NLP</label>
                                <input type="password" class="form-control" id="paramGoogleNlpKey" name="google_nlp_api_key">
                            </div>
                            <div id="nlpModeIndicateur" class="mb-3">
                                <!-- JS affichera le mode actif ici -->
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check-lg me-1"></i> Enregistrer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Paramètres par défaut -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 fw-bold">Paramètres par défaut</h5>
                    </div>
                    <div class="card-body">
                        <form id="formulaireParametresDefaut">
                            <div class="mb-3">
                                <label for="paramPeriode" class="form-label">Période par défaut</label>
                                <select class="form-select" id="paramPeriode" name="periode_defaut">
                                    <option value="week">7 jours</option>
                                    <option value="month">30 jours</option>
                                    <option value="year">12 mois</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="paramLimite" class="form-label">Limite de posts par défaut</label>
                                <input type="number" class="form-control" id="paramLimite" name="limite_defaut" value="500">
                            </div>
                            <div class="mb-3">
                                <label for="paramSubreddits" class="form-label">Subreddits favoris</label>
                                <input type="text" class="form-control" id="paramSubreddits" name="subreddits_favoris"
                                       placeholder="technology, gadgets, reviews">
                            </div>
                            <div class="mb-3">
                                <label for="paramSeuilAlerte" class="form-label">Seuil d'alerte (score min)</label>
                                <input type="number" class="form-control" id="paramSeuilAlerte" name="seuil_alerte_score"
                                       value="30" min="0" max="100">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check-lg me-1"></i> Enregistrer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Marques suivies -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Marques suivies</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Slug</th>
                                    <th>Date de création</th>
                                    <th>Dernière analyse</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="corpsMarques"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Bootstrap JS + Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/chart.umd.min.js"></script>
<script src="app.js"></script>
</body>
</html>
