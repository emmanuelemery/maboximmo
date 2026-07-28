<?php
declare(strict_types=1);

/**
 * Liste les biens actuellement diffusés pour une agence donnée.
 * Retourne pour chaque bien : référence, type, adresse, canaux activés,
 * date de 1ère diffusion, commercial (initiales + nom complet).
 *
 * GET : id_agence
 * Réponse JSON : { ok:true, biens: [...] } ou { ok:false, error:... }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$idAgence  = isset($_GET['id_agence']) && ctype_digit((string)$_GET['id_agence']) ? (int)$_GET['id_agence'] : 0;
if ($idAgence <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_agence manquant']));
}

try {
    // Filtre cohérent avec le compteur "X/15 diffusées" de bien_detail.php :
    // toutes les annonces actives portails de l'agence, triées par date_modification DESC
    // (= ordre supposé de priorisation LBC : les "remontées" récemment passent en tête).
    $sql = "
        SELECT
            b.id AS id_bien,
            b.reference_bien,
            b.code_postal,
            b.ville,
            b.adresse_1,
            COALESCE(btm.libelle, tb.libelle) AS type_bien,
            a.id AS id_annonce,
            a.titre AS annonce_titre,
            a.type_transaction,
            a.visible_maboximmo,
            a.visible_site_perso,
            a.visible_portails,
            a.date_mise_en_ligne,
            a.date_modification AS annonce_date_modification,
            u.id AS user_id,
            u.prenom AS user_prenom,
            u.nom AS user_nom
        FROM annonces a
        JOIN biens b ON b.id = a.id_bien
        LEFT JOIN bien_types btm ON btm.id = b.id_bien_type
        LEFT JOIN types_bien tb  ON tb.id  = b.id_type_bien
        LEFT JOIN users u ON u.id = a.id_user
        WHERE a.id_agence = ?
          AND a.visible_portails = 1
          AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archive','archivee','supprime'))
        ORDER BY a.date_modification DESC, a.id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$idAgence]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $biens = [];
    foreach ($rows as $r) {
        $prenom = trim((string)($r['user_prenom'] ?? ''));
        $nom    = trim((string)($r['user_nom']    ?? ''));
        $init   = strtoupper(($prenom !== '' ? mb_substr($prenom, 0, 1) : '') . ($nom !== '' ? mb_substr($nom, 0, 1) : ''));
        $datePremDiff = '';
        if (!empty($r['date_mise_en_ligne'])) {
            try {
                $datePremDiff = (new DateTime((string)$r['date_mise_en_ligne']))->format('d/m/Y H:i');
            } catch (Throwable) { $datePremDiff = (string)$r['date_mise_en_ligne']; }
        }
        // Format date_modification (utilisée pour le tri + affichage rang LBC)
        $dateModifDisplay = '';
        if (!empty($r['annonce_date_modification'])) {
            try {
                $dateModifDisplay = (new DateTime((string)$r['annonce_date_modification']))->format('d/m/Y H:i');
            } catch (Throwable) { $dateModifDisplay = (string)$r['annonce_date_modification']; }
        }
        $biens[] = [
            'id_bien'           => (int)$r['id_bien'],
            'id_annonce'        => (int)$r['id_annonce'],
            'reference_bien'    => (string)($r['reference_bien'] ?? ''),
            'annonce_titre'     => (string)($r['annonce_titre'] ?? ''),
            'type_transaction'  => (string)($r['type_transaction'] ?? ''),
            'type_bien'         => (string)($r['type_bien'] ?? ''),
            'code_postal'       => (string)($r['code_postal'] ?? ''),
            'ville'             => (string)($r['ville'] ?? ''),
            'visible_maboximmo' => (int)($r['visible_maboximmo'] ?? 0) === 1,
            'visible_site_perso'=> (int)($r['visible_site_perso'] ?? 0) === 1,
            'visible_portails'  => (int)($r['visible_portails'] ?? 0) === 1,
            'date_premiere_diff'=> $datePremDiff,
            'date_modif_display'=> $dateModifDisplay,
            'commercial_init'   => $init,
            'commercial_full'   => trim($prenom . ' ' . $nom),
        ];
    }

    // Limite LBC par flux (cohérent avec $UBIFLOW_LIMIT_PER_FLUX de bien_detail.php).
    // C'est cette limite qui définit "publié" (rang ≤ limite) vs "en attente" (rang > limite).
    $LBC_LIMIT = 15;

    exit(json_encode([
        'ok'    => true,
        'count' => count($biens),
        'limit' => $LBC_LIMIT,
        'biens' => $biens,
    ], JSON_UNESCAPED_UNICODE));

} catch (Throwable $e) {
    error_log('[agence_biens_diffuses] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
