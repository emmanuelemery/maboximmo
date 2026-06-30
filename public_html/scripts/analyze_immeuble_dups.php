<?php
/**
 * analyze_immeuble_dups.php — RAPPORT (lecture seule) des doublons d'immeubles
 * détectés par google_place_id. N'écrit/ne fusionne RIEN.
 * Pour chaque immeuble d'un groupe : nb biens, biens avec annonce, nb baux, CRG.
 * Propose comme survivant l'immeuble qui porte une annonce.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

$dups = $pdo->query("SELECT google_place_id, GROUP_CONCAT(id) ids
    FROM immeubles WHERE google_place_id IS NOT NULL AND google_place_id<>''
    GROUP BY google_place_id HAVING COUNT(*)>1")->fetchAll(PDO::FETCH_ASSOC);

function immStats(PDO $pdo, int $id): array {
    $nbBiens = (int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_immeuble=$id")->fetchColumn();
    $nbAnn   = (int)$pdo->query("SELECT COUNT(*) FROM annonces a JOIN biens b ON b.id=a.id_bien WHERE b.id_immeuble=$id")->fetchColumn();
    $nbBaux  = (int)$pdo->query("SELECT COUNT(*) FROM baux bx JOIN biens b ON b.id=bx.id_bien WHERE b.id_immeuble=$id")->fetchColumn();
    return [$nbBiens, $nbAnn, $nbBaux];
}

$grp = 0;
foreach ($dups as $d) {
    $det = $pdo->query("SELECT id, nom_immeuble, adresse_1, code_postal, ville, code_crg, id_proprietaire, id_societe, adresse_formatee
        FROM immeubles WHERE id IN ({$d['ids']})")->fetchAll(PDO::FETCH_ASSOC);
    $hasCrg=false;$hasNon=false;
    foreach ($det as $x){ if($x['code_crg'])$hasCrg=true; else $hasNon=true; }
    if (!($hasCrg && $hasNon)) continue; // seulement les vrais doublons CRG↔annonce/autre
    $grp++;
    $fmt = $det[0]['adresse_formatee'] ?? '';
    echo "\n══ GROUPE $grp — $fmt\n";
    $survivor = null; $maxAnn = -1;
    foreach ($det as $x) {
        [$nb,$na,$nbx] = immStats($pdo, (int)$x['id']);
        $x['_b']=$nb;$x['_a']=$na;$x['_x']=$nbx;
        printf("   imm#%-6s soc=%-2s %-22s %-26s | CRG:%-12s prop:%-5s | biens:%-3s annonces:%-3s baux:%-3s\n",
            $x['id'], $x['id_societe']?:'-', mb_substr((string)$x['nom_immeuble'],0,22), mb_substr((string)$x['adresse_1'],0,26),
            $x['code_crg']?:'-', $x['id_proprietaire']?:'-', $nb, $na, $nbx);
        if ($na > $maxAnn) { $maxAnn = $na; $survivor = $x; }
    }
    // survivant = celui avec le + d'annonces ; à défaut le non-CRG (fiche d'origine)
    if ($maxAnn <= 0) { foreach ($det as $x){ if(!$x['code_crg']){ $survivor=$x; break; } } }
    echo "   → SURVIVANT proposé : imm#{$survivor['id']} (" . ($maxAnn>0 ? "porte $maxAnn annonce(s)" : "fiche d'origine sans annonce") . ") ; les autres y seraient rattachés.\n";
}
echo "\n(Rapport seul — aucune fusion effectuée.)\n";
