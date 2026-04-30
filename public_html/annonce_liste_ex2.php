<?php
/**
 * annonce_liste.php
 * ─────────────────
 * Liste des annonces (équivalent de bien_liste.php mais côté annonce).
 * Filtres : recherche, type transaction, agence, état publication, visible portails.
 * Voyants Ubiflow clickables (vers bien_detail section annonce).
 * Admin : bouton supprimer par ligne.
 *
 * Respecte le scope société/agence selon rôle (multi-tenant).
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$userId    = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$isSuperAdmin = ($roleId === 1);
$isAdmin      = in_array($roleId, [1, 2, 3], true); // SA / admin société / admin agence

function ae($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ─── Filtres GET ────────────────────────────────────────────────
$q         = trim((string)($_GET['q']          ?? ''));
$fTrans    = trim((string)($_GET['transaction']?? ''));
$fAgence   = (int)($_GET['id_agence']          ?? 0);
$fEtat     = trim((string)($_GET['etat']       ?? '')); // brouillon / diffusee / archivee / all
$fPortails = (int)($_GET['portails']           ?? 0);   // 1 = visible_portails = 1

$where = ['1=1'];
$params = [];

// Scope multi-tenant
if (!$isSuperAdmin) {
    if ($societeId > 0) {
        $where[] = 'a.id_societe = :session_soc';
        $params[':session_soc'] = $societeId;
    }
}

if ($q !== '') {
    $where[] = '(a.titre LIKE :q OR a.reference_annonce LIKE :q OR b.reference_bien LIKE :q OR b.ville LIKE :q OR i.ville LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($fTrans !== '') {
    $where[] = 'a.type_transaction = :trans';
    $params[':trans'] = $fTrans;
}
if ($fAgence > 0) {
    $where[] = 'a.id_agence = :ag';
    $params[':ag'] = $fAgence;
}
if ($fEtat !== '' && $fEtat !== 'all') {
    if ($fEtat === 'brouillon') {
        $where[] = "(a.etat_publication = 'brouillon' OR a.statut = 'brouillon')";
    } elseif ($fEtat === 'diffusee') {
        // ⚠️ parenthèses OBLIGATOIRES sinon le OR casse le scope des autres filtres AND
        // (ex: filtre agence ignoré car OR statut IN (...) matche toute annonce publiée)
        $where[] = "(a.etat_publication IN ('diffusee','publiee') OR a.statut IN ('publiee','active','en_ligne'))";
    } elseif ($fEtat === 'archivee') {
        $where[] = "a.etat_publication IN ('archivee','archived')";
    }
}
if ($fPortails === 1) {
    $where[] = 'a.visible_portails = 1';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// ─── Fetch annonces ─────────────────────────────────────────────
$sql = "
    SELECT
        a.id                AS annonce_id,
        a.id_bien,
        a.id_agence,
        a.id_user,
        a.id_societe,
        a.reference_annonce,
        a.titre,
        a.description,
        a.type_transaction,
        a.statut,
        a.etat_publication,
        a.visible_portails,
        a.visible_site,
        a.prix,
        a.loyer,
        a.loyer_cc,
        a.loyer_reference_majore,
        a.complement_loyer,
        a.honoraires_location_bail,
        a.honoraires_etat_des_lieux,
        a.date_creation,
        a.date_modification,
        a.date_mise_en_ligne,
        b.reference_bien,
        b.designation,
        b.surface_habitable,
        b.surface_totale,
        b.nb_pieces,
        b.nb_chambres,
        b.dpe_classe,
        b.ges_classe,
        b.dpe_vierge,
        b.id_proprietaire,
        b.charges_locatives,
        COALESCE(i.adresse_1,   b.adresse_1)   AS adresse_1,
        COALESCE(i.code_postal, b.code_postal) AS code_postal,
        COALESCE(i.ville,       b.ville)       AS ville,
        COALESCE(bt.code,    tbl.code)    AS type_code,
        COALESCE(bt.libelle, tbl.libelle) AS type_libelle,
        ag.nom_agence,
        s.nom                   AS societe_nom,
        CONCAT(u.prenom, ' ', u.nom) AS commercial_nom,
        (SELECT COUNT(*) FROM biens_photos bp WHERE bp.id_bien = b.id) AS nb_photos,
        (SELECT bp.url_photo FROM biens_photos bp WHERE bp.id_bien = b.id ORDER BY bp.ordre ASC, bp.id ASC LIMIT 1) AS photo_url
    FROM annonces a
    JOIN biens b        ON b.id  = a.id_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    -- Migration 20260430_bien_types : types_bien renommée en types_bien_legacy.
    -- Source de vérité = bien_types (via biens.id_bien_type), fallback legacy.
    LEFT JOIN bien_types        bt  ON bt.id  = b.id_bien_type
    LEFT JOIN types_bien_legacy tbl ON tbl.id = b.id_type_bien
    LEFT JOIN agences  ag ON ag.id = a.id_agence
    LEFT JOIN societes s  ON s.id  = a.id_societe
    LEFT JOIN users    u  ON u.id  = a.id_user
    {$whereClause}
    ORDER BY a.date_modification DESC, a.id DESC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Listes pour selects
$agencesOpts = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);

// Helper voyants Ubiflow sur annonce (adapté de bien_liste)
$ubiflowChecks = static function(array $a): array {
    $tc = strtolower((string)($a['type_code'] ?? ''));
    $exempt = in_array($tc, ['parking','stationnement','garage','box','terrain','terrain_agricole'], true);
    $trans = (string)($a['type_transaction'] ?? '');
    $checks = [];
    $checks[] = ['key'=>'titre','label'=>'Titre','ok'=>!empty($a['titre']),'severity'=>'critical','section'=>'annonce','focus'=>'titre','ic'=>'📝'];
    $checks[] = ['key'=>'description','label'=>'Description','ok'=>!empty($a['description']),'severity'=>'critical','section'=>'annonce','focus'=>'description','ic'=>'📄'];
    $checks[] = ['key'=>'transaction','label'=>'Type transaction','ok'=>$trans !== '','severity'=>'critical','section'=>'annonce','focus'=>'type_transaction','ic'=>'💼'];
    $prixOk = true; $pf = 'prix';
    if ($trans === 'vente') $prixOk = (float)($a['prix'] ?? 0) > 0;
    elseif (in_array($trans, ['location','saisonnier'], true)) { $prixOk = (float)($a['loyer'] ?? 0) > 0; $pf = 'loyer'; }
    $checks[] = ['key'=>'prix','label'=>($trans==='vente'?'Prix':'Loyer'),'ok'=>$prixOk,'severity'=>'critical','section'=>'annonce','focus'=>$pf,'ic'=>'💰'];
    $checks[] = ['key'=>'photos','label'=>'Photos','ok'=>(int)($a['nb_photos'] ?? 0) >= 1,'severity'=>'critical','section'=>'documents','focus'=>'','ic'=>'📸'];
    $adrOk = !empty($a['adresse_1']) && !empty($a['code_postal']) && !empty($a['ville']);
    $checks[] = ['key'=>'adresse','label'=>'Adresse','ok'=>$adrOk,'severity'=>'critical','section'=>'descriptif','focus'=>'adresse_1','ic'=>'📍'];
    $checks[] = ['key'=>'proprio','label'=>'Propriétaire','ok'=>(int)($a['id_proprietaire'] ?? 0) > 0,'severity'=>'critical','section'=>'descriptif','focus'=>'proprio','ic'=>'👤'];
    if (!$exempt) {
        $checks[] = ['key'=>'surface','label'=>'Surface','ok'=>(float)($a['surface_habitable'] ?? 0) > 0 || (float)($a['surface_totale'] ?? 0) > 0,'severity'=>'warning','section'=>'descriptif','focus'=>'surface_habitable','ic'=>'📐'];
        $checks[] = ['key'=>'dpe','label'=>'DPE','ok'=>!empty($a['dpe_classe']) || (int)($a['dpe_vierge'] ?? 0) === 1,'severity'=>'warning','section'=>'dpe','focus'=>'dpe_classe','ic'=>'⚡'];
    }
    return $checks;
};

// ── KPIs en haut ─────────────────────────────────────────────────
$totalAnnonces = count($rows);
$nbDiffusees   = array_sum(array_map(fn($r) =>
    (in_array($r['etat_publication'], ['diffusee','publiee'], true) || in_array($r['statut'], ['publiee','active','en_ligne'], true)) ? 1 : 0, $rows));
$nbBrouillon   = array_sum(array_map(fn($r) =>
    ($r['etat_publication'] === 'brouillon' || $r['statut'] === 'brouillon') ? 1 : 0, $rows));
$nbPortails    = array_sum(array_map(fn($r) => (int)($r['visible_portails'] ?? 0) === 1 ? 1 : 0, $rows));

$pageTitle    = 'Mes annonces';
$pageSubtitle = 'Ma Box Agency · Annonces · ' . $totalAnnonces . ' résultat(s)';
require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<style>
  .al-wrap { max-width: 1280px; margin: 0 auto; padding: 0 4px; }
  .al-topkpi { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap:10px; margin-bottom:18px; }
  .al-kpi { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; text-align:center; }
  .al-kpi-nb { font-size:22px; font-weight:800; color:#0f172a; }
  .al-kpi-lbl { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
  .al-filters { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:14px;
                display:grid; grid-template-columns: 2fr 1fr 1.2fr 1fr auto auto; gap:8px; align-items:center; }
  .al-filters input, .al-filters select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; }
  .al-btn { padding:8px 14px; border-radius:8px; border:1px solid #0ea5e9; background:#0ea5e9; color:#fff; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; }
  .al-btn-ghost { background:#fff; color:#64748b; border-color:#cbd5e1; }

  .al-row { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px 10px 10px;
            display:grid; grid-template-columns: 72px 1fr auto auto auto auto auto; gap:12px; align-items:center; margin-bottom:8px; }
  .al-thumb { width:72px; height:54px; border-radius:6px; overflow:hidden; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:20px; }
  .al-thumb img { width:100%; height:100%; object-fit:cover; }
  .al-ref { font-family:monospace; font-size:10px; color:#64748b; }
  .al-title { font-size:14px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:420px; }
  .al-sub { font-size:11px; color:#64748b; display:flex; gap:10px; flex-wrap:wrap; margin-top:3px; }
  .al-badge { padding:2px 8px; border-radius:99px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  .al-b-vente { background:#dbeafe; color:#1e40af; }
  .al-b-loc   { background:#dcfce7; color:#166534; }
  .al-b-saison{ background:#fef3c7; color:#78350f; }
  .al-b-viager{ background:#e9d5ff; color:#6b21a8; }
  .al-b-brouillon { background:#fef3c7; color:#78350f; }
  .al-b-diffusee  { background:#dcfce7; color:#166534; }
  .al-b-archivee  { background:#f3f4f6; color:#64748b; }
  .al-price { font-size:14px; font-weight:800; color:#0f172a; white-space:nowrap; }
  .al-voyants { display:flex; gap:3px; padding:3px 6px; background:rgba(15,23,42,.03); border-radius:99px; }
  .al-v-dot { display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; font-size:11px; border-radius:50%; text-decoration:none; }
  .al-v-ok   { background:#dcfce7; }
  .al-v-warn { background:#fef3c7; box-shadow:0 0 0 1px #f59e0b inset; }
  .al-v-crit { background:#fee2e2; box-shadow:0 0 0 2px #dc2626 inset; animation: al-pulse 1.5s ease-in-out infinite; }
  @keyframes al-pulse { 0%,100%{box-shadow:0 0 0 2px #dc2626 inset, 0 0 0 0 rgba(220,38,38,.4);} 50%{box-shadow:0 0 0 2px #dc2626 inset, 0 0 0 6px rgba(220,38,38,0);} }
  .al-actions { display:flex; gap:4px; }
  .al-act { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:#f1f5f9; color:#475569; border:none; cursor:pointer; text-decoration:none; font-size:14px; }
  .al-act:hover { background:#e2e8f0; }
  .al-act-del { background:#fee2e2; color:#991b1b; }
  .al-act-del:hover { background:#fecaca; }
  .al-empty { background:#f8fafc; border:2px dashed #cbd5e1; padding:40px; border-radius:12px; text-align:center; color:#64748b; margin-top:20px; }
</style>

<div class="al-wrap">

  <!-- KPIs -->
  <div class="al-topkpi">
    <div class="al-kpi"><div class="al-kpi-nb"><?= $totalAnnonces ?></div><div class="al-kpi-lbl">Total filtré</div></div>
    <div class="al-kpi"><div class="al-kpi-nb" style="color:#166534;"><?= $nbDiffusees ?></div><div class="al-kpi-lbl">Diffusées</div></div>
    <div class="al-kpi"><div class="al-kpi-nb" style="color:#78350f;"><?= $nbBrouillon ?></div><div class="al-kpi-lbl">Brouillon</div></div>
    <div class="al-kpi"><div class="al-kpi-nb" style="color:#0369a1;"><?= $nbPortails ?></div><div class="al-kpi-lbl">Visible portails</div></div>
  </div>

  <!-- Filtres — submit automatique au changement -->
  <form method="get" class="al-filters" id="al-form">
    <input type="text" name="q" placeholder="🔎 Recherche titre, réf, ville…" value="<?= ae($q) ?>" id="al-q">
    <select name="transaction" onchange="this.form.submit()">
      <option value="">Toutes transactions</option>
      <?php foreach (['vente'=>'Vente','location'=>'Location','saisonnier'=>'Saisonnier','viager'=>'Viager'] as $k=>$lbl): ?>
        <option value="<?= ae($k) ?>" <?= $fTrans === $k ? 'selected' : '' ?>><?= ae($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="id_agence" onchange="this.form.submit()">
      <option value="0">Toutes agences</option>
      <?php foreach ($agencesOpts as $ao): ?>
        <option value="<?= (int)$ao['id'] ?>" <?= $fAgence === (int)$ao['id'] ? 'selected' : '' ?>><?= ae($ao['nom_agence']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="etat" onchange="this.form.submit()">
      <option value="all"       <?= $fEtat==='' || $fEtat==='all' ? 'selected' : '' ?>>Tous états</option>
      <option value="brouillon" <?= $fEtat==='brouillon' ? 'selected' : '' ?>>Brouillon</option>
      <option value="diffusee"  <?= $fEtat==='diffusee'  ? 'selected' : '' ?>>Diffusée / active</option>
      <option value="archivee"  <?= $fEtat==='archivee'  ? 'selected' : '' ?>>Archivée</option>
    </select>
    <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569;">
      <input type="checkbox" name="portails" value="1" <?= $fPortails===1 ? 'checked' : '' ?> onchange="this.form.submit()">
      Portails=1
    </label>
    <a href="annonce_liste.php" class="al-btn al-btn-ghost" title="Réinitialiser tous les filtres">✕ Reset</a>
  </form>
  <script>
    // Submit automatique sur la recherche texte (debounce 400 ms) + sur Entrée
    (function() {
      const q = document.getElementById('al-q');
      const form = document.getElementById('al-form');
      if (!q || !form) return;
      let t = null;
      q.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => form.submit(), 400);
      });
      q.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); form.submit(); }
      });
    })();
  </script>

  <!-- Tableau -->
  <?php if (empty($rows)): ?>
    <div class="al-empty">
      <div style="font-size:40px; margin-bottom:12px;">📡</div>
      Aucune annonce ne correspond à ces critères.
    </div>
  <?php else: foreach ($rows as $a):
    $trans = (string)($a['type_transaction'] ?? '');
    $transBadge = [
      'vente'=>['Vente','al-b-vente'], 'location'=>['Location','al-b-loc'],
      'saisonnier'=>['Saison.','al-b-saison'], 'viager'=>['Viager','al-b-viager'],
    ][$trans] ?? [ucfirst($trans), 'al-b-loc'];
    $ep = (string)($a['etat_publication'] ?? 'brouillon');
    $epBadge = match($ep) {
      'diffusee','publiee' => ['🟢 Diffusée','al-b-diffusee'],
      'archivee','archived'=> ['📦 Archivée','al-b-archivee'],
      default              => ['📝 Brouillon','al-b-brouillon'],
    };
    $photoUrl = !empty($a['photo_url']) ? app_url('/' . ltrim((string)$a['photo_url'], '/')) : '';
    // Prix à afficher
    $priceStr = '';
    if ($trans === 'vente' && (float)($a['prix'] ?? 0) > 0) {
      $priceStr = number_format((float)$a['prix'], 0, ',', ' ') . ' €';
    } elseif (in_array($trans, ['location','saisonnier'], true) && (float)($a['loyer_cc'] ?? $a['loyer'] ?? 0) > 0) {
      $v = (float)($a['loyer_cc'] ?? $a['loyer']);
      $priceStr = number_format($v, 0, ',', ' ') . ' € CC';
    }
    $checks = $ubiflowChecks($a);
    $checksCritKO = count(array_filter($checks, fn($c) => !$c['ok'] && $c['severity']==='critical'));
    $urlEdit = app_url('/bien_detail.php?edit=' . (int)$a['id_bien'] . '&section=annonce');
  ?>
    <div class="al-row">
      <a href="<?= ae($urlEdit) ?>" class="al-thumb" title="Modifier l'annonce">
        <?php if ($photoUrl): ?>
          <img src="<?= ae($photoUrl) ?>" alt="">
        <?php else: ?>📷<?php endif; ?>
      </a>

      <div>
        <div class="al-ref"><?= ae($a['reference_annonce'] ?? ('#' . $a['annonce_id'])) ?> · <?= ae($a['reference_bien'] ?? '—') ?></div>
        <div class="al-title">
          <a href="<?= ae($urlEdit) ?>" style="color:inherit; text-decoration:none;"><?= ae($a['titre'] ?: '(Sans titre)') ?></a>
        </div>
        <div class="al-sub">
          <?php if (!empty($a['ville'])): ?><span>📍 <?= ae(trim(($a['code_postal']??'') . ' ' . ($a['ville']??''))) ?></span><?php endif; ?>
          <?php if (!empty($a['surface_habitable'])): ?><span>📐 <?= ae($a['surface_habitable']) ?> m²</span><?php endif; ?>
          <?php if (!empty($a['nb_pieces'])): ?><span>🚪 <?= (int)$a['nb_pieces'] ?> p.</span><?php endif; ?>
          <?php if (!empty($a['nom_agence'])): ?><span>🏢 <?= ae($a['nom_agence']) ?></span><?php endif; ?>
          <?php if (!empty($a['commercial_nom'])): ?><span>👤 <?= ae($a['commercial_nom']) ?></span><?php endif; ?>
        </div>
      </div>

      <span class="al-badge <?= $transBadge[1] ?>"><?= ae($transBadge[0]) ?></span>
      <span class="al-badge <?= $epBadge[1] ?>"><?= $epBadge[0] ?></span>

      <!-- Voyants Ubiflow -->
      <div class="al-voyants" title="<?= $checksCritKO ?> bloquant(s)">
        <?php foreach ($checks as $c):
          $cls = $c['ok'] ? 'al-v-ok' : ($c['severity']==='critical' ? 'al-v-crit' : 'al-v-warn');
          $url = app_url('/bien_detail.php?edit=' . (int)$a['id_bien']
                 . '&section=' . rawurlencode((string)$c['section'])
                 . ($c['focus'] ? '&focus=' . rawurlencode((string)$c['focus']) : ''));
        ?>
          <a href="<?= ae($url) ?>" class="al-v-dot <?= $cls ?>" title="<?= ae($c['label']) ?> <?= $c['ok']?'✓':'✗' ?>">
            <?= $c['ic'] ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="al-price"><?= ae($priceStr) ?></div>

      <div class="al-actions">
        <a href="<?= ae($urlEdit) ?>" class="al-act" title="Modifier">✏️</a>
        <?php if ($isAdmin): ?>
          <button type="button" class="al-act al-act-del" title="Supprimer (admin)"
                  data-annonce-id="<?= (int)$a['annonce_id'] ?>"
                  data-annonce-ref="<?= ae($a['reference_annonce'] ?: ('#' . $a['annonce_id'])) ?>"
                  onclick="annonceDelete(this)">🗑️</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

</div>

<?php if ($isAdmin): ?>
<script>
const ANNONCE_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token('annonce_delete') : '', JSON_UNESCAPED_SLASHES) ?>;
async function annonceDelete(btn) {
  const id  = btn.dataset.annonceId;
  const ref = btn.dataset.annonceRef;
  if (!confirm('⚠️ Supprimer l\'annonce ' + ref + ' ?\n\nCela supprime uniquement l\'annonce, pas le bien associé.\nCette action est IRRÉVERSIBLE.')) return;
  btn.disabled = true;
  btn.textContent = '⏳';
  try {
    const fd = new FormData();
    fd.append('id_annonce', id);
    fd.append('csrf_token', ANNONCE_CSRF);
    const r = await fetch('api/annonce_delete.php', { method:'POST', body:fd, credentials:'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Erreur');
    btn.closest('.al-row').style.opacity = 0;
    setTimeout(() => btn.closest('.al-row').remove(), 300);
  } catch (e) {
    alert('❌ ' + e.message);
    btn.disabled = false;
    btn.textContent = '🗑️';
  }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
