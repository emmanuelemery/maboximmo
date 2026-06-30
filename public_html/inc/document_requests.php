<?php
declare(strict_types=1);
/**
 * inc/document_requests.php — « Demande de document » : lien de dépôt sécurisé.
 *
 * Une demande (document_requests) porte un token public et N pièces
 * (document_request_items), chacune = une carte de dépôt classée automatiquement
 * en GED à la réception. Modèles réutilisables (document_request_templates).
 *
 * Briques réutilisées : gus_commit_document (pipeline GED), send_mail (notif).
 */
require_once __DIR__ . '/ged_document_links.php';

/* ── Modèles par défaut (seed idempotent) ─────────────────────────────── */
if (!function_exists('dr_default_templates')) {
    function dr_default_templates(): array
    {
        return [
            [
                'code' => 'candidat_locataire',
                'nom'  => 'Dossier candidat locataire',
                'description' => "Pièces autorisées (décret n°2015-1437) pour valider une candidature locative.",
                'audience' => 'locataire',
                'items' => [
                    ['label' => "Pièce d'identité (CNI ou passeport)", 'doc_type' => 'cni', 'required' => 1],
                    ['label' => "Justificatif de domicile actuel", 'doc_type' => 'justif_domicile', 'required' => 1],
                    ['label' => "3 derniers bulletins de salaire", 'doc_type' => 'bulletin_salaire', 'required' => 1],
                    ['label' => "Dernier ou avant-dernier avis d'imposition", 'doc_type' => 'avis_imposition', 'required' => 1],
                    ['label' => "Contrat de travail ou attestation employeur", 'doc_type' => 'contrat_travail', 'required' => 1],
                    ['label' => "3 dernières quittances de loyer (ou attestation d'hébergement)", 'doc_type' => 'quittance_loyer', 'required' => 0],
                ],
            ],
            [
                'code' => 'projet_salaires_agence',
                'nom'  => 'Projet de salaires par agence',
                'description' => "Demande au comptable du projet de salaires pour chaque agence (1 carte par agence).",
                'audience' => 'comptable',
                'items' => [
                    // Généré dynamiquement par agence à la création (generator=agences).
                    ['label' => "Projet de salaires", 'doc_type' => 'projet_salaires', 'required' => 1, 'generator' => 'agences'],
                ],
            ],
            [
                'code' => 'bilan_comptable',
                'nom'  => 'Bilan comptable',
                'description' => "Demande des bilans/liasses à l'expert-comptable.",
                'audience' => 'comptable',
                'items' => [
                    ['label' => "Bilan", 'doc_type' => 'bilan', 'required' => 1],
                    ['label' => "Liasse fiscale", 'doc_type' => 'liasse_fiscale', 'required' => 0],
                    ['label' => "Grand livre", 'doc_type' => 'grand_livre', 'required' => 0],
                ],
            ],
        ];
    }
}

if (!function_exists('dr_seed_templates')) {
    function dr_seed_templates(PDO $pdo): void
    {
        $ins = $pdo->prepare("INSERT IGNORE INTO document_request_templates (code, nom, description, audience, items_json)
                              VALUES (?,?,?,?,?)");
        foreach (dr_default_templates() as $t) {
            $ins->execute([$t['code'], $t['nom'], $t['description'], $t['audience'],
                json_encode($t['items'], JSON_UNESCAPED_UNICODE)]);
        }
    }
}

if (!function_exists('dr_templates')) {
    function dr_templates(PDO $pdo): array
    {
        try {
            dr_seed_templates($pdo);
            return $pdo->query("SELECT code, nom, description, audience, items_json
                                FROM document_request_templates WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}

/* ── Token + URL ──────────────────────────────────────────────────────── */
if (!function_exists('dr_gen_token')) {
    function dr_gen_token(): string { return bin2hex(random_bytes(24)); } // 48 hex
}
if (!function_exists('dr_public_url')) {
    function dr_public_url(string $token): string
    {
        // URL ABSOLUE obligatoire : ce lien part dans un email. Un lien relatif
        // (/p/document_depot.php) est interprété par Outlook comme un chemin de
        // fichier local Windows (\p\document_depot.php) => alerte sécurité puis
        // « fichier introuvable ». On force toujours scheme + host.
        $base = function_exists('app_url') ? app_url('/p/document_depot.php') : '/p/document_depot.php';
        if (strpos($base, 'http') === 0) {
            return $base . '?t=' . $token;
        }
        $host   = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
        $isLocal = (stripos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
        $https   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        $scheme  = ($https || !$isLocal) ? 'https' : 'http';
        return $scheme . '://' . $host . $base . '?t=' . $token;
    }
}

/* ── Création ─────────────────────────────────────────────────────────── */
if (!function_exists('dr_create_request')) {
    /**
     * @param array $req   titre, message, recipient_email, recipient_name, entity_type, entity_id,
     *                     societe_id, agence_id, created_by, template_code, require_email_gate,
     *                     expires_at, reminder_mode, reminder_first_at, reminder_interval_days
     * @param array $items liste de [label, doc_type, entity_type, entity_id, period, required]
     * @return array [ok, id, token, url] | [ok=false, error]
     */
    function dr_create_request(PDO $pdo, array $req, array $items): array
    {
        $email = trim((string)($req['recipient_email'] ?? ''));
        $titre = trim((string)($req['titre'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Email destinataire invalide'];
        if ($titre === '') return ['ok' => false, 'error' => 'Titre requis'];
        $items = array_values(array_filter($items, fn($i) => trim((string)($i['label'] ?? '')) !== ''));
        if (!$items) return ['ok' => false, 'error' => 'Au moins une pièce est requise'];

        $token = dr_gen_token();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO document_requests
                (token, template_code, titre, message, recipient_email, recipient_name, entity_type, entity_id,
                 societe_id, agence_id, created_by, require_email_gate, expires_at,
                 reminder_mode, reminder_first_at, reminder_interval_days)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $token,
                $req['template_code'] ?? null,
                $titre,
                $req['message'] ?? null,
                $email,
                $req['recipient_name'] ?? null,
                $req['entity_type'] ?? null,
                isset($req['entity_id']) ? (int)$req['entity_id'] : null,
                isset($req['societe_id']) ? (int)$req['societe_id'] : null,
                isset($req['agence_id']) ? (int)$req['agence_id'] : null,
                isset($req['created_by']) ? (int)$req['created_by'] : null,
                !empty($req['require_email_gate']) ? 1 : 0,
                $req['expires_at'] ?? null,
                in_array(($req['reminder_mode'] ?? 'none'), ['none','once','recurring'], true) ? ($req['reminder_mode'] ?? 'none') : 'none',
                $req['reminder_first_at'] ?? null,
                isset($req['reminder_interval_days']) && $req['reminder_interval_days'] !== '' ? (int)$req['reminder_interval_days'] : null,
            ]);
            $reqId = (int)$pdo->lastInsertId();

            $sti = $pdo->prepare("INSERT INTO document_request_items
                (request_id, label, doc_type, kind, max_files, entity_type, entity_id, period, required, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $ord = 0;
            $kinds = ['file','files','text','photos'];
            foreach ($items as $it) {
                $kind = in_array(($it['kind'] ?? 'file'), $kinds, true) ? ($it['kind'] ?? 'file') : 'file';
                $maxF = isset($it['max_files']) && (int)$it['max_files'] > 0 ? (int)$it['max_files'] : ($kind === 'file' || $kind === 'text' ? 1 : 10);
                $sti->execute([
                    $reqId,
                    trim((string)$it['label']),
                    $it['doc_type'] ?? null,
                    $kind,
                    $maxF,
                    $it['entity_type'] ?? ($req['entity_type'] ?? null),
                    isset($it['entity_id']) && $it['entity_id'] !== '' ? (int)$it['entity_id'] : (isset($req['entity_id']) ? (int)$req['entity_id'] : null),
                    $it['period'] ?? null,
                    isset($it['required']) ? (int)!empty($it['required']) : 1,
                    $ord++,
                ]);
            }
            $pdo->commit();
            return ['ok' => true, 'id' => $reqId, 'token' => $token, 'url' => dr_public_url($token)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

/* ── Lecture ──────────────────────────────────────────────────────────── */
if (!function_exists('dr_get_by_token')) {
    function dr_get_by_token(PDO $pdo, string $token): ?array
    {
        $st = $pdo->prepare("SELECT * FROM document_requests WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
if (!function_exists('dr_get')) {
    function dr_get(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM document_requests WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
if (!function_exists('dr_items')) {
    function dr_items(PDO $pdo, int $requestId): array
    {
        $st = $pdo->prepare("SELECT * FROM document_request_items WHERE request_id = ? ORDER BY sort_order, id");
        $st->execute([$requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('dr_is_valid')) {
    function dr_is_valid(array $req): bool
    {
        if (in_array($req['status'] ?? '', ['revoque', 'expire'], true)) return false;
        if (!empty($req['expires_at']) && strtotime((string)$req['expires_at']) < time()) return false;
        return true;
    }
}

/* ── Ingestion d'un dépôt → GED + marquage reçu ───────────────────────── */
if (!function_exists('dr_commit_deposit')) {
    /**
     * Classe un fichier déposé dans la GED (gus_commit_document) et passe la pièce en "reçu".
     * @param array $upload  ['tmp_path','name_original','mime_type','size_bytes']
     * @return array [ok, doc_id] | [ok=false, error]
     */
    function dr_commit_deposit(PDO $pdo, array $req, array $item, array $upload): array
    {
        $entityType = $item['entity_type'] ?: ($req['entity_type'] ?: null);
        $entityId   = (int)($item['entity_id'] ?: ($req['entity_id'] ?: 0));
        $socId      = (int)($req['societe_id'] ?? 0) ?: 1;
        $ageId      = (int)($req['agence_id'] ?? 0) ?: 0;
        $dateDoc    = date('Y-m-d');

        // Entités niveau organisation : classées via ctx (societe_id/agence_id), PAS via lien GED.
        $gedLinkTypes = ['BIEN','IMMEUBLE','IMB','TIERS','BAIL'];
        $entityTypeU  = strtoupper((string)$entityType);
        if ($entityTypeU === 'AGENCE')  { $ageId = $entityId ?: $ageId; $entityType = null; $entityId = 0; }
        elseif ($entityTypeU === 'SOCIETE') { $socId = $entityId ?: $socId; $entityType = null; $entityId = 0; }

        // Fichier persistant
        $permDir = __DIR__ . '/../uploads/document_requests/' . (int)$req['id'] . '/';
        if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
        $ext = pathinfo((string)$upload['name_original'], PATHINFO_EXTENSION);
        $permName = 'item' . (int)$item['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext ? '.' . $ext : '');
        $permPath = $permDir . $permName;
        if (!@rename($upload['tmp_path'], $permPath)) { @copy($upload['tmp_path'], $permPath); @unlink($upload['tmp_path']); }
        $publicUrl = '/uploads/document_requests/' . (int)$req['id'] . '/' . $permName;

        $docType = strtoupper((string)($item['doc_type'] ?: 'DOCUMENT'));
        $links = [];
        if ($entityType && $entityId > 0 && in_array(strtoupper($entityType), $gedLinkTypes, true)) {
            $links[] = ['entity_type' => strtoupper($entityType), 'entity_id' => $entityId, 'relation_type' => 'main'];
        }

        try {
            $res = gus_commit_document($pdo,
                [
                    'path_on_disk' => $permPath,
                    'name_original' => (string)$upload['name_original'],
                    'mime_type' => (string)($upload['mime_type'] ?: 'application/octet-stream'),
                    'size_bytes' => (int)($upload['size_bytes'] ?? (filesize($permPath) ?: 0)),
                    'public_url' => $publicUrl,
                ],
                [
                    'document_type' => $docType,
                    'source_module' => 'DEMANDE_DOCUMENT',
                    'security_level' => 'interne',
                    'societe_id' => $socId,
                    'agence_id' => $ageId ?: null,
                    'tenant_id' => $socId,
                    'created_by' => isset($req['created_by']) ? (int)$req['created_by'] : null,
                    'storage_provider' => 'local',
                    'name_display' => $item['label'] . ($item['period'] ? ' — ' . $item['period'] : ''),
                    'metadata_extra' => [
                        'source' => 'demande_document',
                        'request_id' => (int)$req['id'],
                        'item_id' => (int)$item['id'],
                        'recipient_email' => $req['recipient_email'] ?? '',
                        'doc_date' => $dateDoc,
                    ],
                    'naming_ctx' => [
                        'type_doc' => $docType,
                        'entity_type' => $entityType ?: 'SOCIETE',
                        'entity_id' => $entityId ?: $socId,
                        'date_doc' => $dateDoc,
                        'source_filename' => (string)$upload['name_original'],
                    ],
                ],
                $links
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($res['ok'])) return ['ok' => false, 'error' => 'GED: ' . json_encode($res['errors'] ?? ['unknown'])];

        $docId = (int)($res['doc_id'] ?? 0);
        $pdo->prepare("UPDATE document_request_items SET status='recu', ged_document_id=?, original_name=?, received_at=NOW() WHERE id=?")
            ->execute([$docId, (string)$upload['name_original'], (int)$item['id']]);
        dr_recompute_status($pdo, (int)$req['id']);
        return ['ok' => true, 'doc_id' => $docId];
    }
}

/* ── Statut global recalculé ──────────────────────────────────────────── */
if (!function_exists('dr_recompute_status')) {
    function dr_recompute_status(PDO $pdo, int $requestId): string
    {
        $items = dr_items($pdo, $requestId);
        $tot = count($items);
        $recus = count(array_filter($items, fn($i) => $i['status'] === 'recu'));
        $reqLeft = count(array_filter($items, fn($i) => (int)$i['required'] === 1 && $i['status'] !== 'recu'));
        $status = 'en_attente';
        if ($tot > 0 && $reqLeft === 0) $status = 'complet';
        elseif ($recus > 0)             $status = 'partiel';
        $completed = $status === 'complet' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE document_requests SET status = ?, completed_at = ? WHERE id = ? AND status NOT IN ('revoque','expire')")
            ->execute([$status, $completed, $requestId]);
        return $status;
    }
}
