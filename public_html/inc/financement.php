<?php
declare(strict_types=1);

/**
 * inc/financement.php — Accès données du module FINANCEMENT (Tranche 1).
 * Dossier financier collaboratif : tête + liens polymorphes + ACL. RÉFÉRENCE l'existant
 * (tiers, biens, immeubles, sociétés, créancier, GED), ne duplique JAMAIS.
 * Multi-tenant strict (id_societe). Historique via AuditLog.
 */

require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/ged_document_links.php';

/* ─────────── Libellés (jamais de terme technique à l'écran ; jamais « fait ») ─────────── */
if (!function_exists('fin_type_labels')) {
    function fin_type_labels(): array {
        return ['financement_bancaire' => 'Financement bancaire', 'dette_creancier' => 'Dette / créancier', 'procedure_financiere' => 'Procédure financière'];
    }
    function fin_statut_labels(): array {
        return ['ouvert' => 'Ouvert', 'en_cours' => 'En cours', 'suspendu' => 'Suspendu', 'clos' => 'Clos'];
    }
    function fin_confid_labels(): array {
        return ['normal' => 'Normal', 'confidentiel' => 'Confidentiel', 'restreint' => 'Restreint'];
    }
    function fin_role_labels(): array {
        return ['proprietaire' => 'Propriétaire', 'avocat' => 'Avocat', 'comptable' => 'Expert-comptable', 'notaire' => 'Notaire', 'banque' => 'Banque', 'commissaire' => 'Commissaire de justice', 'regie' => 'Régie', 'pilote' => 'Pilote'];
    }
    function fin_niveau_labels(): array {
        return ['lecture' => 'Lecture', 'contribution' => 'Contribution', 'validation' => 'Validation', 'pilote' => 'Pilote'];
    }
    /** Catégories documentaires générales (relation_type du lien GED). */
    function fin_ged_categories(): array {
        return ['pret_banque' => 'Prêt et banque', 'procedure' => 'Assignation et procédure', 'decompte' => 'Décompte', 'paiement' => 'Paiement', 'garantie' => 'Garantie et hypothèque', 'comptabilite' => 'Comptabilité', 'notaire' => 'Notaire', 'avocat' => 'Avocat', 'correspondance' => 'Correspondance', 'autre' => 'Autre'];
    }
    function fin_L(array $map, ?string $k, string $fallback = '—'): string { return $k !== null && isset($map[$k]) ? $map[$k] : $fallback; }
}

/* ─────────── Accès / périmètre ─────────── */
if (!function_exists('fin_soc')) {
    function fin_soc(): int { return (int)(function_exists('current_societe_id') ? (current_societe_id() ?? 0) : ($_SESSION['id_societe'] ?? 0)); }
    function fin_uid(): int { return (int)(function_exists('current_user_id') ? current_user_id() : ($_SESSION['user_id'] ?? 0)); }
    /** T1 : réservé super admin ; sinon rôle 1/2/7 de la même société OU acces explicite. */
    function fin_can_view(PDO $pdo, array $dossier): bool {
        if (function_exists('is_super_admin') && is_super_admin()) return true;
        $r = (int)(function_exists('current_role_id') ? current_role_id() : 0);
        $soc = fin_soc();
        if (in_array($r, [1, 2, 7], true) && (int)$dossier['id_societe'] === $soc) return true;
        $st = $pdo->prepare("SELECT 1 FROM fin_dossier_acces WHERE id_dossier=? AND identite_type='user' AND identite_id=? AND actif=1");
        $st->execute([(int)$dossier['id'], fin_uid()]);
        return (bool)$st->fetchColumn();
    }
    function fin_scope_ok(PDO $pdo, int $dossierId): ?array {
        $st = $pdo->prepare("SELECT * FROM fin_dossier WHERE id=?");
        $st->execute([$dossierId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return null;
        return fin_can_view($pdo, $d) ? $d : null;
    }
}

/* ─────────── CRUD dossier ─────────── */
if (!function_exists('fin_create')) {
    function fin_create(PDO $pdo, array $d): int {
        $soc = fin_soc(); $uid = fin_uid();
        $pdo->prepare("INSERT INTO fin_dossier (id_societe,id_agence,type,libelle,id_tiers,id_societe_concernee,pilote_user_id,statut,confidentialite,synthese,created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $soc, $d['id_agence'] ?? (function_exists('current_agence_id') ? current_agence_id() : null),
                $d['type'] ?? 'financement_bancaire', trim((string)($d['libelle'] ?? 'Dossier financier')),
                !empty($d['id_tiers']) ? (int)$d['id_tiers'] : null,
                !empty($d['id_societe_concernee']) ? (int)$d['id_societe_concernee'] : null,
                !empty($d['pilote_user_id']) ? (int)$d['pilote_user_id'] : $uid,
                $d['statut'] ?? 'ouvert', $d['confidentialite'] ?? 'normal', $d['synthese'] ?? null, $uid,
            ]);
        $id = (int)$pdo->lastInsertId();
        // Le créateur devient pilote (ACL)
        $pdo->prepare("INSERT IGNORE INTO fin_dossier_acces (id_societe,id_dossier,identite_type,identite_id,role_intervenant,niveau,perimetre,created_by)
                       VALUES (?,?,'user',?,'pilote','pilote','tout',?)")->execute([$soc, $id, $uid, $uid]);
        AuditLog::log($pdo, 'CREATE', 'fin_dossier', $id, [], ['libelle' => $d['libelle'] ?? '', 'type' => $d['type'] ?? '']);
        return $id;
    }
    function fin_update(PDO $pdo, int $id, array $fields): void {
        $allowed = ['type', 'libelle', 'id_tiers', 'id_societe_concernee', 'pilote_user_id', 'statut', 'confidentialite', 'synthese'];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) { if (in_array($k, $allowed, true)) { $set[] = "`$k`=?"; $vals[] = ($v === '' ? null : $v); } }
        if (!$set) return;
        $vals[] = $id;
        $pdo->prepare("UPDATE fin_dossier SET " . implode(',', $set) . ", updated_at=NOW() WHERE id=?")->execute($vals);
        AuditLog::log($pdo, 'UPDATE', 'fin_dossier', $id, [], $fields);
    }
    /** Liste des dossiers de la société (+ compteurs liens/participants). */
    function fin_list(PDO $pdo, int $soc): array {
        $sql = "SELECT d.*,
                       TRIM(CONCAT(COALESCE(t.civilite,''),' ',COALESCE(t.nom_affichage, CONCAT(COALESCE(t.nom,''),' ',COALESCE(t.prenom,''))))) AS tiers_nom,
                       s.nom AS societe_concernee_nom,
                       TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS pilote_nom,
                       (SELECT COUNT(*) FROM fin_dossier_lien l WHERE l.id_dossier=d.id AND l.entity_type IN ('BIEN','IMMEUBLE')) AS nb_biens,
                       (SELECT COUNT(*) FROM fin_dossier_lien l WHERE l.id_dossier=d.id AND l.entity_type='CREANCIER_DOSSIER') AS nb_creanciers,
                       (SELECT COUNT(*) FROM fin_dossier_acces a WHERE a.id_dossier=d.id AND a.actif=1) AS nb_participants
                FROM fin_dossier d
                LEFT JOIN tiers t     ON t.id = d.id_tiers
                LEFT JOIN societes s  ON s.id = d.id_societe_concernee
                LEFT JOIN users u     ON u.id = d.pilote_user_id
                WHERE d.id_societe = ?
                ORDER BY d.created_at DESC";
        $st = $pdo->prepare($sql); $st->execute([$soc]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    function fin_get(PDO $pdo, int $id): ?array {
        $sql = "SELECT d.*,
                       TRIM(CONCAT(COALESCE(t.civilite,''),' ',COALESCE(t.nom_affichage, CONCAT(COALESCE(t.nom,''),' ',COALESCE(t.prenom,''))))) AS tiers_nom,
                       s.nom AS societe_concernee_nom,
                       TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS pilote_nom
                FROM fin_dossier d
                LEFT JOIN tiers t ON t.id=d.id_tiers LEFT JOIN societes s ON s.id=d.id_societe_concernee LEFT JOIN users u ON u.id=d.pilote_user_id
                WHERE d.id=?";
        $st = $pdo->prepare($sql); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

/* ─────────── Liens polymorphes (référence, pas de copie) ─────────── */
if (!function_exists('fin_link_add')) {
    function fin_link_add(PDO $pdo, int $dossierId, string $type, int $entityId, ?string $role = null, ?string $note = null): bool {
        $type = strtoupper($type);
        if (!in_array($type, ['CREANCIER_DOSSIER', 'CREANCIER_SAISIE', 'BIEN', 'IMMEUBLE', 'SOCIETE', 'TIERS', 'DOSSIER_VENTE'], true) || $entityId <= 0) return false;
        $pdo->prepare("INSERT IGNORE INTO fin_dossier_lien (id_societe,id_dossier,entity_type,entity_id,role_lien,note,created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([fin_soc(), $dossierId, $type, $entityId, $role, $note, fin_uid()]);
        AuditLog::log($pdo, 'LINK_ADD', 'fin_dossier', $dossierId, [], ['type' => $type, 'entity_id' => $entityId]);
        return true;
    }
    function fin_link_remove(PDO $pdo, int $dossierId, string $type, int $entityId): void {
        $pdo->prepare("DELETE FROM fin_dossier_lien WHERE id_dossier=? AND entity_type=? AND entity_id=?")->execute([$dossierId, strtoupper($type), $entityId]);
        AuditLog::log($pdo, 'LINK_REMOVE', 'fin_dossier', $dossierId, ['type' => $type, 'entity_id' => $entityId], []);
    }
    /** Liens résolus (label + url) groupés par type. */
    function fin_links(PDO $pdo, int $dossierId): array {
        $st = $pdo->prepare("SELECT entity_type, entity_id, role_lien, note FROM fin_dossier_lien WHERE id_dossier=? ORDER BY entity_type, id");
        $st->execute([$dossierId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $res = fin_resolve_entity($pdo, $l['entity_type'], (int)$l['entity_id']);
            $out[$l['entity_type']][] = $l + $res;
        }
        return $out;
    }
    /** Résout un (type,id) → label + url MBI (aucune donnée dupliquée, lecture directe). */
    function fin_resolve_entity(PDO $pdo, string $type, int $id): array {
        $app = fn($p) => function_exists('app_url') ? app_url($p) : $p;
        switch (strtoupper($type)) {
            case 'BIEN':
                $r = $pdo->prepare("SELECT reference_bien, adresse_1, ville FROM biens WHERE id=?"); $r->execute([$id]); $b = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                return ['label' => trim(($b['reference_bien'] ?? '') . ' · ' . ($b['adresse_1'] ?? '') . ' ' . ($b['ville'] ?? ''), ' ·'), 'url' => $app('/bien_360.php?id=' . $id)];
            case 'IMMEUBLE':
                $r = $pdo->prepare("SELECT nom_immeuble, adresse_1, ville FROM immeubles WHERE id=?"); $r->execute([$id]); $b = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                return ['label' => trim(($b['nom_immeuble'] ?? '') . ' · ' . ($b['adresse_1'] ?? '') . ' ' . ($b['ville'] ?? ''), ' ·'), 'url' => $app('/immeuble_360.php?id=' . $id)];
            case 'TIERS':
                $r = $pdo->prepare("SELECT COALESCE(nom_affichage, CONCAT(COALESCE(nom,''),' ',COALESCE(prenom,''))) nom FROM tiers WHERE id=?"); $r->execute([$id]); $t = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                return ['label' => trim((string)($t['nom'] ?? ('Tiers #' . $id))), 'url' => $app('/tiers_360.php?id=' . $id)];
            case 'SOCIETE':
                $r = $pdo->prepare("SELECT nom FROM societes WHERE id=?"); $r->execute([$id]); $s = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                return ['label' => (string)($s['nom'] ?? ('Société #' . $id)), 'url' => '#'];
            case 'CREANCIER_DOSSIER':
                $r = $pdo->prepare("SELECT code, libelle FROM creancier_dossier WHERE id=?"); $r->execute([$id]); $c = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                return ['label' => trim(($c['code'] ?? '') . ' — ' . ($c['libelle'] ?? ''), ' —'), 'url' => $app('/creancier_dossier360.php?id=' . $id)];
            case 'DOSSIER_VENTE':
                return ['label' => 'Dossier de vente #' . $id, 'url' => $app('/transaction_dossier.php?id=' . $id)];
            default:
                return ['label' => $type . ' #' . $id, 'url' => '#'];
        }
    }
}

/* ─────────── Synthèse créancier (LECTURE seule, jamais de copie) ─────────── */
if (!function_exists('fin_creancier_synthese')) {
    function fin_creancier_synthese(PDO $pdo, int $creancierDossierId): ?array {
        $r = $pdo->prepare("SELECT id, code, libelle, statut, niveau_risque FROM creancier_dossier WHERE id=?");
        $r->execute([$creancierDossierId]);
        $c = $r->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        // créancier principal (lien role_dossier créancier* → tiers)
        $cp = $pdo->prepare("SELECT COALESCE(t.nom_affichage, CONCAT(COALESCE(t.nom,''),' ',COALESCE(t.prenom,''))) nom
                             FROM creancier_dossier_lien l JOIN tiers t ON t.id=l.entity_id
                             WHERE l.id_dossier=? AND l.entity_type='TIERS' AND l.role_dossier LIKE 'creancier%' LIMIT 1");
        $cp->execute([$creancierDossierId]);
        $c['creancier_principal'] = (string)($cp->fetchColumn() ?: '');
        // prochaine échéance prévue
        try {
            $ec = $pdo->prepare("SELECT date_prevue, montant FROM creancier_echeancier WHERE id_dossier=? AND statut='prevu' AND date_prevue>=CURDATE() ORDER BY date_prevue ASC LIMIT 1");
            $ec->execute([$creancierDossierId]);
            $c['prochaine_echeance'] = $ec->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { $c['prochaine_echeance'] = null; }
        return $c;
    }
}

/* ─────────── ACL participants ─────────── */
if (!function_exists('fin_acces_list')) {
    function fin_acces_list(PDO $pdo, int $dossierId): array {
        $st = $pdo->prepare("SELECT a.*,
                                    CASE WHEN a.identite_type='user'
                                         THEN TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,'')))
                                         ELSE COALESCE(t.nom_affichage, CONCAT(COALESCE(t.nom,''),' ',COALESCE(t.prenom,''))) END AS nom
                             FROM fin_dossier_acces a
                             LEFT JOIN users u ON (a.identite_type='user' AND u.id=a.identite_id)
                             LEFT JOIN tiers t ON (a.identite_type='tiers' AND t.id=a.identite_id)
                             WHERE a.id_dossier=? AND a.actif=1 ORDER BY a.role_intervenant, a.id");
        $st->execute([$dossierId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    function fin_acces_add(PDO $pdo, int $dossierId, string $identiteType, int $identiteId, string $role, string $niveau, string $perimetre = 'tout'): bool {
        if (!in_array($identiteType, ['user', 'tiers'], true) || $identiteId <= 0) return false;
        $pdo->prepare("INSERT INTO fin_dossier_acces (id_societe,id_dossier,identite_type,identite_id,role_intervenant,niveau,perimetre,created_by)
                       VALUES (?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE role_intervenant=VALUES(role_intervenant), niveau=VALUES(niveau), perimetre=VALUES(perimetre), actif=1, updated_at=NOW()")
            ->execute([fin_soc(), $dossierId, $identiteType, $identiteId, $role, $niveau, $perimetre, fin_uid()]);
        AuditLog::log($pdo, 'ACCES_ADD', 'fin_dossier', $dossierId, [], ['identite' => $identiteType . ':' . $identiteId, 'role' => $role]);
        return true;
    }
    function fin_acces_remove(PDO $pdo, int $dossierId, int $accesId): void {
        $pdo->prepare("UPDATE fin_dossier_acces SET actif=0, updated_at=NOW() WHERE id=? AND id_dossier=?")->execute([$accesId, $dossierId]);
        AuditLog::log($pdo, 'ACCES_REMOVE', 'fin_dossier', $dossierId, ['acces_id' => $accesId], []);
    }
}

/* ─────────── Documents GED (référence, pas de table doc) ─────────── */
if (!function_exists('fin_ged_docs')) {
    function fin_ged_docs(PDO $pdo, int $dossierId): array {
        try { return gdl_documents_for_entity($pdo, 'FIN', $dossierId, ['limit' => 200]); }
        catch (Throwable $e) { return []; }
    }
    /** Retrouve les dossiers financiers liés à une entité (pour les fiches 360 croisées). */
    function fin_dossiers_for_entity(PDO $pdo, string $type, int $entityId, int $soc): array {
        $st = $pdo->prepare("SELECT d.id, d.libelle, d.type, d.statut
                             FROM fin_dossier_lien l JOIN fin_dossier d ON d.id=l.id_dossier
                             WHERE l.entity_type=? AND l.entity_id=? AND d.id_societe=? ORDER BY d.created_at DESC");
        $st->execute([strtoupper($type), $entityId, $soc]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Dossiers financiers dont le propriétaire (tiers) est le sujet. */
    function fin_dossiers_for_tiers(PDO $pdo, int $tiersId, int $soc): array {
        $st = $pdo->prepare("SELECT id, libelle, type, statut FROM fin_dossier WHERE id_tiers=? AND id_societe=? ORDER BY created_at DESC");
        $st->execute([$tiersId, $soc]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Biens d'un propriétaire (tiers) — chaîne biens.id_proprietaire → proprietaires.id_tiers. */
    function fin_biens_of_tiers(PDO $pdo, int $tiersId, int $soc): array {
        $st = $pdo->prepare("SELECT b.id, TRIM(CONCAT(COALESCE(b.reference_bien,''),' · ',COALESCE(b.adresse_1,''),' ',COALESCE(b.ville,''))) AS label
                             FROM biens b JOIN proprietaires p ON p.id = b.id_proprietaire
                             WHERE p.id_tiers = ? AND b.id_societe = ? ORDER BY b.reference_bien");
        $st->execute([$tiersId, $soc]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Dossiers Créancier où ce tiers apparaît (débiteur/garant/…). */
    function fin_creanciers_of_tiers(PDO $pdo, int $tiersId, int $soc): array {
        try {
            $st = $pdo->prepare("SELECT DISTINCT c.id, TRIM(CONCAT(COALESCE(c.code,''),' — ',COALESCE(c.libelle,''))) AS label, c.statut, c.niveau_risque
                                 FROM creancier_dossier c JOIN creancier_dossier_lien l ON l.id_dossier = c.id
                                 WHERE l.entity_type='TIERS' AND l.entity_id = ? AND c.id_societe = ? ORDER BY c.created_at DESC");
            $st->execute([$tiersId, $soc]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('fin_related_block')) {
    /**
     * Bloc HTML « Dossiers financiers liés » à inclure dans une fiche 360
     * (bien, immeuble, créancier, propriétaire). T1 : visible super admin / admin.
     * @param string $type BIEN|IMMEUBLE|CREANCIER_DOSSIER|TIERS
     */
    function fin_related_block(PDO $pdo, string $type, int $entityId, ?int $soc = null): string {
        $isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)(function_exists('current_role_id') ? current_role_id() : 0), [1, 7], true);
        if (!$isAdmin || $entityId <= 0) return '';
        $soc = $soc ?? fin_soc();
        $type = strtoupper($type);
        $rows = $type === 'TIERS'
            ? array_merge(fin_dossiers_for_tiers($pdo, $entityId, $soc), fin_dossiers_for_entity($pdo, 'TIERS', $entityId, $soc))
            : fin_dossiers_for_entity($pdo, $type, $entityId, $soc);
        // dédoublonne par id
        $seen = []; $uniq = [];
        foreach ($rows as $r) { if (!isset($seen[$r['id']])) { $seen[$r['id']] = 1; $uniq[] = $r; } }
        $app = fn($p) => function_exists('app_url') ? app_url($p) : $p;
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $tl = fin_type_labels(); $sl = fin_statut_labels();
        $items = '';
        foreach ($uniq as $r) {
            $items .= '<a href="' . $h($app('/financement_360.php?id=' . (int)$r['id'])) . '" style="display:block;text-decoration:none;color:#5c4e22;padding:6px 0;border-bottom:1px dashed #eee;font-size:.86rem">💶 '
                . $h($r['libelle']) . ' <span style="color:#98917f">· ' . $h($tl[$r['type']] ?? $r['type']) . ' · ' . $h($sl[$r['statut']] ?? $r['statut']) . '</span></a>';
        }
        if ($items === '') $items = '<div style="color:#98917f;font-size:.84rem;padding:4px 0">Aucun dossier financier lié.</div>';
        // Depuis la fiche PROPRIÉTAIRE (tiers) : bouton de création pré-branchée sur ce propriétaire.
        $createBtn = $type === 'TIERS'
            ? '<a href="' . $h($app('/financement_liste.php?tiers=' . $entityId)) . '" style="font-size:.78rem;color:#fff;background:#7a6830;border-radius:8px;padding:3px 10px;text-decoration:none">+ Créer un dossier</a> '
            : '';
        return '<div style="background:#fff;border:1px solid #ece7d6;border-left:4px solid #7a6830;border-radius:12px;padding:12px 14px;margin:12px 0">'
            . '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px">'
            . '<strong style="color:#243B5C;font-size:.9rem">💶 Dossiers financiers</strong>'
            . '<span>' . $createBtn . '<a href="' . $h($app('/financement_liste.php')) . '" style="font-size:.78rem;color:#7a6830">Tous →</a></span></div>'
            . $items . '</div>';
    }
}
