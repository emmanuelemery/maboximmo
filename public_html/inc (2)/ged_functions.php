<?php
declare(strict_types=1);

/**
 * Ma GED Box V1 — Helpers métier (arborescence dynamique BDD)
 * ============================================================
 *
 * Fonctions utilitaires pour :
 *   - manipulation des dossiers (ged_folders) en CRUD safe
 *   - calcul/recalcul de path_cache + depth récursifs
 *   - génération nom canonique stable + slug + UUID
 *   - audit (ged_audit_log) et trace de sync (ged_arbo_sync_log)
 *
 * Conventions :
 *   - tenant_id = id_societe ($_SESSION['id_societe']) ou NULL = global
 *   - is_system = 1 = dossier intouchable (sauf super admin)
 *   - is_archived = 1 = soft delete, jamais de suppression physique
 *   - PDO via $GLOBALS['pdo'] (injecté par bootstrap.php)
 *
 * Mode AJOUT uniquement : ne touche jamais aux tables existantes.
 */

if (!function_exists('ged_pdo')) {
    function ged_pdo(): PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('GED: PDO indisponible (bootstrap.php non chargé)');
        }
        return $pdo;
    }
}

if (!function_exists('ged_current_tenant_id')) {
    /** Renvoie l'id société courant comme tenant_id, ou null si super admin global */
    function ged_current_tenant_id(): ?int
    {
        $id = (int)($_SESSION['id_societe'] ?? 0);
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('ged_current_user_id')) {
    function ged_current_user_id(): ?int
    {
        $id = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? 0);
        return $id > 0 ? $id : null;
    }
}

/**
 * Slugifie un nom (accent removal + lower + tirets/underscores).
 * Conserve les chiffres, transforme tout le reste en _.
 */
function ged_slugify(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';

    // Translittération sans dépendances
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($tr !== false) $name = $tr;
    }
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?? $name;
    $name = strtolower(trim($name, '_'));
    $name = preg_replace('/_+/', '_', $name) ?? $name;

    return mb_substr($name, 0, 120);
}

/**
 * Génère un UUID v4 (RFC 4122) côté PHP.
 * Évite la dépendance à uuid_create() ou ext-uuid.
 */
function ged_generate_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 1
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Reconstruit le path_cache d'un dossier en remontant l'arbre via parent_id.
 * Format : 'slug_parent/slug_courant' (slash-separated).
 *
 * Limite : 6 niveaux de remontée (sécurité anti-cycle).
 */
function ged_build_path_cache(int $folder_id): string
{
    $pdo = ged_pdo();
    $parts = [];
    $currentId = $folder_id;
    $maxDepth = 8; // 6 niveaux + marge

    while ($currentId > 0 && $maxDepth-- > 0) {
        $st = $pdo->prepare("SELECT slug, parent_id FROM ged_folders WHERE id = ? LIMIT 1");
        $st->execute([$currentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) break;
        array_unshift($parts, (string)$row['slug']);
        $currentId = (int)($row['parent_id'] ?? 0);
    }

    return implode('/', $parts);
}

/**
 * Recalcule path_cache + depth pour TOUS les dossiers d'un tenant
 * (ou pour tous si $tenant_id = null). Retourne le compteur traité.
 *
 * Utilisé par le bouton "Recalculer path_cache" de l'admin.
 */
function ged_recalculate_folder_tree(?int $tenant_id = null): int
{
    $pdo = ged_pdo();

    // Trace dans ged_arbo_sync_log
    $logId = 0;
    try {
        $st = $pdo->prepare("INSERT INTO ged_arbo_sync_log (tenant_id, actor_id, sync_type, scope, status)
                             VALUES (?, ?, 'recalc_path_cache', ?, 'running')");
        $st->execute([$tenant_id, ged_current_user_id(), $tenant_id === null ? 'global' : "tenant={$tenant_id}"]);
        $logId = (int)$pdo->lastInsertId();
    } catch (Throwable) {}

    $where = $tenant_id === null ? "" : "WHERE tenant_id = " . (int)$tenant_id;
    $sql = "SELECT id FROM ged_folders {$where} ORDER BY id ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    $errors = 0;
    $upd = $pdo->prepare("UPDATE ged_folders SET path_cache = ?, depth = ? WHERE id = ?");
    foreach ($rows as $id) {
        try {
            $path = ged_build_path_cache((int)$id);
            $depth = $path === '' ? 0 : (substr_count($path, '/'));
            $upd->execute([$path, $depth, (int)$id]);
            $count++;
        } catch (Throwable) {
            $errors++;
        }
    }

    if ($logId > 0) {
        try {
            $pdo->prepare("UPDATE ged_arbo_sync_log
                           SET finished_at = NOW(), nb_folders_processed = ?, nb_errors = ?,
                               status = ?
                           WHERE id = ?")
                ->execute([$count, $errors, $errors > 0 ? 'error' : 'done', $logId]);
        } catch (Throwable) {}
    }

    return $count;
}

/**
 * Crée un dossier dans ged_folders.
 *
 * $data attendu :
 *   - parent_id      (int|null)  parent direct, NULL = racine
 *   - name_display   (string)    nom humain (obligatoire)
 *   - slug           (string|null) slug ; si null, généré depuis name_display
 *   - module         (string|null)
 *   - scope          (string|null) global|societe|agence|service|entity (def. global)
 *   - source_type    (string|null) auto|manual (def. manual)
 *   - entity_type    (string|null)
 *   - entity_id      (int|null)
 *   - societe_id     (int|null)
 *   - agence_id      (int|null)
 *   - service_id     (int|null)
 *   - tenant_id      (int|null)   def. ged_current_tenant_id()
 *   - position       (int|null)   def. dernière + 10
 *   - is_system      (bool|null)  def. false (réservé super admin)
 *   - gdrive_folder_id (string|null)
 *
 * @return int id du dossier créé
 * @throws RuntimeException si nom vide / slug en doublon / parent inexistant
 */
function ged_create_folder(array $data): int
{
    $pdo = ged_pdo();

    $name = trim((string)($data['name_display'] ?? ''));
    if ($name === '') throw new RuntimeException('name_display obligatoire');

    $parentId = isset($data['parent_id']) && (int)$data['parent_id'] > 0 ? (int)$data['parent_id'] : null;
    $tenantId = array_key_exists('tenant_id', $data) ? (is_null($data['tenant_id']) ? null : (int)$data['tenant_id']) : ged_current_tenant_id();
    $slug = ged_slugify((string)($data['slug'] ?? $name));
    if ($slug === '') $slug = 'dossier_' . substr(ged_generate_uuid(), 0, 8);

    // Vérifie parent + calcule depth
    $depth = 0;
    if ($parentId !== null) {
        $st = $pdo->prepare("SELECT id, depth FROM ged_folders WHERE id = ? LIMIT 1");
        $st->execute([$parentId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException("Parent introuvable : {$parentId}");
        $depth = (int)$p['depth'] + 1;
        if ($depth > 6) throw new RuntimeException('Profondeur maximale (6) dépassée');
    }

    // Position : dernière + 10 si non fournie
    $position = $data['position'] ?? null;
    if ($position === null) {
        $st = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 10 FROM ged_folders WHERE " .
            ($parentId === null ? "parent_id IS NULL" : "parent_id = ?"));
        if ($parentId === null) $st->execute(); else $st->execute([$parentId]);
        $position = (int)$st->fetchColumn();
    }

    $uuid = ged_generate_uuid();
    $module = $data['module'] ?? null;
    $scope = $data['scope'] ?? 'global';
    $sourceType = $data['source_type'] ?? 'manual';
    $isSystem = !empty($data['is_system']) ? 1 : 0;

    // Nom canonique : si non fourni, dérivé du slug en MAJ + scope éventuel
    $nameCanonical = (string)($data['name_canonical'] ?? '');
    if ($nameCanonical === '') $nameCanonical = strtoupper($slug);

    $st = $pdo->prepare("
        INSERT INTO ged_folders
            (uuid, tenant_id, parent_id, depth, path_cache, slug, name_display, name_canonical,
             module, scope, source_type, entity_type, entity_id,
             societe_id, agence_id, service_id, gdrive_folder_id, position, is_system,
             created_by, updated_by)
        VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    try {
        $st->execute([
            $uuid, $tenantId, $parentId, $depth,
            $slug, $name, $nameCanonical,
            $module, $scope, $sourceType,
            $data['entity_type'] ?? null,
            isset($data['entity_id']) ? (int)$data['entity_id'] : null,
            isset($data['societe_id']) ? (int)$data['societe_id'] : null,
            isset($data['agence_id'])  ? (int)$data['agence_id']  : null,
            isset($data['service_id']) ? (int)$data['service_id'] : null,
            $data['gdrive_folder_id'] ?? null,
            $position, $isSystem,
            ged_current_user_id(), ged_current_user_id(),
        ]);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) {
            throw new RuntimeException("Un dossier avec le slug '{$slug}' existe déjà sous ce parent");
        }
        throw $e;
    }

    $id = (int)$pdo->lastInsertId();

    // Calcule + persiste path_cache
    $path = ged_build_path_cache($id);
    $pdo->prepare("UPDATE ged_folders SET path_cache = ? WHERE id = ?")->execute([$path, $id]);

    ged_audit('create_folder', 'folder', $id, null, [
        'uuid' => $uuid, 'name' => $name, 'slug' => $slug, 'parent_id' => $parentId, 'path' => $path,
    ]);

    return $id;
}

/**
 * Met à jour un dossier existant (subset des champs autorisés).
 * Retourne true si modifié, false si inchangé.
 * Refuse de modifier un dossier is_system sauf si super admin (id_role=1).
 */
function ged_update_folder(int $id, array $data): bool
{
    $pdo = ged_pdo();
    $st = $pdo->prepare("SELECT * FROM ged_folders WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $before = $st->fetch(PDO::FETCH_ASSOC);
    if (!$before) throw new RuntimeException("Dossier {$id} introuvable");

    $isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
    if ((int)$before['is_system'] === 1 && !$isSuperAdmin) {
        throw new RuntimeException('Dossier système : modification réservée au super admin');
    }

    $allowed = [
        'name_display', 'slug', 'module', 'scope', 'entity_type', 'entity_id',
        'societe_id', 'agence_id', 'service_id', 'gdrive_folder_id', 'position',
        'parent_id', 'is_archived',
    ];
    $sets = [];
    $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $data)) continue;
        $val = $data[$k];
        if ($k === 'slug' && is_string($val)) $val = ged_slugify($val);
        $sets[] = "`{$k}` = ?";
        $params[] = $val;
    }
    if (!$sets) return false;

    // updated_by
    $sets[] = "`updated_by` = ?";
    $params[] = ged_current_user_id();

    $params[] = $id;
    $sql = "UPDATE ged_folders SET " . implode(', ', $sets) . " WHERE id = ?";
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) {
            throw new RuntimeException('Conflit de slug sous ce parent');
        }
        throw $e;
    }

    // Si parent_id ou slug a changé : recalculer path_cache + depth (et descendants)
    if (array_key_exists('parent_id', $data) || array_key_exists('slug', $data)) {
        ged_recalc_subtree($id);
    }

    $st->execute([$id]);
    $after = $st->fetch(PDO::FETCH_ASSOC);

    ged_audit('update_folder', 'folder', $id, $before, $after);
    return true;
}

/** Recalcule path_cache + depth d'un dossier ET de tous ses descendants. */
function ged_recalc_subtree(int $folderId): int
{
    $pdo = ged_pdo();
    $count = 0;
    $stack = [$folderId];

    while ($stack) {
        $cur = array_pop($stack);
        $path = ged_build_path_cache((int)$cur);
        $depth = $path === '' ? 0 : substr_count($path, '/');
        $pdo->prepare("UPDATE ged_folders SET path_cache = ?, depth = ? WHERE id = ?")
            ->execute([$path, $depth, $cur]);
        $count++;

        $children = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ?");
        $children->execute([$cur]);
        foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $stack[] = (int)$cid;
        }
    }
    return $count;
}

/**
 * Archive un dossier (soft delete). Pas de suppression physique.
 * Refuse si dossier système (sauf super admin).
 */
function ged_archive_folder(int $id): bool
{
    $pdo = ged_pdo();
    $st = $pdo->prepare("SELECT id, is_system, is_archived FROM ged_folders WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Dossier {$id} introuvable");

    $isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
    if ((int)$row['is_system'] === 1 && !$isSuperAdmin) {
        throw new RuntimeException('Dossier système : archivage réservé au super admin');
    }

    $pdo->prepare("UPDATE ged_folders SET is_archived = 1, updated_by = ? WHERE id = ?")
        ->execute([ged_current_user_id(), $id]);

    ged_audit('archive_folder', 'folder', $id, $row, ['is_archived' => 1]);
    return true;
}

/**
 * Renvoie l'arborescence complète sous forme d'un tableau hiérarchique.
 *
 * Filtre par tenant_id : NULL = système global + ceux du tenant courant.
 * Inclut/exclut les archivés selon $includeArchived.
 *
 * Format retourné :
 *   [ {id, uuid, parent_id, depth, name_display, slug, path_cache, module,
 *      is_system, is_archived, position, children: [...]}, ... ]
 */
function ged_get_tree(?int $tenant_id = null, bool $includeArchived = false): array
{
    $pdo = ged_pdo();
    $where = $tenant_id === null
        ? "tenant_id IS NULL"
        : "(tenant_id IS NULL OR tenant_id = " . (int)$tenant_id . ")";
    if (!$includeArchived) $where .= " AND is_archived = 0";

    $sql = "SELECT id, uuid, tenant_id, parent_id, depth, path_cache, slug,
                   name_display, name_canonical, module, scope, source_type,
                   entity_type, entity_id, societe_id, agence_id, service_id,
                   gdrive_folder_id, position, is_system, is_archived
            FROM ged_folders
            WHERE {$where}
            ORDER BY parent_id IS NULL DESC, parent_id ASC, position ASC, name_display ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Indexation parent → enfants
    $byParent = [];
    foreach ($rows as $r) {
        $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
        $byParent[$pid][] = $r;
    }

    $build = static function (int $parentId) use (&$build, &$byParent): array {
        $out = [];
        foreach (($byParent[$parentId] ?? []) as $node) {
            $node['children'] = $build((int)$node['id']);
            $out[] = $node;
        }
        return $out;
    };

    return $build(0);
}

/**
 * Génère un nom canonique stable pour un document — format MÉTIER MBI.
 *
 * Format : TYPE_DATE_ENTITE_AGENCE_SOCIETE
 * Exemple : BAIL_2026-05-01_BIEN_12_RUE_VICTOR_HUGO_LYON_RE
 *
 * @param string      $type        Type de doc (BAIL, FACT, EDL, MANDAT…)
 * @param string|null $date        Date du doc (parseable strtotime, sortie YYYY-MM-DD)
 * @param string|null $entityLabel Label métier de l'entité (ex: "12 Rue Victor Hugo Lyon")
 * @param string|null $agenceCode  Code agence (ex: "RE")
 * @param string|null $societeCode Code société (ex: "EM")
 *
 * Le user ne saisit JAMAIS le name_canonical : il est généré automatiquement
 * à partir des entités liées + métadonnées du document.
 */
function ged_generate_canonical_name(
    string $type,
    ?string $date = null,
    ?string $entityLabel = null,
    ?string $agenceCode = null,
    ?string $societeCode = null
): string {
    $parts = [];

    $type = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $type) ?: 'DOC');
    $parts[] = $type;

    if ($date) {
        $ts = strtotime((string)$date);
        if ($ts) $parts[] = date('Y-m-d', $ts);
    }

    if ($entityLabel !== null && $entityLabel !== '') {
        $entitySlug = ged_slugify($entityLabel);
        $entitySlug = strtoupper(str_replace('_', '_', $entitySlug));
        if ($entitySlug !== '') $parts[] = $entitySlug;
    }

    if ($agenceCode !== null && $agenceCode !== '') {
        $parts[] = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $agenceCode));
    }
    if ($societeCode !== null && $societeCode !== '') {
        $parts[] = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $societeCode));
    }

    return implode('_', array_filter($parts, static fn($s) => $s !== ''));
}

/**
 * Instancie un template d'arborescence (ged_folder_templates) sous un dossier
 * parent existant pour une entité métier (propriétaire, bien, locataire).
 *
 * Crée le dossier-racine "{LABEL}" sous $parentFolderId, puis tous les
 * sous-dossiers définis dans ged_folder_template_nodes.
 *
 * Idempotent : si un dossier portant le même slug existe déjà sous le parent,
 * il est réutilisé (on n'en recrée pas un doublon).
 *
 * @param string $templateCode  Code du template (ex: 'TPL_PROPRIETAIRE')
 * @param int    $parentFolderId ID du dossier parent (ex: 01_PROPRIETAIRES)
 * @param string $entityLabel   Label humain de l'entité (ex: "DUPONT Jean")
 * @param string $entityType    Type métier (ex: "proprietaire", "bien", "locataire")
 * @param int    $entityId      ID dans la table métier (proprietaires.id, biens.id, …)
 * @return int                  ID du dossier racine de l'entité (créé ou réutilisé)
 */
function ged_instantiate_template_for_entity(
    string $templateCode,
    int $parentFolderId,
    string $entityLabel,
    string $entityType,
    int $entityId
): int {
    $pdo = ged_pdo();

    // 1. Récupère le template
    $st = $pdo->prepare("SELECT id, module FROM ged_folder_templates WHERE code = ? AND is_active = 1 LIMIT 1");
    $st->execute([$templateCode]);
    $tpl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) throw new RuntimeException("Template introuvable ou inactif : {$templateCode}");

    $module = (string)($tpl['module'] ?? 'GESTION_LOCATIVE');
    $tplId = (int)$tpl['id'];

    // 2. Slug de l'entité racine (ex: "DUPONT Jean" → "dupont_jean")
    $entitySlug = ged_slugify($entityLabel);
    if ($entitySlug === '') $entitySlug = strtolower($entityType) . '_' . $entityId;

    // 3. Cherche si le dossier racine existe déjà (idempotence)
    $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = ? LIMIT 1");
    $st->execute([$parentFolderId, $entitySlug]);
    $rootId = (int)($st->fetchColumn() ?: 0);

    if ($rootId === 0) {
        $rootId = ged_create_folder([
            'parent_id'      => $parentFolderId,
            'name_display'   => $entityLabel,
            'slug'           => $entitySlug,
            'module'         => $module,
            'scope'          => 'entity',
            'source_type'    => 'auto',
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
        ]);
        // Met folder_kind = business_view (le helper crée en business_view par défaut
        // mais si la migration ALTER vient de passer, force le bon type)
        try {
            $pdo->prepare("UPDATE ged_folders SET folder_kind = 'business_view', is_virtual = 1 WHERE id = ?")
                ->execute([$rootId]);
        } catch (Throwable) {}
    }

    // 4. Instancie les nœuds du template (sous-dossiers fixes : 01_ADMIN, 02_MANDATS, …)
    $nodes = $pdo->prepare("
        SELECT slug, name_display, position, parent_node_id, depth
        FROM ged_folder_template_nodes
        WHERE template_id = ?
        ORDER BY depth ASC, position ASC, id ASC
    ");
    $nodes->execute([$tplId]);
    $nodeRows = $nodes->fetchAll(PDO::FETCH_ASSOC);

    // V1 : tous les nodes sont à plat sous le root (depth 0). Pour V2 : récursif.
    foreach ($nodeRows as $node) {
        $childSlug = (string)$node['slug'];
        $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = ? LIMIT 1");
        $st->execute([$rootId, $childSlug]);
        if ((int)$st->fetchColumn() > 0) continue; // déjà créé

        $childId = ged_create_folder([
            'parent_id'      => $rootId,
            'name_display'   => (string)$node['name_display'],
            'slug'           => $childSlug,
            'module'         => $module,
            'scope'          => 'entity',
            'source_type'    => 'auto',
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'position'       => (int)$node['position'],
        ]);
        try {
            $pdo->prepare("UPDATE ged_folders SET folder_kind = 'business_view', is_virtual = 1 WHERE id = ?")
                ->execute([$childId]);
        } catch (Throwable) {}
    }

    ged_audit('instantiate_template', 'folder', $rootId, null, [
        'template_code' => $templateCode,
        'entity_type'   => $entityType,
        'entity_id'     => $entityId,
        'entity_label'  => $entityLabel,
    ]);

    return $rootId;
}

/**
 * Lie un document à une entité métier (proprietaire, bien, locataire, mandat,
 * fournisseur, sinistre, etc.) via ged_document_links — sans dupliquer le doc.
 *
 * Un même document peut être lié à plusieurs entités (apparition dans
 * plusieurs vues métier). Chaque lien a un link_role (principal/secondaire).
 *
 * @param int    $documentId ID du document (ged_documents.id)
 * @param string $entityType "proprietaire" | "bien" | "locataire" | "mandat" | "fournisseur" | "sinistre" | …
 * @param int    $entityId   ID dans la table métier
 * @param string $linkRole   "principal" (défaut) | "secondaire" | "piece_jointe" | "reference"
 * @return int               ID du lien créé (ou existant)
 */
function ged_link_document_to_entity(
    int $documentId,
    string $entityType,
    int $entityId,
    string $linkRole = 'principal'
): int {
    if ($documentId <= 0 || $entityType === '' || $entityId <= 0) {
        throw new InvalidArgumentException('Paramètres invalides pour ged_link_document_to_entity');
    }
    $pdo = ged_pdo();
    $tenant = ged_current_tenant_id();

    // Idempotence : (document_id, entity_type, entity_id, relation_type) en UNIQUE.
    // On utilise relation_type = 'main' pour rester compat ; link_role en colonne séparée.
    $relationType = 'main';

    $st = $pdo->prepare("SELECT id FROM ged_document_links
                         WHERE document_id = ? AND entity_type = ? AND entity_id = ? AND relation_type = ?
                         LIMIT 1");
    $st->execute([$documentId, $entityType, $entityId, $relationType]);
    $existing = (int)($st->fetchColumn() ?: 0);
    if ($existing > 0) {
        // Met à jour link_role si différent
        try {
            $pdo->prepare("UPDATE ged_document_links SET link_role = ? WHERE id = ?")
                ->execute([$linkRole, $existing]);
        } catch (Throwable) {}
        return $existing;
    }

    try {
        $pdo->prepare("
            INSERT INTO ged_document_links
                (tenant_id, document_id, entity_type, entity_id, relation_type, link_role, is_validated)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ")->execute([$tenant, $documentId, $entityType, $entityId, $relationType, $linkRole]);
    } catch (PDOException $e) {
        // Si link_role n'existe pas (migration 06 pas appliquée), retry sans
        $pdo->prepare("
            INSERT INTO ged_document_links
                (tenant_id, document_id, entity_type, entity_id, relation_type, is_validated)
            VALUES (?, ?, ?, ?, ?, 1)
        ")->execute([$tenant, $documentId, $entityType, $entityId, $relationType]);
    }

    $linkId = (int)$pdo->lastInsertId();
    ged_audit('link_document', 'document', $documentId, null, [
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'link_role'   => $linkRole,
    ]);
    return $linkId;
}

/**
 * Renvoie tous les documents liés à une entité métier donnée.
 *
 * @return array Liste de documents (jointure ged_documents + ged_document_links)
 */
function ged_get_documents_for_entity(string $entityType, int $entityId, ?int $limit = 100): array
{
    $pdo = ged_pdo();
    $sql = "
        SELECT d.id, d.uuid, d.name_display, d.name_canonical, d.name_file,
               d.document_type, d.source_module, d.mime_type, d.size_bytes,
               d.confidence_score, d.status, d.version,
               d.folder_id, d.created_at,
               l.link_role, l.is_validated, l.confidence AS link_confidence
        FROM ged_document_links l
        JOIN ged_documents d ON d.id = l.document_id
        WHERE l.entity_type = ? AND l.entity_id = ?
          AND d.status <> 'deleted'
        ORDER BY d.created_at DESC, d.id DESC
        LIMIT " . max(1, (int)$limit);
    $st = $pdo->prepare($sql);
    $st->execute([$entityType, $entityId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Inscrit une action dans ged_audit_log. Best-effort (n'échoue jamais).
 */
function ged_audit(string $action, string $target_type, $target_id, $before = null, $after = null, ?string $message = null): void
{
    try {
        $pdo = ged_pdo();
        $pdo->prepare("
            INSERT INTO ged_audit_log
                (tenant_id, actor_id, actor_role, action, target_type, target_id,
                 before_json, after_json, message, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            ged_current_tenant_id(),
            ged_current_user_id(),
            (string)($_SESSION['role_code'] ?? $_SESSION['id_role'] ?? ''),
            $action,
            $target_type,
            is_numeric($target_id) ? (int)$target_id : null,
            $before === null ? null : (is_string($before) ? $before : json_encode($before, JSON_UNESCAPED_UNICODE)),
            $after  === null ? null : (is_string($after)  ? $after  : json_encode($after,  JSON_UNESCAPED_UNICODE)),
            $message,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[ged_audit] ' . $e->getMessage());
    }
}
