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

$pdo = $GLOBALS['pdo'];
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

$affichage = 'CRG ' . ($crg['compte'] ?: '') . ' p.' . (int)$crg['page_debut'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($affichage) . '"');
header('Content-Length: ' . filesize((string)$crg['chemin']));
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
$fh = fopen((string)$crg['chemin'], 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
fpassthru($fh);
fclose($fh);
