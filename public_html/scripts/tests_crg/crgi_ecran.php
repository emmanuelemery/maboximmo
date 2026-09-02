<?php
/**
 * L'ÉCRAN D'INTÉGRATION — il doit se rendre, et son JavaScript doit se charger.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QU'UNE CHAÎNE JAVASCRIPT NON FERMÉE A CASSÉ TOUS LES BOUTONS DE
 *    LA PAGE, EN SILENCE. Un `lignes.join('` ouvert sur deux lignes suffit : le navigateur
 *    abandonne le bloc `<script>` entier, plus AUCUN écouteur ne s'attache, et l'écran reste
 *    parfaitement beau. Emmanuel a cliqué sur « Valider le bilan » — rien. Aucune erreur PHP,
 *    aucune ligne de log, aucun test au rouge : le module tout entier était mort côté client.
 *
 * ⚠️ `CE QUI N'EST PAS SUR LA PAGE N'EXISTE PAS` (INTEG-ECRAN-01) — et un bouton qui ne
 *    répond pas n'est pas sur la page. Le contrôle du rendu ne suffit donc pas : il faut
 *    vérifier que le SCRIPT se charge, et que chaque bouton a bien son écouteur.
 *
 * ⚠️ IL NE MODIFIE RIEN. Il rend la page en mémoire, sous une session forgée en lecture.
 *
 * Usage : php crgi_ecran.php [import_id]
 */
declare(strict_types=1);

// ⚠️ PAS D'IMPORT ÉCRIT EN DUR — voir `crgi_coherence.php` : un import annulé rendait la
//    suite muette et verte à la fois. Il faut donc la base AVANT de rendre la page, puisque
//    c'est `$_GET['import']` qui décide de ce qu'elle affiche.
require_once __DIR__ . '/../../inc/crg_integration.php';
$importId = (int)($argv[1] ?? crgi_import_courant($GLOBALS['pdo']));
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

function exiger(bool $c, string $m): void
{
    if (!$c) {
        throw new RuntimeException($m);
    }
}

// ── on rend la page une fois, sous une session forgée ─────────────────────────────────────
// ⚠️ LE BOOTSTRAP A DÉJÀ OUVERT LA SESSION. Rappeler `session_start()` n'échoue pas, mais
//    émet un avis dans la sortie du harnais — et un harnais bruyant finit par ne plus se lire.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = $_SESSION['id_user'] = 8;
$_SESSION['role'] = $_SESSION['id_role'] = 1;
$_SESSION['id_societe'] = 1;
$_SESSION['user'] = ['id' => 8, 'role' => 1, 'id_societe' => 1];
$_GET = ['import' => $importId];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/admin_crg_integration.php';
$_SERVER['HTTP_HOST'] = 'localhost';

echo "ÉCRAN D'INTÉGRATION — import {$importId}\n";
/**
 * ⚠️ ON REND LA PAGE DANS UNE PORTÉE À PART. Un `include` au niveau global laisse la page
 *    écraser les variables du test : elle définit son propre `$ok`, et le compteur du
 *    harnais devenait une Closure. Le test se cassait sur son propre outillage.
 */
function crgi_rendre_ecran(): string
{
    ob_start();
    include __DIR__ . '/../../admin/admin_crg_integration.php';
    return (string)ob_get_clean();
}
$page = crgi_rendre_ecran();

controle(
    'la page se rend',
    'Une page vide ou tronquée signalerait une erreur fatale silencieuse.',
    function () use ($page) {
        exiger(strlen($page) > 50000, 'page de ' . strlen($page) . ' octets seulement');
        exiger(!str_contains($page, 'Fatal error'), 'la page contient une erreur fatale PHP');
    }
);

controle(
    'la feuille de style est chargée par une URL absolue',
    'Le layout MBI pose un `<base href>` : un `../css/…` y est résolu depuis la racine et part '
    . 'en 404. La page s’était affichée entièrement nue.',
    function () use ($page) {
        exiger(str_contains($page, 'crg_integration.css'), 'la feuille de style est absente');
        exiger(!preg_match('~href="\.\./css/~', $page),
               'un chemin relatif « ../css/ » subsiste');
    }
);

// ── LE JAVASCRIPT DOIT SE CHARGER ─────────────────────────────────────────────────────────
preg_match_all('~<script(?![^>]*src=)[^>]*>(.*?)</script>~s', $page, $m);
$js = implode("\n;\n", $m[1]);

controle(
    'le JavaScript de la page est syntaxiquement valide',
    'Une chaîne non fermée casse le bloc `<script>` ENTIER : plus aucun écouteur ne s’attache, '
    . 'la page reste belle, et tous les boutons deviennent muets sans le moindre signal.',
    function () use ($js) {
        exiger(strlen($js) > 1000, 'aucun script inline trouvé sur la page');
        $noeud = null;
        foreach (['node', 'C:/Program Files/nodejs/node.exe'] as $candidat) {
            $test = @shell_exec(escapeshellarg($candidat) . ' --version 2>&1');
            if ($test && preg_match('~^v\d~', trim((string)$test))) {
                $noeud = $candidat;
                break;
            }
        }
        exiger($noeud !== null, 'node introuvable — le contrôle ne prouverait rien');
        $f = tempnam(sys_get_temp_dir(), 'crgijs_') . '.js';
        file_put_contents($f, $js);
        $sortie = (string)@shell_exec(escapeshellarg($noeud) . ' --check '
                                    . escapeshellarg($f) . ' 2>&1');
        @unlink($f);
        exiger(trim($sortie) === '', 'node refuse le script : ' . mb_substr(trim($sortie), 0, 300));
    }
);

controle(
    'chaque bouton de la page porte son écouteur',
    'Un bouton sans écouteur est un bouton mort. On vérifie l’appariement, pas la présence.',
    function () use ($page, $js) {
        preg_match_all('~id="(btn[A-Za-z0-9]+)"~', $page, $b);
        $boutons = array_unique($b[1]);
        exiger(count($boutons) >= 5, 'trop peu de boutons trouvés : ' . count($boutons));
        $orphelins = [];
        foreach ($boutons as $id) {
            if (!str_contains($js, "getElementById('" . $id . "')")) {
                $orphelins[] = $id;
            }
        }
        exiger(!$orphelins, 'boutons sans écouteur : ' . implode(', ', $orphelins));
    }
);

controle(
    'le bouton de validation du bilan est présent et écouté',
    'C’est le dernier geste d’Emmanuel avant l’intégration : s’il ne répond pas, tout le '
    . 'module est inutilisable — et c’est précisément ce qui s’est produit.',
    function () use ($page, $js) {
        $valide = str_contains($page, 'btnValider5')
               || str_contains($page, 'Validation du bilan avant intégration');
        exiger($valide, 'ni le bouton ni la carte de validation ne sont sur la page');
        if (str_contains($page, 'btnValider5')) {
            exiger(str_contains($js, "getElementById('btnValider5')"),
                   'le bouton de validation n’a pas d’écouteur');
        }
    }
);

controle(
    'REFUS — aucun bouton de la page n’écrit dans les données métier',
    'Cet écran décrit ce qui SERAIT écrit. Aucun de ses boutons ne doit déclencher une '
    . 'intégration.',
    function () use ($js) {
        foreach (['integrer', 'appliquer', 'ecrire'] as $interdit) {
            exiger(!preg_match("~append\('action', '" . $interdit . "'~", $js),
                   "un bouton déclenche l’action « {$interdit} »");
        }
    }
);


controle(
    'chaque ligne d’arbitrage porte un champ pour répondre',
    'On demandait d’arbitrer sans donner où répondre : l’écran posait les questions, listait '
    . 'les choix et leurs conséquences, et n’offrait aucun champ. Un arbitrage qu’on ne peut '
    . 'pas enregistrer n’est pas un arbitrage — c’est un constat qu’on relit indéfiniment.',
    function () use ($page, $js) {
        $cellules = substr_count($page, 'class="crgi-arb"');
        $choix = substr_count($page, 'crgi-arb-choix');
        $precisions = substr_count($page, 'crgi-arb-precision');
        exiger($cellules > 0, 'aucune cellule de décision sur la page');
        exiger($choix >= $cellules, 'des lignes n’ont pas de liste de choix');
        exiger($precisions >= $cellules, 'des lignes n’ont pas de champ de précision');
        exiger(str_contains($js, "append('action', 'arbitrer')"),
               'aucun écouteur n’enregistre les décisions');
        exiger(str_contains($js, "querySelectorAll('.crgi-arb')"),
               'les cellules de décision ne sont pas écoutées');
    }
);

echo "\nÉCRAN : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
