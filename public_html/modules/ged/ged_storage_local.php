<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Driver stockage LOCAL (dev XAMPP)
 * Fichier : modules/ged/ged_storage_local.php
 *
 * Stocke les fichiers sous {storage_base}/{folder_path}/{filename}, hors webroot.
 * Utilise le chemin relatif comme file_id (déterministe + lisible).
 *
 * Permissions par défaut : 0755 sur les dossiers, 0644 sur les fichiers.
 */

require_once __DIR__ . '/ged_storage.php';

class GedStorageLocal extends GedStorageDriver
{
    private string $base;

    public function __construct(string $baseDir)
    {
        // Normalise (résout les .. et autres pour comparaisons sûres)
        $base = rtrim($baseDir, "/\\");
        if ($base === '') {
            throw new RuntimeException('GedStorageLocal : baseDir vide.');
        }
        if (!is_dir($base)) {
            if (!@mkdir($base, 0755, true) && !is_dir($base)) {
                throw new RuntimeException("GedStorageLocal : impossible de créer {$base}");
            }
        }
        $this->base = $base;
    }

    public function getName(): string { return 'local'; }

    public function upload(string $localPath, string $remoteName, ?string $folderId = null, ?string $mime = null): array
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("Fichier source introuvable : {$localPath}");
        }

        $folderRel = $folderId !== null ? $this->safeFolderRel($folderId) : '';
        $folderAbs = $this->base . ($folderRel !== '' ? '/' . $folderRel : '');
        if (!is_dir($folderAbs)) {
            if (!@mkdir($folderAbs, 0755, true) && !is_dir($folderAbs)) {
                throw new RuntimeException("Impossible de créer le dossier : {$folderAbs}");
            }
        }

        $safeName = $this->sanitizeFilename($remoteName);
        $destAbs  = $folderAbs . '/' . $safeName;
        $destRel  = ($folderRel !== '' ? $folderRel . '/' : '') . $safeName;

        if (!@copy($localPath, $destAbs)) {
            throw new RuntimeException("copy() échoué : {$localPath} → {$destAbs}");
        }
        @chmod($destAbs, 0644);

        $sha = self::sha256($destAbs);
        $size = (int)filesize($destAbs);
        $mt   = $mime ?: self::detectMime($destAbs);

        return [
            'file_id'   => $destRel,
            'folder_id' => $folderRel !== '' ? $folderRel : null,
            'sha256'    => $sha,
            'size'      => $size,
            'mime'      => $mt,
            'name'      => $safeName,
        ];
    }

    public function download(string $fileId, string $localDestPath): int
    {
        $src = $this->base . '/' . $this->safeRel($fileId);
        if (!is_file($src)) {
            throw new RuntimeException("Fichier introuvable : {$fileId}");
        }
        if (!@copy($src, $localDestPath)) {
            throw new RuntimeException("copy() échoué : {$src} → {$localDestPath}");
        }
        return (int)filesize($localDestPath);
    }

    public function delete(string $fileId): bool
    {
        $src = $this->base . '/' . $this->safeRel($fileId);
        if (!is_file($src)) return false;
        return @unlink($src);
    }

    public function getMetadata(string $fileId): array
    {
        $src = $this->base . '/' . $this->safeRel($fileId);
        $exists = is_file($src);
        return [
            'file_id' => $fileId,
            'name'    => $exists ? basename($src) : '',
            'size'    => $exists ? (int)filesize($src) : null,
            'mime'    => $exists ? self::detectMime($src) : null,
            'exists'  => $exists,
        ];
    }

    public function ensureFolder(string $name, ?string $parentId = null): string
    {
        $safe = $this->sanitizeFilename($name);
        $rel  = $parentId !== null ? rtrim($this->safeFolderRel($parentId), '/') . '/' . $safe : $safe;
        $abs  = $this->base . '/' . $rel;
        if (!is_dir($abs)) {
            if (!@mkdir($abs, 0755, true) && !is_dir($abs)) {
                throw new RuntimeException("Impossible de créer le dossier : {$abs}");
            }
        }
        return $rel;
    }

    /** Empêche les escape-out (../, chemin absolu, etc.). */
    private function safeRel(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, '..')) {
            throw new RuntimeException("Chemin invalide : {$rel}");
        }
        return $rel;
    }

    private function safeFolderRel(string $rel): string
    {
        return rtrim($this->safeRel($rel), '/');
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? '';
        $name = preg_replace('/_+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file_' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        return mb_substr($name, 0, 200);
    }
}
