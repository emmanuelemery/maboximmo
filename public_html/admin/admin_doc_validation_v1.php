<?php
/**
 * admin/admin_doc_validation_v1.php
 *
 * Sprint 3A V0 — Station de validation documentaire.
 *
 * Vue split : PDF à gauche, formulaire de validation à droite.
 *
 * État : V0 squelette testable. AUCUNE création de table.
 * S'appuie sur l'existant :
 *   - fluxbox_documents (source : flux brut + OCR + hash)
 *   - fluxbox_cartes    (file d'attente / statut validation)
 *   - ia_extract_cache  (extraction IA cachée si dispo via hash_sha256)
 *
 * Accès super admin uniquement (role=1).
 *
 * Cohérent avec [[project_fluxbox_module]] §3 et la doctrine Sprint 3 :
 * additive only, lecture seule pour l'aperçu, écriture simple via fluxbox_cartes.
 *
 * Non couvert en V0 (jalons V1+) :
 *   - autosave temps réel
 *   - matching engine (sera 3B)
 *   - classement GED N1→N6 (sera 3C)
 *   - logs d'extraction structurés (à valider : utiliser fluxbox_actions_ia ?)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = $GLOBALS['pdo'];

// ─── Helpers ─────────────────────────────────────────────────────────
function dv_table_exists(PDO $pdo, string $name): bool {
    // MariaDB 11+ refuse `SHOW TABLES LIKE ?` en prepared statement quand
    // EMULATE_PREPARES=false. Passer par information_schema qui supporte les params.
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) { return false; }
}

function dv_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$hasFluxbox = dv_table_exists($pdo, 'fluxbox_documents');
$hasCartes  = dv_table_exists($pdo, 'fluxbox_cartes');
$hasCache   = dv_table_exists($pdo, 'ia_extract_cache');

// ─── Action POST : valider une carte (V0 minimal) ────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_v0') {
    $cardId = (int)($_POST['card_id'] ?? 0);
    $payload = [
        'type_doc'    => trim((string)($_POST['type_doc'] ?? '')),
        'date_doc'    => trim((string)($_POST['date_doc'] ?? '')),
        'montant'     => trim((string)($_POST['montant'] ?? '')),
        'tiers'       => trim((string)($_POST['tiers'] ?? '')),
        'reference'   => trim((string)($_POST['reference'] ?? '')),
        'commentaire' => trim((string)($_POST['commentaire'] ?? '')),
    ];

    if ($cardId > 0 && $hasCartes) {
        try {
            $st = $pdo->prepare("
                UPDATE fluxbox_cartes
                SET statut = 'validated',
                    validated_by = :uid,
                    validated_at = NOW(),
                    proposition_json = COALESCE(proposition_json, JSON_OBJECT()),
                    proposition_json = JSON_MERGE_PATCH(proposition_json, :patch)
                WHERE id = :id
            ");
            $st->execute([
                ':uid'   => (int)($_SESSION['id_user'] ?? 0),
                ':patch' => json_encode(['validation_v0' => $payload], JSON_UNESCAPED_UNICODE),
                ':id'    => $cardId,
            ]);
            $flash = ['ok' => true, 'msg' => "Carte #$cardId marquée validée (V0)."];
        } catch (Throwable $e) {
            $flash = ['ok' => false, 'msg' => 'Erreur validation : ' . $e->getMessage()];
        }
    } else {
        $flash = ['ok' => false, 'msg' => 'card_id manquant ou table fluxbox_cartes absente.'];
    }
}

// ─── Sélection du document courant ───────────────────────────────────
$docId  = (int)($_GET['doc_id']  ?? 0);
$cardId = (int)($_GET['card_id'] ?? 0);

$doc  = null;
$card = null;
$cacheRow = null;

if ($hasFluxbox && $docId > 0) {
    $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($hasCartes && $cardId > 0) {
    $st = $pdo->prepare("SELECT * FROM fluxbox_cartes WHERE id = ?");
    $st->execute([$cardId]);
    $card = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($card && !$doc && !empty($card['document_id'])) {
        $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
        $st->execute([(int)$card['document_id']]);
        $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Cache IA si dispo (extraction Claude par hash)
if ($hasCache && $doc && !empty($doc['hash_sha256'])) {
    try {
        $st = $pdo->prepare("SELECT model, response_json, confidence, last_at
                              FROM ia_extract_cache
                              WHERE hash_sha256 = ?
                              ORDER BY last_at DESC LIMIT 1");
        $st->execute([$doc['hash_sha256']]);
        $cacheRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}

// Pré-remplissage formulaire depuis cache IA si dispo
$prefill = ['type_doc'=>'','date_doc'=>'','montant'=>'','tiers'=>'','reference'=>'','commentaire'=>''];
if ($cacheRow && !empty($cacheRow['response_json'])) {
    $resp = json_decode((string)$cacheRow['response_json'], true);
    if (is_array($resp)) {
        $prefill['type_doc']  = (string)($resp['type_doc']  ?? $resp['type'] ?? '');
        $prefill['date_doc']  = (string)($resp['date_doc']  ?? $resp['date'] ?? '');
        $prefill['montant']   = (string)($resp['montant']   ?? $resp['amount'] ?? '');
        $prefill['tiers']     = (string)($resp['tiers']     ?? $resp['fournisseur'] ?? $resp['emetteur'] ?? '');
        $prefill['reference'] = (string)($resp['reference'] ?? $resp['ref'] ?? '');
    }
}

// ─── Liste des cartes en attente (sidebar) ──────────────────────────
$pendingCards = [];
if ($hasCartes) {
    try {
        $pendingCards = $pdo->query("
            SELECT c.id, c.titre, c.sous_titre, c.priorite, c.statut,
                   c.confiance_ia, c.document_id, c.created_at,
                   d.fichier_nom, d.hash_sha256, d.ocr_status, d.mime_type
            FROM fluxbox_cartes c
            LEFT JOIN fluxbox_documents d ON d.id = c.document_id
            WHERE c.statut IN ('pending','in_progress')
            ORDER BY FIELD(c.priorite,'urgent','important','normal','faible'),
                     c.created_at DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $pendingCards = [];
    }
}

// URL du PDF (best effort — fluxbox_documents.fichier_chemin)
$pdfUrl = null;
if ($doc && !empty($doc['fichier_chemin'])) {
    // Si chemin commence par /uploads ou /public_html, on essaie de le servir tel quel
    $path = (string)$doc['fichier_chemin'];
    if (str_starts_with($path, '/')) {
        $pdfUrl = $path;
    } else {
        // Convertir chemin disque → URL en best-effort
        // (Hostinger / XAMPP : pas de mapping automatique en V0)
        $pdfUrl = '/uploads/' . basename($path);
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Station validation documentaire — Sprint 3A V0</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-navy: #243B5C;
            --mbi-or:   #D4A047;
            --bg:       #0f172a;
            --panel:    #1e293b;
            --line:     #334155;
            --text:     #f1f5f9;
            --muted:    #94a3b8;
            --ok:       #84a98c;
            --warn:     #fde68a;
            --ko:       #f87171;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0;
            font-family: "DM Mono", "JetBrains Mono", monospace;
            background: var(--bg); color: var(--text); font-size: 12.5px;
        }
        header {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 18px; background: #11203b;
            border-bottom: 2px solid var(--mbi-or);
        }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .v0-badge {
            background: #7c3aed; color: #fff; padding: 2px 8px;
            border-radius: 4px; font-size: 10px; font-weight: 700;
        }
        header nav { margin-left: auto; display: flex; gap: 10px; }
        header nav a {
            color: var(--muted); text-decoration: none; font-size: 11px;
            border: 1px solid var(--line); padding: 4px 10px; border-radius: 4px;
        }
        header nav a:hover { color: var(--text); border-color: var(--mbi-or); }

        .layout {
            display: grid;
            grid-template-columns: 240px 1fr 1fr;
            gap: 0;
            height: calc(100vh - 47px);
        }
        .col {
            overflow-y: auto;
            border-right: 1px solid var(--line);
        }
        .col:last-child { border-right: none; }

        /* Sidebar : liste des cartes à valider */
        .sidebar { background: #0a1424; padding: 8px; }
        .sidebar h2 {
            font-size: 11px; color: var(--mbi-or); margin: 4px 0 8px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .card-item {
            display: block; padding: 8px 10px; margin-bottom: 6px;
            background: var(--panel); border-radius: 4px;
            border-left: 3px solid var(--line);
            color: var(--text); text-decoration: none; font-size: 11px;
        }
        .card-item:hover { background: #2a3548; }
        .card-item.active { border-left-color: var(--mbi-or); background: #2a3548; }
        .card-item .title { font-weight: 700; margin-bottom: 2px; }
        .card-item .sub { color: var(--muted); font-size: 10px; }
        .card-item .meta {
            display: flex; gap: 6px; margin-top: 4px;
            font-size: 9.5px; color: var(--muted);
        }
        .card-item .meta span { background: #334155; padding: 1px 6px; border-radius: 2px; }
        .card-item .meta .prio-urgent { background: #7f1d1d; color: #fff; }
        .card-item .meta .prio-important { background: #7c2d12; color: #fff; }

        /* Colonne PDF */
        .pdf-pane { background: #1a2438; display: flex; flex-direction: column; }
        .pdf-header {
            padding: 8px 14px; border-bottom: 1px solid var(--line);
            background: var(--panel); font-size: 11px; color: var(--muted);
        }
        .pdf-header b { color: var(--text); }
        .pdf-viewer { flex: 1; position: relative; background: #0a1424; }
        .pdf-viewer iframe {
            position: absolute; inset: 0; width: 100%; height: 100%;
            border: none;
        }
        .pdf-empty {
            display: flex; align-items: center; justify-content: center;
            height: 100%; color: var(--muted); padding: 24px; text-align: center;
        }

        /* Colonne formulaire */
        .form-pane { background: var(--panel); padding: 14px 18px; }
        .form-pane h2 {
            font-size: 12px; margin: 0 0 12px; color: var(--mbi-or);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--line); padding-bottom: 6px;
        }
        .flash {
            padding: 8px 12px; border-radius: 4px; margin-bottom: 12px;
            font-size: 11px;
        }
        .flash.ok { background: #14532d; color: #d1fae5; border-left: 3px solid var(--ok); }
        .flash.ko { background: #7f1d1d; color: #fee2e2; border-left: 3px solid var(--ko); }

        .field { margin-bottom: 10px; }
        .field label {
            display: block; font-size: 10.5px; color: var(--muted);
            margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .field input, .field textarea, .field select {
            width: 100%; padding: 6px 10px;
            background: #0f172a; border: 1px solid var(--line);
            color: var(--text); border-radius: 3px; font-family: inherit; font-size: 12px;
        }
        .field input:focus, .field textarea:focus, .field select:focus {
            border-color: var(--mbi-or); outline: none;
        }
        .field textarea { resize: vertical; min-height: 60px; }

        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        .actions {
            display: flex; gap: 10px; margin-top: 14px;
            padding-top: 12px; border-top: 1px solid var(--line);
        }
        .btn {
            padding: 8px 14px; border: none; border-radius: 4px;
            font-family: inherit; font-size: 11.5px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary { background: var(--mbi-or); color: #1a1a1a; font-weight: 700; }
        .btn-primary:hover { background: #b8862f; }
        .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .btn-ghost:hover { color: var(--text); border-color: var(--mbi-or); }

        .meta-block {
            background: #0f172a; padding: 8px 12px; border-radius: 4px;
            border-left: 3px solid var(--mbi-or); margin-bottom: 14px;
            font-size: 10.5px; color: var(--muted); line-height: 1.6;
        }
        .meta-block b { color: var(--text); }
        .meta-block .row { display: flex; gap: 8px; margin: 2px 0; }
        .meta-block .row .k { color: var(--mbi-or); min-width: 90px; }

        .empty-state {
            text-align: center; padding: 40px 20px; color: var(--muted);
        }
        .empty-state h3 { color: var(--warn); }

        .v0-warn {
            background: #422006; color: #fde68a; padding: 6px 10px;
            border-radius: 3px; font-size: 10px; margin-top: 12px;
            border-left: 3px solid var(--warn);
        }
    </style>
</head>
<body>

<header>
    <h1>🎯 Station validation documentaire</h1>
    <span class="v0-badge">SPRINT 3A · V0</span>
    <nav>
        <a href="admin_audit_sprint3_tables.php">📊 Audit BDD</a>
        <a href="admin_entity_matcher_ab.php">🔬 A/B matcher</a>
        <a href="admin_doc_match_assistant_v1.php">🔗 Matching 3B</a>
        <a href="admin_doc_classify_v1.php">📁 Classement 3C</a>
    </nav>
</header>

<div class="layout">

    <!-- ─── SIDEBAR : cartes en attente ─── -->
    <aside class="col sidebar">
        <h2>📥 À valider <?= count($pendingCards) ? '(' . count($pendingCards) . ')' : '' ?></h2>

        <?php if (!$hasCartes): ?>
            <div style="color: var(--ko); font-size: 11px;">⚠️ Table <code>fluxbox_cartes</code> absente.</div>
        <?php elseif (!count($pendingCards)): ?>
            <div style="color: var(--muted); font-size: 11px;">Aucune carte en attente.</div>
        <?php else: ?>
            <?php foreach ($pendingCards as $c):
                $isActive = ($cardId > 0 && (int)$c['id'] === $cardId);
                $linkDocId = (int)($c['document_id'] ?? 0);
            ?>
                <a class="card-item <?= $isActive ? 'active' : '' ?>"
                   href="?card_id=<?= (int)$c['id'] ?>&doc_id=<?= $linkDocId ?>">
                    <div class="title"><?= dv_html((string)($c['titre'] ?: '(sans titre)')) ?></div>
                    <?php if (!empty($c['sous_titre'])): ?>
                        <div class="sub"><?= dv_html((string)$c['sous_titre']) ?></div>
                    <?php endif; ?>
                    <div class="meta">
                        <span class="prio-<?= dv_html((string)$c['priorite']) ?>"><?= dv_html((string)$c['priorite']) ?></span>
                        <?php if ($c['confiance_ia'] !== null): ?>
                            <span>IA <?= (int)$c['confiance_ia'] ?>%</span>
                        <?php endif; ?>
                        <?php if (!empty($c['ocr_status'])): ?>
                            <span>OCR <?= dv_html((string)$c['ocr_status']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="v0-warn">
            <b>V0 :</b> liste limitée aux cartes <code>pending/in_progress</code>.
            Filtres avancés en V1.
        </div>
    </aside>

    <!-- ─── COLONNE PDF ─── -->
    <section class="col pdf-pane">
        <div class="pdf-header">
            <?php if ($doc): ?>
                <b><?= dv_html((string)($doc['fichier_nom'] ?? '?')) ?></b>
                · <?= dv_html((string)($doc['mime_type'] ?? '?')) ?>
                · <?= number_format((int)($doc['taille_octets'] ?? 0) / 1024, 0) ?> Ko
                · hash <code><?= dv_html(substr((string)($doc['hash_sha256'] ?? ''), 0, 12)) ?>…</code>
            <?php else: ?>
                Aucun document sélectionné.
            <?php endif; ?>
        </div>
        <div class="pdf-viewer">
            <?php if ($doc && $pdfUrl): ?>
                <iframe src="<?= dv_html($pdfUrl) ?>#zoom=page-fit" title="PDF"></iframe>
            <?php elseif ($doc): ?>
                <div class="pdf-empty">
                    <div>
                        <p>📄 Chemin disque trouvé mais URL HTTP non résolue en V0.</p>
                        <p style="font-size: 10px; color: var(--warn);">
                            <code><?= dv_html((string)$doc['fichier_chemin']) ?></code>
                        </p>
                        <p style="font-size: 10px;">V1 : mapping chemin disque → URL via <code>uploads_dispatcher.php</code>.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="pdf-empty">
                    <div>
                        <p>👈 Sélectionne une carte dans la sidebar pour afficher le PDF.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ─── COLONNE FORMULAIRE ─── -->
    <section class="col form-pane">

        <?php if ($flash): ?>
            <div class="flash <?= $flash['ok'] ? 'ok' : 'ko' ?>">
                <?= dv_html((string)$flash['msg']) ?>
            </div>
        <?php endif; ?>

        <h2>📝 Formulaire validation</h2>

        <?php if (!$doc && !$card): ?>
            <div class="empty-state">
                <h3>Aucun document sélectionné</h3>
                <p>Choisis une carte en attente dans la sidebar de gauche.</p>
            </div>
        <?php else: ?>

            <div class="meta-block">
                <?php if ($card): ?>
                    <div class="row"><span class="k">Carte ID</span> <b>#<?= (int)$card['id'] ?></b></div>
                    <div class="row"><span class="k">Priorité</span> <?= dv_html((string)$card['priorite']) ?></div>
                    <div class="row"><span class="k">Statut</span> <?= dv_html((string)$card['statut']) ?></div>
                    <?php if ($card['confiance_ia'] !== null): ?>
                        <div class="row"><span class="k">Confiance IA</span> <?= (int)$card['confiance_ia'] ?>%</div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($cacheRow): ?>
                    <div class="row">
                        <span class="k">Cache IA</span>
                        ✓ <code><?= dv_html((string)$cacheRow['model']) ?></code>
                        · <?= dv_html((string)$cacheRow['last_at']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="validate_v0">
                <input type="hidden" name="card_id" value="<?= (int)($card['id'] ?? 0) ?>">
                <input type="hidden" name="doc_id" value="<?= (int)($doc['id'] ?? 0) ?>">

                <div class="row2">
                    <div class="field">
                        <label>Type document</label>
                        <select name="type_doc">
                            <?php
                            $types = ['', 'facture', 'devis', 'bail', 'mandat', 'releve_bancaire',
                                      'acte_propriete', 'pv_ag', 'avis_echeance', 'quittance', 'autre'];
                            foreach ($types as $t):
                                $sel = ($t === $prefill['type_doc']) ? 'selected' : '';
                            ?>
                                <option value="<?= dv_html($t) ?>" <?= $sel ?>>
                                    <?= dv_html($t === '' ? '— choisir —' : $t) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Date document</label>
                        <input type="date" name="date_doc" value="<?= dv_html($prefill['date_doc']) ?>">
                    </div>
                </div>

                <div class="row2">
                    <div class="field">
                        <label>Tiers / émetteur</label>
                        <input type="text" name="tiers" value="<?= dv_html($prefill['tiers']) ?>"
                               placeholder="Ex. EDF, Otis SA, Notaire...">
                    </div>
                    <div class="field">
                        <label>Montant (€)</label>
                        <input type="text" name="montant" value="<?= dv_html($prefill['montant']) ?>"
                               placeholder="Ex. 1240.50">
                    </div>
                </div>

                <div class="field">
                    <label>Référence interne</label>
                    <input type="text" name="reference" value="<?= dv_html($prefill['reference']) ?>"
                           placeholder="N° facture, contrat, dossier...">
                </div>

                <div class="field">
                    <label>Commentaire validation</label>
                    <textarea name="commentaire" placeholder="Optionnel — note libre"></textarea>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">✓ Valider (V0)</button>
                    <a class="btn btn-ghost" href="?">↺ Recharger la liste</a>
                </div>

                <div class="v0-warn">
                    <b>V0 :</b> validation marque la carte <code>validated</code> + stocke les champs
                    dans <code>fluxbox_cartes.proposition_json.validation_v0</code>.
                    Pas encore de classement GED ni de matching auto (3B/3C).
                </div>
            </form>

        <?php endif; ?>

    </section>

</div>

</body>
</html>
