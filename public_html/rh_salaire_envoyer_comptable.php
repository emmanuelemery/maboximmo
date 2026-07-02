<?php
declare(strict_types=1);
/**
 * rh_salaire_envoyer_comptable.php — ENVOI CONSOLIDÉ au comptable.
 *
 * Quand toutes les agences d'une société sont validées : UN SEUL mail au
 * comptable, avec une card par agence (nom + remarques), les PDF projets en
 * pièces jointes, et UN SEUL lien de dépôt listant toutes les agences (une
 * card par agence, remarques rappelées) pour retourner l'ENSEMBLE des bulletins
 * définitifs. Les bulletins déposés reviennent via le pont -> étape 3.
 *
 * Sécurité : POST + CSRF + auth + admin/gestion_salaires.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_once __DIR__ . '/inc/document_requests.php';
require_once __DIR__ . '/inc/mailer.php';
require_login();

if (!function_exists('mois_fr')) {
    function mois_fr(int $m): string { $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[$m] ?? ''; }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
verify_csrf();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$societeId = (int)($_POST['societe_id'] ?? 0);
$mois      = (int)($_POST['mois'] ?? 0);
$annee     = (int)($_POST['annee'] ?? 0);
$redirect  = (string)($_POST['redirect_to'] ?? 'rh_salaires.php');
unset($_SESSION['dr_offer_send']);

if ($societeId <= 0 || $mois <= 0 || $annee <= 0) { $_SESSION['message_err'] = 'Paramètres incomplets.'; header('Location: ' . $redirect); exit; }

// Dernière comparaison projet VALIDÉE par agence.
$st = $pdo->prepare("SELECT c.* FROM rh_salaires_comparaisons c
                     JOIN (SELECT id_agence, MAX(id) AS mid FROM rh_salaires_comparaisons
                           WHERE id_societe=? AND mois=? AND annee=? AND type='projet' AND validated_at IS NOT NULL
                           GROUP BY id_agence) last ON last.mid = c.id");
$st->execute([$societeId, $mois, $annee]);
$cmps = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$cmps) { $_SESSION['message_err'] = 'Aucune agence validée à envoyer.'; header('Location: ' . $redirect); exit; }

// Comptable de la société.
$stSoc = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
$stSoc->execute([$societeId]);
$societe = $stSoc->fetch(PDO::FETCH_ASSOC) ?: [];
$comptableEmail = trim((string)($societe['comptable_email'] ?? ''));
$comptableNom   = trim((string)($societe['comptable_nom'] ?? ''));
if ($comptableEmail === '' || !filter_var($comptableEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['message_err'] = 'Email comptable manquant pour cette société.'; header('Location: ' . $redirect); exit;
}

$moisLabel   = mois_fr($mois);
$periode     = sprintf('%04d-%02d', $annee, $mois);
$societeNom  = trim((string)($societe['raison_sociale'] ?? '')) ?: trim((string)($societe['nom'] ?? '')) ?: ('Société #' . $societeId);

// Infos par agence (société + nom + remarques). Aucun PDF joint : on n'envoie
// QUE les remarques + le lien de dépôt (le comptable renvoie tous les bulletins).
$agencesInfo = [];  // [id_agence => ['nom','remarques']]
foreach ($cmps as $c) {
    $idAg = (int)$c['id_agence'];
    $stAg = $pdo->prepare("SELECT nom_agence FROM agences WHERE id = ? LIMIT 1");
    $stAg->execute([$idAg]);
    $nomAg = (string)($stAg->fetchColumn() ?: ('Agence #' . $idAg));
    $agencesInfo[$idAg] = ['nom' => $nomAg, 'remarques' => trim((string)($c['remarques'] ?? ''))];
}

// Une pièce par agence, préfixée de la SOCIÉTÉ (page mutualisée d'un comptable
// pouvant regrouper plusieurs sociétés). La note rappelle société + remarques.
$items = [];
foreach ($agencesInfo as $idAg => $inf) {
    $note = 'Société : ' . $societeNom . ($inf['remarques'] !== '' ? "\n📝 " . $inf['remarques'] : '');
    $items[] = [
        'label'       => $societeNom . ' — ' . $inf['nom'],
        'doc_type'    => 'BULLETIN_SALAIRE',
        'entity_type' => 'AGENCE',
        'entity_id'   => $idAg,
        'period'      => $periode,
        'required'    => 1,
        'kind'        => 'files',
        'max_files'   => 50,
        'note'        => $note,
    ];
}
$titre = 'Bulletins définitifs — ' . $moisLabel . ' ' . $annee;
$message = "Merci de nous retourner L'ENSEMBLE des bulletins définitifs de chaque agence (pas seulement ceux modifiés). Nos remarques sont rappelées au-dessus de chaque agence.";

// RÉUTILISATION : le comptable garde UNE seule page. Si une demande « bulletins
// définitifs » active existe déjà pour lui, on y AJOUTE les agences (anti-doublon)
// au lieu d'en créer une nouvelle. Sinon on la crée (template_code = marqueur).
$depotUrl = ''; $existReq = null;
try {
    $q = $pdo->prepare("SELECT id, token FROM document_requests
                        WHERE recipient_email = ? AND template_code = 'bulletins_definitifs'
                          AND status NOT IN ('revoque','expire') AND (expires_at IS NULL OR expires_at > NOW())
                        ORDER BY id DESC LIMIT 1");
    $q->execute([$comptableEmail]);
    $existReq = $q->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

if ($existReq) {
    dr_add_agence_items($pdo, (int)$existReq['id'], $items);
    // On rouvre une fenêtre de 5 jours et on (re)pose le drapeau de fermeture auto :
    // ce renvoi ajoute des agences → le lien doit rester déposable 5 j puis tomber
    // à la complétion, comme un lien neuf.
    try { $pdo->prepare("UPDATE document_requests SET expires_at = DATE_ADD(NOW(), INTERVAL 5 DAY) WHERE id = ?")->execute([(int)$existReq['id']]); } catch (Throwable) {}
    try { $pdo->prepare("UPDATE document_requests SET close_on_complete = 1 WHERE id = ?")->execute([(int)$existReq['id']]); } catch (Throwable) {}
    $depotUrl = dr_public_url((string)$existReq['token']);
} else {
    $res = dr_create_request($pdo, [
        'titre'           => $titre,
        'message'         => $message,
        'recipient_email' => $comptableEmail,
        'recipient_name'  => $comptableNom,
        'template_code'   => 'bulletins_definitifs',
        'societe_id'      => $societeId,
        'created_by'      => $userId,
        'require_email_gate' => 1,
        // Lien comptable : ouvert 5 jours, puis « tombe » dès qu'il est complet
        // (terminé = clos pour le comptable). close_on_complete géré au recompute.
        'expires_at'      => date('Y-m-d H:i:s', strtotime('+5 days')),
        'close_on_complete' => 1,
        'reminder_mode'   => 'none',
    ], $items);
    if (empty($res['ok'])) { $_SESSION['message_err'] = 'Échec création du lien de dépôt : ' . ($res['error'] ?? '?'); header('Location: ' . $redirect); exit; }
    $depotUrl = $res['url'];
}

// 2) Corps du mail : cards par agence (nom + remarques) + consigne + lien.
$cards = '';
$socEsc = htmlspecialchars($societeNom, ENT_QUOTES, 'UTF-8');
foreach ($agencesInfo as $inf) {
    $rem = $inf['remarques'] !== '' ? nl2br(htmlspecialchars($inf['remarques'], ENT_QUOTES, 'UTF-8')) : '<em style="color:#94a3b8;">Aucune remarque</em>';
    $cards .= '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin:10px 0;background:#fff;">'
        . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;">🏛 ' . $socEsc . '</div>'
        . '<div style="font-weight:700;color:#0f172a;margin-top:2px;">🏢 ' . htmlspecialchars($inf['nom'], ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div style="margin-top:6px;font-size:13px;color:#334155;">📝 ' . $rem . '</div></div>';
}
$bonjour = $comptableNom !== '' ? 'Bonjour ' . htmlspecialchars(trim((string)preg_split('/\s+/', $comptableNom)[0]), ENT_QUOTES, 'UTF-8') : 'Bonjour';
$bodyHtml = '<p>' . $bonjour . ',</p>'
    . '<p>Les projets de salaires <strong>' . htmlspecialchars($moisLabel . ' ' . $annee, ENT_QUOTES, 'UTF-8') . '</strong> sont validés pour toutes les agences. Nos remarques par agence :</p>'
    . $cards
    . '<p style="margin-top:16px;padding:10px 14px;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;"><strong>Merci de nous retourner l\'ENSEMBLE des bulletins définitifs</strong> de chaque agence (pas seulement les modifiés), via le lien ci-dessous. Une card de dépôt par agence vous y attend, avec le rappel de nos remarques.</p>'
    . '<p style="margin:22px 0;"><a href="' . htmlspecialchars($depotUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#0e7490;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700;">📤 Déposer les bulletins définitifs</a></p>'
    . '<p style="color:#64748b;font-size:12px;">Lien sécurisé. Si le bouton ne fonctionne pas : ' . htmlspecialchars($depotUrl, ENT_QUOTES, 'UTF-8') . '</p>';

// Aucune pièce jointe : on n'envoie QUE les remarques + le lien.
$attachments = [];

$subject = 'Projets validés — bulletins définitifs à retourner — ' . $moisLabel . ' ' . $annee;
$hostNow = (string)($_SERVER['HTTP_HOST'] ?? '');
$isDevOrLocal = (str_contains($hostNow, 'dev.maboximmo') || str_contains($hostNow, 'localhost') || str_contains($hostNow, '127.0.0.1'));
$ccDirection = 'emmanuel.emery@regie-emery.com';

if ($isDevOrLocal) {
    $mailOk = true; $devMessage = ' (mode test dev — mail NON envoyé)';
} else {
    $mailOk = send_mail($comptableEmail, $subject, $bodyHtml, $attachments, true, $ccDirection, 'salaire@maboximmo.fr');
    $devMessage = '';
}

// Trace : log ENVOI par agence (marque aussi comme envoyé).
try {
    foreach ($agencesInfo as $idAg => $inf) {
        $moisRef = sprintf('%04d-%02d-01', $annee, $mois);
        rh_wf_log_action($pdo, $societeId, $idAg, $moisRef, RH_WF_TYPE_VALIDATION,
            null, null, null, $comptableEmail, $userId, 'ok',
            $isDevOrLocal ? '🧪 Mode test (mail non envoyé)' : null,
            'Envoi consolidé au comptable + lien bulletins définitifs');
    }
} catch (Throwable $e) {}

if (!$mailOk) { $_SESSION['message_err'] = 'Erreur lors de l\'envoi du mail consolidé.'; header('Location: ' . $redirect); exit; }
$_SESSION['message_ok'] = '📤 Mail consolidé envoyé au comptable (' . count($agencesInfo) . ' agence(s)) + lien de dépôt des bulletins créé ✅' . $devMessage;
header('Location: ' . $redirect);
exit;
