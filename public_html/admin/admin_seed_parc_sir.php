<?php
declare(strict_types=1);
/**
 * investisseur/_seed_parc_sir.php — Import du parc SIR depuis CSV
 *
 * RÈGLE ABSOLUE : ne JAMAIS créer de nouveau bien dans la table `biens`.
 * Les biens sont déjà en base. On se contente de :
 *   - créer une analyse investisseur par ligne du CSV
 *   - essayer de la lier au bien existant via matching (propriétaire + ville + adresse)
 *   - si matching échoue → analyse autonome (id_bien_source = null)
 *
 * Ne touche pas non plus à `biens` en UPDATE : par prudence, on ne réécrit
 * pas les coquilles existantes. Toutes les données CSV vont dans investisseur_analyses.
 *
 * Dedup : si une analyse existe déjà avec le même titre + ville (ou même
 * id_bien_source), on fait un UPDATE plutôt qu'un INSERT.
 *
 * Usage CLI :
 *   php _seed_parc_sir.php [--dry-run] [--force] [--limit=N] [--csv=<path>]
 */

$isCli = (php_sapi_name() === 'cli');

$opt = [
    'dry_run' => false,
    'force'   => false,
    'limit'   => null,
    'csv'     => 'c:/tmp/parc_sir.csv',
];
if ($isCli) {
    foreach ($_SERVER['argv'] ?? [] as $i => $a) {
        if ($i === 0) continue;
        if ($a === '--dry-run') $opt['dry_run'] = true;
        elseif ($a === '--force') $opt['force'] = true;
        elseif (strpos($a, '--limit=') === 0) $opt['limit'] = (int)substr($a, 8);
        elseif (strpos($a, '--csv=') === 0)   $opt['csv']   = substr($a, 6);
    }
} else {
    require_once __DIR__ . '/../inc/bootstrap.php';
    require_once __DIR__ . '/../inc/auth.php';
    require_login();
    $opt['dry_run'] = !empty($_GET['dry_run']);
    $opt['force']   = !empty($_GET['force']);
    $opt['limit']   = !empty($_GET['limit']) ? (int)$_GET['limit'] : null;
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if (!in_array($roleId, [1, 7], true) && empty($_SESSION['super_admin'])) {
        http_response_code(403); exit('Admin only.');
    }
}

if ($isCli) {
    require_once __DIR__ . '/../config/db.php';
    session_start();
}
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';
require_once __DIR__ . '/../inc/investisseur_interpretations.php';

$pdo = isset($GLOBALS['pdo']) ? $GLOBALS['pdo'] : db();
$GLOBALS['pdo'] = $pdo;

// ─── Scope session : on cible la Régie EMERY (société 1) par défaut ──
if (empty($_SESSION['id_societe'])) $_SESSION['id_societe'] = 1;
if (empty($_SESSION['id_user']))    $_SESSION['id_user']    = 1;
$idSociete = (int)$_SESSION['id_societe'];

// ─── Chargement CSV ───────────────────────────────────────────────────
if (!is_file($opt['csv'])) {
    echo "ERREUR : CSV introuvable : {$opt['csv']}" . PHP_EOL; exit(1);
}
$fh = fopen($opt['csv'], 'r');
$header = fgetcsv($fh, 0, ';');
$rowsCsv = [];
while (($r = fgetcsv($fh, 0, ';')) !== false) {
    if (count($r) < 2) continue;
    $rowsCsv[] = array_combine($header, array_pad($r, count($header), ''));
}
fclose($fh);

if ($opt['limit']) $rowsCsv = array_slice($rowsCsv, 0, $opt['limit']);

// ─── Normalisation pour matching ──────────────────────────────────────
$norm = function (string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','î'=>'i','ô'=>'o','û'=>'u','ç'=>'c','ï'=>'i','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s;
};

// ─── Cache des propriétaires SIR/SIRES/OPERA/HIMMALAYA/ELYSEE/CB ──────
$propMap = [];
$propQ = $pdo->query("SELECT id, nom, societe FROM proprietaires
    WHERE nom LIKE '%SIR%' OR societe LIKE '%SIR%'
       OR nom LIKE '%OPERA%' OR societe LIKE '%OPERA%'
       OR nom LIKE '%HIMMA%' OR societe LIKE '%HIMMA%'
       OR nom LIKE '%ELYSEE%' OR societe LIKE '%ELYSEE%'
       OR nom LIKE '%CB FINAN%' OR societe LIKE '%CB FINAN%'");
foreach ($propQ->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $key = $norm(($p['societe'] ?: '') . ($p['nom'] ?: ''));
    $propMap[$key] = $p;
}

$resolveProp = function (string $propCsv) use ($norm, $propMap): ?array {
    $k = $norm($propCsv);
    // Alias
    if (strpos($k, 'groupesir') !== false) $alias = 'sarlgroupesir';
    elseif ($k === 'sires')                $alias = 'scisires';
    elseif ($k === 'opera')                $alias = 'sciopera';
    elseif (strpos($k, 'himmalaya') !== false) $alias = 'scihimmalaya';
    elseif (strpos($k, 'elysee1') !== false || strpos($k, 'elysee i') !== false) $alias = 'scielyseei';
    elseif (strpos($k, 'elysee2') !== false || strpos($k, 'elysee ii') !== false) $alias = 'scielyseeii';
    elseif (strpos($k, 'cbfinance') !== false) $alias = 'sccbfinances';
    else $alias = $k;
    foreach ($propMap as $normKey => $p) {
        if (strpos($normKey, $alias) !== false || strpos($alias, $normKey) !== false) return $p;
    }
    return null;
};

// ─── Cache biens des SCI SIR ──────────────────────────────────────────
$bienCols = $pdo->query("DESCRIBE biens")->fetchAll(PDO::FETCH_COLUMN);
$hasAdresse1 = in_array('adresse_1', $bienCols, true);
$propIds = array_map(fn($p) => (int)$p['id'], $propMap);
if (!empty($propIds)) {
    $in = implode(',', $propIds);
    $q = "SELECT id, id_proprietaire, designation, ville, code_postal, " . ($hasAdresse1 ? 'adresse_1' : "'' AS adresse_1") . "
          FROM biens WHERE id_proprietaire IN ($in)";
    $biensStock = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
} else {
    $biensStock = [];
}

// Indexe par (id_prop, ville_norm, adresse_norm)
$bienIndex = [];
foreach ($biensStock as $b) {
    $k = (int)$b['id_proprietaire'] . '|' . $norm((string)$b['ville']) . '|' . $norm((string)$b['adresse_1']);
    $bienIndex[$k] = $b;
}

$findBien = function (int $idProp, string $ville, string $adresse) use ($bienIndex, $biensStock, $norm): ?int {
    $nVille = $norm($ville);
    $nAdr   = $norm($adresse);
    // 1) Match exact (prop + ville + adresse)
    $k = $idProp . '|' . $nVille . '|' . $nAdr;
    if (isset($bienIndex[$k])) return (int)$bienIndex[$k]['id'];
    // 2) Match partiel : prop + adresse commence par / contient
    foreach ($biensStock as $b) {
        if ((int)$b['id_proprietaire'] !== $idProp) continue;
        $bAdr = $norm((string)$b['adresse_1']);
        $bVille = $norm((string)$b['ville']);
        if ($bAdr && $nAdr && (strpos($bAdr, $nAdr) !== false || strpos($nAdr, $bAdr) !== false)) {
            // Vérifier cohérence ville si présente
            if (!$bVille || !$nVille || $bVille === $nVille || strpos($bVille, $nVille) !== false || strpos($nVille, $bVille) !== false) {
                return (int)$b['id'];
            }
        }
    }
    return null;
};

// ─── Mapping typologie (texte CSV → type_bien propre) ─────────────────
$normalizeType = function (string $t): string {
    $t = trim($t);
    return match (strtolower($t)) {
        "local d'activité", "local d activité" => "Local d'activité",
        'local commercial'  => 'Local commercial',
        'bureaux','bureau'  => 'Bureaux',
        'immeuble','immeuble complet' => 'Immeuble',
        'immeuble de bureaux' => 'Immeuble',
        'tènement immobilier' => 'Tènement immobilier',
        'parking','parking/garage' => 'Parking/Garage',
        'appartements','appartement t2','appartement' => 'Appartement',
        'maison' => 'Maison',
        default  => $t ?: 'Autre',
    };
};

$parseDate = function (string $d): ?string {
    $d = trim($d);
    if ($d === '') return null;
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $d, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    return null;
};

// ─── Analyses déjà présentes (dedup par titre + ville) ────────────────
$existingByBien = [];
$existingByKey  = [];
$st = $pdo->prepare("SELECT id, id_bien_source, titre_analyse, ville FROM investisseur_analyses WHERE id_societe = :s");
$st->bindValue(':s', $idSociete, PDO::PARAM_INT);
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
    if (!empty($a['id_bien_source'])) $existingByBien[(int)$a['id_bien_source']] = (int)$a['id'];
    $existingByKey[$norm((string)$a['titre_analyse']) . '|' . $norm((string)$a['ville'])] = (int)$a['id'];
}

// ─── Traitement ──────────────────────────────────────────────────────
$report = [];
$nCreated = 0; $nUpdated = 0; $nError = 0; $nBienMatch = 0; $nBienNoMatch = 0;

foreach ($rowsCsv as $r) {
    $propCsv = trim((string)($r['PROPRIETAIRE'] ?? ''));
    $adresseCheck = trim((string)($r['ADRESSE'] ?? ''));
    // Skip lignes sans propriétaire ET sans adresse (lignes vides ou totaux)
    if ($propCsv === '' && $adresseCheck === '') continue;
    if ($propCsv === '') continue;

    $prop = $resolveProp($propCsv);
    // Si pas trouvé, on continue quand même (id_proprietaire restera null)
    // — on NE crée PAS de propriétaire, on n'enfreint pas la règle "pas de création".
    $idProp = $prop ? (int)$prop['id'] : 0;

    $ville   = trim((string)($r['VILLE'] ?? ''));
    $adresse = trim((string)($r['ADRESSE'] ?? ''));
    $cp      = trim((string)($r['CP'] ?? ''));
    $type    = $normalizeType((string)($r['TYPE'] ?? ''));

    // Construction titre
    $n = trim((string)($r['N'] ?? ''));
    $titre = 'SIR #' . ($n ?: '?') . ' — ' . $propCsv . ' — ' . $ville . ' — ' . $type;

    // Loyer annuel HT → loyer mensuel
    $loyerAn = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($r['LOYER_HT_AN'] ?? '0')));
    $prixVente = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($r['PRIX_VENTE'] ?? '0')));
    $prixTotal = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($r['PRIX_TOTAL'] ?? '0')));
    $honoraires = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($r['HONORAIRES'] ?? '0')));
    $tf = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($r['TAXE_FONCIERE'] ?? '0')));
    $surface = (float)preg_replace('/[^0-9.]/', '', (string)($r['SURFACE'] ?? '0'));
    $parkings = (int)($r['PARKINGS'] ?? 0);

    // Prix retenu = prix total (avec honoraires) — c'est ce que l'acheteur paie réellement
    // mais on met prix_achat = prix_vente (HT honoraires) + frais_agence = honoraires
    $data = [
        'id_bien_source'    => null,
        'id_proprietaire'   => $idProp ?: null,
        'titre_analyse'     => $titre,
        'reference_bien'    => 'SIR-' . ($n ?: ''),
        'type_bien'         => $type,
        'ville'             => $ville,
        'quartier'          => '',
        'adresse'           => $adresse . ($cp ? ' ' . $cp : ''),
        'surface'           => $surface,
        'nb_parkings'       => $parkings,
        'prix_achat'        => $prixVente,
        'frais_agence'      => $honoraires,
        'frais_notaire'     => 0,
        'travaux'           => 0,
        'ameublement'       => 0,
        'apport'            => 0,
        'taux_credit'       => 0, // hypothèse auto 3.5%
        'duree_credit'      => 0, // hypothèse auto 20 ans
        'loyer_estime'      => $loyerAn > 0 ? round($loyerAn / 12, 2) : 0,
        'taxe_fonciere'     => $tf,
        'vacance_locative'  => 3, // commerce : vacance faible par défaut
        'entretien_imprevus'=> 3,
        'gestion_locative'  => 0,
        'locataire_nom'     => trim((string)($r['LOCATAIRE'] ?? '')),
        'bail_fin'          => $parseDate((string)($r['FIN_BAIL'] ?? '')),
        'photovoltaique'    => ((int)($r['PHOTOV'] ?? 0) === 1) ? 1 : 0,
        'honoraires_vente'  => $honoraires,
        'strategie'         => 'Patrimonial',
        'potentiel_valorisation' => 3,
        'tension_locative'      => 3,
        'facilite_revente'      => 3,
        'niveau_risque'         => 3,
        'qualite_emplacement'   => 3,
        'commentaire_humain'    => "Importé depuis le tableau de vente du parc SIR (22/04/2026). Prix hors honoraires : " . number_format($prixVente, 0, ',', ' ') . " € / Honoraires : " . number_format($honoraires, 0, ',', ' ') . " € / Prix total acte en main : " . number_format($prixTotal, 0, ',', ' ') . " €.",
        'statut'            => 'brouillon',
    ];

    // Matching bien existant (si propriétaire trouvé)
    $matchedBienId = $idProp ? $findBien($idProp, $ville, $adresse) : null;
    if ($matchedBienId) {
        $data['id_bien_source'] = $matchedBienId;
        $nBienMatch++;
    } else {
        $nBienNoMatch++;
    }

    // Dedup : si analyse existe déjà
    $existingId = null;
    if ($matchedBienId && isset($existingByBien[$matchedBienId])) {
        $existingId = $existingByBien[$matchedBienId];
    } else {
        $k = $norm($titre) . '|' . $norm($ville);
        if (isset($existingByKey[$k])) $existingId = $existingByKey[$k];
    }

    if ($opt['dry_run']) {
        $report[] = ['status' => 'would-' . ($existingId ? 'update' : 'create'),
                     'n' => $n, 'prop' => $propCsv, 'ville' => $ville, 'adresse' => $adresse,
                     'bien_id' => $matchedBienId, 'existing_analyse' => $existingId,
                     'prix' => $prixVente, 'loyer_an' => $loyerAn, 'type' => $type];
        if ($existingId) $nUpdated++; else $nCreated++;
        continue;
    }

    try {
        if ($existingId && !$opt['force']) {
            // Skip : analyse existe déjà (on ne veut pas écraser)
            $report[] = ['status' => 'skip', 'n' => $n, 'prop' => $propCsv, 'ville' => $ville, 'analyse_id' => $existingId];
            continue;
        }
        $newId = inv_save($pdo, $data, $existingId);
        if ($existingId) {
            $nUpdated++;
            $report[] = ['status' => 'updated', 'n' => $n, 'analyse_id' => $newId, 'bien_id' => $matchedBienId, 'prop' => $propCsv, 'ville' => $ville];
        } else {
            $nCreated++;
            $report[] = ['status' => 'created', 'n' => $n, 'analyse_id' => $newId, 'bien_id' => $matchedBienId, 'prop' => $propCsv, 'ville' => $ville];
        }
    } catch (Throwable $e) {
        $nError++;
        $report[] = ['status' => 'error', 'n' => $n, 'error' => $e->getMessage(), 'prop' => $propCsv];
    }
}

$summary = [
    'csv_lignes'    => count($rowsCsv),
    'id_societe'    => $idSociete,
    'dry_run'       => $opt['dry_run'],
    'created'       => $nCreated,
    'updated'       => $nUpdated,
    'errors'        => $nError,
    'bien_matched'  => $nBienMatch,
    'bien_no_match' => $nBienNoMatch,
];

if ($isCli) {
    echo "═══ SEED PARC SIR ═══" . PHP_EOL;
    echo "CSV : " . $opt['csv'] . PHP_EOL;
    echo "Lignes : " . count($rowsCsv) . PHP_EOL . PHP_EOL;
    foreach ($report as $r) {
        $ic = match ($r['status']) {
            'created'       => '✓ CREATE',
            'updated'       => '⟳ UPDATE',
            'would-create'  => '~ DRY-C ',
            'would-update'  => '~ DRY-U ',
            'skip'          => '- SKIP  ',
            'error'         => '✗ ERROR ',
            'prop-not-found'=> '? NO-PRO',
            default         => '? ' . $r['status'],
        };
        $line = sprintf('%s #%-4s %-12s %-18s %-40s',
            $ic, $r['n'] ?? '?', mb_substr((string)($r['prop'] ?? ''), 0, 10),
            mb_substr((string)($r['ville'] ?? ''), 0, 17),
            mb_substr((string)($r['adresse'] ?? ''), 0, 38));
        if (!empty($r['bien_id']))  $line .= ' → bien #' . $r['bien_id'];
        if (!empty($r['analyse_id'])) $line .= ' / analyse #' . $r['analyse_id'];
        if (!empty($r['error']))    $line .= ' : ' . $r['error'];
        echo $line . PHP_EOL;
    }
    echo PHP_EOL . "═══ RÉSUMÉ ═══" . PHP_EOL;
    foreach ($summary as $k => $v) echo "  $k : " . (is_bool($v) ? ($v?'true':'false') : $v) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['summary' => $summary, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
