/* ============================================================
   FLUXBOX — Pile de cartes — JS interaction
   ============================================================ */

(function () {
  'use strict';

  const API = window.FLUXBOX_API_URL || '/api/fluxbox_action.php';
  const CSRF = window.FLUXBOX_CSRF || '';

  const card = document.getElementById('fbx-card');
  const modal = document.getElementById('fbx-modal-adjust');

  if (!card) {
    // Pile vide — pas d'interaction
    setupKeyboardHelp();
    return;
  }

  const carteId = parseInt(card.getAttribute('data-carte-id') || '0', 10);
  if (!carteId) return;

  /* ──────────────────────────────────────────────────────────
     API helper
     ────────────────────────────────────────────────────────── */
  async function callApi(action, extra = {}) {
    const body = Object.assign({ action, carte_id: carteId, csrf: CSRF }, extra);
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF,
          'Accept': 'application/json',
        },
        body: JSON.stringify(body),
        credentials: 'same-origin',
      });
      return await res.json();
    } catch (e) {
      return { ok: false, errors: ['Réseau : ' + e.message] };
    }
  }

  /* ──────────────────────────────────────────────────────────
     Transitions visuelles + rechargement carte suivante
     ────────────────────────────────────────────────────────── */
  function leaveCard(direction) {
    const cls = {
      right: 'fbx-leaving-right',
      left:  'fbx-leaving-left',
      up:    'fbx-leaving-up',
    }[direction] || 'fbx-leaving-right';
    card.classList.add(cls);
    setTimeout(() => { window.location.reload(); }, 280);
  }

  function flash(message, type = 'info') {
    const div = document.createElement('div');
    div.className = 'fbx-flash fbx-flash-' + type;
    div.textContent = message;
    Object.assign(div.style, {
      position: 'fixed', top: '20px', left: '50%', transform: 'translateX(-50%)',
      padding: '12px 24px', borderRadius: '10px',
      background: type === 'error' ? '#fee2e2' : '#dcfce7',
      color: type === 'error' ? '#991b1b' : '#166534',
      fontSize: '14px', fontWeight: '600', boxShadow: '0 6px 20px rgba(0,0,0,0.15)',
      zIndex: '9999',
    });
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 3000);
  }

  /* ──────────────────────────────────────────────────────────
     Actions principales
     ────────────────────────────────────────────────────────── */
  async function actionValidate() {
    const res = await callApi('validate');
    if (res.ok) {
      flash('C\'est fait. Le document est classé.');
      leaveCard('right');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  async function actionLater(until = '+1 day') {
    const res = await callApi('later', { until });
    if (res.ok) {
      flash('Pas de souci, je le remettrai plus tard.');
      leaveCard('left');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  async function actionAdjust(classement) {
    const res = await callApi('adjust', classement);
    if (res.ok) {
      flash('C\'est fait avec vos ajustements.');
      leaveCard('right');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  async function actionDelete() {
    if (!window.confirm('Supprimer définitivement ce document ?\n\nLa carte est retirée et le fichier est effacé du disque (si aucune autre carte ne le référence). Action irréversible.')) {
      return;
    }
    const res = await callApi('delete_doc');
    if (res.ok) {
      const fileDeleted = res.data && res.data.file_deleted;
      flash(fileDeleted ? 'Document supprimé (fichier effacé du disque).' : 'Carte supprimée (fichier conservé car référencé par d\'autres cartes).');
      leaveCard('up');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  function actionView() {
    // Détection .msg via le nom de fichier exposé par le PHP (data-attribute sur la carte)
    const filename = (card.getAttribute('data-doc-filename') || '').toLowerCase();
    if (filename.endsWith('.msg')) {
      openMsgModal();
      return;
    }
    // Sinon viewer iframe générique (PDF, image, etc.)
    const viewModal = document.getElementById('fbx-modal-view');
    const iframe = document.getElementById('fbx-view-iframe');
    if (!viewModal || !iframe) {
      flash('Visualisation non disponible pour ce document.', 'error');
      return;
    }
    const sep = API.includes('?') ? '&' : '?';
    iframe.src = API + sep + 'action=view_doc&carte_id=' + encodeURIComponent(String(carteId));
    if (typeof viewModal.showModal === 'function') {
      viewModal.showModal();
    } else {
      viewModal.setAttribute('open', 'true');
    }
  }

  function closeViewModal() {
    const viewModal = document.getElementById('fbx-modal-view');
    const iframe = document.getElementById('fbx-view-iframe');
    if (!viewModal) return;
    if (iframe) iframe.src = 'about:blank';
    if (typeof viewModal.close === 'function') viewModal.close();
    else viewModal.removeAttribute('open');
  }

  /* ──────────────────────────────────────────────────────────
     Modal .msg (fiche document mail reçu)
     ────────────────────────────────────────────────────────── */
  let _msgCache = null; // cache de la réponse read_msg

  async function openMsgModal() {
    const msgModal = document.getElementById('fbx-modal-msg');
    if (!msgModal) {
      flash('Modal mail non disponible — recharge la page.', 'error');
      return;
    }
    const fiche = document.getElementById('fbx-msg-view-fiche');
    const reply = document.getElementById('fbx-msg-view-reply');
    if (fiche) fiche.hidden = false;
    if (reply) reply.hidden = true;
    if (fiche) fiche.innerHTML = '<div class="fbx-msg-loading">⏳ Chargement du document mail…</div>';

    if (typeof msgModal.showModal === 'function') msgModal.showModal();
    else msgModal.setAttribute('open', 'true');

    // Appel API read_msg
    const res = await callApi('read_msg');
    if (!res.ok) {
      if (fiche) fiche.innerHTML = '<div class="fbx-msg-warning">⚠️ Lecture impossible : ' + ((res.errors || []).join(', ') || 'erreur inconnue') + '</div>';
      return;
    }
    _msgCache = res.data || {};
    renderMsgFiche(_msgCache);
  }

  function closeMsgModal() {
    const msgModal = document.getElementById('fbx-modal-msg');
    if (!msgModal) return;
    if (typeof msgModal.close === 'function') msgModal.close();
    else msgModal.removeAttribute('open');
    _msgCache = null;
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderMsgFiche(data) {
    const fiche = document.getElementById('fbx-msg-view-fiche');
    if (!fiche) return;

    // Avertissement honnête : si métadonnées principales vides, le parser PHP pur a ses limites
    const isIncomplete = !data.subject && !data.body_text;
    let warnings = '';
    if (isIncomplete) {
      warnings = `<div class="fbx-msg-warning">
        ⚠️ <strong>Lecture .msg partielle.</strong>
        Le parser PHP intégré ne peut pas lire tous les champs des fichiers Outlook .msg
        (format binaire propriétaire complexe).
        <br>👉 <strong>Pour ouvrir correctement</strong> : télécharge le .msg ci-dessous et ouvre-le dans Outlook.
        <br>💡 <em>Pour activer un parsing fiable côté serveur : <code>composer require hfig/mapi</code></em>
      </div>`;
    } else if ((data.warnings || []).length > 0) {
      warnings = '<div class="fbx-msg-warning">⚠️ ' + escapeHtml(data.warnings.join(' · ')) + '</div>';
    }

    // Section Origine (qui, quand, depuis où)
    const sectionOrigine = `
      <div class="fbx-msg-section">
        <div class="fbx-msg-section-title">📍 Origine du document</div>
        <div class="fbx-msg-meta-row">
          <div class="fbx-msg-meta-label">Expéditeur</div>
          <div class="fbx-msg-meta-value">${escapeHtml(data.from) || '<em style="color:#94a3b8">non détecté</em>'}</div>
        </div>
        ${(data.from_email && /^[^\s/]+@[^\s/]+\.[^\s/]+$/.test(data.from_email)) ? `
        <div class="fbx-msg-meta-row">
          <div class="fbx-msg-meta-label">Email</div>
          <div class="fbx-msg-meta-value"><code>${escapeHtml(data.from_email)}</code></div>
        </div>` : ''}
        ${(data.to && data.to.length >= 5 && (/@/.test(data.to) || /;/.test(data.to))) ? `
        <div class="fbx-msg-meta-row">
          <div class="fbx-msg-meta-label">Destinataire</div>
          <div class="fbx-msg-meta-value">${escapeHtml(data.to)}</div>
        </div>` : ''}
        ${data.date_sent ? `
        <div class="fbx-msg-meta-row">
          <div class="fbx-msg-meta-label">Date d'envoi</div>
          <div class="fbx-msg-meta-value">${escapeHtml(data.date_sent)}</div>
        </div>` : ''}
        <div class="fbx-msg-meta-row">
          <div class="fbx-msg-meta-label">Fichier source</div>
          <div class="fbx-msg-meta-value"><code style="font-size:11px;color:#94a3b8">${escapeHtml(data.fichier_nom || '')}</code></div>
        </div>
      </div>
    `;

    // Section Sujet
    const sectionSujet = `
      <div class="fbx-msg-section">
        <div class="fbx-msg-section-title">🏷️ Sujet</div>
        <div style="font-size:15px;font-weight:600;color:#243B5C;">${escapeHtml(data.subject) || '<em style="color:#94a3b8;font-weight:400">aucun sujet détecté</em>'}</div>
      </div>
    `;

    // Section Contenu (texte brut, jamais HTML rendu pour éviter style "mail")
    const bodyText = data.body_text || '';
    const sectionContenu = `
      <div class="fbx-msg-section">
        <div class="fbx-msg-section-title">📝 Contenu reçu</div>
        <div class="fbx-msg-content">${escapeHtml(bodyText) || '<em style="color:#94a3b8">contenu non extrait — ouvrir le fichier .msg pour le détail</em>'}</div>
      </div>
    `;

    // Section Pièces jointes (listées comme docs à classer dans la GED + boutons cliquables)
    const atts = data.attachments || [];
    const imgExts = ['jpg','jpeg','png','gif','bmp','webp','svg'];
    let sectionAtts = '';
    if (atts.length > 0) {
      const sep = API.includes('?') ? '&' : '?';
      const items = atts.map(a => {
        const ext = (String(a.name).split('.').pop() || '').toLowerCase();
        const isImage = imgExts.includes(ext);
        const isPdf = ext === 'pdf';
        const icon = isImage ? '🖼️' : (isPdf ? '📕' : (['doc','docx'].includes(ext) ? '📘' : (['xls','xlsx'].includes(ext) ? '📗' : '📎')));
        const baseUrl = API + sep + 'action=download_msg_attachment&carte_id=' + encodeURIComponent(String(carteId)) + '&idx=' + a.idx;
        return `
          <li class="fbx-msg-attachment-item">
            <span style="font-size:18px;">${icon}</span>
            <span class="fbx-msg-attachment-name">${escapeHtml(a.name)}</span>
            ${a.size > 0 ? `<span class="fbx-msg-attachment-size">${(a.size/1024).toFixed(1)} Ko</span>` : ''}
            <button type="button" class="fbx-btn fbx-btn-view fbx-att-view-btn"
                    data-view-url="${baseUrl}&mode=inline"
                    data-dl-url="${baseUrl}&mode=download"
                    data-name="${escapeHtml(a.name)}"
                    data-ext="${ext}"
                    style="padding:4px 10px;font-size:11px;" title="Ouvrir dans une popup">👁️</button>
            <a href="${baseUrl}&mode=download" class="fbx-btn fbx-btn-view" style="padding:4px 10px;font-size:11px;text-decoration:none;" title="Télécharger">📥</a>
          </li>
        `;
      }).join('');
      sectionAtts = `
        <div class="fbx-msg-section">
          <div class="fbx-msg-section-title">📎 Pièces jointes (${atts.length})</div>
          <ul class="fbx-msg-attachment-list">${items}</ul>
        </div>
      `;
    }

    // Actions disponibles (style FluxBox standard, pas client mail)
    const canReply = data.from_email && /^[^\s/]+@[^\s/]+\.[^\s/]+$/.test(data.from_email);
    const downloadUrl = API + (API.includes('?') ? '&' : '?') + 'action=view_doc&carte_id=' + encodeURIComponent(String(carteId));
    const sectionActions = `
      <div class="fbx-msg-actions">
        ${canReply ? `<button type="button" class="fbx-btn fbx-btn-validate" id="fbx-msg-btn-reply">📤 Répondre à l'expéditeur</button>` : ''}
        ${atts.length > 0 ? `<button type="button" class="fbx-btn fbx-btn-view" id="fbx-msg-btn-ingest">📥 Récupérer les pièces jointes</button>` : ''}
        <a href="${downloadUrl}" target="_blank" rel="noopener" class="fbx-btn fbx-btn-view" style="text-decoration:none;">⬇️ Télécharger .msg (Outlook)</a>
        <button type="button" class="fbx-btn fbx-btn-secondary" data-msg-close>← Retour à la pile</button>
      </div>
    `;

    fiche.innerHTML = warnings + sectionOrigine + sectionSujet + sectionContenu + sectionAtts + sectionActions;

    // Branchements actions
    document.getElementById('fbx-msg-btn-reply')?.addEventListener('click', openMsgReplyForm);
    document.getElementById('fbx-msg-btn-ingest')?.addEventListener('click', ingestMsgAttachments);
    fiche.querySelectorAll('[data-msg-close]').forEach(el =>
      el.addEventListener('click', closeMsgModal));
  }

  function openMsgReplyForm() {
    if (!_msgCache) return;
    const fiche = document.getElementById('fbx-msg-view-fiche');
    const reply = document.getElementById('fbx-msg-view-reply');
    if (!fiche || !reply) return;
    document.getElementById('fbx-msg-reply-to').value = _msgCache.from_email || '';
    document.getElementById('fbx-msg-reply-subject').value = 'Re: ' + (_msgCache.subject || '');
    document.getElementById('fbx-msg-reply-body').value =
      '\n\n--- Message original ---\nDe : ' + (_msgCache.from || '') +
      (_msgCache.date_sent ? '\nDate : ' + _msgCache.date_sent : '') +
      '\nSujet : ' + (_msgCache.subject || '') + '\n\n' +
      (_msgCache.body_text || '');
    fiche.hidden = true;
    reply.hidden = false;
  }

  async function sendMsgReply() {
    const toEmail = document.getElementById('fbx-msg-reply-to').value;
    const subject = document.getElementById('fbx-msg-reply-subject').value;
    const bodyTxt = document.getElementById('fbx-msg-reply-body').value;
    if (!toEmail || !subject || !bodyTxt.trim()) {
      flash('Remplis tous les champs avant d\'envoyer.', 'error');
      return;
    }
    const sendBtn = document.getElementById('fbx-msg-reply-send');
    if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = '⏳ Envoi…'; }
    const res = await callApi('send_msg_reply', { to_email: toEmail, subject, body: bodyTxt });
    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = '📤 Envoyer la réponse'; }
    if (res.ok) {
      flash('Réponse envoyée à ' + toEmail);
      closeMsgModal();
    } else {
      flash('Erreur : ' + ((res.errors || ['inconnue']).join(', ')), 'error');
    }
  }

  async function ingestMsgAttachments() {
    const btn = document.getElementById('fbx-msg-btn-ingest');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Analyse…'; }
    const res = await callApi('ingest_msg_attachments');
    if (btn) { btn.disabled = false; btn.textContent = '📥 Récupérer les pièces jointes'; }
    if (res.ok) {
      const d = res.data || {};
      const nb = d.attachments_detected || 0;
      // Affiche un message clair dans le modal (pas juste un toast éphémère)
      const fiche = document.getElementById('fbx-msg-view-fiche');
      if (fiche) {
        const warn = document.createElement('div');
        warn.className = 'fbx-msg-warning';
        warn.style.marginTop = '12px';
        warn.innerHTML = nb > 0
          ? `<strong>${nb} pièce${nb>1?'s':''} jointe${nb>1?'s':''} détectée${nb>1?'s':''}.</strong><br>
             ⚠️ Extraction binaire non disponible avec le parser PHP pur actuel.<br>
             Pour activer le téléchargement individuel des PJ : <code>composer require hfig/php-msgreader</code> côté serveur.<br>
             En attendant, télécharge le .msg complet et ouvre-le dans Outlook pour accéder aux PJ.`
          : `Aucune pièce jointe détectée dans ce .msg.`;
        fiche.appendChild(warn);
        warn.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      flash(`${nb} pièces jointes détectées — voir détails dans le modal.`);
    } else {
      flash('Erreur : ' + ((res.errors || ['inconnue']).join(', ')), 'error');
    }
  }

  /* ──────────────────────────────────────────────────────────
     Modal Ajuster — cascade boutons GED (cohérence UX avec modal upload)
     ────────────────────────────────────────────────────────── */

  // Mapping N1 → icône + label court (synchro avec fluxbox_upload_modal.php)
  const ADJ_METIER_DEF = {
    '01_AGENCE':                 { icon: '🏬', label: 'Agence',       pos:  1 },
    '02_RH':                     { icon: '👥', label: 'RH',           pos:  2 },
    '06_COMPTABILITE':           { icon: '💰', label: 'Compta',       pos:  3 },
    '01_DIRECTION':              { icon: '⚙️', label: 'Direction',    pos:  4 },
    '07_JURIDIQUE_CONTENTIEUX':  { icon: '⚖️', label: 'Juridique',    pos:  5 },
    '08_MARKETING_COMMUNICATION':{ icon: '📣', label: 'Marketing',    pos:  6 },
    '09_MODELES_DOCUMENTS':      { icon: '📄', label: 'Modèles',      pos:  7 },
    '10_REFERENTIEL':            { icon: '📚', label: 'Référentiel',  pos:  8 },
    '12_ARCHIVES':               { icon: '📦', label: 'Archives',     pos:  9 },
    '99_SYSTEME':                { icon: '🛠️', label: 'Système',      pos: 10 },
    '11_MAILS_COMMUNICATIONS':   { icon: '📧', label: 'Mail & Comm',  pos: 11 },
    '03_GESTION_LOCATIVE':       { icon: '🏠', label: 'Gestion',      pos: 12 },
    '04_SYNDIC':                 { icon: '🏢', label: 'Syndic',       pos: 13 },
    '05_TRANSACTION':            { icon: '🤝', label: 'Transaction',  pos: 14 },
    '13_FOURNISSEURS':           { icon: '🚚', label: 'Fournisseurs', pos: 15 },
  };

  // Refs des conteneurs cascade
  const adjRowMet = () => document.getElementById('fbx-adjust-row-metiers');
  const adjRowN2  = () => document.getElementById('fbx-adjust-row-n2');
  const adjRowN3  = () => document.getElementById('fbx-adjust-row-n3');
  const adjRowN4  = () => document.getElementById('fbx-adjust-row-n4');
  const adjRowN5  = () => document.getElementById('fbx-adjust-row-n5');

  // Refs hidden inputs
  const adjN1Input = () => document.getElementById('fbx-adjust-n1');
  const adjN2Input = () => document.getElementById('fbx-adjust-n2');
  const adjN3Input = () => document.getElementById('fbx-adjust-n3');
  const adjN4Input = () => document.getElementById('fbx-adjust-n4');
  const adjN5Input = () => document.getElementById('fbx-adjust-n5');

  async function adjLoadGedChildren(level, parents) {
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action: 'ged_cascade', level, ...parents, csrf: CSRF }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      return data.ok ? (data.data.items || []) : [];
    } catch (_) { return []; }
  }

  /* Filtre N3 live : masque les boutons non-matchants, et si la recherche est non vide,
     interroge la BDD immeubles pour ajouter des résultats "virtuels" en bleu. */
  async function adjFilterN3(query) {
    const row = adjRowN3();
    if (!row) return;
    const q = (query || '').trim().toLowerCase();
    // 1. Supprime les anciens boutons "search result" (issus d'une recherche précédente)
    row.querySelectorAll('.fbx-search-result').forEach(b => b.remove());
    // 2. Affiche/masque les boutons selon match label/code
    row.querySelectorAll('.fbx-choice-btn:not(.fbx-btn-add-ref)').forEach(btn => {
      if (q === '') {
        btn.classList.remove('fbx-hidden-by-search');
        return;
      }
      const code = (btn.dataset.code || '').toLowerCase();
      const label = (btn.textContent || '').toLowerCase();
      const match = code.includes(q) || label.includes(q);
      btn.classList.toggle('fbx-hidden-by-search', !match);
    });
    // 3. Si recherche non vide ET N1=04_SYNDIC + N2=IMMEUBLES → cherche en BDD immeubles
    if (q.length < 2) return;
    const n1 = adjN1Input()?.value || '';
    const n2 = adjN2Input()?.value || '';
    if (n1 !== '04_SYNDIC' || n2 !== 'IMMEUBLES') return;
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action: 'search_immeubles', csrf: CSRF, q, limit: 10 }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.ok || !data.data || !Array.isArray(data.data.items)) return;
      // Ajoute des boutons virtuels pour chaque immeuble trouvé
      data.data.items.forEach(imm => {
        // Évite doublon si déjà affiché en bouton standard
        const codeKey = String(imm.reference_immeuble || imm.id);
        if (row.querySelector('.fbx-choice-btn[data-code="' + codeKey + '"]')) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fbx-choice-btn fbx-search-result';
        btn.dataset.code = codeKey;
        btn.title = imm.nom_immeuble + ' (ref ' + codeKey + ')';
        btn.innerHTML = '<span class="fbx-choice-label">' + (imm.nom_immeuble || codeKey) + '</span>';
        btn.addEventListener('click', () => {
          // Sélectionne ce bouton + propage à adjOnPickN3 comme un N3 normal
          row.querySelectorAll('.fbx-choice-btn').forEach(b => b.classList.toggle('is-selected', b === btn));
          adjOnPickN3(codeKey);
          // Pré-remplit aussi l'instance entité avec la ref
          const entInput = document.querySelector('input[name="entity_instance"]');
          if (entInput) { entInput.value = codeKey; entInput.classList.add('fbx-auto-filled'); entInput.classList.remove('fbx-empty-fillable'); }
        });
        // Insère AVANT le bouton "+"
        const addBtn = row.querySelector('.fbx-btn-add-ref');
        if (addBtn) row.insertBefore(btn, addBtn);
        else row.appendChild(btn);
      });
    } catch (_) {}
  }

  function adjRenderBtnGrid(container, items, emptyMsg, onPick, selectedCode) {
    container.innerHTML = '';
    if (items && items.length > 0) {
      items.forEach(it => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fbx-choice-btn';
        btn.dataset.code = it.code;
        if (it.code === selectedCode) btn.classList.add('is-selected');
        const lab = document.createElement('span');
        lab.className = 'fbx-choice-label';
        lab.textContent = it.label || it.code;
        btn.appendChild(lab);
        btn.addEventListener('click', () => {
          container.querySelectorAll('.fbx-choice-btn').forEach(b =>
            b.classList.toggle('is-selected', b === btn));
          onPick(it.code);
        });
        container.appendChild(btn);
      });
    } else {
      const e = document.createElement('div');
      e.className = 'fbx-row-empty';
      e.textContent = emptyMsg;
      container.appendChild(e);
    }
    // Bouton "+" admin pour ajouter une nouvelle référence à ce niveau
    adjAppendAddRefBtn(container, onPick);
  }

  /* Bouton + admin (cohérence avec modal upload) — visible si window.FLUXBOX_IS_ADMIN === true */
  function adjAppendAddRefBtn(container, onPick) {
    if (!window.FLUXBOX_IS_ADMIN) return;
    const level = parseInt(container.dataset.metaLevel || '0', 10);
    if (![2, 3, 4, 5].includes(level)) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'fbx-choice-btn fbx-btn-add-ref';
    btn.title = 'Ajouter une nouvelle référence à ce niveau (admin)';
    btn.innerHTML = '<span class="fbx-choice-icon">+</span><span class="fbx-choice-label">Ajouter</span>';
    btn.addEventListener('click', () => adjPromptAddLevelCode(level, container, onPick));
    container.appendChild(btn);
  }

  /* Workflow ajout niveau code depuis modal Ajuster — identique à l'upload modal */
  async function adjPromptAddLevelCode(level, container, onPick) {
    const niveauLabel = {2:'Domaine',3:'Sous-domaine',4:'Catégorie',5:'Sous-catégorie'}[level] || 'Niveau';
    const parents = {
      n1: adjN1Input()?.value || '',
      n2: level >= 3 ? (adjN2Input()?.value || '') : '',
      n3: level >= 4 ? (adjN3Input()?.value || '') : '',
      n4: level >= 5 ? (adjN4Input()?.value || '') : '',
    };
    if (level >= 2 && !parents.n1) { alert('Choisis d\'abord un Métier.'); return; }
    if (level >= 3 && !parents.n2) { alert('Choisis d\'abord un Domaine.'); return; }
    if (level >= 4 && !parents.n3) { alert('Choisis d\'abord un Sous-domaine.'); return; }
    if (level >= 5 && !parents.n4) { alert('Choisis d\'abord une Catégorie.'); return; }
    const label = (prompt(`Nouveau ${niveauLabel.toLowerCase()} — Libellé affiché :`) || '').trim();
    if (!label) return;
    const codeSuggested = label.toUpperCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^A-Z0-9]+/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
    const code = (prompt(`Code court (A-Z 0-9 _)`, codeSuggested) || '').trim().toUpperCase();
    if (!code) return;
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({
          action: 'add_level_code', csrf: CSRF,
          level, code, label,
          parent_n1: parents.n1, parent_n2: parents.n2, parent_n3: parents.n3, parent_n4: parents.n4,
        }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.ok) { alert('Erreur : ' + ((data.errors || []).join(', ') || 'inconnue')); return; }
      // Recharge la grille du niveau concerné
      const items = await adjLoadGedChildren(level, { n1: parents.n1, n2: parents.n2, n3: parents.n3, n4: parents.n4 });
      const empty = {2:'— Aucun domaine —',3:'— Aucun sous-domaine —',4:'— Aucune catégorie —',5:'— Aucune sous-catégorie —'}[level];
      adjRenderBtnGrid(container, items, empty, onPick, code);
      if (onPick) onPick(code);
    } catch (e) {
      alert('Erreur réseau : ' + e.message);
    }
  }

  function adjRenderMetiers(grouped, selectedN1) {
    const row = adjRowMet();
    if (!row) return;
    if (!grouped || grouped.length === 0) {
      row.innerHTML = '<div class="fbx-row-empty">Aucun métier seedé.</div>';
      return;
    }
    // Aplatit + trie selon METIER_DEF
    const allCodes = [];
    grouped.forEach(g => (g.codes || []).forEach(c => allCodes.push(c)));
    allCodes.sort((a, b) => {
      const pa = ADJ_METIER_DEF[a.code]?.pos ?? 999;
      const pb = ADJ_METIER_DEF[b.code]?.pos ?? 999;
      return pa - pb;
    });
    row.innerHTML = '';
    allCodes.forEach(c => {
      const def = ADJ_METIER_DEF[c.code] || { icon: '📁', label: (c.label || '').replace(/^\d+\s*-\s*/, '') };
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fbx-choice-btn';
      btn.dataset.code = c.code;
      if (c.code === selectedN1) btn.classList.add('is-selected');
      btn.title = c.code;
      btn.innerHTML = `<span class="fbx-choice-icon">${def.icon}</span><span class="fbx-choice-label">${def.label}</span>`;
      btn.addEventListener('click', () => adjOnPickN1(c.code));
      row.appendChild(btn);
    });
  }

  async function adjOnPickN1(code) {
    if (adjN1Input()) adjN1Input().value = code;
    if (adjN2Input()) adjN2Input().value = '';
    if (adjN3Input()) adjN3Input().value = '';
    if (adjN4Input()) adjN4Input().value = '';
    if (adjN5Input()) adjN5Input().value = '';
    // Marquage UI
    adjRowMet().querySelectorAll('.fbx-choice-btn').forEach(b =>
      b.classList.toggle('is-selected', b.dataset.code === code));
    // Reset cascade aval
    adjRowN2().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    adjRowN3().innerHTML = '<div class="fbx-row-empty">— Choisir un domaine d\'abord —</div>';
    adjRowN4().innerHTML = '<div class="fbx-row-empty">— Choisir un sous-domaine d\'abord —</div>';
    adjRowN5().innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie d\'abord —</div>';
    const items = await adjLoadGedChildren(2, { n1: code });
    adjRenderBtnGrid(adjRowN2(), items, '— Aucun domaine seedé —', adjOnPickN2);
  }

  async function adjOnPickN2(code) {
    const n1 = adjN1Input()?.value || '';
    if (adjN2Input()) adjN2Input().value = code;
    if (adjN3Input()) adjN3Input().value = '';
    if (adjN4Input()) adjN4Input().value = '';
    if (adjN5Input()) adjN5Input().value = '';
    adjRowN3().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    adjRowN4().innerHTML = '<div class="fbx-row-empty">— Choisir un sous-domaine d\'abord —</div>';
    adjRowN5().innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie d\'abord —</div>';
    const items = await adjLoadGedChildren(3, { n1, n2: code });
    adjRenderBtnGrid(adjRowN3(), items, '— Aucun sous-domaine seedé —', adjOnPickN3);
  }

  async function adjOnPickN3(code) {
    const n1 = adjN1Input()?.value || '', n2 = adjN2Input()?.value || '';
    if (adjN3Input()) adjN3Input().value = code;
    if (adjN4Input()) adjN4Input().value = '';
    if (adjN5Input()) adjN5Input().value = '';
    adjRowN4().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    adjRowN5().innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie d\'abord —</div>';
    const items = await adjLoadGedChildren(4, { n1, n2, n3: code });
    adjRenderBtnGrid(adjRowN4(), items, '— Aucune catégorie seedée —', adjOnPickN4);
  }

  async function adjOnPickN4(code) {
    const n1 = adjN1Input()?.value || '', n2 = adjN2Input()?.value || '', n3 = adjN3Input()?.value || '';
    if (adjN4Input()) adjN4Input().value = code;
    if (adjN5Input()) adjN5Input().value = '';
    adjRowN5().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await adjLoadGedChildren(5, { n1, n2, n3, n4: code });
    adjRenderBtnGrid(adjRowN5(), items, '— Aucune sous-catégorie seedée —', adjOnPickN5);
  }

  function adjOnPickN5(code) {
    if (adjN5Input()) adjN5Input().value = code;
  }

  let _adjCascadeInitialized = false;

  async function initAdjustCascade() {
    if (_adjCascadeInitialized) return;
    _adjCascadeInitialized = true;
    // Récup valeurs initiales depuis hidden inputs (alimentés par PHP au render)
    const n1 = adjN1Input()?.value || '';
    const n2 = adjN2Input()?.value || '';
    const n3 = adjN3Input()?.value || '';
    const n4 = adjN4Input()?.value || '';
    // 1. Charge N1 (métiers groupés)
    let metierGrouped = [];
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action: 'fbx_context', csrf: CSRF }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      metierGrouped = data.ok ? (data.data.metiers_grouped || []) : [];
    } catch (_) {}
    adjRenderMetiers(metierGrouped, n1);

    // 2. Si N1 sélectionné, charge N2 + pré-sélection
    if (n1) {
      const items2 = await adjLoadGedChildren(2, { n1 });
      adjRenderBtnGrid(adjRowN2(), items2, '— Aucun domaine seedé —', adjOnPickN2, n2);
    }
    // 3. Si N2 aussi, charge N3
    if (n1 && n2) {
      const items3 = await adjLoadGedChildren(3, { n1, n2 });
      adjRenderBtnGrid(adjRowN3(), items3, '— Aucun sous-domaine seedé —', adjOnPickN3, n3);
    }
    // 4. N3 → charge N4
    if (n1 && n2 && n3) {
      const items4 = await adjLoadGedChildren(4, { n1, n2, n3 });
      adjRenderBtnGrid(adjRowN4(), items4, '— Aucune catégorie seedée —', adjOnPickN4, n4);
    }
    // 5. N4 → charge N5
    if (n1 && n2 && n3 && n4) {
      const items5 = await adjLoadGedChildren(5, { n1, n2, n3, n4 });
      adjRenderBtnGrid(adjRowN5(), items5, '— Aucune sous-catégorie seedée —', adjOnPickN5, adjN5Input()?.value || '');
    }
  }

  function openAdjustModal() {
    if (!modal) return;
    if (typeof modal.showModal === 'function') {
      modal.showModal();
    } else {
      modal.setAttribute('open', '');
    }
    // Boutons N6 signature : SIGNE / NON_SIGNE / vide (cascade comme les autres niveaux)
    const n6Row = document.getElementById('fbx-adjust-row-n6');
    const n6Input = document.getElementById('fbx-adjust-n6');
    if (n6Row && n6Input && !n6Row.dataset.bound) {
      n6Row.dataset.bound = '1';
      n6Row.querySelectorAll('.fbx-choice-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          n6Row.querySelectorAll('.fbx-choice-btn').forEach(b => b.classList.toggle('is-selected', b === btn));
          n6Input.value = btn.dataset.n6Val || '';
        });
      });
    }

    // Champ recherche N3 — filtre live des boutons + lookup BDD pour entités placeholder
    const n3Search = document.getElementById('fbx-adjust-n3-search');
    if (n3Search && !n3Search.dataset.bound) {
      n3Search.dataset.bound = '1';
      let n3SearchTimer = null;
      n3Search.addEventListener('input', () => {
        clearTimeout(n3SearchTimer);
        n3SearchTimer = setTimeout(() => adjFilterN3(n3Search.value), 200);
      });
    }
    // Charge le viewer doc (iframe à droite) si dispo — sans sidebar pages PDF
    const viewerIframe = document.getElementById('fbx-adjust-viewer-iframe');
    const cardShell = document.getElementById('fbx-card');
    if (viewerIframe && cardShell) {
      const carteId = cardShell.getAttribute('data-carte-id') || '';
      if (carteId && viewerIframe.src === 'about:blank') {
        const sep = API.includes('?') ? '&' : '?';
        // #toolbar=0&navpanes=0 → cache barre d'outils + panneau pages du viewer PDF natif
        // #view=FitH → ajuste la page à la largeur
        viewerIframe.src = API + sep + 'action=view_doc&carte_id=' + encodeURIComponent(carteId)
          + '#toolbar=1&navpanes=0&view=FitH';
      }
    }
    // Filtre agences par société sélectionnée (one-time init)
    const socSel = document.getElementById('fbx-adjust-soc');
    const ageSel = document.getElementById('fbx-adjust-age');
    if (socSel && ageSel && !socSel.dataset.boundFilter) {
      socSel.dataset.boundFilter = '1';
      const filterAgences = () => {
        const sid = parseInt(socSel.value || '0', 10);
        ageSel.querySelectorAll('option[data-societe]').forEach(opt => {
          const sOpt = parseInt(opt.getAttribute('data-societe') || '0', 10);
          opt.hidden = sid > 0 && sOpt !== sid;
        });
        // Si l'agence sélectionnée n'est plus visible, reset
        const cur = ageSel.querySelector('option[value="' + ageSel.value + '"]');
        if (cur && cur.hidden) ageSel.value = '';
      };
      socSel.addEventListener('change', filterAgences);
      filterAgences();
    }
    // Lance la cascade au premier open (cache ensuite)
    initAdjustCascade();
  }
  function closeAdjustModal() {
    if (!modal) return;
    // Libère l'iframe viewer pour éviter de continuer à charger en arrière-plan
    const viewerIframe = document.getElementById('fbx-adjust-viewer-iframe');
    if (viewerIframe) viewerIframe.src = 'about:blank';
    if (typeof modal.close === 'function') {
      modal.close();
    } else {
      modal.removeAttribute('open');
    }
  }
  function submitAdjustModal() {
    if (!modal) return;
    const form = modal.querySelector('form');
    if (!form) return;
    const data = {};
    form.querySelectorAll('input[name], textarea[name], select[name]').forEach((el) => {
      data[el.name] = el.value || '';
    });
    // Validation client minimum N1+N2+N3
    if (!data.n1 || !data.n2 || !data.n3) {
      flash('N1, N2 et N3 sont requis pour valider — clique au moins jusqu\'au sous-domaine.', 'error');
      return;
    }
    closeAdjustModal();
    actionAdjust(data);
  }

  /* ──────────────────────────────────────────────────────────
     Branche les 3 boutons + modal
     ────────────────────────────────────────────────────────── */
  card.querySelectorAll('[data-action]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      const action = btn.getAttribute('data-action');
      if (action === 'validate') actionValidate();
      else if (action === 'later') actionLater(btn.getAttribute('data-later') || '+1 day');
      else if (action === 'adjust') openAdjustModal();
      else if (action === 'view') actionView();
      else if (action === 'delete') actionDelete();
    });
  });

  if (modal) {
    modal.querySelector('[data-modal-cancel]')?.addEventListener('click', closeAdjustModal);
    modal.querySelector('[data-modal-confirm]')?.addEventListener('click', submitAdjustModal);
    modal.addEventListener('cancel', (e) => { e.preventDefault(); closeAdjustModal(); });
  }

  // Branchements modal viewer
  const viewModal = document.getElementById('fbx-modal-view');
  if (viewModal) {
    viewModal.querySelectorAll('[data-view-close]').forEach((el) =>
      el.addEventListener('click', closeViewModal));
    viewModal.addEventListener('cancel', (e) => { e.preventDefault(); closeViewModal(); });
  }

  // Branchements modal .msg
  const msgModal = document.getElementById('fbx-modal-msg');
  if (msgModal) {
    msgModal.querySelectorAll('[data-msg-close]').forEach((el) =>
      el.addEventListener('click', closeMsgModal));
    msgModal.addEventListener('cancel', (e) => { e.preventDefault(); closeMsgModal(); });
    document.getElementById('fbx-msg-reply-cancel')?.addEventListener('click', () => {
      document.getElementById('fbx-msg-view-fiche').hidden = false;
      document.getElementById('fbx-msg-view-reply').hidden = true;
    });
    document.getElementById('fbx-msg-reply-send')?.addEventListener('click', sendMsgReply);
  }

  // Sous-modal Pièce jointe (popup au-dessus du modal mail)
  const attModal    = document.getElementById('fbx-modal-att');
  const attIframe   = document.getElementById('fbx-att-iframe');
  const attImage    = document.getElementById('fbx-att-image');
  const attFallback = document.getElementById('fbx-att-fallback');
  const attTitle    = document.getElementById('fbx-att-title');
  const attOpenNew  = document.getElementById('fbx-att-open-new');
  const attDl       = document.getElementById('fbx-att-download');
  let _fbxCurrentAtt = null; // {idx, name}
  const FBX_IMAGE_EXT  = ['jpg','jpeg','png','gif','bmp','webp','svg'];
  const FBX_PDF_EXT    = ['pdf'];
  const FBX_IFRAME_EXT = ['txt','html','htm','xml','json'];

  function showFbxAttElement(which) {
    if (attIframe)   attIframe.style.display   = (which === 'iframe') ? 'block' : 'none';
    if (attImage)    attImage.style.display    = (which === 'image')  ? 'block' : 'none';
    if (attFallback) attFallback.style.display = (which === 'fallback') ? 'block' : 'none';
  }

  function closeAtt() {
    if (!attModal) return;
    if (attIframe) attIframe.src = 'about:blank';
    if (attImage)  attImage.src = '';
    showFbxAttElement('iframe');
    if (typeof attModal.close === 'function') attModal.close();
    else attModal.removeAttribute('open');
  }

  // Délégation : clic sur n'importe quel .fbx-att-view-btn (créés dynamiquement)
  document.body.addEventListener('click', (e) => {
    const btn = e.target.closest('.fbx-att-view-btn');
    if (!btn) return;
    e.preventDefault();
    const url   = btn.getAttribute('data-view-url') || '';
    const dlUrl = btn.getAttribute('data-dl-url')   || '';
    const name  = btn.getAttribute('data-name')     || 'Pièce jointe';
    const ext   = (btn.getAttribute('data-ext')     || '').toLowerCase();
    // Mémorise idx pour le save + pré-remplit le formulaire
    const idxMatch = url.match(/idx=(\d+)/);
    _fbxCurrentAtt = { idx: idxMatch ? parseInt(idxMatch[1], 10) : -1, name: name };
    const fbxAttNameInput = document.getElementById('fbx-att-name-input');
    if (fbxAttNameInput) fbxAttNameInput.value = name;
    // Init cascade avec classement parent
    initFbxAttCascade(window.FLUXBOX_PARENT_CLASSEMENT || {});
    const fbxSaveBtn = document.getElementById('fbx-att-save');
    if (fbxSaveBtn) { fbxSaveBtn.disabled = false; fbxSaveBtn.textContent = '💾 Enregistrer dans la GED'; }

    if (attTitle) attTitle.textContent = '📎 ' + name;
    if (attOpenNew) attOpenNew.href = url;
    if (attDl) attDl.href = dlUrl;
    if (FBX_IMAGE_EXT.includes(ext)) {
      if (attImage) { attImage.src = url; attImage.alt = name; }
      if (attIframe) attIframe.src = 'about:blank';
      showFbxAttElement('image');
    } else if (FBX_PDF_EXT.includes(ext)) {
      if (attIframe) attIframe.src = url + '#view=FitH&toolbar=1';
      if (attImage) attImage.src = '';
      showFbxAttElement('iframe');
    } else if (FBX_IFRAME_EXT.includes(ext)) {
      if (attIframe) attIframe.src = url;
      if (attImage) attImage.src = '';
      showFbxAttElement('iframe');
    } else {
      if (attIframe) attIframe.src = 'about:blank';
      if (attImage) attImage.src = '';
      showFbxAttElement('fallback');
    }
    if (attModal) {
      if (typeof attModal.showModal === 'function') attModal.showModal();
      else attModal.setAttribute('open', '');
    }
  });
  attModal?.querySelectorAll('[data-att-close]').forEach((el) =>
    el.addEventListener('click', closeAtt));
  attModal?.addEventListener('cancel', (e) => { e.preventDefault(); closeAtt(); });

  /* ─── Cascade GED dans le sous-modal PJ (réutilise adjLoadGedChildren) ─── */
  const fbxAttRowMet = () => document.getElementById('fbx-att-row-metiers');
  const fbxAttRowN2  = () => document.getElementById('fbx-att-row-n2');
  const fbxAttRowN3  = () => document.getElementById('fbx-att-row-n3');
  const fbxAttRowN4  = () => document.getElementById('fbx-att-row-n4');
  const fbxAttRowN5  = () => document.getElementById('fbx-att-row-n5');

  function setFbxAttVal(level, code) {
    const inp = document.getElementById('fbx-att-' + level);
    if (inp) inp.value = code;
  }
  function getFbxAttVal(level) {
    return document.getElementById('fbx-att-' + level)?.value || '';
  }

  async function fbxAttPickN1(code) {
    setFbxAttVal('n1', code); setFbxAttVal('n2',''); setFbxAttVal('n3',''); setFbxAttVal('n4',''); setFbxAttVal('n5','');
    fbxAttRowMet().querySelectorAll('.fbx-choice-btn').forEach(b => b.classList.toggle('is-selected', b.dataset.code === code));
    fbxAttRowN3().innerHTML = '<div class="fbx-row-empty">— Choisir un domaine —</div>';
    fbxAttRowN4().innerHTML = '<div class="fbx-row-empty">— Choisir un sous-domaine —</div>';
    fbxAttRowN5().innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie —</div>';
    fbxAttRowN2().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await adjLoadGedChildren(2, { n1: code });
    adjRenderBtnGrid(fbxAttRowN2(), items, '— Aucun domaine —', fbxAttPickN2);
  }
  async function fbxAttPickN2(code) {
    setFbxAttVal('n2', code); setFbxAttVal('n3',''); setFbxAttVal('n4',''); setFbxAttVal('n5','');
    fbxAttRowN4().innerHTML = '<div class="fbx-row-empty">— Choisir un sous-domaine —</div>';
    fbxAttRowN5().innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie —</div>';
    fbxAttRowN3().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await adjLoadGedChildren(3, { n1: getFbxAttVal('n1'), n2: code });
    adjRenderBtnGrid(fbxAttRowN3(), items, '— Aucun sous-domaine —', fbxAttPickN3);
  }
  async function fbxAttPickN3(code) {
    setFbxAttVal('n3', code); setFbxAttVal('n4',''); setFbxAttVal('n5','');
    fbxAttRowN5().innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie —</div>';
    fbxAttRowN4().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await adjLoadGedChildren(4, { n1: getFbxAttVal('n1'), n2: getFbxAttVal('n2'), n3: code });
    adjRenderBtnGrid(fbxAttRowN4(), items, '— Aucune catégorie —', fbxAttPickN4);
  }
  async function fbxAttPickN4(code) {
    setFbxAttVal('n4', code); setFbxAttVal('n5','');
    fbxAttRowN5().innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await adjLoadGedChildren(5, { n1: getFbxAttVal('n1'), n2: getFbxAttVal('n2'), n3: getFbxAttVal('n3'), n4: code });
    adjRenderBtnGrid(fbxAttRowN5(), items, '— Aucune sous-catégorie —', (c) => setFbxAttVal('n5', c));
  }

  async function initFbxAttCascade(parent) {
    parent = parent || {};
    setFbxAttVal('n1', parent.n1 || '');
    setFbxAttVal('n2', parent.n2 || '');
    setFbxAttVal('n3', parent.n3 || '');
    setFbxAttVal('n4', parent.n4 || '');
    setFbxAttVal('n5', parent.n5 || '');

    // Charge les métiers
    let grouped = [];
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action: 'fbx_context', csrf: CSRF }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      grouped = data.ok ? (data.data.metiers_grouped || []) : [];
    } catch (_) {}

    // Renderer métiers (réutilise ADJ_METIER_DEF pour icônes/ordre)
    const row = fbxAttRowMet();
    if (!row) return;
    if (!grouped || grouped.length === 0) {
      row.innerHTML = '<div class="fbx-row-empty">Aucun métier seedé.</div>';
      return;
    }
    const allCodes = [];
    grouped.forEach(g => (g.codes || []).forEach(c => allCodes.push(c)));
    allCodes.sort((a, b) => (ADJ_METIER_DEF[a.code]?.pos ?? 999) - (ADJ_METIER_DEF[b.code]?.pos ?? 999));
    row.innerHTML = '';
    allCodes.forEach(c => {
      const def = ADJ_METIER_DEF[c.code] || { icon: '📁', label: (c.label || '').replace(/^\d+\s*-\s*/, '') };
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fbx-choice-btn';
      btn.dataset.code = c.code;
      if (c.code === parent.n1) btn.classList.add('is-selected');
      btn.title = c.code;
      btn.innerHTML = `<span class="fbx-choice-icon">${def.icon}</span><span class="fbx-choice-label">${def.label}</span>`;
      btn.addEventListener('click', () => fbxAttPickN1(c.code));
      row.appendChild(btn);
    });
    // Cascade aval si parent renseigné
    if (parent.n1) {
      const items2 = await adjLoadGedChildren(2, { n1: parent.n1 });
      adjRenderBtnGrid(fbxAttRowN2(), items2, '— Aucun domaine —', fbxAttPickN2, parent.n2);
    }
    if (parent.n1 && parent.n2) {
      const items3 = await adjLoadGedChildren(3, { n1: parent.n1, n2: parent.n2 });
      adjRenderBtnGrid(fbxAttRowN3(), items3, '— Aucun sous-domaine —', fbxAttPickN3, parent.n3);
    }
    if (parent.n1 && parent.n2 && parent.n3) {
      const items4 = await adjLoadGedChildren(4, { n1: parent.n1, n2: parent.n2, n3: parent.n3 });
      adjRenderBtnGrid(fbxAttRowN4(), items4, '— Aucune catégorie —', fbxAttPickN4, parent.n4);
    }
    if (parent.n1 && parent.n2 && parent.n3 && parent.n4) {
      const items5 = await adjLoadGedChildren(5, { n1: parent.n1, n2: parent.n2, n3: parent.n3, n4: parent.n4 });
      adjRenderBtnGrid(fbxAttRowN5(), items5, '— Aucune sous-catégorie —', (c) => setFbxAttVal('n5', c), parent.n5);
    }
  }

  // Handler du bouton "Enregistrer dans la GED" pour les PJ FluxBox
  document.getElementById('fbx-att-save')?.addEventListener('click', async () => {
    if (!_fbxCurrentAtt || _fbxCurrentAtt.idx < 0) {
      flash('Impossible de déterminer le contexte de la PJ.', 'error');
      return;
    }
    const saveBtn = document.getElementById('fbx-att-save');
    saveBtn.disabled = true;
    saveBtn.textContent = '⏳ Enregistrement…';
    const payload = {
      action: 'save_msg_attachment',
      carte_id: carteId,
      idx: _fbxCurrentAtt.idx,
      override_name: (document.getElementById('fbx-att-name-input')?.value || '').trim(),
    };
    ['n1','n2','n3','n4','n5'].forEach(k => {
      const v = (document.getElementById('fbx-att-' + k)?.value || '').trim();
      if (v) payload['override_' + k] = v;
    });
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ ...payload, csrf: CSRF }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.ok) {
        saveBtn.disabled = false;
        saveBtn.textContent = '💾 Enregistrer dans la GED';
        flash('Erreur : ' + ((data.errors || []).join(', ') || 'inconnue'), 'error');
        return;
      }
      const d = data.data || {};
      saveBtn.textContent = (d.is_duplicate ? '✅ Déjà enregistré' : '✅ Enregistré') +
                            (d.ged_doc_id ? ' (#' + d.ged_doc_id + ')' : '');
    } catch (e) {
      saveBtn.disabled = false;
      saveBtn.textContent = '💾 Enregistrer dans la GED';
      flash('Réseau : ' + e.message, 'error');
    }
  });

  /* ──────────────────────────────────────────────────────────
     Raccourcis clavier
     ────────────────────────────────────────────────────────── */
  document.addEventListener('keydown', (e) => {
    // Ne pas intercepter dans les inputs / modal ouverte
    const tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (modal && modal.hasAttribute('open')) return;

    if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      actionValidate();
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      actionLater();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      openAdjustModal();
    } else if (e.key === '?') {
      e.preventDefault();
      showHelp();
    }
  });

  /* ──────────────────────────────────────────────────────────
     Aide raccourcis (popup)
     ────────────────────────────────────────────────────────── */
  function setupKeyboardHelp() {
    document.addEventListener('keydown', (e) => {
      if (e.key === '?' && (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA')) {
        showHelp();
      }
    });
  }

  function showHelp() {
    if (document.querySelector('.fbx-help-popup')) return;
    const pop = document.createElement('div');
    pop.className = 'fbx-help-popup';
    pop.innerHTML = `
      <div style="background:#fff;padding:24px 28px;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-width:380px">
        <h3 style="margin:0 0 14px;color:#243B5C">⌨️ Raccourcis clavier</h3>
        <ul style="list-style:none;margin:0;padding:0;font-size:13px;line-height:2;color:#475569">
          <li><kbd>Espace</kbd> / <kbd>Entrée</kbd> &nbsp; Valider la carte</li>
          <li><kbd>←</kbd> &nbsp; Ouvrir Ajuster</li>
          <li><kbd>→</kbd> &nbsp; Plus tard (+1 jour)</li>
          <li><kbd>Esc</kbd> &nbsp; Fermer cette aide</li>
        </ul>
        <button style="margin-top:14px;width:100%;padding:10px;border:none;background:#243B5C;color:#fff;border-radius:8px;cursor:pointer">Fermer</button>
      </div>
    `;
    Object.assign(pop.style, {
      position: 'fixed', inset: '0', display: 'flex', alignItems: 'center', justifyContent: 'center',
      background: 'rgba(15,23,42,.5)', zIndex: '99999',
    });
    document.body.appendChild(pop);
    const close = () => pop.remove();
    pop.querySelector('button').addEventListener('click', close);
    pop.addEventListener('click', (e) => { if (e.target === pop) close(); });
    document.addEventListener('keydown', function escHandler(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); }
    });
  }

  setupKeyboardHelp();

  /* ──────────────────────────────────────────────────────────
     MOTEUR DE PROGRESSION (validation par chunks, barre live)
     ────────────────────────────────────────────────────────── */
  const CHUNK_SIZE = 25;
  const bulkOverlay = document.getElementById('fbx-bulk-overlay');
  const bulkDialog  = bulkOverlay?.querySelector('.fbx-bulk-dialog');
  const bulkTitle   = document.getElementById('fbx-bulk-title');
  const bulkDone    = document.getElementById('fbx-bulk-done');
  const bulkTotal   = document.getElementById('fbx-bulk-total');
  const bulkPct     = document.getElementById('fbx-bulk-pct');
  const bulkFill    = document.getElementById('fbx-bulk-fill');
  const bulkOk      = document.getElementById('fbx-bulk-ok');
  const bulkErr     = document.getElementById('fbx-bulk-err');
  const bulkChunkInfo = document.getElementById('fbx-bulk-chunk-info');
  const bulkCancel  = document.getElementById('fbx-bulk-cancel');
  const bulkClose   = document.getElementById('fbx-bulk-close');
  let bulkAbort = false;

  function bulkOpen(title, total) {
    if (!bulkOverlay) return;
    bulkAbort = false;
    bulkDialog?.classList.remove('is-done');
    bulkTitle.textContent = title;
    bulkDone.textContent = '0';
    bulkTotal.textContent = String(total);
    bulkPct.textContent = '0';
    bulkFill.style.width = '0%';
    bulkOk.textContent = '0';
    bulkErr.textContent = '0';
    bulkChunkInfo.textContent = `Lot 0 / ${Math.ceil(total / CHUNK_SIZE)}`;
    bulkCancel.style.display = '';
    bulkClose.style.display  = 'none';
    bulkOverlay.classList.add('is-open');
    bulkOverlay.setAttribute('aria-hidden', 'false');
  }
  function bulkUpdate({ done, total, ok, err, chunk, chunks, statusText }) {
    if (!bulkOverlay) return;
    if (typeof done   === 'number') bulkDone.textContent = done;
    if (typeof total  === 'number') bulkTotal.textContent = total;
    if (typeof ok     === 'number') bulkOk.textContent = ok;
    if (typeof err    === 'number') bulkErr.textContent = err;
    if (typeof chunk  === 'number' && typeof chunks === 'number')
      bulkChunkInfo.textContent = `Lot ${chunk} / ${chunks}`;
    const t = parseInt(bulkTotal.textContent, 10) || 1;
    const d = parseInt(bulkDone.textContent, 10) || 0;
    const pct = Math.min(100, Math.round((d / t) * 100));
    bulkPct.textContent = pct;
    bulkFill.style.width = pct + '%';
    if (statusText) bulkTitle.textContent = statusText;
  }
  function bulkFinish(title) {
    if (!bulkOverlay) return;
    bulkTitle.textContent = title;
    bulkDialog?.classList.add('is-done');
    bulkCancel.style.display = 'none';
    bulkClose.style.display  = '';
  }
  function bulkClosePopup() {
    if (!bulkOverlay) return;
    bulkOverlay.classList.remove('is-open');
    bulkOverlay.setAttribute('aria-hidden', 'true');
  }
  bulkCancel?.addEventListener('click', () => {
    bulkAbort = true;
    bulkTitle.textContent = '⏹ Annulation en cours…';
  });
  bulkClose?.addEventListener('click', () => {
    bulkClosePopup();
    window.location.reload();
  });

  /**
   * Traite une liste d'IDs par chunks via bulk_validate carte_ids.
   * @param {number[]} ids
   * @param {string} title titre affiché dans l'overlay
   */
  async function bulkValidateIds(ids, title = '⏳ Validation en masse…') {
    if (!ids || ids.length === 0) {
      flash('Aucune carte à valider.', 'error');
      return;
    }
    const total = ids.length;
    const chunks = Math.ceil(total / CHUNK_SIZE);
    let done = 0, ok = 0, err = 0;
    bulkOpen(title, total);
    for (let i = 0; i < chunks; i++) {
      if (bulkAbort) {
        bulkFinish(`⏹ Annulé — ${ok} validées avant arrêt`);
        return;
      }
      const slice = ids.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE);
      try {
        const r = await callApi('bulk_validate', { carte_ids: slice });
        if (r.ok) {
          const d = r.data || {};
          ok  += (d.validated || 0);
          err += (d.failed    || 0);
        } else {
          err += slice.length;
        }
      } catch (e) {
        err += slice.length;
      }
      done += slice.length;
      bulkUpdate({ done, ok, err, chunk: i + 1, chunks });
    }
    bulkFinish(`✅ Terminé — ${ok} validées · ${err} erreurs`);
  }

  /* ──────────────────────────────────────────────────────────
     SÉLECTION MULTIPLE (batch list)
     ────────────────────────────────────────────────────────── */
  const batch = document.getElementById('fbx-batch');
  if (batch) {
    const list      = document.getElementById('fbx-batch-list');
    const cbAll     = document.getElementById('fbx-batch-all');
    const cbIaReady = document.getElementById('fbx-batch-ia-ready');
    const counter   = document.getElementById('fbx-batch-selected');
    const toolbar   = document.getElementById('fbx-batch-toolbar');
    let lastCheckedIndex = -1;

    function getCheckboxes() {
      return Array.from(list.querySelectorAll('.fbx-batch-cb'));
    }
    const counterTop = document.getElementById('fbx-batch-selected-top');
    function updateUI() {
      const cbs = getCheckboxes();
      const selected = cbs.filter(c => c.checked);
      counter.textContent = selected.length;
      if (counterTop) counterTop.textContent = selected.length;
      toolbar.classList.toggle('is-hidden', selected.length === 0);
      // Marquer visuellement les lignes cochées
      cbs.forEach(cb => {
        const li = cb.closest('.fbx-batch-item');
        if (li) li.classList.toggle('is-checked', cb.checked);
      });
      // Sync de la checkbox "Tout cocher"
      if (cbAll) {
        cbAll.checked = selected.length > 0 && selected.length === cbs.length;
        cbAll.indeterminate = selected.length > 0 && selected.length < cbs.length;
      }
    }

    // Tout cocher / décocher
    cbAll?.addEventListener('change', () => {
      const cbs = getCheckboxes();
      cbs.forEach(c => { c.checked = cbAll.checked; });
      updateUI();
    });

    // Cocher uniquement les IA-prêtes
    cbIaReady?.addEventListener('click', () => {
      const cbs = getCheckboxes();
      cbs.forEach(c => {
        const li = c.closest('.fbx-batch-item');
        const conf = parseFloat(li?.dataset.confiance || '0');
        if (conf >= 85) c.checked = true;
      });
      updateUI();
    });

    // Click sur ligne / checkbox (avec support Shift+click pour plage)
    list.addEventListener('click', (e) => {
      const cb = e.target.closest('.fbx-batch-cb');
      if (!cb) return;
      const cbs = getCheckboxes();
      const idx = cbs.indexOf(cb);
      if (e.shiftKey && lastCheckedIndex >= 0 && idx !== lastCheckedIndex) {
        const [start, end] = idx > lastCheckedIndex ? [lastCheckedIndex, idx] : [idx, lastCheckedIndex];
        const targetState = cb.checked;
        for (let i = start; i <= end; i++) cbs[i].checked = targetState;
      }
      lastCheckedIndex = idx;
      updateUI();
    });

    // Actions toolbar (HAUT + BAS) : valider / plus tard la sélection
    batch.querySelectorAll('[data-batch-action]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const action = btn.getAttribute('data-batch-action');
        const ids = getCheckboxes().filter(c => c.checked).map(c => parseInt(c.value, 10));
        if (ids.length === 0) return;
        const confirmMsg = action === 'validate'
          ? `Valider ${ids.length} carte${ids.length>1?'s':''} ?`
          : `Reporter ${ids.length} carte${ids.length>1?'s':''} à plus tard ?`;
        if (!confirm(confirmMsg)) return;

        if (action === 'validate') {
          await bulkValidateIds(ids, `⏳ Validation de ${ids.length} carte${ids.length>1?'s':''}…`);
        } else if (action === 'later') {
          // "Plus tard" en chunks aussi (boucle séquentielle dans l'overlay)
          await bulkLaterIds(ids);
        }
      });
    });

    // Reporter une liste à demain (avec progression)
    async function bulkLaterIds(ids) {
      const total = ids.length;
      bulkOpen(`⏳ Report de ${total} carte${total>1?'s':''}…`, total);
      let done = 0, ok = 0, err = 0;
      const chunks = Math.ceil(total / CHUNK_SIZE);
      for (let i = 0; i < chunks; i++) {
        if (bulkAbort) {
          bulkFinish(`⏹ Annulé — ${ok} reportées avant arrêt`);
          return;
        }
        const slice = ids.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE);
        for (const id of slice) {
          try {
            const r = await callApi('later', { carte_id: id, until: '+1 day' });
            if (r.ok) ok++; else err++;
          } catch (_) { err++; }
        }
        done += slice.length;
        bulkUpdate({ done, ok, err, chunk: i + 1, chunks });
      }
      bulkFinish(`✅ Terminé — ${ok} reportées · ${err} erreurs`);
    }

    updateUI();
  }

  /* ──────────────────────────────────────────────────────────
     BOUTON "TOUT VALIDER EN MASSE" (hors liste batch)
     ────────────────────────────────────────────────────────── */
  const massBtn = document.getElementById('fbx-mass-validate');
  if (massBtn) {
    massBtn.addEventListener('click', async () => {
      // Détecte le filtre source depuis l'URL
      const urlParams = new URLSearchParams(window.location.search);
      const source = urlParams.get('source') || 'all';
      try {
        // Dry-run pour récupérer la liste exacte des IDs éligibles
        const dryRes = await callApi('bulk_validate', { source, min_confiance: 60, limit: 5000, dry_run: 1 });
        const ids = dryRes.ok ? (dryRes.data?.carte_ids || []) : [];
        if (ids.length === 0) {
          flash('Aucune carte éligible (confiance ≥ 60%) pour validation en masse.', 'error');
          return;
        }
        if (!confirm(`Valider en masse ${ids.length} carte${ids.length>1?'s':''} où l'IA est sûre (confiance ≥ 60%) ?\n\nLes cartes incertaines (< 60%) resteront à valider manuellement.`)) return;
        // Chunk avec barre live
        await bulkValidateIds(ids, `⏳ Validation en masse de ${ids.length} carte${ids.length>1?'s':''}…`);
      } catch (e) {
        flash('Erreur : ' + e.message, 'error');
      }
    });
  }

  /* Scroll smooth pour le lien "📋 Sélection multiple" */
  document.querySelectorAll('a[href="#fbx-batch"]').forEach(a => {
    a.addEventListener('click', (e) => {
      e.preventDefault();
      const target = document.getElementById('fbx-batch');
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
