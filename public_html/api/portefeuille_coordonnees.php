<?php
// api/portefeuille_coordonnees.php — Le DESTINATAIRE d'un portefeuille met à jour ses
// propres coordonnées depuis la page publique (accès par token, pas de login).
// Met à jour : le tiers lié (source de vérité), l'entête portefeuille (visible par le
// conseiller) et le snapshot de l'envoi (pour cohérence à la réouverture du lien).
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';   // PDO + helpers, PAS d'auth (page publique)
header('Content-Type: application/json; charset=utf-8');
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$fail = function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$token = trim((string)($_POST['t'] ?? ''));
if (strlen($token) < 20) { $fail('Lien invalide.'); }

// Le token EST le secret : on valide qu'il correspond à un envoi actif et non expiré.
$st = $pdo->prepare("SELECT * FROM portefeuille_envois WHERE token = ? LIMIT 1");
$st->execute([$token]);
$envoi = $st->fetch(PDO::FETCH_ASSOC);
if (!$envoi)                                $fail('Lien introuvable.', 404);
if ((int)$envoi['actif'] !== 1)             $fail('Accès clôturé.', 403);
if (!empty($envoi['date_expiration']) && strtotime((string)$envoi['date_expiration']) < time())
                                            $fail('Accès expiré.', 403);

$idPf = (int)$envoi['id_portefeuille'];
$pf   = $pdo->prepare("SELECT id_tiers_destinataire FROM portefeuilles WHERE id = ?");
$pf->execute([$idPf]);
$idTiers = (int)($pf->fetchColumn() ?: 0);

// ── Champs soumis (nettoyés) ──
$s = fn($k, $max = 190) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
$prenom = $s('prenom', 100);
$nom    = $s('nom', 100);
$email  = $s('email');
$tel    = $s('telephone', 30);
$mobile = $s('mobile', 30);
$adr1   = $s('adresse_ligne1');
$cp     = $s('code_postal', 12);
$ville  = $s('ville', 120);

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $fail('Adresse email invalide.'); }
if ($nom === '' && $prenom === '' && $email === '' && $tel === '' && $mobile === '') {
    $fail('Renseignez au moins un champ.');
}

try {
    $pdo->beginTransaction();

    // 1) Tiers = source de vérité (si rattaché).
    if ($idTiers > 0) {
        $pdo->prepare("UPDATE tiers SET
                prenom = ?, nom = ?, email = ?, telephone = ?, mobile = ?,
                adresse_ligne1 = ?, code_postal = ?, ville = ?, date_modification = NOW()
            WHERE id = ?")
            ->execute([$prenom, $nom, $email, $tel, $mobile, $adr1, $cp, $ville, $idTiers]);
    }

    // 2) Entête portefeuille (ce que voit le conseiller).
    $pdo->prepare("UPDATE portefeuilles SET
            destinataire_nom = ?, destinataire_prenom = ?, destinataire_email = ?, destinataire_tel = ?
        WHERE id = ?")
        ->execute([$nom ?: null, $prenom ?: null, $email ?: null, ($mobile ?: $tel) ?: null, $idPf]);

    // 3) Snapshot de CET envoi (cohérence à la réouverture du lien).
    $snap = json_decode((string)$envoi['snapshot_json'], true) ?: ['header' => [], 'lignes' => []];
    $snap['header']['destinataire_nom']    = $nom;
    $snap['header']['destinataire_prenom'] = $prenom;
    $snap['header']['destinataire_email']  = $email;
    $snap['header']['destinataire_tel']    = $mobile ?: $tel;
    $pdo->prepare("UPDATE portefeuille_envois SET snapshot_json = ?, email_destinataire = COALESCE(NULLIF(?, ''), email_destinataire) WHERE id = ?")
        ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), $email, (int)$envoi['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[portefeuille_coordonnees] ' . $e->getMessage());
    $fail('Enregistrement impossible. Réessayez plus tard.', 500);
}

error_log(sprintf('[portefeuille_coordonnees] envoi=%d pf=%d tiers=%d maj coordonnées destinataire', (int)$envoi['id'], $idPf, $idTiers));

echo json_encode([
    'success' => true,
    'message' => 'Vos coordonnées ont été mises à jour. Merci !',
    'data'    => ['prenom' => $prenom, 'nom' => $nom, 'email' => $email, 'telephone' => $tel, 'mobile' => $mobile,
                  'adresse_ligne1' => $adr1, 'code_postal' => $cp, 'ville' => $ville],
], JSON_UNESCAPED_UNICODE);
