<?php
declare(strict_types=1);

/**
 * Tests — fluxbox_resoudre_contexte() (contrat figé)
 *
 * Lancement CLI :  php public_html/tests/test_fluxbox_resolution.php
 *
 * Les 3 cas demandés :
 *   (a) contexte explicite donné      → tout vert, jamais écrasé
 *   (b) entité existante rapprochée   → entité JAUNE proposée, pas de doublon
 *   (c) aucune source sûre société    → JAUNE (contexte_user) + cascade, bloquant=true
 *
 * Exécuté dans une TRANSACTION rollback : aucune donnée n'est persistée.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/fluxbox_resolution.php';

$pdo = function_exists('ged_pdo') ? ged_pdo() : $GLOBALS['pdo'];

$tests = 0; $fails = 0;
function ok(bool $cond, string $label): void {
    global $tests, $fails;
    $tests++;
    if ($cond) { echo "  ✅ $label\n"; }
    else { $fails++; echo "  ❌ $label\n"; }
}

/* ════════════════════════════════════════════════════════════════════════
 * (a) Contexte explicite → tout vert, jamais écrasé (Invariant I2)
 * ════════════════════════════════════════════════════════════════════════ */
echo "\n(a) Contexte explicite gagne toujours (vert, non écrasé)\n";
$res = fluxbox_resoudre_contexte(
    0,
    [
        'explicite' => [
            'societe_id'=>12, 'societe_nom'=>'REEM',
            'agence_id'=>34,  'agence_nom'=>'69_2',
            'metier'=>'RH', 'domaine'=>'COLLABORATEURS',
            'type_document'=>'CV',
            'confirme'=>['societe'=>true,'agence'=>true],
        ],
        'compte' => ['user_id'=>8,'societe_id'=>1,'societe_nom'=>'AUTRE'],
    ],
    // Le document tente d'imposer une AUTRE société / type → doit être IGNORÉ
    ['societe'=>'SOCIETE PIRATE','siren'=>'999999999','type_doc'=>'FACTURE','metier'=>'COMPTA'],
    null,   // pas de lot
    $pdo
);
ok($res['societe']['source']==='contexte_explicite' && $res['societe']['confiance']==='vert' && (int)$res['societe']['id']===12, "société = explicite/vert/id12 (pas écrasée par le document)");
ok($res['agence']['source']==='contexte_explicite' && $res['agence']['confiance']==='vert', "agence = explicite/vert");
ok($res['type_document']['valeur']==='CV' && $res['type_document']['confiance']==='vert', "type_document = CV/vert (pas FACTURE)");
ok($res['metier']['valeur']==='RH' && $res['metier']['confiance']==='vert', "métier = RH/vert (pas COMPTA)");
ok($res['bloquant']===false, "bloquant = false (tout vert/confirmé)");

/* ════════════════════════════════════════════════════════════════════════
 * (b) Entité existante rapprochée → JAUNE, pas de doublon
 * ════════════════════════════════════════════════════════════════════════ */
echo "\n(b) Entité existante rapprochée → jaune proposée, pas de doublon\n";
$pdo->beginTransaction();
try {
    // Société + agence + tiers de test
    $pdo->prepare("INSERT INTO societes (nom, raison_sociale) VALUES ('TEST_SOC_RESO','TEST_SOC_RESO')")->execute();
    $socId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agences (id_societe, nom_agence) VALUES (?, 'TEST_AGE_RESO')")->execute([$socId]);
    $ageId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tiers (id_societe, id_agence, type_tiers, nom, prenom, nom_affichage, actif)
                   VALUES (?,?, 'personne_physique','PAYET','Jennifer','Jennifer PAYET',1)")->execute([$socId,$ageId]);
    $tiersId = (int)$pdo->lastInsertId();

    $res = fluxbox_resoudre_contexte(
        0,
        ['explicite'=>[], 'compte'=>['user_id'=>8,'societe_id'=>$socId,'societe_nom'=>'TEST_SOC_RESO']],
        ['entite_nom'=>'Jennifer PAYET'],
        null,
        $pdo
    );
    ok($res['entite']['existante']===true && (int)$res['entite']['id']===$tiersId, "entité rapprochée sur la fiche existante #$tiersId (pas de doublon)");
    ok($res['entite']['confiance']==='jaune', "entité = jaune (à confirmer)");
    ok($res['entite']['source']==='fiche', "source = fiche");
    ok(!empty($res['entite']['candidats']), "candidats alternatifs présents (cascade)");
    // Hérite société/agence de la fiche, en VERT (Invariant 3)
    ok((int)($res['societe']['id'] ?? 0)===$socId, "société héritée de la fiche entité");
    ok($res['societe']['source']==='fiche' && $res['societe']['confiance']==='vert', "société = fiche/vert (Invariant 3 — pas reprise du compte)");

    /* ── (b2) BIEN choisi explicitement → société/agence fiche/vert, type 'bien' ── */
    echo "\n(b2) Bien choisi explicitement → société/agence fiche/vert\n";
    $typeBienId = (int)($pdo->query("SELECT id_type_bien FROM biens WHERE id_type_bien IS NOT NULL LIMIT 1")->fetchColumn()
                  ?: $pdo->query("SELECT id FROM types_bien_legacy LIMIT 1")->fetchColumn() ?: 0);
    $pdo->prepare("INSERT INTO biens (id_societe, id_agence, id_type_bien, designation, adresse_1, ville)
                   VALUES (?,?,?, 'Lot 3B test', '7 Place Ampère', 'Lyon')")->execute([$socId,$ageId,$typeBienId ?: null]);
    $bienId = (int)$pdo->lastInsertId();
    $resB = fluxbox_resoudre_contexte(
        0,
        ['explicite'=>['entite_id'=>$bienId, 'entite_type'=>'bien', 'entite_nom'=>'7 Place Ampère — Lot 3B']],
        ['societe'=>'PIRATE','siren'=>'111111111'],  // doc tente une autre société → ignoré
        null,
        $pdo
    );
    ok($resB['entite']['type']==='bien' && (int)$resB['entite']['id']===$bienId, "entité = bien #$bienId");
    ok($resB['societe']['source']==='fiche' && (int)$resB['societe']['id']===$socId, "société = fiche du bien (pas le doc pirate)");
    ok($resB['agence']['source']==='fiche' && (int)$resB['agence']['id']===$ageId, "agence = fiche du bien");
    ok(strpos($resB['chemin'], 'TEST_SOC_RESO')!==false, "chemin (QUI) contient la société");
} finally {
    $pdo->rollBack();
}

/* ════════════════════════════════════════════════════════════════════════
 * (c) Aucune source sûre société → jaune (contexte_user) + bloquant=true
 * ════════════════════════════════════════════════════════════════════════ */
echo "\n(c) Société non sûre → jaune (compte) + bloquant=true (Invariants I1/I3)\n";
$res = fluxbox_resoudre_contexte(
    0,
    ['explicite'=>[], 'compte'=>['user_id'=>8,'societe_id'=>1,'societe_nom'=>'REGIE EMERY','agence_id'=>3,'agence_nom'=>'LYON']],
    ['type_doc'=>'FACTURE'], // aucune société dans le doc, aucune entité
    null,
    $pdo
);
ok($res['societe']['source']==='contexte_user', "société = source contexte_user (dernier recours)");
ok($res['societe']['confiance']==='jaune', "société = jaune (Invariant I1 : contexte_user jamais vert)");
ok($res['bloquant']===true, "bloquant = true (société jaune non confirmée)");

/* ════════════════════════════════════════════════════════════════════════
 * (d) Lot de 81 → entité résolue 1× et IDENTIQUE sur tout le lot (Invariant 4)
 * ════════════════════════════════════════════════════════════════════════ */
echo "\n(d) Lot à contexte partagé → entité identique pour tous les fichiers\n";
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO societes (nom, raison_sociale) VALUES ('TEST_SOC_LOT','TEST_SOC_LOT')")->execute();
    $socId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agences (id_societe, nom_agence) VALUES (?, 'TEST_AGE_LOT')")->execute([$socId]);
    $ageId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tiers (id_societe, id_agence, type_tiers, nom, nom_affichage, actif)
                   VALUES (?,?, 'personne_physique','DUPONT','DUPONT',1)")->execute([$socId,$ageId]);
    $tiersId = (int)$pdo->lastInsertId();

    $contexteLot = [
        'lot_id'=>777,
        'entite_verrouillee'=>['type'=>'tiers','id'=>$tiersId,'valeur'=>'locataire DUPONT'],
        'societe_id'=>$socId, 'agence_id'=>$ageId, 'source'=>'nom_dossier',
    ];
    // Simule 3 fichiers du lot, chacun avec une extraction DIFFÉRENTE (autre nom dans le doc)
    $ids = [];
    foreach (['MARTIN','BERNARD','PETIT'] as $autre) {
        $rL = fluxbox_resoudre_contexte(
            0,
            ['explicite'=>[], 'compte'=>['user_id'=>8,'societe_id'=>1,'societe_nom'=>'AUTRE']],
            ['entite_nom'=>$autre],  // le doc nomme quelqu'un d'autre → IGNORÉ (lot verrouillé)
            $contexteLot,
            $pdo
        );
        $ids[] = ((int)($rL['entite']['id'] ?? 0)) . ':' . ($rL['societe']['id'] ?? 0) . ':' . ($rL['agence']['id'] ?? 0);
        $lastSrc = $rL['entite']['source'];
    }
    ok(count(array_unique($ids))===1, "entité/société/agence IDENTIQUES sur les 3 fichiers du lot ($ids[0])");
    ok($lastSrc==='lot', "source entité = lot (verrouillée, doc ignoré)");
} finally {
    $pdo->rollBack();
}

/* ════════════════════════════════════════════════════════════════════════ */
echo "\n──────────────────────────────────────────\n";
echo ($fails===0 ? "✅ TOUS LES TESTS PASSENT" : "❌ $fails ÉCHEC(S)") . " — $tests assertions\n";
exit($fails === 0 ? 0 : 1);
