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

if (!function_exists('bail_habitation_prefill_from_bien')) {
    /**
     * Valeurs de PRÉ-REMPLISSAGE d'un nouveau bail habitation, reprises automatiquement du BIEN
     * et de son ANNONCE de location active (surface, DPE, pièces, loyer, charges, loyer de
     * référence, zone tendue, ancien loyer…). Retour = map colonne bien_baux → valeur.
     */
    function bail_habitation_prefill_from_bien(PDO $pdo, int $bienId): array
    {
        if ($bienId <= 0) return [];
        $out = [];
        // Bien : surface, pièces, DPE.
        try {
            $q = $pdo->prepare("SELECT surface_habitable, nb_pieces, dpe_classe FROM biens WHERE id=? LIMIT 1");
            $q->execute([$bienId]);
            if ($b = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($b['surface_habitable'] !== null && (float)$b['surface_habitable'] > 0) $out['surface_habitable'] = (float)$b['surface_habitable'];
                if ($b['nb_pieces'] !== null && (int)$b['nb_pieces'] > 0)                   $out['nb_pieces'] = (int)$b['nb_pieces'];
                if (trim((string)($b['dpe_classe'] ?? '')) !== '')                           $out['dpe_classe'] = strtoupper(trim((string)$b['dpe_classe']));
            }
        } catch (Throwable) {}
        // Annonce de LOCATION la plus récente : on reprend TOUT ce qui est exploitable.
        try {
            $q = $pdo->prepare("SELECT loyer, loyer_de_base, loyer_cc, complement_loyer, charges, charges_annuelles,
                                       loyer_reference, loyer_reference_majore, zone_encadrement_loyer, loyer_mode,
                                       modalite_recuperation_charges_locatives, depot_garantie,
                                       honoraires_etat_des_lieux, honoraires_location_bail,
                                       ancien_loyer_montant, ancien_loyer_date_revision,
                                       dpe_classe AS a_dpe, meuble
                                  FROM annonces
                                 WHERE id_bien=? AND (type_transaction='location' OR loyer>0 OR loyer_de_base>0 OR depot_garantie>0)
                                 ORDER BY id DESC LIMIT 1");
            $q->execute([$bienId]);
            if ($a = $q->fetch(PDO::FETCH_ASSOC)) {
                // Loyer & charges
                $loy = (float)($a['loyer_de_base'] ?: $a['loyer'] ?: 0);
                if ($loy > 0) $out['loyer_mensuel_hc'] = $loy;
                if ((float)($a['complement_loyer'] ?? 0) > 0) $out['complement_loyer'] = (float)$a['complement_loyer'];
                $ch = (float)($a['charges'] ?: 0); if ($ch <= 0 && (float)($a['charges_annuelles'] ?? 0) > 0) $ch = round((float)$a['charges_annuelles'] / 12, 2);
                if ($ch > 0) $out['charges_mensuelles'] = $ch;
                // Modalité charges → provisions / forfait
                $mrc = (string)($a['modalite_recuperation_charges_locatives'] ?? '');
                if (stripos($mrc, 'forfait') !== false) $out['charges_type'] = 'forfait';
                elseif (stripos($mrc, 'provision') !== false) $out['charges_type'] = 'provisions';
                // Zone tendue / références
                if ((float)($a['loyer_reference'] ?? 0) > 0)        $out['loyer_reference'] = (float)$a['loyer_reference'];
                if ((float)($a['loyer_reference_majore'] ?? 0) > 0) $out['loyer_reference_majore'] = (float)$a['loyer_reference_majore'];
                if (!empty($a['zone_encadrement_loyer']) || in_array((string)($a['loyer_mode'] ?? ''), ['majore', 'reference'], true)) $out['zone_tendue'] = 1;
                // Ancien loyer
                if ((float)($a['ancien_loyer_montant'] ?? 0) > 0)   $out['dernier_loyer_montant'] = (float)$a['ancien_loyer_montant'];
                if (!empty($a['ancien_loyer_date_revision']))       $out['dernier_loyer_date_revision'] = (string)$a['ancien_loyer_date_revision'];
                // Dépôt de garantie (sinon = 1 mois de loyer pour un nu, ajustable)
                if ((float)($a['depot_garantie'] ?? 0) > 0) $out['depot_garantie'] = (float)$a['depot_garantie'];
                elseif ($loy > 0 && empty($a['meuble'])) $out['depot_garantie'] = $loy;
                // Honoraires (montant imputé au LOCATAIRE ; le BAILLEUR paie au moins autant → même base)
                $hEdl = (float)($a['honoraires_etat_des_lieux'] ?? 0);
                $hBail = (float)($a['honoraires_location_bail'] ?? 0);
                if ($hBail > 0) { $out['hono_locataire_visite'] = $hBail; $out['hono_bailleur_visite'] = $hBail; }
                if ($hEdl > 0)  { $out['hono_locataire_edl'] = $hEdl;     $out['hono_bailleur_edl'] = $hEdl; }
                // DPE de l'annonce si le bien n'en a pas
                if (empty($out['dpe_classe']) && trim((string)($a['a_dpe'] ?? '')) !== '') $out['dpe_classe'] = strtoupper(trim((string)$a['a_dpe']));
            }
        } catch (Throwable) {}
        return $out;
    }
}

if (!function_exists('bail_habitation_decompte')) {
    /**
     * Décompte financier du bail HABITATION (source unique, réutilisable PDF + mail) :
     *  - « signature » : sommes à verser à l'entrée (loyer+charges+TF au prorata, honoraires
     *    locataire visite+EDL, dépôt de garantie). Pas de TVA, pas de droit d'entrée.
     *  - « echeance »  : montant d'une échéance de loyer (loyer + complément + charges
     *    + provision TF + assurance colocataires). Mensuel (pas de trimestriel).
     * @return array{signature:array<array{0:string,1:float}>, signature_total:float,
     *               echeance:array<array{0:string,1:float}>, echeance_total:float, prorata:array}
     */
    function bail_habitation_decompte(array $r): array {
        $n = static fn($k) => (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) ? (float)$r[$k] : 0.0;
        $loyer = $n('loyer_mensuel_hc'); $comp = $n('complement_loyer'); $charges = $n('charges_mensuelles');
        $tf = $n('provision_tf_mensuelle'); $assur = $n('assurance_colocataires_mensuel');
        $dg = $n('depot_garantie'); $honoLoc = $n('hono_locataire_visite') + $n('hono_locataire_edl');
        $loyerBase = $loyer + $comp;

        // Prorata de la 1re période, depuis prorata_date_debut sinon date_prise_effet.
        $ratio = 1.0; $prBase = ($r['prorata_date_debut'] ?? '') ?: ($r['date_prise_effet'] ?? ''); $prFin = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prBase)) {
            $ts = strtotime((string)$prBase); $dim = (int)date('t', $ts); $jj = (int)date('j', $ts);
            $ratio = $dim > 0 ? ($dim - $jj + 1) / $dim : 1.0; $prFin = date('Y-m-t', $ts);
        }
        // Franchise : prorata débutant APRÈS la date d'effet → pas de loyer à la signature.
        $franchise = false; $de = (string)($r['date_prise_effet'] ?? ''); $pd = (string)($r['prorata_date_debut'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $franchise = strtotime($pd) > strtotime($de);

        $sig = [];
        if (!$franchise) {
            if ($loyerBase > 0) $sig[] = ['1er loyer (prorata temporis)', $loyerBase * $ratio];
            if ($charges > 0)   $sig[] = ['Provision de charges (prorata)', $charges * $ratio];
            if ($tf > 0)        $sig[] = ['Provision de taxe foncière (prorata)', $tf * $ratio];
        }
        if ($honoLoc > 0) $sig[] = ['Honoraires à la charge du locataire (visite/dossier/rédaction + état des lieux)', $honoLoc];
        if ($dg > 0)      $sig[] = ['Dépôt de garantie', $dg];

        $ech = [['Loyer mensuel (hors complément)', $loyer]];
        if ($comp > 0)    $ech[] = ['Complément de loyer', $comp];
        if ($charges > 0) $ech[] = ['Provisions/forfait de charges', $charges];
        if ($tf > 0)      $ech[] = ['Provision de taxe foncière', $tf];
        if ($assur > 0)   $ech[] = ['Assurance pour compte des colocataires', $assur];

        return [
            'signature'       => $sig, 'signature_total' => array_sum(array_map(static fn($x) => $x[1], $sig)),
            'echeance'        => $ech, 'echeance_total'  => array_sum(array_map(static fn($x) => $x[1], $ech)),
            'prorata'         => ['ratio' => $ratio, 'debut' => $prBase, 'fin' => $prFin, 'franchise' => $franchise],
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

        /* ── LE TYPE COMMANDE LE DOCUMENT ────────────────────────────────────────────
           Ce générateur produit QUATRE baux de la loi 89 : nu (titre Ier), meublé
           (titre Ier bis), meublé étudiant (art. 25-7) et mobilité (titre Ier ter).
           Ils partagent le même squelette I → XV ; seuls quelques paragraphes divergent,
           et c'est exactement pourquoi ils vivent dans UN générateur : trois fichiers
           auraient garanti qu'à la prochaine évolution légale — un seuil de DPE, un
           plafond d'honoraires — on en corrige deux sur trois.
           Textes de référence : inc/modeles_texte/bail_*.txt (copie stricte MODELO). */
        require_once __DIR__ . '/bail_types_registry.php';
        $slug = bt_slug_depuis_bail($r) ?: 'hab_nu';
        $T    = bt_type($slug) ?: bt_type('hab_nu');
        $estMeuble   = in_array($slug, ['hab_meuble', 'hab_etudiant'], true);
        $estEtudiant = ($slug === 'hab_etudiant');
        $estMobilite = ($slug === 'hab_mobilite');
        $meubleOuMob = $estMeuble || $estMobilite;

        $h  = '<div class="doc">';
        $h .= '<h1>' . bcp_e($T['titre_doc']) . '</h1>';
        $h .= '<p class="sub">' . bcp_e($T['loi']) . '</p>';
        if ($ctx['numero_bail']) $h .= '<p class="ref">Référence : ' . bcp_e($ctx['numero_bail']) . '</p>';

        // ── I. DÉSIGNATION DES PARTIES ──
        $h .= '<h2>I. DÉSIGNATION DES PARTIES</h2>';
        $h .= '<p>Le présent contrat est conclu entre les soussignés :</p>';
        $h .= '<p class="clabel">Pour le bailleur</p>';
        $h .= '<p>' . $F($ctx['bailleur']['nom'] ?? '', 20) . ($ctx['bailleur']['adresse'] ? ', demeurant ' . bcp_e($ctx['bailleur']['adresse']) : ', demeurant ……………') . ',<br><span class="qual">Ci-après « le BAILLEUR », d\'une part,</span></p>';
        $h .= '<p class="clabel">Représenté(e)(s) par :</p><p>' . $mand . '</p>';
        /* ⚠️🔥 LE LOCATAIRE N ETAIT PAS NOMME DANS L ACTE.
           Ces colonnes `locataire_*` ne sont plus alimentees depuis que les parties
           viennent de `tiers_roles` (15/08). Le bail sortait donc avec
           « …………………… né(e) le …… à …… » à la place de l identite du preneur — dans
           la DESIGNATION DES PARTIES, c est-a-dire a l endroit meme qui dit qui
           s engage. Un contrat qui ne nomme pas l une de ses parties se signait
           ainsi, alors que la page de signature affichait le bon nom deux ecrans
           plus loin. On lit la source unique ; repli sur les colonnes pour les baux
           anterieurs a la bascule. */
        require_once __DIR__ . '/bail_locataires.php';
        $locsAct = [];
        try { $locsAct = bail_locataires_list($GLOBALS['pdo'], (int)($r['id'] ?? 0)); }
        catch (Throwable $e) { error_log('[bail_habitation locataires] ' . $e->getMessage()); }

        $h .= '<p class="clabel">Le' . (count($locsAct) > 1 ? 's Locataires' : ' Locataire') . '</p>';
        foreach ($locsAct as $lp) {
            /* Chaque colocataire est designe SEPAREMENT avec son etat civil : les
               fondre en une ligne empecherait de savoir qui est ne ou, et l article
               8-1 traite chacun comme un titulaire a part entiere. */
            $nomL = trim((string)($lp['nom_affichage'] ?: ($lp['raison_sociale']
                    ?: trim(((string)($lp['prenom'] ?? '')) . ' ' . ((string)($lp['nom'] ?? ''))))));
            $adrL = trim(implode(' ', array_filter([
                (string)($lp['adresse_ligne1'] ?? ''), (string)($lp['code_postal'] ?? ''), (string)($lp['ville'] ?? ''),
            ])));
            $h .= '<p>' . $F($nomL, 20)
                . (!empty($lp['date_naissance']) ? ' né(e) le ' . $D($lp['date_naissance']) : ' né(e) le ……………')
                . (!empty($lp['lieu_naissance']) ? ' à ' . bcp_e((string)$lp['lieu_naissance']) : ' à ……………')
                . (!empty($lp['nationalite']) ? ', de nationalité ' . bcp_e((string)$lp['nationalite']) : ', de nationalité ……………')
                . ($adrL !== '' ? ', demeurant ' . bcp_e($adrL) : ', demeurant ……………') . ',</p>';
        }
        // La qualité se pose UNE fois, après la liste : « Ci-après le LOCATAIRE »
        // désigne l'ensemble des colocataires, qui sont tenus solidairement.
        if ($locsAct) $h .= '<p><span class="qual">Ci-après « le LOCATAIRE », d\'autre part,</span></p>';

        $locNom = trim((string)($r['locataire_raison_sociale'] ?? '') ?: trim((string)($r['locataire_prenom'] ?? '') . ' ' . (string)($r['locataire_nom'] ?? '')));
        if (!$locsAct) $h .= '<p>' . $F($locNom, 20)
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
        // Descriptif structuré du bien — SOURCE UNIQUE (inc/bien_descriptif.php), repris du bien.
        require_once __DIR__ . '/bien_descriptif.php';
        $descBail = '';
        try {
            $qb = $GLOBALS['pdo']->prepare("SELECT b.*, COALESCE(bt.libelle, tb.libelle) AS _type_bien_libelle
                FROM biens b LEFT JOIN bien_types bt ON bt.id=b.id_bien_type LEFT JOIN types_bien tb ON tb.id=b.id_type_bien
                WHERE b.id=? LIMIT 1");
            $qb->execute([(int)($r['id_bien'] ?? 0)]);
            if ($bienStruct = $qb->fetch(PDO::FETCH_ASSOC)) $descBail = bien_descriptif_texte($bienStruct);
        } catch (Throwable) {}

        $desig = ($ctx['bien_adresse'] ? bcp_e($ctx['bien_adresse']) : '……………')
            . ($ctx['immeuble'] ? ', dépendant de l\'immeuble ' . bcp_e($ctx['immeuble']) : '')
            . (!empty($r['en_copropriete']) && $r['lot_copropriete'] ? ', lot de copropriété n° ' . bcp_e((string)$r['lot_copropriete']) . ($r['lot_tantiemes'] ? ' (' . bcp_e((string)$r['lot_tantiemes']) . ')' : '') : '')
            . '.';
        $h .= '<p>' . $desig . '</p>';
        // Reprise du descriptif du bien (type, surface, pièces, étage, extérieur, dépendances).
        if ($descBail !== '') {
            $h .= '<p>' . bcp_e($descBail) . '</p>';
        } else {
            $h .= '<p>' . ($rowNum('surface_habitable') !== null ? 'D\'une surface habitable de ' . bcp_e((string)$r['surface_habitable']) . ' m²' : 'D\'une surface habitable de …… m²')
                . ($rowNum('nb_pieces') !== null ? ', comprenant ' . (int)$r['nb_pieces'] . ' pièce(s) principale(s)' : '') . '.</p>';
        }
        $h .= '<p><b>Niveau de performance du logement (DPE) :</b> ' . $F($r['dpe_classe'] ?? '', 4) . '</p>';

        /* ⚠️ IDENTIFIANT FISCAL — exigé par les modèles MEUBLÉ et MOBILITÉ, absent du
           modèle NU. Asymétrie de MODELO conservée telle quelle : on ne l'ajoute pas au
           nu « par cohérence ». 12 caractères, les 2 premiers = département. */
        if ($meubleOuMob) {
            $h .= '<p><b>Identifiant fiscal du logement :</b> ' . $F($r['identifiant_fiscal'] ?? '', 12)
                . ' <span class="mut">(numéro invariant — rubrique « Gérer mes biens immobiliers » sur impots.gouv.fr)</span></p>';
        }
        $h .= '<p class="clabel">Destination des locaux</p><p>Les locaux sont loués pour un <b>usage exclusif d\'habitation principale.</b></p>';
        $h .= '<p class="clabel">Équipement d\'accès aux technologies de l\'information et de la communication</p><p>' . $F($r['equipement_tic'] ?? '', 20) . '</p>';
        /* ⚠️🔥 MOBILIER — le décret 2015-981 est REPRODUIT dans le contrat, ce n'est pas
           une annexe facultative : c'est lui qui fait qu'un logement mérite la
           qualification de « meublé », et donc le régime qui va avec (durée d'un an,
           dépôt à DEUX mois). L'inventaire lui-même est annexé à la remise des clés. */
        if ($meubleOuMob) {
            $h .= '<p class="clabel">Mobilier et équipements</p>';
            $h .= '<p>L\'inventaire et l\'état détaillé du mobilier fourni, établis lors de la remise des clefs du logement au LOCATAIRE, seront annexés à chacun des exemplaires du présent contrat de location.</p>';
            $h .= '<p class="sub2">Pour la bonne information des Parties, sont reproduites ci-après les dispositions des deux premiers articles du décret n° 2015-981 du 31 juillet 2015 fixant la liste des éléments de mobilier qu\'un logement meublé doit impérativement comporter.<br>'
                . '<b>Article 1 :</b> Chaque pièce d\'un logement meublé est équipée d\'éléments de mobilier conformes à sa destination.<br>'
                . '<b>Article 2 :</b> Le mobilier d\'un logement meublé, mentionné à l\'article 25-4 de la loi du 6 juillet 1989 susvisée, comporte au minimum les éléments suivants :</p>';
            $h .= '<ul>'
                . '<li>1° Literie comprenant couette ou couverture ;</li>'
                . '<li>2° Dispositif d\'occultation des fenêtres dans les pièces destinées à être utilisées comme chambre à coucher ;</li>'
                . '<li>3° Plaques de cuisson ;</li>'
                . '<li>4° Four ou four à micro-ondes ;</li>'
                . '<li>5° Réfrigérateur et congélateur ou, au minimum, un réfrigérateur doté d\'un compartiment permettant de disposer d\'une température inférieure ou égale à &minus;&nbsp;6&nbsp;°C ;</li>'
                . '<li>6° Vaisselle nécessaire à la prise des repas ;</li>'
                . '<li>7° Ustensiles de cuisine ;</li>'
                . '<li>8° Table et sièges ;</li>'
                . '<li>9° Étagères de rangement ;</li>'
                . '<li>10° Luminaires ;</li>'
                . '<li>11° Matériel d\'entretien ménager adapté aux caractéristiques du logement.</li></ul>';
        }

        /* ⚠️🔥 MOTIF DE MOBILITÉ — CONDITION D'ACCÈS AU RÉGIME (art. 25-12), pas un
           renseignement d'agrément. Sans motif justifié à la date de prise d'effet, ce
           n'est pas un bail mobilité : c'est un meublé ordinaire, avec sa durée d'un an,
           sa reconduction tacite et son dépôt de garantie. */
        if ($estMobilite) {
            $h .= '<h3>II bis. Motif justifiant le bénéfice du bail mobilité</h3>';
            $h .= '<p>À la date de prise d\'effet du présent contrat, le LOCATAIRE justifie être : <b>' . $F($r['mobilite_motif'] ?? '', 24) . '</b>.</p>';
            $h .= '<p class="sub2">Le bail mobilité est réservé au locataire qui, à la date de prise d\'effet du bail, justifie être en formation professionnelle, en études supérieures, en contrat d\'apprentissage, en stage, en engagement volontaire dans le cadre d\'un service civique, en mutation professionnelle ou en mission temporaire dans le cadre de son activité professionnelle.</p>';
        }


        // ── III. Date de prise d'effet et durée ──
        $h .= '<h3>III. Date de prise d\'effet et durée du contrat</h3>';
        $h .= '<p class="clabel">A. Date de prise d\'effet du contrat</p><p>Le présent bail prendra effet le ' . $D($r['date_prise_effet']) . '.</p>';
        $h .= '<p class="clabel">B. Durée du contrat</p>';
        /* ⚠️🔥 LA DURÉE ET LA RECONDUCTION SONT LE CŒUR DU RÉGIME.
           Nu : 3 ans (6 si bailleur personne morale), reconduction tacite.
           Meublé : 1 an, reconduction tacite d'un an.
           Étudiant : 9 mois, JAMAIS reconduit tacitement (art. 25-7 al. 4).
           Mobilité : 1 à 10 mois, NI renouvelable NI reconductible (titre Ier ter),
                      un seul avenant possible sans dépasser dix mois au total.
           Imprimer la reconduction sur un étudiant ou une mobilité fabriquerait un
           document qui contredit la loi dont il se réclame. */
        if ($estMobilite) {
            $dm = (int)($r['duree_mois'] ?? 0);
            $h .= '<p>Le présent bail est conclu pour une durée de <b>' . ($dm > 0 ? $dm . ' mois' : '…… mois') . '</b>, comprise entre un et dix mois.</p>';
            $h .= '<p><b>La durée du contrat est non renouvelable et non reconductible.</b> Toutefois, elle peut être modifiée une fois par avenant sans que la durée totale du contrat ne dépasse dix mois.</p>';
            $h .= '<p>Il est précisé que si, au terme du contrat, les parties concluent un nouveau bail portant sur le même logement meublé, ce nouveau bail est soumis aux dispositions du titre I<sup>er</sup> bis de la loi n° 89-462 du 6 juillet 1989.</p>';
            $h .= '<p>Le LOCATAIRE peut résilier le contrat à tout moment, sous réserve de respecter un délai de préavis d\'un mois. Le congé doit être notifié par lettre recommandée avec demande d\'avis de réception, signifié par acte de commissaire de justice, ou remis en main propre contre récépissé ou émargement. Le délai de préavis court à compter du jour de la réception de la lettre recommandée, de la signification de l\'acte, ou de la remise en main propre.</p>';
        } elseif ($estEtudiant) {
            $h .= '<p>Le présent bail est consenti à un locataire étudiant. Il est conclu pour une durée de <b>NEUF mois</b>, conformément aux dispositions du quatrième alinéa de l\'article 25-7 de la loi n° 89-462 du 6 juillet 1989.</p>';
            $h .= '<p><b>Les contrats de locations meublées consenties à un étudiant pour une durée de neuf mois ne sont pas reconduits tacitement à leur terme</b> et le locataire peut mettre fin au bail à tout moment, après avoir donné congé. Le bailleur peut, quant à lui, mettre fin au bail à son échéance et après avoir donné congé.</p>';
        } elseif ($estMeuble) {
            $h .= '<p>Le présent bail est conclu pour une durée d\'un an. <b>Il sera reconduit tacitement à son terme pour une durée d\'un an et dans les mêmes conditions.</b> Le LOCATAIRE peut mettre fin au bail à tout moment, après avoir donné congé. Le bailleur peut, quant à lui, mettre fin au bail à son échéance et après avoir donné congé, soit pour reprendre le logement en vue de l\'occuper lui-même ou une personne de sa famille, soit pour le vendre, soit pour un motif sérieux et légitime.</p>';
        } else {
            $h .= '<p>En l\'absence de proposition de renouvellement du contrat, celui-ci est, à son terme, reconduit tacitement pour une durée de 3 ou 6 ans et dans les mêmes conditions. Le LOCATAIRE peut mettre fin au bail à tout moment, après avoir donné congé. Le BAILLEUR, quant à lui, peut mettre fin au bail à son échéance et après avoir donné congé, soit pour reprendre le logement en vue de l\'occuper lui-même ou une personne de sa famille, soit pour le vendre, soit pour un motif sérieux et légitime.</p>';
        }

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
        /* ⚠️ § IV.C réservé à la location NUE : l'art. 23-1 ne prévoit la contribution au
           partage des économies de charges que pour le nu. Le modèle MEUBLÉ ne la porte
           pas — l'imprimer là serait ajouter une clause au modèle qu'on oppose. */
        if (!$meubleOuMob) {
            $h .= '<p class="clabel">C. Contribution pour le partage des économies de charges</p><p>Sans objet.</p>';
        }
        $h .= '<p class="clabel">D. Souscription par le BAILLEUR d\'une assurance pour le compte des colocataires</p><p>Le montant récupérable par douzième au titre de l\'assurance pour compte des colocataires est de ' . $E($rowNum('assurance_colocataires_mensuel')) . '.</p>';
        $h .= '<p class="clabel">E. Modalités de paiement</p><p>Le loyer est payable à échoir au plus tard le ' . $F($r['paiement_jour'] ?? '', 4) . ' de chaque mois entre les mains ' . $F($r['paiement_beneficiaire'] ?? ($ge['raison'] ?? ''), 16) . '.</p>';

        // ── Récapitulatifs financiers (helper centralisé, réutilisable PDF + mail) ──
        $dcp = bail_habitation_decompte($r);
        // Table B — montant d'une échéance de loyer (période complète)
        $h .= '<table class="tbl"><tr><th colspan="2">Montant total dû à chaque échéance de loyer (période complète de location)</th></tr>';
        foreach ($dcp['echeance'] as $ld) $h .= '<tr><td>' . bcp_e($ld[0]) . '</td><td class="who">' . $E($ld[1]) . '</td></tr>';
        $h .= '<tr><td><b>TOTAL par échéance</b></td><td class="who"><b>' . $E($dcp['echeance_total']) . '</b></td></tr></table>';
        // Table A — montant total à verser à l'entrée dans les lieux (à la signature)
        $h .= '<table class="tbl" style="margin-top:6px;"><tr><th colspan="2">Montant total à verser à l\'entrée dans les lieux (à la signature du bail)</th></tr>';
        if ($dcp['signature']) {
            foreach ($dcp['signature'] as $ld) $h .= '<tr><td>' . bcp_e($ld[0]) . '</td><td class="who">' . $E($ld[1]) . '</td></tr>';
        } else {
            $h .= '<tr><td colspan="2"><i>Aucune somme à verser à la signature (à compléter).</i></td></tr>';
        }
        $h .= '<tr><td><b>TOTAL à verser à la signature</b></td><td class="who"><b>' . $E($dcp['signature_total']) . '</b></td></tr></table>';
        if (!empty($dcp['prorata']['fin']) && $dcp['prorata']['ratio'] < 1) {
            $h .= '<p style="font-size:8.5pt;color:#444;">Le 1<sup>er</sup> loyer est calculé <i>prorata temporis</i> pour la période allant du ' . bcp_date($dcp['prorata']['debut']) . ' au ' . bcp_date($dcp['prorata']['fin']) . '.</p>';
        }
        /* ⚠️🔥 PARAGRAPHE QUI MANQUAIT PUREMENT ET SIMPLEMENT. Le générateur passait de E
           à G : le § « réévaluation d'un loyer manifestement sous-évalué » n'existait nulle
           part, et la numérotation sautait sans que rien ne le signale. Détecté le 15/08 en
           comparant le rendu au texte de référence MODELO — impossible à voir autrement.
           469 baux d'habitation concernés.
           Sur un bail étudiant ou mobilité il n'y a pas de renouvellement : MODELO imprime
           malgré tout la section avec « Sans objet. », on fait de même plutôt que de la
           masquer — une section absente laisse croire à un oubli. */
        $h .= '<p class="clabel">F. Exclusivement lors d\'un renouvellement de contrat, modalités de réévaluation d\'un loyer manifestement sous-évalué</p>';
        if ($estEtudiant || $estMobilite) {
            $h .= '<p>Sans objet.</p>';
        } else {
            $h .= '<p>' . $F($r['conditions_particulieres_loyer'] ?? '', 20) . '</p>';
        }
        $h .= '<p class="clabel">G. Dépenses énergétiques (pour information)</p><p>Montant estimé des dépenses annuelles d\'énergie pour un usage standard : entre ' . $E($rowNum('depenses_energie_min')) . ' et ' . $E($rowNum('depenses_energie_max')) . ' par an (estimation réalisée à partir des prix énergétiques de référence de l\'année ' . $F($r['depenses_energie_annee'] ?? '', 4) . ').</p>';

        // ── V. Travaux ──
        $h .= '<h3>V. Travaux réalisés ou à réaliser</h3>';
        $h .= '<p><b>Travaux réalisés :</b> ' . $F($r['travaux_realises_3ans'] ?? '', 20) . '</p>';
        $h .= '<p><b>Travaux à réaliser — Convention de travaux :</b> ' . $F($r['travaux_prevus_3ans'] ?? '', 20) . '</p>';

        // ── VI. Garantie ──
        $h .= '<h3>VI. Garantie</h3>';
        /* ⚠️🔥 DÉPÔT DE GARANTIE — trois régimes, dont une INTERDICTION.
           Nu : un mois de loyer hors charges (art. 22).
           Meublé et étudiant : DEUX mois (art. 25-6) — ce n'est pas une tolérance
             commerciale, c'est le plafond légal propre au meublé.
           Mobilité : INTERDIT. Le titre Ier ter fait « interdiction au bailleur d'exiger
             le versement d'un dépôt de garantie ». Le champ n'est pas seulement laissé
             vide : la clause elle-même change, et le montant ne doit pas s'imprimer. */
        if ($estMobilite) {
            $h .= '<p>Le présent bail étant soumis aux dispositions du titre I<sup>er</sup> ter de la loi n° 89-462 du 6 juillet 1989, <b>il est fait interdiction au bailleur d\'exiger le versement d\'un dépôt de garantie.</b></p>';
        } else {
            $plafond = $estMeuble ? 'deux mois' : 'un mois';
            $art     = $estMeuble ? 'article 25-6' : 'article 22';
            $h .= '<p>En vue de garantir l\'exécution de ses obligations, le LOCATAIRE verse ce jour la somme de ' . $E($rowNum('depot_garantie')) . ' entre les mains ' . $F($ge['raison'] ?? '', 12) . ' qui lui en donnera quittance. Ce dépôt de garantie ne peut excéder ' . $plafond . ' de loyer hors charges (' . $art . ' de la loi du 6 juillet 1989). En cas de colocation ou de cotitularité du présent bail, le dépôt de garantie ne sera restitué qu\'en fin de bail et après restitution totale des lieux loués.</p>';
        }

        // ── VII. Solidarité (verbatim) ──
        $h .= '<h3>VII. Solidarité - Indivisibilité</h3>';
        /* ⚠️🔥 LE MODÈLE MEUBLÉ NOMME L'EXTINCTION DE LA CAUTION, LE MODÈLE NU NON.
           « la solidarité d'un des colocataires ET CELLE DE LA PERSONNE QUI S'EST PORTÉE
           CAUTION POUR LUI prennent fin… ». L'article 8-1 VI s'applique pourtant aux deux
           régimes, et la notice légale le confirme sans distinguer : c'est une lacune de
           rédaction du modèle nu, pas une règle différente.
           Copie stricte oblige, on n'ajoute pas la phrase au nu — mais la règle métier
           d'extinction de la caution au congé s'applique aux DEUX. */
        if ($meubleOuMob) {
            $h .= '<p>Il est expressément stipulé que les copreneurs et toutes personnes pouvant se prévaloir des dispositions de l\'article 14 de la loi du 6 juillet 1989 seront tenus solidairement et indivisiblement de l\'exécution des obligations du présent contrat. Les cotitulaires soussignés, désignés sous le vocable « Le LOCATAIRE », reconnaissent expressément qu\'ils se sont engagés solidairement et que le BAILLEUR n\'a accepté de consentir le présent bail qu\'en considération de cette cotitularité solidaire et n\'aurait pas consenti la présente location à l\'un seulement d\'entre eux. Si un cotitulaire délivrait congé et quittait les lieux, il resterait en tout état de cause tenu du paiement des loyers et accessoires et, plus généralement, de toutes les obligations du bail en cours au moment de la délivrance du congé, et de ses suites et notamment des indemnités d\'occupation et de toutes sommes dues au titre des travaux de remise en état. La présente clause est une condition substantielle du contrat.</p>';
            $h .= '<p><b>En cas de colocation, la solidarité d\'un des colocataires et celle de la personne qui s\'est portée caution pour lui prennent fin à la date d\'effet du congé régulièrement délivré et lorsqu\'un nouveau colocataire figure au bail. À défaut, la solidarité du colocataire sortant s\'éteint au plus tard à l\'expiration d\'un délai de six mois après la date d\'effet du congé.</b></p>';
        } else {
        $h .= '<p>Il est expressément stipulé que les copreneurs et toutes personnes pouvant se prévaloir des dispositions de l\'article 14 de la loi du 6 juillet 1989 seront tenus solidairement et indivisiblement de l\'exécution des obligations du présent contrat. En cas de colocation, les colocataires soussignés, désignés sous le vocable « Le LOCATAIRE », reconnaissent expressément qu\'ils se sont engagés solidairement. Si un colocataire délivrait congé et quittait les lieux, il resterait tenu du paiement des loyers et accessoires et, plus généralement, de toutes les obligations du bail en cours au moment de la délivrance du congé, et de ses suites, au même titre que le(s) colocataire(s) demeuré(s) dans les lieux pendant une durée de six mois à compter de la date d\'effet du congé. Toutefois, cette solidarité prendra fin, avant l\'expiration de ce délai, si un nouveau colocataire, accepté par le BAILLEUR, figure au présent contrat. Le BAILLEUR n\'a accepté de consentir le présent bail qu\'en considération de cette cotitularité solidaire ; la présente clause est une condition substantielle. En cas de départ d\'un ou plusieurs colocataires, le dépôt de garantie ne sera restitué qu\'après libération totale des lieux et dans un délai maximum de deux mois à compter de la remise des clés.</p>';
        }

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
        /* ⚠️🔥 Ce bloc ne montrait QU'UN locataire et QU'UN bailleur, et ignorait
           purement et simplement les colocataires et les cautions — qui signent
           pourtant depuis le 15/08/2026. Un couple de colocataires voyait son
           deuxième signataire disparaître de l'acte. Bloc unique désormais. */
        require_once __DIR__ . '/bail_signature_bloc.php';
        $sigs = $ctx['signatures'] ?? [];
        $gar  = $ctx['garant'] ?? $ctx['caution'] ?? [];
        return bsb_bloc_signatures($sigs, [
            ['titre' => 'Le BAILLEUR', 'sous' => '(ou son mandataire dûment habilité)',
             'roles' => ['bailleur', 'mandataire']],
            ['titre' => 'Le LOCATAIRE', 'roles' => ['preneur', 'colocataire', 'locataire']],
            /* La caution n'apparaît que s'il y en a une : une case « LA CAUTION »
               vide sur un bail sans garant laisserait croire qu'il en manque une. */
            ['titre' => 'La CAUTION', 'roles' => ['caution'], 'masquer_si_absent' => true,
             /* ⚠️🔥 Formule d'AVANT la réforme du 15/09/2021 : sans plafond chiffré ni
                renonciation aux bénéfices de discussion et de division, un cautionnement
                recueilli ainsi est NUL (art. 2297 C. civ.). Une fois la caution signée,
                `bsb_cellule()` imprime la mention qu'elle a réellement apposée. */
             'mention' => 'Mention de l\'article 2297 du Code civil, apposée par la caution'],
        ]);
    }
}

if (!function_exists('bail_habitation_build_pdf')) {
    function bail_habitation_build_pdf(PDO $pdo, int $bailId, ?bool $forceProjet = null): string
    {
        $ctx = bail_habitation_context($pdo, $bailId);
        if ($ctx === null) throw new RuntimeException('Bail #' . $bailId . ' introuvable.');
        // Signatures (tracés) pour le bloc signatures.
        try {
            /* ⚠️ Deux requêtes : `signature_mode` peut manquer si la migration
               20260818a n a pas encore été jouée sur cet environnement. Le repli
               garde le bail imprimable — sans la mention du mode, rien de plus. */
            try { $qs = $pdo->prepare("SELECT role_code, nom_signataire, signature_data, signature_mode, statut, signed_at FROM bail_signatures WHERE id_bail=? ORDER BY id ASC"); $qs->execute([$bailId]); }
            catch (Throwable) {
                // Repli sans signature_mode ; s'il échoue aussi, le try extérieur prend le relais.
                $qs = $pdo->prepare("SELECT role_code, nom_signataire, signature_data, statut, signed_at FROM bail_signatures WHERE id_bail=? ORDER BY id ASC");
                $qs->execute([$bailId]);
            }
            $ctx['signatures'] = $qs ? ($qs->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) { $ctx['signatures'] = []; }

        $withProjet = $forceProjet !== null ? $forceProjet : in_array($ctx['statut'], ['projet', 'envoye', 'brouillon'], true);
        /* ⚠️ L'ENVELOPPE PDF EST COMMUNE, LE CORPS NE L'EST PAS. Le bail civil personne
           morale a sa propre structure (1.1→1.14 / 2.1→2.9) et son propre générateur ;
           il partage en revanche la feuille de style et la mise en page. Aiguiller ICI
           évite de dupliquer trente lignes de CSS et le bloc de signatures. */
        if ((string)($ctx['row']['bail_nature'] ?? '') === 'civil' && is_file(__DIR__ . '/bail_civil_pdf.php')) {
            require_once __DIR__ . '/bail_civil_pdf.php';
            $body = bail_civil_corps($ctx);
        } else {
            $body = bail_habitation_corps($ctx);
        }
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
        // Version PROJET : horodater l'émission de CE PDF (chaque instantané est daté →
        // le destinataire distingue la dernière version d'un ancien PDF reçu par email).
        $projetStamp = $withProjet
            ? '<br><span style="color:#b5352e;font-weight:bold;">Version PROJET &mdash; &eacute;mise le ' . date('d/m/Y') . ' &agrave; ' . date('H\hi') . ' &mdash; seule la version pr&eacute;sent&eacute;e au moment de la signature fait foi.</span>'
            : '';
        $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:7.5pt;color:#999;border-top:0.4pt solid #ddd;padding-top:3px;">Bail habitation (loi 89-462)' . ($ctx['numero_bail'] ? ' &mdash; ' . bcp_e($ctx['numero_bail']) : '') . ' &mdash; page {PAGENO}/{nbpg}' . $projetStamp . '</div>');
        if ($withProjet) { $mpdf->SetWatermarkText('PROJET'); $mpdf->showWatermarkText = true; $mpdf->watermarkTextAlpha = 0.08; $mpdf->watermark_font = 'DejaVuSans'; }
        $mpdf->WriteHTML($css . $body . $annexes);

        $tmp = sys_get_temp_dir() . '/bailhab_' . $bailId . '_' . ($withProjet ? 'projet' : 'def') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $mpdf->Output($tmp, \Mpdf\Output\Destination::FILE);
        return $tmp;
    }
}
