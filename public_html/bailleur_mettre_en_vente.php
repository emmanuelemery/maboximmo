<?php
/**
 * bailleur_mettre_en_vente.php — Relais Bailleur → Transaction
 * ------------------------------------------------------------------
 * Depuis le tableau Patrimoine actif, bascule un bien (déjà en BDD)
 * vers le module Transaction :
 *   1. biens.type_commercialisation = 'vente' (+ date_mise_en_vente, occupation)
 *   2. crée une annonce 'brouillon' si aucune n'existe (diffusion à préparer)
 *   3. calcule la checklist documentaire vente (diagnostics + copropriété)
 *      en lisant la GED centrale (ged_document_links)
 *
 * POST : id_bien, id_proprietaire
 * Réponse JSON : { ok, id_bien, id_annonce, url_bien, url_transaction, docs:{required,present,missing} }
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/bien_prix.php';
require_once __DIR__ . '/inc/bien_missions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

// ── Accès : super admin OU service bailleur ──────────────────────────
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès réservé au module Bailleur']); exit;
}

$idBien = (int)($_POST['id_bien'] ?? 0);
$idProp = (int)($_POST['id_proprietaire'] ?? 0);
if ($idBien <= 0 || $idProp <= 0) { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }

// ── Ownership : un bailleur non-SA ne peut basculer que SES propriétaires ──
if (!$isSuperAdmin) {
    $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
    $chk->execute([$userId, $idProp]);
    if (!$chk->fetchColumn()) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Propriétaire hors périmètre']); exit;
    }
}

try {
    // ── Charger le bien (+ immeuble) et vérifier la cohérence propriétaire ──
    $stmt = $pdo->prepare("
        SELECT b.id, b.id_proprietaire, b.id_societe, b.id_agence, b.id_immeuble,
               b.reference_bien, b.designation, b.ville, b.type_commercialisation,
               b.statut_bien, b.bien_en_copropriete,
               b.prix_demande_initial, b.prix_vente_estime, b.loyer_hc, b.rendement_brut,
               COALESCE(NULLIF(b.surface_carrez,0), NULLIF(b.surface_habitable,0)) AS surface,
               i.nom_immeuble, i.vendu AS imm_vendu
        FROM biens b
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        WHERE b.id = ? LIMIT 1
    ");
    $stmt->execute([$idBien]);
    $bien = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$bien) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }

    // Appartenance via la CRG (source de vérité) — biens.id_proprietaire peut être
    // NULL/désaligné (ex. FOCH #260 dont les biens restent à 2/NULL). Repli sur biens.
    if ((int)$bien['id_proprietaire'] !== $idProp) {
        $chk = $pdo->prepare("SELECT 1 FROM crg_situations_locataires c
            JOIN crg_trimestres t ON c.id_crg = t.id
            WHERE c.id_bien = ? AND t.id_proprietaire = ? LIMIT 1");
        $chk->execute([$idBien, $idProp]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['ok'=>false,'error'=>'Le bien n\'appartient pas à ce propriétaire']); exit;
        }
    }
    if ((int)$bien['imm_vendu'] === 1) {
        echo json_encode(['ok'=>false,'error'=>'Immeuble déjà vendu']); exit;
    }

    // ── Anti-doublon : un bien JUMEAU est-il déjà en vente ? ──────────────
    // JUMEAU = même immeuble + même surface (≈) → très probablement le MÊME lot
    // physique représenté par 2 enregistrements (cas des 2 jeux de biens : import
    // CRG vs bien initial). On NE filtre PAS sur id_proprietaire : un bien CRG a
    // souvent id_proprietaire = NULL, ce qui faisait rater la détection.
    // Un même lot peut aussi être détecté par sa référence cadastrale. On renvoie
    // un signal "duplicate" → pop-up ; l'utilisateur décide (force=1 si ce sont
    // réellement 2 lots distincts de surface identique).
    $force = (int)($_POST['force'] ?? 0) === 1;
    if (!$force) {
        $surfRef = (float)($bien['surface'] ?? 0);
        // a) jumeau par immeuble + surface
        $twin = null;
        if ($surfRef > 0 && (int)$bien['id_immeuble'] > 0) {
            $stTwin = $pdo->prepare("SELECT id, reference_bien FROM biens
                WHERE id <> :id AND id_immeuble = :imm AND type_commercialisation = 'vente'
                  AND ABS(COALESCE(NULLIF(surface_carrez,0), NULLIF(surface_habitable,0), 0) - :surf) < 0.5
                ORDER BY id ASC LIMIT 1");
            $stTwin->execute([':id'=>$idBien, ':imm'=>(int)$bien['id_immeuble'], ':surf'=>$surfRef]);
            $twin = $stTwin->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        if ($twin) {
            echo json_encode([
                'ok'        => false,
                'duplicate' => true,
                'message'   => "Un bien identique semble déjà en vente (réf {$twin['reference_bien']}) : même immeuble et même surface. Les jumeaux sont interdits — vérifiez avant de forcer.",
                'twin_ref'  => $twin['reference_bien'],
                'twin_bien_id'    => (int)$twin['id'],
                // Affiche TOUS les biens en vente du propriétaire (pas seulement le jumeau)
                'url_transaction' => app_url('/transaction_index.php?proprietaire_id=' . ($idProp ?: (int)$bien['id_proprietaire']) . '&type=vente'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ── Occupation : locataire présent au dernier CRG du propriétaire ? ──
    $occ = $pdo->prepare("
        SELECT MAX(crg.loyer_appele) AS loyer
        FROM crg_situations_locataires crg
        JOIN crg_trimestres ct ON crg.id_crg = ct.id
        WHERE crg.id_bien = ? AND ct.id_proprietaire = ?
          AND ct.parse_statut = 'ok'
          AND (ct.annee, ct.trimestre) = (
              SELECT ct2.annee, ct2.trimestre FROM crg_trimestres ct2
              WHERE ct2.id_proprietaire = ct.id_proprietaire AND ct2.parse_statut='ok'
              ORDER BY ct2.annee DESC, ct2.trimestre DESC LIMIT 1)
    ");
    $occ->execute([$idBien, $idProp]);
    $loueActif    = ((float)($occ->fetchColumn() ?: 0)) > 0;
    $occupation   = $loueActif ? 'loue' : 'libre';

    // ── Loyer mensuel HC repris depuis le BAIL (source de vérité), repli CRG ──
    $stL = $pdo->prepare("SELECT loyer_mensuel_hc FROM bien_baux
        WHERE id_bien=? AND statut='actif' AND loyer_mensuel_hc>0
        ORDER BY id DESC LIMIT 1");
    $stL->execute([$idBien]);
    $loyerMois = (float)($stL->fetchColumn() ?: 0);
    if ($loyerMois <= 0) {
        // Repli : loyer appelé trimestriel du dernier CRG / 3
        $stC = $pdo->prepare("SELECT MAX(c.loyer_appele) FROM crg_situations_locataires c
            JOIN crg_trimestres t ON t.id=c.id_crg
            WHERE c.id_bien=? AND t.id_proprietaire=? AND t.parse_statut='ok'");
        $stC->execute([$idBien, $idProp]);
        $loyerMois = round(((float)($stC->fetchColumn() ?: 0)) / 3, 2);
    }
    $loyerAn = round($loyerMois * 12, 2);

    // Prix : prix demandé (simulateur) sinon estimation
    $prixVente = (float)($bien['prix_demande_initial'] ?: $bien['prix_vente_estime'] ?: 0);
    // Rendement brut recalculé si absent et données disponibles
    $rendement = (float)($bien['rendement_brut'] ?: 0);
    if ($rendement <= 0 && $prixVente > 0 && $loyerAn > 0) {
        $rendement = round($loyerAn / $prixVente * 100, 2);
    }

    $pdo->beginTransaction();

    // ── 1. Mission canonique : garantir un mandat VENTE (source faisant autorité) ──
    //     On N'ÉCRIT PLUS type_commercialisation en direct : c'est désormais un
    //     miroir dérivé de mandats (cf. derive_type_commercialisation ci-dessous).
    ensure_mandat_vente($pdo, $idBien, [
        'id_agence'       => (int)($bien['id_agence'] ?: 0) ?: null,
        'id_proprietaire' => $idProp ?: null,
        'id_user'         => $userId ?: null,
    ]);

    // ── 1bis. Reprise financière (faits/estimations sur le BIEN, hors mission) ──
    $pdo->prepare("
        UPDATE biens
        SET occupation_bien    = :occ,
            loyer_hc           = COALESCE(NULLIF(loyer_hc,0), :loyer),
            rendement_brut     = COALESCE(NULLIF(rendement_brut,0), :rdt),
            date_mise_en_vente = IFNULL(date_mise_en_vente, CURDATE()),
            date_modification  = NOW()
        WHERE id = :id
    ")->execute([
        ':occ'   => $occupation,
        ':loyer' => $loyerMois > 0 ? $loyerMois : null,
        ':rdt'   => $rendement > 0 ? $rendement : null,
        ':id'    => $idBien,
    ]);

    // ── 1ter. Miroir dérivé : recalcule type_commercialisation depuis les mandats ──
    derive_type_commercialisation($pdo, $idBien);

    // ── 2. Annonce brouillon (1 par bien si aucune active) ──
    $stA = $pdo->prepare("SELECT id FROM annonces WHERE id_bien=? AND type_transaction='vente'
                          AND statut NOT IN ('supprime','archive') LIMIT 1");
    $stA->execute([$idBien]);
    $idAnnonce = (int)($stA->fetchColumn() ?: 0);

    $surface = (float)($bien['surface'] ?: 0);
    if ($idAnnonce === 0) {
        $titre = trim((string)($bien['designation'] ?: $bien['reference_bien'] ?: ('Bien #'.$idBien)));
        if ($bien['ville']) $titre .= ' — ' . $bien['ville'];
        $insA = $pdo->prepare("
            INSERT INTO annonces
              (id_bien, id_societe, id_agence, id_user, type_transaction,
               statut, etat_publication, source_annonce, langue, titre,
               prix, loyer, surface_ponderee, date_creation)
            VALUES
              (:id_bien, :soc, :age, :usr, 'vente',
               'brouillon', 'brouillon', 'bailleur', 'fr', :titre,
               :prix, :loyer, :surf, NOW())
        ");
        $insA->execute([
            ':id_bien' => $idBien,
            ':soc'     => $bien['id_societe'] ?: null,
            ':age'     => $bien['id_agence']  ?: null,
            ':usr'     => $userId ?: null,
            ':titre'   => $titre,
            ':prix'    => $prixVente > 0 ? $prixVente : null,
            ':loyer'   => $loyerMois > 0 ? $loyerMois : null,
            ':surf'    => $surface  > 0 ? $surface  : null,
        ]);
        $idAnnonce = (int)$pdo->lastInsertId();
    } else {
        // Annonce existante : compléter prix/loyer/surface si vides (ne pas écraser une saisie)
        $pdo->prepare("
            UPDATE annonces
            SET prix             = COALESCE(NULLIF(prix,0), :prix),
                loyer            = COALESCE(NULLIF(loyer,0), :loyer),
                surface_ponderee = COALESCE(NULLIF(surface_ponderee,0), :surf)
            WHERE id = :id
        ")->execute([
            ':prix'  => $prixVente > 0 ? $prixVente : null,
            ':loyer' => $loyerMois > 0 ? $loyerMois : null,
            ':surf'  => $surface  > 0 ? $surface  : null,
            ':id'    => $idAnnonce,
        ]);
    }

    $pdo->commit();

    // ── 2bis. Historisation prix/loyer courants (bien_prix) + miroirs ──
    // L'annonce existe désormais : le helper historise et resynchronise biens + annonce.
    try {
        if ($prixVente > 0) bien_prix_valider($pdo, $idBien, 'prix_vente', (float)$prixVente, 'bascule', $userId);
        if ($loyerMois > 0) bien_prix_valider($pdo, $idBien, 'loyer',      (float)$loyerMois, 'bascule', $userId);
    } catch (Throwable $e) { /* non bloquant */ }

    // ── 3. Checklist documentaire vente ──
    $docsByType = [];
    try {
        $docs = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['status'=>'active','limit'=>100]);
        foreach ($docs as $d) {
            $t = strtoupper(trim((string)($d['document_type'] ?? '')));
            if ($t !== '') $docsByType[$t] = ($docsByType[$t] ?? 0) + 1;
        }
    } catch (Throwable $e) {}

    // ── Checklist VENTE pilotée par le GLOSSAIRE (ged_codes_glossaire) ──
    // Le glossaire est la SOURCE DE VÉRITÉ : on ne définit ici que la liste
    // des CODES requis pour une vente ; le libellé affiché est résolu depuis
    // le glossaire (catégorie type_document). Le test de présence se fait sur
    // le code glossaire, insensible à la casse, contre ged_documents.document_type.
    require_once __DIR__ . '/inc/ged_glossary.php';

    // Codes attendus pour une vente (doivent exister dans le glossaire type_document).
    // copro=true → pièces requises uniquement si le bien est en copropriété.
    $checklistCodes = [
        ['code'=>'DPE',             'copro'=>false],
        ['code'=>'ERP',             'copro'=>false],
        ['code'=>'PLOMB',           'copro'=>false],
        ['code'=>'AMIANTE',         'copro'=>false],
        ['code'=>'GAZ',             'copro'=>false],
        ['code'=>'ELEC',            'copro'=>false],
        ['code'=>'CARREZ',          'copro'=>false],
        ['code'=>'ACTE',            'copro'=>false],
        ['code'=>'PHOTO',           'copro'=>false],
        ['code'=>'PV',              'copro'=>true],   // PV d'assemblée générale
        ['code'=>'REGLEMENT_COPRO', 'copro'=>true],
        ['code'=>'CARNET_ENTRETIEN','copro'=>true],
    ];

    // Résout le libellé depuis le glossaire (fallback = le code lui-même).
    $glossLabel = static function (string $code): string {
        if (function_exists('ged_glossary_get_by_code')) {
            try {
                $g = ged_glossary_get_by_code('type_document', $code);
                if ($g && !empty($g['label'])) return (string)$g['label'];
            } catch (Throwable $e) {}
        }
        return $code;
    };

    $isCopro = (int)$bien['bien_en_copropriete'] === 1;
    $present = []; $missing = [];
    foreach ($checklistCodes as $p) {
        if ($p['copro'] && !$isCopro) continue;
        $code  = strtoupper($p['code']);
        $label = $glossLabel($p['code']);
        if (isset($docsByType[$code])) { $present[] = $label; }
        else                           { $missing[] = $label; }
    }

    echo json_encode([
        'ok'              => true,
        'id_bien'         => $idBien,
        'id_annonce'      => $idAnnonce,
        'occupation'      => $occupation,
        'copropriete'     => $isCopro,
        'url_bien'        => app_url('/bien_360.php?id=' . $idBien),
        // Après mise en vente : afficher TOUS les biens en vente du propriétaire
        'url_transaction' => app_url('/transaction_index.php?proprietaire_id=' . ($idProp ?: (int)$bien['id_proprietaire']) . '&type=vente'),
        'docs'            => [
            'present' => $present,
            'missing' => $missing,
            'total'   => count($present) + count($missing),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
