<?php
/**
 * LE TABLEAU DE BORD D'INTÉGRATION — LA VUE. Attend `$pilote` et `$importId`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE BLOC SE GREFFE EN TÊTE DE LA PAGE D'INTÉGRATION EXISTANTE, IL NE LA DOUBLE PAS. Une
 *    seconde interface parallèle aurait divergé de la première au premier ajout, et Emmanuel
 *    aurait eu deux écrans à consulter pour une seule question. La frise des six phases et
 *    les bilans techniques restent EN DESSOUS : ils servent à comprendre, ce bloc sert à
 *    décider s'il faut comprendre.
 *
 * ⚠️ CINQ SECONDES, PAS VINGT-CINQ CHIFFRES. Le premier niveau ne porte que ce qui change la
 *    conduite : où on en est, si ça se passe bien, s'il faut intervenir et pour combien de
 *    temps. Le détail métier est plus bas, replié.
 *
 * ⚠️ LA PERTE SILENCIEUSE EST LE SEUL CHIFFRE QU'ON MET EN ROUGE SANS CONDITION. Tout le reste
 *    peut légitimement ne pas être à 100 % — un document ne dit pas tout. Mais un objet vu,
 *    non traité, et sur lequel MBI ne pose AUCUNE question est une donnée perdue.
 */
$P = $pilote;
$pc = fn($v) => number_format((float)$v, 1, ',', ' ');
$nb = fn($v) => number_format((int)$v, 0, ',', ' ');
$etatSigne = ['FINI' => '✓', 'ENCOURS' => '●', 'ARBITRAGE' => '!', 'ERREUR' => '✕',
              'ATTENTE' => '·'];
?>
<div class="crgi-carte crgi-bord">

  <?php if (!empty($_GET['fini'])): ?>
    <p class="crgi-note" style="border-left-color:var(--crgi-vert);background:#f6fbf8">
      <b>✓ Arbitrages terminés.</b> L’état ci-dessous a été recalculé à l’instant, décisions
      comprises.
    </p>
  <?php endif; ?>

  <!-- ═══ L'EN-TÊTE : QUI, QUOI, DEPUIS QUAND, AVEC QUEL MOTEUR ═══════════════════ -->
  <div class="crgi-bord-tete">
    <div>
      <h2>Intégration CRG</h2>
      <p class="crgi-sous" style="margin:0">
        <?php $ags = array_slice($P['agences'], 0, 3);
              echo h(implode(' · ', array_map(fn($a) => $a['a'] . ' (' . $a['n'] . ')', $ags)));
              if (count($P['agences']) > 3) { echo ' … +' . (count($P['agences']) - 3); } ?>
        <?php if (!empty($P['import']['libelle'])): ?>
          — <?= h((string)$P['import']['libelle']) ?>
        <?php endif; ?>
        · déposé le <?= h((string)($P['import']['cree_le'] ?? '—')) ?>
        · moteur <code><?= h(substr((string)($P['moteur']['commit'] ?? '—'), 0, 7)) ?></code>
      </p>
    </div>
    <div class="crgi-bord-statut crgi-st-<?= h(preg_replace('~[^A-Z]~', '',
                                              str_replace('’', '', $P['statut']))) ?>">
      <?= h($P['statut']) ?>
    </div>
  </div>

  <!-- ═══ CE QUI CHANGE LA CONDUITE ══════════════════════════════════════════════ -->
  <div class="crgi-bord-kpi">
    <div><span>CRG</span><b><?= $nb($P['volumes']['crg']) ?></b></div>
    <div><span>Pages examinées</span>
         <b><?= $nb($P['volumes']['pages_lues']) ?><i> / <?= $nb($P['volumes']['pages']) ?></i></b></div>
    <div><span>Compris automatiquement</span><b><?= $pc($P['taux']['compris']) ?><i> %</i></b></div>
    <div><span>Objets reliés</span><b><?= $pc($P['taux']['relies']) ?><i> %</i></b></div>
    <div><span>Mouvements qualifiés</span><b><?= $pc($P['taux']['qualifies']) ?><i> %</i></b></div>
    <div class="<?= $P['arbitrages']['total'] ? 'crgi-bord-att' : 'crgi-bord-ok' ?>">
      <span>Arbitrages</span><b><?= $nb($P['arbitrages']['total']) ?></b></div>
    <!-- ⚠️ LE CHIFFRE QUI DOIT SAUTER AUX YEUX. -->
    <div class="crgi-bord-perte <?= $P['pertes'] ? 'crgi-bord-mal' : 'crgi-bord-ok' ?>">
      <span>Pertes silencieuses</span>
      <b><?= $nb(array_sum(array_column($P['pertes'], 'n'))) ?></b></div>
    <div><span>Interventions / 100 CRG</span><b><?= $pc($P['kpi']['interv_100crg']) ?></b></div>
    <div><span>Temps machine</span>
         <b><?= $P['temps']['machine'] === null ? '—' : h(crgi_duree((int)$P['temps']['machine'])) ?></b></div>
    <div><span>Temps humain</span><b><?= h(crgi_duree((int)$P['temps']['humain'])) ?></b></div>
  </div>

  <?php if ($P['temps']['machine'] === null): ?>
    <p class="crgi-note">
      <b>Temps machine non mesuré</b> pour cet import : les phases ont été analysées avant que
      la durée ne soit enregistrée. L’atelier — du dépôt à la dernière validation — a duré
      <?= h(crgi_duree((int)$P['temps']['atelier'])) ?>, mais cette horloge murale contient les
      relectures et les nuits : la présenter comme du temps de calcul ferait chercher une
      lenteur du moteur là où il n’y en a pas.
    </p>
  <?php endif; ?>

  <?php if ($P['pertes']): ?>
    <p class="crgi-note rouge">
      <b>Ce que MBI a vu sans rien en faire, et sans poser de question :</b>
      <?php foreach ($P['pertes'] as $x): ?>
        <br>· <?= $nb($x['n']) ?> <?= h($x['quoi']) ?>
      <?php endforeach; ?>
      <br>Un objet en attente qui ouvre une question est du travail en cours ; le même objet
      sans question est une donnée que personne ne cherchera jamais.
    </p>
  <?php endif; ?>

  <!-- ═══ OÙ EN EST-ON ══════════════════════════════════════════════════════════ -->
  <div class="crgi-bord-parcours">
    <?php foreach ($P['parcours'] as $e): ?>
      <div class="crgi-bord-pas crgi-pas-<?= h(strtolower($e['etat'])) ?>"
           title="<?= h($e['quoi']) ?>">
        <span class="crgi-bord-signe"><?= $etatSigne[$e['etat']] ?? '·' ?></span>
        <span class="crgi-bord-nom"><?= h($e['titre']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══ FAUT-IL INTERVENIR ? ══════════════════════════════════════════════════ -->
  <?php if ($P['arbitrages']['total'] > 0): ?>
    <div class="crgi-bord-action">
      <a class="crgi-b or"
         href="<?= h(app_url('/admin/admin_crgi_arbitrage.php')) ?>?import=<?= (int)$importId ?>">
        Traiter les <?= $nb($P['arbitrages']['total']) ?> arbitrages →</a>
      <span class="crgi-bord-prio">
        <?php foreach ($P['arbitrages']['priorites'] as $p => $n):
              if (!$n) { continue; } ?>
          <b class="crgi-prio-<?= h(strtolower(str_replace(' ', '', $p))) ?>"><?= $nb($n) ?></b>
          <?= h(mb_strtolower($p)) ?><?= $n > 1 ? 's' : '' ?>
        <?php endforeach; ?>
      </span>
    </div>
    <ul class="crgi-bord-groupes">
      <?php foreach (array_slice($P['arbitrages']['groupes'], 0, 5, true) as $g => $d): ?>
        <li>
          <a href="<?= h(app_url('/admin/admin_crgi_arbitrage.php')) ?>?import=<?= (int)$importId ?>&amp;groupe=<?= h(urlencode($g)) ?>">
            <b><?= $nb($d['n']) ?></b> <?= h($g) ?></a>
          <span class="crgi-diag-ctx"><?= h($d['famille']) ?> ·
            <?= h(mb_strtolower($d['priorite'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="crgi-note" style="border-left-color:var(--crgi-vert);background:#f6fbf8">
      <b>✓ Aucun arbitrage.</b> Rien n’attend de décision humaine sur cet import.
    </p>
  <?php endif; ?>

  <!-- ═══ CE QUE MBI A APPRIS ═══════════════════════════════════════════════════ -->
  <p class="crgi-bord-appris">
    <b><?= (int)$P['apprentissages']['regles_connues'] ?></b> règles apprises et appliquées
    automatiquement ·
    <b><?= count($P['apprentissages']['nouveaux']) ?></b> phénomène<?= count($P['apprentissages']['nouveaux']) > 1 ? 's' : '' ?>
    jamais rencontré<?= count($P['apprentissages']['nouveaux']) > 1 ? 's' : '' ?> auparavant
    <?php if ($P['apprentissages']['nouveaux']): ?>
      <span class="crgi-diag-ctx">(<?= h(implode(', ', array_slice($P['apprentissages']['nouveaux'], 0, 4))) ?>)</span>
    <?php endif; ?>
    · <b><?= (int)$P['apprentissages']['proposes'] ?></b> proposé<?= $P['apprentissages']['proposes'] > 1 ? 's' : '' ?>
    comme règle
  </p>

  <!-- ═══ LE DÉTAIL MÉTIER, REPLIÉ : IL SERT À COMPRENDRE, PAS À DÉCIDER ════════ -->
  <details class="crgi-repli">
    <summary>Le détail par famille — détectés, automatiques, en attente</summary>
    <div class="crgi-defile">
      <table>
        <tr><th>Famille</th><th class="num">Détectés</th><th class="num">Automatiques</th>
            <th class="num">En arbitrage</th><th class="num">Inexpliqués</th><th class="num">%</th></tr>
        <?php foreach ($P['familles'] as $f): ?>
          <tr>
            <td><?= h($f['famille']) ?></td>
            <td class="num"><?= $nb($f['detectes']) ?></td>
            <td class="num"><?= $nb($f['auto']) ?></td>
            <td class="num"><?= $f['attente'] ? $nb($f['attente']) : '—' ?></td>
            <!-- ⚠️ INEXPLIQUÉ = DÉTECTÉ − AUTOMATIQUE − EN ARBITRAGE. Doit valoir 0 : tout ce
                 qui n'est pas traité doit être une question posée. -->
            <td class="num<?= ($f['detectes'] - $f['auto'] - $f['attente']) ? ' crgi-bord-mal' : '' ?>">
              <?= $nb($f['detectes'] - $f['auto'] - $f['attente']) ?></td>
            <td class="num"><?= $pc($f['taux']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <p class="crgi-diag-ctx" style="margin-top:8px">
      <b>Attendu = examiné + explicitement exclu</b>, et <b>inexpliqué = 0</b>. Un taux inférieur
      à 100 % n’est pas un défaut : un document ne démontre pas tout. Une colonne « inexpliqués »
      non nulle, si.
    </p>
  </details>
</div>
