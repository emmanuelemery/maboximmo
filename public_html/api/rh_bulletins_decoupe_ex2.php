<?php
declare(strict_types=1);
/**
 * api/rh_bulletins_decoupe.php — Découpe les bulletins d'agence en un PDF par salarié.
 *
 * TROIS MODES, et l'ordre compte :
 *   · mode=preview (GET)  — n'écrit RIEN. Renvoie le tableau « page → salarié »
 *                           pour validation humaine. C'est un passage OBLIGÉ.
 *   · mode=zip     (GET)  — renvoie un ZIP des bulletins découpés, sans rien ranger.
 *   · mode=apply   (POST) — range un bulletin dans le dossier RH de chaque salarié.
 *
 * ⚠️ CONFIDENTIALITÉ : un bulletin rangé chez le mauvais salarié est une fuite de
 * rémunération. La détection (inc/rh_bulletins_lib.php) refuse de départager deux
 * candidats ; les pages ambiguës ressortent en « à vérifier » et ne sont JAMAIS
 * écrites. L'aperçu existe pour que l'attribution soit validée par un humain
 * avant la moindre écriture.
 *
 * Sécurité : login + privilèges paie + périmètre société ; CSRF sur l'écriture.
 */
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/rh_bulletins_lib.php';
require_once dirname(__DIR__) . '/inc/rh_bulletins_coffre.php';   // coffre RH + versions
require_login();
ini_set('display_errors', '0');

$pdo     = $GLOBALS['pdo'];
$roleId  = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$userId  = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$rhAdmin = in_array($roleId, [1, 7, 8], true);
$superAdmin = in_array($roleId, [1, 7], true);

$mode = (string)($_REQUEST['mode'] ?? 'preview');
$json = !in_array($mode, ['zip', 'pdf'], true);   // ces deux-là renvoient un fichier
if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    /* Jamais de cache sur ces réponses : l'état de classement change à chaque
       dépôt, et une réponse ressortie du cache afficherait la version d'avant
       — le classement paraîtrait avoir un train de retard. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$echec = function (string $msg, int $code = 400) use ($json): void {
    http_response_code($code);
    if (!$json) header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE));
};
if (!$rhAdmin) $echec('Accès refusé.', 403);

$soc    = (int)($_REQUEST['soc']    ?? 0);
$agence = (int)($_REQUEST['agence'] ?? 0);
$mois   = (int)($_REQUEST['mois']   ?? 0);
$annee  = (int)($_REQUEST['annee']  ?? 0);
if ($mois < 1 || $mois > 12 || $annee < 2000 || $annee > 2100) $echec('Mois ou année invalide.');

if (!$superAdmin) {
    $socUser = rhb_societe_utilisateur($pdo, $userId);   // `users` fait foi, pas la session
    if ($socUser <= 0) $echec('Société de rattachement inconnue.', 403);
    if ($soc === 0)            $soc = $socUser;
    elseif ($soc !== $socUser) $echec('Hors périmètre.', 403);
}

$depots = rhb_depots($pdo, $soc, $mois, $annee, $agence);
if (!$depots) $echec("Aucun bulletin déposé pour {$mois}/{$annee} sur ce périmètre.", 404);

/**
 * Analyse commune aux trois modes : pour chaque dépôt d'agence, l'attribution
 * page → salarié. Calculée une seule fois, jamais dupliquée entre les modes,
 * pour que l'aperçu validé et l'écriture portent EXACTEMENT sur le même résultat.
 */
$analyse = [];
foreach ($depots as $d) {
    $ligne = [
        'depot_id'   => (int)$d['id'],
        'societe'    => (string)($d['societe_nom'] ?? ''),
        'agence'     => (string)($d['agence_nom'] ?? ''),
        'id_societe' => (int)$d['id_societe'],
        'id_agence'  => (int)$d['id_agence'],
        'fichier'    => (string)$d['file_name'],
        'ok'         => false, 'error' => null,
        'pages'      => 0, 'salaries' => [], 'non_attribuees' => [],
        // Remontés à l'écran : sans eux, « 1 seul bulletin » reste inexplicable.
        'methode'    => '', 'alerte' => null, 'diagnostic' => [],
    ];
    if (empty($d['chemin'])) {
        $ligne['error'] = 'Fichier absent du serveur.';
        $analyse[] = $ligne; continue;
    }
    $sal = rhb_salaries($pdo, (int)$d['id_societe'], (int)$d['id_agence']);
    if (!$sal) {
        $ligne['error'] = 'Aucun salarié actif avec matricule sur cette agence.';
        $analyse[] = $ligne; continue;
    }
    // Analyse MÉMORISÉE : identique tant que ni le PDF ni les fiches n'ont bougé.
    $r = rhb_analyse_depot($pdo, $d, $sal);
    $ligne['ok']             = (bool)$r['ok'];
    $ligne['error']          = $r['error'];
    $ligne['pages']          = (int)$r['pages'];
    $ligne['salaries']       = array_values($r['par_salarie']);
    $ligne['non_attribuees'] = $r['non_attribuees'];
    $ligne['methode']        = (string)($r['methode'] ?? '');
    $ligne['alerte']         = $r['alerte'] ?? null;
    $ligne['diagnostic']     = $r['diagnostic'] ?? [];
    $ligne['_chemin']        = $d['chemin'];

    /* État de classement de chaque salarié : sans lui, l'écran propose de classer
       un travail déjà fait et personne ne sait où il en est. Un bulletin refusé par
       le contrôle n'a pas de version : il ressort donc « à classer ». */
    foreach ($ligne['salaries'] as $i => $s) {
        $c = rhbc_courant($pdo, (int)$s['id_user'], $mois, $annee);
        $ligne['salaries'][$i]['classe'] = $c
            ? ['version' => (int)$c['version'], 'envoye' => !empty($c['envoye_at'])]
            : null;
    }

    /* Extrait des pages NON attribuées : « page 3 » tout seul n'apprend rien.
       Voir le début du texte permet de comprendre en un regard s'il s'agit d'un
       salarié absent du logiciel, d'un récapitulatif, ou d'une page illisible. */
    $ligne['extraits'] = [];
    foreach ($r['non_attribuees'] as $np) {
        $ligne['extraits'][] = ['page' => $np, 'texte' => (string)($r['detail'][$np]['extrait'] ?? '')];
    }
    $analyse[] = $ligne;
}

/* Compteurs globaux : ce que l'utilisateur doit voir AVANT de valider. */
$nbSal = 0; $nbPagesOrphelines = 0; $nbPresume = 0; $nbClasses = 0;
foreach ($analyse as $a) {
    $nbSal += count($a['salaries']);
    $nbPagesOrphelines += count($a['non_attribuees']);
    foreach ($a['salaries'] as $s) {
        if (!empty($s['presume'])) $nbPresume++;
        if (!empty($s['classe']))  $nbClasses++;
    }
}

// ─────────────────────────── APERÇU ───────────────────────────
if ($mode === 'preview') {
    $sortie = array_map(function (array $a) { unset($a['_chemin']); return $a; }, $analyse);
    exit(json_encode([
        'ok'        => true,
        'mois'      => $mois, 'annee' => $annee,
        'depots'    => $sortie,
        'resume'    => [
            'agences'          => count($analyse),
            'bulletins'        => $nbSal,
            'pages_a_verifier' => $nbPagesOrphelines,
            'suites_presumees' => $nbPresume,
            'deja_classes'     => $nbClasses,   // pilote le libellé du bouton
        ],
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * Retrouve, dans l'analyse serveur, les pages d'un salarié pour un dépôt donné.
 * Le navigateur ne transmet JAMAIS de liste de pages : elle est toujours
 * recalculée ici. Sinon il suffirait de forger une requête pour extraire les
 * pages d'un collègue.
 */
$trouverCible = function (int $depotId, int $idUser) use ($analyse): ?array {
    foreach ($analyse as $a) {
        if ((int)$a['depot_id'] !== $depotId || !$a['ok']) continue;
        foreach ($a['salaries'] as $s) {
            if ((int)$s['id_user'] === $idUser) return ['depot' => $a, 'salarie' => $s];
        }
    }
    return null;
};

// ─────────────────────── APERÇU VISUEL D'UN BULLETIN ───────────────────────
if ($mode === 'pdf') {
    $depotId = (int)($_GET['depot'] ?? 0);
    $cibleId = (int)($_GET['user']  ?? 0);
    $c = $trouverCible($depotId, $cibleId);
    if (!$c) $echec('Bulletin introuvable pour ce salarié.', 404);

    $tmpDir = dirname(__DIR__) . '/uploads/_tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    $apercu   = $tmpDir . '/apercu_' . bin2hex(random_bytes(6)) . '.pdf';
    $ephemere = true;

    /* CE QUI S'AFFICHE EST LA VERSION COURANTE DU COFFRE.
       Re-découper le dépôt d'agence montrerait le bulletin D'ORIGINE alors qu'une
       version corrigée a pris sa place : l'œil afficherait un document que plus
       personne ne recevra. L'aperçu, l'envoi et le coffre doivent montrer le même
       fichier. Repli sur l'extraction tant que le bulletin n'est pas classé. */
    $courantApercu = rhbc_courant($pdo, $cibleId, $mois, $annee);
    $absApercu     = rhbc_fichier($pdo, $courantApercu);   // copie durable, sinon doc GED
    if ($absApercu !== null) { $apercu = $absApercu; $ephemere = false; }
    if ($ephemere) {
        $ex = rhb_extraire_pages($c['depot']['_chemin'], $c['salarie']['pages'], $apercu);
        if (!$ex['ok']) { @unlink($apercu); $echec($ex['error'] ?? 'Extraction impossible.', 500); }
    }

    /* Le contrôle d'envoi est joué DÈS L'APERÇU : ce que l'écran affiche porte
       déjà le verdict, on ne découvre pas le problème au moment d'envoyer. */
    $ctl = rhb_controle_pdf($apercu, $cibleId, rhb_salaries($pdo, (int)$c['depot']['id_societe'], (int)$c['depot']['id_agence']));

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rhb_slug($c['salarie']['nom']) . '.pdf"');
    header('Content-Length: ' . filesize($apercu));
    header('X-Controle-Ok: ' . ($ctl['ok'] ? '1' : '0'));
    header('X-Controle-Motif: ' . rawurlencode($ctl['motif']));
    header('X-Bulletin-Version: ' . ($ephemere ? 'depot' : 'coffre-v' . (int)$courantApercu['version']));
    header('Cache-Control: no-store');
    readfile($apercu);
    if ($ephemere) @unlink($apercu);   // jamais le fichier durable du coffre
    exit;
}

/* mois_fr() est définie à l'intérieur d'une autre fonction du projet : elle n'est
   pas accessible ici. Un mail annonçant « bulletin de paie — 4 2026 » ferait
   amateur, on nomme donc le mois sur place. */
$NOMS_MOIS = [1=>'janvier','février','mars','avril','mai','juin',
              'juillet','août','septembre','octobre','novembre','décembre'];
$moisLbl   = $NOMS_MOIS[$mois] ?? (string)$mois;

/* Modèle par défaut du message. Défini UNE fois : l'aperçu proposé à l'écran et
   le texte réellement envoyé doivent être le même objet, sinon on relit un
   message et on en expédie un autre. */
$sujetDefaut = 'Votre bulletin de paie — ' . $moisLbl . ' ' . $annee;
$corpsDefaut = "Bonjour {{prenom}},\n\n"
             . "Vous trouverez ci-joint votre bulletin de paie de {{mois}} {{annee}}.\n\n"
             . "Nous vous invitons à le conserver sans limitation de durée.\n\n"
             . "Pour toute question, votre service RH reste à votre disposition.\n\n"
             . "Bien cordialement,";

// ────────────────── DESTINATAIRES (préparation de l'envoi) ──────────────────
if ($mode === 'destinataires') {
    $cibleUser = (int)($_GET['user'] ?? 0);
    $liste = [];
    foreach ($analyse as $a) {
        if (!$a['ok']) continue;
        foreach ($a['salaries'] as $s) {
            $uid = (int)$s['id_user'];
            if ($cibleUser > 0 && $uid !== $cibleUser) continue;
            $u = $pdo->prepare("SELECT prenom, nom, COALESCE(email,'') AS email FROM users WHERE id = ? LIMIT 1");
            $u->execute([$uid]);
            $d = $u->fetch(PDO::FETCH_ASSOC) ?: ['prenom'=>'','nom'=>'','email'=>''];
            $liste[] = [
                'id_user'  => $uid,
                'nom'      => (string)$s['nom'],
                'agence'   => (string)$a['agence'],
                'depot_id' => (int)$a['depot_id'],
                'email'    => trim((string)$d['email']),
                'presume'  => (bool)$s['presume'],
                'pages'    => $s['pages'],
            ];
        }
    }
    exit(json_encode([
        'ok'       => true,
        'sujet'    => $sujetDefaut,
        'corps'    => $corpsDefaut,
        'mois'     => $moisLbl,
        'annee'    => $annee,
        'liste'    => $liste,
        'sans_mail'=> count(array_filter($liste, fn($x) => $x['email'] === '')),
    ], JSON_UNESCAPED_UNICODE));
}

// ─────────────────────────── ENVOI INDIVIDUEL ───────────────────────────
if ($mode === 'send') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $echec('POST requis.', 405);
    verify_csrf_any('default');
    require_once dirname(__DIR__) . '/inc/mailer.php';

    $cibleUser = (int)($_POST['user'] ?? 0);      // 0 = tous les bulletins analysés
    $sujetSaisi = trim((string)($_POST['sujet'] ?? ''));
    $corpsSaisi = (string)($_POST['corps'] ?? '');
    if ($sujetSaisi === '') $sujetSaisi = $sujetDefaut;
    if (trim($corpsSaisi) === '') $corpsSaisi = $corpsDefaut;

    /* Adresses ajoutées à la main pour les salariés qui n'en avaient pas.
       Elles sont ENREGISTRÉES sur la fiche : sans cela il faudrait les ressaisir
       chaque mois, et la prochaine campagne échouerait au même endroit. */
    $ajouts = json_decode((string)($_POST['emails'] ?? '{}'), true);
    if (!is_array($ajouts)) $ajouts = [];
    $enregistrees = 0; $adresseKo = [];
    foreach ($ajouts as $uid => $mail) {
        $uid = (int)$uid; $mail = trim((string)$mail);
        if ($uid <= 0 || $mail === '') continue;
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { $adresseKo[] = "$mail : adresse invalide"; continue; }

        /* `users.email` porte un index UNIQUE : une adresse déjà attribuée fait
           échouer l'écriture. Le dire est indispensable — sinon l'adresse n'est
           pas enregistrée, le bulletin n'est pas envoyé, et rien ne l'explique. */
        try {
            $dup = $pdo->prepare("SELECT id, CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,'')) n
                                    FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $dup->execute([$mail, $uid]);
            if ($autre = $dup->fetch(PDO::FETCH_ASSOC)) {
                $adresseKo[] = "$mail : déjà utilisée par " . trim((string)$autre['n']);
                continue;
            }
            // Ne jamais écraser une adresse existante : on ne comble que les vides.
            $st = $pdo->prepare("UPDATE users SET email = ? WHERE id = ? AND COALESCE(email,'') = ''");
            $st->execute([$mail, $uid]);
            $enregistrees += $st->rowCount();
        } catch (Throwable $e) {
            $adresseKo[] = "$mail : " . $e->getMessage();
            error_log('[rh_bulletins email] ' . $e->getMessage());
        }
    }

    $envoyes = 0; $refuses = []; $echecs = [];
    $logSt = $pdo->prepare("INSERT INTO mail_salaire_log (id_user, mois_reference, email, status, error, sent_at)
                            VALUES (?,?,?,?,?,NOW())");

    foreach ($analyse as $a) {
        if (!$a['ok']) continue;
        $salAgence = rhb_salaries($pdo, (int)$a['id_societe'], (int)$a['id_agence']);

        foreach ($a['salaries'] as $s) {
            $uid = (int)$s['id_user'];
            if ($cibleUser > 0 && $uid !== $cibleUser) continue;

            /* Le destinataire est résolu ICI, depuis la fiche du salarié.
               Aucune adresse ne transite par le navigateur : impossible de
               détourner un bulletin vers une autre boîte. */
            $u = $pdo->prepare("SELECT email, prenom, nom FROM users WHERE id = ? AND actif = 1 LIMIT 1");
            $u->execute([$uid]);
            $dest = $u->fetch(PDO::FETCH_ASSOC);
            if (!$dest || trim((string)$dest['email']) === '') {
                $refuses[] = $s['nom'] . ' : aucune adresse e-mail';
                continue;
            }

            $tmpDir = dirname(__DIR__) . '/uploads/_tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
            $pdfEnvoi = $tmpDir . '/bull_' . $uid . '_' . bin2hex(random_bytes(5)) . '.pdf';
            $ephemere = true;      // faut-il effacer le fichier envoyé après coup ?

            /* CE QUI PART EST LA VERSION COURANTE DU COFFRE, pas une re-découpe du
               dépôt d'agence : après une correction, ré-extraire les pages du dépôt
               d'origine renverrait la v1 — soit exactement le bulletin faux que le
               comptable vient de remplacer. Repli sur l'extraction si le bulletin
               n'a pas encore été classé (dépôt antérieur à la bascule au coffre). */
            $bulletinId = 0;
            $courant    = rhbc_courant($pdo, $uid, $mois, $annee);
            $absCourant = rhbc_fichier($pdo, $courant);      // copie durable, sinon doc GED
            if ($absCourant !== null) {
                $pdfEnvoi   = $absCourant;
                $ephemere   = false;
                $bulletinId = (int)$courant['id'];
            }
            if ($ephemere) {
                $ex = rhb_extraire_pages($a['_chemin'], $s['pages'], $pdfEnvoi);
                if (!$ex['ok']) { $echecs[] = $s['nom'] . ' : ' . ($ex['error'] ?? 'extraction'); @unlink($pdfEnvoi); continue; }
            }

            // ── LE GARDE-FOU : on relit le fichier qui part réellement ──
            $ctl = rhb_controle_pdf($pdfEnvoi, $uid, $salAgence);
            if (!$ctl['ok']) {
                $refuses[] = $s['nom'] . ' : ' . $ctl['motif'];
                if ($ephemere) @unlink($pdfEnvoi);   // jamais le fichier durable du coffre
                try { $logSt->execute([$uid, sprintf('%04d-%02d-01', $annee, $mois), $dest['email'], 'invalid', $ctl['motif']]); } catch (Throwable $e) {}
                continue;
            }

            /* Le texte saisi est traité comme du TEXTE, jamais comme du HTML :
               il est échappé puis les sauts de ligne deviennent des <br>. Un
               opérateur ne doit pas pouvoir injecter de balises dans un courrier
               partant à tout le personnel, même involontairement. */
            $vars = [
                '{{prenom}}' => trim((string)$dest['prenom']),
                '{{nom}}'    => trim((string)$dest['nom']),
                '{{mois}}'   => $moisLbl,
                '{{annee}}'  => (string)$annee,
            ];
            $sujet = strtr($sujetSaisi, $vars);
            $corps = nl2br(htmlspecialchars(strtr($corpsSaisi, $vars), ENT_QUOTES, 'UTF-8'))
                   . '<p style="color:#94a3b8;font-size:12px;margin-top:18px;">Message automatique — merci de ne pas y répondre.</p>';

            $ok = false;
            try { $ok = send_mail((string)$dest['email'], $sujet, $corps, [$pdfEnvoi], true); }
            catch (Throwable $e) { $echecs[] = $s['nom'] . ' : ' . $e->getMessage(); }

            try {
                $logSt->execute([$uid, sprintf('%04d-%02d-01', $annee, $mois), $dest['email'],
                                 $ok ? 'sent' : 'failed', $ok ? null : 'send_mail a renvoyé false']);
            } catch (Throwable $e) { /* le log ne doit jamais bloquer un envoi réussi */ }

            if ($ok) {
                $envoyes++;
                // La version envoyée est horodatée : l'écran distingue « v2 non envoyée »
                // d'une version déjà partie, sans quoi une correction pourrait rester
                // au coffre sans que personne ne la transmette.
                if ($bulletinId > 0) rhbc_marquer_envoye($pdo, $bulletinId);
            } else {
                $echecs[] = $s['nom'] . ' : envoi refusé par le serveur de messagerie';
            }
            if ($ephemere) @unlink($pdfEnvoi);       // le fichier du coffre, lui, reste
        }
    }

    echo json_encode([
        'ok'           => true,
        'envoyes'      => $envoyes,
        'refuses'      => $refuses,      // bloqués par le contrôle : JAMAIS partis
        'echecs'       => $echecs,
        'enregistrees' => $enregistrees, // adresses ajoutées et sauvegardées sur la fiche
        'adresses_ko'  => $adresseKo,    // saisies refusées (doublon, format)
        'message'      => $envoyes . ' bulletin(s) envoyé(s)'
                          . ($enregistrees ? ' · ' . $enregistrees . ' adresse(s) enregistrée(s)' : '')
                          . (count($adresseKo) ? ' · ' . count($adresseKo) . ' adresse(s) refusée(s)' : '')
                          . (count($refuses) ? ' · ' . count($refuses) . ' bloqué(s) par le contrôle' : '')
                          . (count($echecs)  ? ' · ' . count($echecs) . ' en échec' : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────── ZIP DES DÉCOUPES ───────────────────────────
if ($mode === 'zip') {
    $tmpDir = dirname(__DIR__) . '/uploads/_tmp/decoupe_' . bin2hex(random_bytes(5));
    @mkdir($tmpDir, 0777, true);
    $zipPath = $tmpDir . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) $echec('Création du ZIP impossible.', 500);

    $n = 0;
    foreach ($analyse as $a) {
        if (!$a['ok']) continue;
        foreach ($a['salaries'] as $s) {
            $tmpPdf = $tmpDir . '/' . rhb_slug($s['nom']) . '_' . (int)$s['id_user'] . '.pdf';
            $ex = rhb_extraire_pages($a['_chemin'], $s['pages'], $tmpPdf);
            if (!$ex['ok']) continue;
            $zip->addFile($tmpPdf, sprintf('%s/%04d-%02d_%s.pdf',
                rhb_slug($a['agence'] ?: 'agence', 28), $annee, $mois, rhb_slug($s['nom'])));
            $n++;
        }
    }
    $zip->close();
    if ($n === 0) { @unlink($zipPath); $echec('Aucun bulletin découpable.', 404); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="bulletins-decoupes_' . sprintf('%04d-%02d', $annee, $mois) . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    readfile($zipPath);
    // Ménage : les PDF intermédiaires ne doivent pas rester sur le disque.
    foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir); @unlink($zipPath);
    exit;
}

// ─────────────────────────── RANGEMENT (écriture) ───────────────────────────
if ($mode !== 'apply') $echec('Mode inconnu.');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $echec('POST requis pour écrire.', 405);
// Formulaire 'default' : c'est celui que rh_salaires.php publie dans <meta name="csrf-token">.
verify_csrf_any('default');

/* Le rangement passe désormais par le COFFRE RH (inc/rh_bulletins_coffre.php) :
   plus une ligne dans `rh_documents`, que le salarié lit lui-même — sa paie y était
   visible en ligne. Le bulletin devient un document GED de niveau 'coffre' (accès
   nominatif `rh.coffre.read`), versionné : rejouer la découpe ne duplique rien, et
   un dépôt corrigé crée la version suivante au lieu d'être ignoré.
   Ce bouton fait donc exactement ce que fait le dépôt du comptable — même chemin,
   même résultat : il ne sert plus qu'à rattraper un dépôt arrivé avant la bascule. */
$cree = 0; $ignore = 0; $refuses = []; $erreurs = [];

foreach ($analyse as $a) {
    if (!$a['ok']) { if ($a['error']) $erreurs[] = ($a['agence'] ?: 'Agence') . ' : ' . $a['error']; continue; }

    $r = rhbc_classer_depot($pdo, (int)$a['depot_id'], $userId);
    if (empty($r['ok'])) { $erreurs[] = ($a['agence'] ?: 'Agence') . ' : ' . ($r['error'] ?? 'classement impossible'); continue; }

    $cree   += (int)$r['crees'];
    $ignore += (int)$r['inchanges'];
    foreach ($r['refuses'] as $motif) $refuses[] = ($a['agence'] ?: 'Agence') . ' — ' . $motif;
}

echo json_encode([
    'ok'      => true,
    'crees'   => $cree,
    'ignores' => $ignore,                 // déjà classés depuis la même source
    'refuses' => $refuses,                // bloqués par le contrôle d'identité : rien n'a été écrit
    'a_verifier' => $nbPagesOrphelines,   // pages jamais attribuées, à traiter à la main
    'erreurs' => $erreurs,
    'message' => $cree . ' bulletin(s) classé(s) au coffre'
                 . ($ignore ? ', ' . $ignore . ' inchangé(s)' : '')
                 . (count($refuses) ? ' · ' . count($refuses) . ' refusé(s) par le contrôle' : '')
                 . ($nbPagesOrphelines ? ' — ' . $nbPagesOrphelines . ' page(s) non attribuée(s) à traiter à la main' : ''),
], JSON_UNESCAPED_UNICODE);
