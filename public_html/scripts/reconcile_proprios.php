<?php
/**
 * reconcile_proprios.php — RAPPORT de réconciliation (LECTURE SEULE).
 *
 * Rapproche les propriétaires EXISTANTS (sans code_compte) des comptes CRG
 * (par nom), pour préparer le stamping de code_compte AVANT l'import et
 * éviter ainsi tout doublon. N'ÉCRIT RIEN. Ne touche pas aux annonces.
 *
 * Sortie : tableau console + CSV (scripts/reconcile_proprios.csv).
 * Confiance :
 *   FORT   = nom de famille identique ET un prénom commun
 *   MOYEN  = nom de famille identique seulement
 *   (les FORT pourront être stampés ; les MOYEN sont à valider à la main)
 *
 * Usage : php reconcile_proprios.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

// Mode : 'stamp' écrit le code_compte sur les matches éligibles (FORT + société 2).
$STAMP = (($argv[1] ?? '') === 'stamp');
const SOCIETE_EMERY = 2; // EMERY IMMO (Riom/Chamalières) — cible des CRG

$python = 'C:/Users/emery/AppData/Local/Python/bin/python3.exe';
$base_dir = 'D:/CRG EMERY IMMO';
$jsonl = __DIR__ . '/parse_crg_batch.php_out.jsonl';

if (!is_file($jsonl)) {
    echo "Parsing des CRG…\n";
    shell_exec(escapeshellarg($python) . ' ' . escapeshellarg(__DIR__ . '/parse_crg_batch.py')
        . ' ' . escapeshellarg($base_dir) . ' ' . escapeshellarg($jsonl) . ' 2>nul');
}

/** Squelette : MAJ sans accents/ponctuation, pour comparer. */
function skel(string $s): string {
    $s = strtr($s, ['À'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C']);
    $s = strtoupper($s);
    return preg_replace('/[^A-Z0-9 ]/', '', $s);
}
/** Renvoie [surnameSkel, set de tokens] d'un nom CRG complet. */
function crg_tokens(string $raw): array {
    $civ = '/^(M\.\s*(?:ET|OU)\s*MME|MR\s*(?:ET|OU)\s*MME|MONSIEUR\s*(?:ET|OU)\s*MADAME|MADEMOISELLE|MONSIEUR|MADAME|MLLE|MME|MR|M\.|ENTREPRISE|SCI|SARL|SAS|EURL|SCM|SNC|SA)\s+/u';
    $s = skel($raw);
    $s = preg_replace($civ, '', $s);
    $toks = array_values(array_filter(explode(' ', $s)));
    return [$toks[0] ?? '', $toks];
}

// ── CRG : compte → nom complet ────────────────────────────────────
$crg = [];
foreach (file($jsonl) as $l) {
    $d = json_decode($l, true);
    if (!$d || empty($d['meta']['compte']) || empty($d['meta']['proprietaire'])) continue;
    if (!($d['meta']['trimestre'] ?? 0)) continue; // ignore les relevés sans trimestre
    $crg[$d['meta']['compte']] = $d['meta']['proprietaire'];
}

// ── Proprios existants SANS code_compte (+ leur agence/société) ───
$existing = $pdo->query("SELECT p.id, p.nom, p.prenom, p.societe, p.code_compte, p.id_agence, ag.id_societe
    FROM proprietaires p LEFT JOIN agences ag ON ag.id = p.id_agence
    WHERE (p.code_compte IS NULL OR p.code_compte='')")->fetchAll(PDO::FETCH_ASSOC);

// proprios ayant une annonce (priorité)
$withAnnonce = $pdo->query("SELECT DISTINCT b.id_proprietaire FROM biens b JOIN annonces a ON a.id_bien=b.id WHERE b.id_proprietaire IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$withAnnonce = array_flip(array_map('intval', $withAnnonce));

$rows = [];
foreach ($existing as $e) {
    $eName = trim(($e['nom'] ?? '') . ' ' . ($e['societe'] ?? ''));
    if (skel($eName) === '') continue;
    [$eSur, $eToks] = crg_tokens($eName);
    $ePren = skel((string)($e['prenom'] ?? ''));
    if ($ePren !== '') { $eToks = array_merge($eToks, explode(' ', $ePren)); }
    if ($eSur === '' || strlen($eSur) < 3) continue;

    $best = null;
    foreach ($crg as $compte => $cname) {
        [$cSur, $cToks] = crg_tokens($cname);
        if ($cSur !== $eSur) continue;
        $shared = array_intersect(array_filter($eToks, fn($t)=>strlen($t)>=2), array_filter($cToks, fn($t)=>strlen($t)>=2));
        // retire le nom de famille du calcul de prénom commun
        $sharedPrenom = array_diff($shared, [$eSur]);
        $conf = !empty($sharedPrenom) ? 'FORT' : 'MOYEN';
        if (!$best || ($conf === 'FORT' && $best['conf'] === 'MOYEN')) {
            $best = ['compte' => $compte, 'cname' => $cname, 'conf' => $conf];
        }
    }
    if ($best) {
        $soc = (int)($e['id_societe'] ?? 0);
        // Éligible au stamping : match FORT ET propriétaire sur EMERY IMMO (société 2).
        // (un homonyme sur Lyon/société 1 ou un prénom différent reste séparé)
        $eligible = ($best['conf'] === 'FORT' && $soc === SOCIETE_EMERY);
        $rows[] = [
            'id' => $e['id'],
            'existant' => $eName,
            'annonce' => isset($withAnnonce[(int)$e['id']]) ? 'OUI' : '',
            'soc' => $soc ?: '-',
            'compte' => $best['compte'],
            'crg' => $best['cname'],
            'conf' => $best['conf'],
            'eligible' => $eligible,
        ];
    }
}

// Garde-fou collision : un compte ne doit être stampé que sur UN seul proprio.
$comptePerEligible = [];
foreach ($rows as $r) { if ($r['eligible']) $comptePerEligible[$r['compte']] = ($comptePerEligible[$r['compte']] ?? 0) + 1; }
foreach ($rows as &$r) {
    if ($r['eligible'] && ($comptePerEligible[$r['compte']] ?? 0) > 1) { $r['eligible'] = false; $r['conf'] .= '*COLLISION'; }
}
unset($r);

// tri : éligibles d'abord, puis annonce, puis confiance
usort($rows, fn($a,$b)=> [$b['eligible']?1:0,$b['annonce'],$b['conf']] <=> [$a['eligible']?1:0,$a['annonce'],$a['conf']]);

$csvPath = __DIR__ . '/reconcile_proprios.csv';
$csv = @fopen($csvPath, 'w');
if ($csv === false) { $csvPath = sys_get_temp_dir() . '/reconcile_proprios.csv'; $csv = @fopen($csvPath, 'w'); }
if ($csv) fputcsv($csv, ['id_proprio','nom_existant','a_annonce','societe','compte_crg','nom_crg','confiance','eligible_stamp']);
$nElig=0;
printf("%-6s %-30s %-4s %-4s %-9s %-30s %-7s %s\n", 'ID','EXISTANT','ANN','SOC','COMPTE','CRG','CONF','STAMP');
foreach ($rows as $r) {
    if ($csv) fputcsv($csv, [$r['id'],$r['existant'],$r['annonce'],$r['soc'],$r['compte'],$r['crg'],$r['conf'],$r['eligible']?'OUI':'']);
    if ($r['eligible']) $nElig++;
    printf("%-6s %-30s %-4s %-4s %-9s %-30s %-7s %s\n",
        $r['id'], mb_substr($r['existant'],0,30), $r['annonce'], $r['soc'], $r['compte'], mb_substr($r['crg'],0,30), $r['conf'], $r['eligible']?'→ STAMP':'');
}
if ($csv) fclose($csv);

// ── Stamping (mode 'stamp' uniquement) : UPDATE non destructif ────
$stamped = 0;
if ($STAMP) {
    foreach ($rows as $r) {
        if (!$r['eligible']) continue;
        $u = $pdo->prepare("UPDATE proprietaires SET code_compte=? WHERE id=? AND (code_compte IS NULL OR code_compte='')");
        $u->execute([$r['compte'], $r['id']]);
        if ($u->rowCount() > 0) { $stamped++; echo "  STAMPÉ #{$r['id']} {$r['existant']} ← compte {$r['compte']}\n"; }
    }
}

echo "\n=== BILAN " . ($STAMP ? "(STAMPING APPLIQUÉ)" : "(lecture seule, rien écrit)") . " ===\n";
echo "Rapprochements proposés     : " . count($rows) . "\n";
echo "Éligibles (FORT + société 2): $nElig" . ($STAMP ? " | stampés : $stamped" : " → seront stampés en mode 'stamp'") . "\n";
echo "Non éligibles (Lyon / prénom≠ / collision) : " . (count($rows)-$nElig) . " (gardés séparés, rien touché)\n";
echo "CSV : " . ($csv !== false ? $csvPath : '(non écrit — fichier verrouillé)') . "\n";
if (!$STAMP) echo "\n→ Pour appliquer le stamping : php reconcile_proprios.php stamp\n";
