<?php
// api/annonce_diffuser.php — Lance la diffusion d'une annonce
//
// Fait en un appel :
//   1. Vérifie la complétude Ubiflow (refuse si champs bloquants manquent)
//   2. UPDATE annonces.etat_publication = 'diffusee' + date_publication (1re fois)
//   3. Si visible_portails = 1 : déclenche ubiflow_deploy() pour l'agence
//      du bien (slug résolu depuis ubiflow_agences.php via id_agence)
//   4. Retourne JSON structuré avec le résultat par canal
//
// POST : { _annonce_id, csrf_token }
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ubiflow_validator.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
$annonceId = isset($_POST['_annonce_id']) && ctype_digit((string)$_POST['_annonce_id']) ? (int)$_POST['_annonce_id'] : 0;
if ($annonceId <= 0) exit(json_encode(['ok' => false, 'error' => 'id_annonce manquant']));

try {
    /* ── 1. Chargement annonce + bien + scope société ─────────── */
    $st = $pdo->prepare("
        SELECT a.*, b.id AS bid, b.id_societe AS b_societe, b.id_agence AS b_agence
        FROM annonces a
        JOIN biens b ON b.id = a.id_bien
        WHERE a.id = ?
        LIMIT 1
    ");
    $st->execute([$annonceId]);
    $annonce = $st->fetch(PDO::FETCH_ASSOC);
    if (!$annonce) exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
    if (!$isSuperAdmin && $societeId > 0 && (int)$annonce['b_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }

    // JOIN types_bien  : expose _type_bien_code (ex: 'garage', 'parking', 'appartement'…)
    //                    indispensable pour que ubiflow_check_completude() applique
    //                    correctement les required_when basés sur le code type.
    // JOIN immeubles   : expose _imm_code_postal / _imm_ville / _imm_adresse_1 / etc.
    //                    indispensable car bien_autosave.php persiste l'adresse dans la
    //                    table `immeubles` (via biens.id_immeuble) — PAS dans biens.*.
    //                    Sans ce JOIN, le validator voit biens.code_postal et biens.ville
    //                    vides alors que l'immeuble lié a bien les bonnes valeurs.
    $bienRow = $pdo->prepare("
        SELECT b.*,
               COALESCE(bt.code, btb.code) AS _type_bien_code,
               i.adresse_1  AS _imm_adresse_1,
               i.adresse_2  AS _imm_adresse_2,
               i.code_postal AS _imm_code_postal,
               i.ville      AS _imm_ville,
               i.pays       AS _imm_pays,
               i.latitude   AS _imm_latitude,
               i.longitude  AS _imm_longitude,
               -- Nb lots copropriété : nb_lots (saisi) sinon copro_nb_lots (registre RNC)
               COALESCE(NULLIF(i.nb_lots, 0), i.copro_nb_lots) AS _imm_nb_lots
        FROM biens b
        LEFT JOIN bien_types      bt  ON bt.id  = b.id_bien_type
        LEFT JOIN types_bien btb ON btb.id = b.id_type_bien
        LEFT JOIN immeubles  i  ON i.id  = b.id_immeuble
        WHERE b.id = ?
        LIMIT 1
    ");
    $bienRow->execute([(int)$annonce['bid']]);
    $bien = $bienRow->fetch(PDO::FETCH_ASSOC) ?: [];

    /* ── 2. Nombre de photos sélectionnées pour l'annonce ─────── */
    $stP = $pdo->prepare("SELECT COUNT(*) FROM annonces_photos WHERE id_annonce = ?");
    $stP->execute([$annonceId]);
    $photosCount = (int)$stP->fetchColumn();

    /* ── 3. Check complétude Ubiflow ──────────────────────────── */
    $check = ubiflow_check_completude($bien, $annonce, $photosCount, $pdo, $societeId);
    $blocking = array_filter($check['missing'] ?? [], static fn($m) => !empty($m['blocking']));
    if (!empty($blocking)) {
        exit(json_encode([
            'ok'    => false,
            'error' => 'Champs bloquants manquants (' . count($blocking) . ') — résoudre avant diffusion.',
            'missing' => array_values($blocking),
            'score'   => (int)($check['score'] ?? 0),
        ]));
    }

    /* ── 4. Au moins un canal doit être coché ─────────────────── */
    $chanMbi  = (int)($annonce['visible_maboximmo']   ?? 0) === 1;
    $chanWeb  = (int)($annonce['visible_site_perso']  ?? 0) === 1;
    $chanPort = (int)($annonce['visible_portails']    ?? 0) === 1;
    if (!$chanMbi && !$chanWeb && !$chanPort) {
        exit(json_encode([
            'ok'    => false,
            'error' => 'Aucun canal de diffusion sélectionné (MaBoxImmo / Site perso / Portails).',
        ]));
    }

    /* ── 5. UPDATE etat_publication ───────────────────────────── */
    $alreadyDiffusee = ($annonce['etat_publication'] ?? '') === 'diffusee';
    // date_mise_en_ligne (colonne réelle en BDD, cf. schéma annonces) — set 1ère fois uniquement
    // statut = 'publiee' : requis par le filtre Ubiflow (config/ubiflow_mapping.php)
    // qui filtre sur statut IN ('publiee','active','en_ligne')
    // AGENCE DE DIFFUSION = celle CHOISIE sur l'annonce (sélecteur → annonces.id_agence),
    // sinon repli sur l'agence du bien. Le builder Ubiflow filtre sur annonces.id_agence :
    // sans valeur, l'annonce est EXCLUE du flux malgré une diffusion réussie.
    // BLOQUANT : pas d'agence résolue → on refuse la diffusion (l'utilisateur doit choisir).
    $diffAgence = (int)($annonce['id_agence'] ?? 0) ?: (int)($annonce['b_agence'] ?? 0);
    if ($diffAgence <= 0) {
        exit(json_encode([
            'ok'    => false,
            'error' => "Agence de diffusion non renseignée — sélectionne l'« Agence de diffusion » avant de diffuser.",
            'field' => 'annonce_agence_id',
        ]));
    }
    // On persiste l'agence effective (no-op si déjà posée à un choix délibéré ; renseigne si NULL).
    $pdo->prepare("
        UPDATE annonces
        SET etat_publication = 'diffusee',
            statut = 'publiee',
            id_agence = ?,
            date_mise_en_ligne = COALESCE(date_mise_en_ligne, NOW()),
            date_modification = NOW()
        WHERE id = ?
    ")->execute([$diffAgence, $annonceId]);

    /* ── 6. Trigger Ubiflow si canal portails activé ──────────── */
    $portailsResult = null;
    // 🚨 GARDE-FOU : AUCUN envoi Ubiflow hors PRODUCTION (local/dev = jamais).
    $ubiHost   = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $ubiIsProd = in_array($ubiHost, ['maboximmo.fr', 'www.maboximmo.fr'], true);
    if ($chanPort && !$ubiIsProd) {
        $portailsResult = [
            'ok'         => false,
            'simulation' => true,
            'error'      => '⛔ Diffusion Ubiflow désactivée hors production (host=' . ($ubiHost ?: 'cli') . '). Flags BDD à jour, aucun flux envoyé.',
        ];
    } elseif ($chanPort) {
        try {
            require_once __DIR__ . '/../config/ubiflow_agences.php';
            require_once __DIR__ . '/../config/ubiflow_mapping.php';
            require_once __DIR__ . '/../api/flux/ubiflow_ftp.php';

            // Trouve le slug de l'AGENCE DE DIFFUSION choisie (annonces.id_agence), pas celle du bien.
            $idAgence = $diffAgence;
            $slug = null;
            foreach (ubiflow_agences_all() as $s => $cfg) {
                if ((int)($cfg['id_agence'] ?? 0) === $idAgence && !empty($cfg['actif'])) {
                    $slug = (string)$s;
                    break;
                }
            }
            if ($slug === null) {
                $portailsResult = [
                    'ok' => false,
                    'error' => 'Agence #' . $idAgence . ' non configurée dans ubiflow_agences (ou actif=false)',
                ];
            } else {
                // Génère le XML (tout le flux de l'agence) puis deploy
                require_once __DIR__ . '/../inc/ubiflow_build.php';
                $cfg       = ubiflow_agence_get($slug);
                $loginFtp  = (string)$cfg['login_ftp'];
                $exportDir = dirname(__DIR__) . '/api/flux/export/' . $slug;
                if (!is_dir($exportDir)) @mkdir($exportDir, 0775, true);
                $outFile = $exportDir . '/' . $loginFtp . '.xml';

                $build = ubiflow_build_flux_xml($pdo, $idAgence);
                if (@file_put_contents($outFile, $build['xml']) === false) {
                    throw new RuntimeException('write failed: ' . $outFile);
                }

                $portailsResult = ubiflow_deploy($slug, $outFile, [
                    'triggered_by'   => 'manual',
                    'triggered_user' => $userId,
                    'force'          => false,
                ]);
                $portailsResult['annonces_in_flux'] = $build['count'];
                $portailsResult['skipped_in_flux']  = $build['skipped'];
            }
        } catch (Throwable $e) {
            error_log('[annonce_diffuser ubiflow] ' . $e->getMessage());
            $portailsResult = ['ok' => false, 'error' => 'Ubiflow : ' . $e->getMessage()];
        }
    }

    exit(json_encode([
        'ok'                 => true,
        'annonce_id'         => $annonceId,
        'state_before'       => $alreadyDiffusee ? 'diffusee' : 'brouillon',
        'state_after'        => 'diffusee',
        'canaux' => [
            'maboximmo'  => $chanMbi ? ['ok' => true, 'info' => 'Visible dans l\'annuaire interne'] : null,
            'site_perso' => $chanWeb ? ['ok' => true, 'info' => 'Visible sur le site agence'] : null,
            'portails'   => $chanPort ? $portailsResult : null,
        ],
        'score' => (int)($check['score'] ?? 0),
    ]));
} catch (Throwable $e) {
    error_log('[annonce_diffuser] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
