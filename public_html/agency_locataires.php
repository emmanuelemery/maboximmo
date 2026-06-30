<?php
/**
 * agency_locataires.php — Liste des LOCATAIRES (même modèle visuel que agency_proprietaires).
 *
 * Les locataires ne sont pas des tiers : ils sont portés par bien_baux (locataire_nom /
 * raison_sociale). On les agrège donc depuis les baux, groupés par locataire, avec les
 * biens loués. Card → locataire_360.php?loc=<nom>&id_bien=<bien>.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/entity_card.php';
require_login();

if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$pdo     = $GLOBALS['pdo'];
$roleId  = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1 || $roleId === 7) || (function_exists('is_super_admin') && is_super_admin());

$mySoc    = (int)($_SESSION['id_societe'] ?? 0);
$scopeSoc = isset($_GET['societe']) && ctype_digit((string)$_GET['societe']) ? (int)$_GET['societe'] : ($isAdmin ? 0 : $mySoc);
$scopeAg  = isset($_GET['agence'])  && ctype_digit((string)$_GET['agence'])  ? (int)$_GET['agence']  : 0;
$filterStatut = (string)($_GET['statut'] ?? 'actif');   // actif | tous

$conds = ['1=1']; $params = [];
if ($scopeSoc > 0) { $conds[] = 'b.id_societe = ?'; $params[] = $scopeSoc; }
if ($scopeAg  > 0) { $conds[] = 'b.id_agence = ?';  $params[] = $scopeAg;  }
if ($filterStatut === 'actif') { $conds[] = "bb.statut = 'actif'"; }

$baux = [];
try {
    $sql = "SELECT bb.id AS bail_id, bb.id_bien, bb.statut, bb.loyer_mensuel_hc, bb.id_tiers_locataire,
                   bb.locataire_raison_sociale, bb.locataire_nom, bb.locataire_prenom,
                   bb.locataire_email, bb.locataire_telephone,
                   b.reference_bien,
                   COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
                   COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
                   i.nom_immeuble
            FROM bien_baux bb
            JOIN biens b      ON b.id = bb.id_bien
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY bb.id DESC LIMIT 5000";
    $st = $pdo->prepare($sql); $st->execute($params);
    $baux = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ex) { error_log('[agency_locataires] ' . $ex->getMessage()); $baux = []; }

// ── Regroupement par locataire ──
$norm = static function (string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return preg_replace('/[^A-Z0-9]+/u', '', strtr($s, ['À'=>'A','Â'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U'])) ?? '';
};
$locataires = [];
foreach ($baux as $r) {
    $nom = trim((string)($r['locataire_raison_sociale'] ?? '')) ?: trim((string)($r['locataire_nom'] ?? '') . ' ' . (string)($r['locataire_prenom'] ?? ''));
    $nom = trim(preg_replace('/\s+/', ' ', $nom) ?? '');
    if ($nom === '') continue;
    $idT = (int)($r['id_tiers_locataire'] ?? 0);
    $key = $idT > 0 ? 'T:' . $idT : 'N:' . $norm($nom);
    if (!isset($locataires[$key])) {
        $locataires[$key] = ['nom' => $nom, 'id_tiers' => $idT, 'email' => $r['locataire_email'] ?: null,
                             'tel' => $r['locataire_telephone'] ?: null, 'baux' => []];
    }
    if (!$locataires[$key]['email'] && $r['locataire_email']) $locataires[$key]['email'] = $r['locataire_email'];
    if (!$locataires[$key]['tel'] && $r['locataire_telephone']) $locataires[$key]['tel'] = $r['locataire_telephone'];
    $locataires[$key]['baux'][] = $r;
}
// Tri alpha
uasort($locataires, fn($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));

$kpiLoc  = count($locataires);
$kpiBaux = count($baux);

// Sélecteurs scope
$societesOpt = []; $agencesOpt = [];
try {
    $societesOpt = $pdo->query("SELECT DISTINCT s.id, s.nom FROM biens b JOIN societes s ON s.id=b.id_societe ORDER BY s.nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $agencesOpt  = $pdo->query("SELECT DISTINCT a.id, a.nom_agence, a.ville, a.id_societe FROM biens b JOIN agences a ON a.id=b.id_agence ORDER BY a.nom_agence")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$appLayout = true; $pageTitle = 'Locataires'; $bodyClass = ''; $robots = 'noindex, nofollow';
include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  .mbi-main{ background:linear-gradient(135deg, rgba(45,95,107,0.12) 0%, rgba(255,255,255,0) 45%, rgba(72,120,166,0.10) 70%, rgba(255,255,255,0) 100%), #fafbfc; background-attachment:fixed; }
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:#fff; border-radius:14px; padding:10px 16px; min-width:96px; box-shadow:4px 4px 10px #d4d7de,-4px -4px 10px #fff; text-align:center; }
  .pk-kpi-val { font-size:22px; font-weight:800; color:#243B5C; line-height:1.1; }
  .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; margin-top:3px; }
  .pk-bar { position:sticky; top:0; z-index:50; display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding:10px 2px; margin-bottom:14px; background:rgba(250,251,252,.92); backdrop-filter:blur(6px); }
  .pk-bar-search { width:100%; max-width:300px; display:flex; align-items:center; gap:8px; background:#fff; border-radius:999px; padding:8px 16px; box-shadow:3px 3px 8px #d4d7de,-3px -3px 8px #fff; }
  .pk-bar-search input { border:none !important; outline:none !important; background:transparent !important; width:100%; font-family:'Sora',sans-serif; font-size:15px; font-weight:700; color:#243B5C; }
  .pk-sel { display:flex; gap:7px; align-items:center; flex-wrap:wrap; font-size:12.5px; }
  .pk-sbtn { text-decoration:none; padding:6px 13px; border-radius:10px; font-weight:700; background:#fff; color:#243B5C; box-shadow:3px 3px 8px #d4d7de,-3px -3px 8px #fff; }
  .pk-sbtn.active { background:#2d5f6b; color:#fff; }
</style>

<div class="mbi-main">
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span class="active" style="font-size:1.7rem;font-weight:800;">Locataires</span></nav>
    <div class="topbar-spacer"></div>
    <a href="<?= e(app_url('/agency_proprietaires.php')) ?>" class="bl-btn" style="background:#fef3e2;color:#c97b2e;border:1px solid #f3d2a6;font-weight:700;text-decoration:none;margin-right:10px;">👤 Propriétaires</a>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <div class="page-head" style="flex-wrap:wrap;gap:16px;align-items:center;">
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= number_format($kpiLoc, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Locataires</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#2d5f6b;"><?= number_format($kpiBaux, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Baux<?= $filterStatut === 'actif' ? ' actifs' : '' ?></div></div>
    </div>
    <div class="pk-sel">
      <?php $f = $filterStatut !== 'actif' ? ['statut'=>$filterStatut] : []; ?>
      <span style="font-weight:700;color:#8a8680;">Société :</span>
      <a class="pk-sbtn <?= $scopeSoc<=0?'active':'' ?>" href="?<?= e(http_build_query($f)) ?>">Toutes</a>
      <?php foreach ($societesOpt as $s): ?>
        <a class="pk-sbtn <?= $scopeSoc===(int)$s['id']?'active':'' ?>" href="?<?= e(http_build_query($f + ['societe'=>(int)$s['id']])) ?>"><?= e($s['nom']) ?></a>
      <?php endforeach; ?>
      <?php if ($agencesOpt): ?>
        <span style="font-weight:700;color:#8a8680;margin-left:8px;">Agence :</span>
        <a class="pk-sbtn <?= $scopeAg<=0?'active':'' ?>" href="?<?= e(http_build_query($f + ($scopeSoc>0?['societe'=>$scopeSoc]:[]))) ?>">Toutes</a>
        <?php foreach ($agencesOpt as $a): if ($scopeSoc>0 && (int)$a['id_societe']!==$scopeSoc) continue; ?>
          <a class="pk-sbtn <?= $scopeAg===(int)$a['id']?'active':'' ?>" href="?<?= e(http_build_query($f + ($scopeSoc>0?['societe'=>$scopeSoc]:[]) + ['agence'=>(int)$a['id']])) ?>"><?= e($a['ville'] ?: $a['nom_agence']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <a class="pk-sbtn <?= $filterStatut==='tous'?'active':'' ?>" style="margin-left:8px;" href="?<?= e(http_build_query(array_filter(['societe'=>$scopeSoc?:null,'agence'=>$scopeAg?:null,'statut'=>$filterStatut==='tous'?null:'tous']))) ?>"><?= $filterStatut==='tous'?'✓ Tous baux':'Inclure baux inactifs' ?></a>
    </div>
  </div>

  <div class="bl-content">
    <?php if (!$locataires): ?>
      <div class="bl-empty"><div class="bl-empty-icon">🔑</div><h2>Aucun locataire</h2><p>Aucun bail dans ce périmètre.</p></div>
    <?php else: ?>
      <?php entity_card_assets(); ?>
      <div class="pk-bar">
        <div class="pk-bar-search"><span>🔍</span><input type="text" id="locSearch" placeholder="Rechercher un locataire…" oninput="locFilter()" autocomplete="off" autofocus></div>
      </div>
      <div class="ec-grid" id="locGrid">
        <?php foreach ($locataires as $L):
            $nb = count($L['baux']);
            $first = $L['baux'][0];
            // Contexte AGENCE : tiers 360° moderne si le locataire est rattaché à un tiers,
            // sinon la fiche bien (qui montre le bail + locataire).
            // NB : on n'envoie PAS vers locataire_360.php (page du module Bailleur, sidebar bailleur).
            $idTiersLoc = (int)($L['id_tiers'] ?? 0);
            $url = $idTiersLoc > 0
                ? app_url('/tiers_360.php?id=' . $idTiersLoc)
                : app_url('/bien_360.php?id=' . (int)$first['id_bien']);
            $loyerTot = array_sum(array_map(fn($b) => (float)($b['loyer_mensuel_hc'] ?? 0), $L['baux']));

            $chips = [];
            if ($nb === 1) {
                $adr = trim((string)($first['adresse'] ?? '') . ' ' . (string)($first['ville'] ?? ''));
                $chips[] = '🏠 ' . e($first['reference_bien'] ?: '#' . $first['id_bien']) . ($adr ? ' · <span style="color:#4878a6;">' . e($adr) . '</span>' : '');
            } else {
                $chips[] = '🏠 <strong>' . $nb . '</strong>&nbsp;biens loués';
            }
            if ($loyerTot > 0) $chips[] = '💶 ' . number_format($loyerTot, 0, ',', ' ') . ' €/mois';

            $actions = [];
            if ($L['tel'])   { $tel = preg_replace('/[^0-9+]/', '', (string)$L['tel']); $actions[] = '<a class="ec-abtn" href="tel:' . e($tel) . '" title="Appeler">📞</a>'; }
            if ($L['email']) { $actions[] = '<a class="ec-abtn" href="mailto:' . e($L['email']) . '" title="Écrire">✉️</a>'; }

            $nomLetter = $L['nom']; $fl = mb_strtoupper(mb_substr($nomLetter, 0, 1, 'UTF-8'), 'UTF-8');
            $fl = strtr($fl, ['À'=>'A','Â'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U']);
            if (!preg_match('/[A-Z]/', $fl)) $fl = '#';

            entity_card([
                'accent'  => '#2d5f6b',
                'url'     => $url,
                'ref'     => $L['id_tiers'] > 0 ? ('Tiers #' . $L['id_tiers']) : ($nb . ' bail' . ($nb > 1 ? 'x' : '')),
                'title'   => $L['nom'],
                'chips'   => $chips,
                'actions' => $actions,
                'data'    => ['name' => mb_strtolower($L['nom'], 'UTF-8'), 'letter' => $fl],
            ]);
        endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function locFilter(){
  var q = (document.getElementById('locSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('#locGrid .ec-card').forEach(function(c){
    c.style.display = (!q || (c.dataset.name||'').indexOf(q)!==-1) ? '' : 'none';
  });
}
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
