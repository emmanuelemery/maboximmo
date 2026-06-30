<?php
/**
 * Migration LOCALE — NEUTRALISÉE (no-op).
 *
 * Version d'origine : supprimait les enfants des 556 biens doublons puis les biens.
 * Les statements enfants (#1-5) ont bien été exécutés ; le #6 (DELETE biens) échouait
 * sur l'erreur MySQL 1093 (auto-référence `biens` dans une sous-requête EXISTS).
 *
 * La suppression finale des biens est désormais assurée par la migration
 * 20260605b_dedup_biens_delete (DELETE sans auto-référence).
 *
 * Ce fichier est volontairement rendu inoffensif pour qu'un éventuel rejeu
 * ne déclenche plus l'erreur 1093.
 */
return [
    'id'          => '20260605_dedup_biens_ref_doublons',
    'title'       => 'Dedup biens doublons par reference (LOCAL) — NEUTRALISÉE (voir 20260605b)',
    'description' => 'Neutralisée : la suppression finale est faite par 20260605b_dedup_biens_delete. No-op.',
    'created_at'  => '2026-06-05',
    'sql' => <<<'SQL'
DO 1;
SQL,
];
