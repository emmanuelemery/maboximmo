<?php
/**
 * COHÉRENCE ET COUVERTURE DE L'INTÉGRATION — sur le staging réel, phases scellées.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE À CAUSE DES 26 LOTS PERDUS. La phase 2 voyait 98 lots là où le document
 *    en imprime 124 ; elle était scellée, validée, et le bilan d'intégration s'est construit
 *    sur son chiffre amputé. Rien ne l'a signalé — il a fallu qu'Emmanuel compte à la main.
 *
 * ⚠️ `EXACTITUDE ≠ EXHAUSTIVITÉ.` Une phase peut être juste sur ce qu'elle traite et fausse
 *    sur la population. Les deux se démontrent, ou la phase n'est pas valide.
 *
 * Usage : php crgi_coherence.php [import_id]
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';

$pdo = $GLOBALS['pdo'];
$importId = (int)($argv[1] ?? 5);
$ok = 0;
$ko = [];

/** Un contrôle, avec l'incident réel qu'il défend. */
function controle(string $titre, string $incident, callable $fn): void
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

function exiger(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

echo "COHÉRENCE — import {$importId}\n";

// ── COUVERTURE : ATTENDUE = EXAMINÉE + EXCLUE, INEXPLIQUÉE = 0 ─────────────────────────────
foreach (crgi_couverture($pdo, $importId) as $c) {
    controle(
        sprintf('couverture P%d — %s', $c['phase'], $c['population']),
        'Une phase peut être exacte et incomplète : la population inexpliquée doit être nulle.',
        function () use ($c) {
            exiger($c['inexpliquee'] === 0, sprintf(
                '%d objets inexpliqués : attendue %d, examinée %d, exclue %d',
                $c['inexpliquee'], $c['attendue'], $c['examinee'], $c['exclue']
            ));
        }
    );
}

// ── FRONTIÈRES : deux phases qui parlent du même objet en comptent autant ──────────────────
foreach (crgi_frontieres($pdo, $importId) as $f) {
    controle(
        sprintf('frontière %s : %s → %s', $f['objet'], $f['amont'], $f['aval']),
        'Une phase AVAL ne doit jamais connaître un objet que l’amont ignore : c’est ainsi '
        . 'que 26 lots ont disparu du patrimoine sans que rien ne le signale.',
        function () use ($f) {
            exiger(empty($f['manquants_amont']), sprintf(
                '%d objets connus de « %s » et absents de « %s » : %s',
                count($f['manquants_amont']), $f['aval'], $f['amont'],
                implode(', ', array_slice($f['manquants_amont'], 0, 5))
            ));
            if ($f['sens'] !== 'inclusion') {
                exiger(empty($f['manquants_aval']), sprintf(
                    '%d objets connus de « %s » et absents de « %s » : %s',
                    count($f['manquants_aval']), $f['amont'], $f['aval'],
                    implode(', ', array_slice($f['manquants_aval'], 0, 5))
                ));
            }
        }
    );
}

// ── LES SCEAUX ────────────────────────────────────────────────────────────────────────────
controle(
    'aucune phase validée n’est périmée',
    'Un sceau périmé signifie que le résultat validé n’est plus celui qui est en base.',
    function () use ($pdo, $importId) {
        foreach ([0, 1, 2, 3, 4, 5] as $p) {
            $e = crgi_phase_validee($pdo, $importId, $p);
            exiger(!($e['validee'] && $e['perimee']), "la phase {$p} est PÉRIMÉE");
        }
    }
);

controle(
    'aucune empreinte ne dépend d’un AUTO_INCREMENT',
    'Les empreintes portaient l’`id` de staging : une relecture STRICTEMENT IDENTIQUE '
    . 'périmait la phase alors que rien n’avait changé. Un sceau qui crie au loup ne vaut pas '
    . 'mieux qu’un sceau muet.',
    function () {
        // ⚠️ LE FICHIER PEUT ÊTRE EN CRLF. Chercher « \n}\n » n'y trouvait rien, le corps
        //    débordait sur la fonction suivante, et le test accusait la phase 5 d'un `id`
        //    qui appartenait à sa voisine. On normalise les fins de ligne d'abord.
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../inc/crg_integration.php'));
        foreach ([2, 3, 4, 5] as $p) {
            $i = strpos($src, "function crgi_empreinte_phase{$p}(");
            exiger($i !== false, "empreinte de la phase {$p} introuvable");
            // Le corps s'arrête à la première accolade fermante en colonne 0 : découper à une
            // longueur fixe faisait déborder le contrôle sur la fonction suivante, et le test
            // accusait la phase 5 d'un `id` qui appartenait au voisin.
            $j = strpos($src, "\n}\n", $i);
            $corps = substr($src, $i, ($j === false ? 2600 : $j - $i));
            exiger(!preg_match('~SELECT\s+(?:\w+\.)?id\b~i', $corps),
                   "l’empreinte de la phase {$p} sélectionne un id technique");
            exiger(str_contains($corps, 'sort($l'),
                   "l’empreinte de la phase {$p} ne trie pas son contenu");
        }
    }
);

// ── LE PLAN D'INTÉGRATION ─────────────────────────────────────────────────────────────────
controle(
    'le plan rend compte de TOUS les mouvements',
    'Un bilan qui ne totalise pas son propre matériau ne prouve rien : 628 lignes étaient '
    . 'restées hors du plan.',
    function () use ($pdo, $importId) {
        $b = crgi_bilan_phase5($pdo, $importId);
        exiger($b['boucle'], sprintf('%d mouvements sur %d couverts',
                                     $b['couverts'], $b['mouvements']));
    }
);

controle(
    'REFUS — « SUPPRIMER » n’existe pas dans le plan',
    '`ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER` et `ANCIEN LOCATAIRE ≠ SUPPRIMER`. Le maximum '
    . 'est ARCHIVER, et il se démontre.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT DISTINCT action FROM crgi_plan WHERE import_id = ?');
        $st->execute([$importId]);
        $permis = ['CREER', 'METTRE A JOUR', 'ARCHIVER', 'INCHANGE', 'A ARBITRER',
                   'NON INTEGRABLE'];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
            exiger(in_array($a, $permis, true), "action interdite dans le plan : « {$a} »");
        }
    }
);

controle(
    'REFUS — aucun « CRÉER » sans confrontation à MBI',
    '`OBSERVÉ DANS LE CORPUS ≠ NOUVEAU DANS MBI` : annoncer 119 locataires à créer aurait '
    . 'fabriqué 88 doublons de locataires que MBI porte déjà.',
    function () use ($pdo, $importId) {
        foreach (['LOCATAIRES', 'PROPRIETAIRES'] as $famille) {
            $st = $pdo->prepare('SELECT SUM(nombre) FROM crgi_plan
                                  WHERE import_id = ? AND famille = ? AND action = "INCHANGE"');
            $st->execute([$importId, $famille]);
            exiger((int)$st->fetchColumn() > 0,
                   "{$famille} : aucune ligne INCHANGÉ — la confrontation à MBI n’a pas eu lieu");
        }
    }
);

// ── AUCUNE ÉCRITURE MÉTIER ────────────────────────────────────────────────────────────────
controle(
    'aucune écriture dans les données métier de MBI',
    'Le module est entièrement en staging : aucune table métier ne doit bouger.',
    function () use ($pdo) {
        $temoins = ['biens' => 1379, 'bien_baux' => 670, 'immeubles' => 1009,
                    'proprietaires' => 393, 'crg_trimestres' => 501, 'crg_ecritures' => 26711,
                    'crg_situations_locataires' => 3226];
        foreach ($temoins as $t => $attendu) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            exiger($n === $attendu, "{$t} : {$n} lignes au lieu de {$attendu}");
        }
    }
);

controle(
    'REFUS — le code d’intégration n’écrit jamais dans une table métier',
    'La garantie ne doit pas reposer sur un comptage a posteriori : le code lui-même ne doit '
    . 'porter aucun INSERT/UPDATE/DELETE hors du staging `crgi_*`.',
    function () {
        $src = file_get_contents(__DIR__ . '/../../inc/crg_integration.php');
        $interdits = ['biens', 'bien_baux', 'immeubles', 'proprietaires', 'crg_trimestres',
                      'crg_ecritures', 'crg_situations_locataires', 'tiers'];
        foreach ($interdits as $t) {
            foreach (['INSERT INTO', 'UPDATE', 'DELETE FROM'] as $verbe) {
                $motif = '~' . preg_quote($verbe, '~') . '\s+`?' . preg_quote($t, '~') . '`?\b~i';
                exiger(!preg_match($motif, $src),
                       "« {$verbe} {$t} » trouvé dans le code d’intégration");
            }
        }
    }
);

echo "\nCOHÉRENCE : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
