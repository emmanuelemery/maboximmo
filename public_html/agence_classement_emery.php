<?php
/**
 * agence_classement_emery.php — Page TEMPORAIRE de classement rapide des
 * propriétaires EMERY IMMO dans leur agence (RIOM / CHAMALIERES).
 *
 * Accès : par URL directe (à envoyer aux collègues), utilisateurs EMERY
 * (société 2) connectés, ou admin/super-admin. Scopée société 2 uniquement.
 *
 * Un clic sur « Riom » ou « Chamalières » classe le propriétaire ET tout son
 * portefeuille (tiers + immeubles + biens) dans l'agence choisie, sans recharger.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$pdo = $GLOBALS['pdo'];
$SOC = 2;                    // EMERY IMMOBILIER
$AG = [5 => 'RIOM', 6 => 'CHAMALIERES'];

// Garde-fou : société 2 OU admin/super-admin
$userSoc = (int)($_SESSION['id_societe'] ?? 0);
if ($userSoc !== $SOC && !is_admin_or_super_admin()) {
    http_response_code(403);
    exit('Accès réservé aux utilisateurs EMERY IMMO.');
}

// ── Action : classer un propriétaire ──────────────────────────────
if (($_POST['action'] ?? '') === 'set') {
    header('Content-Type: application/json; charset=utf-8');
    verify_csrf_any('agence_classe');
    $id = (int)($_POST['id'] ?? 0);
    $ag = (int)($_POST['ag'] ?? 0);
    if (!isset($AG[$ag]) || $id <= 0) { echo json_encode(['ok' => false, 'error' => 'paramètres invalides']); exit; }

    // Vérifie que le proprio est bien EMERY (agence 5/6) avant de toucher
    $chk = $pdo->prepare("SELECT id_tiers FROM proprietaires WHERE id=? AND id_agence IN (5,6)");
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'propriétaire hors périmètre EMERY']); exit; }

    $pdo->prepare("UPDATE proprietaires SET id_agence=? WHERE id=?")->execute([$ag, $id]);
    if (!empty($row['id_tiers'])) $pdo->prepare("UPDATE tiers SET id_agence=? WHERE id=?")->execute([$ag, (int)$row['id_tiers']]);
    $pdo->prepare("UPDATE immeubles SET id_agence=? WHERE id_proprietaire=?")->execute([$ag, $id]);
    $pdo->prepare("UPDATE biens SET id_agence=? WHERE id_proprietaire=?")->execute([$ag, $id]);

    echo json_encode(['ok' => true, 'ag' => $ag]);
    exit;
}

// ── Liste des propriétaires EMERY (société 2) ─────────────────────
$rows = $pdo->query("
    SELECT p.id, p.id_agence,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.civilite, p.nom, p.prenom)), ''), p.societe, CONCAT('Proprio #', p.id)) AS nom,
           (SELECT i.adresse_1 FROM immeubles i WHERE i.id_proprietaire = p.id ORDER BY i.id LIMIT 1) AS adresse,
           (SELECT i.ville     FROM immeubles i WHERE i.id_proprietaire = p.id ORDER BY i.id LIMIT 1) AS ville,
           (SELECT tb.libelle FROM biens b JOIN types_bien tb ON tb.id = b.id_type_bien
              WHERE b.id_proprietaire = p.id ORDER BY b.id LIMIT 1) AS type_bien
    FROM proprietaires p
    WHERE p.id_agence IN (5, 6)
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

$nbRiom = $nbCham = 0;
foreach ($rows as $r) { if ((int)$r['id_agence'] === 5) $nbRiom++; else $nbCham++; }
$token = csrf_token('agence_classe');
?>
<!doctype html>
<html lang="fr"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Classement agences — EMERY IMMO</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f4f5f7; color: #1f2430; }
  header { background: #36577d; color: #fff; padding: 16px 22px; position: sticky; top: 0; z-index: 10; }
  header h1 { margin: 0; font-size: 18px; }
  header .sub { font-size: 12px; opacity: .85; margin-top: 3px; }
  .bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 12px 22px; background: #fff; border-bottom: 1px solid #e6e8ec; position: sticky; top: 58px; z-index: 9; }
  .bar input { flex: 1; min-width: 220px; padding: 10px 14px; border: 1px solid #d4d7de; border-radius: 10px; font-size: 14px; }
  .pill { font-size: 12px; padding: 5px 11px; border-radius: 999px; background: #eef1f5; color: #4a5568; font-weight: 600; }
  .pill.r { background: #e8f0fe; color: #1a56c4; } .pill.c { background: #fff3e6; color: #b4530a; }
  table { width: 100%; border-collapse: collapse; background: #fff; }
  th, td { text-align: left; padding: 11px 14px; border-bottom: 1px solid #eef0f3; font-size: 14px; }
  th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #8a909c; position: sticky; top: 108px; background: #fff; }
  td.nom { font-weight: 600; }
  td.muted { color: #6b7280; }
  .btns { display: flex; gap: 6px; }
  .ag { padding: 7px 14px; border: 1.5px solid #d4d7de; background: #fff; border-radius: 9px; cursor: pointer; font-weight: 700; font-size: 13px; color: #6b7280; transition: all .12s; }
  .ag:hover { border-color: #9aa2af; }
  .ag.active-r { background: #1a56c4; border-color: #1a56c4; color: #fff; }
  .ag.active-c { background: #b4530a; border-color: #b4530a; color: #fff; }
  .ag:disabled { cursor: default; }
  tr.saving td { opacity: .5; }
  .empty { padding: 30px; text-align: center; color: #8a909c; }
  .count { font-size: 13px; color: #6b7280; }
</style>
</head><body>
<header>
  <h1>🏷️ Classement des propriétaires — EMERY IMMO</h1>
  <div class="sub">Clique sur <b>Riom</b> ou <b>Chamalières</b> pour classer chaque propriétaire (et tout son portefeuille). Enregistrement immédiat.</div>
</header>
<div class="bar">
  <input id="q" placeholder="🔎 Filtrer par nom, adresse, ville…" autocomplete="off">
  <span class="count"><b id="total"><?= count($rows) ?></b> propriétaires</span>
  <span class="pill r">Riom : <b id="cr"><?= $nbRiom ?></b></span>
  <span class="pill c">Chamalières : <b id="cc"><?= $nbCham ?></b></span>
</div>
<table>
  <thead><tr><th>Propriétaire</th><th>Adresse du bien</th><th>Ville</th><th>Type</th><th>Agence</th></tr></thead>
  <tbody id="tb">
  <?php foreach ($rows as $r): $cur = (int)$r['id_agence']; ?>
    <tr data-search="<?= h(mb_strtolower($r['nom'].' '.$r['adresse'].' '.$r['ville'])) ?>" data-id="<?= (int)$r['id'] ?>">
      <td class="nom"><?= h($r['nom']) ?></td>
      <td class="<?= $r['adresse'] ? '' : 'muted' ?>"><?= h($r['adresse'] ?: '—') ?></td>
      <td class="<?= $r['ville'] ? '' : 'muted' ?>"><?= h($r['ville'] ?: '—') ?></td>
      <td class="muted"><?= h($r['type_bien'] ?: '—') ?></td>
      <td>
        <div class="btns">
          <button class="ag <?= $cur === 5 ? 'active-r' : '' ?>" data-ag="5">Riom</button>
          <button class="ag <?= $cur === 6 ? 'active-c' : '' ?>" data-ag="6">Chamalières</button>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$rows): ?><div class="empty">Aucun propriétaire EMERY à classer.</div><?php endif; ?>

<script>
const TOKEN = <?= json_encode($token) ?>;
const q = document.getElementById('q'), tb = document.getElementById('tb');
const crEl = document.getElementById('cr'), ccEl = document.getElementById('cc');

// Filtre instantané
q.addEventListener('input', () => {
  const v = q.value.trim().toLowerCase();
  let n = 0;
  tb.querySelectorAll('tr').forEach(tr => {
    const ok = !v || tr.dataset.search.includes(v);
    tr.style.display = ok ? '' : 'none';
    if (ok) n++;
  });
  document.getElementById('total').textContent = n;
});

// Recompte les pills
function recount() {
  let r = 0, c = 0;
  tb.querySelectorAll('tr').forEach(tr => {
    if (tr.querySelector('.active-r')) r++; else if (tr.querySelector('.active-c')) c++;
  });
  crEl.textContent = r; ccEl.textContent = c;
}

// Clic agence
tb.addEventListener('click', async (e) => {
  const btn = e.target.closest('.ag');
  if (!btn) return;
  const tr = btn.closest('tr');
  const id = tr.dataset.id, ag = btn.dataset.ag;
  if ((ag === '5' && btn.classList.contains('active-r')) || (ag === '6' && btn.classList.contains('active-c'))) return;
  tr.classList.add('saving');
  try {
    const fd = new FormData();
    fd.append('action', 'set'); fd.append('id', id); fd.append('ag', ag); fd.append('csrf_token', TOKEN);
    const res = await fetch('agence_classement_emery.php', { method: 'POST', body: fd });
    const j = await res.json();
    if (j.ok) {
      tr.querySelectorAll('.ag').forEach(b => b.classList.remove('active-r', 'active-c'));
      btn.classList.add(ag === '5' ? 'active-r' : 'active-c');
      recount();
    } else { alert('Erreur : ' + (j.error || '?')); }
  } catch (err) { alert('Erreur réseau'); }
  tr.classList.remove('saving');
});
</script>
</body></html>
