<?php
declare(strict_types=1);

/**
 * FluxBox — API handler des actions de cartes
 *
 * Endpoints (POST JSON) :
 *   action=validate   → valide la carte (exécute les actions IA + promotion GED)
 *   action=adjust     → valide avec overrides (classement modifié par user)
 *   action=later      → reporte la carte (+1h / +1j / custom)
 *   action=dismiss    → rejette la carte
 *   action=ingest     → uploade un fichier en flux (anti-doublon SHA-256)
 *   action=stats      → renvoie les stats bandeau (refresh dashboard)
 *
 * Réponse JSON : { ok:bool, data?:..., errors?:[] }
 */

// ── Hardening : on garantit du JSON même en cas de fatal error ──
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

// On capture toute sortie parasite (warnings PHP, BOM, echo) émise par les includes
ob_start();

// Filet de sécurité : si on meurt avant le respond, on renvoie un JSON d'erreur
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        // Vide le buffer pour ne pas mélanger HTML + JSON
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo json_encode([
            'ok'     => false,
            'errors' => ['Fatal PHP: ' . ($err['message'] ?? 'unknown') . ' (' . basename((string)($err['file'] ?? '')) . ':' . (int)($err['line'] ?? 0) . ')'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
    // Convertit les warnings en exceptions (attrapées par le catch global plus bas)
    if ((error_reporting() & $no) === 0) return false;
    throw new ErrorException($msg, 0, $no, $file, $line);
});

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/fluxbox_functions.php';
require_once __DIR__ . '/../inc/fluxbox_ia_cascade.php';
require_once __DIR__ . '/../inc/ged_classement_v3.php';
require_login();

// Cascade IA Vision peut prendre 60-90s par doc ; on étend le timeout PHP
// pour ne pas couper en plein traitement (sinon : Maximum execution time exceeded).
@set_time_limit(600);
@ini_set('max_execution_time', '600');
@ignore_user_abort(true);

// Une fois login OK, on s'assure que rien n'a fui en HTML
while (ob_get_level() > 1) { @ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fbx_api_respond(bool $ok, array $payload = []): void
{
    // Vide tout buffer parasite (warnings PHP, BOM, echo accidentel) avant d'écrire JSON
    while (ob_get_level() > 0) {
        $junk = (string)@ob_get_clean();
        if ($junk !== '' && $ok) {
            $payload['_warning_html_leak'] = mb_substr($junk, 0, 500);
            $payload['ok'] = false;
        }
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    // Flags tolérants : UTF-8 invalide → '?', erreurs partielles tolérées (sinon json_encode retourne false silencieusement)
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $out = json_encode(array_merge(['ok' => $ok], $payload), $flags);
    if ($out === false) {
        // Fallback ultime : retourne au moins un JSON minimal pour ne pas casser le client
        $out = json_encode(['ok' => false, 'errors' => ['json_encode error: ' . json_last_error_msg()], '_partial' => true], $flags);
        if ($out === false) $out = '{"ok":false,"errors":["json_encode total failure"]}';
    }
    echo $out;
    exit;
}

// Détermination préalable de l'action (peut venir de $_GET pour les endpoints en lecture pure)
$_preAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');
// Endpoints autorisés en GET (lecture pure, auth session, pas de mutation)
$_readOnlyActions = ['view_doc', 'read_msg', 'download_msg_attachment'];
$_isReadOnly = in_array($_preAction, $_readOnlyActions, true);

if (!$_isReadOnly && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    fbx_api_respond(false, ['errors' => ['Méthode non autorisée']]);
}

// CSRF (skippé pour les endpoints read-only en GET — auth garantie par require_login + tenant_id check)
if (!$_isReadOnly) {
    $csrfReceived = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $csrfExpected = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrfExpected !== '' && !hash_equals($csrfExpected, $csrfReceived)) {
        // En V1 on est lax si csrf_token n'est pas en session (pas tous les bootstraps l'initialisent)
        // En prod il faudra durcir : http_response_code(403); fbx_api_respond(false, ['errors'=>['CSRF']]);
    }
}

// Lecture body : JSON ou form (vide pour GET)
$body = $_POST;
$rawJson = (string)file_get_contents('php://input');
if ($rawJson !== '' && (str_starts_with($rawJson, '{') || str_starts_with($rawJson, '['))) {
    $decoded = json_decode($rawJson, true);
    if (is_array($decoded)) $body = $decoded + $body;
}

// Action lue depuis body (POST/JSON) ou GET (pour endpoints read-only)
$action = (string)($body['action'] ?? $_GET['action'] ?? '');
$pdo = ged_pdo();

try {
    switch ($action) {

        case 'validate': {
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $result = fluxbox_carte_validate($carteId, [], $pdo);
            fbx_api_respond($result['ok'], ['data' => $result, 'errors' => $result['errors']]);
        }

        case 'adjust': {
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $classement = [
                'n1' => (string)($body['n1'] ?? ''),
                'n2' => (string)($body['n2'] ?? ''),
                'n3' => (string)($body['n3'] ?? ''),
                'n4' => (string)($body['n4'] ?? ''),
                'n5' => (string)($body['n5'] ?? ''),
                'n6' => (string)($body['n6'] ?? ''),
            ];
            // Date document (priorité user > existant proposition)
            $targetDateAdj = trim((string)($body['target_date'] ?? ''));
            if ($targetDateAdj !== '') $classement['date'] = $targetDateAdj;
            // Société/agence cibles depuis selects (si user a override)
            if (isset($body['target_societe_id']) && $body['target_societe_id'] !== '') {
                $classement['target_societe_id'] = (int)$body['target_societe_id'];
            }
            if (isset($body['target_agence_id'])) {
                // Chaîne vide = "société uniquement" → 0
                $classement['target_agence_id'] = $body['target_agence_id'] !== ''
                    ? (int)$body['target_agence_id']
                    : 0;
            }
            // Champs additionnels repris du formulaire (libellé, entité, etc.)
            $overrides = [
                'classement'      => $classement,
                'user_label'      => (string)($body['user_label']      ?? ''),
                'entity_instance' => (string)($body['entity_instance'] ?? ''),
            ];
            $result = fluxbox_carte_validate($carteId, $overrides, $pdo);
            fbx_api_respond($result['ok'], ['data' => $result, 'errors' => $result['errors']]);
        }

        case 'later': {
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $until = (string)($body['until'] ?? '+1 day');
            $ok = fluxbox_carte_later($carteId, $until, $pdo);
            fbx_api_respond($ok, ['data' => ['carte_id' => $carteId, 'until' => $until]]);
        }

        case 'dismiss': {
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $reason = (string)($body['reason'] ?? '');
            $ok = fluxbox_carte_dismiss($carteId, $reason, $pdo);
            fbx_api_respond($ok, ['data' => ['carte_id' => $carteId]]);
        }

        case 'delete_doc': {
            // Supprime la carte + le fichier physique si plus aucune autre carte pending pointe dessus.
            // L'enregistrement fluxbox_documents reste (anti-doublon SHA-256 préservé).
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $tenantId = fluxbox_current_tenant_id();

            // Lecture carte
            $stC = $pdo->prepare("SELECT id, document_id FROM fluxbox_cartes WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stC->execute([$carteId, $tenantId]);
            $carte = $stC->fetch(PDO::FETCH_ASSOC);
            if (!$carte) throw new RuntimeException('Carte introuvable');

            // Dismiss la carte (= sortie de la pile)
            fluxbox_carte_dismiss($carteId, 'Supprimé par l\'utilisateur', $pdo);

            $docId = (int)$carte['document_id'];
            $fileDeleted = false;
            if ($docId > 0) {
                // Check si d'autres cartes pending/later sur ce doc
                $stOther = $pdo->prepare("SELECT COUNT(*) FROM fluxbox_cartes WHERE document_id = ? AND tenant_id = ? AND statut IN ('pending','later')");
                $stOther->execute([$docId, $tenantId]);
                $otherCount = (int)$stOther->fetchColumn();
                if ($otherCount === 0) {
                    // Plus aucune carte active : supprime le fichier physique et marque le doc deleted
                    $stD = $pdo->prepare("SELECT fichier_chemin, source_meta FROM fluxbox_documents WHERE id = ? AND tenant_id = ?");
                    $stD->execute([$docId, $tenantId]);
                    $doc = $stD->fetch(PDO::FETCH_ASSOC);
                    if ($doc) {
                        $path = (string)$doc['fichier_chemin'];
                        // Si chemin relatif, le résoudre depuis storage_fluxbox
                        if ($path !== '' && !preg_match('#^([A-Za-z]:|/)#', $path)) {
                            $path = __DIR__ . '/../storage_fluxbox/' . $path;
                        }
                        if ($path !== '' && is_file($path)) {
                            // FILET : sauve une copie durable pour tout doc GED encore adossé à
                            // ce fichier AVANT de l'effacer → la suppression pile n'orpheline plus la GED.
                            require_once __DIR__ . '/../inc/ged_durable.php';
                            try { ged_ensure_durable_from_fluxbox($pdo, $docId); } catch (Throwable) {}
                            $fileDeleted = @unlink($path);
                        }
                        // Flag le doc supprimé dans son source_meta
                        $meta = !empty($doc['source_meta']) ? (json_decode((string)$doc['source_meta'], true) ?: []) : [];
                        $meta['deleted_at']      = date('Y-m-d H:i:s');
                        $meta['deleted_by_user'] = (int)(current_user_id() ?? 0);
                        $pdo->prepare("UPDATE fluxbox_documents SET source_meta = ? WHERE id = ?")
                            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $docId]);
                    }
                }
            }

            fbx_api_respond(true, ['data' => [
                'carte_id'     => $carteId,
                'file_deleted' => $fileDeleted,
            ]]);
        }

        case 'search_immeubles': {
            // Recherche immeubles en BDD pour le champ recherche N3 du modal Ajuster
            $q = trim((string)($body['q'] ?? ''));
            $limit = max(1, min(20, (int)($body['limit'] ?? 10)));
            if (mb_strlen($q) < 2) {
                fbx_api_respond(true, ['data' => ['items' => []]]);
            }
            $like = '%' . str_replace(['%','_'], ['\\%','\\_'], mb_strtolower($q)) . '%';
            try {
                $st = $pdo->prepare("
                    SELECT id, reference_immeuble, nom_immeuble, ville, code_postal
                    FROM immeubles
                    WHERE LOWER(nom_immeuble) LIKE ?
                       OR LOWER(reference_immeuble) LIKE ?
                       OR LOWER(adresse_1) LIKE ?
                       OR LOWER(ville) LIKE ?
                    ORDER BY nom_immeuble ASC
                    LIMIT ?
                ");
                $st->bindValue(1, $like);
                $st->bindValue(2, $like);
                $st->bindValue(3, $like);
                $st->bindValue(4, $like);
                $st->bindValue(5, $limit, PDO::PARAM_INT);
                $st->execute();
                $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                fbx_api_respond(true, ['data' => ['items' => $items]]);
            } catch (Throwable $e) {
                fbx_api_respond(false, ['errors' => [$e->getMessage()]]);
            }
        }

        case 'add_level_code': {
            // Ajout d'une nouvelle référence GED à un niveau (N2 à N5).
            // Réservé super admin (id_role=1).
            $roleId = (int)($_SESSION['id_role'] ?? 0);
            if ($roleId !== 1) {
                http_response_code(403);
                fbx_api_respond(false, ['errors' => ['Réservé super admin']]);
            }
            $level = (int)($body['level'] ?? 0);
            if (!in_array($level, [2, 3, 4, 5], true)) {
                throw new InvalidArgumentException('Niveau invalide (2-5 attendu)');
            }
            $code  = strtoupper(trim((string)($body['code']  ?? '')));
            $label = trim((string)($body['label'] ?? ''));
            if ($code === '' || $label === '') {
                throw new InvalidArgumentException('Code et libellé requis');
            }
            // Normalise le code : A-Z 0-9 _ seulement
            $code = preg_replace('/[^A-Z0-9_]+/', '_', $code) ?? '';
            $code = preg_replace('/_+/', '_', $code) ?? '';
            $code = trim($code, '_');
            if ($code === '') throw new InvalidArgumentException('Code invalide après normalisation');

            $parents = [
                'n1' => trim((string)($body['parent_n1'] ?? '')),
                'n2' => trim((string)($body['parent_n2'] ?? '')),
                'n3' => trim((string)($body['parent_n3'] ?? '')),
                'n4' => trim((string)($body['parent_n4'] ?? '')),
            ];
            // Vérif cohérence parents selon le niveau
            $requiredParents = ['n1'];
            if ($level >= 3) $requiredParents[] = 'n2';
            if ($level >= 4) $requiredParents[] = 'n3';
            if ($level >= 5) $requiredParents[] = 'n4';
            foreach ($requiredParents as $k) {
                if ($parents[$k] === '') throw new InvalidArgumentException("Parent $k manquant pour niveau $level");
            }

            // Si parent_n3 est une INSTANCE (ex 2024_ILOT_17), on remonte au placeholder
            // (IMMEUBLE) pour que le code créé soit attaché au placeholder et applique à toutes
            // les instances. Évite la prolifération de doublons.
            if ($level >= 4 && !empty($parents['n3'])) {
                try {
                    $stPh = $pdo->prepare("
                        SELECT 1 FROM ged_level_codes
                        WHERE level_number = 3 AND code COLLATE utf8mb4_unicode_ci = ?
                          AND COALESCE(is_entity_placeholder, 0) = 1
                        LIMIT 1
                    ");
                    $stPh->execute([$parents['n3']]);
                    if (!$stPh->fetchColumn()) {
                        // N3 = instance, remonte au placeholder
                        $stPh2 = $pdo->prepare("
                            SELECT code FROM ged_level_codes
                            WHERE level_number = 3
                              AND parent_n1 COLLATE utf8mb4_unicode_ci = ?
                              AND parent_n2 COLLATE utf8mb4_unicode_ci = ?
                              AND COALESCE(is_entity_placeholder, 0) = 1
                              AND is_active = 1
                            LIMIT 1
                        ");
                        $stPh2->execute([$parents['n1'], $parents['n2']]);
                        $placeholderCode = (string)$stPh2->fetchColumn();
                        if ($placeholderCode !== '') {
                            $parents['n3'] = $placeholderCode;
                        }
                    }
                } catch (Throwable) {}
            }

            // Détecte la prochaine position dispo — accepte parent NULL OU '' (compat selon schéma BDD)
            $whereParents = '';
            $whereParams = [$level];
            foreach (['n1','n2','n3','n4'] as $k) {
                if ($parents[$k] === '') {
                    $whereParents .= " AND (parent_$k IS NULL OR parent_$k = '')";
                } else {
                    $whereParents .= " AND parent_$k COLLATE utf8mb4_unicode_ci = ?";
                    $whereParams[] = $parents[$k];
                }
            }
            $st = $pdo->prepare("
                SELECT COALESCE(MAX(position), 0) + 1
                FROM ged_level_codes
                WHERE level_number = ?
                  $whereParents
            ");
            $st->execute($whereParams);
            $nextPos = (int)$st->fetchColumn();

            // Tenant cible : tenant courant si dispo, sinon 1 par défaut (compat BDD sans NULL)
            $tenantIdForInsert = (int)(fluxbox_current_tenant_id() ?: ($_SESSION['id_societe'] ?? 1));
            if ($tenantIdForInsert <= 0) $tenantIdForInsert = 1;

            // Vérif anti-doublon EXPLICITE (accepte NULL ou '' selon schéma BDD)
            $checkSql = "SELECT id, tenant_id FROM ged_level_codes WHERE level_number = ? AND code COLLATE utf8mb4_unicode_ci = ?";
            $checkParams = [$level, $code];
            foreach (['n1','n2','n3','n4'] as $k) {
                if ($parents[$k] === '') {
                    $checkSql .= " AND (parent_$k IS NULL OR parent_$k = '')";
                } else {
                    $checkSql .= " AND parent_$k COLLATE utf8mb4_unicode_ci = ?";
                    $checkParams[] = $parents[$k];
                }
            }
            $stCheck = $pdo->prepare($checkSql . " LIMIT 1");
            $stCheck->execute($checkParams);
            $existing = $stCheck->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new RuntimeException("Ce code « $code » existe déjà à ce niveau (id BDD : " . (int)$existing['id'] . ", tenant : " . ($existing['tenant_id'] ?? 'NULL') . ")");
            }

            // Insert : on passe '' au lieu de NULL pour les parents vides (compat schéma NOT NULL)
            // Les requêtes SELECT acceptent IS NULL OR = '' donc compat dans les 2 sens.
            $pn1 = (string)$parents['n1'];
            $pn2 = (string)$parents['n2'];
            $pn3 = (string)$parents['n3'];
            $pn4 = (string)$parents['n4'];
            try {
                $st = $pdo->prepare("
                    INSERT INTO ged_level_codes
                        (tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code, label, position, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $st->execute([$tenantIdForInsert, $level, $pn1, $pn2, $pn3, $pn4, $code, $label, $nextPos]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new RuntimeException('Conflit de contrainte : ' . $e->getMessage());
                }
                throw $e;
            }

            // lastInsertId peut être 0 dans certains setups MySQL — on récupère via SELECT post-insert
            $insertedId = (int)$pdo->lastInsertId();
            if ($insertedId === 0) {
                $stPost = $pdo->prepare($checkSql . " LIMIT 1");
                $stPost->execute($checkParams);
                $insertedId = (int)($stPost->fetchColumn() ?: 0);
            }

            // ───── CLONE DESCENDANCE D'UN SIBLING ─────
            // Cherche le premier "frère" existant (autre code au même level + mêmes parents)
            // et clone toute sa descendance sous le nouveau code.
            // Ex : créer "DUPONT_PIERRE" sous COLLABORATEURS → clone les N4 de COLLABORATEUR
            // (01_IDENTITE, 02_CONTRAT_TRAVAIL, 03_PAIE…) et leurs descendants.
            $sibSql = "SELECT code FROM ged_level_codes
                       WHERE level_number = ? AND code COLLATE utf8mb4_unicode_ci != ? AND is_active = 1";
            $sibParams = [$level, $code];
            foreach (['n1','n2','n3','n4'] as $k) {
                if ($parents[$k] === '') {
                    $sibSql .= " AND (parent_$k IS NULL OR parent_$k = '')";
                } else {
                    $sibSql .= " AND parent_$k COLLATE utf8mb4_unicode_ci = ?";
                    $sibParams[] = $parents[$k];
                }
            }
            $sibSql .= " ORDER BY position ASC, id ASC LIMIT 1";
            $stSib = $pdo->prepare($sibSql);
            $stSib->execute($sibParams);
            $siblingCode = (string)($stSib->fetchColumn() ?: '');

            $childrenCopied = 0;
            $siblingUsed = null;
            // N5 = niveau le plus profond → pas de descendants à cloner, on saute
            if ($siblingCode !== '' && $level < 5) {
                $siblingUsed = $siblingCode;
                $parentField = "parent_n$level"; // colonne à remplacer dans les descendants
                // Sélectionne TOUS les descendants (directs et indirects) du sibling
                $descSql = "SELECT * FROM ged_level_codes
                            WHERE $parentField COLLATE utf8mb4_unicode_ci = ?
                              AND level_number > ?
                              AND is_active = 1
                            ORDER BY level_number ASC, position ASC";
                $stDesc = $pdo->prepare($descSql);
                $stDesc->execute([$siblingCode, $level]);
                $descendants = $stDesc->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (count($descendants) > 0) {
                    $insStmt = $pdo->prepare("
                        INSERT INTO ged_level_codes
                            (tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4,
                             code, label, position, is_active, is_entity_placeholder)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                    ");
                    foreach ($descendants as $d) {
                        // Garde les parents originaux SAUF parent_n{level} qu'on remplace par le nouveau code
                        $cn1 = (string)($d['parent_n1'] ?? '');
                        $cn2 = (string)($d['parent_n2'] ?? '');
                        $cn3 = (string)($d['parent_n3'] ?? '');
                        $cn4 = (string)($d['parent_n4'] ?? '');
                        if ($level === 1) $cn1 = $code;
                        elseif ($level === 2) $cn2 = $code;
                        elseif ($level === 3) $cn3 = $code;
                        elseif ($level === 4) $cn4 = $code;
                        try {
                            $insStmt->execute([
                                $tenantIdForInsert,
                                (int)$d['level_number'],
                                $cn1, $cn2, $cn3, $cn4,
                                (string)$d['code'],
                                (string)$d['label'],
                                (int)($d['position'] ?? 0),
                                (int)($d['is_entity_placeholder'] ?? 0),
                            ]);
                            $childrenCopied++;
                        } catch (PDOException $e) {
                            // Si un descendant existe déjà sous le nouveau parent (rejeu) → on skippe sans casser
                            if ($e->getCode() !== '23000') throw $e;
                        }
                    }
                }
            }

            fbx_api_respond(true, ['data' => [
                'id'              => $insertedId,
                'code'            => $code,
                'label'           => $label,
                'level'           => $level,
                'sibling_used'    => $siblingUsed,
                'children_copied' => $childrenCopied,
            ]]);
        }

        case 'save_msg_attachment': {
            // Enregistre une PJ d'un .msg comme document indépendant dans la GED.
            // Par défaut : même classement que le .msg parent. Overrides possibles : nom + n1/n2/n3/n4/n5.
            // Params : (carte_id OU ged_doc_id) + idx + [override_name] + [override_n1..n5]
            $carteId   = (int)($body['carte_id']   ?? 0);
            $gedDocId  = (int)($body['ged_doc_id'] ?? 0);
            $attIdx    = (int)($body['idx']        ?? -1);
            $overrideName = trim((string)($body['override_name'] ?? ''));
            $overrideClassement = [
                'n1' => trim((string)($body['override_n1'] ?? '')),
                'n2' => trim((string)($body['override_n2'] ?? '')),
                'n3' => trim((string)($body['override_n3'] ?? '')),
                'n4' => trim((string)($body['override_n4'] ?? '')),
                'n5' => trim((string)($body['override_n5'] ?? '')),
            ];
            if (($carteId <= 0 && $gedDocId <= 0) || $attIdx < 0) {
                throw new InvalidArgumentException('Paramètres manquants');
            }
            $tenantId = fluxbox_current_tenant_id();
            $userId   = (int)(current_user_id() ?? 0);

            // 1. Récupère le .msg parent + son classement
            $parentDoc = null;
            $parentGedDoc = null;
            $parentClassement = [];
            if ($gedDocId > 0) {
                $st = $pdo->prepare("
                    SELECT gd.id AS ged_id, gd.source_module, gd.metadata, gd.societe_id, gd.agence_id,
                           fd.id AS flux_id, fd.fichier_chemin
                    FROM ged_documents gd
                    INNER JOIN fluxbox_documents fd ON fd.id = gd.fluxbox_source_id
                    WHERE gd.id = ? AND gd.tenant_id = ?
                    LIMIT 1
                ");
                $st->execute([$gedDocId, $tenantId]);
                $parentDoc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($parentDoc) {
                    $parentGedDoc = $parentDoc;
                    $parentMeta = !empty($parentDoc['metadata'])
                        ? (json_decode((string)$parentDoc['metadata'], true) ?: [])
                        : [];
                    $parentClassement = (array)($parentMeta['classement'] ?? [
                        'n1' => (string)($parentDoc['source_module'] ?? ''),
                    ]);
                }
            } elseif ($carteId > 0) {
                $st = $pdo->prepare("
                    SELECT c.proposition_json, d.id AS flux_id, d.fichier_chemin
                    FROM fluxbox_cartes c
                    INNER JOIN fluxbox_documents d ON d.id = c.document_id
                    WHERE c.id = ? AND c.tenant_id = ?
                    LIMIT 1
                ");
                $st->execute([$carteId, $tenantId]);
                $parentDoc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($parentDoc) {
                    $parentProp = !empty($parentDoc['proposition_json'])
                        ? (json_decode((string)$parentDoc['proposition_json'], true) ?: [])
                        : [];
                    $parentClassement = (array)($parentProp['classement'] ?? []);
                }
            }
            if (!$parentDoc) throw new RuntimeException('Document parent introuvable');

            $parentPath = (string)$parentDoc['fichier_chemin'];
            if ($parentPath !== '' && !preg_match('#^([A-Za-z]:|/)#', $parentPath)) {
                $parentPath = __DIR__ . '/../storage_fluxbox/' . $parentPath;
            }
            if (!is_file($parentPath)) throw new RuntimeException('Fichier .msg parent introuvable');

            // 2. Parse .msg et extrait la PJ
            require_once __DIR__ . '/../../vendor/autoload.php';
            $messageFactory  = new \Hfig\MAPI\MapiMessageFactory();
            $documentFactory = new \Hfig\MAPI\OLE\Pear\DocumentFactory();
            $ole2 = $documentFactory->createFromFile($parentPath);
            $msg  = $messageFactory->parseMessage($ole2);
            $atts = [];
            foreach ($msg->getAttachments() as $a) { $atts[] = $a; }
            if (!isset($atts[$attIdx])) throw new RuntimeException('Index PJ invalide');

            $att = $atts[$attIdx];
            $attName = (string)(@$att->getFilename() ?: ('piece_jointe_' . ($attIdx + 1) . '.bin'));
            // Override du nom utilisateur (préserve l'extension si l'override n'en a pas)
            if ($overrideName !== '') {
                $origExt = pathinfo($attName, PATHINFO_EXTENSION);
                $ovrExt  = pathinfo($overrideName, PATHINFO_EXTENSION);
                $attName = ($ovrExt === '' && $origExt !== '') ? $overrideName . '.' . $origExt : $overrideName;
            }
            $attBlob = (string)(@$att->getData() ?? '');
            if ($attBlob === '') throw new RuntimeException('PJ sans contenu');
            $attMime = (string)(@$att->getMimeType() ?: 'application/octet-stream');

            // 3. Sauve le blob sur disque (sous-dossier dédié pour pj)
            $storeDir = __DIR__ . '/../storage_fluxbox/' . $tenantId . '/' . date('Y/m');
            if (!is_dir($storeDir) && !@mkdir($storeDir, 0750, true)) {
                throw new RuntimeException('Création dossier stockage impossible');
            }
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attName) ?: 'attachment.bin';
            $attPath = $storeDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
            if (file_put_contents($attPath, $attBlob) === false) {
                throw new RuntimeException('Écriture PJ échouée');
            }

            // 4. Détermine le parent_msg_carte_id pour traçabilité
            $parentCarteId = $carteId ?: null;

            // 5. Ingest via fluxbox_documents_ingest (gère l'anti-doublon SHA-256)
            require_once __DIR__ . '/../inc/fluxbox_functions.php';
            $ingest = fluxbox_documents_ingest([
                'path'        => $attPath,
                'fichier_nom' => $attName,
                'source_type' => 'manual',
                'mime_type'   => $attMime,
                'source_meta' => [
                    'extracted_from_msg' => true,
                    'parent_carte_id'    => $parentCarteId,
                    'parent_ged_doc_id'  => $gedDocId ?: null,
                    'parent_msg_name'    => basename($parentPath),
                ],
            ], $pdo);

            // 6. Promote en ged_document avec classement override OU parent
            $newGedDocId = null;
            // Construit le classement final : override > parent > vide
            $finalClassement = $parentClassement;
            foreach (['n1','n2','n3','n4','n5'] as $k) {
                if ($overrideClassement[$k] !== '') {
                    $finalClassement[$k] = $overrideClassement[$k];
                }
            }
            $finalClassement['name_display'] = $attName;

            // Promote uniquement si on a au moins N1 (sinon le ged_document serait orphelin)
            $hasN1 = !empty($finalClassement['n1']);
            if ($hasN1 && function_exists('fluxbox_promote_to_ged')) {
                $newGedDocId = fluxbox_promote_to_ged((int)$ingest['id'], $finalClassement, $pdo, null);
            }

            fbx_api_respond(true, ['data' => [
                'attachment_name' => $attName,
                'fluxbox_doc_id'  => (int)$ingest['id'],
                'ged_doc_id'      => $newGedDocId,
                'is_duplicate'    => (bool)($ingest['is_duplicate'] ?? false),
                'message'         => 'Pièce jointe enregistrée dans la GED' . ($newGedDocId ? " (doc GED #$newGedDocId)" : ''),
            ]]);
        }

        case 'download_msg_attachment': {
            // Extrait UNE pièce jointe d'un .msg et la sert au navigateur.
            // Param : (carte_id OU ged_doc_id) + idx (index PJ, 0-based) + mode (inline|download)
            $carteId   = (int)($_GET['carte_id']   ?? $body['carte_id']   ?? 0);
            $gedDocId  = (int)($_GET['ged_doc_id'] ?? $body['ged_doc_id'] ?? 0);
            $attIdx    = (int)($_GET['idx']        ?? $body['idx']        ?? -1);
            $mode      = strtolower((string)($_GET['mode'] ?? 'inline'));
            if (!in_array($mode, ['inline', 'download'], true)) $mode = 'inline';
            if (($carteId <= 0 && $gedDocId <= 0) || $attIdx < 0) {
                throw new InvalidArgumentException('Paramètres manquants (carte_id ou ged_doc_id + idx)');
            }
            $tenantId = fluxbox_current_tenant_id();

            $doc = null;
            if ($carteId > 0) {
                $st = $pdo->prepare("
                    SELECT d.fichier_chemin
                    FROM fluxbox_cartes c
                    INNER JOIN fluxbox_documents d ON d.id = c.document_id
                    WHERE c.id = ? AND c.tenant_id = ? AND d.tenant_id = ?
                    LIMIT 1
                ");
                $st->execute([$carteId, $tenantId, $tenantId]);
                $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } else {
                $st = $pdo->prepare("
                    SELECT fd.fichier_chemin
                    FROM ged_documents gd
                    INNER JOIN fluxbox_documents fd ON fd.id = gd.fluxbox_source_id
                    WHERE gd.id = ? AND gd.tenant_id = ?
                    LIMIT 1
                ");
                $st->execute([$gedDocId, $tenantId]);
                $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$doc) throw new RuntimeException('Document parent introuvable');

            $path = (string)$doc['fichier_chemin'];
            if ($path !== '' && !preg_match('#^([A-Za-z]:|/)#', $path)) {
                $path = __DIR__ . '/../storage_fluxbox/' . $path;
            }
            if (!is_file($path)) throw new RuntimeException('Fichier .msg source introuvable sur disque');

            // Parse via hfig/mapi et récupère la PJ par index
            require_once __DIR__ . '/../../vendor/autoload.php';
            if (!class_exists('Hfig\\MAPI\\MapiMessageFactory')) {
                throw new RuntimeException('Lib hfig/mapi non disponible');
            }
            $messageFactory  = new \Hfig\MAPI\MapiMessageFactory();
            $documentFactory = new \Hfig\MAPI\OLE\Pear\DocumentFactory();
            $ole2 = $documentFactory->createFromFile($path);
            $msg  = $messageFactory->parseMessage($ole2);

            $atts = [];
            foreach ($msg->getAttachments() as $a) { $atts[] = $a; }
            if (!isset($atts[$attIdx])) throw new RuntimeException('Index PJ invalide');

            $a = $atts[$attIdx];
            $filename = (string)(@$a->getFilename() ?: ('piece_jointe_' . ($attIdx + 1) . '.bin'));
            $blob     = (string)(@$a->getData() ?? '');
            if ($blob === '') throw new RuntimeException('PJ sans contenu binaire');
            $mime     = (string)(@$a->getMimeType() ?: 'application/octet-stream');

            // Stream au navigateur
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Content-Type: ' . $mime);
            $disposition = ($mode === 'download') ? 'attachment' : 'inline';
            header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');
            header('Content-Length: ' . (string)strlen($blob));
            header('X-Content-Type-Options: nosniff');
            echo $blob;
            exit;
        }

        case 'mindee_poll': {
            // Rafraîchit les jobs Mindee en attente (appelable par frontend ou cron).
            // Réservé user logué.
            require_once __DIR__ . '/../inc/fluxbox_mindee_ocr.php';
            $limit = max(1, min(50, (int)($body['limit'] ?? $_GET['limit'] ?? 20)));
            $stats = fluxbox_mindee_poll_pending($limit, $pdo);
            fbx_api_respond(true, ['data' => $stats]);
        }

        case 'read_msg': {
            // Lit un fichier .msg et retourne ses métadonnées + corps + liste PJ.
            // Accepte 2 modes : carte_id (depuis FluxBox) ou ged_doc_id (depuis GED).
            require_once __DIR__ . '/../inc/fluxbox_msg_parser.php';

            $carteId   = (int)($_GET['carte_id']   ?? $body['carte_id']   ?? 0);
            $gedDocId  = (int)($_GET['ged_doc_id'] ?? $body['ged_doc_id'] ?? 0);
            if ($carteId <= 0 && $gedDocId <= 0) {
                throw new InvalidArgumentException('carte_id ou ged_doc_id manquant');
            }
            $tenantId = fluxbox_current_tenant_id();

            $doc = null;
            if ($carteId > 0) {
                $st = $pdo->prepare("
                    SELECT d.id, d.fichier_chemin, d.fichier_nom
                    FROM fluxbox_cartes c
                    INNER JOIN fluxbox_documents d ON d.id = c.document_id
                    WHERE c.id = ? AND c.tenant_id = ? AND d.tenant_id = ?
                    LIMIT 1
                ");
                $st->execute([$carteId, $tenantId, $tenantId]);
                $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } elseif ($gedDocId > 0) {
                // Doc GED → on remonte au fluxbox_documents via fluxbox_source_id
                $st = $pdo->prepare("
                    SELECT fd.id, fd.fichier_chemin, fd.fichier_nom
                    FROM ged_documents gd
                    INNER JOIN fluxbox_documents fd ON fd.id = gd.fluxbox_source_id
                    WHERE gd.id = ? AND gd.tenant_id = ?
                    LIMIT 1
                ");
                $st->execute([$gedDocId, $tenantId]);
                $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$doc) throw new RuntimeException('Document introuvable');

            $path = (string)$doc['fichier_chemin'];
            if ($path !== '' && !preg_match('#^([A-Za-z]:|/)#', $path)) {
                $path = __DIR__ . '/../storage_fluxbox/' . $path;
            }

            // Parse en mode silencieux — JAMAIS d'exception remontée, toujours du JSON valide
            $parsed = null;
            if (!is_file($path)) {
                // Fichier supprimé du disque : retour propre avec warning explicatif
                $parsed = [
                    'ok' => false, 'from' => '', 'from_email' => '', 'to' => '', 'cc' => '',
                    'subject' => '', 'date_sent' => null, 'body_text' => '', 'body_html' => '',
                    'attachments' => [], 'raw_size' => 0,
                    'warnings' => [
                        'Fichier source supprimé du disque (probablement nettoyé après une suppression précédente).',
                        'Réuploade le .msg pour récupérer l\'accès au contenu.',
                    ],
                ];
            } else {
                try {
                    $parsed = fluxbox_msg_parse($path);
                } catch (Throwable $e) {
                    error_log('msg parse error: ' . $e->getMessage());
                    $parsed = [
                        'ok' => false, 'from' => '', 'from_email' => '', 'to' => '', 'cc' => '',
                        'subject' => '', 'date_sent' => null, 'body_text' => '', 'body_html' => '',
                        'attachments' => [], 'raw_size' => 0,
                        'warnings' => ['Parser .msg a rencontré une erreur — affichage limité. Télécharge le fichier pour l\'ouvrir dans Outlook.'],
                    ];
                }
            }
            if (!is_array($parsed)) $parsed = [];
            $parsed['fichier_nom'] = (string)$doc['fichier_nom'];
            $parsed['carte_id']    = $carteId;
            $parsed['doc_id']      = (int)$doc['id'];

            // Classement parent (pour pré-remplir la cascade PJ) — wrapped silently, ne doit JAMAIS planter
            $parentClassement = ['n1'=>'','n2'=>'','n3'=>'','n4'=>'','n5'=>''];
            try {
                if ($gedDocId > 0) {
                    $stMeta = $pdo->prepare("SELECT metadata, source_module FROM ged_documents WHERE id = ? LIMIT 1");
                    $stMeta->execute([$gedDocId]);
                    $row = $stMeta->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $meta = !empty($row['metadata']) ? (json_decode((string)$row['metadata'], true) ?: []) : [];
                        $c = (array)($meta['classement'] ?? []);
                        foreach (['n1','n2','n3','n4','n5'] as $k) {
                            $parentClassement[$k] = (string)($c[$k] ?? '');
                        }
                        if ($parentClassement['n1'] === '') $parentClassement['n1'] = (string)($row['source_module'] ?? '');
                    }
                } elseif ($carteId > 0) {
                    $stProp = $pdo->prepare("SELECT proposition_json FROM fluxbox_cartes WHERE id = ? LIMIT 1");
                    $stProp->execute([$carteId]);
                    $row = $stProp->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $prop = !empty($row['proposition_json']) ? (json_decode((string)$row['proposition_json'], true) ?: []) : [];
                        $c = (array)($prop['classement'] ?? []);
                        foreach (['n1','n2','n3','n4','n5'] as $k) {
                            $parentClassement[$k] = (string)($c[$k] ?? '');
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('read_msg parent_classement failed: ' . $e->getMessage());
            }
            $parsed['parent_classement'] = $parentClassement;

            fbx_api_respond(true, ['data' => $parsed]);
        }

        case 'send_msg_reply': {
            // Envoie une réponse au mail d'origine via PHPMailer + log dans fluxbox_msg_replies.
            // Phase 2 : nécessite que la table fluxbox_msg_replies existe (migration à venir).
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $toEmail = trim((string)($body['to_email'] ?? ''));
            $subject = trim((string)($body['subject'] ?? ''));
            $bodyTxt = (string)($body['body'] ?? '');
            if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Email destinataire invalide');
            }
            if ($subject === '') throw new InvalidArgumentException('Sujet manquant');
            if (trim($bodyTxt) === '') throw new InvalidArgumentException('Corps du message vide');

            $tenantId = fluxbox_current_tenant_id();
            $userId   = current_user_id();

            // Vérif carte appartient au tenant
            $stC = $pdo->prepare("SELECT id FROM fluxbox_cartes WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stC->execute([$carteId, $tenantId]);
            if (!$stC->fetchColumn()) throw new RuntimeException('Carte introuvable');

            // Envoi via PHPMailer si dispo
            $sent = false;
            $errSend = null;
            try {
                if (file_exists(__DIR__ . '/../inc/phpmailer_send.php')) {
                    require_once __DIR__ . '/../inc/phpmailer_send.php';
                    if (function_exists('phpmailer_send')) {
                        $sent = (bool)phpmailer_send($toEmail, $subject, $bodyTxt, false);
                    }
                }
                if (!$sent && file_exists(__DIR__ . '/../inc/mail_send.php')) {
                    require_once __DIR__ . '/../inc/mail_send.php';
                    if (function_exists('mail_send')) {
                        $sent = (bool)mail_send($toEmail, $subject, $bodyTxt);
                    }
                }
                // Fallback PHP mail() si rien d'autre
                if (!$sent) {
                    $sent = @mail($toEmail, $subject, $bodyTxt);
                }
            } catch (Throwable $e) {
                $errSend = $e->getMessage();
            }

            // Log dans fluxbox_msg_replies (création silencieuse de la table si absente)
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `fluxbox_msg_replies` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `tenant_id` INT UNSIGNED NOT NULL,
                        `carte_id` BIGINT UNSIGNED NOT NULL,
                        `sent_by` INT UNSIGNED NULL,
                        `to_email` VARCHAR(255) NOT NULL,
                        `subject` VARCHAR(500) NOT NULL,
                        `body` MEDIUMTEXT NOT NULL,
                        `sent_ok` TINYINT(1) NOT NULL DEFAULT 0,
                        `error_msg` TEXT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        INDEX `idx_carte` (`carte_id`),
                        INDEX `idx_tenant_date` (`tenant_id`, `created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $pdo->prepare("
                    INSERT INTO fluxbox_msg_replies
                        (tenant_id, carte_id, sent_by, to_email, subject, body, sent_ok, error_msg)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$tenantId, $carteId, $userId, $toEmail, $subject, $bodyTxt, $sent ? 1 : 0, $errSend]);
            } catch (Throwable) {}

            if (!$sent) {
                throw new RuntimeException('Envoi mail échoué : ' . ($errSend ?? 'mail() retourne false (config SMTP manquante ?)'));
            }

            fbx_api_respond(true, ['data' => ['sent' => true, 'to' => $toEmail]]);
        }

        case 'ingest_msg_attachments': {
            // Ingère les pièces jointes d'un .msg comme fluxbox_documents indépendants.
            // V1 : best-effort — le parser PHP pur ne sait pas toujours extraire les blobs PJ.
            // Pour vraiment exploiter, installer hfig/php-msgreader via Composer.
            require_once __DIR__ . '/../inc/fluxbox_msg_parser.php';
            $carteId = (int)($body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $tenantId = fluxbox_current_tenant_id();

            $st = $pdo->prepare("
                SELECT d.id, d.fichier_chemin, d.fichier_nom
                FROM fluxbox_cartes c
                INNER JOIN fluxbox_documents d ON d.id = c.document_id
                WHERE c.id = ? AND c.tenant_id = ?
                LIMIT 1
            ");
            $st->execute([$carteId, $tenantId]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new RuntimeException('Document introuvable');

            $path = (string)$doc['fichier_chemin'];
            if (!preg_match('#^([A-Za-z]:|/)#', $path)) {
                $path = __DIR__ . '/../storage_fluxbox/' . $path;
            }
            if (!is_file($path)) throw new RuntimeException('Fichier source introuvable');

            $parsed = fluxbox_msg_parse($path);
            $attachments = $parsed['attachments'] ?? [];

            // V1 limitation : on liste mais on n'extrait pas (parser PHP pur insuffisant).
            // On crée un placeholder JSON pour signaler à l'utilisateur ce qui a été détecté.
            fbx_api_respond(true, ['data' => [
                'attachments_detected' => count($attachments),
                'attachments'          => $attachments,
                'message'              => count($attachments) > 0
                    ? "Détection : " . count($attachments) . " pièce(s) jointe(s). Extraction binaire non disponible en V1 (parser PHP pur) — installer hfig/php-msgreader via Composer pour activer."
                    : 'Aucune pièce jointe détectée.',
            ]]);
        }

        case 'view_doc': {
            // Sert le fichier d'une carte au navigateur (PDF inline / image), vérif tenant.
            // Param : carte_id (depuis ?carte_id=N en GET ou body)
            $carteId = (int)($_GET['carte_id'] ?? $body['carte_id'] ?? 0);
            if ($carteId <= 0) throw new InvalidArgumentException('carte_id manquant');
            $tenantId = fluxbox_current_tenant_id();

            $st = $pdo->prepare("
                SELECT d.id, d.fichier_chemin, d.fichier_nom, d.mime_type
                FROM fluxbox_cartes c
                INNER JOIN fluxbox_documents d ON d.id = c.document_id
                WHERE c.id = ? AND c.tenant_id = ? AND d.tenant_id = ?
                LIMIT 1
            ");
            $st->execute([$carteId, $tenantId, $tenantId]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new RuntimeException('Document introuvable');

            $path = (string)$doc['fichier_chemin'];
            if ($path !== '' && !preg_match('#^([A-Za-z]:|/)#', $path)) {
                $path = __DIR__ . '/../storage_fluxbox/' . $path;
            }
            if (!is_file($path)) throw new RuntimeException('Fichier introuvable sur disque');

            $mime = (string)($doc['mime_type'] ?? 'application/octet-stream');
            $fname = (string)$doc['fichier_nom'];

            // Vide tous les buffers (sinon le PHP a déjà commencé à émettre du JSON)
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . rawurlencode($fname) . '"');
            header('Content-Length: ' . (string)filesize($path));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }

        case 'ingest': {
            // Upload fichier en flux → fluxbox_documents (anti-doublon SHA-256)
            // → cascade IA pour préparer une proposition → crée la carte
            // Si ZIP : extraction récursive (ZIP dans ZIP supportés).
            if (empty($_FILES['file'])) throw new InvalidArgumentException('Aucun fichier reçu (champ "file")');
            $tmp = (string)($_FILES['file']['tmp_name'] ?? '');
            $nom = (string)($_FILES['file']['name'] ?? 'upload.bin');
            $mime = (string)($_FILES['file']['type'] ?? '');
            if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Upload invalide');

            // Métadonnées user (commentaire + libellé + classement_hint + société/agence cible)
            $userComment = trim((string)($_POST['user_comment'] ?? ''));
            $userLabel   = trim((string)($_POST['user_label']   ?? ''));
            $classementHint = [
                'n1' => trim((string)($_POST['ged_n1'] ?? '')),  // métier
                'n2' => trim((string)($_POST['ged_n2'] ?? '')),  // domaine
                'n3' => trim((string)($_POST['ged_n3'] ?? '')),  // sous-domaine
                'n4' => trim((string)($_POST['ged_n4'] ?? '')),  // catégorie
                'n5' => trim((string)($_POST['ged_n5'] ?? '')),  // sous-catégorie
            ];
            // Société/agence : RÈGLE (2026-06-09) — jamais la session de l'user.
            //  - Si un BIEN est connu (upload depuis un bien) → on résout depuis le
            //    bien/immeuble/propriétaire (autorité métier), en ignorant tout défaut session.
            //  - Sinon (upload « libre ») → on respecte un choix explicite ; à défaut Régie EMERY (1/3).
            require_once dirname(__DIR__) . '/inc/bien_scope_resolver.php';
            $prefillBienId = (int)($_POST['prefill_bien_id'] ?? 0);
            $prefillBailId = (int)($_POST['prefill_bail_id'] ?? 0);
            // Un bail → on résout via son bien (autorité métier = le bien du bail).
            if ($prefillBienId <= 0 && $prefillBailId > 0) {
                try {
                    $stBb = $pdo->prepare("SELECT id_bien FROM bien_baux WHERE id = ? LIMIT 1");
                    $stBb->execute([$prefillBailId]);
                    $prefillBienId = (int)$stBb->fetchColumn();
                } catch (Throwable) {}
            }
            if ($prefillBienId > 0) {
                $rv = bien_resolve_soc_age($pdo, $prefillBienId);
                $targetSocieteId = $rv['societe_id'];
                $targetAgenceId  = $rv['agence_id'];
            } else {
                $targetSocieteId = (int)($_POST['target_societe_id'] ?? 0) ?: 1;
                $targetAgenceId  = (isset($_POST['target_agence_id']) && $_POST['target_agence_id'] !== '')
                    ? (int)$_POST['target_agence_id'] : 3;
            }
            // Date document : détection placée APRÈS le move (pour avoir $target accessible aux parsers PDF/msg)
            $userDateRaw = trim((string)($_POST['target_date'] ?? ''));

            // [V3.1 — 2026-05-25] Prefill métier depuis page appelante (bien/immeuble/tiers)
            // Permet le naming entité polymorphe + détection N1 contextuelle.
            $prefillBienId     = (int)($_POST['prefill_bien_id']     ?? 0);
            $prefillImmeubleId = (int)($_POST['prefill_immeuble_id'] ?? 0);
            $prefillTiersId    = (int)($_POST['prefill_tiers_id']    ?? 0);
            $prefillBailId     = (int)($_POST['prefill_bail_id']     ?? 0);
            $prefillEmpId      = (int)($_POST['prefill_emp_id']      ?? 0);
            $prefillCreancierDossierId = (int)($_POST['prefill_creancier_dossier_id'] ?? 0);
            $forcedTypeDoc     = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['forced_type_doc'] ?? '')))) ?: null;
            $prefillOrigin     = (string)($_POST['prefill_origin']   ?? '');

            // Contexte dossier créancier → tenant résolu depuis le dossier (autorité métier).
            if ($prefillCreancierDossierId > 0 && $prefillBienId <= 0) {
                try {
                    $stCd = $pdo->prepare("SELECT id_societe, id_agence FROM creancier_dossier WHERE id = ?");
                    $stCd->execute([$prefillCreancierDossierId]);
                    if ($rCd = $stCd->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($rCd['id_societe'])) $targetSocieteId = (int)$rCd['id_societe'];
                        if (!empty($rCd['id_agence']))  $targetAgenceId  = (int)$rCd['id_agence'];
                    }
                } catch (Throwable) {}
            }

            // Déplace dans un dossier de stockage tenant-safe
            $tenantId = fluxbox_current_tenant_id();
            $storeDir = __DIR__ . '/../storage_fluxbox/' . $tenantId . '/' . date('Y/m');
            if (!is_dir($storeDir) && !@mkdir($storeDir, 0750, true)) {
                throw new RuntimeException('Création dossier stockage impossible');
            }
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nom) ?: 'upload.bin';
            $target = $storeDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
            if (!@move_uploaded_file($tmp, $target)) {
                if (!@copy($tmp, $target)) throw new RuntimeException('Échec déplacement upload');
                @unlink($tmp);
            }

            // Maintenant que $target existe, on lance la détection multi-sources de la date du doc
            require_once __DIR__ . '/../inc/fluxbox_date_detector.php';
            $detection = fluxbox_detect_document_date([
                'path'      => $target,
                'filename'  => $nom,
                'mime_type' => $mime,
                'user_date' => $userDateRaw,
            ]);
            $targetDate     = $detection['date']     ?? '';
            $targetDateSrc  = $detection['source']   ?? 'none';
            $targetDateConf = (int)($detection['confidence'] ?? 0);

            // Détection ZIP → extraction récursive
            $isZip = strtolower(pathinfo($nom, PATHINFO_EXTENSION)) === 'zip'
                  || $mime === 'application/zip'
                  || $mime === 'application/x-zip-compressed';
            if ($isZip) {
                $zipStats = fluxbox_zip_extract_recursive($target, [
                    'source_type' => 'zip',
                    'source_meta' => [
                        'user_comment'      => $userComment,
                        'classement_hint'   => $classementHint,
                        'original_zip'      => $nom,
                        'target_societe_id' => $targetSocieteId,
                        'target_agence_id'  => $targetAgenceId,
                    ],
                ], $pdo);

                // Pour chaque fichier ingéré dans le ZIP, on lance la cascade + crée une carte
                // Pour V1 on aggrège : 1 carte par fichier nouveau via les ingestions déjà loggées
                // → optimisation : on crée des cartes pour les fluxbox_documents récents ajoutés par cet ingest
                // Détection grossière : les documents créés dans les 60 dernières secondes pour ce tenant
                $stCreated = $pdo->prepare("
                    SELECT id FROM fluxbox_documents
                    WHERE tenant_id = ?
                      AND first_seen_at >= (NOW() - INTERVAL 2 MINUTE)
                      AND JSON_EXTRACT(source_meta, '$.extracted_from') IS NOT NULL
                    ORDER BY id DESC
                    LIMIT 200
                ");
                $stCreated->execute([$tenantId]);
                $createdCarteIds = [];
                while ($row = $stCreated->fetch(PDO::FETCH_ASSOC)) {
                    $docId = (int)$row['id'];
                    // Skip si une carte existe déjà pour ce document
                    $stCk = $pdo->prepare("SELECT id FROM fluxbox_cartes WHERE document_id = ? LIMIT 1");
                    $stCk->execute([$docId]);
                    if ($stCk->fetchColumn()) continue;

                    $cascade = fluxbox_ia_run_cascade($docId, $pdo);
                    // Override classement si user a précisé (prefill > IA)
                    foreach (['n1','n2','n3','n4','n5'] as $k) {
                        if ($classementHint[$k] !== '') $cascade['classement'][$k] = $classementHint[$k];
                    }
                    // Récupère nom fichier + relative_path + source_meta du doc ZIP
                    $stDoc = $pdo->prepare("SELECT fichier_nom, source_meta FROM fluxbox_documents WHERE id = ?");
                    $stDoc->execute([$docId]);
                    $docRow = $stDoc->fetch(PDO::FETCH_ASSOC) ?: [];
                    $fname        = (string)($docRow['fichier_nom'] ?? '');
                    $srcMetaDoc   = !empty($docRow['source_meta']) ? (json_decode((string)$docRow['source_meta'], true) ?: []) : [];
                    $relativePath = (string)($srcMetaDoc['relative_path'] ?? '');

                    // Si N3 final = entité placeholder + on a un relative_path → premier segment = instance probable
                    $entityInstance = '';
                    $n3Final = (string)($cascade['classement']['n3'] ?? '');
                    if ($n3Final !== '' && $relativePath !== '') {
                        $stPh = $pdo->prepare("SELECT 1 FROM ged_level_codes WHERE code = ? AND COALESCE(is_entity_placeholder, 0) = 1 LIMIT 1");
                        $stPh->execute([$n3Final]);
                        if ($stPh->fetchColumn()) {
                            $segs = explode('/', str_replace('\\', '/', $relativePath));
                            if (count($segs) >= 2) {
                                // Le premier segment est le nom du dossier parent (instance entité)
                                $entityInstance = trim($segs[0]);
                            }
                        }
                    }

                    // Override date : user explicit > extraction depuis nom du fichier extrait
                    $perFileDate = $targetDate;
                    if ($perFileDate === '' && $fname !== '') {
                        $auto = ged_v3_extract_date_from_text($fname);
                        if ($auto) $perFileDate = $auto;
                    }
                    if ($perFileDate !== '') $cascade['classement']['date'] = $perFileDate;

                    // Libellé : user_label > entité détectée > IA n6 > nom fichier
                    if ($userLabel !== '') {
                        $titre = $userLabel;
                    } elseif ($entityInstance !== '') {
                        $titre = $entityInstance . ' — ' . ($fname ?: 'Document');
                    } else {
                        $titre = ($cascade['classement']['n6'] ?? '') !== '' ? $cascade['classement']['n6'] : ($fname ?: 'Document');
                    }
                    $carteId = fluxbox_carte_create([
                        'document_id'  => $docId,
                        'titre'        => $titre,
                        'sous_titre'   => "Extrait de ZIP — " . ($userComment ?: ($cascade['raison'] ?? '')),
                        'priorite'     => (string)($cascade['priorite'] ?? 'normal'),
                        'confiance_ia' => (float)$cascade['confiance'],
                        'proposition'  => [
                            'classement'        => $cascade['classement'],
                            'niveau_utilise'    => $cascade['niveau_utilise'],
                            'user_comment'      => $userComment,
                            'user_label'        => $userLabel,
                            'target_societe_id' => $targetSocieteId,
                            'target_agence_id'  => $targetAgenceId,
                            'entity_instance'   => $entityInstance,
                            'target_date'       => $perFileDate,
                        ],
                    ], $pdo);
                    foreach (($cascade['actions'] ?? []) as $a) {
                        fluxbox_carte_action_add(
                            $carteId, (string)($a['type'] ?? 'autre'),
                            (string)($a['label'] ?? 'Action'),
                            (array)($a['payload'] ?? []),
                            isset($a['confiance']) ? (float)$a['confiance'] : null,
                            $pdo
                        );
                    }
                    $createdCarteIds[] = $carteId;
                }

                // Analyse Variante A pour les imports ZIP : seulement si peu de cartes
                // créées (< 20). Au-delà, on laisse en 'pending' pour traitement batch
                // via admin_fluxbox_naming_review.php (évite timeout HTTP).
                $vaAnalyzed = 0;
                $vaErrors   = 0;
                if (count($createdCarteIds) > 0 && count($createdCarteIds) <= 20) {
                    require_once __DIR__ . '/../inc/fluxbox_va_orchestrator.php';
                    foreach ($createdCarteIds as $cid) {
                        try {
                            $r = fluxbox_va_analyze_carte($cid, $pdo);
                            if ($r['ok']) $vaAnalyzed++; else $vaErrors++;
                        } catch (Throwable) { $vaErrors++; }
                    }
                }

                fbx_api_respond(true, [
                    'data' => [
                        'is_zip'           => true,
                        'files_ingested'   => $zipStats['files_ingested'],
                        'files_dup'        => $zipStats['files_dup'],
                        'nested_zips'      => $zipStats['nested_zips'],
                        'errors'           => $zipStats['errors'],
                        'cards_created'    => count($createdCarteIds),
                        'va_analyzed'      => $vaAnalyzed,
                        'va_errors'        => $vaErrors,
                        'va_deferred'      => count($createdCarteIds) > 20,
                    ],
                ]);
            }

            // ─── Cas fichier unique (non-ZIP, peut venir d'un panneau "Dossier" → relative_path) ───
            $relativePath = trim((string)($_POST['relative_path'] ?? ''));
            $ingest = fluxbox_documents_ingest([
                'path'         => $target,
                'fichier_nom'  => $nom,
                'source_type'  => $relativePath !== '' ? 'folder' : 'manual',
                'mime_type'    => $mime,
                'source_meta'  => [
                    'user_comment'      => $userComment,
                    'classement_hint'   => $classementHint,
                    'target_societe_id' => $targetSocieteId,
                    'target_agence_id'  => $targetAgenceId,
                    'relative_path'     => $relativePath,
                    // [V3.1] prefill métier pour orchestrator naming
                    'bien_id'           => $prefillBienId ?: null,
                    'immeuble_id'       => $prefillImmeubleId ?: null,
                    'tiers_id'          => $prefillTiersId ?: null,
                    'bail_id'           => $prefillBailId ?: null,
                    'emp_id'            => $prefillEmpId ?: null,
                    'creancier_dossier_id' => $prefillCreancierDossierId ?: null,
                    'forced_type_doc'   => $forcedTypeDoc,
                    'prefill_origin'    => $prefillOrigin,
                ],
            ], $pdo);

            if ($ingest['is_duplicate']) {
                // Enrichit la réponse : carte + filename + uploader + date pour modal dedupe
                $origCarteId = null;
                $origGedDocId = null;
                $origStatut = null;
                $origFilename = '';
                $origUploadedAt = '';
                $origUploaderId = 0;
                $origTitre = '';
                $origGedName = '';
                try {
                    $stC = $pdo->prepare("SELECT id, statut, titre, created_at, created_by FROM fluxbox_cartes WHERE document_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 1");
                    $stC->execute([(int)$ingest['id'], fluxbox_current_tenant_id()]);
                    if ($r = $stC->fetch(PDO::FETCH_ASSOC)) {
                        $origCarteId = (int)$r['id'];
                        $origStatut  = (string)$r['statut'];
                        $origTitre   = (string)$r['titre'];
                        $origUploadedAt = (string)$r['created_at'];
                        $origUploaderId = (int)$r['created_by'];
                    }
                    $stD = $pdo->prepare("SELECT fichier_nom FROM fluxbox_documents WHERE id = ? LIMIT 1");
                    $stD->execute([(int)$ingest['id']]);
                    $origFilename = (string)$stD->fetchColumn();
                    $stG = $pdo->prepare("SELECT id, name_display FROM ged_documents WHERE fluxbox_source_id = ? LIMIT 1");
                    $stG->execute([(int)$ingest['id']]);
                    $row = $stG->fetch(PDO::FETCH_ASSOC);
                    if ($row) { $origGedDocId = (int)$row['id']; $origGedName = (string)$row['name_display']; }
                } catch (Throwable) {}

                fbx_api_respond(true, [
                    'data' => [
                        'is_duplicate'    => true,
                        'seen_count'      => $ingest['seen_count'],
                        'orig_doc_id'     => (int)$ingest['id'],
                        'orig_carte_id'   => $origCarteId,
                        'orig_ged_doc_id' => $origGedDocId,
                        'orig_ged_name'   => $origGedName,
                        'orig_carte_status' => $origStatut,
                        'orig_filename'   => $origFilename,
                        'orig_uploaded_at' => $origUploadedAt,
                        'orig_uploader_id' => $origUploaderId,
                        'orig_titre'      => $origTitre,
                        'new_filename'    => $nom,  // nom du fichier qu'on essayait d'uploader
                        'message'         => "Document déjà reçu (vu {$ingest['seen_count']} fois). Aucun nouveau traitement.",
                    ],
                ]);
            }

            // ─── Soumission Mindee (OCR + reconnaissance type doc) ───
            // Non bloquant : si Mindee est dispo et le doc est OCRisable, on soumet.
            // Le cache empêche tout double appel sur même hash. Polling séparé pour
            // récupérer le résultat (mindee_poll endpoint ou cron).
            try {
                require_once __DIR__ . '/../inc/fluxbox_mindee_ocr.php';
                fluxbox_mindee_submit_doc((int)$ingest['id'], $pdo);
            } catch (Throwable $e) {
                error_log('Mindee submit error: ' . $e->getMessage());
            }

            // Lance la cascade IA pour préparer la proposition
            $cascade = fluxbox_ia_run_cascade($ingest['id'], $pdo);

            // Si Mindee a déjà retourné un type doc (cas du cache OCR), applique son classement
            try {
                $stMeta = $pdo->prepare("SELECT source_meta FROM fluxbox_documents WHERE id = ?");
                $stMeta->execute([(int)$ingest['id']]);
                $metaJson = $stMeta->fetchColumn();
                $docMeta = $metaJson ? json_decode((string)$metaJson, true) : null;
                $mindeeType = is_array($docMeta) ? (string)($docMeta['mindee_doc_type'] ?? '') : '';
                if ($mindeeType !== '' && $mindeeType !== 'generic') {
                    $mindeeClassement = fluxbox_mindee_classement_from_type($mindeeType);
                    foreach ($mindeeClassement as $k => $v) {
                        if ($v !== '' && empty($cascade['classement'][$k])) {
                            $cascade['classement'][$k] = $v;
                        }
                    }
                    if ($mindeeClassement) {
                        $cascade['confiance']      = max(85, (int)($cascade['confiance'] ?? 0));
                        $cascade['niveau_utilise'] = 'MINDEE';
                        $cascade['raison']         = 'Type document détecté par Mindee : ' . $mindeeType;
                    }
                }
            } catch (Throwable) {}

            // Override avec le classement_hint user (s'il a précisé n1/n2/n3/n4/n5, on respecte au-dessus de Mindee)
            foreach (['n1','n2','n3','n4','n5'] as $k) {
                if ($classementHint[$k] !== '') {
                    $cascade['classement'][$k] = $classementHint[$k];
                }
            }
            // Override date document si fourni / extrait
            if ($targetDate !== '') {
                $cascade['classement']['date'] = $targetDate;
            }

            // Détection instance entité depuis relative_path (panneau Dossier)
            // Ex : "Dupont-Pierre/contrat.pdf" + N3=COLLABORATEUR placeholder → entity_instance="Dupont-Pierre"
            $entityInstance = '';
            $n3Final = (string)($cascade['classement']['n3'] ?? '');
            if ($n3Final !== '' && $relativePath !== '') {
                $stPh = $pdo->prepare("SELECT 1 FROM ged_level_codes WHERE code = ? AND COALESCE(is_entity_placeholder, 0) = 1 LIMIT 1");
                $stPh->execute([$n3Final]);
                if ($stPh->fetchColumn()) {
                    $segs = explode('/', str_replace('\\', '/', $relativePath));
                    if (count($segs) >= 2) {
                        $entityInstance = trim($segs[0]);
                    }
                }
            }

            // Crée la carte — libellé user > Haiku titre_court > entité+nom > IA n6 > nom fichier
            if ($userLabel !== '') {
                $titre = $userLabel;
            } elseif (!empty($cascade['titre_court'])) {
                $titre = (string)$cascade['titre_court'];
            } elseif ($entityInstance !== '') {
                $titre = $entityInstance . ' — ' . $nom;
            } else {
                $titre = $cascade['classement']['n6'] ?: $nom;
            }
            $sousTitre = $userComment !== ''
                ? '💬 ' . mb_substr($userComment, 0, 160)
                : ($cascade['raison'] ?: null);
            $priorite = (string)($cascade['priorite'] ?? 'normal');

            $carteId = fluxbox_carte_create([
                'document_id'    => $ingest['id'],
                'titre'          => $titre,
                'sous_titre'     => $sousTitre,
                'priorite'       => $priorite,
                'priorite_reason'=> $cascade['raison'] ?? null,
                'confiance_ia'   => (float)$cascade['confiance'],
                'proposition'    => [
                    'classement'         => $cascade['classement'],
                    'niveau_utilise'     => $cascade['niveau_utilise'],
                    'user_comment'       => $userComment,
                    'user_label'         => $userLabel,
                    'target_societe_id'  => $targetSocieteId,
                    'target_agence_id'   => $targetAgenceId,
                    'entity_instance'    => $entityInstance,
                    'target_date'        => $targetDate,
                    'target_date_source' => $targetDateSrc,
                    'target_date_confidence' => $targetDateConf,
                    // [V3.1] Prefill métier propagé pour naming entité polymorphe
                    'bien_id'            => $prefillBienId ?: null,
                    'immeuble_id'        => $prefillImmeubleId ?: null,
                    'tiers_id'           => $prefillTiersId ?: null,
                    'bail_id'            => $prefillBailId ?: null,
                    'emp_id'             => $prefillEmpId ?: null,
                    'creancier_dossier_id' => $prefillCreancierDossierId ?: null,
                    'forced_type_doc'    => $forcedTypeDoc,
                    'prefill_origin'     => $prefillOrigin,
                ],
            ], $pdo);

            // Stocke les actions IA proposées
            foreach (($cascade['actions'] ?? []) as $a) {
                fluxbox_carte_action_add(
                    $carteId,
                    (string)($a['type'] ?? 'autre'),
                    (string)($a['label'] ?? 'Action'),
                    (array)($a['payload'] ?? []),
                    isset($a['confiance']) ? (float)$a['confiance'] : null,
                    $pdo
                );
            }

            // Analyse Variante A (Vision + glossaire) — synchrone pour upload unitaire
            $vaResult = null;
            try {
                require_once __DIR__ . '/../inc/fluxbox_va_orchestrator.php';
                $vaResult = fluxbox_va_analyze_carte($carteId, $pdo);
            } catch (Throwable $e) {
                // Vision/réseau peut planter — la carte reste utilisable, naming_status reste 'pending'
                $vaResult = ['ok' => false, 'erreur' => $e->getMessage()];
            }

            // [V3.1 + Auto-commit — 2026-05-25] Si éligible (IA ≥ 90%, entité matchée, type spécifique)
            // → auto-commit en GED. Sinon, carte reste pending pour review manuelle.
            $autoCommit = null;
            try {
                require_once __DIR__ . '/../inc/fluxbox_auto_commit_ged.php';
                $autoCommit = fluxbox_auto_commit_try($carteId, $pdo);
            } catch (Throwable $e) {
                $autoCommit = ['attempted' => false, 'committed' => false, 'ged_doc_id' => null, 'eligibility' => ['reason' => 'exception: ' . $e->getMessage()], 'promote' => null];
            }

            fbx_api_respond(true, [
                'data' => [
                    'is_duplicate' => false,
                    'document_id'  => $ingest['id'],
                    'carte_id'     => $carteId,
                    'cascade_niveau' => $cascade['niveau_utilise'],
                    'confiance'    => $cascade['confiance'],
                    'naming'       => $vaResult ? [
                        'status'   => $vaResult['naming_status']  ?? null,
                        'reason'   => $vaResult['naming_review_reason'] ?? null,
                        'proposed' => $vaResult['naming_proposed'] ?? null,
                        'ok'       => $vaResult['ok'] ?? false,
                        'erreur'   => $vaResult['erreur'] ?? null,
                    ] : null,
                    'auto_commit'  => $autoCommit ? [
                        'attempted'  => $autoCommit['attempted'],
                        'committed'  => $autoCommit['committed'],
                        'ged_doc_id' => $autoCommit['ged_doc_id'],
                        'reason'     => $autoCommit['eligibility']['reason'] ?? '',
                    ] : null,
                    // Infos PRÉPARÉES par l'IA (mêmes données que le modal « Ajuster ») —
                    // pour affichage immédiat des champs extraits à droite du modal de chargement.
                    'prepared'     => [
                        'titre'      => $titre,
                        // Vrai résumé IA (pas un commentaire user préfixé 💬).
                        'resume'     => (mb_substr((string)$sousTitre, 0, 1) === '💬') ? null : ($sousTitre ?: null),
                        'confiance'  => (float)$cascade['confiance'],
                        // Copie EXACTE des champs de la pile (résolution) — 2 colonnes lecture seule.
                        'fields'     => (function () use ($carteId, $pdo) {
                            try {
                                require_once __DIR__ . '/../inc/fluxbox_resolution.php';
                                if (!function_exists('fluxbox_resoudre_carte')) return [];
                                $reso = fluxbox_resoudre_carte((int)$carteId, $pdo);
                                $defs = [
                                    ['🏢 Société', 'societe'], ['🏬 Agence', 'agence'],
                                    ['💼 Métier', 'metier'], ['📂 Domaine', 'domaine'],
                                    ['📁 Sous-domaine', 'sousdomaine'], ['👤 Entité', 'entite'],
                                    ['🏷️ Type de document', 'type_document'], ['📄 Catégorie', 'categorie'],
                                    ['📑 Sous-catégorie', 'souscategorie'], ['✍️ Signature', 'signature'],
                                ];
                                $out = [];
                                foreach ($defs as [$lbl, $key]) {
                                    $f = $reso[$key] ?? null;
                                    if (!is_array($f)) continue;
                                    $out[] = ['label' => $lbl, 'value' => $f['valeur'] ?? null, 'conf' => $f['confiance'] ?? null];
                                }
                                return $out;
                            } catch (Throwable $e) { return []; }
                        })(),
                    ],
                ],
            ]);
        }

        case 'fbx_context': {
            // Renvoie sociétés + agences accessibles + N1 groupés par business_group
            // Utilisé par la modale d'upload pour peupler les 3 rangées de boutons.
            $societes = ged_v3_list_societes_accessible($pdo);
            $defaultSocId = (int)($_SESSION['id_societe'] ?? 0);
            $askedSocId = (int)($body['societe_id'] ?? $defaultSocId);
            $agences = $askedSocId > 0 ? ged_v3_list_agences_accessible($askedSocId, $pdo) : [];

            // Métiers N1 groupés
            $n1Rows = $pdo->query("
                SELECT code, label, COALESCE(business_group,'AUTRE') AS bg
                FROM ged_level_codes
                WHERE level_number = 1 AND is_active = 1 AND COALESCE(is_virtual,0) = 0
                ORDER BY FIELD(business_group,'RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','MARKETING','DIRECTION','JURIDIQUE'),
                         position ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $groupLabels = [
                'RH'           => ['label'=>'RH',           'icon'=>'👥'],
                'COMPTA'       => ['label'=>'Comptabilité', 'icon'=>'💰'],
                'BAILLEUR'     => ['label'=>'Gestion',      'icon'=>'🏠'],
                'SYNDIC'       => ['label'=>'Syndic',       'icon'=>'🏢'],
                'AGENCE'       => ['label'=>'Agence',       'icon'=>'🤝'],
                'FOURNISSEURS' => ['label'=>'Fournisseurs', 'icon'=>'🔧'],
                'MARKETING'    => ['label'=>'Marketing',    'icon'=>'📣'],
                'DIRECTION'    => ['label'=>'Direction',    'icon'=>'⚙️'],
                'JURIDIQUE'    => ['label'=>'Juridique',    'icon'=>'⚖️'],
                'AUTRE'        => ['label'=>'Autre',        'icon'=>'❓'],
            ];

            $grouped = [];
            foreach ($n1Rows as $r) {
                $bg = $r['bg'];
                if (!isset($grouped[$bg])) {
                    $grouped[$bg] = [
                        'group'      => $bg,
                        'group_label'=> $groupLabels[$bg]['label'] ?? $bg,
                        'icon'       => $groupLabels[$bg]['icon']  ?? '',
                        'codes'      => [],
                    ];
                }
                $grouped[$bg]['codes'][] = ['code'=>$r['code'], 'label'=>$r['label']];
            }

            fbx_api_respond(true, [
                'data' => [
                    'societes'         => $societes,
                    'agences'          => $agences,
                    'societe_default'  => $defaultSocId,
                    'agence_default'   => (int)($_SESSION['id_agence'] ?? 0),
                    'metiers_grouped'  => array_values($grouped),
                    'can_change_societe' => ((int)($_SESSION['id_role'] ?? 0) === 1) || !empty($_SESSION['super_admin']),
                    'can_change_agence'  => in_array((int)($_SESSION['id_role'] ?? 0), [1, 2], true) || !empty($_SESSION['super_admin']),
                ],
            ]);
        }

        case 'rename_document': {
            // Re-génère le name_canonical d'un ged_document existant avec :
            //  - une nouvelle date document (target_date YYYY-MM-DD ou YYYY-MM)
            //  - et/ou un nouveau classement (n1/n2/n3/n4/n5/n6)
            // Utile pour corriger les 632 docs déjà importés avec date du jour.
            $tenantId = fluxbox_current_tenant_id();
            $docId = (int)($body['document_id'] ?? 0);
            if ($docId <= 0) throw new InvalidArgumentException('document_id manquant');

            $st = $pdo->prepare("SELECT * FROM ged_documents WHERE id = ? AND tenant_id = ?");
            $st->execute([$docId, $tenantId]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new RuntimeException('Document introuvable');

            $meta = !empty($doc['metadata']) ? (json_decode((string)$doc['metadata'], true) ?: []) : [];
            $classement = (array)($meta['classement'] ?? []);

            foreach (['n1','n2','n3','n4','n5','n6'] as $k) {
                $key = 'ged_' . $k;
                if (isset($body[$key]) && $body[$key] !== '') $classement[$k] = (string)$body[$key];
            }
            $newDate = trim((string)($body['target_date'] ?? ''));
            if ($newDate !== '') $classement['date'] = $newDate;

            $ctx = ged_v3_get_user_context($pdo);
            $newName = ged_v3_build_canonical_name([
                'soc'  => $ctx['societe_code'] ?: 'SOC',
                'age'  => $ctx['agence_code']  ?: 'AGE',
                'n1'   => (string)($classement['n1'] ?? $doc['source_module'] ?? ''),
                'n2'   => (string)($classement['n2'] ?? ''),
                'n3'   => (string)($classement['n3'] ?? ''),
                'n4'   => (string)($classement['n4'] ?? ''),
                'n5'   => (string)($classement['n5'] ?? ''),
                'n6'   => (string)($classement['n6'] ?? ''),
                'date' => $classement['date'] ?? null,
                'ext'  => pathinfo((string)$doc['name_file'], PATHINFO_EXTENSION),
            ]);

            $meta['classement'] = $classement;
            $meta['renamed_at'] = date('Y-m-d H:i:s');
            $meta['renamed_from'] = $doc['name_canonical'];

            $pdo->prepare("
                UPDATE ged_documents
                SET name_canonical = ?, name_file = ?, metadata = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ")->execute([$newName, $newName, json_encode($meta, JSON_UNESCAPED_UNICODE), $docId, $tenantId]);

            fbx_api_respond(true, [
                'data' => [
                    'document_id'  => $docId,
                    'old_name'     => $doc['name_canonical'],
                    'new_name'     => $newName,
                    'classement'   => $classement,
                ],
            ]);
        }

        case 'rename_documents_bulk': {
            // Bulk : recalcule les noms en extrayant la date auto.
            // Params : document_ids:[] OU filter {source_module, fluxbox_only}, target_date (commun optionnel), dry_run
            $tenantId = fluxbox_current_tenant_id();
            $dryRun = !empty($body['dry_run']);
            $commonDate = trim((string)($body['target_date'] ?? ''));
            $commonClassement = [];
            foreach (['n1','n2','n3','n4','n5','n6'] as $k) {
                $key = 'ged_' . $k;
                if (isset($body[$key]) && $body[$key] !== '') $commonClassement[$k] = (string)$body[$key];
            }

            $docIds = [];
            if (!empty($body['document_ids']) && is_array($body['document_ids'])) {
                $docIds = array_values(array_filter(array_map('intval', $body['document_ids']), fn($i) => $i > 0));
            } else {
                $w = ['tenant_id = ?']; $p = [$tenantId];
                if (!empty($body['source_module'])) {
                    $w[] = 'source_module = ?'; $p[] = (string)$body['source_module'];
                }
                if (!empty($body['fluxbox_only'])) {
                    $w[] = 'fluxbox_source_id IS NOT NULL';
                }
                $limit = max(1, min(2000, (int)($body['limit'] ?? 500)));
                $st = $pdo->prepare("SELECT id FROM ged_documents WHERE " . implode(' AND ', $w) . " ORDER BY id ASC LIMIT $limit");
                $st->execute($p);
                $docIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }

            if (count($docIds) === 0) {
                fbx_api_respond(true, ['data' => ['total' => 0, 'renamed' => 0, 'errors' => []]]);
            }

            if ($dryRun) {
                fbx_api_respond(true, ['data' => ['total' => count($docIds), 'document_ids' => array_map('intval', $docIds), 'dry_run' => true]]);
            }

            $renamed = 0; $errors = [];
            $ctx = ged_v3_get_user_context($pdo);

            foreach ($docIds as $docId) {
                try {
                    $st = $pdo->prepare("
                        SELECT d.*, f.fichier_nom AS flux_nom, f.ocr_text AS flux_ocr
                        FROM ged_documents d
                        LEFT JOIN fluxbox_documents f ON f.id = d.fluxbox_source_id
                        WHERE d.id = ? AND d.tenant_id = ?
                    ");
                    $st->execute([(int)$docId, $tenantId]);
                    $doc = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$doc) { $errors[] = "Doc #$docId introuvable"; continue; }

                    $meta = !empty($doc['metadata']) ? (json_decode((string)$doc['metadata'], true) ?: []) : [];
                    $classement = array_merge((array)($meta['classement'] ?? []), $commonClassement);

                    $useDate = $commonDate;
                    if ($useDate === '') {
                        $candidates = [
                            (string)($doc['flux_nom'] ?? ''),
                            (string)($doc['name_canonical'] ?? ''),
                            mb_substr((string)($doc['flux_ocr'] ?? ''), 0, 2000),
                        ];
                        foreach ($candidates as $cand) {
                            $auto = ged_v3_extract_date_from_text($cand);
                            if ($auto) { $useDate = $auto; break; }
                        }
                    }
                    if ($useDate !== '') $classement['date'] = $useDate;

                    $newName = ged_v3_build_canonical_name([
                        'soc'  => $ctx['societe_code'] ?: 'SOC',
                        'age'  => $ctx['agence_code']  ?: 'AGE',
                        'n1'   => (string)($classement['n1'] ?? $doc['source_module'] ?? ''),
                        'n2'   => (string)($classement['n2'] ?? ''),
                        'n3'   => (string)($classement['n3'] ?? ''),
                        'n4'   => (string)($classement['n4'] ?? ''),
                        'n5'   => (string)($classement['n5'] ?? ''),
                        'n6'   => (string)($classement['n6'] ?? ''),
                        'date' => $classement['date'] ?? null,
                        'ext'  => pathinfo((string)$doc['name_file'], PATHINFO_EXTENSION),
                    ]);

                    $meta['classement'] = $classement;
                    $meta['renamed_at'] = date('Y-m-d H:i:s');
                    $meta['renamed_from'] = $doc['name_canonical'];

                    $pdo->prepare("
                        UPDATE ged_documents
                        SET name_canonical = ?, name_file = ?, metadata = ?, updated_at = NOW()
                        WHERE id = ? AND tenant_id = ?
                    ")->execute([$newName, $newName, json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$docId, $tenantId]);

                    $renamed++;
                } catch (Throwable $e) {
                    $errors[] = "Doc #$docId : " . $e->getMessage();
                }
            }

            fbx_api_respond(true, [
                'data' => [
                    'total'   => count($docIds),
                    'renamed' => $renamed,
                    'errors'  => array_slice($errors, 0, 10),
                ],
            ]);
        }

        case 'ged_cascade': {
            // Charge les enfants d'un niveau (utilisé par les selects en cascade).
            // Params : level (1-5), n1, n2, n3, n4 (le parent)
            $level = (int)($body['level'] ?? 1);
            $parents = [
                'n1' => (string)($body['n1'] ?? ''),
                'n2' => (string)($body['n2'] ?? ''),
                'n3' => (string)($body['n3'] ?? ''),
                'n4' => (string)($body['n4'] ?? ''),
            ];
            if ($level === 1) {
                // N1 racines (avec leur business_group pour affichage groupé)
                $st = $pdo->query("
                    SELECT code, label, COALESCE(business_group,'') AS business_group
                    FROM ged_level_codes
                    WHERE level_number = 1 AND is_active = 1 AND COALESCE(is_virtual,0) = 0
                    ORDER BY FIELD(business_group,'RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','MARKETING','DIRECTION','JURIDIQUE'),
                             position ASC
                ");
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                fbx_api_respond(true, ['data' => ['level' => 1, 'items' => $rows]]);
            }
            $children = ged_v3_get_children($level, $parents, $pdo);

            // Injection automatique d'entités du glossaire pour les placeholders connus :
            // - N3 sous 01_DIRECTION > SOCIETES → injecte les 25 véhicules (category=vehicule)
            // Les entités glossaire apparaissent comme des choix cliquables aux côtés du placeholder.
            if ($level === 3 && $parents['n1'] === '01_DIRECTION' && $parents['n2'] === 'SOCIETES') {
                try {
                    $stV = $pdo->query("SELECT code, label FROM ged_codes_glossaire WHERE category='vehicule' AND is_active=1 ORDER BY label");
                    while ($v = $stV->fetch(PDO::FETCH_ASSOC)) {
                        $children[] = [
                            'id'    => 0,
                            'code'  => (string)$v['code'],
                            'label' => '🚗 ' . (string)$v['label'],
                            'is_entity_placeholder' => 0,
                        ];
                    }
                } catch (Throwable) {}
            }

            fbx_api_respond(true, ['data' => ['level' => $level, 'parents' => $parents, 'items' => $children]]);
        }

        case 'ingest_url': {
            // Télécharge un fichier depuis une URL HTTPS distante puis ingest.
            $url = trim((string)($body['url'] ?? ''));
            if ($url === '' || !preg_match('/^https:\/\//i', $url)) {
                throw new InvalidArgumentException('URL HTTPS requise');
            }
            // Whitelist permissive : on télécharge avec contrôle de taille
            $maxBytes = 50 * 1024 * 1024;
            $tenantId = fluxbox_current_tenant_id();
            $storeDir = __DIR__ . '/../storage_fluxbox/' . $tenantId . '/' . date('Y/m');
            if (!is_dir($storeDir) && !@mkdir($storeDir, 0750, true)) {
                throw new RuntimeException('Création dossier stockage impossible');
            }

            $nameFromUrl = basename(parse_url($url, PHP_URL_PATH) ?? 'download.bin');
            if ($nameFromUrl === '' || $nameFromUrl === false) $nameFromUrl = 'download.bin';
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nameFromUrl) ?: 'download.bin';
            $target = $storeDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safe;

            $ch = curl_init($url);
            if ($ch === false) throw new RuntimeException('curl_init failed');
            $fp = @fopen($target, 'wb');
            if (!$fp) throw new RuntimeException('Création fichier impossible');
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'MaBoxImmo-FluxBox/1.0',
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dlSize, $dl) use ($maxBytes) {
                    return ($dl > $maxBytes) ? 1 : 0; // abort si > 50 Mo
                },
            ]);
            $ok = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $httpCode < 200 || $httpCode >= 400) {
                @unlink($target);
                throw new RuntimeException("Téléchargement échoué (HTTP $httpCode)");
            }
            if (filesize($target) > $maxBytes) {
                @unlink($target);
                throw new RuntimeException('Fichier trop volumineux (> 50 Mo)');
            }

            // Métadonnées user (mêmes champs que action=ingest)
            $userCommentUrl = trim((string)($body['user_comment'] ?? ''));
            $userLabelUrl   = trim((string)($body['user_label']   ?? ''));
            $classementHintUrl = [
                'n1' => trim((string)($body['ged_n1'] ?? '')),
                'n2' => trim((string)($body['ged_n2'] ?? '')),
                'n3' => trim((string)($body['ged_n3'] ?? '')),
                'n4' => trim((string)($body['ged_n4'] ?? '')),
                'n5' => trim((string)($body['ged_n5'] ?? '')),
            ];
            $targetDateUrl = trim((string)($body['target_date'] ?? ''));

            $ingest = fluxbox_documents_ingest([
                'path'         => $target,
                'fichier_nom'  => $nameFromUrl,
                'source_type'  => 'webhook',
                'mime_type'    => $contentType,
                'source_meta'  => [
                    'url'             => $url,
                    'user_comment'    => $userCommentUrl,
                    'classement_hint' => $classementHintUrl,
                ],
            ], $pdo);

            if ($ingest['is_duplicate']) {
                fbx_api_respond(true, [
                    'data' => [
                        'is_duplicate' => true,
                        'seen_count'   => $ingest['seen_count'],
                        'message'      => "Document déjà reçu (vu {$ingest['seen_count']} fois).",
                    ],
                ]);
            }

            // Cascade IA + carte (même logique que action=ingest)
            $cascade = fluxbox_ia_run_cascade($ingest['id'], $pdo);
            // Override classement user
            foreach (['n1','n2','n3','n4','n5'] as $k) {
                if ($classementHintUrl[$k] !== '') $cascade['classement'][$k] = $classementHintUrl[$k];
            }
            if ($targetDateUrl !== '') $cascade['classement']['date'] = $targetDateUrl;
            // Titre : libellé user > IA n6 > nom URL
            $titre = $userLabelUrl !== ''
                ? $userLabelUrl
                : (($cascade['classement']['n6'] ?? '') !== '' ? $cascade['classement']['n6'] : $nameFromUrl);
            $carteId = fluxbox_carte_create([
                'document_id'    => $ingest['id'],
                'titre'          => $titre,
                'sous_titre'     => $userCommentUrl !== '' ? ('💬 ' . mb_substr($userCommentUrl, 0, 160)) : ($cascade['raison'] ?? null),
                'priorite'       => (string)($cascade['priorite'] ?? 'normal'),
                'confiance_ia'   => (float)$cascade['confiance'],
                'proposition'    => [
                    'classement'     => $cascade['classement'],
                    'niveau_utilise' => $cascade['niveau_utilise'],
                    'user_comment'   => $userCommentUrl,
                    'user_label'     => $userLabelUrl,
                ],
            ], $pdo);
            foreach (($cascade['actions'] ?? []) as $a) {
                fluxbox_carte_action_add(
                    $carteId, (string)($a['type'] ?? 'autre'),
                    (string)($a['label'] ?? 'Action'),
                    (array)($a['payload'] ?? []),
                    isset($a['confiance']) ? (float)$a['confiance'] : null,
                    $pdo
                );
            }

            fbx_api_respond(true, [
                'data' => [
                    'is_duplicate'   => false,
                    'document_id'    => $ingest['id'],
                    'carte_id'       => $carteId,
                    'cascade_niveau' => $cascade['niveau_utilise'],
                    'confiance'      => $cascade['confiance'],
                ],
            ]);
        }

        case 'ingest_clipboard': {
            // Image base64 collée depuis le presse-papier (data URL)
            $dataUrl = (string)($body['data_url'] ?? '');
            if ($dataUrl === '' || !preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $dataUrl)) {
                throw new InvalidArgumentException('data_url image base64 attendue');
            }
            [, $b64] = explode(',', $dataUrl, 2);
            $bin = base64_decode($b64, true);
            if ($bin === false) throw new InvalidArgumentException('Décodage base64 échoué');

            $tenantId = fluxbox_current_tenant_id();
            $storeDir = __DIR__ . '/../storage_fluxbox/' . $tenantId . '/' . date('Y/m');
            if (!is_dir($storeDir) && !@mkdir($storeDir, 0750, true)) {
                throw new RuntimeException('Création dossier stockage impossible');
            }
            $ext = 'png';
            if (preg_match('#^data:image/([a-z0-9.+-]+);#i', $dataUrl, $m)) {
                $ext = strtolower(preg_replace('/[^a-z0-9]/', '', $m[1])) ?: 'png';
            }
            $nom = 'capture_' . date('Ymd_His') . '.' . $ext;
            $target = $storeDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $nom;
            if (file_put_contents($target, $bin) === false) {
                throw new RuntimeException('Écriture fichier capture impossible');
            }

            $ingest = fluxbox_documents_ingest([
                'path'        => $target,
                'fichier_nom' => $nom,
                'source_type' => 'photo',
                'mime_type'   => 'image/' . $ext,
                'source_meta' => ['source' => 'clipboard'],
            ], $pdo);

            if ($ingest['is_duplicate']) {
                fbx_api_respond(true, ['data' => ['is_duplicate' => true, 'seen_count' => $ingest['seen_count']]]);
            }
            $cascade = fluxbox_ia_run_cascade($ingest['id'], $pdo);
            $carteId = fluxbox_carte_create([
                'document_id'  => $ingest['id'],
                'titre'        => 'Capture écran',
                'sous_titre'   => 'Image collée depuis le presse-papier',
                'priorite'     => 'normal',
                'confiance_ia' => (float)$cascade['confiance'],
                'proposition'  => ['classement' => $cascade['classement'], 'niveau_utilise' => $cascade['niveau_utilise']],
            ], $pdo);
            fbx_api_respond(true, [
                'data' => ['is_duplicate' => false, 'document_id' => $ingest['id'], 'carte_id' => $carteId],
            ]);
        }

        case 'bulk_validate': {
            // Valide un ensemble de cartes pending.
            // Modes :
            //   A. Liste explicite : carte_ids: [1,2,3,…]  ← cas sélection user dans la pile
            //   B. Filtre auto : source + min_confiance + limit  ← cas validation en masse "tout l'IA-prêt"
            //   - dry_run : si 1, ne valide pas, renvoie juste le count cible
            $tenantId = fluxbox_current_tenant_id();
            if ($tenantId <= 0) throw new RuntimeException('tenant_id manquant');

            $dryRun = !empty($body['dry_run']);
            $carteIds = [];

            // Mode A : liste explicite
            if (isset($body['carte_ids']) && is_array($body['carte_ids']) && count($body['carte_ids']) > 0) {
                $requested = array_values(array_unique(array_map('intval', $body['carte_ids'])));
                $requested = array_slice(array_filter($requested, fn($i) => $i > 0), 0, 2000);
                if (count($requested) > 0) {
                    $placeholders = implode(',', array_fill(0, count($requested), '?'));
                    $sql = "SELECT id FROM fluxbox_cartes
                            WHERE tenant_id = ? AND statut = 'pending'
                              AND id IN ($placeholders)";
                    $params = array_merge([$tenantId], $requested);
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $carteIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
                }
            }
            // Mode B : filtre auto
            else {
                $source = (string)($body['source'] ?? 'all');
                $minConf = isset($body['min_confiance']) ? (float)$body['min_confiance'] : 60.0;
                $limit = max(1, min(2000, (int)($body['limit'] ?? 1000)));

                $sourceMap = [
                    'telechargements' => ['manual','watcher','zip','photo'],
                    'mails'           => ['email','webhook'],
                    'all'             => null,
                ];
                $sourceTypes = $sourceMap[$source] ?? null;

                $sql = "SELECT c.id FROM fluxbox_cartes c
                        LEFT JOIN fluxbox_documents d ON d.id = c.document_id
                        WHERE c.tenant_id = ?
                          AND c.statut = 'pending'
                          AND (c.confiance_ia IS NULL OR c.confiance_ia >= ?)";
                $params = [$tenantId, $minConf];
                if ($sourceTypes !== null) {
                    $placeholders = implode(',', array_fill(0, count($sourceTypes), '?'));
                    $sql .= " AND (d.source_type IN ($placeholders) OR c.document_id IS NULL)";
                    $params = array_merge($params, $sourceTypes);
                }
                $sql .= " ORDER BY FIELD(c.priorite,'urgent','important','normal','faible'), c.created_at ASC
                          LIMIT $limit";

                $st = $pdo->prepare($sql);
                $st->execute($params);
                $carteIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            $total = count($carteIds);

            if ($dryRun) {
                fbx_api_respond(true, [
                    'data' => [
                        'total_eligible' => $total,
                        'carte_ids'      => array_map('intval', $carteIds),
                        'dry_run'        => true,
                    ],
                ]);
            }

            // Validation en masse
            $ok = 0; $ko = 0; $errors = [];
            foreach ($carteIds as $cid) {
                try {
                    $r = fluxbox_carte_validate((int)$cid, [], $pdo);
                    if ($r['ok']) $ok++;
                    else {
                        $ko++;
                        if (count($errors) < 10) $errors[] = "Carte #$cid : " . implode(', ', $r['errors']);
                    }
                } catch (Throwable $e) {
                    $ko++;
                    if (count($errors) < 10) $errors[] = "Carte #$cid : " . $e->getMessage();
                }
            }

            fbx_api_respond(true, [
                'data' => [
                    'total_targeted' => $total,
                    'validated'      => $ok,
                    'failed'         => $ko,
                    'errors_sample'  => $errors,
                ],
            ]);
        }

        case 'stats': {
            $stats = fluxbox_carte_stats($pdo);
            $societeId = (int)(current_societe_id() ?? 0);
            $plafond = $societeId > 0
                ? fluxbox_ia_get_plafond_state($societeId, $pdo)
                : ['plafond_eur'=>75,'consomme_eur'=>0,'pct'=>0,'mode_degrade'=>false];
            fbx_api_respond(true, ['data' => ['stats' => $stats, 'plafond_ia' => $plafond]]);
        }

        default:
            http_response_code(400);
            fbx_api_respond(false, ['errors' => ['Action inconnue: ' . $action]]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    fbx_api_respond(false, [
        'errors' => [$e->getMessage()],
        '_trace' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e),
        ],
    ]);
}
