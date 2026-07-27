<?php
declare(strict_types=1);
/**
 * dossier_vente_partage.php — Page PUBLIQUE (jeton, lecture seule) d'un dossier de vente,
 * partagée à un ACQUÉREUR ou un NOTAIRE : infos du bien + documents SÉLECTIONNÉS
 * (consultation + téléchargement via api/dossier_vente_doc.php). Sans login. Photos : en attente.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/dossier_vente.php';
$pdo = $GLOBALS['pdo'];

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Referrer-Policy: no-referrer');
if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('dv_ged_shortname')) {
    function dv_ged_shortname(string $name): string {
        $ext = '';
        if (preg_match('/(\.[A-Za-z0-9]{2,5})$/', $name, $m)) { $ext = $m[1]; $name = substr($name, 0, -strlen($ext)); }
        $parts = explode('_', $name);
        if (count($parts) >= 6 && preg_match('/^[A-Z]{3,5}$/', $parts[0])) {
            $parts = array_slice($parts, 4);
            $parts = array_values(array_filter($parts, fn($p) => $p !== '' && $p !== '-'));
            return implode(' · ', $parts) . $ext;
        }
        return $name . $ext;
    }
}
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';

function dvp_stop(string $titre, string $msg): void {
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($titre) . '</title>'
       . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#10254d;color:#fff;display:grid;place-items:center;height:100vh;margin:0;}.c{max-width:460px;text-align:center;padding:30px;}h1{font-size:22px;margin:0 0 10px;}p{color:#aebfd8;line-height:1.6;}</style></head>'
       . '<body><div class="c"><div style="font-size:46px;margin-bottom:10px;">🔒</div><h1>' . htmlspecialchars($titre) . '</h1><p>' . htmlspecialchars($msg) . '</p></div></body></html>';
    exit;
}

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if (strlen($token) < 32) dvp_stop('Lien invalide', 'Ce lien de partage est incomplet.');
$st = $pdo->prepare("SELECT * FROM dossier_vente_partage WHERE token = ? LIMIT 1");
$st->execute([$token]);
$share = $st->fetch(PDO::FETCH_ASSOC);
if (!$share)                       dvp_stop('Lien invalide', "Ce lien n'existe pas ou a été supprimé.");
if (!empty($share['revoked_at']))  dvp_stop('Accès clôturé', 'Ce partage a été révoqué.');
if (!empty($share['expires_at']) && strtotime((string)$share['expires_at']) < time())
                                   dvp_stop('Lien expiré', 'Ce lien de partage a expiré.');

try { $pdo->prepare("UPDATE dossier_vente_partage SET nb_vues = nb_vues + 1, last_view_at = NOW() WHERE id = ?")->execute([(int)$share['id']]); } catch (Throwable) {}

$role = (string)($share['role_destinataire'] ?? 'acquereur');
$roleLbl = ['acquereur'=>'Acquéreur','notaire'=>'Notaire','commercialisateur'=>'Commercialisateur'][$role] ?? 'Acquéreur';
$idDossier = (int)$share['id_dossier'];
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) dvp_stop('Dossier indisponible', 'Le dossier de vente est introuvable.');

// Lots du dossier (façon deal-room p.php) : 1 card par bien, photos + caractéristiques + prix.
$lots = function_exists('dv_lots') ? dv_lots($pdo, $idDossier) : [];
if (!$lots && (int)($dossier['id_bien'] ?? 0) > 0) { $lots = [['id_bien' => (int)$dossier['id_bien'], 'rang' => 1]]; }
$totaux = function_exists('dv_totaux') ? dv_totaux($pdo, $idDossier) : ['prix_total'=>0,'rendement_brut'=>null];
$prix = (float)($totaux['prix_total'] ?? 0);

// Enrichissement par lot : caractéristiques biens + photos (biens_photos).
$biensView = [];
foreach ($lots as $l) {
    $bid = (int)($l['id_bien'] ?? 0); if ($bid <= 0) continue;
    $b = [];
    try {
        $sb = $pdo->prepare("SELECT b.reference_bien, b.surface_habitable, b.nb_pieces, b.etage, b.designation, b.description,
                                    bt.libelle AS type_libelle,
                                    COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adr,
                                    COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
                                    COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS cp
                             FROM biens b
                             LEFT JOIN immeubles i ON i.id = b.id_immeuble
                             LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
                             WHERE b.id = ? LIMIT 1");
        $sb->execute([$bid]);
        $b = $sb->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
    $photos = [];
    try {
        $ph = $pdo->prepare("SELECT url_photo FROM biens_photos WHERE ((entity_type='BIEN' AND entity_id = ?) OR id_bien = ?) ORDER BY ordre ASC, id ASC");
        $ph->execute([$bid, $bid]);
        foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $u) { $u = trim((string)$u); if ($u !== '') $photos[] = (function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/') . ltrim($u, '/'); }
    } catch (Throwable) {}
    $prixLot = (float)(($l['prix_vente'] ?? null) ?? ($l['_prix_vente_bien'] ?? 0));
    $biensView[] = ['b' => $b, 'photos' => $photos, 'prix' => $prixLot, 'lot' => $l];
}
$adresse = '';
if ($biensView) { $b0 = $biensView[0]['b']; $adresse = trim(trim((string)($b0['adr'] ?? '')) . ' ' . trim((string)($b0['cp'] ?? '') . ' ' . (string)($b0['ville'] ?? ''))); }

// Documents autorisés (docs_json) — actifs.
$docs = [];
$ids = array_values(array_filter(array_map('intval', json_decode((string)($share['docs_json'] ?? '[]'), true) ?: [])));
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sd = $pdo->prepare("SELECT id, name_display, name_file, document_type, created_at
                             FROM ged_documents WHERE id IN ($in) AND status='active' ORDER BY document_type, id");
        $sd->execute($ids);
        $docs = $sd->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
}
$base = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/';
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dossier de vente — <?= h($adresse ?: ($dossier['reference'] ?? 'Bien')) ?></title>
<style>
:root{--navy:#243B5C;--or:#D4A047;--line:#e6e1d8;--ink:#3a3830;}
*{box-sizing:border-box;} body{font-family:-apple-system,Segoe UI,sans-serif;background:#f4f1ea;color:var(--ink);margin:0;}
.top{background:var(--navy);color:#fff;padding:18px 20px;}
.top .role{display:inline-block;background:var(--or);color:#1c2c46;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:3px 10px;border-radius:999px;margin-bottom:8px;}
.top h1{margin:0;font-size:20px;font-weight:800;}
.top .sub{color:#aebfd8;font-size:13px;margin-top:3px;}
.wrap{max-width:820px;margin:0 auto;padding:18px;}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:16px;}
.card h2{font-size:14px;color:var(--navy);margin:0 0 12px;font-weight:800;}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;}
.kpi .k{font-size:10px;color:#9a9690;text-transform:uppercase;} .kpi .v{font-size:18px;font-weight:800;color:var(--navy);}
.doc{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f2eee7;font-size:13px;}
.doc:last-child{border-bottom:none;}
.doc .t{font-family:'DM Mono',monospace;font-size:10px;color:#5b21b6;background:#f3effa;border-radius:6px;padding:2px 7px;margin-right:6px;}
.btn{border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer;}
.bv{background:#eef1f6;color:var(--navy);} .bd{background:var(--navy);color:#fff;}
.empty{color:#a29c90;font-style:italic;font-size:13px;}
.foot{color:#a29c90;font-size:11px;text-align:center;padding:14px;}
.lot{border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:16px;background:#fff;}
.lot + .lot{margin-top:0;}
.lot-head{padding:14px 16px 4px;}
.lot-head .ref{font-size:11px;color:#9a9690;font-family:'DM Mono',monospace;}
.lot-head h2{font-size:16px;color:var(--navy);margin:2px 0 0;font-weight:800;}
.lot-head .type{display:inline-block;background:#eef1f6;color:var(--navy);font-size:11px;font-weight:700;border-radius:6px;padding:2px 8px;margin-top:4px;}
.gallery{display:flex;gap:6px;overflow-x:auto;padding:12px 16px;scroll-snap-type:x mandatory;}
.gallery img{height:190px;border-radius:8px;object-fit:cover;scroll-snap-align:start;flex:none;background:#f0ece6;}
.gallery.one img{width:100%;height:auto;max-height:340px;object-fit:cover;}
.lot-body{padding:6px 16px 16px;}
.lot-desc{margin-top:10px;font-size:13px;line-height:1.5;white-space:pre-line;}
</style></head><body>
<div class="top">
  <div class="role"><?= h($roleLbl) ?></div>
  <h1><?= h($adresse ?: 'Bien à la vente') ?></h1>
  <div class="sub"><?= count($biensView) > 1 ? h((string)count($biensView)) . ' biens' : 'Dossier de vente' ?> — accès en lecture seule</div>
</div>
<div class="wrap">
  <?php if (count($biensView) > 1 || $prix > 0): ?>
  <div class="card">
    <h2>💼 Synthèse</h2>
    <div class="kpis">
      <?php if (count($biensView) > 1): ?><div class="kpi"><div class="k">Biens</div><div class="v"><?= count($biensView) ?></div></div><?php endif; ?>
      <?php if ($prix > 0): ?><div class="kpi"><div class="k">Prix total</div><div class="v"><?= $eur($prix) ?></div></div><?php endif; ?>
      <?php if (!empty($totaux['rendement_brut'])): ?><div class="kpi"><div class="k">Rendement brut</div><div class="v"><?= number_format((float)$totaux['rendement_brut'], 2, ',', ' ') ?> %</div></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($biensView as $bv): $b = $bv['b']; $photos = $bv['photos']; $plot = (float)$bv['prix']; ?>
  <div class="lot">
    <div class="lot-head">
      <?php if (!empty($b['reference_bien'])): ?><div class="ref"><?= h((string)$b['reference_bien']) ?></div><?php endif; ?>
      <h2><?= h(trim((string)($b['adr'] ?? '') . ' ' . (string)($b['cp'] ?? '') . ' ' . (string)($b['ville'] ?? '')) ?: 'Bien') ?></h2>
      <?php if (!empty($b['type_libelle'])): ?><span class="type"><?= h((string)$b['type_libelle']) ?></span><?php endif; ?>
    </div>
    <?php if ($photos): ?>
      <div class="gallery<?= count($photos) === 1 ? ' one' : '' ?>">
        <?php foreach ($photos as $pu): ?><img src="<?= h($pu) ?>" alt="" loading="lazy">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="lot-body">
      <div class="kpis">
        <?php if (!empty($b['surface_habitable'])): ?><div class="kpi"><div class="k">Surface</div><div class="v"><?= (float)$b['surface_habitable'] ?> m²</div></div><?php endif; ?>
        <?php if (!empty($b['nb_pieces'])): ?><div class="kpi"><div class="k">Pièces</div><div class="v"><?= (int)$b['nb_pieces'] ?></div></div><?php endif; ?>
        <?php if ($b['etage'] !== null && $b['etage'] !== ''): ?><div class="kpi"><div class="k">Étage</div><div class="v"><?= h((string)$b['etage']) ?></div></div><?php endif; ?>
        <?php if ($plot > 0): ?><div class="kpi"><div class="k">Prix</div><div class="v"><?= $eur($plot) ?></div></div><?php endif; ?>
      </div>
      <?php $desc = trim((string)($b['designation'] ?? '') ?: (string)($b['description'] ?? '')); if ($desc !== ''): ?>
        <div class="lot-desc"><?= h(mb_substr($desc, 0, 1500)) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="card">
    <h2>📂 Documents (<?= count($docs) ?>)</h2>
    <?php if (!$docs): ?><div class="empty">Aucun document partagé pour le moment.</div><?php endif; ?>
    <?php foreach ($docs as $d):
      $name = dv_ged_shortname((string)($d['name_display'] ?: ($d['name_file'] ?: ('Document #' . $d['id']))));
      $view = $base . 'api/dossier_vente_doc.php?t=' . h($token) . '&doc=' . (int)$d['id'] . '&mode=inline';
      $dl   = $base . 'api/dossier_vente_doc.php?t=' . h($token) . '&doc=' . (int)$d['id'] . '&mode=download';
    ?>
      <div class="doc">
        <span><?php if (!empty($d['document_type'])): ?><span class="t"><?= h($d['document_type']) ?></span><?php endif; ?><?= h($name) ?></span>
        <span style="display:flex;gap:6px;flex:none;">
          <a class="btn bv" href="<?= h($view) ?>" target="_blank" rel="noopener">👁 Voir</a>
          <a class="btn bd" href="<?= h($dl) ?>">⬇ Télécharger</a>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="foot">Lien sécurisé — les documents confidentiels ne sont jamais accessibles par ce partage.</div>
</body></html>
