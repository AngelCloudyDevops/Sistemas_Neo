<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/bootstrap.php';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $requestHost !== '' ? $scheme . '://' . $requestHost . '/gs_neo/public' : '';
$assetBase = $baseUrl !== '' ? $baseUrl : '.';
$apiBase = $baseUrl !== '' ? $baseUrl . '/../api/v1' : '../api/v1';
$portalUrl = $requestHost !== '' ? $scheme . '://' . $requestHost . '/neo/public/' : '/neo/public/';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) $config['app']['name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css?v=20260630-neo-layout">
    <link rel="stylesheet" href="/neo_shared/assets/css/neo-standard.css?v=20260630-profile-grid-contract">
</head>
<body>
    <section class="login-screen" id="loginScreen">
        <div class="login-brand-panel" aria-hidden="true">
            <span class="brand-shine"></span>
            <div class="brand-emblem">NEO</div>
            <h2>Gestion centralizada</h2>
                    <p>Usuarios, grupos y permisos para todos los sistemas institucionales.</p>
            <div class="feature-list">
                <span>Directorio institucional</span>
                <span>Permisos por sistema</span>
                <span>Auditoria y trazabilidad</span>
            </div>
            <div class="login-stats">
                <strong>Accesos</strong>
                <strong>GS</strong>
                <strong>NEO</strong>
            </div>
        </div>
        <form class="login-card" id="loginForm" autocomplete="on">
            <div class="brand-row">
                <span class="brand-dot"></span>
                <strong>NEO</strong>
                <span>Gestion de usuarios</span>
            </div>
            <h1>Acceso administrativo</h1>
            <label>
                Correo o DNI
                <input id="loginEmail" type="text" inputmode="email" autocomplete="username" required>
            </label>
            <label>
                Contrasena
                <input id="loginPassword" type="password" autocomplete="current-password" required>
            </label>
            <button type="submit">Ingresar</button>
            <a class="portal-login-button" href="<?= htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') ?>">Ingresar desde Portal NEO</a>
            <p class="form-message" id="loginMessage"></p>
        </form>
    </section>

    <section class="app-shell hidden" id="appShell">
        <aside class="sidebar">
            <div class="app-mark">
                <span class="brand-dot"></span>
                <div>
                    <strong>GS NEO</strong>
                    <small>Usuarios y accesos</small>
                </div>
            </div>
            <nav class="side-nav">
                <div class="nav-section">
                    <span class="nav-label">Principal</span>
                    <button class="nav-item active" data-view="dashboard" data-icon="D">Resumen</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Usuarios</span>
                    <button class="nav-item" data-view="users" data-icon="U">Usuarios</button>
                    <button class="nav-item" data-view="create" data-icon="+">Crear usuario</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Accesos</span>
                    <button class="nav-item" data-view="groups" data-icon="G">Grupos</button>
                    <button class="nav-item" data-view="catalog" data-icon="C">Catalogo</button>
                    <button class="nav-item" data-view="audit" data-icon="H">Auditoria</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Sistema</span>
                    <button class="nav-item" data-view="profile" data-icon="P">Mi Perfil</button>
                    <button class="nav-item" data-view="password-requests" data-icon="R">Reinicios <span class="nav-count hidden" id="passwordRequestsNavCount">0</span></button>
                </div>
            </nav>
            <button class="logout" id="logoutButton">Cerrar sesion</button>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <h1>Gestion de Usuarios NEO</h1>
                    <p>Control administrativo para usuarios, grupos y politicas.</p>
                </div>
                <section class="topbar-profile-summary" aria-label="Perfil activo">
                    <span class="topbar-profile-avatar" id="topbarProfileAvatar">U</span>
                    <div>
                        <span>Perfil activo</span>
                        <strong id="topbarProfileName">Usuario</strong>
                        <small id="topbarProfileRole">Usuario</small>
                    </div>
                </section>
                <div class="topbar-actions">
                    <div class="topbar-search">
                        <span>Q</span>
                        <input type="search" placeholder="Buscar usuarios, grupos...">
                    </div>
                    <button type="button" class="topbar-icon" aria-label="Identificador">ID</button>
                    <div class="notification-menu">
                        <button type="button" class="notification-button" id="notificationButton" aria-label="Notificaciones">
                            <span>!</span>
                            <strong class="notification-badge hidden" id="notificationBadge">0</strong>
                        </button>
                        <div class="notification-dropdown">
                            <div class="notification-head">
                                <strong>Notificaciones</strong>
                                <button type="button" id="refreshNotificationsButton">Actualizar</button>
                            </div>
                            <div class="notification-list" id="notificationList">
                                <span>Sin notificaciones pendientes.</span>
                            </div>
                        </div>
                    </div>
                <div class="profile-menu">
                    <button type="button" class="user-chip profile-button account-menu-button" id="currentUser" aria-label="Menu de usuario">...</button>
                    <div class="profile-dropdown">
                        <strong id="profileName">Usuario</strong>
                        <span id="profileLogin">Usuario</span>
                        <a href="#perfil" data-profile-link>Mi Perfil</a>
                        <button type="button" id="logoutButtonProfile">Cerrar sesion</button>
                    </div>
                </div>
                </div>
            </header>

            <section class="view active" data-view-panel="dashboard">
                <div class="dashboard-hero">
                    <div>
                        <span class="hero-kicker">Panel administrativo</span>
                        <h2>Accesos compartidos para el ecosistema NEO</h2>
                        <p>Vista de tus accesos asignados, grupos activos y rol vigente.</p>
                    </div>
                    <div class="hero-badge">
                        <span>GS</span>
                        <strong>Accesos</strong>
                    </div>
                </div>
                <div class="metrics-grid" id="summaryCards"></div>
            </section>

            <section class="view" data-view-panel="users">
                <div class="panel-head">
                    <h2>Usuarios</h2>
                    <div class="search-box">
                        <input id="userSearch" type="search" placeholder="Buscar por correo, DNI o nombre">
                        <button id="refreshUsers" type="button">Buscar</button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>DNI</th>
                                <th>Grupos</th>
                                <th>Estado</th>
                                <th>Ultimo login</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="usersTable"></tbody>
                    </table>
                    <div class="pagination-row" id="usersPagination">
                        <span id="usersPageInfo">Pagina 1 de 1</span>
                        <div>
                            <button class="ghost-button" id="prevUsersPage" type="button">Anterior</button>
                            <button class="ghost-button" id="nextUsersPage" type="button">Siguiente</button>
                        </div>
                    </div>
                </div>
                <section class="user-access-panel hidden" id="userAccessPanel">
                    <div class="panel-head">
                        <div>
                            <h2 id="accessUserTitle">Accesos del usuario</h2>
                            <p id="accessUserSubtitle">Grupos permanentes como acceso principal.</p>
                        </div>
                        <button class="ghost-button" id="closeAccessPanel" type="button">Cerrar</button>
                    </div>
                    <div class="access-lanes">
                        <article>
                            <h3>Grupos permanentes</h3>
                            <div class="check-sections" id="editGroupsList"></div>
                        </article>
                        <article class="access-reserved">
                            <h3>Permisos directos</h3>
                            <div class="check-sections" id="editDirectPoliciesList"></div>
                        </article>
                        <article class="access-reserved">
                            <h3>Roles temporales</h3>
                            <div class="empty-state">Reservado para elevaciones con vencimiento.</div>
                        </article>
                    </div>
                    <div class="actions-row">
                        <button id="saveUserGroups" type="button">Guardar accesos</button>
                        <p class="form-message" id="accessMessage"></p>
                    </div>
                </section>
            </section>

            <section class="view" data-view-panel="create">
                <form class="user-form" id="createUserForm">
                    <div class="panel-head">
                        <h2>Crear usuario</h2>
                        <p>Primero busca y selecciona el personal. Si no existe, se puede llenar manualmente.</p>
                    </div>

                    <div class="form-grid">
                        <label class="span-2">
                            Buscar personal
                            <input id="personalSearch" type="search" placeholder="DNI o nombres">
                        </label>
                        <div class="personal-results span-2" id="personalResults"></div>
                        <input id="personalId" type="hidden">
                        <div class="selected-personal-notice span-2 hidden" id="selectedPersonalNotice">
                            <span>Personal seleccionado. Los datos institucionales quedan bloqueados para mantener el directorio como fuente.</span>
                            <button class="ghost-button" id="clearSelectedPersonal" type="button">Cambiar personal</button>
                        </div>

                        <label>
                            DNI
                            <input id="dni" name="dni" required>
                        </label>
                        <label>
                            Correo opcional
                            <input id="email" name="email" type="email">
                        </label>
                        <label>
                            Nombres
                            <input id="nombres" name="nombres" required>
                        </label>
                        <label>
                            Apellidos
                            <input id="apellidos" name="apellidos" required>
                        </label>
                        <input id="roleId" name="rol_id" type="hidden">
                        <label>
                            Contrasena inicial
                            <input id="password" name="password" type="password" minlength="8" required>
                        </label>
                    </div>

                    <div>
                        <h3>Grupos</h3>
                        <div class="check-sections" id="groupsList"></div>
                    </div>

                    <details class="advanced-access-section">
                        <summary>Permisos especiales</summary>
                        <p>Permisos unitarios directos. Usalos solo cuando un grupo no cubre el caso.</p>
                        <div class="check-sections" id="directPoliciesList"></div>
                    </details>

                    <label class="check-row">
                        <input id="mustChangePassword" type="checkbox" checked>
                        Forzar cambio de contrasena en el primer ingreso
                    </label>

                    <div class="actions-row">
                        <button type="submit">Crear usuario</button>
                        <p class="form-message" id="createMessage"></p>
                    </div>
                </form>
            </section>

            <section class="view" data-view-panel="groups">
                <div class="panel-head">
                    <div>
                        <h2>Grupos</h2>
                        <p>Acceso permanente por sistema. Las politicas del grupo se crean por SQL/versionado.</p>
                    </div>
                </div>
                <div class="system-groups" id="groupsOverview"></div>

                <section class="user-access-panel hidden" id="groupManagePanel">
                    <div class="panel-head">
                        <div>
                            <h2 id="groupManageTitle">Grupo</h2>
                            <p id="groupManageSubtitle">Usuarios asignados permanentemente.</p>
                        </div>
                        <button class="ghost-button" id="closeGroupPanel" type="button">Cerrar</button>
                    </div>

                    <div class="group-manager-grid">
                        <article>
                            <h3>Usuarios del grupo</h3>
                            <div class="table-wrap compact-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Usuario</th>
                                            <th>DNI</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="groupMembersTable"></tbody>
                                </table>
                            </div>
                        </article>
                        <article>
                            <h3>Agregar usuario</h3>
                            <div class="search-box stacked-search">
                                <input id="groupUserSearch" type="search" placeholder="Buscar por correo, DNI o nombre">
                                <button id="groupUserSearchButton" type="button">Buscar</button>
                            </div>
                            <div class="personal-results" id="groupUserResults"></div>
                            <p class="form-message" id="groupMessage"></p>
                        </article>
                    </div>
                </section>
            </section>

            <section class="view" data-view-panel="catalog">
                <div class="panel-head">
                    <h2>Catalogo de accesos</h2>
                    <p>Lectura de grupos permanentes y politicas registradas.</p>
                </div>
                <div class="catalog-grid">
                    <div>
                        <h3>Grupos permanentes</h3>
                        <div id="groupsCatalog" class="tag-list"></div>
                    </div>
                    <div>
                        <h3>Politicas</h3>
                        <div id="policiesCatalog" class="tag-list"></div>
                    </div>
                </div>
            </section>

            <section class="view" data-view-panel="audit">
                <div class="panel-head">
                    <h2>Auditoria</h2>
                    <p>Ultimos cambios realizados desde GS NEO.</p>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Accion</th>
                                <th>Usuario</th>
                                <th>Detalle</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody id="auditTable"></tbody>
                    </table>
                </div>
            </section>

            <section class="view" data-view-panel="password-requests">
                <div class="panel-head">
                    <h2>Reinicios de contrasena</h2>
                    <p>Solicitudes pendientes. Al aprobar, el usuario debera cambiar su contrasena en el siguiente ingreso.</p>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>DNI</th>
                                <th>Solicitado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="passwordRequestsTable"></tbody>
                    </table>
                </div>
                <p class="form-message" id="passwordRequestsMessage"></p>
            </section>

            <section class="view" data-view-panel="profile">
                <div class="panel-head">
                    <h2>Mi Perfil</h2>
                    <p>Parametros generales compartidos en todos tus sistemas.</p>
                </div>
                <section class="profile-hero">
                    <div class="profile-identity">
                        <div class="profile-avatar" id="profileAvatarPreview">U</div>
                        <div>
                            <span>Perfil activo</span>
                            <strong id="profileHeroName">Usuario</strong>
                            <small id="profileHeroRole">Usuario</small>
                        </div>
                    </div>
                    <div class="profile-goal-summary">
                        <span>Color principal</span>
                        <strong id="profileColorSummary">#7c3aed</strong>
                        <small>Preferencia visual de tu perfil.</small>
                    </div>
                </section>
                <form class="profile-form" id="profileForm">
                    <label class="profile-display-field">Nombre visible<input id="profileDisplayName" type="text" maxlength="160"></label>
                    <label class="profile-role-field">Cargo o area<input id="profileRoleArea" type="text" maxlength="160" disabled></label>
                    <label class="profile-email-field">Correo<input id="profileEmail" type="text" disabled></label>
                    <div class="color-field">
                        <label for="profileAccent">Color principal</label>
                        <div class="color-picker" id="profileColorPicker">
                            <div class="color-map" id="profileColorMap" role="slider" aria-label="Seleccionar saturacion y brillo" tabindex="0">
                                <span class="color-map-cursor" id="profileColorCursor"></span>
                            </div>
                            <input class="color-slider" id="profileHue" type="range" min="0" max="360" value="262" aria-label="Deslizar color principal">
                            <div class="color-hex-row">
                                <span class="color-preview" id="profileAccentPreview"></span>
                                <span class="color-hash">#</span>
                                <input id="profileAccent" type="text" maxlength="6" value="7c3aed" inputmode="text" aria-label="Color hexadecimal">
                                <span class="color-format">Hex</span>
                            </div>
                        </div>
                    </div>
                    <small class="profile-note">El color principal se usa como preferencia visual de tu perfil.</small>
                    <label class="full profile-avatar-field">Foto avatar URL<input id="profileAvatarUrl" type="url" placeholder="https://..."></label>
                    <button type="submit">Guardar perfil</button>
                    <button type="button" class="secondary-profile-button" id="requestPasswordResetButton">Solicitar reinicio de contrasena</button>
                    <p class="form-message" id="profileMessage"></p>
                </form>
                <div class="password-reset-panel hidden" id="passwordResetPanel">
                    <h3>Cambio de contrasena aprobado</h3>
                    <p>Ingresa y confirma tu nueva contrasena.</p>
                    <label>Nueva contrasena
                        <span class="password-field"><input id="newProfilePassword" type="password" minlength="8" autocomplete="new-password"><button type="button" data-toggle-password="newProfilePassword">Ver</button></span>
                    </label>
                    <label>Confirmar contrasena
                        <span class="password-field"><input id="confirmProfilePassword" type="password" minlength="8" autocomplete="new-password"><button type="button" data-toggle-password="confirmProfilePassword">Ver</button></span>
                    </label>
                    <button type="button" id="changeProfilePasswordButton">Cambiar contrasena</button>
                    <p class="form-message" id="changeProfilePasswordMessage"></p>
                </div>
            </section>
        </main>
    </section>

    <script>
        window.GS_NEO_API_BASE = '<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>';
    </script>
    <script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=20260630-neo-layout"></script>
</body>
</html>

