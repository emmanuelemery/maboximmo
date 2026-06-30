<?php
/**
 * inc/entity_card.php — Mini-card d'entité réutilisable (style neumorphique MBI).
 *
 * Composant partagé pour présenter une entité (immeuble, propriétaire, …) en grille
 * de mini-cards cliquables → 360°, SANS boutons d'action (le clic sur la card navigue).
 * Reprend le graphisme des cards `agency_immeubles`. Utilisé par agency_immeubles.php
 * et agency_proprietaires.php (et extensible aux autres listes).
 *
 *   entity_card_assets();              // une fois par page : injecte le CSS (.ec-*)
 *   echo '<div class="ec-grid">';
 *   entity_card([                      // une card
 *     'url'       => 'tiers_360.php?id=12',
 *     'ref'       => 'optionnel (mono)',
 *     'title'     => 'NOM EN GROS',
 *     'sub'       => '<svg…/> Ville',  // HTML libre (contrôlé par l'appelant)
 *     'badge'     => '<span…>🏢 Société</span>',
 *     'chips'     => ['<strong>3</strong> biens', 'EMERY IMMO RIOM'],  // HTML
 *     'foot_left' => '👤 Représentant',                                // HTML
 *     'actions'   => ['<a class="ec-abtn" href="tel:…">📞</a>'],       // HTML, stopPropagation auto
 *   ]);
 *   echo '</div>';
 */
declare(strict_types=1);

if (!function_exists('entity_card_assets')) {
    function entity_card_assets(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<style>
.ec-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
  gap:16px; grid-auto-rows:1fr; }   /* 1fr → toutes les cards d'une même hauteur */
.ec-card { background:var(--bg-primary,#e4e8f0); border-radius:16px;
  box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de), -6px -6px 14px #ffffff;
  padding:16px 18px; display:flex; flex-direction:column; gap:10px; height:100%; min-height:150px;
  transition:box-shadow .15s, transform .12s; cursor:pointer; }
.ec-card:hover { box-shadow:8px 8px 18px #c8cbd2, -8px -8px 18px #ffffff; transform:translateY(-2px); }
.ec-head { display:flex; align-items:flex-start; gap:10px; }
.ec-head-txt { flex:1; min-width:0; }
.ec-thumb { width:48px; height:48px; border-radius:10px; object-fit:cover; flex-shrink:0; background:#eef1f6; border:1px solid #e2e6ec; }
.ec-ref { font-family:'DM Mono',monospace; font-size:10px; font-weight:600; letter-spacing:.12em; color:#4878a6; text-transform:uppercase; }
.ec-title { font-size:15px; font-weight:800; color:#243B5C; line-height:1.25; margin-top:2px; }
.ec-sub { font-size:11px; color:#8a8680; margin-top:3px; display:flex; align-items:center; gap:4px; }
.ec-body { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.ec-chip { display:inline-flex; align-items:center; gap:5px; background:var(--bg-secondary,#eef1f6);
  border-radius:8px; padding:4px 9px; font-size:11px; color:#3a3830; }
.ec-foot { display:flex; align-items:center; justify-content:space-between; gap:8px;
  padding-top:10px; border-top:1px solid rgba(196,192,186,.4); min-height:30px; margin-top:auto; }
.ec-foot-left { font-size:11px; color:#6a6660; display:flex; align-items:center; gap:5px; min-width:0; }
.ec-actions { display:flex; gap:6px; flex-shrink:0; }
.ec-abtn { width:30px; height:30px; border-radius:10px; background:var(--bg-primary,#e4e8f0);
  box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de), -3px -3px 8px #ffffff;
  display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:14px; color:#3a3830; }
.ec-abtn:hover { box-shadow:inset 2px 2px 5px #d4d7de, inset -2px -2px 5px #ffffff; }
.ec-toolbar { display:flex; justify-content:flex-end; margin-bottom:12px; }
.ec-sort-toggle { display:inline-flex; align-items:center; gap:6px; cursor:pointer; text-decoration:none;
  font-family:'Sora',sans-serif; font-size:11px; font-weight:600; letter-spacing:.03em; color:#3a3830;
  padding:0 14px; height:32px; border-radius:999px; background:var(--bg-primary,#e4e8f0);
  box-shadow:4px 4px 10px var(--shadow-dark,#d4d7de), -4px -4px 10px #ffffff; }
.ec-sort-toggle:hover { box-shadow:inset 3px 3px 6px #d4d7de, inset -3px -3px 6px #ffffff; }
</style>
        <?php
    }

    /** Direction de tri alpha depuis l'URL (asc par défaut). */
    function entity_card_sort_dir(string $param = 'alpha'): string {
        return strtolower((string)($_GET[$param] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    /** Lien toggle A→Z / Z→A (préserve les autres paramètres GET). Nu (à wrapper si besoin). */
    function entity_card_sort_toggle(string $dir, string $param = 'alpha'): string {
        $q = $_GET; $q[$param] = ($dir === 'desc') ? 'asc' : 'desc';
        $url   = '?' . http_build_query($q);
        $label = $dir === 'desc' ? 'Z → A' : 'A → Z';
        $arrow = $dir === 'desc' ? '↓' : '↑';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES)
             . '" class="ec-sort-toggle" title="Inverser le tri alphabétique">🔤 ' . $arrow . ' ' . $label . '</a>';
    }
}

if (!function_exists('entity_card')) {
    function entity_card(array $o): void {
        $url = (string)($o['url'] ?? '#');
        // Accent couleur métier : barre verticale à gauche (style dashboard EXPLORER).
        $accent = (string)($o['accent'] ?? '');
        // Accent droit optionnel (ex. dossier de vente en orange), plus fin que celui de gauche.
        $accentRight = (string)($o['accent_right'] ?? '');
        $accentRightW = (int)($o['accent_right_w'] ?? 3);
        $styleParts = [];
        if ($accent !== '')      $styleParts[] = 'border-left:5px solid ' . htmlspecialchars($accent, ENT_QUOTES);
        if ($accentRight !== '') $styleParts[] = 'border-right:' . $accentRightW . 'px solid ' . htmlspecialchars($accentRight, ENT_QUOTES);
        $style = $styleParts ? ' style="' . implode(';', $styleParts) . '"' : '';
        // Attributs data-* (pour le filtrage live côté JS : data-name, data-letter…).
        $data = '';
        foreach (($o['data'] ?? []) as $k => $v) {
            $data .= ' data-' . preg_replace('/[^a-z0-9-]/', '', strtolower((string)$k))
                   . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
        }
        // Card = div cliquable (et NON <a>) pour autoriser des liens tel:/mailto: à l'intérieur.
        echo '<div class="ec-card"' . $data . $style . ' onclick="location.href=' . htmlspecialchars(json_encode($url), ENT_QUOTES) . '">';
        echo '<div class="ec-head">';
        if (!empty($o['thumb'])) {
            echo '<img class="ec-thumb" src="' . htmlspecialchars((string)$o['thumb'], ENT_QUOTES) . '" loading="lazy" alt="">';
        }
        echo '<div class="ec-head-txt">';
        // Si la clé 'ref' est fournie, on réserve toujours la ligne (placeholder si vide)
        // → les titres restent alignés entre cards même sans référence.
        if (array_key_exists('ref', $o)) {
            $ref = (string)$o['ref'];
            echo '<div class="ec-ref">' . ($ref !== '' ? htmlspecialchars($ref) : '&nbsp;') . '</div>';
        }
        echo '<div class="ec-title">' . htmlspecialchars((string)($o['title'] ?? '')) . '</div>';
        if (!empty($o['sub']))   echo '<div class="ec-sub">' . $o['sub'] . '</div>';   // HTML contrôlé
        echo '</div>';
        if (!empty($o['badge'])) echo '<div>' . $o['badge'] . '</div>';
        echo '</div>';

        if (!empty($o['chips'])) {
            echo '<div class="ec-body">';
            foreach ($o['chips'] as $c) echo '<div class="ec-chip">' . $c . '</div>';
            echo '</div>';
        }

        if (!empty($o['extra'])) echo $o['extra'];   // bloc libre (ex. liste des lots vacants)

        $footLeft = (string)($o['foot_left'] ?? '');
        $actions  = $o['actions'] ?? [];
        if ($footLeft !== '' || !empty($actions)) {
            echo '<div class="ec-foot">';
            echo '<div class="ec-foot-left">' . $footLeft . '</div>';
            if (!empty($actions)) {
                echo '<div class="ec-actions" onclick="event.stopPropagation()">' . implode('', $actions) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
