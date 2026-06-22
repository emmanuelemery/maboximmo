<?php
/**
 * api/creancier_analyse_generate.php — Génère la synthèse IA d'un dossier créancier.
 *
 * Agrège le contexte du dossier (synthèse, commentaire avocat, créanciers, dettes,
 * agenda, champs extraits des documents analysés) et demande à l'IA une analyse claire
 * pour la compréhension du dossier. Stocke dans creancier_dossier.analyse_ia.
 *
 * POST : id_dossier, csrf_token (form 'creancier_analyse'). Manager + ACL.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_analyse');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$id = (int)($_POST['id_dossier'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier requis']); exit; }
if (!creancier_user_can_access_dossier($pdo, $id, $userId)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit; }

$d = $pdo->prepare("SELECT * FROM creancier_dossier WHERE id=?"); $d->execute([$id]); $dos=$d->fetch(PDO::FETCH_ASSOC);
if (!$dos) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Dossier introuvable']); exit; }

$data = creancier_urgence_data($pdo, $id, $userId);
$lines = [];
$lines[] = "DOSSIER : {$dos['libelle']} ({$dos['code']}) — statut {$dos['statut']}, risque {$dos['niveau_risque']}.";
if ($dos['numero_dossier_adverse']) $lines[] = "N° dossier adverse : {$dos['numero_dossier_adverse']}.";
if ($dos['synthese'])   $lines[] = "Synthèse : {$dos['synthese']}";
if ($dos['commentaire']) $lines[] = "Commentaire/avis avocat : {$dos['commentaire']}";
$lines[] = sprintf("Montants : net bloqué %s €, reste dû %s €.", number_format($data['total_net_bloque'],2,',',' '), number_format($data['reste_du'],2,',',' '));
foreach ($data['saisies_par_creancier'] as $c) $lines[] = "Créancier {$c['libelle']} : " . number_format($c['total_net'],2,',',' ') . " € ({$c['nb']} saisie(s)).";

$it = $pdo->prepare("SELECT type,titre,montant,date_echeance FROM creancier_dossier_item WHERE id_dossier=? ORDER BY priorite DESC LIMIT 40");
$it->execute([$id]);
foreach ($it as $r) $lines[] = "{$r['type']} : {$r['titre']}" . ($r['montant']!==null?' ('.number_format((float)$r['montant'],2,',',' ').' €)':'') . ($r['date_echeance']?' échéance '.$r['date_echeance']:'');

$ag = creancier_agenda($pdo, [$id], 400);
foreach (array_slice($ag,0,20) as $e) $lines[] = "AGENDA {$e['date']} [{$e['type']}] {$e['libelle']}";

// Champs extraits des documents analysés (creancier_doc_champ)
try {
    $ch = $pdo->prepare("SELECT cle, valeur FROM creancier_doc_champ WHERE id_dossier=? ORDER BY id DESC LIMIT 120");
    $ch->execute([$id]);
    $champs = $ch->fetchAll(PDO::FETCH_ASSOC);
    if ($champs) { $lines[] = "CHAMPS EXTRAITS DES DOCUMENTS :"; foreach ($champs as $c) $lines[] = " - {$c['cle']} = {$c['valeur']}"; }
} catch (Throwable $e) {}

$contexte = mb_substr(implode("\n", $lines), 0, 14000);

$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
if (!$api_key) { echo json_encode(['ok'=>false,'error'=>'Clé OpenAI non configurée']); exit; }

$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role'=>'system','content'=>"Tu es un juriste analyste de contentieux de recouvrement. À partir du contexte d'un dossier créancier, rédige une SYNTHÈSE claire et structurée pour la bonne compréhension : (1) Situation en 2-3 phrases, (2) Créancier(s) et montants en jeu, (3) Échéances/urgences à ne pas manquer, (4) Risques principaux, (5) Actions recommandées. Sois factuel, concis, en français. Markdown léger (titres en gras)."],
        ['role'=>'user','content'=>"Contexte du dossier :\n" . $contexte],
    ],
    'temperature' => 0.2,
    'max_tokens' => 1200,
];
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$api_key],
    CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_TIMEOUT=>90]);
$resp = curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
if ($code !== 200) { echo json_encode(['ok'=>false,'error'=>'Erreur API IA (HTTP '.$code.')']); exit; }
$j = json_decode((string)$resp, true);
$txt = trim((string)($j['choices'][0]['message']['content'] ?? ''));
if ($txt === '') { echo json_encode(['ok'=>false,'error'=>'Réponse IA vide']); exit; }

$pdo->prepare("UPDATE creancier_dossier SET analyse_ia=?, analyse_ia_at=NOW() WHERE id=?")->execute([$txt, $id]);
echo json_encode(['ok'=>true, 'analyse'=>$txt], JSON_UNESCAPED_UNICODE);
