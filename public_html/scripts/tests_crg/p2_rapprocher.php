<?php
declare(strict_types=1);
/**
 * PHASE 2 · POINTS 4 à 10 — RAPPROCHER LES PROPRIÉTAIRES CRG DE L'EXISTANT MBI.
 *
 * ⚠️ SIMULATION. Aucun INSERT, UPDATE ou DELETE. Le script ouvre la base en lecture et rend un
 *    fichier de décisions ; rien n'est écrit dans MBI, rien n'est fusionné, rien n'est créé.
 *
 * ⚠️ UNE RESSEMBLANCE N'EST JAMAIS UNE CERTITUDE. Le fuzzy ne sert qu'à PRODUIRE DES CANDIDATS.
 *    « SABY Yves », « M. ET MME SABY » et « SCI FOCH SABY » partagent un mot et sont trois
 *    identités : une SCI n'est pas son associé, un couple n'est pas l'un de ses membres, une
 *    indivision n'est pas un indivisaire.
 *
 * ⚠️ ET LA NORMALISATION SERT À TROUVER, PAS À FUSIONNER. La casse, les accents et les espaces
 *    parasites ne créent jamais une identité nouvelle — mais le libellé source du CRG est
 *    conservé tel quel, à l'octet près.
 */
require_once 'c:/xampp/htdocs/MaBoxImmo2026/public_html/inc/bootstrap.php';

$SOURCE = 'C:/tmp/p2_source.json';
$SORTIE = 'C:/tmp/p2_decisions.json';

/* ── Normalisation de RECHERCHE ────────────────────────────────────────────────────────── */
function plat(?string $s): string
{
    $s = (string)$s;
    $s = strtr($s, ['À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A','Ã'=>'A','Å'=>'A','Ç'=>'C','È'=>'E','É'=>'E',
                    'Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Í'=>'I','Ì'=>'I','Ô'=>'O','Ö'=>'O','Ò'=>'O',
                    'Ó'=>'O','Õ'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U','Ú'=>'U','Ÿ'=>'Y','Ñ'=>'N','Œ'=>'OE',
                    'Æ'=>'AE','à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
                    'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y','ñ'=>'n',
                    'œ'=>'oe','æ'=>'ae']);
    $s = preg_replace('/[^0-9A-Za-z]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', mb_strtoupper($s)));
}

/** Les mots signifiants d'un nom : on retire civilités et formes juridiques. */
const BRUIT = ['M','MR','MME','MLLE','MONSIEUR','MADAME','MADEMOISELLE','ET','OU','LE','LA','LES',
               'DE','DU','DES','SCI','SC','SARL','SAS','SASU','SNC','SA','EURL','SCP','SCM','GIE',
               'INDIVISION','IND','SUCCESSION','HOIRIE','ASSOCIATION','SYNDICAT','CONSORTS'];

function mots(string $plat): array
{
    $m = array_filter(explode(' ', $plat), fn($w) => strlen($w) > 1 && !in_array($w, BRUIT, true));
    return array_values(array_unique($m));
}

/** La nature juridique DÉCLARÉE par le libellé — jamais déduite d'une ressemblance. */
function nature(string $plat): string
{
    if (preg_match('/\b(INDIVISION|IND|SUCCESSION|HOIRIE|HERITIERS?|CONSORTS)\b/', $plat)) return 'indivision';
    if (preg_match('/\b(SCI|SC|SARL|SAS|SASU|SNC|SA|EURL|SCP|SCM|GIE|GPE|GROUPE|ASSOCIATION|SYNDICAT|FONCIERE|CABINET|HOLDING)\b/', $plat)) return 'personne_morale';
    if (preg_match('/\b(ET MME|ET MADAME|M ET MME|MR ET MME|EPOUX)\b/', $plat)) return 'couple';
    return 'personne_physique';
}

/** Deux natures peuvent-elles désigner la même identité ? */
function natures_compatibles(string $a, string $b): bool
{
    if ($a === $b) return true;
    /* ⚠️ UN COUPLE ET UNE PERSONNE PHYSIQUE PEUVENT ÊTRE LE MÊME TIERS EN BASE — « MR & MME
       SABY » y est un seul tiers. Mais une SCI n'est jamais une personne physique, et une
       indivision n'est jamais l'un de ses indivisaires. */
    $souple = [['couple', 'personne_physique']];
    foreach ($souple as $p) if (in_array($a, $p, true) && in_array($b, $p, true)) return true;
    return false;
}

/* ── L'existant MBI, chargé une fois ───────────────────────────────────────────────────── */
$tiers = $pdo->query(
    "SELECT t.id, t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale, t.nom_affichage,
            t.siren, t.siret, t.email, t.telephone, t.adresse_ligne1, t.code_postal, t.ville,
            GROUP_CONCAT(DISTINCT r.role_code) roles
       FROM tiers t LEFT JOIN tiers_roles r ON r.id_tiers = t.id AND r.actif = 1
      GROUP BY t.id")->fetchAll(PDO::FETCH_ASSOC);

$proprios = $pdo->query(
    "SELECT id, id_tiers, id_agence, type_personne, civilite, nom, prenom, societe, email,
            telephone, adresse_1, code_postal, ville, code_compte, actif
       FROM proprietaires")->fetchAll(PDO::FETCH_ASSOC);

/* ⚠️ ENRICHIR AVANT D'INDEXER. Un index construit sur les lignes brutes en garde une COPIE :
   les champs calcules ensuite n'y apparaissent jamais, et le rapprochement compare du vide. */
foreach ($tiers as &$t) {
    $t['_libelle'] = trim((string)($t['raison_sociale'] ?: $t['nom_affichage']
        ?: trim(((string)$t['nom']) . ' ' . ((string)$t['prenom']))));
    $t['_plat'] = plat($t['_libelle']);
    $t['_mots'] = mots($t['_plat']);
    $t['_nature'] = nature($t['_plat']);
}
unset($t);
foreach ($proprios as &$p) {
    $p['_libelle'] = trim((string)($p['societe'] ?: trim(((string)$p['nom']) . ' ' . ((string)$p['prenom']))));
    $p['_plat'] = plat($p['_libelle']);
    $p['_mots'] = mots($p['_plat']);
    $p['_nature'] = nature($p['_plat']);
}
unset($p);

/* index de recherche, construits APRES l'enrichissement */
$parCompte = [];
$parTiers = [];
foreach ($proprios as $p) {
    $c = trim((string)$p['code_compte']);
    if ($c !== '') $parCompte[$c][] = $p;
    if ($p['id_tiers']) $parTiers[(int)$p['id_tiers']][] = $p;
}

/** Score de recouvrement des mots signifiants — un CANDIDAT, jamais une preuve. */
function recouvrement(array $a, array $b): float
{
    if (!$a || !$b) return 0.0;
    $c = count(array_intersect($a, $b));
    return $c / max(count($a), count($b));
}

/* ── Les propriétaires source, dédoublonnés en IDENTITÉS ───────────────────────────────── */
$ARBITRAGES = json_decode(file_get_contents(__DIR__ . '/p2_arbitrages.json'), true);
$occurrences = json_decode(file_get_contents($SOURCE), true);
$identites = [];
foreach ($occurrences as $o) {
    /* ⚠️ UNE IDENTITÉ SOURCE N'EST PAS UNE OCCURRENCE. Un même propriétaire apparaît sur
       plusieurs CRG et plusieurs périodes sans devenir plusieurs propriétaires. La clé
       d'identité est le couple (périmètre, libellé normalisé) — le compte n'y entre pas :
       il est un indice de rapprochement, pas un composant de l'identité. */
    $cle = $o['agence'] . '|' . plat($o['nom_source']);
    if (!isset($identites[$cle])) {
        $identites[$cle] = ['agence' => $o['agence'], 'nom_source' => $o['nom_source'],
                            'plat' => plat($o['nom_source']), 'comptes' => [], 'occurrences' => [],
                            'nature' => nature(plat($o['nom_source']))];
    }
    $identites[$cle]['comptes'][$o['compte']] = true;
    $identites[$cle]['occurrences'][] = ['pdf' => $o['pdf'], 'sha' => $o['sha'],
                                         'page' => $o['page'], 'periode' => $o['periode']];
}

/* ── La décision, propriétaire par propriétaire ────────────────────────────────────────── */
$sortie = [];
foreach ($identites as $id) {
    $mots_src = mots($id['plat']);
    $comptes = array_keys($id['comptes']);
    $candidats = [];

    /* 1. Le compte personnel — l'indice le plus discriminant dont nous disposions.
          ⚠️ IL NE CERTIFIE PAS LE COMPTE MANDANT : cette phase ne porte que sur l'identité. */
    foreach ($comptes as $c) {
        foreach ($parCompte[$c] ?? [] as $p) {
            $candidats['P' . $p['id']] = ['proprietaire' => $p, 'via' => 'compte ' . $c,
                                          'score' => recouvrement($mots_src, $p['_mots'])];
        }
    }
    /* 2. Le nom, sur les propriétaires puis sur les tiers — pour PRODUIRE des candidats. */
    foreach ($proprios as $p) {
        $s = recouvrement($mots_src, $p['_mots']);
        if ($s >= 0.6 && !isset($candidats['P' . $p['id']])) {
            $candidats['P' . $p['id']] = ['proprietaire' => $p, 'via' => 'nom', 'score' => $s];
        }
    }
    $cand_tiers = [];
    foreach ($tiers as $t) {
        $s = recouvrement($mots_src, $t['_mots']);
        if ($s >= 0.6) $cand_tiers['T' . $t['id']] = ['tiers' => $t, 'score' => $s];
    }

    /* 3. La décision — et la preuve qui la porte. */
    $decision = 'CREER_TIERS_ET_PROPRIETAIRE';
    $certitude = 'CERTAIN';
    $preuve = 'aucun candidat MBI apres recherche par compte et par nom';
    $tiers_id = null; $prop_id = null;

    /* le meilleur candidat propriétaire : compte ET nom concordants */
    /* ⚠️ UNE CONCORDANCE DE COMPTE NE SE JETTE JAMAIS SUR UN SCORE DE NOM. Le seuil de 0,5
       ecartait « M. et Mme SABY Yves et Christelle » face a la fiche « MR & MME SABY » du MEME
       compte 01460000 — un mot commun sur trois — et renvoyait vers un homonyme d'un autre
       compte. Un compte qui concorde produit toujours un candidat : soit il emporte la
       decision, soit il part en arbitrage, jamais a la poubelle. */
    $forts = array_filter($candidats, fn($c) => strpos($c['via'], 'compte') === 0
        && $c['score'] >= 0.5
        && natures_compatibles($id['nature'], $c['proprietaire']['_nature']));
    usort($forts, fn($a, $b) => $b['score'] <=> $a['score']);

    if ($forts) {
        $p = $forts[0]['proprietaire'];
        $prop_id = (int)$p['id'];
        $tiers_id = $p['id_tiers'] ? (int)$p['id_tiers'] : null;
        $preuve = sprintf('%s + nom (recouvrement %.0f%%) : « %s » ~ « %s »',
            $forts[0]['via'], 100 * $forts[0]['score'], $id['nom_source'], $p['_libelle']);
        $decision = $tiers_id ? 'REUTILISER_TIERS_ET_PROPRIETAIRE' : 'REUTILISER_PROPRIETAIRE_RATTACHER_TIERS';
    } else {
        /* Pas de concordance compte + nom. On regarde les tiers, puis les proprietaires trouves
           par le nom seul — sans jamais conclure sur cette seule base. */
        $compat = array_values(array_filter($cand_tiers,
            fn($c) => natures_compatibles($id['nature'], $c['tiers']['_nature'])));
        usort($compat, fn($a, $b) => $b['score'] <=> $a['score']);
        if ($compat) {
            $t = $compat[0]['tiers'];
            $tiers_id = (int)$t['id'];
            $aFiche = !empty($parTiers[$tiers_id]);
            $certitude = $compat[0]['score'] >= 0.99 ? 'CERTAIN' : 'A_ARBITRER';
            $decision = $certitude === 'A_ARBITRER' ? 'A_ARBITRER'
                : ($aFiche ? 'REUTILISER_TIERS_ET_PROPRIETAIRE' : 'REUTILISER_TIERS_CREER_PROPRIETAIRE');
            if ($aFiche) $prop_id = (int)$parTiers[$tiers_id][0]['id'];
            $preuve = sprintf('nom seul (recouvrement %.0f%%) : « %s » ~ tiers #%d « %s »%s',
                100 * $compat[0]['score'], $id['nom_source'], $tiers_id, $t['_libelle'],
                $certitude === 'A_ARBITRER' ? ' — recouvrement partiel, insuffisant sans identifiant fort' : '');
        } elseif ($candidats) {
            /* ⚠️ DIRE LA VRAIE RAISON. Une premiere version annoncait « nature incompatible »
               sur TOUS ces cas, y compris « Madame HERR SOPHIE » vs « HERR SOPHIE » qui sont
               de meme nature. Presenter un arbitrage avec un motif faux fait arbitrer a cote. */
            $c = null;
            foreach ($candidats as $k) { $c = $k; break; }
            $p = $c['proprietaire'];
            $memeNature = natures_compatibles($id['nature'], $p['_nature']);
            $parCpt = strpos($c['via'], 'compte') === 0;
            $certitude = 'A_ARBITRER';
            $decision = 'A_ARBITRER';
            $prop_id = (int)$p['id'];
            $tiers_id = $p['id_tiers'] ? (int)$p['id_tiers'] : null;
            if (!$memeNature) {
                $motif = sprintf('nature incompatible : source %s / MBI %s', $id['nature'], $p['_nature']);
            } elseif ($parCpt) {
                $motif = sprintf('compte concordant mais nom trop different (recouvrement %.0f%%)',
                                 100 * $c['score']);
            } else {
                $motif = sprintf('nom proche (recouvrement %.0f%%) mais AUCUN compte concordant',
                                 100 * $c['score']);
            }
            /* Un nom MBI qui commence par un chiffre est presque toujours une adresse rangee
               dans un champ de nom : le signaler plutot que de le comparer serieusement. */
            if (preg_match('/^\d/', trim($p['_libelle']))) {
                $motif .= ' — ⚠ le libelle MBI ressemble a une ADRESSE, pas a un nom';
            }
            $preuve = sprintf('%s : « %s » ~ proprietaire #%d « %s »%s',
                $motif, $id['nom_source'], $prop_id, $p['_libelle'],
                $parCpt ? ' (via ' . $c['via'] . ')' : '');
        }
    }

    /* 3bis. ⚠️ GARDE-FOU ANTI-DOUBLON : ON NE CREE JAMAIS UNE FICHE SUR UN COMPTE QUI EN A
              DEJA UNE. Trois identites de VIENNE — DIAS, CUZIN, PHILOD — avaient ete decidees
              « creer proprietaire » parce qu'un TIERS portait leur nom : la fiche existait
              pourtant deja sur le meme compte, sous un libelle FAUX herite d'un ancien lecteur
              (« 25, Route de Berardier »). Creer par-dessus aurait fabrique le doublon que
              cette phase existe pour eviter. */
    if (in_array($decision, ['CREER_TIERS_ET_PROPRIETAIRE', 'REUTILISER_TIERS_CREER_PROPRIETAIRE'], true)) {
        $dejaLa = [];
        foreach ($comptes as $c) foreach ($parCompte[$c] ?? [] as $p) $dejaLa[] = $p;
        if ($dejaLa) {
            $p = $dejaLa[0];
            $prop_id = (int)$p['id'];
            $decision = 'A_ARBITRER';
            $certitude = 'A_ARBITRER';
            $adresse = preg_match('/^\d/', trim($p['_libelle']))
                ? ' — ⚠ le libelle MBI ressemble a une ADRESSE, pas a un nom' : '';
            $preuve = sprintf('fiche proprietaire #%d DEJA PRESENTE sur le compte %s sous le libelle '
                . '« %s »%s ; ne pas creer par-dessus. %s',
                $prop_id, $p['code_compte'], $p['_libelle'], $adresse, $preuve);
        }
    }

    /* 3ter. ⚠️ UN ARBITRAGE RENDU NE SE RECALCULE PAS. Il est ecrit dans p2_arbitrages.json
              avec son motif, et il prime sur toute heuristique — c'est ce qui rend la decision
              relisible dans six mois : « pourquoi ce proprietaire est-il le TIERS #5 ? Parce
              qu'Emmanuel l'a tranche le 30/08/2026, et voici sa phrase. » */
    $cleArb = $id['agence'] . '|' . $id['plat'];
    $arb = $ARBITRAGES[$cleArb] ?? null;
    if (!$arb && ($ARBITRAGES['_vienne']['correspondances'] ?? null)) {
        foreach ($comptes as $c) {
            if (isset($ARBITRAGES['_vienne']['correspondances'][$c])) {
                $v = $ARBITRAGES['_vienne']['correspondances'][$c];
                $arb = ['decision' => $ARBITRAGES['_vienne']['decision'],
                        'proprietaire' => $v['proprietaire'],
                        'motif' => $ARBITRAGES['_vienne']['motif'] . ' Nom retenu : « ' . $v['nom_crg'] . ' ».'];
                break;
            }
        }
    }
    if ($arb) {
        $decision = $arb['decision'];
        $certitude = 'CERTAIN (arbitre)';
        $prop_id = $arb['proprietaire'] ?? $prop_id;
        $tiers_id = $arb['tiers'] ?? $tiers_id;
        $preuve = 'ARBITRAGE EMMANUEL 30/08/2026 — ' . $arb['motif'];
    }

    /* 4. Doublons MBI déjà présents — signalés, jamais fusionnés. */
    $doublons = [];
    if ($tiers_id && count($parTiers[$tiers_id] ?? []) > 1) {
        foreach ($parTiers[$tiers_id] as $p) $doublons[] = ['id_proprietaire' => (int)$p['id'],
                                                            'libelle' => $p['_libelle'],
                                                            'compte' => $p['code_compte']];
    }

    $sortie[] = [
        'agence' => $id['agence'], 'nom_source_crg' => $id['nom_source'],
        'identite_normalisee' => $id['plat'], 'nature_source' => $id['nature'],
        'comptes_crg' => $comptes, 'occurrences' => count($id['occurrences']),
        'crg_fichier' => $id['occurrences'][0]['pdf'], 'sha' => $id['occurrences'][0]['sha'],
        'page' => $id['occurrences'][0]['page'],
        'tiers_mbi_candidat' => $tiers_id, 'proprietaire_mbi_candidat' => $prop_id,
        'decision' => $decision, 'niveau_certitude' => $certitude, 'preuve_matching' => $preuve,
        'doublons_mbi_potentiels' => count($doublons) > 1 ? $doublons : [],
    ];
}

file_put_contents($SORTIE, json_encode($sortie, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
printf("identites source : %d  ->  %s%s", count($sortie), $SORTIE, PHP_EOL);
$par = [];
foreach ($sortie as $s) $par[$s['agence']][$s['decision']] = ($par[$s['agence']][$s['decision']] ?? 0) + 1;
foreach ($par as $ag => $d) {
    printf("%s%-16s%s", PHP_EOL, $ag, PHP_EOL);
    foreach ($d as $k => $v) printf("   %-40s %3d%s", $k, $v, PHP_EOL);
}
