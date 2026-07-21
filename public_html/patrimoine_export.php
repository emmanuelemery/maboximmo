<?php
/**
 * patrimoine_export.php — Export Excel (CSV UTF-8 ; ) de la liste patrimoine
 * d'UN propriétaire, depuis un partage à jeton (notaire, propriétaire, comptable)
 * ou en aperçu staff. Mêmes biens que la page (patrimoine actif, dédup, type/surface),
 * colonnes limitées aux autorisations du partage.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/patrimoine_partage_auth.php';
require_once __DIR__ . '/inc/patrimoine_partage_data.php';

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
[$share, $isPreview, $canWrite] = pp_resolve_share($pdo);

// Périmètre : le propriétaire doit appartenir au compte bailleur du partage.
$proprioId = (int)($_GET['proprio'] ?? 0);
$stChk = $pdo->prepare("SELECT COUNT(*) FROM user_proprietaires WHERE id_user=? AND id_proprietaire=?");
$stChk->execute([(int)$share['id_user_bailleur'], $proprioId]);
if (!$proprioId || !(int)$stChk->fetchColumn()) {
    pp_auth_stop('Hors périmètre', 'Ce propriétaire n\'est pas accessible depuis ce lien.');
}
$proprioNom = (string)($pdo->query("SELECT COALESCE(NULLIF(societe,''),TRIM(CONCAT_WS(' ',prenom,nom))) FROM proprietaires WHERE id=" . $proprioId)->fetchColumn() ?: 'proprietaire');

// Scénario autorisé
$scenAllowed = array_values(array_filter(array_map('trim', explode(',', (string)$share['scenario_code'])))) ?: ['courant'];
$scenReq = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['scenario'] ?? '')));
$scenSel = in_array($scenReq, $scenAllowed, true) ? $scenReq : $scenAllowed[0];

// Flags colonnes
$showDesc  = (int)$share['montrer_descriptif'];
$showLoc   = (int)$share['montrer_locataire'];
$showLoyer = (int)$share['montrer_loyer'];
$showPrix  = (int)$share['montrer_prix_vente'];

// Données (identiques à la page)
$details = pp_load_details($pdo, 'AND ct.id_proprietaire IN (' . $proprioId . ')', $scenSel);
$biens   = pp_dedup_biens($details[$proprioId] ?? []);

// ── Sortie CSV UTF-8 (BOM) séparateur ';' → Excel FR ─────────────────
$slug = preg_replace('/[^a-z0-9]+/i', '_', $proprioNom);
$fname = 'patrimoine_' . trim($slug, '_') . '_' . date('Ymd_Hi') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
$sep = ';';
$num = fn($v) => $v > 0 ? number_format((float)$v, 2, ',', ' ') : '';

fputcsv($out, ['Patrimoine — ' . $proprioNom], $sep);
fputcsv($out, ['Scénario : ' . $scenSel . '  ·  Export du ' . date('d/m/Y H:i')], $sep);
fputcsv($out, [], $sep);

// En-têtes
$head = ['Référence', 'Type'];
if ($showDesc) { $head[] = 'Adresse'; $head[] = 'Ville'; }
$head[] = 'Surface (m²)';
if ($showLoc)   $head[] = 'Locataire';
if ($showLoyer) $head[] = 'Loyer/mois (€)';
if ($showPrix)  $head[] = 'Prix de vente (€)';
fputcsv($out, $head, $sep);

$totLoyer = 0.0; $totPrix = 0.0;
foreach ($biens as $d) {
    [$icon, $typeLbl, $cat] = pp_batiment($d);
    $vacant = !empty($d['hors_crg']) || $d['presence'] !== 'present';
    $surf = (float)($d['surface'] ?? 0);
    $loyer = pp_loyer_mois($d); $prix = pp_prix($d);
    $totLoyer += $loyer; $totPrix += $prix;

    $row = [ (string)($d['reference_bien'] ?: ''), $typeLbl ];
    if ($showDesc) {
        $row[] = trim((string)($d['bien_adresse'] ?? '')) ?: trim((string)($d['imm_adresse'] ?? ''));
        $row[] = trim((string)($d['bien_ville'] ?? ''))   ?: trim((string)($d['imm_ville'] ?? ''));
    }
    $row[] = $surf > 0 ? number_format($surf, 2, ',', ' ') : '';
    if ($showLoc)   $row[] = $vacant ? 'Vacant' : (string)$d['locataire_nom'];
    if ($showLoyer) $row[] = $num($loyer);
    if ($showPrix)  $row[] = $num($prix);
    fputcsv($out, $row, $sep);
}

// Ligne total (alignée aux en-têtes)
$tot = ['TOTAL (' . count($biens) . ' bien' . (count($biens) > 1 ? 's' : '') . ')'];
$tot[] = ''; // Type
if ($showDesc) { $tot[] = ''; $tot[] = ''; } // Adresse, Ville
$tot[] = ''; // Surface
if ($showLoc)   $tot[] = '';
if ($showLoyer) $tot[] = $num($totLoyer);
if ($showPrix)  $tot[] = $num($totPrix);
fputcsv($out, [], $sep);
fputcsv($out, $tot, $sep);

fclose($out);
exit;
