<?php
declare(strict_types=1);
/**
 * api/pappers_search.php — Recherche société (proxy serveur, clé côté back).
 *
 * Primaire : API Pappers (riche : finances, dirigeants, capital, CA…).
 * Secours  : API gouvernementale « Recherche d'entreprises » (gratuite, sans clé)
 *            si pas de token Pappers ou quota dépassé.
 *
 * Le token Pappers vit dans la config back (PAPPERS_API_TOKEN) — JAMAIS exposé au front.
 *
 * GET :
 *   ?q=texte|SIREN(9)|SIRET(14)   → liste de candidats (ou détail direct si SIREN/SIRET)
 *   ?siren=123456789              → détail complet d'une société (dirigeants, finances)
 *
 * Contrat constant : { ok, data, source, confidence, error }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');

$token = getenv('PAPPERS_API_TOKEN') ?: (defined('PAPPERS_API_TOKEN') ? PAPPERS_API_TOKEN : '');
$q     = trim((string)($_GET['q'] ?? ''));
$siren = preg_replace('/\D+/', '', (string)($_GET['siren'] ?? ''));

$digits = preg_replace('/\D+/', '', $q);
if ($siren === '' && ($q !== '') && (strlen($digits) === 9 || strlen($digits) === 14)) {
    $siren = substr($digits, 0, 9);
}

function out($ok, $data, $source, $confidence, $error = null) {
    echo json_encode(['ok' => $ok, 'data' => $data, 'source' => $source,
        'confidence' => $confidence, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}
function http_get_json(string $url, int $t = 9): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $t,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false || $code >= 400) return null;
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : null;
}

/** Normalise un bloc « siège » Pappers. */
function adr_pappers(array $s): array {
    return [
        'adresse'     => trim((string)($s['adresse_ligne_1'] ?? '')),
        'code_postal' => (string)($s['code_postal'] ?? ''),
        'ville'       => (string)($s['ville'] ?? ''),
        'siret'       => (string)($s['siret'] ?? ''),
        'latitude'    => $s['latitude'] ?? null,
        'longitude'   => $s['longitude'] ?? null,
    ];
}

// ─────────────────────────────────────────────────────────────
// 1) DÉTAIL par SIREN
// ─────────────────────────────────────────────────────────────
if ($siren !== '') {
    if ($token !== '') {
        $d = http_get_json('https://api.pappers.fr/v2/entreprise?' . http_build_query([
            'api_token' => $token, 'siren' => $siren,
        ]), 12);
        if ($d && !empty($d['siren'])) {
            $s = $d['siege'] ?? [];
            $reps = [];
            foreach (($d['representants'] ?? []) as $r) {
                $nom = trim((string)($r['nom_complet'] ?? trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))));
                if ($nom === '' && !empty($r['denomination'])) $nom = (string)$r['denomination'];
                if ($nom !== '') $reps[] = ['nom' => $nom, 'qualite' => (string)($r['qualite'] ?? '')];
            }
            out(true, [
                'raison_sociale'  => (string)($d['nom_entreprise'] ?? ($d['denomination'] ?? '')),
                'siren'           => (string)$d['siren'],
                'forme_juridique' => (string)($d['forme_juridique'] ?? ''),
                'date_creation'   => (string)($d['date_creation'] ?? ''),
                'naf'             => trim((string)($d['code_naf'] ?? '') . ' ' . (string)($d['libelle_code_naf'] ?? '')),
                'capital'         => $d['capital'] ?? null,
                'chiffre_affaires'=> $d['chiffre_affaires'] ?? null,
                'resultat'        => $d['resultat'] ?? null,
                'effectif'        => (string)($d['effectif'] ?? ''),
                'siege'           => adr_pappers(is_array($s) ? $s : []),
                'dirigeants'      => $reps,
            ], 'pappers', 'certain');
        }
    }
    // Secours gouv (détail par SIREN)
    $g = http_get_json('https://recherche-entreprises.api.gouv.fr/search?' . http_build_query(['q' => $siren, 'per_page' => 1]));
    $r = $g['results'][0] ?? null;
    if ($r) {
        $reps = [];
        foreach (($r['dirigeants'] ?? []) as $x) {
            $nom = trim((string)(($x['prenoms'] ?? '') . ' ' . ($x['nom'] ?? ($x['denomination'] ?? ''))));
            if ($nom !== '') $reps[] = ['nom' => $nom, 'qualite' => (string)($x['qualite'] ?? '')];
        }
        out(true, [
            'raison_sociale'  => (string)($r['nom_complet'] ?? ($r['nom_raison_sociale'] ?? '')),
            'siren'           => (string)($r['siren'] ?? ''),
            'forme_juridique' => (string)($r['nature_juridique'] ?? ''),
            'date_creation'   => (string)($r['date_creation'] ?? ''),
            'naf'             => (string)($r['activite_principale'] ?? ''),
            'capital' => null, 'chiffre_affaires' => null, 'resultat' => null, 'effectif' => '',
            'siege' => [
                'adresse'     => (string)($r['siege']['adresse'] ?? ''),
                'code_postal' => (string)($r['siege']['code_postal'] ?? ''),
                'ville'       => (string)($r['siege']['libelle_commune'] ?? ''),
                'siret'       => (string)($r['siege']['siret'] ?? ''),
                'latitude'    => $r['siege']['latitude'] ?? null,
                'longitude'   => $r['siege']['longitude'] ?? null,
            ],
            'dirigeants' => $reps,
        ], 'annuaire-entreprises', 'certain');
    }
    out(false, null, 'pappers', 'manquant', 'Société introuvable');
}

// ─────────────────────────────────────────────────────────────
// 2) RECHERCHE texte → liste de candidats
// ─────────────────────────────────────────────────────────────
if ($q === '' || mb_strlen($q) < 3) out(true, ['candidats' => []], 'pappers', 'non_traite');

if ($token !== '') {
    $d = http_get_json('https://api.pappers.fr/v2/recherche?' . http_build_query([
        'api_token' => $token, 'q' => $q, 'par_page' => 5,
    ]), 10);
    if ($d && isset($d['resultats'])) {
        $cands = [];
        foreach ($d['resultats'] as $r) {
            $s = $r['siege'] ?? [];
            $cands[] = [
                'raison_sociale'  => (string)($r['nom_entreprise'] ?? ($r['denomination'] ?? '')),
                'siren'           => (string)($r['siren'] ?? ''),
                'forme_juridique' => (string)($r['forme_juridique'] ?? ''),
                'ville'           => (string)($s['ville'] ?? ''),
                'code_postal'     => (string)($s['code_postal'] ?? ''),
            ];
        }
        out(true, ['candidats' => $cands], 'pappers', 'certain');
    }
}
// Secours gouv
$g = http_get_json('https://recherche-entreprises.api.gouv.fr/search?' . http_build_query(['q' => $q, 'per_page' => 5]));
$cands = [];
foreach (($g['results'] ?? []) as $r) {
    $cands[] = [
        'raison_sociale'  => (string)($r['nom_complet'] ?? ($r['nom_raison_sociale'] ?? '')),
        'siren'           => (string)($r['siren'] ?? ''),
        'forme_juridique' => (string)($r['nature_juridique'] ?? ''),
        'ville'           => (string)($r['siege']['libelle_commune'] ?? ''),
        'code_postal'     => (string)($r['siege']['code_postal'] ?? ''),
    ];
}
out(true, ['candidats' => $cands], 'annuaire-entreprises', $cands ? 'certain' : 'manquant');
