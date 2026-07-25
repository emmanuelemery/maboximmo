<?php
/**
 * creancier_dossier360.php — Vue 360° DÉFINITIVE d'un dossier créancier.
 *
 * Page de PRÉSENTATION : toutes les données métier proviennent EXCLUSIVEMENT du
 * contrat de lecture creancier_360_data() (inc/creancier_360.php). Aucune requête
 * métier inline, aucun calcul de rôle/finance/timeline dupliqué ici.
 *
 * S'adapte automatiquement à la POSITION de notre partie (DEBITEUR / CREANCIER /
 * MIXTE / INCONNUE) fournie par le contrat — jamais recalculée dans la page.
 *
 * Actions : réutilisent les API d'écriture existantes (item, mouvement, échéancier,
 * garant, contact, feed, note, chat, partage, accès, énervé, analyse, frais) +
 * qualification manuelle de lien (api/creancier_lien_action.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
if (!function_exists('mail_compose_url') && is_file(__DIR__ . '/inc/mail_button.php')) require_once __DIR__ . '/inc/mail_button.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/tiers_selector.php';
require_once __DIR__ . '/inc/acteur_modal.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';   // ACL technique (creancier_user_can_access_dossier)
require_once __DIR__ . '/inc/creancier_roles.php';
require_once __DIR__ . '/inc/creancier_360.php';
if (is_file(__DIR__ . '/inc/creancier_doc_reference_pages.php')) require_once __DIR__ . '/inc/creancier_doc_reference_pages.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';
$mdlite = function (string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    return nl2br($s);
};

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isStaff = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
$base   = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';
$idDossier = (int)($_GET['id_dossier'] ?? 0);
if ($idDossier <= 0) { header('Location: ' . $base . 'creancier_liste.php'); exit; }

// ── Garde d'accès technique (auth), avant toute lecture métier ────────────────
if (!creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); exit('Accès non autorisé à ce dossier.');
}

// ── CONTRAT DE LECTURE — source unique de toutes les données affichées ────────
$viewer = ['profil' => ($isStaff ? 'staff' : 'bailleur'), 'user_id' => $userId, 'bypass_acces' => $isStaff];
$D = creancier_360_data($idDossier, $viewer);

if (!$D['ok']) {
    $pageTitle = 'Dossier créancier'; $pageSubtitle = ''; $extraCss = '';
    include __DIR__ . '/inc/agency_layout_top.php';
    echo '<div style="max-width:640px;margin:40px auto;background:#fff;border:1px solid #e6e1d8;border-radius:14px;padding:28px;text-align:center;">'
       . '<div style="font-size:40px;">⚠️</div><h2 style="color:#243B5C;">Dossier indisponible</h2>'
       . '<p style="color:#6b7280;">' . h((string)($D['error'] ?: 'Impossible de charger ce dossier.')) . '</p>'
       . '<a class="tr-btn tr-btn-primary" href="' . h($base) . 'creancier_liste.php">← Retour à la liste</a></div>';
    include __DIR__ . '/inc/agency_layout_bottom.php';
    exit;
}

// Raccourcis contrat.
$dos   = $D['dossier'];
$pos   = $D['position'];
$np    = $pos['notre_partie'];
$P     = $D['permissions'];
$fin   = $D['finances'];
$kpis  = $D['kpis'];
$parties = $D['parties'];
$avocats = $D['avocats'];
$agenda  = $D['timeline']['agenda'];
$frise   = $D['timeline']['frise'];
$ech     = $D['echeances'];
$docs    = $D['documents'];
$piecesMq = $D['pieces_manquantes'];
$actions  = $D['actions'];
$anomalies = $D['anomalies'];

$canFin=$P['can_view_finances']; $canProc=$P['can_view_procedure']; $canGuar=$P['can_view_guarantees'];
$canNotes=$P['can_view_internal_notes']; $canDocs=$P['can_view_documents']; $canUpload=$P['can_upload_documents'];
$canMsg=$P['can_send_messages']; $canManage=$P['can_manage_dossier'];

// ── CSRF (une seule fois) ─────────────────────────────────────────────────────
$csrfChat = csrf_token('creancier_chat'); $csrfAnalyse = csrf_token('creancier_analyse');
$csrfContact = csrf_token('creancier_contact'); $csrfFeed = csrf_token('creancier_feed');
$csrfNote = csrf_token('creancier_note'); $csrfPartage = csrf_token('creancier_partage');
$csrfAcces = csrf_token('creancier_acces'); $csrfMouvement = csrf_token('creancier_mouvement');
$csrfItem = csrf_token('creancier_item'); $csrfFrais = csrf_token('creancier_frais');
$csrfEch = csrf_token('creancier_echeancier'); $csrfGarant = csrf_token('creancier_garant');
$csrfLien = csrf_token('creancier_lien');

// ── Données de pilotage (staff) chargées via requêtes NON métier (partage/accès) ─
$partages = []; $accesTiers = []; $candidatsTiers = [];
if ($canManage) {
    try {
        $stp = $pdo->prepare("SELECT * FROM creancier_dossier_partage WHERE id_dossier = ? AND revoked_at IS NULL ORDER BY id DESC");
        $stp->execute([$idDossier]); $partages = $stp->fetchAll(PDO::FETCH_ASSOC);
        $sta = $pdo->prepare("SELECT a.id_user, a.niveau, u.nom, u.prenom, u.email FROM creancier_dossier_acces a JOIN users u ON u.id = a.id_user WHERE a.id_dossier = ? ORDER BY FIELD(a.niveau,'pilote','edition','lecture'), u.nom");
        $sta->execute([$idDossier]); $accesTiers = $sta->fetchAll(PDO::FETCH_ASSOC);
        $candidatsTiers = $pdo->query("SELECT DISTINCT u.id, u.nom, u.prenom, u.email FROM users u LEFT JOIN user_bailleur_modules m ON m.id_user = u.id AND m.module_code='creancier' WHERE u.super_admin=0 AND u.actif=1 AND (u.id_role IN (9,10) OR m.id_user IS NOT NULL) ORDER BY u.nom, u.prenom")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $partages = []; $accesTiers = []; $candidatsTiers = []; }
}
// Échanges (feed/notes/chat) — communication, non métier.
$feed = []; $notesPriv = []; $messages = [];
try {
    $stf = $pdo->prepare("SELECT m.id, m.message, m.created_at, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.prenom,u.nom)),''), u.username, 'Intervenant') AS auteur FROM creancier_dossier_message m LEFT JOIN users u ON u.id=m.id_user WHERE m.id_dossier=? AND m.canal='feed' ORDER BY m.id DESC LIMIT 200");
    $stf->execute([$idDossier]); $feed = $stf->fetchAll(PDO::FETCH_ASSOC);
    if ($canNotes) {
        $stn = $pdo->prepare("SELECT id, message, created_at FROM creancier_dossier_message WHERE id_dossier=? AND canal='note_privee' AND id_user=? ORDER BY id DESC LIMIT 200");
        $stn->execute([$idDossier, $userId]); $notesPriv = $stn->fetchAll(PDO::FETCH_ASSOC);
    }
    $stm = $pdo->prepare("SELECT role, message FROM creancier_dossier_message WHERE id_dossier=? AND canal='chat' ORDER BY id ASC LIMIT 100");
    $stm->execute([$idDossier]); $messages = $stm->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ── Libellés financiers / KPI selon la POSITION ─────────────────────────────── */
$POSLBL = ['DEBITEUR'=>'Nous sommes débiteurs','CREANCIER'=>'Nous sommes créanciers','MIXTE'=>'Position mixte','INCONNUE'=>'Position à préciser'];
$POSCLS = ['DEBITEUR'=>'c3-pos-deb','CREANCIER'=>'c3-pos-cre','MIXTE'=>'c3-pos-mix','INCONNUE'=>'c3-pos-unk'];
if ($np === 'DEBITEUR')      { $lblCreance='Dette réclamée'; $lblRegle='Sommes réglées'; $lblSolde='Reste dû'; }
elseif ($np === 'CREANCIER') { $lblCreance='Créance réclamée'; $lblRegle='Sommes recouvrées'; $lblSolde='Reste à recouvrer'; }
elseif ($np === 'MIXTE')     { $lblCreance='Créance réclamée'; $lblRegle='Réglé / recouvré'; $lblSolde='Solde net provisoire'; }
else                         { $lblCreance='Montant de la créance'; $lblRegle='Montant réglé'; $lblSolde='Solde'; }

// Retard (jours) & prochaine échéance depuis l'agenda du contrat.
$today = date('Y-m-d');
$retardMax = 0; $prochDate = null; $prochLbl = '';
foreach ($agenda as $ev) {
    if (!empty($ev['retard'])) { $j = (int)floor((strtotime($today) - strtotime((string)$ev['date'])) / 86400); if ($j > $retardMax) $retardMax = $j; }
    elseif ($prochDate === null && (string)$ev['date'] >= $today) { $prochDate = (string)$ev['date']; $prochLbl = (string)$ev['libelle']; }
}
$recouvrePct = ($fin['montant_creance'] > 0) ? round($fin['montant_regle'] / $fin['montant_creance'] * 100) : null;

// Icônes de rôle (affichage compact).
$roleIcon = static function (string $roleN): string {
    return [
        'creancier_principal'=>'🏦','avocat'=>'👔','avocat_debiteur'=>'👔','avocat_creancier'=>'👔',
        'commissaire_justice'=>'📜','notaire'=>'⚖️','expert_comptable'=>'🧮','gerant'=>'🧑‍💼',
        'gestionnaire'=>'🏢','heritier'=>'👪','associe'=>'🤝','autre_intervenant'=>'👤',
        'debiteur'=>'🏢','debiteur_solidaire'=>'🤝','garant'=>'🛡️','proprietaire_concerne'=>'🏠',
    ][$roleN] ?? '👤';
};
// Contacts pour la sidebar = professionnels HORS avocats (avocats gérés au bloc Parties).
$contactsSide = array_values(array_filter($D['liens']['professionnels'], fn($p) => !in_array($p['role_normalise'], ['avocat','avocat_debiteur','avocat_creancier'], true)));

$pageTitle    = 'Dossier · ' . ($dos['libelle'] ?: $dos['code']);
$pageSubtitle = 'Vue 360° · créancier';
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>
<script>window.APP_BASE = <?= json_encode(rtrim($base, '/')) ?>;</script>
<style>
.c3 { --navy:#243B5C; --or:#D4A047; --line:#e6e1d8; --ink:#3a3830; --mut:#8a8680; }
.c3-bread { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
.c3-chip { background:#f4f1ea; border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:12.5px; color:#4a463f; text-decoration:none; font-weight:600; }
.c3-chip.now { background:#fbeaea; border-color:#f0c9c9; color:#b3261e; }
.c3-chip:hover { border-color:var(--or); }
.c3-arrow { color:#b8b2a6; }
.c3-titlerow { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.c3-title { font-family:'Sora',sans-serif; font-size:24px; font-weight:800; color:var(--navy); margin:0; }
.c3-badge { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; padding:4px 11px; border-radius:999px; }
.c3-pos-deb { background:#e8eef7; color:#1d4ed8; } .c3-pos-cre { background:#e7f6ec; color:#166534; }
.c3-pos-mix { background:#fef3e2; color:#b45309; } .c3-pos-unk { background:#f1f0ee; color:#6b7280; }
.c3-risk-rouge { background:#fee2e2; color:#b91c1c; } .c3-risk-orange { background:#fef3e2; color:#b45309; } .c3-risk-vert { background:#e7f6ec; color:#166534; }
.c3-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:16px; }
.c3-kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
.c3-kpi .k { font-size:11px; color:var(--mut); text-transform:uppercase; letter-spacing:.02em; margin-bottom:4px; }
.c3-kpi .v { font-size:22px; font-weight:800; color:var(--navy); font-family:'Sora',sans-serif; }
.c3-kpi.alert .v { color:#dc2626; }
.c3-frise { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 18px; margin-bottom:18px; }
.c3-frise-head { display:flex; justify-content:space-between; font-size:12px; color:var(--mut); font-weight:700; margin-bottom:10px; }
.c3-frise-bar { height:6px; background:#eee7db; border-radius:6px; overflow:hidden; margin-bottom:10px; }
.c3-frise-fill { height:100%; background:linear-gradient(90deg,#dc2626,#f59e0b); }
.c3-frise-steps { display:flex; gap:6px; overflow-x:auto; }
.c3-step { flex:1; min-width:90px; font-size:11.5px; text-align:center; color:#b0aa9e; font-weight:600; }
.c3-step.done { color:var(--navy); } .c3-step.cur { color:#dc2626; font-weight:800; }
.c3-layout { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:16px; align-items:start; }
.c3-main { display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; }
.c3-main .c3-span { grid-column:1/3; }
.c3-side { position:sticky; top:12px; display:flex; flex-direction:column; gap:14px; }
.c3-card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 18px; }
.c3-card h3 { font-family:'Sora',sans-serif; font-size:14px; font-weight:800; color:var(--navy); margin:0 0 10px; display:flex; align-items:center; gap:8px; justify-content:space-between; }
.c3-card h3 .cnt { font-size:11px; color:var(--mut); font-weight:600; }
.c3-row { display:flex; justify-content:space-between; gap:10px; padding:7px 0; border-bottom:1px solid #f2eee7; font-size:12.5px; }
.c3-row:last-child { border-bottom:none; }
.c3-empty { color:#a29c90; font-size:12.5px; font-style:italic; padding:8px 0; }
.c3-tag { background:#eef1f6; color:#4878a6; border-radius:999px; padding:2px 8px; font-size:10px; font-weight:700; }
.c3-mini { display:grid; grid-template-columns:repeat(auto-fit,minmax(72px,1fr)); gap:10px; margin-bottom:10px; }
.c3-mini .k { font-size:10px; color:var(--mut); text-transform:uppercase; } .c3-mini .v { font-size:16px; font-weight:800; color:var(--navy); }
.c3-inter { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:8px; }
.c3-inter .cell { border:1px solid var(--line); border-radius:9px; padding:8px 10px; }
.c3-inter .cell .r { font-size:10px; color:var(--mut); text-transform:uppercase; } .c3-inter .cell .n { font-size:13px; font-weight:700; color:var(--ink); }
.c3-btn { border:none; border-radius:8px; padding:5px 10px; font-size:11.5px; font-weight:700; cursor:pointer; }
.c3-btn-p { background:var(--navy); color:#fff; } .c3-btn-g { background:#f1efe9; color:#4a463f; } .c3-btn-d { background:#fef2f2; color:#b91c1c; }
.c3-act { display:flex; flex-direction:column; gap:2px; }
.c3-act a, .c3-act button { display:flex; align-items:center; gap:9px; padding:9px 6px; border-bottom:1px solid #f2eee7; font-size:13px; color:var(--ink); text-decoration:none; background:none; border-left:none;border-right:none;border-top:none; width:100%; text-align:left; cursor:pointer; }
.c3-act a:hover, .c3-act button:hover { color:var(--navy); }
.c3-alert { background:#fff8ec; border:1px solid #f0e2c4; border-radius:10px; padding:10px 12px; font-size:12px; color:#7a5a1e; margin-bottom:6px; }
.c3-fld { padding:7px 9px; border:1px solid #d8d2c8; border-radius:7px; font-size:12.5px; }
@media (max-width:1150px){ .c3-layout { grid-template-columns:1fr; } .c3-side { position:static; } }
@media (max-width:820px){ .c3-main { grid-template-columns:1fr; } .c3-main .c3-span { grid-column:1; } }
</style>

<div class="c3">
  <!-- ══ BREADCRUMB ══ -->
  <div class="c3-bread">
    <?php
    // Fil d'ariane compact : le(s) débiteur(s), sinon le(s) propriétaire(s) concerné(s), puis le dossier.
    $bcSource = array_merge($parties['debiteurs'], $parties['debiteurs_solidaires']);
    if (!$bcSource) $bcSource = $parties['proprietaires_concernes'];
    $bcParts = [];
    foreach (array_slice($bcSource, 0, 3) as $d0) {
        $u = $d0['entity_type']==='SOCIETE' ? $base.'creancier360.php?type=SOCIETE&id='.(int)$d0['entity_id'] : $base.'creancier360.php?type=TIERS&id='.(int)$d0['entity_id'];
        $bcParts[] = '<a class="c3-chip" href="'.h($u).'">'.h($d0['label']).'</a>';
    }
    echo implode('<span class="c3-arrow">→</span>', $bcParts);
    if ($bcParts) echo '<span class="c3-arrow">→</span>';
    ?>
    <span class="c3-chip now">⚖️ <?= h($dos['code'] ?: 'Dossier') ?></span>
  </div>

  <!-- ══ TITRE + POSITION + RISQUE ══ -->
  <div class="c3-titlerow">
    <h1 class="c3-title"><?= h($dos['libelle'] ?: $dos['code']) ?></h1>
    <span class="c3-badge <?= $POSCLS[$np] ?>"><?= h($POSLBL[$np]) ?></span>
    <?php if ($dos['niveau_risque']): ?><span class="c3-badge c3-risk-<?= h($dos['niveau_risque']) ?>"><?= strtoupper(h($dos['niveau_risque'])) ?></span><?php endif; ?>
    <span style="color:#a29c90;font-size:12px;">· <?= h(['actif'=>'Actif','surveillance'=>'Surveillance','clos'=>'Clos'][$dos['statut']] ?? $dos['statut']) ?><?= $dos['numero_dossier_adverse'] ? ' · n° '.h($dos['numero_dossier_adverse']) : '' ?></span>
  </div>

  <!-- ══ KPI (adaptés à la position) ══ -->
  <?php $lblInit = ($np==='CREANCIER') ? 'Créance initiale' : 'Dette initiale'; ?>
  <div class="c3-kpis">
    <div class="c3-kpi"><div class="k"><?= h($lblInit) ?></div><div class="v"><?= $eur($fin['montant_initial']) ?></div></div>
    <div class="c3-kpi<?= $fin['solde']>0?' alert':'' ?>"><div class="k"><?= h($lblSolde) ?></div><div class="v"><?= $eur($fin['solde']) ?></div></div>
    <?php if ($retardMax > 0): ?><div class="c3-kpi alert"><div class="k">Retard</div><div class="v"><?= (int)$retardMax ?> j</div></div><?php endif; ?>
    <?php if ($prochDate): ?><div class="c3-kpi"><div class="k">Prochaine échéance</div><div class="v" style="font-size:16px;"><?= $dfr($prochDate) ?></div></div><?php endif; ?>
    <?php if ($recouvrePct !== null): ?><div class="c3-kpi"><div class="k"><?= $np==='DEBITEUR'?'Réglé':'Recouvré' ?></div><div class="v"><?= (int)$recouvrePct ?> %</div></div><?php endif; ?>
  </div>

  <!-- ══ FRISE PROCÉDURALE ══ -->
  <?php $ec = $frise['etape_courante']; $nb = count($frise['etapes']); $fillPct = ($ec !== null) ? round((($ec+1)/$nb)*100) : 0; ?>
  <div class="c3-frise">
    <div class="c3-frise-head"><span>Procédure<?= $np==='INCONNUE'||$np==='MIXTE' ? ' (libellés neutres — position à préciser)' : '' ?></span><span><?= $ec!==null ? (($ec+1).' / '.$nb) : '—' ?></span></div>
    <div class="c3-frise-bar"><div class="c3-frise-fill" style="width:<?= (int)$fillPct ?>%;"></div></div>
    <div class="c3-frise-steps">
      <?php foreach ($frise['etapes'] as $i => $st): $cls = $ec===null?'':($i<$ec?'done':($i===$ec?'cur':'')); ?>
        <div class="c3-step <?= $cls ?>"><?= h($st['libelle']) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="c3-layout">
    <!-- ══════════ COLONNE PRINCIPALE : 4 BLOCS ══════════ -->
    <div class="c3-main">

      <!-- ── BLOC 1 · PARTIES & INTERVENANTS ── -->
      <div class="c3-card">
        <h3>👥 Parties & intervenants</h3>

        <?php
        $renderParty = function(array $p) use ($base, $roleIcon, $eur) {
            $roleN = $p['role_normalise'];
            $url = $roleN==='creancier_principal' ? $base.'creancier_creancier360.php?id='.(int)$p['entity_id']
                 : ($p['entity_type']==='TIERS' ? $base.'tiers_360.php?id='.(int)$p['entity_id'] : null);
            $coord = trim((string)($p['email'] ?? '') ?: (string)($p['tel'] ?? '') ?: (string)($p['mobile'] ?? ''));
            $g = ($roleN==='garant' && $p['montant_garanti']!==null) ? ' · <span style="color:#8a5a2b;font-weight:700;">garant '.$eur($p['montant_garanti']).'</span>' : '';
            echo '<div class="c3-row"><span>'.$roleIcon($roleN).' ';
            echo $url ? '<a href="'.h($url).'" style="color:var(--navy);font-weight:600;text-decoration:none;">'.h($p['label']).' ↗</a>' : '<strong>'.h($p['label']).'</strong>';
            echo ' <span class="c3-tag">'.h(creancier_role_label($p['role_stocke'])).'</span>'.$g.'</span>';
            echo '<span style="color:#9a9690;font-size:11px;">'.h($coord).'</span></div>';
        };
        // Noms déjà présents côté débiteur : un « propriétaire concerné » homonyme est
        // redondant (c'est le débiteur lui-même) → on ne le réaffiche pas.
        $debNames = [];
        foreach (array_merge($parties['debiteurs'], $parties['debiteurs_solidaires']) as $p) $debNames[mb_strtolower(trim((string)$p['label']))] = true;
        $hasParty = false; $seenParty = [];
        foreach (['debiteurs','debiteurs_solidaires','creanciers','proprietaires_concernes'] as $grp) {
            foreach ($parties[$grp] as $p) {
                $lbl = mb_strtolower(trim((string)$p['label']));
                // Dédoublonnage d'affichage : un même nom + rôle n'apparaît qu'une fois.
                $key = $p['role_normalise'] . '|' . $lbl;
                if (isset($seenParty[$key])) continue;
                if ($grp === 'proprietaires_concernes' && isset($debNames[$lbl])) continue; // homonyme du débiteur
                $seenParty[$key] = true;
                $renderParty($p); $hasParty = true;
            }
        }
        if ($canGuar) foreach ($parties['garants'] as $p) { $renderParty($p); $hasParty = true; }
        if (!$hasParty) echo '<div class="c3-empty">Aucune partie enregistrée.</div>';
        ?>

        <!-- Avocats (notre / adverse / à qualifier) -->
        <div style="margin-top:12px;">
          <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin-bottom:6px;">Avocats</div>
          <?php
          $noAvo = true;
          foreach ($avocats['notre_avocat'] as $a) { $noAvo=false; echo '<div class="c3-row"><span>👔 <strong>'.h($a['label']).'</strong> <span class="c3-tag" style="background:#e7f6ec;color:#166534;">Notre avocat</span></span></div>'; }
          foreach ($avocats['avocat_adverse'] as $a) { $noAvo=false; echo '<div class="c3-row"><span>👔 <strong>'.h($a['label']).'</strong> <span class="c3-tag" style="background:#fee2e2;color:#b91c1c;">Adverse</span></span></div>'; }
          // Avocats objectifs non couverts par notre/adverse (MIXTE/INCONNUE) :
          if (in_array($np, ['MIXTE','INCONNUE'], true)) {
              foreach ($avocats['debiteur'] as $a)  { $noAvo=false; echo '<div class="c3-row"><span>👔 <strong>'.h($a['label']).'</strong> <span class="c3-tag">Avocat côté débiteur</span></span></div>'; }
              foreach ($avocats['creancier'] as $a) { $noAvo=false; echo '<div class="c3-row"><span>👔 <strong>'.h($a['label']).'</strong> <span class="c3-tag">Avocat côté créancier</span></span></div>'; }
          }
          foreach ($avocats['non_qualifies'] as $a):
              $noAvo=false; ?>
              <div class="c3-row">
                <span>👔 <strong><?= h($a['label']) ?></strong> <span class="c3-tag" style="background:#fef3e2;color:#b45309;">à qualifier</span></span>
                <?php if ($canManage): ?>
                <span style="display:flex;gap:5px;">
                  <button type="button" class="c3-btn c3-btn-g" onclick="creQualifAvocat(<?= (int)$a['entity_id'] ?>,'avocat_debiteur')">côté débiteur</button>
                  <button type="button" class="c3-btn c3-btn-g" onclick="creQualifAvocat(<?= (int)$a['entity_id'] ?>,'avocat_creancier')">côté créancier</button>
                </span>
                <?php endif; ?>
              </div>
          <?php endforeach;
          if ($noAvo) echo '<div class="c3-empty">Aucun avocat qualifié.</div>';
          ?>
        </div>

        <?php // Liens à préciser (groupe, etc.)
        if (!empty($D['liens']['a_investiguer'])): ?>
        <div style="margin-top:10px;">
          <div style="font-size:11px;color:#b45309;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Liens à préciser</div>
          <?php foreach ($D['liens']['a_investiguer'] as $p): ?>
            <div class="c3-row">
              <span>🔗 <strong><?= h($p['label']) ?></strong> <span class="c3-tag" style="background:#fef3e2;color:#b45309;"><?= h($p['role_stocke']) ?> — à préciser</span></span>
              <?php if ($canManage && $p['role_stocke']==='groupe'): ?>
              <span style="display:flex;gap:5px;">
                <button type="button" class="c3-btn c3-btn-g" onclick="creSetLien('<?= h($p['entity_type']) ?>',<?= (int)$p['entity_id'] ?>,'groupe','debiteur')">débiteur</button>
                <button type="button" class="c3-btn c3-btn-g" onclick="creSetLien('<?= h($p['entity_type']) ?>',<?= (int)$p['entity_id'] ?>,'groupe','autre_intervenant')">intervenant</button>
              </span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
          <div style="margin-top:12px;"><?= acteur_modal_button('cre_acteur', '➕ Ajouter un intervenant') ?></div>
        <?php endif; ?>
      </div>

      <!-- ── BLOC 2 · SITUATION FINANCIÈRE & ÉCHÉANCES ── -->
      <?php if ($canFin): ?>
      <div class="c3-card">
        <h3>💶 Compte de la <?= $np==='DEBITEUR'?'dette':'créance' ?></h3>
        <div class="c3-mini">
          <div><div class="k"><?= h($lblCreance) ?></div><div class="v"><?= $eur($fin['montant_creance']) ?></div></div>
          <?php if ($fin['montant_reconnu']>0): ?><div><div class="k">Reconnu</div><div class="v"><?= $eur($fin['montant_reconnu']) ?></div></div><?php endif; ?>
          <?php if ($fin['montant_conteste']>0): ?><div><div class="k">Contesté</div><div class="v"><?= $eur($fin['montant_conteste']) ?></div></div><?php endif; ?>
          <?php if ($fin['interets']>0): ?><div><div class="k">Intérêts</div><div class="v"><?= $eur($fin['interets']) ?></div></div><?php endif; ?>
          <?php if ($fin['frais']>0): ?><div><div class="k">Frais</div><div class="v"><?= $eur($fin['frais']) ?></div></div><?php endif; ?>
          <div><div class="k"><?= h($lblRegle) ?></div><div class="v" style="color:#166534;"><?= $eur($fin['montant_regle']) ?></div></div>
          <div><div class="k"><?= h($lblSolde) ?></div><div class="v" style="color:#dc2626;"><?= $eur($fin['solde']) ?></div></div>
        </div>
        <?php if ($np==='MIXTE'): ?><div class="c3-alert">⚠️ Ventilation des créances (sens débiteur / créancier) à préciser.</div><?php endif; ?>

        <!-- Mouvements -->
        <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin:4px 0 6px;">Derniers mouvements <span class="cnt"><?= count($fin['mouvements']) ?></span></div>
        <?php if (!$fin['mouvements']): ?><div class="c3-empty">Aucun mouvement financier.</div><?php endif; ?>
        <?php foreach (array_slice($fin['mouvements'],0,6) as $m): ?>
          <div class="c3-row" data-mid="<?= (int)$m['id'] ?>">
            <span><span class="c3-tag"><?= h(creancier_mouvement_labels()[$m['type']] ?? $m['type']) ?></span>
              <?= $m['date_mouvement'] ? ' · '.$dfr($m['date_mouvement']) : '' ?>
              <?= !empty($m['beneficiaire']) ? ' · '.h($m['beneficiaire']) : '' ?></span>
            <span style="display:flex;gap:8px;align-items:center;"><strong><?= $eur($m['montant']) ?></strong>
              <?php if ($canManage): ?><button type="button" class="c3-btn c3-btn-d" onclick="mvDelete(<?= (int)$m['id'] ?>)">✕</button><?php endif; ?></span>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
        <details style="margin-top:10px;"><summary style="cursor:pointer;font-size:12px;color:var(--navy);font-weight:700;">➕ Ajouter un mouvement / extraire les frais (IA)</summary>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;">
            <select id="mvType" class="c3-fld"><?php foreach (creancier_mouvement_labels() as $k=>$l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
            <input type="text" id="mvMontant" inputmode="decimal" placeholder="Montant € *" class="c3-fld">
            <input type="date" id="mvDate" class="c3-fld">
            <input type="text" id="mvRef" placeholder="Référence" class="c3-fld">
            <input type="text" id="mvNote" placeholder="Note / bénéficiaire" class="c3-fld" style="grid-column:1/3;">
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:8px;"><button type="button" id="mvAdd" class="c3-btn c3-btn-p">Enregistrer</button><span id="mvMsg" style="font-size:12px;"></span></div>
          <div style="margin-top:10px;border-top:1px dashed var(--line);padding-top:10px;">
            <input type="file" id="fraisFile" accept="application/pdf" style="font-size:12px;"> <button type="button" id="fraisAnalyse" class="c3-btn c3-btn-g">🧠 Analyser un décompte</button> <span id="fraisMsg" style="font-size:12px;"></span>
            <div id="fraisResult" style="margin-top:8px;"></div>
          </div>
        </details>
        <?php endif; ?>

        <!-- Échéancier prévisionnel -->
        <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin:14px 0 6px;">Échéancier prévisionnel <span class="cnt">prévu <?= $eur($ech['total_prevu']) ?><?= $ech['total_recu']>0?' · reçu '.$eur($ech['total_recu']):'' ?></span></div>
        <?php if (!$ech['rows']): ?><div class="c3-empty">Aucun échéancier défini.</div><?php else: $typeLbl=['mensualite'=>'Mensualité','acompte'=>'Acompte','unique'=>'Échéance']; ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;">
          <?php foreach ($ech['rows'] as $e): $stt=$e['statut']; $bg=$stt==='recu'?'#e7f6ec':($stt==='annule'?'#f1f5f9':'#fff'); $ret=($stt==='prevu' && (string)$e['date_prevue']<$today); ?>
            <div style="border:1px solid var(--line);border-radius:9px;padding:8px 10px;background:<?= $bg ?>;<?= $stt==='annule'?'opacity:.55;':'' ?>">
              <div style="font-size:10px;color:var(--mut);"><?= h($typeLbl[$e['type']]??$e['type']) ?> · <?= $dfr($e['date_prevue']) ?><?= $ret?' <span style="color:#dc2626;">⏰</span>':'' ?></div>
              <div style="font-weight:800;font-size:14px;color:var(--navy);"><?= $eur($e['montant']) ?><?= $stt==='recu'?' ✅':'' ?></div>
              <?php if ($canManage): ?><div style="display:flex;gap:5px;margin-top:4px;"><?php if ($stt!=='recu'): ?><button type="button" class="c3-btn" style="background:#dcfce7;color:#166534;font-size:10px;" onclick="echStatut(<?= (int)$e['id'] ?>,'recu')">Reçu</button><?php endif; ?><button type="button" class="c3-btn c3-btn-d" style="font-size:10px;" onclick="echSuppr(<?= (int)$e['id'] ?>)">✕</button></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($canManage): ?>
        <details style="margin-top:8px;"><summary style="cursor:pointer;font-size:12px;color:var(--navy);font-weight:700;">➕ Planifier des échéances</summary>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;">
            <div><label style="font-size:10px;color:var(--mut);display:block;">Mensualité €</label><input type="number" id="ech-m-montant" class="c3-fld" style="width:100px;"></div>
            <div><label style="font-size:10px;color:var(--mut);display:block;">1re date</label><input type="date" id="ech-m-date" class="c3-fld"></div>
            <div><label style="font-size:10px;color:var(--mut);display:block;">Nb mois</label><input type="number" id="ech-m-nb" value="12" class="c3-fld" style="width:60px;"></div>
            <button type="button" class="c3-btn c3-btn-p" onclick="echGenerer()">Générer</button>
            <span style="width:1px;height:28px;background:var(--line);"></span>
            <div><label style="font-size:10px;color:var(--mut);display:block;">Acompte €</label><input type="number" id="ech-a-montant" class="c3-fld" style="width:100px;"></div>
            <div><label style="font-size:10px;color:var(--mut);display:block;">Date</label><input type="date" id="ech-a-date" class="c3-fld"></div>
            <button type="button" class="c3-btn c3-btn-g" onclick="echAjouter()">+ Échéance</button>
          </div>
        </details>
        <?php endif; ?>
      </div>
      <?php endif; /* canFin */ ?>

      <!-- ── BLOC 3 · SYNTHÈSE & PROCÉDURE ── -->
      <div class="c3-card">
        <h3>📝 Synthèse & procédure</h3>
        <div style="font-size:13px;line-height:1.55;color:var(--ink);white-space:pre-line;">
          <?= $D['synthese']['synthese'] ? h($D['synthese']['synthese']) : '<span class="c3-empty">Aucune synthèse saisie.</span>' ?>
        </div>
        <?php if ($D['synthese']['analyse_ia']): ?>
        <details style="margin-top:10px;"><summary style="cursor:pointer;font-size:12px;color:#7c3aed;font-weight:700;">✨ Analyse IA (indicative)</summary>
          <div style="font-size:12.5px;line-height:1.5;color:#4a463f;margin-top:6px;background:#f7f5ff;border-radius:8px;padding:10px;"><?= $mdlite((string)$D['synthese']['analyse_ia']) ?></div>
        </details>
        <?php elseif ($canManage): ?>
          <div style="margin-top:8px;"><button type="button" id="genAnalyse" class="c3-btn c3-btn-g">🧠 Générer une analyse IA</button> <span id="analyseBox" style="font-size:12px;"></span></div>
        <?php endif; ?>
        <?php if ($D['synthese']['commentaire'] && $canProc): ?>
        <details style="margin-top:10px;"><summary style="cursor:pointer;font-size:12px;color:var(--navy);font-weight:700;">⚖️ Commentaire / avis avocat</summary>
          <div style="font-size:12.5px;white-space:pre-line;color:var(--ink);margin-top:6px;"><?= h($D['synthese']['commentaire']) ?></div>
        </details>
        <?php endif; ?>

        <?php if ($canProc): ?>
        <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin:14px 0 6px;">Actions & procédures <span class="cnt"><?= count($actions) ?></span></div>
        <?php if (!$actions): ?><div class="c3-empty">Aucune action en cours.</div><?php endif; ?>
        <?php foreach ($actions as $it): $ret=(!empty($it['date_echeance']) && (string)$it['date_echeance']<$today && ($it['statut']??'')!=='fait'); ?>
          <div class="c3-row">
            <span><span class="c3-tag"><?= h($it['type']) ?></span> <?= h($it['titre']) ?><?= $it['statut']?' · <span style="color:#9a9690;">'.h($it['statut']).'</span>':'' ?></span>
            <strong style="color:<?= $ret?'#dc2626':'#4878a6' ?>;"><?= $it['date_echeance']?$dfr($it['date_echeance']):'' ?></strong>
          </div>
        <?php endforeach; ?>
        <?php if ($canManage): ?>
        <details style="margin-top:8px;"><summary style="cursor:pointer;font-size:12px;color:var(--navy);font-weight:700;">➕ Ajouter une action / échéance</summary>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;">
            <select id="evType" class="c3-fld"><option value="ACTION">Action à faire</option><option value="PROCEDURE">Procédure</option><option value="DECISION">Décision</option><option value="ECHEANCE">Échéance</option></select>
            <input type="date" id="evDate" class="c3-fld">
            <input type="text" id="evTitre" placeholder="Intitulé *" class="c3-fld" style="grid-column:1/3;">
          </div>
          <div style="margin-top:8px;"><button type="button" id="evAdd" class="c3-btn c3-btn-p">Ajouter</button> <span id="evMsg" style="font-size:12px;"></span></div>
        </details>
        <?php endif; ?>
        <?php endif; /* canProc */ ?>
      </div>

      <!-- ── BLOC 4 · DOCUMENTS ── -->
      <?php if ($canDocs): ?>
      <div class="c3-card">
        <h3>📂 Documents <span class="cnt"><?= count($docs) ?></span></h3>
        <?php if (!$docs): ?><div class="c3-empty">Aucun document rattaché.</div><?php endif; ?>
        <?php
        $refDocId = function_exists('creancier_doc_reference_document_id') ? creancier_doc_reference_document_id($pdo) : 0;
        $refPage  = function_exists('creancier_doc_reference_page') ? creancier_doc_reference_page((string)$dos['code']) : null;
        foreach ($docs as $d):
            $docId = (int)($d['id'] ?? 0);
            $isRef = ($refDocId > 0 && $docId === $refDocId);
            $href  = $base . 'api/ged_doc_serve.php?id=' . $docId;
            if ($isRef && $refPage) $href .= '#page=' . (int)$refPage;
            $name = $d['name_display'] ?? $d['name_file'] ?? ('Doc #'.$docId);
        ?>
          <div class="c3-row">
            <span style="font-size:12px;">
              <span style="font-family:'DM Mono',monospace;color:#5b21b6;font-weight:700;">[<?= h($d['document_type'] ?? '') ?>]</span>
              <a href="<?= h($href) ?>" target="_blank" rel="noopener" style="color:var(--navy);text-decoration:none;">📄 <?= h($name) ?></a>
              <?php if ($isRef && $refPage): ?><span class="c3-tag" style="background:#fef3e2;color:#b45309;">↳ p.<?= (int)$refPage ?></span><?php endif; ?>
            </span>
            <span style="color:#9a9690;font-size:10px;"><?= isset($d['created_at'])?h(date('d/m/y',strtotime((string)$d['created_at']))):'' ?></span>
          </div>
        <?php endforeach; ?>
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
          <?php if ($canUpload): ?><button type="button" class="c3-btn c3-btn-p" onclick="fbxOpenUploadModal({creancier_dossier_id:<?= $idDossier ?>, soc_id:<?= (int)($dos['id_societe']??0) ?>, age_id:<?= (int)($dos['id_agence']??0) ?>, origin:'creancier'});return false;">📄 Charger un document</button><?php endif; ?>
          <?php if ($canUpload): ?><a class="c3-btn c3-btn-g" href="<?= h($base) ?>document_request_new.php" style="text-decoration:none;">✉️ Demander une pièce</a><?php endif; ?>
        </div>
        <?php
        $gsfCardFile = __DIR__ . '/inc/ged_source_folders_card.php';
        if (is_file($gsfCardFile)) { require_once $gsfCardFile;
            if (function_exists('ged_source_folders_card')) { try {
                ged_source_folders_card($pdo, 'CREANCIER_DOSSIER', $idDossier, ['id_societe'=>(int)($dos['id_societe'] ?? 0), 'id_agence'=>(int)($dos['id_agence'] ?? 0)]);
            } catch (Throwable $e) {} } }
        ?>
      </div>
      <?php endif; ?>

      <!-- ── ÉCHANGES (fil / notes perso / discussion IA) — pleine largeur ── -->
      <div class="c3-card c3-span">
        <h3>💬 Échanges & suivi</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <!-- Fil d'actualité -->
          <div>
            <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin-bottom:6px;">📣 Fil d'actualité (partagé)</div>
            <?php if ($canMsg): ?>
            <div style="display:flex;gap:6px;margin-bottom:8px;"><textarea id="feedInput" rows="2" placeholder="Commentaire horodaté & signé…" class="c3-fld" style="flex:1;resize:vertical;"></textarea><button type="button" id="feedSend" class="c3-btn c3-btn-p" style="align-self:flex-end;">Publier</button></div>
            <div id="feedMsg" style="font-size:12px;"></div>
            <?php endif; ?>
            <div id="feedList">
              <?php if (!$feed): ?><div class="c3-empty" id="feedEmpty">Aucun commentaire.</div><?php endif; ?>
              <?php foreach ($feed as $f): ?>
                <div style="padding:8px 0;border-bottom:1px solid #f2eee7;"><div style="font-size:11px;color:var(--mut);"><strong style="color:var(--navy);"><?= h($f['auteur']) ?></strong> · <?= h(date('d/m/Y H:i', strtotime((string)$f['created_at']))) ?></div><div style="font-size:12.5px;white-space:pre-line;color:var(--ink);"><?= h($f['message']) ?></div></div>
              <?php endforeach; ?>
            </div>
          </div>
          <!-- Notes perso (staff) + Chat IA -->
          <div>
            <?php if ($canNotes): ?>
            <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin-bottom:6px;">🔒 Mes notes (privées)</div>
            <div style="display:flex;gap:6px;margin-bottom:8px;"><textarea id="noteInput" rows="2" placeholder="Note perso — visible de vous seul…" class="c3-fld" style="flex:1;resize:vertical;"></textarea><button type="button" id="noteSend" class="c3-btn c3-btn-p" style="align-self:flex-end;">Noter</button></div>
            <div id="noteMsg" style="font-size:12px;"></div>
            <div id="noteList" style="margin-bottom:12px;">
              <?php if (!$notesPriv): ?><div class="c3-empty" id="noteEmpty">Aucune note.</div><?php endif; ?>
              <?php foreach ($notesPriv as $n): ?>
                <div style="padding:8px 0;border-bottom:1px solid #f2eee7;" data-note-id="<?= (int)$n['id'] ?>"><div style="font-size:11px;color:var(--mut);display:flex;justify-content:space-between;"><span><?= h(date('d/m/Y H:i', strtotime((string)$n['created_at']))) ?></span><button type="button" class="noteDel" data-id="<?= (int)$n['id'] ?>" style="border:none;background:none;color:#a85858;cursor:pointer;font-size:11px;">supprimer</button></div><div style="font-size:12.5px;white-space:pre-line;color:var(--ink);"><?= h($n['message']) ?></div></div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="font-size:11px;color:var(--mut);text-transform:uppercase;font-weight:700;margin-bottom:6px;">✨ Question IA sur le dossier</div>
            <div id="creChatBox" style="max-height:180px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
              <?php if (!$messages): ?><div class="c3-empty" id="creChatEmpty">Posez une question — l'IA répond depuis le contexte du dossier.</div><?php endif; ?>
              <?php foreach ($messages as $m): $me=$m['role']==='user'; ?><div style="max-width:85%;padding:7px 11px;border-radius:11px;font-size:12px;white-space:pre-line;<?= $me?'align-self:flex-end;background:var(--navy);color:#fff;':'align-self:flex-start;background:#eef1f6;color:var(--navy);' ?>"><?= h($m['message']) ?></div><?php endforeach; ?>
            </div>
            <div style="display:flex;gap:6px;"><input type="text" id="creChatInput" placeholder="Votre question…" class="c3-fld" style="flex:1;"><button type="button" id="creChatSend" class="c3-btn c3-btn-p">Envoyer</button></div>
          </div>
        </div>
      </div>

    </div><!-- /c3-main -->

    <!-- ══════════ SIDEBAR DROITE : PILOTAGE ══════════ -->
    <div class="c3-side">

      <!-- Prochaine action / échéance -->
      <div class="c3-card">
        <h3>🎯 Prochaines échéances</h3>
        <?php if ($prochDate): ?>
          <div class="c3-row"><span>📅 <?= h($prochLbl ?: 'Échéance') ?></span><strong style="color:#4878a6;"><?= $dfr($prochDate) ?></strong></div>
        <?php endif; ?>
        <?php $rc=0; foreach ($agenda as $ev): if (empty($ev['retard'])) continue; $rc++; if ($rc>4) break; ?>
          <div class="c3-row"><span>⏰ <?= h($ev['libelle']) ?></span><strong style="color:#dc2626;"><?= $dfr($ev['date']) ?></strong></div>
        <?php endforeach; ?>
        <?php if (!$prochDate && !$rc): ?><div class="c3-empty">Aucune échéance à venir.</div><?php endif; ?>
      </div>

      <!-- Pièces manquantes -->
      <div class="c3-card" style="border-left:3px solid var(--or);">
        <h3>📋 Pièces manquantes <span class="cnt"><?= count($piecesMq) ?></span></h3>
        <?php if (!$piecesMq): ?><div class="c3-empty">Aucune pièce manquante.</div><?php endif; ?>
        <?php foreach ($piecesMq as $pm): ?><div class="c3-row"><span>☐ <?= h($pm['titre']) ?></span></div><?php endforeach; ?>
      </div>

      <!-- Points à vérifier (STAFF uniquement) -->
      <?php if ($isStaff && $anomalies):
        $anoMsg = [
          'POSITION_NON_DETERMINEE'=>'La position de notre partie n\'est pas encore déterminée.',
          'DOSSIER_SANS_DEBITEUR'=>'Aucun débiteur rattaché au dossier.',
          'DOSSIER_SANS_CREANCIER'=>'Aucun créancier rattaché au dossier.',
          'DOSSIER_MULTI_DEBITEURS'=>'Plusieurs débiteurs principaux.',
          'DOSSIER_MULTI_CREANCIERS'=>'Plusieurs créanciers principaux.',
          'ROLE_NON_RECONNU'=>'Un rôle de lien n\'est pas reconnu.',
          'ROLE_A_INVESTIGUER'=>'Un rôle « groupe » doit être précisé.',
          'ROLE_ENTITY_TYPE_INCOMPATIBLE'=>'Un rôle est incompatible avec le type d\'entité.',
          'ENTITE_INTROUVABLE'=>'Une entité liée au dossier est introuvable.',
          'AVOCAT_NON_QUALIFIE'=>'Un avocat lié au dossier doit être qualifié.',
          'GARANT_SANS_MONTANT'=>'Un garant n\'a pas de montant garanti renseigné.',
          'ACTION_SANS_DATE'=>'Une action n\'a pas de date d\'échéance.',
          'DONNEES_FINANCIERES_INCOHERENTES'=>'Les sommes réglées dépassent la créance connue.',
        ];
      ?>
      <div class="c3-card" style="border-left:3px solid #eab308;">
        <h3>🔎 Points à vérifier <span class="cnt"><?= count($anomalies) ?></span></h3>
        <?php foreach ($anomalies as $a): ?>
          <div class="c3-alert"><?= h($anoMsg[$a['code']] ?? $a['message']) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Actions rapides -->
      <div class="c3-card">
        <h3>⚡ Actions</h3>
        <div class="c3-act">
          <?php if ($canUpload): ?><button type="button" onclick="fbxOpenUploadModal({creancier_dossier_id:<?= $idDossier ?>, soc_id:<?= (int)($dos['id_societe']??0) ?>, age_id:<?= (int)($dos['id_agence']??0) ?>, origin:'creancier'});return false;">📄 Charger un document</button><?php endif; ?>
          <?php if ($canUpload): ?><a href="<?= h($base) ?>document_request_new.php">✉️ Demander une pièce</a><?php endif; ?>
          <?php if ($canManage): ?><button type="button" onclick="if(window.acteurModalOpen_cre_acteur)window.acteurModalOpen_cre_acteur();">👤 Ajouter un intervenant</button><?php endif; ?>
          <?php if ($canMsg): ?><a href="<?= h(mail_compose_url('CREANCIER_DOSSIER', $idDossier, 'creancier_dossier360.php?id_dossier='.$idDossier)) ?>">📧 Envoyer par mail</a><?php endif; ?>
          <a href="<?= h($base) ?>creancier_dashboard.php">📊 Tableau de bord</a>
          <a href="<?= h($base) ?>creancier_liste.php">📂 Tous les dossiers</a>
        </div>
      </div>

      <!-- CONTACTS (sous la card Actions, comme demandé) -->
      <div class="c3-card">
        <h3>📇 Contacts <span class="cnt"><?= count($contactsSide) ?></span></h3>
        <?php if (!$contactsSide): ?><div class="c3-empty">Aucun contact.</div><?php endif; ?>
        <?php foreach ($contactsSide as $co): $u=$co['entity_type']==='TIERS'?$base.'tiers_360.php?id='.(int)$co['entity_id']:null; ?>
          <div class="c3-row"><span><?= $roleIcon($co['role_normalise']) ?> <?= $u?'<a href="'.h($u).'" style="color:var(--ink);text-decoration:none;font-weight:600;">'.h($co['label']).'</a>':'<strong>'.h($co['label']).'</strong>' ?> <span class="c3-tag"><?= h(creancier_role_label($co['role_stocke'])) ?></span></span><span style="color:#9a9690;font-size:10px;"><?= h(trim((string)($co['email']??'') ?: (string)($co['tel']??''))) ?></span></div>
        <?php endforeach; ?>
      </div>

      <!-- Garants (add/remove) -->
      <?php if ($canGuar): ?>
      <div class="c3-card" style="border-left:3px solid #8a5a2b;">
        <h3>🛡️ Garants <span class="cnt"><?= count($parties['garants']) ?></span></h3>
        <?php if (!$parties['garants']): ?><div class="c3-empty">Aucun garant.</div><?php endif; ?>
        <?php foreach ($parties['garants'] as $g): ?>
          <div class="c3-row"><span>👤 <strong><?= h($g['label']) ?></strong><?= $g['montant_garanti']!==null?' · <span style="color:#8a5a2b;font-weight:700;">'.$eur($g['montant_garanti']).'</span>':'' ?></span><?php if ($canManage): ?><button type="button" class="c3-btn c3-btn-d" onclick="garRemove(<?= (int)$g['entity_id'] ?>)">✕</button><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if ($canManage): ?>
        <details style="margin-top:8px;"><summary style="cursor:pointer;font-size:12px;color:var(--navy);font-weight:700;">+ Ajouter un garant</summary>
          <div style="margin-top:8px;"><?php tiers_selector_render(['id'=>'garant_picker','name'=>'id_tiers','label'=>'Garant','scope'=>'all','placeholder'=>'Rechercher un tiers…','allow_create'=>true]); ?></div>
          <div style="display:flex;gap:8px;align-items:flex-end;margin-top:8px;"><div><label style="font-size:10px;color:var(--mut);display:block;">Montant garanti €</label><input type="number" id="gar-montant" class="c3-fld" style="width:120px;"></div><button type="button" class="c3-btn c3-btn-p" onclick="garAdd()">Ajouter</button></div>
        </details>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Accès & partage (staff/manage) -->
      <?php if ($canManage): ?>
      <div class="c3-card" style="border-left:3px solid #16a34a;">
        <h3>🔗 Partage (lecture seule)</h3>
        <div style="display:flex;gap:6px;margin-bottom:8px;">
          <select id="partExpire" class="c3-fld" style="flex:1;"><option value="0">Sans expiration</option><option value="7">7 j</option><option value="30" selected>30 j</option><option value="90">90 j</option></select>
          <button type="button" id="partCreate" class="c3-btn c3-btn-p">Créer un lien</button>
        </div>
        <div id="partMsg" style="font-size:12px;"></div>
        <div id="partList">
          <?php foreach ($partages as $pg): $purl = rtrim($base,'/').'/creancier_partage.php?t='.$pg['token']; ?>
            <div class="part-row" data-pid="<?= (int)$pg['id'] ?>" style="display:flex;gap:5px;align-items:center;padding:5px 0;font-size:11px;">
              <input type="text" readonly value="<?= h($purl) ?>" onclick="this.select()" class="c3-fld" style="flex:1;font-size:10px;">
              <button type="button" class="c3-btn c3-btn-g" onclick="partCopy(this)">📋</button>
              <button type="button" class="c3-btn c3-btn-d" onclick="partRevoke(<?= (int)$pg['id'] ?>)">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="c3-card" style="border-left:3px solid #7c3aed;">
        <h3>🔐 Accès tiers</h3>
        <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;">
          <select id="accUser" class="c3-fld" style="flex:2;min-width:130px;"><option value="">— Tiers —</option><?php foreach ($candidatsTiers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(trim($c['prenom'].' '.$c['nom']) ?: $c['email']) ?></option><?php endforeach; ?></select>
          <select id="accNiveau" class="c3-fld"><option value="lecture">Lecture</option><option value="edition">Édition</option><option value="pilote">Pilote</option></select>
          <button type="button" id="accGrant" class="c3-btn c3-btn-p">OK</button>
        </div>
        <div id="accMsg" style="font-size:12px;"></div>
        <div id="accList">
          <?php foreach ($accesTiers as $a): $nomA=trim($a['prenom'].' '.$a['nom']) ?: $a['email']; ?>
            <div class="acc-row" data-uid="<?= (int)$a['id_user'] ?>" style="display:flex;gap:6px;align-items:center;padding:5px 0;font-size:12px;"><span style="flex:1;"><?= h($nomA) ?></span><span class="c3-tag"><?= h($a['niveau']) ?></span><button type="button" class="c3-btn c3-btn-d" onclick="accRevoke(<?= (int)$a['id_user'] ?>)">✕</button></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if (function_exists('fiche360_mail_history')) fiche360_mail_history($pdo, 'creancier_dossier:' . $idDossier, 'Communication', 'c3-card'); ?>
    </div><!-- /c3-side -->
  </div><!-- /c3-layout -->
</div><!-- /c3 -->

<?php if ($canManage):
  acteur_modal_render([
    'id'=>'cre_acteur','title'=>'➕ Ajouter un intervenant','role_label'=>'Rôle dans le dossier',
    'roles'=>['creancier'=>'Créancier','avocat'=>'Avocat','commissaire_justice'=>'Commissaire de justice','expert_comptable'=>'Expert-comptable','conseil'=>'Conseil','notaire'=>'Notaire','gerant'=>'Gérant','gestionnaire'=>'Gestionnaire (régie)','heritier'=>'Héritier','associe'=>'Associé','contact'=>'Contact'],
    'api_add'=>app_url('/api/creancier_contact_add.php'),'entity'=>['id_dossier'=>$idDossier],
    'role_field'=>'role_dossier','tiers_field'=>'id_tiers','csrf'=>$csrfContact,
  ]);
endif; ?>

<?= fiche360_js() ?>
<script>
(function(){
  const D=<?= (int)$idDossier ?>;
  const post=(url,body)=>fetch(url,{method:'POST',body:body,credentials:'same-origin'}).then(r=>r.json());
  const form=(obj)=>{const f=new FormData();Object.keys(obj).forEach(k=>f.append(k,obj[k]));return f;};

  // ── Qualification avocat / lien (staff) ──
  window.creQualifAvocat=function(eid,role){ if(!confirm('Qualifier cet avocat ?')) return;
    post('api/creancier_lien_action.php',form({action:'set_role',id_dossier:D,entity_type:'TIERS',entity_id:eid,role_actuel:'avocat',role_cible:role,csrf_token:<?= json_encode($csrfLien) ?>}))
      .then(j=>j.ok?location.reload():alert('✗ '+(j.error||'échec'))).catch(()=>alert('Erreur réseau')); };
  window.creSetLien=function(etype,eid,rold,rnew){ if(!confirm('Préciser ce rôle ?')) return;
    post('api/creancier_lien_action.php',form({action:'set_role',id_dossier:D,entity_type:etype,entity_id:eid,role_actuel:rold,role_cible:rnew,csrf_token:<?= json_encode($csrfLien) ?>}))
      .then(j=>j.ok?location.reload():alert('✗ '+(j.error||'échec'))).catch(()=>alert('Erreur réseau')); };

  // ── Chat IA ──
  const box=document.getElementById('creChatBox'), input=document.getElementById('creChatInput'), btn=document.getElementById('creChatSend');
  function bubble(t,me){const e=document.getElementById('creChatEmpty');if(e)e.remove();const d=document.createElement('div');d.style.cssText='max-width:85%;padding:7px 11px;border-radius:11px;font-size:12px;white-space:pre-line;'+(me?'align-self:flex-end;background:#243B5C;color:#fff;':'align-self:flex-start;background:#eef1f6;color:#243B5C;');d.textContent=t;box.appendChild(d);box.scrollTop=box.scrollHeight;return d;}
  async function send(){const msg=(input.value||'').trim();if(!msg)return;bubble(msg,true);input.value='';btn.disabled=true;const w=bubble('…',false);
    try{const j=await post('api/creancier_chat_post.php',form({id_dossier:D,message:msg,csrf_token:<?= json_encode($csrfChat) ?>}));w.textContent=j.ok?j.reply:('Erreur : '+(j.error||'échec'));}
    catch(e){w.textContent='Erreur réseau';}finally{btn.disabled=false;box.scrollTop=box.scrollHeight;}}
  if(btn){btn.addEventListener('click',send);input.addEventListener('keydown',e=>{if(e.key==='Enter')send();});box.scrollTop=box.scrollHeight;}

  // ── Fil d'actualité ──
  const feedBtn=document.getElementById('feedSend'), feedIn=document.getElementById('feedInput');
  if(feedBtn) feedBtn.addEventListener('click', async function(){
    const msg=(feedIn.value||'').trim(); const out=document.getElementById('feedMsg');
    if(!msg){out.style.color='#dc2626';out.textContent='Écris un commentaire.';return;}
    feedBtn.disabled=true;out.textContent='Publication…';
    try{const j=await post('api/creancier_feed_post.php',form({id_dossier:D,message:msg,csrf_token:<?= json_encode($csrfFeed) ?>}));
      if(j.ok){const em=document.getElementById('feedEmpty');if(em)em.remove();const esc=s=>String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));const div=document.createElement('div');div.style.cssText='padding:8px 0;border-bottom:1px solid #f2eee7;';div.innerHTML='<div style="font-size:11px;color:#8a8680;"><strong style="color:#243B5C;">'+esc(j.auteur)+'</strong> · '+esc(j.date)+'</div><div style="font-size:12.5px;white-space:pre-line;color:#3a3830;">'+esc(j.message)+'</div>';const l=document.getElementById('feedList');l.insertBefore(div,l.firstChild);feedIn.value='';out.textContent='';}
      else{out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');}
    }catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';}finally{feedBtn.disabled=false;}
  });

  // ── Notes perso ──
  const noteBtn=document.getElementById('noteSend'), noteIn=document.getElementById('noteInput');
  if(noteBtn) noteBtn.addEventListener('click', async function(){
    const msg=(noteIn.value||'').trim(); const out=document.getElementById('noteMsg');
    if(!msg){out.style.color='#dc2626';out.textContent='Écris une note.';return;}
    noteBtn.disabled=true;out.textContent='Enregistrement…';
    try{const j=await post('api/creancier_note_post.php',form({action:'add',id_dossier:D,message:msg,csrf_token:<?= json_encode($csrfNote) ?>}));
      if(j.ok){const em=document.getElementById('noteEmpty');if(em)em.remove();const esc=s=>String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));const div=document.createElement('div');div.style.cssText='padding:8px 0;border-bottom:1px solid #f2eee7;';div.setAttribute('data-note-id',j.id);div.innerHTML='<div style="font-size:11px;color:#8a8680;display:flex;justify-content:space-between;"><span>'+esc(j.date)+'</span><button type="button" class="noteDel" data-id="'+j.id+'" style="border:none;background:none;color:#a85858;cursor:pointer;font-size:11px;">supprimer</button></div><div style="font-size:12.5px;white-space:pre-line;color:#3a3830;">'+esc(j.message)+'</div>';const l=document.getElementById('noteList');l.insertBefore(div,l.firstChild);noteIn.value='';out.textContent='';}
      else{out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');}
    }catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';}finally{noteBtn.disabled=false;}
  });
  const noteListEl=document.getElementById('noteList');
  if(noteListEl) noteListEl.addEventListener('click', async function(e){
    const del=e.target.closest('.noteDel'); if(!del) return; if(!confirm('Supprimer cette note ?')) return;
    try{const j=await post('api/creancier_note_post.php',form({action:'delete',note_id:del.dataset.id,csrf_token:<?= json_encode($csrfNote) ?>}));
      if(j.ok){const row=del.closest('[data-note-id]');if(row)row.remove();}}catch(e){}
  });

  // ── Item (action/échéance) ──
  const evBtn=document.getElementById('evAdd');
  if(evBtn) evBtn.addEventListener('click', async function(){
    const out=document.getElementById('evMsg'); const titre=(document.getElementById('evTitre').value||'').trim();
    if(!titre){out.style.color='#dc2626';out.textContent='Intitulé requis.';return;}
    evBtn.disabled=true;out.textContent='Ajout…';
    try{const j=await post('api/creancier_item_action.php',form({action:'add',id_dossier:D,csrf_token:<?= json_encode($csrfItem) ?>,type:document.getElementById('evType').value,titre:titre,date_echeance:document.getElementById('evDate').value}));
      if(j.ok)location.reload();else{out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');evBtn.disabled=false;}
    }catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';evBtn.disabled=false;}
  });

  // ── Mouvements ──
  const mvBtn=document.getElementById('mvAdd');
  if(mvBtn) mvBtn.addEventListener('click', async function(){
    const out=document.getElementById('mvMsg'); const montant=(document.getElementById('mvMontant').value||'').trim();
    if(!montant){out.style.color='#dc2626';out.textContent='Montant requis.';return;}
    mvBtn.disabled=true;out.textContent='Enregistrement…';
    try{const j=await post('api/creancier_mouvement_action.php',form({action:'add',id_dossier:D,csrf_token:<?= json_encode($csrfMouvement) ?>,type:document.getElementById('mvType').value,montant:montant,date_mouvement:document.getElementById('mvDate').value,reference:document.getElementById('mvRef').value,note:document.getElementById('mvNote').value}));
      if(j.ok)location.reload();else{out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');mvBtn.disabled=false;}
    }catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';mvBtn.disabled=false;}
  });
  window.mvDelete=async function(mid){ if(!confirm('Supprimer ce mouvement ?'))return;
    try{const j=await post('api/creancier_mouvement_action.php',form({action:'delete',id_dossier:D,mouvement_id:mid,csrf_token:<?= json_encode($csrfMouvement) ?>}));
      if(j.ok)location.reload();else alert('✗ '+(j.error||'échec'));}catch(e){alert('✗ réseau');} };

  // ── Frais IA ──
  const MVLBL={versement_creancier:'Versement créancier',honoraire_avocat:'Honoraires avocat',frais_huissier:'Frais huissier',frais_procedure:'Frais procédure',autre:'Autre'};
  const fraisBtn=document.getElementById('fraisAnalyse');
  if(fraisBtn) fraisBtn.addEventListener('click', async function(){
    const f=document.getElementById('fraisFile').files[0]; const out=document.getElementById('fraisMsg'); const res=document.getElementById('fraisResult');
    if(!f){out.style.color='#dc2626';out.textContent='Choisis un PDF.';return;}
    fraisBtn.disabled=true;out.textContent='🧠 Analyse… (10-20s)';res.innerHTML='';
    try{const j=await post('api/creancier_frais_extract.php',form({doc:f,id_dossier:D,csrf_token:<?= json_encode($csrfFrais) ?>}));
      if(!j.ok){out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');fraisBtn.disabled=false;return;}
      out.style.color='#166534';out.textContent='✓ '+j.count+' ligne(s)';
      if(!j.count){res.innerHTML='<div class="c3-empty">Aucune ligne détectée.</div>';fraisBtn.disabled=false;return;}
      const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
      let html='';j.lignes.forEach(function(l,i){let opts='';Object.keys(MVLBL).forEach(k=>opts+='<option value="'+k+'"'+(k===l.type?' selected':'')+'>'+MVLBL[k]+'</option>');
        html+='<div class="frais-line" data-i="'+i+'" style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f2eee7;font-size:12px;"><input type="checkbox" class="frais-cb" checked><select class="frais-type c3-fld" style="padding:3px 5px;">'+opts+'</select><span style="flex:1;">'+esc(l.libelle||'—')+(l.date?' · '+esc(l.date):'')+'</span><strong>'+Number(l.montant).toLocaleString('fr-FR')+' €</strong></div>';});
      html+='<div style="margin-top:8px;"><button type="button" id="fraisCommit" class="c3-btn c3-btn-p">➕ Ajouter les lignes cochées</button> <span id="fraisCommitMsg" style="font-size:12px;"></span></div>';
      res.innerHTML=html;window.__fraisLignes=j.lignes;
      document.getElementById('fraisCommit').addEventListener('click', async function(){
        const cm=document.getElementById('fraisCommitMsg');const rows=res.querySelectorAll('.frais-line');const arr=[];
        rows.forEach(function(row){const cb=row.querySelector('.frais-cb');if(!cb.checked)return;const i=parseInt(row.dataset.i,10);const l=Object.assign({},window.__fraisLignes[i]);l.type=row.querySelector('.frais-type').value;arr.push(l);});
        if(!arr.length){cm.style.color='#dc2626';cm.textContent='Coche au moins une ligne.';return;}
        this.disabled=true;cm.textContent='Enregistrement…';
        try{const j2=await post('api/creancier_mouvement_action.php',form({action:'add_bulk',id_dossier:D,csrf_token:<?= json_encode($csrfMouvement) ?>,lignes:JSON.stringify(arr)}));
          if(j2.ok)location.reload();else{cm.style.color='#dc2626';cm.textContent='✗ '+(j2.error||'échec');}}catch(e){cm.style.color='#dc2626';cm.textContent='✗ réseau';}
      });
    }catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';}finally{fraisBtn.disabled=false;}
  });

  // ── Échéancier ──
  window.echGenerer=function(){const m=document.getElementById('ech-m-montant').value,d=document.getElementById('ech-m-date').value,n=document.getElementById('ech-m-nb').value;if(!m||!d){alert('Mensualité + 1re date requises');return;}post('api/creancier_echeancier_save.php',form({action:'generer',id_dossier:D,montant:m,date_debut:d,nb_mois:n,csrf_token:<?= json_encode($csrfEch) ?>})).then(j=>j.ok?location.reload():alert(j.error||'Échec'));};
  window.echAjouter=function(){const m=document.getElementById('ech-a-montant').value,d=document.getElementById('ech-a-date').value;if(!m||!d){alert('Montant + date requis');return;}post('api/creancier_echeancier_save.php',form({action:'ajouter',id_dossier:D,montant:m,date:d,type:'acompte',csrf_token:<?= json_encode($csrfEch) ?>})).then(j=>j.ok?location.reload():alert(j.error||'Échec'));};
  window.echStatut=function(id,s){post('api/creancier_echeancier_save.php',form({action:'statut',id:id,statut:s,csrf_token:<?= json_encode($csrfEch) ?>})).then(j=>j.ok?location.reload():alert(j.error||'Échec'));};
  window.echSuppr=function(id){if(confirm('Supprimer cette échéance ?'))post('api/creancier_echeancier_save.php',form({action:'supprimer',id:id,csrf_token:<?= json_encode($csrfEch) ?>})).then(j=>j.ok?location.reload():alert(j.error||'Échec'));};

  // ── Garant ──
  window.garAdd=function(){const root=document.querySelector('[data-ts-root="garant_picker"]');const tid=root?root.querySelector('.ts-value').value:'';const m=document.getElementById('gar-montant').value;if(!tid){alert('Choisissez un tiers garant');return;}post('api/creancier_garant_save.php',form({action:'add',id_dossier:D,id_tiers:tid,montant:m||0,csrf_token:<?= json_encode($csrfGarant) ?>})).then(j=>j.ok?location.reload():alert(j.error||'Échec'));};
  window.garRemove=function(tid){if(confirm('Retirer ce garant ?'))post('api/creancier_garant_save.php',form({action:'remove',id_dossier:D,id_tiers:tid,csrf_token:<?= json_encode($csrfGarant) ?>})).then(j=>j.ok?location.reload():alert(j.error||'Échec'));};

  // ── Analyse IA ──
  const gen=document.getElementById('genAnalyse');
  if(gen) gen.addEventListener('click', async function(){ gen.disabled=true;const t=gen.textContent;gen.textContent='⏳…';const ab=document.getElementById('analyseBox');ab.textContent='🧠 Analyse (10-20s)…';
    try{const j=await post('api/creancier_analyse_generate.php',form({id_dossier:D,csrf_token:<?= json_encode($csrfAnalyse) ?>}));ab.textContent=j.ok?'✓ Généré — rechargez.':('✗ '+(j.error||'échec'));if(j.ok)location.reload();}
    catch(e){ab.textContent='✗ réseau';}finally{gen.disabled=false;gen.textContent=t;} });

  // ── Partage ──
  const partBtn=document.getElementById('partCreate');
  if(partBtn) partBtn.addEventListener('click', async function(){const out=document.getElementById('partMsg');partBtn.disabled=true;out.textContent='Création…';
    try{const j=await post('api/creancier_partage_action.php',form({action:'create',id_dossier:D,expires_days:document.getElementById('partExpire').value,csrf_token:<?= json_encode($csrfPartage) ?>}));if(j.ok)location.reload();else{out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');partBtn.disabled=false;}}catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';partBtn.disabled=false;} });
  window.partCopy=function(btn){const inp=btn.parentElement.querySelector('input');inp.select();try{document.execCommand('copy');btn.textContent='✓';}catch(e){}setTimeout(()=>btn.textContent='📋',1200);};
  window.partRevoke=async function(pid){if(!confirm('Révoquer ce lien ?'))return;try{const j=await post('api/creancier_partage_action.php',form({action:'revoke',id_dossier:D,partage_id:pid,csrf_token:<?= json_encode($csrfPartage) ?>}));if(j.ok){const r=document.querySelector('.part-row[data-pid="'+pid+'"]');if(r)r.remove();}else alert('✗ '+(j.error||'échec'));}catch(e){alert('✗ réseau');}};

  // ── Accès tiers ──
  const accBtn=document.getElementById('accGrant');
  if(accBtn) accBtn.addEventListener('click', async function(){const out=document.getElementById('accMsg');const uid=document.getElementById('accUser').value;if(!uid){out.style.color='#dc2626';out.textContent='Choisissez un tiers.';return;}accBtn.disabled=true;out.textContent='…';
    try{const j=await post('api/creancier_acces_action.php',form({action:'grant',id_dossier:D,id_user:uid,niveau:document.getElementById('accNiveau').value,csrf_token:<?= json_encode($csrfAcces) ?>}));if(j.ok)location.reload();else{out.style.color='#dc2626';out.textContent='✗ '+(j.error||'échec');accBtn.disabled=false;}}catch(e){out.style.color='#dc2626';out.textContent='✗ réseau';accBtn.disabled=false;} });
  window.accRevoke=async function(uid){if(!confirm('Retirer cet accès ?'))return;try{const j=await post('api/creancier_acces_action.php',form({action:'revoke',id_dossier:D,id_user:uid,csrf_token:<?= json_encode($csrfAcces) ?>}));if(j.ok){const r=document.querySelector('.acc-row[data-uid="'+uid+'"]');if(r)r.remove();}else alert('✗ '+(j.error||'échec'));}catch(e){alert('✗ réseau');}};
})();
</script>
<?php if ($canManage) { tiers_selector_assets(); require __DIR__ . '/inc/fluxbox_upload_modal.php'; } ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
