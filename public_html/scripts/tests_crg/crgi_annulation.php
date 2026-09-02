<?php
/**
 * ANNULER UN IMPORT — LA RÉVERSIBILITÉ, PROUVÉE TABLE PAR TABLE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE À CAUSE DE 8 408 LIGNES SURVIVANTES. Le 02/09/2026, l'import 5 a été
 *    annulé depuis la page Admin : l'écran a affiché ANNULÉ, les pages, les CRG, les phases et
 *    le PDF ont bien disparu — et `crgi_immeuble` (311), `crgi_lot` (471), `crgi_occupation`
 *    (478), `crgi_mouvement` (7 099) et `crgi_plan` (49) sont restés intacts. `crgi_annuler`
 *    énumérait à la main les quatre tables du premier jour ; six migrations en avaient ajouté
 *    six autres sans que personne n'allonge la liste.
 *
 * ⚠️ CE N'EST PAS UN DÉTAIL DE MÉNAGE. Redéposer le même PDF après une telle annulation ne
 *    repart pas de zéro : il repart d'un demi-souvenir, et personne ne sait plus lequel.
 *    L'annulation est ce qui rend un document À NOUVEAU INCONNU du système.
 *
 * ⚠️ ET AUCUNE CLÉ ÉTRANGÈRE NE RATTRAPE L'OUBLI : les tables `crgi_*` n'en portent aucune.
 *    Rien ne cascade. C'est le code, et lui seul, qui tient la promesse.
 *
 * Ce test travaille sur un import JETABLE qu'il crée lui-même, jamais sur un import réel.
 *
 * Usage : php crgi_annulation.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';

$pdo = $GLOBALS['pdo'];
$ok = 0;
$ko = [];

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

function exiger(bool $cond, string $m): void
{
    if (!$cond) {
        throw new RuntimeException($m);
    }
}

/**
 * Une ligne plausible dans chaque table de staging, sans rien savoir de leur schéma :
 * on remplit les colonnes NOT NULL sans défaut, et on laisse le reste tranquille.
 */
function semer(PDO $pdo, string $table, int $importId): void
{
    $valeurs = ['import_id' => $importId];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $nom = $c['Field'];
        if ($nom === 'import_id' || $c['Extra'] === 'auto_increment') {
            continue;
        }
        if ($c['Null'] === 'YES' || $c['Default'] !== null) {
            continue;
        }
        $type = strtolower($c['Type']);
        // ⚠️ 9 ET NON 0 : `crgi_creer_import` inscrit d'office la phase 0, et une semence à 0
        //    heurtait sa clé unique — le test échouait sur son propre outillage.
        $valeurs[$nom] = match (true) {
            str_contains($type, 'int'), str_contains($type, 'decimal'),
            str_contains($type, 'float'), str_contains($type, 'double') => 9,
            str_contains($type, 'date'), str_contains($type, 'time')    => '2026-09-02 00:00:00',
            default                                                     => 'TEST',
        };
    }
    $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($valeurs)));
    $marq = implode(', ', array_fill(0, count($valeurs), '?'));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($marq)")
        ->execute(array_values($valeurs));
}

echo "ANNULATION — réversibilité d'un import\n";

/**
 * ⚠️ LE TEST INTERROGE LE SCHÉMA LUI-MÊME, IL N'APPELLE PAS `crgi_tables_de_staging()`.
 *    La première version le faisait : semer et vérifier sur la liste rendue par la fonction
 *    testée revenait à lui demander sa propre note. Avec la liste amputée d'origine, le
 *    contrôle central passait au vert alors que 8 408 lignes survivaient — il ne semait
 *    simplement pas dans les tables oubliées. Un oracle emprunté au code testé n'est pas
 *    un oracle.
 */
$tables = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'import_id'
        AND TABLE_NAME LIKE 'crgi\\_%' AND TABLE_NAME <> 'crgi_import'
      ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

controle(
    'le balayage se déduit du schéma, pas d’une liste écrite à la main',
    'La liste en dur de `crgi_annuler` s’est arrêtée aux quatre tables du premier jour. '
    . 'Six migrations ont ajouté six tables ; l’annulation les ignorait toutes.',
    function () use ($pdo, $tables) {
        exiger(count($tables) >= 10, 'seulement ' . count($tables) . ' tables de staging vues');
        $balaye = crgi_tables_de_staging($pdo);
        exiger(!in_array('crgi_import', $balaye, true), 'crgi_import serait effacée');
        // Toute table `crgi_` portant `import_id` doit être dans le balayage : c'est la
        // règle qui remplace la liste, et elle doit valoir pour la table ajoutée demain.
        $oubliees = array_diff($tables, $balaye);
        exiger(!$oubliees, 'tables oubliées : ' . implode(', ', $oubliees));
    }
);

// ── L'import jetable : on le crée, on le sème dans TOUTES les tables, on l'annule. ────────
// ⚠️ LE TÉMOIN SE PREND AVANT LA CRÉATION, sans quoi `crgi_import_courant` désigne le jetable
//    lui-même et le contrôle « on ne touche pas aux autres » se compare à sa propre victime.
$temoin  = crgi_import_courant($pdo);
$jetable = crgi_creer_import($pdo, 'TEST ANNULATION — jetable, à supprimer', 0);

try {
    controle(
        'un import annulé ne laisse AUCUNE ligne dans AUCUNE table de staging',
        'Import 5 annulé le 02/09/2026 : 311 immeubles, 471 lots, 478 occupations, '
        . '7 099 mouvements et 49 lignes de plan ont survécu à l’annulation.',
        function () use ($pdo, $tables, $jetable) {
            foreach ($tables as $t) {
                semer($pdo, $t, $jetable);
            }
            foreach ($tables as $t) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE import_id = ?");
                $st->execute([$jetable]);
                // `crgi_phase` en contient déjà une : on exige « au moins une », pas « une ».
                exiger((int)$st->fetchColumn() >= 1, "la semence de {$t} n’a pas pris");
            }

            crgi_annuler($pdo, $jetable, 0, 'test automatique');

            $restes = [];
            foreach ($tables as $t) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE import_id = ?");
                $st->execute([$jetable]);
                $n = (int)$st->fetchColumn();
                if ($n > 0) {
                    $restes[] = "{$t}={$n}";
                }
            }
            exiger(!$restes, 'survivants après annulation : ' . implode(', ', $restes));
        }
    );

    controle(
        'la ligne d’import survit, en ANNULE, avec son motif',
        'Effacer la ligne ferait disparaître la trace du dépôt : on ne saurait plus qu’il a '
        . 'eu lieu, ni pourquoi il a été abandonné.',
        function () use ($pdo, $jetable) {
            $st = $pdo->prepare('SELECT statut, annule_motif, nb_pages FROM crgi_import
                                  WHERE id = ?');
            $st->execute([$jetable]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            exiger((bool)$r, 'la ligne d’import a été supprimée');
            exiger($r['statut'] === 'ANNULE', 'statut = ' . $r['statut']);
            exiger($r['annule_motif'] === 'test automatique', 'motif perdu');
            exiger((int)$r['nb_pages'] === 0, 'nb_pages non remis à zéro');
        }
    );

    controle(
        'annuler un import ne touche pas les autres',
        'Un DELETE sans `import_id` viderait le staging des imports voisins — y compris '
        . 'un import en cours d’analyse dans un autre onglet.',
        function () use ($pdo, $tables, $temoin, $jetable) {
            exiger($temoin > 0 && $temoin !== $jetable, 'aucun import témoin disponible');
            $vus = 0;
            foreach ($tables as $t) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE import_id = ?");
                $st->execute([$temoin]);
                $vus += (int)$st->fetchColumn();
            }
            exiger($vus > 0, "l’import témoin {$temoin} n’a plus aucune ligne de staging");
        }
    );

    controle(
        'un import déjà INTÉGRÉ refuse de s’annuler ici',
        'À ce stade des écritures métier existent : les défaire est une autre opération, '
        . 'qui ne se déclenche pas du même bouton.',
        function () use ($pdo, $jetable) {
            $pdo->prepare('UPDATE crgi_import SET statut = "INTEGRE" WHERE id = ?')
                ->execute([$jetable]);
            $refus = false;
            try {
                crgi_annuler($pdo, $jetable, 0, 'ne doit pas passer');
            } catch (Throwable $e) {
                $refus = str_contains($e->getMessage(), 'DÉJÀ INTÉGRÉ');
            }
            $pdo->prepare('UPDATE crgi_import SET statut = "ANNULE" WHERE id = ?')
                ->execute([$jetable]);
            exiger($refus, 'l’annulation d’un import INTEGRE a été acceptée');
        }
    );
} finally {
    // ⚠️ LE JETABLE PART VRAIMENT. Le laisser en base ferait grossir la liste des imports
    //    d'une ligne à chaque exécution du harnais.
    foreach ($tables as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE import_id = ?")->execute([$jetable]);
    }
    $pdo->prepare('DELETE FROM crgi_import WHERE id = ?')->execute([$jetable]);
}

echo "\nANNULATION : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
