<?php
/**
 * inc/rh_extractors/assurance_vehicule.php — Extraction attestation d'assurance auto.
 *
 * Point CRITIQUE : détection de la mention "usage professionnel".
 * Si absent, alerter le gestionnaire RH (le véhicule ne peut pas être utilisé
 * dans le cadre professionnel, donc IK impossible).
 *
 * Champs extraits :
 *   - assureur              : nom de la compagnie
 *   - numero_contrat        : police d'assurance
 *   - assure_principal      : titulaire du contrat
 *   - vehicule_immat        : immat. du véhicule assuré (pour cross-check carte grise)
 *   - date_debut_validite
 *   - date_fin_validite     : CRITIQUE (bloquant si expiré)
 *   - type_couverture       : tiers / tous risques / tiers+
 *   - usage_declare         : loisir / trajet-domicile-travail / affaires
 *   - usage_professionnel   : bool (CRITIQUE — prérequis IK)
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_assurance_vehicule(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    if (trim($text) !== '') {
        /* ─── Assureur (compagnie) ───────────────────────────── */
        // Liste d'assureurs connus — détection par mot-clé direct
        $knownInsurers = [
            'AXA','Allianz','Maif','Macif','Matmut','GMF','MMA','Groupama',
            'Pacifica','Aviva','Generali','Swiss Life','April','Assu 2000',
            'Direct Assurance','Euro Assurance','L\'olivier Assurance',
            'Pacifica','Natio Vie','BNP Paribas Cardif','Crédit Agricole',
        ];
        foreach ($knownInsurers as $ins) {
            if (stripos($text, $ins) !== false) {
                $fields['assureur'] = $ins;
                $score += 20;
                break;
            }
        }
        if (empty($fields['assureur']) && preg_match('/(?:Compagnie|Assureur|Soci[ée]t[ée]\s+d.assurance)[^\n:]*[:\s]\s*([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s&]{2,60})/u', $text, $m)) {
            $a = preg_split('/\s{2,}|\n/', trim($m[1]))[0] ?? $m[1];
            if (mb_strlen($a) >= 3) { $fields['assureur'] = rhNormalizeName($a); $score += 15; }
        }

        /* ─── Numéro de contrat / police ─────────────────────── */
        if (preg_match('/(?:N[°o]\s*(?:de\s+)?(?:contrat|police)|Police\s+n[°o])[^\n:]*[:\s]\s*([A-Z0-9\-\/]{6,25})/iu', $text, $m)) {
            $fields['numero_contrat'] = strtoupper(trim($m[1]));
            $score += 20;
        }

        /* ─── Assuré principal ───────────────────────────────── */
        if (preg_match('/(?:Assur[ée]|Souscripteur|Titulaire)[^\n:]*[:\s]\s*([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s]{3,60})/u', $text, $m)) {
            $a = preg_split('/\s{2,}|\n/', trim($m[1]))[0] ?? $m[1];
            if (mb_strlen($a) >= 3) {
                $fields['assure_principal'] = rhNormalizeName($a);
                $score += 10;
            }
        }

        /* ─── Immatriculation véhicule ───────────────────────── */
        if (preg_match('/\b([A-Z]{2}[\s\-]?\d{3}[\s\-]?[A-Z]{2})\b/', $text, $m)) {
            $plate = rhNormalizePlate($m[1]);
            if (rhValidatePlate($plate)) {
                $fields['vehicule_immat'] = $plate;
                $score += 15;
            }
        }

        /* ─── Dates validité ─────────────────────────────────── */
        if (preg_match('/(?:Du|Valable\s+du|P[ée]riode)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})\s*(?:au)\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $normDeb = rhNormalizeDate($m[1]);
            $normFin = rhNormalizeDate($m[2]);
            if ($normDeb) { $fields['date_debut_validite'] = $normDeb; $score += 5; }
            if ($normFin) {
                $fields['date_fin_validite'] = $normFin;
                // CRITIQUE : bloquant si expiré, alerte si < 30j
                if (!rhDateNotExpired($normFin)) {
                    $valid['date_fin_validite'] = 'expired_blocking';
                } else {
                    $exp = DateTime::createFromFormat('Y-m-d', $normFin);
                    $days = (int)(new DateTime('today'))->diff($exp)->days;
                    $valid['date_fin_validite'] = ($days <= 30) ? 'expires_soon_alert' : 'ok';
                }
                $score += 15;
            }
        }

        /* ─── Type de couverture ─────────────────────────────── */
        if (preg_match('/tous\s+risques/i', $text))     { $fields['type_couverture'] = 'tous_risques';     $score += 5; }
        elseif (preg_match('/tiers\s*[\+]|tiers\s+[ée]tendu/i', $text)) { $fields['type_couverture'] = 'tiers_plus'; $score += 5; }
        elseif (preg_match('/au\s+tiers/i', $text))     { $fields['type_couverture'] = 'tiers';            $score += 5; }

        /* ─── Usage déclaré (CRITIQUE pour IK) ───────────────── */
        if (preg_match('/usage\s+professionnel|d[ée]placements?\s+professionnels?|usage\s+affaires/i', $text)) {
            $fields['usage_professionnel'] = true;
            $fields['usage_declare']       = 'affaires';
            $valid['usage_professionnel']  = 'ok';
            $score += 25;
        } elseif (preg_match('/trajet.*domicile.*travail/i', $text)) {
            $fields['usage_declare']      = 'trajet_domicile_travail';
            $fields['usage_professionnel'] = false;
            $valid['usage_professionnel']  = 'warning_no_pro_use';
            $score += 10;
        } elseif (preg_match('/usage\s+(?:priv[ée]|loisir|personnel)/i', $text)) {
            $fields['usage_declare']      = 'prive';
            $fields['usage_professionnel'] = false;
            $valid['usage_professionnel']  = 'warning_no_pro_use';
            $score += 10;
        }
    }

    /* ─── Fallback IA ────────────────────────────────────────── */
    if ($score < 60 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $ia = rhAssuranceVehiculeCallIa($text, $visionImages);
            if (!empty($ia)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';
                $stringKeys = ['assureur','numero_contrat','assure_principal','type_couverture','usage_declare'];
                $dateKeys   = ['date_debut_validite','date_fin_validite'];
                foreach ($stringKeys as $k) {
                    if (!empty($ia[$k]) && empty($fields[$k])) {
                        $val = $ia[$k];
                        if (in_array($k, ['assureur','assure_principal'], true)) {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 7;
                    }
                }
                foreach ($dateKeys as $k) {
                    if (!empty($ia[$k]) && empty($fields[$k])) {
                        $norm = rhNormalizeDate($ia[$k]);
                        if ($norm) { $fields[$k] = $norm; $score += 7; }
                    }
                }
                if (!empty($ia['vehicule_immat']) && empty($fields['vehicule_immat'])) {
                    $plate = rhNormalizePlate($ia['vehicule_immat']);
                    if (rhValidatePlate($plate)) { $fields['vehicule_immat'] = $plate; $score += 10; }
                }
                if (isset($ia['usage_professionnel'])) {
                    $fields['usage_professionnel'] = (bool)$ia['usage_professionnel'];
                    $valid['usage_professionnel']  = $fields['usage_professionnel'] ? 'ok' : 'warning_no_pro_use';
                }
                // Re-valid date_fin_validite
                if (!empty($fields['date_fin_validite'])) {
                    if (!rhDateNotExpired($fields['date_fin_validite'])) {
                        $valid['date_fin_validite'] = 'expired_blocking';
                    } else {
                        $exp = DateTime::createFromFormat('Y-m-d', $fields['date_fin_validite']);
                        $days = (int)(new DateTime('today'))->diff($exp)->days;
                        $valid['date_fin_validite'] = ($days <= 30) ? 'expires_soon_alert' : 'ok';
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/assurance_vehicule] IA: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhAssuranceVehiculeCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en attestations d'assurance automobile françaises.
Tu extrais et retournes UNIQUEMENT un JSON plat avec ces clés (omets les absentes) :

  - "assureur"              : nom de la compagnie (ex: "AXA", "MAIF", "Allianz")
  - "numero_contrat"        : police d'assurance
  - "assure_principal"      : nom du titulaire du contrat
  - "vehicule_immat"        : immatriculation du véhicule (format AA-000-AA)
  - "date_debut_validite"   : YYYY-MM-DD
  - "date_fin_validite"     : YYYY-MM-DD
  - "type_couverture"       : "tiers" | "tiers_plus" | "tous_risques"
  - "usage_declare"         : "prive" | "trajet_domicile_travail" | "affaires"
  - "usage_professionnel"   : true si mention "usage professionnel" ou "déplacements
                              professionnels" ou "affaires", false sinon

RÈGLES CRITIQUES :
- N'INVENTE JAMAIS.
- N'extrais PAS de "assureur" sur un véhicule (ne mélange pas avec vehicule_nom).
- Dates au format YYYY-MM-DD.
- Immat au format SIV (AA-000-AA).
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'une attestation d'assurance auto :\n\n{$text}\n\nJSON."
        : "Image d'une attestation d'assurance auto. JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
