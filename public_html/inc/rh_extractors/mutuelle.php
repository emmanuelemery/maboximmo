<?php
/**
 * inc/rh_extractors/mutuelle.php — Extraction attestation mutuelle.
 *
 * Deux cas gérés (détectés automatiquement) :
 *   A) Attestation d'adhésion → organisme, n° adhérent, dates, garanties
 *   B) Formulaire de dispense → motif de dispense
 *
 * Champs extraits :
 *   - organisme          : nom de la mutuelle
 *   - numero_adherent    : identifiant unique
 *   - numero_contrat     : contrat collectif
 *   - nom_assure, prenom_assure
 *   - date_effet         : début de l'adhésion
 *   - date_expiration    : fin / renouvellement
 *   - garanties          : base / option 1 / option 2 / etc.
 *   - est_dispense       : bool (si cas B)
 *   - motif_dispense     : raison de la dispense
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_mutuelle(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    if (trim($text) !== '') {
        /* ─── Détection Dispense vs Adhésion ─────────────────── */
        $isDispense = (bool)preg_match('/dispense|je\s+(?:suis|certifie)\s+(?:couvert|dispens)/i', $text);
        if ($isDispense) {
            $fields['est_dispense'] = true;
            $score += 20;

            $motifs = [
                '/couverture.*conjoint/i'           => 'couverture_conjoint',
                '/CMU\-?C|complementaire\s+sant[ée]\s+solidaire/i' => 'cmu_css',
                '/CDD.*(?:moins|<)\s*3\s*mois/i'    => 'cdd_court',
                '/temps\s+partiel.*(?:moins|<)\s*15h/i' => 'temps_partiel',
                '/ayant\s+droit.*autre\s+contrat/i' => 'ayant_droit_tiers',
                '/Alsace\s*Moselle|r[ée]gime\s+local/i' => 'regime_local',
            ];
            foreach ($motifs as $pat => $code) {
                if (preg_match($pat, $text)) {
                    $fields['motif_dispense'] = $code;
                    $score += 15;
                    break;
                }
            }
        }

        /* ─── Organisme mutualiste ───────────────────────────── */
        if (preg_match('/(?:Mutuelle|Pr[ée]voyance|Assurance|Organisme)[^\n:]*[:\s]\s*([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s&]{2,60})/u', $text, $m)) {
            $org = trim($m[1]);
            $org = preg_split('/\s{2,}|\n/', $org)[0] ?? $org;
            if (mb_strlen($org) >= 3) {
                $fields['organisme'] = rhNormalizeName($org);
                $score += 15;
            }
        }

        /* ─── Numéro d'adhérent ──────────────────────────────── */
        if (preg_match('/(?:N[°o]\s*adh[ée]rent|Num[ée]ro\s+d.adh[ée]rent|ID\s+adh[ée]rent)[^\n:]*[:\s]\s*([A-Z0-9\-]{6,20})/iu', $text, $m)) {
            $fields['numero_adherent'] = strtoupper(trim($m[1]));
            $score += 15;
        }

        /* ─── Numéro de contrat ──────────────────────────────── */
        if (preg_match('/(?:N[°o]\s+contrat|Contrat\s+collectif)[^\n:]*[:\s]\s*([A-Z0-9\-\/]{6,25})/iu', $text, $m)) {
            $fields['numero_contrat'] = strtoupper(trim($m[1]));
            $score += 10;
        }

        /* ─── Nom / prénom assuré ────────────────────────────── */
        if (preg_match('/(?:Assur[ée]|B[ée]n[ée]ficiaire|Nom\s+de\s+l.assur[ée])[^\n:]*[:\s]\s*([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s]{3,60})/u', $text, $m)) {
            $full = trim($m[1]);
            $full = preg_split('/\s{2,}|\n/', $full)[0] ?? $full;
            $parts = preg_split('/\s+/', $full);
            if (count($parts) >= 2) {
                $fields['prenom_assure'] = rhNormalizeName($parts[0]);
                $fields['nom_assure']    = rhNormalizeName(implode(' ', array_slice($parts, 1)));
                $score += 8;
            }
        }

        /* ─── Dates effet / expiration ───────────────────────── */
        if (preg_match('/(?:Date\s+d.effet|Effet\s+au|Adh[ée]sion\s+le)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) { $fields['date_effet'] = $norm; $score += 10; }
        }
        if (preg_match('/(?:Expiration|Valable\s+jusqu\'au|Renouvellement)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_expiration'] = $norm;
                $valid['date_expiration']  = rhDateNotExpired($norm) ? 'ok' : 'expired';
                $score += 10;
            }
        }
    }

    /* ─── Fallback IA ────────────────────────────────────────── */
    if ($score < 60 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $ia = rhMutuelleCallIa($text, $visionImages);
            if (!empty($ia)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';
                foreach (['organisme','numero_adherent','numero_contrat','nom_assure','prenom_assure','date_effet','date_expiration','est_dispense','motif_dispense'] as $k) {
                    if (isset($ia[$k]) && (empty($fields[$k]) || $fields[$k] === false)) {
                        $val = $ia[$k];
                        if (in_array($k, ['date_effet','date_expiration'], true)) {
                            $val = rhNormalizeDate($val) ?? $val;
                        } elseif (in_array($k, ['organisme','nom_assure','prenom_assure'], true)) {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 7;
                    }
                }
                if (!empty($fields['date_expiration'])) {
                    $valid['date_expiration'] = rhDateNotExpired($fields['date_expiration']) ? 'ok' : 'expired';
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/mutuelle] IA: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhMutuelleCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en attestations de mutuelle / complémentaire santé française.
Deux cas possibles à détecter automatiquement :

  A) Attestation d'adhésion (cas standard)
  B) Formulaire de dispense (cas exceptionnel)

Retourne UNIQUEMENT un JSON plat avec ces clés (omets celles absentes) :

  - "organisme"        : nom de la mutuelle
  - "numero_adherent"  : identifiant de l'adhérent
  - "numero_contrat"   : contrat collectif
  - "nom_assure"
  - "prenom_assure"
  - "date_effet"       : début d'adhésion, YYYY-MM-DD
  - "date_expiration"  : fin / renouvellement, YYYY-MM-DD
  - "est_dispense"     : true/false
  - "motif_dispense"   : si cas B, code parmi :
      "couverture_conjoint", "cmu_css", "cdd_court",
      "temps_partiel", "ayant_droit_tiers", "regime_local"

RÈGLES :
- N'INVENTE JAMAIS.
- Dates au format YYYY-MM-DD.
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'une attestation mutuelle :\n\n{$text}\n\nJSON."
        : "Image d'une attestation mutuelle. JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
