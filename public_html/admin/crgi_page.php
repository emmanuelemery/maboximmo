<?php
declare(strict_types=1);
/**
 * INTÉGRATION CRG — OUVRIR LE PDF DÉPOSÉ À LA PAGE D'UN CRG DÉTECTÉ.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ UN DÉCOUPAGE NE SE CROIT PAS, IL SE VÉRIFIE. Sans cette route, le bilan de la phase 0
 *    serait une liste de lignes qu'il faudrait croire sur parole. Ici, chaque CRG détecté
 *    ouvre le document à sa page de début : le contrôle redevient possible en un clic.
 *
 * ⚠️ LE CHEMIN NE VIENT JAMAIS DE L'URL. Le navigateur envoie un identifiant de CRG ; le
 *    chemin est relu en base, et il doit appartenir à l'import. Accepter un chemin depuis
 *    l'URL ferait de cette page un lecteur de fichiers arbitraire du serveur.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin_or_super_admin();

// ⚠️ LA PREUVE DOIT ÊTRE CHERCHÉE DANS LA BASE QUI A PRODUIT L'IDENTIFIANT. Cet écran lisait
//    toujours la base par défaut ; les identifiants venant du bac à sable y étaient
//    introuvables, et le bouton « voir la preuve » répondait « CRG INTROUVABLE » sur un
//    document parfaitement présent. Un lien de preuve qui ne prouve rien est pire que pas de
//    lien : il fait douter du document au lieu de douter du lien.
//
// ⚠️ LA LISTE DES BASES EST FERMÉE, ET LE CHEMIN NE VIENT TOUJOURS PAS DE L'URL. On choisit
//    parmi deux noms écrits ici ; le chemin du fichier, lui, reste relu en base.
const CRGIPG_BASES = ['maboximmo', 'mbi_bis'];
$pdo = $GLOBALS['pdo'];
$base = (string)($_GET['base'] ?? '');
if ($base !== '' && $base !== 'maboximmo' && in_array($base, CRGIPG_BASES, true)) {
    $secret = 'C:/Users/emery/.mbi/mbi_agent.pass';
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $base . ';charset=utf8mb4',
            'mbi_agent', is_file($secret) ? trim((string)file_get_contents($secret)) : '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        $pdo = $GLOBALS['pdo'];
    }
}
$crgId = (int)($_GET['crg'] ?? 0);

$st = $pdo->prepare(
    'SELECT c.page_debut, c.page_fin, c.compte, c.proprietaire, p.chemin, p.nom_original
       FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
      WHERE c.id = ?'
);
$st->execute([$crgId]);
$crg = $st->fetch(PDO::FETCH_ASSOC);

if (!$crg || !is_file((string)$crg['chemin'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    // ⚠️ ON DIT CE QUI MANQUE. « Introuvable » sans le pourquoi enverrait chercher un défaut
    //    de droits là où le fichier de staging a simplement été supprimé par une annulation.
    exit($crg
        ? "PIÈCE ABSENTE DU STAGING — l'import a-t-il été annulé ?\n\n"
          . 'Fichier attendu : ' . $crg['chemin'] . "\n"
        : "CRG INTROUVABLE — identifiant " . $crgId . "\n");
}

// ⚠️ ON NE FAIT PAS TÉLÉCHARGER 616 Mo POUR LIRE UNE PAGE. Un dépôt trimestriel est UN seul
//    PDF de plusieurs centaines de pages ; servir le fichier entier pour vérifier une ligne
//    est une preuve qu'on renonce à consulter — donc pas une preuve. On n'extrait que les
//    pages DU compte rendu concerné, ce qui ramène le plus gros cas à quelques dizaines de
//    kilo-octets, et le numéro de page redevient relatif à cet extrait.
//
// ⚠️ ET SI L'EXTRACTION ÉCHOUE, ON SERT LE FICHIER ENTIER. Mieux vaut lent que muet : un
//    lien de preuve qui ne répond rien fait douter du document au lieu de douter du lien.
$chemin  = (string)$crg['chemin'];
$decalage = 0;
$exe = null;
foreach (['C:\\poppler\\Library\\bin\\pdftocairo.exe', '/usr/bin/pdftocairo',
          '/usr/local/bin/pdftocairo'] as $c) {
    if (@is_file($c)) { $exe = $c; break; }
}
if ($exe !== null && filesize($chemin) > 2 * 1024 * 1024) {
    $d = (int)$crg['page_debut'];
    $f = max($d, (int)$crg['page_fin']);
    $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'crgipg_' . bin2hex(random_bytes(6)) . '.pdf';
    @exec('"' . $exe . '" -pdf -f ' . $d . ' -l ' . $f . ' '
          . escapeshellarg($chemin) . ' ' . escapeshellarg($tmp)
          . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    if (is_file($tmp) && filesize($tmp) > 0) {
        $chemin = $tmp;
        $decalage = $d - 1;   // la page 93 du dépôt est la page 1 de l'extrait
        register_shutdown_function(static function () use ($tmp) { @unlink($tmp); });
    }
}

$affichage = 'CRG ' . ($crg['compte'] ?: '') . ' p.' . (int)$crg['page_debut'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($affichage) . '"');
header('Content-Length: ' . filesize($chemin));
header('X-CRGI-Decalage-Page: ' . $decalage);
// ⚠️ LE NUMÉRO DE PAGE VIT DANS LE FRAGMENT D'URL, que le lecteur PDF du navigateur
//    interprète. Il ne peut pas être posé ici : c'est la page appelante qui l'ajoute.
//
// ⚠️ ON DIFFUSE PAR TRANCHES, ON NE CHARGE PAS LE FICHIER EN MÉMOIRE. `readfile()` paraît
//    diffuser, mais avec `output_buffering` actif — 4096 par défaut sous XAMPP — le tampon
//    grossit jusqu'à contenir TOUT le document. Un dépôt trimestriel est un seul PDF de
//    plusieurs centaines de pages : celui de 587,6 Mo réclamait 616 198 144 octets et
//    heurtait la limite de 512 Mo. Le navigateur recevait alors l'erreur fatale à la place
//    du PDF et affichait « échec de chargement » — sur le SEUL dépôt dont le découpage
//    méritait le plus d'être vérifié. Les petits fichiers, eux, passaient : le défaut
//    grandissait avec le document, donc avec l'enjeu.
while (ob_get_level() > 0) {
    ob_end_clean();
}
$fh = fopen($chemin, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
fpassthru($fh);
fclose($fh);
