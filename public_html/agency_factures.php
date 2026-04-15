<?php
// agency_factures.php — Liste des factures V2 MaBoxImmo
$current_page = 'factures';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Actions GET rapides ───────────────────────────────────────────────
if (isset($_GET['delete']) && $roleId === 1) {
    $did = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM agency_facture_ligne WHERE id_facture=?")->execute([$did]);
    $pdo->prepare("DELETE FROM agency_facture WHERE id=?")->execute([$did]);
    header('Location: agency_factures.php?deleted=1'); exit;
}
if (isset($_GET['duplicate'])) {
    $srcId = (int)$_GET['duplicate'];
    $src   = $pdo->prepare("SELECT * FROM agency_facture WHERE id=?");
    $src->execute([$srcId]);
    $src = $src->fetch(PDO::FETCH_ASSOC);
    if ($src) {
        $annee = (int)date('Y');
        $gen   = generateNumeroFacture($pdo, (int)$src['id_etablissement'], $annee);
        $pdo->prepare("INSERT INTO agency_facture (id_etablissement,id_createur,client,type_client,immeuble_txt,id_immeuble,annee,seq_num,numero,date_emission,date_echeance,statut,tva_defaut,mode_paiement,mail_destinataire,mail_cc,notes,total_ht,total_tva,total_ttc,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?,?,?,0,0,0,NOW())")
            ->execute([$src['id_etablissement'],$userId,$src['client'],$src['type_client'],$src['immeuble_txt'],$src['id_immeuble'],$annee,$gen['seq_num'],$gen['numero'],null,'brouillon',$src['tva_defaut'],$src['mode_paiement'],$src['mail_destinataire'],$src['mail_cc'],$src['notes']]);
        $newId = (int)$pdo->lastInsertId();
        // Dupliquer les lignes
        $lignes = $pdo->prepare("SELECT * FROM agency_facture_ligne WHERE id_facture=? ORDER BY ordre");
        $lignes->execute([$srcId]);
        foreach ($lignes->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $pdo->prepare("INSERT INTO agency_facture_ligne (id_facture,ordre,designation,description,quantite,prix_unitaire_ht,tva_taux) VALUES (?,?,?,?,?,?,?)")
                ->execute([$newId,$l['ordre'],$l['designation'],$l['description'],$l['quantite'],$l['prix_unitaire_ht'],$l['tva_taux']]);
        }
        header("Location: agency_facture_form.php?id=$newId"); exit;
    }
}

function generateNumeroFacture(PDO $pdo, int $idEtab, int $annee): array {
    $st = $pdo->prepare("SELECT sigle FROM etablissements WHERE id=?");
    $st->execute([$idEtab]);
    $sigle  = $st->fetchColumn() ?: 'F';
    $prefix = strtoupper(substr(preg_replace('/[^A-Z]/i','',trim((string)$sigle)),0,3)) ?: 'FAC';
    $st2 = $pdo->prepare("SELECT COALESCE(MAX(seq_num),0)+1 FROM agency_facture WHERE id_etablissement=? AND annee=?");
    $st2->execute([$idEtab,$annee]);
    $next   = (int)$st2->fetchColumn();
    $numero = sprintf('%s-%04d-%06d', $prefix, $annee, $next);
    return ['seq_num'=>$next,'numero'=>$numero];
}

// ── Filtres ────────────────────────────────────────────────────────────
$f_q      = trim($_GET['q']      ?? '');
$f_statut = $_GET['statut']      ?? '';
$f_annee  = (int)($_GET['annee'] ?? 0);
$f_imm    = (int)($_GET['immeuble'] ?? 0);
$f_etab   = ($roleId === 1) ? (int)($_GET['etab'] ?? 0) : (int)($_SESSION['id_etablissement'] ?? 0);
$sort     = in_array($_GET['sort']??'', ['date_emission','numero','client','total_ttc','statut']) ? $_GET['sort'] : 'date_emission';
$dir      = ($_GET['dir']??'DESC')==='ASC' ? 'ASC' : 'DESC';

$annees   = $pdo->query("SELECT DISTINCT annee FROM agency_facture ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
$immeubles= $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$etabs    = ($roleId===1) ? $pdo->query("SELECT id,nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Requête ─────────────────────────────────────────────────────────────
$where = ['1=1']; $bind = [];
if ($roleId >= 2 && $f_etab) { $where[] = 'f.id_etablissement=:etab'; $bind[':etab']=$f_etab; }
if ($f_statut) { $where[] = 'f.statut=:stat'; $bind[':stat']=$f_statut; }
if ($f_annee)  { $where[] = 'f.annee=:ann';   $bind[':ann']=$f_annee; }
if ($f_imm)    { $where[] = 'f.id_immeuble=:imm'; $bind[':imm']=$f_imm; }
if ($f_q)      { $where[] = '(f.numero LIKE :q OR f.client LIKE :q2 OR f.immeuble_txt LIKE :q3)'; $bind[':q']="%$f_q%"; $bind[':q2']="%$f_q%"; $bind[':q3']="%$f_q%"; }

$stmt = $pdo->prepare("
    SELECT f.*, e.nom AS etab_nom,
           i.nom_immeuble AS imm_nom,
           u.nom AS auteur_nom, u.prenom AS auteur_prenom
    FROM agency_facture f
    LEFT JOIN etablissements e ON e.id=f.id_etablissement
    LEFT JOIN immeubles i ON i.id=f.id_immeuble
    LEFT JOIN users u ON u.id=f.id_createur
    WHERE ".implode(' AND ',$where)."
    ORDER BY f.$sort $dir
");
$stmt->execute($bind);
$factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs ─────────────────────────────────────────────────────────────
$kpi_q = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(total_ttc),0) AS ca_total,
        COALESCE(SUM(CASE WHEN statut='payee' THEN total_ttc END),0) AS ca_encaisse,
        COALESCE(SUM(CASE WHEN statut IN ('envoyee','validee') THEN total_ttc END),0) AS ca_attente,
        COUNT(CASE WHEN statut='brouillon' THEN 1 END) AS nb_brouillons
    FROM agency_facture
    WHERE annee=YEAR(CURDATE())
    ".($f_etab && $roleId>=2 ? "AND id_etablissement=$f_etab" : "")
);
$kpi_q->execute(); $k = $kpi_q->fetch(PDO::FETCH_ASSOC);

// ── Helpers ────────────────────────────────────────────────────────────
$STAT = [
    'brouillon' => ['Brouillon', '#9a9690','var(--bg-primary,#e4e8f0)'],
    'validee'   => ['Validée',   '#4878a6','#d8e8f5'],
    'envoyee'   => ['Envoyée',   '#7a6830','#f8eddc'],
    'payee'     => ['Payée',     '#3a7a6a','#d8eee3'],
    'annulee'   => ['Annulée',   '#8a5040','#fce8e8'],
];
function statBadge(string $s, array $map): string {
    $v = $map[$s] ?? ['?','#808080','#e8e8e8'];
    return '<span style="background:'.$v[2].';color:'.$v[1].';padding:2px 9px;border-radius:999px;font-size:10px;font-weight:700;font-family:\'DM Mono\',monospace">'.$v[0].'</span>';
}
function eur(float $v): string { return number_format($v,2,',',' ').' €'; }
function sortLinkF(string $col, string $lbl, string $cur, string $cd): string {
    $nd = ($cur===$col && $cd==='ASC') ? 'DESC' : 'ASC';
    $p  = array_merge($_GET,['sort'=>$col,'dir'=>$nd]);
    $ic = $cur===$col ? ($cd==='ASC'?' ↑':' ↓') : '';
    return '<a href="?'.http_build_query($p).'" style="color:inherit;text-decoration:none">'.$lbl.$ic.'</a>';
}

// ── Layout ───────────────────────────────────────────────────────────
$layout_title   = 'Factures';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)($k['total']??0).'</div><div class="ph-kpi-lbl">Total '.date('Y').'</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.eur((float)($k['ca_total']??0)).'</div><div class="ph-kpi-lbl">CA TTC</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.eur((float)($k['ca_encaisse']??0)).'</div><div class="ph-kpi-lbl">Encaissé</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.eur((float)($k['ca_attente']??0)).'</div><div class="ph-kpi-lbl">Attente</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.(int)($k['nb_brouillons']??0).'</div><div class="ph-kpi-lbl">Brouillons</div></div>
';

$btnNew = ($roleId <= 2)
    ? '<a href="agency_facture_form.php" class="ph-btn primary"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Nouvelle</a>'
    : '<a class="ph-btn dispo">—</a>';
$layout_head_actions = $btnNew . '
    <a href="agency_dashboard.php" class="ph-btn">Dashboard</a>
    <a class="ph-btn dispo">—</a>
    <a class="ph-btn dispo">—</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.filter-bar{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:14px;box-shadow:5px 5px 14px var(--shadow-dark,#d4d7de),-5px -5px 12px var(--shadow-light,#fff);padding:12px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.fg{display:flex;flex-direction:column;gap:3px}
.fg label{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}
.fg select,.fg input{background:var(--bg-secondary,#eef1f6);border:none;border-radius:8px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:6px 10px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;height:34px}
.pf{padding:4px 12px;border-radius:999px;font-family:'DM Mono',monospace;font-size:10px;font-weight:600;text-decoration:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;border:none;cursor:pointer;white-space:nowrap}
.pf.active{background:#4878a6;color:#fff;box-shadow:inset 2px 2px 5px #355f88,inset -2px -2px 5px #6898bf}
.pill-row{display:flex;gap:5px;flex-wrap:wrap}
.t-wrap{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);overflow:hidden}
.t-fact{width:100%;border-collapse:separate;border-spacing:0}
.t-fact thead tr{background:var(--bg-secondary,#eef1f6)}
.t-fact th{font-family:'DM Mono',monospace;font-size:9px;color:#4a6038;text-transform:uppercase;letter-spacing:.14em;padding:10px 14px;font-weight:700;border-bottom:1px solid #e4e6ec;white-space:nowrap}
.t-fact tbody tr{border-bottom:1px solid #ece8e2;transition:background .12s}
.t-fact tbody tr:hover{background:var(--bg-secondary,#eef1f6)}
.t-fact tbody tr:last-child{border-bottom:none}
.t-fact td{padding:10px 14px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;vertical-align:middle}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;text-decoration:none;border:none;cursor:pointer;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#4878a6;white-space:nowrap}
.btn-xs.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de)}
.btn-xs.red{color:#8a5040}
.empty-state{text-align:center;padding:50px 20px;font-family:'DM Mono',monospace;font-size:12px;color:#9a9690}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function applyF(k,v){ const p=new URLSearchParams(window.location.search); if(v)p.set(k,v);else p.delete(k); window.location.href='?'+p; }
let _qt; document.getElementById('fq')?.addEventListener('input',function(){ clearTimeout(_qt); _qt=setTimeout(()=>applyF('q',this.value),500); });
</script>
EXTRAJS;

ob_start();
?>

<!-- Filtres -->
<div class="filter-bar">
    <div class="fg">
        <label>Recherche</label>
        <input type="text" id="fq" value="<?= htmlspecialchars($f_q) ?>" placeholder="N° facture, client…" onchange="applyF('q',this.value)">
    </div>
    <div class="fg">
        <label>Statut</label>
        <div class="pill-row">
            <?php foreach (['' => 'Tous','brouillon'=>'Brouillon','validee'=>'Validée','envoyee'=>'Envoyée','payee'=>'Payée','annulee'=>'Annulée'] as $v=>$l):
                $a = ($f_statut===$v) ? ' active' : '';
                $p = array_merge($_GET,['statut'=>$v]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pf<?= $a ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (!empty($annees)): ?>
    <div class="fg">
        <label>Année</label>
        <select onchange="applyF('annee',this.value)">
            <option value="">— Toutes —</option>
            <?php foreach ($annees as $a): ?>
            <option value="<?= $a ?>" <?= $f_annee==$a?'selected':'' ?>><?= $a ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="fg">
        <label>Immeuble</label>
        <select onchange="applyF('immeuble',this.value)">
            <option value="">— Tous —</option>
            <?php foreach ($immeubles as $im): ?>
            <option value="<?= $im['id'] ?>" <?= $f_imm==$im['id']?'selected':'' ?>><?= htmlspecialchars($im['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($roleId===1 && !empty($etabs)): ?>
    <div class="fg">
        <label>Établissement</label>
        <select onchange="applyF('etab',this.value)">
            <option value="">— Tous —</option>
            <?php foreach ($etabs as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $f_etab==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <a href="agency_factures.php" class="btn-xs" style="height:34px;margin-top:auto">✕ Reset</a>
</div>

<?php if (isset($_GET['deleted'])): ?><div style="background:#d8eee3;color:#2a6040;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">✅ Facture supprimée.</div><?php endif; ?>

<!-- Table -->
<?php if (empty($factures)): ?>
<div class="empty-state">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--shadow-dark,#d4d7de)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:10px"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
    <div>Aucune facture trouvée</div>
</div>
<?php else: ?>
<div class="t-wrap">
<table class="t-fact">
    <thead>
        <tr>
            <th><?= sortLinkF('numero','N° Facture',$sort,$dir) ?></th>
            <th><?= sortLinkF('date_emission','Date',$sort,$dir) ?></th>
            <th><?= sortLinkF('client','Client',$sort,$dir) ?></th>
            <th>Immeuble</th>
            <th style="text-align:right"><?= sortLinkF('total_ttc','Total TTC',$sort,$dir) ?></th>
            <th><?= sortLinkF('statut','Statut',$sort,$dir) ?></th>
            <th>Mode paiement</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($factures as $f):
        $isLate = !in_array($f['statut'],['payee','annulee']) && !empty($f['date_echeance']) && $f['date_echeance'] < date('Y-m-d');
    ?>
    <tr>
        <td>
            <a href="agency_facture_form.php?id=<?= $f['id'] ?>" style="font-family:'DM Mono',monospace;font-weight:700;color:#4878a6;text-decoration:none">
                <?= htmlspecialchars($f['numero']) ?>
            </a>
        </td>
        <td>
            <div><?= $f['date_emission'] ? date('d/m/Y',strtotime($f['date_emission'])) : '—' ?></div>
            <?php if ($f['date_echeance']): ?>
            <div style="font-family:'DM Mono',monospace;font-size:10px;color:<?= $isLate ? '#8a5040' : '#9a9690' ?>">
                Éch. <?= date('d/m/Y',strtotime($f['date_echeance'])) ?><?= $isLate ? ' ⚠' : '' ?>
            </div>
            <?php endif; ?>
        </td>
        <td>
            <div style="font-weight:600"><?= htmlspecialchars($f['client'] ?: '—') ?></div>
            <?php if ($f['etab_nom']): ?><div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690"><?= htmlspecialchars($f['etab_nom']) ?></div><?php endif; ?>
        </td>
        <td>
            <?php if ($f['imm_nom']): ?>
            <a href="agency_immeuble_fiche.php?id=<?= $f['id_immeuble'] ?>" style="color:#4878a6;text-decoration:none;font-size:11px"><?= htmlspecialchars($f['imm_nom']) ?></a>
            <?php elseif ($f['immeuble_txt']): ?>
            <span style="font-size:11px;color:#6a6864"><?= htmlspecialchars($f['immeuble_txt']) ?></span>
            <?php else: ?><span style="color:var(--shadow-dark,#d4d7de)">—</span><?php endif; ?>
        </td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;font-size:13px">
            <?= eur((float)$f['total_ttc']) ?>
            <?php if ($f['total_ht'] != $f['total_ttc']): ?>
            <div style="font-size:10px;font-weight:400;color:#9a9690">HT <?= eur((float)$f['total_ht']) ?></div>
            <?php endif; ?>
        </td>
        <td><?= statBadge($f['statut']??'brouillon', $STAT) ?></td>
        <td style="font-family:'DM Mono',monospace;font-size:10px;color:#6a6864;text-transform:capitalize"><?= htmlspecialchars($f['mode_paiement'] ?? '—') ?></td>
        <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap">
                <a href="agency_facture_form.php?id=<?= $f['id'] ?>" class="btn-xs primary">Ouvrir</a>
                <a href="agency_pdf_facture.php?id=<?= $f['id'] ?>" target="_blank" class="btn-xs" title="PDF">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    PDF
                </a>
                <a href="?duplicate=<?= $f['id'] ?>" class="btn-xs" title="Dupliquer">⧉</a>
                <?php if ($roleId===1): ?>
                <a href="?delete=<?= $f['id'] ?>" class="btn-xs red" onclick="return confirm('Supprimer cette facture ?')" title="Supprimer">🗑</a>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin-top:8px;text-align:right"><?= count($factures) ?> facture<?= count($factures)>1?'s':'' ?></div>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
