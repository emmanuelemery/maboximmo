<?php
// bien_ajouter.php — Redirection permanente vers bien_detail.php
//
// Historique : bien_ajouter.php était la page "pro" de création/édition de bien.
// Toutes les corrections récentes (sync dpe_diags ↔ biens ↔ bien_chauffages /
// bien_energies / bien_vues, chips ambiance/nuisances, sous-onglet "Données
// extraites", lazy backfills, fix doubles flèches DPE, emojis Vue unifiés,
// widgets chauffage dans applyValue, force autosave après "Valider & remplacer",
// etc.) ont été appliquées sur bien_detail.php uniquement.
//
// bien_ajouter.php nécessitait 7 fixes critiques pour être alignée — plutôt que
// maintenir 2 fichiers de 9000 lignes en parallèle (et subir des divergences
// silencieuses comme Express vs bien_detail), on unifie sur bien_detail.php
// (cf. project_bien_detail_unifiee.md dans la memory Claude).
//
// Cette redirection permanente préserve les anciens liens / bookmarks.
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

// Préserve tous les paramètres GET éventuels (edit=X, etc.)
$query = $_GET ? '?' . http_build_query($_GET) : '';

header('Location: ' . app_url('/bien_detail.php' . $query), true, 301);
exit;
