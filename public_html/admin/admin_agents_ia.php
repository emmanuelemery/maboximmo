<?php
declare(strict_types=1);

/**
 * Admin FluxBox — Agents IA (templates + clones tenant).
 *
 * Liste les 10 templates système (clone-only) + les agents du tenant (éditables).
 * Permet : cloner un template, éditer un agent tenant, toggle actif.
 *
 * Phase 1 — gestion des agents (cette page).
 * Phase 2 — éditeur tree sections/sous-sections/actions (page séparée à venir).
 *
 * Réservé super admin (id_role = 1).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/fluxbox_agents.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tenantId = (int)(ged_current_tenant_id() ?? 0);

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'clone_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            if ($tid <= 0) throw new RuntimeException('template_id manquant');
            $r = fluxbox_agents_clone_template($tid, $tenantId, $pdo);
            if (!$r['ok']) throw new RuntimeException(implode(' / ', $r['errors']));
            $msg = match ($r['action']) {
                'cloned'         => "✅ Template cloné. Agent #{$r['agent_id']} prêt à configurer.",
                'already_cloned' => "ℹ️ Agent déjà cloné (#{$r['agent_id']}).",
                default          => "Action : {$r['action']}",
            };
            $flash = ['type'=>'success', 'msg'=>$msg];
        }
        elseif ($action === 'toggle_active') {
            $aid = (int)($_POST['agent_id'] ?? 0);
            if ($aid <= 0) throw new RuntimeException('agent_id manquant');
            $ok = fluxbox_agents_toggle_active($aid, $pdo);
            $flash = ['type'=>'success', 'msg'=>$ok ? "🔄 Agent #$aid actif/inactif basculé." : "Aucun changement"];
        }
        elseif ($action === 'update_agent') {
            $aid = (int)($_POST['agent_id'] ?? 0);
            if ($aid <= 0) throw new RuntimeException('agent_id manquant');
            $changes = [
                'nom'                  => (string)($_POST['nom'] ?? ''),
                'description'          => (string)($_POST['description'] ?? ''),
                'provider'             => (string)($_POST['provider'] ?? 'anthropic'),
                'modele'               => (string)($_POST['modele'] ?? ''),
                'prompt_systeme'       => (string)($_POST['prompt_systeme'] ?? ''),
                'plafond_eur_mensuel'  => (float)($_POST['plafond_eur_mensuel'] ?? 75.00),
            ];
            $r = fluxbox_agents_update($aid, $changes, $pdo);
            if (!$r['ok']) throw new RuntimeException(implode(' / ', $r['errors']));
            $flash = ['type'=>'success', 'msg'=>"✅ Agent #$aid mis à jour."];
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'error', 'msg'=>'❌ ' . $e->getMessage()];
    }
}

$templates = fluxbox_agents_list_templates($pdo);
$tenantAgents = fluxbox_agents_list_tenant($tenantId, $pdo);

// Index par code pour savoir si un template est déjà cloné
$clonedCodes = [];
foreach ($tenantAgents as $a) $clonedCodes[$a['code']] = (int)$a['id'];

// Si édition demandée
$editAgentId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editAgent = $editAgentId > 0 ? fluxbox_agents_get($editAgentId, $pdo) : null;
if ($editAgent && (int)$editAgent['tenant_id'] !== $tenantId) $editAgent = null;

$layout_title          = 'Agents IA';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;
$layout_topbar_right   = '<a class="btn-outline" href="' . $h(app_url('/admin/admin_fluxbox_naming_review.php')) . '">🪪 Cartes à valider</a>
                         <a class="btn-outline" href="' . $h(app_url('/admin/admin_ged_glossaire.php')) . '">🏷️ Glossaire</a>';

ob_start();
?>

        <div class="content-wrap">
            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                    <?= $h($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <?php if ($editAgent): ?>
                <div class="card">
                    <h2>✏️ Édition — <?= $h($editAgent['nom']) ?> <span class="muted">(<?= $h($editAgent['code']) ?>)</span></h2>
                    <form method="post" class="edit-form">
                        <input type="hidden" name="action" value="update_agent">
                        <input type="hidden" name="agent_id" value="<?= (int)$editAgent['id'] ?>">

                        <label>Nom
                            <input type="text" name="nom" value="<?= $h($editAgent['nom']) ?>" required>
                        </label>
                        <label>Description
                            <textarea name="description" rows="2"><?= $h($editAgent['description']) ?></textarea>
                        </label>

                        <div class="row">
                            <label>Provider
                                <select name="provider">
                                    <?php foreach (['anthropic','openai','mindee','cascade'] as $p): ?>
                                        <option value="<?= $p ?>" <?= $editAgent['provider'] === $p ? 'selected' : '' ?>><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Modèle
                                <input type="text" name="modele" value="<?= $h($editAgent['modele']) ?>" placeholder="claude-sonnet-4-6 / gpt-4o-mini / mindee:bank-statement-v2">
                            </label>
                            <label>Plafond €/mois
                                <input type="number" name="plafond_eur_mensuel" value="<?= $h($editAgent['plafond_eur_mensuel']) ?>" min="0" step="5">
                            </label>
                        </div>

                        <label>Prompt système (NULL si provider Mindee)
                            <textarea name="prompt_systeme" rows="6"><?= $h($editAgent['prompt_systeme']) ?></textarea>
                        </label>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Enregistrer</button>
                            <a class="btn-outline" href="<?= $h(app_url('/admin/admin_agents_ia.php')) ?>">Annuler</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <h2>📦 Mes agents (société)</h2>
            <?php if (!$tenantAgents): ?>
                <p class="muted">Aucun agent encore cloné. Choisis un template ci-dessous pour démarrer.</p>
            <?php else: ?>
                <div class="agents-grid">
                    <?php foreach ($tenantAgents as $a): ?>
                        <div class="agent-card <?= $a['is_active'] ? '' : 'inactive' ?>">
                            <div class="agent-head">
                                <div>
                                    <div class="agent-code"><?= $h($a['code']) ?></div>
                                    <div class="agent-name"><?= $h($a['nom']) ?></div>
                                </div>
                                <span class="badge badge-<?= $h($a['provider']) ?>"><?= $h($a['provider']) ?></span>
                            </div>
                            <div class="agent-desc"><?= $h($a['description']) ?></div>
                            <div class="agent-meta">
                                <span>📐 <?= $h($a['modele'] ?: '—') ?></span>
                                <span>💶 <?= $h($a['plafond_eur_mensuel']) ?>€/mois</span>
                            </div>
                            <div class="agent-actions">
                                <a class="btn-sm btn-outline" href="<?= $h(app_url('/admin/admin_agents_ia.php?edit=' . (int)$a['id'])) ?>">✏️ Éditer</a>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn-sm btn-outline">
                                        <?= $a['is_active'] ? '⏸️ Désactiver' : '▶️ Activer' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:32px">🧬 Templates système</h2>
            <p class="muted">Templates fournis — clone pour démarrer rapidement, puis personnalise.</p>

            <div class="agents-grid">
                <?php foreach ($templates as $t):
                    $isCloned = isset($clonedCodes[$t['code']]);
                ?>
                    <div class="agent-card template <?= $isCloned ? 'cloned' : '' ?>">
                        <div class="agent-head">
                            <div>
                                <div class="agent-code"><?= $h($t['code']) ?></div>
                                <div class="agent-name"><?= $h($t['nom']) ?></div>
                            </div>
                            <span class="badge badge-<?= $h($t['provider']) ?>"><?= $h($t['provider']) ?></span>
                        </div>
                        <div class="agent-desc"><?= $h($t['description']) ?></div>
                        <div class="agent-meta">
                            <span>📐 <?= $h($t['modele'] ?: '—') ?></span>
                            <span>💶 <?= $h($t['plafond_eur_mensuel']) ?>€/mois</span>
                        </div>
                        <div class="agent-actions">
                            <?php if ($isCloned): ?>
                                <span class="badge-cloned">✅ Cloné (#<?= (int)$clonedCodes[$t['code']] ?>)</span>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="clone_template">
                                    <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn-sm btn-primary">📋 Cloner</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
<?php
$layout_content = ob_get_clean();

$layout_extra_css = '<style>
.content-wrap{padding:24px;display:flex;flex-direction:column;gap:16px}
.muted{color:#667085}
.alert{padding:12px 14px;border-radius:10px}
.alert-success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}
.alert-error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}

.agents-page h2{margin:0;color:#243B5C;font-size:18px}

.agents-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:14px}
.agent-card{background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(16,24,40,.06);padding:16px;display:flex;flex-direction:column;gap:8px}
.agent-card.template{border-left:3px solid #D4A047}
.agent-card.template.cloned{opacity:.7;border-left-color:#039855}
.agent-card.inactive{opacity:.5}

.agent-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.agent-code{font-size:11px;color:#667085;letter-spacing:.05em;text-transform:uppercase;font-weight:600}
.agent-name{font-weight:700;color:#101828;font-size:15px;margin-top:2px}
.agent-desc{font-size:12px;color:#475467;line-height:1.45;min-height:34px}
.agent-meta{display:flex;gap:12px;font-size:11px;color:#667085}
.agent-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}

.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.badge-anthropic{background:#fef0c7;color:#b54708}
.badge-openai{background:#d1fadf;color:#039855}
.badge-mindee{background:#dbeafe;color:#1d4ed8}
.badge-cascade{background:#fce7f3;color:#be185d}
.badge-cloned{font-size:11px;color:#039855;font-weight:600}

.btn-sm{font-size:12px;padding:6px 12px;border-radius:6px;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#243B5C;color:#fff}
.btn-outline{background:#fff;border:1px solid #d0d5dd;color:#243B5C}

.card{background:#fff;border-radius:14px;box-shadow:0 8px 24px rgba(16,24,40,.08);padding:20px}
.edit-form{display:flex;flex-direction:column;gap:12px;margin-top:12px}
.edit-form label{font-size:12px;color:#475467;display:flex;flex-direction:column;gap:4px;font-weight:600}
.edit-form input, .edit-form select, .edit-form textarea{padding:8px 10px;border:1px solid #d0d5dd;border-radius:8px;font-size:13px;font-family:inherit}
.edit-form textarea{resize:vertical;font-family:"Courier New", monospace;font-size:12px}
.edit-form .row{display:grid;grid-template-columns:1fr 1.5fr 1fr;gap:12px}
.form-actions{display:flex;gap:8px;margin-top:6px}
</style>';

require_once __DIR__ . '/../inc/layout_maboximmo.php';
