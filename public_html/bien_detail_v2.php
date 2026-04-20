<?php
// bien_detail_v2.php — Redirection permanente vers bien_detail.php
//
// Historique : durant la refonte, bien_detail_v2.php était la nouvelle
// page (carousel, autosave AJAX) qui coexistait avec la V1 "pro" de
// ~9300 lignes sous le nom bien_detail.php.
//
// Depuis le 2026-04-20 (commit 4ff1ab7), la V2 est promue page
// principale sous le nom bien_detail.php, et la V1 est archivée sous
// bien_detail_ex.php. Ce fichier existe uniquement pour que les
// anciens liens / bookmarks qui pointaient vers bien_detail_v2.php
// continuent de fonctionner (301 permanent vers bien_detail.php).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

// Préserve tous les paramètres GET (edit, section, focus…)
$query = $_GET ? '?' . http_build_query($_GET) : '';

header('Location: ' . app_url('/bien_detail.php' . $query), true, 301);
exit;
