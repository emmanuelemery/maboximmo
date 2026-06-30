<?php
/**
 * Migration : CRÉANCIERS — note privée (canal note_privee).
 *
 * Étend creancier_dossier_message.canal pour porter, à côté du chat IA ('chat')
 * et du fil d'actualité partagé ('feed'), des NOTES PERSONNELLES privées
 * ('note_privee') visibles uniquement de leur auteur (id_user).
 *
 * AJOUT uniquement (idempotent : MODIFY rejouable). NE TOUCHE À RIEN d'autre.
 *
 * ── ROLLBACK (-- DOWN) ────────────────────────────────────────────────
 *   ALTER TABLE `creancier_dossier_message`
 *     MODIFY COLUMN `canal` ENUM('chat','feed') NOT NULL DEFAULT 'chat';
 */

return [
    'id'          => '20260630_creanciers_10',
    'title'       => 'CRÉANCIERS — notes personnelles privées (canal note_privee)',
    'description' => "Étend creancier_dossier_message.canal en ENUM('chat','feed','note_privee') pour porter des notes privées scopées à leur auteur. AJOUT uniquement.",
    'created_at'  => '2026-06-30',
    'sql' => <<<'SQL'
ALTER TABLE `creancier_dossier_message`
  MODIFY COLUMN `canal` ENUM('chat','feed','note_privee') NOT NULL DEFAULT 'chat'
  COMMENT 'chat = assistant IA ; feed = fil partagé signé ; note_privee = note perso (auteur only)';
SQL
];
