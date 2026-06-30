<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/honoraires_helper.php';
require_login();

$pdo = db();
$idSociete = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : 0;
$idAgence  = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : 0;

// Filtre société : par défaut la société de l'user (sinon toutes via ?societe_id=0)
$fSoc = isset($_GET['societe_id']) ? (int)$_GET['societe_id'] : $idSociete;

// Liste des sociétés (pour le sélecteur)
$societesOpt = [];
try { $societesOpt = $pdo->query("SELECT id, nom FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}

// Colonnes = AGENCES (chaque agence hérite du barème de sa société), filtrées par société.
$baremes = [];
try {
    // Barème de CHAQUE agence : sa ligne propre (id_agence=a.id) sinon le modèle société (id_agence=0)
    $sql = "SELECT a.id AS agence_id, a.nom_agence, a.ville, a.id_societe,
                   s.nom AS societe_nom, sh.*
            FROM agences a
            JOIN societes s ON s.id = a.id_societe
            LEFT JOIN societe_honoraires sh ON sh.id = (
                SELECT x.id FROM societe_honoraires x
                WHERE x.id_societe = a.id_societe AND x.id_agence IN (a.id, 0)
                ORDER BY (x.id_agence = a.id) DESC LIMIT 1
            )";
    if ($fSoc > 0) $sql .= " WHERE a.id_societe = " . $fSoc;
    $sql .= " ORDER BY (a.id = " . $idAgence . ") DESC, s.nom, a.nom_agence";
    $baremes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Pré-migration (pas de colonne id_agence) → JOIN société simple
    try {
        $sql2 = "SELECT a.id AS agence_id, a.nom_agence, a.ville, a.id_societe, s.nom AS societe_nom, sh.*
                 FROM agences a JOIN societes s ON s.id = a.id_societe
                 LEFT JOIN societe_honoraires sh ON sh.id_societe = a.id_societe";
        if ($fSoc > 0) $sql2 .= " WHERE a.id_societe = " . $fSoc;
        $sql2 .= " ORDER BY (a.id = " . $idAgence . ") DESC, s.nom, a.nom_agence";
        $baremes = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {}
}

// Tarifs LOCATION par zone (source : societe_tarifs_honoraires via helper) pour chaque colonne.
$zonesComp = ['non_tendue'=>'Non tendue','tendue'=>'Tendue','tres_tendue'=>'Très tendue'];
$zoneByCol = [];
foreach ($baremes as $i => $b) {
    foreach ($zonesComp as $zk => $zl) {
        $t = function_exists('tarifs_honoraires_get') ? tarifs_honoraires_get($pdo, (int)$b['id_societe'], $zk, (int)$b['agence_id']) : ['location_bail'=>null,'edl'=>null];
        $zoneByCol[$i][$zk] = $t;
    }
}
// Gestion : tranches décodées par colonne + méthode
$gestTrByCol = [];
$maxGestTr = 0;
foreach ($baremes as $i => $b) {
    $gt = json_decode((string)($b['gestion_tranches_json'] ?? ''), true) ?: [];
    $gestTrByCol[$i] = $gt;
    $maxGestTr = max($maxGestTr, count($gt));
}

// Formatteurs
$eur = static fn($v) => ($v === null || $v === '') ? '—' : number_format((float)$v, 0, ',', ' ') . ' €';
$pct = static fn($v) => ($v === null || $v === '') ? '—' : rtrim(rtrim(number_format((float)$v, 2, ',', ' '), '0'), ',') . ' %';
$venteResume = static function (array $b) use ($eur, $pct): string {
    $m = (string)($b['vente_methode'] ?? '');
    if ($m === 'taux_unique' || $m === 'pct_unique') return $pct($b['vente_taux_unique'] ?? null);
    if ($m === 'forfait')     return $eur($b['vente_forfait'] ?? null);
    if ($m === 'tranches') {
        $tr = json_decode((string)($b['vente_tranches_json'] ?? ''), true) ?: [];
        if (!$tr) return 'Par tranches';
        $parts = [];
        foreach ($tr as $t) {
            $min = ($t['min'] ?? null);
            $max = ($t['max'] ?? null);
            $p   = ($t['pct'] ?? null);
            $minL = ($min !== null && $min !== '') ? number_format((float)$min, 0, ',', ' ') : '0';
            $maxL = ($max !== null && $max !== '') ? number_format((float)$max, 0, ',', ' ') : '∞';
            $pL   = ($p !== null && $p !== '') ? rtrim(rtrim(number_format((float)$p, 2, ',', ' '), '0'), ',') . ' %' : '—';
            $parts[] = $minL . '→' . $maxL . ' : ' . $pL;
        }
        return implode(' · ', $parts);
    }
    return '—';
};

$txt = static fn($v) => ($v === null || $v === '') ? '—' : ucfirst(str_replace('_', ' ', (string)$v));
$fmt = static function (string $type, $v) use ($eur, $pct, $txt) {
    return match ($type) { 'eur' => $eur($v), 'pct' => $pct($v), default => $txt($v) };
};
// Comparaison COMPLÈTE, groupée par section : [titre => [ [label, field, type], ... ]]
$sections = [
    '🏷️ Vente' => [
        ['Méthode',                 'vente_methode',          'txt'],
        ['Taux unique',             'vente_taux_unique',      'pct'],
        ['Forfait',                 'vente_forfait',          'eur'],
        ['Montant minimum',         'vente_montant_minimum',  'eur'],
        ['Charge par défaut',       'vente_charge_par_defaut','txt'],
    ],
    '🔑 Location' => [
        // (les honoraires locataire €/m² par zone sont injectés dynamiquement avant ces lignes)
        ['Honoraires bailleur (%)',       'location_honoraires_bailleur_pct',       'pct'],
        ['Honoraires bailleur (forfait)', 'location_honoraires_bailleur_forfait',   'eur'],
    ],
    '🏠 Gestion locative' => [
        // (méthode % ou tranches injectée dynamiquement avant ces lignes)
        ['Frais entrée locataire',      'gestion_frais_entree_locataire',       'eur'],
        ['Frais sortie locataire',      'gestion_frais_sortie_locataire',       'eur'],
        ['Renouvellement bail',         'gestion_renouvellement_bail',          'eur'],
        ['Avenant bail',                'gestion_avenant_bail',                 'eur'],
        ['Suivi travaux (%)',           'gestion_suivi_travaux_pct',            'pct'],
        ['Quittance supplémentaire',    'gestion_quittance_supplementaire',     'eur'],
        ['Assurance loyers impayés (%)','gestion_assurance_loyers_impayes_pct', 'pct'],
        ['Carence locative (%)',        'gestion_carence_locative_pct',         'pct'],
    ],
    '🏛️ Syndic de copropriété' => [
        ['Forfait annuel / lot',          'syndic_forfait_annuel_lot',          'eur'],
        ['Plancher annuel total',         'syndic_forfait_min',                 'eur'],
        ['Rémunération de base / an',     'syndic_remuneration_base',           'eur'],
        ['Visite immeuble supplément.',   'syndic_visite_immeuble',             'eur'],
        ['AG supplémentaire',             'syndic_assemblee_supplementaire',    'eur'],
        ['État daté pré-vente',           'syndic_etat_date_pre',               'eur'],
        ['Mise en concurrence travaux',   'syndic_mise_en_concurrence',         'eur'],
        ['Recouvrement simple',           'syndic_recouvrement_simple',         'eur'],
        ['Recouvrement contentieux (%)',  'syndic_recouvrement_contentieux_pct','pct'],
        ['Archivage (%)',                 'syndic_archivage_pct',               'pct'],
    ],
    '🔎 Mandat de recherche' => [
        ['Honoraires (%)',      'mandat_recherche_pct',     'pct'],
        ['Honoraires (forfait)','mandat_recherche_forfait', 'eur'],
    ],
];

// Tranches de vente décodées par colonne (1 ligne par tranche dans le comparatif)
$tranchesByCol = [];
$maxTranches = 0;
foreach ($baremes as $i => $b) {
    $tr = json_decode((string)($b['vente_tranches_json'] ?? ''), true) ?: [];
    $tranchesByCol[$i] = $tr;
    $maxTranches = max($maxTranches, count($tr));
}
// Affichage compact : on n'indique QUE la fin de tranche → « → 60 000 € : 8 % » (dernière = « → ∞ »).
$fmtTranche = static function (?array $t) use ($pct): string {
    if (!$t) return '—';
    $max = ($t['max'] ?? null); $p = ($t['pct'] ?? null);
    $maxL = ($max !== null && $max !== '') ? number_format((float)$max, 0, ',', ' ') . ' €' : '∞';
    return '→ ' . $maxL . ' : ' . $pct($p);
};

ob_start();
?>
<style>
.hcmp-wrap { padding:24px 28px 80px; max-width:1200px; }
.hcmp-head { display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px; }
.hcmp-head h1 { font-size:20px;margin:0;color:#1a1816; }
.hcmp-head .sub { font-size:12px;color:#888;margin-top:3px; }
.hcmp-edit { background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:700;font-size:13px;box-shadow:0 4px 12px rgba(249,115,22,.3); }
.hcmp-card { background:#fff;border-radius:14px;padding:8px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow-x:auto; }
.hcmp-table { width:100%;border-collapse:collapse;font-size:13px; }
.hcmp-table th, .hcmp-table td { padding:11px 14px;text-align:left;border-bottom:1px solid #f0ece6; }
.hcmp-table td { white-space:normal; min-width:150px; vertical-align:top; }
.hcmp-table th { white-space:nowrap; }
.hcmp-table thead th { position:sticky;top:0;background:#faf8f4;font-size:12px;color:#5a5650; }
.hcmp-table td:first-child, .hcmp-table th:first-child { position:sticky;left:0;background:#fff;font-weight:600;color:#3a3830;border-right:1px solid #eee;z-index:1; }
.hcmp-table thead th:first-child { background:#faf8f4;z-index:2; }
.hcmp-own { background:#eef6f1 !important; }
.hcmp-own-h { background:#2d8659 !important;color:#fff !important; }
.hcmp-own-h a { color:#fff;text-decoration:underline; }
.hcmp-badge { font-size:10px;font-weight:700;background:rgba(255,255,255,.25);padding:2px 7px;border-radius:99px;margin-left:6px; }
</style>

<div class="hcmp-wrap">
    <div style="background:#1f6f7a;color:#fff;font-weight:700;text-align:center;padding:10px 16px;border-radius:10px;margin-bottom:16px;font-size:14px;">
      💶 Tous les tarifs comparés sont exprimés en <strong>prix TTC</strong>.
    </div>
    <div class="hcmp-head">
      <div>
        <h1>📊 Comparatif des barèmes par agence</h1>
        <div class="sub">Sélectionnez une société pour comparer toutes ses agences. Votre agence est mise en avant ; les autres sont en lecture seule.</div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <form method="get" style="margin:0;">
          <select name="societe_id" onchange="this.form.submit()" style="padding:9px 12px;border:1px solid #d4d0ca;border-radius:8px;font-size:13px;">
            <option value="0" <?= $fSoc===0?'selected':'' ?>>Toutes les sociétés</option>
            <?php foreach ($societesOpt as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $fSoc===(int)$s['id']?'selected':'' ?>><?= h($s['nom'] ?: '#'.$s['id']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <a class="hcmp-edit" href="<?= h(app_url('/agency_honoraires_config.php')) ?>">✏️ Modifier mon barème</a>
      </div>
    </div>

    <?php if (empty($baremes)): ?>
      <div class="hcmp-card" style="padding:30px;text-align:center;color:#888;">Aucun barème enregistré pour l'instant.</div>
    <?php else: ?>
      <div class="hcmp-card">
        <table class="hcmp-table">
          <thead>
            <tr>
              <th>Critère</th>
              <?php foreach ($baremes as $b): $own = ((int)$b['agence_id'] === $idAgence); ?>
                <th class="<?= $own ? 'hcmp-own-h' : '' ?>">
                  <?= h(ucwords(mb_strtolower((string)($b['ville'] ?: $b['nom_agence'] ?: ('Agence #' . (int)$b['agence_id']))))) ?>
                  <div style="font-size:10px;font-weight:400;opacity:.75;"><?= h($b['societe_nom'] ?: '') ?></div>
                  <?php if ($own): ?><span class="hcmp-badge">vous</span><?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sections as $titre => $items): ?>
              <tr><td colspan="<?= count($baremes) + 1 ?>" style="background:#f4f1ec;font-weight:700;color:#5a5650;"><?= h($titre) ?></td></tr>

              <?php /* Location : honoraires locataire €/m² par zone (source societe_tarifs_honoraires) */
              if (str_contains($titre, 'Location')):
                $eurM2 = static fn($v) => ($v === null || $v === '') ? '—' : number_format((float)$v, 2, ',', ' ') . ' €/m²';
                foreach ($zonesComp as $zk => $zl): ?>
                <tr>
                  <td>Hon. locataire /m² — <?= h($zl) ?></td>
                  <?php foreach ($baremes as $i => $b): $own = ((int)$b['agence_id'] === $idAgence); ?>
                    <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h($eurM2($zoneByCol[$i][$zk]['location_bail'] ?? null)) ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <tr>
                  <td>État des lieux /m²</td>
                  <?php foreach ($baremes as $i => $b): $own = ((int)$b['agence_id'] === $idAgence); ?>
                    <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h($eurM2($zoneByCol[$i]['non_tendue']['edl'] ?? null)) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endif; ?>

              <?php /* Gestion : méthode + (tranches OU % unique) */
              if (str_contains($titre, 'Gestion')): ?>
                <tr>
                  <td>Méthode</td>
                  <?php foreach ($baremes as $b): $own = ((int)$b['agence_id'] === $idAgence);
                    $gm = ((string)($b['gestion_methode'] ?? 'pct') === 'tranches') ? 'Par tranches' : 'Taux unique'; ?>
                    <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h($gm) ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php if ($maxGestTr > 0): for ($gi = 0; $gi < $maxGestTr; $gi++): ?>
                <tr>
                  <td>↳ Tranche loyer <?= $gi + 1 ?></td>
                  <?php foreach ($baremes as $i => $b): $own = ((int)$b['agence_id'] === $idAgence);
                    $gt = $gestTrByCol[$i][$gi] ?? null;
                    if ($gt) { $mx=($gt['max']??null); $p=($gt['pct']??null);
                      $cell = '→ '.(($mx!==null&&$mx!=='')?number_format((float)$mx,0,',',' ').' €':'∞').' : '.$pct($p);
                    } else { $cell = '—'; } ?>
                    <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h($cell) ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endfor; else: ?>
                <tr>
                  <td>% du loyer (taux unique)</td>
                  <?php foreach ($baremes as $b): $own = ((int)$b['agence_id'] === $idAgence); ?>
                    <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h($pct($b['gestion_pct_loyer'] ?? null)) ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endif; ?>
              <?php endif; ?>

              <?php foreach ($items as [$label, $field, $type]): ?>
              <tr>
                <td><?= h($label) ?></td>
                <?php foreach ($baremes as $b): $own = ((int)$b['agence_id'] === $idAgence);
                    $val = $fmt($type, $b[$field] ?? null);
                ?>
                  <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h((string)$val) ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
              <?php /* Vente : une ligne par tranche dégressive */
              if (str_contains($titre, 'Vente') && $maxTranches > 0):
                for ($ti = 0; $ti < $maxTranches; $ti++): ?>
                <tr>
                  <td>↳ Tranche <?= $ti + 1 ?></td>
                  <?php foreach ($baremes as $i => $b): $own = ((int)$b['agence_id'] === $idAgence); ?>
                    <td class="<?= $own ? 'hcmp-own' : '' ?>"><?= h($fmtTranche($tranchesByCol[$i][$ti] ?? null)) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endfor; endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="font-size:12px;color:#888;margin-top:12px;">💡 Pour ajuster votre barème, cliquez sur <strong>« Modifier mon barème »</strong> — vos changements seront immédiatement reflétés dans ce comparatif.</p>
    <?php endif; ?>
  </div>
<?php
$layout_content = ob_get_clean();
$layout_sidebar = 'sidebar_net';
$layout_title   = 'Comparatif des barèmes';
$layout_hide_page_head = true;
require __DIR__ . '/inc/layout_maboximmo.php';
