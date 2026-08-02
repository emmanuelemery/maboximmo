<?php
// inc/fiche_360_layout.php — Composants réutilisables pour les vues 360°
// Utilisé par bien_360.php, immeuble_360.php, tiers_360.php, etc.
declare(strict_types=1);

if (!function_exists('h')) require_once __DIR__ . '/security.php';
// Bouton/URL « Envoyer par mail » dispo dans tous les 360 (mail_compose_url()).
if (!function_exists('mail_compose_url')) require_once __DIR__ . '/mail_button.php';

/**
 * CSS commun pour toutes les pages 360°.
 * À inclure UNE FOIS dans $extraCss avant l'agency_layout_top.
 */
if (!function_exists('fiche360_css')) {
    function fiche360_css(): string {
        return <<<'CSS'
<style>
body { background:#fafaf6; }

/* Breadcrumb hiérarchique */
.f360-breadcrumb { font-size:11px; color:#7a766f; margin-bottom:10px; }
.f360-breadcrumb a { color:#4878a6; text-decoration:none; }
.f360-breadcrumb a:hover { text-decoration:underline; }
.f360-chain { display:flex; align-items:center; gap:8px; padding:10px 14px; background:#fff; border-radius:10px;
    margin-bottom:14px; box-shadow:2px 2px 6px #e3dfd8; flex-wrap:wrap; }
.f360-chain-label { font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.04em; margin-right:8px; }
.f360-chip { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:99px;
    background:#f4f1ec; color:#2c2a28; font-size:12px; text-decoration:none; transition:all .12s; }
.f360-chip:hover { background:#e9e6e0; }
.f360-chip.active { background:#fef3c7; color:#92400e; font-weight:700; }
.f360-chain-arrow { color:#c8c4be; }

/* Header objet */
.f360-header { background:#fff; border-radius:14px; padding:18px 22px; margin-bottom:14px;
    box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff;
    display:flex; align-items:center; gap:16px; }
.f360-header-icon { width:56px; height:56px; border-radius:14px; background:linear-gradient(135deg,#fef3c7,#fde68a);
    display:flex; align-items:center; justify-content:center; font-size:28px; flex-shrink:0; }
.f360-header-info { flex:1; min-width:0; }
.f360-header-title { font-size:20px; font-weight:800; color:#2c2a28; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.f360-header-title .badge { font-size:11px; font-weight:700; padding:3px 9px; border-radius:99px; }
.f360-header-title .badge.vacant { background:#fef3c7; color:#92400e; }
.f360-header-title .badge.loue { background:#d9f0db; color:#2d6a35; }
.f360-header-title .badge.vendu { background:#e6dcf2; color:#4a2e7a; }
.f360-header-title .badge.actif { background:#d9f0db; color:#2d6a35; }
.f360-header-sub { font-size:13px; color:#7a766f; margin-top:4px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.f360-header-meta { font-size:11px; color:#9a9690; }
.f360-header-actions { display:flex; gap:8px; flex-shrink:0; }

/* Bandeau IA contextuelle */
.f360-ia { background:linear-gradient(90deg,#eef4fb,#f5f3ff); border-radius:10px; padding:12px 16px;
    margin-bottom:14px; display:flex; gap:10px; align-items:center; box-shadow:2px 2px 6px #e3dfd8; }
.f360-ia-icon { font-size:20px; }
.f360-ia-input { flex:1; background:#fff; border:1px solid #c4b5fd; border-radius:8px; padding:8px 12px;
    font-size:13px; font-family:inherit; outline:none; }
.f360-ia-input:focus { border-color:#7c3aed; box-shadow:0 0 0 2px rgba(124,58,237,.2); }
.f360-ia-btn { background:#7c3aed; color:#fff; border:none; border-radius:8px; padding:8px 14px;
    font-weight:700; cursor:pointer; font-size:12.5px; }
.f360-ia-btn:hover { background:#6d28d9; }
.f360-ia-model { font-size:10px; color:#7a766f; }
.f360-ia-response { background:#fff; border-radius:10px; padding:14px 16px; margin-bottom:14px; box-shadow:2px 2px 6px #e3dfd8;
    font-size:13px; line-height:1.5; color:#2c2a28; border-left:3px solid #7c3aed; display:none; }
.f360-ia-response.show { display:block; }
.f360-ia-response .ia-q { color:#7a766f; font-size:11.5px; margin-bottom:6px; font-style:italic; }
.f360-ia-response .ia-loading { color:#7c3aed; }

/* Bandeau statut */
.f360-status { padding:11px 16px; border-radius:10px; margin-bottom:14px; font-size:13px;
    display:flex; align-items:center; gap:10px; }
.f360-status.green  { background:linear-gradient(90deg,#d9f0db,#bbf7d0); color:#14532d; }
.f360-status.orange { background:linear-gradient(90deg,#ffe5c2,#fde68a); color:#78350f; }
.f360-status.red    { background:linear-gradient(90deg,#fbe9e9,#fecaca); color:#7f1d1d; }
.f360-status.gray   { background:#f4f1ec; color:#5a5650; }
.f360-status-icon { font-size:18px; }
.f360-status-alerts { font-size:11px; opacity:.8; margin-left:6px; }

/* Layout 2 colonnes */
.f360-grid { display:grid; grid-template-columns:1fr 360px; gap:14px; align-items:start; }
@media (max-width: 1100px) { .f360-grid { grid-template-columns:1fr; } }

/* Card générique */
.f360-card { background:#fff; border-radius:10px; padding:14px 18px; margin-bottom:14px;
    box-shadow:2px 2px 6px #e3dfd8; }
.f360-card h3 { margin:0 0 10px; font-size:13.5px; color:#2c2a28; display:flex; align-items:center; gap:8px; }
.f360-card h3 .count { background:#f4f1ec; color:#5a5650; font-size:10px; font-weight:700;
    padding:2px 8px; border-radius:99px; margin-left:auto; }

/* Tabs */
.f360-tabs { display:flex; gap:0; border-bottom:2px solid #f0ece6; margin-bottom:12px; }
.f360-tab { padding:8px 14px; border:none; background:transparent; cursor:pointer; font-family:inherit;
    font-size:12.5px; color:#7a766f; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .12s; }
.f360-tab.active { color:#2c2a28; border-bottom-color:#7c3aed; font-weight:700; }
.f360-tab .count { background:#f4f1ec; color:#5a5650; font-size:10px; padding:1px 6px; border-radius:99px; margin-left:4px; }

/* Checklist */
.f360-checklist { background:#fff; border-radius:10px; padding:14px 16px; margin-bottom:14px;
    box-shadow:2px 2px 6px #e3dfd8; }
.f360-checklist h3 { margin:0 0 10px; font-size:13.5px; display:flex; align-items:center; }
.f360-checklist h3 .ratio { margin-left:auto; font-family:'DM Mono',monospace; font-size:11px;
    background:#f4f1ec; padding:2px 8px; border-radius:99px; color:#5a5650; }
.f360-checkitem { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid #f0ece6; font-size:12px; }
.f360-checkitem:last-child { border-bottom:none; }
.f360-checkitem .ico { width:18px; flex-shrink:0; text-align:center; }
.f360-checkitem.ok .ico { color:#2d6a35; }
.f360-checkitem.missing .ico { color:#a8323b; }
.f360-checkitem .label { flex:1; }
.f360-checkitem .label small { display:block; color:#9a9690; font-size:10px; }
.f360-checkitem .add { background:#f4f1ec; border:1px solid #e3dfd8; color:#4878a6; padding:2px 8px;
    border-radius:6px; font-size:11px; cursor:pointer; text-decoration:none; }
.f360-checkitem .add:hover { background:#fef3c7; color:#92400e; }

/* Rattachement */
.f360-attach { background:#fff; border-radius:10px; padding:12px 16px; margin-bottom:14px;
    box-shadow:2px 2px 6px #e3dfd8; }
.f360-attach .lbl { font-size:9.5px; color:#9a9690; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
.f360-attach a { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:8px;
    text-decoration:none; color:#2c2a28; transition:background .12s; }
.f360-attach a:hover { background:#fafaf6; }
.f360-attach .ico { width:30px; height:30px; border-radius:8px; background:#eef4fb;
    display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; }
.f360-attach .name { flex:1; font-size:12.5px; font-weight:600; }
.f360-attach .ref { font-family:'DM Mono',monospace; font-size:10.5px; color:#7a766f; }

/* Panneau Actions (noir) */
.f360-actions { background:#1f1d1c; color:#fff; border-radius:12px; padding:14px 16px; margin-bottom:14px;
    box-shadow:4px 4px 12px rgba(0,0,0,.2); }
.f360-actions h4 { margin:0 0 10px; font-size:11px; color:#fbbf24; text-transform:uppercase;
    letter-spacing:.06em; display:flex; align-items:center; gap:6px; }
.f360-actions a { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:8px;
    color:#fff; text-decoration:none; font-size:12.5px; margin-bottom:4px; transition:background .12s; }
.f360-actions a:hover { background:rgba(255,255,255,.08); }
.f360-actions a:last-child { margin-bottom:0; }
.f360-actions a .arrow { margin-left:auto; opacity:.5; }

/* Mention dans (where used) */
.f360-mention { padding:9px 12px; border-bottom:1px solid #f0ece6; font-size:12px;
    display:flex; align-items:center; gap:10px; }
.f360-mention:last-child { border-bottom:none; }
.f360-mention .ico { width:24px; height:24px; border-radius:6px; background:#eef4fb;
    display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
.f360-mention .info { flex:1; }
.f360-mention .info strong { display:block; font-size:12px; color:#2c2a28; }
.f360-mention .info small { color:#9a9690; font-family:'DM Mono',monospace; font-size:10px; }
.f360-mention .see { color:#4878a6; text-decoration:none; font-size:11px; }
.f360-mention .see:hover { text-decoration:underline; }

/* Empty state */
.f360-empty { padding:30px 16px; text-align:center; color:#9a9690; font-size:13px; }
.f360-empty .em-ico { font-size:36px; margin-bottom:10px; opacity:.5; }
</style>
CSS;
    }
}

// ─── BREADCRUMB hiérarchique (chips clicables : Propriétaire → Immeuble → Bien) ──
if (!function_exists('fiche360_breadcrumb')) {
    /**
     * @param array $entites [['icon'=>'👤','label'=>'Bernadette ANTONY','url'=>'/tiers_360.php?id=5'], ...]
     * @param string $label  Libellé titre (ex: "Patrimoine", "Chaîne hiérarchique")
     */
    function fiche360_breadcrumb(array $entites, string $label = 'Chaîne hiérarchique', string $rightHtml = ''): void {
        if (empty($entites)) return;
        echo '<div class="f360-chain">';
        if ($label !== '') echo '<span class="f360-chain-label">' . h($label) . '</span>';
        $last = count($entites) - 1;
        foreach ($entites as $i => $e) {
            $cls = ($i === $last) ? 'active' : '';
            if (!empty($e['url']) && $i !== $last) {
                echo '<a class="f360-chip ' . $cls . '" href="' . h($e['url']) . '">'
                   . h($e['icon'] ?? '') . ' ' . h($e['label']) . '</a>';
            } else {
                echo '<span class="f360-chip ' . $cls . '">' . h($e['icon'] ?? '') . ' ' . h($e['label']) . '</span>';
            }
            if ($i < $last) echo '<span class="f360-chain-arrow">→</span>';
        }
        // Bloc aligné à droite (ex : boutons « À vendre » / « À louer »)
        if ($rightHtml !== '') echo '<div style="margin-left:auto;display:flex;align-items:center;gap:10px">' . $rightHtml . '</div>';
        echo '</div>';
    }
}

// ─── HEADER objet ──
if (!function_exists('fiche360_header')) {
    /**
     * @param string $icone   ex: 🏠
     * @param string $titre   ex: "Lot 0002 · parking"
     * @param string $badge   ex: ['label'=>'Vacant','class'=>'vacant']  ou null
     * @param string $adresse Sous-ligne adresse
     * @param array  $metas   [['icon'=>'📐','text'=>'565 m²'], ...]
     * @param array  $actions [['label'=>'Importer un document','url'=>'...', 'class'=>'tr-btn'], ...]
     */
    function fiche360_header(string $icone, string $titre, ?array $badge, string $adresse, array $metas = [], array $actions = []): void {
        echo '<div class="f360-header">';
        echo '<div class="f360-header-icon">' . h($icone) . '</div>';
        echo '<div class="f360-header-info">';
        echo '<div class="f360-header-title">' . h($titre);
        if ($badge) {
            echo ' <span class="badge ' . h($badge['class'] ?? '') . '">● ' . h($badge['label']) . '</span>';
        }
        echo '</div>';
        echo '<div class="f360-header-sub">';
        if ($adresse) echo '<span>📍 ' . h($adresse) . '</span>';
        foreach ($metas as $m) echo '<span class="f360-header-meta">' . h($m['icon'] ?? '') . ' ' . h($m['text']) . '</span>';
        echo '</div>';
        echo '</div>';
        if (!empty($actions)) {
            echo '<div class="f360-header-actions">';
            foreach ($actions as $a) {
                $cls = $a['class'] ?? 'tr-btn';
                echo '<a class="' . h($cls) . '" href="' . h($a['url'] ?? '#') . '"';
                if (!empty($a['target']))  echo ' target="' . h($a['target']) . '"';
                if (!empty($a['onclick'])) echo ' onclick="' . h($a['onclick']) . '"';
                echo '>' . h($a['label']) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}

// ─── Bandeau IA contextuelle ──
if (!function_exists('fiche360_ia_bar')) {
    /**
     * @param string $entityType bien|immeuble|tiers|bail
     * @param int    $entityId
     * @param string $placeholder ex: "Demander à l'IA sur ce bien…"
     * @param string $modele      "claude-haiku-4-5-20251001" par défaut
     */
    function fiche360_ia_bar(string $entityType, int $entityId, string $placeholder, string $modele = 'Claude Haiku 4.5'): void {
        $rid = 'f360-ia-' . bin2hex(random_bytes(4));
        echo '<div class="f360-ia">';
        echo '<span class="f360-ia-icon">🤖</span>';
        echo '<input type="text" class="f360-ia-input" id="' . $rid . '-q" placeholder="' . h($placeholder) . '"'
           . ' data-entity-type="' . h($entityType) . '" data-entity-id="' . (int)$entityId . '"'
           . ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();fiche360IaAsk(\'' . $rid . '\');}">';
        echo '<button type="button" class="f360-ia-btn" onclick="fiche360IaAsk(\'' . $rid . '\')">Demander</button>';
        echo '<span class="f360-ia-model">' . h($modele) . '</span>';
        echo '</div>';
        echo '<div class="f360-ia-response" id="' . $rid . '-resp"></div>';
    }
}

// ─── Bandeau statut intelligent ──
if (!function_exists('fiche360_status_banner')) {
    /**
     * @param string $message Texte principal
     * @param string $color   green|orange|red|gray
     * @param string $icon    Emoji
     * @param string $alertes Texte secondaire (alertes, pièces manquantes…)
     */
    function fiche360_status_banner(string $message, string $color = 'gray', string $icon = 'ℹ️', string $alertes = ''): void {
        echo '<div class="f360-status ' . h($color) . '">';
        echo '<span class="f360-status-icon">' . h($icon) . '</span>';
        echo '<span>' . $message . '</span>';
        if ($alertes !== '') echo '<span class="f360-status-alerts">' . h($alertes) . '</span>';
        echo '</div>';
    }
}

// ─── Checklist (pièces obligatoires) ──
if (!function_exists('fiche360_checklist')) {
    /**
     * @param string $titre ex: "Pièces du bien"
     * @param array  $items [['label'=>'DPE','sublabel'=>'Diag de performance énergétique','ok'=>false,'add_url'=>'...','count'=>3,'total'=>4], ...]
     */
    function fiche360_checklist(string $titre, array $items, ?array $fbxPrefill = null): void {
        // $fbxPrefill = contexte FluxBox de la fiche (bien_id/bail_id/immeuble_id/tiers_id +
        // soc/age/entite…). Si fourni ET qu'une pièce a 'fbx_type', son « + » ouvre le modal
        // FluxBox avec le TYPE pré-sélectionné (générique à toutes les fiches 360).
        $fbxHasPrefill = ($fbxPrefill !== null && $fbxPrefill !== []);
        $nbOk = 0;
        foreach ($items as $i) if (!empty($i['ok'])) $nbOk++;
        $total = count($items);

        echo '<!-- fiche360_layout VERSION onedrive-diag-20260606 -->';
        echo '<div class="f360-checklist">';
        echo '<h3>📋 ' . h($titre) . ' <span class="ratio">' . $nbOk . '/' . $total . '</span></h3>';
        foreach ($items as $it) {
            $ok = !empty($it['ok']);
            // Prefill effectif de la pièce : un override PAR ITEM ('fbx_prefill') prime sur
            // le prefill global de la fiche. Permet, ex., que « Bail signé »/« EDL » d'une
            // fiche BIEN ciblent le BAIL (= locataire) au lieu du bien nu.
            $itemPrefill = (!empty($it['fbx_prefill']) && is_array($it['fbx_prefill'])) ? $it['fbx_prefill'] : $fbxPrefill;
            $itemHasPrefill = ($itemPrefill !== null && $itemPrefill !== []);
            // Libellé cliquable : pièce manquante + prefill + type → tout l'item ouvre le modal pré-rempli.
            $rowClickable = (!$ok && $itemHasPrefill && !empty($it['fbx_type']));
            // Pièce PRÉSENTE + doc rattaché → clic ouvre le modal de visualisation (mvptModalView).
            $okClickable  = ($ok && !empty($it['doc_id']));
            $rowAttr = '';
            if ($rowClickable) {
                $pfRow = $itemPrefill; $pfRow['forced_type_doc'] = (string)$it['fbx_type'];
                $pfRowJson = htmlspecialchars(json_encode($pfRow, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                $rowAttr = ' style="cursor:pointer" title="Charger : ' . h($it['label']) . ' — type pré-sélectionné"'
                    . ' data-pf="' . $pfRowJson . '"'
                    . ' onclick="if(event.target.closest(\'button,a\'))return; try{window.fbxOpenUploadModal(JSON.parse(this.dataset.pf));}catch(e){console.error(e);}"';
            } elseif ($okClickable) {
                $rowAttr = ' style="cursor:pointer" title="Voir : ' . h($it['label']) . '"'
                    . ' data-docid="' . (int)$it['doc_id'] . '"'
                    . ' data-docname="' . h((string)($it['doc_name'] ?? $it['label'])) . '"'
                    . ' onclick="if(event.target.closest(\'button,a\'))return; if(typeof window.mvptModalView===\'function\'){window.mvptModalView(parseInt(this.dataset.docid,10), this.dataset.docname);}"';
            }
            echo '<div class="f360-checkitem ' . ($ok ? 'ok' : 'missing') . '"' . $rowAttr . '>';
            echo '<span class="ico">' . ($ok ? '✓' : '⚠') . '</span>';
            echo '<span class="label">' . h($it['label']);
            if (!empty($it['sublabel'])) echo ' <small>' . h($it['sublabel']) . '</small>';
            if (isset($it['count'])) echo ' <small>' . (int)$it['count'] . '/' . (int)($it['total'] ?? 0) . ' chargés</small>';
            echo '</span>';
            // Bouton recherche assistée OneDrive (si pièce manquante + code fourni)
            if (!$ok && !empty($it['search_code'])) {
                echo '<button type="button" class="f360-ged-search-btn" '
                   . 'data-type-code="' . h($it['search_code']) . '" '
                   . 'data-type-label="' . h($it['label']) . '" '
                   . 'title="Rechercher ce document dans le OneDrive général">🔎 Rechercher</button>';
            }
            // « + » : ouvre le modal FluxBox avec le TYPE pré-sélectionné si on a un prefill
            // fiche + un type de pièce. Sinon fallback vers l'ancien lien add_url.
            if (!$ok && $itemHasPrefill && !empty($it['fbx_type'])) {
                $pf = $itemPrefill;
                $pf['forced_type_doc'] = (string)$it['fbx_type'];
                // JSON encodé normalement (avec de vrais "), puis htmlspecialchars(ENT_QUOTES)
                // transforme " en &quot; pour l'attribut. Le navigateur les redécode → JSON.parse
                // reçoit un JSON valide. (NE PAS utiliser JSON_HEX_QUOT : casse la structure.)
                $pfJson = htmlspecialchars(json_encode($pf, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                echo '<button type="button" class="add" '
                   . 'onclick="try{window.fbxOpenUploadModal(JSON.parse(this.dataset.pf));}catch(e){console.error(e);}return false;" '
                   . 'data-pf="' . $pfJson . '" '
                   . 'title="Charger cette pièce (' . h($it['label']) . ') — type pré-sélectionné">+</button>';
            } elseif (!$ok && !empty($it['add_url'])) {
                echo '<a class="add" href="' . h($it['add_url']) . '">+</a>';
            }
            // Action optionnelle par item (HTML brut) — ex. bouton « 🔄 Ré-analyser le DPE ».
            if (!empty($it['action_html'])) echo $it['action_html'];
            echo '</div>';
        }
        echo '</div>';
    }
}

// ─── Rattachement (lien vers une entité parente / liée) ──
if (!function_exists('fiche360_attach')) {
    /**
     * @param string $sectionLabel ex: "PROPRIÉTAIRE", "IMMEUBLE", "SYNDIC"
     * @param array  $links [['icon'=>'👤','name'=>'Bernadette ANTONY','ref'=>'OWN-2026-00207','url'=>'/tiers_360.php?id=5'], ...]
     */
    function fiche360_attach(string $sectionLabel, array $links, string $headerActionHtml = ''): void {
        echo '<div class="f360-attach">';
        if ($headerActionHtml !== '') {
            echo '<div class="lbl" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">'
               . '<span>' . h($sectionLabel) . '</span>' . $headerActionHtml . '</div>';
        } else {
            echo '<div class="lbl">' . h($sectionLabel) . '</div>';
        }
        foreach ($links as $l) {
            $action = (string)($l['action'] ?? '');   // bouton optionnel (ex. supprimer) rendu HORS du lien
            if ($action !== '') echo '<div style="display:flex;align-items:center;gap:2px;">';
            echo '<a href="' . h($l['url'] ?? '#') . '"' . ($action !== '' ? ' style="flex:1;min-width:0;"' : '') . '>';
            echo '<span class="ico">' . h($l['icon'] ?? '🔗') . '</span>';
            echo '<span class="name">' . h($l['name']);
            if (!empty($l['ref'])) echo '<div class="ref">' . h($l['ref']) . '</div>';
            echo '</span>';
            echo '<span style="color:#c8c4be;">→</span>';
            echo '</a>';
            if ($action !== '') { echo $action; echo '</div>'; }
        }
        echo '</div>';
    }
}

// ─── Panneau Actions (noir, bas droite) ──
if (!function_exists('fiche360_actions_panel')) {
    /**
     * @param string $titre ex: "ACTIONS BIEN"
     * @param array  $actions [['icon'=>'📋','label'=>'Créer un avenant','url'=>'...'], ...]
     */
    function fiche360_actions_panel(string $titre, array $actions): void {
        if (empty($actions)) return;
        echo '<div class="f360-actions">';
        echo '<h4>⚡ ' . h($titre) . '</h4>';
        foreach ($actions as $a) {
            echo '<a href="' . h($a['url'] ?? '#') . '"';
            if (!empty($a['target']))  echo ' target="' . h($a['target']) . '"';
            if (!empty($a['onclick'])) echo ' onclick="' . h($a['onclick']) . '"';
            if (!empty($a['class']))   echo ' class="' . h($a['class']) . '"';
            if (!empty($a['style']))   echo ' style="' . h($a['style']) . '"';
            echo '>';
            echo '<span>' . h($a['icon'] ?? '▸') . '</span>';
            echo '<span>' . h($a['label']) . '</span>';
            echo '<span class="arrow">→</span>';
            echo '</a>';
        }
        echo '</div>';
    }
}

// ─── "Mentionné dans" — where used ──
if (!function_exists('fiche360_mention_dans')) {
    /**
     * @param array $mentions [['icon'=>'📄','title'=>'CRG ...','ref'=>'CRG-2026-00380','url'=>'...'], ...]
     */
    function fiche360_mention_dans(array $mentions): void {
        echo '<div class="f360-card">';
        echo '<h3>🔗 Mentionné dans <span class="count">' . count($mentions) . '</span></h3>';
        echo '<div style="font-size:11px; color:#9a9690; margin-bottom:8px; font-style:italic;">Documents qui citent cette entité (ex : CRG, courriers).</div>';
        if (empty($mentions)) {
            echo '<div class="f360-empty"><div class="em-ico">📭</div>Pas encore mentionnée dans un autre document.</div>';
        } else {
            foreach ($mentions as $m) {
                echo '<div class="f360-mention">';
                echo '<span class="ico">' . h($m['icon'] ?? '📄') . '</span>';
                echo '<span class="info" style="min-width:0;"><strong style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-all;line-height:1.3;" title="' . h($m['title']) . '">' . h($m['title']) . '</strong>';
                if (!empty($m['ref'])) echo '<small>' . h($m['ref']) . '</small>';
                echo '</span>';
                if (!empty($m['url'])) echo '<a class="see" href="' . h($m['url']) . '" target="_blank">👁 Voir</a>';
                echo '</div>';
            }
        }
        echo '</div>';
    }
}

if (!function_exists('fiche360_mail_history')) {
    /**
     * Carte « Communication » : historique des mails envoyés depuis le composeur pour cette entité.
     * $historyKey = clé mail_context : strtolower(type).':'.id (ex. 'imb:848', 'bail:662', 'bien:2455').
     * Défensif : n'échoue jamais si la table mail_history n'existe pas encore.
     */
    function fiche360_mail_history(PDO $pdo, string $historyKey, string $titre = 'Communication', string $cardClass = 'f360-card'): void {
        $rows = [];
        try {
            $st = $pdo->prepare("SELECT h.id, h.subject, h.recipients_json, h.recipients_count, h.sent_at,
                                        TRIM(CONCAT_WS(' ', u.prenom, u.nom)) AS envoyeur
                                   FROM mail_history h LEFT JOIN users u ON u.id = h.sent_by
                                  WHERE h.recipient_type = ? ORDER BY h.sent_at DESC, h.id DESC LIMIT 30");
            $st->execute([$historyKey]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $rows = []; }
        echo '<div class="' . h($cardClass) . '">';
        echo '<h3>✉️ ' . h($titre) . ' <span class="count">' . count($rows) . '</span></h3>';
        if (empty($rows)) {
            echo '<div class="f360-empty"><div class="em-ico">📭</div>Aucun mail envoyé depuis cette fiche.</div>';
        } else {
            foreach ($rows as $r) {
                $dest = '';
                $rj = json_decode((string)($r['recipients_json'] ?? ''), true);
                if (is_array($rj)) {
                    $ems = [];
                    foreach ($rj as $x) { $e = is_array($x) ? ((string)($x['email'] ?? '')) : (string)$x; if ($e !== '') $ems[] = $e; }
                    $dest = implode(', ', array_slice($ems, 0, 4)) . (count($ems) > 4 ? ' +' . (count($ems) - 4) : '');
                }
                $dt = $r['sent_at'] ? date('d/m/y H:i', strtotime((string)$r['sent_at'])) : '';
                echo '<div onclick="mailHistView(' . (int)$r['id'] . ')" title="Ouvrir le mail" style="padding:7px 0;border-bottom:1px solid #f0ece6;font-size:12px;cursor:pointer;" onmouseover="this.style.background=\'#faf8ff\'" onmouseout="this.style.background=\'transparent\'">';
                echo '<div style="font-weight:700;color:#243B5C;word-break:break-word;">✉️ ' . h((string)($r['subject'] ?? '(sans objet)')) . '</div>';
                echo '<div style="color:#9a9690;font-size:11px;margin-top:2px;">';
                echo '📅 ' . h($dt);
                if (!empty($r['envoyeur'])) echo ' · 👤 ' . h((string)$r['envoyeur']);
                echo ' · 📧 ' . (int)($r['recipients_count'] ?? 0) . ' destinataire(s)';
                echo '</div>';
                if ($dest !== '') echo '<div style="color:#7a766f;font-size:11px;margin-top:1px;word-break:break-all;">→ ' . h($dest) . '</div>';
                echo '</div>';
            }
        }
        echo '</div>';

        // Modal de lecture (émis une seule fois par page).
        static $modalDone = false;
        if (!$modalDone) {
            $modalDone = true;
            $api = function_exists('app_url') ? app_url('/api/mail_history_view.php') : '/api/mail_history_view.php';
            echo '<div id="mailHistModal" style="display:none;position:fixed;inset:0;background:rgba(20,25,35,.55);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)mailHistClose()">'
               . '<div style="background:#fff;border-radius:12px;max-width:760px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.3);">'
               . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;border-bottom:1px solid #eee;position:sticky;top:0;background:#fff;">'
               . '<strong id="mhSubject" style="font-size:15px;color:#243B5C;">Mail</strong>'
               . '<button type="button" onclick="mailHistClose()" style="border:none;background:#eceef1;border-radius:50%;width:30px;height:30px;cursor:pointer;font-weight:700;">✕</button></div>'
               . '<div id="mhMeta" style="font-size:12px;color:#7a766f;padding:10px 18px;border-bottom:1px solid #f2f2f2;"></div>'
               . '<div id="mhBody" style="padding:16px 18px;font-size:14px;line-height:1.55;color:#2c2a28;">Chargement…</div>'
               . '</div></div>'
               . '<script>(function(){window.MAILHIST_API=' . json_encode($api) . ';'
               . 'window.mailHistClose=function(){document.getElementById("mailHistModal").style.display="none";};'
               . 'window.mailHistView=function(id){var m=document.getElementById("mailHistModal");m.style.display="flex";'
               . 'document.getElementById("mhSubject").textContent="Mail";document.getElementById("mhMeta").textContent="";document.getElementById("mhBody").innerHTML="Chargement…";'
               . 'fetch(window.MAILHIST_API+"?id="+encodeURIComponent(id),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){'
               . 'if(!d||!d.ok){document.getElementById("mhBody").textContent="⚠ "+((d&&d.error)||"Introuvable");return;}'
               . 'document.getElementById("mhSubject").textContent=d.subject||"(sans objet)";'
               . 'var meta="📅 "+(d.sent_at||"")+(d.envoyeur?" · 👤 "+d.envoyeur:"")+(d.recipients&&d.recipients.length?" · 📧 "+d.recipients.join(", "):"");'
               . 'document.getElementById("mhMeta").textContent=meta;'
               . 'document.getElementById("mhBody").innerHTML=d.body_html||"(vide)";'
               . '}).catch(function(e){document.getElementById("mhBody").textContent="⚠ Réseau : "+e;});};'
               . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")window.mailHistClose();});})();</script>';
        }
    }
}

// ─── JS commun (IA Ask) ──
if (!function_exists('fiche360_js')) {
    function fiche360_js(): string {
        return <<<'JS'
<script>
async function fiche360IaAsk(rid) {
    const input = document.getElementById(rid + '-q');
    const resp  = document.getElementById(rid + '-resp');
    const q = input.value.trim();
    if (!q) return;
    const entityType = input.dataset.entityType;
    const entityId   = input.dataset.entityId;
    resp.classList.add('show');
    resp.innerHTML = '<div class="ia-q">❓ ' + q + '</div><div class="ia-loading">⏳ L\'IA réfléchit…</div>';
    try {
        const fd = new FormData();
        fd.append('entity_type', entityType);
        fd.append('entity_id', entityId);
        fd.append('question', q);
        const res = await fetch((window.APP_BASE || '') + '/api/ia_contexte_ask.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            const ans = (data.answer || '').replace(/\n/g, '<br>');
            resp.innerHTML = '<div class="ia-q">❓ ' + q + '</div>' + ans
                + '<div style="font-size:10px; color:#9a9690; margin-top:8px;">⏱️ ' + (data.duration_ms || '?') + 'ms · 💰 ' + (data.cout_centimes || 0) + ' ct · 🤖 ' + (data.model || '?') + '</div>';
            input.value = '';
        } else {
            resp.innerHTML = '<div class="ia-q">❓ ' + q + '</div><div style="color:#a8323b;">⚠️ ' + (data.error || 'Erreur') + '</div>';
        }
    } catch (e) {
        resp.innerHTML = '<div class="ia-q">❓ ' + q + '</div><div style="color:#a8323b;">⚠️ Erreur réseau</div>';
    }
}
</script>
JS;
    }
}
