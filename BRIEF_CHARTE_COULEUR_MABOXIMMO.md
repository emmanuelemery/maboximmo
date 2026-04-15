# 🎨 BRIEF CHARTE COULEUR - MABOXIMMO

## 📋 Contexte
MABOXIMMO est un portail immobilier moderne conçu pour agences, bailleurs et syndics. La plateforme offre plusieurs modules (Ma Box Immo, Ma Box Agency, Ma Box Syndic, Ma Box Pro, Ma Box Community) avec une interface claire et minimaliste.

---

## 🎯 PALETTE COULEUR: "Minimaliste Naturel"

### Couleurs Primaires

| Couleur | Hex | Usage | Feeling |
|---------|-----|-------|---------|
| **Vert Kaki** | #6b8e6f | Boutons primaires, highlights, accents | Nature, croissance, calme |
| **Bleu Pétrole Foncé** | #2d5f6b | Headers, titres, accents forts | Confiance, stabilité, professionnel |
| **Blanc Naturel** | #fffbf8 | Texte sur dark, fond principal | Douceur, accessibilité |
| **Beige Naturel** | #f4e8d8 | Sections alternées, détails | Chaleur, minimalisme |
| **Beige Clair** | #faf6f0 | Fonds alternatifs, filtres | Légèreté, séparation |

### Couleurs Neutres

| Couleur | Hex | Usage |
|---------|-----|-------|
| **Gris Foncé** | #6b6b6b | Texte principal |
| **Gris Moyen** | #757575 | Texte secondaire |
| **Gris Clair** | #b0b0b0 | Textes très légers |
| **Gris Ligne** | #e5e5e5 | Borders, séparations |

---

## 🖼️ APPLICATIONS PAR SECTION

### Navigation & Header
- **Fond:** Blanc naturel (#fffbf8) avec backdrop blur léger
- **Logo & texte:** Bleu pétrole (#2d5f6b)
- **Boutons CTA:** Vert kaki (#6b8e6f) avec hover #5a7d5e
- **Border bottom:** Gris ligne (#e5e5e5)
- **État hover:** Fond beige léger (#faf6f0)

### Hero Section
- **Fond:** Blanc naturel (#fffbf8)
- **Titre:** Bleu pétrole (#2d5f6b), 40px, 800 weight
- **Sous-titre:** Gris moyen (#757575)
- **Boîte annonces (header):** Vert kaki (#6b8e6f)
- **Boîte annonces (filtre):** Beige clair (#faf6f0)
- **Boutons pitch:** Vert kaki (#6b8e6f)

### Cards (Annonces)
- **Fond card:** Blanc pur (#ffffff)
- **Border:** Gris ligne (#e5e5e5) - 1px normal, 2px featured
- **Card Featured:**
  - Border color: Vert kaki (#6b8e6f)
  - Shadow: rgba(107, 142, 111, 0.16)
  - Taille: 1.8x plus grande que les autres
- **Icon container:** Blanc avec opacity 95%, shadow légère
- **Texte card:** Gris foncé (#6b6b6b)

### Sections Alternées
- **Fond pair:** Beige clair (#faf6f0)
- **Fond impair:** Blanc (#ffffff)
- **Titre section:** Bleu pétrole (#2d5f6b), 26-38px, 800 weight
- **Label/eyebrow:** Bleu pétrole (#2d5f6b), 10-11px, 700 weight, uppercase

### Boutons
- **Primaire:** Vert kaki (#6b8e6f)
- **Primaire hover:** #5a7d5e
- **Secondaire:** Bleu pétrole (#2d5f6b)
- **Text:** Blanc naturel (#fffbf8)
- **Border radius:** 14px (standard), 980px (pill buttons)

### Cards/Boxes
- **Fond:** Blanc (#ffffff)
- **Border:** Gris ligne (#e5e5e5)
- **Border radius:** 14px
- **Shadow normal:** 0 4px 24px rgba(0,0,0,0.07)
- **Shadow hover:** 0 12px 32px rgba(0,0,0,0.09)

### Footer
- **Fond:** Bleu pétrole (#2d5f6b)
- **Texte:** Blanc naturel (#fffbf8)
- **Links:** Blanc avec opacity 40%, hover 100%

---

## 📐 GRADIENT & EFFECTS

### Gradients Interdits
❌ Ne PAS utiliser de gradients colorés (bleu→vert)
❌ Éviter les gradients agressifs ou trop vifs
✅ Gradients subtils: variantes du même couleur OK (ex: #6b8e6f → #8aae8f)

### Effects Autorisés
- Backdrop blur: blur(20px) sur nav
- Shadows subtiles: utiliser var(--shadow-md), var(--shadow-lg)
- Opacités: rgba(255,255,255,.22) pour overlay, rgba(0,0,0,.07) pour shadows
- Transitions: .2s ease pour hovers

---

## 🎨 DIRECTIVES DE DESIGN

### Hiérarchie Visuelle
1. **Bleu Pétrole** = information principale, titres, accents forts
2. **Vert Kaki** = actions, appels à l'action, highlights
3. **Beige** = séparation, contexte, sections
4. **Gris** = texte secondaire, informations légères

### Contraste
- Minimum WCAG AA (4.5:1) pour texte/fond
- Blanc naturel sur bleu pétrole ✅ Bon contraste
- Blanc naturel sur vert kaki ✅ Bon contraste
- Gris moyen sur blanc ✅ Acceptable

### Minimalisme
- Moins de couleurs = plus d'impact
- Espaces blancs généreux
- Pas de motifs complexes
- Icons épurés (1 couleur, simple)
- Ligne fine, subtle borders

---

## 🔄 ÉTAT DES ÉLÉMENTS

### Buttons
- **Default:** Vert kaki (#6b8e6f)
- **Hover:** #5a7d5e (plus foncé)
- **Active:** Bleu pétrole (#2d5f6b)
- **Disabled:** Gris clair (#b0b0b0)

### Links
- **Default:** Bleu pétrole (#2d5f6b)
- **Hover:** Vert kaki (#6b8e6f)
- **Visited:** Gris moyen (#757575)

### Cards
- **Default:** Blanc avec border gris
- **Hover:** Translatey(-4px), shadow augmentée
- **Featured:** Border + color vert kaki, shadow vert kaki

---

## 📱 RESPONSIVE & Adaptations

### Desktop (1200px+)
- Padding généreux: 64px
- Espaces full
- Sidebars visibles pour admin

### Tablet (768-1199px)
- Padding réduit: 32px
- Cards en 2 colonnes
- Hamburger menu

### Mobile (<768px)
- Padding minimal: 24px
- Cards en 1 colonne
- Beige clair pour sections (meilleure lisibilité)
- Texte réduit mais lisible

---

## ✨ AMÉLIORATIONS GRAPHIQUES À APPLIQUER

Utilisant UNIQUEMENT cette palette, améliore:

1. **Profondeur visuelle**
   - Ajouter des subtle shadows sur les cards
   - Layering avec beige naturel pour contexte
   - Blanc pur (#ffffff) pour éléments foreground

2. **Micro-interactions**
   - Hover states subtils mais clairs
   - Transitions douces (.2s)
   - Scale/translateY pour feedback

3. **Typographie**
   - Bleu pétrole pour hiérarchie (h1, h2, accents)
   - Gris pour lecture longue
   - Font-weight 800 pour titres, 600 pour accents, 400 pour corps

4. **Séparations visuelles**
   - Utiliser beige naturel plutôt que gris ligne
   - Spacing généreux (16px, 24px, 32px)
   - Pas de trop de borders (1px max)

5. **Elements d'intérêt**
   - Vert kaki pour éléments à mettre en avant
   - Featured state avec border + shadow verts
   - Icons avec background blanc léger

---

## 🚫 À ÉVITER

- ❌ Dégradés multicolores
- ❌ Couleurs saturation élevée
- ❌ Trop de shadows/effets
- ❌ Éléments clignotants ou animés rapides
- ❌ Police sans serif mixée avec serif
- ❌ Couleurs en dehors de la palette
- ❌ Contraste insuffisant (<4.5:1)

---

## ✅ OBJECTIF FINAL

Une interface **minimaliste, naturelle et professionnelle** qui:
- Inspire confiance (bleu pétrole)
- Incite à l'action (vert kaki)
- Repose les yeux (blanc naturel, beige)
- Est accessible (contraste adéquat)
- Fonctionne sur tous les appareils
- Valorise le contenu immobilier (photos, annonces)

---

## 📝 COMMENT UTILISER CE BRIEF

Vous pouvez utiliser ce brief avec n'importe quel outil IA (Claude, Midjourney, Figma AI) en formulant:

**Prompt suggéré:**

> Je travaille sur un portail immobilier appelé MABOXIMMO. Voici ma charte couleur et mes directives de design. En utilisant UNIQUEMENT cette palette couleur (#6b8e6f, #2d5f6b, #fffbf8, #f4e8d8, #faf6f0), peux-tu:
>
> 1. Améliorer le graphisme de [SECTION]
> 2. Ajouter plus de profondeur visuelle avec shadows et spacing
> 3. Assurer une hiérarchie visuelle claire
> 4. Maintenir le minimalisme naturel
> 5. Respecter les directives d'accessibilité
>
> [INSÉRER LE CONTENU DU BRIEF]

---

**Créé pour:** MABOXIMMO
**Palette:** Minimaliste Naturel
**Date:** 2026-03-24
**Mainteneur:** Équipe Design MABOXIMMO
