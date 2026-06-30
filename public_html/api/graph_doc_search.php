<?php
/**
 * api/graph_doc_search.php
 *
 * Recherche assistée d'un document attendu d'un bien dans le OneDrive général.
 * GUIDÉE PAR LE BIEN COURANT, en DEUX TEMPS (priorité au dossier) :
 *
 *   PHASE 1 — DOSSIER D'ABORD : on localise le(s) dossier(s) OneDrive du bien
 *   (propriétaire / référence / voie), et on liste TOUS leurs fichiers, quel
 *   que soit leur nom. C'est la voie principale car les fichiers OneDrive ne
 *   portent pas toujours le mot "DPE" dans leur nom.
 *
 *   PHASE 2 — PAR LE NOM : recherche globale Graph par mot-clé de type + indices
 *   du bien, en complément (couvre l'exception Lyon : dossiers par type "Diag").
 *
 * Entrée (POST) : bien_id, type_code
 * Sortie (JSON) : ok, candidates[ {item_id,name,path,size,modified,web_url,
 *                  mime_type,in_folder,score,reasons,best} ]
 */

declare(strict_types=1);
set_time_limit(90);
// API JSON : aucune sortie HTML d'erreur ne doit corrompre le flux JSON.
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/microsoft_graph.php';
require_once dirname(__DIR__) . '/inc/graph_doc_match.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}
verify_csrf_any('ged_graph');

if (!graph_is_configured()) {
    exit(json_encode(['ok' => false, 'error' => 'Microsoft Graph non configuré sur ce serveur.']));
}

$pdo      = $GLOBALS['pdo'];
$bienId   = isset($_POST['bien_id']) && ctype_digit((string)$_POST['bien_id']) ? (int)$_POST['bien_id'] : 0;
$typeCode = strtoupper(trim((string)($_POST['type_code'] ?? '')));
if ($bienId <= 0 || $typeCode === '') {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres manquants (bien_id, type_code).']));
}

// ── Contexte du bien (réf, adresse, ville, propriétaire) ───────────────
$st = $pdo->prepare("
    SELECT b.reference_bien, b.lot_principal, b.lot_secondaire,
           COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1)     AS adresse_1,
           COALESCE(NULLIF(b.ville, ''), i.ville)             AS ville,
           i.nom_immeuble                                     AS immeuble_nom,
           a.nom_agence, a.code_agence,
           COALESCE(NULLIF(tp.nom_affichage, ''), NULLIF(tp.raison_sociale, ''),
                    NULLIF(CONCAT_WS(' ', tp.prenom, tp.nom), ''),
                    NULLIF(p.societe, ''), NULLIF(CONCAT_WS(' ', p.prenom, p.nom), '')) AS proprio_nom
      FROM biens b
      LEFT JOIN immeubles i     ON i.id = b.id_immeuble
      LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
      LEFT JOIN tiers tp        ON tp.id = p.id_tiers
      LEFT JOIN agences  a      ON a.id = b.id_agence
     WHERE b.id = ? LIMIT 1");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    exit(json_encode(['ok' => false, 'error' => 'Bien introuvable.']));
}

$typeKeywords = gdm_type_keywords($typeCode);
$proprioName  = (string)($bien['proprio_nom'] ?? '');
$proprioTokens = gdm_tokens($proprioName);

// SIR & rattachés (GROUPE SIR / GPE IMMO DR / SABY / Sté Immobilière du Rhône) :
// pour eux SEULEMENT, on autorise le repli vers la branche TRANSACTION Lyon
// (VENTES GROUPE SIR & SABY / 04_DIAGNOSTICS). Cf. [[gpe-immo-dr-equals-groupe-sir]].
$proprioNorm = gdm_normalize($proprioName);
$isSirAffiliated = (bool)preg_match('/\b(sir|saby)\b/', $proprioNorm)
    || str_contains($proprioNorm, 'groupe sir')
    || str_contains($proprioNorm, 'gpe immo')
    || str_contains($proprioNorm, 'immobiliere du rhone');
$immeubleTokens = gdm_tokens((string)($bien['immeuble_nom'] ?? ''));
$agenceTokens   = array_values(array_unique(array_merge(
    gdm_tokens((string)($bien['nom_agence'] ?? '')),
    gdm_tokens((string)($bien['code_agence'] ?? ''))
)));
// Source de la voie : adresse_1 si présente, SINON le nom d'immeuble (qui porte
// souvent l'adresse, ex. bien 1638 adresse_1=NULL, immeuble="27 HENRI MARECHAL ST-PRIEST").
$voieSource    = trim((string)($bien['adresse_1'] ?? ''));
if ($voieSource === '') $voieSource = (string)($bien['immeuble_nom'] ?? '');
$voieTokens    = gdm_tokens($voieSource);
$villeTokens   = gdm_tokens((string)($bien['ville'] ?? ''));
$refTokens     = gdm_tokens((string)($bien['reference_bien'] ?? ''));
// Numéro(s) de lot : indice fort présent dans le bien ET dans le diagnostic
$lotTokens     = array_values(array_unique(array_merge(
    gdm_tokens((string)($bien['lot_principal'] ?? '')),
    gdm_tokens((string)($bien['lot_secondaire'] ?? ''))
)));
$bienTokens    = array_values(array_unique(array_merge($voieTokens, $villeTokens, $refTokens, $lotTokens)));

$scoreCtx = [
    'document_type'   => $typeCode,
    'type_keywords'   => $typeKeywords,
    'bien_tokens'     => $bienTokens,
    'proprio_tokens'  => $proprioTokens,
    'lot_tokens'      => $lotTokens,
    'locataire_tokens'=> [],   // renseigné plus bas (après requête baux)
];

// ── Drive cible : drive métier ─────────────────────────────────────────
if (defined('GRAPH_ONEDRIVE_USER') && (string)GRAPH_ONEDRIVE_USER !== '') {
    graph_set_drive_user((string)GRAPH_ONEDRIVE_USER);
}

$errors = [];

/** Nettoie un parentReference.path Graph en chemin lisible. */
$cleanPath = static function (string $raw): string {
    $p = preg_replace('#^/drives?/[^/]+/root:#', '', $raw) ?? $raw;
    $p = preg_replace('#^/drive/root:#', '', $p) ?? $p;
    return trim((string)$p, '/');
};

// Tokens adresse : numéro (122) à part, et NOM de voie SANS les mots génériques
// (rue, avenue…) car les dossiers s'appellent "122 MARIETTON" (sans "rue").
$streetStop = ['rue','avenue','boulevard','bd','route','chemin','place','impasse',
               'allee','quai','cours','montee','passage','square','voie','clos',
               'lotissement','residence','chez','de','du','des','la','le','les','et'];
$streetTokens = array_values(array_filter($voieTokens,
    fn($t) => !ctype_digit($t) && !in_array($t, $streetStop, true)));
$numberTokens = array_values(array_filter($voieTokens, fn($t) => ctype_digit($t)));

// Locataires du bien : ACTUEL + PARTIS/ARCHIVÉS (tous statuts). Les diagnostics
// d'immeubles à lots sont souvent nommés d'après le locataire du lot (ex.
// "LOT 85 KL HABILLEMENT.pdf"). On reconnaît ce nom dans le fichier. (Emmanuel)
$locataireTokens = [];
$locataireNoms = [];   // noms bruts (pour affichage contexte)
$locStop = ['sarl','sas','sa','sci','scp','eurl','sasu','snc','sci','mr','mme','m',
            'monsieur','madame','sci','ets','etablissements','et','de','du','des','la','le','les'];
try {
    $stL = $pdo->prepare("
        SELECT COALESCE(NULLIF(t.nom_affichage,''), NULLIF(t.raison_sociale,''),
                        NULLIF(bb.locataire_raison_sociale,''),
                        NULLIF(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom), '')) AS loc
          FROM bien_baux bb
          LEFT JOIN tiers t ON t.id = bb.id_tiers_locataire
         WHERE bb.id_bien = ?
         ORDER BY bb.date_prise_effet DESC LIMIT 20");
    $stL->execute([$bienId]);
    foreach (($stL->fetchAll(PDO::FETCH_COLUMN) ?: []) as $loc) {
        $loc = trim((string)$loc);
        if ($loc !== '' && !in_array($loc, $locataireNoms, true)) $locataireNoms[] = $loc;
        foreach (gdm_tokens($loc) as $tk) {
            if (!in_array($tk, $locStop, true)) $locataireTokens[] = $tk;
        }
    }
    $locataireTokens = array_values(array_unique($locataireTokens));
} catch (Throwable $e) {}
$scoreCtx['locataire_tokens'] = $locataireTokens;   // reconnaissance nom locataire

$byId   = [];     // item_id => ['item'=>..., 'in_folder'=>bool, 'source'=>string]
$budget = 500;    // plafond global de fichiers listés (anti-explosion)

$containsAny = function (string $hay, array $toks): bool {
    foreach ($toks as $t) { if ($t !== '' && str_contains($hay, $t)) return true; }
    return false;
};
$ingest = function (array $files, string $source) use (&$byId) {
    foreach ($files as $f) {
        $id = (string)($f['id'] ?? '');
        if ($id === '' || isset($byId[$id])) continue;
        $byId[$id] = ['item' => $f, 'in_folder' => true, 'source' => $source];
    }
};

// ── DÉCOUVERTE PAR RECHERCHE DE DOSSIER (agence-agnostique, peu d'appels) ──
// On cherche directement les DOSSIERS pertinents (adresse / immeuble / proprio /
// réf / locataire), puis on CLASSE chacun par son CHEMIN :
//   - chemin dans la SECTION du type (ex. 022-DIAGNOSTICS) → source 'section'
//   - chemin propriétaire / immeuble                       → source correspondant
//   - chemin d'une branche étrangère (ASSEMBLEES…)         → ignoré
// Évite d'énumérer toutes les agences (cause du timeout quand id_agence=NULL).
$sectionKw = array_map('gdm_normalize', gdm_type_section_keywords($typeCode));

// Tokens de rue DISTINCTIFS (≥4 car.) pour l'anti-bruit : un immeuble peut être
// classé sous un autre n° de voie que celui du bien (ex. bien au 27, diags au 40-42
// Henri Maréchal — même immeuble). On matche donc sur le NOM DE RUE, pas le numéro.
$streetCore = array_values(array_filter($streetTokens, fn($t) => strlen($t) >= 4));
if (empty($streetCore)) $streetCore = $streetTokens;

// Requêtes de dossier, par ordre de pertinence (la 1re qui matche = source)
$folderProbes = [];
// Adresse : on cherche par NOM DE RUE SANS le numéro (Graph restreint sinon au n° exact).
if (!empty($streetTokens)) $folderProbes[] = ['src' => 'section',      'q' => implode(' ', $streetTokens), 'tokens' => $streetCore];
if (!empty($immeubleTokens)) $folderProbes[] = ['src' => 'immeuble',   'q' => implode(' ', $streetTokens ?: $immeubleTokens), 'tokens' => $streetCore ?: $immeubleTokens];
if (!empty($proprioTokens)) $folderProbes[] = ['src' => 'propriétaire','q' => implode(' ', $proprioTokens), 'tokens' => $proprioTokens];
if (!empty($refTokens))     $folderProbes[] = ['src' => 'bien',        'q' => implode(' ', $refTokens), 'tokens' => $refTokens];
if (!empty($locataireTokens)) $folderProbes[] = ['src' => 'locataire', 'q' => implode(' ', $locataireTokens), 'tokens' => $locataireTokens];

// NB : les résultats Graph /search n'exposent pas parentReference.path (null).
// On ne peut donc pas classer le dossier avant ouverture : on liste ses enfants
// (les FICHIERS, eux, portent leur chemin complet) et le filtre par-fichier
// (gdm_path_excluded dans la boucle de scoring) écarte les branches étrangères.
$seenFolder = [];
$folderCap  = 0;   // garde-fou : nb de dossiers ouverts (anti-explosion)
foreach ($folderProbes as $probe) {
    if (trim($probe['q']) === '') continue;
    try {
        $results = graph_search_drive($probe['q']);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        continue;
    }
    foreach ($results as $it) {
        if (empty($it['folder'])) continue;
        $fid = (string)($it['id'] ?? '');
        if ($fid === '' || isset($seenFolder[$fid])) continue;

        $fname = gdm_normalize((string)($it['name'] ?? ''));
        // Le nom du dossier doit recouper les tokens de la sonde (anti-bruit)
        if (!$containsAny($fname, $probe['tokens'])) continue;

        $seenFolder[$fid] = true;
        if (++$folderCap > 25 || $budget <= 0) break;   // borne dure

        try {
            $children = graph_list_all_children_by_item_id($fid);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            continue;
        }
        $files = array_values(array_filter($children, fn($c) => !empty($c['file'])));
        $budget -= count($files);
        $ingest($files, $probe['src']);
    }
    if ($folderCap > 25 || $budget <= 0) break;
}

// ── COMPLÉMENT PAR LE NOM DE FICHIER (cas hors arborescence standard) ──
$mainKw = $typeKeywords[0] ?? strtolower($typeCode);
$nameQueries = [$mainKw];
if (!empty($streetTokens))  $nameQueries[] = $mainKw . ' ' . implode(' ', $streetTokens);
if (!empty($proprioTokens)) $nameQueries[] = $mainKw . ' ' . implode(' ', array_slice($proprioTokens, 0, 2));

foreach (array_unique($nameQueries) as $q) {
    try {
        $results = graph_search_drive($q);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        continue;
    }
    foreach ($results as $it) {
        if (empty($it['file'])) continue;
        $id = (string)($it['id'] ?? '');
        if ($id === '' || isset($byId[$id])) continue;   // déjà trouvé via dossier
        $byId[$id] = ['item' => $it, 'in_folder' => false, 'source' => 'nom'];
    }
}

if (empty($byId) && !empty($errors)) {
    exit(json_encode(['ok' => false, 'error' => 'Recherche OneDrive : ' . $errors[0]]));
}

// ── Filtre d'éligibilité STRICT (Emmanuel 2026-06-06) ──────────────────
// On ne propose QUE des fichiers réellement classés dans le bon dossier :
//   (a) dossier du PROPRIÉTAIRE, ou
//   (b) dossier de l'IMMEUBLE, ou
//   (c) dossier de TYPE (ex. Lyon "Diag"/"Assemblée") MAIS scopé à l'AGENCE.
// Tout fichier hors de ces dossiers (simple match de nom global) est écarté.
$typeFolderTokens = array_values(array_unique(array_map('gdm_normalize', $typeKeywords)));
$isEligible = function (array $it, bool $inFolder) use (
    $cleanPath, $proprioTokens, $immeubleTokens, $typeFolderTokens, $agenceTokens
): bool {
    if ($inFolder) return true;  // déjà dans un dossier proprio/immeuble validé
    $path = gdm_normalize($cleanPath((string)($it['parentReference']['path'] ?? '')));
    if ($path === '') return false;

    $hasTok = function (array $toks) use ($path): bool {
        foreach ($toks as $t) { if ($t !== '' && str_contains($path, $t)) return true; }
        return false;
    };
    // (a) ou (b) : chemin contient le propriétaire ou l'immeuble
    if ($hasTok($proprioTokens) || $hasTok($immeubleTokens)) return true;
    // (c) : dossier de type, mais uniquement dans la branche de l'AGENCE du bien
    if ($hasTok($typeFolderTokens)) {
        // si on connaît l'agence, on exige sa présence dans le chemin
        if (empty($agenceTokens)) return true;
        return $hasTok($agenceTokens);
    }
    return false;
};

// ── Scoring + tri ──────────────────────────────────────────────────────
// Les fichiers issus du DOSSIER du bien sont prioritaires (+50), puis on
// affine par mot-clé de type pour faire remonter le bon document.
$candidates = [];
$rejected = 0;
foreach ($byId as $id => $row) {
    $it = $row['item'];
    $fname = (string)($it['name'] ?? '');
    $fpath = $cleanPath((string)($it['parentReference']['path'] ?? ''));
    // Filtre négatif NOM : pas un doc identifié comme un AUTRE type (PV AG, mandat…)
    if (gdm_name_conflicts_type($fname, $typeCode)) { $rejected++; continue; }
    // Filtre négatif CHEMIN : pas dans une branche métier étrangère (ASSEMBLEES…)
    if (gdm_path_excluded($fpath, $typeCode)) { $rejected++; continue; }
    if (!$row['in_folder'] && !$isEligible($it, false)) { $rejected++; continue; }
    $sc = gdm_score_candidate($it, $scoreCtx);
    $score = (int)$sc['score'];
    $reasons = $sc['reasons'];

    // Branche TRANSACTION (VENTES GROUPE SIR & SABY…) : réservée à SIR & rattachés.
    // Pour tout autre propriétaire, on n'y pioche pas.
    $inTransaction = str_contains($hayPathRaw = gdm_normalize($fpath), 'transaction')
                  || str_contains($hayPathRaw, 'saby')
                  || str_contains($hayPathRaw, 'ventes');
    if ($inTransaction && !$isSirAffiliated) { $rejected++; continue; }

    // Source RÉELLE déduite du chemin du fichier (badge juste + bon classement)
    $hayPath = $hayPathRaw;
    $isDiagSection = !empty($sectionKw) && $containsAny($hayPath, $sectionKw);
    $src = $row['source'] ?? 'dossier';
    if ($isDiagSection && str_contains($hayPath, 'gestion')) {
        $src = 'diagnostics gestion';
    } elseif ($isDiagSection && $inTransaction) {
        $src = 'diagnostics transaction';
    } elseif ($isDiagSection) {
        $src = 'diagnostics';
    } elseif ($containsAny($hayPath, $proprioTokens) || str_contains($hayPath, 'proprietaire')) {
        $src = 'propriétaire';
    } elseif (str_contains($hayPath, 'immeuble') || $containsAny($hayPath, $immeubleTokens)) {
        $src = 'immeuble';
    } elseif ($inTransaction) {
        $src = 'transaction';
    }
    if ($row['in_folder']) {
        // Préférence : diag gestion (+70) > diag transaction SIR (+55) > autre dossier (+40)
        if ($src === 'diagnostics gestion')          $score += 70;
        elseif ($src === 'diagnostics transaction')  $score += 55;
        elseif ($isDiagSection)                       $score += 65;
        else                                          $score += 40;
        $reasons[] = 'dossier ' . $src;
    }

    // ── DISCRIMINANT NUMÉRO DE VOIE (fort) ────────────────────────────────
    // 112 et 124 Montesquieu = DEUX immeubles. On compare le n° du bien au n°
    // du SEGMENT d'adresse du chemin (celui qui contient le nom de rue).
    // n° exact → +80 ; n° différent sur la même rue → -70 + jamais "meilleur".
    $numMatch = 0; // 1 = exact, -1 = différent, 0 = inconnu
    if (!empty($numberTokens)) {
        foreach (explode('/', $fpath) as $seg) {
            $segNorm = gdm_normalize($seg);
            if (!$containsAny($segNorm, $streetCore)) continue;       // segment d'adresse
            preg_match_all('/\b(\d{1,4})\b/', $segNorm, $mm);          // n° 1-4 chiffres (exclut CP 5)
            $segNums = $mm[1] ?? [];
            if (array_intersect($numberTokens, $segNums)) { $numMatch = 1; break; }
            if (!empty($segNums)) $numMatch = -1;                      // autre n° sur la rue
        }
    }
    if ($numMatch === 1)      { $score += 80; $reasons[] = 'n° exact'; }
    elseif ($numMatch === -1) { $score -= 70; $reasons[] = 'n° différent'; }

    $candidates[] = [
        'item_id'   => $id,
        'name'      => (string)($it['name'] ?? ''),
        'path'      => $fpath,
        'size'      => (int)($it['size'] ?? 0),
        'modified'  => (string)($it['lastModifiedDateTime'] ?? ''),
        'web_url'   => (string)($it['webUrl'] ?? ''),
        'mime_type' => (string)($it['file']['mimeType'] ?? 'application/octet-stream'),
        'in_folder' => $row['in_folder'],
        'source'    => $src,
        'score'     => $score,
        'reasons'   => $reasons,
        'num_mismatch' => ($numMatch === -1),
        'best'      => false,
    ];
}

usort($candidates, function ($a, $b) {
    if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
    return strcmp($b['modified'], $a['modified']);
});

// Pré-cochage du meilleur candidat : score plausible ET pas un n° de voie différent.
if (!empty($candidates) && $candidates[0]['score'] >= 40 && empty($candidates[0]['num_mismatch'])) {
    $candidates[0]['best'] = true;
}

$candidates = array_slice($candidates, 0, 25);

echo json_encode([
    'ok'            => true,
    'bien_id'       => $bienId,
    'type_code'     => $typeCode,
    'proprio'       => $proprioName,
    'bien_info'     => [
        'reference'      => $bien['reference_bien'] ?? null,
        'adresse'        => $voieSource,
        'ville'          => $bien['ville'] ?? null,
        'lot_principal'  => $bien['lot_principal'] ?? null,
        'lot_secondaire' => $bien['lot_secondaire'] ?? null,
        'proprio'        => $proprioName,
        'locataires'     => $locataireNoms,
    ],
    'folders_opened'=> count($seenFolder),
    'folder_probes' => array_values(array_map(fn($p) => $p['q'], $folderProbes)),
    'name_queries'  => array_values(array_unique($nameQueries)),
    'rejected'      => $rejected,
    'count'         => count($candidates),
    'candidates'    => $candidates,
], JSON_UNESCAPED_UNICODE);
