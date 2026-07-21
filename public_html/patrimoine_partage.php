<?php
/**
 * patrimoine_partage.php — Page PUBLIQUE d'accès au patrimoine (lecture seule, à jeton).
 *
 * SANS login. Accès garanti par patrimoine_partages.token (imprévisible), avec
 * expiration, révocation et consentement au 1er accès (pattern p.php).
 * Périmètre = user_proprietaires du COMPTE BAILLEUR rattaché au partage.
 * Colonnes affichées selon les flags du partage (descriptif / locataire / loyer / prix vente).
 * Le tiers choisit le scénario de prix parmi ceux partagés.
 *
 * Modes :
 *   ?t=TOKEN            → accès public tiers (jeton + consentement)
 *   ?preview=ID         → APERÇU staff (rendu live, sans jeton ni consentement)
 *   &scenario=CODE      → scénario sélectionné (doit être dans la liste partagée)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/patrimoine_base.php';

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$e   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// Donnée privée : jamais indexable + pas de fuite du jeton via le Referer.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Referrer-Policy: no-referrer');

/** Page d'erreur sobre. */
function pp_stop(string $titre, string $msg): void {
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($titre) . '</title>'
       . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#10254d;color:#fff;display:grid;place-items:center;height:100vh;margin:0;}'
       . '.c{max-width:460px;text-align:center;padding:30px;}h1{font-size:22px;margin:0 0 10px;}p{color:#aebfd8;line-height:1.6;}</style></head>'
       . '<body><div class="c"><div style="font-size:46px;margin-bottom:10px;">🔒</div><h1>' . htmlspecialchars($titre) . '</h1><p>' . htmlspecialchars($msg) . '</p></div></body></html>';
    exit;
}

// ════════════════════════════════════════════════════════════════════
// 1) RÉSOLUTION DU PARTAGE (aperçu staff OU jeton public)
// ════════════════════════════════════════════════════════════════════
$isPreview = false;
$needConsent = false;
$previewId = (int)($_GET['preview'] ?? 0);

if ($previewId > 0) {
    // ── APERÇU STAFF : rendu live, sans jeton ni consentement ──
    require_once __DIR__ . '/inc/auth.php';
    require_login();
    $staffRoles = [1, 2, 3, 7];
    $realRole   = (int)($_SESSION['id_role'] ?? 0);
    $effRole    = function_exists('current_role_id') ? (int)current_role_id() : 0;
    if (!in_array($realRole, $staffRoles, true) && !in_array($effRole, $staffRoles, true)
        && !(function_exists('is_super_admin') && is_super_admin())) {
        pp_stop('Accès refusé', 'Aperçu réservé au personnel.');
    }
    $st = $pdo->prepare("SELECT * FROM patrimoine_partages WHERE id = ?");
    $st->execute([$previewId]);
    $share = $st->fetch(PDO::FETCH_ASSOC);
    if (!$share) pp_stop('Introuvable', 'Partage introuvable.');
    $isPreview = true;
} else {
    // ── ACCÈS PUBLIC PAR JETON ──
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
    if ($token === '') pp_stop('Lien invalide', 'Ce lien de partage est incomplet.');
    $st = $pdo->prepare("SELECT * FROM patrimoine_partages WHERE token = ? LIMIT 1");
    $st->execute([$token]);
    $share = $st->fetch(PDO::FETCH_ASSOC);
    if (!$share)                       pp_stop('Lien invalide', 'Ce lien de partage n\'existe pas ou a été supprimé.');
    if ((int)$share['actif'] !== 1)    pp_stop('Accès clôturé', 'Ce partage a été désactivé ou révoqué par l\'agence.');
    if ($share['expire_at'] && strtotime($share['expire_at']) < time())
                                       pp_stop('Lien expiré', 'Ce lien de partage a expiré. Contactez votre gestionnaire.');

    // Consentement au 1er accès
    if (empty($share['consent_at'])) {
        if (($_POST['consent'] ?? '') === '1') {
            $pdo->prepare("UPDATE patrimoine_partages SET consent_at = NOW() WHERE id = ?")->execute([$share['id']]);
            $share['consent_at'] = date('Y-m-d H:i:s');
        } else {
            $needConsent = true;
        }
    }

    // Journal de consultation (hors consentement)
    if (!$needConsent) {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE patrimoine_partages
                       SET nb_consultations = nb_consultations + 1,
                           derniere_consultation = ?,
                           premiere_consultation = COALESCE(premiere_consultation, ?)
                       WHERE id = ?")->execute([$now, $now, $share['id']]);
    }
}

// ── Écran de consentement ────────────────────────────────────────────
if ($needConsent) {
    $titre = 'Accès à votre espace patrimoine';
    ?>
    <!doctype html><html lang="fr"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $e($titre) ?></title>
    <style>
      body{font-family:-apple-system,Segoe UI,sans-serif;background:#10254d;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0;}
      .c{max-width:520px;padding:36px;background:#16305e;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.35);}
      h1{font-size:22px;margin:0 0 12px;} p{color:#c3d2ea;line-height:1.6;font-size:.95em;}
      button{margin-top:18px;background:#d4a047;color:#10254d;border:none;border-radius:10px;padding:13px 26px;font-size:1em;font-weight:700;cursor:pointer;}
      button:hover{background:#e0b25e;}
    </style></head><body>
      <div class="c">
        <div style="font-size:42px;margin-bottom:8px;">🏛️</div>
        <h1><?= $e($titre) ?></h1>
        <p>Vous accédez à un espace privé de consultation du patrimoine, mis à votre disposition par l'agence.
           Ces informations sont <strong>confidentielles</strong> et réservées à votre usage. En continuant,
           vous acceptez d'en préserver la confidentialité.</p>
        <form method="POST">
          <input type="hidden" name="consent" value="1">
          <button type="submit">J'accède à l'espace →</button>
        </form>
      </div>
    </body></html>
    <?php
    exit;
}

// ════════════════════════════════════════════════════════════════════
// 2) PÉRIMÈTRE + CONTEXTE (logo agence / gestionnaire)
// ════════════════════════════════════════════════════════════════════
$idBailleur = (int)$share['id_user_bailleur'];
$stP = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?");
$stP->execute([$idBailleur]);
$propIds = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN));
$propFilterWhere = empty($propIds) ? 'AND 1=0'
                 : 'AND ct.id_proprietaire IN (' . implode(',', $propIds) . ')';

// Flags colonnes
$showDesc  = (int)$share['montrer_descriptif'];
$showLoc   = (int)$share['montrer_locataire'];
$showLoyer = (int)$share['montrer_loyer'];
$showPrix  = (int)$share['montrer_prix_vente'];
$showCrea  = (int)($share['montrer_creanciers'] ?? 0);
// Droit d'écriture : staff en aperçu écrit toujours ; sinon selon niveau_acces du partage.
$canWrite  = $isPreview || (($share['niveau_acces'] ?? 'lecture') === 'contribution');

// Scénarios partagés + sélection
$scenAllowed = array_values(array_filter(array_map('trim', explode(',', (string)$share['scenario_code']))));
if (empty($scenAllowed)) $scenAllowed = ['courant'];
$scenReq = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['scenario'] ?? '')));
$scenSel = in_array($scenReq, $scenAllowed, true) ? $scenReq : $scenAllowed[0];
// Libellés lisibles des scénarios
$scenLabels = [];
if ($scenAllowed) {
    $in = implode(',', array_fill(0, count($scenAllowed), '?'));
    $stL = $pdo->prepare("SELECT scenario_code, MAX(COALESCE(NULLIF(scenario_label,''),scenario_code)) AS lbl
                          FROM bien_prix WHERE type_valeur='prix_vente' AND scenario_code IN ($in) GROUP BY scenario_code");
    $stL->execute($scenAllowed);
    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $r) { $scenLabels[$r['scenario_code']] = $r['lbl']; }
}
foreach ($scenAllowed as $sc) { if (!isset($scenLabels[$sc])) $scenLabels[$sc] = ucfirst($sc); }

// Coordonnées du GESTIONNAIRE (bloc contact) — indépendant de la marque affichée.
$gest = null;
if (!empty($share['id_user_gestionnaire'])) {
    $stG = $pdo->prepare("SELECT id, nom, prenom, email, telephone, fonction FROM users WHERE id = ?");
    $stG->execute([(int)$share['id_user_gestionnaire']]);
    $gest = $stG->fetch(PDO::FETCH_ASSOC) ?: null;
}

// MARQUE (logo + nom + coordonnées) = AGENCE DU PROPRIÉTAIRE BAILLEUR (pas du user).
// On prend l'agence dominante des propriétaires du périmètre.
$idAgence = 0;
if ($propIds) {
    $inP = implode(',', $propIds);
    $idAgence = (int)($pdo->query("
        SELECT pr.id_agence FROM proprietaires pr
        WHERE pr.id IN ($inP) AND pr.id_agence IS NOT NULL
        GROUP BY pr.id_agence ORDER BY COUNT(*) DESC LIMIT 1
    ")->fetchColumn() ?: 0);
}
$ag = null;
if ($idAgence) {
    $stA = $pdo->prepare("SELECT id, id_societe, nom_agence, logo_url, logo_path, telephone, email,
                                 adresse_1, code_postal, ville, site_web
                          FROM agences WHERE id = ?");
    $stA->execute([$idAgence]);
    $ag = $stA->fetch(PDO::FETCH_ASSOC) ?: null;
}
$idSociete = (int)($ag['id_societe'] ?? 1);
$stS = $pdo->prepare("SELECT nom, raison_sociale, logo_url, telephone, email, adresse_1, code_postal, ville, site_web, couleur_principale
                      FROM societes WHERE id = ?");
$stS->execute([$idSociete]);
$soc = $stS->fetch(PDO::FETCH_ASSOC) ?: ['nom' => 'Agence'];

// Logo : agence (url puis path), repli société.
$logoRel = $ag['logo_url'] ?? $ag['logo_path'] ?? $soc['logo_url'] ?? '';
$logoSrc = $logoRel ? (function_exists('app_url') ? app_url('/' . ltrim($logoRel, '/')) : '/' . ltrim($logoRel, '/')) : '';
$accent  = !empty($soc['couleur_principale']) ? $soc['couleur_principale'] : '#243B5C';

// Identité affichée = agence si dispo, sinon société.
$marqueNom   = $ag['nom_agence'] ?? ($soc['raison_sociale'] ?: $soc['nom']);
$marqueTel   = $ag['telephone'] ?? $soc['telephone'] ?? '';
$marqueMail  = $ag['email'] ?? $soc['email'] ?? '';
$marqueAdr   = trim(($ag['adresse_1'] ?? $soc['adresse_1'] ?? '') . ' ' . ($ag['code_postal'] ?? $soc['code_postal'] ?? '') . ' ' . ($ag['ville'] ?? $soc['ville'] ?? ''));

// ════════════════════════════════════════════════════════════════════
// 3) DONNÉES PATRIMOINE
// ════════════════════════════════════════════════════════════════════
$base_sql = patrimoine_base_sql($propFilterWhere, $scenSel);

// Propriétaires (repliés par défaut) + agrégats
$props = $pdo->query("
    SELECT p.id, COALESCE(NULLIF(p.societe,''), TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS nom,
           COUNT(DISTINCT CASE WHEN sub.imm_vendu=0 THEN sub.id_bien END) AS nb_biens,
           ROUND(SUM(CASE WHEN sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.loyer_appele ELSE 0 END)/3,0) AS loyer_total
    FROM proprietaires p
    JOIN ({$base_sql}) sub ON sub.id_proprietaire = p.id
    GROUP BY p.id
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

// Stats dossiers créanciers par propriétaire (voyant/KPI ligne repliée) — si autorisé.
$creStats = [];
if ($showCrea && $propIds) {
    $inP = implode(',', $propIds);
    $stC = $pdo->query("
        SELECT dl.entity_id AS pid,
               SUM(d.statut IN ('actif','surveillance')) AS actifs,
               SUM(d.statut = 'clos')                    AS clos,
               MAX(d.niveau_risque = 'rouge')            AS urgent,
               MAX(d.debiteur_enerve = 1)                AS enerve
        FROM creancier_dossier_lien dl
        JOIN creancier_dossier d ON d.id = dl.id_dossier
        WHERE dl.entity_type = 'PROPRIETAIRE' AND dl.entity_id IN ($inP)
        GROUP BY dl.entity_id
    ");
    foreach ($stC as $r) { $creStats[(int)$r['pid']] = $r; }
}

// Détail par propriétaire (biens + locataire + loyer + prix scénario)
$details = [];
foreach ($props as $pr) { $details[(int)$pr['id']] = []; }
$detStmt = $pdo->query("
    SELECT sub.id_proprietaire, sub.id_bien, sub.locataire_nom, sub.loyer_appele,
           sub.presence, sub.imm_vendu, sub.loc_archive, sub.hors_crg,
           b.reference_bien, b.prix_demande_initial,
           COALESCE(NULLIF(b.surface_habitable,0), NULLIF(b.surface_carrez,0), NULLIF(b.surface_totale,0),
                    NULLIF(b.surface_commerciale,0), NULLIF(b.surface_depot,0), NULLIF(b.surface_bureau,0)) AS surface,
           COALESCE(bt.categorie, btb.categorie_usage) AS bat_cat,
           COALESCE(bt.libelle, btb.label)             AS bat_label,
           b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
           i.id AS id_immeuble, i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
           (SELECT bx.loyer FROM baux bx WHERE bx.id_bien=sub.id_bien AND bx.id_proprietaire=sub.id_proprietaire
              ORDER BY (bx.statut='actif') DESC, bx.id DESC LIMIT 1) AS bail_loyer,
           (SELECT bp.montant FROM bien_prix bp
              WHERE bp.id_bien=sub.id_bien AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code=" . $pdo->quote($scenSel) . "
              ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1) AS prix_scenario
    FROM ({$base_sql}) sub
    LEFT JOIN biens b ON b.id = sub.id_bien
    LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
    LEFT JOIN base_types_bien btb ON btb.id = b.id_type_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    WHERE sub.imm_vendu = 0 AND sub.loc_archive = 0
    ORDER BY sub.id_proprietaire, b.reference_bien ASC, sub.locataire_nom
");
foreach ($detStmt as $r) { $details[(int)$r['id_proprietaire']][] = $r; }

// Helpers d'affichage bien
function pp_bien_desc(array $d): string {
    // Adresse seule (la surface passe en colonne dédiée alignée).
    $bits = array_filter([
        trim((string)($d['bien_adresse'] ?? '')) ?: trim((string)($d['imm_adresse'] ?? '')),
        trim((string)($d['bien_ville'] ?? '')) ?: trim((string)($d['imm_ville'] ?? '')),
    ]);
    return implode(' · ', $bits);
}
function pp_surface(array $d): string {
    $s = (float)($d['surface'] ?? 0);
    return $s > 0 ? rtrim(rtrim(number_format($s, 1, ',', ' '), '0'), ',') . ' m²' : '—';
}
/** Type de bâtiment simplifié : Habitation / Commerce / Entrepôt-Pro / Terrain / Stationnement. */
function pp_batiment(array $d): array {
    $cat = strtolower(trim((string)($d['bat_cat'] ?? '')));
    $label = trim((string)($d['bat_label'] ?? ''));
    switch ($cat) {
        case 'habitation':    return ['🏠', 'Habitation', $label];
        case 'commerce':
        case 'commercial':    return ['🏪', 'Commerce', $label];
        case 'professionnel': return ['🏭', 'Entrepôt / Pro', $label];
        case 'terrain':       return ['🌳', 'Terrain', $label];
        case 'stationnement': return ['🅿️', 'Stationnement', $label];
        case 'annexe':        return ['📦', 'Annexe', $label];
        default:              return ['🏢', $label ?: '—', ''];
    }
}
function pp_loyer_mois(array $d): float {
    $bl = (float)($d['bail_loyer'] ?? 0);
    return $bl > 0 ? $bl : ((float)($d['loyer_appele'] ?? 0) / 3);
}
function pp_prix(array $d): float {
    $px = (float)($d['prix_scenario'] ?? 0);
    if ($px <= 0) $px = (float)($d['prix_demande_initial'] ?? 0);
    return $px;
}
if (!function_exists('fmt_euro')) { function fmt_euro($v){ return number_format((float)$v, 0, ',', ' ') . ' €'; } }

$destNom = $share['destinataire_nom'] ?: 'Consultation patrimoine';
$colspan = 3 + $showLoc + $showLoyer + $showPrix; // Bien + Type + Surface + colonnes optionnelles
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($soc['nom']) ?> — Patrimoine</title>
<style>
  :root{ --accent: <?= $e($accent) ?>; }
  *{box-sizing:border-box;}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#eef1f6;color:#1f2a44;}
  .wrap{max-width:1180px;margin:0 auto;padding:0 18px 60px;}
  header.top{background:var(--accent);color:#fff;}
  .top-inner{max-width:1180px;margin:0 auto;padding:18px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;}
  .logo{height:52px;background:#fff;border-radius:8px;padding:5px 9px;}
  .top-title{font-size:1.15em;font-weight:700;}
  .top-sub{font-size:.82em;opacity:.8;}
  .gest{margin-left:auto;text-align:right;font-size:.82em;line-height:1.5;}
  .gest strong{font-size:1.05em;}
  .gest a{color:#fff;text-decoration:none;}
  <?php if ($isPreview): ?>.preview-band{background:#d4a047;color:#3a2a00;text-align:center;padding:6px;font-size:.82em;font-weight:700;}<?php endif; ?>

  .toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:20px 0 14px;}
  .toolbar input.search{flex:1;min-width:220px;border:1px solid #cdd4e0;border-radius:10px;padding:10px 14px;font-size:.92em;}
  .scen-pills{display:flex;gap:6px;flex-wrap:wrap;}
  .scen-pill{border:1px solid #cdd4e0;background:#fff;border-radius:20px;padding:7px 14px;font-size:.82em;text-decoration:none;color:#3a4b6e;}
  .scen-pill.on{background:var(--accent);color:#fff;border-color:var(--accent);font-weight:700;}
  .btn-toggle-all{border:1px solid #cdd4e0;background:#fff;border-radius:10px;padding:9px 14px;font-size:.85em;cursor:pointer;color:#3a4b6e;}

  .prop{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:12px;overflow:hidden;}
  .prop-head{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;}
  .prop-head:hover{background:#f7f9fc;}
  .prop-caret{transition:transform .15s;color:#8592ad;}
  .prop.open .prop-caret{transform:rotate(90deg);}
  .prop-name{font-weight:700;font-size:1.02em;}
  .prop-meta{margin-left:auto;display:flex;align-items:center;gap:14px;font-size:.85em;color:#5a6884;}
  .prop-meta b{color:#1f2a44;}
  .prop-meta .pm-nb{white-space:nowrap;}
  /* Totaux accolés, alignés sous les colonnes Loyer / Prix (mêmes largeurs). */
  .prop-meta .tot-wrap{display:flex;gap:0;}
  .prop-meta .tot-loyer,.prop-meta .tot-prix{width:150px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;}
  .prop-meta .tot-loyer{padding-right:14px;} .prop-meta .tot-prix{padding-right:18px;}
  .prop-meta .tot-loyer small,.prop-meta .tot-prix small{display:block;font-size:.72em;color:#8592ad;font-weight:400;}
  /* Largeurs colonnes = largeurs des totaux (alignement vertical). */
  table.biens th.col-loyer,table.biens td.col-loyer,
  table.biens th.col-prix,table.biens td.col-prix{width:150px;}
  table.biens th:last-child,table.biens td:last-child{padding-right:18px;}
  .cre-kpi{display:inline-flex;align-items:center;gap:6px;background:#fff4e5;color:#a15c00;border:1px solid #f3d6a8;border-radius:20px;padding:3px 11px;font-size:.86em;}
  .cre-kpi.urg{background:#fdecec;color:#b52a2a;border-color:#f3b8b8;}
  .cre-kpi.zero{background:#f2f4f8;color:#8592ad;border-color:#e2e7f0;}
  a.cre-kpi{text-decoration:none;cursor:pointer;}
  a.cre-kpi:hover{filter:brightness(.97);border-color:#c7d0e0;}
  .cre-kpi .cre-add{font-weight:700;color:#2f6d4a;}
  .cre-kpi .voyant{width:9px;height:9px;border-radius:50%;background:#e23b3b;box-shadow:0 0 0 3px rgba(226,59,59,.18);animation:crePulse 1.6s infinite;}
  .cre-kpi .tag-env{font-size:1.05em;}
  @keyframes crePulse{0%,100%{opacity:1;}50%{opacity:.35;}}
  .prop-body{display:none;border-top:1px solid #eef1f6;}
  .prop.open .prop-body{display:block;}
  table.biens{width:100%;border-collapse:collapse;font-size:.86em;}
  table.biens th{background:#f4f6fb;text-align:left;padding:9px 14px;color:#5a6884;font-size:.9em;white-space:nowrap;}
  table.biens td{padding:9px 14px;border-top:1px solid #f0f2f7;vertical-align:top;}
  table.biens tr:hover td{background:#fafbfe;}
  .ref{font-weight:600;color:var(--accent);}
  .desc{color:#6b7796;font-size:.92em;}
  .num{text-align:right;white-space:nowrap;}
  .col-type{white-space:nowrap;} .col-surf{width:92px;}
  .bat{display:inline-flex;align-items:center;gap:5px;background:#eef1f6;color:#3a4b6e;border-radius:20px;padding:2px 10px;font-size:.92em;}
  .prix-cell{font-weight:600;color:#1f2a44;}
  table.biens td.num{font-variant-numeric:tabular-nums;}
  .vacant{color:#b0851f;font-style:italic;}
  .empty{padding:40px;text-align:center;color:#9aa6bd;}
  footer.legal{text-align:center;color:#9aa6bd;font-size:.76em;margin-top:30px;line-height:1.6;}
</style>
</head>
<body>
<?php if ($isPreview): ?><div class="preview-band">👁 APERÇU INTERNE — vue exacte du destinataire « <?= $e($destNom) ?> » (non journalisé)</div><?php endif; ?>

<header class="top">
  <div class="top-inner">
    <?php if ($logoSrc): ?><img src="<?= $e($logoSrc) ?>" alt="" class="logo"><?php endif; ?>
    <div>
      <div class="top-title"><?= $e($marqueNom) ?></div>
      <div class="top-sub">Consultation du patrimoine · <?= $e($destNom) ?></div>
    </div>
    <div class="gest">
      <?php if ($gest): ?>
        <strong><?= $e(trim(($gest['prenom'] ?? '') . ' ' . ($gest['nom'] ?? ''))) ?></strong><br>
        <?php if (!empty($gest['fonction'])): ?><?= $e($gest['fonction']) ?><br><?php endif; ?>
        <?php if (!empty($gest['telephone'])): ?>📞 <?= $e($gest['telephone']) ?><br><?php endif; ?>
        <?php if (!empty($gest['email'])): ?>✉️ <a href="mailto:<?= $e($gest['email']) ?>"><?= $e($gest['email']) ?></a><?php endif; ?>
      <?php else: ?>
        <?php if ($marqueTel): ?>📞 <?= $e($marqueTel) ?><br><?php endif; ?>
        <?php if ($marqueMail): ?>✉️ <a href="mailto:<?= $e($marqueMail) ?>"><?= $e($marqueMail) ?></a><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="wrap">

  <div class="toolbar">
    <input type="text" class="search" id="pp-search" placeholder="🔎 Rechercher un propriétaire, une adresse, un locataire…">
    <?php if (count($scenAllowed) > 1): ?>
    <div class="scen-pills">
      <?php foreach ($scenAllowed as $sc):
        $q = array_merge($_GET, ['scenario' => $sc]); ?>
      <a class="scen-pill <?= $sc === $scenSel ? 'on' : '' ?>" href="?<?= $e(http_build_query($q)) ?>"><?= $e($scenLabels[$sc]) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button type="button" class="btn-toggle-all" id="pp-toggle-all">Tout déplier</button>
  </div>

  <?php if (empty($props)): ?>
    <div class="prop"><div class="empty">Aucun bien à afficher dans ce périmètre.</div></div>
  <?php else: foreach ($props as $pr):
      $pid = (int)$pr['id'];
      // Une seule ligne par bien : on garde la ligne OCCUPÉE en priorité (repli vacant).
      $parBien = [];
      foreach ($details[$pid] ?? [] as $d) {
          $ib = (int)$d['id_bien'];
          $isPresent = ($d['presence'] === 'present' && empty($d['hors_crg']));
          if (!isset($parBien[$ib]) || ($isPresent && $parBien[$ib]['presence'] !== 'present')) {
              $parBien[$ib] = $d;
          }
      }
      $biens = array_values($parBien);
      if (empty($biens)) continue; // propriétaire sans bien actif dans ce périmètre
      $nbBiens = count($biens);
      // Totaux alignés colonnes (calculés sur les biens réellement affichés).
      $sumLoyer = 0.0; $sumPrix = 0.0;
      foreach ($biens as $d) { $sumLoyer += pp_loyer_mois($d); $sumPrix += pp_prix($d); }
  ?>
  <div class="prop" data-prop>
    <div class="prop-head" onclick="this.parentNode.classList.toggle('open')">
      <span class="prop-caret">▶</span>
      <span class="prop-name"><?= $e($pr['nom']) ?></span>
      <span class="prop-meta">
        <?php if ($showCrea):
            $cs = $creStats[$pid] ?? null;
            $csA = (int)($cs['actifs'] ?? 0); $csC = (int)($cs['clos'] ?? 0);
            $csUrg = !empty($cs['urgent']); $csEnv = !empty($cs['enerve']);
            $csTotal = $csA + $csC;
            // Cible du clic : page dossiers avocat (Phase B). En aperçu on passe l'id du partage.
            $creHref = $isPreview
                ? 'patrimoine_creancier.php?preview=' . (int)$share['id'] . '&proprio=' . $pid
                : 'patrimoine_creancier.php?t=' . urlencode($token ?? '') . '&proprio=' . $pid;
            $isLink = $canWrite || $csTotal > 0; // cliquable si on peut écrire OU s'il y a des dossiers à consulter
            $tag = $isLink ? 'a' : 'span';
            $hrefAttr = $isLink ? ('href="' . $e($creHref) . '"') : '';
        ?>
          <<?= $tag ?> <?= $hrefAttr ?> class="cre-kpi <?= $csUrg ? 'urg' : ($csTotal === 0 ? 'zero' : '') ?>"
             onclick="event.stopPropagation();"
             title="<?= $csTotal ? 'Dossiers créanciers de ce propriétaire' : ($canWrite ? 'Aucun dossier — cliquer pour en créer' : 'Aucun dossier créancier') ?>">
            <?php if ($csUrg): ?><span class="voyant"></span><?php endif; ?>
            <?php if ($csTotal === 0): ?>
              ⚖️ <b>0</b><?php if ($canWrite): ?> <span class="cre-add">+ créer</span><?php endif; ?>
            <?php else: ?>
              ⚖️ <b><?= $csA ?></b> en cours<?php if ($csC): ?> · <?= $csC ?> clos<?php endif; ?>
              <?php if ($csEnv): ?><span class="tag-env" title="Débiteur énervé">😤</span><?php endif; ?>
            <?php endif; ?>
          </<?= $tag ?>>
        <?php endif; ?>
        <span class="pm-nb"><b><?= $nbBiens ?></b> bien<?= $nbBiens > 1 ? 's' : '' ?></span>
        <span class="tot-wrap">
          <?php if ($showLoc): ?><span class="tot-loc"></span><?php endif; ?>
          <?php if ($showLoyer): ?>
          <span class="tot-loyer" title="Total des loyers mensuels"><b><?= fmt_euro($sumLoyer) ?></b><small>/mois</small></span>
          <?php endif; ?>
          <?php if ($showPrix): ?>
          <span class="tot-prix" title="Valorisation totale (prix de vente)"><b><?= fmt_euro($sumPrix) ?></b><small>valorisation</small></span>
          <?php endif; ?>
        </span>
      </span>
    </div>
    <div class="prop-body">
      <table class="biens">
        <thead><tr>
          <th>Bien<?= $showDesc ? ' — descriptif' : '' ?></th>
          <th class="col-type">Type</th>
          <th class="num col-surf">Surface</th>
          <?php if ($showLoc): ?><th>Locataire</th><?php endif; ?>
          <?php if ($showLoyer): ?><th class="num col-loyer">Loyer/mois</th><?php endif; ?>
          <?php if ($showPrix): ?><th class="num col-prix">Prix de vente</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($biens as $d):
            $vacant = !empty($d['hors_crg']) || $d['presence'] !== 'present';
            $loc = trim((string)$d['locataire_nom']);
            [$batIcon, $batCat, $batLabel] = pp_batiment($d);
        ?>
        <tr data-search="<?= $e(mb_strtolower($pr['nom'].' '.($d['reference_bien']??'').' '.pp_bien_desc($d).' '.$batCat.' '.$batLabel.' '.$loc)) ?>">
          <td>
            <span class="ref"><?= $e($d['reference_bien'] ?: '—') ?></span>
            <?php if ($showDesc): ?><div class="desc"><?= $e(pp_bien_desc($d)) ?></div><?php endif; ?>
          </td>
          <td class="col-type">
            <span class="bat" title="<?= $e($batLabel ?: $batCat) ?>"><?= $batIcon ?> <?= $e($batCat) ?></span>
          </td>
          <td class="num col-surf"><?= $e(pp_surface($d)) ?></td>
          <?php if ($showLoc): ?>
          <td class="<?= $vacant ? 'vacant' : '' ?>"><?= $vacant ? 'Vacant' : $e($loc) ?></td>
          <?php endif; ?>
          <?php if ($showLoyer): ?>
          <td class="num col-loyer"><?= pp_loyer_mois($d) > 0 ? fmt_euro(pp_loyer_mois($d)) : '—' ?></td>
          <?php endif; ?>
          <?php if ($showPrix): ?>
          <td class="num col-prix prix-cell"><?= pp_prix($d) > 0 ? fmt_euro(pp_prix($d)) : '—' ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <footer class="legal">
    <?= $e($marqueNom) ?>
    <?php if ($marqueAdr): ?> · <?= $e($marqueAdr) ?><?php endif; ?>
    <?php if ($marqueTel): ?> · <?= $e($marqueTel) ?><?php endif; ?><br>
    Espace privé et confidentiel — informations fournies à titre indicatif, sans valeur contractuelle.
  </footer>

</div>

<script>
(function(){
  // Recherche : filtre lignes + ouvre les propriétaires qui matchent
  var search = document.getElementById('pp-search');
  var props  = Array.prototype.slice.call(document.querySelectorAll('[data-prop]'));
  if (search) search.addEventListener('input', function(){
    var q = this.value.trim().toLowerCase();
    props.forEach(function(p){
      var rows = p.querySelectorAll('tbody tr'); var any = false;
      rows.forEach(function(r){
        var ok = !q || (r.getAttribute('data-search')||'').indexOf(q) !== -1;
        r.style.display = ok ? '' : 'none'; if (ok) any = true;
      });
      p.style.display = any ? '' : 'none';
      if (q && any) p.classList.add('open'); else if (!q) p.classList.remove('open');
    });
  });
  // Tout déplier / replier
  var btn = document.getElementById('pp-toggle-all'); var expanded = false;
  if (btn) btn.addEventListener('click', function(){
    expanded = !expanded;
    props.forEach(function(p){ p.classList.toggle('open', expanded); });
    btn.textContent = expanded ? 'Tout replier' : 'Tout déplier';
  });
})();
</script>
</body>
</html>
