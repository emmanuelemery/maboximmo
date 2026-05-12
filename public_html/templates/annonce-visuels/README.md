# 📋 TEMPLATES ANNONCES VISUELS - MaBoxImmo

## 🎯 Vue d'ensemble

4 approches design pour générer automatiquement les visuels d'annonces immobilières. Chaque approche est déclinée en 5 formats :

- **A3 Horizontal** (1122×1587px) → Vitrines agence
- **A4 Vertical** (794×1123px) → Papier & PDF email
- **Instagram Carré** (1080×1080px) → Réseaux sociaux
- **Instagram Story** (1080×1920px) → Stories & vertical
- **Facebook/LinkedIn** (1200×628px) → Réseaux professionnels

---

## 📁 Architecture

```
annonce-visuels/
├── styles.css                    # Feuille CSS partagée (variables + grilles)
├── approche1-photo-grand.html    # Photo immersive (émotion)
├── approche2-argument-avant.html # Argument commercial (style de vie)
├── approche3-ciblage.html        # Ciblage démographique
├── approche4-multi-photos.html   # Galerie premium
├── README.md                      # (ce fichier)
└── integration.php               # Exemple PHP d'utilisation
```

---

## 🎨 Les 4 approches

### 1️⃣ **LA PHOTO EN GRAND** — *Émotion visuelle immédiate*
- Grande image de fond + overlay texte sombre
- Cas d'usage : biens "coup de cœur", vue exceptionnelle
- Idéal pour : luminosité, terrasse, piscine, vue unique

**Données à fournir :**
```php
$approach1 = [
  'logo_svg' => '<svg>...</svg>',     // Logo agence
  'title' => 'BEL APPARTEMENT LUMINEUX',
  'subtitle' => '3 pièces – 68 m² – Balcon',
  'image_url' => 'https://...',       // Photo principale
  'price' => '265 000 € FAI',
  'details' => [
    ['icon' => '🛏️', 'label' => '2 CHAMBRES'],
    ['icon' => '🌳', 'label' => 'BALCON'],
    ['icon' => '⬆️', 'label' => 'ASCENSEUR'],
    ['icon' => '📦', 'label' => 'CAVE'],
  ]
];
```

---

### 2️⃣ **L'ARGUMENT EN AVANT** — *Vendre un style/avantage*
- Split : texte + image côte à côte
- Highlight principal (confort, lumière, localisation)
- 4 arguments secondaires avec icônes

**Données à fournir :**
```php
$approach2 = [
  'logo_svg' => '<svg>...</svg>',
  'main_text' => 'CONFORT, LUMIÈRE',
  'subtitle' => 'et emplacement idéal !',
  'image_url' => 'https://...',       // Intérieur/style
  'price' => '265 000 € FAI',
  'price_details' => '3 pièces – 68 m² – Balcon',
  'features' => [
    ['icon' => '☀️', 'title' => 'EXPOSITION SUD-OUEST', 'desc' => 'Lumineuse toute la journée'],
    ['icon' => '📍', 'title' => 'EMPLACEMENT RECHERCHE', 'desc' => 'À deux pas des commerces'],
    ['icon' => '🏠', 'title' => 'RESIDENCE RECENTE', 'desc' => 'Calme, sécurisée et bien entretenue'],
    ['icon' => '€', 'title' => 'FAIBLES CHARGES', 'desc' => 'Excellente performance énergétique'],
  ]
];
```

---

### 3️⃣ **CIBLAGE SPÉCIFIQUE** — *Par démographie*
- Header coloré avec icône + message ciblé
- 3 arguments + CTA spécifique
- **3 variantes** : Étudiant / Investisseur / Famille

**Données à fournir :**
```php
$approach3 = [
  'target' => 'etudiant|investisseur|famille',  // Force couleur & message
  'icon' => '🎓',                                // Emoji cible
  'header_title' => 'Étudiant ?',
  'header_subtitle' => 'CET APPARTEMENT EST FAIT POUR TOI !',
  'image_url' => 'https://...',
  'price' => '540 € CC / MOIS',
  'price_details' => 'Studio – 20 m²',
  'features' => [
    ['icon' => '📍', 'title' => 'À 5 MIN DES FACULTÉS', 'desc' => 'Transports, commerces...'],
    ['icon' => '💰', 'title' => 'LOYER ACCESSIBLE', 'desc' => 'Idéal pour maîtriser ton budget'],
    ['icon' => '🛋️', 'title' => 'MEUBLÉ & ÉQUIPÉ', 'desc' => 'Pose tes valises...'],
  ]
];
```

**Styles par cible :**
- `.target-etudiant` → Vert (#5dbea3)
- `.target-investisseur` → Marron (#c67c4e)
- `.target-famille` → Beige chaud (#d4a574)

---

### 4️⃣ **MULTI-PHOTOS PREMIUM** — *Galerie riche*
- 1 grande photo + galerie 3 petites (A3) ou responsive
- Parfait pour haut de gamme / portefeuilles
- Grille adaptée par format

**Données à fournir :**
```php
$approach4 = [
  'logo_svg' => '<svg>...</svg>',
  'title' => 'BEL APPARTEMENT',
  'subtitle' => 'AU CŒUR DE LA VILLE',
  'main_image' => 'https://...',      // Grande image
  'gallery_images' => [
    'https://...',  // Chambre
    'https://...',  // Cuisine
    'https://...',  // Balcon
    'https://...',  // Cave (optionnel)
  ],
  'price' => '265 000 € FAI',
  'price_details' => '3 pièces – 68 m²',
  'features' => [
    ['icon' => '🛏️', 'label' => '2 CHAMBRES'],
    ['icon' => '🌳', 'label' => 'BALCON'],
    ['icon' => '🍳', 'label' => 'CUISINE ÉQUIPÉE'],
    ['icon' => '📦', 'label' => 'CAVE'],
  ]
];
```

---

## 🔧 Intégration PHP

### Étape 1 : Charger le template
```php
<?php
// Chemin relatif depuis le fichier PHP qui inclut
$template_path = '/templates/annonce-visuels/approche1-photo-grand.html';
$template = file_get_contents($template_path);

// Ou loader via une fonction helper
function render_annonce_visual($approach, $data, $format = 'a3') {
  // $approach: 'approche1', 'approche2', 'approche3', 'approche4'
  // $data: array des données
  // $format: 'a3', 'a4', 'instagram-square', 'instagram-story', 'facebook'
  
  // À implémenter : remplacer les {{variables}} dans le HTML
}
?>
```

### Étape 2 : Remplacer les variables
```php
// Remplacer les variables dans le HTML
$data = [
  '{{TITLE}}' => 'BEL APPARTEMENT LUMINEUX',
  '{{SUBTITLE}}' => '3 pièces – 68 m² – Balcon',
  '{{IMAGE_URL}}' => 'https://example.com/image.jpg',
  '{{PRICE}}' => '265 000 € FAI',
  // ... etc
];

foreach ($data as $placeholder => $value) {
  $template = str_replace($placeholder, $value, $template);
}

echo $template;
```

### Étape 3 : Générer une image (optionnel)
```php
// Convertir HTML → PNG avec puppeteer ou similar
// ou laisser HTML brut pour affichage direct

// Option A : Affichage direct (recommandé pour web)
echo $template;

// Option B : Conversion → PNG (pour impression/social)
// Utiliser wkhtmltoimage, puppeteer, ou service cloud
```

---

## 📱 Formats & dimensions

| Format | Pixels | DPI | Usages |
|--------|--------|-----|--------|
| A3 Horizontal | 1122×1587 | 150 | Vitrines agence |
| A4 Vertical | 794×1123 | 150 | Papier + email |
| Instagram Carré | 1080×1080 | – | Instagram grid |
| Instagram Story | 1080×1920 | – | Stories, Reels |
| Facebook/LinkedIn | 1200×628 | – | Réseaux pro |

---

## 🎯 Sélectionner une approche

**Critères de choix :**

- **Approche 1** → Bien visuellement spectaculaire (vue, luminosité, terrasse)
- **Approche 2** → Vendre un lifestyle (luxe, confort, localisation prestige)
- **Approche 3** → Ciblage commercial (étudiant budget, investisseur rendement, famille)
- **Approche 4** → Haut de gamme, multiple biens, portfolio

---

## 🎨 Personnalisation CSS

Toutes les variables se trouvent en haut du fichier `styles.css` :

```css
:root {
  --color-primary: #1a3a4a;        /* Bleu marin agence */
  --color-accent: #c9a961;         /* Or/beige */
  --color-accent-light: #e8d5b7;   /* Beige clair */
  
  --font-family-display: 'Georgia', serif;    /* Titres */
  --font-family-body: 'Segoe UI', sans-serif; /* Corps */
  
  --font-size-title: 2.5rem;
  --font-size-subtitle: 1.8rem;
  /* ... */
}
```

**À adapter pour chaque agence :**
- Logo (SVG ou image)
- Couleurs (primaire, accent)
- Police (optionnel, vérifier licences)
- Liens de contact (email, téléphone, site)

---

## 🚀 Roadmap d'automatisation

Pour intégrer complètement dans MaBoxImmo :

1. **Phase 1** (Fait) : Créer templates HTML/CSS modulaires
2. **Phase 2** : Fonction PHP `render_annonce_visual($approach, $data, $format)`
3. **Phase 3** : Admin UI pour choisir approche/format par bien
4. **Phase 4** : Génération batch (tous les formats d'un coup)
5. **Phase 5** : Export PNG/JPG avec wkhtmltoimage (impression vitrine)
6. **Phase 6** : Intégration réseaux sociaux (auto-post Instagram, Facebook)

---

## 📧 Support & Questions

- Toutes les classes CSS sont nommées explicitement (`.approach-*`, `.format-*`)
- Responsive automatique via CSS Grid + media queries
- Pas de JavaScript requis (HTML/CSS pur)
- Compatible avec tous les navs modernes

Fichiers prêts pour intégration → `integration.php` contient un exemple complet.
