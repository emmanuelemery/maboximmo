<?php
/**
 * inc/avant_contrat.php — Helpers de l'avant-contrat (compromis / promesse).
 * Tables : dossier_avant_contrat (+ dossier_avant_contrat_lot, sélection des lots).
 * Voir migration 20260615b_dossier_avant_contrat.
 */
declare(strict_types=1);

if (!function_exists('dac_columns')) {
    /** Colonnes éditables (whitelist) + leur type pour la conversion. */
    function dac_columns(): array {
        return [
            'type'=>'s','copro'=>'b','statut'=>'s','reference'=>'s',
            'date_signature'=>'d','date_reiteration_max'=>'d','lieu_signature'=>'s',
            'depot_garantie_montant'=>'n','depot_garantie_pct'=>'n','sequestre_type'=>'s',
            'indemnite_immobilisation'=>'n','date_levee_option'=>'d',
            'cs_pret'=>'b','pret_montant'=>'n','pret_duree_mois'=>'i','pret_taux_max'=>'n',
            'pret_nb_offres'=>'i','pret_date_limite'=>'d','pret_apport'=>'n','pret_organismes'=>'s',
            'cs_preemption'=>'b','cs_preemption_detail'=>'s','cs_servitudes'=>'b','cs_urbanisme'=>'b',
            'cs_hypotheques'=>'b','cs_vente_bien_acquereur'=>'b','cs_autres'=>'s',
            'date_entree_jouissance'=>'d','occupation'=>'s','mobilier_inclus'=>'b',
            'mobilier_valeur'=>'n','mobilier_detail'=>'s',
            'notaire_redacteur'=>'s','frais_acte_charge'=>'s',
            'sru_date_notification'=>'d','sru_date_fin_retractation'=>'d',
            'conditions_particulieres'=>'s',
        ];
    }
}

if (!function_exists('dac_get')) {
    /** Avant-contrat du dossier (le plus récent). Null si aucun. */
    function dac_get(PDO $pdo, int $idDossier): ?array {
        if ($idDossier <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM dossier_avant_contrat WHERE id_dossier = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$idDossier]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('dac_ensure')) {
    /** Crée un avant-contrat brouillon si le dossier n'en a pas. Retourne son id. */
    function dac_ensure(PDO $pdo, int $idDossier, ?int $idUser = null): int {
        $cur = dac_get($pdo, $idDossier);
        if ($cur) return (int)$cur['id'];
        $st = $pdo->prepare("INSERT INTO dossier_avant_contrat (id_dossier, type, statut, id_user, created_at, updated_at)
                             VALUES (?, 'compromis', 'brouillon', ?, NOW(), NOW())");
        $st->execute([$idDossier, $idUser]);
        $idAc = (int)$pdo->lastInsertId();
        // Présélectionne tous les lots du dossier par défaut.
        try {
            $pdo->prepare("INSERT IGNORE INTO dossier_avant_contrat_lot (id_avant_contrat, id_bien, created_at)
                           SELECT ?, id_bien, NOW() FROM dossier_vente_bien WHERE id_dossier = ?")
                ->execute([$idAc, $idDossier]);
        } catch (Throwable $e) { error_log('[dac_ensure lots] ' . $e->getMessage()); }
        return $idAc;
    }
}

if (!function_exists('dac_save')) {
    /** Met à jour les champs (whitelist). Valeurs '' → NULL pour les champs non requis. */
    function dac_save(PDO $pdo, int $idAc, int $idDossier, array $vals): bool {
        if ($idAc <= 0) return false;
        $cols = dac_columns();
        $sets = []; $params = [];
        foreach ($cols as $c => $type) {
            if (!array_key_exists($c, $vals)) continue;
            $v = $vals[$c];
            switch ($type) {
                case 'b': $v = (in_array((string)$v, ['1','on','true'], true)) ? 1 : 0; break;
                case 'i': $v = ($v === '' || $v === null) ? null : (int)$v; break;
                case 'n': $v = ($v === '' || $v === null) ? null : (float)str_replace([' ',','],['','.'],(string)$v); break;
                case 'd': $v = ($v === '' || $v === null) ? null : (string)$v; break;
                default:  $v = ($v === '' ? null : (string)$v);
            }
            $sets[] = "`$c` = ?"; $params[] = $v;
        }
        if (!$sets) return true;
        // SRU : si date notification fournie et fin non fournie → +10 jours auto
        $params[] = $idAc; $params[] = $idDossier;
        $sql = "UPDATE dossier_avant_contrat SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ? AND id_dossier = ?";
        try { return $pdo->prepare($sql)->execute($params); }
        catch (Throwable $e) { error_log('[dac_save] ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('dac_lots')) {
    /** Ids des biens sélectionnés pour l'acte. */
    function dac_lots(PDO $pdo, int $idAc): array {
        if ($idAc <= 0) return [];
        $st = $pdo->prepare("SELECT id_bien FROM dossier_avant_contrat_lot WHERE id_avant_contrat = ?");
        $st->execute([$idAc]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}

if (!function_exists('dac_set_lots')) {
    /** Remplace la sélection de lots de l'acte. */
    function dac_set_lots(PDO $pdo, int $idAc, array $bienIds): bool {
        if ($idAc <= 0) return false;
        $bienIds = array_values(array_unique(array_map('intval', $bienIds)));
        try {
            $pdo->prepare("DELETE FROM dossier_avant_contrat_lot WHERE id_avant_contrat = ?")->execute([$idAc]);
            if ($bienIds) {
                $ins = $pdo->prepare("INSERT INTO dossier_avant_contrat_lot (id_avant_contrat, id_bien, created_at) VALUES (?, ?, NOW())");
                foreach ($bienIds as $b) { if ($b > 0) $ins->execute([$idAc, $b]); }
            }
            return true;
        } catch (Throwable $e) { error_log('[dac_set_lots] ' . $e->getMessage()); return false; }
    }
}
