<?php
declare(strict_types=1);
/**
 * missions/mettre_bien_location.php — MISSION « Mettre un bien en location » (parcours autoguidé).
 * Deuxième branche UX : réutilise 100 % du backend (bien_nouveau.php + APIs), ne recrée que le front.
 * Phases : P1 Création/récupération du bien · P2 Annonce · P3 Visites · P4 Dossier+bail+EDL.
 * P1 = cascade immeuble → propriétaire → bien (existant OU nouveau + mandat GESTION) → documents.
 * Layout/socle chargés par _mission_head.php (partagé). Voir project_vision_missions_paradigme.
 */
define('MBI', true);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
$__admin = function_exists('is_admin_or_super_admin')
    ? is_admin_or_super_admin()
    : ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$__admin) {
    header('Location: ' . (function_exists('app_url') ? app_url('/agency_dashboard.php') : '/'));
    exit;
}
$pageTitle = 'Mettre un bien en location';
require __DIR__ . '/_mission_head.php';
?>
<div class="shell">

  <header class="hero">
    <div class="hero-bg"></div>
    <div class="hero-row">
      <div class="hero-mid">
        <div class="eyebrow"><span>Parcours autoguidé · Service Location</span></div>
        <div class="instance" id="ml-hero-title">Nouvelle instance — commencez par identifier le bien.</div>
      </div>
      <label class="search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
        <input placeholder="Rechercher un bien, un tiers, un document…" aria-label="Recherche globale">
        <kbd>/</kbd>
      </label>
    </div>

    <div class="gaugehead"><div class="ph">Phase 1 <small>Création / récupération du bien</small></div><div class="pct" id="pct">0&nbsp;%</div></div>
    <div class="gauge">
      <div class="seg cur" style="--p:5%;--sc:var(--c-bien)"><div class="bar"><i></i></div><div class="lab"><span class="num">1</span><span>Le bien</span></div></div>
      <div class="seg" style="--sc:var(--c-document)"><div class="bar"><i></i></div><div class="lab"><span class="num">2</span><span>Annonce</span></div></div>
      <div class="seg" style="--sc:var(--c-tiers)"><div class="bar"><i></i></div><div class="lab"><span class="num">3</span><span>Visites</span></div></div>
      <div class="seg" style="--sc:var(--c-bailleur)"><div class="bar"><i></i></div><div class="lab"><span class="num">4</span><span>Dossier · bail · EDL</span></div></div>
    </div>
  </header>

  <div class="grid">

    <!-- Colonne étapes de la Phase 1 : cascade immeuble → proprio → bien → docs -->
    <section class="card">
      <div class="hd"><div class="t">Étapes de la phase<small>Création / récupération · 0 / 4</small></div></div>
      <div class="bd"><div class="steps" id="p1-steps">
        <button class="step cur" data-castep="immeuble"><span class="srow"><span class="mk">›</span><span class="en">Étape 1</span><svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m9 6 6 6-6 6"/></svg></span><span class="lb">Identifier l'immeuble</span></button>
        <button class="step todo" data-castep="proprio"><span class="srow"><span class="mk"></span><span class="en">Étape 2</span></span><span class="lb">Le propriétaire</span></button>
        <button class="step todo" data-castep="bien"><span class="srow"><span class="mk"></span><span class="en">Étape 3</span></span><span class="lb">Le bien (existant ou nouveau)</span></button>
        <button class="step todo" data-castep="docs"><span class="srow"><span class="mk"></span><span class="en">Étape 4</span></span><span class="lb">Documents (DPE, photos…)</span></button>
      </div></div>
    </section>

    <!-- Zone de travail : hôte de la cascade (câblée aux endpoints existants) -->
    <section class="card">
      <div class="bd">
        <div class="worktop">
          <span class="eb">Phase 1 · Création / récupération du bien</span>
          <span class="st">Étape 1 / 4</span>
          <h2>Identifier l'immeuble</h2>
        </div>
        <div class="work" id="p1-work">

          <!-- ÉTAPE immeuble : adresse Google (composant data-places réutilisé) -->
          <div class="block m-immeuble" data-castep-pane="immeuble">
            <div class="bh"><span class="tag t-immeuble"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4 8 4v14"/></svg>Immeuble</span><span class="bt">Adresse de l'immeuble</span>
            </div>
            <div class="bb">
              <input type="text" id="ml-addr" class="search" style="width:100%;flex:1 1 100%"
                     placeholder="Ex : 15 rue de la République, Lyon…" autocomplete="off"
                     data-places-input
                     data-places-endpoint="<?= h($EP['places_auto']) ?>"
                     data-places-details-endpoint="<?= h($EP['places_details']) ?>"
                     data-places-geocode-endpoint="<?= h($EP['geocode']) ?>"
                     data-places-street1="ml-f-adresse1" data-places-postal="ml-f-cp" data-places-city="ml-f-ville"
                     data-places-lat="ml-f-lat" data-places-lng="ml-f-lng" data-places-place-id="ml-f-placeid"
                     data-places-formatted="ml-f-formatted" data-places-immeuble-id="ml-f-immid" data-places-country-code="fr">
              <input type="hidden" id="ml-f-adresse1"><input type="hidden" id="ml-f-cp"><input type="hidden" id="ml-f-ville">
              <input type="hidden" id="ml-f-lat"><input type="hidden" id="ml-f-lng"><input type="hidden" id="ml-f-placeid">
              <input type="hidden" id="ml-f-formatted"><input type="hidden" id="ml-f-immid">
              <div class="datarow"><span class="k">Statut</span><span class="v" id="ml-imm-state">Tapez l'adresse — le logiciel reconnaît l'immeuble et récupère les infos publiques.</span></div>

              <!-- Résultat auto : rempli par mission-cascade.js -->
              <div id="ml-imm-result" hidden>
                <img id="ml-sv" alt="Street View" style="display:none;width:100%;max-width:340px;border-radius:12px;border:1px solid var(--line);margin:2px 0 4px">
                <div class="attendus"><div class="ah" id="ml-imm-lines-h">ℹ Infos publiques récupérées automatiquement</div>
                  <div id="ml-imm-lines"></div>
                </div>
                <div id="ml-imm-confirm" hidden>
                  <div class="datarow"><span class="k">Immeuble</span><span class="v" id="ml-imm-resume"></span><button class="mini" id="ml-imm-oui">✓ C'est bien cet immeuble</button><button class="mini ghost" id="ml-imm-non">✗ Non, ce n'est pas cet immeuble</button></div>
                </div>
                <div id="ml-imm-doublon"></div>
              </div>
            </div>
          </div>

          <!-- Étapes suivantes (placeholders — câblage progressif) -->
          <div class="block m-tiers" data-castep-pane="proprio" hidden>
            <div class="bh"><span class="tag t-tiers"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>Tiers</span><span class="bt">Le propriétaire</span></div>
            <div class="bb"><div class="datarow"><span class="v">Recherche du propriétaire (base MBI puis registre). Astuce : depuis l'immeuble, un clic sur un propriétaire connu vous amène directement au bien.</span></div></div>
          </div>
          <div class="block m-bien" data-castep-pane="bien" hidden>
            <div class="bh"><span class="tag t-bien"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-7 9 7"/><path d="M5 10v9h14v-9"/></svg>Bien</span><span class="bt">Le bien</span></div>
            <div class="bb"><div class="datarow"><span class="v">Type · pièces · étage + mandat de gestion.</span></div></div>
          </div>
          <div class="block m-doc" data-castep-pane="docs" hidden>
            <div class="bh"><span class="tag t-doc"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l3 3v15H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>Documents</span><span class="bt">Documents</span></div>
            <div class="bb"><div class="datarow"><span class="v">DPE, photos, diagnostics → chargement et classement GED.</span></div></div>
          </div>

        </div>
      </div>
    </section>

    <!-- Colonne aides -->
    <section class="card">
      <div class="tabs" role="tablist">
        <button class="tab" role="tab" aria-selected="true" data-tab="conseils">Conseils</button>
        <button class="tab" role="tab" aria-selected="false" data-tab="juridique">Juridique</button>
        <button class="tab" role="tab" aria-selected="false" data-tab="histo">Historique</button>
      </div>
      <div data-pane="conseils">
        <div class="why"><div class="h">💡 Pourquoi cette étape&nbsp;?</div><p>L'immeuble est le <b>pivot anti-doublon</b> : en le reconnaissant, le logiciel retrouve les lots déjà en gestion et les propriétaires connus — vous <b>reprenez</b> au lieu de recréer.</p></div>
        <div class="aide"><h4>Identifier l'immeuble</h4>Tapez l'adresse (recherche Google). Si l'immeuble est connu, choisissez un lot existant (bien déjà en gestion) ou continuez pour créer un nouveau bien.</div>
      </div>
      <div data-pane="juridique" hidden><div class="aide"><h4>À réunir</h4><ul>
        <li><b>Mandat de gestion</b> signé (nouveau bien).</li>
        <li><b>DPE</b> en cours de validité.</li>
        <li><b>Diagnostics</b> obligatoires selon la zone.</li></ul></div></div>
      <div data-pane="histo" hidden><div class="aide"><h4>Historique</h4><ul><li>Instance non encore démarrée.</li></ul></div></div>
    </section>

  </div>
</div><!-- /.shell -->

<div class="actbar"><div class="in">
  <button class="abtn ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>Précédent</button>
  <span class="savehint" id="sh"><svg viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="var(--ok)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="m4 10 4 4 8-9"/></svg>Tout est enregistré automatiquement</span>
</div></div>

<?php require __DIR__ . '/_mission_foot.php'; ?>
