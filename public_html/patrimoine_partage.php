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
$isAdmin   = false;
$ADMIN_PROP_IDS = null;
$previewId = (int)($_GET['preview'] ?? 0);
$adminMode = !empty($_GET['admin']);

if ($adminMode) {
    // ── VUE ADMIN / PLEIN ACCÈS (staff = tout ; bailleur = son patrimoine) ──
    require_once __DIR__ . '/inc/auth.php';
    require_login();
    $uid    = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);
    $isSA   = function_exists('is_super_admin') && is_super_admin();
    $realRole = (int)($_SESSION['id_role'] ?? 0);
    $isStaff  = $isSA || in_array($realRole, [1, 2, 3, 7], true);

    if ($isStaff) {
        // Staff : périmètre = un bailleur ciblé (&bailleur=ID) OU tous les propriétaires.
        $bArg = (int)($_GET['bailleur'] ?? 0);
        if ($bArg > 0) {
            $stB = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
            $stB->execute([$bArg]);
            $ADMIN_PROP_IDS = array_map('intval', $stB->fetchAll(PDO::FETCH_COLUMN));
            $admBailleur = $bArg;
        } else {
            $ADMIN_PROP_IDS = array_map('intval', $pdo->query("SELECT id FROM proprietaires")->fetchAll(PDO::FETCH_COLUMN));
            $admBailleur = 0;
        }
        $admNom = 'Vue admin — plein accès';
    } else {
        // Bailleur (ex: Thomas SABY) : son propre patrimoine, plein accès.
        $stB = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
        $stB->execute([$uid]);
        $ADMIN_PROP_IDS = array_map('intval', $stB->fetchAll(PDO::FETCH_COLUMN));
        if (empty($ADMIN_PROP_IDS)) pp_stop('Accès refusé', 'Aucun patrimoine associé à votre compte.');
        $admBailleur = $uid;
        $admNom = 'Mon patrimoine';
    }

    $allScen = (string)($pdo->query("SELECT GROUP_CONCAT(DISTINCT scenario_code) FROM bien_prix WHERE type_valeur='prix_vente' AND is_courant=1")->fetchColumn() ?: 'courant');
    $share = [
        'id' => 0, 'token' => '', 'id_user_bailleur' => $admBailleur, 'id_user_gestionnaire' => null,
        'id_tiers_destinataire' => null, 'destinataire_nom' => $admNom, 'destinataire_email' => null,
        'scenario_code' => $allScen,
        'montrer_prix_vente' => 1, 'montrer_creanciers' => 1, 'montrer_financements' => 1,
        'montrer_loyer' => 1, 'montrer_locataire' => 1, 'montrer_descriptif' => 1,
        'niveau_acces' => 'contribution', 'expire_at' => null, 'actif' => 1, 'consent_at' => date('Y-m-d H:i:s'),
    ];
    $isPreview     = true;  // staff-like : écriture autorisée, pas de journalisation
    $isAdmin       = true;
    $adminIsStaff  = $isStaff;
    $adminBailleur = $admBailleur;
    // Staff : liste des comptes bailleurs pour le sélecteur.
    $adminBailleurs = [];
    if ($isStaff) {
        $adminBailleurs = $pdo->query("
            SELECT u.id, TRIM(CONCAT_WS(' ', u.prenom, u.nom)) AS nom,
                   (SELECT COUNT(*) FROM user_proprietaires WHERE id_user=u.id) AS nb
            FROM users u JOIN roles r ON r.id=u.id_role
            WHERE u.super_admin=0
              AND (r.code IN ('PROPRIO','PROPRIO_VIP','bailleur')
                   OR EXISTS (SELECT 1 FROM user_proprietaires WHERE id_user=u.id))
            HAVING nb > 0
            ORDER BY nom
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($previewId > 0) {
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
if ($isAdmin) {
    $propIds = $ADMIN_PROP_IDS ?: [];
} else {
    $stP = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?");
    $stP->execute([$idBailleur]);
    $propIds = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN));
}
$propFilterWhere = empty($propIds) ? 'AND 1=0'
                 : 'AND ct.id_proprietaire IN (' . implode(',', $propIds) . ')';

// Flags colonnes
$showDesc  = (int)$share['montrer_descriptif'];
$showLoc   = (int)$share['montrer_locataire'];
$showLoyer = (int)$share['montrer_loyer'];
$showPrix  = (int)$share['montrer_prix_vente'];
$showCrea  = (int)($share['montrer_creanciers'] ?? 0);
$showFin   = (int)($share['montrer_financements'] ?? 0);
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
// EXCEPTION vue admin « Tous les bailleurs » : périmètre = tous les propriétaires
// → l'agence dominante n'a aucun sens (elle donnerait celle qui a le plus de biens).
// On affiche alors l'agence du compte connecté (staff).
$idAgence = 0;
if ($isAdmin && !empty($adminIsStaff) && empty($adminBailleur)) {
    $idAgence = (int)($_SESSION['id_agence'] ?? 0);
}
if (!$idAgence && $propIds) {
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
           COALESCE(p.ifi_personnel,0) AS ifi_personnel,
           COUNT(DISTINCT CASE WHEN sub.imm_vendu=0 THEN sub.id_bien END) AS nb_biens,
           ROUND(SUM(CASE WHEN sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.loyer_appele ELSE 0 END)/3,0) AS loyer_total
    FROM proprietaires p
    JOIN ({$base_sql}) sub ON sub.id_proprietaire = p.id
    GROUP BY p.id
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

// Mode IFI : impôt PERSONNEL → seuls les propriétaires marqués ifi_personnel.
$isIfi   = ($scenSel === 'ifi');
$ifiEdit = ($isIfi && $canWrite && $showFin); // édition réservée au comptable (contribution)
if ($isIfi) {
    $props = array_values(array_filter($props, fn($p) => !empty($p['ifi_personnel'])));
    $showPrix = 1;              // la colonne « Valeur IFI » s'affiche toujours en scénario IFI
    $colspan  = 3 + $showLoc + $showLoyer + $showPrix;
}

// ── Validation d'une valeur IFI (comptable) → nouvelle ligne bien_prix (historique natif) ──
if ($ifiEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_ifi') {
    $bienId  = (int)($_POST['bien_id'] ?? 0);
    $montant = round((float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0')), 2);
    // Le bien doit appartenir à un propriétaire IFI-perso du périmètre.
    $chk = $pdo->prepare("SELECT COUNT(*) FROM biens b JOIN proprietaires p ON p.id=b.id_proprietaire
                          WHERE b.id=? AND p.ifi_personnel=1 AND b.id_proprietaire IN (" . implode(',', $propIds ?: [0]) . ")");
    $chk->execute([$bienId]);
    if ($bienId && $montant > 0 && (int)$chk->fetchColumn()) {
        $uid = ($isPreview && function_exists('current_user_id')) ? (int)current_user_id() : null;
        $pdo->beginTransaction();
        try {
            // Historisation : l'ancienne valeur IFI courante passe à is_courant=0.
            $pdo->prepare("UPDATE bien_prix SET is_courant=0
                           WHERE id_bien=? AND type_valeur='prix_vente' AND scenario_code='ifi' AND is_courant=1")->execute([$bienId]);
            $pdo->prepare("INSERT INTO bien_prix
                (id_bien, type_valeur, scenario_code, scenario_label, montant, source, id_user, commentaire, is_courant, date_validation)
                VALUES (?, 'prix_vente', 'ifi', 'IFI', ?, 'comptable', ?, ?, 1, NOW())")
                ->execute([$bienId, $montant, $uid, 'Valeur IFI validée via partage']);
            $pdo->commit();
        } catch (Throwable $ex) { $pdo->rollBack(); }
    }
    $selfUrl = ($isPreview ? 'patrimoine_partage.php?preview=' . (int)$share['id'] : 'patrimoine_partage.php?t=' . urlencode((string)($token ?? '')))
             . '&scenario=ifi#p' . (int)($_POST['back_pid'] ?? 0);
    header('Location: ' . $selfUrl);
    exit;
}

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

// Stats financements par propriétaire (voyant/KPI 💶) — si autorisé.
$finStats = [];
if ($showFin && $propIds) {
    $inP = implode(',', $propIds);
    $stF = $pdo->query("
        SELECT dl.entity_id AS pid,
               SUM(d.statut <> 'clos')            AS encours,
               SUM(d.statut = 'clos')             AS soldes,
               ROUND(SUM(CASE WHEN d.statut <> 'clos' THEN COALESCE(d.solde_restant, d.montant_total, 0) ELSE 0 END)) AS solde_du
        FROM fin_dossier_lien dl
        JOIN fin_dossier d ON d.id = dl.id_dossier
        WHERE dl.entity_type = 'PROPRIETAIRE' AND dl.entity_id IN ($inP)
        GROUP BY dl.entity_id
    ");
    foreach ($stF as $r) { $finStats[(int)$r['pid']] = $r; }
}

// Détail par propriétaire — source unique partagée avec l'export Excel.
require_once __DIR__ . '/inc/patrimoine_partage_data.php';
$details = pp_load_details($pdo, $propFilterWhere, $scenSel);

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
  .admin-bailleur{border:1px solid var(--accent);background:#fff;border-radius:10px;padding:9px 12px;font-size:.9em;color:#1f2a44;font-weight:600;min-width:220px;}

  .prop{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:12px;overflow:hidden;}
  .prop-head{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;}
  .prop-head:hover{background:#f7f9fc;}
  .prop-caret{transition:transform .15s;color:#8592ad;}
  .prop.open .prop-caret{transform:rotate(90deg);}
  .prop-name{font-weight:700;font-size:1.02em;}
  .prop-meta{margin-left:auto;display:flex;align-items:center;gap:14px;font-size:.85em;color:#5a6884;}
  .prop-meta b{color:#1f2a44;}
  .prop-meta .pm-nb{white-space:nowrap;}
  .prop-meta .pm-export{white-space:nowrap;text-decoration:none;background:#1f6b4e;color:#fff;border-radius:8px;padding:6px 11px;font-size:.82em;font-weight:600;}
  .prop-meta .pm-export:hover{background:#175a41;}
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
  .fin-kpi{display:inline-flex;align-items:center;gap:6px;background:#e9f2ee;color:#1f6b4e;border:1px solid #bfe0d1;border-radius:20px;padding:3px 11px;font-size:.86em;text-decoration:none;}
  .fin-kpi.zero{background:#f2f4f8;color:#8592ad;border-color:#e2e7f0;}
  a.fin-kpi{cursor:pointer;} a.fin-kpi:hover{filter:brightness(.97);}
  .fin-kpi .cre-add{color:#2f6d4a;font-weight:700;}
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
  .ifi-form{display:inline-flex;gap:5px;align-items:center;justify-content:flex-end;}
  .ifi-input{width:104px;border:1px solid #cdd4e0;border-radius:7px;padding:5px 8px;font-size:.92em;text-align:right;font-variant-numeric:tabular-nums;}
  .ifi-btn{border:none;background:#1f6b4e;color:#fff;border-radius:7px;width:28px;height:28px;cursor:pointer;font-weight:700;}
  .ifi-btn:hover{background:#175a41;}
  .ifi-note{background:#eef4ff;border:1px solid #cdddf7;color:#274b8a;border-radius:10px;padding:10px 14px;font-size:.85em;margin:0 0 14px;}
  .ifi-note b{color:#1a3566;}
  table.biens td.num{font-variant-numeric:tabular-nums;}
  .vacant{color:#b0851f;font-style:italic;}
  .empty{padding:40px;text-align:center;color:#9aa6bd;}
  footer.legal{text-align:center;color:#9aa6bd;font-size:.76em;margin-top:30px;line-height:1.6;}
</style>
</head>
<body>
<?php if ($isAdmin): ?><div class="preview-band">🔓 VUE PLEIN ACCÈS — <?= $e($destNom) ?> · toutes colonnes, tous scénarios, édition IFI/valeurs (interne)</div>
<?php elseif ($isPreview): ?><div class="preview-band">👁 APERÇU INTERNE — vue exacte du destinataire « <?= $e($destNom) ?> » (non journalisé)</div><?php endif; ?>

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
    <?php if ($isAdmin && !empty($adminIsStaff)): ?>
    <select class="admin-bailleur" onchange="if(this.value)location.href=this.value">
      <option value="patrimoine_partage.php?admin=1&scenario=<?= $e($scenSel) ?>" <?= empty($adminBailleur) ? 'selected' : '' ?>>👥 Tous les bailleurs</option>
      <?php foreach (($adminBailleurs ?? []) as $b): ?>
      <option value="patrimoine_partage.php?admin=1&bailleur=<?= (int)$b['id'] ?>&scenario=<?= $e($scenSel) ?>"
              <?= (int)$adminBailleur === (int)$b['id'] ? 'selected' : '' ?>>
        <?= $e($b['nom'] ?: ('Compte #' . $b['id'])) ?> (<?= (int)$b['nb'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
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

  <?php if ($isIfi): ?>
  <div class="ifi-note">
    🏛️ <b>Scénario IFI</b> — l'IFI est un impôt personnel : seuls les propriétaires détenus personnellement sont affichés (SCI FOCH, SMH, SABY).
    <?php if ($ifiEdit): ?> Vous pouvez <b>saisir et valider</b> la valeur IFI de chaque bien (bouton ✓) — chaque validation est <b>historisée</b> et enregistrée sur le bien.<?php endif; ?>
  </div>
  <?php endif; ?>

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
  <div class="prop" data-prop id="p<?= $pid ?>">
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
            $isLink = !$isAdmin && ($canWrite || $csTotal > 0); // pas de drill-down en vue admin (pas de partage)
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
        <?php if ($showFin):
            $fs = $finStats[$pid] ?? null;
            $fsE = (int)($fs['encours'] ?? 0); $fsS = (int)($fs['soldes'] ?? 0);
            $fsTotal = $fsE + $fsS;
            $finHref = $isPreview
                ? 'patrimoine_financement.php?preview=' . (int)$share['id'] . '&proprio=' . $pid
                : 'patrimoine_financement.php?t=' . urlencode($token ?? '') . '&proprio=' . $pid;
            $fIsLink = !$isAdmin && ($canWrite || $fsTotal > 0);
            $fTag = $fIsLink ? 'a' : 'span';
            $fHref = $fIsLink ? ('href="' . $e($finHref) . '"') : '';
        ?>
          <<?= $fTag ?> <?= $fHref ?> class="fin-kpi <?= $fsTotal === 0 ? 'zero' : '' ?>"
             onclick="event.stopPropagation();"
             title="<?= $fsTotal ? 'Financements de ce propriétaire' : ($canWrite ? 'Aucun financement — cliquer pour en créer' : 'Aucun financement') ?>">
            <?php if ($fsTotal === 0): ?>
              💶 <b>0</b><?php if ($canWrite): ?> <span class="cre-add">+ créer</span><?php endif; ?>
            <?php else: ?>
              💶 <b><?= $fsE ?></b> en cours<?php if ($fsS): ?> · <?= $fsS ?> soldé<?= $fsS > 1 ? 's' : '' ?><?php endif; ?>
            <?php endif; ?>
          </<?= $fTag ?>>
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
        <?php if (!$isAdmin):
          $expHref = ($isPreview ? 'patrimoine_export.php?preview=' . (int)$share['id'] : 'patrimoine_export.php?t=' . urlencode($token ?? ''))
                   . '&proprio=' . $pid . '&scenario=' . urlencode($scenSel);
        ?>
        <a class="pm-export" href="<?= $e($expHref) ?>" onclick="event.stopPropagation();"
           title="Exporter la liste de ce propriétaire en Excel (notaire, propriétaire, comptable)">⬇ Excel</a>
        <?php endif; ?>
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
          <?php if ($showPrix): ?><th class="num col-prix"><?= $isIfi ? 'Valeur IFI' : 'Prix de vente' ?></th><?php endif; ?>
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
            <?php if ($ifiEdit): ?>
            <td class="num col-prix prix-cell">
              <form method="POST" class="ifi-form" onclick="event.stopPropagation();">
                <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                <input type="hidden" name="action" value="validate_ifi">
                <input type="hidden" name="bien_id" value="<?= (int)$d['id_bien'] ?>">
                <input type="hidden" name="back_pid" value="<?= $pid ?>">
                <input type="text" name="montant" class="ifi-input" inputmode="numeric"
                       value="<?= pp_prix($d) > 0 ? (int)pp_prix($d) : '' ?>" placeholder="montant €">
                <button type="submit" class="ifi-btn" title="Valider cette valeur IFI (historisée)">✓</button>
              </form>
            </td>
            <?php else: ?>
            <td class="num col-prix prix-cell"><?= pp_prix($d) > 0 ? fmt_euro(pp_prix($d)) : '—' ?></td>
            <?php endif; ?>
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
