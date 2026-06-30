<?php
// transaction_baux_overview.php — Vue agrégée de tous les baux créés avec leurs liens
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);

// Filtre scope société pour non-manager
$where = '1=1';
$bind  = [];
if (!$isManager && isset($_SESSION['id_societe'])) {
    $where = '(bb.id_societe = ? OR bb.id_societe IS NULL)';
    $bind[] = (int)$_SESSION['id_societe'];
}

$sql = "SELECT
    bb.id AS bail_id, bb.bail_nature, bb.statut,
    bb.date_signature, bb.date_prise_effet, bb.date_fin, bb.duree_mois,
    bb.loyer_mensuel_hc, bb.complement_loyer, bb.charges_mensuelles, bb.tva_applicable, bb.tva_taux,
    bb.depot_garantie, bb.nb_termes_garantie,
    bb.indice_type, bb.indice_trimestre, bb.indice_valeur,
    bb.bailleur_representant_nom, bb.bailleur_representant_qualite,
      bb.bailleur_representant_email, bb.bailleur_representant_telephone,
    bb.locataire_raison_sociale, bb.locataire_nom, bb.locataire_prenom,
      bb.locataire_email, bb.locataire_telephone, bb.locataire_siren,
    bb.locataire_representant_nom, bb.locataire_representant_qualite,
      bb.locataire_representant_email, bb.locataire_representant_telephone,
    bb.renonciation_recours_reciproque, bb.conditions_particulieres,
    bb.id_tiers_locataire, bb.created_at, bb.id_user_created,
    -- Bien
    b.id AS bien_id, b.reference_bien,
    COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1)     AS bien_adresse,
    COALESCE(NULLIF(b.ville, ''), i.ville)             AS bien_ville,
    COALESCE(NULLIF(b.code_postal, ''), i.code_postal) AS bien_cp,
    b.surface_habitable, b.priorite_vente,
    -- Immeuble
    i.id AS immeuble_id, i.nom_immeuble,
    -- Propriétaire (legacy → tiers)
    p.id AS proprietaire_id, p.societe AS proprio_societe,
    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.prenom, p.nom)), ''), p.societe) AS proprio_nom_legacy,
    p.id_tiers AS proprio_tiers_id,
    COALESCE(NULLIF(tp.nom_affichage, ''), tp.raison_sociale, NULLIF(TRIM(CONCAT_WS(' ', tp.prenom, tp.nom)), '')) AS proprio_tiers_nom,
    -- Locataire tiers
    tl.nom_affichage AS locataire_tiers_nom,
    tl.type_tiers    AS locataire_tiers_type,
    -- User créateur
    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.prenom, u.nom)), ''), u.email) AS createur,
    -- Compteurs
    (SELECT COUNT(*) FROM ged_documents d
       WHERE d.status='active' AND d.source_module='05_TRANSACTION'
         AND JSON_EXTRACT(d.metadata, '\$.classement.bien_id_bdd') = b.id) AS nb_docs_ged,
    (SELECT COUNT(*) FROM tiers_roles tr
       WHERE tr.objet_type='bail' AND tr.id_objet=bb.id) AS nb_roles_bail,
    (SELECT COUNT(*) FROM tiers_roles tr
       WHERE tr.objet_type='bien' AND tr.id_objet=b.id) AS nb_roles_bien
FROM bien_baux bb
LEFT JOIN biens b           ON b.id = bb.id_bien
LEFT JOIN immeubles i       ON i.id = b.id_immeuble
LEFT JOIN proprietaires p   ON p.id = b.id_proprietaire
LEFT JOIN tiers tp          ON tp.id = p.id_tiers
LEFT JOIN tiers tl          ON tl.id = bb.id_tiers_locataire
LEFT JOIN users u           ON u.id = bb.id_user_created
WHERE $where
ORDER BY bb.created_at DESC LIMIT 200";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
    $err = $e->getMessage();
}

$pageTitle    = 'Baux — Vue agrégée';
$pageSubtitle = 'Ma Box Agency · Tous les baux et leurs liens';

$extraCss = <<<'CSS'
<style>
body { background: #f7f4ef; }
.bx-wrap { max-width: 100%; padding: 0 8px; }
.bx-stats { display: flex; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.bx-stat { background: #fff; padding: 12px 18px; border-radius: 10px; box-shadow: 4px 4px 10px #c8c4be,-4px -4px 10px #fff; }
.bx-stat strong { display: block; font-size: 22px; color: #2c5687; }
.bx-stat span { font-size: 11px; color: #7a766f; text-transform: uppercase; letter-spacing: .04em; }

.bx-table-wrap { background: #fff; border-radius: 12px; overflow-x: auto; box-shadow: 4px 4px 10px #c8c4be,-4px -4px 10px #fff; }
.bx-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.bx-table th { background: #f4f1ec; color: #5a5650; font-weight: 700; font-size: 10px; padding: 8px 6px;
    text-align: left; text-transform: uppercase; border-bottom: 2px solid #e3dfd8; white-space: nowrap;
    position: sticky; top: 0; z-index: 2; }
.bx-table td { padding: 8px 6px; border-bottom: 1px solid #f0ece6; vertical-align: top; }
.bx-table tr:hover { background: #fafaf6; }
.bx-table .num { text-align: right; font-family: 'DM Mono', monospace; }
.bx-table .ref { font-family: 'DM Mono', monospace; font-weight: 700; color: #4878a6; }
.bx-table a { color: #4878a6; text-decoration: none; }
.bx-table a:hover { text-decoration: underline; }

.bx-pill { display: inline-block; padding: 2px 7px; border-radius: 99px; font-size: 10px; font-weight: 700; }
.bx-pill.actif     { background: #d9f0db; color: #2d6a35; }
.bx-pill.brouillon { background: #e9e6e0; color: #5a5650; }
.bx-pill.resilie   { background: #fbe9e9; color: #a8323b; }
.bx-pill.expire    { background: #ffe5c2; color: #8a4c12; }
.bx-pill-nat       { background: #e0e7ff; color: #3b2c87; padding: 1px 6px; border-radius: 4px; font-size: 9.5px; }
.bx-link-icon { display: inline-block; padding: 1px 6px; border-radius: 4px; background: #f4f1ec; font-size: 10px; margin-right: 3px; }
.bx-link-icon.ok { background: #d9f0db; color: #2d6a35; }

.bx-rep { font-size: 10.5px; color: #5a5650; line-height: 1.3; }
.bx-rep strong { color: #2c2a28; }
.bx-rep .lbl { color: #9a9690; font-size: 9.5px; }

.bx-cond { font-size: 10px; color: #5a5650; max-width: 220px; }
.bx-cond.empty { color: #c8c4be; font-style: italic; }
.bx-cond:hover { background: #fff; position: relative; }

.bx-renon { display: inline-block; padding: 2px 7px; border-radius: 99px; font-size: 10px; font-weight: 700; }
.bx-renon.oui { background: #d9f0db; color: #2d6a35; }
.bx-renon.non { background: #fef3c7; color: #92400e; }
.bx-renon.na  { background: #e9e6e0; color: #9a9690; }

.bx-empty { padding: 60px; text-align: center; color: #9a9690; }
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
?>

<div class="bx-wrap">

<?php
$nbActifs = 0; $nbBrouillons = 0; $totalLoyer = 0; $nbAvecLocataire = 0;
foreach ($rows as $r) {
    if ($r['statut'] === 'actif') $nbActifs++;
    if ($r['statut'] === 'brouillon') $nbBrouillons++;
    if (!empty($r['loyer_mensuel_hc']) && $r['statut'] === 'actif') $totalLoyer += (float)$r['loyer_mensuel_hc'];
    if (!empty($r['id_tiers_locataire'])) $nbAvecLocataire++;
}
?>

<div class="bx-stats">
    <div class="bx-stat"><strong><?= count($rows) ?></strong><span>Baux total</span></div>
    <div class="bx-stat"><strong><?= $nbActifs ?></strong><span>✓ Actifs</span></div>
    <div class="bx-stat"><strong><?= $nbBrouillons ?></strong><span>Brouillons</span></div>
    <div class="bx-stat"><strong><?= number_format($totalLoyer, 0, ',', ' ') ?> €</strong><span>Loyer base mensuel cumulé</span></div>
    <div class="bx-stat"><strong><?= $nbAvecLocataire ?>/<?= count($rows) ?></strong><span>Avec tiers locataire</span></div>
</div>

<div class="bx-table-wrap">
<?php if (!empty($err)): ?>
    <div class="bx-empty">
        ⚠️ Erreur SQL : <code><?= h($err) ?></code>
    </div>
<?php elseif (empty($rows)): ?>
    <div class="bx-empty">
        📭 Aucun bail encore créé.<br>
        <a href="<?= h(app_url('/transaction_chargement.php')) ?>">→ Va sur Chargement par lot pour analyser un bail et l'enregistrer</a>
    </div>
<?php else: ?>
    <table class="bx-table">
        <thead>
            <tr>
                <th>Bail</th>
                <th>Bien</th>
                <th>📍 Adresse</th>
                <th>🏢 Immeuble</th>
                <th>🏠 Propriétaire</th>
                <th>👥 Locataire</th>
                <th>👤 Repr. bailleur</th>
                <th>👥 Repr. locataire</th>
                <th>📅 Période</th>
                <th class="num">💶 Loyer/mois</th>
                <th class="num">💶 Charges</th>
                <th class="num">💶 DG</th>
                <th>📊 Indice</th>
                <th>🛡️ Renonc.</th>
                <th>📋 Conditions particulières</th>
                <th>🔗 Liens</th>
                <th>👤 Créé par</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $nat = $r['bail_nature'] ?: '?';
            $statut = $r['statut'] ?: 'brouillon';
            $locaName = $r['locataire_tiers_nom']
                     ?: $r['locataire_raison_sociale']
                     ?: trim((string)$r['locataire_prenom'] . ' ' . $r['locataire_nom'])
                     ?: '—';
            $proprioName = $r['proprio_tiers_nom'] ?: $r['proprio_nom_legacy'] ?: '—';
            $renon = $r['renonciation_recours_reciproque'];
            $renonClass = $renon === '1' || $renon === 1 ? 'oui' : ($renon === '0' || $renon === 0 ? 'non' : 'na');
            $renonLabel = $renon === '1' || $renon === 1 ? '✓ OUI' : ($renon === '0' || $renon === 0 ? '✗ NON' : '? n/a');
            $cond = (string)($r['conditions_particulieres'] ?? '');
        ?>
            <tr>
                <td>
                    <div class="ref">#<?= (int)$r['bail_id'] ?></div>
                    <span class="bx-pill-nat"><?= h($nat) ?></span>
                    <span class="bx-pill <?= h($statut) ?>"><?= h($statut) ?></span>
                </td>
                <td>
                    <?php if ($r['bien_id']): ?>
                        <a href="<?= h(app_url('/bien_detail.php?edit=' . $r['bien_id'])) ?>" target="_blank" class="ref">
                            <?= h($r['reference_bien'] ?: '#' . $r['bien_id']) ?>
                        </a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <div><?= h($r['bien_adresse'] ?: '—') ?></div>
                    <div style="font-size:10px; color:#7a766f;"><?= h(trim((string)$r['bien_cp'] . ' ' . $r['bien_ville'])) ?></div>
                </td>
                <td>
                    <?php if ($r['immeuble_id']): ?>
                        <a href="<?= h(app_url('/immeuble_360.php?id=' . $r['immeuble_id'])) ?>" target="_blank">
                            #<?= (int)$r['immeuble_id'] ?>
                        </a>
                        <div style="font-size:10px;"><?= h($r['nom_immeuble'] ?: '') ?></div>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <div><?= h($proprioName) ?></div>
                    <div style="font-size:10px; color:#7a766f;">
                        <?php if ($r['proprietaire_id']): ?>p#<?= (int)$r['proprietaire_id'] ?> <?php endif; ?>
                        <?php if ($r['proprio_tiers_id']): ?>· t#<?= (int)$r['proprio_tiers_id'] ?><?php endif; ?>
                    </div>
                </td>
                <td>
                    <div><?= h($locaName) ?></div>
                    <div style="font-size:10px; color:#7a766f;">
                        <?php if ($r['id_tiers_locataire']): ?>t#<?= (int)$r['id_tiers_locataire'] ?><?php endif; ?>
                        <?php if ($r['locataire_siren']): ?> · SIREN <?= h($r['locataire_siren']) ?><?php endif; ?>
                    </div>
                    <?php if ($r['locataire_email']): ?><div style="font-size:9.5px; color:#7a766f;">📧 <?= h($r['locataire_email']) ?></div><?php endif; ?>
                </td>
                <td class="bx-rep">
                    <?php if ($r['bailleur_representant_nom']): ?>
                        <strong><?= h($r['bailleur_representant_nom']) ?></strong>
                        <?php if ($r['bailleur_representant_qualite']): ?><div class="lbl"><?= h($r['bailleur_representant_qualite']) ?></div><?php endif; ?>
                        <?php if ($r['bailleur_representant_email']): ?><div>📧 <?= h($r['bailleur_representant_email']) ?></div><?php endif; ?>
                        <?php if ($r['bailleur_representant_telephone']): ?><div>📞 <?= h($r['bailleur_representant_telephone']) ?></div><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="bx-rep">
                    <?php if ($r['locataire_representant_nom']): ?>
                        <strong><?= h($r['locataire_representant_nom']) ?></strong>
                        <?php if ($r['locataire_representant_qualite']): ?><div class="lbl"><?= h($r['locataire_representant_qualite']) ?></div><?php endif; ?>
                        <?php if ($r['locataire_representant_email']): ?><div>📧 <?= h($r['locataire_representant_email']) ?></div><?php endif; ?>
                        <?php if ($r['locataire_representant_telephone']): ?><div>📞 <?= h($r['locataire_representant_telephone']) ?></div><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <div><?= h($r['date_prise_effet'] ?: '?') ?></div>
                    <div style="font-size:10px;">→ <?= h($r['date_fin'] ?: '?') ?></div>
                    <?php if ($r['duree_mois']): ?><div style="font-size:9.5px; color:#7a766f;"><?= (int)$r['duree_mois'] ?> mois</div><?php endif; ?>
                </td>
                <td class="num"><?= $r['loyer_mensuel_hc'] ? number_format((float)$r['loyer_mensuel_hc'], 0, ',', ' ') . ' €' : '—' ?></td>
                <td class="num"><?= $r['charges_mensuelles'] ? number_format((float)$r['charges_mensuelles'], 0, ',', ' ') . ' €' : '—' ?></td>
                <td class="num"><?= $r['depot_garantie'] ? number_format((float)$r['depot_garantie'], 0, ',', ' ') . ' €' : '—' ?></td>
                <td style="font-size:10.5px;">
                    <?php if ($r['indice_type']): ?>
                        <strong><?= h($r['indice_type']) ?></strong>
                        <?php if ($r['indice_trimestre']): ?><div style="color:#7a766f;"><?= h($r['indice_trimestre']) ?></div><?php endif; ?>
                        <?php if ($r['indice_valeur']): ?><div class="num"><?= h($r['indice_valeur']) ?></div><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="bx-renon <?= $renonClass ?>"><?= $renonLabel ?></span></td>
                <td class="bx-cond <?= $cond ? '' : 'empty' ?>" title="<?= h($cond) ?>">
                    <?= $cond ? h(mb_substr($cond, 0, 160)) . (mb_strlen($cond) > 160 ? '…' : '') : '—' ?>
                </td>
                <td style="font-size:10px;">
                    <span class="bx-link-icon ok" title="Bien"><?= $r['bien_id'] ? '✓ B' : '✗' ?></span>
                    <span class="bx-link-icon <?= $r['immeuble_id'] ? 'ok' : '' ?>"><?= $r['immeuble_id'] ? '✓ I' : '∅ I' ?></span>
                    <span class="bx-link-icon <?= $r['proprio_tiers_id'] ? 'ok' : '' ?>"><?= $r['proprio_tiers_id'] ? '✓ Tp' : '∅ Tp' ?></span>
                    <span class="bx-link-icon <?= $r['id_tiers_locataire'] ? 'ok' : '' ?>"><?= $r['id_tiers_locataire'] ? '✓ Tl' : '∅ Tl' ?></span>
                    <div style="margin-top:2px; color:#7a766f;">
                        roles: <?= (int)$r['nb_roles_bail'] ?>b · <?= (int)$r['nb_roles_bien'] ?>B
                        · 📎<?= (int)$r['nb_docs_ged'] ?>
                    </div>
                </td>
                <td style="font-size:10.5px;">
                    <?= h($r['createur'] ?: '—') ?>
                    <div style="font-size:9.5px; color:#7a766f;"><?= $r['created_at'] ? h(date('d/m/y H:i', strtotime($r['created_at']))) : '' ?></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<div style="margin-top:14px; font-size:11px; color:#7a766f;">
    <strong>Légende liens :</strong>
    <span class="bx-link-icon ok">✓ B</span> Bien rattaché ·
    <span class="bx-link-icon ok">✓ I</span> Immeuble lié ·
    <span class="bx-link-icon ok">✓ Tp</span> Tiers propriétaire ·
    <span class="bx-link-icon ok">✓ Tl</span> Tiers locataire ·
    <strong>roles:</strong> nb dans tiers_roles (objet_type=bail / bien) ·
    <strong>📎</strong> docs GED rattachés au bien
</div>

</div><!-- /bx-wrap -->

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
