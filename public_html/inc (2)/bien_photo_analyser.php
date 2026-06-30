<?php
declare(strict_types=1);

/**
 * Analyse une photo de bien via Claude Sonnet 4.6 Vision (fallback GPT-4o)
 * et retourne 2 blocs :
 *   - commercial : { categorie, description } pour alimenter l'annonce
 *   - critique   : { niveau, points_forts[], points_faibles[], conseil }
 *                  pour coacher l'équipe sur la qualité de prise de vue
 *
 * Réutilise le moteur ged_vision (Claude prim. + GPT-4o fallback).
 */

require_once __DIR__ . '/../modules/ged/ged_vision.php';

/**
 * @return array{
 *   ok: bool,
 *   error?: string,
 *   commercial?: array{categorie:string, description:string},
 *   critique?: array{niveau:string, points_forts:array, points_faibles:array, conseil:string},
 *   model_used?: string,
 *   // Compat ascendante pour les anciens appelants (description courte uniquement)
 *   categorie?: string,
 *   description?: string,
 * }
 */
function analyserPhotoBien(string $imagePath): array
{
    if (!is_readable($imagePath)) {
        return ['ok' => false, 'error' => 'Image illisible'];
    }

    $systemPrompt = "Tu es un expert en immobilier ET en photographie immobilière. "
        . "Tu analyses des photos de biens à mettre en annonce. "
        . "Tu réponds STRICTEMENT en JSON valide, jamais de texte hors JSON.";

    $userPrompt = <<<PROMPT
Analyse cette photo d'un bien immobilier français et réponds en JSON STRICT.

OBJECTIFS :
1. Analyse commerciale (pour annonce immobilière)
2. Critique de la prise de vue (pour améliorer les prochaines photos)

FORMAT JSON OBLIGATOIRE (toutes les clés requises) :
{
  "commercial": {
    "categorie": "exterieur|salon|cuisine|chambre|salle_de_bain|wc|entree|couloir|terrasse|jardin|garage|cave|vue|plan|autre",
    "description": "phrase 15-30 mots décrivant CE QUI EST RÉELLEMENT VISIBLE : matériaux, luminosité, style, équipements. Ton commercial sobre, en français, sans inventer."
  },
  "critique": {
    "niveau": "bon|moyen|mauvais",
    "points_forts": ["...", "..."],
    "points_faibles": ["...", "..."],
    "conseil": "UN conseil prioritaire pour améliorer la prochaine prise de vue (action concrète terrain)"
  }
}

CRITIQUE PHOTO — critères à évaluer :
  - cadrage (angle, composition, lignes droites)
  - luminosité (sombre, surexposée, contre-jour)
  - rangement (objets parasites, encombrement, désordre visible)
  - angle (trop bas/haut, distorsion)
  - lisibilité (la pièce se comprend-elle ?)

Niveau :
  - "bon"     : photo immédiatement publiable
  - "moyen"   : publiable mais améliorable
  - "mauvais" : à refaire

Sois concret et utile terrain. Pas de blabla. JSON UNIQUEMENT.
PROMPT;

    try {
        $res = gedVisionExtract($imagePath, $systemPrompt, $userPrompt);
        $data = $res['data'] ?? [];

        $commercial = isset($data['commercial']) && is_array($data['commercial']) ? $data['commercial'] : [];
        $critique   = isset($data['critique'])   && is_array($data['critique'])   ? $data['critique']   : [];

        $categorie   = trim((string)($commercial['categorie']   ?? 'autre'));
        $description = trim((string)($commercial['description'] ?? ''));

        $niveau = strtolower(trim((string)($critique['niveau'] ?? '')));
        if (!in_array($niveau, ['bon', 'moyen', 'mauvais'], true)) $niveau = '';

        $pf = is_array($critique['points_forts']   ?? null) ? array_values(array_filter(array_map('strval', $critique['points_forts']),   fn($s) => trim($s) !== '')) : [];
        $pw = is_array($critique['points_faibles'] ?? null) ? array_values(array_filter(array_map('strval', $critique['points_faibles']), fn($s) => trim($s) !== '')) : [];
        $conseil = trim((string)($critique['conseil'] ?? ''));

        return [
            'ok'         => true,
            'model_used' => (string)($res['model_used'] ?? ''),
            'commercial' => [
                'categorie'   => $categorie,
                'description' => $description,
            ],
            'critique' => [
                'niveau'         => $niveau,
                'points_forts'   => $pf,
                'points_faibles' => $pw,
                'conseil'        => $conseil,
            ],
            // Compat ascendante (anciens appelants qui lisaient ['categorie']/['description'] à plat)
            'categorie'   => $categorie,
            'description' => $description,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
