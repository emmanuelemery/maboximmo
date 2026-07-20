<?php
/**
 * Migration : chargement des PREMIÈRES DONNÉES de la table déclencheurs (service Gestion).
 *
 * Pour CHAQUE société qui n'a encore AUCUN déclencheur : insère les 9 déclencheurs
 * Gestion de départ + les 5 actions d'exemple du « préavis de départ ».
 * Idempotente (NOT EXISTS) : rejouable sans doublon, et n'écrase pas ce que les
 * collaborateurs ont déjà ajouté (une société qui a déjà des déclencheurs est ignorée).
 */
return [
    'id'          => '20260720c_pilotage_declencheurs_seed',
    'title'       => 'Déclencheurs : données initiales (Gestion) par société',
    'description' => "Insère 9 déclencheurs Gestion + 5 actions du préavis pour chaque société sans déclencheur.",
    'created_at'  => '2026-07-20',
    'sql' => <<<'SQL'
INSERT INTO `pilotage_declencheurs` (`id_societe`,`service_slug`,`label`,`icon`,`status`,`display_order`)
SELECT s.id, 'gestion', X.label, X.icon, 'propose', X.ord
FROM `societes` s
CROSS JOIN (
        SELECT '📩' AS icon, 'Réception d''un préavis de départ'       AS label, 1 AS ord
  UNION ALL SELECT '💧', 'Déclaration d''un sinistre',                    2
  UNION ALL SELECT '🔧', 'Demande de travaux',                            3
  UNION ALL SELECT '🔑', 'Nouvelle demande de gestion locative',          4
  UNION ALL SELECT '👤', 'Nouveau propriétaire',                          5
  UNION ALL SELECT '💶', 'Réception d''un commandement de payer',         6
  UNION ALL SELECT '⚖️', 'Réception d''une décision de justice',          7
  UNION ALL SELECT '📈', 'Demande de révision de loyer',                  8
  UNION ALL SELECT '📞', 'Demande d''estimation',                         9
) X
WHERE NOT EXISTS (SELECT 1 FROM `pilotage_declencheurs` d WHERE d.id_societe = s.id);

INSERT INTO `pilotage_declencheur_missions` (`id_societe`,`declencheur_id`,`label_libre`,`display_order`)
SELECT d.id_societe, d.id, A.label, A.ord
FROM `pilotage_declencheurs` d
CROSS JOIN (
        SELECT 'Accuser réception du préavis au locataire'                AS label, 1 AS ord
  UNION ALL SELECT 'Planifier l''état des lieux de sortie',                  2
  UNION ALL SELECT 'Vérifier et préparer la restitution du dépôt de garantie', 3
  UNION ALL SELECT 'Informer le propriétaire du départ',                     4
  UNION ALL SELECT 'Préparer la remise en location du bien',                 5
) A
WHERE d.service_slug = 'gestion'
  AND d.label = 'Réception d''un préavis de départ'
  AND NOT EXISTS (SELECT 1 FROM `pilotage_declencheur_missions` m WHERE m.declencheur_id = d.id);
SQL
];
