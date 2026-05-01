# Agent GED MaBoxImmo — Module v1

Module d'analyse IA semi-automatique des documents :

```
Document reçu  →  OCR  →  Analyse IA  →  Suggestion classement / nom / action
              →  Validation humaine  →  Classement GED propre
```

## 📦 Fichiers livrés

### SQL (`/sql/agent_ged/`)
- `001_create_agent_ged_analyses.sql` — table `agent_ged_analyses` (CREATE TABLE IF NOT EXISTS, idempotente)

### Backend PHP (`/modules/`)
- `modules/ged/agent_functions.php` — fonctions métier :
  - `buildGedPrompt()` : construit le prompt IA avec taxonomie 7 modules + 25 règles métier
  - `analyzeGedDocument()` : orchestre OpenAI + persistance dans `agent_ged_analyses`
  - `runPremiumOcr()` : placeholder Mindee/Vision (à brancher quand prêt)
  - `validateGedAnalysis()` / `rejectGedAnalysis()` : validation humaine
  - `listAgentQueue()` : liste filtrée pour la page (multi-tenant via `id_societe`)
  - `agentQueueStats()` / `agentStatsByModule()` : stats pour cards

- `modules/agent/agent_ged.php` — page UI principale :
  - 5 cards stats (total / à valider / validés / rejetés / revue manuelle)
  - Filtres (statut, module, recherche)
  - Tableau queue avec module/niveau/immeuble/action/confiance/statut + boutons d'action
  - Layout MaBoxImmo (header/footer auto-inclus si dispo)

- `modules/agent/agent_ged_action.php` — handler AJAX :
  - POST avec `action` ∈ {validate, reject, reanalyze, ocr_free, ocr_premium}
  - Réponse JSON normalisée `{ok, message, ...}`
  - Sécurité : `require_login()`, prepared statements, no debug output en prod

### Frontend (`/assets/`)
- `assets/css/agent_ged.css` — styles dédiés (cards, badges, table, boutons, toast, responsive)
- `assets/js/agent_ged.js` — logique boutons : `agedAction()`, `agedView()`, `agedToast()`

## 🚀 Installation

### 1. Appliquer la migration SQL

```sql
-- En local (XAMPP) : ouvre phpMyAdmin → DB MaBoxImmo → SQL → colle le contenu de :
sql/agent_ged/001_create_agent_ged_analyses.sql

-- Ou en CLI :
mysql -u root maboximmo < sql/agent_ged/001_create_agent_ged_analyses.sql
```

### 2. Vérifier la config OpenAI

Le fichier `u630423897/maboximmo_openai_config.php` (ou `dev_maboximmo_openai_config.php`) doit définir :

```php
define('OPENAI_API_KEY', 'sk-proj-...');
```

### 3. Accéder à la page

URL : `/modules/agent/agent_ged.php`

Tu peux ajouter un lien dans la sidebar admin (à voir dans `inc/agency_layout_top.php`).

## 🔄 Flow utilisateur

1. Un document est uploadé (système existant : `admin_documents.php` ou autre)
2. L'OCR est appliqué (Tesseract local actuel)
3. **L'utilisateur lance l'analyse IA** depuis la fiche du doc (à câbler avec un bouton "Analyser via Agent GED")
4. L'analyse crée une ligne dans `agent_ged_analyses` (status=`to_validate`)
5. L'utilisateur ouvre `/modules/agent/agent_ged.php`, voit la queue, valide/rejette/relance
6. Une fois validé, le document peut être classé définitivement (à brancher selon ton workflow GED actuel)

## 🔌 Branchements futurs

- **OCR premium** : dans `runPremiumOcr()`, brancher Mindee API v2 (`api-v2.mindee.net/v2/products/ocr/enqueue`) ou Google Cloud Vision
- **Routage doc → fichier** : dans `agent_ged.js::agedView()`, adapter selon les pages MaBoxImmo réelles
- **Création tâche** : ajouter un bouton "Créer tâche" qui appelle un endpoint qui insère dans `mbi_tasks` (à créer)
- **Renommage fichier** : sur `validate`, prendre `suggested_filename` et déplacer le fichier physique vers son arborescence finale

## 🔒 Sécurité

- Aucune clé API en dur dans le code
- Toutes les requêtes SQL en prepared statements
- Filtrage multi-tenant : `id_societe` / `id_agence` côté SQL pour les non-admins (rôles 1, 7, 8 voient tout)
- `require_login()` sur les 2 endpoints (page + handler)
- Réponse JSON `Cache-Control: no-store` pour éviter les caches navigateur

## 📊 Coût IA estimé

- gpt-4o-mini : ~0.05¢ par analyse complète (600 tokens entrée + 400 sortie)
- Pour 1000 docs/mois → ~50 centimes
- Mindee OCR premium : 250 pages gratuites/mois pendant essai 14 jours

## 🎯 Évolutions

Voir la roadmap V2→V10 dans la conversation principale (surlignage PDF, chat IA, apprentissage continu, workflow approval, intégrations métier, cockpit, mobile, multi-modèles, RGPD).
