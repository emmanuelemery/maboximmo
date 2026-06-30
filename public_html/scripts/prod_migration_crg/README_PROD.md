# Migration CRG EMERY IMMO — PROD (Hostinger)

Dossier à déployer tel quel dans `public_html/scripts/prod_migration_crg/`.
Contenu : `run_prod.php` (orchestrateur) + `crg_emery.jsonl` (230 CRG parsés en local).

> ⚠️ Le parsing est fait EN LOCAL (Python/pdfplumber). La prod n'a pas Python :
> elle lit le JSONL embarqué. **Ne pas régénérer le JSONL sur la prod.**

## 0. AVANT TOUT — obligatoire

1. **BACKUP COMPLET de la BDD prod** (mysqldump). Non négociable.
2. (Optionnel) copier les 230 PDF dans `public_html/uploads/crg/` (pour la GED /
   l'ouverture des fichiers). L'import N'EN A PAS BESOIN.
3. Déployer ce dossier sur la prod (FTP / outil de déploiement).
4. Vérifier que `GOOGLE_MAPS_API_KEY` est configurée en prod (pour l'étape geocode).

## 1. Exécution — depuis le navigateur, connecté en SUPER-ADMIN

URL de base :
`https://<prod>/scripts/prod_migration_crg/run_prod.php?step=...`

Faire **chaque étape d'abord en DRY-RUN** (sans `&go=1`), lire le résultat,
puis relancer avec `&go=1`. Ordre impératif :

| Ordre | Dry-run | Exécution |
|-------|---------|-----------|
| 1. État | `?step=status` | — |
| 2. Réconciliation proprios | `?step=reconcile` | `?step=reconcile&go=1` |
| 3. Pré-liaison biens | `?step=prestamp` | `?step=prestamp&go=1` |
| 4. Import | `?step=import` | `?step=import&go=1` |
| 5. Géocodage | `?step=geocode` | `?step=geocode&go=1&max=300` (relancer tant que « restant » > 0) |
| 6. Fusion doublons | `?step=merge` | `?step=merge&go=1` |
| 7. Vérification | `?step=verify` | — |

> ⚠️ **reconcile + prestamp AVANT import** : sinon les proprios/biens d'annonce
> existants sont dupliqués (l'import ne les reconnaît que par code_compte/code_crg
> qui sont posés par reconcile/prestamp).

## 2. Garanties de sécurité

- **import / reconcile / prestamp / merge** tournent en transaction.
- **import est idempotent** : rejouable sans créer de doublon (dédup par
  `code_compte`, `code_crg`, `id_immeuble+numero_lot`, `annee/trimestre`).
- **merge auto-rollback** : si le nombre d'annonces change ou qu'un lien casse,
  il **annule tout** (rien écrit) et affiche l'erreur.
- **reconcile** ne stampe que les correspondances FORTES (nom+prénom) en
  société EMERY ; les homonymes Lyon et les collisions sont laissés séparés.
- **merge** ne fusionne jamais deux bâtiments distincts au même `place_id`
  (ex. « 6 Maupassant » appart vs garages → conservés séparés).

## 3. Contrôle final Ubiflow

Après l'étape 7 :
1. Noter le nombre d'annonces affiché par `?step=verify` (doit être inchangé vs
   avant migration) et « Annonces à lien cassé : 0 ».
2. Régénérer le flux : admin → Flux XML Ubiflow (ou
   `php api/flux/ubiflow.php --all --save` si CLI dispo), et comparer le nombre
   d'annonces par agence avant/après. Doit être identique.

## 4. En cas de souci

- L'import s'est arrêté en cours ? Relancer `?step=import&go=1` : il reprend
  (idempotent), il ne recrée rien de déjà présent.
- Doute sur la fusion ? `?step=merge` (dry-run) montre le plan sans rien écrire.
- Catastrophe ? Restaurer le backup de l'étape 0.

## Valeurs de référence (local, pour comparaison)

Import : 225 CRG exploitables (2026-T1), ~213 proprios créés + ~12 réutilisés,
~228 immeubles, ~311 biens, ~305 baux. Reconcile : 10 proprios stampés.
Prestamp : 8 biens d'annonce pré-liés. Geocode : ~239 immeubles. Merge : dépend
des doublons réels de prod (en local : 78 groupes / 89 immeubles supprimés, tous
legacy GROUPE SIR + 2 EMERY ; annonces 63=63 inchangées).
