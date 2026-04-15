<?php
// agency_reunion_detail.php — Détail réunion V2 MaBoxImmo
// Fonctions : affichage fiche, ODJ, participants, convocation PDF, envoi mail convocation
$current_page = 'reunions';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: agency_reunions.php'); exit; }

// ── AJAX / POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Édition inline champ
    if (isset($_POST['champ']) && in_array($_POST['champ'], ['titre','lieu','date_reunion','fin_effective','commentaire'], true)) {
        $champ  = $_POST['champ'];
        $valeur = $_POST['valeur'] ?? null;
        if (in_array($champ, ['date_reunion','fin_effective'], true)) {
            $valeur = $valeur ? str_replace('T',' ', trim($valeur)) : null;
        } else {
            $valeur = trim((string)$valeur) ?: null;
        }
        $pdo->prepare("UPDATE agency_reunion SET $champ = :v WHERE id = :id")->execute([':v'=>$valeur,':id'=>$id]);
        echo 'OK'; exit;
    }
    // Ajout participants
    if (isset($_POST['ajouts']) && is_array($_POST['ajouts'])) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO agency_reunion_participant (id_reunion, id_user) VALUES (?,?)");
        foreach ($_POST['ajouts'] as $uid) $stmt->execute([$id, (int)$uid]);
        header("Location: agency_reunion_detail.php?id=$id"); exit;
    }
    // Suppression participant
    if (isset($_POST['supprimer_participant'])) {
        $pdo->prepare("DELETE FROM agency_reunion_participant WHERE id_reunion=? AND id_user=?")->execute([$id, (int)$_POST['supprimer_participant']]);
        header("Location: agency_reunion_detail.php?id=$id"); exit;
    }
    // Clôturer
    if (isset($_POST['cloturer'])) {
        $pdo->prepare("UPDATE agency_reunion SET statut='cloturee' WHERE id=?")->execute([$id]);
        header("Location: agency_reunion_detail.php?id=$id"); exit;
    }
    // Envoyer convocation par mail
    if (isset($_POST['send_convocation'])) {
        require_once __DIR__ . '/inc/mailer_reunion.php';
        // Génère PDF convocation en mémoire et envoie
        $result = sendConvocationMail($pdo, $id);
        header("Location: agency_reunion_detail.php?id=$id&mail=" . ($result ? 'ok' : 'err')); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*,
           i.nom_immeuble AS immeuble_nom, i.reference_immeuble AS immeuble_ref, i.adresse_1 AS immeuble_adresse,
           i.ville AS immeuble_ville, i.nb_lots,
           e.nom AS etab_nom,
           u.nom AS auteur_nom, u.prenom AS auteur_prenom
    FROM agency_reunion r
    LEFT JOIN immeubles i ON i.id = r.id_immeuble
    LEFT JOIN etablissements e ON e.id = r.id_etablissement
    LEFT JOIN users u ON u.id = r.cree_par
    WHERE r.id = ?
");
$stmt->execute([$id]);
$reunion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reunion) { header('Location: agency_reunions.php'); exit; }

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

$resolutions_stmt = $pdo->prepare("
    SELECT res.*, o.intitule AS odj_intitule
    FROM agency_reunion_resolution res
    LEFT JOIN agency_reunion_odj o ON o.id = res.id_odj
    WHERE res.id_reunion = ?
    ORDER BY res.id
");
$resolutions_stmt->execute([$id]);
$resolutions = $resolutions_stmt->fetchAll(PDO::FETCH_ASSOC);

$all_users  = $pdo->query("SELECT id, nom, prenom, email FROM users WHERE actif = 1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);
$non_invites = array_filter($all_users, fn($u) => !in_array($u['id'], $participants_ids));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dtFr(?string $dt, bool $heure = true): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date($heure ? 'd/m/Y à H:i' : 'd/m/Y', $ts) : '—';
}
function dtLocal(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

$statutColor = ['planifiee'=>'#4878a6','en_cours'=>'#3a7a6a','cloturee'=>'#808080'];
$statutLabel = ['planifiee'=>'Planifiée','en_cours'=>'En cours','cloturee'=>'Clôturée'];
$sc = $statutColor[$reunion['statut'] ?? 'planifiee'] ?? '#4878a6';
$sl = $statutLabel[$reunion['statut'] ?? 'planifiee'] ?? '—';
$canEdit = ($roleId <= 2);

// ── Layout ───────────────────────────────────────────────────────────
$layout_title   = $reunion['titre'] ?? ('Réunion #'.$id);
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:'.$sc.'">'.$sl.'</div><div class="ph-kpi-lbl">Statut</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.count($odj).'</div><div class="ph-kpi-lbl">Points ODJ</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.count($participants).'</div><div class="ph-kpi-lbl">Invités</div></div>
';

$_hActions = '';
if ($canEdit && ($reunion['statut'] ?? '') !== 'cloturee') {
    $_hActions .= '<a href="agency_reunion_tenir.php?id='.$id.'" class="ph-btn primary"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>Tenir</a>';
    $_hActions .= '<a href="agency_reunion_form.php?id='.$id.'" class="ph-btn">Modifier</a>';
} else {
    $_hActions .= '<a class="ph-btn dispo">—</a><a class="ph-btn dispo">—</a>';
}
$_hActions .= '<a href="agency_pdf_convocation.php?id='.$id.'" target="_blank" class="ph-btn">PDF Conv.</a>';
$_hActions .= '<a href="agency_reunions.php" class="ph-btn">Liste</a>';
$layout_head_actions = $_hActions;

$layout_extra_css = <<<'EXTRACSS'
<style>
.det-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px}
.det-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:20px 24px;margin-bottom:0}
.det-card-title{font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#2c2a28;margin-bottom:14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e4e6ec;padding-bottom:10px}
.det-card-title svg{width:15px;height:15px;stroke:#4878a6;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
/* Editable inline */
.editable{cursor:text;padding:4px 8px;border-radius:8px;transition:background .15s}
.editable:hover{background:#e0dbd4}
.editable[contenteditable="true"]{background:var(--bg-secondary,#eef1f6);box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;outline:none}
/* ODJ */
.odj-row{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:12px;background:var(--bg-secondary,#eef1f6);box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;margin-bottom:8px}
.odj-num{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,#6898bf,#4878a6);display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:10px;font-weight:700;color:#fff;flex-shrink:0}
.odj-text{font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;flex:1;line-height:1.5}
.odj-done{width:18px;height:18px;flex-shrink:0;margin-top:2px}
/* Participants */
.part-pill{display:inline-flex;align-items:center;gap:5px;background:var(--bg-secondary,#eef1f6);box-shadow:2px 2px 6px var(--shadow-dark,#d4d7de),-2px -2px 6px var(--shadow-light,#fff);border-radius:999px;padding:4px 10px;font-family:'Sora',sans-serif;font-size:11px;color:#2c2a28;margin:3px}
.part-del{background:none;border:none;cursor:pointer;color:#c84040;font-size:13px;padding:0;margin-left:2px;line-height:1}
/* Résolutions */
.res-row{display:flex;gap:10px;padding:10px 12px;border-radius:12px;background:var(--bg-secondary,#eef1f6);box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;margin-bottom:8px;align-items:center}
.vote-chip{padding:2px 8px;border-radius:999px;font-family:'DM Mono',monospace;font-size:10px;font-weight:700}
/* Action buttons */
.action-group{display:flex;flex-direction:column;gap:8px}
.act-btn{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:4px 4px 10px var(--shadow-dark,#d4d7de),-4px -4px 10px var(--shadow-light,#fff);text-decoration:none;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#4878a6;border:none;cursor:pointer;transition:box-shadow .15s;width:100%;justify-content:flex-start}
.act-btn:hover{box-shadow:2px 2px 6px var(--shadow-dark,#d4d7de),-2px -2px 6px var(--shadow-light,#fff)}
.act-btn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
.act-btn.green{color:#3a7a6a}
.act-btn.orange{color:#7a6830}
.act-btn.red{color:#c84040}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);border:none;cursor:pointer;color:#4878a6;text-decoration:none}
.btn-xs.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff}
/* Save notice */
#save-notice{display:none;background:#d8eee3;color:#2a6040;border-radius:10px;padding:8px 14px;margin-bottom:12px;font-family:'Sora',sans-serif;font-size:12px}
</style>
EXTRACSS;

// PHP expressions embedded in JS — keep script inside content instead of extra_js
$layout_extra_js = '';

ob_start();
?>

<?php if (isset($_GET['created'])): ?>
<div style="background:#d8eee3;color:#2a6040;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">✅ Réunion créée avec succès.</div>
<?php endif; ?>
<?php if (isset($_GET['mail'])): ?>
<div style="background:<?= $_GET['mail']==='ok' ? '#d8eee3' : '#fce8e8' ?>;color:<?= $_GET['mail']==='ok' ? '#2a6040' : '#c84040' ?>;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">
    <?= $_GET['mail']==='ok' ? '✅ Convocation envoyée par e-mail.' : '❌ Erreur lors de l\'envoi de la convocation.' ?>
</div>
<?php endif; ?>

<div id="save-notice">✅ Sauvegardé</div>

<div class="det-grid">

    <!-- Colonne principale -->
    <div style="display:flex;flex-direction:column;gap:18px">

        <!-- Informations -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Informations
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:3px">Date</div>
                    <?php if ($canEdit): ?>
                    <input type="datetime-local" id="f_date_reunion" value="<?= dtLocal($reunion['date_reunion']) ?>"
                        style="background:var(--bg-secondary,#eef1f6);border:none;border-radius:8px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:6px 10px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28"
                        onchange="saveField('date_reunion',this.value)">
                    <?php else: ?>
                    <div style="font-family:'Sora',sans-serif;font-size:13px;font-weight:600"><?= dtFr($reunion['date_reunion']) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:3px">Lieu</div>
                    <?php if ($canEdit): ?>
                    <span class="editable" contenteditable="true" data-field="lieu" onblur="saveField('lieu',this.innerText.trim())"><?= h($reunion['lieu'] ?? '') ?></span>
                    <?php else: ?>
                    <div style="font-size:13px"><?= h($reunion['lieu'] ?? '—') ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($reunion['immeuble_nom']): ?>
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:3px">Immeuble</div>
                    <a href="agency_immeuble_fiche.php?id=<?= $reunion['id_immeuble'] ?>" style="color:#4878a6;text-decoration:none;font-size:13px;font-weight:600">
                        <?= h($reunion['immeuble_nom']) ?> (<?= h($reunion['immeuble_ref']) ?>)
                    </a>
                </div>
                <?php endif; ?>
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:3px">Établissement</div>
                    <div style="font-size:13px"><?= h($reunion['etab_nom'] ?? '—') ?></div>
                </div>
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:3px">Créée par</div>
                    <div style="font-size:13px"><?= h(trim(($reunion['auteur_prenom'] ?? '') . ' ' . ($reunion['auteur_nom'] ?? ''))) ?></div>
                </div>
                <?php if ($reunion['fin_effective']): ?>
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:3px">Fin effective</div>
                    <div style="font-size:13px"><?= dtFr($reunion['fin_effective']) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($reunion['commentaire']): ?>
            <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e4e6ec">
                <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:4px">Commentaire</div>
                <div style="font-size:12px;color:#4a4844;line-height:1.6"><?= nl2br(h($reunion['commentaire'])) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Ordre du jour -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                Ordre du jour
                <span style="margin-left:auto;font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;font-weight:400"><?= count($odj) ?> point<?= count($odj) > 1 ? 's' : '' ?></span>
            </div>
            <?php if (empty($odj)): ?>
            <div style="font-family:'DM Mono',monospace;font-size:11px;color:#9a9690;text-align:center;padding:16px 0">Aucun point à l'ordre du jour</div>
            <?php else: ?>
            <?php foreach ($odj as $i => $pt): ?>
            <div class="odj-row">
                <div class="odj-num"><?= $i+1 ?></div>
                <div class="odj-text">
                    <?= h($pt['intitule']) ?>
                    <?php
                    // Résolution associée à ce point
                    $res = array_filter($resolutions, fn($r) => $r['id_odj'] == $pt['id']);
                    foreach ($res as $rv):
                    ?>
                    <div style="margin-top:6px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <span class="vote-chip" style="background:#d8eee3;color:#2a6040">✓ <?= (int)$rv['vote_pour'] ?></span>
                        <span class="vote-chip" style="background:#fce8e8;color:#c84040">✗ <?= (int)$rv['vote_contre'] ?></span>
                        <span class="vote-chip" style="background:#e8e8e8;color:#808080">○ <?= (int)$rv['vote_abstention'] ?></span>
                        <span class="vote-chip" style="background:<?= $rv['adopte'] ? '#d8eee3' : '#fce8e8' ?>;color:<?= $rv['adopte'] ? '#2a6040' : '#c84040' ?>">
                            <?= $rv['adopte'] ? 'Adopté' : 'Rejeté' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <svg class="odj-done" viewBox="0 0 24 24" fill="none" stroke="<?= $pt['traite'] ? '#3a7a6a' : 'var(--shadow-dark,#d4d7de)' ?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Participants -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                Participants
                <span style="margin-left:auto;font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;font-weight:400"><?= count($participants) ?> invité<?= count($participants) > 1 ? 's' : '' ?></span>
            </div>
            <div style="margin-bottom:12px">
                <?php foreach ($participants as $p): ?>
                <span class="part-pill">
                    <?= h(trim($p['prenom'].' '.$p['nom'])) ?>
                    <?php if (!empty($p['email'])): ?><span style="font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;margin-left:4px"><?= h($p['email']) ?></span><?php endif; ?>
                    <?php if ($canEdit): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Retirer ce participant ?')">
                        <button class="part-del" name="supprimer_participant" value="<?= $p['id'] ?>">×</button>
                    </form>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
                <?php if (empty($participants)): ?><div style="font-family:'DM Mono',monospace;font-size:11px;color:#9a9690">Aucun participant</div><?php endif; ?>
            </div>
            <?php if ($canEdit && !empty($non_invites)): ?>
            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <select name="ajouts[]" multiple style="background:var(--bg-secondary,#eef1f6);border:none;border-radius:10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:6px 10px;font-family:'Sora',sans-serif;font-size:11px;color:#2c2a28;min-width:200px;height:70px">
                    <?php foreach ($non_invites as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= h(trim($u['prenom'].' '.$u['nom'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-xs primary" style="height:34px">Ajouter</button>
            </form>
            <?php endif; ?>
        </div>

    </div>
    <!-- /Colonne principale -->

    <!-- Colonne droite : actions -->
    <div style="display:flex;flex-direction:column;gap:14px">

        <!-- Statut + actions -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Actions
            </div>
            <div class="action-group">
                <?php if ($canEdit && $reunion['statut'] !== 'cloturee'): ?>
                <a href="agency_reunion_tenir.php?id=<?= $id ?>" class="act-btn green">
                    <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    Tenir la réunion
                </a>
                <a href="agency_reunion_form.php?id=<?= $id ?>" class="act-btn">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Modifier
                </a>
                <?php endif; ?>
                <!-- PDF Convocation -->
                <a href="agency_pdf_convocation.php?id=<?= $id ?>" target="_blank" class="act-btn">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    PDF Convocation
                </a>
                <!-- PDF Compte-rendu -->
                <?php if ($reunion['statut'] === 'cloturee'): ?>
                <a href="agency_pdf_cr.php?id=<?= $id ?>" target="_blank" class="act-btn">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="16 17 12 13 8 17"/><line x1="12" y1="13" x2="12" y2="21"/></svg>
                    PDF Compte-rendu
                </a>
                <?php endif; ?>
                <!-- Envoi mail convocation -->
                <?php if ($canEdit && $reunion['statut'] !== 'cloturee'): ?>
                <form method="POST">
                    <button type="submit" name="send_convocation" value="1" class="act-btn orange" style="width:100%" onclick="return confirm('Envoyer la convocation par e-mail aux <?= count($participants) ?> participant(s) ?')">
                        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Envoyer convocation
                    </button>
                </form>
                <?php endif; ?>
                <!-- Clôturer -->
                <?php if ($canEdit && $reunion['statut'] !== 'cloturee'): ?>
                <form method="POST" onsubmit="return confirm('Clôturer définitivement cette réunion ?')">
                    <button type="submit" name="cloturer" value="1" class="act-btn red" style="width:100%">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        Clôturer la réunion
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Infos immeuble (si lié) -->
        <?php if ($reunion['immeuble_nom']): ?>
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                Immeuble lié
            </div>
            <div style="font-weight:700;color:#1a1816;margin-bottom:4px"><?= h($reunion['immeuble_nom']) ?></div>
            <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin-bottom:8px"><?= h($reunion['immeuble_ref']) ?> · <?= (int)$reunion['nb_lots'] ?> lots</div>
            <?php if ($reunion['immeuble_adresse']): ?><div style="font-size:11px;color:#6a6864"><?= h($reunion['immeuble_adresse']) ?>, <?= h($reunion['immeuble_ville']) ?></div><?php endif; ?>
            <a href="agency_immeuble_fiche.php?id=<?= $reunion['id_immeuble'] ?>" style="display:inline-block;margin-top:8px;font-family:'DM Mono',monospace;font-size:10px;color:#4878a6;text-decoration:none">Fiche immeuble →</a>
        </div>
        <?php endif; ?>

    </div>
    <!-- /Colonne droite -->

</div>

<script>
const reunionId = <?= $id ?>;

function saveField(field, value) {
    fetch('agency_reunion_detail.php?id=' + reunionId, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'champ=' + encodeURIComponent(field) + '&valeur=' + encodeURIComponent(value)
    }).then(r => r.text()).then(t => {
        if (t === 'OK') showSaved();
    });
}

function showSaved() {
    const n = document.getElementById('save-notice');
    n.style.display = 'block';
    clearTimeout(n._t);
    n._t = setTimeout(() => n.style.display = 'none', 2500);
}
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
