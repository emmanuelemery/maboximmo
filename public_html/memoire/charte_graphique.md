# Charte Graphique V2 — MaBoxImmo

## Design System : Neumorphisme clair

Fond sable clair, ombres douces, pas de bordures dures. Tout est en relief ou en creux.

---

## Couleurs de base

| Variable | Valeur | Usage |
|---|---|---|
| Fond principal | `#f0ede8` | body, page |
| Fond composants | `#e8e4de` | cartes, boutons, inputs |
| Ombre foncée | `#c4c0ba` | ombre bas-droite neumorphique |
| Ombre claire | `#ffffff` | ombre haut-gauche neumorphique |
| Texte principal | `#1a1816` | titres, contenu |
| Texte secondaire | `#6a6660` | labels, descriptions |
| Texte muet | `#a8a49e` | placeholders, meta |
| Vert accent | `#4a6038` / `#7a9060` | titres section, accents RH |
| Bleu brand | `#36577d` | boutons primaires, valeurs |
| Rouge danger | `#cc5c58` | suppression, erreur |
| Or admin | `#7a6030` | zones admin uniquement |

---

## Typographie

- **Sora** : texte courant, labels, boutons
- **DM Mono** : valeurs chiffrées, codes, breadcrumb, labels uppercase

---

## Ombres neumorphiques

```css
/* Relief (bouton, carte) */
box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff;

/* Creux (input, select actif) */
box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 6px #ffffff;

/* Carte grande */
box-shadow: 8px 8px 18px #c4c0ba, -8px -8px 18px #ffffff;

/* Bouton pressé */
box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 7px #ffffff;
```

---

## Composants principaux

### Layout
```
body > .shell > sidebar_rh.php + .sb-content > .topbar + .main
```
- Sidebar fixe : 272px
- `.sb-content` : `margin-left: 272px`
- `.topbar` : 56px de haut, sticky
- `.main` : scroll vertical, padding `0 24px 24px`

### Topbar
- Boutons back/forward neumorphiques
- Breadcrumb en DM Mono : `RH › Module › Page active`
- Spacer flexible
- Horloge DM Mono
- Cloche notifications
- Avatar initiales

### CSS stack à inclure sur chaque page
```html
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/tokens.css">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/layout.css">
<link rel="stylesheet" href="css/theme-rh.css">
```

### Boutons `.v2-btn`
```css
padding: 0 16px; height: 32px; border-radius: 999px;
background: #e8e4de; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff;
font-family: 'Sora'; font-size: 11px; font-weight: 600;
```
Variantes : `.primary` (bleu), `.success` (vert), `.danger` (rouge), `.gold` (admin)

### Tooltip sur boutons icône
```css
.tbl-btn[title]:hover::after {
    content: attr(title);
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: #2c2a27; color: #f0ece6;
    font-size: 10px; padding: 3px 7px; border-radius: 4px;
    white-space: nowrap; pointer-events: none; z-index: 999;
}
```

### Section title V2
```html
<div class="sec-head">
    <div class="line-l"></div>
    <span class="sec-txt">Titre section</span>
    <div class="line-r"></div>
</div>
```

### Cartes `.v2-card`
```css
background: #e8e4de; border-radius: 20px;
box-shadow: 8px 8px 18px #c4c0ba, -8px -8px 18px #ffffff;
```
Structure : `.v2-card-head` + `.v2-card-body`

### Badges `.v2-badge`
- `.ok` : fond vert clair
- `.warn` : fond jaune clair
- `.danger` : fond rouge clair
- `.locked` : fond sable neumorphique
