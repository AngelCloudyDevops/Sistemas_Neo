<?php

declare(strict_types=1);

namespace TiNeo\Models;

use PDO;
use TiNeo\Core\Sql;

final class User
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findActiveByLogin(string $login): ?array
    {
        $login = strtolower(trim($login));
        $sql = Sql::isSqlServer($this->db)
            ? 'SELECT TOP 1
                u.id,
                u.rol_id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                u.password_hash,
                u.must_change_password,
                u.estado,
                r.nombre AS rol_nombre
             FROM core_usuarios u
             INNER JOIN core_roles r ON r.id = u.rol_id
             WHERE (LOWER(u.email) = :login_email OR u.dni = :login_dni)
               AND u.estado = \'activo\'
               AND r.estado = \'activo\''
            : 'SELECT
                u.id,
                u.rol_id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                u.password_hash,
                u.must_change_password,
                u.estado,
                r.nombre AS rol_nombre
             FROM core_usuarios u
             INNER JOIN core_roles r ON r.id = u.rol_id
             WHERE (LOWER(u.email) = :login_email OR u.dni = :login_dni)
               AND u.estado = \'activo\'
               AND r.estado = \'activo\'
             LIMIT 1';

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'login_email' => $login,
            'login_dni' => $login,
        ]);
        $user = $stmt->fetch();

        return is_array($user) ? $this->withGlobalProfile($user) : null;
    }

    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE core_usuarios SET ultimo_login_en = ' . Sql::now($this->db) . ' WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    public function findActiveById(int $id): ?array
    {
        $sql = Sql::isSqlServer($this->db)
            ? 'SELECT TOP 1 u.id, u.rol_id, u.dni, u.nombres, u.apellidos, u.email, u.password_hash, u.must_change_password, u.estado, r.nombre AS rol_nombre FROM core_usuarios u INNER JOIN core_roles r ON r.id = u.rol_id WHERE u.id = :id AND u.estado = \'activo\' AND r.estado = \'activo\''
            : 'SELECT u.id, u.rol_id, u.dni, u.nombres, u.apellidos, u.email, u.password_hash, u.must_change_password, u.estado, r.nombre AS rol_nombre FROM core_usuarios u INNER JOIN core_roles r ON r.id = u.rol_id WHERE u.id = :id AND u.estado = \'activo\' AND r.estado = \'activo\' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return is_array($user) ? $this->withGlobalProfile($user) : null;
    }

    private function withGlobalProfile(array $user): array
    {
        $profile = [];

        try {
            $this->ensureGlobalProfileTable();
            $stmt = $this->db->prepare(
                'SELECT nombre_visible, cargo_area, color_principal, meta_diaria_tickets, avatar_url, background_url, background_opacity
                 FROM core_usuario_perfiles
                 WHERE usuario_id = :usuario_id'
            );
            $stmt->execute(['usuario_id' => (int) $user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $profile = is_array($row) ? $row : [];
        } catch (\Throwable) {
            $profile = [];
        }

        $baseName = trim((string) (($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? '')));
        $displayName = trim((string) ($profile['nombre_visible'] ?? '')) ?: $baseName ?: (string) ($user['email'] ?? 'Usuario');
        $roleArea = trim((string) ($profile['cargo_area'] ?? '')) ?: (string) ($user['rol_nombre'] ?? 'Usuario');

        $user['nombre'] = $displayName;
        $user['perfil'] = [
            'nombre_visible' => $displayName,
            'cargo_area' => $roleArea,
            'color_principal' => (string) ($profile['color_principal'] ?? '#7c3aed'),
            'avatar_url' => (string) ($profile['avatar_url'] ?? ''),
            'background_url' => (string) ($profile['background_url'] ?? ''),
            'background_opacity' => (float) ($profile['background_opacity'] ?? 0.12),
            'iniciales' => $this->initials($displayName),
            'meta_diaria_tickets' => (int) ($profile['meta_diaria_tickets'] ?? 60),
        ];

        return $user;
    }

    private function ensureGlobalProfileTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec(
                "IF OBJECT_ID('dbo.core_usuario_perfiles', 'U') IS NULL
                 BEGIN
                    CREATE TABLE dbo.core_usuario_perfiles (
                        usuario_id BIGINT NOT NULL CONSTRAINT PK_core_usuario_perfiles PRIMARY KEY,
                        nombre_visible NVARCHAR(160) NULL,
                        cargo_area NVARCHAR(160) NULL,
                        color_principal VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_perfiles_color DEFAULT ('#7c3aed'),
                        meta_diaria_tickets INT NOT NULL CONSTRAINT DF_core_usuario_perfiles_meta DEFAULT (60),
                        avatar_url NVARCHAR(500) NULL,
                        background_url NVARCHAR(500) NULL,
                        background_opacity DECIMAL(4,2) NOT NULL CONSTRAINT DF_core_usuario_perfiles_bg_opacity DEFAULT (0.12),
                        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_perfiles_creado DEFAULT (SYSDATETIME()),
                        actualizado_en DATETIME2(0) NULL,
                        CONSTRAINT FK_core_usuario_perfiles_usuario
                            FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id) ON DELETE CASCADE,
                        CONSTRAINT CK_core_usuario_perfiles_meta CHECK (meta_diaria_tickets BETWEEN 1 AND 999)
                    );
                 END"
            );
            $this->db->exec("IF COL_LENGTH('dbo.core_usuario_perfiles', 'background_url') IS NULL ALTER TABLE dbo.core_usuario_perfiles ADD background_url NVARCHAR(500) NULL");
            $this->db->exec("IF COL_LENGTH('dbo.core_usuario_perfiles', 'background_opacity') IS NULL ALTER TABLE dbo.core_usuario_perfiles ADD background_opacity DECIMAL(4,2) NOT NULL CONSTRAINT DF_core_usuario_perfiles_bg_opacity DEFAULT (0.12)");
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS core_usuario_perfiles (
                usuario_id BIGINT UNSIGNED NOT NULL,
                nombre_visible VARCHAR(160) NULL,
                cargo_area VARCHAR(160) NULL,
                color_principal VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
                meta_diaria_tickets INT NOT NULL DEFAULT 60,
                avatar_url VARCHAR(500) NULL,
                background_url VARCHAR(500) NULL,
                background_opacity DECIMAL(4,2) NOT NULL DEFAULT 0.12,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (usuario_id),
                CONSTRAINT fk_core_usuario_perfiles_usuario
                    FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT ck_core_usuario_perfiles_meta CHECK (meta_diaria_tickets BETWEEN 1 AND 999)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec("ALTER TABLE core_usuario_perfiles ADD COLUMN IF NOT EXISTS background_url VARCHAR(500) NULL");
        $this->db->exec("ALTER TABLE core_usuario_perfiles ADD COLUMN IF NOT EXISTS background_opacity DECIMAL(4,2) NOT NULL DEFAULT 0.12");
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $first = substr((string) ($parts[0] ?? 'U'), 0, 1);
        $second = substr((string) ($parts[1] ?? ''), 0, 1);

        return strtoupper($first . $second);
    }
}
