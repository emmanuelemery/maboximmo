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

// ── Documents partagés (docs_json) → {kind,title,label,url} (liens jeton) attachés à chaque bien ──
//   kind  = seau visuel (icône) ; title = VRAI libellé du type (référentiel ged_document_types).
$docMeta  = [];   // id → {kind,title,label,url}
$docLinks = [];   // id → [ [entity_type, entity_id], ... ]  (pour router bien vs immeuble)
$ids = array_values(array_filter(array_map('intval', json_decode((string)($share['docs_json'] ?? '[]'), true) ?: [])));
if ($ids) {
    // Référentiel des types (code → libellé lisible).
    $typeLbl = [];
    try { foreach ($pdo->query("SELECT LOWER(code) c, libelle l FROM ged_document_types")->fetchAll(PDO::FETCH_ASSOC) as $r) { $typeLbl[$r['c']] = (string)$r['l']; } } catch (Throwable) {}
    $prettyType = function(string $code) use ($typeLbl): string {
        $c = strtolower(trim($code)); if ($c === '') return '';
        if (isset($typeLbl[$c]) && $typeLbl[$c] !== '') return $typeLbl[$c];
        return ucfirst(str_replace(['_', '-'], ' ', $c));
    };
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sd = $pdo->prepare("SELECT id, name_display, name_file, document_type FROM ged_documents WHERE id IN ($in) AND status='active' ORDER BY document_type, id");
        $sd->execute($ids);
        foreach ($sd->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $code = (string)$d['document_type'];
            // Seau visuel (icône) : sur le CODE de type en priorité (fiable). Nom en repli SEULEMENT
            // si le code est vide (sinon la réf de bail dans le nom fausse tout : un Carrez → « bail »).
            $src  = strtoupper($code !== '' ? $code : ((string)$d['name_display'] . ' ' . (string)$d['name_file']));
            $kind = str_contains($src, 'DPE') ? 'dpe'
                  : ((str_contains($src, 'CARREZ') || str_contains($src, 'BOUTIN') || str_contains($src, 'SURFACE')) ? 'carrez'
                  : (str_contains($src, 'REGLEMENT') ? 'reglement'
                  : (str_contains($src, 'BAIL') ? 'bail'
                  : ((str_contains($src, 'TAXE') || str_contains($src, 'FONCIERE') || preg_match('/\bTF\b/', $src)) ? 'tf' : 'diag'))));
            $docMeta[(int)$d['id']] = [
                'kind'  => $kind,
                'title' => $prettyType($code),   // VRAI libellé (ex. « Bail signé », « DPE », « État des risques »)
                'label' => dv_ged_shortname((string)($d['name_display'] ?: ($d['name_file'] ?: $code))),
                'url'   => app_url('/api/dossier_vente_doc.php?t=' . $token . '&doc=' . (int)$d['id'] . '&mode=inline'),
            ];
        }
        // Liens d'entité (pour router chaque doc vers SON bien, ou vers l'immeuble).
        $ql = $pdo->prepare("SELECT document_id, entity_type, entity_id FROM ged_document_links WHERE document_id IN ($in)");
        $ql->execute($ids);
        foreach ($ql->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $docLinks[(int)$r['document_id']][] = [strtoupper((string)$r['entity_type']), (int)$r['entity_id']];
        }
    } catch (Throwable) {}
}

// ── Lots du dossier → $biensJs (forme attendue par inc/p_portefeuille_view.php) ──
$pickKey = function(array $a, array $keys) { foreach ($keys as $k) { if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) return $a[$k]; } return null; };
$lots = function_exists('dv_lots') ? dv_lots($pdo, $idDossier) : [];
if (!$lots && (int)($dossier['id_bien'] ?? 0) > 0) $lots = [['id_bien' => (int)$dossier['id_bien']]];

// ── Routage des documents : chaque doc sur SON bien (bail/DPE propres) ; les docs de
//    l'immeuble sur une card IMMEUBLE dédiée (évite les doublons entre biens d'un même immeuble). ──
$bienIds = [];
$immOfBien = [];   // id_bien → id_immeuble
foreach ($lots as $l) { $bid = (int)($l['id_bien'] ?? 0); if ($bid > 0) { $bienIds[$bid] = true; $immOfBien[$bid] = (int)($l['id_immeuble'] ?? 0); } }
$immIds = array_values(array_unique(array_filter($immOfBien)));

$docsByBien = [];   // id_bien → [docMeta...]
$docsByImm  = [];   // id_immeuble → [docMeta...]
$docsCommun = [];   // ni bien ni immeuble du dossier → carte « Documents du dossier »
foreach ($docMeta as $docId => $meta) {
    $links = $docLinks[$docId] ?? [];
    $toBien = 0; $toImm = 0;
    foreach ($links as [$et, $eid]) {
        if (($et === 'BIEN') && isset($bienIds[$eid])) { $toBien = $eid; break; }
        if (($et === 'IMB' || $et === 'IMMEUBLE') && in_array($eid, $immIds, true)) { $toImm = $eid; }
    }
    if ($toBien > 0)      $docsByBien[$toBien][] = $meta;
    elseif ($toImm > 0)   $docsByImm[$toImm][]   = $meta;
    else                  $docsCommun[]          = $meta;
}

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
    // Photos DU BIEN + HÉRITAGE des photos de son IMMEUBLE (le bien hérite de l'immeuble,
    // jamais l'inverse → l'immeuble n'affiche que ses propres photos, pas de doublon).
    $photos = [];
    try {
        $ph = $pdo->prepare("SELECT COALESCE(NULLIF(url_lbc,''), url_photo) AS u FROM biens_photos WHERE ((entity_type='BIEN' AND entity_id = ?) OR id_bien = ?) ORDER BY ordre ASC, id ASC");
        $ph->execute([$bid, $bid]);
        foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $u) { $u = trim((string)$u); if ($u !== '') $photos[] = app_url('/' . ltrim($u, '/')); }
        $bImm = (int)($immOfBien[$bid] ?? ($b['id_immeuble'] ?? 0));
        if ($bImm > 0) {
            $pi = $pdo->prepare("SELECT COALESCE(NULLIF(url_lbc,''), url_photo) AS u FROM biens_photos WHERE entity_type='IMB' AND entity_id=? ORDER BY ordre ASC, id ASC");
            $pi->execute([$bImm]);
            foreach ($pi->fetchAll(PDO::FETCH_COLUMN) as $u) { $u = trim((string)$u); if ($u !== '') $photos[] = app_url('/' . ltrim($u, '/')); }
        }
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
        'docs'     => $docsByBien[$bid] ?? [],   // UNIQUEMENT les docs propres à ce bien
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

// ── Card(s) IMMEUBLE : pleine largeur, en TÊTE, couleur immeuble. Documents communs
//    (règlement copro, ERP…) une seule fois + photos immeuble (biens_photos entity_type='IMB'). ──
$carteImmeuble = function(string $adr, string $loc, string $ref, string $type, string $descr, array $docs, array $photos = []) {
    return [
        'dispo'=>true, 'idb'=>0, 'is_immeuble'=>true, 'adr'=>$adr, 'loc'=>$loc, 'ref'=>$ref, 'surf'=>'—', 'pm'=>'—',
        'type'=>$type, 'meuble'=>false, 'pro'=>false, 'annee'=>'—', 'chauf'=>'—', 'dpe'=>'—',
        'descr'=>$descr, 'annonce'=>'', 'photos'=>$photos, 'docs'=>$docs,
        'loyer'=>'—', 'bail'=>null, 'loyerMax'=>'—', 'encStatut'=>'', 'cp'=>'', 'lat'=>'', 'lng'=>'',
        'pv'=>'—', 'ho'=>'—', 'nv'=>'—', 'rdt'=>'—', 'simRate'=>'3,40', 'simUsage'=>'habitation',
    ];
};
$immCards = [];
$communAssigned = false;
foreach ($immIds as $i => $immId) {
    $idoc = $docsByImm[$immId] ?? [];
    if (!$communAssigned && $docsCommun) { $idoc = array_merge($idoc, $docsCommun); $communAssigned = true; }
    // Photos de l'immeuble (galerie dédiée biens_photos entity_type='IMB').
    $iphotos = [];
    try {
        $qp = $pdo->prepare("SELECT COALESCE(NULLIF(url_lbc,''), url_photo) AS u FROM biens_photos WHERE entity_type='IMB' AND entity_id=? ORDER BY ordre ASC, id ASC");
        $qp->execute([$immId]);
        foreach ($qp->fetchAll(PDO::FETCH_COLUMN) as $u) { $u = trim((string)$u); if ($u !== '') $iphotos[] = app_url('/' . ltrim($u, '/')); }
    } catch (Throwable) {}
    if (!$idoc && !$iphotos) continue;   // rien à montrer pour cet immeuble
    $im = [];
    try { $qi = $pdo->prepare("SELECT nom_immeuble, adresse_1, code_postal, ville, type_immeuble, annee_construction,
                                      nb_niveaux, nb_lots, nb_batiments, nb_logements, nb_commerces, nb_stationnements,
                                      registre_copro_immatriculation, registre_copro_periode, copro_nb_lots
                               FROM immeubles WHERE id=? LIMIT 1"); $qi->execute([$immId]); $im = $qi->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable) {}
    $iadr = trim((string)($im['adresse_1'] ?? '')); $ivil = trim((string)($im['ville'] ?? '')); $icp = trim((string)($im['code_postal'] ?? ''));
    // Infos publiques (registre copro / caractéristiques) — la seule info d'un immeuble.
    $infos = [];
    $add = function($lbl, $val) use (&$infos) { $val = trim((string)$val); if ($val !== '' && $val !== '0') $infos[] = ['k'=>$lbl, 'v'=>$val]; };
    $add('Type', $im['type_immeuble'] ?? '');
    $add('Année de construction', $im['annee_construction'] ?? '');
    $add('Période (registre)', $im['registre_copro_periode'] ?? '');
    $add('Immatriculation copro', $im['registre_copro_immatriculation'] ?? '');
    $add('Lots de copropriété', ($im['copro_nb_lots'] ?? '') ?: ($im['nb_lots'] ?? ''));
    $add('Niveaux', $im['nb_niveaux'] ?? '');
    $add('Bâtiments', $im['nb_batiments'] ?? '');
    $add('Logements', $im['nb_logements'] ?? '');
    $add('Commerces', $im['nb_commerces'] ?? '');
    $add('Stationnements', $im['nb_stationnements'] ?? '');
    // Résumé court pour la card (3-4 infos clés).
    $rp = [];
    if (!empty($im['nb_lots']) || !empty($im['copro_nb_lots'])) $rp[] = '🏘 ' . (($im['copro_nb_lots'] ?? '') ?: $im['nb_lots']) . ' lots';
    if (!empty($im['nb_niveaux']))         $rp[] = '🏗 ' . $im['nb_niveaux'] . ' niveaux';
    if (!empty($im['annee_construction'])) $rp[] = '📅 ' . $im['annee_construction'];
    $resume = implode(' · ', $rp);
    $c = $carteImmeuble(((string)($im['nom_immeuble'] ?? '') ?: ($iadr ?: 'Immeuble')), trim($ivil . ($icp ? ' · ' . $icp : '')), 'IMMEUBLE', 'Immeuble · parties communes', $iadr, $idoc, $iphotos);
    $c['idb'] = -$immId; $c['cp'] = $icp; $c['infos'] = $infos; $c['resume'] = $resume;
    $immCards[] = $c;
}
// Docs non rattachés (ni bien ni immeuble du dossier) → card « Documents du dossier ».
if (!$communAssigned && $docsCommun) {
    $immCards[] = $carteImmeuble('Documents du dossier', '', 'DOSSIER', 'Pièces communes', '', $docsCommun);
}
// Immeuble(s) EN TÊTE, puis les biens.
$biensJs = array_merge($immCards, $biensJs);

// ── Destinataire résolu depuis l'acteur du dossier (nom + coordonnées) selon le rôle du partage. ──
$destActeur = null;
try {
    foreach (dv_acteurs($pdo, $idDossier) as $a) {
        $rc = strtolower((string)($a['role_code'] ?? ''));
        if ($rc === $role || ($role !== '' && str_contains($rc, $role))) { $destActeur = $a; break; }
    }
    // Repli : id_tiers_destinataire du partage.
    if (!$destActeur && !empty($share['id_tiers_destinataire'])) {
        $qt = $pdo->prepare("SELECT nom_affichage, nom, prenom, raison_sociale, email, telephone FROM tiers WHERE id=? LIMIT 1");
        $qt->execute([(int)$share['id_tiers_destinataire']]); $destActeur = $qt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable) {}
$destName = '';
if ($destActeur) {
    $destName = trim((string)($destActeur['nom_affichage'] ?? '') ?: ($destActeur['raison_sociale'] ?? '') ?: trim((string)($destActeur['prenom'] ?? '') . ' ' . (string)($destActeur['nom'] ?? '')));
}
// ── Variables d'en-tête attendues par le template ──
$destNom     = trim((string)($share['libelle'] ?? '')) ?: ($destName ?: $roleLbl);
$destPrenom  = $destActeur ? trim((string)($destActeur['prenom'] ?? '')) : '';
$destNomSeul = $destName ?: $destNom;
$destEmail   = $destActeur ? trim((string)($destActeur['email'] ?? '')) : '';
$destTel     = $destActeur ? trim((string)($destActeur['telephone'] ?? '')) : '';
$destInit    = mb_strtoupper(mb_substr($destName ?: $destNom, 0, 1)) ?: '👤';
$typeDest    = $role === 'commercialisateur' ? 'commercialisateur' : '';
$pxMin = $pxMax = $sfMin = $sfMax = null;
// Compteurs séparés : biens réels vs immeubles.
$nbImmeubles = 0; foreach ($biensJs as $_b) { if (!empty($_b['is_immeuble'])) $nbImmeubles++; }
$nbBiens = max(0, count($biensJs) - $nbImmeubles);
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
