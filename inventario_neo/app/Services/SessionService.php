<?php

declare(strict_types=1);

namespace InventarioNeo\Services;

use InventarioNeo\Core\Sql;
use PDO;
use RuntimeException;

final class SessionService
{
    public function __construct(private readonly PDO $db, private readonly array $config)
    {
    }

    public function create(int $userId, string $system): array
    {
        $this->ensureTable();
        $refreshToken = $this->newRefreshToken();
        $ttl = $this->refreshTtl();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $stmt = $this->db->prepare(
            Sql::isSqlServer($this->db)
                ? 'INSERT INTO dbo.core_sesiones (usuario_id, sistema_codigo, refresh_token_hash, ip, user_agent, expira_en, ultimo_uso_en, estado, creado_en) OUTPUT INSERTED.id VALUES (:usuario_id, :sistema_codigo, :refresh_token_hash, :ip, :user_agent, :expira_en, SYSDATETIME(), \'activo\', SYSDATETIME())'
                : 'INSERT INTO core_sesiones (usuario_id, sistema_codigo, refresh_token_hash, ip, user_agent, expira_en, ultimo_uso_en, estado, creado_en) VALUES (:usuario_id, :sistema_codigo, :refresh_token_hash, :ip, :user_agent, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW(), \'activo\', NOW())'
        );
        $params = [
            'usuario_id' => $userId,
            'sistema_codigo' => $system,
            'refresh_token_hash' => $this->hash($refreshToken),
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'expira_en' => $expiresAt,
        ];
        if (!Sql::isSqlServer($this->db)) {
            unset($params['expira_en']);
            $params['ttl'] = $ttl;
        }
        $stmt->execute($params);

        return [
            'session_id' => Sql::isSqlServer($this->db) ? (int) $stmt->fetchColumn() : (int) $this->db->lastInsertId(),
            'refresh_token' => $refreshToken,
            'refresh_expires_in' => $ttl,
        ];
    }

    public function renew(string $refreshToken): array
    {
        $this->ensureTable();
        $table = Sql::isSqlServer($this->db) ? 'dbo.core_sesiones' : 'core_sesiones';
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE refresh_token_hash = :hash AND estado = 'activo'");
        $stmt->execute(['hash' => $this->hash($refreshToken)]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($session)) {
            throw new RuntimeException('Sesion no encontrada.');
        }

        $now = time();
        $expiresAt = strtotime((string) $session['expira_en']);
        $lastUsedAt = strtotime((string) ($session['ultimo_uso_en'] ?? $session['creado_en']));
        if ($expiresAt !== false && $expiresAt <= $now) {
            $this->revokeById((int) $session['id']);
            throw new RuntimeException('Sesion vencida.');
        }
        if ($lastUsedAt !== false && ($now - $lastUsedAt) > $this->inactivityTtl()) {
            $this->revokeById((int) $session['id']);
            throw new RuntimeException('Sesion vencida por inactividad.');
        }

        $stmt = $this->db->prepare("UPDATE {$table} SET ultimo_uso_en = " . Sql::now($this->db) . ' WHERE id = :id');
        $stmt->execute(['id' => (int) $session['id']]);

        return ['session_id' => (int) $session['id'], 'user_id' => (int) $session['usuario_id']];
    }

    public function revoke(string $refreshToken): void
    {
        $this->ensureTable();
        $table = Sql::isSqlServer($this->db) ? 'dbo.core_sesiones' : 'core_sesiones';
        $stmt = $this->db->prepare("UPDATE {$table} SET estado = 'revocado', revocado_en = " . Sql::now($this->db) . ' WHERE refresh_token_hash = :hash');
        $stmt->execute(['hash' => $this->hash($refreshToken)]);
    }

    private function revokeById(int $sessionId): void
    {
        $table = Sql::isSqlServer($this->db) ? 'dbo.core_sesiones' : 'core_sesiones';
        $stmt = $this->db->prepare("UPDATE {$table} SET estado = 'revocado', revocado_en = " . Sql::now($this->db) . ' WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
    }

    private function ensureTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec("IF OBJECT_ID('dbo.core_sesiones', 'U') IS NULL BEGIN CREATE TABLE dbo.core_sesiones (id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_sesiones PRIMARY KEY, usuario_id BIGINT NOT NULL, sistema_codigo VARCHAR(60) NOT NULL, refresh_token_hash CHAR(64) NOT NULL, ip VARCHAR(80) NULL, user_agent NVARCHAR(500) NULL, expira_en DATETIME2(0) NOT NULL, ultimo_uso_en DATETIME2(0) NOT NULL, estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_sesiones_estado DEFAULT ('activo'), creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_sesiones_creado DEFAULT (SYSDATETIME()), revocado_en DATETIME2(0) NULL, CONSTRAINT FK_core_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id)); CREATE UNIQUE INDEX UX_core_sesiones_refresh_hash ON dbo.core_sesiones(refresh_token_hash); CREATE INDEX IX_core_sesiones_usuario_estado ON dbo.core_sesiones(usuario_id, estado, expira_en); END");
            $this->db->exec("IF COL_LENGTH('dbo.core_sesiones', 'revocado_en') IS NULL BEGIN ALTER TABLE dbo.core_sesiones ADD revocado_en DATETIME2(0) NULL; END");
            $this->db->exec("IF COL_LENGTH('dbo.core_sesiones', 'ultimo_uso_en') IS NULL BEGIN ALTER TABLE dbo.core_sesiones ADD ultimo_uso_en DATETIME2(0) NULL; END");
            return;
        }

        $this->db->exec("CREATE TABLE IF NOT EXISTS core_sesiones (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, usuario_id BIGINT UNSIGNED NOT NULL, sistema_codigo VARCHAR(60) NOT NULL, refresh_token_hash CHAR(64) NOT NULL, ip VARCHAR(80) NULL, user_agent VARCHAR(500) NULL, expira_en DATETIME NOT NULL, ultimo_uso_en DATETIME NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'activo', creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revocado_en DATETIME NULL, UNIQUE KEY ux_core_sesiones_refresh_hash (refresh_token_hash), KEY idx_core_sesiones_usuario_estado (usuario_id, estado, expira_en), CONSTRAINT fk_core_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function newRefreshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function refreshTtl(): int
    {
        return (int) ($this->config['security']['refresh_ttl_seconds'] ?? 43200);
    }

    private function inactivityTtl(): int
    {
        return (int) ($this->config['security']['session_inactivity_seconds'] ?? 3600);
    }

    private function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
    }

    private function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }
}
