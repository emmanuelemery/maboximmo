<?php
declare(strict_types=1);
/**
 * inc/comptes_bancaires.php — Comptes bancaires multiples (table societes_rib), typés par usage
 * et rattachés à une agence.
 *
 *   type_compte : 'gestion' (baux + compta gestion) · 'societe' (transaction/location hors gestion)
 *                 · 'transaction' (séquestre / transaction dédié)
 *   id_agence   : agence propriétaire du compte (NULL = niveau société)
 *
 * Résolution cb_resolve() : du plus spécifique au plus général, avec repli legacy sur societes.*.
 */

if (!function_exists('cb_types')) {
    function cb_types(): array {
        return [
            'gestion'   => 'Gestion (clients)',
            'sequestre' => 'Séquestre',
            'societe'   => 'Société',
        ];
    }
}

if (!function_exists('cb_list')) {
    /** Liste des comptes d'une société (optionnellement filtrés sur une agence). */
    function cb_list(PDO $pdo, int $idSociete, ?int $idAgence = null): array {
        if ($idSociete <= 0) return [];
        $sql = "SELECT * FROM societes_rib WHERE id_societe = ?";
        $args = [$idSociete];
        if ($idAgence !== null) { $sql .= " AND (id_agence = ? OR id_agence IS NULL)"; $args[] = $idAgence; }
        $sql .= " ORDER BY (id_agence IS NULL), type_compte, is_default DESC, libelle";
        $st = $pdo->prepare($sql); $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('cb_resolve')) {
    /**
     * Résout LE compte à utiliser pour (société, agence, usage).
     * Ordre : agence+type → société(sans agence)+type → société+type(toute agence)
     *         → défaut société → legacy societes.rib_emetteur_*.
     * @return array{iban:string,bic:string,banque:string,titulaire:string,libelle:string,source:string}
     */
    function cb_resolve(PDO $pdo, int $idSociete, ?int $idAgence, string $type = 'gestion'): array {
        $empty = ['iban'=>'','bic'=>'','banque'=>'','titulaire'=>'','libelle'=>'','source'=>'aucun'];
        if ($idSociete <= 0) return $empty;
        $pick = function(string $where, array $args) use ($pdo): ?array {
            $st = $pdo->prepare("SELECT iban,bic,banque,titulaire,libelle FROM societes_rib
                                  WHERE actif = 1 AND $where ORDER BY is_default DESC, id ASC LIMIT 1");
            $st->execute($args);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $cand = null; $src = '';
        if ($idAgence) { $cand = $pick("id_societe=? AND id_agence=? AND type_compte=?", [$idSociete,$idAgence,$type]); $src = 'agence+type'; }
        if (!$cand)    { $cand = $pick("id_societe=? AND id_agence IS NULL AND type_compte=?", [$idSociete,$type]); $src = 'société+type'; }
        if (!$cand)    { $cand = $pick("id_societe=? AND type_compte=?", [$idSociete,$type]); $src = 'type (toute agence)'; }
        if (!$cand)    { $cand = $pick("id_societe=?", [$idSociete]); $src = 'défaut société'; }
        if ($cand) return [
            'iban'=>(string)$cand['iban'], 'bic'=>(string)$cand['bic'], 'banque'=>(string)$cand['banque'],
            'titulaire'=>(string)$cand['titulaire'], 'libelle'=>(string)$cand['libelle'], 'source'=>$src,
        ];
        // Repli legacy : ancien champ unique sur societes.
        $st = $pdo->prepare("SELECT rib_emetteur_iban iban, rib_emetteur_bic bic, rib_emetteur_nom banque FROM societes WHERE id=?");
        $st->execute([$idSociete]); $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($r['iban'])) return [
            'iban'=>(string)$r['iban'], 'bic'=>(string)($r['bic']??''), 'banque'=>(string)($r['banque']??''),
            'titulaire'=>'', 'libelle'=>'', 'source'=>'legacy societes',
        ];
        return $empty;
    }
}

if (!function_exists('cb_save')) {
    /** Crée/modifie un compte. $d : id?, id_societe, id_agence?, type_compte, libelle, titulaire, iban, bic, banque, is_default?, actif? */
    function cb_save(PDO $pdo, array $d): int {
        $idSoc = (int)($d['id_societe'] ?? 0);
        if ($idSoc <= 0) throw new InvalidArgumentException('id_societe requis');
        $type = array_key_exists($d['type_compte'] ?? '', cb_types()) ? $d['type_compte'] : 'gestion';
        $idAge = !empty($d['id_agence']) ? (int)$d['id_agence'] : null;
        $fields = [
            'id_societe'  => $idSoc,
            'id_agence'   => $idAge,
            'type_compte' => $type,
            'libelle'     => trim((string)($d['libelle'] ?? '')),
            'titulaire'   => trim((string)($d['titulaire'] ?? '')),
            'iban'        => trim((string)($d['iban'] ?? '')),
            'bic'         => trim((string)($d['bic'] ?? '')),
            'banque'      => trim((string)($d['banque'] ?? '')),
            'is_default'  => !empty($d['is_default']) ? 1 : 0,
            'actif'       => array_key_exists('actif',$d) ? (!empty($d['actif'])?1:0) : 1,
        ];
        // Un seul compte par défaut pour un même (société, agence, type).
        if ($fields['is_default']) {
            $st = $pdo->prepare("UPDATE societes_rib SET is_default=0
                                  WHERE id_societe=? AND type_compte=? AND ".($idAge!==null?"id_agence=?":"id_agence IS NULL"));
            $st->execute($idAge!==null ? [$idSoc,$type,$idAge] : [$idSoc,$type]);
        }
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) {
            $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($fields)));
            $pdo->prepare("UPDATE societes_rib SET $set, date_modif=NOW() WHERE id=?")
                ->execute([...array_values($fields), $id]);
            return $id;
        }
        $cols = implode(',', array_keys($fields));
        $ph   = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO societes_rib ($cols, date_creation, date_modif) VALUES ($ph, NOW(), NOW())")
            ->execute(array_values($fields));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('cb_delete')) {
    function cb_delete(PDO $pdo, int $id, int $idSociete): bool {
        $st = $pdo->prepare("DELETE FROM societes_rib WHERE id=? AND id_societe=?");
        $st->execute([$id, $idSociete]);
        return $st->rowCount() > 0;
    }
}
