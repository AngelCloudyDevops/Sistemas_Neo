<?php

declare(strict_types=1);

namespace TiNeo\Services;

use PDO;
use RuntimeException;
use TiNeo\Core\Sql;

final class ScreenDeviceService
{
    private const GLOBAL_TICKER_KEY = 'pantallas_ticker_global';
    private const DEFAULT_TICKER = 'Hospital San Jose del Callao - Mantenga su DNI a la mano y acerquese cuando vea su turno en pantalla.';

    public function __construct(private readonly PDO $db)
    {
    }

    public function listDevices(): array
    {
        $this->ensureTable();
        $stmt = $this->db->query(
            'SELECT id, codigo, nombre, ubicacion, formato, media_tipo, media_url, mensaje,
                    activo, ultimo_pulso_en, ultima_ip, ultimo_user_agent, creado_en, actualizado_en
             FROM st_pantallas_dispositivos
             ORDER BY nombre ASC, codigo ASC'
        );

        return array_map(fn (array $row): array => $this->formatDevice($row), $stmt->fetchAll());
    }

    public function create(array $input, int $userId): array
    {
        $this->ensureTable();
        $name = trim((string) ($input['nombre'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Ingresa un nombre para la pantalla.');
        }

        $code = $this->normalizeCode((string) ($input['codigo'] ?? $name));
        if ($code === '') {
            throw new RuntimeException('No se pudo generar el codigo de pantalla.');
        }

        if ($this->findRawByCode($code) !== null) {
            throw new RuntimeException('Ya existe una pantalla con ese codigo.');
        }

        $plainKey = $this->newKey();
        $format = $this->normalizeFormat((string) ($input['formato'] ?? 'turnos_clasico'));
        $params = [
            'codigo' => $code,
            'nombre' => $name,
            'ubicacion' => trim((string) ($input['ubicacion'] ?? '')),
            'formato' => $format,
            'media_tipo' => null,
            'media_url' => null,
            'mensaje' => trim((string) ($input['mensaje'] ?? 'Hospital San Jose del Callao - Mantenga su DNI a la mano.')),
            'clave_hash' => $this->hashKey($plainKey),
            'creado_por' => $userId,
        ];

        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                'INSERT INTO dbo.st_pantallas_dispositivos
                    (codigo, nombre, ubicacion, formato, media_tipo, media_url, mensaje, clave_hash, activo, creado_por, creado_en, actualizado_en)
                 OUTPUT INSERTED.id
                 VALUES
                    (:codigo, :nombre, :ubicacion, :formato, :media_tipo, :media_url, :mensaje, :clave_hash, 1, :creado_por, SYSDATETIME(), SYSDATETIME())'
            );
            $stmt->execute($params);
            $id = (int) $stmt->fetchColumn();
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO st_pantallas_dispositivos
                    (codigo, nombre, ubicacion, formato, media_tipo, media_url, mensaje, clave_hash, activo, creado_por, creado_en, actualizado_en)
                 VALUES
                    (:codigo, :nombre, :ubicacion, :formato, :media_tipo, :media_url, :mensaje, :clave_hash, 1, :creado_por, NOW(), NOW())'
            );
            $stmt->execute($params);
            $id = (int) $this->db->lastInsertId();
        }

        return [
            'device' => $this->findById($id),
            'key' => $plainKey,
        ];
    }

    public function regenerateKey(int $id): array
    {
        $this->ensureTable();
        $device = $this->findById($id);
        if ($device === null) {
            throw new RuntimeException('Pantalla no encontrada.');
        }

        $plainKey = $this->newKey();
        $stmt = $this->db->prepare(
            'UPDATE st_pantallas_dispositivos
             SET clave_hash = :clave_hash, actualizado_en = ' . Sql::now($this->db) . '
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'clave_hash' => $this->hashKey($plainKey),
        ]);

        return [
            'device' => $this->findById($id),
            'key' => $plainKey,
        ];
    }

    public function updateFormat(int $id, string $format, string $mediaUrl = ''): array
    {
        $this->ensureTable();
        $device = $this->findById($id);
        if ($device === null) {
            throw new RuntimeException('Pantalla no encontrada.');
        }
        $normalizedFormat = $this->normalizeFormat($format);
        $mediaType = null;
        $mediaUrl = trim($mediaUrl);

        if ($normalizedFormat === 'imagen_url') {
            $mediaType = 'imagen';
            $this->assertValidMediaUrl($mediaUrl);
        } else {
            $mediaUrl = '';
        }

        $stmt = $this->db->prepare(
            'UPDATE st_pantallas_dispositivos
             SET formato = :formato,
                 media_tipo = :media_tipo,
                 media_url = :media_url,
                 actualizado_en = ' . Sql::now($this->db) . '
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'formato' => $normalizedFormat,
            'media_tipo' => $mediaType,
            'media_url' => $mediaUrl !== '' ? $mediaUrl : null,
        ]);

        return [
            'device' => $this->findById($id),
        ];
    }

    public function updateDetails(int $id, string $name, string $location): array
    {
        $this->ensureTable();
        $device = $this->findById($id);
        if ($device === null) {
            throw new RuntimeException('Pantalla no encontrada.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Ingresa un nombre para la pantalla.');
        }

        $stmt = $this->db->prepare(
            'UPDATE st_pantallas_dispositivos
             SET nombre = :nombre,
                 ubicacion = :ubicacion,
                 actualizado_en = ' . Sql::now($this->db) . '
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'nombre' => $name,
            'ubicacion' => trim($location),
        ]);

        return [
            'device' => $this->findById($id),
        ];
    }

    public function globalTicker(): string
    {
        try {
            $stmt = $this->db->prepare('SELECT valor FROM st_configuracion WHERE clave = :clave');
            $stmt->execute(['clave' => self::GLOBAL_TICKER_KEY]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        } catch (\Throwable) {
            return self::DEFAULT_TICKER;
        }

        return self::DEFAULT_TICKER;
    }

    public function saveGlobalTicker(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            $message = self::DEFAULT_TICKER;
        }

        if (strlen($message) > 300) {
            throw new RuntimeException('El ticker no puede superar 300 caracteres.');
        }

        Sql::upsertConfig($this->db, self::GLOBAL_TICKER_KEY, $message);

        return ['ticker' => $this->globalTicker()];
    }

    public function delete(int $id): void
    {
        $this->ensureTable();
        $device = $this->findById($id);
        if ($device === null) {
            throw new RuntimeException('Pantalla no encontrada.');
        }

        $stmt = $this->db->prepare('DELETE FROM st_pantallas_dispositivos WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function validatePublicDevice(string $code, string $key): array
    {
        $this->ensureTable();
        $device = $this->findRawByCode($this->normalizeCode($code));
        if ($device === null || (int) ($device['activo'] ?? 0) !== 1) {
            throw new RuntimeException('Pantalla no registrada o inactiva.');
        }

        if (!hash_equals((string) $device['clave_hash'], $this->hashKey($key))) {
            throw new RuntimeException('Clave de pantalla invalida.');
        }

        $this->touch((int) $device['id']);

        return $this->formatDevice($device);
    }

    public function touch(int $id): void
    {
        $staleCondition = Sql::isSqlServer($this->db)
            ? 'ultimo_pulso_en IS NULL OR ultimo_pulso_en < DATEADD(SECOND, -15, SYSDATETIME())'
            : 'ultimo_pulso_en IS NULL OR ultimo_pulso_en < DATE_SUB(NOW(), INTERVAL 15 SECOND)';

        $stmt = $this->db->prepare(
            'UPDATE st_pantallas_dispositivos
             SET ultimo_pulso_en = ' . Sql::now($this->db) . ',
                 ultima_ip = :ip,
                 ultimo_user_agent = :user_agent
             WHERE id = :id
               AND (' . $staleCondition . ')'
        );
        $stmt->execute([
            'id' => $id,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    private function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, codigo, nombre, ubicacion, formato, media_tipo, media_url, mensaje,
                    activo, ultimo_pulso_en, ultima_ip, ultimo_user_agent, creado_en, actualizado_en
             FROM st_pantallas_dispositivos
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->formatDevice($row) : null;
    }

    private function findRawByCode(string $code): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM st_pantallas_dispositivos
             WHERE codigo = :codigo'
        );
        $stmt->execute(['codigo' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function formatDevice(array $row): array
    {
        $lastPulse = (string) ($row['ultimo_pulso_en'] ?? '');
        $status = $this->deviceStatus($lastPulse);

        return [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'nombre' => (string) $row['nombre'],
            'ubicacion' => (string) ($row['ubicacion'] ?? ''),
            'formato' => (string) ($row['formato'] ?? 'turnos_clasico'),
            'media_tipo' => $row['media_tipo'] ?? null,
            'media_url' => $row['media_url'] ?? null,
            'mensaje' => (string) ($row['mensaje'] ?? ''),
            'activo' => (int) ($row['activo'] ?? 0) === 1,
            'ultimo_pulso_en' => $lastPulse !== '' ? $lastPulse : null,
            'ultima_ip' => (string) ($row['ultima_ip'] ?? ''),
            'ultimo_user_agent' => (string) ($row['ultimo_user_agent'] ?? ''),
            'estado_conexion' => $status,
            'creado_en' => (string) ($row['creado_en'] ?? ''),
            'actualizado_en' => (string) ($row['actualizado_en'] ?? ''),
        ];
    }

    private function deviceStatus(string $lastPulse): string
    {
        if ($lastPulse === '') {
            return 'sin_pulso';
        }

        $timestamp = strtotime($lastPulse);
        if ($timestamp === false) {
            return 'sin_pulso';
        }

        $seconds = time() - $timestamp;
        if ($seconds <= 45) {
            return 'en_linea';
        }
        if ($seconds <= 180) {
            return 'inestable';
        }

        return 'sin_senal';
    }

    private function normalizeCode(string $value): string
    {
        $code = strtoupper(trim($value));
        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?: '';
        $code = trim($code, '_');

        return substr($code, 0, 60);
    }

    private function normalizeFormat(string $value): string
    {
        $format = strtolower(trim($value));
        $aliases = [
            'turnero' => 'turnos_clasico',
            'turnos' => 'turnos_clasico',
            'turnos_clasico' => 'turnos_clasico',
            'citas' => 'citas_mes_doctor',
            'citas_mes' => 'citas_mes_doctor',
            'citas_mes_doctor' => 'citas_mes_doctor',
            'citas_limpio' => 'citas_mes_doctor_limpio',
            'citas_mes_limpio' => 'citas_mes_doctor_limpio',
            'citas_mes_doctor_limpio' => 'citas_mes_doctor_limpio',
            'citas_aviso' => 'citas_mes_doctor_aviso',
            'citas_mes_aviso' => 'citas_mes_doctor_aviso',
            'citas_mes_doctor_aviso' => 'citas_mes_doctor_aviso',
            'imagen' => 'imagen_url',
            'imagen_url' => 'imagen_url',
            'image_url' => 'imagen_url',
        ];

        if (!isset($aliases[$format])) {
            throw new RuntimeException('Formato de pantalla no disponible.');
        }

        return $aliases[$format];
    }

    private function assertValidMediaUrl(string $url): void
    {
        if ($url === '') {
            throw new RuntimeException('Ingresa la URL de la imagen.');
        }

        if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('La URL de imagen no es valida.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('La URL de imagen debe iniciar con http o https.');
        }
    }

    private function newKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private function ensureTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec(
                "IF OBJECT_ID('dbo.st_pantallas_dispositivos', 'U') IS NULL
                 BEGIN
                    CREATE TABLE dbo.st_pantallas_dispositivos (
                        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_pantallas_dispositivos PRIMARY KEY,
                        codigo VARCHAR(60) NOT NULL,
                        nombre VARCHAR(120) NOT NULL,
                        ubicacion VARCHAR(160) NULL,
                        formato VARCHAR(40) NOT NULL CONSTRAINT DF_st_pantallas_formato DEFAULT ('turnos_clasico'),
                        media_tipo VARCHAR(20) NULL,
                        media_url VARCHAR(500) NULL,
                        mensaje VARCHAR(300) NULL,
                        clave_hash CHAR(64) NOT NULL,
                        activo BIT NOT NULL CONSTRAINT DF_st_pantallas_activo DEFAULT (1),
                        ultimo_pulso_en DATETIME2(0) NULL,
                        ultima_ip VARCHAR(80) NULL,
                        ultimo_user_agent VARCHAR(255) NULL,
                        creado_por BIGINT NULL,
                        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_pantallas_creado DEFAULT (SYSDATETIME()),
                        actualizado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_pantallas_actualizado DEFAULT (SYSDATETIME())
                    );
                    CREATE UNIQUE INDEX UX_st_pantallas_codigo ON dbo.st_pantallas_dispositivos(codigo);
                 END"
            );

            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS st_pantallas_dispositivos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(60) NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                ubicacion VARCHAR(160) NULL,
                formato VARCHAR(40) NOT NULL DEFAULT 'turnos_clasico',
                media_tipo VARCHAR(20) NULL,
                media_url VARCHAR(500) NULL,
                mensaje VARCHAR(300) NULL,
                clave_hash CHAR(64) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_pulso_en DATETIME NULL,
                ultima_ip VARCHAR(80) NULL,
                ultimo_user_agent VARCHAR(255) NULL,
                creado_por BIGINT UNSIGNED NULL,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY ux_st_pantallas_codigo (codigo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
