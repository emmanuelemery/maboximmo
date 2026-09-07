<?php
/**
 * Migration : L'OCCUPATION RETIENT SI SON BLOC APPELLE UN LOYER.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ SANS CE FAIT, LA PHASE 3 TRANCHAIT SUR L'ORDRE D'IMPRESSION. Un lot imprimé
 *    DEUX FOIS au même arrêté porte un occupant qui appelle son loyer — trois
 *    lignes « Du … Au … » — et un ancien qui ne porte qu'un solde figé. Faute de
 *    savoir lequel appelle, le moteur prenait le second bloc pour un successeur et
 *    déclarait le premier parti.
 *
 * ⚠️ MESURÉ : **616 des 623 « départs » d'un dépôt** reposaient sur un occupant lu
 *    au MÊME arrêté, et non sur une période ultérieure. Dont un locataire toujours
 *    en place, dont la dette grossissait de 6,28 € à 4 712,78 € pendant que le
 *    moteur le disait sorti — et dont le voisin de bloc restait à 0,00 € sur six
 *    trimestres, sans un seul appel.
 *
 * ⚠️ L'ORDRE D'UNE PAGE NE PROUVE RIEN SUR LE TEMPS. La présence d'un appel de
 *    loyer, si. Règle d'Emmanuel, 07/09/2026 : « les appels de loyer déterminent
 *    qu'il est en place ; mais en cours de trimestre il peut y avoir un changement,
 *    et alors le dernier qui arrive dans le CRG est celui qui est devenu actif ».
 *    Le second critère ne s'ouvre donc que si les DEUX appellent.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER — une colonne de staging, rien d'autre.
 */
return [
    'id'          => '20260907a_crgi_occupation_appels',
    'title'       => 'Intégration CRG — les appels de loyer du bloc d’occupation',
    'description' => "Ajoute crgi_occupation.appels : le nombre de lignes « Du … Au … » du "
                   . "bloc. C'est ce qui distingue l'occupant en place de l'ancien locataire "
                   . "imprimé à côté de lui, sur le même lot et au même arrêté. Sans elle, la "
                   . "phase 3 tranchait sur l'ordre d'impression et fabriquait 616 faux "
                   . "départs sur un seul dépôt. Aucune écriture métier.",
    'created_at'  => '2026-09-07',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_occupation`
  ADD COLUMN `appels` SMALLINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Lignes « Du … Au … » du bloc : qui appelle un loyer est en place.'
  AFTER `solde_source`;
SQL,
];
