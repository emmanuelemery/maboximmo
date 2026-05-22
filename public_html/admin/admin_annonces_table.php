<?php
/**
 * admin/admin_annonces_table.php
 *
 * Tableau éditable inline de la table `annonces` — évite phpMyAdmin.
 * Cellules cliquables → input/select → blur save via api/admin_annonce_edit.php.
 * FK (id_agence, id_user, id_societe) affichées avec le libellé résolu.
 *
 * Accès : Super Admin uniquement (données brutes, édition directe).
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin_or_super_admin();

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

function ate($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Filtres
$fAgence = (int)($_GET['id_agence']    ?? 0);
$fUser   = (int)($_GET['id_user']      ?? 0);
$fTrans  = trim((string)($_GET['transaction'] ?? ''));
$fEtat   = trim((string)($_GET['etat'] ?? ''));
$sort    = trim((string)($_GET['sort'] ?? 'id'));
$dir     = strtolower(trim((string)($_GET['dir'] ?? 'desc')));
if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

$where = ['1=1'];
$params = [];
if (!$isSuperAdmin && $societeId > 0) {
    $where[] = 'a.id_societe = :fsoc';
    $params[':fsoc'] = $societeId;
}
if ($fAgence > 0) { $where[] = 'a.id_agence = :fag'; $params[':fag'] = $fAgence; }
if ($fUser   > 0) { $where[] = 'a.id_user = :fu';    $params[':fu']  = $fUser;   }
if ($fTrans !== '') { $where[] = 'a.type_transaction = :ftr'; $params[':ftr'] = $fTrans; }
if ($fEtat === 'brouillon')    $where[] = "(a.etat_publication = 'brouillon' OR a.statut = 'brouillon')";
if ($fEtat === 'diffusee')     $where[] = "(a.etat_publication IN ('diffusee','publiee') OR a.statut IN ('publiee','active','en_ligne'))";
if ($fEtat === 'archivee')     $where[] = "a.etat_publication IN ('archivee','archived')";
$whereClause = 'WHERE ' . implode(' AND ', $where);

// Whitelist des colonnes triables (sécurité — pas d'injection via $_GET)
$sortable = [
    'id'                    => 'a.id',
    'id_bien'               => 'a.id_bien',
    'id_agence'             => 'ag.nom_agence',
    'id_societe'            => 's.nom',
    'id_user'               => 'u.nom',
    'type_transaction'      => 'a.type_transaction',
    'statut'                => 'a.statut',
    'etat_publication'      => 'a.etat_publication',
    'visible_portails'      => 'a.visible_portails',
    'visible_site'          => 'a.visible_site',
    'visible_maboximmo'     => 'a.visible_maboximmo',
    'visible_site_perso'    => 'a.visible_site_perso',
    'prix'                  => 'a.prix',
    'loyer'                 => 'a.loyer',
    'loyer_cc'              => 'a.loyer_cc',
    'honoraires_location_bail'  => 'a.honoraires_location_bail',
    'honoraires_etat_des_lieux' => 'a.honoraires_etat_des_lieux',
    'depot_garantie'        => 'a.depot_garantie',
    'mandat_numero'         => 'a.mandat_numero',
    'mandat_type'           => 'a.mandat_type',
    'titre'                 => 'a.titre',
    'date_modification'     => 'a.date_modification',
];
$orderSql = ($sortable[$sort] ?? 'a.id') . ' ' . strtoupper($dir);

$sql = "
    SELECT
        a.id, a.id_bien, a.id_agence, a.id_societe, a.id_user,
        a.reference_annonce, a.titre, a.type_transaction,
        a.statut, a.etat_publication,
        -- Vente
        a.prix, a.prix_net_vendeur,
        a.honoraires, a.honoraires_charge_acquereur, a.honoraires_charge_vendeur,
        a.alur_pourcentage_honoraires_ttc, a.pourcentage_honoraires_vendeur,
        a.honoraires_negociation_cumules,
        -- Location
        a.loyer, a.loyer_cc, a.loyer_est_cc,
        a.complement_loyer, a.loyer_reference_majore, a.loyer_de_base,
        a.zone_encadrement_loyer, a.modalite_recuperation_charges_locatives,
        a.depot_garantie,
        a.honoraires_location_bail, a.honoraires_etat_des_lieux,
        a.duree_bail_mois, a.date_disponibilite, a.disponible_de_suite,
        a.meuble,
        a.ancien_loyer_montant, a.ancien_loyer_charges, a.ancien_loyer_communique,
        -- Taxes
        a.charges, a.taxe_fonciere, a.taxe_habitation, a.taxe_ordures_menageres,
        -- Visibilité
        a.visible_portails, a.visible_site, a.visible_maboximmo, a.visible_site_perso,
        a.exclusivite, a.coup_coeur,
        -- Mandat
        a.mandat_numero, a.mandat_type, a.date_mandat, a.mandat_echeance,
        a.url_tarifs_publics,
        -- Charges biens (source Ubiflow)
        b.charges_locatives AS b_charges_locatives,
        -- Meta
        a.date_creation, a.date_modification,
        b.reference_bien,
        ag.nom_agence,
        s.nom AS societe_nom,
        CONCAT(u.prenom, ' ', u.nom) AS user_nom
    FROM annonces a
    LEFT JOIN biens b     ON b.id = a.id_bien
    LEFT JOIN agences ag  ON ag.id = a.id_agence
    LEFT JOIN societes s  ON s.id = a.id_societe
    LEFT JOIN users u     ON u.id = a.id_user
    {$whereClause}
    ORDER BY {$orderSql}
    LIMIT 300
";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Options FK pour les dropdowns
$agences  = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
$societes = $pdo->query("SELECT id, COALESCE(NULLIF(raison_sociale,''), nom) AS lbl FROM societes ORDER BY lbl")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) AS lbl FROM users WHERE actif = 1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);

$csrf = function_exists('csrf_token') ? csrf_token('admin_annonce_edit') : '';

$pageTitle    = 'BDD Annonces — édition directe';
$pageSubtitle = 'Super Admin · remplace phpMyAdmin';
require_once __DIR__ . '/../inc/agency_layout_top.php';
?>

<style>
  .at-wrap { max-width: 100%; padding: 0 8px; }
  .at-filters { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin-bottom:14px;
                display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .at-filters select { padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:12px; }
  .at-filters .count { margin-left:auto; font-size:12px; color:#64748b; }
  .at-table { border-collapse:collapse; font-size:10px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; }
  .at-table th { background:#f8fafc; padding:5px 6px; text-align:left; font-weight:700; color:#475569; border-bottom:1px solid #e5e7eb; white-space:nowrap; font-size:9px; text-transform:uppercase; letter-spacing:.02em; position:sticky; top:0; z-index:2; }
  .at-table th a { color:#475569; text-decoration:none; display:inline-flex; align-items:center; gap:3px; }
  .at-table th a:hover { color:#0369a1; }
  .at-table th .arrow { color:#0ea5e9; font-weight:800; }
  .at-table td { padding:3px 6px; border-bottom:1px solid #f1f5f9; vertical-align:middle; white-space:nowrap; }
  .at-table tr:hover td { background:#f8fafc; }
  .at-cell { cursor:pointer; padding:2px 4px; border-radius:3px; min-height:16px; display:inline-block; min-width:30px; font-size:10px; }
  .at-cell:hover { background:#dbeafe; }
  .at-cell.saving { background:#fef3c7 !important; }
  .at-cell.saved { background:#dcfce7 !important; transition:background 1s; }
  .at-cell.err { background:#fee2e2 !important; }
  .at-cell input, .at-cell select {
    font-size:10px; padding:1px 3px; border:1px solid #0ea5e9; border-radius:3px;
    background:#fff; min-width:70px; font-family:inherit;
  }
  .at-id { font-family:monospace; font-size:9px; color:#64748b; }
  .at-fk-link { color:#0369a1; text-decoration:none; border-bottom:1px dotted #0ea5e9; font-size:10px; }
  .at-fk-link:hover { background:#dbeafe; }
  .at-b { padding:1px 5px; border-radius:99px; font-size:8px; font-weight:700; }
  .at-b-on  { background:#dcfce7; color:#166534; }
  .at-b-off { background:#fee2e2; color:#991b1b; }
  .at-ref { font-family:monospace; font-size:9px; color:#334155; }
  .at-scroll { overflow-x:auto; max-width:100%; border-radius:6px; max-height:calc(100vh - 240px); overflow-y:auto; }
</style>

<div class="at-wrap">

  <form method="get" class="at-filters" action="<?= htmlspecialchars(app_url('/admin/admin_annonces_table.php')) ?>">
    <!-- Préserve le tri courant lors d'un filtrage -->
    <input type="hidden" name="sort" value="<?= ate($sort) ?>">
    <input type="hidden" name="dir"  value="<?= ate($dir) ?>">

    <label style="font-size:12px; font-weight:600; color:#475569;">Agence</label>
    <select name="id_agence" onchange="this.form.submit()">
      <option value="0">Toutes</option>
      <?php foreach ($agences as $ag): ?>
        <option value="<?= (int)$ag['id'] ?>" <?= $fAgence === (int)$ag['id'] ? 'selected' : '' ?>><?= ate($ag['nom_agence']) ?></option>
      <?php endforeach; ?>
    </select>

    <label style="font-size:12px; font-weight:600; color:#475569;">Commercial</label>
    <select name="id_user" onchange="this.form.submit()">
      <option value="0">Tous</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= ate($u['lbl']) ?></option>
      <?php endforeach; ?>
    </select>

    <label style="font-size:12px; font-weight:600; color:#475569;">Transaction</label>
    <select name="transaction" onchange="this.form.submit()">
      <option value="">Toutes</option>
      <option value="vente"      <?= $fTrans==='vente' ? 'selected':'' ?>>Vente</option>
      <option value="location"   <?= $fTrans==='location' ? 'selected':'' ?>>Location</option>
      <option value="saisonnier" <?= $fTrans==='saisonnier' ? 'selected':'' ?>>Saisonnier</option>
      <option value="viager"     <?= $fTrans==='viager' ? 'selected':'' ?>>Viager</option>
    </select>

    <label style="font-size:12px; font-weight:600; color:#475569;">État</label>
    <select name="etat" onchange="this.form.submit()">
      <option value="">Tous</option>
      <option value="brouillon" <?= $fEtat === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
      <option value="diffusee"  <?= $fEtat === 'diffusee'  ? 'selected' : '' ?>>Diffusée</option>
      <option value="archivee"  <?= $fEtat === 'archivee'  ? 'selected' : '' ?>>Archivée</option>
    </select>

    <a href="<?= htmlspecialchars(app_url('/admin/admin_annonces_table.php')) ?>" style="font-size:12px; color:#64748b;">✕ Reset</a>
    <span class="count"><?= count($rows) ?> annonce(s) · tri : <?= ate($sort) ?> <?= $dir === 'desc' ? '↓' : '↑' ?></span>
  </form>

  <div style="background:#fef3c7; padding:10px 14px; border-radius:8px; margin-bottom:10px; font-size:12px; color:#78350f;">
    ⚠️ <strong>Édition directe BDD</strong> — clique une cellule pour la modifier. La sauvegarde se fait au blur (hors focus).
    Toute modif est irréversible — pas de système d'annulation.
  </div>

  <?php
    // ── Définition centralisée des colonnes ───────────────────────────────
    // scope : 'both' | 'vente' | 'location' (caché si filtre type_transaction différent)
    // type : 'id' (readonly fk_bien), 'fk-agence'|'fk-societe'|'fk-user', 'enum-*',
    //        'bool', 'decimal', 'text', 'readonly'
    // tip : tooltip affiché au survol du header — explique l'utilité + balise Ubiflow si exportée
    $columns = [
      ['key'=>'id',                'label'=>'#id',       'type'=>'readonly', 'scope'=>'both',     'tip'=>"Identifiant unique de l'annonce"],
      ['key'=>'id_bien',           'label'=>'Bien',      'type'=>'fk_bien',  'scope'=>'both',     'tip'=>'Lien vers le bien rattaché'],
      ['key'=>'id_agence',         'label'=>'Agence',    'type'=>'fk-agence','scope'=>'both',     'tip'=>"Agence de l'annonce (détermine le flux Ubiflow destination)"],
      ['key'=>'id_societe',        'label'=>'Société',   'type'=>'fk-societe','scope'=>'both',    'tip'=>"Société d'appartenance"],
      ['key'=>'id_user',           'label'=>'Commercial','type'=>'fk-user',  'scope'=>'both',     'tip'=>"Commercial en charge (contact_nom sur Ubiflow)"],
      ['key'=>'type_transaction',  'label'=>'Transaction','type'=>'enum-trans','scope'=>'both',   'tip'=>"Vente/Location/Saisonnier/Viager → balise Ubiflow <prestation type> (V/L/S/W)"],
      ['key'=>'statut',            'label'=>'Statut',    'type'=>'enum-statut','scope'=>'both',   'tip'=>"Statut interne (brouillon/publiee/active/en_ligne/archivee). Requis publiée pour flux Ubiflow."],
      ['key'=>'etat_publication',  'label'=>'État pub.', 'type'=>'enum-etat','scope'=>'both',     'tip'=>"État du cycle de publication (brouillon/diffusée/archivée)"],
      ['key'=>'visible_portails',  'label'=>'Port.',     'type'=>'bool',     'scope'=>'both',     'tip'=>"Visible sur les portails Ubiflow (LeBonCoin/SeLoger). Obligatoire pour diffusion."],
      ['key'=>'visible_site',      'label'=>'Site',      'type'=>'bool',     'scope'=>'both',     'tip'=>"Visible sur le site public MaBoxImmo"],
      ['key'=>'visible_maboximmo', 'label'=>'MBI',       'type'=>'bool',     'scope'=>'both',     'tip'=>"Visible dans l'annuaire interne MaBoxImmo"],
      ['key'=>'visible_site_perso','label'=>'S.pers',    'type'=>'bool',     'scope'=>'both',     'tip'=>"Visible sur le site perso de l'agence"],
      ['key'=>'exclusivite',       'label'=>'Excl.',     'type'=>'bool',     'scope'=>'both',     'tip'=>"Mandat exclusif (balise <exclusivite> Ubiflow)"],
      ['key'=>'coup_coeur',        'label'=>'♥',         'type'=>'bool',     'scope'=>'both',     'tip'=>"Mise en avant coup de cœur"],

      // ── VENTE uniquement ─────────────────────────────────────────────
      ['key'=>'prix',              'label'=>'Prix',      'type'=>'decimal',  'scope'=>'vente',    'tip'=>"Prix de vente TTC honoraires inclus → balise Ubiflow <prix>"],
      ['key'=>'prix_net_vendeur',  'label'=>'Prix net',  'type'=>'decimal',  'scope'=>'vente',    'tip'=>"Prix hors honoraires (= prix - honoraires) → balise <prix_hors_honoraires>"],
      ['key'=>'honoraires',        'label'=>'Hono',      'type'=>'decimal',  'scope'=>'vente',    'tip'=>"Montant honoraires de négociation → balise <honoraires_negociation>"],
      ['key'=>'honoraires_charge_acquereur', 'label'=>'Acq.', 'type'=>'bool','scope'=>'vente',    'tip'=>"Honoraires à charge acquéreur (O/N ALUR)"],
      ['key'=>'honoraires_charge_vendeur',   'label'=>'Vend.','type'=>'bool','scope'=>'vente',    'tip'=>"Honoraires à charge vendeur (O/N ALUR)"],
      ['key'=>'alur_pourcentage_honoraires_ttc', 'label'=>'% ALUR','type'=>'decimal','scope'=>'vente','tip'=>"% TTC des honoraires sur le prix hors honoraires (obligation ALUR)"],
      ['key'=>'honoraires_negociation_cumules','label'=>'Hono cumul','type'=>'decimal','scope'=>'vente','tip'=>"Somme honoraires acquéreur + vendeur → balise <honoraires_negociation_cumules>"],

      // ── LOCATION uniquement ──────────────────────────────────────────
      ['key'=>'loyer',                 'label'=>'Loyer HC',  'type'=>'decimal','scope'=>'location','tip'=>"Loyer mensuel hors charges → balise Ubiflow <loyer_mensuel>"],
      ['key'=>'loyer_cc',              'label'=>'Loyer CC',  'type'=>'decimal','scope'=>'location','tip'=>"Loyer charges comprises (= HC + charges) → balise <loyer_mensuel_cc>"],
      ['key'=>'complement_loyer',      'label'=>'Compl.',    'type'=>'decimal','scope'=>'location','tip'=>"Complément de loyer (zone encadrée) → balise <complement_loyer>"],
      ['key'=>'loyer_reference_majore','label'=>'Majoré',    'type'=>'decimal','scope'=>'location','tip'=>"Loyer de référence majoré (zone encadrée) → balise <loyer_reference_majore>"],
      ['key'=>'loyer_de_base',         'label'=>'Base',      'type'=>'decimal','scope'=>'location','tip'=>"Loyer de base mensuel (ALUR zone encadrée) → balise <loyer_de_base>"],
      ['key'=>'zone_encadrement_loyer','label'=>'Zone enc.', 'type'=>'bool',   'scope'=>'location','tip'=>"Zone encadrement des loyers → balise <zone_encadrement_loyer>"],
      ['key'=>'b_charges_locatives',   'label'=>'Charges',   'type'=>'readonly','scope'=>'location','tip'=>"Charges locatives mensuelles (sur biens.charges_locatives) → balise <charges_locatives>"],
      ['key'=>'depot_garantie',        'label'=>'Dépôt',     'type'=>'decimal','scope'=>'location','tip'=>"Dépôt de garantie → balise <depot_garantie>. 1 mois de loyer HC par défaut."],
      ['key'=>'honoraires_location_bail',  'label'=>'Loc+bail','type'=>'decimal','scope'=>'location','tip'=>"Honoraires location + rédaction bail (plafond ALUR) → balise <honoraires_location>"],
      ['key'=>'honoraires_etat_des_lieux', 'label'=>'EDL',     'type'=>'decimal','scope'=>'location','tip'=>"Honoraires état des lieux (plafond ALUR 3 €/m²) → balise <honoraires_etat_des_lieux>"],
      ['key'=>'honoraires_loc_total',      'label'=>'Tot. loc','type'=>'readonly_calc','scope'=>'location','tip'=>"Total honoraires locataire = loc+bail + EDL → balise <honoraires_locataire_total>"],
      ['key'=>'meuble',                'label'=>'Meublé',    'type'=>'bool',  'scope'=>'location','tip'=>"Location meublée → balise <meuble>"],
      ['key'=>'disponible_de_suite',   'label'=>'Dispo',     'type'=>'bool',  'scope'=>'location','tip'=>"Disponible immédiatement → balise <disponible_immediatement>"],
      ['key'=>'date_disponibilite',    'label'=>'Date dispo','type'=>'date',  'scope'=>'location','tip'=>"Date disponibilité → balise <date_disponibilite>"],
      ['key'=>'duree_bail_mois',       'label'=>'Bail(m)',   'type'=>'decimal','scope'=>'location','tip'=>"Durée du bail en mois → balise <duree_du_bail>"],

      // ── Taxes (both) ──────────────────────────────────────────────────
      ['key'=>'taxe_fonciere',       'label'=>'TF',        'type'=>'decimal','scope'=>'both',    'tip'=>"Taxe foncière annuelle → balise <taxe_fonciere>"],
      ['key'=>'taxe_habitation',     'label'=>'TH',        'type'=>'decimal','scope'=>'both',    'tip'=>"Taxe habitation annuelle → balise <taxe_habitation>"],
      ['key'=>'taxe_ordures_menageres','label'=>'TOM',     'type'=>'decimal','scope'=>'both',    'tip'=>"Taxe enlèvement ordures ménagères (TEOM/TOM) annuelle"],

      // ── Mandat & annonce (both) ───────────────────────────────────────
      ['key'=>'mandat_numero',       'label'=>'Mandat №',  'type'=>'text',   'scope'=>'both',    'tip'=>"Numéro de mandat → balise <mandat_numero>"],
      ['key'=>'mandat_type',         'label'=>'Type M.',   'type'=>'enum-mandat','scope'=>'both','tip'=>"Type de mandat : exclusif/simple → balise <mandat_type>"],
      ['key'=>'date_mandat',         'label'=>'Date M.',   'type'=>'date',   'scope'=>'both',    'tip'=>"Date de signature du mandat → balise <date_mandat>"],
      ['key'=>'url_tarifs_publics',  'label'=>'URL barème','type'=>'text',   'scope'=>'both',    'tip'=>"URL publique du barème honoraires (obligation ALUR) → balise <url_tarifs_publics>"],
      ['key'=>'titre',               'label'=>'Titre',     'type'=>'text',   'scope'=>'both',    'tip'=>"Titre de l'annonce → balise <titre>. Obligatoire pour diffusion."],
      ['key'=>'date_modification',   'label'=>'Modifiée',  'type'=>'readonly','scope'=>'both',   'tip'=>"Date de dernière modification"],
    ];

    // Filtrage scope selon filtre transaction
    $visibleCols = array_filter($columns, function($c) use ($fTrans) {
      if ($c['scope'] === 'both') return true;
      if ($fTrans === 'vente') return $c['scope'] === 'vente';
      if (in_array($fTrans, ['location','saisonnier','location_annuelle'], true)) return $c['scope'] === 'location';
      return true; // filtre = Toutes → affiche tout
    });

    // Helpers rendu
    $currentQS = $_GET;
    // ⚠️ <base href="/"> dans agency_layout_top.php casse les liens query-only (?foo=bar).
    // On préfixe par l'URL absolue de la page pour contourner.
    $selfUrl = app_url('/admin/admin_annonces_table.php');
    $renderHeader = function(array $c) use (&$currentQS, $sort, $dir, $selfUrl) {
        $sortable = isset($c['key']) && !in_array($c['type'], ['readonly_calc','fk_bien'], true);
        $tip = htmlspecialchars($c['tip'] ?? '', ENT_QUOTES);
        if (!$sortable) {
            echo '<th title="' . $tip . '">' . htmlspecialchars($c['label']) . '</th>';
            return;
        }
        $nextDir = ($sort === $c['key'] && $dir === 'asc') ? 'desc' : 'asc';
        $qs = $currentQS; $qs['sort'] = $c['key']; $qs['dir'] = $nextDir;
        $url = $selfUrl . '?' . http_build_query($qs);
        $arrow = '';
        if ($sort === $c['key']) $arrow = '<span class="arrow">' . ($dir === 'asc' ? '↑' : '↓') . '</span>';
        echo '<th title="' . $tip . '"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($c['label']) . ' ' . $arrow . '</a></th>';
    };

    $renderCell = function(array $c, array $r, int $annId, string $editBien) {
        $key = $c['key'];
        $type = $c['type'];
        $tip = htmlspecialchars($c['tip'] ?? '', ENT_QUOTES);
        // Cas spéciaux
        if ($type === 'readonly') {
            if ($key === 'id') {
                echo '<td title="' . $tip . '"><a href="' . htmlspecialchars($editBien, ENT_QUOTES) . '" class="at-fk-link">#' . $annId . '</a></td>';
                return;
            }
            if ($key === 'date_modification') {
                echo '<td style="color:#94a3b8; font-size:9px;">' . htmlspecialchars((string)($r[$key] ?? '')) . '</td>';
                return;
            }
            // Default readonly
            echo '<td title="' . $tip . '" style="color:#64748b;">' . htmlspecialchars((string)($r[$key] ?? '—')) . '</td>';
            return;
        }
        if ($type === 'fk_bien') {
            echo '<td><a href="' . htmlspecialchars($editBien, ENT_QUOTES) . '" class="at-fk-link">#' . (int)$r['id_bien']
               . (!empty($r['reference_bien']) ? '<br><span class="at-ref">' . htmlspecialchars((string)$r['reference_bien']) . '</span>' : '')
               . '</a></td>';
            return;
        }
        if ($type === 'readonly_calc') {
            // Calculs spéciaux
            if ($key === 'honoraires_loc_total') {
                $t = (float)($r['honoraires_location_bail'] ?? 0) + (float)($r['honoraires_etat_des_lieux'] ?? 0);
                echo '<td style="font-weight:700; color:#78350f; background:#fef3c7;" title="' . $tip . '">' . ($t > 0 ? number_format($t, 2, '.', '') : '—') . '</td>';
            } else {
                echo '<td>—</td>';
            }
            return;
        }

        $v = $r[$key] ?? null;
        $display = $v;
        if ($type === 'fk-agence') $display = $r['nom_agence'] ?? '—';
        elseif ($type === 'fk-societe') $display = $r['societe_nom'] ?? '—';
        elseif ($type === 'fk-user') $display = $r['user_nom'] ?? '—';
        elseif ($type === 'bool') $display = (int)$v;
        elseif ($display === null || $display === '') $display = '—';

        $dataValue = (string)($v ?? '');
        if ($type === 'fk-agence') $dataValue = (string)(int)$r['id_agence'];
        elseif ($type === 'fk-societe') $dataValue = (string)(int)$r['id_societe'];
        elseif ($type === 'fk-user') $dataValue = (string)(int)$r['id_user'];
        elseif ($type === 'bool') $dataValue = (string)(int)$v;

        $displayHtml = htmlspecialchars((string)$display);
        if ($type === 'bool') {
            $bv = (int)$v;
            $displayHtml = '<span class="at-b ' . ($bv ? 'at-b-on' : 'at-b-off') . '">' . ($bv ? '1' : '0') . '</span>';
        } elseif ($type === 'text' && $key === 'titre') {
            $displayHtml = htmlspecialchars(mb_substr((string)($v ?: '—'), 0, 60));
        }

        echo '<td title="' . $tip . '"><span class="at-cell"'
           . ' data-ann="' . $annId . '"'
           . ' data-field="' . htmlspecialchars($key, ENT_QUOTES) . '"'
           . ' data-type="' . htmlspecialchars($type, ENT_QUOTES) . '"'
           . ' data-value="' . htmlspecialchars($dataValue, ENT_QUOTES) . '">'
           . $displayHtml
           . '</span></td>';
    };
  ?>

  <div class="at-scroll">
  <table class="at-table">
    <thead>
      <tr>
        <?php foreach ($visibleCols as $c) $renderHeader($c); ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $editBien = app_url('/bien_detail.php?edit=' . (int)$r['id_bien'] . '&section=annonce');
        $annId = (int)$r['id'];
      ?>
        <tr>
          <?php foreach ($visibleCols as $c) $renderCell($c, $r, $annId, $editBien); ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
const AT_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
const AT_ENDPOINT = <?= json_encode(app_url('/api/admin_annonce_edit.php'), JSON_UNESCAPED_SLASHES) ?>;
const AT_AGENCES = <?= json_encode(array_map(fn($a) => ['id'=>(int)$a['id'],'lbl'=>$a['nom_agence']], $agences), JSON_UNESCAPED_UNICODE) ?>;
const AT_SOCIETES = <?= json_encode(array_map(fn($s) => ['id'=>(int)$s['id'],'lbl'=>$s['lbl']], $societes), JSON_UNESCAPED_UNICODE) ?>;
const AT_USERS = <?= json_encode(array_map(fn($u) => ['id'=>(int)$u['id'],'lbl'=>$u['lbl']], $users), JSON_UNESCAPED_UNICODE) ?>;

function atBuildEditor(cell) {
  const type  = cell.dataset.type;
  const value = cell.dataset.value || '';
  let el;

  if (type === 'bool') {
    el = document.createElement('select');
    el.innerHTML = '<option value="0">0 (non)</option><option value="1">1 (oui)</option>';
    el.value = value;
  } else if (type === 'fk-agence' || type === 'fk-societe' || type === 'fk-user') {
    el = document.createElement('select');
    const list = type === 'fk-agence' ? AT_AGENCES : (type === 'fk-societe' ? AT_SOCIETES : AT_USERS);
    el.innerHTML = '<option value="0">—</option>' + list.map(o =>
      `<option value="${o.id}" ${String(o.id) === String(value) ? 'selected' : ''}>${o.id} — ${o.lbl || ''}</option>`
    ).join('');
  } else if (type === 'enum-trans') {
    el = document.createElement('select');
    el.innerHTML = ['','vente','location','saisonnier','viager','location_annuelle','cession_bail','fonds_commerce','neuf','vefa']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'enum-statut') {
    el = document.createElement('select');
    el.innerHTML = ['','brouillon','publiee','active','en_ligne','archivee']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'enum-etat') {
    el = document.createElement('select');
    el.innerHTML = ['','brouillon','diffusee','publiee','archivee','archived']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'enum-mandat') {
    el = document.createElement('select');
    el.innerHTML = ['','exclusif','simple']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'decimal') {
    el = document.createElement('input');
    el.type = 'number'; el.step = '0.01'; el.value = value;
  } else {
    el = document.createElement('input');
    el.type = 'text'; el.value = value;
  }
  return el;
}

async function atSave(cell, newVal) {
  cell.classList.add('saving');
  cell.classList.remove('saved','err');
  try {
    const fd = new FormData();
    fd.append('id_annonce', cell.dataset.ann);
    fd.append('field', cell.dataset.field);
    fd.append('value', newVal);
    fd.append('csrf_token', AT_CSRF);
    const r = await fetch(AT_ENDPOINT, { method:'POST', body:fd, credentials:'same-origin' });
    // Protection contre une réponse HTML (session expirée → redirect login, erreur PHP, 404…)
    const ct = r.headers.get('content-type') || '';
    if (!ct.includes('application/json')) {
      const txt = await r.text();
      const snippet = txt.substring(0, 200).replace(/\s+/g, ' ');
      throw new Error('Réponse non-JSON (HTTP ' + r.status + ') — début : ' + snippet);
    }
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Erreur');

    // Met à jour l'affichage + data-value
    cell.dataset.value = j.value !== null && j.value !== undefined ? j.value : '';
    const type = cell.dataset.type;
    if (type === 'bool') {
      cell.innerHTML = '<span class="at-b ' + ((+j.value) ? 'at-b-on' : 'at-b-off') + '">' + (j.value ? '1' : '0') + '</span>';
    } else if (type.startsWith('fk-')) {
      cell.textContent = j.display || (j.value ? '#' + j.value : '—');
    } else {
      cell.textContent = j.value === null || j.value === '' ? '—' : String(j.value);
    }
    cell.classList.remove('saving');
    cell.classList.add('saved');
    setTimeout(() => cell.classList.remove('saved'), 1500);
  } catch (e) {
    cell.classList.remove('saving');
    cell.classList.add('err');
    alert('❌ ' + e.message);
  }
}

// Binding de tous les at-cell
document.querySelectorAll('.at-cell').forEach(cell => {
  cell.addEventListener('click', function(e) {
    if (cell.querySelector('input, select')) return; // déjà en édition
    const currentText = cell.textContent;
    const editor = atBuildEditor(cell);
    cell.innerHTML = '';
    cell.appendChild(editor);
    editor.focus();
    if (editor.tagName === 'INPUT') editor.select();

    const finish = (save) => {
      if (save) {
        const newVal = editor.value;
        if (String(newVal) === String(cell.dataset.value)) {
          cell.innerHTML = currentText;
          return;
        }
        cell.dataset.value = newVal;
        atSave(cell, newVal);
      } else {
        cell.innerHTML = currentText;
      }
    };
    editor.addEventListener('blur', () => finish(true));
    editor.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); editor.blur(); }
      if (ev.key === 'Escape') { finish(false); }
    });
  });
});
</script>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
