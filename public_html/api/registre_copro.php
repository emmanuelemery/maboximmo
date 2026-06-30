<?php
declare(strict_types=1);
/**
 * api/registre_copro.php — Fiche copropriété depuis un point GPS, via le
 * Registre National d'Immatriculation des Copropriétés (RNC / ANAH).
 *
 * Chaîne : GPS → parcelle cadastrale (API Carto IGN) → match RNC par
 * (code INSEE + section + numéro de parcelle) → immatriculation + caractéristiques.
 * Aucune saisie : l'immatriculation est RETROUVÉE automatiquement.
 *
 * Sources officielles gratuites :
 *   - Cadastre  : https://apicarto.ign.fr/api/cadastre/parcelle
 *   - RNC       : data.gouv.fr (API tabulaire) resource cc062fa7-… (T3 2025, maj trimestrielle)
 *
 * GET : lat=FLOAT&lng=FLOAT
 * Réponse : { ok, trouve:bool, immatriculation, nom, nb_lots, nb_habitation,
 *             nb_stationnement, construction, syndic, representant, date_reglement,
 *             mandat_en_cours, cooperatif, residence_service, adresse, infos:[{label,value}] }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');

$lat  = (float)($_GET['lat'] ?? 0);
$lng  = (float)($_GET['lng'] ?? 0);
$cp   = preg_replace('/\D/', '', (string)($_GET['cp'] ?? ''));
$voie = trim((string)($_GET['voie'] ?? $_GET['adresse'] ?? ''));
// Il faut AU MOINS une piste : adresse (cp+voie) OU coordonnées.
if (($cp === '' || $voie === '') && ($lat === 0.0 || $lng === 0.0)) {
    exit(json_encode(['ok' => false, 'error' => 'cp+voie ou lat/lng requis']));
}

$RNC_RID = 'cc062fa7-4a80-449c-ab73-d2856b455ec9';

$getJson = static function (string $url, int $timeout = 10): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $r = curl_exec($ch); curl_close($ch);
    if ($r === false) return null;
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : null;
};

$row = null; $matchMode = ''; $section = ''; $numero = ''; $insee = '';

// ── MÉTHODE 1 (PRIORITAIRE) : recherche par ADRESSE (code postal + voie) ──────
// Simple et fiable : on filtre sur le code postal de référence + le nom de voie,
// puis on retient la copro dont l'adresse commence par le bon numéro.
if ($cp !== '' && $voie !== '') {
    // Normalise : enlève accents, numéro de tête et le type de voie (rue/cours/av…)
    $vn  = strtolower((string)(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $voie) ?: $voie));
    $num = preg_match('/^\s*(\d+)/', $vn, $mN) ? $mN[1] : '';
    $core = preg_replace('/^\s*\d+\s*(bis|ter|quater)?\s*/', '', $vn);
    $core = preg_replace('/\b(rue|cours|avenue|ave|av|boulevard|bd|place|pl|impasse|imp|allee|allees|chemin|che|quai|route|rte|montee|mtee|passage|cite|crs|r)\b/', ' ', $core);
    $core = trim((string)preg_replace('/\s+/', ' ', $core));
    if ($core !== '') {
        $q = http_build_query([
            'code_postal_adresse_de_reference__exact'        => $cp,
            'numero_et_voie_adresse_de_reference__contains'  => $core,
            'page_size'                                      => 30,
        ]);
        $res  = $getJson("https://tabular-api.data.gouv.fr/api/resources/{$RNC_RID}/data/?{$q}", 12);
        $rows = $res['data'] ?? [];
        if ($rows) {
            // Priorité : adresse qui COMMENCE par le numéro exact (ex. "152 crs albert thomas")
            if ($num !== '') {
                foreach ($rows as $r) {
                    $adr = strtolower(trim((string)($r['numero_et_voie_adresse_de_reference'] ?? '')));
                    if (preg_match('/^0*' . preg_quote($num, '/') . '\b/', $adr)) { $row = $r; break; }
                }
                // sinon : le numéro apparaît dans l'adresse (cas des plages "152-156")
                if (!$row) foreach ($rows as $r) {
                    $adr = strtolower((string)($r['numero_et_voie_adresse_de_reference'] ?? ''));
                    if (preg_match('/\b0*' . preg_quote($num, '/') . '\b/', $adr)) { $row = $r; break; }
                }
            }
            // Si le numéro est inconnu mais qu'il n'y a qu'un seul candidat, on le prend.
            if (!$row && $num === '' && count($rows) === 1) $row = $rows[0];
        }
        if ($row) $matchMode = 'adresse';
    }
}

// ── MÉTHODE 2 (SECOURS) : parcelle cadastrale du point GPS ────────────────────
if (!$row && $lat !== 0.0 && $lng !== 0.0) {
    $geom = json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]);
    $cad = $getJson('https://apicarto.ign.fr/api/cadastre/parcelle?geom=' . rawurlencode($geom));
    $p = $cad['features'][0]['properties'] ?? null;
    if ($p) {
        $insee   = (string)($p['code_dep'] ?? '') . (string)($p['code_com'] ?? '');
        $section = (string)($p['section'] ?? '');
        $numero  = (string)($p['numero'] ?? '');
        if ($insee !== '' && $section !== '' && $numero !== '') {
            for ($slot = 1; $slot <= 3 && !$row; $slot++) {
                $q = http_build_query([
                    "code_insee_commune_{$slot}__exact" => $insee,
                    "section_{$slot}__exact"            => $section,
                    "numero_parcelle_{$slot}__exact"    => $numero,
                    'page_size'                         => 1,
                ]);
                $res = $getJson("https://tabular-api.data.gouv.fr/api/resources/{$RNC_RID}/data/?{$q}", 12);
                if (!empty($res['data'][0])) $row = $res['data'][0];
            }
            if ($row) $matchMode = 'parcelle';
        }
    }
}

if (!$row) {
    exit(json_encode(['ok' => true, 'trouve' => false,
                      'raison' => ($cp !== '' && $voie !== '') ? 'aucune copropriété à cette adresse au registre' : 'parcelle non copropriété au registre',
                      'parcelle' => $section !== '' ? "$section $numero ($insee)" : ''], JSON_UNESCAPED_UNICODE));
}

// 3) Normalisation lisible
$periodes = [
    'AVANT_1949' => 'Avant 1949', 'DE_1949_A_1960' => '1949–1960', 'DE_1961_A_1974' => '1961–1974',
    'DE_1975_A_1993' => '1975–1993', 'DE_1994_A_2000' => '1994–2000', 'A_PARTIR_DE_2001' => 'À partir de 2001',
    'NON_CONNUE' => 'Non connue',
];
$g = static fn(string $k) => trim((string)($row[$k] ?? ''));
$constrCode = $g('periode_de_construction');
$construction = $periodes[$constrCode] ?? $constrCode;

// Booléen lisible (RNC encode souvent "oui"/"non"/"1"/"0"/vide)
$boolFr = static function (string $v): string {
    $v = mb_strtolower(trim($v));
    if (in_array($v, ['oui','1','true','o'], true)) return 'Oui';
    if (in_array($v, ['non','0','false','n'], true)) return 'Non';
    return '';
};

$out = [
    'immatriculation'   => $g('numero_d_immatriculation'),
    'nom'               => $g('nom_d_usage_de_la_copropriete'),
    'nb_lots'           => $g('nombre_total_de_lots'),
    'construction'      => $construction,
    'syndic'            => $g('type_de_syndic_benevole_professionnel_non_connu'),
    'date_maj'          => $g('date_de_la_derniere_maj'),
    'adresse'           => trim($g('numero_et_voie_adresse_de_reference') . ' ' . $g('code_postal_adresse_de_reference') . ' ' . $g('nom_officiel_commune')),
];

// Chips lisibles EXHAUSTIFS (uniquement les champs renseignés)
$infos = [];
$push = static function (string $label, $val) use (&$infos) {
    $v = trim((string)$val);
    if ($v !== '' && $v !== '0') $infos[] = ['label' => $label, 'value' => $v];
};

// Identité
$push('Immatriculation', $out['immatriculation']);
$push('Copropriété', $out['nom']);
$push('Adresse de référence', $out['adresse']);
$push('Adresses complémentaires', $g('nombre_d_adresses_complementaires'));
// Lots
$push('Lots (total)', $g('nombre_total_de_lots'));
$push("Lots d'habitation", $g('nombre_de_lots_a_usage_d_habitation'));
$push('Lots habitation/bureaux/commerces', $g('nombre_total_de_lots_a_usage_d_habitation_de_bureaux_ou_de_comm'));
$push('Stationnements', $g('nombre_de_lots_de_stationnement'));
// Bâti
$push('Période de construction', $out['construction']);
$push('Parcelles cadastrales', $g('nombre_de_parcelles_cadastrales'));
// Syndic / gouvernance
if ($g('type_de_syndic_benevole_professionnel_non_connu') !== '') $push('Type de syndic', ucfirst($g('type_de_syndic_benevole_professionnel_non_connu')));
$push('Représentant légal', $g('raison_sociale_du_representant_legal') ?: $g('identification_du_representant_legal_raison_sociale_et_le_numer'));
$push('SIRET représentant', $g('siret_du_representant_legal'));
if ($boolFr($g('mandat_en_cours_dans_la_copropriete')) !== '') $push('Mandat en cours', $boolFr($g('mandat_en_cours_dans_la_copropriete')));
$push('Fin du dernier mandat', $g('date_de_fin_du_dernier_mandat'));
if ($boolFr($g('syndicat_cooperatif')) !== '') $push('Syndicat coopératif', $boolFr($g('syndicat_cooperatif')));
$push('Type de syndicat', $g('syndicat_principal_ou_syndicat_secondaire'));
$push('N° immat. principal (si secondaire)', $g('si_secondaire_n_d_immatriculation_du_principal'));
if ($boolFr($g('residence_service')) !== '') $push('Résidence-service', $boolFr($g('residence_service')));
// Rattachements
$push('ASL rattachées', $g('nombre_d_asl_auxquelles_est_rattache_le_syndicat_de_coproprieta'));
$push('AFUL rattachées', $g('nombre_d_aful_auxquelles_est_rattache_le_syndicat_de_copropriet'));
$push('Unions de syndicats', $g('nombre_d_unions_de_syndicats_auxquelles_est_rattache_le_syndica'));
// Dispositifs / territoire
if ($boolFr($g('copro_aidee')) !== '') $push('Copropriété aidée', $boolFr($g('copro_aidee')));
$push('Quartier prioritaire (QPV)', $g('nom_qp_2024') ?: $g('nom_qp_2015'));
$push('EPCI', $g('nom_officiel_epci'));
// Dates clés
$push("Date d'immatriculation", $g('date_d_immatriculation'));
$push('Date du règlement de copro', $g('date_du_reglement_de_copropriete'));
$push('Dernière mise à jour (registre)', $out['date_maj']);

// 4) GARDE-FOU DE COHÉRENCE — anti « copro fausse mais certifiée ».
// Le match se fait par parcelle (issue des coordonnées) : si les coordonnées sont
// imprécises, on peut récupérer une copro d'un AUTRE quartier. On compare donc le
// code postal de la copro trouvée à celui de l'adresse confirmée du bien (param cp).
$cpAttendu = preg_replace('/\D/', '', (string)($_GET['cp'] ?? ''));
$cpCopro   = preg_replace('/\D/', '', $g('code_postal_adresse_de_reference'));
$coherent = true; $avertissement = '';
if ($cpAttendu !== '' && $cpCopro !== '' && $cpAttendu !== $cpCopro) {
    $coherent = false;
    $avertissement = "⚠️ Copropriété trouvée au $cpCopro alors que le bien est au $cpAttendu "
                   . "— résultat probablement FAUX (coordonnées imprécises). À vérifier, NE PAS rattacher automatiquement.";
}

echo json_encode([
    'ok' => true, 'trouve' => true, 'match_mode' => $matchMode,
    'coherent' => $coherent, 'avertissement' => $avertissement,
    'cp_attendu' => $cpAttendu, 'cp_copro' => $cpCopro,
    'immatriculation' => $out['immatriculation'], 'nom' => $out['nom'],
    'nb_lots' => $out['nb_lots'], 'construction' => $out['construction'],
    'construction_code' => $constrCode, 'syndic' => $out['syndic'], 'date_maj' => $out['date_maj'],
    'parcelle' => "$section $numero ($insee)", 'parcelle_section' => $section,
    'parcelle_numero' => $numero, 'parcelle_insee' => $insee,
    'infos' => $infos,
    'raw' => $row,   // snapshot complet pour persistance (Loi 2)
], JSON_UNESCAPED_UNICODE);
