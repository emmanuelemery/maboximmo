# Fixtures GED — Documents de test

Ce dossier contient des PDFs/images réels (anonymisés) servant de jeu de test pour
le pipeline d'extraction GED (`modules/ged/ged_extraction.php`).

## Convention de nommage des fixtures

```
{NUM}_{TYPE_ATTENDU}_{NOTE}.{ext}
```

Exemples :
- `01_facture_edf_propre.pdf` (PDF texte natif)
- `02_bail_loi_alur.pdf` (PDF texte natif, 8 pages)
- `03_pv_ag_scan.pdf` (PDF scanné — vision IA obligatoire)
- `04_rib_image.jpg` (image, vision IA)
- `05_attestation_assurance_pli.pdf` (PDF scanné de mauvaise qualité — stress test)

## Ground truth attendu

Pour chaque fixture, un fichier `.expected.json` doit décrire les valeurs **exactes**
qu'on s'attend à extraire. Le test `test_extraction.php` compare l'output IA à ce fichier
et calcule la précision champ par champ.

Exemple — `01_facture_edf_propre.expected.json` :
```json
{
  "type_document": "FACTURE",
  "module": "FOURNISSEURS",
  "date_document": "2026-04-15",
  "emetteur_nom": "EDF",
  "fournisseur": "EDF",
  "montant_ttc": 145.32,
  "devise": "EUR",
  "tolerances": {
    "description_courte": "any",
    "champs_confiance":   "any"
  }
}
```

Champs avec `"any"` dans `tolerances` = on n'évalue pas l'égalité stricte (ex.
description libre, scores de confiance qui peuvent varier).

## Lancement du test

```bash
php public_html/modules/ged/test_extraction.php
```

Le test affiche :
- Précision par champ critique
- Précision globale (matrice attendu vs obtenu)
- PASS si précision globale ≥ 80% sur les champs notés "obligatoires"

## Documents à fournir (5 fixtures cibles)

- [ ] **01_facture_propre.pdf** — facture fournisseur PDF natif (cas le plus courant)
- [ ] **02_bail_alur.pdf** — bail signé multi-pages
- [ ] **03_pv_ag_scan.pdf** — PV d'AG scanné (PDF image)
- [ ] **04_rib.jpg** — RIB en image (test vision pure)
- [ ] **05_attestation_pli.pdf** — document fortement dégradé (stress test, sera probablement < 80%)

Anonymise toujours : pas de noms réels, IBAN factices, montants modifiés,
adresses changées. Les fixtures sont versionnées (publiques côté repo).
