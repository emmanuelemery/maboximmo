<?php
declare(strict_types=1);
/**
 * inc/communications.php — Écriture du JOURNAL MÉTIER des communications.
 *
 * Deux fonctions seulement :
 *   · `comm_contexte()` — la page dit DE QUEL DOSSIER elle parle, avant d'envoyer ;
 *   · `comm_log()`      — appelée par `send_mail()` et `sms_envoyer()`, jamais
 *                          par les 51 points d'appel.
 *
 * ── Pourquoi un contexte AMBIANT plutôt qu'un paramètre ─────────────────────
 * `send_mail()` est appelée depuis 42 fichiers. Lui ajouter un paramètre
 * « dossier » obligerait à rouvrir les 51 appels, et un seul oubli produirait un
 * envoi orphelin — silencieusement. Le contexte se pose donc À CÔTÉ : la page qui
 * sait de quoi elle parle le déclare, les autres n'ont rien à changer et leurs
 * envois sont quand même journalisés, simplement sans dossier.
 *
 * ⚠️ Le contexte est un état de REQUÊTE, pas de session : il meurt avec le script.
 * Une requête = un dossier. `comm_contexte_effacer()` existe pour les scripts de
 * lot qui enchaînent plusieurs dossiers dans le même processus.
 *
 * ⚠️🔥 RÈGLE REPRISE, PAS REDÉCOUVERTE : un secret ne s'écrit jamais dans un
 * journal consultable. `sms_envoyer()` masque déjà les codes OTP ; ici on masque
 * à nouveau à l'écriture de `extrait`, parce que TOUT canal peut transporter un
 * secret et que ce journal-ci est fait pour être lu par des collaborateurs.
 *
 * ⚠️ Journaliser ne doit JAMAIS empêcher d'envoyer. Tout est sous `try` muet :
 * une table absente, une colonne renommée, un disque plein — le mail part quand
 * même. L'inverse (un envoi bloqué par son journal) serait absurde.
 */

if (!function_exists('comm_contexte')) {
    /**
     * Déclare le dossier auquel se rattachent les envois qui suivent.
     * Ex. : comm_contexte('BAIL', 660) puis send_mail(...) → la ligne est rattachée.
     */
    function comm_contexte(string $objetType, int $objetId, ?int $idTiers = null): void
    {
        $GLOBALS['__COMM_CTX__'] = [
            'objet_type' => strtoupper(trim($objetType)) ?: null,
            'objet_id'   => $objetId > 0 ? $objetId : null,
            'id_tiers'   => ($idTiers !== null && $idTiers > 0) ? $idTiers : null,
        ];
    }
}

if (!function_exists('comm_contexte_effacer')) {
    function comm_contexte_effacer(): void { unset($GLOBALS['__COMM_CTX__']); }
}

if (!function_exists('comm_contexte_get')) {
    function comm_contexte_get(): array
    {
        $c = $GLOBALS['__COMM_CTX__'] ?? [];
        return is_array($c) ? $c : [];
    }
}

if (!function_exists('comm_masquer')) {
    /**
     * Un extrait lisible, débarrassé de ce qui ne doit pas se lire.
     *
     * ⚠️ Les suites de 4 à 8 chiffres partent en premier : c'est la forme d'un
     * code de signature. Puis les IBAN, qu'un journal n'a aucune raison de porter.
     * On coupe ENSUITE seulement — masquer après troncature laisserait passer un
     * secret situé au-delà de la coupe si la longueur changeait un jour.
     */
    function comm_masquer(string $texte, int $max = 400, bool $aplatir = true): string
    {
        /* ⚠️ Les balises de BLOC deviennent des sauts de ligne AVANT `strip_tags()`.
           Sans ça « <p>Bonjour,</p><p>Veuillez… » donne « Bonjour,Veuillez… » :
           le message reste techniquement lisible, mais plus personne ne le lit.
           `$aplatir` sépare les deux usages : l'extrait de liste tient sur une
           ligne, le message complet garde ses paragraphes. */
        $t = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])[^>]*>#i', "
", $texte) ?? $texte;
        $t = trim(strip_tags($t));
        /* ⚠️ L'IBAN EN PREMIER. Mesuré au test : masquer les chiffres d'abord
           découpait « FR76 3000 4000 … » en groupes masqués, la règle IBAN ne
           reconnaissait plus rien, et le dernier groupe de 3 chiffres survivait.
           On retire donc les motifs LONGS avant les motifs courts. */
        $t = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9 ]{10,30}\b/', '[IBAN]', $t) ?? $t;
        $t = preg_replace('/\b\d{4,8}\b/', '••••', $t) ?? $t;
        $t = $aplatir
            ? (preg_replace('/\s+/', ' ', $t) ?? $t)
            : (preg_replace(['/[ 	]+/', '/
{3,}/'], [' ', "

"], $t) ?? $t);
        return trim(mb_substr($t, 0, $max));
    }
}

if (!function_exists('comm_log')) {
    /**
     * Écrit une ligne de journal. Best-effort ABSOLU : ne lève jamais, ne bloque
     * jamais l'envoi qui l'a déclenchée.
     *
     * @param array{canal:string,destinataire:string,destinataire_nom?:string,
     *              sujet?:string,corps?:string,nb_pieces?:int,statut?:string,
     *              error_message?:string,ref_technique?:string,expediteur?:string,
     *              id_societe?:int,id_user?:int,objet_type?:string,objet_id?:int,
     *              id_tiers?:int} $d
     */
    function comm_log(array $d): void
    {
        try {
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!$pdo instanceof PDO) return;

            $dest = trim((string)($d['destinataire'] ?? ''));
            if ($dest === '') return;   // sans destinataire, la ligne n'apprend rien

            $ctx = comm_contexte_get();
            /* L'identité vient de la SESSION : c'est elle qui porte le collaborateur
               et sa société. Un automate (cron, webhook) n'en a pas — il est
               journalisé sans user, ce qui est l'information juste. */
            /* ⚠️ `?:` et non `??` : un appelant qui passe explicitement 0 (« je ne
               sais pas ») doit retomber sur la session, alors que `??` ne réagit
               qu'à null et aurait enregistré « société inconnue » pour un envoi
               parfaitement identifiable. Mesuré au premier test. */
            $idUser = (int)($d['id_user']    ?? 0) ?: ((int)($_SESSION['user_id']    ?? 0) ?: null);
            $idSoc  = (int)($d['id_societe'] ?? 0) ?: ((int)($_SESSION['id_societe'] ?? 0) ?: null);

            /* ⚠️🔥 Le piège du `in_array(($d['x'] ?? 'def')) ? $d['x'] : 'def'` :
               quand la clé MANQUE, le test passe grâce au défaut… puis la branche
               vraie relit `$d['x']`, qui est indéfini → NULL → colonne NOT NULL en
               violation, et la ligne est perdue. On résout la valeur AVANT de la
               valider, une seule fois. */
            $canal  = (string)($d['canal']  ?? 'mail');
            if (!in_array($canal, ['mail','sms','courrier','appel'], true)) $canal = 'mail';
            $statut = (string)($d['statut'] ?? 'envoye');
            if (!in_array($statut, ['envoye','echec','simule'], true)) $statut = 'envoye';

            $idAgence = null;
            if ($idUser) {
                try {
                    $q = $pdo->prepare("SELECT id_agence FROM users WHERE id = ? LIMIT 1");
                    $q->execute([$idUser]);
                    $idAgence = (int)($q->fetchColumn() ?: 0) ?: null;
                } catch (Throwable) { /* colonne absente : on filtre par société */ }
            }

            $st = $pdo->prepare("INSERT INTO communications
                (canal, sens, id_societe, id_agence, id_user, expediteur,
                 destinataire, destinataire_nom, id_tiers, objet_type, objet_id,
                 sujet, extrait, corps, pieces_json, nb_pieces, statut, error_message,
                 ref_technique, ip, created_at)
                VALUES (?, 'sortant', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->execute([
                $canal,
                $idSoc, $idAgence, $idUser,
                mb_substr(trim((string)($d['expediteur'] ?? '')), 0, 190) ?: null,
                mb_substr($dest, 0, 190),
                mb_substr(trim((string)($d['destinataire_nom'] ?? '')), 0, 150) ?: null,
                (int)($d['id_tiers'] ?? ($ctx['id_tiers'] ?? 0)) ?: null,
                mb_substr((string)($d['objet_type'] ?? ($ctx['objet_type'] ?? '')), 0, 30) ?: null,
                (int)($d['objet_id'] ?? ($ctx['objet_id'] ?? 0)) ?: null,
                mb_substr(trim((string)($d['sujet'] ?? '')), 0, 255) ?: null,
                comm_masquer((string)($d['corps'] ?? '')) ?: null,
                /* Le message ENTIER, mais soumis au MÊME masquage que l'extrait :
                   afficher plus ne veut pas dire protéger moins. `$max` est relevé,
                   la règle ne change pas. */
                comm_masquer((string)($d['corps'] ?? ''), 60000, false) ?: null,
                /* Les NOMS des pièces, jamais leurs chemins : un chemin serveur
                   dans un journal, c'est une carte du disque offerte au lecteur. */
                !empty($d['pieces']) && is_array($d['pieces'])
                    ? json_encode(array_values(array_map(
                        static fn($f) => basename((string)$f), $d['pieces'])), JSON_UNESCAPED_UNICODE)
                    : null,
                max(0, (int)($d['nb_pieces'] ?? 0)),
                $statut,
                mb_substr(trim((string)($d['error_message'] ?? '')), 0, 255) ?: null,
                mb_substr(trim((string)($d['ref_technique'] ?? '')), 0, 64) ?: null,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);
        } catch (Throwable $e) {
            // Un journal qui empêche d'envoyer serait pire que pas de journal.
            error_log('[comm_log] ' . $e->getMessage());
        }
    }
}
