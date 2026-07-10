<?php
/**
 * api/fluxbox_entity_resolve.php — Résout les LIBELLÉS d'entités à partir de leurs ID.
 *
 * Le modal d'upload est unique et partagé ; mais chaque page appelante lui passe un
 * contexte plus ou moins complet. Pour garantir la MÊME qualité PARTOUT (jamais de
 * « Propriétaire #id »), le modal appelle cette API avec les ID connus et récupère les
 * noms manquants — quelle que soit la page d'entrée.
 *
 * GET/POST : proprio_tiers_id?, proprio_id?, bien_id?, immeuble_id?, bail_id?
 * → { ok, proprio_nom, proprio_tiers_id, immeuble_id, immeuble_nom, bien_ref, bail_locataire }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$in  = static fn(string $k): int => (int)($_GET[$k] ?? $_POST[$k] ?? 0);

$proprioTiersId = $in('proprio_tiers_id');
$proprioId      = $in('proprio_id');
$bienId         = $in('bien_id');
$immeubleId     = $in('immeuble_id');
$bailId         = $in('bail_id');

$out = ['ok' => true, 'proprio_nom' => '', 'proprio_tiers_id' => $proprioTiersId,
        'immeuble_id' => $immeubleId, 'immeuble_nom' => '', 'bien_id' => $bienId,
        'bien_ref' => '', 'bail_locataire' => ''];

try {
    // CASCADE : BAIL → bien → immeuble + propriétaire. On résout d'ABORD le bail
    // (pour en déduire le bien), PUIS le bien (immeuble + proprio), etc.
    if ($bailId > 0) {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(locataire_raison_sociale,''),
                                    NULLIF(TRIM(CONCAT_WS(' ', locataire_prenom, locataire_nom)),'')) AS loc, id_bien
                               FROM bien_baux WHERE id = ? LIMIT 1");
        $st->execute([$bailId]);
        if ($bl = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['bail_locataire'] = (string)($bl['loc'] ?? '');
            if ($bienId <= 0 && !empty($bl['id_bien'])) $bienId = (int)$bl['id_bien'];
        }
    }

    // Depuis le BIEN (donné ou déduit du bail) : immeuble + propriétaire.
    if ($bienId > 0) {
        $out['bien_id'] = $bienId;
        $st = $pdo->prepare("SELECT reference_bien, designation, id_immeuble, id_proprietaire FROM biens WHERE id = ? LIMIT 1");
        $st->execute([$bienId]);
        if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['bien_ref'] = (string)($b['reference_bien'] ?: $b['designation'] ?: ('bien #' . $bienId));
            if ($immeubleId <= 0 && !empty($b['id_immeuble'])) $immeubleId = $out['immeuble_id'] = (int)$b['id_immeuble'];
            if ($proprioId <= 0 && !empty($b['id_proprietaire'])) $proprioId = (int)$b['id_proprietaire'];
        }
    }

    // PROPRIÉTAIRE : priorité tiers, repli table proprietaires.
    if ($proprioTiersId > 0) {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''), NULLIF(raison_sociale,''),
                                    NULLIF(TRIM(CONCAT_WS(' ', prenom, nom)),'')) FROM tiers WHERE id = ? LIMIT 1");
        $st->execute([$proprioTiersId]);
        $out['proprio_nom'] = (string)($st->fetchColumn() ?: '');
    }
    if ($out['proprio_nom'] === '' && $proprioId > 0) {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(societe,''), NULLIF(TRIM(CONCAT_WS(' ', prenom, nom)),'')), id_tiers
                               FROM proprietaires WHERE id = ? LIMIT 1");
        $st->execute([$proprioId]);
        if ($p = $st->fetch(PDO::FETCH_NUM)) {
            $out['proprio_nom'] = (string)($p[0] ?? '');
            if ($out['proprio_tiers_id'] <= 0 && !empty($p[1])) $out['proprio_tiers_id'] = (int)$p[1];
        }
    }

    // IMMEUBLE : nom lisible.
    if ($immeubleId > 0) {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(nom_immeuble,''), NULLIF(adresse_1,'')) FROM immeubles WHERE id = ? LIMIT 1");
        $st->execute([$immeubleId]);
        $out['immeuble_nom'] = (string)($st->fetchColumn() ?: '');
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
