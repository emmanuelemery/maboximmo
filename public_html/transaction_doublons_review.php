<?php
// transaction_doublons_review.php — Revue manuelle des biens à référence dupliquée.
// Affiche chaque groupe de biens partageant la même reference_bien, avec leurs données,
// et permet de supprimer le doublon choisi (API doublon_delete.php, déclenché par l'utilisateur).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

// Groupes de référence en double.
$refs = $pdo->query("SELECT reference_bien FROM biens
                     WHERE reference_bien IS NOT NULL AND reference_bien <> ''
                     GROUP BY reference_bien HAVING COUNT(*) > 1
                     ORDER BY reference_bien")->fetchAll(PDO::FETCH_COLUMN);

$groupes = [];
if ($refs) {
    $st = $pdo->prepare("SELECT b.id, b.reference_bien, b.numero_lot, b.surface_habitable, b.statut_bien,
        COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
        COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS cp,
        COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
        b.id_immeuble, b.date_creation,
        (SELECT COUNT(*) FROM crg_situations_locataires s WHERE s.id_bien=b.id) AS crg,
        (SELECT COUNT(*) FROM bien_prix p WHERE p.id_bien=b.id) AS prix,
        (SELECT COUNT(*) FROM bien_baux x WHERE x.id_bien=b.id) AS baux,
        (SELECT COUNT(*) FROM annonces a WHERE a.id_bien=b.id) AS ann,
        (SELECT COUNT(*) FROM mandats m WHERE m.id_bien=b.id AND m.statut='actif') AS mand,
        (SELECT COUNT(*) FROM locataires_statuts ls WHERE ls.id_bien=b.id) AS locst
        FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
        WHERE b.reference_bien = ? ORDER BY b.id");
    foreach ($refs as $ref) {
        $st->execute([$ref]);
        $biens = $st->fetchAll(PDO::FETCH_ASSOC);
        // Score pour recommander le gardien.
        foreach ($biens as &$bb) {
            $bb['score'] = $bb['crg'] * 1000 + $bb['baux'] * 100 + $bb['locst'] * 10 + $bb['prix'];
            $bb['crg_lock'] = $bb['crg'] > 0 || $bb['ann'] > 0 || $bb['mand'] > 0; // non supprimable
        }
        unset($bb);
        $maxScore = max(array_column($biens, 'score'));
        $groupes[$ref] = ['biens' => $biens, 'maxScore' => $maxScore];
    }
}

$pageTitle    = 'Doublons — Revue';
$pageSubtitle = 'Ma Box Agency · Nettoyage';
$bodyAttr     = 'data-theme-module="transaction"';
$extraCss = <<<'CSS'
<style>
.dr-wrap { max-width:1100px; }
.dr-head { margin-bottom:16px; }
.dr-grp { background:#fff; border-radius:12px; box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff; margin-bottom:16px; overflow:hidden; }
.dr-grp-title { background:#f4f1ec; padding:10px 16px; font-family:'DM Mono',monospace; font-weight:700; color:#4878a6; }
.dr-row { display:flex; align-items:center; gap:14px; padding:12px 16px; border-bottom:1px solid #f0ece6; }
.dr-row:last-child { border-bottom:none; }
.dr-row.keep { background:#f1f6fb; }
.dr-row.gone { opacity:.4; }
.dr-main { flex:1; min-width:0; }
.dr-adr { font-weight:700; color:#2c2a28; }
.dr-meta { font-size:12px; color:#6a665f; margin-top:3px; display:flex; flex-wrap:wrap; gap:10px; }
.dr-badge { font-size:10.5px; padding:1px 7px; border-radius:99px; font-weight:700; }
.dr-b-crg { background:#e6dcf2; color:#4a2e7a; }
.dr-b-data { background:#d9f0db; color:#2d6a35; }
.dr-b-arch { background:#e9e6e0; color:#5a5650; }
.dr-tag-keep { display:inline-block; background:#d9f0db; color:#2d6a35; font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; }
.dr-del { border:1px solid #d9a3a3; background:#fff; color:#a8323b; border-radius:8px; padding:8px 14px; cursor:pointer; font-size:13px; white-space:nowrap; }
.dr-del:hover { background:#fbe9e9; }
.dr-del:disabled { opacity:.4; cursor:not-allowed; }
.dr-empty { padding:50px; text-align:center; color:#2d6a35; font-size:15px; }
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<div class="dr-wrap">
    <div class="dr-head">
        <h2 style="margin:0;font-size:20px;color:#2c2a28;">🧹 Revue des doublons (référence identique)</h2>
        <div style="font-size:12.5px;color:#7a766f;margin-top:2px;">
            Le bien <b>recommandé à conserver</b> (le plus de données) est surligné. Les biens rattachés au CRG / avec annonce / mandat actif ne sont pas supprimables.
        </div>
    </div>

    <?php if (empty($groupes)): ?>
        <div class="dr-empty">✅ Aucune référence en double. Rien à nettoyer.</div>
    <?php else: ?>
        <?php foreach ($groupes as $ref => $g): ?>
            <div class="dr-grp" data-ref="<?= $e($ref) ?>">
                <div class="dr-grp-title"><?= $e($ref) ?> · <?= count($g['biens']) ?> biens</div>
                <?php foreach ($g['biens'] as $b): ?>
                    <?php $isKeep = ($b['score'] === $g['maxScore']); ?>
                    <div class="dr-row <?= $isKeep ? 'keep' : '' ?>" data-id="<?= (int)$b['id'] ?>">
                        <div class="dr-main">
                            <div class="dr-adr">
                                <?= $e($b['adresse'] !== null && $b['adresse'] !== '' ? $b['adresse'] : 'Adresse non renseignée') ?>
                                <?php if ($b['ville']): ?> · <?= $e(trim($b['cp'] . ' ' . $b['ville'])) ?><?php endif; ?>
                                <?php if ($isKeep): ?> <span class="dr-tag-keep">✓ à conserver</span><?php endif; ?>
                            </div>
                            <div class="dr-meta">
                                <span>#<?= (int)$b['id'] ?></span>
                                <span>imm <?= $e($b['id_immeuble'] ?? '—') ?></span>
                                <span>lot <?= $e($b['numero_lot'] ?? '—') ?></span>
                                <span><?= $b['surface_habitable'] ? $e(fmt_m2($b['surface_habitable'], 0)) : '—' ?></span>
                                <?php if ($b['crg']): ?><span class="dr-badge dr-b-crg">CRG ×<?= (int)$b['crg'] ?></span><?php endif; ?>
                                <?php if ($b['baux']): ?><span class="dr-badge dr-b-data">bail</span><?php endif; ?>
                                <?php if ($b['prix']): ?><span class="dr-badge dr-b-data">prix</span><?php endif; ?>
                                <?php if ($b['locst']): ?><span class="dr-badge dr-b-data">locataire</span><?php endif; ?>
                                <?php if ($b['mand']): ?><span class="dr-badge dr-b-data">mandat actif</span><?php endif; ?>
                                <?php if ($b['ann']): ?><span class="dr-badge dr-b-data">annonce</span><?php endif; ?>
                                <?php if ($b['statut_bien'] === 'archive'): ?><span class="dr-badge dr-b-arch">archivé</span><?php endif; ?>
                                <span style="color:#a8a39a;">créé <?= $e(substr((string)$b['date_creation'], 0, 10)) ?></span>
                            </div>
                        </div>
                        <button class="dr-del" data-id="<?= (int)$b['id'] ?>"
                                <?= $b['crg_lock'] ? 'disabled title="Protégé (CRG / annonce / mandat actif)"' : '' ?>
                                onclick="drDelete(this)">🗑 Supprimer</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
const DR_CSRF = <?= json_encode(csrf_token('doublon_delete')) ?>;
async function drDelete(btn) {
    const id = btn.dataset.id;
    if (!confirm('Supprimer définitivement le bien #' + id + ' ?')) return;
    btn.disabled = true; btn.textContent = '…';
    try {
        const fd = new FormData(); fd.append('id_bien', id);
        const res = await fetch(<?= json_encode(app_url('/api/doublon_delete.php')) ?>, { method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token': DR_CSRF}, body: fd });
        const j = await res.json();
        if (!j.success) { alert(j.message || 'Erreur.'); btn.disabled = false; btn.textContent = '🗑 Supprimer'; return; }
        const row = btn.closest('.dr-row'); row.classList.add('gone');
        btn.textContent = 'supprimé';
        // si le groupe n'a plus qu'une ligne visible, on le masque
        const grp = btn.closest('.dr-grp');
        const left = [...grp.querySelectorAll('.dr-row')].filter(r => !r.classList.contains('gone'));
        if (left.length <= 1) grp.style.opacity = '.5';
    } catch (e) { alert('Erreur réseau.'); btn.disabled = false; btn.textContent = '🗑 Supprimer'; }
}
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
