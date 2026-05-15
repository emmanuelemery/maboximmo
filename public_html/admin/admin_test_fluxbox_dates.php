<?php
declare(strict_types=1);

/**
 * Test ciblé : extraction date document avec Claude Vision sur N relevés bancaires.
 *
 * Workflow :
 *  1. Sélectionne N ged_documents récents (filtrables : groupe, source FluxBox)
 *  2. Pour chaque, lit le PDF physique via fluxbox_documents.fichier_chemin
 *  3. Envoie à Claude Sonnet Vision pour extraire mois/année
 *  4. Si extraction OK → renomme : INSERT MM-YYYY avant la date d'upload (YYYYMMDD à la fin)
 *  5. Affiche tableau récap avant/après + log d'erreurs
 *
 * Sécurité : réservé role=1 (super admin). Nécessite ANTHROPIC_API_KEY.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_functions.php';
require_once __DIR__ . '/../inc/ged_naming_v3.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tenantId = (int)(ged_current_tenant_id() ?? 0);

$results = [];
$errors  = [];
$totalCost = 0.0;
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
$limit  = max(1, min(50, (int)($_POST['limit'] ?? 10)));
$applyChanges = !empty($_POST['apply']);
$sourceModule = (string)($_POST['source_module'] ?? '06_COMPTABILITE');

// Vérifie la clé Anthropic
$hasAnthropicKey = false;
try {
    require_once __DIR__ . '/../modules/ged/ged_vision.php';
    $hasAnthropicKey = function_exists('ged_vision_anthropic_key') && ged_vision_anthropic_key() !== '';
} catch (Throwable $e) {
    $errors[] = "Impossible de charger ged_vision : " . $e->getMessage();
}

if ($action === 'run' && $hasAnthropicKey) {
    // Sélection des documents
    $sql = "SELECT d.id, d.uuid, d.name_canonical, d.name_file, d.source_module, d.metadata,
                   d.fluxbox_source_id, d.size_bytes,
                   f.fichier_chemin, f.fichier_nom, f.mime_type
            FROM ged_documents d
            INNER JOIN fluxbox_documents f ON f.id = d.fluxbox_source_id
            WHERE d.tenant_id = ?
              AND d.status = 'active'
              AND d.fluxbox_source_id IS NOT NULL";
    $params = [$tenantId];
    if ($sourceModule !== '') {
        $sql .= " AND d.source_module = ?";
        $params[] = $sourceModule;
    }
    $sql .= " ORDER BY d.id DESC LIMIT $limit";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $errors[] = "Erreur SQL: " . $e->getMessage();
        $docs = [];
    }

    foreach ($docs as $doc) {
        $r = [
            'doc_id'   => (int)$doc['id'],
            'old_name' => $doc['name_canonical'],
            'flux_path'=> $doc['fichier_chemin'],
            'flux_nom' => $doc['fichier_nom'],
            'status'   => 'pending',
            'date_extracted' => null,
            'new_name' => null,
            'error'    => null,
            'cost_eur' => 0.0,
            'model'    => null,
            'duration_ms' => 0,
        ];

        $path = (string)$doc['fichier_chemin'];
        if (!is_file($path) || !is_readable($path)) {
            $r['status'] = 'error';
            $r['error']  = "Fichier physique introuvable : " . $path;
            $results[] = $r;
            continue;
        }

        try {
            // Prompt Vision orienté extraction date sur relevé bancaire
            $sys = <<<SYS
Tu es un assistant qui extrait UNIQUEMENT la période concernée par un document financier (relevé bancaire, facture, bulletin).
RÈGLES :
- Tu cherches la date principale du document (la PÉRIODE qu'il concerne), pas la date d'impression ou d'envoi.
- Pour un relevé bancaire : la période de relevé (ex "du 1er au 31 mars 2026" → "2026-03").
- Pour une facture : la date d'émission.
- Pour un bulletin de paie : le mois concerné.
- Réponds STRICTEMENT en JSON valide avec ce schéma :
  { "year": 2026, "month": 3, "day": 31, "confidence": 0.95, "raison": "Bref texte" }
  - month: 1-12 (entier)
  - day: 1-31 ou null si on ne connaît que mois/année
  - confidence: 0.0 à 1.0
- Si tu ne trouves PAS la date : { "year": null, "month": null, "day": null, "confidence": 0, "raison": "Pas trouvé" }
- N'invente JAMAIS. Si doute → null.
SYS;
            $usr = "Extrais la période concernée par ce document. Réponds UNIQUEMENT le JSON demandé.";

            $t0 = microtime(true);
            $resp = gedVisionExtract($path, $sys, $usr);
            $r['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
            $r['model'] = (string)($resp['model_used'] ?? 'unknown');
            $data = $resp['data'] ?? [];

            $year  = isset($data['year'])  && is_numeric($data['year'])  ? (int)$data['year']  : null;
            $month = isset($data['month']) && is_numeric($data['month']) ? (int)$data['month'] : null;
            $conf  = isset($data['confidence']) ? (float)$data['confidence'] : 0.0;

            if ($year === null || $month === null || $month < 1 || $month > 12 || $year < 1900 || $year > 2100) {
                $r['status'] = 'no_date';
                $r['error']  = "Pas de date extraite (raison IA : " . (string)($data['raison'] ?? '—') . ")";
                $results[] = $r;
                continue;
            }

            $mmYyyy = sprintf('%02d-%04d', $month, $year);
            $r['date_extracted'] = $mmYyyy;
            $r['confidence'] = $conf;

            // Calcul du nouveau nom : INSERT MM-YYYY juste avant la date d'upload (YYYYMMDD à la fin)
            $old = (string)$doc['name_canonical'];
            $ext = pathinfo($old, PATHINFO_EXTENSION);
            $base = pathinfo($old, PATHINFO_FILENAME);
            // Si finit par _YYYYMMDD → on insère MM-YYYY juste avant
            if (preg_match('/^(.*)_(\d{8})$/', $base, $m)) {
                $newBase = $m[1] . '_' . $mmYyyy . '_' . $m[2];
            } else {
                // Sinon on append à la fin
                $newBase = $base . '_' . $mmYyyy;
            }
            $newName = $newBase . ($ext !== '' ? ('.' . $ext) : '');
            $r['new_name'] = $newName;

            if ($applyChanges) {
                // UPDATE BDD
                $meta = !empty($doc['metadata']) ? (json_decode((string)$doc['metadata'], true) ?: []) : [];
                $meta['date_extracted_by_vision'] = [
                    'mm_yyyy'    => $mmYyyy,
                    'confidence' => $conf,
                    'model'      => $r['model'],
                    'raison'     => $data['raison'] ?? null,
                    'extracted_at' => date('Y-m-d H:i:s'),
                ];
                $meta['renamed_from'] = $old;
                $pdo->prepare("
                    UPDATE ged_documents
                    SET name_canonical = ?, name_file = ?, metadata = ?, updated_at = NOW()
                    WHERE id = ? AND tenant_id = ?
                ")->execute([$newName, $newName, json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$doc['id'], $tenantId]);
                $r['status'] = 'applied';
            } else {
                $r['status'] = 'dry_run';
            }

            // Coût estimé (Sonnet ~ 0.003-0.005 € par doc petit)
            $r['cost_eur'] = $r['model'] === GED_MODEL_EXTRACTION ? 0.005 : 0.001;
            $totalCost += $r['cost_eur'];

        } catch (Throwable $e) {
            $r['status'] = 'error';
            $r['error']  = $e->getMessage();
        }

        $results[] = $r;
    }
}

$layout_title          = 'GED — Test extraction dates';
$layout_module         = 'Ma GED Box';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.tfd-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 0 40px; }
.tfd-h1 { font-family:"Sora",sans-serif; font-size:26px; color:#243B5C; margin:0 0 10px; }
.tfd-sub { color:#64748b; font-size:13px; margin-bottom:20px; }
.tfd-form { background:#fff; padding:16px 20px; border-radius:14px;
            box-shadow:4px 4px 12px rgba(196,192,186,0.5), -4px -4px 12px #fff;
            margin-bottom:18px; }
.tfd-form-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.tfd-field { display:flex; flex-direction:column; gap:4px; }
.tfd-field label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; }
.tfd-field input, .tfd-field select { padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:13px; min-width:160px; }
.tfd-toggle { display:flex; align-items:center; gap:6px; padding:8px 12px; background:#f8fafc; border-radius:8px; font-size:13px; cursor:pointer; }
.tfd-btn { padding:10px 22px; border-radius:10px; border:none; cursor:pointer; font-weight:700; font-size:14px; }
.tfd-btn-primary { background:linear-gradient(135deg,#243B5C,#1e3050); color:#fff; }
.tfd-btn-warn { background:linear-gradient(135deg,#D4A047,#b88835); color:#1a1816; }
.tfd-alert { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; }
.tfd-alert-ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.tfd-alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.tfd-alert-info { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.tfd-table { width:100%; border-collapse:collapse; background:#fff; border-radius:14px; overflow:hidden;
             box-shadow:4px 4px 12px rgba(196,192,186,0.5), -4px -4px 12px #fff; font-size:12px; }
.tfd-table th, .tfd-table td { padding:9px 12px; text-align:left; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.tfd-table th { background:#f8fafc; font-size:10px; text-transform:uppercase; color:#64748b; letter-spacing:0.05em; }
.tfd-status { padding:2px 8px; border-radius:6px; font-weight:700; font-size:10px; }
.tfd-status-applied  { background:#dcfce7; color:#166534; }
.tfd-status-dry_run  { background:#dbeafe; color:#1e40af; }
.tfd-status-no_date  { background:#fef3c7; color:#92400e; }
.tfd-status-error    { background:#fee2e2; color:#991b1b; }
.tfd-status-pending  { background:#f1f5f9; color:#64748b; }
.tfd-canon { font-family:"JetBrains Mono",monospace; font-size:11px; color:#475569; word-break:break-all; }
.tfd-canon-new { color:#243B5C; font-weight:600; }
.tfd-summary { background:#fff; border-radius:14px; padding:14px 18px; margin-bottom:14px;
               box-shadow:4px 4px 12px rgba(196,192,186,0.5), -4px -4px 12px #fff;
               display:flex; gap:24px; flex-wrap:wrap; }
.tfd-summary strong { color:#243B5C; font-size:18px; font-weight:700; display:block; }
.tfd-summary small  { color:#64748b; font-size:11px; }
</style>';

ob_start();
?>
<div class="tfd-wrap">

    <h1 class="tfd-h1">🧪 Test extraction dates (Claude Vision)</h1>
    <p class="tfd-sub">
        Test ciblé : envoie N relevés bancaires (déjà classés via FluxBox) à Claude Vision pour extraire
        le mois/année du document, puis renomme en insérant <code>MM-YYYY</code> avant la date d'upload.
    </p>

    <?php if (!$hasAnthropicKey): ?>
        <div class="tfd-alert tfd-alert-err">
            ❌ ANTHROPIC_API_KEY non configurée. Vérifie <code>config/anthropic.php</code> ou la variable d'env.
            (Le test ne peut pas tourner sans clé.)
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <div class="tfd-alert tfd-alert-err">❌ <?= $h($err) ?></div>
    <?php endforeach; ?>

    <!-- Form -->
    <form method="POST" class="tfd-form" <?= !$hasAnthropicKey ? 'style="opacity:0.5;pointer-events:none"' : '' ?>>
        <div class="tfd-form-row">
            <div class="tfd-field">
                <label>Nombre de docs à tester</label>
                <input type="number" name="limit" value="<?= (int)$limit ?>" min="1" max="50">
            </div>
            <div class="tfd-field">
                <label>Filtre source_module</label>
                <select name="source_module">
                    <option value="06_COMPTABILITE" <?= $sourceModule === '06_COMPTABILITE' ? 'selected' : '' ?>>06_COMPTABILITE</option>
                    <option value="" <?= $sourceModule === '' ? 'selected' : '' ?>>— Tous —</option>
                </select>
            </div>
            <label class="tfd-toggle">
                <input type="checkbox" name="apply" value="1" <?= $applyChanges ? 'checked' : '' ?>>
                Appliquer les changements (UPDATE ged_documents)
            </label>
            <input type="hidden" name="action" value="run">
            <button type="submit" class="tfd-btn <?= $applyChanges ? 'tfd-btn-warn' : 'tfd-btn-primary' ?>">
                <?= $applyChanges ? '⚠️ Lancer + Appliquer' : '🧪 Lancer en DRY-RUN' ?>
            </button>
        </div>
    </form>

    <?php if ($action === 'run' && count($results) > 0):
        $okCount  = count(array_filter($results, fn($r) => in_array($r['status'], ['applied','dry_run'], true)));
        $noDate   = count(array_filter($results, fn($r) => $r['status'] === 'no_date'));
        $errCount = count(array_filter($results, fn($r) => $r['status'] === 'error'));
    ?>
        <!-- Résumé -->
        <div class="tfd-summary">
            <div><strong><?= count($results) ?></strong><small>Documents testés</small></div>
            <div><strong style="color:#16a34a"><?= $okCount ?></strong><small>Dates extraites</small></div>
            <div><strong style="color:#ca8a04"><?= $noDate ?></strong><small>Date introuvable</small></div>
            <div><strong style="color:#dc2626"><?= $errCount ?></strong><small>Erreurs techniques</small></div>
            <div><strong><?= number_format($totalCost, 4, ',', ' ') ?> €</strong><small>Coût IA estimé</small></div>
            <div>
                <strong style="color:<?= $applyChanges ? '#16a34a' : '#1e40af' ?>">
                    <?= $applyChanges ? 'APPLIQUÉ' : 'DRY-RUN' ?>
                </strong>
                <small><?= $applyChanges ? 'BDD modifiée' : 'Aucune modif en BDD' ?></small>
            </div>
        </div>

        <!-- Table résultats -->
        <table class="tfd-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Date extraite</th>
                    <th>Avant</th>
                    <th>Après</th>
                    <th>Fichier source</th>
                    <th>Coût / Durée</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><?= (int)$r['doc_id'] ?></td>
                    <td>
                        <span class="tfd-status tfd-status-<?= $h($r['status']) ?>">
                            <?php
                            echo [
                                'applied' => '✅ APPLIQUÉ',
                                'dry_run' => '🧪 DRY-RUN',
                                'no_date' => '⚠️ NO DATE',
                                'error'   => '❌ ERREUR',
                                'pending' => '⏳',
                            ][$r['status']] ?? $h($r['status']);
                            ?>
                        </span>
                    </td>
                    <td><strong><?= $h($r['date_extracted'] ?? '—') ?></strong>
                        <?php if (!empty($r['confidence'])): ?>
                            <br><small>conf <?= round(((float)$r['confidence']) * 100) ?>%</small>
                        <?php endif; ?>
                    </td>
                    <td class="tfd-canon"><?= $h($r['old_name']) ?></td>
                    <td class="tfd-canon tfd-canon-new"><?= $h($r['new_name'] ?? '—') ?></td>
                    <td>
                        <small class="tfd-canon"><?= $h($r['flux_nom'] ?? '') ?></small>
                        <?php if (!empty($r['error'])): ?>
                            <br><small style="color:#dc2626">⚠ <?= $h($r['error']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= number_format($r['cost_eur'], 4, ',', ' ') ?> €</small>
                        <?php if ($r['duration_ms'] > 0): ?>
                            <br><small><?= (int)$r['duration_ms'] ?> ms</small>
                        <?php endif; ?>
                        <?php if (!empty($r['model'])): ?>
                            <br><small style="color:#94a3b8"><?= $h($r['model']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!$applyChanges && $okCount > 0): ?>
            <div class="tfd-alert tfd-alert-info" style="margin-top:14px">
                ℹ️ Résultats en mode DRY-RUN — aucune modification appliquée à la BDD.
                Relance avec <strong>"Appliquer les changements"</strong> coché pour les pousser.
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'run'): ?>
        <div class="tfd-alert tfd-alert-info">
            Aucun document trouvé pour ces filtres. Vérifie qu'il y a bien des
            <code>ged_documents</code> avec <code>fluxbox_source_id IS NOT NULL</code>
            et <code>source_module = '<?= $h($sourceModule) ?>'</code>.
        </div>
    <?php endif; ?>

</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/../inc/layout_maboximmo.php';
?>
