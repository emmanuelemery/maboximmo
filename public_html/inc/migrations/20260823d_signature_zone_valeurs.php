<?php
/**
 * Migration : ce que CHAQUE signataire a effectivement rempli, zone par zone.
 *
 * `signature_zones` dit OÙ écrire. Cette table dit QUOI a été écrit, PAR QUI et QUAND.
 * Deux natures différentes, deux tables : une zone se déplace, se renomme, se supprime —
 * une valeur apposée, jamais.
 *
 * ── POURQUOI L'APPOSITION N'A LIEU QU'À LA FIN ─────────────────────────────────────
 * Arbitrage d'Emmanuel, 23/08/2026. Réassembler le PDF à chaque signature en changerait
 * l'empreinte à chaque fois — or l'empreinte est précisément ce qui prouve que le
 * document n'a pas bougé entre deux signatures. On collecte donc les valeurs ici, et on
 * n'écrit sur le PDF qu'une seule fois, quand tout le monde a signé.
 *
 * ⚠️ MAIS CHAQUE SIGNATAIRE DOIT VOIR SA SIGNATURE tout de suite. Sans retour visible,
 * il croit que ça n'a pas marché, recommence, ou appelle l'agence. C'est la raison
 * d'être de `valeur_image` : la page relit ce qui est ici et le dessine sur le document,
 * immédiatement. Le rendu à l'écran est donc alimenté par la MÊME source que
 * l'apposition finale — ils ne peuvent pas diverger.
 *
 * ── UNE VALEUR NE SE RÉÉCRIT PAS ───────────────────────────────────────────────────
 * L'unicité (zone × signature) empêche le double envoi de créer deux valeurs pour la
 * même case. Corriger, c'est repartir d'une nouvelle cérémonie — pas repasser sur ce
 * qu'on a déjà recueilli. `signed_at` et `ip` accompagnent la valeur : ce sont eux, avec
 * l'identification, qui font la preuve — pas le tracé.
 */

return [
    'id'          => '20260823d_signature_zone_valeurs',
    'title'       => 'Valeurs recueillies zone par zone',
    'description' => "Table signature_zone_valeurs : ce que chaque signataire a rempli dans chaque zone, horodaté. L'apposition sur le PDF n'a lieu qu'à la fin (l'empreinte ne doit pas changer entre deux signatures), mais la page relit cette table pour montrer à chacun sa signature immédiatement.",
    'created_at'  => '2026-08-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `signature_zone_valeurs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_zone`       BIGINT UNSIGNED NOT NULL,
  `id_signature`  BIGINT UNSIGNED NOT NULL COMMENT 'bail_signatures.id — QUI a rempli',
  `valeur_texte`  TEXT NULL,
  `valeur_image`  MEDIUMTEXT NULL COMMENT 'PNG en data URL (signature, paraphe)',
  `signed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`            VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_zone_sig` (`id_zone`, `id_signature`),
  KEY `idx_sig` (`id_signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ce qui a ete rempli dans chaque zone, par qui et quand';
SQL
];
