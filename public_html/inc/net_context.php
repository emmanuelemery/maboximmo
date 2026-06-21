<?php
declare(strict_types=1);

/**
 * Ma Box Net — Contexte de vitrine par AGENCE (sous-domaine).
 * ───────────────────────────────────────────────────────────
 * Chaque agence dispose d'un sous-domaine dédié servant le MÊME portail
 * d'annonces (offre globale, non filtrée) avec un habillage + un contenu
 * éditorial LOCAL différencié (anti contenu-dupliqué SEO).
 *
 *   regie-emery-lyon.maboximmo.fr        → agence #3
 *   regie-emery-vienne.maboximmo.fr      → agence #1
 *   ...
 *
 * En local/dev, on simule via ?net_agence=ID (ou ?agence=ID) sur n'importe
 * quelle page.  Sur le domaine principal maboximmo.fr (sans sous-domaine
 * reconnu), net_context() renvoie null → portail générique MaBoxImmo inchangé.
 *
 * AUCUN filtrage des annonces : le contexte ne touche QUE le branding et
 * les pages éditoriales. La liste de biens reste globale.
 */

/**
 * Table de correspondance SOUS-DOSSIER → id_agence.
 * Format : "{societe}/{ville}" (tirets, minuscules). Choisi pour le SEO :
 * les sous-dossiers concentrent l'autorité sur le domaine unique maboximmo.fr,
 * regroupés par marque (regie-emery / emery-immo).
 * Sociétés 1 (Régie EMERY) et 2 (EMERY IMMO) uniquement.
 *
 * @return array<string,int>
 */
function net_path_map(): array
{
    return [
        'regie-emery/vienne'      => 1,
        'regie-emery/mions'       => 2,
        'regie-emery/lyon-7'      => 3,
        'regie-emery/chaponost'   => 4,
        'emery-immo/riom'         => 5,
        'emery-immo/chamalieres'  => 6,
    ];
}

/**
 * Préfixe de sous-dossier agence injecté par le .htaccess (NET_PREFIX),
 * normalisé sans slash de bord. '' si on n'est pas sous un sous-dossier.
 */
function net_prefix_from_request(): string
{
    foreach (['REDIRECT_REDIRECT_NET_PREFIX', 'REDIRECT_NET_PREFIX', 'NET_PREFIX'] as $k) {
        if (!empty($_SERVER[$k])) return strtolower(trim((string)$_SERVER[$k], '/'));
    }
    return '';
}

/**
 * Résout le contexte de vitrine courant.
 *
 * @return array{
 *   id_agence:int, id_societe:int, nom:string, ville:string,
 *   logo_url:?string, agence:array, societe:array
 * }|null  null si pas de contexte vitrine (= portail générique).
 */
function net_context(?PDO $pdo = null): ?array
{
    static $resolved = false;
    static $ctx = null;
    if ($resolved) return $ctx;
    $resolved = true;

    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) return null;

    // 1) sous-dossier (prod, via .htaccess NET_PREFIX) ; 2) override ?net_agence= (dev/local)
    $prefix   = net_prefix_from_request();
    $idAgence = $prefix !== '' ? (net_path_map()[$prefix] ?? 0) : 0;
    if ($idAgence === 0) {
        $ov = $_GET['net_agence'] ?? $_GET['agence'] ?? '';
        if (ctype_digit((string)$ov)) $idAgence = (int)$ov;
    }
    if ($idAgence === 0) return null;

    try {
        $stmt = $pdo->prepare("SELECT * FROM agences WHERE id = ? AND id_societe IN (1,2) LIMIT 1");
        $stmt->execute([$idAgence]);
        $agence = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) { $agence = null; }
    if (!$agence) return null;

    $societe = [];
    try {
        $s = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
        $s->execute([(int)$agence['id_societe']]);
        $societe = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}

    // Logo : agence prioritaire, fallback logo société.
    $logo = $agence['logo_url'] ?: ($agence['logo_path'] ?? null);
    if (!$logo) $logo = $societe['logo_url'] ?? null;

    $ctx = [
        'id_agence'  => (int)$agence['id'],
        'id_societe' => (int)$agence['id_societe'],
        'nom'        => (string)($agence['nom_agence'] ?? 'Notre agence'),
        'ville'      => (string)($agence['ville'] ?? ''),
        'logo_url'   => $logo,
        'prefix'     => $prefix,   // sous-dossier agence ('' en mode override local)
        'agence'     => $agence,
        'societe'    => $societe,
    ];
    return $ctx;
}

/**
 * Périmètre net : sociétés ayant des annonces (vitrines).
 * @return int[]
 */
function net_societes_perimetre(): array { return [1, 2]; }

/**
 * Droit d'éditer les vitrines : managers (role 1 super admin, role 2 société).
 */
function net_admin_can(): bool
{
    $r = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
    return in_array($r, [1, 2], true);
}

/**
 * Liste des agences que l'utilisateur courant peut gérer (périmètre net).
 * - role 1 (super admin) : toutes les agences des sociétés du périmètre.
 * - role 2 (manager société) : uniquement les agences de SA société.
 *
 * @return array<int,array{id:int,nom_agence:string,id_societe:int}>
 */
function net_admin_agences(PDO $pdo): array
{
    $perim = net_societes_perimetre();
    $in = implode(',', array_map('intval', $perim));
    $role = function_exists('current_role_id') ? (int)current_role_id() : 0;
    $soc  = function_exists('current_societe_id') ? current_societe_id() : null;

    $sql = "SELECT id, nom_agence, id_societe FROM agences WHERE id_societe IN ($in)";
    $params = [];
    if ($role !== 1) { // pas super admin → restreint à sa société
        $sql .= " AND id_societe = ?";
        $params[] = (int)$soc;
    }
    $sql .= " ORDER BY id_societe, nom_agence";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { return []; }
}

/**
 * Vérifie qu'une agence est bien dans le périmètre éditable de l'utilisateur.
 */
function net_admin_agence_autorisee(PDO $pdo, int $idAgence): bool
{
    foreach (net_admin_agences($pdo) as $a) {
        if ((int)$a['id'] === $idAgence) return true;
    }
    return false;
}

/**
 * Récupère le contenu éditorial local d'une page pour l'agence en contexte.
 *
 * @return array{titre:?string,contenu_html:?string,meta_title:?string,meta_description:?string}|null
 */
function net_page(PDO $pdo, int $idAgence, string $pageKey): ?array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT titre, contenu_html, meta_title, meta_description
             FROM agence_net_page
             WHERE id_agence = ? AND page_key = ? AND actif = 1 LIMIT 1"
        );
        $stmt->execute([$idAgence, $pageKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        return null;
    }
}
