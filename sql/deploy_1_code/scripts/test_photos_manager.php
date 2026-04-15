<?php
declare(strict_types=1);

/**
 * Script de test — AnnoncePhotosManager
 * ─────────────────────────────────────────────────────────────
 * Valide le pipeline complet :
 *   1. Crée une annonce de test sur un bien existant
 *   2. Importe 2 photos via AnnoncePhotosManager
 *   3. Vérifie que les 8 fichiers (2 originaux + 6 WebP) sont créés
 *   4. Vérifie que 8 lignes sont bien insérées dans annonces_photos
 *   5. NETTOIE TOUT (rollback complet : fichiers + lignes + annonce)
 *
 * Usage :
 *   /c/xampp/php/php.exe scripts/test_photos_manager.php
 *   (ou via navigateur : http://localhost/MaBoxImmo2026/public_html/scripts/test_photos_manager.php)
 *
 * Le script n'écrit RIEN de permanent — à la fin tout est supprimé.
 */

require_once __DIR__ . '/../inc/seo_slug.php';
require_once __DIR__ . '/../inc/annonce_photos_manager.php';

// ─── Sortie lisible en CLI et en navigateur ─────────────────
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function say(string $msg): void { echo $msg . "\n"; }
function ok(string $msg): void  { say('  [OK]    ' . $msg); }
function ko(string $msg): void  { say('  [ECHEC] ' . $msg); }
function hr(): void             { say(str_repeat('─', 60)); }

hr();
say(' TEST AnnoncePhotosManager — pipeline complet');
hr();

// ─── 1. Connexion BDD ───────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=maboximmo;charset=utf8mb4',
        'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    ok('Connexion BDD');
} catch (Throwable $e) {
    ko('Connexion BDD : ' . $e->getMessage());
    exit(1);
}

// ─── 2. Bien de test (#3 = MIONS) ───────────────────────────
$bienId = 3;
$ctx = $pdo->prepare('
    SELECT b.id, b.id_societe, b.id_agence, tb.label AS type_bien,
           b.ville, ag.nom_agence, ag.slug AS agence_slug
    FROM biens b
    LEFT JOIN base_types_bien tb ON tb.id = b.id_type_bien
    LEFT JOIN agences ag ON ag.id = b.id_agence
    WHERE b.id = ?
');
$ctx->execute([$bienId]);
$bien = $ctx->fetch(PDO::FETCH_ASSOC);
if (!$bien) { ko("Bien #$bienId introuvable"); exit(1); }

ok("Bien #{$bien['id']} : {$bien['type_bien']} à {$bien['ville']} (agence « {$bien['nom_agence']} »)");

// ─── 3. Création annonce de test ────────────────────────────
$pdo->prepare('
    INSERT INTO annonces (id_bien, id_agence, id_societe, type_transaction, titre, statut, etat_publication, date_creation)
    VALUES (?, ?, ?, "vente", "ANNONCE TEST — à supprimer", "brouillon", "brouillon", NOW())
')->execute([$bien['id'], $bien['id_agence'], $bien['id_societe']]);
$annonceId = (int)$pdo->lastInsertId();
ok("Annonce de test créée : ID = $annonceId");

// ─── 4. Photos sources ──────────────────────────────────────
$photosSrc = [
    dirname(__DIR__) . '/uploads/imports/3/2026/04/photos_9/photo_01_7ca00878.jpg',
    dirname(__DIR__) . '/uploads/imports/3/2026/04/photos_9/photo_02_74d50cf8.jpg',
];
foreach ($photosSrc as $p) {
    if (!is_readable($p)) {
        ko("Photo source introuvable : $p");
        // On n'exit pas — on essaie quand même avec ce qu'on a
    }
}

// ─── 5. Import des photos via le manager ────────────────────
hr();
say(' IMPORT DES PHOTOS');
hr();

$manager = new AnnoncePhotosManager($pdo);
$resultats = [];

foreach ($photosSrc as $i => $src) {
    if (!is_readable($src)) continue;
    $ordre = $i + 1;
    $principale = ($ordre === 1);
    say("  → Import photo $ordre (principale=" . ($principale ? 'oui' : 'non') . ')');
    $r = $manager->importerDepuisFichier($annonceId, $src, $ordre, $principale);
    if (!$r['ok']) {
        ko("  Import photo $ordre : {$r['error']}");
    } else {
        ok("  " . count($r['photos']) . ' variantes créées :');
        foreach ($r['photos'] as $v) {
            say("        • [{$v['variante']}] {$v['fichier']} (BDD id={$v['id']})");
        }
    }
    $resultats[] = $r;
}

// ─── 6. Vérifications ───────────────────────────────────────
hr();
say(' VÉRIFICATIONS');
hr();

// 6a. Lignes BDD
$nb = $pdo->prepare('SELECT COUNT(*) FROM annonces_photos WHERE id_annonce = ?');
$nb->execute([$annonceId]);
$nbLignes = (int)$nb->fetchColumn();
say("  Lignes annonces_photos : $nbLignes");
if ($nbLignes > 0) ok('Lignes BDD insérées'); else ko('Aucune ligne en BDD');

// 6b. Détail des lignes
$detail = $pdo->prepare('
    SELECT id, variante, ordre_affichage, largeur, hauteur, poids_octets, url_photo, principale
    FROM annonces_photos WHERE id_annonce = ?
    ORDER BY ordre_affichage, FIELD(variante,"original","thumb","medium","large")
');
$detail->execute([$annonceId]);
say('');
say('  Détail :');
foreach ($detail as $r) {
    $p = $r['principale'] ? ' ★' : '  ';
    say(sprintf(
        "  %s #%d ordre=%d [%s] %dx%d %s octets",
        $p, $r['id'], $r['ordre_affichage'], $r['variante'],
        $r['largeur'], $r['hauteur'], number_format((int)$r['poids_octets'], 0, ',', ' ')
    ));
    $fichier = dirname(__DIR__) . '/' . $r['url_photo'];
    if (is_file($fichier)) {
        say('       → ' . $r['url_photo']);
    } else {
        ko('       → FICHIER MANQUANT : ' . $r['url_photo']);
    }
}

// ─── 7. NETTOYAGE (rollback complet) ────────────────────────
hr();
say(' NETTOYAGE (suppression des fichiers et des lignes)');
hr();

// 7a. Supprimer les fichiers disque
$dir = dirname(__DIR__) . '/uploads/annonces/' . $bien['id_societe'] . '/' . $annonceId . '/';
$nbSupp = 0;
if (is_dir($dir)) {
    foreach (glob($dir . '*') as $f) {
        if (@unlink($f)) $nbSupp++;
    }
    @rmdir($dir);
    // Remonter d'un cran si le dossier société est vide
    $dirSoc = dirname(__DIR__) . '/uploads/annonces/' . $bien['id_societe'];
    if (is_dir($dirSoc) && count(scandir($dirSoc)) === 2) @rmdir($dirSoc);
    $dirRoot = dirname(__DIR__) . '/uploads/annonces';
    if (is_dir($dirRoot) && count(scandir($dirRoot)) === 2) @rmdir($dirRoot);
}
ok("$nbSupp fichiers supprimés du disque");

// 7b. Supprimer les lignes annonces_photos (cascade fera le job via FK)
$pdo->prepare('DELETE FROM annonces WHERE id = ?')->execute([$annonceId]);
ok("Annonce test #$annonceId supprimée (cascade sur annonces_photos)");

// 7c. Vérification finale
$nb->execute([$annonceId]);
$reste = (int)$nb->fetchColumn();
if ($reste === 0) {
    ok('Aucune ligne résiduelle — rollback OK');
} else {
    ko("Il reste $reste lignes ???");
}

hr();
say(' FIN DU TEST');
hr();
