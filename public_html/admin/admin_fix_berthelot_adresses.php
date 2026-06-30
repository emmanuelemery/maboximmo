<?php
declare(strict_types=1);
/**
 * admin/admin_fix_berthelot_adresses.php
 * --------------------------------------
 * GESTION DES BIENS — copro BERTHELOT-ROY LYON 7 (décision : 1 immeuble, distinction au bien).
 * Tableau éditable, trié par référence (le n° de lot est le suffixe de reference_bien) :
 *   - éditer le TYPE de bien (id_type_bien ← bien_types)
 *   - éditer le STATUT : Occupé / Libre / Vendu
 *       Occupé → statut_occupation='occupé' · Libre → statut_occupation='vacant'
 *       Vendu  → statut_bien='vendu'
 *   - SUPPRIMER (soft : statut_bien='supprime', réversible, masqué du tableau)
 *
 * Conserve l'outil one-shot « réparer les adresses » (n° de rue saisis à tort dans etage
 * → adresse_1). Idempotent. Matché par NOM d'immeuble (rejouable en prod).
 *
 * Accès : super-admin (role 1).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin (role 1).'); }

$pdo = $GLOBALS['pdo'];
const IMM_NAME_LIKE = 'BERTHELOT- ROY%';
const ROY  = [2, 4];
const BERT = [77, 79, 81, 83, 85];

/* ── Réparation adresses (etage → adresse_1) ───────────────────────────── */
function fix_berthelot_plan(PDO $pdo): array {
    $st = $pdo->prepare(
        "SELECT b.id, b.etage FROM biens b JOIN immeubles i ON b.id_immeuble = i.id
          WHERE i.nom_immeuble LIKE ? AND b.etage IS NOT NULL");
    $st->execute([IMM_NAME_LIKE]);
    $plan = [];
    foreach ($st as $r) {
        $e = (int)$r['etage'];
        if     (in_array($e, ROY,  true)) $adr = "$e Rue Roy";
        elseif (in_array($e, BERT, true)) $adr = "$e Avenue Berthelot";
        else continue;
        $plan[] = ['id' => (int)$r['id'], 'new_adresse_1' => $adr];
    }
    return $plan;
}

$flash = null; $flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('berthelot_admin');
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'fix_adresses') {
            $plan = fix_berthelot_plan($pdo);
            $upd = $pdo->prepare("UPDATE biens SET adresse_1 = ?, etage = NULL WHERE id = ?");
            foreach ($plan as $p) $upd->execute([$p['new_adresse_1'], $p['id']]);
            $flash = '✅ ' . count($plan) . ' adresse(s) réparée(s).';

        } elseif ($action === 'save_row') {
            $id      = (int)($_POST['id'] ?? 0);
            $idType  = (int)($_POST['id_type_bien'] ?? 0);
            $statut  = (string)($_POST['statut'] ?? '');
            $adresse = trim((string)($_POST['adresse_1'] ?? ''));
            if ($id <= 0) throw new RuntimeException('id manquant');
            // Type (FK → types_bien_legacy)
            if ($idType > 0) $pdo->prepare("UPDATE biens SET id_type_bien = ? WHERE id = ?")->execute([$idType, $id]);
            // Adresse (entrée) — liste déroulante
            $pdo->prepare("UPDATE biens SET adresse_1 = ? WHERE id = ?")->execute([$adresse, $id]);
            // Statut : un seul sélecteur. On évite l'enum `statut_occupation` qui est
            // CORROMPU en base (le membre « occupé » est stocké en mojibake → toute
            // écriture propre échoue/truncate). On utilise le champ varchar propre
            // `occupation_bien` (libre/loue) + `statut_bien='vendu'`.
            if ($statut === 'occupe') {
                $pdo->prepare("UPDATE biens SET occupation_bien = 'loue', statut_bien = 'actif' WHERE id = ?")->execute([$id]);
            } elseif ($statut === 'libre') {
                $pdo->prepare("UPDATE biens SET occupation_bien = 'libre', statut_bien = 'actif' WHERE id = ?")->execute([$id]);
            } elseif ($statut === 'vendu') {
                $pdo->prepare("UPDATE biens SET statut_bien = 'vendu' WHERE id = ?")->execute([$id]);
            }
            $flash = "✅ Bien #$id mis à jour.";

        } elseif ($action === 'delete_row') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id manquant');
            $pdo->prepare("UPDATE biens SET statut_bien = 'supprime' WHERE id = ?")->execute([$id]);
            $flash = "🗑 Bien #$id supprimé (réversible : statut 'supprime').";
        }
    } catch (Throwable $e) {
        $flashErr = '⚠️ Erreur : ' . $e->getMessage();
    }
}

/* ── Données du tableau ────────────────────────────────────────────────── */
// ⚠️ id_type_bien a une FK vers types_bien_legacy (PAS bien_types) — on lit/écrit
// donc cette table, sinon violation de clé étrangère (erreur 500 à l'enregistrement).
$types = $pdo->query("SELECT id, libelle FROM types_bien_legacy WHERE actif = 1 ORDER BY ordre_affichage, libelle")->fetchAll(PDO::FETCH_KEY_PAIR);

// Entrées (adresses) de la copro — liste déroulante éditable. On part du canon
// + toute adresse_1 déjà présente en base (pour ne rien perdre).
$ADRESSES = ['2 Rue Roy', '4 Rue Roy',
             '77 Avenue Berthelot', '79 Avenue Berthelot', '81 Avenue Berthelot',
             '83 Avenue Berthelot', '85 Avenue Berthelot'];
$existAdr = $pdo->prepare("SELECT DISTINCT b.adresse_1 FROM biens b JOIN immeubles i ON b.id_immeuble=i.id
                            WHERE i.nom_immeuble LIKE ? AND b.adresse_1 IS NOT NULL AND b.adresse_1 <> ''");
$existAdr->execute([IMM_NAME_LIKE]);
foreach ($existAdr->fetchAll(PDO::FETCH_COLUMN) as $a) if (!in_array($a, $ADRESSES, true)) $ADRESSES[] = $a;

$st = $pdo->prepare(
    "SELECT b.id, b.reference_bien, b.id_type_bien, b.adresse_1, b.surface_habitable,
            b.occupation_bien, b.statut_bien
       FROM biens b JOIN immeubles i ON b.id_immeuble = i.id
      WHERE i.nom_immeuble LIKE ? AND COALESCE(b.statut_bien,'') <> 'supprime'
      ORDER BY b.reference_bien, b.id");
$st->execute([IMM_NAME_LIKE]);
$biens = $st->fetchAll(PDO::FETCH_ASSOC);

// Statut courant dérivé (vendu prioritaire, puis occupation via occupation_bien)
function statut_courant(array $b): string {
    if (($b['statut_bien'] ?? '') === 'vendu') return 'vendu';
    $o = strtolower((string)($b['occupation_bien'] ?? ''));
    if ($o === 'loue' || $o === 'loué' || $o === 'occupe' || $o === 'occupé') return 'occupe';
    if ($o === 'libre' || $o === 'vacant') return 'libre';
    return '';
}
// N° de lot = suffixe après le dernier tiret de la référence
function lot_from_ref(?string $ref): string {
    $ref = (string)$ref;
    if (strpos($ref, '-') === false) return '';
    return ltrim(substr($ref, strrpos($ref, '-') + 1), '0') ?: '0';
}

$csrf = csrf_token('berthelot_admin');
$nbAFixer = count(fix_berthelot_plan($pdo));
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestion biens BERTHELOT-ROY</title>
<style>
 body{font:14px/1.5 system-ui,sans-serif;max-width:1080px;margin:24px auto;padding:0 16px;color:#1e293b}
 h1{font-size:19px} h2{font-size:15px;margin-top:26px}
 table{border-collapse:collapse;width:100%;margin:12px 0;font-size:13px}
 th,td{border:1px solid #e2e8f0;padding:5px 8px;text-align:left;vertical-align:middle} th{background:#f1f5f9}
 select{padding:5px 7px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit;max-width:170px}
 .muted{color:#94a3b8} code{background:#f1f5f9;padding:1px 5px;border-radius:4px}
 .btn{background:#0e7490;color:#fff;border:none;border-radius:7px;padding:6px 12px;font-weight:700;cursor:pointer;font-size:12.5px}
 .btn-sm{padding:5px 9px;font-size:12px}
 .btn-del{background:#fff;color:#b91c1c;border:1px solid #fecaca}
 .banner{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:10px 14px;margin:12px 0;color:#065f46}
 .banner-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin:12px 0;color:#991b1b;font-family:monospace;font-size:12.5px;white-space:pre-wrap}
 .ref{font-family:monospace;font-weight:700;color:#334155}
 form.row{margin:0;display:flex;gap:6px;align-items:center}
 .pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
 .p-occupe{background:#fef3c7;color:#92400e}.p-libre{background:#dcfce7;color:#166534}.p-vendu{background:#e0e7ff;color:#3730a3}
</style></head><body>
<h1>🏠 Gestion des biens — copro BERTHELOT-ROY LYON 7</h1>
<?php if ($flash): ?><div class="banner"><?= h($flash) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="banner-err"><?= h($flashErr) ?></div><?php endif; ?>

<?php if ($nbAFixer > 0): ?>
<form method="post" onsubmit="return confirm('Réparer <?= $nbAFixer ?> adresses (n° de rue → adresse_1) ?');">
  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fix_adresses">
  <button class="btn" type="submit">🔧 Réparer <?= $nbAFixer ?> adresse(s) (n° de rue mal placés dans « etage »)</button>
</form>
<?php endif; ?>

<h2><?= count($biens) ?> bien(s) — triés par référence</h2>
<table>
  <tr><th>Réf</th><th>Lot</th><th>Surface</th><th>Adresse (entrée)</th><th>Type</th><th>Statut</th><th></th></tr>
  <?php foreach ($biens as $b): $cur = statut_courant($b); ?>
  <tr>
    <td class="ref"><?= h((string)$b['reference_bien']) ?></td>
    <td><strong><?= h(lot_from_ref($b['reference_bien'])) ?></strong></td>
    <td><?= $b['surface_habitable'] ? h((string)$b['surface_habitable']).' m²' : '<span class="muted">—</span>' ?></td>
    <td colspan="3">
      <form class="row" method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_row">
        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <select name="adresse_1">
          <option value="">— adresse —</option>
          <?php foreach ($ADRESSES as $adr): ?>
            <option value="<?= h($adr) ?>" <?= (string)$b['adresse_1'] === $adr ? 'selected' : '' ?>><?= h($adr) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="id_type_bien">
          <option value="">— type —</option>
          <?php foreach ($types as $tid => $tlib): ?>
            <option value="<?= (int)$tid ?>" <?= (int)$b['id_type_bien'] === (int)$tid ? 'selected' : '' ?>><?= h($tlib) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="statut">
          <option value="">— statut —</option>
          <option value="occupe" <?= $cur==='occupe'?'selected':'' ?>>Occupé</option>
          <option value="libre"  <?= $cur==='libre' ?'selected':'' ?>>Libre</option>
          <option value="vendu"  <?= $cur==='vendu' ?'selected':'' ?>>Vendu</option>
        </select>
        <button class="btn btn-sm" type="submit">💾</button>
      </form>
    </td>
    <td>
      <form method="post" style="margin:0" onsubmit="return confirm('Supprimer le bien <?= h((string)$b['reference_bien']) ?> ? (réversible)');">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete_row">
        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <button class="btn btn-sm btn-del" type="submit">🗑</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$biens): ?><tr><td colspan="7" class="muted">Aucun bien.</td></tr><?php endif; ?>
</table>
<p class="muted">Statut actuel :
  <span class="pill p-occupe">Occupé</span> = loué &nbsp;
  <span class="pill p-libre">Libre</span> = vacant &nbsp;
  <span class="pill p-vendu">Vendu</span>. La suppression met le statut à <code>supprime</code> (réversible en BDD).</p>
</body></html>
