# 🚀 QUICK START - Générateur Visuels Annonces

## En 3 clics : générer un visuel pour votre bien

### 1️⃣ **Choisir une approche** (type de visuel)

| Approche | Pour quel bien ? | Exemple |
|----------|-----------------|---------|
| 📸 **Photo en Grand** | Vue exceptionnelle, très lumineux | Appart avec terrasse panoramique |
| 💬 **Argument en Avant** | Style de vie, localisation prestige | Nouveau building, hyper-centre |
| 🎓 **Ciblage** | Étudiant / Investisseur / Famille | Studio près fac, T3 bon rendement |
| 🖼️ **Multi-Photos** | Haut de gamme, galerie riche | Villa prestige, immeuble exception |

### 2️⃣ **Remplir les infos du bien**

#### Pour **Photo en Grand** :
```
Titre            : BEL APPARTEMENT LUMINEUX
Sous-titre       : 3 pièces – 68 m² – Balcon
Photo principale : (photo du bien)
Prix             : 265 000 € FAI
```

#### Pour **Argument en Avant** :
```
Message principal : CONFORT, LUMIÈRE
Sous-message     : et emplacement idéal !
4 arguments clés :
  ☀️ EXPOSITION SUD-OUEST – Lumineuse...
  📍 EMPLACEMENT RECHERCHE – Commerce...
  🏠 RESIDENCE RECENTE – Calme...
  € FAIBLES CHARGES – Perf. énerg...
```

#### Pour **Ciblage** :
```
Cible            : Étudiant OU Investisseur OU Famille
Photo            : (photo représentative)
3 points clés adaptés à la cible
Prix             : à adapter (€/mois pour étudiant)
```

#### Pour **Multi-Photos** :
```
Titre            : BEL APPARTEMENT
Sous-titre       : AU CŒUR DE LA VILLE
Photo principale : (grande image)
Galerie (3-4)    : Chambre, Cuisine, Balcon, Autres
```

### 3️⃣ **Générer pour quels formats ?**

Tous les formats générés automatiquement :

- **Vitrine agence** → A3 Horizontal (imprimé 42×60cm)
- **Email/PDF** → A4 Vertical (feuille A4)
- **Instagram** → Carré + Story + Grille
- **Facebook/LinkedIn** → Format 1.91:1

---

## 📱 Où utiliser chaque format ?

### 🪟 **A3 Horizontal**
- Affichage vitrine agence
- Imprimer sur affiche 42×60cm
- Fenêtre magasin
- **Résolution : 1122×1587px**

### 📄 **A4 Vertical**
- Email aux clients
- Flyers papier à imprimer
- PDF partagé
- Document téléchargeable
- **Résolution : 794×1123px**

### 📸 **Instagram Carré**
- Publier sur Instagram grid
- Pinterest
- LinkedIn actualités
- **Résolution : 1080×1080px**

### 🎬 **Instagram Story**
- Stories Instagram (disparaît 24h)
- TikTok
- Snapchat
- Contenu vertical
- **Résolution : 1080×1920px**

### 💼 **Facebook/LinkedIn**
- Partage Facebook
- LinkedIn Pulse
- Annonces sponsorisées
- **Résolution : 1200×628px**

---

## 💡 Comment choisir l'approche ?

### **Si le bien a…**
- ✅ **Vue spectaculaire** → Photo en Grand
- ✅ **Nouvelle résidence** → Argument en Avant
- ✅ **Studio près fac** → Ciblage Étudiant
- ✅ **Bon rendement** → Ciblage Investisseur
- ✅ **Maison spacieuse** → Ciblage Famille
- ✅ **Prestige/galerie** → Multi-Photos

### **Budget / Timing ?**
Chaque approche = même temps de production
**Tous les formats générés en même temps**

---

## 🎨 Personnalisation

### Couleurs agence
- Primaire (bleu) : `#1a3a4a`
- Accent (or) : `#c9a961`
- Modifier dans `styles.css` `:root { }`

### Logo agence
Remplacer le SVG dans chaque template HTML

### Texte de contact
Ajouter en bas : `www.votreagence.fr | 01 23 45 67 89`

---

## 📊 Résultats : une approche = 5 formats

**Exemple : 1 bien avec approche "Photo en Grand"**

| Format | Fichier | Usages |
|--------|---------|--------|
| A3 | `bien-001-a3.html` | Imprimer vitrine |
| A4 | `bien-001-a4.html` | Email, PDF, flyer |
| Instagram Carré | `bien-001-ig-square.html` | Instagram grid |
| Instagram Story | `bien-001-ig-story.html` | Stories 24h |
| Facebook | `bien-001-fb.html` | Réseaux sociaux |

**Total : 5 fichiers HTML prêts à utiliser**

---

## ⚡ Workflow type

```
1. Ajouter bien dans MaBoxImmo
2. Sélectionner approche visuelle
3. Charger photo(s) + infos
4. Cliquer "Générer visuels"
5. ✅ Tous les formats générés
6. Télécharger / Partager
   - Imprimer A3 pour vitrine
   - Envoyer A4 par email
   - Poster sur Instagram/Facebook
```

---

## 🔗 Intégration MaBoxImmo

### Admin / Backend
```
Biens → Sélectionner bien → Onglet "Visuels" → Choisir approche → Générer
```

### Clients
```
Annonce → "Télécharger visuels" → Choisir format → ZIP avec tous les formats
```

### API
```php
// Code pour développeurs
$visuals = mbi_generate_annonce_visuals(
  bien_id: 123,
  approach: 'approche1',
  formats: ['a3', 'a4', 'instagram-square'] // optionnel
);
```

---

## ❓ FAQ

### "Quel format pour la vitrine ?"
**A3 Horizontal** (1122×1587px) → Imprimer 42×60cm ou 30×42cm

### "Comment partager sur Instagram ?"
- **Grille** : utiliser **Instagram Carré**
- **Stories** : utiliser **Instagram Story**

### "Puis-je customiser les couleurs ?"
Oui ! Modifier `styles.css` variables `:root`

### "Comment exporter en PNG ?"
Version HTML est native. Pour PNG : cliquer "Exporter image" (requiert wkhtmltoimage)

### "Je veux 2 approches pour un même bien ?"
Générer les deux ! Chaque approche = nouvelle génération

### "Combien de temps pour générer ?"
**Instantané** (HTML/CSS pur, pas de processing serveur)

---

## 🎯 Tips & Bonnes pratiques

✅ **À FAIRE :**
- Photos haute résolution (2000×2000px minimum)
- Titre court et percutant
- Prix clair (€ FAI ou € CC)
- Features vraies et spécifiques

❌ **À NE PAS FAIRE :**
- Photos floues ou de mauvaise qualité
- Trop de texte (< 3 lignes par zone)
- Prix ambigus
- Infos manquantes

---

## 📞 Support

**Fichiers de référence :**
- `README.md` — Doc technique complète
- `config.json` — Règles de sélection auto
- `integration.php` — Intégration backend

**Pour l'équipe tech :**
Voir `README.md` section "Intégration PHP"

**Pour les agents :**
Demander à l'admin comment accéder au générateur

---

**Généré pour MaBoxImmo — 2026**
