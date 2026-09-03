<?php
declare(strict_types=1);
/**
 * ADMIN — LA FILE D'ARBITRAGE. UNE BOÎTE DE RÉCEPTION, PAS UN OUTIL DE DIAGNOSTIC.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CET ÉCRAN EXISTE PARCE QUE LE TEMPS D'EMMANUEL PARTAIT DANS LA RECHERCHE DE LA PREUVE,
 *    PAS DANS LA DÉCISION. Les questions étaient posées, les choix listés — et pour trancher
 *    il fallait ouvrir le PDF, chercher la page, retrouver le compte. Ici, tout ce qu'il faut
 *    pour décider tient sur un écran, et la preuve est à UN CLIC.
 *
 * ⚠️ UN ARBITRAGE À LA FOIS. Une liste de 331 lignes ne se traite pas : elle se contemple.
 *    « Valider et suivant » enchaîne sans recharger la page ni revenir à un index — c'est la
 *    seule façon d'en passer cinquante en quelques minutes.
 *
 * ⚠️ L'AGENT PROPOSE, EMMANUEL DÉCIDE — ET « MA PROPOSITION » EST TOUJOURS OUVERTE. Un écran
 *    qui n'offre que des cases à cocher force la réponse dans les catégories du moteur ; or
 *    c'est précisément quand aucune ne convient qu'on apprend quelque chose.
 *
 * ⚠️ ET RIEN NE SE PROPAGE EN SILENCE. Une décision porte sur CE cas. L'étendre à un ensemble
 *    exige un geste explicite, et l'écran montre d'abord le critère exact et le nombre de
 *    lignes couvertes.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. Décider n'est pas intégrer : tout vit dans `crgi_arbitrage`.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/crgi_arbitrage.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
$csrf = csrf_token('default');
$importId = (int)($_GET['import'] ?? 0) ?: crgi_import_courant($pdo);
$filtreStatut = (string)($_GET['statut'] ?? 'A TRAITER');
$filtreGroupe = (string)($_GET['groupe'] ?? '');
$position = max(0, (int)($_GET['n'] ?? 0));

$imports = $pdo->query(
    'SELECT id, libelle, statut FROM crgi_import ORDER BY (statut = "ANNULE"), id DESC LIMIT 30'
)->fetchAll(PDO::FETCH_ASSOC);

$kpi = crgi_kpi_arbitrage($pdo, $importId);
$toute = crgi_file_arbitrages($pdo, $importId);
$file = crgi_file_arbitrages($pdo, $importId, array_filter([
    'statut' => $filtreStatut !== 'TOUS' ? $filtreStatut : null,
    'groupe' => $filtreGroupe ?: null,
]));
$groupes = [];
foreach ($toute as $a) {
    $groupes[$a['groupe']] = ($groupes[$a['groupe']] ?? 0) + 1;
}
ksort($groupes);

$position = min($position, max(0, count($file) - 1));
$courant = $file[$position] ?? null;
$props = $courant ? crgi_propositions($pdo, $importId, $courant) : [];
$groupeSemblable = $courant ? crgi_groupe_semblable($pdo, $importId, $courant) : null;

$pageTitle = '⚖️ Arbitrages CRG';
$extraCss  = '<link rel="stylesheet" href="' . asset_url('/css/crg_integration.css') . '">';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$euro = fn($v) => number_format((float)$v, 2, ',', ' ') . ' €';
$lien = fn(array $q) => h(app_url('/admin/admin_crgi_arbitrage.php') . '?' . http_build_query(
    array_merge(['import' => $importId, 'statut' => $filtreStatut,
                 'groupe' => $filtreGroupe, 'n' => $position], $q)));
?>
<div class="crgi">

<!-- ═══ LA FILE, EN UN COUP D'ŒIL ═══════════════════════════════════════════════ -->
<div class="crgi-carte">
  <form method="get" class="crgi-diag-barre">
    <label for="import">Import</label>
    <select name="import" id="import" onchange="this.form.submit()">
      <?php foreach ($imports as $im): ?>
        <option value="<?= (int)$im['id'] ?>" <?= (int)$im['id'] === $importId ? 'selected' : '' ?>>
          n°<?= (int)$im['id'] ?> — <?= h($im['libelle'] ?: '(sans libellé)') ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label for="statut">État</label>
    <select name="statut" id="statut" onchange="this.form.submit()">
      <?php foreach (['A TRAITER' => 'à traiter', 'TOUS' => 'tous', 'VALIDE' => 'validés',
                      'REPORTE' => 'reportés', 'INDETERMINABLE' => 'indéterminables'] as $v => $l): ?>
        <option value="<?= h($v) ?>" <?= $filtreStatut === $v ? 'selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="groupe">Phénomène</label>
    <select name="groupe" id="groupe" onchange="this.form.submit()">
      <option value="">tous (<?= count($toute) ?>)</option>
      <?php foreach ($groupes as $g => $n): ?>
        <option value="<?= h($g) ?>" <?= $filtreGroupe === $g ? 'selected' : '' ?>>
          <?= h($g) ?> (<?= (int)$n ?>)</option>
      <?php endforeach; ?>
    </select>
    <a class="crgi-diag-lien" href="<?= h(app_url('/admin/admin_crgi_diag.php')) ?>?import=<?= $importId ?>">
      diagnostic de l’import →</a>
  </form>

  <div class="crgi-diag-chiffres">
    <div><span>CRG</span><b><?= (int)$kpi['crg'] ?></b></div>
    <div><span>Compris seul</span><b><?= $kpi['auto'] ?><i> %</i></b></div>
    <div class="<?= $kpi['a_traiter'] ? 'crgi-diag-alerte' : '' ?>">
      <span>À traiter</span><b><?= (int)$kpi['a_traiter'] ?> <i>/ <?= (int)$kpi['total'] ?></i></b></div>
    <div><span>Validés</span><b><?= (int)$kpi['valides'] ?></b></div>
    <div><span>Reportés</span><b><?= (int)$kpi['reportes'] ?></b></div>
    <div><span>Arbitrages / 100 CRG</span><b><?= $kpi['arb_100crg'] ?></b></div>
    <div><span>Interventions / 100 CRG</span><b><?= $kpi['interv_100crg'] ?></b></div>
    <div><span>Temps moyen</span><b><?= (int)$kpi['sec_moyen'] ?><i> s</i></b></div>
  </div>
</div>

<?php if (!$courant): ?>
  <div class="crgi-carte">
    <p class="crgi-note crgi-diag-ok">Rien à arbitrer avec ce filtre — la file est vide.</p>
  </div>
<?php else:
  $c = $courant['contexte'];
  $estMvt = $courant['cible'] === 'MOUVEMENT';
?>

<!-- ═══ L'ARBITRAGE COURANT ════════════════════════════════════════════════════ -->
<div class="crgi-carte crgi-arb-fiche" id="fiche"
     data-import="<?= $importId ?>"
     data-groupe="<?= h($courant['groupe']) ?>"
     data-cible-type="<?= h($courant['cible']) ?>"
     data-cible-id="<?= (int)$courant['cible_id'] ?>"
     data-preuve-page="<?= (int)($c['page'] ?? 0) ?>"
     data-preuve-pdf="<?= h((string)($c['nom_original'] ?? '')) ?>"
     data-preuve-sha="<?= h((string)($c['sha256'] ?? '')) ?>">

  <div class="crgi-arb-tete">
    <span class="crgi-arb-rang"><?= $position + 1 ?> / <?= count($file) ?></span>
    <span class="crgi-diag-tag"><?= h($courant['groupe']) ?></span>
    <span class="crgi-arb-nav">
      <a href="<?= $lien(['n' => max(0, $position - 1)]) ?>" <?= $position === 0 ? 'aria-disabled="true"' : '' ?>>← précédent</a>
      <a href="<?= $lien(['n' => $position + 1]) ?>">suivant →</a>
    </span>
  </div>

  <h2 class="crgi-arb-question"><?= h($courant['question']) ?></h2>
  <p class="crgi-sous"><?= h($courant['regle']) ?></p>

  <!-- ── LA PREUVE, ET LE BOUTON QUI Y MÈNE ─────────────────────────────────── -->
  <div class="crgi-arb-preuve">
    <div class="crgi-arb-montant">
      <?php if ($estMvt): ?>
        <b><?= $euro($c['montant'] ?? 0) ?></b>
        <span class="crgi-arb-sens crgi-arb-<?= h((string)($c['colonne'] ?? '')) ?>">
          <?= h(strtoupper((string)($c['colonne'] ?? '—'))) ?></span>
      <?php else: ?>
        <b><?= h(mb_substr((string)($c['nom'] ?? $c['lot_reference'] ?? '—'), 0, 42)) ?></b>
      <?php endif; ?>
    </div>
    <div class="crgi-arb-libelle">
      <?= h(mb_substr((string)($c['libelle'] ?? $c['motif'] ?? ''), 0, 160)) ?: '—' ?>
    </div>
    <a class="crgi-arb-voir"
       href="<?= h(app_url('/admin/crgi_page.php')) ?>?crg=<?= (int)($c['crg_id'] ?? 0) ?>#page=<?= (int)($c['page'] ?? 1) ?>"
       target="_blank" rel="noopener">📄 Voir dans le CRG — page <?= (int)($c['page'] ?? 0) ?></a>
  </div>

  <!-- ── LE CONTEXTE : juste ce qu'il faut pour décider ──────────────────────── -->
  <dl class="crgi-arb-ctx">
    <?php
    $ctxAff = [
      'agence'       => ($c['agence'] ?? '') . ' · ' . ($c['format'] ?? ''),
      'propriétaire' => $c['proprietaire'] ?? '',
      'compte'       => $c['compte'] ?? '',
      'période'      => ($c['periode_cle'] ?? '') . ' · arrêté ' . ($c['date_arrete'] ?? ''),
      'immeuble'     => $c['immeuble'] ?? $c['nom'] ?? '',
      'lot'          => $c['lot_reference'] ?? '',
      'locataire'    => $c['locataire'] ?? $c['precedent'] ?? '',
      'section'      => $c['section'] ?? '',
      'maille'       => $c['maille'] ?? '',
      'document'     => $c['nom_original'] ?? '',
    ];
    foreach ($ctxAff as $k => $v):
      if (trim((string)$v, ' ·') === '') { continue; } ?>
      <dt><?= h($k) ?></dt><dd><?= h(mb_substr(trim((string)$v, ' ·'), 0, 70)) ?></dd>
    <?php endforeach; ?>
  </dl>

  <!-- ── CE QUE L'AGENT PROPOSE ─────────────────────────────────────────────── -->
  <h3 class="crgi-arb-h3">Ce que MBI propose</h3>
  <div class="crgi-arb-props">
    <?php foreach ($props as $k => $p): ?>
      <label class="crgi-arb-prop">
        <input type="radio" name="choix" value="<?= h($p['choix']) ?>"
               data-confiance="<?= (int)$p['confiance'] ?>"
               data-agent="<?= $k === 0 ? '1' : '0' ?>">
        <span class="crgi-arb-jauge"><i style="width:<?= (int)$p['confiance'] ?>%"></i>
          <b><?= (int)$p['confiance'] ?> %</b></span>
        <span class="crgi-arb-prop-txt"><b><?= h($p['choix']) ?></b><br>
          <span class="crgi-diag-ctx"><?= h($p['raison']) ?></span></span>
      </label>
    <?php endforeach; ?>

    <?php
    // Les choix que la RÈGLE offre, s'ils ne sont pas déjà instanciés par une proposition.
    // Une piste nommée (« l'immeuble MBI #563 ») remplace l'option générique dont elle est
    // l'instance : les réafficher côte à côte rendrait à nouveau possible une décision floue.
    $couverts = array_merge(array_column($props, 'choix'),
                            array_filter(array_column($props, 'regle')));
    foreach (array_keys($courant['choix']) as $ch): ?>
      <?php if (in_array($ch, $couverts, true)) { continue; } ?>
      <label class="crgi-arb-prop crgi-arb-prop-regle">
        <input type="radio" name="choix" value="<?= h($ch) ?>" data-confiance="" data-agent="0">
        <span class="crgi-arb-jauge crgi-arb-jauge-vide">règle</span>
        <span class="crgi-arb-prop-txt"><b><?= h($ch) ?></b><br>
          <span class="crgi-diag-ctx"><?= h($courant['choix'][$ch]) ?></span></span>
      </label>
    <?php endforeach; ?>

    <!-- ⚠️ TOUJOURS OUVERTE. C'est quand aucune case ne convient qu'on apprend. -->
    <label class="crgi-arb-prop crgi-arb-prop-libre">
      <input type="radio" name="choix" value="__LIBRE__" id="choixLibre" data-agent="0">
      <span class="crgi-arb-jauge crgi-arb-jauge-vide">à moi</span>
      <span class="crgi-arb-prop-txt"><b>Ma proposition</b><br>
        <input type="text" id="maProposition" class="crgi-arb-libre"
               placeholder="ex. indemnité d’assurance liée au sinistre du lot, à rattacher au sinistre"
               maxlength="120"></span>
    </label>
  </div>

  <label class="crgi-arb-precision">
    Précision (conservée intégralement, quelle que soit la décision)
    <textarea id="precision" rows="2" maxlength="1000"
              placeholder="ce que vous voulez que MBI retienne de ce cas"></textarea>
  </label>

  <!-- ── L'EXTENSION À UN GROUPE, JAMAIS IMPLICITE ──────────────────────────── -->
  <?php if ($groupeSemblable): ?>
    <div class="crgi-arb-groupe">
      <label>
        <input type="checkbox" id="appliquerGroupe">
        <b>Appliquer cette décision aux <?= (int)$groupeSemblable['nombre'] ?> lignes
           qui relèvent exactement du même phénomène</b>
      </label>
      <p class="crgi-diag-ctx">
        Critère : <?= h($groupeSemblable['critere']) ?> ·
        <?= h($groupeSemblable['pages']) ?> ·
        total <?= $euro($groupeSemblable['total']) ?>.
        Chaque décision produite porte ce critère et son nombre de lignes : rien ne se
        propage sans laisser sa trace.
      </p>
    </div>
  <?php endif; ?>

  <!-- ── LES ACTIONS ────────────────────────────────────────────────────────── -->
  <div class="crgi-arb-actions">
    <button type="button" id="btnSuivant" class="crgi-b or">Valider et suivant →</button>
    <button type="button" id="btnValider" class="crgi-b creux">Valider</button>
    <button type="button" id="btnIndet" class="crgi-b creux">Indéterminable</button>
    <button type="button" id="btnReporter" class="crgi-b creux">Reporter</button>
    <span id="etatArb" class="crgi-arb-etat"></span>
  </div>
  <p class="crgi-note">
    <?= h($courant['impact']) ?>
    <?php if (!empty($courant['prise'])): ?>
      <br><b>Déjà décidé</b> : « <?= h((string)$courant['prise']['choix']) ?> »
      (<?= h((string)$courant['prise']['statut']) ?>,
       portée <?= h((string)$courant['prise']['portee']) ?>,
       le <?= h((string)$courant['prise']['decide_le']) ?>).
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

</div>

<script>
(function () {
  const fiche = document.getElementById('fiche');
  if (!fiche) return;
  const API  = <?= json_encode(app_url('/api/crg_integration_action.php')) ?>;
  const CSRF = <?= json_encode($csrf) ?>;
  const PAGE = <?= json_encode(app_url('/admin/admin_crgi_arbitrage.php')) ?>;
  const IMPORT = fiche.dataset.import;
  const STATUT = <?= json_encode($filtreStatut) ?>;
  const GROUPE = <?= json_encode($filtreGroupe) ?>;
  const N = <?= (int)$position ?>;
  // ⚠️ LE TEMPS HUMAIN SE MESURE, IL NE S'ESTIME PAS. C'est le KPI qu'Emmanuel ressent.
  const T0 = Date.now();

  const etat = document.getElementById('etatArb');
  const libre = document.getElementById('choixLibre');
  const maProp = document.getElementById('maProposition');
  maProp?.addEventListener('input', () => { if (libre) libre.checked = true; });

  function choisi() {
    const r = fiche.querySelector('input[name="choix"]:checked');
    if (!r) return null;
    if (r.value === '__LIBRE__') {
      const v = (maProp?.value || '').trim();
      return v ? {choix: v, agent: 0, confiance: ''} : null;
    }
    return {choix: r.value, agent: r.dataset.agent === '1' ? 1 : 0,
            confiance: r.dataset.confiance || ''};
  }

  async function decider(statut, suivant) {
    const c = choisi();
    if (statut === 'VALIDE' && !c) {
      etat.textContent = 'Choisissez une proposition, ou écrivez la vôtre.';
      return;
    }
    const premier = fiche.querySelector('input[name="choix"][data-agent="1"]');
    const d = new FormData();
    d.append('csrf_token', CSRF);
    d.append('action', 'decider');
    d.append('import_id', IMPORT);
    d.append('groupe', fiche.dataset.groupe);
    d.append('cible_type', fiche.dataset.cibleType);
    d.append('cible_id', fiche.dataset.cibleId);
    d.append('choix', c ? c.choix : '');
    d.append('statut', statut);
    d.append('portee', document.getElementById('appliquerGroupe')?.checked ? 'GROUPE' : 'CAS');
    d.append('precision', document.getElementById('precision')?.value || '');
    d.append('agent_proposition', premier ? premier.value : '');
    d.append('agent_confiance', premier ? (premier.dataset.confiance || '') : '');
    if (c && c.agent) d.append('agent_suivi', '1');
    d.append('preuve_page', fiche.dataset.preuvePage);
    d.append('preuve_pdf', fiche.dataset.preuvePdf);
    d.append('preuve_sha', fiche.dataset.preuveSha);
    d.append('secondes', Math.round((Date.now() - T0) / 1000));
    <?php if ($groupeSemblable): ?>
    if (document.getElementById('appliquerGroupe')?.checked) {
      d.append('groupe_appliquer', '1');
      d.append('groupe_critere', <?= json_encode($groupeSemblable['critere']) ?>);
      d.append('g_section', <?= json_encode($groupeSemblable['sql']['section']) ?>);
      d.append('g_colonne', <?= json_encode($groupeSemblable['sql']['colonne']) ?>);
      d.append('g_maille',  <?= json_encode($groupeSemblable['sql']['maille']) ?>);
    }
    <?php endif; ?>

    etat.textContent = 'enregistrement…';
    try {
      const r = await fetch(API, {method: 'POST', body: d});
      const j = await r.json();
      if (!j.ok) throw new Error(j.erreur || 'Erreur inconnue.');
      etat.textContent = j.faits > 1
        ? j.faits + ' décisions enregistrées · ' + j.reste + ' restent'
        : 'enregistré · ' + j.reste + ' restent';
      if (suivant) {
        // ⚠️ ON NE REVIENT PAS À UNE LISTE. Le filtre « à traiter » fait remonter le suivant
        //    à la même position : c'est ce qui permet d'en passer cinquante d'affilée.
        const q = new URLSearchParams({import: IMPORT, statut: STATUT, groupe: GROUPE,
                                       n: STATUT === 'A TRAITER' ? N : N + 1});
        location.href = PAGE + '?' + q.toString();
      }
    } catch (e) { etat.textContent = 'ÉCHEC : ' + e.message; }
  }

  document.getElementById('btnSuivant').addEventListener('click', () => decider('VALIDE', true));
  document.getElementById('btnValider').addEventListener('click', () => decider('VALIDE', false));
  document.getElementById('btnIndet').addEventListener('click', () => decider('INDETERMINABLE', true));
  document.getElementById('btnReporter').addEventListener('click', () => decider('REPORTE', true));
})();
</script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
