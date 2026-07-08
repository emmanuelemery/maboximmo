<?php
/**
 * api/bien_add_mandat.php
 *
 * Ajoute un mandat (gestion / location / vente) à un bien existant.
 * Plusieurs mandats actifs cumulables par bien (Sprint MANDATS 2026-05-25).
 *
 * POST JSON :
 *   { "bien_id": 726, "type_mandat": "vente" }
 *
 * Auth : user authentifié + scope check societe (sauf admin).
 * Empêche le doublon : un seul mandat ACTIF par type par bien.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_missions.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']    ?? 0);
$userSocId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = ((int)($_SESSION['id_role'] ?? 0) === 1);

$body       = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$bienId     = (int)($body['bien_id'] ?? 0);
$typeMandat = strtolower(trim((string)($body['type_mandat'] ?? '')));

if ($bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'bien_id requis']));
}

$typesAutorises = ['gestion', 'gerance', 'location', 'vente', 'transaction'];
if (!in_array($typeMandat, $typesAutorises, true)) {
    exit(json_encode(['ok' => false, 'error' => 'type_mandat invalide (gestion|location|vente)']));
}

// Normalisation : "gerance" = ancien terme legacy, on garde mais aligne sur "gestion"
$typeNorm = $typeMandat === 'gerance' ? 'gestion' : ($typeMandat === 'transaction' ? 'vente' : $typeMandat);

// Lecture bien + scope check
$st = $pdo->prepare("SELECT id, id_societe, id_agence, id_proprietaire, reference_bien
                     FROM biens WHERE id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
}
if (!$isAdmin && !empty($bien['id_societe']) && (int)$bien['id_societe'] !== $userSocId) {
    // Exception BAILLEUR (rôle 9/10) : autorisé si le bien appartient à un de SES
    // propriétaires (user_proprietaires) — les comptes bailleurs n'ont pas de société.
    $okBailleur = false;
    if (in_array((int)($_SESSION['id_role'] ?? 0), [9, 10], true) && (int)($bien['id_proprietaire'] ?? 0) > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user = ? AND id_proprietaire = ? LIMIT 1");
        $chk->execute([$userId, (int)$bien['id_proprietaire']]);
        $okBailleur = (bool)$chk->fetchColumn();
    }
    if (!$okBailleur) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Bien hors de votre périmètre']));
    }
}

// Numéro AUTO déterministe (utilisé seulement à la 1ʳᵉ création ; un mandat
// réactivé garde le sien).
$letter = match ($typeNorm) {
    'gestion'  => 'G',
    'location' => 'L',
    'vente'    => 'V',
    default    => 'X',
};
$numeroMandat = 'AUTO-' . $letter . '-' . date('Y') . '-' . str_pad((string)$bienId, 5, '0', STR_PAD_LEFT);

$annonceCreated = null;
try {
    $pdo->beginTransaction();

    // CHEMIN DE CRÉATION UNIQUE = ensure_mandat() : idempotent (renvoie le mandat
    // en cours s'il existe) ET réactive un mandat clôturé du même type (re-listing
    // même année → pas de doublon ni de collision de numéro). Mandat en 'projet',
    // jamais signé, sans date contractuelle.
    $mandatId = ensure_mandat($pdo, $bienId, $typeNorm, [
        'numero'          => $numeroMandat,
        'id_agence'       => $bien['id_agence'] ?: null,
        'id_proprietaire' => $bien['id_proprietaire'] ?: null,
        'id_user'         => $userId ?: null,
    ]);
    // Numéro réel du mandat (existant réactivé ou nouvellement créé)
    $stNum = $pdo->prepare("SELECT numero_mandat FROM mandats WHERE id = ?");
    $stNum->execute([$mandatId]);
    $numeroMandat = (string)($stNum->fetchColumn() ?: $numeroMandat);

    // ─── Upsert ANNONCE du canal correspondant (Sprint MANDATS Ubiflow multi-canal) ───
    // Mandat VENTE → upsert annonce type_transaction='vente'
    // Mandat LOCATION → upsert annonce type_transaction='location'
    // Mandat GESTION → pas d'annonce commerciale (gestion = administratif)
    if (in_array($typeNorm, ['vente', 'location'], true)) {
        // Re-mise en marché : on efface le marqueur de retrait résiduel d'un cycle précédent.
        $pdo->prepare("UPDATE biens SET date_retrait_commercialisation = NULL WHERE id = ?")->execute([$bienId]);

        $stAnn = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? AND type_transaction = ? LIMIT 1");
        $stAnn->execute([$bienId, $typeNorm]);
        $annId = (int)$stAnn->fetchColumn();
        if ($annId === 0) {
            $pdo->prepare("INSERT INTO annonces
                (id_bien, id_societe, id_agence, id_user, type_transaction,
                 etat_publication, date_creation, date_modification)
                VALUES (?, ?, ?, ?, ?, 'brouillon', NOW(), NOW())")
                ->execute([
                    $bienId,
                    $bien['id_societe'] ?: null,
                    $bien['id_agence']  ?: null,
                    $userId ?: null,
                    $typeNorm,
                ]);
            $annonceCreated = (int)$pdo->lastInsertId();
        } else {
            $annonceCreated = $annId; // existait déjà
        }

        // ── AUTO-REMPLISSAGE annonce VENTE (best-effort, ne touche que les champs vides) ──
        if ($typeNorm === 'vente' && $annonceCreated > 0) {
            try {
                require_once __DIR__ . '/../inc/honoraires_helper.php';
                // 1) Ancien loyer ALUR ← bail actif du bien.
                $qb = $pdo->prepare("SELECT loyer_mensuel_hc, charges_mensuelles, date_fin, date_prise_effet
                                       FROM bien_baux WHERE id_bien=? AND statut IN ('actif','signe') ORDER BY id DESC LIMIT 1");
                $qb->execute([$bienId]); $bAct = $qb->fetch(PDO::FETCH_ASSOC);
                if ($bAct) {
                    $pdo->prepare("UPDATE annonces SET
                            ancien_loyer_montant = COALESCE(NULLIF(ancien_loyer_montant,0), ?),
                            ancien_loyer_charges = COALESCE(NULLIF(ancien_loyer_charges,0), ?),
                            ancien_locataire_date_sortie = COALESCE(ancien_locataire_date_sortie, ?),
                            ancien_loyer_date_revision   = COALESCE(ancien_loyer_date_revision, ?),
                            ancien_loyer_communique = 1, date_modification = NOW()
                          WHERE id=?")->execute([
                        (float)($bAct['loyer_mensuel_hc'] ?? 0) ?: null,
                        (float)($bAct['charges_mensuelles'] ?? 0) ?: null,
                        $bAct['date_fin'] ?: null,
                        $bAct['date_prise_effet'] ?: null,
                        $annonceCreated,
                    ]);
                }
                // 2) Prix ← patrimoine actif (prix de vente courant), si l'annonce n'a pas de prix.
                $qp = $pdo->prepare("SELECT montant FROM bien_prix
                                       WHERE id_bien=? AND type_valeur='prix_vente' AND is_courant=1 AND montant>0
                                       ORDER BY id DESC LIMIT 1");
                $qp->execute([$bienId]); $prixPat = (float)$qp->fetchColumn();
                if ($prixPat > 0) {
                    $pdo->prepare("UPDATE annonces SET prix = COALESCE(NULLIF(prix,0), ?), date_modification=NOW() WHERE id=?")
                        ->execute([$prixPat, $annonceCreated]);
                }
                // 3) Honoraires ← barème vente (agence → société → modèle MBI), si vides.
                $qa = $pdo->prepare("SELECT prix, honoraires FROM annonces WHERE id=?"); $qa->execute([$annonceCreated]);
                $ann = $qa->fetch(PDO::FETCH_ASSOC) ?: [];
                $prixAnn = (float)($ann['prix'] ?? 0);
                if ($prixAnn > 0 && (float)($ann['honoraires'] ?? 0) <= 0) {
                    $h = honoraires_vente_bareme($pdo, (int)($bien['id_societe'] ?? 0) ?: null, (int)($bien['id_agence'] ?? 0), $prixAnn);
                    if ($h['montant'] > 0) {
                        $pdo->prepare("UPDATE annonces SET honoraires=?, alur_pourcentage_honoraires_ttc=?,
                                pourcentage_honoraires_vendeur=?, honoraires_charge=?,
                                honoraires_charge_acquereur=?, honoraires_charge_vendeur=?,
                                prix_net_vendeur=?, date_modification=NOW() WHERE id=?")
                            ->execute([
                                $h['montant'], $h['pct'],
                                ($h['charge']==='vendeur' ? $h['pct'] : 0),
                                $h['charge'],
                                ($h['charge']==='acquereur' ? 1 : 0),
                                ($h['charge']==='vendeur' ? 1 : 0),
                                max(0, $prixAnn - $h['montant']),
                                $annonceCreated,
                            ]);
                    }
                }
            } catch (Throwable $e) { error_log('[bien_add_mandat autofill] ' . $e->getMessage()); }
        }
    }

    // Miroir dérivé : ce chemin crée une mission (mandat) → on réaligne
    // biens.type_commercialisation depuis mandats (vente > location > NULL).
    derive_type_commercialisation($pdo, $bienId);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Transaction échouée : ' . $e->getMessage()]));
}

echo json_encode([
    'ok'             => true,
    'mandat_id'      => $mandatId,
    'bien_id'        => $bienId,
    'type_mandat'    => $typeNorm,
    'numero_mandat'  => $numeroMandat,
    'annonce_id'     => $annonceCreated,
    'message'        => 'Mandat ' . strtoupper($typeNorm) . ' créé.'
                      . ($annonceCreated ? ' Annonce ' . $typeNorm . ' #' . $annonceCreated . ' prête pour Ubiflow.' : ''),
], JSON_UNESCAPED_UNICODE);
