<?php
/**
 * LES RÈGLES DU RAPPROCHEMENT FINANCIER — et surtout ses REFUS.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER DÉFEND LA RÈGLE LA PLUS DANGEREUSE DU MODULE. Rapprocher, c'est décider qu'une
 *    écriture existe déjà — donc décider de ne PAS l'écrire. Une règle trop large fait perdre
 *    de l'argent ; une règle trop étroite en crée en double. Les deux sont silencieuses.
 *
 * ⚠️ `MÊME MONTANT ≠ MÊME ÉCRITURE`, et `MÊME COMPTE + MÊME MONTANT ≠ MÊME ÉCRITURE`. Le
 *    montant n'intervient qu'en DERNIER, pour départager des candidats déjà retenus sur leur
 *    identité. Il ne désigne jamais un candidat à lui seul.
 *
 * Les fixtures reproduisent les écarts RÉELS entre les deux chaînes d'extraction :
 *    — MBI recopie la ligne entière dans `libelle`, montant compris ;
 *    — MBI écrit le lot court (« 01 ») là où le document imprime « 276-01 » ;
 *    — MBI n'a que `debit` et `credit` : aucune colonne d'APPEL.
 *
 * Usage : php crgi_rapprochement.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';

// ⚠️ PAS D'IMPORT ÉCRIT EN DUR. Le `5` était recopié onze fois dans ce fichier ; le jour où
//    l'import 5 a été annulé, trois contrôles ont échoué sur « aucun groupe d'arbitrage » —
//    ils cherchaient les décisions d'un import qui n'existait plus.
$pdo = $GLOBALS['pdo'];
$IMPORT = (int)($argv[1] ?? crgi_import_reference($pdo));

$ok = 0;
$ko = [];

function essai(string $titre, string $incident, callable $fn): void
{
    global $ok, $ko;
    try {
        $fn();
        $ok++;
        echo "  OK   {$titre}\n";
    } catch (Throwable $e) {
        $ko[] = [$titre, $incident, $e->getMessage()];
        echo "  ÉCHEC {$titre}\n";
    }
}

function exiger(bool $c, string $m): void
{
    if (!$c) {
        throw new RuntimeException($m);
    }
}

/** Un mouvement du staging, réduit à ce dont la règle se sert. */
function mvt(string $colonne, float $montant, string $libelle, string $lot = ''): array
{
    return ['colonne' => $colonne, 'montant' => $montant, 'libelle' => $libelle,
            'lot_reference' => $lot];
}

/** Une écriture MBI candidate. */
function ecr(int $id, string $libelle, string $lot, float $debit, float $credit): array
{
    return ['id' => $id, 'libelle' => $libelle, 'numero_lot' => $lot,
            'debit' => $debit, 'credit' => $credit];
}

echo "RAPPROCHEMENT FINANCIER\n";

essai(
    'même situation, même libellé, même lot, même montant → DÉJÀ PRÉSENT',
    'Sans ce rapprochement, 416 écritures que MBI porte déjà auraient été créées une '
    . 'seconde fois.',
    function () {
        [$v, $id, $_m] = crgi_verdict_rapprochement(
            mvt('credit', 183.74, 'Loyer Mars 2026', '276-01'),
            [ecr(53054, 'Loyer Mars 2026', '01', 0.0, 183.74)]
        );
        exiger($v === 'DEJA PRESENT', "verdict {$v}");
        exiger($id === 53054, 'l’écriture MBI retenue n’est pas nommée');
    }
);

essai(
    'REFUS — un montant d’APPEL n’a aucun équivalent dans MBI',
    '`crg_ecritures` n’a que `debit` et `credit` : les colonnes `Loyers`, `Charges`, `Autres` '
    . 'et `Reste dû` du document n’y ont AUCUNE place. Ces montants sont nouveaux par '
    . 'construction du modèle, pas par échec de confrontation — et le motif doit le dire.',
    function () {
        foreach (['loyers', 'charges', 'autres', 'reste_du'] as $colonne) {
            [$v, $id, $m] = crgi_verdict_rapprochement(
                mvt($colonne, 474.49, 'Loyer Avril 2026', '01'),
                [ecr(1, 'Loyer Avril 2026', '01', 0.0, 474.49)]
            );
            exiger($v === 'NOUVEAU', "colonne {$colonne} : verdict {$v}");
            exiger($id === null, 'aucune écriture MBI ne doit être retenue');
            exiger(str_contains($m, 'construction du modèle'),
                   'le motif ne distingue pas la limite du modèle d’un échec');
        }
    }
);

essai(
    'REFUS — le même montant ne fait pas la même écriture',
    'Deux écritures identiques dans une situation : les départager au montant serait '
    . 'exactement le rapprochement forcé qu’on s’interdit.',
    function () {
        [$v, $id, $_m] = crgi_verdict_rapprochement(
            mvt('debit', 20.00, 'Honoraires Revenus Fonciers', ''),
            [ecr(1, 'Honoraires Revenus Fonciers 20,00', '', 20.00, 0.0),
             ecr(2, 'Honoraires Revenus Fonciers 20,00', '', 20.00, 0.0)]
        );
        // ⚠️ `NON RAPPROCHABLE` ET NON `CANDIDAT NON DEMONTRABLE` : deux écritures strictement
        //    identiques ne se départagent pas — et un humain n'a rien de plus pour le faire.
        //    Le refus est le même ; ce qui change, c'est qu'on ne le pose plus en question.
        exiger($v === 'NON RAPPROCHABLE', "verdict {$v}");
        exiger($id === null, 'aucune des deux ne doit être désignée');
    }
);

essai(
    'REFUS — un libellé générique ne désigne personne',
    '« Solde » fait cinq caractères et s’imprime à chaque bloc : s’en servir comme clé faisait '
    . 'pointer six mouvements différents vers LA MÊME écriture MBI, et produisait six fausses '
    . 'contradictions.',
    function () {
        [$v, $_id, $m] = crgi_verdict_rapprochement(
            mvt('debit', 51.96, 'Solde', ''),
            [ecr(1, 'Solde 51,96', '', 51.96, 0.0), ecr(2, 'Solde 70,86', '', 70.86, 0.0)]
        );
        exiger($v === 'NON RAPPROCHABLE', "verdict {$v}");
        exiger(str_contains($m, 'générique'), 'le motif ne nomme pas la cause');
    }
);

essai(
    'MBI porte la ligne avec un AUTRE montant → CONTRADICTION',
    'Ce n’est ni un doublon ni une création : les deux ne peuvent pas être vrais, et un humain '
    . 'doit trancher.',
    function () {
        [$v, $id, $_m] = crgi_verdict_rapprochement(
            mvt('credit', 87.64, 'Loyer Avril 2026', '361-1'),
            [ecr(9, 'Loyer Avril 2026', '1', 0.0, 55.00)]
        );
        exiger($v === 'CONTRADICTION', "verdict {$v}");
        exiger($id === 9, 'l’écriture contradictoire doit être nommée');
    }
);

essai(
    'MBI porte la ligne à 0,00 → ni doublon, ni contradiction',
    'MBI a enregistré la ligne sans son montant. La créer doublerait la ligne ; la déclarer '
    . 'contradictoire accuserait à tort.',
    function () {
        [$v, $id, $m] = crgi_verdict_rapprochement(
            mvt('credit', 474.49, 'Loyer Avril 2026', '01'),
            [ecr(7, 'Loyer Avril 2026', '01', 0.0, 0.0)]
        );
        exiger($v === 'CANDIDAT NON DEMONTRABLE', "verdict {$v}");
        exiger($id === 7 && str_contains($m, 'montant nul'), 'le motif doit dire pourquoi');
    }
);

essai(
    'aucune écriture MBI ne porte ce libellé → NOUVEAU',
    'MBI ne possède pas cette ligne : elle serait créée. C’est le cas le plus fréquent, et il '
    . 'doit rester distinct de « hors périmètre ».',
    function () {
        [$v, $id, $_m] = crgi_verdict_rapprochement(
            mvt('debit', 15.68, 'Budget prévisionnel 2026', '01G01-5213-000367'), []
        );
        exiger($v === 'NOUVEAU' && $id === null, "verdict {$v}");
    }
);

// ── LES RÈGLES DE CLÉ, TESTÉES POUR CE QU'ELLES REFUSENT ──────────────────────────────────
essai(
    'REFUS — « 01 » ne s’apparie pas à « 101 »',
    'Le lot MBI est accepté comme SUFFIXE du lot du document. Une inclusion libre confondrait '
    . '« 01 » et « 101 » — deux appartements différents, un rapprochement faux.',
    function () {
        $cle = fn($v) => preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$v));
        $suffixe = fn(string $lotDoc, string $lotMbi)
            => $cle($lotMbi) !== '' && str_ends_with($cle($lotDoc), $cle($lotMbi));
        exiger($suffixe('276-01', '01'), '« 276-01 » doit accepter « 01 »');
        exiger($suffixe('01', '01'), 'l’égalité doit rester acceptée');
        exiger(!$suffixe('101', '01') === false, 'contrôle du test lui-même');
        // « 101 » se termine bien par « 01 » : c'est précisément pourquoi le suffixe seul ne
        // suffit pas et pourquoi le libellé est exigé D'ABORD.
        exiger(!$suffixe('01', '101'), '« 01 » ne doit jamais accepter « 101 »');
    }
);

essai(
    'le libellé du staging est le PRÉFIXE du libellé MBI',
    'MBI recopie la ligne entière, montant compris : « EDF 5 PL FUTERIE 8458251472 244,93 ». '
    . 'Exiger l’égalité ne rapprochait que 60 mouvements au lieu de 416.',
    function () {
        $cle = fn($v) => preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$v));
        $staging = $cle('EDF 5 PL FUTERIE 8458251472');
        $mbi = $cle('EDF 5 PL FUTERIE 8458251472 244,93');
        exiger(str_starts_with($mbi, $staging), 'le préfixe doit être reconnu');
        exiger(!str_starts_with($cle('EDF 6 PL FUTERIE'), $staging),
               'un libellé différent ne doit PAS être reconnu');
    }
);

essai(
    'REFUS — la normalisation des noms ignore les espaces, RIEN d’autre',
    'MBI enregistre « ALOUILotfi » soudé : comparer « ALOUI LOTFI » à « ALOUILOTFI » ne '
    . 'rapprochait qu’UN locataire sur 119, et déclarer les 118 autres « à créer » aurait '
    . 'fabriqué 88 doublons. Mais deux noms qui diffèrent d’une LETTRE restent deux noms.',
    function () {
        exiger(crgi_cle_nom('ALOUI Lotfi') === crgi_cle_nom('ALOUILotfi'),
               'les espaces doivent être ignorés');
        exiger(crgi_cle_nom('BERRUYER Thierry') === crgi_cle_nom('BERRUYERThierry'),
               'idem');
        exiger(crgi_cle_nom('ABOUKHEIR YOUNESS') !== crgi_cle_nom('ABOUKHEIR YOUNESSE'),
               'une lettre de plus est un AUTRE nom');
        exiger(crgi_cle_nom('MARTIN Eve') !== crgi_cle_nom('MARTIN Yves'),
               'deux noms distincts doivent le rester');
    }
);


// ── L'ARBITRAGE : ON DOIT POUVOIR RÉPONDRE, ET SEULEMENT CE QUI EST PROPOSÉ ───────────────
essai(
    'une décision d’arbitrage s’enregistre, se relit et se retire',
    'Un arbitrage qu’on ne peut pas enregistrer n’est pas un arbitrage. Et une décision doit '
    . 'pouvoir se changer : on ne veut pas empiler des avis contradictoires sur le même objet.',
    function () {
        global $pdo, $IMPORT;
        $g = crgi_arbitrages($pdo, $IMPORT);
        exiger(!empty($g), 'aucun groupe d’arbitrage — le test ne prouverait rien');
        $groupe = $g[0];
        $cible = (int)$groupe['lignes'][0]['cible_id'];
        $choix = array_key_first($groupe['choix']);
        crgi_arbitrer($pdo, $IMPORT, $groupe['cible'], $cible, $choix, 'précision d’essai', 8);
        $relu = crgi_arbitrages($pdo, $IMPORT)[0];
        $trouve = null;
        foreach ($relu['lignes'] as $l) {
            if ((int)$l['cible_id'] === $cible) {
                $trouve = $l['decision'];
            }
        }
        exiger($trouve !== null, 'la décision n’est pas relue par l’écran');
        exiger($trouve['choix'] === $choix, 'le choix relu ne correspond pas');
        exiger($trouve['precision_h'] === 'précision d’essai', 'la précision n’est pas conservée');
        exiger((int)$relu['tranches'] >= 1, 'le compteur de décisions ne bouge pas');
        // retirer sa décision EST une décision
        crgi_arbitrer($pdo, $IMPORT, $groupe['cible'], $cible, '', null, 8);
        $apres = crgi_arbitrages($pdo, $IMPORT)[0];
        exiger((int)$apres['tranches'] === 0, 'la décision retirée subsiste');
    }
);

essai(
    'REFUS — on n’arbitre pas un objet qui n’est pas en arbitrage',
    'Une décision ne se pose que sur une question réellement ouverte : sinon elle porterait '
    . 'sur un objet que le document démontre déjà.',
    function () {
        global $pdo, $IMPORT;
        $leve = false;
        try {
            crgi_arbitrer($pdo, $IMPORT, 'MOUVEMENT', 1, 'Créer le mouvement', null, 8);
        } catch (Throwable $e) {
            $leve = str_contains($e->getMessage(), 'PAS EN ARBITRAGE');
        }
        exiger($leve, 'aucun refus sur un objet hors arbitrage');
    }
);

essai(
    'REFUS — un choix hors de ceux que la règle propose',
    'Accepter n’importe quel texte laisserait entrer une décision que l’intégration ne saurait '
    . 'pas exécuter. La précision libre est là pour ce que les choix fermés ne disent pas.',
    function () {
        global $pdo, $IMPORT;
        $g = crgi_arbitrages($pdo, $IMPORT)[0];
        $leve = false;
        try {
            crgi_arbitrer($pdo, $IMPORT, $g['cible'], (int)$g['lignes'][0]['cible_id'],
                          'Faire ce que je veux', null, 8);
        } catch (Throwable $e) {
            $leve = str_contains($e->getMessage(), 'CHOIX INCONNU');
        }
        exiger($leve, 'aucun refus sur un choix inconnu');
    }
);

essai(
    'REFUS — décider n’écrit rien dans les données métier',
    'Décider n’est pas intégrer : la décision vit en staging, datée et signée, et c’est la '
    . 'phase d’intégration — non livrée — qui l’exécutera.',
    function () {
        global $pdo, $IMPORT;
        $temoins = ['biens' => 1379, 'immeubles' => 1009, 'proprietaires' => 393,
                    'crg_ecritures' => 26711];
        $avant = [];
        foreach ($temoins as $t => $_a) {
            $avant[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        }
        $g = crgi_arbitrages($pdo, $IMPORT)[0];
        $cible = (int)$g['lignes'][0]['cible_id'];
        crgi_arbitrer($pdo, $IMPORT, $g['cible'], $cible, array_key_first($g['choix']), null, 8);
        foreach ($temoins as $t => $attendu) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            exiger($n === $avant[$t] && $n === $attendu,
                   "{$t} a bougé après un arbitrage : {$n} au lieu de {$avant[$t]}");
        }
        crgi_arbitrer($pdo, $IMPORT, $g['cible'], $cible, '', null, 8);
    }
);

// ── L'IDENTITÉ D'UN IMMEUBLE : CE QUI N'EST PAS UN BÂTIMENT N'EST PAS CANDIDAT ───────────
essai(
    'un LOT de MBI n’est jamais candidat pour un IMMEUBLE',
    'La table `immeubles` de MBI porte 245 « Appartement », 22 « Local commercial » et 10 '
    . '« Garage » — des lots rangés là par une reprise ancienne, à la même adresse et sous le '
    . 'même nom que leur bâtiment. Ils devenaient des homonymes : 33 des 41 questions '
    . 'd’identité d’un corpus n’opposaient pas deux bâtiments, mais un bâtiment à ses lots.',
    function () {
        $cands = [
            ['id' => 172, 'type_immeuble' => 'Immeuble',         'code_crg' => ''],
            ['id' => 448, 'type_immeuble' => 'Local commercial', 'code_crg' => ''],
            ['id' => 468, 'type_immeuble' => 'Appartement',      'code_crg' => ''],
        ];
        $gardes = crgi_candidats_batiments($cands);
        exiger(count($gardes) === 1 && (int)$gardes[0]['id'] === 172,
               'candidats retenus : ' . implode(', ', array_column($gardes, 'id')));
        // ⚠️ UNE MAISON EST UN IMMEUBLE. L'exclusion porte sur ce qui est DÉMONTRÉ être un lot,
        //    jamais sur une liste de ce qu'on accepte.
        $maison = crgi_candidats_batiments([
            ['id' => 9, 'type_immeuble' => 'Maison', 'code_crg' => ''],
            ['id' => 8, 'type_immeuble' => 'Garage', 'code_crg' => ''],
        ]);
        exiger(count($maison) === 1 && (int)$maison[0]['id'] === 9, 'une maison a été écartée');
        // ⚠️ ET UN TYPE INCONNU RESTE CANDIDAT (`NOUVEAUTÉ ≠ EXCLUSION`).
        $neuf = crgi_candidats_batiments([
            ['id' => 7, 'type_immeuble' => 'Résidence-services', 'code_crg' => ''],
            ['id' => 6, 'type_immeuble' => null,                 'code_crg' => ''],
        ]);
        exiger(count($neuf) === 2, 'un type jamais vu a été écarté sans preuve');
    }
);

essai(
    'le code imprimé sur le CRG se retrouve ENTIER dans `code_crg`',
    'MBI écrit « 01S01-0067 », le document n’imprime que « 0067 ». Le segment après le tiret '
    . 'est le code de l’immeuble ; ce qui précède est le préfixe d’agence et d’activité. Une '
    . 'inclusion libre confondrait « 67 » et « 0067 » — ce serait le rapprochement approximatif '
    . 'que la doctrine interdit.',
    function () {
        $cands = [
            ['id' => 2739, 'code_crg' => '01G01-5140', 'type_immeuble' => 'immeuble'],
            ['id' => 2752, 'code_crg' => '01S01-0067', 'type_immeuble' => 'immeuble'],
        ];
        $t = crgi_candidat_par_code_crg($cands, '0067');
        exiger($t !== null && (int)$t['id'] === 2752, 'le code exact n’a pas désigné n°2752');
        // ⚠️ ET LE MÊME CODE SANS PRÉFIXE : un autre gestionnaire écrit « 01040087 » des deux
        //    côtés. Exiger le tiret rendait la preuve AVEUGLE sur tout un corpus — 14
        //    questions d’identité posées alors que MBI portait le code, à l’identique.
        $sansTiret = [
            ['id' => 775, 'code_crg' => '01080134', 'type_immeuble' => 'immeuble'],
            ['id' => 776, 'code_crg' => '',         'type_immeuble' => 'immeuble'],
        ];
        $u = crgi_candidat_par_code_crg($sansTiret, '01080134');
        exiger($u !== null && (int)$u['id'] === 775, 'le code sans préfixe n’a désigné personne');
        exiger(crgi_candidat_par_code_crg($sansTiret, '0134') === null,
               '« 0134 » a été accepté comme fin de « 01080134 » : inclusion libre');
        // Les zéros de tête ne font pas la différence, le reste du segment si.
        exiger(crgi_candidat_par_code_crg($cands, '67') !== null, '« 67 » vaut « 0067 »');
        exiger(crgi_candidat_par_code_crg($cands, '140') === null,
               '« 140 » a été accepté comme suffixe de « 5140 » : inclusion libre');
        exiger(crgi_candidat_par_code_crg($cands, '') === null, 'un code vide a désigné quelqu’un');
        // Deux candidats au même code ne se départagent pas : on ne tranche pas au hasard.
        exiger(crgi_candidat_par_code_crg([
            ['id' => 1, 'code_crg' => 'A-0067'], ['id' => 2, 'code_crg' => 'B-0067'],
        ], '0067') === null, 'deux candidats au même code ont été départagés');
    }
);

essai(
    'MBI qui se contredit ne se confond pas avec un document ambigu',
    '10 groupes d’« homonymes » d’un dépôt réunissaient des enregistrements portant le MÊME '
    . 'code de gestion et la MÊME adresse : « 238 Route de Vienne » existe TROIS fois dans MBI '
    . 'sous le code 01040087. Le document n’était ambigu à aucun moment — la réponse est un '
    . 'ménage à faire dans MBI, pas une relecture du PDF.',
    function () {
        // Trois copies du même immeuble : la base se contredit, et elle dit PAR QUOI.
        $c = crgi_meme_immeuble_repete([
            ['id' => 111, 'code_crg' => '01040087'],
            ['id' => 775, 'code_crg' => '01040087'],
            ['id' => 879, 'code_crg' => '01040087'],
        ]);
        exiger($c !== null && str_contains($c, '01040087') && str_contains($c, 'code'),
               'trois copies du même code ne sont pas reconnues : ' . var_export($c, true));
        // ⚠️ ET L'ADRESSE SUFFIT AUSSI, PARCE QUE MBI NE REMPLIT PAS TOUJOURS LES DEUX
        //    COLONNES. Sur un corpus, 10 groupes se démontrent par le code et 7 par la seule
        //    adresse ; n'en garder qu'une laissait 7 contradictions de la base passer pour
        //    des ambiguïtés du document.
        $a = crgi_meme_immeuble_repete([
            ['id' => 1, 'code_crg' => '', 'adresse_1' => '238 Route DE VIENNE'],
            ['id' => 2, 'code_crg' => '', 'adresse_1' => '238 Route de Vienne'],
        ]);
        exiger($a !== null && str_contains($a, 'adresse'),
               'la même adresse écrite deux fois n’est pas reconnue : ' . var_export($a, true));
        // Deux adresses réellement différentes : le document, lui, est bien ambigu.
        exiger(crgi_meme_immeuble_repete([
            ['id' => 1, 'code_crg' => '', 'adresse_1' => '77 Avenue Berthelot'],
            ['id' => 2, 'code_crg' => '', 'adresse_1' => '77-85 AVENUE BERTHELOT'],
        ]) === null, 'deux adresses distinctes prises pour une répétition');
        // ⚠️ LE PIÈGE QUI A TUÉ LA PREMIÈRE VERSION : `array_unique` ramène trois codes
        //    identiques à UN seul. Comparer ce nombre au nombre de candidats rendait la
        //    branche inatteignable — un code mort qui avait l’air de fonctionner.
        exiger(crgi_meme_immeuble_repete([['id' => 1, 'code_crg' => 'X']]) === null,
               'un candidat seul n’est pas une contradiction');
        // Un candidat sans code : on ne sait pas, donc on n’affirme pas.
        exiger(crgi_meme_immeuble_repete([
            ['id' => 1, 'code_crg' => ''], ['id' => 2, 'code_crg' => '01080134'],
        ]) === null, 'un candidat sans code a été compté comme une copie');
        // Deux codes différents : deux bâtiments, le document est bien ambigu.
        exiger(crgi_meme_immeuble_repete([
            ['id' => 1, 'code_crg' => 'A'], ['id' => 2, 'code_crg' => 'B'],
        ]) === null, 'deux codes différents pris pour une contradiction de MBI');
    }
);

essai(
    'REFUS — une question à laquelle personne ne peut répondre n’est pas posée',
    'Douze écritures « Solde » identiques dans la même situation : demander LAQUELLE '
    . 'correspond, c’est demander de deviner. `MÊME MONTANT ≠ MÊME ÉCRITURE` vaut aussi pour '
    . 'l’humain — il voit exactement ce que le moteur voit.',
    function () {
        // Libellé générique : indécidable pour tout le monde.
        [$v] = crgi_verdict_rapprochement(
            ['libelle' => 'Solde', 'colonne' => 'credit', 'montant' => 10.0],
            [['id' => 1, 'credit' => 10.0], ['id' => 2, 'credit' => 99.0]]);
        exiger($v === 'NON RAPPROCHABLE', 'libellé générique → ' . $v);
        // Deux écritures strictement identiques : indécidable pour tout le monde.
        [$v2] = crgi_verdict_rapprochement(
            ['libelle' => 'Assurance propriétaire non occupant', 'colonne' => 'debit',
             'montant' => 51.96],
            [['id' => 1, 'debit' => 51.96], ['id' => 2, 'debit' => 51.96]]);
        exiger($v2 === 'NON RAPPROCHABLE', 'écritures indiscernables → ' . $v2);
        // ⚠️ MAIS CE QUI SE TRANCHE RESTE UNE QUESTION. Plusieurs candidates, aucune au montant
        //    du document : la page le dira. Requalifier celle-ci en « limite » ferait
        //    disparaître du travail réel.
        [$v3] = crgi_verdict_rapprochement(
            ['libelle' => 'Assurance propriétaire non occupant', 'colonne' => 'debit',
             'montant' => 51.96],
            [['id' => 1, 'debit' => 12.00], ['id' => 2, 'debit' => 33.00]]);
        exiger($v3 === 'CANDIDAT NON DEMONTRABLE', 'vraie question requalifiée → ' . $v3);
        // Et une contradiction reste une contradiction.
        [$v4] = crgi_verdict_rapprochement(
            ['libelle' => 'Assurance propriétaire non occupant', 'colonne' => 'debit',
             'montant' => 51.96],
            [['id' => 1, 'debit' => 12.00]]);
        exiger($v4 === 'CONTRADICTION', 'contradiction requalifiée → ' . $v4);
    }
);

echo "\nRAPPROCHEMENT : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
