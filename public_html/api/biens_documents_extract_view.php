<?php
// api/biens_documents_extract_view.php — Renvoie le dernier résultat IA stocké
// pour un document GED (ou la metadata "extra" si pas d'IA enregistrée).
//
// Cas d'usage : bouton 👁️ "Voir l'extraction" sur la liste des documents du bien.
// Pas de coût IA — on lit juste ce qui est déjà en BDD.
//
// POST { id_doc, csrf_token }
//   id_doc : "ged_<id>" (cas nominal) ou "<id>" legacy biens_documents (pas d'extraction historisée).
//
// Retour :
//   { ok:true, doc_type:"BAIL", fields:{…}, ia_last:{at,...}, dpe_diag:{…}|null, bail:{…}|null }
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$roleId    = (int)($_SESSION['id_role']    ?? 0);

$raw = isset($_POST['id_doc']) ? trim((string)$_POST['id_doc']) : '';
if ($raw === '') exit(json_encode(['ok' => false, 'error' => 'id_doc manquant']));

$useGed = false;
if (str_starts_with($raw, 'ged_')) { $useGed = true; $raw = substr($raw, 4); }
if (!ctype_digit($raw)) exit(json_encode(['ok' => false, 'error' => 'id_doc invalide']));
$idDoc = (int)$raw;

try {
    if ($useGed) {
        $st = $pdo->prepare("SELECT id, name_display, name_file, document_type, metadata,
                                    societe_id, tenant_id, created_at
                               FROM ged_documents
                              WHERE id = ? LIMIT 1");
        $st->execute([$idDoc]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document GED introuvable']));

        $docSoc = (int)($row['societe_id'] ?? 0);
        if ($roleId !== 1 && $societeId > 0 && $docSoc > 0 && $docSoc !== $societeId) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
        }

        $meta  = json_decode((string)$row['metadata'], true) ?: [];
        $extra = $meta['extra'] ?? [];
        // Cherche le résultat IA — soit le tout récent (ia_result_last), soit l'historique
        $iaLast = $extra['ia_result_last'] ?? null;
        $fields = is_array($iaLast['fields'] ?? null) ? $iaLast['fields'] : [];
        $resume = (string)($extra['resume_ia'] ?? $iaLast['resume'] ?? '');

        // Liens vers les tables structurées (bail / DPE) si dispo
        $bienId = null;
        try {
            $stL = $pdo->prepare("SELECT entity_id FROM ged_document_links
                                   WHERE document_id = ? AND entity_type = 'BIEN' LIMIT 1");
            $stL->execute([$idDoc]);
            $bienId = (int)($stL->fetchColumn() ?: 0) ?: null;
        } catch (Throwable $e) {}

        $linkedDpe = null;
        $linkedBail = null;
        if ($bienId) {
            try {
                $stD = $pdo->prepare("SELECT id, dpe_classe, ges_classe, consommation_energie,
                                             emission_ges, date_diagnostic, type_bien_detecte,
                                             adresse_detectee, code_postal_detecte, ville_detectee,
                                             surface_habitable_detectee, nb_pieces_detecte,
                                             extraction_method, extraction_score, extraction_date
                                        FROM dpe_diags
                                       WHERE id_bien = ? ORDER BY est_diag_principal DESC, id DESC LIMIT 1");
                $stD->execute([$bienId]);
                $linkedDpe = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
            try {
                $stB = $pdo->prepare("SELECT id, bail_nature, loyer_mensuel_hc, charges_mensuelles,
                                             date_prise_effet, date_fin, locataire_nom, locataire_prenom,
                                             indice_type, indice_trimestre, indice_valeur, depot_garantie
                                        FROM bien_baux WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
                $stB->execute([$bienId]);
                $linkedBail = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }

        exit(json_encode([
            'ok'           => true,
            'id_doc'       => 'ged_' . $idDoc,
            'name_display' => (string)$row['name_display'],
            'document_type'=> (string)$row['document_type'],
            'created_at'   => (string)$row['created_at'],
            'fields'       => $fields,
            'resume'       => $resume,
            'ia_last'      => $iaLast,
            'metadata_extra' => $extra,
            'linked_dpe'   => $linkedDpe,
            'linked_bail'  => $linkedBail,
        ], JSON_UNESCAPED_UNICODE));
    }

    // Legacy biens_documents : pas d'extraction historisée
    $st = $pdo->prepare("SELECT bd.id, bd.id_bien, bd.url_fichier, bd.nom_original, bd.type_document,
                                b.id_societe
                           FROM biens_documents bd
                           JOIN biens b ON b.id = bd.id_bien
                          WHERE bd.id = ? LIMIT 1");
    $st->execute([$idDoc]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document introuvable']));
    if ($roleId !== 1 && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }
    exit(json_encode([
        'ok'           => true,
        'id_doc'       => $idDoc,
        'name_display' => (string)$row['nom_original'],
        'document_type'=> (string)$row['type_document'],
        'fields'       => [],
        'resume'       => '',
        'ia_last'      => null,
        'metadata_extra' => null,
        'message'      => 'Document legacy biens_documents — aucune extraction IA stockée. Cliquez sur 🔄 Re-analyser pour générer.',
    ], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    error_log('[biens_documents_extract_view] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
