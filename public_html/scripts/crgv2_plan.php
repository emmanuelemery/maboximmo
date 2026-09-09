<?php
declare(strict_types=1);
/**
 * crgv2_plan.php — DU STAGING CRG VERS UN PLAN D'ÉCRITURE POUR LA BASE V2.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ POURQUOI UN PLAN, ET PAS UNE ÉCRITURE DIRECTE.
 *    Le staging `crgi_*` est ICI, en local, avec les PDF et le moteur de lecture. La base V2
 *    est LÀ-BAS, dans un conteneur sur le VPS, joignable seulement de l'intérieur du réseau
 *    Docker. Ouvrir un tunnel ou fabriquer un compte de passage pour les relier ajouterait
 *    une surface d'attaque à une machine de production, pour une commodité.
 *
 *    On sépare donc les deux moitiés du travail, et la séparation a un bénéfice propre :
 *
 *      CE FICHIER            décide. Toute la lecture métier — qui est une personne morale,
 *                            quel lot est un local commercial, quelle occupation est close —
 *                            se fait ici, où elle est relisible et rejouable à volonté.
 *      `crgv2_ecrire.php`    exécute. Il ne décide de rien : il résout des clés temporaires
 *                            en identifiants réels, insère, et journalise.
 *
 * ⚠️ UNE LIGNE N'EST PAS UN OBJET. Le staging porte 2 291 lignes de lot pour ~1 125 lots :
 *    un lot réapparaît à chaque période. Tout ce fichier est un travail de REPLIEMENT, et
 *    c'est là que se logent les erreurs de comptage. Chaque famille est repliée sur une clé
 *    explicite, jamais sur « la ligne ».
 *
 * Usage :
 *   php crgv2_plan.php                 tous les dépôts, un plan par dépôt
 *   php crgv2_plan.php 4               le seul dépôt 4
 */

const SORTIE = 'C:/tmp/crgv2';

// ⚠️ LA NORMALISATION EST PARTAGÉE AVEC L'ÉCRIVAIN, ET C'EST LE POINT. Deux copies du même
//    code sont deux normalisations qui vont diverger — et un rapprochement qui diverge ne
//    rapproche rien, en silence.
require_once __DIR__ . '/crgv2_norm.php';

/** Les bornes d'une période, quand le document ne les a pas imprimées. */
function bornes(?string $cle, ?string $debut, ?string $fin): array
{
    if ($debut && $fin) return [$debut, $fin];
    $cle = trim((string)$cle);
    if (preg_match('/^(\d{4})-T([1-4])$/', $cle, $m)) {
        $d = sprintf('%s-%02d-01', $m[1], ((int)$m[2] - 1) * 3 + 1);
        return [$d, date('Y-m-t', strtotime($d . ' +2 months'))];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $cle, $m)) {
        $d = "$m[1]-$m[2]-01";
        return [$d, date('Y-m-t', strtotime($d))];
    }
    if (preg_match('/^(\d{4})$/', $cle, $m)) return ["$m[1]-01-01", "$m[1]-12-31"];
    // ⚠️ ON NE REPLIE PAS SUR UN JOUR. Une borne inventée trop étroite fait disparaître des
    //    faits : un trimestre réduit à sa date d'arrêté avait mis 4 appels de loyer à zéro.
    return [$debut ?: null, $fin ?: ($debut ?: null)];
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  L'IDENTITÉ — une chaîne imprimée devient une personne
// ═══════════════════════════════════════════════════════════════════════════════════════════

/**
 * Les formes juridiques. Leur présence en MOT ENTIER fait la personne morale.
 *
 * ⚠️ EN MOT ENTIER, JAMAIS EN SOUS-CHAÎNE. « SA » est une forme juridique ; « SABATIER »
 *    ne l'est pas, et une recherche par `strpos` en ferait une société.
 */
const FORMES_MORALES = [
    'SCI','SARL','SAS','SASU','SA','EURL','SNC','SCP','SCM','SCCV','SCA','SEM','SEP',
    'GFA','GIE','GAEC','SCEA','SDC','ASL','AFUL','EARL','SELARL','SELAS','SPFPL','SCIC',
    'ASSOCIATION','SYNDICAT','MUTUELLE','FONDATION','INDIVISION','SUCCESSION','CCAS',
    'COPROPRIETE','CABINET','OFFICE','ETS','ETABLISSEMENTS','GROUPE','HOLDING','COMMUNE',
    'SOCIETE','ENTREPRISE',
];

/**
 * Les articles et particules : ils ne sont JAMAIS un patronyme à eux seuls.
 *
 * ⚠️ SANS EUX, « LE LAGON BLEU » DEVENAIT nom=`LE`, prénoms=`LAGON BLEU`. Mesuré :
 *    **27 tiers** portaient un nom de deux lettres — `LE`, `LA`, `AU`, `DA`, `EL`, `DE` —
 *    parce que la règle « tout en capitales : le premier mot fait le nom » prenait l'article
 *    pour le patronyme.
 */
const PARTICULES = ['LE','LA','LES','L','AU','AUX','DE','DU','DES','D','DA','DO','DOS','DI',
                    'VAN','VON','DER','EL','AL','SAINT','ST','MAC','MC','O'];

/**
 * Les enseignes. Un nom de commerce n'a pas de forme juridique imprimée mais désigne
 * quand même une personne morale — et le CRG en est plein côté locataires de boutique.
 */
const MOTS_COMMERCE = [
    'PHARMACIE','BOULANGERIE','PATISSERIE','BOUCHERIE','RESTAURANT','ROTISSERIE','PIZZERIA',
    'BRASSERIE','TABAC','PRESSE','COIFFURE','OPTIQUE','GARAGE','AUTO','TAXI','TRANSPORT',
    'IMMOBILIER','IMMO','ASSURANCE','BANQUE','CLINIQUE','LABORATOIRE','PHARMA','MEDICAL',
    'SUPERMARCHE','ALIMENTATION','TRAITEUR','FLEURS','FLEURISTE','BIJOUTERIE','MEUBLES',
    'GESTION','SERVICES','CONSEIL','CONSULTING','FORMATION','AGENCE','BOUTIQUE','MAGASIN',
    'SNACK','KEBAB','SUSHI','BURGER','CAFE','BAR','HOTEL','SALON','INSTITUT','ATELIER',
    'FOOD','HABILLEMENT','MANUFACTURE','DISTRIB','DISTRIBUTION','EXPLOITATION','CONCIERGERIE',
    'FOURNIL','EPICERIE','PRIMEUR','PRESSING','LAVERIE','TEINTURERIE','COMMERCE','NEGOCE',
];

/** Les civilités, qui prouvent une personne physique et qu'on retire du nom. */
const CIVILITES = [
    'MONSIEUR ET MADAME','MONSIEUR OU MADAME','M ET MME','M OU MME','MR ET MME','MR OU MME',
    'MONSIEUR','MADAME','MADEMOISELLE','MLLE','MELLE','MME','MR','M',
];

/**
 * Ce que le document a imprimé → une identité typée.
 *
 * ⚠️ `chk_tiers_forme` DE LA BASE V2 EST STRICT : une personne MORALE porte `denomination`
 *    et RIEN d'autre ; une personne PHYSIQUE porte `nom` et jamais `denomination`. Une
 *    identité mal typée ne passe pas — elle est refusée par la base, pas avalée.
 *
 * ⚠️ ET LA CHAÎNE BRUTE N'EST JAMAIS PERDUE. Elle reste dans `mandants.libelle` et dans
 *    `occupation_occupants.nom_lu`. Si ce typage se trompe, la preuve de ce qu'on a lu est
 *    toujours là pour le corriger — c'est ce qui rend l'heuristique acceptable.
 */
function identite(string $brut): array
{
    $brut = trim(preg_replace('/\s+/', ' ', $brut));
    // ⚠️ LA CIVILITÉ EST PARFOIS COLLÉE AU NOM — « MonsieurVENET Roland ». Le lecteur PDF
    //    perd l'espace, et la détection de civilité ne trouvait alors rien : le tiers entrait
    //    sous le nom « MonsieurVENET ». On la décolle avant tout le reste.
    $brut = preg_replace('/^(Monsieur|Madame|Mademoiselle|Mme|Mlle|Mrs?)(?=\p{Lu})/u', '$1 ', $brut);
    // ⚠️ ET LE DOCUMENT DOUBLE PARFOIS LA FORME JURIDIQUE : « SCI SCI CINCO GINKO ».
    $brut = preg_replace('/\b(SCI|SARL|SAS|SASU|EURL|SCP|SNC|SA)\s+\1\b/iu', '$1', $brut);
    $brut = trim(preg_replace('/\s+/', ' ', $brut));

    $p    = plat($brut);
    if ($p === '') return [];

    // ⚠️ UN EN-TÊTE DE PAGE N'EST PAS UNE PERSONNE. J'avais posé cette garde sur les
    //    adresses et pas sur les noms : « RECAPITULATIF DES OPERATIONS Débits Crédits Dont
    //    T.V.A. » est entré comme un TIERS de type PHYSIQUE. La structure du document se
    //    reconnaît au même endroit, quelle que soit la colonne où on la ramasse.
    if (nomSuspect($brut)) return [];

    $mots = explode(' ', $p);

    // ⚠️ UN NOM TOUT EN CAPITALES QUI COMMENCE PAR UN ARTICLE EST UNE ENSEIGNE, PAS UNE
    //    PERSONNE. « LE LAGON BLEU », « AU PLAISIR DU SENEGAL », « LA MAISON DU BOUTON ».
    //    La nuance est la CASSE : « LE GOFF Mélanie » porte un prénom en minuscules, donc
    //    c'est un patronyme breton et non une enseigne — et il reste PHYSIQUE.
    if (in_array($mots[0], ['LE','LA','LES','AU','AUX'], true)
        && count($mots) >= 2 && mb_strtoupper($brut, 'UTF-8') === $brut) {
        return ['type' => 'MORALE', 'denomination' => mb_substr($brut, 0, 255),
                'nom' => null, 'prenoms' => null, 'regle' => 'ENSEIGNE ARTICLE'];
    }

    foreach (FORMES_MORALES as $f) {
        if (in_array($f, $mots, true)) {
            return ['type' => 'MORALE', 'denomination' => mb_substr($brut, 0, 255),
                    'nom' => null, 'prenoms' => null, 'regle' => 'FORME JURIDIQUE'];
        }
    }

    foreach (CIVILITES as $c) {
        if (str_starts_with($p, $c . ' ')) {
            // On repart du BRUT pour garder la casse — `plat()` ne conserve pas les
            // positions d'origine — en retirant autant de mots que la civilité en compte.
            $reste = trim(implode(' ', array_slice(explode(' ', $brut), count(explode(' ', $c)))));
            return decouperPhysique($reste !== '' ? $reste : $brut, 'CIVILITE');
        }
    }

    foreach (MOTS_COMMERCE as $m) {
        if (in_array($m, $mots, true)) {
            return ['type' => 'MORALE', 'denomination' => mb_substr($brut, 0, 255),
                    'nom' => null, 'prenoms' => null, 'regle' => 'ENSEIGNE'];
        }
    }

    return decouperPhysique($brut, 'DEFAUT');
}

/**
 * NOM / PRÉNOMS, à partir de la casse quand elle parle, du rang sinon.
 *
 * ⚠️ LA CASSE EST LE SIGNAL LE PLUS FIABLE : « EMERY DUVAREILLE Barbara » dit lui-même où
 *    finit le nom. Quand tout est en capitales — « SARKISSIAN CHRISTELLE » — il ne reste
 *    que le rang, et le premier mot fait le nom.
 *
 * ⚠️ UN SÉPARATEUR COLLE LES MOTS QU'IL RELIE. « COUTURIER - VIRICEL » est UN nom composé ;
 *    couper au premier mot en ferait un prénom « - VIRICEL ».
 */
function decouperPhysique(string $s, string $regle): array
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($s === '') return [];
    $mots = explode(' ', $s);

    $estMaj  = static fn(string $m): bool =>
        $m !== '' && mb_strtoupper($m, 'UTF-8') === $m && preg_match('/\p{L}/u', $m) === 1;
    $estLien = static fn(string $m): bool => in_array($m, ['-', '/', '&', 'ET', 'OU'], true);

    $nom = [];
    $i   = 0;
    while ($i < count($mots)) {
        $m = $mots[$i];
        if ($estMaj($m) || ($estLien($m) && isset($mots[$i + 1]) && $estMaj($mots[$i + 1]))) {
            $nom[] = $m;
            $i++;
            continue;
        }
        break;
    }

    if (!$nom) {  // aucune capitale : tout est nom, aucun prénom n'est démontré
        return ['type' => 'PHYSIQUE', 'nom' => mb_substr($s, 0, 150), 'prenoms' => null,
                'denomination' => null, 'regle' => $regle . ' SANS CASSE'];
    }

    $prenoms = trim(implode(' ', array_slice($mots, $i)));

    // Tout en capitales : la casse n'a rien dit. Le premier bloc fait le nom, le reste les
    // prénoms — et s'il n'y a qu'un mot, il n'y a pas de prénom.
    if ($prenoms === '' && count($nom) > 1) {
        $coupe = 1;
        while ($coupe < count($nom) && $estLien($nom[$coupe])) $coupe += 2;
        // ⚠️ UNE PARTICULE N'EST JAMAIS UN PATRONYME À ELLE SEULE. « DA SILVA VILAS BOAS »
        //    donnait nom=`DA` ; « EL MKAREM HASSAN » donnait nom=`EL`. La particule se colle
        //    au mot qui suit — 27 tiers portaient ainsi un nom de deux lettres.
        while ($coupe < count($nom) && in_array(plat($nom[$coupe - 1]), PARTICULES, true)) {
            $coupe++;
        }
        $prenoms = trim(implode(' ', array_slice($nom, $coupe)));
        $nom     = array_slice($nom, 0, $coupe);
    }

    return ['type' => 'PHYSIQUE', 'nom' => mb_substr(implode(' ', $nom), 0, 150),
            'prenoms' => $prenoms !== '' ? mb_substr($prenoms, 0, 190) : null,
            'denomination' => null, 'regle' => $regle];
}

/**
 * Un libellé de locataire → UNE ou PLUSIEURS personnes.
 *
 * ⚠️ LES BARRES OBLIQUES NE SÉPARENT PAS TOUJOURS DEUX PERSONNES. Dans l'export SPI elles
 *    séparent le plus souvent la colonne NOM de la colonne PRÉNOM : « SY/// Adama » est
 *    UNE personne, « BURDULEA//// GABRIELA » aussi. Mais « BILLARD Justine// SCHULTZ Célia »
 *    en porte DEUX. Ce qui les distingue : deux parts qui portent CHACUNE au moins deux
 *    mots ressemblent à deux identités complètes ; une part d'un seul mot est une colonne.
 *
 * ⚠️ ON NE COUPE PAS SUR « et ». « AYAD Oumar et Noria » est un ménage sous un seul nom :
 *    couper donnerait un tiers « Noria » sans patronyme.
 */
function occupants(string $brut): array
{
    $brut = trim(preg_replace('/\s+/', ' ', $brut));
    if ($brut === '') return [];
    $parts    = array_values(array_filter(array_map('trim', preg_split('#/+#', $brut)), fn($x) => $x !== ''));
    $complets = array_filter($parts, fn($x) => count(explode(' ', $x)) >= 2);
    if (count($parts) >= 2 && count($complets) >= 2) return $parts;
    return [trim(implode(' ', $parts))];
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  LES CORRESPONDANCES
// ═══════════════════════════════════════════════════════════════════════════════════════════

/**
 * L'éditeur lu → le référentiel de `objet_codes`.
 *
 * ⚠️ `lyon` N'EST PAS UNE VALEUR ACCEPTÉE PAR LA BASE V2. Son CHECK liste `loca_immo_lyon` :
 *    le staging porte le nom court de la VARIANTE du moteur, la base porte le nom du
 *    RÉFÉRENTIEL. Sans cette table, chaque code de Lyon serait refusé.
 */
const SYSTEMES = ['lyon' => 'loca_immo_lyon', 'emery_immo' => 'emery_immo', 'septeo_spi' => 'septeo_spi'];

/** Ce que le CRG imprime en TYPE → le vocabulaire fermé `biens_natures`. */
const NATURES = [
    'APPARTEMENT' => 'appartement', 'MAISON' => 'maison', 'HABITATION' => 'habitation',
    'LOCAL COMMERCIAL' => 'local_commercial', 'BUREAU' => 'bureau', 'GARAGE' => 'garage',
    'TERRAIN' => 'terrain', 'PARTIES COMMUNES' => 'parties_communes', 'PANNEAU' => 'panneau',
];

/**
 * Ce que le LIBELLÉ dit de plus précis que le type.
 *
 * ⚠️ ENTRE DEUX LECTURES VRAIES, ON GARDE LA PLUS FINE. Le CRG classe un entrepôt en
 *    « LOCAL COMMERCIAL » — ce n'est pas faux, c'est la maille au-dessus — mais son libellé
 *    dit « Entrepot ». Ne lire que le type, c'était jeter une information IMPRIMÉE : 13 lots
 *    sont dans ce cas, et un entrepôt ne s'évalue pas comme une boutique.
 *
 * ⚠️ ON N'AFFINE QUE VERS UNE NATURE QUE LE MOT DÉSIGNE SANS AMBIGUÏTÉ. « Local commercial
 *    BUREAUX » n'est pas ici : on ne sait pas si ce sont des bureaux OU un local commercial
 *    qui en comporte, et deviner rangerait une supposition dans une colonne que la taxe
 *    foncière lira comme un fait.
 *
 * ⚠️ ET LE MOTIF EST ANCRÉ, PAS CHERCHÉ N'IMPORTE OÙ. « Garage BOX » contient BOX et reste
 *    un garage ; « Parking Parking couvert » COMMENCE par Parking. Un motif non ancré aurait
 *    aussi attrapé « Appartement avec parking » et rangé un logement en annexe.
 */
const NATURES_LIBELLE = [
    '/\bENTREPOT\b/' => 'entrepot',
    '/^PARKING\b/'   => 'parking',
    '/^CAVE\b/'      => 'cave',
    '/^RESERVE\b/'   => 'dependance',
    '/^ATELIER\b/'   => 'atelier',
    '/^CHAMBRE\b/'   => 'chambre',
    '/^GRENIER\b/'   => 'grenier',
    '/^JARDIN\b/'    => 'jardin',
];

function natureDuLot(?string $typeBien, ?string $libelle): ?string
{
    $l = plat($libelle);
    foreach (NATURES_LIBELLE as $motif => $fine) {
        if (preg_match($motif, $l) === 1) return $fine;
    }
    return NATURES[strtoupper(trim((string)$typeBien))] ?? null;
}

/** Un code d'objet doit passer `chk_oc_code`. Un code qui ne passe pas n'est pas réparé. */
function codeValide(?string $c): bool
{
    return $c !== null && $c !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $c) === 1;
}

/**
 * Ce qui n'est PAS une adresse, bien qu'imprimé à la place de l'adresse.
 *
 * ⚠️ ON FILTRE PAR LA STRUCTURE DU DOCUMENT, PAS PAR LA FORME DE LA RUE. Ma première
 *    tentative exigeait un numéro ou un type de voie en tête : elle rejetait « Chabrepine »,
 *    « LIEUDIT LE MONINSABLE », « ZAC DU TISSOT », « MAIL DE ROCHELONGUE » — des adresses
 *    parfaitement valides. Une règle qui décrit ce qu'une adresse DOIT être écarte les
 *    lieux-dits ; une règle qui nomme les en-têtes du document n'écarte que les en-têtes.
 *
 * ⚠️ ET L'ADRESSE DE L'AGENCE N'EST PAS CELLE DE L'IMMEUBLE. Emmanuel, 08/09/2026 : « sans
 *    mélanger l'adresse du pro, de l'agence etc. ». Un CRG imprime les deux sur la même page.
 *
 * Mesuré sur le corpus : 13 en-têtes et 1 nom d'agence sur 512 adresses lues.
 */
const ENTETES_DOCUMENT = [
    'SITUATION DES LOCATAIRES', 'RECAPITULATIF DES OPERATIONS', 'COMPTE RENDU DE GESTION',
    'DETAIL DES OPERATIONS', 'DETAIL DES CHARGES', 'ARRETE AU', 'PERIODE DU', 'REPORT A NOUVEAU',
    'TOTAL GENERAL', 'HONORAIRES DE GESTION', 'SOLDE AU', 'DEBITS CREDITS',
];

/**
 * Nos raisons sociales. Elles disqualifient une ADRESSE — jamais un NOM.
 *
 * ⚠️ UN NOM D'AGENCE EST UNE MAUVAISE ADRESSE MAIS UN EXCELLENT NOM DE PERSONNE. En
 *    appliquant cette liste aux noms, j'ai fait disparaître **DE GASPERIS Thierry, Peggy et
 *    Jacqueline** — de vrais propriétaires, dont la famille a donné son nom à l'agence — et
 *    nos propres sociétés quand elles sont elles-mêmes bailleresses ou locataires. Six
 *    propriétaires et huit locataires perdus, en silence.
 *
 *    Une liste d'exclusion ne vaut que pour le CHAMP sur lequel elle a été calibrée.
 */
const NOS_SOCIETES = [
    'AGENCE EMERY', 'REGIE EMERY', 'EMERY IMMO', 'LOCA IMMO', 'DE GASPERIS', 'MABOXIMMO',
];

/** Ce qui n'est PAS une adresse : la structure du document, ou une de nos raisons sociales. */
function adresseSuspecte(?string $a): bool
{
    $p = plat($a);
    if ($p === '') return true;
    foreach (ENTETES_DOCUMENT as $m) if (str_contains($p, $m)) return true;
    foreach (NOS_SOCIETES  as $m) if (str_contains($p, $m)) return true;
    return false;
}

/** Ce qui n'est PAS un nom de personne : la structure du document, et ELLE SEULE. */
function nomSuspect(?string $n): bool
{
    $p = plat($n);
    if ($p === '') return true;
    foreach (ENTETES_DOCUMENT as $m) if (str_contains($p, $m)) return true;
    return false;
}

/**
 * Un nom qui EST une adresse. 121 immeubles du corpus n'ont pas d'autre adresse que leur
 * nom — « 312 COURS LAFAYETTE » — et la taxe foncière en aura besoin.
 */
function nomEstAdresse(?string $n): bool
{
    $p = plat($n);
    return $p !== '' && !adresseSuspecte($n) && (
        preg_match('/^\d/', $p) === 1 ||
        preg_match('/^(RUE|AVENUE|AV|BOULEVARD|BD|PLACE|IMPASSE|CHEMIN|ALLEE|ROUTE|RTE|COURS'
                 . '|QUAI|MONTEE|LOTISSEMENT|RESIDENCE|SQUARE|PASSAGE|GRANDE RUE|CITE|TRAVERSE'
                 . '|VOIE|ESPLANADE|HAMEAU|CLOS|DOMAINE|VILLA|LIEUDIT|ZAC|MAIL|MAISON) /', $p) === 1
    );
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  UNION — replier deux groupes qui désignent le même objet
// ═══════════════════════════════════════════════════════════════════════════════════════════

/** Le représentant d'un groupe, avec compression de chemin. */
function racine(array &$parent, string $x): string
{
    while (($parent[$x] ?? $x) !== $x) {
        $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
        $x = $parent[$x];
    }
    return $x;
}

function unir(array &$parent, string $a, string $b): void
{
    $ra = racine($parent, $a);
    $rb = racine($parent, $b);
    if ($ra !== $rb) $parent[$rb] = $ra;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  LA CONSTRUCTION DU PLAN
// ═══════════════════════════════════════════════════════════════════════════════════════════

function pdoStaging(): PDO
{
    $mdp = trim((string)file_get_contents('C:/Users/emery/.mbi/mbi_agent.pass'));
    return new PDO('mysql:host=127.0.0.1;dbname=mbi_bis;charset=utf8mb4', 'mbi_agent', $mdp,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * Le référentiel de CHAQUE AGENCE, déduit de son corpus.
 *
 * ⚠️ LE SYSTÈME SUIT L'AGENCE, PAS LE FORMAT LU SUR LA PAGE. Le moteur ICS a deux variantes
 *    — `lyon` et `emery_immo` — qui lisent le MÊME référentiel de codes. Mettre la variante
 *    dans la clé dédoublait les objets : **22 immeubles et 31 lots** de l'agence 3 portaient
 *    le même code sous les deux variantes, et seraient entrés deux fois.
 */
function systemesParAgence(PDO $pdo): array
{
    $out = [];
    $st = $pdo->query("SELECT agence_id, format, COUNT(*) n FROM crgi_crg
                        WHERE agence_id IS NOT NULL AND agence_id > 0
                        GROUP BY 1,2 ORDER BY 1, 3 DESC");
    foreach ($st as $r) {
        $ag = (int)$r['agence_id'];
        $sy = SYSTEMES[$r['format']] ?? null;
        if ($sy && !isset($out[$ag])) $out[$ag] = $sy;   // le plus fréquent en premier
    }
    return $out;
}

function construire(PDO $pdo, int $importId, array $sysAgence, array $adresses): array
{
    $imp = $pdo->query("SELECT * FROM crgi_import WHERE id = $importId")->fetch(PDO::FETCH_ASSOC);
    if (!$imp) throw new RuntimeException("Dépôt $importId inconnu.");

    $crgs = $pdo->query(
        "SELECT id, agence_id, format, compte, proprietaire, periode_cle, periode_debut, periode_fin
           FROM crgi_crg WHERE import_id = $importId"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ctx = [];
    $ecartes = ['crg_sans_agence' => 0, 'compte_illisible' => 0, 'lot_sans_reference' => 0,
                'lot_sans_immeuble' => 0, 'immeuble_sans_identite' => 0];
    foreach ($crgs as $c) {
        $ag = (int)$c['agence_id'];
        if (!$ag || !isset($sysAgence[$ag])) { $ecartes['crg_sans_agence']++; continue; }
        [$d, $f] = bornes($c['periode_cle'], $c['periode_debut'], $c['periode_fin']);
        $ctx[(int)$c['id']] = ['agence' => $ag, 'systeme' => $sysAgence[$ag],
                               'compte' => trim((string)$c['compte']),
                               'proprietaire' => trim((string)$c['proprietaire']),
                               'debut' => $d, 'fin' => $f, 'mandant_k' => null];
    }

    $tiers = $mandants = [];
    $regles = [];

    /**
     * Replie une identité sur sa forme normalisée — LE point de non-duplication des tiers.
     *
     * ⚠️ LA CLÉ SE CALCULE SUR L'IDENTITÉ ANALYSÉE, PAS SUR LA CHAÎNE BRUTE. La base ne
     *    stocke pas « Monsieur XERRI Florent » : elle stocke nom=XERRI, prenoms=Florent.
     *    Une clé prise sur le brut donnerait `MONSIEUR XERRI FLORENT`, que l'écrivain ne
     *    pourrait JAMAIS retrouver en relisant la base — et il recréerait le tiers à chaque
     *    dépôt. Les deux membres d'une comparaison d'identité passent par la même fonction,
     *    et ici cette fonction s'applique à la MÊME forme des deux côtés.
     *
     *    Bénéfice second : « Monsieur XERRI Florent », « M. XERRI Florent » et « XERRI
     *    Florent » se replient enfin sur un seul tiers.
     */
    $poserTiers = function (string $brut, ?array $adresse = null, ?string $vue = null)
                  use (&$tiers, &$regles): ?string {
        $id = identite($brut);
        if (!$id) return null;
        $k = cleTiers($id['type'], $id['denomination'] ?? null, $id['nom'] ?? null, $id['prenoms'] ?? null);
        if ($k === '') return null;
        if (!isset($tiers[$k])) {
            $regles[$id['regle']] = ($regles[$id['regle']] ?? 0) + 1;
            unset($id['regle']);
            $tiers[$k] = $id + ['k' => $k, 'adresse' => null, 'adresse_vue' => null];
        }
        // ⚠️ LA PLUS RÉCENTE L'EMPORTE. Une personne déménage ; le CRG du trimestre suivant
        //    porte alors sa nouvelle adresse, et c'est celle-là qu'on veut sur l'enveloppe.
        if ($adresse && (string)$vue >= (string)$tiers[$k]['adresse_vue']) {
            $tiers[$k]['adresse']     = $adresse;
            $tiers[$k]['adresse_vue'] = $vue;
        }
        return $k;
    };

    // ═══ LES COMPTES MANDANTS — clé : agence × compte ═══════════════════════════════════
    foreach ($ctx as $crgId => $c) {
        if (!codeValide($c['compte'])) { $ecartes['compte_illisible']++; continue; }
        $k = $c['agence'] . '|' . $c['compte'];
        if (!isset($mandants[$k])) {
            $mandants[$k] = ['k' => $k, 'id_agence' => $c['agence'], 'libelle' => $c['proprietaire'],
                             'tiers_k' => null, 'systeme' => $c['systeme'], 'code' => $c['compte'],
                             'debut' => $c['debut'], 'fin' => $c['fin']];
        }
        $m =& $mandants[$k];
        // Le libellé le plus RÉCENT l'emporte : c'est celui que l'agence utilise aujourd'hui.
        if ($c['proprietaire'] !== '' && (string)$c['fin'] >= (string)$m['fin']) {
            $m['libelle'] = $c['proprietaire'];
            $m['fin']     = $c['fin'];
        }
        if ($c['debut'] && $c['debut'] < (string)$m['debut']) $m['debut'] = $c['debut'];
        if ($c['proprietaire'] !== '') {
            // ⚠️ L'ADRESSE DU PROPRIÉTAIRE EST IMPRIMÉE SUR CHAQUE CRG — c'est un document
            //    qu'on met sous enveloppe. Je l'avais dite absente et je n'avais rien lu :
            //    `crgv2_adresses.php` la relit sur la première page, 903 CRG sur 948.
            $tk = $poserTiers($c['proprietaire'], $adresses[$crgId] ?? null, $c['fin']);
            if (!$m['tiers_k']) $m['tiers_k'] = $tk;
        }
        unset($m);
        $ctx[$crgId]['mandant_k'] = $k;
    }

    // ═══ LES IMMEUBLES ══════════════════════════════════════════════════════════════════
    //
    // ⚠️ UN IMMEUBLE SANS CODE RESTE UN IMMEUBLE. 49 bâtiments de VIENNE et CHAPONOST
    //    portent une adresse imprimée mais aucun numéro : les écarter faute de code
    //    perdrait exactement ce que la taxe foncière viendra chercher. Ils entrent, et
    //    l'écrivain leur frappera un code `mbi` — la migration 0017 prévoit ce cas.
    //
    // ⚠️ ET DEUX CODES PEUVENT DÉSIGNER UN SEUL BÂTIMENT. Un immeuble partagé entre deux
    //    bailleurs reçoit UN CODE PAR COMPTE. On replie donc sur l'adresse quand elle est
    //    lue, et `objet_codes` porte les DEUX codes — c'est la raison d'être de cette table.
    $rangAdresse = ['LUE' => 3, 'NOM EST UNE ADRESSE' => 2, 'ABSENTE' => 0];
    $lignes = $pdo->query("SELECT crg_id, code, nom, adresse, adresse_source, code_postal, ville
                             FROM crgi_immeuble WHERE import_id = $importId")->fetchAll(PDO::FETCH_ASSOC);

    $parent = [];                       // union-find sur les groupes d'immeuble
    $groupes = [];                      // groupe provisoire → données accumulées
    $parCrg  = [];                      // crg_id → groupes rencontrés (pour le rattachement)
    $parCode = [];                      // agence|code → groupe (pour le rattachement des lots)

    foreach ($lignes as $r) {
        $c = $ctx[(int)$r['crg_id']] ?? null;
        if (!$c) continue;
        $code = trim((string)$r['code']);
        $rang = $rangAdresse[(string)$r['adresse_source']] ?? 0;
        $adr  = trim((string)$r['adresse']);
        $nom  = trim((string)$r['nom']);

        // ⚠️ UN EN-TÊTE DE PAGE N'EST PAS UNE ADRESSE. On l'écarte AVANT qu'il ne serve de
        //    clé de repliement : deux immeubles distincts partageant le même en-tête
        //    « SITUATION DES LOCATAIRES - 2e Trimestre 2026 » seraient fusionnés en un seul.
        if ($adr !== '' && adresseSuspecte($adr)) {
            $ecartes['adresse_est_un_entete'] = ($ecartes['adresse_est_un_entete'] ?? 0) + 1;
            $adr  = '';
            $rang = 0;
        }

        $cleAdr = ($rang > 0 && $adr !== '')
                ? 'A:' . $c['agence'] . '|' . plat($adr) . '|' . trim((string)$r['code_postal'])
                                       . '|' . plat((string)$r['ville'])
                : null;

        if (codeValide($code))      $g = 'C:' . $c['agence'] . '|' . $code;
        elseif ($cleAdr !== null)   $g = $cleAdr;
        elseif ($nom !== '')        $g = 'N:' . $c['agence'] . '|' . plat($nom);
        else { $ecartes['immeuble_sans_identite']++; continue; }

        $parent[$g] = $parent[$g] ?? $g;
        if ($cleAdr !== null) {                       // deux codes, une seule adresse → un objet
            $parent[$cleAdr] = $parent[$cleAdr] ?? $cleAdr;
            unir($parent, $cleAdr, $g);
        }

        $groupes[$g] ??= ['id_agence' => $c['agence'], 'nom' => '', 'adresse_1' => null,
                          'adresse_2' => null, 'code_postal' => null, 'ville' => null,
                          'rang' => -1, 'codes' => [], 'adresses' => []];
        // ⚠️ DEUX LIGNES D'ADRESSE, ET CE N'EST PAS DU CONFORT : un immeuble à plusieurs
        //    allées s'annonce sur deux voies. On garde donc TOUTES les voies distinctes lues.
        if ($rang > 0 && $adr !== '') $groupes[$g]['adresses'][plat($adr)] = $adr;
        if (codeValide($code)) {
            $groupes[$g]['codes'][$c['systeme'] . '|' . $code] = ['systeme' => $c['systeme'], 'code' => $code];
            $parCode[$c['agence'] . '|' . $code] = $g;
        }
        // ⚠️ ON GARDE LA MEILLEURE ADRESSE LUE, pas la dernière rencontrée : une adresse
        //    absente ne doit jamais écraser une adresse imprimée.
        if ($rang > $groupes[$g]['rang']) {
            $groupes[$g]['rang']        = $rang;
            $groupes[$g]['adresse_1']   = $rang ? ($adr ?: null) : null;
            $groupes[$g]['code_postal'] = trim((string)$r['code_postal']) ?: null;
            $groupes[$g]['ville']       = trim((string)$r['ville']) ?: null;
        }
        if ($groupes[$g]['nom'] === '') $groupes[$g]['nom'] = $nom ?: ($adr ?: $code);
        $parCrg[(int)$r['crg_id']][$g] = true;
    }

    // Repliement final : chaque groupe rejoint sa racine, en fusionnant ce qu'il porte.
    $immeubles = [];
    $versRacine = [];
    foreach (array_keys($groupes) as $g) {
        $r = racine($parent, $g);
        $versRacine[$g] = $r;
        if (!isset($immeubles[$r])) {
            $immeubles[$r] = ['k' => $r] + $groupes[$g];
        } else {
            $i =& $immeubles[$r];
            foreach ($groupes[$g]['codes'] as $ck => $cv)    $i['codes'][$ck] = $cv;
            foreach ($groupes[$g]['adresses'] as $ak => $av) $i['adresses'][$ak] = $av;
            if ($groupes[$g]['rang'] > $i['rang']) {
                $i['rang']        = $groupes[$g]['rang'];
                $i['adresse_1']   = $groupes[$g]['adresse_1'];
                $i['code_postal'] = $groupes[$g]['code_postal'];
                $i['ville']       = $groupes[$g]['ville'];
            }
            if ($i['nom'] === '') $i['nom'] = $groupes[$g]['nom'];
            unset($i);
        }
    }
    foreach ($immeubles as $k => $i) {
        $nom = $i['nom'] !== '' ? $i['nom'] : $k;

        // ⚠️ QUAND L'ADRESSE LUE A ÉTÉ ÉCARTÉE, LE NOM LA PORTE SOUVENT. « 312 COURS
        //    LAFAYETTE » est un nom d'immeuble ET une adresse. La taxe foncière ne se
        //    contentera pas d'un code postal : on la promeut, plutôt que de la perdre.
        if (($i['adresse_1'] ?? null) === null && nomEstAdresse($nom)) {
            $immeubles[$k]['adresse_1'] = mb_substr($nom, 0, 255);
        }
        // La seconde voie, s'il y en a une autre que celle retenue.
        $autres = array_values(array_filter($i['adresses'],
            fn($a) => plat($a) !== plat((string)$immeubles[$k]['adresse_1'])));
        $immeubles[$k]['adresse_2'] = $autres ? mb_substr($autres[0], 0, 255) : null;

        $immeubles[$k]['nom']   = mb_substr($nom, 0, 190);
        $immeubles[$k]['codes'] = array_values($i['codes']);
        unset($immeubles[$k]['rang'], $immeubles[$k]['adresses']);
    }

    // ═══ LES BIENS — clé : agence × référence ═══════════════════════════════════════════
    $biens = [];
    $lignes = $pdo->query("SELECT crg_id, reference, code_immeuble, libelle, type_bien, vendu
                             FROM crgi_lot WHERE import_id = $importId")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lignes as $r) {
        $c = $ctx[(int)$r['crg_id']] ?? null;
        if (!$c) continue;
        $ref = trim((string)$r['reference']);
        if (!codeValide($ref)) { $ecartes['lot_sans_reference']++; continue; }
        $k = $c['agence'] . '|' . $ref;

        // ⚠️ ON NE RATTACHE JAMAIS « AU DERNIER IMMEUBLE RENCONTRÉ ». Trois voies, dans cet
        //    ordre : le code imprimé sur la ligne ; à défaut, l'immeuble UNIQUE du CRG —
        //    quand il n'y en a qu'un, il n'y a pas de choix à faire, donc pas d'erreur
        //    possible ; à défaut, rien. Un lot sans immeuble reste un lot sans immeuble.
        $ci   = trim((string)$r['code_immeuble']);
        $immK = null;
        if ($ci !== '' && isset($parCode[$c['agence'] . '|' . $ci])) {
            $immK = $versRacine[$parCode[$c['agence'] . '|' . $ci]] ?? null;
        } elseif (count($parCrg[(int)$r['crg_id']] ?? []) === 1) {
            $immK = $versRacine[array_key_first($parCrg[(int)$r['crg_id']])] ?? null;
        }

        if (!isset($biens[$k])) {
            $biens[$k] = ['k' => $k, 'id_agence' => $c['agence'], 'immeuble_k' => $immK,
                          'mandant_k' => $c['mandant_k'], 'designation' => null,
                          'nature_code' => null, 'vendu' => 0, 'codes' => [],
                          'debut' => $c['debut'], 'fin' => $c['fin']];
        }
        $b =& $biens[$k];
        $b['codes'][$c['systeme'] . '|' . $ref] = ['systeme' => $c['systeme'], 'code' => $ref];
        if ($immK !== null) $b['immeuble_k'] = $immK;
        if (!$b['mandant_k']) $b['mandant_k'] = $c['mandant_k'];
        if (trim((string)$r['libelle']) !== '') $b['designation'] = mb_substr(trim((string)$r['libelle']), 0, 190);
        $nat = natureDuLot($r['type_bien'], $r['libelle']);
        if ($nat) $b['nature_code'] = $nat;
        if ((int)$r['vendu'] === 1) $b['vendu'] = 1;
        if ($c['debut'] && $c['debut'] < (string)$b['debut']) $b['debut'] = $c['debut'];
        if ($c['fin']   && $c['fin']   > (string)$b['fin'])   $b['fin']   = $c['fin'];
        unset($b);
    }
    foreach ($biens as $k => $b) {
        $biens[$k]['codes'] = array_values($b['codes']);
        if ($b['immeuble_k'] === null) $ecartes['lot_sans_immeuble']++;
    }

    // ═══ LES OCCUPATIONS — clé : bien × locataire normalisé ═════════════════════════════
    //
    // ⚠️ UNE OCCUPATION N'EST PAS UNE LIGNE DE CRG. Le même locataire réapparaît à chaque
    //    période : on replie sur (lot, nom), et les bornes sont le MIN et le MAX observés.
    //
    // ⚠️ ABSENCE ≠ DÉPART DÉMONTRÉ, et c'est la phase 3 qui a tranché — pas ce fichier.
    //    On ne referme une occupation que sur un verdict de départ.
    $partis = ['PARTI DEMONTRE', 'ANCIEN LOCATAIRE AVEC DETTE'];
    $occupations = [];
    $lignes = $pdo->query("SELECT crg_id, lot_reference, locataire, bail_du, bail_au,
                                  date_arrete, statut, dernier_appel_au
                             FROM crgi_occupation WHERE import_id = $importId
                            ORDER BY date_arrete, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lignes as $r) {
        $c = $ctx[(int)$r['crg_id']] ?? null;
        if (!$c) continue;
        $ref = trim((string)$r['lot_reference']);
        $nom = trim((string)$r['locataire']);
        if ($nom === '' || !codeValide($ref)) continue;
        $bienK = $c['agence'] . '|' . $ref;
        if (!isset($biens[$bienK])) continue;

        $k = $bienK . '#' . plat($nom);
        if (!isset($occupations[$k])) {
            $occupations[$k] = ['k' => $k, 'bien_k' => $bienK, 'nom_lu' => mb_substr($nom, 0, 190),
                                'date_debut' => null, 'date_fin' => null, 'parti' => false,
                                'bail_du' => null, 'bail_au' => null];
        }
        $o =& $occupations[$k];

        // ⚠️ LA BORNE OBSERVÉE ET LA DATE DE CONTRAT NE VONT PAS DANS LA MÊME COLONNE.
        //    J'écrivais « Bail du 05/08/2024 » dans `occupations.date_debut` — une date de
        //    contrat rangée là où la migration 0017 écrit noir sur blanc « les BORNES
        //    OBSERVÉES, pas les dates d'un contrat ». L'observation, c'est la période du CRG
        //    où ce nom apparaît ; le contrat, c'est ce que le document déclare, et il part
        //    désormais dans `baux`.
        if ($c['debut'] && (!$o['date_debut'] || $c['debut'] < $o['date_debut'])) {
            $o['date_debut'] = $c['debut'];
        }
        if ($r['bail_du'] && (!$o['bail_du'] || $r['bail_du'] < $o['bail_du'])) $o['bail_du'] = $r['bail_du'];

        // ⚠️ UNE DATE DE FIN DE BAIL N'EST PAS UN DÉPART. Je fermais l'occupation dès que
        //    « Bail du … au … » portait une échéance — or un bail commercial 3-6-9 imprime
        //    sa date de fin et le locataire est là pour des années. Mesuré : **12 lots** de
        //    VIENNE et CHAPONOST déclarés vides alors qu'ils sont occupés. Le terme du
        //    contrat va dans `baux.date_fin` ; l'occupation, elle, ne se ferme que sur un
        //    DÉPART DÉMONTRÉ — c'est la phase 3 qui en juge, pas une échéance imprimée.
        if ($r['bail_au']) $o['bail_au'] = $r['bail_au'];

        // ⚠️ ET LE DERNIER CRG LU TRANCHE. Un couple marqué « parti » à une période puis
        //    RE-VU actif à la suivante est un locataire toujours en place : le compte rendu
        //    de mars se trompait, celui de juin le corrige. Sans cette remise à zéro, un
        //    verdict de départ était définitif — **7 lots de LYON** restaient vides à tort,
        //    dont KPMG, parti quatre trimestres puis à nouveau appelé.
        if (in_array((string)$r['statut'], $partis, true)) {
            $o['parti']    = true;
            $o['date_fin'] = $r['dernier_appel_au'] ?: $r['date_arrete'];
        } else {
            $o['parti']    = false;
            $o['date_fin'] = null;
        }
        unset($o);
    }

    foreach ($occupations as $k => $o) {
        $liste = [];
        foreach (occupants($o['nom_lu']) as $i => $n) {
            $liste[] = ['tiers_k' => $poserTiers($n), 'nom_lu' => mb_substr($n, 0, 190),
                        'role_code' => 'locataire', 'rang' => $i + 1];
        }
        $occupations[$k]['occupants'] = $liste;
        // ⚠️ UNE BORNE DE FIN ANTÉRIEURE AU DÉBUT EST REFUSÉE PAR `chk_occ_bornes`.
        if ($o['date_fin'] && $o['date_debut'] && $o['date_fin'] < $o['date_debut']) {
            $occupations[$k]['date_fin'] = $o['date_debut'];
        }
    }

    // ═══ LES MANDATS DE GESTION ═════════════════════════════════════════════════════════
    //
    // ⚠️ `date_debut` EST UNE BORNE OBSERVÉE, PAS UNE DATE DE SIGNATURE. Le CRG prouve que
    //    le mandat existait à cette période, jamais qu'il a commencé ce jour-là.
    // ⚠️ ET UN MANDAT NE SE PERD PAS PARCE QUE LA PÉRIODE N'A PAS ÉTÉ IMPRIMÉE. 12 lots
    //    venaient de CRG dont l'en-tête ne portait pas de dates lisibles : sans repli, leur
    //    mandat disparaissait alors que le lot, lui, entrait. On prend la borne du dépôt —
    //    c'est la période la plus étroite qu'on puisse démontrer.
    $debutsCtx  = array_filter(array_column($ctx, 'debut'));
    $reculDepot = $debutsCtx ? min($debutsCtx) : null;

    $mandats = [];
    foreach ($biens as $k => $b) {
        if (!$b['mandant_k'] || !isset($mandants[$b['mandant_k']])) continue;
        $debut = $b['debut'] ?: $reculDepot;
        if (!$debut) continue;
        $mandats[] = ['id_agence' => $b['id_agence'], 'type_code' => 'gestion',
                      'mandant_k' => $b['mandant_k'], 'immeuble_k' => $b['immeuble_k'],
                      'bien_k' => $k, 'date_debut' => $debut,
                      'date_fin' => $b['vendu'] ? $b['fin'] : null];
    }

    // ═══ LES RÔLES — SUR LEUR OBJET ═════════════════════════════════════════════════════
    //
    // ⚠️ J'AVAIS POSÉ CES RÔLES « GLOBAUX », SANS OBJET. C'ÉTAIT FAUX, et le registre V2 le
    //    dit lui-même : `tiers_roles_codes.objets` vaut **`bien,immeuble`** pour
    //    `proprietaire`. `RolesTiers::accepteObjet('proprietaire', null)` répond NON — un
    //    rôle sans objet n'est accepté que des rôles dont la liste d'objets est VIDE, comme
    //    l'assureur ou l'expert-comptable. 526 lignes invalides étaient entrées.
    //
    //    Et ce n'est pas qu'une question de conformité : sans objet, « qui possède cet
    //    immeuble ? » restait sans réponse. Le chemin `tiers → mandant → biens → immeuble`
    //    ne la donne pas quand l'immeuble ne porte aucun lot rattaché — 20 lots sont dans ce
    //    cas — et il ne dit rien d'un bâtiment partagé entre plusieurs propriétaires.
    //
    // ⚠️ LE LOCATAIRE N'ENTRE PAS ICI, ET CE N'EST PAS UN OUBLI. Son rôle se pose sur un
    //    `bail` — objet qui n'existe pas encore. Le lien locataire vit déjà dans
    //    `occupation_occupants` (`id_tiers` + `role_code`), qui puise dans le MÊME glossaire :
    //    c'est la table de liens des locataires, et elle est remplie.
    //
    // ⚠️ MINUSCULES. Le registre écrit `bien` et `immeuble` ; `objet_codes` écrit `BIEN` et
    //    `IMMEUBLE`. Deux colonnes, deux conventions, et `tiers_roles.objet_type` est en
    //    collation binaire : une majuscule ici rendrait le rôle invalide sans aucune erreur.
    $roles = [];
    $poserRole = function (string $tk, string $role, string $objetType, string $objetK, ?string $d)
                 use (&$roles, $reculDepot): void {
        // ⚠️ UN RÔLE NE SE PERD PAS FAUTE DE DATE : `date_debut` est NOT NULL, et sans repli
        //    12 propriétaires entraient comme tiers mais sans le rôle qui les rend trouvables.
        $d = $d ?: $reculDepot;
        if (!$d) return;
        $k = $tk . '|' . $role . '|' . $objetType . '|' . $objetK;
        if (!isset($roles[$k]) || $d < (string)$roles[$k]['date_debut']) {
            $roles[$k] = ['tiers_k' => $tk, 'role_code' => $role, 'objet_type' => $objetType,
                          'objet_k' => $objetK, 'date_debut' => $d];
        }
    };
    // ⚠️ LE COMPTE EST L'ANCRAGE QUI NE MANQUE JAMAIS. Emmanuel, 09/09/2026 : « si c'est un
    //    propriétaire retrouvé d'un CRG il faut quand même créer le tiers avec un rôle
    //    propriétaire ». 30 tiers n'en portaient aucun — 29 avaient pourtant un COMPTE, mais
    //    aucun lot lisible, souvent parce que le compte rendu ne portait plus qu'un solde.
    //    Un lot peut être illisible, un immeuble non rattaché ; le compte, lui, est ce qui a
    //    fait exister le document.
    foreach ($mandants as $mk => $m) {
        if ($m['tiers_k']) $poserRole($m['tiers_k'], 'proprietaire', 'mandant', $mk, $m['debut']);
    }
    foreach ($biens as $k => $b) {
        $m = $b['mandant_k'] ? ($mandants[$b['mandant_k']] ?? null) : null;
        if (!$m || !$m['tiers_k']) continue;
        $poserRole($m['tiers_k'], 'proprietaire', 'bien', $k, $b['debut']);
        // Le propriétaire d'un lot est propriétaire DANS l'immeuble. Plusieurs comptes
        // peuvent l'être sur le même bâtiment : c'est justement ce qu'on veut pouvoir lire.
        if ($b['immeuble_k']) {
            $poserRole($m['tiers_k'], 'proprietaire', 'immeuble', $b['immeuble_k'], $b['debut']);
        }
    }

    $debuts = array_filter(array_column($ctx, 'debut'));
    $fins   = array_filter(array_column($ctx, 'fin'));
    $agences = array_values(array_unique(array_column($ctx, 'agence')));

    return [
        'libelle'       => $imp['libelle'],
        'source'        => 'CRG',
        'staging_id'    => $importId,
        'id_agence'     => count($agences) === 1 ? $agences[0] : null,
        'nb_documents'  => count($crgs),
        'periode_debut' => $debuts ? min($debuts) : null,
        'periode_fin'   => $fins   ? max($fins)   : null,
        'tiers'         => array_values($tiers),
        'mandants'      => array_values($mandants),
        'immeubles'     => array_values($immeubles),
        'biens'         => array_values($biens),
        'mandats'       => $mandats,
        'occupations'   => array_values($occupations),
        'roles'         => array_values($roles),
        'ecartes'       => array_filter($ecartes),
        'regles_identite' => $regles,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════════════════

$pdo = pdoStaging();
$sys = systemesParAgence($pdo);
@mkdir(SORTIE, 0775, true);

// Les adresses relues sur la première page de chaque CRG par `crgv2_adresses.php`.
$adresses = is_file(SORTIE . '/adresses.json')
          ? (json_decode((string)file_get_contents(SORTIE . '/adresses.json'), true) ?: [])
          : [];
if (!$adresses) {
    fwrite(STDERR, "⚠️  adresses.json absent — les tiers entreront SANS adresse."
                 . " Lancer d'abord : php crgv2_adresses.php\n");
}
echo "référentiel par agence : " . json_encode($sys)
   . "  ·  " . count($adresses) . " adresses de propriétaire disponibles\n\n";

$ids = isset($argv[1])
     ? [(int)$argv[1]]
     : array_map('intval', $pdo->query('SELECT id FROM crgi_import ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

$tot = [];
foreach ($ids as $id) {
    $plan = construire($pdo, $id, $sys, $adresses);
    file_put_contents(SORTIE . '/plan_' . $id . '.json',
                      json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    printf("dépôt %d — %-20s %4d docs | %4d tiers | %4d mandants | %4d immeubles | %4d biens | %4d mandats | %4d occup. | %4d rôles\n",
        $id, mb_substr($plan['libelle'], 0, 20), $plan['nb_documents'], count($plan['tiers']),
        count($plan['mandants']), count($plan['immeubles']), count($plan['biens']),
        count($plan['mandats']), count($plan['occupations']), count($plan['roles']));
    if ($plan['ecartes']) echo "        écartés : " . json_encode($plan['ecartes']) . "\n";
    foreach (['tiers','mandants','immeubles','biens','mandats','occupations','roles'] as $f)
        $tot[$f] = ($tot[$f] ?? 0) + count($plan[$f]);
}
echo "\nTOTAL AVANT REPLIEMENT INTER-DÉPÔTS : " . json_encode($tot) . "\n";
