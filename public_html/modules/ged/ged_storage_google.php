<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Driver stockage GOOGLE DRIVE (prod)
 * Fichier : modules/ged/ged_storage_google.php
 *
 * Implémentation Google Drive API v3 SANS google/apiclient (dépendance lourde).
 * Utilise OAuth2 refresh_token pour obtenir un access_token + cURL pour les calls.
 *
 * Compatible :
 *   - Mon Drive (root_folder_id = ID d'un dossier dans le drive perso)
 *   - Drive partagé / Shared Drive (drive_id = ID du Shared Drive)
 *
 * Configuration : u630423897/maboximmo_drive_config.php (cf. .template).
 */

require_once __DIR__ . '/ged_storage.php';

class GedStorageGoogleDrive extends GedStorageDriver
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private string $rootFolderId;
    private ?string $driveId;

    private ?string $accessToken = null;
    private int $accessTokenExp = 0;

    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const FILES_URL  = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public function __construct(array $cfg)
    {
        foreach (['client_id', 'client_secret', 'refresh_token', 'root_folder_id'] as $k) {
            if (empty($cfg[$k])) {
                throw new RuntimeException("GedStorageGoogleDrive : config '{$k}' manquante.");
            }
        }
        $this->clientId     = (string)$cfg['client_id'];
        $this->clientSecret = (string)$cfg['client_secret'];
        $this->refreshToken = (string)$cfg['refresh_token'];
        $this->rootFolderId = (string)$cfg['root_folder_id'];
        $this->driveId      = !empty($cfg['drive_id']) ? (string)$cfg['drive_id'] : null;
    }

    public function getName(): string { return 'google_drive'; }

    public function upload(string $localPath, string $remoteName, ?string $folderId = null, ?string $mime = null): array
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("Fichier source introuvable : {$localPath}");
        }
        $parent = $folderId ?: $this->rootFolderId;
        $sha    = self::sha256($localPath);
        $size   = (int)filesize($localPath);
        $mt     = $mime ?: self::detectMime($localPath);

        $metadata = [
            'name'    => $this->sanitizeName($remoteName),
            'parents' => [$parent],
        ];

        $boundary = '----GedMbi' . bin2hex(random_bytes(8));
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata, JSON_UNESCAPED_UNICODE) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mt}\r\n\r\n";
        $body .= file_get_contents($localPath) . "\r\n";
        $body .= "--{$boundary}--";

        $url = self::UPLOAD_URL . '?uploadType=multipart&fields=id,name,parents,size,mimeType';
        if ($this->driveId !== null) {
            $url .= '&supportsAllDrives=true';
        }

        $resp = $this->httpRequest('POST', $url, [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Content-Type: multipart/related; boundary=' . $boundary,
        ], $body);

        return [
            'file_id'   => (string)($resp['id'] ?? ''),
            'folder_id' => $parent,
            'sha256'    => $sha,
            'size'      => $size,
            'mime'      => $mt,
            'name'      => (string)($resp['name'] ?? $metadata['name']),
        ];
    }

    public function download(string $fileId, string $localDestPath): int
    {
        $url = self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media';
        if ($this->driveId !== null) $url .= '&supportsAllDrives=true';

        $fp = fopen($localDestPath, 'wb');
        if (!$fp) throw new RuntimeException("Impossible d'ouvrir {$localDestPath} en écriture");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->getAccessToken()],
            CURLOPT_FILE       => $fp,
            CURLOPT_TIMEOUT    => 120,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false) throw new RuntimeException('cURL download error: ' . $err);
        if ($code !== 200)  throw new RuntimeException("Drive download HTTP {$code}");

        return (int)filesize($localDestPath);
    }

    public function delete(string $fileId): bool
    {
        $url = self::FILES_URL . '/' . rawurlencode($fileId);
        if ($this->driveId !== null) $url .= '?supportsAllDrives=true';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->getAccessToken()],
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 204 = supprimé, 404 = déjà absent (idempotent OK)
        return $code === 204 || $code === 404;
    }

    public function getMetadata(string $fileId): array
    {
        $url = self::FILES_URL . '/' . rawurlencode($fileId) . '?fields=id,name,size,mimeType,trashed';
        if ($this->driveId !== null) $url .= '&supportsAllDrives=true';

        try {
            $r = $this->httpRequest('GET', $url, [
                'Authorization: Bearer ' . $this->getAccessToken(),
            ]);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return ['file_id' => $fileId, 'name' => '', 'size' => null, 'mime' => null, 'exists' => false];
            }
            throw $e;
        }

        $exists = !((bool)($r['trashed'] ?? false));
        return [
            'file_id' => (string)($r['id'] ?? $fileId),
            'name'    => (string)($r['name'] ?? ''),
            'size'    => isset($r['size']) ? (int)$r['size'] : null,
            'mime'    => $r['mimeType'] ?? null,
            'exists'  => $exists,
        ];
    }

    public function ensureFolder(string $name, ?string $parentId = null): string
    {
        $parent = $parentId ?: $this->rootFolderId;
        $safe = $this->sanitizeName($name);

        // Cherche un dossier existant avec ce nom sous ce parent
        $q = sprintf(
            "name='%s' and mimeType='application/vnd.google-apps.folder' and '%s' in parents and trashed=false",
            addslashes($safe),
            addslashes($parent)
        );
        $url = self::FILES_URL . '?q=' . rawurlencode($q) . '&fields=files(id,name)';
        if ($this->driveId !== null) {
            $url .= '&supportsAllDrives=true&includeItemsFromAllDrives=true&corpora=drive&driveId=' . rawurlencode($this->driveId);
        }
        $r = $this->httpRequest('GET', $url, [
            'Authorization: Bearer ' . $this->getAccessToken(),
        ]);
        if (!empty($r['files'][0]['id'])) {
            return (string)$r['files'][0]['id'];
        }

        // Sinon création
        $createUrl = self::FILES_URL . '?fields=id';
        if ($this->driveId !== null) $createUrl .= '&supportsAllDrives=true';
        $r2 = $this->httpRequest('POST', $createUrl, [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Content-Type: application/json',
        ], json_encode([
            'name'     => $safe,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parent],
        ]));
        if (empty($r2['id'])) {
            throw new RuntimeException('Drive ensureFolder : id manquant en réponse');
        }
        return (string)$r2['id'];
    }

    // ── OAuth ───────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExp - 60) {
            return $this->accessToken;
        }
        $body = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        $r = $this->httpRequest('POST', self::TOKEN_URL, [
            'Content-Type: application/x-www-form-urlencoded',
        ], $body);
        if (empty($r['access_token'])) {
            throw new RuntimeException('Drive OAuth : access_token manquant');
        }
        $this->accessToken    = (string)$r['access_token'];
        $this->accessTokenExp = time() + (int)($r['expires_in'] ?? 3600);
        return $this->accessToken;
    }

    /**
     * Wrapper cURL JSON. Lève RuntimeException si HTTP != 2xx.
     * @return array<string,mixed>
     */
    private function httpRequest(string $method, string $url, array $headers = [], $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException("cURL: {$err}");
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Drive HTTP {$code}: " . substr((string)$raw, 0, 500));
        }
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : [];
    }

    private function sanitizeName(string $name): string
    {
        $name = str_replace(["\r", "\n", "\t"], ' ', $name);
        $name = preg_replace('/[\\\\\/]/', '_', $name) ?? $name;
        return mb_substr(trim($name), 0, 250);
    }
}
