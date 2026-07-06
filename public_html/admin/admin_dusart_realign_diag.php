<?php
/**
 * admin/admin_dusart_realign_diag.php — DIAGNOSTIC (lecture seule).
 *
 * Rapproche les lots « SCN-DUSART-XXX » (placeholders portant une valeur du
 * scénario Dusart) d'un VRAI bien actif, par ADRESSE normalisée. Ne modifie rien.
 *
 * But : savoir si on peut ré-aligner Dusart sur les biens actifs.
 * ?scenario=dusart (défaut). Accès super-admin.
 */
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors', '1');
$IncDir = is_dir(__DIR__ . '/inc') ? __DIR__ . '/inc' : __DIR__ . '/../inc';
require_once $IncDir . '/bootstrap.php';
require_once $IncDir . '/auth.php';
require_login();
$pdo = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin.'); }

$scenario = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['scenario'] ?? 'dusart'))) ?: 'dusart';
header('Content-Type: text/plain; charset=utf-8');
echo "=== DIAGNOSTIC RÉ-ALIGNEMENT « $scenario » → biens actifs (par adresse) ===\n\n";

// Normalisation d'adresse : minuscule, sans accents, sans mots de type de voie, alphanum.
function nrm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','û'=>'u','ü'=>'u','œ'=>'oe']);
    $s = preg_replace('/\b(avenue|av|rue|r|boulevard|bd|cours|chemin|ch|place|pl|impasse|imp|allee|allée|route|rte|quai|bis|ter)\b/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
// Clé « voie » = adresse normalisée SANS les mots de la ville (pour matcher malgré « … Lyon » en trop).
function nrm_voie(string $adr, string $ville): string {
    $a = nrm($adr); $v = nrm($ville);
    if ($v !== '') { foreach (explode(' ', $v) as $w) { if (strlen($w) >= 3) $a = trim(preg_replace('/\b' . preg_quote($w, '/') . '\b/', ' ', $a)); } }
    return trim(preg_replace('/\s+/', ' ', $a));
}

// Lots placeholders du scénario
$stP = $pdo->prepare("SELECT b.id, b.reference_bien, b.adresse_1, b.ville, b.id_proprietaire, bp.montant
                      FROM bien_prix bp JOIN biens b ON b.id = bp.id_bien
                      WHERE bp.scenario_code=? AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.montant>0
                        AND b.reference_bien LIKE 'SCN-%'
                      ORDER BY b.reference_bien");
$stP->execute([$scenario]);
$placeholders = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Candidats : vrais biens actifs (ref non SCN), avec adresse (bien ou immeuble)
$cands = $pdo->query("
    SELECT b.id, b.reference_bien, b.id_proprietaire,
           COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adr,
           COALESCE(NULLIF(b.ville,''), i.ville) AS ville
    FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
    WHERE (b.reference_bien IS NULL OR b.reference_bien NOT LIKE 'SCN-%')
      AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
      AND COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Décompose une adresse en [numéro, set de mots de rue] (sans type de voie ni accents).
function adr_parts(string $adr, string $ville): array {
    $k = nrm_voie($adr, $ville);
    $toks = $k === '' ? [] : explode(' ', $k);
    $num = ''; $words = [];
    foreach ($toks as $t) { if ($num === '' && preg_match('/^\d+$/', $t)) $num = $t; elseif (strlen($t) >= 2) $words[] = $t; }
    return [$num, array_values(array_unique($words))];
}
// Prépare les candidats
foreach ($cands as &$c) { [$c['_num'], $c['_w']] = adr_parts((string)$c['adr'], (string)$c['ville']); }
unset($c);

$nMatch1 = $nMatchN = $nNo = 0;
foreach ($placeholders as $p) {
    [$pnum, $pw] = adr_parts((string)$p['adresse_1'], (string)$p['ville']);
    $hits = [];
    if ($pnum !== '' && $pw) {
        foreach ($cands as $c) {
            if ($c['_num'] !== $pnum || !$c['_w']) continue;
            // mots de rue : l'un inclus dans l'autre (gère la ville en trop d'un côté)
            $inter = array_intersect($pw, $c['_w']);
            $sub = (count($inter) === count($pw)) || (count($inter) === count($c['_w']));
            if ($sub && $inter) $hits[] = $c;
        }
    }
    // même propriétaire prioritaire
    $samep = array_filter($hits, fn($c) => (int)$c['id_proprietaire'] === (int)$p['id_proprietaire']);
    $use = $samep ?: $hits;
    $tag = $p['reference_bien'] . ' (' . trim((string)$p['adresse_1'] . ' ' . $p['ville']) . ') → ' . number_format((float)$p['montant'],0,',',' ') . ' €';
    if (count($use) === 1) {
        $c = array_values($use)[0];
        echo "  ✅ $tag  ⇒  bien réel #{$c['id']} {$c['reference_bien']}" . ($samep?'':' [prop≠]') . "\n";
        $nMatch1++;
    } elseif (count($use) > 1) {
        echo "  ⚠️ $tag  ⇒  " . count($use) . " candidats (#" . implode(',#', array_map(fn($c)=>$c['id'], array_slice($use,0,4))) . ") — à trancher\n";
        $nMatchN++;
    } else {
        echo "  ·  $tag  ⇒  aucun bien à cette adresse\n";
        $nNo++;
    }
}

echo "\n=== BILAN ===\n";
echo "Lots placeholders '$scenario' : " . count($placeholders) . "\n";
echo "  ✅ match unique (ré-alignables) : $nMatch1\n";
echo "  ⚠️ plusieurs candidats (à trancher) : $nMatchN\n";
echo "  ·  aucun match adresse : $nNo\n";
echo "\n(lecture seule — rien n'a été modifié)\n";
