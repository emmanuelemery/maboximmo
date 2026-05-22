<?php
// inc/transaction_doc_lib.php — Helpers partagés pour le stockage doc Transaction
// (utilisé par transaction_doc_upload.php ET transaction_bail_save.php)
declare(strict_types=1);

if (!function_exists('tr_bail_ensure_columns')) {
    /**
     * Auto-création des colonnes bien_baux étendues (représentants, renonciation, conditions).
     * Idempotent, exécuté 1× par requête.
     */
    function tr_bail_ensure_columns(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        $colsToAdd = [
            'bailleur_representant_nom'        => "VARCHAR(150) NULL COMMENT 'Représentant légal du bailleur' AFTER `id_proprietaire`",
            'bailleur_representant_qualite'    => "VARCHAR(80) NULL AFTER `bailleur_representant_nom`",
            'bailleur_representant_email'      => "VARCHAR(190) NULL AFTER `bailleur_representant_qualite`",
            'bailleur_representant_telephone'  => "VARCHAR(30) NULL AFTER `bailleur_representant_email`",
            'locataire_representant_nom'       => "VARCHAR(150) NULL COMMENT 'Représentant légal du locataire' AFTER `locataire_telephone`",
            'locataire_representant_qualite'   => "VARCHAR(80) NULL AFTER `locataire_representant_nom`",
            'locataire_representant_email'     => "VARCHAR(190) NULL AFTER `locataire_representant_qualite`",
            'locataire_representant_telephone' => "VARCHAR(30) NULL AFTER `locataire_representant_email`",
            'renonciation_recours_reciproque'  => "TINYINT(1) NULL DEFAULT NULL COMMENT '0=non, 1=oui, NULL=non précisé' AFTER `clause_resolutoire`",
            'conditions_particulieres'         => "TEXT NULL COMMENT 'Conditions particulières du bail' AFTER `metadata`",
        ];
        try {
            $existing = [];
            $st = $pdo->query("SHOW COLUMNS FROM bien_baux");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) $existing[$r['Field']] = true;
            foreach ($colsToAdd as $col => $defn) {
                if (isset($existing[$col])) continue;
                try {
                    $pdo->exec("ALTER TABLE `bien_baux` ADD COLUMN `$col` $defn");
                } catch (Throwable $e) {
                    error_log('[tr_bail_ensure_columns] ' . $col . ' : ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[tr_bail_ensure_columns] ' . $e->getMessage());
        }
    }
}

if (!function_exists('transaction_upload_document')) {
    /**
     * Stocke un fichier dans uploads/ged/transaction/{bien_id}/ + INSERT ged_documents.
     * Lien GED via metadata.classement.bien_id_bdd (compat ged_consult).
     *
     * @param array $file $_FILES['xxx'] (le caller place une struct $_FILES-compatible)
     * @param string $mode 'upload' (move_uploaded_file) | 'copy' (copy from staging)
     */
    function transaction_upload_document(
        PDO $pdo,
        int $idBien,
        array $file,
        string $typeDoc,
        string $visibilite,
        string $commentaire,
        ?int $idSociete,
        ?int $idAgence,
        ?int $idUser,
        string $mode = 'upload'   // 'upload' ou 'copy'
    ): array {
        $stmt = $pdo->prepare('SELECT id_societe, id_agence, reference_bien, ville FROM biens WHERE id = ? LIMIT 1');
        $stmt->execute([$idBien]);
        $bien = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bien) throw new RuntimeException('bien introuvable');
        $idSociete = $idSociete ?: ($bien['id_societe'] ? (int)$bien['id_societe'] : null);
        $idAgence  = $idAgence  ?: ($bien['id_agence']  ? (int)$bien['id_agence']  : null);

        $baseDir = __DIR__ . '/../uploads/ged/transaction/' . $idBien;
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);

        $origName = basename((string)$file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $safeBase = preg_replace('~[^a-zA-Z0-9._-]+~', '_', pathinfo($origName, PATHINFO_FILENAME));
        $uuid     = bin2hex(random_bytes(16));
        $uuidHuman= sprintf('%s-%s-%s-%s-%s', substr($uuid,0,8), substr($uuid,8,4), substr($uuid,12,4), substr($uuid,16,4), substr($uuid,20,12));
        $finalName= $uuidHuman . ($safeBase ? '_' . $safeBase : '') . ($ext ? '.' . $ext : '');
        $finalPath= $baseDir . '/' . $finalName;

        if ($mode === 'copy') {
            if (!copy($file['tmp_name'], $finalPath)) throw new RuntimeException('copy from staging failed');
        } else {
            if (!move_uploaded_file($file['tmp_name'], $finalPath)) throw new RuntimeException('move_uploaded_file failed');
        }

        $size = filesize($finalPath) ?: 0;
        $mime = function_exists('mime_content_type') ? (mime_content_type($finalPath) ?: 'application/octet-stream') : 'application/octet-stream';
        $hash = hash_file('sha256', $finalPath);

        $datePart = date('Ymd');
        $refBien  = strtoupper(preg_replace('~[^A-Z0-9]+~', '', (string)($bien['reference_bien'] ?: 'B' . $idBien)));
        $nameCanonical = '05_TRANSACTION_' . $typeDoc . '_' . $refBien . '_' . $datePart;
        $nameDisplay   = $typeDoc . ' — ' . ($bien['reference_bien'] ?: '#' . $idBien) . ' — ' . date('d/m/Y');

        $metadata = [
            'classement' => [
                'n1'            => '05_TRANSACTION',
                'n4'            => $typeDoc,
                'bien_id_bdd'   => $idBien,
                'bien_ref_bdd'  => $bien['reference_bien'] ?? null,
                'bien_ville'    => $bien['ville'] ?? null,
            ],
            'source'      => 'transaction_index',
            'visibilite'  => $visibilite,
            'commentaire' => $commentaire,
            'old_filename'=> $origName,
        ];
        $linkedEntities = [['type' => 'bien', 'id' => $idBien]];

        // INSERT ged_documents AVEC FK directe id_bien (raccourci JSON_EXTRACT)
        // On gère le cas où la colonne id_bien n'existe pas encore (avant migration FK)
        $hasIdBienCol = false;
        try {
            $st = $pdo->query("SHOW COLUMNS FROM ged_documents LIKE 'id_bien'");
            $hasIdBienCol = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}

        if ($hasIdBienCol) {
            $ins = $pdo->prepare('INSERT INTO ged_documents
                (uuid, tenant_id, societe_id, agence_id, id_bien, name_display, name_canonical, name_file,
                 document_type, source_module, storage_provider, mime_type, size_bytes, hash_sha256,
                 metadata, linked_entities, security_level, status, version, created_by,
                 confidence_score, old_filename, final_destination)
                VALUES
                (:uuid, :tenant, :soc, :age, :id_bien, :nd, :nc, :nf,
                 :dt, "05_TRANSACTION", "local", :mime, :size, :hash,
                 :meta, :linked, :sec, "active", 1, :ub,
                 100, :origName, :path)');
            $ins->execute([
                ':uuid'   => $uuidHuman, ':tenant' => $idSociete, ':soc' => $idSociete, ':age' => $idAgence,
                ':id_bien' => $idBien,
                ':nd' => $nameDisplay, ':nc' => $nameCanonical, ':nf' => $finalName, ':dt' => $typeDoc,
                ':mime' => $mime, ':size' => $size, ':hash' => $hash,
                ':meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ':linked' => json_encode($linkedEntities, JSON_UNESCAPED_UNICODE),
                ':sec' => ($visibilite === 'interne') ? 'interne' : 'public',
                ':ub' => $idUser ?: null,
                ':origName' => $origName,
                ':path' => 'uploads/ged/transaction/' . $idBien . '/' . $finalName,
            ]);
        } else {
            // Fallback ancien schéma (sans FK directe)
            $ins = $pdo->prepare('INSERT INTO ged_documents
                (uuid, tenant_id, societe_id, agence_id, name_display, name_canonical, name_file,
                 document_type, source_module, storage_provider, mime_type, size_bytes, hash_sha256,
                 metadata, linked_entities, security_level, status, version, created_by,
                 confidence_score, old_filename, final_destination)
                VALUES
                (:uuid, :tenant, :soc, :age, :nd, :nc, :nf,
                 :dt, "05_TRANSACTION", "local", :mime, :size, :hash,
                 :meta, :linked, :sec, "active", 1, :ub,
                 100, :origName, :path)');
            $ins->execute([
                ':uuid' => $uuidHuman, ':tenant' => $idSociete, ':soc' => $idSociete, ':age' => $idAgence,
                ':nd' => $nameDisplay, ':nc' => $nameCanonical, ':nf' => $finalName, ':dt' => $typeDoc,
                ':mime' => $mime, ':size' => $size, ':hash' => $hash,
                ':meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ':linked' => json_encode($linkedEntities, JSON_UNESCAPED_UNICODE),
                ':sec' => ($visibilite === 'interne') ? 'interne' : 'public',
                ':ub' => $idUser ?: null,
                ':origName' => $origName,
                ':path' => 'uploads/ged/transaction/' . $idBien . '/' . $finalName,
            ]);
        }

        $gedId = (int)$pdo->lastInsertId();

        // ─── Architecture propre : ged_documents = UNIQUE source de vérité ──
        // Plus de double écriture biens_documents (annule fix précédent).
        // bien_detail.php sera adapté (étape C) pour lire ged_documents via id_bien.

        return [
            'id'    => $gedId,
            'path'  => $finalPath,
            'name'  => $nameDisplay,
            'type'  => $typeDoc,
        ];
    }
}
