<?php
/**
 * inc/bailleur_hub.php — Rendu MUTUALISÉ d'un dashboard « hub » de module bailleur.
 *
 * Un hub = sidebar bailleur conservée + onglets permanents (le 1er = Tableau de bord interne ;
 * les suivants ouvrent un outil en iframe via ?embed=1). Utilisé par les dashboards Patrimoine,
 * Financement, Transaction… → CSS/JS écrits UNE seule fois ici (règle d'or : CSS centralisé).
 *
 * Emploi type dans une page :
 *   $layoutSidebar = 'sidebar_bailleur_module';
 *   require_once __DIR__ . '/inc/bailleur_hub.php';
 *   $extraCss = bailleur_hub_css();          // injecté dans le <head> par agency_layout_top
 *   include __DIR__ . '/inc/agency_layout_top.php';
 *   bailleur_hub_render($tabs, $dashHtml, $staffBarHtml);
 *
 *   $tabs     : [ ['k'=>'dash','lbl'=>'Tableau de bord','ic'=>'📊','url'=>''],
 *                 ['k'=>'xxx','lbl'=>'…','ic'=>'…','url'=>app_url('/outil.php?embed=1')], … ]
 *   $dashHtml : HTML du panneau « Tableau de bord » (KPIs + cartes) — cf. bailleur_hub_kpis()/_cards().
 *   $staffBar : HTML optionnel (sélecteur « voir en tant que » du staff), rendu à droite des onglets.
 */
declare(strict_types=1);

if (!function_exists('bailleur_hub_css')) {
    function bailleur_hub_css(): string {
        return <<<'CSS'
<style>
:root{ --hb-ink:#2c2a28; --hb-soft:#7a766f; --hb-bg:#f4f1ec; --hb-line:#ece7df; --hb-blue:#4878a6;
  --hb-green:#2d8a4e; --hb-amber:#a8741d; --hb-purple:#6b4aa0; --hb-red:#c0453f;
  --hb-sh:0 1px 2px rgba(44,42,40,.05),0 6px 20px rgba(44,42,40,.07); }
.agency-content{ display:flex; flex-direction:column; height:100vh; overflow:hidden; }
.agency-topbar{ margin-bottom:0 !important; flex:none; }
.hb-tabs{ display:flex; gap:4px; padding:0 14px; background:#fff; border-bottom:1px solid var(--hb-line); flex:none; overflow-x:auto; }
.hb-tab{ display:inline-flex; align-items:center; gap:8px; padding:13px 18px; font-size:14px; font-weight:700; color:var(--hb-soft);
  border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; white-space:nowrap; transition:.12s; }
.hb-tab:hover{ color:var(--hb-ink); background:#faf8f5; }
.hb-tab.active{ color:#fff; background:var(--hb-blue); border-bottom-color:var(--hb-blue); border-radius:10px 10px 0 0; }
.hb-asbar{ margin-left:auto; display:flex; align-items:center; gap:8px; padding:7px 4px; }
.hb-asbar label{ font-size:12px; font-weight:700; color:var(--hb-soft); white-space:nowrap; }
.hb-asbar select{ padding:7px 10px; border-radius:9px; border:1px solid var(--hb-line); background:#fff; font-size:13px; font-weight:600; cursor:pointer; max-width:260px; }
.hb-body{ flex:1; position:relative; overflow:hidden; background:var(--hb-bg); }
.hb-panel{ position:absolute; inset:0; display:none; overflow:auto; }
.hb-panel.active{ display:block; }
.hb-panel iframe{ width:100%; height:100%; border:0; display:block; }
.hb-dash{ padding:22px; max-width:1200px; margin:0 auto; }
.hb-h{ font-size:20px; font-weight:800; color:var(--hb-ink); margin:0 0 4px; }
.hb-sub{ font-size:13px; color:var(--hb-soft); margin:0 0 18px; }
.hb-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; margin-bottom:24px; }
.hb-kpi{ background:#fff; border-radius:16px; padding:16px 18px; box-shadow:var(--hb-sh); position:relative; overflow:hidden; }
.hb-kpi::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--c,#ccc); }
.hb-kpi .ic{ position:absolute; right:14px; top:14px; font-size:22px; opacity:.85; }
.hb-kpi .lbl{ font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; color:var(--hb-soft); }
.hb-kpi .val{ font-size:24px; font-weight:800; color:var(--hb-ink); line-height:1.1; margin-top:4px; }
.hb-kpi .sub{ font-size:11.5px; color:#a8a39a; margin-top:2px; }
.hb-kpi.blue{ --c:var(--hb-blue);} .hb-kpi.green{ --c:var(--hb-green);} .hb-kpi.amber{ --c:var(--hb-amber);} .hb-kpi.purple{ --c:var(--hb-purple);} .hb-kpi.red{ --c:var(--hb-red);}
.hb-cards{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
.hb-card{ background:#fff; border-radius:16px; padding:20px; box-shadow:var(--hb-sh); cursor:pointer; transition:.15s; border:1px solid transparent; text-align:left; }
.hb-card:hover{ transform:translateY(-2px); border-color:var(--hb-blue); }
.hb-card .cic{ font-size:30px; }
.hb-card .ct{ font-size:16px; font-weight:800; color:var(--hb-ink); margin-top:10px; }
.hb-card .cd{ font-size:13px; color:var(--hb-soft); margin-top:4px; }
</style>
CSS;
    }
}

if (!function_exists('bailleur_hub_kpi')) {
    /** Construit le HTML d'une tuile KPI. $cls = blue|green|amber|purple|red */
    function bailleur_hub_kpi(string $cls, string $ic, string $lbl, string $val, string $sub = ''): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        return '<div class="hb-kpi ' . $e($cls) . '"><span class="ic">' . $ic . '</span>'
             . '<div class="lbl">' . $e($lbl) . '</div><div class="val">' . $e($val) . '</div>'
             . ($sub !== '' ? '<div class="sub">' . $e($sub) . '</div>' : '') . '</div>';
    }
}

if (!function_exists('bailleur_hub_card')) {
    /** Construit le HTML d'une carte d'accès (ouvre l'onglet $key). */
    function bailleur_hub_card(string $key, string $ic, string $title, string $desc): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        return '<button class="hb-card" onclick="hbGo(' . json_encode($key) . ')"><div class="cic">' . $ic . '</div>'
             . '<div class="ct">' . $e($title) . '</div><div class="cd">' . $e($desc) . '</div></button>';
    }
}

if (!function_exists('bailleur_hub_card_link')) {
    /** Carte d'accès qui NAVIGUE vers une URL (pour outils non embeddables). */
    function bailleur_hub_card_link(string $href, string $ic, string $title, string $desc): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        return '<a class="hb-card" href="' . $e($href) . '" style="display:block;text-decoration:none;"><div class="cic">' . $ic . '</div>'
             . '<div class="ct">' . $e($title) . '</div><div class="cd">' . $e($desc) . '</div></a>';
    }
}

if (!function_exists('bailleur_hub_landing')) {
    /** Dashboard « landing » : pas d'onglets iframe, juste le Tableau de bord (KPIs + cartes-liens). */
    function bailleur_hub_landing(string $dashHtml, string $staffBar = ''): void {
        if ($staffBar !== '') echo '<div class="hb-tabs" id="hb-tabs">' . $staffBar . '</div>';
        echo '<div class="hb-body" style="overflow:auto;"><div class="hb-dash">' . $dashHtml . '</div></div>';
    }
}

if (!function_exists('bailleur_hub_render')) {
    function bailleur_hub_render(array $tabs, string $dashHtml, string $staffBar = ''): void {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="hb-tabs" id="hb-tabs">
            <?php foreach ($tabs as $i => $t): ?>
                <button class="hb-tab<?= $i === 0 ? ' active' : '' ?>" data-tab="<?= $e($t['k']) ?>" data-src="<?= $e($t['url']) ?>" onclick="hbTab(this)">
                    <span><?= $t['ic'] ?></span><?= $e($t['lbl']) ?>
                </button>
            <?php endforeach; ?>
            <?= $staffBar ?>
        </div>
        <div class="hb-body">
            <div class="hb-panel active" data-panel="dash"><div class="hb-dash"><?= $dashHtml ?></div></div>
            <?php foreach ($tabs as $t): if (($t['url'] ?? '') === '') continue; ?>
                <div class="hb-panel" data-panel="<?= $e($t['k']) ?>"><iframe data-src="<?= $e($t['url']) ?>" title="<?= $e($t['lbl']) ?>"></iframe></div>
            <?php endforeach; ?>
        </div>
        <script>
        let hbCurrent = 'dash';
        const hbScroll = {};
        function hbActivate(key){
            if (key === hbCurrent) return;
            const cur = document.querySelector('.hb-panel[data-panel="'+hbCurrent+'"] iframe');
            if (cur && cur.contentWindow) { try { hbScroll[hbCurrent] = cur.contentWindow.scrollY || 0; } catch(_){} }
            hbCurrent = key;
            document.querySelectorAll('.hb-tab').forEach(t=>t.classList.toggle('active', t.dataset.tab===key));
            document.querySelectorAll('.hb-panel').forEach(p=>p.classList.toggle('active', p.dataset.panel===key));
            const panel = document.querySelector('.hb-panel[data-panel="'+key+'"]');
            if (panel){
                const f = panel.querySelector('iframe');
                if (f && f.dataset.src){
                    const sep = f.dataset.src.indexOf('?') >= 0 ? '&' : '?';
                    const saved = hbScroll[key] || 0;
                    f.onload = function(){ try { f.contentWindow.scrollTo(0, saved); } catch(_){} };
                    f.src = f.dataset.src + sep + '_hb=' + Date.now();
                }
            }
            try { history.replaceState(null,'', location.pathname + location.search + '#' + key); } catch(_){}
        }
        function hbTab(btn){ hbActivate(btn.dataset.tab); }
        function hbGo(key){ hbActivate(key); }
        function hbViewAs(v){ const h = location.hash || ''; location.href = location.pathname + (parseInt(v,10) > 0 ? ('?bailleur=' + v) : '') + h; }
        const h = (location.hash||'').replace('#',''); if (h && document.querySelector('.hb-tab[data-tab="'+h+'"]')) hbActivate(h);
        </script>
        <?php
    }
}

if (!function_exists('bailleur_hub_staffbar')) {
    /** Barre « voir en tant que » (staff) à partir de la liste pf_bailleurs_list(). */
    function bailleur_hub_staffbar(array $bailleurs, int $viewAs): string {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $h = '<div class="hb-asbar"><label>👁️ Voir en tant que</label><select onchange="hbViewAs(this.value)">';
        $h .= '<option value="0">— Tout le périmètre —</option>';
        foreach ($bailleurs as $b) {
            $h .= '<option value="' . (int)$b['id'] . '"' . ($viewAs === (int)$b['id'] ? ' selected' : '') . '>' . $e($b['label']) . ' (' . (int)$b['nb'] . ')</option>';
        }
        return $h . '</select></div>';
    }
}
