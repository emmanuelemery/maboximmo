<?php
/**
 * LA FILE D'ARBITRAGE — ELLE DOIT SE RENDRE, ET SURTOUT ÊTRE DÉCIDABLE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QUE L'ÉCRAN A POSÉ SA PREMIÈRE QUESTION AVEC QUATRE RÉPONSES
 *    IDENTIQUES. Le 03/09/2026, l'immeuble « 27 rue Ferrer » offrait quatre homonymes MBI —
 *    #563, #781, #850, #885 — sous un seul et même intitulé : « Désigner l'immeuble MBI
 *    existant ». On pouvait cliquer, valider, enchaîner ; la base aurait gardé quatre fois le
 *    même mot. On aurait su qu'Emmanuel avait tranché. Jamais POUR LEQUEL.
 *
 * ⚠️ UN ARBITRAGE VAUT CE QUE VAUT LA PRÉCISION DE CE QU'IL ENREGISTRE. C'est le contrôle
 *    central de ce fichier : deux pistes proposées ne portent jamais le même intitulé.
 *
 * ⚠️ ET LA PREUVE DOIT ÊTRE ATTEIGNABLE. Une question sans son lien vers la page du PDF
 *    renvoie chercher le document à la main — exactement le coût qu'on prétendait supprimer.
 *    La page citée doit en outre exister dans le document : c'est le garde-fou laissé après
 *    le double décalage qui envoyait à la page 1809 d'un PDF de 906 pages.
 *
 * ⚠️ IL NE MODIFIE RIEN. Il rend la page en mémoire, sous une session forgée en lecture.
 *
 * Usage : php crgi_file_arbitrage.php [import_id]
 */
declare(strict_types=1);

require_once __DIR__ . '/../../inc/crg_integration.php';
require_once __DIR__ . '/../../inc/crgi_arbitrage.php';

$pdo = $GLOBALS['pdo'];
$importId = (int)($argv[1] ?? crgi_import_courant($pdo));
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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = $_SESSION['id_user'] = 8;
$_SESSION['role'] = $_SESSION['id_role'] = 1;
$_SESSION['id_societe'] = 1;
$_SESSION['user'] = ['id' => 8, 'role' => 1, 'id_societe' => 1];
$_GET = ['import' => $importId];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/admin_crgi_arbitrage.php';
$_SERVER['HTTP_HOST'] = 'localhost';

// ⚠️ ON REND AVANT D'ÉCRIRE UN SEUL CARACTÈRE. La page appelle `require_admin_or_super_admin()`,
//    qui pose des en-têtes : un titre affiché plus tôt les rend impossibles et noie la sortie
//    du harnais sous deux avis PHP — un harnais bruyant finit par ne plus se lire.
function crgi_rendre_file(): string
{
    ob_start();
    include __DIR__ . '/../../admin/admin_crgi_arbitrage.php';
    return (string)ob_get_clean();
}
$page = crgi_rendre_file();
preg_match_all('~<script(?![^>]*src=)[^>]*>(.*?)</script>~s', $page, $m);
$js = implode("\n", $m[1] ?? []);
$file = crgi_file_arbitrages($pdo, $importId);

echo "FILE D'ARBITRAGE — import {$importId}\n";

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
    'Le layout MBI pose un `<base href>` : un « ../css/… » part en 404 et la page s’affiche nue.',
    function () use ($page) {
        exiger(str_contains($page, 'crg_integration.css'), 'la feuille de style est absente');
        exiger(!preg_match('~href="\.\./css/~', $page), 'un chemin relatif « ../css/ » subsiste');
    }
);

// ── LE CONTRÔLE CENTRAL : UNE DÉCISION DOIT ÊTRE DISCERNABLE ──────────────────────────────
controle(
    'deux pistes proposées ne portent jamais le même intitulé',
    'Quatre homonymes proposés sous « Désigner l’immeuble MBI existant » : la décision '
    . 'enregistrée disait qu’on avait tranché, jamais pour lequel. Un arbitrage indiscernable '
    . 'en base n’est pas un arbitrage, c’est une trace de clic.',
    function () use ($pdo, $importId, $file) {
        $vus = 0;
        foreach ($file as $a) {
            $intitules = array_column(crgi_propositions($pdo, $importId, $a), 'choix');
            exiger(count($intitules) === count(array_unique($intitules)),
                   'doublon d’intitulé sur « ' . $a['groupe'] . ' » cible '
                   . $a['cible_id'] . ' : ' . implode(' / ', $intitules));
            $vus++;
        }
        exiger($vus > 0, 'aucun arbitrage examiné — la file est-elle vide ?');
    }
);

controle(
    'l’option générique n’est jamais réaffichée à côté de ses instances',
    'Rouvrir « Désigner l’immeuble MBI existant » sous les quatre pistes nommées rendrait à '
    . 'nouveau possible la décision floue qu’on vient de fermer.',
    function () use ($page) {
        $lignes = [];
        preg_match_all('~<input type="radio" name="choix" value="([^"]*)"~', $page, $r);
        foreach ($r[1] ?? [] as $v) {
            $lignes[] = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
        }
        exiger($lignes !== [], 'aucune proposition sur la page');
        foreach ($lignes as $v) {
            if ($v === '__LIBRE__' || !str_starts_with($v, 'Désigner l’immeuble MBI')) {
                continue;
            }
            exiger((bool)preg_match('~#\d+$~', $v),
                   'une option d’immeuble ne nomme pas sa cible : « ' . $v . ' »');
        }
    }
);

// ── LA PREUVE ────────────────────────────────────────────────────────────────────────────
controle(
    'chaque arbitrage porte son lien vers la page du CRG',
    'Sans lien, il faut rouvrir le PDF et chercher : c’est exactement le coût qu’on prétendait '
    . 'supprimer. La preuve doit être à un clic.',
    function () use ($page) {
        exiger((bool)preg_match('~crgi_page\.php\?crg=(\d+)#page=(\d+)~', $page, $x),
               'aucun lien de preuve sur la page');
        exiger((int)$x[1] > 0, 'le lien de preuve ne désigne aucun CRG');
        exiger((int)$x[2] > 0, 'le lien de preuve ne désigne aucune page');
        // ⚠️ ON EXIGE UN BOUTON DE PREUVE, PAS UNE FORMULE. Un conflit d'identité en porte
        //    DEUX — « Voir preuve A » et « Voir preuve B » —, et exiger la formule du cas
        //    simple aurait fait échouer le test sur l'écran le mieux outillé des deux.
        exiger(str_contains($page, 'Voir dans le CRG') || str_contains($page, 'Voir preuve'),
               'le bouton de preuve n’est pas nommé');
    }
);

controle(
    'aucune page citée par la file n’est hors de son document',
    'Un double décalage envoyait à la page 1809 d’un PDF de 906 pages : le lien s’ouvrait sur '
    . 'du vide, et la question devenait invérifiable.',
    function () use ($pdo, $importId, $file) {
        $st = $pdo->prepare('SELECT c.id, p.nb_pages FROM crgi_crg c
                               JOIN crgi_piece p ON p.id = c.piece_id WHERE c.import_id = ?');
        $st->execute([$importId]);
        $bornes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bornes[(int)$r['id']] = (int)$r['nb_pages'];
        }
        foreach ($file as $a) {
            $c = $a['contexte'];
            $page = (int)($c['page'] ?? 0);
            $max = $bornes[(int)($c['crg_id'] ?? 0)] ?? 0;
            if ($page === 0 || $max === 0) {
                continue;
            }
            exiger($page <= $max, 'page ' . $page . ' citée dans un document de '
                                  . $max . ' pages (cible ' . $a['cible_id'] . ')');
        }
    }
);

// ── LES GESTES ───────────────────────────────────────────────────────────────────────────
controle(
    '« Ma proposition » est toujours offerte',
    'Un écran qui n’offre que des cases force la réponse dans les catégories du moteur — or '
    . 'c’est justement quand aucune ne convient qu’on apprend quelque chose.',
    function () use ($page, $js) {
        exiger(str_contains($page, 'id="maProposition"'), 'le champ libre est absent');
        exiger(str_contains($page, 'value="__LIBRE__"'), 'le choix libre n’est pas sélectionnable');
        exiger(str_contains($js, "maProp?.value"), 'le champ libre n’est pas relu à l’envoi');
    }
);

controle(
    'les quatre gestes ont chacun leur écouteur',
    'Une chaîne JavaScript non fermée a déjà tué tous les boutons d’un écran, en silence, sans '
    . 'une ligne de log : la page reste belle et ne répond plus.',
    function () use ($page, $js) {
        foreach (['btnSuivant', 'btnValider', 'btnIndet', 'btnReporter'] as $b) {
            exiger(str_contains($page, 'id="' . $b . '"'), "le bouton {$b} est absent");
            exiger(str_contains($js, "getElementById('" . $b . "')"),
                   "le bouton {$b} n’a pas d’écouteur");
        }
        exiger(str_contains($js, "append('action', 'decider')"),
               'aucun geste n’enregistre de décision');
        exiger(str_contains($js, 'csrf_token'), 'les décisions partent sans jeton CSRF');
    }
);

controle(
    'le temps humain est mesuré, pas estimé',
    'Le KPI « interventions / 100 CRG » ne vaut que si la durée vient de l’écran. Une durée '
    . 'estimée après coup ferait un indicateur qui se contemple au lieu de se mesurer.',
    function () use ($js) {
        exiger(str_contains($js, 'Date.now()'), 'aucune horloge sur la fiche');
        exiger(str_contains($js, "append('secondes'"), 'la durée n’est pas transmise');
    }
);

// ── L'EXTENSION À UN GROUPE ──────────────────────────────────────────────────────────────
controle(
    'l’extension à un groupe n’est jamais cochée d’avance',
    'Une décision qui se propagerait par défaut appliquerait à des centaines de lignes un '
    . 'arbitrage rendu sur une seule. `RIEN NE SE PROPAGE EN SILENCE`.',
    function () use ($page) {
        if (!str_contains($page, 'id="appliquerGroupe"')) {
            return;   // cet arbitrage-ci n’a pas de groupe semblable : rien à défendre
        }
        exiger((bool)preg_match('~id="appliquerGroupe"([^>]*)>~', $page, $x),
               'la case de groupe est malformée');
        exiger(!str_contains($x[1], 'checked'), 'la case de groupe est cochée par défaut');
    }
);

controle(
    'un groupe annonce son critère et son nombre avant le clic',
    'Étendre une décision sans dire à quoi ni à combien, c’est demander une signature en blanc.',
    function () use ($pdo, $importId, $file) {
        $vus = 0;
        foreach ($file as $a) {
            $g = crgi_groupe_semblable($pdo, $importId, $a);
            if (!$g) {
                continue;
            }
            exiger(trim((string)$g['critere']) !== '', 'un groupe sans critère écrit');
            exiger((int)$g['nombre'] > 0, 'un groupe sans nombre de lignes');
            exiger(isset($g['sql']['section'], $g['sql']['colonne'], $g['sql']['maille']),
                   'le critère affiché n’est pas rejouable côté serveur');
            $vus++;
        }
        echo "       ({$vus} arbitrages porteurs d’un groupe semblable)\n";
    }
);

// ── LES CONFLITS D'IDENTITÉ, SUR FIXTURE SYNTHÉTIQUE ─────────────────────────────────────
// ⚠️ AUCUN NOM DU CORPUS ICI. Un test bâti sur « le cas connu » prouverait qu'on se souvient,
//    pas qu'on a compris — et il passerait encore le jour où le moteur aurait tout oublié
//    sauf cette exception-là. La fixture fabrique le PHÉNOMÈNE : une même clé d'identité,
//    deux valeurs. Elle vit dans un import jetable, purgé quoi qu'il arrive.
controle(
    'un conflit d’identité fabriqué devient une question, jamais une fusion',
    'Le moteur détectait un conflit d’occupant, le harnais virait au rouge, et l’écran ne '
    . 'posait aucune question : Emmanuel voyait un défaut sans porte de sortie. Et l’autre '
    . 'issue — rapprocher deux noms voisins — aurait créé une identité par approximation.',
    function () use ($pdo) {
        $bac = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 9000 FROM crgi_import')
                        ->fetchColumn();
        try {
            $pdo->prepare('INSERT INTO crgi_piece (id, import_id, nom_original, chemin, sha256,
                                                   nb_pages, etat)
                           VALUES (?, ?, "fixture.pdf", "", REPEAT("0", 64), 2, "LU")')
                ->execute([$bac, $bac]);
            $ins = $pdo->prepare(
                'INSERT INTO crgi_crg (id, import_id, piece_id, compte, proprietaire, agence,
                                       format, periode_cle, date_arrete, page_debut, page_fin)
                 VALUES (?, ?, ?, "9999999999", "PROPRIO FIXTURE", "AF - FIXTURE", "fixture",
                         "2026-T2", "2026-06-30", ?, ?)');
            $ins->execute([$bac, $bac, $bac, 1, 1]);
            $ins->execute([$bac + 1, $bac, $bac, 2, 2]);
            $occ = $pdo->prepare(
                'INSERT INTO crgi_occupation (import_id, crg_id, lot_reference, locataire, rang,
                                              date_arrete, periode_cle, page, statut)
                 VALUES (?, ?, "FIXT-01", ?, 0, "2026-06-30", "2026-T2", ?, "LU")');
            $occ->execute([$bac, $bac, 'ALPHA Camille', 1]);
            $occ->execute([$bac, $bac + 1, 'ALPMA Camille', 2]);

            $conflits = crgi_conflits_identite($pdo, $bac);
            exiger(count($conflits) === 1, count($conflits) . ' conflits au lieu d’un');
            $c = $conflits[0];
            exiger($c['type'] === 'CONFLIT_OCCUPANT', $c['type']);
            exiger(count($c['lectures']) === 2, 'les deux lectures ne sont pas rendues');
            foreach ($c['lectures'] as $l) {
                exiger((int)$l['crg_id'] > 0 && (int)$l['page'] > 0,
                       'une lecture sans preuve atteignable');
            }
            $pages = array_column($c['lectures'], 'page');
            exiger(count(array_unique($pages)) === 2,
                   'les deux côtés pointent la même page : la preuve ne distingue rien');

            // La question doit exister, et porter les deux issues sans en imposer aucune.
            $file = crgi_file_arbitrages($pdo, $bac, ['groupe' => 'IDENTITE-OCCUPANT']);
            exiger(count($file) === 1, count($file) . ' questions au lieu d’une');
            $choix = array_keys($file[0]['choix']);
            exiger(in_array('Même identité', $choix, true)
                   && in_array('Identités différentes', $choix, true), implode(' / ', $choix));

            // La proximité MESURE, elle ne conclut pas : les deux issues restent proposées.
            $props = crgi_propositions($pdo, $bac, $file[0]);
            $intitules = array_column($props, 'choix');
            exiger(in_array('Même identité', $intitules, true), implode(' / ', $intitules));
            exiger(in_array('Identités différentes', $intitules, true), implode(' / ', $intitules));
            foreach ($props as $p) {
                exiger($p['confiance'] < 100,
                       'une proposition à 100 % ferme la question au lieu de l’ouvrir');
            }
            exiger(count($intitules) === count(array_unique($intitules)),
                   'deux pistes portent le même intitulé : la décision serait indiscernable');
        } finally {
            // ⚠️ LE BALAYAGE SE DÉDUIT DU SCHÉMA. Une liste écrite à la main laisserait un jour
            //    la fixture derrière elle, et le prochain import compterait des lignes fantômes.
            foreach (crgi_tables_de_staging($pdo) as $t) {
                $pdo->prepare('DELETE FROM `' . $t . '` WHERE import_id = ?')->execute([$bac]);
            }
        }
        $reste = 0;
        foreach (crgi_tables_de_staging($pdo) as $t) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `' . $t . '` WHERE import_id = ?');
            $st->execute([$bac]);
            $reste += (int)$st->fetchColumn();
        }
        exiger($reste === 0, $reste . ' lignes de fixture ont survécu au nettoyage');
    }
);

// ── LE TABLEAU DE BORD ───────────────────────────────────────────────────────────────────
controle(
    'le tableau de bord répond aux six questions, sans en inventer aucune',
    'L’avancement était lisible mais pas visible : la frise disait où on en était, il fallait '
    . 'ouvrir le diagnostic pour savoir si ça allait bien et la file pour savoir s’il fallait '
    . 'intervenir. Trois écrans pour une seule question : « dois-je m’en occuper ? ».',
    function () use ($pdo, $importId) {
        require_once __DIR__ . '/../../inc/crgi_pilotage.php';
        $p = crgi_pilotage($pdo, $importId);
        foreach (['crg', 'pages', 'pages_lues', 'mouvements'] as $k) {
            exiger(isset($p['volumes'][$k]), 'volume manquant : ' . $k);
        }
        foreach (['pages', 'compris', 'relies', 'qualifies'] as $k) {
            exiger(isset($p['taux'][$k]) && $p['taux'][$k] >= 0 && $p['taux'][$k] <= 100,
                   'taux hors bornes : ' . $k . ' = ' . var_export($p['taux'][$k] ?? null, true));
        }
        exiger(in_array($p['statut'], ['EN COURS', 'EN ATTENTE D’ARBITRAGE', 'PRÊTE',
                                       'ÉCHEC', 'ANNULÉE'], true), $p['statut']);
        exiger(count($p['parcours']) === count(CRGI_PARCOURS), 'parcours incomplet');
        // ⚠️ `isset()` MENT SUR UN NULL VOULU. Le temps machine vaut NULL quand personne ne
        //    l'a mesuré — c'est une information, pas une absence de clé. Confondre les deux
        //    ferait remplacer « non mesuré » par « zéro », et un zéro s'optimise.
        exiger(array_key_exists('machine', $p['temps']), 'clé « machine » absente');
        exiger(array_key_exists('humain', $p['temps']), 'clé « humain » absente');
        exiger($p['temps']['machine'] === null || $p['temps']['machine'] >= 0,
               'temps machine incohérent');
    }
);

controle(
    'aucun taux ne se calcule sur un dénominateur absent',
    'Un pourcentage bâti sur une population vide vaut soit 0 %, soit une division par zéro : '
    . 'les deux mentent. Un écran de pilotage qui affiche « 0 % compris » sur un import vide '
    . 'ferait chercher une panne du moteur.',
    function () use ($pdo, $importId) {
        $p = crgi_pilotage($pdo, $importId);
        foreach ($p['familles'] as $f) {
            exiger($f['detectes'] >= 0, $f['famille']);
            if ($f['detectes'] === 0) {
                exiger($f['taux'] === 100.0,
                       'population vide affichée à ' . $f['taux'] . ' % : ' . $f['famille']);
            }
            // ⚠️ ATTENDU = AUTOMATIQUES + EN ARBITRAGE. Le reste serait inexpliqué.
            exiger($f['detectes'] - $f['auto'] - $f['attente'] === 0,
                   $f['famille'] . ' : ' . ($f['detectes'] - $f['auto'] - $f['attente'])
                   . ' objets inexpliqués');
        }
    }
);

controle(
    'une perte silencieuse est un objet SANS question, pas un objet en attente',
    'Compter les objets en attente comme des pertes aurait affiché 302 pertes sur un dépôt '
    . 'parfaitement sain : le KPI serait devenu du bruit dès le premier import, et personne '
    . 'ne l’aurait plus regardé.',
    function () use ($pdo, $importId) {
        $p = crgi_pilotage($pdo, $importId);
        $enAttente = 0;
        foreach ($p['familles'] as $f) {
            $enAttente += $f['attente'];
        }
        exiger($enAttente > 0, 'aucun objet en attente : le contrôle ne prouve rien ici');
        $pertes = array_sum(array_column($p['pertes'], 'n'));
        exiger($pertes < $enAttente,
               $pertes . ' pertes pour ' . $enAttente . ' objets en attente : le KPI compte '
               . 'du travail en cours');
    }
);

controle(
    'le tableau de bord mène à la file, et la file ramène au tableau de bord',
    'Un écran de pilotage sans porte de sortie oblige à retenir une URL ; une file sans retour '
    . 'laisse Emmanuel devant une page vide après le dernier arbitrage.',
    function () use ($importId) {
        $bord = (string)file_get_contents(__DIR__ . '/../../inc/crgi_pilotage_vue.php');
        exiger(str_contains($bord, 'admin_crgi_arbitrage.php'),
               'le tableau de bord n’ouvre pas la file');
        exiger(str_contains($bord, 'Traiter les'), 'le bouton principal est absent');
        exiger(str_contains($bord, 'Aucun arbitrage'),
               'aucun message quand il n’y a rien à arbitrer');
        $file = (string)file_get_contents(__DIR__ . '/../../admin/admin_crgi_arbitrage.php');
        exiger(str_contains($file, 'admin_crg_integration.php'),
               'la file ne ramène pas au tableau de bord');
        exiger(str_contains($file, 'j.reste === 0'),
               'le dernier arbitrage ne déclenche aucun retour');
    }
);

// ── AUCUNE ÉCRITURE MÉTIER ───────────────────────────────────────────────────────────────
controle(
    'décider n’écrit rien dans le métier',
    '`DÉCIDER N’EST PAS INTÉGRER` : un arbitrage vit dans `crgi_arbitrage`. Une écriture métier '
    . 'depuis cet écran contournerait les phases et leurs empreintes.',
    function () {
        $src = file_get_contents(__DIR__ . '/../../inc/crgi_arbitrage.php')
             . file_get_contents(__DIR__ . '/../../admin/admin_crgi_arbitrage.php');
        // ⚠️ « ON DUPLICATE KEY UPDATE choix » N'EST PAS UN UPDATE DE TABLE. Sans cette garde,
        //    le contrôle lisait « UPDATE choix » et accusait une écriture métier sur une table
        //    nommée `choix` qui n'existe pas : un test qui crie au loup finit ignoré.
        preg_match_all('~\b(INSERT\s+INTO|(?<!KEY )UPDATE|DELETE\s+FROM)\s+`?([a-z_]+)`?~i',
                       $src, $t, PREG_SET_ORDER);
        foreach ($t as $x) {
            $table = strtolower($x[2]);
            exiger(str_starts_with($table, 'crgi_'),
                   'écriture hors staging : ' . strtoupper($x[1]) . ' ' . $table);
        }
    }
);

echo "\nFILE ARBITRAGE : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
