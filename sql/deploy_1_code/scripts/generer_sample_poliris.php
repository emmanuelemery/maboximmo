<?php
declare(strict_types=1);

/**
 * Génère un fichier XML d'échantillon au format Poliris/IRIS
 * pour validation technique auprès de Leboncoin (ou tout autre portail
 * acceptant ce format standard).
 *
 * ATTENTION : ce fichier est un ÉCHANTILLON basé sur le format Poliris
 * standard. Avant envoi, vérifier auprès du support partenaire Leboncoin :
 *   - la version exacte du format attendu (Poliris 4.x ? Leboncoin Pro ?)
 *   - l'encodage (ISO-8859-1 historique / UTF-8 récent)
 *   - les codes numériques exacts des types de bien / transaction
 *   - le nommage et la livraison des photos (ZIP ? FTP ? URL ?)
 *
 * Usage :
 *   /c/xampp/php/php.exe scripts/generer_sample_poliris.php
 * Sortie :
 *   public_html/api/flux/sample_poliris.xml
 */

// ─── Connexion BDD ──────────────────────────────────────────
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=maboximmo;charset=utf8mb4',
    'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ─── Mapping types de bien Poliris ──────────────────────────
// Codes historiques Poliris (1=maison, 2=appart, 3=terrain, 4=local, …)
// À vérifier auprès de Leboncoin selon leur référentiel.
$MAP_TYPE_BIEN_POLIRIS = [
    'appartement'      => 2,
    'maison'           => 1,
    'villa'            => 1,
    'terrain'          => 3,
    'local_commercial' => 4,
    'bureau'           => 5,
    'parking'          => 6,
    'garage'           => 6,
    'immeuble'         => 7,
    'loft'             => 2,
    'chateau'          => 1,
];

// Codes action Poliris : 1=vente, 2=location, 3=viager, 4=location saisonnière
$MAP_ACTION = [
    'vente'                 => 1,
    'location'              => 2,
    'viager'                => 3,
    'location_saisonniere'  => 4,
];

// ─── Biens à inclure dans l'échantillon ─────────────────────
// On prend les 2 premiers biens complets (avec ville, agence, type)
$sql = "
    SELECT b.*, tb.code AS type_code, tb.label AS type_label,
           ag.nom_agence, ag.slug AS agence_slug, ag.adresse_1 AS ag_adresse,
           ag.code_postal AS ag_cp, ag.ville AS ag_ville,
           ag.telephone AS ag_tel, ag.email AS ag_email,
           ag.siret AS ag_siret
    FROM biens b
    LEFT JOIN base_types_bien tb ON tb.id = b.id_type_bien
    LEFT JOIN agences ag ON ag.id = b.id_agence
    WHERE b.ville IS NOT NULL AND b.ville <> ''
      AND b.id_agence IS NOT NULL
      AND b.id_type_bien IS NOT NULL
    LIMIT 2
";
$biens = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (!$biens) {
    die("Aucun bien éligible trouvé en base.\n");
}

// ─── Construction XML ───────────────────────────────────────
$xml = new DOMDocument('1.0', 'ISO-8859-1');
$xml->formatOutput = true;

$root = $xml->createElement('annonces');
$root->setAttribute('version', '4.05');
$root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
$root->setAttribute('date_generation', date('c'));
$xml->appendChild($root);

// Helper : crée un nœud avec texte (auto-encode accents en ISO-8859-1)
$node = function (DOMDocument $doc, string $name, $value) {
    $n = $doc->createElement($name);
    $txt = (string)($value ?? '');
    // Le contenu interne du DOMDocument est en UTF-8 ; l'encodage final
    // est géré par saveXML() selon la déclaration ISO-8859-1.
    $n->appendChild($doc->createTextNode($txt));
    return $n;
};

foreach ($biens as $b) {
    $annonce = $xml->createElement('annonce');

    // ═══ AGENCE ═══
    $agence = $xml->createElement('AGENCE');
    $agence->appendChild($node($xml, 'NOM',       $b['nom_agence'] ?: 'MaBoxImmo'));
    $agence->appendChild($node($xml, 'SIRET',     $b['ag_siret'] ?? ''));
    $agence->appendChild($node($xml, 'ADRESSE',   $b['ag_adresse'] ?? ''));
    $agence->appendChild($node($xml, 'CP',        $b['ag_cp'] ?? ''));
    $agence->appendChild($node($xml, 'VILLE',     $b['ag_ville'] ?? ''));
    $agence->appendChild($node($xml, 'TEL',       $b['ag_tel'] ?? ''));
    $agence->appendChild($node($xml, 'EMAIL',     $b['ag_email'] ?? ''));
    $annonce->appendChild($agence);

    // ═══ MANDAT ═══
    $mandat = $xml->createElement('MANDAT');
    $mandat->appendChild($node($xml, 'NUMERO', 'MD-' . str_pad((string)$b['id'], 6, '0', STR_PAD_LEFT)));
    $mandat->appendChild($node($xml, 'ACTION',
        $MAP_ACTION[strtolower((string)$b['type_commercialisation'] ?? 'vente')] ?? 1
    ));
    $mandat->appendChild($node($xml, 'DATE_DEBUT', date('Y-m-d', strtotime((string)$b['date_creation']))));
    $annonce->appendChild($mandat);

    // ═══ BIEN ═══
    $bien = $xml->createElement('BIEN');
    $bien->appendChild($node($xml, 'REFERENCE',     $b['reference_bien'] ?: ('REF-' . $b['id'])));
    $bien->appendChild($node($xml, 'TYPE',
        $MAP_TYPE_BIEN_POLIRIS[strtolower((string)$b['type_code'])] ?? 99
    ));
    $bien->appendChild($node($xml, 'TYPE_LIBELLE',  $b['type_label'] ?? ''));
    $bien->appendChild($node($xml, 'TITRE',
        ($b['type_label'] ?? 'Bien') . ' à ' . ($b['ville'] ?? '')
    ));
    $bien->appendChild($node($xml, 'SURFACE',       (string)($b['surface_habitable'] ?? '')));
    $bien->appendChild($node($xml, 'SURFACE_TERRAIN', (string)($b['surface_terrain'] ?? '')));
    $bien->appendChild($node($xml, 'NB_PIECES',     (string)($b['nb_pieces'] ?? '')));
    $bien->appendChild($node($xml, 'NB_CHAMBRES',   (string)($b['nb_chambres'] ?? '')));
    $bien->appendChild($node($xml, 'ETAGE',         (string)($b['etage'] ?? '')));
    $bien->appendChild($node($xml, 'ANNEE_CONSTRUCTION', (string)($b['annee_construction'] ?? '')));
    $bien->appendChild($node($xml, 'DPE',           (string)($b['dpe_classe'] ?? '')));
    $bien->appendChild($node($xml, 'GES',           (string)($b['ges_classe'] ?? '')));
    $bien->appendChild($node($xml, 'DPE_VALEUR',    (string)($b['dpe_valeur'] ?? '')));
    $bien->appendChild($node($xml, 'GES_VALEUR',    (string)($b['ges_valeur'] ?? '')));
    $bien->appendChild($node($xml, 'DESCRIPTIF',
        $b['commentaire'] ?: 'Description à compléter — échantillon technique.'
    ));
    $annonce->appendChild($bien);

    // ═══ LIEU ═══
    $lieu = $xml->createElement('LIEU');
    $lieu->appendChild($node($xml, 'PAYS',      'FRA'));
    $lieu->appendChild($node($xml, 'CP',        $b['code_postal'] ?? ''));
    $lieu->appendChild($node($xml, 'VILLE',     $b['ville'] ?? ''));
    $lieu->appendChild($node($xml, 'LATITUDE',  (string)($b['latitude'] ?? '')));
    $lieu->appendChild($node($xml, 'LONGITUDE', (string)($b['longitude'] ?? '')));
    $annonce->appendChild($lieu);

    // ═══ PRIX ═══
    $prix = $xml->createElement('PRIX');
    // Pas d'annonce en base → prix fictif basé sur l'ID pour l'échantillon
    $prixFictif = 150000 + ((int)$b['id'] * 25000);
    $prix->appendChild($node($xml, 'VALEUR',    (string)$prixFictif));
    $prix->appendChild($node($xml, 'DEVISE',    'EUR'));
    $prix->appendChild($node($xml, 'HONORAIRES_INCLUS', 'oui'));
    $prix->appendChild($node($xml, 'HONORAIRES_CHARGE', 'acquereur'));
    $annonce->appendChild($prix);

    // ═══ PHOTOS ═══ (fictives pour l'échantillon)
    $photos = $xml->createElement('PHOTOS');
    for ($i = 1; $i <= 3; $i++) {
        $p = $xml->createElement('PHOTO');
        $p->appendChild($node($xml, 'ORDRE', (string)$i));
        $p->appendChild($node($xml, 'URL',
            'https://www.maboximmo.com/uploads/annonces/' . $b['id_societe'] . '/SAMPLE/'
            . strtolower((string)($b['type_code'] ?? 'bien'))
            . '-' . strtolower(str_replace(' ', '-', (string)($b['ville'] ?? 'ville')))
            . '-' . $b['id']
            . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '.jpg'
        ));
        $photos->appendChild($p);
    }
    $annonce->appendChild($photos);

    $root->appendChild($annonce);
}

// ─── Écriture fichier ───────────────────────────────────────
$outPath = dirname(__DIR__) . '/api/flux/sample_poliris.xml';
if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}

// DOMDocument gère l'encodage ISO-8859-1 automatiquement
$ok = $xml->save($outPath);

if ($ok === false) {
    die("Erreur écriture fichier $outPath\n");
}

echo "Fichier généré : $outPath\n";
echo "Taille : " . filesize($outPath) . " octets\n";
echo "Annonces incluses : " . count($biens) . "\n";
echo "\n--- Aperçu (50 premières lignes) ---\n";
$lines = file($outPath);
foreach (array_slice($lines, 0, 50) as $l) echo $l;
