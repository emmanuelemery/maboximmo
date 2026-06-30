<?php
declare(strict_types=1);
/**
 * bien_nouveau.php — Écran « Création d'un bien » (pipeline en accordéon).
 *
 * Pipeline 3 étapes : ① Immeuble → ② Propriétaire → ③ Bien, une seule ouverte à la fois.
 * Étape 1 : adresse → révélation progressive (sources officielles) + confirmation Street View.
 * Étape 2 : un champ → recherche d'abord dans la base (gratuit) puis registre (Pappers).
 * Étape 3 : type/pièces/étage/mandat en tap + dépôt DPE/photos. Diaporama de fin.
 *
 * (Ancienne version carrousel archivée : bien_nouveau_carrousel.php.)
 * Règles (Constitution) : pas de <select>, clavier = exception, une seule étape
 * ouverte à la fois, étapes verrouillées non interactives, confiance affichée.
 *
 * Accès : admin / super admin (pilote). ÉCRITURES = Phase 6 (à venir).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_admin_or_super_admin();

$GOOGLE_MAPS_API_KEY = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$au = static fn(string $p) => function_exists('app_url') ? app_url($p) : $p;
$assets = static fn(string $p) => function_exists('asset_url') ? asset_url($p) : $p;

// Agences de la société de l'user (pour l'attribution du bien + du propriétaire).
// Par défaut : l'agence de l'user ; modifiable par boutons (toutes les agences de sa société).
$nb2_pdo       = $GLOBALS['pdo'] ?? null;
$nb2_societeId = (int)($_SESSION['id_societe'] ?? 0);
$nb2_agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$nb2_agences   = [];
if ($nb2_pdo && $nb2_societeId > 0) {
    try {
        $stNb = $nb2_pdo->prepare("SELECT id, COALESCE(NULLIF(ville,''), nom_agence) AS lib FROM agences WHERE id_societe = ? ORDER BY lib");
        $stNb->execute([$nb2_societeId]);
        $nb2_agences = $stNb->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $nb2_agences = []; }
}
// Si l'agence de session n'est pas dans la liste, prendre la 1re comme défaut
$nb2_agIds = array_map(static fn($a) => (int)$a['id'], $nb2_agences);
if ($nb2_agenceId <= 0 || !in_array($nb2_agenceId, $nb2_agIds, true)) {
    $nb2_agenceId = $nb2_agIds[0] ?? 0;
}

// Layout agency : sidebar + topbar + fond dégradé (comme bien_360 / agency_biens)
$pageIcon     = ''; // le logo FluxBox remplace l'icône (injecté en CSS sur .tb-title-block)
$pageTitle    = 'Création d\'un bien hors gestion';
$pageSubtitle = '';
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
  /* Fond de page en dégradé (identique à la page Biens) */
  .agency-content{
    background:linear-gradient(135deg, rgba(132,169,140,0.18) 0%, rgba(255,255,255,0) 35%, rgba(72,120,166,0.14) 60%, rgba(255,255,255,0) 85%, rgba(201,123,46,0.16) 100%), #fafbfc;
    background-attachment:fixed;
  }
  /* Logo + titre CENTRÉS sur la page (espaces gauche/droite équilibrés) */
  .agency-topbar .tb-gap{ flex:1 1 0; width:auto; }
  .agency-topbar .tb-center{ flex:1 1 0; }
  .agency-topbar .tb-title-block{ display:flex; align-items:center; }
  .agency-topbar .tb-title-block::before{
    content:''; flex:none;
    width:112px; height:112px; margin-right:12px;
    background:url('<?= h($assets('/images/icons/boutons/fluxbox.png')) ?>') no-repeat center/contain;
  }
  /* ── Charte « luxe sobre » (amande / pétrole / kaki) ── */
  .nb2 { --amande:#ECE4D2; --carte:#FAF7EF; --ligne:#F6F1E6; --petrole:#1B4A52; --petrole-d:#143A41;
         --kaki:#6E6C49; --kaki-t:#565434; --certain:#2E7D52; --propose:#C28A1A; --manquant:#B14A30;
         --nontraite:#A89F8B; --txt:#143A41; --txt2:#6B6655; --muet:#8A8472; --bord:#D7CEB7; --clair:#F2ECDD;
         background:transparent; padding:8px 0 90px; }
  .nb2 * { box-sizing:border-box; }
  .nb2-wrap { max-width:760px; margin:0 auto; }
  .nb2-intro { color:var(--txt2); font-size:13.5px; margin:0 0 18px; }
  .nb2-intro b { color:var(--petrole-d); }

  /* Carte d'étape */
  .nb2-step { background:var(--carte); border:1px solid var(--bord); border-radius:14px; margin-bottom:14px;
              overflow:hidden; transition:opacity .25s, border-color .25s; }
  .nb2-step.s-locked { opacity:.5; pointer-events:none; }
  .nb2-step.s-done   { background:#E9F5F0; border-color:#BFE3D4; }
  .nb2-head { display:flex; align-items:center; gap:14px; padding:16px 20px; cursor:pointer; user-select:none; }
  .nb2-step.s-locked .nb2-head { cursor:default; }
  .nb2-num { width:30px; height:30px; border-radius:50%; flex:none; display:grid; place-items:center;
             font-size:14px; font-weight:800; color:#fff; background:var(--nontraite); transition:background .25s; }
  .nb2-step.s-active .nb2-num { background:var(--petrole); }
  .nb2-step.s-done   .nb2-num { background:var(--certain); }
  .nb2-titles { min-width:0; }
  .nb2-title { font-size:15.5px; font-weight:800; color:var(--petrole-d); letter-spacing:.2px; }
  .nb2-sub { font-size:12px; color:var(--muet); margin-top:1px; }
  .nb2-summary { margin-left:auto; text-align:right; font-size:13.5px; font-weight:800; color:var(--petrole-d);
                 max-width:48%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .nb2-chev { color:var(--muet); font-size:18px; transition:transform .25s; margin-left:12px; }
  .nb2-step.open .nb2-chev { transform:rotate(180deg); }

  /* Corps repliable — s'ouvre selon l'étape OUVERTE (statut indépendant) */
  .nb2-body { max-height:0; overflow:hidden; transition:max-height .3s ease; }
  .nb2-step.open .nb2-body { max-height:2200px; }
  .nb2-body-inner { padding:4px 20px 20px; }

  .nb2-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
  .nb2-btn { border:none; border-radius:10px; padding:11px 22px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; }
  .nb2-btn-primary   { background:var(--petrole); color:var(--clair); }
  .nb2-btn-secondary { background:#fff; color:var(--kaki-t); border:1px solid #CDC3AC; }
  .nb2-btn:disabled { opacity:.45; cursor:default; }

  /* Champ clavier (rare) */
  .nb2-input { width:100%; padding:14px 16px; border:1px solid var(--bord); border-radius:10px; font-size:16px;
               font-family:inherit; background:#fff; color:var(--txt); outline:none; }
  .nb2-input:focus { border-color:var(--petrole); box-shadow:0 0 0 3px rgba(27,74,82,.12); }
  .nb2-hint { font-size:12px; color:var(--muet); margin-top:8px; }

  /* Boutons tap-select (jamais de <select>) */
  .nb2-taprow { display:flex; gap:9px; flex-wrap:wrap; }
  .nb2-tap { border:1px solid #CDC3AC; background:#fff; color:var(--kaki-t); border-radius:10px;
             padding:11px 16px; font-size:13.5px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .12s; }
  .nb2-tap:hover { border-color:var(--petrole); }
  .nb2-tap.sel { background:var(--petrole); color:var(--clair); border-color:var(--petrole); }

  /* Pastilles de confiance */
  .nb2-dot { width:10px; height:10px; border-radius:50%; display:inline-block; vertical-align:middle; }
  .dot-certain{background:var(--certain)} .dot-propose{background:var(--propose)}
  .dot-manquant{background:var(--manquant)} .dot-nontraite{background:transparent;border:1.5px solid var(--nontraite)}

  .nb2-placeholder { color:var(--muet); font-size:13px; font-style:italic; padding:8px 0; }
  @keyframes nb2done {
    0%   { box-shadow:0 0 0 0 rgba(46,125,82,.45); transform:scale(1); }
    50%  { transform:scale(1.06); }
    70%  { box-shadow:0 0 0 26px rgba(46,125,82,0); }
    100% { box-shadow:0 0 0 0 rgba(46,125,82,0); transform:scale(1); }
  }
  #nb2-done-circle:hover { filter:brightness(1.05); }
</style>

<div class="nb2">
 <div class="nb2-wrap">
  <p class="nb2-intro" style="text-align:center">Une adresse, un propriétaire, le bien. <b>Le logiciel travaille, tu valides.</b></p>

  <!-- ÉTAPE 1 — IMMEUBLE -->
  <section class="nb2-step s-active open" id="step-1" data-step="1">
    <div class="nb2-head">
      <div class="nb2-num">1</div>
      <div class="nb2-titles"><div class="nb2-title">Immeuble</div><div class="nb2-sub">L'adresse — le seul endroit où le clavier sert.</div></div>
      <div class="nb2-summary" id="sum-1"></div>
      <div class="nb2-chev">⌄</div>
    </div>
    <div class="nb2-body"><div class="nb2-body-inner" id="body-1">
      <div id="nb2-addr-zone">
        <input type="text" id="nb2-addr" class="nb2-input"
               placeholder="Ex : 15 rue de la République, Lyon…" autocomplete="off"
               data-places-input
               data-places-endpoint="<?= h($au('/api/places_autocomplete.php')) ?>"
               data-places-details-endpoint="<?= h($au('/api/places_details.php')) ?>"
               data-places-geocode-endpoint="<?= h($au('/api/geocode_address.php')) ?>"
               data-places-street1="nb2-f-adresse1" data-places-postal="nb2-f-cp" data-places-city="nb2-f-ville"
               data-places-lat="nb2-f-lat" data-places-lng="nb2-f-lng" data-places-place-id="nb2-f-placeid"
               data-places-formatted="nb2-f-formatted" data-places-immeuble-id="nb2-f-immid" data-places-country-code="fr">
        <div class="nb2-hint">Recherche Google obligatoire — tape l'adresse, le logiciel fait le reste.</div>
        <input type="hidden" id="nb2-f-adresse1"><input type="hidden" id="nb2-f-cp"><input type="hidden" id="nb2-f-ville">
        <input type="hidden" id="nb2-f-lat"><input type="hidden" id="nb2-f-lng"><input type="hidden" id="nb2-f-placeid">
        <input type="hidden" id="nb2-f-formatted"><input type="hidden" id="nb2-f-immid">
      </div>

      <div id="nb2-analyse" style="display:none;margin-top:18px">
        <div style="font-size:13px;font-weight:800;color:var(--petrole-d);margin-bottom:10px">
          <span id="nb2-an-title">Analyse de l'adresse…</span>
          <span id="nb2-compteur" style="background:var(--petrole);color:var(--clair);border-radius:20px;padding:2px 10px;font-size:12px;margin-left:6px">0 infos</span>
        </div>
        <div id="nb2-lines"></div>
        <div id="nb2-synth" style="display:none;margin-top:12px;font-size:13px;font-weight:700;color:var(--certain)"></div>
      </div>

      <div id="nb2-confirm" style="display:none;margin-top:18px;border:1px solid var(--bord);border-radius:12px;padding:14px;background:#fff">
        <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
          <img id="nb2-sv" alt="Façade" style="width:160px;height:104px;object-fit:cover;border-radius:10px;border:1px solid var(--bord);display:none">
          <div style="flex:1;min-width:180px">
            <div id="nb2-confirm-resume" style="font-size:14px;font-weight:800;color:var(--petrole-d)"></div>
            <div style="font-size:12px;color:var(--muet);margin-top:4px">📍 Photo Google Street View récupérée</div>
            <div style="font-size:14px;font-weight:700;color:var(--txt);margin-top:12px">C'est bien cet immeuble ?</div>
            <div class="nb2-taprow" style="margin-top:8px">
              <button type="button" class="nb2-tap" id="nb2-oui" style="background:var(--certain);color:#fff;border-color:var(--certain)">Oui, c'est lui</button>
              <button type="button" class="nb2-tap" id="nb2-non">Non</button>
            </div>
          </div>
        </div>
      </div>
    </div></div>
  </section>

  <!-- ÉTAPE 2 — PROPRIÉTAIRE -->
  <section class="nb2-step s-locked" id="step-2" data-step="2">
    <div class="nb2-head">
      <div class="nb2-num">2</div>
      <div class="nb2-titles"><div class="nb2-title">Propriétaire</div><div class="nb2-sub">Personne physique ou société.</div></div>
      <div class="nb2-summary" id="sum-2"></div>
      <div class="nb2-chev">⌄</div>
    </div>
    <div class="nb2-body"><div class="nb2-body-inner" id="body-2">

      <div style="font-size:13px;font-weight:700;color:var(--txt);margin-bottom:8px">Tape un nom, un SIRET ou une société — le logiciel cherche.</div>
      <input type="text" id="pp-q" class="nb2-input" autocomplete="off"
             placeholder="Nom du propriétaire, SIRET / SIREN ou nom de société…">
      <div id="pp-status" style="font-size:12px;color:var(--muet);margin-top:8px;min-height:16px"></div>

      <!-- Personnes déjà connues (anti-doublon) -->
      <div id="pp-res-tiers" style="margin-top:8px"></div>
      <!-- Sociétés trouvées au registre (Pappers) -->
      <div id="pp-res-soc" style="margin-top:8px"></div>

      <!-- Fiche société sélectionnée -->
      <div id="pp-soc-result" style="display:none;margin-top:12px;border:1px solid var(--bord);border-radius:12px;padding:14px;background:#fff">
        <div id="pp-soc-doublon"></div>
        <div id="pp-soc-detail"></div>
        <div style="font-size:13px;font-weight:700;color:var(--txt);margin:14px 0 8px">Qui contactons-nous ?</div>
        <div class="nb2-taprow" id="pp-contact-choix"></div>
        <div id="pp-contact-autre" style="display:none;margin-top:10px">
          <input type="text" id="pp-autre-nom" class="nb2-input" placeholder="Nom du contact" style="margin-bottom:10px">
          <input type="text" id="pp-autre-coord" class="nb2-input" placeholder="Téléphone ou email">
        </div>
      </div>

      <!-- Création d'une personne physique (si aucun existant choisi) -->
      <div id="pp-phys" style="display:none;margin-top:12px">
        <div style="font-size:12.5px;color:var(--txt2);margin-bottom:8px">Nouvelle personne — ajoute un moyen de contact :</div>
        <input type="text" id="pp-contact" class="nb2-input" placeholder="Téléphone ou email (un seul suffit)">
      </div>

      <div class="nb2-footer"><button type="button" class="nb2-btn nb2-btn-primary" id="pp-valider" disabled>Valider</button></div>
    </div></div>
  </section>

  <!-- ÉTAPE 3 — DOCUMENTS -->
  <section class="nb2-step s-locked" id="step-3" data-step="3">
    <div class="nb2-head">
      <div class="nb2-num">3</div>
      <div class="nb2-titles"><div class="nb2-title">Bien</div><div class="nb2-sub">Type, pièces, mandat — et le DPE (pour publier).</div></div>
      <div class="nb2-summary" id="sum-3"></div>
      <div class="nb2-chev">⌄</div>
    </div>
    <div class="nb2-body"><div class="nb2-body-inner" id="body-3">

      <style>
        /* Boutons d'agence : couleur société = bleu MBI, même effet graphique que le bouton principal */
        #d-agence .nb2-tap{
          background:#fff; color:#243B5C; border:1.5px solid #c3cede; font-weight:700;
          transition:background .15s, box-shadow .15s, color .15s, border-color .15s;
        }
        #d-agence .nb2-tap:hover{ border-color:#243B5C; }
        #d-agence .nb2-tap.sel{
          background:linear-gradient(135deg,#243B5C,#1a2c45); color:#fff;
          border:1px solid #243B5C; box-shadow:0 2px 8px rgba(36,59,92,.28);
        }
      </style>
      <?php if (count($nb2_agences) > 0): ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <div style="font-size:13px;font-weight:700;color:var(--txt)">Attribué à l'agence</div>
        <div style="font-size:11.5px;color:var(--muet)">— le bien et le propriétaire</div>
      </div>
      <div class="nb2-taprow" id="d-agence" style="margin-bottom:18px">
        <?php foreach ($nb2_agences as $ag): $aid=(int)$ag['id']; ?>
        <button type="button" class="nb2-tap<?= $aid===$nb2_agenceId ? ' sel' : '' ?>" data-agence="<?= $aid ?>">🏛️ <?= h($ag['lib']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="font-size:13px;font-weight:700;color:var(--txt);margin-bottom:8px">Type du bien</div>
      <div class="nb2-taprow" id="d-type" style="margin-bottom:16px">
        <button type="button" class="nb2-tap" data-type="maison">🏠 Maison</button>
        <button type="button" class="nb2-tap" data-type="appartement">🏢 Appartement</button>
        <button type="button" class="nb2-tap" data-type="immeuble">🏬 Immeuble</button>
        <button type="button" class="nb2-tap" data-type="terrain">🌳 Terrain</button>
        <button type="button" class="nb2-tap" data-type="bureau">🗂️ Bureau</button>
        <button type="button" class="nb2-tap" data-type="local_commercial">🏪 Local commercial</button>
        <button type="button" class="nb2-tap" data-type="entrepot">📦 Entrepôt</button>
        <button type="button" class="nb2-tap" data-type="autre">Autre</button>
      </div>

      <div style="font-size:13px;font-weight:700;color:var(--txt);margin-bottom:8px">Nombre de pièces</div>
      <div class="nb2-taprow" id="d-pieces" style="margin-bottom:16px">
        <button type="button" class="nb2-tap" data-pieces="1">1</button>
        <button type="button" class="nb2-tap" data-pieces="2">2</button>
        <button type="button" class="nb2-tap" data-pieces="3">3</button>
        <button type="button" class="nb2-tap" data-pieces="4">4</button>
        <button type="button" class="nb2-tap" data-pieces="5+">5+</button>
      </div>

      <div id="d-etage-q" style="display:none">
        <div style="font-size:13px;font-weight:700;color:var(--txt);margin-bottom:8px">Étage</div>
        <div class="nb2-taprow" id="d-etage" style="margin-bottom:16px">
          <button type="button" class="nb2-tap" data-etage="rdc">RDC</button>
          <button type="button" class="nb2-tap" data-etage="1">1er</button>
          <button type="button" class="nb2-tap" data-etage="2">2ème</button>
          <button type="button" class="nb2-tap" data-etage="3+">3ème+</button>
        </div>
      </div>

      <div style="font-size:13px;font-weight:700;color:var(--txt);margin-bottom:8px">Type de mandat</div>
      <div class="nb2-taprow" id="d-mandat" style="margin-bottom:18px">
        <button type="button" class="nb2-tap" data-mandat="vente">💼 Vente</button>
        <button type="button" class="nb2-tap" data-mandat="location">🔑 Location</button>
      </div>

      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:stretch;margin-bottom:6px">
        <!-- DPE : tuile principale, incitative -->
        <label id="d-dpe-zone" style="flex:2;min-width:240px;border:2px dashed var(--petrole);border-radius:14px;padding:22px 18px;text-align:center;cursor:pointer;background:#fff;transition:background .15s">
          <div style="font-size:40px;line-height:1">🏷️</div>
          <div style="font-weight:800;color:var(--petrole-d);font-size:15px;margin-top:6px">Déposer le DPE</div>
          <div id="d-dpe-state" style="font-size:12px;color:var(--muet);margin-top:3px">Le logiciel remplira surface, classe, GES…</div>
          <input type="file" id="d-dpe" accept="application/pdf" style="display:none">
        </label>
        <!-- Photos : secondaire, optionnel -->
        <label id="d-photos-zone" style="flex:1;min-width:160px;border:1.5px dashed var(--bord);border-radius:14px;padding:22px 14px;text-align:center;cursor:pointer;background:#fff">
          <div style="font-size:32px;line-height:1">📷</div>
          <div style="font-weight:700;color:var(--kaki-t);font-size:13px;margin-top:6px">Photos</div>
          <div id="d-photos-state" style="font-size:11.5px;color:var(--muet);margin-top:3px">éventuellement</div>
          <input type="file" id="d-photos" accept="image/*" multiple style="display:none">
        </label>
      </div>

      <!-- Bail + Mandat : extraction IA automatique (réutilise bien_intake_upload) -->
      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:stretch;margin:12px 0 6px">
        <label id="d-bail-zone" style="flex:1;min-width:200px;border:1.5px dashed var(--bord);border-radius:14px;padding:18px 14px;text-align:center;cursor:pointer;background:#fff">
          <div style="font-size:30px;line-height:1">📄</div>
          <div style="font-weight:700;color:var(--kaki-t);font-size:13px;margin-top:6px">Déposer le bail</div>
          <div id="d-bail-state" style="font-size:11.5px;color:var(--muet);margin-top:3px">extraction loyer, dates, locataire…</div>
          <input type="file" id="d-bail" accept="application/pdf" style="display:none">
        </label>
        <label id="d-mandat-zone" style="flex:1;min-width:200px;border:1.5px dashed var(--bord);border-radius:14px;padding:18px 14px;text-align:center;cursor:pointer;background:#fff">
          <div style="font-size:30px;line-height:1">📜</div>
          <div style="font-weight:700;color:var(--kaki-t);font-size:13px;margin-top:6px">Déposer le mandat</div>
          <div id="d-mandat-state" style="font-size:11.5px;color:var(--muet);margin-top:3px">extraction type, honoraires, dates…</div>
          <input type="file" id="d-mandat" accept="application/pdf" style="display:none">
        </label>
      </div>

      <div class="nb2-footer">
        <button type="button" class="nb2-btn nb2-btn-primary" id="d-terminer" disabled>Terminer</button>
      </div>
    </div></div>
  </section>

  <!-- BIEN CRÉÉ — gros cercle animé, cliquable → bien 360 -->
  <div id="step-done" style="display:none;text-align:center;padding:34px 0 10px">
    <button type="button" id="nb2-done-circle" aria-label="Ouvrir le bien"
      style="width:128px;height:128px;border-radius:50%;border:none;cursor:pointer;background:var(--certain);
             color:#fff;font-size:58px;line-height:1;display:inline-flex;align-items:center;justify-content:center;
             box-shadow:0 0 0 0 rgba(46,125,82,.45);animation:nb2done 1.8s ease-out infinite">✓</button>
    <div style="font-size:19px;font-weight:800;color:var(--petrole-d);margin-top:18px">C'est terminé !</div>
    <div style="font-size:13.5px;color:var(--kaki-t);margin-top:4px">Clique pour ouvrir le bien →</div>
  </div>
 </div>
</div>

<script>
(function(){
  var steps = [1,2,3];
  // statut de progression (indépendant de l'étape affichée)
  var status = { 1:'active', 2:'locked', 3:'locked' };
  var openStep = 1; // étape dont le corps est visible (une seule à la fois)

  function el(n){ return document.getElementById('step-' + n); }
  function paint(){
    steps.forEach(function(n){
      el(n).className = 'nb2-step s-' + status[n] + (openStep === n ? ' open' : '');
    });
  }
  function openIt(n){
    if (status[n] === 'locked') return; // verrouillée = non interactive
    openStep = n; paint();
  }
  function validate(n){
    status[n] = 'done';
    if (n < 3) { status[n+1] = 'active'; openStep = n+1; paint(); }
    else {
      openStep = 0; paint();
      document.getElementById('step-done').style.display = '';
      document.getElementById('step-done').scrollIntoView({behavior:'smooth', block:'center'});
    }
  }
  // Cercle « terminé » → ouvre le bien 360 (id réel fourni par la Phase 6)
  document.getElementById('nb2-done-circle').addEventListener('click', function(){
    var id = window.NB2_bienId || 0;
    window.location.href = <?= json_encode($au('/bien_360.php')) ?> + (id ? ('?id=' + id) : '');
  });

  // En-tête cliquable : ré-ouvre une étape (active ou déjà validée) pour consultation
  steps.forEach(function(n){
    el(n).querySelector('.nb2-head').addEventListener('click', function(){ openIt(n); });
  });
  document.querySelectorAll('[data-validate]').forEach(function(b){
    b.addEventListener('click', function(e){ e.stopPropagation(); validate(+b.getAttribute('data-validate')); });
  });
  document.querySelectorAll('[data-back]').forEach(function(b){
    b.addEventListener('click', function(e){ e.stopPropagation(); var n=+b.getAttribute('data-back'); if(n>1) openIt(n-1); });
  });

  // Résumé aligné à droite dans la card (visible même repliée)
  function setSummary(n, text){ var e = document.getElementById('sum-' + n); if (e) e.textContent = text || ''; }
  // Récap partagé (alimenté par les 3 étapes, lu par le diaporama final)
  window.NB2_recap = { immeuble:null, proprietaire:null, bien:null };
  // Payload de création réelle (Phase 6) alimenté par les 3 étapes, envoyé à api/bien_creer.php
  window.NB2_create = { immeuble:null, enrichissement:{}, proprietaire:null, bien:null };
  // Exposé pour que les modules d'étape pilotent la progression
  window.NB2 = { validate: validate, open: openIt, status: status, setSummary: setSummary };
  paint();
})();
</script>

<!-- Modal anti-doublon : lots & propriétaires déjà dans l'immeuble -->
<div id="nb2-imm-modal" style="display:none;position:fixed;inset:0;z-index:9000;align-items:center;justify-content:center;padding:20px">
  <div id="nb2-imm-ov" style="position:absolute;inset:0;background:rgba(15,23,42,.55)"></div>
  <div style="position:relative;background:#FAF7EF;border:1px solid #D7CEB7;border-radius:16px;width:min(640px,100%);max-height:88vh;overflow:auto;box-shadow:0 24px 64px rgba(0,0,0,.3);padding:20px">
    <div style="font-size:15px;font-weight:800;color:#143A41;margin-bottom:4px">🏢 Cet immeuble est déjà connu</div>
    <div style="font-size:12.5px;color:#8A8472;margin-bottom:14px">Reprends un lot ou un propriétaire existant plutôt que de créer un doublon.</div>
    <div id="nb2-imm-body"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">
      <button type="button" id="nb2-imm-new" style="background:#1B4A52;color:#F2ECDD;border:none;border-radius:10px;padding:11px 20px;font-size:14px;font-weight:700;cursor:pointer">+ Nouveau lot ici</button>
    </div>
  </div>
</div>
<style>
  #nb2-imm-modal .nb2-imm-h{font-size:12.5px;font-weight:800;color:#143A41;margin-bottom:8px}
  #nb2-imm-modal .nb2-imm-lot{display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid #EDE7D8;border-radius:10px;margin-bottom:7px;text-decoration:none;color:#143A41;background:#fff;cursor:pointer;transition:border-color .12s,background .12s}
  #nb2-imm-modal .nb2-imm-lot:hover{border-color:#1B4A52;background:#F2F7F5}
  #nb2-imm-modal .nb2-imm-go{color:#1B4A52;font-weight:800;font-size:16px}
  #nb2-imm-modal .nb2-imm-props{display:flex;flex-wrap:wrap;gap:8px}
  #nb2-imm-modal .nb2-imm-prop{background:#fff;border:1px solid #CDC3AC;border-radius:10px;padding:9px 14px;font-size:13px;font-weight:700;color:#565434;cursor:pointer}
  #nb2-imm-modal .nb2-imm-prop:hover{border-color:#1B4A52;color:#143A41}
</style>
<script src="<?= h($assets('/js/places.js')) ?>?v=<?= @filemtime(__DIR__ . '/js/places.js') ?: '1' ?>"></script>
<?php if (!empty($GOOGLE_MAPS_API_KEY)): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>
<script>
/* ── ÉTAPE 1 — Immeuble : adresse → révélation progressive → confirmation ── */
(function(){
  var GKEY  = <?= json_encode((string)$GOOGLE_MAPS_API_KEY) ?>;
  var EP = {
    recon: <?= json_encode($au('/api/immeuble_reconnaitre.php')) ?>,
    cad:   <?= json_encode($au('/api/geo_cadastre_plu.php')) ?>,
    urba:  <?= json_encode($au('/api/geo_urbanisme.php')) ?>,
    risk:  <?= json_encode($au('/api/geo_risques.php')) ?>,
    copro: <?= json_encode($au('/api/registre_copro.php')) ?>,
    alt:   <?= json_encode($au('/api/geo_altitude.php')) ?>
  };
  var CONTENU = <?= json_encode($au('/api/immeuble_contenu.php')) ?>;
  var BIEN360 = <?= json_encode($au('/bien_360.php')) ?>;
  var geo = { lat:'', lng:'', label:'', adresse1:'', ville:'' };
  var resu = { immeuble_id:0, immat:'', lots:'', constr:'', nom:'' };
  var total = 0, pending = 0;
  var recapLines = [], lineLabels = {};
  var recapDetails = []; // détail complet par source (pour le diaporama)
  var enrich = {};       // données brutes à persister (cadastre/plu/altitude/registre)
  function pushDetail(icon, title, items){ if (items && items.length) recapDetails.push({ icon:icon, title:title, items:items }); }

  // Nom immeuble (convention) : "{num} {voie SANS type}_{VILLE}", MAJ, sans accent
  function deriveImmNom(adr, ville){
    if (!adr) return '';
    var types = /\b(rue|avenue|av|bd|boulevard|impasse|imp|chemin|chem|allee|all[ée]e|place|pl|route|rte|quai|cours|passage|pass|square|sq|sentier|villa|voie|montee|mont[ée]e|esplanade|faubourg|fbg|traverse)\b/gi;
    var sa = function(s){ return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g,'') : s; };
    var voie = sa(adr).replace(types,' ').replace(/\s+/g,' ').trim().toUpperCase();
    var vl = ville ? sa(ville).replace(/\s+/g,' ').trim().toUpperCase() : '';
    return vl ? voie + '_' + vl : voie;
  }

  var input = document.getElementById('nb2-addr');
  var lines = document.getElementById('nb2-lines');
  var compteur = document.getElementById('nb2-compteur');
  var anTitle = document.getElementById('nb2-an-title');

  function q(s){ return geo.lat && geo.lng ? (s + 'lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng)) : s; }
  function setCompteur(){ compteur.textContent = total + ' info' + (total>1?'s':''); }

  // Une ligne de source : ⚪ → 🟡 en cours → 🟢 ✓ +N (ou 🔴)
  function addLine(key, label){
    lineLabels[key] = label;
    var row = document.createElement('div');
    row.id = 'ln-' + key;
    row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid var(--ligne);font-size:13px';
    row.innerHTML = '<span class="nb2-dot dot-propose" style="animation:nb2pulse 1s infinite"></span>'
      + '<span style="flex:1;color:var(--txt2)">' + label + '</span>'
      + '<span class="ln-res" style="color:var(--muet)">en cours…</span>';
    lines.appendChild(row);
    return row;
  }
  function done(key, ok, n, summary){
    var row = document.getElementById('ln-' + key); if (!row) return;
    var dot = row.querySelector('.nb2-dot'); var res = row.querySelector('.ln-res');
    dot.style.animation = '';
    if (ok) {
      dot.className = 'nb2-dot dot-certain';
      res.innerHTML = (summary ? '<span style="color:var(--txt)">' + summary + '</span> ' : '')
        + (n>0 ? '<b style="color:var(--certain)">+' + n + '</b>' : '<b style="color:var(--certain)">✓</b>');
      if (n>0) { total += n; setCompteur(); }
      if (summary) recapLines.push({ label: lineLabels[key]||'', value: summary });
    } else {
      dot.className = 'nb2-dot dot-manquant';
      res.innerHTML = '<span style="color:var(--manquant)">non récupéré</span>';
    }
    if (--pending === 0) finish();
  }

  function run(key, label, url, handler){
    pending++; addLine(key, label);
    // Timeout client : aucune ligne ne tourne à l'infini (25 s max)
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var to = setTimeout(function(){ if (ctrl) ctrl.abort(); }, 25000);
    fetch(q(url + (url.indexOf('?')>=0?'&':'?')), ctrl ? { signal: ctrl.signal } : undefined)
      .then(function(r){ return r.json(); })
      .then(function(a){ clearTimeout(to); handler(a); })
      .catch(function(){ clearTimeout(to); done(key, false, 0, 'délai dépassé'); });
  }

  input.addEventListener('places:filled', function(ev){
    var d = ev.detail || {};
    geo.lat = d.latitude || ''; geo.lng = d.longitude || '';
    geo.adresse1 = d.adresse_1 || ''; geo.ville = d.ville || '';
    geo.cp = d.code_postal || ''; geo.placeid = d.google_place_id || d.place_id || '';
    geo.label = d.adresse_formatee || ((d.adresse_1||'') + ' ' + (d.ville||''));
    if (!geo.lat || !geo.lng) return;
    document.getElementById('nb2-analyse').style.display = 'block';
    document.getElementById('nb2-confirm').style.display = 'none';
    document.getElementById('nb2-synth').style.display = 'none';
    lines.innerHTML = ''; total = 0; pending = 0; setCompteur();
    confirmShown = false; // nouvelle recherche → la confirmation pourra réapparaître
    recapLines = []; lineLabels = {}; recapDetails = []; enrich = {};
    resu = { immeuble_id:0, immat:'', lots:'', constr:'', nom:'' };

    // Photo Street View (instantané)
    if (GKEY) {
      var sv = document.getElementById('nb2-sv');
      sv.src = 'https://maps.googleapis.com/maps/api/streetview?size=320x208&location=' + encodeURIComponent(geo.lat+','+geo.lng) + '&fov=80&pitch=10&key=' + GKEY;
      sv.style.display = 'block';
    }

    // Sources réelles (chaque ✓ à sa résolution — théâtre honnête)
    run('recon', '🏢 Reconnaissance immeuble', EP.recon
        + '?adresse_1=' + encodeURIComponent(d.adresse_1||'') + '&code_postal=' + encodeURIComponent(d.code_postal||'')
        + '&ville=' + encodeURIComponent(d.ville||'') + '&place_id=' + encodeURIComponent(d.google_place_id||'') + '&', function(a){
      if (a && a.connu) { resu.immeuble_id = a.immeuble_id; resu.nom = a.nom_immeuble || ''; done('recon', true, (a.infos||[]).length, 'reconnu #' + a.immeuble_id);
        pushDetail('🏢', 'Immeuble reconnu', [{label:'Immeuble', value:'#'+a.immeuble_id+(a.nb_biens?(' · '+a.nb_biens+' lot(s) déjà en base'):'')}].concat(a.infos||[])); }
      else { done('recon', true, 0, 'nouvel immeuble'); pushDetail('🏢', 'Nouvel immeuble', [{label:'Adresse', value:geo.label}]); }
      revealConfirm(); // confirmation dispo dès la reconnaissance, sans attendre les sources lentes
    });
    run('cad', '📐 Cadastre & PLU', EP.cad, function(a){
      var n=0, parts=[], items=[];
      if (a && a.parcelle){ if(a.parcelle.section){n++;parts.push('parcelle '+a.parcelle.section+' '+a.parcelle.numero);items.push({label:'Parcelle',value:a.parcelle.section+' '+a.parcelle.numero+(a.parcelle.commune?(' · '+a.parcelle.commune):'')});} if(a.parcelle.contenance){n++;parts.push(a.parcelle.contenance+' m²');items.push({label:'Surface parcelle',value:a.parcelle.contenance+' m²'});} }
      if (a && a.plu && a.plu.type){ n++; parts.push('PLU '+a.plu.type); items.push({label:'Zone PLU',value:a.plu.type+(a.plu.libelle?(' — '+a.plu.libelle):'')}); }
      done('cad', !!(a&&a.ok), n, parts.join(' · '));
      pushDetail('📐', 'Cadastre & PLU', items);
      if (a && a.parcelle && a.parcelle.reference) enrich.cadastre = { reference: a.parcelle.reference };
      if (a && a.plu && a.plu.type) enrich.plu = { type: a.plu.type, libelle: a.plu.libelle||'' };
    });
    run('urba', '🏗️ Urbanisme', EP.urba, function(a){
      var n=0, parts=[], items=[];
      if(a&&a.zone&&a.zone.libelle){n++;parts.push('zone '+a.zone.libelle);items.push({label:'Zone',value:a.zone.libelle+(a.zone.type?(' ('+a.zone.type+')'):'')});}
      if(a&&a.zone&&a.zone.libelong){items.push({label:'Libellé',value:a.zone.libelong});}
      if(a&&a.destinations&&a.destinations.autorise){items.push({label:'Destinations autorisées',value:a.destinations.autorise});}
      if(a&&a.destinations&&a.destinations.interdit){items.push({label:'Interdites',value:a.destinations.interdit});}
      (a&&a.prescriptions||[]).forEach(function(p){ n++; items.push({label:'Servitude',value:p}); });
      if(a&&a.abf){n++;parts.push('ABF');items.push({label:'Secteur protégé',value:'Avis ABF obligatoire'+(a.abf_motif?(' · '+a.abf_motif):'')});}
      done('urba', !!(a&&a.ok), n, parts.join(' · '));
      pushDetail('🏗️', 'Urbanisme', items);
    });
    run('alt', '⛰️ Altitude', EP.alt, function(a){
      done('alt', !!(a&&a.ok&&a.altitude!=null), a&&a.altitude!=null?1:0, a&&a.altitude!=null?(a.altitude+' m'):'');
      if(a&&a.altitude!=null){ pushDetail('⛰️','Altitude',[{label:'Altitude',value:a.altitude+' m'+(a.altitude>800?' (zone DPE > 800 m)':'')}]); enrich.altitude=a.altitude; }
    });
    run('risk', '⚠️ Risques ERP', EP.risk, function(a){
      var n=(a&&a.risques)?a.risques.length:0; done('risk', !!(a&&a.ok), n, n?(n+' risque'+(n>1?'s':'')):'aucun');
      if(n) pushDetail('⚠️','Risques ERP', a.risques.map(function(rq){ return {label: rq.label, value: rq.statut||'présent'}; }));
    });
    run('copro', '🏛️ Copropriété (registre)', EP.copro, function(a){
      if (a && a.trouve){ resu.immat=a.immatriculation; resu.lots=a.nb_lots; resu.constr=a.construction; done('copro', true, (a.infos||[]).length, a.immatriculation+' · '+a.nb_lots+' lots');
        pushDetail('🏛️','Copropriété (registre national)', a.infos||[]);
        enrich.registre = { immatriculation:a.immatriculation, construction:a.construction, date_maj:a.date_maj, nb_lots:a.nb_lots }; }
      else done('copro', true, 0, 'non copropriété');
    });
  });

  var confirmShown = false;
  function revealConfirm(){
    if (confirmShown) return; confirmShown = true;
    var resume = [];
    if (geo.label) resume.push(geo.label);
    if (resu.lots) resume.push(resu.lots + ' lots');
    if (resu.constr) resume.push(resu.constr);
    document.getElementById('nb2-confirm-resume').textContent = resume.join(' · ') || geo.label;
    document.getElementById('nb2-confirm').style.display = 'block';
  }
  function finish(){
    anTitle.textContent = 'Analyse terminée.';
    var synth = document.getElementById('nb2-synth');
    var mins = Math.max(6, Math.round(total*0.8));
    synth.style.display = 'block';
    synth.innerHTML = '≈ ' + mins + ' min économisées · 0 frappe';
    // Le résumé de confirmation s'enrichit (lots/construction) une fois la copro arrivée
    var resume = [];
    if (geo.label) resume.push(geo.label);
    if (resu.lots) resume.push(resu.lots + ' lots');
    if (resu.constr) resume.push(resu.constr);
    document.getElementById('nb2-confirm-resume').textContent = resume.join(' · ') || geo.label;
    revealConfirm();
  }

  document.getElementById('nb2-non').addEventListener('click', function(){
    document.getElementById('nb2-confirm').style.display = 'none';
    document.getElementById('nb2-analyse').style.display = 'none';
    document.getElementById('nb2-addr').value = '';
    document.getElementById('nb2-addr').focus();
  });
  function upVille(v){
    if (!v) return '';
    var sa = v.normalize ? v.normalize('NFD').replace(/[̀-ͯ]/g,'') : v;
    return sa.replace(/\s+/g,' ').trim().toUpperCase();
  }
  document.getElementById('nb2-oui').addEventListener('click', function(){
    // (Phase 6 : ici on créera/attachera l'immeuble + persistera l'enrichissement.)
    var nom = resu.nom || deriveImmNom(geo.adresse1, geo.ville);
    var ville = upVille(geo.ville);
    // Convention : doit finir par _VILLE — on l'ajoute s'il manque (nom stocké sans ville)
    if (nom && ville && nom.toUpperCase().indexOf('_' + ville) < 0) nom = nom.toUpperCase() + '_' + ville;
    nom = nom || geo.label;
    window.NB2_recap.immeuble = { nom: nom, adresse: geo.label, lat: geo.lat, lng: geo.lng, total: total, details: recapDetails.slice() };
    window.NB2_create.immeuble = { id: resu.immeuble_id||0, adresse_1: geo.adresse1, code_postal: geo.cp, ville: geo.ville,
      lat: geo.lat, lng: geo.lng, place_id: geo.placeid, nom: nom };
    enrich.details = recapDetails.slice(); // détail complet par source (lu en modal dans bien_360)
    window.NB2_create.enrichissement = enrich;
    if (window.NB2) window.NB2.setSummary(1, nom);

    // Immeuble DÉJÀ connu → on vérifie lots & propriétaires existants pour éviter les doublons
    var immId = resu.immeuble_id || 0;
    if (immId) {
      fetch(CONTENU + '?immeuble_id=' + encodeURIComponent(immId))
        .then(function(r){ return r.json(); })
        .then(function(a){
          var lots = (a && a.lots) || [], props = (a && a.proprietaires) || [];
          if (lots.length || props.length) { openImmeubleModal(lots, props); }
          else { window.NB2.validate(1); }
        })
        .catch(function(){ window.NB2.validate(1); });
    } else {
      window.NB2.validate(1);
    }
  });

  // ── Modal de sélection : lots & propriétaires déjà dans l'immeuble (anti-doublon) ──
  function openImmeubleModal(lots, props){
    var box = document.getElementById('nb2-imm-modal');
    var body = document.getElementById('nb2-imm-body');
    var html = '';
    if (lots.length) {
      html += '<div class="nb2-imm-h">🏠 ' + lots.length + ' lot(s) déjà dans cet immeuble — clique pour le reprendre :</div>';
      html += lots.map(function(l){
        var meta = [l.type, (l.surface?l.surface+' m²':''), (l.pieces?l.pieces+' pièces':''), (l.etage!=null?('Ét. '+l.etage):''), l.statut, l.proprio?('👤 '+l.proprio):''].filter(Boolean).join(' · ');
        var ref = (l.reference||('Bien #'+l.id));
        return '<div class="nb2-imm-lot" data-bid="'+l.id+'" data-ref="'+ref.replace(/"/g,'&quot;')+'"><div style="flex:1;min-width:0"><b>'+ref+'</b><div style="font-size:12px;color:var(--muet)">'+meta+'</div></div><span class="nb2-imm-go">→</span></div>';
      }).join('');
    }
    if (props.length) {
      html += '<div class="nb2-imm-h" style="margin-top:14px">👥 Propriétaires déjà connus ici — réutilise :</div>';
      html += '<div class="nb2-imm-props">' + props.map(function(p){
        return '<button type="button" class="nb2-imm-prop" data-pid="'+p.id+'" data-tid="'+(p.tiers_id||0)+'" data-nom="'+(p.nom||'').replace(/"/g,'&quot;')+'">👤 '+(p.nom||('#'+p.id))+(p.nb_lots>1?(' ('+p.nb_lots+' lots)'):'')+'</button>';
      }).join('') + '</div>';
    }
    body.innerHTML = html;
    // Reprendre un LOT existant → on NE file PAS en bien_360 : on passe par l'étape 3
    // (chargement DPE / bail / mandat) puis on ouvrira ce bien existant.
    body.querySelectorAll('.nb2-imm-lot').forEach(function(b){
      b.addEventListener('click', function(){
        var bid = +b.getAttribute('data-bid') || 0; var ref = b.getAttribute('data-ref') || ('Bien #'+bid);
        if (!bid) return;
        box.style.display='none';
        window.NB2_existingBienId = bid; window.NB2_bienId = bid;
        // Marque immeuble + propriétaire comme acquis, ouvre l'étape 3
        window.NB2.status[1]='done'; window.NB2.status[2]='done'; window.NB2.status[3]='active';
        window.NB2.setSummary(1, resu.nom || ('Immeuble #'+resu.immeuble_id));
        window.NB2.setSummary(2, 'Repris (bien existant)');
        window.NB2.setSummary(3, ref);
        window.NB2.open(3);
        if (window.NB2_enterExistingBien) window.NB2_enterExistingBien(bid, ref);
      });
    });
    // Réutiliser un propriétaire → on saute directement à l'étape 3 (bien)
    body.querySelectorAll('.nb2-imm-prop').forEach(function(b){
      b.addEventListener('click', function(){
        var nom=b.getAttribute('data-nom');
        window.NB2_create.proprietaire = { existingProprio:+b.getAttribute('data-pid'), existingTiers:+b.getAttribute('data-tid'), type:'reprise', nom:nom };
        window.NB2_recap.proprietaire = { nom:nom, type:'reprise' };
        box.style.display='none';
        window.NB2.validate(1); window.NB2.setSummary(2, nom); window.NB2.validate(2);
      });
    });
    box.style.display='flex';
  }
  document.getElementById('nb2-imm-new').addEventListener('click', function(){
    document.getElementById('nb2-imm-modal').style.display='none'; window.NB2.validate(1);
  });
  document.getElementById('nb2-imm-ov').addEventListener('click', function(){
    document.getElementById('nb2-imm-modal').style.display='none'; window.NB2.validate(1);
  });
})();
</script>
<script>
/* ── ÉTAPE 2 — Propriétaire : UN champ, détection auto (personne/société), anti-doublon ── */
(function(){
  var PAPPERS = <?= json_encode($au('/api/pappers_search.php')) ?>;
  var TIERS   = <?= json_encode($au('/api/tiers_lookup.php')) ?>;
  // type déduit : '' | 'reprise' | 'physique' | 'morale'
  var prop = { type:'', societe:null, contact_choice:'', existingTiers:0, displayNom:'' };

  var qEl       = document.getElementById('pp-q');
  var statusEl  = document.getElementById('pp-status');
  var resTiers  = document.getElementById('pp-res-tiers');
  var resSoc    = document.getElementById('pp-res-soc');
  var socResult = document.getElementById('pp-soc-result');
  var physBox   = document.getElementById('pp-phys');
  var valBtn    = document.getElementById('pp-valider');
  var timer = null, seq = 0;

  function show(el){ el.style.display=''; } function hide(el){ el.style.display='none'; }
  function euro(v){ return (v==null||v==='') ? '' : Number(v).toLocaleString('fr-FR') + ' €'; }

  function refreshValider(){
    valBtn.disabled = !(
      prop.existingTiers ||
      (prop.societe && prop.contact_choice) ||
      (qEl.value.trim().length >= 2 && !prop.societe)   // nouvelle personne physique
    );
  }

  function tiersRow(t){
    var nom = (t.raison_sociale || ((t.prenom||'') + ' ' + (t.nom||''))).trim();
    var meta = [t.ville, t.telephone, t.email].filter(Boolean).join(' · ');
    var typ = (t.type_tiers === 'personne_morale') ? '🏢' : '👤';
    return '<button type="button" class="nb2-tap" data-tiers="' + t.id + '" data-type="' + (t.type_tiers||'') + '" data-nom="' + nom.replace(/"/g,'&quot;') + '" style="display:block;width:100%;text-align:left;margin-bottom:6px">'
      + typ + ' <b>' + nom + '</b>' + (meta?(' <span style="color:var(--muet)">· ' + meta + '</span>'):'') + ' <span style="color:var(--certain);font-weight:700">↩ reprendre</span></button>';
  }
  function bindReprendre(container){
    container.querySelectorAll('[data-tiers]').forEach(function(b){
      b.addEventListener('click', function(){
        prop.existingTiers = +b.getAttribute('data-tiers');
        prop.type = 'reprise'; prop.societe = null; prop.displayNom = b.getAttribute('data-nom') || '';
        resTiers.innerHTML = '<div style="font-size:12.5px;color:var(--certain);font-weight:700">✓ ' + b.getAttribute('data-nom') + ' — repris (pas de doublon)</div>';
        resSoc.innerHTML=''; hide(socResult); hide(physBox);
        refreshValider();
      });
    });
  }

  // ── Recherche : D'ABORD notre base (gratuit). Pappers (PAYANT) seulement si rien trouvé. ──
  function searchAll(){
    // Normalisation : trim + espaces multiples → un seul (la casse est gérée par les APIs)
    var v = qEl.value.trim().replace(/\s+/g,' '); var digits = v.replace(/\D+/g,''); var my = ++seq;
    // Variante pour le REGISTRE : tirets → espaces (Pappers traite « LOCA-VENTE » = « LOCA VENTE »)
    var vPap = v.replace(/[-–—]/g,' ').replace(/\s+/g,' ').trim();
    prop.existingTiers = 0; prop.societe = null; prop.type='';
    resTiers.innerHTML=''; resSoc.innerHTML=''; hide(socResult);
    if (v.length < 2) { statusEl.textContent=''; hide(physBox); refreshValider(); return; }
    statusEl.textContent = 'recherche dans votre base…';
    show(physBox); prop.type='physique'; refreshValider();

    // 1) NOTRE base d'abord (par le nom, casse indifférente) — GRATUIT
    fetch(TIERS + '?q=' + encodeURIComponent(v) + '&limit=6')
      .then(function(r){ return r.json(); })
      .then(function(res){ if (my!==seq) return;
        var items = (res && res.items) ? res.items : [];
        if (items.length) {
          // Propriétaire déjà connu → on s'ARRÊTE ici (pas d'appel Pappers payant)
          resTiers.innerHTML = '<div style="font-size:12px;color:var(--propose);font-weight:700;margin-bottom:6px">⚠️ Déjà connu — reprends plutôt que recréer :</div>' + items.map(tiersRow).join('');
          bindReprendre(resTiers);
          statusEl.textContent = '';
          return;
        }
        // 2) Rien en base → on interroge le registre (Pappers, payant) MAINTENANT seulement
        statusEl.textContent = 'recherche au registre…';
        if (digits.length === 9 || digits.length === 14) { chercherDetail(digits.substr(0,9), my); }
        else if (vPap.length >= 3) { pappersSearch(vPap, my); } // tirets normalisés pour Pappers
        else { statusEl.textContent = ''; }
      }).catch(function(){ statusEl.textContent=''; });
  }
  function pappersSearch(v, my){
    fetch(PAPPERS + '?q=' + encodeURIComponent(v))
      .then(function(r){ return r.json(); })
      .then(function(res){ if (my!==seq) return;
        statusEl.textContent = '';
        var cands = (res && res.data && res.data.candidats) ? res.data.candidats : [];
        if (!cands.length) return;
        resSoc.innerHTML = '<div style="font-size:12px;color:var(--muet);font-weight:700;margin:4px 0 6px">🏢 Sociétés au registre :</div>'
          + cands.map(function(c){
            return '<button type="button" class="nb2-tap" data-siren="' + c.siren + '" style="display:block;width:100%;text-align:left;margin-bottom:6px">'
              + '<b>' + (c.raison_sociale||'') + '</b> <span style="color:var(--muet)">· ' + (c.forme_juridique||'') + ' · ' + (c.ville||'') + ' · ' + c.siren + '</span></button>';
          }).join('');
        resSoc.querySelectorAll('[data-siren]').forEach(function(b){
          b.addEventListener('click', function(){ resSoc.innerHTML=''; resTiers.innerHTML=''; chercherDetail(b.getAttribute('data-siren'), ++seq); });
        });
      }).catch(function(){ statusEl.textContent=''; });
  }
  // Debounce 600 ms (Pappers payant : on limite les appels au strict nécessaire)
  qEl.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(searchAll, 600); });

  function chercherDetail(siren, my){
    statusEl.textContent = 'récupération de la société…';
    fetch(PAPPERS + '?siren=' + encodeURIComponent(siren))
      .then(function(r){ return r.json(); })
      .then(function(res){ if (my && my!==seq) return;
        statusEl.textContent = '';
        if (res && res.ok && res.data) afficheSociete(res.data, res.source);
      }).catch(function(){ statusEl.textContent = ''; });
  }

  function afficheSociete(s, source){
    prop.societe = s; prop.type = 'morale'; prop.contact_choice = ''; prop.existingTiers = 0; prop.displayNom = s.raison_sociale || '';
    hide(physBox); resTiers.innerHTML='';
    var fin = [];
    if (s.capital) fin.push('capital ' + euro(s.capital));
    if (s.chiffre_affaires) fin.push('CA ' + euro(s.chiffre_affaires));
    if (s.resultat!=null && s.resultat!=='') fin.push('résultat ' + euro(s.resultat));
    var ad = s.siege ? [s.siege.adresse, s.siege.code_postal, s.siege.ville].filter(Boolean).join(' ') : '';
    var rows = [['Raison sociale', s.raison_sociale], ['SIREN', s.siren], ['Forme', s.forme_juridique],
      ['Siège', ad], ['Création', s.date_creation], ['Activité', s.naf], ['Finances', fin.join(' · ')]];
    document.getElementById('pp-soc-detail').innerHTML =
      '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><span class="nb2-dot dot-certain"></span>'
      + '<b style="font-size:15px;color:var(--petrole-d)">' + (s.raison_sociale||'') + '</b>'
      + '<span style="font-size:11px;color:var(--muet)">source ' + (source||'') + '</span></div>'
      + rows.filter(function(r){ return r[1]; }).map(function(r){
          return '<div style="font-size:12.5px;color:var(--txt2);padding:2px 0"><span style="color:var(--muet)">' + r[0] + '</span> : ' + r[1] + '</div>'; }).join('');

    var ger = (s.dirigeants && s.dirigeants[0]) ? s.dirigeants[0].nom : '';
    var choix = [['moi','Moi (mes coordonnées)']];
    if (ger) choix.push(['gerant','Le gérant : ' + ger]);
    choix.push(['autre','Autre contact']);
    document.getElementById('pp-contact-choix').innerHTML = choix.map(function(c){
      return '<button type="button" class="nb2-tap" data-contact="' + c[0] + '">' + c[1] + '</button>'; }).join('');
    document.getElementById('pp-contact-choix').querySelectorAll('[data-contact]').forEach(function(b){
      b.addEventListener('click', function(){
        document.querySelectorAll('#pp-contact-choix .nb2-tap').forEach(function(x){ x.classList.remove('sel'); });
        b.classList.add('sel'); prop.contact_choice = b.getAttribute('data-contact');
        document.getElementById('pp-contact-autre').style.display = (prop.contact_choice==='autre') ? '' : 'none';
        refreshValider();
      });
    });
    show(socResult);

    // Anti-doublon société dans notre base (SIRET du siège)
    var dbl = document.getElementById('pp-soc-doublon'); dbl.innerHTML='';
    var siret = (s.siege && s.siege.siret) ? s.siege.siret : '';
    fetch(TIERS + '?type=personne_morale&limit=4' + (siret?('&siret='+encodeURIComponent(siret)):'') + '&q=' + encodeURIComponent(s.raison_sociale||''))
      .then(function(r){ return r.json(); })
      .then(function(res){
        var items = (res && (res.doublons && res.doublons.length ? res.doublons : res.items)) || [];
        if (!items.length) { dbl.innerHTML = '<div style="font-size:12px;color:var(--certain);margin-bottom:8px">✓ Société pas encore dans votre base.</div>'; return; }
        dbl.innerHTML = '<div style="font-size:12px;color:var(--propose);font-weight:700;margin-bottom:6px">⚠️ Déjà connue — reprends-la :</div>' + items.map(tiersRow).join('');
        bindReprendre(dbl);
      }).catch(function(){});
    refreshValider();
  }
  document.getElementById('pp-contact').addEventListener('input', refreshValider);

  // Validation (Phase 6 : persistance réelle + rattachement au bien)
  valBtn.addEventListener('click', function(){
    var nom = prop.displayNom || qEl.value.trim();
    var contact = (document.getElementById('pp-contact')||{}).value || '';
    if (prop.contact_choice === 'autre') contact = (document.getElementById('pp-autre-coord')||{}).value || contact;
    window.NB2_recap.proprietaire = { nom: nom, type: prop.type, societe: prop.societe, contact: prop.contact_choice };
    window.NB2_create.proprietaire = { existingTiers: prop.existingTiers||0, type: prop.type, nom: nom,
      contact: contact, societe: prop.societe, contact_choice: prop.contact_choice };
    if (window.NB2) { window.NB2.setSummary(2, nom); window.NB2.validate(2); }
  });
})();
</script>
<script>
/* ── ÉTAPE 3 — Documents : tap (type/pièces/étage/mandat) + dépôt DPE/photos ── */
(function(){
  var BIENCREER = <?= json_encode($au('/api/bien_creer.php')) ?>;
  var EP_DPE    = <?= json_encode($au('/api/dpe_import_upload.php')) ?>;
  var EP_INTAKE = <?= json_encode($au('/api/bien_intake_upload.php')) ?>;
  var DOC_CSRF  = <?= json_encode(function_exists('csrf_token') ? csrf_token('ajouter_bien') : '') ?>;
  var doc = { type:'', pieces:'', etage:'', mandat:'', dpe:null, photos:0, bail:null, mandatDoc:null,
              id_agence: (<?= json_encode((int)$nb2_agenceId) ?> || 0) };
  function pick(group, attr, cb){
    document.querySelectorAll('#' + group + ' .nb2-tap').forEach(function(b){
      b.addEventListener('click', function(){
        document.querySelectorAll('#' + group + ' .nb2-tap').forEach(function(x){ x.classList.remove('sel'); });
        b.classList.add('sel'); cb(b.getAttribute(attr)); refresh();
      });
    });
  }
  function needEtage(t){ return t==='appartement' || t==='immeuble' || t==='bureau' || t==='local_commercial'; }
  pick('d-type', 'data-type', function(v){
    doc.type = v;
    // Étage seulement pour les biens en étages (appart/immeuble/bureau/local)
    document.getElementById('d-etage-q').style.display = needEtage(v) ? '' : 'none';
    if (!needEtage(v)) doc.etage = '';
  });
  pick('d-pieces', 'data-pieces', function(v){ doc.pieces = v; });
  pick('d-etage', 'data-etage', function(v){ doc.etage = v; });
  pick('d-mandat', 'data-mandat', function(v){ doc.mandat = v; });
  pick('d-agence', 'data-agence', function(v){ doc.id_agence = +v || 0; });

  // Dépôt fichiers : GLISSER ou CLIQUER (hook d'analyse Phase 6 — ici on capte le fichier)
  var dpeInput = document.getElementById('d-dpe');
  var phInput  = document.getElementById('d-photos');
  function handleDpe(file){
    if (!file || file.type !== 'application/pdf') return;
    doc.dpe = file;
    document.getElementById('d-dpe-state').innerHTML = '✓ <b style="color:var(--certain)">' + file.name + '</b> — analyse au démarrage du bien';
  }
  function handlePhotos(list){
    var imgs = Array.prototype.filter.call(list || [], function(f){ return /^image\//.test(f.type); });
    doc.photos = imgs.length;
    if (doc.photos) document.getElementById('d-photos-state').innerHTML = '✓ <b style="color:var(--certain)">' + doc.photos + ' photo(s)</b>';
  }
  dpeInput.addEventListener('change', function(){ handleDpe(dpeInput.files && dpeInput.files[0]); });
  phInput.addEventListener('change', function(){ handlePhotos(phInput.files); });

  // Bail + Mandat (extraction IA réutilisée à la création)
  function handleDoc(key, stateId, file){
    if (!file || file.type !== 'application/pdf') return;
    doc[key] = file;
    document.getElementById(stateId).innerHTML = '✓ <b style="color:var(--certain)">' + file.name + '</b> — extraction au démarrage';
  }
  var bailInput = document.getElementById('d-bail'), mandatInput = document.getElementById('d-mandat');
  bailInput.addEventListener('change', function(){ handleDoc('bail','d-bail-state', bailInput.files && bailInput.files[0]); });
  mandatInput.addEventListener('change', function(){ handleDoc('mandatDoc','d-mandat-state', mandatInput.files && mandatInput.files[0]); });

  // Drag & drop
  function setupDrop(zoneId, onFiles){
    var z = document.getElementById(zoneId);
    ['dragenter','dragover'].forEach(function(ev){ z.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); z.style.background = '#EEF6F4'; }); });
    ['dragleave','dragend'].forEach(function(ev){ z.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); z.style.background = '#fff'; }); });
    z.addEventListener('drop', function(e){ e.preventDefault(); e.stopPropagation(); z.style.background = '#fff';
      if (e.dataTransfer && e.dataTransfer.files) onFiles(e.dataTransfer.files); });
  }
  setupDrop('d-dpe-zone', function(files){ handleDpe(files[0]); });
  setupDrop('d-photos-zone', function(files){ handlePhotos(files); });
  setupDrop('d-bail-zone', function(files){ handleDoc('bail','d-bail-state', files[0]); });
  setupDrop('d-mandat-zone', function(files){ handleDoc('mandatDoc','d-mandat-state', files[0]); });

  // Upload d'un document vers un endpoint d'extraction existant (DPE / intake bail|mandat)
  function uploadDoc(endpoint, bienId, file, extra){
    var fd = new FormData();
    fd.append('id_bien', bienId);
    fd.append('fichier', file);
    fd.append('csrf_token', DOC_CSRF);
    if (extra) Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
    return fetch(endpoint, { method:'POST', body: fd }).then(function(r){ return r.json().catch(function(){ return {ok:false}; }); }).catch(function(){ return {ok:false}; });
  }
  function uploadAllDocs(bienId){
    var chain = Promise.resolve();
    if (doc.dpe)       chain = chain.then(function(){ return uploadDoc(EP_DPE, bienId, doc.dpe); });
    if (doc.bail)      chain = chain.then(function(){ return uploadDoc(EP_INTAKE, bienId, doc.bail, {force_type:'bail'}); });
    if (doc.mandatDoc) chain = chain.then(function(){ return uploadDoc(EP_INTAKE, bienId, doc.mandatDoc, {force_type:'mandat'}); });
    return chain;
  }

  // Mode « bien existant » : repris depuis le modal anti-doublon → pas de création,
  // on charge seulement les documents puis on ouvre le bien.
  var existingBienId = 0;
  window.NB2_enterExistingBien = function(id, ref){
    existingBienId = +id || 0;
    if (existingBienId) {
      document.getElementById('d-terminer').disabled = false;
      document.getElementById('d-terminer').textContent = '📎 Charger les documents';
    }
  };

  function refresh(){
    if (existingBienId) { document.getElementById('d-terminer').disabled = false; return; }
    // Terminer ne dépend JAMAIS du DPE (porte = publication, pas création)
    var ok = doc.type && doc.pieces && doc.mandat &&
             (!(doc.type==='appartement'||doc.type==='immeuble') || doc.etage);
    document.getElementById('d-terminer').disabled = !ok;
  }

  var TYPE_LBL = { maison:'MAISON', appartement:'APPARTEMENT', immeuble:'IMMEUBLE', terrain:'TERRAIN',
    bureau:'BUREAU', local_commercial:'LOCAL COMMERCIAL', entrepot:'ENTREPÔT', autre:'AUTRE' };
  var termBtn = document.getElementById('d-terminer');
  termBtn.addEventListener('click', function(){
    // ── Bien EXISTANT repris : pas de création, on charge les docs puis on ouvre le bien ──
    if (existingBienId) {
      termBtn.disabled = true;
      var hasDocs0 = doc.dpe || doc.bail || doc.mandatDoc;
      termBtn.textContent = hasDocs0 ? '⏳ Lecture des documents…' : '⏳ …';
      uploadAllDocs(existingBienId).then(function(){
        window.location.href = <?= json_encode($au('/bien_360.php')) ?> + '?id=' + existingBienId;
      });
      return;
    }
    var lbl = (TYPE_LBL[doc.type] || doc.type.toUpperCase()) + ' ' + doc.pieces + 'P · ' + (doc.mandat||'').toUpperCase();
    window.NB2_recap.bien = { type: TYPE_LBL[doc.type]||doc.type, pieces: doc.pieces, etage: doc.etage,
      mandat: (doc.mandat||'').toUpperCase(), dpe: doc.dpe ? doc.dpe.name : '', photos: doc.photos };
    window.NB2_create.bien = { type: doc.type, pieces: doc.pieces, etage: doc.etage, mandat: doc.mandat };
    window.NB2_create.id_agence = doc.id_agence || 0;

    termBtn.disabled = true; termBtn.textContent = '⏳ Création…';
    fetch(BIENCREER, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(window.NB2_create) })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (res && res.ok && res.data && res.data.bien_id) {
          window.NB2_bienId = res.data.bien_id; // le cercle final ouvrira ce vrai bien
          if (window.NB2) { window.NB2.setSummary(3, lbl); window.NB2.validate(3); }
          // Chargement + extraction des documents déposés (DPE / bail / mandat), puis diaporama
          var hasDocs = doc.dpe || doc.bail || doc.mandatDoc;
          if (hasDocs) termBtn.textContent = '⏳ Lecture des documents…';
          uploadAllDocs(res.data.bien_id).then(function(){
            if (window.NB2startRecap) window.NB2startRecap();
          });
        } else {
          termBtn.disabled = false; termBtn.textContent = 'Terminer';
          alert('Création échouée : ' + ((res && res.error) || 'erreur inconnue'));
        }
      })
      .catch(function(){ termBtn.disabled = false; termBtn.textContent = 'Terminer'; alert('Création échouée (réseau).'); });
  });
})();
</script>
<style>@keyframes nb2pulse{0%,100%{opacity:1}50%{opacity:.35}}</style>

<!-- ════ DIAPORAMA DE FIN — « le logiciel a déjà tout rangé » ════ -->
<div id="nb2-recap-show" style="display:none;position:fixed;inset:0;z-index:9500;background:#ECE4D2;flex-direction:column">
  <div id="recap-bars" style="display:flex;gap:6px;padding:18px 22px 6px"></div>
  <div id="recap-stage" style="flex:1;display:flex;align-items:center;justify-content:center;padding:20px;cursor:pointer"></div>
  <div style="display:flex;gap:12px;justify-content:center;align-items:center;padding:14px 0 26px">
    <button type="button" id="recap-prev" class="recap-ctrl">◀</button>
    <button type="button" id="recap-play" class="recap-ctrl">⏸</button>
    <button type="button" id="recap-next" class="recap-ctrl">▶</button>
    <span style="width:1px;height:26px;background:#D7CEB7;margin:0 4px"></span>
    <button type="button" class="recap-ctrl recap-speed" data-speed="lent" style="width:auto;padding:0 12px">🐢 Lent</button>
    <button type="button" class="recap-ctrl recap-speed sel" data-speed="normal" style="width:auto;padding:0 12px">Normal</button>
    <button type="button" class="recap-ctrl recap-speed" data-speed="rapide" style="width:auto;padding:0 12px">🐇 Rapide</button>
    <span style="width:1px;height:26px;background:#D7CEB7;margin:0 4px"></span>
    <button type="button" id="recap-replay" class="recap-ctrl" style="width:auto;padding:0 16px">↺ Recommencer</button>
    <button type="button" id="recap-close" class="recap-ctrl" style="width:auto;padding:0 16px">✕ Fermer</button>
  </div>
</div>
<style>
  .recap-ctrl{height:40px;min-width:40px;border-radius:10px;border:1px solid #CDC3AC;background:#FAF7EF;color:#565434;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
  .recap-ctrl:hover{background:#F2ECDD}
  .recap-speed.sel{background:#1B4A52;color:#F2ECDD;border-color:#1B4A52}
  .recap-bar{flex:1;height:4px;border-radius:3px;background:#D7CEB7;overflow:hidden}
  .recap-bar > i{display:block;height:100%;width:0;background:#1B4A52}
  .recap-slide{max-width:620px;text-align:center;animation:recapIn .4s cubic-bezier(.19,1,.22,1)}
  @keyframes recapIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
  .recap-big{font-size:60px;line-height:1}
  .recap-title{font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6E6C49;margin-top:10px}
  .recap-val{font-size:26px;font-weight:800;color:#143A41;margin-top:6px}
  .recap-note{font-size:14px;color:#6B6655;margin-top:12px}
  .recap-img{width:300px;max-width:80vw;height:180px;object-fit:cover;border-radius:14px;border:1px solid #D7CEB7;margin-top:14px}
  .recap-items{margin:16px auto 0;text-align:left;max-width:520px;max-height:46vh;overflow:auto}
  .recap-it{display:flex;justify-content:space-between;gap:24px;padding:7px 2px;border-bottom:1px solid #E3DAC4;font-size:14.5px}
  .recap-it span{color:#8A8472}
  .recap-it b{color:#143A41;text-align:right;font-weight:700}
  .recap-circle{width:120px;height:120px;border-radius:50%;border:none;background:#2E7D52;color:#fff;font-size:54px;cursor:pointer;animation:nb2done 1.8s ease-out infinite;margin-top:8px}
</style>
<script>
(function(){
  var GKEY = <?= json_encode((string)$GOOGLE_MAPS_API_KEY) ?>;
  var BIEN360 = <?= json_encode($au('/bien_360.php')) ?>;
  var show = document.getElementById('nb2-recap-show');
  var stage = document.getElementById('recap-stage');
  var barsBox = document.getElementById('recap-bars');
  // Vitesse choisie par l'user — durée PAR SLIDE (pour pouvoir lire et comprendre)
  var SPEEDS = { lent:3400, normal:2000, rapide:1100 };
  var speed = 'normal';
  var DUR = SPEEDS[speed], slides = [], idx = 0, paused = false, elapsed = 0, lastTs = null, raf = null;

  function buildSlides(){
    var r = window.NB2_recap || {}; var s = [];
    var im = r.immeuble || {}; var pr = r.proprietaire || {}; var bi = r.bien || {};
    var mins = Math.max(6, Math.round((im.total||0) * 0.8));
    s.push({ big:'✨', title:'Le logiciel a déjà tout rangé', val:(im.total||0)+' infos captées', note:'≈ '+mins+' min gagnées · 0 frappe — tu retrouveras TOUT dans le bien.' });
    if (im.nom) {
      var img = (GKEY && im.lat && im.lng) ? ('https://maps.googleapis.com/maps/api/streetview?size=600x360&location='+encodeURIComponent(im.lat+','+im.lng)+'&fov=80&pitch=10&key='+GKEY) : '';
      s.push({ big:'🏢', title:'Immeuble', val: im.nom, note: im.adresse||'', img: img });
    }
    // Une slide DÉTAILLÉE par source (toutes les infos extraites, label : valeur)
    (im.details||[]).forEach(function(d){ s.push({ big:d.icon, title:d.title, items:d.items }); });
    if (pr.nom) {
      var items = [];
      if (pr.societe) {
        var so = pr.societe; var g=(so.dirigeants&&so.dirigeants[0])?so.dirigeants[0].nom:'';
        if (so.forme_juridique) items.push({label:'Forme', value:so.forme_juridique});
        if (so.siren) items.push({label:'SIREN', value:so.siren});
        if (g) items.push({label:'Gérant', value:g});
        if (so.capital) items.push({label:'Capital', value:Number(so.capital).toLocaleString('fr-FR')+' €'});
        if (so.chiffre_affaires) items.push({label:'CA', value:Number(so.chiffre_affaires).toLocaleString('fr-FR')+' €'});
        if (so.siege && so.siege.adresse) items.push({label:'Siège', value:[so.siege.adresse,so.siege.code_postal,so.siege.ville].filter(Boolean).join(' ')});
      }
      if (pr.contact) items.push({label:'Contact', value: pr.contact==='gerant'?'le gérant':(pr.contact==='moi'?'moi':'autre contact')});
      s.push({ big:'👤', title:'Propriétaire', val: pr.nom, items: items });
    }
    if (bi.type) {
      var bit = [];
      bit.push({label:'Type', value:(bi.type||'').toUpperCase()});
      if (bi.pieces) bit.push({label:'Pièces', value:bi.pieces});
      if (bi.etage) bit.push({label:'Étage', value:bi.etage});
      bit.push({label:'Mandat', value:bi.mandat});
      if (bi.dpe) bit.push({label:'DPE', value:'déposé ('+bi.dpe+')'});
      if (bi.photos) bit.push({label:'Photos', value:bi.photos});
      s.push({ big:'🔑', title:'Le bien', items: bit });
    }
    s.push({ final:true, big:'', title:'Tout est dans le bien', val:'', note:'Clique pour l\'ouvrir et explorer' });
    return s;
  }

  function renderBars(){
    barsBox.innerHTML = slides.map(function(){ return '<div class="recap-bar"><i></i></div>'; }).join('');
  }
  function setBar(i, ratio){
    var bars = barsBox.querySelectorAll('.recap-bar > i');
    bars.forEach(function(b, k){ b.style.width = (k<i?100:(k===i?Math.round(ratio*100):0)) + '%'; });
  }
  function render(i){
    var sl = slides[i]; if (!sl) return;
    if (sl.final) {
      stage.innerHTML = '<div class="recap-slide"><button type="button" class="recap-circle" id="recap-go">✓</button>'
        + '<div class="recap-val" style="margin-top:18px">'+sl.title+'</div><div class="recap-note">'+sl.note+'</div></div>';
      document.getElementById('recap-go').addEventListener('click', ouvrir);
    } else {
      stage.innerHTML = '<div class="recap-slide">'
        + (sl.big?'<div class="recap-big">'+sl.big+'</div>':'')
        + '<div class="recap-title">'+(sl.title||'')+'</div>'
        + (sl.val?'<div class="recap-val">'+sl.val+'</div>':'')
        + (sl.items&&sl.items.length?('<div class="recap-items">'+sl.items.map(function(it){
              return '<div class="recap-it"><span>'+(it.label||'')+'</span><b>'+(it.value||'')+'</b></div>'; }).join('')+'</div>'):'')
        + (sl.img?'<img class="recap-img" src="'+sl.img+'" alt="">':'')
        + (sl.note?'<div class="recap-note">'+sl.note+'</div>':'')
        + '</div>';
    }
    setBar(i, 0);
  }
  function go(i){
    if (i < 0) i = 0;
    if (i >= slides.length) i = slides.length - 1;
    idx = i; elapsed = 0; render(idx);
    if (slides[idx].final) { paused = true; updatePlay(); setBar(idx,1); }
  }
  function loop(ts){
    if (lastTs == null) lastTs = ts; var dt = ts - lastTs; lastTs = ts;
    if (!paused && slides[idx] && !slides[idx].final) {
      elapsed += dt; setBar(idx, Math.min(1, elapsed/DUR));
      if (elapsed >= DUR) go(idx+1);
    }
    raf = requestAnimationFrame(loop);
  }
  function updatePlay(){ document.getElementById('recap-play').textContent = paused ? '▶' : '⏸'; }
  function ouvrir(){ var id = window.NB2_bienId || 0; window.location.href = BIEN360 + (id?('?id='+id):''); }

  document.getElementById('recap-stage').addEventListener('click', function(e){ if (e.target.id!=='recap-go') go(idx+1); });
  document.getElementById('recap-prev').addEventListener('click', function(){ go(idx-1); });
  document.getElementById('recap-next').addEventListener('click', function(){ go(idx+1); });
  document.getElementById('recap-play').addEventListener('click', function(){ paused=!paused; lastTs=null; updatePlay(); });
  document.querySelectorAll('.recap-speed').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('.recap-speed').forEach(function(x){ x.classList.remove('sel'); });
      b.classList.add('sel'); speed = b.getAttribute('data-speed'); DUR = SPEEDS[speed];
      elapsed = 0; lastTs = null; // la slide en cours repart avec la nouvelle vitesse
    });
  });
  document.getElementById('recap-replay').addEventListener('click', function(){ paused=false; updatePlay(); go(0); });
  document.getElementById('recap-close').addEventListener('click', function(){ show.style.display='none'; if(raf) cancelAnimationFrame(raf); raf=null; });

  window.NB2startRecap = function(){
    slides = buildSlides(); idx = 0; elapsed = 0; lastTs = null; paused = false;
    DUR = SPEEDS[speed];
    renderBars(); render(0); updatePlay();
    show.style.display = 'flex';
    if (!raf) raf = requestAnimationFrame(loop);
  };
})();
</script>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
