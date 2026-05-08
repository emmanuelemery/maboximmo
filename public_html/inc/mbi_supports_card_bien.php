<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_card_bien.php — Card "Communication" pour la section
 * Annonce de bien_detail.php (placée APRÈS la Card 5 Diffusion).
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Affiche dans le carousel v2 :
 *   - Score commercial actuel (badge cliquable)
 *   - Statut critique export (OK / KO) avec nb manquants
 *   - 3 boutons de génération (Affiche · Fiche client · Fiche interne)
 *   - Bouton "Ouvrir le module"
 *
 * Variables attendues :
 *   - $editingBienId : int
 *   - $pdo           : PDO (ou $GLOBALS['pdo'])
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!isset($editingBienId) || !is_int($editingBienId) || $editingBienId <= 0) return;

require_once __DIR__ . '/mbi_supports_completer_fields.php';

$mbiSupPdo = $pdo ?? $GLOBALS['pdo'] ?? null;
$mbiSupScore     = null;
$mbiSupNbSupports = 0;
$mbiSupCritiqueOk = null;
$mbiSupNbBd       = 0;
$mbiSupBlocsDurs  = [];

if ($mbiSupPdo instanceof PDO) {
    try {
        $st = $mbiSupPdo->prepare("
            SELECT score, niveau_urgence, angle_recommande, date_calcul
            FROM bien_score_commercial
            WHERE id_bien = :b AND statut = 'calcule'
            ORDER BY date_calcul DESC, id DESC LIMIT 1
        ");
        $st->execute([':b' => $editingBienId]);
        $mbiSupScore = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}

    try {
        // Compte uniquement les supports VALIDÉS (officiels) pour ne pas polluer
        // l'affichage avec les brouillons en cours d'édition.
        $st = $mbiSupPdo->prepare("
            SELECT COUNT(*) FROM mbi_supports_commerciaux
            WHERE id_bien = :b
              AND statut IN ('valide','diffuse','archive')
              AND deleted_at IS NULL
        ");
        $st->execute([':b' => $editingBienId]);
        $mbiSupNbSupports = (int)$st->fetchColumn();
    } catch (Throwable) {}
}

// Critique côté affiche_vitrine (le plus exigeant) — best effort, ne bloque pas l'affichage
if (!function_exists('mbi_supports_critic_check') && file_exists(__DIR__ . '/mbi_supports_critic_engine.php')) {
    require_once __DIR__ . '/mbi_supports_critic_engine.php';
}
$mbiSupContexte = ['transaction' => 'vente', 'est_copro' => false]; // défaut
$mbiSupCritiqueCtx = null;
if (function_exists('mbi_supports_critic_check')) {
    try {
        $cr = mbi_supports_critic_check($editingBienId, 'affiche_vitrine');
        if ($cr['ok']) {
            $mbiSupCritiqueOk = (bool)$cr['peut_exporter'];
            $mbiSupBlocsDurs  = $cr['blocs_durs_violes'] ?? [];
            $mbiSupNbBd       = count($mbiSupBlocsDurs);
            $mbiSupCritiqueCtx = $cr['contexte'] ?? null;
        }
    } catch (Throwable) {}
}

// Charge la catégorie du bien_type (pour distinguer entreprise / habitation)
if ($mbiSupCritiqueCtx && $mbiSupPdo instanceof PDO) {
    try {
        $st = $mbiSupPdo->prepare("
            SELECT bt.categorie
            FROM biens b
            LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
            WHERE b.id = :id LIMIT 1
        ");
        $st->execute([':id' => $editingBienId]);
        $cat = (string)($st->fetchColumn() ?: '');
        if ($cat !== '' && isset($mbiSupCritiqueCtx['bien'])) {
            $mbiSupCritiqueCtx['bien']['_bien_type_categorie'] = $cat;
        }
    } catch (Throwable) {}

    // Résout le contexte normalisé (transaction + copro)
    if (function_exists('mbi_supports_completer_contexte_resoudre')) {
        $mbiSupContexte = mbi_supports_completer_contexte_resoudre($mbiSupCritiqueCtx);
    }
}

$dashUrl = function_exists('app_url')
    ? app_url('/mbi_supports_dashboard.php?id_bien=' . $editingBienId)
    : '/mbi_supports_dashboard.php?id_bien=' . $editingBienId;

// URL raccourci : génère + redirige vers éditeur
$genUrlBase = function_exists('app_url')
    ? app_url('/mbi_supports_dashboard.php?id_bien=' . $editingBienId . '&go_generer=')
    : '/mbi_supports_dashboard.php?id_bien=' . $editingBienId . '&go_generer=';

$scoreNum = $mbiSupScore ? (int)$mbiSupScore['score'] : null;
$scoreColor = match (true) {
    $scoreNum === null => '#6b7280',
    $scoreNum >= 80    => '#1a8754',
    $scoreNum >= 60    => '#5a8a3f',
    $scoreNum >= 40    => '#c97b2e',
    $scoreNum >= 20    => '#a85858',
    default            => '#7a3030',
};
?>
<!-- Card MBI Supports : Communication (Lot 6) -->
<section class="v2-card is-next" role="tabpanel" aria-label="Ma Box Communication">
  <div class="v2-card-label">📰 Communication <?php if ($mbiSupNbSupports > 0): ?><span class="v2-count"><?= (int)$mbiSupNbSupports ?></span><?php endif; ?></div>
  <div class="v2-card-body">
    <div style="max-width:880px; margin:0 auto; padding:8px 0;">

      <!-- Bandeau résumé -->
      <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:18px;">

        <!-- Score -->
        <div style="flex:1; min-width:200px; background:linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px;">
          <div style="font-size:10px; font-weight:700; letter-spacing:0.08em; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Score commercial</div>
          <?php if ($scoreNum !== null): ?>
            <div style="display:flex; align-items:baseline; gap:8px;">
              <span style="font-size:32px; font-weight:700; color:<?= $scoreColor ?>; line-height:1;"><?= $scoreNum ?></span>
              <span style="color:#9ca3af; font-size:14px;">/100</span>
            </div>
            <div style="font-size:11px; color:#6b7280; margin-top:4px;">
              Calculé le <?= htmlspecialchars(date('d/m/Y', strtotime((string)$mbiSupScore['date_calcul']))) ?>
              <?php if (!empty($mbiSupScore['angle_recommande'])): ?>
                · Angle : <strong><?= htmlspecialchars($mbiSupScore['angle_recommande']) ?></strong>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div style="font-size:14px; color:#9ca3af; font-style:italic; padding:6px 0;">
              Pas encore évalué
            </div>
            <a href="<?= htmlspecialchars($dashUrl . '&action=recalculer_score&modele=haiku') ?>"
               style="display:inline-block; margin-top:6px; font-size:12px; color:#243B5C; font-weight:600; text-decoration:underline;">
              Évaluer commercialement →
            </a>
          <?php endif; ?>
        </div>

        <!-- Critique export -->
        <div style="flex:1; min-width:200px; background:linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px;">
          <div style="font-size:10px; font-weight:700; letter-spacing:0.08em; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Mentions légales — affiche</div>
          <?php if ($mbiSupCritiqueOk === true): ?>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:24px; line-height:1;">✓</span>
              <span style="font-size:14px; font-weight:600; color:#1a5e36;">Export autorisé</span>
            </div>
            <div style="font-size:11px; color:#6b7280; margin-top:4px;">
              Toutes les mentions obligatoires sont OK.
            </div>
          <?php elseif ($mbiSupCritiqueOk === false): ?>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:24px; line-height:1; color:#7a2828;">✗</span>
              <span style="font-size:14px; font-weight:600; color:#7a2828;">
                <?= $mbiSupNbBd ?> mention<?= $mbiSupNbBd > 1 ? 's' : '' ?> à corriger
              </span>
            </div>
            <button type="button"
                    onclick="mbiSupCompleterOpen()"
                    style="display:inline-block; margin-top:6px; font-size:12px; color:#243B5C; font-weight:600; background:none; border:none; padding:0; cursor:pointer; text-decoration:underline;">
              Compléter les mentions →
            </button>
          <?php else: ?>
            <div style="font-size:13px; color:#9ca3af; font-style:italic; padding:6px 0;">
              Critique non disponible
            </div>
          <?php endif; ?>
        </div>

        <!-- Compteur supports -->
        <div style="flex:1; min-width:200px; background:linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px;">
          <div style="font-size:10px; font-weight:700; letter-spacing:0.08em; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Supports déjà générés</div>
          <div style="font-size:32px; font-weight:700; color:#243B5C; line-height:1;"><?= (int)$mbiSupNbSupports ?></div>
          <div style="font-size:11px; color:#6b7280; margin-top:4px;">
            Versions historisées (jamais écrasées)
          </div>
        </div>

      </div>

      <!-- Boutons de génération : génère + ouvre l'éditeur direct -->
      <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:14px;">
        <button type="button" onclick="mbiSup4AnglesOpen()"
           style="display:block; padding:14px 16px; border:2px solid #243B5C; color:#243B5C; background:#fff; border-radius:12px; text-align:center; font-weight:600; transition:all 0.15s; cursor:pointer; font-family:inherit;"
           onmouseover="this.style.background='#243B5C'; this.style.color='#fff';"
           onmouseout="this.style.background='#fff'; this.style.color='#243B5C';">
          <div style="font-size:24px; margin-bottom:4px;">📰</div>
          <div style="font-size:14px;">Générer les 4 affiches</div>
          <div style="font-size:11px; opacity:0.7; margin-top:2px;">IA · 4 angles d'un coup</div>
        </button>
        <a href="<?= htmlspecialchars($genUrlBase . 'fiche_client') ?>"
           style="display:block; padding:14px 16px; border:2px solid #243B5C; color:#243B5C; background:#fff; border-radius:12px; text-decoration:none; text-align:center; font-weight:600; transition:all 0.15s;"
           onmouseover="this.style.background='#243B5C'; this.style.color='#fff';"
           onmouseout="this.style.background='#fff'; this.style.color='#243B5C';">
          <div style="font-size:24px; margin-bottom:4px;">📄</div>
          <div style="font-size:14px;">Fiche client</div>
          <div style="font-size:11px; opacity:0.7; margin-top:2px;">Multi-page éditable</div>
        </a>
        <a href="<?= htmlspecialchars($genUrlBase . 'fiche_visite_interne') ?>"
           style="display:block; padding:14px 16px; border:2px solid #a85858; color:#a85858; background:#fff; border-radius:12px; text-decoration:none; text-align:center; font-weight:600; transition:all 0.15s;"
           onmouseover="this.style.background='#a85858'; this.style.color='#fff';"
           onmouseout="this.style.background='#fff'; this.style.color='#a85858';">
          <div style="font-size:24px; margin-bottom:4px;">🔒</div>
          <div style="font-size:14px;">Fiche visite interne</div>
          <div style="font-size:11px; opacity:0.7; margin-top:2px;">Coaching négo · filigrane</div>
        </a>
      </div>

      <!-- Lien vers dashboard -->
      <div style="text-align:center; padding-top:8px;">
        <a href="<?= htmlspecialchars($dashUrl) ?>"
           style="display:inline-block; padding:10px 22px; background:#243B5C; color:#fff; border-radius:999px; text-decoration:none; font-weight:600; font-size:13px;">
          Ouvrir le module Communication →
        </a>
      </div>

    </div>
  </div>
</section>

<?php
// ═════════════════════════════════════════════════════════════════════════
// MODALE — Compléter les mentions légales (LOT 1)
// ═════════════════════════════════════════════════════════════════════════
// Le formulaire est rendu côté serveur d'après les blocs durs courants.
// Submit → POST AJAX vers api/mbi_supports_completer_save.php → màj de
// l'écran avec le nouveau diagnostic.
?>
<div id="mbiSupCompleterOverlay"
     style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9990; align-items:flex-start; justify-content:center; overflow-y:auto; padding:40px 16px;">
  <div role="dialog" aria-modal="true" aria-labelledby="mbiSupCompleterTitle"
       style="background:#fff; max-width:760px; width:100%; border-radius:14px; box-shadow:0 25px 60px -10px rgba(0,0,0,0.4); padding:0; overflow:hidden;">

    <header style="background:#243B5C; color:#fff; padding:18px 24px; display:flex; align-items:center; justify-content:space-between;">
      <div>
        <div id="mbiSupCompleterTitle" style="font-size:18px; font-weight:700;">📋 Compléter les mentions légales</div>
        <div style="font-size:12px; opacity:0.85; margin-top:2px;">
          <?= (int)$mbiSupNbBd ?> mention<?= $mbiSupNbBd > 1 ? 's' : '' ?> à corriger pour autoriser l'export d'une affiche.
        </div>
      </div>
      <button type="button" onclick="mbiSupCompleterClose()"
              aria-label="Fermer"
              style="background:none; border:0; color:#fff; font-size:24px; cursor:pointer; line-height:1; padding:4px 8px;">×</button>
    </header>

    <form id="mbiSupCompleterForm" style="padding:20px 24px 8px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token('ajouter_bien') : '') ?>">
      <input type="hidden" name="id_bien" value="<?= (int)$editingBienId ?>">
      <input type="hidden" name="type_support" value="affiche_vitrine">

      <?php
      // ─── Collecte dédupliquée : 1 champ unique = 1 input affiché ────────
      // Plusieurs codes peuvent réclamer le même champ ; on agrège leurs
      // libellés pour expliquer pourquoi le champ est demandé.
      $uniqueFields = [];   // 'table.column' => ['def'=>def, 'codes'=>[…], 'libelles'=>[…]]
      $orphans      = [];   // codes sans formulaire associé (à corriger ailleurs)
      foreach ($mbiSupBlocsDurs as $bloc) {
          $code    = (string)($bloc['code']    ?? '');
          $libelle = (string)($bloc['libelle'] ?? $code);
          $detail  = (string)($bloc['detail']  ?? '');
          $fields  = function_exists('mbi_supports_completer_fields_pour_code')
                     ? mbi_supports_completer_fields_pour_code($code, $mbiSupContexte)
                     : [];
          if (empty($fields)) {
              $orphans[] = ['code'=>$code, 'libelle'=>$libelle, 'detail'=>$detail];
              continue;
          }
          foreach ($fields as $def) {
              if (empty($def['table']) || empty($def['column'])) continue;
              $key = $def['table'] . '.' . $def['column'];
              if (!isset($uniqueFields[$key])) {
                  $uniqueFields[$key] = ['def'=>$def, 'codes'=>[], 'libelles'=>[]];
              }
              if (!in_array($code,    $uniqueFields[$key]['codes'],    true)) $uniqueFields[$key]['codes'][]    = $code;
              if (!in_array($libelle, $uniqueFields[$key]['libelles'], true)) $uniqueFields[$key]['libelles'][] = $libelle;
          }
      }

      // Regroupement par entité pour lisibilité (Bien → Agence → Mandat → Annonce)
      $entiteOrder = ['biens'=>'🏠 Bien','annonces'=>'📰 Annonce','agences'=>'🏢 Agence','mandats'=>'📜 Mandat','users'=>'👤 Négociateur'];
      $byEntite = [];
      foreach ($uniqueFields as $key => $row) {
          $tbl = (string)($row['def']['table'] ?? 'biens');
          $byEntite[$tbl][$key] = $row;
      }
      $rendered = count($uniqueFields);

      // Bandeau "contexte détecté" pour expliciter ce que la modale propose
      $txLib = match ($mbiSupContexte['transaction'] ?? 'vente') {
          'location'   => '🔑 Location',
          'entreprise' => '🏬 Immobilier d\'entreprise',
          default      => '🏠 Vente résidentielle',
      };
      ?>

      <div style="background:#f0f7ff; border:1px solid #bfdbfe; border-radius:8px; padding:8px 12px; font-size:12px; color:#1e40af; margin-bottom:14px;">
        Mentions adaptées au contexte : <strong><?= htmlspecialchars($txLib) ?></strong><?= !empty($mbiSupContexte['est_copro']) ? ' · en copropriété' : '' ?>
      </div>

      <?php foreach ($entiteOrder as $tbl => $titre):
          if (empty($byEntite[$tbl])) continue; ?>
        <fieldset style="border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px 6px; margin-bottom:14px;">
          <legend style="padding:0 8px; font-size:12px; font-weight:700; color:#243B5C; letter-spacing:0.04em; text-transform:uppercase;">
            <?= htmlspecialchars($titre) ?>
          </legend>

          <?php foreach ($byEntite[$tbl] as $key => $row):
              $def    = $row['def'];
              $label  = (string)($def['label']  ?? $def['column']);
              $type   = (string)($def['type']   ?? 'text');
              $hint   = (string)($def['hint']   ?? '');
              $req    = !empty($def['required']);
              $name   = "fields[{$key}]";
              $why    = implode(' · ', array_slice($row['libelles'], 0, 3));
          ?>
            <div style="margin-bottom:12px;">
              <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px;">
                <?= htmlspecialchars($label) ?> <?= $req ? '<span style="color:#a85858">*</span>' : '' ?>
              </label>
              <?php if ($type === 'textarea'): ?>
                <textarea name="<?= htmlspecialchars($name) ?>" rows="4"
                          style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; font-family:inherit;"></textarea>
              <?php elseif ($type === 'select' && !empty($def['options'])): ?>
                <select name="<?= htmlspecialchars($name) ?>"
                        style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                  <?php foreach ($def['options'] as $val => $lib): ?>
                    <option value="<?= htmlspecialchars((string)$val) ?>"><?= htmlspecialchars((string)$lib) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($type === 'checkbox'): ?>
                <label style="display:inline-flex; align-items:center; gap:8px; font-size:13px; color:#374151;">
                  <input type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1">
                  Oui
                </label>
              <?php elseif ($type === 'date'): ?>
                <input type="date" name="<?= htmlspecialchars($name) ?>"
                       style="padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
              <?php elseif ($type === 'number'): ?>
                <input type="number" step="any" name="<?= htmlspecialchars($name) ?>"
                       style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
              <?php else: ?>
                <input type="text" name="<?= htmlspecialchars($name) ?>"
                       style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
              <?php endif; ?>
              <?php if ($hint !== ''): ?>
                <div style="font-size:11px; color:#6b7280; margin-top:3px;"><?= htmlspecialchars($hint) ?></div>
              <?php endif; ?>
              <?php if ($why !== ''): ?>
                <div style="font-size:10px; color:#9ca3af; margin-top:2px;">Concerne : <?= htmlspecialchars($why) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>

      <?php if (!empty($orphans)): ?>
      <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:12px 14px; margin-bottom:14px;">
        <div style="font-size:12px; font-weight:700; color:#9a3412; margin-bottom:6px;">À corriger directement sur la fiche concernée :</div>
        <ul style="margin:0; padding-left:18px; font-size:12px; color:#7c2d12;">
          <?php foreach ($orphans as $o): ?>
            <li><?= htmlspecialchars($o['libelle']) ?><?php if ($o['detail']): ?> — <em><?= htmlspecialchars($o['detail']) ?></em><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($rendered === 0 && empty($orphans)): ?>
      <div style="text-align:center; padding:30px 10px; color:#6b7280; font-style:italic;">
        Aucun bloc dur à corriger 🎉
      </div>
      <?php endif; ?>

      <div id="mbiSupCompleterMsg" style="display:none; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:13px;"></div>

      <footer style="display:flex; justify-content:flex-end; gap:10px; padding:8px 0 16px; border-top:1px solid #e5e7eb; margin-top:6px;">
        <button type="button" onclick="mbiSupCompleterClose()"
                style="padding:9px 18px; background:#fff; color:#374151; border:1px solid #d1d5db; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer;">
          Annuler
        </button>
        <?php if ($rendered > 0): ?>
        <button type="submit"
                style="padding:9px 22px; background:#243B5C; color:#fff; border:0; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer;">
          Sauvegarder + Re-vérifier
        </button>
        <?php endif; ?>
      </footer>
    </form>
  </div>
</div>

<script>
(function(){
  const overlay = document.getElementById('mbiSupCompleterOverlay');
  const form    = document.getElementById('mbiSupCompleterForm');
  const msg     = document.getElementById('mbiSupCompleterMsg');
  if (!overlay || !form) return;

  window.mbiSupCompleterOpen = function() {
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  };
  window.mbiSupCompleterClose = function() {
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  };
  overlay.addEventListener('click', function(e){
    if (e.target === overlay) mbiSupCompleterClose();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && overlay.style.display === 'flex') mbiSupCompleterClose();
  });

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    msg.style.display = 'none';
    const submitBtn = form.querySelector('button[type=submit]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Enregistrement…'; }

    const data = new FormData(form);
    let httpStatus = 0;
    let rawText = '';
    try {
      const r = await fetch('api/mbi_supports_completer_save.php', {
        method: 'POST', body: data, credentials: 'same-origin',
      });
      httpStatus = r.status;
      rawText = await r.text();
      console.log('[completer_save] HTTP', httpStatus, 'body:', rawText);
      let j;
      try { j = JSON.parse(rawText); }
      catch (parseErr) { throw new Error('Réponse non-JSON (HTTP ' + httpStatus + ') — voir console'); }
      if (!j.ok) {
        // CAS SPÉCIAL : 409 no_target_entities → message clair + lien création mandat
        if (httpStatus === 409 && j.error === 'no_target_entities') {
          msg.style.display = 'block';
          msg.style.background = '#fff7ed';
          msg.style.color = '#9a3412';
          msg.style.border = '1px solid #fed7aa';
          let html = '⚠ ' + (j.message || 'Cible manquante.');
          if (j.help_create_mandat) {
            html += '<br><a href="' + j.help_create_mandat + '" style="display:inline-block;margin-top:8px;padding:6px 14px;background:#0ea5e9;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:12px;">→ Créer un mandat pour ce bien</a>';
          }
          msg.innerHTML = html;
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Sauvegarder + Re-vérifier'; }
          return;
        }
        throw new Error(j.error || ('Erreur HTTP ' + httpStatus));
      }

      // ── Comptage saved / ignored ──
      let savedCount = 0;
      Object.values(j.saved || {}).forEach(arr => savedCount += (Array.isArray(arr) ? arr.length : 0));
      const ignoredCount = (j.ignored || []).length;
      const ignoredDetails = (j.ignored || []).map(i => i.key + ' (' + i.reason + ')').join(', ');

      msg.style.display = 'block';
      if (savedCount === 0 && ignoredCount === 0) {
        // Aucun champ envoyé : le user a cliqué sans rien remplir
        msg.style.background = '#fff7ed';
        msg.style.color = '#9a3412';
        msg.style.border = '1px solid #fed7aa';
        msg.textContent = '⚠ Aucun champ rempli. Saisis au moins une valeur.';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Sauvegarder + Re-vérifier'; }
        return;
      }
      if (j.peut_exporter) {
        msg.style.background = '#ecfdf5';
        msg.style.color = '#065f46';
        msg.style.border = '1px solid #a7f3d0';
        msg.textContent = `✓ ${savedCount} champ(s) sauvegardé(s) · toutes les mentions sont OK. La page va se recharger.`;
        setTimeout(() => location.reload(), 1200);
      } else {
        const reste = (j.blocs_durs_violes || []).length;
        msg.style.background = '#fff7ed';
        msg.style.color = '#9a3412';
        msg.style.border = '1px solid #fed7aa';
        let txt = `✓ ${savedCount} champ(s) sauvegardé(s).`;
        if (ignoredCount > 0) txt += ` ⚠ ${ignoredCount} ignoré(s) : ${ignoredDetails}.`;
        txt += ` Il reste ${reste} mention${reste > 1 ? 's' : ''} à corriger.`;
        msg.textContent = txt;
        setTimeout(() => location.reload(), 2500);
      }
    } catch (err) {
      msg.style.display = 'block';
      msg.style.background = '#fef2f2';
      msg.style.color = '#991b1b';
      msg.style.border = '1px solid #fecaca';
      let detail = err.message;
      if (httpStatus === 419) detail += ' — token CSRF invalide (recharge la page : Ctrl+F5)';
      else if (httpStatus === 401) detail += ' — session expirée (recharge la page)';
      else if (httpStatus === 403) detail += ' — pas le droit d\'écrire (scope société)';
      else if (rawText && rawText.length < 300) detail += ' — Réponse serveur : ' + rawText.substring(0, 200);
      msg.textContent = '❌ ' + detail;
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Sauvegarder + Re-vérifier'; }
    }
  });
})();
</script>

<?php
// ═════════════════════════════════════════════════════════════════════════
// MODALE — Générer les 4 affiches d'un coup (LOT 3)
// ═════════════════════════════════════════════════════════════════════════
?>
<div id="mbiSup4AnglesOverlay"
     style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9991; align-items:flex-start; justify-content:center; overflow-y:auto; padding:40px 16px;">
  <div role="dialog" aria-modal="true" aria-labelledby="mbiSup4AnglesTitle"
       style="background:#fff; max-width:760px; width:100%; border-radius:14px; box-shadow:0 25px 60px -10px rgba(0,0,0,0.4); padding:0; overflow:hidden;">

    <header style="background:#243B5C; color:#fff; padding:18px 24px; display:flex; align-items:center; justify-content:space-between;">
      <div>
        <div id="mbiSup4AnglesTitle" style="font-size:18px; font-weight:700;">📰 Générer les 4 affiches vitrine</div>
        <div style="font-size:12px; opacity:0.85; margin-top:2px;">
          Famille · Investisseur · Premium · Primo-accédant — IA Haiku ~0,1 ¢ × 4
        </div>
      </div>
      <button type="button" onclick="mbiSup4AnglesClose()"
              aria-label="Fermer"
              style="background:none; border:0; color:#fff; font-size:24px; cursor:pointer; line-height:1; padding:4px 8px;">×</button>
    </header>

    <div style="padding:20px 24px;">
      <div id="mbiSup4AnglesIntro" style="margin-bottom:16px; font-size:13px; color:#4b5563;">
        Lance la génération des 4 versions de l'affiche vitrine pour ce bien.
        Chaque version a une rédaction commerciale dédiée à sa cible.
      </div>

      <div id="mbiSup4AnglesProgress" style="display:none; margin-bottom:14px;">
        <div style="font-size:13px; color:#374151; margin-bottom:6px;">Génération en cours…</div>
        <div style="height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden;">
          <div id="mbiSup4AnglesBar" style="height:100%; width:0%; background:#243B5C; transition:width 0.4s;"></div>
        </div>
      </div>

      <div id="mbiSup4AnglesResults" style="display:grid; grid-template-columns:repeat(2, 1fr); gap:10px;"></div>

      <div id="mbiSup4AnglesError" style="display:none; padding:10px 12px; border-radius:8px; margin-top:14px; font-size:13px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca;"></div>

      <?php if ((int)($_SESSION['id_role'] ?? 0) === 1): ?>
        <div style="margin-top:14px; padding:10px 12px; border:1px dashed #c87870; border-radius:8px; background:#fff7f5;">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:12px; color:#7f1d1d;">
            <input type="checkbox" id="mbiSup4AnglesForce" style="width:16px; height:16px;">
            <span>
              <strong>🔓 Forcer l'export (super admin)</strong>
              <span style="display:block; color:#9a2922; margin-top:2px;">
                Bypass les blocs durs de la critique IA (carte pro, garant, mandat, etc.).
                Utile pour générer rapidement quand les docs officiels ne sont pas encore en BDD.
              </span>
            </span>
          </label>
        </div>
      <?php endif; ?>

      <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:14px; border-top:1px solid #e5e7eb; margin-top:14px;">
        <button type="button" onclick="mbiSup4AnglesClose()"
                style="padding:9px 18px; background:#fff; color:#374151; border:1px solid #d1d5db; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer;">
          Fermer
        </button>
        <button type="button" id="mbiSup4AnglesGoBtn" onclick="mbiSup4AnglesLancer()"
                style="padding:9px 22px; background:#243B5C; color:#fff; border:0; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer;">
          Lancer la génération
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const overlay  = document.getElementById('mbiSup4AnglesOverlay');
  const goBtn    = document.getElementById('mbiSup4AnglesGoBtn');
  const intro    = document.getElementById('mbiSup4AnglesIntro');
  const progress = document.getElementById('mbiSup4AnglesProgress');
  const bar      = document.getElementById('mbiSup4AnglesBar');
  const results  = document.getElementById('mbiSup4AnglesResults');
  const err      = document.getElementById('mbiSup4AnglesError');
  const ID_BIEN  = <?= (int)$editingBienId ?>;
  if (!overlay || !goBtn) return;

  const ANGLE_LIBS = {
    'famille':       { label: 'Pour la famille',    color: '#2a7a5f' },
    'investisseur':  { label: 'Investisseur',       color: '#42608c' },
    'premium':       { label: 'Premium',            color: '#a57c32' },
    'premier_achat': { label: 'Primo-accédant',     color: '#c06646' },
  };

  window.mbiSup4AnglesOpen = function() {
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  };
  window.mbiSup4AnglesClose = function() {
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  };
  overlay.addEventListener('click', function(e){
    if (e.target === overlay) mbiSup4AnglesClose();
  });

  window.mbiSup4AnglesLancer = async function() {
    err.style.display = 'none';
    results.innerHTML = '';
    intro.style.display = 'none';
    progress.style.display = 'block';
    bar.style.width = '8%';
    goBtn.disabled = true;
    goBtn.textContent = 'Génération…';

    // Animation indicative — pas de vrai stream côté serveur (génération synchrone)
    let pct = 8;
    const tick = setInterval(() => { pct = Math.min(pct + 4, 92); bar.style.width = pct + '%'; }, 800);

    try {
      const fd = new FormData();
      fd.append('id_bien', String(ID_BIEN));
      fd.append('type', 'affiche_vitrine');
      fd.append('modele_ia', 'haiku');
      const forceEl = document.getElementById('mbiSup4AnglesForce');
      if (forceEl && forceEl.checked) fd.append('force', '1');
      const r = await fetch('api/mbi_supports_generer_4_angles.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const j = await r.json();
      clearInterval(tick);
      bar.style.width = '100%';

      if (j.critique_ko) {
        err.style.display = 'block';
        err.innerHTML = '✗ La critique des mentions légales refuse l\'export. <a href="#" onclick="mbiSup4AnglesClose(); mbiSupCompleterOpen(); return false;" style="color:#991b1b; font-weight:700;">Compléter les mentions →</a>';
        goBtn.disabled = false; goBtn.textContent = 'Lancer la génération';
        return;
      }
      if (!j.ok) throw new Error(j.error || 'Erreur inconnue');

      const cards = (j.resultats || []).map(rs => {
        const meta = ANGLE_LIBS[rs.angle] || { label: rs.angle, color: '#243B5C' };
        if (!rs.ok) {
          return `<div style="border:1px solid #fecaca; background:#fef2f2; border-radius:10px; padding:12px;">
            <div style="font-weight:700; color:${meta.color}; margin-bottom:4px;">${meta.label}</div>
            <div style="font-size:12px; color:#991b1b;">Erreur : ${rs.erreur || 'inconnue'}</div>
          </div>`;
        }
        const url = rs.pdf_url || '#';
        return `<a href="${url}" target="_blank" rel="noopener"
                   style="display:block; border:2px solid ${meta.color}; border-radius:10px; padding:14px; text-decoration:none; color:#1f2937; background:#fff;">
          <div style="font-size:11px; color:${meta.color}; font-weight:700; letter-spacing:0.05em; text-transform:uppercase;">${meta.label}</div>
          <div style="margin-top:6px; font-size:13px; font-weight:600;">📄 Ouvrir le PDF</div>
          <div style="margin-top:2px; font-size:11px; color:#6b7280;">v${rs.version} · ${rs.cout_centimes}¢</div>
        </a>`;
      });
      results.innerHTML = cards.join('');
      goBtn.textContent = 'Régénérer';
      goBtn.disabled = false;
    } catch (e) {
      clearInterval(tick);
      err.style.display = 'block';
      err.textContent = 'Erreur : ' + e.message;
      goBtn.disabled = false; goBtn.textContent = 'Lancer la génération';
    }
  };
})();
</script>
