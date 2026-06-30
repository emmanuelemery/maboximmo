<?php
/**
 * run_prod.php — Migration CRG EMERY IMMO pour la PROD (Hostinger). Fichier UNIQUE.
 * ============================================================================
 * Rejoue le pipeline validé en local, SANS Python (lit crg_emery.jsonl embarqué).
 * Web-déclenché, super-admin uniquement, chaque étape en DRY-RUN par défaut.
 *
 * PRÉREQUIS :
 *   1. BACKUP COMPLET de la BDD prod (mysqldump) — OBLIGATOIRE.
 *   2. (option) Copier les 230 PDF dans public_html/uploads/crg/ (références/GED).
 *      L'import N'EN A PAS BESOIN : il lit crg_emery.jsonl.
 *   3. Déployer ce dossier (run_prod.php + crg_emery.jsonl) sur la prod.
 *
 * ORDRE IMPÉRATIF (dry-run d'abord, puis &go=1) :
 *   ?step=status
 *   ?step=reconcile  [&go=1]   ← AVANT import (stampe code_compte sur proprios existants)
 *   ?step=prestamp   [&go=1]   ← AVANT import (stampe code_crg sur biens d'annonce)
 *   ?step=import     [&go=1]   ← APRÈS : réutilise les fiches → zéro doublon
 *   ?step=geocode    [&go=1&max=300]
 *   ?step=merge      [&go=1]
 *   ?step=verify
 *
 * ⚠️ NE JAMAIS lancer import&go=1 AVANT reconcile&go=1 + prestamp&go=1,
 *    sinon les proprios/biens existants (annonces) sont DUPLIQUÉS.
 *
 * Invariants : merge s'auto-annule (rollback) si une annonce disparaît / lien casse.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/bootstrap.php';
require_admin_or_super_admin();
$pdo = $GLOBALS['pdo'];
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(600);

$step = (string)($_GET['step'] ?? 'status');
$GO   = (($_GET['go'] ?? '') === '1');
$JSONL = __DIR__ . '/crg_emery.jsonl';

$ag = $pdo->prepare("SELECT id, id_societe FROM agences WHERE nom_agence=? LIMIT 1");
$ag->execute(['EMERY IMMO RIOM']);
$agence = $ag->fetch(PDO::FETCH_ASSOC);
if (!$agence) { exit("ERREUR : agence 'EMERY IMMO RIOM' introuvable en prod.\n"); }
$ID_AGENCE = (int)$agence['id']; $ID_SOCIETE = (int)$agence['id_societe'];

echo "=== CRG EMERY IMMO (prod) — $step " . ($GO ? "[EXÉCUTION]" : "[DRY-RUN]") . " ===\n";
echo "Cible agence #$ID_AGENCE / société #$ID_SOCIETE\n\n";
if (!is_file($JSONL)) { exit("ERREUR : crg_emery.jsonl absent.\n"); }

// ───────── helpers ─────────
function load_crg(string $j): array { $o=[]; foreach(file($j) as $l){ $d=json_decode(trim($l),true); if($d&&!empty($d['meta']))$o[]=$d; } return $o; }
function ppn(string $raw): array {
    $raw=trim(preg_replace('/\s+/',' ',$raw)); $raw=preg_replace('/^(\b[\p{L}]+\b)\s+\1\b/iu','$1',$raw);
    if(preg_match('/\b(SCI|SARL|SASU|SAS|EURL|SCM|SNC|SA|GFA|GAEC|SCEA|SCP|INDIVISION|SUCCESSION|ENTREPRISE|SOCIET[EÉ]|ASSOCIATION)\b/iu',$raw))
        return ['type'=>'morale','civilite'=>null,'nom'=>$raw,'prenom'=>null,'societe'=>$raw];
    $civ=null;$rest=$raw;
    if(preg_match('/^(M\.\s*(?:et|ou)\s*Mme|Mr\s*(?:et|ou)\s*Mme|Monsieur\s*(?:et|ou)\s*Madame|Mademoiselle|Monsieur|Madame|Mlle|Mme|Mr|M\.)\s+/iu',$raw,$mm)){ $civ=trim($mm[1]); $rest=trim(substr($raw,strlen($mm[0]))); }
    $tk=$rest===''?[]:explode(' ',$rest);$nm=[];$pr=[];$in=true;
    foreach($tk as $t){ if($in&&preg_match('/^[\p{Lu}][\p{Lu}\'\-]+$/u',$t))$nm[]=$t; else{$in=false;$pr[]=$t;} }
    if(!$nm){$nm=$tk;$pr=[];}
    return ['type'=>'physique','civilite'=>$civ,'nom'=>implode(' ',$nm)?:$raw,'prenom'=>implode(' ',$pr)?:null,'societe'=>null];
}
function enum_members(PDO $p,string $t,string $c):array{ $r=$p->query("SHOW COLUMNS FROM `$t` LIKE ".$p->quote($c))->fetch(PDO::FETCH_ASSOC); if(!$r||!preg_match('/^enum\((.*)\)$/is',$r['Type'],$m))return[]; return str_getcsv($m[1],',',"'"); }
$SK=fn(string $s):string=>preg_replace('/[^a-z0-9]/','',strtolower($s));
function rstat(string $st,array $mem,callable $sk):string{ $d=['occupe'=>'occupé','parti-debiteur'=>'parti-débiteur','vacant'=>'vacant'][$st]??'vacant'; $t=$sk($d); foreach($mem as $m)if($sk($m)===$t)return $m; foreach($mem as $m)if($sk($m)==='vacant')return $m; return $mem[0]??'vacant'; }
function nadr(string $s):string{ $s=strtoupper(strtr($s,['É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Â'=>'A','À'=>'A','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Û'=>'U','Ü'=>'U','Ç'=>'C'])); return trim(preg_replace('/\s+/',' ',preg_replace('/[^A-Z0-9 ]/',' ',$s))); }

// ════════ STATUS ════════
if ($step==='status') {
    $crg=load_crg($JSONL);
    echo "CRG dans le JSONL : ".count($crg)."\n";
    echo "Proprios avec code_compte : ".(int)$pdo->query("SELECT COUNT(*) FROM proprietaires WHERE code_compte<>''")->fetchColumn()."\n";
    echo "Immeubles code_crg : ".(int)$pdo->query("SELECT COUNT(*) FROM immeubles WHERE code_crg<>''")->fetchColumn()."\n";
    echo "Annonces (à préserver) : ".(int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn()."\n";
    echo "\nORDRE IMPÉRATIF : status → reconcile → prestamp → import → geocode → merge → verify\n";
    echo "(import APRÈS reconcile+prestamp, sinon doublons. Dry-run puis &go=1 à chaque étape.)\n";
    exit;
}

// ════════ IMPORT ════════
if ($step==='import') {
    $crg=load_crg($JSONL);
    $tbmap=[]; foreach($pdo->query("SELECT id,code FROM types_bien")->fetchAll() as $r)$tbmap[$r['code']]=(int)$r['id'];
    $defType=$tbmap['appartement']??1;
    $lt=function(string $t,string $cat) use($tbmap,$defType){ $t=strtolower($t);
        if(str_contains($t,'appart')||str_contains($t,'studio')||str_contains($t,'chambre')||preg_match('/\bt[1-5]\b/',$t))return $tbmap['appartement']??$defType;
        if(str_contains($t,'maison')||str_contains($t,'villa')||str_contains($t,'pavillon'))return $tbmap['maison']??$defType;
        if(str_contains($t,'local')||str_contains($t,'magasin')||str_contains($t,'commerce'))return $tbmap['local_commercial']??$defType;
        if(str_contains($t,'bureau'))return $tbmap['bureau']??$defType;
        if(str_contains($t,'garage')||str_contains($t,'box')||str_contains($t,'cave'))return $tbmap['garage']??$defType;
        if(str_contains($t,'parking')||str_contains($t,'place'))return $tbmap['parking']??$defType;
        if($cat==='habitation')return $tbmap['appartement']??$defType; if($cat==='commercial')return $tbmap['local_commercial']??$defType; return $defType; };
    $bsm=enum_members($pdo,'biens','statut_occupation'); $ssm=enum_members($pdo,'crg_situations_locataires','statut_trimestre');
    $st=['fic'=>0,'skip'=>0,'pnew'=>0,'pold'=>0,'imm'=>0,'bien'=>0,'bail'=>0,'crg'=>0,'situ'=>0,'ecr'=>0,'ecrk'=>0];
    $pdo->beginTransaction();
    foreach($crg as $d){ $st['fic']++; $m=$d['meta']; $an=(int)($m['annee']??0);$tr=(int)($m['trimestre']??0);$cp=trim((string)($m['compte']??''));
        if(!$an||!$tr||$cp===''){ $st['skip']++; continue; }
        $sp=$pdo->prepare("SELECT id FROM proprietaires WHERE code_compte=? LIMIT 1"); $sp->execute([$cp]); $pr=$sp->fetch(PDO::FETCH_ASSOC);
        if($pr){ $idp=(int)$pr['id']; $st['pold']++; }
        else { $pp=ppn((string)($m['proprietaire']??'')); $pdo->prepare("INSERT INTO proprietaires (id_agence,type_personne,civilite,nom,prenom,societe,code_compte,actif,date_creation) VALUES (?,?,?,?,?,?,?,1,NOW())")->execute([$ID_AGENCE,$pp['type'],$pp['civilite'],$pp['nom'],$pp['prenom'],$pp['societe'],$cp]); $idp=(int)$pdo->lastInsertId(); $st['pnew']++; }
        $sc=$pdo->prepare("SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?"); $sc->execute([$idp,$an,$tr]); if($sc->fetch())continue;
        $pdo->prepare("INSERT INTO crg_trimestres (id_proprietaire,annee,trimestre,fichier_pdf,parse_statut,parsed_at,date_arrete,solde_report,total_debits,total_credits,total_tva) VALUES (?,?,?,?,'ok',NOW(),STR_TO_DATE(?,'%d/%m/%Y'),?,?,?,?)")
            ->execute([$idp,$an,$tr,(string)($d['_file']??''),$m['date_arrete'],$m['solde_report']??0,$m['total_debits']??0,$m['total_credits']??0,$m['total_tva']??0]);
        $idc=(int)$pdo->lastInsertId(); $st['crg']++;
        foreach($d['immeubles'] as $imm){ $code=(string)($imm['code']??'');
            $si=$pdo->prepare("SELECT id FROM immeubles WHERE code_crg=? AND id_proprietaire=?"); $si->execute([$code,$idp]); $ir=$si->fetch(PDO::FETCH_ASSOC);
            if($ir)$idi=(int)$ir['id']; else { preg_match('/(\d{5})\s+(.+)$/',(string)($imm['adresse']??''),$g); $cpv=$g[1]??'';$vl=trim($g[2]??''); $a1=trim((string)($imm['adresse']??'')); if($cpv!=='')$a1=trim(preg_replace('/\s*'.preg_quote($cpv,'/').'\s+.*$/','',$a1));
                $pdo->prepare("INSERT INTO immeubles (id_proprietaire,id_societe,id_agence,code_crg,reference_immeuble,nom_immeuble,adresse_1,code_postal,ville,type_immeuble,mode_gestion) VALUES (?,?,?,?,?,?,?,?,?,'immeuble','gestion')")->execute([$idp,$ID_SOCIETE,$ID_AGENCE,$code,$code,(string)($imm['nom']??''),$a1,$cpv,$vl]); $idi=(int)$pdo->lastInsertId(); $st['imm']++; }
            foreach($imm['lots'] as $lot){ $nl=(string)($lot['numero_lot']??''); $stt=rstat((string)$lot['statut'],$bsm,$GLOBALS['SK']); $sts=rstat((string)$lot['statut'],$ssm,$GLOBALS['SK']); $ccb=$code.'_'.$nl;
                $sb=$pdo->prepare("SELECT id FROM biens WHERE code_crg=? LIMIT 1"); $sb->execute([$ccb]); $br=$sb->fetch(PDO::FETCH_ASSOC);
                if(!$br){ $sb=$pdo->prepare("SELECT id FROM biens WHERE id_immeuble=? AND numero_lot=?"); $sb->execute([$idi,$nl]); $br=$sb->fetch(PDO::FETCH_ASSOC); }
                if($br){ $idb=(int)$br['id']; $pdo->prepare("UPDATE biens SET id_immeuble=COALESCE(id_immeuble,?),code_crg=COALESCE(NULLIF(code_crg,''),?) WHERE id=?")->execute([$idi,$ccb,$idb]); }
                else { $tid=$lt((string)$lot['type_bien'],(string)$lot['categorie']); $pdo->prepare("INSERT INTO biens (id_immeuble,id_proprietaire,id_societe,id_agence,id_type_bien,numero_lot,code_crg,statut_occupation,statut_bien,designation) VALUES (?,?,?,?,?,?,?,?,'actif',?)")->execute([$idi,$idp,$ID_SOCIETE,$ID_AGENCE,$tid,$nl,$ccb,$stt,'Lot '.$nl.' — '.$lot['type_bien']]); $idb=(int)$pdo->lastInsertId(); $st['bien']++; }
                $idbail=null;
                if($lot['statut']==='occupe'&&!empty($lot['locataire_nom'])&&($lot['loyer_appele']??0)>0){ $sba=$pdo->prepare("SELECT id FROM baux WHERE id_bien=? AND statut='actif' LIMIT 1"); $sba->execute([$idb]); $bx=$sba->fetch(PDO::FETCH_ASSOC);
                    if($bx)$idbail=(int)$bx['id']; else { $pdo->prepare("INSERT INTO baux (id_bien,id_proprietaire,locataire_nom,loyer,loyer_hc,statut,date_creation) VALUES (?,?,?,?,?,'actif',NOW())")->execute([$idb,$idp,$lot['locataire_nom'],$lot['loyer_appele'],$lot['loyer_appele']]); $idbail=(int)$pdo->lastInsertId(); $st['bail']++; } }
                $pdo->prepare("INSERT INTO crg_situations_locataires (id_crg,id_bien,id_bail,locataire_nom,numero_lot,type_bien,categorie_bien,loyer_appele,solde_anterieur,total_loyers,total_charges,total_regle,total_impaye,statut_trimestre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$idc,$idb,$idbail,(string)($lot['locataire_nom']??''),$nl,(string)$lot['type_bien'],(string)$lot['categorie'],$lot['loyer_appele']??0,$lot['solde_anterieur']??0,$lot['loyer_appele']??0,$lot['total_provisions']??0,$lot['total_regle']??0,$lot['total_impaye']??0,$sts]); $st['situ']++;
            }
            foreach($imm['charges']??[] as $ch){ $mt=(float)($ch['montant']??0); if($mt<=0||$mt>=10000000){$st['ecrk']++;continue;}
                $cm=['honoraires'=>'honoraires_ht','syndic'=>'syndic','assurance'=>'assurance','taxe_fonciere'=>'taxe_fonciere','huissier'=>'huissier','travaux'=>'travaux','tlv'=>'tlv','indemnite_sinistre'=>'indemnite_sinistre','energie'=>'charges','eau'=>'charges']; $dc=$cm[$ch['categorie']??'autre']??'autre';
                $pdo->prepare("INSERT INTO crg_ecritures (id_crg,id_bien,libelle,categorie,debit,tva) VALUES (?,NULL,?,?,?,?)")->execute([$idc,(string)($ch['libelle']??''),$dc,$mt,min((float)($ch['tva']??0),9999999)]); $st['ecr']++; }
        }
    }
    if($GO)$pdo->commit(); else $pdo->rollBack();
    echo ($GO?"IMPORT APPLIQUÉ":"DRY-RUN")."\n";
    foreach($st as $k=>$v)echo "  $k = $v\n";
    echo $GO?"":"\n→ &go=1 pour appliquer\n"; exit;
}

// ════════ RECONCILE (stamp code_compte) ════════
if ($step==='reconcile') {
    $crg=load_crg($JSONL); $byc=[]; foreach($crg as $d){ $m=$d['meta']; if(($m['trimestre']??0)&&!empty($m['compte'])&&!empty($m['proprietaire']))$byc[$m['compte']]=$m['proprietaire']; }
    $tok=function(string $raw) use($SK){ $s=$SK(strtoupper($raw)); return array_values(array_filter(explode(' ', preg_replace('/^(METMME|MOUMME|MONSIEUR|MADAME|MLLE|MME|MR|M|SCI|SARL|SAS|EURL|ENTREPRISE)/','',$s)))); };
    // refaire un squelette mots
    $words=function(string $raw){ $s=strtoupper(strtr($raw,['É'=>'E','È'=>'E','Ê'=>'E','Â'=>'A','À'=>'A','Ô'=>'O','Û'=>'U','Ç'=>'C','Ï'=>'I','Î'=>'I'])); $s=preg_replace('/\b(M|MR|MME|MLLE|MONSIEUR|MADAME|ET|OU|SCI|SARL|SAS|EURL|ENTREPRISE|SUCCESSION|INDIVISION)\b/','',$s); return array_values(array_filter(preg_split('/[^A-Z]+/',$s),fn($w)=>strlen($w)>=3)); };
    // Comptes MOYEN validés manuellement (société 2) à forcer en plus des FORT.
    $FORCE=['10330000','09420000','07270000']; // BRAUGE, DUCHIRON, SCI JPS
    $existing=$pdo->query("SELECT p.id,p.nom,p.prenom,p.societe,ag.id_societe FROM proprietaires p LEFT JOIN agences ag ON ag.id=p.id_agence WHERE (p.code_compte IS NULL OR p.code_compte='')")->fetchAll(PDO::FETCH_ASSOC);
    $cands=[];
    foreach($existing as $e){ $ew=$words(trim(($e['nom']??'').' '.($e['prenom']??'').' '.($e['societe']??''))); if(!$ew)continue; $esur=$ew[0];
        $best=null; foreach($byc as $cp=>$cn){ $cw=$words($cn); if(!$cw||$cw[0]!==$esur)continue; $shared=array_intersect(array_slice($ew,1),array_slice($cw,1)); $conf=$shared?'FORT':'MOYEN'; if(!$best||($conf==='FORT'&&$best[2]==='MOYEN'))$best=[$cp,$cn,$conf]; }
        if($best){ $soc=(int)($e['id_societe']??0); $isForce=in_array((string)$best[0],$FORCE,true); $elig=(($best[2]==='FORT'||$isForce)&&$soc===$ID_SOCIETE); $cands[]=['id'=>(int)$e['id'],'nom'=>trim(($e['nom']??'').' '.($e['prenom']??'')),'soc'=>$soc,'cp'=>(string)$best[0],'cn'=>$best[1],'conf'=>$best[2].($isForce?'(forcé)':''),'elig'=>$elig]; } }
    // anti-collision : un compte = un seul proprio éligible
    $cnt=[]; foreach($cands as $c)if($c['elig'])$cnt[$c['cp']]=($cnt[$c['cp']]??0)+1;
    foreach($cands as &$c)if($c['elig']&&$cnt[$c['cp']]>1){$c['elig']=false;$c['conf'].='*COLL';} unset($c);
    $n=0;
    foreach($cands as $c){ printf("  #%-5s %-26s soc=%s %-9s %-26s %s%s\n",$c['id'],mb_substr($c['nom'],0,26),$c['soc'],$c['cp'],mb_substr($c['cn'],0,26),$c['conf'],$c['elig']?' → STAMP':'');
        if($c['elig']){ $n++; if($GO)$pdo->prepare("UPDATE proprietaires SET code_compte=? WHERE id=? AND (code_compte IS NULL OR code_compte='')")->execute([$c['cp'],$c['id']]); } }
    echo "\nÉligibles (FORT+société $ID_SOCIETE) : $n ".($GO?"→ stampés":"(dry-run)")."\n"; echo $GO?"":"\n→ &go=1 pour appliquer\n"; exit;
}

// ════════ PRESTAMP (stamp code_crg sur biens d'annonce) ════════
if ($step==='prestamp') {
    $crg=load_crg($JSONL); $cimm=[]; foreach($crg as $d){ $m=$d['meta']; if(!($m['trimestre']??0)||empty($m['compte']))continue; foreach($d['immeubles'] as $im)$cimm[$m['compte']][]=['code'=>(string)($im['code']??''),'adresse'=>(string)($im['adresse']??''),'lots'=>array_map(fn($l)=>(string)($l['numero_lot']??''),$im['lots']??[])]; }
    // Choix manuels multi-lots (stables : compte + indice rue + surface → code_crg)
    $picks=['10010000'=>[['JULES FERRY',null,'10010614_0003']],'04830000'=>[['POURRAT',null,'04830243_0004']],'07470000'=>[['GABRIEL PERI',42.0,'07470431_0003'],['GABRIEL PERI',41.0,'07470431_0002']]];
    $q=$pdo->query("SELECT p.code_compte,b.id bid,b.adresse_1,b.code_crg,b.surface_carrez,b.surface_habitable FROM proprietaires p JOIN biens b ON b.id_proprietaire=p.id JOIN annonces a ON a.id_bien=b.id WHERE p.code_compte<>'' GROUP BY b.id");
    $n=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){ if(!empty($r['code_crg']))continue; $cc=$r['code_compte']; $an=nadr((string)$r['adresse_1']); $annNum=preg_match('/^\s*(\d+)/',(string)$r['adresse_1'],$mm)?$mm[1]:''; $aw=array_filter(explode(' ',$an),fn($w)=>strlen($w)>3); $surf=(float)($r['surface_carrez']?:$r['surface_habitable']);
        $code=null;
        // 1) override multi-lots
        foreach($picks[$cc]??[] as $pk){ if(strpos($an,nadr($pk[0]))!==false && ($pk[1]===null||abs($surf-$pk[1])<2.0)){ $code=$pk[2]; break; } }
        // 2) immeuble mono-lot par adresse
        if(!$code)foreach($cimm[$cc]??[] as $im){ $cn=nadr($im['adresse']); $com=0; foreach($aw as $w)if(strpos($cn,$w)!==false)$com++; if($annNum!==''&&strpos($cn,$annNum)!==false&&$com>=1&&count($im['lots'])===1){ $code=$im['code'].'_'.$im['lots'][0]; break; } }
        if($code){ printf("  bien#%-6s %-26s → %s\n",$r['bid'],mb_substr((string)$r['adresse_1'],0,26),$code); $n++; if($GO)$pdo->prepare("UPDATE biens SET code_crg=? WHERE id=? AND (code_crg IS NULL OR code_crg='')")->execute([$code,$r['bid']]); } }
    echo "\nBiens d'annonce pré-liés : $n ".($GO?"→ stampés":"(dry-run)")."\n"; echo $GO?"":"\n→ &go=1\n"; exit;
}

// ════════ GEOCODE ════════
if ($step==='geocode') {
    $key=$GLOBALS['GOOGLE_MAPS_API_KEY']??(defined('GOOGLE_MAPS_API_KEY')?GOOGLE_MAPS_API_KEY:''); if(!$key){exit("GOOGLE_MAPS_API_KEY absente.\n");}
    $max=(int)($_GET['max']??300);
    $rows=$pdo->query("SELECT id,nom_immeuble,adresse_1,code_postal,ville FROM immeubles WHERE id_societe=$ID_SOCIETE AND (latitude IS NULL OR longitude IS NULL) ORDER BY id LIMIT $max")->fetchAll(PDO::FETCH_ASSOC);
    echo "À géocoder : ".count($rows)." (max $max)\n"; if(!$GO){ echo "→ &go=1&max=300 pour lancer\n"; exit; }
    $ok=0;$f=0;
    foreach($rows as $im){ $adr=trim((string)$im['adresse_1'])!==''?$im['adresse_1']:$im['nom_immeuble']; $full=trim($adr.' '.$im['code_postal'].' '.$im['ville']); if(trim($full)===''){$f++;continue;}
        $ch=curl_init('https://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($full).'&key='.urlencode($key)); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]); $raw=curl_exec($ch); curl_close($ch); $b=json_decode((string)$raw,true);
        if(($b['status']??'')==='OK'&&!empty($b['results'])){ $g=$b['results'][0]; $lat=$g['geometry']['location']['lat']??null; $lng=$g['geometry']['location']['lng']??null; if($lat&&$lng){ $setA=trim((string)$im['adresse_1'])===''?', adresse_1=?':''; $par=[$lat,$lng,$g['place_id']??null,$g['formatted_address']??null]; if($setA)$par[]=$im['nom_immeuble']; $par[]=$im['id']; $pdo->prepare("UPDATE immeubles SET latitude=?,longitude=?,google_place_id=?,adresse_formatee=?,gps_source='google'$setA WHERE id=?")->execute($par); $ok++; usleep(60000); continue; } }
        $f++; usleep(120000); }
    echo "Géocodés : $ok | échecs : $f | coût ~".number_format($ok*0.005,2)." €\n";
    $rest=(int)$pdo->query("SELECT COUNT(*) FROM immeubles WHERE id_societe=$ID_SOCIETE AND latitude IS NULL")->fetchColumn();
    echo "Restant sans géoloc : $rest".($rest>0?" → relance &go=1&max=300":"")."\n"; exit;
}

// ════════ MERGE (avec invariants) ════════
if ($step==='merge') {
    $repoint=['biens','immeubles_infos','agency_reunion','agency_facture','agency_contrat','agency_mandant','agency_mandat','bailleur_documents','contrat_syndic','factures','ged_classification_staging','ged_documents','ged_import_releves_items','immeubles_documents','reg_mandats','retour_ag'];
    $stat=function(int $id) use($pdo){ return [(int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_immeuble=$id")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM annonces a JOIN biens b ON b.id=a.id_bien WHERE b.id_immeuble=$id")->fetchColumn()]; };
    $dups=$pdo->query("SELECT GROUP_CONCAT(id) ids FROM immeubles WHERE google_place_id IS NOT NULL AND google_place_id<>'' GROUP BY google_place_id HAVING COUNT(*)>1")->fetchAll(PDO::FETCH_ASSOC);
    $plan=[];$flag=0;
    foreach($dups as $d){ $imms=$pdo->query("SELECT id,nom_immeuble,adresse_formatee,code_crg FROM immeubles WHERE id IN ({$d['ids']})")->fetchAll(PDO::FETCH_ASSOC);
        $dc=array_values(array_unique(array_filter(array_map(fn($x)=>trim((string)$x['code_crg']),$imms))));
        if(count($dc)>1){ $flag++; continue; }
        foreach($imms as &$x){[$x['_b'],$x['_a']]=$stat((int)$x['id']);} unset($x);
        usort($imms,fn($a,$b)=>$a['_a']!==$b['_a']?$b['_a']<=>$a['_a']:($a['_b']!==$b['_b']?$b['_b']<=>$a['_b']:$a['id']<=>$b['id']));
        $plan[]=['adr'=>$imms[0]['adresse_formatee'],'S'=>$imms[0],'L'=>array_slice($imms,1),'crg'=>$dc[0]??null]; }
    echo "Groupes à fusionner : ".count($plan)." | bâtiments distincts non fusionnés : $flag\n";
    foreach($plan as $p){ echo "  ".$p['adr']." → survivant #".$p['S']['id']." (biens {$p['S']['_b']}, annonces {$p['S']['_a']})"; foreach($p['L'] as $l)echo " ← #{$l['id']}"; echo "\n"; }
    if(!$GO){ echo "\n→ &go=1 pour fusionner (rollback auto si annonce perdue)\n"; exit; }
    $annB=(int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn(); $brkB=(int)$pdo->query("SELECT COUNT(*) FROM annonces a LEFT JOIN biens b ON b.id=a.id_bien WHERE b.id IS NULL")->fetchColumn();
    $pdo->beginTransaction(); $mv=0;$del=0;
    foreach($plan as $p){ $S=(int)$p['S']['id']; if(empty($p['S']['code_crg'])&&$p['crg'])$pdo->prepare("UPDATE immeubles SET code_crg=? WHERE id=? AND (code_crg IS NULL OR code_crg='')")->execute([$p['crg'],$S]);
        foreach($p['L'] as $l){ $L=(int)$l['id'];
            foreach($pdo->query("SELECT id,code_crg FROM biens WHERE id_immeuble=$L")->fetchAll(PDO::FETCH_ASSOC) as $b){ $coll=null; if($b['code_crg']){ $c=$pdo->prepare("SELECT id FROM biens WHERE id_immeuble=? AND code_crg=? AND id<>? LIMIT 1"); $c->execute([$S,$b['code_crg'],$b['id']]); $coll=$c->fetchColumn(); }
                if($coll){ $pdo->prepare("UPDATE annonces SET id_bien=? WHERE id_bien=?")->execute([$coll,$b['id']]); $pdo->prepare("UPDATE baux SET id_bien=? WHERE id_bien=?")->execute([$coll,$b['id']]); $pdo->prepare("UPDATE crg_situations_locataires SET id_bien=? WHERE id_bien=?")->execute([$coll,$b['id']]); $pdo->prepare("DELETE FROM biens WHERE id=?")->execute([$b['id']]); }
                else { $pdo->prepare("UPDATE biens SET id_immeuble=? WHERE id=?")->execute([$S,$b['id']]); $mv++; } }
            foreach($repoint as $t){ if($t==='biens')continue; try{$pdo->prepare("UPDATE `$t` SET id_immeuble=? WHERE id_immeuble=?")->execute([$S,$L]);}catch(Throwable $e){} }
            $pdo->prepare("DELETE FROM immeubles WHERE id=?")->execute([$L]); $del++; } }
    $annA=(int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn(); $brkA=(int)$pdo->query("SELECT COUNT(*) FROM annonces a LEFT JOIN biens b ON b.id=a.id_bien WHERE b.id IS NULL")->fetchColumn(); $brkBien=(int)$pdo->query("SELECT COUNT(*) FROM biens b WHERE b.id_immeuble IS NOT NULL AND NOT EXISTS(SELECT 1 FROM immeubles i WHERE i.id=b.id_immeuble)")->fetchColumn();
    $err=[]; if($annA!==$annB)$err[]="annonces $annB→$annA"; if($brkA>$brkB)$err[]="annonces orphelines $brkB→$brkA"; if($brkBien>0)$err[]="$brkBien biens sans immeuble";
    if($err){ $pdo->rollBack(); echo "\n❌ INVARIANT VIOLÉ → ROLLBACK :\n  - ".implode("\n  - ",$err)."\n"; exit; }
    $pdo->commit(); echo "\n✅ APPLIQUÉ : ".count($plan)." groupes, $mv biens déplacés, $del immeubles supprimés. Invariants OK (annonces $annB=$annA).\n"; exit;
}

// ════════ VERIFY ════════
if ($step==='verify') {
    echo "Annonces : ".(int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn()."\n";
    echo "Annonces à lien cassé : ".(int)$pdo->query("SELECT COUNT(*) FROM annonces a LEFT JOIN biens b ON b.id=a.id_bien WHERE b.id IS NULL")->fetchColumn()."\n";
    echo "Biens sans immeuble valide : ".(int)$pdo->query("SELECT COUNT(*) FROM biens b WHERE b.id_immeuble IS NOT NULL AND NOT EXISTS(SELECT 1 FROM immeubles i WHERE i.id=b.id_immeuble)")->fetchColumn()."\n";
    echo "Proprios code_compte : ".(int)$pdo->query("SELECT COUNT(*) FROM proprietaires WHERE code_compte<>''")->fetchColumn()."\n";
    echo "Immeubles soc.$ID_SOCIETE géocodés : ".(int)$pdo->query("SELECT COUNT(*) FROM immeubles WHERE id_societe=$ID_SOCIETE AND latitude IS NOT NULL")->fetchColumn()."/".(int)$pdo->query("SELECT COUNT(*) FROM immeubles WHERE id_societe=$ID_SOCIETE")->fetchColumn()."\n";
    echo "\n→ Régénère ensuite le flux Ubiflow (admin) et compare le nombre d'annonces.\n"; exit;
}

// ════════ TIERSLINK : crée tiers + rôle proprietaire pour les proprios CRG sans tiers ════════
// Respecte le modèle : un TIERS porte le rôle 'proprietaire', et proprietaires.id_tiers le relie.
if ($step==='tierslink') {
    $rows=$pdo->query("SELECT id,id_agence,type_personne,civilite,nom,prenom,societe,email,telephone,adresse_1,code_postal,ville,code_compte
        FROM proprietaires WHERE id_tiers IS NULL AND code_compte<>''")->fetchAll(PDO::FETCH_ASSOC);
    echo "Proprietaires CRG sans tiers : ".count($rows)."\n\n";
    $n=0;
    if($GO)$pdo->beginTransaction();
    foreach($rows as $p){
        $tt = ($p['type_personne']==='morale')?'personne_morale':'personne_physique';
        $rs = ($p['type_personne']==='morale')?($p['societe']?:$p['nom']):null;
        $aff = ($tt==='personne_morale') ? (string)$rs : trim(((string)$p['prenom']).' '.((string)$p['nom']));
        if($aff==='')$aff=(string)($p['nom']?:$rs?:('Propriétaire '.$p['code_compte']));
        if($GO){
            $pdo->prepare("INSERT INTO tiers (id_societe,id_agence,type_tiers,civilite,nom,prenom,raison_sociale,nom_affichage,email,telephone,adresse_ligne1,code_postal,ville,source_creation,actif,date_creation)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'import_crg',1,NOW())")
                ->execute([$ID_SOCIETE,$p['id_agence']?:$ID_AGENCE,$tt,$p['civilite'],$p['nom'],$p['prenom'],$rs,$aff,$p['email'],$p['telephone'],$p['adresse_1'],$p['code_postal'],$p['ville']]);
            $idt=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO tiers_roles (id_tiers,role_code,actif,date_creation) VALUES (?,'proprietaire',1,NOW())")->execute([$idt]);
            $pdo->prepare("UPDATE proprietaires SET id_tiers=? WHERE id=?")->execute([$idt,$p['id']]);
        }
        $n++;
    }
    if($GO)$pdo->commit();
    echo ($GO?"✅ CRÉÉS : $n tiers + rôle 'proprietaire' (et proprietaires.id_tiers reliés)":"À créer : $n tiers + rôle proprietaire")."\n";
    echo $GO?"":"\n→ &go=1 pour créer\n";
    exit;
}

// ════════ PDFLINK : convention LYON (méthode B) ════════
// PDF À PLAT : uploads/crg/{nom_proprio}_{annee}_T{trim}_{ref}.pdf
// DB : crg_trimestres.pdf_fichier = "uploads/crg/{...}.pdf"  ET  fichier_pdf = NULL
// (le visualiseur bailleur lit pdf_fichier — cf get_locataire_history.php)
// + nettoyage de l'ancienne erreur méthode A (dossiers {id}/{annee}_T{tr}.pdf).
if ($step==='pdflink') {
    $crg=load_crg($JSONL);
    $baseCrg = defined('UPLOAD_CRG') ? rtrim(UPLOAD_CRG,'/\\') : (__DIR__.'/../../uploads/crg');
    $inbox = $baseCrg.'/_inbox_emery';
    $san = function(string $s): string { $s=trim($s); $s=str_replace(['/','\\',':','*','?','"','<','>','|'],'_',$s); return preg_replace('/\s+/','_',$s); };
    echo "Source : $inbox\n";
    echo "Cible (convention Lyon, à plat) : uploads/crg/{nom}_{annee}_T{trim}_{ref}.pdf\n";
    echo "DB : pdf_fichier renseigné, fichier_pdf = NULL\n\n";
    $n=0;$absent=0;$noprop=0;$cleaned=0;$sample=0;
    if($GO)$pdo->beginTransaction();
    foreach($crg as $d){ $m=$d['meta']; $an=(int)($m['annee']??0);$tr=(int)($m['trimestre']??0);$cp=trim((string)($m['compte']??'')); $f=(string)($d['_file']??''); $prop=(string)($m['proprietaire']??'');
        if(!$an||!$tr||$cp===''||$f==='')continue;
        $sp=$pdo->prepare("SELECT id FROM proprietaires WHERE code_compte=? LIMIT 1"); $sp->execute([$cp]); $idp=(int)$sp->fetchColumn();
        if(!$idp){$noprop++; continue;}
        $ref=preg_replace('/\.(tmp\.)?pdf$/i','',$f);
        $fname=$san($prop).'_'.$an.'_T'.$tr.'_'.$ref.'.pdf';
        $rel='uploads/crg/'.$fname; $dest=$baseCrg.'/'.$fname; $src=$inbox.'/'.$f; $has=is_file($src);
        if(!$has)$absent++; else $n++;
        if(!$GO && $sample<8){ echo "  $f → $fname\n"; $sample++; }
        if($GO){
            if($has)@copy($src,$dest);
            $pdo->prepare("UPDATE crg_trimestres SET pdf_fichier=?, fichier_pdf=NULL WHERE id_proprietaire=? AND annee=? AND trimestre=?")->execute([$rel,$idp,$an,$tr]);
            // nettoyage méthode A : supprime uploads/crg/{id}/{annee}_T{tr}.pdf + dossier {id} si vide
            $oldA=$baseCrg.'/'.$idp.'/'.$an.'_T'.$tr.'.pdf';
            if(is_file($oldA)){ @unlink($oldA); $cleaned++; @rmdir($baseCrg.'/'.$idp); }
        }
    }
    if($GO)$pdo->commit();
    echo "\n".($GO?"APPLIQUÉ":"DRY-RUN")." :\n";
    echo "  Fichiers ".($GO?"copiés (à plat)":"prêts dans l'inbox")." : $n\n";
    echo "  PDF absents de l'inbox : $absent\n";
    echo "  Proprios introuvables : $noprop\n";
    if($GO) echo "  Anciens fichiers méthode-A supprimés : $cleaned\n";
    echo $GO?"\nDB : pdf_fichier = uploads/crg/{nom}_..._{ref}.pdf, fichier_pdf = NULL. Tu peux supprimer _inbox_emery après vérif.\n":"\n→ &go=1 pour ranger (méthode B) + corriger la DB + nettoyer l'ancien\n";
    exit;
}

// ════════ GEDLINK : verse les CRG dans la GED CENTRALE (ged_documents) ════════
// 1 ged_documents par CRG, lié au tiers + proprio + immeuble via linked_entities
// (lu par tiers_360 « Documents du tiers » / « Mentionné dans »). + crg_trimestres.ged_document_id.
if ($step==='gedlink') {
    $baseRoot = __DIR__.'/../../';
    // Colonne ged_document_id sur crg_trimestres (additif, guardé)
    $hasCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crg_trimestres' AND column_name='ged_document_id'")->fetchColumn();
    if ($GO && !$hasCol) { $pdo->exec("ALTER TABLE crg_trimestres ADD COLUMN ged_document_id BIGINT(20) UNSIGNED NULL DEFAULT NULL"); $hasCol=1; }
    $selGed = $hasCol ? "ct.ged_document_id" : "NULL AS ged_document_id";
    $rows = $pdo->query("SELECT ct.id, ct.id_proprietaire, ct.annee, ct.trimestre, ct.pdf_fichier, $selGed,
            p.id_agence, p.id_tiers,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ',p.civilite,p.nom,p.prenom)),''),p.societe) AS pnom,
            (SELECT i.id FROM immeubles i WHERE i.id_proprietaire=p.id ORDER BY i.id LIMIT 1) AS id_immeuble
        FROM crg_trimestres ct JOIN proprietaires p ON p.id=ct.id_proprietaire
        WHERE p.id_agence IN (5,6) AND ct.pdf_fichier IS NOT NULL AND ct.pdf_fichier<>''")->fetchAll(PDO::FETCH_ASSOC);
    echo "CRG à verser en GED centrale : ".count($rows)."\n\n";
    $n=0;$skip=0;$nofile=0;
    if($GO)$pdo->beginTransaction();
    foreach($rows as $r){
        if(!empty($r['ged_document_id'])){ $skip++; continue; } // déjà versé (idempotent)
        if(empty($r['id_tiers'])){ continue; }
        $rel=ltrim((string)$r['pdf_fichier'],'/'); $abs=$baseRoot.$rel; $nameFile=basename($rel);
        $size=is_file($abs)?(int)filesize($abs):0; $hash=is_file($abs)?hash_file('sha256',$abs):null; if(!is_file($abs))$nofile++;
        $disp='CRG '.$r['annee'].' T'.$r['trimestre'].' — '.$r['pnom'];
        $canon=trim(preg_replace('/_+/','_',preg_replace('/[^A-Za-z0-9]+/','_',$disp)),'_');
        // id en CHAÎNE : le lecteur (tiers_360) binde le param en string (EMULATE_PREPARES=0),
        // donc JSON_CONTAINS ne matche que des id stockés en string.
        $linked=[['type'=>'tiers','id'=>(string)(int)$r['id_tiers']],['type'=>'proprietaire','id'=>(string)(int)$r['id_proprietaire']]];
        if($r['id_immeuble'])$linked[]=['type'=>'immeuble','id'=>(string)(int)$r['id_immeuble']];
        $meta=['classement'=>['tiers_id_bdd'=>(int)$r['id_tiers']],'crg'=>['annee'=>(int)$r['annee'],'trimestre'=>(int)$r['trimestre']],'source'=>'import_crg_emery'];
        if($GO){
            $uuid=sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff));
            $pdo->prepare("INSERT INTO ged_documents
                (uuid,tenant_id,societe_id,agence_id,id_immeuble,name_display,name_canonical,name_file,document_type,source_module,storage_provider,mime_type,size_bytes,hash_sha256,metadata,linked_entities,security_level,status,version,final_destination,created_at,updated_at)
                VALUES (?,1,?,?,?,?,?,?,'CRG','03_GESTION_LOCATIVE','local','application/pdf',?,?,?,?,'interne','active',1,?,NOW(),NOW())")
                ->execute([$uuid,$ID_SOCIETE,$r['id_agence']?:$ID_AGENCE,$r['id_immeuble']?:null,$disp,$canon?:('CRG_'.$r['id']),$nameFile,$size,$hash,json_encode($meta,JSON_UNESCAPED_UNICODE),json_encode($linked,JSON_UNESCAPED_UNICODE),'/'.$rel]);
            $docId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE crg_trimestres SET ged_document_id=? WHERE id=?")->execute([$docId,$r['id']]);
            // Liens pivot pour les « Mentionné dans » des biens/immeubles (le CRG cite chaque lot).
            // bien_360 lit ged_document_links (entity_type BIEN, relation reference/annexe/piece_jointe).
            $bq=$pdo->prepare("SELECT DISTINCT id_bien FROM crg_situations_locataires WHERE id_crg=? AND id_bien IS NOT NULL");
            $bq->execute([$r['id']]);
            foreach($bq->fetchAll(PDO::FETCH_COLUMN) as $bid){
                $pdo->prepare("INSERT INTO ged_document_links (tenant_id,document_id,entity_type,entity_id,relation_type,link_role,is_validated,created_at) VALUES (1,?,'BIEN',?,'reference','crg',1,NOW())")->execute([$docId,(int)$bid]);
            }
            if($r['id_immeuble']) $pdo->prepare("INSERT INTO ged_document_links (tenant_id,document_id,entity_type,entity_id,relation_type,link_role,is_validated,created_at) VALUES (1,?,'IMMEUBLE',?,'reference','crg',1,NOW())")->execute([$docId,(int)$r['id_immeuble']]);
        }
        $n++;
    }
    if($GO)$pdo->commit();
    echo ($GO?"VERSÉS DANS LA GED":"À verser").": $n | déjà versés (skip): $skip | PDF fichier absent: $nofile\n";
    echo "Chaque CRG = 1 ged_documents (type CRG, module 03_GESTION_LOCATIVE), lié tiers+proprio+immeuble.\n";
    echo $GO?"\n→ Apparaît dans tiers_360 « Documents du tiers » et « Mentionné dans ».\n":"\n→ &go=1 pour verser\n";
    exit;
}

echo "Étape inconnue : $step\n";
