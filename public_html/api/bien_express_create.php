<?php
declare(strict_types=1);

/**
 * POST /api/bien_express_create.php
 *
 * Crée un bien brouillon depuis le flow Express. À l'appel initial :
 * - assigne la référence bien (pattern société/agence via ref_generator)
 * - crée le bailleur si nouveau (sinon lien à un existant via id_proprietaire)
 * - crée la ligne biens en statut='brouillon'
 *
 * Entrée minimale : rien (retourne une référence + un id pour le brouillon).
 * Entrée enrichie : les champs DPE extraits + bailleur.
 *
 * Paramètres POST :
 *   - csrf_token
 *   - adresse_1, code_postal, ville (strings)
 *   - type_bien_code (string)
 *   - surface_habitable, nb_pieces, etc.
 *   - id_proprietaire (int, optionnel)       ou
 *   - proprio_nom, proprio_prenom, ...       (création nouveau)
 *   - transaction (location|vente, optionnel — sera stockée sur l'annonce plus tard)
 *
 * Réponse :
 *   { ok: true, id_bien, reference_bien, slug }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/ref_generator.php';
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
    $agenceId  = (int)($_SESSION['id_agence']  ?? 0);
    $userId    = (int)($_SESSION['user_id']    ?? 0);

    if ($agenceId <= 0) {
        exit(json_encode(['ok' => false, 'error' => 'Agence non définie dans la session']));
    }

    // Helpers
    $str  = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));
    $int  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
    $flt  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;

    // ─── 1. Gestion du bailleur (existant ou nouveau) ────────────
    // Note : proprietaires a id_agence, pas id_societe
    $proprioId = $int('id_proprietaire');
    if (!$proprioId) {
        $pNom = $str('proprio_nom');
        if ($pNom !== '') {
            $stmtP = $pdo->prepare("
                INSERT INTO proprietaires (nom, prenom, societe, email, telephone, adresse_1, code_postal, ville,
                    type_personne, id_agence, actif, date_creation, date_modification)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmtP->execute([
                $pNom,
                $str('proprio_prenom') ?: null,
                $str('proprio_societe') ?: null,
                $str('proprio_email') ?: null,
                $str('proprio_telephone') ?: null,
                $str('proprio_adresse') ?: null,
                $str('proprio_code_postal') ?: null,
                $str('proprio_ville') ?: null,
                $str('proprio_societe') !== '' ? 'morale' : 'physique',
                $agenceId ?: null,
            ]);
            $proprioId = (int)$pdo->lastInsertId();
        }
    }

    // RÈGLE (2026-06-07) : pas de bien sans propriétaire. On exige soit un
    // id_proprietaire existant, soit les infos d'un nouveau proprio (proprio_nom).
    // Évite la fabrique de biens orphelins (doublons d'annonce pré-import CRG).
    if (!$proprioId) {
        http_response_code(422);
        exit(json_encode([
            'ok'    => false,
            'error' => 'Sélectionnez un propriétaire avant de créer un bien.',
            'code'  => 'PROPRIETAIRE_REQUIS',
        ], JSON_UNESCAPED_UNICODE));
    }

    // ─── 2. Résolution des 2 ids (nouveau + legacy) depuis le code ──
    require_once dirname(__DIR__) . '/inc/bien_type_helper.php';
    $typeBienCode = $str('type_bien_code') ?: $str('type_bien');
    $idTypeBien   = null;
    $idBienType   = null;
    if ($typeBienCode !== '') {
        $resolved   = bien_type_resolve($pdo, $typeBienCode);
        $idBienType = $resolved['id_bien_type'];
        $idTypeBien = $resolved['id_type_bien'];
    }

    // ─── 3. Récupération du user (pour initiales dans la ref) ──
    $user = ['nom' => (string)($_SESSION['nom'] ?? ''), 'prenom' => (string)($_SESSION['prenom'] ?? '')];
    if (empty($user['nom']) && $userId > 0) {
        $stmtU = $pdo->prepare("SELECT nom, prenom FROM users WHERE id = ? LIMIT 1");
        $stmtU->execute([$userId]);
        $u = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($u) $user = ['nom' => (string)$u['nom'], 'prenom' => (string)$u['prenom']];
    }

    // ─── 4. Génération de la référence bien ─────────────────────
    $ville = $str('ville');
    $refBien = ref_generate_bien($pdo, [
        'id_agence'       => $agenceId,
        'type_bien_code'  => $typeBienCode,
        'ville'           => $ville,
        'user'            => $user,
    ]);

    // ─── 5. Slug SEO depuis reference + type + ville ────────────
    $slugBase = preg_replace('/[^a-z0-9]+/', '-',
        strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE',
            ($typeBienCode ?: 'bien') . '-' . $ville . '-' . $refBien
        ))) ?: 'bien';
    $slug = trim($slugBase, '-');

    // ─── 6. Création bien ───────────────────────────────────────
    // Note : biens n'a PAS de colonne id_user native, on utilise id_user_actuel
    // (ajoutée par la migration 20260418_express_flow) pour tracer le commercial courant
    $stmtB = $pdo->prepare("
        INSERT INTO biens (
            reference_bien, slug, statut_bien,
            id_societe, id_agence, id_user_actuel, id_proprietaire, id_type_bien, id_bien_type,
            adresse_1, adresse_2, code_postal, ville, latitude, longitude,
            etage, lot_principal,
            surface_habitable, nb_pieces, nb_chambres, nb_salles_bain, nb_wc,
            annee_construction,
            dpe_classe, ges_classe, dpe_valeur, ges_valeur, dpe_date_realisation, dpe_vierge,
            chauffage_type, chauffage_energie, eau_chaude_type,
            prix_vente_estime, loyer_hc,
            date_creation, date_modification
        ) VALUES (
            :ref, :slug, 'brouillon',
            :soc, :age, :usr, :pro, :tb, :tb_new,
            :a1, :a2, :cp, :v, :lat, :lng,
            :etg, :lot,
            :sh, :np, :nc, :nsb, :nwc,
            :annee,
            :dpeC, :gesC, :dpeV, :gesV, :dpeDate, :dpeVierge,
            :chT, :chE, :ecT,
            :pxV, :loyer,
            NOW(), NOW()
        )
    ");
    $stmtB->execute([
        ':ref'       => $refBien,
        ':slug'      => $slug,
        ':soc'       => $societeId ?: null,
        ':age'       => $agenceId,
        ':usr'       => $userId ?: null,
        ':pro'       => $proprioId ?: null,
        ':tb'        => $idTypeBien,
        ':tb_new'    => $idBienType,
        ':a1'        => $str('adresse_1') ?: null,
        ':a2'        => $str('adresse_2') ?: null,
        ':cp'        => $str('code_postal') ?: null,
        ':v'         => $ville ?: null,
        ':lat'       => $flt('latitude'),
        ':lng'       => $flt('longitude'),
        ':etg'       => $str('etage') ?: null,
        ':lot'       => $str('lot_principal') ?: null,
        ':sh'        => $flt('surface_habitable'),
        ':np'        => $int('nb_pieces'),
        ':nc'        => $int('nb_chambres'),
        ':nsb'       => $int('nb_salles_bain'),
        ':nwc'       => $int('nb_wc'),
        ':annee'     => $int('annee_construction'),
        ':dpeC'      => $str('dpe_classe') ?: null,
        ':gesC'      => $str('ges_classe') ?: null,
        ':dpeV'      => $flt('dpe_valeur'),
        ':gesV'      => $flt('ges_valeur'),
        ':dpeDate'   => $str('dpe_date_realisation') ?: null,
        ':dpeVierge' => ($_POST['dpe_vierge'] ?? '') !== '' ? (int)(bool)(int)$_POST['dpe_vierge'] : 0,
        ':chT'       => $str('chauffage_type') ?: null,
        ':chE'       => $str('chauffage_energie') ?: null,
        ':ecT'       => $str('eau_chaude_type') ?: null,
        ':pxV'       => $flt('prix_vente_estime'),
        ':loyer'     => $flt('loyer_hc'),
    ]);
    $idBien = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'             => true,
        'id_bien'        => $idBien,
        'reference_bien' => $refBien,
        'slug'           => $slug,
        'id_proprietaire'=> $proprioId ?: null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
