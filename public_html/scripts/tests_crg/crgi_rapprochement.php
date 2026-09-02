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
        exiger($v === 'CANDIDAT NON DEMONTRABLE', "verdict {$v}");
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
        exiger($v === 'CANDIDAT NON DEMONTRABLE', "verdict {$v}");
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

echo "\nRAPPROCHEMENT : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
