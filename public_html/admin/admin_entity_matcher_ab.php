<?php
/**
 * admin/admin_entity_matcher_ab.php
 *
 * Comparaison A/B : legacy vs moteur central, sans toucher au flag.
 *
 * Pour chaque cas test, on appelle DIRECTEMENT :
 *  - le code legacy (en simulant FEATURE_ENTITY_MATCHER=OFF) → résultat legacy
 *  - le moteur central                                       → résultat moteur
 * Et on affiche un diff côte à côte.
 *
 * À utiliser pour valider que la migration est iso-résultat (ou comprendre
 * les écarts) avant de basculer le flag.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/entity_matcher.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = $GLOBALS['pdo'];

// ─── Helpers ─────────────────────────────────────────────────────────

/** Appel direct du moteur (résultats moteur central). */
function ab_moteur_tiers(PDO $pdo, array $data, ?int $exclude = null): array {
    $r = em_match_tiers($pdo, $data, null, $exclude);
    return [
        'count' => count($r['matches'] ?? []),
        'top_score' => $r['confidence'] ?? 0,
        'match_type' => $r['match_type'] ?? 'unknown',
        'best_id' => $r['best']['id'] ?? null,
        'best_label' => $r['best']['nom_affichage'] ?? '',
        'reasons' => $r['best']['reasons'] ?? [],
        'can_create' => $r['can_create'] ?? null,
    ];
}

/** Simule l'algo legacy de bailleur_check_duplicate (en local, sans HTTP). */
function ab_legacy_bailleur(PDO $pdo, array $post, ?int $exclude = null, ?int $agenceId = null, int $roleId = 1): array {
    $norm = static function (string $s): string {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower(trim($s));
        return preg_replace('/\s+/', ' ', $s) ?? '';
    };
    $normPhone = static function (string $s): string {
        return substr(preg_replace('/[^0-9]/', '', $s) ?? '', -9);
    };
    $nom    = $norm($post['nom']     ?? '');
    $prenom = $norm($post['prenom']  ?? '');
    $societe= $norm($post['societe'] ?? '');
    $email  = $norm($post['email']   ?? '');
    $tel    = $normPhone($post['telephone'] ?? '');
    $siret  = preg_replace('/[^0-9]/', '', $post['siret'] ?? '') ?? '';

    $where = ['p.actif = 1']; $params = [];
    if ($agenceId && $roleId !== 7) { $where[] = '(p.id_agence = :ag OR p.id_agence IS NULL)'; $params[':ag'] = $agenceId; }
    if ($exclude)                   { $where[] = 'p.id <> :ex'; $params[':ex'] = $exclude; }

    $or = [];
    if ($email !== '')   { $or[] = 'LOWER(p.email) LIKE :em'; $params[':em'] = '%' . $email . '%'; }
    if ($tel !== '')     { $or[] = 'REPLACE(REPLACE(REPLACE(REPLACE(p.telephone, " ", ""), ".", ""), "-", ""), "+", "") LIKE :tl'; $params[':tl'] = '%' . $tel . '%'; }
    if ($nom !== '')     { $or[] = 'LOWER(p.nom) LIKE :nm'; $params[':nm'] = '%' . $nom . '%'; }
    if ($prenom !== '')  { $or[] = 'LOWER(p.prenom) LIKE :pn'; $params[':pn'] = '%' . $prenom . '%'; }
    if ($societe !== '') { $or[] = 'LOWER(p.societe) LIKE :sc'; $params[':sc'] = '%' . $societe . '%'; }
    if ($siret !== '')   { $or[] = 'p.siret = :si'; $params[':si'] = $siret; }
    if (empty($or)) return ['count' => 0, 'top_score' => 0, 'best_id' => null, 'best_label' => '', 'reasons' => [], 'match_type' => 'unknown', 'can_create' => true];
    $where[] = '(' . implode(' OR ', $or) . ')';

    $hasSiret = (bool)$pdo->query("SHOW COLUMNS FROM proprietaires LIKE 'siret'")->fetchColumn();
    if (!$hasSiret && isset($params[':si'])) unset($params[':si']);
    $selSiret = $hasSiret ? 'p.siret' : 'NULL AS siret';

    $sql = "SELECT p.id, p.nom, p.prenom, p.societe, p.email, p.telephone, $selSiret FROM proprietaires p WHERE " . implode(' AND ', $where) . " LIMIT 30";
    try {
        $st = $pdo->prepare($sql); $st->execute($params); $cands = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    $matches = [];
    foreach ($cands as $c) {
        $score = 0; $reasons = [];
        $cNom = $norm((string)$c['nom']); $cPre = $norm((string)$c['prenom']);
        $cSoc = $norm((string)$c['societe']); $cMail = $norm((string)$c['email']);
        $cTel = $normPhone((string)$c['telephone']);
        $cSiret = preg_replace('/[^0-9]/', '', (string)$c['siret']) ?? '';
        if ($siret !== '' && $cSiret !== '' && $siret === $cSiret) { $score += 80; $reasons[] = 'SIRET'; }
        if ($email !== '' && $cMail === $email) { $score += 70; $reasons[] = 'Email'; }
        if ($tel !== '' && $cTel === $tel) { $score += 50; $reasons[] = 'Tel'; }
        if ($societe !== '' && $cSoc === $societe) { $score += 30; $reasons[] = 'Société'; }
        if ($nom !== '' && $cNom === $nom) { $score += 20; $reasons[] = 'Nom'; }
        if ($prenom !== '' && $cPre === $prenom) { $score += 10; $reasons[] = 'Prénom'; }
        if ($score >= 30) $matches[] = ['id' => (int)$c['id'], 'score' => $score, 'reasons' => $reasons, 'label' => trim(($c['societe'] ?: ($c['prenom'] . ' ' . $c['nom'])))];
    }
    usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);
    $best = $matches[0] ?? null;
    return [
        'count' => count($matches),
        'top_score' => $best['score'] ?? 0,
        'best_id' => $best['id'] ?? null,
        'best_label' => $best['label'] ?? '',
        'reasons' => $best['reasons'] ?? [],
        'match_type' => 'legacy',
        'can_create' => ($best['score'] ?? 0) < 60,
    ];
}

// ─── Cas tests (auto-générés depuis la BDD) ──────────────────────────
$cases = [];
try {
    $st = $pdo->query("SELECT nom, prenom, email, telephone, societe FROM proprietaires WHERE actif = 1 LIMIT 5");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $i => $row) {
        $cases[] = ['label' => 'Cas #' . ($i+1) . ' (proprio réel: ' . substr($row['nom'] ?: $row['societe'] ?: 'N/A', 0, 30) . ')', 'data' => array_filter($row)];
    }
} catch (Throwable $e) {
    $cases[] = ['label' => 'erreur sample', 'data' => []];
}

if (empty($cases)) {
    $cases[] = ['label' => 'Cas test générique', 'data' => ['nom' => 'DUPONT']];
}

// ─── Exécution ───────────────────────────────────────────────────────
$rows = [];
foreach ($cases as $case) {
    $data = $case['data'];
    // Mapping proprietaire → tiers : societe → raison_sociale pour le moteur
    $moteurData = $data;
    if (isset($moteurData['societe'])) { $moteurData['raison_sociale'] = $moteurData['societe']; }
    $rows[] = [
        'case'   => $case,
        'legacy' => ab_legacy_bailleur($pdo, $data, null, (int)($_SESSION['id_agence'] ?? 0), (int)($_SESSION['id_role'] ?? 0)),
        'moteur' => ab_moteur_tiers($pdo, $moteurData, null),
    ];
}

$flagOn = defined('FEATURE_ENTITY_MATCHER') && FEATURE_ENTITY_MATCHER;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>A/B legacy vs entity_matcher</title>
    <style>
        body { font-family: "DM Mono", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1400px; margin: 0 auto; }
        h1 { color: #fde68a; margin: 0 0 6px; }
        h2 { color: #84a98c; font-size: 14px; margin: 18px 0 6px; }
        .flag { padding: 8px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .flag.on { background: #15803d; color: #fff; }
        .flag.off { background: #94a3b8; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; margin-bottom: 22px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #334155; vertical-align: top; }
        th { background: #1e293b; color: #94a3b8; font-weight: 600; }
        .col-legacy { background: #1e293b; }
        .col-moteur { background: #0c2030; }
        .diff { color: #fde68a; font-weight: 700; }
        .same { color: #84a98c; }
        .case-data { font-family: monospace; font-size: 10.5px; color: #94a3b8; background: #0c2030; padding: 6px 10px; border-radius: 4px; margin-top: 4px; }
        pre { margin: 4px 0; font-size: 10.5px; }
    </style>
</head>
<body>

<h1>🔬 A/B legacy vs entity_matcher — Sprint 2A</h1>
<p>Comparaison directe (sans appel HTTP) entre l'algorithme legacy de
<code>bailleur_check_duplicate.php</code> et le moteur central <code>em_match_tiers()</code>.</p>

<div class="flag <?= $flagOn ? 'on' : 'off' ?>">
    FEATURE_ENTITY_MATCHER = <strong><?= $flagOn ? 'ON' : 'OFF' ?></strong>
    — <?= $flagOn ? 'Les wrappers API délèguent au moteur en prod.' : 'Comportement legacy actif. Le moteur reste appelable directement via les APIs <code>tiers_check_duplicate.php</code> et <code>immeuble_check_duplicate.php</code>.' ?>
</div>

<?php foreach ($rows as $r):
    $case = $r['case']; $legacy = $r['legacy']; $moteur = $r['moteur'];
    $sameId   = ($legacy['best_id'] ?? null) === ($moteur['best_id'] ?? null);
    $sameCount= ($legacy['count'] ?? 0) === ($moteur['count'] ?? 0);
?>
    <h2><?= htmlspecialchars($case['label']) ?></h2>
    <div class="case-data">Input : <?= htmlspecialchars(json_encode($case['data'], JSON_UNESCAPED_UNICODE)) ?></div>
    <table>
        <thead>
            <tr><th></th><th class="col-legacy">LEGACY (bailleur_check_duplicate)</th><th class="col-moteur">MOTEUR (em_match_tiers)</th></tr>
        </thead>
        <tbody>
            <tr><td>count</td>
                <td class="col-legacy"><?= $legacy['count'] ?? '?' ?></td>
                <td class="col-moteur <?= !$sameCount ? 'diff' : 'same' ?>"><?= $moteur['count'] ?? '?' ?></td></tr>
            <tr><td>top score</td>
                <td class="col-legacy"><?= $legacy['top_score'] ?? '?' ?></td>
                <td class="col-moteur <?= ($legacy['top_score'] ?? 0) !== ($moteur['top_score'] ?? 0) ? 'diff' : 'same' ?>"><?= $moteur['top_score'] ?? '?' ?></td></tr>
            <tr><td>best id</td>
                <td class="col-legacy"><?= $legacy['best_id'] ?? '—' ?></td>
                <td class="col-moteur <?= !$sameId ? 'diff' : 'same' ?>"><?= $moteur['best_id'] ?? '—' ?></td></tr>
            <tr><td>best label</td>
                <td class="col-legacy"><?= htmlspecialchars($legacy['best_label'] ?? '') ?></td>
                <td class="col-moteur"><?= htmlspecialchars($moteur['best_label'] ?? '') ?></td></tr>
            <tr><td>reasons</td>
                <td class="col-legacy"><pre><?= htmlspecialchars(json_encode($legacy['reasons'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></td>
                <td class="col-moteur"><pre><?= htmlspecialchars(json_encode($moteur['reasons'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></td></tr>
            <tr><td>can_create</td>
                <td class="col-legacy"><?= isset($legacy['can_create']) ? ($legacy['can_create'] ? 'Y' : 'N') : '?' ?></td>
                <td class="col-moteur"><?= isset($moteur['can_create']) ? ($moteur['can_create'] ? 'Y' : 'N') : '?' ?></td></tr>
        </tbody>
    </table>
<?php endforeach; ?>

<h2>📌 Note importante : sources de données différentes</h2>
<ul style="font-size: 12px; color: #94a3b8;">
    <li><strong>Legacy</strong> requête la table <code>proprietaires</code> (legacy).</li>
    <li><strong>Moteur</strong> requête la table <code>tiers</code> (architecture 2026-04-19 figée).</li>
    <li>Donc des écarts de count/best_id sont <strong>attendus</strong> tant que la migration
        proprietaires → tiers n'est pas totale. Le scoring lui est plus solide côté moteur
        (normalisation déterministe + barème Sprint 1B).</li>
    <li>Pour la migration en prod : viser une période où proprietaires + tiers sont cohérents,
        ou faire une bascule progressive avec monitoring.</li>
</ul>

</body>
</html>
