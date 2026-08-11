<?php
declare(strict_types=1);
/**
 * admin_bulletins_reprise_coffre.php — outil admin one-shot.
 *
 * Les bulletins rangés AVANT la bascule au coffre sont dans `rh_documents`, table que
 * le salarié lit lui-même (rh_documents_user.php) : sa paie lui est visible en ligne.
 * Cette page les reprend un par un — le PDF part au coffre salaires (accès nominatif,
 * versionné) et la ligne `rh_documents` est ARCHIVÉE (`actif = 0`), jamais supprimée :
 * le fichier d'origine et sa trace restent intacts.
 *
 * Sans écriture tant que le bouton n'est pas cliqué : l'affichage seul est un état des lieux.
 * Réservé role_id = 1 (super admin).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/rh_bulletins_coffre.php';

require_login();
if (current_role_id() !== 1) {
    http_response_code(403);
    exit('Réservé super admin (role_id=1).');
}

$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Mois/année d'un bulletin : d'abord ses métadonnées, sinon son libellé « … 06/2026 ». */
function repr_periode(array $doc): array
{
    $meta = json_decode((string)($doc['metadata_json'] ?? ''), true);
    if (is_array($meta) && !empty($meta['mois']) && !empty($meta['annee'])) {
        return [(int)$meta['mois'], (int)$meta['annee']];
    }
    if (preg_match('#(\d{1,2})[/-](\d{4})#', (string)($doc['label'] ?? ''), $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    // Dernier recours : le mois du dépôt du fichier.
    $ts = strtotime((string)($doc['upload_date'] ?? '')) ?: time();
    return [(int)date('n', $ts), (int)date('Y', $ts)];
}

/** Chemin serveur du PDF (les valeurs stockées sont hétérogènes). */
function repr_fichier(array $doc): ?string
{
    $racine = dirname(__DIR__);
    $essais = [];
    if (!empty($doc['file_path'])) $essais[] = $racine . '/' . ltrim(str_replace('\\', '/', (string)$doc['file_path']), '/');
    if (!empty($doc['filename']))  $essais[] = $racine . '/uploads/rh_docs/' . (int)$doc['id_user'] . '/' . (string)$doc['filename'];
    foreach ($essais as $e) if (is_file($e) && is_readable($e)) return $e;
    return null;
}

// ── État des lieux ────────────────────────────────────────────────────────────
$st = $pdo->query("
    SELECT d.id, d.id_user, d.id_societe, d.id_agence, d.label, d.filename, d.file_path,
           d.metadata_json, d.upload_date,
           u.nom, u.prenom, u.id_agence AS user_agence, u.id_societe AS user_societe,
           COALESCE(u.matricule_paie,'') AS matricule, COALESCE(u.num_secu,'') AS num_secu
      FROM rh_documents d
 LEFT JOIN users u ON u.id = d.id_user
     WHERE d.type_document = 'bulletin_paie' AND d.actif = 1
  ORDER BY d.id");
$lignes = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Reprise (écriture) ────────────────────────────────────────────────────────
$rapport = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reprendre') {
    verify_csrf('bulletins_reprise');

    foreach ($lignes as $d) {
        $nom = trim(((string)$d['prenom']) . ' ' . ((string)$d['nom']));
        $abs = repr_fichier($d);
        if ($abs === null) { $rapport[] = ['id' => $d['id'], 'nom' => $nom, 'etat' => 'fichier introuvable sur le serveur']; continue; }

        [$mois, $annee] = repr_periode($d);
        $idSoc    = (int)($d['id_societe'] ?: $d['user_societe']);
        $idAgence = (int)($d['id_agence'] ?: $d['user_agence']);

        $salarie = [
            'id' => (int)$d['id_user'], 'matricule' => (string)$d['matricule'],
            'nom' => (string)$d['nom'], 'prenom' => (string)$d['prenom'],
            'num_secu' => (string)$d['num_secu'], 'id_agence' => $idAgence, 'complet' => $nom,
        ];
        // Le contrôle « et personne d'autre » a besoin de tous les salariés de l'agence.
        $tousSal = rhb_salaries($pdo, $idSoc, $idAgence);
        if (!$tousSal) $tousSal = [$salarie];

        $lib = $pdo->prepare("SELECT s.nom AS societe_nom, a.nom_agence AS agence_nom
                                FROM (SELECT 1) x
                           LEFT JOIN societes s ON s.id = ?
                           LEFT JOIN agences  a ON a.id = ?");
        $lib->execute([$idSoc, $idAgence]);
        $noms = $lib->fetch(PDO::FETCH_ASSOC) ?: ['societe_nom' => '', 'agence_nom' => ''];

        $r = rhbc_classer_fichier($pdo, $abs, $salarie, $mois, $annee, $tousSal, [
            'via'         => 'reprise',
            'id_societe'  => $idSoc,
            'id_agence'   => $idAgence,
            'societe_nom' => (string)$noms['societe_nom'],
            'agence_nom'  => (string)$noms['agence_nom'],
        ], $userId);

        if (empty($r['ok'])) {
            $rapport[] = ['id' => $d['id'], 'nom' => $nom, 'etat' => 'NON repris — ' . $r['motif']];
            continue;   // la ligne reste active : on n'archive rien qu'on n'a pas su mettre à l'abri
        }

        /* Archivage de la ligne d'origine — jamais de DELETE : le motif et la date
           restent lisibles, et le fichier physique n'est pas touché. */
        $meta = json_decode((string)($d['metadata_json'] ?? ''), true);
        if (!is_array($meta)) $meta = [];
        $meta['archive'] = [
            'motif'      => 'Bulletin repris au coffre salaires (invisible du salarié)',
            'le'         => date('Y-m-d H:i:s'),
            'par'        => $userId,
            'bulletin_id'=> (int)$r['id'],
        ];
        $pdo->prepare("UPDATE rh_documents SET actif = 0, metadata_json = ? WHERE id = ?")
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$d['id']]);

        $rapport[] = ['id' => $d['id'], 'nom' => $nom,
                      'etat' => 'repris au coffre (v' . $r['version'] . ') — ligne archivée'];
    }

    // Recharger l'état des lieux après écriture.
    $st = $pdo->query("SELECT COUNT(*) FROM rh_documents WHERE type_document = 'bulletin_paie' AND actif = 1");
    $restant = (int)$st->fetchColumn();
} else {
    $restant = count($lignes);
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Reprise des bulletins vers le coffre RH</title>
<h1>Reprise des bulletins vers le coffre RH</h1>

<p><strong><?= $restant ?></strong> bulletin(s) encore dans <code>rh_documents</code> —
donc visibles par le salarié dans son espace Documents.</p>

<?php if ($rapport): ?>
    <h2>Résultat</h2>
    <ul>
    <?php foreach ($rapport as $r): ?>
        <li>#<?= (int)$r['id'] ?> — <?= h($r['nom']) ?> : <?= h($r['etat']) ?></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($lignes && !$rapport): ?>
    <h2>À reprendre</h2>
    <table border="1" cellpadding="6" cellspacing="0">
        <tr><th>#</th><th>Salarié</th><th>Libellé</th><th>Période</th><th>Fichier</th></tr>
        <?php foreach ($lignes as $d): [$m, $y] = repr_periode($d); ?>
        <tr>
            <td><?= (int)$d['id'] ?></td>
            <td><?= h(trim(((string)$d['prenom']) . ' ' . ((string)$d['nom']))) ?></td>
            <td><?= h((string)$d['label']) ?></td>
            <td><?= sprintf('%02d/%04d', $m, $y) ?></td>
            <td><?= repr_fichier($d) ? 'présent' : '<strong>INTROUVABLE</strong>' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <form method="post" style="margin-top:1em">
        <?= csrf_field('bulletins_reprise') ?>
        <input type="hidden" name="action" value="reprendre">
        <button type="submit" onclick="return confirm('Reprendre <?= count($lignes) ?> bulletin(s) au coffre et archiver les lignes ?');">
            Reprendre au coffre
        </button>
    </form>
    <p><small>Le PDF part au coffre salaires, la ligne d'origine est archivée (<code>actif = 0</code>),
    rien n'est supprimé. Un bulletin que le contrôle d'identité refuse n'est pas archivé.</small></p>
<?php elseif (!$lignes): ?>
    <p>Aucun bulletin à reprendre : plus rien n'est visible par les salariés.</p>
<?php endif; ?>
