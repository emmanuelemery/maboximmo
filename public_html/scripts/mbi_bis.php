<?php
declare(strict_types=1);
/**
 * mbi_bis.php — LE BAC À SABLE D'ENTRAÎNEMENT DE L'AGENT D'INTÉGRATION CRG.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE POUR QUE L'AGENT NE PUISSE PAS TRICHER, ET POUR QUE MOI NON PLUS.
 *    Jusqu'ici, le moteur travaillait en `root@localhost` avec `GRANT ALL ON *.*` : la
 *    lecture seule des données métier ne tenait qu'à la vigilance de celui qui tapait les
 *    commandes. Elle a cédé deux fois en une séance — une capacité `-table` déduite d'un nom
 *    de produit, une liste de tables arrêtée six migrations plus tôt. On ne demande donc plus
 *    à personne d'être prudent : on retire le droit.
 *
 * ⚠️ TROIS ZONES, ET C'EST LA BASE QUI LES FAIT RESPECTER.
 *
 *      ÉCRITURE      `mbi_bis`            le staging et la reconstruction
 *      LECTURE SEULE `maboximmo`          une LISTE BLANCHE de tables métier, pour
 *                                         confronter — jamais pour deviner
 *      INATTEIGNABLE `maboximmo.crg_*`    le référentiel certifié : ce sont LES RÉPONSES
 *
 * ⚠️ DEUX IDENTITÉS, PARCE QUE CELUI QUI PRODUIT NE PEUT PAS ÊTRE CELUI QUI JUGE.
 *
 *      `mbi_agent`   reconstruit. Il ne voit JAMAIS le référentiel certifié.
 *      `mbi_scorer`  note. Il lit `mbi_bis` ET l'oracle, et n'écrit nulle part.
 *
 *    Le moteur ne possède pas le mot de passe du scorer : les deux identifiants vivent dans
 *    deux fichiers séparés, hors du dépôt et hors de la racine web.
 *
 * ⚠️ ET L'EXAMEN N'EST PAS INTERACTIF. `exam` enchaîne RESET → contrôle des droits → hash du
 *    code → import → score, sans reprendre la main. Tant qu'il tourne, personne ne corrige :
 *    c'est la seule façon de mesurer ce que le moteur SAIT, et non ce que je sais lui souffler.
 *
 * Usage :
 *   php mbi_bis.php droits     crée/actualise `mbi_bis` et les deux comptes (exige root)
 *   php mbi_bis.php schema     régénère le schéma versionné depuis `maboximmo`
 *   php mbi_bis.php reset      détruit et recrée `mbi_bis` depuis le schéma versionné
 *   php mbi_bis.php etanche    PROUVE l'étanchéité — ce que chaque compte peut et ne peut pas
 *   php mbi_bis.php exam <corpus>   une passe d'examen, non interactive
 *   php mbi_bis.php score      confronte `mbi_bis` à l'oracle et rend la feuille de score
 */

const BIS_BASE     = 'mbi_bis';
const BIS_SOURCE   = 'maboximmo';
const BIS_SECRETS  = 'C:/Users/emery/.mbi';
const BIS_SCHEMA   = __DIR__ . '/mbi_bis/schema.sql';
const BIS_RAPPORTS = __DIR__ . '/mbi_bis/rapports';

/**
 * ⚠️ LA LISTE BLANCHE EST ÉCRITE ICI, EN CLAIR, ET NULLE PART AILLEURS. Un `GRANT SELECT ON
 *    maboximmo.*` aurait donné l'oracle avec le reste : c'est précisément ce qu'on interdit.
 *    Toute table absente de cette liste est inatteignable pour l'agent, y compris une table
 *    créée demain.
 */
const BIS_LISTE_BLANCHE = [
    'agences',                   // le référentiel des agences : configuration, pas réponse
    'proprietaires',
    'proprietaire_comptes_crg',
    'immeubles',
    'biens',
    'bien_baux',
    'tiers',
    // ⚠️ `crg_trimestres` EST UN INVENTAIRE, PAS UNE RÉPONSE — et l'exclure bloquait la
    //    phase 1 entière. Le filtre portait sur le préfixe `crg_*` parce que DEUX de ces
    //    tables sont l'oracle : `crg_extractions` et `crg_situations_locataires`, qui
    //    contiennent le CONTENU extrait des documents. `crg_trimestres`, elle, ne porte que
    //    des périodes par compte mandant : c'est la réponse à « MBI connaît-il déjà ce
    //    trimestre ? », qui est précisément la question de l'inventaire. Un préfixe n'est pas
    //    un critère de confidentialité ; la liste blanche se raisonne table par table.
    'crg_trimestres',
];

/** Le staging du module d'intégration, répliqué à l'identique dans le bac à sable. */
const BIS_STAGING = 'crgi\_%';

/**
 * ⚠️ LES TABLES QUE L'AGENT NE DOIT JAMAIS POUVOIR LIRE. Elles portent le résultat certifié
 *    de LYON, EMERY et VIENNE : 517 extractions, 26 711 écritures, 3 226 situations. Les lui
 *    donner, même en lecture, c'est lui donner le corrigé de l'examen.
 */
const BIS_ORACLE = 'crg\_%';

function sortie(string $m = ''): void { echo $m, "\n"; }
function titre(string $m): void { sortie(''); sortie('══ ' . $m); }

/** La connexion `root`, pour les seules opérations d'administration du bac à sable. */
function pdo_root(): PDO
{
    require_once __DIR__ . '/../config/db.php';
    return db();
}

/**
 * Un mot de passe qu'aucun humain n'a besoin de retenir — et qui n'est pas dans le dépôt.
 *
 * ⚠️ LES DEUX SECRETS VIVENT DANS DEUX FICHIERS DISTINCTS. Le moteur ne lit que le sien : si
 *    les deux étaient dans un même fichier de configuration, la séparation agent/scorer ne
 *    serait qu'une convention de nommage.
 */
function secret(string $compte, bool $creer = false): string
{
    @mkdir(BIS_SECRETS, 0700, true);
    $f = BIS_SECRETS . '/' . $compte . '.pass';
    if ($creer || !is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(18)));
    }
    return trim((string)file_get_contents($f));
}

function pdo_compte(string $compte, ?string $base = null): PDO
{
    $dsn = 'mysql:host=127.0.0.1;charset=utf8mb4' . ($base ? ';dbname=' . $base : '');
    return new PDO($dsn, $compte, secret($compte), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  DROITS — la matrice, appliquée par la base
// ══════════════════════════════════════════════════════════════════════════════════════════
function cmd_droits(): int
{
    $pdo = pdo_root();
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . BIS_BASE . '` '
             . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    sortie('base `' . BIS_BASE . '` prête');

    foreach (['mbi_agent', 'mbi_scorer'] as $compte) {
        $mdp = secret($compte, true);
        $pdo->exec("CREATE USER IF NOT EXISTS '$compte'@'localhost' IDENTIFIED BY " . $pdo->quote($mdp));
        $pdo->exec("ALTER USER '$compte'@'localhost' IDENTIFIED BY " . $pdo->quote($mdp));
        // ⚠️ ON RÉVOQUE AVANT D'ACCORDER. Sans cela, un droit donné lors d'un essai
        //    précédent survivrait à la matrice qu'on croit poser — et l'étanchéité serait
        //    une opinion, pas un état.
        try { $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$compte'@'localhost'"); }
        catch (Throwable $e) { /* aucun droit à révoquer : c'est très bien */ }
    }

    // ── L'AGENT : il écrit son bac à sable, il lit une liste blanche, rien d'autre. ────────
    $pdo->exec("GRANT ALL PRIVILEGES ON `" . BIS_BASE . "`.* TO 'mbi_agent'@'localhost'");
    foreach (BIS_LISTE_BLANCHE as $t) {
        $pdo->exec("GRANT SELECT ON `" . BIS_SOURCE . "`.`$t` TO 'mbi_agent'@'localhost'");
    }
    sortie('mbi_agent  : ALL sur ' . BIS_BASE . ' · SELECT sur '
         . count(BIS_LISTE_BLANCHE) . ' tables de ' . BIS_SOURCE . ' · RIEN sur crg_*');

    // ── LE SCORER : il ne produit rien. Il lit le bac à sable ET l'oracle, et il note. ────
    $pdo->exec("GRANT SELECT ON `" . BIS_BASE . "`.* TO 'mbi_scorer'@'localhost'");
    $pdo->exec("GRANT SELECT ON `" . BIS_SOURCE . "`.* TO 'mbi_scorer'@'localhost'");
    sortie('mbi_scorer : SELECT sur ' . BIS_BASE . ' et ' . BIS_SOURCE . ' · aucune écriture');

    $pdo->exec('FLUSH PRIVILEGES');
    titre('la matrice, telle que la base la rend');
    foreach (['mbi_agent', 'mbi_scorer'] as $compte) {
        foreach ($pdo->query("SHOW GRANTS FOR '$compte'@'localhost'")->fetchAll(PDO::FETCH_COLUMN) as $g) {
            sortie('  ' . $g);
        }
        sortie('');
    }
    return 0;
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  SCHEMA — versionné, donc reproductible
// ══════════════════════════════════════════════════════════════════════════════════════════
function cmd_schema(): int
{
    $pdo = pdo_root();
    @mkdir(dirname(BIS_SCHEMA), 0775, true);

    $sql = "-- mbi_bis/schema.sql — GÉNÉRÉ par `php mbi_bis.php schema`, ne pas éditer à la main.\n"
         . "-- ⚠️ Le schéma est VERSIONNÉ pour que `reset` reconstruise une base identique au\n"
         . "--    bit près. Un RESET qui ne reproduit pas exactement la même structure ne\n"
         . "--    prouve rien : la passe suivante ne serait pas comparable à la précédente.\n"
         . "-- source : " . BIS_SOURCE . "\n\n"
         . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    // ── LE STAGING : de vraies tables, que l'agent remplit et que le RESET détruit. ───────
    foreach ($pdo->query('SHOW TABLES LIKE "' . BIS_STAGING . '"')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $ddl = $pdo->query('SHOW CREATE TABLE `' . BIS_SOURCE . '`.`' . $t . '`')
                   ->fetch(PDO::FETCH_ASSOC)['Create Table'] ?? null;
        if ($ddl === null) {
            continue;
        }
        // L'auto-increment courant n'est pas de la structure : le figer rendrait le schéma
        // dépendant de l'état du moment.
        $sql .= "-- $t — staging du module d’intégration\n"
              . preg_replace('~\s+AUTO_INCREMENT=\d+~', '', $ddl) . ";\n\n";
    }

    // ── LA LISTE BLANCHE : DES VUES, PAS DES COPIES. ─────────────────────────────────────
    // ⚠️ MA PREMIÈRE VERSION CRÉAIT DES TABLES VIDES, ET ELLE ÉTAIT FAUSSE. Les requêtes du
    //    moteur ne préfixent pas leur base : pointé sur `mbi_bis`, il aurait confronté ses
    //    lectures à un miroir VIDE et déclaré tout le portefeuille « nouveau ». Une vue lit
    //    la vraie table de `maboximmo` sans la copier — donc sans jamais s'en écarter.
    //
    // ⚠️ `SQL SECURITY INVOKER` EST LA CLÉ DE L'ÉTANCHÉITÉ. Une vue en `DEFINER` s'exécute
    //    avec les droits de son créateur — `root` — et donnerait à l'agent tout ce que root
    //    peut lire, oracle compris, en contournant la matrice. En `INVOKER`, ce sont les
    //    droits de l'AGENT qui s'appliquent : la vue ne peut jamais lui ouvrir plus que sa
    //    liste blanche, et une vue sur `crg_*` resterait refusée.
    //
    // ⚠️ ET UNE VUE EST EN LECTURE SEULE POUR LUI : il n'a pas l'INSERT sur la table de base.
    //    La reconstruction va donc dans des tables `bis_*` à elle, jamais dans le miroir.
    foreach (BIS_LISTE_BLANCHE as $t) {
        $sql .= "-- $t — VUE en lecture seule sur " . BIS_SOURCE . ", pour la confrontation\n"
              . "CREATE OR REPLACE SQL SECURITY INVOKER VIEW `$t` AS "
              . "SELECT * FROM `" . BIS_SOURCE . "`.`$t`;\n\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    file_put_contents(BIS_SCHEMA, $sql);
    sortie('schéma écrit : ' . BIS_SCHEMA);
    sortie(sprintf('  %d tables de staging · %d vues de liste blanche · sha256 %s',
                   preg_match_all('~^CREATE TABLE~m', $sql),
                   preg_match_all('~^CREATE OR REPLACE~m', $sql),
                   hash('sha256', $sql)));
    return 0;
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  RESET — destruction totale, recréation à l'identique
// ══════════════════════════════════════════════════════════════════════════════════════════
function cmd_reset(): int
{
    if (!is_file(BIS_SCHEMA)) {
        sortie('SCHÉMA ABSENT — lancer d’abord `php mbi_bis.php schema`.');
        return 1;
    }
    $pdo = pdo_root();

    // ⚠️ ON NE DÉTRUIT QUE `mbi_bis`, ET LE NOM EST UNE CONSTANTE. Un RESET paramétrable par
    //    la ligne de commande finirait un jour par recevoir « maboximmo ».
    $pdo->exec('DROP DATABASE IF EXISTS `' . BIS_BASE . '`');
    $pdo->exec('CREATE DATABASE `' . BIS_BASE . '` '
             . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . BIS_BASE . '`');
    // ⚠️ ON RETIRE LES COMMENTAIRES AVANT DE DÉCOUPER, PAS APRÈS. Chaque DDL est précédée
    //    d'une ligne `-- table — rôle` : découper d'abord faisait commencer chaque morceau
    //    par ce commentaire, et le filtre « ignorer ce qui commence par -- » jetait
    //    l'instruction avec lui. Résultat : un RESET qui annonçait « base recréée » avec
    //    ZÉRO table. Une base vide qui se déclare prête est exactement le genre de silence
    //    que ce bac à sable existe pour interdire — d'où le contrôle ci-dessous.
    $sql = (string)file_get_contents(BIS_SCHEMA);
    $sql = preg_replace('~^\s*--.*$~m', '', $sql);
    $ordres = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($ordres as $ordre) {
        $pdo->exec($ordre);
    }

    // Les dépôts et rapports de la passe s'en vont avec elle.
    $data = __DIR__ . '/../data/mbi_bis';
    if (is_dir($data)) {
        foreach (glob($data . '/*') ?: [] as $f) {
            is_dir($f) ? rmdir_recursif($f) : @unlink($f);
        }
    }
    @mkdir($data, 0775, true);

    // ⚠️ UN RESET QUI NE RECRÉE RIEN N'EST PAS UN RESET. On exige le compte attendu, sinon
    //    la passe suivante travaillerait sur une base incomplète en croyant repartir de zéro.
    // On compte séparément les tables et les vues : une vue manquante casserait la
    // confrontation en silence, une table manquante casserait le staging.
    $objets = $pdo->query(
        'SELECT table_type t, COUNT(*) n FROM information_schema.tables
          WHERE table_schema = ' . $pdo->quote(BIS_BASE) . ' GROUP BY t'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $tables = (int)($objets['BASE TABLE'] ?? 0);
    $vues   = (int)($objets['VIEW'] ?? 0);
    $aTables = preg_match_all('~^CREATE TABLE~m', $sql);
    $aVues   = preg_match_all('~^CREATE OR REPLACE~m', $sql);
    if ($tables !== $aTables || $vues !== $aVues) {
        sortie(sprintf('RESET INCOMPLET — %d/%d tables et %d/%d vues.',
                       $tables, $aTables, $vues, $aVues));
        return 1;
    }
    sortie(sprintf('RESET fait — `%s` recréée : %d tables de staging (0 ligne) '
                 . '+ %d vues de confrontation', BIS_BASE, $tables, $vues));
    sortie('  schéma sha256 ' . hash_file('sha256', BIS_SCHEMA));
    sortie('  INTACTS : ' . BIS_SOURCE . ' · les PDF sources · le code · le registre · les tests');
    return 0;
}

function rmdir_recursif(string $d): void
{
    foreach (glob($d . '/*') ?: [] as $f) {
        is_dir($f) ? rmdir_recursif($f) : @unlink($f);
    }
    @rmdir($d);
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  ÉTANCHE — la preuve, par l'échec
// ══════════════════════════════════════════════════════════════════════════════════════════
/**
 * ⚠️ UNE FRONTIÈRE NE SE DÉCRIT PAS, ELLE SE HEURTE. On ne se contente pas de lire
 *    `SHOW GRANTS` : on TENTE chaque interdit et on exige l'erreur 1142. Un droit mal
 *    révoqué se voit ici, et nulle part ailleurs.
 */
function cmd_etanche(): int
{
    $essais = [];
    $ajout = function (string $quoi, string $attendu, callable $fn) use (&$essais) {
        try {
            $fn();
            $essais[] = [$quoi, $attendu, 'PASSÉ', $attendu === 'AUTORISÉ'];
        } catch (Throwable $e) {
            $refus = str_contains($e->getMessage(), '1142') || str_contains($e->getMessage(), 'denied');
            $essais[] = [$quoi, $attendu, $refus ? 'REFUSÉ (1142)' : 'ERREUR : '
                       . substr($e->getMessage(), 0, 60), $attendu === 'INTERDIT' && $refus];
        }
    };

    $agent  = pdo_compte('mbi_agent', BIS_BASE);
    $scorer = pdo_compte('mbi_scorer', BIS_BASE);

    $ajout('agent  : écrire dans mbi_bis', 'AUTORISÉ', fn() =>
        $agent->exec('CREATE TABLE IF NOT EXISTS _essai_etancheite (id INT)'));
    $ajout('agent  : lire la liste blanche (proprietaires)', 'AUTORISÉ', fn() =>
        $agent->query('SELECT COUNT(*) FROM `' . BIS_SOURCE . '`.proprietaires')->fetchColumn());
    $ajout('agent  : lire l’ORACLE (crg_extractions)', 'INTERDIT', fn() =>
        $agent->query('SELECT COUNT(*) FROM `' . BIS_SOURCE . '`.crg_extractions')->fetchColumn());
    $ajout('agent  : lire l’ORACLE (crg_situations_locataires)', 'INTERDIT', fn() =>
        $agent->query('SELECT COUNT(*) FROM `' . BIS_SOURCE . '`.crg_situations_locataires')->fetchColumn());
    $ajout('agent  : ÉCRIRE dans le métier (proprietaires)', 'INTERDIT', fn() =>
        $agent->exec('UPDATE `' . BIS_SOURCE . '`.proprietaires SET nom = nom WHERE id = 0'));
    $ajout('agent  : SUPPRIMER dans le métier (biens)', 'INTERDIT', fn() =>
        $agent->exec('DELETE FROM `' . BIS_SOURCE . '`.biens WHERE id = 0'));
    $ajout('agent  : lire une table métier HORS liste blanche (audit_log)', 'INTERDIT', fn() =>
        $agent->query('SELECT COUNT(*) FROM `' . BIS_SOURCE . '`.audit_log')->fetchColumn());
    $ajout('scorer : lire mbi_bis', 'AUTORISÉ', fn() =>
        $scorer->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '
                     . $scorer->quote(BIS_BASE))->fetchColumn());
    $ajout('scorer : lire l’ORACLE', 'AUTORISÉ', fn() =>
        $scorer->query('SELECT COUNT(*) FROM `' . BIS_SOURCE . '`.crg_extractions')->fetchColumn());
    $ajout('scorer : ÉCRIRE dans mbi_bis', 'INTERDIT', fn() =>
        $scorer->exec('CREATE TABLE _essai_scorer (id INT)'));
    $ajout('scorer : ÉCRIRE dans le métier', 'INTERDIT', fn() =>
        $scorer->exec('UPDATE `' . BIS_SOURCE . '`.proprietaires SET nom = nom WHERE id = 0'));

    try { $agent->exec('DROP TABLE IF EXISTS _essai_etancheite'); } catch (Throwable $e) {}

    titre('ÉTANCHÉITÉ — chaque interdit a été TENTÉ, pas seulement décrit');
    $ok = 0;
    foreach ($essais as [$quoi, $attendu, $obtenu, $bon]) {
        printf("  %s  %-52s %-10s %s\n", $bon ? '✓' : '✗', $quoi, $attendu, $obtenu);
        $ok += $bon ? 1 : 0;
    }
    sortie('');
    sortie('  ' . $ok . '/' . count($essais) . ' frontières vérifiées');

    // ── LE VERROU DE LITTÉRAUX : contre ma propre mémoire. ────────────────────────────────
    titre('VERROU DE LITTÉRAUX — aucun identifiant de corpus dans le code exécutable');
    $moteur = glob(__DIR__ . '/crg_i*.py') ?: [];
    $moteur = array_merge($moteur, glob(__DIR__ . '/crg_ics_core.py') ?: [],
                          glob(__DIR__ . '/crg_format.py') ?: [],
                          glob(__DIR__ . '/parse_crg_septeo.py') ?: [],
                          glob(__DIR__ . '/crg_integration_*.py') ?: []);
    // ⚠️ LA DOCUMENTATION N'EST PAS UNE FUITE, ET UN DÉTECTEUR QUI L'OUBLIE FINIT DÉSACTIVÉ.
    //    La première version ne sautait que les lignes commençant par `#` : elle accusait
    //    trois docstrings qui RACONTENT un incident — « COMPTE PERSONNEL 01040000 », relevé
    //    sur le document pour expliquer pourquoi le lecteur cherche cette marque. Citer un
    //    cas dans un commentaire est ce qui rend le code compréhensible ; s'en servir dans
    //    une condition est ce qui fait passer l'examen sans le comprendre. On ne surveille
    //    donc que le CODE : hors commentaire et hors docstring.
    $fautes = [];
    foreach (array_unique($moteur) as $f) {
        $dans_doc = null;                    // le délimiteur ouvert : ''' ou """
        foreach (file($f) ?: [] as $no => $ligne) {
            $nu = trim($ligne);
            if ($dans_doc !== null) {
                if (str_contains($nu, $dans_doc)) {
                    $dans_doc = null;
                }
                continue;
            }
            foreach (['"""', "'''"] as $d) {
                $p = strpos($nu, $d);
                if ($p !== false) {
                    // Une docstring ouverte ET fermée sur la même ligne ne s'ouvre pas.
                    $dans_doc = substr_count($nu, $d) >= 2 ? null : $d;
                    $nu = substr($nu, 0, $p);
                    break;
                }
            }
            if ($nu === '' || str_starts_with($nu, '#')) {
                continue;
            }
            $code = preg_replace('~#.*$~', '', $nu);   // et le commentaire de fin de ligne
            if (preg_match('~\b(1105\d{6,7}|0[0-9]{7})\b~', (string)$code, $m)) {
                $fautes[] = basename($f) . ':' . ($no + 1) . ' → ' . $m[1];
            }
        }
    }
    if ($fautes) {
        foreach ($fautes as $x) { sortie('  ✗ ' . $x); }
        sortie('  ' . count($fautes) . ' littéral(aux) de corpus dans le code — l’oracle fuit.');
    } else {
        sortie('  ✓ ' . count(array_unique($moteur)) . ' fichiers moteur, 0 identifiant de corpus');
    }

    return ($ok === count($essais) && !$fautes) ? 0 : 1;
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  EXAM — une passe non interactive, que personne ne peut corriger en cours de route
// ══════════════════════════════════════════════════════════════════════════════════════════
/**
 * ⚠️ L'EXAMEN N'EST PAS UNE SÉANCE DE TRAVAIL. Jusqu'ici je corrigeais pendant que je
 *    mesurais, et le rapport mêlait les deux : impossible de dire si le moteur savait ou si
 *    je venais de lui souffler la réponse. Cette commande enchaîne tout et ne rend la main
 *    qu'à la fin. Le contrat est vérifié AVANT (arbre de travail propre, étanchéité prouvée)
 *    et APRÈS (le commit n'a pas bougé) : une passe pendant laquelle le code a changé est
 *    déclarée NULLE, pas « presque bonne ».
 *
 * ⚠️ LE MANIFESTE NE PORTE QUE DES NOMS ET DES TAILLES. Y mettre une empreinte du contenu
 *    serait déjà un début de corrigé.
 */
function cmd_exam(array $args): int
{
    $corpus = $args[0] ?? '';
    $asec   = !in_array('--pour-de-vrai', $args, true);
    if ($corpus === '' || !is_dir($corpus)) {
        sortie('usage : php mbi_bis.php exam <dossier_corpus> [--pour-de-vrai]');
        return 2;
    }
    @mkdir(BIS_RAPPORTS, 0775, true);
    $debut = date('c');

    titre('CONTRAT D’EXAMEN — vérifié avant de commencer');
    $racine = realpath(__DIR__ . '/../..');
    // ⚠️ ON NOMME LA SURFACE DU MOTEUR, ON NE SURVEILLE PAS UN DOSSIER. `public_html/scripts`
    //    contient des scripts de migration de production sans rapport avec la lecture des
    //    CRG : un d'entre eux modifié aurait bloqué tout examen, et la tentation aurait été
    //    d'affaiblir le contrôle. Le contrat porte donc sur ce qui décide de la lecture, et
    //    sur rien d'autre — cette liste s'allonge quand le moteur s'étend.
    $suivi  = [
        'public_html/scripts/crg_format.py',
        'public_html/scripts/crg_ics_core.py',
        'public_html/scripts/crg_depot_lire.py',
        'public_html/scripts/crg_integration_phase0.py',
        'public_html/scripts/crg_integration_phase2.py',
        'public_html/scripts/crg_integration_phase3.py',
        'public_html/scripts/crg_integration_phase4.py',
        'public_html/scripts/crg_integration_doublons.py',
        'public_html/scripts/crg_integration_lots.py',
        'public_html/scripts/crg_periode.py',
        'public_html/scripts/parse_crg_geo.py',
        'public_html/scripts/parse_crg_emery.py',
        'public_html/scripts/parse_crg_septeo.py',
        'public_html/scripts/crg_harnais.py',
        'public_html/scripts/mbi_bis.php',
        'public_html/scripts/mbi_bis',
        'public_html/scripts/tests_crg',
        'public_html/inc/crg_integration.php',
        'public_html/api/crg_integration_action.php',
        'docs/reference_crg_certification.md',
        'docs/reference_crg_apprentissages.md',
    ];
    $sale = trim((string)shell_exec('git -C ' . escapeshellarg($racine) . ' status --porcelain -- '
                                  . implode(' ', array_map('escapeshellarg', $suivi)) . ' 2>&1'));
    $sale = implode("\n", array_filter(explode("\n", $sale),
        fn($l) => $l !== '' && !str_contains($l, '__pycache__') && !preg_match('~^\?\?~', $l)));
    $commit = trim((string)shell_exec('git -C ' . escapeshellarg($racine) . ' rev-parse HEAD 2>&1'));

    $contrat = [
        'commit'            => $commit,
        'arbre_propre'      => $sale === '',
        'schema_version'    => is_file(BIS_SCHEMA) ? hash_file('sha256', BIS_SCHEMA) : null,
        'registre_version'  => is_file($r = __DIR__ . '/../../docs/reference_crg_apprentissages.md')
                               ? hash_file('sha256', $r) : null,
    ];
    $pdfs = glob(rtrim($corpus, '/\\') . '/*.pdf') ?: [];
    sort($pdfs);
    $manif = [];
    foreach ($pdfs as $p) {
        $manif[] = basename($p) . '|' . filesize($p);
    }
    $contrat['corpus'] = rtrim($corpus, '/\\');
    $contrat['corpus_fichiers'] = count($pdfs);
    $contrat['corpus_manifest_sha'] = hash('sha256', implode("\n", $manif));

    foreach ($contrat as $k => $v) {
        printf("  %-22s %s\n", $k, is_bool($v) ? ($v ? 'oui' : 'NON') : (string)$v);
    }
    if (!$contrat['arbre_propre']) {
        sortie('');
        sortie('EXAMEN REFUSÉ — l’arbre de travail porte des modifications non commitées :');
        sortie($sale);
        sortie('Un examen ne se passe pas sur du code en cours d’écriture.');
        return 1;
    }
    if ($contrat['schema_version'] === null || $contrat['registre_version'] === null) {
        sortie('EXAMEN REFUSÉ — schéma ou registre absent.');
        return 1;
    }

    titre('ÉTANCHÉITÉ — exigée avant toute écriture');
    if (cmd_etanche() !== 0) {
        sortie('EXAMEN REFUSÉ — le bac à sable n’est pas étanche.');
        return 1;
    }

    titre('RESET');
    if (cmd_reset() !== 0) {
        sortie('EXAMEN REFUSÉ — RESET incomplet.');
        return 1;
    }

    titre($asec ? 'PASSE À SEC — le corpus n’est PAS traité' : 'PASSE — traitement du corpus');
    if ($asec) {
        sortie('  ' . count($pdfs) . ' PDF prêts, non traités.');
        sortie('  Ajouter --pour-de-vrai pour lancer la passe. Rien n’a été lu.');
    } else {
        sortie('  (le pilote d’import non interactif se branche ici — non livré à ce stade)');
    }

    // ⚠️ ON REVÉRIFIE LE COMMIT À LA SORTIE. C'est la seule garantie qu'aucun correctif n'a
    //    été glissé pendant la passe — y compris par moi.
    $apres = trim((string)shell_exec('git -C ' . escapeshellarg($racine) . ' rev-parse HEAD 2>&1'));
    $contrat['commit_fin'] = $apres;
    $contrat['code_intact'] = ($apres === $commit);
    $contrat['fin'] = date('c');
    $contrat['debut'] = $debut;
    $contrat['a_sec'] = $asec;

    $f = BIS_RAPPORTS . '/' . date('Ymd_His') . '_exam.json';
    file_put_contents($f, json_encode($contrat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    titre('RAPPORT');
    sortie('  ' . $f);
    sortie('  code intact pendant la passe : ' . ($contrat['code_intact'] ? 'OUI' : 'NON — PASSE NULLE'));
    return $contrat['code_intact'] ? 0 : 1;
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  SCORE — celui qui juge n'est pas celui qui produit
// ══════════════════════════════════════════════════════════════════════════════════════════
/**
 * ⚠️ LE SCORER SE CONNECTE AVEC SA PROPRE IDENTITÉ, et c'est tout l'intérêt : lui seul voit
 *    l'oracle, et il n'écrit nulle part. Le moteur n'a pas son mot de passe.
 *
 * ⚠️ LE KPI QUI COMPTE N'EST PAS UN POURCENTAGE DE TESTS VERTS. C'est
 *    `interventions humaines / 100 CRG` : c'est celui qu'Emmanuel ressent, et le seul qui
 *    baisse quand l'agent apprend vraiment.
 */
function cmd_score(array $args): int
{
    $interventions = (int)($args[0] ?? 0);
    $scorer = pdo_compte('mbi_scorer', BIS_BASE);

    $un = function (string $sql) use ($scorer) {
        try { return (int)$scorer->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    $crg   = $un('SELECT COUNT(*) FROM crgi_crg');
    $pages = $un('SELECT COUNT(*) FROM crgi_page');
    $aff   = $un('SELECT COUNT(*) FROM crgi_page WHERE crg_id IS NOT NULL');
    $mvt   = $un('SELECT COUNT(*) FROM crgi_mouvement');
    $muets = $un('SELECT COUNT(*) FROM crgi_mouvement WHERE categorie = "INDETERMINABLE"');
    $occ   = $un('SELECT COUNT(*) FROM crgi_occupation');
    $occN  = $un('SELECT COUNT(*) FROM crgi_occupation WHERE locataire IS NOT NULL AND locataire <> ""');
    $arb   = $un('SELECT COALESCE(SUM(nombre),0) FROM crgi_plan WHERE action = "A ARBITRER"');
    $nouv  = $un('SELECT COUNT(DISTINCT section) FROM crgi_mouvement WHERE section LIKE "INCONNUE:%"');
    // ⚠️ LA PERTE SILENCIEUSE EST LE PIRE DÉFAUT : un objet démontré en aval, inconnu en amont.
    $perte = $un('SELECT COUNT(*) FROM crgi_occupation o
                   WHERE (o.locataire IS NULL OR o.locataire = "")
                     AND EXISTS (SELECT 1 FROM crgi_mouvement m WHERE m.crg_id = o.crg_id
                                  AND m.lot_reference = o.lot_reference
                                  AND m.locataire IS NOT NULL AND m.locataire <> "")');

    $pct = fn(int $a, int $b) => $b ? round(100 * $a / $b, 1) : 0.0;
    titre('FEUILLE DE SCORE — ' . date('Y-m-d H:i'));
    $score = [
        'CRG · pages'                          => $crg . ' · ' . $pages,
        'pages rattachées'                     => $aff . ' (' . $pct($aff, $pages) . ' %)',
        'mouvements'                           => (string)$mvt,
        'mouvements qualifiés automatiquement' => ($mvt - $muets) . ' (' . $pct($mvt - $muets, $mvt) . ' %)',
        'occupations nommées automatiquement'  => $occN . ' / ' . $occ . ' (' . $pct($occN, $occ) . ' %)',
        'nouveautés détectées'                 => (string)$nouv,
        'arbitrages proposés'                  => (string)$arb,
        'PERTES SILENCIEUSES'                  => (string)$perte,
        'interventions humaines'               => (string)$interventions,
        'INTERVENTIONS / 100 CRG'              => $crg ? (string)round(100 * $interventions / $crg, 2) : 'n/a',
    ];
    foreach ($score as $k => $v) {
        printf("  %-38s %s\n", $k, $v);
    }
    @mkdir(BIS_RAPPORTS, 0775, true);
    $f = BIS_RAPPORTS . '/' . date('Ymd_His') . '_score.json';
    file_put_contents($f, json_encode($score, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    sortie('');
    sortie('  ' . $f);
    return 0;
}

// ══════════════════════════════════════════════════════════════════════════════════════════
//  ROUTAGE
// ══════════════════════════════════════════════════════════════════════════════════════════
$cmd = $argv[1] ?? '';
exit(match ($cmd) {
    'droits'  => cmd_droits(),
    'schema'  => cmd_schema(),
    'reset'   => cmd_reset(),
    'etanche' => cmd_etanche(),
    'exam'    => cmd_exam(array_slice($argv, 2)),
    'score'   => cmd_score(array_slice($argv, 2)),
    default   => (function () {
        sortie('usage : php mbi_bis.php <commande>');
        sortie('  droits            crée `mbi_bis` et les deux comptes MySQL (exige root)');
        sortie('  schema            régénère le schéma versionné depuis maboximmo');
        sortie('  reset             détruit et recrée `mbi_bis` depuis ce schéma');
        sortie('  etanche           PROUVE les frontières en heurtant chaque interdit');
        sortie('  exam <corpus>     une passe non interactive — à sec par défaut');
        sortie('  score [n_interv]  la feuille de score, lue par `mbi_scorer`');
        return 2;
    })(),
});
