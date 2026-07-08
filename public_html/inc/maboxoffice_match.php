<?php
declare(strict_types=1);
/**
 * inc/maboxoffice_match.php — Reconnaissance d'entité + métier (gratuit, sans IA).
 *
 * À partir du texte OCR d'un document, tente de reconnaître une entité MBI DÉJÀ CONNUE
 * (immeuble, propriétaire, locataire) pour proposer une attribution directe, et devine
 * le métier concerné (syndic / gestion / transaction). Non décisif : le user valide.
 *
 * Méthode : on normalise le texte, puis on cherche la présence des libellés connus
 * (chargés une fois et mis en cache) — le plus long libellé trouvé gagne.
 */

/** Normalise pour comparaison : MAJUSCULES, sans accents, alphanum + espaces. */
function mbo_norm(string $s): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Construit (et met en cache) l'index des entités connues d'un tenant.
 * @return array{IMB:array,TIERS:array,BAIL:array} listes de ['id','label','needle']
 */
function mbo_entity_index(PDO $pdo, int $tenant): array
{
    static $cache = [];
    if (isset($cache[$tenant])) return $cache[$tenant];

    $idx = ['IMB' => [], 'TIERS' => [], 'BAIL' => []];

    // Immeubles : nom + adresse (needle = le plus distinctif, longueur >= 6).
    foreach ($pdo->query("SELECT id, nom_immeuble, adresse_1 FROM immeubles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ([$r['nom_immeuble'], $r['adresse_1']] as $cand) {
            $n = mbo_norm((string)$cand);
            if (strlen($n) >= 6 && !preg_match('/^[0-9 ]+$/', $n)) {
                $idx['IMB'][] = ['id' => (int)$r['id'], 'label' => trim((string)($r['nom_immeuble'] ?: $r['adresse_1'])), 'needle' => $n];
            }
        }
    }
    // Propriétaires : société ou nom.
    foreach ($pdo->query("SELECT id, nom, societe FROM proprietaires")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = trim((string)($r['societe'] ?: $r['nom']));
        $n = mbo_norm($label);
        if (strlen($n) >= 6) $idx['TIERS'][] = ['id' => (int)$r['id'], 'label' => $label, 'needle' => $n];
    }
    // Locataires (baux) : raison sociale ou nom.
    foreach ($pdo->query("SELECT id, id_bien, COALESCE(NULLIF(locataire_raison_sociale,''), locataire_nom) AS nom FROM bien_baux")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = mbo_norm((string)$r['nom']);
        if (strlen($n) >= 6) $idx['BAIL'][] = ['id' => (int)$r['id'], 'label' => trim((string)$r['nom']), 'needle' => $n, 'id_bien' => (int)$r['id_bien']];
    }

    return $cache[$tenant] = $idx;
}

/**
 * Liste d'exclusion : identités PROPRES de la régie/agences (entête des courriers).
 * Évite de reconnaître l'adresse/nom de l'émetteur au lieu de la vraie entité du doc.
 * @return string[] fragments normalisés distinctifs à ignorer.
 */
function mbo_own_denylist(PDO $pdo): array
{
    static $deny = null;
    if ($deny !== null) return $deny;
    $deny = ['LOCA IMMO', 'REGIE EMERY', 'EMERY IMMO', 'EMERY IMMOBILIER', 'SERVAJEAN', 'ICS', 'GALIAN'];
    $stop = ['RUE','BD','BOULEVARD','PLACE','AVENUE','AV','ALLEE','ALLEE','IMPASSE','CHEMIN','TER','BIS','DE','DU','DES','LA','LE','LES'];
    foreach ($pdo->query("SELECT nom_agence, adresse_1 FROM agences")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $deny[] = mbo_norm((string)$r['nom_agence']);
        // Cœur distinctif de l'adresse agence : mots hors numéro et types de voie.
        $words = array_filter(explode(' ', mbo_norm((string)$r['adresse_1'])), fn($w) => $w !== '' && !ctype_digit($w) && !in_array($w, $stop, true));
        $core = implode(' ', $words);
        if (strlen($core) >= 5) $deny[] = $core; // ex. "YVES FARGE", "MARECHAL FOCH"
    }
    return $deny = array_values(array_unique(array_filter($deny, fn($s) => strlen($s) >= 4)));
}

/** Un needle correspond-il à une identité propre (à ignorer) ? */
function mbo_is_own(string $needle, array $deny): bool
{
    foreach ($deny as $d) {
        if ($d !== '' && (str_contains($needle, $d) || str_contains($d, $needle))) return true;
    }
    return false;
}

/**
 * Reconnaissance FIABLE par CODE : les documents de gestion (ICS/Loca Immo) portent le
 * code immeuble à 8 chiffres (code_crg, ex. 01330224). L'OCR peut le couper (« 0133 »
 * « 0224 ») → on cherche dans le FLUX de chiffres du texte. Bien plus sûr que les noms.
 * @return array{type:?string,id:?int,label:?string,score:int}|null
 */
function mbo_match_by_code(PDO $pdo, string $text): ?array
{
    // Tokens numériques isolés du texte (le code est souvent coupé : « 0133 » … « 0224 »).
    preg_match_all('/\b\d{3,8}\b/', $text, $mm);
    $tokens = array_flip($mm[0]);
    if (!$tokens) return null;

    static $codes = null;
    if ($codes === null) {
        $codes = $pdo->query("SELECT id, code_crg, nom_immeuble, adresse_1
                              FROM immeubles WHERE code_crg REGEXP '^[0-9]{8}$' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($codes as $c) {
        $code = (string)$c['code_crg'];              // ex. 01330224
        $p4 = substr($code, 0, 4);                    // mandat/propriétaire (0133)
        $s4 = substr($code, 4, 4);                    // n° immeuble (0224)
        // Code complet en un token, OU préfixe ET suffixe présents comme tokens isolés.
        if (isset($tokens[$code]) || (isset($tokens[$p4]) && isset($tokens[$s4]))) {
            return ['type'=>'IMB', 'id'=>(int)$c['id'],
                    'label'=>trim((string)($c['nom_immeuble'] ?: $c['adresse_1'])) ?: ('Immeuble ' . $code),
                    'score'=>100];
        }
    }
    return null;
}

/**
 * Une fois l'IMMEUBLE identifié, retrouve le BAIL concerné parmi SES locataires,
 * par correspondance de tokens (le nom peut être dans un autre ordre dans le doc).
 * Bien plus fiable que le matching global. @return match BAIL ou null.
 */
function mbo_best_bail_of_immeuble(PDO $pdo, int $idImmeuble, string $normText): ?array
{
    if ($idImmeuble <= 0) return null;
    $stop = ['MONSIEUR','MADAME','MME','MLLE','SCI','SARL','SAS','INDIVISION','MONSIEUR','ET','LA','LE','LES','DE','DU'];
    $best = null; $bestScore = 0;
    foreach (mbo_immeuble_baux($pdo, $idImmeuble) as $b) {
        $words = array_filter(explode(' ', mbo_norm((string)$b['locataire'])), fn($w) => strlen($w) >= 4 && !in_array($w, $stop, true));
        if (!$words) continue;
        $score = 0; $hasLong = false;
        foreach ($words as $w) {
            if (str_contains($normText, ' ' . $w . ' ') || str_contains($normText, ' ' . $w)) { $score += strlen($w); if (strlen($w) >= 5) $hasLong = true; }
        }
        // au moins un token significatif (>=5) présent, priorité aux baux actifs.
        if ($hasLong && $score > $bestScore) {
            $bestScore = $score;
            $best = ['type'=>'BAIL', 'id'=>(int)$b['bail_id'], 'label'=>trim((string)$b['locataire']), 'score'=>90 + $score];
        }
    }
    return $best;
}

/**
 * Cherche l'entité connue la mieux reconnue dans le texte (hors identités de la régie).
 * Priorité : locataire de l'immeuble identifié → code immeuble → locataire nommé → nom/adresse.
 * @return array{type:?string,id:?int,label:?string,score:int}
 */
function mbo_match_entity(PDO $pdo, int $tenant, string $text): array
{
    $t = ' ' . mbo_norm($text) . ' ';
    if (strlen($t) < 8) return ['type'=>null,'id'=>null,'label'=>null,'score'=>0];
    $idx  = mbo_entity_index($pdo, $tenant);
    $deny = mbo_own_denylist($pdo);

    // 1) Code immeuble = reconnaissance certaine (prioritaire sur les noms).
    $byCode = mbo_match_by_code($pdo, $text);
    // 1bis) Immeuble certain → on tente d'affiner sur SON locataire (plus fiable).
    if ($byCode) {
        $bail = mbo_best_bail_of_immeuble($pdo, (int)$byCode['id'], $t);
        if ($bail) return $bail;
    }

    // Meilleur match GLOBAL + meilleur match par type (pour prioriser le locataire).
    $best = ['type'=>null,'id'=>null,'label'=>null,'score'=>0];
    $byType = [];
    foreach ($idx as $type => $list) {
        foreach ($list as $e) {
            $needle = $e['needle'];
            if ($needle === '') continue;
            if (mbo_is_own($needle, $deny)) continue; // ignore l'entête régie/agence
            $len = strlen($needle);
            if ($len <= ($byType[$type]['score'] ?? 0)) continue;
            if (str_contains($t, ' ' . $needle . ' ') || str_contains($t, $needle)) {
                $byType[$type] = ['type'=>$type, 'id'=>$e['id'], 'label'=>$e['label'], 'score'=>$len];
                if ($len > $best['score']) $best = $byType[$type];
            }
        }
    }
    // Le document concerne d'abord le LOCATAIRE quand un bail fiable est reconnu
    // (nom >= 8 car.) → chaîne complète Locataire → Bien → Immeuble → Propriétaire.
    if (isset($byType['BAIL']) && $byType['BAIL']['score'] >= 8) return $byType['BAIL'];
    // Sinon le code immeuble (certain) prime sur un simple match de nom/adresse.
    if ($byCode) return $byCode;
    return $best;
}

/** Devine le métier : syndic | gestion | transaction | '' (indéterminé). */
function mbo_guess_metier(string $text, string $docType = ''): string
{
    $h = ' ' . mb_strtolower($text) . ' ';
    $has = fn(array $kw) => (bool)array_filter($kw, fn($k) => str_contains($h, $k));

    // Priorité par type de document déjà deviné.
    if (in_array($docType, ['bulletin_paie','note_frais','justificatif'], true)) return 'rh';
    if ($docType === 'appel_fonds') return 'syndic';
    if (in_array($docType, ['avis_echeance','quittance','bail','crg'], true)) return 'gestion';

    // RH : salaire / paie / frais / congés (souvent via l'objet du mail + expéditeur salarié).
    if ($has(['salaire', 'bulletin de paie', 'fiche de paie', 'note de frais', 'frais kilometriques', 'frais kilométriques',
              'indemnite kilometrique', 'indemnité kilométrique', 'justificatifs salaire', 'justificatif de salaire',
              ' paie ', 'conges payes', 'congés payés', 'urssaf', 'bulletin de salaire'])) return 'rh';
    if ($has(['assemblee generale', 'assemblée générale', 'copropriete', 'copropriété', 'syndic', 'appel de fonds',
              'tantiemes', 'tantièmes', 'conseil syndical', 'proces-verbal', 'procès-verbal', 'ravalement', 'coprop'])) return 'syndic';
    if ($has(['compromis', 'promesse de vente', 'mandat de vente', "offre d'achat", 'acquereur', 'acquéreur',
              'acte authentique', 'avant-contrat', 'avant contrat'])) return 'transaction';
    if ($has(['loyer', 'bail', "avis d'échéance", 'quittance', 'locataire', 'regularisation des charges',
              'régularisation des charges', 'preavis', 'préavis', 'etat des lieux', 'état des lieux', 'gestion locative'])) return 'gestion';
    return '';
}

/** Libellé lisible du métier. */
function mbo_metier_label(string $m): string
{
    return ['syndic'=>'Syndic','gestion'=>'Gestion','transaction'=>'Transaction'][$m] ?? '';
}

/**
 * Liens GED (ged_document_links) déduits de l'entité reconnue = la chaîne validée.
 * @return array liste de ['entity_type'=>'BAIL|BIEN|IMB|TIERS','entity_id'=>int,'relation_type'=>...]
 */
function mbo_ged_links_for_entity(PDO $pdo, ?string $type, ?int $id): array
{
    if (!$type || !$id) return [];
    if ($type === 'EMP') return [['entity_type'=>'EMP', 'entity_id'=>(int)$id, 'relation_type'=>'main', 'is_validated'=>1]];
    if ($type === 'TRS') return [['entity_type'=>'TIERS', 'entity_id'=>(int)$id, 'relation_type'=>'main', 'is_validated'=>1]]; // tiers direct (moderne)
    $idBail = $idBien = $idImm = $idProp = 0;
    if ($type === 'BAIL') {
        $st = $pdo->prepare("SELECT bx.id, bx.id_bien FROM bien_baux bx WHERE bx.id=?");
        $st->execute([$id]); if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $idBail = (int)$r['id']; $idBien = (int)$r['id_bien']; }
    } elseif ($type === 'BIEN') { $idBien = (int)$id; }
    elseif ($type === 'IMB')  { $idImm  = (int)$id; }
    elseif ($type === 'TIERS'){ $idProp = (int)$id; }

    if ($idBien > 0) {
        $st = $pdo->prepare("SELECT id_immeuble, id_proprietaire FROM biens WHERE id=?");
        $st->execute([$idBien]); if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $idImm = $idImm ?: (int)$r['id_immeuble']; $idProp = $idProp ?: (int)$r['id_proprietaire']; }
    }
    if ($idImm > 0 && !$idProp) {
        $st = $pdo->prepare("SELECT id_proprietaire FROM immeubles WHERE id=?");
        $st->execute([$idImm]); $idProp = (int)$st->fetchColumn();
    }
    $idTiers = 0;
    if ($idProp > 0) {
        $st = $pdo->prepare("SELECT id_tiers FROM proprietaires WHERE id=?");
        $st->execute([$idProp]); $idTiers = (int)$st->fetchColumn();
    }

    $links = [];
    if ($idTiers > 0) $links[] = ['entity_type'=>'TIERS', 'entity_id'=>$idTiers, 'relation_type'=>'main', 'is_validated'=>1];
    if ($idImm  > 0)  $links[] = ['entity_type'=>'IMB',  'entity_id'=>$idImm,  'relation_type'=>'reference'];
    if ($idBien > 0)  $links[] = ['entity_type'=>'BIEN', 'entity_id'=>$idBien, 'relation_type'=>'reference'];
    if ($idBail > 0)  $links[] = ['entity_type'=>'BAIL', 'entity_id'=>$idBail, 'relation_type'=>'reference'];
    return $links;
}

/** Code CONTRÔLÉ du glossaire (société/agence/immeuble/…). '' si absent. */
function mbo_glossaire_code(PDO $pdo, string $category, int $entityId): string
{
    if ($entityId <= 0) return '';
    $st = $pdo->prepare("SELECT code FROM ged_codes_glossaire WHERE category=? AND entity_id=? AND is_active=1 ORDER BY id LIMIT 1");
    $st->execute([$category, $entityId]);
    return (string)$st->fetchColumn();
}
/**
 * GARANTIT un code glossaire pour une ENTITÉ (société/agence/immeuble/…).
 * Le retourne s'il existe ; sinon le GÉNÈRE une seule fois, le persiste, et le renvoie.
 * → Aucun travail manuel par document. NE concerne JAMAIS les types (liste fermée).
 */
function mbo_glossaire_ensure(PDO $pdo, string $category, int $entityId, string $entityTable, string $label, int $tenant, ?int $uid = null): string
{
    if ($entityId <= 0) return '';
    $code = mbo_glossaire_code($pdo, $category, $entityId);
    if ($code !== '') return $code;

    // Génère un code compact et déterministe à partir du libellé (unique dans la catégorie).
    $base = mbo_seg($label); $base = str_replace('-', '', $base);
    if ($base === '') $base = strtoupper($category);
    $base = substr($base, 0, 8);
    $code = $base;
    $chk = $pdo->prepare("SELECT 1 FROM ged_codes_glossaire WHERE category=? AND code=? LIMIT 1");
    $n = 1;
    while (true) {
        $chk->execute([$category, $code]);
        if (!$chk->fetchColumn()) break;
        $n++; $code = substr($base, 0, 6) . $n; // suffixe anti-collision
    }
    try {
        $pdo->prepare("INSERT INTO ged_codes_glossaire (tenant_id, category, entity_table, entity_id, code, label, is_locked, is_active, created_by, created_at)
                       VALUES (?,?,?,?,?,?,0,1,?,NOW())")
            ->execute([$tenant, $category, $entityTable, $entityId, $code, mb_substr($label,0,190), $uid]);
    } catch (Throwable) { /* course concurrente : on relit */ $code = mbo_glossaire_code($pdo, $category, $entityId) ?: $code; }
    return $code;
}

/** Métier → code contrôlé metier_n1. */
function mbo_metier_n1_code(string $metier): string
{
    return ['gestion'=>'GELO', 'syndic'=>'SYNDIC', 'transaction'=>'TRANSA', 'rh'=>'RH', 'compta'=>'COMPTA', 'fournisseur'=>'FOURNI'][$metier] ?? '';
}

/**
 * Déduit le MÉTIER quand il n'a pas été reconnu, à partir du type de doc et de l'entité.
 * Évite un métier vide dans le nom GED (ex. un BAIL → gestion locative).
 */
function mbo_infer_metier(string $typeDoc, string $entityType): string
{
    $t = strtolower($typeDoc);
    if (in_array($t, ['bulletin_paie','note_frais','justificatif'], true)) return 'rh';
    if (in_array($t, ['bail','bail_signe','bail_projet','avis_echeance','quittance','crg','mandat_gestion'], true)) return 'gestion';
    if (in_array($t, ['appel_fonds','pv_ag'], true)) return 'syndic';
    if (in_array($t, ['facture','devis'], true)) return 'fournisseur';
    if (in_array($t, ['mandat'], true)) return 'transaction';
    // repli via l'entité liée : par défaut GESTION (le syndic est affiné par la réf immeuble).
    if ($entityType === 'EMP') return 'rh';
    if (in_array($entityType, ['BAIL','BIEN','IMB'], true)) return 'gestion';
    return '';
}

/**
 * Métier d'un immeuble selon sa référence : SYNDIC si réf 4 chiffres 1000–3999
 * (copropriété gérée en syndic), sinon GESTION. Règle métier figée.
 */
function mbo_metier_from_immeuble(PDO $pdo, int $idImm): string
{
    if ($idImm <= 0) return 'gestion';
    try { $st=$pdo->prepare("SELECT reference_immeuble FROM immeubles WHERE id=?"); $st->execute([$idImm]); $ref=trim((string)$st->fetchColumn()); }
    catch (Throwable) { return 'gestion'; }
    if (preg_match('/^\d{4}$/', $ref) && (int)$ref >= 1000 && (int)$ref <= 3999) return 'syndic';
    return 'gestion';
}

/**
 * Reconnaît le SALARIÉ concerné par un document RH (nom du collaborateur dans le texte).
 * @return array{id:int,label:string,id_societe:int,id_agence:int,score:int}
 */
function mbo_match_employee(PDO $pdo, string $normText): array
{
    static $users = null;
    if ($users === null) $users = $pdo->query("SELECT id, prenom, nom, id_societe, id_agence FROM users WHERE nom IS NOT NULL AND nom<>''")->fetchAll(PDO::FETCH_ASSOC);
    $best = ['id'=>0,'label'=>'','id_societe'=>0,'id_agence'=>0,'score'=>0];
    foreach ($users as $u) {
        $nom = mbo_norm((string)$u['nom']); $prenom = mbo_norm((string)$u['prenom']);
        if (strlen($nom) < 4) continue;
        if (str_contains($normText, ' ' . $nom . ' ') || str_contains($normText, ' ' . $nom)) {
            $hasPrenom = $prenom !== '' && str_contains($normText, $prenom);
            if (!$hasPrenom && strlen($nom) < 6) continue; // nom court seul = trop risqué
            $score = strlen($nom) + ($hasPrenom ? strlen($prenom) + 10 : 0);
            if ($score > $best['score']) $best = ['id'=>(int)$u['id'], 'label'=>trim($u['prenom'].' '.$u['nom']), 'id_societe'=>(int)$u['id_societe'], 'id_agence'=>(int)$u['id_agence'], 'score'=>$score];
        }
    }
    return $best;
}

/**
 * Contexte EMAIL d'un document capté (expéditeur, objet, corps).
 * Souvent PLUS parlant que l'OCR de la pièce jointe → à intégrer dans l'analyse.
 * @return array{from:string,subject:string,body:string}
 */
function mbo_email_context(PDO $pdo, int $tenant, int $docId): array
{
    $out = ['from'=>'', 'subject'=>'', 'body'=>''];
    if ($docId <= 0) return $out;
    $st = $pdo->prepare("SELECT from_addr, subject, graph_message_id FROM mbo_mail_captures WHERE tenant_id=? AND fluxbox_document_id=? LIMIT 1");
    $st->execute([$tenant, $docId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return $out;
    $out['from'] = (string)$c['from_addr']; $out['subject'] = (string)$c['subject'];
    if (!empty($c['graph_message_id'])) {
        $m = $pdo->prepare("SELECT body FROM mbo_mail_messages WHERE tenant_id=? AND message_key=? LIMIT 1");
        $m->execute([$tenant, $c['graph_message_id']]);
        $out['body'] = (string)$m->fetchColumn();
    }
    return $out;
}

/**
 * Reconnaît l'EXPÉDITEUR d'un email (users d'abord = salarié, sinon tiers).
 * Un email interne (salarié) oriente vers le métier RH (frais, paie…).
 * @return array{kind:?string,id:?int,label:?string} kind = 'user'|'tiers'|null
 */
function mbo_match_sender(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) return ['kind'=>null,'id'=>null,'label'=>null];
    // 1) Salarié (users) — email pro/perso/principal.
    $st = $pdo->prepare("SELECT id, TRIM(CONCAT_WS(' ', prenom, nom)) AS nom FROM users
                         WHERE LOWER(email)=? OR LOWER(email_pro)=? OR LOWER(email_perso)=? LIMIT 1");
    $st->execute([$email, $email, $email]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['kind'=>'user', 'id'=>(int)$r['id'], 'label'=>trim((string)$r['nom']) ?: $email];
    // 2) Tiers (fournisseur, contact…).
    $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(raison_sociale,''), TRIM(CONCAT_WS(' ',prenom,nom))) AS nom FROM tiers WHERE LOWER(email)=? LIMIT 1");
    $st->execute([$email]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['kind'=>'tiers', 'id'=>(int)$r['id'], 'label'=>trim((string)$r['nom']) ?: $email];
    return ['kind'=>null,'id'=>null,'label'=>null];
}
/** Type MaBoxOffice → code CONTRÔLÉ ged_document_types (sinon AUTRE). Normalisation = clé de l'extraction en masse. */
function mbo_type_ged_code(PDO $pdo, string $mboType): string
{
    if ($mboType === '') return '';
    // Map code (stable) → abréviation d'affichage GED (abbr) si définie, sinon le code lui-même.
    static $map = null;
    if ($map === null) {
        $map = [];
        try { $rows = $pdo->query("SELECT code, abbr FROM ged_document_types WHERE actif=1")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable) { $rows = array_map(fn($c)=>['code'=>$c,'abbr'=>null], $pdo->query("SELECT code FROM ged_document_types WHERE actif=1")->fetchAll(PDO::FETCH_COLUMN)); }
        foreach ($rows as $r) {
            $ab = trim((string)($r['abbr'] ?? ''));
            $map[strtoupper((string)$r['code'])] = $ab !== '' ? strtoupper($ab) : strtoupper((string)$r['code']);
        }
    }
    return $map[strtoupper($mboType)] ?? 'AUTRE';
}

/** Slug d'UN segment de nom (MAJUSCULES, sans accents, espaces→tiret). '' reste ''. */
function mbo_seg(string $s): string
{
    // Translittération accents FR fiable (iconv//TRANSLIT est instable sous Windows).
    $accents = ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                'î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
                'ç'=>'c','ñ'=>'n','œ'=>'oe','æ'=>'ae'];
    $s = strtr($s, $accents);
    $s = strtr($s, array_change_key_case($accents, CASE_UPPER)); // au cas où déjà en majuscules accentuées
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}

/**
 * Nom GED MaBoxOffice à 11 POSITIONS FIXES (cases vides conservées → « __ ») :
 * SOCIETE_AGENCE_METIER_PRO_IMMEUBLE_BIEN_BAIL_TYPEDOC_N+1_LIBELLE_DATE.ext
 * Les IDs, eux, vont dans ged_document_links (vérité technique).
 */
/**
 * Reconnaissance complète d'un document à partir de son texte OCR + contexte email,
 * puis persistance (type, métier, entité, dates, ocr_text, mbo_ocr_at).
 * Réutilisable : extraction gratuite (pdfparser) OU texte OCR Mindee (scans).
 * Nécessite maboxoffice_ocr.php chargé. @return array stats.
 */
function mbo_recognize_and_store(PDO $pdo, int $tenant, array $d, string $ocr): array
{
    $em = mbo_email_context($pdo, $tenant, (int)$d['id']);
    $header = ($em['from'] || $em['subject'] || $em['body']) ? ("De: {$em['from']}\nObjet: {$em['subject']}\n{$em['body']}\n\n") : '';
    $text = $header . $ocr;
    $sender = $em['from'] ? mbo_match_sender($pdo, $em['from']) : ['kind'=>null,'id'=>null,'label'=>null];

    $type   = mbo_guess_type($text, (string)$d['fichier_nom']);
    $metier = mbo_guess_metier($text, $type);
    if ($metier === '' && $sender['kind'] === 'user') $metier = 'rh';
    $ent = mbo_match_entity($pdo, $tenant, $text);
    if (!$ent['type'] && $sender['kind'] === 'tiers') $ent = ['type'=>'TIERS','id'=>$sender['id'],'label'=>$sender['label'],'score'=>80];
    if ($metier === 'rh') {
        $emp = mbo_match_employee($pdo, ' ' . mbo_norm($text) . ' ');
        if ($emp['id'] > 0)                 $ent = ['type'=>'EMP','id'=>$emp['id'],'label'=>$emp['label'],'score'=>$emp['score']];
        elseif ($sender['kind'] === 'user') $ent = ['type'=>'EMP','id'=>$sender['id'],'label'=>$sender['label'],'score'=>60];
    }
    $dateDoc = mbo_extract_doc_date($text);
    $refSal  = ($metier === 'rh') ? mbo_extract_salaire_date($text) : mbo_extract_ref_date($text, $type);

    $pdo->prepare("UPDATE fluxbox_documents
                   SET ocr_text = COALESCE(NULLIF(ocr_text,''), ?), ocr_status='done',
                       mbo_type_propose=?, mbo_metier=?, mbo_date_doc=?, mbo_ref_salaire=?,
                       mbo_entity_type=?, mbo_entity_id=?, mbo_entity_label=?, mbo_entity_score=?, mbo_ocr_at=NOW()
                   WHERE id=?")
        ->execute([mb_substr($ocr, 0, 60000), $type, ($metier ?: null), ($dateDoc ?: null), ($refSal ?: null),
                   $ent['type'], $ent['id'], $ent['label'], ($ent['score'] ?: null), (int)$d['id']]);

    return ['type'=>$type, 'avec_texte'=>trim($text) !== '', 'reconnu'=>(bool)$ent['type']];
}

/**
 * Nom d'immeuble pour le NOM GED : privilégie nom_immeuble ; à défaut, dérive un nom
 * lisible de l'adresse en retirant le numéro de voie et le TYPE de voie (rue, avenue…).
 * Ex. « 100 RUE MARYSE BASTIE » → « MARYSE BASTIE » (« il n'y a pas RUE dans un nom »).
 */
function mbo_immeuble_name(string $nom, string $adresse): string
{
    // Nom d'immeuble COURT pour le nom GED. On part du nom saisi, sinon de l'adresse.
    // Piège : la modale adresse Google recopie parfois l'adresse COMPLÈTE dans nom_immeuble
    // (ex. « Le Tissot, 42530 Saint-Genest-Lerpt, France ») → on la raccourcit quand même.
    $s = trim($nom) !== '' ? trim($nom) : trim($adresse);
    if ($s === '') return '';
    return mbo_shorten_immeuble_label($s, trim($nom) === '');
}

/** Raccourcit un libellé d'immeuble : garde voie/lieu-dit, coupe « CP Ville, Pays ». */
function mbo_shorten_immeuble_label(string $s, bool $stripStreet = false): string
{
    $raw = $s;
    // 1) adresse formatée « Voie, CP Ville, Pays » → on ne garde que le 1er segment (voie/lieu-dit).
    if (strpos($s, ',') !== false) $s = trim(explode(',', $s)[0]);
    // 2) filet si pas de virgule : coupe à partir d'un code postal (5 chiffres) et ce qui suit.
    $s = preg_replace('/\s*\b\d{5}\b.*$/u', '', $s);
    // 3) retire un « France » résiduel.
    $s = trim(preg_replace('/\s*,?\s*\bfrance\b\.?\s*$/iu', '', $s));
    if ($stripStreet) {
        // fallback pur adresse : retire n° de voie en tête + type de voie
        $s = preg_replace('/^\s*\d+\s*(bis|ter|quater)?\s*,?\s*/iu', '', $s);
        $s = preg_replace('/\b(rue|avenue|av|bd|boulevard|impasse|all[ée]+e|chemin|place|route|rte|quai|cours|passage|square|villa|sentier|lotissement|residence|r[ée]sidence)\b\.?\s*/iu', '', $s);
    }
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return $s !== '' ? $s : $raw; // jamais vide : garde le libellé d'origine
}

/** Agence de rattachement d'une entité (comme le nom GED : bien→immeuble/proprio). */
function mbo_agence_of_entity(PDO $pdo, string $type, int $id): int
{
    if ($id <= 0) return 0;
    $type = strtoupper($type); $idBien = $idImm = $idProp = $idAgence = 0;
    if ($type === 'EMP')   { $st=$pdo->prepare("SELECT id_agence FROM users WHERE id=?"); $st->execute([$id]); return (int)$st->fetchColumn(); }
    if ($type === 'BAIL')  { $st=$pdo->prepare("SELECT id_bien FROM bien_baux WHERE id=?"); $st->execute([$id]); $idBien=(int)$st->fetchColumn(); }
    elseif ($type === 'BIEN')  $idBien = $id;
    elseif ($type === 'IMB')   $idImm  = $id;
    elseif ($type === 'TIERS') $idProp = $id;
    if ($idBien>0){ $st=$pdo->prepare("SELECT id_immeuble,id_proprietaire,id_agence FROM biens WHERE id=?"); $st->execute([$idBien]); if($r=$st->fetch(PDO::FETCH_ASSOC)){ $idImm=$idImm?:(int)$r['id_immeuble']; $idProp=$idProp?:(int)$r['id_proprietaire']; $idAgence=(int)$r['id_agence']; } }
    if (!$idAgence && $idImm>0){ $st=$pdo->prepare("SELECT id_agence FROM immeubles WHERE id=?"); $st->execute([$idImm]); $idAgence=(int)$st->fetchColumn(); }
    if (!$idAgence && $idProp>0){ $st=$pdo->prepare("SELECT id_agence FROM proprietaires WHERE id=?"); $st->execute([$idProp]); $idAgence=(int)$st->fetchColumn(); }
    return $idAgence;
}

/** Société d'un document telle qu'elle apparaît dans le nom GED (override > entité > tenant). */
function mbo_doc_societe_id(PDO $pdo, array $d): int
{
    if (!empty($d['mbo_societe_id'])) return (int)$d['mbo_societe_id'];
    $idAgence = (!empty($d['mbo_agence_id']) && (int)$d['mbo_agence_id'] > 0) ? (int)$d['mbo_agence_id']
              : mbo_agence_of_entity($pdo, (string)($d['mbo_entity_type'] ?? ''), (int)($d['mbo_entity_id'] ?? 0));
    if ($idAgence > 0) { $st=$pdo->prepare("SELECT id_societe FROM agences WHERE id=?"); $st->execute([$idAgence]); $s=(int)$st->fetchColumn(); if ($s) return $s; }
    return (int)($d['tenant_id'] ?? 0);
}

function mbo_build_ged_name(PDO $pdo, array $d, bool $ensure = false, ?int $uid = null): string
{
    // Résout les libellés pro / immeuble / bien / bail depuis l'entité reconnue.
    $type = (string)($d['mbo_entity_type'] ?? ''); $id = (int)($d['mbo_entity_id'] ?? 0);
    $idBail = $idBien = $idImm = $idProp = $idAgence = 0;
    $empName = ''; $empSocId = 0;
    if ($type === 'EMP') {
        if ($id <= 0) {
            // RH « TOUS les collaborateurs » (id ≤ 0) : PRO = TOUS ; société/agence = SCOPE du doc
            // (toutes agences → société ; agence précise → cette agence, géré par l'override plus bas).
            $empName = 'TOUS';
            $empSocId = (int)($d['mbo_societe_id'] ?? 0);
        } else {
            // Document RH : tout part du SALARIÉ (sa société + son agence, lui = entité/PRO).
            $st=$pdo->prepare("SELECT TRIM(CONCAT_WS(' ',prenom,nom)) AS nom, id_societe, id_agence FROM users WHERE id=?");
            $st->execute([$id]); if($u=$st->fetch(PDO::FETCH_ASSOC)){ $empName=(string)$u['nom']; $empSocId=(int)$u['id_societe']; $idAgence=(int)$u['id_agence']; }
        }
    }
    elseif ($type === 'BAIL') { $st=$pdo->prepare("SELECT id,id_bien FROM bien_baux WHERE id=?"); $st->execute([$id]); if($r=$st->fetch(PDO::FETCH_ASSOC)){$idBail=(int)$r['id'];$idBien=(int)$r['id_bien'];} }
    elseif ($type === 'BIEN') { $idBien=$id; } elseif ($type === 'IMB') { $idImm=$id; } elseif ($type === 'TIERS') { /* $id = tiers.id → résolu via la table `tiers` plus bas (PAS proprietaires) */ }
    if ($idBien>0){ $st=$pdo->prepare("SELECT id_immeuble,id_proprietaire FROM biens WHERE id=?"); $st->execute([$idBien]); if($r=$st->fetch(PDO::FETCH_ASSOC)){$idImm=$idImm?:(int)$r['id_immeuble'];$idProp=$idProp?:(int)$r['id_proprietaire'];} }
    if ($idImm>0 && !$idProp){ $st=$pdo->prepare("SELECT id_proprietaire FROM immeubles WHERE id=?"); $st->execute([$idImm]); $idProp=(int)$st->fetchColumn(); }

    // Au classement : garantir un lot interne au bien (jamais de bien sans lot).
    // Jamais de bien sans lot : on garantit un lot interne dès qu'un bien est concerné
    // (idempotent) → la référence LOT apparaît aussi en aperçu, pas seulement au classement.
    if ($idBien>0) { require_once __DIR__ . '/bien_lot.php'; bien_ensure_lot_interne($pdo, $idBien); }

    $lblPro=$lblImm=$lblBien=$lblBail='';
    if ($type === 'EMP') { $lblPro = $empName; } // le salarié occupe la position PRO
    if (in_array($type, ['TRS','TIERS'], true) && $id>0) { // tiers direct/propriétaire-tiers (fournisseur, copropriétaire, notaire, bailleur…) — id = tiers.id
        $st=$pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''), NULLIF(raison_sociale,''), NULLIF(TRIM(CONCAT_WS(' ',prenom,nom)),'')) AS lbl, id_agence FROM tiers WHERE id=?");
        $st->execute([$id]); if($r=$st->fetch(PDO::FETCH_ASSOC)){ $lblPro=(string)$r['lbl']; if(!$idAgence && !empty($r['id_agence'])) $idAgence=(int)$r['id_agence']; }
    }
    if ($idProp>0){ $st=$pdo->prepare("SELECT COALESCE(NULLIF(societe,''),nom) FROM proprietaires WHERE id=?"); $st->execute([$idProp]); $lblPro=(string)$st->fetchColumn(); }
    if ($idImm>0){ $st=$pdo->prepare("SELECT nom_immeuble, adresse_1, id_agence FROM immeubles WHERE id=?"); $st->execute([$idImm]); if($r=$st->fetch(PDO::FETCH_ASSOC)){ $lblImm=mbo_immeuble_name((string)$r['nom_immeuble'], (string)$r['adresse_1']); $idAgence=(int)$r['id_agence']; } }
    // Bien SANS immeuble rattaché (id_immeuble NULL) : on remplit quand même la position 5
    // avec l'ADRESSE DU BIEN (c'est le bâtiment concerné) → le nom n'a jamais un immeuble vide.
    if ($idBien>0 && $lblImm===''){ $st=$pdo->prepare("SELECT adresse_1 FROM biens WHERE id=?"); $st->execute([$idBien]); $adrB=(string)$st->fetchColumn(); if($adrB!=='') $lblImm=mbo_immeuble_name('', $adrB); }
    if ($idBien>0){ // Priorité : lot syndic officiel (lot_principal) puis lot interne (numero_lot).
        $st=$pdo->prepare("SELECT COALESCE(NULLIF(lot_principal,''), NULLIF(numero_lot,'')) FROM biens WHERE id=?"); $st->execute([$idBien]);
        $lot=(string)$st->fetchColumn(); $lblBien=$lot!==''?('LOT-'.$lot):''; }
    if ($idBail>0){ $st=$pdo->prepare("SELECT COALESCE(NULLIF(locataire_raison_sociale,''),locataire_nom) FROM bien_baux WHERE id=?"); $st->execute([$idBail]); $lblBail=(string)$st->fetchColumn(); }

    // Agence : les immeubles ne sont pas attribués → on la déduit du PROPRIÉTAIRE, sinon du BIEN.
    if (!$idAgence && $idProp>0){ $st=$pdo->prepare("SELECT id_agence FROM proprietaires WHERE id=?"); $st->execute([$idProp]); $idAgence=(int)$st->fetchColumn(); }
    if (!$idAgence && $idBien>0){ $st=$pdo->prepare("SELECT id_agence FROM biens WHERE id=?"); $st->execute([$idBien]); $idAgence=(int)$st->fetchColumn(); }
    // Override manuel (picker) prioritaire sur la déduction. -1 = TOUTES agences (niveau société).
    $allAgences = false;
    if (!empty($d['mbo_agence_id'])) {
        $ov = (int)$d['mbo_agence_id'];
        if ($ov > 0) $idAgence = $ov;
        elseif ($ov === -1) $allAgences = true; // garde l'agence dérivée pour la société, code agence vidé
    }

    // CODES CONTRÔLÉS via le glossaire (règle primordiale : normalisation = extraction en masse).
    // RH : la société vient du SALARIÉ (ex. EMERY IMMO), pas du tenant.
    // SOCIÉTÉ : override manuel > salarié (RH) > société DE L'ENTITÉ liée (via son agence) > tenant.
    $socId = 0;
    if (!empty($d['mbo_societe_id']))        $socId = (int)$d['mbo_societe_id'];
    elseif ($type === 'EMP' && $empSocId)    $socId = $empSocId;
    elseif ($idAgence > 0) { try { $qs=$pdo->prepare("SELECT id_societe FROM agences WHERE id=?"); $qs->execute([$idAgence]); $socId=(int)$qs->fetchColumn(); } catch (Throwable) {} }
    if ($socId === 0) $socId = (int)($d['tenant_id'] ?? 0);
    $tenant = (int)($d['tenant_id'] ?? 0) ?: 1;
    if ($ensure) {
        // Filet de sécurité : au classement, on GÉNÈRE le code entité s'il manque (1 seule fois).
        $socLbl = ''; try { $q=$pdo->prepare("SELECT COALESCE(NULLIF(raison_sociale,''),nom) FROM societes WHERE id=?"); $q->execute([$socId]); $socLbl=(string)$q->fetchColumn(); } catch (Throwable) {}
        $ageLbl = ''; if($idAgence>0){ try { $q=$pdo->prepare("SELECT nom_agence FROM agences WHERE id=?"); $q->execute([$idAgence]); $ageLbl=(string)$q->fetchColumn(); } catch (Throwable) {} }
        $socCode = mbo_glossaire_ensure($pdo, 'societe', $socId, 'societes', $socLbl, $tenant, $uid);
        $ageCode = mbo_glossaire_ensure($pdo, 'agence', $idAgence, 'agences', $ageLbl, $tenant, $uid);
        if ($idImm > 0) mbo_glossaire_ensure($pdo, 'immeuble', $idImm, 'immeubles', $lblImm, $tenant, $uid); // registre (le nom garde le libellé lisible)
    } else {
        $socCode = mbo_glossaire_code($pdo, 'societe', $socId);
        $ageCode = mbo_glossaire_code($pdo, 'agence', $idAgence);
    }
    if ($allAgences) $ageCode = ''; // niveau société : pas de code agence
    // MÉTIER : override explicite prioritaire. Sinon déduit de l'ENTITÉ (JAMAIS du type,
    // pour que choisir un type d'un autre métier ne change PAS le métier du document).
    $metierRaw  = (string)($d['mbo_metier'] ?? '');
    if ($metierRaw === '') {
        if ($type === 'EMP') $metierRaw = 'rh';                                             // salarié → RH
        elseif ($idImm > 0)  $metierRaw = mbo_metier_from_immeuble($pdo, $idImm);           // immeuble → syndic/gestion
        elseif ($idBien > 0 || $idBail > 0 || $idProp > 0) $metierRaw = 'gestion';          // bien/bail/pro → gestion
        else $metierRaw = mbo_infer_metier((string)($d['mbo_type_propose'] ?? ''), $type);  // pas d'entité → repli type
    }
    $metierCode = mbo_metier_n1_code($metierRaw);
    $typeCode   = mbo_type_ged_code($pdo, (string)($d['mbo_type_propose'] ?? ''));

    $libelle = trim((string)($d['mbo_libelle'] ?? ''));
    // RH : la CATÉGORIE salaire (frais_deplacement…) alimente le libellé (position 10),
    // devant l'éventuel libellé libre. Ex. « FRAIS-DEPLACEMENT ».
    if ($metierRaw === 'rh') {
        $cat = trim((string)($d['mbo_rh_categorie'] ?? ''));
        if ($cat !== '') $libelle = trim(str_replace('_', ' ', $cat) . ' ' . $libelle);
    }
    // DATE = date MÉTIER du document (extraite) en priorité, sinon date d'ingestion.
    $dateSrc = !empty($d['mbo_date_doc']) ? (string)$d['mbo_date_doc'] : (string)($d['first_seen_at'] ?? $d['created_at'] ?? 'now');
    $dt = strtotime($dateSrc) ?: time();
    $ext = strtolower(pathinfo((string)($d['fichier_nom'] ?? ''), PATHINFO_EXTENSION)) ?: 'pdf';

    // 11 positions fixes (ordre invariable ; case vide = segment vide → « __ »).
    // Positions 1-3 et 8 = CODES GLOSSAIRE (contrôlés). Seule la position 10 (libellé) est libre.
    $pos = [
        mbo_seg($socCode),                               // 1 SOCIETE (code)
        mbo_seg($ageCode),                               // 2 AGENCE (code)
        mbo_seg($metierCode),                            // 3 METIER (code metier_n1)
        mbo_seg($lblPro),                                // 4 PRO
        mbo_seg($lblImm),                                // 5 IMMEUBLE
        mbo_seg($lblBien),                               // 6 BIEN
        mbo_seg($lblBail),                               // 7 BAIL
        mbo_seg($typeCode),                              // 8 TYPE_DOC (code contrôlé)
        // 9 DATE DE RÉFÉRENCE : paye = MM-AAAA (mois) ; sinon date complète JJ-MM-AAAA.
        (!empty($d['mbo_ref_salaire'])
            ? (($d['mbo_metier'] ?? '') === 'rh'
                ? date('m-Y', strtotime((string)$d['mbo_ref_salaire']))
                : date('d-m-Y', strtotime((string)$d['mbo_ref_salaire'])))
            : ''),
        mbo_seg($libelle),                               // 10 LIBELLE (libre)
        date('Ymd', $dt),                                // 11 DATE
    ];
    return implode('_', $pos) . '.' . $ext;
}

/** Résout l'id du SALAIRE d'un salarié pour le mois d'un doc (0 si aucun). */
function mbo_resolve_salaire(PDO $pdo, int $idUser, string $dateDoc): int
{
    if ($idUser <= 0 || $dateDoc === '') return 0;
    $ts = strtotime($dateDoc); if (!$ts) return 0;
    $st = $pdo->prepare("SELECT id FROM salaires WHERE id_user=? AND YEAR(mois_reference)=? AND MONTH(mois_reference)=? ORDER BY id DESC LIMIT 1");
    $st->execute([$idUser, (int)date('Y', $ts), (int)date('n', $ts)]);
    return (int)$st->fetchColumn();
}

/** Type de doc RH → catégorie de la fiche salaire (relation_type du lien SAL). */
function mbo_rh_categorie(string $typeDoc): string
{
    return [
        'note_frais'    => 'frais_deplacement',
        'justificatif'  => 'frais_deplacement',
        'bulletin_paie' => 'bulletin',
    ][$typeDoc] ?? 'justificatif';
}

/** Résout l'id de l'immeuble à partir d'une entité reconnue (ou 0). */
function mbo_resolve_immeuble_id(PDO $pdo, ?string $type, ?int $id): int
{
    if (!$type || !$id) return 0;
    if ($type === 'IMB') return (int)$id;
    if ($type === 'BAIL') {
        $st = $pdo->prepare("SELECT b.id_immeuble FROM bien_baux bx JOIN biens b ON b.id=bx.id_bien WHERE bx.id=?");
        $st->execute([$id]); return (int)$st->fetchColumn();
    }
    if ($type === 'BIEN') {
        $st = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id=?");
        $st->execute([$id]); return (int)$st->fetchColumn();
    }
    return 0;
}

/**
 * Baux (locataires) d'un immeuble, actifs d'abord.
 * @return array [ ['bail_id','bien_id','numero_lot','locataire','actif'], ... ]
 */
function mbo_immeuble_baux(PDO $pdo, int $idImmeuble): array
{
    if ($idImmeuble <= 0) return [];
    $st = $pdo->prepare("SELECT bx.id AS bail_id, b.id AS bien_id, b.numero_lot,
                                COALESCE(NULLIF(bx.locataire_raison_sociale,''), bx.locataire_nom) AS locataire,
                                (bx.statut='actif') AS actif
                         FROM bien_baux bx JOIN biens b ON b.id = bx.id_bien
                         WHERE b.id_immeuble = ? AND COALESCE(NULLIF(bx.locataire_raison_sociale,''), bx.locataire_nom) <> ''
                         ORDER BY (bx.statut='actif') DESC, b.numero_lot");
    $st->execute([$idImmeuble]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Résout la CHAÎNE de liens d'une entité reconnue, du plus fin au plus large :
 *   locataire → bail → bien → immeuble → propriétaire.
 * @return array liste ordonnée de ['icon','label','url']
 */
function mbo_entity_chain(PDO $pdo, ?string $type, ?int $id): array
{
    if (!$type || !$id) return [];
    if ($type === 'TRS') { // tiers direct (fournisseur, copropriétaire, notaire…)
        $st=$pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''), NULLIF(raison_sociale,''), NULLIF(TRIM(CONCAT_WS(' ',prenom,nom)),'')) AS nom FROM tiers WHERE id=?");
        $st->execute([$id]); $n=(string)$st->fetchColumn();
        return [['icon'=>'👤', 'label'=>$n ?: 'Tiers', 'url'=>app_url('/acteur_fiche.php?id=' . (int)$id)]];
    }
    if ($type === 'EMP') {
        $st = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ',prenom,nom)) AS nom, id_agence FROM users WHERE id=?");
        $st->execute([$id]); $u = $st->fetch(PDO::FETCH_ASSOC);
        $out = [['icon'=>'🧑‍💼', 'label'=>trim((string)($u['nom'] ?? 'Salarié')), 'url'=>app_url('/rh_dashboard_user.php?id=' . (int)$id)]];
        if (!empty($u['id_agence'])) { $ag=$pdo->prepare("SELECT nom_agence FROM agences WHERE id=?"); $ag->execute([(int)$u['id_agence']]); if($an=$ag->fetchColumn()) $out[]=['icon'=>'🏢','label'=>(string)$an,'url'=>'#']; }
        return $out;
    }
    $chain = [];
    $u = fn($p, $i) => app_url($p . '?id=' . (int)$i);

    $idBien = 0; $idImm = 0; $idProp = 0;

    if ($type === 'BAIL') {
        $st = $pdo->prepare("SELECT id, id_bien, COALESCE(NULLIF(locataire_raison_sociale,''), locataire_nom) AS nom FROM bien_baux WHERE id=?");
        $st->execute([$id]); $b = $st->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $chain[] = ['icon'=>'🔑', 'label'=>trim((string)$b['nom']) ?: 'Locataire', 'url'=>$u('/bail_360.php', $b['id'])];
            $idBien = (int)$b['id_bien'];
        }
    } elseif ($type === 'IMB') {
        $idImm = $id;
    } elseif ($type === 'TIERS') {
        $idProp = $id;
    }

    if ($idBien > 0) {
        $st = $pdo->prepare("SELECT id, numero_lot, code_crg, id_immeuble, id_proprietaire FROM biens WHERE id=?");
        $st->execute([$idBien]); $bi = $st->fetch(PDO::FETCH_ASSOC);
        if ($bi) {
            $lbl = trim((string)($bi['code_crg'] ?: ('Lot ' . $bi['numero_lot']))) ?: 'Bien';
            $chain[] = ['icon'=>'🏠', 'label'=>$lbl, 'url'=>$u('/bien_360.php', $bi['id'])];
            $idImm  = (int)$bi['id_immeuble'];
            $idProp = (int)$bi['id_proprietaire'];
        }
    }
    if ($idImm > 0) {
        $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, id_proprietaire FROM immeubles WHERE id=?");
        $st->execute([$idImm]); $im = $st->fetch(PDO::FETCH_ASSOC);
        if ($im) {
            $chain[] = ['icon'=>'🏢', 'label'=>trim((string)($im['nom_immeuble'] ?: $im['adresse_1'])) ?: 'Immeuble', 'url'=>$u('/immeuble_360.php', $im['id'])];
            if (!$idProp) $idProp = (int)$im['id_proprietaire'];
        }
    }
    if ($idProp > 0) {
        $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(societe,''), nom) AS nom FROM proprietaires WHERE id=?");
        $st->execute([$idProp]); $pr = $st->fetch(PDO::FETCH_ASSOC);
        if ($pr) $chain[] = ['icon'=>'👤', 'label'=>trim((string)$pr['nom']) ?: 'Propriétaire', 'url'=>$u('/agency_proprietaire_fiche.php', $pr['id'])];
    }
    return $chain;
}
