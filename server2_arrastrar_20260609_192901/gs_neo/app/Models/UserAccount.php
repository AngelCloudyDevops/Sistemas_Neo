<?php

declare(strict_types=1);

namespace GsNeo\Models;

use PDO;
use GsNeo\Core\Sql;

final class UserAccount
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
                u.personal_id,
                u.rol_id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                u.password_hash,
                u.must_change_password,
                u.estado,
                r.nombre AS rol_nombre
             FROM dbo.core_usuarios u
             LEFT JOIN dbo.core_roles r ON r.id = u.rol_id
             WHERE (LOWER(u.email) = :login_email OR u.dni = :login_dni)
               AND u.estado = \'activo\''
            : 'SELECT
                u.id,
                u.personal_id,
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
             LEFT JOIN core_roles r ON r.id = u.rol_id
             WHERE (LOWER(u.email) = :login_email OR u.dni = :login_dni)
               AND u.estado = \'activo\'
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
        $stmt = $this->db->prepare('UPDATE dbo.core_usuarios SET ultimo_login_en = ' . Sql::now($this->db) . ' WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    public function hasPolicy(int $userId, string $action, string $resource): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $queries = [];
            $params = [
                'user_id_group' => $userId,
                'action_exact' => $action,
                'resource_exact' => $resource,
            ];

            if ($this->tableExists('dbo.core_usuario_politicas')) {
                $queries[] = "SELECT
                    cp.efecto,
                    cp.recurso,
                    up.permitido
                 FROM dbo.core_usuario_politicas up
                 INNER JOIN dbo.core_politicas cp ON cp.id = up.politica_id
                 WHERE up.usuario_id = :user_id_direct
                   AND up.estado = 'activo'
                   AND cp.estado = 'activo'
                   AND cp.accion IN (:action_exact_direct, 'sistema.admin', '*')
                   AND cp.recurso IN (:resource_exact_direct, 'sistema:*', '*')";
                $params['user_id_direct'] = $userId;
                $params['action_exact_direct'] = $action;
                $params['resource_exact_direct'] = $resource;
            }

            $queries[] = "SELECT
                    cp.efecto,
                    cp.recurso,
                    gp.permitido
                 FROM dbo.core_grupo_usuarios gu
                 INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id
                 INNER JOIN dbo.core_grupo_politicas gp ON gp.grupo_id = g.id
                 INNER JOIN dbo.core_politicas cp ON cp.id = gp.politica_id
                 WHERE gu.usuario_id = :user_id_group
                   AND gu.estado = 'activo'
                   AND g.estado = 'activo'
                   AND cp.estado = 'activo'
                   AND cp.accion IN (:action_exact, 'sistema.admin', '*')
                   AND cp.recurso IN (:resource_exact, 'sistema:*', '*')";

            $sql = 'SELECT * FROM (' . implode("\nUNION ALL\n", $queries) . ') p';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false;
        }

        $allowed = false;
        foreach ($rows as $row) {
            $effect = strtolower((string) ($row['efecto'] ?? 'allow'));
            $permitted = (int) ($row['permitido'] ?? 1) === 1;

            if (!$permitted || $effect === 'deny') {
                return false;
            }

            if ($effect === 'allow') {
                $allowed = true;
            }
        }

        return $allowed;
    }

    public function findActiveById(int $id): ?array
    {
        $sql = Sql::isSqlServer($this->db)
            ? 'SELECT TOP 1 u.id, u.personal_id, u.rol_id, u.dni, u.nombres, u.apellidos, u.email, u.password_hash, u.must_change_password, u.estado, r.nombre AS rol_nombre FROM dbo.core_usuarios u LEFT JOIN dbo.core_roles r ON r.id = u.rol_id WHERE u.id = :id AND u.estado = \'activo\''
            : 'SELECT u.id, u.personal_id, u.rol_id, u.dni, u.nombres, u.apellidos, u.email, u.password_hash, u.must_change_password, u.estado, r.nombre AS rol_nombre FROM core_usuarios u LEFT JOIN core_roles r ON r.id = u.rol_id WHERE u.id = :id AND u.estado = \'activo\' LIMIT 1';

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
                 FROM dbo.core_usuario_perfiles
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
        $roleArea = trim((string) ($profile['cargo_area'] ?? '')) ?: 'Usuario';

        $user['nombre'] = $displayName;
        $user['perfil'] = [
            'nombre_visible' => $displayName,
            'cargo_area' => $roleArea,
            'color_principal' => (string) ($profile['color_principal'] ?? '#7c3aed'),
            'avatar_url' => (string) ($profile['avatar_url'] ?? ''),
            'background_url' => (string) ($profile['background_url'] ?? ''),
            'background_opacity' => max(0, min(35, (int) ($profile['background_opacity'] ?? 12))),
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
                        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_perfiles_creado DEFAULT (SYSDATETIME()),
                        actualizado_en DATETIME2(0) NULL,
                        CONSTRAINT FK_core_usuario_perfiles_usuario
                            FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id) ON DELETE CASCADE,
                        CONSTRAINT CK_core_usuario_perfiles_meta CHECK (meta_diaria_tickets BETWEEN 1 AND 999)
                    );
                 END"
            );
            $this->db->exec("IF COL_LENGTH('dbo.core_usuario_perfiles', 'background_url') IS NULL ALTER TABLE dbo.core_usuario_perfiles ADD background_url NVARCHAR(500) NULL");
            $this->db->exec("IF COL_LENGTH('dbo.core_usuario_perfiles', 'background_opacity') IS NULL ALTER TABLE dbo.core_usuario_perfiles ADD background_opacity TINYINT NOT NULL CONSTRAINT DF_core_usuario_perfiles_bg_opacity DEFAULT (12)");
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
        $this->db->exec("ALTER TABLE core_usuario_perfiles ADD COLUMN IF NOT EXISTS background_opacity TINYINT UNSIGNED NOT NULL DEFAULT 12");
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $first = substr((string) ($parts[0] ?? 'U'), 0, 1);
        $second = substr((string) ($parts[1] ?? ''), 0, 1);

        return strtoupper($first . $second);
    }

    public function userAccessSummary(int $userId): array
    {
        $sql = Sql::isSqlServer($this->db)
            ? "SELECT
                u.id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                r.nombre AS rol_nombre
             FROM dbo.core_usuarios u
             LEFT JOIN dbo.core_roles r ON r.id = u.rol_id
             WHERE u.id = :id"
            : "SELECT
                u.id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                r.nombre AS rol_nombre
             FROM core_usuarios u
             LEFT JOIN core_roles r ON r.id = u.rol_id
             WHERE u.id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            return [
                'usuario' => null,
                'rol' => '',
                'grupos' => [],
            ];
        }

        $groupsSql = Sql::isSqlServer($this->db)
            ? "SELECT g.id, g.nombre, g.descripcion
             FROM dbo.core_grupo_usuarios cgu
             INNER JOIN dbo.core_grupos g ON g.id = cgu.grupo_id
             WHERE cgu.usuario_id = :id
               AND cgu.estado = 'activo'
               AND g.estado = 'activo'
             ORDER BY g.nombre"
            : "SELECT g.id, g.nombre, g.descripcion
             FROM core_grupo_usuarios cgu
             INNER JOIN core_grupos g ON g.id = cgu.grupo_id
             WHERE cgu.usuario_id = :id
               AND cgu.estado = 'activo'
               AND g.estado = 'activo'
             ORDER BY g.nombre";

        $groupsStmt = $this->db->prepare($groupsSql);
        $groupsStmt->execute(['id' => $userId]);

        return [
            'usuario' => [
                'id' => (int) $user['id'],
                'dni' => (string) ($user['dni'] ?? ''),
                'nombres' => (string) ($user['nombres'] ?? ''),
                'apellidos' => (string) ($user['apellidos'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
            ],
            'rol' => (string) ($user['rol_nombre'] ?? ''),
            'grupos' => $groupsStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function list(string $search = '', int $page = 1, int $perPage = 20): array
    {
        $this->ensureDirectPoliciesTable();

        $page = max(1, $page);
        $perPage = max(10, min(50, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [];
        $filters = [
            "u.email NOT LIKE 'retirado-%@local.invalid'",
            "u.dni NOT LIKE 'RET-%'",
        ];

        if ($search !== '') {
            $filters[] = '(u.email LIKE :search_email
                OR u.dni LIKE :search_dni
                OR u.nombres LIKE :search_nombres
                OR u.apellidos LIKE :search_apellidos
                OR p.nombre_completo LIKE :search_personal)';
            $likeSearch = '%' . $search . '%';
            $params = [
                'search_email' => $likeSearch,
                'search_dni' => $likeSearch,
                'search_nombres' => $likeSearch,
                'search_apellidos' => $likeSearch,
                'search_personal' => $likeSearch,
            ];
        }

        $where = 'WHERE ' . implode(' AND ', $filters);
        $countSql = "SELECT COUNT(*)
             FROM dbo.core_usuarios u
             LEFT JOIN dbo.core_roles r ON r.id = u.rol_id
             LEFT JOIN dbo.core_personal p ON p.id = u.personal_id
             {$where}";

        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = Sql::isSqlServer($this->db)
            ? "SELECT
                u.id,
                u.personal_id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                u.estado,
                u.must_change_password,
                u.ultimo_login_en,
                r.nombre AS rol_nombre,
                p.nombre_completo AS personal_nombre,
                COALESCE(
                    STUFF((
                        SELECT ', ' + cg.nombre
                        FROM dbo.core_grupo_usuarios cgu
                        INNER JOIN dbo.core_grupos cg ON cg.id = cgu.grupo_id
                        WHERE cgu.usuario_id = u.id
                          AND cgu.estado = 'activo'
                          AND cg.estado = 'activo'
                        ORDER BY cg.nombre
                        FOR XML PATH(''), TYPE
                    ).value('.', 'NVARCHAR(MAX)'), 1, 2, ''),
                    ''
                ) AS grupos,
                COALESCE(
                    STUFF((
                        SELECT ',' + CONVERT(NVARCHAR(30), cg.id)
                        FROM dbo.core_grupo_usuarios cgu
                        INNER JOIN dbo.core_grupos cg ON cg.id = cgu.grupo_id
                        WHERE cgu.usuario_id = u.id
                          AND cgu.estado = 'activo'
                          AND cg.estado = 'activo'
                        ORDER BY cg.nombre
                        FOR XML PATH(''), TYPE
                    ).value('.', 'NVARCHAR(MAX)'), 1, 1, ''),
                    ''
                ) AS grupo_ids,
                COALESCE(
                    STUFF((
                        SELECT ',' + CONVERT(NVARCHAR(30), cp.id)
                        FROM dbo.core_usuario_politicas cup
                        INNER JOIN dbo.core_politicas cp ON cp.id = cup.politica_id
                        WHERE cup.usuario_id = u.id
                          AND cup.estado = 'activo'
                          AND cup.permitido = 1
                          AND cp.estado = 'activo'
                        ORDER BY cp.codigo
                        FOR XML PATH(''), TYPE
                    ).value('.', 'NVARCHAR(MAX)'), 1, 1, ''),
                    ''
                ) AS politica_ids
             FROM dbo.core_usuarios u
             LEFT JOIN dbo.core_roles r ON r.id = u.rol_id
             LEFT JOIN dbo.core_personal p ON p.id = u.personal_id
             {$where}
             ORDER BY u.id DESC
             OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY"
            : "SELECT
                u.id,
                u.personal_id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                u.estado,
                u.must_change_password,
                u.ultimo_login_en,
                r.nombre AS rol_nombre,
                p.nombre_completo AS personal_nombre,
                '' AS grupos,
                '' AS grupo_ids,
                '' AS politica_ids
             FROM core_usuarios u
             LEFT JOIN core_roles r ON r.id = u.rol_id
             LEFT JOIN core_personal p ON p.id = u.personal_id
             {$where}
             ORDER BY u.id DESC
             LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'usuarios' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function searchPersonal(string $search, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $sql = Sql::isSqlServer($this->db)
            ? "SELECT TOP {$limit}
                id,
                dni,
                nombres,
                apellido_paterno,
                apellido_materno,
                nombre_completo,
                correo_institucional,
                correo_firma,
                estado
             FROM dbo.core_personal
             WHERE dni LIKE :search_dni
                OR nombre_completo LIKE :search_nombre_completo
                OR nombres LIKE :search_nombres
                OR apellido_paterno LIKE :search_apellido_paterno
                OR apellido_materno LIKE :search_apellido_materno
             ORDER BY nombre_completo"
            : "SELECT
                id,
                dni,
                nombres,
                apellido_paterno,
                apellido_materno,
                nombre_completo,
                correo_institucional,
                correo_firma,
                estado
             FROM core_personal
             WHERE dni LIKE :search_dni
                OR nombre_completo LIKE :search_nombre_completo
                OR nombres LIKE :search_nombres
                OR apellido_paterno LIKE :search_apellido_paterno
                OR apellido_materno LIKE :search_apellido_materno
             ORDER BY nombre_completo
             LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $likeSearch = '%' . $search . '%';
        $stmt->execute([
            'search_dni' => $likeSearch,
            'search_nombre_completo' => $likeSearch,
            'search_nombres' => $likeSearch,
            'search_apellido_paterno' => $likeSearch,
            'search_apellido_materno' => $likeSearch,
        ]);

        return $stmt->fetchAll();
    }

    public function searchActiveUsers(string $search, int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        $likeSearch = '%' . $search . '%';
        $stmt = $this->db->prepare(
            "SELECT TOP {$limit}
                id,
                dni,
                nombres,
                apellidos,
                email,
                estado
             FROM dbo.core_usuarios
             WHERE estado = 'activo'
               AND (
                    email LIKE :search_email
                    OR dni LIKE :search_dni
                    OR nombres LIKE :search_nombres
                    OR apellidos LIKE :search_apellidos
               )
             ORDER BY apellidos, nombres, email"
        );
        $stmt->execute([
            'search_email' => $likeSearch,
            'search_dni' => $likeSearch,
            'search_nombres' => $likeSearch,
            'search_apellidos' => $likeSearch,
        ]);

        return $stmt->fetchAll();
    }


    public function create(array $data): int
    {
        $roleId = $this->defaultRoleId();
        $password = (string) ($data['password'] ?? '');
        $personalId = isset($data['personal_id']) && $data['personal_id'] !== '' ? (int) $data['personal_id'] : null;

        $person = $personalId !== null ? $this->findPersonalById($personalId) : null;
        if ($personalId !== null && $person === null) {
            throw new \InvalidArgumentException('El personal seleccionado no existe.');
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $dni = trim((string) ($data['dni'] ?? ''));
        $nombres = trim((string) ($data['nombres'] ?? ''));
        $apellidos = trim((string) ($data['apellidos'] ?? ''));

        if ($person !== null) {
            $emailFromPersonal = trim((string) ($person['correo_institucional'] ?? ''));
            if ($emailFromPersonal === '') {
                $emailFromPersonal = trim((string) ($person['correo_firma'] ?? ''));
            }

            $email = $emailFromPersonal !== '' ? strtolower($emailFromPersonal) : $email;
            $dni = trim((string) ($person['dni'] ?? ''));
            $nombres = trim((string) ($person['nombres'] ?? ''));
            $apellidos = trim($this->buildSurnames($person));
        }

        if ($dni === '' || $nombres === '' || $apellidos === '') {
            throw new \InvalidArgumentException('DNI, nombres y apellidos son obligatorios.');
        }

        if ($email === '') {
            $email = $this->dniLoginEmail($dni);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new \InvalidArgumentException('Correo valido o DNI y contrasena de 8 caracteres son obligatorios.');
        }

        if ($this->emailExists($email)) {
            throw new \InvalidArgumentException('Ya existe un usuario con ese correo.');
        }

        if ($this->dniExists($dni)) {
            throw new \InvalidArgumentException('Ya existe un usuario con ese DNI.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $mustChange = !empty($data['must_change_password']) ? 1 : 0;

        $sql = Sql::isSqlServer($this->db)
            ? 'INSERT INTO dbo.core_usuarios
                (personal_id, rol_id, dni, nombres, apellidos, email, password_hash, must_change_password, origen, estado, creado_en)
               OUTPUT INSERTED.id
               VALUES (:personal_id, :rol_id, :dni, :nombres, :apellidos, :email, :password_hash, :must_change_password, :origen, :estado, SYSDATETIME())'
            : 'INSERT INTO core_usuarios
                (personal_id, rol_id, dni, nombres, apellidos, email, password_hash, must_change_password, origen, estado, creado_en)
               VALUES (:personal_id, :rol_id, :dni, :nombres, :apellidos, :email, :password_hash, :must_change_password, :origen, :estado, NOW())';

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'personal_id' => $personalId,
                'rol_id' => $roleId,
                'dni' => $dni,
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'email' => $email,
                'password_hash' => $hash,
                'must_change_password' => $mustChange,
                'origen' => 'gs_neo',
                'estado' => 'activo',
            ]);

            $id = Sql::isSqlServer($this->db) ? (int) $stmt->fetchColumn() : (int) $this->db->lastInsertId();
            $this->syncGroups($id, $data['grupo_ids'] ?? []);
            $this->syncDirectPolicies($id, $data['politica_ids'] ?? []);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $id;
    }

    public function passwordResetRequests(): array
    {
        $this->ensurePasswordResetTable();
        $sql = Sql::isSqlServer($this->db)
            ? "SELECT r.id, r.usuario_id, r.estado, r.solicitado_en, u.dni, u.email, u.nombres, u.apellidos
               FROM dbo.core_password_reset_requests r
               INNER JOIN dbo.core_usuarios u ON u.id = r.usuario_id
               WHERE r.estado = 'pendiente'
               ORDER BY r.solicitado_en DESC"
            : "SELECT r.id, r.usuario_id, r.estado, r.solicitado_en, u.dni, u.email, u.nombres, u.apellidos
               FROM core_password_reset_requests r
               INNER JOIN core_usuarios u ON u.id = r.usuario_id
               WHERE r.estado = 'pendiente'
               ORDER BY r.solicitado_en DESC";

        return $this->db->query($sql)->fetchAll();
    }

    public function notifications(): array
    {
        $requests = $this->passwordResetRequests();

        return [
            'total' => count($requests),
            'items' => array_map(static function (array $row): array {
                $name = trim((string) (($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? '')));

                return [
                    'id' => 'password-reset-' . (int) $row['id'],
                    'type' => 'password_reset',
                    'title' => 'Reinicio de contrasena pendiente',
                    'message' => ($name !== '' ? $name : (string) ($row['email'] ?? 'Usuario')) . ' solicito reinicio de contrasena.',
                    'created_at' => (string) ($row['solicitado_en'] ?? ''),
                    'view' => 'password-requests',
                ];
            }, $requests),
        ];
    }

    public function approvePasswordReset(int $requestId, int $adminId): void
    {
        $this->ensurePasswordResetTable();
        $table = Sql::isSqlServer($this->db) ? 'dbo.core_password_reset_requests' : 'core_password_reset_requests';
        $users = Sql::isSqlServer($this->db) ? 'dbo.core_usuarios' : 'core_usuarios';
        $now = Sql::now($this->db);

        $stmt = $this->db->prepare("SELECT usuario_id FROM {$table} WHERE id = :id AND estado = 'pendiente'");
        $stmt->execute(['id' => $requestId]);
        $userId = (int) $stmt->fetchColumn();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Solicitud no encontrada o ya atendida.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE {$users} SET must_change_password = 1, actualizado_en = {$now} WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $stmt = $this->db->prepare("UPDATE {$table} SET estado = 'aprobado', aprobado_por = :admin_id, aprobado_en = {$now}, actualizado_en = {$now} WHERE id = :id");
            $stmt->execute(['id' => $requestId, 'admin_id' => $adminId]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function ensurePasswordResetTable(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec("IF OBJECT_ID('dbo.core_password_reset_requests', 'U') IS NULL BEGIN CREATE TABLE dbo.core_password_reset_requests (id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_password_reset_requests PRIMARY KEY, usuario_id BIGINT NOT NULL, estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_password_reset_requests_estado DEFAULT ('pendiente'), solicitado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_password_reset_requests_solicitado DEFAULT (SYSDATETIME()), aprobado_por BIGINT NULL, aprobado_en DATETIME2(0) NULL, actualizado_en DATETIME2(0) NULL); CREATE UNIQUE INDEX UX_core_password_reset_requests_pendiente ON dbo.core_password_reset_requests(usuario_id) WHERE estado = 'pendiente'; END");
            return;
        }

        $this->db->exec("CREATE TABLE IF NOT EXISTS core_password_reset_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, usuario_id BIGINT UNSIGNED NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'pendiente', solicitado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, aprobado_por BIGINT UNSIGNED NULL, aprobado_en TIMESTAMP NULL, actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, INDEX idx_core_password_reset_requests_estado (estado), INDEX idx_core_password_reset_requests_usuario (usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE dbo.core_usuarios SET estado = :estado, actualizado_en = ' . Sql::now($this->db) . ' WHERE id = :id');
        $stmt->execute(['id' => $id, 'estado' => 'inactivo']);
    }

    public function activate(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE dbo.core_usuarios SET estado = :estado, actualizado_en = ' . Sql::now($this->db) . ' WHERE id = :id');
        $stmt->execute(['id' => $id, 'estado' => 'activo']);
    }

    public function retire(int $id): void
    {
        if (!$this->userExists($id)) {
            throw new \InvalidArgumentException('Usuario no existe.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE dbo.core_grupo_usuarios
                 SET estado = 'inactivo', actualizado_en = SYSDATETIME()
                 WHERE usuario_id = :id"
            );
            $stmt->execute(['id' => $id]);

            $stmt = $this->db->prepare(
                "UPDATE dbo.core_usuarios
                 SET estado = 'inactivo',
                     email = CONCAT('retirado-', id, '@local.invalid'),
                     dni = CONCAT('RET-', id),
                     actualizado_en = SYSDATETIME()
                 WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateGroups(int $id, array $groupIds, array $policyIds = []): void
    {
        if (!$this->userExists($id)) {
            throw new \InvalidArgumentException('Usuario no existe.');
        }

        $this->db->beginTransaction();
        try {
            $this->syncGroups($id, $groupIds);
            $this->syncDirectPolicies($id, $policyIds);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function catalogs(): array
    {
        return [
            'grupos' => $this->allActive('dbo.core_grupos', 'nombre'),
            'politicas' => $this->allActive('dbo.core_politicas', 'codigo'),
        ];
    }

    public function groupsOverview(): array
    {
        $groupPoliciesHasEstado = $this->columnExists('dbo.core_grupo_politicas', 'estado');
        $policiesHasEstado = $this->columnExists('dbo.core_politicas', 'estado');
        $groupPoliciesStateFilter = $groupPoliciesHasEstado ? " AND cgp.estado = 'activo'" : '';
        $policiesStateFilter = $policiesHasEstado ? " AND cp.estado = 'activo'" : '';

        $policyCountSelect = $this->tableExists('dbo.core_grupo_politicas')
            ? "(SELECT COUNT(*)
                FROM dbo.core_grupo_politicas cgp
                INNER JOIN dbo.core_politicas cp ON cp.id = cgp.politica_id
                WHERE cgp.grupo_id = g.id
                  {$groupPoliciesStateFilter}
                  {$policiesStateFilter})"
            : '0';

        $sql = "SELECT
                g.id,
                g.nombre,
                g.descripcion,
                g.estado,
                (SELECT COUNT(*)
                 FROM dbo.core_grupo_usuarios cgu
                 INNER JOIN dbo.core_usuarios u ON u.id = cgu.usuario_id
                 WHERE cgu.grupo_id = g.id
                   AND cgu.estado = 'activo'
                   AND u.estado = 'activo') AS usuarios_count,
                {$policyCountSelect} AS politicas_count
             FROM dbo.core_grupos g
             WHERE g.estado = 'activo'
             ORDER BY g.nombre";

        return $this->db->query($sql)->fetchAll();
    }

    public function groupMembers(int $groupId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                u.id,
                u.dni,
                u.nombres,
                u.apellidos,
                u.email,
                u.estado,
                u.ultimo_login_en
             FROM dbo.core_grupo_usuarios cgu
             INNER JOIN dbo.core_usuarios u ON u.id = cgu.usuario_id
             WHERE cgu.grupo_id = :group_id
               AND cgu.estado = 'activo'
               AND u.estado = 'activo'
             ORDER BY u.apellidos, u.nombres, u.email"
        );
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll();
    }

    public function addUserToGroup(int $userId, int $groupId): void
    {
        if (!$this->activeUserExists($userId)) {
            throw new \InvalidArgumentException('Usuario activo no existe.');
        }

        $stmt = $this->db->prepare(
            "MERGE dbo.core_grupo_usuarios AS t
             USING (SELECT :grupo_id AS grupo_id, :usuario_id AS usuario_id) AS s
             ON t.grupo_id = s.grupo_id AND t.usuario_id = s.usuario_id
             WHEN MATCHED THEN
                UPDATE SET estado = 'activo', actualizado_en = SYSDATETIME()
             WHEN NOT MATCHED THEN
                INSERT (grupo_id, usuario_id, estado, creado_en)
                VALUES (s.grupo_id, s.usuario_id, 'activo', SYSDATETIME());"
        );
        $stmt->execute([
            'grupo_id' => $groupId,
            'usuario_id' => $userId,
        ]);
    }

    public function removeUserFromGroup(int $userId, int $groupId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE dbo.core_grupo_usuarios
             SET estado = 'inactivo', actualizado_en = SYSDATETIME()
             WHERE grupo_id = :grupo_id
               AND usuario_id = :usuario_id"
        );
        $stmt->execute([
            'grupo_id' => $groupId,
            'usuario_id' => $userId,
        ]);
    }

    public function auditLogs(int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        if (!$this->tableExists('dbo.core_logs_auditoria')) {
            return [];
        }

        $sql = "SELECT TOP {$limit}
                l.id,
                l.usuario_id,
                u.email AS usuario_email,
                l.accion,
                l.descripcion,
                l.ip,
                l.creado_en
             FROM dbo.core_logs_auditoria l
             LEFT JOIN dbo.core_usuarios u ON u.id = l.usuario_id
             WHERE l.modulo = 'gs_neo'
             ORDER BY l.creado_en DESC, l.id DESC";

        return $this->db->query($sql)->fetchAll();
    }

    private function tableExists(string $table): bool
    {
        $safeTable = str_replace("'", "''", $table);
        $stmt = $this->db->query("SELECT OBJECT_ID(N'{$safeTable}', N'U')");

        return $stmt->fetchColumn() !== null;
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT 1
             FROM sys.columns
             WHERE object_id = OBJECT_ID(:table_name)
               AND name = :column_name"
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function findPersonalById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT TOP 1 * FROM dbo.core_personal WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $person = $stmt->fetch();

        return is_array($person) ? $person : null;
    }

    private function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT TOP 1 id FROM dbo.core_usuarios WHERE email = :email');
        $stmt->execute(['email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    private function dniLoginEmail(string $dni): string
    {
        return preg_replace('/[^0-9A-Za-z._-]/', '', $dni) . '@dni.local';
    }

    private function dniExists(string $dni): bool
    {
        $stmt = $this->db->prepare('SELECT TOP 1 id FROM dbo.core_usuarios WHERE dni = :dni');
        $stmt->execute(['dni' => $dni]);

        return $stmt->fetchColumn() !== false;
    }

    private function userExists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT TOP 1 id FROM dbo.core_usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    private function activeUserExists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT TOP 1 id FROM dbo.core_usuarios WHERE id = :id AND estado = 'activo'");
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    private function defaultRoleId(): int
    {
        $stmt = $this->db->query(
            "SELECT TOP 1 id
             FROM dbo.core_roles
             WHERE estado = 'activo'
               AND nombre IN (N'Usuario', N'Usuario Base', N'Sin privilegios', N'Basico', N'Básico', N'BÃ¡sico')
             ORDER BY CASE
                WHEN nombre IN (N'Usuario', N'Usuario Base', N'Sin privilegios', N'Basico', N'Básico') THEN 0
                ELSE 1
             END, id"
        );
        $roleId = (int) $stmt->fetchColumn();
        if ($roleId <= 0) {
            throw new \InvalidArgumentException('No existe un rol base activo para crear el usuario.');
        }

        return $roleId;
    }

    private function buildSurnames(?array $person): string
    {
        if ($person === null) {
            return '';
        }

        return trim((string) ($person['apellido_paterno'] ?? '') . ' ' . (string) ($person['apellido_materno'] ?? ''));
    }

    private function syncGroups(int $userId, array $groupIds): void
    {
        $cleanGroupIds = [];
        foreach ($groupIds as $groupId) {
            $groupId = (int) $groupId;
            if ($groupId <= 0) {
                continue;
            }

            $cleanGroupIds[$groupId] = $groupId;
        }

        $stmt = $this->db->prepare(
            'UPDATE dbo.core_grupo_usuarios
             SET estado = :estado, actualizado_en = SYSDATETIME()
             WHERE usuario_id = :usuario_id'
        );
        $stmt->execute([
            'usuario_id' => $userId,
            'estado' => 'inactivo',
        ]);

        foreach ($cleanGroupIds as $groupId) {
            $stmt = $this->db->prepare(
                'MERGE dbo.core_grupo_usuarios AS t
                 USING (SELECT :grupo_id AS grupo_id, :usuario_id AS usuario_id, :estado AS estado) AS s
                 ON t.grupo_id = s.grupo_id AND t.usuario_id = s.usuario_id
                 WHEN MATCHED THEN
                    UPDATE SET estado = s.estado, actualizado_en = SYSDATETIME()
                 WHEN NOT MATCHED THEN
                    INSERT (grupo_id, usuario_id, estado, creado_en)
                    VALUES (s.grupo_id, s.usuario_id, s.estado, SYSDATETIME());'
            );
            $stmt->execute([
                'grupo_id' => $groupId,
                'usuario_id' => $userId,
                'estado' => 'activo',
            ]);
        }
    }

    private function syncDirectPolicies(int $userId, array $policyIds): void
    {
        $this->ensureDirectPoliciesTable();

        $cleanPolicyIds = [];
        foreach ($policyIds as $policyId) {
            $policyId = (int) $policyId;
            if ($policyId <= 0) {
                continue;
            }

            $cleanPolicyIds[$policyId] = $policyId;
        }

        $stmt = $this->db->prepare(
            'UPDATE dbo.core_usuario_politicas
             SET estado = :estado, actualizado_en = SYSDATETIME()
             WHERE usuario_id = :usuario_id'
        );
        $stmt->execute([
            'usuario_id' => $userId,
            'estado' => 'inactivo',
        ]);

        foreach ($cleanPolicyIds as $policyId) {
            $stmt = $this->db->prepare(
                'MERGE dbo.core_usuario_politicas AS t
                 USING (SELECT :usuario_id AS usuario_id, :politica_id AS politica_id, :permitido AS permitido, :estado AS estado) AS s
                 ON t.usuario_id = s.usuario_id AND t.politica_id = s.politica_id
                 WHEN MATCHED THEN
                    UPDATE SET permitido = s.permitido, estado = s.estado, actualizado_en = SYSDATETIME()
                 WHEN NOT MATCHED THEN
                    INSERT (usuario_id, politica_id, permitido, estado, creado_en)
                    VALUES (s.usuario_id, s.politica_id, s.permitido, s.estado, SYSDATETIME());'
            );
            $stmt->execute([
                'usuario_id' => $userId,
                'politica_id' => $policyId,
                'permitido' => 1,
                'estado' => 'activo',
            ]);
        }
    }

    private function ensureDirectPoliciesTable(): void
    {
        if (!Sql::isSqlServer($this->db)) {
            return;
        }

        $this->db->exec(
            "IF OBJECT_ID('dbo.core_usuario_politicas', 'U') IS NULL
             BEGIN
                CREATE TABLE dbo.core_usuario_politicas (
                    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_usuario_politicas PRIMARY KEY,
                    usuario_id BIGINT NOT NULL,
                    politica_id BIGINT NOT NULL,
                    permitido BIT NOT NULL CONSTRAINT DF_core_usuario_politicas_permitido DEFAULT (1),
                    estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_politicas_estado DEFAULT ('activo'),
                    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_politicas_creado DEFAULT (SYSDATETIME()),
                    actualizado_en DATETIME2(0) NULL,
                    CONSTRAINT UQ_core_usuario_politicas UNIQUE (usuario_id, politica_id),
                    CONSTRAINT CK_core_usuario_politicas_estado CHECK (estado IN ('activo', 'inactivo'))
                );
             END;

             IF COL_LENGTH('dbo.core_usuario_politicas', 'permitido') IS NULL
             BEGIN
                ALTER TABLE dbo.core_usuario_politicas
                ADD permitido BIT NOT NULL CONSTRAINT DF_core_usuario_politicas_permitido DEFAULT (1);
             END"
        );
    }

    private function allActive(string $table, string $orderBy): array
    {
        $stmt = $this->db->query("SELECT * FROM {$table} WHERE estado = 'activo' ORDER BY {$orderBy}");
        return $stmt->fetchAll();
    }
}
