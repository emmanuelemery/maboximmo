<?php
/**
 * REPLAY ET IDEMPOTENCE — une relecture identique doit produire un résultat identique.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QUE LES SCEAUX MENTAIENT DANS LES DEUX SENS. Ils portaient l'`id`
 *    d'`AUTO_INCREMENT` : une relecture STRICTEMENT IDENTIQUE décalait les identifiants et
 *    périmait la phase alors qu'aucun fait métier n'avait bougé. Et le jour où on l'a éprouvé,
 *    on a découvert que le staging de la phase 2 portait CHAQUE OCCURRENCE DEUX FOIS : le
 *    résultat validé était juste, mais il n'était pas reproductible.
 *
 * ⚠️ UN SCEAU S'ÉPROUVE SUR TROIS ESSAIS, PAS DEUX :
 *       replay identique → NON périmée   ·   modification → périmée   ·   restauration → NON
 *    C'est le premier qui manquait, et c'est lui qui a tout révélé.
 *
 * ⚠️ CE TEST REJOUE LES MOTEURS. Il est lent (plusieurs minutes) et il ne touche QUE le
 *    staging. Aucune donnée métier n'est écrite, et chaque phase est rescellée sur son propre
 *    résultat — jamais sur un résultat modifié.
 *
 * Usage : php crgi_replay.php [import_id]
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';

$pdo = $GLOBALS['pdo'];
// ⚠️ PAS D'IMPORT ÉCRIT EN DUR — voir `crgi_coherence.php`.
$importId = (int)($argv[1] ?? crgi_import_reference($pdo));
$ok = 0;
$ko = [];

function essai(string $titre, string $incident, callable $fn): void
{
    global $ok, $ko;
    try {
        $fn();
        $ok++;
        echo "  OK   {$titre}\n";
    } catch (Throwable $e) {
        $ko[] = [$titre, $incident, $e->getMessage()];
        echo "  ÉCHEC {$titre}\n";
    }
}

function exiger(bool $cond, string $m): void
{
    if (!$cond) {
        throw new RuntimeException($m);
    }
}

echo "REPLAY / IDEMPOTENCE — import {$importId}\n";

$empreintes = [
    2 => 'crgi_empreinte_phase2', 3 => 'crgi_empreinte_phase3',
    4 => 'crgi_empreinte_phase4', 5 => 'crgi_empreinte_phase5',
];
$moteurs = [2 => 'crgi_phase2', 3 => 'crgi_phase3', 4 => 'crgi_phase4', 5 => 'crgi_phase5'];

foreach ($empreintes as $p => $emp) {
    essai(
        "phase {$p} — relecture strictement identique",
        'Une empreinte qui dépend de l’AUTO_INCREMENT périme la phase sans qu’un seul fait '
        . 'métier ait changé. Et un contenu non reproductible se découvre uniquement ici.',
        function () use ($pdo, $importId, $p, $emp, $moteurs) {
            // ⚠️ ON RESTAURE LE STATUT QU'AVAIT LA PHASE, PAS « VALIDEE » D'OFFICE. Forcer
            //    « VALIDEE » sur une phase jamais scellée la déclarait périmée contre une
            //    empreinte vide : le test accusait le moteur de son propre raccourci.
            $st = $pdo->prepare('SELECT statut, resultat_sha FROM crgi_phase
                                  WHERE import_id = ? AND phase = ?');
            $st->execute([$importId, $p]);
            $etatAvant = $st->fetch(PDO::FETCH_ASSOC) ?: ['statut' => 'EN ATTENTE',
                                                          'resultat_sha' => null];
            $avant = $emp($pdo, $importId);
            $moteurs[$p]($pdo, $importId);
            $apres = $emp($pdo, $importId);
            $pdo->prepare('UPDATE crgi_phase SET statut = ? WHERE import_id = ? AND phase = ?')
                ->execute([$etatAvant['statut'], $importId, $p]);
            exiger($avant === $apres, sprintf(
                'empreinte %s avant, %s après une relecture identique',
                substr($avant, 0, 12), substr($apres, 0, 12)
            ));
            if ($etatAvant['statut'] === 'VALIDEE' && $etatAvant['resultat_sha']) {
                $e = crgi_phase_validee($pdo, $importId, $p);
                exiger(!$e['perimee'],
                       'la phase est déclarée périmée après une relecture identique');
            }
        }
    );
}

// ── LE SCEAU DOIT VOIR CE QU'IL PRÉTEND COUVRIR ───────────────────────────────────────────
$mutations = [
    // ⚠️ LE NOM DU PROPRIÉTAIRE ÉTAIT HORS DU SCEAU DE LA PHASE 0. Le 02/09/2026, 288 CRG sur
    //    325 portaient « Agence: A3 - REGIE EMERY - VIENNE » comme nom ; la correction en a
    //    fait 72 noms justes — et l'empreinte de la phase 0 n'a pas bougé d'un caractère.
    //    Une identification fausse pouvait donc rester scellée « VALIDÉE ».
    [0, 'crgi_empreinte_phase0', 'le nom du propriétaire lu',
     'UPDATE crgi_crg SET proprietaire = "AGENCE PRISE POUR UN NOM" WHERE id = :id',
     'SELECT id, COALESCE(proprietaire, "") v FROM crgi_crg WHERE import_id = :i
       ORDER BY id LIMIT 1',
     'UPDATE crgi_crg SET proprietaire = :v WHERE id = :id'],
    [3, 'crgi_empreinte_phase3', 'le statut d’une occupation',
     'UPDATE crgi_occupation SET statut = "PARTI DEMONTRE" WHERE id = :id',
     'SELECT id, statut v FROM crgi_occupation WHERE import_id = :i ORDER BY id LIMIT 1',
     'UPDATE crgi_occupation SET statut = :v WHERE id = :id'],
    [3, 'crgi_empreinte_phase3', 'la référence d’un lot',
     'UPDATE crgi_occupation SET lot_reference = "ZZZ" WHERE id = :id',
     'SELECT id, lot_reference v FROM crgi_occupation WHERE import_id = :i ORDER BY id LIMIT 1',
     'UPDATE crgi_occupation SET lot_reference = :v WHERE id = :id'],
    [4, 'crgi_empreinte_phase4', 'un montant, d’un centime',
     'UPDATE crgi_mouvement SET montant = montant + 0.01 WHERE id = :id',
     'SELECT id, montant v FROM crgi_mouvement WHERE import_id = :i ORDER BY id LIMIT 1',
     'UPDATE crgi_mouvement SET montant = :v WHERE id = :id'],
    [4, 'crgi_empreinte_phase4', 'une réimpression requalifiée en contributive',
     'UPDATE crgi_mouvement SET reimpression = 0, additionnable = 1 WHERE id = :id',
     'SELECT id, reimpression v FROM crgi_mouvement WHERE import_id = :i AND reimpression = 1
       ORDER BY id LIMIT 1',
     'UPDATE crgi_mouvement SET reimpression = :v, additionnable = 0 WHERE id = :id'],
    [4, 'crgi_empreinte_phase4', 'la nature d’un mouvement',
     'UPDATE crgi_mouvement SET categorie = "ENCAISSEMENT" WHERE id = :id',
     'SELECT id, categorie v FROM crgi_mouvement WHERE import_id = :i
       AND categorie = "LOYER APPELE" ORDER BY id LIMIT 1',
     'UPDATE crgi_mouvement SET categorie = :v WHERE id = :id'],
    [5, 'crgi_empreinte_phase5', 'un dénombrement du plan',
     'UPDATE crgi_plan SET nombre = nombre + 1 WHERE id = :id',
     'SELECT id, nombre v FROM crgi_plan WHERE import_id = :i ORDER BY id LIMIT 1',
     'UPDATE crgi_plan SET nombre = :v WHERE id = :id'],
    [5, 'crgi_empreinte_phase5', 'une action du plan',
     'UPDATE crgi_plan SET action = "CREER" WHERE id = :id',
     'SELECT id, action v FROM crgi_plan WHERE import_id = :i AND action = "A ARBITRER"
       ORDER BY id LIMIT 1',
     'UPDATE crgi_plan SET action = :v WHERE id = :id'],
];

foreach ($mutations as [$p, $emp, $quoi, $sqlMut, $sqlLire, $sqlRest]) {
    essai(
        "phase {$p} — le sceau voit : {$quoi}",
        'Un sceau qui ne voit pas la donnée qu’il prétend couvrir ne prouve rien. La phase 2 '
        . 'en a fait la démonstration : son empreinte existait et n’était jamais appelée.',
        function () use ($pdo, $importId, $emp, $sqlMut, $sqlLire, $sqlRest) {
            $st = $pdo->prepare(str_replace(':i', (string)$importId, $sqlLire));
            $st->execute();
            $ligne = $st->fetch(PDO::FETCH_ASSOC);
            exiger((bool)$ligne, 'aucune ligne à muter — le test ne prouverait rien');
            $ref = $emp($pdo, $importId);
            $pdo->prepare($sqlMut)->execute([':id' => $ligne['id']]);
            $mute = $emp($pdo, $importId);
            $pdo->prepare($sqlRest)->execute([':id' => $ligne['id'], ':v' => $ligne['v']]);
            $restaure = $emp($pdo, $importId);
            exiger($mute !== $ref, 'l’empreinte n’a PAS changé alors que la donnée a changé');
            exiger($restaure === $ref, 'l’empreinte n’est pas revenue après restauration');
        }
    );
}

// ── LE CONTENU LUI-MÊME DOIT ÊTRE REPRODUCTIBLE ───────────────────────────────────────────
essai(
    'le contenu du staging est reproductible, pas seulement son empreinte',
    'Le staging de la phase 2 portait chaque occurrence DEUX FOIS : 622 lignes d’immeubles au '
    . 'lieu de 311. Les compteurs métier étaient justes — ils groupent par objet — mais le '
    . 'résultat validé n’était pas rejouable.',
    function () use ($pdo, $importId) {
        $compter = fn(string $t) => (int)$pdo->query(
            "SELECT COUNT(*) FROM `{$t}` WHERE import_id = {$importId}"
        )->fetchColumn();
        $avant = ['crgi_immeuble' => $compter('crgi_immeuble'),
                  'crgi_lot' => $compter('crgi_lot'),
                  'crgi_occupation' => $compter('crgi_occupation'),
                  'crgi_mouvement' => $compter('crgi_mouvement')];
        crgi_phase2($pdo, $importId);
        $pdo->prepare('UPDATE crgi_phase SET statut = "VALIDEE"
                        WHERE import_id = ? AND phase = 2')->execute([$importId]);
        foreach (['crgi_immeuble', 'crgi_lot'] as $t) {
            exiger($compter($t) === $avant[$t], sprintf(
                '%s : %d lignes après relecture au lieu de %d', $t, $compter($t), $avant[$t]
            ));
        }
    }
);

echo "\nREPLAY : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
