<?php
/**
 * creancier_dashboard_test.php — PAGE JETABLE (lecture seule).
 *
 * Affiche brut le retour de creancier_urgence_data() pour valider la base technique
 * du module CRÉANCIERS. Hors règles UX. À SUPPRIMER après validation.
 *
 * Usage : /creancier_dashboard_test.php?id_dossier=1
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$idDossier = (int)($_GET['id_dossier'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><meta charset='utf-8'><title>TEST cockpit créanciers</title>";
echo "<body style='font-family:monospace;background:#0f172a;color:#e2e8f0;padding:24px'>";
echo "<h2>⚠️ Page de test JETABLE — creancier_urgence_data()</h2>";

// Liste des dossiers accessibles (debug).
$st = $pdo->query("SELECT id, code, libelle, statut, niveau_risque FROM creancier_dossier ORDER BY id");
echo "<p>Dossiers : ";
foreach ($st as $d) {
    echo "<a style='color:#fbbf24' href='?id_dossier={$d['id']}'>#{$d['id']} {$d['code']}</a> &nbsp; ";
}
echo "</p>";

if ($idDossier <= 0) { echo "<p>Choisis un dossier ci-dessus.</p></body>"; exit; }

$data = creancier_urgence_data($pdo, $idDossier);

if (!$data['acces']) {
    echo "<p style='color:#f87171'>Accès refusé (ACL / tenant) pour le dossier #$idDossier.</p></body>";
    exit;
}

echo "<pre style='white-space:pre-wrap;background:#1e293b;padding:16px;border-radius:8px'>";
echo htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "</pre></body>";
