<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function require_arbitrage_access(): void
{
    require_login();

    $roleId = current_role_id();
    if (in_array($roleId, [1, 2, 7], true)) {
        return;
    }

    // Permettre l'accès aux users "gestion locative" SIR
    if (!empty($_SESSION['code_acces']) && strtoupper((string)$_SESSION['code_acces']) === 'SIR') {
        return;
    }

    // Fallback : charger code_acces (certaines pages ne le mettent pas en session)
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT code_acces, super_admin FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([current_user_id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['code_acces'] = (string)($row['code_acces'] ?? '');
            if (!empty($row['super_admin'])) {
                $_SESSION['super_admin'] = true;
                return;
            }
            if (strtoupper((string)($row['code_acces'] ?? '')) === 'SIR') {
                return;
            }
        }
    }

    deny_access('Accès réservé (module Arbitrage Patrimonial).');
}

