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
    <!-- ⚠️ COMPRENDRE ET DÉCIDER SEUL SONT DEUX CHOSES, ET UN SEUL CHIFFRE DISAIT LES DEUX.
         « Compris automatiquement » affichait la part des mouvements portant une nature :
         100 % sur un dépôt où 126 immeubles attendaient une décision d'identité. Les deux
         taux sont désormais côte à côte, sur la MÊME assiette — toutes les familles — et
         l'autonomie est toujours le plus bas des deux. -->
    <div><span>Compris (lu et nommé)</span><b><?= $pc($P['taux']['compris']) ?><i> %</i></b></div>
    <!-- ⚠️ LA NOTE DU LECTEUR ET CELLE DE LA BASE SONT DEUX NOTES. Un compte rendu
         parfaitement lu mais impossible à rattacher parce que MBI porte trois fois le même
         immeuble ne doit pas faire baisser la première. -->
    <div><span>Raccordé à MBI</span><b><?= $pc($P['taux']['confrontation']) ?><i> %</i></b></div>
    <div class="<?= $P['taux']['autonomie'] < 100 ? 'crgi-bord-att' : 'crgi-bord-ok' ?>">
      <span>Décidé sans l’humain</span><b><?= $pc($P['taux']['autonomie']) ?><i> %</i></b></div>
    <div class="<?= $P['apprentissages']['evitees'] ? 'crgi-bord-ok' : '' ?>">
      <span>Questions évitées par apprentissage</span>
      <b><?= $nb($P['apprentissages']['evitees']) ?></b></div>
    <div><span>Objets reliés</span><b><?= $pc($P['taux']['relies']) ?><i> %</i></b></div>
    <div><span>Mouvements qualifiés</span><b><?= $pc($P['taux']['qualifies']) ?><i> %</i></b></div>
    <!-- ⚠️ TROIS CAUSES, TROIS COMPTEURS — JAMAIS UNE SOMME. « ARBITRAGES = X + Y + Z »
         mélangerait trois problèmes qui ne se règlent ni au même endroit ni par la même
         personne : relire un PDF, faire le ménage dans MBI, régler un serveur. -->
    <div class="<?= $P['causes']['DOCUMENT'] ? 'crgi-bord-att' : 'crgi-bord-ok' ?>">
      <span>Arbitrages CRG</span><b><?= $nb($P['causes']['DOCUMENT']) ?></b></div>
    <div class="<?= $P['causes']['MBI'] ? 'crgi-bord-att' : 'crgi-bord-ok' ?>">
      <span>Ambiguïtés dues à MBI</span><b><?= $nb($P['causes']['MBI']) ?></b></div>
    <!-- ⚠️ LE CHIFFRE QUI DOIT SAUTER AUX YEUX. -->
    <div class="crgi-bord-perte <?= $P['pertes'] ? 'crgi-bord-mal' : 'crgi-bord-ok' ?>">
      <span>Pertes silencieuses</span>
      <b><?= $nb(array_sum(array_column($P['pertes'], 'n'))) ?></b></div>
    <div><span>Interventions / 100 CRG</span><b><?= $pc($P['kpi']['interv_100crg']) ?></b></div>
    <div class="<?= $P['apprentissages']['contredites'] ? 'crgi-bord-mal' : '' ?>">
      <span>Décisions contredites</span>
      <b><?= $nb($P['apprentissages']['contredites']) ?></b></div>
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

  <!-- ═══ LES DOCUMENTS REÇUS, ET CE QU'ILS DEMANDENT ═══════════════════════════ -->
  <?php $horsAnalyse = array_diff_key($P['documents'], ['ANALYSEE' => 1]);
        if ($horsAnalyse): ?>
    <p class="crgi-note">
      <b>Les pièces qui ne sont pas des comptes rendus</b> — elles sont comptées, jamais perdues :
      <?php foreach ($horsAnalyse as $etat => $n): ?>
        <br>· <b><?= $nb($n) ?></b> <?= h(mb_strtolower($etat)) ?><?php
          echo match ($etat) {
              'OCR REQUIS' => ' — des numérisations sans couche texte. Elles demandent un OCR, '
                            . 'pas une correction du moteur.',
              'HORS CRG' => ' — lues entièrement ; ce ne sont pas des comptes rendus de gestion.',
              'STRUCTURE INCONNUE' => ' — techniquement lisibles, mais leur grammaire n’est pas '
                                    . 'encore connue du moteur : c’est un travail d’analyse.',
              'ILLISIBLE' => ' — la source elle-même ne se lit pas.',
              '(sans état)' => ' — ⚠️ aucune de ces pièces ne devrait exister : un document sans '
                             . 'état a disparu avant les phases métier.',
              default => '',
          }; ?>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <!-- ═══ CE QUE MBI COÛTE À CE DÉPÔT, ET OÙ ALLER LE RÉGLER ════════════════════ -->
  <?php if ($P['causes']['MBI']): ?>
    <p class="crgi-note">
      <b><?= $nb($P['causes']['MBI']) ?> question<?= $P['causes']['MBI'] > 1 ? 's' : '' ?>
      ne vien<?= $P['causes']['MBI'] > 1 ? 'nent' : 't' ?> pas des documents</b> — ils sont
      compris. C’est MBI qui ne sait pas répondre : plusieurs enregistrements y prétendent
      être le même immeuble, ou portent la même écriture avec un autre montant.
      <?php if ($P['causes']['bloquees_par_mbi']): ?>
        <?= $nb($P['causes']['bloquees_par_mbi']) ?> d’entre elles empêchent réellement une
        association.
      <?php endif; ?>
      <br>La réponse n’est pas dans le PDF :
      <a href="<?= h(app_url('/admin/admin_qualite_donnees.php')) ?>">voir la qualité des
      données</a>.
    </p>
  <?php endif; ?>

  <!-- ═══ CE QUI NE SE DEMANDE À PERSONNE ═══════════════════════════════════════
       ⚠️ CE BLOC EST LA CONTREPARTIE DE LA FILE COURTE. On a retiré des questions ;
          il faut donc dire lesquelles, et pourquoi — sans quoi « 12 arbitrages » ne
          voudrait rien dire de plus que « 49 » : on ne saurait pas ce qu'on ne voit
          plus. Ces lignes existent, elles sont comptées, elles ne sont pas écrites. -->
  <?php if (!empty($P['sans_reponse'])): ?>
    <p class="crgi-note">
      <b><?= $nb($P['sans_reponse']) ?> ligne<?= $P['sans_reponse'] > 1 ? 's' : '' ?> que
      personne ne peut trancher</b> — ni le moteur, ni vous. Un libellé générique (« Solde »)
      face à plusieurs écritures MBI homonymes, ou des écritures strictement indiscernables :
      même libellé <i>et</i> même montant. <code>MÊME MONTANT ≠ MÊME ÉCRITURE</code>.
      Elles sont lues, conservées et comptées au plan en « non intégrable » — jamais écrites,
      et jamais posées en question, faute de réponse possible.
    </p>
  <?php endif; ?>

  <?php if (!empty($P['valeurs_inconnues'])): ?>
    <p class="crgi-note rouge">
      <b>Des valeurs que personne n’a déclarées.</b> Une valeur nouvelle n’est pas une erreur —
      qu’elle passe inaperçue en est une : un filtre écrit en positif exclut en silence tout ce
      qui n’existait pas quand on l’a écrit.
      <?php foreach (array_slice($P['valeurs_inconnues'], 0, 6) as $v): ?>
        <br>· <code><?= h($v['ou']) ?></code> = « <?= h($v['valeur']) ?> »
      <?php endforeach; ?>
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
    <?php if ($P['apprentissages']['evitees']): ?>
      · <b><?= (int)$P['apprentissages']['evitees'] ?></b> question<?= $P['apprentissages']['evitees'] > 1 ? 's' : '' ?>
      qu’Emmanuel n’a pas eu à reprendre, parce qu’il y avait déjà répondu
    <?php endif; ?>
    <?php if ($P['apprentissages']['contredites']): ?>
      · <b class="crgi-bord-mal"><?= (int)$P['apprentissages']['contredites'] ?></b>
      décision<?= $P['apprentissages']['contredites'] > 1 ? 's' : '' ?> mémorisée<?= $P['apprentissages']['contredites'] > 1 ? 's' : '' ?>
      que le document contredit — remise<?= $P['apprentissages']['contredites'] > 1 ? 's' : '' ?> en arbitrage
    <?php endif; ?>
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
