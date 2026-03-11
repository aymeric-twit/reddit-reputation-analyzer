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
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0 fw-bold">Nouvelle analyse de réputation</h5>
            </div>
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

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary py-2 px-4 fw-semibold" id="btnLancer">
                            <i class="bi bi-play-fill me-1"></i> Lancer l'analyse
                        </button>
                    </div>
                </form>

                <!-- Zone de progression (affichée pendant l'analyse) -->
                <div id="zoneProgression" class="mt-4" style="display:none;">
                    <div class="card" style="border-left: 3px solid var(--brand-teal);">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <div class="spinner-border spinner-border-sm text-primary me-2" id="spinnerAnalyse"></div>
                                <strong id="etapeAnalyse">Initialisation...</strong>
                            </div>
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" id="barreProgression"
                                     style="width:0%; background-color: var(--brand-teal);">0%</div>
                            </div>
                            <small class="text-muted" id="detailsProgression"></small>
                        </div>
                    </div>
                </div>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="app.js"></script>
</body>
</html>
