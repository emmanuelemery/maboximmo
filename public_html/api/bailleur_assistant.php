<?php
/**
 * api/bailleur_assistant.php — Assistant IA du module Bailleur (function-calling).
 * Accès DONNÉES + GED + orientation pages, TOUJOURS scopé aux droits de l'utilisateur :
 *   - super admin  → tous les propriétaires bailleurs
 *   - bailleur     → uniquement ses propriétaires (user_proprietaires)
 * Outils en LECTURE SEULE (SELECT). POST {question}. Réutilise OpenAI (cf api/ask_ia.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/roles_services.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST requis']); exit; }

$pdo=$GLOBALS['pdo']; $userId=(int)current_user_id(); $roleId=(int)current_role_id(); $isSA=is_super_admin();
if (!$isSA && !hasServiceAccess($roleId,'bailleur')) { http_response_code(403); echo json_encode(['error'=>'Accès réservé au module Bailleur']); exit; }

$input=json_decode(file_get_contents('php://input'),true)?:[];
$question=trim((string)($input['question']??''));
if ($question==='') { echo json_encode(['error'=>'Question requise']); exit; }

// ── Périmètre autorisé (ids propriétaires) ──
if ($isSA) {
    $scope = array_map('intval', array_column($pdo->query("SELECT DISTINCT id_proprietaire FROM crg_trimestres")->fetchAll(PDO::FETCH_ASSOC),'id_proprietaire'));
} else {
    $st=$pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?"); $st->execute([$userId]);
    $scope = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC),'id_proprietaire'));
}
if (!$scope) $scope=[0];
$IN = implode(',', $scope);

// ── Outils scopés (lecture seule) ──
function tool_kpis($pdo,$IN){
    return $pdo->query("SELECT COALESCE(NULLIF(p.societe,''),CONCAT_WS(' ',p.prenom,p.nom)) AS proprietaire,
        COUNT(DISTINCT b.id) biens, COUNT(DISTINCT x.id) baux_actifs,
        ROUND(SUM(x.loyer_mensuel_hc)) loyer_mensuel
      FROM proprietaires p
      LEFT JOIN biens b ON b.id_proprietaire=p.id
      LEFT JOIN bien_baux x ON x.id_bien=b.id AND x.statut='actif'
      WHERE p.id IN ($IN) GROUP BY p.id")->fetchAll(PDO::FETCH_ASSOC);
}
function tool_impayes($pdo,$IN){
    return $pdo->query("SELECT s.locataire_nom, ROUND(s.total_impaye) impaye, i.adresse_1, i.ville,
        COALESCE(NULLIF(p.societe,''),CONCAT_WS(' ',p.prenom,p.nom)) proprietaire,
        CASE WHEN s.loyer_appele>0 THEN 'en place' ELSE 'parti' END AS situation
      FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg
      LEFT JOIN biens b ON b.id=s.id_bien LEFT JOIN immeubles i ON i.id=b.id_immeuble
      LEFT JOIN proprietaires p ON p.id=t.id_proprietaire
      WHERE t.id_proprietaire IN ($IN) AND s.total_impaye>0
        AND (t.annee,t.trimestre)=(SELECT a.annee,a.trimestre FROM crg_trimestres a WHERE a.id_proprietaire=t.id_proprietaire AND a.parse_statut='ok' ORDER BY a.annee DESC,a.trimestre DESC LIMIT 1)
      ORDER BY s.total_impaye DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
}
function tool_locataire($pdo,$IN,$nom){
    $st=$pdo->prepare("SELECT s.locataire_nom, i.nom_immeuble, i.adresse_1, i.ville, b.reference_bien, b.surface_habitable,
        ROUND(s.loyer_appele/3) loyer_mois, ROUND(s.total_impaye) impaye,
        COALESCE(NULLIF(p.societe,''),CONCAT_WS(' ',p.prenom,p.nom)) proprietaire
      FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg
      LEFT JOIN biens b ON b.id=s.id_bien LEFT JOIN immeubles i ON i.id=b.id_immeuble
      LEFT JOIN proprietaires p ON p.id=t.id_proprietaire
      WHERE t.id_proprietaire IN ($IN) AND UPPER(s.locataire_nom) LIKE UPPER(?)
        AND (t.annee,t.trimestre)=(SELECT a.annee,a.trimestre FROM crg_trimestres a WHERE a.id_proprietaire=t.id_proprietaire AND a.parse_statut='ok' ORDER BY a.annee DESC,a.trimestre DESC LIMIT 1)
      LIMIT 15");
    $st->execute(['%'.$nom.'%']); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function tool_bien($pdo,$IN,$terme){
    $st=$pdo->prepare("SELECT b.reference_bien, i.nom_immeuble, i.adresse_1, i.ville, b.surface_habitable, b.occupation_bien, b.statut_bien,
        x.locataire_nom, x.loyer_mensuel_hc, COALESCE(NULLIF(p.societe,''),CONCAT_WS(' ',p.prenom,p.nom)) proprietaire
      FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
      LEFT JOIN bien_baux x ON x.id_bien=b.id AND x.statut='actif'
      LEFT JOIN proprietaires p ON p.id=b.id_proprietaire
      WHERE b.id_proprietaire IN ($IN) AND (UPPER(i.adresse_1) LIKE UPPER(?) OR UPPER(i.nom_immeuble) LIKE UPPER(?) OR b.reference_bien LIKE ?)
      LIMIT 20");
    $st->execute(['%'.$terme.'%','%'.$terme.'%','%'.$terme.'%']); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function tool_ged($pdo,$IN,$terme){
    // GED scopée : documents liés aux biens/immeubles des propriétaires autorisés
    $st=$pdo->prepare("SELECT d.title, d.document_type, d.created_at,
        JSON_UNQUOTE(JSON_EXTRACT(d.metadata,'$.classement.bien_id_bdd')) bien_id
      FROM ged_documents d
      WHERE d.status='active'
        AND JSON_EXTRACT(d.metadata,'$.classement.bien_id_bdd') IN (SELECT id FROM biens WHERE id_proprietaire IN ($IN))
        AND (? = '' OR UPPER(d.title) LIKE UPPER(?) OR UPPER(d.document_type) LIKE UPPER(?))
      ORDER BY d.created_at DESC LIMIT 25");
    $st->execute([$terme,'%'.$terme.'%','%'.$terme.'%']); return $st->fetchAll(PDO::FETCH_ASSOC);
}

$tools=[
 ['type'=>'function','function'=>['name'=>'kpis','description'=>'Indicateurs par propriétaire (nb biens, baux actifs, loyer mensuel).','parameters'=>['type'=>'object','properties'=>new stdClass()]]],
 ['type'=>'function','function'=>['name'=>'impayes','description'=>'Liste des impayés (locataire, montant, adresse, propriétaire, en place/parti).','parameters'=>['type'=>'object','properties'=>new stdClass()]]],
 ['type'=>'function','function'=>['name'=>'locataire','description'=>'Recherche un locataire par nom : bien, immeuble, loyer, impayé.','parameters'=>['type'=>'object','properties'=>['nom'=>['type'=>'string']],'required'=>['nom']]]],
 ['type'=>'function','function'=>['name'=>'bien','description'=>'Recherche un bien par adresse/immeuble/référence : locataire, loyer, surface, statut.','parameters'=>['type'=>'object','properties'=>['terme'=>['type'=>'string']],'required'=>['terme']]]],
 ['type'=>'function','function'=>['name'=>'ged','description'=>'Recherche des documents (GED) liés aux biens du périmètre.','parameters'=>['type'=>'object','properties'=>['terme'=>['type'=>'string']]]]],
];

$pagesMap = "Pages: bailleur_dashboard.php (accueil/KPI) ; bailleur_patrimoine_actif.php (patrimoine, simuler/mettre en vente) ; bien_baux_liste.php (baux) ; bailleur_revision_loyer.php (révision loyers) ; bailleur_ged.php (documents) ; bailleur_crg_audit.php (audit CRG).";
$system="Tu es l'assistant du module Bailleur de MaBoxImmo. Réponds en français, concis. "
 ."Tu peux interroger les données via les outils (toujours limités aux droits de l'utilisateur). "
 ."Quand c'est une question de navigation, oriente avec un lien Markdown [libellé](page.php). $pagesMap "
 ."Il n'existe pas encore d'estimation de prix marché par secteur (DVF) ; pour estimer, oriente vers le simulateur de bailleur_patrimoine_actif.php.";

global $OPENAI_API_KEY,$OPENAI_TEXT_MODEL;
$model=$OPENAI_TEXT_MODEL?:'gpt-4o';
$messages=[['role'=>'system','content'=>$system],['role'=>'user','content'=>$question]];

function llm($model,$messages,$tools,$key){
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>$model,'max_completion_tokens'=>900,'messages'=>$messages,'tools'=>$tools],JSON_UNESCAPED_UNICODE)]);
    $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code,json_decode($raw,true),$raw];
}

// Boucle function-calling (max 4 tours)
for ($i=0;$i<4;$i++){
    [$code,$res,$raw]=llm($model,$messages,$tools,$OPENAI_API_KEY);
    if ($code!==200){ echo json_encode(['error'=>'IA indisponible : '.($res['error']['message']??"HTTP $code")]); exit; }
    $msg=$res['choices'][0]['message']??[];
    if (!empty($msg['tool_calls'])){
        $messages[]=$msg;
        foreach ($msg['tool_calls'] as $tc){
            $fn=$tc['function']['name']??''; $args=json_decode($tc['function']['arguments']??'{}',true)?:[];
            try {
                $out = match($fn){
                    'kpis'=>tool_kpis($pdo,$IN),
                    'impayes'=>tool_impayes($pdo,$IN),
                    'locataire'=>tool_locataire($pdo,$IN,(string)($args['nom']??'')),
                    'bien'=>tool_bien($pdo,$IN,(string)($args['terme']??'')),
                    'ged'=>tool_ged($pdo,$IN,(string)($args['terme']??'')),
                    default=>['error'=>'outil inconnu'],
                };
            } catch(Throwable $e){ $out=['error'=>$e->getMessage()]; }
            $messages[]=['role'=>'tool','tool_call_id'=>$tc['id'],'content'=>json_encode($out,JSON_UNESCAPED_UNICODE)];
        }
        continue;
    }
    // Pas de tool_calls → réponse finale attendue
    $answer = trim((string)($msg['content'] ?? ''));
    if ($answer !== '') { echo json_encode(['answer'=>$answer],JSON_UNESCAPED_UNICODE); exit; }
    // Contenu VIDE (le modèle a souvent appelé un outil sans rédiger la synthèse) :
    // on force une rédaction texte SANS outils à partir du contexte déjà obtenu.
    error_log('[bailleur_assistant] contenu vide tour '.$i.' — raw='.substr((string)$raw,0,400));
    $messages[]=['role'=>'user','content'=>'Rédige maintenant, en français et de façon concise, la réponse à ma question à partir des informations obtenues ci-dessus.'];
    [$c2,$r2,$raw2]=llm($model,$messages,[],$OPENAI_API_KEY);
    if ($c2!==200){ echo json_encode(['error'=>'IA indisponible : '.($r2['error']['message']??"HTTP $c2")]); exit; }
    $answer = trim((string)($r2['choices'][0]['message']['content'] ?? ''));
    echo json_encode(['answer'=>$answer !== '' ? $answer : "Je n'ai pas trouvé d'information à afficher pour cette demande."],JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(['answer'=>"Je n'ai pas pu finaliser la réponse, reformule s'il te plaît."]);
