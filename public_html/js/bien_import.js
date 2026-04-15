/**
 * bien_import.js — Module d'import intelligent de fiches biens
 * MaBoxImmo — v2.0.0
 */
(function () {
  'use strict';

  // ── Config ────────────────────────────────────────────────────
  const _base = (window.__bi_base || '').replace(/\/$/, '');
  const CFG = {
    uploadUrl     : _base + '/api/bien_import_upload.php',
    creerUrl      : _base + '/api/bien_import_creer.php',
    actionUrl     : _base + '/api/bien_import_action.php',
    proprioSearch : _base + '/api/proprietaire_chercher.php',
    proprioCreer  : _base + '/api/proprietaire_creer.php',
    feedbackUrl   : _base + '/api/import_feedback.php',
    csrfToken     : window.__bi_csrf || '',
    maxSizeMo     : 20,
  };

  // ── État modal courant ────────────────────────────────────────
  let _proprioId    = null;
  let _feedbacks    = {};   // { champ: { valeur_extraite, valeur_correcte, qualite } }
  let _logicielSrc  = '';   // détecté dans le PDF (Hektor, PERIZIA…)

  // ── Champs SEO indispensables (affichés en rouge si manquants) ─
  const CHAMPS_SEO = ['type_bien','type_offre','ville','code_postal','surface_habitable','designation'];

  const CHAMPS_LABELS = {
    type_offre:'Type d\'offre', type_bien:'Type de bien',
    surface_habitable:'Surface hab. (m²)', surface_terrain:'Surface terrain (m²)',
    surface_carrez:'Surface Carrez (m²)', prix_vente:'Prix de vente (€)',
    loyer:'Loyer (€/mois)', charges_loc:'Charges (€)',
    adresse_1:'Adresse', ville:'Ville', code_postal:'Code postal',
    quartier:'Quartier', nb_pieces:'Pièces', nb_chambres:'Chambres',
    etage:'Étage', annee_construction:'Année construction',
    reference_bien:'Référence', designation:'Désignation commerciale',
    description:'Description annonce',
  };

  const LABELS_TYPE_BIEN = {
    appartement:'Appartement', maison:'Maison', villa:'Villa', terrain:'Terrain',
    local_commercial:'Local comm.', bureau:'Bureau', parking:'Parking', garage:'Garage',
    immeuble:'Immeuble', loft:'Loft', atelier:'Atelier', boutique:'Boutique',
  };
  const LABELS_OFFRE = { vente:'🤝 Vente', location:'🔑 Location' };
  const LABELS_SCORE = [
    [80, 'bi-score-hi',  '🟢'],
    [55, 'bi-score-med', '🟡'],
    [0,  'bi-score-lo',  '🔴'],
  ];

  // ── État ─────────────────────────────────────────────────────
  let state = {
    fichiers    : [],
    importId    : null,
    detailIndex : null,
  };

  // ── DOM ───────────────────────────────────────────────────────
  let dom = {};

  // ════════════════════════════════════════════════════════════
  // INIT
  // ════════════════════════════════════════════════════════════
  function init() {
    dom.trigger      = document.getElementById('bi-trigger');
    dom.panel        = document.getElementById('bi-panel');
    dom.input        = document.getElementById('bi-file-input');
    dom.list         = document.getElementById('bi-list');
    dom.listWrap     = document.getElementById('bi-list-wrap');
    dom.modal        = document.getElementById('bi-modal');
    dom.modalClose   = document.getElementById('bi-modal-close');
    dom.modalContent = document.getElementById('bi-modal-content');
    dom.modalCreate  = document.getElementById('bi-modal-create');
    dom.modalFill    = document.getElementById('bi-modal-fill');

    if (!dom.trigger) return;

    // Clic bouton → ouvre directement l'explorateur
    dom.trigger.addEventListener('click', () => dom.input.click());
    dom.input.addEventListener('change', () => handleFiles(dom.input.files));

    // Drag & drop sur la zone trigger elle-même (optionnel)
    dom.trigger.addEventListener('dragover', e => { e.preventDefault(); dom.trigger.classList.add('drag-over'); });
    dom.trigger.addEventListener('dragleave', () => dom.trigger.classList.remove('drag-over'));
    dom.trigger.addEventListener('drop', e => {
      e.preventDefault();
      dom.trigger.classList.remove('drag-over');
      handleFiles(e.dataTransfer.files);
    });

    // Modal
    dom.modalClose.addEventListener('click', closeModal);
    dom.modal.addEventListener('click', e => { if (e.target === dom.modal) closeModal(); });
    dom.modalCreate.addEventListener('click', submitCreate);
    dom.modalFill.addEventListener('click', submitFill);
  }

  // ════════════════════════════════════════════════════════════
  // UPLOAD & ANALYSE
  // ════════════════════════════════════════════════════════════
  function handleFiles(fileList) {
    if (!fileList || fileList.length === 0) return;

    const valid = [...fileList].filter(f => {
      const ext = f.name.split('.').pop().toLowerCase();
      if (ext !== 'pdf') { showToast('⚠️ ' + f.name + ' : format non supporté (PDF uniquement)', 'warn'); return false; }
      if (f.size > CFG.maxSizeMo * 1024 * 1024) { showToast('⚠️ ' + f.name + ' : fichier trop volumineux (max 20 Mo)', 'warn'); return false; }
      return true;
    });
    if (!valid.length) return;

    valid.forEach(f => {
      state.fichiers.push({ nom: f.name, statut: 'analysing', score: null, fichier_id: null, _local: true });
    });
    renderList();
    dom.panel.classList.add('bi-panel-open');

    const fd = new FormData();
    valid.forEach(f => fd.append('fichiers[]', f));
    fd.append('csrf_token', CFG.csrfToken);

    fetch(CFG.uploadUrl, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) { showToast('❌ Erreur : ' + data.error, 'error'); return; }
        state.importId = data.import_id;
        state.fichiers = state.fichiers.filter(f => !f._local);
        data.resultats.forEach(r => state.fichiers.push(r));
        renderList();
        // Si un seul fichier et qu'il est analysé → ouvrir auto la modale
        if (data.resultats.length === 1 && data.resultats[0].statut !== 'error') {
          BiImport.verifie(state.fichiers.length - 1);
        }
      })
      .catch(err => {
        state.fichiers = state.fichiers.filter(f => !f._local);
        renderList();
        showToast('❌ Erreur réseau : ' + err.message, 'error');
      });

    dom.input.value = '';
  }

  // ════════════════════════════════════════════════════════════
  // LISTE RÉSULTATS (compacte)
  // ════════════════════════════════════════════════════════════
  function renderList() {
    if (!state.fichiers.length) { dom.panel.classList.remove('bi-panel-open'); return; }

    const rows = state.fichiers.map((f, i) => {
      if (f.statut === 'analysing') return `<tr>
        <td colspan="7"><span class="bi-spinner"></span> Analyse de <em>${esc(truncate(f.nom, 40))}</em>…</td>
      </tr>`;

      const scoreInfo   = scoreStyle(f.score);
      const statusBadge = statusLabel(f.statut);
      const manq        = (f.manquants || []).length;
      const typeBien    = LABELS_TYPE_BIEN[f.type_bien] || f.type_bien || '—';
      const offre       = f.type_offre ? (LABELS_OFFRE[f.type_offre] || f.type_offre) : '—';
      const isIgnored   = f.statut === 'ignored';
      const isCreated   = f.statut === 'created';

      return `<tr>
        <td title="${esc(f.nom)}">${esc(truncate(f.nom, 26))}</td>
        <td>${statusBadge}</td>
        <td><span class="bi-score ${scoreInfo[0]}">${scoreInfo[1]} ${f.score ?? '—'}%</span></td>
        <td>${esc(typeBien)} — ${offre}</td>
        <td class="${manq > 0 ? 'bi-warn' : 'bi-ok'}">${manq > 0 ? '⚠️ '+manq+' manquant(s)' : '✅ complet'}</td>
        <td>
          ${!isCreated && !isIgnored ? `
            <button class="bi-action-btn primary" style="padding:4px 9px;font-size:11px;" onclick="BiImport.verifie(${i})">🔍 Vérifier</button>
            <button class="bi-action-btn success" style="padding:4px 9px;font-size:11px;" onclick="BiImport.creeDirectement(${i})">⚡ Créer</button>
            <button class="bi-action-btn danger"  style="padding:4px 9px;font-size:11px;" onclick="BiImport.ignore(${i})">✕</button>
          ` : ''}
          ${isCreated ? '<span class="bi-badge ready">✅ Créé</span>' : ''}
          ${isIgnored ? `<button class="bi-action-btn secondary" style="padding:4px 9px;font-size:11px;" onclick="BiImport.reinit(${i})">↩</button>` : ''}
        </td>
      </tr>`;
    }).join('');

    dom.list.innerHTML = `
      <table class="bi-table">
        <thead><tr>
          <th>Fichier</th><th>Statut</th><th>Score</th>
          <th>Type / Offre</th><th>Complétude</th><th>Actions</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`;
  }

  // ════════════════════════════════════════════════════════════
  // MODAL VÉRIFICATION
  // ════════════════════════════════════════════════════════════
  function openModal()  { dom.modal.classList.add('bi-modal-open'); }
  function closeModal() { dom.modal.classList.remove('bi-modal-open'); }

  window.BiImport = {

    verifie(index) {
      const f = state.fichiers[index];
      if (!f) return;
      state.detailIndex = index;
      renderModal(f);
      openModal();
    },

    ignore(index) {
      const f = state.fichiers[index];
      if (!f?.fichier_id) return;
      postAction('ignorer', f.fichier_id)
        .then(() => { state.fichiers[index].statut = 'ignored'; renderList(); });
    },

    reinit(index) {
      const f = state.fichiers[index];
      if (!f?.fichier_id) return;
      postAction('reinit', f.fichier_id)
        .then(() => { state.fichiers[index].statut = 'analysed'; renderList(); });
    },

    creeDirectement(index) {
      const f = state.fichiers[index];
      if (!f?.fichier_id) return;
      if (!confirm('Créer ce bien directement avec les données détectées ?')) return;
      creerBien(f.fichier_id, {}, index);
    },
  };

  function renderModal(f) {
    const champs    = f.champs   || {};
    const deduits   = f.deduits  || {};
    const manquants = f.manquants || [];
    _proprioId      = null;
    _feedbacks      = {};
    // Détection logiciel source dans le nom du fichier ou des données
    _logicielSrc = detectLogiciel(f.nom || '');

    const seoManquants = CHAMPS_SEO.filter(k => {
      const val = champs[k]?.valeur;
      return !val && val !== 0;
    });

    const blocSeo       = renderBlocSeoManquants(champs, seoManquants);
    const blocRemplis   = renderBlocRemplis(champs, seoManquants);
    const blocDeduits   = renderBlocDeduits(deduits);
    const blocDesc      = f.description ? `
      <div class="bi-bloc">
        <div class="bi-bloc-title">✏️ Description pour publication — à retravailler</div>
        <textarea id="bi-desc-edit" class="bi-desc-textarea" rows="5">${esc(f.description)}</textarea>
      </div>` : '';

    const blocReprise   = f.reprise_descriptif ? renderBlocRepriseDescriptif(f.reprise_descriptif) : '';
    const blocPhotos    = renderBlocPhotos(f.photos || []);
    const blocFeedback  = renderBlocFeedback(champs, f);

    const blocProprio = renderBlocProprietaireShell(f.proprietaire || {});

    const scoreInfo = scoreStyle(f.score);
    dom.modalContent.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
        <strong style="flex:1;">${esc(f.nom)}</strong>
        <span class="bi-score ${scoreInfo[0]}">${scoreInfo[1]} ${f.score ?? '—'}%</span>
      </div>
      ${blocSeo}
      ${blocRemplis}
      ${blocDeduits}
      ${blocReprise}
      ${blocDesc}
      ${blocPhotos}
      ${blocProprio}
      ${blocFeedback}
    `;

    dom.modalCreate.disabled = false;
    dom.modalCreate.dataset.index = state.fichiers.indexOf(f);
    dom.modalFill.dataset.index   = state.fichiers.indexOf(f);

    // Lance la recherche propriétaire automatiquement après rendu
    searchProprietaire(f.proprietaire || {});
  }

  // ── Bloc champs SEO manquants (rouge) ─────────────────────────
  function renderBlocSeoManquants(champs, seoManquants) {
    if (!seoManquants.length) return `
      <div class="bi-bloc" style="border-color:#48c78e;">
        <div class="bi-bloc-title" style="color:#48c78e;">✅ Tous les champs SEO indispensables sont renseignés</div>
      </div>`;

    const items = seoManquants.map(k => {
      const label = CHAMPS_LABELS[k] || k;
      const type  = fieldInputType(k);
      if (k === 'type_offre') return `<div class="bi-field-item">
        <label>${label} <span style="color:#ff5a5a;">*</span></label>
        <select name="${k}" class="bi-seo-input">
          <option value="">— choisir —</option>
          <option value="vente">Vente</option>
          <option value="location">Location</option>
        </select>
      </div>`;
      return `<div class="bi-field-item">
        <label>${label} <span style="color:#ff5a5a;">*</span></label>
        <input class="bi-seo-input" name="${k}" type="${type}" placeholder="${label}">
      </div>`;
    }).join('');

    return `<div class="bi-bloc" style="border-color:#ff5a5a;background:rgba(255,90,90,.05);">
      <div class="bi-bloc-title" style="color:#ff5a5a;">⚠️ Champs SEO indispensables manquants — à compléter</div>
      <div class="bi-field-row">${items}</div>
    </div>`;
  }

  // ── Bloc champs remplis automatiquement (tous éditables) ──────
  function renderBlocRemplis(champs, seoManquants) {
    const entries = Object.entries(champs).filter(([k]) => !seoManquants.includes(k));
    if (!entries.length) return '';

    const items = entries.map(([k, v]) => {
      const label = CHAMPS_LABELS[k] || k;
      const val   = String(v.valeur ?? '');
      const conf  = v.score || 'moyen';
      const border = conf === 'faible' ? 'border-color:rgba(255,183,0,.6);' : '';
      return `<div class="bi-field-item">
        <label style="display:flex;justify-content:space-between;">
          <span>${esc(label)}</span>
          <span title="Confiance : ${conf}">${confIcon(conf)}</span>
        </label>
        <input class="bi-field-input" name="${k}" value="${esc(val)}"
               type="${fieldInputType(k)}" style="${border}"
               ${conf === 'faible' ? 'title="Confiance faible — à vérifier"' : ''}>
      </div>`;
    }).join('');

    return `<div class="bi-bloc" style="border-color:#48c78e;">
      <div class="bi-bloc-title" style="color:#48c78e;">✅ Champs remplis automatiquement — modifiables</div>
      <div class="bi-field-row">${items}</div>
    </div>`;
  }

  // ── Détection logiciel source ─────────────────────────────────
  function detectLogiciel(nomFichier) {
    const n = nomFichier.toLowerCase();
    if (n.includes('hektor'))  return 'Hektor';
    if (n.includes('perizia')) return 'PERIZIA';
    if (n.includes('netty'))   return 'Netty';
    if (n.includes('apimo'))   return 'Apimo';
    if (n.includes('ubiflow')) return 'Ubiflow';
    return '';
  }

  // ── Bloc reprise descriptif intégral (supprimable) ────────────
  function renderBlocRepriseDescriptif(texte) {
    return `<div class="bi-bloc" id="bi-reprise-bloc" style="border-color:#9b59b6;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <div class="bi-bloc-title" style="color:#c07dff;margin-bottom:0;">📋 Descriptif original — extrait du PDF</div>
        <div style="display:flex;gap:6px;">
          <button type="button" class="bi-action-btn primary" style="font-size:11px;padding:4px 10px;"
                  onclick="BiImport.copierVersDescription()">📋 Copier vers Description</button>
          <button type="button" class="bi-action-btn danger" style="font-size:11px;padding:4px 10px;"
                  onclick="BiImport.supprimerReprise()" title="Supprimer ce descriptif (incorrect)">✕ Supprimer</button>
        </div>
      </div>
      <div style="margin-bottom:8px;font-size:11px;color:var(--muted);">
        Texte brut. Si incorrect → supprimez-le. Sinon copiez-le et retravaillez-le dans le champ publication.
      </div>
      <textarea id="bi-reprise-display" class="bi-desc-textarea" rows="6" readonly
                style="border-color:rgba(155,89,182,.3);color:#ddd;cursor:default;resize:vertical;">${esc(texte)}</textarea>
      <input type="hidden" id="bi-reprise-val" value="${esc(texte)}">
    </div>`;
  }

  // ── Bloc photos extraites du PDF ─────────────────────────────
  function renderBlocPhotos(photos) {
    if (!photos || !photos.length) return '';

    const thumbs = photos.map((p, i) => {
      const url = _base + '/' + p;
      return `<div style="position:relative;display:inline-block;">
        <img src="${esc(url)}" alt="Photo ${i+1}" loading="lazy"
             style="width:140px;height:100px;object-fit:cover;border-radius:8px;border:2px solid rgba(255,255,255,.12);cursor:pointer;"
             onclick="window.open('${esc(url)}','_blank')" title="Cliquer pour agrandir">
        <div style="position:absolute;bottom:4px;left:4px;background:rgba(0,0,0,.65);border-radius:4px;font-size:10px;padding:1px 5px;color:#eee;">
          ${i+1}
        </div>
      </div>`;
    }).join('');

    return `<div class="bi-bloc" style="border-color:#48c78e;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <div class="bi-bloc-title" style="color:#48c78e;margin-bottom:0;">🖼️ Photos extraites du PDF (${photos.length})</div>
        <div style="font-size:11px;color:var(--muted);">Cliquer pour agrandir — elles seront importées avec le bien</div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">${thumbs}</div>
    </div>`;
  }

  // ── Bloc notation qualité extraction ─────────────────────────
  function renderBlocFeedback(champs, f) {
    const entries = Object.entries(champs);
    if (!entries.length) return '';

    const rows = entries.map(([k, v]) => {
      const label = CHAMPS_LABELS[k] || k;
      const val   = esc(String(v.valeur ?? ''));
      return `<tr data-champ="${k}" data-extrait="${val}">
        <td style="font-size:12px;padding:4px 8px;color:var(--muted);">${esc(label)}</td>
        <td style="font-size:12px;padding:4px 8px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${val}">${val || '<em style="color:#666">vide</em>'}</td>
        <td style="padding:4px 8px;">
          <div style="display:flex;gap:4px;">
            <button type="button" class="bi-fb-btn bi-fb-ok"  onclick="BiImport.noterChamp(this,'ok')"      title="Correct">✅</button>
            <button type="button" class="bi-fb-btn bi-fb-bad" onclick="BiImport.noterChamp(this,'mauvais')" title="Mauvaise valeur">❌</button>
            <button type="button" class="bi-fb-btn bi-fb-mis" onclick="BiImport.noterChamp(this,'manquant')" title="Valeur manquante">⚠️</button>
          </div>
        </td>
        <td style="padding:4px 8px;">
          <input class="bi-fb-corr" type="text" placeholder="valeur correcte"
                 style="display:none;font-size:11px;padding:3px 6px;border-radius:5px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.07);color:#eee;width:130px;">
        </td>
      </tr>`;
    }).join('');

    return `<div class="bi-bloc" style="border-color:#ffb700;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <div class="bi-bloc-title" style="color:#ffb700;margin-bottom:0;">⭐ Notez la qualité de l'extraction</div>
        <div style="display:flex;align-items:center;gap:8px;">
          <select id="bi-fb-logiciel" style="font-size:11px;padding:3px 8px;border-radius:6px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.07);color:#eee;">
            <option value="">Logiciel source…</option>
            <option value="Hektor"   ${_logicielSrc==='Hektor'   ?'selected':''}>Hektor</option>
            <option value="PERIZIA"  ${_logicielSrc==='PERIZIA'  ?'selected':''}>PERIZIA</option>
            <option value="Netty"    ${_logicielSrc==='Netty'    ?'selected':''}>Netty</option>
            <option value="Apimo"    ${_logicielSrc==='Apimo'    ?'selected':''}>Apimo</option>
            <option value="Ubiflow"  ${_logicielSrc==='Ubiflow'  ?'selected':''}>Ubiflow</option>
            <option value="Autre">Autre</option>
          </select>
          <button type="button" class="bi-action-btn primary" style="font-size:11px;padding:4px 10px;"
                  onclick="BiImport.envoyerFeedback(${f.fichier_id||0},'${esc(f.nom||'')}')">
            💾 Envoyer les notes
          </button>
        </div>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:10px;">
        Ces retours m'aident à améliorer la détection pour les prochaines fiches du même logiciel.
      </div>
      <table style="width:100%;border-collapse:collapse;" id="bi-fb-table">
        <thead><tr style="border-bottom:1px solid rgba(255,255,255,.08);">
          <th style="text-align:left;font-size:11px;color:var(--muted);padding:4px 8px;">Champ</th>
          <th style="text-align:left;font-size:11px;color:var(--muted);padding:4px 8px;">Extrait</th>
          <th style="font-size:11px;color:var(--muted);padding:4px 8px;">Qualité</th>
          <th style="font-size:11px;color:var(--muted);padding:4px 8px;">Correction</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
  }

  // ── Bloc propriétaire — shell initial (rempli par AJAX) ──────
  function renderBlocProprietaireShell(proprio) {
    const detected = [];
    if (proprio.nom)       detected.push(`<strong>${esc(proprio.prenom || '')} ${esc(proprio.nom)}</strong>`);
    if (proprio.telephone) detected.push(`📞 ${esc(proprio.telephone)}`);
    if (proprio.email)     detected.push(`✉️ ${esc(proprio.email)}`);

    return `<div class="bi-bloc" id="bi-proprio-bloc" style="border-color:#66d9ff;">
      <div class="bi-bloc-title" style="color:#66d9ff;">👤 Propriétaire</div>
      ${detected.length ? `<div style="margin-bottom:10px;font-size:13px;">Détecté dans la fiche : ${detected.join(' — ')}</div>` : '<div style="margin-bottom:10px;font-size:12px;color:var(--muted);">Aucun propriétaire détecté dans la fiche.</div>'}
      <div id="bi-proprio-results" style="margin-bottom:12px;"></div>
      <div id="bi-proprio-form" style="display:none;">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px;">Créer un nouveau propriétaire</div>
        <div class="bi-field-row">
          <div class="bi-field-item" style="min-width:80px;max-width:80px;">
            <label>Civilité</label>
            <select class="bi-proprio-input" name="civilite">
              <option value="">—</option>
              <option value="M." ${(proprio.civilite||'') === 'M.' ? 'selected':''}>M.</option>
              <option value="Mme" ${(proprio.civilite||'') === 'Mme' ? 'selected':''}>Mme</option>
            </select>
          </div>
          <div class="bi-field-item">
            <label>Nom *</label>
            <input class="bi-proprio-input" name="nom" value="${esc(proprio.nom||'')}" placeholder="NOM" required>
          </div>
          <div class="bi-field-item">
            <label>Prénom</label>
            <input class="bi-proprio-input" name="prenom" value="${esc(proprio.prenom||'')}" placeholder="Prénom">
          </div>
        </div>
        <div class="bi-field-row" style="margin-top:8px;">
          <div class="bi-field-item">
            <label>Téléphone</label>
            <input class="bi-proprio-input" name="telephone" value="${esc(proprio.telephone||'')}" placeholder="06 xx xx xx xx">
          </div>
          <div class="bi-field-item">
            <label>Email</label>
            <input class="bi-proprio-input" name="email" type="email" value="${esc(proprio.email||'')}" placeholder="email@exemple.fr">
          </div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
          <button type="button" class="bi-action-btn success" onclick="BiImport.validerProprietaire()">✅ Valider la création</button>
          <button type="button" class="bi-action-btn secondary" style="font-size:11px;" onclick="BiImport.annulerProprietaire()">Annuler</button>
        </div>
      </div>
    </div>`;
  }

  // ── Recherche AJAX propriétaire ──────────────────────────────
  function searchProprietaire(proprio) {
    const params = new URLSearchParams();
    if (proprio.nom)       params.set('nom',       proprio.nom);
    if (proprio.prenom)    params.set('prenom',     proprio.prenom);
    if (proprio.email)     params.set('email',      proprio.email);
    if (proprio.telephone) params.set('telephone',  proprio.telephone);

    if (!params.toString()) {
      renderProprioResults([]);
      return;
    }

    const el = document.getElementById('bi-proprio-results');
    if (el) el.innerHTML = '<span style="font-size:12px;color:var(--muted);">🔍 Recherche en cours…</span>';

    fetch(CFG.proprioSearch + '?' + params.toString())
      .then(r => r.json())
      .then(data => {
        if (data.ok) renderProprioResults(data.resultats || []);
      })
      .catch(() => { if (el) el.innerHTML = ''; });
  }

  function renderProprioResults(resultats) {
    const el = document.getElementById('bi-proprio-results');
    if (!el) return;

    if (resultats.length === 0) {
      el.innerHTML = `<div style="font-size:12px;color:var(--muted);margin-bottom:8px;">Aucun propriétaire trouvé dans la base.</div>
        <button type="button" class="bi-action-btn primary" style="font-size:12px;" onclick="BiImport.ouvrirFormulaireProprietaire()">+ Créer un propriétaire</button>`;
      return;
    }

    const cards = resultats.map(p => {
      const label = [p.prenom, p.nom].filter(Boolean).join(' ') || p.societe || '—';
      const detail = [p.telephone, p.email, p.ville].filter(Boolean).join(' · ');
      return `<div class="bi-proprio-card" id="bi-pc-${p.id}">
        <div style="flex:1;">
          <strong style="font-size:13px;">${esc(label)}</strong>
          ${detail ? `<div style="font-size:11px;color:var(--muted);margin-top:2px;">${esc(detail)}</div>` : ''}
        </div>
        <button type="button" class="bi-action-btn success" style="font-size:11px;white-space:nowrap;"
                onclick="BiImport.lierProprietaire(${p.id}, '${esc(label).replace(/'/g,"\\'")}')">
          🔗 Lier
        </button>
      </div>`;
    }).join('');

    el.innerHTML = `<div style="font-size:12px;color:#48c78e;font-weight:600;margin-bottom:8px;">Correspondances trouvées :</div>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px;">${cards}</div>
      <button type="button" class="bi-action-btn secondary" style="font-size:11px;" onclick="BiImport.ouvrirFormulaireProprietaire()">+ Créer un nouveau propriétaire</button>`;
  }

  // ── Actions publiques propriétaire ──────────────────────────
  Object.assign(window.BiImport, {

    noterDeduit(btn, qualite, itemId) {
      const row = document.getElementById('bdd-row-' + itemId);
      if (!row) return;
      row.querySelectorAll('.bi-fb-btn').forEach(b => { b.style.opacity='0.35'; b.style.outline=''; });
      btn.style.opacity = '1';
      btn.style.outline = '2px solid currentColor';
      const extrait = row.dataset.extrait || '';
      _feedbacks['deduit__' + itemId] = { valeur_extraite: extrait, valeur_correcte: '', qualite };
      // Si ❌ → barrer visuellement
      const chip = document.getElementById('bdd-chip-' + itemId);
      if (chip) chip.style.textDecoration = qualite === 'mauvais' ? 'line-through' : '';
    },

    supprimerDeduit(itemId, valeur, groupe) {
      const row = document.getElementById('bdd-row-' + itemId);
      if (row) row.remove();
      // Stocker dans feedbacks comme "mauvais" + dans les suppressions
      _feedbacks['deduit__' + itemId] = { valeur_extraite: valeur, valeur_correcte: '', qualite: 'mauvais' };
      // Marquer pour exclure de l'injection
      if (!state._deduitsSupprime) state._deduitsSupprime = new Set();
      state._deduitsSupprime.add(itemId);
      showToast(`🗑️ "${valeur}" supprimé`, 'ok', 2000);
    },

    supprimerReprise() {
      const bloc = document.getElementById('bi-reprise-bloc');
      const val  = document.getElementById('bi-reprise-val');
      if (bloc) bloc.remove();
      if (val)  val.value = '';
      // Marquer le champ reprise_descriptif comme à vider
      _feedbacks['reprise_descriptif'] = { valeur_extraite: val?.value || '', valeur_correcte: '', qualite: 'mauvais' };
      showToast('🗑️ Descriptif supprimé — il ne sera pas enregistré', 'ok');
    },

    noterChamp(btn, qualite) {
      const row   = btn.closest('tr[data-champ]');
      if (!row) return;
      const champ   = row.dataset.champ;
      const extrait = row.dataset.extrait || '';
      // Highlight bouton actif
      row.querySelectorAll('.bi-fb-btn').forEach(b => b.style.opacity = '0.35');
      btn.style.opacity = '1';
      btn.style.outline = '2px solid currentColor';
      // Montrer le champ correction si mauvais
      const corrInput = row.querySelector('.bi-fb-corr');
      if (corrInput) corrInput.style.display = qualite !== 'ok' ? 'inline-block' : 'none';
      // Stocker
      _feedbacks[champ] = {
        valeur_extraite: extrait,
        valeur_correcte: corrInput?.value || '',
        qualite,
      };
      // Écoute corrections
      if (corrInput) {
        corrInput.oninput = () => { _feedbacks[champ].valeur_correcte = corrInput.value; };
      }
    },

    envoyerFeedback(fichierId, nomFiche) {
      const fbList = Object.entries(_feedbacks).map(([champ, fb]) => ({
        champ,
        valeur_extraite : fb.valeur_extraite,
        valeur_correcte : fb.valeur_correcte || '',
        qualite         : fb.qualite,
      }));
      if (!fbList.length) { showToast('Aucune note à envoyer.', 'warn'); return; }

      const logiciel = document.getElementById('bi-fb-logiciel')?.value || _logicielSrc || '';

      fetch(CFG.feedbackUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          fichier_id: fichierId,
          nom_fiche: nomFiche,
          logiciel_source: logiciel,
          feedbacks: fbList,
          csrf_token: CFG.csrfToken,
        }),
      })
        .then(r => r.json())
        .then(res => {
          if (res.ok) {
            const ok  = fbList.filter(f => f.qualite === 'ok').length;
            const bad = fbList.filter(f => f.qualite !== 'ok').length;
            showToast(`✅ ${res.nb} notes enregistrées — ${ok} ✅ corrects, ${bad} ❌/⚠️ à améliorer`, 'ok', 5000);
          } else {
            showToast('❌ ' + (res.error || 'Erreur'), 'error');
          }
        })
        .catch(() => showToast('❌ Erreur réseau', 'error'));
    },

    copierVersDescription() {
      const src  = document.getElementById('bi-reprise-val');
      const dest = document.getElementById('bi-desc-edit');
      if (src && dest) {
        dest.value = src.value;
        dest.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        dest.focus();
        showToast('✅ Descriptif copié — vous pouvez le retravailler', 'ok');
      }
    },

    lierProprietaire(id, label) {
      _proprioId = id;
      const el = document.getElementById('bi-proprio-results');
      if (el) el.innerHTML = `<div style="background:rgba(72,199,142,.12);border:1px solid rgba(72,199,142,.4);border-radius:8px;padding:10px 14px;font-size:13px;">
        ✅ <strong>${esc(label)}</strong> sélectionné
        <button type="button" onclick="BiImport.deselectionnerProprietaire()" style="background:none;border:none;color:var(--muted);cursor:pointer;margin-left:8px;font-size:11px;">✕ Changer</button>
      </div>`;
      const form = document.getElementById('bi-proprio-form');
      if (form) form.style.display = 'none';
    },

    deselectionnerProprietaire() {
      _proprioId = null;
      const el = document.getElementById('bi-proprio-results');
      if (el) el.innerHTML = '<button type="button" class="bi-action-btn secondary" style="font-size:11px;" onclick="BiImport.ouvrirFormulaireProprietaire()">+ Créer un propriétaire</button>';
    },

    ouvrirFormulaireProprietaire() {
      const form = document.getElementById('bi-proprio-form');
      if (form) { form.style.display = 'block'; form.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    },

    annulerProprietaire() {
      const form = document.getElementById('bi-proprio-form');
      if (form) form.style.display = 'none';
    },

    validerProprietaire() {
      const form = document.getElementById('bi-proprio-form');
      if (!form) return;
      const data = { csrf_token: CFG.csrfToken };
      form.querySelectorAll('.bi-proprio-input').forEach(inp => {
        if (inp.name) data[inp.name] = inp.value.trim();
      });
      if (!data.nom) { alert('Le nom est obligatoire.'); return; }

      const btn = form.querySelector('.bi-action-btn.success');
      if (btn) { btn.disabled = true; btn.textContent = '⏳…'; }

      fetch(CFG.proprioCreer, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      })
        .then(r => r.json())
        .then(res => {
          if (res.ok) {
            BiImport.lierProprietaire(res.id, res.nom);
            form.style.display = 'none';
            showToast(res.existant ? '🔗 Propriétaire existant sélectionné (doublon évité)' : '✅ Propriétaire créé et lié !', 'ok');
          } else {
            showToast('❌ ' + (res.error || 'Erreur'), 'error');
            if (btn) { btn.disabled = false; btn.textContent = '✅ Valider la création'; }
          }
        })
        .catch(() => {
          showToast('❌ Erreur réseau', 'error');
          if (btn) { btn.disabled = false; btn.textContent = '✅ Valider la création'; }
        });
    },
  });

  // ── Aplatit les déduits en liste { clé, groupe, valeur_affichée } ──
  function flattenDeduits(deduits) {
    const items = [];
    if (deduits.dependances) {
      Object.keys(deduits.dependances).forEach(k =>
        items.push({ id: 'dep__' + k, groupe: 'Dépendances', label: k.replace(/_/g,' '), valeur: k })
      );
    }
    (deduits.chauffage || []).forEach(c =>
      items.push({ id: 'ch__' + c, groupe: 'Chauffage', label: c.replace(/_/g,' '), valeur: c })
    );
    (deduits.vues || []).forEach(v =>
      items.push({ id: 've__' + v, groupe: 'Vues', label: v.replace(/_/g,' '), valeur: v })
    );
    ['centre_ville','calme','ascenseur','renove','meuble','parquet','cuisine_equipee','double_vitrage',
     'digicode','residence'].forEach(k => {
      if (deduits[k]) items.push({ id: 'fl__' + k, groupe: 'Caractéristiques', label: k.replace(/_/g,' '), valeur: k });
    });
    return items;
  }

  function renderBlocDeduits(deduits) {
    const items = flattenDeduits(deduits);
    if (!items.length) return '';

    const rows = items.map(item => `
      <tr id="bdd-row-${item.id}" data-champ="deduit__${item.id}" data-extrait="${esc(item.valeur)}">
        <td style="font-size:11px;padding:3px 8px;color:var(--muted);">${esc(item.groupe)}</td>
        <td style="font-size:12px;padding:3px 8px;">
          <span class="bi-chip bi-chip-info" id="bdd-chip-${item.id}">${esc(item.label)}</span>
        </td>
        <td style="padding:3px 8px;">
          <div style="display:flex;gap:4px;align-items:center;">
            <button type="button" class="bi-fb-btn" onclick="BiImport.noterDeduit(this,'ok','${item.id}')"      title="Correct">✅</button>
            <button type="button" class="bi-fb-btn" onclick="BiImport.noterDeduit(this,'mauvais','${item.id}')" title="Faux — à supprimer">❌</button>
            <button type="button" class="bi-action-btn danger" style="font-size:10px;padding:2px 7px;margin-left:4px;"
                    onclick="BiImport.supprimerDeduit('${item.id}','${esc(item.valeur)}','${esc(item.groupe)}')" title="Supprimer">✕</button>
          </div>
        </td>
      </tr>`).join('');

    return `<div class="bi-bloc" id="bi-deduits-bloc" style="border-color:#c07dff;">
      <div class="bi-bloc-title" style="color:#c07dff;">💡 Champs déduits du descriptif — à vérifier</div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:10px;">
        Ces valeurs ont été détectées dans le texte. Notez ✅/❌ ou ✕ supprimez les erronées — elles ne seront pas injectées.
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <tbody>${rows}</tbody>
      </table>
    </div>`;
  }

  // ════════════════════════════════════════════════════════════
  // ACTIONS MODAL
  // ════════════════════════════════════════════════════════════

  // ⚡ Créer directement via API
  function submitCreate() {
    const idx = parseInt(dom.modalCreate.dataset.index ?? '-1');
    const f = state.fichiers[idx];
    if (!f?.fichier_id) return;

    const overrides = collectOverrides();
    dom.modalCreate.disabled = true;
    dom.modalCreate.textContent = '⏳ Création…';
    creerBien(f.fichier_id, overrides, idx);
  }

  // 📝 Compléter le formulaire → injecte dans la page et ferme
  function submitFill() {
    const idx = parseInt(dom.modalFill.dataset.index ?? '-1');
    const f = state.fichiers[idx];
    if (!f) return;

    const overrides = collectOverrides();
    // Fusionne champs API + overrides utilisateur
    const allChamps = {};
    Object.entries(f.champs || {}).forEach(([k, v]) => { allChamps[k] = String(v.valeur ?? ''); });
    Object.entries(overrides).forEach(([k, v]) => { allChamps[k] = v; });
    if (f.description && !allChamps.description) allChamps.description = f.description;

    injectIntoForm(allChamps, f.deduits || {});
    closeModal();
    showToast('✅ Formulaire pré-rempli — complétez les détails puis soumettez', 'ok', 5000);
    // Ferme le panel import et scroll vers le formulaire
    dom.panel.classList.remove('bi-panel-open');
    const firstCard = document.querySelector('.ba-card');
    if (firstCard) firstCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function collectOverrides() {
    const overrides = {};
    dom.modalContent.querySelectorAll('.bi-field-input, .bi-seo-input').forEach(inp => {
      if (inp.name && inp.value.trim() !== '') overrides[inp.name] = inp.value.trim();
    });
    const descEl = document.getElementById('bi-desc-edit');
    if (descEl && descEl.value.trim()) overrides['description'] = descEl.value.trim();
    // reprise_descriptif = texte original PDF (toujours stocker si présent)
    const repriseEl = document.getElementById('bi-reprise-val');
    if (repriseEl && repriseEl.value.trim()) {
      overrides['reprise_descriptif'] = repriseEl.value.trim();
      // Si description non renseignée dans la modale → copier le descriptif intégral
      if (!overrides['description']) overrides['description'] = repriseEl.value.trim();
    }
    if (_proprioId) overrides['id_proprietaire'] = String(_proprioId);
    // Photos extraites : récupérées depuis l'état courant
    const f = state.fichiers[state.detailIndex];
    if (f && f.photos && f.photos.length) overrides['_photos'] = f.photos;
    return overrides;
  }

  // ════════════════════════════════════════════════════════════
  // INJECTION DANS LE FORMULAIRE PRINCIPAL
  // ════════════════════════════════════════════════════════════
  function injectIntoForm(champs, deduits) {
    Object.entries(champs).forEach(([k, v]) => {
      if (!v && v !== 0) return;
      const val = String(v);

      // Cas spéciaux
      if (k === 'type_bien') {
        // Clic sur la carte correspondante
        const card = document.querySelector(`.type-card[data-value="${CSS.escape(val)}"]`);
        if (card) { card.click(); }
        const hidden = document.getElementById('type_bien_hidden');
        if (hidden) hidden.value = val;
        return;
      }
      if (k === 'type_offre') {
        const sel = document.querySelector('[name="annonce_transaction"]');
        if (sel) sel.value = val;
        return;
      }
      if (k === 'description') {
        const el = document.querySelector('[name="description"]');
        if (el && !el.value) el.value = val;
        return;
      }
      if (k === 'reprise_descriptif') {
        // Injecte dans reprise_descriptif ET dans description si vide
        const elR = document.querySelector('[name="reprise_descriptif"]');
        if (elR) elR.value = val;
        const elD = document.querySelector('[name="description"]');
        if (elD && !elD.value) elD.value = val;
        return;
      }
      if (k === 'prix_vente') {
        const el = document.querySelector('[name="annonce_prix_vente"]') || document.querySelector('[name="prix_vente"]');
        if (el && !el.value) el.value = val;
        return;
      }
      if (k === 'loyer') {
        const el = document.querySelector('[name="annonce_loyer"]') || document.querySelector('[name="loyer"]');
        if (el && !el.value) el.value = val;
        return;
      }

      // Champ standard
      const el = document.querySelector(`[name="${k}"]`);
      if (el && !el.value) el.value = val;
    });

    // Reprise descriptif
    if (champs['reprise_descriptif']) {
      const elR = document.querySelector('[name="reprise_descriptif"]');
      if (elR && !elR.value) elR.value = champs['reprise_descriptif'];
    }

    // Propriétaire
    if (champs['id_proprietaire']) {
      const hiddenProprio = document.getElementById('ba-proprietaire-id');
      if (hiddenProprio) hiddenProprio.value = champs['id_proprietaire'];
    }

    // Déduits : coche les bool chips — uniquement si non supprimés
    const supprime = state._deduitsSupprime || new Set();

    function estSupprime(groupe, valeur) {
      const id = (groupe === 'dep' ? 'dep__' : groupe === 'ch' ? 'ch__' : groupe === 've' ? 've__' : 'fl__') + valeur;
      return supprime.has(id);
    }

    if (deduits.dependances) {
      Object.keys(deduits.dependances).forEach(k => {
        if (supprime.has('dep__' + k)) return;
        const inp = document.querySelector(`input[name="${k}"][type="checkbox"]`);
        if (inp && !inp.checked) { inp.checked = true; inp.closest('label')?.classList.add('on'); }
      });
    }
    ['ascenseur','cuisine_equipee','double_vitrage','parquet','calme','digicode','centre_ville'].forEach(k => {
      if (!deduits[k] || supprime.has('fl__' + k)) return;
      const inp = document.querySelector(`input[name="${k}"][type="checkbox"]`);
      if (inp && !inp.checked) { inp.checked = true; inp.closest('label')?.classList.add('on'); }
    });
  }

  // ════════════════════════════════════════════════════════════
  // CRÉATION VIA API
  // ════════════════════════════════════════════════════════════
  function creerBien(fichierId, overrides, index) {
    fetch(CFG.creerUrl, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ fichier_id: fichierId, overrides, csrf_token: CFG.csrfToken }),
    })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          state.fichiers[index].statut = 'created';
          renderList();
          closeModal();
          showToast('✅ Bien créé avec succès !', 'ok');
          if (data.bien_id) {
            showToast(`→ <a href="/public_html/bien_detail.php?id=${data.bien_id}" target="_blank">Voir le bien #${data.bien_id}</a>`, 'ok', 6000);
          }
        } else {
          showToast('❌ ' + (data.error || 'Erreur inconnue'), 'error');
        }
        dom.modalCreate.disabled = false;
        dom.modalCreate.textContent = '⚡ Créer directement';
      })
      .catch(() => {
        showToast('❌ Erreur réseau', 'error');
        dom.modalCreate.disabled = false;
        dom.modalCreate.textContent = '⚡ Créer directement';
      });
  }

  function postAction(action, fichierId) {
    return fetch(CFG.actionUrl, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ action, fichier_id: fichierId, csrf_token: CFG.csrfToken }),
    }).then(r => r.json());
  }

  // ════════════════════════════════════════════════════════════
  // HELPERS UI
  // ════════════════════════════════════════════════════════════
  function scoreStyle(score) {
    for (const [min, cls, icon] of LABELS_SCORE) {
      if ((score ?? 0) >= min) return [cls, icon];
    }
    return ['bi-score-lo', '🔴'];
  }

  function statusLabel(statut) {
    const map = {
      analysed  : '<span class="bi-badge ready">Analysé</span>',
      ready     : '<span class="bi-badge ready">✅ Prêt</span>',
      incomplete: '<span class="bi-badge incomplete">⚠️ Incomplet</span>',
      error     : '<span class="bi-badge error">❌ Erreur</span>',
      ignored   : '<span class="bi-badge ignored">Ignoré</span>',
      created   : '<span class="bi-badge created">✅ Créé</span>',
      analysing : '<span class="bi-badge analysing">⏳ Analyse…</span>',
    };
    return map[statut] || `<span class="bi-badge">${esc(statut)}</span>`;
  }

  function confIcon(s) {
    return s === 'eleve' ? '🟢' : s === 'moyen' ? '🟡' : '🔴';
  }

  function fieldInputType(k) {
    const nums = ['surface_habitable','surface_terrain','surface_carrez','prix_vente','loyer',
                  'charges_loc','nb_pieces','nb_chambres','etage','annee_construction'];
    return nums.includes(k) ? 'number' : 'text';
  }

  function showToast(html, type = 'ok', duration = 3500) {
    let wrap = document.getElementById('bi-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'bi-toasts'; document.body.appendChild(wrap); }
    const t = document.createElement('div');
    t.className = 'bi-toast bi-toast-' + type;
    t.innerHTML = html;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), duration);
  }

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function truncate(s, n) { return s.length > n ? s.slice(0, n) + '…' : s; }

  // ── Démarrage ───────────────────────────────────────────────
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})();
