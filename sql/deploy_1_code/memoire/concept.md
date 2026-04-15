# MaBoxImmo — Concept & Objectifs

## Vision du projet

MaBoxImmo est une plateforme destinée **aux particuliers et aux professionnels de l'immobilier**.
Elle doit être utile, accessible, et efficace pour les deux publics.

---

## Principes fondateurs

### Utilité avant tout
- Chaque page doit apporter une valeur immédiate à l'utilisateur
- Pas de contenu décoratif sans fonction
- L'information utile doit être visible **au premier regard**, sans avoir à chercher

### Navigation intelligente
- **Minimum de clics** pour accéder à une action
- Les sous-menus doivent être logiques et prévisibles
- Le fil d'Ariane (breadcrumb) dans la topbar permet de savoir où on est à tout moment
- Les boutons d'action principaux sont toujours accessibles sans scroll

### Lisibilité
- Affichage **non surchargé** : chaque zone respire
- Hiérarchie visuelle claire : titre > sous-titre > contenu > actions
- Les données chiffrées s'affichent en police monospace (DM Mono) pour la lisibilité
- Les textes courants en Sora (lisible, moderne, neutre)

### Cohérence entre toutes les pages
- Une seule charte graphique : le Design System V2 neumorphique
- Même sidebar sur toutes les pages d'un module
- Même topbar avec breadcrumb, horloge, avatar
- Mêmes composants (boutons, cartes, badges, formulaires)

### Comportement des boutons d'action
- **Tooltip au survol obligatoire** sur tous les boutons icône (via `title` + CSS `::after`)
- Le tooltip apparaît instantanément (pas de délai natif)
- Les boutons d'action de tableau : petits, neumorphiques, colorés par type (bleu = voir, vert = ok, rouge = danger)
