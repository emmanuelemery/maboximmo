<?php
/**
 * Migration : lots 83 & 84 du 77 Av. Berthelot (GROUPE SIR) — locaux commerciaux
 * vacants (ex-locataire « SP LYON 7 », parti, loyer 2026 T1 = 0) → bascule en
 * COMMERCIALISATION VENTE pour qu'ils apparaissent dans « Portefeuille à vendre ».
 *
 * Les biens EXISTENT déjà (réf 01040117-0083 = bien #980, 01040117-0084 = bien #1007)
 * et sont liés aux CRG — on ne crée RIEN (anti-doublon). On les marque seulement
 * « vacant à vendre ». Ciblage par reference_bien (stable en prod). Rejouable.
 */
return [
    'id'          => '20260606d_lots_83_84_vacant_a_vendre',
    'title'       => 'Lots 83/84 Berthelot — vacants à vendre',
    'description' => "Bascule les lots 83 (01040117-0083) et 84 (01040117-0084) du 77 Av. Berthelot en commercialisation vente, occupation libre (ex-SP LYON 7 parti).",
    'created_at'  => '2026-06-06',
    'sql' => <<<'SQL'
UPDATE `biens`
SET type_commercialisation = 'vente',
    occupation_bien        = 'libre',
    date_mise_en_vente     = IFNULL(date_mise_en_vente, CURDATE()),
    date_modification      = NOW()
WHERE reference_bien IN ('01040117-0083', '01040117-0084')
  AND (statut_bien IS NULL OR statut_bien NOT IN ('vendu', 'archive', 'supprime'));
SQL,
];
