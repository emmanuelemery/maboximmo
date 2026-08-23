<?php
declare(strict_types=1);
/**
 * inc/bail_ceremonie.php — L'ORCHESTRATION de la cérémonie de signature du bail.
 *
 * `inc/bail_signature.php` tient les jetons et les signatures ; ce fichier-ci tient
 * le DÉROULÉ : qui est prévenu, quand, et par quels canaux.
 *
 * ── Les deux vagues (arbitrage du 18/08/2026) ────────────────────────────────
 *   VAGUE 1 — preneur · colocataires · cautions · bailleur, tous en même temps
 *   VAGUE 2 — le mandataire seul, ouverte dès que la vague 1 est complète
 *
 * ── Pourquoi le SMS d'invitation ne porte PAS le code ────────────────────────
 * Le SMS transporte le LIEN ; le code part à l'ouverture de la page. Deux raisons,
 * l'une pratique et l'autre juridique :
 *   · un code vit 10 minutes (OTP_VALIDITE_MIN) et le lien 48 heures — glissé dans
 *     l'invitation, le code serait mort pour presque tout le monde, et le
 *     signataire verrait un code qui « ne marche pas » ;
 *   · ce que le code démontre, c'est la détention du téléphone AU MOMENT de
 *     signer. Émis deux jours plus tôt, il ne dirait rien de l'instant de l'acte.
 *
 * Le vrai gain du SMS est ailleurs : le signataire n'a plus à ouvrir sa boîte mail
 * sur son téléphone pour atteindre le document.
 *
 * ⚠️ Le SMS ne remplace pas le mail. Le mail porte le projet de bail, les annexes
 * en pièces séparées, le RIB et le montant à verser — le SMS ne porte qu'une porte
 * d'entrée. Les deux partent ensemble.
 */

require_once __DIR__ . '/bail_signature.php';
require_once __DIR__ . '/sms_service.php';
require_once __DIR__ . '/communications.php';

if (!function_exists('bcer_contexte')) {
    /**
     * Le strict nécessaire pour rédiger une invitation : le nom du bail (jamais
     * « bail commercial » en dur — 81 % des baux sont des baux d'habitation) et
     * l'adresse du bien, qui permet au signataire de reconnaître SON dossier dans
     * un SMS de 160 caractères.
     */
    function bcer_contexte(PDO $pdo, int $idBail): array
    {
        $out = ['nom_bail' => 'bail', 'adresse' => '', 'ref' => '#' . $idBail, 'id_societe' => 0, 'numero' => ''];
        try {
            $st = $pdo->prepare("
                SELECT bb.bail_nature, bb.bail_regime, bb.duree_mois, bb.numero_bail,
                       /* ⚠️ `sms_envoyer()` REFUSE un envoi sans société : un SMS est
                          toujours émis au nom de quelqu'un. Le bail ne porte pas
                          toujours `id_societe` — on retombe sur celle du bien, sans
                          quoi le SMS échouerait sur les baux anciens. */
                       COALESCE(NULLIF(bb.id_societe, 0), b.id_societe) AS id_societe,
                       b.reference_bien, b.adresse_1, b.code_postal, b.ville
                  FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien
                 WHERE bb.id = ? LIMIT 1");
            $st->execute([$idBail]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return $out;

            if (is_file(__DIR__ . '/bail_types_registry.php')) {
                require_once __DIR__ . '/bail_types_registry.php';
                if (function_exists('bt_libelle')) $out['nom_bail'] = bt_libelle($r);
            }
            $out['adresse']    = trim(implode(' ', array_filter([
                trim((string)($r['adresse_1'] ?? '')), trim((string)($r['ville'] ?? '')),
            ])));
            $out['ref']        = trim((string)($r['reference_bien'] ?? '')) ?: ('#' . $idBail);
            $out['numero']     = trim((string)($r['numero_bail'] ?? ''));
            $out['id_societe'] = (int)($r['id_societe'] ?? 0);
        } catch (Throwable $e) { error_log('[bcer_contexte] ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('bcer_sms_message')) {
    /**
     * LE texte du SMS d'invitation — une seule fois, pour deux usages.
     *
     * Extrait de `bcer_sms_invitation()` le 22/08/2026 pour que le PRÉ-VOL mesure
     * exactement le message qui partira, et non une copie qui aurait dérivé au
     * premier ajustement de formulation. Fonction PURE : aucune écriture, aucun
     * envoi — c'est ce qui la rend utilisable par une vérification à blanc.
     *
     * ⚠️ Message tenu SOUS 160 CARACTÈRES à dessein. L'URL en consomme déjà ~107
     * (43 de chemin + 64 de jeton) : au-delà, l'opérateur découpe en segments
     * facturés séparément, et une cérémonie à cinq signataires paierait le double
     * pour du texte décoratif.
     *
     * Sans accents : hors GSM-7, un SMS bascule en UCS-2 et tombe à 70 caractères
     * par segment — l'adresse « Métropole » suffirait à le faire exploser.
     */
    function bcer_sms_message(array $sig, array $ctx): string
    {
        $url = bsig_build_url((string)$sig['token']);

        // Translittération : « Vénissieux » → « Venissieux ». Sans quoi : UCS-2, 70 car/segment.
        $sansAccent = static function (string $t): string {
            $r = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            return preg_replace('/[^\x20-\x7E]/', '', $r === false ? $t : $r) ?? $t;
        };

        /* ── LE BUDGET DE CARACTÈRES SE CALCULE, IL NE SE DEVINE PAS ──────────
           Mesuré au premier essai : le message rédigé « à vue » faisait 199
           caractères, soit DEUX segments facturés — pour cinq signataires, on
           payait le double sans que rien ne le signale.

           L URL n a pas de longueur fixe : 107 caractères en production
           (https://maboximmo.fr/…) mais 132 sur un poste local. On part donc de
           ce qui reste APRÈS l URL, et l adresse du bien — seul élément
           compressible — prend la place disponible, ou disparaît s il n en reste
           pas. Mieux vaut un SMS sans adresse qu un SMS à deux segments. */
        $avant  = 'MaBoxImmo : bail ';
        $apres  = ' a signer : ';
        $restant = 160 - mb_strlen($avant . $apres . $url);
        $lieu = '';
        if ($restant >= 8) {
            $lieu = trim($sansAccent(mb_substr((string)($ctx['adresse'] ?? ''), 0, $restant)));
            // Ne pas couper un mot en deux : « 12 rue de la Republ » est illisible.
            if ($lieu !== '' && mb_strlen((string)($ctx['adresse'] ?? '')) > mb_strlen($lieu)) {
                $coupe = mb_strrpos($lieu, ' ');
                if ($coupe !== false && $coupe >= 8) $lieu = mb_substr($lieu, 0, $coupe);
            }
        }
        $msg = $avant . ($lieu !== '' ? $lieu : 'a signer') . ($lieu !== '' ? $apres : ' : ') . $url;

        if (function_exists('sms_compter_segments')) {
            $seg = sms_compter_segments($msg);
            if ((int)($seg['segments'] ?? 1) > 1) {
                /* Filet : si malgré le calcul on dépasse (URL exotique), on retombe
                   sur le message minimal plutôt que de facturer deux segments. */
                $msg = 'MaBoxImmo : votre bail est a signer : ' . $url;
                $seg = sms_compter_segments($msg);
                if ((int)($seg['segments'] ?? 1) > 1) {
                    error_log('[bcer_sms_message] ' . (int)$seg['segments'] . ' segments malgre le repli ('
                        . mb_strlen($msg) . ' car.) — URL de ' . mb_strlen($url) . ' car.');
                }
            }
        }
        return $msg;
    }
}

if (!function_exists('bcer_sms_invitation')) {
    /**
     * Le SMS d'invitation : identité de l'émetteur, de quoi reconnaître le dossier,
     * et le lien. Rien d'autre.
     *
     * ⚠️ Message tenu SOUS 160 CARACTÈRES à dessein. L'URL en consomme déjà ~107
     * (43 de chemin + 64 de jeton) : au-delà, l'opérateur découpe en segments
     * facturés séparément, et une cérémonie à cinq signataires paierait le double
     * pour du texte décoratif. `sms_compter_segments()` est journalisé pour qu'un
     * dépassement se voie, plutôt que de se payer en silence.
     *
     * Sans accents : hors GSM-7, un SMS bascule en UCS-2 et tombe à 70 caractères
     * par segment — l'adresse « Métropole » suffirait à le faire exploser.
     */
    function bcer_sms_invitation(PDO $pdo, array $sig, array $ctx, bool $dryRun = false): array
    {
        $tel = trim((string)($sig['destinataire_tel'] ?? ''));
        if ($tel === '') return ['ok' => false, 'error' => 'Aucun mobile pour ce signataire.'];

        $msg = bcer_sms_message($sig, $ctx);

        /* Rattache l'envoi au DOSSIER pour le journal des communications. Posé ici
           et non chez l'appelant : les deux canaux du bail passent par ces deux
           fonctions, un rattachement oublié serait un échange invisible. */
        if (function_exists('comm_contexte')) {
            comm_contexte('BAIL', (int)($sig['id_bail'] ?? 0), (int)($sig['id_tiers'] ?? 0) ?: null);
        }
        $res = sms_envoyer($pdo, $tel, $msg, [
            'type'       => 'SIGNATURE',
            'id_societe' => (int)($ctx['id_societe'] ?? 0),
            'objet_type' => 'BAIL_SIGNATURE',
            'objet_id'   => (int)$sig['id'],
            'id_tiers'   => (int)($sig['id_tiers'] ?? 0) ?: null,
            'dry_run'    => $dryRun,
        ]);
        if (!empty($res['ok'])) {
            try {
                $pdo->prepare("UPDATE bail_signatures SET sms_invite_at = NOW() WHERE id = ?")
                    ->execute([(int)$sig['id']]);
            } catch (Throwable $e) { error_log('[bcer_sms_invitation maj] ' . $e->getMessage()); }
        }
        return $res + ['message' => $msg];
    }
}

if (!function_exists('bcer_mail_invitation')) {
    /**
     * Le mail d'invitation STANDARD — celui de la vague 2, et le filet de secours
     * de la vague 1.
     *
     * ⚠️ En vague 1, l'agent compose lui-même son message dans mail_compose.php :
     * il y ajoute le RIB, le montant à verser, les annexes. Ce gabarit-ci ne sert
     * QU'À la vague 2, où personne n'est devant l'écran pour rédiger — le
     * mandataire est prévenu par la machine, au moment où la dernière signature de
     * la vague 1 tombe, éventuellement à 23 h un dimanche.
     */
    function bcer_mail_invitation(PDO $pdo, array $sig, array $ctx): bool
    {
        $email = trim((string)($sig['destinataire_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        require_once __DIR__ . '/mailer.php';
        if (!function_exists('send_mail')) return false;

        $url  = bsig_build_url((string)$sig['token']);
        $nom  = trim((string)($sig['nom_signataire'] ?? '')) ?: 'Madame, Monsieur';
        $nomB = (string)($ctx['nom_bail'] ?? 'bail');
        $adr  = (string)($ctx['adresse'] ?? '');
        $h    = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $subject = 'À signer : ' . $nomB . ($adr !== '' ? ' — ' . $adr : '');
        $body =
            '<p>Bonjour ' . $h($nom) . ',</p>'
          . '<p><strong>Toutes les autres parties ont signé</strong> le ' . $h($nomB)
          . ($adr !== '' ? ' concernant le bien <strong>' . $h($adr) . '</strong>' : '')
          . '. Il ne manque plus que votre signature pour clore la cérémonie.</p>'
          . '<p><a href="' . $h($url) . '" style="display:inline-block;padding:12px 22px;background:#5f8f93;'
          . 'color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">Consulter et signer</a></p>'
          . '<p style="font-size:12px;color:#666;">Ou copiez ce lien : ' . $h($url) . '</p>'
          . '<p style="font-size:12px;color:#666;">Un code de sécurité vous sera demandé à l\'ouverture de la page. '
          . 'Lien valable 48 heures ; votre signature est horodatée et tracée à des fins de preuve.</p>';

        // Même rattachement que pour le SMS : le mail du bail est un échange du bail.
        if (function_exists('comm_contexte')) {
            comm_contexte('BAIL', (int)($sig['id_bail'] ?? 0), (int)($sig['id_tiers'] ?? 0) ?: null);
        }
        try { return (bool)send_mail($email, $subject, $body, [], true); }
        catch (Throwable $e) { error_log('[bcer_mail_invitation] ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('bcer_ouvrir_vague')) {
    /**
     * Ouvre une vague : marque ses liens comme ouverts, puis prévient chaque
     * signataire par mail ET par SMS.
     *
     * ⚠️ L'ordre compte. On pose `vague_ouverte_at` AVANT d'envoyer quoi que ce
     * soit : deux signataires de la vague 1 qui terminent à la même seconde
     * appelleraient cette fonction deux fois, et le mandataire recevrait son mail
     * en double. Marquer d'abord ferme la porte au second appel — au pire, un
     * envoi échoue et se rattrape par la relance manuelle ; au mieux on évite un
     * doublon que le destinataire lirait comme un bug.
     *
     * @return array<int,array{id:int,role:string,email:?string,tel:?string,mail:bool,sms:bool,sms_error:?string}>
     */
    function bcer_ouvrir_vague(PDO $pdo, int $idBail, int $vague, bool $dryRun = false, string $canaux = 'tous'): array
    {
        /* $canaux : 'tous' (défaut) · 'sms' · 'mail'.
           ⚠️ Le défaut reste 'tous' — `bcer_avancer()` convoque le mandataire par
           cette même fonction, et il ne doit rien perdre parce qu'un autre appel
           s'est restreint. Le choix du canal appartient à l'agent qui déclenche,
           pas à la mécanique de la cérémonie. */
        $faireMail = ($canaux !== 'sms');
        $faireSms  = ($canaux !== 'mail');
        $aOuvrir = bsig_vague_a_ouvrir($pdo, $idBail, $vague);
        if (!$aOuvrir) return [];

        $ids = array_map(static fn($s) => (int)$s['id'], $aOuvrir);
        try {
            /* ⚠️🔥 Placeholders `?` et non `:nommes` — un placeholder nommé réutilisé
               dans un IN(...) plante en production. */
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE bail_signatures SET vague_ouverte_at = NOW(), sent_at = NOW()
                            WHERE id IN ($in) AND vague_ouverte_at IS NULL")->execute($ids);
        } catch (Throwable $e) { error_log('[bcer_ouvrir_vague maj] ' . $e->getMessage()); return []; }

        $ctx = bcer_contexte($pdo, $idBail);
        $out = [];
        foreach ($aOuvrir as $sig) {
            $mail = false; $sms = false; $smsErr = null; $mailErr = null;
            if ($faireMail) {
                try { $mail = bcer_mail_invitation($pdo, $sig, $ctx); }
                catch (Throwable $e) { $mailErr = $e->getMessage(); error_log('[bcer_ouvrir_vague mail] ' . $mailErr); }
            }
            /* ⚠️🔥 LA RAISON DE L'ÉCHEC REMONTE, elle ne se perd plus.
               Cette fonction ne renvoyait qu'un booléen : un SMS non parti était
               signalé, jamais EXPLIQUÉ. Mesuré sur le bail #660 le 20/08 — le mail
               partait, le SMS non, et l'écran se contentait de ne pas le mentionner.
               Il a fallu trois requêtes SQL pour apprendre ce que `sms_envoyer()`
               disait depuis le début : « Configuration SMS absente ou incomplète ».
               Une cause connue et tue coûte plus cher qu'une panne bruyante. */
            if ($faireSms) {
                try {
                    $r = bcer_sms_invitation($pdo, $sig, $ctx, $dryRun);
                    $sms = !empty($r['ok']);
                    if (!$sms) $smsErr = (string)($r['error'] ?? 'échec inconnu');
                } catch (Throwable $e) {
                    $smsErr = $e->getMessage();
                    error_log('[bcer_ouvrir_vague sms] ' . $smsErr);
                }
            }
            $out[] = [
                'id'        => (int)$sig['id'],
                'role'      => (string)($sig['role_code'] ?? ''),
                'email'     => $sig['destinataire_email'] ?? null,
                'tel'       => $sig['destinataire_tel'] ?? null,
                'mail'      => $mail, 'sms' => $sms,
                'sms_error' => $smsErr, 'mail_error' => $mailErr,
            ];
        }
        return $out;
    }
}

if (!function_exists('bcer_avancer')) {
    /**
     * Appelée APRÈS chaque signature : si la vague 1 est complète, ouvre la vague 2.
     *
     * Volontairement silencieuse et sans effet de bord au-delà de l'ouverture — la
     * finalisation du bail (PDF, GED, envoi du combiné) reste sous le contrôle de
     * `bsig_sign` / `bail_cloturer`, qui la déclenchent quand TOUTES les vagues
     * sont signées.
     */
    function bcer_avancer(PDO $pdo, int $idBail): array
    {
        if ($idBail <= 0) return [];
        if (!bsig_vague_complete($pdo, $idBail, 1)) return [];
        return bcer_ouvrir_vague($pdo, $idBail, 2);
    }
}

if (!function_exists('bcer_prevol')) {
    /**
     * LE PRÉ-VOL — tout ce qui doit être vrai AVANT d'ouvrir une cérémonie.
     *
     * ⚠️🔥 Pourquoi elle ne réutilise PAS `bcer_ouvrir_vague($dryRun = true)`.
     * Ce paramètre ne couvre QUE le SMS : la fonction pose `vague_ouverte_at` et
     * `sent_at` avant tout envoi, et appelle `bcer_mail_invitation()` sans
     * condition. Une « vérification » qui passerait par là ouvrirait la vague et
     * enverrait de vrais mails — exactement ce qu'on veut éviter. Le pré-vol est
     * donc un chemin séparé, et il est PUR : aucune écriture, aucun envoi, rien
     * qui consomme un jeton. On peut le relancer autant qu'on veut.
     *
     * Trois états, et un seul compte pour décider : `ko` = quelqu'un ne recevra
     * rien ou rien ne partira ; `warn` = ça partira mais dégradé ; `ok` = vérifié.
     *
     * @return array{ok:bool, bloquants:int, alertes:int,
     *                points:array<int,array{cle:string,lbl:string,etat:string,detail:string}>}
     */
    function bcer_prevol(PDO $pdo, int $idBail): array
    {
        $points = [];
        $add = static function (string $cle, string $lbl, string $etat, string $detail) use (&$points): void {
            $points[] = ['cle' => $cle, 'lbl' => $lbl, 'etat' => $etat, 'detail' => $detail];
        };

        $sigs = bsig_list_for_bail($pdo, $idBail);
        $ctx  = bcer_contexte($pdo, $idBail);

        // ── 1. LES TABLES DE TRAÇABILITÉ ────────────────────────────────────────
        /* ⚠️🔥 En prod, `sms_envois` n'existait pas (erreur #1146, 20/08/2026) :
           `sms_envoyer()` INSERT avant d'appeler OVH, donc AUCUN SMS ne pouvait
           partir — et les appelants avalaient l'exception. Un lot de migrations
           « du même jour » n'est PAS joué en bloc : on vérifie table par table.
           `information_schema`, lui, ne plante jamais. */
        $attendues = ['sms_envois' => 'journal des SMS', 'sms_otp' => 'codes de sécurité',
                      'communications' => 'journal des mails'];
        try {
            $in = implode(',', array_fill(0, count($attendues), '?'));
            $st = $pdo->prepare("SELECT table_name FROM information_schema.tables
                                  WHERE table_schema = DATABASE() AND table_name IN ($in)");
            $st->execute(array_keys($attendues));
            $presentes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $manquantes = [];
            foreach ($attendues as $t => $quoi) { if (!in_array($t, $presentes, true)) $manquantes[] = $t . ' (' . $quoi . ')'; }
            if ($manquantes) {
                $add('tables', 'Tables de traçabilité', in_array('sms_envois', $presentes, true) ? 'warn' : 'ko',
                    'Manquante(s) : ' . implode(', ', $manquantes)
                    . '. Rejouer la migration correspondante dans admin/admin_migrations.php.');
            } else {
                $add('tables', 'Tables de traçabilité', 'ok', 'Les trois journaux sont en place.');
            }
        } catch (Throwable $e) {
            $add('tables', 'Tables de traçabilité', 'warn', 'Contrôle impossible : ' . $e->getMessage());
        }

        // ── 2. LA CONFIGURATION SMS ─────────────────────────────────────────────
        if (!function_exists('sms_config_publique')) {
            $add('sms_conf', 'Configuration SMS', 'ko', 'Module SMS non chargé (inc/sms_config.php absent ?).');
        } else {
            $c = sms_config_publique();
            if (empty($c['configuree'])) {
                $add('sms_conf', 'Configuration SMS', 'ko', 'Aucune configuration OVH trouvée : aucun SMS ne partira.');
            } elseif (empty($c['prete'])) {
                $add('sms_conf', 'Configuration SMS', 'ko', 'Configuration incomplète (service, identifiant ou mot de passe manquant).');
            } elseif (empty($c['actif'])) {
                /* ⚠️ `'actif' => true` manquant : deuxième cause muette du 20/08. */
                $add('sms_conf', 'Configuration SMS', 'ko', "Le canal SMS est DÉSACTIVÉ dans la configuration ('actif' => false).");
            } elseif (trim((string)($c['sender'] ?? '')) === '') {
                /* ⚠️ `sender` vide : OVH répond « Missing from » et rien ne se voit. */
                $add('sms_conf', 'Configuration SMS', 'ko', "Expéditeur vide : OVH refusera l'envoi (Missing from).");
            } else {
                $add('sms_conf', 'Configuration SMS', 'ok',
                    'Prête — expéditeur « ' . (string)$c['sender'] .' », quota ' . (int)($c['quota_horaire'] ?? 0) . '/h.');
            }
        }

        // ── 3. LE LIEN QUI PARTIRA ──────────────────────────────────────────────
        /* Un lien en http:// ou pointant sur un poste local est reçu, cliqué… et
           ne mène nulle part. Autant le voir ici plutôt que chez le signataire. */
        $urlTest = bsig_build_url(str_repeat('0', 64));
        $hote    = (string)(parse_url($urlTest, PHP_URL_HOST) ?? '');
        $https   = str_starts_with($urlTest, 'https://');
        $local   = $hote === '' || str_contains($hote, 'localhost') || str_contains($hote, '127.0.0.1');
        if ($local) {
            $add('lien', 'Adresse du lien', 'ko', 'Le lien pointe sur « ' . $hote . ' » : injoignable depuis un mobile.');
        } elseif (!$https) {
            $add('lien', 'Adresse du lien', 'warn', 'Lien en http:// vers ' . $hote . ' — la caméra et la copie d\'IBAN seront bloquées.');
        } else {
            $add('lien', 'Adresse du lien', 'ok', 'https://' . $hote . ' — valable ' . (int)(BSIG_TTL_MIN / 60) . ' h après l\'envoi.');
        }

        // ── 4. LES SIGNATAIRES SONT-ILS JOIGNABLES ? ────────────────────────────
        if (!$sigs) {
            $add('signataires', 'Signataires', 'ko',
                'Aucun signataire déterminé : vérifie le preneur et le bailleur dans « Modifier le projet ».');
        } else {
            $muets = []; $sansMobile = []; $segTrop = []; $telFictif = [];
            foreach ($sigs as $s) {
                $nom   = trim((string)($s['nom_signataire'] ?? '')) ?: ('rôle ' . (string)$s['role_code']);
                $mail  = trim((string)($s['destinataire_email'] ?? ''));
                $tel   = trim((string)($s['destinataire_tel'] ?? ''));
                $mailOk = $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false;
                $telOk  = false;
                if ($tel !== '' && function_exists('sms_normaliser_numero')) {
                    $n = sms_normaliser_numero($tel);
                    $telOk = !empty($n['ok']);
                    /* ⚠️ Un numéro peut être PARFAITEMENT valide de forme et n'exister
                       chez personne : 0600000000 passe la normalisation, part chez OVH,
                       est facturé, et s'affiche « envoyé ». Le signataire n'a rien reçu
                       et rien ne le dit. On le signale — on ne bloque pas, on ne corrige
                       pas la fiche tiers d'où il vient. */
                    if ($telOk && function_exists('sms_numero_suspect')) {
                        $sus = sms_numero_suspect((string)($n['national'] ?? ''));
                        if ($sus !== '') $telFictif[] = $nom . ' (' . $tel . ' — ' . $sus . ')';
                    }
                }
                /* Le seul VRAI blocage : ni mail ni mobile. Depuis le 19/08 le
                   mobile manquant ne bloque plus — mail et SMS portent le même
                   jeton — mais il doit être NOMMÉ, jamais tu. */
                if (!$mailOk && !$telOk)      $muets[] = $nom;
                elseif (!$telOk)              $sansMobile[] = $nom;
                if ($telOk) {
                    $msg = bcer_sms_message($s, $ctx);
                    $seg = function_exists('sms_compter_segments') ? (int)(sms_compter_segments($msg)['segments'] ?? 1) : 1;
                    if ($seg > 1) $segTrop[] = $nom . ' (' . $seg . ' segments)';
                }
            }
            if ($muets) {
                $add('signataires', 'Signataires joignables', 'ko',
                    'Ne recevra RIEN, ni mail ni SMS : ' . implode(', ', $muets) . '.');
            } else {
                $add('signataires', 'Signataires joignables', 'ok',
                    count($sigs) . ' signataire(s), tous joignables par au moins un canal.');
            }
            if ($sansMobile) {
                $add('mobiles', 'Mobiles', 'warn',
                    'Sans mobile, donc mail seul : ' . implode(', ', $sansMobile)
                    . '. La preuve reposera sur le lien nominatif, l\'horodatage et l\'IP — pas sur la détention du téléphone.');
            }
            if ($telFictif) {
                $add('tel_fictif', 'Mobile invraisemblable', 'warn',
                    implode(' ; ', $telFictif) . '. Le SMS partira, sera facturé et s\'affichera « envoyé » — '
                    . 'sans que personne ne le reçoive. À corriger sur la fiche du signataire avant d\'envoyer.');
            }
            if ($segTrop) {
                $add('sms_budget', 'Budget SMS', 'warn', 'Message à plus d\'un segment (facturé double) : ' . implode(', ', $segTrop) . '.');
            }
        }

        // ── 5. LE DOCUMENT SE GÉNÈRE-T-IL ? ─────────────────────────────────────
        /* Attrape le cas où le bail pointe sur un bien supprimé : le corps sort
           VIDE et la page de signature affiche un acte sans texte. Vu sur le
           bail #1160 (bien 3257 disparu). */
        try {
            require_once __DIR__ . '/bail_commercial_pdf.php';
            $corps = bail_build_corps_dispatch($pdo, $idBail, []);
            $n = mb_strlen((string)$corps);
            if ($n < 500) {
                $add('document', 'Le bail se génère', 'ko',
                    $n === 0 ? 'Le corps de l\'acte est VIDE : le bien rattaché existe-t-il encore ?'
                             : 'Corps anormalement court (' . $n . ' caractères).');
            } else {
                $add('document', 'Le bail se génère', 'ok', number_format($n, 0, ',', ' ') . ' caractères d\'acte.');
            }
        } catch (Throwable $e) {
            $add('document', 'Le bail se génère', 'ko', 'Génération impossible : ' . $e->getMessage());
        }

        // ── 6. LES ANNEXES SONT-ELLES LISIBLES ? ────────────────────────────────
        /* On faisait cocher « j'ai lu les N pièces » sans qu'aucune soit ouvrable.
           Une annexe annoncée mais introuvable fait tomber son opposabilité. */
        try {
            require_once __DIR__ . '/bail_justificatifs_pdf.php';
            if (function_exists('bail_annexes_chemins')) {
                /* ⚠️ La fonction rend TROIS listes — `chemins` (fichiers vérifiés),
                   `jointes` (noms retenus), `ecartees` (noms perdus) — et non une
                   liste plate. Compter le tableau du dessus donnait « 3 pièces sur
                   3 introuvables » sur un bail sans aucune annexe : le pré-vol
                   inventait une panne. Attrapé en le testant, le 22/08. */
                $ax       = bail_annexes_chemins($pdo, $idBail);
                $jointes  = (array)($ax['jointes']  ?? []);
                $ecartees = (array)($ax['ecartees'] ?? []);
                $tot      = count($jointes) + count($ecartees);
                if ($tot === 0) {
                    $add('annexes', 'Annexes', 'ok', 'Aucune pièce annexée à cet acte.');
                } elseif ($ecartees) {
                    $add('annexes', 'Annexes', 'warn',
                        count($ecartees) . ' pièce(s) sur ' . $tot . ' ne seront PAS jointes ni ouvrables : '
                        . implode(', ', array_map('strval', $ecartees))
                        . '. Ne pas faire cocher « j\'ai lu les annexes » dans cet état.');
                } else {
                    $add('annexes', 'Annexes', 'ok', $tot . ' pièce(s), toutes lisibles et ouvrables.');
                }
            }
        } catch (Throwable $e) {
            $add('annexes', 'Annexes', 'warn', 'Contrôle impossible : ' . $e->getMessage());
        }

        /* ── 7. LE CAUTIONNEMENT ────────────────────────────────────────────────
           Deux contrôles que rien d'autre ne fait, et dont l'échec est silencieux
           puis irréversible : un cautionnement mal formé est NUL (art. 2297) et on
           ne s'en aperçoit qu'au contentieux, des années plus tard. */
        try {
            require_once __DIR__ . '/bail_cautions.php';
            $cautions = function_exists('bail_cautions_list') ? bail_cautions_list($pdo, $idBail) : [];

            if ($cautions) {
                /* a) La colonne peut-elle SEULEMENT contenir la mention ?
                   ⚠️🔥 `mention_manuscrite` est née en VARCHAR(255) (migration 20260724d),
                   à l'époque où l'on y stockait « Bon pour caution solidaire ». La mention
                   de l'art. 2297 mesure ~1 040 caractères : sur une colonne restée courte,
                   MySQL la TRONQUE sans erreur (pas de mode STRICT) — l'acte serait amputé
                   du montant, donc nul, et rien ne le dirait. La migration 20260815f la
                   passe en TEXT ; on vérifie qu'elle a réellement PRIS, parce qu'un lot de
                   migrations « du même jour » n'est pas joué en bloc (cf. `sms_envois`). */
                $typ = null;
                try {
                    $st = $pdo->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
                                           FROM information_schema.COLUMNS
                                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bail_signatures'
                                            AND COLUMN_NAME = 'mention_manuscrite' LIMIT 1");
                    $st->execute();
                    $typ = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) { error_log('[bcer_prevol mention col] ' . $e->getMessage()); }

                $cap = $typ ? (int)($typ['CHARACTER_MAXIMUM_LENGTH'] ?? 0) : 0;
                if (!$typ) {
                    $add('mention_col', 'Colonne de la mention', 'warn', 'Type non vérifiable.');
                } elseif ($cap > 0 && $cap < 2000) {
                    $add('mention_col', 'Colonne de la mention', 'ko',
                        'mention_manuscrite = ' . strtoupper((string)$typ['DATA_TYPE']) . '(' . $cap . ') : la mention de '
                        . 'l\'art. 2297 fait ~1 040 caractères et serait TRONQUÉE SANS ERREUR — le cautionnement serait nul. '
                        . 'Rejouer la migration 20260815f_mention_2297_longueur.');
                } else {
                    $add('mention_col', 'Colonne de la mention', 'ok',
                        strtoupper((string)$typ['DATA_TYPE']) . ' — la mention complète tient sans troncature.');
                }

                // b) La mention peut-elle être FORMÉE pour chacune ? (plafond, durée, débiteur nommé)
                $bloq = []; $prets = 0; $avert = [];
                foreach ($cautions as $c) {
                    $nomC = trim((string)($c['nom_affichage'] ?? '')) ?: trim(((string)($c['prenom'] ?? '')) . ' ' . ((string)($c['nom'] ?? '')));
                    $cx = bail_caution_mention_ctx($pdo, $idBail, (int)$c['id_tiers']);
                    if (empty($cx['ok'])) { $bloq[] = ($nomC ?: 'caution #' . (int)$c['id_tiers']) . ' — ' . $cx['raison']; continue; }
                    $prets++;
                    if (!empty($cx['alerte'])) $avert[] = ($nomC ?: 'caution') . ' : ' . $cx['alerte'];
                }
                if ($bloq) {
                    $add('caution', 'Engagement de caution', 'ko',
                        'Mention de l\'art. 2297 impossible à former — ' . implode(' ; ', $bloq)
                        . '. Sans montant chiffré l\'engagement serait nul : la page de signature refusera.');
                } else {
                    $add('caution', 'Engagement de caution', 'ok',
                        $prets . ' caution(s), mention de l\'art. 2297 prête à être apposée.');
                }
                foreach ($avert as $a) $add('caution_alerte', 'Durée de l\'engagement', 'warn', $a);
            }
        } catch (Throwable $e) {
            $add('caution', 'Engagement de caution', 'warn', 'Contrôle impossible : ' . $e->getMessage());
        }

        $bloquants = 0; $alertes = 0;
        foreach ($points as $p) { if ($p['etat'] === 'ko') $bloquants++; elseif ($p['etat'] === 'warn') $alertes++; }
        return ['ok' => $bloquants === 0, 'bloquants' => $bloquants, 'alertes' => $alertes, 'points' => $points];
    }
}

if (!function_exists('bcer_suivi')) {
    /**
     * LE SUIVI EN DIRECT — où en est CHAQUE signataire, étape par étape.
     *
     * ⚠️🔥 Pourquoi cette fonction existe. Toute la traçabilité était DÉJÀ écrite —
     * `sent_at`, `sms_invite_at`, `otp_sms_id`, `otp_valide_at`, `annexes_lues_at`,
     * `signed_at`, `doc_combine_id`, plus `sms_envois` et `communications` — et
     * l'écran la réduisait à UN booléen : `'envoye' => !empty($s['sent_at'])`.
     * Quand ça marchait et quand ça échouait, l'agent voyait exactement la même
     * pastille. C'est ce silence qui a coûté trois jours de diagnostic sur le
     * bail #660 (table `sms_envois` absente, `sender` vide, verrou mobile) et
     * encore trois de plus sur le lien expiré du 22/08.
     *
     * ── LA RÈGLE D'HONNÊTETÉ ────────────────────────────────────────────────
     * Chaque étape ne dit QUE ce que la base prouve. « Lien émis » n'est pas
     * « mail reçu » ; un SMS `envoye` chez OVH n'est pas un SMS lu. Aucune étape
     * n'invente une certitude qu'on n'a pas — sinon ce tableau deviendrait le
     * nouvel écran qui ment, et on aurait juste déplacé le problème.
     *
     * Dégradation : une table absente (c'est arrivé) donne l'état `inconnu`,
     * jamais une exception. Un suivi qui plante n'aide personne.
     *
     * @return array<int, array{etapes:array<int,array{cle:string,lbl:string,etat:string,quand:?string,detail:string}>,
     *                          lien:array{arme:bool,expire_le:?string,reste_h:?int}}>
     */
    function bcer_suivi(PDO $pdo, int $idBail): array
    {
        $sigs = bsig_list_for_bail($pdo, $idBail);
        if (!$sigs) return [];
        $ids = array_map(static fn($s) => (int)$s['id'], $sigs);

        // ── SMS : invitation (type SIGNATURE) et code (type OTP), par signataire ──
        // ⚠️ Placeholders `?` : un `:nommé` réutilisé dans un IN(...) plante en prod.
        $smsParSig = [];   // [id_signature][type] = dernière ligne
        $smsDispo  = true;
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, objet_id, type, statut, provider_message_id, error_code,
                                        error_message, nb_segments, created_at, sent_at, delivered_at
                                   FROM sms_envois
                                  WHERE objet_type = 'BAIL_SIGNATURE' AND objet_id IN ($in)
                                  ORDER BY id ASC");
            $st->execute($ids);
            foreach ($st as $r) { $smsParSig[(int)$r['objet_id']][(string)$r['type']] = $r; }
        } catch (Throwable $e) {
            // Table absente / colonne manquante : on le DIT, on ne le masque pas.
            $smsDispo = false;
            error_log('[bcer_suivi sms] ' . $e->getMessage());
        }

        // ── MAIL : le journal unifié, rattaché au bail, apparié par adresse ──
        $mailParDest = []; $mailDispo = true;
        try {
            $st = $pdo->prepare("SELECT destinataire, statut, error_message, sujet, created_at
                                   FROM communications
                                  WHERE canal = 'mail' AND objet_type = 'BAIL' AND objet_id = ?
                                  ORDER BY id ASC");
            $st->execute([$idBail]);
            foreach ($st as $r) { $mailParDest[mb_strtolower(trim((string)$r['destinataire']))] = $r; }
        } catch (Throwable $e) {
            $mailDispo = false;
            error_log('[bcer_suivi mail] ' . $e->getMessage());
        }

        $ttlH = (int)(BSIG_TTL_MIN / 60);
        $out  = [];

        foreach ($sigs as $s) {
            $id      = (int)$s['id'];
            $signe   = ((string)($s['statut'] ?? '') === 'signe');
            $vague   = (int)($s['vague'] ?? 1);
            $ouverte = $vague < 2 || !empty($s['vague_ouverte_at']);
            $etapes  = [];

            /* Un helper local plutôt qu'un tableau recopié six fois : la forme d'une
               étape doit être décrite à UN endroit, sinon elles divergent. */
            $etape = static function (string $cle, string $lbl, string $etat, ?string $quand = null, string $detail = '') {
                return ['cle' => $cle, 'lbl' => $lbl, 'etat' => $etat,
                        'quand' => $quand ?: null, 'detail' => $detail];
            };

            // 1. Le lien a-t-il été émis ? (`sent_at` = dernière émission ou relance)
            if (!$ouverte) {
                $etapes[] = $etape('emis', 'Lien émis', 'attente', null,
                    'Vague ' . $vague . ' : convoqué automatiquement à la dernière signature des autres.');
            } elseif (!empty($s['sent_at'])) {
                $etapes[] = $etape('emis', 'Lien émis', 'ok', (string)$s['sent_at'], 'Jeton nominatif armé pour ' . $ttlH . ' h.');
            } else {
                $etapes[] = $etape('emis', 'Lien émis', 'ko', null,
                    "Aucune date d'envoi : ce lien n'est jamais parti (ligne créée à l'ouverture de l'écran).");
            }

            // 2. Le mail
            $mail = mb_strtolower(trim((string)($s['destinataire_email'] ?? '')));
            if ($mail === '') {
                $etapes[] = $etape('mail', 'Invitation par mail', 'na', null, 'Aucune adresse : ce canal ne peut pas servir.');
            } elseif (!$ouverte) {
                /* ⚠️🔥 L'APPARIEMENT SE FAIT PAR ADRESSE, ET DEUX RÔLES PARTAGENT
                   SOUVENT LA MÊME. Testé le 22/08 : le mandataire (vague 2, jamais
                   convoqué) affichait « mail parti le 18/08 » — c'était celui du
                   preneur, même boîte. On ne crédite donc un envoi qu'à un
                   signataire dont la vague est ouverte. Un suivi qui invente une
                   certitude est pire que pas de suivi : c'est le nouvel écran
                   qui ment, et on aurait juste déplacé le problème. */
                $etapes[] = $etape('mail', 'Invitation par mail', 'attente', null, 'Pas encore convoqué.');
            } elseif (!$mailDispo) {
                $etapes[] = $etape('mail', 'Invitation par mail', 'inconnu', null, 'Journal des communications illisible.');
            } elseif (isset($mailParDest[$mail])) {
                $m  = $mailParDest[$mail];
                $ok = (string)($m['statut'] ?? '') === 'envoye';
                $etapes[] = $etape('mail', 'Invitation par mail', $ok ? 'ok' : 'ko', (string)$m['created_at'],
                    $ok ? 'Remis au serveur d\'envoi pour ' . $mail . ' — la réception n\'est pas prouvée.'
                        : 'Échec : ' . ((string)($m['error_message'] ?? '') ?: 'raison non journalisée'));
            } else {
                $etapes[] = $etape('mail', 'Invitation par mail', $ouverte ? 'ko' : 'attente', null,
                    $ouverte ? 'Aucune trace d\'envoi vers ' . $mail . ' dans le journal.' : 'Pas encore convoqué.');
            }

            // 3. Le SMS d'invitation
            $tel = trim((string)($s['destinataire_tel'] ?? ''));
            if ($tel === '') {
                $etapes[] = $etape('sms', 'Invitation par SMS', 'na', null,
                    'Aucun mobile : le signataire passe par le mail (mêmes jeton et cérémonie).');
            } elseif (!$smsDispo) {
                $etapes[] = $etape('sms', 'Invitation par SMS', 'inconnu', null, 'Journal SMS illisible (table absente ?).');
            } elseif (isset($smsParSig[$id]['SIGNATURE'])) {
                $r  = $smsParSig[$id]['SIGNATURE'];
                $st2 = (string)($r['statut'] ?? '');
                /* ⚠️ « Accepté par l'opérateur » n'est pas « reçu », et sur un numéro
                   invraisemblable ça ne veut carrément rien dire : le SMS est facturé et
                   personne ne l'a. On le dit ICI, à l'endroit où l'agent croirait que
                   l'invitation est partie. */
                $susTel = function_exists('sms_numero_suspect') ? sms_numero_suspect($tel) : '';
                $etapes[] = $etape('sms', 'Invitation par SMS',
                    $st2 === 'envoye' ? ($susTel !== '' ? 'warn' : 'ok') : ($st2 === 'simule' ? 'simule' : 'ko'),
                    (string)($r['sent_at'] ?: $r['created_at']),
                    $st2 === 'envoye' ? ($susTel !== ''
                        ? 'Accepté par l\'opérateur, mais ' . $tel . ' est invraisemblable (' . $susTel
                          . ') : facturé, et probablement reçu par personne.'
                        : 'Accepté par l\'opérateur (id ' . (string)$r['provider_message_id'] . ', '
                          . (int)$r['nb_segments'] . ' segment' . ((int)$r['nb_segments'] > 1 ? 's' : '') . ').')
                        : ($st2 === 'simule' ? 'Vérification à blanc : rien n\'a été envoyé.'
                        : 'Refusé : ' . ((string)($r['error_message'] ?? '') ?: (string)$r['error_code'] ?: 'raison inconnue')));
            } else {
                $etapes[] = $etape('sms', 'Invitation par SMS', $ouverte ? 'ko' : 'attente', null,
                    $ouverte ? 'Aucun SMS enregistré pour ce signataire.' : 'Pas encore convoqué.');
            }

            // 4. La page a été ouverte — le code ne peut être demandé QUE depuis elle.
            if (!empty($smsParSig[$id]['OTP']) || !empty($s['otp_sms_id'])) {
                $r = $smsParSig[$id]['OTP'] ?? null;
                $etapes[] = $etape('ouverture', 'Page ouverte', 'ok',
                    $r ? (string)($r['sent_at'] ?: $r['created_at']) : null,
                    'Un code a été demandé : la personne était bien sur la page.');
            } elseif ($signe) {
                $etapes[] = $etape('ouverture', 'Page ouverte', 'ok', null, 'Déduit de la signature.');
            } else {
                $etapes[] = $etape('ouverture', 'Page ouverte', 'attente', null, 'Le lien n\'a pas encore été ouvert.');
            }

            // 5. Identité démontrée (code validé)
            if (!empty($s['otp_valide_at'])) {
                $etapes[] = $etape('identite', 'Code validé', 'ok', (string)$s['otp_valide_at'],
                    'Détention du mobile enregistré démontrée.');
            } elseif ($tel === '') {
                $etapes[] = $etape('identite', 'Code validé', 'na', null,
                    'Sans mobile : la preuve repose sur le lien nominatif, l\'horodatage et l\'IP.');
            } else {
                $etapes[] = $etape('identite', 'Code validé', 'attente', null, 'Code pas encore saisi.');
            }

            // 6. Annexes ouvertes
            if (!empty($s['annexes_lues_at'])) {
                $n = 0;
                $j = json_decode((string)($s['annexes_lues_json'] ?? ''), true);
                if (is_array($j)) $n = count($j);
                $etapes[] = $etape('annexes', 'Annexes consultées', 'ok', (string)$s['annexes_lues_at'],
                    $n > 0 ? $n . ' pièce' . ($n > 1 ? 's' : '') . ' réellement ouverte' . ($n > 1 ? 's' : '') . '.' : 'Pièces ouvertes.');
            } else {
                $etapes[] = $etape('annexes', 'Annexes consultées', 'attente', null, 'Aucune pièce ouverte pour l\'instant.');
            }

            // 7. La signature
            if ($signe) {
                // Le mode est stocké en code ; on l'écrit en français, pas en jargon.
                $modes = ['trace' => 'tracé au doigt', 'clavier' => 'nom au clavier', 'presentiel' => 'signé en présentiel'];
                $mode  = $modes[(string)($s['signature_mode'] ?? '')] ?? (string)($s['signature_mode'] ?? '');
                $etapes[] = $etape('signature', 'Signé', 'ok', (string)($s['signed_at'] ?? ''),
                    trim(($mode !== '' ? $mode . ' · ' : '') . 'IP ' . ((string)($s['ip'] ?? '') ?: '—')));
            } else {
                $etapes[] = $etape('signature', 'Signé', 'attente', null, 'En attente de sa signature.');
            }

            // 8. L'acte assemblé (bail + annexes + justificatifs, empreinte gravée)
            if (!empty($s['doc_combine_id'])) {
                $etapes[] = $etape('acte', 'Acte assemblé', 'ok', null,
                    'Document combiné n° ' . (int)$s['doc_combine_id']
                    . (!empty($s['doc_combine_hash']) ? ' · empreinte ' . substr((string)$s['doc_combine_hash'], 0, 12) . '…' : ''));
            } else {
                $etapes[] = $etape('acte', 'Acte assemblé', 'attente', null, 'Assemblé à la dernière signature de la cérémonie.');
            }

            // ── L'état du LIEN, séparé des étapes : c'est ce qui décide d'une relance ──
            $expire = null; $reste = null;
            $base   = (string)($s['sent_at'] ?? '');
            if ($base !== '' && !$signe) {
                $ts     = strtotime($base);
                $fin    = $ts !== false ? $ts + BSIG_TTL_MIN * 60 : null;
                $expire = $fin ? date('Y-m-d H:i:s', $fin) : null;
                $reste  = $fin ? (int)floor(($fin - time()) / 3600) : null;
            }
            $out[$id] = [
                'etapes' => $etapes,
                // `arme` à NULL quand c'est signé : il n'y a plus de lien à juger,
                // et afficher « lien mort » sur un acte abouti serait inquiétant pour rien.
                'lien'   => ['arme' => $signe ? null : !bsig_is_expired($s), 'expire_le' => $expire, 'reste_h' => $reste],
            ];
        }
        return $out;
    }
}
