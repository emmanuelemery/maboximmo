# Skill: /audit-projet

Un audit complet en deux phases : comprendre le projet humainement, puis analyser le code techniquement.

---

## Phase 1 — Découverte produit (6 questions)

Pose ces 6 questions **une par une**, attends la réponse avant de passer à la suivante. Ne saute pas de question. Sois direct, pas complaisant.

**Question 1 — Qui paie / qui utilise**
> Qui utilise ce projet aujourd'hui — et pourquoi ils continueraient à l'utiliser si tu arrêtais de le maintenir pendant 3 mois ?
> (Si personne ne paie encore : qui a dit explicitement "je veux ça" et qu'est-ce qu'ils font aujourd'hui à la place ?)

**Question 2 — Douleur réelle**
> Qu'est-ce qui se passe concrètement si un utilisateur n'utilise pas le projet cette semaine ? Il fait comment à la place ?

**Question 3 — Traction**
> Combien d'utilisateurs actifs en ce moment — et c'est toi qui les relances ou ils reviennent d'eux-mêmes ?

**Question 4 — Scalabilité humaine**
> Qui produit la valeur chaque semaine ? Est-ce que ce volume peut tenir si le projet grandit x10 ?

**Question 5 — Risques**
> Quel est le vrai risque que tu vois dans les 6 prochains mois — technique, commercial, ou personnel ?

**Question 6 — Viralité**
> Qu'est-ce qui ferait qu'un utilisateur montre le projet à son supérieur ou à un collègue en disant "regarde ce qu'on a" ?

Après la 6ème réponse, synthétise en un tableau :
| Qui | Quoi | Vs concurrence | Goulot | Risque | Levier viral |

---

## Phase 2 — Revue de code

Lance un sous-agent Explore sur le répertoire racine du projet avec les instructions suivantes :

```
Fais une revue de code approfondie du projet à [CHEMIN_DU_PROJET].

Lis les fichiers clés : point d'entrée principal, configuration, authentification, base de données, sécurité, templates, modules/controllers.

Évalue et rapporte sur :

1. SÉCURITÉ
   - Injection SQL (prepared statements ?)
   - XSS (htmlspecialchars sur les outputs ?)
   - CSRF (tokens présents sur tous les formulaires POST ?)
   - Authentification et gestion des sessions
   - Autorisation (les contrôles d'accès sont-ils au niveau du routeur, pas seulement de l'UI ?)
   - Upload de fichiers (validation MIME, taille, CSRF)
   - Exposition de secrets dans les erreurs

2. ARCHITECTURE
   - Séparation des responsabilités (routing / business logic / templates)
   - Cohérence de la structure des fichiers
   - Points d'entrée uniques vs logique dispersée

3. SCALABILITÉ — qu'est-ce qui casse à x10 utilisateurs ?
   - Stockage des sessions
   - Appels externes synchrones (API, emails)
   - Index manquants en base de données
   - Opérations qui bloquent le thread principal

4. POINTS DE DÉFAILLANCE UNIQUES
   - Clés API partagées
   - Traitements manuels non automatisables
   - Dépendances externes sans fallback

5. QUALITÉ DU CODE
   - Gestion des erreurs (erreurs internes exposées aux utilisateurs ?)
   - Code mort ou redondant
   - Cohérence des conventions

6. BASE DE DONNÉES
   - Qualité du schéma (foreign keys, contraintes, index)
   - Champs redondants ou contradictoires
   - Absence de soft deletes ou d'audit trail

Sois précis : cite les fichiers et numéros de ligne. Sois honnête sur ce qui est solide et ce qui est fragile.
```

---

## Livrable final

Présente les résultats en 4 sections :

### ✅ Ce qui est solide
Liste les bonnes pratiques trouvées.

### ⚠️ Ce qui est fragile
Tableau : Problème | Fichier | Impact | Sévérité (🔴/🟠/🟡)

### 🔥 Ce qui cassera à x10
Liste les goulots d'étranglement techniques.

### Priorités concrètes
- **Cette semaine** : corrections rapides, sans refacto
- **Dans 2 semaines** : améliorations de fond
- **Quand tu voudras scaler** : changements d'architecture

---

## Notes d'utilisation

- Adapter la Phase 1 si le projet est purement technique (remplacer "qui paie" par "qui dépend de ce système")
- La Phase 2 fonctionne sur n'importe quel langage — les questions sont agnostiques
- Ne pas proposer de migration vers un autre stack sauf si c'est explicitement demandé
- Citer les lignes de code précises dans la revue, pas de généralités
