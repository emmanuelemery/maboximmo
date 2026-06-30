<?php
/**
 * prestamp_biens.php — Pré-rattachement des biens d'annonce aux lots CRG.
 *
 * Pour chaque propriétaire déjà rapproché (code_compte renseigné) qui possède
 * un bien porteur d'annonce, on cherche le lot CRG correspondant par ADRESSE
 * (l'adresse du bien vient du CRG). Si l'immeuble CRG n'a qu'UN lot → on stampe
 * biens.code_crg sur le bien d'annonce existant pour que l'import le RÉUTILISE
 * (zéro doublon, annonce intacte). Multi-lots ou adresse incertaine → on n'y
 * touche pas, on signale pour validation manuelle.
 *
 * N'écrit QUE biens.code_crg (champ de gestion), jamais les annonces.
 * Usage : php prestamp_biens.php            (rapport)
 *         php prestamp_biens.php stamp      (applique)
 *         php prestamp_biens.php stamp 871,857,943   (force ces biens même si approx.)
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];
$STAMP = (($argv[1] ?? '') === 'stamp');
$FORCE = array_filter(array_map('intval', explode(',', (string)($argv[2] ?? ''))));

$jsonl = 'C:/tmp/crg_emery.jsonl';
if (!is_file($jsonl)) {
    $py = 'C:/Users/emery/AppData/Local/Python/bin/python3.exe';
    shell_exec(escapeshellarg($py) . ' ' . escapeshellarg(__DIR__ . '/parse_crg_batch.py') . ' "D:/CRG EMERY IMMO" ' . escapeshellarg($jsonl) . ' 2>nul');
}
function norm(string $s): string {
    $s = strtoupper(strtr($s, ['É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Â'=>'A','À'=>'A','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Û'=>'U','Ü'=>'U','Ç'=>'C']));
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ', $s)));
}

// compte → immeubles [{code, adresse, lots:[numero_lot]}]
$crg = [];
foreach (file($jsonl) as $l) {
    $d = json_decode($l, true);
    if (!$d || empty($d['meta']['compte']) || !($d['meta']['trimestre'] ?? 0)) continue;
    foreach ($d['immeubles'] as $im) {
        $crg[$d['meta']['compte']][] = [
            'code' => (string)($im['code'] ?? ''),
            'adresse' => (string)($im['adresse'] ?? ''),
            'lots' => array_map(fn($lt) => (string)($lt['numero_lot'] ?? ''), $im['lots'] ?? []),
        ];
    }
}

$q = $pdo->query("SELECT p.id AS pid, p.nom, p.code_compte, b.id AS bid, b.adresse_1, b.code_crg
    FROM proprietaires p
    JOIN biens b ON b.id_proprietaire = p.id
    JOIN annonces a ON a.id_bien = b.id
    WHERE p.code_compte IS NOT NULL AND p.code_compte <> '' GROUP BY b.id");

$stamped = 0; $flagged = 0; $already = 0;
printf("%-6s %-20s %-26s %-14s %s\n", 'BIEN', 'PROPRIO', 'ADRESSE ANNONCE', 'CODE_CRG', 'ACTION');
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!empty($r['code_crg'])) { $already++; continue; }
    $annNum = preg_match('/^\s*(\d+)/', (string)$r['adresse_1'], $m) ? $m[1] : '';
    $annN = norm((string)$r['adresse_1']);
    $annWords = array_filter(explode(' ', $annN), fn($w) => strlen($w) > 3);

    $match = null; $multi = false;
    foreach ($crg[$r['code_compte']] ?? [] as $im) {
        $cn = norm($im['adresse']);
        $common = 0; foreach ($annWords as $w) { if (strpos($cn, $w) !== false) $common++; }
        $numOk = $annNum !== '' && strpos($cn, $annNum) !== false;
        $forced = in_array((int)$r['bid'], $FORCE, true);
        if (($numOk && $common >= 1) || ($forced && $common >= 1)) {
            if (count($im['lots']) === 1) { $match = $im['code'] . '_' . $im['lots'][0]; }
            else { $multi = true; $match = null; }
            break;
        }
    }

    if ($match) {
        printf("%-6s %-20s %-26s %-14s %s\n", $r['bid'], mb_substr($r['nom'],0,20), mb_substr((string)($r["adresse_1"]??""),0,26), $match, $STAMP ? '→ STAMPÉ' : '→ à stamper');
        if ($STAMP) {
            $u = $pdo->prepare("UPDATE biens SET code_crg=? WHERE id=? AND (code_crg IS NULL OR code_crg='')");
            $u->execute([$match, $r['bid']]);
            if ($u->rowCount() > 0) $stamped++;
        } else { $stamped++; }
    } else {
        printf("%-6s %-20s %-26s %-14s %s\n", $r['bid'], mb_substr($r['nom'],0,20), mb_substr((string)($r["adresse_1"]??""),0,26), '-', $multi ? '⚠ immeuble multi-lots → manuel' : '— pas de match');
        $flagged++;
    }
}
echo "\n=== BILAN " . ($STAMP ? "(APPLIQUÉ)" : "(rapport)") . " ===\n";
echo "À stamper / stampés : $stamped | déjà code_crg : $already | à valider manuel : $flagged\n";
if (!$STAMP) echo "→ Appliquer : php prestamp_biens.php stamp   (forcer approx. : php prestamp_biens.php stamp 871,857,943)\n";
