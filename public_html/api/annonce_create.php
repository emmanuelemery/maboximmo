<?php
// api/annonce_create.php — Créer une annonce brouillon pour un bien
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$userId    = function_exists('current_user_id') ? (int)current_user_id() : 0;
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;

$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
if ($bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_bien manquant']));
}

// Verification scope societe
try {
    $st = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ?");
    $st->execute([$bienId]);
    $bienSoc = (int)($st->fetchColumn() ?: 0);
    if (!$isSuperAdmin && $societeId > 0 && $bienSoc !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Bien hors de votre société']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérification bien']));
}

// Refus si bien pas encore validé (flow 2026-04-22 : validation bien obligatoire)
try {
    $stS = $pdo->prepare("SELECT statut_bien FROM biens WHERE id = ? LIMIT 1");
    $stS->execute([$bienId]);
    $statutBien = (string)($stS->fetchColumn() ?: 'brouillon');
    if ($statutBien !== 'actif') {
        http_response_code(403);
        exit(json_encode([
            'ok' => false,
            'error' => 'Le bien doit être validé avant de créer une annonce',
            'statut_bien' => $statutBien,
        ]));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérif statut bien']));
}

// Si une annonce NON archivée existe déjà pour ce bien, la retourner.
// Règle 2026-04-22 : on ne reprend JAMAIS une annonce archivée — on en crée une
// nouvelle à chaque demande (vente 2024 archivée → location 2026 = nouvelle annonce).
try {
    $st = $pdo->prepare("
        SELECT id FROM annonces
        WHERE id_bien = ? AND (etat_publication IS NULL OR etat_publication NOT IN ('archivee','archived'))
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$bienId]);
    $existing = (int)($st->fetchColumn() ?: 0);
    if ($existing > 0) {
        exit(json_encode(['ok' => true, 'id' => $existing, 'existed' => true]));
    }

    // Hérite id_agence + id_user (commercial) du bien si renseignés, sinon fallback session
    $stB = $pdo->prepare("SELECT id_agence, id_user_actuel, id_societe FROM biens WHERE id = ?");
    $stB->execute([$bienId]);
    $bienRow = $stB->fetch(PDO::FETCH_ASSOC) ?: [];
    $finalSoc    = (int)($bienRow['id_societe']     ?? 0) ?: ($societeId ?: 0);
    $finalAgence = (int)($bienRow['id_agence']      ?? 0) ?: ($agenceId  ?: 0);
    $finalUser   = (int)($bienRow['id_user_actuel'] ?? 0) ?: ($userId    ?: 0);

    // URL tarifs publics par défaut : page barème de la société.
    // Obligatoire sur tous les supports publics (loi Hoguet) — on pré-remplit
    // pour qu'aucune annonce ne parte sans le lien.
    $urlTarifs = null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && $finalSoc > 0) {
        $base = $scheme . '://' . $host;
        $path = function_exists('app_url') ? app_url('/tarifs.php') : '/tarifs.php';
        $urlTarifs = $base . $path . '?societe=' . $finalSoc;
    }

    $st = $pdo->prepare("
        INSERT INTO annonces (id_bien, id_societe, id_agence, id_user, etat_publication,
                              visible_portails, visible_maboximmo, visible_site_perso,
                              url_tarifs_publics,
                              date_creation, date_modification)
        VALUES (:bien, :soc, :ag, :usr, 'brouillon', 0, 0, 0,
                :url_tarifs,
                NOW(), NOW())
    ");
    $st->execute([
        ':bien' => $bienId,
        ':soc'  => $finalSoc    ?: null,
        ':ag'   => $finalAgence ?: null,
        ':usr'  => $finalUser   ?: null,
        ':url_tarifs' => $urlTarifs,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Auto-lien : toutes les photos actuelles du bien sont par défaut incluses dans l'annonce.
    // Le tri d'exclusion se fait ensuite depuis la Card 2 Photos de l'annonce.
    $linked = 0;
    try {
        $stP = $pdo->prepare("SELECT id FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
        $stP->execute([$bienId]);
        $photoIds = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($photoIds) {
            $ins = $pdo->prepare("INSERT INTO annonces_photos (id_annonce, id_biens_photo, ordre) VALUES (?, ?, ?)");
            foreach ($photoIds as $k => $pid) {
                $ins->execute([$id, $pid, $k]);
                $linked++;
            }
        }
    } catch (Throwable $e) {
        error_log('[annonce_create] auto-lien photos: ' . $e->getMessage());
    }

    // ── Cascade de recalcul initial (2026-04-22) ──
    // À la création, on initialise immédiatement les champs dérivés depuis
    // le bien (surface, CP, charges, enc_loyer_max…) :
    //   - zone_tendue sur biens (depuis CP via base_zones_tendues)
    //   - loyer_reference_majore = surface × enc_loyer_max (si calculable)
    //   - honoraires_location_bail = surface × tarif zone (plafond ALUR)
    //   - honoraires_etat_des_lieux = surface × 3
    //   - depot_garantie = 1 mois de loyer HC référence
    //   - loyer_cc = loyer HC + charges
    // Ainsi l'utilisateur arrive sur Card 1 avec les montants déjà pré-remplis.
    try {
        require_once dirname(__DIR__) . '/inc/honoraires_helper.php';
        loyer_majore_recalc_save($pdo, $id);
        loyer_hc_recalc_save($pdo, $id);
        depot_garantie_recalc_save($pdo, $id);
        honoraires_recalc_save($pdo, $id, false);
        loyer_cc_recalc_save($pdo, $id);
    } catch (Throwable $e) {
        error_log('[annonce_create] cascade recalc: ' . $e->getMessage());
    }

    exit(json_encode(['ok' => true, 'id' => $id, 'existed' => false, 'photos_linked' => $linked]));
} catch (Throwable $e) {
    error_log('[annonce_create] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
