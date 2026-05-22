<?php
// api/ia_contexte_ask.php — IA contextuelle Claude Haiku pour les fiches 360°
// Reçoit : entity_type (bien|immeuble|tiers|bail) + entity_id + question
// Charge le contexte adapté + appelle Claude Haiku 4.5 + retourne JSON
declare(strict_types=1);

@ini_set('display_errors', '0');
@set_time_limit(120);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/mbi_supports_score_ia.php'; // → mbi_supports_ia_anthropic_key()
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$entityType = trim((string)(post('entity_type') ?? ''));
$entityId   = (int)(post('entity_id') ?? 0);
$question   = trim((string)(post('question') ?? ''));

if ($entityId <= 0)  { echo json_encode(['ok'=>false,'error'=>'entity_id manquant']); exit; }
if ($question === '') { echo json_encode(['ok'=>false,'error'=>'question vide']); exit; }
if (!in_array($entityType, ['bien','immeuble','tiers','bail'], true)) {
    echo json_encode(['ok'=>false,'error'=>'entity_type invalide']); exit;
}

$apiKey = mbi_supports_ia_anthropic_key();
if ($apiKey === '') { echo json_encode(['ok'=>false,'error'=>'clé Anthropic absente']); exit; }

// ─── Charge le CONTEXTE selon l'entité ──────────────────────────────
function build_contexte_bien(PDO $pdo, int $id): string {
    $st = $pdo->prepare("SELECT b.*, i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
        COALESCE(p.societe, CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom,
        p.email AS proprio_email
        FROM biens b
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE b.id = ? LIMIT 1");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return '';

    $ctx = "BIEN #{$id}\n"
         . "Référence : {$b['reference_bien']}\n"
         . "Désignation : {$b['designation']}\n"
         . "Adresse : " . trim(($b['adresse_1'] ?? '') . ' ' . ($b['code_postal'] ?? '') . ' ' . ($b['ville'] ?? '')) . "\n"
         . "Statut : {$b['statut_bien']} · {$b['statut_occupation']}\n"
         . "Type commercialisation : " . ($b['type_commercialisation'] ?? 'aucun') . "\n"
         . "Usage : " . ($b['usage_bien'] ?? '?') . "\n"
         . "Surface habitable : " . ($b['surface_habitable'] ?? '?') . " m²\n"
         . "Nb pièces : " . ($b['nb_pieces'] ?? '?') . "\n"
         . "DPE : " . ($b['dpe_classe'] ?? '?') . " · GES : " . ($b['ges_classe'] ?? '?') . "\n"
         . "Loyer HC mensuel : " . ($b['loyer_hc'] ?? '?') . " €\n"
         . "Prix demandé : " . ($b['prix_demande_initial'] ?? $b['prix_vente_estime'] ?? '?') . " €\n"
         . "Propriétaire : " . ($b['proprio_nom'] ?? '?') . "\n"
         . "Immeuble : " . ($b['nom_immeuble'] ?? '?') . " (" . ($b['imm_ville'] ?? '') . ")\n";

    // Bail actif
    try {
        $stB = $pdo->prepare('SELECT * FROM bien_baux WHERE id_bien = ? AND statut = "actif" ORDER BY date_prise_effet DESC LIMIT 1');
        $stB->execute([$id]);
        $bail = $stB->fetch(PDO::FETCH_ASSOC);
        if ($bail) {
            $ctx .= "\nBAIL ACTIF :\n"
                  . "  Nature : {$bail['bail_nature']}\n"
                  . "  Locataire : " . ($bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'] . ' ' . $bail['locataire_nom'])) . "\n"
                  . "  Période : {$bail['date_prise_effet']} → {$bail['date_fin']}\n"
                  . "  Loyer mensuel HC : {$bail['loyer_mensuel_hc']} €\n"
                  . "  Charges mensuelles : {$bail['charges_mensuelles']} €\n"
                  . "  Dépôt garantie : {$bail['depot_garantie']} €\n"
                  . "  Indice : {$bail['indice_type']} ({$bail['indice_trimestre']})\n";
        } else {
            $ctx .= "\nBAIL : Aucun bail actif.\n";
        }
    } catch (Throwable $e) {}

    // Offres en cours
    try {
        $stO = $pdo->prepare("SELECT COUNT(*) AS n, MAX(prix_propose) AS meilleure FROM leads_annonces
            WHERE id_bien = ? AND type_contact = 'offre' AND (statut_offre IS NULL OR statut_offre NOT IN ('refusee','expiree'))");
        $stO->execute([$id]);
        $o = $stO->fetch(PDO::FETCH_ASSOC);
        if ($o && $o['n'] > 0) {
            $ctx .= "\nOFFRES ACTIVES : {$o['n']} (meilleure : {$o['meilleure']} €)\n";
        }
    } catch (Throwable $e) {}

    // Docs GED
    try {
        $stD = $pdo->prepare("SELECT document_type, COUNT(*) AS n FROM ged_documents
            WHERE status='active' AND source_module='05_TRANSACTION'
              AND JSON_EXTRACT(metadata,'$.classement.bien_id_bdd') = ?
            GROUP BY document_type");
        $stD->execute([$id]);
        $docs = $stD->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($docs)) {
            $ctx .= "\nDOCUMENTS GED :\n";
            foreach ($docs as $d) $ctx .= "  - {$d['document_type']} × {$d['n']}\n";
        }
    } catch (Throwable $e) {}

    return $ctx;
}

function build_contexte_immeuble(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT * FROM immeubles WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $i = $st->fetch(PDO::FETCH_ASSOC);
    if (!$i) return '';
    $ctx = "IMMEUBLE #{$id} : {$i['nom_immeuble']}\n"
         . "Adresse : " . trim(($i['adresse_1'] ?? '') . ' ' . ($i['code_postal'] ?? '') . ' ' . ($i['ville'] ?? '')) . "\n"
         . "Type : " . ($i['type_immeuble'] ?? '?') . " · " . ($i['mode_gestion'] ?? '?') . "\n"
         . "Nb lots : " . ($i['nb_lots'] ?? '?') . "\n";
    try {
        $stL = $pdo->prepare("SELECT COUNT(*) AS n FROM biens WHERE id_immeuble = ?");
        $stL->execute([$id]);
        $ctx .= "Lots en BDD : " . (int)$stL->fetchColumn() . "\n";
    } catch (Throwable $e) {}
    return $ctx;
}

function build_contexte_tiers(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT * FROM tiers WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return '';
    $nom = $t['raison_sociale'] ?: trim((string)$t['prenom'] . ' ' . $t['nom']);
    return "TIERS #{$id} : {$nom}\n"
         . "Type : {$t['type_tiers']}\n"
         . "Email : " . ($t['email'] ?? '?') . "\n"
         . "SIREN : " . ($t['siren'] ?? '?') . "\n"
         . "Ville : " . ($t['ville'] ?? '?') . "\n";
}

function build_contexte_bail(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT * FROM bien_baux WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return '';
    return "BAIL #{$id}\n"
         . "Nature : {$b['bail_nature']}\n"
         . "Statut : {$b['statut']}\n"
         . "Locataire : " . ($b['locataire_raison_sociale'] ?: trim((string)$b['locataire_prenom'] . ' ' . $b['locataire_nom'])) . "\n"
         . "Période : {$b['date_prise_effet']} → {$b['date_fin']}\n"
         . "Loyer HC : {$b['loyer_mensuel_hc']} €/mois\n"
         . "Charges : {$b['charges_mensuelles']} €/mois\n"
         . "DG : {$b['depot_garantie']} €\n"
         . "Indice : {$b['indice_type']} ({$b['indice_trimestre']})\n";
}

$contexte = match ($entityType) {
    'bien'     => build_contexte_bien($pdo, $entityId),
    'immeuble' => build_contexte_immeuble($pdo, $entityId),
    'tiers'    => build_contexte_tiers($pdo, $entityId),
    'bail'     => build_contexte_bail($pdo, $entityId),
};
if ($contexte === '') { echo json_encode(['ok'=>false,'error'=>'entité introuvable']); exit; }

// ─── Appel Claude Haiku 4.5 ──────────────────────────────────────────
$modele = 'claude-haiku-4-5-20251001';
$systemPrompt = "Tu es un assistant pour un agent immobilier français. Tu réponds de manière CONCISE, factuelle et professionnelle aux questions sur l'entité fournie.\n"
              . "Règles :\n"
              . "- Réponds en français.\n"
              . "- Réponse courte (3-6 lignes max) sauf si la question demande explicitement un détail.\n"
              . "- Si l'info n'est pas dans le contexte, dis-le clairement (ex: 'Le bail ne précise pas X').\n"
              . "- Tu peux calculer (loyer × 12, rendement, %) si les données sont là.\n"
              . "- Pas de markdown lourd. Tu peux utiliser des puces (•) et **gras**.\n";

$userPrompt = "Contexte :\n" . $contexte . "\n\nQuestion : " . $question;

$t0 = microtime(true);
$payload = [
    'model'      => $modele,
    'max_tokens' => 600,
    'system'     => $systemPrompt,
    'messages'   => [['role' => 'user', 'content' => $userPrompt]],
];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 60,
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
$durationMs = (int)round((microtime(true) - $t0) * 1000);

if ($raw === false || $code !== 200) {
    echo json_encode(['ok'=>false,'error'=>"anthropic_http_{$code}: " . ($err ?: substr((string)$raw, 0, 200))]);
    exit;
}

$body = json_decode((string)$raw, true);
$answer = $body['content'][0]['text'] ?? '';
if ($answer === '') { echo json_encode(['ok'=>false,'error'=>'réponse vide']); exit; }

// Calcul coût Haiku 4.5 : input ~$1/MTok, output ~$5/MTok
$usage = $body['usage'] ?? [];
$costEur = ((int)($usage['input_tokens'] ?? 0)) * 0.000001 + ((int)($usage['output_tokens'] ?? 0)) * 0.000005;
$costCent = (int)round($costEur * 100);

// Mini sanitize : convertit **gras** en <strong> et basic markdown
$html = htmlspecialchars($answer, ENT_QUOTES, 'UTF-8');
$html = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $html);
$html = preg_replace('/\*([^*]+)\*/u', '<em>$1</em>', $html);

echo json_encode([
    'ok'            => true,
    'answer'        => $html,
    'model'         => $modele,
    'duration_ms'   => $durationMs,
    'cout_centimes' => $costCent,
    'usage'         => $usage,
], JSON_UNESCAPED_UNICODE);
