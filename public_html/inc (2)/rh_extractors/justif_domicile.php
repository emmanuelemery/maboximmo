<?php
/**
 * inc/rh_extractors/justif_domicile.php — Extraction justif. de domicile.
 *
 * Types acceptés : facture EDF/GDF/eau, quittance loyer, avis d'imposition,
 * attestation CAF, relevé bancaire avec adresse, etc.
 *
 * Champs extraits : nom_titulaire, adresse, code_postal, ville, date_doc,
 *                   type_doc (facture|quittance|attestation|avis), emetteur
 *
 * Validation : date du document < 3 mois (alerte sinon, non bloquant).
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_justif_domicile(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    if (trim($text) !== '') {
        /* ─── Détection du type de document ───────────────────────── */
        $typeDoc = null;
        $emetteur = null;

        $emetteurs = [
            '/\bEDF\b/'              => ['EDF', 'facture'],
            '/\bENGIE\b/i'           => ['ENGIE', 'facture'],
            '/\bVeolia\b/i'          => ['Veolia', 'facture'],
            '/\bGDF\b/'              => ['GDF', 'facture'],
            '/\bSuez\b/i'            => ['Suez', 'facture'],
            '/\bOrange\b/i'          => ['Orange', 'facture'],
            '/\bSFR\b/'              => ['SFR', 'facture'],
            '/\bBouygues\s+Telecom\b/i' => ['Bouygues Telecom', 'facture'],
            '/\bFree\s+Mobile\b/i'   => ['Free', 'facture'],
            '/\bavis\s+d.imposition\b/i' => ['DGFiP', 'avis_imposition'],
            '/\bquittance\s+de\s+loyer\b/i' => ['Bailleur', 'quittance'],
            '/\bCAF\b/'              => ['CAF', 'attestation'],
        ];
        foreach ($emetteurs as $pat => [$em, $type]) {
            if (preg_match($pat, $text)) {
                $emetteur = $em;
                $typeDoc  = $type;
                $score   += 15;
                break;
            }
        }

        if ($emetteur) {
            $fields['emetteur'] = $emetteur;
            $fields['type_doc'] = $typeDoc;
        }

        /* ─── Code postal + ville (pattern "75001 PARIS") ─────────── */
        if (preg_match('/\b(\d{5})\s+([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s]{2,40})\b/u', $text, $m)) {
            $fields['code_postal'] = $m[1];
            $fields['ville']       = rhNormalizeName(trim($m[2]));
            $score += 20;
        }

        /* ─── Adresse (numéro + rue/avenue/etc) ───────────────────── */
        if (preg_match('/\b(\d{1,4}(?:\s*(?:bis|ter|quater))?[,\s]+(?:rue|avenue|boulevard|impasse|all[ée]e|chemin|place|route|quai|passage|cours|voie)[^\n,]{3,60})/iu', $text, $m)) {
            $adresse = trim($m[1]);
            $adresse = preg_replace('/\s{2,}/', ' ', $adresse);
            $fields['adresse'] = $adresse;
            $score += 25;
        }

        /* ─── Nom du titulaire (après "à l'attention de" ou début de facture) ── */
        if (preg_match('/(?:[ÀA]\s+l.attention\s+de|Titulaire|Nom\s+du\s+titulaire|Destinataire)\s*[:\s]\s*([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s]{3,60})/u', $text, $m)) {
            $nom = trim($m[1]);
            $nom = preg_split('/\s{2,}/', $nom)[0] ?? $nom;
            if (mb_strlen($nom) >= 3) {
                $fields['nom_titulaire'] = rhNormalizeName($nom);
                $score += 15;
            }
        }

        /* ─── Date du document ────────────────────────────────────── */
        $dates = [];
        if (preg_match_all('/\b(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})\b/', $text, $matches)) {
            foreach ($matches[1] as $d) {
                $norm = rhNormalizeDate($d);
                if ($norm) $dates[] = $norm;
            }
        }
        // Format littéral français "12 avril 2026"
        if (preg_match('/\b(\d{1,2}\s+[a-zéû]+\s+\d{4})\b/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) $dates[] = $norm;
        }
        if (!empty($dates)) {
            // On garde la plus récente (probablement la date d'émission)
            rsort($dates);
            $fields['date_doc'] = $dates[0];
            $valid['date_doc']  = rhDateIsFresh($dates[0], 90) ? 'ok' : 'too_old';
            $score += 15;
        }
    }

    /* ─── Fallback IA si score insuffisant ──────────────────────── */
    if ($score < 60 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $iaResult = rhJustifDomicileCallIa($text, $visionImages);
            if (!empty($iaResult)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';
                foreach (['nom_titulaire','adresse','code_postal','ville','date_doc','type_doc','emetteur'] as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        $val = $iaResult[$k];
                        if ($k === 'date_doc')     $val = rhNormalizeDate($val) ?? $val;
                        if ($k === 'nom_titulaire' || $k === 'ville') $val = rhNormalizeName($val);
                        $fields[$k] = $val;
                        $score += 10;
                    }
                }
                if (!empty($fields['date_doc'])) {
                    $valid['date_doc'] = rhDateIsFresh($fields['date_doc'], 90) ? 'ok' : 'too_old';
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/justif_domicile] IA fallback: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhJustifDomicileCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en documents administratifs français (factures EDF, ENGIE,
quittances de loyer, avis d'imposition, attestations CAF…).
Tu extrais les informations présentes et retournes UNIQUEMENT un objet JSON
plat avec ces clés (omets celles absentes) :

  - "nom_titulaire" : nom de la personne destinataire
  - "adresse"       : ligne d'adresse complète (ex "12 rue de la Paix")
  - "code_postal"   : 5 chiffres
  - "ville"
  - "date_doc"      : date d'émission du document, format YYYY-MM-DD
  - "type_doc"      : "facture" | "quittance" | "avis_imposition" | "attestation" | "releve_bancaire"
  - "emetteur"      : nom de l'organisme (EDF, Engie, bailleur, DGFiP, CAF…)

RÈGLES :
- N'INVENTE JAMAIS de valeur.
- Date au format YYYY-MM-DD.
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'un justificatif de domicile :\n\n{$text}\n\nRetourne le JSON."
        : "Image d'un justificatif de domicile. Retourne le JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
