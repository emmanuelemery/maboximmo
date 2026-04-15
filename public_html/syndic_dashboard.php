<?php
// syndic_dashboard.php — Dashboard Syndic V2 MaBoxImmo (bleu-gris)
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
$pdo    = db_connect();

// ── KPIs ────────────────────────────────────────────────────────────
$where_etab = '';
$bind_etab  = [];
if ($roleId >= 2) {
    $where_etab = 'WHERE i.id_etablissement = :etab';
    $bind_etab  = [':etab' => $_SESSION['id_etablissement'] ?? 0];
}

$kpi = $pdo->prepare("
    SELECT
        COUNT(DISTINCT i.id)                       AS nb_immeubles,
        COALESCE(SUM(i.nb_lots),0)                 AS total_lots,
        COALESCE(SUM(ii.honoraires_ht_2026),0)     AS total_honoraires,
        COUNT(DISTINCT CASE
            WHEN ii.date_ag_2026 BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            THEN i.id END)                         AS ag_prochaines
    FROM immeubles i
    LEFT JOIN immeubles_infos ii ON ii.id_immeuble = i.id
    $where_etab
");
$kpi->execute($bind_etab);
$k = $kpi->fetch(PDO::FETCH_ASSOC);

// ── Prochaines AG (90 jours) ────────────────────────────────────────
$ag_q = $pdo->prepare("
    SELECT i.id, i.reference, i.nom, i.ville, i.nb_lots,
           ii.date_ag_2026, ii.date_ag_2027
    FROM immeubles i
    JOIN immeubles_infos ii ON ii.id_immeuble = i.id
    " . ($where_etab ? str_replace('i.id_etablissement', 'i.id_etablissement', $where_etab) : '') . "
    HAVING (ii.date_ag_2026 BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY))
        OR (ii.date_ag_2027 BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY))
    ORDER BY LEAST(
        COALESCE(ii.date_ag_2026,'9999-12-31'),
        COALESCE(ii.date_ag_2027,'9999-12-31')
    )
    LIMIT 10
");
$ag_q->execute($bind_etab);
$ag_list = $ag_q->fetchAll(PDO::FETCH_ASSOC);

// ── Immeubles sans fiche info ───────────────────────────────────────
$sans_fiche_q = $pdo->prepare("
    SELECT i.id, i.reference, i.nom, i.ville
    FROM immeubles i
    LEFT JOIN immeubles_infos ii ON ii.id_immeuble = i.id
    " . ($where_etab ?: '') . "
    HAVING ii.id IS NULL
    LIMIT 8
");
$sans_fiche_q->execute($bind_etab);
$sans_fiche = $sans_fiche_q->fetchAll(PDO::FETCH_ASSOC);

// ── Derniers immeubles ajoutés ─────────────────────────────────────
$recents_q = $pdo->prepare("
    SELECT i.id, i.reference, i.nom, i.ville, i.type_immeuble, i.nb_lots,
           i.created_at
    FROM immeubles i
    " . ($where_etab ?: '') . "
    ORDER BY i.created_at DESC
    LIMIT 6
");
$recents_q->execute($bind_etab);
$recents = $recents_q->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ─────────────────────────────────────────────────────────
function typeBadgeD(string $t): string {
    $map = [
        'sdc'    => ['SDC',     '#4878a6','#d8e8f5'],
        'maison' => ['Maison',  '#4a8060','#d8eee3'],
        'local'  => ['Local',   '#8860a0','#eeddf8'],
        'garage' => ['Garage',  '#806840','#f5e8d0'],
    ];
    $v = $map[strtolower($t)] ?? ['Autre','#808080','#e8e8e8'];
    return '<span style="background:'.$v[2].';color:'.$v[0][0].';padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;font-family:\'DM Mono\',monospace;color:'.$v[1].'">'.$v[0].'</span>';
}
function agUrgency(string $date): string {
    $days = (int)((strtotime($date) - time()) / 86400);
    if ($days <= 14) return '#c84040';
    if ($days <= 30) return '#c87830';
    return '#4878a6';
}
function fmt_money(float $v): string {
    return number_format($v, 0, ',', ' ') . ' €';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Syndic — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/tokens.css">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/layout.css">
<link rel="stylesheet" href="css/theme-syndic.css">
<style>
/* ── KPI strip ───────────────────────────────────────────────── */
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.kpi-card{background:#ffffff;border-radius:16px;padding:18px 20px;box-shadow:6px 6px 16px #c8c4be,-6px -6px 14px #ffffff;display:flex;flex-direction:column;gap:6px}
.kpi-icon{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#8eb4d3,#4878a6);display:flex;align-items:center;justify-content:center;margin-bottom:4px}
.kpi-icon svg{width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.kpi-val{font-family:'Sora',sans-serif;font-size:28px;font-weight:700;color:#2c2a28;line-height:1}
.kpi-lbl{font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}

/* ── Quick actions ───────────────────────────────────────────── */
.quick-bar{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.qa-btn{display:flex;align-items:center;gap:7px;padding:9px 18px;border-radius:999px;background:#ffffff;box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #ffffff;text-decoration:none;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#4878a6;border:none;cursor:pointer;transition:box-shadow .15s}
.qa-btn:hover{box-shadow:2px 2px 6px #c8c4be,-2px -2px 6px #ffffff}
.qa-btn svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ── Two-column layout ───────────────────────────────────────── */
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}

/* ── Sections ────────────────────────────────────────────────── */
.dash-section{background:#ffffff;border-radius:18px;box-shadow:6px 6px 16px #c8c4be,-6px -6px 14px #ffffff;padding:20px}
.dash-section-title{font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#2c2a28;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.dash-section-title svg{width:15px;height:15px;stroke:#4878a6;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ── AG list items ───────────────────────────────────────────── */
.ag-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;background:#f7f8fa;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;margin-bottom:8px;text-decoration:none}
.ag-item:last-child{margin-bottom:0}
.ag-date-badge{flex-shrink:0;width:44px;text-align:center;border-radius:10px;padding:4px 0;font-family:'DM Mono',monospace;font-size:10px;font-weight:700;line-height:1.4}
.ag-info{flex:1;min-width:0}
.ag-nom{font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#1a1816;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ag-sub{font-family:'DM Mono',monospace;font-size:10px;color:#9a9690}
.ag-empty{font-family:'DM Mono',monospace;font-size:11px;color:#9a9690;text-align:center;padding:16px 0}

/* ── Alert incomplete ─────────────────────────────────────────── */
.alert-card{background:#fff3cd;border-radius:12px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px;font-family:'Sora',sans-serif;font-size:12px;color:#856404}
.alert-card svg{width:16px;height:16px;stroke:#856404;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;margin-top:1px}
.alert-links{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px}
.alert-link{font-size:11px;padding:2px 8px;border-radius:999px;background:#ffffff;box-shadow:2px 2px 5px #c8c4be,-2px -2px 5px #ffffff;color:#4878a6;text-decoration:none;font-weight:600}

/* ── Recent items ────────────────────────────────────────────── */
.recent-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:10px;transition:background .15s;text-decoration:none}
.recent-row:hover{background:#f7f8fa}
.recent-dot{width:8px;height:8px;border-radius:50%;background:linear-gradient(135deg,#8eb4d3,#4878a6);flex-shrink:0}
.recent-nom{font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#1a1816;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.recent-meta{font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;white-space:nowrap}
</style>
</head>
<body data-theme-module="syndic">

<?php include __DIR__ . '/sidebar_syndic.php'; ?>

<div class="sb-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-nav">
            <button class="btn-icon" onclick="history.back()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="btn-icon" onclick="history.forward()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
        </div>
        <div style="width:50px"></div>
        <div class="topbar-breadcrumb">
            <span class="topbar-module">Syndic</span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#9a9690" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            <span class="topbar-page">Dashboard</span>
        </div>
        <div class="topbar-spacer"></div>
        <div class="topbar-actions">
            <button class="btn-icon" title="Notifications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            </button>
            <div class="topbar-avatar"><?= htmlspecialchars($initials ?? 'U') ?></div>
        </div>
    </div>
    <!-- /Topbar -->

    <main class="page-main">

        <!-- Page head -->
        <div class="page-head">
            <div>
                <div class="page-module-lbl">Syndic · Copropriété</div>
                <h1 class="page-title">Dashboard</h1>
            </div>
            <div class="page-head-actions">
                <a href="syndic_immeubles.php" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Immeubles
                </a>
                <?php if ($roleId <= 2): ?>
                <a href="syndic_immeuble_form.php" class="btn btn-secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Ajouter immeuble
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sec head -->
        <div class="sec-head">
            <div class="line-l"></div>
            <div class="sec-txt">Vue d'ensemble du parc immobilier</div>
            <div class="line-r"></div>
        </div>

        <!-- KPI Strip -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <div class="kpi-val"><?= (int)($k['nb_immeubles'] ?? 0) ?></div>
                <div class="kpi-lbl">Immeubles</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <div class="kpi-val"><?= number_format((int)($k['total_lots'] ?? 0), 0, ',', ' ') ?></div>
                <div class="kpi-lbl">Total lots</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="kpi-val" style="font-size:20px"><?= fmt_money((float)($k['total_honoraires'] ?? 0)) ?></div>
                <div class="kpi-lbl">Honoraires HT 2026</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:linear-gradient(135deg,#e89a5a,#c87030)">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="kpi-val"><?= (int)($k['ag_prochaines'] ?? 0) ?></div>
                <div class="kpi-lbl">AG prochaines 90j</div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="quick-bar">
            <a href="syndic_immeubles.php" class="qa-btn">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                Liste immeubles
            </a>
            <?php if ($roleId <= 2): ?>
            <a href="syndic_immeuble_form.php" class="qa-btn">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Nouvel immeuble
            </a>
            <?php endif; ?>
            <a href="syndic_reunions.php" class="qa-btn">
                <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Réunions / AG
            </a>
            <a href="syndic_taches.php" class="qa-btn">
                <svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                Tâches
            </a>
            <a href="syndic_mandants.php" class="qa-btn">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Mandants
            </a>
            <a href="syndic_factures.php" class="qa-btn">
                <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                Factures
            </a>
        </div>

        <!-- Two-column dashboard -->
        <div class="dash-grid">

            <!-- Left: Prochaines AG -->
            <div class="dash-section">
                <div class="dash-section-title">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Prochaines Assemblées Générales
                    <span style="margin-left:auto;font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;font-weight:400">90 jours</span>
                </div>
                <?php if (empty($ag_list)): ?>
                    <div class="ag-empty">Aucune AG planifiée dans les 90 prochains jours</div>
                <?php else: ?>
                    <?php foreach ($ag_list as $ag):
                        $date_ag = $ag['date_ag_2026'] ?? $ag['date_ag_2027'] ?? null;
                        if (!$date_ag) continue;
                        $ts   = strtotime($date_ag);
                        $days = (int)(($ts - time()) / 86400);
                        $col  = agUrgency($date_ag);
                    ?>
                    <a href="syndic_immeuble_fiche.php?id=<?= $ag['id'] ?>" class="ag-item">
                        <div class="ag-date-badge" style="background:<?= $col ?>22;color:<?= $col ?>">
                            <div style="font-size:16px;font-weight:700;line-height:1"><?= date('d', $ts) ?></div>
                            <div style="font-size:9px;text-transform:uppercase"><?= date('M', $ts) ?></div>
                        </div>
                        <div class="ag-info">
                            <div class="ag-nom"><?= htmlspecialchars($ag['nom']) ?></div>
                            <div class="ag-sub"><?= htmlspecialchars($ag['ville']) ?> · <?= (int)$ag['nb_lots'] ?> lots · J-<?= $days ?></div>
                        </div>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9a9690" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                    <?php endforeach; ?>
                    <div style="margin-top:10px;text-align:right">
                        <a href="syndic_reunions.php" style="font-family:'DM Mono',monospace;font-size:10px;color:#4878a6;text-decoration:none">Toutes les réunions →</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Alerts + Recents -->
            <div style="display:flex;flex-direction:column;gap:20px">

                <?php if (!empty($sans_fiche) && $roleId <= 2): ?>
                <div class="dash-section">
                    <div class="dash-section-title">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Fiches incomplètes
                        <span style="margin-left:auto">
                            <span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:999px;font-family:'DM Mono',monospace;font-size:10px;font-weight:700"><?= count($sans_fiche) ?> immeuble<?= count($sans_fiche) > 1 ? 's' : '' ?></span>
                        </span>
                    </div>
                    <div class="alert-card">
                        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <div>
                            Ces immeubles n'ont pas encore de fiche d'informations (honoraires, AG, etc.)
                            <div class="alert-links">
                                <?php foreach ($sans_fiche as $sf): ?>
                                <a href="syndic_immeuble_fiche.php?id=<?= $sf['id'] ?>" class="alert-link"><?= htmlspecialchars($sf['reference'] ?: $sf['nom']) ?></a>
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
                        <a href="syndic_immeuble_fiche.php?id=<?= $r['id'] ?>" class="recent-row">
                            <div class="recent-dot"></div>
                            <div class="recent-nom"><?= htmlspecialchars($r['nom']) ?></div>
                            <div class="recent-meta"><?= htmlspecialchars($r['ville']) ?> · <?= (int)$r['nb_lots'] ?> lots</div>
                        </a>
                        <?php endforeach; ?>
                        <div style="margin-top:10px;text-align:right">
                            <a href="syndic_immeubles.php" style="font-family:'DM Mono',monospace;font-size:10px;color:#4878a6;text-decoration:none">Voir tous →</a>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
            <!-- /Right -->

        </div>
        <!-- /Two-column -->

    </main>
</div>

</body>
</html>
