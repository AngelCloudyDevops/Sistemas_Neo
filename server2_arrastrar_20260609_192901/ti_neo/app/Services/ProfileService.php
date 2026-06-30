<?php

declare(strict_types=1);

namespace TiNeo\Services;

use PDO;
use RuntimeException;
use TiNeo\Core\Sql;

final class ProfileService
{
    private const DEFAULT_ACCENT = '#7c3aed';
    private const DEFAULT_DAILY_GOAL = 60;

    public function __construct(private readonly PDO $db)
    {
    }

    public function get(array $sessionUser): array
    {
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Sesion invalida.');
        }

        $this->ensureProfileTable();
        $user = $this->userRow($userId);
        $profile = $this->profileRow($userId);
        $merged = $this->mergeProfile($user, $profile);
        $widgets = $this->widgets($userId, (int) $merged['meta_diaria']);

        return [
            'perfil' => $merged,
            'widgets' => $widgets,
            'reconocimientos' => $this->recognitions($widgets),
            'alertas' => $this->alerts($widgets),
            'password_reset' => $this->passwordResetStatus($userId),
        ];
    }

    public function save(array $input, array $sessionUser): array
    {
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Sesion invalida.');
        }

        $displayName = $this->cleanNullable((string) ($input['nombre_visible'] ?? ''), 160);
        $accent = trim((string) ($input['color_acento'] ?? self::DEFAULT_ACCENT));
        $dailyGoal = (int) ($input['meta_diaria'] ?? self::DEFAULT_DAILY_GOAL);
        $avatarUrl = $this->cleanNullable((string) ($input['avatar_url'] ?? ''), 500);
        $backgroundUrl = $this->cleanNullable((string) ($input['background_url'] ?? ''), 500);
        $backgroundOpacity = $this->normalizeOpacity($input['background_opacity'] ?? 0.12);
        if ($avatarUrl === 'https://...') {
            $avatarUrl = null;
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            throw new RuntimeException('Selecciona un color valido.');
        }

        if ($dailyGoal < 1 || $dailyGoal > 999) {
            throw new RuntimeException('La meta diaria debe estar entre 1 y 999.');
        }

        if ($avatarUrl !== null && !$this->isAllowedAvatarUrl($avatarUrl)) {
            throw new RuntimeException('La URL del avatar debe iniciar con http o https, o ser un avatar predefinido.');
        }

        if ($backgroundUrl !== null && !$this->isAllowedBackgroundUrl($backgroundUrl)) {
            throw new RuntimeException('La URL del fondo debe iniciar con http o https, o ser un fondo predefinido.');
        }

        $this->ensureProfileTable();
        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                'MERGE dbo.core_usuario_perfiles AS target
                 USING (
                    SELECT
                        :usuario_id AS usuario_id,
                        CAST(:nombre_visible AS NVARCHAR(160)) AS nombre_visible,
                        CAST(:color_acento AS VARCHAR(20)) AS color_acento,
                        CAST(:meta_diaria AS INT) AS meta_diaria,
                        CAST(:avatar_url AS NVARCHAR(500)) AS avatar_url,
                        CAST(:background_url AS NVARCHAR(500)) AS background_url,
                        CAST(:background_opacity AS DECIMAL(4,2)) AS background_opacity
                 ) AS source
                    ON target.usuario_id = source.usuario_id
                 WHEN MATCHED THEN
                    UPDATE SET nombre_visible = source.nombre_visible,
                               color_principal = source.color_acento,
                               meta_diaria_tickets = source.meta_diaria,
                               avatar_url = source.avatar_url,
                               background_url = source.background_url,
                               background_opacity = source.background_opacity,
                               actualizado_en = SYSDATETIME()
                 WHEN NOT MATCHED THEN
                    INSERT (usuario_id, nombre_visible, color_principal, meta_diaria_tickets, avatar_url, background_url, background_opacity, creado_en, actualizado_en)
                    VALUES (source.usuario_id, source.nombre_visible, source.color_acento, source.meta_diaria, source.avatar_url, source.background_url, source.background_opacity, SYSDATETIME(), SYSDATETIME());'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO core_usuario_perfiles (
                    usuario_id, nombre_visible, color_principal, meta_diaria_tickets, avatar_url, background_url, background_opacity, actualizado_en
                 ) VALUES (
                    :usuario_id, :nombre_visible, :color_acento, :meta_diaria, :avatar_url, :background_url, :background_opacity, NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    nombre_visible = VALUES(nombre_visible),
                    color_principal = VALUES(color_principal),
                    meta_diaria_tickets = VALUES(meta_diaria_tickets),
                    avatar_url = VALUES(avatar_url),
                    background_url = VALUES(background_url),
                    background_opacity = VALUES(background_opacity),
                    actualizado_en = NOW()'
            );
        }

        $stmt->execute([
            'usuario_id' => $userId,
            'nombre_visible' => $displayName,
            'color_acento' => strtolower($accent),
            'meta_diaria' => $dailyGoal,
            'avatar_url' => $avatarUrl,
            'background_url' => $backgroundUrl,
            'background_opacity' => $backgroundOpacity,
        ]);

        return $this->get($sessionUser);
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

    private function ensureProfileTable(): void
    {
        try {
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
        } catch (\Throwable $exception) {
            throw new RuntimeException('No se pudo preparar la tabla de perfil global. Ejecuta la migracion core_usuario_perfiles.');
        }
    }

    private function ensurePasswordResetTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec(
                "IF OBJECT_ID('dbo.core_password_reset_requests', 'U') IS NULL
                 BEGIN
                    CREATE TABLE dbo.core_password_reset_requests (
                        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_password_reset_requests PRIMARY KEY,
                        usuario_id BIGINT NOT NULL,
                        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_password_reset_requests_estado DEFAULT ('pendiente'),
                        solicitado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_password_reset_requests_solicitado DEFAULT (SYSDATETIME()),
                        aprobado_por BIGINT NULL,
                        aprobado_en DATETIME2(0) NULL,
                        actualizado_en DATETIME2(0) NULL
                    );
                    CREATE UNIQUE INDEX UX_core_password_reset_requests_pendiente
                        ON dbo.core_password_reset_requests(usuario_id)
                        WHERE estado = 'pendiente';
                 END"
            );
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS core_password_reset_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                usuario_id BIGINT UNSIGNED NOT NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                solicitado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                aprobado_por BIGINT UNSIGNED NULL,
                aprobado_en TIMESTAMP NULL,
                actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_core_password_reset_requests_estado (estado),
                INDEX idx_core_password_reset_requests_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function userRow(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombres, apellidos, email, area, cargo
             FROM core_usuarios
             WHERE id = :id'
        );
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
        try {
            $stmt = $this->db->prepare(
                'SELECT nombre_visible, cargo_area, color_principal AS color_acento, meta_diaria_tickets AS meta_diaria, avatar_url, background_url, background_opacity
                 FROM core_usuario_perfiles
                 WHERE usuario_id = :usuario_id'
            );
            $stmt->execute(['usuario_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function mergeProfile(array $user, array $profile): array
    {
        $baseName = trim((string) (($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? '')));
        $roleArea = trim((string) (($user['cargo'] ?? '') ?: ($user['area'] ?? '')));
        $displayName = trim((string) ($profile['nombre_visible'] ?? '')) ?: $baseName ?: 'Usuario';
        $finalRoleArea = trim((string) ($profile['cargo_area'] ?? '')) ?: $roleArea ?: 'Equipo de atencion';

        return [
            'nombre_visible' => $displayName,
            'cargo_area' => $finalRoleArea,
            'correo' => (string) ($user['email'] ?? ''),
            'color_acento' => (string) ($profile['color_acento'] ?? self::DEFAULT_ACCENT),
            'meta_diaria' => (int) ($profile['meta_diaria'] ?? self::DEFAULT_DAILY_GOAL),
            'avatar_url' => (string) ($profile['avatar_url'] ?? ''),
            'background_url' => (string) ($profile['background_url'] ?? ''),
            'background_opacity' => (float) ($profile['background_opacity'] ?? 0.12),
            'iniciales' => $this->initials($displayName),
        ];
    }

    private function widgets(int $userId, int $dailyGoal): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $todayCount = $this->ticketCountByDate($userId, $today);
        $yesterdayCount = $this->ticketCountByDate($userId, $yesterday);
        $bestDay = $this->bestDay($userId);
        $bestHour = $this->bestHour($userId, $today);
        $progress = min(100, (int) floor(($todayCount / max(1, $dailyGoal)) * 100));

        return [
            'fecha' => $today,
            'hoy' => $todayCount,
            'ayer' => $yesterdayCount,
            'diferencia' => $todayCount - $yesterdayCount,
            'meta_diaria' => $dailyGoal,
            'meta_porcentaje' => $progress,
            'faltan_meta' => max(0, $dailyGoal - $todayCount),
            'mejor_dia' => $bestDay,
            'mejor_hora' => $bestHour,
            'anulaciones_hoy' => $this->cancellationsToday($userId, $today),
            'reimpresiones_hoy' => $this->reprintsToday($userId, $today),
            'lotes_pendientes' => $this->pendingBatches($userId),
        ];
    }

    private function ticketCountByDate(int $userId, string $date): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM st_tickets
             WHERE creado_por = :user_id
               AND fecha = :fecha
               AND estado <> 'anulado'"
        );
        $stmt->execute(['user_id' => $userId, 'fecha' => $date]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function bestDay(int $userId): array
    {
        $sql = Sql::isSqlServer($this->db)
            ? "SELECT TOP 1 fecha, COUNT(*) AS total
               FROM st_tickets
               WHERE creado_por = :user_id AND estado <> 'anulado'
               GROUP BY fecha
               ORDER BY total DESC, fecha DESC"
            : "SELECT fecha, COUNT(*) AS total
               FROM st_tickets
               WHERE creado_por = :user_id AND estado <> 'anulado'
               GROUP BY fecha
               ORDER BY total DESC, fecha DESC
               LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'fecha' => (string) ($row['fecha'] ?? ''),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    private function bestHour(int $userId, string $date): array
    {
        $hour = Sql::isSqlServer($this->db) ? 'DATEPART(hour, hora)' : 'HOUR(hora)';
        $limit = Sql::isSqlServer($this->db) ? 'TOP 1 ' : '';
        $tail = Sql::isSqlServer($this->db) ? '' : ' LIMIT 1';
        $stmt = $this->db->prepare(
            "SELECT {$limit}{$hour} AS hora, COUNT(*) AS total
             FROM st_tickets
             WHERE creado_por = :user_id
               AND fecha = :fecha
               AND estado <> 'anulado'
             GROUP BY {$hour}
             ORDER BY total DESC, hora ASC{$tail}"
        );
        $stmt->execute(['user_id' => $userId, 'fecha' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rawHour = $row['hora'] ?? null;

        return [
            'hora' => $rawHour === null ? '' : str_pad((string) (int) $rawHour, 2, '0', STR_PAD_LEFT) . ':00',
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    private function cancellationsToday(int $userId, string $date): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM st_tickets
             WHERE anulado_por = :user_id
               AND fecha = :fecha
               AND estado = 'anulado'"
        );
        $stmt->execute(['user_id' => $userId, 'fecha' => $date]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function reprintsToday(int $userId, string $date): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(reimpresiones), 0)
             FROM st_tickets
             WHERE creado_por = :user_id
               AND fecha = :fecha"
        );
        $stmt->execute(['user_id' => $userId, 'fecha' => $date]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function pendingBatches(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM st_lotes
             WHERE creado_por = :user_id
               AND estado IN ('pendiente', 'en_impresion', 'interrumpido')"
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function recognitions(array $widgets): array
    {
        $items = [];
        if ((int) $widgets['hoy'] >= (int) $widgets['meta_diaria']) {
            $items[] = 'Meta diaria completada.';
        }
        if ((int) $widgets['diferencia'] > 0) {
            $items[] = 'Vas por encima de ayer.';
        }
        if ((int) $widgets['hoy'] > 0 && (int) $widgets['anulaciones_hoy'] === 0) {
            $items[] = 'Jornada sin anulaciones.';
        }
        if ((int) ($widgets['mejor_hora']['total'] ?? 0) >= 10) {
            $items[] = 'Buen ritmo en tu mejor hora.';
        }

        return $items ?: ['Empieza la jornada y registra tus primeros tickets.'];
    }

    private function alerts(array $widgets): array
    {
        $items = [];
        if ((int) $widgets['lotes_pendientes'] > 0) {
            $items[] = (int) $widgets['lotes_pendientes'] . ' lote(s) pendiente(s) de gestion.';
        }
        if ((int) $widgets['anulaciones_hoy'] > 0) {
            $items[] = 'Revisa las anulaciones de hoy.';
        }
        if ((int) $widgets['faltan_meta'] > 0) {
            $items[] = 'Faltan ' . (int) $widgets['faltan_meta'] . ' ticket(s) para tu meta.';
        }

        return $items ?: ['Sin alertas operativas por ahora.'];
    }

    private function cleanNullable(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $first = substr((string) ($parts[0] ?? 'U'), 0, 1);
        $second = substr((string) ($parts[1] ?? ''), 0, 1);

        return strtoupper($first . $second);
    }
}
