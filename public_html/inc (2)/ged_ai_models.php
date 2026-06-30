<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Sélection modèle IA par usage.
 * Fichier : inc/ged_ai_models.php
 *
 * Centralise la stratégie modèle. Un seul endroit à modifier pour ajuster
 * le ratio coût/qualité globalement.
 *
 * Stratégie :
 *   - Sonnet 4.6 : extraction structurée + analyse persistante + chat multi-docs
 *   - Haiku 4.5 : chat simple (questions courtes, peu de contexte)
 *   - Opus 4.7  : audit dossier 200+ docs, raisonnement profond
 *   - GPT-4o    : fallback si Anthropic down (pas Anthropic mini, qualité comparable)
 */

const GED_MODEL_EXTRACTION    = 'claude-sonnet-4-6';
const GED_MODEL_ANALYSE       = 'claude-sonnet-4-6';
const GED_MODEL_CHAT_SIMPLE   = 'claude-haiku-4-5';
const GED_MODEL_CHAT_COMPLEXE = 'claude-sonnet-4-6';
const GED_MODEL_AUDIT_DOSSIER = 'claude-opus-4-7';
const GED_MODEL_FALLBACK      = 'gpt-4o';

/**
 * Retourne l'ID modèle pour un usage métier donné.
 *
 * Usages reconnus :
 *   extraction | analyse | chat_simple | chat_complexe | audit_dossier | fallback
 *
 * @throws InvalidArgumentException si usage inconnu.
 */
function ged_model_for(string $usage): string
{
    $map = [
        'extraction'    => GED_MODEL_EXTRACTION,
        'analyse'       => GED_MODEL_ANALYSE,
        'chat_simple'   => GED_MODEL_CHAT_SIMPLE,
        'chat_complexe' => GED_MODEL_CHAT_COMPLEXE,
        'audit_dossier' => GED_MODEL_AUDIT_DOSSIER,
        'fallback'      => GED_MODEL_FALLBACK,
    ];
    if (!isset($map[$usage])) {
        throw new InvalidArgumentException("ged_model_for : usage inconnu '{$usage}'.");
    }
    return $map[$usage];
}

/**
 * Indique si un model_id est de la famille Anthropic (Claude).
 */
function ged_is_anthropic_model(string $modelId): bool
{
    return str_starts_with($modelId, 'claude-');
}

/**
 * Indique si un model_id est de la famille OpenAI.
 */
function ged_is_openai_model(string $modelId): bool
{
    return str_starts_with($modelId, 'gpt-');
}

/**
 * Escalade dynamique : si le contexte dépasse le seuil, monte sur Opus
 * (raisonnement profond justifié), sinon reste sur Sonnet (économie).
 *
 * @param int $estimatedTokens Estimation tokens d'entrée (caractères / 4 environ).
 */
function ged_choose_chat_model(int $estimatedTokens, int $docsCount = 1): string
{
    if ($estimatedTokens > 80_000 || $docsCount > 10) {
        return GED_MODEL_AUDIT_DOSSIER;
    }
    if ($docsCount > 1 || $estimatedTokens > 4_000) {
        return GED_MODEL_CHAT_COMPLEXE;
    }
    return GED_MODEL_CHAT_SIMPLE;
}
