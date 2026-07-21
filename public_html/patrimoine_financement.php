<?php
/**
 * patrimoine_financement.php — Financements d'un propriétaire, vus depuis un
 * partage patrimoine (jeton comptable) ou en aperçu staff.
 *
 * Miroir de patrimoine_creancier.php, mais pour les EMPRUNTS DU BAILLEUR
 * (crédits d'acquisition / trésorerie), saisis par le comptable.
 * Lecture pour tous ; ÉCRITURE (créer) si niveau_acces='contribution' ou staff.
 * Se branche sur le socle Financement existant (fin_dossier + fin_dossier_lien).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/patrimoine_partage_auth.php';

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$e   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';

[$share, $isPreview, $canWrite] = pp_resolve_share($pdo);
if ((int)($share['montrer_financements'] ?? 0) !== 1) {
    pp_auth_stop('Non autorisé', 'Les financements ne sont pas partagés sur ce lien.');
}

// ── Périmètre ────────────────────────────────────────────────────────
$isAdmin   = !empty($_GET['admin']);
$proprioId = (int)($_GET['proprio'] ?? 0);
if (!pp_perimeter_ok($pdo, $share, $proprioId)) {
    pp_auth_stop('Hors périmètre', 'Ce propriétaire n\'est pas accessible depuis ce lien.');
}
$pr = $pdo->prepare("SELECT id, COALESCE(NULLIF(societe,''),TRIM(CONCAT_WS(' ',prenom,nom))) AS nom, id_agence, id_tiers
                     FROM proprietaires WHERE id=?");
$pr->execute([$proprioId]);
$proprio = $pr->fetch(PDO::FETCH_ASSOC);
if (!$proprio) pp_auth_stop('Introuvable', 'Propriétaire introuvable.');
$idSociete = (int)($pdo->query("SELECT id_societe FROM agences WHERE id=" . (int)$proprio['id_agence'])->fetchColumn() ?: 0) ?: null;

$subQS   = pp_sub_qs($share, $isPreview, $isAdmin, (string)($_GET['t'] ?? ''));
$backUrl = 'patrimoine_partage.php?' . $subQS;

$msg = ''; $msgType = '';
$createdBy = ($isPreview && function_exists('current_user_id')) ? (int)current_user_id() : null;

// ── Création d'un financement ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_fin') {
    if (!$canWrite) pp_auth_stop('Lecture seule', 'Vous n\'avez pas le droit de créer un financement.');
    $libelle   = trim((string)($_POST['libelle'] ?? ''));
    $organisme = trim((string)($_POST['organisme'] ?? '')) ?: null;
    $montant   = ($_POST['montant_total'] ?? '') !== '' ? (float)str_replace([' ', ','], ['', '.'], $_POST['montant_total']) : null;
    if ($libelle === '') { $msg = 'Intitulé obligatoire.'; $msgType = 'error'; }
    else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO fin_dossier
                (id_societe, id_agence, type, libelle, organisme, montant_total, solde_restant,
                 id_tiers, statut, confidentialite, created_by)
                VALUES (?,?, 'financement_bancaire', ?,?,?,?, ?, 'en_cours', 'confidentiel', ?)")
                ->execute([$idSociete, (int)$proprio['id_agence'], $libelle, $organisme, $montant, $montant,
                           (int)$proprio['id_tiers'] ?: null, $createdBy]);
            $idDossier = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO fin_dossier_lien (id_societe, id_dossier, entity_type, entity_id, role_lien, created_by)
                           VALUES (?, ?, 'PROPRIETAIRE', ?, 'proprietaire_concerne', ?)")
                ->execute([$idSociete, $idDossier, $proprioId, $createdBy]);
            $pdo->commit();
            $_SESSION['pf_flash'] = 'Financement créé. Complétez-le (montant, mensualités, biens, échéance).';
            header('Location: patrimoine_financement_dossier.php?' . $subQS . '&dossier=' . $idDossier);
            exit;
        } catch (Throwable $ex) { $pdo->rollBack(); $msg = 'Erreur : ' . $ex->getMessage(); $msgType = 'error'; }
    }
}

// ── Liste des financements du propriétaire ───────────────────────────
$stD = $pdo->prepare("
    SELECT d.id, d.libelle, d.organisme, d.montant_total, d.mensualite, d.solde_restant,
           d.date_echeance, d.statut,
           (SELECT COUNT(*) FROM fin_dossier_lien l WHERE l.id_dossier=d.id AND l.entity_type='BIEN') AS nb_biens
    FROM fin_dossier d
    WHERE d.id_tiers = ?
       OR EXISTS (SELECT 1 FROM fin_dossier_lien l WHERE l.id_dossier=d.id
                   AND ((l.entity_type='PROPRIETAIRE' AND l.entity_id=?)
                        OR (l.entity_type='TIERS' AND l.entity_id=?)))
    ORDER BY (d.statut='clos') ASC, d.date_echeance ASC, d.id DESC
");
$stD->execute([(int)($proprio['id_tiers'] ?? 0), $proprioId, (int)($proprio['id_tiers'] ?? 0)]);
$dossiers = $stD->fetchAll(PDO::FETCH_ASSOC);

$dosBase = 'patrimoine_financement_dossier.php?' . $subQS;
$statutLbl = ['ouvert'=>'Ouvert','en_cours'=>'En cours','suspendu'=>'Suspendu','clos'=>'Soldé'];
?>
<!doctype html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Financements — <?= $e($proprio['nom']) ?></title>
<style>
  *{box-sizing:border-box;} body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#eef1f6;color:#1f2a44;}
  .wrap{max-width:960px;margin:0 auto;padding:22px 18px 60px;}
  .back{display:inline-block;color:#3a4b6e;text-decoration:none;font-size:.88em;margin-bottom:14px;}
  h1{font-size:1.3em;margin:0 0 4px;} .sub{color:#6b7796;font-size:.9em;margin-bottom:18px;}
  <?php if ($isPreview): ?>.band{background:#d4a047;color:#3a2a00;text-align:center;padding:6px;font-size:.82em;font-weight:700;}<?php endif; ?>
  .alert{border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.88em;}
  .alert.success{background:#e8f5e9;border-left:4px solid #2e7d32;color:#1b5e20;} .alert.error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;}
  .card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:14px;}
  .d-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #f0f2f7;text-decoration:none;color:inherit;}
  .d-row:last-child{border-bottom:none;} .d-row:hover{background:#fafbfe;}
  .d-main{flex:1;min-width:0;} .d-lib{font-weight:600;} .d-meta{font-size:.8em;color:#8592ad;}
  .num{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap;}
  .chip{font-size:.76em;padding:3px 9px;border-radius:20px;white-space:nowrap;}
  .c-en_cours,.c-ouvert,.c-suspendu{background:#e9f2ee;color:#1f6b4e;} .c-clos{background:#eceff4;color:#6b7796;}
  .empty{padding:26px;text-align:center;color:#9aa6bd;}
  .form-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:20px 22px;margin-top:8px;}
  .form-card h3{margin:0 0 14px;font-size:1em;}
  .fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
  .fg label{font-size:.82em;font-weight:600;color:#555;}
  .fg input{border:1px solid #cdd4e0;border-radius:8px;padding:9px 12px;font-size:.9em;}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .btn{background:#1f6b4e;color:#fff;border:none;border-radius:9px;padding:10px 20px;font-weight:700;font-size:.9em;cursor:pointer;}
</style></head>
<body>
<?php if ($isPreview): ?><div class="band">👁 APERÇU INTERNE — vue comptable « <?= $e($share['destinataire_nom']) ?> »</div><?php endif; ?>
<div class="wrap">
  <a class="back" href="<?= $e($backUrl) ?>">← Retour au patrimoine</a>
  <h1>💶 Financements</h1>
  <div class="sub"><?= $e($proprio['nom']) ?><?= $canWrite ? ' · <span style="color:#1f6b4e;font-weight:600;">contribution activée</span>' : ' · lecture seule' ?></div>

  <?php if (!empty($_SESSION['pf_flash'])): $msg = $_SESSION['pf_flash']; $msgType = 'success'; unset($_SESSION['pf_flash']); endif; ?>
  <?php if ($msg): ?><div class="alert <?= $msgType ?>"><?= $e($msg) ?></div><?php endif; ?>

  <div class="card">
    <?php if (empty($dossiers)): ?>
      <div class="empty">Aucun financement sur ce propriétaire<?= $canWrite ? ' — créez-en un ci-dessous.' : '.' ?></div>
    <?php else: foreach ($dossiers as $d): $clos = $d['statut'] === 'clos'; ?>
    <a class="d-row" href="<?= $e($dosBase) ?>&dossier=<?= (int)$d['id'] ?>">
      <div class="d-main">
        <div class="d-lib"><?= $e($d['libelle']) ?></div>
        <div class="d-meta">
          <?= $d['organisme'] ? $e($d['organisme']) . ' · ' : '' ?>
          <?= (int)$d['nb_biens'] ?> bien(s)<?= $d['date_echeance'] ? ' · échéance ' . date('d/m/Y', strtotime($d['date_echeance'])) : '' ?>
        </div>
      </div>
      <div class="num">
        <?php if ($d['solde_restant'] !== null): ?><div><strong><?= $eur($d['solde_restant']) ?></strong> restant</div><?php endif; ?>
        <?php if ($d['mensualite'] !== null): ?><div class="d-meta"><?= $eur($d['mensualite']) ?>/mois</div><?php endif; ?>
      </div>
      <span class="chip c-<?= $e($d['statut']) ?>"><?= $statutLbl[$d['statut']] ?? $d['statut'] ?></span>
      <span style="color:#9aa6bd;">→</span>
    </a>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($canWrite): ?>
  <div class="form-card">
    <h3>➕ Créer un financement</h3>
    <form method="POST">
      <input type="hidden" name="action" value="create_fin">
      <div class="fg"><label>Intitulé *</label><input type="text" name="libelle" placeholder="Crédit acquisition 12 rue X / Ligne de trésorerie" required></div>
      <div class="grid2">
        <div class="fg"><label>Organisme prêteur</label><input type="text" name="organisme" placeholder="Banque…"></div>
        <div class="fg"><label>Montant emprunté (€)</label><input type="text" name="montant_total" placeholder="250000"></div>
      </div>
      <button type="submit" class="btn">Créer &amp; compléter</button>
    </form>
  </div>
  <?php endif; ?>
</div>
</body></html>
