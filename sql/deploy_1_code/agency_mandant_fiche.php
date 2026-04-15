<?php
// agency_mandant_fiche.php — Fiche détail d'un mandant
require_once __DIR__ . '/inc/init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: agency_mandants.php'); exit; }

$stmt = $pdo->prepare("
    SELECT mn.*, i.nom AS imm_nom, i.adresse AS imm_adresse, i.ville AS imm_ville,
           e.nom AS etab_nom
    FROM agency_mandant mn
    LEFT JOIN immeubles i ON i.id = mn.id_immeuble
    LEFT JOIN etablissements e ON e.id = mn.id_etablissement
    WHERE mn.id = ?
");
$stmt->execute([$id]);
$mn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mn) { http_response_code(404); exit('Mandant introuvable'); }

// Mandats liés
$mandats = $pdo->prepare("
    SELECT m.*, i.nom AS imm_nom
    FROM agency_mandat m
    LEFT JOIN immeubles i ON i.id = m.id_immeuble
    WHERE m.id_mandant = ?
    ORDER BY m.date_debut DESC
");
$mandats->execute([$id]);
$mandats = $mandats->fetchAll(PDO::FETCH_ASSOC);

// Contrats syndic liés
$contrats = $pdo->prepare("
    SELECT cs.id, cs.nom_copropriete, cs.date_debut, cs.date_fin, cs.remuneration_annuelle_ht, cs.nb_lots_principaux
    FROM contrat_syndic cs
    WHERE cs.id_immeuble = ? AND ? > 0
    ORDER BY cs.date_debut DESC
    LIMIT 10
");
$contrats->execute([$mn['id_immeuble'] ?? 0, $mn['id_immeuble'] ?? 0]);
$contrats = $contrats->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = ['copropriete'=>'Copropriété','proprietaire'=>'Propriétaire','sci'=>'SCI','autre'=>'Autre'];
$typeIcons  = ['copropriete'=>'🏢','proprietaire'=>'👤','sci'=>'🏛️','autre'=>'📋'];
$statutColors = ['actif'=>['#dcfce7','#3a7a6a'],'suspendu'=>['#fef3c7','#7a6830'],'resilie'=>['#fee2e2','#8a5040'],'expire'=>['#f3f4f6','#9ca3af'],'archive'=>['#f3f4f6','#9ca3af']];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dtFr($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function fE($n) { return number_format((float)$n,2,',',' ').' €'; }

$initiales = strtoupper(mb_substr($mn['raison_sociale'],0,1));
if (($mn['representant'] ?? '')) {
    $parts = explode(' ', $mn['representant']);
    $initiales = strtoupper(mb_substr($parts[0],0,1).(isset($parts[1])?mb_substr($parts[1],0,1):''));
}

$actifCount = count(array_filter($mandats, fn($m) => $m['statut']==='actif'));
$caTotal = array_sum(array_column($mandats, 'honoraires_ht'));

$layout_title   = 'Mandant — '.$mn['raison_sociale'];
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">'.count($mandats).'</div><div class="ph-kpi-lbl">Mandats</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.$actifCount.'</div><div class="ph-kpi-lbl">Actifs</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.number_format((float)$caTotal,0,',',' ').' €</div><div class="ph-kpi-lbl">CA HT</div></div>
';

$layout_head_actions = '
<a href="agency_mandant_form.php?id='.$id.'" class="ph-btn">
    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Modifier
</a>
<a href="agency_registre_form.php?mode=rapide&mandant_id='.$id.'" class="ph-btn primary">
    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Mandat
</a>
<a href="agency_mandants.php" class="ph-btn">
    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Liste
</a>
<span class="ph-btn dispo">dispo</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.hero{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:20px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:28px;display:flex;gap:24px;align-items:flex-start;margin-bottom:22px}
.avatar{width:72px;height:72px;border-radius:18px;background:linear-gradient(135deg,#3a7a6a,#4a8a7a);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#fff;flex-shrink:0}
.hero-info{flex:1}
.hero-name{font-size:22px;font-weight:800;margin-bottom:4px;color:#2f587d}
.hero-sub{font-size:13px;color:#8a8680;margin-bottom:10px}
.hero-badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700}
.hero-actions{display:flex;gap:8px;flex-shrink:0;align-self:center}
.btn-icon{width:36px;height:36px;border-radius:10px;border:none;cursor:pointer;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 8px var(--shadow-light,#fff);color:#8a8680;display:flex;align-items:center;justify-content:center;font-size:15px;text-decoration:none;transition:all .15s}
.btn-icon:hover{color:#3a7a6a;box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff)}
.grid2{display:grid;grid-template-columns:2fr 1fr;gap:18px;align-items:start}
.fiche-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:22px;margin-bottom:18px}
.card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8a5040;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.info-item .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#7a6830;margin-bottom:2px}
.info-item .val{font-size:13px;font-weight:600;color:#1a1816}
.fiche-card table{width:100%;border-collapse:collapse}
.fiche-card th{padding:10px 14px;text-align:left;white-space:nowrap;font-family:'DM Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#4a6038;border-bottom:1px solid rgba(196,192,186,0.5);background:var(--bg-secondary,var(--bg-secondary,#eef1f6))}
.fiche-card td{padding:11px 12px;font-size:13px;border-bottom:1px solid rgba(196,192,186,0.25)}
.fiche-card tr:last-child td{border-bottom:none}
.fiche-card tr:hover td{background:rgba(72,120,166,.04)}
.btn-sm{padding:6px 14px;border-radius:999px;border:none;cursor:pointer;font-size:12px;font-weight:700;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:opacity .15s}
.btn-sm.primary{background:#3a7a6a;color:#fff}
.btn-sm.primary:hover{opacity:.88}
.btn-sm.outline{background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 8px var(--shadow-light,#fff);color:#8a8680}
.btn-sm.outline:hover{box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff);color:#3a7a6a}
.empty{padding:30px;text-align:center;color:#8a8680;font-size:13px}
@media(max-width:800px){.grid2{grid-template-columns:1fr}.hero{flex-direction:column}.info-grid{grid-template-columns:1fr}}
</style>
EXTRACSS;

$layout_extra_js = '';

ob_start();
?>

<!-- Hero -->
<div class="hero">
    <div class="avatar"><?= $initiales ?></div>
    <div class="hero-info">
        <div class="hero-name"><?= h($mn['raison_sociale']) ?></div>
        <div class="hero-sub">
            <?php if ($mn['representant']): ?><?= h($mn['representant']) ?> · <?php endif; ?>
            <?php if ($mn['ville']): ?><?= h($mn['code_postal'].' '.$mn['ville']) ?><?php endif; ?>
        </div>
        <div class="hero-badges">
            <span class="badge" style="background:rgba(58,122,106,.15);color:#3a7a6a"><?= $typeIcons[$mn['type_mandant']]??'' ?> <?= $typeLabels[$mn['type_mandant']]??'' ?></span>
            <span class="badge" style="background:<?= $mn['actif']?'#dcfce7':'#fee2e2' ?>;color:<?= $mn['actif']?'#3a7a6a':'#8a5040' ?>"><?= $mn['actif']?'Actif':'Inactif' ?></span>
            <?php if ($mn['etab_nom']): ?><span class="badge" style="background:rgba(148,163,184,.15);color:#8a8680"><?= h($mn['etab_nom']) ?></span><?php endif; ?>
            <?php if (count($mandats)): ?><span class="badge" style="background:rgba(58,122,106,.12);color:#3a7a6a"><?= count($mandats) ?> mandat<?= count($mandats)>1?'s':'' ?></span><?php endif; ?>
        </div>
    </div>
    <div class="hero-actions">
        <a href="agency_mandant_form.php?id=<?= $id ?>" class="btn-icon" title="Modifier">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </a>
        <a href="agency_registre_form.php?mode=rapide&mandant_id=<?= $id ?>" class="btn-icon" title="Nouveau mandat" style="background:#3a7a6a;color:#fff;box-shadow:none">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        </a>
    </div>
</div>

<div class="grid2">
<div>

<!-- Coordonnées -->
<div class="fiche-card">
    <div class="card-title">Coordonnées &amp; Identité</div>
    <div class="info-grid">
        <?php if ($mn['email']): ?>
        <div class="info-item">
            <div class="lbl">Email</div>
            <div class="val"><a href="mailto:<?= h($mn['email']) ?>" style="color:#3a7a6a"><?= h($mn['email']) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if ($mn['telephone']): ?>
        <div class="info-item">
            <div class="lbl">Téléphone</div>
            <div class="val"><a href="tel:<?= h($mn['telephone']) ?>" style="color:#3a7a6a"><?= h($mn['telephone']) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if ($mn['adresse']): ?>
        <div class="info-item" style="grid-column:span 2">
            <div class="lbl">Adresse</div>
            <div class="val"><?= h($mn['adresse']) ?><?= $mn['code_postal']||$mn['ville'] ? ' — '.h($mn['code_postal'].' '.$mn['ville']) : '' ?></div>
        </div>
        <?php endif; ?>
        <?php if ($mn['siret']): ?>
        <div class="info-item">
            <div class="lbl">SIRET</div>
            <div class="val" style="font-family:'DM Mono',monospace"><?= h($mn['siret']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($mn['imm_nom']): ?>
        <div class="info-item">
            <div class="lbl">Immeuble lié</div>
            <div class="val"><?= h($mn['imm_nom']) ?><?= $mn['imm_ville']?' — '.h($mn['imm_ville']):'' ?></div>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <div class="lbl">Membre depuis</div>
            <div class="val"><?= dtFr($mn['created_at']) ?></div>
        </div>
    </div>
</div>

<!-- Mandats -->
<div class="fiche-card">
    <div class="card-title">
        Mandats liés (<?= count($mandats) ?>)
        <a href="agency_registre_form.php?mode=rapide&mandant_id=<?= $id ?>" class="btn-sm primary">＋ Nouveau mandat</a>
    </div>
    <?php if (empty($mandats)): ?>
    <div class="empty">Aucun mandat enregistré pour ce mandant</div>
    <?php else: ?>
    <table>
        <thead><tr>
            <th>N°</th><th>Type</th><th>Immeuble</th><th>Période</th><th>Honoraires HT/an</th><th>Statut</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($mandats as $m):
            $sc = $statutColors[$m['statut']] ?? ['#f3f4f6','#9ca3af'];
        ?>
        <tr>
            <td style="font-family:'DM Mono',monospace;font-weight:700;color:#3a7a6a"><?= str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT) ?></td>
            <td style="font-size:12px;text-transform:capitalize"><?= h($m['type_mandat']) ?></td>
            <td><?= h($m['imm_nom'] ?: $m['immeuble_txt'] ?: '—') ?></td>
            <td style="font-size:12px;color:#8a8680"><?= dtFr($m['date_debut']) ?> → <?= dtFr($m['date_fin']) ?></td>
            <td style="font-weight:700"><?= fE($m['honoraires_ht']) ?></td>
            <td><span class="badge" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>"><?= h($m['statut']) ?></span></td>
            <td><a href="agency_registre_fiche.php?id=<?= $m['id'] ?>" class="btn-sm outline">Ouvrir</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($contrats): ?>
<!-- Contrats syndic liés -->
<div class="fiche-card">
    <div class="card-title">Contrats de syndic liés (<?= count($contrats) ?>)</div>
    <table>
        <thead><tr><th>Copropriété</th><th>Période</th><th>Lots</th><th>Honoraires HT</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($contrats as $cs): ?>
        <tr>
            <td style="font-weight:600"><?= h($cs['nom_copropriete'] ?: '—') ?></td>
            <td style="font-size:12px;color:#8a8680"><?= dtFr($cs['date_debut']) ?> → <?= dtFr($cs['date_fin']) ?></td>
            <td><?= $cs['nb_lots_principaux'] ? (int)$cs['nb_lots_principaux'].' lots' : '—' ?></td>
            <td style="font-weight:700"><?= $cs['remuneration_annuelle_ht'] ? fE($cs['remuneration_annuelle_ht']) : '—' ?></td>
            <td><a href="agency_syndic_contrat_form.php?id=<?= $cs['id'] ?>" class="btn-sm outline">Voir</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div>
<div>

<!-- Sidebar info -->
<div class="fiche-card">
    <div class="card-title">Récapitulatif</div>
    <div style="display:flex;flex-direction:column;gap:12px">
        <div style="padding:14px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:12px;box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff)">
            <div style="font-size:24px;font-weight:800;color:#3a7a6a"><?= count($mandats) ?></div>
            <div style="font-size:11px;color:#8a8680;text-transform:uppercase;letter-spacing:.4px">Mandats total</div>
        </div>
        <div style="padding:14px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:12px;box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff)">
            <div style="font-size:24px;font-weight:800;color:#3a7a6a"><?= $actifCount ?></div>
            <div style="font-size:11px;color:#8a8680;text-transform:uppercase;letter-spacing:.4px">Mandats actifs</div>
        </div>
        <div style="padding:14px;background:linear-gradient(135deg,#3a7a6a,#4a8a7a);border-radius:12px">
            <div style="font-size:20px;font-weight:800;color:#fff"><?= fE($caTotal) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.4px">CA HT total</div>
        </div>
    </div>
</div>

<div class="fiche-card">
    <div class="card-title">Actions rapides</div>
    <div style="display:flex;flex-direction:column;gap:8px">
        <a href="agency_registre_form.php?mode=pro&mandant_id=<?= $id ?>" class="btn-sm primary" style="justify-content:center;padding:10px">
            ＋ Nouveau mandat complet
        </a>
        <a href="agency_registre_form.php?mode=rapide&mandant_id=<?= $id ?>" class="btn-sm outline" style="justify-content:center;padding:10px">
            ⚡ Mandat rapide
        </a>
        <a href="agency_mandant_form.php?id=<?= $id ?>" class="btn-sm outline" style="justify-content:center;padding:10px">
            Modifier le mandant
        </a>
        <?php if ($mn['email']): ?>
        <a href="mailto:<?= h($mn['email']) ?>" class="btn-sm outline" style="justify-content:center;padding:10px">
            Envoyer un email
        </a>
        <?php endif; ?>
    </div>
</div>

</div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
