# 🔗 Relais Transaction ↔ Bailleur — Architecture

## Vue d'ensemble
Transaction (gestion commerciale des biens) et Bailleur (gestion du patrimoine) synchronisent le statut "vendu" bidirectionnellement. **Source de vérité unique** : les données physiques du bien (Transaction) remontent vers le propriétaire (Bailleur).

## 1. Source de vérité : `biens.statut_bien`

| Niveau | Colonne | Signification |
|--------|---------|---------------|
| **Bien** (Transaction) | `biens.statut_bien` | État du bien individuel (NULL, 'vendu', 'archive', etc.) |
| **Immeuble** (Bailleur) | `immeubles.vendu` | État du portefeuille : 0 (actif) / 1 (vendu) |

### Immeuble vendu = TOUS ses biens vendus
- Un immeuble passe `vendu=1` **seulement si** 100% de ses biens physiques sont `statut_bien='vendu'`
- Cela évite un immeuble plié "vendu" avec encore des biens actifs en Transaction

## 2. Flux Transaction → Bailleur (Acte Authentique)

```
Acte_Authentique.pdf déposé en Transaction
         ↓
   inc/transaction_metier_hooks.php:tmh_run_hooks()
         ↓
   ✅ Type='acte_authentique' + Confiance IA ≥ 90%
         ↓
   tmh_try_update_bien_vente()
         ├─ UPDATE biens.statut_bien = 'vendu'
         ├─ UPDATE biens.date_retrait_commercialisation
         └─ UPDATE biens.prix_final_vente
         ↓
   ✅ NOUVEAU: tmh_try_sync_immeuble_vendu()
         ├─ SELECT COUNT(*) from biens WHERE id_immeuble = X
         ├─ Si TOUS les biens.statut_bien = 'vendu'
         └─ UPDATE immeubles.vendu = 1
```

### Résultat
- ✅ Bailleur voit automatiquement l'immeuble passer "vendu" **sans action manuelle**
- ✅ La date de vente provient de l'acte (source Transaction)

---

## 3. Flux Bailleur → Transaction (Bouton 🏷️ Marquer vendu)

```
Bailleur: clic bouton "🏷️ Marquer vendu"
         ↓
   toggle_immeuble_vendu.php (maintenant sécurisé)
         ├─ Vérification accès : super admin OU bailleur
         └─ Vérification propriétaire
         ↓
   UPDATE immeubles.vendu = 1
         ↓
   ✅ NOUVEAU: _sync_biens_vendu()
         ├─ SELECT biens WHERE id_immeuble = X
         └─ UPDATE biens.statut_bien = 'vendu' (si NULL)
```

### Résultat
- ✅ Transaction voit automatiquement tous les biens de l'immeuble comme vendus
- ✅ Un démarquage rare (vendu=0) passe les biens en 'archive' pour éviter l'orphelinat

---

## 4. Synchronisation inverse (démarquage rare)

```
Bailleur: clic bouton "🏷️ Marquer non-vendu"
         ↓
   toggle_immeuble_vendu.php
         ↓
   UPDATE immeubles.vendu = 0
         ↓
   ✅ _sync_biens_not_vendu()
         └─ UPDATE biens.statut_bien = 'archive'
            (plutôt que 'vendu', pour ne pas les laisser en suspens)
```

---

## 5. Sécurité des endpoints AJAX

Tous les endpoints AJAX appelés par bailleur_patrimoine_actif.php (v.2026-05-30) vérifient :

### toggle_immeuble_vendu.php
```php
✅ require_login() — Authentification
✅ hasServiceAccess($roleId, 'bailleur') — Autorisation service
✅ user_proprietaires — Vérifier que l'immeuble appartient au propriétaire de l'utilisateur
```

### toggle_locataire_archive.php
```php
✅ require_login() — Authentification
✅ hasServiceAccess($roleId, 'bailleur') — Autorisation service
✅ user_proprietaires — Vérifier que le bien appartient au propriétaire
```

### get_locataire_history.php
```php
✅ require_login() — Authentification
✅ hasServiceAccess($roleId, 'bailleur') — Autorisation service
✅ user_proprietaires — Lecture des CRG restreinte au propriétaire
```

---

## 6. Cas d'usage : Vente d'un immeuble entier

### Scénario 1 : Transaction (acte authentique) en premier
```
1. Transaction : acte_authentique.pdf reçu → bien #42 (lot RDC) passe 'vendu'
2. Hook tmh_try_sync_immeuble_vendu() vérifie : 
   - Immeuble #8 a 2 lots (RDC + 1er étage)
   - RDC (bien #42) = 'vendu'  ✅
   - 1er étage (bien #43) = NULL ⚠
   - Immeuble #8 → RESTE actif (1/2 vendus)
3. Transaction : acte_authentique.pdf pour lot 1er étage
   - Bien #43 passe 'vendu'
   - Hook : TOUS les biens de #8 sont vendus → immeuble #8.vendu = 1 ✅
4. Bailleur : immeuble #8 automatiquement marqué VENDU (tableau redéployé)
```

### Scénario 2 : Bailleur en premier (bien partiel)
```
1. Bailleur : clic "Marquer vendu" sur immeuble #8
2. toggle_immeuble_vendu.php :
   - immeuble #8.vendu = 1
   - _sync_biens_vendu() :
     - Bien #42.statut_bien = 'vendu'
     - Bien #43.statut_bien = 'vendu'
3. Transaction voit maintenant les 2 biens comme 'vendu'
4. Lors d'un futur acte_authentique pour #42 :
   - Transaction : bien #42 déjà 'vendu' → skip update (idempotent)
```

---

## 7. Idempotence

- ✅ `tmh_try_sync_immeuble_vendu()` : vérifie d'abord si `immeuble.vendu = 1` → skip si déjà marqué
- ✅ `_sync_biens_vendu()` : UPDATE seulement si `biens.statut_bien IS NULL` → ignore les biens déjà à jour
- ✅ Acte_authentique.pdf relu 10× : même résultat (idempotent)

---

## 8. Logs/Audit

### tmh_run_hooks (après acte_authentique)
```json
{
  "mode": "apply",
  "actions": [
    {
      "table": "biens",
      "id": 42,
      "fields": {"statut_bien": "vendu", "date_retrait_commercialisation": "2026-05-30"}
    },
    {
      "table": "immeubles",
      "id": 8,
      "reason": "all_biens_sold",
      "fields": {"vendu": 1, "date_vente": "2026-05-30"}
    }
  ],
  "log": [
    {"level": "apply", "msg": "✅ UPDATE biens #42"},
    {"level": "apply", "msg": "✅ 🔗 SYNCHRO Bailleur : immeuble #8 marqué vendu"}
  ]
}
```

---

## 9. Points clés d'implémentation

| Point | Détail |
|-------|--------|
| **N+1 requis** | tmh_try_sync_immeuble_vendu() appelle une SELECT COUNT() supplémentaire — acceptable (hook, pas critique path) |
| **Date de vente** | Provient de date_retrait_commercialisation (Transaction) ou date du jour (Bailleur) |
| **Démarquage** | Très rare ; passe bien en 'archive' plutôt que le laisser orphelin |
| **Bien sans immeuble** | Possible (bien isolé). Hook l'ignore silencieusement. |
| **Bailleur multi-user** | Chaque utilisateur ne voit que ses propriétaires → OK |

---

## 10. À venir (V2)

- [ ] Afficher dans bailleur_patrimoine_actif.php le statut_bien par bien (colonne) — pour distinguer "immeuble entier vendu" vs "1 lot vendu"
- [ ] Quand locataire est parti/archivé (toggle_locataire_archive), exposer à Transaction : bien peut passer 'libre à la commercialisation'
- [ ] Webhook pour que lorsqu'un mandataire clique "Vente conclue" en Transaction, immeuble Bailleur se mette à jour en temps réel (non-PDF)
