<?php
// bien_ajouter_express.php — Redirection permanente vers bien_detail.php
//
// Historique : flow "Express" de création rapide d'un bien avec intake IA
// (upload DPE/mandat/bail → extraction + pré-remplissage auto).
//
// Retiré le 2026-04-20 : les informations extraites par l'intake IA
// n'étaient pas reprises de manière fiable dans le bien ensuite ouvert
// via bien_detail (contexte non partagé entre les 2 pages). Le flow
// "Express" n'apportait donc pas le gain attendu et était source de
// confusion.
//
// Le flow d'intake IA (upload DPE + extraction + sync automatique vers
// `biens`) existe désormais directement dans bien_detail.php (V2) :
//   Section Documents → Card Chargement → dropzone DocumentUploader
//   Le DPE est analysé par GPT-4o Vision, stocké dans `dpe_diags`, et
//   synchronisé vers `biens.*` au prochain chargement de la page.
//
// Comportement : redirection 301 vers bien_detail.php (tous les GET
// params préservés — ?id_bien=X reste compatible).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

// Préserve tous les paramètres GET
$query = $_GET ? '?' . http_build_query($_GET) : '';

header('Location: ' . app_url('/bien_detail.php' . $query), true, 301);
exit;
