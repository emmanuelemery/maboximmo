<?php
/**
 * transaction_dossier.php — DOSSIER DE VENTE UNIQUE (Phase 1, lecture seule).
 *
 * Fiche pivot qui AGRÈGE l'existant sans aucune ressaisie :
 *   bien + immeuble (biens/immeubles) · acteurs (tiers_roles) · documents (ged_document_links,
 *   entity_type='DOSSIER' + ceux du bien) · mandat (mandats) · offres (leads_annonces) ·
 *   prix (bien_prix / biens). L'étape macro vient de dossier_vente.etape.
 *
 * Accès : ?id_bien=ID (crée/retrouve le dossier, idempotent) ou ?id=ID_DOSSIER.
 * UX MaBoxImmo : navigation par cartes/boutons, aucun <select>. Phase 1 = affichage.
 * La timeline est conçue pour accueillir EN PHASE ULTÉRIEURE les actions d'étape
 * (compromis / acte / honoraires) — ici les jalons futurs sont affichés inactifs.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_once __DIR__ . '/inc/tiers_selector.php';   // composant recherche/création tiers réutilisable
require_login();

$pdo = $GLOBALS['pdo'];

// ── Résolution du dossier (par bien = crée/retrouve, ou par id dossier) ──
$idBien    = (int)($_GET['id_bien'] ?? 0);
$idDossier = (int)($_GET['id'] ?? 0);

if ($idBien > 0) {
    $idDossier = dv_ensure_for_bien($pdo, $idBien, ['source' => 'estimation', 'id_user' => current_user_id()]);
}
$dossier = $idDossier > 0 ? dv_get($pdo, $idDossier) : null;
if (!$dossier) {
    http_response_code(404);
    exit('Dossier de vente introuvable.');
}
$idBien = (int)$dossier['id_bien'];

// ── Bien + immeuble + propriétaire (lu par référence) ──
$stB = $pdo->prepare("
    SELECT b.*,
        COALESCE(NULLIF(b.adresse_1,''), i.adresse_1)     AS bien_adresse,
        COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS bien_cp,
        COALESCE(NULLIF(b.ville,''), i.ville)             AS bien_ville,
        i.id AS immeuble_id, i.nom_immeuble, i.adresse_1 AS imm_adresse,
        p.id AS proprio_id, p.id_tiers AS proprio_tiers_id
    FROM biens b
    LEFT JOIN immeubles i     ON i.id = b.id_immeuble
    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
    WHERE b.id = ? LIMIT 1");
$stB->execute([$idBien]);
$bien = $stB->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit('Bien introuvable.'); }

// ── Mandat de vente lié (référence) ──
$mandat = null;
if (!empty($dossier['id_mandat'])) {
    $stM = $pdo->prepare("SELECT * FROM mandats WHERE id = ? LIMIT 1");
    $stM->execute([(int)$dossier['id_mandat']]);
    $mandat = $stM->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Offres (référence) ──
$stO = $pdo->prepare("SELECT id, civilite, nom, prenom, email, telephone, prix_propose,
                             financement_type, statut_offre, date_creation
                      FROM leads_annonces
                      WHERE id_bien = ? AND type_contact = 'offre'
                      ORDER BY COALESCE(prix_propose,0) DESC, date_creation DESC");
$stO->execute([$idBien]);
$offres = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── Prix courant (bien_prix), repli biens (référence, jamais recopié) ──
$prixCourant = null;
try {
    $stP = $pdo->prepare("SELECT montant FROM bien_prix
                          WHERE id_bien = ? AND type_valeur = 'prix_vente' AND is_courant = 1
                          ORDER BY date_validation DESC, id DESC LIMIT 1");
    $stP->execute([$idBien]);
    $prixCourant = $stP->fetchColumn();
} catch (Throwable) { /* table/col absente en legacy : on retombe sur biens */ }
$prixCourant = $prixCourant !== false && $prixCourant !== null
    ? (float)$prixCourant
    : ($bien['prix_demande_initial'] ?? $bien['prix_vente_estime'] ?? null);

// ── Acteurs + documents (pivot) ──
$acteurs   = dv_acteurs($pdo, $idDossier);
$docsDoss  = dv_documents($pdo, $idDossier);
$docsBien  = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['limit' => 100]);

// ── Helpers d'affichage ──
$fmtPrix = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$acteurNom = static function(array $a): string {
    $n = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')));
    return $n !== '' ? $n : 'Tiers #' . $a['id_tiers'];
};
$roleLabels = [
    'prospect_vendeur' => 'Vendeur (pressenti)', 'vendeur' => 'Vendeur',
    'acquereur' => 'Acquéreur', 'prospect_acquereur' => 'Acquéreur (pressenti)',
    'notaire' => 'Notaire vendeur', 'notaire_acquereur' => 'Notaire acquéreur',
    'partenaire_apporteur' => 'Apporteur / partenaire',
];

// ── Timeline : 7 jalons macro ──
$etapes = [
    'estimation'        => ['Estimation', '📊', $dossier['date_estimation']],
    'mandat'            => ['Mandat',      '📝', $dossier['date_mandat']],
    'commercialisation' => ['Commercialisation', '📣', null],
    'offre'             => ['Offre',       '💰', null],
    'compromis'         => ['Compromis',   '🤝', $dossier['date_compromis']],
    'acte'              => ['Acte',        '🏛️', $dossier['date_acte']],
    'solde'             => ['Solde',       '✅', null],
];
$rankCourant   = dv_etape_rank((string)$dossier['etape']);
$etapeTerminal = in_array($dossier['etape'], ['sans_suite', 'perdu'], true);

$refBien   = $bien['reference_bien'] ?: ('#' . $idBien);
$pageTitle = 'Dossier de vente · ' . $refBien;
$extraCss  = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
.dv-wrap{max-width:1180px;margin:0 auto;padding:8px 16px 48px;}
.dv-timeline{display:flex;gap:6px;align-items:stretch;flex-wrap:wrap;margin:18px 0 26px;}
.dv-step{flex:1 1 120px;min-width:120px;border:1px solid #e2e8f0;border-radius:12px;padding:12px 10px;text-align:center;background:#fff;position:relative;}
.dv-step.done{background:linear-gradient(135deg,#e9f7ef,#d7f0e0);border-color:#9ad3ab;}
.dv-step.current{border-color:#0f6cbd;box-shadow:0 0 0 2px #0f6cbd33;background:#eef5fc;}
.dv-step.future{opacity:.5;}
.dv-step .ic{font-size:22px;}
.dv-step .lb{font-weight:800;font-size:12px;margin-top:4px;color:#1f2937;}
.dv-step .dt{font-size:11px;color:#64748b;margin-top:2px;}
.dv-step .soon{position:absolute;top:6px;right:6px;font-size:9px;background:#eef2f6;color:#64748b;border-radius:6px;padding:1px 5px;font-weight:700;}
.dv-terminal{display:inline-block;background:#fde2e1;color:#a11;border:1px solid #f3b4b1;border-radius:8px;padding:4px 12px;font-weight:800;margin-bottom:14px;}
.dv-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:880px){.dv-grid{grid-template-columns:1fr;}}
.dv-card{border:1px solid #e2e8f0;border-radius:14px;background:#fff;padding:16px 18px;}
.dv-card h3{margin:0 0 12px;font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;}
.dv-row{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed #eef2f6;font-size:13px;}
.dv-row:last-child{border-bottom:0;}
.dv-row .k{color:#64748b;}
.dv-row .v{font-weight:700;color:#1f2937;text-align:right;}
.dv-actor{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed #eef2f6;}
.dv-actor:last-child{border-bottom:0;}
.dv-actor .role{font-size:11px;font-weight:800;color:#0f6cbd;background:#eef5fc;border-radius:6px;padding:2px 7px;white-space:nowrap;}
.dv-actor .nm{font-weight:700;font-size:13px;}
.dv-actor .ct{font-size:11px;color:#64748b;}
.dv-empty{color:#94a3b8;font-size:13px;font-style:italic;padding:6px 0;}
.dv-doc{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed #eef2f6;font-size:12.5px;}
.dv-doc:last-child{border-bottom:0;}
.dv-doc a{color:#0f6cbd;text-decoration:none;font-weight:700;}
.dv-badge{font-size:10px;background:#f1f5f9;border-radius:5px;padding:1px 6px;color:#475569;font-weight:700;}
.dv-prix{font-size:26px;font-weight:900;color:#0b8043;}
.dv-note{font-size:11px;color:#94a3b8;margin-top:8px;}
.dv-add-btn{margin-left:auto;width:26px;height:26px;border:none;border-radius:8px;background:linear-gradient(135deg,#0f6cbd,#0c5aa0);color:#fff;font-size:18px;font-weight:900;line-height:1;cursor:pointer;}
.dv-add-btn:hover{filter:brightness(1.08);}
.dv-actor-del{border:none;background:#f1f5f9;color:#94a3b8;border-radius:7px;width:22px;height:22px;font-size:14px;font-weight:900;cursor:pointer;flex:none;}
.dv-actor-del:hover{background:#fde2e1;color:#a11;}
/* Modal acteur */
.dvm-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9000;align-items:flex-start;justify-content:center;padding:48px 16px;}
.dvm-backdrop.open{display:flex;}
.dvm{background:#fff;border-radius:16px;max-width:520px;width:100%;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.dvm h3{margin:0 0 4px;font-size:16px;font-weight:900;}
.dvm .sub{font-size:12px;color:#64748b;margin-bottom:16px;}
.dvm-roles{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.dvm-role{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:800;color:#334155;cursor:pointer;}
.dvm-role.active{border-color:#0f6cbd;background:#eef5fc;color:#0c5aa0;box-shadow:0 0 0 2px #0f6cbd22;}
.dvm-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
.dvm-btn{border:none;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;}
.dvm-btn.cancel{background:#eceef1;color:#374151;}
.dvm-btn.ok{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
.dvm-btn:disabled{opacity:.5;cursor:not-allowed;}
.dvm-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:#8a8680;text-transform:uppercase;margin:0 0 6px;}
/* Le modal de création de tiers (composant partagé, z-index 5000) doit s'empiler
   AU-DESSUS du modal acteur (9000) — sinon il s'ouvre derrière et reste inaccessible. */
.ts-modal-overlay{z-index:9500 !important;}
.tiers-selector .ts-dropdown{z-index:9600;}
</style>

<div class="dv-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0;font-size:22px;font-weight:900;">🗂️ Dossier de vente — <?= h($refBien) ?></h1>
      <div style="color:#64748b;font-size:13px;margin-top:3px;">
        <?= h($bien['designation'] ?: '') ?>
        <?= h(trim(($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? ''))) ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="tr-btn tr-btn-primary" href="<?= h(app_url('/bien_360.php?id=' . $idBien)) ?>">🏠 Vue 360° du bien</a>
      <a class="tr-btn" href="<?= h(app_url('/bien_documents_list.php?id=' . $idBien)) ?>">📁 Documents</a>
    </div>
  </div>

  <?php if ($etapeTerminal): ?>
    <div style="margin-top:14px;"><span class="dv-terminal">⛔ <?= $dossier['etape'] === 'perdu' ? 'Dossier perdu' : 'Sans suite' ?></span></div>
  <?php endif; ?>

  <!-- ═══ TIMELINE (jalons futurs prêts à accueillir les actions d'étape) ═══ -->
  <div class="dv-timeline">
    <?php foreach ($etapes as $code => [$lib, $ic, $dt]):
        $rank = dv_etape_rank($code);
        $cls  = $etapeTerminal ? 'future'
              : ($rank < $rankCourant ? 'done' : ($rank === $rankCourant ? 'current' : 'future'));
        // Jalons en écriture (compromis/acte/solde) = à venir en phase ultérieure
        $soon = in_array($code, ['compromis','acte','solde'], true) && $rank > $rankCourant;
    ?>
      <div class="dv-step <?= $cls ?>">
        <?php if ($soon): ?><span class="soon">à venir</span><?php endif; ?>
        <div class="ic"><?= $ic ?></div>
        <div class="lb"><?= h($lib) ?></div>
        <div class="dt"><?= $dt ? h($fmtDate($dt)) : ($rank <= $rankCourant && !$etapeTerminal ? '✓' : '') ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="dv-grid">
    <!-- ACTEURS -->
    <div class="dv-card">
      <h3>👥 Acteurs du dossier
        <button type="button" class="dv-add-btn" onclick="dvOpenActeurModal()" title="Ajouter un acteur (acquéreur, notaire, apporteur…)">+</button>
      </h3>
      <div id="dv-acteurs-list">
        <?php foreach ($acteurs as $a):
            $meta = json_decode((string)($a['metadata'] ?? ''), true) ?: [];
        ?>
          <div class="dv-actor" data-role-id="<?= (int)$a['role_id'] ?>">
            <span class="role"><?= h($roleLabels[$a['role_code']] ?? $a['role_code']) ?></span>
            <div style="flex:1;min-width:0;">
              <div class="nm"><?= h($acteurNom($a)) ?>
                <?php if (!empty($meta['modifiable'])): ?><span class="dv-badge" title="Proposé automatiquement, modifiable">proposé</span><?php endif; ?>
              </div>
              <?php if ($a['email'] || $a['telephone']): ?>
                <div class="ct"><?= h(trim(($a['email'] ?? '') . ($a['telephone'] ? ' · ' . $a['telephone'] : ''))) ?></div>
              <?php endif; ?>
            </div>
            <?php if (!empty($a['id_tiers'])): ?>
              <a class="dv-badge" href="<?= h(app_url('/tiers_360.php?id=' . (int)$a['id_tiers'])) ?>">fiche →</a>
            <?php endif; ?>
            <button type="button" class="dv-actor-del" title="Retirer du dossier" onclick="dvRemoveActeur(<?= (int)$a['role_id'] ?>, this)">×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="dv-empty" id="dv-acteurs-empty" style="<?= $acteurs ? 'display:none;' : '' ?>">Aucun acteur rattaché.</div>
      <div class="dv-note">Acteurs reliés au référentiel <code>tiers</code> (aucune ressaisie : un tiers existant est réutilisé, un nouveau est créé une seule fois).</div>
    </div>

    <!-- BIEN / MANDAT / PRIX -->
    <div class="dv-card">
      <h3>🏠 Bien &amp; mandat</h3>
      <div class="dv-row"><span class="k">Référence</span><span class="v"><?= h($refBien) ?></span></div>
      <?php if (!empty($bien['surface_habitable'])): ?>
        <div class="dv-row"><span class="k">Surface</span><span class="v"><?= number_format((float)$bien['surface_habitable'],0,',',' ') ?> m²<?= !empty($bien['nb_pieces']) ? ' · ' . (int)$bien['nb_pieces'] . ' p.' : '' ?></span></div>
      <?php endif; ?>
      <?php if (!empty($bien['immeuble_id'])): ?>
        <div class="dv-row"><span class="k">Immeuble</span><span class="v"><a href="<?= h(app_url('/immeuble_360.php?id=' . (int)$bien['immeuble_id'])) ?>"><?= h($bien['nom_immeuble'] ?: $bien['imm_adresse']) ?></a></span></div>
      <?php endif; ?>
      <div class="dv-row"><span class="k">Mandat de vente</span><span class="v">
        <?php if ($mandat): ?><?= h($mandat['numero_mandat'] ?: ('#' . $mandat['id'])) ?><?= !empty($mandat['exclusif']) ? ' · exclusif' : '' ?><?php else: ?>—<?php endif; ?>
      </span></div>
      <?php if ($mandat): ?>
        <div class="dv-row"><span class="k">Signé le</span><span class="v"><?= h($fmtDate($mandat['date_signature'] ?: $mandat['date_debut'])) ?></span></div>
      <?php endif; ?>
      <div style="margin-top:12px;text-align:center;">
        <div style="font-size:11px;color:#64748b;font-weight:700;">PRIX COURANT</div>
        <div class="dv-prix"><?= h($fmtPrix($prixCourant)) ?></div>
      </div>
    </div>

    <!-- OFFRES -->
    <div class="dv-card">
      <h3>💰 Offres (<?= count($offres) ?>)</h3>
      <?php if (!$offres): ?>
        <div class="dv-empty">Aucune offre reçue.</div>
      <?php else: foreach ($offres as $o): ?>
        <div class="dv-row">
          <span class="k"><?= h(trim(($o['prenom'] ?? '') . ' ' . ($o['nom'] ?? '')) ?: 'Acquéreur') ?>
            <?php if ($o['statut_offre']): ?><span class="dv-badge"><?= h($o['statut_offre']) ?></span><?php endif; ?>
          </span>
          <span class="v"><?= h($fmtPrix($o['prix_propose'])) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- DOCUMENTS -->
    <div class="dv-card">
      <h3>📄 Documents</h3>
      <?php
        // Docs du dossier d'abord, puis ceux du bien (dédupliqués par id).
        $seen = [];
        $allDocs = [];
        foreach ($docsDoss as $d) { $seen[$d['id']] = 1; $d['_scope'] = 'dossier'; $allDocs[] = $d; }
        foreach ($docsBien as $d) { if (isset($seen[$d['id']])) continue; $d['_scope'] = 'bien'; $allDocs[] = $d; }
      ?>
      <?php if (!$allDocs): ?>
        <div class="dv-empty">Aucun document rattaché.</div>
      <?php else: foreach (array_slice($allDocs, 0, 40) as $d): ?>
        <div class="dv-doc">
          <a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$d['id'])) ?>" target="_blank">
            <?= h($d['name_display'] ?: $d['name_file'] ?: ('Doc #' . $d['id'])) ?>
          </a>
          <span>
            <?php if (!empty($d['document_type'])): ?><span class="dv-badge"><?= h($d['document_type']) ?></span><?php endif; ?>
            <span class="dv-badge"><?= $d['_scope'] === 'dossier' ? 'dossier' : 'bien' ?></span>
          </span>
        </div>
      <?php endforeach; endif; ?>
      <div class="dv-note">GED unique — un même document peut être rattaché au bien et au dossier sans duplication physique.</div>
    </div>
  </div>

  <div class="dv-note" style="margin-top:18px;">
    Dossier #<?= (int)$dossier['id'] ?> · étape <strong><?= h($dossier['etape']) ?></strong> ·
    source <?= h($dossier['source']) ?> · créé le <?= h($fmtDate($dossier['created_at'])) ?>.
    Toutes les données ci-dessus sont lues depuis les modules existants (aucune ressaisie).
  </div>
</div>

<!-- ═══ MODAL : ajouter un acteur au dossier ═══ -->
<div class="dvm-backdrop" id="dvm-acteur">
  <div class="dvm">
    <h3>➕ Ajouter un acteur</h3>
    <div class="sub">Choisissez un rôle, puis recherchez un tiers existant ou créez-en un nouveau (zéro double saisie).</div>

    <p class="dvm-label">1 · Rôle dans la vente</p>
    <div class="dvm-roles" id="dvm-roles">
      <?php foreach (dv_roles_autorises() as $code => $lib): ?>
        <button type="button" class="dvm-role" data-role="<?= h($code) ?>"><?= h($lib) ?></button>
      <?php endforeach; ?>
    </div>

    <p class="dvm-label">2 · Tiers</p>
    <?php tiers_selector_render([
        'id'           => 'dvm_tiers',
        'name'         => 'dvm_id_tiers',
        'allow_create' => true,
        'placeholder'  => 'Rechercher (nom, email, téléphone…) ou créer',
    ]); ?>

    <div class="dvm-actions">
      <button type="button" class="dvm-btn cancel" onclick="dvCloseActeurModal()">Annuler</button>
      <button type="button" class="dvm-btn ok" id="dvm-submit" disabled onclick="dvSubmitActeur()">Ajouter au dossier</button>
    </div>
  </div>
</div>
<?php tiers_selector_assets(); ?>

<script>
(function(){
  const DOSSIER_ID = <?= (int)$idDossier ?>;
  const API_ADD    = <?= json_encode(app_url('/api/transaction_dossier_acteur_add.php')) ?>;
  const API_DEL    = <?= json_encode(app_url('/api/transaction_dossier_acteur_remove.php')) ?>;
  const TIERS_FICHE= <?= json_encode(app_url('/tiers_360.php?id=')) ?>;
  let selectedRole = '';

  const root   = document.querySelector('[data-ts-root="dvm_tiers"]');
  const hidden = () => root ? root.querySelector('.ts-value') : null;

  function refreshSubmit(){
    const ok = selectedRole !== '' && hidden() && hidden().value;
    document.getElementById('dvm-submit').disabled = !ok;
  }

  // Choix du rôle (boutons, pas de select)
  document.getElementById('dvm-roles').addEventListener('click', (e)=>{
    const b = e.target.closest('.dvm-role'); if(!b) return;
    document.querySelectorAll('#dvm-roles .dvm-role').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    selectedRole = b.dataset.role;
    // Le rôle choisi devient aussi le rôle global posé à la création du tiers
    if (root) root.dataset.tsDefaultRoles = selectedRole;
    refreshSubmit();
  });

  // Tiers sélectionné OU créé → le hidden est renseigné
  if (root){
    root.addEventListener('tiers:selected', refreshSubmit);
    root.addEventListener('tiers:created',  refreshSubmit);
    root.querySelector('.ts-search')?.addEventListener('input', ()=>setTimeout(refreshSubmit,50));
    root.querySelector('.ts-clear')?.addEventListener('click', ()=>setTimeout(refreshSubmit,10));
  }

  window.dvOpenActeurModal = function(){
    selectedRole='';
    document.querySelectorAll('#dvm-roles .dvm-role').forEach(x=>x.classList.remove('active'));
    if (hidden()) hidden().value='';
    const s = root && root.querySelector('.ts-search'); if(s){ s.value=''; s.classList.remove('is-selected'); }
    refreshSubmit();
    document.getElementById('dvm-acteur').classList.add('open');
  };
  window.dvCloseActeurModal = function(){ document.getElementById('dvm-acteur').classList.remove('open'); };

  window.dvSubmitActeur = async function(){
    const idTiers = hidden() && hidden().value;
    if (!selectedRole || !idTiers) return;
    const btn = document.getElementById('dvm-submit'); btn.disabled=true; btn.textContent='Ajout…';
    try{
      const body = new URLSearchParams({ id_dossier:DOSSIER_ID, id_tiers:idTiers, role_code:selectedRole });
      const res  = await fetch(API_ADD, {method:'POST', credentials:'same-origin', body});
      const out  = await res.json();
      if(!out.ok){ alert('Erreur : '+(out.error||'inconnue')); return; }
      dvAppendActeur(out.acteur);
      dvCloseActeurModal();
    }catch(err){ alert('Erreur réseau : '+err.message); }
    finally{ btn.disabled=false; btn.textContent='Ajouter au dossier'; }
  };

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  window.dvAppendActeur = function(a){
    document.getElementById('dv-acteurs-empty').style.display='none';
    const list = document.getElementById('dv-acteurs-list');
    // si le même rôle existe déjà (réactivation), ne pas dupliquer
    if (list.querySelector('[data-role-id="'+a.role_id+'"]')) return;
    const ct = [a.email, a.telephone].filter(Boolean).join(' · ');
    const div = document.createElement('div');
    div.className='dv-actor'; div.dataset.roleId=a.role_id;
    div.innerHTML =
      '<span class="role">'+esc(a.role_label)+'</span>'+
      '<div style="flex:1;min-width:0;"><div class="nm">'+esc(a.nom)+'</div>'+
      (ct?'<div class="ct">'+esc(ct)+'</div>':'')+'</div>'+
      '<a class="dv-badge" href="'+TIERS_FICHE+a.id_tiers+'">fiche →</a>'+
      '<button type="button" class="dv-actor-del" title="Retirer du dossier" onclick="dvRemoveActeur('+a.role_id+', this)">×</button>';
    list.appendChild(div);
  };

  window.dvRemoveActeur = async function(roleId, el){
    if(!confirm('Retirer cet acteur du dossier ?')) return;
    try{
      const body = new URLSearchParams({ id_dossier:DOSSIER_ID, role_id:roleId });
      const res  = await fetch(API_DEL, {method:'POST', credentials:'same-origin', body});
      const out  = await res.json();
      if(!out.ok){ alert('Erreur : '+(out.error||'inconnue')); return; }
      const row = el.closest('.dv-actor'); if(row) row.remove();
      if(!document.querySelector('#dv-acteurs-list .dv-actor'))
        document.getElementById('dv-acteurs-empty').style.display='';
    }catch(err){ alert('Erreur réseau : '+err.message); }
  };
})();
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
