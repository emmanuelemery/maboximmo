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
    function bail_commercial_pdf_context(PDO $pdo, int $bailId): ?array
    {
        $sql = "SELECT bb.*,
            b.reference_bien, b.designation, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
            b.code_postal AS bien_cp, b.surface_habitable, b.numero_lot, b.id_immeuble,
            b.description AS bien_description, b.etage AS bien_etage,
            b.bien_en_copropriete, b.lot_tantiemes, b.copro_nb_lots,
            b.id_societe AS bien_soc, b.id_agence AS bien_age,
            i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
            p.id AS proprio_id, p.id_tiers AS proprio_tiers_id,
            COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
            COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom
            FROM bien_baux bb
            INNER JOIN biens b        ON b.id = bb.id_bien
            LEFT JOIN immeubles i     ON i.id = b.id_immeuble
            LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
            LEFT JOIN tiers tp        ON tp.id = p.id_tiers
            WHERE bb.id = ? LIMIT 1";
        $st = $pdo->prepare($sql); $st->execute([$bailId]);
        $bail = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bail) return null;

        // Société gestionnaire (jamais en dur)
        $soc = [];
        if (!empty($bail['bien_soc'])) {
            $q = $pdo->prepare("SELECT raison_sociale,nom,forme_juridique,capital_social,siren,siret,adresse_1,code_postal,ville,carte_pro_numero,numero_carte_t,carte_pro_cci,cci_carte_t,assurance_rcp,garantie_financiere,rib_emetteur_iban,rib_emetteur_bic,rib_emetteur_nom FROM societes WHERE id=?");
            $q->execute([(int)$bail['bien_soc']]); $soc = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $age = [];
        if (!empty($bail['bien_age'])) {
            $q = $pdo->prepare("SELECT nom_agence,adresse_1,code_postal,ville,rcs,iban,bic,banque_nom FROM agences WHERE id=?");
            $q->execute([(int)$bail['bien_age']]); $age = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $proprioNom = $bail['proprio_tiers_nom'] ?: $bail['proprio_nom_legacy'] ?: '';
        $loyerM = (float)($bail['loyer_mensuel_hc'] ?? 0);

        return [
            'statut'       => (string)$bail['statut'],
            'numero_bail'  => (string)($bail['numero_bail'] ?? ''),
            'proprio_nom'  => (string)$proprioNom,
            'bailleur_rep' => trim((string)($bail['bailleur_representant_nom'] ?? '') . (($bail['bailleur_representant_qualite'] ?? '') ? ' (' . $bail['bailleur_representant_qualite'] . ')' : '')),
            'bien_ref'     => (string)($bail['reference_bien'] ?: $bail['designation'] ?: ('Bien #' . $bail['id_bien'])),
            'bien_adresse' => trim((string)($bail['bien_adresse'] ?? '') . ' ' . ($bail['bien_cp'] ?? '') . ' ' . ($bail['bien_ville'] ?? '')),
            'immeuble'     => (string)($bail['nom_immeuble'] ?: $bail['imm_adresse'] ?: ''),
            'numero_lot'   => (string)($bail['numero_lot'] ?? ''),
            'surface'      => (float)($bail['surface_habitable'] ?? 0),
            'bien_description' => (string)($bail['bien_description'] ?? ''),
            'bien_etage'   => (($bail['bien_etage'] ?? null) !== null && $bail['bien_etage'] !== '' ? ((int)$bail['bien_etage'] === 0 ? 'rez-de-chaussée' : (int)$bail['bien_etage'] . 'ᵉ étage') : ''),
            'bien_copro'   => (!empty($bail['bien_en_copropriete']) ? 'bien en copropriété' . (!empty($bail['lot_tantiemes']) ? ' (' . (int)$bail['lot_tantiemes'] . ' / ' . (int)($bail['copro_nb_lots'] ?: 0) . ' tantièmes)' : '') : ''),
            'cond_particulieres' => (string)($bail['conditions_particulieres'] ?? ''),
            'cond_loyer'   => (string)($bail['conditions_particulieres_loyer'] ?? ''),
            'preneur' => [
                'type'      => ($bail['locataire_type'] ?? 'societe') === 'physique' ? 'physique' : 'societe',
                'raison'    => (string)($bail['locataire_raison_sociale'] ?? ''),
                'siren'     => (string)($bail['locataire_siren'] ?? ''),
                'nom'       => trim((string)($bail['locataire_prenom'] ?? '') . ' ' . ($bail['locataire_nom'] ?? '')),
                'rep'       => (string)($bail['locataire_representant_nom'] ?? ''),
                'rep_q'     => (string)($bail['locataire_representant_qualite'] ?? ''),
                'email'     => (string)($bail['locataire_email'] ?? ''),
                'tel'       => (string)($bail['locataire_telephone'] ?? ''),
                'adresse'   => (string)($bail['locataire_adresse'] ?? ''),
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
                'rib_iban'  => (string)(($soc['rib_emetteur_iban'] ?? '') ?: ($age['iban'] ?? '')),
                'rib_nom'   => (string)(($soc['rib_emetteur_nom'] ?? '') ?: ($age['banque_nom'] ?? '')),
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
            ],
        ];
    }

    /** Corps HTML complet (articles détaillés + annexes + signatures) pour mPDF. */
    function bail_commercial_articles_html(array $ctx): string
    {
        $ge = $ctx['gestionnaire']; $pr = $ctx['preneur']; $c = $ctx['cond'];
        $mut = fn($v) => $v !== null && $v !== '' ? $v : '<span style="color:#999">………………</span>';

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
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['date_effet_raw'])) {
            $ts = strtotime($c['date_effet_raw']); $y=(int)date('Y',$ts); $m=(int)date('n',$ts); $d=(int)date('j',$ts);
            if ($c['perio'] === 'trimestrielle') {
                $qs = intdiv($m-1,3)*3+1; $start=mktime(0,0,0,$qs,1,$y); $end=mktime(0,0,0,$qs+3,0,$y);
                $tot=(int)round(($end-$start)/86400)+1; $rem=(int)round(($end-$ts)/86400)+1; $ratio=$tot>0?$rem/$tot:1; $prLabel='trimestre';
            } else {
                $dim=(int)date('t',$ts); $ratio=($dim-$d+1)/$dim; $prLabel='mois';
            }
        }
        $decompte = [];
        if ($loyerMn)  $decompte[] = ['1ᵉʳ loyer' . ($prLabel?' (prorata '.$prLabel.')':'') . ($tvaOn?' TTC':' HT'), $ttc($loyerMn*$mult*$ratio)];
        if ($chargesMn)$decompte[] = ['Provision charges courantes' . ($prLabel?' (prorata)':'') . ($tvaOn?' TTC':''), $ttc($chargesMn*$mult*$ratio)];
        if ($tfMn)     $decompte[] = ['Provision taxe foncière' . ($prLabel?' (prorata)':'') . ($tvaOn?' TTC':''), $ttc($tfMn*$mult*$ratio)];
        if ($techMn)   $decompte[] = ['Honoraires gestion technique ' . rtrim(rtrim(number_format($techPct,2,',',''),'0'),',') . ' %' . ($prLabel?' (prorata)':'') . ($tvaOn?' TTC':''), $ttc($techMn*$mult*$ratio)];
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
        $h .= '<table class="tbl"><thead><tr><th>Période</th><th>Travaux</th><th>Coût / à la charge de</th></tr></thead><tbody>'
            . '<tr><td>Trois années écoulées</td><td>Récapitulatif des travaux réalisés</td><td>Néant / à compléter</td></tr>'
            . '<tr><td>Trois années à venir</td><td>État prévisionnel des travaux envisagés</td><td>Néant / à compléter</td></tr>'
            . '</tbody></table>';
        $h .= '<p class="mut">Le Bailleur informera le Preneur, en cours de bail, de tout nouveau travaux envisagé et de son coût prévisionnel.</p>';

        // ── Signatures ──
        $lieu = $ge['ville_sig'] ?: '……………………';
        $h .= '<div class="sign">';
        $h .= '<p>Fait à ' . bcp_e($lieu) . ', le ……………………, en deux exemplaires originaux, dont un remis à chaque partie.</p>';
        $h .= '<table class="sigtbl"><tr>'
            . '<td><b>LE BAILLEUR</b><br><span class="mut">(ou son mandataire)</span><br><br>Signature précédée de la mention « Lu et approuvé »<br><br><br>………………………………</td>'
            . '<td><b>LE PRENEUR</b><br><span class="mut">&nbsp;</span><br><br>Signature précédée de la mention « Lu et approuvé »<br><br><br>………………………………</td>'
            . '</tr></table>';
        $h .= '</div>';

        $h .= '</div>';
        return $h;
    }

    /** Construit le PDF et renvoie le chemin du fichier temporaire. */
    function bail_commercial_build_pdf(PDO $pdo, int $bailId, ?bool $forceProjet = null): string
    {
        $ctx = bail_commercial_pdf_context($pdo, $bailId);
        if ($ctx === null) throw new RuntimeException('Bail #' . $bailId . ' introuvable.');

        // Filigrane PROJET : par défaut selon le statut ; forçable via le toggle (true = filigrané,
        // false = version définitive sans filigrane), quel que soit le statut.
        $withProjet = $forceProjet !== null
            ? $forceProjet
            : in_array($ctx['statut'], ['projet', 'envoye', 'brouillon'], true);
        $body = bail_commercial_articles_html($ctx);

        $css = '<style>
            body{font-family:"Times",serif;font-size:10.5pt;color:#1c2226;line-height:1.5;}
            h1{font-size:16pt;text-align:center;letter-spacing:1px;margin:0 0 4px;}
            .sub{text-align:center;font-size:8.5pt;font-style:italic;color:#555;margin:0 0 2px;}
            .ref{text-align:center;font-size:8.5pt;color:#777;margin:0 0 14px;}
            h2{font-size:11pt;color:#2c4b4d;border-bottom:0.6pt solid #cddcdc;padding-bottom:2px;margin:14px 0 4px;}
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
            . ' &mdash; page {PAGENO}/{nbpg} &mdash; paraphe : ……</div>');

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
