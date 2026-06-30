<?php
/**
 * ADMIN — Purge OPcache + diagnostic version code
 *
 * Utilisé pour forcer Hostinger à recharger les fichiers PHP après FTP
 * (OPcache sert les anciennes versions tant que le mtime n'est pas détecté
 * comme changé OU tant que le TTL n'expire pas).
 *
 * URL : /admin/admin_opcache_reset.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if (current_role_id() !== 1) {
    http_response_code(403);
    exit('Accès admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OPcache reset</title>';
echo '<style>body{font-family:monospace;padding:20px;line-height:1.5}h1{color:#0f172a}'
   . '.ok{color:#16a34a;font-weight:bold}.ko{color:#dc2626;font-weight:bold}'
   . 'pre{background:#f1f5f9;padding:10px;border-radius:6px;overflow:auto}'
   . 'table{border-collapse:collapse;margin:10px 0}td,th{border:1px solid #cbd5e1;padding:6px 12px;text-align:left}'
   . '</style></head><body>';

echo '<h1>🔄 OPcache reset + diagnostic</h1>';

// 1. Statut OPcache
echo '<h2>1. Statut OPcache</h2>';
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status === false) {
        echo '<p class="ko">❌ OPcache désactivé.</p>';
    } else {
        echo '<p class="ok">✓ OPcache actif</p>';
        echo '<table>';
        echo '<tr><th>Hits</th><td>' . number_format((int)($status['opcache_statistics']['hits'] ?? 0)) . '</td></tr>';
        echo '<tr><th>Misses</th><td>' . number_format((int)($status['opcache_statistics']['misses'] ?? 0)) . '</td></tr>';
        echo '<tr><th>Cached scripts</th><td>' . number_format((int)($status['opcache_statistics']['num_cached_scripts'] ?? 0)) . '</td></tr>';
        echo '<tr><th>Memory used</th><td>' . round(((int)($status['memory_usage']['used_memory'] ?? 0)) / 1024 / 1024, 1) . ' MB</td></tr>';
        echo '</table>';
    }
} else {
    echo '<p class="ko">❌ Extension OPcache non chargée.</p>';
}

// 2. Reset
echo '<h2>2. Reset OPcache</h2>';
if (function_exists('opcache_reset')) {
    $ok = @opcache_reset();
    echo $ok ? '<p class="ok">✓ opcache_reset() OK — tout le cache vidé.</p>'
             : '<p class="ko">❌ opcache_reset() a retourné false (peut-être désactivé par Hostinger).</p>';
} else {
    echo '<p class="ko">❌ opcache_reset non disponible.</p>';
}

// 3. Invalidate ciblé sur les fichiers Ubiflow + bien + GED CENTRALE (Sprint 7D 2026-05-25)
echo '<h2>3. Invalidate ciblé</h2>';
$targets = [
    __DIR__ . '/../inc/ubiflow_validator.php',
    __DIR__ . '/../inc/ubiflow_build.php',
    __DIR__ . '/../config/ubiflow_mapping.php',
    __DIR__ . '/../inc/bien_form_loader.php',
    __DIR__ . '/../inc/bien_type_helper.php',
    __DIR__ . '/../api/bien_autosave.php',
    __DIR__ . '/../api/ubiflow_trigger.php',
    // GED CENTRALE UNIQUE — fichiers Sprint 7A/B/C/D
    __DIR__ . '/admin_migrate_legacy_to_ged.php',
    __DIR__ . '/admin_audit_documents_entite.php',
    __DIR__ . '/admin_purge_biens_documents.php',
    __DIR__ . '/admin_rollback_bien_adresse.php',
    __DIR__ . '/admin_ged_doc_dump.php',
    __DIR__ . '/../inc/ged_document_links.php',
    __DIR__ . '/../inc/ged_doc_naming_v3.php',
    __DIR__ . '/../inc/fluxbox_auto_commit_ged.php',
    __DIR__ . '/../inc/fluxbox_upload_modal.php',
    __DIR__ . '/../api/bien_intake_upload.php',
    __DIR__ . '/../api/immeuble_doc_upload.php',
    __DIR__ . '/../api/dpe_import_upload.php',
    __DIR__ . '/../bien_detail.php',
    __DIR__ . '/../bien_360.php',
    __DIR__ . '/../bailleur_ged.php',
    __DIR__ . '/../agency_proprietaire_fiche.php',
    __DIR__ . '/../fluxbox_pile.php',
    __DIR__ . '/../p/upload.php',
];
echo '<table><tr><th>Fichier</th><th>Existe</th><th>Mtime</th><th>Invalidate</th></tr>';
foreach ($targets as $t) {
    $exists = is_file($t);
    $mtime = $exists ? date('Y-m-d H:i:s', filemtime($t)) : '—';
    $inv = '—';
    if ($exists && function_exists('opcache_invalidate')) {
        $inv = @opcache_invalidate($t, true) ? '<span class="ok">✓</span>' : '<span class="ko">✗</span>';
    }
    echo '<tr><td>' . htmlspecialchars(basename(dirname($t)) . '/' . basename($t))
       . '</td><td>' . ($exists ? '<span class="ok">✓</span>' : '<span class="ko">✗</span>')
       . '</td><td>' . $mtime . '</td><td>' . $inv . '</td></tr>';
}
echo '</table>';

// 4. Vérification version code Ubiflow
echo '<h2>4. Vérification version code Ubiflow</h2>';
$mappingFile = __DIR__ . '/../config/ubiflow_mapping.php';
if (is_file($mappingFile)) {
    $src = file_get_contents($mappingFile);
    $hasContactBlock = strpos($src, "'contact'") !== false || strpos($src, '"contact"') !== false;
    $hasNegResolve  = strpos($src, 'a_id_user') !== false || strpos($src, 'u_neg') !== false;
    echo '<table>';
    echo '<tr><th>Bloc contact dans le builder</th><td>' . ($hasContactBlock ? '<span class="ok">✓ Présent</span>' : '<span class="ko">✗ ABSENT — fichier non à jour !</span>') . '</td></tr>';
    echo '<tr><th>JOIN users / a_id_user</th><td>' . ($hasNegResolve ? '<span class="ok">✓ Présent</span>' : '<span class="ko">✗ ABSENT — fichier non à jour !</span>') . '</td></tr>';
    echo '<tr><th>Taille fichier</th><td>' . number_format(strlen($src)) . ' octets</td></tr>';
    echo '<tr><th>Dernière modif</th><td>' . date('Y-m-d H:i:s', filemtime($mappingFile)) . '</td></tr>';
    echo '</table>';
} else {
    echo '<p class="ko">❌ Fichier ubiflow_mapping.php introuvable !</p>';
}

// 5. Diagnostic users avec id_user attribué sur annonces visibles
echo '<h2>5. Users avec annonces diffusées</h2>';
try {
    $pdo = $GLOBALS['pdo'];
    $st = $pdo->query("
        SELECT u.id, u.prenom, u.nom, u.email, u.telephone, u.telephone_pro,
               COUNT(a.id) AS nb_annonces
        FROM users u
        INNER JOIN annonces a ON a.id_user = u.id
        WHERE a.visible_portails = 1
          AND a.statut IN ('publiee','active','en_ligne')
        GROUP BY u.id
        ORDER BY u.nom, u.prenom
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo '<p class="ko">⚠️ Aucun user avec annonce diffusée trouvé. Vérifie que les annonces ont bien `id_user` rempli.</p>';
    } else {
        echo '<table><tr><th>Id</th><th>Nom</th><th>Email</th><th>Mobile (telephone)</th><th>Fixe (telephone_pro)</th><th># annonces</th></tr>';
        foreach ($rows as $r) {
            $mobOk = !empty($r['telephone']);
            $fixOk = !empty($r['telephone_pro']);
            echo '<tr>';
            echo '<td>' . (int)$r['id'] . '</td>';
            echo '<td><strong>' . htmlspecialchars(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) . '</strong></td>';
            echo '<td>' . htmlspecialchars((string)($r['email'] ?? '')) . '</td>';
            echo '<td>' . ($mobOk ? '<span class="ok">' . htmlspecialchars((string)$r['telephone']) . '</span>' : '<span class="ko">— MANQUANT</span>') . '</td>';
            echo '<td>' . ($fixOk ? '<span class="ok">' . htmlspecialchars((string)$r['telephone_pro']) . '</span>' : '<span class="ko">— MANQUANT</span>') . '</td>';
            echo '<td>' . (int)$r['nb_annonces'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Throwable $e) {
    echo '<p class="ko">Erreur SQL : ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// 6. Diagnostic biens avec mauvais type (Maison alors que titre dit Appartement)
echo '<h2>6. Diagnostic types de bien sur annonces visibles</h2>';
try {
    $st = $pdo->query("
        SELECT a.id AS a_id, a.reference_annonce, a.titre,
               b.id AS b_id, b.id_bien_type, b.id_type_bien,
               bt.code AS bt_code, bt.libelle AS bt_libelle
        FROM annonces a
        INNER JOIN biens b ON b.id = a.id_bien
        LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
        WHERE a.visible_portails = 1 AND a.statut IN ('publiee','active','en_ligne')
        ORDER BY a.id
        LIMIT 20
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '<table><tr><th>Annonce</th><th>Titre</th><th>id_bien_type</th><th>id_type_bien</th><th>bien_types.code</th></tr>';
    foreach ($rows as $r) {
        $codeOk = !empty($r['bt_code']);
        echo '<tr>';
        echo '<td>#' . (int)$r['a_id'] . ' (' . htmlspecialchars((string)$r['reference_annonce']) . ')</td>';
        echo '<td>' . htmlspecialchars(substr((string)$r['titre'], 0, 60)) . '</td>';
        echo '<td>' . ($r['id_bien_type'] ?? '<span class="ko">NULL</span>') . '</td>';
        echo '<td>' . ($r['id_type_bien'] ?? '<span class="ko">NULL</span>') . '</td>';
        echo '<td>' . ($codeOk ? '<strong>' . htmlspecialchars((string)$r['bt_code']) . '</strong>' : '<span class="ko">— bien_types absent</span>') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
} catch (Throwable $e) {
    echo '<p class="ko">Erreur SQL : ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<hr><p style="color:#64748b;font-size:11px;">Recharge cette page après modif fichiers / re-upload FTP pour vérifier que la nouvelle version est bien servie.</p>';
echo '</body></html>';
