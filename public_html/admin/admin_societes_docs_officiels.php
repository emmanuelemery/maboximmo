<?php
declare(strict_types=1);

/**
 * ADMIN — Saisie manuelle des docs officiels par société
 *
 * Bypass de l'OCR Sonnet : remplit directement les attestations RCP + GF par
 * activité dans `societes_couvertures` (la table existante depuis 2026-04-11),
 * + KBIS / Carte pro CPI sur `societes.*` directement.
 *
 * Cas d'usage : OCR a échoué (timeout, scan dégradé), ou on veut éviter le
 * coût IA pour des docs qu'on connaît déjà par cœur.
 *
 * URL : /admin/admin_societes_docs_officiels.php
 *      ou ?sa_id=N pour focus sur une société
 *
 * Sécurité : super admin (role_id = 1) uniquement.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin (role_id=1)</h1>');
}

$pdo = $GLOBALS['pdo'];

// Activités exercées (matchent l'ENUM de societes_couvertures.activite)
const ACTIVITES = ['transaction', 'gestion', 'syndic', 'marchand'];
const ACTIVITES_LABELS = [
    'transaction' => 'Transaction',
    'gestion'     => 'Gestion locative',
    'syndic'      => 'Syndic',
    'marchand'    => 'Marchand de biens',
];

// Note : `marchand` n'est pas dans l'ENUM societes_couvertures (qui a transaction/
// gestion/syndic/location/neuf/multi). Pour le UPSERT on mappe marchand → multi
// en attendant une migration ALTER ENUM, sinon la PRIMARY KEY rejette la valeur.
function mapper_activite_pour_couvertures(string $a): string
{
    return $a === 'marchand' ? 'multi' : $a;
}

$flash    = null;
$focusSoc = (int)($_GET['sa_id'] ?? 0); // focus sur une société particulière

// ─── POST : sauvegarde ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $idSoc = (int)($_POST['id_societe'] ?? 0);
    if ($idSoc > 0) {
        try {
            $pdo->beginTransaction();

            // 1. Société : flags activités + KBIS + Carte pro CPI
            //    (on ne touche que les colonnes qui existent — defensive)
            $colonnesPossibles = [
                'activite_immobilier', 'activite_transaction', 'activite_gestion',
                'activite_syndic', 'activite_marchand', 'activite_rh',
                'kbis_numero', 'kbis_date',
                'carte_pro_numero', 'carte_pro_cci', 'carte_pro_validite',
                // Variante avec les noms historiques de societe.php
                'numero_carte_t', 'cci_carte_t', 'carte_t_date_expiration',
            ];
            try {
                $stCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'societes'");
                $stCols->execute();
                $existingCols = array_map('strtolower', $stCols->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (Throwable) { $existingCols = []; }

            $sets   = [];
            $params = [':id' => $idSoc];

            $applyIfExists = function (string $col, $value) use (&$sets, &$params, $existingCols) {
                if (in_array(strtolower($col), $existingCols, true)) {
                    $sets[]              = "`{$col}` = :{$col}";
                    $params[":{$col}"]   = $value;
                }
            };

            $applyIfExists('activite_immobilier',  !empty($_POST['activite_immobilier'])  ? 1 : 0);
            $applyIfExists('activite_transaction', !empty($_POST['activite_transaction']) ? 1 : 0);
            $applyIfExists('activite_gestion',     !empty($_POST['activite_gestion'])     ? 1 : 0);
            $applyIfExists('activite_syndic',      !empty($_POST['activite_syndic'])      ? 1 : 0);
            $applyIfExists('activite_marchand',    !empty($_POST['activite_marchand'])    ? 1 : 0);
            $applyIfExists('activite_rh',          !empty($_POST['activite_rh'])          ? 1 : 0);

            $kbisNum  = trim((string)($_POST['kbis_numero']         ?? '')) ?: null;
            $kbisDate = trim((string)($_POST['kbis_date']           ?? '')) ?: null;
            $cpiNum   = trim((string)($_POST['carte_pro_numero']    ?? '')) ?: null;
            $cpiCci   = trim((string)($_POST['carte_pro_cci']       ?? '')) ?: null;
            $cpiVal   = trim((string)($_POST['carte_pro_validite']  ?? '')) ?: null;

            $applyIfExists('kbis_numero',         $kbisNum);
            $applyIfExists('kbis_date',           $kbisDate);
            $applyIfExists('carte_pro_numero',    $cpiNum);
            $applyIfExists('carte_pro_cci',       $cpiCci);
            $applyIfExists('carte_pro_validite',  $cpiVal);
            // Compat avec les noms historiques de societe.php
            $applyIfExists('numero_carte_t',          $cpiNum);
            $applyIfExists('cci_carte_t',             $cpiCci);
            $applyIfExists('carte_t_date_expiration', $cpiVal);

            if (!empty($sets)) {
                $sql = "UPDATE societes SET " . implode(', ', $sets) . " WHERE id = :id";
                $pdo->prepare($sql)->execute($params);
            }

            // 2. RCP + GF par activité — UPSERT en mode COMPLÉMENT
            //    COALESCE(VALUES(col), col) : la saisie manuelle vient compléter
            //    les valeurs existantes (issues de l'OCR ou d'une saisie précédente).
            //    Si un champ est laissé vide dans le form, on PRÉSERVE la valeur
            //    en base — pas d'écrasement intempestif des extractions IA.
            $upsertCouv = $pdo->prepare("
                INSERT INTO societes_couvertures
                  (id_societe, type, activite, compagnie, numero_police, montant,
                   date_expiration, est_active, version_num, updated_at)
                VALUES
                  (:id_soc, :type, :act, :compagnie, :numero, :montant,
                   :date_exp, 1, 1, NOW())
                ON DUPLICATE KEY UPDATE
                  compagnie       = COALESCE(VALUES(compagnie),       compagnie),
                  numero_police   = COALESCE(VALUES(numero_police),   numero_police),
                  montant         = COALESCE(VALUES(montant),         montant),
                  date_expiration = COALESCE(VALUES(date_expiration), date_expiration),
                  est_active      = 1,
                  updated_at      = NOW()
            ");

            foreach (ACTIVITES as $act) {
                $actDb = mapper_activite_pour_couvertures($act);

                // RC pro — upsert si AU MOINS un champ rempli (sinon on touche pas)
                $rcpA = trim((string)($_POST["rcp_{$act}_assureur"] ?? ''));
                $rcpN = trim((string)($_POST["rcp_{$act}_numero"]   ?? ''));
                $rcpV = trim((string)($_POST["rcp_{$act}_validite"] ?? ''));
                $rcpM = trim((string)($_POST["rcp_{$act}_montant"]  ?? ''));
                if ($rcpA !== '' || $rcpN !== '' || $rcpV !== '' || $rcpM !== '') {
                    $upsertCouv->execute([
                        ':id_soc'    => $idSoc,
                        ':type'      => 'rcp',
                        ':act'       => $actDb,
                        ':compagnie' => $rcpA ?: null,
                        ':numero'    => $rcpN ?: null,
                        ':montant'   => $rcpM !== '' ? (float)str_replace([' ', ','], ['', '.'], $rcpM) : null,
                        ':date_exp'  => $rcpV ?: null,
                    ]);
                }

                // Garantie financière
                $gfN   = trim((string)($_POST["gf_{$act}_nom"]      ?? ''));
                $gfNum = trim((string)($_POST["gf_{$act}_numero"]   ?? ''));
                $gfV   = trim((string)($_POST["gf_{$act}_validite"] ?? ''));
                $gfM   = trim((string)($_POST["gf_{$act}_montant"]  ?? ''));
                if ($gfN !== '' || $gfNum !== '' || $gfV !== '' || $gfM !== '') {
                    $upsertCouv->execute([
                        ':id_soc'    => $idSoc,
                        ':type'      => 'garantie_financiere',
                        ':act'       => $actDb,
                        ':compagnie' => $gfN ?: null,
                        ':numero'    => $gfNum ?: null,
                        ':montant'   => $gfM !== '' ? (float)str_replace([' ', ','], ['', '.'], $gfM) : null,
                        ':date_exp'  => $gfV ?: null,
                    ]);
                }
            }

            $pdo->commit();
            $flash = ['ok' => true, 'msg' => '✅ Société #' . $idSoc . ' enregistrée. Voir le résultat sur '
                . '<a href="../societe.php?sa_id=' . $idSoc . '#financier" target="_blank">societe.php → Financier</a>.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $flash = ['ok' => false, 'msg' => '❌ Erreur : ' . htmlspecialchars($e->getMessage())];
        }
    }
}

// ─── Chargement données ───
$socWhere = $focusSoc > 0 ? 'id = ' . $focusSoc : "nom != 'Externe'";

// Lecture défensive : on prend `*` pour ne pas planter si certaines colonnes manquent
$societes = $pdo->query("SELECT * FROM societes WHERE $socWhere ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Charge couvertures actives, indexé par [id_societe][type][activite]
$couvertures = [];
try {
    $rows = $pdo->query("
        SELECT id_societe, type, activite, compagnie, numero_police, montant,
               date_expiration
        FROM societes_couvertures
        WHERE est_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $actDb = $r['activite'] === 'multi' ? 'marchand' : $r['activite'];
        $couvertures[(int)$r['id_societe']][$r['type']][$actDb] = $r;
    }
} catch (Throwable) {}

// rh_documents par société (chips de visualisation)
$rhDocs = [];
try {
    $rows = $pdo->query("
        SELECT id, id_societe, type_document, ocr_at, ocr_confidence, file_path, upload_date
        FROM rh_documents
        WHERE actif = 1 AND categorie IN ('societe', 'agence')
          AND id_societe IS NOT NULL
        ORDER BY id_societe, type_document, upload_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $rhDocs[(int)$r['id_societe']][$r['type_document']][] = $r;
    }
} catch (Throwable) {}

$csrf = csrf_token();

function rhd_label(string $t): string {
    return match ($t) {
        'kbis' => 'KBIS', 'carte_pro' => 'Carte pro CPI',
        'rcp_transaction' => 'RCP T', 'rcp_gestion' => 'RCP G',
        'rcp_syndic' => 'RCP S', 'rcp_marchand' => 'RCP M',
        'gf_transaction' => 'GF T', 'gf_gestion' => 'GF G',
        'gf_syndic' => 'GF S', 'gf_marchand' => 'GF M',
        'bareme_honoraires' => 'Barème', 'assurance_mri' => 'MRI',
        default => $t,
    };
}

function rhd_url(string $fp): string {
    if ($fp === '') return '';

    // 1. Résout les artefacts `/foo/../` (exemple : .../dev/api/../uploads/...)
    //    → on obtient un chemin canonique sans réf relatives.
    $normalized = $fp;
    while (preg_match('#/[^/]+/\.\./#', $normalized)) {
        $new = preg_replace('#/[^/]+/\.\./#', '/', $normalized);
        if ($new === $normalized) break; // évite boucle infinie
        $normalized = $new;
    }

    // 2. Détecte l'env d'origine depuis le path. Sur Hostinger les chemins
    //    absolus contiennent /public_html/dev/ pour dev et /public_html/ pour prod.
    //    On en déduit l'URL HTTPS publique correspondante (utile quand on browse
    //    cette page depuis localhost — les fichiers physiques sont sur Hostinger).
    if (str_contains($normalized, '/public_html/dev/')) {
        $base = 'https://dev.maboximmo.fr';
        $rel  = preg_replace('#^.*?/public_html/dev/#', '/', $normalized) ?: $normalized;
    } elseif (str_contains($normalized, '/public_html/')) {
        $base = 'https://maboximmo.fr';
        $rel  = preg_replace('#^.*?/public_html/#', '/', $normalized) ?: $normalized;
    } else {
        // Path local (XAMPP) ou autre — on retourne tel quel, le browser fera son boulot
        $base = '';
        $rel  = $normalized;
    }

    return $base . $rel;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8">
<title>Saisie manuelle docs officiels</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 1500px; margin: 24px auto; padding: 0 20px; line-height: 1.5; background: #f5f7fa; color: #0f172a; }
  h1 { margin-bottom: 6px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 14px; }
  .infobox { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; color: #92400e; }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .flash.ok a { color: #14532d; font-weight: 700; }
  .flash.ko { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .societe-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
  .societe-title { font-size: 20px; font-weight: 700; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
  .societe-id { font-family: monospace; font-size: 11px; color: #94a3b8; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; }
  .docs-existants { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 12px 14px; margin: 12px 0 16px; }
  .docs-existants-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #0369a1; font-weight: 700; margin-bottom: 8px; }
  .docs-list { display: flex; flex-wrap: wrap; gap: 8px; }
  .doc-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; }
  .doc-chip.ocr-ok { border-color: #86efac; background: #f0fdf4; color: #166534; }
  .doc-chip.ocr-ko { border-color: #fca5a5; background: #fef2f2; color: #991b1b; }
  .doc-chip a { text-decoration: none; color: inherit; }
  .doc-chip-meta { font-size: 10px; color: #64748b; margin-left: 4px; }
  .activites { display: flex; flex-wrap: wrap; gap: 10px; margin: 14px 0 18px; padding: 12px 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; }
  .activites-label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.06em; align-self: center; margin-right: 6px; }
  .check-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fff; border: 1.5px solid #e5e7eb; border-radius: 99px; font-size: 13px; cursor: pointer; user-select: none; }
  .check-pill input { margin: 0; }
  .check-pill:has(input:checked) { background: #f0fdfa; border-color: #14b8a6; }
  .check-pill input:checked + span { font-weight: 700; color: #0f766e; }
  .grid-haut { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
  .group { background: #f8fafc; border-radius: 10px; padding: 14px 16px; border: 1px solid #e2e8f0; }
  .group h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #475569; margin: 0 0 12px; font-weight: 700; }
  .activite-block { border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin-top: 12px; background: #fff; transition: all 0.2s; }
  .activite-block.active { border-color: #14b8a6; background: #f0fdfa; }
  .activite-block.disabled { opacity: 0.45; }
  .activite-block h4 { font-size: 14px; margin: 0 0 12px; color: #0f766e; font-weight: 700; }
  .activite-block.disabled h4 { color: #94a3b8; }
  .activite-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }
  .field label { font-size: 11px; color: #64748b; font-weight: 600; }
  .field input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 13px; background: #fff; }
  .field input:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 2px rgba(14,165,233,0.15); }
  .actions { margin-top: 18px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .btn { padding: 9px 20px; border-radius: 8px; border: none; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .btn-primary { background: #0ea5e9; color: #fff; } .btn-primary:hover { background: #0284c7; }
  .btn-back { background: #fff; color: #475569; border: 1px solid #cbd5e1; } .btn-back:hover { background: #f8fafc; }
  .help { font-size: 11px; color: #94a3b8; font-style: italic; }
</style>
</head><body>

<h1>✏️ Saisie manuelle docs officiels</h1>
<p class="sub">Permet de remplir directement les attestations sans passer par l'analyse IA d'un PDF (utile quand l'OCR a échoué ou quand on a les valeurs à la main). Les données sont stockées dans <code>societes_couvertures</code> — la même table que l'onglet Financier de la fiche société.</p>

<div class="infobox">
  💡 <strong>UI complète</strong> : la fiche société à <code>societe.php?sa_id=N</code> →
  onglet 📑 Financier affiche déjà ces attestations en lecture/édition. Cette
  page est prévue pour les saisies en masse ou quand l'OCR est en panne.
</div>

<?php if ($flash): ?>
  <div class="flash <?= $flash['ok'] ? 'ok' : 'ko' ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<?php if ($focusSoc > 0): ?>
  <p><a href="?" class="btn btn-back">← Voir toutes les sociétés</a></p>
<?php endif; ?>

<?php foreach ($societes as $s):
    $idS = (int)$s['id'];
    $couvS = $couvertures[$idS] ?? ['rcp' => [], 'garantie_financiere' => []];
    $rhdS  = $rhDocs[$idS] ?? [];
?>
<form method="post" class="societe-card" data-societe-id="<?= $idS ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="id_societe" value="<?= $idS ?>">

  <h2 class="societe-title">
    <?= htmlspecialchars((string)$s['nom']) ?>
    <span class="societe-id">id=<?= $idS ?></span>
    <a href="../societe.php?sa_id=<?= $idS ?>#financier" target="_blank" style="margin-left:auto;font-size:12px;color:#0ea5e9;text-decoration:none;font-weight:600;">→ Voir fiche société</a>
  </h2>

  <?php if (!empty($rhdS)): ?>
  <div class="docs-existants">
    <div class="docs-existants-title">📎 PDF déjà uploadés (pas besoin de re-charger) — clique pour ouvrir / 🔄 relancer OCR</div>
    <div class="docs-list">
      <?php foreach ($rhdS as $type => $listeDocs):
            $d = $listeDocs[0];
            $hasOcr = !empty($d['ocr_at']) && (int)$d['ocr_confidence'] >= 50;
            $cls = $hasOcr ? 'ocr-ok' : 'ocr-ko';
            $url = rhd_url((string)($d['file_path'] ?? ''));
            $confTxt = !empty($d['ocr_at']) ? ((int)$d['ocr_confidence'] . '% OCR') : 'OCR à relancer';
      ?>
      <span class="doc-chip <?= $cls ?>">
        <span><?= $hasOcr ? '✅' : '⚠️' ?></span>
        <strong><?= htmlspecialchars(rhd_label($type)) ?></strong>
        <?php if ($url !== ''): ?><a href="<?= htmlspecialchars($url) ?>" target="_blank" title="Ouvrir le PDF">📄</a><?php endif; ?>
        <a href="admin_relancer_ocr_doc.php?id=<?= (int)$d['id'] ?>" title="Relancer OCR">🔄</a>
        <span class="doc-chip-meta"><?= htmlspecialchars($confTxt) ?></span>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="activites">
    <span class="activites-label">Activités exercées :</span>
    <label class="check-pill"><input type="checkbox" name="activite_immobilier" <?= !empty($s['activite_immobilier'] ?? null) ? 'checked' : '' ?>><span>🏠 Immobilier</span></label>
    <label class="check-pill act-toggle" data-act="transaction"><input type="checkbox" name="activite_transaction" <?= !empty($s['activite_transaction'] ?? null) ? 'checked' : '' ?>><span>📑 Transaction</span></label>
    <label class="check-pill act-toggle" data-act="gestion"><input type="checkbox" name="activite_gestion" <?= !empty($s['activite_gestion'] ?? null) ? 'checked' : '' ?>><span>🏘️ Gestion</span></label>
    <label class="check-pill act-toggle" data-act="syndic"><input type="checkbox" name="activite_syndic" <?= !empty($s['activite_syndic'] ?? null) ? 'checked' : '' ?>><span>🏢 Syndic</span></label>
    <label class="check-pill act-toggle" data-act="marchand"><input type="checkbox" name="activite_marchand" <?= !empty($s['activite_marchand'] ?? null) ? 'checked' : '' ?>><span>💼 Marchand</span></label>
    <label class="check-pill"><input type="checkbox" name="activite_rh" <?= !empty($s['activite_rh'] ?? null) ? 'checked' : '' ?>><span>👥 RH</span></label>
  </div>

  <div class="grid-haut">
    <div class="group">
      <h3>📜 KBIS</h3>
      <div class="field"><label>N° RCS</label><input type="text" name="kbis_numero" value="<?= htmlspecialchars((string)($s['kbis_numero'] ?? '')) ?>"></div>
      <div class="field"><label>Date émission</label><input type="date" name="kbis_date" value="<?= htmlspecialchars((string)($s['kbis_date'] ?? '')) ?>"></div>
    </div>
    <div class="group">
      <h3>🪪 Carte pro CPI (Hoguet)</h3>
      <?php
        $cpiNum = (string)($s['carte_pro_numero'] ?? $s['numero_carte_t'] ?? '');
        $cpiCci = (string)($s['carte_pro_cci']    ?? $s['cci_carte_t']    ?? '');
        $cpiVal = (string)($s['carte_pro_validite']?? $s['carte_t_date_expiration'] ?? '');
      ?>
      <div class="field"><label>N° CPI</label><input type="text" name="carte_pro_numero" value="<?= htmlspecialchars($cpiNum) ?>"></div>
      <div class="field"><label>CCI émettrice</label><input type="text" name="carte_pro_cci" value="<?= htmlspecialchars($cpiCci) ?>"></div>
      <div class="field"><label>Validité</label><input type="date" name="carte_pro_validite" value="<?= htmlspecialchars($cpiVal) ?>"></div>
    </div>
  </div>

  <?php foreach (ACTIVITES as $act):
      $isActive = !empty($s["activite_{$act}"] ?? null);
      $rcpRow   = $couvS['rcp'][$act] ?? [];
      $gfRow    = $couvS['garantie_financiere'][$act] ?? [];
      $emoji    = ['transaction'=>'📑','gestion'=>'🏘️','syndic'=>'🏢','marchand'=>'💼'][$act];
  ?>
  <div class="activite-block <?= $isActive ? 'active' : 'disabled' ?>" data-activite="<?= $act ?>">
    <h4><?= $emoji ?> <?= ACTIVITES_LABELS[$act] ?></h4>
    <div class="activite-row">
      <div>
        <strong style="font-size:11px;color:#475569;">🛡️ RC Pro <?= ACTIVITES_LABELS[$act] ?></strong>
        <div class="field"><label>Assureur (compagnie)</label><input type="text" name="rcp_<?= $act ?>_assureur" value="<?= htmlspecialchars((string)($rcpRow['compagnie'] ?? '')) ?>" placeholder="ex : Galian-SMABTP"></div>
        <div class="field"><label>N° police</label><input type="text" name="rcp_<?= $act ?>_numero" value="<?= htmlspecialchars((string)($rcpRow['numero_police'] ?? '')) ?>"></div>
        <div class="field"><label>Validité</label><input type="date" name="rcp_<?= $act ?>_validite" value="<?= htmlspecialchars((string)($rcpRow['date_expiration'] ?? '')) ?>"></div>
        <div class="field"><label>Plafond garantie (€)</label><input type="text" name="rcp_<?= $act ?>_montant" value="<?= htmlspecialchars((string)($rcpRow['montant'] ?? '')) ?>"></div>
      </div>
      <div>
        <strong style="font-size:11px;color:#475569;">💰 Garantie financière <?= ACTIVITES_LABELS[$act] ?></strong>
        <div class="field"><label>Garant (compagnie)</label><input type="text" name="gf_<?= $act ?>_nom" value="<?= htmlspecialchars((string)($gfRow['compagnie'] ?? '')) ?>" placeholder="ex : Galian-SMABTP"></div>
        <div class="field"><label>N° police</label><input type="text" name="gf_<?= $act ?>_numero" value="<?= htmlspecialchars((string)($gfRow['numero_police'] ?? '')) ?>"></div>
        <div class="field"><label>Validité</label><input type="date" name="gf_<?= $act ?>_validite" value="<?= htmlspecialchars((string)($gfRow['date_expiration'] ?? '')) ?>"></div>
        <div class="field"><label>Plafond garantie (€)</label><input type="text" name="gf_<?= $act ?>_montant" value="<?= htmlspecialchars((string)($gfRow['montant'] ?? '')) ?>"></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="actions">
    <button type="submit" class="btn btn-primary">💾 Enregistrer cette société</button>
    <a href="../societe.php?sa_id=<?= $idS ?>#financier" class="btn btn-back">→ Voir résultat sur la fiche</a>
    <span class="help">Données écrites dans societes_couvertures (mêmes que la fiche société).</span>
  </div>
</form>
<?php endforeach; ?>

<script>
document.querySelectorAll('.act-toggle input').forEach(cb => {
    const update = () => {
        const act = cb.closest('.act-toggle').dataset.act;
        const block = cb.closest('form').querySelector('.activite-block[data-activite="' + act + '"]');
        if (block) {
            block.classList.toggle('active', cb.checked);
            block.classList.toggle('disabled', !cb.checked);
        }
    };
    cb.addEventListener('change', update);
});
</script>

</body></html>
