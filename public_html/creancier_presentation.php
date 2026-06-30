<?php
/**
 * creancier_presentation.php — Présentation / démo du module CRÉANCIERS.
 *
 * Page vitrine interne : explique la valeur du module, ses fonctions et affiche
 * des chiffres en direct sur le périmètre accessible à l'utilisateur. Chrome standard.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_once __DIR__ . '/inc/creancier_mouvement.php';
require_login();

if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$base   = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';

// ── Chiffres en direct sur le périmètre accessible ──
$dossiers = creancier_accessible_dossiers($pdo, $userId);
$ids = array_map(fn($d) => (int)$d['id'], $dossiers);
$kDette = 0.0; $kDecaisse = 0.0; $kTiers = 0; $kDocs = 0; $kFeed = 0;
foreach ($dossiers as $d) {
    $u = creancier_urgence_data($pdo, (int)$d['id'], $userId);
    if (!$u['acces']) continue;
    $kDette += $u['montant_du'] ?? 0;
    $mv = creancier_mouvements($pdo, (int)$d['id']);
    $kDecaisse += $mv['total_general'];
}
if ($ids) {
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stT = $pdo->prepare("SELECT COUNT(DISTINCT entity_id) FROM creancier_dossier_lien WHERE entity_type='TIERS' AND role_dossier IN ('debiteur','debiteur_solidaire','groupe') AND id_dossier IN ($in)");
        $stT->execute($ids); $kTiers = (int)$stT->fetchColumn();
        $stF = $pdo->prepare("SELECT COUNT(*) FROM creancier_dossier_message WHERE canal='feed' AND id_dossier IN ($in)");
        $stF->execute($ids); $kFeed = (int)$stF->fetchColumn();
    } catch (Throwable $ex) { /* best-effort */ }
}

$appLayout = true; $pageTitle = 'Créanciers — présentation'; $bodyClass = ''; $robots = 'noindex, nofollow';
include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  .mbi-main{ background:linear-gradient(135deg, rgba(36,59,92,0.10) 0%, rgba(255,255,255,0) 40%, rgba(212,160,71,0.12) 70%, rgba(255,255,255,0) 100%), #fafbfc; background-attachment:fixed; }
  .cp-wrap { max-width:1000px; margin:0 auto; padding:8px 16px 60px; }
  .cp-hero { background:linear-gradient(135deg,#243B5C,#1a2940); color:#fff; border-radius:18px; padding:30px 32px; margin-bottom:20px; box-shadow:0 10px 30px rgba(36,59,92,.25); }
  .cp-hero h1 { margin:0 0 8px; font-size:28px; }
  .cp-hero p { margin:0; opacity:.85; font-size:15px; max-width:680px; line-height:1.5; }
  .cp-hero .cp-cta { margin-top:18px; display:flex; gap:10px; flex-wrap:wrap; }
  .cp-btn { text-decoration:none; font-weight:700; font-size:13.5px; padding:10px 18px; border-radius:10px; display:inline-flex; align-items:center; gap:6px; }
  .cp-btn-or { background:#D4A047; color:#1a2940; }
  .cp-btn-gh { background:rgba(255,255,255,.14); color:#fff; }
  .cp-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:22px; }
  .cp-kpi { background:#fff; border-radius:14px; padding:16px; text-align:center; box-shadow:4px 4px 10px #e3e6ec,-4px -4px 10px #fff; }
  .cp-kpi-val { font-size:24px; font-weight:800; color:#243B5C; }
  .cp-kpi-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#8a8680; margin-top:4px; }
  .cp-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
  .cp-feat { background:#fff; border:1px solid #ece7df; border-radius:14px; padding:18px 20px; }
  .cp-feat .ico { font-size:24px; }
  .cp-feat h3 { margin:8px 0 6px; font-size:15px; color:#243B5C; }
  .cp-feat p { margin:0; font-size:13px; color:#5b5750; line-height:1.5; }
  .cp-steps { background:#fff; border:1px solid #ece7df; border-radius:14px; padding:18px 22px; margin:20px 0; }
  .cp-steps h2 { font-size:15px; color:#243B5C; margin:0 0 14px; }
  .cp-step { display:flex; gap:12px; align-items:flex-start; padding:8px 0; }
  .cp-step .n { flex:0 0 26px; height:26px; border-radius:50%; background:#243B5C; color:#fff; font-weight:800; font-size:13px; display:flex; align-items:center; justify-content:center; }
  .cp-step .t { font-size:13.5px; color:#3a3830; }
  .cp-note { font-size:12px; color:#8a8680; margin-top:18px; text-align:center; font-style:italic; }
</style>

<div class="mbi-main">
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span class="active" style="font-size:1.7rem;font-weight:800;">Créanciers — présentation</span></nav>
    <div class="topbar-spacer"></div>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <div class="cp-wrap">

    <div class="cp-hero">
      <h1>⚖️ Module Créanciers</h1>
      <p>Le cockpit de pilotage des contentieux de recouvrement et des saisies. Un dossier par débiteur,
         qui agrège tout l'existant (tiers, biens, documents) sans rien ressaisir : procédures, créanciers,
         intervenants, échéances, mouvements financiers et fil d'actualité — partageable en un clic.</p>
      <div class="cp-cta">
        <a class="cp-btn cp-btn-or" href="<?= e($base) ?>creancier_dashboard.php">📊 Ouvrir le dashboard</a>
        <a class="cp-btn cp-btn-gh" href="<?= e($base) ?>creancier_liste.php">📂 Tous les dossiers</a>
      </div>
    </div>

    <div class="cp-kpis">
      <div class="cp-kpi"><div class="cp-kpi-val"><?= count($dossiers) ?></div><div class="cp-kpi-lbl">Dossiers</div></div>
      <div class="cp-kpi"><div class="cp-kpi-val" style="color:#dc2626;"><?= $eur($kDette) ?></div><div class="cp-kpi-lbl">Reste dû</div></div>
      <div class="cp-kpi"><div class="cp-kpi-val"><?= $eur($kDecaisse) ?></div><div class="cp-kpi-lbl">Décaissé suivi</div></div>
      <div class="cp-kpi"><div class="cp-kpi-val"><?= $kTiers ?></div><div class="cp-kpi-lbl">Tiers concernés</div></div>
      <div class="cp-kpi"><div class="cp-kpi-val"><?= $kFeed ?></div><div class="cp-kpi-lbl">Messages du fil</div></div>
    </div>

    <div class="cp-grid">
      <div class="cp-feat"><div class="ico">🏛️</div><h3>Un dossier = un débiteur</h3><p>Tous les créanciers, saisies, avocats et huissiers d'un même groupe débiteur réunis. Liens croisés vers les fiches tiers / propriétaires existantes.</p></div>
      <div class="cp-feat"><div class="ico">📣</div><h3>Fil d'actualité partagé</h3><p>Les intervenants ajoutent des commentaires signés et horodatés. Tout le monde voit la même chronologie, y compris via le lien de partage.</p></div>
      <div class="cp-feat"><div class="ico">📅</div><h3>Agenda des échéances</h3><p>Audiences, délais de contestation, butoirs : ajoutés en deux clics, remontés automatiquement dans l'agenda du dossier et du dashboard.</p></div>
      <div class="cp-feat"><div class="ico">💶</div><h3>Mouvements financiers</h3><p>Versements au créancier, honoraires d'avocat, frais d'huissier et de procédure dans un registre unique, avec totaux par poste.</p></div>
      <div class="cp-feat"><div class="ico">🧠</div><h3>Extraction IA des frais</h3><p>Dépose un décompte d'huissier ou un jugement : l'IA isole les lignes de frais à enregistrer, tu valides ce qui est juste.</p></div>
      <div class="cp-feat"><div class="ico">🔗</div><h3>Partage lecture seule</h3><p>Un lien public sécurisé (expiration, révocation) pour transmettre le dossier complet à un avocat ou un mandant, sans accès au reste.</p></div>
    </div>

    <div class="cp-steps">
      <h2>Le déroulé type d'un dossier</h2>
      <div class="cp-step"><div class="n">1</div><div class="t"><b>Création</b> — le dossier débiteur naît du premier acte scanné, ou manuellement. Les créanciers et intervenants se rattachent comme rôles sur des tiers existants.</div></div>
      <div class="cp-step"><div class="n">2</div><div class="t"><b>Documents &amp; IA</b> — chaque pièce (commandement, jugement, décompte) est analysée : créancier, montants, dates et frais extraits puis validés.</div></div>
      <div class="cp-step"><div class="n">3</div><div class="t"><b>Pilotage</b> — échéances dans l'agenda, mouvements financiers suivis, fil d'actualité alimenté par tous les intervenants.</div></div>
      <div class="cp-step"><div class="n">4</div><div class="t"><b>Partage</b> — le dossier complet se transmet en lecture seule via un lien révocable, sans rien dupliquer.</div></div>
    </div>

    <div class="cp-note">Chiffres calculés en direct sur les dossiers auxquels vous avez accès. Module confidentiel — visibilité par habilitation.</div>
  </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
