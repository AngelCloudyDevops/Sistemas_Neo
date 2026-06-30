<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/bootstrap.php';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $requestHost !== '' ? $scheme . '://' . $requestHost . '/inventario_neo/public' : '';
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
    <script>
        window.INVENTARIO_NEO_API_BASE = '<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>';
        window.INVENTARIO_NEO_PUBLIC_BASE = '<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>';
        window.INVENTARIO_NEO_PRESET_AVATARS = [
            'avatar_01.jpg',
            'avatar_02.jpg',
            'avatar_03.jpg',
            'avatar_04.webp',
            'avatar_05.jpg',
            'avatar_06.webp'
        ].map((name) => `${window.INVENTARIO_NEO_PUBLIC_BASE}/assets/img/avatars/${name}`);
        window.INVENTARIO_NEO_PRESET_BACKGROUNDS = [
            'bg_01.svg',
            'bg_02.svg',
            'bg_03.svg',
            'bg_04.svg'
        ].map((name) => `${window.INVENTARIO_NEO_PUBLIC_BASE}/assets/img/backgrounds/${name}`);
    </script>
    <script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=20260630-neo-layout" defer></script>
</head>
<body data-visual-class="operaciones">
    <section class="login-screen" id="loginScreen">
        <div class="login-brand-panel">
            <span class="brand-shine"></span>
            <div class="brand-emblem">INV</div>
            <h2>Inventario institucional NEO</h2>
            <p>Personal, equipos e impresoras conectados a la base central.</p>
            <div class="feature-list">
                <span>Directorio de personal</span>
                <span>Equipos asignados</span>
                <span>Impresoras y ubicaciones</span>
            </div>
        </div>
        <form class="login-card" id="loginForm">
            <div class="brand-row"><span class="brand-dot"></span><strong>NEO</strong><span>Inventario</span></div>
            <h1>Acceso administrativo</h1>
            <label>Correo o DNI <input id="loginEmail" type="text" autocomplete="username" required></label>
            <label>Contrasena <input id="loginPassword" type="password" autocomplete="current-password" required></label>
            <button type="submit">Ingresar</button>
            <a class="portal-login-button" href="<?= htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') ?>">Ingresar desde Portal NEO</a>
            <p class="form-message" id="loginMessage"></p>
        </form>
    </section>

    <section class="app-shell hidden" id="appShell">
        <aside class="sidebar">
            <div class="app-mark">
                <span class="brand-dot">IN</span>
                <div><strong>Inventario NEO</strong><small>Core institucional</small></div>
            </div>
            <nav class="side-nav">
                <div class="nav-section">
                    <span class="nav-label">Principal</span>
                    <button class="nav-item active" data-view="dashboard" data-icon="D">Dashboard</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Gestion</span>
                    <button class="nav-item" data-view="personal" data-icon="P">Personal</button>
                    <button class="nav-item" data-view="equipos" data-icon="E">Equipos</button>
                    <button class="nav-item" data-view="impresoras" data-icon="I">Impresoras</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Sistema</span>
                    <button class="nav-item" data-view="profile" data-icon="U">Mi Perfil</button>
                </div>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar app-topbar">
                <section class="topbar-profile-summary">
                    <span class="topbar-profile-avatar" id="topbarProfileAvatar">U</span>
                    <div><span>Perfil activo</span><strong id="topbarProfileName">Usuario</strong><small id="topbarProfileRole">Inventario</small></div>
                </section>
                <div class="topbar-actions">
                    <div class="topbar-search"><span>Q</span><input id="globalSearch" type="search" placeholder="Buscar"></div>
                    <button type="button" class="topbar-icon hidden" id="newRecordButton" aria-label="Nuevo registro">+</button>
                    <div class="profile-menu">
                        <button type="button" class="profile-button account-menu-button" id="profileInitials" aria-label="Menu de usuario">...</button>
                        <div class="profile-dropdown">
                            <strong id="profileName">Usuario</strong>
                            <span id="currentUser">Usuario</span>
                            <a href="#perfil" data-profile-link>Mi Perfil</a>
                            <button type="button" id="logoutButton">Cerrar Sesion</button>
                        </div>
                    </div>
                </div>
            </header>

            <section class="view active" data-view-panel="dashboard">
                <div class="dashboard-hero">
                    <div>
                        <span class="hero-kicker">Inventario central</span>
                        <h2>Personal, equipos e impresoras desde core_*</h2>
                        <p>Gestion inicial conectada a las tablas centrales del ecosistema.</p>
                    </div>
                    <div class="hero-badge"><span>INV</span><strong>NEO</strong></div>
                </div>
                <div class="metrics-grid" id="summaryCards"></div>
            </section>

            <section class="view" data-view-panel="profile">
                <div class="panel-head">
                    <h2>Mi Perfil</h2>
                    <button class="ghost-button" type="button" id="profileRefreshButton">Actualizar</button>
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
                        <span>Color</span>
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
                            <div class="color-input-row">
                                <span class="color-preview" id="profileAccentPreview"></span>
                                <span>#</span>
                                <input id="profileAccent" type="text" maxlength="6" value="7c3aed" inputmode="text" aria-label="Color hexadecimal">
                            </div>
                        </div>
                    </div>
                    <small class="profile-note">El color principal se usa como preferencia visual de tu perfil.</small>
                    <div class="avatar-preset-field">
                        <span>Avatar predefinido</span>
                        <div class="avatar-preset-grid" id="profileAvatarPresets" aria-label="Seleccionar avatar predefinido"></div>
                    </div>
                    <label class="full profile-avatar-field">Foto avatar URL<input id="profileAvatarUrl" type="url" placeholder="https://..."></label>
                    <div class="background-preset-field">
                        <span>Fondo visual</span>
                        <div class="background-preset-grid" id="profileBackgroundPresets" aria-label="Seleccionar fondo predefinido"></div>
                    </div>
                    <label class="full profile-background-field">Fondo URL<input id="profileBackgroundUrl" type="url" placeholder="https://..."></label>
                    <label class="full profile-opacity-field">Opacidad del fondo
                        <span class="opacity-control">
                            <input id="profileBackgroundOpacity" type="range" min="0" max="35" step="1" value="12">
                            <strong id="profileBackgroundOpacityValue">12%</strong>
                        </span>
                    </label>
                    <button type="submit">Guardar perfil</button>
                    <button type="button" class="secondary-profile-button" id="requestPasswordResetButton">Solicitar reinicio de contrasena</button>
                    <p class="form-message" id="profileMessage"></p>
                </form>
                <section class="profile-form hidden" id="passwordResetPanel">
                    <label>Nueva contrasena<input id="newProfilePassword" type="password" autocomplete="new-password"></label>
                    <label>Confirmar contrasena<input id="confirmProfilePassword" type="password" autocomplete="new-password"></label>
                    <button type="button" id="changeProfilePasswordButton">Cambiar contrasena</button>
                    <p class="form-message" id="changeProfilePasswordMessage"></p>
                </section>
            </section>

            <section class="view" data-view-panel="personal">
                <div class="panel-head">
                    <h2>Personal</h2>
                    <div class="panel-actions">
                        <button class="ghost-button" type="button" data-create="personal">Nuevo personal</button>
                        <button class="ghost-button" type="button" data-refresh="personal">Actualizar</button>
                    </div>
                </div>
                <div class="table-wrap"><table><thead><tr><th>DNI</th><th>Nombre</th><th>Correo</th><th>Estado</th><th></th></tr></thead><tbody id="personalTable"></tbody></table></div>
                <div class="pagination-row" id="personalPagination"></div>
            </section>

            <section class="view" data-view-panel="equipos">
                <div class="panel-head">
                    <h2>Equipos</h2>
                    <div class="panel-actions">
                        <button class="ghost-button" type="button" data-create="equipos">Nuevo equipo</button>
                        <button class="ghost-button" type="button" data-refresh="equipos">Actualizar</button>
                    </div>
                </div>
                <div class="table-wrap"><table class="wide-table equipos-table"><thead id="equiposHead"><tr><th>Equipo</th><th>Usuario</th><th>Patrimonio</th><th>Fecha adquisicion</th><th>Unidad</th><th>Area</th><th>Oficina</th><th>IP / MAC</th><th>Estado</th><th></th></tr></thead><tbody id="equiposTable"></tbody></table></div>
                <div class="pagination-row" id="equiposPagination"></div>
            </section>

            <section class="view" data-view-panel="impresoras">
                <div class="panel-head">
                    <h2>Impresoras</h2>
                    <div class="panel-actions">
                        <button class="ghost-button" type="button" data-create="impresoras">Nueva impresora</button>
                        <button class="ghost-button" type="button" data-refresh="impresoras">Actualizar</button>
                    </div>
                </div>
                <div class="table-wrap"><table class="wide-table impresoras-table"><thead><tr><th>ID</th><th>Ubicacion</th><th>Unidad</th><th>Area</th><th>Marca</th><th>Modelo</th><th>Tipo</th><th>Patrimonio</th><th>Inventario</th><th>Serie</th><th>Estado</th><th></th></tr></thead><tbody id="impresorasTable"></tbody></table></div>
                <div class="pagination-row" id="impresorasPagination"></div>
            </section>
        </main>
    </section>

    <dialog id="recordDialog">
        <form id="recordForm" method="dialog">
            <header><strong id="dialogTitle">Registro</strong><button class="icon-button" type="button" id="closeDialogButton">x</button></header>
            <div class="form-grid" id="dialogFields"></div>
            <footer>
                <button class="ghost-button" type="button" id="cancelDialogButton">Cancelar</button>
                <button type="submit">Guardar</button>
            </footer>
            <p class="form-message" id="dialogMessage"></p>
        </form>
    </dialog>
</body>
</html>


