<?php
/**
 * Migration : Ma Box Communication — Seed mentions légales v1
 *
 * Insere la version "2026-05-01.v1" du referentiel des mentions legales
 * (brouillon fonctionnel, valide_juridiquement = 0).
 *
 * Source : docs/mentions_legales_supports.md
 *
 * Idempotent : INSERT IGNORE (uk_version sur version) — re-jouable sans casse.
 * Avant insertion : desactive toute version active precedente.
 * Apres insertion : force la v1 a actif=1 (corrige le bug initial qui
 * laissait la BDD sans version active si la migration etait re-jouee).
 */

return [
    'id'          => '20260501_mbi_supports_06_seed_mentions_v1',
    'title'       => 'Ma Box Communication — seed mentions légales v1',
    'description' => "Insere la version 2026-05-01.v1 du referentiel des mentions legales (brouillon fonctionnel, non valide juridiquement). Inclut le bloc dur transverse, les regles par type de support (affiche / fiche client / interne / dossier), et les specifiques (DPE, honoraires, copropriete, ERP, mandat, carte pro). Activee automatiquement.",
    'created_at'  => '2026-05-01',
    'sql' => <<<'SQL'
UPDATE `mbi_supports_mentions_versions` SET `actif` = 0 WHERE `actif` = 1;

INSERT IGNORE INTO `mbi_supports_mentions_versions`
  (`version`, `libelle`, `description`,
   `regles_json`,
   `concerne_vente`, `concerne_location`, `concerne_copro`,
   `actif`, `date_application`,
   `valide_juridiquement`,
   `source_reference`, `commentaire_validation`,
   `created_by`)
VALUES
  ('2026-05-01.v1',
   'Mentions légales V1 — Vente (brouillon fonctionnel)',
   'Référentiel V1 : vente + copropriété + DPE/GES + honoraires + ERP + carte pro. Location prévue V2.',
   '{
  "version": "2026-05-01.v1",
  "transverse": {
    "bloc_dur": [
      { "code": "MANDAT_ACTIF",          "regle": "mandat_actif_existe" },
      { "code": "AUTORISATION_DIFFUSION","regle": "autorisation_diffusion_signee" },
      { "code": "PRIX_DEFINI",           "champ": "biens.prix_vente",        "predicat": "gt:0" },
      { "code": "SURFACE_DEFINIE",       "champ": "biens.surface_habitable", "predicat": "gt:0" },
      { "code": "TYPE_BIEN_DEFINI",      "champ": "biens.id_type_bien",      "predicat": "not_null" },
      { "code": "ADRESSE_VILLE",         "champ": "biens.ville",             "predicat": "not_empty" },
      { "code": "HONORAIRES_RENSEIGNES", "regle": "honoraires_renseignes" },
      { "code": "AGENCE_RATTACHEE",      "champ": "biens.id_agence",         "predicat": "gt:0" },
      { "code": "NEGOCIATEUR_RATTACHE",  "regle": "negociateur_rattache" },
      { "code": "CARTE_PRO",             "regle": "carte_pro_valide" },
      { "code": "PHOTO_EXPLOITABLE",     "regle": "au_moins_une_photo_exploitable" },
      { "code": "DPE_STATUT_VALIDE",     "regle": "dpe_statut_valide" }
    ],
    "alertes": [
      { "code": "PHOTO_PRINCIPALE_SOMBRE",     "regle": "luminosite_photo_hero_basse" },
      { "code": "PHOTOS_PEU_NOMBREUSES",       "regle": "nb_photos_lt_5" },
      { "code": "DESCRIPTION_FAIBLE",          "regle": "description_lt_200" },
      { "code": "DESCRIPTION_GENERIQUE",       "regle": "description_generique" },
      { "code": "DPE_DEFAVORABLE_NON_TRAITE",  "regle": "dpe_E_F_G_sans_angle" }
    ]
  },
  "par_type_support": {
    "affiche_vitrine": {
      "bloc_dur": [
        { "code": "AFFICHE_DPE_GES_CLASSE", "regle": "dpe_ges_affiches_ou_motif" },
        { "code": "AFFICHE_PRIX_HONORAIRES","regle": "prix_avec_honoraires" },
        { "code": "AFFICHE_AGENCE_NOM",     "regle": "agence_nom_visible" },
        { "code": "AFFICHE_CARTE_PRO",      "regle": "carte_pro_valide" }
      ],
      "bloc_dur_si_copro": [
        { "code": "AFFICHE_COPRO_LOTS",       "regle": "copro_nb_lots" },
        { "code": "AFFICHE_COPRO_CHARGES",    "regle": "copro_quote_part_charges" },
        { "code": "AFFICHE_COPRO_PROCEDURES", "regle": "copro_procedures_l611" }
      ]
    },
    "fiche_client": {
      "bloc_dur": [
        { "code": "FICHE_RISQUES_ERP",            "regle": "erp_disponible" },
        { "code": "FICHE_DPE_DETAIL",             "regle": "dpe_ges_affiches_ou_motif" },
        { "code": "FICHE_HONORAIRES_DETAIL",      "regle": "honoraires_detail" },
        { "code": "FICHE_AGENCE_COORDONNEES",     "regle": "agence_coordonnees_completes" },
        { "code": "FICHE_NEGOCIATEUR_COORDONNEES","regle": "negociateur_coordonnees_completes" },
        { "code": "FICHE_MENTION_INFORMATION",    "regle": "info_precontractuelle" }
      ],
      "bloc_dur_si_copro": [
        { "code": "AFFICHE_COPRO_LOTS",       "regle": "copro_nb_lots" },
        { "code": "AFFICHE_COPRO_CHARGES",    "regle": "copro_quote_part_charges" },
        { "code": "AFFICHE_COPRO_PROCEDURES", "regle": "copro_procedures_l611" }
      ]
    },
    "fiche_visite_interne": {
      "bloc_dur": [
        { "code": "INTERNE_FILIGRANE",      "regle": "interne_marquage" },
        { "code": "INTERNE_BADGE_ROUGE",    "regle": "interne_marquage" },
        { "code": "INTERNE_HORS_PUBLIC",    "regle": "interne_acces_reserve" },
        { "code": "INTERNE_NOM_FICHIER",    "regle": "interne_nom_fichier" },
        { "code": "INTERNE_JOURNALISATION", "regle": "interne_journalisation" }
      ],
      "exonerations": "mentions_publiques_non_applicables"
    },
    "dossier_presentation": {
      "bloc_dur": [
        { "code": "DOSSIER_AGENCE_PRESENTATION",     "regle": "agence_presentation_dossier" },
        { "code": "DOSSIER_NEGOCIATEUR_PRESENTATION","regle": "negociateur_presentation_dossier" },
        { "code": "DOSSIER_STRATEGIE_DIFFUSION",     "regle": "strategie_diffusion_definie" },
        { "code": "DOSSIER_HONORAIRES_BAREME",       "regle": "honoraires_bareme" },
        { "code": "DOSSIER_DUREE_MANDAT_PROPOSEE",   "regle": "duree_mandat_proposee" },
        { "code": "MANDAT_NUMERO_REGISTRE",          "regle": "mandat_numero_registre" }
      ]
    },
    "email": {
      "bloc_dur": [
        { "code": "EMAIL_EXPEDITEUR_AGENCE", "regle": "email_signature_agence" },
        { "code": "EMAIL_DESINSCRIPTION",    "regle": "email_lien_desinscription" },
        { "code": "EMAIL_RGPD_MENTION",      "regle": "email_mention_rgpd" }
      ]
    },
    "reseaux_sociaux": {
      "bloc_dur": [],
      "todo": "À cadrer V4"
    }
  },
  "specifiques": {
    "dpe_ges": {
      "matrice_statut": {
        "present":     { "bloc_dur": false, "alerte_si_E_F_G_non_traite": true },
        "en_cours":    { "bloc_dur": false, "mention_obligatoire": "dpe_en_cours" },
        "non_soumis":  { "bloc_dur": false, "mention_obligatoire": "dpe_non_soumis_R126_15" },
        "manquant":    { "bloc_dur": true,  "code": "DPE_STATUT_VALIDE" }
      },
      "mentions_textes": {
        "dpe_en_cours": "DPE en cours de réalisation à la date d''édition du support — sera communiqué dès réception.",
        "dpe_non_soumis_R126_15": "Bien non soumis au DPE en application de l''article R.126-15 du code de la construction et de l''habitation."
      }
    },
    "honoraires": {
      "bloc_dur": [
        { "code": "HONO_MONTANT",          "regle": "honoraires_montant" },
        { "code": "HONO_CHARGE",           "regle": "honoraires_charge_definie" },
        { "code": "HONO_PRIX_HONO_INCLUS", "regle": "prix_avec_honoraires" },
        { "code": "HONO_PRIX_HORS_HONO",   "regle": "prix_hors_honoraires_si_charge_acq" },
        { "code": "HONO_TVA_NON_APPLICABLE","regle": "tva_mention_particulier" }
      ],
      "alertes": [
        { "code": "HONO_INCOHERENCE_PRIX", "regle": "honoraires_coherence_prix" },
        { "code": "HONO_BAREME_AGENCE",    "regle": "honoraires_bareme_accessible" }
      ]
    },
    "copropriete": {
      "bloc_dur_si_en_copro": [
        { "code": "COPRO_NB_LOTS",            "regle": "copro_nb_lots" },
        { "code": "COPRO_QUOTE_PART_CHARGES", "regle": "copro_quote_part_charges" },
        { "code": "COPRO_PROCEDURES_L611",    "regle": "copro_procedures_l611" }
      ],
      "alertes": [
        { "code": "COPRO_TRAVAUX_VOTES", "regle": "copro_travaux_votes_communiques" },
        { "code": "COPRO_FONDS_TRAVAUX", "regle": "copro_fonds_travaux_alur" }
      ]
    },
    "risques": {
      "bloc_dur_par_support": {
        "fiche_client":         [{ "code": "RISQUES_ERP_DISPONIBLE", "regle": "erp_disponible" }],
        "dossier_presentation": [{ "code": "RISQUES_ERP_DISPONIBLE", "regle": "erp_disponible" }]
      },
      "alertes": [
        { "code": "RISQUES_ZONE_SISMIQUE", "regle": "zone_sismique_3_plus" },
        { "code": "RISQUES_ZONAGE_ARGILE", "regle": "zone_aleas_argile_fort" },
        { "code": "RISQUES_INONDATION",    "regle": "zone_plan_inondation" }
      ]
    },
    "mandat_diffusion": {
      "bloc_dur": [
        { "code": "AUTORISATION_DIFFUSION_PERIMETRE", "regle": "autorisation_diffusion_couvre_canaux" }
      ]
    },
    "carte_pro": {
      "bloc_dur_supports_publics": [
        { "code": "CARTE_PRO_NUMERO",           "regle": "carte_pro_numero" },
        { "code": "CARTE_PRO_CCI",              "regle": "carte_pro_cci" },
        { "code": "CARTE_PRO_VALIDITE",         "regle": "carte_pro_validite" },
        { "code": "CARTE_PRO_GARANT_FINANCIER", "regle": "carte_pro_garant_financier" },
        { "code": "CARTE_PRO_RC_PRO",           "regle": "carte_pro_rc_pro" }
      ]
    }
  },
  "perimetre": {
    "concerne_vente": true,
    "concerne_location": false,
    "concerne_copro": true
  }
}',
   1, 0, 1,
   1, '2026-05-01',
   0,
   'Loi Hoguet n°70-9 / Loi ALUR n°2014-366 / Loi Climat n°2021-1104 / Décret 2020-1610 + arrêtés DPE 2021 / Code consommation / Code copropriété / Code environnement L125-5 / Arrêté honoraires 10 janvier 2017',
   'Brouillon fonctionnel — à valider juridiquement avant passage en production stricte.',
   NULL);

-- Garde-fou : meme si l'INSERT IGNORE ci-dessus a ete saute (v1 deja
-- presente), on force la v1 a actif=1 pour eviter de laisser la BDD
-- sans aucune version active (le UPDATE initial avait tout desactive).
UPDATE `mbi_supports_mentions_versions` SET `actif` = 1 WHERE `version` = '2026-05-01.v1';
SQL,
];
