<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès réservé aux managers.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON invalide.']);
    exit;
}

$entretienId = (int)($data['entretien_id'] ?? 0);
$rubriqueId  = (int)($data['rubrique_id']  ?? 0);

if ($entretienId <= 0 || $rubriqueId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres manquants.']);
    exit;
}

// Vérifier ownership
try {
    $stmt = $pdo->prepare("SELECT e.manager_id, e.collaborateur_id, u.prenom, u.nom, u.fonction
        FROM rh_entretiens e JOIN users u ON u.id = e.collaborateur_id WHERE e.id = ?");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $entretien = null; }

if (!$entretien || ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

// ── Charger les réponses de la rubrique (critères classiques) ─────────────────
$notesArr   = [];
$tagsArr    = [];
$textesArr  = [];
$posCount   = 0;
$vigCount   = 0;

try {
    $stmtR = $pdo->prepare("
        SELECT r.note, r.texte_final, r.commentaire,
               rt.tonalite, rt.note_cible,
               c.label, c.axe_radar, c.poids_score
        FROM rh_entretien_reponses r
        LEFT JOIN rh_entretien_reponses_types rt ON rt.id = r.reponse_type_id
        LEFT JOIN rh_entretien_criteres c ON c.id = r.critere_id
        WHERE r.entretien_id = ? AND r.rubrique_id = ?
    ");
    $stmtR->execute([$entretienId, $rubriqueId]);
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['note']) $notesArr[] = (int)$row['note'];
        if ($row['tonalite']) $tagsArr[] = $row['tonalite'];
        if ($row['texte_final'] && !is_array(json_decode($row['texte_final'], true))) {
            $textesArr[] = mb_strimwidth($row['texte_final'], 0, 120);
        }
    }
} catch (PDOException $e) { /* ignoré */ }

// ── Charger les réponses aux questions (nouveau système) ──────────────────────
$questionTextes = [];
try {
    $stmtQ = $pdo->prepare("
        SELECT rq.note, rq.texte_libre, o.tags, o.positivite, o.vigilance,
               o.orientation_solution, o.famille_analytique, q.question
        FROM rh_entretien_reponses_questions rq
        JOIN rh_entretien_questions q ON q.id = rq.question_id
        LEFT JOIN rh_entretien_questions_options o ON o.id = rq.option_id
        JOIN rh_entretien_rubriques r ON r.id = q.rubrique_id
        WHERE rq.entretien_id = ? AND r.ordre = ?
    ");
    $stmtQ->execute([$entretienId, $rubriqueId]);
    foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $qr) {
        if ($qr['note']) $notesArr[] = (int)$qr['note'];
        if ($qr['positivite']) $posCount++;
        if ($qr['vigilance'])  $vigCount++;
        if ($qr['tags']) {
            foreach (explode(',', $qr['tags']) as $t) {
                $tagsArr[] = trim($t);
            }
        }
        if ($qr['famille_analytique']) $tagsArr[] = $qr['famille_analytique'];
        if ($qr['texte_libre']) $questionTextes[] = mb_strimwidth($qr['texte_libre'], 0, 100);
    }
} catch (PDOException $e) { /* ignoré */ }

// ── Si aucune donnée suffisante, renvoyer vide ────────────────────────────────
if (empty($notesArr) && empty($tagsArr)) {
    echo json_encode(['success' => true, 'synthese_manager' => '', 'synthese_collab' => '', 'source' => 'empty']);
    exit;
}

// ── Analyser les données ──────────────────────────────────────────────────────
$avgNote   = !empty($notesArr) ? array_sum($notesArr) / count($notesArr) : 3.0;
$tagFreq   = array_count_values($tagsArr);
arsort($tagFreq);
$topTags   = array_slice(array_keys($tagFreq), 0, 6);

$tonalite = match(true) {
    $avgNote >= 4.0 || ($posCount > $vigCount * 2) => 'positif',
    $avgNote <= 2.5 || ($vigCount > $posCount)     => 'vigilance',
    default                                         => 'neutre',
};

// ── Charger la rubrique ───────────────────────────────────────────────────────
$rubriqueNom  = '';
$rubriqueCode = '';
try {
    $stmtRub = $pdo->prepare("SELECT nom, code, objectif FROM rh_entretien_rubriques WHERE ordre = ?");
    $stmtRub->execute([$rubriqueId]);
    $rub = $stmtRub->fetch(PDO::FETCH_ASSOC);
    $rubriqueNom  = $rub['nom']  ?? '';
    $rubriqueCode = $rub['code'] ?? '';
    $rubriqueObj  = $rub['objectif'] ?? '';
} catch (PDOException $e) { }

$collaborateurNom = trim($entretien['prenom'] . ' ' . $entretien['nom']);
$poste            = $entretien['fonction'] ?? '';

// ── Fallback rule-based ───────────────────────────────────────────────────────
function buildFallbackManager(string $rubriqueNom, string $tonalite, float $avgNote, array $topTags): string {
    $noteStr = round($avgNote, 1);
    $hasBesoin    = array_filter($topTags, fn($t) => str_starts_with($t, 'besoin_'));
    $hasCharge    = in_array('charge_elevee', $topTags, true);
    $hasMotiv     = in_array('motivation_basse', $topTags, true) || in_array('motivation_haute', $topTags, true);
    $hasEvo       = in_array('evolution_souhaitee', $topTags, true);

    return match($tonalite) {
        'positif' => "La rubrique « {$rubriqueNom} » présente un profil globalement positif, avec une note moyenne de {$noteStr}/5. " .
                     ($hasEvo ? "Une envie d'évolution est clairement exprimée, ce qui constitue un signal d'engagement fort. " : "Les éléments recueillis témoignent d'une dynamique solide et d'une posture professionnelle affirmée. ") .
                     "Quelques ajustements ponctuels restent possibles, mais la tendance générale est encourageante.",
        'vigilance' => "La rubrique « {$rubriqueNom} » met en évidence des points de vigilance à prendre en compte (moyenne {$noteStr}/5). " .
                       ($hasCharge ? "La charge de travail semble peser significativement sur le quotidien. " : "") .
                       (!empty($hasBesoin) ? "Des besoins spécifiques ont été exprimés et méritent une réponse concrète. " : "") .
                       "Ces éléments constituent une base de travail pour des ajustements ciblés et mesurables.",
        default => "La rubrique « {$rubriqueNom} » révèle une situation équilibrée (moyenne {$noteStr}/5), avec des points forts réels et quelques axes à consolider. " .
                   ($hasMotiv ? "La dimension motivationnelle mérite une attention particulière pour maintenir l'élan. " : "") .
                   "Un suivi régulier permettra de s'assurer que les ajustements identifiés produisent leur effet.",
    };
}

function buildFallbackCollab(string $rubriqueNom, string $tonalite, float $avgNote): string {
    return match($tonalite) {
        'positif' => "Cette étape montre une belle dynamique de votre côté : votre implication et vos points forts sont clairement identifiés. Continuez dans cette direction — vos efforts sont vus et reconnus.",
        'vigilance' => "Cette rubrique a permis de mettre en mots certaines difficultés que vous traversez. C'est une étape importante : nommer les choses, c'est déjà commencer à les résoudre. Des pistes concrètes vont être explorées ensemble.",
        default => "Cet échange a permis de faire un point utile sur cette dimension. Des points forts ont été identifiés, et quelques axes de travail émergent — rien de bloquant, mais des sujets qu'il vaudra la peine de suivre.",
    };
}

// ── OpenAI si disponible ──────────────────────────────────────────────────────
$apiKey = $GLOBALS['OPENAI_API_KEY'] ?? '';
if (!$apiKey) {
    // Essayer les chemins de config secondaires
    foreach ([
        dirname(__DIR__, 2) . '/u630423897/openai_config.php',
        __DIR__ . '/../../u630423897/openai_config.php',
        '/home/u630423897/openai_config.php',
    ] as $p) {
        if (file_exists($p)) { @include $p; break; }
    }
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
}

$model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4o';

$syntheseManager = '';
$syntheseCollab  = '';
$source          = 'local';

if ($apiKey) {
    $tagsStr     = implode(', ', array_slice($topTags, 0, 8));
    $textesStr   = implode(' | ', array_slice(array_merge($textesArr, $questionTextes), 0, 5));
    $tonDescr    = match($tonalite) {
        'positif'   => 'positif — valorisant et encourageant',
        'vigilance' => 'vigilance — bienveillant mais factuel',
        default     => 'neutre — équilibré et constructif',
    };

    $promptManager = <<<PROMPT
Tu es un expert RH spécialisé en entretiens individuels.
Contexte : entretien individuel de {$collaborateurNom}, poste : {$poste}.
Rubrique analysée : « {$rubriqueNom} » — Objectif : {$rubriqueObj}
Note moyenne de la rubrique : {$avgNote}/5.
Tags analytiques principaux : {$tagsStr}.
Extraits de réponses : {$textesStr}
Tonalité détectée : {$tonDescr}.

Rédige une synthèse de rubrique en 2 à 3 phrases, destinée au manager.
Style : analytique, professionnel, factuel, jamais agressif.
Ne pas répéter le nom de la rubrique. Ne pas commencer par "Cette rubrique".
Donne une lecture claire des signaux, identifie les forces et les vigilances.
Maximum 3 phrases. Pas de titre, pas de guillemets, pas d'explication.
PROMPT;

    $promptCollab = <<<PROMPT
Tu es un expert RH spécialisé en entretiens individuels.
Contexte : entretien individuel de {$collaborateurNom}.
Rubrique : « {$rubriqueNom} ».
Tonalité : {$tonDescr}. Note moyenne : {$avgNote}/5.

Rédige une phrase de conclusion destinée au collaborateur.
Style : humain, motivant, jamais condescendant, jamais générique.
Objectif : que le collaborateur se sente écouté et que l'entretien lui apporte quelque chose.
Maximum 2 phrases. Ton bienveillant et sincère. Pas de titre, pas de guillemets.
PROMPT;

    $callAI = function(string $prompt) use ($apiKey, $model): ?string {
        $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]]];
        if (str_contains($model, 'gpt-5')) {
            $body['max_completion_tokens'] = 250;
        } else {
            $body['max_tokens']  = 250;
            $body['temperature'] = 0.7;
        }
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code !== 200) return null;
        $r = json_decode($resp, true);
        return trim($r['choices'][0]['message']['content'] ?? '', '"\'«» ') ?: null;
    };

    try {
        $syntheseManager = $callAI($promptManager) ?? buildFallbackManager($rubriqueNom, $tonalite, $avgNote, $topTags);
        $syntheseCollab  = $callAI($promptCollab)  ?? buildFallbackCollab($rubriqueNom, $tonalite, $avgNote);
        $source = 'openai';
    } catch (Throwable $e) {
        $syntheseManager = buildFallbackManager($rubriqueNom, $tonalite, $avgNote, $topTags);
        $syntheseCollab  = buildFallbackCollab($rubriqueNom, $tonalite, $avgNote);
    }
} else {
    $syntheseManager = buildFallbackManager($rubriqueNom, $tonalite, $avgNote, $topTags);
    $syntheseCollab  = buildFallbackCollab($rubriqueNom, $tonalite, $avgNote);
}

// ── Sauvegarder en base ───────────────────────────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO rh_entretien_syntheses
            (entretien_id, rubrique_id, synthese_manager, synthese_collab, tonalite, tags_detectes)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            synthese_manager = VALUES(synthese_manager),
            synthese_collab  = VALUES(synthese_collab),
            tonalite         = VALUES(tonalite),
            tags_detectes    = VALUES(tags_detectes),
            updated_at       = NOW()
    ")->execute([
        $entretienId, $rubriqueId,
        $syntheseManager, $syntheseCollab,
        $tonalite,
        implode(',', array_slice($topTags, 0, 10)),
    ]);
} catch (PDOException $e) { /* ignoré */ }

echo json_encode([
    'success'          => true,
    'synthese_manager' => $syntheseManager,
    'synthese_collab'  => $syntheseCollab,
    'tonalite'         => $tonalite,
    'avg_note'         => round($avgNote, 1),
    'source'           => $source,
], JSON_UNESCAPED_UNICODE);
