<?php
/**
 * ADMIN — Schéma OneDrive par agence (multi-racines) + cache dossier par propriétaire.
 *
 * Permet de renseigner, pour chaque agence, la/les racine(s) OneDrive (base_path + mode
 * propriétaire/immeuble + label), afin que la résolution du dossier d'un propriétaire ne
 * cherche plus dans la mauvaise agence (« dossier introuvable »).
 * Permet aussi de purger le cache `proprietaire_onedrive` (dossiers mémorisés) pour forcer
 * une nouvelle résolution.
 *
 * URL : /admin/admin_onedrive_schema.php   (super admin role=1)
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
if (current_role_id() !== 1) { http_response_code(403); exit('Accès admin uniquement.'); }

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf_any('onedrive_schema');
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $code  = trim((string)($_POST['code_agence'] ?? ''));
            $label = trim((string)($_POST['label'] ?? 'principal')) ?: 'principal';
            $base  = trim(str_replace('\\', '/', (string)($_POST['base_path'] ?? '')), '/ ');
            $mode  = ($_POST['mode'] ?? 'par_proprietaire') === 'par_immeuble' ? 'par_immeuble' : 'par_proprietaire';
            $drive = trim((string)($_POST['drive_user'] ?? '')) ?: null;
            $actif = isset($_POST['actif']) ? 1 : 0;
            if ($code === '' || $base === '') { $msg = '❌ code_agence et base_path requis.'; }
            elseif ($id > 0) {
                $pdo->prepare("UPDATE agence_onedrive_schema SET code_agence=?,label=?,base_path=?,mode=?,drive_user=?,actif=? WHERE id=?")
                    ->execute([$code,$label,$base,$mode,$drive,$actif,$id]);
                $msg = '✓ Racine #'.$id.' mise à jour.';
            } else {
                $pdo->prepare("INSERT INTO agence_onedrive_schema (code_agence,label,base_path,mode,drive_user,actif) VALUES (?,?,?,?,?,?)")
                    ->execute([$code,$label,$base,$mode,$drive,$actif]);
                $msg = '✓ Racine ajoutée.';
            }
        } elseif ($act === 'del_schema') {
            $pdo->prepare("DELETE FROM agence_onedrive_schema WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = '✓ Racine supprimée.';
        } elseif ($act === 'clear_cache') {
            $pid = (int)($_POST['id_proprietaire'] ?? 0);
            if ($pid > 0) { $pdo->prepare("DELETE FROM proprietaire_onedrive WHERE id_proprietaire=?")->execute([$pid]); $msg = '✓ Cache propriétaire #'.$pid.' purgé.'; }
            else { $pdo->exec("DELETE FROM proprietaire_onedrive"); $msg = '✓ Tout le cache propriétaires purgé.'; }
        }
    } catch (Throwable $e) { $msg = '❌ '.$e->getMessage(); }
}

$csrf    = function_exists('csrf_token') ? csrf_token('onedrive_schema') : '';
$schemas = $pdo->query("SELECT s.*, (SELECT GROUP_CONCAT(a.nom_agence) FROM agences a WHERE a.code_agence=s.code_agence) nom_age
                        FROM agence_onedrive_schema s ORDER BY s.code_agence, s.id")->fetchAll(PDO::FETCH_ASSOC);
$cache   = $pdo->query("SELECT c.*, COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) nom
                        FROM proprietaire_onedrive c LEFT JOIN proprietaires p ON p.id=c.id_proprietaire
                        ORDER BY c.resolved_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$agences = $pdo->query("SELECT code_agence, nom_agence FROM agences WHERE code_agence<>'' ORDER BY code_agence")->fetchAll(PDO::FETCH_ASSOC);
function h2($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Schéma OneDrive par agence</title>
<style>
 body{font-family:system-ui,Segoe UI,sans-serif;padding:24px;background:#f1f5f9;color:#0f172a;max-width:1100px;margin:auto}
 h1{font-size:20px}h2{font-size:16px;margin-top:28px}
 table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden;font-size:13px}
 td,th{border:1px solid #e2e8f0;padding:7px 9px;text-align:left;vertical-align:top}
 th{background:#243B5C;color:#fff}
 input,select{padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;box-sizing:border-box}
 .msg{padding:10px 14px;border-radius:8px;background:#ecfdf5;border:1px solid #a7f3d0;margin:12px 0;font-weight:600}
 .btn{background:#243B5C;color:#fff;border:none;border-radius:6px;padding:7px 12px;font-weight:700;cursor:pointer}
 .btn.red{background:#b91c1c}.btn.sm{padding:4px 9px;font-size:12px}
 code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:12px}
 .card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-top:14px}
 .mode-imm{color:#7c3aed;font-weight:700}.mode-pro{color:#0e7490;font-weight:700}
</style></head><body>
<h1>📁 Schéma OneDrive par agence</h1>
<p style="color:#475569;font-size:13.5px">Chaque agence peut avoir <b>plusieurs racines</b> (ex. LYON = propriétaires + diagnostics). <code>par_proprietaire</code> = un dossier par propriétaire · <code>par_immeuble</code> = un dossier par immeuble. La résolution mémorise le dossier trouvé dans le cache ci-dessous.</p>
<?php if ($msg): ?><div class="msg"><?= h2($msg) ?></div><?php endif; ?>

<h2>Racines configurées</h2>
<table>
 <tr><th>#</th><th>Agence</th><th>Label</th><th>Base path</th><th>Mode</th><th>Drive user</th><th>Actif</th><th></th></tr>
 <?php foreach ($schemas as $s): ?>
 <tr>
   <form method="post"><input type="hidden" name="csrf_token" value="<?= h2($csrf) ?>"><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
   <td><?= (int)$s['id'] ?></td>
   <td><input name="code_agence" value="<?= h2($s['code_agence']) ?>" size="7" list="agl"><div style="color:#64748b;font-size:11px"><?= h2($s['nom_age']) ?></div></td>
   <td><input name="label" value="<?= h2($s['label']) ?>" size="12"></td>
   <td><input name="base_path" value="<?= h2($s['base_path']) ?>" style="width:340px"></td>
   <td><select name="mode"><option value="par_proprietaire"<?= $s['mode']==='par_proprietaire'?' selected':'' ?>>propriétaire</option><option value="par_immeuble"<?= $s['mode']==='par_immeuble'?' selected':'' ?>>immeuble</option></select></td>
   <td><input name="drive_user" value="<?= h2($s['drive_user']) ?>" size="14" placeholder="(défaut)"></td>
   <td style="text-align:center"><input type="checkbox" name="actif" <?= (int)$s['actif']?'checked':'' ?>></td>
   <td style="white-space:nowrap"><button class="btn sm">💾</button></form>
       <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette racine ?')"><input type="hidden" name="csrf_token" value="<?= h2($csrf) ?>"><input type="hidden" name="act" value="del_schema"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn sm red">✕</button></form></td>
 </tr>
 <?php endforeach; ?>
</table>
<datalist id="agl"><?php foreach ($agences as $a): ?><option value="<?= h2($a['code_agence']) ?>"><?= h2($a['nom_agence']) ?></option><?php endforeach; ?></datalist>

<div class="card">
 <h2 style="margin-top:0">➕ Ajouter une racine</h2>
 <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
   <input type="hidden" name="csrf_token" value="<?= h2($csrf) ?>"><input type="hidden" name="act" value="save">
   <input name="code_agence" placeholder="code agence" size="8" list="agl" required>
   <input name="label" placeholder="label (ex. diagnostics)" size="14" required>
   <input name="base_path" placeholder="01_SERVICE_GESTION/…" style="width:320px" required>
   <select name="mode"><option value="par_proprietaire">propriétaire</option><option value="par_immeuble">immeuble</option></select>
   <input name="drive_user" placeholder="drive user (option)" size="14">
   <label><input type="checkbox" name="actif" checked> actif</label>
   <button class="btn">Ajouter</button>
 </form>
</div>

<h2>Cache dossiers résolus par propriétaire <span style="font-weight:400;color:#64748b">(200 derniers)</span></h2>
<form method="post" style="margin-bottom:8px">
  <input type="hidden" name="csrf_token" value="<?= h2($csrf) ?>"><input type="hidden" name="act" value="clear_cache">
  <button class="btn red" onclick="return confirm('Purger TOUT le cache ?')">🗑️ Purger tout le cache</button>
</form>
<table>
 <tr><th>Proprio</th><th>Schéma</th><th>Dossier mémorisé</th><th>Source</th><th>Le</th><th></th></tr>
 <?php foreach ($cache as $c): ?>
 <tr>
   <td>#<?= (int)$c['id_proprietaire'] ?> · <?= h2($c['nom']) ?></td>
   <td><?= (int)$c['schema_id'] ?></td>
   <td><code><?= h2($c['folder_path']) ?></code></td>
   <td><?= $c['source']==='manuel'?'✍️ manuel':'🤖 auto' ?></td>
   <td style="font-size:11.5px;color:#64748b"><?= h2($c['resolved_at']) ?></td>
   <td><form method="post" onsubmit="return confirm('Purger le cache de ce propriétaire ?')"><input type="hidden" name="csrf_token" value="<?= h2($csrf) ?>"><input type="hidden" name="act" value="clear_cache"><input type="hidden" name="id_proprietaire" value="<?= (int)$c['id_proprietaire'] ?>"><button class="btn sm red">✕</button></form></td>
 </tr>
 <?php endforeach; ?>
 <?php if (!$cache): ?><tr><td colspan="6" style="color:#94a3b8">Aucun dossier mémorisé pour l'instant.</td></tr><?php endif; ?>
</table>
</body></html>
