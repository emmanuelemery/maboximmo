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
            // En cas de pollution, on dégrade gracieusement : on ajoute le bruit en debug
            $payload['_warning_html_leak'] = mb_substr($junk, 0, 500);
            $payload['ok'] = false;
        }
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    fbx_api_respond(false, ['errors' => ['Méthode non autorisée']]);
}

// CSRF simple (réutilise le pattern session)
$csrfReceived = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfExpected = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfExpected !== '' && !hash_equals($csrfExpected, $csrfReceived)) {
    // En V1 on est lax si csrf_token n'est pas en session (pas tous les bootstraps l'initialisent)
    // En prod il faudra durcir : http_response_code(403); fbx_api_respond(false, ['errors'=>['CSRF']]);
}

// Lecture body : JSON ou form
$body = $_POST;
$rawJson = (string)file_get_contents('php://input');
if ($rawJson !== '' && (str_starts_with($rawJson, '{') || str_starts_with($rawJson, '['))) {
    $decoded = json_decode($rawJson, true);
    if (is_array($decoded)) $body = $decoded + $body;
}

$action = (string)($body['action'] ?? '');
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
            $result = fluxbox_carte_validate($carteId, ['classement' => $classement], $pdo);
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

        case 'ingest': {
            // Upload fichier en flux → fluxbox_documents (anti-doublon SHA-256)
            // → cascade IA pour préparer une proposition → crée la carte
            // Si ZIP : extraction récursive (ZIP dans ZIP supportés).
            if (empty($_FILES['file'])) throw new InvalidArgumentException('Aucun fichier reçu (champ "file")');
            $tmp = (string)($_FILES['file']['tmp_name'] ?? '');
            $nom = (string)($_FILES['file']['name'] ?? 'upload.bin');
            $mime = (string)($_FILES['file']['type'] ?? '');
            if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Upload invalide');

            // Métadonnées user (commentaire + classement_hint + société/agence cible)
            $userComment = trim((string)($_POST['user_comment'] ?? ''));
            $classementHint = [
                'n1' => trim((string)($_POST['ged_n1'] ?? '')),  // métier
                'n2' => trim((string)($_POST['ged_n2'] ?? '')),  // domaine
                'n3' => trim((string)($_POST['ged_n3'] ?? '')),  // sous-domaine
            ];
            $targetSocieteId = (int)($_POST['target_societe_id'] ?? ($_SESSION['id_societe'] ?? 0));
            $targetAgenceId  = (int)($_POST['target_agence_id']  ?? ($_SESSION['id_agence']  ?? 0));
            // Date document : user explicit > extraction nom de fichier > null (= today)
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            if ($targetDate === '') {
                // Tentative auto sur le nom de fichier
                $extracted = ged_v3_extract_date_from_text($nom);
                if ($extracted) $targetDate = $extracted;
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

            // Détection ZIP → extraction récursive
            $isZip = strtolower(pathinfo($nom, PATHINFO_EXTENSION)) === 'zip'
                  || $mime === 'application/zip'
                  || $mime === 'application/x-zip-compressed';
            if ($isZip) {
                $zipStats = fluxbox_zip_extract_recursive($target, [
                    'source_type' => 'zip',
                    'source_meta' => [
                        'user_comment'    => $userComment,
                        'classement_hint' => $classementHint,
                        'original_zip'    => $nom,
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
                    foreach (['n1','n2','n3'] as $k) {
                        if ($classementHint[$k] !== '') $cascade['classement'][$k] = $classementHint[$k];
                    }
                    // Override date : user explicit > extraction depuis nom du fichier extrait
                    $perFileDate = $targetDate;
                    if ($perFileDate === '') {
                        $stN2 = $pdo->prepare("SELECT fichier_nom FROM fluxbox_documents WHERE id = ?");
                        $stN2->execute([$docId]);
                        $extractedNom = (string)$stN2->fetchColumn();
                        if ($extractedNom !== '') {
                            $auto = ged_v3_extract_date_from_text($extractedNom);
                            if ($auto) $perFileDate = $auto;
                        }
                    }
                    if ($perFileDate !== '') $cascade['classement']['date'] = $perFileDate;
                    $stN = $pdo->prepare("SELECT fichier_nom FROM fluxbox_documents WHERE id = ?");
                    $stN->execute([$docId]);
                    $fname = (string)$stN->fetchColumn();

                    $titre = $cascade['classement']['n6'] !== '' ? $cascade['classement']['n6'] : ($fname ?: 'Document');
                    $carteId = fluxbox_carte_create([
                        'document_id'  => $docId,
                        'titre'        => $titre,
                        'sous_titre'   => "Extrait de ZIP — " . ($userComment ?: ($cascade['raison'] ?? '')),
                        'priorite'     => (string)($cascade['priorite'] ?? 'normal'),
                        'confiance_ia' => (float)$cascade['confiance'],
                        'proposition'  => [
                            'classement'     => $cascade['classement'],
                            'niveau_utilise' => $cascade['niveau_utilise'],
                            'user_comment'   => $userComment,
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

            // ─── Cas fichier unique (non-ZIP) ───
            $ingest = fluxbox_documents_ingest([
                'path'         => $target,
                'fichier_nom'  => $nom,
                'source_type'  => 'manual',
                'mime_type'    => $mime,
                'source_meta'  => [
                    'user_comment'    => $userComment,
                    'classement_hint' => $classementHint,
                ],
            ], $pdo);

            if ($ingest['is_duplicate']) {
                fbx_api_respond(true, [
                    'data' => [
                        'is_duplicate' => true,
                        'seen_count'   => $ingest['seen_count'],
                        'message'      => "Document déjà reçu (vu {$ingest['seen_count']} fois). Aucun nouveau traitement.",
                    ],
                ]);
            }

            // Lance la cascade IA pour préparer la proposition
            $cascade = fluxbox_ia_run_cascade($ingest['id'], $pdo);

            // Override avec le classement_hint user (s'il a précisé n1/n2/n3, on respecte)
            foreach (['n1','n2','n3'] as $k) {
                if ($classementHint[$k] !== '') {
                    $cascade['classement'][$k] = $classementHint[$k];
                }
            }
            // Override date document si fourni / extrait
            if ($targetDate !== '') {
                $cascade['classement']['date'] = $targetDate;
            }

            // Crée la carte
            $titre = $cascade['classement']['n6'] ?: $nom;
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
                    'classement'     => $cascade['classement'],
                    'niveau_utilise' => $cascade['niveau_utilise'],
                    'user_comment'   => $userComment,
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

            $ingest = fluxbox_documents_ingest([
                'path'         => $target,
                'fichier_nom'  => $nameFromUrl,
                'source_type'  => 'webhook',
                'mime_type'    => $contentType,
                'source_meta'  => ['url' => $url],
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
            $titre = $cascade['classement']['n6'] ?? '';
            if ($titre === '') $titre = $nameFromUrl;
            $carteId = fluxbox_carte_create([
                'document_id'    => $ingest['id'],
                'titre'          => $titre,
                'sous_titre'     => $cascade['raison'] ?? null,
                'priorite'       => (string)($cascade['priorite'] ?? 'normal'),
                'confiance_ia'   => (float)$cascade['confiance'],
                'proposition'    => [
                    'classement'     => $cascade['classement'],
                    'niveau_utilise' => $cascade['niveau_utilise'],
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
