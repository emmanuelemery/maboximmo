# Mémo — MaBoxImmo (fonctionnalités & pistes)

Date : 2026-04-15  
Périmètre : ce mémo décrit l’existant tel qu’il apparaît dans `public_html/` (pages, sidebars, API, scripts) + les zones “en cours” visibles dans le code.

## 1) Vue d’ensemble

MaBoxImmo est structuré en **modules (services)** accessibles selon le rôle et les modules activés côté société.

- Point d’entrée public : `accueil.php` (présentation + connexion + cartes “Syndic / RH / Agence / Propriétaire”).
- Point d’entrée “après connexion” : `dashboard.php` → redirection `agence_portail.php`.
- Routage par services (rôles → services) : `inc/roles_services.php`.
- Design system V2 (neumorphique) + méthode de restyling : `memoire/charte_graphique.md`, `memoire/methode_restyling.md`.

Services/modules visibles dans le code :
- `Agency` (agence immobilière / gestion immeubles, mandats, tâches, réunions, factures…)
- `Syndic` (copropriété / immeubles + AG)
- `RH` (salaires, congés, documents, entretiens, mails…)
- `Bailleur` (CRG, encaissements/impayés, baux, immeubles/biens, GED…)
- `Gestion` (SIR/CRG/assistant IA – partiellement câblé)
- `Net` (diffusion/portails/leads – actuellement démo)
- `Admin / Super admin` (admin dashboards + base/paramétrage)

## 2) Fonctionnalités actuelles (en place)

### A) Sécurité, comptes, multi-rôles
- Authentification + sessions : `inc/auth.php`, `inc/bootstrap.php`, `accueil.php`, `login.php`/`logout.php`.
- CSRF + garde-fous : `inc/csrf.php`, `inc/security.php`, `inc/SecurityGuard.php`, `inc/RateLimiter.php`.
- Accès par service et par société (modules `module_rh`, `module_agency`, `module_syndic`, `module_gestion`) : `inc/roles_services.php`.
- Portail agence + thème + demande d’accès “Registre (payant)” : `agence_portail.php`, `admin_registres_access.php`, `sso_registres.php`.

### B) Module Agency (Ma Box Agency)
Navigation principale V2 : `sidebar_agency.php`.

Fonctions métier visibles :
- Dashboard + KPIs + alertes “fiches incomplètes” + prochaines AG : `agency_dashboard.php`.
- Immeubles : liste / fiche / formulaire : `agency_immeubles.php`, `agency_immeuble_fiche.php`, `agency_immeuble_form.php`.
- Mandants : liste / fiche / formulaire : `agency_mandants.php`, `agency_mandant_fiche.php`, `agency_mandant_form.php`.
- Mandats : `agency_mandats.php`.
- Réunions / AG : liste / création / tenue / détail : `agency_reunions.php`, `agency_reunion_form.php`, `agency_reunion_tenir.php`, `agency_reunion_detail.php`.
- Tâches : liste + détail : `agency_taches.php`, `agency_tache_detail.php`.
- Contrats / proposition syndic + tarifs : `agency_syndic_contrats.php`, `agency_syndic_contrat_form.php`, `agency_syndic_propositions.php`, `agency_syndic_proposition_form.php`, `agency_syndic_tarifs.php`.
- Facturation / honoraires : `agency_factures.php`, `agency_facture_form.php`, `agency_honoraires_config.php`.
- Registres : liste / fiche / formulaire + PDF : `agency_registres.php`, `agency_registre_fiche.php`, `agency_registre_form.php`, `agency_pdf_registre.php`.
- Génération PDF (convocation, CR, facture, contrat syndic, proposition) : `agency_pdf_convocation.php`, `agency_pdf_cr.php`, `agency_pdf_facture.php`, `agency_pdf_contrat_syndic.php`, `agency_pdf_syndic_proposition.php`.
- Analyse de documents (usage agence) : `agency_analyse_doc.php`.

Gestion “biens / annonces” (hors préfixe agency) :
- Biens : `bien_liste.php`, `bien_ajouter.php`, `bien_supprimer.php`.
- Intake/import : `bien_intake.php` + endpoints `api/bien_intake_*`, `api/bien_import_*`.

Diffusion / flux :
- Dashboard diffusion : `agency_dashboard_diffusion.php`.
- Export/validation flux (Ubiflow, Poliris, etc.) : `api/flux/…` + `inc/ubiflow_validator.php`.

### C) Module Syndic
Navigation V2 : `sidebar_syndic.php`.

- Dashboard “Syndic V2” (KPIs, AG à venir, alertes fiches, récents) : `syndic_dashboard.php`.
- Immeubles syndic : liste / fiche / formulaire : `syndic_immeubles.php`, `syndic_immeuble_fiche.php`, `syndic_immeuble_form.php`.

### D) Module Bailleur / Propriétaire (gestion patrimoniale + CRG)
- Dashboard bailleur (multi-propriétaires, filtres année/trimestre/immeuble/lot, impayés, marquage “locataire parti”, regroupement dépenses, accès PDF viewer) : `bailleur_dashboard.php`.
- GED bailleur : `bailleur_ged.php`.
- Immeubles bailleur : `bailleur_immeubles.php`.
- Révision de loyer : `bailleur_revision_loyer.php`.
- Organigramme SCI : `bailleur_sci_organigramme.php`.
- Audit CRG : `bailleur_crg_audit.php`.
- Portail propriétaire (KPIs + dernier CRG + historique) : `dashboard_proprietaire.php`.
- Vision PDF page ciblée : `api/pdf_viewer.php` (utilisé par bailleur).

### E) Module Gestion (SIR / analyses / IA)
Navigation : `gestion/inc/nav_gestion.php`.

Pages présentes :
- Tableau SIR : `gestion/dashboard_sir.php`.
- Patrimoine SIR : `gestion/patrimoine_sir.php`.
- Analyses SIR : `gestion/analyses_sir.php`.
- Assistant IA : `gestion/assistant_ia.php`.
- Import CRG : `gestion/upload_crg.php` + `scripts/import_all_crg.php`, `scripts/parse_crg.py`.

### F) Module RH (Ressources Humaines)
Navigation V2 : `sidebar_rh.php`.

Fonctions visibles (pages principales) :
- Dashboard RH : `rh_dashboard.php` (+ variantes `rh_dashboard_user.php`, `rh_dashboard_manager.php`, `rh_dashboard_admin.php`).
- Salaires (liste + détail + user) : `rh_salaires.php`, `rh_salaire_detail.php`, `rh_salaire_user.php`, `rh_salaires_user.php`, `rh_salaires_user_list.php`, `rh_salaires_historiq.php`, `rh_salaires_detail.php`.
- Congés : `rh_conges.php`, `rh_conges_edit.php`, `rh_conges_validation.php`, `rh_conges_historiq.php`.
- Indemnités KM / frais : `rh_indemnite_km.php`, `rh_ik_user.php`, `rh_frais_user.php`.
- Documents RH + extraction : `rh_documents.php`, `rh_documents_config.php`, `inc/rh_document_extractor.php`, endpoints `api/*user_doc*`.
- Mails / modèles : `rh_mails.php`, `rh_mail_templates.php`, `rh_modeles.php` + endpoints `api/get_mail_templates.php`, `api/delete_mail_template.php`, etc.
- Entretiens annuels (workflow + auto-éval + signature + PDF + jobs runner) : `rh_entretien_*` (notamment `rh_entretien_tenir.php`, `rh_entretien_signature.php`, `rh_entretien_generer_pdf.php`, `rh_entretien_jobs_runner.php`).
- Automatisme rappel congés : `scripts/rh_conges_rappel.php`.

### G) IA & automatisations “assistées”
IA côté API (chat + génération) :
- Assistant IA général + données contextualisées (proprio / SIR / agence) : `api/ask_ia.php`.
- Génération texte (annonce/bien) : `api/bien_ai_generate.php`, `api/chatgpt_draft.php`, `api/generate_annonce.php`.
- Analyse documents (admin/agence/RIB/bail/DPE) : `api/admin_doc_analyze.php`, `api/agence_rib_analyze.php`, `api/bail_analyze.php`, `inc/dpe_ia_analyse.php`, `api/dpe_import_upload.php`.

## 3) Ce qui est “en développement / perfectionnement” (visible dans le code)

- Restyling progressif en V2 : documentation `memoire/*` + sauvegardes dans `_archive/` (ex: `rh_*` et `bien_*` backup).
- Module Net : `net_dashboard.php` est explicitement “standalone / données de démo” et pointe vers des pages non présentes (`net_annonces.php`, `net_portails.php`, `net_leads.php`, `net_reporting.php`) → à construire.
- Module Gestion : `gestion/inc/nav_gestion.php` référence des pages non présentes (`gestion/dashboard_crg.php`, `gestion/config_ia.php`) → à finaliser.
- Module Syndic : coexistence `dashboard_syndic.php` (placeholder “en construction”) et `syndic_dashboard.php` (V2 fonctionnel) + mapping service vers `dashboard_syndic.php` dans `inc/roles_services.php` → harmonisation à faire.
- RH onboarding / “pages provisoires” : fichiers `*_prov.php` et logique de sortie dans `rh_dashboard_user.php` (onboarding/validation) → stabilisation + unification des parcours.
- RH “entretien” en itérations : versions `inc/rh_entretien_v3.php` … `inc/rh_entretien_v7.php` → consolidation.
- Navigation legacy vs V2 : présence d’anciennes sidebars/menus (`inc/sidebar.php`, `inc/rh_sidebar.php`, `sidebar_rh_archive.php`) → nettoyage/alignement quand migration terminée.

## 4) Idées à développer ensuite (pour gagner du temps au quotidien)

### A) “Moins de clics”, plus d’automatisme
- Barre de recherche globale (immeuble/lot/locataire/mandat/doc) + raccourcis clavier.
- “Centre d’actions” (1 écran) : congés à valider, fiches incomplètes, AG à J-30, impayés > X€, tâches en retard, docs manquants.
- Notifications intelligentes (in-app + email) : rappels d’échéances + escalade si non traité.

### B) Documents & GED (gros levier)
- GED unifiée : tags, versioning, droits, modèle de nommage automatique, liens entre doc ↔ immeuble ↔ lot ↔ locataire ↔ mandat.
- OCR + extraction systématique (RIB, bail, DPE, quittance) avec contrôles qualité (drapeaux “à vérifier”).
- Génération “1 clic” : convocation + ordre du jour + feuille de présence + CR + envois + archive.

### C) Diffusion & acquisition (Agency / Net)
- Supervision diffusion : statut flux par portail, erreurs, relances automatiques, historique des changements.
- Pipeline leads : qualification, attribution à un négociateur, relance sous 24h, statistiques conversion.

### D) Finance & pilotage
- Tableaux de bord “impayés” : par immeuble / lot / âge de la dette / actions (relance, mise en demeure, contentieux).
- Rapprochements : encaissements vs quittances vs CRG, détection anomalies, export compta.

### E) IA “utile” (encadrée)
- IA “assistante de rédaction” : annonces, mails, comptes rendus, synthèses CRG, sans inventer (toujours basé sur données fournies).
- IA “checklist” : contrôle qualité d’une annonce (photos, DPE, cohérence prix/surface/charges), contrôle d’un bail (clauses manquantes), contrôle convocation AG.

## 5) Prochaines étapes (si on veut prioriser)

1) Harmoniser les points d’entrée (Syndic / Gestion / Net) et supprimer les liens morts.  
2) Continuer la migration UI V2 (pages à forte fréquence d’usage : `bien_ajouter.php`, `bien_liste.php`, dashboards).  
3) Industrialiser “documents + workflow” (c’est le ROI le plus rapide au quotidien).

## 6) Priorites (selon usage)

Ordre confirme : (1) Biens / diffusion portail `paboximmo`, (2) RH, (3) Agency.

### Top 10 (ROI / effort)

| # | Chantier | Pourquoi (ROI) | Effort | Points d’appui existants |
|---|---|---|---|---|
| 1 | Câbler la publication sur `paboximmo` | Publication fiable, 1 clic depuis un bien | M | UI déjà là : `bien_ajouter.php` (`diffusion_maboximmo`), statuts existants : `annonces.visible_portails` |
| 2 | Statuts “annonce” unifiés (brouillon → prête → publiée portail → diffusée flux) | Supprime les ambiguïtés, évite les annonces “à moitié” | S/M | `annonce_nouvelle.php` + `annonces.statut` + `annonces.visible_portails` |
| 3 | Complétude diffusion basée sur le validator (et non des heuristiques) | Zéro surprise au moment de publier | S | `inc/ubiflow_validator.php` (règles + score + manquants) |
| 4 | “File de publication” (liste des annonces prêtes / à corriger) | Pilotage quotidien (qui publie quoi / quand) | M | `bien_liste.php` a déjà une vue + statut “En diffusion” |
| 5 | Photos : cover + ordre + contrôle qualité (min, format, poids) | La qualité photo fait le taux de contact | S/M | Sélection/SEO WebP déjà prévu dans `bien_ajouter.php` + `inc/annonce_photos_manager.php` |
| 6 | Export “portails” industrialisé (cron + logs + alertes) | Diffusion externe stable (Ubiflow) | S | `api/flux/ubiflow.php`, `api/flux/ubiflow_ftp.php`, `scripts/ubiflow_cron_19h.bat` |
| 7 | RH : onboarding collaborateur (documents + profil + congés) simplifié | Moins de support interne, moins d’oublis | M | Logique “sortie pages provisoires” dans `rh_dashboard_user.php` |
| 8 | RH : documents (GED RH) + extraction + renommage standard | Gain massif sur classement/recherche | M | `rh_documents.php`, `inc/rh_document_extractor.php`, endpoints `api/*user_doc*` |
| 9 | RH : congés (validation + rappels + exports) | Fluidifie le quotidien manager/collab | S/M | `rh_conges.php`, `rh_conges_validation.php`, `scripts/rh_conges_rappel.php` |
| 10 | Agency : “centre d’actions” (AG J-30, fiches incomplètes, tâches) | Pilotage agence au jour le jour | M | `agency_dashboard.php` + tâches/réunions déjà structurées |

### Precisions utiles avant dev (2 questions)
- `paboximmo` : c’est (A) un site public dans ce même projet (pages à créer), ou (B) un autre site qui consomme un flux (JSON/XML) ?
- Pour `paboximmo`, on publie une annonce si : `diffusion_maboximmo=1` uniquement, ou bien on réutilise `visible_portails=1` ?
