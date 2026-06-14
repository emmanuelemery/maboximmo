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

// ── Signatures du mandat (si mandat lié) ──
require_once __DIR__ . '/inc/mandat_signature.php';
$signatures = $mandat ? msig_list_for_mandat($pdo, (int)$mandat['id']) : [];

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
    'avocat' => 'Avocat', 'partenaire_apporteur' => 'Apporteur / partenaire',
    'collaborateur' => 'Collaborateur',
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
.dv-statut{display:inline-block;border-radius:8px;padding:5px 14px;font-weight:800;font-size:12.5px;}
.dv-statut.temp{background:#fef3c7;color:#92600a;border:1px solid #fcd980;}
.dv-statut.conf{background:#d7f0e0;color:#0b6b35;border:1px solid #9ad3ab;}
.dv-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:880px){.dv-grid{grid-template-columns:1fr;}}
/* Consultation mobile efficace */
@media(max-width:640px){
  .dv-wrap{padding:6px 10px 40px;}
  .dv-wrap h1{font-size:18px !important;}
  .dv-timeline{gap:5px;}
  .dv-step{flex:1 1 calc(33.333% - 5px);min-width:0;padding:9px 5px;}
  .dv-step .ic{font-size:18px;} .dv-step .lb{font-size:10px;} .dv-step .soon{display:none;}
  .dv-card{padding:14px 15px;border-radius:12px;}
  .dvm{padding:18px 16px;border-radius:14px;}
  .dvm-actions{flex-direction:column-reverse;}
  .dvm-actions .dvm-btn{width:100%;}
}
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
.dv-estim-btn{margin-top:8px;border:none;background:none;color:#0f6cbd;font-size:12px;font-weight:700;cursor:pointer;text-decoration:underline;}
.dv-estim-btn:hover{color:#0c5aa0;}
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
/* Modal adresse Google par-dessus le modal création tiers (cascade complète). */
.addr-modal{z-index:9700 !important;}
.addr-modal .places-dropdown{z-index:9800 !important;}
</style>

<div class="dv-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <div style="color:#475569;font-size:14px;font-weight:700;">
        <?= h($bien['designation'] ?: $refBien) ?>
        <span style="color:#64748b;font-weight:400;"><?= h(trim(($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? ''))) ?></span>
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

  <?php $estTemporaire = (($dossier['statut'] ?? 'temporaire') === 'temporaire'); ?>
  <div style="margin-top:12px;">
    <?php if ($estTemporaire): ?>
      <span class="dv-statut temp">⏳ Dossier temporaire — confirmé à la signature du mandat de vente</span>
    <?php else: ?>
      <span class="dv-statut conf">✅ Dossier confirmé</span>
    <?php endif; ?>
  </div>

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
        <?php if ($mandat['honoraires'] !== null && $mandat['honoraires'] !== ''): ?>
          <div class="dv-row"><span class="k">Honoraires</span><span class="v"><?= h($fmtPrix($mandat['honoraires'])) ?><?= !empty($mandat['honoraires_charge']) ? ' · ' . h($mandat['honoraires_charge']) : '' ?></span></div>
        <?php endif; ?>
        <div class="dv-row"><span class="k">Signature</span><span class="v">
          <?php
            $nbSig = count($signatures);
            $nbSigne = count(array_filter($signatures, fn($s) => $s['statut'] === 'signe'));
            if ($nbSig === 0)            echo '<span class="dv-badge">non envoyé</span>';
            elseif ($nbSigne === $nbSig) echo '<span class="dv-badge" style="background:#d7f0e0;color:#0b6b35;">signé ✓</span>';
            else                         echo '<span class="dv-badge" style="background:#fef3c7;color:#92600a;">' . $nbSigne . '/' . $nbSig . ' signé</span>';
          ?>
        </span></div>
        <?php foreach ($signatures as $s): ?>
          <div class="dv-row" style="font-size:12px;">
            <span class="k"><?= h($s['nom_affichage'] ?: $s['raison_sociale'] ?: trim(($s['prenom'] ?? '') . ' ' . ($s['nom'] ?? '')) ?: 'Vendeur') ?></span>
            <span class="v"><?php if ($s['statut'] === 'signe'): ?>✅ <?= h($fmtDate($s['signed_at'])) ?> (IP <?= h($s['ip'] ?: '—') ?>)<?php else: ?>⏳ en attente<?php endif; ?></span>
          </div>
        <?php endforeach; ?>
        <div style="margin-top:10px;text-align:center;">
          <button type="button" class="dvm-btn ok" style="padding:9px 16px;" onclick="dvSendMandat()">✉️ Envoyer au vendeur pour signature</button>
          <div id="dv-mandat-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
        </div>
      <?php else: ?>
        <div style="margin-top:10px;text-align:center;">
          <button type="button" class="dvm-btn ok" style="padding:9px 16px;" onclick="dvOpenMandatModal()">📝 Créer le mandat de vente</button>
        </div>
      <?php endif; ?>
      <div style="margin-top:12px;text-align:center;">
        <div style="font-size:11px;color:#64748b;font-weight:700;">PRIX COURANT</div>
        <div class="dv-prix" id="dv-prix-val"><?= h($fmtPrix($prixCourant)) ?></div>
        <button type="button" class="dv-estim-btn" onclick="dvToggleEstim(true)"><?= $prixCourant ? '✏️ Modifier l\'estimation' : '📊 Estimer le prix' ?></button>
        <div id="dv-estim-form" style="display:none;margin-top:10px;">
          <div style="display:flex;gap:8px;justify-content:center;align-items:center;">
            <input type="text" id="dv-estim-input" inputmode="numeric" placeholder="Prix de vente €"
                   value="<?= $prixCourant ? (int)$prixCourant : '' ?>"
                   style="width:150px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;text-align:right;">
            <button type="button" class="dvm-btn ok" style="padding:8px 14px;" onclick="dvSaveEstim()">Valider</button>
            <button type="button" class="dvm-btn cancel" style="padding:8px 12px;" onclick="dvToggleEstim(false)">×</button>
          </div>
          <div id="dv-estim-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
        </div>
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
<!-- ═══ MODAL : créer le mandat de vente (termes) ═══ -->
<div class="dvm-backdrop" id="dvm-mandat">
  <div class="dvm">
    <h3>📝 Créer le mandat de vente</h3>
    <div class="sub">Les termes du mandat. Le bien et le vendeur sont déjà repris du dossier (zéro ressaisie).</div>

    <p class="dvm-label">Honoraires</p>
    <div style="display:flex;gap:8px;align-items:center;">
      <input type="text" id="dvm-honoraires" inputmode="numeric" placeholder="Montant €"
             style="width:140px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;text-align:right;">
      <span style="font-size:12px;color:#64748b;">à charge :</span>
      <div class="dvm-roles" id="dvm-charge" style="margin:0;">
        <button type="button" class="dvm-role" data-charge="vendeur">Vendeur</button>
        <button type="button" class="dvm-role" data-charge="acquereur">Acquéreur</button>
        <button type="button" class="dvm-role" data-charge="partage">Partagé</button>
      </div>
    </div>

    <p class="dvm-label" style="margin-top:14px;">Exclusivité</p>
    <div class="dvm-roles" id="dvm-exclusif">
      <button type="button" class="dvm-role" data-excl="0">Mandat simple</button>
      <button type="button" class="dvm-role" data-excl="1">Mandat exclusif</button>
    </div>

    <div style="display:flex;gap:18px;margin-top:14px;flex-wrap:wrap;">
      <div><p class="dvm-label">Durée (mois)</p>
        <input type="text" id="dvm-duree" inputmode="numeric" value="3" style="width:90px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;text-align:center;"></div>
      <div><p class="dvm-label">Prise d'effet</p>
        <input type="date" id="dvm-datedebut" value="<?= date('Y-m-d') ?>" style="padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;"></div>
    </div>

    <div class="dvm-actions">
      <button type="button" class="dvm-btn cancel" onclick="dvCloseMandatModal()">Annuler</button>
      <button type="button" class="dvm-btn ok" onclick="dvSubmitMandat()">Créer le mandat</button>
    </div>
    <div id="dvm-mandat-form-msg" style="font-size:11px;color:#94a3b8;margin-top:8px;text-align:right;"></div>
  </div>
</div>

<?php tiers_selector_assets(); ?>

<?php
// Modal d'adresse Google (obligatoire pour la saisie d'adresse d'un nouveau tiers).
require_once __DIR__ . '/inc/adresse_modal.php';
?>
<script src="<?= h(asset_url('/js/places.js')) ?>"></script>
<script src="<?= h(asset_url('/js/adresse_modal.js')) ?>"></script>
<?php if (($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '') !== ''): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= h($GLOBALS['GOOGLE_MAPS_API_KEY']) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>

<script>
(function(){
  const DOSSIER_ID = <?= (int)$idDossier ?>;
  const API_ADD    = <?= json_encode(app_url('/api/transaction_dossier_acteur_add.php')) ?>;
  const API_DEL    = <?= json_encode(app_url('/api/transaction_dossier_acteur_remove.php')) ?>;
  const API_ESTIM  = <?= json_encode(app_url('/api/transaction_dossier_estimation_save.php')) ?>;
  const API_MANDAT = <?= json_encode(app_url('/api/transaction_dossier_mandat_create.php')) ?>;
  const API_MSEND  = <?= json_encode(app_url('/api/transaction_dossier_mandat_send.php')) ?>;
  const TIERS_FICHE= <?= json_encode(app_url('/tiers_360.php?id=')) ?>;
  let selectedRole = '';
  let mCharge = '', mExcl = '0';

  // ── Mandat : création (termes) ──
  window.dvOpenMandatModal = function(){ document.getElementById('dvm-mandat').classList.add('open'); };
  window.dvCloseMandatModal = function(){ document.getElementById('dvm-mandat').classList.remove('open'); };
  document.getElementById('dvm-charge')?.addEventListener('click', e=>{
    const b=e.target.closest('.dvm-role'); if(!b)return;
    document.querySelectorAll('#dvm-charge .dvm-role').forEach(x=>x.classList.remove('active'));
    b.classList.add('active'); mCharge=b.dataset.charge;
  });
  document.getElementById('dvm-exclusif')?.addEventListener('click', e=>{
    const b=e.target.closest('.dvm-role'); if(!b)return;
    document.querySelectorAll('#dvm-exclusif .dvm-role').forEach(x=>x.classList.remove('active'));
    b.classList.add('active'); mExcl=b.dataset.excl;
  });
  window.dvSubmitMandat = async function(){
    const msg=document.getElementById('dvm-mandat-form-msg'); msg.textContent='Création…';
    try{
      const body=new URLSearchParams({
        id_dossier:DOSSIER_ID,
        honoraires:(document.getElementById('dvm-honoraires').value||'').replace(/[^0-9.,]/g,''),
        honoraires_charge:mCharge, exclusif:mExcl,
        duree_mois:(document.getElementById('dvm-duree').value||'').replace(/[^0-9]/g,''),
        date_debut:document.getElementById('dvm-datedebut').value||''
      });
      const res=await fetch(API_MANDAT,{method:'POST',credentials:'same-origin',body});
      const out=await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      msg.style.color='#0b8043'; msg.textContent='✓ '+(out.message||'Mandat créé');
      setTimeout(()=>location.reload(), 800);
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

  // ── Mandat : envoi au vendeur pour signature ──
  window.dvSendMandat = async function(){
    const msg=document.getElementById('dv-mandat-msg'); msg.style.color='#94a3b8'; msg.textContent='Envoi…';
    try{
      const body=new URLSearchParams({ id_dossier:DOSSIER_ID });
      const res=await fetch(API_MSEND,{method:'POST',credentials:'same-origin',body});
      const out=await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      const lignes=(out.envois||[]).map(e=>{
        if(e.sent) return '✉️ Email envoyé à '+e.email;
        if(e.email) return '⚠️ Email non parti ('+e.email+') — lien : '+e.url;
        return '🔗 Pas d\'email vendeur — lien à transmettre : '+e.url;
      });
      msg.style.color='#0b8043'; msg.innerHTML='✓ '+lignes.join('<br>');
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

  // ── Estimation inline (écrit dans bien_prix via l'endpoint dédié) ──
  window.dvToggleEstim = function(show){
    document.getElementById('dv-estim-form').style.display = show ? '' : 'none';
    if (show) setTimeout(()=>document.getElementById('dv-estim-input').focus(), 30);
  };
  window.dvSaveEstim = async function(){
    const inp = document.getElementById('dv-estim-input');
    const msg = document.getElementById('dv-estim-msg');
    const prix = (inp.value || '').replace(/[^0-9.,]/g,'');
    if (!prix){ msg.textContent='Saisis un prix.'; return; }
    msg.textContent='Enregistrement…';
    try{
      const body = new URLSearchParams({ id_dossier:DOSSIER_ID, prix });
      const res  = await fetch(API_ESTIM, {method:'POST', credentials:'same-origin', body});
      const out  = await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      document.getElementById('dv-prix-val').textContent = out.prix_fmt;
      msg.style.color='#0b8043'; msg.textContent='✓ Estimation enregistrée'+(out.etape?' · étape : '+out.etape:'');
      setTimeout(()=>dvToggleEstim(false), 1200);
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

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
