<?php
declare(strict_types=1);
/**
 * document_requests_admin.php — Administration des demandes de documents.
 * Liste toutes les demandes (lien de dépôt sécurisé) avec 3 filtres efficaces :
 *   1. État (En cours / Terminé / Clôturé)   2. Agence   3. Recherche (titre/destinataire)
 * Actions par ligne : ouvrir/copier le lien, révoquer.
 *
 * Accès : admin / manager / super-admin. Non-super → limité à son agence.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/document_requests.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuper = in_array($roleId, [1, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!in_array($roleId, [1, 2, 7, 8], true) && !$isSuper) { http_response_code(403); exit('Accès réservé.'); }
$myAgence = (int)($_SESSION['id_agence'] ?? 0);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ── Actions POST (révoquer / relancer / supprimer / modifier) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== '') {
    if (function_exists('verify_csrf')) verify_csrf('dr_admin');
    $action = (string)$_POST['action'];
    $rid    = (int)($_POST['id'] ?? 0);
    $scope  = $isSuper ? '' : ' AND agence_id=' . $myAgence;
    // Vérifie l'appartenance au périmètre + récupère la demande
    $rq = null;
    if ($rid > 0) { $s = $pdo->prepare("SELECT * FROM document_requests WHERE id=?$scope"); $s->execute([$rid]); $rq = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    if ($rq) {
        try {
            if ($action === 'revoke') {
                $pdo->prepare("UPDATE document_requests SET status='revoque' WHERE id=?")->execute([$rid]);
            } elseif ($action === 'delete') {
                $pdo->prepare("DELETE FROM document_request_items WHERE request_id=?")->execute([$rid]);
                $pdo->prepare("DELETE FROM document_requests WHERE id=?")->execute([$rid]);
            } elseif ($action === 'relance') {
                $lien = function_exists('dr_public_url') ? dr_public_url((string)$rq['token']) : '';
                if (function_exists('send_mail') && filter_var($rq['recipient_email'], FILTER_VALIDATE_EMAIL) && $lien) {
                    $body = '<p>Bonjour,</p><p><strong>Rappel</strong> — il vous reste des documents à déposer pour : <strong>'
                          . $h($rq['titre']) . '</strong>.</p>'
                          . '<p><a href="' . $h($lien) . '" style="background:#0e7490;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:700">Déposer mes documents</a></p>'
                          . '<p style="color:#64748b;font-size:12px">Ou copiez ce lien : ' . $h($lien) . '</p>';
                    send_mail((string)$rq['recipient_email'], 'Rappel — ' . (string)$rq['titre'], $body, [], true);
                    $pdo->prepare("UPDATE document_requests SET last_reminded_at=NOW() WHERE id=?")->execute([$rid]);
                }
            } elseif ($action === 'update') {
                $t  = trim((string)($_POST['titre'] ?? ''));
                $em = trim((string)($_POST['recipient_email'] ?? ''));
                $nm = trim((string)($_POST['recipient_name'] ?? ''));
                $exp = trim((string)($_POST['expires_at'] ?? ''));
                $expSql = $exp !== '' ? date('Y-m-d 23:59:59', strtotime($exp)) : null;
                if ($t !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $pdo->prepare("UPDATE document_requests SET titre=?, recipient_email=?, recipient_name=?, expires_at=? WHERE id=?")
                        ->execute([$t, $em, ($nm ?: null), $expSql, $rid]);
                }
            }
        } catch (Throwable $e) {}
    }
    header('Location: ' . app_url('/document_requests_admin.php?' . ($_SERVER['QUERY_STRING'] ?: ''))); exit;
}

// ── Filtres ──────────────────────────────────────────────────────────────
$fEtat   = (string)($_GET['etat'] ?? 'en_cours');     // en_cours | termine | cloture | tous
$fAgence = (int)($_GET['agence'] ?? 0);
$fQ      = trim((string)($_GET['q'] ?? ''));
if (!$isSuper) $fAgence = $myAgence; // non-super : verrouillé sur son agence

$where = ['1=1']; $args = [];
switch ($fEtat) {
    case 'en_cours': $where[] = "dr.status IN ('en_attente','partiel') AND (dr.expires_at IS NULL OR dr.expires_at >= NOW())"; break;
    case 'termine':  $where[] = "dr.status = 'complet'"; break;
    case 'cloture':  $where[] = "(dr.status IN ('expire','revoque') OR (dr.expires_at IS NOT NULL AND dr.expires_at < NOW()))"; break;
}
if ($fAgence > 0) { $where[] = "dr.agence_id = ?"; $args[] = $fAgence; }
if ($fQ !== '')   { $where[] = "(dr.titre LIKE ? OR dr.recipient_email LIKE ? OR dr.recipient_name LIKE ?)"; $like = '%' . $fQ . '%'; array_push($args, $like, $like, $like); }
$whereSql = implode(' AND ', $where);

$rows = [];
try {
    $st = $pdo->prepare("
        SELECT dr.id, dr.token, dr.titre, dr.recipient_email, dr.recipient_name, dr.entity_type, dr.entity_id,
               dr.status, dr.agence_id, dr.expires_at, dr.created_at,
               (SELECT COUNT(*) FROM document_request_items i WHERE i.request_id = dr.id) nb_items,
               (SELECT COUNT(*) FROM document_request_items i WHERE i.request_id = dr.id AND i.status='recu') nb_recus,
               a.nom_agence,
               TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) createur
        FROM document_requests dr
        LEFT JOIN agences a ON a.id = dr.agence_id
        LEFT JOIN users u   ON u.id = dr.created_by
        WHERE $whereSql
        ORDER BY dr.created_at DESC
        LIMIT 500");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

// Pièces de chaque demande (pour le détail « chargées / manquantes »)
$itemsByReq = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    try {
        foreach ($pdo->query("SELECT request_id, label, status, entity_type FROM document_request_items WHERE request_id IN ($ids) ORDER BY sort_order, id") as $it) {
            $itemsByReq[(int)$it['request_id']][] = $it;
        }
    } catch (Throwable $e) {}
}

// KPIs (selon le périmètre agence courant, indépendants du filtre d'état)
$kpiWhere = $isSuper && $fAgence <= 0 ? '1=1' : 'agence_id = ' . (int)($fAgence ?: $myAgence);
$kpi = ['en_cours'=>0,'termine'=>0,'cloture'=>0];
try {
    foreach ($pdo->query("SELECT
        SUM(status IN ('en_attente','partiel') AND (expires_at IS NULL OR expires_at>=NOW())) en_cours,
        SUM(status='complet') termine,
        SUM(status IN ('expire','revoque') OR (expires_at IS NOT NULL AND expires_at<NOW())) cloture
        FROM document_requests WHERE $kpiWhere") as $k) { $kpi = ['en_cours'=>(int)$k['en_cours'],'termine'=>(int)$k['termine'],'cloture'=>(int)$k['cloture']]; }
} catch (Throwable $e) {}

$agences = $isSuper ? $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC) : [];
$csrf = function_exists('csrf_token') ? csrf_token('dr_admin') : '';
$STATUTS = ['en_attente'=>['En attente','#b7791f','#fef3d8'],'partiel'=>['Partiel','#0e7490','#d8f1f8'],
            'complet'=>['Terminé','#176a3a','#e7f6ec'],'expire'=>['Expiré','#6b7280','#eef1f5'],'revoque'=>['Révoqué','#a01818','#fdecec']];

$pageTitle = 'Demandes de documents';
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
.dra-wrap{max-width:1150px;margin:0 auto;padding:6px 4px 60px}
.dra-kpis{display:flex;gap:12px;margin:6px 0 16px;flex-wrap:wrap}
.dra-kpi{flex:1;min-width:150px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 16px}
.dra-kpi b{display:block;font-size:24px;color:#243B5C;line-height:1.1}
.dra-kpi span{font-size:12px;color:#7a8694;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.dra-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;margin-bottom:14px}
.dra-filters .f{display:flex;flex-direction:column;gap:4px}
.dra-filters label{font-size:11px;font-weight:700;color:#7a8694;text-transform:uppercase}
.dra-filters select,.dra-filters input{padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;font-family:inherit}
.dra-filters input[type=text]{min-width:240px}
.dra-btn{background:#0e7490;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none}
.dra-btn.ghost{background:#fff;color:#475569;border:1px solid #cbd5e1}
table.dra{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;font-size:13px}
table.dra th{background:#f8fafc;text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase;color:#64748b;border-bottom:1px solid #e5e7eb}
table.dra td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.dra-prog{height:6px;width:90px;background:#eef2f7;border-radius:4px;overflow:hidden;display:inline-block;vertical-align:middle;margin-right:6px}
.dra-prog>i{display:block;height:100%;background:#0e7490}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
.ent{font-size:11px;color:#64748b}
.muted{color:#94a3b8}
.lnk{color:#0e7490;font-weight:600;text-decoration:none;cursor:pointer}
</style>

<div class="dra-wrap">
  <div class="dra-kpis">
    <a class="dra-kpi" style="text-decoration:none" href="?etat=en_cours<?= $fAgence?'&agence='.$fAgence:'' ?>"><b><?= $kpi['en_cours'] ?></b><span>🟡 En cours</span></a>
    <a class="dra-kpi" style="text-decoration:none" href="?etat=termine<?= $fAgence?'&agence='.$fAgence:'' ?>"><b><?= $kpi['termine'] ?></b><span>✅ Terminées</span></a>
    <a class="dra-kpi" style="text-decoration:none" href="?etat=cloture<?= $fAgence?'&agence='.$fAgence:'' ?>"><b><?= $kpi['cloture'] ?></b><span>⏹️ Clôturées</span></a>
  </div>

  <form class="dra-filters" method="get">
    <div class="f"><label>État</label>
      <select name="etat" onchange="this.form.submit()">
        <option value="en_cours" <?= $fEtat==='en_cours'?'selected':'' ?>>🟡 En cours</option>
        <option value="termine"  <?= $fEtat==='termine' ?'selected':'' ?>>✅ Terminées</option>
        <option value="cloture"  <?= $fEtat==='cloture' ?'selected':'' ?>>⏹️ Clôturées</option>
        <option value="tous"     <?= $fEtat==='tous'    ?'selected':'' ?>>Toutes</option>
      </select>
    </div>
    <?php if ($isSuper): ?>
    <div class="f"><label>Agence</label>
      <select name="agence" onchange="this.form.submit()">
        <option value="0">Toutes les agences</option>
        <?php foreach ($agences as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $fAgence===(int)$a['id']?'selected':'' ?>><?= $h($a['nom_agence']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="f"><label>Recherche (titre / destinataire)</label>
      <input type="text" name="q" value="<?= $h($fQ) ?>" placeholder="email, nom, titre…">
    </div>
    <button class="dra-btn" type="submit">Filtrer</button>
    <a class="dra-btn ghost" href="<?= $h(app_url('/document_requests_admin.php')) ?>">Réinitialiser</a>
    <a class="dra-btn" style="margin-left:auto;background:#243B5C" href="<?= $h(app_url('/document_request_new.php')) ?>">➕ Nouvelle demande</a>
  </form>

  <table class="dra">
    <tr>
      <th>Demande</th><th>Destinataire</th><th>Rattachée à</th><th>Avancement</th><th>Statut</th>
      <?php if ($isSuper): ?><th>Agence</th><?php endif; ?>
      <th>Créée</th><th>Expire</th><th></th>
    </tr>
    <?php foreach ($rows as $r): $st = $STATUTS[$r['status']] ?? ['?','#64748b','#eef1f5'];
      $pct = $r['nb_items'] ? round($r['nb_recus']*100/$r['nb_items']) : 0;
      $lien = function_exists('dr_public_url') ? dr_public_url((string)$r['token']) : '';
      $expSoon = $r['expires_at'] && strtotime((string)$r['expires_at']) < time()+3*86400;
      $active = in_array($r['status'], ['en_attente','partiel'], true);
    ?>
    <tr>
      <td><strong><?= $h($r['titre']) ?></strong></td>
      <td><?= $h($r['recipient_name'] ?: '') ?><div class="ent"><?= $h($r['recipient_email']) ?></div></td>
      <td><?php if ($r['entity_type']): ?><span class="pill" style="background:#eef2ff;color:#3730a3"><?= $h($r['entity_type']) ?> #<?= (int)$r['entity_id'] ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><span class="dra-prog"><i style="width:<?= $pct ?>%"></i></span><?= (int)$r['nb_recus'] ?>/<?= (int)$r['nb_items'] ?></td>
      <td><span class="pill" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>"><?= $h($st[0]) ?></span></td>
      <?php if ($isSuper): ?><td class="ent"><?= $h($r['nom_agence'] ?: '—') ?></td><?php endif; ?>
      <td class="ent"><?= $r['created_at'] ? date('d/m/Y', strtotime((string)$r['created_at'])) : '' ?><?php if($r['createur']): ?><div><?= $h($r['createur']) ?></div><?php endif; ?></td>
      <td class="ent" style="<?= $expSoon?'color:#b91c1c;font-weight:700':'' ?>"><?= $r['expires_at'] ? date('d/m/Y', strtotime((string)$r['expires_at'])) : '—' ?></td>
      <td style="white-space:nowrap">
        <?php if ($lien): ?><a class="lnk" href="<?= $h($lien) ?>" target="_blank" title="Voir la page client de dépôt">👁️ Voir la page</a><?php endif; ?>
        <button class="lnk" style="background:none;border:none;padding:0 0 0 8px;cursor:pointer" onclick="draToggle(<?= (int)$r['id'] ?>)" title="Voir les pièces chargées / manquantes">📋 Détail</button>
        <button class="lnk" style="background:none;border:none;padding:0 0 0 8px;cursor:pointer" onclick='draEdit(<?= json_encode(["id"=>(int)$r["id"],"titre"=>$r["titre"],"email"=>$r["recipient_email"],"nom"=>$r["recipient_name"],"exp"=>$r["expires_at"]?date("Y-m-d",strtotime((string)$r["expires_at"])):""], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' title="Modifier la demande">✏️ Modifier</button>
        <?php if ($active): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Renvoyer le lien par email à <?= $h($r['recipient_email']) ?> ?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="relance"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="lnk" style="background:none;border:none;color:#0e7490;padding:0 0 0 8px;cursor:pointer" type="submit">🔔 Relancer</button>
          </form>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('SUPPRIMER définitivement cette demande et ses pièces ? Action irréversible.');">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="lnk" style="background:none;border:none;color:#b91c1c;padding:0 0 0 8px;cursor:pointer" type="submit">🗑️ Supprimer</button>
        </form>
      </td>
    </tr>
    <tr id="dra-det-<?= (int)$r['id'] ?>" style="display:none;background:#fafbfc">
      <td colspan="<?= $isSuper?9:8 ?>" style="padding:8px 14px">
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach (($itemsByReq[(int)$r['id']] ?? []) as $it): $ok = ($it['status']==='recu'); ?>
            <span class="pill" style="background:<?= $ok?'#e7f6ec':'#fdecec' ?>;color:<?= $ok?'#176a3a':'#a01818' ?>">
              <?= $ok?'✅':'⬜' ?> <?= $h($it['label']) ?><?php if($it['entity_type']): ?> <span style="opacity:.6">· <?= $h($it['entity_type']) ?></span><?php endif; ?>
            </span>
          <?php endforeach; ?>
          <?php if (empty($itemsByReq[(int)$r['id']])): ?><span class="muted">Aucune pièce.</span><?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $isSuper?9:8 ?>" class="muted" style="padding:24px;text-align:center">Aucune demande pour ces filtres.</td></tr><?php endif; ?>
  </table>
</div>

<!-- Modal modifier -->
<div id="dra-edit" style="display:none;position:fixed;inset:0;z-index:9500;align-items:center;justify-content:center;padding:20px">
  <div style="position:absolute;inset:0;background:rgba(15,23,42,.55)" onclick="document.getElementById('dra-edit').style.display='none'"></div>
  <form method="post" style="position:relative;background:#fff;border-radius:14px;width:min(480px,100%);padding:20px;box-shadow:0 24px 64px rgba(0,0,0,.3)">
    <h3 style="margin:0 0 14px;font-size:16px;color:#243B5C">✏️ Modifier la demande</h3>
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="dra-e-id">
    <label style="font-size:12px;font-weight:700;color:#475569">Titre</label>
    <input type="text" name="titre" id="dra-e-titre" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin:4px 0 12px">
    <label style="font-size:12px;font-weight:700;color:#475569">Email destinataire</label>
    <input type="email" name="recipient_email" id="dra-e-email" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin:4px 0 12px">
    <label style="font-size:12px;font-weight:700;color:#475569">Nom (optionnel)</label>
    <input type="text" name="recipient_name" id="dra-e-nom" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin:4px 0 12px">
    <label style="font-size:12px;font-weight:700;color:#475569">Expire le</label>
    <input type="date" name="expires_at" id="dra-e-exp" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin:4px 0 16px">
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button type="button" class="dra-btn ghost" onclick="document.getElementById('dra-edit').style.display='none'">Annuler</button>
      <button type="submit" class="dra-btn">Enregistrer</button>
    </div>
    <p class="muted" style="font-size:11px;margin:12px 0 0">Pour ajouter/retirer des pièces, créez une nouvelle demande (les pièces sont figées une fois envoyée).</p>
  </form>
</div>

<script>
function draToggle(id){ var r=document.getElementById('dra-det-'+id); if(r) r.style.display = (r.style.display==='none'?'':'none'); }
function draEdit(d){
  document.getElementById('dra-e-id').value=d.id;
  document.getElementById('dra-e-titre').value=d.titre||'';
  document.getElementById('dra-e-email').value=d.email||'';
  document.getElementById('dra-e-nom').value=d.nom||'';
  document.getElementById('dra-e-exp').value=d.exp||'';
  document.getElementById('dra-edit').style.display='flex';
}
</script>
