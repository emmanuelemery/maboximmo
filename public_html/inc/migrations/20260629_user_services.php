<?php
/**
 * Migration : un collaborateur peut appartenir à PLUSIEURS services à la fois
 * (gestion, syndic, transaction, comptabilité). Le champ mono-valeur `users.service`
 * ne suffit plus → table pivot `user_services` (id_user × service).
 * `users.service` est conservé (compat lecture legacy) = service principal.
 * Idempotente : CREATE IF NOT EXISTS + seed INSERT IGNORE depuis l'existant.
 */
return [
    'id'          => '20260629_user_services',
    'title'       => 'Users : multi-services (table pivot user_services)',
    'description' => "Crée `user_services` (id_user × service) pour gérer plusieurs métiers par collaborateur. Seed depuis `users.service` ('autre'→gestion).",
    'created_at'  => '2026-06-29',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_services` (
  `id_user`  INT NOT NULL,
  `service`  VARCHAR(20) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`, `service`),
  INDEX `idx_service` (`service`)
);

INSERT IGNORE INTO `user_services` (`id_user`, `service`)
SELECT u.id,
       CASE WHEN u.service IS NULL OR u.service = '' OR u.service = 'autre'
            THEN 'gestion' ELSE u.service END
FROM `users` u
WHERE u.id_role IN (1,2,3);
SQL
];
