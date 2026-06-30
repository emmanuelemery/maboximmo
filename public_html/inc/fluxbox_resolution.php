<?php
declare(strict_types=1);

/**
 * FluxBox — Résolution de contexte fiable (Correctifs 1→4)
 * ════════════════════════════════════════════════════════════════════════
 * Objectif : FLUXBOX ne se trompe JAMAIS de société / agence / entité, et le
 * montre champ par champ (vert / jaune / rouge + source).
 *
 * Fonction unique, contrat figé :
 *   fluxbox_resoudre_contexte(int $carte_id, array $contexte_user, array $extraction_ia): array
 *
 * Chaîne de priorité STRICTE, par CHAMP (jamais globale) :
 *   1. contexte_explicite  (boutons tapés avant upload)  → gagne toujours, VERT, jamais écrasé
 *   2. document            (extrait IA, clair)            → VERT
 *   3. fiche               (entité rapprochée existante)  → VERT ou JAUNE
 *   4. contexte_user       (compte de l'utilisateur)      → DERNIER RECOURS, JAUNE max
 *
 * Invariants verrouillés :
 *   I1 : source == 'contexte_user' ⇒ confiance ∈ {jaune, rouge} (jamais vert)
 *   I2 : un choix explicite utilisateur n'est jamais écrasé
 *   I3 : bloquant = true si un champ obligatoire (société, agence, métier) est
 *        rouge, OU si société/agence est jaune non confirmé.
 *
 * Forme attendue de $contexte_user :
 *   [
 *     'explicite' => [ 'societe_id'=>?, 'societe_nom'=>?, 'agence_id'=>?, 'agence_nom'=>?,
 *                      'metier'=>?, 'domaine'=>?, 'type_document'=>?, 'entite_id'=>?,
 *                      'entite_nom'=>?, 'entite_type'=>?,
 *                      'confirme' => ['societe'=>bool, 'agence'=>bool] ],  // taps de confirmation
 *     'compte'    => [ 'user_id'=>?, 'societe_id'=>?, 'societe_nom'=>?,
 *                      'agence_id'=>?, 'agence_nom'=>? ],                  // fallback compte connecté
 *   ]
 *
 * Forme attendue de $extraction_ia (souple — toutes clés optionnelles) :
 *   [ 'societe'=>txt, 'siren'=>txt, 'agence'=>txt, 'metier'=>txt, 'domaine'=>txt,
 *     'type_doc'=>txt, 'type_document'=>code, 'entite_nom'=>txt, 'entite_siren'=>txt,
 *     'entite_adresse'=>txt, 'entite_cp'=>txt, 'entite_ville'=>txt, 'personne'=>txt,
 *     'date_doc'=>txt ]
 */

require_once __DIR__ . '/entity_matcher.php';

/* ────────────────────────────────────────────────────────────────────────
 * Constantes / vocabulaire
 * ──────────────────────────────────────────────────────────────────────── */

if (!function_exists('flux_types_vocabulaire')) {
    /** Vocabulaire contrôlé type_document (miroir de ged_document_types). */
    function flux_types_vocabulaire(): array {
        return ['CV','CNI','RIB','KBIS','BAIL','MANDAT','DPE','RELEVE_BANCAIRE','BILAN',
                'PV_AG','DIPLOME','CONTRAT_TRAVAIL','ATTESTATION','FACTURE','JUGEMENT','AUTRE'];
    }
}

if (!function_exists('flux_mapper_type_document')) {
    /**
     * Mappe un type libre (slug interne, mot-clé) → code du vocabulaire contrôlé.
     * Retourne '' si rien de fiable trouvé (le caller décidera du défaut).
     */
    function flux_mapper_type_document(string $raw): string {
        $r = strtoupper(trim($raw));
        if ($r === '') return '';
        // Déjà un code du vocabulaire ?
        if (in_array($r, flux_types_vocabulaire(), true)) return $r;
        // Normalise pour mots-clés
        $u = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', ' ', em_strip_accents($raw)));
        $regles = [
            'CV'              => '/\b(CV|CURRICULUM)\b/',
            'CNI'             => '/\b(CNI|CARTE? IDENTITE|PASSEPORT|TITRE SEJOUR)\b/',
            'RIB'             => '/\bRIB\b/',
            'RELEVE_BANCAIRE' => '/\b(RELEVE|EXTRAIT) (BANCAIRE|COMPTE)\b|\bRELEVE BANCAIRE\b/',
            'KBIS'            => '/\b(KBIS|K BIS|EXTRAIT KBIS)\b/',
            'BAIL'            => '/\bBAIL\b/',
            'MANDAT'          => '/\bMANDAT\b/',
            'DPE'             => '/\b(DPE|PERFORMANCE ENERGETIQUE)\b/',
            'BILAN'           => '/\b(BILAN|LIASSE FISCALE|COMPTE DE RESULTAT)\b/',
            'PV_AG'           => '/\b(PV AG|PROCES VERBAL|ASSEMBLEE GENERALE)\b/',
            'DIPLOME'         => '/\b(DIPLOME|ATTESTATION REUSSITE|MASTER|LICENCE|BTS)\b/',
            'CONTRAT_TRAVAIL' => '/\b(CONTRAT (DE )?TRAVAIL|CDI|CDD|AVENANT CONTRAT)\b/',
            'JUGEMENT'        => '/\b(JUGEMENT|ORDONNANCE|DECISION JUSTICE|TRIBUNAL)\b/',
            'FACTURE'         => '/\b(FACTURE|INVOICE|DEVIS)\b/',
            'ATTESTATION'     => '/\bATTESTATION\b/',
        ];
        foreach ($regles as $code => $re) {
            if (preg_match($re, $u)) return $code;
        }
        // Slugs internes connus (fluxbox_va_detect_type_from_filename)
        $slugMap = [
            'facture'=>'FACTURE','rib'=>'RIB','releve_bancaire'=>'RELEVE_BANCAIRE',
            'bail_signe'=>'BAIL','dpe'=>'DPE','attestation_assurance'=>'ATTESTATION',
            'mandat_gestion'=>'MANDAT','mandat_vente'=>'MANDAT','mandat_simple'=>'MANDAT',
        ];
        $low = strtolower(trim($raw));
        return $slugMap[$low] ?? '';
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * Helper de construction d'un champ résolu
 * ──────────────────────────────────────────────────────────────────────── */

if (!function_exists('flux_champ')) {
    /**
     * Construit un champ du contrat, en APPLIQUANT l'invariant I1.
     * @param string $source 'contexte_explicite'|'document'|'fiche'|'nom_fichier'|'contexte_user'|'defaut'
     */
    function flux_champ($valeur, string $confiance, string $source, array $extra = []): array {
        // Invariant I1 : contexte_user ne peut JAMAIS être vert.
        if ($source === 'contexte_user' && $confiance === 'vert') {
            $confiance = 'jaune';
        }
        // defaut/absence → rouge si pas de valeur
        if (($valeur === null || $valeur === '') && $confiance === 'vert') {
            $confiance = 'rouge';
        }
        return array_merge([
            'valeur'    => $valeur,
            'confiance' => $confiance,
            'source'    => $source,
        ], $extra);
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * Correctif 2 — Rapprochement à l'existant (anti-doublon)
 * ──────────────────────────────────────────────────────────────────────── */

if (!function_exists('flux_rapprocher_entite')) {
    /**
     * Rapproche une entité (personne / société) extraite contre tiers + users.
     * Normalise (lower/accents/trim), matche nom + (SIREN) + (adresse).
     *
     * @return array{
     *   match: ?array{type:string,id:int,label:string,score:int},
     *   candidats: array<int,array{type:string,id:int,label:string,score:int}>
     * }
     */
    function flux_rapprocher_entite(PDO $pdo, array $ext, ?int $scopeSocieteId = null): array {
        $nom    = trim((string)($ext['entite_nom'] ?? $ext['personne'] ?? ''));
        $siren  = em_normalize_siret((string)($ext['entite_siren'] ?? $ext['siren'] ?? ''));
        $out = ['match' => null, 'candidats' => []];
        if ($nom === '' && $siren === '') return $out;

        $cands = [];
        $nomNorm = em_normalize_name($nom);

        // 1) Match fort par SIREN/SIRET sur tiers
        if ($siren !== '') {
            try {
                $st = $pdo->prepare("SELECT id, nom_affichage, nom, prenom, raison_sociale
                    FROM tiers WHERE actif=1 AND (REPLACE(siren,' ','')=? OR LEFT(REPLACE(siret,' ',''),9)=?) LIMIT 5");
                $st->execute([$siren, $siren]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $label = (string)($r['nom_affichage'] ?: trim(($r['prenom']??'').' '.($r['nom']??'')) ?: $r['raison_sociale']);
                    $cands[] = ['type'=>'TIERS','id'=>(int)$r['id'],'label'=>$label,'score'=>98];
                }
            } catch (Throwable) {}
        }

        // 2) Match par nom sur tiers (LIKE tolérant) puis scoring par similarité
        if ($nomNorm !== '') {
            $like = '%' . str_replace(' ', '%', em_normalize_for_search($nom)) . '%';
            try {
                $sql = "SELECT id, nom_affichage, nom, prenom, raison_sociale FROM tiers
                        WHERE actif=1 AND (
                            LOWER(CONCAT_WS(' ',prenom,nom)) LIKE ? OR LOWER(nom) LIKE ?
                            OR LOWER(raison_sociale) LIKE ? OR LOWER(nom_affichage) LIKE ?)";
                $args = [$like,$like,$like,$like];
                if ($scopeSocieteId) { $sql .= " AND (id_societe = ? OR id_societe IS NULL)"; $args[] = $scopeSocieteId; }
                $sql .= " LIMIT 15";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $label = (string)($r['nom_affichage'] ?: trim(($r['prenom']??'').' '.($r['nom']??'')) ?: $r['raison_sociale']);
                    $cands[] = ['type'=>'TIERS','id'=>(int)$r['id'],'label'=>$label,
                                'score'=>flux_score_nom($nomNorm, em_normalize_name($label))];
                }
            } catch (Throwable) {}

            // 3) Match sur users (collaborateurs)
            try {
                $sql = "SELECT id, prenom, nom FROM users WHERE actif=1 AND LOWER(CONCAT_WS(' ',prenom,nom)) LIKE ?";
                $args = [$like];
                if ($scopeSocieteId) { $sql .= " AND (id_societe = ? OR id_societe IS NULL)"; $args[] = $scopeSocieteId; }
                $sql .= " LIMIT 10";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $label = trim(($r['prenom']??'').' '.($r['nom']??''));
                    $cands[] = ['type'=>'USER','id'=>(int)$r['id'],'label'=>$label,
                                'score'=>flux_score_nom($nomNorm, em_normalize_name($label))];
                }
            } catch (Throwable) {}
        }

        // Dédup (type+id), garde le meilleur score
        $best = [];
        foreach ($cands as $c) {
            $k = $c['type'].':'.$c['id'];
            if (!isset($best[$k]) || $c['score'] > $best[$k]['score']) $best[$k] = $c;
        }
        $cands = array_values($best);
        usort($cands, static fn($a,$b) => $b['score'] <=> $a['score']);
        $cands = array_slice($cands, 0, 5);

        $out['candidats'] = $cands;
        // Seuil de proposition : 80 (à confirmer = jaune)
        if (!empty($cands) && $cands[0]['score'] >= 80) $out['match'] = $cands[0];
        return $out;
    }
}

if (!function_exists('flux_score_nom')) {
    /** Score 0-100 de similarité entre 2 noms normalisés (Levenshtein-based). */
    function flux_score_nom(string $a, string $b): int {
        if ($a === '' || $b === '') return 0;
        if ($a === $b) return 100;
        $la = strlen($a); $lb = strlen($b);
        $max = max($la, $lb);
        if ($max > 255) { $a = substr($a,0,255); $b = substr($b,0,255); $max = max(strlen($a),strlen($b)); }
        $dist = levenshtein($a, $b);
        $sim = (int)round((1 - $dist / max(1,$max)) * 100);
        // Bonus si l'un contient l'autre (sous-chaîne)
        if (str_contains($a, $b) || str_contains($b, $a)) $sim = max($sim, 85);
        return max(0, min(100, $sim));
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * FONCTION PRINCIPALE — contrat figé
 * ──────────────────────────────────────────────────────────────────────── */

if (!function_exists('fluxbox_resoudre_contexte')) {
    /**
     * @param ?array $contexte_lot Correctif 6 : ['lot_id'=>…, 'entite_verrouillee'=>['type'=>,'id'=>,'valeur'=>],
     *                             'societe_id'=>…, 'agence_id'=>…, 'source'=>'fiche'|'nom_dossier']
     */
    function fluxbox_resoudre_contexte(int $carte_id, array $contexte_user, array $extraction_ia, ?array $contexte_lot = null, ?PDO $pdo = null): array {
        if ($pdo === null) $pdo = function_exists('ged_glossary_pdo') ? ged_glossary_pdo() : $GLOBALS['pdo'];

        $explicite = $contexte_user['explicite'] ?? [];
        $compte    = $contexte_user['compte']    ?? [];
        $confirme  = $explicite['confirme']       ?? [];

        // Filename (ancre) — chargé depuis la carte si dispo
        $fileName = (string)($extraction_ia['__filename'] ?? '');
        if ($fileName === '' && $carte_id > 0) {
            try {
                $st = $pdo->prepare("SELECT d.fichier_nom FROM fluxbox_cartes c
                    LEFT JOIN fluxbox_documents d ON d.id = c.document_id WHERE c.id = ? LIMIT 1");
                $st->execute([$carte_id]);
                $fileName = (string)($st->fetchColumn() ?: '');
            } catch (Throwable) {}
        }

        $scopeSocieteId = (int)($explicite['societe_id'] ?? $compte['societe_id'] ?? 0) ?: null;

        $societe = null; $agence = null; $entite = null; $ancres = [];
        $metierFromEntite = null; // métier inféré du type d'entité (QUI façonne aussi le métier par défaut)

        /* ═══════════════════════════════════════════════════════════════════
         * CHEMIN « QUI » — l'entité choisie (lot > explicite > rapprochement)
         * Invariant 3/4 : entité verrouillée/choisie ⇒ société/agence source=fiche/vert
         * ═══════════════════════════════════════════════════════════════════ */
        $rappro = flux_rapprocher_entite($pdo, $extraction_ia, $scopeSocieteId);

        // — Correctif 6 : contexte de LOT verrouillé (gagne sur tout sauf override explicite) —
        $lotEnt = (is_array($contexte_lot) && !empty($contexte_lot['entite_verrouillee']))
                ? $contexte_lot['entite_verrouillee'] : null;

        // Source d'entité retenue : explicite > lot > rapprochement
        $chosen = null; $chosenSource = null;
        if (!empty($explicite['entite_id'])) {
            $chosen = ['type'=>strtolower((string)($explicite['entite_type'] ?? 'tiers')),
                       'id'=>(int)$explicite['entite_id'], 'valeur'=>(string)($explicite['entite_nom'] ?? '')];
            $chosenSource = 'contexte_explicite';
        } elseif ($lotEnt) {
            $chosen = ['type'=>strtolower((string)($lotEnt['type'] ?? 'tiers')),
                       'id'=>(int)($lotEnt['id'] ?? 0), 'valeur'=>(string)($lotEnt['valeur'] ?? '')];
            $chosenSource = 'lot';
        } elseif ($rappro['match'] !== null) {
            $m = $rappro['match'];
            $chosen = ['type'=>strtolower($m['type']), 'id'=>(int)$m['id'], 'valeur'=>(string)$m['label']];
            $chosenSource = 'rapprochement';
        }

        if ($chosen !== null && $chosen['id'] > 0) {
            // Charge le scope réel de la fiche (société/agence/label/métier)
            $sc = flux_charger_scope_entite($pdo, $chosen['type'], $chosen['id']);
            $label = $chosen['valeur'] !== '' ? $chosen['valeur'] : (string)($sc['label'] ?? '');
            // Entité : vert si explicite/lot, jaune si simple rapprochement (à confirmer d'un tap)
            $confEnt = ($chosenSource === 'rapprochement') ? 'jaune' : 'vert';
            $srcEnt  = ($chosenSource === 'contexte_explicite') ? 'contexte_explicite'
                     : ($chosenSource === 'lot' ? 'lot' : 'fiche');
            $entite = flux_champ($label, $confEnt, $srcEnt, [
                'id'=>$chosen['id'], 'type'=>$chosen['type'], 'existante'=>true,
                'candidats'=>$rappro['candidats'], 'score'=>$rappro['match']['score'] ?? 100]);
            // Invariant 3/4 : société/agence DE LA FICHE, vert, jamais reprises du compte
            $srcScope = ($chosenSource === 'lot') ? 'lot' : 'fiche';
            if (!empty($sc['societe_id'])) {
                $societe = flux_champ($sc['societe_nom'], 'vert', $srcScope, ['id'=>(int)$sc['societe_id']]);
            }
            if (!empty($sc['agence_id'])) {
                $agence = flux_champ($sc['agence_nom'], 'vert', $srcScope, ['id'=>(int)$sc['agence_id']]);
            }
            if (!empty($sc['metier'])) $metierFromEntite = $sc['metier'];
        }

        /* ═══ SOCIÉTÉ explicite (override utilisateur — Invariant 2, jamais écrasé) ═══ */
        if (!empty($explicite['societe_id'])) {
            $societe = flux_champ((string)($explicite['societe_nom'] ?? $explicite['societe_id']), 'vert',
                'contexte_explicite', ['id'=>(int)$explicite['societe_id']]);
        }
        if (!empty($explicite['agence_id'])) {
            $agence = flux_champ((string)($explicite['agence_nom'] ?? $explicite['agence_id']), 'vert',
                'contexte_explicite', ['id'=>(int)$explicite['agence_id']]);
        }

        // Société/agence du lot (si pas d'entité fiche ni explicite) → source 'lot'
        if (is_array($contexte_lot)) {
            if ($societe === null && !empty($contexte_lot['societe_id'])) {
                $sl = flux_charger_libelle($pdo, 'societes', (int)$contexte_lot['societe_id']);
                $societe = flux_champ($sl, 'vert', 'lot', ['id'=>(int)$contexte_lot['societe_id']]);
            }
            if ($agence === null && !empty($contexte_lot['agence_id'])) {
                $al = flux_charger_libelle($pdo, 'agences', (int)$contexte_lot['agence_id']);
                $agence = flux_champ($al, 'vert', 'lot', ['id'=>(int)$contexte_lot['agence_id']]);
            }
        }

        /* ═══ ENTITÉ — fallback si aucune fiche : proposition document (jaune) + ancre ═══ */
        if ($entite === null) {
            $nomExt = trim((string)($extraction_ia['entite_nom'] ?? $extraction_ia['personne'] ?? ''));
            if ($nomExt !== '') {
                $entite = flux_champ($nomExt, 'jaune', 'document', [
                    'id'=>null, 'type'=>'tiers', 'existante'=>false, 'candidats'=>$rappro['candidats']]);
                $ancres[] = ['niveau'=>'entite', 'valeur_brute'=>$nomExt, 'entite_type'=>'TIERS'];
            } else {
                $entite = flux_champ(null, 'rouge', 'defaut', ['id'=>null,'type'=>null,'existante'=>false,'candidats'=>$rappro['candidats']]);
            }
        }

        /* ═══ SOCIÉTÉ/AGENCE — priorité 2 : document (si toujours non résolu) ═══ */
        if ($societe === null && !empty($extraction_ia['societe'])) {
            $found = flux_matcher_societe($pdo, (string)$extraction_ia['societe'], (string)($extraction_ia['siren'] ?? ''));
            if ($found) {
                $societe = flux_champ($found['nom'], 'vert', 'document', ['id'=>$found['id']]);
                if (empty($extraction_ia['siren']) && !$found['exact']) {
                    $societe['confiance'] = 'jaune';
                }
            }
        }

        /* ═══ SOCIÉTÉ/AGENCE — priorité 4 : compte connecté (JAUNE max — I1) ═══ */
        if ($societe === null) {
            if (!empty($compte['societe_id'])) {
                $societe = flux_champ((string)($compte['societe_nom'] ?? $compte['societe_id']), 'jaune',
                    'contexte_user', ['id'=>(int)$compte['societe_id'], 'note'=>'supposé d\'après ton compte, à confirmer']);
            } else {
                $societe = flux_champ(null, 'rouge', 'defaut', ['id'=>null]);
            }
        }
        if ($agence === null) {
            if (!empty($compte['agence_id'])) {
                $agence = flux_champ((string)($compte['agence_nom'] ?? $compte['agence_id']), 'jaune',
                    'contexte_user', ['id'=>(int)$compte['agence_id'], 'note'=>'supposé d\'après ton compte, à confirmer']);
            } else {
                $agence = flux_champ(null, 'rouge', 'defaut', ['id'=>null]);
            }
        }

        /* ═══ TYPE_DOCUMENT ═══ priorité : explicite > document > nom_fichier > defaut(AUTRE) */
        $type = null;
        if (!empty($explicite['type_document'])) {
            $code = flux_mapper_type_document((string)$explicite['type_document']);
            $type = flux_champ($code ?: (string)$explicite['type_document'], 'vert', 'contexte_explicite');
        }
        if ($type === null) {
            $codeDoc = flux_mapper_type_document((string)($extraction_ia['type_document'] ?? $extraction_ia['type_doc'] ?? ''));
            if ($codeDoc !== '') $type = flux_champ($codeDoc, 'vert', 'document');
        }
        if ($type === null && $fileName !== '') {
            $codeFile = flux_mapper_type_document($fileName);
            if ($codeFile !== '') {
                $type = flux_champ($codeFile, 'jaune', 'nom_fichier');
                $ancres[] = ['niveau'=>'type', 'valeur_brute'=>$fileName, 'entite_type'=>null];
            }
        }
        if ($type === null) $type = flux_champ('AUTRE', 'jaune', 'defaut');

        /* ═══ MÉTIER / DOMAINE ═══ priorité : explicite > document > (type d'entité) > defaut */
        $metier = flux_resoudre_simple($explicite['metier'] ?? null, $extraction_ia['metier'] ?? null, null);
        $domaine = flux_resoudre_simple($explicite['domaine'] ?? null, $extraction_ia['domaine'] ?? null, null);

        /* ═══ CHEMIN « QUOI » + NOM FICHIER ═══ via le moteur V3.1 existant (inchangé) */
        $cheminCode = ''; $libelleHumain = (string)($type['valeur'] ?? ''); $nomFichier = $fileName;
        $n1 = $n2 = $n3 = '';
        if ($carte_id > 0 && function_exists('fluxbox_va_compute_v3_1_name')) {
            try {
                $v3 = fluxbox_va_compute_v3_1_name($carte_id, $pdo);
                if (!empty($v3['name'])) $nomFichier = $v3['name'];
                $n1 = (string)($v3['n1'] ?? '');
                $n2 = (string)($v3['ctx']['n2_slug'] ?? '');
                $n3 = (string)($v3['ctx']['n3_slug'] ?? '');
                $cheminCode = trim(implode(' > ', array_filter([$n1,$n2,$n3])), ' >');
                if (($metier['valeur'] ?? null) === null && $n1 !== '') {
                    $metier = flux_champ(strtoupper(preg_replace('/^\d+_/','',$n1)), 'jaune', 'document');
                }
            } catch (Throwable) {}
        }
        // Métier inféré du type d'entité (QUI) si toujours rien
        if (($metier['valeur'] ?? null) === null && $metierFromEntite !== null) {
            $metier = flux_champ($metierFromEntite, 'jaune', 'fiche');
        }
        if (($metier['valeur'] ?? null) === null) $metier = flux_champ(null, 'rouge', 'defaut');
        if (($domaine['valeur'] ?? null) === null) $domaine = flux_champ(null, 'jaune', 'defaut');

        /* ═══ CHEMIN HUMAIN = QUI + QUOI (Correctif 7/8) ═══ */
        $chemin = flux_construire_chemin_humain($societe, $agence, $entite, $metier, $domaine, $type, $n2, $n3);

        /* ═══ BLOQUANT (Invariant I5) ═══ */
        $bloquant = false;
        foreach (['societe'=>$societe,'agence'=>$agence,'metier'=>$metier] as $f) {
            if (($f['confiance'] ?? '') === 'rouge') $bloquant = true;
        }
        // Société/agence jaune NON confirmée → bloquant (vert via fiche/lot ne bloque pas)
        if (($societe['confiance'] ?? '') === 'jaune' && empty($confirme['societe'])) $bloquant = true;
        if (($agence['confiance'] ?? '') === 'jaune' && empty($confirme['agence']))  $bloquant = true;

        return [
            'type_document'  => $type,
            'societe'        => $societe,
            'agence'         => $agence,
            'metier'         => $metier,
            'domaine'        => $domaine,
            'entite'         => $entite,
            'chemin'         => $chemin,       // fil d'Ariane lisible (QUI › QUOI)
            'chemin_code'    => $cheminCode,   // slugs GED (n1 > n2 > n3)
            'libelle_humain' => $libelleHumain,
            'nom_fichier'    => $nomFichier,
            'lot_id'         => is_array($contexte_lot) ? ($contexte_lot['lot_id'] ?? null) : null,
            'bloquant'       => $bloquant,
            '_ancres'        => $ancres, // à persister via fluxbox_persister_ancres()
        ];
    }
}

if (!function_exists('flux_construire_chemin_humain')) {
    /** Fil d'Ariane lisible : QUI (société › agence › entité) + QUOI (métier › domaine › type). */
    function flux_construire_chemin_humain(array $societe, array $agence, array $entite, array $metier, array $domaine, array $type, string $n2='', string $n3=''): string {
        $seg = [];
        if (!empty($societe['valeur'])) $seg[] = (string)$societe['valeur'];
        if (!empty($agence['valeur']))  $seg[] = (string)$agence['valeur'];
        if (!empty($entite['valeur']))  $seg[] = (string)$entite['valeur'];
        // QUOI : préférer métier + libellés n2/n3 lisibles, sinon type
        $quoi = [];
        if (!empty($metier['valeur']))  $quoi[] = flux_humaniser_slug((string)$metier['valeur']);
        if ($n2 !== '') $quoi[] = flux_humaniser_slug($n2);
        elseif (!empty($domaine['valeur'])) $quoi[] = flux_humaniser_slug((string)$domaine['valeur']);
        if ($n3 !== '') $quoi[] = flux_humaniser_slug($n3);
        if (empty($quoi) && !empty($type['valeur'])) $quoi[] = flux_humaniser_slug((string)$type['valeur']);
        return trim(implode(' › ', array_merge($seg, $quoi)), ' ›');
    }
}

if (!function_exists('flux_humaniser_slug')) {
    /** '05_gestion_locative' → 'Gestion locative' ; 'BAIL' → 'Bail'. */
    function flux_humaniser_slug(string $s): string {
        $s = preg_replace('/^\d+[_\-]/', '', $s);
        $s = str_replace(['_','-'], ' ', (string)$s);
        $s = trim((string)$s);
        return $s === '' ? '' : (mb_strtoupper(mb_substr($s,0,1)) . mb_strtolower(mb_substr($s,1)));
    }
}

if (!function_exists('flux_charger_libelle')) {
    /** Libellé court d'une société/agence par id. */
    function flux_charger_libelle(PDO $pdo, string $table, int $id): string {
        if ($id <= 0) return '';
        $col = $table === 'agences' ? 'nom_agence' : 'nom';
        try { $st=$pdo->prepare("SELECT {$col} FROM {$table} WHERE id=? LIMIT 1"); $st->execute([$id]); return (string)$st->fetchColumn(); }
        catch (Throwable) { return ''; }
    }
}

if (!function_exists('flux_resoudre_simple')) {
    /** Résout un champ simple (texte) : explicite(vert) > document(vert) > null. */
    function flux_resoudre_simple($explicite, $document, $defaut): array {
        if ($explicite !== null && $explicite !== '') return flux_champ((string)$explicite, 'vert', 'contexte_explicite');
        if ($document  !== null && $document  !== '') return flux_champ((string)$document, 'vert', 'document');
        if ($defaut    !== null && $defaut    !== '') return flux_champ((string)$defaut, 'jaune', 'defaut');
        return ['valeur'=>null,'confiance'=>null,'source'=>null];
    }
}

if (!function_exists('flux_matcher_societe')) {
    /** Matche une société par SIREN (exact) ou nom (LIKE). @return ?array{id,nom,exact} */
    function flux_matcher_societe(PDO $pdo, string $nom, string $siren): ?array {
        $siren = em_normalize_siret($siren);
        if ($siren !== '') {
            try {
                $st = $pdo->prepare("SELECT id, nom FROM societes WHERE REPLACE(siren,' ','')=? LIMIT 1");
                $st->execute([$siren]);
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['id'=>(int)$r['id'],'nom'=>(string)$r['nom'],'exact'=>true];
            } catch (Throwable) {}
        }
        $nomN = em_normalize_for_search($nom);
        if ($nomN !== '') {
            try {
                $st = $pdo->prepare("SELECT id, nom, raison_sociale FROM societes
                    WHERE LOWER(nom) LIKE ? OR LOWER(raison_sociale) LIKE ? LIMIT 1");
                $st->execute(['%'.$nomN.'%','%'.$nomN.'%']);
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['id'=>(int)$r['id'],'nom'=>(string)$r['nom'],'exact'=>false];
            } catch (Throwable) {}
        }
        return null;
    }
}

if (!function_exists('flux_charger_scope_entite')) {
    /**
     * Charge société/agence (id + nom) + label + métier inféré d'une entité.
     * Invariant 3 : c'est la SOURCE de vérité société/agence quand une fiche est choisie.
     * Accepte type en minuscule (bien|immeuble|tiers|user|societe) ou majuscule (BIEN|IMB|TIERS|USER|SOC).
     */
    function flux_charger_scope_entite(PDO $pdo, string $type, int $id): array {
        $out = ['societe_id'=>null,'societe_nom'=>'','agence_id'=>null,'agence_nom'=>'','label'=>'','metier'=>null];
        $t = strtolower($type);

        try {
            if ($t === 'bien') {
                // société/agence du bien, avec fallback immeuble ; label = designation/adresse
                $st = $pdo->prepare("SELECT b.id_societe, b.id_agence, b.designation, b.adresse_1, b.ville,
                        i.id_societe AS imm_soc, i.id_agence AS imm_age, i.adresse_1 AS imm_adr
                    FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ? LIMIT 1");
                $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['societe_id'] = (int)($r['id_societe'] ?? 0) ?: ((int)($r['imm_soc'] ?? 0) ?: null);
                $out['agence_id']  = (int)($r['id_agence']  ?? 0) ?: ((int)($r['imm_age'] ?? 0) ?: null);
                $out['label'] = (string)($r['designation'] ?: $r['adresse_1'] ?: $r['imm_adr'] ?: ('Bien #'.$id));
                // métier inféré : bail actif → gestion, sinon transaction si mandat vente (via helper existant)
                if (function_exists('fluxbox_va_detect_n1_from_context')) {
                    $d = fluxbox_va_detect_n1_from_context('BIEN', $id, $pdo);
                    $out['metier'] = strtoupper(preg_replace('/^\d+_/','', (string)($d['n1'] ?? '')));
                }
            } elseif ($t === 'immeuble' || $t === 'imb') {
                $st = $pdo->prepare("SELECT id_societe, id_agence, nom_immeuble, adresse_1 FROM immeubles WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['societe_id'] = !empty($r['id_societe']) ? (int)$r['id_societe'] : null;
                $out['agence_id']  = !empty($r['id_agence'])  ? (int)$r['id_agence']  : null;
                $out['label'] = (string)($r['nom_immeuble'] ?: $r['adresse_1'] ?: ('Immeuble #'.$id));
                $out['metier'] = 'SYNDIC';
            } elseif ($t === 'user' || $t === 'emp') {
                $st = $pdo->prepare("SELECT id_societe, id_agence, prenom, nom FROM users WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['societe_id'] = !empty($r['id_societe']) ? (int)$r['id_societe'] : null;
                $out['agence_id']  = !empty($r['id_agence'])  ? (int)$r['id_agence']  : null;
                $out['label'] = trim((string)($r['prenom'] ?? '').' '.(string)($r['nom'] ?? ''));
                $out['metier'] = 'RH';
            } elseif ($t === 'societe' || $t === 'soc') {
                $st = $pdo->prepare("SELECT nom FROM societes WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $out['societe_id'] = $id;
                $out['label'] = (string)($st->fetchColumn() ?: ('Société #'.$id));
            } else { // tiers (défaut)
                $st = $pdo->prepare("SELECT id_societe, id_agence, nom_affichage, nom, prenom, raison_sociale FROM tiers WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['societe_id'] = !empty($r['id_societe']) ? (int)$r['id_societe'] : null;
                $out['agence_id']  = !empty($r['id_agence'])  ? (int)$r['id_agence']  : null;
                $out['label'] = (string)($r['nom_affichage'] ?: trim(($r['prenom']??'').' '.($r['nom']??'')) ?: $r['raison_sociale'] ?: ('Tiers #'.$id));
            }
        } catch (Throwable) {}

        if ($out['societe_id']) $out['societe_nom'] = flux_charger_libelle($pdo, 'societes', (int)$out['societe_id']);
        if ($out['agence_id'])  $out['agence_nom']  = flux_charger_libelle($pdo, 'agences', (int)$out['agence_id']);
        return $out;
    }
}

if (!function_exists('fluxbox_persister_ancres')) {
    /** Persiste les ancres non résolues (Correctif 2) pour rattachement rétroactif. */
    function fluxbox_persister_ancres(PDO $pdo, int $tenantId, int $carteId, array $ancres): void {
        if (empty($ancres)) return;
        try {
            $st = $pdo->prepare("INSERT INTO fluxbox_ancres
                (tenant_id, carte_id, niveau, valeur_brute, entite_type, statut)
                VALUES (?,?,?,?,?,'en_attente')");
            foreach ($ancres as $a) {
                $st->execute([$tenantId, $carteId, (string)$a['niveau'],
                    (string)$a['valeur_brute'], $a['entite_type'] ?? null]);
            }
        } catch (Throwable) {}
    }
}

if (!function_exists('fluxbox_resoudre_carte')) {
    /**
     * Wrapper : assemble contexte_user (session) + extraction_ia (JSON stockés sur la
     * carte / ia_extract_cache) et appelle fluxbox_resoudre_contexte() pour une carte.
     * Ne persiste rien (lecture seule) — l'appelant décide.
     */
    function fluxbox_resoudre_carte(int $carteId, PDO $pdo, array $explicite = []): array {
        // 1. Carte + doc
        $st = $pdo->prepare("SELECT c.*, d.fichier_nom, d.hash_sha256
            FROM fluxbox_cartes c LEFT JOIN fluxbox_documents d ON d.id = c.document_id
            WHERE c.id = ? LIMIT 1");
        $st->execute([$carteId]);
        $carte = $st->fetch(PDO::FETCH_ASSOC);
        if (!$carte) return [];

        // 2. Contexte compte (utilisateur connecté = dernier recours, jaune)
        $userId = (int)($carte['created_by'] ?? ($_SESSION['user_id'] ?? 0));
        $compte = ['user_id'=>$userId];
        if ($userId) {
            try {
                $stU = $pdo->prepare("SELECT u.id_societe, u.id_agence, s.nom AS soc_nom, a.nom_agence AS age_nom
                    FROM users u LEFT JOIN societes s ON s.id=u.id_societe
                    LEFT JOIN agences a ON a.id=u.id_agence WHERE u.id=? LIMIT 1");
                $stU->execute([$userId]);
                if ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
                    $compte['societe_id']  = $u['id_societe'] ? (int)$u['id_societe'] : null;
                    $compte['societe_nom'] = (string)($u['soc_nom'] ?? '');
                    $compte['agence_id']   = $u['id_agence'] ? (int)$u['id_agence'] : null;
                    $compte['agence_nom']  = (string)($u['age_nom'] ?? '');
                }
            } catch (Throwable) {}
        }

        // 3. Extraction IA : naming_extracted_json + proposition_json + ia_extract_cache
        $ext = [];
        if (!empty($carte['naming_extracted_json'])) $ext = json_decode((string)$carte['naming_extracted_json'], true) ?: [];
        if (!empty($carte['hash_sha256'])) {
            try {
                $stC = $pdo->prepare("SELECT response_json FROM ia_extract_cache WHERE hash_sha256=? ORDER BY last_at DESC LIMIT 1");
                $stC->execute([(string)$carte['hash_sha256']]);
                $cache = json_decode((string)$stC->fetchColumn(), true) ?: [];
                $ext = array_merge($cache, $ext);
            } catch (Throwable) {}
        }
        // Mappe quelques clés courantes vers le vocabulaire de la résolution
        $extraction_ia = [
            '__filename'     => (string)($carte['fichier_nom'] ?? ''),
            'societe'        => (string)($ext['societe'] ?? $ext['raison_sociale'] ?? ''),
            'siren'          => (string)($ext['siren'] ?? ''),
            'metier'         => (string)($ext['metier'] ?? ''),
            'domaine'        => (string)($ext['domaine'] ?? ''),
            'type_doc'       => (string)($ext['type_doc'] ?? $ext['type_document'] ?? ''),
            'entite_nom'     => (string)($ext['proprietaire'] ?? $ext['locataire'] ?? $ext['personne'] ?? $ext['entite_nom'] ?? ''),
            'entite_siren'   => (string)($ext['entite_siren'] ?? ''),
            'entite_adresse' => (string)($ext['adresse_bien'] ?? $ext['adresse'] ?? ''),
            'entite_cp'      => (string)($ext['code_postal'] ?? ''),
            'entite_ville'   => (string)($ext['ville'] ?? ''),
        ];

        // 4. Contexte de lot (Correctif 6) si la carte est rattachée à un lot
        $contexteLot = null;
        if (!empty($carte['lot_id'])) {
            try {
                $stL = $pdo->prepare("SELECT * FROM fluxbox_lots WHERE id = ? LIMIT 1");
                $stL->execute([(int)$carte['lot_id']]);
                if ($lot = $stL->fetch(PDO::FETCH_ASSOC)) {
                    $contexteLot = [
                        'lot_id'     => (int)$lot['id'],
                        'societe_id' => $lot['societe_id'] ? (int)$lot['societe_id'] : null,
                        'agence_id'  => $lot['agence_id']  ? (int)$lot['agence_id']  : null,
                        'source'     => (string)$lot['source'],
                    ];
                    if (!empty($lot['entite_id'])) {
                        $contexteLot['entite_verrouillee'] = [
                            'type'   => (string)$lot['entite_type'],
                            'id'     => (int)$lot['entite_id'],
                            'valeur' => (string)$lot['entite_label'],
                        ];
                    }
                }
            } catch (Throwable) {}
        }

        return fluxbox_resoudre_contexte($carteId, ['explicite'=>$explicite, 'compte'=>$compte], $extraction_ia, $contexteLot, $pdo);
    }
}

if (!function_exists('fluxbox_render_proposition_inline')) {
    /**
     * Correctif 5 — rend la proposition complète inline, champ par champ, avec
     * pastille vert/jaune/rouge + source. Boutons de confirmation = cascade (pas de select).
     * Le bouton "Valider et classer" n'est actif que si !bloquant.
     *
     * @return string HTML (CSS + markup auto-portés).
     */
    function fluxbox_render_proposition_inline(array $r, int $carteId): string {
        if (empty($r)) return '';
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $pastille = [
            'vert'  => ['#2d6a35', '#d9f0db', '🟢', 'sûr'],
            'jaune' => ['#92400e', '#fef3c7', '🟡', 'à confirmer'],
            'rouge' => ['#991b1b', '#fee2e2', '🔴', 'manquant'],
        ];
        $srcLabel = [
            'contexte_explicite' => 'choix explicite',
            'document'           => 'contenu du document',
            'fiche'              => 'fiche entité',
            'rapprochement'      => 'fiche existante',
            'nom_fichier'        => 'nom du fichier',
            'lot'                => 'lot (dossier)',
            'contexte_user'      => 'ton compte (supposé)',
            'defaut'             => 'défaut',
        ];
        $champs = [
            'societe'       => '🏢 Société',
            'agence'        => '🏬 Agence',
            'entite'        => '👤 Entité',
            'type_document' => '🏷️ Type de document',
            'metier'        => '💼 Métier',
            'domaine'       => '📂 Domaine',
        ];

        $rows = '';
        foreach ($champs as $key => $libelle) {
            $f = $r[$key] ?? null;
            if (!is_array($f)) continue;
            $conf = (string)($f['confiance'] ?? 'rouge');
            [$fg,$bg,$ico,$txt] = $pastille[$conf] ?? $pastille['rouge'];
            $val = $f['valeur'] ?? null;
            $valTxt = ($val === null || $val === '') ? '—' : (string)$val;
            $src = $srcLabel[$f['source'] ?? 'defaut'] ?? (string)($f['source'] ?? '');

            // Confirmation cascade pour société/agence jaune
            $confirmBtn = '';
            if (in_array($key, ['societe','agence'], true) && $conf === 'jaune') {
                $confirmBtn = '<button type="button" class="fbx-reso-confirm" data-field="'.$h($key).'">✓ Confirmer</button>';
            }
            // Candidats alternatifs (entité) — cascade
            $cands = '';
            if ($key === 'entite' && !empty($f['candidats'])) {
                $items = '';
                foreach (array_slice($f['candidats'], 0, 4) as $c) {
                    $items .= '<button type="button" class="fbx-reso-cand" data-id="'.(int)$c['id'].'" data-type="'.$h($c['type']).'">'
                            . $h($c['label']).' <em>('.(int)$c['score'].'%)</em></button>';
                }
                $cands = '<div class="fbx-reso-cands">'.$items.'</div>';
            }
            $existant = '';
            if ($key === 'entite') {
                $typeBadge = !empty($f['type']) ? ' <span class="fbx-reso-badge">'.$h(ucfirst((string)$f['type'])).'</span>' : '';
                $existant = $typeBadge . (!empty($f['existante'])
                    ? ' <span class="fbx-reso-tag">fiche #'.(int)($f['id'] ?? 0).'</span>'
                    : ' <span class="fbx-reso-tag fbx-reso-new">nouvelle</span>');
                // Bouton "Changer" → ouvre la recherche universelle (Correctif 7)
                $existant .= ' <button type="button" class="fbx-reso-change">🔍 Changer l\'entité</button>';
            }

            $rows .= '<div class="fbx-reso-row" data-field="'.$h($key).'" data-conf="'.$h($conf).'">'
                . '<div class="fbx-reso-lbl">'.$h($libelle).'</div>'
                . '<div class="fbx-reso-val" style="color:'.$fg.'">'.$ico.' <strong>'.$h($valTxt).'</strong>'.$existant.'</div>'
                . '<div class="fbx-reso-src" style="background:'.$bg.';color:'.$fg.'">'.$h($txt).' · '.$h($src).'</div>'
                . $confirmBtn
                . $cands
                . '</div>';
        }

        $bloquant = !empty($r['bloquant']);
        $chemin = (string)($r['chemin'] ?? '');
        $cheminCode = (string)($r['chemin_code'] ?? '');
        $nom    = (string)($r['nom_fichier'] ?? '');
        $lotId  = $r['lot_id'] ?? null;
        $ent    = $r['entite'] ?? [];
        $lotHeader = '';
        if (!empty($lotId)) {
            $lotHeader = '<div class="fbx-reso-lot">📦 <strong>Lot</strong> #'.(int)$lotId
                . ' — entité/société/agence héritées du dossier · <em>identiques pour tous les fichiers</em>'
                . ' <button type="button" class="fbx-reso-confirm" data-field="lot">✓ Confirmer le lot</button></div>';
        }

        ob_start(); ?>
<div class="fbx-reso" id="fbx-reso-<?= (int)$carteId ?>" data-carte="<?= (int)$carteId ?>" data-bloquant="<?= $bloquant ? '1':'0' ?>">
  <style>
    .fbx-reso{border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin:12px 0;background:#fff;}
    .fbx-reso-head{font-weight:800;color:#243B5C;font-size:13px;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
    .fbx-reso-lot{background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:#3730a3;}
    .fbx-reso-row{display:grid;grid-template-columns:130px 1fr auto auto;gap:8px;align-items:center;padding:6px 0;border-bottom:1px dashed #eef0f3;}
    .fbx-reso-lbl{font-size:12px;font-weight:600;color:#475569;}
    .fbx-reso-val{font-size:13px;min-width:0;}
    .fbx-reso-src{font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;white-space:nowrap;}
    .fbx-reso-tag{font-size:10px;color:#64748b;background:#f1f5f9;padding:1px 6px;border-radius:6px;}
    .fbx-reso-badge{font-size:10px;font-weight:700;color:#0e7490;background:#ecfeff;border:1px solid #a5f3fc;padding:1px 6px;border-radius:6px;}
    .fbx-reso-new{color:#92400e;background:#fef3c7;}
    .fbx-reso-confirm{font-size:11px;font-weight:700;border:1px solid #d4a047;background:#fff7e6;color:#92400e;border-radius:8px;padding:3px 10px;cursor:pointer;}
    .fbx-reso-confirm.is-done{background:#d9f0db;border-color:#2d6a35;color:#2d6a35;}
    .fbx-reso-change{font-size:10.5px;font-weight:600;border:1px solid #cbd5e1;background:#f8fafc;color:#475569;border-radius:7px;padding:2px 8px;cursor:pointer;margin-left:4px;}
    .fbx-reso-cands{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;}
    .fbx-reso-cand{font-size:11px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:8px;padding:3px 9px;cursor:pointer;}
    .fbx-reso-cand:hover{border-color:#0e7490;background:#ecfeff;}
    .fbx-reso-search{grid-column:1/-1;margin-top:8px;display:none;}
    .fbx-reso-search.is-open{display:block;}
    .fbx-reso-search input{width:100%;height:34px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;outline:none;}
    .fbx-reso-search input:focus{border-color:#0e7490;box-shadow:0 0 0 2px rgba(14,116,144,.15);}
    .fbx-reso-results{display:flex;flex-direction:column;gap:4px;margin-top:6px;max-height:240px;overflow:auto;}
    .fbx-reso-res{display:flex;flex-direction:column;align-items:flex-start;gap:1px;border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;text-align:left;}
    .fbx-reso-res:hover{border-color:#0e7490;background:#f0fdff;}
    .fbx-reso-res .ttl{font-size:12.5px;font-weight:700;color:#243B5C;}
    .fbx-reso-res .b{font-size:9.5px;font-weight:800;color:#0e7490;background:#ecfeff;border-radius:5px;padding:0 5px;margin-right:6px;}
    .fbx-reso-res .rp{font-size:11px;color:#64748b;}
    .fbx-reso-dest{margin-top:12px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;}
    .fbx-reso-dest .ar{font-size:12.5px;color:#243B5C;font-weight:600;}
    .fbx-reso-dest .nm{margin-top:5px;font-family:'DM Mono',monospace;font-size:10.5px;color:#334155;}
    .fbx-reso-dest .cc{margin-top:3px;font-size:10px;color:#94a3b8;}
    .fbx-reso-block{margin-top:8px;font-size:12px;font-weight:700;color:#991b1b;}
    .fbx-reso-block.ok{color:#2d6a35;}
  </style>
  <div class="fbx-reso-head">🎯 Proposition de classement — vérifiez champ par champ</div>
  <?= $lotHeader ?>
  <?= $rows ?>

  <!-- Correctif 8 — aperçu destination AVANT validation -->
  <div class="fbx-reso-dest">
    <div class="ar" id="fbx-reso-chemin-<?= (int)$carteId ?>">📁 <?= $h($chemin ?: '—') ?></div>
    <div class="nm">📋 <span id="fbx-reso-nom-<?= (int)$carteId ?>"><?= $h($nom) ?></span></div>
    <div class="cc">🗂️ <span id="fbx-reso-cc-<?= (int)$carteId ?>"><?= $h($cheminCode) ?></span></div>
  </div>

  <div class="fbx-reso-block <?= $bloquant ? '' : 'ok' ?>" id="fbx-reso-status-<?= (int)$carteId ?>">
    <?= $bloquant ? '⚠️ Confirmez les champs jaunes (société/agence) avant de valider.' : '✅ Prêt à classer.' ?>
  </div>

  <script>
  (function(){
    var CARTE = <?= (int)$carteId ?>;
    var root = document.getElementById('fbx-reso-'+CARTE);
    if(!root) return;
    var PAS = {vert:['#2d6a35','#d9f0db','🟢','sûr'],jaune:['#92400e','#fef3c7','🟡','à confirmer'],rouge:['#991b1b','#fee2e2','🔴','manquant']};
    var SRC = {contexte_explicite:'choix explicite',document:'contenu du document',fiche:'fiche entité',rapprochement:'fiche existante',nom_fichier:'nom du fichier',lot:'lot (dossier)',contexte_user:'ton compte (supposé)',defaut:'défaut'};
    var confirmed = {societe:false, agence:false};

    function statusEl(){ return document.getElementById('fbx-reso-status-'+CARTE); }
    function refresh(){
      var pending = root.querySelectorAll('.fbx-reso-row[data-field="societe"][data-conf="jaune"] .fbx-reso-confirm:not(.is-done),'
        + '.fbx-reso-row[data-field="agence"][data-conf="jaune"] .fbx-reso-confirm:not(.is-done)');
      var hasRed = root.querySelector('.fbx-reso-row[data-conf="rouge"][data-field="societe"],'
        + '.fbx-reso-row[data-conf="rouge"][data-field="agence"],.fbx-reso-row[data-conf="rouge"][data-field="metier"]');
      var blocked = pending.length>0 || !!hasRed;
      root.dataset.bloquant = blocked ? '1':'0';
      var st = statusEl();
      if(st){ st.className='fbx-reso-block '+(blocked?'':'ok');
        st.textContent = blocked ? '⚠️ Confirmez les champs jaunes (société/agence) avant de valider.' : '✅ Prêt à classer.'; }
      var vbtn = document.querySelector('.fbx-card-shell[data-carte-id="'+CARTE+'"] [data-action="validate"]') || document.querySelector('[data-action="validate"]');
      if(vbtn){ vbtn.disabled = blocked; vbtn.style.opacity = blocked?'0.5':'1'; vbtn.style.cursor = blocked?'not-allowed':'pointer'; }
    }
    function bindConfirm(b){
      b.addEventListener('click', function(){
        b.classList.add('is-done'); b.textContent='✓ Confirmé';
        var fld = b.getAttribute('data-field'); if(fld) confirmed[fld]=true;
        refresh();
      });
    }
    root.querySelectorAll('.fbx-reso-confirm').forEach(bindConfirm);

    // — Met à jour l'affichage d'un champ depuis la résolution renvoyée —
    function patchField(key, f){
      var row = root.querySelector('.fbx-reso-row[data-field="'+key+'"]'); if(!row||!f) return;
      var p = PAS[f.confiance]||PAS.rouge;
      row.dataset.conf = f.confiance;
      var val = row.querySelector('.fbx-reso-val');
      if(val){ val.style.color=p[0]; val.innerHTML = p[2]+' <strong>'+(f.valeur||'—')+'</strong>'
        + (key==='entite' && f.type ? ' <span class="fbx-reso-badge">'+f.type+'</span>' : ''); }
      var src = row.querySelector('.fbx-reso-src');
      if(src){ src.style.background=p[1]; src.style.color=p[0]; src.textContent=p[3]+' · '+(SRC[f.source]||f.source||''); }
    }
    function applyResolution(res){
      ['societe','agence','entite','type_document','metier','domaine'].forEach(function(k){ patchField(k,res[k]); });
      var c=document.getElementById('fbx-reso-chemin-'+CARTE); if(c) c.textContent='📁 '+(res.chemin||'—');
      var n=document.getElementById('fbx-reso-nom-'+CARTE); if(n) n.textContent=res.nom_fichier||'';
      var cc=document.getElementById('fbx-reso-cc-'+CARTE); if(cc) cc.textContent=res.chemin_code||'';
      // entité résolue via fiche → société/agence repassent vert : retire les boutons confirmer obsolètes
      ['societe','agence'].forEach(function(k){
        var row=root.querySelector('.fbx-reso-row[data-field="'+k+'"]');
        if(row && res[k] && res[k].confiance==='vert'){ var cb=row.querySelector('.fbx-reso-confirm'); if(cb) cb.remove(); }
      });
      refresh();
    }

    // — Correctif 7/8 : recherche universelle + choix entité → resolve live —
    function resolveWith(payload){
      payload.carte_id = CARTE;
      payload.confirme_societe = confirmed.societe?1:0;
      payload.confirme_agence  = confirmed.agence?1:0;
      fetch('/api/fluxbox_resolve.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)})
        .then(function(r){return r.json();})
        .then(function(d){ if(d && d.ok && d.resolution) applyResolution(d.resolution); })
        .catch(function(){});
    }
    // candidats rapprochés
    root.querySelectorAll('.fbx-reso-cand').forEach(function(b){
      b.addEventListener('click', function(){
        resolveWith({entite_id:b.getAttribute('data-id'), entite_type:b.getAttribute('data-type'), entite_nom:b.textContent.replace(/\s*\(\d+%\)\s*$/,'')});
      });
    });
    // bouton "Changer l'entité" → champ de recherche universel
    var changeBtn = root.querySelector('.fbx-reso-change');
    if(changeBtn){
      var box = document.createElement('div');
      box.className='fbx-reso-search';
      box.innerHTML='<input type="search" placeholder="🔍 Bien, immeuble, société, locataire, propriétaire, collaborateur…" autocomplete="off"><div class="fbx-reso-results"></div>';
      changeBtn.closest('.fbx-reso-row').appendChild(box);
      var input=box.querySelector('input'), out=box.querySelector('.fbx-reso-results'), tmr=null;
      changeBtn.addEventListener('click', function(){ box.classList.toggle('is-open'); if(box.classList.contains('is-open')) input.focus(); });
      input.addEventListener('input', function(){
        clearTimeout(tmr); var q=input.value.trim(); if(q.length<2){ out.innerHTML=''; return; }
        tmr=setTimeout(function(){
          fetch('/api/fluxbox_entity_search.php?q='+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(d){
            out.innerHTML='';
            (d.results||[]).forEach(function(it){
              var el=document.createElement('button'); el.type='button'; el.className='fbx-reso-res';
              el.innerHTML='<div class="ttl"><span class="b">'+it.badge+'</span>'+it.label+'</div>'
                + (it.repere1?'<div class="rp">'+it.repere1+'</div>':'')
                + (it.repere2?'<div class="rp">'+it.repere2+'</div>':'');
              el.addEventListener('click', function(){
                box.classList.remove('is-open');
                resolveWith({entite_id:it.id, entite_type:it.entity_type, entite_nom:it.label});
              });
              out.appendChild(el);
            });
          }).catch(function(){});
        },220);
      });
    }
    refresh();
  })();
  </script>
</div>
<?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('fluxbox_persister_resolution')) {
    /** Persiste le retour du contrat sur la carte (resolution_json + type_document). */
    function fluxbox_persister_resolution(PDO $pdo, int $carteId, array $resolution): void {
        try {
            $st = $pdo->prepare("UPDATE fluxbox_cartes
                SET resolution_json = :json, type_document = :type WHERE id = :id");
            $st->execute([
                ':json' => json_encode($resolution, JSON_UNESCAPED_UNICODE),
                ':type' => (string)($resolution['type_document']['valeur'] ?? null) ?: null,
                ':id'   => $carteId,
            ]);
        } catch (Throwable) {}
    }
}
