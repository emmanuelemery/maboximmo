# `docs/v2_migrations/` — ⚠️ CE DOSSIER N'EST PAS LE JEU COMPLET

**Le schéma de la base V2 `mbi` fait autorité sur le VPS, dans `/opt/mbi/migrations/`.**
Ce dossier-ci n'en contient qu'une **copie partielle** : les seules migrations que j'ai
écrites, versionnées ici parce que `/opt/mbi` n'est pas un dépôt git et que sans cela elles
n'existeraient nulle part ailleurs que sur la machine.

| ici | ailleurs |
|---|---|
| 0020 · 0022 · 0023 · 0024 · 0025 · 0026 · 0029 · 0030 | **0001 → 0019**, **0021**, **0027** — écrites par l'autre session |

⚠️ **NE PAS RECONSTRUIRE UNE BASE À PARTIR DE CE DOSSIER.** Il manque le socle entier et deux
migrations de garde-fous. La reconstruction se fait depuis `/opt/mbi/migrations/[0-9]*.sql`,
dans l'ordre — vérifiée le 09/09/2026 : 29 migrations, 0 échec.

⚠️ **ET LES MIGRATIONS SEULES NE SUFFISENT PAS.** Elles ne créent aucun utilisateur, alors que
`created_by` porte une clé étrangère : aucun import n'est possible sans l'amorçage
(`/opt/mbi/bin/bootstrap_super_admin.php`). Le schéma est reproductible, la base utilisable
demande une étape de plus.

## Les fichiers qui ne sont pas des migrations

| fichier | à quoi il sert |
|---|---|
| `_reference_chiffres.sql` | **la référence** — produit les chiffres de `docs/reference_v2_chiffres_import_crg.md`. Une comparaison n'a de sens que si cette requête n'a pas bougé. |
| `_controle_liens.sql` | tous les liens et les dix invariants, qui doivent valoir 0 |
| `_controle.sql` | l'état métier par agence après import |
| `_registre.sql` | amorçage du registre `schema_migrations` |
