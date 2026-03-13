<?php require_once __DIR__ . '/boot.php';

$bd = BaseDonnees::instance();
$marques = $bd->selectionner(
    "SELECT m.*,
        (SELECT score_reputation FROM analyses WHERE marque_id = m.id AND statut = 'terminee' ORDER BY date_lancement DESC LIMIT 1) as dernier_score
    FROM marques m
    ORDER BY m.nom"
);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reddit Reputation — Comparaison de marques</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
    <style>
        .marque-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 2rem;
            background: var(--brand-teal-light, #e8f4f4);
            color: var(--brand-dark, #004c4c);
            font-size: 0.875rem;
            font-weight: 600;
        }
        .marque-pill .btn-retirer {
            background: none;
            border: none;
            padding: 0;
            font-size: 1rem;
            line-height: 1;
            color: var(--brand-dark, #004c4c);
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.15s;
        }
        .marque-pill .btn-retirer:hover {
            opacity: 1;
        }
        .selecteur-marques .form-check {
            padding: 0.5rem 0.75rem 0.5rem 2.25rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .selecteur-marques .form-check:last-child {
            border-bottom: none;
        }
        .selecteur-marques .form-check:hover {
            background: var(--brand-teal-light, #e8f4f4);
        }
        .selecteur-marques .form-check-input:checked ~ .form-check-label {
            font-weight: 600;
            color: var(--brand-dark, #004c4c);
        }
        .liste-marques-scroll {
            max-height: 280px;
            overflow-y: auto;
        }
        .score-jauge {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 auto 0.5rem;
        }
        .score-bon { background: rgba(25, 135, 84, 0.12); color: #198754; border: 3px solid #198754; }
        .score-moyen { background: rgba(251, 176, 59, 0.12); color: #d4920a; border: 3px solid #fbb03b; }
        .score-mauvais { background: rgba(220, 53, 69, 0.12); color: #dc3545; border: 3px solid #dc3545; }
        .meilleure-valeur {
            background: rgba(25, 135, 84, 0.08);
            font-weight: 700;
            color: #198754;
        }
        #resultatsComparaison { display: none; }
        .graphique-sentiment-container {
            min-height: 220px;
        }
    </style>
</head>
<body>

<?php if (!defined('PLATFORM_EMBEDDED')): ?>
<nav class="navbar mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand mb-0 h1">Reddit Reputation
            <span class="d-block d-sm-inline ms-sm-2">Comparaison de marques</span>
        </span>
    </div>
</nav>
<?php endif; ?>

<div class="container pb-5" data-page="comparaison" style="max-width:1200px;">

    <!-- Lien retour -->
    <a href="index.php" class="btn btn-sm btn-outline-secondary mb-4">
        <i class="bi bi-arrow-left me-1"></i> Retour au tableau de bord
    </a>

    <!-- ===== SELECTEUR DE MARQUES ===== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0 fw-bold"><i class="bi bi-bar-chart-line me-2"></i>Comparer des marques</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">Selectionnez entre 2 et 5 marques pour les comparer.</p>

            <?php if (empty($marques)): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i> Aucune marque disponible. Lancez d'abord une analyse depuis le tableau de bord.
                </div>
            <?php else: ?>
                <!-- Pills des marques selectionnees -->
                <div id="pillsMarques" class="d-flex flex-wrap gap-2 mb-3" style="min-height:2rem;"></div>

                <!-- Liste des marques avec checkboxes -->
                <div class="selecteur-marques border rounded mb-3">
                    <div class="liste-marques-scroll">
                        <?php foreach ($marques as $marque): ?>
                        <div class="form-check">
                            <input class="form-check-input cb-marque" type="checkbox"
                                   value="<?= htmlspecialchars((string)$marque['id']) ?>"
                                   id="marque-<?= htmlspecialchars((string)$marque['id']) ?>"
                                   data-nom="<?= htmlspecialchars((string)$marque['nom']) ?>"
                                   data-score="<?= htmlspecialchars((string)($marque['dernier_score'] ?? '')) ?>">
                            <label class="form-check-label w-100 d-flex justify-content-between" for="marque-<?= htmlspecialchars((string)$marque['id']) ?>">
                                <span><?= htmlspecialchars((string)$marque['nom']) ?></span>
                                <?php if ($marque['dernier_score'] !== null): ?>
                                    <span class="badge bg-<?= $marque['dernier_score'] >= 60 ? 'success' : ($marque['dernier_score'] >= 40 ? 'warning' : 'danger') ?>">
                                        <?= htmlspecialchars((string)$marque['dernier_score']) ?>/100
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pas de score</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="button" class="btn btn-primary fw-semibold px-4" id="btnComparer" disabled>
                    <i class="bi bi-bar-chart-line me-1"></i> Comparer
                </button>
                <small class="text-muted ms-2" id="compteurSelection">0 marque(s) selectionnee(s)</small>
            <?php endif; ?>
        </div>
    </div>

    <!-- Zone de chargement -->
    <div id="chargementComparaison" class="text-center py-5" style="display:none;">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <p class="text-muted">Chargement de la comparaison...</p>
    </div>

    <!-- ===== RESULTATS DE COMPARAISON ===== -->
    <div id="resultatsComparaison">

        <!-- 1. Score cards cote a cote -->
        <div class="row g-3 mb-4" id="scoreCards"></div>

        <!-- 2. Graphique en barres des scores -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2"></i>Comparaison des scores</h5>
            </div>
            <div class="card-body">
                <canvas id="graphiqueScores" height="80"></canvas>
            </div>
        </div>

        <!-- 3. Comparaison des sentiments (donuts) -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0 fw-bold"><i class="bi bi-pie-chart-fill me-2"></i>Repartition des sentiments</h5>
            </div>
            <div class="card-body">
                <div class="row g-3" id="donutsSentiments"></div>
            </div>
        </div>

        <!-- 4. Tableau comparatif des sujets -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0 fw-bold"><i class="bi bi-tags-fill me-2"></i>Comparaison par sujet</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="tableauSujets">
                    <thead id="enteteSujets"></thead>
                    <tbody id="corpsSujets"></tbody>
                </table>
            </div>
        </div>

        <!-- 5. Evolution temporelle -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2"></i>Evolution temporelle</h5>
            </div>
            <div class="card-body">
                <canvas id="graphiqueEvolution" height="100"></canvas>
            </div>
        </div>

        <!-- 6. Tableau recapitulatif -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0 fw-bold"><i class="bi bi-table me-2"></i>Tableau recapitulatif</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="tableauRecap">
                    <thead id="enteteRecap"></thead>
                    <tbody id="corpsRecap"></tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<!-- Bootstrap JS + Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/chart.umd.min.js"></script>
<script src="app.js"></script>
<script>
(function() {
    'use strict';

    const COULEURS_MARQUES = [
        '#004c4c', '#66b2b2', '#fbb03b', '#dc3545', '#6f42c1'
    ];

    const checkboxes = document.querySelectorAll('.cb-marque');
    const btnComparer = document.getElementById('btnComparer');
    const pillsContainer = document.getElementById('pillsMarques');
    const compteur = document.getElementById('compteurSelection');
    const resultats = document.getElementById('resultatsComparaison');
    const chargement = document.getElementById('chargementComparaison');

    let graphiqueScoresInstance = null;
    let graphiqueEvolutionInstance = null;
    const graphiquesSentimentInstances = [];

    /**
     * Met a jour les pills et le compteur de selection
     */
    function mettreAJourSelection() {
        if (!pillsContainer || !compteur || !btnComparer) return;

        const selectionnees = document.querySelectorAll('.cb-marque:checked');
        const nb = selectionnees.length;

        // Pills
        pillsContainer.innerHTML = '';
        selectionnees.forEach(function(cb) {
            const pill = document.createElement('span');
            pill.className = 'marque-pill';
            pill.innerHTML = cb.dataset.nom +
                ' <button class="btn-retirer" data-id="' + cb.value + '" title="Retirer">&times;</button>';
            pillsContainer.appendChild(pill);
        });

        // Boutons retirer dans les pills
        pillsContainer.querySelectorAll('.btn-retirer').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const cb = document.getElementById('marque-' + this.dataset.id);
                if (cb) { cb.checked = false; mettreAJourSelection(); }
            });
        });

        // Compteur
        compteur.textContent = nb + ' marque(s) selectionnee(s)';

        // Desactiver/activer les checkboxes au-dela de 5
        checkboxes.forEach(function(cb) {
            if (!cb.checked && nb >= 5) {
                cb.disabled = true;
            } else {
                cb.disabled = false;
            }
        });

        // Bouton comparer
        btnComparer.disabled = (nb < 2);
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', mettreAJourSelection);
    });

    /**
     * Retourne la classe CSS pour un score
     */
    function classeScore(score) {
        if (score >= 60) return 'score-bon';
        if (score >= 40) return 'score-moyen';
        return 'score-mauvais';
    }

    /**
     * Retourne la couleur hexadecimale pour un score
     */
    function couleurScore(score) {
        if (score >= 60) return '#198754';
        if (score >= 40) return '#fbb03b';
        return '#dc3545';
    }

    /**
     * Genere les score cards cote a cote
     */
    function genererScoreCards(donnees) {
        const container = document.getElementById('scoreCards');
        if (!container) return;

        const colClass = donnees.length <= 3 ? 'col-md-4' : (donnees.length === 4 ? 'col-md-3' : 'col-md');
        container.innerHTML = donnees.map(function(m, i) {
            const score = m.score !== null ? Math.round(m.score) : '—';
            const jaugeClass = m.score !== null ? classeScore(m.score) : '';
            return '<div class="' + colClass + '">' +
                '<div class="card text-center h-100">' +
                '<div class="card-body">' +
                '<div class="score-jauge ' + jaugeClass + '">' + score + '</div>' +
                '<h6 class="fw-bold mb-1" style="color:' + COULEURS_MARQUES[i % COULEURS_MARQUES.length] + '">' +
                    escHtml(m.nom) + '</h6>' +
                '<small class="text-muted">' + (m.volume || 0) + ' publications</small>' +
                '</div></div></div>';
        }).join('');
    }

    /**
     * Graphique en barres des scores
     */
    function genererGraphiqueScores(donnees) {
        if (graphiqueScoresInstance) graphiqueScoresInstance.destroy();

        const ctx = document.getElementById('graphiqueScores');
        if (!ctx) return;

        graphiqueScoresInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: donnees.map(function(m) { return m.nom; }),
                datasets: [{
                    label: 'Score de reputation',
                    data: donnees.map(function(m) { return m.score; }),
                    backgroundColor: donnees.map(function(m, i) { return COULEURS_MARQUES[i % COULEURS_MARQUES.length]; }),
                    borderRadius: 6,
                    maxBarThickness: 60
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score /100' } }
                }
            }
        });
    }

    /**
     * Donuts de sentiments cote a cote
     */
    function genererDonutsSentiments(donnees) {
        const container = document.getElementById('donutsSentiments');
        if (!container) return;

        // Detruire les anciens graphiques
        graphiquesSentimentInstances.forEach(function(c) { c.destroy(); });
        graphiquesSentimentInstances.length = 0;

        const colClass = donnees.length <= 3 ? 'col-md-4' : (donnees.length === 4 ? 'col-md-3' : 'col-md');
        container.innerHTML = donnees.map(function(m, i) {
            return '<div class="' + colClass + ' text-center">' +
                '<h6 class="fw-bold mb-2" style="color:' + COULEURS_MARQUES[i % COULEURS_MARQUES.length] + '">' +
                    escHtml(m.nom) + '</h6>' +
                '<div class="graphique-sentiment-container">' +
                '<canvas id="donutSentiment' + i + '" height="200"></canvas>' +
                '</div></div>';
        }).join('');

        donnees.forEach(function(m, i) {
            const ctx = document.getElementById('donutSentiment' + i);
            if (!ctx) return;

            const sent = m.sentiments || { positif: 0, neutre: 0, negatif: 0 };
            const chart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Positif', 'Neutre', 'Negatif'],
                    datasets: [{
                        data: [sent.positif || 0, sent.neutre || 0, sent.negatif || 0],
                        backgroundColor: ['#198754', '#6c757d', '#dc3545'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                    },
                    cutout: '55%'
                }
            });
            graphiquesSentimentInstances.push(chart);
        });
    }

    /**
     * Tableau comparatif des sujets
     */
    function genererTableauSujets(donnees) {
        const entete = document.getElementById('enteteSujets');
        const corps = document.getElementById('corpsSujets');
        if (!entete || !corps) return;

        // Collecter tous les sujets uniques
        const sujetsSet = {};
        donnees.forEach(function(m) {
            if (m.sujets) {
                m.sujets.forEach(function(s) { sujetsSet[s.nom] = true; });
            }
        });
        const sujets = Object.keys(sujetsSet).sort();

        // En-tete
        entete.innerHTML = '<tr><th>Sujet</th>' +
            donnees.map(function(m, i) {
                return '<th style="color:' + COULEURS_MARQUES[i % COULEURS_MARQUES.length] + '">' + escHtml(m.nom) + '</th>';
            }).join('') + '</tr>';

        // Corps
        if (sujets.length === 0) {
            corps.innerHTML = '<tr><td colspan="' + (donnees.length + 1) + '" class="text-center text-muted py-3">Aucun sujet disponible</td></tr>';
            return;
        }

        corps.innerHTML = sujets.map(function(sujet) {
            return '<tr><td class="fw-semibold">' + escHtml(sujet) + '</td>' +
                donnees.map(function(m) {
                    const found = (m.sujets || []).find(function(s) { return s.nom === sujet; });
                    if (!found) return '<td class="text-muted">—</td>';
                    const sentiment = found.sentiment || 'neutre';
                    let icone = '<i class="bi bi-dash-circle text-secondary"></i>';
                    if (sentiment === 'positif') icone = '<i class="bi bi-emoji-smile text-success"></i>';
                    else if (sentiment === 'negatif') icone = '<i class="bi bi-emoji-frown text-danger"></i>';
                    return '<td>' + icone + ' <small>' + escHtml(sentiment) + '</small></td>';
                }).join('') + '</tr>';
        }).join('');
    }

    /**
     * Graphique d'evolution temporelle multi-lignes
     */
    function genererGraphiqueEvolution(donnees) {
        if (graphiqueEvolutionInstance) graphiqueEvolutionInstance.destroy();

        const ctx = document.getElementById('graphiqueEvolution');
        if (!ctx) return;

        // Collecter toutes les dates uniques
        const datesSet = {};
        donnees.forEach(function(m) {
            if (m.evolution) {
                m.evolution.forEach(function(point) { datesSet[point.date] = true; });
            }
        });
        const dates = Object.keys(datesSet).sort();

        if (dates.length === 0) {
            ctx.parentNode.innerHTML = '<p class="text-center text-muted py-3">Aucune donnee temporelle disponible.</p>';
            return;
        }

        const datasets = donnees.map(function(m, i) {
            const couleur = COULEURS_MARQUES[i % COULEURS_MARQUES.length];
            const dataMap = {};
            if (m.evolution) {
                m.evolution.forEach(function(point) { dataMap[point.date] = point.score; });
            }
            return {
                label: m.nom,
                data: dates.map(function(d) { return dataMap[d] !== undefined ? dataMap[d] : null; }),
                borderColor: couleur,
                backgroundColor: couleur + '22',
                tension: 0.3,
                fill: false,
                spanGaps: true,
                pointRadius: 4,
                pointHoverRadius: 6
            };
        });

        graphiqueEvolutionInstance = new Chart(ctx, {
            type: 'line',
            data: { labels: dates, datasets: datasets },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score /100' } },
                    x: { title: { display: true, text: 'Date' } }
                }
            }
        });
    }

    /**
     * Tableau recapitulatif
     */
    function genererTableauRecap(donnees) {
        const entete = document.getElementById('enteteRecap');
        const corps = document.getElementById('corpsRecap');
        if (!entete || !corps) return;

        entete.innerHTML = '<tr><th>Indicateur</th>' +
            donnees.map(function(m, i) {
                return '<th style="color:' + COULEURS_MARQUES[i % COULEURS_MARQUES.length] + '">' + escHtml(m.nom) + '</th>';
            }).join('') + '</tr>';

        const lignes = [
            {
                label: 'Score',
                valeurs: donnees.map(function(m) { return m.score !== null ? Math.round(m.score) : null; }),
                format: function(v) { return v !== null ? v + '/100' : '—'; },
                meilleur: 'max'
            },
            {
                label: 'Volume',
                valeurs: donnees.map(function(m) { return m.volume || 0; }),
                format: function(v) { return v.toLocaleString('fr-FR'); },
                meilleur: 'max'
            },
            {
                label: '% Positif',
                valeurs: donnees.map(function(m) {
                    var s = m.sentiments || {};
                    var total = (s.positif || 0) + (s.neutre || 0) + (s.negatif || 0);
                    return total > 0 ? Math.round((s.positif || 0) / total * 100) : 0;
                }),
                format: function(v) { return v + ' %'; },
                meilleur: 'max'
            },
            {
                label: '% Negatif',
                valeurs: donnees.map(function(m) {
                    var s = m.sentiments || {};
                    var total = (s.positif || 0) + (s.neutre || 0) + (s.negatif || 0);
                    return total > 0 ? Math.round((s.negatif || 0) / total * 100) : 0;
                }),
                format: function(v) { return v + ' %'; },
                meilleur: 'min'
            },
            {
                label: 'Top subreddit',
                valeurs: donnees.map(function(m) { return m.top_subreddit || '—'; }),
                format: function(v) { return escHtml(v); },
                meilleur: null
            },
            {
                label: 'Top sujet',
                valeurs: donnees.map(function(m) { return m.top_sujet || '—'; }),
                format: function(v) { return escHtml(v); },
                meilleur: null
            }
        ];

        corps.innerHTML = lignes.map(function(ligne) {
            // Trouver la meilleure valeur
            let meilleurIdx = -1;
            if (ligne.meilleur) {
                let ref = ligne.meilleur === 'max' ? -Infinity : Infinity;
                ligne.valeurs.forEach(function(v, idx) {
                    if (v === null) return;
                    if (ligne.meilleur === 'max' && v > ref) { ref = v; meilleurIdx = idx; }
                    if (ligne.meilleur === 'min' && v < ref) { ref = v; meilleurIdx = idx; }
                });
            }

            return '<tr><td class="fw-semibold">' + ligne.label + '</td>' +
                ligne.valeurs.map(function(v, idx) {
                    const cls = (idx === meilleurIdx) ? ' class="meilleure-valeur"' : '';
                    return '<td' + cls + '>' + ligne.format(v) + '</td>';
                }).join('') + '</tr>';
        }).join('');
    }

    /**
     * Echappe le HTML
     */
    function escHtml(texte) {
        if (!texte) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(texte));
        return div.innerHTML;
    }

    /**
     * Lance la comparaison via l'API
     */
    function lancerComparaison() {
        const selectionnees = document.querySelectorAll('.cb-marque:checked');
        const ids = Array.from(selectionnees).map(function(cb) { return cb.value; });

        if (ids.length < 2) return;

        // Afficher le chargement
        if (chargement) chargement.style.display = '';
        if (resultats) resultats.style.display = 'none';

        fetch('api/comparaison.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ marque_ids: ids })
        })
        .then(function(response) { return response.json(); })
        .then(function(json) {
            if (chargement) chargement.style.display = 'none';

            if (!json.donnees || !Array.isArray(json.donnees) || json.donnees.length < 2) {
                if (resultats) resultats.style.display = 'none';
                alert(json.message || 'Erreur lors de la comparaison.');
                return;
            }

            if (resultats) resultats.style.display = '';

            const donnees = json.donnees;
            genererScoreCards(donnees);
            genererGraphiqueScores(donnees);
            genererDonutsSentiments(donnees);
            genererTableauSujets(donnees);
            genererGraphiqueEvolution(donnees);
            genererTableauRecap(donnees);

            // Scroll vers les resultats
            resultats.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })
        .catch(function(erreur) {
            if (chargement) chargement.style.display = 'none';
            console.error('Erreur comparaison:', erreur);
            alert('Erreur lors de la comparaison. Veuillez reessayer.');
        });
    }

    if (btnComparer) {
        btnComparer.addEventListener('click', lancerComparaison);
    }
})();
</script>
</body>
</html>
