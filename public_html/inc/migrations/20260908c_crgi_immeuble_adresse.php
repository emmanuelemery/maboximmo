<?php
/**
 * Migration : L'ADRESSE DE L'IMMEUBLE — LUE, ET JAMAIS CELLE DE QUELQU'UN D'AUTRE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ ELLE ÉTAIT LUE PUIS JETÉE. Le lecteur ICS capte quatre champs — `nom`,
 *    `adresse`, `code_postal`, `ville` — et le pont vers l'intégration n'en
 *    transmettait que trois. `crgi_immeuble` n'avait même pas de colonne où la
 *    poser. Sur un dépôt, seuls 227 immeubles sur 594 portaient un nom
 *    ressemblant à une adresse : pour les 367 autres, la rue était disponible et
 *    perdue. Même faute que les honoraires de gestion, sur un autre champ.
 *
 * ⚠️ LES DEUX ÉDITEURS NE L'ÉCRIVENT PAS PAREIL, ET C'EST LA CLÉ.
 *      · ICS imprime sur des lignes séparées : « 192 CUVIER LYON 6 » (nom) puis
 *        « 192 RUE CUVIER » (adresse) puis « 69006 LYON ».
 *      · SPI n'imprime qu'un champ : « Immeuble 15 rue Siméon Gouet - 38200
 *        VIENNE » ou « Immeuble LES BALCONS DU CARDINAL - 69390 VERNAISON ».
 *        Tantôt une adresse, tantôt un nom de résidence — et rien d'autre.
 *    `adresse_source` dit donc D'OÙ vient ce qu'on affiche, pour qu'une rue
 *    déduite d'un nom ne se confonde jamais avec une rue imprimée.
 *
 * ⚠️ ON NE MÉLANGE JAMAIS AVEC L'ADRESSE DU PROPRIÉTAIRE NI CELLE DE L'AGENCE.
 *    Les deux figurent sur la même page — le mandant en haut à droite, l'agence
 *    en pied. Cette confusion a déjà coûté 56 attributions d'agence fausses au
 *    moment d'identifier les CRG. Une garde explicite refuse toute adresse égale
 *    au propriétaire du compte rendu ou à l'établissement émetteur.
 *
 * ⚠️ ET CE QUI MANQUE SE DIT. Un immeuble sans rue n'est pas un défaut de
 *    lecture quand le document ne la porte pas : c'est une donnée à compléter,
 *    et l'écran d'analyse doit la réclamer plutôt que de la laisser vide en
 *    silence.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER — deux colonnes de staging.
 */
return [
    'id'          => '20260908c_crgi_immeuble_adresse',
    'title'       => 'Intégration CRG — l’adresse de l’immeuble et sa provenance',
    'description' => "Ajoute crgi_immeuble.adresse et crgi_immeuble.adresse_source (LUE | NOM "
                   . "EST UNE ADRESSE | ABSENTE). Le lecteur ICS lisait l'adresse et le pont "
                   . "la jetait faute de colonne. La source distingue une rue IMPRIMÉE d'une "
                   . "rue reconnue dans le nom, et « ABSENTE » alimente la liste des immeubles "
                   . "à compléter dans l'écran d'analyse. Aucune écriture métier.",
    'created_at'  => '2026-09-08',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_immeuble`
  ADD COLUMN `adresse` VARCHAR(255) DEFAULT NULL
    COMMENT 'Rue de l immeuble, JAMAIS celle du proprietaire ni de l agence.'
    AFTER `nom`,
  ADD COLUMN `adresse_source` VARCHAR(24) NOT NULL DEFAULT 'ABSENTE'
    COMMENT 'LUE | NOM EST UNE ADRESSE | ABSENTE — d ou vient ce qu on affiche.'
    AFTER `adresse`;
SQL,
];
