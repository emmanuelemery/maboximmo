<?php
declare(strict_types=1);

/**
 * PACKAGING ZIP + DÉPÔT FTP UBIFLOW — multi-agences
 * ==================================================
 *
 * Ce module est invoqué par `api/flux/ubiflow.php` quand le flag `--deploy`
 * est présent. Pour une agence donnée :
 *   1. Crée le ZIP `[login_ftp].zip` contenant `[login_ftp].xml`
 *      (format 32 bits standard, ZipArchive par défaut — ZIP64 jamais utilisé
 *       sur des fichiers < 4 Go, ce qui est le cas des flux XML).
 *   2. Se connecte à `ftp.ubiflow.net` en mode passif.
 *   3. Dépose le ZIP À LA RACINE du compte FTP (recommandation Ubiflow).
 *   4. Dépose les photos AYANT CHANGÉ depuis le dernier run à la racine
 *      FTP, SÉPARÉMENT du ZIP (HTTP recommandé pour la prod ; le FTP est
 *      supporté en fallback). Le mode différentiel évite de re-pousser des
 *      dizaines de milliers de fichiers à chaque run.
 *
 * CREDENTIALS
 * -----------
 * Les logins/mots de passe FTP sont fournis par l'équipe Ubiflow pour
 * chaque agence après validation du premier XML de test. Ils doivent être
 * définis dans `config/db.php` (fichier non versionné) :
 *
 *   define('UBIFLOW_FTP_HOST',             'ftp.ubiflow.net');  // optionnel, défaut = ftp.ubiflow.net
 *   define('UBIFLOW_FTP_USER_CHAPONOST',   'xxxx');
 *   define('UBIFLOW_FTP_PASS_CHAPONOST',   'xxxx');
 *   define('UBIFLOW_FTP_USER_LYON',        'xxxx');
 *   define('UBIFLOW_FTP_PASS_LYON',        'xxxx');
 *   define('UBIFLOW_FTP_USER_CHAMALIERES', 'xxxx');
 *   define('UBIFLOW_FTP_PASS_CHAMALIERES', 'xxxx');
 *   define('UBIFLOW_FTP_USER_RIO',         'xxxx');
 *   define('UBIFLOW_FTP_PASS_RIO',         'xxxx');
 *
 * API PUBLIQUE
 * ------------
 *   ubiflow_create_zip(string $xmlPath, string $loginFtp): string
 *   ubiflow_ftp_upload(string $slug, string $zipPath, array $photoPaths = []): array
 *   ubiflow_deploy(string $slug, string $xmlPath): array   ← fonction de haut niveau
 *
 * @see config/ubiflow_agences.php
 * @see api/flux/ubiflow.php
 */

require_once __DIR__ . '/../../config/ubiflow_agences.php';

/**
 * Crée le ZIP attendu par Ubiflow.
 *
 * Contraintes :
 *  - format 32 bits (ZIP64 interdit). ZipArchive est 32 bits par défaut
 *    pour les archives < 4 Go.
 *  - le ZIP doit s'appeler EXACTEMENT `[login_ftp].zip` et contenir un
 *    UNIQUE fichier `[login_ftp].xml`.
 *  - le ZIP est écrit à côté du XML : `export/{slug}/{login_ftp}.zip`.
 *
 * @throws RuntimeException si le ZIP ne peut être créé ou si le XML manque.
 */
function ubiflow_create_zip(string $xmlPath, string $loginFtp): string
{
    if (!is_file($xmlPath)) {
        throw new RuntimeException("Fichier XML introuvable : {$xmlPath}");
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension PHP zip absente (class ZipArchive).');
    }

    $dir     = dirname($xmlPath);
    $zipPath = $dir . '/' . $loginFtp . '.zip';

    // Supprime l'ancien ZIP pour éviter ZIP::CREATE de fusionner
    if (is_file($zipPath)) @unlink($zipPath);

    $zip = new ZipArchive();
    $res = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        throw new RuntimeException("Impossible de créer le ZIP : {$zipPath} (code {$res})");
    }

    // Le XML doit être à la racine du ZIP sous le nom exact attendu
    $zip->addFile($xmlPath, $loginFtp . '.xml');
    $zip->close();

    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        throw new RuntimeException("ZIP vide ou non créé : {$zipPath}");
    }

    return $zipPath;
}

/**
 * Dépose le ZIP (et éventuellement une liste de photos) sur le FTP Ubiflow.
 *
 * @param string $slug        Slug de l'agence (tel que dans ubiflow_agences.php)
 * @param string $zipPath     Chemin absolu du ZIP à déposer
 * @param array  $photoPaths  Liste de chemins absolus de photos à déposer (optionnel)
 *
 * @return array {
 *   ok: bool,
 *   error?: string,
 *   zip: 'OK'|'ECHEC',
 *   photos_count: int,
 *   photos_errors: string[],
 * }
 */
function ubiflow_ftp_upload(string $slug, string $zipPath, array $photoPaths = []): array
{
    $slugUc = strtoupper($slug);

    // Host FTP — toujours ftp.ubiflow.net sauf override explicite
    $host = defined('UBIFLOW_FTP_HOST') ? (string)UBIFLOW_FTP_HOST : 'ftp.ubiflow.net';

    // Credentials : constantes par agence REQUISES
    $userConst = 'UBIFLOW_FTP_USER_' . $slugUc;
    $passConst = 'UBIFLOW_FTP_PASS_' . $slugUc;
    if (!defined($userConst) || !defined($passConst)) {
        return [
            'ok'    => false,
            'error' => "Credentials manquants : définir {$userConst} et {$passConst} dans config/db.php",
            'zip'   => 'SKIP',
            'photos_count' => 0,
            'photos_errors' => [],
        ];
    }
    $user = (string)constant($userConst);
    $pass = (string)constant($passConst);

    if (!function_exists('ftp_connect')) {
        return [
            'ok'    => false,
            'error' => 'Extension PHP ftp absente',
            'zip'   => 'SKIP',
            'photos_count' => 0,
            'photos_errors' => [],
        ];
    }

    $conn = @ftp_connect($host, 21, 30);
    if ($conn === false) {
        return [
            'ok'    => false,
            'error' => "Connexion FTP échouée ({$host}:21)",
            'zip'   => 'ECHEC',
            'photos_count' => 0,
            'photos_errors' => [],
        ];
    }

    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        return [
            'ok'    => false,
            'error' => "Authentification FTP refusée (user={$user})",
            'zip'   => 'ECHEC',
            'photos_count' => 0,
            'photos_errors' => [],
        ];
    }
    @ftp_pasv($conn, true); // mode passif, recommandé derrière NAT

    // 1. Déposer le ZIP en PREMIER (recommandation Ubiflow)
    $remoteZipName = '/' . basename($zipPath);
    $zipOk = @ftp_put($conn, $remoteZipName, $zipPath, FTP_BINARY);

    // 2. Déposer les photos (si fournies) — mode différentiel recommandé
    $photosOk     = 0;
    $photosErrors = [];
    foreach ($photoPaths as $photoPath) {
        if (!is_file($photoPath)) {
            $photosErrors[] = basename($photoPath) . ' : fichier absent';
            continue;
        }
        $remoteName = '/' . basename($photoPath);
        if (@ftp_put($conn, $remoteName, $photoPath, FTP_BINARY)) {
            $photosOk++;
        } else {
            $photosErrors[] = basename($photoPath) . ' : upload échoué';
        }
    }

    ftp_close($conn);

    return [
        'ok'            => (bool)$zipOk,
        'zip'           => $zipOk ? 'OK' : 'ECHEC',
        'photos_count'  => $photosOk,
        'photos_errors' => $photosErrors,
        'error'         => $zipOk ? null : 'ftp_put ZIP échoué',
    ];
}

/**
 * Fonction de haut niveau : pour un slug d'agence + un chemin XML,
 * crée le ZIP et le déploie sur FTP. C'est elle qui est appelée par
 * `ubiflow.php --deploy`.
 *
 * PROTECTIONS ANTI-DOUBLONS (audit V3)
 * ────────────────────────────────────
 * 1. LOCK FILE par slug : empêche deux runs concurrents (cron qui se
 *    déclenche 2× simultanément, admin qui clique pendant que le cron
 *    tourne). Lock auto-libéré au plus tard après 5 minutes.
 * 2. HASH MD5 : si le ZIP a EXACTEMENT le même contenu que le dernier
 *    dépôt `ok` des 24 dernières heures, on SKIP l'upload FTP (économie
 *    de bande passante et de requêtes FTP inutiles) et on logge le cas
 *    comme `skipped_duplicate`.
 * 3. TABLE `ubiflow_deploy_log` : chaque tentative (réussite, skip,
 *    erreur) est tracée avec horodatage, utilisateur, durée, hash.
 *
 * @param string $slug          Slug de l'agence
 * @param string $xmlPath       Chemin absolu du XML à déployer
 * @param array  $opts          [
 *                                'triggered_by' => 'cron'|'manual'|'cli',
 *                                'triggered_user' => int|null,
 *                                'force' => bool (ignore la garde MD5),
 *                              ]
 * @return array {
 *   ok, zip_path, zip, photos_count, error, status, duration_ms,
 *   md5, skipped_reason
 * }
 */
function ubiflow_deploy(string $slug, string $xmlPath, array $opts = []): array
{
    $triggeredBy   = in_array($opts['triggered_by'] ?? 'cli', ['cron','manual','cli'], true)
                       ? $opts['triggered_by'] : 'cli';
    $triggeredUser = isset($opts['triggered_user']) ? (int)$opts['triggered_user'] : null;
    $force         = !empty($opts['force']);

    $t0 = microtime(true);

    $cfg = ubiflow_agence_get($slug);
    if ($cfg === null) {
        return ['ok' => false, 'error' => "Agence inconnue : {$slug}", 'status' => 'build_error'];
    }
    $loginFtp = $cfg['login_ftp'] ?? $slug;

    // ─────── 1. Lock file ─────────────────────────────────────────────
    $lockDir = sys_get_temp_dir() . '/ubiflow_locks';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
    $lockPath = $lockDir . '/' . $slug . '.lock';

    // Détection lock stale (> 5 min = probablement un run mort)
    if (is_file($lockPath)) {
        $age = time() - filemtime($lockPath);
        if ($age < 300) {
            $msg = "Lock actif depuis {$age}s — un autre run de {$slug} est en cours";
            ubiflow_log_deploy($slug, $loginFtp, null, null, null, 'skipped_duplicate', $msg, 0, $triggeredBy, $triggeredUser);
            return ['ok' => false, 'status' => 'skipped_duplicate', 'error' => $msg];
        }
        @unlink($lockPath); // lock stale : on le retire
    }
    @file_put_contents($lockPath, (string)getmypid());

    // Try/finally pour garantir la libération du lock
    try {
        // ───── 2. Packaging ZIP ────────────────────────────────────────
        try {
            $zipPath = ubiflow_create_zip($xmlPath, $loginFtp);
        } catch (Throwable $e) {
            $err = 'ZIP : ' . $e->getMessage();
            ubiflow_log_deploy($slug, $loginFtp, null, null, null, 'build_error', $err,
                (int)((microtime(true) - $t0) * 1000), $triggeredBy, $triggeredUser);
            return ['ok' => false, 'status' => 'build_error', 'error' => $err];
        }

        $zipMd5  = @md5_file($zipPath) ?: null;
        $zipSize = @filesize($zipPath) ?: 0;

        // Compte les annonces dans le XML (pour stats)
        $annoncesCount = 0;
        try {
            $xmlBin = @file_get_contents($xmlPath);
            if ($xmlBin !== false) {
                $annoncesCount = substr_count($xmlBin, '<annonce>');
            }
        } catch (Throwable) {}

        // ───── 3. Garde anti-doublon : skip si MD5 déjà déposé OK aujourd'hui ──
        if (!$force && $zipMd5 !== null) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("
                    SELECT id, started_at FROM ubiflow_deploy_log
                    WHERE slug_agence = :slug
                      AND zip_md5 = :md5
                      AND status = 'ok'
                      AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY started_at DESC LIMIT 1
                ");
                $stmt->execute([':slug' => $slug, ':md5' => $zipMd5]);
                $dup = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $msg = "Contenu identique au dépôt #{$dup['id']} du {$dup['started_at']} — skip";
                    ubiflow_log_deploy($slug, $loginFtp, $zipMd5, $zipSize, $annoncesCount,
                        'skipped_duplicate', $msg,
                        (int)((microtime(true) - $t0) * 1000), $triggeredBy, $triggeredUser);
                    return [
                        'ok'              => true,
                        'status'          => 'skipped_duplicate',
                        'zip_path'        => $zipPath,
                        'md5'             => $zipMd5,
                        'skipped_reason'  => $msg,
                        'duration_ms'     => (int)((microtime(true) - $t0) * 1000),
                    ];
                }
            } catch (Throwable $e) {
                error_log('[ubiflow_deploy] dup check failed : ' . $e->getMessage());
                // Non-bloquant : on continue le dépôt
            }
        }

        // ───── 4. Upload FTP ────────────────────────────────────────────
        $ftp = ubiflow_ftp_upload($slug, $zipPath, []);
        $dur = (int)((microtime(true) - $t0) * 1000);

        // Status normalisé pour le log
        if ($ftp['ok']) {
            $status = 'ok';
        } elseif (str_contains((string)($ftp['error'] ?? ''), 'Credentials manquants')) {
            $status = 'no_creds';
        } else {
            $status = 'ftp_error';
        }

        ubiflow_log_deploy($slug, $loginFtp, $zipMd5, $zipSize, $annoncesCount,
            $status, $ftp['error'] ?? null, $dur, $triggeredBy, $triggeredUser);

        return array_merge([
            'zip_path'    => $zipPath,
            'md5'         => $zipMd5,
            'status'      => $status,
            'duration_ms' => $dur,
        ], $ftp);

    } finally {
        // Toujours libérer le lock
        @unlink($lockPath);
    }
}

/**
 * Insère une ligne dans `ubiflow_deploy_log`. Tolérant : si la table
 * n'existe pas encore (migration pas passée), logge via error_log.
 */
function ubiflow_log_deploy(
    string $slug, string $loginFtp, ?string $md5, ?int $size,
    ?int $annoncesCount, string $status, ?string $errorMsg,
    int $durationMs, string $triggeredBy, ?int $triggeredUser
): void {
    try {
        $pdo = db();
        $pdo->prepare("
            INSERT INTO ubiflow_deploy_log
                (slug_agence, login_ftp, zip_md5, zip_size, annonces_count,
                 status, error_msg, duration_ms, triggered_by, triggered_user)
            VALUES
                (:slug, :login, :md5, :size, :n, :st, :err, :dur, :by, :usr)
        ")->execute([
            ':slug'  => $slug,
            ':login' => $loginFtp,
            ':md5'   => $md5,
            ':size'  => $size,
            ':n'     => $annoncesCount,
            ':st'    => $status,
            ':err'   => $errorMsg ? mb_substr($errorMsg, 0, 500) : null,
            ':dur'   => $durationMs,
            ':by'    => $triggeredBy,
            ':usr'   => $triggeredUser,
        ]);
    } catch (Throwable $e) {
        error_log('[ubiflow_log_deploy] ' . $e->getMessage()
                  . " (slug={$slug} status={$status})");
    }
}
