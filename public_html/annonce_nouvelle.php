<?php
declare(strict_types=1);

/**
 * annonce_nouvelle.php — Page de création d'une annonce (parcours guidé)
 *
 * Adaptée de la maquette `maquette_ideale.html`.
 *
 * État actuel : rendu de la page avec données dynamiques (types de bien,
 * agence connectée). Le POST et la sauvegarde seront branchés à l'étape
 * suivante — pour l'instant le formulaire est en lecture/écriture côté
 * navigateur uniquement.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/seo_slug.php';
require_login();

// ─── Layout MaBoxImmo ───────────────────────────────────────
$appLayout          = true;                  // Pas de header/footer public
$pageTitle          = 'Passer une annonce — MaBoxImmo';
$pageDescription    = 'Création d\'une nouvelle annonce immobilière sur MaBoxImmo.';
$robots             = 'noindex, nofollow';
$includeGooglePlaces = true;                 // footer.php injectera js/places.js
$bodyClass          = 'mode-fast';           // Mode Rapide par défaut (bascule via JS)

$pdo       = db();
$userId    = (int)($_SESSION['user_id']    ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$prenom    = (string)($_SESSION['prenom']  ?? '');

// Agence de l'utilisateur connecté
$agence = null;
if ($agenceId > 0) {
    $stmt = $pdo->prepare("SELECT id, nom_agence, slug, ville, code_postal FROM agences WHERE id = ?");
    $stmt->execute([$agenceId]);
    $agence = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─── CATALOGUES dynamiques ──────────────────────────────────
$typesBien    = $pdo->query("SELECT id, code, label, icone FROM base_types_bien        WHERE actif=1 ORDER BY ordre_defaut, label")->fetchAll(PDO::FETCH_ASSOC);
$dependances  = $pdo->query("SELECT id, code, label, icone, famille FROM base_dependances_exterieurs WHERE actif=1 ORDER BY famille, ordre_defaut, label")->fetchAll(PDO::FETCH_ASSOC);
$vues         = $pdo->query("SELECT id, code, label, icone FROM base_vues              WHERE actif=1 ORDER BY ordre_defaut, label")->fetchAll(PDO::FETCH_ASSOC);
$chauffages   = $pdo->query("SELECT id, code, label, icone FROM base_types_chauffage   WHERE actif=1 ORDER BY ordre_defaut, label")->fetchAll(PDO::FETCH_ASSOC);
$energies     = $pdo->query("SELECT id, code, label, icone FROM base_energies          WHERE actif=1 ORDER BY ordre_defaut, label")->fetchAll(PDO::FETCH_ASSOC);

// ─── POST handler (save brouillon / publier / autosave AJAX) ─
$postErrors = [];
$postSuccess = '';
$draftId = (int)($_GET['id'] ?? 0);
$draft = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $action = (string)($_POST['action'] ?? 'brouillon'); // brouillon | publier | autosave

    try {
        verify_csrf('annonce_nouvelle');

        // ── Extraction champs ──
        $str  = static fn($k, $d = null) => trim((string)($_POST[$k] ?? '')) !== '' ? trim((string)$_POST[$k]) : $d;
        $int  = static fn($k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k]   : null;
        $flt  = static fn($k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;
        $bool = static fn($k) => !empty($_POST[$k]) ? 1 : 0;

        $idTypeBien       = $int('id_type_bien');
        $typeTransaction  = $str('type_transaction', 'vente');
        $designation      = $str('designation');
        $referenceBien    = $str('reference_bien');
        $accroche         = $str('accroche_commerciale');
        $description      = $str('description');

        $ville            = $str('ville');
        $codePostal       = $str('code_postal');
        $adresse1         = $str('adresse_1');
        $adresse2         = $str('adresse_2');
        $quartier         = $str('quartier');
        $pays             = $str('pays', 'France');
        $latitude         = $flt('latitude');
        $longitude        = $flt('longitude');
        $precisionGeo     = $str('precision_geoloc');
        $googlePlaceId    = $str('google_place_id');
        $adresseFormatee  = $str('adresse_formatee');
        $accesTransports  = $str('acces_transports');
        $distanceCommerces = $str('distance_commerces');

        $prix             = $flt('prix');
        $honoraires       = $flt('honoraires');
        $honorairesCharge = $str('honoraires_charge');
        $prixNetVendeur   = $flt('prix_net_vendeur');
        $charges          = $flt('charges');
        $taxeFonciere     = $flt('taxe_fonciere');
        $taxeHabitation   = $flt('taxe_habitation');
        $chargesAnnuelles = $flt('charges_annuelles');
        $depotGarantie    = $flt('depot_garantie');
        $dureeBailMois    = $int('duree_bail_mois');

        $surfaceHab       = $flt('surface_habitable');
        $surfaceTerrain   = $flt('surface_terrain');
        $surfaceCarrez    = $flt('surface_carrez');
        $nbPieces         = $int('nb_pieces');
        $nbChambres       = $int('nb_chambres');
        $nbSDB            = $int('nb_salles_bain');
        $nbWC             = $int('nb_wc');
        $etage            = $int('etage');
        $nbNiveaux        = $int('nb_niveaux');
        $anneeConstruct   = $int('annee_construction');
        $hauteurPlaf      = $flt('hauteur_sous_plafond');
        $dpeClasse        = $str('dpe_classe');
        $gesClasse        = $str('ges_classe');
        $dpeValeur        = $int('dpe_valeur');
        $gesValeur        = $int('ges_valeur');

        $videoUrl         = $str('video_url');
        $visiteUrl        = $str('visite_virtuelle_url');
        $metaTitle        = $str('meta_title');
        $metaDescription  = $str('meta_description');
        $texteIa          = $str('texte_ia');
        $pointsFortsJson  = $str('points_forts_json');

        $exclusivite      = $bool('exclusivite');
        $coupCoeur        = $bool('coup_coeur');
        $nouveaute        = $bool('nouveaute');
        $visiblePortails  = $bool('visible_portails');

        $draftId          = (int)($_POST['id_annonce_draft'] ?? $draftId);

        // ── Validation ──
        if ($idTypeBien === null || $idTypeBien <= 0)  $postErrors[] = 'Type de bien requis';
        if ($designation === null || $designation === '') $postErrors[] = 'Désignation requise';
        if ($action === 'publier') {
            if (!$ville)       $postErrors[] = 'Ville requise pour publier';
            if (!$codePostal)  $postErrors[] = 'Code postal requis pour publier';
            if ($prix === null || $prix <= 0) $postErrors[] = 'Prix requis pour publier';
            if ($surfaceHab === null || $surfaceHab <= 0) $postErrors[] = 'Surface habitable requise pour publier';
        }

        if (!$postErrors) {
            $pdo->beginTransaction();

            // Si on édite un brouillon existant, récupérer l'id_bien lié
            $idBien = 0;
            if ($draftId > 0) {
                $st = $pdo->prepare("SELECT id_bien FROM annonces WHERE id=? AND id_societe=? LIMIT 1");
                $st->execute([$draftId, $societeId]);
                $idBien = (int)$st->fetchColumn();
            }

            // ── INSERT / UPDATE BIEN ──
            $bienData = [
                'id_type_bien'       => $idTypeBien,
                'id_agence'          => $agenceId ?: null,
                'id_societe'         => $societeId ?: null,
                'reference_bien'     => $referenceBien,
                'adresse_1'          => $adresse1,
                'adresse_2'          => $adresse2,
                'code_postal'        => $codePostal,
                'ville'              => $ville,
                'latitude'           => $latitude,
                'longitude'          => $longitude,
                'precision_geoloc'   => $precisionGeo,
                'surface_habitable'  => $surfaceHab,
                'surface_terrain'    => $surfaceTerrain,
                'surface_carrez'     => $surfaceCarrez,
                'nb_pieces'          => $nbPieces,
                'nb_chambres'        => $nbChambres,
                'nb_salles_bain'     => $nbSDB,
                'nb_wc'              => $nbWC,
                'etage'              => $etage,
                'nb_niveaux'         => $nbNiveaux,
                'annee_construction' => $anneeConstruct,
                'hauteur_sous_plafond' => $hauteurPlaf,
                'dpe_classe'         => $dpeClasse,
                'ges_classe'         => $gesClasse,
                'dpe_valeur'         => $dpeValeur,
                'ges_valeur'         => $gesValeur,
                'designation'        => $designation,
                'commentaire'        => $description,
                'balcon'             => $bool('balcon'),
                'terrasse'           => $bool('terrasse'),
                'jardin'             => $bool('jardin'),
                'garage'             => $bool('garage'),
                'cave'               => $bool('cave'),
                'piscine'            => $bool('piscine'),
                'ascenseur'          => $bool('ascenseur'),
                'climatisation'      => $bool('climatisation'),
                'fibre'              => $bool('fibre'),
                'double_vitrage'     => $bool('double_vitrage'),
                'volets_roulants'    => $bool('volets_roulants'),
                'cheminee'           => $bool('cheminee'),
                'alarme'             => $bool('alarme'),
                'interphone'         => $bool('interphone'),
                'digicode'           => $bool('digicode'),
                'cuisine_equipee'    => $bool('cuisine_equipee'),
            ];

            if ($idBien > 0) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($bienData)));
                $bienData['id'] = $idBien;
                $pdo->prepare("UPDATE biens SET $sets, date_modification = NOW() WHERE id = :id")->execute($bienData);
            } else {
                $cols = '`' . implode('`,`', array_keys($bienData)) . '`';
                $phs  = ':' . implode(', :', array_keys($bienData));
                $pdo->prepare("INSERT INTO biens ($cols, statut_bien, date_creation) VALUES ($phs, 'actif', NOW())")->execute($bienData);
                $idBien = (int)$pdo->lastInsertId();
            }

            // ── INSERT / UPDATE ANNONCE ──
            $typeBienLabel = '';
            foreach ($typesBien as $t) if ((int)$t['id'] === $idTypeBien) { $typeBienLabel = $t['label']; break; }
            $etatPub = $action === 'publier' ? 'en_ligne' : 'brouillon';
            $statut  = $etatPub;

            $annonceData = [
                'id_bien'          => $idBien,
                'id_agence'        => $agenceId ?: null,
                'id_societe'       => $societeId ?: null,
                'id_user'          => $userId ?: null,
                'type_transaction' => $typeTransaction,
                'titre'            => $designation,
                'description'      => $description,
                'accroche_commerciale' => $accroche,
                'prix'             => $prix,
                'honoraires'       => $honoraires,
                'honoraires_charge' => $honorairesCharge,
                'prix_net_vendeur' => $prixNetVendeur,
                'charges'          => $charges,
                'charges_annuelles' => $chargesAnnuelles,
                'taxe_fonciere'    => $taxeFonciere,
                'taxe_habitation'  => $taxeHabitation,
                'loyer'            => $typeTransaction === 'location' ? $prix : null,
                'depot_garantie'   => $depotGarantie,
                'duree_bail_mois'  => $dureeBailMois,
                'dpe_classe'       => $dpeClasse,
                'ges_classe'       => $gesClasse,
                'video_url'        => $videoUrl,
                'visite_virtuelle_url' => $visiteUrl,
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDescription,
                'texte_ia'         => $texteIa,
                'points_forts'     => $pointsFortsJson,
                'exclusivite'      => $exclusivite,
                'coup_coeur'       => $coupCoeur,
                'nouveaute'        => $nouveaute,
                'visible_portails' => $visiblePortails,
                'statut'           => $statut,
                'etat_publication' => $etatPub,
                'indexable'        => $etatPub === 'en_ligne' ? 1 : 0,
                'date_mise_en_ligne' => $etatPub === 'en_ligne' ? date('Y-m-d H:i:s') : null,
            ];

            if ($draftId > 0) {
                $annonceData['id'] = $draftId;
                $sets = implode(', ', array_map(fn($k) => $k === 'id' ? '' : "`$k` = :$k", array_keys($annonceData)));
                $sets = trim(str_replace(', ,', ',', $sets), ', ');
                $pdo->prepare("UPDATE annonces SET $sets, date_modification = NOW() WHERE id = :id")->execute($annonceData);
                $idAnnonce = $draftId;
            } else {
                $cols = '`' . implode('`,`', array_keys($annonceData)) . '`';
                $phs  = ':' . implode(', :', array_keys($annonceData));
                $pdo->prepare("INSERT INTO annonces ($cols, date_creation) VALUES ($phs, NOW())")->execute($annonceData);
                $idAnnonce = (int)$pdo->lastInsertId();
            }

            // ── Génération slug SEO (maintenant qu'on a l'ID) ──
            $slug = SeoSlug::annonceSlug(
                ['type_bien' => $typeBienLabel, 'surface_habitable' => $surfaceHab, 'ville' => $ville],
                ['id' => $idAnnonce],
                ['slug' => $agence['slug'] ?? '', 'nom_agence' => $agence['nom_agence'] ?? '']
            );
            $pdo->prepare("UPDATE annonces SET slug = ? WHERE id = ?")->execute([$slug, $idAnnonce]);

            $pdo->commit();

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'id_annonce' => $idAnnonce, 'id_bien' => $idBien, 'slug' => $slug, 'action' => $action], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Redirection propre : on reste sur la page avec l'id en GET
            header('Location: annonce_nouvelle.php?id=' . $idAnnonce . '&saved=' . $action);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $postErrors[] = 'Erreur : ' . $e->getMessage();
    }

    if ($isAjax ?? false) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($postErrors ? 422 : 200);
        echo json_encode(['ok' => empty($postErrors), 'errors' => $postErrors], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Chargement d'un brouillon existant ?id= ────────────────
if ($draftId > 0) {
    $st = $pdo->prepare("
        SELECT a.*, b.id_type_bien, b.reference_bien, b.adresse_1, b.adresse_2,
               b.code_postal, b.ville, b.latitude, b.longitude, b.precision_geoloc,
               b.surface_habitable, b.surface_terrain, b.surface_carrez,
               b.nb_pieces, b.nb_chambres, b.nb_salles_bain, b.nb_wc,
               b.etage, b.nb_niveaux, b.annee_construction, b.hauteur_sous_plafond,
               b.balcon, b.terrasse, b.jardin, b.garage, b.cave, b.piscine,
               b.ascenseur, b.climatisation, b.fibre, b.double_vitrage,
               b.volets_roulants, b.cheminee, b.alarme, b.interphone, b.digicode,
               b.cuisine_equipee, b.designation AS bien_designation
        FROM annonces a
        LEFT JOIN biens b ON b.id = a.id_bien
        WHERE a.id = ? AND a.id_societe = ?
        LIMIT 1
    ");
    $st->execute([$draftId, $societeId]);
    $draft = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Helper pour pré-remplissage
$d = static function(string $key, $default = '') use ($draft) {
    if (!$draft) return $default;
    return $draft[$key] ?? $default;
};
$dCheck = static function(string $key) use ($draft) {
    return $draft && !empty($draft[$key]) ? 'on' : '';
};

// Icônes emoji par code type de bien (fallback si pas d'icone en base)
$EMOJI_TYPE = [
    'appartement' => '🏢',
    'maison'      => '🏠',
    'villa'       => '🏡',
    'terrain'     => '🌳',
    'local_commercial' => '🏬',
    'bureau'      => '🏢',
    'parking'     => '🚗',
    'garage'      => '🚗',
    'immeuble'    => '🏦',
    'loft'        => '🛋️',
    'chateau'     => '🏰',
    'chambre'     => '🛏️',
];

$pageTitleShort = 'Passer une annonce';
require_once __DIR__ . '/inc/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ══ PALETTE MABOXIMMO (basée sur variables.css) ═══════════ */
:root{
  /* Thème clair par défaut — couleurs MaBoxImmo officielles */
  --bg:#faf6f3;                     /* color-white-alt */
  --surface:#ffffff;                /* color-white */
  --card:#ffffff;
  --card2:#f5f1ee;                  /* color-white-light */
  --card3:#faf6f0;                  /* color-beige-light */
  --border:#e5e5e5;                 /* color-gray-lightest */
  --border-strong:#d9d9d9;          /* color-gray-lighter */
  --ink:#343d4b;                    /* color-primary (texte) */
  --muted:#757575;                  /* color-gray-medium */
  --faint:#e5e5e5;
  --accent:#2d5f6b;                 /* color-secondary */
  --accent2:#3d7f8b;                /* color-secondary-light */
  --accent3:#7a9e7f;                /* color-primary-light (sage) */
  --green:#10b981;                  /* color-success */
  --amber:#f59e0b;                  /* color-warning */
  --red:#ef4444;                    /* color-danger */
  --purple:#7640d4;
  --r-sm:12px; --r-md:16px; --r-lg:20px;
  --shadow-sm:0 1px 2px rgba(0,0,0,.05);
  --shadow-md:0 4px 12px rgba(0,0,0,.06);
  --shadow-lg:0 10px 30px rgba(45,95,107,.08);
  --shadow:var(--shadow-md);
}
[data-theme="dark"]{
  --bg:#0f1923; --surface:#16222e; --card:#1c2d3e; --card2:#213346; --card3:#1a2a3a;
  --border:rgba(255,255,255,.09); --border-strong:rgba(255,255,255,.15);
  --ink:#e8f1ff; --muted:#7a91a8; --faint:#2d4055;
  --accent:#3d7f8b; --accent2:#4878a6; --accent3:#7a9e7f;
  --green:#2ec77a; --amber:#f5a623; --red:#ff5c5c; --purple:#b07dff;
  --shadow-md:0 4px 12px rgba(0,0,0,.35);
  --shadow-lg:0 10px 30px rgba(0,0,0,.45);
  --shadow:var(--shadow-md);
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100vh;overflow:hidden;}
body{
  font-family:"Manrope",Inter,system-ui,sans-serif;
  background:
    radial-gradient(1100px 500px at 85% -10%, rgba(61,127,139,0.06), transparent 60%),
    radial-gradient(900px 400px at 5% 10%, rgba(122,158,127,0.05), transparent 55%),
    var(--bg);
  color:var(--ink);font-size:15px;line-height:1.55;
}
button,input,select,textarea{font:inherit;color:inherit;}
button{cursor:pointer;border:none;background:none;}
a{color:inherit;text-decoration:none;}

/* ══ SIDEBAR ══════════════════════════════════════════════ */
.app-wrap{display:flex;height:100vh;overflow:hidden;}
.sidebar{
  width:58px;flex-shrink:0;background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;align-items:center;
  padding:12px 0;gap:4px;
  position:sticky;top:0;height:100vh;overflow:hidden;
  transition:width .22s ease;z-index:200;
}
.sidebar:hover{width:210px;}
.sidebar-logo{
  width:38px;height:38px;border-radius:12px;
  background:linear-gradient(135deg,var(--accent),var(--accent3));
  display:flex;align-items:center;justify-content:center;
  font-size:15px;flex-shrink:0;margin-bottom:8px;color:#fff;font-weight:800;letter-spacing:.5px;
  box-shadow:0 4px 12px rgba(45,95,107,.25);
}
.sb-item{
  display:flex;align-items:center;gap:11px;
  width:100%;padding:10px 11px;border-radius:8px;
  font-size:14px;font-weight:600;color:var(--muted);
  cursor:pointer;transition:.15s ease;white-space:nowrap;overflow:hidden;
}
.sb-item:hover{background:rgba(45,95,107,.06);color:var(--ink);}
.sb-item.active{background:rgba(45,95,107,.10);color:var(--accent);}
.sb-icon{font-size:19px;flex-shrink:0;width:36px;text-align:center;}
.sb-label{opacity:0;transition:opacity .18s .05s;font-size:13px;}
.sidebar:hover .sb-label{opacity:1;}
.sb-sep{width:32px;height:1px;background:var(--border);margin:6px 0;flex-shrink:0;transition:width .22s;}
.sidebar:hover .sb-sep{width:186px;}

/* ══ MAIN ═════════════════════════════════════════════════ */
.main{flex:1;display:flex;flex-direction:column;min-width:0;height:100vh;overflow:hidden;}

/* ── TOPBAR (56px, fixe haut) ─────────────────────────── */
.topbar{
  flex-shrink:0;
  display:flex;align-items:center;gap:12px;
  height:56px;padding:0 20px 0 18px;background:var(--surface);
  border-bottom:1px solid var(--border);
  box-shadow:0 2px 8px rgba(0,0,0,.03);
  z-index:100;
}
.topbar .tb-nav{display:flex;gap:6px;flex-shrink:0;}
.topbar .tb-breadcrumb{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.topbar .tb-breadcrumb strong{color:var(--ink);font-weight:700;}
.topbar .tb-sep{color:var(--border-strong);}

/* ── PAGE-HEADER (96px, fixe sous topbar) ─────────────── */
.page-header{
  flex-shrink:0;
  display:flex;align-items:center;justify-content:space-between;
  gap:18px;padding:16px 24px;
  background:var(--card);
  border-bottom:1px solid var(--border);
  box-shadow:0 4px 12px rgba(45,95,107,.04);
  z-index:90;
}
.page-header .ph-left{flex:1;min-width:0;}
.page-header .ph-title{font-size:22px;font-weight:800;color:var(--ink);letter-spacing:-.4px;margin-bottom:4px;}
.page-header .ph-sub{font-size:13px;color:var(--muted);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.page-header .ph-right{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap;}
.page-header .ph-progress{display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:10px;background:var(--card2);border:1px solid var(--border);}
.page-header .ph-progress .prog-bar{width:120px;}
.back-btn{display:flex;align-items:center;gap:5px;padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);font-size:13px;font-weight:600;transition:.15s;}
.back-btn:hover{border-color:var(--accent);color:var(--accent);}
.page-title{font-size:17px;font-weight:800;flex:1;letter-spacing:-.3px;}
.agence-pill{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:99px;border:1px solid var(--border);background:var(--card);font-size:12px;color:var(--muted);}
.agence-pill strong{color:var(--ink);}

.prog-wrap{display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);}
.prog-label{font-size:13px;color:var(--muted);white-space:nowrap;}
.prog-bar{width:100px;height:6px;background:var(--faint);border-radius:99px;overflow:hidden;}
.prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .4s;}
.prog-pct{font-size:14px;font-weight:800;color:var(--accent2);width:30px;}

.mode-toggle{display:flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;}
.mode-btn{padding:6px 13px;font-size:13px;font-weight:600;color:var(--muted);transition:.15s;}
.mode-btn.on{background:var(--accent);color:#fff;}

.btn-import{display:flex;align-items:center;gap:6px;padding:8px 15px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:700;font-size:13px;transition:.18s;box-shadow:0 4px 14px rgba(45,95,107,.25);}
.btn-import:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(45,95,107,.35);}
.btn-save{padding:8px 18px;border-radius:9px;background:var(--green);color:#fff;font-weight:700;font-size:13px;}
.btn-theme{padding:7px 10px;border-radius:8px;border:1px solid var(--border);background:var(--card);font-size:16px;}

.tabs-wrap{flex-shrink:0;display:flex;gap:2px;padding:0 18px;background:var(--surface);border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none;}
.tabs-wrap::-webkit-scrollbar{display:none;}
.tab{padding:11px 16px;font-size:14px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:6px;transition:.15s;}
.tab:hover{color:var(--ink);}
.tab.active{color:var(--accent);border-bottom-color:var(--accent);}
.dot{width:7px;height:7px;border-radius:50%;background:var(--faint);}
.dot.ok{background:var(--green);}
.dot.warn{background:var(--amber);}
.dot.act{background:var(--accent);}

#formAnnonce{flex:1;display:flex;flex-direction:column;min-height:0;overflow:hidden;}
.layout{flex:1;display:grid;grid-template-columns:1fr 360px;align-items:stretch;min-height:0;overflow:hidden;}
.left-col{padding:18px 22px;border-right:1px solid var(--border);overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border-strong) transparent;}
.left-col::-webkit-scrollbar{width:6px;}
.left-col::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:3px;}
.right-col{padding:16px 18px;overflow-y:auto;display:flex;flex-direction:column;gap:14px;background:var(--card3);scrollbar-width:thin;scrollbar-color:var(--border-strong) transparent;}
.right-col::-webkit-scrollbar{width:6px;}
.right-col::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:3px;}

.section{background:var(--card);border:1px solid var(--border);border-radius:var(--r-md);padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm);}
.section:focus-within{border-color:var(--accent2);box-shadow:var(--shadow-md);}
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;}
.sec-title{font-size:15px;font-weight:700;display:flex;align-items:center;gap:7px;}
.badge{font-size:11px;padding:3px 8px;border-radius:99px;font-weight:700;}
.b-ok{background:rgba(46,199,122,.12);color:var(--green);border:1px solid rgba(46,199,122,.2);}
.b-warn{background:rgba(245,166,35,.12);color:var(--amber);border:1px solid rgba(245,166,35,.2);}

.type-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;}
.tc{
  position:relative;border:2px solid var(--border);background:var(--card2);
  border-radius:var(--r-md);padding:14px 8px;cursor:pointer;transition:.18s ease;
  display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;min-height:86px;justify-content:center;
}
.tc:hover{border-color:var(--accent2);transform:translateY(-2px);box-shadow:var(--shadow-md);}
.tc.sel{border-color:var(--accent);background:rgba(45,95,107,.06);box-shadow:0 0 0 3px rgba(45,95,107,.10);}
.tc-icon{font-size:24px;line-height:1;}
.tc-label{font-size:13px;font-weight:700;}

.offre-row{display:flex;gap:8px;}
.oc{flex:1;border:2px solid var(--border);background:var(--card2);border-radius:var(--r-md);padding:12px 16px;cursor:pointer;display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;transition:.18s;}
.oc:hover{border-color:var(--accent2);box-shadow:var(--shadow-sm);}
.oc.sel{border-color:var(--accent);background:rgba(45,95,107,.06);box-shadow:0 0 0 3px rgba(45,95,107,.10);}
.oc-icon{font-size:20px;}
.oc-sub{font-size:11px;color:var(--muted);font-weight:400;}

.fg{display:grid;gap:10px;}
.g2{grid-template-columns:repeat(2,1fr);}
.g3{grid-template-columns:repeat(3,1fr);}
.g4{grid-template-columns:repeat(4,1fr);}
.s2{grid-column:span 2;}
.s4{grid-column:span 4;}

.f{display:flex;flex-direction:column;gap:4px;}
.f label{font-size:13px;font-weight:600;color:var(--muted);display:flex;align-items:center;gap:4px;}
.f label .req{color:var(--red);}
.f input,.f select,.f textarea{
  padding:10px 13px;border-radius:10px;border:1px solid var(--border);
  background:#fff;color:var(--ink);font-size:14px;
  transition:border-color .15s,box-shadow .15s;outline:none;width:100%;
}
[data-theme="dark"] .f input,[data-theme="dark"] .f select,[data-theme="dark"] .f textarea{background:var(--card2);}
.f input:focus,.f select:focus,.f textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(45,95,107,.12);}
.f textarea{resize:vertical;min-height:90px;}
.f .hint{font-size:11px;color:var(--muted);}
.f .vmsg{font-size:11px;margin-top:2px;}
.f.ok input{border-color:rgba(46,199,122,.35);}
.f.ok .vmsg{color:var(--green);}

.iu{display:flex;}
.iu input{border-radius:8px 0 0 8px;flex:1;}
.unit{padding:0 11px;background:var(--card);border:1px solid var(--border);border-left:none;border-radius:0 8px 8px 0;display:flex;align-items:center;font-size:13px;color:var(--muted);font-weight:600;white-space:nowrap;}

.chip-group{display:flex;flex-wrap:wrap;gap:7px;}
.chip{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:99px;border:1px solid var(--border);background:var(--card2);cursor:pointer;font-size:13px;font-weight:600;transition:.18s;user-select:none;}
.chip:hover{border-color:var(--accent2);color:var(--accent);}
.chip.on{background:rgba(45,95,107,.08);border-color:var(--accent);color:var(--accent);box-shadow:0 0 0 2px rgba(45,95,107,.08);}
.sub-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin:12px 0 7px;}

.photo-drop{border:2px dashed var(--border);border-radius:12px;padding:16px;text-align:center;cursor:pointer;transition:.15s;background:var(--card2);}
.photo-drop:hover{border-color:var(--accent);}
.photo-thumbs{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px;}
.pthumb{width:68px;height:52px;border-radius:8px;border:1px solid var(--border);background:var(--card);display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;overflow:hidden;position:relative;}
.pthumb.add{border-style:dashed;color:var(--faint);font-size:22px;}

.seo-box{background:var(--card2);border-radius:10px;padding:12px 14px;border:1px solid var(--border);margin-top:8px;}
.seo-url{font-size:12px;color:var(--green);margin-bottom:2px;}
.seo-title{font-size:15px;color:var(--accent);font-weight:600;margin-bottom:3px;line-height:1.3;}
.seo-desc{font-size:13px;color:var(--muted);line-height:1.5;}

/* Aperçu droite */
.pcard{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
.phero{min-height:150px;background:linear-gradient(135deg,var(--accent),var(--accent2) 55%,var(--accent3));display:flex;align-items:flex-end;padding:14px;position:relative;}
.phero-badge{position:absolute;top:10px;right:10px;padding:5px 12px;border-radius:99px;font-size:11px;font-weight:700;background:rgba(255,255,255,.95);color:var(--accent);}
.phero-label{background:rgba(0,0,0,.5);backdrop-filter:blur(8px);border-radius:8px;padding:8px 12px;}
.phero-type{font-size:11px;color:#6a6660;font-weight:600;letter-spacing:.5px;text-transform:uppercase;}
.phero-title{font-size:16px;font-weight:800;color:#fff;line-height:1.2;margin-top:2px;}
.pbody{padding:14px;}
.pkpis{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;}
.kbox{background:var(--card2);border-radius:10px;padding:10px 12px;border:1px solid var(--border);}
.kval{font-size:18px;font-weight:800;color:var(--ink);line-height:1;}
.kval span{font-size:12px;font-weight:500;color:var(--muted);}
.klbl{font-size:11px;color:var(--muted);margin-top:2px;}
.pdesc{font-size:13px;color:var(--muted);line-height:1.55;background:var(--card2);border-radius:10px;padding:12px 14px;border:1px solid var(--border);white-space:pre-wrap;word-wrap:break-word;}
.pdesc.ia{background:linear-gradient(180deg,rgba(118,64,212,.06),var(--card2));border-color:rgba(118,64,212,.25);color:var(--ink);}
.pdesc-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin:10px 0 5px;display:flex;align-items:center;gap:6px;}
.pdesc-label.ia{color:var(--purple);}
.ia-tag{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:rgba(118,64,212,.12);color:var(--purple);border:1px solid rgba(118,64,212,.25);}
.points-forts{list-style:none;padding:0;margin:6px 0 10px;}
.points-forts li{font-size:12px;padding:4px 0 4px 18px;position:relative;color:var(--ink);}
.points-forts li::before{content:"✓";position:absolute;left:0;top:3px;color:var(--green);font-weight:700;}
.ia-btn-gen{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;background:linear-gradient(135deg,#7640d4,#9d6fff);color:#fff;font-weight:700;font-size:13px;transition:.18s;box-shadow:0 4px 14px rgba(118,64,212,.25);border:none;cursor:pointer;}
.ia-btn-gen:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(118,64,212,.35);}
.ia-btn-gen:disabled{opacity:.6;cursor:wait;transform:none;}
.ia-status{font-size:12px;color:var(--muted);margin-top:6px;}
.ia-status.ok{color:var(--green);}
.ia-status.err{color:var(--red);}

.scard{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 16px;}
.scard-title{font-size:14px;font-weight:700;margin-bottom:11px;display:flex;align-items:center;}
.si{display:flex;align-items:center;gap:8px;margin-bottom:7px;}
.si-icon{font-size:13px;width:18px;text-align:center;}
.si-name{flex:1;font-size:13px;color:var(--muted);}
.si-bar{width:72px;height:5px;background:var(--faint);border-radius:99px;overflow:hidden;}
.si-fill{height:100%;border-radius:99px;}
.si-fill.ok{background:var(--green);}
.si-fill.warn{background:var(--amber);}
.si-fill.no{background:var(--red);}
.divider{height:1px;background:var(--border);margin:12px 0;}

.bottom-bar{
  flex-shrink:0;z-index:50;
  padding:12px 22px;background:var(--surface);border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
  box-shadow:0 -4px 12px rgba(0,0,0,.03);
}
.bbtn{padding:9px 18px;border-radius:9px;font-size:14px;font-weight:700;transition:.15s;}
.bbtn.ghost{border:1px solid var(--border);background:var(--card);color:var(--ink);}
.bbtn.ghost:hover{border-color:var(--accent);color:var(--accent);}
.bbtn.primary{background:var(--accent);color:#fff;box-shadow:0 4px 14px rgba(45,95,107,.25);}
.bbtn.primary:hover{background:var(--accent2);box-shadow:0 6px 18px rgba(61,127,139,.3);}
.bbtn.success{background:var(--green);color:#fff;}
.bbtn.success:hover{filter:brightness(1.08);}
.pb-space{height:62px;}

/* ══ PANELS D'ONGLETS ══════════════════════════════════════ */
.tab-panel{display:none;animation:fadeIn .2s ease;}
.tab-panel.active{display:block;}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px);}to{opacity:1;transform:translateY(0);}}

/* ══ MODE RAPIDE vs PRO ════════════════════════════════════
   En mode "fast" on masque tout ce qui est marqué .pro-only  */
body.mode-fast .pro-only{display:none !important;}
.pro-flag{font-size:10px;padding:2px 7px;border-radius:99px;background:rgba(176,125,255,.12);color:var(--purple);border:1px solid rgba(176,125,255,.25);font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-left:6px;}

/* Navigation entre onglets */
.panel-nav{display:flex;justify-content:space-between;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);}
.nav-btn{display:flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;border:1px solid var(--border);background:var(--card);color:var(--ink);font-size:13px;font-weight:700;transition:.15s;}
.nav-btn:hover{border-color:var(--accent);color:var(--accent);}
.nav-btn.prev{}
.nav-btn.next{background:var(--accent);color:#fff;border-color:var(--accent);}
.nav-btn.next:hover{background:var(--accent2);color:#fff;}
.nav-btn[disabled]{opacity:.3;cursor:not-allowed;}

/* ══ GOOGLE PLACES DROPDOWN ════════════════════════════════
   Nécessaire pour que les résultats d'autocomplete Google
   (injectés en dehors du formulaire, sur document.body)
   soient visibles et stylisés correctement. */
.places-dropdown{
  position:absolute;z-index:9999;background:#fff;
  border:1px solid var(--border);border-radius:10px;
  box-shadow:var(--shadow-lg);
  max-height:280px;overflow:auto;
  font-family:"Manrope",Inter,system-ui,sans-serif;
}
.places-item{
  padding:10px 13px;cursor:pointer;font-size:14px;color:var(--ink);
  border-bottom:1px solid #eef2f7;
}
.places-item:last-child{border-bottom:none;}
.places-item:hover,.places-item.active{
  background:rgba(45,95,107,.08);color:var(--accent);
}

@media(max-width:980px){
  .layout{grid-template-columns:1fr;}
  .right-col{position:static;height:auto;border-top:1px solid var(--border);}
  .left-col{border-right:none;}
}
</style>
<div class="app-wrap">

<!-- ══════════════ SIDEBAR ══════════════ -->
<nav class="sidebar">
  <a href="default.php" class="sidebar-logo" title="Accueil">MI</a>
  <div class="sb-item active"><span class="sb-icon">➕</span><span class="sb-label">Créer une annonce</span></div>
  <a href="bien_liste.php" class="sb-item"><span class="sb-icon">🏘️</span><span class="sb-label">Mes biens</span></a>
  <div class="sb-item"><span class="sb-icon">📋</span><span class="sb-label">Mandats</span></div>
  <div class="sb-item"><span class="sb-icon">👥</span><span class="sb-label">Propriétaires</span></div>
  <div class="sb-sep"></div>
  <div class="sb-item"><span class="sb-icon">📊</span><span class="sb-label">Statistiques</span></div>
  <div class="sb-item"><span class="sb-icon">📄</span><span class="sb-label">Imports PDF</span></div>
  <div class="sb-sep"></div>
  <div class="sb-item"><span class="sb-icon">⚙️</span><span class="sb-label">Paramètres</span></div>
  <a href="logout.php" class="sb-item"><span class="sb-icon">🚪</span><span class="sb-label">Déconnexion</span></a>
</nav>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main">

<!-- ══ TOPBAR (56px fixe) ══ -->
<header class="topbar">
  <div class="tb-nav">
    <button type="button" class="back-btn" onclick="history.back()" title="Retour">←</button>
    <button type="button" class="back-btn" onclick="history.forward()" title="Avancer">→</button>
  </div>
  <div class="tb-breadcrumb">
    <a href="default.php" style="color:var(--muted);">Accueil</a>
    <span class="tb-sep">›</span>
    <span>Annonces</span>
    <span class="tb-sep">›</span>
    <strong>Passer une annonce</strong>
  </div>
  <div class="mode-toggle">
    <button type="button" class="mode-btn on" onclick="setMode('fast',this)">⚡ Rapide</button>
    <button type="button" class="mode-btn" onclick="setMode('pro',this)">🛠 Pro</button>
  </div>
  <button type="button" class="btn-theme" onclick="toggleTheme()" title="Thème clair/sombre">☀️</button>
</header>

<!-- ══ PAGE-HEADER (96px fixe) ══ -->
<section class="page-header">
  <div class="ph-left">
    <div class="ph-title">🏠 <?= h($pageTitleShort) ?></div>
    <div class="ph-sub">
      <?php if ($agence): ?>
        <span>📍 Agence : <strong style="color:var(--ink);"><?= h($agence['nom_agence']) ?></strong></span>
      <?php endif; ?>
      <?php if ($draftId > 0): ?>
        <span>•</span>
        <span>Brouillon #<?= (int)$draftId ?></span>
      <?php else: ?>
        <span>•</span>
        <span>Nouvelle annonce</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="ph-right">
    <div class="ph-progress">
      <span class="prog-label">Complétude</span>
      <div class="prog-bar"><div class="prog-fill" id="progFill" style="width:15%"></div></div>
      <span class="prog-pct" id="progPct">15%</span>
    </div>
  </div>
</section>

<!-- ONGLETS (navigation interne dans la page) -->
<nav class="tabs-wrap">
  <button type="button" class="tab active" data-tab="identification" onclick="goTab(this)"><span class="dot act" id="dot-identification"></span> 1. Identification</button>
  <button type="button" class="tab" data-tab="localisation" onclick="goTab(this)"><span class="dot" id="dot-localisation"></span> 2. Localisation</button>
  <button type="button" class="tab" data-tab="financier" onclick="goTab(this)"><span class="dot" id="dot-financier"></span> 3. Financier</button>
  <button type="button" class="tab" data-tab="caracteristiques" onclick="goTab(this)"><span class="dot" id="dot-caracteristiques"></span> 4. Caractéristiques</button>
  <button type="button" class="tab" data-tab="photos" onclick="goTab(this)"><span class="dot" id="dot-photos"></span> 5. Photos</button>
  <button type="button" class="tab" data-tab="seo" onclick="goTab(this)"><span class="dot" id="dot-seo"></span> 6. Annonce &amp; SEO</button>
</nav>

<?php if (!empty($_GET['saved'])): ?>
  <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:var(--green);padding:10px 18px;font-size:14px;font-weight:600;text-align:center;">
    ✓ Annonce <?= $_GET['saved'] === 'publier' ? 'publiée' : 'enregistrée en brouillon' ?> avec succès
    <?php if ($draftId > 0): ?>· ID #<?= (int)$draftId ?><?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($postErrors): ?>
  <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:10px 18px;font-size:14px;">
    <strong>⚠ Erreurs :</strong> <?= h(implode(' · ', $postErrors)) ?>
  </div>
<?php endif; ?>

<!-- FORMULAIRE -->
<form method="post" action="annonce_nouvelle.php<?= $draftId > 0 ? '?id='.(int)$draftId : '' ?>" id="formAnnonce" novalidate>
<?= csrf_field('annonce_nouvelle') ?>
<input type="hidden" name="id_annonce_draft" value="<?= (int)$draftId ?>">
<input type="hidden" name="id_agence"   value="<?= (int)$agenceId ?>">
<input type="hidden" name="id_societe"  value="<?= (int)$societeId ?>">
<input type="hidden" name="id_type_bien" id="fType" value="<?= h($d('id_type_bien','')) ?>">
<input type="hidden" name="type_transaction" id="fTrans" value="<?= h($d('type_transaction','vente')) ?>">
<input type="hidden" name="latitude"          id="fLat"      value="<?= h($d('latitude','')) ?>">
<input type="hidden" name="longitude"         id="fLng"      value="<?= h($d('longitude','')) ?>">
<input type="hidden" name="google_place_id"   id="fPlaceId"  value="">
<input type="hidden" name="adresse_formatee"  id="fAddrFmt"  value="">

<div class="layout">

<!-- ════ GAUCHE — FORMULAIRE (6 panels) ════ -->
<div class="left-col">

  <!-- ═══════════════════════════════════════════════════════
       PANEL 1 — IDENTIFICATION
       ═══════════════════════════════════════════════════════ -->
  <div class="tab-panel active" data-panel="identification">

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">🏗️ Type de bien</div>
        <span class="badge b-warn" id="typeBadge">À sélectionner</span>
      </div>
      <div class="type-grid">
        <?php foreach ($typesBien as $t):
          $emoji = $EMOJI_TYPE[$t['code']] ?? '🏠';
        ?>
          <div class="tc" data-id="<?= (int)$t['id'] ?>" data-code="<?= h($t['code']) ?>" data-label="<?= h($t['label']) ?>" onclick="selType(this)">
            <div class="tc-icon"><?= $emoji ?></div>
            <div class="tc-label"><?= h($t['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">💼 Type d'offre</div>
        <span class="badge b-ok" id="offreBadge">✓ Vente</span>
      </div>
      <div class="offre-row">
        <div class="oc sel" data-code="vente" onclick="selOffre(this)">
          <span class="oc-icon">🤝</span>
          <div><div>Vente</div><div class="oc-sub">Cession définitive</div></div>
        </div>
        <div class="oc" data-code="location" onclick="selOffre(this)">
          <span class="oc-icon">🔑</span>
          <div><div>Location</div><div class="oc-sub">Bail résidentiel / comm.</div></div>
        </div>
        <div class="oc pro-only" data-code="viager" onclick="selOffre(this)">
          <span class="oc-icon">🔁</span>
          <div><div>Viager <span class="pro-flag">Pro</span></div><div class="oc-sub">Occupé / libre</div></div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">📝 Désignation commerciale</div>
        <span class="badge b-warn">Obligatoire</span>
      </div>
      <div class="f">
        <label>Titre court et accrocheur <span class="req">*</span>
          <span style="font-size:11px;font-weight:400;color:var(--muted);">50–80 car.</span>
        </label>
        <input type="text" name="designation" id="desig" value="<?= h($d('titre','')) ?>" placeholder="Ex: Appartement T3 lumineux avec balcon, vue dégagée — centre-ville" oninput="sync()">
        <div class="vmsg" id="desig-msg">Minimum 50 caractères pour un bon SEO</div>
      </div>
      <div class="fg g2" style="margin-top:10px;">
        <div class="f pro-only">
          <label>🏷️ Référence interne <span class="pro-flag">Pro</span></label>
          <input type="text" name="reference_bien" placeholder="Auto si vide">
        </div>
        <div class="f pro-only">
          <label>🎯 Accroche commerciale <span class="pro-flag">Pro</span></label>
          <input type="text" name="accroche_commerciale" placeholder="Une phrase percutante">
        </div>
      </div>
    </div>

    <div class="panel-nav">
      <span></span>
      <button type="button" class="nav-btn next" onclick="goTabByName('localisation')">Localisation →</button>
    </div>
  </div><!-- /panel identification -->


  <!-- ═══════════════════════════════════════════════════════
       PANEL 2 — LOCALISATION
       ═══════════════════════════════════════════════════════ -->
  <div class="tab-panel" data-panel="localisation">

    <!-- Recherche Google Places -->
    <div class="section" style="border-color:rgba(45,95,107,.25);background:linear-gradient(180deg,rgba(45,95,107,.03),var(--card));">
      <div class="sec-head">
        <div class="sec-title">🔎 Recherche d'adresse <span style="font-size:11px;font-weight:400;color:var(--muted);">Tapez une adresse pour auto-remplir les champs + GPS</span></div>
        <span class="badge b-ok">Google Maps</span>
      </div>
      <div class="f">
        <label>🗺️ Adresse complète (Google Places)</label>
        <input type="text"
               id="addressSearch"
               data-places-input
               data-places-endpoint="api/places_autocomplete.php"
               data-places-details-endpoint="api/places_details.php"
               data-places-geocode-endpoint="api/geocode_address.php"
               data-places-street1="adresse_1"
               data-places-street2="adresse_2"
               data-places-postal="code_postal"
               data-places-city="ville"
               data-places-quartier="quartier"
               data-places-country="pays"
               data-places-lat="fLat"
               data-places-lng="fLng"
               data-places-place-id="fPlaceId"
               data-places-formatted="fAddrFmt"
               data-places-country-code="fr"
               placeholder="Ex: 15 avenue Pasteur, Lyon..."
               autocomplete="off">
        <div class="hint">💡 La sélection dans la liste remplit automatiquement adresse, ville, CP, pays et coordonnées GPS exactes.</div>
      </div>
      <div id="gpsBadge" style="margin-top:8px;font-size:12px;color:var(--muted);display:none;">
        📍 GPS : <span id="gpsCoords"></span>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">📍 Adresse détaillée</div>
      </div>
      <div class="fg g2">
        <div class="f s2">
          <label>📍 Adresse (n° et rue)</label>
          <input type="text" name="adresse_1" id="adresse_1" value="<?= h($d('adresse_1', '')) ?>" placeholder="Ex: 15 avenue Pasteur">
        </div>
        <div class="f">
          <label>🏙️ Ville <span class="req">*</span></label>
          <input type="text" name="ville" id="ville" value="<?= h($d('ville', $agence['ville'] ?? '')) ?>" oninput="sync()">
        </div>
        <div class="f">
          <label>📮 Code postal <span class="req">*</span></label>
          <input type="text" name="code_postal" id="code_postal" maxlength="10" value="<?= h($d('code_postal', $agence['code_postal'] ?? '')) ?>">
        </div>
        <div class="f s2 pro-only">
          <label>📍 Complément d'adresse <span class="pro-flag">Pro</span></label>
          <input type="text" name="adresse_2" id="adresse_2" value="<?= h($d('adresse_2', '')) ?>" placeholder="Bâtiment, étage, résidence...">
        </div>
        <div class="f pro-only">
          <label>🗺️ Quartier <span class="pro-flag">Pro</span></label>
          <input type="text" name="quartier" id="quartier" placeholder="Ex: Part-Dieu">
        </div>
        <div class="f pro-only">
          <label>🌍 Pays <span class="pro-flag">Pro</span></label>
          <input type="text" name="pays" id="pays" value="France">
        </div>
      </div>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">🌄 Vue depuis le bien <span class="pro-flag">Pro</span></div>
      </div>
      <div class="chip-group">
        <?php foreach ($vues as $v): ?>
          <label class="chip"><input type="checkbox" name="vue_<?= h($v['code']) ?>" value="1" hidden> <?= h($v['label']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">🚇 Accès aux transports en commun</div>
        <span class="badge b-ok">Temps à pied</span>
      </div>
      <div class="chip-group" data-radio-group="acces_transports">
        <?php
          $tOptions = [
            'moins_5'  => '⚡ < 5 min',
            'moins_10' => '🚶 < 10 min',
            'moins_15' => '🚶 < 15 min',
            'plus_20'  => '🚶 > 20 min',
          ];
          $tCurrent = (string)$d('acces_transports', '');
          foreach ($tOptions as $val => $label):
            $on = $tCurrent === $val ? 'on' : '';
        ?>
          <label class="chip chip-radio <?= $on ?>" data-radio-value="<?= h($val) ?>">
            <input type="radio" name="acces_transports" value="<?= h($val) ?>" <?= $on ? 'checked' : '' ?> hidden>
            <?= $label ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">🛍️ Accès aux commerces — distance au centre-ville</div>
        <span class="badge b-ok">Distance</span>
      </div>
      <div class="chip-group" data-radio-group="distance_commerces">
        <?php
          $cOptions = [
            'moins_200'  => '🏃 < 200 m',
            'moins_400'  => '🚶 < 400 m',
            'moins_600'  => '🚶 < 600 m',
            'moins_800'  => '🚶 < 800 m',
            'plus_1200'  => '🚗 > 1,2 km',
          ];
          $cCurrent = (string)$d('distance_commerces', '');
          foreach ($cOptions as $val => $label):
            $on = $cCurrent === $val ? 'on' : '';
        ?>
          <label class="chip chip-radio <?= $on ?>" data-radio-value="<?= h($val) ?>">
            <input type="radio" name="distance_commerces" value="<?= h($val) ?>" <?= $on ? 'checked' : '' ?> hidden>
            <?= $label ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <input type="hidden" name="precision_geoloc" id="fPrecGeo" value="approximative">

    <div class="panel-nav">
      <button type="button" class="nav-btn prev" onclick="goTabByName('identification')">← Identification</button>
      <button type="button" class="nav-btn next" onclick="goTabByName('financier')">Financier →</button>
    </div>
  </div><!-- /panel localisation -->


  <!-- ═══════════════════════════════════════════════════════
       PANEL 3 — FINANCIER
       ═══════════════════════════════════════════════════════ -->
  <div class="tab-panel" data-panel="financier">

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">💰 Prix</div>
        <span class="badge b-warn">Obligatoire</span>
      </div>
      <div class="fg g2">
        <div class="f">
          <label>💶 <span id="lblPrix">Prix de vente</span> <span class="req">*</span></label>
          <div class="iu">
            <input type="number" name="prix" id="prix" min="0" step="1" value="<?= h($d('prix','')) ?>" oninput="sync()">
            <span class="unit" id="unitPrix">€</span>
          </div>
        </div>
        <div class="f pro-only">
          <label>🧾 Honoraires <span class="pro-flag">Pro</span></label>
          <div class="iu">
            <input type="number" name="honoraires" min="0" step="1">
            <span class="unit">€</span>
          </div>
        </div>
        <div class="f pro-only">
          <label>⚖️ Honoraires à charge de <span class="pro-flag">Pro</span></label>
          <select name="honoraires_charge">
            <option value="">—</option>
            <option value="acquereur">Acquéreur</option>
            <option value="vendeur">Vendeur</option>
            <option value="partage">Partagé</option>
          </select>
        </div>
        <div class="f pro-only">
          <label>💷 Prix hors honoraires (net vendeur) <span class="pro-flag">Pro</span></label>
          <div class="iu">
            <input type="number" name="prix_net_vendeur" min="0" step="1">
            <span class="unit">€</span>
          </div>
        </div>
      </div>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">📊 Charges &amp; taxes <span class="pro-flag">Pro</span></div>
      </div>
      <div class="fg g2">
        <div class="f">
          <label>💡 Charges mensuelles</label>
          <div class="iu"><input type="number" name="charges" min="0" step="1"><span class="unit">€/mois</span></div>
        </div>
        <div class="f">
          <label>🏛️ Taxe foncière</label>
          <div class="iu"><input type="number" name="taxe_fonciere" min="0" step="1"><span class="unit">€/an</span></div>
        </div>
        <div class="f">
          <label>🏠 Taxe d'habitation</label>
          <div class="iu"><input type="number" name="taxe_habitation" min="0" step="1"><span class="unit">€/an</span></div>
        </div>
        <div class="f">
          <label>💰 Charges annuelles</label>
          <div class="iu"><input type="number" name="charges_annuelles" min="0" step="1"><span class="unit">€/an</span></div>
        </div>
      </div>
    </div>

    <div class="section pro-only" id="secLocation" style="display:none;">
      <div class="sec-head">
        <div class="sec-title">🔑 Informations location <span class="pro-flag">Pro</span></div>
      </div>
      <div class="fg g2">
        <div class="f">
          <label>💰 Dépôt de garantie</label>
          <div class="iu"><input type="number" name="depot_garantie" min="0" step="1"><span class="unit">€</span></div>
        </div>
        <div class="f">
          <label>📅 Durée du bail</label>
          <div class="iu"><input type="number" name="duree_bail_mois" min="0" step="1"><span class="unit">mois</span></div>
        </div>
      </div>
    </div>

    <div class="panel-nav">
      <button type="button" class="nav-btn prev" onclick="goTabByName('localisation')">← Localisation</button>
      <button type="button" class="nav-btn next" onclick="goTabByName('caracteristiques')">Caractéristiques →</button>
    </div>
  </div><!-- /panel financier -->


  <!-- ═══════════════════════════════════════════════════════
       PANEL 4 — CARACTÉRISTIQUES
       ═══════════════════════════════════════════════════════ -->
  <div class="tab-panel" data-panel="caracteristiques">

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">📐 Surfaces</div>
        <span class="badge b-warn">Obligatoire</span>
      </div>
      <div class="fg g3">
        <div class="f">
          <label>📐 Surface habitable <span class="req">*</span></label>
          <div class="iu"><input type="number" name="surface_habitable" id="surf" min="0" step="0.01" value="<?= h($d('surface_habitable','')) ?>" oninput="sync()"><span class="unit">m²</span></div>
        </div>
        <div class="f">
          <label>🌳 Surface terrain</label>
          <div class="iu"><input type="number" name="surface_terrain" min="0" step="0.01"><span class="unit">m²</span></div>
        </div>
        <div class="f pro-only">
          <label>📏 Loi Carrez <span class="pro-flag">Pro</span></label>
          <div class="iu"><input type="number" name="surface_carrez" min="0" step="0.01"><span class="unit">m²</span></div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">🔢 Composition</div>
      </div>
      <div class="fg g4">
        <div class="f"><label>🛏️ Pièces</label><input type="number" name="nb_pieces" min="0"></div>
        <div class="f"><label>🛌 Chambres</label><input type="number" name="nb_chambres" min="0"></div>
        <div class="f"><label>🚿 SDB</label><input type="number" name="nb_salles_bain" min="0"></div>
        <div class="f"><label>🚽 WC</label><input type="number" name="nb_wc" min="0"></div>
        <div class="f pro-only"><label>🏢 Étage <span class="pro-flag">Pro</span></label><input type="number" name="etage" min="0"></div>
        <div class="f pro-only"><label>🏗️ Nb niveaux <span class="pro-flag">Pro</span></label><input type="number" name="nb_niveaux" min="0"></div>
        <div class="f pro-only"><label>📅 Année constr. <span class="pro-flag">Pro</span></label><input type="number" name="annee_construction" min="1800" max="2100"></div>
        <div class="f pro-only"><label>📏 H. sous plafond <span class="pro-flag">Pro</span></label><div class="iu"><input type="number" name="hauteur_sous_plafond" step="0.01"><span class="unit">m</span></div></div>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">⚡ DPE &amp; GES</div>
      </div>
      <div class="fg g4">
        <div class="f">
          <label>🌡️ Classe DPE</label>
          <select name="dpe_classe">
            <option value="">—</option>
            <option>A</option><option>B</option><option>C</option>
            <option>D</option><option>E</option><option>F</option><option>G</option>
          </select>
        </div>
        <div class="f">
          <label>💨 Classe GES</label>
          <select name="ges_classe">
            <option value="">—</option>
            <option>A</option><option>B</option><option>C</option>
            <option>D</option><option>E</option><option>F</option><option>G</option>
          </select>
        </div>
        <div class="f pro-only">
          <label>🌡️ Valeur DPE <span class="pro-flag">Pro</span></label>
          <div class="iu"><input type="number" name="dpe_valeur" min="0"><span class="unit">kWh</span></div>
        </div>
        <div class="f pro-only">
          <label>💨 Valeur GES <span class="pro-flag">Pro</span></label>
          <div class="iu"><input type="number" name="ges_valeur" min="0"><span class="unit">kgCO₂</span></div>
        </div>
      </div>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">🌿 Dépendances &amp; extérieurs <span class="pro-flag">Pro</span></div>
      </div>
      <?php
        $depGrouped = ['exterieur' => [], 'dependance' => []];
        foreach ($dependances as $dep) { $depGrouped[$dep['famille']][] = $dep; }
      ?>
      <?php if ($depGrouped['exterieur']): ?>
        <div class="sub-label">Extérieurs</div>
        <div class="chip-group" style="margin-bottom:10px;">
          <?php foreach ($depGrouped['exterieur'] as $dep):
            // Mapping vers colonne boolean existante de biens
            $cbName = in_array($dep['code'], ['balcon','terrasse','jardin','piscine','cour'], true) ? $dep['code'] : 'dep_' . $dep['code'];
            $checked = $dCheck($cbName);
          ?>
            <label class="chip <?= $checked ?>"><input type="checkbox" name="<?= h($cbName) ?>" value="1" <?= $checked ? 'checked' : '' ?> hidden> <?= h($dep['label']) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($depGrouped['dependance']): ?>
        <div class="sub-label">Dépendances</div>
        <div class="chip-group">
          <?php foreach ($depGrouped['dependance'] as $dep):
            $cbName = in_array($dep['code'], ['garage','cave','grenier','box','dependances'], true) ? $dep['code'] : 'dep_' . $dep['code'];
            $checked = $dCheck($cbName);
          ?>
            <label class="chip <?= $checked ?>"><input type="checkbox" name="<?= h($cbName) ?>" value="1" <?= $checked ? 'checked' : '' ?> hidden> <?= h($dep['label']) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">⚙️ Confort &amp; équipements <span class="pro-flag">Pro</span></div>
      </div>
      <div class="chip-group">
        <?php
          $equipements = [
            'ascenseur'       => '🛗 Ascenseur',
            'climatisation'   => '❄️ Climatisation',
            'fibre'           => '🌐 Fibre',
            'double_vitrage'  => '🔲 Double vitrage',
            'volets_roulants' => '🪟 Volets roulants',
            'cheminee'        => '🔥 Cheminée',
            'alarme'          => '🚨 Alarme',
            'interphone'      => '📞 Interphone',
            'digicode'        => '🔢 Digicode',
            'cuisine_equipee' => '🍳 Cuisine équipée',
          ];
          foreach ($equipements as $key => $label):
            $checked = $dCheck($key);
        ?>
          <label class="chip <?= $checked ?>"><input type="checkbox" name="<?= h($key) ?>" value="1" <?= $checked ? 'checked' : '' ?> hidden> <?= $label ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">🔥 Chauffage &amp; énergie <span class="pro-flag">Pro</span></div>
      </div>
      <div class="sub-label">Type de chauffage</div>
      <div class="chip-group" style="margin-bottom:10px;">
        <?php foreach ($chauffages as $c): ?>
          <label class="chip"><input type="checkbox" name="chauffage_<?= h($c['code']) ?>" value="1" hidden> <?= h($c['label']) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="sub-label">Énergie</div>
      <div class="chip-group">
        <?php foreach ($energies as $e): ?>
          <label class="chip"><input type="checkbox" name="energie_<?= h($e['code']) ?>" value="1" hidden> <?= h($e['label']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel-nav">
      <button type="button" class="nav-btn prev" onclick="goTabByName('financier')">← Financier</button>
      <button type="button" class="nav-btn next" onclick="goTabByName('photos')">Photos →</button>
    </div>
  </div><!-- /panel caracteristiques -->


  <!-- ═══════════════════════════════════════════════════════
       PANEL 5 — PHOTOS
       ═══════════════════════════════════════════════════════ -->
  <div class="tab-panel" data-panel="photos">

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">🖼️ Photos de l'annonce</div>
        <span class="badge b-warn" id="photosBadge">Min 5 recommandées</span>
      </div>
      <?php if ($draftId <= 0): ?>
        <div style="padding:14px;background:var(--card3);border:1px dashed var(--border);border-radius:var(--r-md);color:var(--muted);font-size:13px;">
          💡 Enregistrez d'abord l'annonce en brouillon (bouton ci-dessous) pour pouvoir ajouter des photos.
        </div>
      <?php else: ?>
        <div class="photo-drop" id="photoDrop">
          <div style="font-size:40px;margin-bottom:8px;">📸</div>
          <div style="font-weight:700;font-size:16px;margin-bottom:4px;">Glissez vos photos ici ou cliquez pour choisir</div>
          <div style="font-size:13px;color:var(--muted);">JPG · PNG · WEBP — max 10 Mo — 1ère photo = visuel principal</div>
          <div style="font-size:12px;color:var(--muted);margin-top:8px;">Génération auto WebP (thumb/medium/large) + nommage SEO + compression</div>
          <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
        </div>
        <div class="photo-thumbs" id="photoThumbs">
          <?php
            // Liste les photos "original" déjà rattachées à l'annonce
            $st = $pdo->prepare("SELECT id, url_photo, ordre_affichage, principale FROM annonces_photos WHERE id_annonce=? AND variante='original' ORDER BY ordre_affichage");
            $st->execute([$draftId]);
            $existingPhotos = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existingPhotos as $ph):
          ?>
            <div class="pthumb" data-photo-id="<?= (int)$ph['id'] ?>" style="background:#fff;">
              <img src="<?= h($ph['url_photo']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
              <span style="font-size:10px;color:#fff;position:absolute;bottom:3px;left:4px;background:rgba(0,0,0,.5);padding:1px 5px;border-radius:6px;"><?= (int)$ph['ordre_affichage'] ?></span>
              <button type="button" onclick="deletePhoto(<?= (int)$ph['id'] ?>)" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;background:rgba(239,68,68,.9);color:#fff;font-size:11px;line-height:1;border:none;cursor:pointer;">×</button>
            </div>
          <?php endforeach; ?>
        </div>
        <div id="uploadStatus" style="margin-top:10px;font-size:13px;color:var(--muted);"></div>
      <?php endif; ?>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">🎬 Vidéo &amp; visite virtuelle <span class="pro-flag">Pro</span></div>
      </div>
      <div class="fg g2">
        <div class="f">
          <label>🎬 URL vidéo (YouTube, Vimeo)</label>
          <input type="url" name="video_url" placeholder="https://...">
        </div>
        <div class="f">
          <label>🌐 Visite virtuelle 3D</label>
          <input type="url" name="visite_virtuelle_url" placeholder="https://...">
        </div>
      </div>
    </div>

    <div class="panel-nav">
      <button type="button" class="nav-btn prev" onclick="goTabByName('caracteristiques')">← Caractéristiques</button>
      <button type="button" class="nav-btn next" onclick="goTabByName('seo')">Annonce &amp; SEO →</button>
    </div>
  </div><!-- /panel photos -->


  <!-- ═══════════════════════════════════════════════════════
       PANEL 6 — ANNONCE & SEO
       ═══════════════════════════════════════════════════════ -->
  <div class="tab-panel" data-panel="seo">

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">📝 Description brute <span style="font-size:11px;font-weight:400;color:var(--muted);">(notes, points à retenir)</span></div>
      </div>
      <div class="f">
        <textarea name="description" id="descTxt" placeholder="Décrivez le bien en quelques phrases, notes brutes. Point fort, emplacement, ambiance, petits défauts à signaler... L'IA les transformera ensuite en annonce commerciale optimisée." oninput="sync()"><?= h($d('description','')) ?></textarea>
        <div class="hint" id="descMsg">Minimum 100 caractères recommandés pour alimenter l'IA</div>
      </div>
      <div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button type="button" class="ia-btn-gen" id="btnGenIA" onclick="generateIA()">
          ✨ Générer l'annonce avec l'IA
        </button>
        <div class="ia-status" id="iaStatus">L'IA rédige une annonce commerciale positive à partir des données du bien + votre description.</div>
      </div>
    </div>

    <div class="section" id="secAnnonceIA" style="border-color:rgba(118,64,212,.25);background:linear-gradient(180deg,rgba(118,64,212,.03),var(--card));">
      <div class="sec-head">
        <div class="sec-title">✨ Annonce rédigée par l'IA <span class="ia-tag">GPT</span></div>
        <span class="badge b-warn" id="iaBadge">Non générée</span>
      </div>
      <div class="f">
        <label>Texte de l'annonce (modifiable)</label>
        <textarea name="texte_ia" id="texteIA" style="min-height:160px;" placeholder="Le texte généré apparaîtra ici. Vous pourrez l'éditer avant publication." oninput="sync()"><?= h($d('texte_ia','')) ?></textarea>
        <div class="hint">💡 Vous pouvez modifier le texte proposé par l'IA avant de publier.</div>
      </div>
      <div id="iaPointsForts" style="display:none;margin-top:12px;">
        <div class="sub-label">Points forts suggérés</div>
        <ul class="points-forts" id="iaPointsFortsList"></ul>
      </div>
      <input type="hidden" name="points_forts_json" id="iaPFHidden" value="<?= h($d('points_forts','')) ?>">
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">🎯 Balises SEO <span class="pro-flag">Pro</span></div>
      </div>
      <div class="fg">
        <div class="f">
          <label>🏷️ Meta title <span style="font-size:11px;font-weight:400;color:var(--muted);">(50–60 car. optimal)</span></label>
          <input type="text" name="meta_title" id="metaTitle" placeholder="Ex: Appartement T3 à Lyon 3e — 95m² — 450 000 €">
        </div>
        <div class="f">
          <label>📄 Meta description <span style="font-size:11px;font-weight:400;color:var(--muted);">(150–160 car. optimal)</span></label>
          <textarea name="meta_description" id="metaDesc" style="min-height:60px;" placeholder="Description courte affichée dans les résultats Google"></textarea>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div class="sec-title">🔍 Aperçu Google</div>
      </div>
      <div class="seo-box">
        <div class="seo-url">maboximmo.com › <span id="seoUrlSlug">...</span></div>
        <div class="seo-title" id="seoTitle">Désignation du bien</div>
        <div class="seo-desc" id="seoDesc">La description s'affiche ici au fur et à mesure de la saisie.</div>
      </div>
    </div>

    <div class="section pro-only">
      <div class="sec-head">
        <div class="sec-title">📢 Options de publication <span class="pro-flag">Pro</span></div>
      </div>
      <div class="chip-group">
        <label class="chip"><input type="checkbox" name="exclusivite" value="1" hidden>⭐ Exclusivité</label>
        <label class="chip"><input type="checkbox" name="coup_coeur" value="1" hidden>❤️ Coup de cœur</label>
        <label class="chip"><input type="checkbox" name="nouveaute" value="1" hidden>✨ Nouveauté</label>
        <label class="chip"><input type="checkbox" name="visible_portails" value="1" hidden>📡 Diffusion portails</label>
      </div>
    </div>

    <div class="panel-nav">
      <button type="button" class="nav-btn prev" onclick="goTabByName('photos')">← Photos</button>
      <span></span>
    </div>
  </div><!-- /panel seo -->

  <div class="pb-space"></div>
</div><!-- /left-col -->

<!-- ════ DROITE — APERÇU ════ -->
<div class="right-col">

  <div class="pcard">
    <div class="phero">
      <span class="phero-badge" id="pBadge">🤝 Vente</span>
      <div class="phero-label">
        <div class="phero-type"><span id="pType">Type</span> · <span id="pVille"><?= h($agence['ville'] ?? 'Ville') ?></span></div>
        <div class="phero-title" id="pTitle">Désignation du bien</div>
      </div>
    </div>
    <div class="pbody">
      <div class="pkpis">
        <div class="kbox"><div class="kval" id="pPrix">—</div><div class="klbl">Prix</div></div>
        <div class="kbox"><div class="kval" id="pSurf">—</div><div class="klbl">Surface</div></div>
      </div>

      <div id="pPointsForts" style="display:none;">
        <div class="pdesc-label">✨ Points forts</div>
        <ul class="points-forts" id="pPFList"></ul>
      </div>

      <div id="pIaBlock" style="display:none;">
        <div class="pdesc-label ia">✨ Annonce IA <span class="ia-tag">GPT</span></div>
        <div class="pdesc ia" id="pIaTxt"></div>
      </div>

      <div class="pdesc-label">📝 Description</div>
      <div class="pdesc" id="pDesc">La description apparaîtra ici.</div>
    </div>
  </div>

  <div class="scard">
    <div class="scard-title">📊 Complétude <span style="margin-left:auto;font-size:20px;font-weight:800;color:var(--accent2);" id="scorePct">15%</span></div>
    <div class="si"><span class="si-icon" id="ic-type">⚠️</span><span class="si-name">Type &amp; offre</span><div class="si-bar"><div class="si-fill warn" id="bar-type" style="width:50%"></div></div></div>
    <div class="si"><span class="si-icon" id="ic-essentiels">❌</span><span class="si-name">Essentiels</span><div class="si-bar"><div class="si-fill no" id="bar-essentiels" style="width:0%"></div></div></div>
    <div class="si"><span class="si-icon" id="ic-photos">❌</span><span class="si-name">Photos</span><div class="si-bar"><div class="si-fill no" id="bar-photos" style="width:0%"></div></div></div>
    <div class="si"><span class="si-icon" id="ic-desc">❌</span><span class="si-name">Description</span><div class="si-bar"><div class="si-fill no" id="bar-desc" style="width:0%"></div></div></div>
    <div class="divider"></div>
    <div style="font-size:12px;color:var(--muted);">Seuil de publication : <strong style="color:var(--amber)">80%</strong>.</div>
  </div>

</div><!-- /right-col -->
</div><!-- /layout -->

<!-- BOTTOM BAR -->
<div class="bottom-bar">
  <div>
    <a href="default.php" class="bbtn ghost">← Annuler</a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button type="submit" name="action" value="brouillon" class="bbtn ghost">💾 Enregistrer en brouillon</button>
    <button type="submit" name="action" value="publier" class="bbtn success">✅ Créer &amp; publier</button>
  </div>
</div>

</form><!-- /formAnnonce -->
</div><!-- /main -->
</div><!-- /app-wrap -->

<script>
function toggleTheme(){
  const h=document.documentElement;
  const dark=h.dataset.theme==='dark';
  h.dataset.theme=dark?'light':'dark';
  document.querySelector('.btn-theme').textContent=dark?'🌙':'☀️';
}

// ════ ONGLETS ════════════════════════════════════════════
function goTab(btnEl){
  const name = btnEl.dataset.tab;
  goTabByName(name);
}
function goTabByName(name){
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === name));
  // Scroll haut de la left-col
  const lc = document.querySelector('.left-col');
  if(lc) lc.scrollTop = 0;
  sync();
}

// ════ TYPE / OFFRE ═══════════════════════════════════════
function selType(el){
  document.querySelectorAll('.tc').forEach(c=>c.classList.remove('sel'));
  el.classList.add('sel');
  document.getElementById('fType').value = el.dataset.id;
  const badge = document.getElementById('typeBadge');
  badge.textContent = '✓ ' + el.dataset.label;
  badge.className = 'badge b-ok';
  document.getElementById('pType').textContent = el.dataset.label;
  sync();
}

function selOffre(el){
  document.querySelectorAll('.oc').forEach(c=>c.classList.remove('sel'));
  el.classList.add('sel');
  const code = el.dataset.code;
  document.getElementById('fTrans').value = code;
  const labels = {vente:'🤝 Vente', location:'🔑 Location', viager:'🔁 Viager'};
  document.getElementById('pBadge').textContent = labels[code] || 'Offre';
  document.getElementById('offreBadge').textContent = '✓ ' + (code.charAt(0).toUpperCase() + code.slice(1));

  // Adapte libellé et unité du prix selon le type d'offre
  const lblPrix = document.getElementById('lblPrix');
  const unitPrix = document.getElementById('unitPrix');
  const secLoc = document.getElementById('secLocation');
  if(code === 'location'){
    if(lblPrix)  lblPrix.textContent = 'Loyer mensuel';
    if(unitPrix) unitPrix.textContent = '€/mois';
    if(secLoc)   secLoc.style.display = '';
  } else if(code === 'viager'){
    if(lblPrix)  lblPrix.textContent = 'Bouquet viager';
    if(unitPrix) unitPrix.textContent = '€';
    if(secLoc)   secLoc.style.display = 'none';
  } else {
    if(lblPrix)  lblPrix.textContent = 'Prix de vente';
    if(unitPrix) unitPrix.textContent = '€';
    if(secLoc)   secLoc.style.display = 'none';
  }
  sync();
}

// ════ MODE RAPIDE / PRO ══════════════════════════════════
function setMode(m,btn){
  document.querySelectorAll('.mode-btn').forEach(b=>b.classList.remove('on'));
  btn.classList.add('on');
  document.body.classList.remove('mode-fast','mode-pro');
  document.body.classList.add('mode-' + m);
}

// ════ CHIPS (cases à cocher stylisées) ═══════════════════
document.addEventListener('change', function(e){
  const el = e.target;
  if(el.tagName !== 'INPUT') return;
  const chip = el.closest('.chip');
  if(!chip) return;

  if(el.type === 'checkbox'){
    chip.classList.toggle('on', el.checked);
  } else if(el.type === 'radio'){
    // Dans un groupe radio (chip-radio), on désactive les autres chips du même groupe
    const group = chip.closest('[data-radio-group]');
    if(group){
      group.querySelectorAll('.chip').forEach(c => c.classList.remove('on'));
    }
    chip.classList.add('on');
  }
});

// Slugify minimal côté client (miroir de SeoSlug::slugify)
function slugify(s){
  return (s||'').toString()
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g,'-')
    .replace(/(^-|-$)/g,'')
    .substring(0,80);
}

function val(id){ const el = document.getElementById(id); return el ? el.value : ''; }

function sync(){
  const d = val('desig');
  const p = parseInt(val('prix') || 0);
  const s = val('surf');
  const v = val('ville');
  const desc = val('descTxt');
  const typeSel = document.querySelector('.tc.sel');
  const offreSel = document.querySelector('.oc.sel');

  // Désignation — validation visuelle
  const n = d.length;
  const msg = document.getElementById('desig-msg');
  if(msg){
    if(n === 0) msg.textContent = 'Minimum 50 caractères pour un bon SEO';
    else if(n < 50) msg.textContent = '⚠ ' + n + ' car. — minimum 50';
    else if(n <= 80) msg.textContent = '✓ ' + n + ' car. — longueur idéale';
    else msg.textContent = '⚠ ' + n + ' car. — trop long (max 80)';
  }

  // Aperçu droite
  setText('pTitle', d || 'Désignation du bien');
  setText('pVille', v || 'Ville');
  setHtml('pPrix', p > 0 ? (p.toLocaleString('fr-FR') + ' <span>€</span>') : '—');
  setHtml('pSurf', s ? (s + ' <span>m²</span>') : '—');
  setText('pDesc', desc || 'La description apparaîtra ici.');

  // Aperçu annonce IA (si générée)
  const iaTxt = val('texteIA');
  const iaBlock = document.getElementById('pIaBlock');
  if(iaTxt && iaTxt.trim().length > 0){
    iaBlock.style.display = '';
    setText('pIaTxt', iaTxt);
  } else {
    iaBlock.style.display = 'none';
  }

  // SEO preview
  setText('seoTitle', d || 'Désignation du bien');
  setText('seoDesc', desc || 'La description s\'affiche ici au fur et à mesure de la saisie.');
  const slugParts = [
    typeSel ? slugify(typeSel.dataset.label) : '',
    slugify(v),
  ].filter(x => x);
  setText('seoUrlSlug', slugParts.join('/') || '...');

  // ── Complétude par onglet (dots sur les tabs) ──
  const okType   = typeSel && offreSel;
  const okLoc    = v.trim() !== '';
  const okFin    = p > 0;
  const okCarac  = s !== '' && s > 0;
  const okPhotos = false; // pas encore branché
  const okSeo    = d.length >= 50 && desc.length >= 100;

  setDot('identification', okType && d.length >= 50 ? 'ok' : (okType || d.length > 0 ? 'warn' : ''));
  setDot('localisation',   okLoc ? 'ok' : '');
  setDot('financier',      okFin ? 'ok' : '');
  setDot('caracteristiques', okCarac ? 'ok' : '');
  setDot('photos',         okPhotos ? 'ok' : '');
  setDot('seo',            okSeo ? 'ok' : (desc.length > 0 ? 'warn' : ''));

  // ── Score global ──
  let score = 0;
  if(okType)   score += 15;
  if(d.length >= 50) score += 10;
  if(okLoc)    score += 15;
  if(okFin)    score += 15;
  if(okCarac)  score += 15;
  if(okPhotos) score += 15;
  if(desc.length >= 100) score += 15;
  else if(desc.length > 0) score += 5;

  // Barres du panneau droite
  setBar('type',        okType ? 'ok' : 'warn', okType ? 100 : 50, okType ? '✅' : '⚠️');
  setBar('essentiels',  (okLoc && okFin && okCarac) ? 'ok' : ((okLoc||okFin||okCarac)?'warn':'no'), (okLoc?33:0)+(okFin?33:0)+(okCarac?34:0), (okLoc&&okFin&&okCarac)?'✅':((okLoc||okFin||okCarac)?'⚠️':'❌'));
  setBar('photos',      'no', 0, '❌');
  setBar('desc',        desc.length>=100?'ok':(desc.length>0?'warn':'no'), Math.min(100,desc.length), desc.length>=100?'✅':(desc.length>0?'⚠️':'❌'));

  setText('progPct', score + '%');
  setText('scorePct', score + '%');
  const pf = document.getElementById('progFill');
  if(pf) pf.style.width = score + '%';
}

function setText(id,txt){ const el = document.getElementById(id); if(el) el.textContent = txt; }
function setHtml(id,html){ const el = document.getElementById(id); if(el) el.innerHTML = html; }

function setDot(tabName, state){
  const d = document.getElementById('dot-' + tabName);
  if(!d) return;
  d.classList.remove('ok','warn','act');
  if(state) d.classList.add(state);
}

function setBar(key, cls, pct, icon){
  const bar = document.getElementById('bar-' + key);
  const ic  = document.getElementById('ic-' + key);
  if(bar){ bar.className = 'si-fill ' + cls; bar.style.width = Math.max(0,Math.min(100,pct)) + '%'; }
  if(ic){ ic.textContent = icon; }
}

// ════ INITIALISATION AU CHARGEMENT ═══════════════════════
document.addEventListener('DOMContentLoaded', function(){
  // Pré-sélection du type de bien depuis le draft
  const fType = document.getElementById('fType').value;
  if(fType){
    const tc = document.querySelector('.tc[data-id="' + fType + '"]');
    if(tc) selType(tc);
  }
  // Pré-sélection du type de transaction
  const fTrans = document.getElementById('fTrans').value || 'vente';
  const oc = document.querySelector('.oc[data-code="' + fTrans + '"]');
  if(oc) selOffre(oc);
  // Marquer les chips pré-cochées comme "on"
  document.querySelectorAll('.chip input[type=checkbox]').forEach(cb => {
    if(cb.checked) cb.closest('.chip').classList.add('on');
  });

  // Badge GPS dynamique
  const fLat = document.getElementById('fLat');
  const fLng = document.getElementById('fLng');
  if(fLat && fLng && fLat.value && fLng.value){
    const b = document.getElementById('gpsBadge');
    const c = document.getElementById('gpsCoords');
    if(b && c){ b.style.display = ''; c.textContent = fLat.value + ', ' + fLng.value; }
  }
  // Écoute les changements sur les hidden fields (Places les remplit)
  ['fLat','fLng'].forEach(id => {
    const el = document.getElementById(id);
    if(el){
      const obs = new MutationObserver(() => {
        const lat = document.getElementById('fLat').value;
        const lng = document.getElementById('fLng').value;
        if(lat && lng){
          const b = document.getElementById('gpsBadge');
          const c = document.getElementById('gpsCoords');
          if(b && c){ b.style.display = ''; c.textContent = (+lat).toFixed(6) + ', ' + (+lng).toFixed(6); }
        }
      });
      obs.observe(el, {attributes:true, attributeFilter:['value']});
    }
  });

  sync();
  initPhotoUpload();
  initAutosave();
});

// ════ UPLOAD PHOTOS (AJAX) ════════════════════════════════
function initPhotoUpload(){
  const drop = document.getElementById('photoDrop');
  const input = document.getElementById('photoInput');
  if(!drop || !input) return;

  drop.addEventListener('click', () => input.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.style.borderColor = 'var(--accent)'; });
  drop.addEventListener('dragleave', e => { e.preventDefault(); drop.style.borderColor = ''; });
  drop.addEventListener('drop', e => {
    e.preventDefault(); drop.style.borderColor = '';
    uploadFiles(e.dataTransfer.files);
  });
  input.addEventListener('change', e => uploadFiles(e.target.files));
}

async function uploadFiles(files){
  if(!files || !files.length) return;
  const status = document.getElementById('uploadStatus');
  const thumbs = document.getElementById('photoThumbs');
  const draftId = <?= (int)$draftId ?>;

  for(let i = 0; i < files.length; i++){
    const f = files[i];
    if(f.size > 10 * 1024 * 1024){
      status.innerHTML = '⚠ <strong>' + f.name + '</strong> dépasse 10 Mo, ignoré';
      continue;
    }
    status.innerHTML = '⏳ Upload de <strong>' + f.name + '</strong>...';

    const fd = new FormData();
    fd.append('photo', f);
    fd.append('id_annonce', draftId);
    fd.append('csrf_token', '<?= h(csrf_token('annonce_nouvelle')) ?>');

    try {
      const r = await fetch('api/annonce_photo_upload.php', {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await r.json();
      if(data.ok){
        status.innerHTML = '✓ <strong>' + f.name + '</strong> importée (4 variantes)';
        // Ajoute une miniature
        const orig = data.photos.find(p => p.variante === 'original');
        if(orig){
          const div = document.createElement('div');
          div.className = 'pthumb';
          div.dataset.photoId = orig.id;
          div.style.background = '#fff';
          div.innerHTML = '<img src="' + data.dir + orig.fichier + '" style="width:100%;height:100%;object-fit:cover;">' +
            '<span style="font-size:10px;color:#fff;position:absolute;bottom:3px;left:4px;background:rgba(0,0,0,.5);padding:1px 5px;border-radius:6px;">' + data.ordre + '</span>' +
            '<button type="button" onclick="deletePhoto(' + orig.id + ')" style="position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;background:rgba(239,68,68,.9);color:#fff;font-size:11px;line-height:1;border:none;cursor:pointer;">×</button>';
          thumbs.appendChild(div);
        }
      } else {
        status.innerHTML = '❌ Erreur : ' + (data.error || 'inconnu');
      }
    } catch(err){
      status.innerHTML = '❌ Erreur réseau : ' + err.message;
    }
  }
  sync();
}

async function deletePhoto(photoId){
  if(!confirm('Supprimer cette photo ?')) return;
  const fd = new FormData();
  fd.append('id_photo', photoId);
  fd.append('csrf_token', '<?= h(csrf_token('annonce_nouvelle')) ?>');
  const r = await fetch('api/annonce_photo_delete.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
  const data = await r.json();
  if(data.ok){
    document.querySelector('.pthumb[data-photo-id="' + photoId + '"]')?.remove();
  } else {
    alert('Erreur : ' + (data.error || 'inconnu'));
  }
}

// ════ AUTOSAVE (toutes les 30 secondes) ══════════════════
let autosaveTimer = null;
let autosaveDirty = false;
function initAutosave(){
  const form = document.getElementById('formAnnonce');
  if(!form) return;
  form.addEventListener('input', () => { autosaveDirty = true; });
  form.addEventListener('change', () => { autosaveDirty = true; });
  autosaveTimer = setInterval(autosave, 30000);
}
async function autosave(){
  if(!autosaveDirty) return;
  const form = document.getElementById('formAnnonce');
  if(!form) return;
  // Ne lance pas d'autosave si le type de bien n'est pas choisi
  if(!document.getElementById('fType').value) return;
  if(!document.getElementById('desig').value) return;

  const fd = new FormData(form);
  fd.set('action', 'autosave');
  try {
    const r = await fetch('annonce_nouvelle.php', {
      method: 'POST',
      body: fd,
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    });
    if(r.ok){
      const data = await r.json();
      if(data.ok){
        autosaveDirty = false;
        // Update URL avec l'id pour les prochains saves
        if(data.id_annonce && !new URLSearchParams(location.search).get('id')){
          history.replaceState(null, '', '?id=' + data.id_annonce);
          document.querySelector('#formAnnonce').action = 'annonce_nouvelle.php?id=' + data.id_annonce;
        }
        // Petit indicateur visuel
        const ind = document.createElement('div');
        ind.textContent = '💾 Brouillon sauvegardé';
        ind.style.cssText = 'position:fixed;bottom:80px;right:20px;background:var(--green);color:#fff;padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;z-index:999;opacity:0;transition:opacity .3s;';
        document.body.appendChild(ind);
        requestAnimationFrame(() => ind.style.opacity = '1');
        setTimeout(() => { ind.style.opacity = '0'; setTimeout(() => ind.remove(), 300); }, 2000);
      }
    }
  } catch(e) { /* silencieux */ }
}

// ════ GÉNÉRATION IA ══════════════════════════════════════
async function generateIA(){
  const btn = document.getElementById('btnGenIA');
  const status = document.getElementById('iaStatus');
  const textarea = document.getElementById('texteIA');
  const badge = document.getElementById('iaBadge');
  const typeSel = document.querySelector('.tc.sel');
  const offreSel = document.querySelector('.oc.sel');

  // Validation minimum
  if(!typeSel){ alert('Sélectionnez d\'abord un type de bien'); return; }
  if(!val('desig')){ alert('Saisissez d\'abord une désignation'); return; }

  btn.disabled = true;
  btn.innerHTML = '⏳ L\'IA rédige...';
  status.className = 'ia-status';
  status.textContent = 'Analyse des données + rédaction en cours (15-30 secondes)...';

  // Helper pour récupérer la valeur d'un input par name
  const byName = n => {
    const el = document.querySelector('[name="' + n + '"]');
    return el ? el.value : '';
  };
  const checked = n => {
    const el = document.querySelector('[name="' + n + '"]');
    return el && el.checked ? 1 : 0;
  };

  // Construit le payload pour bien_ai_generate.php
  const payload = {
    type_bien:   typeSel.dataset.label || '',
    adresse_1:   byName('adresse_1'),
    code_postal: byName('code_postal'),
    ville:       byName('ville'),
    surface:     parseFloat(byName('surface_habitable')) || 0,
    nb_pieces:   parseInt(byName('nb_pieces')) || 0,
    nb_chambres: parseInt(byName('nb_chambres')) || 0,
    nb_sdb:      parseInt(byName('nb_salles_bain')) || 0,
    etage:       parseInt(byName('etage')) || 0,
    nb_etages:   parseInt(byName('nb_niveaux')) || 0,
    loyer_hc:    offreSel && offreSel.dataset.code === 'location' ? (parseFloat(byName('prix')) || 0) : 0,
    charges:     parseFloat(byName('charges')) || 0,
    prix_vente:  offreSel && offreSel.dataset.code === 'vente' ? (parseFloat(byName('prix')) || 0) : 0,
    dpe_classe:  byName('dpe_classe'),
    ges_classe:  byName('ges_classe'),
    ascenseur:   checked('ascenseur'),
    parking:     checked('garage'),
    balcon:      checked('balcon'),
    terrasse:    checked('terrasse'),
    cave:        checked('cave'),
    digicode:    checked('digicode'),
    fibre:       checked('fibre'),
    // Note brute = la description que l'utilisateur a tapée
    description_brute: val('descTxt'),
  };

  try {
    const r = await fetch('api/bien_ai_generate.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await r.json();

    if(!data.ok){
      throw new Error(data.error || 'Erreur inconnue');
    }

    // Remplit le textarea IA
    textarea.value = data.description || '';

    // Points forts
    if(Array.isArray(data.points_forts) && data.points_forts.length){
      const ul = document.getElementById('iaPointsFortsList');
      const pul = document.getElementById('pPFList');
      ul.innerHTML = ''; pul.innerHTML = '';
      data.points_forts.forEach(pt => {
        const li = document.createElement('li'); li.textContent = pt;
        const li2 = document.createElement('li'); li2.textContent = pt;
        ul.appendChild(li); pul.appendChild(li2);
      });
      document.getElementById('iaPointsForts').style.display = '';
      document.getElementById('pPointsForts').style.display = '';
      document.getElementById('iaPFHidden').value = JSON.stringify(data.points_forts);
    }

    // Meta SEO (si pro mode actif)
    if(data.meta_title){
      const mt = document.getElementById('metaTitle');
      if(mt && !mt.value) mt.value = data.meta_title;
    }
    if(data.meta_description){
      const md = document.getElementById('metaDesc');
      if(md && !md.value) md.value = data.meta_description;
    }

    badge.textContent = '✓ Générée';
    badge.className = 'badge b-ok';
    status.className = 'ia-status ok';
    status.innerHTML = '✓ Annonce générée avec succès — ' + (data.description || '').length + ' caractères';

    sync();
  } catch(e){
    status.className = 'ia-status err';
    status.textContent = '❌ Erreur : ' + e.message;
  } finally {
    btn.disabled = false;
    btn.innerHTML = '✨ Régénérer l\'annonce';
  }
}

sync();
</script>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
