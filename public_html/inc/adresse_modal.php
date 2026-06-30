<?php
/**
 * inc/adresse_modal.php — Modal universel de saisie d'adresse
 *
 * Composant réutilisable qui propose :
 *   - Recherche Google Places (API externe)
 *   - Recherche dans les immeubles existants (BDD locale via api/places_autocomplete.php)
 *   - Validation qui remplit des champs cibles + coordonnées GPS
 *
 * USAGE (depuis une page) :
 *   1. Inclure ce fichier en bas du HTML : require_once __DIR__ . '/inc/adresse_modal.php';
 *   2. Inclure le JS : <script src="<?= asset_url('/js/adresse_modal.js') ?>"></script>
 *   3. Inclure le SDK Google + places.js (pour la partie Google)
 *   4. Déclencher l'ouverture :
 *        <button type="button" data-addr-modal-open
 *                data-addr-target-street1="id-adresse-1"
 *                data-addr-target-street2="id-adresse-2"
 *                data-addr-target-postal="id-cp"
 *                data-addr-target-city="id-ville"
 *                data-addr-target-lat="id-lat"
 *                data-addr-target-lng="id-lng"
 *                data-addr-target-placeid="id-placeid"
 *                data-addr-target-formatted="id-formatted"
 *                data-addr-save-endpoint="/api/bien_autosave.php"
 *                data-addr-bien-id="<?= (int)$bienId ?>"
 *                data-addr-csrf="<?= h(csrf_token('ajouter_bien')) ?>">
 *           📍 Rechercher adresse
 *        </button>
 *
 *   Le JS associé (js/adresse_modal.js) se charge du reste.
 */

declare(strict_types=1);

if (defined('ADRESSE_MODAL_INCLUDED')) return;
define('ADRESSE_MODAL_INCLUDED', true);
?>
<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL ADRESSE — composant universel (Google Places + immeubles locaux)
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="addr-modal" class="addr-modal" hidden>
  <div class="addr-modal-overlay" data-addr-modal-close></div>
  <div class="addr-modal-card">
    <div class="addr-modal-head">
      <div class="addr-modal-head-left">
        <img class="addr-modal-logo"
             src="<?= function_exists('asset_url') ? h(asset_url('/images/mbi_annonces_logo2.png')) : '/images/mbi_annonces_logo2.png' ?>"
             alt="Ma Box Immo" loading="lazy">
        <h3>📍 Rechercher / saisir une adresse</h3>
      </div>
      <button type="button" class="addr-modal-x" data-addr-modal-close aria-label="Fermer">×</button>
    </div>

    <div class="addr-modal-body">
      <!-- ── 1. Recherche Google Places ───────────────────────── -->
      <div class="addr-modal-section">
        <label class="addr-modal-label">🌍 Recherche Google / Adresse postale</label>
        <div style="position:relative;">
          <input type="text"
                 id="addr-modal-google-search"
                 class="addr-modal-input"
                 placeholder="Commencez à taper l'adresse (ex: 15 rue de la République, Lyon)…"
                 autocomplete="off"
                 data-places-input
                 data-places-endpoint="<?= h(app_url('/api/places_autocomplete.php')) ?>"
                 data-places-details-endpoint="<?= h(app_url('/api/places_details.php')) ?>"
                 data-places-geocode-endpoint="<?= h(app_url('/api/geocode_address.php')) ?>"
                 data-places-street1="addr-modal-field-adresse1"
                 data-places-postal="addr-modal-field-cp"
                 data-places-city="addr-modal-field-ville"
                 data-places-lat="addr-modal-field-lat"
                 data-places-lng="addr-modal-field-lng"
                 data-places-place-id="addr-modal-field-placeid"
                 data-places-formatted="addr-modal-field-formatted"
                 data-places-immeuble-id="addr-modal-field-immeuble-id"
                 data-places-country-code="fr">
        </div>
        <small class="addr-modal-hint">Résultats combinés : immeubles déjà enregistrés + suggestions Google.</small>
      </div>

      <!-- ── 2. Champs éditables ─────────────────────────────── -->
      <div class="addr-modal-section">
        <label class="addr-modal-label">📝 Adresse à enregistrer (modifiable)</label>
        <div class="addr-modal-grid">
          <input type="text" id="addr-modal-field-adresse1" class="addr-modal-input" placeholder="Numéro et rue" required>
          <input type="text" id="addr-modal-field-adresse2" class="addr-modal-input" placeholder="Complément (bât, étage…)">
          <input type="text" id="addr-modal-field-cp"       class="addr-modal-input" placeholder="CP" maxlength="10">
          <input type="text" id="addr-modal-field-ville"    class="addr-modal-input" placeholder="Ville">
        </div>
      </div>

      <!-- ── 3. Nom de l'immeuble (proposé auto depuis l'adresse) + GPS ── -->
      <div class="addr-modal-section">
        <label class="addr-modal-label">🏢 Nom de l'immeuble (proposé, modifiable)</label>
        <input type="text" id="addr-modal-field-nom" class="addr-modal-input" placeholder="Ex : 2 Avenue de l'Europe">
        <div class="addr-modal-gps" id="addr-modal-gps"></div>
      </div>

      <!-- Champs hidden pour coordonnées GPS + Place ID + lien vers immeuble existant -->
      <input type="hidden" id="addr-modal-field-lat"          value="">
      <input type="hidden" id="addr-modal-field-lng"          value="">
      <input type="hidden" id="addr-modal-field-placeid"      value="">
      <input type="hidden" id="addr-modal-field-formatted"    value="">
      <input type="hidden" id="addr-modal-field-immeuble-id"  value="">

      <!-- Status / retour utilisateur -->
      <div id="addr-modal-status" class="addr-modal-status" aria-live="polite"></div>
    </div>

    <div class="addr-modal-foot">
      <button type="button" class="addr-modal-btn-secondary" data-addr-modal-close>Annuler</button>
      <button type="button" id="addr-modal-validate" class="addr-modal-btn-primary">✅ Valider l'adresse</button>
    </div>
  </div>
</div>

<style>
  .addr-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
  .addr-modal[hidden] { display: none !important; }
  .addr-modal-overlay { position: absolute; inset: 0; background: rgba(15,23,42,0.55); }
  .addr-modal-card { position: relative; background: #fff; border-radius: 14px; max-width: 640px; width: calc(100% - 32px); max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 24px 64px rgba(0,0,0,0.25); overflow: hidden; }
  .addr-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
  .addr-modal-head-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .addr-modal-logo { height: 200px; width: auto; flex: none; }
  .addr-modal-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
  .addr-modal-x { background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b; line-height: 1; padding: 0 6px; border-radius: 6px; }
  .addr-modal-x:hover { background: #f1f5f9; color: #0f172a; }
  .addr-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
  .addr-modal-section { margin-bottom: 18px; }
  .addr-modal-section:last-child { margin-bottom: 0; }
  .addr-modal-label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px; }
  .addr-modal-input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit; box-sizing: border-box; outline: none; transition: border-color .15s; }
  .addr-modal-input:focus { border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,0.12); }
  .addr-modal-hint { display: block; margin-top: 6px; font-size: 11px; color: #94a3b8; }
  .addr-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .addr-modal-grid > :first-child, .addr-modal-grid > :nth-child(2) { grid-column: 1 / -1; }
  .addr-modal-status { margin-top: 12px; font-size: 12px; min-height: 18px; }
  .addr-modal-status.ok  { color: #16a34a; }
  .addr-modal-status.err { color: #dc2626; }
  .addr-modal-foot { padding: 14px 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #f8fafc; }
  .addr-modal-btn-primary,
  .addr-modal-btn-secondary { padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; border: 1px solid transparent; transition: transform .1s; }
  .addr-modal-btn-primary { background: #0ea5e9; color: #fff; border-color: #0ea5e9; }
  .addr-modal-btn-primary:hover { background: #0284c7; }
  .addr-modal-btn-secondary { background: #fff; color: #475569; border-color: #cbd5e1; }
  .addr-modal-btn-secondary:hover { background: #f1f5f9; }

  .addr-modal-gps { font-size: 11px; color: #0e7490; margin-top: 6px; font-variant-numeric: tabular-nums; min-height: 14px; }

  /* Dropdown Places — repositionné pour apparaître au-dessus du modal */
  .addr-modal .places-dropdown { z-index: 1100 !important; }
</style>
