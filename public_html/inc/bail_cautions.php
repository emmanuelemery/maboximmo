<?php
/**
 * inc/bail_cautions.php — Cautions d'un bail = TIERS (rôle `caution`), PAS une table dédiée.
 *
 * Doctrine (décision 2026-08) : une caution est une PERSONNE réelle avec qui on communiquera
 * (dossiers contentieux, signature des baux…). Elle est donc un `tiers` (source de vérité de
 * l'identité/contact, réutilisable), relié au bail via `tiers_roles` scopé :
 *     role_code = 'caution'  ·  objet_type = 'bail'  ·  id_objet = <bien_baux.id>
 * Les détails PROPRES à ce cautionnement (type solidaire/simple, montant plafond, durée, texte
 * d'engagement) vivent dans `tiers_roles.metadata` (JSON) — ils qualifient le LIEN, pas la personne.
 *
 * NB : le rôle 'caution' existe déjà dans `tiers_roles_codes` (objet_type_defaut='bail').
 * NB : `objet_type` suit la convention MINUSCULE de la base ('bail','bien','dossier_vente').
 *
 * API :
 *   bail_cautions_list($pdo,$bailId)            → array de cautions (tiers + meta du lien)
 *   bail_caution_upsert($pdo,$bailId,$data)     → ['id_tiers'=>int,'id_role'=>int,'created_tiers'=>bool,'created_link'=>bool]
 *   bail_caution_detach($pdo,$bailId,$idTiers)  → bool (désactive le lien, garde le tiers)
 */
declare(strict_types=1);
require_once __DIR__ . '/tiers_dedup.php';   // tiers_person_match() (anti-doublon tiers ordre-agnostique)

if (!function_exists('bail_cautions_soc_agence')) {
    /** Résout [id_societe, id_agence] depuis le bail (pour scoper le tiers). */
    function bail_cautions_soc_agence(PDO $pdo, int $bailId): array {
        try {
            $st = $pdo->prepare("SELECT id_societe, id_agence FROM bien_baux WHERE id = ? LIMIT 1");
            $st->execute([$bailId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return [ (int)($r['id_societe'] ?? 0) ?: null, (int)($r['id_agence'] ?? 0) ?: null ];
        } catch (Throwable $e) { return [null, null]; }
    }
}

if (!function_exists('bail_cautions_list')) {
    /**
     * Cautions actives d'un bail = tiers reliés par le rôle 'caution' scopé au bail.
     * Chaque entrée : champs tiers (nom_affichage, nom, prenom, email, telephone, adresse…)
     * + id_role, priorite, date_debut/fin + les clés « à plat » de metadata (caution_type,
     * montant_max, duree_ans, solidaire, source…).
     */
    function bail_cautions_list(PDO $pdo, int $bailId): array {
        if ($bailId <= 0) return [];
        try {
            $st = $pdo->prepare(
                "SELECT r.id AS id_role, r.id_tiers, r.priorite, r.date_debut, r.date_fin, r.metadata AS role_meta,
                        t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale, t.nom_affichage,
                        t.email, t.telephone, t.mobile, t.adresse_ligne1, t.code_postal, t.ville,
                        t.date_naissance, t.lieu_naissance
                 FROM tiers_roles r
                 INNER JOIN tiers t ON t.id = r.id_tiers
                 WHERE r.role_code = 'caution' AND r.objet_type = 'bail' AND r.id_objet = ?
                   AND r.actif = 1
                 ORDER BY r.priorite ASC, r.id ASC");
            $st->execute([$bailId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[bail_cautions_list] '.$e->getMessage()); return []; }

        foreach ($rows as &$r) {
            $meta = json_decode((string)($r['role_meta'] ?? ''), true) ?: [];
            $r['caution_type'] = $meta['caution_type'] ?? null;   // 'solidaire'|'simple'
            $r['montant_max']  = $meta['montant_max']  ?? null;
            $r['duree_ans']    = $meta['duree_ans']    ?? null;
            $r['engagement_texte'] = $meta['engagement_texte'] ?? null;
            $r['source']       = $meta['source'] ?? null;
            unset($r['role_meta']);
        }
        return $rows;
    }
}

if (!function_exists('bail_caution_find_tiers')) {
    /**
     * Anti-doublon : retrouve un tiers existant (même société) correspondant à l'identité fournie.
     * Priorité : email exact → (nom+prenom) exact (insensible casse/espaces). 0 si aucun.
     * On RÉUTILISE le tiers existant (jamais de double saisie), on ne l'écrase pas.
     */
    function bail_caution_find_tiers(PDO $pdo, array $d, ?int $societeId): int {
        // Anti-doublon ROBUSTE d'abord : SIRET/email/tél/nom en tokens ordre-agnostique (capte le nom
        // mono-champ « NOM PRENOM »). Repli sur les matchs exacts ci-dessous si rien.
        if (function_exists('tiers_person_match')) {
            $m = tiers_person_match($pdo, $d, $societeId);
            if ($m > 0) return $m;
        }
        $email = trim((string)($d['email'] ?? ''));
        $nom   = trim((string)($d['nom'] ?? ''));
        $prenom= trim((string)($d['prenom'] ?? ''));
        $rs    = trim((string)($d['raison_sociale'] ?? ''));
        try {
            if ($email !== '') {
                $sql = "SELECT id FROM tiers WHERE email = ? " . ($societeId ? "AND (id_societe = ? OR id_societe IS NULL) " : "") . "ORDER BY id LIMIT 1";
                $args = $societeId ? [$email, $societeId] : [$email];
                $st = $pdo->prepare($sql); $st->execute($args);
                $id = (int)($st->fetchColumn() ?: 0);
                if ($id > 0) return $id;
            }
            if ($rs !== '') {
                $sql = "SELECT id FROM tiers WHERE raison_sociale = ? " . ($societeId ? "AND (id_societe = ? OR id_societe IS NULL) " : "") . "ORDER BY id LIMIT 1";
                $args = $societeId ? [$rs, $societeId] : [$rs];
                $st = $pdo->prepare($sql); $st->execute($args);
                $id = (int)($st->fetchColumn() ?: 0);
                if ($id > 0) return $id;
            }
            if ($nom !== '') {
                // Comparaison insensible casse/espaces via UPPER(TRIM()) (implicit collation gagne, safe prod).
                $sql = "SELECT id FROM tiers
                        WHERE UPPER(TRIM(nom)) = UPPER(TRIM(?)) AND UPPER(TRIM(COALESCE(prenom,''))) = UPPER(TRIM(?))
                        " . ($societeId ? "AND (id_societe = ? OR id_societe IS NULL) " : "") . "ORDER BY id LIMIT 1";
                $args = $societeId ? [$nom, $prenom, $societeId] : [$nom, $prenom];
                $st = $pdo->prepare($sql); $st->execute($args);
                $id = (int)($st->fetchColumn() ?: 0);
                if ($id > 0) return $id;
            }
        } catch (Throwable $e) { error_log('[bail_caution_find_tiers] '.$e->getMessage()); }
        return 0;
    }
}

if (!function_exists('bail_caution_upsert')) {
    /**
     * Crée/relie une caution (tiers) au bail. $data attend :
     *   Identité : nom, prenom, raison_sociale, siren, email, telephone, adresse_ligne1,
     *              code_postal, ville, date_naissance, lieu_naissance, civilite
     *   Lien     : caution_type ('solidaire'|'simple'), montant_max, duree_ans, engagement_texte, source
     *   Optionnel: id_tiers (force la réutilisation d'un tiers précis, ex. sélection manuelle)
     * Réutilise un tiers existant si trouvé (anti-doublon), sinon en crée un. Idempotent sur le LIEN.
     */
    function bail_caution_upsert(PDO $pdo, int $bailId, array $data): array {
        $out = ['id_tiers'=>0, 'id_role'=>0, 'created_tiers'=>false, 'created_link'=>false];
        if ($bailId <= 0) return $out;

        [$societeId, $agenceId] = bail_cautions_soc_agence($pdo, $bailId);

        $nom    = trim((string)($data['nom'] ?? ''));
        $prenom = trim((string)($data['prenom'] ?? ''));
        $rs     = trim((string)($data['raison_sociale'] ?? ''));
        if ($nom === '' && $rs === '') return $out;   // rien d'exploitable

        // 1) Tiers : réutilisation (id_tiers forcé ou anti-doublon) sinon création.
        $idTiers = (int)($data['id_tiers'] ?? 0);
        if ($idTiers <= 0) $idTiers = bail_caution_find_tiers($pdo, $data, $societeId);

        $morale = ($rs !== '' || trim((string)($data['siren'] ?? '')) !== '');
        $tt  = $morale ? 'personne_morale' : 'personne_physique';
        $aff = $morale ? $rs : trim($prenom . ' ' . $nom);
        if ($aff === '') $aff = $nom ?: $rs;

        if ($idTiers <= 0) {
            try {
                $pdo->prepare(
                    "INSERT INTO tiers (id_societe,id_agence,type_tiers,civilite,nom,prenom,raison_sociale,siren,
                                        nom_affichage,email,telephone,adresse_ligne1,code_postal,ville,
                                        date_naissance,lieu_naissance,source_creation,actif,date_creation,date_modification)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'bail_caution',1,NOW(),NOW())")
                    ->execute([
                        $societeId, $agenceId, $tt,
                        ($data['civilite'] ?? null) ?: null,
                        $nom ?: null, $prenom ?: null, $rs ?: null,
                        ($data['siren'] ?? null) ?: null,
                        $aff,
                        ($data['email'] ?? null) ?: null,
                        ($data['telephone'] ?? null) ?: null,
                        ($data['adresse_ligne1'] ?? null) ?: null,
                        ($data['code_postal'] ?? null) ?: null,
                        ($data['ville'] ?? null) ?: null,
                        ($data['date_naissance'] ?? null) ?: null,
                        ($data['lieu_naissance'] ?? null) ?: null,
                    ]);
                $idTiers = (int)$pdo->lastInsertId();
                $out['created_tiers'] = true;
            } catch (Throwable $e) { error_log('[bail_caution_upsert] insert tiers: '.$e->getMessage()); return $out; }
        }
        if ($idTiers <= 0) return $out;
        $out['id_tiers'] = $idTiers;

        // 2) metadata du LIEN (détails propres à ce cautionnement).
        $meta = [];
        foreach (['caution_type','montant_max','duree_ans','engagement_texte','source'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null) $meta[$k] = $data[$k];
        }
        if (empty($meta['source'])) $meta['source'] = 'manuel';
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

        // 3) Lien tiers_roles idempotent (rôle caution scopé au bail).
        try {
            $st = $pdo->prepare("SELECT id FROM tiers_roles
                                 WHERE id_tiers = ? AND role_code = 'caution' AND objet_type = 'bail' AND id_objet = ?
                                 LIMIT 1");
            $st->execute([$idTiers, $bailId]);
            $idRole = (int)($st->fetchColumn() ?: 0);
            if ($idRole > 0) {
                $pdo->prepare("UPDATE tiers_roles SET metadata = ?, actif = 1, date_modification = NOW() WHERE id = ?")
                    ->execute([$metaJson, $idRole]);
            } else {
                $pdo->prepare(
                    "INSERT INTO tiers_roles (id_tiers,role_code,objet_type,id_objet,priorite,metadata,actif,date_creation,date_modification)
                     VALUES (?,'caution','bail',?,0,?,1,NOW(),NOW())")
                    ->execute([$idTiers, $bailId, $metaJson]);
                $idRole = (int)$pdo->lastInsertId();
                $out['created_link'] = true;
            }
            $out['id_role'] = $idRole;
        } catch (Throwable $e) { error_log('[bail_caution_upsert] link: '.$e->getMessage()); }

        return $out;
    }
}

if (!function_exists('bail_analyse_apply_cautions')) {
    /**
     * Reporte les cautions extraites par l'IA ($data['cautions']) en tiers reliés au bail.
     * Chaque caution IA (type_personne, nom, prenom, raison_sociale, adresse, email, tel,
     * type_engagement, montant_max, duree_ans, engagement_texte…) → bail_caution_upsert().
     * Non destructif : ne supprime jamais une caution existante (idempotent sur le lien).
     * Retourne la liste des libellés créés/reliés (pour l'UI).
     */
    function bail_analyse_apply_cautions(PDO $pdo, int $bailId, array $data): array {
        $cautions = $data['cautions'] ?? [];
        if ($bailId <= 0 || !is_array($cautions) || !$cautions) return [];

        $done = [];
        foreach ($cautions as $c) {
            if (!is_array($c)) continue;
            $nom = trim((string)($c['nom'] ?? ''));
            $rs  = trim((string)($c['raison_sociale'] ?? ''));
            if ($nom === '' && $rs === '') continue;

            // Adresse IA « ligne1 CP VILLE » → on la pose telle quelle en adresse_ligne1 (découpe
            // fine non nécessaire ici ; le tiers reste éditable). CP/ville extraits si évidents.
            $adr = trim((string)($c['adresse'] ?? ''));
            $cp = ''; $ville = '';
            if ($adr !== '' && preg_match('/^(.*?)\s*\b(\d{5})\b\s+(.+)$/u', $adr, $m)) {
                $adr = trim($m[1], ' ,'); $cp = $m[2]; $ville = trim($m[3]);
            }

            $eng = strtolower(trim((string)($c['type_engagement'] ?? '')));
            $cautionType = (strpos($eng, 'simple') !== false) ? 'simple' : ($eng !== '' ? 'solidaire' : null);

            $res = bail_caution_upsert($pdo, $bailId, [
                'civilite'         => $c['civilite'] ?? null,
                'nom'              => $nom,
                'prenom'           => $c['prenom'] ?? null,
                'raison_sociale'   => $rs ?: null,
                'siren'            => $c['siren'] ?? null,
                'email'            => $c['email'] ?? null,
                'telephone'        => $c['telephone'] ?? null,
                'adresse_ligne1'   => $adr ?: null,
                'code_postal'      => $cp ?: null,
                'ville'            => $ville ?: null,
                'date_naissance'   => $c['date_naissance'] ?? null,
                'lieu_naissance'   => $c['lieu_naissance'] ?? null,
                'caution_type'     => $cautionType,
                'montant_max'      => is_numeric($c['montant_max'] ?? null) ? (float)$c['montant_max'] : null,
                'duree_ans'        => is_numeric($c['duree_ans'] ?? null) ? (int)$c['duree_ans'] : null,
                'engagement_texte' => $c['engagement_texte'] ?? null,
                'source'           => 'extraction_bail',
            ]);
            if (($res['id_tiers'] ?? 0) > 0) {
                $done[] = trim(($c['prenom'] ?? '') . ' ' . ($nom ?: $rs)) ?: ($nom ?: $rs);
            }
        }
        return $done;
    }
}

if (!function_exists('bail_caution_detach')) {
    /** Retire une caution DU BAIL (désactive le lien) — le tiers reste (réutilisable ailleurs). */
    function bail_caution_detach(PDO $pdo, int $bailId, int $idTiers): bool {
        if ($bailId <= 0 || $idTiers <= 0) return false;
        try {
            $st = $pdo->prepare("UPDATE tiers_roles SET actif = 0, date_modification = NOW()
                                 WHERE id_tiers = ? AND role_code = 'caution' AND objet_type = 'bail' AND id_objet = ?");
            $st->execute([$idTiers, $bailId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) { error_log('[bail_caution_detach] '.$e->getMessage()); return false; }
    }
}

if (!function_exists('bail_caution_mention_ctx')) {
    /**
     * LA MENTION DE L'ART. 2297, PRÊTE À ÊTRE APPOSÉE — ou le refus motivé.
     *
     * ⚠️🔥 Pourquoi ce helper existe plutôt qu'un bloc dans la page de signature :
     * un cautionnement mal formé est **NUL** (art. 2297 C. civ.), et une nullité ne se
     * découvre qu'au contentieux, des années plus tard, quand le bailleur en a besoin.
     * Le calcul devait donc être vérifiable en ligne de commande, hors navigateur.
     *
     * ── CE QUI REND UN CAUTIONNEMENT VALABLE ────────────────────────────────────────
     * Depuis l'ordonnance 2021-1192 (en vigueur au 01/01/2022), la caution personne
     * physique appose elle-même une mention comportant **le montant en toutes lettres ET
     * en chiffres**, et — pour être solidaire — la **renonciation aux bénéfices de
     * discussion et de division**. Sans plafond chiffré, il n'y a pas de mention possible :
     * on REFUSE de présenter l'écran plutôt que d'écrire « …… » dans un acte.
     *
     * ⚠️ Le plafond saisi par l'agent (`tiers_roles.metadata.montant_max`) l'emporte
     * TOUJOURS sur le calcul : celui-ci est un défaut raisonnable, pas une décision prise
     * à sa place. Règle de calcul : cf. `cautionnement_plafond()`.
     *
     * ⚠️ Le débiteur est NOMMÉ. En colocation, l'art. 8-1 VI impose de désigner le
     * colocataire garanti à peine de nullité ; hors colocation, nommer reste ce que fait
     * MODELO. Si le bail porte plusieurs preneurs et que la caution ne précise pas lequel
     * elle garantit (`metadata.garantit_id_tiers`), on refuse : deviner reviendrait à
     * choisir le débiteur à sa place.
     *
     * @return array{ok:bool, raison:string, mention:?array, plafond:float, source_plafond:string}
     */
    function bail_caution_mention_ctx(PDO $pdo, int $bailId, int $idTiersCaution): array
    {
        $ko = static fn(string $r): array => ['ok'=>false,'raison'=>$r,'mention'=>null,'plafond'=>0.0,
                                              'source_plafond'=>'','alerte'=>'','duree_ans'=>0,'duree_source'=>''];

        require_once __DIR__ . '/bail_cautionnement_acte.php';
        require_once __DIR__ . '/bail_locataires.php';

        // 1. La caution et ses conditions propres (elles qualifient le LIEN, pas la personne).
        $cau = null;
        foreach (bail_cautions_list($pdo, $bailId) as $c) {
            if ((int)$c['id_tiers'] === $idTiersCaution) { $cau = $c; break; }
        }
        if (!$cau) return $ko('cette caution n\'est plus rattachée au bail');

        // Une personne MORALE ne recopie pas la mention : elle n'est pas visée par l'art. 2297.
        $typeCaution = ((string)($cau['type_tiers'] ?? '') === 'personne_morale') ? 'morale' : 'physique';
        if (!cautionnement_mention_requise($typeCaution)) {
            return $ko('caution personne morale : la mention de l\'art. 2297 ne lui est pas applicable');
        }

        // 2. Le bail : nature, loyer tout compris, durée, TVA.
        try {
            $st = $pdo->prepare("SELECT bail_nature, loyer_mensuel_hc, charges_mensuelles, duree_mois,
                                        tva_applicable, provision_tf_mensuelle
                                   FROM bien_baux WHERE id = ? LIMIT 1");
            $st->execute([$bailId]);
            $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Colonnes optionnelles absentes : on retente au minimum vital plutôt que d'échouer.
            try {
                $st = $pdo->prepare("SELECT bail_nature, loyer_mensuel_hc, charges_mensuelles, duree_mois FROM bien_baux WHERE id = ? LIMIT 1");
                $st->execute([$bailId]); $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) { return $ko('bail illisible : ' . $e2->getMessage()); }
        }
        if (!$b) return $ko('bail introuvable');

        $nature   = (string)($b['bail_nature'] ?? '');
        $estComm  = !in_array($nature, ['habitation','meuble','mobilite','civil','meuble_touristique'], true);
        $natureM  = $estComm ? 'commercial' : 'habitation';
        $ttc      = $estComm && !empty($b['tva_applicable']);

        /* Assiette = ce que le locataire doit RÉELLEMENT chaque mois : loyer + charges
           (+ provision de taxe foncière quand elle est appelée à part, cas commercial).
           Garantir le seul loyer nu laisserait les charges hors du plafond. */
        $loyerCC = (float)($b['loyer_mensuel_hc'] ?? 0)
                 + (float)($b['charges_mensuelles'] ?? 0)
                 + (float)($b['provision_tf_mensuelle'] ?? 0);
        if ($loyerCC <= 0) return $ko('le loyer du bail n\'est pas renseigné : le plafond ne peut pas être calculé');

        /* ── DURÉE DE L'ENGAGEMENT : LA SAISIE DE L'AGENT FAIT LOI ──────────────────
           ⚠️🔥 Ces règles sont des DÉFAUTS, jamais des plafonds imposés. Une première
           version écrêtait toute durée à 9 ans, y compris celle que l'agent venait de
           saisir délibérément en rédigeant le projet — un choix métier annulé en
           silence, exactement ce qu'on s'interdit. Corrigé le 22/08 à sa demande.
           On n'écrête donc QUE le repli automatique (celui déduit du bail) ; une durée
           explicitement saisie sur la fiche caution passe telle quelle, et l'écran la
           signale plutôt que de la corriger. */
        $dureeSaisie = (int)($cau['duree_ans'] ?? 0);
        $alerte = '';
        if ($dureeSaisie > 0) {
            $dureeAns = $dureeSaisie;
            if ($dureeAns > CAUTION_DUREE_MAX_ANS) {
                /* En commercial, le renouvellement crée un bail NOUVEAU qui éteint le
                   cautionnement sans clause d'extension expresse : au-delà de 9 ans on
                   écrit une couverture qui n'existera pas. On le DIT, on ne le corrige pas. */
                $alerte = 'durée de ' . $dureeAns . ' ans saisie : au-delà de ' . CAUTION_DUREE_MAX_ANS
                        . ' ans, le renouvellement éteint le cautionnement sans clause d\'extension expresse';
            }
        } else {
            $dureeAns = min((int)ceil(((int)($b['duree_mois'] ?? 0)) / 12), CAUTION_DUREE_MAX_ANS);
        }
        /* Le message NOMME les deux endroits où corriger. « Durée non renseignée » tout
           court laisse chercher : la durée peut venir de la fiche caution OU du bail, et
           quand les deux sont vides (bail 1192 : `duree_mois` NULL) rien ne l'indiquait. */
        if ($dureeAns <= 0) {
            return $ko('durée de l\'engagement absente — renseigner « an(s) » sur la caution, '
                     . 'ou la durée du bail dans « Modifier le projet »');
        }

        // 3. Le plafond : saisi > calculé. Le calcul est un défaut, jamais une décision.
        $saisi   = $cau['montant_max'] !== null && $cau['montant_max'] !== '' ? (float)$cau['montant_max'] : 0.0;
        $plafond = $saisi > 0 ? (float)(int)round($saisi) : cautionnement_plafond($loyerCC, $dureeAns);
        if ($plafond <= 0) return $ko('aucun plafond d\'engagement déterminable');

        // 4. LE DÉBITEUR GARANTI, nommé.
        $locs = bail_locataires_list($pdo, $bailId);
        $nom  = static function (array $t): string {
            $n = trim((string)($t['nom_affichage'] ?? '')) ?: trim((string)($t['raison_sociale'] ?? ''))
               ?: trim(($t['prenom'] ?? '') . ' ' . ($t['nom'] ?? ''));
            return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
        };
        $cible = (int)($cau['garantit_id_tiers'] ?? 0);
        $debiteur = '';
        if ($cible > 0) {
            foreach ($locs as $l) { if ((int)$l['id_tiers'] === $cible) { $debiteur = $nom($l); break; } }
            if ($debiteur === '') return $ko('le colocataire garanti n\'est plus au bail');
        } elseif (count($locs) === 1) {
            $debiteur = $nom($locs[0]);
        } elseif (count($locs) > 1) {
            /* ⚠️ art. 8-1 VI : en colocation, le colocataire garanti doit être NOMMÉ à peine
               de nullité. Choisir pour l'agent serait engager la caution envers quelqu'un
               qu'elle n'a peut-être pas voulu garantir. */
            return $ko('bail à plusieurs preneurs : préciser lequel cette caution garantit (art. 8-1 VI)');
        } else {
            return $ko('aucun preneur identifié sur ce bail');
        }
        if ($debiteur === '') return $ko('le nom du preneur garanti est vide');

        return [
            'ok'             => true,
            'raison'         => '',
            'alerte'         => $alerte,   // non bloquant : à afficher à l'agent, jamais au signataire
            'duree_ans'      => $dureeAns,
            'duree_source'   => $dureeSaisie > 0 ? 'saisie' : 'déduite du bail',
            'mention'        => cautionnement_mention_2297($natureM, $plafond, $ttc, $debiteur, cautionnement_duree_label($dureeAns)),
            'plafond'        => $plafond,
            'source_plafond' => $saisi > 0
                ? 'saisi sur la fiche caution'
                : 'calculé : ' . number_format($loyerCC, 0, ',', ' ') . ' €/mois CC × '
                  . min(CAUTION_PLAFOND_MOIS, $dureeAns * 12) . ' mois',
        ];
    }
}
