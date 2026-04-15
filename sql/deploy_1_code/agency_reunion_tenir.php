<?php
// agency_reunion_tenir.php — Tenir la réunion en direct V2 MaBoxImmo
// Fonctions : horodatage début/fin, suivi ODJ point par point, votes, résolutions, émargement
$current_page = 'reunions';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
if ($roleId > 2) { header('Location: agency_reunions.php'); exit; }
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: agency_reunions.php'); exit; }

// ── AJAX / POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Chrono début/fin
    if (isset($_POST['champ']) && in_array($_POST['champ'], ['debut_effectif','fin_effective','statut'], true)) {
        $v = $_POST['valeur'] ?? null;
        if (in_array($_POST['champ'], ['debut_effectif','fin_effective'], true)) {
            $v = $v ? str_replace('T',' ', trim($v)) : null;
        }
        $pdo->prepare("UPDATE agency_reunion SET {$_POST['champ']} = ? WHERE id = ?")->execute([$v, $id]);
        echo 'OK'; exit;
    }

    // Marquer point ODJ comme traité / non traité
    if (isset($_POST['toggle_odj'])) {
        $oid = (int)$_POST['toggle_odj'];
        $pdo->prepare("UPDATE agency_reunion_odj SET traite = NOT traite WHERE id = ? AND id_reunion = ?")->execute([$oid, $id]);
        echo 'OK'; exit;
    }

    // Enregistrer vote / résolution
    if (isset($_POST['save_resolution'])) {
        $oid         = (int)($_POST['id_odj'] ?? 0);
        $texte       = trim($_POST['texte_resolution'] ?? '');
        $pour        = (int)($_POST['vote_pour'] ?? 0);
        $contre      = (int)($_POST['vote_contre'] ?? 0);
        $abstention  = (int)($_POST['vote_abstention'] ?? 0);
        $adopte      = ($pour > $contre) ? 1 : 0;

        // Check si résolution existe déjà pour ce point
        $ex = $pdo->prepare("SELECT id FROM agency_reunion_resolution WHERE id_reunion=? AND id_odj=?");
        $ex->execute([$id, $oid]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE agency_reunion_resolution SET texte=?,vote_pour=?,vote_contre=?,vote_abstention=?,adopte=? WHERE id_reunion=? AND id_odj=?")
                ->execute([$texte,$pour,$contre,$abstention,$adopte,$id,$oid]);
        } else {
            $pdo->prepare("INSERT INTO agency_reunion_resolution (id_reunion,id_odj,texte,vote_pour,vote_contre,vote_abstention,adopte) VALUES (?,?,?,?,?,?,?)")
                ->execute([$id,$oid,$texte,$pour,$contre,$abstention,$adopte]);
        }
        // Marque le point traité
        $pdo->prepare("UPDATE agency_reunion_odj SET traite=1 WHERE id=?")->execute([$oid]);
        echo json_encode(['ok'=>1,'adopte'=>$adopte]); exit;
    }

    // Ajout participant en direct
    if (isset($_POST['ajouts']) && is_array($_POST['ajouts'])) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO agency_reunion_participant (id_reunion, id_user) VALUES (?,?)");
        foreach ($_POST['ajouts'] as $uid) $stmt->execute([$id, (int)$uid]);
        header("Location: agency_reunion_tenir.php?id=$id"); exit;
    }

    // Clôturer la réunion
    if (isset($_POST['cloturer'])) {
        // Vérifie qu'il y a une date de fin
        $pdo->prepare("UPDATE agency_reunion SET statut='cloturee', fin_effective=COALESCE(fin_effective,NOW()) WHERE id=?")->execute([$id]);
        header("Location: agency_reunion_detail.php?id=$id"); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*,
           i.nom_immeuble AS immeuble_nom, i.reference_immeuble AS immeuble_ref,
           e.nom AS etab_nom
    FROM agency_reunion r
    LEFT JOIN immeubles i ON i.id = r.id_immeuble
    LEFT JOIN etablissements e ON e.id = r.id_etablissement
    WHERE r.id = ?
");
$stmt->execute([$id]);
$reunion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reunion) { header('Location: agency_reunions.php'); exit; }
if ($reunion['statut'] === 'cloturee') { header("Location: agency_reunion_detail.php?id=$id"); exit; }

$odj_stmt = $pdo->prepare("SELECT * FROM agency_reunion_odj WHERE id_reunion = ? ORDER BY ordre, id");
$odj_stmt->execute([$id]);
$odj = $odj_stmt->fetchAll(PDO::FETCH_ASSOC);

$parts_stmt = $pdo->prepare("
    SELECT u.id, u.nom, u.prenom, u.email
    FROM agency_reunion_participant rp
    JOIN users u ON u.id = rp.id_user
    WHERE rp.id_reunion = ?
    ORDER BY u.nom, u.prenom
");
$parts_stmt->execute([$id]);
$participants    = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);
$participants_ids = array_column($participants, 'id');

$res_stmt = $pdo->prepare("SELECT * FROM agency_reunion_resolution WHERE id_reunion = ?");
$res_stmt->execute([$id]);
$resolutions = [];
foreach ($res_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $resolutions[$r['id_odj']] = $r;
}

$all_users   = $pdo->query("SELECT id, nom, prenom FROM users WHERE actif=1 ORDER BY nom,prenom")->fetchAll(PDO::FETCH_ASSOC);
$non_invites = array_filter($all_users, fn($u) => !in_array($u['id'], $participants_ids));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dtLocal(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt); return $ts ? date('Y-m-d\TH:i', $ts) : '';
}
$nb_traites = count(array_filter($odj, fn($p) => $p['traite']));
$pct = count($odj) ? round($nb_traites / count($odj) * 100) : 0;

// ── Layout ───────────────────────────────────────────────────────────
$layout_title   = 'Tenir : '.($reunion['titre'] ?? '');
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.$pct.'%</div><div class="ph-kpi-lbl">Avancement</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.$nb_traites.' / '.count($odj).'</div><div class="ph-kpi-lbl">Points</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.count($participants).'</div><div class="ph-kpi-lbl">Participants</div></div>
';

$layout_head_actions = '
    <a href="agency_reunion_detail.php?id='.$id.'" class="ph-btn">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        Fiche
    </a>
    <a href="agency_reunions.php" class="ph-btn">Liste</a>
    <a class="ph-btn dispo">—</a>
    <a class="ph-btn dispo">—</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.tenir-grid{display:grid;grid-template-columns:1fr 320px;gap:20px}
.tenir-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:20px 24px;margin-bottom:18px}
.tenir-card-title{font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#2c2a28;margin-bottom:14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e4e6ec;padding-bottom:10px}
.tenir-card-title svg{width:15px;height:15px;stroke:#4878a6;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
/* Chrono */
.chrono-row{display:flex;gap:14px;align-items:flex-end}
.chrono-group{display:flex;flex-direction:column;gap:5px}
.chrono-label{font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}
.chrono-input{background:var(--bg-secondary,#eef1f6);border:none;border-radius:10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:8px 12px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28}
/* Progression */
.progress-bar{height:8px;border-radius:999px;background:#e4e6ec;overflow:hidden;margin:10px 0 4px}
.progress-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#6898bf,#4878a6);transition:width .4s}
/* ODJ tenir */
.odj-tenir{background:var(--bg-secondary,#eef1f6);border-radius:14px;padding:14px 16px;margin-bottom:10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;transition:background .2s}
.odj-tenir.done{background:#d8eee3;box-shadow:inset 2px 2px 5px #b8d4be,inset -2px -2px 5px #f0f8f0}
.odj-tenir-head{display:flex;align-items:center;gap:10px;cursor:pointer}
.odj-num{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#6898bf,#4878a6);display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.odj-num.done{background:linear-gradient(135deg,#60a878,#3a8050)}
.odj-titre{font-family:'Sora',sans-serif;font-size:13px;font-weight:600;color:#2c2a28;flex:1}
.odj-status{font-family:'DM Mono',monospace;font-size:10px;font-weight:700;padding:2px 9px;border-radius:999px}
/* Vote panel */
.vote-panel{margin-top:12px;display:none;border-top:1px solid var(--shadow-dark,#d4d7de);padding-top:12px}
.vote-panel.open{display:block}
.vote-inputs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px}
.vote-group{display:flex;flex-direction:column;gap:4px}
.vote-group label{font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.08em}
.vote-input{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border:none;border-radius:8px;box-shadow:inset 2px 2px 4px var(--shadow-dark,#d4d7de),inset -2px -2px 4px #f8f4ee;padding:7px 10px;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;text-align:center;width:100%;color:#2c2a28}
.vote-textarea{width:100%;background:var(--bg-primary,var(--bg-primary,#e4e8f0));border:none;border-radius:10px;box-shadow:inset 2px 2px 4px var(--shadow-dark,#d4d7de),inset -2px -2px 4px #f8f4ee;padding:8px 12px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;resize:vertical;min-height:55px;margin-bottom:8px}
/* Participants sidebar */
.part-item{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:10px;margin-bottom:5px;background:var(--bg-secondary,#eef1f6);box-shadow:inset 1px 1px 3px #cac6c0,inset -1px -1px 3px #f8f4ee}
.part-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#8eb4d3,#4878a6);display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:9px;font-weight:700;color:#fff;flex-shrink:0}
/* Save toast */
#save-toast{position:fixed;bottom:20px;right:20px;background:#2c2a28;color:#fff;border-radius:12px;padding:10px 18px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;display:none;z-index:999;box-shadow:0 4px 16px rgba(0,0,0,.2)}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);border:none;cursor:pointer;color:#4878a6;text-decoration:none}
.btn-xs.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff}
.act-btn{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 8px var(--shadow-light,#fff);text-decoration:none;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;color:#4878a6}
</style>
EXTRACSS;

// PHP expressions in JS — inline script in content
$layout_extra_js = '';

ob_start();
?>

<!-- Bandeau clôture -->
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;gap:10px;align-items:center">
    <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:#4878a6" id="live-clock"></div>
    <form method="POST" style="display:inline" onsubmit="return confirm('Clôturer la réunion ? Cette action est irréversible.')">
        <button type="submit" name="cloturer" value="1" class="btn-xs" style="background:linear-gradient(135deg,#c84040,#a83030);color:#fff">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Clôturer
        </button>
    </form>
</div>

<div class="tenir-grid">

    <!-- Colonne principale -->
    <div>

        <!-- Chrono -->
        <div class="tenir-card">
            <div class="tenir-card-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Horodatage de la séance
            </div>
            <div class="chrono-row">
                <div class="chrono-group">
                    <div class="chrono-label">Début effectif</div>
                    <input type="datetime-local" class="chrono-input" id="f_debut"
                        value="<?= dtLocal($reunion['debut_effectif'] ?? '') ?>"
                        onchange="saveChamp('debut_effectif',this.value)">
                    <button onclick="setNow('f_debut','debut_effectif')" class="btn-xs" style="margin-top:4px">⏱ Maintenant</button>
                </div>
                <div class="chrono-group">
                    <div class="chrono-label">Fin effective</div>
                    <input type="datetime-local" class="chrono-input" id="f_fin"
                        value="<?= dtLocal($reunion['fin_effective'] ?? '') ?>"
                        onchange="saveChamp('fin_effective',this.value)">
                    <button onclick="setNow('f_fin','fin_effective')" class="btn-xs" style="margin-top:4px">⏱ Maintenant</button>
                </div>
                <?php if ($reunion['immeuble_nom']): ?>
                <div style="margin-left:auto;text-align:right">
                    <div class="chrono-label">Immeuble</div>
                    <div style="font-weight:700;color:#1a1816;font-size:13px"><?= h($reunion['immeuble_nom']) ?></div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690"><?= h($reunion['immeuble_ref']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progression ODJ -->
        <div class="tenir-card" style="padding:16px 24px 14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                <div style="font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#2c2a28">Avancement de l'ordre du jour</div>
                <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:#4878a6" id="pct-txt"><?= $pct ?>%</div>
            </div>
            <div class="progress-bar"><div class="progress-fill" id="pct-bar" style="width:<?= $pct ?>%"></div></div>
            <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690" id="pct-sub"><?= $nb_traites ?> / <?= count($odj) ?> point<?= count($odj)>1?'s':'' ?> traité<?= count($odj)>1?'s':'' ?></div>
        </div>

        <!-- ODJ -->
        <div class="tenir-card">
            <div class="tenir-card-title">
                <svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                Ordre du jour
            </div>
            <?php if (empty($odj)): ?>
            <div style="font-family:'DM Mono',monospace;font-size:11px;color:#9a9690;text-align:center;padding:16px 0">Aucun point inscrit</div>
            <?php else: ?>
            <?php foreach ($odj as $i => $pt):
                $isDone = (bool)$pt['traite'];
                $res    = $resolutions[$pt['id']] ?? null;
            ?>
            <div class="odj-tenir <?= $isDone ? 'done' : '' ?>" id="odj-<?= $pt['id'] ?>">
                <div class="odj-tenir-head" onclick="togglePanel(<?= $pt['id'] ?>)">
                    <div class="odj-num <?= $isDone ? 'done' : '' ?>"><?= $i+1 ?></div>
                    <div class="odj-titre"><?= h($pt['intitule']) ?></div>
                    <?php if ($isDone): ?>
                    <span class="odj-status" style="background:#d8eee3;color:#2a6040">Traité</span>
                    <?php else: ?>
                    <span class="odj-status" style="background:var(--bg-primary,#e4e8f0);color:#9a9690">En attente</span>
                    <?php endif; ?>
                    <?php if ($res): ?>
                    <span class="odj-status" style="background:<?= $res['adopte'] ? '#d8eee3' : '#fce8e8' ?>;color:<?= $res['adopte'] ? '#2a6040' : '#c84040' ?>">
                        <?= $res['adopte'] ? 'Adopté' : 'Rejeté' ?>
                        (<?= $res['vote_pour'] ?>✓ / <?= $res['vote_contre'] ?>✗)
                    </span>
                    <?php endif; ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9a9690" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="chevron-<?= $pt['id'] ?>"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="vote-panel" id="panel-<?= $pt['id'] ?>">
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Résolution / vote</div>
                    <textarea class="vote-textarea" id="txt-<?= $pt['id'] ?>" placeholder="Texte de la résolution…"><?= h($res['texte'] ?? '') ?></textarea>
                    <div class="vote-inputs">
                        <div class="vote-group">
                            <label style="color:#2a6040">✓ Pour</label>
                            <input type="number" class="vote-input" id="pour-<?= $pt['id'] ?>" value="<?= $res['vote_pour'] ?? 0 ?>" min="0" style="color:#2a6040">
                        </div>
                        <div class="vote-group">
                            <label style="color:#c84040">✗ Contre</label>
                            <input type="number" class="vote-input" id="contre-<?= $pt['id'] ?>" value="<?= $res['vote_contre'] ?? 0 ?>" min="0" style="color:#c84040">
                        </div>
                        <div class="vote-group">
                            <label style="color:#808080">○ Abstention</label>
                            <input type="number" class="vote-input" id="abst-<?= $pt['id'] ?>" value="<?= $res['vote_abstention'] ?? 0 ?>" min="0" style="color:#808080">
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <button class="btn-xs primary" onclick="saveResolution(<?= $pt['id'] ?>)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            Valider
                        </button>
                        <button class="btn-xs" onclick="toggleDone(<?= $pt['id'] ?>)">
                            <?= $isDone ? '↩ Rouvrir' : '✓ Marquer traité' ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
    <!-- /Colonne principale -->

    <!-- Sidebar droite -->
    <div>

        <!-- Participants présents -->
        <div class="tenir-card">
            <div class="tenir-card-title">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Participants
                <span style="margin-left:auto;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#4878a6"><?= count($participants) ?></span>
            </div>
            <?php foreach ($participants as $p):
                $initials_p = strtoupper(substr($p['prenom'],0,1).substr($p['nom'],0,1));
            ?>
            <div class="part-item">
                <div class="part-avatar"><?= h($initials_p) ?></div>
                <span style="font-family:'Sora',sans-serif;font-size:11px;font-weight:500;color:#2c2a28"><?= h(trim($p['prenom'].' '.$p['nom'])) ?></span>
            </div>
            <?php endforeach; ?>
            <!-- Ajouter participant en direct -->
            <?php if (!empty($non_invites)): ?>
            <form method="POST" style="margin-top:10px;display:flex;gap:6px;align-items:center">
                <select name="ajouts[]" style="flex:1;background:var(--bg-secondary,#eef1f6);border:none;border-radius:8px;box-shadow:inset 2px 2px 4px #cac6c0,inset -2px -2px 4px #f8f4ee;padding:5px 8px;font-family:'Sora',sans-serif;font-size:11px;color:#2c2a28;height:30px">
                    <?php foreach ($non_invites as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= h(trim($u['prenom'].' '.$u['nom'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-xs primary" style="height:30px">+</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- PDF / Export -->
        <div class="tenir-card">
            <div class="tenir-card-title">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Documents
            </div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <a href="agency_pdf_convocation.php?id=<?= $id ?>" target="_blank" class="act-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Convocation PDF
                </a>
                <a href="agency_pdf_cr.php?id=<?= $id ?>" target="_blank" class="act-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="16 17 12 13 8 17"/><line x1="12" y1="13" x2="12" y2="21"/></svg>
                    Compte-rendu PDF
                </a>
            </div>
        </div>

    </div>
    <!-- /Sidebar -->

</div>

<div id="save-toast">✅ Sauvegardé</div>

<script>
const RID = <?= $id ?>;
let totalOdj = <?= count($odj) ?>;
let doneCount = <?= $nb_traites ?>;

// Live clock
function tick() {
    const now = new Date();
    document.getElementById('live-clock').textContent =
        String(now.getHours()).padStart(2,'0') + ':' +
        String(now.getMinutes()).padStart(2,'0') + ':' +
        String(now.getSeconds()).padStart(2,'0');
}
tick(); setInterval(tick, 1000);

// Toast
function toast() {
    const t = document.getElementById('save-toast');
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => t.style.display = 'none', 2000);
}

// Chrono
function saveChamp(field, val) {
    fetch('agency_reunion_tenir.php?id=' + RID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'champ='+encodeURIComponent(field)+'&valeur='+encodeURIComponent(val)
    }).then(r=>r.text()).then(t=>{ if(t==='OK') toast(); });
}
function setNow(inputId, field) {
    const now = new Date();
    const v = now.getFullYear() + '-' +
        String(now.getMonth()+1).padStart(2,'0') + '-' +
        String(now.getDate()).padStart(2,'0') + 'T' +
        String(now.getHours()).padStart(2,'0') + ':' +
        String(now.getMinutes()).padStart(2,'0');
    document.getElementById(inputId).value = v;
    saveChamp(field, v);
}

// Toggle panel ODJ
function togglePanel(oid) {
    const p  = document.getElementById('panel-' + oid);
    const ch = document.getElementById('chevron-' + oid);
    const open = p.classList.toggle('open');
    ch.style.transform = open ? 'rotate(180deg)' : '';
}

// Marquer traité (sans vote)
function toggleDone(oid) {
    fetch('agency_reunion_tenir.php?id=' + RID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'toggle_odj=' + oid
    }).then(r=>r.text()).then(t=>{
        if(t==='OK') {
            const el = document.getElementById('odj-' + oid);
            const isDone = el.classList.toggle('done');
            el.querySelector('.odj-num').classList.toggle('done', isDone);
            // Update status badge
            const badges = el.querySelectorAll('.odj-status');
            badges.forEach(b => {
                if (!b.textContent.includes('Adopt') && !b.textContent.includes('Rejet')) {
                    b.textContent = isDone ? 'Traité' : 'En attente';
                    b.style.background = isDone ? '#d8eee3' : 'var(--bg-primary,#e4e8f0)';
                    b.style.color = isDone ? '#2a6040' : '#9a9690';
                }
            });
            doneCount += isDone ? 1 : -1;
            updateProgress();
            toast();
        }
    });
}

// Enregistrer résolution + votes
function saveResolution(oid) {
    const texte  = document.getElementById('txt-' + oid).value;
    const pour   = parseInt(document.getElementById('pour-' + oid).value) || 0;
    const contre = parseInt(document.getElementById('contre-' + oid).value) || 0;
    const abst   = parseInt(document.getElementById('abst-' + oid).value) || 0;

    fetch('agency_reunion_tenir.php?id=' + RID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'save_resolution=1&id_odj='+oid+
              '&texte_resolution='+encodeURIComponent(texte)+
              '&vote_pour='+pour+'&vote_contre='+contre+'&vote_abstention='+abst
    }).then(r=>r.json()).then(d=>{
        if(d.ok) {
            const el = document.getElementById('odj-' + oid);
            el.classList.add('done');
            el.querySelector('.odj-num').classList.add('done');
            toast();
            // Màj badge résolution
            // Retire ancien badge vote s'il existe
            el.querySelectorAll('.odj-status').forEach(b => {
                if (b.textContent.includes('Adopt') || b.textContent.includes('Rejet')) b.remove();
            });
            const span = document.createElement('span');
            span.className = 'odj-status';
            span.style.background = d.adopte ? '#d8eee3' : '#fce8e8';
            span.style.color = d.adopte ? '#2a6040' : '#c84040';
            span.textContent = (d.adopte ? 'Adopté' : 'Rejeté') + ' (' + pour + '✓ / ' + contre + '✗)';
            el.querySelector('.odj-tenir-head').appendChild(span);
            // Mise à jour progression
            if (!el.classList.contains('done-counted')) {
                el.classList.add('done-counted');
                doneCount++;
                updateProgress();
            }
        }
    });
}

function updateProgress() {
    const pct = totalOdj ? Math.round(doneCount / totalOdj * 100) : 0;
    document.getElementById('pct-bar').style.width = pct + '%';
    document.getElementById('pct-txt').textContent = pct + '%';
    document.getElementById('pct-sub').textContent = doneCount + ' / ' + totalOdj + ' point' + (totalOdj > 1 ? 's' : '') + ' traité' + (totalOdj > 1 ? 's' : '');
}
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
