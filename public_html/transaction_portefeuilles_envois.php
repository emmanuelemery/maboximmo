<?php
// transaction_portefeuilles_envois.php — Admin des liens envoyés (pages p.php).
// Révocation/réactivation des accès + suivi enrichi des consultations.
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();
if (!function_exists('h')) { function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

// Périmètre : super admin = tous les envois ; sinon, ses propres envois.
$isSuper = function_exists('is_admin_or_super_admin') && is_admin_or_super_admin();
$uid     = function_exists('current_user_id') ? (int)current_user_id() : 0;

$flash = ['ok' => '', 'err' => ''];

// ── Actions : révoquer / réactiver ───────────────────────────────────
if (is_post()) {
    verify_csrf('pf_envois');
    $envoiId = (int)post('envoi_id', 0);
    $action  = (string)post('action', '');
    // Vérif périmètre
    $own = $pdo->prepare("SELECT id_user FROM portefeuille_envois WHERE id = ? LIMIT 1");
    $own->execute([$envoiId]);
    $envUser = $own->fetchColumn();
    if ($envUser === false) {
        $flash['err'] = "Envoi introuvable.";
    } elseif (!$isSuper && (int)$envUser !== $uid) {
        $flash['err'] = "Hors de votre périmètre.";
    } else {
        $newActif = $action === 'reactiver' ? 1 : 0;
        $pdo->prepare("UPDATE portefeuille_envois SET actif = ? WHERE id = ?")->execute([$newActif, $envoiId]);
        $flash['ok'] = $newActif ? "Accès réactivé." : "Accès révoqué.";
    }
}

// ── Détail (timeline d'un envoi) ─────────────────────────────────────
$detailId = (int)($_GET['detail'] ?? 0);
$detail = null; $events = []; $schemaWarn = '';
if ($detailId > 0) {
    $d = $pdo->prepare("SELECT pe.*, p.nom AS pf_nom FROM portefeuille_envois pe
                        LEFT JOIN portefeuilles p ON p.id = pe.id_portefeuille WHERE pe.id = ? LIMIT 1");
    $d->execute([$detailId]);
    $detail = $d->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detail && !$isSuper && (int)$detail['id_user'] !== $uid) { $detail = null; }
    if ($detail) {
        try {
            $ev = $pdo->prepare("SELECT c.type, c.id_bien, c.doc_label, c.ip, c.created_at, b.reference_bien
                                 FROM portefeuille_consultation c
                                 LEFT JOIN biens b ON b.id = c.id_bien
                                 WHERE c.id_envoi = ? ORDER BY c.created_at DESC LIMIT 300");
            $ev->execute([$detailId]);
            $events = $ev->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $schemaWarn = "Table 'portefeuille_consultation' absente : appliquez la migration pour l'historique détaillé.";
        }
    }
}

// ── Liste des envois (+ compteurs) ───────────────────────────────────
$rows = [];
$hasStats = true;
$baseSelect = "SELECT pe.id, pe.token, pe.email_destinataire, pe.destinataire_nom, pe.type_destinataire,
                      pe.date_envoi, pe.date_expiration, pe.actif, pe.nb_biens,
                      pe.date_premiere_consultation, pe.date_derniere_consultation,
                      p.nom AS pf_nom, pe.id_portefeuille";
$statSelect = ",
  (SELECT COUNT(*) FROM portefeuille_consultation c WHERE c.id_envoi=pe.id AND c.type='open') AS nb_open,
  (SELECT COUNT(*) FROM portefeuille_consultation c WHERE c.id_envoi=pe.id AND c.type='download') AS nb_dl,
  (SELECT COUNT(DISTINCT c.id_bien) FROM portefeuille_consultation c WHERE c.id_envoi=pe.id AND c.type='view_bien') AS nb_vb";
$from = " FROM portefeuille_envois pe LEFT JOIN portefeuilles p ON p.id = pe.id_portefeuille";
$where = $isSuper ? "" : " WHERE pe.id_user = " . (int)$uid;
$order = " ORDER BY pe.date_envoi DESC LIMIT 500";
try {
    $rows = $pdo->query($baseSelect . $statSelect . $from . $where . $order)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $hasStats = false;
    $rows = $pdo->query($baseSelect . $from . $where . $order)->fetchAll(PDO::FETCH_ASSOC);
    if ($schemaWarn === '') $schemaWarn = "Table 'portefeuille_consultation' absente : appliquez la migration pour les compteurs.";
}

$statut = function (array $r): array {
    if ((int)$r['actif'] !== 1) return ['Révoqué', '#b3261e', '#fdeaea'];
    if (!empty($r['date_expiration']) && strtotime((string)$r['date_expiration']) < time()) return ['Expiré', '#8a6d00', '#fbf3d6'];
    return ['Actif', '#1f7a44', '#e7f6ec'];
};
$fdate = fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '—';

$pageTitle    = 'Liens envoyés & accès';
$pageSubtitle = 'Ma Box Agency · Portefeuilles';
$bodyAttr     = 'data-theme-module="transaction"';
$extraCss = <<<'CSS'
<style>
.pe-page{max-width:1180px}
.pe-hero{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.pe-hero-ic{width:50px;height:50px;border-radius:15px;display:grid;place-items:center;font-size:23px;background:linear-gradient(135deg,#4878a6,#6b4aa0);color:#fff;flex:none}
.pe-hero h1{margin:0;font-size:21px;font-weight:800;color:#2c2a28}
.pe-hero p{margin:2px 0 0;font-size:13px;color:#7a766f}
.pe-alert{padding:11px 15px;border-radius:10px;margin-bottom:14px;font-size:13.5px}
.pe-ok{background:#e7f6ec;color:#1f7a44}.pe-err{background:#fdeaea;color:#b3261e}.pe-warn{background:#fbf3d6;color:#8a6d00}
.pe-table{width:100%;border-collapse:separate;border-spacing:0 8px;font-size:13.5px}
.pe-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#a8a39a;padding:0 12px}
.pe-table td{background:#fff;padding:12px;vertical-align:middle}
.pe-table tr td:first-child{border-radius:11px 0 0 11px}
.pe-table tr td:last-child{border-radius:0 11px 11px 0}
.pe-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700}
.pe-name{font-weight:700;color:#2c2a28}
.pe-sub{font-size:12px;color:#7a766f}
.pe-stat{display:inline-flex;gap:5px;align-items:center;margin-right:10px;font-weight:600;color:#4878a6}
.pe-btn{border-radius:9px;padding:7px 12px;border:1px solid #ece7df;background:#fff;color:#2c2a28;cursor:pointer;font-size:12.5px;font-weight:600;text-decoration:none;display:inline-block}
.pe-btn:hover{background:#f4f1ec}
.pe-btn-danger{border-color:#f3c0bb;color:#b3261e}
.pe-btn-ok{border-color:#bfe6cc;color:#1f7a44}
.pe-link{font-size:12px;color:#4878a6;cursor:pointer}
.pe-tl{background:#fff;border-radius:14px;padding:18px;box-shadow:0 6px 20px rgba(44,42,40,.06)}
.pe-ev{display:flex;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid #f1efe9;font-size:13px}
.pe-ev .ic{width:30px;height:30px;border-radius:8px;display:grid;place-items:center;flex:none}
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
?>
<div class="pe-page">
  <div class="pe-hero">
    <div class="pe-hero-ic">🔗</div>
    <div>
      <h1>Liens envoyés & accès</h1>
      <p>Suivez les consultations et révoquez un accès à tout moment.<?= $isSuper ? '' : ' (vos envois)' ?></p>
    </div>
  </div>

  <?php if ($flash['ok']): ?><div class="pe-alert pe-ok">✅ <?= h($flash['ok']) ?></div><?php endif; ?>
  <?php if ($flash['err']): ?><div class="pe-alert pe-err"><?= h($flash['err']) ?></div><?php endif; ?>
  <?php if ($schemaWarn): ?><div class="pe-alert pe-warn">⚠️ <?= h($schemaWarn) ?></div><?php endif; ?>

  <?php if ($detail): $stt = $statut($detail); ?>
    <!-- ===== Détail / timeline ===== -->
    <div style="margin-bottom:14px"><a class="pe-btn" href="<?= h(app_url('/transaction_portefeuilles_envois.php')) ?>">← Retour à la liste</a></div>
    <div class="pe-tl">
      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
        <div>
          <div class="pe-name"><?= h((string)($detail['pf_nom'] ?: 'Portefeuille')) ?></div>
          <div class="pe-sub"><?= h((string)$detail['email_destinataire']) ?> · envoyé le <?= $fdate($detail['date_envoi']) ?></div>
        </div>
        <span class="pe-badge" style="background:<?= $stt[2] ?>;color:<?= $stt[1] ?>"><?= $stt[0] ?></span>
      </div>
      <?php if (!$events): ?>
        <p class="pe-sub">Aucune consultation enregistrée pour l'instant.</p>
      <?php else: ?>
        <?php foreach ($events as $e):
          $map = ['open'=>['👁','#e3eef7'],'view_bien'=>['🏠','#e7f6ec'],'download'=>['📄','#fbf3d6']];
          [$ic,$bgc] = $map[$e['type']] ?? ['•','#eee'];
          $lbl = $e['type']==='open' ? 'Ouverture de l\'espace'
               : ($e['type']==='view_bien' ? 'Bien consulté'.($e['reference_bien']?' · réf '.$e['reference_bien']:'')
               : 'Document téléchargé'.($e['doc_label']?' · '.$e['doc_label']:''));
        ?>
          <div class="pe-ev">
            <div class="ic" style="background:<?= $bgc ?>"><?= $ic ?></div>
            <div style="flex:1"><?= h($lbl) ?></div>
            <div class="pe-sub"><?= $fdate($e['created_at']) ?><?= $e['ip']?' · '.h((string)$e['ip']):'' ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <!-- ===== Liste ===== -->
    <?php if (!$rows): ?>
      <div class="pe-alert pe-warn">Aucun lien envoyé pour l'instant.</div>
    <?php else: ?>
    <table class="pe-table">
      <thead><tr>
        <th>Portefeuille / destinataire</th><th>Envoyé</th><th>Expiration</th>
        <th>Consultations</th><th>Statut</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $stt = $statut($r);
        $shareUrl = app_url('/p.php?t=' . (string)$r['token']);
        $jours = !empty($r['date_expiration']) ? (int)ceil((strtotime((string)$r['date_expiration']) - time())/86400) : null;
      ?>
        <tr>
          <td>
            <div class="pe-name"><?= h((string)($r['pf_nom'] ?: 'Portefeuille')) ?></div>
            <div class="pe-sub"><?= h((string)$r['email_destinataire']) ?><?= $r['type_destinataire']?' · '.h((string)$r['type_destinataire']):'' ?> · <?= (int)$r['nb_biens'] ?> bien(s)</div>
          </td>
          <td class="pe-sub"><?= $fdate($r['date_envoi']) ?></td>
          <td class="pe-sub"><?= empty($r['date_expiration']) ? 'Aucune' : ($fdate($r['date_expiration']) . ($jours!==null && $jours>=0 ? ' ('.$jours.'j)' : '')) ?></td>
          <td>
            <?php if ($hasStats): ?>
              <span class="pe-stat">👁 <?= (int)($r['nb_open'] ?? 0) ?></span>
              <span class="pe-stat">🏠 <?= (int)($r['nb_vb'] ?? 0) ?></span>
              <span class="pe-stat">📄 <?= (int)($r['nb_dl'] ?? 0) ?></span>
            <?php else: ?>
              <span class="pe-sub"><?= empty($r['date_premiere_consultation']) ? 'Jamais ouvert' : ('Vu le '.$fdate($r['date_derniere_consultation'])) ?></span>
            <?php endif; ?>
            <div><a class="pe-link" href="<?= h(app_url('/transaction_portefeuilles_envois.php?detail=' . (int)$r['id'])) ?>">Voir le détail →</a></div>
          </td>
          <td><span class="pe-badge" style="background:<?= $stt[2] ?>;color:<?= $stt[1] ?>"><?= $stt[0] ?></span></td>
          <td style="white-space:nowrap;text-align:right">
            <button type="button" class="pe-btn" onclick="navigator.clipboard.writeText(location.origin+<?= json_encode($shareUrl) ?>);this.textContent='Copié ✓'">Copier le lien</button>
            <a class="pe-btn" target="_blank" href="<?= h($shareUrl) ?>">Ouvrir</a>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= (int)$r['actif']===1 ? 'Révoquer cet accès ? Le lien ne fonctionnera plus.' : 'Réactiver cet accès ?' ?>')">
              <?= csrf_field('pf_envois') ?>
              <input type="hidden" name="envoi_id" value="<?= (int)$r['id'] ?>">
              <?php if ((int)$r['actif'] === 1): ?>
                <input type="hidden" name="action" value="revoquer">
                <button type="submit" class="pe-btn pe-btn-danger">Révoquer</button>
              <?php else: ?>
                <input type="hidden" name="action" value="reactiver">
                <button type="submit" class="pe-btn pe-btn-ok">Réactiver</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
