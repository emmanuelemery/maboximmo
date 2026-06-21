<?php
// transaction_portefeuilles_liste.php — Liste / recherche des portefeuilles enregistrés + détail.
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$q        = trim((string)($_GET['q'] ?? ''));
$fType    = (string)($_GET['type'] ?? '');

// Module en iframe (hub) : on propage embed + le « voir en tant que » bailleur sur tous les liens internes.
$embed      = (int)($_GET['embed'] ?? 0);
$bailleurId = (int)($_GET['bailleur'] ?? 0);
$navQS      = ($embed ? '&embed=1' : '') . ($bailleurId > 0 ? '&bailleur=' . $bailleurId : '');
// Ouvre directement l'ÉDITEUR (filtres + honoraires modifiables) d'un portefeuille enregistré.
$editUrl    = fn(int $id) => app_url('/transaction_portefeuilles_selection.php?portefeuille=' . $id . $navQS);
$newUrl     = app_url('/transaction_portefeuilles_selection.php?' . ltrim($navQS, '&'));
$listUrl    = app_url('/transaction_portefeuilles_liste.php' . ($navQS ? '?' . ltrim($navQS, '&') : ''));

$pageTitle    = 'Portefeuilles enregistrés';
$pageSubtitle = 'Ma Box Agency · Portefeuilles';
$bodyAttr     = 'data-theme-module="transaction"';
$extraCss = <<<'CSS'
<style>
:root{ --pf-ink:#2c2a28; --pf-soft:#7a766f; --pf-faint:#a8a39a; --pf-bg:#f4f1ec; --pf-line:#ece7df;
  --pf-blue:#4878a6; --pf-green:#2d8a4e; --pf-green-bg:#e3f3e8; --pf-purple:#6b4aa0; --pf-sh:0 1px 2px rgba(44,42,40,.04),0 6px 20px rgba(44,42,40,.06); }
.pf-page{ max-width:1100px; }
.pf-hero{ display:flex; align-items:center; gap:16px; margin-bottom:18px; }
.pf-hero-ic{ width:52px; height:52px; border-radius:16px; display:grid; place-items:center; font-size:24px; background:linear-gradient(135deg,#4878a6,#6b4aa0); color:#fff; box-shadow:0 6px 16px rgba(72,120,166,.35); flex:none; }
.pf-hero h1{ margin:0; font-size:22px; color:var(--pf-ink); font-weight:800; }
.pf-hero p{ margin:2px 0 0; font-size:13px; color:var(--pf-soft); }
.pf-hero .spacer{ flex:1; }
.pf-btn{ border-radius:11px; padding:11px 18px; border:1px solid var(--pf-line); background:#fff; color:var(--pf-ink); cursor:pointer; font-size:13.5px; font-weight:600; display:inline-flex; align-items:center; gap:7px; text-decoration:none; }
.pf-btn:hover{ background:var(--pf-bg); }
.pf-btn-primary{ background:linear-gradient(135deg,#4878a6,#3d6691); color:#fff; border:none; }
.pf-filters{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; background:#fff; border-radius:14px; padding:12px 14px; box-shadow:var(--pf-sh); margin-bottom:18px; }
.pf-search{ position:relative; flex:1; min-width:220px; }
.pf-search input{ width:100%; padding:10px 12px 10px 36px; border-radius:10px; border:1px solid var(--pf-line); background:var(--pf-bg); font-size:13.5px; }
.pf-search .mag{ position:absolute; left:11px; top:50%; transform:translateY(-50%); opacity:.5; }
.pf-btn-create{ flex:none; background:#4878a6; color:#fff; font-weight:700; font-size:13px; padding:10px 16px; border-radius:10px; text-decoration:none; white-space:nowrap; }
.pf-btn-create:hover{ background:#3a6188; }
.pf-filters select{ padding:10px 12px; border-radius:10px; border:1px solid var(--pf-line); background:#fff; font-size:13px; cursor:pointer; }
.pf-card{ background:#fff; border-radius:14px; box-shadow:var(--pf-sh); margin-bottom:12px; padding:16px 18px; display:flex; align-items:center; gap:16px; text-decoration:none; transition:.12s; }
.pf-card:hover{ transform:translateY(-1px); box-shadow:0 4px 18px rgba(44,42,40,.1); }
.pf-card .pf-ttl{ font-size:16px; font-weight:800; color:var(--pf-ink); }
.pf-card .pf-meta{ font-size:12.5px; color:var(--pf-soft); margin-top:3px; display:flex; flex-wrap:wrap; gap:12px; }
.pf-card .grow{ flex:1; }
.pf-tag{ font-size:11px; font-weight:800; padding:3px 10px; border-radius:99px; }
.pf-tag.com{ background:#e7eff6; color:var(--pf-blue); } .pf-tag.inv{ background:#efe7f7; color:var(--pf-purple); } .pf-tag.non{ background:var(--pf-bg); color:var(--pf-faint); }
.pf-amt{ text-align:right; }
.pf-amt .v{ font-size:18px; font-weight:800; font-family:'DM Mono',monospace; color:var(--pf-ink); }
.pf-amt .k{ font-size:10px; color:var(--pf-faint); text-transform:uppercase; font-weight:700; }
.pf-del-card{ flex:none; width:38px; height:38px; border-radius:10px; border:1px solid var(--pf-line); background:#fff; color:#b3261e; cursor:pointer; font-size:16px; line-height:1; display:grid; place-items:center; transition:.12s; }
.pf-del-card:hover{ background:#fdecea; border-color:#f0c8c4; }
.pf-empty{ padding:60px; text-align:center; color:var(--pf-soft); background:#fff; border-radius:16px; box-shadow:var(--pf-sh); }
/* détail */
.pf-dtable{ width:100%; border-collapse:collapse; background:#fff; border-radius:14px; overflow:hidden; box-shadow:var(--pf-sh); }
.pf-dtable th{ background:var(--pf-bg); text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--pf-soft); padding:10px 12px; }
.pf-dtable td{ padding:11px 12px; border-top:1px solid var(--pf-line); font-size:13px; }
.pf-dtable td.num{ text-align:right; font-family:'DM Mono',monospace; }
.pf-sum{ display:flex; gap:24px; background:#fff; border-radius:14px; box-shadow:var(--pf-sh); padding:16px 20px; margin-bottom:16px; }
.pf-sum .s .k{ font-size:10px; color:var(--pf-faint); text-transform:uppercase; font-weight:700; }
.pf-sum .s .v{ font-size:20px; font-weight:800; font-family:'DM Mono',monospace; color:var(--pf-ink); }
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eurSp = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', ' ') . ' €';
$tagDest = function ($t) use ($e) {
    if ($t === 'commercialisateur') return '<span class="pf-tag com">Commercialisateur</span>';
    if ($t === 'investisseur')      return '<span class="pf-tag inv">Investisseur</span>';
    return '<span class="pf-tag non">Sans destinataire</span>';
};
?>
<div class="pf-page">

<?php if ($detailId > 0):
    // ── Vue détail d'un portefeuille ──────────────────────────────
    $pf = $pdo->prepare("SELECT * FROM portefeuilles WHERE id = ?");
    $pf->execute([$detailId]);
    $pf = $pf->fetch(PDO::FETCH_ASSOC);
    // Règle d'or : un bailleur ne peut ouvrir que ses propres portefeuilles.
    require_once __DIR__ . '/inc/portefeuille_scope.php';
    $pfScopeD = pf_scope($pdo);
    // Accès par appartenance : un bailleur n'ouvre un portefeuille que s'il contient un de ses biens.
    if ($pf && !$pfScopeD['is_staff']) {
        $idsD = $pfScopeD['ids'] ? implode(',', array_map('intval', $pfScopeD['ids'])) : '0';
        $okD = $pdo->prepare("SELECT 1 FROM portefeuille_biens pb JOIN biens b ON b.id = pb.id_bien
                              WHERE pb.id_portefeuille = ? AND b.id_proprietaire IN ($idsD) LIMIT 1");
        $okD->execute([(int)$pf['id']]);
        if (!$okD->fetchColumn()) { $pf = null; }
    }
    if (!$pf): ?>
        <div class="pf-empty">Portefeuille introuvable. <a href="<?= $e(app_url('/transaction_portefeuilles_liste.php')) ?>">← Retour</a></div>
    <?php else:
        $bl = $pdo->prepare("SELECT * FROM portefeuille_biens WHERE id_portefeuille = ? ORDER BY ordre, id");
        $bl->execute([$detailId]);
        $biens = $bl->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="pf-hero">
        <div class="pf-hero-ic">📁</div>
        <div class="spacer">
            <h1><?= $e($pf['nom']) ?></h1>
            <p><?= $tagDest($pf['type_destinataire']) ?> <?= $pf['destinataire_nom'] ? '· ' . $e($pf['destinataire_nom']) : '' ?> · créé le <?= $e(substr((string)$pf['date_creation'], 0, 10)) ?></p>
        </div>
        <a class="pf-btn pf-btn-primary" href="<?= $e(app_url('/transaction_portefeuilles_selection.php?portefeuille=' . (int)$pf['id'])) ?>">✏️ Reprendre / Modifier</a>
        <button type="button" class="pf-btn" style="color:#b3261e;border-color:#f0c8c4;" onclick="pfSupprimer(this, <?= (int)$pf['id'] ?>, <?= htmlspecialchars(json_encode($pf['nom'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">🗑️ Supprimer</button>
        <a class="pf-btn" href="<?= $e(app_url('/transaction_portefeuilles_liste.php')) ?>">← Retour</a>
    </div>
    <div class="pf-sum">
        <div class="s"><div class="k">Biens</div><div class="v"><?= (int)$pf['nb_biens'] ?></div></div>
        <div class="s"><div class="k">Total prix de vente</div><div class="v"><?= $e($eurSp($pf['total_prix_vente'])) ?></div></div>
        <div class="s"><div class="k">Total net vendeur</div><div class="v"><?= $e($eurSp($pf['total_net_vendeur'])) ?></div></div>
        <div class="s"><div class="k">Total honoraires</div><div class="v"><?= $e($eurSp($pf['total_honoraires'])) ?></div></div>
    </div>
    <table class="pf-dtable">
        <thead><tr><th>Bien</th><th>Réf.</th><th class="num">Surface</th><th class="num">Prix vente</th><th class="num">Prix/m²</th><th class="num">Honoraires</th><th class="num">Net vendeur</th><th class="num">Droits mut.</th><th class="num">Acte en main</th><th class="num">Rdt</th></tr></thead>
        <tbody>
        <?php foreach ($biens as $b): ?>
            <tr>
                <td><a href="<?= $e(app_url('/bien_360.php?id=' . (int)$b['id_bien'])) ?>" target="_blank" rel="noopener" style="color:var(--pf-ink);font-weight:700;text-decoration:none;"><?= $e($b['snap_adresse'] ?: ('Bien #' . $b['id_bien'])) ?></a><?= $b['snap_ville'] ? ' · <span style="color:var(--pf-soft);">' . $e($b['snap_ville']) . '</span>' : '' ?></td>
                <td><?= $e($b['snap_reference'] ?: '—') ?></td>
                <td class="num"><?= $b['snap_surface'] ? $e(fmt_m2($b['snap_surface'], 0)) : '—' ?></td>
                <td class="num"><?= $e($eurSp($b['prix_vente'])) ?></td>
                <td class="num"><?= $e($eurSp($b['prix_m2'])) ?></td>
                <td class="num"><?= $e($eurSp($b['honoraires_montant'])) ?><?= $b['honoraires_pct'] !== null ? ' <span style="color:var(--pf-faint);">(' . $e(number_format((float)$b['honoraires_pct'], 1, ',', ' ')) . '%)</span>' : '' ?></td>
                <td class="num" style="color:#5c4404;font-weight:700;"><?= $e($eurSp($b['net_vendeur'])) ?></td>
                <td class="num"><?= $b['droits_mutation_montant'] !== null ? $e($eurSp($b['droits_mutation_montant'])) . ($b['droits_mutation_pct'] !== null ? ' <span style="color:var(--pf-faint);">(' . $e(number_format((float)$b['droits_mutation_pct'], 1, ',', ' ')) . '%)</span>' : '') : '—' ?></td>
                <td class="num"><?= $e($eurSp($b['prix_acte_en_main'])) ?></td>
                <td class="num"><?= $b['rendement'] !== null ? $e(number_format((float)$b['rendement'], 1, ',', ' ')) . ' %' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

<?php else:
    // ── Liste + recherche ─────────────────────────────────────────
    $where = []; $params = [];
    // Règle d'or (par APPARTENANCE, pas par créateur) : un portefeuille peut regrouper plusieurs
    // propriétaires. L'admin voit tout ; un bailleur voit un portefeuille dès qu'il contient au moins
    // un bien d'un de SES propriétaires. Staff « voir en tant que » : on simule la vue du bailleur.
    require_once __DIR__ . '/inc/portefeuille_scope.php';
    $pfScope = pf_scope($pdo);
    $scopeIds = (!$pfScope['is_staff'] || $pfScope['view_as'] > 0) ? array_map('intval', $pfScope['ids']) : null;
    if ($scopeIds !== null) {   // null = admin sans « voir en tant que » → aucun filtre d'appartenance
        $inIds = $scopeIds ? implode(',', $scopeIds) : '0';
        $where[] = "EXISTS (SELECT 1 FROM portefeuille_biens pb JOIN biens b ON b.id = pb.id_bien
                            WHERE pb.id_portefeuille = portefeuilles.id AND b.id_proprietaire IN ($inIds))";
    }
    if ($q !== '') { $where[] = '(nom LIKE :q1 OR destinataire_nom LIKE :q2)'; $like = '%' . $q . '%'; $params[':q1'] = $like; $params[':q2'] = $like; }
    if (in_array($fType, ['commercialisateur', 'investisseur'], true)) { $where[] = 'type_destinataire = :t'; $params[':t'] = $fType; }
    $sql = "SELECT * FROM portefeuilles" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY date_creation DESC LIMIT 300";
    $st = $pdo->prepare($sql); $st->execute($params);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
?>
    <div class="pf-hero">
        <div class="pf-hero-ic">📚</div>
        <div>
            <h1>Portefeuilles enregistrés</h1>
            <p>Retrouvez et consultez vos sélections passées.</p>
        </div>
        <div class="spacer"></div>
        <a class="pf-btn" href="<?= $e(app_url('/transaction_portefeuilles_envois.php')) ?>">🔗 Liens envoyés & accès</a>
        <a class="pf-btn pf-btn-primary" href="<?= $e($newUrl) ?>">➕ Nouveau portefeuille de sélection</a>
    </div>

    <form method="get" action="<?= $e(app_url('/transaction_portefeuilles_liste.php')) ?>" class="pf-filters" id="pf-filters">
        <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <?php if ($bailleurId > 0): ?><input type="hidden" name="bailleur" value="<?= (int)$bailleurId ?>"><?php endif; ?>
        <div class="pf-search"><span class="mag">🔎</span>
            <input type="text" name="q" placeholder="Rechercher par titre ou destinataire…" value="<?= $e($q) ?>" data-autosubmit="text">
        </div>
        <select name="type" data-autosubmit>
            <option value="">Tous destinataires</option>
            <option value="commercialisateur"<?= $fType === 'commercialisateur' ? ' selected' : '' ?>>Commercialisateur</option>
            <option value="investisseur"<?= $fType === 'investisseur' ? ' selected' : '' ?>>Investisseur</option>
        </select>
        <a class="pf-btn-create" href="<?= $e($newUrl) ?>">➕ Créer une sélection</a>
    </form>

    <!-- Bandeau « reprendre le brouillon en cours » (sélection non enregistrée, conservée en local) -->
    <div id="pf-resume" style="display:none; margin:12px 0; padding:13px 16px; background:#fff8e1; border:1px solid #ffe082; border-radius:11px; font-size:14px; display:none; align-items:center; gap:12px;">
        <span>📝 Vous avez une <strong>sélection en cours non enregistrée</strong> <span id="pf-resume-info" style="color:#7a766f;"></span>.</span>
        <a href="<?= $e($newUrl) ?>" style="font-weight:700; color:#fff; background:#4878a6; padding:7px 14px; border-radius:9px; text-decoration:none;">▶ Reprendre</a>
        <button type="button" onclick="pfDropDraft()" style="border:1px solid #e0d9cf; background:#fff; color:#8a4c12; border-radius:9px; padding:7px 12px; cursor:pointer; font-weight:600;">Abandonner</button>
    </div>
    <script>
    (function(){
        var el = document.getElementById('pf-resume');
        try{
            var d = JSON.parse(localStorage.getItem('pf_draft_selection') || 'null');
            var nbBiens = (d && d.biens) ? Object.keys(d.biens).length : 0;
            var has = d && (d.nom || d.email || d.nom_d || nbBiens > 0);
            if (has){
                el.style.display = 'flex';
                var info = document.getElementById('pf-resume-info');
                if (info) info.textContent = (d.nom ? '« ' + d.nom + ' »' : '') + (nbBiens > 0 ? ' · ' + nbBiens + ' bien(s)' : '');
            }
        }catch(e){}
        window.pfDropDraft = function(){ try{ localStorage.removeItem('pf_draft_selection'); }catch(e){} el.style.display='none'; };
    })();
    </script>

    <?php if (empty($list)): ?>
        <div class="pf-empty">Aucun portefeuille <?= $q !== '' || $fType !== '' ? 'ne correspond à la recherche' : 'enregistré pour l\'instant' ?>. <a href="<?= $e($newUrl) ?>">Créer une sélection →</a></div>
    <?php else: ?>
        <?php foreach ($list as $pf): ?>
            <a class="pf-card" href="<?= $e($editUrl((int)$pf['id'])) ?>">
                <div>
                    <div class="pf-ttl"><?= $e($pf['nom']) ?></div>
                    <div class="pf-meta">
                        <?= $tagDest($pf['type_destinataire']) ?>
                        <?php if ($pf['destinataire_nom']): ?><span>👤 <?= $e($pf['destinataire_nom']) ?></span><?php endif; ?>
                        <span>🏠 <?= (int)$pf['nb_biens'] ?> bien<?= $pf['nb_biens'] > 1 ? 's' : '' ?></span>
                        <span>🗓️ <?= $e(substr((string)$pf['date_creation'], 0, 10)) ?></span>
                    </div>
                </div>
                <div class="grow"></div>
                <div class="pf-amt"><div class="k">Prix de vente</div><div class="v"><?= $e($eurSp($pf['total_prix_vente'])) ?></div></div>
                <div class="pf-amt"><div class="k">Net vendeur</div><div class="v"><?= $e($eurSp($pf['total_net_vendeur'])) ?></div></div>
                <button type="button" class="pf-del-card" title="Supprimer ce portefeuille"
                    onclick="event.preventDefault(); event.stopPropagation(); pfSupprimer(this, <?= (int)$pf['id'] ?>, <?= htmlspecialchars(json_encode($pf['nom'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>);">🗑️</button>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

</div>

<script>
function pfSupprimer(btn, id, nom){
    if (!confirm('Supprimer définitivement le portefeuille « ' + nom + ' » ?\n\nCette action est irréversible (lignes et historique d\'envois inclus).')) return;
    var prev = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '⏳'; }
    var fd = new FormData();
    fd.append('id_portefeuille', id);
    fd.append('csrf_token', <?= json_encode(csrf_token('portefeuille_supprimer'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    fetch(<?= json_encode(app_url('/api/portefeuille_supprimer.php'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j && j.success){ window.location.href = <?= json_encode($listUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; }
            else { alert('Échec : ' + ((j && j.message) || 'erreur inconnue')); if (btn){ btn.disabled=false; btn.innerHTML=prev; } }
        })
        .catch(function(err){ alert('Erreur réseau : ' + err); if (btn){ btn.disabled=false; btn.innerHTML=prev; } });
}
document.querySelectorAll('#pf-filters [data-autosubmit]').forEach(el=>{
    if(el.dataset.autosubmit==='text'){ let t; el.addEventListener('input',()=>{clearTimeout(t);t=setTimeout(()=>el.form.submit(),450);}); }
    else el.addEventListener('change',()=>el.form.submit());
});
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
