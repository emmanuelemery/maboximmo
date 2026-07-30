<?php
/**
 * api/bail_habitation_preview.php — Aperçu HTML LIVE du bail habitation (loi 89-462), à partir
 * des valeurs EN COURS D'ÉDITION (non enregistrées). Même rendu que le PDF (bail_habitation_corps).
 *
 * POST JSON : { bail_id?, bien_id?, ...champs bien_baux (noms de colonnes)... }
 * Réponse : { ok, html }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bail_habitation_pdf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$in  = json_decode((string)file_get_contents('php://input'), true) ?: [];
$bailId = (int)($in['bail_id'] ?? 0);
$bienId = (int)($in['bien_id'] ?? 0);

try {
    // Contexte de base (mandataire, bailleur, adresse bien) : si le bail existe, on part de lui ;
    // sinon on fabrique un contexte minimal à partir du bien.
    if ($bailId > 0) {
        $ctx = bail_habitation_context($pdo, $bailId);
        if (!$ctx) $ctx = null;
    } else {
        $ctx = null;
    }
    if ($ctx === null) {
        // Contexte minimal depuis le bien (aperçu avant 1ère sauvegarde).
        $ge = []; $bailleur = ['nom' => '', 'adresse' => '']; $bienAdr = ''; $imm = '';
        if ($bienId > 0) {
            try {
                $q = $pdo->prepare("SELECT b.adresse_1, b.code_postal, b.ville, i.nom_immeuble, i.adresse_1 AS imm_adr,
                                           COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, TRIM(CONCAT_WS(' ',tp.prenom,tp.nom)), NULLIF(p.societe,''), TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS bnom,
                                           COALESCE(NULLIF(tp.adresse_ligne1,''), p.adresse_1) AS badr, COALESCE(tp.code_postal,p.code_postal) bcp, COALESCE(tp.ville,p.ville) bville
                                      FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
                                      LEFT JOIN proprietaires p ON p.id=b.id_proprietaire LEFT JOIN tiers tp ON tp.id=p.id_tiers
                                     WHERE b.id=? LIMIT 1");
                $q->execute([$bienId]);
                if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                    $bienAdr  = trim((string)($r['adresse_1'] ?? '') . ' ' . ($r['code_postal'] ?? '') . ' ' . ($r['ville'] ?? ''));
                    $imm      = (string)($r['nom_immeuble'] ?: $r['imm_adr'] ?: '');
                    $bailleur = ['nom' => trim((string)($r['bnom'] ?? '')), 'adresse' => trim((string)($r['badr'] ?? '') . ' ' . ($r['bcp'] ?? '') . ' ' . ($r['bville'] ?? ''))];
                }
            } catch (Throwable) {}
            // Bloc mandataire (société/agence du bien) via le contexte commercial minimal.
            try {
                $ge = (function () use ($pdo, $bienId) {
                    $st = $pdo->prepare("SELECT id_societe, id_agence FROM biens WHERE id=?"); $st->execute([$bienId]);
                    $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                    // Réutilise la résolution gestionnaire commerciale via une ligne bail fictive.
                    return [];
                })();
            } catch (Throwable) {}
        }
        $ctx = ['row' => [], 'statut' => 'projet', 'numero_bail' => '', 'mandataire' => $ge, 'bailleur' => $bailleur, 'bien_adresse' => $bienAdr, 'immeuble' => $imm];
    }

    // Superpose les champs en cours d'édition (noms = colonnes bien_baux).
    $row = is_array($ctx['row'] ?? null) ? $ctx['row'] : [];
    foreach ($in as $k => $v) {
        if (in_array($k, ['bail_id', 'bien_id'], true)) continue;
        $row[$k] = $v;
    }
    // Le descriptif (Désignation des locaux) est composé depuis le bien via $row['id_bien'] :
    // sur un bail NON encore enregistré, la ligne est vide → on injecte l'id du bien édité.
    if (empty($row['id_bien']) && (int)($in['bien_id'] ?? 0) > 0) $row['id_bien'] = (int)$in['bien_id'];
    $ctx['row'] = $row;
    $ctx['signatures'] = [];   // aperçu = pas de signatures

    $html = bail_habitation_corps($ctx);
    echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
