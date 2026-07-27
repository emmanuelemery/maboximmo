<?php
declare(strict_types=1);
/**
 * dossier_vente_partage.php — Page PUBLIQUE (jeton, lecture seule) d'un dossier de vente,
 * partagée à un ACQUÉREUR / NOTAIRE / COMMERCIALISATEUR.
 *
 * Rendu = MÊME page que p.php (deal-room Régie EMERY) via inc/p_portefeuille_view.php :
 * hero + cards biens (photos + caractéristiques + finances) + modale par bien.
 * Les DOCUMENTS PARTAGÉS (docs_json) sont attachés à chaque bien (liens jeton
 * api/dossier_vente_doc.php → confidentiel/coffre structurellement refusés).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/dossier_vente.php';
$pdo = $GLOBALS['pdo'];

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Referrer-Policy: no-referrer');

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (!function_exists('dv_ged_shortname')) {
    function dv_ged_shortname(string $name): string {
        $ext = '';
        if (preg_match('/(\.[A-Za-z0-9]{2,5})$/', $name, $m)) { $ext = $m[1]; $name = substr($name, 0, -strlen($ext)); }
        $parts = explode('_', $name);
        if (count($parts) >= 6 && preg_match('/^[A-Z]{3,5}$/', $parts[0])) {
            $parts = array_slice($parts, 4);
            $parts = array_values(array_filter($parts, fn($p) => $p !== '' && $p !== '-'));
            return implode(' · ', $parts) . $ext;
        }
        return $name . $ext;
    }
}

function dvp_stop(string $titre, string $msg): void {
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($titre) . '</title>'
       . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#10254d;color:#fff;display:grid;place-items:center;height:100vh;margin:0;}.c{max-width:460px;text-align:center;padding:30px;}h1{font-size:22px;margin:0 0 10px;}p{color:#aebfd8;line-height:1.6;}</style></head>'
       . '<body><div class="c"><div style="font-size:46px;margin-bottom:10px;">🔒</div><h1>' . htmlspecialchars($titre) . '</h1><p>' . htmlspecialchars($msg) . '</p></div></body></html>';
    exit;
}

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if (strlen($token) < 32) dvp_stop('Lien invalide', 'Ce lien de partage est incomplet.');
$st = $pdo->prepare("SELECT * FROM dossier_vente_partage WHERE token = ? LIMIT 1");
$st->execute([$token]);
$share = $st->fetch(PDO::FETCH_ASSOC);
if (!$share)                       dvp_stop('Lien invalide', "Ce lien n'existe pas ou a été supprimé.");
if (!empty($share['revoked_at']))  dvp_stop('Accès clôturé', 'Ce partage a été révoqué.');
if (!empty($share['expires_at']) && strtotime((string)$share['expires_at']) < time())
                                   dvp_stop('Lien expiré', 'Ce lien de partage a expiré.');

try { $pdo->prepare("UPDATE dossier_vente_partage SET nb_vues = nb_vues + 1, last_view_at = NOW() WHERE id = ?")->execute([(int)$share['id']]); } catch (Throwable) {}

$role      = (string)($share['role_destinataire'] ?? 'acquereur');
$roleLbl   = ['acquereur'=>'Acquéreur', 'notaire'=>'Notaire', 'commercialisateur'=>'Commercialisateur'][$role] ?? 'Acquéreur';
$idDossier = (int)$share['id_dossier'];
$dossier   = dv_get($pdo, $idDossier);
if (!$dossier) dvp_stop('Dossier indisponible', 'Le dossier de vente est introuvable.');

// ── Documents partagés (docs_json) → {kind,label,url} (liens jeton) attachés à chaque bien ──
$sharedDocs = [];
$ids = array_values(array_filter(array_map('intval', json_decode((string)($share['docs_json'] ?? '[]'), true) ?: [])));
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sd = $pdo->prepare("SELECT id, name_display, name_file, document_type FROM ged_documents WHERE id IN ($in) AND status='active' ORDER BY document_type, id");
        $sd->execute($ids);
        foreach ($sd->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $t = strtoupper((string)$d['document_type']);
            $kind = str_contains($t, 'DPE') ? 'dpe'
                  : (str_contains($t, 'BAIL') ? 'bail'
                  : ((str_contains($t, 'TAXE') || $t === 'TF') ? 'tf'
                  : ((str_contains($t, 'CARREZ') || str_contains($t, 'BOUTIN') || str_contains($t, 'SURFACE')) ? 'carrez'
                  : (str_contains($t, 'REGLEMENT') ? 'reglement' : 'diag'))));
            $sharedDocs[] = [
                'kind'  => $kind,
                'label' => dv_ged_shortname((string)($d['name_display'] ?: ($d['name_file'] ?: $t))),
                'url'   => app_url('/api/dossier_vente_doc.php?t=' . $token . '&doc=' . (int)$d['id'] . '&mode=inline'),
            ];
        }
    } catch (Throwable) {}
}

// ── Lots du dossier → $biensJs (forme attendue par inc/p_portefeuille_view.php) ──
$pickKey = function(array $a, array $keys) { foreach ($keys as $k) { if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) return $a[$k]; } return null; };
$lots = function_exists('dv_lots') ? dv_lots($pdo, $idDossier) : [];
if (!$lots && (int)($dossier['id_bien'] ?? 0) > 0) $lots = [['id_bien' => (int)$dossier['id_bien']]];

$biensJs = [];
$adresse = '';
foreach ($lots as $l) {
    $bid = (int)($l['id_bien'] ?? 0); if ($bid <= 0) continue;
    $b = [];
    try {
        $sb = $pdo->prepare("SELECT b.*, bt.libelle AS type_libelle,
                                    COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS _adr,
                                    COALESCE(NULLIF(b.ville,''), i.ville) AS _ville,
                                    COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS _cp
                             FROM biens b
                             LEFT JOIN immeubles i ON i.id = b.id_immeuble
                             LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
                             WHERE b.id = ? LIMIT 1");
        $sb->execute([$bid]);
        $b = $sb->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
    // Photos.
    $photos = [];
    try {
        $ph = $pdo->prepare("SELECT COALESCE(NULLIF(url_lbc,''), url_photo) AS u FROM biens_photos WHERE ((entity_type='BIEN' AND entity_id = ?) OR id_bien = ?) ORDER BY ordre ASC, id ASC");
        $ph->execute([$bid, $bid]);
        foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $u) { $u = trim((string)$u); if ($u !== '') $photos[] = app_url('/' . ltrim($u, '/')); }
    } catch (Throwable) {}

    $ref    = (string)($b['reference_bien'] ?? '');
    $adr    = trim((string)($b['_adr'] ?? ''));
    $ville  = trim((string)($b['_ville'] ?? ''));
    $cp     = trim((string)($b['_cp'] ?? ''));
    $surf   = (float)($pickKey($b, ['surface_habitable', 'surface']) ?: 0);
    $prixL  = (float)(($l['prix_vente'] ?? null) ?? ($l['_prix_vente_bien'] ?? 0));
    $loyerA = (float)(($l['loyer_reel'] ?? null) ?? ($l['_loyer_reel_bien'] ?? 0));   // annuel
    $descr  = (string)($pickKey($b, ['description', 'descriptif', 'designation']) ?: '');
    if ($adresse === '') $adresse = trim($adr . ' ' . $cp . ' ' . $ville);

    $biensJs[] = [
        'dispo'    => true,
        'idb'      => $bid,
        'adr'      => $adr ?: ('Bien ' . $ref),
        'loc'      => trim($ville . ($cp ? ' · ' . $cp : '')),
        'ref'      => $ref,
        'surf'     => $surf ? rtrim(rtrim(number_format($surf, 0, ',', ' '), '0'), ',') . ' m²' : '—',
        'pm'       => ($prixL > 0 && $surf > 0) ? number_format($prixL / $surf, 0, ',', ' ') . ' €' : '—',
        'type'     => (string)($b['type_libelle'] ?? ''),
        'meuble'   => false,
        'pro'      => false,
        'annee'    => (string)($pickKey($b, ['annee_construction']) ?: '—'),
        'chauf'    => (string)($pickKey($b, ['chauffage', 'type_chauffage', 'mode_chauffage']) ?: '—'),
        'dpe'      => (string)($pickKey($b, ['dpe_classe', 'classe_dpe', 'dpe']) ?: '—'),
        'descr'    => $descr,
        'annonce'  => (string)($pickKey($b, ['bien_annonce_affiche', 'annonce_texte_lbc', 'reprise_descriptif']) ?: ''),
        'photos'   => $photos,
        'docs'     => $sharedDocs,   // mêmes documents partagés pour chaque bien du dossier
        'loyer'    => $loyerA ? number_format($loyerA / 12, 0, ',', ' ') . ' €' : '—',
        'bail'     => null,
        'loyerMax' => '—',
        'encStatut'=> '',
        'cp'       => $cp,
        'lat'      => (string)($pickKey($b, ['latitude']) ?: ''),
        'lng'      => (string)($pickKey($b, ['longitude']) ?: ''),
        'pv'       => $prixL ? number_format($prixL, 0, ',', ' ') . ' €' : '—',
        'ho'       => '—',
        'nv'       => '—',
        'rdt'      => ($prixL > 0 && $loyerA > 0) ? rtrim(rtrim(number_format($loyerA / $prixL * 100, 1, ',', ' '), '0'), ',') . ' %' : '—',
        'simRate'  => '3,40',
        'simUsage' => 'habitation',
    ];
}

// ── Variables d'en-tête attendues par le template ──
$destNom     = trim((string)($share['libelle'] ?? '')) ?: $roleLbl;
$destPrenom  = '';
$destNomSeul = $destNom;
$destEmail   = '';
$destTel     = '';
$destInit    = mb_strtoupper(mb_substr($destNom, 0, 1)) ?: '👤';
$typeDest    = $role === 'commercialisateur' ? 'commercialisateur' : '';
$pxMin = $pxMax = $sfMin = $sfMax = null;
$nbBiens = count($biensJs);
$needConsent = false;
$joursRestants = !empty($share['expires_at']) ? max(0, (int)ceil((strtotime((string)$share['expires_at']) - time()) / 86400)) : null;
$fmtK = fn($v) => $v === null || $v === '' ? null : (number_format((float)$v / 1000, 0, ',', ' ') . ' k€');
$header = [];
$portefeuilles = [];   // pas de sélecteur multi-portefeuilles pour un partage transaction
$isPreview = false;
$envoi = [
    'sujet'           => 'Dossier de vente — ' . ($adresse ?: (string)($dossier['reference'] ?? '')),
    'id_user'         => (int)($share['created_by'] ?? 0),
    'date_envoi'      => $share['created_at'] ?? null,
    'date_expiration' => $share['expires_at'] ?? null,
];

// ── Branding (société/agence du dossier) + conseiller (créateur du partage) ──
$brand = [
    'nom'     => 'Régie EMERY',
    'logo'    => app_url('/images/logos/regie-emery.jpg'),
    'tagline' => 'Location – Gestion – Syndic – Transaction',
    'email'   => 'contact@regie-emery.com',
    'ville'   => 'Lyon',
];
try {
    if (!empty($dossier['id_societe'])) {
        $bs = $pdo->prepare("SELECT nom, logo_url FROM societes WHERE id = ? LIMIT 1");
        $bs->execute([(int)$dossier['id_societe']]);
        if ($s = $bs->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($s['nom']))      $brand['nom']  = (string)$s['nom'];
            if (!empty($s['logo_url'])) $brand['logo'] = app_url('/' . ltrim((string)$s['logo_url'], '/'));
        }
    }
    if (!empty($dossier['id_agence'])) {
        $ba = $pdo->prepare("SELECT ville, email FROM agences WHERE id = ? LIMIT 1");
        $ba->execute([(int)$dossier['id_agence']]);
        if ($a = $ba->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($a['ville'])) $brand['ville'] = ucwords(mb_strtolower((string)$a['ville']));
            if (!empty($a['email'])) $brand['email'] = (string)$a['email'];
        }
    }
} catch (Throwable) {}

$conseiller = null;
try {
    $uid = (int)($share['created_by'] ?? 0);
    if ($uid > 0) {
        $cs = $pdo->prepare("SELECT nom, prenom, fonction, email, telephone, telephone_pro, photo_url, avatar_url FROM users WHERE id = ? LIMIT 1");
        $cs->execute([$uid]);
        if ($u = $cs->fetch(PDO::FETCH_ASSOC)) {
            $cPhoto = trim((string)($u['photo_url'] ?? '')) ?: trim((string)($u['avatar_url'] ?? ''));
            if ($cPhoto !== '' && !preg_match('~^https?://~i', $cPhoto)) $cPhoto = app_url('/' . ltrim($cPhoto, '/'));
            $conseiller = [
                'nom'      => trim((string)($u['prenom'] ?? '') . ' ' . (string)($u['nom'] ?? '')),
                'init'     => mb_strtoupper(mb_substr((string)($u['prenom'] ?? ''), 0, 1) . mb_substr((string)($u['nom'] ?? ''), 0, 1)),
                'fonction' => trim((string)($u['fonction'] ?? '')),
                'email'    => trim((string)($u['email'] ?? '')),
                'tel'      => trim((string)($u['telephone_pro'] ?? '')) ?: trim((string)($u['telephone'] ?? '')),
                'photo'    => $cPhoto,
            ];
        }
    }
} catch (Throwable) {}

require __DIR__ . '/inc/p_portefeuille_view.php';
