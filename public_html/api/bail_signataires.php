<?php
declare(strict_types=1);
/**
 * api/bail_signataires.php — L'ÉCRAN DE PRÉPARATION de la cérémonie, côté serveur.
 *
 * Un seul endroit pour répondre aux trois questions qui décident d'une cérémonie :
 *   · QUI signe ?
 *   · à quelle adresse et sur quel mobile ?
 *   · le mandataire signe-t-il À LA PLACE du bailleur ?
 *
 * Elles étaient jusqu'ici dispersées dans trois écrans — la fiche tiers, le modal
 * « Modifier le projet », et l'écran d'envoi — et c'est cette dispersion qui a
 * produit le bail #660 : un envoi refusé faute de mobile, une case « le mandataire
 * signe pour le bailleur » qui ne tenait pas, et personne pour voir le lien entre
 * les deux.
 *
 * ── Où va la saisie ? DANS LES DEUX ─────────────────────────────────────────
 * Corriger un numéro ici l'écrit :
 *   1. sur la FICHE TIERS — c'est la table source, et l'agent qui corrige veut
 *      que ce soit corrigé pour de bon, pas seulement pour ce bail ;
 *   2. sur la LIGNE DE SIGNATURE en attente — c'est le numéro auquel le code sera
 *      réellement adressé, et il doit rester tel quel même si la fiche rebouge.
 *
 * ⚠️🔥 JAMAIS sur une ligne déjà partie ou signée. `destinataire_email` et
 * `destinataire_tel` y sont une TRACE : ils disent à qui l'acte a été adressé.
 * Les réécrire après coup falsifierait la preuve. Seules les lignes `pending`
 * sont modifiables, et la fiche tiers, elle, se corrige toujours.
 *
 * POST JSON :
 *   { bail_id, action:'list' }
 *   { bail_id, action:'save', mandataire_signe_pour_bailleur:0|1,
 *     signataires:[{id, email, tel}] }
 * → { ok, signataires[], mandataire_signe_pour_bailleur, statut, message }
 * Auth : user connecté + scope société (bypass admin, exception bailleur 9/10).
 */
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

require_once dirname(__DIR__) . '/inc/bail_signature.php';

$pdo     = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['user_id'] ?? 0);
$userSoc = (int)($_SESSION['id_societe'] ?? 0);
$role    = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($role === 1);

$body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$bailId = (int)($body['bail_id'] ?? 0);
$action = (string)($body['action'] ?? 'list');
if ($bailId <= 0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));

// ── Bail + périmètre ─────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT bb.*, b.id_proprietaire
                       FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien
                      WHERE bb.id = ?");
$st->execute([$bailId]);
$bail = $st->fetch(PDO::FETCH_ASSOC);
if (!$bail) { http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($bail['id_societe']) && (int)$bail['id_societe'] !== $userSoc) {
    $ok = false;
    if (in_array($role, [9,10], true) && (int)($bail['id_proprietaire'] ?? 0) > 0) {
        $c = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId, (int)$bail['id_proprietaire']]); $ok = (bool)$c->fetchColumn();
    }
    if (!$ok) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}
$fige = in_array((string)$bail['statut'], ['signe','actif','resilie'], true);

/** Le drapeau tel qu'il est en base — la colonne peut manquer si 20260815d n'est pas jouée. */
$lireDrapeau = static function (PDO $pdo, int $bailId): int {
    try {
        $q = $pdo->prepare("SELECT mandataire_signe_pour_bailleur FROM bien_baux WHERE id = ?");
        $q->execute([$bailId]);
        return (int)($q->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0; }
};

// ═══════════════════════ ENREGISTREMENT ═══════════════════════
$message = '';
if ($action === 'save') {
    if ($fige) {
        exit(json_encode(['ok'=>false,
            'error'=>'Ce bail est ' . $bail['statut'] . " : les signataires ne se modifient plus, il faut un avenant."],
            JSON_UNESCAPED_UNICODE));
    }

    /* 1. Le drapeau D'ABORD : c'est lui qui décide si le bailleur fait partie de la
          cérémonie, et `bsig_create_for_signataires()` le relit juste après. */
    $flag = !empty($body['mandataire_signe_pour_bailleur']) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE bien_baux SET mandataire_signe_pour_bailleur = ? WHERE id = ?")
            ->execute([$flag, $bailId]);
    } catch (Throwable $e) { error_log('[bail_signataires flag] ' . $e->getMessage()); }

    /* 2. Coché : le bailleur SORT de la cérémonie. On retire sa ligne si elle est
          encore en attente — la laisser bloquerait la clôture sur une signature que
          plus personne n'est censé donner. Une ligne déjà signée, elle, RESTE :
          on n'efface pas un acte au motif qu'on a changé d'avis ensuite. */
    if ($flag === 1) {
        try {
            $pdo->prepare("DELETE FROM bail_signatures
                            WHERE id_bail = ? AND role_code = 'bailleur' AND statut = 'pending'")
                ->execute([$bailId]);
        } catch (Throwable $e) { error_log('[bail_signataires del bailleur] ' . $e->getMessage()); }
    }

    /* 3. Les coordonnées saisies. Elles vont sur la FICHE TIERS (table source) et
          sur la ligne de signature en attente. Les lignes déjà parties ou signées
          ne sont jamais réécrites : elles disent à qui l'acte a été adressé. */
    $modifs = 0; $fiches = 0;
    foreach ((array)($body['signataires'] ?? []) as $s) {
        $sid   = (int)($s['id'] ?? 0);
        if ($sid <= 0) continue;
        $email = trim((string)($s['email'] ?? ''));
        $tel   = trim((string)($s['tel'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        try {
            $q = $pdo->prepare("SELECT id, statut, id_tiers FROM bail_signatures WHERE id = ? AND id_bail = ?");
            $q->execute([$sid, $bailId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            if ((string)$row['statut'] === 'pending') {
                $pdo->prepare("UPDATE bail_signatures SET destinataire_email = ?, destinataire_tel = ? WHERE id = ?")
                    ->execute([$email ?: null, $tel ?: null, $sid]);
                $modifs++;
            }
            /* La fiche tiers se corrige même quand la ligne est figée : le numéro
               était faux, il le restera pour tous les actes suivants sinon.
               ⚠️ On n'ÉCRASE jamais avec du vide — un champ laissé blanc à l'écran
               veut dire « je ne sais pas », pas « efface ce que tu avais ». */
            $idTiers = (int)($row['id_tiers'] ?? 0);
            if ($idTiers > 0 && ($email !== '' || $tel !== '')) {
                $sets = []; $args = [];
                if ($email !== '') { $sets[] = 'email = ?';  $args[] = $email; }
                if ($tel   !== '') { $sets[] = 'mobile = ?'; $args[] = $tel; }
                $args[] = $idTiers;
                $pdo->prepare("UPDATE tiers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
                $fiches++;
            }
        } catch (Throwable $e) { error_log('[bail_signataires maj ' . $sid . '] ' . $e->getMessage()); }
    }
    /* ── LE REPRÉSENTANT D'UNE PERSONNE MORALE ───────────────────────────────
       Une SARL ne signe pas : c'est son gérant qui signe pour elle. Deux choses
       distinctes, et elles ne vont PAS au même endroit :

       · le NOM et la QUALITÉ sont du TEXTE D'ACTE — ils s'impriment dans le bail
         (« Représentée par Thomas SABY (Gérant) ») → colonnes `bien_baux` ;
       · l'EMAIL et le MOBILE sont les coordonnées de CETTE cérémonie → ligne
         `bail_signatures`, parce que c'est là que part le lien et le code.

       ⚠️ Ne jamais confondre avec la fiche du tiers : l'email de la SARL est celui
       de l'entreprise, pas celui de la personne qui signe. Envoyer le lien de
       signature à l'accueil d'une société, c'est perdre la preuve de qui a signé. */
    $REP = ['bailleur' => 'bailleur_', 'preneur' => 'locataire_'];
    foreach ((array)($body['representants'] ?? []) as $role => $rep) {
        $prefix = $REP[(string)$role] ?? null;
        if ($prefix === null || !is_array($rep)) continue;
        try {
            $pdo->prepare("UPDATE bien_baux SET {$prefix}representant_nom = ?, {$prefix}representant_qualite = ?,
                                                {$prefix}representant_email = ?, {$prefix}representant_telephone = ?
                            WHERE id = ?")
                ->execute([
                    trim((string)($rep['nom'] ?? ''))     ?: null,
                    trim((string)($rep['qualite'] ?? '')) ?: null,
                    trim((string)($rep['email'] ?? ''))   ?: null,
                    trim((string)($rep['tel'] ?? ''))     ?: null,
                    $bailId,
                ]);
        } catch (Throwable $e) { error_log('[bail_signataires rep ' . $role . '] ' . $e->getMessage()); }
    }

    $message = $modifs . ' signataire(s) mis à jour'
             . ($fiches > 0 ? ', ' . $fiches . ' fiche(s) tiers corrigée(s)' : '') . '.';
}

// ═══════════════════════ RELANCE D'UN SEUL SIGNATAIRE ═══════════════════════
/* ── RELANCER UNE PERSONNE, SUR LE CANAL QU'ON CHOISIT ───────────────────────
   Idée d'Emmanuel le 20/08/2026, et elle vaut mieux que la mienne : je proposais
   de rouvrir TOUTE la vague pour renvoyer un SMS. Or ce dont l'agent a besoin,
   c'est de relancer CELUI qui n'a pas répondu, par le canal qui a une chance de
   l'atteindre — sans renvoyer quoi que ce soit aux autres.

   ⚠️ Le jeton n'est PAS régénéré : c'est le même lien qu'à l'envoi initial, donc
   une relance ne périme pas ce que le signataire a peut-être déjà sous les yeux.
   ⚠️ On refuse de relancer une signature déjà donnée : il n'y a plus rien à
   demander, et le lien mènerait à une page close. */
if ($action === 'relancer') {
    require_once dirname(__DIR__) . '/inc/bail_ceremonie.php';
    $sigId = (int)($body['id'] ?? 0);
    $canal = (string)($body['canal'] ?? 'mail');
    if (!in_array($canal, ['mail', 'sms'], true)) $canal = 'mail';

    $q = $pdo->prepare("SELECT * FROM bail_signatures WHERE id = ? AND id_bail = ?");
    $q->execute([$sigId, $bailId]);
    $sig = $q->fetch(PDO::FETCH_ASSOC);
    if (!$sig) exit(json_encode(['ok'=>false,'error'=>'Signataire introuvable.'], JSON_UNESCAPED_UNICODE));
    if ((string)$sig['statut'] === 'signe') {
        exit(json_encode(['ok'=>false,
            'error'=>trim((string)$sig['nom_signataire']) . ' a déjà signé : plus rien à relancer.'],
            JSON_UNESCAPED_UNICODE));
    }

    $ctx = bcer_contexte($pdo, $bailId);
    if ($canal === 'sms') {
        $tel = trim((string)($sig['destinataire_tel'] ?? ''));
        if ($tel === '') {
            exit(json_encode(['ok'=>false,
                'error'=>'Aucun mobile pour ce signataire : renseigne-le en cliquant sur sa carte.'],
                JSON_UNESCAPED_UNICODE));
        }
        $r = bcer_sms_invitation($pdo, $sig, $ctx);
        if (empty($r['ok'])) {
            exit(json_encode(['ok'=>false, 'error'=>'SMS non parti : ' . (string)($r['error'] ?? 'échec inconnu')],
                JSON_UNESCAPED_UNICODE));
        }
        /* ⚠️🔥 RELANCER SANS RÉARMER, C'EST RENVOYER UN LIEN MORT.
           `bcer_sms_invitation()` n'écrit que `sms_invite_at`, `bcer_mail_invitation()`
           n'écrit rien du tout — or `sent_at` est la SEULE date que lit
           `bsig_is_expired()`. On pouvait donc relancer dix fois : le destinataire
           recevait un message tout neuf qui ouvrait une page « lien expiré », et
           l'agence relançait encore, indéfiniment. Mesuré le 22/08/2026.
           Le jeton ne change pas : le lien déjà reçu redevient valable lui aussi. */
        bsig_mark_sent($pdo, $sigId);
        exit(json_encode(['ok'=>true,
            'message'=>'📱 SMS renvoyé au ' . $tel . ' — lien réarmé pour ' . (int)(BSIG_TTL_MIN / 60) . ' h.'],
            JSON_UNESCAPED_UNICODE));
    }

    $mail = trim((string)($sig['destinataire_email'] ?? ''));
    if ($mail === '') {
        exit(json_encode(['ok'=>false,
            'error'=>'Aucun email pour ce signataire : renseigne-le en cliquant sur sa carte.'],
            JSON_UNESCAPED_UNICODE));
    }
    $ok = bcer_mail_invitation($pdo, $sig, $ctx);
    if (!$ok) {
        exit(json_encode(['ok'=>false,'error'=>'Envoi du mail impossible vers ' . $mail . '.'],
            JSON_UNESCAPED_UNICODE));
    }
    bsig_mark_sent($pdo, $sigId);   // réarme les 48 h — cf. le bloc SMS ci-dessus
    exit(json_encode(['ok'=>true,
        'message'=>'📧 Mail renvoyé à ' . $mail . ' — lien réarmé pour ' . (int)(BSIG_TTL_MIN / 60) . ' h.'],
        JSON_UNESCAPED_UNICODE));
}

// ═══════════════════════ LISTE ═══════════════════════
/* ⚠️ Créer les lignes ICI est légitime : on répond à un CLIC sur « Signataires »,
   pas au rendu passif d'une page. La règle « un écran d'aperçu ne fait jamais de
   get_or_create » vise les pages qui se contentent de s'afficher ; celle-ci est
   l'atelier de préparation de la cérémonie, et sans lignes il n'y aurait rien à
   préparer. Sur un bail figé en revanche, on ne crée rien : on montre l'existant. */
$signataires = [];
try {
    if (!$fige) bsig_create_for_signataires($pdo, $bailId, $userId);
    $signataires = bsig_list_for_bail($pdo, $bailId);
} catch (Throwable $e) {
    error_log('[bail_signataires list] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>'Impossible de lire les signataires : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

$LBL = ['preneur'=>'Preneur','colocataire'=>'Colocataire','caution'=>'Caution',
        'bailleur'=>'Bailleur','mandataire'=>'Mandataire (agence)'];
$ICO = ['preneur'=>'🔑','colocataire'=>'🔑','caution'=>'🛡️','bailleur'=>'🏛️','mandataire'=>'🏢'];
$REPCOL = ['bailleur' => 'bailleur_', 'preneur' => 'locataire_'];

/* La FICHE TIERS complète voyage avec le signataire : la carte cliquée ouvre le
   composant `inc/tiers_edit_modal.php`, qui attend ces champs. On les sert ici
   plutôt que de faire un second aller-retour au clic. */
$fiche = static function (PDO $pdo, int $idTiers): ?array {
    if ($idTiers <= 0) return null;
    try {
        $q = $pdo->prepare("SELECT id, raison_sociale, nom, prenom, nom_affichage, email,
                                   telephone, mobile, adresse_ligne1, adresse_ligne2, code_postal, ville
                              FROM tiers WHERE id = ? LIMIT 1");
        $q->execute([$idTiers]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('[bail_signataires fiche] ' . $e->getMessage()); return null; }
};

/* ── LE SUIVI ÉTAPE PAR ÉTAPE ────────────────────────────────────────────────
   Toute la traçabilité était déjà en base et cet écran n'en montrait qu'un
   booléen (`envoye`). On sert désormais la chaîne complète — lien émis, mail,
   SMS, page ouverte, code validé, annexes, signature, acte — pour que l'agent
   VOIE où en est chacun au lieu de le supposer. Jamais bloquant : si le suivi
   échoue, les cartes s'affichent quand même. */
$suivi = [];
try { require_once dirname(__DIR__) . '/inc/bail_ceremonie.php'; $suivi = bcer_suivi($pdo, $bailId); }
catch (Throwable $e) { error_log('[bail_signataires suivi] ' . $e->getMessage()); }

$out = [];
foreach ($signataires as $s) {
    $rc   = (string)($s['role_code'] ?? '');
    $base = preg_replace('/_\d+$/', '', $rc) ?: $rc;      // preneur_1 → preneur
    $suff = preg_match('/_(\d+)$/', $rc, $m) ? ' ' . ((int)$m[1] + 1) : '';
    $f = $fiche($pdo, (int)($s['id_tiers'] ?? 0));
    /* Morale = la fiche porte une raison sociale, ou le bail déclare une société.
       Le second cas couvre les baux dont le preneur n'est pas encore un tiers. */
    $morale = (bool)(trim((string)($f['raison_sociale'] ?? '')) !== ''
                  || ($base === 'preneur' && (string)($bail['locataire_type'] ?? '') === 'societe'));
    /* Un seul jeu de colonnes par côté : le bloc « représentant » n'a de sens que
       pour le preneur TITULAIRE et pour le bailleur. Un colocataire n° 2 n'a pas
       de colonnes à lui — lui en proposer écraserait celles du premier. */
    $repCle = ($rc === 'bailleur') ? 'bailleur' : (($rc === 'preneur') ? 'preneur' : null);
    if (!$morale) $repCle = null;
    $out[] = [
        'id'      => (int)$s['id'],
        'role'    => $rc,
        'label'   => ($LBL[$base] ?? ucfirst($base)) . $suff,
        'nom'     => (string)($s['nom_signataire'] ?? ''),
        'email'   => (string)($s['destinataire_email'] ?? ''),
        'tel'     => (string)($s['destinataire_tel'] ?? ''),
        'statut'  => (string)($s['statut'] ?? 'pending'),
        'vague'   => (int)($s['vague'] ?? 1),
        // Verrouillé à l'écran : la trace de ce qui est parti ne se réécrit pas.
        'fige'    => (string)($s['statut'] ?? '') !== 'pending',
        'envoye'  => !empty($s['sent_at']),
        // La chaîne complète (8 étapes) + l'état du lien : cf. bcer_suivi().
        'etapes'  => $suivi[(int)$s['id']]['etapes'] ?? [],
        'lien'    => $suivi[(int)$s['id']]['lien']   ?? null,
        'icone'   => $ICO[$base] ?? '👤',
        'id_tiers'=> (int)($s['id_tiers'] ?? 0),
        'fiche'   => $f,
        /* PERSONNE MORALE : une société ne signe pas elle-même. On le dit à
           l'écran, et on ouvre le bloc « représentant » plutôt que de laisser
           croire qu'un email d'entreprise suffit à identifier un signataire. */
        'morale'  => $morale,
        'rep_cle' => $repCle,
        'rep'     => $repCle === null ? null : [
            'nom'     => (string)($bail[$REPCOL[$repCle] . 'representant_nom'] ?? ''),
            'qualite' => (string)($bail[$REPCOL[$repCle] . 'representant_qualite'] ?? ''),
            'email'   => (string)($bail[$REPCOL[$repCle] . 'representant_email'] ?? ''),
            'tel'     => (string)($bail[$REPCOL[$repCle] . 'representant_telephone'] ?? ''),
        ],
    ];
}

/* ── LE BAILLEUR ÉCARTÉ RESTE VISIBLE ────────────────────────────────────────
   Quand le mandataire signe à sa place, sa ligne de signature est supprimée — il
   disparaissait donc complètement de l'écran. Or « ne signe pas » et « n'existe
   pas » ne doivent JAMAIS se confondre : l'agent doit voir QUI il a écarté, sinon
   il ne peut pas revenir en arrière en connaissance de cause. On le renvoie donc
   en carte grisée, hors cérémonie. */
$flagCourant = $lireDrapeau($pdo, $bailId);
$aDejaBailleur = false;
foreach ($out as $o) { if ($o['role'] === 'bailleur') { $aDejaBailleur = true; break; } }
if ($flagCourant === 1 && !$aDejaBailleur) {
    try {
        $q = $pdo->prepare("SELECT tp.id,
                                   COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale,
                                            CONCAT_WS(' ', tp.prenom, tp.nom)) AS nom,
                                   tp.email, tp.mobile, tp.telephone
                              FROM bien_baux bb
                              JOIN biens b ON b.id = bb.id_bien
                         LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                         LEFT JOIN tiers tp ON tp.id = p.id_tiers
                             WHERE bb.id = ? LIMIT 1");
        $q->execute([$bailId]);
        if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
            if ((int)($p['id'] ?? 0) > 0) {
                $out[] = [
                    'id' => 0, 'role' => 'bailleur', 'label' => 'Bailleur',
                    'nom' => (string)($p['nom'] ?? 'Le bailleur'),
                    'email' => (string)($p['email'] ?? ''),
                    'tel' => (string)($p['mobile'] ?: $p['telephone'] ?? ''),
                    'statut' => 'hors', 'vague' => 1, 'fige' => true, 'envoye' => false,
                    'icone' => '🏛️', 'id_tiers' => (int)$p['id'],
                    'fiche' => $fiche($pdo, (int)$p['id']),
                    'hors_ceremonie' => true,
                ];
            }
        }
    } catch (Throwable $e) { error_log('[bail_signataires bailleur fantome] ' . $e->getMessage()); }
}

echo json_encode([
    'ok'      => true,
    'statut'  => (string)$bail['statut'],
    'fige'    => $fige,
    'mandataire_signe_pour_bailleur' => $flagCourant,
    'signataires' => $out,
    'message' => $message,
], JSON_UNESCAPED_UNICODE);
