<?php
/**
 * inc/AuditLog.php — Audit trail RGPD (Phase 3)
 *
 * Enregistre les actions critiques (DELETE, UPLOAD, LOGIN, UPDATE sensible)
 * dans la table `audit_log`. Compatible multi-tenant via id_societe.
 */
class AuditLog
{
    public static function log(
        PDO $pdo,
        string $action,
        string $table,
        int $recordId,
        array $oldValues = [],
        array $newValues = []
    ): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log
                    (id_societe, id_user, action, table_name, record_id,
                     old_values, new_values, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)($_SESSION['id_societe'] ?? 0),
                (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0),
                strtoupper($action),
                $table,
                $recordId,
                !empty($oldValues) ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                !empty($newValues) ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (PDOException $e) {
            error_log('AuditLog error: ' . $e->getMessage());
        }
    }
}
