<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/bootstrap.php';
$baseUrl = rtrim((string) $config['app']['base_url'], '/');
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$portalUrl = $requestHost !== '' ? $scheme . '://' . $requestHost . '/neo/public/' : '/neo/public/';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) $config['app']['name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css?v=20260624-soporte-topbar-safe">
    <link rel="stylesheet" href="/neo_shared/assets/css/neo-standard.css?v=20260630-profile-grid-contract">
    <script>
        window.SOPORTE_NEO_API_BASE = '<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/../api/v1';
        window.SOPORTE_NEO_PORTAL_URL = '<?= htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') ?>';
    </script>
    <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=20260624-soporte-topbar-safe" defer></script>
</head>
<body data-visual-class="soporte" class="auth-boot">
    <section class="login-screen" id="loginScreen">
        <div class="login-brand-panel" aria-hidden="true">
            <span class="brand-shine"></span>
            <div class="brand-emblem">SN</div>
            <h2>Soporte NEO</h2>
            <p>Mesa de ayuda TI conectada al acceso centralizado institucional.</p>
            <div class="feature-list">
                <span>Tickets de soporte</span>
                <span>Asignacion tecnica</span>
                <span>Seguimiento y cierre</span>
            </div>
            <div class="login-stats">
                <strong>Soporte</strong>
                <strong>TI</strong>
                <strong>NEO</strong>
            </div>
        </div>
        <form class="login-card" id="loginForm" autocomplete="on">
            <div class="brand-row">
                <span class="brand-dot mini-dot">SN</span>
                <strong>Soporte NEO</strong>
                <span>Mesa de ayuda TI</span>
            </div>
            <h1>Acceso a soporte</h1>
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
                <span class="brand-dot">SN</span>
                <div>
                    <strong>Soporte NEO</strong>
                    <small>Mesa de ayuda TI</small>
                </div>
            </div>
            <nav class="side-nav">
                <div class="nav-section">
                    <span class="nav-label">Principal</span>
                    <button class="nav-item active" type="button" data-view="dashboard" data-icon="D">Dashboard</button>
                    <button class="nav-item" type="button" data-view="tickets" data-icon="T">Tickets</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Operacion</span>
                    <button class="nav-item" type="button" data-view="tecnicos" data-icon="U">Tecnicos</button>
                    <button class="nav-item" type="button" data-view="reportes" data-icon="R">Reportes</button>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Sistema</span>
                    <button class="nav-item" type="button" data-view="profile" data-icon="U">Mi Perfil</button>
                    <button class="nav-item" type="button" data-view="configuracion" data-icon="C">Configuracion</button>
                </div>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar app-topbar">
                <section class="topbar-profile-summary" aria-label="Perfil activo">
                    <span class="topbar-profile-avatar" id="topbarProfileAvatar">SN</span>
                    <div>
                        <span>PERFIL ACTIVO</span>
                        <strong id="topbarProfileName">Soporte NEO</strong>
                        <small id="topbarProfileRole">Mesa de ayuda TI</small>
                    </div>
                </section>
                <div class="topbar-actions">
                    <input id="searchInput" type="search" placeholder="Buscar ticket, usuario o equipo">
                    <button type="button" id="newTicketButton">Nuevo Ticket</button>
                    <button class="ghost-button" type="button" id="refreshButton">Actualizar</button>
                    <div class="profile-menu">
                        <button type="button" class="profile-button account-menu-button" id="profileInitials" aria-label="Menu de usuario">...</button>
                        <div class="profile-dropdown">
                            <strong id="profileDropdownName">Soporte NEO</strong>
                            <span id="profileDropdownEmail">Mesa de ayuda TI</span>
                            <button type="button" data-open-profile>Mi Perfil</button>
                            <button type="button" id="logoutButton">Cerrar sesion</button>
                        </div>
                    </div>
                </div>
            </header>

            <section class="view-panel active" data-view-panel="dashboard">
                <section class="dashboard-hero">
                    <div>
                        <span class="hero-kicker">Sistema de gestion de soporte</span>
                        <h2>Mesa de ayuda separada del inventario y del turnero</h2>
                        <p>Registro, priorizacion, asignacion y resolucion de tickets de soporte TI.</p>
                    </div>
                    <div class="hero-badge">
                        <span>SN</span>
                        <strong>NEO</strong>
                    </div>
                </section>

                <section class="metrics-grid" id="statsGrid"></section>

                <section class="support-workspace">
                    <aside class="inbox-panel">
                        <div class="panel-head compact">
                            <div>
                                <h2>Bandeja de Tickets</h2>
                                <p id="inboxCount">0 tickets</p>
                            </div>
                        </div>
                        <input id="inboxSearch" type="search" placeholder="Buscar en bandeja">
                        <div id="ticketInbox" class="ticket-list"></div>
                    </aside>

                    <section class="tickets-panel">
                        <div class="panel-head">
                            <div>
                                <h2>Todos los Tickets</h2>
                                <p id="activeFilter">Vista general</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Usuario</th>
                                        <th>Area</th>
                                        <th>Equipo</th>
                                        <th>Problema</th>
                                        <th>Prioridad</th>
                                        <th>Estado</th>
                                        <th>Tecnico</th>
                                        <th>Registrado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="ticketsBody"></tbody>
                            </table>
                        </div>
                    </section>
                </section>
            </section>

            <section class="view-panel" data-view-panel="profile">
                <div class="page-head profile-page-head">
                    <div>
                        <h1>Mi Perfil</h1>
                        <p>Datos visibles y preferencia visual dentro de Soporte NEO.</p>
                    </div>
                    <button class="ghost-button" type="button" id="profileRefreshButton">Actualizar</button>
                </div>

                <section class="profile-hero">
                    <span class="profile-hero-avatar" id="profileHeroAvatar">SN</span>
                    <div>
                        <span>PERFIL ACTIVO</span>
                        <h2 id="profileHeroName">Soporte NEO</h2>
                        <p id="profileHeroRole">Mesa de ayuda TI</p>
                    </div>
                    <div class="profile-hero-color">
                        <span>COLOR</span>
                        <strong id="profileHeroColor">#dc2626</strong>
                    </div>
                </section>

                <section class="profile-panel">
                    <form id="profileForm" class="profile-form">
                        <div class="profile-form-grid">
                            <label>
                                Nombre visible
                                <input id="profileDisplayName" name="nombre_visible" type="text" maxlength="140">
                            </label>
                            <label>
                                Cargo o area
                                <input id="profileRoleArea" name="cargo_area" type="text" disabled>
                            </label>
                            <label>
                                Correo
                                <input id="profileEmail" type="text" disabled>
                            </label>
                            <label class="profile-color-field">
                                Color principal
                                <input id="profileAccent" name="color_principal" type="color" value="#dc2626">
                            </label>
                            <label class="wide">
                                Foto / avatar URL
                                <input id="profileAvatarUrl" name="avatar_url" type="url" placeholder="https://...">
                            </label>
                        </div>
                        <div class="profile-actions">
                            <button type="submit">Guardar perfil</button>
                            <span class="form-message" id="profileMessage"></span>
                        </div>
                    </form>
                </section>
            </section>
        </main>
    </section>

    <dialog id="ticketDialog">
        <form method="dialog" id="ticketForm">
            <header>
                <strong>Nuevo Ticket</strong>
                <button class="icon" type="button" id="closeDialogButton">x</button>
            </header>
            <div class="form-grid">
                <label>Usuario <input name="usuario" required></label>
                <label>Area <input name="area" required></label>
                <label>Equipo <input name="equipo" required></label>
                <label>Prioridad
                    <select name="prioridad">
                        <option>Urgente</option>
                        <option>Alta</option>
                        <option>Media</option>
                        <option>Baja</option>
                    </select>
                </label>
                <label class="wide">Problema <textarea name="problema" rows="3" required></textarea></label>
            </div>
            <footer>
                <button class="secondary" type="button" id="cancelDialogButton">Cancelar</button>
                <button type="submit">Guardar Ticket</button>
            </footer>
        </form>
    </dialog>
</body>
</html>



