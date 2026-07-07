<?php
declare(strict_types=1);
/**
 * api/maboxoffice_settype.php — Fixe le TYPE d'un document (vocabulaire contrôlé
 * ged_document_types) et renvoie le nom GED recalculé. Superadmin.
 *   GET ?list=1            → {ok, types:[{code,libelle}]}
 *   GET ?doc=<id>&type=<CODE> → {ok, gedname, type_label}
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/maboxoffice_match.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
require_login();
// Lecture du glossaire des types (?list=1) = ouverte à tout utilisateur connecté
// (le modal de chargement en a besoin partout). Les MUTATIONS — création/màj de
// type (glossaire), classement d'un document — restent réservées au super admin.
if (!isset($_GET['list']) && !is_super_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit; }
$pdo = $GLOBALS['pdo'];

// Mapping métier ↔ types (codé, multi-métier). Les types ajoutés (colonne metier) s'ajoutent au bon onglet.
function mbo_type_metiers(PDO $pdo): array
{
    $base = [
        'gestion'     => ['label'=>'🏠 Gestion',     'codes'=>['BAIL','BAIL_SIGNE','BAIL_PROJET','AVIS_ECHEANCE','QUITTANCE','ETAT_LIEUX','DPE','ATTESTATION','MANDAT','AUTRE']],
        'transaction' => ['label'=>'🤝 Transaction', 'codes'=>['MANDAT_VENTE','MANDAT','OFFRE_ACHAT','COMPROMIS','ACTE_VENTE','ESTIMATION','DPE','AUTRE']],
        'syndic'      => ['label'=>'🏢 Syndic',      'codes'=>['APPEL_FONDS','PV_AG','CRG','ATTESTATION','AUTRE']],
        'rh'          => ['label'=>'🧑‍💼 RH',          'codes'=>['BULLETIN_PAIE','NOTE_FRAIS','JUSTIFICATIF','CONTRAT_TRAVAIL','CV','DIPLOME','AUTRE']],
        'compta'      => ['label'=>'💶 Compta',      'codes'=>['FACTURE','RELEVE_BANCAIRE','RIB','BILAN','AUTRE']],
        'fournisseur' => ['label'=>'🚚 Fournisseur', 'codes'=>['FACTURE','DEVIS','CONTRAT','RELANCE','AUTRE']],
        'societe'     => ['label'=>'📁 Société',     'codes'=>['KBIS','CNI','ATTESTATION','COURRIER','JUGEMENT','RIB','RELEVE_BANCAIRE','AUTRE']],
    ];
    // Ajoute les types créés (colonne metier renseignée).
    try { foreach ($pdo->query("SELECT code, metier FROM ged_document_types WHERE actif=1 AND metier IS NOT NULL AND metier<>''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (string)$r['metier']; if (isset($base[$k]) && !in_array($r['code'], $base[$k]['codes'], true)) $base[$k]['codes'][] = (string)$r['code'];
    } } catch (Throwable) {}
    $out = []; foreach ($base as $key => $m) $out[] = ['key'=>$key, 'label'=>$m['label'], 'codes'=>$m['codes']];
    return $out;
}

// Source de vérité des types = GLOSSAIRE `ged_level_codes` (référentiel complet,
// éditable). On expose TOUS les codes « de type » (niveaux ≥ 3, non placeholder,
// non virtuels, actifs), groupés par onglet métier via leur N1. La recherche du
// modal filtre sur l'ensemble → n'importe quel type reste trouvable.
// tenant_id 0 = socle commun ; + tenant courant = ajouts locaux.
function mbo_glossaire_list(PDO $pdo, int $tenant): array
{
    $METIER_N1 = [
        'gestion'     => ['🏠 Gestion',     ['03_GESTION_LOCATIVE']],
        'transaction' => ['🤝 Transaction', ['05_TRANSACTION']],
        'syndic'      => ['🏢 Syndic',      ['04_SYNDIC']],
        'rh'          => ['🧑‍💼 RH',          ['02_RH']],
        'compta'      => ['💶 Compta',      ['06_COMPTABILITE']],
        'fournisseur' => ['🚚 Fournisseur', ['13_FOURNISSEURS']],
        'societe'     => ['📁 Société',     ['01_DIRECTION', '01_AGENCE']],
        'juridique'   => ['⚖️ Juridique',   ['07_JURIDIQUE_CONTENTIEUX']],
        'marketing'   => ['📣 Marketing',   ['08_MARKETING_COMMUNICATION']],
    ];
    // Nœuds de RANGEMENT (archives, versions, périodes, buckets génériques) : ce sont
    // des dossiers, pas des types de document → exclus du picker. Le référentiel
    // ged_level_codes n'est PAS modifié (filtrage à la lecture, 100% réversible).
    $EXCLUDE_CODES = [
        'ARCHIVES','99_ARCHIVES','01_VERSION_EN_COURS','02_VERSIONS_PRECEDENTES',
        '03_DOCUMENTATION_USAGE','04_EXEMPLES_REMPLIS',
        'EN_COURS','VERSION_EN_COURS','VERSIONS_PRECEDENTES','VERSIONS','BROUILLONS',
        'ANCIENS','MOIS','DOSSIERS','MODULES','CATEGORIES','A_TRAITER','TYPE',
        'DOSSIERS_CLOTURES','DOCUMENTS','PIECES','OPERATIONS','INCIDENTS',
    ];
    $isNoise = static function (string $code, string $n1, string $n2) use ($EXCLUDE_CODES): bool {
        if (in_array($code, $EXCLUDE_CODES, true)) return true;
        if ($n1 === '12_ARCHIVES') return true;      // domaine Archives entier
        if ($n2 === 'ARCHIVES')    return true;      // sous-dossiers d'archive de chaque branche
        if (preg_match('/^EXERCICE_\d{4}$/', $code)) return true; // Exercice 2023…2027
        return false;
    };
    $st = $pdo->prepare("SELECT code, label, parent_n1, parent_n2 FROM ged_level_codes
        WHERE level_number >= 3 AND is_entity_placeholder = 0 AND is_virtual = 0
          AND is_active = 1 AND tenant_id IN (0, ?)
        ORDER BY label");
    $st->execute([$tenant]);
    $typesByCode = []; $byN1 = [];
    foreach ($st as $r) {
        $c = (string)$r['code']; if ($c === '') continue;
        if ($isNoise($c, (string)$r['parent_n1'], (string)$r['parent_n2'])) continue;
        if (!isset($typesByCode[$c])) $typesByCode[$c] = ((string)$r['label'] !== '' ? (string)$r['label'] : $c);
        $byN1[(string)$r['parent_n1']][$c] = true;
    }
    // Fusion des types custom éditables (ged_document_types) — rien ne se perd.
    try {
        foreach ($pdo->query("SELECT code, libelle FROM ged_document_types WHERE actif=1") as $r) {
            $c = (string)$r['code']; if ($c === '' || isset($typesByCode[$c])) continue;
            $typesByCode[$c] = (string)($r['libelle'] ?: $c);
        }
    } catch (Throwable) {}
    $metiers = [];
    foreach ($METIER_N1 as $key => $def) {
        $codes = [];
        foreach ($def[1] as $n1) foreach (array_keys($byN1[$n1] ?? []) as $c) $codes[$c] = true;
        $metiers[] = ['key' => $key, 'label' => $def[0], 'codes' => array_keys($codes)];
    }
    // Rattache les types custom (colonne metier) à leur onglet.
    try {
        foreach ($pdo->query("SELECT code, metier FROM ged_document_types WHERE actif=1 AND metier IS NOT NULL AND metier<>''") as $r) {
            foreach ($metiers as &$m) if ($m['key'] === (string)$r['metier'] && !in_array($r['code'], $m['codes'], true)) $m['codes'][] = (string)$r['code'];
            unset($m);
        }
    } catch (Throwable) {}
    $types = [];
    foreach ($typesByCode as $c => $l) $types[] = ['code' => $c, 'libelle' => $l];
    return ['types' => $types, 'metiers' => $metiers];
}

if (isset($_GET['list'])) {
    // Demande utilisateur (2026-07-07) : on ne conserve QUE les types curés « en dur »
    // (mbo_type_metiers), pas tout le glossaire ged_level_codes (qui débordait).
    // On élargira plus tard en rebranchant proprement sur ged_level_codes.
    // Bascule 100% réversible : remettre mbo_glossaire_list($pdo,$tenant) pour le glossaire complet.
    $metiers = mbo_type_metiers($pdo);            // [{key,label,codes:[CODE,…]}]
    $labels = [];
    try { foreach ($pdo->query("SELECT code, libelle FROM ged_document_types")->fetchAll(PDO::FETCH_ASSOC) as $r) $labels[(string)$r['code']] = (string)$r['libelle']; } catch (Throwable) {}
    $seen = []; $types = [];
    foreach ($metiers as &$m) {
        $m['codes'] = array_values(array_filter($m['codes'], fn($c) => $c !== 'AUTRE'));  // « Autre… » géré par le sélecteur
        foreach ($m['codes'] as $c) {
            if (isset($seen[$c])) continue; $seen[$c] = 1;
            $types[] = ['code' => $c, 'libelle' => $labels[$c] ?? ucfirst(strtolower(str_replace('_', ' ', $c)))];
        }
    }
    unset($m);
    echo json_encode(['ok'=>true, 'types'=>$types, 'metiers'=>$metiers], JSON_UNESCAPED_UNICODE); exit;
}

// AJOUT d'un type (super admin) : anti-doublon insensible à la casse + rattachement métier.
if (($_GET['action'] ?? '') === 'add') {
    $libelle = trim((string)($_GET['libelle'] ?? ''));
    $metier  = (string)($_GET['metier'] ?? '');
    $codeIn  = trim((string)($_GET['code'] ?? ''));
    if ($libelle === '') { echo json_encode(['ok'=>false,'error'=>'Libellé requis']); exit; }
    // Code = raccourci court. Fourni par l'admin sinon dérivé du libellé.
    // Normalisé : MAJUSCULES, accents retirés, non-alphanum → _, plafonné à 24 car.
    $src  = $codeIn !== '' ? $codeIn : $libelle;
    $code = strtoupper(@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$src) ?: $src);
    $code = trim(preg_replace('/[^A-Z0-9]+/', '_', $code), '_');
    if (strlen($code) > 24) $code = trim(substr($code, 0, 24), '_');
    if ($code === '') { echo json_encode(['ok'=>false,'error'=>'Code invalide']); exit; }
    // Anti-doublon INSENSIBLE À LA CASSE (sur code ou libellé).
    $chk = $pdo->prepare("SELECT code, libelle, metier FROM ged_document_types WHERE UPPER(code)=UPPER(?) OR UPPER(libelle)=UPPER(?) LIMIT 1");
    $chk->execute([$code, $libelle]);
    if ($ex = $chk->fetch(PDO::FETCH_ASSOC)) {
        // Où est-il ? on cherche dans quels métiers ce code apparaît.
        $found = [];
        foreach (mbo_type_metiers($pdo) as $m) if (in_array($ex['code'], $m['codes'], true)) $found[] = $m['label'];
        echo json_encode(['ok'=>false, 'duplicate'=>true, 'code'=>$ex['code'], 'libelle'=>$ex['libelle'],
            'error'=>'Type déjà existant : « ' . $ex['libelle'] . ' »' . ($found ? ' (dans ' . implode(', ', $found) . ')' : '')], JSON_UNESCAPED_UNICODE); exit;
    }
    $m2 = in_array($metier, ['gestion','transaction','syndic','rh','compta','fournisseur','societe'], true) ? $metier : null;
    // Abréviation d'affichage GED (optionnelle) : MAJ, alphanum, max 16 car.
    $abbr = strtoupper(preg_replace('/[^A-Z0-9]+/', '', strtoupper((string)($_GET['abbr'] ?? ''))));
    $abbr = $abbr !== '' ? substr($abbr, 0, 16) : null;
    try { $pdo->prepare("INSERT INTO ged_document_types (code, libelle, abbr, metier, actif) VALUES (?,?,?,?,1)")->execute([$code, $libelle, $abbr, $m2]); }
    catch (Throwable) { $pdo->prepare("INSERT INTO ged_document_types (code, libelle, metier, actif) VALUES (?,?,?,1)")->execute([$code, $libelle, $m2]); }
    echo json_encode(['ok'=>true, 'code'=>$code, 'libelle'=>$libelle, 'abbr'=>$abbr, 'metier'=>$m2], JSON_UNESCAPED_UNICODE); exit;
}

// MISE À JOUR d'un type existant (glossaire admin) : libellé, abréviation GED, métier, actif.
// Le CODE ne change JAMAIS (identifiant stable référencé par les docs) → aucun lien cassé.
if (($_GET['action'] ?? '') === 'update') {
    $code = strtoupper(trim((string)($_GET['code'] ?? '')));
    if ($code === '') { echo json_encode(['ok'=>false,'error'=>'Code requis']); exit; }
    $ex = $pdo->prepare("SELECT code FROM ged_document_types WHERE code=?"); $ex->execute([$code]);
    if (!$ex->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Type inconnu']); exit; }
    $libelle = trim((string)($_GET['libelle'] ?? ''));
    if ($libelle === '') { echo json_encode(['ok'=>false,'error'=>'Libellé requis']); exit; }
    // Anti-doublon libellé (insensible casse), en excluant le type courant.
    $chk = $pdo->prepare("SELECT code FROM ged_document_types WHERE UPPER(libelle)=UPPER(?) AND code<>? LIMIT 1");
    $chk->execute([$libelle, $code]);
    if ($dupCode = $chk->fetchColumn()) { echo json_encode(['ok'=>false,'duplicate'=>true,'error'=>'Libellé déjà utilisé par « '.$dupCode.' »'], JSON_UNESCAPED_UNICODE); exit; }
    $abbr = strtoupper(preg_replace('/[^A-Z0-9]+/', '', strtoupper((string)($_GET['abbr'] ?? ''))));
    $abbr = $abbr !== '' ? substr($abbr, 0, 16) : null;
    $metier = (string)($_GET['metier'] ?? '');
    $m2 = in_array($metier, ['gestion','transaction','syndic','rh','compta','fournisseur','societe'], true) ? $metier : null;
    $actif = ((string)($_GET['actif'] ?? '1')) === '0' ? 0 : 1;
    $pdo->prepare("UPDATE ged_document_types SET libelle=?, abbr=?, metier=?, actif=? WHERE code=?")
        ->execute([$libelle, $abbr, $m2, $actif, $code]);
    echo json_encode(['ok'=>true, 'code'=>$code, 'libelle'=>$libelle, 'abbr'=>$abbr, 'metier'=>$m2, 'actif'=>$actif], JSON_UNESCAPED_UNICODE); exit;
}

// TOGGLE actif/inactif rapide.
if (($_GET['action'] ?? '') === 'toggle') {
    $code = strtoupper(trim((string)($_GET['code'] ?? '')));
    if ($code === '') { echo json_encode(['ok'=>false,'error'=>'Code requis']); exit; }
    $pdo->prepare("UPDATE ged_document_types SET actif = 1 - actif WHERE code=?")->execute([$code]);
    $st = $pdo->prepare("SELECT actif FROM ged_document_types WHERE code=?"); $st->execute([$code]);
    echo json_encode(['ok'=>true, 'code'=>$code, 'actif'=>(int)$st->fetchColumn()]); exit;
}

$doc  = (int)($_REQUEST['doc'] ?? 0);
$code = strtoupper(trim((string)($_REQUEST['type'] ?? '')));
if ($doc <= 0 || $code === '') { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }

$chk = $pdo->prepare("SELECT libelle FROM ged_document_types WHERE code=? AND actif=1");
$chk->execute([$code]); $label = $chk->fetchColumn();
if ($label === false) { echo json_encode(['ok'=>false,'error'=>'Type inconnu']); exit; }

// mbo_type_propose est stocké en minuscules ; mbo_type_ged_code() le remet en MAJ pour le code.
$mboType = strtolower($code);
$pdo->prepare("UPDATE fluxbox_documents SET mbo_type_propose=? WHERE id=?")->execute([$mboType, $doc]);

$st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id=?"); $st->execute([$doc]); $d = $st->fetch(PDO::FETCH_ASSOC);
$gedname = $d ? mbo_build_ged_name($pdo, $d) : '';

echo json_encode(['ok'=>true, 'gedname'=>$gedname, 'type_label'=>$label, 'type'=>$mboType], JSON_UNESCAPED_UNICODE);
