<?php
/**
 * Composant de test de rôles - ADMIN UNIQUEMENT
 *
 * Permet aux admins de tester les différentes vues et restrictions
 * en changeant temporairement leur rôle visible dans la session.
 *
 * ⚠️ IMPORTANT:
 * - Ce changement est UNIQUEMENT en session (temporaire)
 * - Le vrai rôle en base de données n'est JAMAIS modifié
 * - Au logout ou rechargement de page, le rôle réel est restauré
 * - Invisible pour les non-admins
 */

// Vérifier que l'utilisateur est VRAIMENT un admin (pas un test role)
$realRole = (int)($_SESSION['id_role'] ?? 0);
if ($realRole !== 1) {
    return; // Visible uniquement pour les vrais admins
}

$currentTestRole = get_test_role();
$currentRole = current_role_id();
?>

<div style="display:flex;gap:6px;align-items:center">
    <span style="font-size:10px;color:var(--muted);font-weight:600;text-transform:uppercase">Test rôle:</span>
    <div style="display:flex;gap:4px">
        <button onclick="switchTestRole(1)" style="padding:4px 8px;font-size:11px;background:<?=$currentTestRole===1?'#4878a6':'rgba(72,120,166,0.12)'?>;color:<?=$currentTestRole===1?'#07121b':'#4878a6'?>;border:1px solid rgba(72,120,166,0.25);border-radius:4px;cursor:pointer;font-weight:600;transition:all 0.2s">Admin</button>
        <button onclick="switchTestRole(2)" style="padding:4px 8px;font-size:11px;background:<?=$currentTestRole===2?'#b78bff':'rgba(184,139,255,0.2)'?>;color:<?=$currentTestRole===2?'#07121b':'#b78bff'?>;border:1px solid rgba(184,139,255,0.4);border-radius:4px;cursor:pointer;font-weight:600;transition:all 0.2s">Manager</button>
        <button onclick="switchTestRole(3)" style="padding:4px 8px;font-size:11px;background:<?=$currentTestRole===3?'#4a6038':'rgba(124,245,214,0.2)'?>;color:<?=$currentTestRole===3?'#07121b':'#4a6038'?>;border:1px solid rgba(124,245,214,0.4);border-radius:4px;cursor:pointer;font-weight:600;transition:all 0.2s">User</button>
        <?php if ($currentTestRole > 0): ?>
            <button onclick="switchTestRole(0)" style="padding:4px 8px;font-size:11px;background:rgba(255,215,0,0.2);color:#ffd700;border:1px solid rgba(255,215,0,0.4);border-radius:4px;cursor:pointer;font-weight:600;transition:all 0.2s;margin-left:4px">Rôle réel</button>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTestRole(roleId) {
    fetch('/MaBoxImmo2026/public_html/api/set_test_role.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'role=' + roleId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Recharger la page pour voir les changements
            location.reload();
        } else {
            alert('Erreur: ' + data.message);
        }
    })
    .catch(e => alert('Erreur: ' + e.message));
}
</script>
