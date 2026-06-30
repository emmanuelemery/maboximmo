<?php
declare(strict_types=1);
set_time_limit(60);

/**
 * POST /api/bien_generate_annonce.php
 *
 * Génère en un seul appel IA tous les contenus optimisés SEO + Le Bon Coin
 * pour une annonce : titre_seo, titre_lbc, slug, h1, description, meta_description,
 * mots_cles, alt_photos.
 *
 * Paramètres POST :
 *   - csrf_token
 *   - id_bien (int, requis)
 *   - transaction (string)           : location | vente
 *   - note_ia (string, optionnel)    : consignes personnalisées du user
 *                                      (ex: "ton chaleureux, insister sur la vue mer")
 *   - quartier (string, optionnel)
 *   - points_interet (string, optionnel) : free-text (ex: "tram ligne 1 à 200m, école Jules Ferry")
 *   - environnement (array, optionnel)   : { exposition, vue[], ambiance[], nuisances[] }
 *   - argument_phare (string, optionnel)
 *
 * Réponse JSON :
 *   { ok: true,
 *     generated: { titre_seo, titre_lbc, slug, h1, description, meta_description,
 *                  mots_cles: [...], alt_photos: { photo_id: "alt text", ... } },
 *     bien_ref, transaction, model_used, tokens_used }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = $GLOBALS['pdo'];
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $roleId    = (int)($_SESSION['id_role'] ?? 0);

    $idBien = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
    if ($idBien <= 0) exit(json_encode(['ok' => false, 'error' => 'id_bien requis']));

    $transaction   = strtolower(trim((string)($_POST['transaction'] ?? '')));
    $noteIa        = trim((string)($_POST['note_ia'] ?? ''));
    $quartier      = trim((string)($_POST['quartier'] ?? ''));
    $pointsInteret = trim((string)($_POST['points_interet'] ?? ''));
    $argumentPhare = trim((string)($_POST['argument_phare'] ?? ''));
    $environnement = is_array($_POST['environnement'] ?? null) ? $_POST['environnement'] : [];

    if (!in_array($transaction, ['location', 'vente'], true)) {
        exit(json_encode(['ok' => false, 'error' => 'transaction invalide (location|vente)']));
    }

    // ─── Chargement du bien + scope vérif ──
    $stmtB = $pdo->prepare("
        SELECT b.*, tb.code AS type_bien_code, tb.libelle AS type_bien_libelle
        FROM biens b
        LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        WHERE b.id = ?
    ");
    $stmtB->execute([$idBien]);
    $bien = $stmtB->fetch(PDO::FETCH_ASSOC);
    if (!$bien) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
    if ($societeId > 0 && $roleId !== 7 && $roleId !== 1 && (int)($bien['id_societe'] ?? 0) !== $societeId) {
        exit(json_encode(['ok' => false, 'error' => 'Accès refusé']));
    }

    // ─── Photos analysées (IA Vision) ──
    $photos = [];
    try {
        $stmtP = $pdo->prepare("
            SELECT id, ordre, nom_original, categorie, description_ia
            FROM biens_photos
            WHERE id_bien = ? AND description_ia IS NOT NULL AND description_ia <> ''
            ORDER BY ordre ASC, id ASC
            LIMIT 30
        ");
        $stmtP->execute([$idBien]);
        $photos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* colonnes IA absentes → ignore */ }

    // ─── Assemblage du contexte factuel ──
    $typeLibelle   = (string)($bien['type_bien_libelle'] ?? $bien['type_bien_code'] ?? 'Bien');
    $typeCode      = (string)($bien['type_bien_code'] ?? '');
    $surface       = $bien['surface_habitable'] ?? null;
    $pieces        = $bien['nb_pieces'] ?? null;
    $chambres      = $bien['nb_chambres'] ?? null;
    $etage         = trim((string)($bien['etage'] ?? ''));
    $anneeConstr   = $bien['annee_construction'] ?? null;
    $ville         = trim((string)($bien['ville'] ?? ''));
    $codePostal    = trim((string)($bien['code_postal'] ?? ''));
    $dpeClasse     = trim((string)($bien['dpe_classe'] ?? ''));
    $gesClasse     = trim((string)($bien['ges_classe'] ?? ''));
    $chauffage     = trim((string)($bien['chauffage_type'] ?? ''));
    $chauffageEnr  = trim((string)($bien['chauffage_energie'] ?? ''));
    $exposition    = trim((string)($bien['exposition'] ?? $environnement['exposition'] ?? ''));
    $prix          = $transaction === 'vente'
                     ? ($bien['prix_vente_estime'] ?? null)
                     : ($bien['loyer_hc'] ?? null);

    // Normalisation arrays environnement
    $envVue       = array_filter(array_map('trim', (array)($environnement['vue'] ?? [])));
    $envAmbiance  = array_filter(array_map('trim', (array)($environnement['ambiance'] ?? [])));
    $envNuisances = array_filter(array_map('trim', (array)($environnement['nuisances'] ?? [])));

    // ─── Prompt IA ──
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    if (!$apiKey) {
        exit(json_encode(['ok' => false, 'error' => 'Clé OpenAI non configurée (OPENAI_API_KEY)']));
    }
    $model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : ($GLOBALS['OPENAI_TEXT_MODEL'] ?? 'gpt-4o');

    $photosJson = [];
    foreach ($photos as $p) {
        $photosJson[] = [
            'id'          => (int)$p['id'],
            'categorie'   => (string)($p['categorie'] ?? ''),
            'description' => (string)($p['description_ia'] ?? ''),
        ];
    }

    $context = [
        'transaction'        => $transaction === 'vente' ? 'à vendre' : 'à louer',
        'type_bien'          => $typeLibelle,
        'surface_m2'         => $surface,
        'nb_pieces'          => $pieces,
        'nb_chambres'        => $chambres,
        'etage'              => $etage ?: null,
        'annee_construction' => $anneeConstr,
        'ville'              => $ville,
        'code_postal'        => $codePostal,
        'quartier'           => $quartier ?: null,
        'dpe_classe'         => $dpeClasse ?: null,
        'ges_classe'         => $gesClasse ?: null,
        'chauffage'          => trim($chauffage . ' ' . $chauffageEnr) ?: null,
        'exposition'         => $exposition ?: null,
        'vue'                => array_values($envVue),
        'ambiance'           => array_values($envAmbiance),
        'nuisances'          => array_values($envNuisances),
        'points_interet'     => $pointsInteret ?: null,
        'argument_phare'     => $argumentPhare ?: null,
        'prix'               => $prix,
        'photos_analysees'   => $photosJson,
        'note_utilisateur'   => $noteIa ?: null,
    ];

    $system = "Tu es un expert SEO immobilier français, spécialiste Le Bon Coin et Google. "
        . "Tu rédiges des annonces optimisées pour la recherche locale longue traîne. "
        . "TYPE DE BIEN IMPÉRATIF : ce bien est un « {$typeLibelle} ». Emploie EXACTEMENT ce type partout (titres, slug, meta, mots-clés, description). "
        . "Interdiction absolue d'écrire « maison », « appartement », « studio » ou un autre type si ce n'est pas « {$typeLibelle} ». "
        . "Pour des bureaux/locaux commerciaux : pas de « pièces », « chambres », « séjour » — parle de surfaces, postes de travail, stationnement, accessibilité. "
        . "Tu respectes les contraintes légales (ALUR, honoraires, DPE obligatoire 2023). "
        . "Tu ne RÉPONDS QU'EN JSON VALIDE, sans texte avant ni après.";

    $user = <<<PROMPT
Produis en JSON le contenu d'une annonce immobilière optimisé à la fois pour :
1. Google (SEO, title 50-60 chars, meta 150-160 chars, keywords longue traîne locale)
2. Le Bon Coin (titre accrocheur ≤70 chars, description claire, appel au clic)

Contrainte critique : **pas de keyword stuffing**, rédaction naturelle.
Si "note_utilisateur" est fournie, suis ses instructions (ton, focus, exclusions).

Structure JSON EXACTE à produire :
{
  "titre_seo":        "50-60 chars pour <title> Google — format : [Type] [Nb pièces] [Surface] [Action] [Ville] [Quartier]",
  "titre_lbc":        "≤70 chars, punchy, commence par type bien + chiffres clés, 0-2 adjectifs forts",
  "slug":             "slug-url-avec-tirets-lowercase-type-transaction-pieces-surface-ville-quartier",
  "h1":               "Titre H1 affiché sur la page publique (peut différer du titre_seo, plus long)",
  "description":      "300-500 mots, 3-5 paragraphes courts séparés par \\n\\n, H2 en ### pour structurer si pertinent. Décris l'emplacement, le bien, les points forts, les commerces/transports, finis par un CTA 'Contactez-nous pour visiter'.",
  "meta_description": "150-160 chars avec CTA, mot-clé principal en début",
  "mots_cles":        ["TOUJOURS basés sur le type réel du bien (voir contexte), ex: '<type réel> ville', '<type réel> surface ville quartier'", "3-5 expressions longue traîne avec localité"],
  "alt_photos":       { "photo_id_INT": "alt SEO descriptif de la photo (max 100 chars, inclut le type de pièce et ville)", ... }
}

Contexte du bien :
{$this_json_placeholder}
PROMPT;
    // Remplacement manuel car le heredoc a déjà consommé {} comme caractères littéraux
    $user = str_replace('{$this_json_placeholder}', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $user);

    $payload = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'max_tokens'  => 2500,
        'temperature' => 0.6,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError)           exit(json_encode(['ok' => false, 'error' => 'Réseau : ' . $curlError]));
    if ($httpCode !== 200)    exit(json_encode(['ok' => false, 'error' => 'OpenAI HTTP ' . $httpCode . ' : ' . mb_substr((string)$response, 0, 300)]));

    $data    = json_decode((string)$response, true);
    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    $gen     = json_decode($content, true);
    if (!is_array($gen)) {
        exit(json_encode(['ok' => false, 'error' => 'Réponse IA non-JSON', 'raw' => mb_substr($content, 0, 500)]));
    }

    // Normalisation : mots_cles doit être array, alt_photos doit être object
    $gen['mots_cles']  = isset($gen['mots_cles']) && is_array($gen['mots_cles']) ? array_values($gen['mots_cles']) : [];
    $gen['alt_photos'] = isset($gen['alt_photos']) && is_array($gen['alt_photos']) ? $gen['alt_photos'] : new stdClass();

    echo json_encode([
        'ok'          => true,
        'generated'   => $gen,
        'bien_ref'    => (string)($bien['reference_bien'] ?? ''),
        'transaction' => $transaction,
        'model_used'  => $model,
        'tokens_used' => (int)($data['usage']['total_tokens'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
