<?php
/**
 * Migration : les SPÉCIMENS — signature, paraphe, cachet — apposés sur un document.
 *
 * Trois objets DISTINCTS, jamais une image unique (arbitrage d'Emmanuel, 23/08/2026) :
 *   · la SIGNATURE et le PARAPHE appartiennent à une PERSONNE (`user`) ;
 *   · le CACHET appartient à la SOCIÉTÉ (`societe`) — il ne suit pas la personne.
 * Ils sont composés au moment d'apposer, pas au stockage. Empiler les trois dans une
 * seule image condamnerait à tout refaire au moindre changement de société, et
 * interdirait de poser la mention ailleurs que collée sous la signature.
 *
 * ⚠️ La MENTION (« Bon pour accord », « Lu et approuvé ») n'est PAS ici : c'est un type
 * de zone avec un texte, pas un spécimen. Elle change d'un document à l'autre.
 *
 * ── ON N'ÉCRASE JAMAIS UN SPÉCIMEN ─────────────────────────────────────────────────
 * Chaque enregistrement crée une LIGNE ; l'ancienne passe simplement à `actif = 0`.
 * Raison : si une signature apposée en mars est un jour contestée, il faut pouvoir
 * montrer le spécimen **tel qu'il était en mars**. Une colonne écrasée à chaque mise à
 * jour rend cette démonstration impossible, et c'est le genre de manque qui ne se
 * rattrape pas après coup. C'est aussi pourquoi `apposé` référencera l'ID de version
 * utilisée, et non « la signature de l'utilisateur ».
 *
 * ── CE QUE CE SPÉCIMEN PROUVE : RIEN, À LUI SEUL ───────────────────────────────────
 * Une image recollée sur un PDF n'est pas une signature au sens de l'article 1367 al. 2
 * du Code civil : n'importe qui disposant du fichier pourrait la reproduire. Elle est
 * l'APPARENCE — ce que le destinataire reconnaît. La PREUVE, c'est l'identification
 * (compte, horodatage, IP, empreinte du document), consignée dans la page de
 * justificatifs jointe à l'acte. Le certificat doit le dire ainsi et ne jamais laisser
 * croire à une signature qualifiée.
 */

return [
    'id'          => '20260823b_signature_specimens',
    'title'       => 'Spécimens de signature, paraphe et cachet (versionnés)',
    'description' => "Table signature_specimens : le tracé d'une personne (signature, paraphe) et le cachet d'une société, stockés en versions successives — on n'écrase jamais, pour pouvoir montrer le spécimen tel qu'il était à la date d'une signature contestée.",
    'created_at'  => '2026-08-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `signature_specimens` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proprietaire_type` VARCHAR(10) NOT NULL COMMENT 'user | societe — a QUI appartient le specimen',
  `proprietaire_id`  INT UNSIGNED NOT NULL,
  `type`             VARCHAR(12) NOT NULL DEFAULT 'signature' COMMENT 'signature | paraphe | cachet',
  `image_data`       MEDIUMTEXT NOT NULL COMMENT 'PNG en data URL — trace ou image detouree',
  `largeur_px`       SMALLINT UNSIGNED NULL,
  `hauteur_px`       SMALLINT UNSIGNED NULL,
  `actif`            TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = version remplacee, CONSERVEE',
  `cree_par`         INT UNSIGNED NULL,
  `ip`               VARCHAR(45) NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prop` (`proprietaire_type`, `proprietaire_id`, `type`, `actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Specimens apposes sur un document — versionnes, jamais ecrases';
SQL
];
