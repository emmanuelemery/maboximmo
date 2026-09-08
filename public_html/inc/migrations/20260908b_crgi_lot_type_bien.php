<?php
/**
 * Migration : LE TYPE DE BIEN NORMALISÉ, À CÔTÉ DU LIBELLÉ BRUT.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ LE TYPE ÉTAIT LU SUR TOUS LES LOTS, ET INEXPLOITABLE. `crgi_lot.libelle`
 *    porte ce que le document imprime — « Appartement 1 Pièce », « Appart. T3 »,
 *    « Local », « Local commercial 1 Pièce », « Maison village », « Entrepot »,
 *    « Garage Simple » : plus de cinquante graphies pour huit natures. Aucun
 *    regroupement n'était possible, et je m'apprêtais à faire ARBITRER des lots
 *    que le métier lit sans hésiter.
 *
 * ⚠️ « LOCAL » = COMMERCE, et seul le SILENCE vaut HABITATION. Emmanuel,
 *    08/09/2026. Confondre « pas écrit » et « écrit brièvement » fabrique des
 *    questions : les 95 lots nommés « Local » n'ont rien d'indéterminé.
 *
 * ⚠️ ET LE DOCUMENT ÉCRIT PARFOIS « VENDU » À LA PLACE DU TYPE. Ce n'est pas une
 *    nature de bien, c'est un ÉTAT — et il est LU, pas déduit. Ces lots-là n'ont
 *    donc pas à passer par la file « vendu ou gestion terminée ? » : la réponse
 *    est imprimée.
 *
 * ⚠️ LE LIBELLÉ BRUT N'EST PAS REMPLACÉ, LA CATÉGORIE S'AJOUTE. Le jour où la
 *    normalisation se trompe, ce que le document dit est encore là pour le
 *    prouver.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER — deux colonnes de staging, rien d'autre.
 */
return [
    'id'          => '20260908b_crgi_lot_type_bien',
    'title'       => 'Intégration CRG — type de bien normalisé et état « vendu » lu',
    'description' => "Ajoute crgi_lot.type_bien (LOCAL COMMERCIAL, BUREAU, APPARTEMENT, MAISON, "
                   . "GARAGE, TERRAIN, PANNEAU, PARTIES COMMUNES, HABITATION au silence) et "
                   . "crgi_lot.vendu, quand le document écrit « VENDU » à la place du type. "
                   . "Le libellé brut reste intact à côté. Mesuré sur 1 144 lots : 762 "
                   . "appartements, 158 locaux commerciaux, 126 maisons, 81 garages, 7 bureaux, "
                   . "zéro indéterminé. Aucune écriture métier.",
    'created_at'  => '2026-09-08',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_lot`
  ADD COLUMN `type_bien` VARCHAR(24) NOT NULL DEFAULT 'HABITATION'
    COMMENT 'Categorie normalisee. Le libelle brut reste dans `libelle`.'
    AFTER `libelle`,
  ADD COLUMN `vendu` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Le document ecrit VENDU a la place du type : etat LU, pas deduit.'
    AFTER `type_bien`,
  ADD KEY `idx_type_bien` (`import_id`, `type_bien`);
SQL,
];
