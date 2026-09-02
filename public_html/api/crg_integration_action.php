<?php
declare(strict_types=1);
/**
 * INTÉGRATION CRG — LES ACTIONS DE LA PAGE D'ADMINISTRATION.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ LE DÉPÔT SE FAIT PAR TRANCHES, ET CE N'EST PAS UN LUXE. `post_max_size` vaut 64 Mo sur ce
 *    poste ; le document à traiter en pèse 77. Un envoi direct ne renverrait pas une erreur :
 *    PHP viderait `$_POST` ET `$_FILES`, et la page afficherait « aucun fichier reçu » sur un
 *    dépôt parfaitement valide. On découpe donc côté navigateur et on recolle ici.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. Ce point d'entrée ne touche que les tables `crgi_*` et le dossier
 *    de staging.
 *
 * ⚠️ LE CHEMIN NE VIENT JAMAIS DU NAVIGATEUR pour une lecture. Le mode « fichier déjà sur le
 *    serveur » existe pour les gros documents, mais il est borné par `CRGI_RACINES` : sans
 *    cela, l'administration deviendrait un lecteur de fichiers arbitraire.
 *
 * POST : action=creer|tranche|finir|analyser|valider|annuler
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/crg_integration.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');

/** Les seules racines où un fichier « déjà sur le serveur » peut être pris. */
const CRGI_RACINES = ['C:/tmp', 'D:/', '/tmp', '/var/crg'];

function repondre(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(['ok' => false, 'erreur' => 'POST attendu.'], 405);
}
verify_csrf_any('default');

$pdo = $GLOBALS['pdo'];
$user = current_user_id();
$action = (string)($_POST['action'] ?? '');
$importId = (int)($_POST['import_id'] ?? 0);

try {
    switch ($action) {

        // ── Ouvrir un import. Tout le reste s'y rattache. ────────────────────────────────
        case 'creer':
            $id = crgi_creer_import($pdo, (string)($_POST['libelle'] ?? ''), $user);
            repondre(['ok' => true, 'import_id' => $id]);

            // ── Une tranche de fichier. Le navigateur les envoie dans l'ordre. ───────────
        case 'tranche':
            if ($importId <= 0) {
                repondre(['ok' => false, 'erreur' => 'import_id manquant.'], 400);
            }
            $nom = basename((string)($_POST['nom'] ?? ''));
            $index = (int)($_POST['index'] ?? -1);
            if ($nom === '' || $index < 0 || empty($_FILES['tranche']['tmp_name'])) {
                repondre(['ok' => false, 'erreur' => 'Tranche incomplète.'], 400);
            }
            $dossier = crgi_dossier($importId);
            @mkdir($dossier, 0775, true);
            // ⚠️ LE NOM DU FICHIER TEMPORAIRE EST DÉRIVÉ D'UNE EMPREINTE, pas du nom fourni :
            //    deux dépôts simultanés de « CRG.pdf » ne doivent pas se mélanger.
            $cible = $dossier . '/.tmp_' . hash('sha256', $nom) . '.part';
            $flux = fopen($cible, $index === 0 ? 'wb' : 'ab');
            if (!$flux) {
                repondre(['ok' => false, 'erreur' => 'Écriture impossible dans le staging.'], 500);
            }
            fwrite($flux, (string)file_get_contents($_FILES['tranche']['tmp_name']));
            fclose($flux);
            repondre(['ok' => true, 'recu' => filesize($cible)]);

            // ── Le fichier est complet : on le nomme et on l'enregistre. ────────────────
        case 'finir':
            $nom = basename((string)($_POST['nom'] ?? ''));
            $dossier = crgi_dossier($importId);
            $part = $dossier . '/.tmp_' . hash('sha256', $nom) . '.part';
            if (!is_file($part)) {
                repondre(['ok' => false, 'erreur' => 'Aucune tranche reçue pour ' . $nom], 400);
            }
            $final = $dossier . '/' . preg_replace('/[^A-Za-z0-9 ._-]/', '_', $nom);
            rename($part, $final);
            $pieceId = crgi_ajouter_piece($pdo, $importId, $final, $nom);
            repondre(['ok' => true, 'piece_id' => $pieceId,
                      'pages' => (int)$pdo->query('SELECT nb_pages FROM crgi_piece WHERE id = '
                                                  . $pieceId)->fetchColumn()]);

            // ── Un fichier déjà présent sur le serveur, pour les très gros documents. ───
        case 'chemin':
            $chemin = str_replace('\\', '/', trim((string)($_POST['chemin'] ?? '')));
            $reel = realpath($chemin);
            if (!$reel || !is_file($reel)) {
                repondre(['ok' => false, 'erreur' => 'Fichier introuvable : ' . $chemin], 400);
            }
            $normalise = str_replace('\\', '/', $reel);
            $autorise = false;
            foreach (CRGI_RACINES as $racine) {
                if (stripos($normalise, rtrim($racine, '/') . '/') === 0) {
                    $autorise = true;
                    break;
                }
            }
            if (!$autorise) {
                repondre(['ok' => false, 'erreur' => 'Chemin hors des racines autorisées ('
                          . implode(', ', CRGI_RACINES) . ').'], 403);
            }
            $pieceId = crgi_ajouter_piece($pdo, $importId, $normalise, basename($normalise));
            repondre(['ok' => true, 'piece_id' => $pieceId]);

            // ── PHASE 0 ─────────────────────────────────────────────────────────────────
        case 'analyser':
            @set_time_limit(0);
            $bilan = crgi_phase0($pdo, $importId);
            repondre(['ok' => true, 'bilan' => $bilan]);

        case 'phase1':
            // ⚠️ TOUT SE FAIT DEPUIS LA PAGE. La qualification des collisions et l'inventaire
            //    ne sont pas des manipulations d'atelier : ce sont des étapes du parcours, et
            //    elles doivent être déclenchables et visibles là où Emmanuel travaille.
            @set_time_limit(0);
            repondre(['ok' => true, 'bilan' => crgi_phase1($pdo, $importId)]);

        case 'phase2':
            @set_time_limit(0);
            repondre(['ok' => true, 'bilan' => crgi_phase2($pdo, $importId)]);

        case 'phase3':
            @set_time_limit(0);
            repondre(['ok' => true, 'bilan' => crgi_phase3($pdo, $importId)]);

        case 'phase4':
            // La lecture géométrique des 646 pages prend une minute : pas de limite de temps.
            @set_time_limit(0);
            repondre(['ok' => true, 'bilan' => crgi_phase4($pdo, $importId)]);

        case 'compatibilite':
            // ⚠️ AVANT L'ANALYSE, PAS APRÈS. Le test ne modifie rien : il dit si le moteur SAIT
            //    lire ce document, ou quelle structure il ne sait pas traiter.
            @set_time_limit(0);
            $st = $pdo->prepare('SELECT chemin FROM crgi_piece WHERE import_id = ? ORDER BY id');
            $st->execute([$importId]);
            $rapports = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
                $rapports[] = crgi_compatibilite((string)$chemin);
            }
            repondre(['ok' => true, 'rapports' => $rapports]);

        case 'phase5':
            @set_time_limit(0);
            repondre(['ok' => true, 'bilan' => crgi_phase5($pdo, $importId)]);

        case 'arbitrer':
            // ⚠️ DÉCIDER N'EST PAS INTÉGRER. On enregistre le choix d'Emmanuel dans le
            //    staging, daté et signé. Aucune donnée métier n'est touchée, et le plan reste
            //    ce que le DOCUMENT démontre.
            crgi_arbitrer(
                $pdo, $importId,
                (string)($_POST['cible_type'] ?? ''),
                (int)($_POST['cible_id'] ?? 0),
                trim((string)($_POST['choix'] ?? '')),
                trim((string)($_POST['precision'] ?? '')),
                (int)$user
            );
            repondre(['ok' => true]);

        case 'valider':
            $phase = (int)($_POST['phase'] ?? -1);
            if (!array_key_exists($phase, CRGI_PHASES)) {
                repondre(['ok' => false, 'erreur' => 'Phase inconnue.'], 400);
            }
            // ⚠️ ON NE VALIDE PAS UNE PHASE QUI N'A PAS ÉTÉ ANALYSÉE. Une validation sur un
            //    écran vide serait une signature au bas d'une page blanche.
            $etat = $pdo->prepare('SELECT statut FROM crgi_phase WHERE import_id=? AND phase=?');
            $etat->execute([$importId, $phase]);
            if (!in_array((string)$etat->fetchColumn(), ['A VALIDER', 'VALIDEE'], true)) {
                repondre(['ok' => false, 'erreur' => "Cette phase n'est pas prête à être validée."], 409);
            }
            crgi_valider_phase($pdo, $importId, $phase, $user);
            repondre(['ok' => true]);

        case 'annuler':
            crgi_annuler($pdo, $importId, $user, (string)($_POST['motif'] ?? ''));
            repondre(['ok' => true]);

        default:
            repondre(['ok' => false, 'erreur' => 'Action inconnue : ' . $action], 400);
    }
} catch (Throwable $e) {
    // ⚠️ LE MESSAGE REMONTE TEL QUEL. « Une erreur est survenue » obligerait à ouvrir les logs
    //    du serveur pour savoir qu'il manquait simplement Python.
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
