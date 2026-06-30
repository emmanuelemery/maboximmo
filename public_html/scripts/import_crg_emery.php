<?php
/**
 * import_crg_emery.php — Import batch des CRG EMERY IMMO (Riom).
 *
 * Réutilise le parseur Lyon (parse_crg.py) et la même logique de dédup que
 * import_all_crg.php, mais :
 *   - parcourt D:\CRG EMERY IMMO (1 CRG par propriétaire, noms TTA*.tmp.pdf) ;
 *   - identifie/crée le propriétaire PAR CRG via le COMPTE PERSONNEL ICS
 *     (proprietaires.code_compte) au lieu d'un mapping dossier ;
 *   - rattache à l'agence "EMERY IMMO RIOM" (résolue par nom, pas par id en dur) ;
 *   - ignore proprement les documents sans trimestre (relevés "Solde de compte").
 *
 * Usage :
 *   php import_crg_emery.php           → DRY-RUN (simulation, aucune écriture)
 *   php import_crg_emery.php go        → exécution réelle (commit)
 *
 * L'import est idempotent (dédup) : rejouable sans créer de doublons.
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

$DRY   = !(($argv[1] ?? '') === 'go');
$python = 'C:/Users/emery/AppData/Local/Python/bin/python3.exe';
$parser = __DIR__ . '/parse_crg.py';
$base_dir = 'D:/CRG EMERY IMMO';

// ── Cible : agence EMERY IMMO RIOM (résolue par nom) ───────────────
$ag = $pdo->prepare("SELECT id, id_societe FROM agences WHERE nom_agence = ? LIMIT 1");
$ag->execute(['EMERY IMMO RIOM']);
$agence = $ag->fetch(PDO::FETCH_ASSOC);
if (!$agence) {
    fwrite(STDERR, "ERREUR : agence 'EMERY IMMO RIOM' introuvable. Abandon.\n");
    exit(1);
}
$ID_AGENCE  = (int)$agence['id'];
$ID_SOCIETE = (int)$agence['id_societe'];

echo ($DRY ? "=== DRY-RUN (simulation, aucune écriture) ===" : "=== IMPORT RÉEL ===") . "\n";
echo "Cible : agence #$ID_AGENCE / société #$ID_SOCIETE\n";
echo "Source : $base_dir\n\n";

// ── Mapping type lot → types_bien.id ──────────────────────────────
$type_bien_map = [];
foreach ($pdo->query("SELECT id, code FROM types_bien")->fetchAll() as $r) {
    $type_bien_map[$r['code']] = (int)$r['id'];
}
$default_type_id = $type_bien_map['appartement'] ?? 1;

/**
 * Les enums de statut ont des libellés MOJIBAKE dans le schéma (l'accent « é »
 * y est stocké corrompu, ex "occup├®"). On lit les vrais membres et on matche
 * par squelette ASCII pour insérer la valeur EXACTE attendue par la colonne.
 */
function enum_members(PDO $pdo, string $table, string $col): array {
    $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(PDO::FETCH_ASSOC);
    if (!$r || !preg_match('/^enum\((.*)\)$/is', $r['Type'], $m)) return [];
    return str_getcsv($m[1], ',', "'");
}
$skeleton = fn(string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s));
$biens_statut_members = enum_members($pdo, 'biens', 'statut_occupation');
$situ_statut_members  = enum_members($pdo, 'crg_situations_locataires', 'statut_trimestre');

/** Résout un statut logique (occupe|parti-debiteur|vacant) vers le membre enum réel. */
function resolve_statut(string $lotStatut, array $members, callable $skeleton): string {
    $desired = ['occupe' => 'occupé', 'parti-debiteur' => 'parti-débiteur', 'vacant' => 'vacant'][$lotStatut] ?? 'vacant';
    $tgt = $skeleton($desired);
    foreach ($members as $mm) { if ($skeleton($mm) === $tgt) return $mm; }
    foreach ($members as $mm) { if ($skeleton($mm) === 'vacant') return $mm; }
    return $members[0] ?? 'vacant';
}

function lot_type_to_type_bien_id(string $lot_type, string $categorie, array $map, int $default): int {
    $t = strtolower($lot_type);
    if (str_contains($t, 'appart') || str_contains($t, 'studio') || str_contains($t, 'chambre') || preg_match('/\bt[1-5]\b/', $t))
        return $map['appartement'] ?? $default;
    if (str_contains($t, 'maison') || str_contains($t, 'villa') || str_contains($t, 'pavillon'))
        return $map['maison'] ?? $default;
    if (str_contains($t, 'local') || str_contains($t, 'magasin') || str_contains($t, 'boutique') || str_contains($t, 'commerce'))
        return $map['local_commercial'] ?? $default;
    if (str_contains($t, 'bureau'))   return $map['bureau'] ?? $default;
    if (str_contains($t, 'garage') || str_contains($t, 'box')) return $map['garage'] ?? $default;
    if (str_contains($t, 'parking') || str_contains($t, 'place')) return $map['parking'] ?? $default;
    if (str_contains($t, 'cave'))     return $map['garage'] ?? $default;
    if (str_contains($t, 'entrep') || str_contains($t, 'depot')) return $map['entrepot'] ?? $default;
    if ($categorie === 'habitation')  return $map['appartement'] ?? $default;
    if ($categorie === 'commercial')  return $map['local_commercial'] ?? $default;
    return $default;
}

/** Découpe le nom propriétaire du CRG en civilité / nom / prénom / société. */
function parse_proprietaire_name(string $raw): array {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    // dédoublonne un mot répété en tête ("SCI SCI CARINA" → "SCI CARINA")
    $raw = preg_replace('/^(\b[\p{L}]+\b)\s+\1\b/iu', '$1', $raw);
    $isMorale = (bool)preg_match('/\b(SCI|SARL|SASU|SAS|EURL|SCM|SNC|SA|GFA|GAEC|SCEA|SCP|INDIVISION|SUCCESSION|ENTREPRISE|SOCIET[EÉ]|ASSOCIATION)\b/iu', $raw);
    if ($isMorale) {
        return ['type' => 'morale', 'civilite' => null, 'nom' => $raw, 'prenom' => null, 'societe' => $raw];
    }
    $civ = null; $rest = $raw;
    if (preg_match('/^(M\.\s*(?:et|ou)\s*Mme|Mr\s*(?:et|ou)\s*Mme|Monsieur\s*(?:et|ou)\s*Madame|Mademoiselle|Monsieur|Madame|Mlle|Mme|Mr|M\.)\s+/iu', $raw, $mm)) {
        $civ  = trim($mm[1]);
        $rest = trim(substr($raw, strlen($mm[0])));
    }
    $tokens = $rest === '' ? [] : explode(' ', $rest);
    $nomParts = []; $prenomParts = []; $inNom = true;
    foreach ($tokens as $tk) {
        if ($inNom && preg_match('/^[\p{Lu}][\p{Lu}\'\-]+$/u', $tk)) {
            $nomParts[] = $tk;
        } else {
            $inNom = false; $prenomParts[] = $tk;
        }
    }
    if (!$nomParts) { $nomParts = $tokens; $prenomParts = []; }
    return [
        'type'     => 'physique',
        'civilite' => $civ,
        'nom'      => implode(' ', $nomParts) ?: $raw,
        'prenom'   => implode(' ', $prenomParts) ?: null,
        'societe'  => null,
    ];
}


// ── Stats ─────────────────────────────────────────────────────────
$st = [
    'total' => 0, 'parse_err' => 0, 'skip_no_periode' => 0,
    'prop_new' => 0, 'prop_found' => 0,
    'imm_new' => 0, 'bien_new' => 0, 'bail_new' => 0,
    'crg_new' => 0, 'situ' => 0, 'ecr' => 0, 'ecr_skip' => 0,
];
$skipped = [];
$errors  = [];

// ── Parsing : UN seul process Python pour tous les PDF (rapide + fiable) ──
$batch  = __DIR__ . '/parse_crg_batch.php_out.jsonl';
@unlink($batch);
$cmd = escapeshellarg($python) . ' ' . escapeshellarg(__DIR__ . '/parse_crg_batch.py')
     . ' ' . escapeshellarg($base_dir) . ' ' . escapeshellarg($batch) . ' 2>nul';
echo "Parsing des CRG (un seul process Python)…\n";
$nbParsed = (int)trim((string)shell_exec($cmd));
if (!is_file($batch)) {
    fwrite(STDERR, "ERREUR : parsing batch échoué (aucune sortie).\n");
    exit(1);
}
echo "Parsés : $nbParsed\n\n";

$fh = fopen($batch, 'r');

$pdo->beginTransaction();

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $st['total']++;
    $data = json_decode($line, true);
    $fn = is_array($data) ? (string)($data['_file'] ?? '?') : '?';

    if (!is_array($data) || empty($data['meta'])) {
        $st['parse_err']++;
        $errors[] = "$fn : " . (is_array($data) ? ($data['error'] ?? 'sans meta') : 'JSON invalide');
        continue;
    }
    $meta = $data['meta'];
    $annee = (int)($meta['annee'] ?? 0);
    $trim  = (int)($meta['trimestre'] ?? 0);
    $compte = trim((string)($meta['compte'] ?? ''));

    // Documents sans trimestre (relevés "Solde de compte") → on ignore.
    if (!$annee || !$trim) {
        $st['skip_no_periode']++; $skipped[] = "$fn (compte $compte)";
        continue;
    }
    if ($compte === '') { $st['parse_err']++; $errors[] = "$fn : compte vide"; continue; }

    // ── Propriétaire : retrouver par code_compte, sinon créer ──────
    $sp = $pdo->prepare("SELECT id FROM proprietaires WHERE code_compte = ? LIMIT 1");
    $sp->execute([$compte]);
    $prow = $sp->fetch(PDO::FETCH_ASSOC);

    if ($prow) {
        $id_prop = (int)$prow['id']; $st['prop_found']++;
    } else {
        $pp = parse_proprietaire_name((string)($meta['proprietaire'] ?? ''));
        $ins = $pdo->prepare("INSERT INTO proprietaires
            (id_agence, type_personne, civilite, nom, prenom, societe, code_compte, actif, date_creation)
            VALUES (?,?,?,?,?,?,?,1,NOW())");
        $ins->execute([
            $ID_AGENCE, $pp['type'], $pp['civilite'], $pp['nom'], $pp['prenom'], $pp['societe'], $compte,
        ]);
        $id_prop = (int)$pdo->lastInsertId();
        $st['prop_new']++;
    }

    // ── CRG trimestre (dédup id_proprietaire+annee+trimestre) ──────
    $sc = $pdo->prepare("SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?");
    $sc->execute([$id_prop, $annee, $trim]);
    if ($sc->fetch()) {
        // déjà importé pour ce proprio/trimestre → on saute (idempotent)
        continue;
    }
    $ic = $pdo->prepare("INSERT INTO crg_trimestres
        (id_proprietaire, annee, trimestre, fichier_pdf, parse_statut, parsed_at, date_arrete, solde_report, total_debits, total_credits, total_tva)
        VALUES (?,?,?,?,'ok',NOW(),STR_TO_DATE(?, '%d/%m/%Y'),?,?,?,?)");
    $ic->execute([
        $id_prop, $annee, $trim, $fn,
        $meta['date_arrete'], $meta['solde_report'] ?? 0,
        $meta['total_debits'] ?? 0, $meta['total_credits'] ?? 0, $meta['total_tva'] ?? 0,
    ]);
    $id_crg = (int)$pdo->lastInsertId();
    $st['crg_new']++;

    // ── Immeubles / biens / baux ──────────────────────────────────
    foreach ($data['immeubles'] as $imm) {
        $code = (string)($imm['code'] ?? '');
        $si = $pdo->prepare("SELECT id FROM immeubles WHERE code_crg=? AND id_proprietaire=?");
        $si->execute([$code, $id_prop]);
        $irow = $si->fetch(PDO::FETCH_ASSOC);
        if ($irow) {
            $id_imm = (int)$irow['id'];
        } else {
            preg_match('/(\d{5})\s+(.+)$/', (string)($imm['adresse'] ?? ''), $g);
            $cp = $g[1] ?? ''; $ville = trim($g[2] ?? '');
            $adr1 = trim((string)($imm['adresse'] ?? ''));
            if ($cp !== '') { $adr1 = trim(preg_replace('/\s*'.preg_quote($cp,'/').'\s+.*$/', '', $adr1)); }
            $ii = $pdo->prepare("INSERT INTO immeubles
                (id_proprietaire, id_societe, id_agence, code_crg, reference_immeuble, nom_immeuble, adresse_1, code_postal, ville, type_immeuble, mode_gestion)
                VALUES (?,?,?,?,?,?,?,?,?,'immeuble','gestion')");
            $ii->execute([$id_prop, $ID_SOCIETE, $ID_AGENCE, $code, $code, (string)($imm['nom'] ?? ''), $adr1, $cp, $ville]);
            $id_imm = (int)$pdo->lastInsertId();
            $st['imm_new']++;
        }

        foreach ($imm['lots'] as $lot) {
            $numlot = (string)($lot['numero_lot'] ?? '');
            $statut      = resolve_statut((string)$lot['statut'], $biens_statut_members, $skeleton);
            $statut_situ = resolve_statut((string)$lot['statut'], $situ_statut_members, $skeleton);

            $code_crg_bien = $code . '_' . $numlot;
            // Dédup en 2 temps pour NE JAMAIS dupliquer un bien déjà présent :
            //  1) par code_crg = clé de pré-rattachement (ex : un bien d'annonce qu'on a
            //     pré-stampé pour qu'il porte aussi la gestion) → on le RÉUTILISE en place ;
            //  2) sinon par immeuble + numéro de lot.
            $sb = $pdo->prepare("SELECT id FROM biens WHERE code_crg=? LIMIT 1");
            $sb->execute([$code_crg_bien]);
            $brow = $sb->fetch(PDO::FETCH_ASSOC);
            if (!$brow) {
                $sb = $pdo->prepare("SELECT id FROM biens WHERE id_immeuble=? AND numero_lot=?");
                $sb->execute([$id_imm, $numlot]);
                $brow = $sb->fetch(PDO::FETCH_ASSOC);
            }
            if ($brow) {
                $id_bien = (int)$brow['id'];
                // Enrichissement NON destructif : on rattache la gestion au bien existant
                // (id_immeuble + code_crg) sans toucher aux champs d'annonce/Ubiflow.
                $pdo->prepare("UPDATE biens SET id_immeuble = COALESCE(id_immeuble, ?), code_crg = COALESCE(code_crg, ?) WHERE id = ?")
                    ->execute([$id_imm, $code_crg_bien, $id_bien]);
            } else {
                $type_id = lot_type_to_type_bien_id((string)$lot['type_bien'], (string)$lot['categorie'], $type_bien_map, $default_type_id);
                $ib = $pdo->prepare("INSERT INTO biens
                    (id_immeuble, id_proprietaire, id_societe, id_agence, id_type_bien, numero_lot, code_crg, statut_occupation, statut_bien, designation)
                    VALUES (?,?,?,?,?,?,?,?,'actif',?)");
                $ib->execute([
                    $id_imm, $id_prop, $ID_SOCIETE, $ID_AGENCE, $type_id, $numlot,
                    $code . '_' . $numlot, $statut,
                    'Lot ' . $numlot . ' — ' . $lot['type_bien'],
                ]);
                $id_bien = (int)$pdo->lastInsertId();
                $st['bien_new']++;
            }

            // Bail (dédup : un bail actif par bien) — uniquement locataire occupant
            $id_bail = null;
            if ($lot['statut'] === 'occupe' && !empty($lot['locataire_nom']) && ($lot['loyer_appele'] ?? 0) > 0) {
                $sba = $pdo->prepare("SELECT id FROM baux WHERE id_bien=? AND statut='actif' LIMIT 1");
                $sba->execute([$id_bien]);
                $barow = $sba->fetch(PDO::FETCH_ASSOC);
                if ($barow) {
                    $id_bail = (int)$barow['id'];
                } else {
                    $iba = $pdo->prepare("INSERT INTO baux
                        (id_bien, id_proprietaire, locataire_nom, loyer, loyer_hc, statut, date_creation)
                        VALUES (?,?,?,?,?,'actif',NOW())");
                    $iba->execute([$id_bien, $id_prop, $lot['locataire_nom'], $lot['loyer_appele'], $lot['loyer_appele']]);
                    $id_bail = (int)$pdo->lastInsertId();
                    $st['bail_new']++;
                }
            }

            // Situation locataire du trimestre
            $isl = $pdo->prepare("INSERT INTO crg_situations_locataires
                (id_crg, id_bien, id_bail, locataire_nom, numero_lot, type_bien, categorie_bien,
                 loyer_appele, solde_anterieur, total_loyers, total_charges, total_regle, total_impaye, statut_trimestre)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $isl->execute([
                $id_crg, $id_bien, $id_bail,
                (string)($lot['locataire_nom'] ?? ''), $numlot,
                (string)$lot['type_bien'], (string)$lot['categorie'],
                $lot['loyer_appele'] ?? 0, $lot['solde_anterieur'] ?? 0,
                $lot['loyer_appele'] ?? 0, $lot['total_provisions'] ?? 0,
                $lot['total_regle'] ?? 0, $lot['total_impaye'] ?? 0,
                $statut_situ,
            ]);
            $st['situ']++;
        }

        // Écritures / charges
        foreach ($imm['charges'] ?? [] as $ch) {
            $montant = (float)($ch['montant'] ?? 0);
            // Garde-fou : le parseur de charges (regex) capte parfois des SIRET,
            // n° de facture, TVA intracom… comme montants → on ignore l'invraisemblable.
            if ($montant <= 0 || $montant >= 10000000) { $st['ecr_skip']++; continue; }
            $cat_map = [
                'honoraires' => 'honoraires_ht', 'syndic' => 'syndic', 'assurance' => 'assurance',
                'taxe_fonciere' => 'taxe_fonciere', 'huissier' => 'huissier', 'travaux' => 'travaux',
                'tlv' => 'tlv', 'indemnite_sinistre' => 'indemnite_sinistre', 'energie' => 'charges', 'eau' => 'charges',
            ];
            $db_cat = $cat_map[$ch['categorie'] ?? 'autre'] ?? 'autre';
            $ie = $pdo->prepare("INSERT INTO crg_ecritures (id_crg, id_bien, libelle, categorie, debit, tva) VALUES (?,NULL,?,?,?,?)");
            $ie->execute([$id_crg, (string)($ch['libelle'] ?? ''), $db_cat, $montant, min((float)($ch['tva'] ?? 0), 9999999)]);
            $st['ecr']++;
        }
    }

    echo sprintf("  [%3d] %-18s compte %-9s T%d %d → %s\n",
        $st['total'], substr($fn, 0, 18), $compte, $trim, $annee,
        ($prow ? 'proprio existant' : 'NOUVEAU proprio'));
}

fclose($fh);
@unlink($batch);
if ($DRY) { $pdo->rollBack(); } else { $pdo->commit(); }

// ── Résumé ────────────────────────────────────────────────────────
echo "\n=== RÉSUMÉ " . ($DRY ? "(DRY-RUN — rien écrit)" : "(IMPORT RÉEL — committé)") . " ===\n";
echo "Fichiers traités      : {$st['total']}\n";
echo "Propriétaires créés   : {$st['prop_new']}  (existants : {$st['prop_found']})\n";
echo "Immeubles créés       : {$st['imm_new']}\n";
echo "Biens créés           : {$st['bien_new']}\n";
echo "Baux créés            : {$st['bail_new']}\n";
echo "CRG trimestres créés  : {$st['crg_new']}\n";
echo "Situations locataires : {$st['situ']}\n";
echo "Écritures             : {$st['ecr']}  (ignorées suspectes : {$st['ecr_skip']})\n";
echo "Ignorés (sans période): {$st['skip_no_periode']}\n";
echo "Erreurs de parsing    : {$st['parse_err']}\n";
if ($skipped) { echo "\nIgnorés :\n  - " . implode("\n  - ", $skipped) . "\n"; }
if ($errors)  { echo "\nErreurs :\n  - " . implode("\n  - ", $errors) . "\n"; }
if ($DRY) { echo "\n→ Pour exécuter réellement : php import_crg_emery.php go\n"; }
