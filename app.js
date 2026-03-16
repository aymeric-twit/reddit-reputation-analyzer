/**
 * Reddit Reputation Analyzer — Frontend principal
 *
 * Plugin embarque pour seo-platform.
 * Gere trois pages : dashboard (index.php), resultats (resultats.php), comparaison (comparaison.php).
 */

/* ==========================================================================
   UTILITAIRES GLOBAUX
   ========================================================================== */

const BASE_URL = window.MODULE_BASE_URL || '.';

/**
 * Wrapper autour de fetch avec gestion d'erreurs.
 *
 * @param {string} endpoint  Chemin relatif (ex: '/api/marques.php')
 * @param {object} options   Options fetch supplementaires
 * @returns {Promise<object>} Reponse JSON parsee
 */
async function appelerApi(endpoint, options = {}) {
    const url = BASE_URL + endpoint;

    const headers = {
        'Accept': 'application/json',
        ...(options.headers || {}),
    };

    if (options.method === 'POST' && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    if (options.method === 'POST') {
        headers['X-Requested-With'] = 'XMLHttpRequest';
    }

    try {
        const reponse = await fetch(url, {
            ...options,
            headers,
        });

        if (!reponse.ok) {
            const texteErreur = await reponse.text();
            let messageErreur;
            try {
                const jsonErreur = JSON.parse(texteErreur);
                messageErreur = jsonErreur.message || jsonErreur.erreur || `Erreur HTTP ${reponse.status}`;
            } catch {
                messageErreur = `Erreur HTTP ${reponse.status}`;
            }
            throw new Error(messageErreur);
        }

        return await reponse.json();
    } catch (erreur) {
        if (erreur.name === 'TypeError' && erreur.message === 'Failed to fetch') {
            throw new Error('Impossible de contacter le serveur. Verifiez votre connexion.');
        }
        throw erreur;
    }
}

/**
 * Formate un score en objet {valeur, classe, label}.
 *
 * @param {number} score Score entre 0 et 100
 * @returns {{valeur: number, classe: string, label: string}}
 */
function formaterScore(score) {
    const valeur = Math.round(score);

    if (valeur < 30) {
        return { valeur, classe: 'score-low', label: 'Mauvaise' };
    }
    if (valeur < 50) {
        return { valeur, classe: 'score-mid', label: 'Faible' };
    }
    if (valeur < 65) {
        return { valeur, classe: 'score-mid', label: 'Moyenne' };
    }
    if (valeur < 80) {
        return { valeur, classe: 'score-high', label: 'Bonne' };
    }
    return { valeur, classe: 'score-high', label: 'Excellente' };
}

/**
 * Formate une date ISO en format francais lisible.
 *
 * @param {string} dateStr Date au format ISO (YYYY-MM-DD ou ISO 8601)
 * @returns {string} Date formatee
 */
function formaterDate(dateStr) {
    if (!dateStr) return '—';
    try {
        const date = new Date(dateStr);
        return date.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return dateStr;
    }
}

/**
 * Genere un SVG de jauge circulaire pour afficher un score.
 *
 * @param {number} score  Score entre 0 et 100
 * @param {number} taille Diametre du SVG en pixels
 * @returns {string} Chaine SVG
 */
function creerJaugeSvg(score, taille = 100) {
    const rayon = (taille / 2) - 8;
    const circonference = 2 * Math.PI * rayon;
    const progression = (score / 100) * circonference;
    const centre = taille / 2;

    const info = formaterScore(score);
    let couleur;
    if (info.classe === 'score-low') {
        couleur = '#ef4444';
    } else if (info.classe === 'score-mid') {
        couleur = '#fbb03b';
    } else {
        couleur = '#22c55e';
    }

    return `
        <svg width="${taille}" height="${taille}" viewBox="0 0 ${taille} ${taille}">
            <circle
                cx="${centre}" cy="${centre}" r="${rayon}"
                fill="none" stroke="#e5e7eb" stroke-width="6"
            />
            <circle
                cx="${centre}" cy="${centre}" r="${rayon}"
                fill="none" stroke="${couleur}" stroke-width="6"
                stroke-linecap="round"
                stroke-dasharray="${progression} ${circonference - progression}"
                transform="rotate(-90 ${centre} ${centre})"
                style="transition: stroke-dasharray 0.6s ease;"
            />
            <text
                x="${centre}" y="${centre}" text-anchor="middle" dominant-baseline="central"
                font-family="Poppins, sans-serif" font-size="${taille * 0.28}" font-weight="700"
                fill="${couleur}"
            >${info.valeur}</text>
        </svg>
    `;
}

/**
 * Affiche un toast / alerte temporaire.
 *
 * @param {string} message  Texte a afficher
 * @param {string} type     'success' | 'danger' | 'warning' | 'info'
 */
function afficherToast(message, type = 'success') {
    let conteneur = document.getElementById('conteneurToasts');
    if (!conteneur) {
        conteneur = document.createElement('div');
        conteneur.id = 'conteneurToasts';
        conteneur.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:0.5rem;';
        document.body.appendChild(conteneur);
    }

    const toast = document.createElement('div');
    toast.className = `alert alert-${type} alert-dismissible fade show mb-0`;
    toast.setAttribute('role', 'alert');
    toast.style.cssText = 'min-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    `;
    conteneur.appendChild(toast);

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

/**
 * Couleurs de la charte graphique pour les graphiques Chart.js.
 */
const COULEURS_GRAPHIQUES = {
    dark: '#004c4c',
    teal: '#66b2b2',
    gold: '#fbb03b',
    vert: '#22c55e',
    rouge: '#ef4444',
    gris: '#9ca3af',
    serie: ['#004c4c', '#66b2b2', '#fbb03b', '#22c55e', '#ef4444', '#8b5cf6', '#f59e0b', '#06b6d4'],
};

/**
 * Charge Chart.js dynamiquement si absent (supprime par la plateforme en mode embedded).
 *
 * @returns {Promise<void>}
 */
let _chartJsPromise = null;
function chargerChartJs() {
    if (typeof Chart !== 'undefined') return Promise.resolve();
    if (_chartJsPromise) return _chartJsPromise;

    _chartJsPromise = new Promise(function(resolve, reject) {
        const script = document.createElement('script');
        // Fichier local UMD (le CDN est supprime par la plateforme en embedded)
        const base = window.MODULE_BASE_URL || '.';
        script.src = base + '/assets/js/chart.umd.min.js';
        script.onload = resolve;
        script.onerror = function() {
            // Fallback CDN si le fichier local echoue
            const fallback = document.createElement('script');
            fallback.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
            fallback.onload = resolve;
            fallback.onerror = function() { reject(new Error('Impossible de charger Chart.js')); };
            document.head.appendChild(fallback);
        };
        document.head.appendChild(script);
    });
    return _chartJsPromise;
}

/**
 * Configuration globale Chart.js.
 */
function configurerChartJs() {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = 'Poppins, system-ui, -apple-system, sans-serif';
    Chart.defaults.font.size = 13;
    Chart.defaults.color = '#333333';
    Chart.defaults.plugins.tooltip.enabled = true;
    Chart.defaults.plugins.legend.position = 'top';
    Chart.defaults.responsive = true;
}

/**
 * Detruit un graphique Chart.js existant sur un canvas.
 *
 * @param {string} canvasId Identifiant du canvas
 */
function detruireGraphique(canvasId) {
    const canvas = document.getElementById(canvasId);
    if (canvas && canvas._chartInstance) {
        canvas._chartInstance.destroy();
        canvas._chartInstance = null;
    }
    // Approche via Chart.js registry
    if (typeof Chart !== 'undefined') {
        const existing = Chart.getChart(canvasId);
        if (existing) existing.destroy();
    }
}

/* ==========================================================================
   PAGE DASHBOARD (index.php)
   ========================================================================== */

/**
 * Initialise la page dashboard.
 */
function initDashboard() {
    chargerMarques();
    chargerHistorique();

    document.querySelectorAll('[data-vue]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            changerVue(btn.dataset.vue);
        });
    });
}

/**
 * Change la vue visible du dashboard.
 *
 * @param {string} nomVue Nom de la vue a afficher
 */
function changerVue(nomVue) {
    const vues = ['dashboard', 'nouvelle-analyse', 'historique', 'parametres'];
    vues.forEach(vue => {
        const el = document.getElementById(`vue-${vue}`);
        if (el) {
            el.style.display = (vue === nomVue) ? '' : 'none';
        }
    });

    document.querySelectorAll('[data-vue]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.vue === nomVue);
    });

    // Charger les donnees de la vue si necessaire
    if (nomVue === 'historique') {
        chargerHistorique();
    } else if (nomVue === 'parametres') {
        chargerParametres();
    }
}

/**
 * Charge la liste des marques et met a jour le tableau de bord.
 */
async function chargerMarques() {
    const grille = document.getElementById('grille-marques');
    const etatVide = document.getElementById('etat-vide');
    if (!grille) return;

    try {
        const reponse = await appelerApi('/api/marques.php');
        const marques = reponse.donnees || reponse || [];

        if (!Array.isArray(marques) || marques.length === 0) {
            grille.innerHTML = '';
            if (etatVide) etatVide.style.display = '';
            mettreAJourKpis([]);
            return;
        }

        if (etatVide) etatVide.style.display = 'none';

        grille.innerHTML = marques.map(m => renderCarteMarque(m)).join('');

        // Evenements sur les boutons "Relancer"
        grille.querySelectorAll('[data-relancer]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const marqueId = btn.dataset.relancer;
                await relancerAnalyse(marqueId);
            });
        });

        mettreAJourKpis(marques);
    } catch (erreur) {
        // En cas d'erreur (serveur eteint, base vide...), afficher l'etat vide
        grille.innerHTML = '';
        if (etatVide) etatVide.style.display = '';
        mettreAJourKpis([]);
        console.warn('Chargement marques:', erreur.message);
    }
}

/**
 * Met a jour les KPIs du tableau de bord.
 *
 * @param {Array} marques Liste des marques
 */
function mettreAJourKpis(marques) {
    const conteneur = document.getElementById('kpiDashboard');
    if (!conteneur) return;

    const nbMarques = marques.length;

    let scoreMoyen = 0;
    const marquesAvecScore = marques.filter(m => (m.score || m.score_reputation) != null && (m.score || m.score_reputation) > 0);
    if (marquesAvecScore.length > 0) {
        scoreMoyen = marquesAvecScore.reduce((acc, m) => acc + (m.score || m.score_reputation || 0), 0) / marquesAvecScore.length;
    }
    const infoScore = formaterScore(scoreMoyen);

    const maintenant = new Date();
    const moisCourant = maintenant.getMonth();
    const anneeCourante = maintenant.getFullYear();
    const analysesCeMois = marques.filter(m => {
        if (!m.derniere_analyse) return false;
        const d = new Date(m.derniere_analyse);
        return d.getMonth() === moisCourant && d.getFullYear() === anneeCourante;
    }).length;

    const alertes = marques.filter(m => (m.score || 0) > 0 && (m.score || 0) < 40).length;

    conteneur.innerHTML = `
        <div class="kpi-card">
            <div class="kpi-icone"><i class="bi bi-bookmark-star"></i></div>
            <div class="kpi-valeur">${nbMarques}</div>
            <div class="kpi-label">Marques suivies</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icone"><i class="bi bi-speedometer2"></i></div>
            <div class="kpi-valeur ${infoScore.classe}">${nbMarques > 0 ? Math.round(scoreMoyen) : '—'}</div>
            <div class="kpi-label">Score moyen</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icone"><i class="bi bi-graph-up"></i></div>
            <div class="kpi-valeur">${analysesCeMois}</div>
            <div class="kpi-label">Analyses ce mois</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icone"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="kpi-valeur ${alertes > 0 ? 'score-low' : ''}">${alertes}</div>
            <div class="kpi-label">Alertes</div>
        </div>
    `;
}

/**
 * Genere le HTML d'une carte marque pour le dashboard.
 *
 * @param {object} marque Objet marque
 * @returns {string} HTML de la carte
 */
function renderCarteMarque(marque) {
    const score = marque.score_reputation || marque.score || 0;
    const info = formaterScore(score);

    let couleurBordure;
    if (info.classe === 'score-low') {
        couleurBordure = '#ef4444';
    } else if (info.classe === 'score-mid') {
        couleurBordure = '#fbb03b';
    } else {
        couleurBordure = '#22c55e';
    }

    let flecheTendance = '';
    if (marque.tendance === 'hausse') {
        flecheTendance = '<span class="text-success" title="En hausse"><i class="bi bi-arrow-up-circle-fill"></i></span>';
    } else if (marque.tendance === 'baisse') {
        flecheTendance = '<span class="text-danger" title="En baisse"><i class="bi bi-arrow-down-circle-fill"></i></span>';
    } else {
        flecheTendance = '<span class="text-muted" title="Stable"><i class="bi bi-dash-circle"></i></span>';
    }

    const derniereAnalyse = marque.date_derniere_analyse || marque.derniere_analyse
        ? formaterDate(marque.date_derniere_analyse || marque.derniere_analyse)
        : 'Aucune analyse';

    const analyseId = marque.derniere_analyse_id || marque.id_derniere_analyse;
    const lienResultats = analyseId
        ? `${BASE_URL}/resultats.php?analyse_id=${analyseId}`
        : '#';

    const boutonVoir = analyseId
        ? `<a href="${lienResultats}" class="btn btn-sm btn-primary">Voir resultats</a>`
        : `<button class="btn btn-sm btn-primary" disabled>Aucun resultat</button>`;

    return `
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100" style="border-left: 4px solid ${couleurBordure};">
                <div class="card-body d-flex align-items-start gap-3">
                    <div class="flex-shrink-0">
                        ${creerJaugeSvg(score, 80)}
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h5 class="card-title mb-0">${echapper(marque.nom)}</h5>
                            ${flecheTendance}
                        </div>
                        <p class="text-muted small mb-2">
                            <i class="bi bi-clock"></i> ${derniereAnalyse}
                        </p>
                        <span class="badge bg-light text-dark mb-2">${info.label}</span>
                        <div class="d-flex gap-2 mt-auto">
                            ${boutonVoir}
                            <button class="btn btn-sm btn-outline-secondary" data-relancer="${marque.id}" title="Relancer l'analyse">
                                <i class="bi bi-arrow-repeat"></i> Relancer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Relance une analyse pour une marque existante.
 *
 * @param {string|number} marqueId Identifiant de la marque
 */
async function relancerAnalyse(marqueId) {
    try {
        const formData = new FormData();
        formData.append('marque_id', marqueId);

        const reponse = await appelerApi('/api/lancer-analyse.php', {
            method: 'POST',
            body: formData,
        });

        if (reponse.succes) {
            demarrerSuiviProgression(reponse.job_id, reponse.analyse_id);
        } else {
            afficherToast(reponse.message || 'Erreur lors du lancement', 'danger');
        }
    } catch (erreur) {
        afficherToast(erreur.message, 'danger');
    }
}

/**
 * Echappe les caracteres HTML pour eviter les injections XSS.
 *
 * @param {string} texte Texte a echapper
 * @returns {string} Texte echappe
 */
function echapper(texte) {
    if (!texte) return '';
    const div = document.createElement('div');
    div.textContent = texte;
    return div.innerHTML;
}

/* ==========================================================================
   FORMULAIRE NOUVELLE ANALYSE
   ========================================================================== */

/**
 * Initialise le formulaire de nouvelle analyse.
 */
function initFormulaireAnalyse() {
    const formulaire = document.getElementById('formulaireAnalyse');
    if (!formulaire) return;

    const blocNavigateur = document.getElementById('blocNavigateur');
    const blocBtnLancer = document.getElementById('blocBtnLancer');
    const btnCollerDonnees = document.getElementById('btnCollerDonnees');
    const btnPageSuivante = document.getElementById('btnPageSuivante');
    const btnLancerNavigateur = document.getElementById('btnLancerNavigateur');
    const apercuCollage = document.getElementById('apercuCollage');

    // Etat d'accumulation multi-collages
    const collecteNav = {
        /** @type {Array} Posts accumules (objets Reddit data.children[].data) */
        posts: [],
        /** @type {Set<string>} IDs Reddit deja vus (deduplication) */
        idsVus: new Set(),
        /** @type {string|null} Token de pagination (after) du dernier collage */
        afterToken: null,
        /** @type {string|null} Dernier tri utilise */
        dernierTri: null,
    };

    // --- Toggle mode de collecte ---
    document.querySelectorAll('[name="mode_collecte"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const estManuel = radio.value === 'navigateur' && radio.checked;
            if (blocNavigateur) blocNavigateur.classList.toggle('d-none', !estManuel);
            if (blocBtnLancer) blocBtnLancer.classList.toggle('d-none', estManuel);
        });
    });

    /**
     * Construit l'URL Reddit JSON pour un tri donne.
     */
    function construireUrlReddit(tri, afterToken) {
        const marque = document.getElementById('marque').value.trim();
        const periode = document.getElementById('periode').value;
        let url = 'https://old.reddit.com/search.json?q='
            + encodeURIComponent('"' + marque + '"')
            + '&sort=' + encodeURIComponent(tri)
            + '&t=' + encodeURIComponent(periode)
            + '&limit=100&type=link&raw_json=1';
        if (afterToken) {
            url += '&after=' + encodeURIComponent(afterToken);
        }
        return url;
    }

    /**
     * Met a jour l'apercu avec le nombre de posts accumules.
     */
    function majApercu() {
        if (!apercuCollage) return;
        const n = collecteNav.posts.length;
        apercuCollage.innerHTML = '<div class="alert alert-success py-2 mb-0">'
            + '<i class="bi bi-check-circle me-1"></i> '
            + '<strong>' + n + '</strong> posts uniques accumules'
            + '</div>';
        apercuCollage.classList.remove('d-none');

        // Afficher le bouton lancer
        if (btnLancerNavigateur && n > 0) {
            btnLancerNavigateur.classList.remove('d-none');
            btnLancerNavigateur.innerHTML = '<i class="bi bi-play-fill me-1"></i> Lancer l\'analyse (' + n + ' posts)';
        }
    }

    // --- Boutons de recherche par tri ---
    document.querySelectorAll('.btn-reddit-sort').forEach(btn => {
        btn.addEventListener('click', () => {
            const marque = document.getElementById('marque').value.trim();
            if (!marque) {
                afficherToast('Veuillez saisir un nom de marque.', 'warning');
                return;
            }
            const tri = btn.dataset.sort;
            collecteNav.dernierTri = tri;
            collecteNav.afterToken = null;
            const url = construireUrlReddit(tri, null);
            window.open(url, '_blank');
            if (btnCollerDonnees) btnCollerDonnees.disabled = false;
        });
    });

    // --- Bouton page suivante ---
    if (btnPageSuivante) {
        btnPageSuivante.addEventListener('click', () => {
            if (!collecteNav.afterToken || !collecteNav.dernierTri) return;
            const url = construireUrlReddit(collecteNav.dernierTri, collecteNav.afterToken);
            window.open(url, '_blank');
            if (btnCollerDonnees) btnCollerDonnees.disabled = false;
        });
    }

    // --- Coller et accumuler les donnees ---
    if (btnCollerDonnees) {
        btnCollerDonnees.addEventListener('click', async () => {
            try {
                const texte = await navigator.clipboard.readText();
                const donnees = JSON.parse(texte);
                const enfants = donnees?.data?.children ?? [];
                if (enfants.length === 0) {
                    throw new Error('Aucun post trouve dans le JSON');
                }

                // Accumuler avec deduplication
                let nouveaux = 0;
                for (const enfant of enfants) {
                    const id = enfant.data?.name || enfant.data?.id || '';
                    if (id && !collecteNav.idsVus.has(id)) {
                        collecteNav.idsVus.add(id);
                        collecteNav.posts.push(enfant);
                        nouveaux++;
                    }
                }

                // Stocker le token de pagination pour la page suivante
                collecteNav.afterToken = donnees.data?.after || null;

                // Afficher/masquer le bouton page suivante
                if (btnPageSuivante) {
                    btnPageSuivante.classList.toggle('d-none', !collecteNav.afterToken);
                    if (collecteNav.afterToken) {
                        btnPageSuivante.innerHTML = '<i class="bi bi-arrow-right me-1"></i> Page suivante (' + collecteNav.dernierTri + ')';
                    }
                }

                afficherToast(nouveaux + ' nouveaux posts ajoutes (' + collecteNav.posts.length + ' total)', 'success');
                majApercu();
            } catch (erreur) {
                afficherToast('JSON invalide ou presse-papier vide : ' + erreur.message, 'danger');
            }
        });
    }

    // --- Lancer l'analyse avec les donnees accumulees ---
    if (btnLancerNavigateur) {
        btnLancerNavigateur.addEventListener('click', async () => {
            if (collecteNav.posts.length === 0) {
                afficherToast('Aucun post a analyser.', 'warning');
                return;
            }

            // Reconstituer la structure Reddit attendue par le worker
            const donneesReddit = JSON.stringify({
                data: { children: collecteNav.posts }
            });

            const formData = new FormData(formulaire);
            formData.set('mode_collecte', 'navigateur');
            formData.append('donnees_reddit', donneesReddit);

            btnLancerNavigateur.disabled = true;
            btnLancerNavigateur.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Lancement...';

            try {
                await lancerAnalyse(formData);
            } catch (erreur) {
                afficherToast(erreur.message, 'danger');
                btnLancerNavigateur.disabled = false;
                btnLancerNavigateur.innerHTML = '<i class="bi bi-play-fill me-1"></i> Lancer l\'analyse (' + collecteNav.posts.length + ' posts)';
            }
        });
    }

    // --- Soumission classique (mode SerpAPI) ---
    formulaire.addEventListener('submit', async (e) => {
        e.preventDefault();

        const boutonSubmit = formulaire.querySelector('button[type="submit"]');
        if (boutonSubmit) {
            boutonSubmit.disabled = true;
            boutonSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Lancement...';
        }

        try {
            const formData = new FormData(formulaire);
            await lancerAnalyse(formData);
        } catch (erreur) {
            afficherToast(erreur.message, 'danger');
            if (boutonSubmit) {
                boutonSubmit.disabled = false;
                boutonSubmit.innerHTML = '<i class="bi bi-play-fill"></i> Lancer l\'analyse';
            }
        }
    });
}

/**
 * Lance une analyse via l'API.
 *
 * @param {FormData} donnees Donnees du formulaire
 */
async function lancerAnalyse(donnees) {
    collapserHelpPanel();
    const reponse = await appelerApi('/api/lancer-analyse.php', {
        method: 'POST',
        body: donnees,
    });

    if (reponse.succes) {
        demarrerSuiviProgression(reponse.job_id, reponse.analyse_id);
    } else {
        throw new Error(reponse.message || 'Erreur lors du lancement de l\'analyse');
    }
}

/**
 * Demarre le suivi de progression d'une analyse.
 *
 * @param {string} jobId     Identifiant du job
 * @param {string} analyseId Identifiant de l'analyse
 */
function demarrerSuiviProgression(jobId, analyseId) {
    // Masquer TOUTES les vues
    document.querySelectorAll('[id^="vue-"]').forEach(v => v.style.display = 'none');

    // Afficher la zone de progression
    const zone = document.getElementById('zoneProgression');
    if (zone) {
        zone.classList.remove('d-none');
    }

    // Desactiver les onglets de navigation
    document.querySelectorAll('#navigationPrincipale .nav-link').forEach(l => {
        l.classList.remove('active');
        l.classList.add('disabled');
    });

    // Reinitialiser les elements
    const livelog = document.getElementById('livelog');
    if (livelog) livelog.textContent = '';
    let nbLignesLog = 0;

    const barreProgression = document.getElementById('barreProgression');
    const statusMsg = document.getElementById('statusMsg');
    const labelEtape = document.getElementById('etapeAnalyse');
    const livelogCompteur = document.getElementById('livelog-compteur');
    const kpiPosts = document.getElementById('kpiProgressPosts');
    const kpiComments = document.getElementById('kpiProgressComments');
    const kpiPourcent = document.getElementById('kpiProgressPourcent');
    const kpiDuree = document.getElementById('kpiProgressDuree');
    let logOffset = 0;
    const tempsDebut = Date.now();

    // Status initial
    if (statusMsg) {
        statusMsg.className = 'status-msg mb-4 status-loading';
    }

    // Toggle journal
    const btnToggle = document.getElementById('btnToggleJournal');
    const corpsJournal = document.getElementById('corpsJournal');
    if (btnToggle && corpsJournal) {
        btnToggle.onclick = () => {
            const estCache = corpsJournal.style.display === 'none';
            corpsJournal.style.display = estCache ? '' : 'none';
            btnToggle.querySelector('i').className = estCache ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        };
    }

    // Compteur duree
    const intervalDuree = setInterval(() => {
        if (kpiDuree) {
            const sec = Math.floor((Date.now() - tempsDebut) / 1000);
            kpiDuree.textContent = sec < 60 ? sec + 's' : Math.floor(sec / 60) + 'min ' + (sec % 60) + 's';
        }
    }, 1000);

    const intervalle = setInterval(async () => {
        try {
            const prog = await appelerApi(`/progress.php?job_id=${jobId}&log_offset=${logOffset}`);

            // Barre de progression
            const pourcentage = prog.pourcentage || 0;
            if (barreProgression) {
                barreProgression.style.width = `${pourcentage}%`;
                barreProgression.setAttribute('aria-valuenow', pourcentage);
            }
            if (kpiPourcent) {
                kpiPourcent.textContent = `${pourcentage} %`;
            }

            // Etape
            if (labelEtape && prog.etape) {
                labelEtape.textContent = prog.etape;
            }

            // Extraire les compteurs des details
            if (prog.details) {
                const matchPub = prog.details.match(/(\d+)\s*\/\s*\d+\s*publication/);
                if (matchPub && kpiPosts) kpiPosts.textContent = matchPub[1];

                const matchComm = prog.details.match(/(\d+)\s*\/\s*\d+\s*publications?\s*traitee/);
                if (matchComm && kpiComments) kpiComments.textContent = matchComm[1];
            }

            // Nouvelles lignes de log
            if (prog.log && prog.log.length > 0 && livelog) {
                for (const ligne of prog.log) {
                    const classeSpeciale = ligne.niveau === 'success' ? ' log-ok'
                        : ligne.niveau === 'warning' ? ' log-warn'
                        : ligne.niveau === 'error' ? ' log-error'
                        : '';

                    livelog.innerHTML += '<span class="log-time">[' + echapper(ligne.ts || '') + ']</span>'
                        + '<span class="' + classeSpeciale + '"> ' + echapper(ligne.message || '') + '</span>\n';
                }
                nbLignesLog += prog.log.length;
                livelog.scrollTop = livelog.scrollHeight;

                if (livelogCompteur) {
                    livelogCompteur.textContent = `${nbLignesLog} ligne${nbLignesLog > 1 ? 's' : ''}`;
                }

                // Mettre a jour les KPI depuis le log
                for (const ligne of prog.log) {
                    const msg = ligne.message || '';
                    const matchPubLog = msg.match(/^(\d+) publications? collectees/);
                    if (matchPubLog && kpiPosts) kpiPosts.textContent = matchPubLog[1];

                    const matchCommLog = msg.match(/^(\d+) commentaires collectes/);
                    if (matchCommLog && kpiComments) kpiComments.textContent = matchCommLog[1];
                }
            }

            if (prog.log_offset !== undefined) {
                logOffset = prog.log_offset;
            }

            // Termine
            if (prog.statut === 'termine') {
                clearInterval(intervalle);
                clearInterval(intervalDuree);

                if (barreProgression) {
                    barreProgression.style.width = '100%';
                    barreProgression.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    barreProgression.style.backgroundColor = '#16a34a';
                }
                if (kpiPourcent) kpiPourcent.textContent = '100 %';
                if (statusMsg) {
                    const urlResultats = BASE_URL + '/resultats.php?analyse_id=' + (prog.analyse_id || analyseId);
                    statusMsg.className = 'status-msg mb-4 status-success';
                    statusMsg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Analyse terminee ! '
                        + '<a href="' + urlResultats + '" class="btn btn-sm btn-success ms-3"><i class="bi bi-arrow-right me-1"></i> Voir les resultats</a>';
                }

                // Reactiver la navigation
                document.querySelectorAll('#navigationPrincipale .nav-link').forEach(l => l.classList.remove('disabled'));
            }

            // Erreur
            if (prog.statut === 'erreur') {
                clearInterval(intervalle);
                clearInterval(intervalDuree);

                if (barreProgression) {
                    barreProgression.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    barreProgression.style.backgroundColor = '#dc2626';
                }
                if (statusMsg) {
                    statusMsg.className = 'status-msg mb-4 status-error';
                    statusMsg.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> ' + echapper(prog.details || prog.message || 'Erreur lors de l\'analyse');
                }

                // Reactiver la navigation
                document.querySelectorAll('#navigationPrincipale .nav-link').forEach(l => l.classList.remove('disabled'));
            }
        } catch (erreur) {
            console.warn('Erreur lors du suivi de progression :', erreur.message);
        }
    }, 1500);
}

/* ==========================================================================
   VUE HISTORIQUE
   ========================================================================== */

/**
 * Charge l'historique des analyses.
 *
 * @param {number} page Numero de page (pagination)
 */
async function chargerHistorique(page = 1) {
    const corps = document.getElementById('corpsHistorique');
    if (!corps) return;

    try {
        // Recuperer le filtre de marque si present
        const selectMarque = document.getElementById('filtreMarqueHistorique');
        const marqueId = selectMarque ? selectMarque.value : '';

        let url = `/api/historique.php?page=${page}`;
        if (marqueId) {
            url += `&marque_id=${marqueId}`;
        }

        const reponse = await appelerApi(url);
        const analyses = reponse.donnees || reponse || [];
        const pagination = reponse.pagination || null;

        if (!Array.isArray(analyses) || analyses.length === 0) {
            corps.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        Aucune analyse dans l'historique.
                    </td>
                </tr>
            `;
            renderPagination(null);
            return;
        }

        corps.innerHTML = analyses.map(a => renderLigneHistorique(a)).join('');

        // Evenements sur les boutons d'action
        corps.querySelectorAll('[data-supprimer]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (confirm('Supprimer cette analyse ? Cette action est irreversible.')) {
                    await supprimerAnalyse(btn.dataset.supprimer);
                    chargerHistorique(page);
                }
            });
        });

        corps.querySelectorAll('[data-relancer-historique]').forEach(btn => {
            btn.addEventListener('click', async () => {
                await relancerAnalyse(btn.dataset.relancerHistorique);
            });
        });

        renderPagination(pagination, page);
    } catch (erreur) {
        corps.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-danger py-4">
                    Erreur : ${erreur.message}
                </td>
            </tr>
        `;
    }
}

/**
 * Genere le HTML d'une ligne d'historique.
 *
 * @param {object} analyse Objet analyse
 * @returns {string} HTML de la ligne
 */
function renderLigneHistorique(analyse) {
    const score = analyse.score_reputation || analyse.score || 0;
    const info = formaterScore(score);

    let badgeStatut;
    switch (analyse.statut) {
        case 'termine':
            badgeStatut = '<span class="badge bg-success">Termine</span>';
            break;
        case 'en_cours':
            badgeStatut = '<span class="badge bg-warning text-dark">En cours</span>';
            break;
        case 'erreur':
            badgeStatut = '<span class="badge bg-danger">Erreur</span>';
            break;
        default:
            badgeStatut = `<span class="badge bg-secondary">${echapper(analyse.statut || 'Inconnu')}</span>`;
    }

    let couleurScore;
    if (info.classe === 'score-low') {
        couleurScore = 'bg-danger';
    } else if (info.classe === 'score-mid') {
        couleurScore = 'bg-warning text-dark';
    } else {
        couleurScore = 'bg-success';
    }

    const lienResultats = analyse.statut === 'termine'
        ? `<a href="${BASE_URL}/resultats.php?analyse_id=${analyse.id}" class="btn btn-sm btn-outline-primary" title="Voir"><i class="bi bi-eye"></i></a>`
        : '';

    const lienExportCsv = analyse.statut === 'termine'
        ? `<a href="${BASE_URL}/api/export.php?analyse_id=${analyse.id}&format=csv" class="btn btn-sm btn-outline-secondary" title="Exporter CSV"><i class="bi bi-download"></i></a>`
        : '';

    return `
        <tr>
            <td><strong>${echapper(analyse.marque_nom || analyse.marque || '')}</strong></td>
            <td>${formaterDate(analyse.date_lancement || analyse.date_creation || '')}</td>
            <td>${echapper(analyse.periode_debut ? analyse.periode_debut + ' → ' + (analyse.periode_fin || '') : '')}</td>
            <td><span class="badge ${couleurScore}">${info.valeur} — ${info.label}</span></td>
            <td>${analyse.nb_publications || analyse.nombre_posts || 0}</td>
            <td>${badgeStatut}</td>
            <td>
                <div class="btn-group btn-group-sm">
                    ${lienResultats}
                    <button class="btn btn-sm btn-outline-secondary" data-relancer-historique="${analyse.marque_id || ''}" title="Relancer">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    ${lienExportCsv}
                    <button class="btn btn-sm btn-outline-danger" data-supprimer="${analyse.id}" title="Supprimer">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `;
}

/**
 * Genere les controles de pagination.
 *
 * @param {object|null} pagination Objet pagination (page_courante, total_pages)
 * @param {number} pageCourante   Page courante
 */
function renderPagination(pagination, pageCourante = 1) {
    const conteneur = document.getElementById('paginationHistorique');
    if (!conteneur) return;

    if (!pagination || !pagination.total_pages || pagination.total_pages <= 1) {
        conteneur.innerHTML = '';
        return;
    }

    const totalPages = pagination.total_pages;
    let html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';

    // Bouton Precedent
    html += `
        <li class="page-item ${pageCourante <= 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page-historique="${pageCourante - 1}">&laquo;</a>
        </li>
    `;

    // Pages
    const debut = Math.max(1, pageCourante - 2);
    const fin = Math.min(totalPages, pageCourante + 2);

    if (debut > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" data-page-historique="1">1</a></li>`;
        if (debut > 2) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for (let i = debut; i <= fin; i++) {
        html += `
            <li class="page-item ${i === pageCourante ? 'active' : ''}">
                <a class="page-link" href="#" data-page-historique="${i}">${i}</a>
            </li>
        `;
    }

    if (fin < totalPages) {
        if (fin < totalPages - 1) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        html += `<li class="page-item"><a class="page-link" href="#" data-page-historique="${totalPages}">${totalPages}</a></li>`;
    }

    // Bouton Suivant
    html += `
        <li class="page-item ${pageCourante >= totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page-historique="${pageCourante + 1}">&raquo;</a>
        </li>
    `;

    html += '</ul></nav>';
    conteneur.innerHTML = html;

    // Evenements de pagination
    conteneur.querySelectorAll('[data-page-historique]').forEach(lien => {
        lien.addEventListener('click', (e) => {
            e.preventDefault();
            const p = parseInt(lien.dataset.pageHistorique, 10);
            if (p >= 1 && p <= totalPages) {
                chargerHistorique(p);
            }
        });
    });
}

/**
 * Supprime une analyse.
 *
 * @param {string|number} analyseId Identifiant de l'analyse
 */
async function supprimerAnalyse(analyseId) {
    try {
        const formData = new FormData();
        formData.append('analyse_id', analyseId);
        await appelerApi('/api/supprimer-analyse.php', {
            method: 'POST',
            body: formData,
        });
        afficherToast('Analyse supprimee.', 'success');
    } catch (erreur) {
        afficherToast('Erreur lors de la suppression : ' + erreur.message, 'danger');
    }
}

/* ==========================================================================
   VUE PARAMETRES
   ========================================================================== */

/**
 * Charge les parametres enregistres et remplit les formulaires.
 */
async function chargerParametres() {
    const formApi = document.getElementById('formulaireParametresApi');
    const formDefauts = document.getElementById('formulaireParametresDefaut');
    const formNlp = document.getElementById('formulaireParametresNlp');
    if (!formApi && !formDefauts && !formNlp) return;

    try {
        const reponse = await appelerApi('/api/parametres.php');
        const params = reponse.donnees || reponse || {};

        // Remplir les champs du formulaire API
        if (formApi) {
            remplirFormulaire(formApi, params);
        }

        // Remplir les champs du formulaire NLP
        if (formNlp) {
            remplirFormulaire(formNlp, params);
            mettreAJourIndicateurNlp(params);
        }

        // Remplir les champs du formulaire par defaut
        if (formDefauts) {
            remplirFormulaire(formDefauts, params);
        }
    } catch (erreur) {
        console.warn('Impossible de charger les parametres :', erreur.message);
    }
}

/**
 * Met a jour l'indicateur de mode NLP (Google ou lexique).
 */
function mettreAJourIndicateurNlp(params) {
    const indicateur = document.getElementById('nlpModeIndicateur');
    if (!indicateur) return;

    const cleConfiguree = !!(params.google_nlp_api_key);
    if (cleConfiguree) {
        indicateur.innerHTML = '<span class="badge bg-success"><i class="bi bi-cloud-check me-1"></i> Mode Google NLP actif</span>';
    } else {
        indicateur.innerHTML = '<span class="badge bg-secondary"><i class="bi bi-book me-1"></i> Mode lexique local (fallback)</span>';
    }
}

/**
 * Remplit un formulaire a partir d'un objet de donnees.
 *
 * @param {HTMLFormElement} formulaire  Element du formulaire
 * @param {object}          donnees    Donnees cle/valeur
 */
function remplirFormulaire(formulaire, donnees) {
    for (const [cle, valeur] of Object.entries(donnees)) {
        const champ = formulaire.querySelector(`[name="${cle}"]`);
        if (!champ) continue;

        if (champ.type === 'checkbox') {
            champ.checked = !!valeur;
        } else if (champ.type === 'radio') {
            const radio = formulaire.querySelector(`[name="${cle}"][value="${valeur}"]`);
            if (radio) radio.checked = true;
        } else {
            champ.value = valeur || '';
        }
    }
}

/**
 * Initialise les formulaires de parametres.
 */
function initFormulairesParametres() {
    const formulaires = document.querySelectorAll('#formulaireParametresApi, #formulaireParametresDefaut, #formulaireParametresNlp');

    formulaires.forEach(form => {
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const bouton = form.querySelector('button[type="submit"]');
            if (bouton) {
                bouton.disabled = true;
                bouton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enregistrement...';
            }

            try {
                const formData = new FormData(form);
                const donnees = Object.fromEntries(formData.entries());
                const reponse = await appelerApi('/api/parametres.php', {
                    method: 'POST',
                    body: JSON.stringify(donnees),
                });

                afficherToast(reponse.message || 'Parametres enregistres.', 'success');

                // Mettre a jour l'indicateur NLP si on vient du formulaire NLP
                if (form.id === 'formulaireParametresNlp') {
                    mettreAJourIndicateurNlp(donnees);
                }
            } catch (erreur) {
                afficherToast('Erreur : ' + erreur.message, 'danger');
            } finally {
                if (bouton) {
                    bouton.disabled = false;
                    bouton.textContent = 'Enregistrer';
                }
            }
        });
    });
}

/* ==========================================================================
   PAGE RESULTATS (resultats.php)
   ========================================================================== */

/**
 * Initialise la page de resultats.
 */
async function initResultats() {
    await chargerChartJs();
    configurerChartJs();

    const elAnalyse = document.querySelector('[data-analyse-id]');
    if (!elAnalyse) return;

    const analyseId = elAnalyse.dataset.analyseId;
    chargerResultatsAnalyse(analyseId);
}

/**
 * Charge et affiche tous les resultats d'une analyse.
 *
 * @param {string} analyseId Identifiant de l'analyse
 */
async function chargerResultatsAnalyse(analyseId) {
    try {
        const reponseApi = await appelerApi(`/api/resultats.php?analyse_id=${analyseId}`);
        const d = reponseApi.donnees || reponseApi;

        // Mapper les donnees pour les renderers
        const data = {
            donnees: d,
            sujets: d.sujets || [],
            questions: d.questions || [],
            discussions: d.publications || [],
            facteurs: d.facteurs || {},
            engagement: { auteurs: d.auteurs || [] },
            geographie: [],
            opportunites: {},
        };

        // Indicateur qualite des donnees
        renderIndicateurQualite(d.statistiques || {});

        renderSynthese(data);
        renderSujets(data);
        renderQuestions(data);
        renderDiscussions(data);
        renderFacteurs(data);
        renderEngagement(data);
        renderGeographie(data);
        renderOpportunites(data);
    } catch (erreur) {
        const conteneur = document.getElementById('contenuResultats') || document.querySelector('.tab-content');
        if (conteneur) {
            conteneur.innerHTML = `
                <div class="alert alert-danger m-3">
                    <strong>Erreur :</strong> ${erreur.message}
                </div>
            `;
        }
    }
}

/**
 * Affiche l'indicateur de qualite des donnees selon le mode de collecte.
 *
 * @param {object} stats Statistiques de l'analyse
 */
function renderIndicateurQualite(stats) {
    const el = document.getElementById('indicateurQualiteDonnees');
    if (!el) return;

    const mode = stats.mode_collecte || 'serpapi';
    el.style.display = '';

    if (mode === 'navigateur') {
        el.className = 'alert alert-success mb-4';
        el.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> '
            + '<strong>Donnees completes</strong> — Analyse basee sur les donnees Reddit directes (mode Navigateur). '
            + 'Engagement, auteurs et scores disponibles.';
    } else {
        el.className = 'alert alert-warning mb-4';
        el.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> '
            + '<strong>Donnees partielles</strong> — Analyse basee sur les snippets Google (SerpAPI). '
            + 'Les metriques d\'engagement (score, commentaires, awards) ne sont pas disponibles. '
            + '<br><small>Pour des resultats plus complets, relancez l\'analyse en mode Navigateur.</small>';
    }
}

/* --------------------------------------------------------------------------
   Onglet 1 : Synthese
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet synthese avec score, KPIs, et graphiques sentiment.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderSynthese(data) {
    const d = data.donnees || data;
    const analyse = d.analyse || {};
    const stats = d.statistiques || {};
    const score = analyse.score_reputation || 0;
    const info = formaterScore(score);

    // Score principal (KPI card ou gauge)
    const elScore = document.getElementById('synthese-score');
    if (elScore) {
        elScore.textContent = score;
        elScore.style.color = info.couleur;
    }

    const elGauge = document.getElementById('graphiqueGauge');
    if (elGauge) {
        renderGaugeScore(elGauge, score);
    }

    // KPIs de synthese
    const elVolume = document.getElementById('synthese-volume');
    if (elVolume) {
        elVolume.textContent = (stats.total_publications || 0).toLocaleString('fr-FR');
    }
    const elVolumeDetail = document.getElementById('synthese-volume-detail');
    if (elVolumeDetail) {
        const nbComm = stats.total_commentaires || 0;
        elVolumeDetail.textContent = `+ ${nbComm} commentaires`;
    }

    const elSentiment = document.getElementById('synthese-sentiment');
    if (elSentiment) {
        const dist = stats.distribution_sentiment || {};
        const maxSentiment = Math.max(dist.positif || 0, dist.neutre || 0, dist.negatif || 0);
        let sentimentDominant = 'Neutre';
        if (maxSentiment > 0) {
            if ((dist.positif || 0) === maxSentiment) sentimentDominant = 'Positif';
            else if ((dist.negatif || 0) === maxSentiment) sentimentDominant = 'Negatif';
        }
        elSentiment.textContent = sentimentDominant;
        elSentiment.className = 'fw-bold mt-2 mb-1 h2';
        if (sentimentDominant === 'Positif') elSentiment.classList.add('text-success');
        else if (sentimentDominant === 'Negatif') elSentiment.classList.add('text-danger');
    }
    const elSentimentDetail = document.getElementById('synthese-sentiment-detail');
    if (elSentimentDetail) {
        const dist = stats.distribution_sentiment || {};
        elSentimentDetail.textContent = `${dist.positif || 0}+ / ${dist.neutre || 0}= / ${dist.negatif || 0}-`;
    }

    const elTopSub = document.getElementById('synthese-top-subreddit');
    if (elTopSub) {
        const topSubs = stats.top_subreddits || {};
        const premierSub = Object.keys(topSubs)[0] || '—';
        elTopSub.textContent = premierSub !== '—' ? 'r/' + premierSub : '—';
    }
    const elTopSubDetail = document.getElementById('synthese-top-subreddit-detail');
    if (elTopSubDetail) {
        const topSubs = stats.top_subreddits || {};
        const nbSubs = Object.keys(topSubs).length;
        elTopSubDetail.textContent = nbSubs > 1 ? `${nbSubs} subreddits actifs` : '';
    }

    // Badge methode de sentiment
    const elMethodeSentiment = document.getElementById('badgeMethodeSentiment');
    if (elMethodeSentiment) {
        const methode = stats.methode_sentiment || null;
        if (methode === 'google_nlp') {
            elMethodeSentiment.innerHTML = '<span class="badge bg-success"><i class="bi bi-cloud-check me-1"></i> Google NLP</span>';
        } else if (methode === 'lexique') {
            elMethodeSentiment.innerHTML = '<span class="badge bg-secondary"><i class="bi bi-book me-1"></i> Lexique local</span>';
        }
    }

    // Graphique donut sentiment
    renderGraphiqueSentiment(stats.distribution_sentiment);
}

/**
 * Dessine une jauge demi-circulaire pour le score dans un canvas.
 */
function renderGaugeScore(canvas, score) {
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const w = canvas.width;
    const h = canvas.height;
    const cx = w / 2;
    const cy = h - 10;
    const r = Math.min(cx, cy) - 10;

    ctx.clearRect(0, 0, w, h);

    // Fond gris
    ctx.beginPath();
    ctx.arc(cx, cy, r, Math.PI, 2 * Math.PI);
    ctx.lineWidth = 12;
    ctx.strokeStyle = '#e2e8f0';
    ctx.stroke();

    // Arc colore
    const angle = Math.PI + (score / 100) * Math.PI;
    const color = score >= 70 ? '#16a34a' : score >= 40 ? '#d97706' : '#dc2626';
    ctx.beginPath();
    ctx.arc(cx, cy, r, Math.PI, angle);
    ctx.lineWidth = 12;
    ctx.strokeStyle = color;
    ctx.lineCap = 'round';
    ctx.stroke();

    // Score texte
    ctx.fillStyle = color;
    ctx.font = 'bold 28px Poppins, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(Math.round(score).toString(), cx, cy - 10);

    ctx.fillStyle = '#64748b';
    ctx.font = '11px Poppins, sans-serif';
    ctx.fillText('/100', cx, cy + 6);
}

/**
 * Affiche le graphique donut de repartition des sentiments.
 *
 * @param {object} sentiments Objet {positif, neutre, negatif}
 */
function renderGraphiqueSentiment(sentiments) {
    if (!sentiments) return;
    const canvasId = 'graphiqueSentimentDonut';
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    detruireGraphique(canvasId);

    const positif = sentiments.positif || 0;
    const neutre = sentiments.neutre || 0;
    const negatif = sentiments.negatif || 0;

    const chart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Positif', 'Neutre', 'Negatif'],
            datasets: [{
                data: [positif, neutre, negatif],
                backgroundColor: [COULEURS_GRAPHIQUES.vert, COULEURS_GRAPHIQUES.gris, COULEURS_GRAPHIQUES.rouge],
                borderWidth: 2,
                borderColor: '#ffffff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        padding: 16,
                        usePointStyle: true,
                        pointStyleWidth: 12,
                    },
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const pourcent = total > 0 ? Math.round((context.parsed / total) * 100) : 0;
                            return ` ${context.label} : ${context.parsed} (${pourcent}%)`;
                        },
                    },
                },
            },
        },
    });
    canvas._chartInstance = chart;
}

/**
 * Affiche le graphique d'evolution temporelle des sentiments.
 *
 * @param {object} temporel Donnees temporelles {labels, positif[], neutre[], negatif[]}
 */
function renderGraphiqueTemporel(temporel) {
    if (!temporel || !temporel.labels) return;
    const canvasId = 'graphiqueEvolutionTemporelle';
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    detruireGraphique(canvasId);

    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: temporel.labels,
            datasets: [
                {
                    label: 'Positif',
                    data: temporel.positif || [],
                    borderColor: COULEURS_GRAPHIQUES.vert,
                    backgroundColor: COULEURS_GRAPHIQUES.vert + '20',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                },
                {
                    label: 'Neutre',
                    data: temporel.neutre || [],
                    borderColor: COULEURS_GRAPHIQUES.gris,
                    backgroundColor: COULEURS_GRAPHIQUES.gris + '20',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                },
                {
                    label: 'Negatif',
                    data: temporel.negatif || [],
                    borderColor: COULEURS_GRAPHIQUES.rouge,
                    backgroundColor: COULEURS_GRAPHIQUES.rouge + '20',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index',
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#e5e7eb',
                    },
                },
                x: {
                    grid: {
                        display: false,
                    },
                },
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    enabled: true,
                },
            },
        },
    });
    canvas._chartInstance = chart;
}

/* --------------------------------------------------------------------------
   Onglet 2 : Sujets
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet sujets : nuage de mots et tableau des sujets.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderSujets(data) {
    const sujets = data.sujets || [];

    // Nuage de mots
    renderNuageMots(sujets);

    // Tableau des sujets
    const corps = document.getElementById('corpsSujets');
    if (!corps) return;

    if (sujets.length === 0) {
        corps.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Aucun sujet identifie.</td></tr>';
        return;
    }

    corps.innerHTML = sujets.map((sujet, index) => {
        const sentimentPositif = sujet.sentiment_positif || 0;
        const sentimentNeutre = sujet.sentiment_neutre || 0;
        const sentimentNegatif = sujet.sentiment_negatif || 0;
        const total = sentimentPositif + sentimentNeutre + sentimentNegatif || 1;

        const pPos = Math.round((sentimentPositif / total) * 100);
        const pNeu = Math.round((sentimentNeutre / total) * 100);
        const pNeg = Math.round((sentimentNegatif / total) * 100);

        const exemples = (sujet.exemples || []).map(ex =>
            `<a href="${echapper(ex.url || '#')}" target="_blank" rel="noopener" class="small text-muted d-block text-truncate" style="max-width:400px;">${echapper(ex.titre || ex.url || '')}</a>`
        ).join('');

        return `
            <tr>
                <td><strong>${echapper(sujet.nom || sujet.label || '')}</strong></td>
                <td>${sujet.frequence || sujet.count || 0}</td>
                <td style="min-width:200px;">
                    <div class="d-flex" style="height:20px;border-radius:4px;overflow:hidden;">
                        <div style="width:${pPos}%;background:${COULEURS_GRAPHIQUES.vert};" title="Positif ${pPos}%"></div>
                        <div style="width:${pNeu}%;background:${COULEURS_GRAPHIQUES.gris};" title="Neutre ${pNeu}%"></div>
                        <div style="width:${pNeg}%;background:${COULEURS_GRAPHIQUES.rouge};" title="Negatif ${pNeg}%"></div>
                    </div>
                    <small class="text-muted">${pPos}% pos. / ${pNeu}% neu. / ${pNeg}% neg.</small>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#detailSujet${index}">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="collapse mt-2" id="detailSujet${index}">
                        ${exemples || '<span class="text-muted small">Aucun exemple</span>'}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Genere un nuage de mots positionnes dans un conteneur.
 *
 * @param {Array} sujets Liste des sujets avec frequences
 */
function renderNuageMots(sujets) {
    const conteneur = document.getElementById('sujets-wordcloud');
    if (!conteneur || sujets.length === 0) return;

    const maxFreq = Math.max(...sujets.map(s => s.frequence || s.count || 1));
    const minFreq = Math.min(...sujets.map(s => s.frequence || s.count || 1));
    const range = maxFreq - minFreq || 1;

    conteneur.style.position = 'relative';
    conteneur.style.height = '280px';
    conteneur.style.overflow = 'hidden';

    const couleurs = [COULEURS_GRAPHIQUES.dark, COULEURS_GRAPHIQUES.teal, COULEURS_GRAPHIQUES.gold, COULEURS_GRAPHIQUES.vert, COULEURS_GRAPHIQUES.rouge];

    // Utiliser un algorithme simple de placement pseudo-aleatoire
    const seed = sujets.length;
    function pseudoAleatoire(i, max) {
        return ((i * 7 + seed * 13) % max);
    }

    conteneur.innerHTML = sujets.map((sujet, i) => {
        const freq = sujet.frequence || sujet.count || 1;
        const taille = 14 + Math.round(((freq - minFreq) / range) * 24);
        const opacite = 0.6 + ((freq - minFreq) / range) * 0.4;
        const couleur = couleurs[i % couleurs.length];

        const left = pseudoAleatoire(i, 80) + 5;
        const top = pseudoAleatoire(i * 3 + 1, 75) + 5;

        return `
            <span style="
                position: absolute;
                left: ${left}%;
                top: ${top}%;
                font-size: ${taille}px;
                font-weight: ${freq === maxFreq ? '700' : '500'};
                color: ${couleur};
                opacity: ${opacite};
                cursor: default;
                white-space: nowrap;
                transition: transform 0.2s ease;
            " title="${echapper(sujet.nom || sujet.label || '')} (${freq})"
            onmouseover="this.style.transform='scale(1.15)'"
            onmouseout="this.style.transform='scale(1)'"
            >${echapper(sujet.nom || sujet.label || '')}</span>
        `;
    }).join('');
}

/* --------------------------------------------------------------------------
   Onglet 3 : Questions
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet questions avec filtres par categorie.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderQuestions(data) {
    const questions = data.questions || [];
    const conteneur = document.getElementById('liste-questions');
    const filtres = document.getElementById('filtres-questions');
    if (!conteneur) return;

    if (questions.length === 0) {
        conteneur.innerHTML = '<p class="text-muted text-center py-4">Aucune question identifiee.</p>';
        return;
    }

    // Compter par categorie
    const categories = {};
    questions.forEach(q => {
        const cat = q.categorie || 'Autre';
        categories[cat] = (categories[cat] || 0) + 1;
    });

    // Boutons de filtre
    if (filtres) {
        let htmlFiltres = `<button class="btn btn-sm btn-primary me-1 mb-1 filtre-question active" data-categorie="toutes">Toutes (${questions.length})</button>`;
        for (const [cat, count] of Object.entries(categories)) {
            htmlFiltres += `<button class="btn btn-sm btn-outline-primary me-1 mb-1 filtre-question" data-categorie="${echapper(cat)}">${echapper(cat)} (${count})</button>`;
        }
        filtres.innerHTML = htmlFiltres;

        filtres.querySelectorAll('.filtre-question').forEach(btn => {
            btn.addEventListener('click', () => {
                filtres.querySelectorAll('.filtre-question').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                btn.classList.add('active', 'btn-primary');
                btn.classList.remove('btn-outline-primary');

                const categorie = btn.dataset.categorie;
                conteneur.querySelectorAll('.carte-question').forEach(carte => {
                    if (categorie === 'toutes' || carte.dataset.categorie === categorie) {
                        carte.style.display = '';
                    } else {
                        carte.style.display = 'none';
                    }
                });
            });
        });
    }

    // Cartes de questions
    conteneur.innerHTML = questions.map(q => {
        const categorie = q.categorie || 'Autre';
        return `
            <div class="card mb-2 carte-question" data-categorie="${echapper(categorie)}">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge bg-light text-dark me-2">${echapper(categorie)}</span>
                            <strong>${echapper(q.question || q.titre || '')}</strong>
                            ${q.frequence ? `<span class="text-muted small ms-2">(${q.frequence}x)</span>` : ''}
                        </div>
                        ${q.url ? `<a href="${echapper(q.url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary flex-shrink-0"><i class="bi bi-reddit"></i></a>` : ''}
                    </div>
                    ${q.contexte ? `<p class="text-muted small mb-0 mt-1">${echapper(q.contexte)}</p>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

/* --------------------------------------------------------------------------
   Onglet 4 : Discussions
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet discussions avec filtres et scatter plot.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderDiscussions(data) {
    const discussions = data.discussions || [];
    const conteneur = document.getElementById('corpsDiscussions');
    const filtres = document.getElementById('filtres-discussions');
    if (!conteneur) return;

    if (discussions.length === 0) {
        conteneur.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Aucune discussion identifiee.</td></tr>';
        return;
    }

    // Boutons de filtre
    if (filtres) {
        const types = [
            { cle: 'toutes', label: 'Toutes' },
            { cle: 'virale_positive', label: 'Virales +' },
            { cle: 'virale_negative', label: 'Virales -' },
            { cle: 'controversee', label: 'Controversees' },
        ];

        filtres.innerHTML = types.map((t, i) => `
            <button class="btn btn-sm ${i === 0 ? 'btn-primary' : 'btn-outline-primary'} me-1 mb-1 filtre-discussion"
                    data-type-discussion="${t.cle}">${t.label}</button>
        `).join('');

        filtres.querySelectorAll('.filtre-discussion').forEach(btn => {
            btn.addEventListener('click', () => {
                filtres.querySelectorAll('.filtre-discussion').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                btn.classList.add('active', 'btn-primary');
                btn.classList.remove('btn-outline-primary');

                const typeFiltre = btn.dataset.typeDiscussion;
                conteneur.querySelectorAll('tr[data-type]').forEach(ligne => {
                    if (typeFiltre === 'toutes' || ligne.dataset.type === typeFiltre) {
                        ligne.style.display = '';
                    } else {
                        ligne.style.display = 'none';
                    }
                });
            });
        });
    }

    // Tableau des discussions
    conteneur.innerHTML = discussions.map(d => {
        let couleurBordure, badgeType;
        switch (d.type) {
            case 'virale_positive':
                couleurBordure = COULEURS_GRAPHIQUES.vert;
                badgeType = '<span class="badge bg-success">Virale +</span>';
                break;
            case 'virale_negative':
                couleurBordure = COULEURS_GRAPHIQUES.rouge;
                badgeType = '<span class="badge bg-danger">Virale -</span>';
                break;
            case 'controversee':
                couleurBordure = COULEURS_GRAPHIQUES.gold;
                badgeType = '<span class="badge bg-warning text-dark">Controversee</span>';
                break;
            default:
                couleurBordure = COULEURS_GRAPHIQUES.gris;
                badgeType = '<span class="badge bg-secondary">Standard</span>';
        }

        return `
            <tr data-type="${echapper(d.type || 'standard')}" style="border-left: 3px solid ${couleurBordure};">
                <td>
                    <a href="${echapper(d.url || '#')}" target="_blank" rel="noopener" class="text-decoration-none">
                        ${echapper(d.titre || 'Sans titre')}
                    </a>
                </td>
                <td><small class="text-muted">r/${echapper(d.subreddit || '')}</small></td>
                <td>${badgeType}</td>
                <td>${(d.engagement || d.score || 0).toLocaleString('fr-FR')}</td>
                <td>${d.commentaires || 0}</td>
                <td>${formaterDate(d.date)}</td>
            </tr>
        `;
    }).join('');

    // Scatter plot engagement vs sentiment
    renderScatterDiscussions(discussions);
}

/**
 * Affiche le scatter plot engagement vs sentiment des discussions.
 *
 * @param {Array} discussions Liste des discussions
 */
function renderScatterDiscussions(discussions) {
    const canvasId = 'graphiqueEngagementSentiment';
    const canvas = document.getElementById(canvasId);
    if (!canvas || discussions.length === 0) return;

    detruireGraphique(canvasId);

    const couleurParType = {
        virale_positive: COULEURS_GRAPHIQUES.vert,
        virale_negative: COULEURS_GRAPHIQUES.rouge,
        controversee: COULEURS_GRAPHIQUES.gold,
        standard: COULEURS_GRAPHIQUES.gris,
    };

    // Grouper les donnees par type
    const groupes = {};
    discussions.forEach(d => {
        const type = d.type || 'standard';
        if (!groupes[type]) groupes[type] = [];
        groupes[type].push({
            x: d.engagement || d.score || 0,
            y: d.sentiment_score || 0,
            titre: d.titre || '',
        });
    });

    const labelsType = {
        virale_positive: 'Virale +',
        virale_negative: 'Virale -',
        controversee: 'Controversee',
        standard: 'Standard',
    };

    const datasets = Object.entries(groupes).map(([type, points]) => ({
        label: labelsType[type] || type,
        data: points,
        backgroundColor: (couleurParType[type] || COULEURS_GRAPHIQUES.gris) + 'AA',
        borderColor: couleurParType[type] || COULEURS_GRAPHIQUES.gris,
        borderWidth: 1,
        pointRadius: 6,
        pointHoverRadius: 9,
    }));

    const chart = new Chart(canvas, {
        type: 'scatter',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Engagement',
                        font: { weight: '600' },
                    },
                    grid: { color: '#e5e7eb' },
                },
                y: {
                    title: {
                        display: true,
                        text: 'Sentiment',
                        font: { weight: '600' },
                    },
                    grid: { color: '#e5e7eb' },
                    min: -1,
                    max: 1,
                },
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const point = context.raw;
                            const titre = point.titre ? ` — ${point.titre.substring(0, 50)}` : '';
                            return `Engagement: ${point.x}, Sentiment: ${point.y.toFixed(2)}${titre}`;
                        },
                    },
                },
            },
        },
    });
    canvas._chartInstance = chart;
}

/* --------------------------------------------------------------------------
   Onglet 5 : Facteurs
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet facteurs positifs et negatifs avec radar chart.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderFacteurs(data) {
    const facteurs = data.facteurs || {};
    const positifs = facteurs.positifs || [];
    const negatifs = facteurs.negatifs || [];

    // Colonne facteurs positifs
    const colPositifs = document.getElementById('facteurs-positifs');
    if (colPositifs) {
        if (positifs.length === 0) {
            colPositifs.innerHTML = '<p class="text-muted">Aucun facteur positif identifie.</p>';
        } else {
            colPositifs.innerHTML = positifs.map(f => renderCarteFacteur(f, 'positif')).join('');
        }
    }

    // Colonne facteurs negatifs
    const colNegatifs = document.getElementById('facteurs-negatifs');
    if (colNegatifs) {
        if (negatifs.length === 0) {
            colNegatifs.innerHTML = '<p class="text-muted">Aucun facteur negatif identifie.</p>';
        } else {
            colNegatifs.innerHTML = negatifs.map(f => renderCarteFacteur(f, 'negatif')).join('');
        }
    }

    // Radar chart
    renderRadarFacteurs(facteurs.dimensions || facteurs.radar);
}

/**
 * Genere le HTML d'une carte de facteur.
 *
 * @param {object} facteur  Objet facteur
 * @param {string} type     'positif' ou 'negatif'
 * @returns {string} HTML
 */
function renderCarteFacteur(facteur, type) {
    const couleur = type === 'positif' ? COULEURS_GRAPHIQUES.vert : COULEURS_GRAPHIQUES.rouge;
    const couleurFond = type === 'positif' ? '#f0fdf4' : '#fef2f2';
    const maxFrequence = 100;
    const largeurBarre = Math.min(100, Math.round(((facteur.frequence || facteur.count || 1) / maxFrequence) * 100));

    let badgeInfluence = '';
    const influence = facteur.influence || facteur.impact || '';
    if (influence) {
        const couleurBadge = influence === 'forte' ? 'danger' : influence === 'moyenne' ? 'warning' : 'secondary';
        badgeInfluence = `<span class="badge bg-${couleurBadge} ms-2">${echapper(influence)}</span>`;
    }

    return `
        <div class="card mb-2" style="background: ${couleurFond}; border-left: 3px solid ${couleur};">
            <div class="card-body py-2 px-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong>${echapper(facteur.nom || facteur.label || '')}</strong>
                    ${badgeInfluence}
                </div>
                <div class="progress" style="height: 6px;">
                    <div class="progress-bar" style="width: ${largeurBarre}%; background: ${couleur};" role="progressbar"></div>
                </div>
                <small class="text-muted">Frequence : ${facteur.frequence || facteur.count || 0} mentions</small>
            </div>
        </div>
    `;
}

/**
 * Affiche le radar chart des dimensions de reputation.
 *
 * @param {object} dimensions Objet avec 5 dimensions {label: valeur}
 */
function renderRadarFacteurs(dimensions) {
    const canvasId = 'graphiqueRadarFacteurs';
    const canvas = document.getElementById(canvasId);
    if (!canvas || !dimensions) return;

    detruireGraphique(canvasId);

    const labels = Object.keys(dimensions);
    const valeurs = Object.values(dimensions);

    const chart = new Chart(canvas, {
        type: 'radar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Score de reputation',
                data: valeurs,
                backgroundColor: COULEURS_GRAPHIQUES.teal + '30',
                borderColor: COULEURS_GRAPHIQUES.teal,
                borderWidth: 2,
                pointBackgroundColor: COULEURS_GRAPHIQUES.dark,
                pointBorderColor: COULEURS_GRAPHIQUES.dark,
                pointRadius: 4,
                pointHoverRadius: 7,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        backdropColor: 'transparent',
                        font: { size: 11 },
                    },
                    grid: {
                        color: '#e5e7eb',
                    },
                    angleLines: {
                        color: '#e5e7eb',
                    },
                    pointLabels: {
                        font: {
                            size: 13,
                            weight: '600',
                        },
                        color: COULEURS_GRAPHIQUES.dark,
                    },
                },
            },
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ` ${context.label} : ${context.raw}/100`;
                        },
                    },
                },
            },
        },
    });
    canvas._chartInstance = chart;
}

/* --------------------------------------------------------------------------
   Onglet 6 : Engagement
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet engagement : auteurs, subreddits, power users.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderEngagement(data) {
    const engagement = data.engagement || {};

    // Tableau des auteurs
    const corpsAuteurs = document.getElementById('corpsAuteurs');
    const auteurs = engagement.auteurs || [];
    if (corpsAuteurs) {
        if (auteurs.length === 0) {
            corpsAuteurs.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Aucun auteur identifie.</td></tr>';
        } else {
            corpsAuteurs.innerHTML = auteurs.map(a => {
                let badgeInfluence = '';
                if (a.influence === 'forte' || a.est_power_user) {
                    badgeInfluence = '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-star-fill"></i> Power User</span>';
                } else if (a.influence === 'moyenne') {
                    badgeInfluence = '<span class="badge bg-info text-dark ms-1">Actif</span>';
                }

                return `
                    <tr>
                        <td>
                            <a href="https://reddit.com/u/${echapper(a.nom || a.username || '')}" target="_blank" rel="noopener">
                                u/${echapper(a.nom || a.username || '')}
                            </a>
                            ${badgeInfluence}
                        </td>
                        <td>${a.posts || a.nombre_posts || 0}</td>
                        <td>${(a.karma || a.score_total || 0).toLocaleString('fr-FR')}</td>
                        <td>${a.sentiment_moyen !== undefined ? a.sentiment_moyen.toFixed(2) : '—'}</td>
                        <td>${echapper(a.subreddits_principaux || a.top_subreddit || '')}</td>
                    </tr>
                `;
            }).join('');
        }
    }

    // Power users highlight
    const zonePowerUsers = document.getElementById('powerUsers');
    if (zonePowerUsers) {
        const powerUsers = auteurs.filter(a => a.est_power_user || a.influence === 'forte');
        if (powerUsers.length > 0) {
            zonePowerUsers.innerHTML = `
                <div class="alert alert-warning">
                    <strong><i class="bi bi-star-fill"></i> Power Users identifies :</strong>
                    ${powerUsers.map(u => `<span class="badge bg-dark ms-1">u/${echapper(u.nom || u.username || '')}</span>`).join('')}
                </div>
            `;
        } else {
            zonePowerUsers.innerHTML = '';
        }
    }

    // Graphique engagement par subreddit
    renderGraphiqueEngagementSubreddits(engagement.subreddits || []);
}

/**
 * Affiche le bar chart d'engagement par subreddit.
 *
 * @param {Array} subreddits Liste des subreddits avec engagement
 */
function renderGraphiqueEngagementSubreddits(subreddits) {
    const canvasId = 'graphiqueEngagementSubreddits';
    const canvas = document.getElementById(canvasId);
    if (!canvas || subreddits.length === 0) return;

    detruireGraphique(canvasId);

    const labels = subreddits.map(s => 'r/' + (s.nom || s.subreddit || ''));
    const valeurs = subreddits.map(s => s.engagement || s.score || 0);

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Engagement',
                data: valeurs,
                backgroundColor: COULEURS_GRAPHIQUES.serie.slice(0, subreddits.length).map(c => c + 'CC'),
                borderColor: COULEURS_GRAPHIQUES.serie.slice(0, subreddits.length),
                borderWidth: 1,
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#e5e7eb' },
                    title: {
                        display: true,
                        text: 'Score d\'engagement',
                        font: { weight: '600' },
                    },
                },
                y: {
                    grid: { display: false },
                },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ` Engagement : ${context.parsed.x.toLocaleString('fr-FR')}`;
                        },
                    },
                },
            },
        },
    });
    canvas._chartInstance = chart;
}

/* --------------------------------------------------------------------------
   Onglet 7 : Geographie
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet geographie : tableau et bar chart par region.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderGeographie(data) {
    const regions = data.geographie || data.regions || [];

    // Tableau des regions
    const corps = document.getElementById('corpsGeographie');
    if (corps) {
        if (regions.length === 0) {
            corps.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Aucune donnee geographique disponible.</td></tr>';
        } else {
            corps.innerHTML = regions.map(r => {
                const sentimentScore = r.sentiment_score || r.sentiment || 0;
                let couleurSentiment, labelSentiment;
                if (sentimentScore > 0.2) {
                    couleurSentiment = 'text-success';
                    labelSentiment = 'Positif';
                } else if (sentimentScore < -0.2) {
                    couleurSentiment = 'text-danger';
                    labelSentiment = 'Negatif';
                } else {
                    couleurSentiment = 'text-muted';
                    labelSentiment = 'Neutre';
                }

                return `
                    <tr>
                        <td><strong>${echapper(r.region || r.nom || '')}</strong></td>
                        <td>${r.mentions || r.count || 0}</td>
                        <td class="${couleurSentiment}">${labelSentiment} (${sentimentScore.toFixed(2)})</td>
                        <td>${r.subreddits_principaux || '—'}</td>
                    </tr>
                `;
            }).join('');
        }
    }

    // Bar chart horizontal par region
    renderGraphiqueGeographie(regions);
}

/**
 * Affiche le bar chart horizontal de repartition geographique.
 *
 * @param {Array} regions Liste des regions
 */
function renderGraphiqueGeographie(regions) {
    const canvasId = 'graphiqueSentimentRegions';
    const canvas = document.getElementById(canvasId);
    if (!canvas || regions.length === 0) return;

    detruireGraphique(canvasId);

    const labels = regions.map(r => r.region || r.nom || '');
    const mentions = regions.map(r => r.mentions || r.count || 0);
    const sentiments = regions.map(r => r.sentiment_score || r.sentiment || 0);

    const couleurs = sentiments.map(s => {
        if (s > 0.2) return COULEURS_GRAPHIQUES.vert + 'CC';
        if (s < -0.2) return COULEURS_GRAPHIQUES.rouge + 'CC';
        return COULEURS_GRAPHIQUES.gris + 'CC';
    });

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Mentions',
                data: mentions,
                backgroundColor: couleurs,
                borderColor: couleurs.map(c => c.replace('CC', '')),
                borderWidth: 1,
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#e5e7eb' },
                    title: {
                        display: true,
                        text: 'Nombre de mentions',
                        font: { weight: '600' },
                    },
                },
                y: {
                    grid: { display: false },
                },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const idx = context.dataIndex;
                            const sentiment = sentiments[idx];
                            return ` Mentions : ${context.parsed.x} | Sentiment : ${sentiment.toFixed(2)}`;
                        },
                    },
                },
            },
        },
    });
    canvas._chartInstance = chart;
}

/* --------------------------------------------------------------------------
   Onglet 8 : Opportunites
   -------------------------------------------------------------------------- */

/**
 * Affiche l'onglet opportunites et recommandations.
 *
 * @param {object} data Donnees completes de l'analyse
 */
function renderOpportunites(data) {
    const opportunites = data.opportunites || {};
    const analyseId = document.querySelector('[data-analyse-id]')?.dataset.analyseId || '';

    // Sections par categorie
    const categoriesConfig = {
        contenu: { titre: 'Contenu', icone: 'bi-file-earmark-text', couleur: COULEURS_GRAPHIQUES.teal },
        communautes: { titre: 'Communautes', icone: 'bi-people', couleur: COULEURS_GRAPHIQUES.dark },
        questions: { titre: 'Questions', icone: 'bi-question-circle', couleur: COULEURS_GRAPHIQUES.gold },
        advocates: { titre: 'Advocates', icone: 'bi-star', couleur: COULEURS_GRAPHIQUES.vert },
    };

    const conteneur = document.getElementById('liste-opportunites');
    if (conteneur) {
        let html = '';

        for (const [cle, config] of Object.entries(categoriesConfig)) {
            const items = opportunites[cle] || [];
            if (items.length === 0) continue;

            html += `
                <div class="mb-4">
                    <h5 class="mb-3">
                        <i class="bi ${config.icone}" style="color: ${config.couleur};"></i>
                        ${config.titre}
                    </h5>
                    <div class="row g-2">
                        ${items.map(item => renderCarteOpportunite(item)).join('')}
                    </div>
                </div>
            `;
        }

        if (!html) {
            html = '<p class="text-muted text-center py-4">Aucune opportunite identifiee.</p>';
        }

        conteneur.innerHTML = html;
    }

    // Recommandations
    const listeRecos = document.getElementById('recommandations');
    const recommandations = opportunites.recommandations || data.recommandations || [];
    if (listeRecos) {
        if (recommandations.length === 0) {
            listeRecos.innerHTML = '<p class="text-muted">Aucune recommandation.</p>';
        } else {
            listeRecos.innerHTML = `
                <ul class="list-group list-group-flush">
                    ${recommandations.map((r, i) => `
                        <li class="list-group-item d-flex align-items-start gap-2">
                            <span class="badge bg-primary rounded-pill mt-1">${i + 1}</span>
                            <div>
                                <strong>${echapper(r.titre || r.label || '')}</strong>
                                ${r.description ? `<p class="mb-0 small text-muted">${echapper(r.description)}</p>` : ''}
                            </div>
                        </li>
                    `).join('')}
                </ul>
            `;
        }
    }

    // Boutons d'export
    const zoneExport = document.getElementById('zoneExport');
    if (zoneExport) {
        zoneExport.innerHTML = `
            <a href="${BASE_URL}/api/export.php?analyse_id=${analyseId}&format=csv" class="btn btn-outline-primary me-2">
                <i class="bi bi-filetype-csv"></i> Exporter CSV
            </a>
            <a href="${BASE_URL}/api/export.php?analyse_id=${analyseId}&format=json" class="btn btn-outline-secondary">
                <i class="bi bi-filetype-json"></i> Exporter JSON
            </a>
        `;
    }
}

/**
 * Genere le HTML d'une carte d'opportunite.
 *
 * @param {object} item Objet opportunite
 * @returns {string} HTML
 */
function renderCarteOpportunite(item) {
    let badgeImpact = '';
    const impact = item.impact || item.priorite || '';
    if (impact) {
        let couleurImpact;
        switch (impact.toLowerCase()) {
            case 'fort':
            case 'haute':
                couleurImpact = 'danger';
                break;
            case 'moyen':
            case 'moyenne':
                couleurImpact = 'warning';
                break;
            default:
                couleurImpact = 'secondary';
        }
        badgeImpact = `<span class="badge bg-${couleurImpact} text-uppercase" style="font-size:0.7rem;letter-spacing:0.05em;">Impact ${echapper(impact)}</span>`;
    }

    return `
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <strong>${echapper(item.titre || item.label || '')}</strong>
                        ${badgeImpact}
                    </div>
                    ${item.description ? `<p class="small text-muted mb-0">${echapper(item.description)}</p>` : ''}
                    ${item.action ? `<p class="small mt-1 mb-0"><i class="bi bi-arrow-right-circle"></i> ${echapper(item.action)}</p>` : ''}
                </div>
            </div>
        </div>
    `;
}

/* ==========================================================================
   PAGE COMPARAISON (comparaison.php)
   ========================================================================== */

/**
 * Initialise la page de comparaison de marques.
 */
function initComparaison() {
    configurerChartJs();

    const boutonComparer = document.getElementById('boutonComparer');
    const conteneurSelection = document.getElementById('selectionMarques');

    if (!boutonComparer) return;

    // Charger les marques disponibles pour la selection
    chargerMarquesComparaison();

    boutonComparer.addEventListener('click', async () => {
        const marqueIds = [];
        document.querySelectorAll('input[name="marques_comparaison"]:checked').forEach(cb => {
            marqueIds.push(cb.value);
        });

        if (marqueIds.length < 2) {
            afficherToast('Selectionnez au moins 2 marques a comparer.', 'warning');
            return;
        }

        if (marqueIds.length > 5) {
            afficherToast('Maximum 5 marques pour la comparaison.', 'warning');
            return;
        }

        boutonComparer.disabled = true;
        boutonComparer.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Chargement...';

        try {
            await lancerComparaison(marqueIds);
        } catch (erreur) {
            afficherToast(erreur.message, 'danger');
        } finally {
            boutonComparer.disabled = false;
            boutonComparer.innerHTML = '<i class="bi bi-bar-chart-line"></i> Comparer';
        }
    });

    // Limiter la selection a 5 marques
    if (conteneurSelection) {
        conteneurSelection.addEventListener('change', () => {
            const coches = document.querySelectorAll('input[name="marques_comparaison"]:checked');
            if (coches.length >= 5) {
                document.querySelectorAll('input[name="marques_comparaison"]:not(:checked)').forEach(cb => {
                    cb.disabled = true;
                });
            } else {
                document.querySelectorAll('input[name="marques_comparaison"]').forEach(cb => {
                    cb.disabled = false;
                });
            }
        });
    }
}

/**
 * Charge les marques disponibles pour la selection de comparaison.
 */
async function chargerMarquesComparaison() {
    const conteneur = document.getElementById('selectionMarques');
    if (!conteneur) return;

    try {
        const reponse = await appelerApi('/api/marques.php');
        const marques = reponse.donnees || reponse || [];

        if (marques.length === 0) {
            conteneur.innerHTML = '<p class="text-muted">Aucune marque disponible. Lancez d\'abord des analyses.</p>';
            return;
        }

        conteneur.innerHTML = marques.map(m => `
            <div class="form-check form-check-inline mb-2">
                <input class="form-check-input" type="checkbox" name="marques_comparaison"
                       value="${m.id}" id="comp_${m.id}">
                <label class="form-check-label" for="comp_${m.id}">
                    ${echapper(m.nom)}
                    ${m.score ? `<small class="text-muted">(${Math.round(m.score)})</small>` : ''}
                </label>
            </div>
        `).join('');
    } catch (erreur) {
        conteneur.innerHTML = `<p class="text-danger">Erreur : ${erreur.message}</p>`;
    }
}

/**
 * Lance la comparaison entre plusieurs marques.
 *
 * @param {Array<string>} marqueIds Identifiants des marques
 */
async function lancerComparaison(marqueIds) {
    const data = await appelerApi(`/api/comparaison.php?marque_ids=${marqueIds.join(',')}`);

    // Afficher la zone de resultats
    const zoneResultats = document.getElementById('resultatsComparaison');
    if (zoneResultats) {
        zoneResultats.style.display = '';
    }

    renderComparaisonScores(data);
    renderComparaisonSentiment(data);
    renderComparaisonSujets(data);
    renderComparaisonTemporelle(data);
    renderComparaisonTableau(data);
}

/**
 * Affiche la comparaison des scores globaux.
 *
 * @param {object} data Donnees de comparaison
 */
function renderComparaisonScores(data) {
    const conteneur = document.getElementById('comparaisonScores');
    if (!conteneur) return;

    const marques = data.marques || [];

    conteneur.innerHTML = `
        <div class="row g-3 justify-content-center">
            ${marques.map((m, i) => {
                const info = formaterScore(m.score || 0);
                return `
                    <div class="col-auto text-center">
                        ${creerJaugeSvg(m.score || 0, 120)}
                        <h6 class="mt-2 mb-0">${echapper(m.nom)}</h6>
                        <span class="badge ${info.classe === 'score-high' ? 'bg-success' : info.classe === 'score-mid' ? 'bg-warning text-dark' : 'bg-danger'}">${info.label}</span>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

/**
 * Affiche la comparaison des sentiments sous forme de bar chart groupe.
 *
 * @param {object} data Donnees de comparaison
 */
function renderComparaisonSentiment(data) {
    const canvasId = 'graphiqueComparaisonSentiment';
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    detruireGraphique(canvasId);

    const marques = data.marques || [];
    const labels = marques.map(m => m.nom);

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Positif',
                    data: marques.map(m => (m.sentiments?.positif || 0)),
                    backgroundColor: COULEURS_GRAPHIQUES.vert + 'CC',
                    borderColor: COULEURS_GRAPHIQUES.vert,
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Neutre',
                    data: marques.map(m => (m.sentiments?.neutre || 0)),
                    backgroundColor: COULEURS_GRAPHIQUES.gris + 'CC',
                    borderColor: COULEURS_GRAPHIQUES.gris,
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Negatif',
                    data: marques.map(m => (m.sentiments?.negatif || 0)),
                    backgroundColor: COULEURS_GRAPHIQUES.rouge + 'CC',
                    borderColor: COULEURS_GRAPHIQUES.rouge,
                    borderWidth: 1,
                    borderRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#e5e7eb' },
                    title: {
                        display: true,
                        text: 'Pourcentage',
                        font: { weight: '600' },
                    },
                },
                x: {
                    grid: { display: false },
                },
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: { enabled: true },
            },
        },
    });
    canvas._chartInstance = chart;
}

/**
 * Affiche la comparaison des sujets principaux.
 *
 * @param {object} data Donnees de comparaison
 */
function renderComparaisonSujets(data) {
    const conteneur = document.getElementById('comparaisonSujets');
    if (!conteneur) return;

    const marques = data.marques || [];

    if (marques.length === 0) {
        conteneur.innerHTML = '<p class="text-muted text-center">Aucune donnee.</p>';
        return;
    }

    let html = '<div class="row g-3">';

    marques.forEach((m, i) => {
        const sujets = m.sujets_principaux || m.sujets || [];
        const couleur = COULEURS_GRAPHIQUES.serie[i % COULEURS_GRAPHIQUES.serie.length];

        html += `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100" style="border-top: 3px solid ${couleur};">
                    <div class="card-body">
                        <h6 class="card-title">${echapper(m.nom)}</h6>
                        ${sujets.length > 0
                            ? `<ul class="list-unstyled mb-0">
                                ${sujets.slice(0, 8).map(s => `
                                    <li class="d-flex justify-content-between align-items-center mb-1">
                                        <span>${echapper(s.nom || s.label || '')}</span>
                                        <span class="badge bg-light text-dark">${s.frequence || s.count || 0}</span>
                                    </li>
                                `).join('')}
                               </ul>`
                            : '<p class="text-muted small mb-0">Aucun sujet</p>'
                        }
                    </div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    conteneur.innerHTML = html;
}

/**
 * Affiche la comparaison temporelle sous forme de line chart multi-series.
 *
 * @param {object} data Donnees de comparaison
 */
function renderComparaisonTemporelle(data) {
    const canvasId = 'graphiqueComparaisonTemporelle';
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    detruireGraphique(canvasId);

    const temporel = data.temporel || data.evolution_temporelle;
    if (!temporel || !temporel.labels) return;

    const marques = data.marques || [];
    const datasets = marques.map((m, i) => ({
        label: m.nom,
        data: temporel.series?.[m.id] || temporel.series?.[m.nom] || m.evolution || [],
        borderColor: COULEURS_GRAPHIQUES.serie[i % COULEURS_GRAPHIQUES.serie.length],
        backgroundColor: COULEURS_GRAPHIQUES.serie[i % COULEURS_GRAPHIQUES.serie.length] + '20',
        fill: false,
        tension: 0.3,
        pointRadius: 3,
        pointHoverRadius: 6,
        borderWidth: 2,
    }));

    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: temporel.labels,
            datasets: datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index',
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: { color: '#e5e7eb' },
                    title: {
                        display: true,
                        text: 'Score de reputation',
                        font: { weight: '600' },
                    },
                },
                x: {
                    grid: { display: false },
                },
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: { enabled: true },
            },
        },
    });
    canvas._chartInstance = chart;
}

/**
 * Affiche le tableau recapitulatif de comparaison.
 *
 * @param {object} data Donnees de comparaison
 */
function renderComparaisonTableau(data) {
    const corps = document.getElementById('corpsComparaison');
    if (!corps) return;

    const marques = data.marques || [];

    if (marques.length === 0) {
        corps.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Aucune donnee.</td></tr>';
        return;
    }

    corps.innerHTML = marques.map(m => {
        const info = formaterScore(m.score || 0);
        let couleurScore;
        if (info.classe === 'score-high') {
            couleurScore = 'bg-success';
        } else if (info.classe === 'score-mid') {
            couleurScore = 'bg-warning text-dark';
        } else {
            couleurScore = 'bg-danger';
        }

        const sentiments = m.sentiments || {};
        const topSujet = (m.sujets_principaux || m.sujets || [])[0];

        return `
            <tr>
                <td><strong>${echapper(m.nom)}</strong></td>
                <td><span class="badge ${couleurScore}">${info.valeur}</span></td>
                <td>${info.label}</td>
                <td>
                    <span class="text-success">${sentiments.positif || 0}%</span> /
                    <span class="text-muted">${sentiments.neutre || 0}%</span> /
                    <span class="text-danger">${sentiments.negatif || 0}%</span>
                </td>
                <td>${(m.volume || m.nombre_posts || 0).toLocaleString('fr-FR')}</td>
                <td>${topSujet ? echapper(topSujet.nom || topSujet.label || '') : '—'}</td>
                <td>
                    ${m.derniere_analyse_id
                        ? `<a href="${BASE_URL}/resultats.php?analyse_id=${m.derniere_analyse_id}" class="btn btn-sm btn-outline-primary">Voir</a>`
                        : '—'
                    }
                </td>
            </tr>
        `;
    }).join('');
}

/* ==========================================================================
   POINT D'ENTREE
   ========================================================================== */

document.addEventListener('DOMContentLoaded', async () => {
    await chargerChartJs();
    configurerChartJs();

    if (document.querySelector('[data-analyse-id]')) {
        initResultats();
    } else if (document.querySelector('[data-page="comparaison"]')) {
        initComparaison();
    } else {
        initDashboard();
        initFormulaireAnalyse();
        initFormulairesParametres();
    }
});

// --- Help panel collapse ---
function collapserHelpPanel() {
    var panel = document.getElementById('helpPanel');
    if (panel) panel.classList.add('help-hidden');
}
