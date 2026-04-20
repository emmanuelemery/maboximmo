<?php
declare(strict_types=1);
/**
 * bien_ai_generate.php
 * Génère automatiquement : description, points forts, SEO, analyse marché
 * à partir des données du bien (champs + photos en base64 optionnelles).
 *
 * POST JSON :
 *  {
 *    type_bien, adresse_1, code_postal, ville,
 *    surface, nb_pieces, nb_chambres, nb_sdb,
 *    etage, nb_etages,
 *    loyer_hc, charges, prix_vente,
 *    dpe_classe, ges_classe,
 *    meuble, ascenseur, parking, balcon, terrasse, cave, digicode, fibre,
 *    photos: [ { data: "data:image/jpeg;base64,..." }, ... ]  // max 4
 *  }
 *
 * Réponse JSON :
 *  {
 *    ok: true,
 *    description,        // texte libre ~200 mots
 *    points_forts,       // array 3 bullets
 *    meta_title,         // ~60 chars
 *    meta_description,   // ~155 chars
 *    mots_cles,          // array 6-8 mots
 *    slug,               // URL-friendly
 *    prix_m2,            // calculé si surface+prix
 *    marche: {
 *      prix_moyen_m2,    // estimation GPT marché local
 *      note,             // 1-10
 *      note_label,       // "Bon plan" / "Prix du marché" / "Au-dessus du marché"
 *      commentaire       // 1 phrase
 *    }
 *  }
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
verify_csrf_any('ajouter_bien');
RateLimiter::checkAI();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

if (empty($OPENAI_API_KEY)) {
    echo json_encode(['ok' => false, 'error' => 'no_openai_key']);
    exit;
}

/* ── Action spéciale : optimiser la désignation SEO ─── */
if (($body['action'] ?? '') === 'optimize_designation') {
    $desc = trim((string)($body['description'] ?? ''));
    $type = trim((string)($body['type_bien'] ?? ''));
    $ville = trim((string)($body['ville'] ?? ''));
    $cp = trim((string)($body['code_postal'] ?? ''));
    $nbP = (int)($body['nb_pieces'] ?? 0);
    $surf = (float)($body['surface_habitable'] ?? 0);
    $current = trim((string)($body['designation_actuelle'] ?? ''));

    $prompt = "Tu es expert SEO immobilier Le Bon Coin / SeLoger. À partir de cette description d'annonce, génère :\n"
        . "1. Une désignation commerciale optimisée SEO (50-80 caractères, format: Type + nb pièces + surface + atout principal + ville)\n"
        . "2. Un titre SEO (50-60 caractères)\n"
        . "3. Une meta description (150-160 caractères)\n"
        . "4. 6-8 mots-clés longue traîne\n\n"
        . "Contexte : type={$type}, ville={$ville} {$cp}, {$nbP} pièces, {$surf}m²\n"
        . "Désignation actuelle : {$current}\n\n"
        . "Description :\n" . mb_substr($desc, 0, 1500) . "\n\n"
        . "Réponds UNIQUEMENT en JSON :\n"
        . '{"designation_seo":"...","meta_title":"...","meta_description":"...","mots_cles":["...",...]}'
        . "\nPas de markdown, pas d'explication.";

    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $OPENAI_API_KEY],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $resp = json_decode($raw, true);
        $content = $resp['choices'][0]['message']['content'] ?? '';
        $content = preg_replace('/```json\s*|\s*```/', '', trim($content));
        $result = json_decode($content, true);
        if ($result) {
            echo json_encode(array_merge(['ok' => true], $result));
        } else {
            echo json_encode(['ok' => false, 'error' => 'parse_error']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Extraire les données (payload JSON du formulaire) ─── */
$typeBien   = trim((string)($body['type_bien']    ?? 'bien'));
$adresse    = trim((string)($body['adresse_1']    ?? ''));
$cp         = trim((string)($body['code_postal']  ?? ''));
$ville      = trim((string)($body['ville']        ?? ''));
$surface    = (float)($body['surface']    ?? 0);
$nbPieces   = (int)  ($body['nb_pieces']  ?? 0);
$nbChambres = (int)  ($body['nb_chambres']?? 0);
$nbSdb      = (int)  ($body['nb_sdb']     ?? 0);
$etage      = (int)  ($body['etage']      ?? 0);
$nbEtages   = (int)  ($body['nb_etages']  ?? 0);
$loyerHc    = (float)($body['loyer_hc']   ?? 0);
$charges    = (float)($body['charges']    ?? 0);
$prixVente  = (float)($body['prix_vente'] ?? 0);
$dpe        = strtoupper(trim((string)($body['dpe_classe'] ?? '')));
$ges        = strtoupper(trim((string)($body['ges_classe'] ?? '')));
$meuble     = !empty($body['meuble']);
$ascenseur  = !empty($body['ascenseur']);
$parking    = !empty($body['parking']);
$balcon     = !empty($body['balcon']);
$terrasse   = !empty($body['terrasse']);
$cave       = !empty($body['cave']);
$digicode   = !empty($body['digicode']);
$fibre      = !empty($body['fibre']);
$photos     = is_array($body['photos'] ?? null) ? array_slice($body['photos'], 0, 4) : [];
$copro      = is_array($body['copro']  ?? null) ? $body['copro'] : [];
$prestations  = is_array($copro['prestations']  ?? null) ? implode(', ', $copro['prestations'])  : '';
$orientations = is_array($copro['orientations'] ?? null) ? implode(', ', $copro['orientations']) : '';
$descriptionBrute = trim((string)($body['description_brute'] ?? ''));

/* ── Orientation IA (optionnelle, passée depuis Card 3 Annonce v2) ── */
$orientationTon      = trim((string)($body['orientation_ton']      ?? ''));
$orientationCible    = trim((string)($body['orientation_cible']    ?? ''));
$orientationKeywords = trim((string)($body['orientation_keywords'] ?? ''));

/* ── Enrichissement serveur depuis la BDD ────────────────
   Si un bien_id est fourni, on complète le contexte avec :
     - toutes les colonnes biens + annonces récentes
     - les analyses Vision déjà calculées sur chaque biens_photos
   Cela permet à ChatGPT de produire un descriptif fondé sur TOUTES
   les caractéristiques saisies + l'analyse visuelle, sans dépendre
   des seules valeurs du formulaire côté client. */
$bienId = isset($body['bien_id']) && ctype_digit((string)$body['bien_id']) ? (int)$body['bien_id'] : 0;
$photoAnalyses = [];   // [ {ordre, categorie, description} ]
$bienDbCtx     = [];   // lignes supplémentaires pour $dataContext

if ($bienId > 0) {
    try {
        $pdo = db();

        // 1. Bien + type + immeuble
        $stmtB = $pdo->prepare("
            SELECT b.*,
                   tb.code    AS _type_code,
                   tb.libelle AS _type_libelle,
                   i.adresse_1 AS _imm_adresse_1,
                   i.code_postal AS _imm_code_postal,
                   i.ville     AS _imm_ville
            FROM biens b
            LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
            LEFT JOIN immeubles  i  ON i.id  = b.id_immeuble
            WHERE b.id = ?
            LIMIT 1
        ");
        $stmtB->execute([$bienId]);
        $bienRow = $stmtB->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($bienRow) {
            // On écrase / complète les valeurs absentes du payload
            if ($typeBien === 'bien' && !empty($bienRow['_type_code']))   $typeBien = (string)$bienRow['_type_code'];
            if ($adresse   === '' && !empty($bienRow['adresse_1']))       $adresse  = (string)$bienRow['adresse_1'];
            if ($adresse   === '' && !empty($bienRow['_imm_adresse_1']))  $adresse  = (string)$bienRow['_imm_adresse_1'];
            if ($cp        === '' && !empty($bienRow['code_postal']))     $cp       = (string)$bienRow['code_postal'];
            if ($cp        === '' && !empty($bienRow['_imm_code_postal'])) $cp      = (string)$bienRow['_imm_code_postal'];
            if ($ville     === '' && !empty($bienRow['ville']))           $ville    = (string)$bienRow['ville'];
            if ($ville     === '' && !empty($bienRow['_imm_ville']))      $ville    = (string)$bienRow['_imm_ville'];
            if ($surface   == 0  && !empty($bienRow['surface_habitable'])) $surface = (float)$bienRow['surface_habitable'];
            if ($nbPieces  == 0  && !empty($bienRow['nb_pieces']))        $nbPieces = (int)$bienRow['nb_pieces'];
            if ($nbChambres == 0 && !empty($bienRow['nb_chambres']))      $nbChambres = (int)$bienRow['nb_chambres'];
            if ($nbSdb     == 0  && !empty($bienRow['nb_salles_bain']))   $nbSdb    = (int)$bienRow['nb_salles_bain'];
            if ($etage     == 0  && !empty($bienRow['etage']))            $etage    = (int)$bienRow['etage'];
            if ($dpe       === '' && !empty($bienRow['dpe_classe']))      $dpe      = strtoupper((string)$bienRow['dpe_classe']);
            if ($ges       === '' && !empty($bienRow['ges_classe']))      $ges      = strtoupper((string)$bienRow['ges_classe']);
            if (!$ascenseur && (int)($bienRow['ascenseur'] ?? 0) === 1)   $ascenseur = true;
            if (!$parking   && ((int)($bienRow['garage'] ?? 0) === 1 || (int)($bienRow['parking_nb'] ?? 0) > 0)) $parking = true;
            if (!$balcon    && (int)($bienRow['balcon'] ?? 0) === 1)      $balcon   = true;
            if (!$terrasse  && (int)($bienRow['terrasse'] ?? 0) === 1)    $terrasse = true;
            if (!$cave      && (int)($bienRow['cave'] ?? 0) === 1)        $cave     = true;
            if (!$digicode  && (int)($bienRow['digicode'] ?? 0) === 1)    $digicode = true;
            if (!$fibre     && (int)($bienRow['fibre'] ?? 0) === 1)       $fibre    = true;

            // Contexte BDD additionnel : champs utiles pour ChatGPT
            if (!empty($bienRow['designation']))          $bienDbCtx[] = 'Désignation : ' . $bienRow['designation'];
            if (!empty($bienRow['exposition']))           $bienDbCtx[] = 'Exposition : ' . $bienRow['exposition'];
            if (!empty($bienRow['vue']))                  $bienDbCtx[] = 'Vue : ' . $bienRow['vue'];
            if (!empty($bienRow['chauffage_type']))       $bienDbCtx[] = 'Chauffage : ' . $bienRow['chauffage_type'] . (!empty($bienRow['chauffage_energie']) ? ' (' . $bienRow['chauffage_energie'] . ')' : '');
            if (!empty($bienRow['annee_construction']))   $bienDbCtx[] = 'Année construction : ' . $bienRow['annee_construction'];
            if (!empty($bienRow['etat_bien']))            $bienDbCtx[] = 'État : ' . $bienRow['etat_bien'];
            if (!empty($bienRow['standing']))             $bienDbCtx[] = 'Standing : ' . $bienRow['standing'];
            if (!empty($bienRow['cuisine_type']))         $bienDbCtx[] = 'Cuisine : ' . $bienRow['cuisine_type'] . (((int)($bienRow['cuisine_equipee'] ?? 0)) === 1 ? ' (équipée)' : '');
            if (!empty($bienRow['surface_sejour']))       $bienDbCtx[] = 'Séjour : ' . $bienRow['surface_sejour'] . ' m²';
            if (!empty($bienRow['surface_terrain']))      $bienDbCtx[] = 'Terrain : ' . $bienRow['surface_terrain'] . ' m²';
            if (!empty($bienRow['surface_balcon']))       $bienDbCtx[] = 'Balcon : ' . $bienRow['surface_balcon'] . ' m²';
            if (!empty($bienRow['surface_terrasse']))     $bienDbCtx[] = 'Terrasse : ' . $bienRow['surface_terrasse'] . ' m²';
            if (!empty($bienRow['surface_jardin']))       $bienDbCtx[] = 'Jardin : ' . $bienRow['surface_jardin'] . ' m²';
            if (!empty($bienRow['hauteur_sous_plafond'])) $bienDbCtx[] = 'Hauteur sous plafond : ' . $bienRow['hauteur_sous_plafond'] . ' m';
            if ((int)($bienRow['cheminee'] ?? 0) === 1)     $bienDbCtx[] = 'Cheminée : oui';
            if ((int)($bienRow['climatisation'] ?? 0) === 1) $bienDbCtx[] = 'Climatisation : oui';
            if ((int)($bienRow['piscine'] ?? 0) === 1)      $bienDbCtx[] = 'Piscine : oui';
            if (!empty($bienRow['commentaire']))          $bienDbCtx[] = 'Notes internes : ' . $bienRow['commentaire'];
        }

        // 2. Annonce principale (prix, loyer, charges, description brute)
        $stmtA = $pdo->prepare("SELECT * FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
        $stmtA->execute([$bienId]);
        $annRow = $stmtA->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($annRow) {
            if ($prixVente == 0 && !empty($annRow['prix']))  $prixVente = (float)$annRow['prix'];
            if ($loyerHc   == 0 && !empty($annRow['loyer'])) $loyerHc   = (float)$annRow['loyer'];
            if ($charges   == 0 && !empty($annRow['charges'])) $charges = (float)$annRow['charges'];
            if ($descriptionBrute === '' && !empty($annRow['description'])) {
                $descriptionBrute = (string)$annRow['description'];
            }
        }

        // 3. Analyses Vision des photos (déjà calculées à l'upload).
        // Tolère l'absence des colonnes description_ia/categorie (migration non passée).
        try {
            $stmtP = $pdo->prepare("
                SELECT ordre, categorie, description_ia
                FROM biens_photos
                WHERE id_bien = ? AND description_ia IS NOT NULL AND description_ia <> ''
                ORDER BY ordre ASC, id ASC
            ");
            $stmtP->execute([$bienId]);
            $photoAnalyses = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $photoAnalyses = [];
        }
    } catch (Throwable $e) {
        error_log('[bien_ai_generate] enrichissement BDD: ' . $e->getMessage());
        // Non-bloquant : on continue avec les seules données du payload
    }
}

/* ── Calcul prix/m² ───────────────────────────────────── */
$prixM2 = null;
$prixRef = $prixVente > 0 ? $prixVente : ($loyerHc > 0 ? ($loyerHc * 12) : 0);
if ($surface > 0 && $prixRef > 0) {
    $prixM2 = round($prixVente > 0 ? $prixVente / $surface : ($loyerHc / $surface), 1);
}

/* ── Construire le contexte pour GPT ─────────────────── */
$options = array_filter([
    $meuble    ? 'meublé'                      : null,
    $ascenseur ? 'ascenseur'                   : null,
    $parking   ? 'parking'                     : null,
    $balcon    ? 'balcon'                       : null,
    $terrasse  ? 'terrasse'                     : null,
    $cave      ? 'cave'                         : null,
    $digicode  ? 'digicode / accès sécurisé'   : null,
    $fibre     ? 'fibre optique'                : null,
]);

$prixCtx = '';
if ($prixVente > 0) {
    $prixCtx = number_format($prixVente, 0, ',', ' ') . ' € (vente)';
} elseif ($loyerHc > 0) {
    $cc = $loyerHc + $charges;
    $prixCtx = number_format($loyerHc, 0, ',', ' ') . ' €/mois HC'
             . ($charges > 0 ? ' + ' . number_format($charges, 0, ',', ' ') . ' € charges = ' . number_format($cc, 0, ',', ' ') . ' € CC' : '');
}

$etageCtx = $etage > 0
    ? "étage {$etage}" . ($nbEtages > 0 ? "/{$nbEtages}" : '')
    : 'rez-de-chaussée';

$dataContext = implode("\n", array_filter(array_merge([
    "Type       : {$typeBien}",
    $adresse    ? "Adresse    : {$adresse}, {$cp} {$ville}" : null,
    $surface    ? "Surface    : {$surface} m²" : null,
    $nbPieces   ? "Pièces     : {$nbPieces}" : null,
    $nbChambres ? "Chambres   : {$nbChambres}" : null,
    $nbSdb      ? "SDB        : {$nbSdb}" : null,
    "Étage      : {$etageCtx}",
    $dpe        ? "DPE        : {$dpe}" : null,
    $ges        ? "GES        : {$ges}" : null,
    $prixCtx    ? "Prix       : {$prixCtx}" : null,
    $options      ? "Options    : " . implode(', ', $options)    : null,
    $prestations  ? "Prestations  : {$prestations}"             : null,
    ($copro['chauffage'] ?? '') ? "Chauffage    : " . $copro['chauffage'] . ' / ' . ($copro['energie'] ?? '') : null,
    ($copro['annee_construction'] ?? '') ? "Construction : " . $copro['annee_construction'] : null,
    $orientations ? "Orientation  : {$orientations}"            : null,
], $bienDbCtx)));

// Notes brutes saisies par l'agent (contexte additionnel)
$notesBrutes = '';
if ($descriptionBrute !== '') {
    $notesBrutes = "\nNOTES DE L'AGENT (à intégrer intelligemment, ne pas recopier mot à mot) :\n" . $descriptionBrute . "\n";
}

// Orientation éditoriale (ton + cible + mots-clés à privilégier)
$orientationBlock = '';
$orientationParts = [];
if ($orientationTon !== '')      $orientationParts[] = 'Ton à adopter : ' . $orientationTon;
if ($orientationCible !== '')    $orientationParts[] = 'Cible à adresser : ' . $orientationCible;
if ($orientationKeywords !== '') $orientationParts[] = 'Mots-clés à intégrer naturellement : ' . $orientationKeywords;
if (!empty($orientationParts)) {
    $orientationBlock = "\nORIENTATION ÉDITORIALE (à respecter pour tout le contenu généré) :\n- " . implode("\n- ", $orientationParts) . "\n";
}

// Analyses Vision des photos (déjà calculées à l'upload)
$photosBlock = '';
if (!empty($photoAnalyses)) {
    $photosBlock = "\nANALYSES VISUELLES DES PHOTOS (produites par GPT-4o Vision, fiables) :\n";
    foreach ($photoAnalyses as $idx => $pa) {
        $n = $idx + 1;
        $cat = $pa['categorie'] ?? 'autre';
        $desc = trim((string)($pa['description_ia'] ?? ''));
        if ($desc === '') continue;
        $photosBlock .= "  Photo {$n} [{$cat}] : {$desc}\n";
    }
}

/* ── Construire le message system + user ──────────────── */
$systemPrompt = <<<SYSTEM
Tu es un expert immobilier rédacteur pour une agence professionnelle française.
Tu génères des contenus pour des fiches biens et des annonces immobilières.

RÈGLES DE RÉDACTION :
- Textes précis, attrayants, professionnels — AUCUN superlatif vide ("magnifique", "exceptionnel" sans justification).
- Intègre NATURELLEMENT les éléments visuels fournis (analyses des photos) — par exemple si une photo mentionne "cuisine ouverte sur séjour, îlot central, tons clairs", cela DOIT transparaître dans la description.
- Ne fabule JAMAIS : ne mentionne que des éléments présents dans les données (caractéristiques + notes de l'agent + analyses photos).
- Respecte la réglementation française : pas de mention discriminatoire, données DPE factuelles.
- Format : UNIQUEMENT du JSON valide, sans markdown, sans backticks, sans balise.
SYSTEM;

$userPrompt = <<<USER
Voici les données d'un bien immobilier :

{$dataContext}
{$notesBrutes}{$orientationBlock}{$photosBlock}
À partir de TOUTES ces informations (caractéristiques techniques, notes de l'agent, analyses visuelles des photos), génère un JSON avec exactement ces clés :
- "description" : texte commercial ~180 mots, style annonce professionnelle, sans répéter les chiffres déjà listés dans le titre, adapté pour le site web et les portails
- "points_forts" : tableau de 3 chaînes courtes (bullet points pour l'annonce, ex: "Lumineux 3 pièces avec balcon", "DPE B - faibles charges", "Proche commerces et transports")
- "titre" : titre d'annonce diffusé sur les portails (Le Bon Coin, SeLoger) ~70 caractères max, accrocheur, intégrant le type + nb pièces + surface + atout principal + ville
- "accroche" : phrase d'accroche commerciale courte (~100 caractères) — un crochet émotionnel / différenciant, sans répéter mot à mot le titre
- "meta_title" : titre SEO Google ~60 caractères max (plus dense, optimisé moteurs)
- "meta_description" : description SEO ~150 caractères accrocheuse
- "mots_cles" : tableau de 6 à 8 mots-clés SEO longue traîne (ex: "appartement 3 pièces Lyon 6ème à louer")
- "slug" : URL en minuscules avec tirets (ex: "appartement-3-pieces-65m2-lyon-6eme")
- "marche" : objet avec :
  - "prix_moyen_m2" : estimation du prix moyen au m² dans ce quartier/ville pour ce type de bien (nombre entier, en €/m²)
  - "note" : note comparative de 1 à 10 (10 = meilleur rapport qualité/prix du marché)
  - "note_label" : une des valeurs "Bon plan", "Prix du marché", "Au-dessus du marché"
  - "commentaire" : 1 phrase d'analyse (ex: "Prix légèrement inférieur au marché local, bonne opportunité pour ce secteur.")

Réponds uniquement avec le JSON, aucun texte avant ou après.
USER;

/* ── Construire les messages (avec vision si photos) ─── */
$messages = [['role' => 'system', 'content' => $systemPrompt]];

if (!empty($photos)) {
    // GPT-4o vision : message avec images
    $contentParts = [['type' => 'text', 'text' => $userPrompt]];
    foreach ($photos as $ph) {
        $dataUrl = is_string($ph['data'] ?? null) ? $ph['data'] : null;
        if ($dataUrl && str_starts_with($dataUrl, 'data:image/')) {
            $contentParts[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $dataUrl, 'detail' => 'low'],
            ];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $contentParts];
} else {
    $messages[] = ['role' => 'user', 'content' => $userPrompt];
}

/* ── Appel OpenAI ──────────────────────────────────────── */
$payload = [
    'model'       => 'gpt-4o',
    'messages'    => $messages,
    'temperature' => 0.7,
    'max_tokens'  => 1500,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 8,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['ok' => false, 'error' => 'curl_error', 'detail' => $curlErr]);
    exit;
}

$apiData = json_decode($response, true);
if (!is_array($apiData) || empty($apiData['choices'][0]['message']['content'])) {
    echo json_encode(['ok' => false, 'error' => 'api_error', 'detail' => $apiData['error']['message'] ?? 'unknown']);
    exit;
}

$rawContent = trim($apiData['choices'][0]['message']['content']);
// Nettoyer si GPT entoure de ```json
$rawContent = preg_replace('/^```json\s*/i', '', $rawContent);
$rawContent = preg_replace('/\s*```$/', '', $rawContent);

$generated = json_decode($rawContent, true);
if (!is_array($generated)) {
    echo json_encode(['ok' => false, 'error' => 'json_parse_error', 'raw' => $rawContent]);
    exit;
}

/* ── Enrichir avec calculs locaux ─────────────────────── */
$generated['ok']      = true;
$generated['prix_m2'] = $prixM2;

// Note label de sécurité
$note = (int)($generated['marche']['note'] ?? 5);
if (!isset($generated['marche']['note_label'])) {
    if ($note >= 8)      $generated['marche']['note_label'] = 'Bon plan';
    elseif ($note >= 5)  $generated['marche']['note_label'] = 'Prix du marché';
    else                 $generated['marche']['note_label'] = 'Au-dessus du marché';
}

echo json_encode($generated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
