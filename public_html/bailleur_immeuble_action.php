<?php
declare(strict_types=1);
/**
 * bailleur_immeuble_action.php — Actions sur un immeuble (vendu / archiver / réactiver)
 *
 * POST requis :
 *   action   = 'vendre' | 'archiver' | 'reactiver'
 *   id       = id de l'immeuble
 *   (vendre)     : prix_vente, date_vente
 *   (archiver)   : motif_archivage
 *
 * Scope : l'utilisateur doit être habilité sur la SCI (via user_proprietaires
 * pour les propriétaires, ou sur la société pour les autres rôles).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
require_once __DIR__ . '/inc/csrf.php';

$pdo = $GLOBALS['pdo'];

function bail_back(string $msg, bool $ok = true): void {
    $target = (function_exists('app_url') ? app_url('/bailleur_immeubles.php') : '/bailleur_immeubles.php') . '?flash=' . urlencode(($ok ? 'ok|' : 'err|') . $msg);
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bail_back('Méthode invalide', false);
verify_csrf_any();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($id <= 0) bail_back('Immeuble non spécifié', false);

// Charger l'immeuble + vérifier scope
$st = $pdo->prepare("SELECT id, id_proprietaire, id_societe, nom_immeuble FROM immeubles WHERE id = :id LIMIT 1");
$st->bindValue(':id', $id, PDO::PARAM_INT);
$st->execute();
$imm = $st->fetch(PDO::FETCH_ASSOC);
if (!$imm) bail_back('Immeuble introuvable', false);

$idRole = (int)($_SESSION['id_role'] ?? 0);
$idUser = (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$isSuperAdmin = ($idRole === 1);
$isProprio = in_array($idRole, [9, 10], true);

if (!$isSuperAdmin) {
    if ($isProprio) {
        $st = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user = :u AND id_proprietaire = :p LIMIT 1");
        $st->bindValue(':u', $idUser, PDO::PARAM_INT);
        $st->bindValue(':p', (int)$imm['id_proprietaire'], PDO::PARAM_INT);
        $st->execute();
        if (!$st->fetchColumn()) bail_back('Accès refusé', false);
    } else {
        if ((int)$imm['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) bail_back('Accès refusé', false);
    }
}

try {
    if ($action === 'vendre') {
        $prix = (float)str_replace(',', '.', (string)($_POST['prix_vente'] ?? '0'));
        $date = trim((string)($_POST['date_vente'] ?? date('Y-m-d')));
        $st = $pdo->prepare("UPDATE immeubles SET statut_immeuble = 'vendu', date_vente = :d, prix_vente = :p, date_modification = NOW() WHERE id = :id");
        $st->bindValue(':d', $date);
        $st->bindValue(':p', $prix);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        bail_back('Immeuble « ' . ($imm['nom_immeuble'] ?? '#' . $id) . ' » marqué comme vendu.');
    }
    if ($action === 'archiver') {
        $motif = trim((string)($_POST['motif_archivage'] ?? ''));
        $st = $pdo->prepare("UPDATE immeubles SET statut_immeuble = 'archive', motif_archivage = :m, date_modification = NOW() WHERE id = :id");
        $st->bindValue(':m', $motif);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        bail_back('Immeuble archivé.');
    }
    if ($action === 'reactiver') {
        $st = $pdo->prepare("UPDATE immeubles SET statut_immeuble = 'actif', date_vente = NULL, prix_vente = NULL, motif_archivage = NULL, date_modification = NOW() WHERE id = :id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        bail_back('Immeuble réactivé.');
    }
    bail_back('Action inconnue', false);
} catch (Throwable $e) {
    bail_back('Erreur : ' . $e->getMessage(), false);
}
