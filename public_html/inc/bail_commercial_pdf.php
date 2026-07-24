<?php
declare(strict_types=1);
/**
 * inc/bail_commercial_pdf.php — Génère le PDF du BAIL COMMERCIAL (trame FNAIM sous seing
 * privé + clauses greffées du bail notarié). Rendu mPDF, filigrane PROJET tant que non signé.
 *
 * ⚠️ Les informations de la société / agence gestionnaire sont TOUJOURS lues en base
 * (tables societes / agences), jamais codées en dur (règle projet).
 *
 * API publiques :
 *   bail_commercial_pdf_context(PDO $pdo, int $bailId): ?array   → contexte normalisé
 *   bail_commercial_articles_html(array $ctx): string            → corps HTML (articles + annexes)
 *   bail_commercial_build_pdf(PDO $pdo, int $bailId): string     → chemin du PDF temporaire
 */

if (!function_exists('bail_commercial_pdf_context')) {

    function bcp_e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    function bcp_eur($v): ?string {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        return number_format((float)$v, (float)$v == floor((float)$v) ? 0 : 2, ',', ' ') . ' €';
    }
    function bcp_date(?string $d): ?string {
        if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) return null;
        $t = strtotime($d); return $t ? date('d/m/Y', $t) : null;
    }

    /** Charge et normalise tout ce qu'il faut pour éditer le bail. */
    /**
     * Charge la LIGNE du bail enregistré (bien_baux + TOUTES les jointures : bien, immeuble,
     * propriétaire + sa fiche juridique, candidat + sa fiche juridique). C'est la BASE COMMUNE
     * au PDF et à l'aperçu live : en la partageant, « aperçu = PDF » est garanti par construction
     * (l'aperçu superpose seulement les modifications en cours du formulaire).
     */
    function bail_commercial_bail_row(PDO $pdo, int $bailId): ?array
    {
        $sql = "SELECT bb.*,
            b.reference_bien, b.designation, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
            b.code_postal AS bien_cp, b.surface_habitable, b.numero_lot AS bien_numero_lot_src, b.id_immeuble,
            b.description AS bien_description, b.etage AS bien_etage,
            b.bien_en_copropriete, b.lot_tantiemes AS bien_tantiemes_src, b.copro_nb_lots,
            b.id_societe AS bien_soc, b.id_agence AS bien_age,
            b.dpe_classe, b.ges_classe, b.dpe_valeur, b.ges_valeur, b.dpe_date_realisation,
            i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
            p.id AS proprio_id, p.id_tiers AS proprio_tiers_id,
            tp.infos_juridiques_json AS proprio_juridique_json,
            COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
            COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom,
            tc.infos_juridiques_json AS preneur_juridique_json,
            tc.raison_sociale AS preneur_tiers_raison,
            COALESCE(NULLIF(tc.nom_affichage,''), tc.raison_sociale, CONCAT_WS(' ', tc.prenom, tc.nom)) AS preneur_tiers_nom
            FROM bien_baux bb
            INNER JOIN biens b        ON b.id = bb.id_bien
            LEFT JOIN immeubles i     ON i.id = b.id_immeuble
            LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
            LEFT JOIN tiers tp        ON tp.id = p.id_tiers
            LEFT JOIN tiers tc        ON tc.id = bb.candidat_tiers_id
            WHERE bb.id = ? LIMIT 1";
        $st = $pdo->prepare($sql); $st->execute([$bailId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function bail_commercial_pdf_context(PDO $pdo, int $bailId): ?array
    {
        $bail = bail_commercial_bail_row($pdo, $bailId);
        if (!$bail) return null;
        return bail_commercial_ctx_build($pdo, $bail);
    }

    /**
     * Construit le contexte normalisé à partir d'une ligne bien_baux DÉJÀ JOINTE au bien
     * (mêmes alias que la requête ci-dessus). Réutilisé par l'aperçu live (données non
     * encore enregistrées) pour garantir un rendu IDENTIQUE au PDF.
     */
    function bail_commercial_ctx_build(PDO $pdo, array $bail): ?array
    {
        // Société gestionnaire (jamais en dur). Le bien peut avoir id_societe/id_agence NULL
        // → on retombe sur ceux stockés SUR LE BAIL (posés à la création) pour ne jamais laisser
        // le Bailleur sans société de gestion.
        $socId = (int)($bail['bien_soc'] ?? 0) ?: (int)($bail['id_societe'] ?? 0);
        $ageId = (int)($bail['bien_age'] ?? 0) ?: (int)($bail['id_agence'] ?? 0);
        $soc = [];
        if ($socId > 0) {
            $q = $pdo->prepare("SELECT raison_sociale,nom,forme_juridique,capital_social,siren,siret,adresse_1,code_postal,ville,carte_pro_numero,numero_carte_t,carte_pro_cci,cci_carte_t,assurance_rcp,garantie_financiere,rib_emetteur_iban,rib_emetteur_bic,rib_emetteur_nom FROM societes WHERE id=?");
            $q->execute([$socId]); $soc = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $age = [];
        if ($ageId > 0) {
            $q = $pdo->prepare("SELECT nom_agence,adresse_1,code_postal,ville,rcs,iban,bic,banque_nom FROM agences WHERE id=?");
            $q->execute([$ageId]); $age = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        // Compte bancaire de GESTION (baux + compta gestion) — jamais le compte société.
        require_once __DIR__ . '/comptes_bancaires.php';
        $ribG = cb_resolve($pdo, $socId, $ageId ?: null, 'gestion');

        $proprioNom = $bail['proprio_tiers_nom'] ?: $bail['proprio_nom_legacy'] ?: '';
        $loyerM = (float)($bail['loyer_mensuel_hc'] ?? 0);

        // Infos juridiques du BAILLEUR (annuaire Pappers, stockées sur le tiers du propriétaire).
        // Servent à REMPLIR le bloc bailleur du bail (forme, capital, siège, RCS, gérant) SANS
        // écraser une saisie manuelle (la saisie du représentant sur le bail prime — voir corps).
        // Parse les infos juridiques Pappers (JSON) d'un tiers en bloc normalisé. Utilisé pour le
        // BAILLEUR (fiche du propriétaire) ET le PRENEUR (fiche du candidat) : « le bail lit la fiche ».
        $parseLegal = function ($str): array {
            $jd = json_decode((string)$str, true);
            if (!is_array($jd) || !$jd) return [];
            $siege = $jd['siege'] ?? [];
            $siegeStr = is_array($siege)
                ? trim(implode(' ', array_filter([
                    $siege['adresse'] ?? ($siege['ligne'] ?? ($siege['adresse_ligne'] ?? '')),
                    $siege['code_postal'] ?? ($siege['cp'] ?? ''),
                    $siege['ville'] ?? '',
                  ])))
                : trim((string)$siege);
            // Représentant par défaut = 1er dirigeant gérant/président ; sinon le 1er listé.
            $repDef = '';
            foreach (($jd['dirigeants'] ?? []) as $dg) {
                $ql = mb_strtolower((string)($dg['qualite'] ?? ''), 'UTF-8');
                if (preg_match('/g[eé]rant|pr[eé]sident|dirigeant/u', $ql)) { $repDef = trim((string)($dg['nom'] ?? '')); break; }
            }
            if ($repDef === '' && !empty($jd['dirigeants'][0]['nom'])) $repDef = trim((string)$jd['dirigeants'][0]['nom']);
            return [
                'raison'  => (string)($jd['raison_sociale'] ?? ''),
                'forme'   => (string)($jd['forme_juridique'] ?? ''),
                'capital' => $jd['capital'] ?? null,
                'siege'   => $siegeStr,
                'siren'   => (string)($jd['siren'] ?? ''),
                'rep'     => $repDef,
            ];
        };
        $bLegal = $parseLegal($bail['proprio_juridique_json'] ?? '');
        $pLegal = $parseLegal($bail['preneur_juridique_json'] ?? '');

        return [
            'statut'       => (string)$bail['statut'],
            'numero_bail'  => (string)($bail['numero_bail'] ?? ''),
            'proprio_nom'  => (string)$proprioNom,
            'bailleur_rep' => trim((string)($bail['bailleur_representant_nom'] ?? '') . (($bail['bailleur_representant_qualite'] ?? '') ? ' (' . $bail['bailleur_representant_qualite'] . ')' : '')),
            'bailleur_legal' => $bLegal,
            'bien_ref'     => (string)($bail['reference_bien'] ?: $bail['designation'] ?: ('Bien #' . $bail['id_bien'])),
            'bien_adresse' => trim((string)($bail['bien_adresse'] ?? '') . ' ' . ($bail['bien_cp'] ?? '') . ' ' . ($bail['bien_ville'] ?? '')),
            'immeuble'     => (string)($bail['nom_immeuble'] ?: $bail['imm_adresse'] ?: ''),
            // Lot / tantièmes : priorité aux valeurs CAPTÉES SUR LE BAIL (instantané), sinon celles du bien.
            'numero_lot'   => (string)(($bail['lot_copropriete'] ?? '') ?: ($bail['bien_numero_lot_src'] ?? '')),
            'surface'      => (float)($bail['surface_habitable'] ?? 0),
            // Désignation du bien : celle saisie sur le bail prime, sinon description du bien.
            'bien_description' => (string)(($bail['bien_designation'] ?? '') ?: ($bail['bien_description'] ?? '')),
            'bien_etage'   => (($bail['bien_etage'] ?? null) !== null && $bail['bien_etage'] !== '' ? ((int)$bail['bien_etage'] === 0 ? 'rez-de-chaussée' : (int)$bail['bien_etage'] . 'ᵉ étage') : ''),
            'bien_copro'   => (function() use ($bail) {
                $lot  = (string)(($bail['lot_copropriete'] ?? '') ?: ($bail['bien_numero_lot_src'] ?? ''));
                $tant = (string)(($bail['lot_tantiemes'] ?? '') ?: ($bail['bien_tantiemes_src'] ?? ''));
                $on   = !empty($bail['en_copropriete']) || !empty($bail['bien_en_copropriete']) || $lot !== '' || $tant !== '';
                if (!$on) return '';
                return 'bien en copropriété' . ($tant !== '' ? ' (' . $tant . ' tantièmes)' : '');
            })(),
            'cond_particulieres' => (string)($bail['conditions_particulieres'] ?? ''),
            'cond_loyer'   => (string)($bail['conditions_particulieres_loyer'] ?? ''),
            'travaux_realises' => (string)($bail['travaux_realises_3ans'] ?? ''),
            'travaux_prevus'   => (string)($bail['travaux_prevus_3ans'] ?? ''),
            // PRENEUR : les champs SAISIS sur le bail priment ; s'ils sont vides, on REPREND la fiche
            // du candidat (tiers) — infos juridiques Pappers pour une société, nom pour un particulier.
            'preneur' => [
                'type'      => ($bail['locataire_type'] ?? 'societe') === 'physique' ? 'physique' : 'societe',
                'raison'    => (string)(($bail['locataire_raison_sociale'] ?? '') ?: ($pLegal['raison'] ?? '') ?: ($bail['preneur_tiers_raison'] ?? '')),
                'siren'     => (string)(($bail['locataire_siren'] ?? '') ?: ($pLegal['siren'] ?? '')),
                'nom'       => (trim((string)($bail['locataire_prenom'] ?? '') . ' ' . ($bail['locataire_nom'] ?? '')) ?: (string)($bail['preneur_tiers_nom'] ?? '')),
                'rep'       => (string)(($bail['locataire_representant_nom'] ?? '') ?: ($pLegal['rep'] ?? '')),
                'rep_q'     => (string)($bail['locataire_representant_qualite'] ?? ''),
                'email'     => (string)($bail['locataire_email'] ?? ''),
                'tel'       => (string)($bail['locataire_telephone'] ?? ''),
                'adresse'   => (string)(($bail['locataire_adresse'] ?? '') ?: ($pLegal['siege'] ?? '')),
                'naiss_d'   => bcp_date($bail['locataire_date_naissance'] ?? null),
                'naiss_l'   => (string)($bail['locataire_lieu_naissance'] ?? ''),
                'nat'       => (string)($bail['locataire_nationalite'] ?? ''),
            ],
            'garant' => [
                'present'   => !empty($bail['garant_present']),
                'type'      => ($bail['garant_type'] ?? 'physique') === 'societe' ? 'societe' : 'physique',
                'raison'    => (string)($bail['garant_raison_sociale'] ?? ''),
                'siren'     => (string)($bail['garant_siren'] ?? ''),
                'nom'       => trim((string)($bail['garant_prenom'] ?? '') . ' ' . ($bail['garant_nom'] ?? '')),
                'adresse'   => (string)($bail['garant_adresse'] ?? ''),
                'naiss_d'   => bcp_date($bail['garant_date_naissance'] ?? null),
                'naiss_l'   => (string)($bail['garant_lieu_naissance'] ?? ''),
                'montant'   => ($bail['garant_montant_max'] ?? null) !== null ? (float)$bail['garant_montant_max'] : null,
                'duree'     => (int)($bail['garant_duree_ans'] ?? 0),
                'solidaire' => !array_key_exists('garant_solidaire',$bail) || !empty($bail['garant_solidaire']),
            ],
            'gestionnaire' => [
                'raison'    => (string)(($soc['raison_sociale'] ?? '') ?: ($soc['nom'] ?? '')),
                'forme'     => (string)($soc['forme_juridique'] ?? ''),
                'capital'   => $soc['capital_social'] ?? null,
                'siren'     => (string)(($soc['siren'] ?? '') ?: ($soc['siret'] ?? '')),
                'adresse'   => trim((string)($soc['adresse_1'] ?? '') . ' ' . ($soc['code_postal'] ?? '') . ' ' . ($soc['ville'] ?? '')),
                'carte'     => (string)(($soc['carte_pro_numero'] ?? '') ?: ($soc['numero_carte_t'] ?? '')),
                'carte_cci' => (string)(($soc['carte_pro_cci'] ?? '') ?: ($soc['cci_carte_t'] ?? '')),
                'rcp'       => (string)($soc['assurance_rcp'] ?? ''),
                'garantie'  => (string)($soc['garantie_financiere'] ?? ''),
                'age_nom'   => (string)($age['nom_agence'] ?? ''),
                'age_adr'   => trim((string)($age['adresse_1'] ?? '') . ' ' . ($age['code_postal'] ?? '') . ' ' . ($age['ville'] ?? '')),
                // Compte de GESTION de l'agence UNIQUEMENT (jamais le compte société sur un doc de gestion).
                // Si non configuré → vide (le texte affiche « compte de gestion de l'agence » sans IBAN erroné).
                'rib_iban'  => (string)$ribG['iban'],
                'rib_nom'   => (string)($ribG['banque'] ?: $ribG['titulaire']),
                'rib_bic'   => (string)$ribG['bic'],
                'ville_sig' => (string)($age['ville'] ?? ($soc['ville'] ?? '')),
            ],
            'cond' => [
                'destination'   => (string)($bail['destination_activite'] ?? ''),
                'date_effet'    => (string)($bail['date_prise_effet'] ?? ''),
                'duree_mois'    => (int)($bail['duree_mois'] ?? 108),
                'ferme_ans'     => (int)($bail['duree_ferme_ans'] ?? 0),
                'loyer_m'       => $loyerM,
                'loyer_a'       => $loyerM * 12,
                'charges_m'     => ($bail['charges_mensuelles'] ?? null) !== null ? (float)$bail['charges_mensuelles'] : null,
                'indice'        => (string)($bail['indice_type'] ?? 'ILC'),
                'indice_trim'   => (string)($bail['indice_trimestre'] ?? ''),
                'indice_val'    => (string)($bail['indice_valeur'] ?? ''),
                'dg_mois'       => ($bail['nb_termes_garantie'] ?? null) !== null ? (int)$bail['nb_termes_garantie'] : null,
                'dg_montant'    => ($bail['depot_garantie'] ?? null) !== null ? (float)$bail['depot_garantie'] : null,
                'erp'           => !empty($bail['erp_local']),
                'opt'           => !empty($bail['option_achat']),
                'opt_prix'      => ($bail['option_achat_prix'] ?? null) !== null ? (float)$bail['option_achat_prix'] : null,
                'opt_delai'     => ($bail['option_achat_delai_mois'] ?? null) !== null ? (int)$bail['option_achat_delai_mois'] : null,
                'tva_app'       => !array_key_exists('tva_applicable',$bail) || !empty($bail['tva_applicable']),
                'tva_taux'      => ($bail['tva_taux'] ?? null) !== null ? (float)$bail['tva_taux'] : 20.0,
                'perio'         => ($bail['periodicite_paiement'] ?? 'mensuelle') === 'trimestrielle' ? 'trimestrielle' : 'mensuelle',
                'prov_tf'       => ($bail['provision_tf_mensuelle'] ?? null) !== null ? (float)$bail['provision_tf_mensuelle'] : null,
                'tech_pct'      => ($bail['honoraires_gestion_tech_pct'] ?? null) !== null && $bail['honoraires_gestion_tech_pct'] !== '' ? (float)$bail['honoraires_gestion_tech_pct'] : null,
                'hono_loc'      => ($bail['honoraires_locataire_ttc'] ?? null) !== null ? (float)$bail['honoraires_locataire_ttc'] : null,
                'hono_bail'     => ($bail['honoraires_bailleur_ttc'] ?? null) !== null ? (float)$bail['honoraires_bailleur_ttc'] : null,
                'hono_charge'   => (string)($bail['honoraires_charge'] ?? 'locataire'),
                'date_effet_raw'=> (string)($bail['date_prise_effet'] ?? ''),
                'prorata_date'  => (string)($bail['prorata_date_debut'] ?? ''),
                // Champs modèle FNAIM (migration 20260724c)
                'taux_penalite' => ($bail['taux_penalite'] ?? null) !== null && $bail['taux_penalite'] !== '' ? (float)$bail['taux_penalite'] : 10.0,
                'droit_entree'  => ($bail['droit_entree'] ?? null) !== null && $bail['droit_entree'] !== '' ? (float)$bail['droit_entree'] : null,
                'hono_pct_pren' => ($bail['honoraires_pct_preneur'] ?? null) !== null && $bail['honoraires_pct_preneur'] !== '' ? (float)$bail['honoraires_pct_preneur'] : null,
                'hono_pct_bail' => ($bail['honoraires_pct_bailleur'] ?? null) !== null && $bail['honoraires_pct_bailleur'] !== '' ? (float)$bail['honoraires_pct_bailleur'] : null,
            ],
            'dpe' => [
                'classe' => (string)($bail['dpe_classe'] ?? ''),
                'ges'    => (string)($bail['ges_classe'] ?? ''),
                'val'    => (string)($bail['dpe_valeur'] ?? ''),
                'ges_val'=> (string)($bail['ges_valeur'] ?? ''),
                'date'   => bcp_date($bail['dpe_date_realisation'] ?? null),
            ],
        ];
    }

    /** Corps HTML complet (articles détaillés + annexes + signatures) pour mPDF. */
    function bail_commercial_articles_html(array $ctx): string
    {
        $ge = $ctx['gestionnaire']; $pr = $ctx['preneur']; $c = $ctx['cond'];
        // Placeholder en TEXTE SIMPLE (pas de HTML) : le résultat passe par bcp_e() partout,
        // qui échapperait des balises → on ne met qu'une ligne de pointillés à compléter.
        $mut = fn($v) => $v !== null && $v !== '' ? $v : '……………………';

        // Preneur (bloc identité complet)
        if ($pr['type'] === 'societe') {
            $preneur = '<b>' . bcp_e($mut($pr['raison'])) . '</b>'
                . ($pr['siren'] ? ', immatriculée sous le numéro ' . bcp_e($pr['siren']) : '')
                . ($pr['adresse'] ? ', dont le siège est ' . bcp_e($pr['adresse']) : '')
                . ($pr['rep'] ? ', représentée par ' . bcp_e($pr['rep']) . ($pr['rep_q'] ? ' en qualité de ' . bcp_e($pr['rep_q']) : '') : '');
        } else {
            $preneur = '<b>' . bcp_e($mut(trim($pr['nom']))) . '</b>'
                . (($pr['naiss_d'] || $pr['naiss_l']) ? ', né(e) le ' . bcp_e($pr['naiss_d'] ?: '……') . ($pr['naiss_l'] ? ' à ' . bcp_e($pr['naiss_l']) : '') : '')
                . ($pr['nat'] ? ', de nationalité ' . bcp_e($pr['nat']) : '')
                . ($pr['adresse'] ? ', demeurant ' . bcp_e($pr['adresse']) : '');
        }
        $coord = [];
        if ($pr['email']) $coord[] = bcp_e($pr['email']);
        if ($pr['tel'])   $coord[] = bcp_e($pr['tel']);
        if ($coord) $preneur .= ' (' . implode(' · ', $coord) . ')';

        // Bailleur (propriétaire + gestionnaire mandaté, infos BDD)
        $bailleur = '<b>' . bcp_e($mut($ctx['proprio_nom'])) . '</b>, propriétaire'
            . ($ctx['bailleur_rep'] ? ', représenté par ' . bcp_e($ctx['bailleur_rep']) : '')
            . ', représenté pour la gestion et l\'encaissement des loyers par la société <b>' . bcp_e($mut($ge['raison'])) . '</b>'
            . ($ge['forme'] ? ', ' . bcp_e($ge['forme']) . ($ge['capital'] ? ' au capital de ' . bcp_e((string)bcp_eur($ge['capital'])) : '') : '')
            . ($ge['siren'] ? ', immatriculée sous le n° ' . bcp_e($ge['siren']) : '')
            . ($ge['adresse'] ? ', dont le siège est situé ' . bcp_e($ge['adresse']) : '')
            . ($ge['carte'] ? ', titulaire de la carte professionnelle « Gestion immobilière » n° ' . bcp_e($ge['carte']) . ($ge['carte_cci'] ? ' délivrée par ' . bcp_e($ge['carte_cci']) : '') : '')
            . ($ge['rcp'] ? ', assurance de responsabilité civile professionnelle ' . bcp_e($ge['rcp']) : '')
            . ($ge['garantie'] ? ', garantie financière ' . bcp_e($ge['garantie']) : '')
            . ($ge['age_nom'] ? ', par l\'intermédiaire de son agence ' . bcp_e($ge['age_nom']) . ($ge['age_adr'] ? ' — ' . bcp_e($ge['age_adr']) : '') : '')
            . '.';

        // Conditions dérivées
        $dureeTxt = $c['duree_mois'] === 36
            ? 'trois (3) années (bail dérogatoire, article L.145-5 du Code de commerce)'
            : 'neuf (9) années entières et consécutives';
        $loyerA = bcp_eur($c['loyer_a']);
        $loyerM = bcp_eur($c['loyer_m']);
        $charges = $c['charges_m'] !== null ? bcp_eur($c['charges_m']) : null;
        $dg = $c['dg_montant'] !== null ? bcp_eur($c['dg_montant']) : null;
        $indiceBase = ($c['indice_trim'] ?: '……') . ($c['indice_val'] ? ' (valeur ' . bcp_e($c['indice_val']) . ')' : '');

        // Argent : TVA, périodicité, gestion technique, prorata d'entrée
        $tvaOn   = (bool)$c['tva_app']; $tvaTaux = (float)$c['tva_taux'] ?: 20.0;
        $perioTxt = $c['perio'] === 'trimestrielle' ? 'par trimestre d\'avance' : 'par mois d\'avance';
        $mult    = $c['perio'] === 'trimestrielle' ? 3 : 1;
        $loyerMn = (float)$c['loyer_m'];
        $chargesMn = (float)($c['charges_m'] ?? 0);
        $tfMn    = (float)($c['prov_tf'] ?? 0);
        $techPct = $c['tech_pct'] !== null ? (float)$c['tech_pct'] : 0.0;
        $techMn  = $techPct > 0 ? $loyerMn * $techPct / 100 : 0.0;
        $honoTtc = $c['hono_loc'] !== null ? (float)$c['hono_loc'] : null; // part locataire
        $ttc = fn($x) => $tvaOn ? $x * (1 + $tvaTaux / 100) : $x;
        // prorata 1er terme selon la date d'effet
        $ratio = 1.0; $prLabel = '';
        // Point de départ du prorata : date de prorata saisie, sinon date de prise d'effet.
        $prorataRaw = ($c['prorata_date'] ?? '') ?: $c['date_effet_raw'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $prorataRaw)) {
            $ts = strtotime($prorataRaw); $y=(int)date('Y',$ts); $m=(int)date('n',$ts); $d=(int)date('j',$ts);
            if ($c['perio'] === 'trimestrielle') {
                $qs = intdiv($m-1,3)*3+1; $start=mktime(0,0,0,$qs,1,$y); $end=mktime(0,0,0,$qs+3,0,$y);
                $tot=(int)round(($end-$start)/86400)+1; $rem=(int)round(($end-$ts)/86400)+1; $ratio=$tot>0?$rem/$tot:1; $prLabel='trimestre';
            } else {
                $dim=(int)date('t',$ts); $ratio=($dim-$d+1)/$dim; $prLabel='mois';
            }
        }
        // Franchise : le loyer démarre APRÈS la prise d'effet → rien à verser au titre du loyer
        // à la signature (loyer/charges/TF/gestion appelés à compter de la date de loyer).
        $franchise = false;
        if (($c['prorata_date'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$c['prorata_date'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$c['date_effet_raw'])) {
            $franchise = strtotime($c['prorata_date']) > strtotime($c['date_effet_raw']);
        }
        $decompte = [];
        if (!$franchise) {
            if ($loyerMn)  $decompte[] = ['1ᵉʳ loyer' . ($prLabel?' (prorata '.$prLabel.')':'') . ($tvaOn?' TTC':' HT'), $ttc($loyerMn*$mult*$ratio)];
            if ($chargesMn)$decompte[] = ['Provision charges courantes' . ($prLabel?' (prorata)':'') . ($tvaOn?' TTC':''), $ttc($chargesMn*$mult*$ratio)];
            if ($tfMn)     $decompte[] = ['Provision taxe foncière' . ($prLabel?' (prorata)':'') . ($tvaOn?' TTC':''), $ttc($tfMn*$mult*$ratio)];
            if ($techMn)   $decompte[] = ['Honoraires gestion technique ' . rtrim(rtrim(number_format($techPct,2,',',''),'0'),',') . ' %' . ($prLabel?' (prorata)':'') . ($tvaOn?' TTC':''), $ttc($techMn*$mult*$ratio)];
        }
        if ($honoTtc)  $decompte[] = ['Honoraires agence TTC', $honoTtc];
        if ($c['dg_montant']) $decompte[] = ['Dépôt de garantie', (float)$c['dg_montant']];
        $totalVerser = array_sum(array_map(fn($l)=>$l[1], $decompte));

        // ── Génération numérotée ──
        $n = 0;
        $art = function (string $titre, string $corps) use (&$n): string {
            $n++;
            return '<h2>Article ' . $n . ' &ndash; ' . $titre . '</h2><p>' . $corps . '</p>';
        };

        $h  = '<div class="doc">';
        $h .= '<h1>BAIL COMMERCIAL</h1>';
        $h .= '<p class="sub">Soumis au statut des baux commerciaux &mdash; articles L.145-1 et suivants du Code de commerce</p>';
        if ($ctx['numero_bail']) $h .= '<p class="ref">Référence : ' . bcp_e($ctx['numero_bail']) . '</p>';

        $h .= '<h2>Entre les soussignés</h2>';
        $h .= '<p>' . $bailleur . '<br><span class="qual">Ci-après dénommé « le Bailleur », d\'une part,</span></p>';
        $h .= '<p>ET&nbsp;: ' . $preneur . '<br><span class="qual">Ci-après dénommé « le Preneur », d\'autre part.</span></p>';
        $h .= '<p>Lesquels, préalablement au bail objet des présentes, ont exposé puis arrêté ce qui suit.</p>';

        $h .= $art('Désignation des locaux',
            'Le Bailleur donne à bail au Preneur, qui accepte, des locaux à usage commercial ci-après désignés : <b>' . bcp_e($mut($ctx['bien_ref'])) . '</b>'
            . ($ctx['bien_adresse'] ? ', sis ' . bcp_e($ctx['bien_adresse']) : '')
            . ($ctx['immeuble'] ? ', dépendant de l\'immeuble ' . bcp_e($ctx['immeuble']) : '')
            . ($ctx['numero_lot'] ? ', lot n° ' . bcp_e($ctx['numero_lot']) : '')
            . ($ctx['bien_etage'] ? ', ' . bcp_e($ctx['bien_etage']) : '')
            . ($ctx['surface'] > 0 ? ', d\'une superficie d\'environ ' . bcp_e(number_format($ctx['surface'], 0, ',', ' ')) . ' m²' : '')
            . ($ctx['bien_copro'] ? ', ' . bcp_e($ctx['bien_copro']) : '')
            . ', avec ses dépendances, aménagements, installations et équipements existants.'
            . ($ctx['bien_description'] ? ' <b>Description :</b> ' . bcp_e($ctx['bien_description']) . '.' : '')
            . ' Le Preneur déclare parfaitement connaître les lieux pour les avoir visités, les prend dans l\'état où ils se trouvent au jour de l\'entrée en jouissance tel que constaté à l\'état des lieux d\'entrée, et renonce à tout recours contre le Bailleur pour vices apparents ou cachés, défaut d\'entretien antérieur ou insuffisance de superficie, laquelle, même supérieure à un vingtième, ne donnera lieu à aucune réduction de loyer.');

        $h .= $art('Destination des lieux',
            'Les locaux sont exclusivement destinés à l\'exercice de l\'activité suivante : <b>' . bcp_e($mut($c['destination'])) . '</b>, à l\'exclusion de toute autre. '
            . 'Toute adjonction, modification ou extension d\'activité (déspécialisation partielle ou plénière) devra être préalablement autorisée dans les conditions des articles L.145-47 à L.145-55 du Code de commerce. '
            . 'Le Preneur fera son affaire personnelle de toutes autorisations administratives nécessaires à son activité, le Bailleur ne garantissant pas la possibilité effective de l\'exercer. '
            . 'Aucune enseigne, store, publicité ou dispositif en façade ne pourra être apposé sans l\'accord écrit préalable du Bailleur et le respect des règlements d\'urbanisme et de copropriété.');

        $h .= $art('Durée',
            'Le présent bail est consenti et accepté pour une durée de <b>' . $dureeTxt . '</b>, prenant effet le <b>' . bcp_e($mut(bcp_date($c['date_effet']))) . '</b>. '
            . ($c['duree_mois'] === 36
                ? 'S\'agissant d\'un bail dérogatoire, il ne sera pas soumis au statut des baux commerciaux, sous réserve des dispositions de l\'article L.145-5 du Code de commerce.'
                : 'Le Preneur aura la faculté de résilier à l\'expiration de chaque période triennale, moyennant un préavis de six (6) mois donné par acte extrajudiciaire ou par lettre recommandée avec demande d\'avis de réception (article L.145-4 du Code de commerce). À défaut de congé délivré dans les formes et délais, le bail se poursuivra par tacite prolongation dans les conditions de l\'article L.145-9.')
            . ($c['ferme_ans']
                ? ' <b>Par dérogation expresse, le Preneur renonce à la faculté de résiliation à l\'issue de la première période triennale</b> : les ' . ($c['ferme_ans'] === 6 ? 'six (6)' : bcp_e((string)$c['ferme_ans'])) . ' premières années sont fermes.'
                : ''));

        $h .= $art('Entrée en jouissance',
            'Le Preneur entrera en jouissance des locaux le jour de la prise d\'effet du bail, par la remise des clés, contre établissement contradictoire de l\'état des lieux d\'entrée.');

        $h .= $art('Loyer',
            'Le présent bail est consenti moyennant un loyer annuel de <b>' . bcp_e($mut($loyerA)) . ' hors taxes et hors charges</b>'
            . ($loyerM ? ', soit ' . bcp_e($loyerM) . ' par mois' : '') . '. '
            . 'Il est payable <b>' . $perioTxt . '</b>, au domicile du Bailleur ou de son mandataire. '
            . (($c['prorata_date'] ?? '') && bcp_date($c['prorata_date']) ? 'Le loyer est décompté à compter du <b>' . bcp_e((string)bcp_date($c['prorata_date'])) . '</b> (point de départ du calcul au prorata). ' : '')
            . ($tvaOn ? 'Le loyer est majoré de la taxe sur la valeur ajoutée au taux de ' . rtrim(rtrim(number_format($tvaTaux,2,',',''),'0'),',') . ' %. ' : 'Le loyer n\'est pas assujetti à la TVA (article 261 D du CGI). ')
            . (($ctx['cond_loyer'] ?? '') ? '<b>Conditions particulières sur le loyer :</b> ' . bcp_e($ctx['cond_loyer']) . '. ' : '')
            . ($ge['rib_iban'] ? 'Les loyers et accessoires sont réglés par virement sur le compte ' . bcp_e($ge['rib_nom'] ?: 'du mandataire') . ' — IBAN ' . bcp_e($ge['rib_iban']) . '. ' : '')
            . 'Tout paiement s\'imputera par priorité sur les créances les plus anciennes. Tout retard portera intérêt au taux légal majoré, sans préjudice de la clause résolutoire.');

        $h .= $art('Révision et indexation du loyer',
            'Le loyer sera révisé automatiquement chaque année, à la date anniversaire de la prise d\'effet, en fonction de la variation de l\'indice <b>' . bcp_e($c['indice']) . '</b> publié par l\'INSEE. '
            . 'Indice de base : <b>' . $indiceBase . '</b>. '
            . 'La révision jouera de plein droit, à la hausse comme à la baisse, sans formalité ni mise en demeure, dans le respect du plafonnement de l\'article L.145-38 du Code de commerce. '
            . 'En cas de disparition de l\'indice, il sera remplacé par l\'indice de raccordement publié à cet effet ou, à défaut, par un indice équivalent fixé d\'accord entre les parties ou par le juge.');

        $h .= $art('Charges, impôts, taxes et redevances',
            'La répartition des charges, impôts, taxes et redevances entre le Bailleur et le Preneur, ainsi que leurs modalités de calcul, figure à <b>l\'Annexe 1 &laquo; Inventaire et répartition des charges &raquo;</b>, établie conformément à l\'article L.145-40-2 du Code de commerce. '
            . 'Le Preneur remboursera au Bailleur les charges et taxes mises à sa charge'
            . ($charges ? ', par provisions mensuelles de <b>' . bcp_e($charges) . '</b> régularisées annuellement sur justificatifs' : ' sur justificatifs, avec régularisation annuelle')
            . '. '
            . ($tfMn ? 'Le Preneur remboursera en outre la taxe foncière, par provision de ' . bcp_e((string)bcp_eur($tfMn)) . ' par mois. ' : '')
            . ($techMn ? 'Sont également récupérés sur le Preneur les <b>honoraires de gestion technique à hauteur de ' . rtrim(rtrim(number_format($techPct,2,',',''),'0'),',') . ' % du loyer</b> (' . bcp_e((string)bcp_eur($techMn)) . ' par mois). ' : '')
            . 'Le Bailleur adressera chaque année au Preneur un état récapitulatif des charges ainsi que, tous les trois ans, l\'état prévisionnel et le récapitulatif des travaux (Annexe 2).');

        $h .= $art('Dépôt de garantie',
            'À titre de garantie de la bonne exécution du bail, le Preneur verse au Bailleur, à la signature des présentes, un dépôt de garantie de <b>' . bcp_e($mut($dg ? ($dg . ($c['dg_mois'] ? ' (' . $c['dg_mois'] . ' mois de loyer)' : '')) : null)) . '</b>. '
            . 'Cette somme, non productive d\'intérêts, sera restituée en fin de bail après restitution des locaux et apurement des comptes, déduction faite des sommes dont le Preneur pourrait être redevable. Elle sera réajustée à chaque révision pour demeurer équivalente au nombre de termes convenu.');

        $hoB = (float)($c['hono_bail'] ?? 0); $hoL = (float)($c['hono_loc'] ?? 0);
        if ($hoB > 0 || $hoL > 0) {
            $hp = [];
            if ($hoB > 0) $hp[] = '<b>' . bcp_e((string)bcp_eur($hoB)) . ' TTC à la charge du Bailleur</b>';
            if ($hoL > 0) $hp[] = '<b>' . bcp_e((string)bcp_eur($hoL)) . ' TTC à la charge du Preneur</b>';
            $h .= $art('Honoraires de négociation et de rédaction',
                'Les honoraires de négociation et de rédaction du présent bail s\'élèvent à ' . implode(' et ', $hp) . '. '
                . 'En matière de bail commercial, ces honoraires sont librement convenus entre les parties.');
        }

        // ── Décompte à verser à la signature ──
        if ($decompte) {
            $rowsD = '';
            foreach ($decompte as $l) $rowsD .= '<tr><td>' . bcp_e($l[0]) . '</td><td style="text-align:right">' . bcp_e((string)bcp_eur($l[1])) . '</td></tr>';
            $rowsD .= '<tr><td><b>Total à verser à la signature</b></td><td style="text-align:right"><b>' . bcp_e((string)bcp_eur($totalVerser)) . '</b></td></tr>';
            $h .= '<h2>Décompte des sommes à verser à la signature</h2>';
            $h .= '<table class="tbl"><tbody>' . $rowsD . '</tbody></table>';
            $h .= '<p class="mut">Règlement par virement' . ($ge['rib_iban'] ? ' — ' . bcp_e($ge['rib_nom'] ?: 'compte de l\'agence') . ', IBAN ' . bcp_e($ge['rib_iban']) : ' sur le compte de l\'agence') . '.</p>';
        }
        if ($franchise) {
            $h .= '<p class="mut"><b>Franchise de loyer :</b> le loyer est gratuit depuis la prise d\'effet jusqu\'au ' . bcp_e((string)(bcp_date($c['prorata_date']) ?: '')) . ' ; aucun loyer, charge ou provision n\'est dû au titre de cette période. Le loyer est appelé à compter de cette date selon l\'échéance ci-dessous.</p>';
        }

        // ── Échéance périodique : appel de loyer complet (loyer + charges + provisions + TVA) ──
        $baseHT   = ($loyerMn + $chargesMn + $techMn) * $mult;   // assiette assujettie à la TVA
        $tfPeriod = $tfMn * $mult;                               // provision TF (hors TVA)
        $tvaMt    = $tvaOn ? $baseHT * $tvaTaux / 100 : 0.0;
        $totalEch = $baseHT + $tvaMt + $tfPeriod;
        if ($totalEch > 0) {
            $per = $c['perio'] === 'trimestrielle' ? 'trimestrielle' : 'mensuelle';
            $rowsE = '';
            $rowsE .= '<tr><td>Loyer hors charges</td><td style="text-align:right">' . bcp_e((string)bcp_eur($loyerMn * $mult)) . '</td></tr>';
            if ($chargesMn) $rowsE .= '<tr><td>Provision pour charges</td><td style="text-align:right">' . bcp_e((string)bcp_eur($chargesMn * $mult)) . '</td></tr>';
            if ($techMn)    $rowsE .= '<tr><td>Honoraires de gestion technique (' . rtrim(rtrim(number_format($techPct,2,',',''),'0'),',') . ' %)</td><td style="text-align:right">' . bcp_e((string)bcp_eur($techMn * $mult)) . '</td></tr>';
            $rowsE .= '<tr><td><b>Sous-total hors taxes</b></td><td style="text-align:right"><b>' . bcp_e((string)bcp_eur($baseHT)) . '</b></td></tr>';
            if ($tvaOn) $rowsE .= '<tr><td>TVA ' . rtrim(rtrim(number_format($tvaTaux,2,',',''),'0'),',') . ' %</td><td style="text-align:right">' . bcp_e((string)bcp_eur($tvaMt)) . '</td></tr>';
            if ($tfPeriod) $rowsE .= '<tr><td>Provision taxe foncière' . ($tvaOn ? ' (non soumise à TVA)' : '') . '</td><td style="text-align:right">' . bcp_e((string)bcp_eur($tfPeriod)) . '</td></tr>';
            $rowsE .= '<tr><td><b>Total de l\'échéance ' . $per . ($tvaOn ? ' TTC' : '') . '</b></td><td style="text-align:right"><b>' . bcp_e((string)bcp_eur($totalEch)) . '</b></td></tr>';
            $h .= '<h2>Échéance périodique &ndash; appel de loyer</h2>';
            $h .= '<p>Le Preneur réglera, ' . $perioTxt . ', l\'échéance suivante regroupant le loyer, les provisions et, le cas échéant, la taxe sur la valeur ajoutée&nbsp;:</p>';
            $h .= '<table class="tbl"><tbody>' . $rowsE . '</tbody></table>';
            $h .= '<p class="mut">Les provisions de charges et de taxe foncière sont régularisées annuellement sur justificatifs. Le loyer est révisé selon l\'indice ci-dessus.</p>';
        }

        $h .= $art('Entretien et réparations',
            'Le Preneur maintiendra les locaux en parfait état de réparations locatives et d\'entretien pendant toute la durée du bail et les rendra en fin de jouissance en bon état. '
            . 'Il supportera l\'entretien courant et l\'ensemble des réparations autres que celles de l\'article 606 du Code civil. '
            . '<b>Demeurent à la charge du Bailleur les grosses réparations énumérées à l\'article 606 du Code civil</b> (gros murs, voûtes, poutres, couvertures, murs de soutènement), ainsi que les travaux de mise en conformité et de vétusté relevant desdites grosses réparations. '
            . 'Le Preneur ne pourra effectuer aucune démolition, percement ou transformation sans l\'accord écrit et préalable du Bailleur ; les embellissements, améliorations et installations resteront, en fin de bail, la propriété du Bailleur sans indemnité, sauf faculté pour ce dernier d\'en exiger la suppression aux frais du Preneur.');

        $h .= $art('Jouissance et obligations du Preneur',
            'Le Preneur jouira des lieux raisonnablement, les occupera et exploitera personnellement, effectivement et sans interruption. '
            . 'Il s\'oblige à&nbsp;: garnir les locaux de mobilier et matériel suffisants pour répondre du loyer ; respecter le règlement de copropriété ou de l\'immeuble et les règlements administratifs ; ne rien faire qui puisse nuire à la tranquillité, à la sécurité ou à la bonne tenue de l\'immeuble ; laisser visiter les locaux en cas de vente, de relocation ou de travaux ; souffrir sans indemnité les travaux nécessaires quelle qu\'en soit la durée, par dérogation à l\'article 1724 du Code civil.');

        $h .= $art('Assurances',
            'Le Preneur assurera auprès d\'une compagnie notoirement solvable ses risques locatifs, son mobilier, son matériel et ses marchandises, le recours des voisins et des tiers, ainsi que sa responsabilité civile d\'exploitation, et en justifiera à première demande et chaque année. '
            . 'Le Bailleur assurera l\'immeuble. '
            . '<b>Le Bailleur et le Preneur, ainsi que leurs assureurs respectifs, renoncent réciproquement à tout recours l\'un contre l\'autre</b> pour les dommages garantis par leurs polices. '
            . 'Le Preneur s\'interdit toute activité de nature à aggraver les risques ou à entraîner la résiliation ou la majoration des primes du Bailleur, et supportera les surprimes éventuelles.');

        $h .= $art('Destruction des locaux',
            'En cas de destruction totale des locaux par cas fortuit ou force majeure, le bail sera résilié de plein droit sans indemnité (article 1722 du Code civil). '
            . 'En cas de destruction partielle, le bail se poursuivra avec réduction proportionnelle du loyer, sans que le Bailleur soit tenu de reconstruire, le Preneur ne pouvant prétendre à aucune indemnité ni dommages-intérêts.');

        $h .= $art('État des lieux',
            'Un état des lieux contradictoire est établi lors de la prise de possession et lors de la restitution des locaux, puis annexé au bail (article L.145-40-1 du Code de commerce). '
            . '<b>À défaut d\'état des lieux de sortie amiable, celui-ci sera dressé par huissier de justice à l\'initiative de la partie la plus diligente, aux frais partagés par moitié.</b> '
            . 'Le Preneur restituera les locaux en parfait état ; toute remise en état nécessaire sera exécutée à ses frais et une indemnité d\'occupation égale au dernier loyer majoré des charges restera due jusqu\'à restitution complète des clés et des locaux libres de toute occupation.');

        $h .= $art('Cession et sous-location',
            'Toute sous-location, totale ou partielle, est interdite sauf accord écrit préalable du Bailleur, appelé à concourir à l\'acte. '
            . 'La cession du droit au bail n\'est autorisée qu\'au profit de l\'acquéreur du fonds de commerce exploité dans les lieux, le Bailleur devant être appelé à l\'acte par lettre recommandée ou par acte d\'huissier. '
            . '<b>En cas de cession, le cédant demeure garant solidaire du cessionnaire pour le paiement des loyers et l\'exécution des obligations du bail pendant trois (3) ans</b> à compter de la cession (article L.145-16-2 du Code de commerce).');

        $h .= $art('Droit de préférence du Preneur',
            'En cas de projet de vente des locaux loués, le Bailleur informera le Preneur dans les conditions et selon la procédure de l\'article L.145-46-1 du Code de commerce, sous réserve des exceptions légales (vente unique de l\'immeuble, cession à un proche, etc.).');

        $h .= $art('Clause résolutoire',
            'À défaut de paiement d\'un seul terme de loyer, de charges ou d\'accessoires à son échéance, ou d\'inexécution d\'une seule des conditions du bail, et un (1) mois après un commandement de payer ou une mise en demeure d\'exécuter restés infructueux, '
            . '<b>le bail sera résilié de plein droit</b> si bon semble au Bailleur, sans autre formalité que celles prévues à l\'article L.145-41 du Code de commerce, et le Preneur pourra être expulsé par ordonnance de référé.');

        $h .= $art('Clause pénale',
            'En cas de recouvrement contentieux des sommes dues ou de résiliation aux torts du Preneur, les sommes exigibles seront majorées d\'une indemnité forfaitaire de <b>vingt pour cent (20 %)</b> à titre de clause pénale, sans préjudice des intérêts de retard et des frais de procédure et d\'expulsion.');

        $h .= $art('Non-responsabilité du Bailleur',
            'Le Bailleur ne pourra être tenu responsable des dommages causés au Preneur, à son personnel, à sa clientèle, à ses marchandises ou aux tiers du fait des lieux, des installations, d\'un sinistre, d\'un dégât des eaux, d\'une interruption des réseaux, d\'un vol ou du fait d\'autres occupants, sauf faute lourde du Bailleur, le Preneur faisant son affaire personnelle de toute assurance à cet égard.');

        $h .= $art('Diagnostics techniques et état des risques',
            'Les diagnostics techniques obligatoires (dont, le cas échéant, amiante et performance énergétique) et l\'état des risques (naturels, miniers, technologiques, radon, pollution des sols) sont annexés au présent bail conformément à la réglementation en vigueur. '
            . ($c['erp']
                ? '<b>Les locaux constituant un établissement recevant du public (ERP)</b>, le Preneur fait son affaire personnelle des obligations d\'accessibilité, de sécurité incendie et de conformité liées à son exploitation, et en garantit le Bailleur.'
                : 'Le Preneur fera son affaire personnelle des obligations réglementaires liées à son exploitation.'));

        $h .= $art('Prescription biennale',
            'Conformément à l\'article L.145-60 du Code de commerce, toutes les actions exercées en vertu du statut des baux commerciaux se prescrivent par deux (2) ans.');

        if ($c['opt']) {
            $h .= $art('Promesse et option d\'achat',
                'Le Bailleur consent au Preneur, qui l\'accepte, une option d\'achat des locaux loués'
                . ($c['opt_prix'] !== null ? ' au prix de <b>' . bcp_e((string)bcp_eur($c['opt_prix'])) . ' hors taxes et hors frais</b>' : '')
                . ($c['opt_delai'] ? ', levable dans un délai de ' . bcp_e((string)$c['opt_delai']) . ' mois à compter de la prise d\'effet du bail' : '')
                . '. La levée de l\'option s\'effectuera par lettre recommandée avec accusé de réception ; à défaut de levée dans le délai convenu, l\'option deviendra caduque de plein droit sans indemnité de part ni d\'autre.');
        }

        $h .= $art('Solidarité et indivisibilité',
            'Les obligations du présent bail sont indivisibles à l\'égard du Preneur, de ses ayants droit et héritiers, qui en seront tenus solidairement. En cas de pluralité de preneurs, ceux-ci s\'obligent solidairement et indivisiblement à l\'exécution de l\'ensemble des obligations du bail.');

        $h .= $art('Frais, droits et honoraires',
            'Les frais, droits et honoraires des présentes et de leurs suites, ainsi que les frais d\'état des lieux, sont supportés par le Preneur, sauf répartition légale impérative. Les honoraires de négociation et de rédaction sont, le cas échéant, partagés selon la réglementation applicable et l\'accord des parties.');

        $gar = $ctx['garant'] ?? [];
        if (!empty($gar['present'])) {
            if ($gar['type'] === 'societe') {
                $garId = '<b>' . bcp_e($mut($gar['raison'])) . '</b>' . ($gar['siren'] ? ', immatriculée sous le n° ' . bcp_e($gar['siren']) : '') . ($gar['adresse'] ? ', dont le siège est ' . bcp_e($gar['adresse']) : '');
            } else {
                $garId = '<b>' . bcp_e($mut(trim($gar['nom']))) . '</b>'
                    . (($gar['naiss_d'] || $gar['naiss_l']) ? ', né(e) le ' . bcp_e($gar['naiss_d'] ?: '……') . ($gar['naiss_l'] ? ' à ' . bcp_e($gar['naiss_l']) : '') : '')
                    . ($gar['adresse'] ? ', demeurant ' . bcp_e($gar['adresse']) : '');
            }
            $h .= $art('Cautionnement solidaire',
                'Aux présentes intervient, en qualité de garant : ' . $garId . '. '
                . 'Lequel déclare se rendre caution ' . (!empty($gar['solidaire']) ? '<b>solidaire</b>' : 'simple') . ' du Preneur envers le Bailleur, '
                . 'pour le paiement des loyers, charges, accessoires, indemnités et de toutes sommes dues au titre du présent bail'
                . ($gar['montant'] !== null ? ', dans la limite de <b>' . bcp_e((string)bcp_eur($gar['montant'])) . '</b>' : '')
                . ($gar['duree'] ? ' et pour une durée de ' . bcp_e((string)$gar['duree']) . ' an(s)' : '') . '. '
                . 'La caution reconnaît avoir une parfaite connaissance de la nature et de l\'étendue de son engagement et renonce au bénéfice de discussion et de division.');
        }

        if (($ctx['cond_particulieres'] ?? '') !== '') {
            $h .= $art('Conditions particulières',
                'Les parties conviennent en outre des conditions particulières suivantes, qui prévalent sur les clauses générales en cas de contradiction : '
                . nl2br(bcp_e($ctx['cond_particulieres'])) . '.');
        }

        $h .= $art('Élection de domicile et attribution de compétence',
            'Pour l\'exécution des présentes, les parties élisent domicile en leurs sièges et adresses respectifs. Tout litige relatif au présent bail relèvera de la compétence exclusive du Tribunal judiciaire du lieu de situation des locaux.');

        // ── Annexe 1 : répartition des charges ──
        $lignes = [
            ['Loyer et TVA', 'P'],
            ['Consommations propres au local (eau, électricité, gaz, chauffage)', 'P'],
            ['Entretien courant et menues réparations des locaux loués', 'P'],
            ['Charges locatives récupérables de copropriété (nettoyage, ascenseur, espaces verts, éclairage des parties communes)', 'P'],
            ['Taxe d\'enlèvement des ordures ménagères (TEOM) et taxe de balayage', 'P'],
            ['Redevance d\'assainissement', 'P'],
            ['Assurance des risques locatifs et responsabilité du Preneur', 'P'],
            ['Taxe foncière et taxes additionnelles (stipulée à la charge du Preneur au présent bail)', 'P'],
            ['Grosses réparations de l\'article 606 du Code civil (gros murs, voûtes, poutres, toitures, murs de soutènement)', 'B'],
            ['Travaux de mise en conformité et de vétusté relevant des grosses réparations', 'B'],
            ['Travaux et charges relatifs à des locaux vacants ou imputables à d\'autres locataires', 'B'],
            ['Honoraires de gestion des loyers de l\'immeuble', 'B'],
            ['Impôts dont le Bailleur est personnellement redevable (contribution sur les revenus locatifs, CFE du Bailleur)', 'B'],
        ];
        $rows = '';
        foreach ($lignes as $l) {
            $who = $l[1] === 'P'
                ? '<b style="color:#2f7d52">Preneur</b>'
                : '<b style="color:#b06a1c">Bailleur</b>';
            $rows .= '<tr><td>' . bcp_e($l[0]) . '</td><td class="who">' . $who . '</td></tr>';
        }
        $h .= '<pagebreak />';
        $h .= '<h2>Annexe 1 &ndash; Inventaire et répartition des charges</h2>';
        $h .= '<p class="sub2">Établie en application de l\'article L.145-40-2 du Code de commerce (loi Pinel).</p>';
        $h .= '<table class="tbl"><thead><tr><th>Nature de la charge, taxe ou redevance</th><th>À la charge de</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        // ── Annexe 2 : travaux (L.145-40-2) ──
        $h .= '<h2>Annexe 2 &ndash; État prévisionnel et récapitulatif des travaux</h2>';
        $h .= '<p class="sub2">Article L.145-40-2 du Code de commerce.</p>';
        $travR = trim((string)($ctx['travaux_realises'] ?? '')); $travP = trim((string)($ctx['travaux_prevus'] ?? ''));
        $h .= '<table class="tbl"><thead><tr><th>Période</th><th>Travaux</th></tr></thead><tbody>'
            . '<tr><td style="width:32%;">Trois années écoulées<br><span class="mut">Récapitulatif des travaux réalisés</span></td><td>' . ($travR !== '' ? nl2br(bcp_e($travR)) : '<span class="mut">Néant</span>') . '</td></tr>'
            . '<tr><td>Trois années à venir<br><span class="mut">État prévisionnel des travaux envisagés</span></td><td>' . ($travP !== '' ? nl2br(bcp_e($travP)) : '<span class="mut">Néant</span>') . '</td></tr>'
            . '</tbody></table>';
        $h .= '<p class="mut">Le Bailleur informera le Preneur, en cours de bail, de tout nouveau travaux envisagé et de son coût prévisionnel.</p>';

        // ── Signatures (tracés incrustés si le bail est signé) ──
        $sigs = $ctx['signatures'] ?? [];
        $findSig = function (string $role) use ($sigs): ?array {
            foreach ($sigs as $s) {
                if (($s['role_code'] ?? '') === $role && ($s['statut'] ?? '') === 'signe') return $s;
            }
            return null;
        };
        // Rendu d'une case signataire : image du tracé + « Lu et approuvé » + date, sinon ligne à signer.
        $sigCell = function (?array $sig, string $mention = 'Lu et approuvé'): string {
            if ($sig && !empty($sig['signature_data']) && strncmp((string)$sig['signature_data'], 'data:image', 10) === 0) {
                $dt = bcp_date($sig['signed_at'] ?? null);
                $hasPhoto = !empty($sig['photo_preuve']) && strncmp((string)$sig['photo_preuve'], 'data:image', 10) === 0;
                $out = '<span class="mut">« ' . $mention . ' »</span><br>';
                if ($hasPhoto) {
                    // Tracé + photo-preuve côte à côte.
                    $out .= '<table style="border-collapse:collapse;"><tr>'
                        . '<td style="vertical-align:middle;padding:0 8px 0 0;"><img src="' . $sig['signature_data'] . '" style="max-height:60px;max-width:150px;"></td>'
                        . '<td style="vertical-align:middle;"><img src="' . $sig['photo_preuve'] . '" style="max-height:64px;max-width:64px;border:0.5pt solid #999;">'
                        . '<br><span class="mut" style="font-size:7pt;">photo-preuve</span></td>'
                        . '</tr></table>';
                } else {
                    $out .= '<img src="' . $sig['signature_data'] . '" style="max-height:64px;max-width:190px;">';
                }
                $out .= '<span class="mut">' . bcp_e((string)($sig['nom_signataire'] ?? '')) . ($dt ? ' &mdash; signé le ' . bcp_e($dt) : '') . '</span>';
                return $out;
            }
            return 'Signature précédée de la mention « ' . $mention . ' »<br><br><br>………………………………';
        };
        $sigPreneur = $findSig('preneur');
        $sigCaution = $findSig('caution');
        $sigBailleur = $findSig('mandataire') ?: $findSig('bailleur');

        $lieu = $ge['ville_sig'] ?: '……………………';
        $anySigned = $sigPreneur || $sigCaution || $sigBailleur;
        $dateFait = $anySigned ? (bcp_date(($sigPreneur['signed_at'] ?? null) ?: ($sigCaution['signed_at'] ?? null) ?: ($sigBailleur['signed_at'] ?? null)) ?: '……………………') : '……………………';
        $h .= '<div class="sign">';
        $h .= '<p>Fait à ' . bcp_e($lieu) . ', le ' . bcp_e($dateFait) . ', en deux exemplaires originaux, dont un remis à chaque partie.</p>';
        $h .= '<table class="sigtbl"><tr>'
            . '<td><b>LE BAILLEUR</b><br><span class="mut">(ou son mandataire)</span><br><br>' . $sigCell($sigBailleur) . '</td>'
            . '<td><b>LE PRENEUR</b><br><span class="mut">&nbsp;</span><br><br>' . $sigCell($sigPreneur) . '</td>'
            . '</tr>'
            . (!empty($gar['present']) ? '<tr><td colspan="2" style="padding-top:14px;"><b>LA CAUTION</b> <span class="mut">(bon pour caution solidaire)</span><br><br>' . $sigCell($sigCaution, 'Bon pour caution solidaire, lu et approuvé') . '</td></tr>' : '')
            . '</table>';
        if ($anySigned) {
            $preuve = [];
            foreach ($sigs as $s) {
                if (($s['statut'] ?? '') !== 'signe') continue;
                $preuve[] = ucfirst((string)($s['role_code'] ?? '')) . ' : ' . bcp_e((string)($s['nom_signataire'] ?? ''))
                    . (!empty($s['ip']) ? ' — IP ' . bcp_e((string)$s['ip']) : '')
                    . (!empty($s['signed_at']) ? ' — ' . bcp_e((string)$s['signed_at']) : '');
            }
            if ($preuve) $h .= '<p class="mut" style="margin-top:10px;">Preuve de signature électronique &mdash; ' . implode(' · ', $preuve) . '.</p>';
        }
        $h .= '</div>';

        $h .= '</div>';
        return $h;
    }

    /**
     * CORPS EXACT du bail commercial FNAIM (Régie EMERY) + annexe « Inventaire des charges »,
     * UN SEUL document à la suite. Texte reproduit tel quel (exigence RCP), avec injection des
     * données $ctx aux emplacements du modèle ; les champs sans donnée restent VIDES (comme le
     * modèle vierge). Ne PAS "améliorer" ni raccourcir. Le bloc signatures (tracés) est repris
     * de la même machinerie que l'aperçu/PDF.
     */
    function bail_commercial_corps_fnaim(array $ctx): string
    {
        $ge = $ctx['gestionnaire']; $pr = $ctx['preneur']; $c = $ctx['cond']; $gar = $ctx['garant'];
        $B  = fn($v) => '<b>' . bcp_e((string)$v) . '</b>';
        $blank = fn($v) => ($v !== null && $v !== '' && $v !== 0 && $v !== 0.0) ? bcp_e((string)$v) : '……………………';
        $cb = fn($on) => $on ? '&#9746;' : '&#9744;'; // ☒ / ☐

        // ── Identités ──
        // BAILLEUR = propriétaire. Les infos légales (forme, capital, siège, RCS, gérant) sont
        // REPRISES de l'annuaire Pappers stocké sur le tiers ($ctx['bailleur_legal']) ; blanc si
        // non renseigné (aucune invention). Le représentant SAISI sur le bail prime sur le gérant Pappers.
        $bl     = $ctx['bailleur_legal'] ?? [];
        $bForme = !empty($bl['forme'])   ? ', ' . bcp_e((string)$bl['forme'])                 : '';
        $bCap   = !empty($bl['capital']) ? bcp_e((string)bcp_eur($bl['capital'])) . ' €'       : '……………………';
        $bSiege = !empty($bl['siege'])   ? bcp_e((string)$bl['siege'])                          : '……………………';
        $bSiren = !empty($bl['siren'])   ? bcp_e((string)$bl['siren'])                          : '……………………';
        $bRep   = $ctx['bailleur_rep'] ?: (string)($bl['rep'] ?? '');
        $bailleur = 'La Société ' . $B($ctx['proprio_nom'] ?: '……………………') . $bForme
            . ' au capital social de ' . $bCap
            . ', dont le siège social est situé ' . $bSiege
            . ', immatriculée au RCS sous le numéro ' . $bSiren . ','
            . ($bRep ? ' Représentée par ' . $B($bRep) . ', se déclarant habilité(e) à cet effet aux termes des statuts.' : ' Représentée par …………………….');

        // MANDATAIRE (Agence) = société de gestion + agence, infos réelles de la base.
        $mandataire = $B($ge['raison'] ?: '……………………')
            . ($ge['forme'] ? ', ' . bcp_e($ge['forme']) : '')
            . ($ge['capital'] ? ' au capital de ' . bcp_e((string)bcp_eur($ge['capital'])) . ' €' : '')
            . ($ge['adresse'] ? ', dont le siège social est situé ' . bcp_e($ge['adresse']) : '')
            . ($ge['siren'] ? ', immatriculée au RCS sous le n° ' . bcp_e($ge['siren']) : '')
            . ($ge['carte'] ? ', titulaire de la carte professionnelle portant la mention « Gestion immobilière » n° ' . bcp_e($ge['carte']) . ($ge['carte_cci'] ? ' délivrée par ' . bcp_e($ge['carte_cci']) : '') : '')
            . ($ge['rcp'] ? ', titulaire d\'une assurance en responsabilité civile professionnelle ' . bcp_e($ge['rcp']) : '')
            . ($ge['garantie'] ? ', garantie financière ' . bcp_e($ge['garantie']) : '')
            . ($ge['age_nom'] ? ', par l\'intermédiaire de son agence ' . bcp_e($ge['age_nom']) . ($ge['age_adr'] ? ' — ' . bcp_e($ge['age_adr']) : '') : '')
            . ', adhérent de la Fédération Nationale de l\'Immobilier (FNAIM), ayant le titre professionnel administrateur de biens et agent immobilier obtenu en France dont l\'activité est régie par la loi n° 70-9 du 2 janvier 1970 (dite « loi Hoguet ») et son décret d\'application n° 72-678 du 20 juillet 1972, et soumis au code d\'éthique et de déontologie de la FNAIM.';

        // PRENEUR
        if ($pr['type'] === 'societe') {
            $preneur = 'La Société ' . $B($pr['raison'] ?: '……………………')
                . ($pr['siren'] ? ', immatriculée sous le numéro ' . bcp_e($pr['siren']) : ', immatriculée sous le numéro ……………………')
                . ($pr['adresse'] ? ', dont le siège social est situé ' . bcp_e($pr['adresse']) : ', dont le siège social est situé ……………………')
                . ($pr['rep'] ? ', représentée par ' . $B($pr['rep']) . ($pr['rep_q'] ? ' en qualité de ' . bcp_e($pr['rep_q']) : '') : '');
        } else {
            $preneur = $B(trim($pr['nom']) ?: '……………………')
                . (($pr['naiss_d'] || $pr['naiss_l']) ? ', né(e) le ' . bcp_e($pr['naiss_d'] ?: '……') . ($pr['naiss_l'] ? ' à ' . bcp_e($pr['naiss_l']) : '') : '')
                . ($pr['nat'] ? ', de nationalité ' . bcp_e($pr['nat']) : '')
                . ($pr['adresse'] ? ', demeurant ' . bcp_e($pr['adresse']) : '');
        }
        $coord = [];
        if ($pr['email']) $coord[] = bcp_e($pr['email']);
        if ($pr['tel'])   $coord[] = bcp_e($pr['tel']);
        if ($coord) $preneur .= ' (' . implode(' · ', $coord) . ')';

        // ── Valeurs financières ──
        $loyerA = $c['loyer_a'] ? bcp_eur($c['loyer_a']) : '……………………';
        $loyerM = $c['loyer_m'] ? bcp_eur($c['loyer_m']) : '……………………';
        $tvaOn  = (bool)$c['tva_app']; $tvaTaux = (float)$c['tva_taux'] ?: 20.0;
        $dgM    = $c['dg_montant'] !== null ? bcp_eur($c['dg_montant']) : '……………………';
        $dgMois = $c['dg_mois'] !== null ? (int)$c['dg_mois'] : 1;
        $indice = ($c['indice_trim'] ?: '……………………') . ($c['indice_val'] ? ' (valeur ' . bcp_e($c['indice_val']) . ')' : '');
        $dEffet = $c['date_effet'] ? bcp_date($c['date_effet']) : '……………………';
        $dFin   = '';
        if ($c['date_effet'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$c['date_effet'])) {
            $mois = $c['duree_mois'] ?: 108; $dFin = bcp_date(date('Y-m-d', strtotime((string)$c['date_effet'] . ' +' . $mois . ' months -1 day')));
        } else { $dFin = '……………………'; }

        // Champs FNAIM
        $de     = $c['droit_entree'] !== null ? bcp_eur($c['droit_entree']) . ' €' : '…………… €';
        $penal  = rtrim(rtrim(number_format((float)($c['taux_penalite'] ?? 10.0), 2, ',', ''), '0'), ',');
        $loyerAn = (float)($c['loyer_a'] ?? 0);
        $hpPren = $c['hono_pct_pren']; $hpBail = $c['hono_pct_bail'];
        $honoPrenM = $hpPren !== null ? $loyerAn * $hpPren / 100 : ($c['hono_loc'] ?? null);
        $honoBailM = $hpBail !== null ? $loyerAn * $hpBail / 100 : ($c['hono_bail'] ?? null);

        $h = '<div class="doc">';
        $h .= '<h1>BAIL COMMERCIAL</h1>';
        $h .= '<p class="sub">Soumis au statut des baux commerciaux &mdash; articles L.145-1 et suivants du Code de commerce</p>';
        if ($ctx['numero_bail']) $h .= '<p class="ref">Mandat n° ' . bcp_e($ctx['numero_bail']) . '</p>';

        $h .= '<h2>ENTRE LES SOUSSIGNÉS</h2>';
        $h .= '<p class="clabel">Pour le BAILLEUR</p><p>' . $bailleur . '<br><span class="qual">Ci-après « le BAILLEUR », d\'une part,</span></p>';
        $h .= '<p class="clabel">Avec le concours de</p><p>' . $mandataire . '</p>';
        $h .= '<p class="clabel">Le PRENEUR</p><p>' . $preneur . '<br><span class="qual">Ci-après « le PRENEUR », d\'autre part,</span></p>';
        $h .= '<p>De convention expresse, les parties renoncent aux dispositions de l\'article 57 A de la loi n° 86-1290 du 23 décembre 1986 pour se soumettre de façon définitive et irrévocable au statut des baux commerciaux. Cette adoption conventionnelle du statut des baux commerciaux, effectuée conformément aux dispositions de l\'article L. 145-2, 7° du Code de commerce, constitue une condition essentielle et déterminante du présent bail sans laquelle il n\'aurait pas été conclu.</p>';

        $h .= '<h2>EXPOSÉ</h2>';
        $h .= '<p>Le présent contrat a fait l\'objet d\'une négociation libre, éclairée et de bonne foi entre les Parties. Les Parties déclarent que le contrat a fait l\'objet de concessions réciproques entre elles. En conséquence, le contrat constitue un contrat de gré à gré au sens de l\'article 1110 du Code civil. Le BAILLEUR est propriétaire de l\'immeuble ci-après désigné, pour l\'avoir acquis, reçu en donation, recueilli dans une succession, ou fait construire, suivant acte en date du NON PRÉCISÉ.</p>';
        $h .= '<p>Le BAILLEUR déclare : qu\'il n\'existe aucune restriction à l\'utilisation définie ci-dessous des biens loués ni du règlement de copropriété s\'il y a lieu ; qu\'à sa connaissance, les biens loués ne font l\'objet d\'aucune mesure d\'expropriation en cours, que ces biens ne sont pas situés dans un secteur de rénovation et plus généralement, qu\'aucune mesure actuelle d\'urbanisme n\'est susceptible de remettre en cause la jouissance résultant du présent bail.</p>';
        $h .= '<p><b>CECI EXPOSÉ, BAILLEUR ET PRENEUR ONT ÉTABLI CE QUI SUIT :</b></p>';
        $h .= '<p><b>CONVENTION.</b> Conformément aux articles L. 145-1 et suivants du code de commerce, le BAILLEUR donne à bail à usage commercial au profit du PRENEUR, qui accepte, l\'immeuble dont la situation et la désignation suivent :</p>';

        // 1. Situation et désignation des lieux loués
        $desig = ($ctx['bien_ref'] ? $B($ctx['bien_ref']) : '')
            . ($ctx['bien_adresse'] ? ($ctx['bien_ref'] ? ', sis ' : 'Sis ') . bcp_e($ctx['bien_adresse']) : '')
            . ($ctx['immeuble'] ? ', dépendant de l\'immeuble ' . bcp_e($ctx['immeuble']) : '')
            . ($ctx['numero_lot'] ? ', lot n° ' . bcp_e($ctx['numero_lot']) : '')
            . ($ctx['bien_copro'] ? ', ' . bcp_e($ctx['bien_copro']) : '');
        $h .= '<h3>1. Situation et désignation des lieux loués</h3>';
        $h .= '<p><b>Adresse :</b> ' . ($ctx['bien_adresse'] ? bcp_e($ctx['bien_adresse']) : '……………………') . '<br>'
            . '<b>Description :</b> ' . ($desig ?: '……………………') . ($ctx['bien_description'] ? '. ' . bcp_e($ctx['bien_description']) : '') . '</p>';
        $h .= '<p>La surface totale des locaux est d\'environ ' . ($ctx['surface'] > 0 ? bcp_e(number_format($ctx['surface'], 0, ',', ' ')) . ' m²' : '……………') . '. Tels que lesdits lieux s\'entendent, se poursuivent et se comportent sans aucune exception ni réserve, le PRENEUR déclarant les connaître pour les avoir vu et visités préalablement à la signature des présentes.</p>';
        $h .= '<p>Il est précisé que toute différence entre la surface indiquée et les dimensions réelles desdits lieux ne pourra justifier ni réduction ni augmentation du loyer. En conséquence, le PRENEUR ne pourra demander aucune réduction du loyer ou indemnité pour erreur sur la surface.</p>';
        $h .= '<p>Tel que lesdits locaux existent, s\'étendent, se poursuivent et comportent avec toutes leurs aisances et dépendances, sans aucune exception ni réserve, et sans qu\'il soit nécessaire d\'en faire plus ample désignation, le PRENEUR déclarant parfaitement les connaître, pour les avoir vus et visités préalablement aux présentes.</p>';
        $h .= '<p>Il est expressément convenu que les biens loués forment un tout matériellement et juridiquement indivisible.</p>';

        // 2. Durée du bail
        $h .= '<h3>2. Durée du bail</h3>';
        $h .= '<p>Le présent bail est conclu et accepté pour une durée de <b>9</b> années entières et consécutives, qui commenceront à courir le ' . $dEffet . ' pour se terminer le ' . $dFin . '. Toutefois, conformément aux dispositions de l\'article L. 145-4 du code de commerce :</p>';
        $h .= '<p>- le PRENEUR aura la faculté de donner congé à l\'expiration de chaque période triennale au moins six mois à l\'avance, par lettre recommandée avec demande d\'avis de réception ou par acte extrajudiciaire ;<br>'
            . '- le bailleur aura la même faculté, dans les formes et délais de l\'article L. 145-9 du code de commerce (à-savoir par acte extrajudiciare), s\'il entend invoquer les dispositions des articles L. 145-18, L.145-21 et L. 145-24 du code de commerce.</p>';
        $h .= '<p>Si par cas fortuit ou force majeure, les biens loués venaient à être détruits en totalité, le présent bail sera résilié de plein droit, sans indemnité de la part du BAILLEUR et sans préjudice du recours que ce dernier aurait à l\'encontre du PRENEUR si la destruction lui était imputable.</p>';
        $h .= '<p>A l\'issue du présent bail, le PRENEUR ne pourra donner congé que par acte extrajudiciaire.</p>';

        // 3. Destination des lieux loués
        $h .= '<h3>3. Destination des lieux loués</h3>';
        $h .= '<p>Les lieux loués seront destinés exclusivement aux activités de ' . ($c['destination'] ? $B($c['destination']) : '……………………') . ' à l\'exclusion de toute autre utilisation.</p>';
        $h .= '<p>Dès lors, le PRENEUR reconnaît et accepte expressément qu\'il ne pourra en aucun cas utiliser les lieux loués à usage d\'habitation principale. Il s\'agit d\'une condition déterminante de l\'engagement du BAILLEUR, sans laquelle il n\'aurait pas contracté.</p>';
        $h .= '<p>Les locaux loués doivent être affectés uniquement à l\'exercice de l\'activité commerciale prédéfinie ainsi qu\'éventuellement, et à titre accessoire, à usage de remise ou de réserve.</p>';
        $h .= '<p>Le PRENEUR ne pourra, sous aucun prétexte, changer la destination des lieux loués et ce, même de façon temporaire.</p>';
        $h .= '<p>Il pourra toutefois adjoindre à ce commerce des activités connexes ou complémentaires, mais à la condition expresse de faire connaître son intention au BAILLEUR en se conformant à la procédure prévue aux articles L. 145 47 et suivants du code de commerce.</p>';

        // 4. Loyer
        $h .= '<h3>4. Loyer</h3>';
        $h .= '<p><b>Le présent bail est consenti et accepté moyennant un loyer annuel hors taxes en principal de (' . $loyerA . ' €) que le PRENEUR s\'oblige à payer au BAILLEUR ou à son mandataire :</b></p>';
        $h .= '<p>' . $cb($c['perio'] !== 'trimestrielle') . ' par mois<br>' . $cb($c['perio'] === 'trimestrielle') . ' selon une autre modalité :<br>' . $cb(true) . ' à terme d\'avance<br>' . $cb(false) . ' à terme échu</p>';
        $h .= '<p>auquel s\'ajoute la TVA au taux en vigueur à la date d\'exigibilité du loyer, que le PRENEUR s\'engage à régler expressément à la même période que le loyer :</p>';
        $h .= '<p>' . $cb(!$tvaOn) . ' de plein droit.<br>' . $cb($tvaOn) . ' sur option du BAILLEUR, et ce même en cours de bail, option que le PRENEUR accepte expressément par avance.</p>';
        $h .= '<p>Tous les paiements auront lieu au domicile du BAILLEUR ou de son mandataire, ou en tout autre lieu indiqué par lui.</p>';
        $h .= '<p>Le PRENEUR pourra, à tout moment en cours de bail, demander au Bailleur, par lettre recommandée avec demande d\'avis de réception, la mensualisation du paiement du loyer.</p>';
        $h .= '<p>À compter de la réception de cette demande, le règlement s\'effectuera mensuellement et d\'avance, à l\'échéance suivante, sans frais ni pénalité pour le PRENEUR, dans le respect des dispositions légales en vigueur de l\'article 145-32-1 du Code de commerce.</p>';

        // 5. Pas de porte / Droit d'entrée
        $h .= '<h3>5. Pas de porte / Droit d\'entrée</h3>';
        $h .= '<p>Le PRENEUR s\'engage à verser au BAILLEUR, lors de la signature du présent bail, une somme de ' . $de . ', désignée comme pas-de-porte ou droit d\'entrée. Cette somme est définitivement acquise au BAILLEUR et ne pourra donner lieu à restitution, sauf stipulation contraire.</p>';
        $h .= '<p>Le pas-de-porte est convenu entre les parties comme ……………………</p>';
        $h .= '<p>Le paiement du pas-de-porte sera effectué ……………………</p>';
        $h .= '<p>Le pas-de-porte est soumis à la réglementation fiscale en vigueur, notamment en ce qui concerne la taxe sur la valeur ajoutée (TVA). Les parties reconnaissent que le pas-de-porte pourra être pris en compte pour la révision triennale et le calcul du loyer lors du renouvellement du bail, conformément aux dispositions légales applicables.</p>';

        // 6-7. Révision
        $h .= '<h3>6. Indexation annuelle du loyer</h3>';
        $h .= '<h3>7. Révision triennale légale</h3>';
        $h .= '<p>Le loyer ci-dessus fixé pourra être révisé trois ans au moins après la date d\'entrée en jouissance du PRENEUR ou après le point de départ du bail renouvelé conformément à l\'article L. 145-38 du code de commerce. De nouvelles demandes de révision pourront être formées tous les trois ans à compter du jour où le nouveau prix sera applicable par application des dispositions légales.</p>';
        $h .= '<p>L\'indice servant de base à la révision sera celui du trimestre valeur ' . $indice . '.</p>';
        $h .= '<p>L\'indice de comparaison sera le dernier indice publié au jour de la demande de révision et d\'une façon générale les indices à prendre en compte seront d\'une part, le dernier indice publié au jour de la dernière fixation amiable ou judiciaire du loyer et, d\'autre part, le dernier indice publié au jour de la date de révision.</p>';
        $h .= '<p>Si cet indice venait à disparaître, l\'indice qui lui serait substitué s\'appliquerait de plein droit.</p>';
        $h .= '<p>Si aucun indice de substitution n\'était publié, les parties conviendraient d\'un nouvel indice. A défaut d\'accord, il serait déterminé par un arbitre choisi d\'un commun accord entre les parties.</p>';

        // 8. Dépôt de garantie
        $h .= '<h3>8. Dépôt de garantie</h3>';
        $h .= '<p>Pour garantir l\'exécution des obligations lui incombant, le PRENEUR verse au BAILLEUR ou à son mandataire qui le reconnaît, la somme de (' . $dgM . ' €) à titre de garantie correspondant à ' . $dgMois . ' mois de loyer.</p>';
        $h .= '<p>A l\'expiration des relations contractuelles, cette somme sera restituée au PRENEUR, dans les trois mois suivant la remise des clefs, en main propre ou par lettre recommandée avec demande d\'avis de réception, déduction faite de toute somme dont il pourrait être débiteur à quelque titre que ce soit et notamment au titre de loyers, charges, taxes, réparations ou indemnités quelconques.</p>';
        $h .= '<p>Il est expressément convenu qu\'au cas où le loyer viendrait à augmenter, la somme versée à titre de garantie sera augmentée automatiquement dans la même proportion.</p>';
        $h .= '<p>En cas de mutation à titre gratuit ou à titre onéreux des locaux pris à bail, l\'obligation de restitution au PRENEUR des sommes payées à titre de garantie est transmise au nouveau PRENEUR.</p>';
        $h .= '<p>En cas de procédure collective du PRENEUR, le dépôt de garantie sera acquis au BAILLEUR par compensation avec les loyers, charges, impôts, taxes et accessoires et autres sommes au titre des présentes restant éventuellement dus au jour de l\'ouverture de la procédure collective, à due concurrence.</p>';

        // 9. Etat des lieux
        $h .= '<h3>9. Etat des lieux</h3>';
        $h .= '<p>Lors de la prise de possession des locaux et lors de leur restitution, un état des lieux sera établi contradictoirement et amiablement par les parties ou par un tiers mandaté par elles, et joint au contrat de location ou à défaut, conservé par les parties, aux frais partagé entre LE BAILLEUR et LE PRENEUR (50/50) au prix de 4 € HT du m².</p>';
        $h .= '<p>Si l\'état des lieux ne peut être établi dans les conditions ci-dessus invoquées, il sera établi par un commissaire de justice, sur l\'initiative de la partie la plus diligente, à frais partagés par moitié entre le BAILLEUR et le LOCATAIRE.</p>';
        $h .= '<p>Le Preneur prend les lieux loués à ses risques et périls et exonère le Bailleur de toute responsabilité à raison des dommages pouvant survenir dans les locaux loués, sauf faute lourde ou dolosive du Bailleur.</p>';

        // 10. Charges, impôts, taxes et redevances
        $h .= '<h3>10. Charges, impôts, taxes et redevances</h3>';
        $h .= '<p>Le PRENEUR prendra les biens loués dans l\'état où ils se trouveront au moment de l\'entrée en jouissance.</p>';
        $h .= '<p>Le PRENEUR devra assurer, sans aucun recours contre le BAILLEUR, l\'entretien complet des biens loués de manière qu\'ils soient constamment maintenus en état de propreté.</p>';
        $h .= '<p>Le PRENEUR ne pourra rien faire ni laisser faire qui puisse détériorer les biens loués. Il devra prévenir le BAILLEUR, sans aucun retard et par lettre recommandée avec avis de réception, sous peine d\'être personnellement responsable de toute atteinte qui serait portée à la propriété, en cas de travaux, de dégradations et détériorations qui viendraient à se produire dans les biens loués et qui rendraient nécessaire l\'intervention du BAILLEUR.</p>';
        $h .= '<p>A l\'expiration du bail, le PRENEUR rendra les biens loués en bon état de réparations, d\'entretien et de fonctionnement.</p>';
        $h .= '<p>En cas d\'exécution et de préfinancement par le propriétaire de travaux dont la charge incombe au PRENEUR, le BAILLEUR pourra demander, sur justificatif, le remboursement au PRENEUR des provisions ou acomptes qu\'il aura fait pour son compte.</p>';
        $h .= '<p>En conséquence des stipulations ci-dessus, le BAILLEUR sera toujours réputé satisfaire à toutes ses obligations et notamment à celles visées par l\'article 1719 du Code civil.</p>';
        $h .= '<p>En application de l\'article L. 145-40-2 du code de commerce les charges, impôts, taxes et redevances donnent lieu à un inventaire annexé présenté ci-après (article 11) au présent bail qui indique leur répartition entre le BAILLEUR et le PRENEUR. Cet inventaire donne lieu à un état récapitulatif annuel communiqué au PRENEUR au plus tard le 30 septembre de l\'année suivant celle au titre de laquelle il a été établi ou dans le délai de trois mois à compter de la reddition des charges dans l\'hypothèse où les lieux loués sont situés dans un immeuble en copropriété.</p>';
        $h .= '<p>La liste des charges, travaux, impôts, taxes et redevances qui ne peuvent être imputés au locataire a été fixée par un décret n° 2014-1317 du 3 novembre 2014 et codifiée à l\'article R. 145-35 du code de commerce.</p>';

        // Récapitulatif des sommes versées à chaque terme
        $techPctT = ($c['tech_pct'] ?? null) !== null ? (float)$c['tech_pct'] : 0.0;
        $techM    = $techPctT > 0 ? (float)($c['loyer_m'] ?? 0) * $techPctT / 100 : 0.0; // honoraires gestion technique / terme (HT)
        $tvaBaseT = (float)($c['loyer_m'] ?? 0) + $techM;                                // assiette TVA = loyer + gestion technique
        $rows = '';
        $rows .= '<tr><td>Loyer</td><td style="text-align:right;">' . ($c['loyer_m'] ? bcp_eur($c['loyer_m']) . ' €' : '&nbsp;') . '</td></tr>';
        if ($techM > 0) $rows .= '<tr><td>Honoraires de gestion technique (' . rtrim(rtrim(number_format($techPctT,2,',',''),'0'),',') . ' %)</td><td style="text-align:right;">' . bcp_eur($techM) . ' €</td></tr>';
        if ($tvaOn) $rows .= '<tr><td>TVA (' . rtrim(rtrim(number_format($tvaTaux,2,',',''),'0'),',') . ' %)</td><td style="text-align:right;">' . ($tvaBaseT > 0 ? bcp_eur($tvaBaseT * $tvaTaux/100) . ' €' : '&nbsp;') . '</td></tr>';
        $rows .= '<tr><td>Provision pour charges</td><td style="text-align:right;">' . ($c['charges_m'] !== null ? bcp_eur($c['charges_m']) . ' €' : '&nbsp;') . '</td></tr>';
        if ((float)($c['prov_tf'] ?? 0) > 0) $rows .= '<tr><td>Provision taxe foncière</td><td style="text-align:right;">' . bcp_eur($c['prov_tf']) . ' €</td></tr>';
        $totT = $tvaBaseT * ($tvaOn ? (1+$tvaTaux/100) : 1) + (float)($c['charges_m'] ?? 0) + (float)($c['prov_tf'] ?? 0);
        $rows .= '<tr><td><b>Soit un total de</b></td><td style="text-align:right;"><b>' . ($totT>0 ? bcp_eur($totT) . ' €' : '&nbsp;') . '</b></td></tr>';
        $h .= '<p><b>Récapitulatif des sommes versées par le PRENEUR à chaque terme :</b></p>';
        $h .= '<table class="tbl"><thead><tr><th>Somme versée par le LOCATAIRE à chaque terme</th><th style="text-align:right;width:28%;">Montant</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        // Somme à verser à la SIGNATURE (1er versement) — TOUJOURS affichée (partie essentielle du
        // bail), même si des montants restent à compléter (……) : 1er terme + DG + pas-de-porte + honoraires preneur.
        $perM  = ($c['perio'] ?? '') === 'trimestrielle' ? 3 : 1;

        // PRORATA du 1er terme (loyer + charges) selon « Loyer calculé à partir du » (prorata_date),
        // sinon la prise d'effet. Impératif : le 1er versement est proratisé (mois ou trimestre civil).
        $prRatio = 1.0; $prTxt = '';
        $prRaw = ($c['prorata_date'] ?? '') ?: ($c['date_effet'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prRaw)) {
            $pts = strtotime((string)$prRaw); $py=(int)date('Y',$pts); $pm=(int)date('n',$pts); $pd=(int)date('j',$pts);
            if ($perM === 3) {
                $qs = intdiv($pm-1,3)*3+1; $qStart=mktime(0,0,0,$qs,1,$py); $qEnd=mktime(0,0,0,$qs+3,0,$py);
                $tot=(int)round(($qEnd-$qStart)/86400)+1; $rem=(int)round(($qEnd-$pts)/86400)+1; $prRatio = $tot>0 ? $rem/$tot : 1.0;
            } else {
                $dim=(int)date('t',$pts); $prRatio = $dim>0 ? ($dim-$pd+1)/$dim : 1.0;
            }
            if ($prRatio < 0.9999) $prTxt = ', prorata au ' . date('d/m/Y',$pts);
        }

        $loy1  = (float)($c['loyer_m'] ?? 0) * $perM * $prRatio;
        $loy1T = $tvaOn ? $loy1 * (1 + $tvaTaux / 100) : $loy1;
        $ch1   = (float)($c['charges_m'] ?? 0) * $perM * $prRatio;
        $tf1   = (float)($c['prov_tf'] ?? 0) * $perM * $prRatio;
        $dg1   = (float)($c['dg_montant'] ?? 0);
        $de1   = (float)($c['droit_entree'] ?? 0);
        $ho1   = $honoPrenM ? (float)$honoPrenM * 1.20 : 0.0; // honoraires = service agence, TVA 20 %
        $tech1  = $techM * $perM * $prRatio;                          // honoraires gestion technique (récupérables)
        $tech1T = $tvaOn ? $tech1 * (1 + $tvaTaux / 100) : $tech1;    // dans l'assiette TVA
        $fpDef = [
            ['1ᵉʳ loyer (' . ($perM === 3 ? 'trimestre' : 'mois') . ' d\'avance' . $prTxt . ')' . ($tvaOn ? ' TTC' : ' HT'), $loy1T],
        ];
        if ($tech1 > 0) $fpDef[] = ['Honoraires de gestion technique (' . rtrim(rtrim(number_format($techPctT,2,',',''),'0'),',') . ' %)' . ($prRatio < 0.9999 ? ' (prorata)' : '') . ($tvaOn ? ' TTC' : ''), $tech1T];
        $fpDef[] = ['Provision pour charges' . ($prRatio < 0.9999 ? ' (prorata)' : ''), $ch1];
        if ($tf1 > 0) $fpDef[] = ['Provision taxe foncière' . ($prRatio < 0.9999 ? ' (prorata)' : ''), $tf1];
        $fpDef[] = ['Dépôt de garantie', $dg1];
        $fpDef[] = ['Pas-de-porte / droit d\'entrée', $de1];
        $fpDef[] = ['Honoraires à la charge du preneur TTC', $ho1]; // PRENEUR : compté dans le total à verser
        $fpRows = ''; $fpTot = 0.0;
        foreach ($fpDef as $l) { $fpRows .= '<tr><td>' . bcp_e($l[0]) . '</td><td style="text-align:right;">' . ((float)$l[1] > 0 ? bcp_eur($l[1]) . ' €' : '……………') . '</td></tr>'; $fpTot += (float)$l[1]; }
        $fpRows .= '<tr><td><b>Total à verser à la signature</b></td><td style="text-align:right;"><b>' . ($fpTot > 0 ? bcp_eur($fpTot) . ' €' : '……………') . '</b></td></tr>';
        // Honoraires du BAILLEUR — POUR INFORMATION, entre parenthèses, NON compris dans le total.
        $hoB = $honoBailM !== null ? (float)$honoBailM * 1.20 : null;
        if ($hoB) $fpRows .= '<tr><td style="font-style:italic;color:#666;">(Honoraires à la charge du bailleur TTC — pour information)</td><td style="text-align:right;font-style:italic;color:#666;">(' . bcp_eur($hoB) . ' €)</td></tr>';
        $h .= '<p><b>Somme à verser par le PRENEUR à la signature (1ᵉʳ versement) :</b></p>';
        $h .= '<table class="tbl"><thead><tr><th>Nature</th><th style="text-align:right;width:28%;">Montant</th></tr></thead><tbody>' . $fpRows . '</tbody></table>';

        // DPE réel du bien (pour l'article 13) + liste des diagnostics annexés (article 27).
        $dpe = $ctx['dpe'] ?? [];
        $dpeTxt = '';
        if (!empty($dpe['classe'])) {
            $dpeTxt = 'classe ' . bcp_e($dpe['classe'])
                . (!empty($dpe['val']) ? ' (' . bcp_e($dpe['val']) . ' kWh/m²/an)' : '')
                . (!empty($dpe['ges']) ? ' — GES classe ' . bcp_e($dpe['ges']) : '')
                . (!empty($dpe['date']) ? ', réalisé le ' . bcp_e($dpe['date']) : '');
        }
        $diagList = 'Inventaire des charges et travaux ; Diagnostic de performance énergétique (DPE)'
            . ($dpeTxt ? ' — ' . $dpeTxt : '')
            . ' ; État des risques et pollutions (ERP) ; le cas échéant, constat de risque d\'exposition au plomb (CREP), diagnostic amiante (DAPP/DTA) et diagnostic termites ; État des lieux d\'entrée';

        // 11. Inventaire (catégories de charges — à la suite, dans le même document)
        $blk = '<b>……………</b>';
        $h .= '<h3>11. Inventaire</h3>';
        $h .= '<p><b>CATÉGORIES DE CHARGES, IMPÔTS, TAXES ET REDEVANCES AFFÉRENTES AUX BIENS LOUÉS OU À L\'IMMEUBLE OÙ ILS SE TROUVENT</b></p>';
        $h .= '<p class="clabel">Charges</p><ul>'
            . '<li>Les frais de consommation de l\'eau froide, des fluides, combustibles et toutes les énergies nécessaires à la production de l\'eau chaude, du chauffage, de la ventilation mécanique, de réfrigération des locaux privatifs, des locaux communs et des espaces communs (voiries, espaces verts, emplacements de stationnement…) sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais d\'exploitation, de maintenance, d\'entretien, de réparation et de remplacement des équipements qui sont rattachés à ces consommables sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais de consommation d\'énergie nécessaire à l\'éclairage des locaux privatifs et des locaux et espaces communs ainsi que les frais de remplacement, de maintenance, d\'entretien et d\'exploitation des équipements qui leur correspondent sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais d\'exploitation, d\'entretien, de réparation, de maintenance, de contrôle obligatoire et de remplacement des éléments d\'équipements de l\'immeuble et de toutes installations nécessaires à son bon fonctionnement tels qu\'ascenseur, monte charges, nacelles de nettoyage, groupe électrogène, sprinkler, chaudières, armoires électriques, VMC, etc. sont à la charge ' . $blk . '.</li>'
            . '<li>Les dépenses liées au nettoyage, à l\'hygiène et au maintien en état de propreté des parties communes, locaux communs et espaces communs (fourniture et entretien des équipements et des consommables nécessaires, élimination des déchets et des rejets, entretien et vidange des fosses d\'aisances…) sont à la charge ' . $blk . '.</li>'
            . '<li>Les dépenses liées à l\'évacuation des déchets et matériaux liés à l\'activité du LOCATAIRE sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais liés à la recherche de fuites de toute nature et de fissures des conduits de fumée ou de ventilation sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais d\'entretien, de réparation et de réfection des espaces extérieurs (voiries, aires de stationnement et de livraison, espaces verts…) en ce compris les frais d\'acquisition et de renouvellement des végétaux sont à la charge ' . $blk . '.</li>'
            . '<li>Les rémunérations, charges sociales et charges annexes du personnel affecté à l\'immeuble, et notamment au gardiennage, surveillance, au nettoyage, à la sécurité ou à la maintenance ainsi que les frais entraînés par le recours à des entreprises extérieures pour mener à bien ces tâches sont à la charge ' . $blk . '.</li>'
            . '</ul>';
        $h .= '<p class="clabel">Charges d\'honoraires</p><ul>'
            . '<li>Les honoraires de gestion des loyers des lieux loués ou de l\'immeuble faisant l\'objet du bail sont à la charge du BAILLEUR.</li>'
            . '<li>Les honoraires techniques sont à la charge ' . $blk . '.</li>'
            . '</ul>';
        $h .= '<p class="clabel">Impôts, taxes et redevances</p><ul>'
            . '<li>La contribution économique territoriale dont le redevable légal est le BAILLEUR ou le propriétaire du local ou de l\'immeuble est à la charge du BAILLEUR.</li>'
            . '<li>La contribution annuelle sur les revenus locatifs est à la charge du BAILLEUR.</li>'
            . '<li>La taxe foncière est à la charge : ' . $cb(false) . ' du BAILLEUR &nbsp;&nbsp; ' . $cb(true) . ' du PRENEUR &nbsp;&nbsp; ' . $cb(false) . ' Les parties conviennent de fixer le pourcentage de répartition de prise en charge de la taxe foncière dans ces proportions : ……………</li>'
            . '<li>La taxe ou la redevance d\'enlèvement des ordures ménagères, la taxe de balayage est à la charge du PRENEUR.</li>'
            . '<li>Les frais de gestion de la fiscalité locale directe afférente aux taxes réglées par le BAILLEUR sont à la charge du PRENEUR.</li>'
            . '<li>Les taxes et redevances, y compris d\'assainissement, dues sur les consommations en parties privatives, parties communes et sur les espaces verts liées à la consommation des fluides, combustibles et énergie sont à la charge du PRENEUR.</li>'
            . '<li>La taxe sur les bureaux (le cas échéant) est à la charge ' . $blk . '.</li>'
            . '<li>La taxe locale sur les enseignes et publicités extérieures est à la charge du PRENEUR.</li>'
            . '</ul>';
        $h .= '<p class="clabel">Autres charges</p><ul>'
            . '<li>Les assurances des lieux loués ou de l\'immeuble qui incombent au BAILLEUR sont à la charge ' . $blk . '.</li>'
            . '<li>Les assurances des lieux loués ou de l\'immeuble qui incombent au PRENEUR sont à la charge ' . $blk . '.</li>'
            . '<li>Les surprimes d\'assurances liées à l\'activité du PRENEUR sont à la charge ' . $blk . '.</li>'
            . '<li>Les assurances sur travaux à la charge du PRENEUR sont à la charge ' . $blk . '.</li>'
            . '<li>Les assurances sur travaux à la charge du BAILLEUR sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais d\'établissement des diagnostics obligatoires sont à la charge ' . $blk . '.</li>'
            . '<li>Les frais d\'établissement des autres diagnostics (accessibilité…) sont à la charge ' . $blk . '.</li>'
            . '<li>Les abonnements, les frais d\'exploitation, les travaux d\'entretien, de réparation et de remplacement des réseaux de communication électroniques sont à la charge ' . $blk . '.</li>'
            . '</ul>';
        $h .= '<p class="clabel">Charges de travaux</p><ul>'
            . '<li>Les dépenses relatives aux grosses réparations mentionnées à l\'article 606 du Code civil dans les lieux loués ou dans l\'immeuble dans lequel ils se trouvent sont à la charge du BAILLEUR.</li>'
            . '<li>Dès lors qu\'elles relèvent des grosses réparations mentionnées à l\'article 606 du Code civil : les dépenses relatives aux travaux ayant pour objet de remédier à la vétusté ou de mettre en accessibilité ou en conformité avec la réglementation les lieux loués ou l\'immeuble dans lequel ils se trouvent sont à la charge du BAILLEUR.</li>'
            . '<li>Les dépenses pour travaux d\'embellissement et d\'amélioration qui n\'excèdent pas le coût du remplacement à l\'identique et qui relèvent de l\'article 606 du Code civil sont à la charge du BAILLEUR.</li>'
            . '<li>Le cas échéant, les honoraires liés à la réalisation de tous les travaux sont à la charge du BAILLEUR.</li>'
            . '<li>Le cas échéant, les frais d\'assurance liés à la réalisation des travaux ci-avant mentionnés sont à la charge du BAILLEUR.</li>'
            . '<li>Dès lors qu\'elles ne relèvent pas des dépenses de réparation mentionnées à l\'article 606 du Code civil : celles relatives aux travaux de réfection, remise en état, réparation, même celles rendues nécessaires en raison de la vétusté, d\'un vice caché, de la mise en conformité avec la réglementation, de la mise en accessibilité, que ceux–ci soient afférents aux biens loués ou à l\'immeuble dans lequel ils se trouvent sont à la charge du PRENEUR.</li>'
            . '<li>celles relatives aux travaux, installations, transformations quelle qu\'en soit la nature, qui seraient imposés par les autorités administratives, la loi ou les règlements présents ou à venir, en raison de ses activités présentes ou futures sont à la charge du PRENEUR.</li>'
            . '<li>Les dépenses pour travaux d\'embellissement et d\'amélioration qui excèdent le coût du remplacement à l\'identique et qui relèvent de l\'article 606 du Code civil sont à la charge du PRENEUR.</li>'
            . '<li>Les dépenses pour travaux d\'embellissement et d\'amélioration qui ne relèvent pas de l\'article 606 du Code civil sont à la charge du PRENEUR.</li>'
            . '<li>Les dépenses pour travaux et réparations rendues nécessaires en raison d\'un défaut d\'entretien ou d\'exécution de travaux incombant au PRENEUR ou en cas de dégradations de son fait, de celui de sa clientèle ou de son personnel, que ces dépenses relèvent ou pas de l\'article 606 du Code civil sont à la charge du PRENEUR.</li>'
            . '<li>Les dépenses de recherche de fuites de toute nature ou de fissures des conduits de fumée ou de ventilation, que celles-ci soient afférentes aux biens loués ou à l\'immeuble dans lequel ils se trouvent sont à la charge du PRENEUR.</li>'
            . '</ul>';
        $h .= '<h3>12. Etat prévisionnel et récapitulatif des travaux</h3>';
        $h .= '<p>' . ($ctx['travaux_realises'] || $ctx['travaux_prevus'] ? ('Travaux réalisés dans les trois années écoulées : ' . ($ctx['travaux_realises'] ? bcp_e($ctx['travaux_realises']) : 'Néant') . '. Travaux envisagés dans les trois années à venir : ' . ($ctx['travaux_prevus'] ? bcp_e($ctx['travaux_prevus']) : 'Néant') . '.') : 'Le BAILLEUR déclare ne pas envisager de réaliser des travaux dans les trois années suivant celle de la signature du bail.') . '</p>';

        $h .= '<h3>13. Diagnostics et informations relatives aux locaux loués</h3>';
        $h .= '<p><b>13.1 Informations particulières relatives aux locaux loués.</b></p>';
        $h .= '<p><b>Relatives au bruit.</b> Le Bailleur déclare que les locaux loués ne sont pas situés à proximité d\'un aérodrome et que les biens loués ne sont pas classés en zone d\'exposition au bruit.</p>';
        $h .= '<p><b>Relatives à la récupération des eaux de pluie</b> (arrêté du 21 août 2008 pris en application de la loi du 30 décembre 2006). Le Bailleur déclare que les locaux loués ne comportent des équipements de récupération des eaux pluviales.</p>';
        $h .= '<p><b>13.2 Diagnostics techniques.</b></p>';
        $h .= '<p><b>13.2.1. DOSSIER DE DIAGNOSTICS TECHNIQUES.</b> UN DOSSIER DE DIAGNOSTICS TECHNIQUES EST ANNEXÉ AU PRÉSENT CONTRAT DE LOCATION ET COMPREND :</p>';
        $h .= '<p>- le diagnostic de performance énergétique prévu à l\'article L. 134-1 du code de la construction et de l\'habitation' . ($dpeTxt ? ' (' . $dpeTxt . ')' : '') . '. Le PRENEUR reconnaît avoir reçu l\'ensemble des informations concernant le diagnostic de performance énergétique relatif aux biens loués, dont le contenu est annexé au présent bail.<br>'
            . '- si les locaux comprennent une partie à usage d\'habitation, le constat des risques d\'exposition au plomb prévu aux articles L. 1334-5 et L. 1334-7 du code de la santé publique, lorsque l\'immeuble a été construit avant le 1er janvier 1949. Le PRENEUR reconnaît avoir reçu l\'ensemble des informations concernant le constat des risques d\'exposition au plomb relatif aux biens loués, dont le contenu est annexé au présent bail.<br>'
            . '- l\'état des risques naturels et technologiques (ERP)<br>'
            . '- le diagnostic termites (locaux situés dans une zone délimitée par le préfet en application de l\'article L. 133-5 du code de la construction et de l\'habitation)</p>';
        $h .= '<p>Les biens objet des présentes n\'ont pas fait l\'objet d\'un état parasitaire.</p>';
        $h .= '<p><b>13.2.2. INFORMATIONS RELATIVES À L\'AMIANTE POUR LES IMMEUBLES COLLECTIFS DONT LE PERMIS DE CONSTRUIRE A ÉTÉ DÉLIVRÉ AVANT LE 1ER JUILLET 1997.</b></p>';
        $h .= '<p><b>Parties privatives.</b> Le PRENEUR reconnaît avoir été informé de l\'existence d\'un dossier amiante sur les parties privatives qu\'il occupe (DAPP ou DTA). Sur demande écrite, le PRENEUR pourra venir consulter ce document auprès du bailleur ou de son mandataire.</p>';
        $h .= '<p><b>Parties communes.</b> Le PRENEUR reconnaît avoir été informé que le dossier technique amiante (DTA) sur les parties communes est tenu à disposition chez le syndic de la copropriété (selon ses propres modalités de consultation). Pour les immeubles en monopropriété, sur demande écrite, le PRENEUR pourra venir consulter ce document auprès du bailleur ou de son mandataire.</p>';
        $h .= '<p>Les frais d\'établissement de ces diagnostics seront supportés conformément aux conditions fixées dans l\'inventaire prévu à la clause 9. « CHARGES, IMPÔTS, TAXES ET REDEVANCES ».</p>';

        $h .= '<h3>13 BIS. Conditions particulières</h3>';
        $h .= '<p>' . ($ctx['cond_particulieres'] ? nl2br(bcp_e($ctx['cond_particulieres'])) : '…………………………………………………………') . '</p>';

        $h .= '<h3>14. Modalités de jouissance</h3>';
        $h .= '<p>Le présent bail est consenti et accepté sous les charges et conditions ordinaires et de droit en pareille matière et notamment sous celles suivantes que le PRENEUR s\'oblige à bien et fidèlement exécuter à peine de tous dépens et dommages-intérêts et même de résiliation des présentes.</p>';
        $h .= '<p class="clabel">1. CONDITIONS GÉNÉRALES DE JOUISSANCE</p>';
        $h .= '<p>Le PRENEUR fera son affaire personnelle de la garde et de la surveillance des locaux.</p>';
        $h .= '<p>Le PRENEUR devra jouir des biens loués raisonnablement, suivant leur destination, et se conformer à tous règlements qui s\'appliquent à l\'ensemble immobilier dans lequel il exerce et dont il reconnaît avoir eu connaissance.</p>';
        $h .= '<p>Le PRENEUR fera son affaire de l\'élimination des déchets liés à son activité. Il s\'oblige notamment à respecter la réglementation applicable en matière d\'évacuation des déchets et des matières dangereux, polluants ou obstruants. Le PRENEUR, qui s\'y oblige, s\'engage en de telles hypothèses à supporter seul toutes conséquences pécuniaires ou autres et ne pourra prétendre à aucun remboursement, indemnité ou avance de la part du BAILLEUR. Il restera garant vis-à-vis du BAILLEUR de toute action notamment en dommages et intérêts de la part des autres locataires ou voisins que pourraient provoquer l\'exercice de ses activités.</p>';
        $h .= '<p>Sans préjudice des stipulations ci dessus, en cas de réglementation présente ou future, relative à la santé, sécurité, hygiène de l\'immeuble ou de ses occupants, le BAILLEUR effectuera ou fera effectuer les recherches, diagnostics, travaux qui seraient imposés :</p>';
        $h .= '<p>- En cas de risque d\'accessibilité au plomb ou de contamination déclarée, le BAILLEUR informera le PRENEUR de la nécessité d\'effectuer les travaux prescrits par l\'autorité administrative. Dans le cas où l\'évacuation des locaux est rendue nécessaire par la nature des travaux, aucune indemnité ni réfaction du loyer n\'est due par le BAILLEUR autre que les dépenses relatives au relogement temporaire.<br>'
            . '- En cas de travaux préventifs ou d\'éradication des termites ou insectes xylophages, le BAILLEUR tient copie de l\'état parasitaire à la disposition du PRENEUR. Dans l\'hypothèse où l\'immeuble doit être totalement démoli, le bail est résolu de plein droit.</p>';
        $h .= '<p>Les dépenses relatives aux recherches, diagnostics et travaux nécessaires ci-avant mentionnés sont répartis entre le BAILLEUR et le PRENEUR conformément à ce qui est prévu à la clause 10. « DÉPENSES D\'ENTRETIEN ET DE RÉPARATIONS ».</p>';
        $h .= '<p>Le PRENEUR s\'engage à déclarer à la mairie la présence de termites dans l\'immeuble.</p>';
        $h .= '<p>Le PRENEUR veillera à ne rien faire qui puisse apporter un trouble de jouissance aux voisins et à n\'exercer aucune activité contraire aux bonnes mœurs.</p>';
        $h .= '<p>Le PRENEUR s\'engage à ne pas charger les planchers d\'un poids supérieur à celui qu\'ils peuvent supporter et en cas de doute de s\'assurer de ce poids auprès d\'un architecte. Il s\'interdit d\'installer et d\'utiliser des appareils à moteur qui produiraient des nuisances pour le voisinage.</p>';
        $h .= '<p>Le PRENEUR devra satisfaire à toutes les charges de ville, de police, réglementation sanitaire, voirie, salubrité, hygiène, ainsi qu\'à toutes celles pouvant résulter des plans d\'aménagement de la ville, et autres charges, dont les locataires sont ordinairement tenus, de manière à ce que le BAILLEUR ne puisse aucunement être inquiété ni recherché à ce sujet.</p>';
        $h .= '<p>Le PRENEUR fera son affaire personnelle pour toutes réclamations ou contestations qui pourraient survenir du fait de son activité dans les biens loués, de façon à ce que le BAILLEUR ne soit jamais inquiété ni recherché à ce sujet.</p>';
        $h .= '<p>Le PRENEUR s\'engage à maintenir les biens loués en état permanent d\'exploitation effective et normale, sauf les fermetures hebdomadaires et annuelles.</p>';
        $h .= '<p>Le PRENEUR souffrira tous travaux quelconques qui seraient exécutés dans les biens loués ou dans l\'immeuble dont ils dépendent. Il ne pourra prétendre à cette occasion à aucune indemnité ni réduction de loyer, quand bien même la durée des travaux excéderait vingt et un jours.</p>';
        $h .= '<p class="clabel">2. EMBELLISSEMENTS ET AMÉNAGEMENTS</p>';
        $h .= '<p>Le PRENEUR ne pourra effectuer aucuns travaux de transformation, changement de distribution sans accord préalable et écrit du BAILLEUR.</p>';
        $h .= '<p>En cas d\'autorisation du BAILLEUR pour effectuer de tels travaux, le PRENEUR devra les effectuer à ses risques et périls sans que le BAILLEUR puisse être inquiété ni recherché à ce sujet. Si ces travaux affectent le gros œuvre, ils devront être exécutés sous la surveillance d\'un architecte et garantis par une assurance dommages-ouvrage. Les honoraires d\'architecte et les frais d\'assurance dommages-ouvrages sont répartis conformément à la clause 10. « DÉPENSES D\'ENTRETIEN ET DE RÉPARATIONS ».</p>';
        $h .= '<p>Tout embellissement, amélioration et installation faits par le PRENEUR dans les lieux loués resteront à la fin du présent bail la propriété du BAILLEUR sans indemnité et devront être remis en bon état d\'entretien en fin de jouissance, sans préjudice du droit réservé au BAILLEUR d\'exiger la remise en l\'état primitif, pour tout ou partie, aux frais du PRENEUR.</p>';
        $h .= '<p>Le BAILLEUR a la faculté d\'exiger à tout moment, aux frais du PRENEUR, à l\'exception des travaux qu\'il aurait autorisés sans réserve, la remise immédiate des lieux en l\'état lorsque les transformations mettent en péril le bon fonctionnement des équipements ou la sécurité du local ou de l\'immeuble en général.</p>';
        $h .= '<p>Le PRENEUR devra déposer à ses frais tous coffrages, équipements, installations, décoration qu\'il aurait faits dont l\'enlèvement serait nécessaire notamment pour la recherche et la réparation de fuites de toute nature, de fissures des conduits de fumée ou de ventilation.</p>';
        $h .= '<p>Dans le cas où l\'immeuble est soumis au régime de la copropriété, préalablement à l\'exécution de tous travaux, le PRENEUR communiquera au BAILLEUR les éléments nécessaires à l\'obtention de l\'autorisation du syndicat des copropriétaires.</p>';
        $h .= '<p class="clabel">3. PUBLICITÉ</p>';
        $h .= '<p>Le PRENEUR aura le droit d\'installer, dans l\'emprise de sa façade commerciale, toute publicité extérieure indiquant sa dénomination et sa fonction, à condition qu\'elle respecte les règlements administratifs en vigueur et tous règlements qui s\'appliquent à l\'ensemble immobilier dans lequel il exerce et dont il reconnaît avoir eu connaissance. Il s\'engage à acquitter toutes taxes pouvant être dues à ce sujet.</p>';
        $h .= '<p>L\'installation sera faite aux frais du PRENEUR. Il devra l\'entretenir constamment en parfait état et sera seul responsable des accidents que sa pose ou son existence pourrait occasionner. En cas de restitution des biens, le PRENEUR devra faire disparaître toute trace de scellement après enlèvement desdites enseignes ou publicités.</p>';
        $h .= '<p class="clabel">4. VISITE DES LIEUX</p>';
        $h .= '<p>Le PRENEUR devra laisser le BAILLEUR, son mandataire, son architecte, tous entrepreneurs et ouvriers, et toutes personnes autorisées par lui, pénétrer dans les lieux loués, pour constater leur état quand le BAILLEUR le jugera à propos, sous réserve de prévenir le PRENEUR 48 heures à l\'avance sauf urgence.</p>';
        $h .= '<p>En cas de mise en vente des murs, le PRENEUR devra laisser visiter les biens loués durant les horaires d\'ouverture du commerce. En cas de relocation, le PRENEUR devra laisser visiter les biens loués suivant les mêmes modalités par le BAILLEUR, ou d\'éventuels candidats preneurs, dès la délivrance du congé donné par l\'une ou l\'autre des parties.</p>';
        $h .= '<p>Dans tous les cas, le PRENEUR souffrira l\'apposition d\'écriteaux ou d\'affiches annonçant la vente ou la location.</p>';

        // 15 → 30 (clauses — texte exact du modèle ; les articles vides restent en titre seul)
        $clauses = [
            '15. Clause de non-concurrence' => '',
            '16. Garnissement' => 'Le Preneur garnira les lieux et les tiendra constamment garnis pendant toute la durée du bail et de ses renouvellements éventuels, de meubles, matériels et marchandises, en qualité et valeur suffisantes pour répondre du paiement des loyers et accessoires et de l\'exécution des conditions et charges du présent bail.',
            '17. Autorisations administratives' => 'Pour l\'exercice de son activité, le PRENEUR devra se conformer scrupuleusement aux lois, prescriptions, règlements, et ordonnances en vigueur et applicables aux locaux loués (notamment en faisant effectuer par des entreprises agréées les vérifications et contrôles réglementaires de toutes installations équipant les locaux loués) en fournissant tous justificatifs au bailleur à sa première demande, notamment en ce qui concerne l\'exécution à ses frais et sous sa responsabilité par des entreprises et sous la direction des hommes de l\'art, de tous travaux quels qu\'ils soient, imposés par lesdites dispositions légales ou réglementaires, la voirie, l\'hygiène, les prescriptions des pompiers et du mandataire sécurité, les servitudes passives, la salubrité, la police, la sécurité et l\'inspection du travail, et d\'en supporter les frais y afférents de façon que le bailleur ne soit jamais inquiété ni recherché.</p><p>Le PRENEUR devra réaliser en cours de bail à ses seuls frais l\'ensemble des installations, travaux, aménagements nécessaires à l\'exercice de son activité, y compris ceux rendus nécessaires par la réglementation applicable.',
            '18. Assurances' => 'Le PRENEUR devra assurer et maintenir assurés, auprès d\'une compagnie notoirement solvable, les biens loués, les aménagements, les objets mobiliers, matériel et marchandises contre l\'incendie, les risques locatifs, les risques professionnels, le recours des voisins et des tiers, les dégâts des eaux, la recherche de fuites, les explosions, les bris de glace, le vandalisme, tous dommages matériels et immatériels et généralement tous les autres risques.</p><p>Si l\'activité exercée par le PRENEUR entraîne pour le BAILLEUR, directement ou indirectement, des surprimes d\'assurances, le PRENEUR sera tenu tout à la fois d\'indemniser le BAILLEUR du montant de la surprime par lui payée et, en outre, de le garantir contre toutes réclamations. Il devra justifier de tout à chaque réquisition du BAILLEUR. Le PRENEUR s\'engage, en cas de sinistre quelconque, à n\'exercer aucun recours en garantie contre le BAILLEUR et ses assureurs. En cas de sinistre, quelle qu\'en soit la cause, les sommes qui seront dues au PRENEUR par la ou les compagnies ou sociétés d\'assurances, formeront, aux lieu et place des objets mobiliers et du matériel, jusqu\'au remplacement et au rétablissement de ceux-ci, la garantie du BAILLEUR. Les présentes vaudront transport en garantie au BAILLEUR de toutes indemnités d\'assurance, jusqu\'à concurrence des sommes qui lui seraient dues, tous pouvoirs étant donnés au porteur d\'un exemplaire des présentes pour faire signifier le transport à qui besoin sera.</p><p>Le LOCATAIRE devra maintenir et renouveler ses assurances pendant toute la durée du bail, acquitter régulièrement les primes et cotisations et justifier du tout à toute réquisition du BAILLEUR et au moins annuellement, à la date anniversaire du bail, sans qu\'il lui en soit fait la demande. Le Bailleur conserve seulement l\'assurance propriétaire liée à l\'article 606 C. civ.',
            '19. Cession et sous-location' => 'Le PRENEUR ne pourra dans aucun cas et sous aucun prétexte, sous-louer en tout ou en partie, sous quelque forme que ce soit, les biens loués, les prêter, même à titre gratuit.</p><p>Cependant, le PRENEUR pourra, s\'il remplit les conditions légales, consentir une location-gérance du fonds de commerce par lui exploité et concéder au locataire-gérant un droit d\'occupation des lieux loués. Il devra notifier au BAILLEUR cette mise en location-gérance et lui remettre une copie du contrat.</p><p>Le PRENEUR ne pourra, en outre, céder son droit au présent bail, si ce n\'est à son successeur dans son commerce, mais en totalité seulement.</p><p>Le cédant, le cessionnaire de même que les successeurs de celui-ci, demeureront réciproquement et solidairement garants, du paiement des loyers ou accessoires, échus ou à échoir, impôts et taxes, charges, indemnités d\'occupation, complément de loyer, frais de poursuite, etc., d\'une façon générale de toutes sommes dues au titre du Bail ainsi que de l\'entière exécution des clauses du Bail et en résultant et ce, quelle que soit la période pendant laquelle le fonds aura été exploité par l\'un d\'entre eux. Cette garantie devra impérativement être rappelée dans l\'acte de cession.</p><p>Conformément aux dispositions de l\'article L.145-16-2 du Code de commerce, le BAILLEUR ne pourra invoquer la garantie du cédant que durant trois ans à compter de la date d\'effet de la cession du fonds de commerce ou du droit au bail, même si la cession est intervenue moins de trois ans avant l\'expiration du bail, ladite restriction ne s\'appliquant pas au cessionnaire et aux cessionnaires successifs qui demeureront réciproquement et solidairement garants respectivement du cédant et des cédants successifs.</p><p>Dans toutes les cessions, une copie de la cession enregistrée portant la signature manuscrite de chaque partie devra être remise au BAILLEUR, sans frais pour lui, dans le mois de la signature, et le tout à peine de nullité de la cession à l\'égard dudit BAILLEUR et de résiliation des présentes, si bon lui semble, le tout indépendamment de la signification prescrite par l\'article 1690 du Code civil.</p><p>A défaut d\'état des lieux réalisé lors de la cession, les parties conviennent de se rapporter à l\'état des lieux établi dans les conditions prévues à l\'article 8 du présent bail.',
            '20. Clause résolutoire' => 'Il est expressément convenu, qu\'à défaut de paiement d\'un seul terme de loyer ou à défaut de remboursement à leur échéance exacte de toutes sommes accessoires audit loyer, notamment provisions, frais, taxes, impositions, charges ou en cas d\'inexécution de l\'une quelconque des clauses et conditions du présent bail, celui-ci sera résilié de plein droit, si bon semble au BAILLEUR, un mois après un commandement de payer ou d\'exécuter demeuré infructueux, sans qu\'il soit besoin de former une demande en justice.</p><p>Ainsi, toutes les infractions du PRENEUR aux dispositions du présent bail, et ainsi toutes infractions liées au paiement des loyers, charges, impôts, dépôt de garantie, à la destination du bail, à l\'entretien et aux conditions générales de jouissance des lieux loués, aux aménagements réalisés, à l\'exercice du droit de visite du BAILLEUR, aux conditions d\'installation de publicités en extérieur, aux obligations du PRENEUR en matière d\'assurance, aux dispositions relatives à la cession et à la sous-location du présente bail, seront sanctionnées par le jeu de la présente clause résolutoire.</p><p>Dans le cas où le PRENEUR se refuserait à quitter les biens loués, son expulsion pourrait avoir lieu sur simple ordonnance de référé rendue par le président du tribunal judiciaire territorialement compétent et exécutoire par provisions, nonobstant appel.',
            '21. Clause pénale' => 'A défaut de paiement de toutes sommes à son échéance, notamment du loyer et de ses accessoires, et dès mise en demeure délivrée par le BAILLEUR ou son mandataire au PRENEUR, ou dès délivrance d\'un commandement de payer, ou encore après tout début d\'engagement d\'instance, les sommes dues par le PRENEUR seront automatiquement majorées de ' . $penal . ' % à titre d\'indemnité forfaitaire et ce, sans préjudice de tous frais, quelle qu\'en soit la nature, engagés pour le recouvrement des sommes ou de toutes indemnités qui pourraient être mises à la charge du PRENEUR.</p><p>En outre, en cas de résiliation judiciaire ou de plein droit du présent bail, le montant du dépôt de garantie restera acquis au BAILLEUR à titre d\'indemnité minimale en réparation du préjudice résultant de cette résiliation.',
            '22. Solidarité - Indivisibilité' => 'Les obligations résultant du présent bail pour le PRENEUR constitueront pour tous ses ayants droit et pour toutes personnes tenues au paiement et à l\'exécution, une charge solidaire et indivisible, notamment en cas de décès du PRENEUR avant la fin du bail. Il y aura solidarité et indivisibilité entre tous ses héritiers et représentants pour l\'exécution desdites obligations et, s\'il y a lieu de faire les significations prescrites par l\'article 877 du Code civil, le coût de ces significations sera supporté par ceux à qui elles seront faites. Les colocataires soussignés, désignés le «PRENEUR», reconnaissent expressément qu\'ils se sont engagés solidairement et que le BAILLEUR n\'a accepté de consentir le présent bail qu\'en considération de cette cotitularité solidaire et n\'aurait pas consenti la présente location à l\'un seulement d\'entre eux.</p><p>En conséquence, compte tenu de l\'indivisibilité du bail, tout congé pour mettre valablement fin au bail devra émaner de tous les colocataires et être donné pour la même date.',
            '23. Tolérances' => 'Il est formellement convenu que toutes les tolérances de la part du BAILLEUR relatives aux clauses et conditions énoncées ci dessus, quelles qu\'en aient pu être la fréquence et la durée, ne pourront jamais et en aucun cas être considérées comme apportant une modification ou une suppression de ces clauses et conditions, ni génératrices d\'un droit quelconque ; le BAILLEUR pourra toujours y mettre fin par tous les moyens.',
            '24. Droit de préférence au profit du PRENEUR' => 'Conformément aux dispositions de l\'article L. 145-46-1 du code de commerce, le PRENEUR bénéficie d\'un droit de préférence en cas de cession des locaux loués. Toutefois, cette disposition n\'est pas applicable en cas de :</p><p>- cession unique de plusieurs locaux d\'un ensemble commercial,<br>- cession unique de locaux commerciaux distincts,<br>- cession d\'un local commercial aux copropriétaires d\'un ensemble commercial,<br>- cession globale d\'un immeuble comprenant des locaux commerciaux,<br>- cession d\'un local au conjoint du BAILLEUR ou un ascendant ou un descendant du BAILLEUR ou de son conjoint.',
            '25. Protection des données personnelles des parties' => 'Vos données personnelles collectées dans le cadre du présent bail font l\'objet d\'un traitement nécessaire à son exécution. Elles sont susceptibles d\'être utilisées dans le cadre de l\'application de règlementations comme celle relative à la lutte contre le blanchiment des capitaux et le financement du terrorisme.</p><p>Vos données personnelles sont conservées pendant toute la durée de l\'exécution du présent bail, augmentée des délais légaux de prescription applicable. Elles sont destinées au service ……………</p><p>Pour la réalisation de la finalité des présentes, vos données sont, le cas échéant, susceptibles d\'être transmises, notamment :<br>- aux prestataires de la signature électronique et de la lettre recommandée électronique ;<br>- aux entreprises chargées de travaux sur l\'immeuble ;<br>- au commissaire de justice et à l\'avocat en cas de procédures ;<br>- aux organismes d\'assurances souscrites par le bailleur.</p><p>Il est précisé que dans le cadre de l\'exécution de leurs prestations, les tiers limitativement énumérés ci-avant n\'ont qu\'un accès limité aux données et ont l\'obligation de les utiliser en conformité avec les dispositions de la législation applicable en matière de protection des données personnelles.</p><p>Conformément à la loi informatique et libertés, vous bénéficiez d\'un droit d\'accès, de rectification, de suppression, d\'opposition et de portabilité de vos données en vous adressant à …………… ou un courrier à l\'adresse de l\'Agence indiquée en tête des présentes.</p><p>Toute réclamation pourra être introduite auprès de la Commission Nationale de l\'Informatique et des Libertés (www.cnil.fr).</p><p>Dans le cas où des coordonnées téléphoniques ont été recueillies, vous êtes informé(e)(s) de la faculté de vous inscrire sur la liste d\'opposition au démarchage téléphonique prévue en faveur des consommateurs (article L. 223-1 du code de la consommation).',
            '26. Renonciation à la révision pour imprévision' => 'Chacune des parties, pleinement informée des dispositions de l\'article 1195 du Code civil, accepte le risque lié à tout changement de circonstance imprévisible lors de la conclusion du présent contrat qui rendrait l\'exécution de celui-ci excessivement onéreuse pour elle. En conséquence, les parties, ensemble et séparément, renoncent expressément à exercer toute action en révision pour imprévision telle que définie audit article.',
            '27. Valeur contractuelle des annexes' => 'Les annexes font partie intégrante du présent bail et ont valeur contractuelle. Liste des annexes :</p><p>- Inventaire des charges et travaux,<br>- Diagnostics à lister : ' . ($dpeTxt ? 'Diagnostic de performance énergétique (DPE) ' . $dpeTxt . ' ; ' : '') . 'État des risques et pollutions (ERP) ; le cas échéant CREP, amiante (DAPP/DTA), termites,<br>- Etat des lieux d\'entrée,',
            '28. Association des locataires dans les centres commerciaux' => 'Le PRENEUR déclare être informé de l\'existence d\'une association des locataires du centre commercial, dont l\'objet est de promouvoir et d\'animer les activités commerciales au sein du centre. Le PRENEUR accepte librement d\'adhérer à cette association et de participer à ses activités.',
            '29. Médiation conventionnelle' => '',
            '30. Décret tertiaire' => 'Conformément aux dispositions de l\'article L. 111-10-3 du Code de la construction et de l\'habitation et du décret n° 2019-771 du 23 juillet 2019, les Parties conviennent de mettre en œuvre les actions nécessaires pour atteindre les objectifs de réduction de la consommation énergétique finale des bâtiments à usage tertiaire concernés par le présent contrat.</p><p>La présente clause s\'applique au bâtiment ou à la partie de bâtiment à usage tertiaire, dont la surface de plancher est supérieure ou égale à 1 000 m², et hébergeant des activités tertiaires marchandes ou non marchandes.</p><p>Les Parties conviennent que la répartition des obligations de réduction de la consommation énergétique sera négociée et définie dans un avenant au présent contrat, en tenant compte des responsabilités respectives du propriétaire et de l\'occupant.</p><p>Les Parties s\'engagent à mettre en œuvre les actions nécessaires pour atteindre les objectifs suivants :<br>- Réduction de 40 % de la consommation énergétique finale d\'ici 2030,<br>- Réduction de 50 % d\'ici 2040,<br>- Réduction de 60 % d\'ici 2050, par rapport à une année de référence choisie entre 2010 et 2019.</p><p>Les Parties peuvent choisir l\'une des deux méthodes suivantes pour atteindre les objectifs :<br>- Réduction en pourcentage par rapport à l\'année de référence,<br>- Atteinte d\'un niveau de consommation énergétique fixé en valeur absolue pour le type d\'activité concerné.</p><p>Les Parties s\'engagent à transmettre les données de consommation énergétique via la plateforme informatique dédiée (operat), conformément aux dispositions du décret tertiaire. Une attestation numérique sera établie pour justifier du respect des obligations.</p><p>En cas de non-respect des obligations de réduction énergétique, les Parties reconnaissent que des sanctions administratives peuvent être appliquées, notamment une mise en demeure par le préfet et la publication des mises en demeure restées sans effet sur un site internet des services de l\'État.</p><p>Les Parties conviennent que la preuve du respect des obligations sera annexée, à titre d\'information, à tout acte de vente ou de location concernant le bâtiment, conformément aux dispositions légales.',
        ];
        foreach ($clauses as $t => $txt) { $h .= '<h3>' . $t . '</h3>' . ($txt !== '' ? '<p>' . $txt . '</p>' : ''); }

        // 31. Honoraires de location — % preneur / % bailleur (montants calculés sur le loyer annuel HT).
        $fmtPct = fn($p) => rtrim(rtrim(number_format((float)$p, 2, ',', ''), '0'), ',');
        // Honoraires = service de l'agence → toujours TTC à 20 %. On affiche DEUX lignes (preneur
        // puis bailleur) en TTC, jamais le total.
        $honoTtcF = 1.20;
        $honoPrenTTC = $honoPrenM !== null ? (float)$honoPrenM * $honoTtcF : null;
        $honoBailTTC = $honoBailM !== null ? (float)$honoBailM * $honoTtcF : null;
        $h .= '<h3>31. Honoraires de location</h3>';
        $h .= '<p>Les parties reconnaissent que les présentes ont été négociées par l\'Agence, que les parties déclarent en conséquence bénéficiaire du montant de la rémunération convenue conformément au mandat écrit signé' . ($ctx['numero_bail'] ? ' portant le numéro ' . bcp_e($ctx['numero_bail']) : '') . '. Honoraires de location, calculés sur le loyer annuel HT et répartis comme suit :</p>';
        $h .= '<p>&mdash; À la charge du PRENEUR (locataire) : ' . ($hpPren !== null ? $fmtPct($hpPren) . ' % soit ' : '…… % soit ') . ($honoPrenTTC !== null ? '<b>' . bcp_eur($honoPrenTTC) . ' € TTC</b>' : '……………') . '<br>'
            . '&mdash; À la charge du BAILLEUR : ' . ($hpBail !== null ? $fmtPct($hpBail) . ' % soit ' : '…… % soit ') . ($honoBailTTC !== null ? '<b>' . bcp_eur($honoBailTTC) . ' € TTC</b>' : '……………') . '</p>';

        // 32. Frais
        $h .= '<h3>32. Frais</h3><p>Tous les frais et droits des présentes, à l\'exception des honoraires de location dont les modalités d\'imputation sont définies ci-dessus, seront supportés par le PRENEUR qui s\'y oblige.</p>';

        // 33. Election de domicile
        $h .= '<h3>33. Élection de domicile - Attribution de juridiction</h3>';
        $h .= '<p>Pour l\'exécution des présentes et de leurs suites, les parties font élection de domicile, savoir : le BAILLEUR, à l\'adresse indiquée en tête des présentes ; le PRENEUR, dans les lieux loués. Tous les litiges à survenir entre les parties seront de la compétence exclusive des tribunaux du ressort de la situation de l\'immeuble.</p>';

        // ── Date et signatures (bloc commun) — tracés incrustés si signé ──
        $sigs = $ctx['signatures'] ?? [];
        $findSig = function (string $role) use ($sigs): ?array {
            foreach ($sigs as $s) { if (($s['role_code'] ?? '') === $role && ($s['statut'] ?? '') === 'signe') return $s; }
            return null;
        };
        $sigCell = function (?array $sig, string $mention = 'Lu et approuvé'): string {
            if ($sig && !empty($sig['signature_data']) && strncmp((string)$sig['signature_data'], 'data:image', 10) === 0) {
                $dt = bcp_date($sig['signed_at'] ?? null);
                $out = '<span class="mut">« ' . $mention . ' »</span><br><img src="' . $sig['signature_data'] . '" style="max-height:64px;max-width:190px;"><br>'
                     . '<span class="mut">' . bcp_e((string)($sig['nom_signataire'] ?? '')) . ($dt ? ' &mdash; signé le ' . bcp_e($dt) : '') . '</span>';
                return $out;
            }
            return 'Signature précédée de la mention « ' . $mention . ' »<br><br><br>………………………………';
        };
        $sigPreneur = $findSig('preneur'); $sigCaution = $findSig('caution'); $sigBailleur = $findSig('mandataire') ?: $findSig('bailleur');
        $lieu = $ge['ville_sig'] ?: '……………………';
        $anySigned = $sigPreneur || $sigCaution || $sigBailleur;
        $dateFait = $anySigned ? (bcp_date(($sigPreneur['signed_at'] ?? null) ?: ($sigBailleur['signed_at'] ?? null)) ?: '……………………') : '……………………';
        $h .= '<h3>Date et signatures</h3>';
        $h .= '<div class="sign"><p>Fait à ' . bcp_e($lieu) . ' et signé électroniquement par l\'ensemble des Parties, chacune d\'elles en conservant un exemplaire original sur un support durable garantissant l\'intégrité de l\'acte' . ($anySigned ? ' — le ' . bcp_e($dateFait) : '') . '.</p>';
        $h .= '<table class="sigtbl"><tr>'
            . '<td><b>LE BAILLEUR</b><br><span class="mut">(ou son mandataire dûment habilité)</span><br><br>' . $sigCell($sigBailleur) . '</td>'
            . '<td><b>LE PRENEUR</b><br><span class="mut">&nbsp;</span><br><br>' . $sigCell($sigPreneur) . '</td>'
            . '</tr>'
            . (!empty($gar['present']) ? '<tr><td colspan="2" style="padding-top:14px;"><b>LA CAUTION</b> <span class="mut">(bon pour caution solidaire)</span><br><br>' . $sigCell($sigCaution, 'Bon pour caution solidaire, lu et approuvé') . '</td></tr>' : '')
            . '</table></div>';

        $h .= '</div>';
        return $h;
    }

    /** Construit le PDF et renvoie le chemin du fichier temporaire. */
    function bail_commercial_build_pdf(PDO $pdo, int $bailId, ?bool $forceProjet = null): string
    {
        $ctx = bail_commercial_pdf_context($pdo, $bailId);
        if ($ctx === null) throw new RuntimeException('Bail #' . $bailId . ' introuvable.');

        // Signatures électroniques (tracés PNG) — pour incrustation dans le bloc signatures + paraphe.
        $ctx['signatures'] = [];
        try {
            // photo_preuve peut ne pas exister (migration 20260707c non passée) → repli sans la colonne.
            try {
                $qs = $pdo->prepare("SELECT role_code, nom_signataire, signature_data, photo_preuve, statut, ip, signed_at
                                       FROM bail_signatures WHERE id_bail = ? ORDER BY id ASC");
                $qs->execute([$bailId]);
            } catch (Throwable) {
                $qs = $pdo->prepare("SELECT role_code, nom_signataire, signature_data, statut, ip, signed_at
                                       FROM bail_signatures WHERE id_bail = ? ORDER BY id ASC");
                $qs->execute([$bailId]);
            }
            $ctx['signatures'] = $qs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { /* table absente / bail non signé → aucun tracé */ }
        // Paraphe (initiales du preneur) répété en pied de page une fois signé.
        $paraphe = '……';
        foreach ($ctx['signatures'] as $s) {
            if (($s['role_code'] ?? '') === 'preneur' && ($s['statut'] ?? '') === 'signe') {
                $ini = '';
                foreach (preg_split('/\s+/', trim((string)($s['nom_signataire'] ?? ''))) as $w) {
                    if ($w !== '') $ini .= mb_strtoupper(mb_substr($w, 0, 1));
                }
                if ($ini !== '') $paraphe = $ini;
                break;
            }
        }

        // Filigrane PROJET : par défaut selon le statut ; forçable via le toggle (true = filigrané,
        // false = version définitive sans filigrane), quel que soit le statut.
        $withProjet = $forceProjet !== null
            ? $forceProjet
            : in_array($ctx['statut'], ['projet', 'envoye', 'brouillon'], true);
        $body = bail_commercial_corps_fnaim($ctx);   // modèle FNAIM exact + annexe (un seul document)

        $css = '<style>
            body{font-family:"Times",serif;font-size:10.5pt;color:#1c2226;line-height:1.5;}
            h1{font-size:16pt;text-align:center;letter-spacing:1px;margin:0 0 4px;}
            .sub{text-align:center;font-size:8.5pt;font-style:italic;color:#555;margin:0 0 2px;}
            .ref{text-align:center;font-size:8.5pt;color:#777;margin:0 0 14px;}
            h2{font-size:11pt;color:#2c4b4d;border-bottom:0.6pt solid #cddcdc;padding-bottom:2px;margin:14px 0 4px;}
            h3{font-size:10pt;color:#243B5C;background:#eef2f6;padding:3px 7px;margin:11px 0 4px;}
            .clabel{font-weight:bold;color:#2c4b4d;margin:8px 0 2px;}
            ul{margin:3px 0 8px 0;padding-left:18px;} li{margin:2px 0;text-align:justify;}
            p{margin:3px 0 7px;text-align:justify;}
            .qual{font-style:italic;color:#555;}
            .sub2{font-size:8.5pt;font-style:italic;color:#666;margin:0 0 6px;}
            .mut{font-size:8.5pt;font-style:italic;color:#888;}
            .tbl{width:100%;border-collapse:collapse;font-size:9pt;margin:4px 0 10px;}
            .tbl th{background:#e3ecec;text-align:left;padding:5px 7px;border:0.5pt solid #b9cccc;font-size:8.5pt;text-transform:uppercase;}
            .tbl td{padding:4px 7px;border:0.5pt solid #cddada;vertical-align:top;}
            .tbl td.who{text-align:center;white-space:nowrap;width:22%;}
            .sign{margin-top:18px;}
            .sigtbl{width:100%;margin-top:8px;}
            .sigtbl td{width:50%;vertical-align:top;padding:8px 10px;font-size:9.5pt;}
        </style>';

        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Autoload Composer introuvable (mPDF non installé)');
        require_once $autoload;
        if (!class_exists('\\Mpdf\\Mpdf')) throw new RuntimeException('mPDF introuvable');

        $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 16,
            'margin_bottom' => 18,
            'margin_left'   => 16,
            'margin_right'  => 16,
            'tempDir'       => $tmpDir,
            'default_font'  => 'dejavusans',
        ]);
        $mpdf->SetTitle('Bail commercial' . ($ctx['numero_bail'] ? ' ' . $ctx['numero_bail'] : ''));
        $mpdf->SetAuthor($ctx['gestionnaire']['raison'] ?: 'MaBoxImmo');
        $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:7.5pt;color:#999;border-top:0.4pt solid #ddd;padding-top:3px;">'
            . 'Bail commercial' . ($ctx['numero_bail'] ? ' &mdash; ' . bcp_e($ctx['numero_bail']) : '')
            . ' &mdash; page {PAGENO}/{nbpg} &mdash; paraphe : ' . bcp_e($paraphe) . '</div>');

        if ($withProjet) {
            $mpdf->SetWatermarkText('PROJET');
            $mpdf->showWatermarkText = true;
            $mpdf->watermarkTextAlpha = 0.08;
            $mpdf->watermark_font = 'DejaVuSans';
        }

        $mpdf->WriteHTML($css . $body);

        $tmp = sys_get_temp_dir() . '/bail_' . $bailId . '_' . ($withProjet ? 'projet' : 'def') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $mpdf->Output($tmp, \Mpdf\Output\Destination::FILE);
        return $tmp;
    }
}
