<?php
/**
 * bailleur_patrimoine_export.php — Export Excel (CSV) de l'état du patrimoine
 * par propriétaire (actifs / vides / archivés), pour archivage daté en GED.
 * Respecte la sélection propriétaire du dashboard (props[] / session). Lecture seule.
 * GET : props[] (optionnel). Sortie : CSV UTF-8 (BOM) séparateur ';' → Excel FR.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); exit('Accès réservé au module Bailleur.');
}

// ── Périmètre propriétaires (identique à la page patrimoine) ──
$scopeWhere = '';
if ($isSuperAdmin) {
    $getProps = [];
    if (isset($_GET['props'])) {
        foreach ((array)$_GET['props'] as $sid) { $sid=(int)$sid; if ($sid>0) $getProps[]=$sid; }
    } elseif (!empty($_SESSION['bailleur_props'])) {
        foreach ($_SESSION['bailleur_props'] as $sid) { $sid=(int)$sid; if ($sid>0) $getProps[]=$sid; }
    }
    if ($getProps) $scopeWhere = 'AND ct.id_proprietaire IN ('.implode(',', $getProps).')';
} else {
    $st = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
    $st->execute([$userId]);
    $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    $scopeWhere = $ids ? 'AND ct.id_proprietaire IN ('.implode(',', $ids).')' : 'AND 1=0';
}

$sql = "
  SELECT
    COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ',p.civilite,p.prenom,p.nom)) AS proprietaire,
    i.nom_immeuble, COALESCE(NULLIF(i.adresse_1,''),'') AS adresse, i.code_postal, i.ville,
    b.reference_bien, b.id AS bien_id, crg.locataire_nom,
    COALESCE(NULLIF(b.surface_carrez,0), NULLIF(b.surface_habitable,0), 0) AS surface,
    crg.loyer_appele, crg.total_regle, crg.total_impaye,
    (SELECT bx.loyer FROM baux bx WHERE bx.id_bien=crg.id_bien AND bx.id_proprietaire=ct.id_proprietaire
       ORDER BY (bx.statut='actif') DESC, bx.id DESC LIMIT 1) AS bail_loyer,
    (SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 LIMIT 1) AS prix_valide,
    COALESCE(i.vendu,0) AS imm_vendu,
    COALESCE(ls.archive,0) AS loc_archive,
    CASE WHEN crg.loyer_appele>0 OR crg.locataire_nom='OCCUPÉ PAR PROPRIÉTAIRE' THEN 'present' ELSE 'parti' END AS presence,
    ct.annee, ct.trimestre
  FROM crg_situations_locataires crg
  JOIN crg_trimestres ct ON crg.id_crg = ct.id
  JOIN proprietaires p   ON p.id = ct.id_proprietaire
  LEFT JOIN locataires_statuts ls ON ls.locataire_nom=crg.locataire_nom AND ls.id_bien=crg.id_bien
       AND ls.id_proprietaire=ct.id_proprietaire AND (ls.statut IS NULL OR ls.statut!='irrecoverable')
  LEFT JOIN biens b      ON b.id = crg.id_bien
  LEFT JOIN immeubles i  ON i.id = b.id_immeuble
  WHERE (ct.parse_statut IS NULL OR ct.parse_statut <> 'erreur') $scopeWhere
    AND (ct.annee, ct.trimestre) = (
        SELECT ct2.annee, ct2.trimestre FROM crg_trimestres ct2
        WHERE ct2.id_proprietaire = ct.id_proprietaire AND (ct2.parse_statut IS NULL OR ct2.parse_statut <> 'erreur')
        ORDER BY ct2.annee DESC, ct2.trimestre DESC LIMIT 1)
  ORDER BY proprietaire, i.nom_immeuble, b.reference_bien, crg.locataire_nom";
$rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

// ── Prix AFFICHÉS (live) transmis par la page au moment du clic ──
// L'export reprend l'état EN COURS DE SAISIE (scénario/simulation), pas seulement la BDD.
$prixLive = [];
if (isset($_POST['prix_live']) && $_POST['prix_live'] !== '') {
    $decoded = json_decode((string)$_POST['prix_live'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $bid => $montant) { $prixLive[(int)$bid] = (float)$montant; }
    }
}
$scenarioLabel = trim((string)($_POST['scenario_label'] ?? '')) ?: 'Courant';

// ── Nom de fichier : propriétaire + utilisateur + date/heure ──
$slug = static function (string $s): string {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($t !== false) $s = $t;
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    return trim((string)$s, '-') ?: 'NA';
};
// Propriétaire(s) présents dans l'export
$propsNoms = array_values(array_unique(array_filter(array_map(static fn($r) => (string)$r['proprietaire'], $rows))));
$propPart  = count($propsNoms) === 1 ? $slug($propsNoms[0]) : (count($propsNoms) . '-proprietaires');
// Utilisateur courant
$uSt = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', prenom, nom)) AS n, username, email FROM users WHERE id=? LIMIT 1");
$uSt->execute([$userId]);
$u = $uSt->fetch(\PDO::FETCH_ASSOC) ?: [];
$userNom  = trim((string)($u['n'] ?? '')) ?: (string)($u['username'] ?? $u['email'] ?? ('user' . $userId));
$userPart = $slug($userNom);

$date  = date('Y-m-d');
$fname = 'etat_patrimoine_' . $propPart . '_par_' . $userPart . '_' . date('Ymd_Hi') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 → accents corrects dans Excel
$sep = ';';
$nf = static fn($v) => number_format((float)$v, 2, ',', ' '); // format FR

fputcsv($out, ['État du patrimoine — export du ' . date('d/m/Y H:i')
    . ' — scénario : ' . $scenarioLabel . ' — par : ' . $userNom], $sep);
fputcsv($out, [], $sep);
fputcsv($out, [
    'Propriétaire','Immeuble','Adresse','CP','Ville','Référence bien','Locataire','Statut',
    'Surface m²','Loyer/mois €','Loyer/an €','Prix de vente €','Rentabilité %',
    'Encaissé €','Impayé €','Trimestre CRG'
], $sep);

foreach ($rows as $r) {
    $loyerMois = ((float)$r['bail_loyer'] > 0) ? (float)$r['bail_loyer'] : ((float)$r['loyer_appele'] / 3);
    $loyerAn   = $loyerMois * 12;
    // Prix affiché (live) prioritaire ; repli sur le prix validé en base.
    $bienId    = (int)($r['bien_id'] ?? 0);
    $prix      = $prixLive[$bienId] ?? (float)($r['prix_valide'] ?? 0);
    $renta     = ($prix > 0 && $loyerAn > 0) ? round($loyerAn / $prix * 100, 2) : 0;
    if ((int)$r['loc_archive'] === 1)                           $statut = 'Archivé';
    elseif ($r['locataire_nom'] === 'OCCUPÉ PAR PROPRIÉTAIRE')  $statut = 'Occupé propriétaire';
    elseif ($r['presence'] === 'present')                       $statut = 'Loué';
    else                                                        $statut = 'Vide (locataire parti)';
    fputcsv($out, [
        $r['proprietaire'], $r['nom_immeuble'], $r['adresse'], $r['code_postal'], $r['ville'],
        $r['reference_bien'], $r['locataire_nom'], $statut,
        $nf($r['surface']), $nf($loyerMois), $nf($loyerAn),
        $prix > 0 ? $nf($prix) : '', $renta > 0 ? $nf($renta) : '',
        $nf($r['total_regle']), $nf($r['total_impaye']),
        $r['annee'] . ' T' . $r['trimestre'],
    ], $sep);
}
fclose($out);
