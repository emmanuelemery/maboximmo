<?php
/**
 * AJAX — Historique CRG complet d'un locataire
 * GET: id_bien, locataire_nom, id_proprietaire
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'msg'=>'Non connecté']); exit; }

$pdo = $GLOBALS['pdo'];

$id_bien       = (int)($_GET['id_bien'] ?? 0);
$locataire_nom = trim($_GET['locataire_nom'] ?? '');
$id_prop       = (int)($_GET['id_proprietaire'] ?? 0);

if (!$id_bien || !$locataire_nom || !$id_prop) {
    echo json_encode(['ok'=>false,'msg'=>'paramètres manquants']); exit;
}

$stmt = $pdo->prepare("
    SELECT
        ct.annee, ct.trimestre,
        crg.loyer_appele, crg.total_loyers, crg.total_regle, crg.total_impaye,
        crg.page_pdf,
        b.reference_bien,
        i.nom_immeuble, i.adresse_1,
        p.societe, p.nom AS prop_nom,
        ct.id AS id_crg,
        ct.pdf_fichier
    FROM crg_situations_locataires crg
    JOIN crg_trimestres ct ON crg.id_crg = ct.id
    LEFT JOIN biens b ON b.id = crg.id_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    LEFT JOIN proprietaires p ON p.id = ct.id_proprietaire
    WHERE crg.id_bien = ? AND crg.locataire_nom = ? AND ct.id_proprietaire = ?
    ORDER BY ct.annee ASC, ct.trimestre ASC
");
$stmt->execute([$id_bien, $locataire_nom, $id_prop]);

$trimestres = [];
while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $trimestres[] = [
        'label'        => $r['annee'].' T'.$r['trimestre'],
        'annee'        => (int)$r['annee'],
        'trimestre'    => (int)$r['trimestre'],
        'loyer'        => (float)$r['loyer_appele'],
        'total_loyers' => (float)$r['total_loyers'],
        'regle'        => (float)$r['total_regle'],
        'impaye'       => (float)$r['total_impaye'],
        'reference_bien'=> $r['reference_bien'],
        'nom_immeuble' => $r['nom_immeuble'],
        'adresse'      => $r['adresse_1'],
        'proprietaire' => $r['societe'] ?: $r['prop_nom'],
        'id_crg'       => (int)$r['id_crg'],
        'page_pdf'     => $r['page_pdf'] ? (int)$r['page_pdf'] : null,
        'pdf_url'      => $r['pdf_fichier'] ? ('/' . ltrim($r['pdf_fichier'], '/')) : null,
    ];
}

// Calcul deltas
for ($i = 1; $i < count($trimestres); $i++) {
    $trimestres[$i]['delta_impaye'] = $trimestres[$i]['impaye'] - $trimestres[$i-1]['impaye'];
    $trimestres[$i]['delta_regle']  = $trimestres[$i]['regle']  - $trimestres[$i-1]['regle'];
}
if (isset($trimestres[0])) {
    $trimestres[0]['delta_impaye'] = null;
    $trimestres[0]['delta_regle']  = null;
}

echo json_encode(['ok' => true, 'locataire' => $locataire_nom, 'trimestres' => $trimestres]);
