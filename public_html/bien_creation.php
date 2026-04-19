<?php
/**
 * bien_creation.php — Page incassable de création d'un bien en saisie manuelle.
 *
 * Principes :
 *   - Tous les champs de la table `biens` sont exposés (lus via SHOW COLUMNS).
 *   - À l'ouverture sans id, un bien brouillon est créé immédiatement et l'URL
 *     est remplacée par ?id=X → l'utilisateur édite ensuite un enregistrement réel.
 *   - Autosave AJAX sur change/blur → `/api/bien_creation_save.php`.
 *   - Aucune IA, aucune dépendance à ref_generator / immeubles / types_bien.
 *   - Sections colorées pour rendre la saisie agréable.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']    ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);

// ── 1. Création immédiate d'un brouillon si aucun id fourni ───────────
$bienId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

if ($bienId <= 0) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO biens (id_societe, id_agence, id_user_actuel, statut_bien, date_creation, date_modification)
            VALUES (?, ?, ?, 'brouillon', NOW(), NOW())
        ");
        $stmt->execute([$societeId ?: null, $agenceId ?: null, $userId ?: null]);
        $bienId = (int)$pdo->lastInsertId();
        header('Location: bien_creation.php?id=' . $bienId);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Impossible de créer un brouillon : ' . htmlspecialchars($e->getMessage()));
    }
}

// ── 2. Récupération du bien + vérification scope ──────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM biens WHERE id = ? LIMIT 1");
    $stmt->execute([$bienId]);
    $bien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    exit('Erreur lecture bien : ' . htmlspecialchars($e->getMessage()));
}
if (!$bien) { exit('Bien introuvable.'); }
if ($societeId > 0 && (int)$bien['id_societe'] !== $societeId) {
    http_response_code(403); exit('Bien hors de votre société.');
}

// ── 3. Introspection des colonnes de la table ─────────────────────────
$columns = [];
try {
    $rows = $pdo->query("SHOW FULL COLUMNS FROM biens")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $columns[$r['Field']] = $r;
    }
} catch (Throwable $e) {
    exit('Erreur introspection biens : ' . htmlspecialchars($e->getMessage()));
}

// ── 4. Colonnes à ne PAS afficher dans le formulaire ──────────────────
$hidden = [
    'id',
    'date_creation', 'date_modification', 'date_suppression',
    'slug', 'reference_bien',
    'id_societe', 'id_agence', 'id_user_actuel',
    'deleted_at', 'created_at', 'updated_at',
];

// ── 5. Regroupement visuel par section ────────────────────────────────
// Règle : match par patterns (préfixes / mots-clés). Une colonne non matchée
// tombe dans "Autres" — jamais perdue.
$sections = [
    'id'   => ['label' => 'Identification',   'color' => '#3b82f6', 'fields' => []],
    'adr'  => ['label' => 'Adresse & environnement', 'color' => '#f59e0b', 'fields' => []],
    'srf'  => ['label' => 'Surfaces & composition',  'color' => '#10b981', 'fields' => []],
    'equ'  => ['label' => 'Équipements',       'color' => '#f97316', 'fields' => []],
    'dpe'  => ['label' => 'Énergie / DPE',     'color' => '#ef4444', 'fields' => []],
    'fin'  => ['label' => 'Finance / Prix / Copropriété', 'color' => '#8b5cf6', 'fields' => []],
    'desc' => ['label' => 'Description & SEO', 'color' => '#06b6d4', 'fields' => []],
    'oth'  => ['label' => 'Autres',            'color' => '#6b7280', 'fields' => []],
];

$patterns = [
    'id'   => ['designation','reference_externe','statut_bien','type_commercialisation','usage_bien','sous_type_bien','etat_bien','standing','disponibilite_bien','disponible_le','occupation_bien','louable_immediatement','adresse_visible_public','lot_principal','lot_secondaire','id_type_bien','id_proprietaire','id_immeuble','zone_tendue'],
    'adr'  => ['adresse_1','adresse_2','code_postal','ville','pays','latitude','longitude','quartier','exposition','vue','nuisances','ambiance','acces_transports','distance_commerces','altitude','points_interet','argument_phare'],
    'srf'  => ['surface_','hauteur_','etage','nb_','annee_construction','parking_','numero_porte','dernier_etage'],
    'equ'  => ['balcon','terrasse','jardin','cour','cave','grenier','garage','box','piscine','dependances','acces_camion','vitrine','ascenseur','interphone','digicode','alarme','fibre','cheminee','double_vitrage','volets_roulants','climatisation','cuisine_','animaux_acceptes','fumeur_accepte'],
    'dpe'  => ['dpe_','ges_','chauffage_','eau_chaude_','isolation','menuiseries','montant_estime_depenses','date_indice_prix_energies','annee_reference_depenses','obligation_debroussaillement','erp_','risque_','diagnostiqueur_'],
    'fin'  => ['loyer_','charges_','depot_garantie','honoraires_','prix_','rentabilite_','montant_travaux_','taxe_','estimation_agence','enc_','bien_en_copropriete','copro_','syndic_','alur_'],
    'desc' => ['description','points_forts','mots_cles','commentaire','titre_seo','meta_description','accroche_commerciale','reprise_descriptif'],
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

// ── 6. Helpers rendu d'input selon type SQL ──────────────────────────
/** Retourne ['kind' => 'int'|'decimal'|'date'|'datetime'|'bool'|'enum'|'text'|'varchar', 'options' => array|null] */
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
            $optNone = $val === '' ? ' selected' : '';
            $optOui  = $val === '1' ? ' selected' : '';
            $optNon  = $val === '0' ? ' selected' : '';
            return "<select class=\"bc-input\" {$data}><option value=\"\"{$optNone}>—</option><option value=\"1\"{$optOui}>Oui</option><option value=\"0\"{$optNon}>Non</option></select>";
        case 'enum':
            $h = "<select class=\"bc-input\" {$data}><option value=\"\">—</option>";
            foreach ($info['options'] as $o) {
                $sel = ($val === $o) ? ' selected' : '';
                $h .= "<option value=\"" . htmlspecialchars($o, ENT_QUOTES) . "\"{$sel}>" . htmlspecialchars($o) . "</option>";
            }
            return $h . "</select>";
        case 'date':
            $d = ($val && $val !== '0000-00-00') ? substr($val, 0, 10) : '';
            return "<input type=\"date\" class=\"bc-input\" {$data} value=\"" . htmlspecialchars($d, ENT_QUOTES) . "\">";
        case 'datetime':
            $d = ($val && !str_starts_with($val, '0000')) ? str_replace(' ', 'T', substr($val, 0, 16)) : '';
            return "<input type=\"datetime-local\" class=\"bc-input\" {$data} value=\"" . htmlspecialchars($d, ENT_QUOTES) . "\">";
        case 'int':
            return "<input type=\"number\" step=\"1\" class=\"bc-input\" {$data} value=\"{$esc}\">";
        case 'decimal':
            return "<input type=\"number\" step=\"any\" class=\"bc-input\" {$data} value=\"{$esc}\">";
        case 'text':
            return "<textarea class=\"bc-input bc-textarea\" rows=\"3\" {$data}>{$esc}</textarea>";
        default:
            return "<input type=\"text\" class=\"bc-input\" {$data} value=\"{$esc}\">";
    }
}

$csrf = csrf_token('ajouter_bien');
$ref  = $bien['reference_bien'] ?: '—';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Création bien — Saisie manuelle</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Manrope', system-ui, sans-serif; background: #f4f6fb; color: #0f172a; padding-bottom: 80px; }

.bc-topbar { position: sticky; top: 0; z-index: 100; background: #ffffff; border-bottom: 1px solid #e5e7eb; padding: 14px 28px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
.bc-topbar .bc-back { color: #64748b; text-decoration: none; font-size: 22px; }
.bc-topbar h1 { font-size: 18px; font-weight: 700; flex: 1; }
.bc-topbar .bc-ref { background: #eef2ff; color: #3b82f6; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; letter-spacing: .5px; }
.bc-topbar .bc-status { font-size: 13px; color: #64748b; min-width: 120px; text-align: right; }
.bc-topbar .bc-status.ok { color: #10b981; }
.bc-topbar .bc-status.err { color: #ef4444; }
.bc-topbar a.bc-nextbtn { background: #8b5cf6; color: #fff; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14px; }
.bc-topbar a.bc-nextbtn:hover { background: #7c3aed; }

.bc-wrap { max-width: 1100px; margin: 24px auto; padding: 0 24px; }
.bc-section { background: #fff; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); overflow: hidden; }
.bc-section-head { padding: 14px 20px; display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; color: #fff; cursor: pointer; user-select: none; }
.bc-section-head .bc-dot { width: 10px; height: 10px; border-radius: 50%; background: #fff; opacity: .8; }
.bc-section-head .bc-count { margin-left: auto; background: rgba(255,255,255,.25); padding: 2px 8px; border-radius: 10px; font-size: 11px; }
.bc-section-body { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px 18px; padding: 18px 20px; }

.bc-field { display: flex; flex-direction: column; gap: 5px; position: relative; }
.bc-field label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; }
.bc-input { width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 7px; font-family: inherit; font-size: 14px; background: #fff; color: #0f172a; transition: border-color .15s, box-shadow .15s; }
.bc-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.bc-textarea { resize: vertical; min-height: 60px; font-family: inherit; }
.bc-field.bc-text-wide { grid-column: 1 / -1; }

.bc-field .bc-flag { position: absolute; right: 8px; top: 28px; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; opacity: 0; transition: opacity .2s; pointer-events: none; }
.bc-field .bc-flag.ok  { background: #d1fae5; color: #065f46; opacity: 1; }
.bc-field .bc-flag.err { background: #fee2e2; color: #991b1b; opacity: 1; }

.bc-section.collapsed .bc-section-body { display: none; }
</style>
</head>
<body>

<div class="bc-topbar">
    <a href="accueil.php" class="bc-back" title="Retour">←</a>
    <h1>Création d'un bien (saisie manuelle)</h1>
    <span class="bc-ref">REF : <?= htmlspecialchars($ref) ?></span>
    <span class="bc-ref" style="background:#fef3c7;color:#b45309">#<?= $bienId ?></span>
    <span id="bc-status" class="bc-status">Prêt</span>
    <a href="annonce_creation.php?id_bien=<?= $bienId ?>" class="bc-nextbtn">Diffuser →</a>
</div>

<div class="bc-wrap">
<?php foreach ($sections as $skey => $sec): ?>
    <?php if (empty($sec['fields'])) continue; ?>
    <div class="bc-section" data-section="<?= $skey ?>">
        <div class="bc-section-head" style="background: <?= $sec['color'] ?>" onclick="this.parentElement.classList.toggle('collapsed')">
            <span class="bc-dot"></span>
            <?= htmlspecialchars($sec['label']) ?>
            <span class="bc-count"><?= count($sec['fields']) ?></span>
        </div>
        <div class="bc-section-body">
        <?php foreach ($sec['fields'] as $f): ?>
            <?php
                $meta  = $columns[$f];
                $info  = parse_type($meta['Type']);
                $value = $bien[$f] ?? null;
                $wide  = in_array($info['kind'], ['text'], true) || in_array($f, ['description','commentaire','points_forts','accroche_commerciale','reprise_descriptif','meta_description','titre_seo','mots_cles'], true);
            ?>
            <div class="bc-field<?= $wide ? ' bc-text-wide' : '' ?>">
                <label for="bc_<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></label>
                <?= render_input($f, $meta, $value) ?>
                <span class="bc-flag"></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
(function(){
    const BIEN_ID = <?= $bienId ?>;
    const CSRF    = <?= json_encode($csrf) ?>;
    const status  = document.getElementById('bc-status');
    const inputs  = document.querySelectorAll('.bc-input');

    let inflight = 0;
    function setStatus(text, kind){
        status.textContent = text;
        status.className = 'bc-status' + (kind ? ' ' + kind : '');
    }

    async function saveField(input){
        const field = input.dataset.field;
        const value = input.value;
        const flag  = input.parentElement.querySelector('.bc-flag');
        inflight++; setStatus('Enregistrement…');

        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('id', String(BIEN_ID));
            fd.append('field', field);
            fd.append('value', value);
            const r = await fetch('api/bien_creation_save.php', { method: 'POST', body: fd });
            const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));
            if (j.ok) {
                flag.textContent = '✓'; flag.className = 'bc-flag ok';
            } else {
                flag.textContent = '✗'; flag.className = 'bc-flag err';
                flag.title = j.error || 'Erreur';
            }
        } catch (e) {
            flag.textContent = '✗'; flag.className = 'bc-flag err';
            flag.title = e.message || 'Erreur réseau';
        } finally {
            inflight--;
            if (inflight <= 0) setStatus('Tout est enregistré', 'ok');
        }
    }

    inputs.forEach(inp => {
        const evt = (inp.tagName === 'SELECT') ? 'change' : 'blur';
        inp.addEventListener(evt, () => saveField(inp));
        if (inp.tagName === 'TEXTAREA' || inp.type === 'text') {
            // Save on Enter for single-line text
            inp.addEventListener('keydown', e => { if (e.key === 'Enter' && inp.tagName !== 'TEXTAREA') { e.preventDefault(); inp.blur(); } });
        }
    });
})();
</script>
</body>
</html>
