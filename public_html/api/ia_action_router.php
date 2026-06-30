<?php
declare(strict_types=1);
/**
 * api/ia_action_router.php — Routeur d'ACTIONS IA (panneau illimité) pour un bien.
 *
 * À partir d'une demande en langage naturel, l'IA choisit UNE action :
 *   - ouvrir une page interne (édition d'un champ du bien, documents, fiche tiers…)
 *   - ouvrir un lien externe (Google Maps près du bien, recherche web)
 *   - répondre (si c'est une vraie question)
 *
 * POST : { bien_id, question }
 * Réponse : { ok, type:'open'|'answer', url, external:bool, text }
 *
 * Accès : utilisateur authentifié.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];
$key = $OPENAI_API_KEY ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');

$body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
$bienId = (int)($body['bien_id'] ?? 0);
$q      = trim((string)($body['question'] ?? ''));
if ($bienId <= 0 || $q === '') exit(json_encode(['ok'=>false,'error'=>'bien_id et question requis']));

// Contexte du bien (adresse, ville…)
$st = $pdo->prepare("SELECT b.reference_bien, b.adresse_1, b.code_postal, b.ville, b.surface_habitable, b.nb_pieces,
                            COALESCE(NULLIF(b.ville,''), i.ville) AS ville_eff,
                            COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adr_eff, i.code_postal AS imm_cp, i.ville AS imm_ville
                     FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble WHERE b.id=? LIMIT 1");
$st->execute([$bienId]);
$b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$adresse = trim(($b['adr_eff'] ?? '') . ' ' . ($b['code_postal'] ?: $b['imm_cp'] ?? '') . ' ' . ($b['ville_eff'] ?? ''));
$base = rtrim((function_exists('app_url') ? app_url('/') : '/'), '/');

if ($key === '') exit(json_encode(['ok'=>true,'type'=>'answer','text'=>"IA non configurée — précise un champ (surface, prix…) pour que je t'ouvre la page."]));

$sys = "Tu es un ROUTEUR D'ACTIONS pour le logiciel immobilier MaBoxImmo. "
     . "À partir de la demande, tu choisis UNE seule action et réponds en JSON STRICT : "
     . '{"type":"open"|"answer","url":"...","external":true|false,"text":"..."}. '
     . "RÈGLES URL : "
     . "- Éditer un champ du bien → \"$base/bien_detail.php?edit=$bienId&section=SECTION&focus=CHAMP\" (external:false). "
     . "  SECTION=descriptif pour CHAMP ∈ {surface_habitable,surface_carrez,nb_pieces,nb_chambres,nb_salles_bain,nb_wc,etage,annee_construction,type_bien}. "
     . "  SECTION=annonce pour CHAMP ∈ {prix,loyer,honoraires,type_transaction}. SECTION=dpe pour le DPE/diagnostics. "
     . "- Documents du bien → \"$base/bien_documents_list.php?id=$bienId\" (external:false). "
     . "- Voir un propriétaire/tiers/société par son nom → \"$base/agency_proprietaires.php?q=NOM\" (external:false). "
     . "- Trouver un commerce/artisan/professionnel/service à proximité → URL Google Maps "
     . "\"https://www.google.com/maps/search/?api=1&query=TERMES+ADRESSE\" (external:true), ADRESSE=\"$adresse\". "
     . "- Recherche web générale → \"https://www.google.com/search?q=TERMES\" (external:true). "
     . "- Si c'est une vraie QUESTION (pas une navigation) → type=answer + text (réponse courte). "
     . "Toujours url-encoder les paramètres. Réponds UNIQUEMENT le JSON.";

$payload = [
    'model' => $OPENAI_TEXT_MODEL ?? 'gpt-4o-mini',
    'messages' => [
        ['role'=>'system','content'=>$sys],
        ['role'=>'user','content'=>"Bien #$bienId (".($b['reference_bien']??'').") à \"$adresse\". Demande : \"$q\""],
    ],
    'response_format' => ['type'=>'json_object'],
    'temperature' => 0,
    'max_tokens' => 300,
];
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
    CURLOPT_POSTFIELDS=>json_encode($payload)]);
$r = curl_exec($ch); curl_close($ch);
$d = is_string($r) ? json_decode($r, true) : null;
$content = $d['choices'][0]['message']['content'] ?? '';
$act = json_decode((string)$content, true);

if (!is_array($act) || empty($act['type'])) {
    exit(json_encode(['ok'=>true,'type'=>'answer','text'=>"Je n'ai pas compris l'action — reformule (ex. « saisir la surface », « trouver un cuisiniste », « voir Locavente »)."]));
}

$type = $act['type'] === 'open' ? 'open' : 'answer';
$url  = trim((string)($act['url'] ?? ''));
// Sécurité : on n'ouvre que http(s)
if ($type === 'open' && !preg_match('#^https?://#i', $url)) $type = 'answer';

echo json_encode([
    'ok'       => true,
    'type'     => $type,
    'url'      => $url,
    'external' => (bool)($act['external'] ?? false),
    'text'     => (string)($act['text'] ?? ''),
], JSON_UNESCAPED_UNICODE);
