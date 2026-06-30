<?php
/**
 * admin/admin_doc_match_assistant_v1.php
 *
 * Sprint 3B V0 — Assistant de matching documentaire 4 étapes.
 *
 * Vue stepper :
 *   1. Propriétaire / Tiers   → em_match_tiers()
 *   2. Bien                   → em_match_bien()
 *   3. Immeuble               → em_match_immeuble()
 *   4. Confirmation (récap)
 *
 * État : V0 squelette testable. AUCUNE création de table.
 * AUCUNE écriture BDD en V0 (l'écriture sera ajoutée en V1 avec validation user).
 *
 * Accès super admin uniquement (role=1).
 *
 * S'appuie strictement sur l'engine [[entity_matcher]] (Sprint 1 livré).
 * Le formulaire de saisie peut être pré-rempli via ?card_id=X qui lit
 * fluxbox_cartes.proposition_json.validation_v0 (lien avec Sprint 3A).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/entity_matcher.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = $GLOBALS['pdo'];

function ma_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Étape courante ──────────────────────────────────────────────────
$step = max(1, min(4, (int)($_GET['step'] ?? 1)));
$cardId = (int)($_GET['card_id'] ?? 0);

// ─── Pré-remplissage depuis carte (si dispo) ─────────────────────────
$prefill = [];
if ($cardId > 0) {
    try {
        $st = $pdo->prepare("SELECT proposition_json FROM fluxbox_cartes WHERE id = ?");
        $st->execute([$cardId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['proposition_json'])) {
            $prop = json_decode((string)$row['proposition_json'], true);
            if (is_array($prop) && isset($prop['validation_v0'])) {
                $prefill = $prop['validation_v0'];
            }
        }
    } catch (Throwable) {}
}

// ─── Données saisies ────────────────────────────────────────────────
// On garde dans la session pour ne pas tout reposter à chaque step
if (!isset($_SESSION['ma_v1_state'])) {
    $_SESSION['ma_v1_state'] = [];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $k => $v) {
        if (str_starts_with($k, 's_')) {
            $_SESSION['ma_v1_state'][$k] = is_string($v) ? trim($v) : $v;
        }
    }
    if (($_POST['action'] ?? '') === 'reset') {
        $_SESSION['ma_v1_state'] = [];
        header("Location: ?step=1" . ($cardId ? "&card_id=$cardId" : ''));
        exit;
    }
}

$state = $_SESSION['ma_v1_state'];

// Initialisation depuis prefill si rien en session
if (empty($state) && !empty($prefill)) {
    $state['s_tiers_nom']    = (string)($prefill['tiers'] ?? '');
    $state['s_doc_type']     = (string)($prefill['type_doc'] ?? '');
    $state['s_doc_date']     = (string)($prefill['date_doc'] ?? '');
    $state['s_doc_montant']  = (string)($prefill['montant'] ?? '');
    $_SESSION['ma_v1_state'] = $state;
}

// ─── Exécution des matchers selon l'étape ────────────────────────────
$results = ['tiers' => null, 'bien' => null, 'immeuble' => null];

if ($step >= 1 && !empty($state['s_tiers_nom'])) {
    try {
        $results['tiers'] = em_match_tiers($pdo, [
            'raison_sociale' => $state['s_tiers_nom'],
            'nom'            => $state['s_tiers_nom'],
            'email'          => $state['s_tiers_email'] ?? null,
            'telephone'      => $state['s_tiers_telephone'] ?? null,
        ]);
    } catch (Throwable $e) {
        $results['tiers'] = ['ERREUR' => $e->getMessage()];
    }
}

if ($step >= 2 && (!empty($state['s_bien_adresse']) || !empty($state['s_bien_ref']))) {
    try {
        $results['bien'] = em_match_bien($pdo, [
            'adresse'        => $state['s_bien_adresse'] ?? null,
            'code_postal'    => $state['s_bien_cp'] ?? null,
            'ville'          => $state['s_bien_ville'] ?? null,
            'etage'          => $state['s_bien_etage'] ?? null,
            'numero_lot'     => $state['s_bien_lot'] ?? null,
            'ref_externe'    => $state['s_bien_ref'] ?? null,
            'mandat'         => $state['s_bien_mandat'] ?? null,
        ]);
    } catch (Throwable $e) {
        $results['bien'] = ['ERREUR' => $e->getMessage()];
    }
}

if ($step >= 3 && !empty($state['s_imm_adresse'])) {
    try {
        $results['immeuble'] = em_match_immeuble($pdo, [
            'adresse'      => $state['s_imm_adresse'] ?? null,
            'code_postal'  => $state['s_imm_cp'] ?? null,
            'ville'        => $state['s_imm_ville'] ?? null,
            'place_id'     => $state['s_imm_place_id'] ?? null,
            'latitude'     => $state['s_imm_lat'] ?? null,
            'longitude'    => $state['s_imm_lng'] ?? null,
        ]);
    } catch (Throwable $e) {
        $results['immeuble'] = ['ERREUR' => $e->getMessage()];
    }
}

// Confiance globale = min des 3 (ou des étapes renseignées)
$globalConf = null;
$confs = [];
foreach (['tiers','bien','immeuble'] as $k) {
    if (is_array($results[$k]) && isset($results[$k]['confidence'])) {
        $confs[] = (int)$results[$k]['confidence'];
    }
}
if (count($confs)) $globalConf = min($confs);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Assistant matching documentaire — Sprint 3B V0</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171;
            --proprio: #0e7490; --bien: #84a98c; --immeuble: #7c9885;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0;
            font-family: "DM Mono", "JetBrains Mono", monospace;
            background: var(--bg); color: var(--text); font-size: 12.5px;
            min-height: 100vh;
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

        .container { max-width: 1200px; margin: 0 auto; padding: 18px; }

        /* Stepper */
        .stepper {
            display: flex; gap: 0; margin-bottom: 20px;
            background: var(--panel); border-radius: 6px; padding: 4px;
        }
        .stepper a {
            flex: 1; text-align: center; padding: 12px 8px;
            color: var(--muted); text-decoration: none; font-size: 11.5px;
            border-radius: 4px; position: relative;
        }
        .stepper a.active { background: var(--mbi-or); color: #1a1a1a; font-weight: 700; }
        .stepper a.done { color: var(--ok); }
        .stepper a .num {
            display: inline-block; width: 20px; height: 20px; line-height: 20px;
            border-radius: 50%; background: #0f172a; color: var(--text);
            margin-right: 6px; font-size: 10px;
        }
        .stepper a.active .num { background: #1a1a1a; color: var(--mbi-or); }
        .stepper a.done .num { background: var(--ok); color: #1a1a1a; }

        .panel {
            background: var(--panel); border-radius: 6px; padding: 18px;
            margin-bottom: 16px;
        }
        .panel h2 {
            font-size: 13px; margin: 0 0 12px;
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--line); padding-bottom: 6px;
        }
        .panel h2.proprio { color: var(--proprio); border-bottom-color: var(--proprio); }
        .panel h2.bien { color: var(--bien); border-bottom-color: var(--bien); }
        .panel h2.immeuble { color: var(--immeuble); border-bottom-color: var(--immeuble); }
        .panel h2.confirm { color: var(--mbi-or); border-bottom-color: var(--mbi-or); }

        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

        .field { margin-bottom: 10px; }
        .field label {
            display: block; font-size: 10.5px; color: var(--muted);
            margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .field input, .field select {
            width: 100%; padding: 7px 10px;
            background: #0f172a; border: 1px solid var(--line);
            color: var(--text); border-radius: 3px;
            font-family: inherit; font-size: 12px;
        }
        .field input:focus, .field select:focus { border-color: var(--mbi-or); outline: none; }

        .actions {
            display: flex; gap: 10px; justify-content: space-between;
            margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line);
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
        .btn-danger { background: #7f1d1d; color: #fff; }

        /* Résultats matcher */
        .results {
            margin-top: 14px; padding: 12px; background: #0f172a; border-radius: 4px;
            border-left: 3px solid var(--mbi-or);
        }
        .results .verdict {
            font-size: 12.5px; margin-bottom: 8px; font-weight: 700;
        }
        .verdict.found { color: var(--ok); }
        .verdict.create { color: var(--warn); }
        .verdict.err { color: var(--ko); }

        .match-item {
            padding: 8px 10px; background: var(--panel); margin: 4px 0;
            border-radius: 3px; border-left: 2px solid var(--line);
            font-size: 11px;
        }
        .match-item.best { border-left-color: var(--mbi-or); }
        .match-item .score {
            display: inline-block; background: var(--mbi-or); color: #1a1a1a;
            padding: 1px 7px; border-radius: 3px; font-weight: 700; font-size: 10px;
        }
        .match-item .reasons {
            color: var(--muted); font-size: 10px; margin-top: 3px; font-style: italic;
        }

        .confidence-bar {
            height: 8px; background: #0f172a; border-radius: 4px;
            overflow: hidden; margin: 6px 0;
        }
        .confidence-bar div {
            height: 100%; background: var(--ok); transition: width 0.3s;
        }
        .confidence-bar.medium div { background: var(--warn); }
        .confidence-bar.low div { background: var(--ko); }

        .v0-warn {
            background: #422006; color: #fde68a; padding: 8px 12px;
            border-radius: 3px; font-size: 10.5px; margin-top: 14px;
            border-left: 3px solid var(--warn);
        }

        .recap-block {
            background: #0f172a; padding: 10px 14px; border-radius: 4px;
            margin: 10px 0; border-left: 3px solid var(--mbi-or);
        }
        .recap-block h3 { font-size: 11.5px; margin: 0 0 6px; color: var(--mbi-or); }
        .recap-block code { color: var(--text); font-size: 11px; }

        pre {
            background: #0f172a; padding: 10px; border-radius: 3px;
            font-size: 10.5px; overflow-x: auto; line-height: 1.5;
            border: 1px solid var(--line); max-height: 200px;
        }
    </style>
</head>
<body>

<header>
    <h1>🔗 Assistant matching documentaire</h1>
    <span class="v0-badge">SPRINT 3B · V0</span>
    <nav>
        <a href="admin_doc_validation_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">← Validation 3A</a>
        <a href="admin_doc_classify_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">Classement 3C →</a>
        <a href="admin_entity_matcher_ab.php">🔬 A/B matcher</a>
    </nav>
</header>

<div class="container">

    <!-- Stepper -->
    <div class="stepper">
        <?php
        $stepLabels = [1 => 'Propriétaire / Tiers', 2 => 'Bien', 3 => 'Immeuble', 4 => 'Confirmation'];
        foreach ($stepLabels as $i => $label):
            $cls = $i === $step ? 'active' : ($i < $step ? 'done' : '');
        ?>
            <a class="<?= $cls ?>" href="?step=<?= $i ?><?= $cardId ? '&card_id='.$cardId : '' ?>">
                <span class="num"><?= $i ?></span><?= ma_html($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($cardId > 0): ?>
        <div style="font-size: 11px; color: var(--muted); margin-bottom: 12px;">
            🔗 Pré-rempli depuis carte FluxBox <code>#<?= (int)$cardId ?></code>.
        </div>
    <?php endif; ?>

    <form method="POST" action="?step=<?= $step ?><?= $cardId ? '&card_id='.$cardId : '' ?>">

    <?php if ($step === 1): /* ──────────────── ÉTAPE 1 : TIERS ──────────────── */ ?>

        <div class="panel">
            <h2 class="proprio">① Propriétaire / Tiers</h2>

            <div class="grid2">
                <div class="field">
                    <label>Nom / Raison sociale *</label>
                    <input type="text" name="s_tiers_nom"
                           value="<?= ma_html((string)($state['s_tiers_nom'] ?? '')) ?>"
                           placeholder="Ex. MR & MME SABY, SCI ELYSEE I, EDF...">
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="s_tiers_email"
                           value="<?= ma_html((string)($state['s_tiers_email'] ?? '')) ?>">
                </div>
            </div>
            <div class="grid2">
                <div class="field">
                    <label>Téléphone</label>
                    <input type="text" name="s_tiers_telephone"
                           value="<?= ma_html((string)($state['s_tiers_telephone'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>SIRET / SIREN</label>
                    <input type="text" name="s_tiers_siret"
                           value="<?= ma_html((string)($state['s_tiers_siret'] ?? '')) ?>">
                </div>
            </div>

            <?php if ($results['tiers']): ?>
                <div class="results">
                    <?php if (isset($results['tiers']['ERREUR'])): ?>
                        <div class="verdict err">❌ Erreur : <?= ma_html((string)$results['tiers']['ERREUR']) ?></div>
                    <?php else:
                        $r = $results['tiers'];
                        $found = !empty($r['found']);
                        $conf  = (int)($r['confidence'] ?? 0);
                        $verdictCls = $found ? ($conf >= 90 ? 'found' : 'create') : 'create';
                        $barCls = $conf >= 80 ? '' : ($conf >= 50 ? 'medium' : 'low');
                    ?>
                        <div class="verdict <?= $verdictCls ?>">
                            <?= $found ? '✅ '.count($r['matches']).' candidat(s) trouvé(s)' : '⚠️ Aucun match — création possible' ?>
                            · Confiance <?= $conf ?>%
                        </div>
                        <div class="confidence-bar <?= $barCls ?>">
                            <div style="width: <?= $conf ?>%"></div>
                        </div>
                        <?php foreach (array_slice($r['matches'] ?? [], 0, 5) as $i => $m): ?>
                            <div class="match-item <?= $i === 0 ? 'best' : '' ?>">
                                <span class="score"><?= (int)($m['score'] ?? 0) ?></span>
                                <b>#<?= (int)$m['id'] ?></b>
                                · <?= ma_html((string)($m['raison_sociale'] ?: ($m['nom'] ?? '').' '.($m['prenom'] ?? ''))) ?>
                                <?php if (!empty($m['reasons'])): ?>
                                    <div class="reasons"><?= ma_html(is_array($m['reasons']) ? implode(' · ', $m['reasons']) : (string)$m['reasons']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button type="submit" name="action" value="reset" class="btn btn-ghost"
                        onclick="return confirm('Réinitialiser tous les champs ?')">↺ Reset</button>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary">🔍 Matcher</button>
                    <a class="btn btn-primary" href="?step=2<?= $cardId ? '&card_id='.$cardId : '' ?>">Suivant →</a>
                </div>
            </div>
        </div>

    <?php elseif ($step === 2): /* ──────────────── ÉTAPE 2 : BIEN ──────────────── */ ?>

        <div class="panel">
            <h2 class="bien">② Bien</h2>

            <div class="grid2">
                <div class="field">
                    <label>N° mandat</label>
                    <input type="text" name="s_bien_mandat"
                           value="<?= ma_html((string)($state['s_bien_mandat'] ?? '')) ?>"
                           placeholder="Match prioritaire (score 100)">
                </div>
                <div class="field">
                    <label>Référence externe</label>
                    <input type="text" name="s_bien_ref"
                           value="<?= ma_html((string)($state['s_bien_ref'] ?? '')) ?>">
                </div>
            </div>

            <div class="field">
                <label>Adresse</label>
                <input type="text" name="s_bien_adresse"
                       value="<?= ma_html((string)($state['s_bien_adresse'] ?? '')) ?>">
            </div>
            <div class="grid3">
                <div class="field">
                    <label>Code postal</label>
                    <input type="text" name="s_bien_cp"
                           value="<?= ma_html((string)($state['s_bien_cp'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Ville</label>
                    <input type="text" name="s_bien_ville"
                           value="<?= ma_html((string)($state['s_bien_ville'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Étage / Lot</label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" name="s_bien_etage" placeholder="Étage"
                               value="<?= ma_html((string)($state['s_bien_etage'] ?? '')) ?>">
                        <input type="text" name="s_bien_lot" placeholder="Lot"
                               value="<?= ma_html((string)($state['s_bien_lot'] ?? '')) ?>">
                    </div>
                </div>
            </div>

            <?php if ($results['bien']): ?>
                <div class="results">
                    <?php if (isset($results['bien']['ERREUR'])): ?>
                        <div class="verdict err">❌ Erreur : <?= ma_html((string)$results['bien']['ERREUR']) ?></div>
                    <?php else:
                        $r = $results['bien'];
                        $found = !empty($r['found']);
                        $conf  = (int)($r['confidence'] ?? 0);
                        $verdictCls = $found ? ($conf >= 90 ? 'found' : 'create') : 'create';
                        $barCls = $conf >= 80 ? '' : ($conf >= 50 ? 'medium' : 'low');
                    ?>
                        <div class="verdict <?= $verdictCls ?>">
                            <?= $found ? '✅ '.count($r['matches']).' bien(s) candidat(s)' : '⚠️ Aucun match — création possible' ?>
                            · Confiance <?= $conf ?>%
                        </div>
                        <div class="confidence-bar <?= $barCls ?>">
                            <div style="width: <?= $conf ?>%"></div>
                        </div>
                        <?php foreach (array_slice($r['matches'] ?? [], 0, 5) as $i => $m): ?>
                            <div class="match-item <?= $i === 0 ? 'best' : '' ?>">
                                <span class="score"><?= (int)($m['score'] ?? 0) ?></span>
                                <b>#<?= (int)$m['id'] ?></b>
                                <?php if (!empty($m['mandat'])): ?> · mandat <?= ma_html((string)$m['mandat']) ?><?php endif; ?>
                                <?php if (!empty($m['adresse'])): ?> · <?= ma_html((string)$m['adresse']) ?><?php endif; ?>
                                <?php if (!empty($m['reasons'])): ?>
                                    <div class="reasons"><?= ma_html(is_array($m['reasons']) ? implode(' · ', $m['reasons']) : (string)$m['reasons']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <a class="btn btn-ghost" href="?step=1<?= $cardId ? '&card_id='.$cardId : '' ?>">← Précédent</a>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary">🔍 Matcher</button>
                    <a class="btn btn-primary" href="?step=3<?= $cardId ? '&card_id='.$cardId : '' ?>">Suivant →</a>
                </div>
            </div>
        </div>

    <?php elseif ($step === 3): /* ──────────────── ÉTAPE 3 : IMMEUBLE ──────────────── */ ?>

        <div class="panel">
            <h2 class="immeuble">③ Immeuble</h2>

            <div class="field">
                <label>Adresse</label>
                <input type="text" name="s_imm_adresse"
                       value="<?= ma_html((string)($state['s_imm_adresse'] ?? '')) ?>">
            </div>
            <div class="grid2">
                <div class="field">
                    <label>Code postal</label>
                    <input type="text" name="s_imm_cp"
                           value="<?= ma_html((string)($state['s_imm_cp'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Ville</label>
                    <input type="text" name="s_imm_ville"
                           value="<?= ma_html((string)($state['s_imm_ville'] ?? '')) ?>">
                </div>
            </div>
            <div class="grid3">
                <div class="field">
                    <label>Google place_id</label>
                    <input type="text" name="s_imm_place_id"
                           value="<?= ma_html((string)($state['s_imm_place_id'] ?? '')) ?>"
                           placeholder="Match prioritaire si fourni">
                </div>
                <div class="field">
                    <label>Latitude</label>
                    <input type="text" name="s_imm_lat"
                           value="<?= ma_html((string)($state['s_imm_lat'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Longitude</label>
                    <input type="text" name="s_imm_lng"
                           value="<?= ma_html((string)($state['s_imm_lng'] ?? '')) ?>">
                </div>
            </div>

            <?php if ($results['immeuble']): ?>
                <div class="results">
                    <?php if (isset($results['immeuble']['ERREUR'])): ?>
                        <div class="verdict err">❌ Erreur : <?= ma_html((string)$results['immeuble']['ERREUR']) ?></div>
                    <?php else:
                        $r = $results['immeuble'];
                        $found = !empty($r['found']);
                        $conf  = (int)($r['confidence'] ?? 0);
                        $verdictCls = $found ? ($conf >= 90 ? 'found' : 'create') : 'create';
                        $barCls = $conf >= 80 ? '' : ($conf >= 50 ? 'medium' : 'low');
                    ?>
                        <div class="verdict <?= $verdictCls ?>">
                            <?= $found ? '✅ '.count($r['matches']).' immeuble(s) candidat(s)' : '⚠️ Aucun match — création possible' ?>
                            · Confiance <?= $conf ?>%
                        </div>
                        <div class="confidence-bar <?= $barCls ?>">
                            <div style="width: <?= $conf ?>%"></div>
                        </div>
                        <?php foreach (array_slice($r['matches'] ?? [], 0, 5) as $i => $m): ?>
                            <div class="match-item <?= $i === 0 ? 'best' : '' ?>">
                                <span class="score"><?= (int)($m['score'] ?? 0) ?></span>
                                <b>#<?= (int)$m['id'] ?></b>
                                · <?= ma_html((string)($m['adresse'] ?? '?')) ?>
                                <?php if (!empty($m['code_postal']) || !empty($m['ville'])): ?>
                                    , <?= ma_html((string)($m['code_postal'] ?? '')) ?>
                                    <?= ma_html((string)($m['ville'] ?? '')) ?>
                                <?php endif; ?>
                                <?php if (!empty($m['reasons'])): ?>
                                    <div class="reasons"><?= ma_html(is_array($m['reasons']) ? implode(' · ', $m['reasons']) : (string)$m['reasons']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <a class="btn btn-ghost" href="?step=2<?= $cardId ? '&card_id='.$cardId : '' ?>">← Précédent</a>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary">🔍 Matcher</button>
                    <a class="btn btn-primary" href="?step=4<?= $cardId ? '&card_id='.$cardId : '' ?>">Récap →</a>
                </div>
            </div>
        </div>

    <?php elseif ($step === 4): /* ──────────────── ÉTAPE 4 : CONFIRMATION ──────────────── */ ?>

        <div class="panel">
            <h2 class="confirm">④ Récapitulatif</h2>

            <?php if ($globalConf !== null): ?>
                <div style="background:#0f172a; padding:14px; border-radius:4px; margin-bottom:14px;
                            border-left:4px solid <?= $globalConf >= 80 ? 'var(--ok)' : ($globalConf >= 50 ? 'var(--warn)' : 'var(--ko)') ?>;">
                    <div style="font-size:13px; font-weight:700; margin-bottom:6px;">
                        Confiance globale : <?= $globalConf ?>%
                        <span style="color: var(--muted); font-weight: normal; font-size: 11px;">
                            (min des 3 étapes : <?= ma_html(implode('/', $confs)) ?>)
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach (['tiers' => '① Tiers', 'bien' => '② Bien', 'immeuble' => '③ Immeuble'] as $k => $label): ?>
                <div class="recap-block">
                    <h3><?= ma_html($label) ?></h3>
                    <?php if (!$results[$k]): ?>
                        <code style="color: var(--muted);">(non renseigné)</code>
                    <?php elseif (isset($results[$k]['ERREUR'])): ?>
                        <code style="color: var(--ko);">Erreur : <?= ma_html((string)$results[$k]['ERREUR']) ?></code>
                    <?php else:
                        $r = $results[$k];
                        $best = $r['best'] ?? null;
                    ?>
                        <code>
                            <?= !empty($r['found']) ? '✅' : '⚠️' ?>
                            confidence=<?= (int)($r['confidence'] ?? 0) ?>%
                            · matches=<?= count($r['matches'] ?? []) ?>
                            <?php if ($best): ?>
                                · best_id=<?= (int)$best['id'] ?>
                            <?php endif; ?>
                        </code>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="v0-warn">
                <b>V0 :</b> aucune écriture BDD. La confirmation n'enregistre rien encore.
                V1 = bouton "Confirmer les liens" qui injecte dans <code>fluxbox_cartes.proposition_json.matching_v1</code>
                + (option) crée les entités manquantes avec validation user explicite.
            </div>

            <div class="actions">
                <a class="btn btn-ghost" href="?step=3<?= $cardId ? '&card_id='.$cardId : '' ?>">← Précédent</a>
                <div style="display:flex; gap:10px;">
                    <button type="submit" name="action" value="reset" class="btn btn-danger">↺ Recommencer</button>
                    <a class="btn btn-primary" href="admin_doc_classify_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">Étape suivante : classement 3C →</a>
                </div>
            </div>
        </div>

    <?php endif; ?>

    </form>

    <details style="margin-top:20px; color: var(--muted); font-size: 11px;">
        <summary style="cursor:pointer;">🔍 État session brute (debug)</summary>
        <pre><?= ma_html(json_encode([
            'step' => $step,
            'state' => $state,
            'results_summary' => array_map(function($r){
                if (!$r) return null;
                if (isset($r['ERREUR'])) return ['err' => $r['ERREUR']];
                return [
                    'found' => $r['found'] ?? null,
                    'confidence' => $r['confidence'] ?? null,
                    'count' => count($r['matches'] ?? []),
                ];
            }, $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>

</div>

</body>
</html>
