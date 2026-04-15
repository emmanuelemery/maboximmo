<?php
// agency_analyse_doc.php — Analyse IA d'un document + validation + envoi propriétaire
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$doc_id  = (int)($_GET['doc_id'] ?? $_POST['doc_id'] ?? 0);

if (!$doc_id) { header('Location: agency_registres.php'); exit; }

// ── Charger le document ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT d.*,
           m.mandant_nom, m.mandant_representant, m.id_immeuble,
           m.immeuble_txt, m.id_etablissement, m.numero_registre,
           mn.email AS mandant_email,
           i.nom AS imm_nom,
           e.nom AS etab_nom
    FROM agency_mandat_document d
    JOIN agency_mandat m ON m.id = d.id_mandat
    LEFT JOIN agency_mandant mn ON mn.id = m.id_mandant
    LEFT JOIN immeubles i ON i.id = m.id_immeuble
    LEFT JOIN etablissements e ON e.id = m.id_etablissement
    WHERE d.id = ?
");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) { header('Location: agency_registres.php'); exit; }

// Seuls pv_ag, convocation_ag et budget_previsionnel sont analysables
$analysable_types = ['pv_ag', 'convocation_ag', 'budget_previsionnel', 'releve_charges'];
if (!in_array($doc['type_doc'], $analysable_types)) {
    header('Location: agency_registre_fiche.php?id=' . $doc['id_mandat'] . '&err=type_non_analysable');
    exit;
}

$mandat_id = (int)$doc['id_mandat'];
$filepath  = __DIR__ . '/' . ltrim($doc['chemin'], '/');
$immeuble  = $doc['imm_nom'] ?? $doc['immeuble_txt'] ?? '';

require_once __DIR__ . '/inc/ia_analyse.php';
require_once __DIR__ . '/inc/mailer_analyse_doc.php';

// ── Phase 1 : analyse (AJAX POST ajax_analyse) ────────────────────────────────
if (isset($_POST['ajax_analyse'])) {
    header('Content-Type: application/json');
    $text   = extractPdfText($filepath);
    $result = analyseDocumentIA($text, $doc['type_doc'], $immeuble);
    echo json_encode($result);
    exit;
}

// ── Phase 2 : envoi mail (POST send_mail) ─────────────────────────────────────
if (isset($_POST['send_mail'])) {
    $summary_json = $_POST['summary_json'] ?? '{}';
    $summary      = json_decode($summary_json, true) ?: [];
    $dest_email   = trim($_POST['dest_email']  ?? '');
    $dest_name    = trim($_POST['dest_name']   ?? '');
    $msg_perso    = trim($_POST['msg_perso']   ?? '');

    // Appliquer les modifications manuelles du gestionnaire
    $summary['resume_general']        = trim($_POST['edit_resume']   ?? $summary['resume_general'] ?? '');
    $summary['message_proprietaire']  = trim($_POST['edit_message']  ?? $summary['message_proprietaire'] ?? '');

    // Construire $mandat pour le mailer
    $mandat_row = [
        'imm_nom'      => $doc['imm_nom'] ?? '',
        'immeuble_txt' => $doc['immeuble_txt'] ?? '',
        'etab_nom'     => $doc['etab_nom'] ?? '',
    ];

    $result = sendAnalyseMail($summary, $doc, $mandat_row, $dest_email, $dest_name, $msg_perso);

    // Journaliser l'envoi dans agency_mandat_document (champ commentaire si dispo)
    // Sinon on log simplement
    if ($result['ok']) {
        error_log('[analyse_doc] Résumé envoyé à ' . $dest_email . ' pour doc_id=' . $doc_id);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ── Types labels ──────────────────────────────────────────────────────────────
$typeLabels = [
    'pv_ag'              => "Procès-verbal d'AG",
    'convocation_ag'     => "Convocation AG",
    'budget_previsionnel'=> "Budget prévisionnel",
    'releve_charges'     => "Relevé de charges",
];
$typeLabel = $typeLabels[$doc['type_doc']] ?? $doc['type_doc'];

$dest_email_default = $doc['mandant_email'] ?? '';
$dest_name_default  = $doc['mandant_nom'] ?? '';

$layout_title   = 'Analyse IA — '.$typeLabel;
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val" style="font-size:10px">'.htmlspecialchars($typeLabel).'</div><div class="ph-kpi-lbl">Type doc</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.number_format($doc['taille']/1024,1).' Ko</div><div class="ph-kpi-lbl">Taille</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="font-size:10px">Claude AI</div><div class="ph-kpi-lbl">Moteur</div></div>
';

$layout_head_actions = '
<a href="agency_registre_fiche.php?id='.$mandat_id.'#documents" class="ph-btn">
    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Retour
</a>
<a href="'.htmlspecialchars($doc['chemin']).'" target="_blank" class="ph-btn">
    <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> PDF
</a>
<span class="ph-btn dispo">dispo</span>
<span class="ph-btn dispo">dispo</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.main-grid{display:grid;grid-template-columns:1fr 300px;gap:22px;align-items:start}
.main-col{display:flex;flex-direction:column;gap:18px}
.side-col{display:flex;flex-direction:column;gap:14px;position:sticky;top:10px}
.page-sub{font-size:11px;color:#8a8680;margin-top:2px;font-family:'DM Mono',monospace}

.ana-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:22px 24px}
.card-title{font-size:11px;font-weight:700;color:#8a5040;text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #e4e6ec;display:flex;align-items:center;gap:8px}

.phase{display:none}
.phase.active{display:flex;flex-direction:column;gap:18px}

.analyse-box{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:40px 28px;text-align:center}
.spinner{width:52px;height:52px;border:4px solid #e4e6ec;border-top-color:#3a7a6a;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px}
@keyframes spin{to{transform:rotate(360deg)}}
.analyse-title{font-size:18px;font-weight:700;color:#1a1816;margin-bottom:8px}
.analyse-sub{font-size:13px;color:#8a8680;margin-bottom:20px}
.analyse-steps{display:flex;flex-direction:column;gap:8px;max-width:360px;margin:0 auto}
.analyse-step{display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:10px;background:#e0dbd4;font-size:12px;color:#6a6864}
.analyse-step .dot{width:8px;height:8px;border-radius:50%;background:#e4e6ec;flex-shrink:0;transition:background .4s}
.analyse-step.done .dot{background:#3a7a6a}
.analyse-step.active .dot{background:#3a7a6a;animation:pulse 1s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.analyse-step.active{color:#3a7a6a;font-weight:600}

.btn-analyse{padding:12px 32px;border-radius:999px;background:linear-gradient(135deg,#4a8a7a,#3a7a6a);color:#fff;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;border:none;cursor:pointer;box-shadow:3px 6px 16px rgba(58,122,106,.35);transition:box-shadow .15s;margin-top:16px}
.btn-analyse:hover{box-shadow:3px 8px 22px rgba(58,122,106,.45)}
.btn-analyse:disabled{opacity:.6;cursor:not-allowed}

.ia-badge{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#4a8a7a22,#3a7a6a18);border:1px solid #3a7a6a40;border-radius:999px;padding:3px 10px;font-family:'DM Mono',monospace;font-size:10px;font-weight:700;color:#3a7a6a;text-transform:uppercase;letter-spacing:.08em}
.ia-badge::before{content:'';width:6px;height:6px;border-radius:50%;background:#3a7a6a}

.resol-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
.resol-table th{padding:10px 14px;background:var(--bg-secondary,var(--bg-secondary,#eef1f6));font-family:'DM Mono',monospace;font-size:9px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.14em;text-align:left}
.resol-table td{padding:9px 12px;border-bottom:1px solid #e4e6ec;vertical-align:top}
.resol-badge{display:inline-block;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700}
.adoptee{background:#e8f5ee;color:#3a7a6a}
.rejetee{background:#fdecea;color:#8a5040}
.reportee{background:#fff3e0;color:#7a6830}
.info-r{background:#e8f0f8;color:#3a7a6a}

.montants-table{width:100%;border-collapse:collapse;font-size:13px}
.montants-table td{padding:7px 12px;border-bottom:1px solid #e4e6ec}
.montants-table td:last-child{text-align:right;font-weight:700;color:#3a7a6a}

.points-list{list-style:none;padding:0;display:flex;flex-direction:column;gap:6px}
.points-list li{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#4a4844}
.points-list li::before{content:'';width:6px;height:6px;border-radius:50%;background:#3a7a6a;flex-shrink:0;margin-top:5px}

textarea.edit-area{border:none;border-radius:10px;padding:10px 14px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:13px;color:#1a1816;outline:none;width:100%;min-height:90px;resize:vertical}
textarea.edit-area:focus{box-shadow:inset 3px 3px 7px #b8b4ae,inset -3px -3px 6px #ffffff,0 0 0 2px rgba(58,122,106,.18)}

.send-card input,.send-card select,.send-card textarea{border:none;border-radius:8px;padding:8px 12px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#1a1816;outline:none;width:100%;margin-bottom:8px}
.send-card label{font-size:11px;font-weight:600;color:#7a6830;display:block;margin-bottom:4px}
.send-card textarea{min-height:70px;resize:vertical;margin-bottom:0}
.btn-send{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:11px;border-radius:999px;background:linear-gradient(135deg,#4a8a7a,#3a7a6a);color:#fff;font-family:'Sora',sans-serif;font-size:13px;font-weight:700;border:none;cursor:pointer;box-shadow:3px 4px 14px rgba(58,122,106,.35);transition:box-shadow .15s;margin-top:12px}
.btn-send:hover{box-shadow:3px 6px 18px rgba(58,122,106,.45)}
.btn-send:disabled{opacity:.6;cursor:not-allowed}

.alert-ok{background:#e8f5ee;border-radius:10px;padding:10px 14px;font-size:12px;color:#3a7a6a;margin-top:10px;display:none}
.alert-err{background:#fdecea;border-radius:10px;padding:10px 14px;font-size:12px;color:#8a5040;margin-top:10px;display:none}

.doc-info-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.doc-info-icon{width:36px;height:36px;border-radius:10px;background:#e0dbd4;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.doc-info-name{font-size:13px;font-weight:600;color:#1a1816;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.doc-info-meta{font-size:11px;color:#8a8680}

.action-btn{display:flex;align-items:center;gap:8px;width:100%;padding:10px 14px;border-radius:10px;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#4a4844;text-decoration:none;transition:box-shadow .15s;margin-bottom:6px}
.action-btn:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff)}

.error-box{background:#fdecea;border-radius:14px;padding:28px;text-align:center}
.error-box svg{margin-bottom:12px;opacity:.6}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const DOC_ID = DOC_ID_PLACEHOLDER;
let summaryData = null;

// ── Phase 1 : lancer l'analyse ────────────────────────────────────────────────
async function startAnalyse() {
    document.getElementById('btn_start').style.display    = 'none';
    document.getElementById('icon_start').style.display  = 'none';
    document.getElementById('spinner_wrap').style.display = 'block';
    document.getElementById('steps_wrap').style.display  = 'flex';
    document.getElementById('error_analyse').style.display = 'none';

    setStep(1, 'active');

    try {
        const fd = new FormData();
        fd.append('ajax_analyse', '1');

        setTimeout(() => setStep(2, 'active'), 800);
        setTimeout(() => setStep(3, 'active'), 3000);

        const res  = await fetch(location.href, {method:'POST', body:fd});
        const data = await res.json();

        if (!data.ok) {
            showError(data.error || 'Erreur inconnue.');
            return;
        }

        setStep(1, 'done'); setStep(2, 'done'); setStep(3, 'done');
        await new Promise(r => setTimeout(r, 400));

        fillSummary(data.summary);
        showPhase(2);

    } catch(e) {
        showError('Erreur réseau : ' + e.message);
    }
}

function setStep(n, state) {
    const el = document.getElementById('as'+n);
    if (!el) return;
    el.classList.remove('active','done');
    if (state) el.classList.add(state);
}

function showError(msg) {
    document.getElementById('spinner_wrap').style.display = 'none';
    document.getElementById('steps_wrap').style.display  = 'none';
    document.getElementById('btn_start').style.display   = 'inline-flex';
    document.getElementById('icon_start').style.display  = 'block';
    document.getElementById('error_msg').textContent     = msg;
    document.getElementById('error_analyse').style.display = 'block';
}

function showPhase(n) {
    document.querySelectorAll('.phase').forEach(p => p.classList.remove('active'));
    document.getElementById('phase'+n).classList.add('active');
}

function resetAnalyse() { showPhase(1); }

// ── Phase 2 : remplir le résumé ───────────────────────────────────────────────
function fillSummary(s) {
    summaryData = s;

    document.getElementById('ia_resume_display').textContent  = s.resume_general || '';
    document.getElementById('edit_resume').value               = s.resume_general || '';
    document.getElementById('ia_message_display').textContent = s.message_proprietaire || '';
    document.getElementById('edit_message').value              = s.message_proprietaire || '';

    if (s.date_document)
        document.getElementById('ia_date_doc').textContent = s.date_document;

    // Points clés
    const pts = s.points_cles || [];
    if (pts.length) {
        const ul = document.getElementById('ia_points');
        ul.innerHTML = pts.map(p => `<li><span class="dot" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#3a7a6a;flex-shrink:0;margin-top:5px"></span>${esc(p)}</li>`).join('');
        document.getElementById('card_points').style.display = 'block';
    }

    // Résolutions
    const resols = s.resolutions || [];
    if (resols.length) {
        const badgeClass = {ADOPTEE:'adoptee',REJETEE:'rejetee',REPORTEE:'reportee'};
        const badgeLabel = {ADOPTEE:'Adoptée',REJETEE:'Rejetée',REPORTEE:'Reportée',INFO:'Information'};
        document.getElementById('ia_resol').innerHTML = resols.map(r =>
            `<tr>
              <td style="color:#8a8680;font-size:12px">${esc(r.numero||'')}</td>
              <td>${esc(r.objet||'')}</td>
              <td style="text-align:center">
                <span class="resol-badge ${badgeClass[r.resultat]||'info-r'}">${badgeLabel[r.resultat]||r.resultat}</span>
                ${r.votes ? `<div style="font-size:10px;color:#8a8680;margin-top:2px">${esc(r.votes)}</div>` : ''}
              </td>
            </tr>`
        ).join('');
        document.getElementById('card_resol').style.display = 'block';
    }

    // Montants
    const mts = s.montants || [];
    if (mts.length) {
        document.getElementById('ia_montants').innerHTML = mts.map(m =>
            `<tr><td>${esc(m.libelle)}</td><td>${esc(m.montant)}</td></tr>`
        ).join('');
        document.getElementById('card_montants').style.display = 'block';
    }

    // Échéances
    const ech = s.prochaines_echeances || [];
    if (ech.length) {
        document.getElementById('ia_echeances').innerHTML = ech.map(e =>
            `<li><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#3a7a6a"></span>${esc(e)}</li>`
        ).join('');
        document.getElementById('card_echeances').style.display = 'block';
    }
}

// ── Envoi mail ────────────────────────────────────────────────────────────────
async function sendMail() {
    const email = document.getElementById('dest_email').value.trim();
    if (!email) { alert('Veuillez saisir une adresse e-mail.'); return; }

    // Intégrer les modifications manuelles dans summaryData
    summaryData.resume_general       = document.getElementById('edit_resume').value;
    summaryData.message_proprietaire = document.getElementById('edit_message').value;

    const btn = document.getElementById('btn_send');
    btn.disabled = true;
    btn.textContent = 'Envoi en cours…';

    const fd = new FormData();
    fd.append('send_mail',    '1');
    fd.append('doc_id',       DOC_ID);
    fd.append('summary_json', JSON.stringify(summaryData));
    fd.append('dest_email',   email);
    fd.append('dest_name',    document.getElementById('dest_name').value);
    fd.append('msg_perso',    document.getElementById('msg_perso').value);
    fd.append('edit_resume',  summaryData.resume_general);
    fd.append('edit_message', summaryData.message_proprietaire);

    try {
        const res  = await fetch(location.href, {method:'POST', body:fd});
        const data = await res.json();

        if (data.ok) {
            document.getElementById('send_ok').style.display  = 'block';
            document.getElementById('send_err').style.display = 'none';
            btn.textContent = '✓ Envoyé';
        } else {
            document.getElementById('send_err').textContent   = data.msg;
            document.getElementById('send_err').style.display = 'block';
            btn.disabled    = false;
            btn.textContent = 'Valider et envoyer';
        }
    } catch(e) {
        document.getElementById('send_err').textContent   = 'Erreur réseau : ' + e.message;
        document.getElementById('send_err').style.display = 'block';
        btn.disabled    = false;
        btn.textContent = 'Valider et envoyer';
    }
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
EXTRAJS;
$layout_extra_js = str_replace('DOC_ID_PLACEHOLDER', (string)$doc_id, $layout_extra_js);

ob_start();
?>

<div style="margin-bottom:10px">
  <div style="display:flex;align-items:center;gap:10px">
    <span class="ia-badge">Claude AI</span>
    <span class="page-sub"><?= htmlspecialchars($typeLabel) ?> — <?= htmlspecialchars($doc['nom_fichier']) ?></span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PHASE 1 : Lancement analyse
═══════════════════════════════════════════════════════ -->
<div class="phase active" id="phase1">
  <div class="analyse-box">
    <div id="spinner_wrap" style="display:none">
      <div class="spinner"></div>
    </div>
    <svg id="icon_start" width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#3a7a6a" stroke-width="1.5" stroke-linecap="round" style="margin-bottom:16px"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
    <div class="analyse-title">Analyser ce document</div>
    <div class="analyse-sub">
      MaBoxImmo va extraire le texte du document,<br>puis l'analyser avec l'intelligence artificielle Claude.
    </div>

    <!-- Infos document -->
    <div style="background:#e0dbd4;border-radius:10px;padding:12px 16px;max-width:380px;margin:0 auto 20px;text-align:left">
      <div style="font-size:10px;font-weight:700;color:#7a6830;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Document à analyser</div>
      <div class="doc-info-row">
        <div class="doc-info-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8a5040" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
          <div class="doc-info-name"><?= htmlspecialchars($doc['nom_fichier']) ?></div>
          <div class="doc-info-meta"><?= htmlspecialchars($typeLabel) ?> · <?= number_format($doc['taille']/1024, 1) ?> Ko</div>
        </div>
      </div>
      <div style="font-size:11px;color:#6a6864;margin-top:4px">
        <strong>Immeuble :</strong> <?= htmlspecialchars($immeuble ?: '—') ?><br>
        <strong>Mandant :</strong> <?= htmlspecialchars($doc['mandant_nom']) ?>
      </div>
    </div>

    <!-- Étapes de progression -->
    <div class="analyse-steps" id="steps_wrap" style="display:none">
      <div class="analyse-step" id="as1"><span class="dot"></span>Extraction du texte PDF…</div>
      <div class="analyse-step" id="as2"><span class="dot"></span>Envoi à l'IA Claude…</div>
      <div class="analyse-step" id="as3"><span class="dot"></span>Structuration du résumé…</div>
    </div>

    <div id="error_analyse" class="error-box" style="display:none;margin-top:16px">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#8a5040" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div id="error_msg" style="font-size:13px;color:#8a5040;margin-top:8px"></div>
      <button class="btn-analyse" onclick="location.reload()" style="margin-top:16px">Réessayer</button>
    </div>

    <button class="btn-analyse" id="btn_start" onclick="startAnalyse()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:6px;vertical-align:middle"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
      Lancer l'analyse IA
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PHASE 2 : Résumé IA + envoi
═══════════════════════════════════════════════════════ -->
<div class="phase" id="phase2">
<div class="main-grid">

  <!-- Colonne principale -->
  <div class="main-col">

    <!-- Résumé général -->
    <div class="ana-card">
      <div class="card-title">
        <span class="ia-badge">IA</span>
        Résumé général
      </div>
      <div id="ia_resume_display" style="font-size:14px;color:#1a1816;line-height:1.7;margin-bottom:14px"></div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#7a6830;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Modifier le résumé</div>
        <textarea class="edit-area" id="edit_resume" rows="4"></textarea>
      </div>
    </div>

    <!-- Message propriétaire -->
    <div class="ana-card">
      <div class="card-title">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Message au propriétaire
        <span style="font-size:10px;color:#8a8680;font-weight:400;margin-left:auto">Inclus dans l'e-mail</span>
      </div>
      <div id="ia_message_display" style="font-size:13px;color:#1a1816;line-height:1.7;padding:12px;background:#eef6f2;border-radius:10px;border-left:4px solid #3a7a6a;margin-bottom:12px"></div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#7a6830;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Personnaliser le message</div>
        <textarea class="edit-area" id="edit_message" rows="4"></textarea>
      </div>
    </div>

    <!-- Points clés -->
    <div class="ana-card" id="card_points" style="display:none">
      <div class="card-title">Points clés</div>
      <ul class="points-list" id="ia_points"></ul>
    </div>

    <!-- Résolutions -->
    <div class="ana-card" id="card_resol" style="display:none">
      <div class="card-title">Résolutions votées</div>
      <table class="resol-table">
        <thead><tr><th>N°</th><th>Objet</th><th style="text-align:center">Résultat</th></tr></thead>
        <tbody id="ia_resol"></tbody>
      </table>
    </div>

    <!-- Montants -->
    <div class="ana-card" id="card_montants" style="display:none">
      <div class="card-title">Montants à retenir</div>
      <table class="montants-table"><tbody id="ia_montants"></tbody></table>
    </div>

    <!-- Échéances -->
    <div class="ana-card" id="card_echeances" style="display:none">
      <div class="card-title">Prochaines échéances</div>
      <ul class="points-list" id="ia_echeances"></ul>
    </div>

  </div><!-- /main-col -->

  <!-- Colonne latérale -->
  <div class="side-col">

    <!-- Info document -->
    <div class="ana-card">
      <div class="card-title">Document analysé</div>
      <div class="doc-info-row">
        <div class="doc-info-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8a5040" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
          <div class="doc-info-name"><?= htmlspecialchars($doc['nom_fichier']) ?></div>
          <div class="doc-info-meta" id="ia_date_doc"></div>
        </div>
      </div>
      <a href="<?= htmlspecialchars($doc['chemin']) ?>" target="_blank" class="action-btn" style="margin-top:8px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Voir le document original
      </a>
    </div>

    <!-- Formulaire envoi -->
    <div class="ana-card send-card">
      <div class="card-title">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Envoyer au propriétaire
      </div>
      <label>E-mail destinataire *</label>
      <input type="email" id="dest_email" value="<?= htmlspecialchars($dest_email_default) ?>" placeholder="email@exemple.fr" multiple>
      <label>Nom du destinataire</label>
      <input type="text" id="dest_name" value="<?= htmlspecialchars($dest_name_default) ?>" placeholder="Nom, Prénom">
      <label>Message du gestionnaire <span style="font-size:10px;color:#b0aaa4">(optionnel)</span></label>
      <textarea id="msg_perso" placeholder="Ajoutez un message personnalisé qui apparaîtra dans l'e-mail…"></textarea>
      <button class="btn-send" id="btn_send" onclick="sendMail()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Valider et envoyer
      </button>
      <div class="alert-ok" id="send_ok">✓ E-mail envoyé avec succès !</div>
      <div class="alert-err" id="send_err"></div>
    </div>

    <!-- Re-analyser -->
    <button class="action-btn" onclick="resetAnalyse()" style="color:#8a8680">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
      Relancer l'analyse
    </button>

  </div><!-- /side-col -->
</div><!-- /main-grid -->
</div><!-- /phase2 -->

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
