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
        SELECT b.id, b.reference_bien, b.designation, b.ville, b.type_commercialisation,
               b.statut_bien, dv.id AS id_dossier, bt.label AS type_label
          FROM biens b
          LEFT JOIN dossier_vente dv ON dv.id_bien = b.id
          LEFT JOIN base_types_bien bt ON bt.id = b.id_type_bien
         WHERE b.id_proprietaire = ?
         ORDER BY (b.type_commercialisation='vente') DESC, b.reference_bien ASC, b.id ASC
         LIMIT 200");
    $st->execute([$idProp]);

    $biens = array_map(static function(array $b): array {
        $label = $b['reference_bien'] ?: ('#' . $b['id']);
        return [
            'id'          => (int)$b['id'],
            'ref'         => $label,
            'designation' => $b['designation'] ?: ($b['type_label'] ?: ''),
            'ville'       => $b['ville'] ?: '',
            'en_vente'    => $b['type_commercialisation'] === 'vente',
            'id_dossier'  => $b['id_dossier'] !== null ? (int)$b['id_dossier'] : null,
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

    echo json_encode(['ok'=>true, 'biens'=>$biens], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_proprio_biens] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
