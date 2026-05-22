<?php
// api/transaction_offre_save.php — Saisie d'une offre dans leads_annonces (V0)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien    = (int)(post('id_bien') ?? 0);
$idAnnonce = (int)(post('id_annonce') ?? 0);
$prix      = (float)str_replace([' ', ','], ['', '.'], (string)(post('prix_propose') ?? '0'));
$nom       = trim((string)(post('nom') ?? ''));
$prenom    = trim((string)(post('prenom') ?? ''));
$email     = trim((string)(post('email') ?? ''));
$tel       = trim((string)(post('telephone') ?? ''));
$financ    = (string)(post('financement_type') ?? 'inconnu');
$statutOf  = (string)(post('statut_offre') ?? 'recue');
$message   = trim((string)(post('message') ?? ''));

if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }
if ($nom === '')  { echo json_encode(['ok'=>false,'error'=>'nom requis']); exit; }
if ($prix <= 0)   { echo json_encode(['ok'=>false,'error'=>'prix_propose requis']); exit; }
if (!in_array($financ, ['cash','emprunt','mixte','inconnu'], true)) $financ = 'inconnu';
if (!in_array($statutOf, ['recue','transmise_vendeur','acceptee','refusee','contre_offre','expiree'], true)) $statutOf = 'recue';

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSociete    = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAgence     = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;
$idUser       = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    // Scope + retrouve annonce courante si pas fournie
    $stmt = $pdo->prepare('SELECT id_societe, id_agence FROM biens WHERE id = ? LIMIT 1');
    $stmt->execute([$idBien]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }
    if (!$isSuperAdmin && $idSociete !== null && $b['id_societe'] !== null && (int)$b['id_societe'] !== $idSociete) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
    }

    if ($idAnnonce <= 0) {
        $stA = $pdo->prepare('SELECT MAX(id) FROM annonces WHERE id_bien = ? AND (statut IS NULL OR statut <> "archivee")');
        $stA->execute([$idBien]);
        $idAnnonce = (int)($stA->fetchColumn() ?: 0);
    }
    if ($idAnnonce <= 0) {
        // V0 : on accepte l'offre même sans annonce — on crée un placeholder NULL
        // mais leads_annonces.id_annonce est NOT NULL : on doit insérer 0 ou créer une annonce minimale ?
        // Choix V0 : refuser proprement avec message explicite.
        echo json_encode(['ok'=>false,'error'=>'Aucune annonce active sur ce bien : créer d\'abord une annonce.']);
        exit;
    }

    $ins = $pdo->prepare('INSERT INTO leads_annonces
        (id_annonce, id_bien, id_agence, id_user_assigne, source, source_detail,
         type_contact, civilite, nom, prenom, email, telephone, message,
         prix_propose, financement_type, statut_offre,
         statut, score_lead, date_premier_contact, ip, user_agent, date_creation, date_modification)
        VALUES
        (:id_annonce, :id_bien, :id_agence, :id_user, "interne", "transaction_index",
         "offre", NULL, :nom, :prenom, :email, :tel, :message,
         :prix, :financ, :statut_offre,
         "nouveau", NULL, NOW(), :ip, :ua, NOW(), NOW())');

    $ins->execute([
        ':id_annonce' => $idAnnonce,
        ':id_bien'    => $idBien,
        ':id_agence'  => $idAgence ?: ($b['id_agence'] ?: null),
        ':id_user'    => $idUser ?: null,
        ':nom'        => $nom,
        ':prenom'     => $prenom ?: null,
        ':email'      => $email ?: null,
        ':tel'        => $tel ?: null,
        ':message'    => $message ?: null,
        ':prix'       => $prix,
        ':financ'     => $financ,
        ':statut_offre' => $statutOf,
        ':ip'         => client_ip() ?: null,
        ':ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    echo json_encode(['ok'=>true, 'id_lead'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
