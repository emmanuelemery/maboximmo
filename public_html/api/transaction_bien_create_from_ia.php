<?php
// api/transaction_bien_create_from_ia.php — Crée bien + immeuble depuis l'extraction IA
// Réutilise l'infra existante :
//   - bien_form_create_draft() pour le brouillon bien (même flow que bien_detail.php)
//   - geocode_address.php pour la normalisation Google (anti-doublon strict)
// Crée le propriétaire si pas existant, l'immeuble si nécessaire.
// Le bail sera créé séparément via transaction_bail_save.
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bien_form_loader.php';   // → bien_form_create_draft
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$adresse = trim((string)(post('adresse') ?? ''));
$cp      = trim((string)(post('code_postal') ?? ''));
$ville   = trim((string)(post('ville') ?? ''));
$proprio = trim((string)(post('proprietaire') ?? ''));
$desig   = trim((string)(post('designation') ?? ''));
$usage   = trim((string)(post('usage_bien') ?? 'professionnel'));
$typeComm= trim((string)(post('type_commercialisation') ?? ''));
$surface = (float)str_replace([' ', ','], ['', '.'], (string)(post('surface_habitable') ?? '0'));
$loyer   = (float)str_replace([' ', ','], ['', '.'], (string)(post('loyer_hc') ?? '0'));

if ($adresse === '' && $ville === '') {
    echo json_encode(['ok'=>false,'error'=>'adresse ou ville requise']); exit;
}

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$idSoc  = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAge  = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;
$idUser = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    // ─── 1. GÉOCODAGE GOOGLE (anti-doublon strict + remplit lat/lon) ───────
    $latitude = null; $longitude = null; $googlePlaceId = null; $adresseFmt = $adresse;
    $googleKey = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
    if ($googleKey !== '' && $adresse !== '') {
        $query = trim($adresse . ' ' . $cp . ' ' . $ville);
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($query) . '&key=' . urlencode($googleKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $raw) {
            $body = json_decode((string)$raw, true);
            if (($body['status'] ?? '') === 'OK' && !empty($body['results'])) {
                $g = $body['results'][0];
                $latitude  = $g['geometry']['location']['lat'] ?? null;
                $longitude = $g['geometry']['location']['lng'] ?? null;
                $googlePlaceId = $g['place_id'] ?? null;
                $adresseFmt = $g['formatted_address'] ?? $adresse;
            }
        }
    }

    // ─── 2. ANTI-DOUBLON ────────────────────────────────────────────────
    // Strat A : par place_id Google (le plus fiable) si dispo
    if ($googlePlaceId) {
        $check = $pdo->query("SHOW COLUMNS FROM biens LIKE 'google_place_id'")->fetchColumn();
        if ($check) {
            $st = $pdo->prepare('SELECT id FROM biens WHERE google_place_id = ? LIMIT 1');
            $st->execute([$googlePlaceId]);
            $existing = $st->fetchColumn();
            if ($existing) {
                echo json_encode(['ok'=>true, 'bien_id'=>(int)$existing, 'created'=>false, 'reason'=>'doublon_place_id']);
                exit;
            }
        }
    }
    // Strat B : géoloc proche (< 30m) + même type
    if ($latitude && $longitude) {
        $st = $pdo->prepare('SELECT id FROM biens
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
              AND ABS(latitude - ?) < 0.0003 AND ABS(longitude - ?) < 0.0003
              AND (statut_bien IS NULL OR statut_bien NOT IN ("supprime","archive"))
            LIMIT 1');
        $st->execute([$latitude, $longitude]);
        $existing = $st->fetchColumn();
        if ($existing) {
            echo json_encode(['ok'=>true, 'bien_id'=>(int)$existing, 'created'=>false, 'reason'=>'doublon_geoloc']);
            exit;
        }
    }
    // Strat C : adresse textuelle exacte (case-insensitive) + CP
    if ($adresse !== '' && $cp !== '') {
        $st = $pdo->prepare('SELECT id FROM biens
            WHERE LOWER(adresse_1) = LOWER(?) AND code_postal = ?
              AND (statut_bien IS NULL OR statut_bien NOT IN ("supprime","archive"))
            LIMIT 1');
        $st->execute([$adresse, $cp]);
        $existing = $st->fetchColumn();
        if ($existing) {
            echo json_encode(['ok'=>true, 'bien_id'=>(int)$existing, 'created'=>false, 'reason'=>'doublon_adresse']);
            exit;
        }
    }

    // Strat D : adresse normalisée (BLV/BD→Boulevard) + tokens significatifs + CP
    // Détecte SIR-012 "15 BLV Y. FARGE" quand l'IA donne "15 Boulevard Yves Farge"
    if ($adresse !== '' && $cp !== '') {
        $normAddr = function (string $s): string {
            $s = mb_strtolower($s);
            $s = preg_replace('/\b(bd|blv|blvd|bld|b\.)\b\.?/u', 'boulevard', $s);
            $s = preg_replace('/\b(av|ave|avn)\b\.?/u', 'avenue', $s);
            $s = preg_replace('/\b(r|rte)\b\.?/u', 'rue', $s);
            $s = preg_replace('/\b(pl)\b\.?/u', 'place', $s);
            $s = preg_replace('/\b(ch|chem)\b\.?/u', 'chemin', $s);
            $s = str_replace(['.', ','], ' ', (string)$s);
            return preg_replace('/\s+/', ' ', trim((string)$s));
        };
        $stop = ['de','du','des','la','le','les','et','d','l','rue','avenue','boulevard','place','chemin','impasse','route','allee','allée','quai','cours','passage','square'];
        $tokens = [];
        foreach (preg_split('~\s+~', $normAddr($adresse)) as $t) {
            if (mb_strlen($t) < 3 || is_numeric($t) || in_array($t, $stop, true)) continue;
            $tokens[] = $t;
        }
        $numRue = '';
        if (preg_match('/^\s*(\d+)\b/', $adresse, $m)) $numRue = $m[1];

        if (!empty($tokens)) {
            // Récupère candidats au même CP, score le meilleur match
            $st = $pdo->prepare('SELECT id, adresse_1 FROM biens
                WHERE code_postal = ?
                  AND adresse_1 IS NOT NULL AND adresse_1 <> ""
                  AND (statut_bien IS NULL OR statut_bien NOT IN ("supprime","archive"))
                LIMIT 200');
            $st->execute([$cp]);
            $best = null; $bestHits = 0;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $aLow = $normAddr((string)$r['adresse_1']);
                $hits = 0;
                foreach ($tokens as $t) if (str_contains($aLow, $t)) $hits++;
                // Bonus si n° de rue exact
                if ($numRue !== '' && preg_match('/(^|\D)' . preg_quote($numRue, '/') . '(\D|$)/', $aLow)) $hits += 2;
                if ($hits >= max(2, count($tokens))) {
                    if ($hits > $bestHits) { $best = (int)$r['id']; $bestHits = $hits; }
                }
            }
            if ($best) {
                echo json_encode(['ok'=>true, 'bien_id'=>$best, 'created'=>false, 'reason'=>'doublon_adresse_normalisee']);
                exit;
            }
        }
    }

    // ─── 3. CRÉATION PROPRIÉTAIRE si pas trouvé ─────────────────────────
    $idProprio = null;
    if ($proprio !== '') {
        $st = $pdo->prepare('SELECT id FROM proprietaires WHERE societe = ? OR nom = ? LIMIT 1');
        $st->execute([$proprio, $proprio]);
        $idProprio = $st->fetchColumn();
        if (!$idProprio) {
            $colsP = [];
            try {
                $cstP = $pdo->query('SHOW COLUMNS FROM proprietaires');
                while ($r = $cstP->fetch(PDO::FETCH_ASSOC)) $colsP[$r['Field']] = true;
            } catch (Throwable $e) {}
            $fldsP = []; $valsP = []; $pp = [];
            if (isset($colsP['societe']))       { $fldsP[]='societe';       $valsP[]=':soc'; $pp[':soc'] = $proprio; }
            if (isset($colsP['nom']))           { $fldsP[]='nom';           $valsP[]=':nom'; $pp[':nom'] = $proprio; }
            if (isset($colsP['type_personne'])) { $fldsP[]='type_personne'; $valsP[]=':tp';  $pp[':tp']  = 'societe'; }
            if (isset($colsP['actif']))         { $fldsP[]='actif';         $valsP[]=':a';   $pp[':a']   = 1; }
            if (!empty($fldsP)) {
                $sqlP = 'INSERT INTO proprietaires (' . implode(',', $fldsP) . ') VALUES (' . implode(',', $valsP) . ')';
                $stP = $pdo->prepare($sqlP);
                foreach ($pp as $k => $v) $stP->bindValue($k, $v);
                $stP->execute();
                $idProprio = (int)$pdo->lastInsertId();
            }
        }
    }

    // ─── 4. CRÉATION IMMEUBLE (si déjà présent à cette adresse, on le réutilise) ──
    $idImmeuble = null;
    if ($adresse !== '' && $cp !== '') {
        $st = $pdo->prepare('SELECT id FROM immeubles
            WHERE LOWER(adresse_1) = LOWER(?) AND code_postal = ? LIMIT 1');
        $st->execute([$adresse, $cp]);
        $idImmeuble = $st->fetchColumn();
        if (!$idImmeuble) {
            // Création immeuble minimal
            $colsI = [];
            try {
                $cstI = $pdo->query('SHOW COLUMNS FROM immeubles');
                while ($r = $cstI->fetch(PDO::FETCH_ASSOC)) $colsI[$r['Field']] = true;
            } catch (Throwable $e) {}
            $fldsI = []; $valsI = []; $pi = [];
            $addI = function (string $col, $val) use (&$fldsI, &$valsI, &$pi, $colsI) {
                if (!isset($colsI[$col]) || $val === null || $val === '') return;
                $fldsI[] = $col; $valsI[] = ':' . $col; $pi[':' . $col] = $val;
            };
            $addI('adresse_1', $adresseFmt ?: $adresse);
            $addI('code_postal', $cp);
            $addI('ville', $ville);
            $addI('latitude', $latitude);
            $addI('longitude', $longitude);
            if (isset($colsI['google_place_id'])) $addI('google_place_id', $googlePlaceId);
            $addI('nom_immeuble', $adresseFmt ?: trim($adresse . ' ' . $ville));
            $addI('id_societe', $idSoc);
            $addI('id_agence', $idAge);
            $addI('statut_immeuble', 'actif');
            if (!empty($fldsI)) {
                $sqlI = 'INSERT INTO immeubles (' . implode(',', $fldsI) . ') VALUES (' . implode(',', $valsI) . ')';
                $stI = $pdo->prepare($sqlI);
                foreach ($pi as $k => $v) $stI->bindValue($k, $v);
                $stI->execute();
                $idImmeuble = (int)$pdo->lastInsertId();
            }
        } else {
            $idImmeuble = (int)$idImmeuble;
        }
    }

    // ─── 5. CRÉATION DU BIEN via bien_form_create_draft (infra existante) ──

    // Choix intelligent du type_bien selon usage IA + mots-clés du descriptif/désignation
    $defaultTypeBienId = null;
    try {
        $iaUsage = strtolower((string)$usage); // commercial|professionnel|habitation|mixte
        $iaDescLow = mb_strtolower((string)$desig . ' ' . (string)post('bien_description'));
        // Détection mots-clés dans description/désignation pour préciser
        $isEntrepot = preg_match('/\b(entrep[oô]t|stockage|d[eé]p[oô]t|logistique)\b/u', $iaDescLow);
        $isBoutique = preg_match('/\b(boutique|magasin|vitrine|commerce)\b/u', $iaDescLow);
        $isBureau   = preg_match('/\b(bureau|tertiaire|cabinet)\b/u', $iaDescLow);
        $isAtelier  = preg_match('/\b(atelier|garage|hangar)\b/u', $iaDescLow);

        // Patterns de recherche par priorité dans types_bien.libelle / nom / code
        $patterns = [];
        if ($iaUsage === 'commercial' || $iaUsage === 'professionnel') {
            if ($isEntrepot) $patterns[] = "entrep%";
            if ($isEntrepot || $isAtelier) $patterns[] = "local%activit%";
            if ($isBoutique) $patterns[] = "local%commercial%";
            if ($isBoutique) $patterns[] = "boutique%";
            if ($isBureau || $iaUsage === 'professionnel') $patterns[] = "bureau%";
            $patterns[] = "local%commercial%"; // fallback
            $patterns[] = "local%";
        } elseif ($iaUsage === 'habitation') {
            $patterns[] = "appartement%";
            $patterns[] = "maison%";
        }
        // Tente chaque pattern dans l'ordre
        foreach ($patterns as $p) {
            $stT = $pdo->prepare("SELECT id FROM types_bien
                WHERE LOWER(COALESCE(libelle, nom, '')) LIKE ?
                ORDER BY id LIMIT 1");
            $stT->execute([$p]);
            $found = $stT->fetchColumn();
            if ($found) { $defaultTypeBienId = (int)$found; break; }
        }
        // Fallback ultime : premier id
        if (!$defaultTypeBienId) {
            $tid = $pdo->query('SELECT id FROM types_bien ORDER BY id LIMIT 1')->fetchColumn();
            if ($tid) $defaultTypeBienId = (int)$tid;
        }
    } catch (Throwable $e) { error_log('[create bien - pick type] ' . $e->getMessage()); }

    $bienId = bien_form_create_draft($pdo, $idSoc, $idAge, $defaultTypeBienId, $idUser ?: null);

    // Détecte colonnes dispo pour UPDATE défensif
    $colsB = [];
    try {
        $cstB = $pdo->query('SHOW COLUMNS FROM biens');
        while ($r = $cstB->fetch(PDO::FETCH_ASSOC)) $colsB[$r['Field']] = true;
    } catch (Throwable $e) {}

    $upd = [];
    $params = [':id' => $bienId];
    $setField = function (string $col, $val) use (&$upd, &$params, $colsB) {
        if (!isset($colsB[$col]) || $val === null || $val === '') return;
        $upd[] = $col . ' = :' . $col;
        $params[':' . $col] = $val;
    };
    $setField('id_proprietaire',        $idProprio);
    $setField('id_immeuble',            $idImmeuble);
    $setField('designation',            $desig ?: trim($adresseFmt . ' — ' . $ville));
    $setField('adresse_1',              $adresseFmt ?: $adresse);
    $setField('code_postal',            $cp);
    $setField('ville',                  $ville);
    $setField('latitude',               $latitude);
    $setField('longitude',              $longitude);
    if (isset($colsB['google_place_id'])) $setField('google_place_id', $googlePlaceId);
    $setField('statut_bien',            'actif');
    $setField('type_commercialisation', $typeComm ?: 'location');
    $setField('usage_bien',             $usage);
    if ($surface > 0) { $setField('surface_habitable', $surface); $setField('surface_totale', $surface); }
    if ($loyer   > 0)   $setField('loyer_hc', $loyer);

    if (!empty($upd)) {
        $sql = 'UPDATE biens SET ' . implode(', ', $upd) . ', date_modification = NOW() WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
    }

    // Récupère la référence générée par bien_form_create_draft (TMP-XXX)
    $stRef = $pdo->prepare('SELECT reference_bien FROM biens WHERE id = ? LIMIT 1');
    $stRef->execute([$bienId]);
    $ref = (string)$stRef->fetchColumn();

    echo json_encode([
        'ok'              => true,
        'bien_id'         => $bienId,
        'created'         => true,
        'immeuble_id'     => $idImmeuble,
        'proprietaire_id' => $idProprio,
        'reference_bien'  => $ref,
        'geocoded'        => ($latitude !== null),
        'google_place_id' => $googlePlaceId,
        'adresse_formatee'=> $adresseFmt,
        'edit_url'        => app_url('/bien_detail.php?edit=' . $bienId),
    ]);
} catch (Throwable $e) {
    error_log('[transaction_bien_create_from_ia] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
