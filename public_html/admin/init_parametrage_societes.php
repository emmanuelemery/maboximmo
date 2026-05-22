<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/societe_duplication.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$results = [];
$societes = $pdo->query("SELECT id, nom FROM societes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($societes as $s) {
    $sid = (int)$s['id'];
    // Vérifie si déjà initialisé
    $count = (int)$pdo->query("SELECT COUNT(*) FROM societe_vues WHERE id_societe = $sid")->fetchColumn();
    if ($count > 0) {
        $results[] = ['id' => $sid, 'nom' => $s['nom'], 'status' => 'skipped', 'msg' => "Déjà initialisée ($count vues)."];
        continue;
    }
    try {
        dupliquerParametrageSociete($pdo, $sid);
        $nb = (int)$pdo->query("SELECT COUNT(*) FROM societe_vues WHERE id_societe = $sid")->fetchColumn();
        $results[] = ['id' => $sid, 'nom' => $s['nom'], 'status' => 'ok', 'msg' => "Initialisée ($nb vues créées)."];
    } catch (Throwable $e) {
        $results[] = ['id' => $sid, 'nom' => $s['nom'], 'status' => 'error', 'msg' => $e->getMessage()];
    }
}
?>
<!doctype html><html lang="fr"><head><meta charset="UTF-8"><title>Init paramétrage</title>
<style>body{font-family:monospace;background:#111;color:#ddd;padding:30px;}
.ok{color:#6ddc97;} .error{color:#ff8f8f;} .skipped{color:#888;}
table{border-collapse:collapse;width:100%;}td,th{padding:8px 14px;border-bottom:1px solid #333;text-align:left;}
</style></head><body>
<h2>Initialisation du paramétrage pour les sociétés existantes</h2>
<table>
  <tr><th>ID</th><th>Société</th><th>Résultat</th></tr>
  <?php foreach ($results as $r): ?>
  <tr class="<?= h($r['status']) ?>">
    <td><?= (int)$r['id'] ?></td>
    <td><?= h($r['nom']) ?></td>
    <td><?= $r['status'] === 'ok' ? '✅' : ($r['status'] === 'skipped' ? '⏭' : '❌') ?> <?= h($r['msg']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p style="margin-top:20px;color:#888;">Ce fichier peut être supprimé après exécution.</p>
</body></html>
