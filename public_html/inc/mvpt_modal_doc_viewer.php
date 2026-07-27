<?php
/**
 * inc/mvpt_modal_doc_viewer.php
 *
 * Modal UNIVERSEL de visualisation d'un document GED — réutilisable sur toutes les pages.
 * Disposition 2 colonnes : champs extraits À GAUCHE + aperçu du document À DROITE.
 *
 * Usage :
 *   mvptModalView(docId, name)            → aperçu seul (rétro-compatible)
 *   mvptModalView(docId, name, fields)    → + panneau de champs à gauche
 *      fields = [ {section:'Finances'}, {label:'Loyer HC', value:'1 000 €'}, ... ]
 *      (les entrées {section:'…'} créent un sous-titre ; value vide → ligne ignorée)
 *
 *   <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
 */
?>
<div id="mvptModalBackdrop" class="mvpt-modal-backdrop" onclick="if (event.target === this) mvptModalClose()">
    <div class="mvpt-modal" id="mvptModal">
        <div class="mvpt-modal-header">
            <h3 id="mvptModalTitle">📄 Document</h3>
            <div class="mvpt-modal-header-actions">
                <button class="mvpt-modal-reclass" onclick="mvptModalRename()" title="Renommer / reclasser : entité · type · libellé · date (le nom GED est régénéré)">✎ Renommer / reclasser</button>
                <a id="mvptModalOpen" class="mvpt-modal-open" href="#" target="_blank" rel="noopener" title="Ouvrir dans un nouvel onglet">↗ Ouvrir</a>
                <button class="mvpt-modal-close" onclick="mvptModalClose()">✕ Fermer</button>
            </div>
        </div>
        <div id="mvptReclassPanel" style="display:none;padding:12px 16px;border-bottom:1px solid #eee;background:#faf9ff;">
            <div style="font-size:12px;font-weight:800;color:#243B5C;margin-bottom:8px;">✎ Reclasser le document</div>
            <div style="display:grid;grid-template-columns:1fr;gap:8px;">
                <div>
                    <label style="font-size:11px;font-weight:700;color:#5a5650;display:block;margin-bottom:2px;">1 · Attribuer à une entité</label>
                    <input type="text" id="mvptRcEnt" autocomplete="off" placeholder="Bien, immeuble, locataire, propriétaire, créancier…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                    <div id="mvptRcEntChosen" style="font-size:12px;color:#15803d;margin-top:3px;"></div>
                    <div id="mvptRcEntResults" style="margin-top:4px;"></div>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#5a5650;display:block;margin-bottom:2px;">2 · Type de document</label>
                    <input type="text" id="mvptRcType" list="mvptRcTypeList" autocomplete="off" placeholder="Ex. Bail signé, DPE, Convocation AG…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                    <datalist id="mvptRcTypeList"></datalist>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <div style="flex:2;min-width:160px;">
                        <label style="font-size:11px;font-weight:700;color:#5a5650;display:block;margin-bottom:2px;">3 · Libellé</label>
                        <input type="text" id="mvptRcLib" placeholder="Ex. ORDINAIRE, MISE EN DEMEURE…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                    </div>
                    <div style="flex:1;min-width:130px;">
                        <label style="font-size:11px;font-weight:700;color:#5a5650;display:block;margin-bottom:2px;">4 · Date</label>
                        <input type="date" id="mvptRcDate" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:2px;">
                    <button type="button" onclick="mvptReclassClose()" style="border:1px solid #cbd8da;background:#fff;color:#5b6b70;border-radius:8px;padding:7px 14px;font-weight:800;cursor:pointer;font-size:12.5px;">Annuler</button>
                    <button type="button" id="mvptRcSave" onclick="mvptReclassSave()" style="border:none;background:#243B5C;color:#fff;border-radius:8px;padding:7px 16px;font-weight:800;cursor:pointer;font-size:12.5px;">💾 Enregistrer</button>
                </div>
                <div id="mvptRcMsg" style="font-size:12px;"></div>
            </div>
        </div>
        <div class="mvpt-modal-cols">
            <aside class="mvpt-fields" id="mvptModalFields" style="display:none;"></aside>
            <div class="mvpt-modal-body" id="mvptModalBody">
                <div class="mvpt-modal-loading">⏳ Chargement…</div>
            </div>
        </div>
        <div class="mvpt-modal-footer" id="mvptModalFooter">
            <span id="mvptFootMeta">—</span>
            <button type="button" class="mvpt-foot-del" onclick="mvptModalDelete()" title="Archiver ou supprimer ce document">🗑️ Supprimer</button>
        </div>
    </div>
</div>
<style>
.mvpt-modal-backdrop { position: fixed; inset: 0; z-index: 9000; background: rgba(15,18,24,.55);
    align-items: center; justify-content: center; }
.mvpt-modal-backdrop:not(.open) { display: none !important; }
.mvpt-modal-backdrop.open { display: flex; }
.mvpt-modal { background: #fff; border-radius: 14px; width: min(960px, 94vw); max-height: 92vh;
    display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
.mvpt-modal.with-fields { width: min(1240px, 96vw); }
.mvpt-modal-header { display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-bottom: 1px solid #eef0f2; }
.mvpt-modal-header h3 { margin: 0; font-size: 16px; }
.mvpt-modal-cols { flex: 1; display: flex; min-height: 0; overflow: hidden; }
.mvpt-fields { width: 340px; flex: none; overflow: auto; background: #faf8ff; border-right: 1px solid #ece7f5;
    padding: 14px 16px; }
.mvpt-fields h4 { margin: 0 0 10px; font-size: 13px; color: #5b21b6; text-transform: uppercase; letter-spacing: .03em; }
.mvpt-f-sec { font-size: 11px; font-weight: 800; color: #7a766f; text-transform: uppercase; letter-spacing: .03em;
    margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ece7f5; }
.mvpt-f-sec:first-of-type { margin-top: 0; }
.mvpt-f-row { display: flex; flex-direction: column; gap: 1px; padding: 5px 0; border-bottom: 1px dashed #f0ecf6; }
.mvpt-f-lbl { font-size: 10.5px; color: #9a9690; text-transform: uppercase; letter-spacing: .02em; }
.mvpt-f-val { font-size: 13px; color: #2c2a28; font-weight: 600; word-break: break-word; }
.mvpt-modal-body { flex: 1; overflow: auto; min-height: 260px; background: #f8fafc; }
.mvpt-modal-body iframe { width: 100%; height: 78vh; border: 0; display: block; }
.mvpt-modal-body img { max-width: 100%; display: block; margin: 0 auto; }
.mvpt-modal-footer { padding: 10px 18px; border-top: 1px solid #eef0f2; font-size: 12px; color: #6b7280; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mvpt-foot-del { flex: none; border: 1px solid #e4b9b2; background: #fdecea; color: #c0392b; border-radius: 8px; padding: 6px 14px; font-size: 12.5px; font-weight: 800; cursor: pointer; }
.mvpt-foot-del:hover { background: #c0392b; color: #fff; border-color: #c0392b; }
.mvpt-modal-loading { padding: 34px; text-align: center; color: #6b7280; font-size: 14px; }
.mvpt-modal-close, .mvpt-modal-open { padding: 6px 12px; background: #eceef1; border: 1px solid #d6dade; color: #374151;
    border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; text-decoration: none; }
.mvpt-modal-close:hover, .mvpt-modal-open:hover { background: #dfe3e7; }
.mvpt-modal-header-actions { display: flex; gap: 8px; align-items: center; }
.mvpt-modal-reclass { padding: 6px 14px; background: #fef9e7; border: 1px solid #d4a047; color: #92400e;
    border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; transition: all .15s; }
.mvpt-modal-reclass:hover { background: #d4a047; color: #fff; }
@media (max-width: 780px){ .mvpt-modal-cols{ flex-direction: column; } .mvpt-fields{ width: auto; max-height: 30vh; border-right: 0; border-bottom: 1px solid #ece7f5; } }
/* ── Vue mail .msg inline ── */
.mvpt-word { max-height: 78vh; overflow-y: auto; padding: 22px 30px; background: #fff; }
.mvpt-word-note { background: #fffbeb; border: 1px solid rgba(234,179,8,0.4); color: #92400e; font-size: 12.5px; padding: 8px 12px; border-radius: 9px; margin-bottom: 16px; }
.mvpt-word-doc { max-width: 820px; margin: 0 auto; font-size: 14px; line-height: 1.6; color: #1e293b; }
.mvpt-word-doc p { margin: 0 0 8px; white-space: pre-wrap; word-wrap: break-word; }
.mvpt-msg { max-height: 78vh; overflow-y: auto; padding: 20px 26px; }
.mvpt-msg-head { border-bottom: 1px solid #eef0f2; padding-bottom: 14px; margin-bottom: 14px; }
.mvpt-msg-subject { font-size: 18px; font-weight: 800; color: #243B5C; margin-bottom: 10px; }
.mvpt-msg-meta { font-size: 13px; color: #475569; margin: 3px 0; }
.mvpt-msg-meta b { color: #64748b; font-weight: 700; margin-right: 4px; }
.mvpt-msg-ia { background: #f4f7fb; border: 1px solid #dbe6f2; border-left: 3px solid #316887; border-radius: 8px;
    padding: 10px 14px; margin: 14px 0; font-size: 13px; color: #1e3a52; }
.mvpt-msg-ia b { color: #316887; }
.mvpt-msg-warn { background: #fef9e7; border: 1px solid #f0d98a; border-radius: 8px; padding: 10px 14px;
    margin: 12px 0; font-size: 12.5px; color: #92400e; }
.mvpt-msg-body { white-space: pre-wrap; word-break: break-word; font-size: 13.5px; line-height: 1.55; color: #1f2937; }
</style>

<script>
(function() {
    let mvptCurrentDocId = 0;
    let mvptCurrentName = '';
    let mvptDocData = null;   // dernières métadonnées chargées (ged_document + links) pour pré-remplir le reclassement
    // URLs API préfixées par la base de l'app (sinon 404 en local sous sous-dossier).
    const MVPT_SERVE = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_doc_serve.php') : '/api/ged_doc_serve.php') ?>;
    const MVPT_INFO  = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_doc_info.php') : '/api/ged_doc_info.php') ?>;
    const MVPT_FBX   = <?= json_encode(function_exists('app_url') ? app_url('/api/fluxbox_action.php') : '/api/fluxbox_action.php') ?>;
    const MVPT_WORD  = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_word_preview.php') : '/api/ged_word_preview.php') ?>;
    const esc = function(s){ const d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; };

    function renderFields(fields){
        const panel = document.getElementById('mvptModalFields');
        const modal = document.getElementById('mvptModal');
        if (!fields || !fields.length){ panel.style.display='none'; panel.innerHTML=''; modal.classList.remove('with-fields'); return; }
        let html = '<h4>🧾 Données extraites</h4>';
        fields.forEach(function(f){
            if (f && f.section){ html += '<div class="mvpt-f-sec">'+esc(f.section)+'</div>'; return; }
            if (!f || f.value === null || f.value === undefined || String(f.value).trim()===''){ return; }
            html += '<div class="mvpt-f-row"><div class="mvpt-f-lbl">'+esc(f.label||'')+'</div><div class="mvpt-f-val">'+esc(f.value)+'</div></div>';
        });
        panel.innerHTML = html;
        panel.style.display = 'block';
        modal.classList.add('with-fields');
    }

    // Lecture inline d'un mail .msg (expéditeur, objet, corps) via l'action read_msg.
    async function renderWordInline(docId, body, doc) {
        const dlUrl = MVPT_SERVE + '?id=' + docId;
        try {
            const r = await fetch(MVPT_WORD + '?id=' + encodeURIComponent(docId), { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'extraction impossible');
            const approx = (j.mode === 'doc')
                ? '<div class="mvpt-word-note">⚠️ Ancien format .doc — extraction texte approximative (mise en forme non conservée). Pour l’original, utilisez « Ouvrir ».</div>'
                : '';
            body.innerHTML =
                '<div class="mvpt-word">' + approx +
                '<div class="mvpt-word-doc">' + (j.html || '<p><em>(vide)</em></p>') + '</div>' +
                '</div>';
        } catch (e) {
            body.innerHTML = '<div class="mvpt-modal-loading">📎 Aperçu Word indisponible (' + esc(e.message) + ')<br><br>' +
                '<a href="' + dlUrl + '" target="_blank" rel="noopener" style="color:#3D7465;font-weight:700;text-decoration:underline;">↗ Ouvrir / télécharger le document</a></div>';
        }
    }
    async function renderMsgInline(docId, body, doc) {
        try {
            const r = await fetch(MVPT_FBX + '?action=read_msg&ged_doc_id=' + encodeURIComponent(docId), { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok || !j.data) throw new Error((j.errors || ['lecture impossible']).join(', '));
            const d = j.data;
            const bodyText   = (d.body_text || '').trim();
            const incomplete = !d.subject && !bodyText;

            let html = '<div class="mvpt-msg">';
            html += '<div class="mvpt-msg-head">';
            html += '<div class="mvpt-msg-subject">✉️ ' + (esc(d.subject) || '<em style="color:#94a3b8">(sans objet)</em>') + '</div>';
            html += '<div class="mvpt-msg-meta"><b>De</b> ' + (esc(d.from) || '<em style="color:#94a3b8">non détecté</em>') +
                    (d.from_email ? ' &lt;' + esc(d.from_email) + '&gt;' : '') + '</div>';
            if (d.to && d.to.length >= 3)  html += '<div class="mvpt-msg-meta"><b>À</b> ' + esc(d.to) + '</div>';
            if (d.date_sent) html += '<div class="mvpt-msg-meta"><b>Date</b> ' + esc(d.date_sent) + '</div>';
            html += '</div>';

            // Motif / immeuble : classement métier déjà résolu (parent_classement du doc)
            const pc = d.parent_classement || {};
            if (pc && (pc.n3 || pc.n1)) {
                html += '<div class="mvpt-msg-ia">📌 <b>Classé</b> : ' +
                    esc([pc.n1, pc.n2, pc.n3, pc.n4].filter(Boolean).join(' › ') || '—') + '</div>';
            }

            if (incomplete) {
                html += '<div class="mvpt-msg-warn">⚠️ Lecture .msg partielle (format Outlook binaire). ' +
                        'Télécharge le fichier ci-dessus (« Ouvrir ») pour l\'afficher complet dans Outlook.</div>';
            } else if ((d.warnings || []).length) {
                html += '<div class="mvpt-msg-warn">⚠️ ' + esc(d.warnings.join(' · ')) + '</div>';
            }

            html += '<div class="mvpt-msg-body">' + (esc(bodyText) ||
                    '<em style="color:#94a3b8">Corps non extrait — ouvrir le .msg dans Outlook.</em>') + '</div>';
            html += '</div>';
            body.innerHTML = html;
        } catch (e) {
            body.innerHTML = '<div class="mvpt-modal-loading">📎 Mail illisible : ' + esc(e.message) +
                '<br><br>Télécharge le .msg (« Ouvrir ») pour l\'afficher dans Outlook.</div>';
        }
    }

    // Reclassement = panneau inline DIRECT (entité · type · libellé · date → api/ged_doc_reclassify.php).
    // Plus de renvoi vers fluxbox_pile.php (jugé inutile). Alias conservé pour compat des appels existants.
    window.mvptModalReclass = function() { return window.mvptModalRename(); };

    // Supprimer/archiver → réutilise le modal générique ged_delete_modal (confirmation archiver/supprimer).
    window.mvptModalDelete = function() {
        if (mvptCurrentDocId <= 0) return;
        if (typeof window.gedDeleteDoc === 'function') window.gedDeleteDoc(mvptCurrentDocId, mvptCurrentName);
        else if (confirm('Supprimer ce document ?')) { /* repli : rien si le modal n'est pas chargé */ }
    };
    // Après suppression/archivage : fermer le viewer + rafraîchir la fiche.
    document.addEventListener('ged-doc-deleted', function(ev) {
        if (ev.detail && +ev.detail.id === +mvptCurrentDocId) {
            window.FBX_FICHE_DIRTY = true;
            if (typeof window.mvptModalClose === 'function') window.mvptModalClose();
            setTimeout(function(){ location.reload(); }, 300);
        }
    });

    // ── Reclassement en 3 niveaux : ENTITÉ + LIBELLÉ + DATE (le moteur régénère le nom GED) ──
    const MVPT_CSRF      = <?= json_encode(function_exists('csrf_token') ? csrf_token('default') : '') ?>;
    const MVPT_RECLASS   = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_doc_reclassify.php') : '/api/ged_doc_reclassify.php') ?>;
    const MVPT_ENTSEARCH = <?= json_encode(function_exists('app_url') ? app_url('/api/fluxbox_entity_search.php') : '/api/fluxbox_entity_search.php') ?>;
    const MVPT_SETTYPE   = <?= json_encode(function_exists('app_url') ? app_url('/api/maboxoffice_settype.php') : '/api/maboxoffice_settype.php') ?>;
    let mvptRcEnt = { type:'', id:0, label:'' };
    let mvptTypeMap = null;   // { libelleLC: code }  (glossaire des types)
    function mvptLoadTypes(){
        if (mvptTypeMap) return;
        mvptTypeMap = {};
        fetch(MVPT_SETTYPE+'?list=1',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            var dl=document.getElementById('mvptRcTypeList'); var html='';
            ((d&&d.types)||[]).forEach(function(t){
                mvptTypeMap[(t.libelle||'').toLowerCase()]=t.code; mvptTypeMap[(t.code||'').toLowerCase()]=t.code;
                html+='<option value="'+esc(t.libelle||t.code)+'">'+esc(t.code)+'</option>';
            });
            if(dl) dl.innerHTML=html;
        }).catch(function(){});
    }
    function mvptResolveType(){
        var v=(document.getElementById('mvptRcType').value||'').trim(); if(v==='') return '';
        return mvptTypeMap[v.toLowerCase()] || v.toUpperCase().replace(/[^A-Z0-9]+/g,'_');
    }
    window.mvptReclassClose = function(){ document.getElementById('mvptReclassPanel').style.display='none'; };
    window.mvptModalRename = async function(){
        if (mvptCurrentDocId <= 0) return;
        mvptLoadTypes();
        var reqId = mvptCurrentDocId;
        // ── PRÉ-REMPLISSAGE : on RECHARGE les infos FRAÎCHES du doc courant (jamais de cache périmé). ──
        document.getElementById('mvptReclassPanel').style.display='block';
        document.getElementById('mvptRcMsg').textContent='⏳ Chargement des infos…';
        var doc = {}, links = [];
        try {
            var _r = await fetch(MVPT_INFO + '?id=' + reqId, {credentials:'same-origin'});
            var _j = await _r.json();
            if (reqId !== mvptCurrentDocId) return;   // l'utilisateur a changé de doc entre-temps
            doc = _j.ged_document || {}; links = _j.links || [];
        } catch(e){}
        // 1) Entité : lien principal ('main') sinon 1er lien.
        var main = null;
        for (var i=0;i<links.length;i++){ if((links[i].relation_type||'')==='main'){ main=links[i]; break; } }
        if(!main && links.length) main = links[0];
        if(main){
            mvptRcEnt = { type:(main.entity_type||''), id:parseInt(main.entity_id,10)||0, label:(main.entity_type||'')+' #'+main.entity_id };
            document.getElementById('mvptRcEntChosen').textContent = '✓ '+mvptRcEnt.label+' (actuel)';
        } else {
            mvptRcEnt = { type:'', id:0, label:'' };
            document.getElementById('mvptRcEntChosen').textContent = '';
        }
        document.getElementById('mvptRcEnt').value='';
        document.getElementById('mvptRcEntResults').innerHTML='';
        // 2) Type de document (code existant).
        document.getElementById('mvptRcType').value = (doc.document_type||'');
        // 3) Libellé + 4) Date : depuis metadata.extra (posés lors d'un précédent reclassement).
        var lib='', dd='';
        try { var meta = typeof doc.metadata==='string' ? JSON.parse(doc.metadata||'{}') : (doc.metadata||{});
              var ex = (meta && meta.extra) || {}; lib = ex.libelle||''; dd = ex.date_doc||''; } catch(e){}
        document.getElementById('mvptRcLib').value = lib;
        // date_doc stockée en JJ-MM-AAAA → input date (AAAA-MM-JJ).
        var iso=''; var m = /^(\d{2})-(\d{2})-(\d{4})$/.exec(dd||''); if(m) iso = m[3]+'-'+m[2]+'-'+m[1]; else if(/^\d{4}-\d{2}-\d{2}$/.test(dd||'')) iso = dd;
        document.getElementById('mvptRcDate').value = iso;
        document.getElementById('mvptRcMsg').textContent='';
        document.getElementById('mvptReclassPanel').style.display='block';
        document.getElementById('mvptRcLib').focus();
    };
    let _mvptRcTimer=null;
    document.getElementById('mvptRcEnt').addEventListener('input', function(){
        const q=this.value.trim(); const box=document.getElementById('mvptRcEntResults');
        if(q.length<2){ box.innerHTML=''; return; }
        clearTimeout(_mvptRcTimer);
        _mvptRcTimer=setTimeout(function(){
            fetch(MVPT_ENTSEARCH+'?q='+encodeURIComponent(q),{credentials:'same-origin'})
              .then(function(r){return r.json();}).then(function(d){
                const rs=(d&&d.results)||[]; if(!rs.length){ box.innerHTML='<div style="font-size:11px;color:#9a9690;">Aucun résultat</div>'; return; }
                box.innerHTML=rs.slice(0,8).map(function(r){
                    const rep=[r.repere1,r.repere2].filter(Boolean).join(' · ');
                    return '<div class="mvptRcItem" data-t="'+esc(r.entity_type)+'" data-i="'+r.id+'" data-l="'+esc(r.label)+'" style="padding:6px 8px;border:1px solid #eee;border-radius:6px;margin-top:3px;cursor:pointer;font-size:12px;" onmouseover="this.style.background=\'#f0eefe\'" onmouseout="this.style.background=\'#fff\'"><b>'+esc(r.badge||'')+'</b> · '+esc(r.label)+(rep?' <span style="color:#9a9690;">('+esc(rep)+')</span>':'')+'</div>';
                }).join('');
                Array.from(box.querySelectorAll('.mvptRcItem')).forEach(function(el){
                    el.addEventListener('click', function(){
                        mvptRcEnt={ type:el.dataset.t, id:parseInt(el.dataset.i,10)||0, label:el.dataset.l };
                        document.getElementById('mvptRcEntChosen').textContent='✅ '+el.dataset.l;
                        box.innerHTML=''; document.getElementById('mvptRcEnt').value=el.dataset.l;
                    });
                });
              }).catch(function(){});
        },250);
    });
    window.mvptReclassSave = function(){
        if (mvptCurrentDocId<=0) return;
        const lib=document.getElementById('mvptRcLib').value.trim();
        const dt =document.getElementById('mvptRcDate').value;
        const msg=document.getElementById('mvptRcMsg'); const btn=document.getElementById('mvptRcSave');
        const payload={ ged_id:mvptCurrentDocId, libelle:lib, date:dt, type_doc:mvptResolveType(), csrf:MVPT_CSRF };
        if(mvptRcEnt.id>0){ payload.entity_type=mvptRcEnt.type; payload.entity_id=mvptRcEnt.id; }
        btn.disabled=true; msg.style.color='#5a5650'; msg.textContent='⏳ Enregistrement…';
        fetch(MVPT_RECLASS,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':MVPT_CSRF},body:JSON.stringify(payload),credentials:'same-origin'})
          .then(function(r){return r.json();}).then(function(d){
            btn.disabled=false;
            if(d&&d.ok){
                mvptCurrentName=d.name||mvptCurrentName;
                document.getElementById('mvptModalTitle').textContent='📄 '+mvptCurrentName;
                msg.style.color='#15803d'; msg.textContent='✅ Enregistré'+(d.chain?' → '+d.chain:'')+' — actualisation…';
                window.FBX_FICHE_DIRTY=true;
                setTimeout(function(){ location.reload(); }, 700);
            } else { msg.style.color='#c0392b'; msg.textContent='❌ '+((d&&d.error)||'Échec'); }
          }).catch(function(e){ btn.disabled=false; msg.style.color='#c0392b'; msg.textContent='❌ Réseau : '+e; });
    };

    // 3e argument optionnel `fields` = panneau gauche (champs extraits).
    // Mode IMAGE (photos bien/immeuble) : même coquille, sans les actions GED (reclasser/supprimer).
    window.mvptImageView = function(url, name) {
        mvptCurrentDocId = 0; mvptCurrentName = name || ''; mvptDocData = null;
        document.querySelectorAll('.mvpt-modal-reclass').forEach(function(b){ b.style.display = 'none'; });
        var del = document.querySelector('.mvpt-foot-del'); if (del) del.style.display = 'none';
        var fld = document.getElementById('mvptModalFields'); if (fld) fld.style.display = 'none';
        document.getElementById('mvptModalTitle').textContent = '🖼️ ' + (name || 'Photo');
        document.getElementById('mvptModalBody').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;background:#0b1220;min-height:60vh;"><img src="' + url + '" alt="" style="max-width:100%;max-height:82vh;object-fit:contain;"></div>';
        document.getElementById('mvptFootMeta').textContent = name || '';
        var op = document.getElementById('mvptModalOpen'); if (op) op.href = url;
        document.getElementById('mvptModalBackdrop').classList.add('open');
    };

    window.mvptModalView = async function(docId, name, fields) {
        // Restaure les actions GED (au cas où on vient d'un affichage photo).
        document.querySelectorAll('.mvpt-modal-reclass').forEach(function(b){ b.style.display = ''; });
        var _del = document.querySelector('.mvpt-foot-del'); if (_del) _del.style.display = '';
        mvptCurrentDocId = docId;
        mvptCurrentName = name || '';
        const backdrop = document.getElementById('mvptModalBackdrop');
        const title    = document.getElementById('mvptModalTitle');
        const body     = document.getElementById('mvptModalBody');
        const footer   = document.getElementById('mvptFootMeta');
        const openLink = document.getElementById('mvptModalOpen');

        title.textContent = '📄 ' + (name || 'Document #' + docId);
        body.innerHTML = '<div class="mvpt-modal-loading">⏳ Chargement…</div>';
        footer.textContent = 'doc #' + docId + ' · récupération métadonnées…';
        openLink.href = MVPT_SERVE + '?id=' + docId;
        renderFields(fields);
        backdrop.classList.add('open');

        try {
            const r = await fetch(MVPT_INFO + '?id=' + docId, {credentials:'same-origin'});
            if (!r.ok) {
                // Remonte le motif exact du serveur (ex. « Hors société », « chemin hors zone »)
                let motif = '';
                try { const t = await r.text(); try { motif = (JSON.parse(t).error || '').toString(); } catch(_) { motif = t; } } catch(_) {}
                throw new Error('HTTP ' + r.status + (motif ? ' — ' + motif.slice(0, 160) : ''));
            }
            const j = await r.json();
            if (j.error) throw new Error(j.error);
            if (!j.ged_document) throw new Error('Document introuvable');

            const doc = j.ged_document;
            mvptDocData = j;   // mémorise pour pré-remplir « Renommer / reclasser »
            const mime = (doc.mime_type || '').toLowerCase();
            const viewerUrl = MVPT_SERVE + '?id=' + docId;

            const nameLc  = (doc.name_file || name || '').toLowerCase();
            const typeUc  = (doc.document_type || '').toUpperCase();
            const isMsg   = nameLc.endsWith('.msg') || typeUc === 'EMAIL';
            const isWord  = mime.includes('msword') || mime.includes('wordprocessing')
                         || mime.includes('officedocument.word') || mime.includes('rtf')
                         || nameLc.endsWith('.doc') || nameLc.endsWith('.docx') || nameLc.endsWith('.rtf');

            if (isMsg) {
                body.innerHTML = '<div class="mvpt-modal-loading">⏳ Lecture du mail…</div>';
                renderMsgInline(docId, body, doc);
            } else if (mime.includes('pdf')) {
                // #navpanes=0 masque le volet de vignettes/pages du lecteur PDF (encombrant).
                body.innerHTML = '<iframe src="' + viewerUrl + '#toolbar=1&navpanes=0&statusbar=0&view=FitH" title="' + esc(doc.name_file || '') + '"></iframe>';
            } else if (mime.startsWith('image/')) {
                body.innerHTML = '<img src="' + viewerUrl + '" alt="' + esc(doc.name_file || '') + '">';
            } else if (isWord) {
                body.innerHTML = '<div class="mvpt-modal-loading">⏳ Lecture du document Word…</div>';
                renderWordInline(docId, body, doc);
            } else {
                body.innerHTML = '<div class="mvpt-modal-loading">📎 Aperçu non disponible pour ce type (' + esc(mime) + ')<br><br>' +
                    'Type : ' + esc(doc.document_type || '—') + '<br>Module : ' + esc(doc.source_module || '—') + '<br>' +
                    'Taille : ' + Math.round((doc.size_bytes || 0)/1024) + ' Ko</div>';
            }

            const linkCount = (j.links || []).length;
            const folderName = j.folder ? j.folder.name_display : '—';
            footer.innerHTML = 'doc #<code>' + doc.id + '</code> · type <code>' + esc(doc.document_type || '—') + '</code> · ' +
                'mod <code>' + esc(doc.source_module || '—') + '</code> · dossier <code>' + esc(folderName) + '</code> · ' +
                '<b>' + linkCount + '</b> lien(s)';
        } catch (e) {
            body.innerHTML = '<div class="mvpt-modal-loading">❌ Erreur : ' + esc(e.message) + '</div>';
            footer.textContent = 'doc #' + docId + ' · échec récupération';
        }
    };

    window.mvptModalClose = function() {
        document.getElementById('mvptModalBackdrop').classList.remove('open');
        document.getElementById('mvptModalBody').innerHTML = '';
    };
    document.addEventListener('keydown', e => { if (e.key === 'Escape') mvptModalClose(); });
})();
</script>
<?php /* Modal générique « archiver / supprimer » (idempotent via GED_DELETE_MODAL_INCLUDED). */ require_once __DIR__ . '/ged_delete_modal.php'; ?>
