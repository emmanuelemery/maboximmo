<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Validation du compte';
$bodyClass = '';
$robots = 'noindex, nofollow';

$token = trim((string)get('token', ''));
$status = 'error';
$message = 'Lien de validation invalide.';

if ($token !== '') {
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT ev.id, ev.user_id, ev.expires_at, ev.used_at, u.id_societe, u.id_agence
        FROM email_verifications ev
        JOIN users u ON u.id = ev.user_id
        WHERE ev.token_hash = :token_hash
        LIMIT 1
    ");
    $stmt->execute([':token_hash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $usedAt = $row['used_at'] ?? null;
        $expiresAt = (string)($row['expires_at'] ?? '');
        $now = date('Y-m-d H:i:s');

        if ($usedAt !== null) {
            $message = 'Ce lien a déjà été utilisé.';
        } elseif ($expiresAt !== '' && $expiresAt < $now) {
            $message = 'Ce lien de validation a expiré.';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE users
                    SET actif = 1, email_verified_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => (int)$row['user_id']]);

                $pdo->prepare("
                    UPDATE email_verifications
                    SET used_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => (int)$row['id']]);

                $societeId = (int)($row['id_societe'] ?? 0);
                $agenceId = (int)($row['id_agence'] ?? 0);

                if ($societeId > 0) {
                    $pdo->prepare("UPDATE societes SET actif = 1 WHERE id = :id")
                        ->execute([':id' => $societeId]);
                }
                if ($agenceId > 0) {
                    $pdo->prepare("UPDATE agences SET actif = 1 WHERE id = :id")
                        ->execute([':id' => $agenceId]);
                }

                $pdo->commit();

                $status = 'success';
                $message = 'Votre compte est activé. Vous pouvez maintenant vous connecter.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Erreur lors de la validation du compte.';
            }
        }
    } else {
        $message = 'Lien de validation introuvable.';
    }
}

include __DIR__ . '/inc/header.php';
?>

<section class="verify-wrap">
    <div class="verify-card">
        <h1><?= $status === 'success' ? 'Compte activé' : 'Validation du compte' ?></h1>
        <p><?= h($message) ?></p>
        <div class="verify-actions">
            <a href="<?= h(app_url('/login.php')) ?>" class="btn-primary">Se connecter</a>
            <a href="<?= h(app_url('/default.php')) ?>" class="btn-outline">Retour accueil</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>

<style>
.verify-wrap{max-width:760px;margin:0 auto;padding:24px 0}
.verify-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(15,23,42,0.08)}
.verify-card h1{margin:0 0 10px;font-size:28px;color:#17324d}
.verify-card p{margin:0 0 20px;color:#475569}
.verify-actions{display:flex;gap:12px;flex-wrap:wrap}
</style>
