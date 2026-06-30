<?php
/**
 * admin/admin_dedup_skeletons_merge.php — Fusion en lot des coquilles CRG dans leur gardien.
 *
 * Coquille = bien vide (aucun bien_prix/bien_baux/crg_situations_locataires/annonce/
 * locataire_statut) dont le code_crg normalisé (`_`→`-`) correspond à la reference_bien
 * d'un VRAI bien (gardien) qui, lui, porte des données.
 *
 * - GET                : APERÇU (lecture seule) des paires + ce qui sera transféré.
 * - POST action=run    : exécute la fusion (source=coquille → destination=gardien) :
 *     transfère dossier_vente, biens_photos, tiers_roles(bien), mandats, ged/biens_documents,
 *     puis SOFT-DELETE la coquille (statut_bien='supprime'). Une transaction par paire.
 *
 * Réservé super-admin (role 1). Idempotent (une coquille déjà 'supprime' n'est plus listée).
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin (role 1).'); }

$dataExists = "(
    EXISTS (SELECT 1 FROM bien_prix x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM bien_baux x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM crg_situations_locataires x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM annonces x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM locataires_statuts x WHERE x.id_bien = %1\$s)
)";
$skelData    = sprintf($dataExists, 's.id');
$gardienData = sprintf($dataExists, 'g.id');

/** Récupère les paires (coquille → gardien) à fusionner. */
function dedup_pairs(PDO $pdo, string $skelData, string $gardienData): array {
    $sql = "
        SELECT s.id AS src, s.reference_bien AS src_ref, s.code_crg, s.statut_bien,
               COALESCE(NULLIF(s.adresse_1,''), i.adresse_1) AS adresse,
               COALESCE(NULLIF(s.ville,''), i.ville) AS ville,
               g.id AS dst, g.reference_bien AS dst_ref,
               (SELECT COUNT(*) FROM dossier_vente dv WHERE dv.id_bien = s.id) AS src_dossiers,
               (SELECT COUNT(*) FROM biens_photos ph WHERE ph.id_bien = s.id) AS src_photos
          FROM biens s
          LEFT JOIN immeubles i ON i.id = s.id_immeuble
          JOIN biens g
            ON g.id <> s.id
           AND (g.reference_bien = REPLACE(s.code_crg, '_', '-') OR g.code_crg = s.code_crg)
         WHERE s.code_crg IS NOT NULL AND s.code_crg <> ''
           AND s.statut_bien <> 'supprime'
           AND NOT $skelData
           AND $gardienData
         ORDER BY s.code_crg, s.id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fusionne une coquille (src) dans son gardien (dst). Une transaction.
 * Re-vérifie les invariants (src vide, dst avec données) avant d'agir.
 */
function merge_skeleton(PDO $pdo, int $src, int $dst, string $dataExists): array {
    $stats = ['src' => $src, 'dst' => $dst];
    if ($src <= 0 || $dst <= 0 || $src === $dst) { return $stats + ['error' => 'ids invalides']; }

    // Garde-fou exécution : src DOIT être vide, dst DOIT avoir des données.
    $srcHasData = (bool)$pdo->query('SELECT ' . sprintf($dataExists, (string)$src))->fetchColumn();
    $dstHasData = (bool)$pdo->query('SELECT ' . sprintf($dataExists, (string)$dst))->fetchColumn();
    if ($srcHasData) { return $stats + ['error' => 'source a des données — ignorée (sécurité)']; }
    if (!$dstHasData) { return $stats + ['error' => 'destination sans données — ignorée (sécurité)']; }

    try {
        $pdo->beginTransaction();

        // dossier_vente (UNIQUE id_bien) : transfert si dst n'en a pas ; sinon on garde celui de dst.
        $dstHasDoss = (int)$pdo->query('SELECT COUNT(*) FROM dossier_vente WHERE id_bien = ' . $dst)->fetchColumn();
        if ($dstHasDoss === 0) {
            $st = $pdo->prepare('UPDATE dossier_vente SET id_bien = ? WHERE id_bien = ?');
            $st->execute([$dst, $src]); $stats['dossier_vente'] = $st->rowCount();
        } else {
            $srcHasDoss = (int)$pdo->query('SELECT COUNT(*) FROM dossier_vente WHERE id_bien = ' . $src)->fetchColumn();
            $stats['dossier_vente'] = $srcHasDoss > 0 ? 'conflit (dst a déjà un dossier — source conservée)' : 0;
        }

        // biens_photos
        try { $st = $pdo->prepare('UPDATE biens_photos SET id_bien = ? WHERE id_bien = ?');
              $st->execute([$dst, $src]); $stats['photos'] = $st->rowCount(); }
        catch (Throwable $e) { $stats['photos'] = 'skip'; }

        // tiers_roles (objet='bien')
        try { $st = $pdo->prepare("UPDATE IGNORE tiers_roles SET id_objet = ? WHERE objet_type='bien' AND id_objet = ?");
              $st->execute([$dst, $src]); $stats['tiers_roles'] = $st->rowCount();
              $pdo->prepare("DELETE FROM tiers_roles WHERE objet_type='bien' AND id_objet = ?")->execute([$src]); }
        catch (Throwable $e) { $stats['tiers_roles'] = 'skip'; }

        // ged_documents (FK + metadata JSON)
        try {
            $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM ged_documents LIKE 'id_bien'")->fetchColumn();
            if ($hasCol) { $st = $pdo->prepare('UPDATE ged_documents SET id_bien = ? WHERE id_bien = ?');
                           $st->execute([$dst, $src]); $stats['ged'] = $st->rowCount(); }
            $st = $pdo->prepare("UPDATE ged_documents SET metadata = JSON_SET(metadata, '$.classement.bien_id_bdd', ?)
                                  WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.bien_id_bdd')) = ?");
            $st->execute([$dst, $src]);
        } catch (Throwable $e) { $stats['ged'] = 'skip'; }

        // biens_documents (legacy)
        try { $st = $pdo->prepare('UPDATE biens_documents SET id_bien = ? WHERE id_bien = ?');
              $st->execute([$dst, $src]); $stats['biens_documents'] = $st->rowCount(); }
        catch (Throwable $e) { $stats['biens_documents'] = 'skip'; }

        // mandats : on déplace les éventuels mandats RÉELS ; les AUTO-* de la coquille sont supprimés (bruit).
        try {
            $st = $pdo->prepare("UPDATE mandats SET id_bien = ? WHERE id_bien = ? AND numero_mandat NOT LIKE 'AUTO-%'");
            $st->execute([$dst, $src]); $stats['mandats_reels_deplaces'] = $st->rowCount();
            $st = $pdo->prepare("DELETE FROM mandats WHERE id_bien = ? AND numero_mandat LIKE 'AUTO-%'");
            $st->execute([$src]); $stats['mandats_auto_supprimes'] = $st->rowCount();
        } catch (Throwable $e) { $stats['mandats'] = 'skip'; }

        // Note d'audit + SOFT DELETE de la coquille.
        $note = '[' . date('Y-m-d H:i') . "] Coquille CRG fusionnée → bien #$dst (dédup).";
        $pdo->prepare("UPDATE biens SET commentaire = CONCAT(COALESCE(commentaire,''), '\n', ?) WHERE id = ?")
            ->execute([$note, $dst]);
        $pdo->prepare("UPDATE biens SET statut_bien='supprime',
                          designation = CONCAT('[FUSIONNÉ→#$dst] ', COALESCE(designation,'')),
                          date_modification = NOW() WHERE id = ?")->execute([$src]);

        $pdo->commit();
        $stats['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $stats['error'] = $e->getMessage();
    }
    return $stats;
}

// ── EXÉCUTION ──
$report = null;
if (($_POST['action'] ?? '') === 'run') {
    verify_csrf('dedup_merge');
    $pairs = dedup_pairs($pdo, $skelData, $gardienData);
    $report = ['total' => count($pairs), 'ok' => 0, 'conflits' => 0, 'erreurs' => 0, 'lignes' => []];
    foreach ($pairs as $p) {
        $r = merge_skeleton($pdo, (int)$p['src'], (int)$p['dst'], $dataExists);
        if (!empty($r['ok'])) $report['ok']++; else $report['erreurs']++;
        if (isset($r['dossier_vente']) && is_string($r['dossier_vente'])) $report['conflits']++;
        $r['code_crg'] = $p['code_crg']; $r['dst_ref'] = $p['dst_ref'];
        $report['lignes'][] = $r;
    }
}

// ── APERÇU ──
$pairs = $report ? [] : dedup_pairs($pdo, $skelData, $gardienData);
$nbDoss = 0; $nbPhotos = 0;
foreach ($pairs as $p) { $nbDoss += (int)$p['src_dossiers']; $nbPhotos += (int)$p['src_photos']; }

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Fusion coquilles CRG — Admin</title>
<style>
body{font-family:system-ui,Segoe UI,sans-serif;padding:26px;background:#f7f4ef;max-width:1200px;margin:0 auto;color:#1f2937;}
h1{font-size:21px;} .muted{color:#6b7280;font-size:13px;}
.kpi{display:inline-block;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;margin:8px 12px 8px 0;}
.kpi b{font-size:26px;display:block;color:#b45309;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;font-size:12.5px;margin-top:14px;}
th,td{padding:7px 10px;border-bottom:1px solid #f0ece6;text-align:left;}
th{background:#f3f4f6;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;}
.ref{font-family:monospace;font-weight:700;color:#4338ca;}
.warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin:14px 0;font-size:13px;}
.ok{background:#ecfdf5;border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;margin:14px 0;font-size:13px;}
.err{background:#fef2f2;border-left:4px solid #ef4444;padding:12px 16px;border-radius:8px;margin:14px 0;font-size:13px;}
.btn{background:#b91c1c;color:#fff;border:none;border-radius:9px;padding:12px 22px;font-weight:800;font-size:14px;cursor:pointer;}
code{background:#eef2ff;padding:1px 5px;border-radius:4px;}
</style></head><body>

<h1>🔀 Fusion en lot des coquilles CRG → gardien</h1>

<?php if ($report): ?>
  <div class="ok"><strong>Fusion terminée.</strong>
    <?= (int)$report['ok'] ?> réussie(s) · <?= (int)$report['erreurs'] ?> erreur(s) · <?= (int)$report['conflits'] ?> conflit(s) de dossier de vente.</div>
  <table>
    <thead><tr><th>Coquille</th><th>→ Gardien</th><th>code_crg</th><th>dossier_vente</th><th>photos</th><th>mandats auto suppr.</th><th>résultat</th></tr></thead>
    <tbody>
    <?php foreach ($report['lignes'] as $r): ?>
      <tr>
        <td class="ref">#<?= (int)$r['src'] ?></td>
        <td class="ref">#<?= (int)$r['dst'] ?> <?= htmlspecialchars((string)($r['dst_ref'] ?? '')) ?></td>
        <td><code><?= htmlspecialchars((string)($r['code_crg'] ?? '')) ?></code></td>
        <td><?= htmlspecialchars((string)($r['dossier_vente'] ?? '0')) ?></td>
        <td><?= htmlspecialchars((string)($r['photos'] ?? '0')) ?></td>
        <td><?= htmlspecialchars((string)($r['mandats_auto_supprimes'] ?? '0')) ?></td>
        <td><?= !empty($r['ok']) ? '✅' : ('❌ ' . htmlspecialchars((string)($r['error'] ?? ''))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="margin-top:14px;">Les coquilles fusionnées sont en <code>statut_bien='supprime'</code> → elles disparaissent des listes. Rien n'est détruit physiquement.</p>
  <p><a href="?">↻ Recharger l'aperçu (doit afficher 0 paire restante)</a></p>

<?php elseif (!$pairs): ?>
  <div class="ok">✅ Aucune coquille à fusionner. La base est propre.</div>

<?php else: ?>
  <div>
    <div class="kpi">Paires à fusionner<b><?= count($pairs) ?></b></div>
    <div class="kpi">Dossiers de vente transférés<b><?= $nbDoss ?></b></div>
    <div class="kpi">Lots photos transférés<b><?= $nbPhotos ?></b></div>
  </div>
  <div class="warn">
    <strong>Aperçu — rien n'est encore modifié.</strong> Chaque coquille vide sera fusionnée dans son gardien
    (transfert dossier de vente / photos / docs / tiers + vrais mandats), ses mandats <code>AUTO-*</code> supprimés,
    puis elle passera en <code>supprime</code>. Vérifie la liste puis lance.
  </div>
  <form method="post" onsubmit="return confirm('Fusionner les <?= count($pairs) ?> coquilles dans leur gardien ? Action sur la prod.');">
    <?= csrf_field('dedup_merge') ?>
    <input type="hidden" name="action" value="run">
    <button class="btn" type="submit">🔀 Lancer la fusion des <?= count($pairs) ?> paires</button>
  </form>

  <table>
    <thead><tr><th>Coquille</th><th>réf</th><th>code_crg</th><th>statut</th><th>adresse</th><th>→ Gardien</th><th>dossier</th><th>photos</th></tr></thead>
    <tbody>
    <?php foreach ($pairs as $p): ?>
      <tr>
        <td class="ref">#<?= (int)$p['src'] ?></td>
        <td><?= htmlspecialchars((string)($p['src_ref'] ?? '') ?: '—') ?></td>
        <td><code><?= htmlspecialchars((string)($p['code_crg'] ?? '')) ?></code></td>
        <td><?= htmlspecialchars((string)($p['statut_bien'] ?? '')) ?></td>
        <td><?= htmlspecialchars(trim((string)($p['adresse'] ?? '') . ' ' . (string)($p['ville'] ?? ''))) ?></td>
        <td class="ref">#<?= (int)$p['dst'] ?> <?= htmlspecialchars((string)($p['dst_ref'] ?? '')) ?></td>
        <td><?= (int)$p['src_dossiers'] ?: '' ?></td>
        <td><?= (int)$p['src_photos'] ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

</body></html>
