<?php
// p.php — Page PUBLIQUE d'un portefeuille (mini-site privé à jeton). SANS login.
// Accès garanti par un token imprévisible (portefeuille_envois.token), avec expiration,
// révocation, et preuve de consentement (1er accès). Finances FIGÉES (snapshot_json) ;
// contenu du bien LU EN DIRECT (descriptif, photos, GED, loyer).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';   // PDO, app_url, helpers — PAS d'auth/login ici.
require_once __DIR__ . '/inc/encadrement_helper.php';   // calcul officiel du plafond (Lyon/Villeurbanne)
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();
$e   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ── Sécurité : espace privé à jeton ──────────────────────────────────
// Jamais indexable (donnée privée) + pas de fuite du jeton via le Referer
// lors d'un clic sortant (le jeton est dans l'URL).
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Referrer-Policy: no-referrer');

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));

/** Page d'erreur sobre (lien invalide / expiré / révoqué). */
function pf_pub_stop(string $titre, string $msg): void {
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($titre) . '</title>'
       . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#10254d;color:#fff;display:grid;place-items:center;height:100vh;margin:0;}'
       . '.c{max-width:460px;text-align:center;padding:30px;}h1{font-size:22px;margin:0 0 10px;}p{color:#aebfd8;line-height:1.6;}</style></head>'
       . '<body><div class="c"><div style="font-size:46px;margin-bottom:10px;">🔒</div><h1>' . htmlspecialchars($titre) . '</h1><p>' . htmlspecialchars($msg) . '</p></div></body></html>';
    exit;
}

$isPreview     = false;
$preview       = (int)($_GET['preview'] ?? 0);
$portefeuilles = [];
$joursRestants = null;

if ($preview > 0) {
    // ===== APERÇU INTERNE (staff connecté) : rendu live, sans jeton ni consentement =====
    require_once __DIR__ . '/inc/auth.php';
    require_login();
    // Aperçu réservé au personnel : on se base sur le VRAI rôle du compte ($_SESSION['id_role']),
    // pas sur current_role_id() qui renvoie le rôle de TEST/impersonation (« voir en tant que »
    // bailleur/investisseur). Sinon un staff en mode impersonation se voit refuser son propre aperçu.
    $staffRoles = [1, 2, 3, 7];
    $realRole   = (int)($_SESSION['id_role'] ?? 0);
    $effRole    = (int)current_role_id();
    if (!in_array($realRole, $staffRoles, true) && !in_array($effRole, $staffRoles, true)) {
        pf_pub_stop('Accès refusé', 'Aperçu réservé au personnel.');
    }
    $pf = $pdo->prepare("SELECT * FROM portefeuilles WHERE id = ?"); $pf->execute([$preview]); $pf = $pf->fetch(PDO::FETCH_ASSOC);
    if (!$pf) pf_pub_stop('Introuvable', 'Portefeuille introuvable.');
    $lr = $pdo->prepare("SELECT * FROM portefeuille_biens WHERE id_portefeuille = ? ORDER BY ordre, id"); $lr->execute([$preview]);
    $lignes = array_map(fn($l) => [
        'id_bien' => (int)$l['id_bien'], 'prix_vente' => $l['prix_vente'], 'prix_m2' => $l['prix_m2'], 'rendement' => $l['rendement'],
        'honoraires_pct' => $l['honoraires_pct'], 'honoraires_montant' => $l['honoraires_montant'], 'net_vendeur' => $l['net_vendeur'],
        'snap_adresse' => $l['snap_adresse'], 'snap_ville' => $l['snap_ville'], 'snap_reference' => $l['snap_reference'],
        'snap_surface' => $l['snap_surface'], 'snap_loyer_mensuel' => $l['snap_loyer_mensuel'], 'ordre' => (int)($l['ordre'] ?? 0),
    ], $lr->fetchAll(PDO::FETCH_ASSOC));
    $header = [
        'nom' => $pf['nom'] ?? '', 'type_destinataire' => $pf['type_destinataire'] ?? null,
        'destinataire_nom' => $pf['destinataire_nom'] ?? null, 'destinataire_prenom' => $pf['destinataire_prenom'] ?? null,
        'destinataire_email' => $pf['destinataire_email'] ?? null,
        'critere_prix_min' => $pf['critere_prix_min'] ?? null, 'critere_prix_max' => $pf['critere_prix_max'] ?? null,
        'critere_surf_min' => $pf['critere_surf_min'] ?? null, 'critere_surf_max' => $pf['critere_surf_max'] ?? null,
    ];
    $envoi = ['id' => 0, 'id_portefeuille' => $preview, 'email_destinataire' => '', 'sujet' => (string)($pf['nom'] ?? ''),
              'date_envoi' => date('Y-m-d H:i:s'), 'date_expiration' => null, 'actif' => 1, 'consent_at' => date('Y-m-d H:i:s'),
              'snapshot_json' => json_encode(['header' => $header, 'lignes' => $lignes])];
    $isPreview = true; $needConsent = false;
} else {
if (strlen($token) < 20) pf_pub_stop('Lien invalide', 'Ce lien d\'accès n\'est pas valide.');

$st = $pdo->prepare("SELECT * FROM portefeuille_envois WHERE token = ? LIMIT 1");
$st->execute([$token]);
$envoi = $st->fetch(PDO::FETCH_ASSOC);
if (!$envoi)                              pf_pub_stop('Lien introuvable', 'Ce lien d\'accès n\'existe pas ou a été supprimé.');
if ((int)$envoi['actif'] !== 1)           pf_pub_stop('Accès clôturé', 'Cet accès a été clôturé. Contactez votre conseiller pour en obtenir un nouveau.');
if (!empty($envoi['date_expiration']) && strtotime((string)$envoi['date_expiration']) < time())
                                          pf_pub_stop('Accès expiré', 'Cet accès a expiré. Contactez votre conseiller pour le renouveler.');

$snap   = json_decode((string)$envoi['snapshot_json'], true) ?: ['header' => [], 'lignes' => []];
$header = $snap['header'] ?? [];
$lignes = $snap['lignes'] ?? [];

// ── Espace destinataire : tous SES portefeuilles actifs (même email) → sélecteur + consentement unique ──
$portefeuilles = [];
$anyConsent = !empty($envoi['consent_at']);
try {
    $ps = $pdo->prepare("SELECT pe.token, pe.date_envoi, pe.nb_biens, pe.consent_at, p.nom
                         FROM portefeuille_envois pe
                         LEFT JOIN portefeuilles p ON p.id = pe.id_portefeuille
                         WHERE pe.actif = 1 AND (pe.date_expiration IS NULL OR pe.date_expiration >= NOW())
                           AND LOWER(pe.email_destinataire) = LOWER(?)
                         ORDER BY pe.date_envoi DESC");
    $ps->execute([(string)$envoi['email_destinataire']]);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['consent_at'])) $anyConsent = true;
        $portefeuilles[] = [
            'token'   => (string)$r['token'],
            'nom'     => (string)($r['nom'] ?: ('Portefeuille')),
            'nb'      => (int)$r['nb_biens'],
            'date'    => (string)$r['date_envoi'],
            'current' => hash_equals((string)$r['token'], $token),
        ];
    }
} catch (Throwable $ex) {}

// ── Consentement : POST d'acceptation (vaut pour tout l'espace du destinataire) ──
$needConsent = !$anyConsent;
if ($needConsent && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'consent') {
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $ip)[0]);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    // Le consentement vaut pour TOUS les portefeuilles actifs du même destinataire (un seul espace).
    $pdo->prepare("UPDATE portefeuille_envois SET consent_at=NOW(), consent_ip=?, consent_user_agent=?
                   WHERE consent_at IS NULL AND actif=1 AND LOWER(email_destinataire)=LOWER(?)")
        ->execute([substr($ip, 0, 64), $ua, (string)$envoi['email_destinataire']]);
    // Sécurité : on s'assure que l'envoi courant est bien marqué même si l'email diffère.
    $pdo->prepare("UPDATE portefeuille_envois SET consent_at=COALESCE(consent_at,NOW()), consent_ip=COALESCE(consent_ip,?), consent_user_agent=COALESCE(consent_user_agent,?) WHERE id=?")
        ->execute([substr($ip, 0, 64), $ua, (int)$envoi['id']]);
    header('Location: ' . app_url('/p.php?t=' . $token));
    exit;
}

// ── Suivi de consultation (best-effort) ──
try {
    $pdo->prepare("UPDATE portefeuille_envois SET date_derniere_consultation=NOW(),
                   date_premiere_consultation=COALESCE(date_premiere_consultation, NOW()) WHERE id=?")
        ->execute([(int)$envoi['id']]);
} catch (Throwable $ex) {}
// Journal d'événements enrichi : ouverture de l'espace.
require_once __DIR__ . '/inc/portefeuille_track.php';
pf_track($pdo, $envoi, 'open');
}   // fin du mode jeton (else de l'aperçu)

// ─────────────────────────────────────────────────────────────────────────────
// Construction des données des biens : finances FIGÉES (snapshot) + contenu LIVE.
// ─────────────────────────────────────────────────────────────────────────────
$numFr = fn($v) => $v === null || $v === '' ? null : (float)$v;
$pickKey = function(array $row, array $keys) {            // 1re colonne existante non vide
    foreach ($keys as $k) { if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k]; }
    return null;
};
// Pack d'acquisition autorisé au destinataire du portefeuille : Bail signé, DPE,
// Surfaces (Carrez/Boutin), Taxe foncière, EDL d'entrée. (PAS d'avis d'échéance/quittance.)
$docTypes = ['BAIL', 'BAIL_SIGNE', 'DPE', 'DIAG_DPE', 'DIAG', 'DIAGNOSTIC', 'DDT',
             'SURFACE_CARREZ', 'CARREZ', 'BOUTIN', 'SURFACE',
             'TAXE_FONCIERE', 'TF', 'EDL_ENTREE'];   // GED autorisés au destinataire

// Biens ENCORE dans le portefeuille (un retrait côté agence rend le bien « plus disponible »).
$currentSet = [];
try {
    $cs = $pdo->prepare("SELECT id_bien FROM portefeuille_biens WHERE id_portefeuille = ?");
    $cs->execute([(int)$envoi['id_portefeuille']]);
    $currentSet = array_flip(array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN)));
} catch (Throwable $ex) {}

$biensJs = [];
$nbDispo = 0;
foreach ($lignes as $L) {
    $idBien = (int)($L['id_bien'] ?? 0);
    if ($idBien <= 0) continue;

    // Bien live (souple : on lit ce qui existe).
    $b = null;
    try {
        $q = $pdo->prepare("SELECT b.*, i.adresse_1 AS i_adresse, i.code_postal AS i_cp, i.ville AS i_ville,
                                   tb.libelle AS type_libelle, tb.categorie AS tb_categorie
                            FROM biens b
                            LEFT JOIN immeubles i ON i.id = b.id_immeuble
                            LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
                            WHERE b.id = ? LIMIT 1");
        $q->execute([$idBien]);
        $b = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ex) {
        try { $q2 = $pdo->prepare("SELECT * FROM biens WHERE id = ? LIMIT 1"); $q2->execute([$idBien]); $b = $q2->fetch(PDO::FETCH_ASSOC) ?: null; }
        catch (Throwable $ex2) { $b = null; }
    }

    // Disponibilité : retiré du portefeuille, ou bien vendu/archivé/supprimé → « plus disponible ».
    $dispo = isset($currentSet[$idBien]);
    if ($b) {
        $sb = strtolower((string)($b['statut_bien'] ?? ''));
        if (in_array($sb, ['supprime', 'archive', 'vendu', 'vendue'], true)) $dispo = false;
    } else {
        $dispo = false;   // bien retiré de la base
    }
    if (!$dispo) {
        $biensJs[] = [
            'dispo' => false,
            'adr'   => ($b['adresse_1'] ?? ($L['snap_adresse'] ?? '')) ?: ('Bien ' . ($L['snap_reference'] ?? '')),
            'loc'   => trim((string)(($b['ville'] ?? ($L['snap_ville'] ?? '')))),
            'ref'   => (string)($b['reference_bien'] ?? ($L['snap_reference'] ?? '')),
        ];
        continue;
    }
    $nbDispo++;

    // Adresse / localisation (snapshot en repli).
    $adr   = $b ? ($pickKey($b, ['adresse_1']) ?: $b['i_adresse'] ?? '') : ($L['snap_adresse'] ?? '');
    $cp    = $b ? ($pickKey($b, ['code_postal']) ?: $b['i_cp'] ?? '') : '';
    $ville = $b ? ($pickKey($b, ['ville']) ?: $b['i_ville'] ?? '') : ($L['snap_ville'] ?? '');
    $ref   = $b ? ($b['reference_bien'] ?? ($L['snap_reference'] ?? '')) : ($L['snap_reference'] ?? '');
    $surf  = $b ? $numFr($pickKey($b, ['surface_habitable', 'surface_carrez'])) : $numFr($L['snap_surface'] ?? null);
    $cat   = $b ? strtolower((string)($pickKey($b, ['tb_categorie', 'usage_bien']) ?: '')) : '';
    $isPro = $cat !== '' && $cat !== 'habitation';
    $meuble = $b ? (int)($pickKey($b, ['meuble', 'meuble_vide', 'est_meuble']) ? 1 : 0) : 0;

    // Photos (biens_photos).
    $photos = [];
    try {
        $ph = $pdo->prepare("SELECT url_photo FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
        $ph->execute([$idBien]);
        foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $u) {
            if ($u) $photos[] = app_url('/' . ltrim((string)$u, '/'));
        }
    } catch (Throwable $ex) {}

    // Bail actif → loyer mensuel HC + conditions (charges, DG, indice, durée, échéance).
    $loyer = $numFr($L['snap_loyer_mensuel'] ?? null);
    $bailInfo = null;
    try {
        $bx = $pdo->prepare("SELECT loyer_mensuel_hc, charges_mensuelles, depot_garantie,
                                    indice_type, indice_trimestre, bail_nature, date_prise_effet, date_fin
                             FROM bien_baux WHERE id_bien=? AND statut='actif' ORDER BY id DESC LIMIT 1");
        $bx->execute([$idBien]);
        $bb = $bx->fetch(PDO::FETCH_ASSOC);
        if ($bb) {
            if (($bb['loyer_mensuel_hc'] ?? null) !== null && (float)$bb['loyer_mensuel_hc'] > 0) $loyer = (float)$bb['loyer_mensuel_hc'];
            $fmt = fn($v) => ($v === null || $v === '' || (float)$v == 0.0) ? null : number_format((float)$v, 0, ',', ' ') . ' €';
            $bailInfo = array_filter([
                'nature'   => $bb['bail_nature'] ?: null,
                'charges'  => $fmt($bb['charges_mensuelles'] ?? null),
                'dg'       => $fmt($bb['depot_garantie'] ?? null),
                'indice'   => trim((string)($bb['indice_type'] ?? '') . ' ' . (string)($bb['indice_trimestre'] ?? '')) ?: null,
                'effet'    => $bb['date_prise_effet'] ?: null,
                'fin'      => $bb['date_fin'] ?: null,
            ], fn($v) => $v !== null && $v !== '');
        }
    } catch (Throwable $ex) {}

    // Encadrement : plafond mensuel = loyer de réf. majoré officiel (€/m²) × surface.
    // Calcul live via le helper (CP → zone, pièces, époque, meublé) — Métropole de Lyon/Villeurbanne.
    $loyerMax = null;
    if ($surf && $cp !== '' && function_exists('enc_cp_to_zone')) {
        $zone = enc_cp_to_zone((string)$cp);
        if ($zone !== null) {
            $pieces  = (int)($pickKey($b ?: [], ['nb_pieces']) ?: 0) ?: 1;
            $anneeBI = $pickKey($b ?: [], ['annee_construction']);
            $epoque  = enc_annee_to_epoque($anneeBI !== null ? (int)$anneeBI : null);
            $tarif   = enc_tarifs_lookup($zone, $pieces, $epoque, (bool)$meuble);
            if ($tarif) $loyerMax = (float)round($tarif[1] * $surf);   // [ref, MAX, min] → loyer majoré
        }
    }
    $encStatut = $loyerMax ? (($loyer && $loyer > $loyerMax) ? 'non_conforme' : 'conforme') : '';

    // Documents GED autorisés (DPE / DIAG / Bail) → liens sécurisés par jeton.
    $docs = [];
    try {
        $in = implode(',', array_fill(0, count($docTypes), '?'));
        $gd = $pdo->prepare("SELECT gd.id, gd.document_type, gd.name_display
                             FROM ged_documents gd
                             JOIN ged_document_links gdl ON gdl.document_id = gd.id
                             WHERE gdl.entity_type='BIEN' AND gdl.entity_id=? AND gd.status='active'
                               AND UPPER(gd.document_type) IN ($in)
                             ORDER BY gd.document_type");
        $gd->execute(array_merge([$idBien], $docTypes));
        foreach ($gd->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $t = strtoupper((string)$d['document_type']);
            $kind = $t === 'DPE' ? 'dpe' : ($t === 'BAIL' ? 'bail' : 'diag');
            $docs[] = ['kind' => $kind, 'label' => (string)($d['name_display'] ?: $t),
                       'url' => app_url('/api/portefeuille_doc.php?t=' . $token . '&doc=' . (int)$d['id'])];
        }
    } catch (Throwable $ex) {}

    // Finances FIGÉES (snapshot).
    $pv = $numFr($L['prix_vente'] ?? null);
    $hm = $numFr($L['honoraires_montant'] ?? null);
    $hp = $numFr($L['honoraires_pct'] ?? null);
    $nv = $numFr($L['net_vendeur'] ?? null) ?? ($pv !== null && $hm !== null ? $pv - $hm : null);
    $pm = $numFr($L['prix_m2'] ?? null);
    $rdt = $numFr($L['rendement'] ?? null);

    $biensJs[] = [
        'dispo'    => true,
        'idb'      => $idBien,
        'adr'      => $adr ?: ('Bien ' . $ref),
        'loc'      => trim(($ville ?: '') . ($cp ? ' · ' . $cp : '')),
        'ref'      => $ref,
        'surf'     => $surf ? rtrim(rtrim(number_format($surf, 0, ',', ' '), '0'), ',') . ' m²' : '—',
        'pm'       => $pm ? number_format($pm, 0, ',', ' ') . ' €' : '—',
        'type'     => $b ? (string)($b['type_libelle'] ?? '') : '',
        'meuble'   => (bool)$meuble,
        'pro'      => $isPro,
        'annee'    => $b ? (string)($pickKey($b, ['annee_construction']) ?: '—') : '—',
        'chauf'    => $b ? (string)($pickKey($b, ['chauffage', 'type_chauffage', 'mode_chauffage']) ?: '—') : '—',
        'dpe'      => $b ? (string)($pickKey($b, ['dpe_classe', 'classe_dpe', 'dpe']) ?: '—') : '—',
        'descr'    => $b ? (string)($pickKey($b, ['description', 'descriptif', 'designation']) ?: '') : '',
        'annonce'  => $b ? (string)($pickKey($b, ['texte_annonce', 'annonce', 'commentaire']) ?: '') : '',
        'photos'   => $photos,
        'loyer'    => $loyer ? number_format($loyer, 0, ',', ' ') . ' €' : '—',
        'bail'     => $bailInfo ?: null,   // conditions de bail extraites (charges, DG, indice, durée…)
        'loyerMax' => $loyerMax ? number_format($loyerMax, 0, ',', ' ') . ' €' : '—',
        'encStatut'=> $encStatut,
        'cp'       => (string)$cp,
        'pv'       => $pv ? number_format($pv, 0, ',', ' ') . ' €' : '—',
        'ho'       => $hm ? number_format($hm, 0, ',', ' ') . ' €' . ($hp ? ' (' . rtrim(rtrim(number_format($hp,1,',',' '),'0'),',') . ' %)' : '') : '—',
        'nv'       => $nv ? number_format($nv, 0, ',', ' ') . ' €' : '—',
        'rdt'      => $rdt ? rtrim(rtrim(number_format($rdt,1,',',' '),'0'),',') . ' %' : '—',
        'simRate'  => $isPro ? '4,20' : '3,40',
        'simUsage' => $isPro ? 'immobilier professionnel' : 'habitation',
    ];
}

// Données d'en-tête pour le hero / critères / destinataire.
$destPrenom = (string)($header['destinataire_prenom'] ?? '');
$destNomSeul= (string)($header['destinataire_nom'] ?? '');
$destEmail  = (string)($header['destinataire_email'] ?? '');
$destTel    = (string)($header['destinataire_tel'] ?? '');
$destNom    = trim($destPrenom . ' ' . $destNomSeul);
$destInit   = strtoupper(substr($destPrenom, 0, 1) . substr($destNomSeul, 0, 1)) ?: '👤';
$typeDest   = (string)($header['type_destinataire'] ?? '');
$pxMin = $header['critere_prix_min'] ?? null; $pxMax = $header['critere_prix_max'] ?? null;
$sfMin = $header['critere_surf_min'] ?? null; $sfMax = $header['critere_surf_max'] ?? null;
$nbBiens = $nbDispo;   // le compteur met en avant les biens encore disponibles

// Conseiller = l'utilisateur qui a envoyé le portefeuille (envoi.id_user).
// Repli sur l'identité générique Régie EMERY si non rattaché (ex. preview).
$conseiller = null;
if (!empty($envoi['id_user'])) {
    $cs = $pdo->prepare("SELECT nom, prenom, fonction, email, telephone, telephone_pro, photo_url, avatar_url FROM users WHERE id = ? LIMIT 1");
    $cs->execute([(int)$envoi['id_user']]);
    if ($u = $cs->fetch(PDO::FETCH_ASSOC)) {
        $cNom   = trim((string)($u['prenom'] ?? '') . ' ' . (string)($u['nom'] ?? ''));
        $cPhoto = trim((string)($u['photo_url'] ?? '')) ?: trim((string)($u['avatar_url'] ?? ''));
        if ($cPhoto !== '' && !preg_match('~^https?://~i', $cPhoto)) $cPhoto = app_url('/' . ltrim($cPhoto, '/'));
        $conseiller = [
            'nom'     => $cNom,
            'init'    => strtoupper(mb_substr((string)($u['prenom'] ?? ''), 0, 1) . mb_substr((string)($u['nom'] ?? ''), 0, 1)),
            'fonction'=> trim((string)($u['fonction'] ?? '')),
            'email'   => trim((string)($u['email'] ?? '')),
            'tel'     => trim((string)($u['telephone_pro'] ?? '')) ?: trim((string)($u['telephone'] ?? '')),
            'photo'   => $cPhoto,
        ];
    }
}

// Jours restants.
$joursRestants = !empty($envoi['date_expiration']) ? max(0, (int)ceil((strtotime((string)$envoi['date_expiration']) - time()) / 86400)) : null;

$fmtK = fn($v) => $v === null || $v === '' ? null : (number_format((float)$v / 1000, 0, ',', ' ') . ' k€');

// ── Branding dynamique (société/agence de l'expéditeur) ──────────────
// Fallback : Régie EMERY. Sinon, on lit la société (nom + logo) et l'agence
// (ville/email) de l'utilisateur qui a envoyé le portefeuille.
$brand = [
    'nom'     => 'Régie EMERY',
    'logo'    => app_url('/images/logos/regie-emery.jpg'),
    'tagline' => 'Location – Gestion – Syndic – Transaction',
    'email'   => 'contact@regie-emery.com',
    'ville'   => 'Lyon',
];
try {
    if (!empty($envoi['id_user'])) {
        $bu = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ? LIMIT 1");
        $bu->execute([(int)$envoi['id_user']]);
        $bur = $bu->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($bur['id_societe'])) {
            $bs = $pdo->prepare("SELECT nom, logo_url FROM societes WHERE id = ? LIMIT 1");
            $bs->execute([(int)$bur['id_societe']]);
            if ($s = $bs->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($s['nom']))      $brand['nom']  = (string)$s['nom'];
                if (!empty($s['logo_url'])) $brand['logo'] = app_url('/' . ltrim((string)$s['logo_url'], '/'));
            }
        }
        if (!empty($bur['id_agence'])) {
            $ba = $pdo->prepare("SELECT ville, email FROM agences WHERE id = ? LIMIT 1");
            $ba->execute([(int)$bur['id_agence']]);
            if ($a = $ba->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($a['ville']))  $brand['ville'] = ucwords(mb_strtolower((string)$a['ville']));
                if (!empty($a['email']))  $brand['email'] = (string)$a['email'];
            }
        }
    }
} catch (Throwable $ex) {}

require __DIR__ . '/inc/p_portefeuille_view.php';
