<?php
declare(strict_types=1);
/**
 * tools/crg_backfill_immeuble_adresse.php — Backfill CP + ville des immeubles issus du CRG.
 *
 * L'import CRG mettait toute l'adresse dans `adresse_1` sans séparer `code_postal`/`ville`
 * → le registre copropriété (RNC) affichait « cp+voie ou lat/lng requis ». Ce script
 * découpe `adresse_1` (« voie CP ville ») et renseigne `code_postal` + `ville` quand ils
 * manquent. Réservé aux admins. Dry-run par défaut ; ajouter ?apply=1 pour écrire.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crg_import_core.php';
require_admin_or_super_admin();

header('Content-Type: text/html; charset=utf-8');
$pdo   = $GLOBALS['pdo'];
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// Immeubles CRG à corriger :
//  (a) CP manquant mais adresse_1 contient un CP → à découper ;
//  (b) ville POLLUÉE par le tableau CRG (RECAPITULATIF, DEBITS, …) → à re-nettoyer.
$rows = $pdo->query("
    SELECT id, nom_immeuble, adresse_1, code_postal, ville
    FROM immeubles
    WHERE COALESCE(code_crg,'') <> ''
      AND (
        (COALESCE(code_postal,'') = '' AND adresse_1 REGEXP '[0-9]{5}')
        OR ville REGEXP 'R[EÉ]CAPITULATIF|OP[EÉ]RATIONS|D[EÉ]BITS|CR[EÉ]DITS|D[EÉ]PENSES|D[EÉ]DUCTIBLE|LOCATIF|SYNDIC| DONT | T\\.?V\\.?A'
      )
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

echo '<meta charset="utf-8"><style>body{font-family:system-ui,sans-serif;margin:24px;color:#243B5C}'
   . 'table{border-collapse:collapse;font-size:13px}td,th{border:1px solid #dce3ec;padding:5px 9px}'
   . '.ok{color:#15803d}.no{color:#b5352e}h1{font-size:18px}code{background:#eef2f7;padding:1px 5px;border-radius:4px}</style>';
echo '<h1>Backfill adresse immeubles CRG — ' . ($apply ? '<span class="no">MODE ÉCRITURE</span>' : 'DRY-RUN (aperçu)') . '</h1>';
echo '<p>' . count($rows) . ' immeuble(s) candidat(s). ' . ($apply ? '' : 'Ajoute <code>?apply=1</code> à l\'URL pour appliquer.') . '</p>';
echo '<table><tr><th>id</th><th>nom</th><th>adresse_1 (avant)</th><th>→ voie</th><th>→ CP</th><th>→ ville</th><th>action</th></tr>';

$upd = $pdo->prepare("UPDATE immeubles SET adresse_1=?, code_postal=?, ville=? WHERE id=?");
$n = 0;
foreach ($rows as $r) {
    $cpActuel = trim((string)$r['code_postal']);
    if ($cpActuel === '') {
        // (a) CP absent → on découpe adresse_1.
        [$voie, $cp, $ville] = crg_split_adresse((string)$r['adresse_1']);
        $voieFinal = $voie ?: (string)$r['adresse_1'];
    } else {
        // (b) CP déjà présent, ville polluée → on nettoie la ville, adresse_1 inchangée.
        $cp = $cpActuel; $voieFinal = (string)$r['adresse_1']; $ville = crg_clean_ville((string)$r['ville']);
    }
    $doable = ($cp !== '');
    $act = '—';
    if ($doable) {
        if ($apply) { $upd->execute([$voieFinal, $cp, $ville ?: null, (int)$r['id']]); $act = '<span class="ok">écrit</span>'; $n++; }
        else { $act = '<span class="ok">à écrire</span>'; $n++; }
    } else { $act = '<span class="no">CP non extrait</span>'; }
    echo '<tr><td>' . (int)$r['id'] . '</td><td>' . htmlspecialchars((string)$r['nom_immeuble']) . '</td>'
       . '<td>' . htmlspecialchars((string)$r['adresse_1']) . '</td>'
       . '<td>' . htmlspecialchars($voie) . '</td><td>' . htmlspecialchars($cp) . '</td><td>' . htmlspecialchars($ville) . '</td>'
       . '<td>' . $act . '</td></tr>';
}
echo '</table>';
echo '<p><b>' . $n . '</b> immeuble(s) ' . ($apply ? 'mis à jour.' : 'seront mis à jour.') . '</p>';
