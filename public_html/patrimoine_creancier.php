<?php
/**
 * patrimoine_creancier.php — Dossiers créanciers d'un propriétaire, vus depuis un
 * partage patrimoine (jeton avocat) ou en aperçu staff.
 *
 * Lecture pour tous ; ÉCRITURE (créer un dossier) réservée aux partages en
 * niveau_acces='contribution' (ex: avocat) ou au staff en aperçu ($canWrite).
 * Se branche sur le module Créanciers existant (creancier_dossier + _lien).
 * Version minimaliste : liste + création. Le dossier 360° détaillé viendra ensuite.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/patrimoine_partage_auth.php';

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$e   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

[$share, $isPreview, $canWrite] = pp_resolve_share($pdo);

if ((int)($share['montrer_creanciers'] ?? 0) !== 1) {
    pp_auth_stop('Non autorisé', 'Les dossiers créanciers ne sont pas partagés sur ce lien.');
}

// ── Périmètre : le propriétaire doit appartenir au partage (bypass staff admin) ──
$isAdmin   = !empty($_GET['admin']);
$proprioId = (int)($_GET['proprio'] ?? 0);
if (!pp_perimeter_ok($pdo, $share, $proprioId)) {
    pp_auth_stop('Hors périmètre', 'Ce propriétaire n\'est pas accessible depuis ce lien.');
}

$pr = $pdo->prepare("SELECT id, id_tiers, COALESCE(NULLIF(societe,''),TRIM(CONCAT_WS(' ',prenom,nom))) AS nom, id_agence
                     FROM proprietaires WHERE id=?");
$pr->execute([$proprioId]);
$proprio = $pr->fetch(PDO::FETCH_ASSOC);
if (!$proprio) pp_auth_stop('Introuvable', 'Propriétaire introuvable.');
$idSociete = (int)($pdo->query("SELECT id_societe FROM agences WHERE id=" . (int)$proprio['id_agence'])->fetchColumn() ?: 0) ?: null;

$subQS   = pp_sub_qs($share, $isPreview, $isAdmin, (string)($_GET['t'] ?? ''));
$backUrl = 'patrimoine_partage.php?' . $subQS;

$msg = ''; $msgType = '';

// ════════════════════════════════════════════════════════════════════
// CRÉATION D'UN DOSSIER (contribution / staff)
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dossier') {
    if (!$canWrite) pp_auth_stop('Lecture seule', 'Vous n\'avez pas le droit de créer un dossier.');
    $libelle   = trim((string)($_POST['libelle'] ?? ''));
    $creancier = trim((string)($_POST['creancier'] ?? ''));
    $urgent    = isset($_POST['urgent']);
    $enerve    = isset($_POST['enerve']) ? 1 : 0;
    if ($libelle === '') {
        $msg = 'Intitulé du dossier obligatoire.'; $msgType = 'error';
    } else {
        $code = 'PP-' . $proprioId . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $risque = $urgent ? 'rouge' : 'orange';
        $synthese = $creancier !== '' ? ('Créancier : ' . $creancier) : null;
        $createdBy = $isPreview && function_exists('current_user_id') ? (int)current_user_id() : null;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO creancier_dossier
                (code, libelle, statut, niveau_risque, synthese, id_societe, id_agence, debiteur_enerve, created_by)
                VALUES (?,?,'actif',?,?,?,?,?,?)")
                ->execute([$code, $libelle, $risque, $synthese, $idSociete, (int)$proprio['id_agence'], $enerve, $createdBy]);
            $idDossier = (int)$pdo->lastInsertId();
            // Lien explicite propriétaire (source du KPI)
            $pdo->prepare("INSERT INTO creancier_dossier_lien
                (id_dossier, entity_type, entity_id, role_dossier, note, created_by)
                VALUES (?, 'PROPRIETAIRE', ?, 'proprietaire_concerne', ?, ?)")
                ->execute([$idDossier, $proprioId, 'créé via partage', $createdBy]);
            // Trace datée de l'auteur
            $auteur = $isPreview ? 'Staff (aperçu)' : (string)($share['destinataire_nom'] ?: 'Contributeur externe');
            $pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, canal, id_user, message)
                VALUES (?, 'user', 'feed', ?, ?)")
                ->execute([$idDossier, $createdBy, 'Dossier créé par ' . $auteur . '.' . ($creancier !== '' ? ' Créancier : ' . $creancier . '.' : '')]);
            $pdo->commit();
            $msg = 'Dossier créé.'; $msgType = 'success';
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $msg = 'Erreur : ' . $ex->getMessage(); $msgType = 'error';
        }
    }
}

// ── Liste des dossiers du propriétaire ───────────────────────────────
$stD = $pdo->prepare("
    SELECT d.id, d.code, d.libelle, d.statut, d.niveau_risque, d.debiteur_enerve, d.synthese, d.created_at,
           (SELECT COUNT(*) FROM creancier_echeance ce WHERE ce.id_dossier=d.id AND ce.statut='a_venir') AS nb_echeances,
           (SELECT COUNT(*) FROM creancier_dossier_message m WHERE m.id_dossier=d.id) AS nb_messages
    FROM creancier_dossier_lien dl
    JOIN creancier_dossier d ON d.id = dl.id_dossier
    WHERE (dl.entity_type='PROPRIETAIRE' AND dl.entity_id=?)
       OR (dl.entity_type='TIERS' AND dl.entity_id=?)   -- tiers du propriétaire (créancier/débiteur/garant)
    GROUP BY d.id
    ORDER BY (d.statut='clos') ASC, (d.niveau_risque='rouge') DESC, d.created_at DESC
");
$stD->execute([$proprioId, (int)($proprio['id_tiers'] ?? 0)]);
$dossiers = $stD->fetchAll(PDO::FETCH_ASSOC);

$riskLabel = ['rouge' => '🔴 Urgent', 'orange' => '🟠 À suivre', 'vert' => '🟢 Maîtrisé'];
$statutLabel = ['actif' => 'Actif', 'surveillance' => 'Surveillance', 'clos' => 'Clos'];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dossiers créanciers — <?= $e($proprio['nom']) ?></title>
<style>
  *{box-sizing:border-box;} body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#eef1f6;color:#1f2a44;}
  .wrap{max-width:960px;margin:0 auto;padding:22px 18px 60px;}
  .back{display:inline-block;color:#3a4b6e;text-decoration:none;font-size:.88em;margin-bottom:14px;}
  h1{font-size:1.3em;margin:0 0 4px;} .sub{color:#6b7796;font-size:.9em;margin-bottom:18px;}
  <?php if ($isPreview): ?>.band{background:#d4a047;color:#3a2a00;text-align:center;padding:6px;font-size:.82em;font-weight:700;}<?php endif; ?>
  .alert{border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.88em;}
  .alert.success{background:#e8f5e9;border-left:4px solid #2e7d32;color:#1b5e20;}
  .alert.error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;}
  .card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:14px;}
  .d-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #f0f2f7;}
  .d-row:last-child{border-bottom:none;}
  .d-main{flex:1;min-width:0;} .d-lib{font-weight:600;} .d-code{font-size:.76em;color:#9aa6bd;}
  .d-syn{font-size:.82em;color:#6b7796;margin-top:2px;}
  .chip{font-size:.76em;padding:3px 9px;border-radius:20px;white-space:nowrap;}
  .c-rouge{background:#fdecec;color:#b52a2a;} .c-orange{background:#fff4e5;color:#a15c00;} .c-vert{background:#e8f5e9;color:#1b5e20;}
  .c-clos{background:#eceff4;color:#6b7796;} .c-meta{color:#8592ad;font-size:.8em;}
  .empty{padding:26px;text-align:center;color:#9aa6bd;}
  .form-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:20px 22px;margin-top:8px;}
  .form-card h3{margin:0 0 14px;font-size:1em;}
  .fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
  .fg label{font-size:.82em;font-weight:600;color:#555;}
  .fg input[type=text]{border:1px solid #cdd4e0;border-radius:8px;padding:9px 12px;font-size:.9em;}
  .checks{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .chk{display:flex;align-items:center;gap:7px;border:1px solid #cdd4e0;border-radius:8px;padding:8px 12px;font-size:.84em;cursor:pointer;}
  .btn{background:#243B5C;color:#fff;border:none;border-radius:9px;padding:10px 20px;font-weight:700;font-size:.9em;cursor:pointer;}
</style>
</head>
<body>
<?php if ($isPreview): ?><div class="band">👁 APERÇU INTERNE — vue avocat « <?= $e($share['destinataire_nom']) ?> »</div><?php endif; ?>
<div class="wrap">
  <a class="back" href="<?= $e($backUrl) ?>">← Retour au patrimoine</a>
  <h1>⚖️ Dossiers créanciers</h1>
  <div class="sub"><?= $e($proprio['nom']) ?><?= $canWrite ? ' · <span style="color:#2f6d4a;font-weight:600;">contribution activée</span>' : ' · lecture seule' ?></div>

  <?php if ($msg): ?><div class="alert <?= $msgType ?>"><?= $e($msg) ?></div><?php endif; ?>

  <div class="card">
    <?php if (empty($dossiers)): ?>
      <div class="empty">Aucun dossier créancier sur ce propriétaire<?= $canWrite ? ' — créez-en un ci-dessous.' : '.' ?></div>
    <?php else:
        $dosBase = 'patrimoine_creancier_dossier.php?' . $subQS;
        foreach ($dossiers as $d):
        $risk = $d['niveau_risque']; $clos = $d['statut'] === 'clos'; ?>
    <a class="d-row" href="<?= $e($dosBase) ?>&dossier=<?= (int)$d['id'] ?>" style="text-decoration:none;color:inherit;">
      <div class="d-main">
        <div class="d-lib"><?= $e($d['libelle']) ?> <?php if ($d['debiteur_enerve']): ?><span title="Débiteur énervé">😤</span><?php endif; ?></div>
        <div class="d-code"><?= $e($d['code']) ?> · créé le <?= date('d/m/Y', strtotime($d['created_at'])) ?></div>
        <?php if (!empty($d['synthese'])): ?><div class="d-syn"><?= $e($d['synthese']) ?></div><?php endif; ?>
      </div>
      <span class="chip <?= $clos ? 'c-clos' : 'c-'.$e($risk) ?>"><?= $clos ? 'Clos' : ($riskLabel[$risk] ?? $risk) ?></span>
      <span class="c-meta"><?= (int)$d['nb_echeances'] ?> échéance(s) · <?= (int)$d['nb_messages'] ?> note(s)</span>
      <span style="color:#9aa6bd;">→</span>
    </a>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($canWrite): ?>
  <div class="form-card">
    <h3>➕ Créer un dossier créancier</h3>
    <form method="POST">
      <input type="hidden" name="action" value="create_dossier">
      <div class="fg">
        <label>Intitulé du dossier *</label>
        <input type="text" name="libelle" placeholder="Impayés de loyers – Assignation en cours" required>
      </div>
      <div class="fg">
        <label>Créancier(s) / parties</label>
        <input type="text" name="creancier" placeholder="Nom du ou des créanciers">
      </div>
      <div class="checks">
        <label class="chk"><input type="checkbox" name="urgent"> 🔴 Urgent</label>
        <label class="chk"><input type="checkbox" name="enerve"> 😤 Débiteur énervé</label>
      </div>
      <button type="submit" class="btn">Créer le dossier</button>
    </form>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
