<?php
// api/transaction_dossier_proprio_biens.php — Liste les biens existants d'un propriétaire
// (pour choix rapide dans le parcours « Nouveau dossier »). Zéro ressaisie.
// GET : id_proprietaire  →  { ok, biens:[{id, ref, designation, ville, en_vente, id_dossier}] }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$idProp = (int)($_GET['id_proprietaire'] ?? 0);
if ($idProp <= 0) { echo json_encode(['ok'=>false,'error'=>'id_proprietaire manquant']); exit; }

try {
    $st = $pdo->prepare("
        SELECT b.id, b.reference_bien, b.designation, b.type_commercialisation, b.statut_bien,
               b.surface_habitable, b.etage,
               COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse_1,
               COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS code_postal,
               COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
               bt.label AS type_label,
               dv.id AS id_dossier,
               COALESCE(
                 NULLIF(TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)), ''),
                 bb.locataire_raison_sociale
               ) AS locataire
          FROM biens b
          LEFT JOIN immeubles i        ON i.id = b.id_immeuble
          LEFT JOIN base_types_bien bt ON bt.id = b.id_type_bien
          LEFT JOIN dossier_vente dv   ON dv.id_bien = b.id
          LEFT JOIN bien_baux bb       ON bb.id_bien = b.id AND bb.statut = 'actif'
         WHERE b.id_proprietaire = ?
         GROUP BY b.id
         ORDER BY (b.type_commercialisation='vente') DESC, b.reference_bien ASC, b.id ASC
         LIMIT 200");
    $st->execute([$idProp]);

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
