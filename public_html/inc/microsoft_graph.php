<?php
declare(strict_types=1);

/**
 * inc/microsoft_graph.php
 * ------------------------------------------------------------------
 * Client minimal Microsoft Graph / OneDrive pour MaBoxImmo.
 *
 * Authentification : OAuth2 client_credentials (accès serveur).
 * Drive cible      : /users/{GRAPH_ONEDRIVE_USER}/drive
 *                    (/me ne fonctionne pas en client_credentials).
 *
 * Sécurité :
 *  - les secrets viennent de la config hors HTML (constantes GRAPH_*) ;
 *  - aucune fonction n'émet le token ni le secret ;
 *  - en cas d'erreur on lève une RuntimeException (à logger côté appelant).
 *
 * Dépend de constantes définies par la config :
 *   GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, GRAPH_ONEDRIVE_USER
 */

// ── Chargement de la configuration (premier candidat trouvé gagne) ──
(function (): void {
    if (defined('GRAPH_TENANT_ID')) {
        return; // déjà chargée
    }
    $candidates = [
        // Hors webroot (prod / dev)
        '/home/u630423897/microsoft_graph_config.php',
        __DIR__ . '/../../u630423897/microsoft_graph_config.php',
        // Dans config/ (local, non versionné)
        __DIR__ . '/../config/microsoft_graph.local.php',
        // Modèle (valeurs factices, dernier recours)
        __DIR__ . '/../config/microsoft_graph.example.php',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            require_once $candidate;
            break;
        }
    }
})();

if (!defined('GRAPH_ONEDRIVE_USER')) {
    define('GRAPH_ONEDRIVE_USER', '');
}

/**
 * Indique si la configuration Graph est exploitable (valeurs non factices).
 */
function graph_is_configured(): bool
{
    foreach (['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET'] as $c) {
        if (!defined($c)) {
            return false;
        }
        $v = (string)constant($c);
        if ($v === '' || str_starts_with($v, 'REMPLACER_')) {
            return false;
        }
    }
    return true;
}

/**
 * Tenant utilisé (pour affichage état de connexion).
 */
function graph_tenant(): string
{
    return defined('GRAPH_TENANT_ID') ? (string)GRAPH_TENANT_ID : '';
}

/**
 * Récupère un access_token via client_credentials.
 * Mémoïsé en statique pour la durée de la requête PHP.
 *
 * @throws RuntimeException si la config manque ou si la requête échoue.
 */
function graph_get_access_token(): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    if (!graph_is_configured()) {
        throw new RuntimeException('Configuration Microsoft Graph absente ou incomplète.');
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode((string)GRAPH_TENANT_ID) . '/oauth2/v2.0/token';
    $post = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => (string)GRAPH_CLIENT_ID,
        'client_secret' => (string)GRAPH_CLIENT_SECRET,
        'scope'         => 'https://graph.microsoft.com/.default',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp   = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Échec réseau token Graph : ' . $err);
    }
    $data = json_decode((string)$resp, true);
    if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        $desc = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'erreur inconnue') : 'réponse invalide';
        // On ne renvoie jamais le secret ; on tronque la description.
        throw new RuntimeException('Token Graph refusé (HTTP ' . $status . ') : ' . substr((string)$desc, 0, 300));
    }

    $token = (string)$data['access_token'];
    return $token;
}

/**
 * Requête générique vers Microsoft Graph.
 *
 * @param string      $method GET, POST, ...
 * @param string      $url    URL absolue Graph OU chemin relatif débutant par /
 * @param array|null  $body   corps JSON (encodé automatiquement)
 * @return array decoded JSON ['status' => int, 'data' => mixed]
 * @throws RuntimeException en cas d'échec réseau.
 */
function graph_request(string $method, string $url, ?array $body = null): array
{
    $token = graph_get_access_token();

    if (!str_starts_with($url, 'http')) {
        $url = 'https://graph.microsoft.com/v1.0' . $url;
    }

    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp   = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Échec réseau Graph : ' . $err);
    }
    $data = json_decode((string)$resp, true);
    return ['status' => $status, 'data' => $data];
}

/**
 * Définit l'UPN du drive cible pour la requête en cours (override runtime).
 * Permet de basculer entre plusieurs drives (perso / métier) sans toucher
 * la config. Passe '' pour revenir au drive par défaut (GRAPH_ONEDRIVE_USER).
 */
function graph_set_drive_user(string $upn): void
{
    $GLOBALS['__graph_drive_user'] = $upn;
}

/**
 * Préfixe du drive cible. En client_credentials, /me est indisponible :
 * on utilise /users/{UPN}/drive. La fonction tente /me d'abord uniquement
 * si demandé, sinon bascule directement sur l'UPN configuré.
 */
function graph_drive_prefix(): string
{
    $upn = (string)($GLOBALS['__graph_drive_user'] ?? '');
    if ($upn === '') {
        $upn = (string)GRAPH_ONEDRIVE_USER;
    }
    if ($upn === '') {
        // Tentative /me (échouera probablement en client_credentials).
        return '/me/drive';
    }
    return '/users/' . rawurlencode($upn) . '/drive';
}

/**
 * Normalise la réponse Graph d'une collection en levant si erreur HTTP.
 */
function graph_unwrap(array $res, string $context): array
{
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $d = $res['data'];
        $msg = is_array($d) ? ($d['error']['message'] ?? 'erreur inconnue') : 'réponse invalide';
        throw new RuntimeException($context . ' (HTTP ' . $res['status'] . ') : ' . substr((string)$msg, 0, 300));
    }
    return is_array($res['data']) ? $res['data'] : [];
}

/**
 * Liste le contenu de la racine du OneDrive cible (max 100).
 */
function graph_list_root(): array
{
    $res = graph_request('GET', graph_drive_prefix() . '/root/children?$top=100');
    return graph_unwrap($res, 'Lecture racine OneDrive')['value'] ?? [];
}

/**
 * Liste TOUT le contenu d'un dossier (suit la pagination @odata.nextLink).
 * À utiliser pour les dossiers de plus de 100 éléments (ex : PROPRIETAIRES).
 * @param string $path chemin relatif à la racine ('' = racine)
 */
function graph_list_all_children_by_path(string $path): array
{
    $path = trim($path, '/');
    if ($path === '') {
        $url = graph_drive_prefix() . '/root/children?$top=200';
    } else {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = graph_drive_prefix() . '/root:/' . $encoded . ':/children?$top=200';
    }

    $all = [];
    $guard = 0; // garde-fou anti-boucle (200 * 50 = 10000 éléments max)
    while ($url !== null && $guard < 50) {
        $res  = graph_request('GET', $url);
        $data = graph_unwrap($res, 'Lecture dossier paginée');
        foreach (($data['value'] ?? []) as $it) {
            $all[] = $it;
        }
        $url = $data['@odata.nextLink'] ?? null; // URL absolue → graph_request la garde telle quelle
        $guard++;
    }
    return $all;
}

/**
 * Liste le contenu d'un dossier par son chemin relatif à la racine.
 * @param string $path ex: "01_SERVICE_GESTION/05_RIOM GESTION"
 */
function graph_list_children_by_path(string $path): array
{
    $path = trim($path, '/');
    if ($path === '') {
        return graph_list_root();
    }
    // Encodage segment par segment (les ':' délimitent la syntaxe Graph).
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    $url = graph_drive_prefix() . '/root:/' . $encoded . ':/children?$top=100';
    $res = graph_request('GET', $url);
    return graph_unwrap($res, 'Lecture dossier (chemin)')['value'] ?? [];
}

/**
 * Liste le contenu d'un dossier par son item_id.
 */
function graph_list_children_by_item_id(string $itemId): array
{
    $url = graph_drive_prefix() . '/items/' . rawurlencode($itemId) . '/children?$top=100';
    $res = graph_request('GET', $url);
    return graph_unwrap($res, 'Lecture dossier (item_id)')['value'] ?? [];
}

/**
 * Liste TOUT le contenu d'un dossier par item_id (suit la pagination).
 * À utiliser pour les dossiers de plus de 100 éléments.
 */
function graph_list_all_children_by_item_id(string $itemId): array
{
    $url = graph_drive_prefix() . '/items/' . rawurlencode($itemId) . '/children?$top=200';
    $all = [];
    $guard = 0;
    while ($url !== null && $guard < 50) {
        $res  = graph_request('GET', $url);
        $data = graph_unwrap($res, 'Lecture dossier paginée (item_id)');
        foreach (($data['value'] ?? []) as $it) {
            $all[] = $it;
        }
        $url = $data['@odata.nextLink'] ?? null;
        $guard++;
    }
    return $all;
}

/**
 * Recherche dans le drive cible (max 100).
 */
function graph_search_drive(string $query): array
{
    $q = str_replace("'", "''", $query); // échappement OData
    $url = graph_drive_prefix() . "/root/search(q='" . rawurlencode($q) . "')?\$top=100";
    $res = graph_request('GET', $url);
    return graph_unwrap($res, 'Recherche OneDrive')['value'] ?? [];
}

/**
 * Métadonnées d'un item (fichier/dossier) par item_id.
 */
function graph_get_item_metadata(string $itemId): array
{
    $url = graph_drive_prefix() . '/items/' . rawurlencode($itemId);
    $res = graph_request('GET', $url);
    return graph_unwrap($res, 'Métadonnées item');
}

/**
 * Télécharge le contenu binaire d'un fichier OneDrive par item_id.
 *
 * Récupère d'abord l'URL de téléchargement pré-signée (@microsoft.graph.downloadUrl)
 * via les métadonnées de l'item — cette URL ne nécessite pas de header Authorization
 * et évite de suivre une redirection 302 sur l'endpoint /content.
 *
 * @return array {
 *   ok:        bool,
 *   content:   string,   // bytes du fichier (si ok)
 *   name:      string,   // nom OneDrive
 *   mime_type: string,
 *   size:      int,
 *   error?:    string,
 * }
 */
function graph_download_file_content(string $itemId): array
{
    $meta = graph_get_item_metadata($itemId);
    $dlUrl = (string)($meta['@microsoft.graph.downloadUrl'] ?? '');
    $name  = (string)($meta['name'] ?? ('document_' . $itemId));
    $mime  = (string)($meta['file']['mimeType'] ?? 'application/octet-stream');
    $size  = (int)($meta['size'] ?? 0);

    if ($dlUrl === '') {
        return ['ok' => false, 'error' => 'URL de téléchargement indisponible (item non-fichier ?)'];
    }

    $ch = curl_init($dlUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body   = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Échec réseau téléchargement : ' . $err];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'Téléchargement refusé (HTTP ' . $status . ')'];
    }

    return [
        'ok'        => true,
        'content'   => (string)$body,
        'name'      => $name,
        'mime_type' => $mime,
        'size'      => $size ?: strlen((string)$body),
    ];
}
