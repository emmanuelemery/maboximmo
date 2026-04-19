<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Générateur de références multi-société / multi-agence
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Expose :
 *   ref_generate_bien(PDO, array $ctx): string
 *   ref_generate_annonce(PDO, array $ctx): string
 *
 * Pattern tokens supportés :
 *   {TYPE}       code type bien complet (ex: 'appartement')
 *   {TYPE3}      3 lettres majuscules (APT, MSN, TER…)
 *   {VILLE}      ville en majuscules
 *   {VILLE3}     3 premières lettres majuscules
 *   {SOC}        code société (3 lettres des initiales du nom)
 *   {AGE}        code agence (3 lettres des initiales du nom)
 *   {USER3}      initiales commercial (1er lettre prénom + 2 1ères lettres nom)
 *   {USER2}      2 initiales (prénom + nom)
 *   {YY}         2 derniers chiffres année courante
 *   {YYYY}       année complète
 *   {SEQ}        séquence bien (non paddée)
 *   {SEQ:NN}     séquence bien paddée à N chiffres (ex: SEQ:04 → 0042)
 *   {ANN_SEQ}    séquence annonce (non paddée)
 *   {ANN_SEQ:NN} séquence annonce paddée
 *   {BIEN_REF}   référence complète du bien (pour les annonces)
 *   {TRANS3}     transaction courte (LOC / VEN)
 *
 * Concurrence : l'incrément de séquence est fait en transaction avec
 * SELECT ... FOR UPDATE sur la ligne agence concernée, évite les doublons
 * de séquence en cas de création simultanée.
 */

/**
 * Génère la référence d'un bien à partir du contexte.
 *
 * @param PDO $pdo
 * @param array $ctx Attend :
 *                   - id_agence (int, requis)
 *                   - type_bien_code (string)     ex: 'appartement'
 *                   - ville (string)              ex: 'Montpellier'
 *                   - user (array)                avec 'nom', 'prenom'
 * @return string La référence générée (ex: 'APT-MTP-25-0042-JDU')
 */
function ref_generate_bien(PDO $pdo, array $ctx): string
{
    $idAgence = (int)($ctx['id_agence'] ?? 0);
    if ($idAgence <= 0) {
        throw new RuntimeException('ref_generate_bien : id_agence requis');
    }
    [$pattern, $agence, $societe] = ref_resolve_pattern($pdo, $idAgence, 'bien');
    $seq = ref_next_sequence($pdo, $idAgence, 'bien', (bool)($societe['ref_annual_reset'] ?? 1));

    $vars = ref_build_base_vars($ctx, $agence, $societe);
    $vars['SEQ'] = $seq;
    $vars['ANN_SEQ'] = null; // pas pertinent pour un bien
    $vars['BIEN_REF'] = null;
    $vars['TRANS3']   = null;

    return ref_substitute_pattern($pattern, $vars);
}

/**
 * Génère la référence d'une annonce à partir du contexte + de la référence bien parente.
 *
 * @param PDO $pdo
 * @param array $ctx Attend :
 *                   - id_agence (int)
 *                   - bien_ref (string)        référence complète du bien parent
 *                   - transaction (string)     'location' | 'vente'
 *                   - type_bien_code (string)
 *                   - ville (string)
 *                   - user (array)
 * @return string
 */
function ref_generate_annonce(PDO $pdo, array $ctx): string
{
    $idAgence = (int)($ctx['id_agence'] ?? 0);
    if ($idAgence <= 0) {
        throw new RuntimeException('ref_generate_annonce : id_agence requis');
    }
    [$pattern, $agence, $societe] = ref_resolve_pattern($pdo, $idAgence, 'annonce');
    $seq = ref_next_sequence($pdo, $idAgence, 'annonce', (bool)($societe['ref_annual_reset'] ?? 1));

    $vars = ref_build_base_vars($ctx, $agence, $societe);
    $vars['ANN_SEQ']  = $seq;
    $vars['SEQ']      = null; // pas pertinent ici
    $vars['BIEN_REF'] = (string)($ctx['bien_ref'] ?? '');
    $vars['TRANS3']   = ref_transaction_code((string)($ctx['transaction'] ?? ''));

    return ref_substitute_pattern($pattern, $vars);
}

// ════════════════════════════════════════════════════════════════
// INTERNAL HELPERS
// ════════════════════════════════════════════════════════════════

/**
 * Résout le pattern effectif (override agence ou défaut société) + charge les lignes.
 * @return array{0: string, 1: array, 2: array} [pattern, agence, societe]
 */
function ref_resolve_pattern(PDO $pdo, int $idAgence, string $type): array
{
    $col = $type === 'annonce' ? 'ref_pattern_annonce' : 'ref_pattern_bien';
    $stmt = $pdo->prepare("
        SELECT a.id, a.id_societe, a.{$col} AS agence_pattern,
               a.ref_seq_bien_current, a.ref_seq_annonce_current, a.ref_seq_year,
               s.{$col} AS societe_pattern, s.ref_annual_reset,
               COALESCE(s.nom, s.raison_sociale, '') AS societe_nom,
               a.nom_agence AS agence_nom
        FROM agences a
        LEFT JOIN societes s ON s.id = a.id_societe
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$idAgence]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException("ref_resolve_pattern : agence {$idAgence} introuvable");
    }
    $pattern = trim((string)($row['agence_pattern'] ?? '')) !== ''
        ? (string)$row['agence_pattern']
        : (string)($row['societe_pattern'] ?? '');
    if ($pattern === '') {
        // Fallback hard-codé si aucun pattern configuré
        $pattern = $type === 'annonce'
            ? '{BIEN_REF}-{TRANS3}-{ANN_SEQ:02}'
            : '{TYPE3}-{VILLE3}-{YY}-{SEQ:04}-{USER3}';
    }
    $agence = [
        'id'                       => (int)$row['id'],
        'id_societe'               => (int)$row['id_societe'],
        'nom'                      => (string)($row['agence_nom'] ?? ''),
        'ref_seq_bien_current'     => (int)($row['ref_seq_bien_current']     ?? 0),
        'ref_seq_annonce_current'  => (int)($row['ref_seq_annonce_current']  ?? 0),
        'ref_seq_year'             => $row['ref_seq_year'] !== null ? (int)$row['ref_seq_year'] : null,
    ];
    $societe = [
        'nom'              => (string)($row['societe_nom'] ?? ''),
        'ref_annual_reset' => (int)($row['ref_annual_reset'] ?? 1),
    ];
    return [$pattern, $agence, $societe];
}

/**
 * Incrémente atomiquement la séquence de l'agence (bien ou annonce) et la renvoie.
 * Gère le reset annuel automatique si activé.
 */
function ref_next_sequence(PDO $pdo, int $idAgence, string $type, bool $annualReset): int
{
    $colSeq  = $type === 'annonce' ? 'ref_seq_annonce_current' : 'ref_seq_bien_current';
    $currentYear = (int)date('Y');

    // Transaction + verrouillage pour éviter les doublons de séquence
    $wasInTxn = $pdo->inTransaction();
    if (!$wasInTxn) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT {$colSeq} AS seq, ref_seq_year FROM agences WHERE id = ? FOR UPDATE");
        $stmt->execute([$idAgence]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Agence introuvable dans séquence');
        $seq = (int)($row['seq'] ?? 0);
        $lastYear = $row['ref_seq_year'] !== null ? (int)$row['ref_seq_year'] : null;

        if ($annualReset && ($lastYear === null || $lastYear !== $currentYear)) {
            // Nouvelle année → reset les DEUX compteurs (bien + annonce)
            $pdo->prepare("
                UPDATE agences
                SET ref_seq_bien_current = 0,
                    ref_seq_annonce_current = 0,
                    ref_seq_year = ?
                WHERE id = ?
            ")->execute([$currentYear, $idAgence]);
            $seq = 0;
        }

        $newSeq = $seq + 1;
        $pdo->prepare("UPDATE agences SET {$colSeq} = ?, ref_seq_year = ? WHERE id = ?")
            ->execute([$newSeq, $currentYear, $idAgence]);

        if (!$wasInTxn) $pdo->commit();
        return $newSeq;
    } catch (Throwable $e) {
        if (!$wasInTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Construit les variables communes à partir du contexte.
 */
function ref_build_base_vars(array $ctx, array $agence, array $societe): array
{
    $typeBien = (string)($ctx['type_bien_code'] ?? '');
    $ville    = (string)($ctx['ville'] ?? '');
    $user     = (array)($ctx['user'] ?? []);

    return [
        'TYPE'   => strtoupper(ref_slug_basic($typeBien)),
        'TYPE3'  => ref_code_type_bien($typeBien),
        'VILLE'  => strtoupper(ref_slug_basic($ville)),
        'VILLE3' => ref_code_ville($ville),
        'SOC'    => ref_initials((string)$societe['nom'], 3),
        'AGE'    => ref_initials((string)$agence['nom'], 3),
        'USER2'  => ref_user_initials($user, 2),
        'USER3'  => ref_user_initials($user, 3),
        'YY'     => date('y'),
        'YYYY'  => date('Y'),
    ];
}

/**
 * Remplace les tokens du pattern par les valeurs.
 * Supporte {TOKEN}, {TOKEN:NN} (padding zéros à gauche pour SEQ/ANN_SEQ).
 */
function ref_substitute_pattern(string $pattern, array $vars): string
{
    // Regex accepte lettres, chiffres et underscore — sinon les tokens comme
    // {TYPE3}, {VILLE3}, {USER3} ne seraient jamais substitués.
    return preg_replace_callback('/\{([A-Z_][A-Z_0-9]*)(?::(\d+))?\}/', function ($m) use ($vars) {
        $token = $m[1];
        $padTo = isset($m[2]) ? (int)$m[2] : 0;
        $value = $vars[$token] ?? null;
        if ($value === null) return ''; // token non fourni → vide (au lieu de garder {XXX})
        if ($padTo > 0 && (is_int($value) || ctype_digit((string)$value))) {
            return str_pad((string)$value, $padTo, '0', STR_PAD_LEFT);
        }
        return (string)$value;
    }, $pattern);
}

/**
 * Normalisation de base (retire accents, garde alphanum + tirets).
 */
function ref_slug_basic(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^A-Za-z0-9]+/', '', $s) ?? '';
    return strtoupper($s);
}

/**
 * 3 premières lettres d'une ville (ex: 'Montpellier' → 'MTP').
 */
function ref_code_ville(string $ville): string
{
    $n = ref_slug_basic($ville);
    return substr($n, 0, 3);
}

/**
 * Code 3 lettres pour un type de bien.
 * Mapping explicite pour les types courants, fallback sur les 3 premières lettres.
 */
function ref_code_type_bien(string $typeCode): string
{
    $map = [
        'appartement'  => 'APT',
        'maison'       => 'MSN',
        'terrain'      => 'TER',
        'immeuble'     => 'IMB',
        'local'        => 'LOC',
        'commerce'     => 'COM',
        'bureau'       => 'BUR',
        'parking'      => 'PKG',
        'garage'       => 'GAR',
        'chambre'      => 'CHB',
        'studio'       => 'STD',
        'loft'         => 'LFT',
        'villa'        => 'VLA',
        'hotel'        => 'HTL',
        'entrepot'     => 'ENT',
    ];
    $key = strtolower(trim($typeCode));
    if (isset($map[$key])) return $map[$key];
    $n = ref_slug_basic($typeCode);
    return substr($n, 0, 3);
}

/**
 * Initiales d'une raison sociale/agence (retire mots courts : "SARL", "SA", "de", "le", "la", "les").
 */
function ref_initials(string $name, int $length = 3): string
{
    $stopWords = ['SARL','SAS','SASU','SA','EURL','SCI','DE','DU','LA','LE','LES','L','D','ET','OU','AU','AUX'];
    $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $n = strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $n) ?? '');
    $words = array_values(array_filter(explode(' ', $n), static fn($w) => $w !== '' && !in_array($w, $stopWords, true)));
    if (empty($words)) return '';
    $ini = '';
    foreach ($words as $w) {
        $ini .= $w[0] ?? '';
        if (strlen($ini) >= $length) break;
    }
    return substr($ini, 0, $length);
}

/**
 * Initiales d'un utilisateur : 1 lettre prénom + (length - 1) lettres nom.
 * 'Jean', 'Dupont' → 'JDU' (length=3) | 'JD' (length=2)
 */
function ref_user_initials(array $user, int $length = 3): string
{
    $prenom = strtoupper(ref_slug_basic((string)($user['prenom'] ?? '')));
    $nom    = strtoupper(ref_slug_basic((string)($user['nom'] ?? '')));
    $p = $prenom !== '' ? $prenom[0] : '';
    $n = substr($nom, 0, max(0, $length - strlen($p)));
    $out = $p . $n;
    if (strlen($out) < $length) {
        // fallback : si nom/prénom manquent, padder avec le nom seul
        $out = str_pad($out, $length, $nom !== '' ? $nom[0] : 'X', STR_PAD_RIGHT);
    }
    return substr($out, 0, $length);
}

/**
 * Code court pour un type de transaction.
 */
function ref_transaction_code(string $transaction): string
{
    $t = strtolower(trim($transaction));
    if ($t === 'vente' || $t === 'achat') return 'VEN';
    if ($t === 'location')                 return 'LOC';
    if ($t === 'viager')                   return 'VIA';
    return strtoupper(substr(ref_slug_basic($transaction), 0, 3));
}
