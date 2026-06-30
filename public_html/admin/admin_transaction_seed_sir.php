<?php
// admin/admin_transaction_seed_sir.php — Import 1-clic du portefeuille GROUPE SIR (2026-05-18)
// Idempotent : si reference_bien existe déjà → skip.
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bien_missions.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }

// ─── Données : extraites du PDF "00_LISTE OFFICIELLE DES BIENS A LA VENTE.pdf" ───
// Format : [ref, proprietaire, ville, cp, adresse, type, locataire, surface_m2, parkings, loyer_an, prix_vente, taxe_fonciere]
$BIENS = [
    ['SIR-001', 'GROUPE SIR',  'BOURG-EN-BRESSE',  '01000', "PARC CENORD - 101 RUE RADIO",            'Local d\'activité',    'CDO SAINT-GOBAIN',  1001, 12, 98000,  1635000, 5863],
    ['SIR-002', 'GROUPE SIR',  'DAVEZIEUX',        '07430', "RUE DU BOSQUET DES CHENES",              'Local d\'activité',    'ex CEP',             361,  9, 45000,   645000, 2797],
    ['SIR-003', 'GROUPE SIR',  'BOURGES',          '18000', "25 BIS BLV DE LA REPUBLIQUE",            'Local d\'activité',    'ex CEP',             539,  5, 54000,   690000, 9650],
    ['SIR-004', 'GROUPE SIR',  'CHENOVE',          '21300', "5 RUE A. BECQUEREL",                     'Local d\'activité',    'MAESTRIA',           586,  8, 64296,  1075000, 5057],
    ['SIR-005', 'GROUPE SIR',  'CHATEAUROUX',      '36000', "112 RUE MONTAIGNE",                      'Local d\'activité',    'SUPERMARCHE MP2E',   694, 20, 60000,   800000, 7147],
    ['SIR-006', 'GROUPE SIR',  'GRENOBLE',         '38100', "9 RUE P. VERLAINE",                      'Local d\'activité',    'KAYRY MARKET',       744, 10, 79200,  1270000,14385],
    ['SIR-007', 'GROUPE SIR',  'ECHIROLLES',       '38130', "2 RUE DU 19 MARS 1962",                  'Local d\'activité',    'ex CEP',             534,  8, 46800,   690000, 8990],
    ['SIR-008', 'GROUPE SIR',  'BOURGOIN-JALLIEU', '38300', "1 RUE L. BRAILLE",                       'Local d\'activité',    'UNIKALO',            639, 31, 63360,  1090000, 5360],
    ['SIR-009', 'GROUPE SIR',  'VOIRON',           '38500', "9001 RUE J.-M. JACQUARD",                'Local d\'activité',    'MAESTRIA',           414,  8, 52000,   725000, 3505],
    ['SIR-010', 'GROUPE SIR',  'ESTRABLIN',        '38780', "Z.A. DU ROCHER",                         'Local d\'activité',    'UNIKALO',            990,  9, 89928,  1500000, 4958],
    ['SIR-011', 'GROUPE SIR',  'SAINT GENEST LERPT','42530',"ZAC DU TISSOT",                          'Local d\'activité',    'AURA BOISSONS',     1979, 24,147000,  2450000,12129],
    ['SIR-012', 'GROUPE SIR',  'LYON',             '69007', "15 BLV Y. FARGE",                        'Local d\'activité',    'UNIKALO',            636,  4, 86870,  1565000, 8209],
    ['SIR-013A','GROUPE SIR',  'VILLEURBANNE',     '69100', "76 RUE DE VERDUN",                       'Local d\'activité',    'MAIRIE (ASSOCIATION)',1130,27,100200, 1670000,12250],
    ['SIR-013B','GROUPE SIR',  'VILLEURBANNE',     '69100', "76 RUE DE VERDUN",                       'Local d\'activité',    'L2E & GPE SIR',      148,  7, 26200,        0,    0],
    ['SIR-014A','GROUPE SIR',  'VILLEFRANCHE S/S', '69400', "594 BLD. A. CAMUS",                      'Local d\'activité',    'MAESTRIA',           616, 16, 97920,  1632000, 8022],
    ['SIR-014B','GROUPE SIR',  'VILLEFRANCHE S/S', '69400', "594 BLD. A. CAMUS",                      'Local d\'activité',    'MAESTRIA',           104,  3,     0,   240000, 5128],
    ['SIR-015', 'GROUPE SIR',  'IRIGNY',           '69540', "2 RUE D'YVOURS",                         'Local d\'activité',    'ARAMIS AUTO',       1013, 42,120000,  2000000, 9364],
    ['SIR-016', 'GROUPE SIR',  'CRISSEY',          '71530', "2 RUE A. LAMARTINE",                     'Local d\'activité',    'ex CEP',            1959, 14,118000,  1475000,11586],
    ['SIR-017', 'GROUPE SIR',  'VILLARGONDRAN',    '73300', "122 RUE DE L'ARTISAN",                   'Local d\'activité',    'ex CEP',             481,  7, 67480,  1120000,    0],
    ['SIR-018', 'GROUPE SIR',  'LA RAVOIRE',       '73490', "635 RUE P.ET M. CURIE",                  'Local d\'activité',    'ex CEP',             732, 22, 80740,  1345000, 4061],
    ['SIR-019', 'GROUPE SIR',  'SEYNOD',           '74600', "61 RUE DU VAL VERT",                     'Local d\'activité',    'TRIUMPH MOTOS',     1045, 16, 94200,  1371000, 8750],
    ['SIR-020', 'SIRES',       'LYON',             '69004', "1 PLACE GODIEN",                         'Local commercial',     'PLANET ECLAIRAGE',   129,  0, 24113,   395000, 1195],
    ['SIR-021', 'SIRES',       'OULLINS',          '69160', "30 BLD EMILE ZOLA",                      'Bureaux',              'RECTORAT LYON - CIO',686, 55, 55884,   830000, 8279],
    ['SIR-022', 'SIRES',       'LYON',             '69007', "124 RUE MONTESQUIEU",                    'Local commercial',     '',                   141,  0, 20000,   320000, 1265],
    ['SIR-023', 'GROUPE SIR',  'LYON',             '69002', "66 RUE VICTOR HUGO",                     'Local commercial',     'ECOUTER VOIR',        90,  0, 31867,   535000, 1850],
    ['SIR-024', 'SIRES',       'LYON',             '69002', "33 RUE DE BREST",                        'Bureaux',              '3BA France',          62,  0, 19155,   330000,    0],
    ['SIR-025', 'SIRES',       'LYON',             '69002', "33 RUE DE BREST",                        'Bureaux',              'CARRE BLANC',        132,  0, 29564,   580000, 4850],
    ['SIR-026', 'SIRES',       'LYON',             '69005', "51 RUE DU POINT DU JOUR",                'Bureaux',              'NEXITY',             231,  0, 40980,   740000, 6036],
    ['SIR-027', 'SIRES',       'LYON',             '69007', "57 RUE DE LA THIBAUDIERE",               'Local commercial',     'FH MADELEINE',        85,  0, 14359,   235000, 1246],
    ['SIR-028', 'GROUPE SIR',  'LYON',             '69008', "170 CHALLEMEL LACOUR",                   'Immeuble',             '',                   975,  0,133016,  3100000,11150],
    ['SIR-029', 'GROUPE SIR',  'ST PRIEST',        '69800', "40 RUE MARECHAL",                        'Bureaux',              'REGION RHONE',       463,  3, 96000,  1600000, 7862],
    ['SIR-030', 'GROUPE SIR',  'ECULLY',           '69130', "122 RUE MARIETTON",                      'Local commercial',     'LAAD',               122,  0, 17400,   240000,    0],
    ['SIR-031', 'GROUPE SIR',  'ECULLY',           '69130', "122 RUE MARIETTON",                      'Local commercial',     'ARICI',              205,  0, 54000,   900000,    0],
    ['SIR-032', 'GROUPE SIR',  'LYON',             '69007', "7 RUE DU BEGUIN",                        'Parking',              'A LOUER',              0,  0,     0,    30000,  215],
    ['SIR-033', 'GROUPE SIR',  'VILLEFRANCHE S/S', '69400', "573 RUE D'ANSE",                         'Local commercial',     'VIDE IMMEUBLE NEUF', 275,  3,     0,   680000,    0],
    ['SIR-034', 'GROUPE SIR',  'BRIGNAIS',         '69530', "LIEU DIT LE MONINSABLE",                 'Local commercial',     'PPG PEINTURE',       749,  0,110902,  1750000, 4640],
    ['SIR-035', 'GROUPE SIR',  'ST YVOINE',        '63500', "CHEMIN DU TREZINS",                      'Local commercial',     'A LOUER',           2331,  0,     0,   860000,13191],
    ['SIR-036', 'GROUPE SIR',  'LYON',             '69007', "77 AVENUE BERTHELOT",                    'Local commercial',     'SP PIZZA',            84,  0, 18000,   330000,    0],
    ['SIR-037', 'GROUPE SIR',  'LYON',             '69007', "77 AVENUE BERTHELOT",                    'Local commercial',     'KL HABILLEMENT',      70,  0, 14274,   260000,    0],
    ['SIR-038', 'GROUPE SIR',  'LYON',             '69007', "79 AVENUE BERTHELOT",                    'Local commercial',     'TC FOOD',             35,  0,  8639,   155000,    0],
    ['SIR-039', 'GROUPE SIR',  'LYON',             '69007', "79 AVENUE BERTHELOT",                    'Local commercial',     'BEST PHONE',          40,  0,  6827,   130000,    0],
    ['SIR-040', 'GROUPE SIR',  'LYON',             '69007', "83 AVENUE BERTHELOT",                    'Local commercial',     'CITY LAVERIE',        42,  0,  7476,   150000,    0],
    ['SIR-041', 'GROUPE SIR',  'LYON',             '69007', "83 AVENUE BERTHELOT",                    'Local commercial',     'EXOTIC CENTER',       42,  0,  7444,   150000,    0],
    ['SIR-042', 'GROUPE SIR',  'LYON',             '69007', "85 AVENUE BERTHELOT",                    'Local commercial',     'LPES MEDICAL CENTER', 90,  0, 10679,   210000,    0],
    ['SIR-043', 'GROUPE SIR',  'VILLEURBANNE',     '69100', "34 RUE DE VERDUN",                       'Tènement immobilier',  '',                  4864,  2,     0,   400000,21450],
    ['SIR-044', 'SCI ELYSEE 1','LILLE',            '59000', "15 AVENUE KENNEDY",                      'Local commercial',     'CRECHE DU NORD',     139,  0, 17081,   295000, 4480],
    ['SIR-045', 'SCI ELYSEE 2','BRUNOY',           '91800', "11-13 RUE DE LA REPUBLIQUE",             'Local commercial',     'DENTIGEST',          113,  0, 35427,   550000, 6390],
    ['SIR-046', 'OPERA',       'NEUVILLE',         '69250', "7 PLACE AMPERE",                         'Immeuble complet',     '',                   215,  0,     0,   470000, 1680],
    ['SIR-047', 'GROUPE SIR',  'NEUVILLE',         '69250', "13 RUE LOUIS BLANC",                     'Immeuble complet',     '',                   175,  0,     0,   490000, 4299],
    ['SIR-048', 'OPERA',       'LYON',             '69003', "20 BLV EUGENE DERUELLE",                 'Bureaux',              'BARBANEL MOE',       202,  4, 27200,   520000, 5374],
    ['SIR-049', 'HIMMALAYA',   'CAUDAN',           '56850', "215 RUE JOSEPH BIGOT",                   'Local commercial',     'AKZO NOBEL',        1410,  0, 97202,  1350000, 5419],
    ['SIR-050', 'CB FINANCES', 'VENISSIEUX',       '69200', "MOULIN A VENT",                          'Immeuble de bureaux',  'A LOUER',           1723,  0,     0,  1950000,28200],
    ['SIR-051', 'SIRES',       'LYON',             '69003', "3 COURS DR LONG",                        'Appartement T2',       '',                    40,  0,  7742,   155000,  680],
    ['SIR-052', 'GROUPE SIR',  'LYON',             '69008', "238 ROUTE DE VIENNE",                    'Local commercial',     'LE GRAIN LA MEULE',  152,  0, 21417,   365000, 7288],
    ['SIR-053', 'SIRES',       'LYON',             '69008', "238 ROUTE DE VIENNE",                    'Appartements (7 lots)','SYNDIC loués',       385,  0, 59400,  1350000,11010],
    ['SIR-054', 'SIRES',       'LYON',             '69004', "21 RUE DE NUITS",                        'Local commercial',     'GROUP MONSAL',        41,  0,  7315,   125000,  550],
    ['SIR-055', 'HIMMALAYA',   'LA TRONCHE',       '38700', "1 BLV DE LA CHANTOURNE",                 'Local d\'activité',    'AKZO NOBEL',        2106,  0, 73900,  1320000,20224],
    ['SIR-056', 'OPERA',       'ECULLY',           '69130', "7 RUE JULIETTE RECAMIER",                'Maison',               '',                   119,  0,     0,   700000,    0],
];

$run = isset($_GET['run']) && $_GET['run'] === '1';
$stats = ['proprio_created'=>0, 'proprio_existing'=>0, 'bien_created'=>0, 'bien_skipped'=>0, 'errors'=>[]];

if ($run) {
    // 1. Récupère / crée les propriétaires uniques
    $proprioMap = []; // nom => id

    // Cherche colonnes disponibles dans `proprietaires` (defensif)
    $cols = [];
    try {
        $st = $pdo->query('SHOW COLUMNS FROM proprietaires');
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $cols[$r['Field']] = true;
    } catch (Throwable $e) {}

    $uniqueProprios = array_unique(array_column($BIENS, 1));
    foreach ($uniqueProprios as $nomProprio) {
        // Lookup
        $st = $pdo->prepare('SELECT id FROM proprietaires WHERE societe = ? OR nom = ? LIMIT 1');
        $st->execute([$nomProprio, $nomProprio]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $proprioMap[$nomProprio] = (int)$existing;
            $stats['proprio_existing']++;
            continue;
        }
        // Insert
        $fields = []; $values = []; $params = [];
        if (isset($cols['societe']))         { $fields[]='societe';         $values[]=':soc';   $params[':soc'] = $nomProprio; }
        if (isset($cols['nom']))             { $fields[]='nom';             $values[]=':nom';   $params[':nom'] = $nomProprio; }
        if (isset($cols['type_personne']))   { $fields[]='type_personne';   $values[]=':tp';    $params[':tp']  = 'societe'; }
        if (isset($cols['actif']))           { $fields[]='actif';           $values[]=':a';     $params[':a']   = 1; }
        if (isset($cols['date_creation']))   { $fields[]='date_creation';   $values[]='NOW()'; }
        try {
            $sql = 'INSERT INTO proprietaires (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')';
            $st = $pdo->prepare($sql);
            foreach ($params as $k=>$v) $st->bindValue($k, $v);
            $st->execute();
            $proprioMap[$nomProprio] = (int)$pdo->lastInsertId();
            $stats['proprio_created']++;
        } catch (Throwable $e) {
            $stats['errors'][] = 'Propriétaire ' . $nomProprio . ' : ' . $e->getMessage();
        }
    }

    // 2. Crée les biens
    $colsBien = [];
    try {
        $st = $pdo->query('SHOW COLUMNS FROM biens');
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $colsBien[$r['Field']] = true;
    } catch (Throwable $e) {}

    // id_type_bien requis : tente de récupérer un type "Local commercial" / "Bureau" / NULL
    $defaultTypeBienId = null;
    try {
        $tid = $pdo->query("SELECT id FROM types_bien ORDER BY id LIMIT 1")->fetchColumn();
        if ($tid) $defaultTypeBienId = (int)$tid;
    } catch (Throwable $e) {}

    foreach ($BIENS as $b) {
        [$ref, $proprio, $ville, $cp, $adresse, $typeLocal, $locataire, $surface, $parkings, $loyerAn, $prixVente, $taxe] = $b;
        // Skip si ref existe déjà
        $st = $pdo->prepare('SELECT id FROM biens WHERE reference_bien = ? LIMIT 1');
        $st->execute([$ref]);
        if ($st->fetchColumn()) { $stats['bien_skipped']++; continue; }

        $designation = trim($typeLocal . ($locataire ? ' — ' . $locataire : ''));
        $idProprio   = $proprioMap[$proprio] ?? null;

        $fields = ['reference_bien' => $ref];
        if (isset($colsBien['id_proprietaire']) && $idProprio)        $fields['id_proprietaire'] = $idProprio;
        if (isset($colsBien['id_type_bien']) && $defaultTypeBienId)   $fields['id_type_bien'] = $defaultTypeBienId;
        if (isset($colsBien['designation']))            $fields['designation'] = $designation;
        if (isset($colsBien['adresse_1']))              $fields['adresse_1'] = $adresse;
        if (isset($colsBien['code_postal']))            $fields['code_postal'] = $cp;
        if (isset($colsBien['ville']))                  $fields['ville'] = $ville;
        if (isset($colsBien['statut_bien']))            $fields['statut_bien'] = 'actif';
        // type_commercialisation : NON écrit en direct — mission canonique = mandat vente
        // créé après l'INSERT, puis miroir dérivé (cf. ensure_mandat_vente + derive ci-dessous).
        if (isset($colsBien['usage_bien']))             $fields['usage_bien'] = 'professionnel';
        if (isset($colsBien['surface_habitable']) && $surface > 0)  $fields['surface_habitable'] = $surface;
        if (isset($colsBien['surface_totale']) && $surface > 0)     $fields['surface_totale'] = $surface;
        if (isset($colsBien['parking_nb']) && $parkings > 0)        $fields['parking_nb'] = $parkings;
        if (isset($colsBien['loyer_hc']) && $loyerAn > 0)           $fields['loyer_hc'] = round($loyerAn / 12, 2);
        if (isset($colsBien['prix_vente_estime']) && $prixVente > 0) $fields['prix_vente_estime'] = $prixVente;
        if (isset($colsBien['prix_demande_initial']) && $prixVente > 0) $fields['prix_demande_initial'] = $prixVente;
        if (isset($colsBien['priorite_vente']))         $fields['priorite_vente'] = 'normale';
        if (isset($colsBien['date_mise_en_vente']))     $fields['date_mise_en_vente'] = date('Y-m-d');
        if (isset($colsBien['rendement_brut']) && $loyerAn > 0 && $prixVente > 0) {
            $fields['rendement_brut'] = round(($loyerAn / $prixVente) * 100, 2);
        }
        if (isset($colsBien['commentaire']) && $taxe > 0) {
            $fields['commentaire'] = 'Taxe foncière annuelle : ' . number_format($taxe, 0, ',', ' ') . ' €';
        }

        try {
            $cols2 = array_keys($fields);
            $vals = array_map(fn($c) => ':' . $c, $cols2);
            $sql = 'INSERT INTO biens (' . implode(',', $cols2) . ') VALUES (' . implode(',', $vals) . ')';
            $st = $pdo->prepare($sql);
            foreach ($fields as $k=>$v) $st->bindValue(':' . $k, $v);
            $st->execute();
            $newBienId = (int)$pdo->lastInsertId();
            // Mission canonique : mandat vente (projet) + miroir type_commercialisation dérivé.
            ensure_mandat_vente($pdo, $newBienId, ['id_proprietaire' => $idProprio]);
            derive_type_commercialisation($pdo, $newBienId);
            $stats['bien_created']++;
        } catch (Throwable $e) {
            $stats['errors'][] = 'Bien ' . $ref . ' : ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Import portefeuille SIR</title>
<style>
body { font-family: Sora, sans-serif; padding: 30px; max-width: 1100px; background: #f7f4ef; color: #2c2a28; }
h1 { font-size: 22px; }
.card { background: #fff; border-radius: 12px; padding: 22px; margin-bottom: 18px; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.btn { display:inline-block; background:#4878a6; color:#fff; padding:11px 22px; border-radius:8px; text-decoration:none; font-weight:700; font-size:14px; }
.btn:hover { background:#3a6890; }
.stat { background:#f4f1ec; border-radius:8px; padding:12px 16px; margin:6px 0; }
.stat strong { font-size:22px; color:#2c5687; display:block; }
.err { color:#a8323b; }
table { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
td, th { padding:5px 8px; border-bottom:1px solid #f0ece6; text-align:left; }
th { background:#f4f1ec; }
</style></head><body>

<h1>📥 Import portefeuille GROUPE SIR — <?= count($BIENS) ?> biens</h1>

<?php if (!$run): ?>
    <div class="card">
        <p>Cette page importe en base le portefeuille de la liste officielle SIR (PDF du 18/05/2026).</p>
        <ul>
            <li><strong><?= count($BIENS) ?> biens</strong> à importer (GROUPE SIR, SIRES, SCI ELYSEE 1/2, OPERA, HIMMALAYA, CB FINANCES)</li>
            <li>Création automatique des <strong>propriétaires</strong> manquants (en tant que sociétés)</li>
            <li>Tous les biens : <code>type_commercialisation='vente'</code>, <code>priorite_vente='normale'</code></li>
            <li>Rendement brut calculé automatiquement (loyer annuel / prix vente)</li>
            <li><strong>Idempotent</strong> : un bien dont la <code>reference_bien</code> existe déjà est ignoré.</li>
            <li><strong>id_societe / id_agence laissés NULL</strong> → biens visibles toutes sociétés / toutes agences confondues.</li>
        </ul>
        <p>
            <a href="?run=1" class="btn">▶️ Lancer l'import maintenant</a>
            <a href="<?= htmlspecialchars(app_url('/transaction_index.php')) ?>" style="margin-left:14px;">← Retour Transactions</a>
        </p>

        <h3>Aperçu (<?= count($BIENS) ?> lignes)</h3>
        <div style="max-height:400px; overflow:auto;">
            <table>
                <thead><tr><th>Réf</th><th>Propriétaire</th><th>Ville</th><th>Adresse</th><th>Type</th><th>Locataire</th><th>Surf.</th><th>Loyer/an</th><th>Prix</th></tr></thead>
                <tbody>
                <?php foreach ($BIENS as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars($b[0]) ?></td>
                        <td><?= htmlspecialchars($b[1]) ?></td>
                        <td><?= htmlspecialchars($b[2]) ?></td>
                        <td><?= htmlspecialchars($b[4]) ?></td>
                        <td><?= htmlspecialchars($b[5]) ?></td>
                        <td><?= htmlspecialchars($b[6]) ?></td>
                        <td><?= (int)$b[7] ?> m²</td>
                        <td><?= number_format((float)$b[9], 0, ',', ' ') ?> €</td>
                        <td><?= number_format((float)$b[10], 0, ',', ' ') ?> €</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <h2>✅ Import terminé</h2>
        <div class="stat"><strong><?= (int)$stats['bien_created'] ?></strong> bien(s) créé(s)</div>
        <div class="stat"><strong><?= (int)$stats['bien_skipped'] ?></strong> bien(s) ignoré(s) (déjà en base)</div>
        <div class="stat"><strong><?= (int)$stats['proprio_created'] ?></strong> propriétaire(s) créé(s)</div>
        <div class="stat"><strong><?= (int)$stats['proprio_existing'] ?></strong> propriétaire(s) déjà existants</div>
        <?php if (!empty($stats['errors'])): ?>
            <h3 class="err">⚠️ Erreurs (<?= count($stats['errors']) ?>)</h3>
            <ul class="err">
                <?php foreach ($stats['errors'] as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p style="margin-top:18px;">
            <a href="<?= htmlspecialchars(app_url('/transaction_index.php')) ?>" class="btn">🎯 Ouvrir le tableau Transactions</a>
        </p>
    </div>
<?php endif; ?>

</body></html>
