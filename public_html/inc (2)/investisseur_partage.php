<?php
declare(strict_types=1);

/**
 * inc/investisseur_partage.php
 *
 * Génération et validation des liens magiques de partage propriétaire.
 *
 * Flow :
 *   1. $id = inv_partage_create($pdo, ['type'=>'presentation','id_ref'=>42,'destinataire_email'=>'x@y.com'])
 *   2. inv_partage_send_email($pdo, $id) — envoie le mail avec le lien
 *   3. Destinataire clique → ouvre p/investisseur.php?t=TOKEN
 *   4. Le token est vérifié, la consultation trackée
 */

require_once __DIR__ . '/investisseur_helpers.php';

if (!function_exists('inv_partage_generate_token')) {
    function inv_partage_generate_token(): string {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('inv_partage_create')) {
    /**
     * $data requis :
     *   - type : 'presentation' | 'analyse' | 'scenario' | 'portefeuille'
     *   - id_ref : id de la ressource
     *   - destinataire_email : string
     * $data optionnel :
     *   - destinataire_nom
     *   - message
     *   - jours : durée d'expiration (défaut 30)
     */
    function inv_partage_create(PDO $pdo, array $data): int {
        $scope = inv_current_scope();
        $type  = (string)($data['type'] ?? 'presentation');
        if (!in_array($type, ['presentation','analyse','scenario','portefeuille'], true)) {
            throw new RuntimeException('Type de partage invalide.');
        }
        $email = trim((string)($data['destinataire_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email destinataire invalide.');
        }
        $jours = (int)($data['jours'] ?? 30);
        if ($jours < 1) $jours = 30;
        if ($jours > 365) $jours = 365;

        $token = inv_partage_generate_token();
        $expireAt = (new DateTime('+' . $jours . ' days'))->format('Y-m-d H:i:s');

        $st = $pdo->prepare("INSERT INTO investisseur_partages
            (token, type, id_ref, id_societe, id_agence, id_user_crea,
             destinataire_email, destinataire_nom, message, expire_at)
            VALUES (:t, :ty, :ref, :ids, :ida, :idu, :em, :nom, :msg, :exp)");
        $st->bindValue(':t',   $token);
        $st->bindValue(':ty',  $type);
        $st->bindValue(':ref', !empty($data['id_ref']) ? (int)$data['id_ref'] : null, !empty($data['id_ref']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':ids', $scope['id_societe'], $scope['id_societe'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':ida', $scope['id_agence'],  $scope['id_agence']  ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':idu', $scope['id_user'],    $scope['id_user']    ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':em',  $email);
        $st->bindValue(':nom', trim((string)($data['destinataire_nom'] ?? '')));
        $st->bindValue(':msg', trim((string)($data['message'] ?? '')));
        $st->bindValue(':exp', $expireAt);
        $st->execute();
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('inv_partage_load_by_token')) {
    function inv_partage_load_by_token(PDO $pdo, string $token): ?array {
        $st = $pdo->prepare("SELECT * FROM investisseur_partages WHERE token = :t LIMIT 1");
        $st->bindValue(':t', $token);
        $st->execute();
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('inv_partage_is_valid')) {
    function inv_partage_is_valid(array $p): bool {
        if (!empty($p['revoque'])) return false;
        if (empty($p['expire_at'])) return false;
        return strtotime((string)$p['expire_at']) > time();
    }
}

if (!function_exists('inv_partage_track_consultation')) {
    function inv_partage_track_consultation(PDO $pdo, int $id, string $ip = ''): void {
        $st = $pdo->prepare("UPDATE investisseur_partages
            SET consulte_count = consulte_count + 1,
                consulte_at = COALESCE(consulte_at, NOW()),
                last_consult_ip = :ip
            WHERE id = :id");
        $st->bindValue(':ip', $ip ?: ($_SERVER['REMOTE_ADDR'] ?? ''));
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
    }
}

if (!function_exists('inv_partage_build_url')) {
    function inv_partage_build_url(string $token): string {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
        $base   = function_exists('app_url') ? app_url('/p/investisseur.php') : '/p/investisseur.php';
        // Si app_url est absolu, on ne re-préfixe pas
        if (strpos($base, 'http') === 0) return $base . '?t=' . $token;
        return $scheme . '://' . $host . $base . '?t=' . $token;
    }
}

if (!function_exists('inv_partage_revoke')) {
    function inv_partage_revoke(PDO $pdo, int $id): bool {
        $st = $pdo->prepare("UPDATE investisseur_partages SET revoque = 1 WHERE id = :id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        return (bool)$st->rowCount();
    }
}

if (!function_exists('inv_partage_list')) {
    function inv_partage_list(PDO $pdo, array $filters = []): array {
        $scope = inv_current_scope();
        $where = []; $params = [];
        if ($scope['id_societe'] !== null) {
            $where[] = 'id_societe = :s';
            $params[':s'] = $scope['id_societe'];
        }
        if (!empty($filters['type']))   { $where[] = 'type = :ty';    $params[':ty']  = $filters['type']; }
        if (!empty($filters['id_ref'])) { $where[] = 'id_ref = :ref'; $params[':ref'] = (int)$filters['id_ref']; }
        $sql = "SELECT * FROM investisseur_partages";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY created_at DESC LIMIT 100";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ENVOI EMAIL
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_partage_send_email')) {
    /**
     * Envoie le lien magique par email au destinataire.
     * Retourne [ok => bool, error => ?string]
     */
    function inv_partage_send_email(PDO $pdo, int $idPartage): array {
        $st = $pdo->prepare("SELECT * FROM investisseur_partages WHERE id = :id LIMIT 1");
        $st->bindValue(':id', $idPartage, PDO::PARAM_INT);
        $st->execute();
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'error' => 'Partage introuvable'];

        require_once __DIR__ . '/mailer.php';
        $url = inv_partage_build_url((string)$p['token']);

        // Pour user qui envoie : récupérer son email pour Reply-To
        $replyTo = '';
        if (!empty($p['id_user_crea'])) {
            try {
                $st2 = $pdo->prepare("SELECT email, CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,'')) AS nom FROM users WHERE id = :id");
                $st2->bindValue(':id', (int)$p['id_user_crea'], PDO::PARAM_INT);
                $st2->execute();
                $u = $st2->fetch(PDO::FETCH_ASSOC);
                if ($u && !empty($u['email'])) $replyTo = $u['email'];
                $senderName = trim((string)($u['nom'] ?? '')) ?: 'MaBoxImmo';
            } catch (Throwable $e) { $senderName = 'MaBoxImmo'; }
        } else {
            $senderName = 'MaBoxImmo';
        }

        $destinataire = $p['destinataire_nom'] ?: $p['destinataire_email'];
        $typeLabel = match ($p['type']) {
            'presentation'  => 'une présentation d\'investissement',
            'analyse'       => 'une analyse d\'investissement',
            'scenario'      => 'un scénario de valorisation',
            'portefeuille'  => 'un portefeuille',
            default         => 'un document',
        };
        $expDate = date('d/m/Y', strtotime((string)$p['expire_at']));

        $subject = 'MaBoxImmo — ' . ucfirst($typeLabel) . ' à votre attention';

        $body = '<div style="font-family: Arial, sans-serif; color: #2c2a28; max-width: 600px; margin: 0 auto;">';
        $body .= '<h2 style="color: #24324a; font-family: Georgia, serif;">Bonjour ' . htmlspecialchars($destinataire) . ',</h2>';
        $body .= '<p style="font-size: 15px; line-height: 1.6;">';
        $body .= htmlspecialchars($senderName) . ' vous transmet ' . $typeLabel . '.</p>';

        if (!empty($p['message'])) {
            $body .= '<div style="margin: 20px 0; padding: 14px 18px; background: #f9f7f2; border-left: 4px solid #4f7a3a; border-radius: 4px; font-size: 14px; line-height: 1.55;">';
            $body .= nl2br(htmlspecialchars((string)$p['message']));
            $body .= '</div>';
        }

        $body .= '<p style="margin-top: 28px;">';
        $body .= '<a href="' . htmlspecialchars($url) . '" style="display: inline-block; padding: 14px 28px; background: #24324a; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600;">';
        $body .= '→ Consulter le document</a></p>';

        $body .= '<p style="margin-top: 28px; font-size: 12px; color: #999;">';
        $body .= 'Ce lien sécurisé est valable jusqu\'au <strong>' . $expDate . '</strong>. ';
        $body .= 'Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>';
        $body .= '<span style="color: #4878a6; word-break: break-all;">' . htmlspecialchars($url) . '</span>';
        $body .= '</p>';

        $body .= '<p style="margin-top: 28px; padding-top: 14px; border-top: 1px solid #eee; font-size: 11px; color: #999;">';
        $body .= 'Document confidentiel — merci de ne pas transférer ce lien. Pour toute question, répondez à cet email.</p>';
        $body .= '</div>';

        try {
            $ok = send_mail(
                (string)$p['destinataire_email'],
                $subject,
                $body,
                [],
                true,
                '',
                $replyTo
            );
            return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'send_mail a retourné false'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
