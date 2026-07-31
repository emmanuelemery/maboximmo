<?php
/**
 * annonce_creation.php — Page incassable de création d'annonce en saisie manuelle.
 *
 * Flow en 2 étapes :
 *   1. Sélection du bien à diffuser (si aucun id_annonce fourni).
 *   2. Saisie des champs de l'annonce (SHOW COLUMNS) avec autosave.
 *
 * Bouton "Publier" en bas pour passer etat_publication → 'publie'.
 * Aucune IA, aucune dépendance externe, tout fonctionne en saisie manuelle.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']    ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;

$annonceId = isset($_GET['id_annonce']) && ctype_digit((string)$_GET['id_annonce']) ? (int)$_GET['id_annonce'] : 0;
$bienId    = isset($_GET['id_bien'])    && ctype_digit((string)$_GET['id_bien'])    ? (int)$_GET['id_bien']    : 0;

// ═══════════════════════════════════════════════════════════════════════
// ÉTAPE A : créer l'annonce si un id_bien est fourni et pas d'annonce
// ═══════════════════════════════════════════════════════════════════════
if ($annonceId <= 0 && $bienId > 0) {
    try {
        $st = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ?");
        $st->execute([$bienId]);
        $bSoc = (int)($st->fetchColumn() ?: 0);
        if (!$isSuperAdmin && $societeId > 0 && $bSoc !== $societeId) {
            http_response_code(403); exit('Bien hors de votre société.');
        }

        // Si une annonce existe déjà pour ce bien, on la reprend
        $st = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$bienId]);
        $existing = (int)($st->fetchColumn() ?: 0);
        if ($existing > 0) {
            header('Location: annonce_creation.php?id_annonce=' . $existing);
            exit;
        }

        $st = $pdo->prepare("
            INSERT INTO annonces (id_bien, id_societe, id_agence, etat_publication, date_creation, date_modification)
            VALUES (?, ?, ?, 'brouillon', NOW(), NOW())
        ");
        $st->execute([$bienId, $societeId ?: null, $agenceId ?: null]);
        $annonceId = (int)$pdo->lastInsertId();
        header('Location: annonce_creation.php?id_annonce=' . $annonceId);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Erreur création annonce : ' . htmlspecialchars($e->getMessage()));
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ÉTAPE B (pas d'annonce + pas de bien) : afficher le sélecteur de bien
// ═══════════════════════════════════════════════════════════════════════
if ($annonceId <= 0) {
    // Liste des biens du scope
    try {
        $sql = "SELECT b.id, b.reference_bien, b.adresse_1, b.code_postal, b.ville, b.statut_bien,
                       COALESCE(bt.code, tb2.code) AS type_code
                FROM biens b
                LEFT JOIN bien_types bt  ON bt.id  = b.id_bien_type
                LEFT JOIN types_bien tb2 ON tb2.id = b.id_type_bien
                WHERE " . ($societeId > 0 ? "b.id_societe = :soc" : "1=1") . "
                ORDER BY b.date_modification DESC
                LIMIT 200";
        $st = $pdo->prepare($sql);
        if ($societeId > 0) $st->bindValue(':soc', $societeId, PDO::PARAM_INT);
        $st->execute();
        $biens = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $biens = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="fr"><head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nouvelle annonce — Choisir un bien</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Manrope', system-ui, sans-serif; background: #f4f6fb; color: #0f172a; padding: 40px 20px; }
    .wrap { max-width: 900px; margin: 0 auto; }
    h1 { font-size: 26px; margin-bottom: 8px; color: #0f172a; }
    .subtitle { color: #64748b; font-size: 15px; margin-bottom: 24px; }
    .back { color: #64748b; text-decoration: none; display: inline-block; margin-bottom: 18px; }
    .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.05); padding: 26px; }
    .card h2 { font-size: 15px; margin-bottom: 16px; color: #475569; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
    .search { width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 15px; font-family: inherit; margin-bottom: 16px; }
    .search:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,.15); }
    .biens-list { display: flex; flex-direction: column; gap: 8px; max-height: 500px; overflow-y: auto; }
    .bien-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 8px; text-decoration: none; color: inherit; transition: all .15s; }
    .bien-row:hover { border-color: #8b5cf6; background: #faf5ff; transform: translateX(3px); }
    .bien-ref { background: #eef2ff; color: #3b82f6; padding: 3px 8px; border-radius: 5px; font-size: 11px; font-weight: 700; min-width: 90px; text-align: center; }
    .bien-addr { flex: 1; font-size: 14px; }
    .bien-type { color: #64748b; font-size: 12px; text-transform: uppercase; }
    .bien-statut { background: #fef3c7; color: #b45309; padding: 3px 8px; border-radius: 5px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .empty { padding: 40px; text-align: center; color: #64748b; }
    .create-btn { display: inline-block; margin-top: 16px; background: #3b82f6; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14px; }
    .create-btn:hover { background: #2563eb; }
    </style>
    </head><body>
    <div class="wrap">
        <a href="https://maboximmo.fr" class="back">← Retour</a>
        <h1>Nouvelle annonce</h1>
        <p class="subtitle">Choisissez le bien à diffuser dans la liste ci-dessous.</p>

        <div class="card">
            <h2>Mes biens (<?= count($biens) ?>)</h2>
            <input type="text" class="search" id="search" placeholder="Rechercher par référence, adresse, ville…" autofocus>
            <div class="biens-list" id="biensList">
            <?php if (!$biens): ?>
                <div class="empty">Aucun bien trouvé. Créez-en un d'abord.</div>
            <?php else: foreach ($biens as $b): ?>
                <a href="annonce_creation.php?id_bien=<?= (int)$b['id'] ?>" class="bien-row" data-search="<?= htmlspecialchars(strtolower(($b['reference_bien'] ?? '').' '.($b['adresse_1'] ?? '').' '.($b['ville'] ?? '').' '.($b['type_code'] ?? '')), ENT_QUOTES) ?>">
                    <span class="bien-ref"><?= htmlspecialchars($b['reference_bien'] ?: '#'.$b['id']) ?></span>
                    <span class="bien-addr">
                        <?= htmlspecialchars(trim(($b['adresse_1'] ?? '').' ')) ?>
                        <?php if (!empty($b['ville'])): ?>
                            <span class="bien-type">— <?= htmlspecialchars(($b['code_postal'] ?? '').' '.$b['ville']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($b['type_code'])): ?>
                        <span class="bien-type"><?= htmlspecialchars($b['type_code']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($b['statut_bien'])): ?>
                        <span class="bien-statut"><?= htmlspecialchars($b['statut_bien']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; endif; ?>
            </div>
            <a href="agency_proprietaires.php?pick_bien=1" class="create-btn">+ Créer un nouveau bien d'abord</a>
        </div>
    </div>
    <script>
    const search = document.getElementById('search');
    const rows = document.querySelectorAll('.bien-row');
    search.addEventListener('input', () => {
        const q = search.value.toLowerCase().trim();
        rows.forEach(r => {
            r.style.display = (!q || r.dataset.search.includes(q)) ? '' : 'none';
        });
    });
    </script>
    </body></html>
    <?php
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ÉTAPE C : édition d'une annonce existante (mode autosave)
// ═══════════════════════════════════════════════════════════════════════
try {
    $st = $pdo->prepare("SELECT a.*, b.reference_bien, b.adresse_1, b.ville FROM annonces a LEFT JOIN biens b ON b.id = a.id_bien WHERE a.id = ? LIMIT 1");
    $st->execute([$annonceId]);
    $annonce = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    exit('Erreur lecture annonce : ' . htmlspecialchars($e->getMessage()));
}
if (!$annonce) exit('Annonce introuvable.');
if (!$isSuperAdmin && $societeId > 0 && (int)$annonce['id_societe'] !== $societeId) {
    http_response_code(403); exit('Annonce hors de votre société.');
}

// ── Introspection colonnes annonces ──
$columns = [];
try {
    $rows = $pdo->query("SHOW FULL COLUMNS FROM annonces")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $columns[$r['Field']] = $r;
} catch (Throwable $e) {
    exit('Erreur introspection annonces : ' . htmlspecialchars($e->getMessage()));
}

$hidden = [
    'id',
    'date_creation', 'date_modification', 'date_suppression',
    'id_bien', 'id_societe', 'id_agence',
    'deleted_at', 'created_at', 'updated_at',
];

$sections = [
    'pub'  => ['label' => 'Publication',                      'color' => '#8b5cf6', 'fields' => []],
    'prix' => ['label' => 'Prix & conditions',                'color' => '#10b981', 'fields' => []],
    'hono' => ['label' => 'Honoraires & mandats',             'color' => '#f59e0b', 'fields' => []],
    'enc'  => ['label' => 'Encadrement des loyers',           'color' => '#06b6d4', 'fields' => []],
    'anc'  => ['label' => 'Locataire précédent & taxes',      'color' => '#ef4444', 'fields' => []],
    'text' => ['label' => 'Textes & description commerciale', 'color' => '#3b82f6', 'fields' => []],
    'diff' => ['label' => 'Diffusion',                        'color' => '#f97316', 'fields' => []],
    'oth'  => ['label' => 'Autres',                           'color' => '#6b7280', 'fields' => []],
];

$patterns = [
    'pub'  => ['etat_publication','type_transaction','statut','id_user'],
    'prix' => ['prix','loyer','loyer_cc','charges','loyer_de_base','loyer_est_cc','complement_loyer','loyer_reference_majore','modalite_recuperation_charges_locatives','depot_garantie','honoraires_etat_des_lieux'],
    'hono' => ['honoraires_','alur_','pourcentage_honoraires_','url_tarifs_publics','mandat_','date_mandat'],
    'enc'  => ['zone_encadrement_loyer','encadrement_','enc_'],
    'anc'  => ['ancien_','taxe_'],
    'text' => ['description','texte_ia','accroche','titre','meta_','mots_cles','points_forts'],
    'diff' => ['visible_','portails_','diffusion_','annonce_id_','date_publication','date_expiration'],
];

function match_section(string $field, array $patterns): string {
    foreach ($patterns as $key => $list) {
        foreach ($list as $p) {
            if ($p === $field) return $key;
            if (str_ends_with($p, '_') && str_starts_with($field, $p)) return $key;
        }
    }
    return 'oth';
}

foreach ($columns as $name => $meta) {
    if (in_array($name, $hidden, true)) continue;
    $sec = match_section($name, $patterns);
    $sections[$sec]['fields'][] = $name;
}

function parse_type(string $sqlType): array {
    $t = strtolower($sqlType);
    if (preg_match('/^enum\((.*)\)$/', $t, $m)) {
        $opts = [];
        foreach (explode(',', $m[1]) as $o) $opts[] = trim($o, "' ");
        return ['kind' => 'enum', 'options' => $opts];
    }
    if (str_starts_with($t, 'tinyint(1)')) return ['kind' => 'bool', 'options' => null];
    if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $t)) return ['kind' => 'int', 'options' => null];
    if (preg_match('/^(decimal|float|double|numeric)/', $t)) return ['kind' => 'decimal', 'options' => null];
    if ($t === 'date') return ['kind' => 'date', 'options' => null];
    if (str_starts_with($t, 'datetime') || str_starts_with($t, 'timestamp')) return ['kind' => 'datetime', 'options' => null];
    if (str_contains($t, 'text')) return ['kind' => 'text', 'options' => null];
    return ['kind' => 'varchar', 'options' => null];
}

function render_input(string $field, array $meta, $value): string {
    $info = parse_type($meta['Type']);
    $val  = $value === null ? '' : (string)$value;
    $esc  = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    $data = 'data-field="' . htmlspecialchars($field, ENT_QUOTES) . '"';

    switch ($info['kind']) {
        case 'bool':
            $none = $val === '' ? ' selected' : '';
            $oui  = $val === '1' ? ' selected' : '';
            $non  = $val === '0' ? ' selected' : '';
            return "<select class=\"ac-input\" {$data}><option value=\"\"{$none}>—</option><option value=\"1\"{$oui}>Oui</option><option value=\"0\"{$non}>Non</option></select>";
        case 'enum':
            $h = "<select class=\"ac-input\" {$data}><option value=\"\">—</option>";
            foreach ($info['options'] as $o) {
                $sel = ($val === $o) ? ' selected' : '';
                $h .= "<option value=\"" . htmlspecialchars($o, ENT_QUOTES) . "\"{$sel}>" . htmlspecialchars($o) . "</option>";
            }
            return $h . "</select>";
        case 'date':
            $d = ($val && $val !== '0000-00-00') ? substr($val, 0, 10) : '';
            return "<input type=\"date\" class=\"ac-input\" {$data} value=\"" . htmlspecialchars($d, ENT_QUOTES) . "\">";
        case 'datetime':
            $d = ($val && !str_starts_with($val, '0000')) ? str_replace(' ', 'T', substr($val, 0, 16)) : '';
            return "<input type=\"datetime-local\" class=\"ac-input\" {$data} value=\"" . htmlspecialchars($d, ENT_QUOTES) . "\">";
        case 'int':
            return "<input type=\"number\" step=\"1\" class=\"ac-input\" {$data} value=\"{$esc}\">";
        case 'decimal':
            return "<input type=\"number\" step=\"any\" class=\"ac-input\" {$data} value=\"{$esc}\">";
        case 'text':
            return "<textarea class=\"ac-input ac-textarea\" rows=\"4\" {$data}>{$esc}</textarea>";
        default:
            return "<input type=\"text\" class=\"ac-input\" {$data} value=\"{$esc}\">";
    }
}

$csrf  = csrf_token('ajouter_bien');
$isPub = ($annonce['etat_publication'] ?? '') === 'publie';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Création annonce — Saisie manuelle</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Manrope', system-ui, sans-serif; background: #f4f6fb; color: #0f172a; padding-bottom: 80px; }

.ac-topbar { position: sticky; top: 0; z-index: 100; background: #ffffff; border-bottom: 1px solid #e5e7eb; padding: 14px 28px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
.ac-topbar .ac-back { color: #64748b; text-decoration: none; font-size: 22px; }
.ac-topbar h1 { font-size: 18px; font-weight: 700; flex: 1; }
.ac-topbar .ac-ref { background: #faf5ff; color: #8b5cf6; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; letter-spacing: .5px; }
.ac-topbar .ac-status { font-size: 13px; color: #64748b; min-width: 140px; text-align: right; }
.ac-topbar .ac-status.ok { color: #10b981; }
.ac-topbar .ac-status.err { color: #ef4444; }
.ac-topbar button.ac-pub, .ac-topbar a.ac-bien { background: #10b981; color: #fff; padding: 8px 18px; border-radius: 8px; border: 0; cursor: pointer; font-weight: 700; font-size: 14px; font-family: inherit; text-decoration: none; display: inline-block; }
.ac-topbar button.ac-pub:hover { background: #059669; }
.ac-topbar button.ac-pub.published { background: #f59e0b; }
.ac-topbar a.ac-bien { background: #3b82f6; }
.ac-topbar a.ac-bien:hover { background: #2563eb; }

.ac-bien-card { max-width: 1100px; margin: 18px auto 0; padding: 14px 20px; background: #fff; border-radius: 10px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.ac-bien-card .ac-tag { background: #eef2ff; color: #3b82f6; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; }
.ac-bien-card .ac-addr { flex: 1; color: #64748b; }

.ac-wrap { max-width: 1100px; margin: 16px auto 0; padding: 0 20px; }
.ac-section { background: #fff; border-radius: 12px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05); overflow: hidden; }
.ac-section-head { padding: 14px 20px; display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; color: #fff; cursor: pointer; user-select: none; }
.ac-section-head .ac-dot { width: 10px; height: 10px; border-radius: 50%; background: #fff; opacity: .8; }
.ac-section-head .ac-count { margin-left: auto; background: rgba(255,255,255,.25); padding: 2px 8px; border-radius: 10px; font-size: 11px; }
.ac-section-body { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px 18px; padding: 18px 20px; }

.ac-field { display: flex; flex-direction: column; gap: 5px; position: relative; }
.ac-field label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; }
.ac-input { width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 7px; font-family: inherit; font-size: 14px; background: #fff; color: #0f172a; transition: border-color .15s, box-shadow .15s; }
.ac-input:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,.15); }
.ac-textarea { resize: vertical; min-height: 80px; }
.ac-field.ac-wide { grid-column: 1 / -1; }

.ac-field .ac-flag { position: absolute; right: 8px; top: 28px; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; opacity: 0; transition: opacity .2s; pointer-events: none; }
.ac-field .ac-flag.ok  { background: #d1fae5; color: #065f46; opacity: 1; }
.ac-field .ac-flag.err { background: #fee2e2; color: #991b1b; opacity: 1; }

.ac-section.collapsed .ac-section-body { display: none; }
</style>
</head>
<body>

<div class="ac-topbar">
    <a href="https://maboximmo.fr" class="ac-back" title="Retour">←</a>
    <h1>Création d'annonce (saisie manuelle)</h1>
    <span class="ac-ref">ANNONCE #<?= $annonceId ?></span>
    <a href="bien_creation.php?id=<?= (int)$annonce['id_bien'] ?>" class="ac-bien">← Bien</a>
    <span id="ac-status" class="ac-status">Prêt</span>
    <button id="ac-pub" class="ac-pub<?= $isPub ? ' published' : '' ?>">
        <?= $isPub ? '✓ Publiée' : 'Publier' ?>
    </button>
</div>

<div class="ac-bien-card">
    <span class="ac-tag"><?= htmlspecialchars($annonce['reference_bien'] ?: '#'.$annonce['id_bien']) ?></span>
    <span class="ac-addr">
        <?= htmlspecialchars(($annonce['adresse_1'] ?? '') . ' — ' . ($annonce['ville'] ?? '')) ?>
    </span>
    <span style="font-size:12px;color:#64748b">État : <strong><?= htmlspecialchars($annonce['etat_publication'] ?? 'brouillon') ?></strong></span>
</div>

<div class="ac-wrap">
<?php foreach ($sections as $skey => $sec): ?>
    <?php if (empty($sec['fields'])) continue; ?>
    <div class="ac-section" data-section="<?= $skey ?>">
        <div class="ac-section-head" style="background: <?= $sec['color'] ?>" onclick="this.parentElement.classList.toggle('collapsed')">
            <span class="ac-dot"></span>
            <?= htmlspecialchars($sec['label']) ?>
            <span class="ac-count"><?= count($sec['fields']) ?></span>
        </div>
        <div class="ac-section-body">
        <?php foreach ($sec['fields'] as $f): ?>
            <?php
                $meta  = $columns[$f];
                $info  = parse_type($meta['Type']);
                $value = $annonce[$f] ?? null;
                $wide  = in_array($info['kind'], ['text'], true)
                      || in_array($f, ['description','texte_ia','accroche_commerciale','points_forts','meta_description','titre_seo','mots_cles','url_tarifs_publics'], true);
            ?>
            <div class="ac-field<?= $wide ? ' ac-wide' : '' ?>">
                <label for="ac_<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></label>
                <?= render_input($f, $meta, $value) ?>
                <span class="ac-flag"></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
(function(){
    const ANNONCE_ID = <?= $annonceId ?>;
    const CSRF       = <?= json_encode($csrf) ?>;
    const status     = document.getElementById('ac-status');
    const pubBtn     = document.getElementById('ac-pub');
    const inputs     = document.querySelectorAll('.ac-input');

    let inflight = 0;
    function setStatus(text, kind){
        status.textContent = text;
        status.className = 'ac-status' + (kind ? ' ' + kind : '');
    }

    async function saveField(input){
        const field = input.dataset.field;
        const value = input.value;
        const flag  = input.parentElement.querySelector('.ac-flag');
        inflight++; setStatus('Enregistrement…');

        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('id', String(ANNONCE_ID));
            fd.append('field', field);
            fd.append('value', value);
            const r = await fetch('api/annonce_creation_save.php', { method: 'POST', body: fd });
            const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));
            if (j.ok) {
                flag.textContent = '✓'; flag.className = 'ac-flag ok';
            } else {
                flag.textContent = '✗'; flag.className = 'ac-flag err';
                flag.title = j.error || 'Erreur';
            }
        } catch (e) {
            flag.textContent = '✗'; flag.className = 'ac-flag err';
            flag.title = e.message || 'Erreur réseau';
        } finally {
            inflight--;
            if (inflight <= 0) setStatus('Tout est enregistré', 'ok');
        }
    }

    inputs.forEach(inp => {
        const evt = (inp.tagName === 'SELECT') ? 'change' : 'blur';
        inp.addEventListener(evt, () => saveField(inp));
    });

    pubBtn.addEventListener('click', async () => {
        const newState = pubBtn.classList.contains('published') ? 'brouillon' : 'publie';
        if (newState === 'publie' && !confirm('Publier cette annonce ?')) return;

        setStatus('Publication…');
        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('id', String(ANNONCE_ID));
            fd.append('etat', newState);
            const r = await fetch('api/annonce_creation_publish.php', { method: 'POST', body: fd });
            const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));
            if (j.ok) {
                if (newState === 'publie') {
                    pubBtn.classList.add('published');
                    pubBtn.textContent = '✓ Publiée';
                    setStatus('Annonce publiée !', 'ok');
                } else {
                    pubBtn.classList.remove('published');
                    pubBtn.textContent = 'Publier';
                    setStatus('Remise en brouillon', 'ok');
                }
            } else {
                setStatus('Erreur : ' + (j.error || 'inconnue'), 'err');
            }
        } catch (e) {
            setStatus('Erreur : ' + e.message, 'err');
        }
    });
})();
</script>
</body>
</html>
