<?php
declare(strict_types=1);
/**
 * QUALITÉ DES DONNÉES — CE QUE MBI SE CONTREDIT À LUI-MÊME.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CETTE PAGE EXISTE PARCE QUE L'INTÉGRATION CRG ACCUSAIT LE MAUVAIS COUPABLE. Sur un dépôt,
 *    17 des 18 « immeubles homonymes » n'opposaient pas deux bâtiments : ils opposaient un
 *    bâtiment à ses propres copies dans MBI. Le compte rendu était parfaitement compris. Tant
 *    que ces questions remontaient dans la file d'arbitrage, elles gonflaient le travail
 *    humain ET faisaient baisser le taux de compréhension du lecteur pour une faute qui
 *    n'était pas la sienne.
 *
 * ⚠️ LECTURE SEULE VIS-À-VIS DU MÉTIER. Cette page ne modifie JAMAIS `immeubles`, `biens`,
 *    `bien_baux`, `tiers` ni `proprietaires`. Elle écrit uniquement dans `crgq_decision` —
 *    ce qu'Emmanuel a jugé — parce qu'une page sans mémoire redécouvre les mêmes anomalies à
 *    chaque ouverture et fait refaire le même examen indéfiniment.
 *    ÉCRITURE TECHNIQUE ≠ ÉCRITURE MÉTIER.
 *
 * ⚠️ ELLE NE CONNAÎT AUCUNE FAMILLE D'ANOMALIE. Tout ce qu'elle affiche vient du catalogue de
 *    détecteurs. Déclarer une famille nouvelle demain la fera apparaître ici sans qu'une
 *    ligne de cette page ne bouge — c'est la condition pour que l'écran ne devienne pas le
 *    goulot de la connaissance métier.
 *
 * ⚠️ CHAQUE NOMBRE EST OUVRABLE JUSQU'À SA PREUVE. « 27 » sans les 27 identifiants n'est pas
 *    opposable : personne ne devrait croire un compteur qu'il ne peut pas déplier.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/crg_integration.php';
require_once __DIR__ . '/../inc/crgq_qualite.php';
require_admin_or_super_admin();

$pdo  = $GLOBALS['pdo'];
$csrf = csrf_token('default');
$user = (int)($_SESSION['user_id'] ?? 0);
$message = null;

// ── UNE DÉCISION HUMAINE — dans le journal technique, jamais dans la donnée métier ────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('default');
    try {
        crgq_decider($pdo, (string)($_POST['empreinte'] ?? ''),
                     (string)($_POST['decision'] ?? ''),
                     trim((string)($_POST['commentaire'] ?? '')) ?: null, $user);
        $message = ['ok', 'Décision enregistrée dans le journal. Aucune donnée métier n’a été '
                        . 'touchée.'];
    } catch (Throwable $e) {
        $message = ['ko', $e->getMessage()];
    }
}

// ⚠️ ON RELANCE LA DÉTECTION À CHAQUE OUVERTURE, MAIS ON N'OUBLIE RIEN. Le registre garde ce
//    qu'il a déjà vu : une anomalie détectée hier et revue aujourd'hui n'est pas « nouvelle »,
//    et une anomalie disparue reste au registre avec sa date de dernière vue — c'est la seule
//    preuve qu'elle a été corrigée.
$passe = crgq_detecter($pdo);
$registre = crgq_registre($pdo, $passe['debut']);
$synthese = crgq_synthese($registre);

$ouvert = (string)($_GET['detecteur'] ?? '');
$etatFiltre = (string)($_GET['etat'] ?? '');

$occurrences = array_values(array_filter($registre, fn($o) =>
    (!$ouvert || (string)$o['detecteur'] === $ouvert)
    && (!$etatFiltre || (string)$o['etat'] === $etatFiltre)));

// Les trois familles ne se mélangent jamais — ni ici, ni dans les KPI, ni dans le score.
$parFamille = [];
foreach ($synthese as $s) {
    $parFamille[$s['famille']][] = $s;
}
ksort($parFamille);

$pageTitle = '🩺 Qualité des données';
$extraCss  = '<link rel="stylesheet" href="' . asset_url('/css/qualite_donnees.css') . '">';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$lien = fn(array $q) => h(app_url('/admin/admin_qualite_donnees.php')
                        . ($q ? '?' . http_build_query($q) : ''));
$nb = fn($n) => number_format((int)$n, 0, ',', ' ');
?>
<div class="qd">

<?php if ($message): ?>
  <p class="qd-flash qd-<?= h($message[0]) ?>"><?= h($message[1]) ?></p>
<?php endif; ?>

<!-- ═══ CE QUE CETTE PAGE EST, ET CE QU'ELLE N'EST PAS ═══════════════════════════ -->
<p class="qd-intro">
  Ce que l’intégration des comptes rendus a rencontré <b>dans MBI</b>, et non dans les
  documents. Un compte rendu parfaitement lu peut rester impossible à rattacher parce que la
  base porte trois fois le même immeuble : <b>ce n’est pas la même question, et ce n’est pas
  le même coupable</b>.
  <br><b>Cette page ne modifie aucune donnée métier.</b> Elle enregistre ce que vous décidez,
  pour ne plus vous reposer la question.
</p>

<!-- ═══ LES FAMILLES ════════════════════════════════════════════════════════════ -->
<?php foreach ($parFamille as $famille => $lignes): ?>
  <h2 class="qd-famille"><?= h($famille) ?></h2>
  <table class="qd-table">
    <thead><tr>
      <th>Anomalie</th><th>Certitude</th><th class="qd-n">Occurrences</th>
      <th class="qd-n">Objets</th><th>État</th><th>Ce que ça coûte</th><th>Correction proposée</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lignes as $s):
        $cat = crgq_detecteurs()[$s['detecteur']] ?? []; ?>
      <tr class="<?= $ouvert === $s['detecteur'] ? 'qd-ouvert' : '' ?>">
        <td>
          <a href="<?= $lien(['detecteur' => $s['detecteur']]) ?>"><b><?= h($s['libelle']) ?></b></a>
          <div class="qd-desc"><?= h((string)($cat['description'] ?? '')) ?></div>
          <div class="qd-code"><?= h($s['detecteur']) ?> · v<?= h((string)($cat['version'] ?? '?')) ?></div>
        </td>
        <td><span class="qd-cert qd-cert-<?= h(str_replace(' ', '-', strtolower($s['certitude']))) ?>">
            <?= h($s['certitude']) ?></span></td>
        <!-- ⚠️ CHAQUE NOMBRE EST UN LIEN : un compteur qu'on ne peut pas ouvrir n'est pas
             opposable. -->
        <td class="qd-n"><a href="<?= $lien(['detecteur' => $s['detecteur']]) ?>"><?= $nb($s['total']) ?></a></td>
        <td class="qd-n"><?= $nb($s['objets']) ?></td>
        <td class="qd-etats">
          <?php foreach ($s['etats'] as $etat => $n): ?>
            <a href="<?= $lien(['detecteur' => $s['detecteur'], 'etat' => $etat]) ?>"
               class="qd-etat qd-etat-<?= h(str_replace(' ', '-', strtolower($etat))) ?>">
              <?= h($etat) ?> <b><?= $nb($n) ?></b></a>
          <?php endforeach; ?>
        </td>
        <td class="qd-txt"><?= h((string)($cat['impact'] ?? '')) ?></td>
        <td class="qd-txt"><?= h((string)($cat['correction'] ?? '')) ?>
          <div class="qd-risque"><b>Risque :</b> <?= h((string)($cat['risque'] ?? '')) ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>

<!-- ═══ LE DÉPLIEMENT : LA PREUVE, LIGNE À LIGNE ════════════════════════════════ -->
<?php if ($ouvert): $cat = crgq_detecteurs()[$ouvert] ?? []; ?>
  <div class="qd-carte">
    <h2><?= h((string)($cat['libelle'] ?? $ouvert)) ?>
      <span class="qd-code"><?= h($ouvert) ?> · périmètre : <?= h((string)($cat['perimetre'] ?? '')) ?></span></h2>
    <p class="qd-note">
      <?= $nb(count($occurrences)) ?> occurrence(s) affichée(s)<?= $etatFiltre ? ' — état « ' . h($etatFiltre) . ' »' : '' ?>.
      <a href="<?= $lien(['detecteur' => $ouvert]) ?>">tout voir</a> ·
      <a href="<?= $lien([]) ?>">refermer</a>
      <br><b>Chaque ligne porte les identifiants exacts qui l’ont produite</b> : c’est ce qui
      rend le compteur ci-dessus opposable.
    </p>

    <?php foreach ($occurrences as $o): ?>
      <div class="qd-occ">
        <div class="qd-occ-tete">
          <span class="qd-etat qd-etat-<?= h(str_replace(' ', '-', strtolower((string)$o['etat']))) ?>">
            <?= h((string)$o['etat']) ?></span>
          <span class="qd-occ-motif"><?= h((string)$o['motif']) ?></span>
          <span class="qd-occ-vues">vue <?= $nb($o['vues']) ?> fois · depuis le
            <?= h(substr((string)$o['vue_le_premier'], 0, 10)) ?></span>
        </div>
        <div class="qd-preuve"><?= h((string)$o['preuve']) ?></div>
        <div class="qd-ids"><b><?= h((string)$o['objet_type']) ?></b> concernés :
          <code><?= h((string)$o['objet_ids'] ?: '—') ?></code></div>

        <!-- ⚠️ DÉCIDER N'EST PAS CORRIGER. Ce formulaire écrit dans le journal technique ; il
             ne touche aucune table métier. -->
        <form method="post" class="qd-decision">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="empreinte" value="<?= h((string)$o['empreinte']) ?>">
          <select name="decision" required>
            <option value="">— votre conclusion —</option>
            <?php foreach (CRGQ_DECISIONS as $d): ?>
              <option value="<?= h($d) ?>"><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="commentaire" maxlength="500" placeholder="pourquoi (facultatif)">
          <button type="submit">Enregistrer</button>
          <span class="qd-rappel">n’écrit que dans le journal</span>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$occurrences): ?>
      <p class="qd-note">Aucune occurrence dans ce filtre.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p class="qd-pied">
  Détection jouée le <?= h($passe['debut']) ?> — <?= $nb($passe['occurrences']) ?> occurrence(s)
  vue(s) à ce passage. Les anomalies que le détecteur ne voit plus restent au registre, datées :
  c’est la seule preuve qu’elles ont été corrigées.
</p>

</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
