<?php
declare(strict_types=1);

/**
 * inc/entity_matcher.php — Moteur central d'Identity Resolution MaBoxImmo
 * ─────────────────────────────────────────────────────────────────────────
 *
 * SOURCE UNIQUE de matching pour toutes les entités métier :
 *   - Tiers (propriétaires, locataires, copropriétaires, agents, fournisseurs)
 *   - Immeubles
 *   - (Biens : reste pour l'instant dans transaction_doc_match.php — voir TODO Sprint 2)
 *
 * Pourquoi un moteur central :
 *   - Avant : 4 algorithmes de matching dans bailleur_check_duplicate.php +
 *     bien_check_duplicate.php + transaction_doc_match.php + admin_tiers_merge.php
 *     → divergence dans le temps, doublons silencieux.
 *   - Après : 1 seule source de vérité, appelée partout.
 *
 * Doctrine archi MaBoxImmo (figée 2026-04-19) :
 *   - Table maître = `tiers` (avec `tiers_roles` + `tiers_contacts`).
 *   - `proprietaires` est legacy (en cours de migration vers `tiers`).
 *   - Le ROLE (propriétaire/locataire/copro/…) est une étiquette dans
 *     tiers_roles.role_code, pas une table séparée.
 *   - Donc UNE fonction matchTiers($data, $role_code) — pas 3.
 *
 * Score de confiance standardisé :
 *   - >= AUTO       (90)  → rattachement pré-coché dans l'UI, user décoche s'il refuse
 *   - >= VALIDATE   (60)  → validation manuelle obligatoire avant rattachement
 *   - >= CREATE_OK  (60)  → suggestion affichée mais création autorisée si user confirme
 *   - <  CREATE_OK        → création autorisée (matches affichés en bas si présents)
 *
 * RÈGLE ABSOLUE :
 *   - Le moteur NE CRÉE JAMAIS d'entité. Il PROPOSE.
 *   - La décision finale appartient TOUJOURS à l'utilisateur (ou à un workflow
 *     explicitement « auto » à seuil >= AUTO).
 *
 * Feature flag :
 *   - define('FEATURE_ENTITY_MATCHER', true) dans bootstrap pour activer.
 *   - Off par défaut → les fichiers legacy continuent d'utiliser leur propre
 *     logique tant que Sprint 2 n'a pas migré chaque appelant.
 */

if (!defined('ENTITY_MATCHER_AUTO'))        define('ENTITY_MATCHER_AUTO', 90);
if (!defined('ENTITY_MATCHER_VALIDATE'))    define('ENTITY_MATCHER_VALIDATE', 60);
if (!defined('ENTITY_MATCHER_CREATE_OK'))   define('ENTITY_MATCHER_CREATE_OK', 60);

// ═════════════════════════════════════════════════════════════════════════
// NORMALISATIONS — helpers réutilisables (équivalents canoniques)
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('em_strip_accents')) {
    /**
     * Strip accents déterministe via table de remplacement.
     * Ne dépend PAS de la fonction native iconv — sur Windows XAMPP elle retourne
     * "`a" au lieu de "a" pour le caractère "à" avec ASCII//TRANSLIT
     * (bug confirmé 2026-05-23). Table manuelle = comportement identique
     * Windows / Linux / Mac.
     */
    function em_strip_accents(string $s): string
    {
        static $map = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
            'ç'=>'c','Ç'=>'C',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'ñ'=>'n','Ñ'=>'N',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','œ'=>'oe',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Œ'=>'OE',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
            'ý'=>'y','ÿ'=>'y','Ý'=>'Y','Ÿ'=>'Y',
            'š'=>'s','Š'=>'S','ž'=>'z','Ž'=>'Z',
        ];
        return strtr($s, $map);
    }
}

if (!function_exists('em_normalize_text')) {
    /** Strip accents + lowercase + espaces collapsés. */
    function em_normalize_text(string $s): string
    {
        $s = em_strip_accents($s);
        $s = strtolower(trim($s));
        return (string)preg_replace('/\s+/', ' ', $s);
    }
}

if (!function_exists('em_normalize_name')) {
    /** Nom de personne ou raison sociale (strict, pour comparaison exacte) :
     *  norm texte + suppression ponctuation. À utiliser pour le scoring PHP.
     *  Ex : "MR & MME SABY" → "mr  mme saby"
     */
    function em_normalize_name(string $s): string
    {
        $s = em_normalize_text($s);
        return (string)preg_replace('/[^a-z0-9 \-]/', '', $s);
    }
}

if (!function_exists('em_normalize_for_search')) {
    /** Tolérant pour pré-filtre SQL LIKE : lower + accents, GARDE la ponctuation
     *  (&, ', ., …). Sinon "MR & MME SABY" en BDD ne matche pas un pattern
     *  normalisé qui aurait viré le "&".
     */
    function em_normalize_for_search(string $s): string
    {
        return em_normalize_text($s);
    }
}

if (!function_exists('em_strip_civilites')) {
    /** Strip des civilités/conjoints pour comparaison de famille/couple/indivision.
     *  "MR & MME SABY"          → "saby"
     *  "M. et Mme DUPONT"       → "dupont"
     *  "M et Mme DUPONT"        → "dupont"
     *  "Monsieur Pierre EMERY"  → "pierre emery"
     *  "SABY"                   → "saby"
     *
     * Étapes (ordre important) :
     *  1. Normalise (lower + accents)
     *  2. Retire les points et autres ponctuations (sauf espace et tiret)
     *     → "m." devient "m" donc le strip suivant marche
     *  3. Strip des civilités/conjoints (en tant que mots entiers)
     *  4. Collapse espaces
     */
    function em_strip_civilites(string $s): string
    {
        $s = em_normalize_text($s);
        // 1. Retire ponctuations (mais garde espace, tiret, & temporaire pour le strip suivant)
        $s = (string)preg_replace('/[^a-z0-9 \-&]/', ' ', $s);
        // 2. Strip civilités/conjoints
        $patterns = [
            '/\bmr\b/', '/\bm\b/', '/\bmme\b/', '/\bmlle\b/',
            '/\bmonsieur\b/', '/\bmadame\b/', '/\bmademoiselle\b/',
            '/\bet\b/', '/&/',
            '/\bconsorts\b/', '/\bindivision\b/', '/\bsucc\b/', '/\bsuccession\b/',
            '/\bayants?\s+droits?\b/', '/\bes\s+qualites?\b/',
        ];
        $s = (string)preg_replace($patterns, ' ', $s);
        // 3. Collapse espaces
        $s = (string)preg_replace('/\s+/', ' ', trim($s));
        return $s;
    }
}

if (!function_exists('em_normalize_phone')) {
    /** Garde les 9 derniers chiffres (FR : 06 12 34 56 78 → 612345678). */
    function em_normalize_phone(string $s): string
    {
        return substr((string)preg_replace('/[^0-9]/', '', $s), -9);
    }
}

if (!function_exists('em_normalize_email')) {
    function em_normalize_email(string $s): string
    {
        return strtolower(trim($s));
    }
}

if (!function_exists('em_normalize_siret')) {
    /** SIRET = 14 chiffres. SIREN = 9 premiers. */
    function em_normalize_siret(string $s): string
    {
        return (string)preg_replace('/[^0-9]/', '', $s);
    }
}

if (!function_exists('em_normalize_address')) {
    /**
     * Adresse normalisée pour matching :
     *  - strip accents, lowercase
     *  - expansion abréviations (av → avenue, blvd → boulevard, st → saint…)
     *  - tokens triés (pour comparer "100 rue X" et "rue X 100" comme proches)
     */
    function em_normalize_address(string $s): string
    {
        $s = em_normalize_text($s);
        // Suppression ponctuation
        $s = (string)preg_replace('/[,;:\.]/', ' ', $s);
        $s = (string)preg_replace('/\s+/', ' ', $s);

        // Table d'expansion abréviations FR
        $expansions = [
            '/\bav\b/'      => 'avenue',
            '/\bavn\b/'     => 'avenue',
            '/\bbd\b/'      => 'boulevard',
            '/\bblv\b/'     => 'boulevard',
            '/\bblvd\b/'    => 'boulevard',
            '/\brte\b/'     => 'route',
            '/\bch\b/'      => 'chemin',
            '/\bimp\b/'     => 'impasse',
            '/\bpl\b/'      => 'place',
            '/\bsq\b/'      => 'square',
            '/\bres\b/'     => 'residence',
            '/\bbat\b/'     => 'batiment',
            '/\besc\b/'     => 'escalier',
            '/\bst\b/'      => 'saint',
            '/\bste\b/'     => 'sainte',
            '/\bdr\b/'      => 'docteur',
            '/\bpr\b/'      => 'professeur',
            '/\bgal\b/'     => 'general',
            '/\bmal\b/'     => 'marechal',
            '/\bpdt\b/'     => 'president',
            '/\bmme\b/'     => '',
            '/\bm\b/'       => '',
            '/\bmr\b/'      => '',
        ];
        $s = (string)preg_replace(array_keys($expansions), array_values($expansions), ' ' . $s . ' ');
        $s = (string)preg_replace('/\s+/', ' ', trim($s));
        return $s;
    }
}

if (!function_exists('em_address_tokens')) {
    /** Découpe une adresse normalisée en tokens >= 2 chars, triés. */
    function em_address_tokens(string $normalizedAddress): array
    {
        $tokens = (array)preg_split('/\s+/', $normalizedAddress);
        $tokens = array_filter($tokens, static fn($t) => strlen((string)$t) >= 2);
        $tokens = array_values(array_unique($tokens));
        sort($tokens);
        return $tokens;
    }
}

if (!function_exists('em_extract_street_numbers')) {
    /**
     * Extrait TOUS les numéros présents dans une adresse, en distinguant
     * strictement les PLAGES (expand) et les LISTES (pas d'expand).
     *
     * PLAGES (expand 6→8 = [6,7,8]) :
     *   "6/8 rue Mermet"    → [6, 7, 8]
     *   "6-8 rue Mermet"    → [6, 7, 8]
     *   "6 à 8 rue Mermet"  → [6, 7, 8]   ("à" devient "a" après normalisation)
     *   "6 au 8 rue Mermet" → [6, 7, 8]
     *
     * LISTES (juste les 2 valeurs énumérées) :
     *   "6 et 8 rue Mermet" → [6, 8]
     *   "6, 8 rue Mermet"   → [6, 8]
     *   "6 & 8 rue Mermet"  → [6, 8]
     *
     * Numéro isolé :
     *   "6 rue Mermet"      → [6]
     *   "100 boulevard..."  → [100]
     *
     * Limite : plage max 20 unités (sinon traité comme 2 valeurs).
     */
    function em_extract_street_numbers(string $address): array
    {
        $address = ' ' . em_normalize_address($address) . ' ';
        $numbers = [];

        // Helper : expand une plage [start..end] avec garde-fou
        $expandRange = static function (int $start, int $end) use (&$numbers): void {
            if ($end >= $start && ($end - $start) <= 20) {
                for ($n = $start; $n <= $end; $n++) $numbers[] = $n;
            } else {
                $numbers[] = $start; $numbers[] = $end;
            }
        };
        // Helper : ajoute simplement les 2 valeurs (pas d'expansion)
        $addList = static function (int $a, int $b) use (&$numbers): void {
            $numbers[] = $a; $numbers[] = $b;
        };

        // ═══ PLAGES (séparateurs : / - " a " " au ") ═══
        // 1. "/" et "-"
        if (preg_match_all('/(\d+)\s*[\/\-]\s*(\d+)/', $address, $m)) {
            foreach ($m[1] as $i => $start) $expandRange((int)$start, (int)$m[2][$i]);
            $address = (string)preg_replace('/(\d+)\s*[\/\-]\s*(\d+)/', ' ', $address);
        }
        // 2. " a " (= " à " après em_strip_accents qui retire l'accent)
        //    On EXCLUT explicitement "et" — c'est une liste, pas une plage.
        if (preg_match_all('/(\d+)\s+a\s+(\d+)/', $address, $m)) {
            foreach ($m[1] as $i => $start) $expandRange((int)$start, (int)$m[2][$i]);
            $address = (string)preg_replace('/(\d+)\s+a\s+(\d+)/', ' ', $address);
        }
        // 3. " au "
        if (preg_match_all('/(\d+)\s+au\s+(\d+)/', $address, $m)) {
            foreach ($m[1] as $i => $start) $expandRange((int)$start, (int)$m[2][$i]);
            $address = (string)preg_replace('/(\d+)\s+au\s+(\d+)/', ' ', $address);
        }

        // ═══ LISTES (séparateurs : "et" "," "&") ═══
        // 4. " et " — uniquement les 2 valeurs, JAMAIS d'expand
        if (preg_match_all('/(\d+)\s+et\s+(\d+)/', $address, $m)) {
            foreach ($m[1] as $i => $start) $addList((int)$start, (int)$m[2][$i]);
            $address = (string)preg_replace('/(\d+)\s+et\s+(\d+)/', ' ', $address);
        }
        // 5. "," (avec ou sans espace)
        if (preg_match_all('/(\d+)\s*,\s*(\d+)/', $address, $m)) {
            foreach ($m[1] as $i => $start) $addList((int)$start, (int)$m[2][$i]);
            $address = (string)preg_replace('/(\d+)\s*,\s*(\d+)/', ' ', $address);
        }
        // 6. "&" (avec ou sans espace)
        if (preg_match_all('/(\d+)\s*&\s*(\d+)/', $address, $m)) {
            foreach ($m[1] as $i => $start) $addList((int)$start, (int)$m[2][$i]);
            $address = (string)preg_replace('/(\d+)\s*&\s*(\d+)/', ' ', $address);
        }

        // ═══ Numéros isolés restants ═══
        if (preg_match_all('/\b(\d{1,4})\b/', $address, $m)) {
            foreach ($m[1] as $n) $numbers[] = (int)$n;
        }

        $numbers = array_values(array_unique($numbers));
        sort($numbers, SORT_NUMERIC);
        return $numbers;
    }
}

if (!function_exists('em_normalize_street')) {
    /**
     * Extrait la rue SANS les numéros : tokens texte uniquement, triés.
     *   "6/8 rue Mermet"     → "mermet rue"
     *   "Avenue Victor Hugo" → "avenue hugo victor"
     */
    function em_normalize_street(string $address): string
    {
        $norm = em_normalize_address($address);
        // Retire les plages et numéros isolés
        $norm = (string)preg_replace('/\d+\s*[\/\-]\s*\d+/', ' ', $norm);
        $norm = (string)preg_replace('/\d+\s+(?:a|et)\s+\d+/', ' ', $norm);
        $norm = (string)preg_replace('/\b\d{1,4}\b/', ' ', $norm);
        $norm = (string)preg_replace('/\bbis\b|\bter\b|\bquater\b/', ' ', $norm);
        $norm = (string)preg_replace('/\s+/', ' ', trim($norm));
        $tokens = (array)preg_split('/\s+/', $norm);
        $tokens = array_filter($tokens, static fn($t) => strlen((string)$t) >= 2);
        $tokens = array_values(array_unique($tokens));
        sort($tokens);
        return implode(' ', $tokens);
    }
}

// ═════════════════════════════════════════════════════════════════════════
// MATCH TIERS — propriétaire / locataire / copro / agent / fournisseur
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('em_match_tiers')) {
    /**
     * Cherche les tiers existants qui pourraient être doublons d'un tiers candidat.
     *
     * @param PDO        $pdo
     * @param array      $data {
     *   @var string $nom
     *   @var string $prenom
     *   @var string $raison_sociale ou societe
     *   @var string $email
     *   @var string $telephone
     *   @var string $siret OU siren
     *   @var string $type_tiers 'personne_physique' | 'personne_morale' (optionnel)
     * }
     * @param string|null $role_code Optionnel : restreint aux tiers ayant ce role
     *                               (proprietaire, locataire, copropriétaire, etc.)
     * @param int|null    $exclude_id Optionnel : exclut un ID du résultat (édition)
     * @param int|null    $scope_societe_id Optionnel : filtre multi-tenant
     * @return array {
     *   found:        bool,
     *   best:         array|null { id, score, reasons, ... } — meilleur match
     *   matches:      array[]    — tous les matches >= minScore, triés desc
     *   confidence:   int        — score du meilleur match (0 si rien)
     *   match_type:   string     — 'siret' | 'email' | 'phone' | 'name' | 'unknown'
     *   needs_user_validation: bool — true si confidence < AUTO
     *   can_create:   bool       — true si confidence < CREATE_OK
     * }
     */
    function em_match_tiers(
        PDO $pdo,
        array $data,
        ?string $role_code = null,
        ?int $exclude_id = null,
        ?int $scope_societe_id = null
    ): array {
        $nom    = em_normalize_name((string)($data['nom']    ?? ''));
        $prenom = em_normalize_name((string)($data['prenom'] ?? ''));
        $raison = em_normalize_name((string)($data['raison_sociale'] ?? $data['societe'] ?? ''));
        $email  = em_normalize_email((string)($data['email'] ?? ''));
        $tel    = em_normalize_phone((string)($data['telephone'] ?? ''));
        $siret  = em_normalize_siret((string)($data['siret'] ?? $data['siren'] ?? ''));

        // Pré-checks : au moins un signal exploitable
        if ($nom === '' && $prenom === '' && $raison === '' && $email === '' && $tel === '' && $siret === '') {
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
        }

        // Vérifier table tiers présente
        $hasTiers = false;
        try {
            $hasTiers = (bool)$pdo->query("SHOW TABLES LIKE 'tiers'")->fetchColumn();
        } catch (Throwable) {}

        if (!$hasTiers) {
            // Fallback : table proprietaires legacy (Sprint 2 migrera tout)
            return em_match_tiers_legacy_proprietaires($pdo, $data, $exclude_id, $scope_societe_id);
        }

        // ─── Pré-filtre SQL large ────────────────────────────────────
        $where  = [];
        $params = [];

        if ($exclude_id) { $where[] = 't.id <> :exid'; $params[':exid'] = $exclude_id; }

        // Si role_code fourni, on restreint via tiers_roles
        $join = '';
        if ($role_code) {
            $join = 'INNER JOIN tiers_roles tr ON tr.id_tiers = t.id AND tr.role_code = :role AND tr.actif = 1';
            $params[':role'] = $role_code;
        }

        // Filtre OR sur les signaux fournis
        // Pré-filtre SQL : on utilise em_normalize_for_search (garde ponctuation)
        // au lieu de em_normalize_name (qui virait le "&"). Le scoring final reste
        // déterministe via em_normalize_name côté PHP.
        // On scanne AUSSI nom_affichage (souvent rempli pour les couples/indivisions)
        // ET la version "stripped civilités" pour reconnaître "MR & MME SABY" → "SABY".
        $or = [];
        $raisonSearch = $raison !== '' ? em_normalize_for_search((string)($data['raison_sociale'] ?? $data['societe'] ?? '')) : '';
        $nomSearch    = $nom    !== '' ? em_normalize_for_search((string)($data['nom']    ?? '')) : '';
        $stripCiv     = em_strip_civilites((string)($data['raison_sociale'] ?? $data['societe'] ?? $data['nom'] ?? ''));

        if ($email !== '')  { $or[] = 'LOWER(t.email) = :em';              $params[':em']  = $email; }
        if ($tel !== '')    { $or[] = "REGEXP_REPLACE(COALESCE(t.telephone, ''), '[^0-9]', '') LIKE :tl"; $params[':tl'] = '%' . $tel; }
        if ($siret !== '')  { $or[] = "(REGEXP_REPLACE(COALESCE(t.siret, ''), '[^0-9]', '') = :si OR REGEXP_REPLACE(COALESCE(t.siren, ''), '[^0-9]', '') = :sn)";
                              $params[':si'] = $siret; $params[':sn'] = substr($siret, 0, 9); }
        if ($raisonSearch !== '') {
            $or[] = '(LOWER(t.raison_sociale) LIKE :rs OR LOWER(t.nom_affichage) LIKE :rs2)';
            $params[':rs']  = '%' . $raisonSearch . '%';
            $params[':rs2'] = '%' . $raisonSearch . '%';
        }
        if ($nomSearch !== '') {
            $or[] = '(LOWER(t.nom) LIKE :nm OR LOWER(t.nom_affichage) LIKE :nm2)';
            $params[':nm']  = '%' . $nomSearch . '%';
            $params[':nm2'] = '%' . $nomSearch . '%';
        }
        // Fallback "stripped civilités" — pour matcher "MR & MME SABY" → tiers "SABY"
        // Seulement si distinct des autres patterns (évite redondance bruyante)
        if ($stripCiv !== '' && strlen($stripCiv) >= 3
            && $stripCiv !== $raisonSearch && $stripCiv !== $nomSearch) {
            $or[] = '(LOWER(t.nom) LIKE :sc OR LOWER(t.raison_sociale) LIKE :sc2 OR LOWER(t.nom_affichage) LIKE :sc3)';
            $params[':sc']  = '%' . $stripCiv . '%';
            $params[':sc2'] = '%' . $stripCiv . '%';
            $params[':sc3'] = '%' . $stripCiv . '%';
        }

        if (empty($or)) {
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
        }

        $where[] = '(' . implode(' OR ', $or) . ')';

        // sous_type pour reconnaître couple/indivision/sci/etc.
        $hasSousType = false;
        try { $hasSousType = (bool)$pdo->query("SHOW COLUMNS FROM tiers LIKE 'sous_type'")->fetchColumn(); } catch (Throwable) {}
        $selSousType = $hasSousType ? ', t.sous_type' : '';

        $sql = "SELECT t.id, t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale,
                       t.email, t.telephone, t.siret, t.siren, t.ville, t.actif, t.nom_affichage
                       {$selSousType}
                FROM tiers t
                {$join}
                WHERE " . implode(' AND ', $where) . "
                LIMIT 50";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[em_match_tiers] ' . $e->getMessage());
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true,
                    'error' => $e->getMessage()];
        }

        // ─── Scoring PHP — barème Sprint 1B (2026-05-23) ─────────────
        // Doctrine : un nom identique seul = signal MOYEN (60), pas faible.
        // Combiné avec autre signal (email/tel/role) → fort (75-85).
        // SIRET/SIREN = signal légal (95).
        // Création autorisée uniquement si confidence < CREATE_OK (60).
        $matches = [];
        foreach ($candidates as $c) {
            $score = 0;
            $reasons = [];
            $best_signal = 'name';
            $signaux = []; // pour combos : ['nom_exact', 'email_exact', 'tel_exact', 'siret', 'role']

            $cNom      = em_normalize_name((string)($c['nom'] ?? ''));
            $cPre      = em_normalize_name((string)($c['prenom'] ?? ''));
            $cRaison   = em_normalize_name((string)($c['raison_sociale'] ?? ''));
            $cAffich   = em_normalize_name((string)($c['nom_affichage'] ?? ''));
            $cMail     = em_normalize_email((string)($c['email'] ?? ''));
            $cTel      = em_normalize_phone((string)($c['telephone'] ?? ''));
            $cSiret    = em_normalize_siret((string)($c['siret'] ?? ''));
            $cSiren    = em_normalize_siret((string)($c['siren'] ?? ''));
            // Versions "stripped civilités" pour matcher couples/indivisions
            $cStripCiv = em_strip_civilites((string)($c['raison_sociale'] ?? '') . ' ' . (string)($c['nom_affichage'] ?? '') . ' ' . (string)($c['nom'] ?? ''));

            // SIRET/SIREN — identité légale, signal le plus fort
            if ($siret !== '') {
                if ($cSiret === $siret)                                      { $score += 95; $reasons[] = 'SIRET identique';  $best_signal = 'siret'; $signaux[] = 'siret'; }
                elseif (substr($siret, 0, 9) === $cSiren && $cSiren !== '')  { $score += 85; $reasons[] = 'SIREN identique';  $best_signal = 'siret'; $signaux[] = 'siret'; }
            }

            // Email — signal fort
            if ($email !== '' && $cMail !== '') {
                if ($email === $cMail)                { $score += 70; $reasons[] = 'Email identique'; if ($best_signal === 'name') $best_signal = 'email'; $signaux[] = 'email_exact'; }
                elseif (str_contains($cMail, $email)) { $score += 35; $reasons[] = 'Email partiel'; }
            }

            // Téléphone — fort si 9 chiffres identiques
            if ($tel !== '' && $cTel !== '') {
                if ($tel === $cTel)                { $score += 65; $reasons[] = 'Téléphone identique'; if ($best_signal === 'name') $best_signal = 'phone'; $signaux[] = 'tel_exact'; }
                elseif (str_contains($cTel, $tel)) { $score += 30; $reasons[] = 'Téléphone partiel'; }
            }

            // Raison sociale + nom_affichage + version stripped civilités
            // → reconnaissance des couples/indivisions ("MR & MME SABY" ≡ "SABY")
            // Hiérarchie de scoring :
            //   Match EXACT raison/affichage      → 60 (signal fort, doit primer)
            //   Match PARTIEL (substring)         → 25
            //   Famille (civilités stripped)      → 40 (plus bas que partiel exact)
            if ($raison !== '') {
                $raisonStrip = em_strip_civilites($raison);
                if ($cRaison !== '' && $raison === $cRaison)              { $score += 60; $reasons[] = 'Raison sociale identique'; $signaux[] = 'raison_exact'; }
                elseif ($cAffich !== '' && $raison === $cAffich)          { $score += 60; $reasons[] = 'Nom d\'affichage identique'; $signaux[] = 'raison_exact'; }
                elseif ($cRaison !== '' && (str_contains($cRaison, $raison) || str_contains($raison, $cRaison))) { $score += 25; $reasons[] = 'Raison sociale partielle'; }
                elseif ($cAffich !== '' && (str_contains($cAffich, $raison) || str_contains($raison, $cAffich))) { $score += 25; $reasons[] = 'Nom d\'affichage partiel'; }
                // Match couple/indivision : version stripped des civilités/&/et
                elseif ($raisonStrip !== '' && strlen($raisonStrip) >= 3
                        && $cStripCiv !== '' && (
                            $raisonStrip === $cStripCiv
                            || str_contains($cStripCiv, $raisonStrip)
                            || str_contains($raisonStrip, $cStripCiv)
                        )) {
                    $score += 40; $reasons[] = 'Famille identique (civilités stripped : "' . $raisonStrip . '" ≈ "' . $cStripCiv . '")';
                    $signaux[] = 'famille_match';
                    if ($best_signal === 'name') $best_signal = 'famille';
                }
            }

            // Nom — barème renforcé Sprint 1B
            // Nom exact seul → 60 (suffisant pour found=true + needs_user_validation)
            // Le combo nom+prénom monte à 75. Avec email/tel → ~85+.
            if ($nom !== '' && $cNom !== '') {
                if ($nom === $cNom) { $score += 60; $reasons[] = 'Nom identique'; $signaux[] = 'nom_exact'; }
                elseif (str_contains($cNom, $nom)) { $score += 20; $reasons[] = 'Nom partiel'; }
            }
            if ($prenom !== '' && $cPre !== '') {
                if ($prenom === $cPre) { $score += 20; $reasons[] = 'Prénom identique'; $signaux[] = 'prenom_exact'; }
                elseif (str_contains($cPre, $prenom)) { $score += 8; $reasons[] = 'Prénom partiel'; }
            }
            // Combo nom + prénom strict (cumul 80 → idéal pour personne physique)
            if (in_array('nom_exact', $signaux, true) && in_array('prenom_exact', $signaux, true)) {
                $reasons[] = 'Nom+Prénom combo exact';
            }

            // Bonus role_code matché (le tiers a déjà ce rôle → 5)
            if ($role_code) {
                $signaux[] = 'role_match'; // déjà filtré par INNER JOIN
                $reasons[] = 'Rôle ' . $role_code . ' déjà actif';
                $score += 5;
            }

            // Plafonnage : nom seul ne doit pas dépasser CREATE_OK-1 sans autre signal
            // pour forcer validation utilisateur (jamais auto-merge sur nom)
            $hasOtherStrongSignal = (bool)array_intersect($signaux,
                ['siret', 'email_exact', 'tel_exact', 'raison_exact']);
            if (in_array('nom_exact', $signaux, true) && !$hasOtherStrongSignal && !$role_code) {
                // Nom exact seul (sans prénom, sans email/tel/siret) → confidence max 60
                $score = min($score, 60);
            }

            if ($score > 0) {
                // create_with_warning : si confidence >= CREATE_OK on autorise la création
                // mais avec avertissement (l'utilisateur a vu les matches et a choisi de créer quand même)
                $matches[] = [
                    'id'              => (int)$c['id'],
                    'type_tiers'      => (string)($c['type_tiers'] ?? ''),
                    'nom_affichage'   => (string)($c['nom_affichage'] ?: trim(($c['raison_sociale'] ?: ($c['prenom'] . ' ' . $c['nom'])))),
                    'nom'             => (string)$c['nom'],
                    'prenom'          => (string)$c['prenom'],
                    'raison_sociale'  => (string)$c['raison_sociale'],
                    'email'           => (string)$c['email'],
                    'telephone'       => (string)$c['telephone'],
                    'siret'           => (string)$c['siret'],
                    'ville'           => (string)($c['ville'] ?? ''),
                    'score'           => $score,
                    'match_type'      => $best_signal,
                    'signaux'         => $signaux,
                    'reasons'         => $reasons,
                ];
            }
        }

        usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);

        $best = $matches[0] ?? null;
        $confidence = $best ? (int)$best['score'] : 0;

        // Logique de décision Sprint 1B :
        //   - confidence >= AUTO (90)        → rattachement pré-coché, validation user pour confirmer
        //   - confidence >= VALIDATE (60)    → validation obligatoire, création BLOQUÉE
        //   - confidence < VALIDATE          → création libre (pas de doublon détecté)
        $needsValidation = $confidence > 0 && $confidence < ENTITY_MATCHER_AUTO;
        $canCreate       = $confidence < ENTITY_MATCHER_CREATE_OK;
        // create_with_warning : found mais le user peut quand même créer (avec alerte UI)
        // Vrai si confidence >= CREATE_OK mais < AUTO (zone grise 60-89)
        $createWithWarning = $confidence >= ENTITY_MATCHER_CREATE_OK && $confidence < ENTITY_MATCHER_AUTO;

        return [
            'found'                 => $best !== null,
            'best'                  => $best,
            'matches'               => $matches,
            'confidence'            => $confidence,
            'match_type'            => $best['match_type'] ?? 'unknown',
            'needs_user_validation' => $needsValidation,
            'can_create'            => $canCreate,
            'create_with_warning'   => $createWithWarning,
        ];
    }
}

if (!function_exists('em_match_tiers_legacy_proprietaires')) {
    /**
     * Fallback si table `tiers` non présente : interroge `proprietaires` legacy.
     * Utilisé en transition Sprint 2.
     */
    function em_match_tiers_legacy_proprietaires(PDO $pdo, array $data, ?int $exclude_id, ?int $scope_societe_id): array
    {
        // Stub simple — utilisable mais pas optimisé. Le vrai algo reste dans
        // bailleur_check_duplicate.php tant que Sprint 2 n'a pas refactoré.
        return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true,
                'fallback' => 'legacy_proprietaires_not_implemented_here'];
    }
}

// ═════════════════════════════════════════════════════════════════════════
// MATCH IMMEUBLE
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('em_match_immeuble')) {
    /**
     * Cherche les immeubles existants qui pourraient être doublons.
     *
     * Cascade :
     *   1. Google Place ID exact          → 100
     *   2. Latitude/Longitude < 30m       → 95
     *   3. Adresse normalisée + CP exact  → 85
     *   4. Tokens adresse + CP + ville    → 50-75 (selon nb tokens matchés)
     *
     * @param PDO   $pdo
     * @param array $data {
     *   @var string $adresse_1
     *   @var string $code_postal
     *   @var string $ville
     *   @var float|null $latitude
     *   @var float|null $longitude
     *   @var string|null $google_place_id
     *   @var string|null $reference_immeuble
     * }
     * @param int|null $exclude_id
     * @return array Même shape que em_match_tiers
     */
    function em_match_immeuble(PDO $pdo, array $data, ?int $exclude_id = null): array
    {
        // Fix M-01 (2026-05-24) : accepter les 2 conventions de clés
        // - Clés "BDD" (adresse_1, google_place_id, reference_immeuble) : canon historique
        // - Clés "logiques" (adresse, place_id, reference) : utilisées par Sprint 3B/3D et docs IA
        $adresse = (string)($data['adresse_1'] ?? $data['adresse'] ?? '');
        $cp      = trim((string)($data['code_postal'] ?? ''));
        $ville   = em_normalize_text((string)($data['ville'] ?? ''));
        $lat     = isset($data['latitude'])  ? (float)$data['latitude']  : null;
        $lng     = isset($data['longitude']) ? (float)$data['longitude'] : null;
        $placeId = trim((string)($data['google_place_id'] ?? $data['place_id'] ?? ''));
        $refExt  = trim((string)($data['reference_immeuble'] ?? $data['reference'] ?? ''));

        if ($adresse === '' && $placeId === '' && ($lat === null || $lng === null) && $refExt === '') {
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
        }

        $adresseNorm    = em_normalize_address($adresse);
        $adresseTokens  = em_address_tokens($adresseNorm);
        $rueNorm        = em_normalize_street($adresse);          // "rue Mermet"
        $numerosCandidat= em_extract_street_numbers($adresse);    // [6,7,8] pour "6/8"

        // ─── Stratégie 1 : Google Place ID exact ─────────────────────
        if ($placeId !== '') {
            try {
                $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville, latitude, longitude, google_place_id, reference_immeuble
                                     FROM immeubles
                                     WHERE google_place_id = ? " . ($exclude_id ? "AND id <> ? " : "") . "LIMIT 1");
                $args = [$placeId];
                if ($exclude_id) $args[] = $exclude_id;
                $st->execute($args);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return em_immeuble_build_result($row, 100, 'google_place_id', ['Google Place ID identique']);
                }
            } catch (Throwable $e) { error_log('[em_match_immeuble place_id] ' . $e->getMessage()); }
        }

        // ─── Stratégie 2 : Coordonnées GPS < 30m ─────────────────────
        if ($lat !== null && $lng !== null && abs($lat) > 0.01 && abs($lng) > 0.01) {
            try {
                // Bounding box ~50m pour pré-filtre, distance Haversine ensuite
                $delta = 0.0005; // ~55m
                $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville, latitude, longitude, google_place_id, reference_immeuble,
                                     (6371000 * ACOS(
                                         COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?))
                                         + SIN(RADIANS(?)) * SIN(RADIANS(latitude))
                                     )) AS dist_m
                                     FROM immeubles
                                     WHERE latitude BETWEEN ? AND ?
                                       AND longitude BETWEEN ? AND ? "
                                       . ($exclude_id ? "AND id <> ? " : "")
                                       . "HAVING dist_m IS NOT NULL AND dist_m < 30
                                          ORDER BY dist_m ASC LIMIT 1");
                $args = [$lat, $lng, $lat, $lat - $delta, $lat + $delta, $lng - $delta, $lng + $delta];
                if ($exclude_id) $args[] = $exclude_id;
                $st->execute($args);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return em_immeuble_build_result($row, 95, 'geoloc',
                        ['Coordonnées GPS très proches (' . round((float)$row['dist_m'], 1) . ' m)']);
                }
            } catch (Throwable $e) { error_log('[em_match_immeuble geoloc] ' . $e->getMessage()); }
        }

        // ─── Stratégie 3 : Adresse normalisée exacte + CP ────────────
        // Important : la colonne adresse_1 contient le format BRUT
        // (ex: "6/8 Rue Mermet"), alors qu'on compare contre la version normalisée
        // (ex: "6 8 rue mermet"). On élargit le pré-filtre SQL au max (juste CP +
        // 1er token significatif) et on fait le matching exact en PHP.
        if ($adresseNorm !== '' && $cp !== '') {
            try {
                // Tokens >= 3 chars pour le pré-filtre (numéro de rue exclus pour éviter
                // les faux négatifs sur "6/8" vs "6-8" vs "6")
                $firstTextToken = '';
                foreach ($adresseTokens as $tok) {
                    if (!ctype_digit($tok) && strlen($tok) >= 3) { $firstTextToken = $tok; break; }
                }

                if ($firstTextToken === '') {
                    // Fallback : pré-filtre par CP uniquement (cas rare adresses très courtes)
                    $sql = "SELECT id, nom_immeuble, adresse_1, code_postal, ville, latitude, longitude, google_place_id, reference_immeuble
                            FROM immeubles WHERE code_postal = ? "
                          . ($exclude_id ? "AND id <> ? " : "") . "LIMIT 50";
                    $args = [$cp];
                } else {
                    $sql = "SELECT id, nom_immeuble, adresse_1, code_postal, ville, latitude, longitude, google_place_id, reference_immeuble
                            FROM immeubles
                            WHERE code_postal = ?
                              AND LOWER(adresse_1) LIKE ? "
                          . ($exclude_id ? "AND id <> ? " : "") . "LIMIT 50";
                    $args = [$cp, '%' . $firstTextToken . '%'];
                }
                if ($exclude_id) $args[] = $exclude_id;
                $st = $pdo->prepare($sql); $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($rows as $row) {
                    $cAddrNorm = em_normalize_address((string)$row['adresse_1']);
                    if ($cAddrNorm === $adresseNorm) {
                        return em_immeuble_build_result($row, 85, 'normalized_address',
                            ['Adresse normalisée identique', 'CP identique']);
                    }
                }
            } catch (Throwable $e) { error_log('[em_match_immeuble norm_addr] ' . $e->getMessage()); }
        }

        // ─── Stratégie 4 : Rue normalisée + CP + plage numéros (Sprint 1B) ──
        // Cas critique : "6 rue Mermet" doit matcher "6/8 rue Mermet" (et vice-versa).
        // On compare la RUE normalisée (sans numéros) + on vérifie l'intersection
        // des numéros candidats avec ceux de la BDD.
        if ($rueNorm !== '' && $cp !== '') {
            try {
                $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville, latitude, longitude, google_place_id, reference_immeuble
                                     FROM immeubles
                                     WHERE code_postal = ? "
                                       . ($exclude_id ? "AND id <> ? " : "")
                                       . "LIMIT 100");
                $args = [$cp];
                if ($exclude_id) $args[] = $exclude_id;
                $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $matches = [];
                foreach ($rows as $row) {
                    $cRue   = em_normalize_street((string)$row['adresse_1']);
                    if ($cRue === '' || $rueNorm === '') continue;

                    // Comparaison rues : exact OU inclusion
                    $rueExact   = ($cRue === $rueNorm);
                    $rueInclus  = (!$rueExact && (str_contains($cRue, $rueNorm) || str_contains($rueNorm, $cRue)));
                    if (!$rueExact && !$rueInclus) continue;

                    $cNumeros = em_extract_street_numbers((string)$row['adresse_1']);
                    $numerosCommuns = array_intersect($numerosCandidat, $cNumeros);
                    $nbNumCommuns   = count($numerosCommuns);

                    $score = 0;
                    $reasons = [];
                    $matchType = 'fuzzy_tokens';

                    if ($rueExact) {
                        $score = 75; $reasons[] = 'Même rue + même code postal';
                        // Numéros : intersection forte
                        if ($nbNumCommuns > 0) {
                            $score = 87;
                            $reasons[] = 'Numéro ' . implode(',', $numerosCommuns) . ' commun (plage ' . implode('/', $cNumeros) . ')';
                            $matchType = 'street_number_overlap';
                        } elseif (empty($numerosCandidat) || empty($cNumeros)) {
                            // L'un des 2 sans numéro → rue seule = validation user
                            $score = 70; $reasons[] = 'Numéro absent d\'un côté';
                        } else {
                            // Numéros DIFFÉRENTS sur la même rue → match faible
                            $score = 55;
                            $reasons[] = 'Numéros différents : ' . implode(',', $numerosCandidat)
                                       . ' vs ' . implode(',', $cNumeros);
                        }
                    } else {
                        $score = 50; $reasons[] = 'Rue similaire (inclusion) + même CP';
                    }

                    if ($ville !== '' && em_normalize_text((string)$row['ville']) === $ville) {
                        $score += 5; $reasons[] = 'Ville identique';
                    }

                    $matches[] = em_immeuble_build_result($row, $score, $matchType, $reasons);
                }
                if (!empty($matches)) {
                    $flat = [];
                    foreach ($matches as $m) $flat[] = $m['best'];
                    usort($flat, static fn($a, $b) => $b['score'] <=> $a['score']);
                    $best = $flat[0];
                    $conf = (int)$best['score'];
                    return [
                        'found'                 => true,
                        'best'                  => $best,
                        'matches'               => $flat,
                        'confidence'            => $conf,
                        'match_type'            => $best['match_type'],
                        'needs_user_validation' => $conf < ENTITY_MATCHER_AUTO,
                        'can_create'            => $conf < ENTITY_MATCHER_CREATE_OK,
                    ];
                }
            } catch (Throwable $e) { error_log('[em_match_immeuble fuzzy] ' . $e->getMessage()); }
        }

        // ─── Stratégie 5 (fallback) : rue seule sans CP (si CP non fourni) ──
        if ($rueNorm !== '' && $cp === '') {
            try {
                $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville, latitude, longitude, google_place_id, reference_immeuble
                                     FROM immeubles "
                                       . ($exclude_id ? "WHERE id <> ? " : "")
                                       . "LIMIT 200");
                $args = $exclude_id ? [$exclude_id] : [];
                $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $matches = [];
                foreach ($rows as $row) {
                    $cRue = em_normalize_street((string)$row['adresse_1']);
                    if ($cRue !== '' && $cRue === $rueNorm) {
                        $score = 45;
                        $reasons = ['Même rue (CP non fourni)'];
                        if ($ville !== '' && em_normalize_text((string)$row['ville']) === $ville) {
                            $score = 55; $reasons[] = 'Ville identique';
                        }
                        $matches[] = em_immeuble_build_result($row, $score, 'street_only', $reasons)['best'];
                    }
                }
                if (!empty($matches)) {
                    usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);
                    $best = $matches[0];
                    $conf = (int)$best['score'];
                    return [
                        'found'                 => true,
                        'best'                  => $best,
                        'matches'               => $matches,
                        'confidence'            => $conf,
                        'match_type'            => $best['match_type'],
                        'needs_user_validation' => true,
                        'can_create'            => $conf < ENTITY_MATCHER_CREATE_OK,
                    ];
                }
            } catch (Throwable $e) { error_log('[em_match_immeuble street_only] ' . $e->getMessage()); }
        }

        return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
    }
}

// ═════════════════════════════════════════════════════════════════════════
// MATCH BIEN — lot/appartement précis (sur table `biens`)
// Sprint 2A — 2026-05-23 : ajout pour permettre la migration de
// api/bien_check_duplicate.php sous FEATURE_ENTITY_MATCHER.
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('em_match_bien')) {
    /**
     * Cherche les biens existants qui pourraient être doublons d'un candidat.
     *
     * Scoring cumulatif (réplique le barème legacy de api/bien_check_duplicate.php
     * en y ajoutant les normalisations déterministes du moteur central) :
     *   - Mandat identique (numero_mandat)         → 100 (bloquant)
     *   - Référence externe identique              → 100 (bloquant)
     *   - Adresse normalisée + CP + ville          +50
     *   - Étage + (porte ou lot) identiques        +30
     *   - Surface ± 5%                             +10
     *   - Nb pièces exact                          +5
     *   - Bonus géoloc < 30m                       +15
     *
     * @param PDO   $pdo
     * @param array $data {
     *   @var string $adresse_1
     *   @var string $code_postal
     *   @var string $ville
     *   @var string $etage         (optionnel)
     *   @var string $porte         (optionnel)
     *   @var string $lot_principal (optionnel)
     *   @var float  $surface_habitable (optionnel)
     *   @var int    $nb_pieces      (optionnel)
     *   @var string $numero_mandat  (optionnel — bloquant si match)
     *   @var string $reference_externe (optionnel — bloquant si match)
     *   @var float  $latitude       (optionnel)
     *   @var float  $longitude      (optionnel)
     * }
     * @param int|null $exclude_id
     * @param int|null $scope_societe_id Filtre multi-tenant
     * @return array Shape standardisé (found/best/matches/confidence/...)
     */
    function em_match_bien(PDO $pdo, array $data, ?int $exclude_id = null, ?int $scope_societe_id = null): array
    {
        // Fix M-01 (2026-05-24) : accepter les 2 conventions de clés
        // - Clés "BDD" canon : adresse_1, numero_mandat, reference_externe, lot_principal, etc.
        // - Clés "logiques" alias : adresse, mandat, ref_externe, lot, numero_lot, surface, pieces
        $adresse   = (string)($data['adresse_1'] ?? $data['adresse'] ?? '');
        $cp        = trim((string)($data['code_postal'] ?? ''));
        $ville     = em_normalize_text((string)($data['ville'] ?? ''));
        $etage     = trim((string)($data['etage'] ?? ''));
        $porte     = trim((string)($data['porte'] ?? ''));
        $lot       = trim((string)($data['lot_principal'] ?? $data['numero_lot'] ?? $data['lot'] ?? ''));
        $surface   = isset($data['surface_habitable']) && $data['surface_habitable'] !== ''
                     ? (float)$data['surface_habitable']
                     : (isset($data['surface']) && $data['surface'] !== '' ? (float)$data['surface'] : null);
        $nbPieces  = isset($data['nb_pieces']) && $data['nb_pieces'] !== ''
                     ? (int)$data['nb_pieces']
                     : (isset($data['pieces']) && $data['pieces'] !== '' ? (int)$data['pieces'] : null);
        $mandat    = trim((string)($data['numero_mandat'] ?? $data['mandat'] ?? ''));
        $refExt    = trim((string)($data['reference_externe'] ?? $data['ref_externe'] ?? ''));
        $lat       = isset($data['latitude'])  ? (float)$data['latitude']  : null;
        $lng       = isset($data['longitude']) ? (float)$data['longitude'] : null;

        if ($adresse === '' && $mandat === '' && $refExt === '') {
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
        }

        $adresseNorm = em_normalize_address($adresse);
        $rueNorm     = em_normalize_street($adresse);
        $numCandidat = em_extract_street_numbers($adresse);

        // ─── Stratégie bloquante 1 : mandat identique ─────────────────
        if ($mandat !== '') {
            try {
                $sql = "SELECT id, reference_bien, designation, adresse_1, code_postal, ville,
                               etage, lot_principal, surface_habitable, nb_pieces, statut_bien
                        FROM biens
                        WHERE numero_mandat = ? "
                      . ($exclude_id ? "AND id <> ? " : "") . "LIMIT 5";
                $args = [$mandat]; if ($exclude_id) $args[] = $exclude_id;
                $st = $pdo->prepare($sql); $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rows)) {
                    $best = em_bien_build_match($rows[0], 100, 'mandat_identique', ['Même n° mandat : ' . $mandat]);
                    $matches = array_map(static fn($r) => em_bien_build_match($r, 100, 'mandat_identique', ['Même n° mandat : ' . $mandat]), $rows);
                    return [
                        'found' => true, 'best' => $best, 'matches' => $matches,
                        'confidence' => 100, 'match_type' => 'mandat_identique',
                        'needs_user_validation' => false, 'can_create' => false,
                    ];
                }
            } catch (Throwable $e) { error_log('[em_match_bien mandat] ' . $e->getMessage()); }
        }

        // ─── Stratégie bloquante 2 : référence externe identique ──────
        if ($refExt !== '') {
            try {
                // La colonne reference_externe peut ne pas exister sur tous les schémas → check
                $hasCol = false;
                try { $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM biens LIKE 'reference_externe'")->fetchColumn(); } catch (Throwable) {}
                if ($hasCol) {
                    $sql = "SELECT id, reference_bien, designation, adresse_1, code_postal, ville,
                                   etage, lot_principal, surface_habitable, nb_pieces, statut_bien
                            FROM biens
                            WHERE reference_externe = ? "
                          . ($exclude_id ? "AND id <> ? " : "") . "LIMIT 5";
                    $args = [$refExt]; if ($exclude_id) $args[] = $exclude_id;
                    $st = $pdo->prepare($sql); $st->execute($args);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if (!empty($rows)) {
                        $best = em_bien_build_match($rows[0], 100, 'reference_externe', ['Référence externe identique : ' . $refExt]);
                        $matches = array_map(static fn($r) => em_bien_build_match($r, 100, 'reference_externe', ['Référence externe identique : ' . $refExt]), $rows);
                        return [
                            'found' => true, 'best' => $best, 'matches' => $matches,
                            'confidence' => 100, 'match_type' => 'reference_externe',
                            'needs_user_validation' => false, 'can_create' => false,
                        ];
                    }
                }
            } catch (Throwable $e) { error_log('[em_match_bien refExt] ' . $e->getMessage()); }
        }

        // ─── Stratégie 3 : scoring adresse + critères secondaires ────
        if ($rueNorm === '' || $cp === '') {
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
        }

        // Filtre scope multi-tenant (les biens ont id_societe + id_agence)
        $where  = ['b.code_postal = ?'];
        $params = [$cp];
        if ($scope_societe_id !== null && $scope_societe_id > 0) {
            $where[] = '(b.id_societe = ? OR b.id_societe IS NULL)';
            $params[] = $scope_societe_id;
        }
        if ($exclude_id) {
            $where[] = 'b.id <> ?';
            $params[] = $exclude_id;
        }

        try {
            $sql = "SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.code_postal, b.ville,
                           b.etage, b.lot_principal, b.surface_habitable, b.nb_pieces, b.statut_bien,
                           b.id_societe
                    FROM biens b
                    WHERE " . implode(' AND ', $where) . "
                    LIMIT 100";
            $st = $pdo->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[em_match_bien query] ' . $e->getMessage());
            return ['found' => false, 'best' => null, 'matches' => [], 'confidence' => 0,
                    'match_type' => 'unknown', 'needs_user_validation' => false, 'can_create' => true];
        }

        $matches = [];
        foreach ($rows as $row) {
            $score = 0;
            $reasons = [];

            // Adresse normalisée — rue + intersection numéros
            $cRueNorm = em_normalize_street((string)$row['adresse_1']);
            $cNums    = em_extract_street_numbers((string)$row['adresse_1']);
            $rueEq    = ($cRueNorm !== '' && $cRueNorm === $rueNorm);
            $numsOver = !empty(array_intersect($numCandidat, $cNums));
            $villeEq  = ($ville !== '' && em_normalize_text((string)$row['ville']) === $ville);

            if ($rueEq && $numsOver && $villeEq)      { $score += 50; $reasons[] = 'Adresse normalisée + CP + ville identiques'; }
            elseif ($rueEq && $numsOver)              { $score += 45; $reasons[] = 'Adresse normalisée + CP identiques'; }
            elseif ($rueEq)                           { $score += 30; $reasons[] = 'Même rue + même CP'; }
            else                                       { continue; }

            // Étage + porte/lot
            $cEtage = trim((string)($row['etage'] ?? ''));
            $cLot   = trim((string)($row['lot_principal'] ?? ''));
            if ($etage !== '' && $cEtage !== '' && strcasecmp($etage, $cEtage) === 0) {
                if (($porte !== '' || $lot !== '') && (strcasecmp($porte, $cLot) === 0 || strcasecmp($lot, $cLot) === 0)) {
                    $score += 30; $reasons[] = 'Étage + porte/lot identiques';
                } else {
                    $score += 15; $reasons[] = 'Étage identique';
                }
            } elseif ($lot !== '' && $cLot !== '' && strcasecmp($lot, $cLot) === 0) {
                $score += 20; $reasons[] = 'Lot principal identique';
            }

            // Surface ± 5%
            $cSurface = $row['surface_habitable'] !== null ? (float)$row['surface_habitable'] : null;
            if ($surface !== null && $cSurface !== null && $surface > 0 && $cSurface > 0) {
                $diffPct = abs($surface - $cSurface) / $surface * 100;
                if ($diffPct <= 5) { $score += 10; $reasons[] = 'Surface ±5% (' . round($cSurface, 1) . ' m²)'; }
            }

            // Nb pièces exact
            $cNbP = $row['nb_pieces'] !== null ? (int)$row['nb_pieces'] : null;
            if ($nbPieces !== null && $cNbP !== null && $nbPieces === $cNbP) {
                $score += 5; $reasons[] = 'Nb pièces identique (' . $nbPieces . ')';
            }

            if ($score >= 30) {
                $matches[] = em_bien_build_match($row, $score, 'address_score', $reasons);
            }
        }

        usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);
        $best = $matches[0] ?? null;
        $confidence = $best ? (int)$best['score'] : 0;

        return [
            'found'                 => $best !== null,
            'best'                  => $best,
            'matches'               => $matches,
            'confidence'            => $confidence,
            'match_type'            => $best['match_type'] ?? 'unknown',
            'needs_user_validation' => $confidence > 0 && $confidence < ENTITY_MATCHER_AUTO,
            'can_create'            => $confidence < ENTITY_MATCHER_CREATE_OK,
        ];
    }
}

if (!function_exists('em_bien_build_match')) {
    /** Format de sortie standardisé pour un match bien (utilisé par em_match_bien). */
    function em_bien_build_match(array $row, int $score, string $matchType, array $reasons): array
    {
        return [
            'id'                => (int)$row['id'],
            'reference_bien'    => (string)($row['reference_bien'] ?? ''),
            'designation'       => (string)($row['designation'] ?? ''),
            'adresse_1'         => (string)$row['adresse_1'],
            'code_postal'       => (string)$row['code_postal'],
            'ville'             => (string)$row['ville'],
            'etage'             => (string)($row['etage'] ?? ''),
            'lot_principal'     => (string)($row['lot_principal'] ?? ''),
            'surface_habitable' => $row['surface_habitable'] !== null ? (float)$row['surface_habitable'] : null,
            'nb_pieces'         => $row['nb_pieces'] !== null ? (int)$row['nb_pieces'] : null,
            'statut_bien'       => (string)($row['statut_bien'] ?? ''),
            'score'             => $score,
            'match_type'        => $matchType,
            'reasons'           => $reasons,
        ];
    }
}

if (!function_exists('em_immeuble_build_result')) {
    /** Helper : construit le résultat standardisé pour un match immeuble. */
    function em_immeuble_build_result(array $row, int $score, string $matchType, array $reasons): array
    {
        $best = [
            'id'                  => (int)$row['id'],
            'nom_immeuble'        => (string)($row['nom_immeuble'] ?? ''),
            'adresse_1'           => (string)$row['adresse_1'],
            'code_postal'         => (string)$row['code_postal'],
            'ville'               => (string)$row['ville'],
            'latitude'            => $row['latitude']  !== null ? (float)$row['latitude']  : null,
            'longitude'           => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'google_place_id'     => (string)($row['google_place_id'] ?? ''),
            'reference_immeuble'  => (string)($row['reference_immeuble'] ?? ''),
            'score'               => $score,
            'match_type'          => $matchType,
            'reasons'             => $reasons,
        ];
        return [
            'found'                 => true,
            'best'                  => $best,
            'matches'               => [$best],
            'confidence'            => $score,
            'match_type'            => $matchType,
            'needs_user_validation' => $score < ENTITY_MATCHER_AUTO,
            'can_create'            => $score < ENTITY_MATCHER_CREATE_OK,
        ];
    }
}
