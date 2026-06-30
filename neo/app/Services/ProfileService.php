<?php

declare(strict_types=1);

namespace Neo\Services;

use Neo\Core\Sql;
use PDO;
use RuntimeException;

final class ProfileService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function get(array $sessionUser): array
    {
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Sesion invalida.');
        }

        $this->ensureTable();
        return [
            'perfil' => $this->merge($this->userRow($userId), $this->profileRow($userId)),
            'password_reset' => $this->passwordResetStatus($userId),
        ];
    }

    public function save(array $input, array $sessionUser): array
    {
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Sesion invalida.');
        }

        $displayName = $this->clean((string) ($input['nombre_visible'] ?? ''), 160);
        $accent = trim((string) ($input['color_principal'] ?? $input['color_acento'] ?? '#7c3aed'));
        $avatarUrl = $this->clean((string) ($input['avatar_url'] ?? ''), 500);
        $backgroundUrl = $this->clean((string) ($input['background_url'] ?? ''), 500);
        $backgroundOpacity = $this->normalizeOpacity($input['background_opacity'] ?? 0.12);

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            throw new RuntimeException('Selecciona un color valido.');
        }

        if ($avatarUrl !== null && !$this->isAllowedAvatarUrl($avatarUrl)) {
            throw new RuntimeException('Selecciona un avatar valido o usa una URL http/https.');
        }

        if ($backgroundUrl !== null && !$this->isAllowedBackgroundUrl($backgroundUrl)) {
            throw new RuntimeException('Selecciona un fondo valido o usa una URL http/https.');
        }

        $this->ensureTable();
        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                'MERGE dbo.core_usuario_perfiles AS target
                 USING (SELECT :usuario_id usuario_id, :nombre_visible nombre_visible, :color_principal color_principal, :avatar_url avatar_url, :background_url background_url, :background_opacity background_opacity) AS source
                    ON target.usuario_id = source.usuario_id
                 WHEN MATCHED THEN
                    UPDATE SET nombre_visible = source.nombre_visible, color_principal = source.color_principal, avatar_url = source.avatar_url, background_url = source.background_url, background_opacity = source.background_opacity, actualizado_en = SYSDATETIME()
                 WHEN NOT MATCHED THEN
                    INSERT (usuario_id, nombre_visible, color_principal, meta_diaria_tickets, avatar_url, background_url, background_opacity, creado_en, actualizado_en)
                    VALUES (source.usuario_id, source.nombre_visible, source.color_principal, 60, source.avatar_url, source.background_url, source.background_opacity, SYSDATETIME(), SYSDATETIME());'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO core_usuario_perfiles (usuario_id, nombre_visible, color_principal, avatar_url, background_url, background_opacity, actualizado_en)
                 VALUES (:usuario_id, :nombre_visible, :color_principal, :avatar_url, :background_url, :background_opacity, NOW())
                 ON DUPLICATE KEY UPDATE nombre_visible = VALUES(nombre_visible), color_principal = VALUES(color_principal), avatar_url = VALUES(avatar_url), background_url = VALUES(background_url), background_opacity = VALUES(background_opacity), actualizado_en = NOW()'
            );
        }

        $stmt->execute([
            'usuario_id' => $userId,
            'nombre_visible' => $displayName,
            'color_principal' => strtolower($accent),
            'avatar_url' => $avatarUrl,
            'background_url' => $backgroundUrl,
            'background_opacity' => $backgroundOpacity,
        ]);

        return $this->get($sessionUser);
    }

    public function requestPasswordReset(array $sessionUser): array
    {
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Sesion invalida.');
        }

        $this->ensurePasswordResetTable();
        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                "IF EXISTS (SELECT 1 FROM dbo.core_password_reset_requests WHERE usuario_id = :check_user_id AND estado = 'pendiente')
                    UPDATE dbo.core_password_reset_requests SET actualizado_en = SYSDATETIME() WHERE usuario_id = :update_user_id AND estado = 'pendiente'
                 ELSE
                    INSERT INTO dbo.core_password_reset_requests (usuario_id, estado, solicitado_en, actualizado_en) VALUES (:insert_user_id, 'pendiente', SYSDATETIME(), SYSDATETIME())"
            );
            $stmt->execute(['check_user_id' => $userId, 'update_user_id' => $userId, 'insert_user_id' => $userId]);
        } else {
            $stmt = $this->db->prepare("UPDATE core_password_reset_requests SET actualizado_en = NOW() WHERE usuario_id = :usuario_id AND estado = 'pendiente'");
            $stmt->execute(['usuario_id' => $userId]);
            if ($stmt->rowCount() === 0) {
                $stmt = $this->db->prepare("INSERT INTO core_password_reset_requests (usuario_id, estado, solicitado_en, actualizado_en) VALUES (:usuario_id, 'pendiente', NOW(), NOW())");
                $stmt->execute(['usuario_id' => $userId]);
            }
        }

        return ['estado' => 'pendiente'];
    }

    public function changePassword(array $input, array $sessionUser): array
    {
        $userId = (int) ($sessionUser['id'] ?? 0);
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['confirm_password'] ?? '');
        if ($userId <= 0) {
            throw new RuntimeException('Sesion invalida.');
        }
        if ($password === '' || strlen($password) < 8) {
            throw new RuntimeException('La contrasena debe tener minimo 8 caracteres.');
        }
        if ($password !== $confirm) {
            throw new RuntimeException('Las contrasenas no coinciden.');
        }
        if (!$this->canChangePassword($userId)) {
            throw new RuntimeException('El reinicio aun no fue aprobado por GS NEO.');
        }

        $table = Sql::isSqlServer($this->db) ? 'dbo.core_usuarios' : 'core_usuarios';
        $requests = Sql::isSqlServer($this->db) ? 'dbo.core_password_reset_requests' : 'core_password_reset_requests';
        $now = Sql::now($this->db);
        $stmt = $this->db->prepare("UPDATE {$table} SET password_hash = :hash, must_change_password = 0, actualizado_en = {$now} WHERE id = :id");
        $stmt->execute(['hash' => password_hash($password, PASSWORD_BCRYPT), 'id' => $userId]);
        $stmt = $this->db->prepare("UPDATE {$requests} SET estado = 'completado', actualizado_en = {$now} WHERE usuario_id = :id AND estado = 'aprobado'");
        $stmt->execute(['id' => $userId]);

        return ['estado' => 'completado'];
    }

    private function ensureTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec("IF OBJECT_ID('dbo.core_usuario_perfiles', 'U') IS NULL CREATE TABLE dbo.core_usuario_perfiles (usuario_id BIGINT NOT NULL CONSTRAINT PK_core_usuario_perfiles PRIMARY KEY, nombre_visible NVARCHAR(160) NULL, cargo_area NVARCHAR(160) NULL, color_principal VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_perfiles_color DEFAULT ('#7c3aed'), meta_diaria_tickets INT NOT NULL CONSTRAINT DF_core_usuario_perfiles_meta DEFAULT (60), avatar_url NVARCHAR(500) NULL, creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_perfiles_creado DEFAULT (SYSDATETIME()), actualizado_en DATETIME2(0) NULL)");
            $this->db->exec("IF COL_LENGTH('dbo.core_usuario_perfiles', 'background_url') IS NULL ALTER TABLE dbo.core_usuario_perfiles ADD background_url NVARCHAR(500) NULL");
            $this->db->exec("IF COL_LENGTH('dbo.core_usuario_perfiles', 'background_opacity') IS NULL ALTER TABLE dbo.core_usuario_perfiles ADD background_opacity DECIMAL(4,2) NOT NULL CONSTRAINT DF_core_usuario_perfiles_bg_opacity DEFAULT (0.12)");
            return;
        }

        $this->db->exec("CREATE TABLE IF NOT EXISTS core_usuario_perfiles (usuario_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, nombre_visible VARCHAR(160) NULL, cargo_area VARCHAR(160) NULL, color_principal VARCHAR(20) NOT NULL DEFAULT '#7c3aed', meta_diaria_tickets INT NOT NULL DEFAULT 60, avatar_url VARCHAR(500) NULL, background_url VARCHAR(500) NULL, background_opacity DECIMAL(4,2) NOT NULL DEFAULT 0.12, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->exec("ALTER TABLE core_usuario_perfiles ADD COLUMN IF NOT EXISTS background_url VARCHAR(500) NULL");
        $this->db->exec("ALTER TABLE core_usuario_perfiles ADD COLUMN IF NOT EXISTS background_opacity DECIMAL(4,2) NOT NULL DEFAULT 0.12");
    }

    private function ensurePasswordResetTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec("IF OBJECT_ID('dbo.core_password_reset_requests', 'U') IS NULL BEGIN CREATE TABLE dbo.core_password_reset_requests (id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_password_reset_requests PRIMARY KEY, usuario_id BIGINT NOT NULL, estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_password_reset_requests_estado DEFAULT ('pendiente'), solicitado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_password_reset_requests_solicitado DEFAULT (SYSDATETIME()), aprobado_por BIGINT NULL, aprobado_en DATETIME2(0) NULL, actualizado_en DATETIME2(0) NULL); CREATE UNIQUE INDEX UX_core_password_reset_requests_pendiente ON dbo.core_password_reset_requests(usuario_id) WHERE estado = 'pendiente'; END");
            return;
        }

        $this->db->exec("CREATE TABLE IF NOT EXISTS core_password_reset_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, usuario_id BIGINT UNSIGNED NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'pendiente', solicitado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, aprobado_por BIGINT UNSIGNED NULL, aprobado_en TIMESTAMP NULL, actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, INDEX idx_core_password_reset_requests_estado (estado), INDEX idx_core_password_reset_requests_usuario (usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function userRow(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, nombres, apellidos, email, area, cargo FROM core_usuarios WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        return $row;
    }

    private function canChangePassword(int $userId): bool
    {
        $table = Sql::isSqlServer($this->db) ? 'dbo.core_usuarios' : 'core_usuarios';
        $stmt = $this->db->prepare("SELECT must_change_password FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function isAllowedAvatarUrl(string $avatarUrl): bool
    {
        if (preg_match('/^https?:\/\/\S+$/i', $avatarUrl)) {
            return true;
        }

        return preg_match('#^(?:\.?/)?assets/img/avatars/avatar_\d{2}\.(?:jpg|jpeg|png|webp)$#i', $avatarUrl) === 1
            || preg_match('#/(?:neo|ti_neo|gs_neo|inventario_neo)/public/assets/img/avatars/avatar_\d{2}\.(?:jpg|jpeg|png|webp)$#i', $avatarUrl) === 1;
    }

    private function isAllowedBackgroundUrl(string $backgroundUrl): bool
    {
        if (preg_match('/^https?:\/\/\S+$/i', $backgroundUrl)) {
            return true;
        }

        return preg_match('#^(?:\.?/)?assets/img/backgrounds/bg_\d{2}\.(?:jpg|jpeg|png|webp|svg)$#i', $backgroundUrl) === 1
            || preg_match('#/(?:neo|ti_neo|gs_neo|inventario_neo)/public/assets/img/backgrounds/bg_\d{2}\.(?:jpg|jpeg|png|webp|svg)$#i', $backgroundUrl) === 1;
    }

    private function normalizeOpacity(mixed $value): float
    {
        $opacity = is_numeric($value) ? (float) $value : 0.12;
        if ($opacity > 1) {
            $opacity /= 100;
        }

        return max(0.0, min(0.35, $opacity));
    }

    private function passwordResetStatus(int $userId): array
    {
        $this->ensurePasswordResetTable();
        $table = Sql::isSqlServer($this->db) ? 'dbo.core_password_reset_requests' : 'core_password_reset_requests';
        $order = Sql::isSqlServer($this->db) ? 'SELECT TOP 1' : 'SELECT';
        $limit = Sql::isSqlServer($this->db) ? '' : ' LIMIT 1';
        $stmt = $this->db->prepare("{$order} estado, solicitado_en, aprobado_en FROM {$table} WHERE usuario_id = :id ORDER BY id DESC{$limit}");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $canChange = $this->canChangePassword($userId);
        return [
            'estado' => $canChange ? 'aprobado' : (string) ($row['estado'] ?? 'sin_solicitud'),
            'habilitado' => $canChange,
            'solicitado_en' => (string) ($row['solicitado_en'] ?? ''),
            'aprobado_en' => (string) ($row['aprobado_en'] ?? ''),
        ];
    }

    private function profileRow(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT nombre_visible, cargo_area, color_principal, avatar_url, background_url, background_opacity FROM core_usuario_perfiles WHERE usuario_id = :usuario_id');
        $stmt->execute(['usuario_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private function merge(array $user, array $profile): array
    {
        $baseName = trim((string) (($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? '')));
        $displayName = trim((string) ($profile['nombre_visible'] ?? '')) ?: $baseName ?: 'Usuario';
        $roleArea = trim((string) ($profile['cargo_area'] ?? '')) ?: trim((string) (($user['cargo'] ?? '') ?: ($user['area'] ?? ''))) ?: 'Usuario';

        return [
            'nombre_visible' => $displayName,
            'cargo_area' => $roleArea,
            'correo' => (string) ($user['email'] ?? ''),
            'color_principal' => (string) ($profile['color_principal'] ?? '#7c3aed'),
            'avatar_url' => (string) ($profile['avatar_url'] ?? ''),
            'background_url' => (string) ($profile['background_url'] ?? ''),
            'background_opacity' => (float) ($profile['background_opacity'] ?? 0.12),
        ];
    }

    private function clean(string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
