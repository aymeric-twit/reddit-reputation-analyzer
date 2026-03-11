<?php require_once __DIR__ . '/boot.php';

$analyseId = (int)($_GET['analyse_id'] ?? 0);

// Charger les informations de base de l'analyse pour le titre de la page
$bd = BaseDonnees::instance();
$analyse = $bd->selectionnerUn(
    "SELECT a.*, m.nom as marque_nom FROM analyses a JOIN marques m ON a.marque_id = m.id WHERE a.id = ?",
    [$analyseId]
);

if (!$analyse) {
    echo '<div class="container py-5"><div class="alert alert-danger">Analyse introuvable.</div></div>';
    return;
}

$scoreReputation = $analyse['score_reputation'] !== null ? round((float)$analyse['score_reputation']) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats — <?= htmlspecialchars($analyse['marque_nom']) ?> — Reddit Reputation</title>
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

<div class="container pb-5" style="max-width:1200px;" id="conteneurResultats" data-analyse-id="<?= $analyseId ?>">

    <!-- Retour + Titre -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Retour au dashboard
        </a>
        <h4 class="mb-0 fw-bold"><?= htmlspecialchars($analyse['marque_nom']) ?></h4>
        <?php if ($scoreReputation !== null): ?>
            <?php
                $couleurScore = $scoreReputation >= 60 ? 'success' : ($scoreReputation >= 40 ? 'warning' : 'danger');
            ?>
            <span class="badge bg-<?= $couleurScore ?> fs-6"><?= $scoreReputation ?>/100</span>
        <?php endif; ?>
        <small class="text-muted ms-auto">
            Analyse du <?= htmlspecialchars($analyse['date_lancement'] ?? '') ?>
            <?php if ($analyse['periode_debut'] && $analyse['periode_fin']): ?>
                &mdash; Période : <?= htmlspecialchars($analyse['periode_debut']) ?> au <?= htmlspecialchars($analyse['periode_fin']) ?>
            <?php endif; ?>
        </small>
    </div>

    <!-- Navigation onglets (scrollable sur mobile) -->
    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-4" id="onglets-resultats" role="tablist" style="white-space:nowrap;">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="onglet-synthese" data-bs-toggle="tab" data-bs-target="#tab-synthese" type="button" role="tab" aria-selected="true">
                <i class="bi bi-graph-up me-1"></i> Synth&egrave;se
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-sujets" data-bs-toggle="tab" data-bs-target="#tab-sujets" type="button" role="tab">
                <i class="bi bi-chat-dots me-1"></i> Sujets dominants
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-questions" data-bs-toggle="tab" data-bs-target="#tab-questions" type="button" role="tab">
                <i class="bi bi-question-circle me-1"></i> Questions fr&eacute;quentes
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-discussions" data-bs-toggle="tab" data-bs-target="#tab-discussions" type="button" role="tab">
                <i class="bi bi-fire me-1"></i> Discussions influentes
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-facteurs" data-bs-toggle="tab" data-bs-target="#tab-facteurs" type="button" role="tab">
                <i class="bi bi-sliders me-1"></i> Facteurs de r&eacute;putation
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-engagement" data-bs-toggle="tab" data-bs-target="#tab-engagement" type="button" role="tab">
                <i class="bi bi-people me-1"></i> Engagement
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-geographie" data-bs-toggle="tab" data-bs-target="#tab-geographie" type="button" role="tab">
                <i class="bi bi-geo-alt me-1"></i> G&eacute;ographie
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="onglet-opportunites" data-bs-toggle="tab" data-bs-target="#tab-opportunites" type="button" role="tab">
                <i class="bi bi-lightbulb me-1"></i> Opportunit&eacute;s
            </button>
        </li>
    </ul>

    <!-- Contenu des onglets -->
    <div class="tab-content" id="contenu-onglets">

        <!-- ===== TAB 1 : SYNTHESE ===== -->
        <div class="tab-pane fade show active" id="tab-synthese" role="tabpanel">
            <!-- KPI -->
            <div class="row g-3 mb-4" id="synthese-kpis">
                <div class="col-md-3 col-6">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-semibold">Score de r&eacute;putation</small>
                            <div class="mt-2 mb-1" id="synthese-gauge" style="min-height:100px;">
                                <canvas id="graphiqueGauge" width="140" height="100"></canvas>
                            </div>
                            <h2 class="fw-bold mb-0" id="synthese-score">—</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card text-center h-100">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <small class="text-muted text-uppercase fw-semibold">Volume total</small>
                            <h2 class="fw-bold mt-2 mb-1" id="synthese-volume">—</h2>
                            <small class="text-muted" id="synthese-volume-detail">publications analys&eacute;es</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card text-center h-100">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <small class="text-muted text-uppercase fw-semibold">Sentiment dominant</small>
                            <h2 class="fw-bold mt-2 mb-1" id="synthese-sentiment">—</h2>
                            <small class="text-muted" id="synthese-sentiment-detail"></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card text-center h-100">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <small class="text-muted text-uppercase fw-semibold">Top subreddit</small>
                            <h2 class="fw-bold mt-2 mb-1" id="synthese-top-subreddit">—</h2>
                            <small class="text-muted" id="synthese-top-subreddit-detail"></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Graphiques sentiment + timeline -->
            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <h6 class="mb-0 fw-bold">R&eacute;partition des sentiments</h6>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-center">
                            <canvas id="graphiqueSentimentDonut" style="max-width:280px; max-height:280px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card h-100">
                        <div class="card-header">
                            <h6 class="mb-0 fw-bold">&Eacute;volution temporelle</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="graphiqueEvolutionTemporelle" style="max-height:280px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top 3 subreddits -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Top 3 subreddits</h6>
                </div>
                <div class="card-body" id="synthese-top-subreddits">
                    <!-- Rempli par JS -->
                </div>
            </div>
        </div>

        <!-- ===== TAB 2 : SUJETS DOMINANTS ===== -->
        <div class="tab-pane fade" id="tab-sujets" role="tabpanel">
            <!-- Nuage de mots -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Nuage de mots-cl&eacute;s</h6>
                </div>
                <div class="card-body text-center" id="sujets-wordcloud" style="min-height:250px;">
                    <!-- Rempli par JS (mots-cles en spans ponderes) -->
                </div>
            </div>

            <!-- Table des sujets -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Top 10 des sujets</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="tableSujets">
                        <thead>
                            <tr>
                                <th>Sujet</th>
                                <th>Fr&eacute;quence</th>
                                <th>Sentiment</th>
                                <th>Tendance</th>
                                <th>Posts exemples</th>
                            </tr>
                        </thead>
                        <tbody id="corpsSujets">
                            <!-- Rempli par JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== TAB 3 : QUESTIONS FREQUENTES ===== -->
        <div class="tab-pane fade" id="tab-questions" role="tabpanel">
            <!-- Filtres par categorie -->
            <div class="d-flex gap-2 mb-4 flex-wrap" id="filtres-questions">
                <button class="btn btn-sm btn-outline-secondary active" data-categorie="toutes">Toutes</button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="support_technique">
                    <i class="bi bi-tools me-1"></i> Support technique
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="avis_produit">
                    <i class="bi bi-star me-1"></i> Avis produit
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="comparaison">
                    <i class="bi bi-arrow-left-right me-1"></i> Comparaison
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="pre_achat">
                    <i class="bi bi-cart me-1"></i> Pr&eacute;-achat
                </button>
            </div>

            <!-- Liste des questions -->
            <div class="row g-3" id="liste-questions">
                <!-- Rempli par JS : cards par categorie avec question, lien Reddit, occurrences, badge avec/sans reponse -->
            </div>

            <!-- Etat vide -->
            <div id="questions-etat-vide" class="text-center py-4" style="display:none;">
                <p class="text-muted">Aucune question d&eacute;tect&eacute;e dans cette cat&eacute;gorie.</p>
            </div>
        </div>

        <!-- ===== TAB 4 : DISCUSSIONS INFLUENTES ===== -->
        <div class="tab-pane fade" id="tab-discussions" role="tabpanel">
            <!-- Filtres -->
            <div class="d-flex gap-2 mb-4 flex-wrap" id="filtres-discussions">
                <button class="btn btn-sm btn-outline-secondary active" data-filtre="toutes">Toutes</button>
                <button class="btn btn-sm btn-outline-success" data-filtre="virale_positive">
                    <i class="bi bi-hand-thumbs-up me-1"></i> Virales positives
                </button>
                <button class="btn btn-sm btn-outline-danger" data-filtre="virale_negative">
                    <i class="bi bi-hand-thumbs-down me-1"></i> Virales n&eacute;gatives
                </button>
                <button class="btn btn-sm btn-outline-warning" data-filtre="controversee">
                    <i class="bi bi-exclamation-triangle me-1"></i> Controvers&eacute;es
                </button>
            </div>

            <!-- Table des discussions -->
            <div class="card mb-4">
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="tableDiscussions">
                        <thead>
                            <tr>
                                <th>Titre</th>
                                <th>Subreddit</th>
                                <th>Score engagement</th>
                                <th>Sentiment</th>
                                <th>Type</th>
                                <th>Lien</th>
                            </tr>
                        </thead>
                        <tbody id="corpsDiscussions">
                            <!-- Rempli par JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Scatter plot engagement vs sentiment -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Engagement vs Sentiment</h6>
                </div>
                <div class="card-body">
                    <canvas id="graphiqueEngagementSentiment" style="max-height:350px;"></canvas>
                </div>
            </div>
        </div>

        <!-- ===== TAB 5 : FACTEURS DE REPUTATION ===== -->
        <div class="tab-pane fade" id="tab-facteurs" role="tabpanel">
            <div class="row g-3 mb-4">
                <!-- Facteurs positifs -->
                <div class="col-md-6">
                    <div class="card h-100 border-success">
                        <div class="card-header bg-transparent">
                            <h6 class="mb-0 fw-bold text-success">
                                <i class="bi bi-plus-circle me-1"></i> Facteurs positifs
                            </h6>
                        </div>
                        <div class="card-body" id="facteurs-positifs">
                            <!-- Rempli par JS : nom, barre frequence, score influence, posts exemples -->
                        </div>
                    </div>
                </div>
                <!-- Facteurs negatifs -->
                <div class="col-md-6">
                    <div class="card h-100 border-danger">
                        <div class="card-header bg-transparent">
                            <h6 class="mb-0 fw-bold text-danger">
                                <i class="bi bi-dash-circle me-1"></i> Facteurs n&eacute;gatifs
                            </h6>
                        </div>
                        <div class="card-body" id="facteurs-negatifs">
                            <!-- Rempli par JS : nom, barre frequence, score influence, posts exemples -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Radar chart -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Profil de r&eacute;putation</h6>
                    <small class="text-muted">Qualit&eacute;, Prix, Support, Innovation, Fiabilit&eacute;</small>
                </div>
                <div class="card-body d-flex justify-content-center">
                    <canvas id="graphiqueRadarFacteurs" style="max-width:450px; max-height:350px;"></canvas>
                </div>
            </div>
        </div>

        <!-- ===== TAB 6 : ENGAGEMENT ===== -->
        <div class="tab-pane fade" id="tab-engagement" role="tabpanel">
            <!-- Stats globales -->
            <div class="row g-3 mb-4" id="engagement-stats">
                <div class="col-md-4 col-6">
                    <div class="card text-center">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-semibold">Engagement moyen</small>
                            <h3 class="fw-bold mt-2 mb-0" id="engagement-moyen">—</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-6">
                    <div class="card text-center">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-semibold">Power users</small>
                            <h3 class="fw-bold mt-2 mb-0" id="engagement-power-users">—</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-6">
                    <div class="card text-center">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-semibold">Auteurs uniques</small>
                            <h3 class="fw-bold mt-2 mb-0" id="engagement-auteurs-uniques">—</h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top auteurs -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Top auteurs</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="tableAuteurs">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Karma</th>
                                <th>Publications</th>
                                <th>Score d'influence</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody id="corpsAuteurs">
                            <!-- Rempli par JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bar chart engagement par subreddit -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Engagement par subreddit</h6>
                </div>
                <div class="card-body">
                    <canvas id="graphiqueEngagementSubreddits" style="max-height:300px;"></canvas>
                </div>
            </div>
        </div>

        <!-- ===== TAB 7 : GEOGRAPHIE ===== -->
        <div class="tab-pane fade" id="tab-geographie" role="tabpanel">
            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle me-1"></i>
                Les donn&eacute;es g&eacute;ographiques sont estim&eacute;es &agrave; partir des indices disponibles (subreddits r&eacute;gionaux, fuseaux horaires, langue).
                La couverture peut &ecirc;tre limit&eacute;e.
            </div>

            <!-- Table geographique -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">R&eacute;partition g&eacute;ographique</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="tableGeographie">
                        <thead>
                            <tr>
                                <th>R&eacute;gion</th>
                                <th>Sentiment moyen</th>
                                <th>Sujets dominants</th>
                                <th>Volume</th>
                            </tr>
                        </thead>
                        <tbody id="corpsGeographie">
                            <!-- Rempli par JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bar chart sentiment par region -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Sentiment par r&eacute;gion</h6>
                </div>
                <div class="card-body">
                    <canvas id="graphiqueSentimentRegions" style="max-height:300px;"></canvas>
                </div>
            </div>
        </div>

        <!-- ===== TAB 8 : OPPORTUNITES ===== -->
        <div class="tab-pane fade" id="tab-opportunites" role="tabpanel">
            <!-- Categories d'opportunites -->
            <div class="d-flex gap-2 mb-4 flex-wrap" id="filtres-opportunites">
                <button class="btn btn-sm btn-outline-secondary active" data-categorie="toutes">Toutes</button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="contenu">
                    <i class="bi bi-file-text me-1"></i> Contenu
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="communautes">
                    <i class="bi bi-people me-1"></i> Communaut&eacute;s
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="questions">
                    <i class="bi bi-question-circle me-1"></i> Questions
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-categorie="advocates">
                    <i class="bi bi-megaphone me-1"></i> Advocates
                </button>
            </div>

            <!-- Liste priorisee -->
            <div id="liste-opportunites" class="mb-4">
                <!-- Rempli par JS : items avec badges impact (haute=danger, moyenne=warning, basse=success) -->
            </div>

            <!-- Recommandations -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Recommandations</h6>
                </div>
                <div class="card-body" id="recommandations">
                    <!-- Rempli par JS : liste avec effort/impact -->
                </div>
            </div>

            <!-- Export -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold">Exporter les r&eacute;sultats</h6>
                </div>
                <div class="card-body d-flex gap-3 flex-wrap">
                    <a href="api/export.php?analyse_id=<?= $analyseId ?>&format=csv" class="btn btn-outline-secondary">
                        <i class="bi bi-filetype-csv me-1"></i> Export CSV
                    </a>
                    <a href="api/export.php?analyse_id=<?= $analyseId ?>&format=json" class="btn btn-outline-secondary">
                        <i class="bi bi-filetype-json me-1"></i> Export JSON
                    </a>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->

</div><!-- /container -->

<!-- Bootstrap JS + Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="app.js"></script>
</body>
</html>
