<?php
declare(strict_types=1);
/**
 * tools/bien_types_audit.php — AUDIT LECTURE SEULE du type de bien (aucune écriture).
 *
 * Compare, pour chaque bien, le type dans les 3 sources :
 *   - bien_types        (via biens.id_bien_type)   = MODERNE / source de vérité Ubiflow
 *   - types_bien_legacy (via biens.id_type_bien)   = legacy lu par l'EXPORT Ubiflow
 *   - base_types_bien   (via biens.id_type_bien)   = legacy lu par les LISTES/diffusion
 *
 * ⚠️ Les deux tables legacy ont des id incompatibles (1↔2 inversés) → source des
 * affichages « Maison » alors qu'Ubiflow envoie « Appartement ».
 *
 * Usage navigateur (super-admin) : /tools/bien_types_audit.php
 * Option : &all=1 pour lister TOUS les biens (par défaut : seulement les problématiques).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
header('Content-Type: text/html; charset=utf-8');
if (!(function_exists('is_super_admin') && is_super_admin())) { http_response_code(403); echo 'Réservé super-admin.'; exit; }

$pdo = $GLOBALS['pdo'];
$showAll = (($_GET['all'] ?? '') === '1');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$sql = "SELECT b.id, b.reference_bien,
               b.id_bien_type, bt.code  AS modern_code, bt.code_type_ubiflow AS modern_ubi,
               b.id_type_bien, tbl.code AS ubi_legacy_code, base.code AS disp_legacy_code
        FROM biens b
        LEFT JOIN bien_types bt        ON bt.id   = b.id_bien_type
        LEFT JOIN types_bien_legacy tbl ON tbl.id = b.id_type_bien
        LEFT JOIN base_types_bien base  ON base.id = b.id_type_bien
        WHERE (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
        ORDER BY b.id";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$stats = ['total'=>0, 'ok'=>0, 'null_modern'=>0, 'conflict_legacy'=>0, 'conflict_modern'=>0, 'no_type'=>0];
$problems = [];
foreach ($rows as $r) {
    $stats['total']++;
    $modern = $r['modern_code'];              // bien_types (id_bien_type)
    $ubiLeg = $r['ubi_legacy_code'];           // types_bien_legacy (Ubiflow)
    $dispLeg = $r['disp_legacy_code'];         // base_types_bien (affichage)
    $flags = [];

    if ($modern === null && $r['id_type_bien'] === null) { $flags[] = 'AUCUN_TYPE'; $stats['no_type']++; }
    if ($modern === null && $r['id_type_bien'] !== null) { $flags[] = 'MODERNE_NULL'; $stats['null_modern']++; }
    if ($ubiLeg !== null && $dispLeg !== null && $ubiLeg !== $dispLeg) { $flags[] = 'CONFLIT_LEGACY(affichage≠ubiflow)'; $stats['conflict_legacy']++; }
    if ($modern !== null && $ubiLeg !== null && $modern !== $ubiLeg)   { $flags[] = 'CONFLIT_MODERNE≠UBIFLOW'; $stats['conflict_modern']++; }
    // Type Ubiflow réellement émis (comme build_ubiflow_annonce) : moderne prioritaire sinon legacy Ubiflow.
    $typeUbiflowEmis = $modern ?? $ubiLeg ?? '(vide → SKIP)';

    if (!$flags) { $stats['ok']++; }
    else { $problems[] = $r + ['_flags'=>$flags, '_emis'=>$typeUbiflowEmis]; }
}

echo '<!doctype html><meta charset="utf-8"><style>'
   . 'body{font-family:-apple-system,Segoe UI,sans-serif;margin:22px;color:#1f2937;}'
   . 'h1{font-size:20px}h2{font-size:15px;margin-top:22px}'
   . 'table{border-collapse:collapse;width:100%;font-size:12.5px;margin-top:10px}'
   . 'th,td{border:1px solid #e5e7eb;padding:5px 8px;text-align:left}th{background:#f3f4f6}'
   . '.kpi{display:inline-block;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px 16px;margin:4px 8px 4px 0}'
   . '.kpi b{font-size:20px;display:block}.warn{color:#b91c1c;font-weight:700}.ok{color:#15803d}'
   . 'code{background:#f1f5f9;padding:1px 5px;border-radius:4px}'
   . '</style>';
echo '<h1>🔍 Audit type de bien — 3 tables (lecture seule)</h1>';
echo '<div>'
   . '<span class="kpi">Biens<b>' . $stats['total'] . '</b></span>'
   . '<span class="kpi ok">Cohérents<b>' . $stats['ok'] . '</b></span>'
   . '<span class="kpi">Moderne NULL<b class="warn">' . $stats['null_modern'] . '</b></span>'
   . '<span class="kpi">Conflit legacy (affichage≠Ubiflow)<b class="warn">' . $stats['conflict_legacy'] . '</b></span>'
   . '<span class="kpi">Conflit moderne≠Ubiflow<b class="warn">' . $stats['conflict_modern'] . '</b></span>'
   . '<span class="kpi">Aucun type<b class="warn">' . $stats['no_type'] . '</b></span>'
   . '</div>';
echo '<p style="color:#64748b;font-size:12.5px">Source de vérité recommandée = <code>bien_types</code> (id_bien_type), déjà prioritaire pour Ubiflow. '
   . 'Colonne « Ubiflow émis » = ce que l\'export envoie réellement. '
   . ($showAll ? '<a href="?">→ voir seulement les problèmes</a>' : '<a href="?all=1">→ tout lister</a>') . '</p>';

$list = $showAll ? array_map(fn($r)=>$r + ['_flags'=>[], '_emis'=>($r['modern_code'] ?? $r['ubi_legacy_code'] ?? '(vide)')], $rows) : $problems;
echo '<h2>' . ($showAll ? 'Tous les biens' : 'Biens problématiques') . ' (' . count($list) . ')</h2>';
echo '<table><tr><th>id</th><th>réf</th><th>id_bien_type</th><th>MODERNE (bien_types)</th><th>ubi (code)</th>'
   . '<th>id_type_bien</th><th>UBIFLOW legacy (types_bien_legacy)</th><th>AFFICHAGE legacy (base_types_bien)</th>'
   . '<th>➡ Ubiflow émis</th><th>Problèmes</th></tr>';
foreach ($list as $r) {
    echo '<tr>'
       . '<td>' . (int)$r['id'] . '</td>'
       . '<td><code>' . $h($r['reference_bien'] ?? '—') . '</code></td>'
       . '<td>' . $h($r['id_bien_type'] ?? 'NULL') . '</td>'
       . '<td>' . $h($r['modern_code'] ?? '—') . '</td>'
       . '<td>' . $h($r['modern_ubi'] ?? '—') . '</td>'
       . '<td>' . $h($r['id_type_bien'] ?? 'NULL') . '</td>'
       . '<td>' . $h($r['ubi_legacy_code'] ?? '—') . '</td>'
       . '<td>' . $h($r['disp_legacy_code'] ?? '—') . '</td>'
       . '<td><b>' . $h($r['_emis']) . '</b></td>'
       . '<td class="warn">' . $h(implode(' · ', $r['_flags'])) . '</td>'
       . '</tr>';
}
echo '</table>';
