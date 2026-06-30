<?php
/**
 * rh_conges_historiq_user.php — Historique congés SIMPLIFIÉ (provisoire).
 *
 * Version ultra-légère dérivée de `rh_conges_historiq.php` (qui est réservée
 * au rôle admin et trop chargée pour un premier accès collaborateur).
 *
 * Objectif : offrir au user un aperçu minimal de ses congés depuis le
 * 01/06/2025 afin qu'il puisse les valider en 1 clic. Un clic sur une ligne
 * déploie, INLINE sous le tableau, un mini-planning du mois concerné avec
 * les jours de congé surlignés — aucune nouvelle page, aucun filtre.
 *
 * Action POST : action=valider → users.user_conges_validated_at = NOW()
 *                              → retour dashboard avec flash.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$userId = current_user_id();

// ─── Traitement POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'valider') {
    $stmt = $pdo->prepare("UPDATE users SET user_conges_validated_at = NOW() WHERE id = ?");
    $stmt->execute([$userId]);

    $pdo->prepare("INSERT INTO audit_log (id_societe, id_user, action, table_name, record_id, new_values, ip_address)
        VALUES (?, ?, 'conges_validation', 'users', ?, ?, ?)")
        ->execute([
            (int)(current_societe_id() ?? 0),
            $userId,
            $userId,
            json_encode([
                'nom' => ($_SESSION['nom'] ?? ''),
                'prenom' => ($_SESSION['prenom'] ?? ''),
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            ], JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

    $_SESSION['flash_ok'] = 'Historique des congés validé. Merci !';
    header('Location: rh_dashboard_user.php');
    exit;
}

// ─── Lecture congés user depuis le 01/06/2025 ───────────────
$stmt = $pdo->prepare("
    SELECT id, date_debut, date_fin, motif, statut, commentaire
    FROM conges
    WHERE id_user = ? AND date_debut >= '2025-06-01'
    ORDER BY date_debut DESC
");
$stmt->execute([$userId]);
$conges = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statut de validation actuel
$stmt = $pdo->prepare("SELECT user_conges_validated_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$alreadyValidated = (bool)$stmt->fetchColumn();

// ─── Helpers ────────────────────────────────────────────────
$motifLabels = [
    'conges_payes'                    => 'Congés payés',
    'rtt'                             => 'RTT',
    'maladie_justifiee_non_deduite'   => 'Maladie justifiée',
    'maladie_non_justifiee_deduite'   => 'Maladie non justifiée',
    'maladie_justifiee_deduite'       => 'Maladie (déduite)',
    'absence_injustifiee_deduite'     => 'Absence injustifiée',
    'absence_justifiee_non_deduite'   => 'Absence justifiée',
    'absence_justifiee_deduite_heures'=> 'Absence (heures)',
    'autre_legal_non_deduit'          => 'Autre légal',
    'autre_legal_deduit'              => 'Autre légal (déduit)',
];

$holidays = ['01-01','05-01','05-08','07-14','08-15','11-01','11-11','12-25'];

/** Compte les jours ouvrés entre deux dates (samedis/dimanches/fériés exclus). */
function count_working_days_simple(string $start, string $end, array $holidays): int
{
    $d = new DateTime($start);
    $e = new DateTime($end);
    $e->modify('+1 day');
    $n = 0;
    for ($c = clone $d; $c < $e; $c->modify('+1 day')) {
        $dow = (int)$c->format('N');
        if ($dow < 6 && !in_array($c->format('m-d'), $holidays, true)) {
            $n++;
        }
    }
    return $n;
}

$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Préparation JSON des congés pour le mini-planning côté JS
$congesJson = [];
foreach ($conges as $c) {
    $congesJson[] = [
        'id'         => (int)$c['id'],
        'date_debut' => $c['date_debut'],
        'date_fin'   => $c['date_fin'],
        'motif'      => $motifLabels[$c['motif']] ?? ($c['motif'] ?? ''),
        'statut'     => $c['statut'] ?? '',
    ];
}

// ─── Rendu layout ──────────────────────────────────────────
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Mes congés — ' . htmlspecialchars($_userName, ENT_QUOTES, 'UTF-8');
$layout_module  = 'Mon espace · Collaborateur';
$layout_sidebar = 'rh_sidebar';
$layout_hide_page_head = true; // pas de page-head sur les pages user

$layout_extra_css = <<<'CSS'
<style>
.hp-intro {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 18px 22px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 14px;
}
.hp-intro .ico {
    width: 46px; height: 46px; border-radius: 12px;
    background: #4878a6; color: #fff; font-size: 22px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.hp-intro h3 { font-size: 14px; color: #2f587d; margin-bottom: 3px; }
.hp-intro p  { font-size: 12px; color: #6a6660; line-height: 1.5; }

.hp-table-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    overflow: hidden;
    margin-bottom: 18px;
}
.hp-table { width: 100%; border-collapse: collapse; }
.hp-table thead th {
    padding: 12px 16px; text-align: left; white-space: nowrap;
    font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
    letter-spacing: .12em; text-transform: uppercase; color: #4a6038;
    border-bottom: 1px solid rgba(196,192,186,0.5);
    background: #f0f1f3;
}
.hp-table tbody td {
    padding: 12px 16px; font-size: 12px; color: #1a1816;
    border-bottom: 1px solid rgba(196,192,186,0.25);
}
.hp-table tbody tr { cursor: pointer; transition: background .15s; }
.hp-table tbody tr:hover td { background: rgba(72,120,166,0.06); }
.hp-table tbody tr.active td { background: rgba(72,120,166,0.12); color: #2f587d; font-weight: 600; }
.hp-table tbody tr:last-child td { border-bottom: none; }

.hp-badge {
    display: inline-block; padding: 3px 10px; border-radius: 999px;
    font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    box-shadow: 1px 1px 3px rgba(180,185,175,0.4), -1px -1px 3px #fff;
}
.hp-badge.valide { color: #4a6038; background: #e8efe0; }
.hp-badge.attente { color: #7a6830; background: #f2ead8; }
.hp-badge.refuse { color: #8a5040; background: #f0e2da; }

/* Mini planning */
.hp-plan-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 20px 22px;
    margin-bottom: 18px;
    display: none;
}
.hp-plan-card.visible { display: block; }
.hp-plan-header {
    display: flex; align-items: center; justify-content: center; gap: 14px;
    margin-bottom: 14px;
}
.hp-plan-card h4 {
    font-size: 13px; font-weight: 700; color: #2f587d;
    text-transform: uppercase; letter-spacing: .06em;
    min-width: 220px; text-align: center; margin: 0;
}
.hp-plan-nav {
    width: 34px; height: 34px; border-radius: 8px;
    background: #ffffff; color: #4878a6;
    border: none; cursor: pointer;
    box-shadow: 2px 2px 6px #d4d7de, -2px -2px 6px #fff;
    font-size: 14px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    transition: transform .15s, color .15s, box-shadow .15s;
}
.hp-plan-nav:hover { color: #2f587d; transform: translateY(-1px); box-shadow: 3px 3px 8px #d4d7de, -3px -3px 8px #fff; }
.hp-plan-nav:active { box-shadow: inset 2px 2px 4px #d4d7de, inset -2px -2px 4px #fff; }
.hp-cal {
    display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;
    max-width: 520px;
}
.hp-cal .dow {
    font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
    text-transform: uppercase; color: #8a8680; text-align: center;
    padding: 4px 0;
}
.hp-cal .cell {
    aspect-ratio: 1 / 1; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Mono', monospace; font-size: 11px; color: #6a6660;
    background: #f0f1f3;
    box-shadow: inset 1px 1px 3px #d4d7de, inset -1px -1px 3px #fff;
}
.hp-cal .cell.empty { background: transparent; box-shadow: none; }
.hp-cal .cell.weekend { color: #b8b4ae; background: #d4d0ca; }
.hp-cal .cell.holiday { color: #b8b4ae; background: #d4d0ca; font-style: italic; }
.hp-cal .cell.conge {
    background: #4878a6; color: #fff; font-weight: 700;
    box-shadow: 2px 2px 5px #9a9a9a, -2px -2px 5px #fff;
}
.hp-cal .cell.today { outline: 2px solid #4a6038; outline-offset: -2px; }
.hp-plan-legend {
    display: flex; gap: 16px; margin-top: 14px;
    font-size: 10px; color: #8a8680;
}
.hp-plan-legend span { display: inline-flex; align-items: center; gap: 5px; }
.hp-plan-legend i {
    width: 12px; height: 12px; border-radius: 3px; display: inline-block;
}

.hp-validate {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 22px 26px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 18px; flex-wrap: wrap;
}
.hp-validate p { font-size: 12px; color: #6a6660; line-height: 1.55; max-width: 520px; }
.hp-btn {
    padding: 11px 22px; border-radius: 10px; border: none;
    background: #4a6038; color: #fff;
    font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700;
    cursor: pointer; text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    box-shadow: 3px 3px 8px #d4d7de, -3px -3px 8px #fff;
}
.hp-btn:hover { opacity: .92; }
.hp-btn:active { box-shadow: inset 2px 2px 5px rgba(0,0,0,0.2); }
.hp-btn.back { background: #ffffff; color: #6a6660; }
.hp-btn.back:hover { color: #2f587d; }

.hp-empty {
    padding: 30px; text-align: center; color: #8a8680; font-size: 13px;
}
.hp-validated-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border-radius: 10px;
    background: #e8efe0; color: #4a6038;
    font-size: 12px; font-weight: 600;
    box-shadow: inset 2px 2px 5px rgba(180,190,170,0.5), inset -2px -2px 5px #fff;
}
</style>
CSS;

ob_start();
?>

<div class="hp-intro">
    <div class="ico">🏖️</div>
    <div>
        <h3>Validez votre historique de congés</h3>
        <p>Parcourez rapidement les entrées enregistrées depuis le 01/06/2025. Cliquez une ligne pour voir le planning du mois concerné, puis validez l'ensemble en un clic pour partir sur des bases communes.</p>
    </div>
</div>

<div class="hp-table-card">
    <?php if (empty($conges)): ?>
        <div class="hp-empty">
            ✨ Aucun congé enregistré depuis le 01/06/2025. Vous pouvez valider l'historique directement.
        </div>
    <?php else: ?>
    <table class="hp-table">
        <thead>
            <tr>
                <th>Début</th>
                <th>Fin</th>
                <th>Jours ouvrés</th>
                <th>Motif</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($conges as $idx => $c):
            $nbJo = count_working_days_simple($c['date_debut'], $c['date_fin'], $holidays);
            $st = $c['statut'] ?? '';
            $stClass = $st === 'validé' ? 'valide' : ($st === 'refusé' ? 'refuse' : 'attente');
        ?>
            <tr data-idx="<?= $idx ?>" onclick="showPlanning(<?= $idx ?>)">
                <td><?= $e(date('d/m/Y', strtotime($c['date_debut']))) ?></td>
                <td><?= $e(date('d/m/Y', strtotime($c['date_fin']))) ?></td>
                <td><?= $nbJo ?> j</td>
                <td><?= $e($motifLabels[$c['motif']] ?? $c['motif'] ?? '—') ?></td>
                <td><span class="hp-badge <?= $stClass ?>"><?= $e($st ?: 'en attente') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Mini planning inline (apparaît au clic sur une ligne) -->
<div id="hp-plan" class="hp-plan-card">
    <div class="hp-plan-header">
        <button type="button" class="hp-plan-nav" onclick="planningNav(-1)" title="Mois précédent">◀</button>
        <h4 id="hp-plan-title">Planning du mois</h4>
        <button type="button" class="hp-plan-nav" onclick="planningNav(1)" title="Mois suivant">▶</button>
    </div>
    <div class="hp-cal" id="hp-cal"></div>
    <div class="hp-plan-legend">
        <span><i style="background:#4878a6"></i> Congé</span>
        <span><i style="background:#d4d0ca"></i> Week-end / férié</span>
        <span><i style="background:#f0f1f3"></i> Jour ouvré</span>
    </div>
</div>

<!-- Bloc validation -->
<div class="hp-validate">
    <p>
        En validant, vous confirmez avoir vérifié l'historique de vos congés.
        Cette action marque la tâche « Valider mes congés » comme complétée sur votre tableau de bord.
    </p>
    <?php if ($alreadyValidated): ?>
        <span class="hp-validated-badge">✓ Historique déjà validé</span>
    <?php else: ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="valider">
            <button type="submit" class="hp-btn">✓ Je valide mon historique</button>
        </form>
    <?php endif; ?>
    <a href="rh_dashboard_user.php" class="hp-btn back">← Retour au tableau de bord</a>
</div>

<script>
// Données des congés injectées côté PHP
const CONGES = <?= json_encode($congesJson, JSON_UNESCAPED_UNICODE) ?>;
const HOLIDAYS = <?= json_encode($holidays) ?>; // format 'MM-DD'
const MOIS_FR = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet',
                 'Août','Septembre','Octobre','Novembre','Décembre'];
const DOW_FR = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

function parseDate(s) { return new Date(s + 'T00:00:00'); }
function pad(n) { return n < 10 ? '0' + n : '' + n; }

/**
 * État courant du mini-planning (mois affiché).
 * Initialisé au clic sur une ligne, modifié par les boutons ◀ / ▶.
 */
let activeIdx    = -1;
let currentYear  = null;
let currentMonth = null;

/**
 * Déclenché par le clic sur une ligne du tableau des congés.
 * Ouvre/ferme la card et cadre le planning sur le mois du congé cliqué.
 */
function showPlanning(idx) {
    const trs  = document.querySelectorAll('.hp-table tbody tr');
    const card = document.getElementById('hp-plan');

    if (activeIdx === idx) {
        // Toggle off
        card.classList.remove('visible');
        trs.forEach(tr => tr.classList.remove('active'));
        activeIdx = -1;
        currentYear = null;
        currentMonth = null;
        return;
    }

    trs.forEach(tr => tr.classList.remove('active'));
    trs[idx].classList.add('active');
    activeIdx = idx;

    const debut  = parseDate(CONGES[idx].date_debut);
    currentYear  = debut.getFullYear();
    currentMonth = debut.getMonth(); // 0-11

    renderMonth(currentYear, currentMonth);
    card.classList.add('visible');
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/**
 * Navigation mois précédent / suivant depuis les boutons ◀ ▶ du header.
 * @param {number} delta -1 ou +1
 */
function planningNav(delta) {
    if (currentYear === null || currentMonth === null) return;
    currentMonth += delta;
    if (currentMonth < 0)  { currentMonth = 11; currentYear -= 1; }
    if (currentMonth > 11) { currentMonth = 0;  currentYear += 1; }
    renderMonth(currentYear, currentMonth);
}

/**
 * Rendu du calendrier pour un mois donné. Affiche TOUS les congés du
 * mois (pas uniquement celui cliqué) — cohérent pour la navigation libre.
 */
function renderMonth(year, month) {
    document.getElementById('hp-plan-title').textContent =
        MOIS_FR[month] + ' ' + year;

    const cal = document.getElementById('hp-cal');
    cal.innerHTML = '';

    // En-tête des jours de la semaine
    DOW_FR.forEach(d => {
        const e = document.createElement('div');
        e.className = 'dow';
        e.textContent = d;
        cal.appendChild(e);
    });

    // Offset pour aligner le 1er du mois sur le bon jour (lundi en tête)
    const firstDay = new Date(year, month, 1);
    const firstDow = (firstDay.getDay() + 6) % 7;  // 0=dim → 6, 1=lun → 0, …
    for (let i = 0; i < firstDow; i++) {
        const e = document.createElement('div');
        e.className = 'cell empty';
        cal.appendChild(e);
    }

    const nbDays = new Date(year, month + 1, 0).getDate();
    const today  = new Date(); today.setHours(0, 0, 0, 0);

    for (let d = 1; d <= nbDays; d++) {
        const date = new Date(year, month, d);
        const dow  = date.getDay(); // 0 = dimanche
        const mmdd = pad(month + 1) + '-' + pad(d);
        const cell = document.createElement('div');
        cell.className  = 'cell';
        cell.textContent = d;

        // Ce jour appartient-il à AU MOINS UN congé du historique ?
        let isConge = false;
        for (const c of CONGES) {
            const cDeb = parseDate(c.date_debut);
            const cFin = parseDate(c.date_fin);
            if (date >= cDeb && date <= cFin) { isConge = true; break; }
        }

        if (isConge) {
            cell.classList.add('conge');
        } else if (dow === 0 || dow === 6) {
            cell.classList.add('weekend');
        } else if (HOLIDAYS.indexOf(mmdd) !== -1) {
            cell.classList.add('holiday');
        }
        if (date.getTime() === today.getTime()) {
            cell.classList.add('today');
        }
        cal.appendChild(cell);
    }
}
</script>

<?php
$layout_content = ob_get_clean();
require __DIR__ . '/inc/layout_maboximmo.php';
