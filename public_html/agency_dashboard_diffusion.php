<?php
/**
 * agency_dashboard_diffusion.php
 * ────────────────────────────────
 * Dashboard centralisé de la diffusion Ubiflow :
 *   - KPIs synthétiques par agence (dernier dépôt, statut, nb annonces)
 *   - Bouton "Envoyer maintenant" par agence (déclenche --deploy en live)
 *   - Bouton global "Envoyer tous les flux"
 *   - Historique versionné (table ubiflow_deploy_log) des 30 derniers jours
 *   - Détection automatique des retards (pas de dépôt ok dans les 24h)
 *
 * Accessible à tous les users authentifiés du module Agency.
 * Les déclenchements d'envoi sont tracés via `triggered_user` dans le log.
 */
$current_page = 'diffusion';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

require_once __DIR__ . '/config/ubiflow_agences.php';

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();

$societeId = (int)($_SESSION['id_societe'] ?? 0);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ─── 1. Liste des agences actives (config) ────────────────────────────
$agences = ubiflow_agences_actives();

// Filtrer par société pour les non-admins
if (!in_array($roleId, [1, 7], true) && $societeId > 0) {
    $agences = array_filter($agences, fn($a) => (int)($a['id_societe'] ?? 0) === $societeId);
}

// ─── 2. Pour chaque agence, récupère le dernier dépôt `ok` + le dernier tout-statut ─
$stmtLast = $pdo->prepare("
    SELECT status, started_at, annonces_count, duration_ms, zip_size, error_msg, triggered_by
    FROM ubiflow_deploy_log
    WHERE slug_agence = :slug
    ORDER BY started_at DESC
    LIMIT 1
");
$stmtLastOk = $pdo->prepare("
    SELECT started_at, annonces_count
    FROM ubiflow_deploy_log
    WHERE slug_agence = :slug AND status = 'ok'
    ORDER BY started_at DESC
    LIMIT 1
");
$stmtCount30 = $pdo->prepare("
    SELECT COUNT(*) FROM ubiflow_deploy_log
    WHERE slug_agence = :slug AND status = 'ok'
      AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");

// Compteur biens diffusés par agence (count distinct des id_bien avec annonce diffusée)
$stmtBiensDiff = $pdo->prepare("
    SELECT COUNT(DISTINCT a.id_bien)
    FROM annonces a
    WHERE a.id_agence = :id_agence
      AND a.etat_publication = 'diffusee'
");

// Compteur annonces "à diffuser" par agence :
//   - visible_portails = 1 (agent veut diffuser)
//   - etat_publication != 'diffusee' (pas encore envoyé)
$stmtADiffuser = $pdo->prepare("
    SELECT COUNT(*)
    FROM annonces a
    WHERE a.id_agence = :id_agence
      AND COALESCE(a.visible_portails, 0) = 1
      AND (a.etat_publication IS NULL OR a.etat_publication != 'diffusee')
");

foreach ($agences as $slug => &$ag) {
    $stmtLast->execute([':slug' => $slug]);
    $ag['_last']    = $stmtLast->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmtLastOk->execute([':slug' => $slug]);
    $ag['_last_ok'] = $stmtLastOk->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmtCount30->execute([':slug' => $slug]);
    $ag['_count_30'] = (int)$stmtCount30->fetchColumn();
    // Nb biens diffusés pour cette agence
    $stmtBiensDiff->execute([':id_agence' => (int)($ag['id_agence'] ?? 0)]);
    $ag['_nb_biens_diffuses'] = (int)$stmtBiensDiff->fetchColumn();
    // Nb annonces à diffuser (souhaitées portails mais pas encore envoyées)
    $stmtADiffuser->execute([':id_agence' => (int)($ag['id_agence'] ?? 0)]);
    $ag['_nb_a_diffuser'] = (int)$stmtADiffuser->fetchColumn();
}
unset($ag);

$totalADiffuser = array_sum(array_map(fn($a) => (int)($a['_nb_a_diffuser'] ?? 0), $agences));

// ─── Total biens diffusés (toutes agences visibles, dédoublonné par bien) ──
$totalBiensDiffuses = 0;
$idAgences = array_filter(array_map(fn($a) => (int)($a['id_agence'] ?? 0), $agences), fn($v) => $v > 0);
if (!empty($idAgences)) {
    $ph = implode(',', array_fill(0, count($idAgences), '?'));
    $st = $pdo->prepare("
        SELECT COUNT(DISTINCT a.id_bien)
        FROM annonces a
        WHERE a.id_agence IN ($ph)
          AND a.etat_publication = 'diffusee'
    ");
    $st->execute(array_values($idAgences));
    $totalBiensDiffuses = (int)$st->fetchColumn();
}

// ─── 3. KPIs globaux ──────────────────────────────────────────────────
$stmtKpi = $pdo->query("
    SELECT
        COUNT(*) AS total_30d,
        SUM(status = 'ok')                AS ok_30d,
        SUM(status = 'ftp_error')         AS ftp_err_30d,
        SUM(status = 'build_error')       AS build_err_30d,
        SUM(status = 'skipped_duplicate') AS dup_30d,
        SUM(status = 'no_creds')          AS nocred_30d,
        AVG(CASE WHEN status = 'ok' THEN duration_ms END) AS avg_ms,
        MAX(started_at) AS last_run
    FROM ubiflow_deploy_log
    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOk     = (int)($kpi['ok_30d']        ?? 0);
$totalFtpErr = (int)($kpi['ftp_err_30d']   ?? 0);
$totalBuildErr = (int)($kpi['build_err_30d'] ?? 0);
$totalDup    = (int)($kpi['dup_30d']       ?? 0);
$totalNoCred = (int)($kpi['nocred_30d']    ?? 0);
$totalAll    = (int)($kpi['total_30d']     ?? 0);
$avgMs       = (int)round((float)($kpi['avg_ms'] ?? 0));

// ─── 4. Historique 30 derniers jours (50 dernières entrées) ───────────
$stmtHistory = $pdo->query("
    SELECT l.*, u.prenom AS u_prenom, u.nom AS u_nom
    FROM ubiflow_deploy_log l
    LEFT JOIN users u ON u.id = l.triggered_user
    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY started_at DESC
    LIMIT 50
");
$history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ─── 5. Calcul "en retard" : aucun ok dans les 24h ────────────────────
$agencesEnRetard = [];
foreach ($agences as $slug => $ag) {
    if (empty($ag['_last_ok'])) {
        $agencesEnRetard[] = $slug;
        continue;
    }
    $ts = strtotime($ag['_last_ok']['started_at'] ?? '');
    if ($ts && (time() - $ts) > 86400) {
        $agencesEnRetard[] = $slug;
    }
}

// ─── Layout vars ──────────────────────────────────────────────────────
$layout_title    = 'Diffusion Ubiflow';
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#166534">' . $totalOk . '</div><div class="ph-kpi-lbl">✓ Dépôts 30j</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">' . count($agences) . '</div><div class="ph-kpi-lbl">Agences actives</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:' . (count($agencesEnRetard) > 0 ? '#a85858' : '#8a8680') . '">' . count($agencesEnRetard) . '</div><div class="ph-kpi-lbl">En retard</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#92400e">' . ($totalFtpErr + $totalBuildErr) . '</div><div class="ph-kpi-lbl">Erreurs 30j</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a8680">' . $totalDup . '</div><div class="ph-kpi-lbl">Doublons ignorés</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#36577d">' . ($avgMs > 0 ? $avgMs . 'ms' : '—') . '</div><div class="ph-kpi-lbl">Durée moy.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#0ea5e9">' . $totalBiensDiffuses . '</div><div class="ph-kpi-lbl">📡 Biens diffusés</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:' . ($totalADiffuser > 0 ? '#c2410c' : '#8a8680') . '">' . $totalADiffuser . '</div><div class="ph-kpi-lbl">⏳ À diffuser</div></div>
';

$layout_head_actions = '
    <button type="button" class="ph-btn primary" onclick="ubiflowDeployAll()">
        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        Envoyer les 5 flux
    </button>
';

$layout_extra_css = <<<'CSS'
<style>
.diff-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.diff-card {
    background: var(--bg-primary);
    border-radius: 16px;
    box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
    padding: 18px 20px;
    display: flex; flex-direction: column; gap: 12px;
    transition: box-shadow .2s;
}
.diff-card:hover { box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light); }
.diff-card-head {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.diff-card-title {
    font-family: 'Sora', sans-serif;
    font-size: 14px; font-weight: 700; color: var(--ink);
}
.diff-card-sub {
    font-family: 'DM Mono', monospace;
    font-size: 9px;
    color: var(--muted);
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.diff-status {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 99px;
    font-family: 'DM Mono', monospace;
    font-size: 9px; font-weight: 700; letter-spacing: 0.06em;
    white-space: nowrap;
}
.diff-status.ok       { background:#dcfce7; color:#166534; }
.diff-status.warn     { background:#fef3c7; color:#92400e; }
.diff-status.err      { background:#fee2e2; color:#991b1b; }
.diff-status.dup      { background:#e0e7ff; color:#3730a3; }
.diff-status.nocred   { background:#f0ebe3; color:#8a8680; }
.diff-status.none     { background:#f0ebe3; color:#8a8680; }

.diff-meta {
    display: grid; grid-template-columns: auto 1fr; gap: 4px 10px;
    font-size: 11px; color: var(--muted); line-height: 1.5;
}
.diff-meta strong { color: var(--ink); font-weight: 600; }

.diff-actions {
    display: flex; gap: 8px; flex-wrap: wrap;
    padding-top: 10px; border-top: 1px solid var(--stroke);
}
.diff-btn {
    flex: 1; min-width: 100px;
    padding: 8px 12px; border-radius: 10px; border: none;
    font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 700;
    cursor: pointer; transition: box-shadow .15s;
    background: linear-gradient(135deg, var(--btn-save-from, #f97316), var(--btn-save-to, #e67e22));
    color: #fff;
    box-shadow: 3px 3px 8px rgba(249,115,22,0.3);
}
.diff-btn:hover { box-shadow: 4px 4px 12px rgba(249,115,22,0.45); }
.diff-btn:disabled { opacity: 0.6; cursor: wait; }
.diff-btn.ghost {
    background: var(--bg-primary); color: var(--muted);
    box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
}

.diff-card.retard {
    border-left: 3px solid #a85858;
}
.diff-card.retard .diff-card-title::before {
    content: '⚠ '; color: #a85858;
}

/* Timeline historique */
.diff-history {
    background: var(--bg-primary);
    border-radius: 16px;
    box-shadow: inset 3px 3px 8px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
    padding: 18px 20px;
}
.diff-history h3 {
    font-family: 'Sora', sans-serif;
    font-size: 14px; font-weight: 700;
    color: var(--ink);
    margin: 0 0 14px;
    display: flex; align-items: center; gap: 8px;
}
.diff-history-row {
    display: grid;
    grid-template-columns: 130px 120px 80px 60px 1fr 90px;
    gap: 12px; padding: 8px 0;
    border-bottom: 1px solid rgba(196,192,186,0.25);
    font-size: 11px; align-items: center;
}
.diff-history-row:last-child { border-bottom: none; }
.diff-history-row .col-date { font-family:'DM Mono',monospace; color:var(--muted); font-size:10px; }
.diff-history-row .col-slug { font-weight:700; color:var(--ink); }
.diff-history-row .col-count { font-family:'DM Mono',monospace; color:var(--muted); text-align:right; font-size:10px; }
.diff-history-row .col-dur   { font-family:'DM Mono',monospace; color:var(--muted); text-align:right; font-size:10px; }
.diff-history-row .col-user  { font-family:'DM Mono',monospace; color:var(--muted); font-size:10px; }
.diff-history-row .col-err   { font-size:10px; color:var(--muted); font-style:italic; }

.diff-history-header {
    font-family: 'DM Mono', monospace;
    font-size: 9px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.08em;
    border-bottom: 2px solid rgba(196,192,186,0.4);
    padding-bottom: 6px; margin-bottom: 4px;
}

/* Toast result */
.diff-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 10000;
    padding: 14px 18px; border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    font-family: 'Sora', sans-serif; font-size: 13px;
    max-width: 420px; line-height: 1.4;
    transition: opacity .3s;
}
.diff-toast.ok { background:#edf5e7; color:#2a4020; border-left:4px solid #7a9060; }
.diff-toast.err { background:#fef2f2; color:#7a2020; border-left:4px solid #a85858; }
.diff-toast.loading { background:#f5f1ea; color:#8a8680; border-left:4px solid #8a8680; }
</style>
CSS;

$layout_extra_js = <<<'JS'
<script>
const CSRF_UBI = document.querySelector('meta[name="csrf-token-ubiflow"]')?.content || '';

function ubiflowToast(msg, level) {
    const id = 'ubiflowToast';
    let el = document.getElementById(id);
    if (!el) {
        el = document.createElement('div');
        el.id = id; el.className = 'diff-toast';
        document.body.appendChild(el);
    }
    el.className = 'diff-toast ' + (level || 'loading');
    el.innerHTML = msg;
    el.style.opacity = '1';
    if (level !== 'loading') {
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 7000);
    }
}

async function ubiflowDeploy(slug, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Envoi…'; }
    ubiflowToast('⟳ Envoi du flux <strong>' + slug + '</strong>…', 'loading');
    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_UBI);
        fd.append('slug', slug);
        const r = await fetch('api/ubiflow_trigger.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok && !d.results) throw new Error(d.error || 'Erreur inconnue');

        const res = (d.results || [])[0] || {};
        let msg;
        if (res.status === 'ok') {
            msg = '<strong>✓ Flux ' + slug + ' déposé</strong><br>' + (res.annonces || 0) + ' annonce(s) · ' + (res.duration_ms || 0) + ' ms';
            ubiflowToast(msg, 'ok');
        } else if (res.status === 'skipped_duplicate') {
            msg = '<strong>⊘ ' + slug + ' — skip doublon</strong><br><span style="font-size:11px">' + (res.skipped_reason || 'Même MD5 déjà déposé') + '</span>';
            ubiflowToast(msg, 'ok');
        } else {
            msg = '<strong>❌ ' + slug + ' — ' + (res.status || 'erreur') + '</strong><br><span style="font-size:11px">' + (res.error || '') + '</span>';
            ubiflowToast(msg, 'err');
        }

        setTimeout(() => window.location.reload(), 2000);
    } catch (e) {
        ubiflowToast('❌ ' + (e.message || 'Erreur réseau'), 'err');
        if (btn) { btn.disabled = false; btn.innerHTML = '📤 Envoyer maintenant'; }
    }
}

async function ubiflowDeployAll() {
    if (!confirm('Déclencher l\'envoi des 5 flux Ubiflow maintenant ?\n\nChaque flux avec le même contenu (MD5 identique) qu\'un dépôt réussi des 24 dernières heures sera automatiquement ignoré.')) return;

    ubiflowToast('⟳ Envoi des 5 flux en cours…', 'loading');
    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_UBI);
        fd.append('slug', 'all');
        const r = await fetch('api/ubiflow_trigger.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.error) throw new Error(d.error);

        const res = d.results || [];
        const okCount = res.filter(r => r.status === 'ok').length;
        const dupCount = res.filter(r => r.status === 'skipped_duplicate').length;
        const errCount = res.filter(r => !['ok','skipped_duplicate'].includes(r.status)).length;

        const msg = '<strong>' + (errCount === 0 ? '✓' : '⚠') + ' Envoi terminé</strong><br>'
            + okCount + ' ok · ' + dupCount + ' doublon(s) · ' + errCount + ' erreur(s)';
        ubiflowToast(msg, errCount === 0 ? 'ok' : 'err');

        setTimeout(() => window.location.reload(), 2500);
    } catch (e) {
        ubiflowToast('❌ ' + (e.message || 'Erreur réseau'), 'err');
    }
}

// Force resend (ignore la garde MD5)
async function ubiflowForceResend(slug, btn) {
    if (!confirm('FORCER le ré-envoi du flux ' + slug + ' ?\n\nCeci ignore la garde anti-doublons. Utilisez uniquement si le dépôt précédent a échoué côté Ubiflow.')) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Force…'; }
    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_UBI);
        fd.append('slug', slug);
        fd.append('force', '1');
        const r = await fetch('api/ubiflow_trigger.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        const res = (d.results || [])[0] || {};
        if (res.status === 'ok') {
            ubiflowToast('<strong>✓ Force-resend ' + slug + '</strong><br>' + (res.annonces || 0) + ' annonce(s)', 'ok');
        } else {
            ubiflowToast('<strong>❌ ' + slug + '</strong><br>' + (res.error || res.status), 'err');
        }
        setTimeout(() => window.location.reload(), 2000);
    } catch (e) {
        ubiflowToast('❌ ' + (e.message || 'Erreur'), 'err');
        if (btn) { btn.disabled = false; btn.innerHTML = '↻ Force'; }
    }
}

// ── Déplier / replier le journal versionné ──
function toggleHistoryExtra() {
    const btn = document.getElementById('btn-history-toggle');
    const rows = document.querySelectorAll('[data-history-extra="1"]');
    const isHidden = rows.length > 0 && rows[0].style.display === 'none';
    rows.forEach(r => r.style.display = isHidden ? 'grid' : 'none');
    if (btn) btn.innerHTML = isHidden ? '▲ Replier' : '▼ Déplier (' + rows.length + ' autres)';
}

// ── Modal "Biens diffusés par agence" ──
// Stocke l'agence courante pour permettre un reload du modal après l'action "Remonter".
let __biensDiffCurrentAgence = { id: 0, nom: '' };

async function openBiensDiffModal(idAgence, nomAgence) {
    __biensDiffCurrentAgence = { id: idAgence, nom: nomAgence };
    const modal = document.getElementById('biens-diff-modal');
    const title = document.getElementById('biens-diff-modal-title');
    const body  = document.getElementById('biens-diff-modal-body');
    if (!modal) return;
    title.textContent = '📋 Biens diffusés — ' + nomAgence;
    body.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;">⏳ Chargement…</div>';
    modal.style.display = 'flex';
    try {
        const r = await fetch('api/agence_biens_diffuses.php?id_agence=' + encodeURIComponent(idAgence), { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur');
        if (!j.biens || j.biens.length === 0) {
            body.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;">Aucun bien actuellement diffusé pour cette agence.</div>';
            return;
        }
        const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
        const limit = j.limit || 15;
        const total = j.biens.length;
        const nbPublies = Math.min(total, limit);
        const nbAttente = Math.max(0, total - limit);

        // Bandeau récap en tête (vert si tout passe, orange si file d'attente)
        const recapBg = nbAttente === 0 ? '#ecfdf5' : '#fef3c7';
        const recapBd = nbAttente === 0 ? '#10b981' : '#f59e0b';
        const recapFg = nbAttente === 0 ? '#065f46' : '#78350f';
        let html = '<div style="margin-bottom:14px;padding:10px 14px;background:' + recapBg + ';border:1px solid ' + recapBd + ';border-radius:8px;color:' + recapFg + ';font-size:13px;">'
                 + '<strong>' + total + '</strong> annonce(s) active(s) · '
                 + '<strong style="color:#16a34a;">' + nbPublies + '</strong> publiée(s) sur LBC · '
                 + (nbAttente > 0
                    ? '<strong style="color:#dc2626;">' + nbAttente + '</strong> en file d\'attente — clique 🚀 pour remonter une annonce'
                    : 'Aucune en attente')
                 + '</div>';

        html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        html += '<thead><tr style="background:#f8fafc;text-align:left;">'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;width:40px;">Rang</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Statut LBC</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Réf</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Type</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Adresse</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Canaux</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Dern. modif</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Commercial</th>'
              + '<th style="padding:8px;border-bottom:1px solid #e5e7eb;">Action</th>'
              + '</tr></thead><tbody>';
        j.biens.forEach((b, idx) => {
            const rang = idx + 1;
            const isPublished = rang <= limit;
            const rowBg = isPublished ? '#fff' : '#fef2f2';
            const badgeHtml = isPublished
                ? '<span style="background:#dcfce7;color:#166534;font-weight:700;padding:3px 8px;border-radius:99px;font-size:11px;">🟢 Publié</span>'
                : '<span style="background:#fee2e2;color:#991b1b;font-weight:700;padding:3px 8px;border-radius:99px;font-size:11px;">🔴 En attente</span>';
            const canaux = [];
            if (b.visible_maboximmo) canaux.push('<span title="MaBoxImmo">🏢</span>');
            if (b.visible_site_perso) canaux.push('<span title="Site perso">🌐</span>');
            if (b.visible_portails) canaux.push('<span title="LeBonCoin via Ubiflow">📰</span>');
            // Action : bouton "Remonter" uniquement si en attente, sinon lien Ouvrir
            let actionHtml = '<a href="bien_detail.php?edit=' + encodeURIComponent(b.id_bien) + '&section=annonce" target="_blank" style="color:#0ea5e9;text-decoration:none;font-weight:700;font-size:11px;">🔗 Ouvrir</a>';
            if (!isPublished) {
                actionHtml = '<button type="button" onclick="ubiflowRemonter(' + b.id_annonce + ', this)" '
                           + 'style="background:#dc2626;color:#fff;border:none;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;" '
                           + 'title="Bump date_modification → l\'annonce remonte en tête au prochain export Ubiflow">'
                           + '🚀 Remonter</button>';
            }
            html += '<tr style="border-bottom:1px solid #f1f5f9;background:' + rowBg + ';">'
                  + '<td style="padding:8px;font-weight:700;color:#475569;">' + rang + '</td>'
                  + '<td style="padding:8px;">' + badgeHtml + '</td>'
                  + '<td style="padding:8px;font-family:monospace;">' + escapeHtml(b.reference_bien || '#'+b.id_bien) + '</td>'
                  + '<td style="padding:8px;">' + escapeHtml(b.type_bien || '—') + '</td>'
                  + '<td style="padding:8px;color:#475569;">' + escapeHtml((b.code_postal || '') + ' ' + (b.ville || '')) + '</td>'
                  + '<td style="padding:8px;font-size:14px;">' + (canaux.join(' ') || '—') + '</td>'
                  + '<td style="padding:8px;font-family:monospace;color:#475569;font-size:11px;">' + escapeHtml(b.date_modif_display || '—') + '</td>'
                  + '<td style="padding:8px;"><span title="' + escapeHtml(b.commercial_full || '') + '" style="background:#e0f2fe;color:#0369a1;font-weight:700;padding:3px 8px;border-radius:99px;font-family:monospace;font-size:11px;">' + escapeHtml(b.commercial_init || '—') + '</span></td>'
                  + '<td style="padding:8px;">' + actionHtml + '</td>'
                  + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    } catch (e) {
        body.innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;">❌ ' + e.message + '</div>';
    }
}

// "Remonter" une annonce : bump sa date_modification puis recharge le modal pour
// voir le nouveau classement (l'annonce remonte en rang 1).
async function ubiflowRemonter(idAnnonce, btn) {
    if (!idAnnonce) return;
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '⏳';
    try {
        const fd = new FormData();
        fd.append('id_annonce', String(idAnnonce));
        fd.append('csrf_token', CSRF_UBI);
        const r = await fetch('api/annonce_remonter.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Échec');
        // Reload du modal pour refléter le nouveau classement
        openBiensDiffModal(__biensDiffCurrentAgence.id, __biensDiffCurrentAgence.nom);
    } catch (e) {
        btn.disabled = false; btn.innerHTML = original;
        alert('Erreur : ' + e.message);
    }
}

function closeBiensDiffModal() {
    const m = document.getElementById('biens-diff-modal');
    if (m) m.style.display = 'none';
}
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeBiensDiffModal();
});
</script>
JS;

// ─── Contenu ──────────────────────────────────────────────────────────
ob_start();
?>

<meta name="csrf-token-ubiflow" content="<?= e(csrf_token('ubiflow_trigger')) ?>">

<!-- ══ BANDEAU D'ALERTE si retard ══ -->
<?php if (!empty($agencesEnRetard)): ?>
<div style="background:#fef2f2;border-left:4px solid #a85858;padding:14px 18px;border-radius:10px;margin-bottom:20px;">
    <strong style="color:#7a2020;">⚠ <?= count($agencesEnRetard) ?> agence(s) sans dépôt réussi dans les dernières 24h :</strong>
    <span style="color:#7a2020;font-family:'DM Mono',monospace;font-size:12px;">
        <?= e(implode(', ', $agencesEnRetard)) ?>
    </span>
</div>
<?php endif; ?>

<!-- ══ SECTION TITLE ══ -->
<div class="section-header" style="margin-bottom:16px;">
    <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Flux par agence</span>
        <div class="line-r"></div>
    </div>
</div>

<!-- ══ GRILLE DES 5 AGENCES ══ -->
<div class="diff-grid">
    <?php foreach ($agences as $slug => $ag):
        $last    = $ag['_last'];
        $lastOk  = $ag['_last_ok'];
        $count30 = $ag['_count_30'];
        $enRetard = in_array($slug, $agencesEnRetard, true);

        // Statut visuel principal
        $status = $last['status'] ?? 'none';
        $statusLabels = [
            'ok' => ['✓ OK', 'ok'],
            'skipped_duplicate' => ['⊘ Doublon skippé', 'dup'],
            'ftp_error' => ['❌ Erreur FTP', 'err'],
            'build_error' => ['❌ Erreur XML', 'err'],
            'no_creds' => ['⚙ Sans credentials', 'nocred'],
            'none' => ['○ Jamais déposé', 'none'],
        ];
        [$statusLabel, $statusClass] = $statusLabels[$status] ?? ['?', 'warn'];
    ?>
    <div class="diff-card <?= $enRetard ? 'retard' : '' ?>">
        <div class="diff-card-head">
            <div>
                <div class="diff-card-title"><?= e($ag['nom']) ?></div>
                <div class="diff-card-sub"><?= e($ag['ville'] ?? '') ?> · <?= e($ag['code_postal'] ?? '') ?></div>
            </div>
            <span class="diff-status <?= $statusClass ?>"><?= e($statusLabel) ?></span>
        </div>

        <div class="diff-meta">
            <span>Dernier OK :</span>
            <strong>
                <?php if ($lastOk): ?>
                    <?= e((new DateTime($lastOk['started_at']))->format('d/m/Y H:i')) ?>
                    <?php if ($lastOk['annonces_count'] !== null): ?>
                        <span style="color:var(--muted);font-weight:400;">· <?= (int)$lastOk['annonces_count'] ?> annonce(s)</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:#a85858;">Jamais</span>
                <?php endif; ?>
            </strong>

            <span>Dépôts 30j :</span>
            <strong><?= $count30 ?></strong>

            <?php if ($last && $status !== 'ok'): ?>
            <span>Dernier msg :</span>
            <strong style="color:<?= $status === 'ftp_error' || $status === 'build_error' ? '#a85858' : '#8a8680' ?>;font-size:10px;">
                <?= e(mb_substr((string)$last['error_msg'], 0, 80)) ?>
            </strong>
            <?php endif; ?>
        </div>

        <div class="diff-actions">
            <button type="button" class="diff-btn" onclick="ubiflowDeploy('<?= e($slug) ?>', this)">
                📤 Envoyer maintenant
            </button>
            <button type="button" class="diff-btn ghost" onclick="ubiflowForceResend('<?= e($slug) ?>', this)" title="Ignore la garde anti-doublons">
                ↻ Force
            </button>
            <?php if ($roleId === 1): /* Admin uniquement : viewer du fichier exporté */ ?>
            <a href="/public_html/admin/admin_ubiflow_view_export.php?slug=<?= e($slug) ?>"
               target="_blank"
               class="diff-btn ghost"
               style="background:#fef3c7;border-color:#fcd34d;color:#92400e;text-decoration:none;display:inline-flex;align-items:center;gap:4px;"
               title="Admin · Voir le XML envoyé à Ubiflow (LBC/SeLoger/Bien'ici)">
                📄 Voir XML
            </a>
            <?php endif; ?>
        </div>
        <div style="display:flex;justify-content:flex-end;padding-top:6px;">
            <button type="button" class="diff-btn ghost" style="flex:0 0 auto;font-size:10px;padding:5px 12px;"
                    onclick="openBiensDiffModal(<?= (int)($ag['id_agence'] ?? 0) ?>, '<?= e($ag['nom']) ?>')"
                    title="Voir les biens diffusés de cette agence">
                📋 <?= (int)$ag['_nb_biens_diffuses'] ?> bien(s) diffusé(s) →
            </button>
            <?php $nbAD = (int)($ag['_nb_a_diffuser'] ?? 0); ?>
            <span style="flex:0 0 auto;display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:99px;font-size:11px;font-weight:600;background:<?= $nbAD > 0 ? '#fff7ed' : '#f1f5f9' ?>;color:<?= $nbAD > 0 ? '#c2410c' : '#8a8680' ?>;border:1px solid <?= $nbAD > 0 ? '#fdba74' : '#e2e8f0' ?>;"
                  title="Annonces avec visible_portails=1 mais pas encore diffusées">
                ⏳ <?= $nbAD ?> à diffuser
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ══ MODAL : Biens diffusés par agence ══ -->
<div id="biens-diff-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:10000;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)closeBiensDiffModal()">
    <div style="background:#fff;border-radius:12px;max-width:1000px;width:100%;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.4);">
        <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
            <h2 id="biens-diff-modal-title" style="margin:0;font-size:18px;color:#0f172a;">📋 Biens diffusés</h2>
            <button type="button" onclick="closeBiensDiffModal()" style="background:transparent;border:none;font-size:24px;cursor:pointer;color:#64748b;">×</button>
        </div>
        <div id="biens-diff-modal-body" style="padding:20px;overflow-y:auto;font-size:13px;">
            <div style="text-align:center;padding:40px;color:#94a3b8;">⏳ Chargement…</div>
        </div>
    </div>
</div>

<!-- ══ HISTORIQUE 30 DERNIERS JOURS ══ -->
<div class="section-header" style="margin-top:8px;margin-bottom:16px;">
    <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Historique des dépôts (30 derniers jours)</span>
        <div class="line-r"></div>
    </div>
</div>

<div class="diff-history">
    <h3>📜 Journal versionné — <?= count($history) ?> dernière(s) action(s)</h3>

    <?php if (empty($history)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">
        Aucun dépôt enregistré dans les 30 derniers jours.
    </div>
    <?php else: ?>
    <div class="diff-history-row diff-history-header">
        <span>Date</span>
        <span>Agence</span>
        <span>Statut</span>
        <span style="text-align:right;">Ann.</span>
        <span>Détails</span>
        <span>Origine</span>
    </div>
    <?php foreach ($history as $idx => $row):
        $status = $row['status'];
        $statusClass = match($status) {
            'ok'                => 'ok',
            'skipped_duplicate' => 'dup',
            'ftp_error'         => 'err',
            'build_error'       => 'err',
            'no_creds'          => 'nocred',
            default             => 'none',
        };
        $statusLabel = match($status) {
            'ok'                => '✓ OK',
            'skipped_duplicate' => '⊘ Doublon',
            'ftp_error'         => '❌ FTP',
            'build_error'       => '❌ Build',
            'no_creds'          => '⚙ Creds',
            default             => $status,
        };
        $userLabel = '';
        if ($row['triggered_user'] && ($row['u_prenom'] || $row['u_nom'])) {
            $userLabel = trim(($row['u_prenom'] ?? '') . ' ' . ($row['u_nom'] ?? ''));
        }
        // Les 10 premières lignes visibles, le reste caché derrière "Déplier"
        $hiddenAttr = $idx >= 10 ? ' data-history-extra="1" style="display:none;"' : '';
    ?>
    <div class="diff-history-row"<?= $hiddenAttr ?>>
        <span class="col-date"><?= e((new DateTime($row['started_at']))->format('d/m H:i:s')) ?></span>
        <span class="col-slug"><?= e($row['slug_agence']) ?></span>
        <span><span class="diff-status <?= $statusClass ?>"><?= e($statusLabel) ?></span></span>
        <span class="col-count"><?= $row['annonces_count'] !== null ? (int)$row['annonces_count'] : '—' ?></span>
        <span class="col-err">
            <?php if ($row['error_msg']): ?>
                <?= e(mb_substr((string)$row['error_msg'], 0, 60)) ?>
            <?php elseif ($status === 'ok'): ?>
                <span style="color:#8a8680;">md5 <?= e(substr((string)$row['zip_md5'], 0, 8)) ?>… · <?= (int)$row['duration_ms'] ?> ms</span>
            <?php endif; ?>
        </span>
        <span class="col-user">
            <?= e($row['triggered_by']) ?>
            <?php if ($userLabel): ?>
                <br><span style="color:#8a8680;"><?= e($userLabel) ?></span>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if (count($history) > 10): ?>
    <div style="text-align:center;padding-top:14px;">
        <button type="button" id="btn-history-toggle" onclick="toggleHistoryExtra()"
                style="background:#fff;color:#0369a1;border:1px solid #cbd5e1;padding:8px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:12px;font-family:inherit;">
            ▼ Déplier (<?= count($history) - 10 ?> autres)
        </button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
