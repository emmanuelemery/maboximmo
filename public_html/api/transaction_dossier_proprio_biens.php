<?php
// api/transaction_dossier_proprio_biens.php — Liste les biens existants d'un propriétaire
// (pour choix rapide dans le parcours « Nouveau dossier »). Zéro ressaisie.
// GET : id_proprietaire  →  { ok, biens:[{id, ref, designation, ville, en_vente, id_dossier}] }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$idTiers = (int)($_GET['id_tiers'] ?? 0);
$idProp  = (int)($_GET['id_proprietaire'] ?? 0);
if ($idTiers <= 0 && $idProp <= 0) { echo json_encode(['ok'=>false,'error'=>'id_tiers ou id_proprietaire manquant']); exit; }

try {
    // On filtre par TIERS (toutes ses fiches propriétaire) — comme tiers_360.php —
    // pour ne pas rater de biens quand le tiers a plusieurs fiches propriétaire en double.
    // Fallback id_proprietaire si le tiers n'est pas fourni.
    $where  = $idTiers > 0 ? 'p.id_tiers = ?' : 'b.id_proprietaire = ?';
    $param  = $idTiers > 0 ? $idTiers : $idProp;

    $st = $pdo->prepare("
        SELECT b.id, b.reference_bien, b.designation, b.type_commercialisation, b.statut_bien,
               b.statut_occupation, b.surface_habitable, b.etage, b.loyer_hc,
               COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse_1,
               COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS code_postal,
               COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
               bt.label AS type_label,
               dv.id AS id_dossier,
               (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS nb_baux_actifs,
               (SELECT COALESCE(
                         NULLIF(TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)), ''),
                         NULLIF(bb.locataire_raison_sociale, ''),
                         NULLIF(tl.nom_affichage, ''),
                         NULLIF(tl.raison_sociale, ''),
                         NULLIF(TRIM(CONCAT_WS(' ', tl.prenom, tl.nom)), '')
                       )
                  FROM bien_baux bb
                  LEFT JOIN tiers tl ON tl.id = bb.id_tiers_locataire
                 WHERE bb.id_bien = b.id AND bb.statut = 'actif'
                 ORDER BY bb.id DESC LIMIT 1) AS locataire
          FROM biens b
          INNER JOIN proprietaires p   ON p.id = b.id_proprietaire
          LEFT JOIN immeubles i        ON i.id = b.id_immeuble
          LEFT JOIN base_types_bien bt ON bt.id = b.id_type_bien
          LEFT JOIN dossier_vente dv   ON dv.id_bien = b.id
         WHERE $where
           AND COALESCE(b.statut_bien,'') NOT IN ('supprime','archive','vendu')
         ORDER BY (b.type_commercialisation='vente') DESC, b.reference_bien ASC, b.id ASC
         LIMIT 200");
    $st->execute([$param]);

    $biens = array_map(static function(array $b): array {
        $adr = trim((string)($b['adresse_1'] ?? ''));
        $cpv = trim(trim((string)($b['code_postal'] ?? '')) . ' ' . trim((string)($b['ville'] ?? '')));
        return [
            'id'         => (int)$b['id'],
            'ref'        => $b['reference_bien'] ?: ('#' . $b['id']),
            'type'       => $b['type_label'] ?: '',
            'surface'    => $b['surface_habitable'] !== null ? (float)$b['surface_habitable'] : null,
            'etage'      => $b['etage'] !== null && $b['etage'] !== '' ? (int)$b['etage'] : null,
            'adresse'    => trim($adr . ($adr && $cpv ? ', ' : '') . $cpv),
            // « Loué » exactement comme tiers_360 : bail actif sur le bien,
            // sinon loyer renseigné sur la fiche (imports sans bail structuré).
            'loue'       => (int)($b['nb_baux_actifs'] ?? 0) > 0
                            || (float)($b['loyer_hc'] ?? 0) > 0,
            'locataire'  => $b['locataire'] ?: null,
            'en_vente'   => $b['type_commercialisation'] === 'vente',
            'id_dossier' => $b['id_dossier'] !== null ? (int)$b['id_dossier'] : null,
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

    echo json_encode(['ok'=>true, 'biens'=>$biens], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_proprio_biens] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
