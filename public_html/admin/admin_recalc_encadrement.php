<?php
/**
 * admin/admin_recalc_encadrement.php — Recalcul EN MASSE de l'encadrement des loyers
 * sur toutes les annonces actives, d'après le CP courant (immeuble prioritaire).
 *
 * Pourquoi : les annonces sont diffusées (portails/Ubiflow). Si une adresse change
 * sans réouverture de la fiche, le loyer de référence majoré reste FAUX en diffusion.
 * Ce script réaligne tout :
 *   - CP en zone encadrée → applique zone + loyer réf/max/min + active l'encadrement ;
 *   - CP hors zone        → désactive l'encadrement et purge les loyers de référence.
 *
 * Sécurité : super-admin uniquement. DRY-RUN par défaut ; ?apply=1 pour écrire.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/encadrement_helper.php';
require_login();

$pdo = $GLOBALS['pdo'] ?? db();
if (!function_exists('is_super_admin') || !is_super_admin()) {
    http_response_code(403); exit('Accès réservé au super-admin.');
}
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Recalcul encadrement</title>';
echo '<style>body{font-family:system-ui,Arial;margin:24px;color:#1f2937}h1{font-size:20px}'
   . 'table{border-collapse:collapse;margin-top:14px;font-size:13px}td,th{border:1px solid #e5e7eb;padding:5px 9px}'
   . '.k{background:#eef2ff} .ok{color:#15803d} .ko{color:#b91c1c} .btn{display:inline-block;margin-top:14px;padding:10px 16px;'
   . 'background:#1a237e;color:#fff;text-decoration:none;border-radius:8px;font-weight:700}</style>';
echo '<h1>Recalcul de l\'encadrement des loyers — ' . ($apply ? '<span class="ko">APPLICATION</span>' : 'DRY-RUN (simulation)') . '</h1>';

$rows = $pdo->query("
    SELECT a.id AS aid, a.id_bien, a.zone_encadrement_loyer AS zon,
           b.enc_loyer_max, b.enc_zone,
           COALESCE(NULLIF(i.code_postal,''), b.code_postal) AS cp
    FROM annonces a
    JOIN biens b ON b.id = a.id_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    WHERE (a.etat_publication IS NULL OR a.etat_publication <> 'archive')
")->fetchAll(PDO::FETCH_ASSOC);

$nApplied = 0; $nCleared = 0; $nSkip = 0; $nErr = 0;
foreach ($rows as $r) {
    $aid  = (int)$r['aid'];
    $cp   = trim((string)($r['cp'] ?? ''));
    $zone = $cp !== '' ? enc_cp_to_zone($cp) : null;
    if ($zone !== null) {
        if ($apply) {
            $res = enc_auto_apply($pdo, $aid);
            if (!empty($res['ok'])) $nApplied++; else $nErr++;
        } else {
            $nApplied++;   // serait (re)calculé
        }
    } else {
        // Hors zone encadrée : désactiver si encore actif / valeurs présentes
        $needClear = (int)($r['zon'] ?? 0) === 1 || $r['enc_loyer_max'] !== null;
        if ($needClear) {
            if ($apply) {
                $pdo->prepare("UPDATE biens SET enc_loyer_ref=NULL, enc_loyer_max=NULL, enc_loyer_min=NULL WHERE id=?")->execute([(int)$r['id_bien']]);
                $pdo->prepare("UPDATE annonces SET zone_encadrement_loyer=0 WHERE id=?")->execute([$aid]);
            }
            $nCleared++;
        } else {
            $nSkip++;
        }
    }
}

echo '<table>';
echo '<tr class="k"><th>Annonces actives</th><th>En zone → (re)appliquées</th><th>Hors zone → désactivées</th><th>Inchangées</th><th>Erreurs</th></tr>';
echo '<tr><td>' . count($rows) . '</td><td class="ok">' . $nApplied . '</td><td>' . $nCleared . '</td><td>' . $nSkip . '</td><td class="' . ($nErr ? 'ko' : '') . '">' . $nErr . '</td></tr>';
echo '</table>';

if (!$apply) {
    echo '<p>Ceci est une <b>simulation</b> (aucune écriture). Pour appliquer réellement :</p>';
    echo '<a class="btn" href="?apply=1" onclick="return confirm(\'Appliquer le recalcul de l\\\'encadrement sur toutes les annonces actives ?\')">✅ Appliquer le recalcul</a>';
} else {
    echo '<p class="ok"><b>Recalcul appliqué.</b> Les annonces diffusées portent désormais l\'encadrement de leur zone réelle.</p>';
    echo '<p>Pense à <b>re-synchroniser la diffusion (Ubiflow/portails)</b> si nécessaire pour propager les nouveaux loyers de référence.</p>';
}
