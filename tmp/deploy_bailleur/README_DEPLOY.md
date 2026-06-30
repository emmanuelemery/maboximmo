# Déploiement Module Bailleur MBI
**Date :** 30/05/2026  
**Version :** Phase 1 complète

## ÉTAPES DE DÉPLOIEMENT

### 1. SQL — Exécuter migrations.sql sur Hostinger
→ Via hPanel > phpMyAdmin > base `u630423897_maboximmo_dev`
→ Importer le fichier `migrations.sql`

### 2. FTP — Copier les fichiers PHP
→ Tous les fichiers du dossier `public_html/` de ce package
→ vers `public_html/` sur Hostinger (écraser si existant)

**ATTENTION** : Ne PAS écraser `inc/sidebar_agency.php` si vous avez des modifications locales — vérifier manuellement les 2 lignes ajoutées (Ma Box Bailleur dans Navigation)

### 3. Vérifier après déploiement
- [ ] `https://votre-domaine.fr/bailleur_dashboard.php` → dashboard bailleur
- [ ] `https://votre-domaine.fr/bailleur_patrimoine_actif.php` → patrimoine
- [ ] `https://votre-domaine.fr/bailleur_admin_comptes.php` → gestion comptes
- [ ] Sidebar MBI : lien "🏦 Ma Box Bailleur" visible

## FICHIERS INCLUS

| Fichier | Type | Description |
|---------|------|-------------|
| `bailleur_dashboard.php` | Nouveau | Dashboard bailleur avec KPIs et filtres |
| `bailleur_patrimoine_actif.php` | Nouveau | État du patrimoine actif |
| `bailleur_admin_comptes.php` | Nouveau | Gestion comptes bailleurs + modules |
| `toggle_immeuble_vendu.php` | Nouveau | API archivage immeuble |
| `toggle_locataire_archive.php` | Nouveau | API archivage locataire |
| `get_locataire_history.php` | Nouveau | API historique CRG locataire |
| `bailleur_immeubles.php` | Modifié | Filtrage bailleur + session |
| `inc/sidebar_bailleur_module.php` | Nouveau | Sidebar dédiée module bailleur |
| `inc/sidebar_agency.php` | Modifié | +lien Ma Box Bailleur |
| `inc/sidebar_fluxbox.php` | Modifié | +lien Ma Box Bailleur |
| `inc/sidebar_net.php` | Modifié | +lien Ma Box Bailleur |
| `inc/sidebar_ged.php` | Modifié | +lien Ma Box Bailleur |

## AJOUT : Données CRG manquantes sur Hostinger

Le fichier `data_crg.sql` contient les données à importer :

| Table | Lignes |
|-------|--------|
| crg_trimestres | 91 |
| crg_situations_locataires | 1 844 |
| locataires_statuts | 331 |
| immeubles | 609 |
| biens | 703 |

### Ordre d'import sur Hostinger phpMyAdmin :
1. `migrations.sql` → créer les tables manquantes
2. `data_crg.sql` → importer les données (INSERT IGNORE = pas de doublon)

### ATTENTION : data_crg.sql fait 1.1 Mo
Si phpMyAdmin refuse (limite upload), utiliser :
- hPanel → Terminal → `mysql -u user -p db < data_crg.sql`
- Ou augmenter `upload_max_filesize` dans php.ini Hostinger
