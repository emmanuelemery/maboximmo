<?php
/**
 * Migration : document_requests.close_on_complete
 *
 * Drapeau opt-in PAR DEMANDE : quand la demande devient « complet », le lien de
 * dépôt « tombe » immédiatement (expires_at = maintenant) → plus déposable.
 * Utilisé pour le dépôt COMPTABLE (bulletins/projet salaires) : terminé = clos.
 * Les autres demandes (ex. dossier candidat locataire) restent à 0 et gardent
 * leur lien ouvert jusqu'à expiration / autre déclencheur métier.
 *
 * Idempotente (ADD COLUMN IF NOT EXISTS).
 */
return [
    'id'          => '20260702_dr_close_on_complete',
    'title'       => 'document_requests : close_on_complete (lien qui tombe à la complétion)',
    'description' => "Ajoute close_on_complete TINYINT(1) DEFAULT 0 sur document_requests.",
    'created_at'  => '2026-07-02',
    'sql' => <<<'SQL'
ALTER TABLE `document_requests`
  ADD COLUMN IF NOT EXISTS `close_on_complete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `require_email_gate`;
SQL
];
