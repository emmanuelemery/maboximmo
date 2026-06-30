<?php
/**
 * Migration 2026-06-27c : barème de BASE (id_societe = 0) — retrait FK bloquante.
 *
 * Le barème de BASE (super admin, s'applique à toutes les sociétés actuelles et
 * futures) utilise le sentinel id_societe = 0 (cohérent avec id_agence = 0 = modèle).
 * Or societe_tarifs_honoraires.id_societe a une FK vers societes → 0 serait refusé.
 * On retire la FK (l'intégrité est gérée applicativement : 0 = base, NULL = legacy).
 */
return [
    'id'          => '20260627c_honoraires_base_drop_fk',
    'title'       => 'Barème de base : retrait FK fk_tarifs_societe (autorise id_societe=0)',
    'description' => "Supprime la clé étrangère societe_tarifs_honoraires.fk_tarifs_societe pour permettre le barème de base (id_societe=0) appliqué à toutes les sociétés.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
ALTER TABLE `societe_tarifs_honoraires` DROP FOREIGN KEY `fk_tarifs_societe`;
SQL
];
