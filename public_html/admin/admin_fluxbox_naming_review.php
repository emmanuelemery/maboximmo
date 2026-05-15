<?php
declare(strict_types=1);

/**
 * Admin FluxBox — Cartes à valider (nommage Variante A).
 *
 * Liste les cartes dont naming_status='needs_review' : ce sont les cartes où
 * Vision a extrait quelque chose mais qui n'a pas matché dans le glossaire,
 * ou un segment obligatoire n'a pas pu être identifié.
 *
 * Actions admin par segment manquant :
 *   - banque_unknown   → créer le code banque dans le glossaire (label + code court)
 *   - immeuble_unknown → créer le code immeuble dans le glossaire
 *   - compte4_missing  → saisir les 4 derniers chiffres du compte
 *   - periode_missing  → saisir MM-AAAA
 *
 * Après chaque action, on recalcule naming_resolved_json + naming_proposed
 * via fluxbox_va_rebuild_name(). La carte repasse en 'ready' quand tous les
 * segments sont remplis et peut être validée par le user dans FluxBox.
 *
 * Réservé super admin (id_role = 1).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_glossary.php';
require_once __DIR__ . '/../inc/fluxbox_naming_variant_a.php';
require_once __DIR__ . '/../inc/fluxbox_va_orchestrator.php';
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

// ────────────────────────────────────────────────────────────────
// Actions POST
// ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string)($_POST['action'] ?? '');
    $carteId = (int)($_POST['carte_id'] ?? 0);

    // ── Batch : analyse N cartes en 'pending' (pas de carte_id requis) ──
    if ($action === 'batch_analyze') {
        @set_time_limit(600);
        @ignore_user_abort(true);
        $batchSize = max(1, min(100, (int)($_POST['batch_size'] ?? 20)));
        $modele    = (string)($_POST['modele'] ?? 'sonnet');

        try {
            $st = $pdo->prepare("
                SELECT c.id
                FROM fluxbox_cartes c
                WHERE c.tenant_id = ?
                  AND c.naming_status = 'pending'
                  AND c.document_id IS NOT NULL
                ORDER BY c.id ASC
                LIMIT $batchSize
            ");
            $st->execute([$tenantId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

            $stats = ['ready'=>0, 'needs_review'=>0, 'errors'=>0, 'cost_centimes'=>0];
            foreach ($ids as $cid) {
                try {
                    $r = fluxbox_va_analyze_carte($cid, $pdo, ['modele' => $modele]);
                    if (!$r['ok']) { $stats['errors']++; continue; }
                    if ($r['naming_status'] === 'ready') $stats['ready']++;
                    elseif ($r['naming_status'] === 'needs_review') $stats['needs_review']++;
                    $stats['cost_centimes'] += (int)($r['cout_centimes'] ?? 0);
                } catch (Throwable $e) {
                    $stats['errors']++;
                }
            }

            $remaining = (int)$pdo->query("
                SELECT COUNT(*) FROM fluxbox_cartes
                WHERE tenant_id = $tenantId AND naming_status = 'pending' AND document_id IS NOT NULL
            ")->fetchColumn();

            $costEur = number_format($stats['cost_centimes'] / 100, 2, ',', ' ');
            $flash = ['type'=>'success', 'msg'=>sprintf(
                "🤖 Batch terminé : %d traitées (✅%d ready, ⚠️%d à valider, ❌%d erreurs) — coût ~%s€. Reste %d en pending.",
                count($ids), $stats['ready'], $stats['needs_review'], $stats['errors'], $costEur, $remaining
            )];
        } catch (Throwable $e) {
            $flash = ['type'=>'error', 'msg' => '❌ Batch échoué : ' . $e->getMessage()];
        }
        goto end_post;
    }

    try {
        if ($carteId <= 0) throw new RuntimeException('carte_id manquant');

        // Charge la carte (et vérifie tenant)
        $stC = $pdo->prepare("SELECT * FROM fluxbox_cartes WHERE id = ? AND tenant_id = ?");
        $stC->execute([$carteId, $tenantId]);
        $carte = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$carte) throw new RuntimeException("Carte #$carteId introuvable");

        $resolved = !empty($carte['naming_resolved_json'])
            ? (json_decode((string)$carte['naming_resolved_json'], true) ?: [])
            : [];

        if ($action === 'create_glossary_code') {
            // Pour banque + type_document : code libre (entité non liée à une table métier)
            $cat   = (string)($_POST['category'] ?? '');
            $code  = trim((string)($_POST['code']  ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $resolvedKey = (string)($_POST['resolved_key'] ?? '');

            if (!in_array($cat, ['banque','type_document'], true)) {
                throw new RuntimeException("Catégorie invalide pour code libre : $cat");
            }
            if ($label === '') throw new RuntimeException('Label obligatoire');
            if ($resolvedKey === '') throw new RuntimeException('resolved_key manquant');

            if ($code === '') {
                $code = ged_glossary_derive_code($cat, $label, [], $pdo);
            } else {
                $code = ged_glossary_slug_code($code);
            }

            $result = ged_glossary_set([
                'category'    => $cat,
                'entity_table'=> '',
                'entity_id'   => null,
                'code'        => $code,
                'label'       => $label,
                'is_locked'   => 1,
                'is_active'   => 1,
                'notes'       => 'Créé depuis cartes à valider FluxBox',
            ], $pdo);

            if (!$result['ok']) {
                throw new RuntimeException(implode(' / ', $result['errors']));
            }

            $resolved[$resolvedKey] = $result['code'];
        }
        elseif ($action === 'link_immeuble') {
            // Lie la carte à un immeuble existant : récupère son code glossaire + dérive société/agence
            $immeubleId = (int)($_POST['immeuble_id'] ?? 0);
            if ($immeubleId <= 0) throw new RuntimeException('immeuble_id manquant');

            $st = $pdo->prepare("SELECT id, nom_immeuble, id_societe, id_agence FROM immeubles WHERE id = ? LIMIT 1");
            $st->execute([$immeubleId]);
            $imm = $st->fetch(PDO::FETCH_ASSOC);
            if (!$imm) throw new RuntimeException("Immeuble #$immeubleId introuvable");

            $glossImm = ged_glossary_get('immeuble', $immeubleId, $pdo);
            if (!$glossImm) throw new RuntimeException("Code glossaire absent pour immeuble #$immeubleId — vérifier le seed");

            $resolved['immeuble_code'] = (string)$glossImm['code'];
            $resolved['immeuble_id']   = $immeubleId;

            if (!empty($imm['id_societe'])) {
                $resolved['societe_id'] = (int)$imm['id_societe'];
                $g = ged_glossary_get('societe', (int)$imm['id_societe'], $pdo);
                if ($g) $resolved['societe_code'] = (string)$g['code'];
            }
            if (!empty($imm['id_agence'])) {
                $resolved['agence_id'] = (int)$imm['id_agence'];
                $g = ged_glossary_get('agence', (int)$imm['id_agence'], $pdo);
                if ($g) $resolved['agence_code'] = (string)$g['code'];
            }
        }
        elseif ($action === 'set_compte4') {
            $compte4 = preg_replace('/\D+/', '', (string)($_POST['compte4'] ?? '')) ?? '';
            if (strlen($compte4) < 4) throw new RuntimeException('Saisir au moins 4 chiffres');
            $resolved['compte4'] = substr($compte4, -4);
        }
        elseif ($action === 'set_periode') {
            $periode = trim((string)($_POST['periode'] ?? ''));
            $norm = fluxbox_va_format_periode_mm_yyyy($periode);
            if ($norm === null) throw new RuntimeException("Période illisible : $periode (attendu MM-AAAA)");
            $resolved['periode_mm_yyyy'] = $norm;
        }
        elseif ($action === 'set_immeuble_code') {
            $code = ged_glossary_slug_code((string)($_POST['code'] ?? ''));
            if ($code === '') throw new RuntimeException('Code immeuble vide');
            // Vérifier qu'il existe dans le glossaire
            $exists = ged_glossary_get_by_code('immeuble', $code, $pdo);
            if (!$exists) throw new RuntimeException("Code immeuble « $code » inexistant dans le glossaire");
            $resolved['immeuble_code'] = $code;
        }
        elseif ($action === 'set_banque_code') {
            $code = ged_glossary_slug_code((string)($_POST['code'] ?? ''));
            if ($code === '') throw new RuntimeException('Code banque vide');
            $exists = ged_glossary_get_by_code('banque', $code, $pdo);
            if (!$exists) throw new RuntimeException("Code banque « $code » inexistant dans le glossaire");
            $resolved['banque_code'] = $code;
        }
        elseif ($action === 'reanalyze') {
            $opts = ['force' => true, 'modele' => 'sonnet'];
            $res = fluxbox_va_analyze_carte($carteId, $pdo, $opts);
            if (!$res['ok']) throw new RuntimeException((string)($res['erreur'] ?? 'analyze failed'));
            $flash = ['type'=>'success', 'msg'=>"🔄 Carte #$carteId ré-analysée : statut « {$res['naming_status']} »."];
        }
        elseif ($action === 'skip_naming') {
            $pdo->prepare("
                UPDATE fluxbox_cartes
                SET naming_status = 'skipped', naming_review_reason = NULL
                WHERE id = ? AND tenant_id = ?
            ")->execute([$carteId, $tenantId]);
            $flash = ['type'=>'success', 'msg'=>"⏭️ Carte #$carteId passée en 'skipped' (nom legacy conservé)."];
        }
        else {
            throw new RuntimeException("Action inconnue : $action");
        }

        // Si l'action a modifié $resolved, persiste + rebuild
        if (in_array($action, ['create_glossary_code','link_immeuble','set_compte4','set_periode','set_immeuble_code','set_banque_code'], true)) {
            $pdo->prepare("
                UPDATE fluxbox_cartes SET naming_resolved_json = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([json_encode($resolved, JSON_UNESCAPED_UNICODE), $carteId, $tenantId]);

            $rebuilt = fluxbox_va_rebuild_name($carteId, $pdo);
            if ($flash === null) {
                $flash = ['type'=>'success',
                          'msg'=>"✅ Carte #$carteId mise à jour. Nouveau statut : « {$rebuilt['naming_status']} »."];
            }
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'error', 'msg'=>'❌ ' . $e->getMessage()];
    }
    end_post:
}

// ────────────────────────────────────────────────────────────────
// Compteur global des pending (pour bouton batch)
// ────────────────────────────────────────────────────────────────
$pendingCount = (int)$pdo->query("
    SELECT COUNT(*) FROM fluxbox_cartes
    WHERE tenant_id = $tenantId AND naming_status = 'pending' AND document_id IS NOT NULL
")->fetchColumn();

// ────────────────────────────────────────────────────────────────
// Lecture : cartes à valider
// ────────────────────────────────────────────────────────────────
$statusFilter = (string)($_GET['status'] ?? 'needs_review');
$reasonFilter = (string)($_GET['reason'] ?? '');

$where  = ['c.tenant_id = ?'];
$params = [$tenantId];

if ($statusFilter !== 'all') {
    $where[] = 'c.naming_status = ?';
    $params[] = $statusFilter;
}
if ($reasonFilter !== '') {
    $where[] = 'c.naming_review_reason = ?';
    $params[] = $reasonFilter;
}

$sql = "
    SELECT c.id, c.titre, c.sous_titre, c.naming_status, c.naming_review_reason,
           c.naming_extracted_json, c.naming_resolved_json, c.naming_proposed,
           c.created_at, c.document_id,
           d.fichier_nom AS doc_nom, d.fichier_chemin AS doc_chemin
    FROM fluxbox_cartes c
    LEFT JOIN fluxbox_documents d ON d.id = c.document_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.created_at DESC
    LIMIT 200
";
$stL = $pdo->prepare($sql);
$stL->execute($params);
$cartes = $stL->fetchAll(PDO::FETCH_ASSOC);

// Compteurs par raison
$stCount = $pdo->prepare("
    SELECT naming_review_reason AS reason, COUNT(*) AS n
    FROM fluxbox_cartes
    WHERE tenant_id = ? AND naming_status = 'needs_review'
    GROUP BY naming_review_reason
");
$stCount->execute([$tenantId]);
$counts = [];
foreach ($stCount->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $counts[(string)$r['reason']] = (int)$r['n'];
}
$totalReview = array_sum($counts);

$layout_title          = 'Cartes à valider — Nommage';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;
$layout_topbar_right   = '<a class="btn-outline" href="' . $h(app_url('/admin/admin_ged_glossaire.php')) . '">🏷️ Glossaire</a>
                         <a class="btn-outline" href="' . $h(app_url('/fluxbox.php')) . '">🃏 FluxBox</a>';

ob_start();
?>

        <div class="naming-review-page">
            <div class="page-head-local">
                <h1>Cartes à valider — Nommage Variante A</h1>
                <p class="muted">Segments non résolus par Vision. Complète, puis la carte repasse en « ready ».</p>
            </div>

            <div class="content-wrap">
            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                    <?= $h($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <div class="kpis">
                <div class="kpi-card"><div class="kpi-val"><?= $totalReview ?></div><div class="kpi-lbl">À valider total</div></div>
                <div class="kpi-card"><div class="kpi-val"><?= $h($counts['banque_unknown'] ?? 0) ?></div><div class="kpi-lbl">Banque inconnue</div></div>
                <div class="kpi-card"><div class="kpi-val"><?= $h($counts['immeuble_unknown'] ?? 0) ?></div><div class="kpi-lbl">Immeuble inconnu</div></div>
                <div class="kpi-card"><div class="kpi-val"><?= $h($counts['compte4_missing'] ?? 0) ?></div><div class="kpi-lbl">Compte4 manquant</div></div>
                <div class="kpi-card"><div class="kpi-val"><?= $h($counts['periode_missing'] ?? 0) ?></div><div class="kpi-lbl">Période manquante</div></div>
                <div class="kpi-card"><div class="kpi-val"><?= $h($counts['multiple'] ?? 0) ?></div><div class="kpi-lbl">Multiples</div></div>
            </div>

            <div class="filters">
                <form method="get">
                    <label>Statut :
                        <select name="status" onchange="this.form.submit()">
                            <?php foreach (['needs_review','ready','pending','extracted','applied','skipped','all'] as $s): ?>
                                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Raison :
                        <select name="reason" onchange="this.form.submit()">
                            <option value="">— toutes —</option>
                            <?php foreach (['banque_unknown','immeuble_unknown','compte4_missing','periode_missing','multiple','other'] as $r): ?>
                                <option value="<?= $r ?>" <?= $reasonFilter === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>

                <?php if ($pendingCount > 0): ?>
                <form method="post" class="batch-form" onsubmit="return confirm('Lancer Vision sur les prochaines cartes pending ? Coût estimé : ~' + (this.batch_size.value * 0.25).toFixed(2) + '€ (Sonnet) ou ' + (this.batch_size.value * 0.05).toFixed(2) + '€ (Haiku).');">
                    <input type="hidden" name="action" value="batch_analyze">
                    <span class="batch-info">🃏 <strong><?= $pendingCount ?></strong> cartes en pending</span>
                    <label>Lot de
                        <select name="batch_size">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100 (lent)</option>
                        </select>
                    </label>
                    <label>Modèle
                        <select name="modele">
                            <option value="sonnet">Sonnet 4.6 (25¢/doc)</option>
                            <option value="haiku">Haiku 4.5 (5¢/doc)</option>
                        </select>
                    </label>
                    <button type="submit" class="btn-primary btn-sm">🤖 Analyser le lot</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (!$cartes): ?>
                <div class="empty">
                    <p>🎉 Aucune carte à valider pour ce filtre.</p>
                </div>
            <?php else: ?>
                <?php foreach ($cartes as $c):
                    $extracted = !empty($c['naming_extracted_json']) ? (json_decode((string)$c['naming_extracted_json'], true) ?: []) : [];
                    $resolved  = !empty($c['naming_resolved_json'])  ? (json_decode((string)$c['naming_resolved_json'],  true) ?: []) : [];
                    $reason    = (string)($c['naming_review_reason'] ?? '');
                ?>
                <div class="carte-card">
                    <div class="carte-head">
                        <div>
                            <div class="carte-title">#<?= (int)$c['id'] ?> — <?= $h($c['titre']) ?></div>
                            <?php if (!empty($c['sous_titre'])): ?>
                                <div class="carte-sub"><?= $h($c['sous_titre']) ?></div>
                            <?php endif; ?>
                            <div class="muted small">📄 <?= $h($c['doc_nom'] ?? '(pas de doc)') ?></div>
                        </div>
                        <div class="badges">
                            <span class="badge badge-status-<?= $h($c['naming_status']) ?>"><?= $h($c['naming_status']) ?></span>
                            <?php if ($reason !== ''): ?>
                                <span class="badge badge-reason"><?= $h($reason) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="carte-body">
                        <div class="grid-2col">
                            <div>
                                <div class="block-title">📥 Extrait par Vision</div>
                                <div class="kv">
                                    <span>Banque texte</span><span><?= $h($extracted['banque_text']   ?? '—') ?></span>
                                    <span>Compte texte</span><span><?= $h($extracted['compte_text']   ?? '—') ?></span>
                                    <span>Période texte</span><span><?= $h($extracted['periode_text'] ?? '—') ?></span>
                                    <span>Immeuble texte</span><span><?= $h($extracted['immeuble_text']?? '—') ?></span>
                                    <span>Type texte</span><span><?= $h($extracted['type_text']      ?? '—') ?></span>
                                    <span>Confiance</span><span><?= $h($extracted['confidence']     ?? 0) ?>%</span>
                                </div>
                            </div>
                            <div>
                                <div class="block-title">🏷️ Résolu via glossaire</div>
                                <div class="kv">
                                    <span>Société</span><span><?= $h($resolved['societe_code']   ?? '—') ?></span>
                                    <span>Agence</span><span><?= $h($resolved['agence_code']    ?? '—') ?></span>
                                    <span>User</span><span><?= $h($resolved['user_code']       ?? '—') ?></span>
                                    <span>N1 / N2</span><span><?= $h(($resolved['n1_code'] ?? '—') . ' / ' . ($resolved['n2_code'] ?? '—')) ?></span>
                                    <span>Banque code</span><span class="<?= empty($resolved['banque_code']) ? 'missing' : '' ?>"><?= $h($resolved['banque_code'] ?? '— manquant —') ?></span>
                                    <span>Compte4</span><span class="<?= empty($resolved['compte4']) ? 'missing' : '' ?>"><?= $h($resolved['compte4'] ?? '— manquant —') ?></span>
                                    <span>Période</span><span class="<?= empty($resolved['periode_mm_yyyy']) ? 'missing' : '' ?>"><?= $h($resolved['periode_mm_yyyy'] ?? '— manquant —') ?></span>
                                    <span>Immeuble code</span><span class="<?= empty($resolved['immeuble_code']) ? 'missing' : '' ?>"><?= $h($resolved['immeuble_code'] ?? '— manquant —') ?></span>
                                    <span>Type code</span><span><?= $h($resolved['type_code'] ?? '—') ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="proposed-name">
                            <strong>Nom proposé :</strong>
                            <code><?= $h($c['naming_proposed'] ?? '(non calculé)') ?></code>
                        </div>

                        <div class="actions-grid">
                            <?php if (empty($resolved['banque_code'])): ?>
                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="create_glossary_code">
                                <input type="hidden" name="carte_id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="category" value="banque">
                                <input type="hidden" name="resolved_key" value="banque_code">
                                <div class="action-title">🏦 Créer code banque</div>
                                <input type="text" name="label" placeholder="Libellé (ex: Crédit Mutuel)" value="<?= $h($extracted['banque_text'] ?? '') ?>" required>
                                <input type="text" name="code" placeholder="Code (auto si vide)" maxlength="20">
                                <button type="submit" class="btn-primary btn-sm">Créer</button>
                            </form>
                            <?php endif; ?>

                            <?php if (empty($resolved['immeuble_code'])): ?>
                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="link_immeuble">
                                <input type="hidden" name="carte_id" value="<?= (int)$c['id'] ?>">
                                <div class="action-title">🏢 Lier à un immeuble existant</div>
                                <p class="muted small">Vision a vu : <em><?= $h($extracted['immeuble_text'] ?? '(rien)') ?></em></p>
                                <select name="immeuble_id" required>
                                    <option value="">— sélectionner —</option>
                                    <?php
                                    // Charge la liste des immeubles (limité à 1000 pour perf)
                                    $stImm = $pdo->query("
                                        SELECT i.id, i.nom_immeuble, i.adresse_1, i.ville,
                                               a.nom_agence
                                        FROM immeubles i
                                        LEFT JOIN agences a ON a.id = i.id_agence
                                        ORDER BY i.nom_immeuble ASC
                                        LIMIT 1000
                                    ");
                                    while ($imm = $stImm->fetch(PDO::FETCH_ASSOC)):
                                        $optLabel = trim((string)($imm['nom_immeuble'] ?? '')) ?: ('Immeuble #' . (int)$imm['id']);
                                        if (!empty($imm['ville'])) $optLabel .= ' — ' . $imm['ville'];
                                        if (!empty($imm['nom_agence'])) $optLabel .= ' (' . $imm['nom_agence'] . ')';
                                    ?>
                                        <option value="<?= (int)$imm['id'] ?>"><?= $h($optLabel) ?></option>
                                    <?php endwhile; ?>
                                </select>
                                <button type="submit" class="btn-primary btn-sm">Lier + déduire agence</button>
                            </form>
                            <?php endif; ?>

                            <?php if (empty($resolved['compte4'])): ?>
                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="set_compte4">
                                <input type="hidden" name="carte_id" value="<?= (int)$c['id'] ?>">
                                <div class="action-title">💳 4 derniers chiffres du compte</div>
                                <input type="text" name="compte4" placeholder="ex: 0042" maxlength="20" pattern="[0-9 ]+" required>
                                <button type="submit" class="btn-primary btn-sm">Enregistrer</button>
                            </form>
                            <?php endif; ?>

                            <?php if (empty($resolved['periode_mm_yyyy'])): ?>
                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="set_periode">
                                <input type="hidden" name="carte_id" value="<?= (int)$c['id'] ?>">
                                <div class="action-title">📅 Période (MM-AAAA)</div>
                                <input type="text" name="periode" placeholder="ex: 03-2026 ou Mars 2026" value="<?= $h($extracted['periode_text'] ?? '') ?>" required>
                                <button type="submit" class="btn-primary btn-sm">Enregistrer</button>
                            </form>
                            <?php endif; ?>

                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="reanalyze">
                                <input type="hidden" name="carte_id" value="<?= (int)$c['id'] ?>">
                                <div class="action-title">🔄 Ré-analyser</div>
                                <p class="muted small">Relance Vision (Sonnet) sur ce doc.</p>
                                <button type="submit" class="btn-outline btn-sm">Lancer</button>
                            </form>

                            <form method="post" class="action-form" onsubmit="return confirm('Passer cette carte en skipped (garde le nom legacy) ?')">
                                <input type="hidden" name="action" value="skip_naming">
                                <input type="hidden" name="carte_id" value="<?= (int)$c['id'] ?>">
                                <div class="action-title">⏭️ Ignorer</div>
                                <p class="muted small">Garde le nom legacy, sort de la file.</p>
                                <button type="submit" class="btn-outline btn-sm">Skip</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
<?php
$layout_content = ob_get_clean();

$layout_extra_css = '<style>
.naming-review-page{padding:24px}
.page-head-local{margin-bottom:16px}
.page-head-local h1{margin:0;color:#243B5C;font-size:22px}
.page-head-local .muted{color:#667085;font-size:13px;margin-top:4px}
.content-wrap{display:flex;flex-direction:column;gap:16px}
.muted{color:#667085}
.small{font-size:12px}
.alert{padding:12px 14px;border-radius:10px}
.alert-success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}
.alert-error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}

.kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}
.kpi-card{background:#fff;border-radius:12px;padding:14px;box-shadow:0 4px 12px rgba(16,24,40,.06);text-align:center}
.kpi-val{font-size:22px;font-weight:700;color:#243B5C}
.kpi-lbl{font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.05em;margin-top:4px}

.filters{display:flex;gap:12px;align-items:center;background:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 2px 8px rgba(16,24,40,.04);flex-wrap:wrap}
.filters > form{display:flex;gap:12px;align-items:center}
.filters label{font-size:12px;color:#475467}
.filters select{padding:6px 10px;border:1px solid #d0d5dd;border-radius:8px;margin-left:6px}
.batch-form{margin-left:auto;border-left:1px solid #eaecf0;padding-left:16px}
.batch-info{font-size:13px;color:#243B5C}
.batch-info strong{color:#D4A047}

.empty{padding:60px;text-align:center;color:#667085;background:#fff;border-radius:12px}

.carte-card{background:#fff;border-radius:14px;box-shadow:0 8px 24px rgba(16,24,40,.08);padding:18px;display:flex;flex-direction:column;gap:14px}
.carte-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.carte-title{font-weight:700;color:#101828;font-size:15px}
.carte-sub{color:#475467;font-size:13px;margin-top:2px}

.badges{display:flex;gap:6px;flex-wrap:wrap}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.badge-status-needs_review{background:#fef0c7;color:#b54708}
.badge-status-ready{background:#d1fadf;color:#039855}
.badge-status-pending{background:#eaecf0;color:#475467}
.badge-status-extracted{background:#dbeafe;color:#1d4ed8}
.badge-status-applied{background:#d1fadf;color:#067647}
.badge-status-skipped{background:#f2f4f7;color:#667085}
.badge-reason{background:#fee4e2;color:#b42318}

.grid-2col{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.block-title{font-weight:600;color:#243B5C;font-size:13px;margin-bottom:8px;border-bottom:1px solid #eaecf0;padding-bottom:4px}
.kv{display:grid;grid-template-columns:140px 1fr;gap:4px 10px;font-size:12px}
.kv span:nth-child(odd){color:#667085;text-transform:uppercase;letter-spacing:.03em;font-size:10px}
.kv span:nth-child(even){color:#101828;font-family:monospace}
.kv .missing{color:#b42318;font-style:italic}

.proposed-name{background:#f9fafb;padding:10px 14px;border-radius:10px;border-left:3px solid #D4A047;font-size:13px}
.proposed-name code{font-family:"Courier New",monospace;color:#243B5C;font-weight:600;margin-left:8px}

.actions-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.action-form{background:#f9fafb;padding:10px 12px;border-radius:10px;display:flex;flex-direction:column;gap:6px}
.action-title{font-weight:600;font-size:12px;color:#243B5C}
.action-form input[type=text]{padding:6px 8px;border:1px solid #d0d5dd;border-radius:6px;font-size:12px}
.btn-sm{font-size:12px;padding:6px 12px;border-radius:6px;cursor:pointer;border:none}
.btn-primary{background:#243B5C;color:#fff}
.btn-outline{background:#fff;border:1px solid #d0d5dd;color:#243B5C}
</style>';

require_once __DIR__ . '/../inc/layout_maboximmo.php';
