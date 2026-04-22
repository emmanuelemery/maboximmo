<?php
declare(strict_types=1);

/**
 * inc/investisseur_contacts.php
 *
 * CRUD des contacts externes (gérants/propriétaires externes type T. Saby)
 * + gestion des liaisons contact ↔ propriétaires.
 *
 * Utilisation type :
 *   $id = inv_contact_save($pdo, ['nom'=>'SABY','email'=>'t.saby@...'])
 *   inv_contact_attach_proprietaires($pdo, $id, [9, 12, 13, 15, 16])  // 5 SCI SIR
 *   $ids = inv_contact_biens_ids($pdo, $id) // tous les biens visibles par ce contact
 */

require_once __DIR__ . '/investisseur_helpers.php';

if (!function_exists('inv_contact_save')) {
    function inv_contact_save(PDO $pdo, array $data, ?int $id = null): int {
        $sc = inv_current_scope();
        $clean = [
            'civilite'  => trim((string)($data['civilite'] ?? '')),
            'prenom'    => trim((string)($data['prenom'] ?? '')),
            'nom'       => trim((string)($data['nom'] ?? '')),
            'email'     => trim((string)($data['email'] ?? '')),
            'telephone' => trim((string)($data['telephone'] ?? '')),
            'role'      => trim((string)($data['role'] ?? 'gerant')),
            'notes'     => trim((string)($data['notes'] ?? '')),
            'actif'     => !empty($data['actif']) ? 1 : (isset($data['actif']) && $data['actif'] === '0' ? 0 : 1),
        ];
        if ($clean['nom'] === '') throw new RuntimeException('Nom du contact requis.');
        if (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email invalide.');

        if ($id === null) {
            $clean['id_societe'] = $sc['id_societe'];
            $clean['id_agence']  = $sc['id_agence'];
            $cols = array_keys($clean);
            $ph   = array_map(fn($c) => ':' . $c, $cols);
            $sql = "INSERT INTO investisseur_contacts_externes (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
            $st  = $pdo->prepare($sql);
            foreach ($clean as $k => $v) $st->bindValue(':' . $k, $v);
            $st->execute();
            return (int)$pdo->lastInsertId();
        }

        $existing = inv_contact_load($pdo, $id);
        if (!$existing) throw new RuntimeException('Contact introuvable ou hors périmètre.');
        $sets = [];
        foreach (array_keys($clean) as $c) $sets[] = "`$c` = :$c";
        $sql = "UPDATE investisseur_contacts_externes SET " . implode(',', $sets) . " WHERE id = :id_row";
        $st = $pdo->prepare($sql);
        foreach ($clean as $k => $v) $st->bindValue(':' . $k, $v);
        $st->bindValue(':id_row', $id, PDO::PARAM_INT);
        $st->execute();
        return $id;
    }
}

if (!function_exists('inv_contact_load')) {
    function inv_contact_load(PDO $pdo, int $id): ?array {
        $sc = inv_current_scope();
        $sql = "SELECT * FROM investisseur_contacts_externes WHERE id = :id";
        $params = [':id' => $id];
        if ($sc['id_societe'] !== null) {
            $sql .= " AND (id_societe = :s OR id_societe IS NULL)";
            $params[':s'] = $sc['id_societe'];
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('inv_contact_list')) {
    function inv_contact_list(PDO $pdo): array {
        $sc = inv_current_scope();
        $where = []; $params = [];
        if ($sc['id_societe'] !== null) {
            $where[] = '(id_societe = :s OR id_societe IS NULL)';
            $params[':s'] = $sc['id_societe'];
        }
        $sql = "SELECT c.*,
                  (SELECT COUNT(*) FROM investisseur_contacts_proprietaires WHERE id_contact = c.id) AS nb_proprietaires,
                  (SELECT COUNT(*) FROM investisseur_partages WHERE id_contact_externe = c.id) AS nb_partages
                FROM investisseur_contacts_externes c";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.updated_at DESC';
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_contact_delete')) {
    function inv_contact_delete(PDO $pdo, int $id): bool {
        $row = inv_contact_load($pdo, $id);
        if (!$row) return false;
        $pdo->prepare("DELETE FROM investisseur_contacts_externes WHERE id = :id")->execute([':id' => $id]);
        return true;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// LIAISON CONTACT ↔ PROPRIETAIRES
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_contact_attach_proprietaires')) {
    /**
     * Remplace complètement la liste des propriétaires rattachés à un contact.
     */
    function inv_contact_attach_proprietaires(PDO $pdo, int $idContact, array $idsProprietaires): int {
        $pdo->prepare("DELETE FROM investisseur_contacts_proprietaires WHERE id_contact = :c")
            ->execute([':c' => $idContact]);
        $ins = $pdo->prepare("INSERT IGNORE INTO investisseur_contacts_proprietaires (id_contact, id_proprietaire, role_contact)
                              VALUES (:c, :p, 'gerant')");
        $n = 0;
        foreach (array_unique(array_map('intval', $idsProprietaires)) as $idp) {
            if ($idp <= 0) continue;
            $ins->execute([':c' => $idContact, ':p' => $idp]);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('inv_contact_proprietaires')) {
    /**
     * Liste les propriétaires rattachés à un contact, avec meta (nb biens, valeur).
     */
    function inv_contact_proprietaires(PDO $pdo, int $idContact): array {
        $sql = "SELECT p.id, p.nom, p.prenom, p.societe,
                  (SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire = p.id) AS nb_biens
                FROM investisseur_contacts_proprietaires cp
                JOIN proprietaires p ON p.id = cp.id_proprietaire
                WHERE cp.id_contact = :c
                ORDER BY p.societe, p.nom";
        $st = $pdo->prepare($sql);
        $st->bindValue(':c', $idContact, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_contact_biens_ids')) {
    /**
     * Retourne tous les id_bien accessibles à un contact (via ses propriétaires).
     */
    function inv_contact_biens_ids(PDO $pdo, int $idContact): array {
        $sql = "SELECT DISTINCT b.id
                FROM investisseur_contacts_proprietaires cp
                JOIN biens b ON b.id_proprietaire = cp.id_proprietaire
                WHERE cp.id_contact = :c";
        $st = $pdo->prepare($sql);
        $st->bindValue(':c', $idContact, PDO::PARAM_INT);
        $st->execute();
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('inv_contact_analyses_ids')) {
    /**
     * Retourne tous les id_analyse accessibles à un contact (via id_bien_source).
     */
    function inv_contact_analyses_ids(PDO $pdo, int $idContact): array {
        $sql = "SELECT DISTINCT a.id
                FROM investisseur_contacts_proprietaires cp
                JOIN investisseur_analyses a ON a.id_proprietaire = cp.id_proprietaire
                WHERE cp.id_contact = :c";
        $st = $pdo->prepare($sql);
        $st->bindValue(':c', $idContact, PDO::PARAM_INT);
        $st->execute();
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PROPRIÉTAIRES DISPONIBLES (pour UI d'attribution)
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_contact_proprietaires_dispos')) {
    function inv_contact_proprietaires_dispos(PDO $pdo): array {
        $sql = "SELECT p.id, p.nom, p.prenom, p.societe,
                  (SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire = p.id) AS nb_biens
                FROM proprietaires p
                WHERE p.actif = 1
                ORDER BY p.societe, p.nom";
        $st = $pdo->query($sql);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
