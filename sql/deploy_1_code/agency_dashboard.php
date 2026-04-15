<?php
// agency_dashboard.php — Dashboard Syndic (layout_maboximmo)
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
$pdo    = $GLOBALS['pdo'];

// ── KPIs ────────────────────────────────────────────────────────────
$where_etab = '';
$bind_etab  = [];
if ($roleId >= 2) {
    $where_etab = 'WHERE i.id_agence = :etab';
    $bind_etab  = [':etab' => $_SESSION['etablissement_id'] ?? $_SESSION['id_etablissement'] ?? 0];
}

try {
    $kpi = $pdo->prepare("
        SELECT
            COUNT(DISTINCT i.id) AS nb_immeubles,
            COALESCE(SUM(i.nb_lots),0) AS total_lots,
            COALESCE(SUM(ii.honoraires_ht),0) AS total_honoraires,
            COUNT(DISTINCT CASE WHEN ii.date_ag_prochaine BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN i.id END) AS ag_prochaines
        FROM immeubles i
        LEFT JOIN immeubles_infos ii ON ii.id_immeuble = i.id
        $where_etab
    ");
    $kpi->execute($bind_etab);
    $k = $kpi->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $k = ['nb_immeubles'=>0,'total_lots'=>0,'total_honoraires'=>0,'ag_prochaines'=>0];
}

// ── Prochaines AG ───────────────────────────────────────────────────
try {
    $ag_q = $pdo->prepare("
        SELECT i.id, i.reference_immeuble AS reference, i.nom_immeuble AS nom,
               i.ville, i.nb_lots, ii.date_ag_prochaine AS date_ag
        FROM immeubles i
        JOIN immeubles_infos ii ON ii.id_immeuble = i.id
        " . ($where_etab ? $where_etab . " AND ii.date_ag_prochaine BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)" : "WHERE ii.date_ag_prochaine BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)") . "
        ORDER BY ii.date_ag_prochaine ASC LIMIT 10
    ");
    $ag_q->execute($bind_etab);
    $ag_list = $ag_q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ag_list = []; }

// ── Immeubles sans fiche ────────────────────────────────────────────
try {
    $sf_q = $pdo->prepare("
        SELECT i.id, i.reference_immeuble AS reference, i.nom_immeuble AS nom, i.ville
        FROM immeubles i
        LEFT JOIN immeubles_infos ii ON ii.id_immeuble = i.id
        " . ($where_etab ? $where_etab . " AND ii.id IS NULL" : "WHERE ii.id IS NULL") . "
        LIMIT 8
    ");
    $sf_q->execute($bind_etab);
    $sans_fiche = $sf_q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $sans_fiche = []; }

// ── Derniers immeubles ──────────────────────────────────────────────
try {
    $rec_q = $pdo->prepare("
        SELECT i.id, i.reference_immeuble AS reference, i.nom_immeuble AS nom,
               i.ville, i.nb_lots, i.date_creation
        FROM immeubles i " . ($where_etab ?: '') . "
        ORDER BY i.date_creation DESC LIMIT 6
    ");
    $rec_q->execute($bind_etab);
    $recents = $rec_q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $recents = []; }

function fmt_money(float $v): string { return number_format($v, 0, ',', ' ') . ' €'; }
function agUrgency(string $date): string {
    $days = (int)((strtotime($date) - time()) / 86400);
    if ($days <= 14) return '#8a5040';
    if ($days <= 30) return '#7a6830';
    return '#2f587d';
}

// ── Layout ──────────────────────────────────────────────────────────
$layout_title    = 'Dashboard Agency';
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)($k['nb_immeubles'] ?? 0).'</div><div class="ph-kpi-lbl">Immeubles</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.number_format((int)($k['total_lots'] ?? 0),0,',',' ').'</div><div class="ph-kpi-lbl">Lots</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.fmt_money((float)($k['total_honoraires'] ?? 0)).'</div><div class="ph-kpi-lbl">Hono. HT</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.(int)($k['ag_prochaines'] ?? 0).'</div><div class="ph-kpi-lbl">AG 90j</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.count($sans_fiche).'</div><div class="ph-kpi-lbl">Incomplets</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.count($recents).'</div><div class="ph-kpi-lbl">Récents</div></div>
';

$layout_head_actions = '
    <a href="agency_immeubles.php" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        Immeubles
    </a>
    <a href="agency_immeuble_form.php" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Ajouter
    </a>
    <a href="agency_reunions.php" class="ph-btn">Réunions</a>
    <a href="agency_taches.php" class="ph-btn">Tâches</a>
';

$layout_extra_css = '<style>
.dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.dash-section {
    background: var(--bg-primary, var(--bg-primary,#e4e8f0));
    border-radius: 16px;
    box-shadow: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #fff);
    padding: 18px 20px;
}
.dash-section-title {
    font-size: 13px; font-weight: 700; color: #2f587d;
    margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}
.dash-section-title svg { width:15px; height:15px; stroke:#4878a6; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.ag-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; border-radius: 12px;
    background: var(--bg-secondary, var(--bg-secondary,#eef1f6));
    box-shadow: inset 2px 2px 5px rgba(196,192,186,0.4), inset -2px -2px 5px #fff;
    margin-bottom: 8px; text-decoration: none;
}
.ag-item:last-child { margin-bottom: 0; }
.ag-date-badge {
    flex-shrink: 0; width: 44px; text-align: center; border-radius: 10px;
    padding: 4px 0; font-family: "DM Mono", monospace; font-size: 10px; font-weight: 700; line-height: 1.4;
}
.ag-info { flex: 1; min-width: 0; }
.ag-nom { font-size: 12px; font-weight: 600; color: #1a1816; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ag-sub { font-family: "DM Mono", monospace; font-size: 10px; color: #a8a49e; }
.ag-empty { font-family: "DM Mono", monospace; font-size: 11px; color: #a8a49e; text-align: center; padding: 16px 0; }
.alert-card {
    background: #f2edd8; border-radius: 12px; padding: 10px 14px; margin-bottom: 14px;
    display: flex; align-items: flex-start; gap: 10px; font-size: 12px; color: #7a6830;
}
.alert-card svg { width:16px; height:16px; stroke:#7a6830; fill:none; stroke-width:2; flex-shrink:0; margin-top:1px; }
.alert-links { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px; }
.alert-link {
    font-size: 11px; padding: 2px 8px; border-radius: 999px;
    background: var(--bg-primary, var(--bg-primary,#e4e8f0));
    box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
    color: #2f587d; text-decoration: none; font-weight: 600;
}
.recent-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; border-radius: 10px; transition: background .15s; text-decoration: none;
}
.recent-row:hover { background: var(--bg-secondary, var(--bg-secondary,#eef1f6)); }
.recent-dot { width: 8px; height: 8px; border-radius: 50%; background: #2f587d; flex-shrink: 0; }
.recent-nom { font-size: 12px; font-weight: 600; color: #1a1816; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.recent-meta { font-family: "DM Mono", monospace; font-size: 10px; color: #a8a49e; white-space: nowrap; }
@media (max-width: 900px) { .dash-grid { grid-template-columns: 1fr; } }
</style>';

// ── Contenu ─────────────────────────────────────────────────────────
ob_start();
?>

<!-- Prochaines AG + Alertes/Récents -->
<div class="dash-grid">

    <!-- Prochaines AG -->
    <div class="dash-section">
        <div class="dash-section-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Prochaines AG
            <span style="margin-left:auto;font-family:'DM Mono',monospace;font-size:10px;color:#a8a49e;font-weight:400">90 jours</span>
        </div>
        <?php if (empty($ag_list)): ?>
            <div class="ag-empty">Aucune AG planifiée dans les 90 prochains jours</div>
        <?php else: ?>
            <?php foreach ($ag_list as $ag):
                $date_ag = $ag['date_ag'] ?? null;
                if (!$date_ag) continue;
                $ts   = strtotime($date_ag);
                $days = (int)(($ts - time()) / 86400);
                $col  = agUrgency($date_ag);
            ?>
            <a href="agency_immeuble_fiche.php?id=<?= $ag['id'] ?>" class="ag-item">
                <div class="ag-date-badge" style="background:<?= $col ?>18;color:<?= $col ?>">
                    <div style="font-size:16px;font-weight:700;line-height:1"><?= date('d', $ts) ?></div>
                    <div style="font-size:9px;text-transform:uppercase"><?= date('M', $ts) ?></div>
                </div>
                <div class="ag-info">
                    <div class="ag-nom"><?= htmlspecialchars($ag['nom']) ?></div>
                    <div class="ag-sub"><?= htmlspecialchars($ag['ville']) ?> · <?= (int)$ag['nb_lots'] ?> lots · J-<?= $days ?></div>
                </div>
            </a>
            <?php endforeach; ?>
            <div style="margin-top:10px;text-align:right">
                <a href="agency_reunions.php" style="font-family:'DM Mono',monospace;font-size:10px;color:#2f587d;text-decoration:none">Toutes les réunions →</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Alertes + Récents -->
    <div style="display:flex;flex-direction:column;gap:20px">

        <?php if (!empty($sans_fiche) && $roleId <= 2): ?>
        <div class="dash-section">
            <div class="dash-section-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Fiches incomplètes
                <span style="margin-left:auto">
                    <span class="mbi-badge" style="background:#f2edd8;color:#7a6830"><?= count($sans_fiche) ?></span>
                </span>
            </div>
            <div class="alert-card">
                <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    Ces immeubles n'ont pas de fiche d'informations
                    <div class="alert-links">
                        <?php foreach ($sans_fiche as $sf): ?>
                        <a href="agency_immeuble_fiche.php?id=<?= $sf['id'] ?>" class="alert-link"><?= htmlspecialchars($sf['reference'] ?: $sf['nom']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dash-section">
            <div class="dash-section-title">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Derniers immeubles ajoutés
            </div>
            <?php if (empty($recents)): ?>
                <div class="ag-empty">Aucun immeuble enregistré</div>
            <?php else: ?>
                <?php foreach ($recents as $r): ?>
                <a href="agency_immeuble_fiche.php?id=<?= $r['id'] ?>" class="recent-row">
                    <div class="recent-dot"></div>
                    <div class="recent-nom"><?= htmlspecialchars($r['nom']) ?></div>
                    <div class="recent-meta"><?= htmlspecialchars($r['ville']) ?> · <?= (int)$r['nb_lots'] ?> lots</div>
                </a>
                <?php endforeach; ?>
                <div style="margin-top:10px;text-align:right">
                    <a href="agency_immeubles.php" style="font-family:'DM Mono',monospace;font-size:10px;color:#2f587d;text-decoration:none">Voir tous →</a>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
