<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Couche d'abstraction stockage
 * Fichier : modules/ged/ged_storage.php
 *
 * Interface unique pour les drivers (local dev, Google Drive prod). Permet
 * de basculer la cible de stockage sans modifier le code métier.
 *
 * Selon prompt maître §8 : JAMAIS d'appel direct Google Drive — passer par ce driver.
 */

abstract class GedStorageDriver
{
    /**
     * Upload d'un fichier local vers le storage.
     *
     * @param string      $localPath  Chemin du fichier sur le filesystem local.
     * @param string      $remoteName Nom canonique du fichier côté storage (cf. ged_naming).
     * @param string|null $folderId   ID du dossier parent côté storage (null = racine).
     * @param string|null $mime       Mime type explicite (sinon auto-détection).
     * @return array{file_id:string, folder_id:?string, sha256:string, size:int, mime:string, name:string}
     * @throws RuntimeException
     */
    abstract public function upload(string $localPath, string $remoteName, ?string $folderId = null, ?string $mime = null): array;

    /**
     * Télécharge un fichier du storage vers un chemin local.
     *
     * @param string $fileId        ID côté storage.
     * @param string $localDestPath Chemin de destination.
     * @return int Taille en octets.
     * @throws RuntimeException
     */
    abstract public function download(string $fileId, string $localDestPath): int;

    /**
     * Supprime un fichier du storage.
     */
    abstract public function delete(string $fileId): bool;

    /**
     * Métadonnées d'un fichier (existence, taille, nom, mime).
     *
     * @return array{file_id:string, name:string, size:?int, mime:?string, exists:bool}
     */
    abstract public function getMetadata(string $fileId): array;

    /**
     * Crée un dossier (ou retourne l'existant si déjà créé) sous un parent.
     * Utilisé pour matérialiser l'arborescence Drive `00_A_CLASSER_IA, 02_SYNDIC, ...`.
     *
     * @return string ID du dossier.
     */
    abstract public function ensureFolder(string $name, ?string $parentId = null): string;

    /**
     * Identifiant logique du driver (persisté dans ged_analyses.storage_driver).
     */
    abstract public function getName(): string;

    // ── Helpers communs ─────────────────────────────────────────────────────

    /** Calcule le SHA256 d'un fichier local. */
    public static function sha256(string $localPath): string
    {
        $h = hash_file('sha256', $localPath);
        if (!is_string($h)) {
            throw new RuntimeException("Impossible de hasher : {$localPath}");
        }
        return $h;
    }

    /** Détecte le mime type d'un fichier (fallback application/octet-stream). */
    public static function detectMime(string $localPath): string
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if (!$f) return 'application/octet-stream';
        $m = finfo_file($f, $localPath);
        finfo_close($f);
        return is_string($m) && $m !== '' ? $m : 'application/octet-stream';
    }
}

/**
 * Factory : retourne le driver actif selon la config.
 *
 * Sélection :
 *   - constante GED_STORAGE_DRIVER ('local' | 'google_drive')
 *   - sinon getenv('GED_STORAGE_DRIVER')
 *   - sinon : 'google_drive' si la config Drive est chargée, sinon 'local'
 */
function ged_storage_default(): GedStorageDriver
{
    static $instance = null;
    if ($instance instanceof GedStorageDriver) return $instance;

    $explicit = defined('GED_STORAGE_DRIVER') ? (string)GED_STORAGE_DRIVER
              : (getenv('GED_STORAGE_DRIVER') ?: '');

    require_once __DIR__ . '/ged_storage_local.php';

    // Charge la config Drive si présente (ne casse pas si absente)
    $driveConfigCandidates = [
        dirname(__DIR__, 3) . '/u630423897/maboximmo_drive_config.php',
        dirname(__DIR__, 3) . '/u630423897/dev_maboximmo_drive_config.php',
        '/home/u630423897/maboximmo_drive_config.php',
    ];
    foreach ($driveConfigCandidates as $f) {
        if (is_file($f) && is_readable($f)) { require_once $f; break; }
    }
    $driveReady = defined('GOOGLE_DRIVE_CLIENT_ID')
               && defined('GOOGLE_DRIVE_CLIENT_SECRET')
               && defined('GOOGLE_DRIVE_REFRESH_TOKEN')
               && defined('GOOGLE_DRIVE_ROOT_FOLDER_ID')
               && GOOGLE_DRIVE_CLIENT_ID !== ''
               && GOOGLE_DRIVE_CLIENT_SECRET !== ''
               && GOOGLE_DRIVE_REFRESH_TOKEN !== ''
               && GOOGLE_DRIVE_ROOT_FOLDER_ID !== '';

    $choice = $explicit !== '' ? $explicit : ($driveReady ? 'google_drive' : 'local');

    if ($choice === 'google_drive') {
        if (!$driveReady) {
            throw new RuntimeException('Driver google_drive demandé mais config Drive incomplète (cf. u630423897/maboximmo_drive_config.php).');
        }
        require_once __DIR__ . '/ged_storage_google.php';
        $instance = new GedStorageGoogleDrive([
            'client_id'      => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret'  => GOOGLE_DRIVE_CLIENT_SECRET,
            'refresh_token'  => GOOGLE_DRIVE_REFRESH_TOKEN,
            'root_folder_id' => GOOGLE_DRIVE_ROOT_FOLDER_ID,
            'drive_id'       => defined('GOOGLE_DRIVE_DRIVE_ID') ? (string)GOOGLE_DRIVE_DRIVE_ID : null,
        ]);
        return $instance;
    }

    // local par défaut
    $base = defined('GED_STORAGE_LOCAL_BASE') ? (string)GED_STORAGE_LOCAL_BASE
          : dirname(__DIR__, 3) . '/storage/ged';
    $instance = new GedStorageLocal($base);
    return $instance;
}
