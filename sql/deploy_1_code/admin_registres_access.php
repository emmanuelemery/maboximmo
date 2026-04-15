<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_admin();

$appLayout = true;
$pageTitle = 'Accès REGISTRE';
$bodyClass = ''; 
$robots = 'noindex, nofollow';

$errors = [];
$success = '';

if (is_post()) {
    $id = (int)post('id', 0);
    $action = trim((string)post('action', ''));
    verify_csrf('registres_admin_' . $id);

    if ($id <= 0 || !in_array($action, ['activate', 'refuse', 'reset'], true)) {
        $errors[] = 'Action invalide.';
    } else {
        $statut = match ($action) {
            'activate' => 'active',
            'refuse' => 'refused',
            default => 'inactive',
        };
        try {
            $stmt = $pdo->prepare("
                UPDATE registres_acces
                SET statut = :statut,
                    valide_le = CASE WHEN :statut = 'active' THEN NOW() ELSE NULL END
                WHERE id = :id
            ");
            $stmt->execute([
                ':statut' => $statut,
                ':id' => $id,
            ]);
            $success = 'Statut mis à jour.';
        } catch (Throwable $e) {
            $errors[] = 'Erreur lors de la mise à jour.';
        }
    }
}

$rows = [];
try {
    $rows = $pdo->query("
        SELECT r.*, s.nom AS societe_nom, s.email AS societe_email, s.telephone AS societe_tel
        FROM registres_acces r
        JOIN societes s ON s.id = r.id_societe
        ORDER BY r.demande_le DESC
    ")->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Table registres_acces introuvable. Appliquez le patch SQL.';
}

include __DIR__ . '/inc/header.php';
?>

<div class="app-shell">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>

    <div class="main-panel">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Accès REGISTRE</h1>
                <p>Validez les demandes d’accès payant.</p>
            </div>
            <div class="topbar-right">
                <a class="btn" href="<?= h(app_url('/agence_portail.php')) ?>">Espace agence</a>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if ($success): ?>
                <div class="message success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="message error"><?= h(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <div class="card">
                <h3>Demandes en cours</h3>
                <?php if (empty($rows)): ?>
                    <p class="muted">Aucune demande.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Société</th>
                                <th>Contact</th>
                                <th>Statut</th>
                                <th>Mode</th>
                                <th>Montant</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= h((string)$row['societe_nom']) ?></td>
                                    <td>
                                        <?= h((string)$row['societe_email']) ?><br>
                                        <?= h((string)$row['societe_tel']) ?>
                                    </td>
                                    <td><span class="badge <?= h((string)$row['statut']) ?>"><?= h((string)$row['statut']) ?></span></td>
                                    <td><?= h((string)($row['mode_paiement'] ?? '')) ?></td>
                                    <td><?= h((string)($row['montant'] ?? '')) ?> <?= h((string)($row['devise'] ?? '')) ?></td>
                                    <td>
                                        <form method="post" class="inline">
                                            <?= csrf_field('registres_admin_' . (int)$row['id']) ?>
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" name="action" value="activate" class="btn btn-primary">Activer</button>
                                            <button type="submit" name="action" value="refuse" class="btn">Refuser</button>
                                            <button type="submit" name="action" value="reset" class="btn">Réinitialiser</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>

<style>
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:12px 10px;border-bottom:1px solid #ffffff;text-align:left;font-size:14px}
.inline{display:flex;gap:8px;flex-wrap:wrap}
.badge{padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;display:inline-flex}
.badge.active{background:rgba(124,245,214,0.18);color:#b6ffe7;border:1px solid rgba(124,245,214,0.35)}
.badge.pending{background:rgba(255,212,121,0.2);color:#ffe1a3;border:1px solid rgba(255,212,121,0.35)}
.badge.refused{background:rgba(255,122,122,0.18);color:#ffd0d0;border:1px solid rgba(255,122,122,0.35)}
.badge.inactive{background:#ffffff;color:#cbd5f5;border:1px solid #ffffff}
.muted{color:#a8b4c7}
</style>

