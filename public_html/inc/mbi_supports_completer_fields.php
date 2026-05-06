<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_completer_fields.php
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Mapping : code de bloc dur (retourné par mbi_supports_critic_check) →
 * définition du ou des champs à compléter dans la modale.
 *
 * Le mapping s'adapte au CONTEXTE du bien :
 *   transaction : 'vente' | 'location' | 'entreprise'
 *   est_copro   : bool
 *
 * Format d'un champ :
 *   [
 *     'entity'   => 'bien' | 'agence' | 'mandat' | 'user',
 *     'table'    => 'biens' | 'agences' | 'mandats' | 'users',
 *     'column'   => 'nom_colonne_sql',
 *     'label'    => 'Libellé pour l'utilisateur',
 *     'type'     => 'text' | 'number' | 'textarea' | 'select' | 'date' | 'checkbox',
 *     'hint'     => '?str — aide affichée sous le champ',
 *     'options'  => ['valeur' => 'libellé', …] (uniquement pour select),
 *     'required' => bool,
 *   ]
 *
 * API publique :
 *   mbi_supports_completer_contexte_resoudre(array $bienCtx): array
 *   mbi_supports_completer_fields_pour_code(string $code, array $contexte = []): array
 *   mbi_supports_completer_fields_map(array $contexte = []): array
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_completer_contexte_resoudre')) {
    /**
     * Résout le contexte métier d'un bien (transaction + copro + résidentiel)
     * à partir du contexte chargé par mbi_supports_critic_load_contexte().
     *
     * Le contexte sert à filtrer dynamiquement les champs proposés dans la
     * modale (ex : pas de DPE pour un parking, pas de copro pour une maison
     * en pleine propriété, pas de prix de vente pour une location).
     *
     * @param array $ctx  Contexte critic : ['bien'=>..., 'annonce'=>..., 'est_copro'=>bool]
     * @return array{transaction:string, est_copro:bool, categorie:?string, is_residentiel:bool}
     */
    function mbi_supports_completer_contexte_resoudre(array $ctx): array
    {
        $bien    = $ctx['bien']    ?? [];
        $annonce = $ctx['annonce'] ?? null;

        // 1. Transaction : vente / location depuis l'annonce, ou depuis le bien si dispo
        $tx = strtolower(trim((string)(
            ($annonce['type_transaction'] ?? null)
            ?? ($bien['type_transaction'] ?? null)
            ?? ''
        )));

        // 2. Catégorie (résidentiel/pro/commerce/terrain/parking) via bien_types
        $categorie = strtolower(trim((string)(
            $bien['_bien_type_categorie'] ?? $bien['categorie'] ?? ''
        )));
        // Si pas pré-chargée par la card, on tente une lecture rapide
        if ($categorie === '' && !empty($bien['id_bien_type'])) {
            try {
                $pdo = $GLOBALS['pdo'] ?? db();
                $st = $pdo->prepare("SELECT categorie FROM bien_types WHERE id = :id LIMIT 1");
                $st->execute([':id' => (int)$bien['id_bien_type']]);
                $categorie = strtolower((string)($st->fetchColumn() ?: ''));
            } catch (Throwable) {}
        }

        // 3. Normalisation transaction
        // Toute catégorie professionnel/commerce → "entreprise" (peu importe vente/loc)
        // Sinon vente / location selon l'annonce
        if (in_array($categorie, ['professionnel', 'commerce'], true)) {
            $transaction = 'entreprise';
        } elseif ($tx === 'location') {
            $transaction = 'location';
        } else {
            $transaction = 'vente'; // défaut
        }

        $isResidentiel = ($categorie === 'habitation' || $categorie === '');

        return [
            'transaction'    => $transaction,
            'est_copro'      => (bool)($ctx['est_copro'] ?? false),
            'categorie'      => $categorie ?: null,
            'is_residentiel' => $isResidentiel,
        ];
    }
}

if (!function_exists('mbi_supports_completer_fields_pour_code')) {
    /**
     * Renvoie les champs à proposer pour un code donné, filtrés selon le
     * contexte (vente / location / entreprise, copro ou non).
     *
     * Si le code est inconnu, renvoie un tableau vide.
     */
    function mbi_supports_completer_fields_pour_code(string $code, array $contexte = []): array
    {
        $map = mbi_supports_completer_fields_map($contexte);
        return $map[$code] ?? [];
    }
}

if (!function_exists('mbi_supports_completer_fields_map')) {
    function mbi_supports_completer_fields_map(array $contexte = []): array
    {
        $tx       = (string)($contexte['transaction'] ?? 'vente');
        $estCopro = (bool)($contexte['est_copro'] ?? false);

        // ── Définitions atomiques (réutilisées plusieurs fois) ────────────
        $f = [
            'prix_vente'           => ['entity'=>'bien','table'=>'biens','column'=>'prix_vente','label'=>'Prix de vente (€)','type'=>'number','hint'=>'Prix honoraires inclus si charge acquéreur','required'=>true],
            'surface_habitable'    => ['entity'=>'bien','table'=>'biens','column'=>'surface_habitable','label'=>'Surface habitable (m²)','type'=>'number','hint'=>'Loi Carrez si applicable','required'=>true],
            'surface_utile'        => ['entity'=>'bien','table'=>'biens','column'=>'surface_habitable','label'=>'Surface utile (m²)','type'=>'number','hint'=>'Surface exploitable du local','required'=>true],
            'id_type_bien'         => ['entity'=>'bien','table'=>'biens','column'=>'id_type_bien','label'=>'Type de bien','type'=>'select_db','hint'=>'Sélectionne le type métier','required'=>true],
            'ville'                => ['entity'=>'bien','table'=>'biens','column'=>'ville','label'=>'Ville','type'=>'text','required'=>true],
            'code_postal'          => ['entity'=>'bien','table'=>'biens','column'=>'code_postal','label'=>'Code postal','type'=>'text'],
            'description'          => ['entity'=>'bien','table'=>'biens','column'=>'description','label'=>'Description du bien','type'=>'textarea','hint'=>'Au moins 200 caractères, mentionne pièces principales, exposition, atouts','required'=>true],

            // DPE — résidentiel + tertiaire
            'dpe_classe'           => ['entity'=>'bien','table'=>'biens','column'=>'dpe_classe','label'=>'Classe DPE','type'=>'select','options'=>['' => '— non renseigné —','A'=>'A','B'=>'B','C'=>'C','D'=>'D','E'=>'E','F'=>'F','G'=>'G']],
            'ges_classe'           => ['entity'=>'bien','table'=>'biens','column'=>'ges_classe','label'=>'Classe GES','type'=>'select','options'=>['' => '— non renseigné —','A'=>'A','B'=>'B','C'=>'C','D'=>'D','E'=>'E','F'=>'F','G'=>'G']],
            'dpe_statut'           => ['entity'=>'bien','table'=>'biens','column'=>'dpe_statut','label'=>'Statut DPE','type'=>'select','options'=>['present'=>'Présent','en_cours'=>'En cours','non_soumis'=>'Non soumis (R126-15)','manquant'=>'Manquant'],'hint'=>'Si pas de classe, choisir « en cours » ou « non soumis »'],

            // Honoraires vente
            'honoraires_montant'    => ['entity'=>'bien','table'=>'biens','column'=>'honoraires_montant','label'=>'Montant des honoraires (€)','type'=>'number'],
            'honoraires_charge'     => ['entity'=>'bien','table'=>'biens','column'=>'honoraires_charge','label'=>'Charge des honoraires','type'=>'select','options'=>['' => '—','acquereur'=>'Acquéreur','vendeur'=>'Vendeur','partage'=>'Partagée'],'required'=>true],
            'honoraires_inclus'     => ['entity'=>'bien','table'=>'biens','column'=>'honoraires_inclus','label'=>'Honoraires inclus','type'=>'text','hint'=>'Ex : « charge acquéreur »'],
            'honoraires_detail'     => ['entity'=>'bien','table'=>'biens','column'=>'honoraires_detail','label'=>'Détail honoraires','type'=>'textarea'],
            'prix_hors_honoraires'  => ['entity'=>'bien','table'=>'biens','column'=>'prix_hors_honoraires','label'=>'Prix hors honoraires (€)','type'=>'number','hint'=>'Affiché en complément si charge acquéreur'],
            'bareme_url'            => ['entity'=>'agence','table'=>'agences','column'=>'bareme_url','label'=>'URL du barème honoraires de l\'agence','type'=>'text','hint'=>'Lien public vers le barème (PDF ou page)','required'=>true],

            // Honoraires location (ALUR) — sur l'annonce
            'honoraires_location_bail'  => ['entity'=>'annonce','table'=>'annonces','column'=>'honoraires_location_bail','label'=>'Honoraires bail (€ TTC)','type'=>'number','hint'=>'Plafond ALUR appliqué automatiquement'],
            'honoraires_etat_des_lieux' => ['entity'=>'annonce','table'=>'annonces','column'=>'honoraires_etat_des_lieux','label'=>'Honoraires état des lieux (€ TTC)','type'=>'number','hint'=>'Plafond ALUR appliqué automatiquement'],

            // Loyer + dépôt (location uniquement, sur l'annonce)
            'loyer_hc'         => ['entity'=>'annonce','table'=>'annonces','column'=>'loyer','label'=>'Loyer hors charges (€/mois)','type'=>'number','hint'=>'Loyer mensuel hors charges','required'=>true],
            'loyer_cc'         => ['entity'=>'annonce','table'=>'annonces','column'=>'loyer_cc','label'=>'Loyer charges comprises (€/mois)','type'=>'number','hint'=>'Calculé automatiquement après saisie loyer + charges'],
            'depot_garantie'   => ['entity'=>'annonce','table'=>'annonces','column'=>'depot_garantie','label'=>'Dépôt de garantie (€)','type'=>'number','hint'=>'1 mois de loyer HC pour vide, 2 mois pour meublé','required'=>true],
            'meuble'           => ['entity'=>'annonce','table'=>'annonces','column'=>'meuble','label'=>'Logement meublé','type'=>'checkbox','hint'=>'Cocher si location meublée (impacte le dépôt de garantie)'],
            'loyer_mode'       => ['entity'=>'annonce','table'=>'annonces','column'=>'loyer_mode','label'=>'Mode du loyer','type'=>'select',
                                    'options'=>['libre'=>'Libre','majore'=>'Majoré (zone encadrée)','reference'=>'Référence (zone encadrée)','minore'=>'Minoré'],'required'=>true],

            // Carte pro / agence
            'carte_pro_numero'      => ['entity'=>'agence','table'=>'agences','column'=>'carte_pro_numero','label'=>'Numéro carte pro','type'=>'text','required'=>true],
            'carte_pro_validite'    => ['entity'=>'agence','table'=>'agences','column'=>'carte_pro_validite','label'=>'Date de validité carte pro','type'=>'date','hint'=>'Doit être dans le futur'],
            'carte_pro_cci'         => ['entity'=>'agence','table'=>'agences','column'=>'carte_pro_cci','label'=>'CCI émettrice','type'=>'text'],
            'garant_financier'      => ['entity'=>'agence','table'=>'agences','column'=>'garant_financier','label'=>'Garant financier','type'=>'text','hint'=>'Ex : « Galian, Paris — 120 000 € »','required'=>true],
            'rc_pro'                => ['entity'=>'agence','table'=>'agences','column'=>'rc_pro','label'=>'Assurance RC professionnelle','type'=>'text','hint'=>'Ex : « MMA, contrat n°… »','required'=>true],
            'nom_agence'            => ['entity'=>'agence','table'=>'agences','column'=>'nom_agence','label'=>'Nom de l\'agence','type'=>'text','required'=>true],
            'adresse_agence'        => ['entity'=>'agence','table'=>'agences','column'=>'adresse','label'=>'Adresse agence','type'=>'text'],
            'cp_agence'             => ['entity'=>'agence','table'=>'agences','column'=>'code_postal','label'=>'CP agence','type'=>'text'],
            'ville_agence'          => ['entity'=>'agence','table'=>'agences','column'=>'ville','label'=>'Ville agence','type'=>'text'],
            'tel_agence'            => ['entity'=>'agence','table'=>'agences','column'=>'telephone','label'=>'Téléphone agence','type'=>'text'],
            'email_agence'          => ['entity'=>'agence','table'=>'agences','column'=>'email','label'=>'Email agence','type'=>'text'],

            // Mandat / autorisation
            'autorisation_diffusion'=> ['entity'=>'mandat','table'=>'mandats','column'=>'autorisation_diffusion','label'=>'Autorisation de diffusion signée','type'=>'checkbox','hint'=>'Cocher si le mandat autorise la diffusion sur les portails','required'=>true],
            'numero_registre'       => ['entity'=>'mandat','table'=>'mandats','column'=>'numero_registre','label'=>'Numéro de registre du mandat','type'=>'text','required'=>true],
            'duree_mois'            => ['entity'=>'mandat','table'=>'mandats','column'=>'duree_mois','label'=>'Durée du mandat (mois)','type'=>'number','hint'=>'Ex : 3, 6, 12','required'=>true],

            // Copropriété (résidentiel uniquement, si est_copro)
            'copro_nb_lots'         => ['entity'=>'bien','table'=>'biens','column'=>'copro_nb_lots','label'=>'Nombre de lots de la copropriété','type'=>'number','required'=>true],
            'copro_charges'         => ['entity'=>'bien','table'=>'biens','column'=>'copro_charges_annuelles','label'=>'Charges annuelles copro (€/an)','type'=>'number','required'=>true],
            'copro_l611'            => ['entity'=>'bien','table'=>'biens','column'=>'copro_procedures_l611','label'=>'Procédures L.611-1 en cours','type'=>'select','options'=>['' => '—','0'=>'Non','1'=>'Oui'],'required'=>true],

            // ERP / risques
            'erp_present'           => ['entity'=>'bien','table'=>'biens','column'=>'erp_present','label'=>'État des risques (ERP) joint au dossier','type'=>'checkbox','required'=>true],

            // Négociateur
            'nego_email'            => ['entity'=>'user','table'=>'users','column'=>'email','label'=>'Email négociateur','type'=>'text'],
            'nego_telephone'        => ['entity'=>'user','table'=>'users','column'=>'telephone','label'=>'Téléphone négociateur','type'=>'text'],
        ];

        // ── Champs typés selon contexte de transaction ────────────────────
        // Honoraires : remplace selon transaction
        $honorairesFields = match ($tx) {
            'location'   => [$f['honoraires_location_bail'], $f['honoraires_etat_des_lieux']],
            'entreprise' => [$f['honoraires_montant'], $f['honoraires_charge'], $f['honoraires_detail']],
            default      => [$f['honoraires_montant'], $f['honoraires_charge'], $f['honoraires_inclus']], // vente
        };

        // Surface : libellé adapté en entreprise
        $surfaceField = $tx === 'entreprise' ? $f['surface_utile'] : $f['surface_habitable'];

        // Prix vente / loyer selon la transaction
        $prixFields = match ($tx) {
            'location' => [$f['loyer_hc'], $f['depot_garantie'], $f['meuble']],
            default    => [$f['prix_vente']], // vente + entreprise
        };

        // Adresse / ville : toujours
        $adresseFields = [$f['ville'], $f['code_postal']];

        // DPE : oui pour habitation et tertiaire (>50m²), V1 simplifié = oui sauf si on a un type sans DPE
        $dpeFields = [$f['dpe_classe'], $f['ges_classe'], $f['dpe_statut']];

        // Copro : seulement si est_copro
        $coproFields = $estCopro
            ? [$f['copro_nb_lots'], $f['copro_charges'], $f['copro_l611']]
            : [];

        // ── Mapping codes → champs (clé de regroupement compacte) ──────────
        return [

            // Bien — transaction & informations de base
            // PRIX_DEFINI : remplacé en location par LOYER_DEFINI (cf. critic engine).
            // En vente, demande prix_vente. En location, ce code n'est plus émis.
            'PRIX_DEFINI'          => $tx === 'location' ? [] : [$f['prix_vente']],
            'LOYER_DEFINI'         => $tx === 'location' ? [$f['loyer_hc']] : [],
            'DEPOT_GARANTIE_DEFINI'=> $tx === 'location' ? [$f['depot_garantie'], $f['meuble']] : [],
            'SURFACE_DEFINIE'      => [$surfaceField],
            'TYPE_BIEN_DEFINI'     => [$f['id_type_bien']],
            'ADRESSE_VILLE'        => $adresseFields,

            // Description
            'DESCRIPTION_FAIBLE'    => [$f['description']],
            'DESCRIPTION_GENERIQUE' => [$f['description']],

            // DPE / GES (pas pour terrains/parkings — détecté côté contexte si besoin V2)
            'DPE_STATUT_VALIDE'    => $dpeFields,
            'AFFICHE_DPE_GES_CLASSE' => [$f['dpe_classe'], $f['ges_classe']],

            // Honoraires (varient selon transaction)
            'HONORAIRES_RENSEIGNES' => $honorairesFields,
            'HONO_MONTANT'          => $tx === 'location' ? [$f['honoraires_location_bail']] : [$f['honoraires_montant']],
            'HONO_CHARGE'           => $tx === 'location' ? [] : [$f['honoraires_charge']],
            'HONO_PRIX_HORS_HONO'   => $tx === 'location' ? [] : [$f['prix_hors_honoraires']],
            'HONO_BAREME_AGENCE'    => [$f['bareme_url']],
            'FICHE_HONORAIRES_DETAIL' => [$f['honoraires_detail']],

            // Carte pro & agence
            'CARTE_PRO'                  => [$f['carte_pro_numero'], $f['carte_pro_validite'], $f['carte_pro_cci']],
            'CARTE_PRO_NUMERO'           => [$f['carte_pro_numero']],
            'CARTE_PRO_CCI'              => [$f['carte_pro_cci']],
            'CARTE_PRO_VALIDITE'         => [$f['carte_pro_validite']],
            'CARTE_PRO_GARANT_FINANCIER' => [$f['garant_financier']],
            'CARTE_PRO_RC_PRO'           => [$f['rc_pro']],
            'AFFICHE_AGENCE_NOM'         => [$f['nom_agence']],
            'FICHE_AGENCE_COORDONNEES'   => [$f['adresse_agence'], $f['cp_agence'], $f['ville_agence'], $f['tel_agence'], $f['email_agence']],

            // Mandat
            'MANDAT_ACTIF'                  => [], // redirection (création de mandat ailleurs)
            'AUTORISATION_DIFFUSION'        => [$f['autorisation_diffusion']],
            'MANDAT_NUMERO_REGISTRE'        => [$f['numero_registre']],
            'DOSSIER_DUREE_MANDAT_PROPOSEE' => [$f['duree_mois']],

            // Copropriété (vide si pas en copro → champs disparaissent)
            'COPRO_NB_LOTS'             => $coproFields ? [$f['copro_nb_lots']] : [],
            'AFFICHE_COPRO_LOTS'        => $coproFields ? [$f['copro_nb_lots']] : [],
            'COPRO_QUOTE_PART_CHARGES'  => $coproFields ? [$f['copro_charges']] : [],
            'AFFICHE_COPRO_CHARGES'     => $coproFields ? [$f['copro_charges']] : [],
            'COPRO_PROCEDURES_L611'     => $coproFields ? [$f['copro_l611']] : [],
            'AFFICHE_COPRO_PROCEDURES'  => $coproFields ? [$f['copro_l611']] : [],

            // ERP / risques
            'RISQUES_ERP_DISPONIBLE' => [$f['erp_present']],
            'FICHE_RISQUES_ERP'      => [$f['erp_present']],

            // Négociateur
            'NEGOCIATEUR_RATTACHE'           => [],
            'FICHE_NEGOCIATEUR_COORDONNEES'  => [$f['nego_email'], $f['nego_telephone']],

            // Configuration globale (pas de saisie modale)
            'MENTIONS_NON_CONFIGUREES' => [],
            'MENTIONS_JSON_INVALIDE'   => [],
            'BIEN_INTROUVABLE'         => [],
            'AGENCE_RATTACHEE'         => [],
            'PHOTO_EXPLOITABLE'        => [], // upload géré ailleurs
        ];
    }
}
