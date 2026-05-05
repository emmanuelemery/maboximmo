<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * inc/agence_doc_officiel_repliquer.php
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Quand un nouveau document officiel est uploadé et OCRisé, on réplique
 * les champs OCR vers `agences.*` pour que les supports existants (affiches,
 * fiches, modale Lot 1, critic engine) restent compatibles sans toucher
 * à leur lecture.
 *
 * 1 doc actif par type d'agence = source unique pour les supports.
 *
 * API publique :
 *   agence_doc_repliquer_vers_agences(PDO $pdo, int $idAgence, string $typeDoc): bool
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('agence_doc_repliquer_vers_agences')) {

    function agence_doc_repliquer_vers_agences(PDO $pdo, int $idAgence, string $typeDoc): bool
    {
        if ($idAgence <= 0) return false;

        // Récupère le doc actif courant pour ce type
        try {
            $st = $pdo->prepare("
                SELECT numero, emetteur, date_emission, date_validite, montant_garantie,
                       fichier_path, ged_document_id
                FROM agences_documents_officiels
                WHERE id_agence = :a AND type_document = :t AND statut = 'actif'
                ORDER BY id DESC LIMIT 1
            ");
            $st->execute([':a' => $idAgence, ':t' => $typeDoc]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[agence_doc_repliquer SELECT] ' . $e->getMessage());
            return false;
        }
        if (!$doc) return false;

        // Mapping type → colonnes agences cibles
        $patch = [];
        switch ($typeDoc) {
            case 'carte_pro':
                $patch['carte_pro_numero']   = $doc['numero'];
                $patch['carte_pro_cci']      = $doc['emetteur'];
                $patch['carte_pro_validite'] = $doc['date_validite'];
                break;

            case 'kbis':
                $patch['kbis_numero'] = $doc['numero'];
                $patch['kbis_date']   = $doc['date_emission'];
                break;

            case 'garant_financier':
                $patch['garant_financier'] = $doc['emetteur'];
                $patch['garant_validite']  = $doc['date_validite'];
                $patch['garant_montant']   = $doc['montant_garantie'];
                break;

            case 'rc_pro':
                $patch['rc_pro']           = $doc['emetteur'];
                $patch['rc_pro_validite']  = $doc['date_validite'];
                break;

            case 'bareme_honoraires':
                // Le barème pointe vers le PDF lui-même (URL téléchargement)
                if (!empty($doc['fichier_path'])) {
                    $patch['bareme_url_doc'] = $doc['fichier_path'];
                }
                break;

            default:
                return false;
        }

        if (empty($patch)) return false;

        // Ne mettre à jour que les colonnes qui existent réellement
        $existing = agence_doc_repliquer_columns_of($pdo, 'agences');
        $patch = array_intersect_key($patch, array_flip($existing));
        if (empty($patch)) return false;

        try {
            $sets = [];
            $params = [':id' => $idAgence];
            foreach ($patch as $col => $val) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
                $sets[] = "`{$col}` = :v_{$col}";
                $params[":v_{$col}"] = $val;
            }
            $sql = "UPDATE agences SET " . implode(', ', $sets) . " WHERE id = :id";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return true;
        } catch (Throwable $e) {
            error_log('[agence_doc_repliquer UPDATE] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('agence_doc_repliquer_columns_of')) {
    function agence_doc_repliquer_columns_of(PDO $pdo, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
            $st->execute([':t' => $table]);
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) { $cols = []; }
        $cache[$table] = array_map('strtolower', $cols);
        return $cache[$table];
    }
}
