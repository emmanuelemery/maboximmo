<?php
declare(strict_types=1);

/**
 * Suivi enrichi des consultations de portefeuilles partagés.
 * Insère un événement daté dans portefeuille_consultation (best-effort :
 * jamais bloquant pour l'affichage public p.php).
 *
 * @param array $envoi  ligne portefeuille_envois (au moins id, id_portefeuille, email_destinataire)
 * @param string $type  open | view_bien | download
 * @param array $opt    ['id_bien'=>int, 'doc_id'=>int, 'doc_label'=>string]
 */
function pf_track(PDO $pdo, array $envoi, string $type, array $opt = []): void
{
    try {
        $ipRaw = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip    = substr(trim(explode(',', $ipRaw)[0]), 0, 64);
        $ua    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $pdo->prepare(
            "INSERT INTO portefeuille_consultation
               (id_envoi, id_portefeuille, email, type, id_bien, doc_id, doc_label, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            (int)($envoi['id'] ?? 0),
            ((int)($envoi['id_portefeuille'] ?? 0)) ?: null,
            ((string)($envoi['email_destinataire'] ?? '')) ?: null,
            $type,
            isset($opt['id_bien']) ? (int)$opt['id_bien'] : null,
            isset($opt['doc_id']) ? (int)$opt['doc_id'] : null,
            isset($opt['doc_label']) ? substr((string)$opt['doc_label'], 0, 190) : null,
            $ip,
            $ua,
        ]);
    } catch (Throwable) { /* best-effort : ne jamais casser p.php */ }
}
