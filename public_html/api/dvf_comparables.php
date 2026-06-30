<?php
/**
 * api/dvf_comparables.php — Estimation prix de vente via DVF (open data Etalab).
 * Cherche les ventes comparables autour d'un bien (même section cadastrale,
 * même type, surface équivalente ±25%, 4 dernières années) et calcule un €/m².
 * Sources : api-adresse.data.gouv.fr (INSEE) + apicarto.ign.fr (section) + app.dvf.etalab.gouv.fr.
 * GET/POST : id_bien. Accès : service bailleur, bien dans le périmètre de l'utilisateur.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/roles_services.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
$pdo=$GLOBALS['pdo']; $userId=(int)current_user_id(); $roleId=(int)current_role_id(); $isSA=is_super_admin();
if (!$isSA && !hasServiceAccess($roleId,'bailleur')) { http_response_code(403); echo json_encode(['error'=>'Accès refusé']); exit; }

$idBien=(int)($_GET['id_bien'] ?? $_POST['id_bien'] ?? 0);
if ($idBien<=0) { echo json_encode(['error'=>'id_bien requis']); exit; }

$st=$pdo->prepare("SELECT b.id, b.id_proprietaire, b.sous_type_bien, b.usage_bien, tb.code AS type_code, tb.categorie AS type_cat,
    COALESCE(NULLIF(b.surface_carrez,0), NULLIF(b.surface_habitable,0)) AS surface,
    i.latitude, i.longitude, i.adresse_1, i.ville, i.code_postal
  FROM biens b LEFT JOIN types_bien tb ON tb.id=b.id_type_bien LEFT JOIN immeubles i ON i.id=b.id_immeuble WHERE b.id=? LIMIT 1");
$st->execute([$idBien]); $b=$st->fetch(PDO::FETCH_ASSOC);
if (!$b) { echo json_encode(['error'=>'bien introuvable']); exit; }
// périmètre : super admin OU bien d'un propriétaire de l'utilisateur (direct ou via CRG)
if (!$isSA) {
    $c=$pdo->prepare("SELECT 1 FROM user_proprietaires up WHERE up.id_user=? AND (up.id_proprietaire=? OR up.id_proprietaire IN (SELECT t.id_proprietaire FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg WHERE s.id_bien=?)) LIMIT 1");
    $c->execute([$userId,(int)$b['id_proprietaire'],$idBien]);
    if (!$c->fetchColumn()) { http_response_code(403); echo json_encode(['error'=>'Bien hors périmètre']); exit; }
}
$lat=(float)$b['latitude']; $lon=(float)$b['longitude']; $surf=(float)$b['surface'];
if (!$lat || !$lon) {
    // Repli : géocodage à la volée depuis l'adresse (France entière)
    $q=trim(((string)$b['adresse_1']).' '.((string)$b['code_postal']).' '.((string)$b['ville']));
    if ($q!=='') {
        $g=http_json("https://api-adresse.data.gouv.fr/search/?limit=1&q=".rawurlencode($q));
        $coord=$g['features'][0]['geometry']['coordinates'] ?? null;
        if ($coord) { $lon=(float)$coord[0]; $lat=(float)$coord[1]; }
    }
}
if (!$lat || !$lon) { echo json_encode(['error'=>'Impossible de localiser le bien (adresse incomplète).']); exit; }

// type DVF — distinguer HABITATION vs PROFESSIONNEL/COMMERCIAL
$code=(string)($b['type_code'] ?? ''); $cat=mb_strtolower((string)$b['type_cat']);
$usage=mb_strtolower((string)$b['usage_bien']); $stl=mb_strtolower((string)$b['sous_type_bien']);
$estPro = str_contains($usage,'profession') || str_contains($usage,'commerc') || str_contains($usage,'mixte')
    || in_array($code,['local_commercial','bureau','entrepot','local_activite','fonds_commerce','droit_bail'],true)
    || in_array($cat,['professionnel','commerce'],true)
    || preg_match('/local|bureau|entrep|magasin|commerc|atelier/',$stl);
if ($estPro) { $nature='professionnel'; $typeDvf='Local industriel. commercial ou assimilé'; }
elseif ($code==='maison' || str_contains($stl,'maison') || str_contains($stl,'villa')) { $nature='habitation'; $typeDvf='Maison'; }
else { $nature='habitation'; $typeDvf='Appartement'; }

function http_json($url){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'MaBoxImmo/1.0']);
    $r=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return $c===200 ? json_decode($r,true) : null;
}
// 1) INSEE (arrondissement) via reverse-geocode
$rev=http_json("https://api-adresse.data.gouv.fr/reverse/?lon=$lon&lat=$lat");
$insee=$rev['features'][0]['properties']['citycode'] ?? '';
// 2) sections cadastrales du VOISINAGE via IGN — on n'interroge pas qu'une seule
//    section (zone minuscule, souvent 0-1 vente) : on prend toutes les sections
//    comprises dans un rayon ~400 m autour du bien. Robuste France entière.
$ptGeom=rawurlencode(json_encode(['type'=>'Point','coordinates'=>[$lon,$lat]]));
$divPt=http_json("https://apicarto.ign.fr/api/cadastre/division?geom=$ptGeom");
$section=$divPt['features'][0]['properties']['section'] ?? '';   // section du bien (affichage)
if (!$section) {
    $par=http_json("https://apicarto.ign.fr/api/cadastre/parcelle?geom=$ptGeom");
    $section=$par['features'][0]['properties']['section'] ?? '';
}
// bbox ~400 m → polygone, pour récupérer les sections alentour
$dLat=0.0045; $dLon=0.0045/max(0.2,cos(deg2rad($lat)));
$ring=[[$lon-$dLon,$lat-$dLat],[$lon+$dLon,$lat-$dLat],[$lon+$dLon,$lat+$dLat],[$lon-$dLon,$lat+$dLat],[$lon-$dLon,$lat-$dLat]];
$polyGeom=rawurlencode(json_encode(['type'=>'Polygon','coordinates'=>[$ring]]));
$divZone=http_json("https://apicarto.ign.fr/api/cadastre/division?geom=$polyGeom");
$sections=[];
foreach (($divZone['features'] ?? []) as $f){ $s=$f['properties']['section'] ?? ''; if ($s!=='') $sections[$s]=1; }
if ($section!=='') $sections[$section]=1;
$sections=array_slice(array_keys($sections),0,20);   // garde-fou
if (!$insee || !$sections) { echo json_encode(['error'=>'Secteur cadastral introuvable pour ce point (vérifier la géolocalisation de l\'immeuble).']); exit; }
// 3) DVF mutations de TOUTES les sections du voisinage
$muts=[];
foreach ($sections as $s){
    $dvf=http_json("https://app.dvf.etalab.gouv.fr/api/mutations3/$insee/".('000'.$s));
    foreach (($dvf['mutations'] ?? []) as $m){ $muts[]=$m; }
}

$yearMin=(int)date('Y')-4;
$lo=$surf>0?$surf*0.75:0; $hi=$surf>0?$surf*1.25:1e9;
$pm=[]; $sample=[]; $seen=[];
foreach ($muts as $m){
    if (($m['type_local']??'')!==$typeDvf) continue;
    // Exclut les ventes groupées (appart + parking/cave) : valeur_fonciere = total
    // mais surface = local seul → €/m² faussé. On ne garde que les ventes mono-lot.
    if ((int)($m['nombre_lots']??0) > 1) continue;
    $idm=(string)($m['id_mutation']??'');
    if ($idm!=='' && isset($seen[$idm])) continue; // dédoublonne les mutations multi-locaux
    $vf=(float)($m['valeur_fonciere']??0); $sr=(float)($m['surface_reelle_bati']??0);
    if ($vf<=0 || $sr<=0) continue;
    if ($surf>0 && ($sr<$lo || $sr>$hi)) continue;
    $an=(int)substr((string)($m['date_mutation']??''),0,4);
    if ($an<$yearMin) continue;
    $ppm=$vf/$sr; if ($ppm<300 || $ppm>25000) continue; // garde-fou aberrations
    if ($idm!=='') $seen[$idm]=1;
    $pm[]=$ppm;
    $sample[]=['date'=>$m['date_mutation'],'adresse'=>trim(($m['adresse_numero']??'').' '.($m['adresse_nom_voie']??'')),'surface'=>round($sr),'prix'=>round($vf),'prix_m2'=>round($ppm),'_k'=>$ppm];
}
sort($pm);
$median = $pm ? $pm[intdiv(count($pm),2)] : null;
$nb=count($pm);
// Comparables affichés : les plus proches de la médiane (les plus représentatifs), max 12
usort($sample,function($a,$b)use($median){ return abs($a['_k']-$median)<=>abs($b['_k']-$median); });
$sample=array_slice($sample,0,12);
foreach($sample as &$s){ unset($s['_k']); } unset($s);
// Avertissement de fiabilité : une médiane sur très peu de ventes n'est pas représentative.
$fiab=null;
$nbSec=count($sections);
if ($nb===0) $fiab='Aucune vente comparable trouvée dans le secteur (~400 m, '.$nbSec.' sections) : estimation impossible. Vérifier sur le lien DVF.';
elseif ($nb<3) $fiab='Estimation indicative : seulement '.$nb.' vente'.($nb>1?'s':'').' comparable'.($nb>1?'s':'').' dans le secteur. À confirmer sur le site DVF officiel.';
// Lien vers la nouvelle carte DVF officielle (explore.data.gouv.fr) centrée sur le bien
$lienDvf='https://explore.data.gouv.fr/fr/immobilier?onglet=carte&lat='.$lat.'&lng='.$lon.'&zoom=18';
echo json_encode([
  'ok'=>true,
  'bien'=>['surface'=>$surf?:null,'type'=>$typeDvf,'nature'=>$nature,'ville'=>$b['ville'],'section'=>'secteur ~400 m ('.$nbSec.' sections, '.$insee.' '.$section.')'],
  'note'=>($nature==='professionnel'?'Bien professionnel/commercial : les ventes DVF comparables sont plus rares et la valeur dépend fortement de l\'activité (à pondérer).':null),
  'fiabilite'=>$fiab,
  'lien_dvf'=>$lienDvf,
  'nb_ventes'=>$nb,
  'prix_m2_median'=>$median?round($median):null,
  'prix_m2_min'=>$pm?round(min($pm)):null,
  'prix_m2_max'=>$pm?round(max($pm)):null,
  'estimation'=>($median && $surf)?round($median*$surf):null,
  'periode'=>$yearMin.'–'.date('Y'),
  'comparables'=>$sample,
], JSON_UNESCAPED_UNICODE);
