<?php
/**
 * inc/bail_habitation_pdf.php — Générateur du BAIL DE LOCATION DE LOGEMENT NU (loi n° 89-462 du
 * 6 juillet 1989, modèle FNAIM). Reproduit le document VERBATIM (aperçu = PDF), avec les champs
 * à remplir issus de bien_baux (bail_nature = 'habitation').
 *
 * Réutilise le socle commercial : helpers bcp_e/bcp_eur/bcp_date, le bloc MANDATAIRE (société/
 * agence, carte pro, garantie, RCP) et l'adresse du bien via bail_commercial_pdf_context().
 *
 * API :
 *   bail_habitation_context(PDO, int $bailId): ?array
 *   bail_habitation_corps(array $ctx): string        // I → XV + DATE/SIGNATURES (verbatim)
 *   bail_habitation_build_pdf(PDO, int, ?bool $forceProjet=null): string  // chemin PDF temporaire
 *
 * Les ANNEXES (décrets 87-712 / 87-713 + notice légale) sont dans inc/bail_habitation_annexes.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/bail_commercial_pdf.php';   // bcp_e / bcp_eur / bcp_date + contexte partagé

if (!function_exists('bail_habitation_context')) {
    function bail_habitation_context(PDO $pdo, int $bailId): ?array
    {
        $row = bail_commercial_bail_row($pdo, $bailId);
        if (!$row) return null;
        // Bloc mandataire (Régie EMERY) + adresse bien : repris du contexte commercial (identique).
        $cc = bail_commercial_pdf_context($pdo, $bailId) ?: [];
        $ge = $cc['gestionnaire'] ?? [];

        // Bailleur = propriétaire du bien (tiers en source unique).
        $bailleur = ['nom' => '', 'adresse' => ''];
        try {
            $q = $pdo->prepare("SELECT COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, TRIM(CONCAT_WS(' ', tp.civilite, tp.prenom, tp.nom)), NULLIF(p.societe,''), TRIM(CONCAT_WS(' ', p.prenom, p.nom))) AS nom,
                                       COALESCE(NULLIF(tp.adresse_ligne1,''), p.adresse_1) AS adr, COALESCE(tp.code_postal,p.code_postal) cp, COALESCE(tp.ville,p.ville) ville
                                  FROM biens b LEFT JOIN proprietaires p ON p.id=b.id_proprietaire LEFT JOIN tiers tp ON tp.id=p.id_tiers
                                 WHERE b.id=? LIMIT 1");
            $q->execute([(int)($row['id_bien'] ?? 0)]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $bailleur['nom']     = trim((string)($r['nom'] ?? ''));
                $bailleur['adresse'] = trim((string)($r['adr'] ?? '') . ' ' . ($r['cp'] ?? '') . ' ' . ($r['ville'] ?? ''));
            }
        } catch (Throwable) {}
        // Le représentant du bailleur saisi sur le bail PRIME (ex. gérant décédé).
        if (trim((string)($row['bailleur_representant_nom'] ?? '')) !== '') {
            $bailleur['nom'] = trim((string)$row['bailleur_representant_nom']);
        }

        return [
            'row'         => $row,
            'statut'      => (string)($row['statut'] ?? 'projet'),
            'numero_bail' => (string)($row['numero_bail'] ?? $row['reference_bail'] ?? ''),
            'mandataire'  => $ge,
            'bailleur'    => $bailleur,
            'bien_adresse'=> (string)($cc['bien_adresse'] ?? ''),
            'immeuble'    => (string)($cc['immeuble'] ?? ''),
        ];
    }
}

if (!function_exists('bail_habitation_corps')) {
    function bail_habitation_corps(array $ctx): string
    {
        $r  = $ctx['row']; $ge = $ctx['mandataire'];
        $B  = fn($v) => '<b>' . bcp_e((string)$v) . '</b>';
        // Champ à remplir : valeur si présente, sinon pointillés (comme le modèle FNAIM vierge).
        $F  = fn($v, $len = 14) => trim((string)$v) !== '' ? bcp_e((string)$v) : str_repeat('…', $len);
        $E  = fn($v) => ($v !== null && $v !== '' && is_numeric($v)) ? number_format((float)$v, 2, ',', ' ') . ' €' : '…………… €';
        $D  = fn($v) => bcp_date($v) ?: '……………';
        $rowNum = fn($k) => isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null ? (float)$r[$k] : null;

        // ── Bloc MANDATAIRE (Régie EMERY) — carte pro / garantie / RCP / FNAIM ──
        $mand = $B($ge['raison'] ?? 'REGIE EMERY') . ', ci-après désignée « l\'Agence » ou « le Mandataire », sis ' . $F($ge['age_adr'] ?? $ge['adresse'] ?? '')
              . ', établissement de la société ' . $B($ge['raison'] ?? '') . ($ge['forme'] ? ', ' . bcp_e($ge['forme']) : '')
              . ($ge['capital'] ? ' au capital de ' . bcp_e((string)$ge['capital']) . ' euros' : '')
              . ($ge['adresse'] ? ', dont le siège social est situé ' . bcp_e($ge['adresse']) : '')
              . ($ge['siren'] ? ', immatriculée au RCS sous le n° ' . bcp_e($ge['siren']) : '')
              . ($ge['carte'] ? ', titulaire de la carte professionnelle portant la mention « Transaction sur immeubles et fonds de commerce » n° ' . bcp_e($ge['carte'])
                    . ($ge['carte_cci'] ? ' délivrée par ' . bcp_e($ge['carte_cci']) : '') : '')
              . (!empty($ge['representant']) ? ', représentée par ' . bcp_e($ge['representant']) . ($ge['representant_qualite'] ? ' ' . bcp_e($ge['representant_qualite']) : '') . ', dûment habilité(e) à l\'effet des présentes' : '')
              . ($ge['garantie'] ? ', adhérente de la caisse de Garantie ' . bcp_e($ge['garantie']) : '')
              . ($ge['rcp'] ? ', titulaire d\'une assurance en responsabilité civile professionnelle ' . bcp_e($ge['rcp']) : '')
              . ', adhérent de la Fédération Nationale de l\'Immobilier (FNAIM), dont l\'activité est régie par la loi n° 70-9 du 2 janvier 1970 (dite « loi Hoguet ») et son décret d\'application n° 72-678 du 20 juillet 1972.';

        $h  = '<div class="doc">';
        $h .= '<h1>BAIL DE LOCATION OU DE COLOCATION DE LOGEMENT NU</h1>';
        $h .= '<p class="sub">Soumis au titre I<sup>er</sup> de la loi n° 89-462 du 6 juillet 1989 tendant à améliorer les rapports locatifs et portant modification de la loi n° 86-1290 du 23 décembre 1986</p>';
        if ($ctx['numero_bail']) $h .= '<p class="ref">Référence : ' . bcp_e($ctx['numero_bail']) . '</p>';

        // ── I. DÉSIGNATION DES PARTIES ──
        $h .= '<h2>I. DÉSIGNATION DES PARTIES</h2>';
        $h .= '<p>Le présent contrat est conclu entre les soussignés :</p>';
        $h .= '<p class="clabel">Pour le bailleur</p>';
        $h .= '<p>' . $F($ctx['bailleur']['nom'] ?? '', 20) . ($ctx['bailleur']['adresse'] ? ', demeurant ' . bcp_e($ctx['bailleur']['adresse']) : ', demeurant ……………') . ',<br><span class="qual">Ci-après « le BAILLEUR », d\'une part,</span></p>';
        $h .= '<p class="clabel">Représenté(e)(s) par :</p><p>' . $mand . '</p>';
        $h .= '<p class="clabel">Le Locataire</p>';
        $locNom = trim((string)($r['locataire_raison_sociale'] ?? '') ?: trim((string)($r['locataire_prenom'] ?? '') . ' ' . (string)($r['locataire_nom'] ?? '')));
        $h .= '<p>' . $F($locNom, 20)
            . ($r['locataire_date_naissance'] ? ' né(e) le ' . $D($r['locataire_date_naissance']) : ' né(e) le ……………')
            . ($r['locataire_lieu_naissance'] ? ' à ' . bcp_e((string)$r['locataire_lieu_naissance']) : ' à ……………')
            . ($r['locataire_nationalite'] ? ', de nationalité ' . bcp_e((string)$r['locataire_nationalite']) : ', de nationalité ……………')
            . ($r['locataire_adresse'] ? ', demeurant ' . bcp_e((string)$r['locataire_adresse']) : ', demeurant ……………') . ',<br>'
            . '<span class="qual">Ci-après « le LOCATAIRE », d\'autre part,</span></p>';
        $h .= '<h2>IL A ÉTÉ CONVENU CE QUI SUIT</h2>';

        // ── II. Objet du contrat ──
        $h .= '<h3>II. Objet du contrat</h3>';
        $h .= '<p class="clabel">Désignation des locaux</p>';
        $h .= '<p>Rappel : un logement décent doit respecter les critères minimaux de performance suivants : a) En France métropolitaine : i) à compter du 1<sup>er</sup> janvier 2025, le niveau de performance minimal du logement correspond à la classe F du DPE ; ii) à compter du 1<sup>er</sup> janvier 2028, à la classe E du DPE ; iii) à compter du 1<sup>er</sup> janvier 2034, à la classe D du DPE. b) En Guadeloupe, en Martinique, en Guyane, à La Réunion et à Mayotte : i) à compter du 1<sup>er</sup> janvier 2028, à la classe F du DPE ; ii) à compter du 1<sup>er</sup> janvier 2031, à la classe E du DPE. La consommation d\'énergie finale et le niveau de performance du logement sont déterminés selon la méthode du diagnostic de performance énergétique mentionné à l\'article L. 126-26 du code de la construction et de l\'habitation.</p>';
        $desig = ($ctx['bien_adresse'] ? bcp_e($ctx['bien_adresse']) : '……………')
            . ($ctx['immeuble'] ? ', dépendant de l\'immeuble ' . bcp_e($ctx['immeuble']) : '')
            . (!empty($r['en_copropriete']) && $r['lot_copropriete'] ? ', lot de copropriété n° ' . bcp_e((string)$r['lot_copropriete']) . ($r['lot_tantiemes'] ? ' (' . bcp_e((string)$r['lot_tantiemes']) . ')' : '') : '')
            . ($rowNum('surface_habitable') !== null ? ', d\'une surface habitable de ' . bcp_e((string)$r['surface_habitable']) . ' m²' : ', d\'une surface habitable de …… m²')
            . ($rowNum('nb_pieces') !== null ? ', comprenant ' . (int)$r['nb_pieces'] . ' pièce(s) principale(s)' : '') . '.';
        $h .= '<p>' . $desig . '</p>';
        $h .= '<p><b>Niveau de performance du logement (DPE) :</b> ' . $F($r['dpe_classe'] ?? '', 4) . '</p>';
        $h .= '<p class="clabel">Destination des locaux</p><p>Les locaux sont loués pour un <b>usage exclusif d\'habitation principale.</b></p>';
        $h .= '<p class="clabel">Équipement d\'accès aux technologies de l\'information et de la communication</p><p>' . $F($r['equipement_tic'] ?? '', 20) . '</p>';

        // ── III. Date de prise d'effet et durée ──
        $h .= '<h3>III. Date de prise d\'effet et durée du contrat</h3>';
        $h .= '<p class="clabel">A. Date de prise d\'effet du contrat</p><p>Le présent bail prendra effet le ' . $D($r['date_prise_effet']) . '.</p>';
        $h .= '<p class="clabel">B. Durée du contrat</p>';
        $h .= '<p>En l\'absence de proposition de renouvellement du contrat, celui-ci est, à son terme, reconduit tacitement pour une durée de 3 ou 6 ans et dans les mêmes conditions. Le LOCATAIRE peut mettre fin au bail à tout moment, après avoir donné congé. Le BAILLEUR, quant à lui, peut mettre fin au bail à son échéance et après avoir donné congé, soit pour reprendre le logement en vue de l\'occuper lui-même ou une personne de sa famille, soit pour le vendre, soit pour un motif sérieux et légitime.</p>';

        // ── IV. Conditions financières ──
        $h .= '<h3>IV. Conditions financières</h3>';
        $h .= '<p class="clabel">A. Loyer</p>';
        $h .= '<p><b>1°. Fixation du loyer initial :</b></p>';
        $h .= '<p><b>a) Montant du loyer mensuel :</b> Le montant du loyer mensuel initial est fixé à la somme de ' . $E($rowNum('loyer_mensuel_hc')) . '.</p>';
        $h .= '<p><b>b) Modalités particulières de fixation initiale du loyer applicables dans certaines zones tendues :</b> Le loyer du logement objet du présent contrat est soumis au décret fixant annuellement le montant maximum d\'évolution des loyers à la relocation. Ce loyer est soumis au loyer de référence majoré fixé par arrêté préfectoral. Le montant du loyer de référence est de ' . $E($rowNum('loyer_reference')) . ' par mètre carré. Le montant du loyer de référence majoré est de ' . $E($rowNum('loyer_reference_majore')) . ' par mètre carré. Les caractéristiques du logement justifient l\'application d\'un complément de loyer. Ces caractéristiques sont les suivantes : ' . $F($r['complement_loyer_caracteristiques'] ?? '', 20) . '. Le montant du complément de loyer est de ' . $E($rowNum('complement_loyer')) . '. Ce complément de loyer s\'ajoute au loyer de référence majoré.</p>';
        $h .= '<p><b>c) Informations relatives au loyer du dernier LOCATAIRE :</b> Montant du dernier loyer appliqué au précédent LOCATAIRE : ' . $E($rowNum('dernier_loyer_montant')) . '. Date de versement : ' . $D($r['dernier_loyer_date_versement']) . '. Date de la dernière révision du loyer : ' . $D($r['dernier_loyer_date_revision']) . '.</p>';
        $h .= '<p><b>2°. Modalités de révision :</b></p>';
        $h .= '<p><b>a) Date de révision du loyer :</b> Le montant du loyer sera révisé chaque année, le ' . $F($r['date_revision_jour_mois'] ?? '', 6) . ', en fonction de la variation de l\'indice de référence des loyers publié par l\'INSEE.</p>';
        $h .= '<p><b>b) Date ou trimestre de référence de l\'IRL :</b> L\'indice de référence est l\'indice du trimestre ' . $F($r['indice_trimestre'] ?? '', 8) . ' dont la valeur s\'établit à ' . $F($r['indice_valeur'] ?? '', 8) . '.</p>';
        $h .= '<p class="clabel">B. Charges récupérables</p>';
        $h .= '<p>Le montant de la provision initiale pour charges est fixé à la somme de ' . $E($rowNum('charges_mensuelles')) . '. Cette provision comprend les charges suivantes : ' . $F($r['conditions_particulieres'] ?? '', 16) . '. S\'agissant de la <b>Taxe d\'enlèvement des ordures ménagères</b>, il est expressément prévu entre les Parties que cette taxe fera l\'objet d\'un remboursement ponctuel chaque année sur présentation de l\'avis de taxe foncière. Pour l\'année ' . $F($r['teom_annee'] ?? '', 4) . ', le montant de cette taxe s\'établissait à ' . $E($rowNum('teom_montant')) . ' hors frais de rôle. La provision pour charges pourra être réajustée à l\'occasion de la régularisation annuelle, en fonction des dépenses réelles.</p>';
        $h .= '<p class="clabel">C. Contribution pour le partage des économies de charges</p><p>Sans objet.</p>';
        $h .= '<p class="clabel">D. Souscription par le BAILLEUR d\'une assurance pour le compte des colocataires</p><p>Le montant récupérable par douzième au titre de l\'assurance pour compte des colocataires est de ' . $E($rowNum('assurance_colocataires_mensuel')) . '.</p>';
        $h .= '<p class="clabel">E. Modalités de paiement</p><p>Le loyer est payable à échoir au plus tard le ' . $F($r['paiement_jour'] ?? '', 4) . ' de chaque mois entre les mains ' . $F($r['paiement_beneficiaire'] ?? ($ge['raison'] ?? ''), 16) . '.</p>';

        // Tableau montant total 1ère échéance
        $loy = $rowNum('loyer_mensuel_hc') ?? 0; $comp = $rowNum('complement_loyer') ?? 0; $ch = $rowNum('charges_mensuelles') ?? 0;
        $tot = $loy + $comp + $ch;
        $h .= '<table class="tbl"><tr><th colspan="2">Montant total dû à la première échéance de paiement pour une période complète de location</th></tr>'
            . '<tr><td>Loyer mensuel hors complément de loyer éventuel</td><td class="who">' . $E($loy) . '</td></tr>'
            . '<tr><td>Complément de loyer éventuel</td><td class="who">' . $E($comp) . '</td></tr>'
            . '<tr><td>Provisions/forfait de charges</td><td class="who">' . $E($ch) . '</td></tr>'
            . '<tr><td>Contribution pour le partage des économies de charges</td><td class="who">' . $E(0) . '</td></tr>'
            . '<tr><td><b>TOTAL</b></td><td class="who"><b>' . $E($tot) . '</b></td></tr></table>';
        // Prorata 1ère période
        $prR = 1.0; $prBase = ($r['prorata_date_debut'] ?? '') ?: ($r['date_prise_effet'] ?? '');
        $proTxt = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prBase)) {
            $ts = strtotime((string)$prBase); $dim = (int)date('t', $ts); $jj = (int)date('j', $ts);
            $prR = $dim > 0 ? ($dim - $jj + 1) / $dim : 1.0;
            $proTxt = ' Le montant total dû <i>prorata temporis</i> pour la première période de location allant du ' . bcp_date($prBase) . ' au ' . bcp_date(date('Y-m-t', $ts)) . ' est de ' . $E($tot * $prR) . '.';
        }
        if ($proTxt) $h .= '<p>' . $proTxt . '</p>';
        $h .= '<p class="clabel">G. Dépenses énergétiques (pour information)</p><p>Montant estimé des dépenses annuelles d\'énergie pour un usage standard : entre ' . $E($rowNum('depenses_energie_min')) . ' et ' . $E($rowNum('depenses_energie_max')) . ' par an (estimation réalisée à partir des prix énergétiques de référence de l\'année ' . $F($r['depenses_energie_annee'] ?? '', 4) . ').</p>';

        // ── V. Travaux ──
        $h .= '<h3>V. Travaux réalisés ou à réaliser</h3>';
        $h .= '<p><b>Travaux réalisés :</b> ' . $F($r['travaux_realises_3ans'] ?? '', 20) . '</p>';
        $h .= '<p><b>Travaux à réaliser — Convention de travaux :</b> ' . $F($r['travaux_prevus_3ans'] ?? '', 20) . '</p>';

        // ── VI. Garantie ──
        $h .= '<h3>VI. Garantie</h3>';
        $h .= '<p>En vue de garantir l\'exécution de ses obligations, le LOCATAIRE verse ce jour la somme de ' . $E($rowNum('depot_garantie')) . ' entre les mains ' . $F($ge['raison'] ?? '', 12) . ' qui lui en donnera quittance. En cas de colocation ou de cotitularité du présent bail, le dépôt de garantie ne sera restitué qu\'en fin de bail et après restitution totale des lieux loués conformément aux dispositions de l\'article 22 de la loi du 6 juillet 1989.</p>';

        // ── VII. Solidarité (verbatim) ──
        $h .= '<h3>VII. Solidarité - Indivisibilité</h3>';
        $h .= '<p>Il est expressément stipulé que les copreneurs et toutes personnes pouvant se prévaloir des dispositions de l\'article 14 de la loi du 6 juillet 1989 seront tenus solidairement et indivisiblement de l\'exécution des obligations du présent contrat. En cas de colocation, les colocataires soussignés, désignés sous le vocable « Le LOCATAIRE », reconnaissent expressément qu\'ils se sont engagés solidairement. Si un colocataire délivrait congé et quittait les lieux, il resterait tenu du paiement des loyers et accessoires et, plus généralement, de toutes les obligations du bail en cours au moment de la délivrance du congé, et de ses suites, au même titre que le(s) colocataire(s) demeuré(s) dans les lieux pendant une durée de six mois à compter de la date d\'effet du congé. Toutefois, cette solidarité prendra fin, avant l\'expiration de ce délai, si un nouveau colocataire, accepté par le BAILLEUR, figure au présent contrat. Le BAILLEUR n\'a accepté de consentir le présent bail qu\'en considération de cette cotitularité solidaire ; la présente clause est une condition substantielle. En cas de départ d\'un ou plusieurs colocataires, le dépôt de garantie ne sera restitué qu\'après libération totale des lieux et dans un délai maximum de deux mois à compter de la remise des clés.</p>';

        // ── VIII. Clause résolutoire (verbatim) ──
        $h .= '<h3>VIII. Clause résolutoire</h3>';
        $h .= '<p>Le présent contrat sera résilié immédiatement et de plein droit, sans qu\'il soit besoin de faire ordonner cette résiliation en justice, si bon semble au BAILLEUR :</p><ul>'
            . '<li>6 semaines après la délivrance d\'un commandement de payer demeuré infructueux à défaut de paiement aux termes convenus de tout ou partie du loyer et des charges ou en cas de non-versement du dépôt de garantie prévu au contrat ;</li>'
            . '<li>un mois après la délivrance d\'un commandement demeuré infructueux à défaut d\'assurance contre les risques locatifs ;</li>'
            . '<li>dès lors qu\'une décision de justice passée en force de chose jugée constatera des troubles de voisinage et le non-respect de l\'obligation d\'user paisiblement des locaux loués.</li></ul>'
            . '<p>Une fois acquis au BAILLEUR le bénéfice de la clause résolutoire, le LOCATAIRE devra libérer immédiatement les lieux. Les frais, droits et honoraires des actes de procédure seront répartis conformément à l\'article L. 111-8 du code des procédures civiles d\'exécution. Le LOCATAIRE sera tenu de toutes les obligations découlant du présent bail jusqu\'à la libération effective des lieux, sans préjudice des dispositions de l\'article 1760 du Code civil et ce, nonobstant l\'expulsion.</p>';

        // ── IX. Honoraires de location ──
        $h .= '<h3>IX. Honoraires de location</h3>';
        $h .= '<p class="clabel">A. Dispositions applicables</p>';
        $h .= '<p class="sub2">Il est rappelé les dispositions du I de l\'article 5 de la loi du 6 juillet 1989, alinéas 1 à 3 : « La rémunération des personnes mandatées pour se livrer ou prêter leur concours à l\'entremise ou à la négociation d\'une mise en location d\'un logement, tel que défini aux articles 2 et 25-3, est à la charge exclusive du BAILLEUR, à l\'exception des honoraires liés aux prestations mentionnées aux deuxième et troisième alinéas du présent I. Les honoraires des personnes mandatées pour effectuer la visite du preneur, constituer son dossier et rédiger un bail sont partagés entre le BAILLEUR et le preneur. Le montant toutes taxes comprises imputé au preneur pour ces prestations ne peut excéder celui imputé au BAILLEUR et demeure inférieur ou égal à un plafond par mètre carré de surface habitable de la chose louée fixé par voie réglementaire et révisable chaque année, dans des conditions définies par décret. Ces honoraires sont dus à la signature du bail. Les honoraires des personnes mandatées pour réaliser un état des lieux sont partagés entre le BAILLEUR et le preneur. Le montant toutes taxes comprises imputé au LOCATAIRE pour cette prestation ne peut excéder celui imputé au BAILLEUR et demeure inférieur ou égal à un plafond par mètre carré de surface habitable de la chose louée fixé par voie réglementaire et révisable chaque année, dans des conditions définies par décret. Ces honoraires sont dus à compter de la réalisation de la prestation. » Le BAILLEUR et le LOCATAIRE conviennent de confier la réalisation de l\'état des lieux d\'entrée à l\'Agence ' . bcp_e($ge['raison'] ?? 'REGIE EMERY') . ' qu\'ils mandatent expressément à cet effet.</p>';
        $h .= '<p>Plafonds applicables : — montant du plafond des honoraires imputables aux locataires en matière de prestation de visite du preneur, de constitution de son dossier et de rédaction de bail : ' . $E($rowNum('honoraires_plafond_visite_m2')) . '/m² de surface habitable ; — montant du plafond des honoraires imputables aux locataires en matière d\'établissement de l\'état des lieux d\'entrée : ' . $E($rowNum('honoraires_plafond_edl_m2')) . '/m² de surface habitable.</p>';
        $h .= '<p class="clabel">B. Détail et répartition des honoraires</p>';
        $hbv = $rowNum('hono_bailleur_visite') ?? 0; $hbe = $rowNum('hono_bailleur_entremise') ?? 0; $hbl = $rowNum('hono_bailleur_edl') ?? 0;
        $hlv = $rowNum('hono_locataire_visite') ?? 0; $hll = $rowNum('hono_locataire_edl') ?? 0;
        $h .= '<table class="tbl"><tr><th>Honoraires à la charge du BAILLEUR</th><th class="who">Montant</th></tr>'
            . '<tr><td>Visite, constitution du dossier, rédaction du bail</td><td class="who">' . $E($hbv) . ' TTC</td></tr>'
            . '<tr><td>Entremise et de négociation</td><td class="who">' . $E($hbe) . ' TTC</td></tr>'
            . '<tr><td>Réalisation de l\'état des lieux d\'entrée</td><td class="who">' . $E($hbl) . ' TTC</td></tr>'
            . '<tr><td><b>TOTAL</b></td><td class="who"><b>' . $E($hbv + $hbe + $hbl) . ' TTC</b></td></tr></table>';
        $h .= '<table class="tbl"><tr><th>Honoraires à la charge du LOCATAIRE</th><th class="who">Montant</th></tr>'
            . '<tr><td>Visite, constitution du dossier, rédaction du bail</td><td class="who">' . $E($hlv) . ' TTC</td></tr>'
            . '<tr><td>Réalisation de l\'état des lieux d\'entrée</td><td class="who">' . $E($hll) . ' TTC</td></tr>'
            . '<tr><td><b>TOTAL</b></td><td class="who"><b>' . $E($hlv + $hll) . ' TTC</b></td></tr></table>';
        $h .= '<p class="mut">Les honoraires de visite, de constitution du dossier et de rédaction du bail sont dus à la conclusion du bail. Les honoraires de réalisation de l\'état des lieux d\'entrée sont dus dès la réalisation de la prestation.</p>';

        // ── X. Autres conditions particulières (verbatim, 1 → 13) ──
        $h .= '<h3>X. Autres conditions particulières</h3>' . bail_habitation_clauses_x();

        // ── XI. Autres informations ──
        $h .= '<h3>XI. Autres informations</h3>';
        $h .= '<p class="clabel">A - Informations relatives à l\'amiante pour les immeubles collectifs dont le permis de construire a été délivré avant le 1<sup>er</sup> juillet 1997</p>';
        $h .= '<p><b>Parties privatives.</b> Le LOCATAIRE reconnaît avoir été informé de l\'existence d\'un dossier amiante sur les parties privatives qu\'il occupe (DAPP ou DTA). Sur demande écrite, le LOCATAIRE pourra venir consulter ce document auprès du BAILLEUR ou de son mandataire.</p>';
        $h .= '<p><b>Parties communes.</b> Le LOCATAIRE reconnaît avoir été informé que le dossier technique amiante (DTA) sur les parties communes est tenu à disposition chez le syndic de la copropriété (selon ses propres modalités de consultation). Pour les immeubles en monopropriété, sur demande écrite, le LOCATAIRE pourra venir consulter ce document auprès du BAILLEUR ou de son mandataire.</p>';
        $h .= '<p class="clabel">B - Informations relatives aux sinistres</p><p>' . $F($r['travaux_realises_3ans'] ?? '', 12) . '</p>';
        $h .= '<p class="clabel">C - Informations relatives au bruit</p><p>&nbsp;</p>';
        $h .= '<p class="clabel">D - Informations relatives à la récupération des eaux de pluie (arrêté du 21 août 2008 pris en application de la loi du 30 décembre 2006)</p><p>Si les locaux loués comportent des équipements de récupération des eaux pluviales, le BAILLEUR informe le LOCATAIRE des modalités d\'utilisation de ceux-ci.</p>';

        // ── XII / XIII / XIV / XV ──
        $h .= '<h3>XII. Indemnité d\'occupation</h3><p>En cas de congé ou de résiliation, si le LOCATAIRE se maintient après l\'expiration du bail, il sera redevable d\'une indemnité d\'occupation au moins égale au montant du dernier loyer, charges, taxes et accessoires réclamé.</p>';
        $h .= '<h3>XIII. Protection des données personnelles des Parties</h3>';
        $h .= '<p>Vos données personnelles collectées dans le cadre du présent contrat font l\'objet d\'un traitement nécessaire à son exécution. Elles sont susceptibles d\'être utilisées dans le cadre de l\'application de réglementations comme celle relative à la lutte contre le blanchiment des capitaux et le financement du terrorisme. Vos données personnelles sont conservées pendant toute la durée de l\'exécution du présent contrat, augmentée des délais légaux de prescription applicable.</p>';
        $h .= '<p>Pour la réalisation de la finalité des présentes, vos données sont, le cas échéant, susceptibles d\'être transmises, notamment : aux prestataires de la signature électronique et de la lettre recommandée électronique ; aux entreprises chargées de travaux sur l\'immeuble ; à l\'observatoire local des loyers et l\'ANIL ; au commissaire de justice et à l\'avocat en cas de procédure ; aux organismes d\'assurances souscrites par le BAILLEUR. Il est précisé que, dans le cadre de l\'exécution de leurs prestations, les tiers limitativement énumérés ci-avant n\'ont qu\'un accès limité aux données et ont l\'obligation de les utiliser en conformité avec la législation applicable en matière de protection des données personnelles.</p>';
        $h .= '<p>Chacune des parties pourra demander à l\'Agence d\'accéder aux données à caractère personnel la concernant, de les rectifier, de les modifier, de les supprimer, ou de s\'opposer à leur exploitation en lui adressant un courriel en ce sens à ' . bcp_e($ge['email'] ?? 'emmanuel.emery@regie-emery.com') . ' ou un courrier à l\'adresse du siège de l\'Agence. Toute réclamation pourra être introduite auprès de la Commission Nationale de l\'Informatique et des Libertés (www.cnil.fr). Dans le cas où des coordonnées téléphoniques ont été recueillies, vous êtes informé(e)(s) de la faculté de vous inscrire sur la liste d\'opposition au démarchage téléphonique prévue en faveur des consommateurs (article L. 223-1 du code de la consommation).</p>';
        $h .= '<h3>XIV. Annexes</h3><p>Sont annexées et jointes au présent contrat de location les pièces suivantes :</p><ul>'
            . '<li>la notice d\'information relative aux droits et obligations des locataires et des bailleurs ;</li>'
            . '<li>les extraits du règlement de copropriété concernant la destination de l\'immeuble, la jouissance et l\'usage des parties privatives et communes, et précisant la quote-part afférente au lot loué dans chacune des catégories de charges ;</li>'
            . '<li>une attestation de remise du dossier de diagnostic technique (L. n° 89-462, 6 juillet 1989, art. 3-3) ;</li>'
            . '<li>un diagnostic de performance énergétique ;</li>'
            . '<li>un constat des risques d\'exposition au plomb ;</li>'
            . '<li>une copie d\'un état mentionnant l\'absence ou la présence de matériaux ou de produits de la construction contenant de l\'amiante ;</li>'
            . '<li>un état de l\'installation intérieure d\'électricité ;</li>'
            . '<li>un état de l\'installation intérieure de gaz ;</li>'
            . '<li>l\'état des risques et pollutions ;</li>'
            . '<li>l\'état des lieux d\'entrée lorsqu\'il aura été établi ;</li>'
            . '<li>la liste des réparations locatives définies par le décret n° 87-712 du 26 août 1987 ;</li>'
            . '<li>la liste des charges récupérables définies par le décret n° 87-713 du 26 août 1987.</li></ul>';
        $h .= '<h3>XV. Acceptation des notifications électroniques</h3><p>Le LOCATAIRE donne son accord pour que les notifications qui lui seront adressées en exécution du présent bail soient faites par lettres recommandées électroniques à l\'adresse ' . $F($r['locataire_email'] ?? '', 16) . ', conformément à l\'article 1126 du Code civil et à l\'article L.100 du Code des postes et des communications électroniques.</p>';

        // ── DATE ET SIGNATURES ──
        $lieu = trim((string)($r['lieu_signature'] ?? '')) ?: (string)($ge['ville_sig'] ?? '');
        $h .= '<h2>DATE ET SIGNATURES</h2>';
        $h .= '<p>Fait à ' . $F($lieu, 12) . ', et signé électroniquement par l\'ensemble des Parties, chacune d\'elles en conservant un exemplaire original sur un support durable garantissant l\'intégrité de l\'acte.</p>';
        $h .= bail_habitation_bloc_signatures($ctx);
        $h .= '</div>';
        return $h;
    }
}

if (!function_exists('bail_habitation_clauses_x')) {
    /** Conditions particulières X.1 → X.13 — texte VERBATIM du modèle FNAIM (pages 5-8). */
    function bail_habitation_clauses_x(): string
    {
        $items = [
            'Destination des locaux loués' => "Le BAILLEUR est tenu de délivrer un logement conforme à sa destination. Outre les obligations mentionnées dans la notice en annexe, le LOCATAIRE s'interdit expressément : d'utiliser les locaux loués autrement qu'à l'usage fixé au présent bail, à l'exclusion de tout autre ; d'exercer dans les locaux loués, en sa qualité de LOCATAIRE personne physique ou représentant d'une personne morale, aucune activité commerciale, industrielle ou artisanale, ni aucune profession libérale autre que celle éventuellement prévue aux conditions particulières. En cas d'usage mixte professionnel et habitation, le LOCATAIRE fera son affaire personnelle de toute prescription administrative relative à l'exercice de sa profession et s'engage à l'exercer en sorte que le BAILLEUR ne puisse en aucun cas être recherché ni inquiété à ce sujet par l'administration, les occupants de l'immeuble ou les voisins ; de céder en tout ou partie, à titre onéreux ou gratuit, les droits qu'il détient des présentes, ou de sous-louer, échanger ou mettre à disposition les locaux, en tout ou partie, en meublé ou non, sans l'accord écrit du BAILLEUR, y compris sur le prix du loyer, sans que cet éventuel accord puisse faire acquérir au sous-locataire aucun droit à l'encontre du BAILLEUR ni aucun titre d'occupation, les dispositions de la loi du 6 juillet 1989 n'étant pas applicables au contrat de sous-location.",
            "Entretien et nettoyage des générateurs de chauffage et de production d'eau chaude, de pompe à chaleur et des climatisations" => "Le LOCATAIRE devra faire entretenir et nettoyer à ses frais, aussi souvent qu'il en sera besoin conformément à la législation ou à la réglementation en vigueur, et au moins une fois l'an, tous les appareils et installations diverses (chauffe-eau, chauffage central, pompe à chaleur, climatisation, etc.) pouvant exister dans les locaux loués. Il devra en justifier par la production d'une attestation d'un professionnel ou d'une facture acquittée. Le LOCATAIRE devra souscrire un contrat d'entretien auprès d'un établissement spécialisé de son choix pour assurer le bon fonctionnement et l'entretien du ou des générateurs de chauffage et de production d'eau chaude lorsqu'il s'agit d'installations individuelles. L'entretien incombant au LOCATAIRE, il lui appartiendra de produire les justifications de celui-ci, sans que l'absence de demande de justifications puisse entraîner une quelconque responsabilité du BAILLEUR.",
            'Visite des locaux loués' => "En cas de mise en vente ou relocation, le LOCATAIRE devra laisser visiter les lieux loués deux heures pendant les jours ouvrables qui seront conventionnellement arrêtées avec le BAILLEUR. À défaut d'accord, les heures de visite sont fixées entre 17 et 19 heures.",
            'Sinistres et dégradations' => "Le LOCATAIRE s'oblige à déclarer tout sinistre à son assurance et à justifier, sans délai, au BAILLEUR de cette déclaration. Le LOCATAIRE s'oblige également à aviser sans délai par écrit le BAILLEUR de toute dégradation ou de tout sinistre survenant dans les locaux loués ; à défaut, il pourra être tenu responsable de sa carence. Il serait, en outre, responsable envers le BAILLEUR de toute aggravation de ce dommage survenue après cette date.",
            'Ramonage' => "Le LOCATAIRE devra faire ramoner les cheminées et gaines de fumée des lieux loués aussi souvent qu'il en sera besoin conformément à la législation ou à la réglementation en vigueur et au moins une fois par an. Il en justifiera par la production d'une attestation d'un professionnel ou d'une facture acquittée.",
            'Interdiction de certains appareils de chauffage' => "Le LOCATAIRE ne pourra faire usage, dans les locaux loués, d'aucun appareil de chauffage à combustion lente ou continue, en particulier d'aucun appareil utilisant le mazout ou le gaz, sans avoir obtenu préalablement l'accord et l'autorisation écrite du BAILLEUR et, dans le cas où cette autorisation serait donnée, le LOCATAIRE devrait prendre à sa charge les frais consécutifs aux aménagements préalables à réaliser s'il y a lieu (modification ou adaptation des conduits ou des cheminées d'évacuation, etc.). Il reconnaît avoir été avisé de ce que la violation de cette interdiction le rendrait responsable des dommages qui pourraient être causés.",
            'Jouissance paisible' => "Le LOCATAIRE ne devra commettre aucun abus de jouissance susceptible de nuire soit à la solidité ou à la bonne tenue de l'immeuble, soit d'engager la responsabilité du BAILLEUR envers les autres occupants de l'immeuble ou envers le voisinage. En particulier, il ne pourra rien déposer, sur les appuis de fenêtres, balcons et ouvertures quelconques sur rue ou sur cour, qui puisse présenter un danger pour les autres occupants ou causer une gêne, ou nuire à l'aspect dudit immeuble. Il ne pourra notamment y étendre aucun linge, tapis, chiffon, y déposer aucun objet ménager, ustensile ou outil quelconque. Il devra éviter tout bruit de nature à gêner les autres habitants de l'immeuble, notamment régler tout appareil de radio, télévision et de reproduction de sons de telle manière que le voisinage n'ait pas à s'en plaindre.",
            "Détention d'animaux" => "Le LOCATAIRE ne devra conserver dans les lieux loués aucun animal bruyant, malpropre ou malodorant, susceptible de causer des dégradations ou une gêne aux autres occupants de l'immeuble. De plus, il s'interdit de détenir dans les lieux loués des chiens de première catégorie, en application des articles L. 211-12 et suivants du code rural.",
            'Nuisibles' => "Le LOCATAIRE informera le BAILLEUR ou son mandataire de la présence de parasites, rongeurs et insectes dans les lieux loués. Selon le décret n° 87-713 du 27 août 1987 (paragraphe VI HYGIÈNE), les produits relatifs à la désinsectisation et/ou à la désinfection, y compris des colonnes sèches de vide-ordures, intéressant les parties privatives, seront à la charge du locataire dans le respect de la législation sur les charges récupérables. Conformément à l'article L. 133-4 du code de la construction et de l'habitation, le LOCATAIRE est tenu de déclarer en mairie la présence de termites et/ou d'insectes xylophages dans les lieux loués. Il s'engage parallèlement à en informer le BAILLEUR pour qu'il puisse procéder aux travaux préventifs ou d'éradication nécessaires.",
            'Usage des parties communes' => "Le LOCATAIRE ne pourra déposer dans les cours, entrées, couloirs, escaliers, ni sur les paliers et, d'une manière générale, dans aucune des parties communes autres que celles réservées à cet effet, aucun objet, quel qu'il soit, notamment bicyclettes, cycles à moteur et autres véhicules, voitures d'enfant et poussettes.",
            'Gel' => "Le LOCATAIRE devra prendre toutes précautions nécessaires pour protéger du gel les canalisations d'eau ainsi que les compteurs, et sera, dans tous les cas, tenu pour responsable des dégâts qui pourraient survenir du fait de sa négligence. En cas de dégâts des eaux, et notamment par suite de gel, le LOCATAIRE devra le signaler au BAILLEUR ou à son mandataire dans les délais les plus brefs et prendre toutes mesures conservatoires visant à limiter les conséquences du sinistre. À défaut, sa responsabilité pourrait être engagée.",
            "Personnel de l'immeuble" => "Le BAILLEUR pourra remplacer l'éventuel employé d'immeuble chargé de l'entretien par une entreprise ou un technicien de surface effectuant les mêmes prestations. Le LOCATAIRE ne pourra rendre le BAILLEUR ou son mandataire responsable des faits du gardien, du concierge ou de l'employé d'immeuble qui, pour toute mission à lui confiée par le LOCATAIRE, sera considéré comme son mandataire exclusif et spécial. Il est spécifié que le gardien, le concierge ou l'employé d'immeuble n'a pas pouvoir d'accepter un congé, de recevoir les clés ou de signer soit un contrat de location, soit les quittances ou reçus, soit un état des lieux ou toute attestation ou certificat ; en conséquence, sa signature ne saurait engager le BAILLEUR ou son mandataire.",
            "Système d'assainissement autonome" => "Le LOCATAIRE devra entretenir le système d'assainissement autonome et justifier de cet entretien lors de la remise des clés.",
        ];
        $h = ''; $i = 1;
        foreach ($items as $t => $txt) {
            $h .= '<p><b>' . $i . '. ' . bcp_e($t) . '.</b> ' . bcp_e($txt) . '</p>';
            $i++;
        }
        return $h;
    }
}

if (!function_exists('bail_habitation_bloc_signatures')) {
    function bail_habitation_bloc_signatures(array $ctx): string
    {
        $sigs = $ctx['signatures'] ?? [];
        $find = function (array $roles) use ($sigs) {
            foreach ($sigs as $s) if (in_array(($s['role_code'] ?? ''), $roles, true) && ($s['statut'] ?? '') === 'signe') return $s;
            return null;
        };
        $cell = function (?array $sig, string $label) {
            $out = '<b>' . bcp_e($label) . '</b><br><span class="mut">« Lu et approuvé »</span><br>';
            if ($sig && !empty($sig['signature_data']) && strncmp((string)$sig['signature_data'], 'data:image', 10) === 0) {
                $out .= '<img src="' . $sig['signature_data'] . '" style="max-height:64px;max-width:190px;"><br>';
                $out .= '<span class="mut">' . bcp_e((string)($sig['nom_signataire'] ?? '')) . (bcp_date($sig['signed_at'] ?? null) ? ' — signé le ' . bcp_date($sig['signed_at']) : '') . '</span>';
            } else {
                $out .= '<br><br>………………………………';
            }
            return $out;
        };
        return '<table class="sigtbl"><tr>'
            . '<td>' . $cell($find(['bailleur', 'mandataire']), 'Le BAILLEUR (ou son mandataire)') . '</td>'
            . '<td>' . $cell($find(['preneur']), 'Le LOCATAIRE') . '</td>'
            . '</tr></table>';
    }
}

if (!function_exists('bail_habitation_build_pdf')) {
    function bail_habitation_build_pdf(PDO $pdo, int $bailId, ?bool $forceProjet = null): string
    {
        $ctx = bail_habitation_context($pdo, $bailId);
        if ($ctx === null) throw new RuntimeException('Bail #' . $bailId . ' introuvable.');
        // Signatures (tracés) pour le bloc signatures.
        try {
            try { $qs = $pdo->prepare("SELECT role_code, nom_signataire, signature_data, statut, signed_at FROM bail_signatures WHERE id_bail=? ORDER BY id ASC"); $qs->execute([$bailId]); }
            catch (Throwable) { $qs = null; }
            $ctx['signatures'] = $qs ? ($qs->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) { $ctx['signatures'] = []; }

        $withProjet = $forceProjet !== null ? $forceProjet : in_array($ctx['statut'], ['projet', 'envoye', 'brouillon'], true);
        $body = bail_habitation_corps($ctx);
        // Annexes (décrets + notice) — figées, chargées si dispo.
        $annexes = '';
        if (is_file(__DIR__ . '/bail_habitation_annexes.php')) {
            require_once __DIR__ . '/bail_habitation_annexes.php';
            if (function_exists('bail_habitation_annexes_html')) $annexes = bail_habitation_annexes_html();
        }

        $css = '<style>
            body{font-family:"Times",serif;font-size:10pt;color:#1c2226;line-height:1.5;}
            h1{font-size:15pt;text-align:center;letter-spacing:.5px;margin:0 0 4px;}
            .sub{text-align:center;font-size:8pt;font-style:italic;color:#555;margin:0 0 2px;}
            .ref{text-align:center;font-size:8.5pt;color:#777;margin:0 0 12px;}
            h2{font-size:11pt;color:#2c4b4d;border-bottom:0.6pt solid #cddcdc;padding-bottom:2px;margin:14px 0 4px;}
            h3{font-size:10pt;color:#243B5C;background:#eef2f6;padding:3px 7px;margin:11px 0 4px;}
            .clabel{font-weight:bold;color:#2c4b4d;margin:8px 0 2px;}
            ul{margin:3px 0 8px 0;padding-left:18px;} li{margin:2px 0;text-align:justify;}
            p{margin:3px 0 6px;text-align:justify;}
            .qual{font-style:italic;color:#555;} .sub2{font-size:8.5pt;font-style:italic;color:#666;margin:0 0 6px;}
            .mut{font-size:8.5pt;font-style:italic;color:#888;}
            .tbl{width:100%;border-collapse:collapse;font-size:9pt;margin:4px 0 10px;}
            .tbl th{background:#e3ecec;text-align:left;padding:5px 7px;border:0.5pt solid #b9cccc;font-size:8.5pt;}
            .tbl td{padding:4px 7px;border:0.5pt solid #cddada;vertical-align:top;} .tbl td.who{text-align:center;white-space:nowrap;width:26%;}
            .sigtbl{width:100%;margin-top:10px;} .sigtbl td{width:50%;vertical-align:top;padding:8px 10px;font-size:9.5pt;border:0.4pt solid #e0e0e0;}
            .annexe{font-size:8pt;line-height:1.4;} .annexe p{margin:2px 0 4px;} .annexe h2{page-break-before:always;font-size:10pt;} .annexe .clabel{font-size:8.5pt;}
        </style>';

        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Autoload Composer introuvable (mPDF non installé)');
        require_once $autoload;
        if (!class_exists('\\Mpdf\\Mpdf')) throw new RuntimeException('mPDF introuvable');
        $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_top' => 16, 'margin_bottom' => 18, 'margin_left' => 16, 'margin_right' => 16, 'tempDir' => $tmpDir, 'default_font' => 'dejavusans']);
        $mpdf->SetTitle('Bail habitation' . ($ctx['numero_bail'] ? ' ' . $ctx['numero_bail'] : ''));
        $mpdf->SetAuthor($ctx['mandataire']['raison'] ?? 'MaBoxImmo');
        $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:7.5pt;color:#999;border-top:0.4pt solid #ddd;padding-top:3px;">Bail habitation (loi 89-462)' . ($ctx['numero_bail'] ? ' &mdash; ' . bcp_e($ctx['numero_bail']) : '') . ' &mdash; page {PAGENO}/{nbpg}</div>');
        if ($withProjet) { $mpdf->SetWatermarkText('PROJET'); $mpdf->showWatermarkText = true; $mpdf->watermarkTextAlpha = 0.08; $mpdf->watermark_font = 'DejaVuSans'; }
        $mpdf->WriteHTML($css . $body . $annexes);

        $tmp = sys_get_temp_dir() . '/bailhab_' . $bailId . '_' . ($withProjet ? 'projet' : 'def') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $mpdf->Output($tmp, \Mpdf\Output\Destination::FILE);
        return $tmp;
    }
}
