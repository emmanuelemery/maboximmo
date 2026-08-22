<?php
declare(strict_types=1);
/**
 * p/bail_signature.php — Page publique de signature d'un bail (token).
 * Sans authentification. Affiche le BAIL COMPLET + parcours de signature :
 *   acceptation des clauses → mention « bon pour acceptation » tapée + date → signature au doigt
 *   (pavé plein écran) OU nom saisi au clavier.
 * Preuve (faisceau) = code SMS + IP + horodatage + user-agent + signature + mention.
 *
 * ⚠️ La PHOTO-PREUVE a été RETIRÉE le 18/08/2026, à la demande d'Emmanuel : le code
 * reçu par SMS démontre la détention du téléphone enregistré, ce que la photo ne
 * faisait qu'approcher — et bien plus mal. Photographier le visage d'un locataire
 * pour signer un bail, c'est collecter une donnée biométrique sensible pour une
 * preuve plus faible que celle qu'on a désormais. La colonne `photo_preuve` est
 * CONSERVÉE en base (on ne détruit jamais l'historique d'un acte) mais plus rien
 * ne l'alimente.
 * Lien valable BSIG_TTL_MIN minutes. À la dernière signature → PDF définitif → GED → envoi à tous.
 * URL : /p/bail_signature.php?t=<token_64_hex>
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bail_signature.php';
require_once __DIR__ . '/../inc/bail_commercial_pdf.php';

$pdo = $GLOBALS['pdo'];
$token = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); die('Lien invalide.'); }

$sig = bsig_get_by_token($pdo, $token);
if (!$sig) { http_response_code(404); die('Ce lien n\'existe pas.'); }

function bsig_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', (string)$_SERVER[$k])[0]);
    }
    return '';
}

// Normalisation pour comparer la mention tapée à la mention attendue (casse/espaces/accents souples).
function bsig_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/* ⚠️ str_starts_with, PAS une égalité stricte : depuis que la cérémonie appelle
   TOUTES les cautions (15/08), les rôles valent 'caution', 'caution_1',
   'caution_2'… Le test strict faisait recevoir à la deuxième caution la mention
   du preneur — elle se serait engagée sans avoir écrit qu'elle se portait
   caution, ce que l'article 2297 sanctionne par la nullité. */
$isCaution = str_starts_with((string)($sig['role_code'] ?? ''), 'caution');

/* ⚠️🔥 MENTION PROVISOIRE — « Bon pour caution solidaire, lu et approuvé » est la
   formule d'AVANT la réforme du 15/09/2021 : ni plafond en toutes lettres, ni
   renonciation au bénéfice de discussion. Un cautionnement recueilli ainsi est
   NUL (art. 2297 C. civ.).
   À remplacer par cautionnement_mention_2297() de inc/bail_cautionnement_acte.php
   dès que le bloc de saisie à trous est branché.
   Audit du 15/08/2026 : 0 cautionnement signé en base — aucun acte à rattraper,
   mais AUCUN ne doit être recueilli avec cette formule. */
$mentionAttendue = $isCaution ? 'Bon pour caution solidaire, lu et approuvé' : 'Lu et approuvé, bon pour acceptation';

$expired = bsig_is_expired($sig);
$dejaSigne = ($sig['statut'] === 'signe');

/* ══════════════════════════════════════════════════════════════════════════
   LA PORTE D'ENTRÉE : LE CODE REÇU PAR SMS
   ══════════════════════════════════════════════════════════════════════════

   Le lien nominatif prouve qu'on a écrit à une adresse connue. Il ne prouve pas
   QUI ouvre la page : un mail se transfère, une boîte se partage. Le code envoyé
   sur le mobile enregistré ajoute la pièce manquante — le signataire détenait ce
   téléphone au moment de signer.

   ⚠️ Ce n'est PAS une double authentification et le certificat ne doit pas le
   prétendre : rien ne garantit que le lien et le code n'aboutissent pas au même
   appareil. Ce qui est démontré, c'est la DÉTENTION DU TÉLÉPHONE, et c'est déjà
   ce qui manquait.

   ── Pourquoi le code part ICI et non dans le SMS d'invitation ──────────────
   Un code vit 10 minutes ; le lien, 48 heures. Glissé dans l'invitation, il
   serait périmé à l'ouverture pour presque tout le monde — et un code émis deux
   jours plus tôt ne dirait rien de l'instant de l'acte.

   ⚠️ CÉRÉMONIES ANTÉRIEURES : sans mobile enregistré (`destinataire_tel` vide),
   la porte est DÉSACTIVÉE et le signataire entre directement. Les liens partis
   avant le 18/08/2026 n'ont pas de numéro : exiger un code les rendrait tous
   inutilisables, et des baux en cours de signature seraient bloqués sans que
   personne ne comprenne pourquoi. La preuve est alors celle d'avant — plus
   faible, et le certificat l'écrit. */
require_once dirname(__DIR__) . '/inc/sms_otp.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$sigId     = (int)$sig['id'];
$OTP_OBJET = 'BAIL_SIGNATURE';
$telSig    = trim((string)($sig['destinataire_tel'] ?? ''));
$otpRequis = ($telSig !== '');
$otpOk     = !$otpRequis || !empty($_SESSION['bsig_otp'][$sigId]);
$otpFlash  = null;
$otpInfo   = null;
$otpAttendre = 0;

/* ── LA VAGUE : le mandataire ne peut pas signer avant les autres ───────────
   Le lien de la vague 2 existe dès la création de la cérémonie — c'est ce qui
   permet de l'ouvrir d'un seul UPDATE le moment venu. Mais tant que
   `vague_ouverte_at` est NULL, il ne doit RIEN ouvrir : sans ce contrôle,
   l'agence pourrait signer la première en collant son propre lien, et tout
   l'ordre de la cérémonie ne serait qu'une décoration côté écran.
   Le contrôle est SERVEUR, jamais un bouton grisé. */
$vagueFermee = empty($sig['vague_ouverte_at']) && (int)($sig['vague'] ?? 1) >= 2;

/* ── AUCUN POST NE MEURT EN SILENCE ─────────────────────────────────────────
   ⚠️🔥 Mesuré le 22/08/2026 sur un lien de plus de 48 h. Les trois traitements
   POST ci-dessous étaient gardés par `!$expired && !$vagueFermee` : condition
   fausse ⇒ on n'entrait pas dans le bloc, et RIEN n'était prévu pour le dire.
   La page se réaffichait à l'octet près — GET et POST rendaient tous deux
   15 232 octets identiques. Le signataire cliquait « Recevoir mon code », il ne
   se passait rien, et aucun écran ne pouvait le lui expliquer : de son point de
   vue le lien était « inactif ». Trois jours de diagnostic à côté de la plaque.

   UNE SEULE variable porte désormais la raison, et elle sert aux DEUX usages :
   refuser le POST **et** l'expliquer à l'écran. On ne peut plus fermer la porte
   sans dire pourquoi — règle du 20/08 : « rien ne bloque, mais rien ne se tait ». */
$porteFermee = null;
if ($dejaSigne) {
    $porteFermee = 'Vous avez déjà signé ce document : il n\'y a plus rien à faire.';
} elseif ($expired) {
    $porteFermee = 'Ce lien de signature a dépassé sa durée de validité ('
                 . (int)(BSIG_TTL_MIN / 60) . ' heures). Votre agence peut le réactiver en un clic.';
} elseif ($vagueFermee) {
    $porteFermee = 'Ce n\'est pas encore votre tour : vous signez en dernier, une fois '
                 . 'toutes les autres parties engagées.';
}

$action = (string)($_POST['action'] ?? '');

/* Un POST qui arrive sur une porte fermée repart avec la raison, jamais avec
   le silence. Le message s'affiche dans la porte d'entrée ; les états qui ont
   leur propre bandeau (expiré, pas votre tour, déjà signé) le disent en grand. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $porteFermee !== null) {
    $otpFlash = ['ok' => false, 'msg' => $porteFermee];
    error_log('[bail_signature porte] POST "' . $action . '" refuse sur signature #' . $sigId . ' : ' . $porteFermee);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'otp_send' && $otpRequis && $porteFermee === null) {
    $ctxOtp = [];
    try { require_once dirname(__DIR__) . '/inc/bail_ceremonie.php'; $ctxOtp = bcer_contexte($pdo, (int)$sig['id_bail']); }
    catch (Throwable $e) { error_log('[bail_signature otp ctx] ' . $e->getMessage()); }

    $env = otp_emettre($pdo, $OTP_OBJET, $sigId, [
        'telephone'  => $telSig,
        'id_societe' => (int)($ctxOtp['id_societe'] ?? 0),
        'libelle'    => (string)($ctxOtp['nom_bail'] ?? 'votre bail'),
    ]);
    if (!empty($env['ok'])) {
        $otpFlash = ['ok' => true, 'msg' => 'Code envoyé au ' . ($env['telephone_masque'] ?? otp_masquer_numero($telSig)) . '.'];
    } else {
        /* ── UN SIGNATAIRE NE LIT JAMAIS UNE ERREUR D'OPÉRATEUR ───────────────
           ⚠️🔥 Constaté en recette le 18/08 : la page affichait, en rouge et en
           anglais, « Missing from. For more informations : docs.ovh.com » — le
           message brut d'OVH — à la personne à qui l'on demande à l'instant même
           de faire confiance au procédé de signature. Une panne de configuration
           chez nous ne doit pas ressembler à une erreur qu'elle aurait commise.

           On distingue donc ce qu'elle peut CORRIGER (patienter avant de
           redemander un code) de ce qu'elle ne peut pas : dans ce second cas, on
           dit que la panne est de notre côté, on invite à appeler l'agence, et le
           détail technique part dans le journal — là où il sert. */
        $otpAttendre = (int)($env['attendre'] ?? 0);
        $brut = (string)($env['error'] ?? '');
        if ($otpAttendre > 0) {
            $otpFlash = ['ok' => false,
                'msg' => "Un code vient de vous être envoyé. Merci de patienter "
                       . $otpAttendre . " seconde" . ($otpAttendre > 1 ? 's' : '') . " avant d’en redemander un."];
        } else {
            error_log('[bail_signature otp] envoi refuse (signature #' . $sigId . ') : ' . $brut);
            $otpFlash = ['ok' => false,
                'msg' => "L'envoi du SMS est momentanément indisponible. Ce n'est pas de votre fait : "
                       . "merci de contacter votre agence, qui pourra vous ouvrir l'accès au document."];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'otp_verify' && $otpRequis && !$otpOk && $porteFermee === null) {
    $res = otp_verifier($pdo, $OTP_OBJET, $sigId, (string)($_POST['otp_code'] ?? ''));
    if (!empty($res['ok'])) {
        /* Deux traces, deux usages :
           · la SESSION ouvre la lecture pour cette visite — si le signataire
             revient demain, il redemande un code, et la détention du téléphone
             est à nouveau démontrée AU MOMENT de signer ;
           · la BASE gravera dans le certificat que le code a été validé, quand,
             et quelle ligne `sms_otp` le prouve (hash, tentatives, IP). */
        $_SESSION['bsig_otp'][$sigId] = time();
        $otpOk = true;
        try {
            $pdo->prepare("UPDATE bail_signatures SET otp_valide_at = NOW(), otp_sms_id = ? WHERE id = ?")
                ->execute([(int)($res['id'] ?? 0) ?: null, $sigId]);
        } catch (Throwable $e) { error_log('[bail_signature otp maj] ' . $e->getMessage()); }
        $sig = bsig_get_by_token($pdo, $token) ?: $sig;
    } else {
        $otpFlash = ['ok' => false, 'msg' => (string)($res['error'] ?? 'Code incorrect.')];
    }
}

// Un défi encore vivant ? On rouvre l'écran de saisie sans refacturer un SMS.
if ($otpRequis && !$otpOk) {
    $otpInfo = otp_en_cours($pdo, $OTP_OBJET, $sigId);
}

$flash = null; $justSigned = false;
/* ⚠️ `$action === ''` : les POST de la porte d'entrée (otp_send / otp_verify) ne
   doivent PAS retomber dans le traitement de la signature — sans ce filtre, une
   demande de code repartait dans la validation du formulaire et affichait
   « Merci d'indiquer votre nom », alors que le signataire n'avait rien rempli.
   `$otpOk` : la lecture du bail et la signature sont derrière la porte. Le
   contrôle est ici, côté serveur — pas seulement dans l'affichage. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '' && $otpOk && $porteFermee === null) {
    $nom       = trim((string)($_POST['nom_signataire'] ?? ''));
    $approuve  = !empty($_POST['lu_approuve']);
    $mention   = trim((string)($_POST['mention_manuscrite'] ?? ''));
    $dateMain  = trim((string)($_POST['date_manuscrite'] ?? ''));
    $sigData   = (string)($_POST['signature_data'] ?? '');
    /* ── TRACÉ AU DOIGT **OU** NOM AU CLAVIER ──────────────────────────────
       Les deux se valent. Le tracé au doigt n a AUCUNE supériorité légale sur un
       nom tapé : ni l un ni l autre n est une signature manuscrite au sens du
       droit, ce sont deux REPRÉSENTATIONS. Ce qui vaut signature électronique,
       c est le procédé fiable d identification (art. 1367 al. 2 C. civ.) — ici le
       lien nominatif, le code SMS, l horodatage, l IP et l empreinte du document.
       Le gribouillis au doigt est même le plus faible des deux : il n est
       comparable à rien.
       On propose donc les deux, sans hiérarchie, et le certificat note simplement
       lequel a été retenu. */
    $modeSig   = ((string)($_POST['signature_mode'] ?? '') === 'clavier') ? 'clavier' : 'trace';
    $nomClavier = trim((string)($_POST['nom_clavier'] ?? ''));

    if ($nom === '')                                   { $flash = 'Merci d\'indiquer votre nom et prénom.'; }
    elseif (!$approuve)                                { $flash = 'Merci de cocher l\'acceptation des clauses du bail.'; }
    elseif (bsig_norm($mention) !== bsig_norm($mentionAttendue)) { $flash = 'Merci de recopier exactement la mention : « ' . $mentionAttendue . ' ».'; }
    elseif ($dateMain === '')                          { $flash = 'Merci d\'inscrire la date à la main.'; }
    elseif ($modeSig === 'trace' && strncmp($sigData, 'data:image', 10) !== 0) {
        $flash = "Merci de signer dans le cadre avec votre doigt — ou choisissez « Saisir mon nom ».";
    }
    /* En mode clavier, RETAPER son nom EST l acte de signer. Sans cette
       confirmation, un simple clic sur « Signer » suffirait, et le geste
       délibéré — ce qui distingue une signature d un bouton — disparaîtrait. */
    elseif ($modeSig === 'clavier' && bsig_norm($nomClavier) !== bsig_norm($nom)) {
        $flash = "Pour signer au clavier, retapez votre nom exactement comme ci-dessus : « " . $nom . " ».";
    }
    else {
        $res = bsig_sign($pdo, $token, $nom, bsig_client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                         $modeSig === 'clavier' ? null : $sigData, null, $modeSig);
        if (!empty($res['ok'])) {
            // Trace la mention manuscrite + date (best-effort : ne casse pas si la colonne n'existe pas).
            try {
                $pdo->prepare("UPDATE bail_signatures SET mention_manuscrite = ? WHERE id = ?")
                    /* ⚠️🔥 AUCUNE TRONCATURE. La colonne est passée en TEXT (migration
                       20260815f) précisément parce que la mention de l'art. 2297 fait
                       461 à 489 caractères. Un mb_substr(...,255) ici couperait en plein
                       milieu du montant garanti et rendrait le cautionnement nul, en
                       annulant au passage tout l'intérêt de la migration. */
                    ->execute([$mention . ' — ' . $dateMain, (int)$sig['id']]);
            } catch (Throwable $e) { /* colonne absente → ignoré */ }
            $justSigned = true; $sig = bsig_get_by_token($pdo, $token); $dejaSigne = true;
        } else { $flash = $res['error'] ?? 'Erreur lors de la signature.'; }
    }
}

// Rendu du BAIL COMPLET (même générateur que le PDF), avec les tracés déjà signés incrustés.
$bailHtml = '';
try {
    /* Aiguillage sur la nature du bail : un locataire d'habitation doit lire un bail
       d'habitation. Cf. bail_build_corps_dispatch() dans inc/bail_commercial_pdf.php. */
    $bailHtml = bail_build_corps_dispatch($pdo, (int)$sig['id_bail'], bsig_list_for_bail($pdo, (int)$sig['id_bail']));
} catch (Throwable $e) { $bailHtml = ''; }

/* ── LA CÉRÉMONIE VUE PAR LE SIGNATAIRE ────────────────────────────────────
   Qui signe, qui a signé, et à quelle heure. Lu dans un bloc SÉPARÉ du rendu du
   bail : mêlé au précédent try, une erreur de génération du corps aurait aussi
   emporté la liste, et inversement. */
$ceremonie = []; $ceremSignes = 0; $ceremTotal = 0;
try {
    $ceremonie   = bsig_list_for_bail($pdo, (int)$sig['id_bail']);
    $ceremTotal  = count($ceremonie);
    $ceremSignes = count(array_filter($ceremonie, static fn($c) => ($c['statut'] ?? '') === 'signe'));
} catch (Throwable $e) { error_log('[bail_signature ceremonie] ' . $e->getMessage()); }

/* Les rôles portent des suffixes depuis que TOUTES les parties signent :
   « caution_1 » est la deuxième caution. Afficher le code brut donnerait
   « Caution_1 » à un signataire, qui n'a pas à lire nos codes internes. */
$roleLibelle = static function (string $rc): string {
    $base = preg_replace('/_\d+$/', '', $rc);
    $n    = preg_match('/_(\d+)$/', $rc, $m) ? ' n°' . ((int)$m[1] + 1) : '';
    $lbls = ['preneur' => 'Preneur', 'colocataire' => 'Colocataire', 'caution' => 'Caution',
             'mandataire' => 'Agence (mandataire)', 'bailleur' => 'Bailleur'];
    return ($lbls[$base] ?? ucfirst($base)) . $n;
};

/* ── ANNEXES FIGÉES À L'ENVOI ──────────────────────────────────────────────────
   Le signataire doit voir CE QU'IL SIGNE. Les pièces ont été figées au moment de
   l'envoi (api/mail_compose_send.php) : on les affiche telles quelles, sans les relire
   du bien — détacher un document six mois plus tard ne doit pas changer l'acte soumis
   à signature. Le certificat atteste une empreinte ; si la composition de l'acte pouvait
   bouger après coup, l'empreinte n'attesterait plus rien.

   ⚠️ Bloc SÉPARÉ du rendu du bail : logé dans le même try, une erreur ici aurait effacé
   l'aperçu du bail — le signataire se serait retrouvé à signer une page vide.
   ⚠️ RIEN NE BLOQUE : une liste d'annexes vide est un cas normal (un DPE qu'on n'a pas),
   la signature suit son cours. */
require_once dirname(__DIR__) . '/inc/bail_types_registry.php';
$bailNom    = 'bail';
$sigAnnexes = [];
/* Initialisé AVANT le try : `$rowAx` n'est affecté que si la requête aboutit, et
   il est relu plus bas pour choisir le vocabulaire (locataire / preneur). Sans
   cela, une table sans la colonne `bail_regime` produisait un avertissement
   « Undefined variable » sur la page publique de signature. */
$rowAx      = [];
try {
    $stAx = $pdo->prepare("SELECT bail_nature, bail_regime, duree_mois, annexes_signature_json FROM bien_baux WHERE id=? LIMIT 1");
    $stAx->execute([(int)$sig['id_bail']]);
    if ($rowAx = $stAx->fetch(PDO::FETCH_ASSOC)) {
        $bailNom    = bt_libelle($rowAx);
        $sigAnnexes = json_decode((string)($rowAx['annexes_signature_json'] ?? '[]'), true) ?: [];
    }
} catch (Throwable $e) { /* colonnes absentes : ni nom précis, ni annexes — on n'empêche rien */ }

/* ── CE QUI A DÉJÀ ÉTÉ OUVERT, SELON LE SERVEUR ────────────────────────────
   Le badge « consultée » ne doit pas dépendre d'un clic dans la page courante :
   le signataire ouvre une pièce, revient, et la page se recharge — tout serait
   oublié. On relit le journal posé par `p/bail_annexe.php` au moment où il a
   réellement rendu chaque fichier. */
$axLues = [];
try {
    $stL = $pdo->prepare("SELECT annexes_lues_json FROM bail_signatures WHERE id = ? LIMIT 1");
    $stL->execute([$sigId]);
    foreach ((json_decode((string)($stL->fetchColumn() ?: '[]'), true) ?: []) as $lg) {
        $u = (string)($lg['uid'] ?? '');
        if ($u !== '') $axLues[$u] = (string)($lg['ouvert_at'] ?? '');
    }
} catch (Throwable $e) { error_log('[bail_signature annexes lues] ' . $e->getMessage()); }

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eur = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$adresse = trim(($sig['bien_adresse'] ?? '') . ' ' . ($sig['bien_cp'] ?? '') . ' ' . ($sig['bien_ville'] ?? ''));
/* ⚠️🔥 LE PRENEUR ÉTAIT VIDE DANS LE RÉCAPITULATIF.
   Ces colonnes `locataire_*` du bail ne sont plus remplies depuis que les parties
   viennent de `tiers_roles` (15/08). Le signataire lisait donc « Preneur — » sur
   l'écran où on lui demande de reconnaître SON bail : rien ne lui confirmait que
   le document le concernait. On lit la source unique, avec repli sur les colonnes
   pour les baux d'avant la bascule. */
$preneur = '';
try {
    require_once dirname(__DIR__) . '/inc/bail_locataires.php';
    $noms = [];
    foreach (bail_locataires_list($pdo, (int)$sig['id_bail']) as $lp) {
        $n = trim((string)($lp['nom_affichage'] ?: ($lp['raison_sociale']
             ?: trim(((string)($lp['prenom'] ?? '')) . ' ' . ((string)($lp['nom'] ?? ''))))));
        if ($n !== '') $noms[] = $n;
    }
    // Plusieurs colocataires : on les nomme TOUS. Un seul nom laisserait croire
    // que l'autre n'est pas partie au bail.
    $preneur = implode(' et ', $noms);
} catch (Throwable $e) { error_log('[bail_signature preneur] ' . $e->getMessage()); }
if ($preneur === '') {
    $preneur = (string)($sig['locataire_raison_sociale'] ?: trim((string)($sig['locataire_prenom'] ?? '') . ' ' . ($sig['locataire_nom'] ?? '')));
}

/* ⚠️🔥 « Vous signez en tant que LE PRENEUR » S'AFFICHAIT POUR TOUT LE MONDE.
   Le test ne connaissait que deux cas : caution, ou preneur par défaut. Le
   mandataire et le bailleur lisaient donc qu'ils signaient comme preneur — sur
   un acte où la qualité du signataire détermine ce à quoi il s'engage. Un
   bailleur pouvait légitimement refuser de signer, ou pire, signer en croyant
   l'écran. */
$roleBase = preg_replace('/_\d+$/', '', (string)($sig['role_code'] ?? '')) ?: 'preneur';
$roleNum  = preg_match('/_(\d+)$/', (string)($sig['role_code'] ?? ''), $mRole) ? ' n°' . ((int)$mRole[1] + 1) : '';
/* Le vocabulaire suit la NATURE du bail : « locataire » est le mot de la loi
   89-462, « preneur » celui du bail commercial. Les mêler sur un bail commercial
   — « le preneur (locataire) » — donne à l'écran un air d'à-peu-près, juste au
   moment où le signataire cherche des raisons de faire confiance. */
$estHabitation = in_array((string)($rowAx['bail_nature'] ?? ''),
                          ['habitation', 'meuble', 'mobilite', 'meuble_touristique'], true);
$roleLbl  = ([
    'preneur'     => $estHabitation ? 'le locataire' : 'le preneur',
    'colocataire' => 'colocataire',
    'locataire'   => $estHabitation ? 'le locataire' : 'le preneur',
    'caution'     => 'la caution (garant)',
    'mandataire'  => 'le mandataire (agence)',
    'bailleur'    => 'le bailleur',
][$roleBase] ?? $roleBase) . $roleNum;
$loyerA = ($sig['loyer_mensuel_hc'] ?? null) !== null ? (float)$sig['loyer_mensuel_hc'] * 12 : null;

/* ── QUI VOUS ÉCRIT ────────────────────────────────────────────────────────
   Une page publique qui demande une signature engageante doit dire, en haut et
   sans qu'on la cherche, DE QUI elle vient : l'agence, son logo, et la personne
   qui a préparé l'acte. Un lien reçu par SMS renvoyant vers un formulaire de
   signature anonyme, c'est exactement la forme d'une tentative d'hameçonnage —
   et un locataire prudent a raison de s'en méfier. */
$entete = ['logo' => '', 'agence' => '', 'societe' => '', 'user_nom' => '', 'user_fonction' => ''];
try {
    $stE = $pdo->prepare("
        SELECT a.nom_agence, a.nom_commercial, a.logo_url AS logo_agence, a.logo_path,
               s.raison_sociale, s.nom AS soc_nom, s.logo_url AS logo_societe
          FROM bien_baux bb
          JOIN biens b ON b.id = bb.id_bien
          LEFT JOIN agences  a ON a.id = COALESCE(b.id_agence, bb.id_agence)
          LEFT JOIN societes s ON s.id = COALESCE(b.id_societe, bb.id_societe)
         WHERE bb.id = ? LIMIT 1");
    $stE->execute([(int)$sig['id_bail']]);
    if ($rE = $stE->fetch(PDO::FETCH_ASSOC)) {
        $entete['agence']  = trim((string)($rE['nom_commercial'] ?: $rE['nom_agence'] ?: ''));
        $entete['societe'] = trim((string)($rE['raison_sociale'] ?: $rE['soc_nom'] ?: ''));
        // Le logo de l'AGENCE prime : c'est l'interlocuteur du locataire.
        $entete['logo'] = trim((string)($rE['logo_agence'] ?: $rE['logo_path'] ?: $rE['logo_societe'] ?: ''));
    }
} catch (Throwable $e) { error_log('[bail_signature entete] ' . $e->getMessage()); }
try {
    /* La personne qui a ENVOYÉ la cérémonie, pas celle qui a créé le bail :
       c'est elle que le signataire rappellera s'il a une question. */
    if (!empty($sig['id_user_created'])) {
        $stU = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', prenom, nom)) AS nom, fonction FROM users WHERE id = ? LIMIT 1");
        $stU->execute([(int)$sig['id_user_created']]);
        if ($rU = $stU->fetch(PDO::FETCH_ASSOC)) {
            $entete['user_nom']      = trim((string)($rU['nom'] ?? ''));
            $entete['user_fonction'] = trim((string)($rU['fonction'] ?? ''));
        }
    }
} catch (Throwable $e) { error_log('[bail_signature entete user] ' . $e->getMessage()); }

/* ── LE RIB, PARCE QU'ON ANNONCE UNE SOMME À VERSER ────────────────────────
   L'écran affiche « Total à verser à la signature : 940,00 € » et n'indiquait
   nulle part OÙ verser. Le signataire devait rouvrir le mail, ou appeler
   l'agence — au moment précis où il vient de s'engager à payer. Le RIB est celui
   de GESTION de l'agence, lu dans le même contexte que le bail. */
$rib = [];
try {
    $ctxRib = bail_commercial_pdf_context($pdo, (int)$sig['id_bail']);
    $ge = is_array($ctxRib['gestionnaire'] ?? null) ? $ctxRib['gestionnaire'] : [];
    if (trim((string)($ge['rib_iban'] ?? '')) !== '') {
        $rib = [
            'iban'      => trim((string)$ge['rib_iban']),
            'bic'       => trim((string)($ge['rib_bic'] ?? '')),
            'titulaire' => trim((string)($ge['rib_titulaire'] ?? '')) ?: trim((string)($ge['rib_nom'] ?? '')),
            'banque'    => trim((string)($ge['rib_banque'] ?? '')),
        ];
    }
} catch (Throwable $e) { error_log('[bail_signature rib] ' . $e->getMessage()); }

// Montants à payer — mêmes règles que le bail (récap par terme + 1er versement à la signature).
$eur2 = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 2, ',', ' ') . ' €' : '—';
$cond = is_array($ctx['cond'] ?? null) ? $ctx['cond'] : [];
$tvaOn = !empty($cond['tva_app']);
$tvaT  = (float)($cond['tva_taux'] ?? 20) ?: 20.0;
$perM  = (($cond['perio'] ?? '') === 'trimestrielle') ? 3 : 1;
$perLbl = $perM === 3 ? 'trimestre' : 'mois';
$loyM  = (float)($cond['loyer_m'] ?? ($sig['loyer_mensuel_hc'] ?? 0));
$chM   = (float)($cond['charges_m'] ?? ($sig['charges_mensuelles'] ?? 0));
$tfM   = (float)($cond['prov_tf'] ?? 0);
$techM = ($cond['tech_pct'] ?? null) !== null ? $loyM * (float)$cond['tech_pct'] / 100 : 0.0; // honoraires gestion technique
$echLoyerT = $tvaOn ? ($loyM + $techM) * $perM * (1 + $tvaT / 100) : ($loyM + $techM) * $perM; // loyer + gestion technique, TTC si TVA
$totEcheance = $echLoyerT + $chM * $perM + $tfM * $perM;     // total dû à chaque terme (loyer+tech TTC + charges + TF)
// Prorata du 1er terme (mêmes règles que le bail) — sur loyer, charges ET taxe foncière.
$prRatio = 1.0;
$prRaw = ($cond['prorata_date'] ?? '') ?: ($cond['date_effet'] ?? ($sig['date_prise_effet'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prRaw)) {
    $pts = strtotime((string)$prRaw); $pm=(int)date('n',$pts); $pd=(int)date('j',$pts); $py=(int)date('Y',$pts);
    if ($perM === 3) { $qs=intdiv($pm-1,3)*3+1; $qStart=mktime(0,0,0,$qs,1,$py); $qEnd=mktime(0,0,0,$qs+3,0,$py); $tot=(int)round(($qEnd-$qStart)/86400)+1; $rem=(int)round(($qEnd-$pts)/86400)+1; $prRatio=$tot>0?$rem/$tot:1.0; }
    else { $dim=(int)date('t',$pts); $prRatio=$dim>0?($dim-$pd+1)/$dim:1.0; }
}
$dgM   = (float)($cond['dg_montant'] ?? 0);
$deM   = (float)($cond['droit_entree'] ?? 0);
// Honoraires PRENEUR TTC (loyer annuel HT × % × 1,20) — comptés dans le total. Bailleur = non compté.
$loyAn = (float)($cond['loyer_a'] ?? ($loyM * 12));
$hpPren = $cond['hono_pct_pren'] ?? null;
$honoPrenTTC = $hpPren !== null ? $loyAn * (float)$hpPren / 100 * 1.20 : (float)($cond['hono_loc'] ?? 0);
// 1er versement = 1er terme PRORATISÉ (loyer+charges+TF) + DG + pas-de-porte + honoraires preneur TTC.
$premierTerme = ($echLoyerT + $chM * $perM + $tfM * $perM) * $prRatio;
$totSignature = $premierTerme + $dgM + $deM + $honoPrenTTC;
$hasMontants = ($totEcheance > 0 || $totSignature > 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signature — <?= $h($bailNom) ?></title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:#f1f5f9;color:#1f2937;}
  .wrap{max-width:720px;margin:0 auto;padding:20px 14px 70px;}
  .card{background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:24px 26px;margin-top:16px;}
  h1{font-size:22px;margin:0 0 4px;}
  .sub{color:#64748b;font-size:13px;}
  .terms{margin:18px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
  .terms .row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;gap:14px;}
  .terms .row:last-child{border-bottom:0;}
  .terms .k{color:#64748b;} .terms .v{font-weight:700;text-align:right;}
  .ok-banner{background:linear-gradient(135deg,#e9f7ef,#d7f0e0);border:1px solid #9ad3ab;color:#0b6b35;border-radius:12px;padding:18px 20px;}
  .exp-banner{background:#fef3c7;border:1px solid #f0d38a;color:#8a5a00;border-radius:12px;padding:18px 20px;}
  .err{background:#fde2e1;border:1px solid #f3b4b1;color:#a11;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px;}
  label.fld{display:block;font-size:12px;font-weight:700;color:#475569;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.05em;}
  input[type=text]{width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;}
  .chk{display:flex;gap:10px;align-items:flex-start;margin:14px 0;font-size:14px;}
  /* Les pièces annexées, listées AVANT la case d'acceptation : on ne coche pas
     « j'ai lu les annexes » sans savoir lesquelles. */
  .annexes{margin:14px 0;padding:11px 13px;border:1px solid #e2e8f0;border-left:3px solid #5f8f93;
           border-radius:9px;background:#f8fbfb;font-size:13.5px;}
  .annexes b{display:block;margin-bottom:5px;color:#243B5C;}
  .annexes ul{margin:0;padding-left:20px;}
  .annexes li{margin:2px 0;line-height:1.45;overflow-wrap:anywhere;}
  .annexes small{display:block;margin-top:6px;color:#64748b;font-size:12px;line-height:1.45;}
  .chk input{margin-top:3px;transform:scale(1.3);}
  .btn{width:100%;margin-top:18px;padding:15px;border:none;border-radius:12px;background:linear-gradient(135deg,#5f8f93,#84A7AB);color:#fff;font-size:16px;font-weight:800;cursor:pointer;}
  .btn-sec{background:#eef2f6;color:#334155;font-weight:700;font-size:13px;padding:8px 14px;border:none;border-radius:9px;cursor:pointer;}
  .legal{font-size:11px;color:#94a3b8;margin-top:14px;line-height:1.5;}
  .proof{font-size:12px;color:#475569;margin-top:10px;}
  /* Bail complet repliable */
  details.bailbox{margin:16px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
  details.bailbox>summary{cursor:pointer;padding:12px 16px;background:#eef2f6;font-weight:800;color:#243B5C;font-size:14px;list-style:none;}
  details.bailbox>summary::-webkit-details-marker{display:none;}
  .bailfull{max-height:52vh;overflow:auto;padding:16px 18px;background:#fff;font-family:'Times New Roman',Times,serif;font-size:13px;line-height:1.5;color:#1c2226;}
  .bailfull h1{font-size:18px;text-align:center;margin:0 0 4px;}
  .bailfull .sub,.bailfull .ref{text-align:center;font-size:11px;color:#777;font-style:italic;margin:0 0 6px;}
  .bailfull h2{font-size:13px;color:#2c4b4d;border-bottom:1px solid #cddcdc;padding-bottom:2px;margin:12px 0 4px;}
  .bailfull h3{font-size:12px;color:#243B5C;background:#eef2f6;padding:3px 7px;margin:10px 0 4px;}
  .bailfull p{margin:3px 0 6px;text-align:justify;}
  .bailfull .clabel{font-weight:bold;color:#2c4b4d;margin:6px 0 2px;}
  .bailfull ul{margin:3px 0 8px;padding-left:18px;} .bailfull li{margin:2px 0;text-align:justify;}
  .bailfull .tbl{width:100%;border-collapse:collapse;font-size:11px;margin:4px 0 10px;}
  .bailfull .tbl th{background:#e3ecec;text-align:left;padding:5px 7px;border:.5px solid #b9cccc;}
  .bailfull .tbl td{padding:4px 7px;border:.5px solid #cddada;}
  .bailfull img{max-width:100%;}
  /* Pavé de signature */
  .padwrap{margin-top:8px;}
  .pad{width:100%;height:200px;border:2px dashed #94a3b8;border-radius:12px;background:#fff;touch-action:none;display:block;}
  .padrow{display:flex;justify-content:space-between;align-items:center;margin-top:8px;}
  .photo-note{font-size:11px;color:#64748b;margin-top:4px;}
  .assur{margin:14px 0;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#fff;}
  .assur input[type=file]{font-size:13px;}
  /* ── Porte d'entrée (code SMS) et attente de vague ── */
  .gate{margin:18px 0 6px;padding:22px 18px;border:1px solid #dbe6e6;border-radius:14px;
        background:#f8fafb;text-align:center;}
  .gate-ico{font-size:38px;line-height:1;margin-bottom:8px;}
  .gate h2{margin:0 0 8px;font-size:19px;color:#243B5C;font-weight:800;}
  .gate p{margin:0 auto 12px;max-width:430px;font-size:14px;color:#475569;line-height:1.55;}
  .gate-form{max-width:300px;margin:0 auto;}
  .gate-form .btn{width:100%;}
  .gate-ok{margin:0 auto 12px;max-width:430px;padding:9px 12px;border-radius:9px;
           background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:13px;}
  .gate-note{font-size:11.5px;color:#64748b;line-height:1.5;margin-top:12px;}
  /* Les six chiffres, assez grands pour être lus et tapés sans lunettes : la
     cible est un locataire debout dans la rue, pas un poste de bureau. */
  .otp-input{width:100%;text-align:center;font-size:30px;font-weight:800;letter-spacing:.36em;
             padding:13px 10px;border:2px solid #5f8f93;border-radius:11px;margin-bottom:11px;
             font-variant-numeric:tabular-nums;box-sizing:border-box;}

  /* ── Choix du mode de signature : deux onglets de POIDS ÉGAL ──
     Aucun n'est « recommandé » : le droit ne hiérarchise pas ces deux
     représentations, et l'écran ne doit pas le faire à sa place. */
  .modetabs{display:flex;gap:8px;margin:2px 0 10px;}
  .modetab{flex:1;padding:11px 8px;border:1px solid #cbd5e1;background:#fff;color:#334155;
           border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;line-height:1.3;}
  .modetab.on{background:#5f8f93;border-color:#5f8f93;color:#fff;}
  .sigtype{width:100%;box-sizing:border-box;font-size:22px;padding:14px 12px;border:1px solid #cbd5e1;
           border-radius:10px;font-family:Georgia,'Times New Roman',serif;font-style:italic;}

  /* ── Où en est la cérémonie ── */
  .cerem{margin:18px 0 4px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;}
  .cerem-h{padding:10px 13px;background:#f4f6f9;border-bottom:1px solid #e2e8f0;
           font-size:13px;font-weight:800;color:#243B5C;}
  .cerem-l{display:flex;gap:9px;padding:9px 13px;border-bottom:1px solid #f1f5f9;font-size:13px;}
  .cerem-l:last-of-type{border-bottom:0;}
  .cerem-moi{background:#f8fbfb;}
  .cerem-p{flex:0 0 auto;}
  .cerem-t{flex:1;min-width:0;color:#0f172a;line-height:1.4;}
  .cerem-t em{color:#5f8f93;font-style:normal;font-weight:700;}
  .cerem-n{color:#475569;font-weight:400;}
  .cerem-d{display:block;font-size:11.5px;color:#64748b;margin-top:2px;}
  .cerem-f{padding:9px 13px;background:#f8fafb;border-top:1px solid #e2e8f0;
           font-size:11.5px;color:#475569;line-height:1.5;}

  /* ── Qui vous écrit ── */
  .entete{display:flex;align-items:center;gap:12px;padding-bottom:12px;margin-bottom:14px;
          border-bottom:1px solid #e8edf2;}
  .entete-logo{flex:0 0 auto;max-height:52px;max-width:130px;object-fit:contain;}
  .entete-txt{display:flex;flex-direction:column;gap:1px;min-width:0;font-size:12.5px;color:#64748b;line-height:1.4;}
  .entete-txt b{color:#243B5C;font-size:14px;font-weight:800;}
  .entete-txt span b{font-size:12.5px;}
  .accueil{margin:14px 0 4px;padding:12px 14px;border-radius:10px;background:#f8fafb;
           border:1px solid #dbe6e6;font-size:13.5px;color:#334155;line-height:1.55;}
  .accueil span{display:block;margin-top:7px;font-size:12.5px;color:#64748b;}

  /* ── Annexes réellement consultables ── */
  .axlist{margin:0;padding-left:2px;list-style:none;}
  .axlist li{margin:5px 0;line-height:1.45;overflow-wrap:anywhere;}
  .axlink{display:inline-block;color:#243B5C;text-decoration:none;font-weight:600;
          border-bottom:1px solid #b9cccd;padding-bottom:1px;}
  .axopen{display:inline-block;margin-left:5px;font-size:11px;font-weight:700;color:#5f8f93;
          border:1px solid #b9cccd;border-radius:6px;padding:1px 6px;vertical-align:1px;}
  /* ⚠️🔥 `display:inline-block` ÉCRASE l'attribut `hidden` : la feuille de style
     de l'auteur l'emporte sur celle du navigateur, et TOUS les badges
     « ✓ consultée » s'affichaient dès l'ouverture de la page — sur des pièces que
     personne n'avait ouvertes. Le compteur, lui, était juste : l'écran affirmait
     donc deux choses contradictoires, et c'est la fausse qui rassurait.
     La règle `[hidden]` doit être réaffirmée explicitement. */
  .axvu{display:inline-block;margin-left:6px;font-size:11.5px;font-weight:700;color:#15803d;}
  .axvu[hidden]{display:none;}
  .axko{display:inline-block;margin-left:6px;font-size:11px;color:#b45309;}
  .axreste{margin-top:8px;font-size:12px;color:#b45309;font-weight:700;line-height:1.45;}

  /* ── RIB : on annonce une somme, on dit où la verser ── */
  .rib{margin:14px 0;padding:13px 15px;border:1px solid #dbe6e6;border-left:3px solid #243B5C;
       border-radius:10px;background:#f8fafb;}
  .rib-t{font-weight:800;color:#243B5C;font-size:14px;margin-bottom:8px;}
  .rib-l{display:flex;justify-content:space-between;gap:12px;font-size:13px;padding:3px 0;}
  .rib-l span{color:#64748b;flex:0 0 auto;}
  .rib-l b{color:#0f172a;text-align:right;overflow-wrap:anywhere;}
  /* L'IBAN se lit et se recopie : chiffres à chasse fixe, jamais coupés au hasard. */
  .rib-iban{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;letter-spacing:.02em;}
  .rib-copy{margin-top:9px;width:100%;}
  .rib-n{margin-top:9px;font-size:11.5px;color:#64748b;line-height:1.5;}

  /* ── Ce que le signataire ÉCRIT, distinct de ce qu'il lit ── */
  .manuscrit{font-family:'Segoe Script','Bradley Hand','Snell Roundhand','Apple Chancery',cursive;
             font-size:19px;line-height:1.5;color:#1a2a44;}
  .aide-date{margin-top:5px;font-size:12px;color:#64748b;}
  .lienbtn{background:none;border:0;padding:0;color:#5f8f93;font-weight:800;font-size:12px;
           font-family:inherit;text-decoration:underline;cursor:pointer;}
  .facultatif{display:inline-block;margin-left:6px;font-size:10.5px;font-weight:700;color:#5f8f93;
              background:#eef4f4;border:1px solid #cfe0e0;border-radius:6px;padding:1px 6px;vertical-align:2px;}

  /* ── La barre qui conduit d'un champ obligatoire au suivant ── */
  .barre{position:sticky;bottom:0;z-index:20;margin:18px -22px -22px;padding:12px 22px 16px;
         background:linear-gradient(to top,#fff 72%,rgba(255,255,255,0));}
  .barre .btn{width:100%;}
  .btn-suivant{background:#243B5C;}

  /* ── Remerciement ── */
  .merci{text-align:center;padding:22px 6px 6px;}
  .merci-ico{font-size:46px;line-height:1;margin-bottom:6px;}
  .merci h2{margin:0 0 10px;font-size:22px;color:#243B5C;font-weight:800;}
  .merci p{margin:0 auto 14px;max-width:420px;font-size:15px;color:#334155;line-height:1.55;}
  .merci-p{margin:0 auto 14px;max-width:420px;padding:11px 14px;border-radius:10px;
           background:#e9f7ef;border:1px solid #9ad3ab;color:#0b6b35;font-size:13.5px;line-height:1.5;}
  .merci-suite{margin:0 auto;max-width:420px;padding:12px 14px;border-radius:10px;text-align:left;
               background:#f8fafb;border:1px solid #dbe6e6;color:#475569;font-size:13px;line-height:1.55;}
  .merci-suite b{color:#243B5C;}
  /* Or de la charte (#D4A047) : c'est le SEUL geste qui reste au signataire,
     il doit se voir sans être cherché. */
  .merci-fin{margin-top:18px;max-width:420px;background:#D4A047;color:#1a2333;
             font-size:17px;font-weight:800;box-shadow:0 4px 14px rgba(212,160,71,.4);}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <?php /* ⚠️🔥 « Bail commercial » était ÉCRIT EN DUR ici. Le corps de la page
             disait bien « bail d'habitation » (bt_libelle) et le titre le
             contredisait deux lignes plus haut — sur l'écran même où on demande au
             signataire de faire confiance au document. 469 baux d'habitation
             contre 70 commerciaux : la contradiction était visible par 8
             signataires sur 10. */ ?>
    <?php if ($entete['agence'] !== '' || $entete['logo'] !== ''): ?>
    <div class="entete">
      <?php if ($entete['logo'] !== ''): ?>
        <?php /* Le logo est décoratif : `alt` vide, et une image manquante ne
                 doit pas laisser un cadre cassé au-dessus d'un acte. */ ?>
        <img class="entete-logo" src="<?= $h($entete['logo']) ?>" alt=""
             onerror="this.style.display='none';">
      <?php endif; ?>
      <div class="entete-txt">
        <?php if ($entete['agence'] !== ''): ?><b><?= $h($entete['agence']) ?></b><?php endif; ?>
        <?php if ($entete['societe'] !== '' && $entete['societe'] !== $entete['agence']): ?>
          <span><?= $h($entete['societe']) ?></span>
        <?php endif; ?>
        <?php if ($entete['user_nom'] !== ''): ?>
          <span>Votre interlocuteur : <b><?= $h($entete['user_nom']) ?></b><?php
            if ($entete['user_fonction'] !== '') echo ' · ' . $h($entete['user_fonction']); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <h1>🔑 <?= $h(mb_strtoupper(mb_substr($bailNom, 0, 1)) . mb_substr($bailNom, 1)) ?></h1>
    <div class="sub"><?= $h($sig['numero_bail'] ?: '') ?> · <?= $h($sig['designation'] ?: $sig['reference_bien']) ?> · Vous signez en tant que <strong><?= $h($roleLbl) ?></strong></div>

    <?php if (!$dejaSigne && !$expired && $otpOk && !$vagueFermee): ?>
    <?php /* Un mot d'accueil, avant la mécanique. Le signataire arrive par un SMS
             sur un formulaire qui lui demande de s'engager pour trois ans : lui
             dire pourquoi ce document existe et d'où il sort coûte deux lignes. */ ?>
    <div class="accueil">
      Merci de prendre le temps de signer ce document, préparé
      <?= $entete['user_nom'] !== '' ? 'par <b>' . $h($entete['user_nom']) . '</b> ' : '' ?>
      conformément aux accords convenus entre les parties.
      <span>Prenez connaissance de l'acte et de ses pièces annexées, puis signez ci-dessous.
      <b>Dès que toutes les parties auront signé</b>, le document définitif — acte, annexes
      et justificatifs réunis — sera adressé <b>par mail à chacune d'elles</b>.</span>
    </div>
    <?php endif; ?>

    <?php if ($expired): ?>
      <?php /* ⚠️🔥 CE BANDEAU ÉTAIT INATTEIGNABLE. Il vivait à l'intérieur du `else`
               de la porte SMS : `elseif (!$otpOk)` gagnait toujours avant lui, donc
               seul un signataire SANS mobile pouvait le voir — c'est-à-dire les
               cérémonies d'avant le 18/08. Tous les autres tombaient sur la porte
               d'entrée, cliquaient « Recevoir mon code » et n'obtenaient rien.
               Un lien mort doit le dire EN PREMIER : avant le rôle, avant la vague,
               avant le code. C'est la seule information qui serve à quelque chose. */ ?>
      <div class="gate">
        <div class="gate-ico">⏱️</div>
        <h2>Ce lien n'est plus valable</h2>
        <p>Par sécurité, un lien de signature expire au bout de
        <strong><?= (int)(BSIG_TTL_MIN / 60) ?> heures</strong>. Celui-ci a été envoyé
        <?= !empty($sig['sent_at'])
              ? 'le <strong>' . $h(date('d/m/Y \à H\hi', strtotime((string)$sig['sent_at']))) . '</strong>'
              : 'il y a plus longtemps' ?>.</p>
        <p class="gate-note">Vous n'avez rien fait de mal, et rien n'est perdu :
        <strong>votre agence le réactive en un clic</strong>, et c'est le <em>même</em> lien
        qui redevient actif — celui que vous avez déjà reçu. Contactez-la, ou attendez
        le nouveau message.</p>
      </div>

    <?php elseif ($vagueFermee): ?>
      <?php /* Le mandataire signe en dernier. Le lien existe déjà — c'est ce qui
               permet de l'ouvrir d'un seul UPDATE le moment venu — mais il n'ouvre
               rien tant que la vague 1 n'est pas close. On l'explique plutôt que
               d'afficher une erreur : ce n'est pas une panne, c'est l'ordre prévu. */ ?>
      <div class="gate">
        <div class="gate-ico">⏳</div>
        <h2>Ce n'est pas encore votre tour</h2>
        <p>Vous signez <strong>en dernier</strong>, une fois que toutes les autres parties
        se sont engagées. Vous recevrez un mail et un SMS <strong>automatiquement</strong>
        dès la dernière signature — vous n'avez rien à surveiller.</p>
      </div>

    <?php elseif (!$otpOk): ?>
      <?php /* LA PORTE D'ENTRÉE. Le bail n'est pas affiché derrière : tant que le
               code n'est pas validé, on ne sait pas qui est là, et le contenu d'un
               bail (loyer, identités, adresse) n'a pas à s'afficher pour quelqu'un
               qui n'a fait que suivre un lien transféré. */ ?>
      <div class="gate">
        <div class="gate-ico">📱</div>
        <h2>Vérifions que c'est bien vous</h2>
        <p>Pour ouvrir votre <?= $h($bailNom) ?>, nous envoyons un <strong>code à 6 chiffres</strong>
        par SMS au <strong><?= $h(otp_masquer_numero($telSig)) ?></strong>.</p>

        <?php if ($otpFlash): ?>
          <div class="<?= !empty($otpFlash['ok']) ? 'gate-ok' : 'err' ?>"><?= $h($otpFlash['msg']) ?></div>
        <?php endif; ?>

        <?php if (!$otpInfo && empty($otpFlash['ok'])): ?>
          <form method="post" class="gate-form">
            <input type="hidden" name="t" value="<?= $h($token) ?>">
            <input type="hidden" name="action" value="otp_send">
            <button class="btn" type="submit">📩 Recevoir mon code par SMS</button>
          </form>
        <?php else: ?>
          <form method="post" class="gate-form" autocomplete="one-time-code">
            <input type="hidden" name="t" value="<?= $h($token) ?>">
            <input type="hidden" name="action" value="otp_verify">
            <label class="fld">Code reçu par SMS</label>
            <?php /* inputmode numeric + autocomplete one-time-code : sur mobile, le
                     clavier s'ouvre en chiffres et iOS propose le code du SMS d'un
                     seul geste. Sans ça, le signataire bascule entre deux
                     applications pour recopier six chiffres. */ ?>
            <input type="text" name="otp_code" class="otp-input" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
            <button class="btn" type="submit">Valider et ouvrir le document</button>
          </form>
          <form method="post" class="gate-form" style="margin-top:6px;">
            <input type="hidden" name="t" value="<?= $h($token) ?>">
            <input type="hidden" name="action" value="otp_send">
            <button class="btn-sec" type="submit" style="width:100%;">↻ Je n'ai rien reçu — renvoyer un code</button>
          </form>
          <?php if ($otpInfo): ?>
            <p class="gate-note">Code valable jusqu'à <?= $h(date('H:i', strtotime((string)$otpInfo['expire_at']))) ?>
            · <?= (int)$otpInfo['max_tentatives'] - (int)$otpInfo['tentatives'] ?> essai(s) restant(s).</p>
          <?php endif; ?>
        <?php endif; ?>

        <p class="gate-note">
          Ce code atteste que vous détenez le téléphone enregistré pour vous. Il est
          conservé sous forme chiffrée et ne peut servir qu'une fois.
        </p>
      </div>

    <?php else: ?>

    <div class="terms">
      <div class="row"><span class="k">Local</span><span class="v"><?= $h($adresse !== '' ? $adresse : $sig['reference_bien']) ?></span></div>
      <div class="row"><span class="k">Preneur</span><span class="v"><?= $h($preneur ?: '—') ?></span></div>
      <div class="row"><span class="k">Prise d'effet</span><span class="v"><?= $h($fmtDate($sig['date_prise_effet'] ?? null)) ?></span></div>
      <?php if ($hasMontants): ?>
      <div class="row"><span class="k">Total par <?= $h($perLbl) ?><?= $tvaOn ? ' (TTC)' : '' ?></span><span class="v"><?= $h($eur2($totEcheance)) ?></span></div>
      <div class="row"><span class="k"><strong>Total à verser à la signature</strong></span><span class="v"><strong><?= $h($eur2($totSignature)) ?></strong></span></div>
      <?php endif; ?>
    </div>

    <?php if ($rib && $totSignature > 0 && !$isCaution && $roleBase !== 'mandataire' && $roleBase !== 'bailleur'): ?>
    <?php /* Le RIB ne concerne QUE celui qui paie. L'afficher au mandataire ou au
             bailleur n'aurait aucun sens, et à la caution ce serait pire : elle
             pourrait croire qu'on lui réclame la somme dès la signature, alors
             qu'un cautionnement ne se met en jeu qu'en cas de défaillance. */ ?>
    <div class="rib">
      <div class="rib-t">🏦 Où verser les <?= $h($eur2($totSignature)) ?></div>
      <?php if ($rib['titulaire'] !== ''): ?><div class="rib-l"><span>Titulaire</span><b><?= $h($rib['titulaire']) ?></b></div><?php endif; ?>
      <?php if ($rib['banque'] !== ''): ?><div class="rib-l"><span>Banque</span><b><?= $h($rib['banque']) ?></b></div><?php endif; ?>
      <div class="rib-l"><span>IBAN</span><b class="rib-iban" id="rib-iban"><?= $h($rib['iban']) ?></b></div>
      <?php if ($rib['bic'] !== ''): ?><div class="rib-l"><span>BIC</span><b><?= $h($rib['bic']) ?></b></div><?php endif; ?>
      <button type="button" class="btn-sec rib-copy" id="rib-copy">📋 Copier l'IBAN</button>
      <div class="rib-n">Virement à effectuer pour la remise des clés. Ce versement ne conditionne
      pas votre signature : vous pouvez signer maintenant et virer ensuite.</div>
    </div>
    <?php endif; ?>

    <?php if (($sig['role_code'] ?? '') === 'preneur'): ?>
      <div class="assur">
        <div style="font-weight:800;color:#243B5C;margin-bottom:3px;">🛡️ Attestation d'assurance <span class="facultatif">facultatif ici</span></div>
        <?php /* Le dépôt de l'attestation NE BLOQUE PAS la signature — et l'écran
                 doit le dire. Sans cette mention, le locataire qui n'a pas son
                 attestation sous la main referme la page en croyant qu'il ne peut
                 pas signer : on perd la signature pour une pièce qui n'est due
                 qu'à la remise des clés. */ ?>
        <p style="font-size:12.5px;color:#475569;margin:0 0 8px;">Vous pouvez la joindre dès maintenant
        (PDF ou photo) — elle sera exigée <strong>à la remise des clés</strong>, pas pour signer.
        <b>Vous pouvez signer sans elle</b> et nous l'envoyer plus tard.</p>
        <input type="file" id="assur-file" accept="application/pdf,image/*">
        <div style="margin-top:8px;"><button type="button" class="btn-sec" id="assur-btn">📎 Charger l'attestation</button></div>
        <div class="photo-note" id="assur-status"></div>
      </div>
    <?php endif; ?>

    <?php if ($bailHtml !== ''): ?>
      <details class="bailbox">
        <summary>📄 Lire le bail complet avant de signer</summary>
        <div class="bailfull"><?= $bailHtml /* HTML généré côté serveur, mêmes classes que le PDF */ ?></div>
      </details>
    <?php endif; ?>

    <?php if ($dejaSigne): ?>
      <?php /* ── LA PAGE DE REMERCIEMENT ────────────────────────────────────
               L'écran s'arrêtait sur un bandeau vert et « vous pouvez fermer
               cette page ». Pour quelqu'un qui vient d'engager trois ans de
               loyer, c'est court : il ne sait ni ce qui se passe ensuite, ni ce
               qu'il doit faire, ni quand il recevra son exemplaire. On distingue
               donc le MOMENT de la signature (remerciement, ce qui suit) du
               simple retour sur la page plus tard (rappel de ce qui a été fait). */ ?>
      <div class="merci">
        <div class="merci-ico"><?= $justSigned ? '🎉' : '✅' ?></div>
        <h2><?= $justSigned
              ? ($roleBase === 'mandataire' || $roleBase === 'bailleur' ? 'Signature enregistrée' : 'Bienvenue, et merci !')
              : 'Vous avez déjà signé' ?></h2>
        <?php if ($justSigned && $roleBase !== 'mandataire' && $roleBase !== 'bailleur'): ?>
          <p>Votre <?= $h($bailNom) ?> est signé. Nous sommes heureux de vous compter
          parmi nos locataires<?= $adresse !== '' ? ' au ' . $h($adresse) : '' ?>.</p>
        <?php elseif ($justSigned): ?>
          <p>Votre signature est enregistrée pour ce <?= $h($bailNom) ?>.</p>
        <?php endif; ?>

        <div class="merci-p">
          Signé par <b><?= $h($sig['nom_signataire']) ?></b>
          le <b><?= $h(date('d/m/Y \à H\hi', strtotime((string)$sig['signed_at']))) ?></b>.
          <div class="proof">Preuve enregistrée — adresse IP <?= $h($sig['ip'] ?: '—') ?>
          <?= !empty($sig['otp_valide_at']) ? ' · code SMS validé' : '' ?>.</div>
        </div>

        <?php /* « Et maintenant ? » — la question que se pose tout signataire, et
                 à laquelle personne ne répondait. */ ?>
        <div class="merci-suite">
          <b>Et maintenant ?</b>
          <?php if ($ceremSignes < $ceremTotal): ?>
            <div>Il reste <b><?= (int)($ceremTotal - $ceremSignes) ?> signature<?= ($ceremTotal - $ceremSignes) > 1 ? 's' : '' ?></b>
            à recueillir. Dès la dernière, vous recevrez par mail
            <b>l'acte complet</b> — <?= $h($bailNom) ?><?= $sigAnnexes ? ', ses ' . count($sigAnnexes) . ' annexes' : '' ?>
            et la page de justificatifs qui atteste des signatures.</div>
          <?php else: ?>
            <div><b>Toutes les parties ont signé.</b> L'acte complet vous est adressé par mail
            à l'instant — <?= $h($bailNom) ?><?= $sigAnnexes ? ', ses ' . count($sigAnnexes) . ' annexes' : '' ?>
            et la page de justificatifs.</div>
          <?php endif; ?>
          <?php if ($rib && $totSignature > 0 && !$isCaution && $roleBase !== 'mandataire' && $roleBase !== 'bailleur'): ?>
            <div style="margin-top:7px;">Pensez au virement de <b><?= $h($eur2($totSignature)) ?></b>
            sur l'IBAN indiqué plus haut, à effectuer avant la remise des clés.</div>
          <?php endif; ?>
        </div>

        <?php /* Un bouton pour SORTIR. Sur mobile, « fermez cette page » ne veut
                 rien dire : il n'y a pas de croix. window.close() ne marche que
                 sur un onglet ouvert par script — on ne promet donc rien qu'on ne
                 puisse tenir, et le bouton replie simplement l'écran sur un
                 message de fin. */ ?>
        <button type="button" class="btn merci-fin" id="btn-fin">✔️ J'ai terminé</button>
      </div>

    <?php /* L'ancien `elseif ($expired)` vivait ICI — inatteignable, cf. le bandeau
             remonté en tête de chaîne. Ne pas le remettre : un second endroit qui
             parle d'expiration, c'est un second endroit à corriger. */ ?>
    <?php else: ?>
      <?php if ($flash): ?><div class="err"><?= $h($flash) ?></div><?php endif; ?>
      <form method="post" id="sigform">
        <input type="hidden" name="t" value="<?= $h($token) ?>">
        <input type="hidden" name="signature_data" id="signature_data">

        <label class="fld">Votre nom et prénom</label>
        <input type="text" name="nom_signataire" value="<?= $h($_POST['nom_signataire'] ?? '') ?>" placeholder="Ex. Jean Dupont" required>

        <?php if ($sigAnnexes): ?>
        <?php /* ⚠️🔥 LES ANNEXES ÉTAIENT UNE SIMPLE LISTE DE NOMS.
                 On faisait cocher « j'ai pris connaissance des N pièces annexées »
                 sans qu'aucune ne soit ouvrable : on demandait d'attester une
                 lecture matériellement impossible. Il suffisait au locataire de
                 dire qu'il n'avait jamais pu ouvrir le règlement de copropriété
                 pour faire tomber la case — et avec elle l'opposabilité de
                 l'annexe, donc tout l'intérêt de l'avoir jointe.
                 Chaque pièce s'ouvre désormais (p/bail_annexe.php), et la case
                 ne se coche qu'une fois toutes les pièces ouvertes. */ ?>
        <div class="annexes">
          <b>Pièces annexées à cet acte (<?= count($sigAnnexes) ?>)</b>
          <ul class="axlist">
            <?php foreach ($sigAnnexes as $iAx => $ax): ?>
              <?php
                $axId = 0;
                if (preg_match('/^(?:ged|mbo):(\d+)$/', (string)($ax['uid'] ?? ''), $mAx)) $axId = (int)$mAx[1];
                $axUrl = $axId > 0
                    ? (function_exists('app_url') ? app_url('/p/bail_annexe.php') : '/p/bail_annexe.php')
                      . '?t=' . urlencode($token) . '&d=' . $axId
                    : '';
              ?>
              <li>
                <?php $dejaVue = isset($axLues[(string)($ax['uid'] ?? '')]); ?>
                <?php /* ⚠️ PAS de target="_blank". Un nouvel onglet affichant un PDF
                         plein écran sur mobile est un cul-de-sac : le signataire ne
                         sait plus revenir. On navigue dans le MÊME onglet vers une
                         page à nous, qui porte une barre de retour permanente. Le
                         formulaire déjà rempli est sauvegardé avant de partir et
                         restauré au retour (voir le JS). */ ?>
                <?php if ($axUrl !== ''): ?>
                  <a class="axlink" href="<?= $h($axUrl) ?>" data-ax="<?= (int)$iAx ?>">📄
                     <?= $h((string)($ax['nom'] ?? 'Pièce')) ?>
                     <span class="axopen">ouvrir</span></a>
                  <span class="axvu" data-vu="<?= (int)$iAx ?>"<?= $dejaVue ? '' : ' hidden' ?>>✓ consultée<?php
                    if ($dejaVue && $axLues[(string)$ax['uid']] !== '')
                        echo ' le ' . $h(date('d/m à H\hi', strtotime($axLues[(string)$ax['uid']]))); ?></span>
                <?php else: ?>
                  <?php /* Pièce dont l'identifiant n'est pas exploitable : on l'affiche
                           quand même — elle fait partie de l'acte — mais on ne prétend
                           pas qu'elle est consultable ici. */ ?>
                  📄 <?= $h((string)($ax['nom'] ?? 'Pièce')) ?>
                  <span class="axko">à demander à votre agence</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <small>Elles vous ont aussi été transmises par mail et <b>font partie intégrante du
          document signé</b>. Ouvrez-les avant de cocher la case ci-dessous.</small>
          <div id="ax-reste" class="axreste"></div>
        </div>
        <?php endif; ?>

        <label class="chk">
          <input type="checkbox" name="lu_approuve" value="1" required>
          <span>J'ai lu l'intégralité du <?= $h($bailNom) ?><?= $sigAnnexes ? ' et des ' . count($sigAnnexes) . ' pièces annexées' : '' ?> et j'en accepte les termes. Ma signature électronique a valeur d'engagement.</span>
        </label>

        <label class="fld">Recopiez la mention : « <?= $h($mentionAttendue) ?> »</label>
        <?php /* Fonte manuscrite : ce que le signataire RECOPIE de sa main doit se
                 distinguer du texte imprimé qu'il ne fait que lire. C'est la même
                 intention qu'une mention manuscrite sur papier — la forme dit que
                 ces mots-là viennent de lui. */ ?>
        <input type="text" name="mention_manuscrite" class="manuscrit" data-req="1"
               value="<?= $h($_POST['mention_manuscrite'] ?? '') ?>"
               placeholder="Recopiez ici la mention ci-dessus…" required>

        <label class="fld">Date</label>
        <?php /* ⚠️ Saisie GUIDÉE : les barres obliques s'écrivent toutes seules et
                 le clavier s'ouvre en chiffres. Au doigt, sur un téléphone, taper
                 « 18/08/2026 » demandait de basculer deux fois vers le clavier des
                 symboles pour deux caractères que la machine connaît déjà. */ ?>
        <input type="text" name="date_manuscrite" id="date_manuscrite" class="manuscrit" data-req="1"
               inputmode="numeric" maxlength="10" autocomplete="off"
               value="<?= $h($_POST['date_manuscrite'] ?? '') ?>" placeholder="jj/mm/aaaa" required>
        <div class="aide-date">Aujourd'hui : <button type="button" class="lienbtn" id="date-auj"><?= date('d/m/Y') ?></button></div>

        <?php /* ── DEUX FAÇONS DE SIGNER, SANS HIÉRARCHIE ────────────────────────
                 Le tracé au doigt n'a AUCUNE supériorité légale sur un nom tapé :
                 ni l'un ni l'autre n'est une signature manuscrite au sens du droit,
                 ce sont deux représentations. Ce qui vaut signature électronique,
                 c'est le procédé fiable d'identification (art. 1367 al. 2 C. civ.) —
                 le lien nominatif, le code SMS, l'horodatage, l'IP et l'empreinte.
                 Le gribouillis au doigt est même le plus faible : il n'est
                 comparable à rien. On propose donc les deux à égalité, et l'écran
                 ne suggère nulle part que l'un « vaut mieux ». */ ?>
        <label class="fld">Votre signature</label>
        <input type="hidden" name="signature_mode" id="signature_mode"
               value="<?= $h(($_POST['signature_mode'] ?? '') === 'clavier' ? 'clavier' : 'trace') ?>">
        <div class="modetabs" role="tablist">
          <button type="button" class="modetab" id="mode-trace" data-mode="trace">✍️ Dessiner ma signature</button>
          <button type="button" class="modetab" id="mode-clavier" data-mode="clavier">⌨️ Saisir mon nom</button>
        </div>

        <div id="bloc-trace">
          <div class="padwrap">
            <canvas class="pad" id="pad"></canvas>
            <div class="padrow">
              <span class="photo-note">Signez dans le cadre ci-dessus.</span>
              <button type="button" class="btn-sec" id="pad-clear">Effacer</button>
            </div>
          </div>
        </div>

        <div id="bloc-clavier" style="display:none;">
          <?php /* Retaper son nom EST l'acte de signer : sans ce geste délibéré,
                   un simple clic suffirait, et rien ne distinguerait la signature
                   d'un bouton. Le serveur compare cette saisie au nom déclaré
                   plus haut (comparaison souple sur la casse et les accents). */ ?>
          <input type="text" name="nom_clavier" id="nom_clavier" class="sigtype"
                 value="<?= $h($_POST['nom_clavier'] ?? '') ?>"
                 placeholder="Retapez votre nom et prénom" autocomplete="off">
          <div class="photo-note">Retapez votre nom exactement comme indiqué en haut du formulaire.
          Cette saisie vaut signature, au même titre qu'un tracé.</div>
        </div>


        <?php /* ── LA BARRE « SUIVANT » ────────────────────────────────────
                 Le formulaire fait plusieurs écrans de haut sur un téléphone :
                 nom, annexes, case, mention, date, signature, photo. On ne voit
                 jamais ce qui manque, et le bouton « Signer » refusait sans dire
                 . La barre reste visible et conduit d'un champ obligatoire
                 au suivant ; quand tout est rempli, elle devient le bouton de
                 signature. */ ?>
        <div class="barre" id="barre">
          <button type="button" class="btn btn-suivant" id="btn-suivant">Suivant →</button>
          <button class="btn" type="submit" id="btn-signer">✍️ Signer le <?= $h($bailNom) ?></button>
        </div>
        <p class="legal">
          Signature électronique (procédé simple, art. 1366-1367 du Code civil) : votre adresse IP (<?= $h(bsig_client_ip() ?: 'non détectée') ?>),
          l'horodatage, la mention recopiée et le tracé de votre signature sont enregistrés comme preuve. Lien valable <?= (int)(BSIG_TTL_MIN/60) ?> heures.
        </p>
      </form>
    <?php endif; ?>

    <?php endif; /* ── fin de la porte d'entrée / vague ── */ ?>

    <?php /* ── OÙ EN EST LA CÉRÉMONIE ────────────────────────────────────────
             Chacun voit qui a signé, et QUAND — à la minute. Sans cet écran, un
             colocataire n'a aucun moyen de savoir si l'autre a signé : il
             téléphone à l'agence, ou il signe une deuxième fois « au cas où ».

             ⚠️ Affiché DERRIÈRE la porte uniquement (le mandataire en attente le
             voit aussi, c'est précisément ce qu'il lui faut). On montre les noms
             et les qualités — jamais les emails ni les mobiles des autres
             parties : ils n'ont pas à circuler entre signataires. */ ?>
    <?php if ($ceremonie): ?>
    <div class="cerem">
      <div class="cerem-h">👥 Les signataires de cet acte</div>
      <?php foreach ($ceremonie as $cs): ?>
        <?php
          $csSigne = (($cs['statut'] ?? '') === 'signe');
          $csMoi   = ((int)$cs['id'] === $sigId);
          $csQuand = $csSigne && !empty($cs['signed_at'])
                   ? date('d/m/Y \à H\hi', strtotime((string)$cs['signed_at'])) : '';
        ?>
        <div class="cerem-l<?= $csMoi ? ' cerem-moi' : '' ?>">
          <span class="cerem-p"><?= $csSigne ? '✅' : '⏳' ?></span>
          <span class="cerem-t">
            <b><?= $h($roleLibelle((string)($cs['role_code'] ?? ''))) ?></b><?= $csMoi ? ' <em>(vous)</em>' : '' ?>
            <?php if (trim((string)($cs['nom_signataire'] ?? '')) !== ''): ?>
              <span class="cerem-n">— <?= $h($cs['nom_signataire']) ?></span>
            <?php endif; ?>
            <span class="cerem-d">
              <?php if ($csSigne): ?>
                Signé le <?= $h($csQuand) ?><?php if (($cs['signature_mode'] ?? '') !== ''): ?>
                  · <?= $h(($cs['signature_mode'] === 'clavier') ? 'nom saisi' : 'signature tracée') ?>
                <?php endif; ?>
              <?php elseif ((int)($cs['vague'] ?? 1) >= 2): ?>
                Signera en dernier, quand toutes les autres parties auront signé
              <?php else: ?>
                En attente de signature
              <?php endif; ?>
            </span>
          </span>
        </div>
      <?php endforeach; ?>
      <div class="cerem-f">
        <?= (int)$ceremSignes ?> signature<?= $ceremSignes > 1 ? 's' : '' ?> sur <?= (int)$ceremTotal ?>.
        <?php if ($ceremSignes < $ceremTotal): ?>
          L'acte définitif — bail, annexes et justificatifs réunis — sera adressé à
          toutes les parties dès la dernière signature.
        <?php else: ?>
          <strong>Toutes les parties ont signé.</strong>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ⚠️ Le JS pilote le pavé de signature et la caméra : chargé alors que le
         formulaire n'existe pas (porte fermée, vague en attente), il planterait
         sur `document.getElementById('pad').getContext(...)` — et un plantage JS
         en tête de page fige TOUT le reste, y compris le bouton « Recevoir mon
         code ». On ne le charge donc que lorsque le formulaire est réellement
         rendu. */ ?>
<?php if (!$dejaSigne && !$expired && $otpOk && !$vagueFermee): ?>
<script>
(function(){
  // ── Pavé de signature (canvas, tactile + souris) ──
  var pad = document.getElementById('pad');
  var hidden = document.getElementById('signature_data');
  var ctx = pad.getContext('2d');
  var drawing = false, hasDrawn = false, last = null;
  function fit(){
    var r = pad.getBoundingClientRect();
    var dpr = window.devicePixelRatio || 1;
    // Sauvegarde le tracé courant avant redimensionnement
    var prev = hasDrawn ? pad.toDataURL() : null;
    pad.width = Math.round(r.width * dpr);
    pad.height = Math.round(r.height * dpr);
    ctx.setTransform(dpr,0,0,dpr,0,0);
    ctx.lineWidth = 2.2; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.strokeStyle='#111827';
    if (prev){ var img=new Image(); img.onload=function(){ ctx.drawImage(img,0,0,r.width,r.height); }; img.src=prev; }
  }
  function pos(e){
    var r = pad.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }
  function start(e){ drawing=true; last=pos(e); e.preventDefault(); }
  function move(e){
    if(!drawing) return;
    var p = pos(e);
    ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.stroke();
    last = p; hasDrawn = true; e.preventDefault();
  }
  function end(){ drawing=false; }
  pad.addEventListener('mousedown',start); pad.addEventListener('mousemove',move);
  window.addEventListener('mouseup',end);
  pad.addEventListener('touchstart',start,{passive:false});
  pad.addEventListener('touchmove',move,{passive:false});
  pad.addEventListener('touchend',end);
  document.getElementById('pad-clear').addEventListener('click',function(){
    ctx.clearRect(0,0,pad.width,pad.height); hasDrawn=false; hidden.value='';
  });
  window.addEventListener('resize',fit); fit();


  /* ── Bascule tracé / clavier ──────────────────────────────────────────
     Le mode retenu part dans un champ caché : le serveur ne déduit RIEN de la
     présence ou de l'absence d'un tracé — il applique le mode déclaré. Deviner
     ferait passer pour « clavier » un tracé qui aurait échoué à s'encoder. */
  var champMode = document.getElementById('signature_mode');
  var blocTrace = document.getElementById('bloc-trace');
  var blocClav  = document.getElementById('bloc-clavier');
  var champClav = document.getElementById('nom_clavier');
  var ongTrace  = document.getElementById('mode-trace');
  var ongClav   = document.getElementById('mode-clavier');

  function appliqueMode(m){
    var estClav = (m === 'clavier');
    champMode.value = estClav ? 'clavier' : 'trace';
    blocTrace.style.display = estClav ? 'none' : '';
    blocClav.style.display  = estClav ? '' : 'none';
    ongTrace.classList.toggle('on', !estClav);
    ongClav.classList.toggle('on', estClav);
    // Un canvas dimensionné pendant qu'il était caché mesure 0 : on le refait.
    if (!estClav) { try { fit(); } catch(e){} }
    if (typeof majBarre === 'function') majBarre();
  }
  ongTrace.addEventListener('click', function(){ appliqueMode('trace'); });
  ongClav.addEventListener('click',  function(){ appliqueMode('clavier'); if(champClav) champClav.focus(); });
  appliqueMode(champMode.value || 'trace');   // restaure le choix après une erreur de saisie

  /* ══════════════════════════════════════════════════════════════════════
     LE PARCOURS GUIDÉ
     ══════════════════════════════════════════════════════════════════════ */

  /* ── 1. LES ANNEXES DOIVENT AVOIR ÉTÉ OUVERTES ────────────────────────
     La case « j'ai lu le bail et les N pièces annexées » se cochait sans
     qu'aucune pièce ne soit consultable. On ouvre maintenant chaque annexe,
     et la case reste VERROUILLÉE tant qu'elles ne l'ont pas toutes été.

     ⚠️ Ce n'est pas une preuve de lecture — personne ne peut prouver qu'un
     document a été lu. C'est la preuve que le document a été MIS À
     DISPOSITION et OUVERT, ce qui est exactement ce que la loi demande, et
     tout ce qu'un écran peut honnêtement constater. */
  var axLiens  = Array.prototype.slice.call(document.querySelectorAll('.axlink'));
  var axReste  = document.getElementById('ax-reste');
  var caseLu   = document.querySelector('input[name="lu_approuve"]');
  var axVues   = {};

  // État initial : ce que le SERVEUR a déjà servi (badge « consultée » déjà posé).
  document.querySelectorAll('.axvu:not([hidden])').forEach(function(v){
    axVues[v.getAttribute('data-vu')] = 1;
  });

  function axMaj(){
    if (!axLiens.length) return true;
    var reste = axLiens.length - Object.keys(axVues).length;
    /* ⚠️ LA CASE N'EST PLUS VERROUILLÉE — arbitrage d'Emmanuel du 18/08.
       Ma version bloquait la case tant que toutes les pièces n'étaient pas
       ouvertes. C'était contraire à la doctrine « rien ne bloque jamais » : un
       signataire qui a déjà lu les annexes par mail se serait retrouvé coincé,
       et une pièce illisible aurait suffi à arrêter la cérémonie.
       La case reste donc une DÉCLARATION libre du signataire ; ce qui fait foi,
       c'est le journal serveur des pièces réellement servies (annexes_lues_json),
       reporté au certificat. On informe, on ne contraint pas. */
    if (reste === 0 && caseLu && !caseLu.checked && !caseLu.dataset.decoche) {
      // Tout a été consulté : on coche pour lui, il peut toujours décocher.
      caseLu.checked = true;
    }
    if (axReste) {
      axReste.textContent = reste > 0
        ? ('Il vous reste ' + reste + ' pièce' + (reste > 1 ? 's' : '') + ' à ouvrir. '
           + 'Vous pouvez signer sans les ouvrir ici, mais la consultation est enregistrée.')
        : '✓ Toutes les pièces annexées ont été consultées.';
      axReste.style.color = reste > 0 ? '#b45309' : '#15803d';
    }
    return reste === 0;
  }
  if (caseLu) caseLu.addEventListener('change', function(){
    // Mémorise un décochage volontaire pour ne pas le recocher dans son dos.
    if (!this.checked) this.dataset.decoche = '1'; else delete this.dataset.decoche;
  });

  /* ── AVANT DE PARTIR LIRE UNE PIÈCE, ON GARDE LA SAISIE ────────────────
     La navigation se fait dans le même onglet (seul moyen fiable de revenir sur
     mobile) : sans cette sauvegarde, le signataire qui a rempli son nom et la
     mention les perdrait en ouvrant une annexe, et recommencerait — ou
     abandonnerait. */
  var CLE = 'bsig_form_' + (location.search.match(/t=([a-f0-9]+)/) || [,''])[1];
  function sauverForm(){
    try {
      var d = {};
      document.querySelectorAll('#sigform input[type=text], #sigform input[type=hidden]').forEach(function(i){
        if (i.name && i.value) d[i.name] = i.value;
      });
      if (caseLu) d.__lu = caseLu.checked ? 1 : 0;
      sessionStorage.setItem(CLE, JSON.stringify(d));
    } catch (e) {}
  }
  function restaurerForm(){
    try {
      var d = JSON.parse(sessionStorage.getItem(CLE) || '{}');
      Object.keys(d).forEach(function(k){
        if (k === '__lu') { if (caseLu && d[k]) caseLu.checked = true; return; }
        var i = document.querySelector('#sigform [name="' + k + '"]');
        if (i && !i.value) i.value = d[k];
      });
    } catch (e) {}
  }
  restaurerForm();

  axLiens.forEach(function(a){
    a.addEventListener('click', function(){
      sauverForm();
      var i = a.getAttribute('data-ax');
      axVues[i] = 1;
      var vu = document.querySelector('.axvu[data-vu="' + i + '"]');
      if (vu) vu.hidden = false;
      axMaj();
    });
  });
  axMaj();

  /* ── 2. LA DATE S'ÉCRIT TOUTE SEULE ───────────────────────────────────
     Les barres obliques sont posées par la machine ; le signataire ne tape
     que des chiffres, sur un clavier numérique. Sur un téléphone, taper
     « 18/08/2026 » imposait sinon deux allers-retours vers le clavier des
     symboles pour deux caractères parfaitement prévisibles. */
  var champDate = document.getElementById('date_manuscrite');
  function masqueDate(v){
    var n = (v || '').replace(/\D/g, '').slice(0, 8);
    if (n.length <= 2) return n;
    if (n.length <= 4) return n.slice(0, 2) + '/' + n.slice(2);
    return n.slice(0, 2) + '/' + n.slice(2, 4) + '/' + n.slice(4);
  }
  if (champDate) {
    champDate.addEventListener('input', function(){
      /* On ne reformate PAS quand l'utilisateur efface : sinon la barre
         oblique se remet en place à chaque retour arrière et le curseur
         devient impossible à sortir de « 18/ ». */
      var avant = this.value;
      var apres = masqueDate(avant);
      if (apres !== avant && avant.slice(-1) !== '/') this.value = apres;
    });
    champDate.addEventListener('blur', function(){ this.value = masqueDate(this.value); });
  }
  var btnAuj = document.getElementById('date-auj');
  if (btnAuj && champDate) {
    btnAuj.addEventListener('click', function(){
      champDate.value = btnAuj.textContent.trim();
      champDate.dispatchEvent(new Event('input'));
      majBarre();
    });
  }

  /* ── 3. LA BARRE « SUIVANT » ──────────────────────────────────────────
     Elle nomme ce qui manque et y conduit. Le bouton « Signer » n'apparaît
     que lorsque tout est rempli : un bouton qui refuse est plus décourageant
     qu'un bouton absent, surtout après avoir rempli six champs. */
  var btnSuivant = document.getElementById('btn-suivant');
  var btnSigner  = document.getElementById('btn-signer');
  var champNom   = document.querySelector('input[name="nom_signataire"]');

  function etapes(){
    var l = [];
    if (champNom)  l.push({el: champNom,  ok: champNom.value.trim() !== '',  quoi: 'votre nom et prénom'});
    /* ⚠️ Les annexes NE SONT PLUS une étape obligatoire : les y laisser aurait
       gardé le bouton « Signer » caché tant qu'elles n'étaient pas toutes
       ouvertes, c'est-à-dire exactement le blocage qu'on vient de retirer de la
       case de lecture. Leur consultation est encouragée et journalisée, pas
       exigée. */
    if (caseLu)    l.push({el: caseLu,    ok: caseLu.checked,                quoi: 'cocher la lecture du document'});
    var champMention = document.querySelector('input[name="mention_manuscrite"]');
    if (champMention) l.push({el: champMention, ok: champMention.value.trim() !== '', quoi: 'recopier la mention'});
    if (champDate) l.push({el: champDate, ok: champDate.value.trim().length >= 8, quoi: 'la date'});
    // La signature elle-même : tracé dessiné, ou nom retapé selon le mode.
    var modeClav = champMode && champMode.value === 'clavier';
    if (modeClav) {
      l.push({el: champClav, ok: champClav && champClav.value.trim() !== '', quoi: 'retaper votre nom pour signer'});
    } else {
      l.push({el: pad, ok: hasDrawn, quoi: 'dessiner votre signature'});
    }
    return l;
  }

  function majBarre(){
    var manque = etapes().filter(function(e){ return !e.ok; });
    if (!btnSuivant || !btnSigner) return;
    if (manque.length === 0) {
      btnSuivant.style.display = 'none';
      btnSigner.style.display  = '';
    } else {
      btnSuivant.style.display = '';
      btnSigner.style.display  = 'none';
      btnSuivant.textContent   = 'Suivant : ' + manque[0].quoi + ' →';
    }
  }

  if (btnSuivant) {
    btnSuivant.addEventListener('click', function(){
      var manque = etapes().filter(function(e){ return !e.ok; });
      if (!manque.length) return;
      var cible = manque[0].el;
      if (!cible) return;
      cible.scrollIntoView({behavior: 'smooth', block: 'center'});
      /* Le focus n'est posé que sur ce qui se saisit : le donner à une case
         ou à un canvas ferait apparaître un liseré sans rien apporter. */
      if (cible.tagName === 'INPUT' && cible.type === 'text') {
        setTimeout(function(){ cible.focus(); }, 320);
      }
    });
  }
  /* On réévalue à chaque frappe et à chaque clic : c'est le seul moyen que la
     barre dise toujours la vérité, y compris après un retour arrière. */
  ['input', 'change', 'click'].forEach(function(ev){
    document.addEventListener(ev, function(){ setTimeout(majBarre, 0); }, true);
  });
  majBarre();

  /* ── 4. L'IBAN SE COPIE ───────────────────────────────────────────────
     Recopier un IBAN à la main depuis un téléphone est le geste le plus
     propice à l'erreur de tout le parcours — et l'erreur se solde par un
     virement perdu. */
  var btnRib = document.getElementById('rib-copy');
  var ibanEl = document.getElementById('rib-iban');
  if (btnRib && ibanEl) {
    btnRib.addEventListener('click', function(){
      var iban = (ibanEl.textContent || '').replace(/\s+/g, '');
      var ok = function(){ btnRib.textContent = '✓ IBAN copié'; setTimeout(function(){ btnRib.textContent = '📋 Copier l’IBAN'; }, 2500); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(iban).then(ok, function(){ ok(); });
      } else {
        /* Hors contexte sécurisé (http local), l'API Clipboard est absente :
           on retombe sur la sélection, que l'utilisateur copie à la main. */
        var r = document.createRange(); r.selectNodeContents(ibanEl);
        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
        btnRib.textContent = 'IBAN sélectionné — appuyez sur « Copier »';
      }
    });
  }

  /* ── RECADRAGE DU TRACÉ SUR CE QUI EST RÉELLEMENT DESSINÉ ──────────────
     `pad.toDataURL()` exporte TOUT le canvas, y compris le vide autour. Une
     signature tracée petit, dans un coin, ressortait donc minuscule au milieu
     d'un grand rectangle transparent — et comme le PDF contraint l'image à
     64 px de haut, elle devenait illisible, voire invisible.

     On calcule la boîte englobante des pixels non transparents et on n'exporte
     que celle-là : quelle que soit la taille du geste, la signature remplit son
     cadre dans l'acte. Marge de 6 px pour ne pas raser les extrémités.

     Repli sur l'export intégral si quoi que ce soit échoue (getImageData peut
     être refusé sur un canvas « teinté ») : mieux vaut une signature mal cadrée
     qu'une signature perdue. */
  function padRecadre(){
    try {
      var w = pad.width, hgt = pad.height;
      var d = ctx.getImageData(0, 0, w, hgt).data;
      var minX = w, minY = hgt, maxX = -1, maxY = -1;
      for (var y = 0; y < hgt; y++) {
        for (var x = 0; x < w; x++) {
          if (d[(y * w + x) * 4 + 3] > 8) {          // canal alpha : pixel encré
            if (x < minX) minX = x;
            if (x > maxX) maxX = x;
            if (y < minY) minY = y;
            if (y > maxY) maxY = y;
          }
        }
      }
      if (maxX < 0) return pad.toDataURL('image/png');   // rien d encre : on n invente pas
      var m = 6;
      minX = Math.max(0, minX - m); minY = Math.max(0, minY - m);
      maxX = Math.min(w - 1, maxX + m); maxY = Math.min(hgt - 1, maxY + m);
      var cw = maxX - minX + 1, ch = maxY - minY + 1;
      var out = document.createElement('canvas');
      out.width = cw; out.height = ch;
      out.getContext('2d').drawImage(pad, minX, minY, cw, ch, 0, 0, cw, ch);
      return out.toDataURL('image/png');
    } catch (err) {
      return pad.toDataURL('image/png');
    }
  }

  document.getElementById('sigform').addEventListener('submit', function(e){
    if (champMode.value === 'clavier') {
      if (!champClav || !champClav.value.trim()) {
        e.preventDefault(); alert('Retapez votre nom pour signer, ou choisissez « Dessiner ma signature ».'); return;
      }
      hidden.value = '';           // pas de tracé : le nom saisi fait foi
      return;
    }
    if(!hasDrawn){ e.preventDefault(); alert('Merci de signer dans le cadre avec votre doigt, ou choisissez « Saisir mon nom ».'); return; }
    hidden.value = padRecadre();
  });
})();
</script>
<?php endif; ?>
<?php if ($dejaSigne): ?>
<script>
(function(){
  /* « J'ai terminé » — sur un téléphone, « vous pouvez fermer cette page » ne
     veut rien dire : il n'y a pas de croix à cliquer. window.close() ne
     fonctionne que sur un onglet ouvert par script, donc on ne le promet pas :
     on replie l'écran sur un message de fin, ce qui donne au geste une réponse
     visible et clôt le parcours. */
  var b = document.getElementById('btn-fin'); if (!b) return;
  b.addEventListener('click', function(){
    var carte = document.querySelector('.card');
    if (carte) {
      carte.innerHTML = '<div class="merci" style="padding:44px 10px;">'
        + '<div class="merci-ico">👋</div>'
        + '<h2>À très bientôt</h2>'
        + '<p>Vous pouvez fermer cet onglet. Votre exemplaire vous parviendra par mail.</p>'
        + '</div>';
    }
    window.scrollTo({top: 0, behavior: 'smooth'});
    try { window.close(); } catch (e) {}   // sans effet si l onglet n a pas ete ouvert par script
  });
})();
</script>
<?php endif; ?>
<?php if (($sig['role_code'] ?? '') === 'preneur'): ?>
<script>
(function(){
  var b=document.getElementById('assur-btn'); if(!b) return;
  var f=document.getElementById('assur-file'), s=document.getElementById('assur-status');
  b.addEventListener('click', function(){
    var file=f.files&&f.files[0]; if(!file){ s.style.color='#c0392b'; s.textContent='Sélectionnez un fichier.'; return; }
    if(file.size>15*1024*1024){ s.style.color='#c0392b'; s.textContent='Fichier trop volumineux (max 15 Mo).'; return; }
    var ext=(file.name.split('.').pop()||'pdf').toLowerCase().replace(/[^a-z0-9]/g,'')||'pdf';
    b.disabled=true; var old=b.textContent; b.textContent='⏳ Envoi…';
    var fd=new FormData(); fd.append('t', <?= json_encode($token) ?>); fd.append('attestation', file, 'attestation.'+ext);
    fetch(<?= json_encode(function_exists('app_url') ? app_url('/api/bail_assurance_upload.php') : '/api/bail_assurance_upload.php') ?>, {method:'POST', body:fd})
      .then(function(r){return r.json();}).then(function(j){
        if(j&&j.ok){ s.style.color='#15803d'; s.textContent='✅ Attestation reçue, merci.'; b.textContent='✅ Chargée'; }
        else { b.disabled=false; b.textContent=old; s.style.color='#c0392b'; s.textContent='❌ '+((j&&j.error)||'échec'); }
      }).catch(function(){ b.disabled=false; b.textContent=old; s.style.color='#c0392b'; s.textContent='❌ erreur réseau'; });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
