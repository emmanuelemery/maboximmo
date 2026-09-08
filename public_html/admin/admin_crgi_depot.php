<?php
declare(strict_types=1);
/**
 * DÉPOSER UN DOSSIER DE COMPTES RENDUS, ET LAISSER LE MOTEUR TRAVAILLER.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CET ÉCRAN MANQUAIT, ET C'EST POUR ÇA QUE TOUT SE FAISAIT EN LIGNE DE COMMANDE. Les
 *    fonctions existaient depuis le début — `crgi_creer_import()`, `crgi_ajouter_piece()`,
 *    `crgi_phase0()` à `crgi_phase5()` — mais rien ne les enchaînait depuis le navigateur. Les
 *    quatre dépôts du corpus ont été montés à la main, par des scripts de mise au point. Un
 *    module qu'on ne sait lancer que depuis un terminal n'est pas livré.
 *
 * ⚠️ ON DÉSIGNE UN DOSSIER, ON NE TÉLÉVERSE PAS. Les dépôts pèsent plusieurs centaines de
 *    mégaoctets — un seul PDF du corpus fait 587 Mo. Passer par un formulaire d'upload
 *    heurterait `upload_max_filesize`, `post_max_size` et le temps d'exécution, pour recopier
 *    des fichiers qui sont déjà sur la machine. On lit donc le dossier SUR PLACE, et le chemin
 *    est conservé : la preuve reste consultable page par page, sans duplication.
 *
 * ⚠️ LE CHEMIN NE VIENT PAS LIBREMENT DE L'UTILISATEUR. Une racine autorisée est déclarée ici ;
 *    tout ce qui en sort est refusé. Sans cette borne, l'écran deviendrait un lecteur de
 *    fichiers arbitraires du serveur.
 *
 * ⚠️ LE TRAVAIL EST LONG ET IL SE VOIT. Une passe complète prend un quart d'heure sur le plus
 *    gros dépôt. On lance donc phase par phase, chacune rendant la main avec son résultat —
 *    « je veux faire phase par phase pour détecter les problèmes et résoudre très vite »
 *    (Emmanuel, 07/09/2026). Enchaîner les six en silence, c'est perdre l'heure quand la
 *    troisième révèle un défaut.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/crg_integration.php';
require_admin_or_super_admin();

// ── Les racines où l'on accepte de lire ─────────────────────────────────────────────────
// ⚠️ DÉCLARÉES ICI, JAMAIS DANS L'URL. Ajouter une racine est une décision, pas une saisie.
const CRGID_RACINES = [
    'D:/'                                   => 'Disque D: (dépôts CRG)',
    'C:/xampp/htdocs/MaBoxImmo2026/data'    => 'Données du projet',
];

$pdo  = $GLOBALS['pdo'];
$csrf = csrf_token('default');
$msg  = null;
$err  = null;

/** Le chemin est-il sous une racine autorisée ? Rend le chemin normalisé, ou null. */
function crgid_chemin_admis(string $brut): ?string
{
    $reel = realpath($brut);
    if ($reel === false || !is_dir($reel)) {
        return null;
    }
    $reel = str_replace('\\', '/', $reel);
    foreach (array_keys(CRGID_RACINES) as $racine) {
        $r = str_replace('\\', '/', (string)realpath($racine));
        if ($r !== '' && str_starts_with($reel . '/', rtrim($r, '/') . '/')) {
            return $reel;
        }
    }
    return null;
}

/** Les PDF d'un dossier, sous-dossiers compris — triés, sans doublon de chemin. */
function crgid_pdf_du_dossier(string $dossier): array
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS)
    );
    $pdf = [];
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') {
            $pdf[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($pdf);
    return $pdf;
}

// ── ACTIONS ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('default');
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'deposer') {
            $dossier = crgid_chemin_admis((string)($_POST['dossier'] ?? ''));
            if ($dossier === null) {
                throw new RuntimeException(
                    'DOSSIER REFUSÉ — il doit exister et se trouver sous une racine autorisée. '
                    . 'Racines : ' . implode(' · ', array_keys(CRGID_RACINES))
                );
            }
            $pdfs = crgid_pdf_du_dossier($dossier);
            if (!$pdfs) {
                throw new RuntimeException('AUCUN PDF dans ce dossier : ' . $dossier);
            }
            $libelle = trim((string)($_POST['libelle'] ?? '')) ?: basename($dossier);
            $importId = crgi_creer_import($pdo, $libelle, (int)($_SESSION['user_id'] ?? 0));
            // ⚠️ UN FICHIER QUI REFUSE DE SE LAISSER COMPTER NE FAIT PAS TOMBER LE DÉPÔT : il
            //    est signalé et le reste continue. Perdre 400 documents pour un PDF corrompu
            //    serait une régression sur un incident.
            $ok = 0;
            $refuses = [];
            foreach ($pdfs as $chemin) {
                try {
                    crgi_ajouter_piece($pdo, $importId, $chemin, basename($chemin));
                    $ok++;
                } catch (Throwable $e) {
                    $refuses[] = basename($chemin) . ' — ' . $e->getMessage();
                }
            }
            $msg = 'Dépôt #' . $importId . ' « ' . $libelle . ' » créé : ' . $ok . ' PDF sur '
                 . count($pdfs) . '.'
                 . ($refuses ? ' REFUSÉS : ' . implode(' | ', array_slice($refuses, 0, 5)) : '');
        } elseif ($action === 'phase') {
            $importId = (int)($_POST['import'] ?? 0);
            $phase    = (int)($_POST['phase'] ?? -1);
            if (!isset(CRGI_PHASES[$phase])) {
                throw new RuntimeException('PHASE INCONNUE : ' . $phase);
            }
            @set_time_limit(0);
            $t0 = microtime(true);
            $fn = 'crgi_phase' . $phase;
            $bilan = $fn($pdo, $importId);
            $sec = round(microtime(true) - $t0);
            // ⚠️ ON SCELLE DANS LA FOULÉE, ET ON DIT SI LE SCEAU REFUSE. Une phase analysée
            //    mais non validée bloque la suivante : le taire ferait chercher longtemps.
            try {
                crgi_valider_phase($pdo, $importId, $phase, (int)($_SESSION['user_id'] ?? 0));
                $msg = 'Phase ' . $phase . ' — ' . CRGI_PHASES[$phase] . ' : ANALYSÉE ET SCELLÉE ('
                     . $sec . ' s). ' . crgid_resume($bilan);
            } catch (Throwable $e) {
                $err = 'Phase ' . $phase . ' analysée (' . $sec . ' s) mais NON SCELLÉE — '
                     . $e->getMessage();
            }
        } elseif ($action === 'supprimer') {
            $importId = (int)($_POST['import'] ?? 0);
            // ⚠️ ON N'EFFACE QUE DU STAGING. Les PDF sources ne sont jamais touchés : ils ne
            //    nous appartiennent pas, on les a seulement lus là où ils sont.
            foreach (['crgi_arbitrage', 'crgi_plan', 'crgi_mouvement', 'crgi_occupation',
                      'crgi_lot', 'crgi_immeuble', 'crgi_page', 'crgi_crg', 'crgi_piece',
                      'crgi_phase'] as $t) {
                $pdo->prepare("DELETE FROM `$t` WHERE import_id = ?")->execute([$importId]);
            }
            $pdo->prepare('DELETE FROM crgi_import WHERE id = ?')->execute([$importId]);
            $msg = 'Dépôt #' . $importId . ' retiré du staging. Les PDF sources sont intacts.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

/** Un bilan de phase, rendu lisible sans connaître sa forme. */
function crgid_resume($bilan): string
{
    if (!is_array($bilan)) {
        return '';
    }
    $bouts = [];
    foreach ($bilan as $k => $v) {
        if (is_scalar($v)) {
            $bouts[] = $k . ' : ' . $v;
        }
    }
    return implode(' · ', array_slice($bouts, 0, 8));
}

$imports = $pdo->query(
    'SELECT i.*,
            (SELECT COUNT(*) FROM crgi_piece p WHERE p.import_id = i.id) pieces,
            (SELECT COALESCE(SUM(p.nb_pages),0) FROM crgi_piece p WHERE p.import_id = i.id) pages,
            (SELECT COUNT(*) FROM crgi_crg c WHERE c.import_id = i.id) crg
       FROM crgi_import i ORDER BY i.id DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$etatPhases = $pdo->query(
    'SELECT import_id, phase, statut FROM crgi_phase'
)->fetchAll(PDO::FETCH_ASSOC);
$etat = [];
foreach ($etatPhases as $e) {
    $etat[(int)$e['import_id']][(int)$e['phase']] = (string)$e['statut'];
}

$nb = fn($x) => number_format((float)$x, 0, ',', ' ');
$pageTitle = 'CRG — déposer un dossier et lancer l’analyse';
require_once __DIR__ . '/../inc/agency_layout_top.php';
?>
<style>
  .crgid-carte { border:1px solid #d8dee9; border-radius:8px; padding:14px 16px; margin:12px 0;
                 background:#fff; }
  .crgid-carte h3 { margin:0 0 6px; font-size:15px; }
  .crgid-pas { display:inline-block; padding:5px 10px; margin:3px 4px 3px 0; border-radius:6px;
               border:1px solid #cbd5e1; font-size:12px; background:#f8fafc; }
  .crgid-pas.fait { background:#dcfce7; border-color:#86efac; }
  .crgid-pas.attente { color:#64748b; }
  .crgid-msg { padding:10px 14px; border-radius:6px; margin:10px 0; }
  .crgid-ok { background:#dcfce7; border:1px solid #86efac; }
  .crgid-err { background:#fee2e2; border:1px solid #fca5a5; }
  .crgid-note { color:#475569; font-size:13px; line-height:1.55; }
</style>

<div class="container" style="max-width:1100px">
  <h1 style="font-size:24px;margin:16px 0 4px">Comptes rendus de gestion — dépôt et analyse</h1>
  <p class="crgid-note">
    On <b>désigne un dossier</b> du disque : les PDF y sont lus <b>sur place</b>, jamais copiés.
    Un seul document du corpus pèse 587 Mo — le téléversement n’a pas de sens, et le chemin
    conservé permet de rouvrir la preuve page par page.
    <br><b>Rien n’est écrit dans MBI.</b> Tout vit dans le staging <code>crgi_*</code> :
    analyser n’est pas intégrer.
  </p>

  <?php if ($msg): ?><div class="crgid-msg crgid-ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="crgid-msg crgid-err"><b>REFUSÉ</b> — <?= h($err) ?></div><?php endif; ?>

  <div class="crgid-carte">
    <h3>Déposer un dossier</h3>
    <form method="post" action="<?= h(app_url('/admin/admin_crgi_depot.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="deposer">
      <p style="margin:8px 0">
        <label>Dossier&nbsp;
          <input type="text" name="dossier" required style="width:52%;padding:6px 8px"
                 placeholder="D:/CRG REGIE EMERY LYON"></label>
        <label style="margin-left:10px">Nom du dépôt&nbsp;
          <input type="text" name="libelle" style="width:22%;padding:6px 8px"
                 placeholder="(le nom du dossier par défaut)"></label>
        <button type="submit" style="margin-left:8px;padding:7px 18px;font-weight:600">
          Déposer</button>
      </p>
      <p class="crgid-note">
        Les sous-dossiers sont parcourus. Racines autorisées :
        <?php foreach (CRGID_RACINES as $r => $lib): ?>
          <code><?= h($r) ?></code> <?= h($lib) ?><?= $r === array_key_last(CRGID_RACINES) ? '' : ' · ' ?>
        <?php endforeach; ?>
      </p>
    </form>
  </div>

  <?php foreach ($imports as $i): $id = (int)$i['id']; ?>
    <div class="crgid-carte">
      <h3>#<?= $id ?> — <?= h((string)$i['libelle']) ?></h3>
      <p class="crgid-note">
        <b><?= $nb($i['pieces']) ?></b> fichier(s) · <b><?= $nb($i['pages']) ?></b> page(s) ·
        <b><?= $nb($i['crg']) ?></b> compte(s) rendu(s) détecté(s) ·
        déposé le <?= h(substr((string)$i['cree_le'], 0, 16)) ?>
      </p>
      <?php // ⚠️ UNE PHASE À LA FOIS. Enchaîner les six en silence, c'est perdre l'heure quand
            //    la troisième révèle un défaut. Chaque bouton rend la main avec son résultat. ?>
      <form method="post" action="<?= h(app_url('/admin/admin_crgi_depot.php')) ?>"
            style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="phase">
        <input type="hidden" name="import" value="<?= $id ?>">
        <?php foreach (CRGI_PHASES as $p => $titre):
            $st = $etat[$id][$p] ?? 'EN ATTENTE';
            $fait = $st === 'VALIDEE'; ?>
          <button type="submit" name="phase" value="<?= $p ?>"
                  class="crgid-pas <?= $fait ? 'fait' : 'attente' ?>"
                  title="<?= h($st) ?>">
            <?= $p ?> · <?= h($titre) ?> <?= $fait ? '✔' : '' ?></button>
        <?php endforeach; ?>
      </form>
      <div style="margin-top:8px">
        <a href="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=2&amp;base=maboximmo">
          voir l’analyse</a>
        &nbsp;·&nbsp;
        <form method="post" action="<?= h(app_url('/admin/admin_crgi_depot.php')) ?>"
              style="display:inline"
              onsubmit="return confirm('Retirer le dépôt #<?= $id ?> du staging ? Les PDF sources ne sont pas touchés.')">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="supprimer">
          <input type="hidden" name="import" value="<?= $id ?>">
          <button type="submit" style="border:none;background:none;color:#b91c1c;cursor:pointer;
                                       padding:0;font-size:13px">retirer du staging</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$imports): ?>
    <p class="crgid-note">Aucun dépôt pour l’instant.</p>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
