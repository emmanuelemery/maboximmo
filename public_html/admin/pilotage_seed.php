<?php
declare(strict_types=1);

/**
 * admin/pilotage_seed.php — Initialisation du Service Location EN 2 TEMPS :
 *   1) Prévisualisation : société ciblée, postes → comptes MBI (suggestion),
 *      missions déjà présentes vs référentiel, affectations existantes conservées.
 *   2) Confirmation explicite : crée le référentiel (idempotent) puis applique le
 *      mapping poste→user (jamais destructif, ne réécrase pas une personnalisation).
 *
 * Réservé admin / super admin.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage_seed.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
$targetSociete = (int)($_GET['societe'] ?? $_POST['id_societe'] ?? current_societe_id() ?? 0);

$done = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    verify_csrf('pilotage_seed');
    if ($targetSociete <= 0) {
        $err = 'Société cible invalide.';
    } else {
        try {
            $pdo->beginTransaction();
            $seed = pilotage_seed_referentiel($pdo, $targetSociete);          // référentiel (idempotent)
            $map = [];
            foreach ((array)($_POST['map'] ?? []) as $poste => $uids) {
                $list = array_values(array_filter(array_map('intval', (array)$uids), fn($v) => $v > 0));
                if ($list) $map[(string)$poste] = $list; // PLUSIEURS collaborateurs par poste
            }
            $applied = pilotage_apply_poste_mapping($pdo, $targetSociete, $map); // affectations réelles
            $pdo->commit();
            $done = ['seed' => $seed, 'applied' => $applied];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

// Données de prévisualisation
$preview = $targetSociete > 0 ? pilotage_seed_preview($pdo, $targetSociete) : null;

// Utilisateurs de la société, GROUPÉS PAR AGENCE (pour cocher plusieurs comptes / poste)
$usersByAgence = []; // [ ['agence'=>nom, 'users'=>[...] ], ... ]
if ($targetSociete > 0) {
    $st = $pdo->prepare("SELECT u.id, u.prenom, u.nom, u.fonction, u.id_agence,
                                COALESCE(a.nom_agence, a.ville, CONCAT('Agence #', u.id_agence)) AS agence_nom
                         FROM users u LEFT JOIN agences a ON a.id = u.id_agence
                         WHERE u.id_societe=? AND u.actif=1
                         ORDER BY agence_nom, u.prenom, u.nom");
    $st->execute([$targetSociete]);
    $grp = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $key = $u['agence_nom'] ?: 'Sans agence';
        $grp[$key][] = $u;
    }
    foreach ($grp as $ag => $us) $usersByAgence[] = ['agence' => $ag, 'users' => $us];
}

$societes = [];
try { $societes = $pdo->query("SELECT id, nom FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Initialisation Service Location</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
 body{font-family:system-ui,Segoe UI,sans-serif;max-width:860px;margin:34px auto;padding:0 20px;color:#243B5C;background:#f7f9fb}
 h1{font-size:1.25rem} h2{font-size:1rem;margin-top:0}
 .card{background:#fff;border:1px solid #e6ebf0;border-radius:14px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:18px}
 .ok{background:#ecfdf5;border:1px solid #a7f3d0;padding:14px;border-radius:10px}
 .err{background:#fef2f2;border:1px solid #fecaca;padding:14px;border-radius:10px;color:#991b1b}
 .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
 .stat{background:#f1f5f9;border-radius:10px;padding:10px 14px}
 .stat b{display:block;font-size:1.3rem}
 table{border-collapse:collapse;width:100%} th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef2f5;font-size:.9rem}
 select{padding:7px;border-radius:8px;border:1px solid #cbd5e1;font-size:.9rem;width:100%}
 button{background:#84A7AB;color:#fff;border:0;border-radius:10px;padding:11px 20px;font-size:1rem;cursor:pointer;font-weight:600}
 button.sec{background:#fff;color:#334155;border:1px solid #cbd5e1;font-weight:400}
 .found{color:#2FA36B} .nf{color:#DD4735}
 .muted{color:#64748b;font-size:.85rem} a{color:#5c8388}
 .poste-block{border:1px solid #e6ebf0;border-radius:12px;padding:12px 14px;margin-bottom:12px;background:#fbfcfd}
 .poste-h{font-weight:700;margin-bottom:8px}
 .ag-h{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#8a5f22;font-weight:700;margin:8px 0 5px}
 .chk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px}
 .chk{display:flex;align-items:center;gap:7px;padding:6px 9px;border:1px solid #d6e0e6;border-radius:8px;font-size:.86rem;cursor:pointer;background:#fff}
 .chk.on{border-color:#84A7AB;background:#eef5f6}
 .chk input{margin:0}
</style>
<script>
 document.addEventListener('change',function(e){ if(e.target.matches('.chk input')) e.target.closest('.chk').classList.toggle('on', e.target.checked); });
</script></head><body>
<h1>🔑 Initialisation du Service Location</h1>

<?php if ($err): ?><div class="card err">Erreur : <?= h($err) ?></div><?php endif; ?>

<?php if ($done): ?>
  <div class="card ok">
    <h2>✅ Initialisation confirmée — société #<?= (int)$targetSociete ?></h2>
    <div class="grid">
      <div class="stat"><b><?= (int)$done['seed']['tasks'] ?></b>missions créées (0 si déjà présentes)</div>
      <div class="stat"><b><?= (int)$done['applied']['assignments_created'] ?></b>affectations créées</div>
      <div class="stat"><b><?= (int)$done['applied']['mapped'] ?></b>postes mappés</div>
      <div class="stat"><b><?= (int)$done['applied']['skipped_existing_primary'] ?></b>personnalisations conservées</div>
    </div>
    <p style="margin-top:12px">→ <a href="<?= h(app_url('/pilotage_service_location.php')) ?>">Ouvrir la page Service Location</a></p>
  </div>
<?php endif; ?>

<form method="get" class="card">
  <h2>Société cible</h2>
  <div style="display:flex;gap:10px;align-items:center">
    <select name="societe" onchange="this.form.submit()">
      <?php if ($societes): foreach ($societes as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $targetSociete ? 'selected' : '') ?>>#<?= (int)$s['id'] ?> — <?= h($s['nom'] ?? '') ?></option>
      <?php endforeach; else: ?>
        <option value="<?= (int)$targetSociete ?>">#<?= (int)$targetSociete ?></option>
      <?php endif; ?>
    </select>
    <button type="submit" class="sec">Prévisualiser</button>
  </div>
</form>

<?php if ($preview): ?>
<form method="post" class="card">
  <input type="hidden" name="action" value="confirm">
  <input type="hidden" name="id_societe" value="<?= (int)$targetSociete ?>">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token('pilotage_seed')) ?>">

  <h2>Prévisualisation — société #<?= (int)$targetSociete ?></h2>
  <div class="grid" style="margin-bottom:16px">
    <div class="stat"><b><?= (int)$preview['existing_tasks'] ?></b>missions déjà présentes</div>
    <div class="stat"><b><?= (int)$preview['tasks_in_referential'] ?></b>missions au référentiel (Location)</div>
    <div class="stat"><b class="found"><?= (int)$preview['found_count'] ?></b>postes avec compte trouvé</div>
    <div class="stat"><b class="<?= $preview['not_found_count'] ? 'nf' : 'found' ?>"><?= (int)$preview['not_found_count'] ?></b>postes sans compte</div>
  </div>
  <p class="muted">Affectations existantes conservées : <b><?= (int)$preview['existing_assignments_preserved'] ?></b>. L'initialisation est <b>idempotente</b> et n'écrase aucune affectation personnalisée.</p>

  <h2 style="margin-top:18px">Correspondance poste → comptes MBI</h2>
  <p class="muted">Cochez <b>un ou plusieurs</b> collaborateurs par poste (les comptes sont regroupés par agence). Un poste peut être tenu par plusieurs personnes de la société.</p>
  <?php foreach ($preview['postes'] as $p):
        $mapped = array_map('intval', $p['mapped_user_ids'] ?? []);
        $suggest = (int)($p['suggested_user_id'] ?? 0); ?>
    <div class="poste-block">
      <div class="poste-h"><?= h($p['poste_label']) ?> <span class="muted"><?= h($p['poste_code']) ?></span></div>
      <?php foreach ($usersByAgence as $grp): ?>
        <div class="ag-h"><?= h($grp['agence']) ?></div>
        <div class="chk-grid">
        <?php foreach ($grp['users'] as $u):
              $id = (int)$u['id'];
              $checked = in_array($id, $mapped, true) || ($id === $suggest && $id > 0);
              $lbl = trim($u['prenom'].' '.$u['nom']); ?>
          <label class="chk<?= $checked ? ' on' : '' ?>">
            <input type="checkbox" name="map[<?= h($p['poste_code']) ?>][]" value="<?= $id ?>" <?= $checked ? 'checked' : '' ?>>
            <?= h($lbl) ?><?= $u['fonction'] ? ' <span class="muted">· '.h($u['fonction']).'</span>' : '' ?>
          </label>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($mapped) && !$suggest): ?><span class="muted nf">aucun compte suggéré — à cocher manuellement</span><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <p class="muted" style="margin-top:14px">Les suggestions (pré-cochées) proviennent d'une recherche par prénom mais ne sont <b>jamais</b> appliquées automatiquement : c'est votre confirmation qui crée les affectations, sur l'identifiant réel des comptes cochés. Rejouable sans doublon.</p>

  <div style="margin-top:16px">
    <button type="submit">Confirmer l'initialisation du Service Location</button>
  </div>
</form>
<?php endif; ?>

</body></html>
