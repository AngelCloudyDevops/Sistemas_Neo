<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $requestHost !== '' ? $scheme . '://' . $requestHost . '/ti_neo/public' : '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tickets HSJ Neo</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/assets/css/app.css' : 'assets/css/app.css', ENT_QUOTES, 'UTF-8') ?>?v=20260630-neo-layout">
    <link rel="stylesheet" href="/neo_shared/assets/css/neo-standard.css?v=20260630-profile-grid-contract">
    <script>
        window.TI_NEO_PUBLIC_BASE = '<?= htmlspecialchars($baseUrl !== '' ? $baseUrl : '.', ENT_QUOTES, 'UTF-8') ?>';
        window.TI_NEO_API_BASE = '<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/../api/v1' : '../api/v1', ENT_QUOTES, 'UTF-8') ?>';
        window.TI_NEO_PRESET_AVATARS = [
            'avatar_01.jpg',
            'avatar_02.jpg',
            'avatar_03.jpg',
            'avatar_04.webp',
            'avatar_05.jpg',
            'avatar_06.webp'
        ].map((name) => `${window.TI_NEO_PUBLIC_BASE}/assets/img/avatars/${name}`);
        window.TI_NEO_PRESET_BACKGROUNDS = [
            'bg_01.svg',
            'bg_02.svg',
            'bg_03.svg',
            'bg_04.svg'
        ].map((name) => `${window.TI_NEO_PUBLIC_BASE}/assets/img/backgrounds/${name}`);
    </script>
    <script src="<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/assets/js/app.js' : 'assets/js/app.js', ENT_QUOTES, 'UTF-8') ?>?v=20260630-neo-layout" defer></script>
</head>
<body data-visual-class="atencion">
    <section class="login-screen hidden" id="loginScreen">
        <div class="login-brand-panel" aria-hidden="true">
            <span class="brand-shine"></span>
            <div class="brand-emblem">TI</div>
            <h2>Tickets NEO</h2>
            <p>Emision, turnero, pantalla y reportes conectados al acceso institucional.</p>
            <div class="feature-list">
                <span>Ingreso con correo o DNI</span>
                <span>Sesion compatible con Portal NEO</span>
                <span>Permisos separados por perfil</span>
            </div>
            <div class="login-stats">
                <strong>Tickets</strong>
                <strong>Turnos</strong>
                <strong>NEO</strong>
            </div>
        </div>

        <form id="loginForm" class="login-card" autocomplete="on">
            <div class="brand-row">
                <span class="brand-dot"></span>
                <strong>Tickets HSJ</strong>
                <span>Sistema de emision</span>
            </div>
            <h1>Acceso al sistema de tickets</h1>
            <label>
                Correo o DNI
                <input id="loginEmail" name="login" type="text" inputmode="email" autocomplete="username" required>
            </label>
            <label>
                Contrasena
                <input id="loginPassword" name="password" type="password" autocomplete="current-password" required>
            </label>
            <button type="submit" id="loginButton">Ingresar</button>
            <a class="portal-login-button" href="/neo/public/">Ingresar desde Portal NEO</a>
            <p class="message" id="loginMessage"></p>
        </form>
    </section>

    <section class="app hidden" id="appShell">
        <button type="button" class="sidebar-scrim" id="sidebarScrim" aria-label="Cerrar menu"></button>
        <aside class="sidebar" id="sidebar">
            <div class="app-mark">
                <span class="module-icon">TI</span>
                <div>
                    <strong>Tickets Neo</strong>
                    <small>Sistema de gestion de tickets</small>
                </div>
            </div>
            <nav class="side-nav">
                <div class="nav-section">
                    <span class="nav-label">Atencion</span>
                    <a class="active" href="#emision" data-view="emision">Emision</a>
                    <a href="#turnero" data-view="turnero">Turnero</a>
                    <a href="#pantalla" data-view="pantalla">Vista previa</a>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Control</span>
                    <a href="#rangos" data-view="rangos">Rangos diarios</a>
                    <a href="#historial" data-view="historial">Historial</a>
                    <a href="#reportes" data-view="reportes">Reportes</a>
                </div>
                <div class="nav-section">
                    <span class="nav-label">Sistema</span>
                    <a href="#perfil" data-view="perfil">Mi Perfil</a>
                    <a href="#ventanillas" data-view="ventanillas">Ventanillas</a>
                    <a href="#pantallas" data-view="pantallas">Pantallas</a>
                    <a href="#impresion" data-view="impresion">Impresion</a>
                </div>
            </nav>
        </aside>

        <main class="content">
            <header class="app-topbar">
                <button type="button" class="menu-toggle" id="menuToggle" aria-label="Abrir menu" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <section class="topbar-profile-summary" aria-label="Perfil activo">
                    <span class="topbar-profile-avatar" id="topbarProfileAvatar">AD</span>
                    <div>
                        <span>Perfil activo</span>
                        <strong id="topbarProfileName">Usuario</strong>
                        <small id="topbarProfileRole">Equipo de atencion</small>
                    </div>
                </section>
                <div class="topbar-actions">
                    <div class="topbar-search">
                        <span>Q</span>
                        <input id="globalSearchInput" type="search" placeholder="Buscar tickets, documentos...">
                    </div>
                    <button type="button" class="topbar-icon" id="topbarScanButton" aria-label="Ir a emision" hidden aria-hidden="true" tabindex="-1">ID</button>
                    <button type="button" class="topbar-icon" id="openOperatorButton" aria-label="Abrir mini turnero" hidden aria-hidden="true" tabindex="-1">TV</button>
                    <button type="button" class="topbar-icon" id="topbarRefreshButton" aria-label="Actualizar vista" hidden aria-hidden="true" tabindex="-1">!</button>
                    <div class="profile-menu">
                        <button type="button" class="profile-button account-menu-button" id="profileInitials" aria-label="Menu de usuario">...</button>
                        <div class="profile-dropdown">
                            <strong id="profileName">Usuario</strong>
                            <span id="currentUser">Usuario</span>
                            <a href="#perfil" data-view="perfil">Mi Perfil</a>
                            <a href="#ventanillas" data-view="ventanillas">Ventanillas</a>
                            <button type="button" id="logoutButton">Cerrar Sesion</button>
                        </div>
                    </div>
                </div>
            </header>

            <section class="view active" data-view-panel="emision">
                <div class="page-head">
                    <div>
                        <h1>Emision de Tickets</h1>
                        <p id="operationalDate">Cargando fecha operativa...</p>
                    </div>
                    <button type="button" id="refreshButton">Actualizar</button>
                </div>

                <section class="stats-grid">
                    <article class="stat">
                        <span>Emitidos hoy</span>
                        <strong id="statTotal">0</strong>
                        <small>Tickets registrados</small>
                    </article>
                    <article class="stat">
                        <span>Preferenciales</span>
                        <strong class="amber" id="statPreferencial">0</strong>
                        <small>Atencion prioritaria</small>
                    </article>
                    <article class="stat">
                        <span>Normales</span>
                        <strong class="green" id="statNormal">0</strong>
                        <small>Atencion regular</small>
                    </article>
                    <article class="stat">
                        <span>Procedimientos</span>
                        <strong class="blue" id="statProcedimiento">0</strong>
                        <small>Por especialidad</small>
                    </article>
                    <article class="stat">
                        <span>Ultimo ticket</span>
                        <strong id="statLast">-</strong>
                        <small>Ultima emision</small>
                    </article>
                </section>

                <section class="work-grid">
                    <article class="panel">
                        <h2>Emitir nuevo ticket</h2>
                        <div class="emit-mode" aria-label="Modo de emision">
                            <span>Modo</span>
                            <div class="emit-mode-options">
                                <button id="emitModeNormal" class="active" type="button" data-emit-mode="normal">Atencion normal</button>
                                <button id="emitModePrintOnly" type="button" data-emit-mode="solo_impresion">Solo impresion</button>
                            </div>
                        </div>
                        <p id="emitModeNotice" class="emit-mode-notice" hidden>Solo impresion: el ticket se imprime y queda registrado, pero no entra al turnero.</p>
                        <div class="emit-steps" aria-label="Progreso de emision">
                            <span class="active" data-step-dot="1">1 Documento</span>
                            <span data-step-dot="2">2 Especialidad</span>
                            <span data-step-dot="3">3 Ticket</span>
                        </div>

                        <div class="emit-step active" data-step-panel="1">
                            <input id="documentType" type="hidden" value="DNI">
                            <div class="doc-type-actions">
                                <button class="doc-type-button active" type="button" data-doc-type="DNI">DNI</button>
                                <button class="doc-type-button" type="button" data-doc-type="CE">Carne extranjeria</button>
                                <button class="doc-type-button" type="button" data-doc-type="PASAPORTE">Pasaporte</button>
                            </div>
                            <label class="document-label">
                                Numero de documento
                                <input id="documentNumber" type="text" inputmode="text" autocomplete="off" placeholder="Escanea o escribe el documento">
                            </label>
                            <button class="step-next" type="button" id="goSpecialtyButton">Continuar</button>
                        </div>

                        <div class="emit-step" data-step-panel="2">
                            <input id="specialtySelect" type="hidden" value="">
                            <label>
                                Buscar especialidad
                                <input id="specialtySearch" type="search" autocomplete="off" placeholder="Escribe para filtrar">
                            </label>
                            <div id="specialtyList" class="specialty-button-grid"></div>
                            <div class="frequent-specialties">
                                <strong>Frecuentes de hoy</strong>
                                <div id="frequentSpecialtyList" class="specialty-button-grid compact"></div>
                            </div>
                            <div class="step-actions">
                                <button class="light" type="button" data-go-step="1">Volver</button>
                                <button class="step-next" type="button" id="goTicketTypeButton">Continuar</button>
                            </div>
                        </div>

                        <div class="emit-step" data-step-panel="3">
                            <div class="selected-summary" id="selectedSummary">Documento y especialidad listos.</div>
                            <div class="ticket-actions">
                                <button class="ticket-button preferencial" type="button" data-type="Preferencial" data-shortcut="1">
                                    <span>Preferencial</span>
                                    <small class="ticket-hotkey">Tecla 1</small>
                                </button>
                                <button class="ticket-button normal" type="button" data-type="Normal" data-shortcut="2">
                                    <span>Normal</span>
                                    <small class="ticket-hotkey">Tecla 2</small>
                                </button>
                                <button class="ticket-button procedimiento" type="button" data-type="Procedimiento" data-shortcut="3">
                                    <span>Procedimiento</span>
                                    <small class="ticket-hotkey">Tecla 3</small>
                                </button>
                            </div>
                            <button class="light step-back" type="button" data-go-step="2">Volver</button>
                        </div>

                        <p class="message" id="emitMessage"></p>
                    </article>

                    <article class="panel availability-panel">
                        <div class="panel-title-row">
                            <div>
                                <h2>Disponibilidad de citas</h2>
                                <p>Cupos del mes y prÃƒÂ³ximas fechas SIGH menos reservas temporales de Tickets NEO.</p>
                            </div>
                            <span id="availabilityPeriod">Mes actual</span>
                        </div>
                        <div class="availability-tabs" id="availabilityTabs" role="tablist" aria-label="Filtro de cupos del mes"></div>
                        <div class="availability-list" id="availabilityPanelList">
                            <span class="availability-empty">Cargando disponibilidad...</span>
                        </div>
                    </article>
 
                    <article class="panel daily-reprint-panel" id="dailyReprintPanel" hidden>
                        <div class="panel-title-row">
                            <div>
                                <h2>Reimprimir hoy</h2>
                                <p>Busqueda limitada a tickets emitidos durante el dia operativo.</p>
                            </div>
                        </div>
                        <div class="daily-reprint-search">
                            <input class="filter-search" id="dailyReprintSearchInput" type="search" placeholder="Codigo o documento de hoy">
                            <button type="button" id="dailyReprintSearchButton">Buscar</button>
                        </div>
                        <div class="table-wrap daily-reprint-results">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Hora</th>
                                        <th>Ticket</th>
                                        <th>Documento</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="dailyReprintBody">
                                    <tr><td colspan="4">Busca un codigo o documento.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="message" id="dailyReprintMessage"></p>
                    </article>
                </section>

                <article class="panel range-availability-panel">
                    <div class="panel-title-row">
                        <div>
                            <h2>Rangos disponibles</h2>
                            <p>Control rapido de numeros restantes por tipo de ticket.</p>
                        </div>
                        <span id="rangeStatus">Cargando rangos...</span>
                    </div>
                    <div id="rangesList" class="ranges-list compact"></div>
                </article>
            </section>

            <section class="view" data-view-panel="turnero">
                <div class="page-head">
                    <div>
                        <h1>Turnero</h1>
                        <p id="queueOperationalDate">Cola de atencion del dia.</p>
                    </div>
                    <div class="row-actions">
                        <button type="button" id="queueCallNextButton">Llamar siguiente</button>
                        <button type="button" class="light" id="queueRefreshButton">Actualizar</button>
                    </div>
                </div>

                <section class="stats-grid queue-stats-grid">
                    <article class="stat">
                        <span>En cola</span>
                        <strong id="queueStatWaiting">0</strong>
                        <small>Listos para llamar</small>
                    </article>
                    <article class="stat">
                        <span>Llamando</span>
                        <strong class="amber" id="queueStatCalling">0</strong>
                        <small>En pantalla</small>
                    </article>
                    <article class="stat">
                        <span>Atendiendo</span>
                        <strong class="blue" id="queueStatServing">0</strong>
                        <small>En ventanilla</small>
                    </article>
                    <article class="stat">
                        <span>Atendidos</span>
                        <strong class="green" id="queueStatDone">0</strong>
                        <small>Cerrados hoy</small>
                    </article>
                    <article class="stat">
                        <span>Ausentes</span>
                        <strong class="red" id="queueStatAbsent">0</strong>
                        <small>No respondieron</small>
                    </article>
                </section>

                <section class="work-grid queue-work-grid">
                    <article class="panel">
                        <div class="panel-head">
                            <h2>Cola activa</h2>
                            <span id="queueActiveCount" class="count-pill">0 tickets</span>
                        </div>
                        <div id="queueActiveList" class="queue-list"></div>
                    </article>

                    <article class="panel">
                        <div class="panel-head">
                            <h2>Enviar a cola</h2>
                            <span id="queueAvailableCount" class="count-pill">0 impresos</span>
                        </div>
                        <div class="filter-toolbar queue-filter-toolbar">
                            <input class="filter-search" id="queueSearchInput" type="search" placeholder="Buscar codigo o documento...">
                            <button type="button" class="light" id="queueClearSearchButton">Limpiar</button>
                        </div>
                        <div id="queueAvailableList" class="queue-list compact"></div>
                    </article>
                </section>

                <p class="message" id="queueMessage"></p>
            </section>

            <section class="view" data-view-panel="pantalla">
                <div class="page-head">
                    <div>
                        <h1>Vista previa del turnero</h1>
                        <p id="screenOperationalDate">PrevisualizaciÃƒÂ³n local del llamado actual.</p>
                    </div>
                    <button type="button" class="light" id="screenRefreshButton">Actualizar</button>
                </div>

                <section class="screen-board">
                    <article class="screen-media-panel">
                        <video class="screen-media-video" autoplay muted loop playsinline preload="metadata">
                            <source src="assets/videos/sala.mp4" type="video/mp4">
                        </video>
                        <div class="screen-media-fallback">
                            <strong>Hospital San JosÃƒÂ© del Callao</strong>
                            <span>Video institucional</span>
                        </div>
                        <div class="screen-flow-guide" aria-label="Pasos para atenciÃƒÂ³n">
                            <article class="screen-flow-step">
                                <span class="screen-flow-number">1</span>
                                <svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="DNI en mano">
                                    <rect x="42" y="16" width="58" height="36" rx="4" fill="#eff6ff" stroke="#2563eb" stroke-width="3"/>
                                    <rect x="45" y="19" width="52" height="9" rx="2" fill="#2563eb"/>
                                    <circle cx="57" cy="39" r="7" fill="#f59e0b"/>
                                    <rect x="69" y="34" width="22" height="4" rx="2" fill="#64748b"/>
                                    <rect x="69" y="43" width="17" height="4" rx="2" fill="#94a3b8"/>
                                    <path d="M27 72c-8-13-7-31 2-39 5-5 12-3 13 5l3 16 9-10c4-5 11 0 8 6L50 74z" fill="#f8c6a6"/>
                                    <path d="M35 74h25v12H35z" fill="#1d4ed8"/>
                                </svg>
                                <strong>DNI en mano</strong>
                                <small>Prepara tu documento.</small>
                            </article>
                            <span class="screen-flow-arrow" aria-hidden="true">&rarr;</span>
                            <article class="screen-flow-step">
                                <span class="screen-flow-number">2</span>
                                <svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="Ticket en Puerta 4">
                                    <rect x="42" y="12" width="38" height="62" rx="8" fill="#dbeafe" stroke="#2563eb" stroke-width="4"/>
                                    <rect x="50" y="22" width="22" height="14" rx="3" fill="#0f172a"/>
                                    <rect x="52" y="44" width="18" height="6" rx="3" fill="#16a34a"/>
                                    <path d="M83 42h24v34H83z" fill="#fff7ed" stroke="#f59e0b" stroke-width="3"/>
                                    <path d="M88 52h14M88 60h12" stroke="#64748b" stroke-width="3" stroke-linecap="round"/>
                                    <circle cx="26" cy="31" r="10" fill="#f8c6a6"/>
                                    <path d="M15 76c3-20 21-20 24 0z" fill="#2563eb"/>
                                    <rect x="30" y="40" width="18" height="8" rx="4" fill="#f8c6a6"/>
                                </svg>
                                <strong>Ticket Puerta 4</strong>
                                <small>Retira tu ticket.</small>
                            </article>
                            <span class="screen-flow-arrow" aria-hidden="true">&rarr;</span>
                            <article class="screen-flow-step">
                                <span class="screen-flow-number">3</span>
                                <svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="Mira tu turno">
                                    <rect x="26" y="12" width="70" height="44" rx="6" fill="#0f172a" stroke="#60a5fa" stroke-width="4"/>
                                    <rect x="37" y="24" width="48" height="10" rx="3" fill="#2563eb"/>
                                    <rect x="46" y="39" width="30" height="6" rx="3" fill="#bfdbfe"/>
                                    <path d="M58 56h8v10h14v7H44v-7h14z" fill="#64748b"/>
                                    <circle cx="27" cy="68" r="9" fill="#f8c6a6"/>
                                    <path d="M13 87c3-17 24-17 28 0z" fill="#16a34a"/>
                                    <circle cx="92" cy="68" r="9" fill="#f8c6a6"/>
                                    <path d="M78 87c3-17 24-17 28 0z" fill="#f59e0b"/>
                                </svg>
                                <strong>Mira la pantalla</strong>
                                <small>Espera tu turno.</small>
                            </article>
                            <span class="screen-flow-arrow" aria-hidden="true">&rarr;</span>
                            <article class="screen-flow-step">
                                <span class="screen-flow-number">4</span>
                                <svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="AcÃƒÂ©rcate a ventanilla">
                                    <rect x="43" y="11" width="62" height="50" rx="6" fill="#eff6ff" stroke="#2563eb" stroke-width="4"/>
                                    <rect x="49" y="18" width="50" height="15" rx="3" fill="#bfdbfe"/>
                                    <path d="M48 48h52" stroke="#2563eb" stroke-width="4" stroke-linecap="round"/>
                                    <circle cx="26" cy="36" r="10" fill="#f8c6a6"/>
                                    <path d="M12 80c4-23 25-23 31 0z" fill="#2563eb"/>
                                    <rect x="35" y="48" width="20" height="10" rx="3" fill="#fff" stroke="#94a3b8" stroke-width="2"/>
                                    <rect x="55" y="58" width="24" height="13" rx="3" fill="#fff7ed" stroke="#f59e0b" stroke-width="2"/>
                                </svg>
                                <strong>AcÃƒÂ©rcate a ventanilla</strong>
                                <small>Lleva ticket y DNI.</small>
                            </article>
                        </div>
                    </article>
                    <article class="panel screen-recent-panel">
                        <h2>Turno / Ventanilla</h2>
                        <div id="screenRecentList" class="screen-recent-list"></div>
                    </article>
                </section>
                <div class="screen-ticker">
                    <span id="screenTickerText">Hospital San JosÃƒÂ© del Callao - Mantenga su DNI a la mano y acÃƒÂ©rquese cuando vea su turno en pantalla.</span>
                </div>
            </section>

            <section class="view" data-view-panel="rangos">
                <div class="page-head">
                    <div>
                        <h1>Rangos diarios</h1>
                        <p>Configura numeracion, genera lotes y retoma impresiones pendientes.</p>
                    </div>
                </div>

                <section class="panel">
                    <div class="panel-head">
                        <h2>Calendario de rangos</h2>
                        <div class="row-actions">
                            <button type="button" class="light" id="rangePrevMonth">Anterior</button>
                            <button type="button" class="light" id="rangeToday">Hoy</button>
                            <button type="button" class="light" id="rangeNextMonth">Siguiente</button>
                        </div>
                    </div>
                    <p class="message" id="rangesMonthTitle">Cargando...</p>
                    <div class="calendar-weekdays">
                        <span>Lun</span><span>Mar</span><span>Mie</span><span>Jue</span><span>Vie</span><span>Sab</span><span>Dom</span>
                    </div>
                    <div id="rangesCalendar" class="calendar-grid"></div>
                </section>

                <section class="work-grid range-work-grid">
                    <article class="panel">
                        <h2>Rango seleccionado</h2>
                        <div class="range-summary-card">
                            <strong id="rangeSummaryTitle">Selecciona fecha y tipo</strong>
                            <div class="range-summary-grid">
                                <span>Fecha <b id="rangeSummaryDate">-</b></span>
                                <span>Tipo <b id="rangeSummaryType">-</b></span>
                                <span>Rango <b id="rangeSummaryRange">-</b></span>
                                <span>Usados <b id="rangeSummaryUsed">0</b></span>
                                <span>Siguiente <b id="rangeSummaryNext">-</b></span>
                                <span>Disponible <b id="rangeSummaryAvailable">0</b></span>
                            </div>
                            <small id="rangeSummaryState" class="range-state warn">Sin rango</small>
                        </div>

                        <div class="form-grid range-form">
                            <label>
                                Fecha
                                <input id="rangeDate" type="date">
                            </label>
                            <label>
                                Tipo
                                <select id="rangeType">
                                    <option value="Preferencial">Preferencial</option>
                                    <option value="Normal">Normal</option>
                                    <option value="Procedimiento">Procedimiento</option>
                                </select>
                            </label>
                            <label>
                                Desde
                                <input id="rangeFrom" type="number" min="1" value="1">
                            </label>
                            <label>
                                Hasta
                                <input id="rangeTo" type="number" min="1" value="300">
                            </label>
                            <label>
                                Siguiente
                                <input id="rangeNext" type="number" disabled>
                            </label>
                        </div>
                        <button type="button" id="saveRangeButton">Guardar rango</button>
                        <p class="message" id="rangeMessage"></p>
                    </article>

                    <article class="panel">
                        <h2>Impresion por lote</h2>
                        <div class="form-grid batch-form">
                            <label>
                                Fecha
                                <input id="batchDate" type="date">
                            </label>
                            <label>
                                Tipo
                                <select id="batchType">
                                    <option value="Preferencial">Preferencial</option>
                                    <option value="Normal">Normal</option>
                                    <option value="Procedimiento">Procedimiento</option>
                                </select>
                            </label>
                            <label>
                                Pausa ms
                                <input id="batchPauseMs" type="number" min="300" max="3000" step="100" value="900">
                            </label>
                        </div>
                        <div class="row-actions">
                            <button type="button" id="generateBatchButton">Imprimir lote pendiente</button>
                            <button type="button" class="danger" id="cancelBatchButton">Cancelar impresion</button>
                        </div>
                        <p class="message" id="batchStatus">Selecciona un rango con disponibilidad.</p>

                        <h2 class="section-subtitle">Impresion parcial</h2>
                        <div class="form-grid batch-form">
                            <label>
                                Desde
                                <input id="partialFrom" type="number" min="1" disabled>
                            </label>
                            <label>
                                Hasta
                                <input id="partialTo" type="number" min="1">
                            </label>
                            <label>
                                Pausa ms
                                <input id="partialPauseMs" type="number" min="300" max="3000" step="100" value="900">
                            </label>
                        </div>
                        <button type="button" class="light" id="generatePartialBatchButton">Imprimir parte</button>
                        <p class="message" id="partialBatchStatus">Imprime una parte consecutiva desde el siguiente disponible.</p>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <h2>Lotes pendientes</h2>
                        <button type="button" class="light" id="pendingBatchesRefreshButton">Actualizar</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Rango</th>
                                    <th>Cantidad</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="pendingBatchesBody">
                                <tr><td colspan="6">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section class="view" data-view-panel="historial">
                <div class="page-head">
                    <div>
                        <h1>Historial</h1>
                        <p>Consulta, reimprime o anula tickets registrados.</p>
                    </div>
                    <button type="button" class="ghost light" id="historyRefreshButton">Actualizar</button>
                </div>

                <section class="panel">
                    <div class="filter-toolbar">
                        <input class="filter-search" id="historySearchFilter" type="search" placeholder="Buscar por codigo o documento...">
                        <input id="historyDateFilter" type="date">
                        <select id="historyTypeFilter">
                            <option value="">Todos los tipos</option>
                            <option value="Preferencial">Preferencial</option>
                            <option value="Normal">Normal</option>
                            <option value="Procedimiento">Procedimiento</option>
                        </select>
                        <select id="historyStatusFilter">
                            <option value="">Todos los estados</option>
                            <option value="emitido">Emitidos</option>
                            <option value="anulado">Anulados</option>
                        </select>
                        <button type="button" class="filter-button" id="historyFilterButton">Filtrar</button>
                        <button type="button" class="light" id="historyClearButton">Limpiar</button>
                    </div>
                    <div class="panel-head">
                        <h2>Ultimos tickets</h2>
                        <span id="historyCount" class="count-pill">0 registros</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Hora</th>
                                    <th>Tipo</th>
                                    <th>Codigo</th>
                                    <th>Documento</th>
                                    <th>Especialidad</th>
                                    <th>Impresion</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="historyBody">
                                <tr><td colspan="8">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-bar">
                        <button type="button" class="light" id="historyPrevPage">Anterior</button>
                        <span id="historyPageInfo">Pagina 1 de 1</span>
                        <button type="button" class="light" id="historyNextPage">Siguiente</button>
                    </div>
                </section>
            </section>

            <section class="view" data-view-panel="reportes">
                <div class="page-head">
                    <div>
                        <h1>Reportes</h1>
                        <p id="reportPeriod">Resumen operativo del modulo de tickets.</p>
                    </div>
                    <button type="button" id="reportRefreshButton">Actualizar</button>
                </div>

                <section class="panel">
                    <div class="filter-toolbar report-filter-toolbar">
                        <label>
                            Desde
                            <input id="reportFrom" type="date">
                        </label>
                        <label>
                            Hasta
                            <input id="reportTo" type="date">
                        </label>
                        <label>
                            Tipo
                            <select id="reportType">
                                <option value="">Todos</option>
                                <option value="Preferencial">Preferencial</option>
                                <option value="Normal">Normal</option>
                                <option value="Procedimiento">Procedimiento</option>
                            </select>
                        </label>
                        <label>
                            Estado
                            <select id="reportStatus">
                                <option value="">Todos</option>
                                <option value="emitido">Emitidos</option>
                                <option value="anulado">Anulados</option>
                            </select>
                        </label>
                        <button type="button" class="filter-button" id="reportFilterButton">Filtrar</button>
                        <button type="button" class="light" id="reportClearButton">Limpiar</button>
                    </div>
                    <p class="message" id="reportMessage"></p>
                </section>

                <section class="stats-grid">
                    <article class="stat">
                        <span>Total periodo</span>
                        <strong id="reportTotal">0</strong>
                        <small>Todos los tickets</small>
                    </article>
                    <article class="stat">
                        <span>Validos</span>
                        <strong class="green" id="reportValid">0</strong>
                        <small>No anulados</small>
                    </article>
                    <article class="stat">
                        <span>Anulados</span>
                        <strong class="red" id="reportCanceled">0</strong>
                        <small>Con motivo registrado</small>
                    </article>
                    <article class="stat">
                        <span>Procedimientos</span>
                        <strong class="blue" id="reportProcedures">0</strong>
                        <small>Validos</small>
                    </article>
                    <article class="stat">
                        <span>Reimpresiones</span>
                        <strong id="reportReprints">0</strong>
                        <small>Acumuladas</small>
                    </article>
                </section>

                <section class="work-grid report-grid">
                    <article class="panel">
                        <h2>Tickets por hora</h2>
                        <div id="reportByHour" class="bar-list"></div>
                    </article>
                    <article class="panel">
                        <h2>Tickets por dia</h2>
                        <div id="reportByDay" class="bar-list"></div>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <h2>Detalle del periodo</h2>
                        <span id="reportDetailCount" class="count-pill">0 registros</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Hora</th>
                                    <th>Tipo</th>
                                    <th>Codigo</th>
                                    <th>Estado</th>
                                    <th>Reimp.</th>
                                </tr>
                            </thead>
                            <tbody id="reportDetailBody">
                                <tr><td colspan="6">Genera un reporte para ver detalle.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-bar">
                        <button type="button" class="light" id="reportPrevPage">Anterior</button>
                        <span id="reportPageInfo">Pagina 1 de 1</span>
                        <button type="button" class="light" id="reportNextPage">Siguiente</button>
                    </div>
                </section>
            </section>

            <section class="view" data-view-panel="perfil">
                <div class="page-head">
                    <div>
                        <h1>Mi Perfil</h1>
                        <p>Parametros generales compartidos en todos tus sistemas.</p>
                    </div>
                </div>

                <section class="profile-hero">
                    <div class="profile-identity">
                        <div class="profile-avatar" id="profileAvatarPreview">AD</div>
                        <div>
                            <span>Perfil activo</span>
                            <strong id="profileHeroName">Usuario</strong>
                            <small id="profileHeroRole">Equipo de atencion</small>
                        </div>
                    </div>
                    <div class="profile-goal-summary">
                        <span>Color principal</span>
                        <strong id="profileColorSummary">#7c3aed</strong>
                        <small>Preferencia visual de tu perfil.</small>
                    </div>
                </section>

                <form class="profile-form" id="profileForm">
                    <label class="profile-display-field">
                        Nombre visible
                        <input id="profileDisplayName" type="text" maxlength="160" autocomplete="name">
                    </label>
                    <label class="profile-role-field">
                        Cargo o area
                        <input id="profileRoleArea" type="text" maxlength="160" disabled>
                    </label>
                    <label class="profile-email-field">
                        Correo
                        <input id="profileEmail" type="email" disabled>
                    </label>
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
                    <label class="profile-avatar-field">
                        Foto / avatar URL
                        <input id="profileAvatarUrl" type="url" placeholder="https://...">
                    </label>
                    <div class="avatar-preset-field full">
                        <span>Avatar predefinido</span>
                        <div class="avatar-preset-grid" id="profileAvatarPresets" aria-label="Seleccionar avatar predefinido"></div>
                    </div>
                    <div class="background-preset-field">
                        <span>Fondo visual</span>
                        <div class="background-preset-grid" id="profileBackgroundPresets" aria-label="Seleccionar fondo predefinido"></div>
                    </div>
                    <label class="full profile-background-field">Fondo URL
                        <input id="profileBackgroundUrl" type="url" placeholder="https://...">
                    </label>
                    <label class="full profile-opacity-field">Opacidad del fondo
                        <span class="opacity-control">
                            <input id="profileBackgroundOpacity" type="range" min="0" max="35" step="1" value="12">
                            <strong id="profileBackgroundOpacityValue">12%</strong>
                        </span>
                    </label>
                    <button type="button" id="saveProfileButton">Guardar perfil</button>
                    <button type="button" class="secondary-profile-button" id="requestPasswordResetButton">Solicitar reinicio de contrasena</button>
                    <p class="form-message message" id="profileMessage"></p>
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
                    <p class="message" id="changeProfilePasswordMessage"></p>
                </div>
            </section>

            <section class="view" data-view-panel="ventanillas">
                <div class="page-head">
                    <div>
                        <h1>Ventanillas</h1>
                        <p>Asigna usuarios, modos de atencion y libera ventanillas activas.</p>
                    </div>
                </div>

                <section class="panel" id="windowsAdminPanel">
                    <div class="panel-head">
                        <h2>Control maestro de ventanillas</h2>
                        <button type="button" class="light" id="windowsAdminRefreshButton">Actualizar</button>
                    </div>
                    <div class="window-admin-form">
                        <select id="windowAdminUserSelect">
                            <option value="">Selecciona usuario</option>
                        </select>
                        <select id="windowAdminNumberSelect">
                            <option value="">Ventanilla</option>
                            <option value="1">Ventanilla 1</option>
                            <option value="2">Ventanilla 2</option>
                            <option value="3">Ventanilla 3</option>
                            <option value="4">Ventanilla 4</option>
                            <option value="5">Ventanilla 5</option>
                            <option value="6">Ventanilla 6</option>
                            <option value="7">Ventanilla 7</option>
                            <option value="8">Ventanilla 8</option>
                            <option value="9">Ventanilla 9</option>
                        </select>
                        <button type="button" id="windowAdminAssignButton">Asignar</button>
                    </div>
                    <div id="windowsAdminList" class="windows-admin-list"></div>
                    <p class="message" id="windowsAdminMessage"></p>
                </section>
            </section>

            <section class="view" data-view-panel="pantallas">
                <div class="page-head">
                    <div>
                        <h1>Pantallas</h1>
                        <p>Administra pantallas fisicas, estado en linea y URLs publicas seguras.</p>
                    </div>
                </div>

                <section class="panel" id="screenDevicesPanel">
                    <div class="panel-head">
                        <h2>Pantallas fisicas</h2>
                        <button type="button" class="light" id="screenDevicesRefreshButton">Actualizar</button>
                    </div>
                    <div class="screen-ticker-config">
                        <label>
                            Ticker global
                            <textarea id="screenGlobalTickerInput" maxlength="300" rows="2" placeholder="Mensaje que se mostrara abajo en todas las pantallas fisicas"></textarea>
                        </label>
                        <button type="button" id="screenGlobalTickerSaveButton">Guardar ticker</button>
                    </div>
                    <div class="window-admin-form">
                        <input id="screenDeviceName" type="text" placeholder="Nombre de pantalla">
                        <input id="screenDeviceLocation" type="text" placeholder="Ubicacion">
                        <select id="screenDeviceFormat" aria-label="Formato de pantalla">
                            <option value="turnos_clasico">Turnero</option>
                            <option value="citas_mes_doctor">Citas del mes por doctor</option>
                            <option value="citas_mes_doctor_limpio">Citas del mes limpio</option>
                            <option value="citas_mes_doctor_aviso">Citas del mes con aviso</option>
                            <option value="imagen_url">Imagen por URL</option>
                        </select>
                        <button type="button" id="screenDeviceCreateButton">Crear pantalla</button>
                    </div>
                    <p class="form-hint">La URL solo se muestra al crear o regenerar. Guardala antes de recargar.</p>
                    <div id="screenDevicesList" class="screen-devices-list"></div>
                    <p class="message" id="screenDevicesMessage"></p>
                </section>

                <div class="modal-backdrop" id="screenDeviceEditModal" hidden>
                    <section class="modal-card screen-device-edit-modal" role="dialog" aria-modal="true" aria-labelledby="screenDeviceEditTitle">
                        <div class="modal-head">
                            <div>
                                <span id="screenDeviceEditCode">Pantalla</span>
                                <h2 id="screenDeviceEditTitle">Editar pantalla</h2>
                            </div>
                            <button type="button" class="icon-button" id="screenDeviceEditCloseButton" aria-label="Cerrar">X</button>
                        </div>
                        <div class="modal-body">
                            <input id="screenDeviceEditId" type="hidden">
                            <label>
                                Nombre
                                <input id="screenDeviceEditName" type="text" maxlength="120">
                            </label>
                            <label>
                                UbicaciÃƒÂ³n
                                <input id="screenDeviceEditLocation" type="text" maxlength="160" placeholder="Sin ubicaciÃƒÂ³n">
                            </label>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="light" id="screenDeviceEditCancelButton">Cancelar</button>
                            <button type="button" id="screenDeviceEditSaveButton">Guardar datos</button>
                        </div>
                    </section>
                </div>
            </section>

            <section class="view" data-view-panel="impresion">
                <div class="page-head">
                    <div>
                        <h1>Impresion</h1>
                        <p>Ajusta el tamano real de impresion y revisa la vista previa.</p>
                    </div>
                </div>

                <section class="work-grid config-print-grid">
                    <article class="panel">
                        <h2>Ajuste del ticket impreso</h2>
                        <div class="print-config-grid" id="printConfigForm">
                            <section class="print-config-section">
                                <h3>Base</h3>
                                <div class="print-config-row">
                                    <label>
                                        Papel
                                        <input data-print-setting="papel_mm" type="number" min="50" max="90" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Area util
                                        <input data-print-setting="contenido_mm" type="number" min="40" max="86" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Margen
                                        <input data-print-setting="padding_mm" type="number" min="0" max="12" step="1">
                                        <small>mm</small>
                                    </label>
                                </div>
                            </section>

                            <section class="print-config-section">
                                <h3>Tamanos</h3>
                                <div class="print-config-row">
                                    <label>
                                        Logos
                                        <input data-print-setting="logo_mm" type="number" min="8" max="22" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Hospital
                                        <input data-print-setting="header_px" type="number" min="8" max="22" step="1">
                                        <small>px</small>
                                    </label>
                                    <label>
                                        Codigo
                                        <input data-print-setting="codigo_px" type="number" min="28" max="72" step="1">
                                        <small>px</small>
                                    </label>
                                    <label>
                                        Documento
                                        <input data-print-setting="documento_px" type="number" min="10" max="30" step="1">
                                        <small>px</small>
                                    </label>
                                    <label>
                                        Especialidad
                                        <input data-print-setting="especialidad_px" type="number" min="10" max="32" step="1">
                                        <small>px</small>
                                    </label>
                                    <label>
                                        Fecha
                                        <input data-print-setting="fecha_px" type="number" min="16" max="42" step="1">
                                        <small>px</small>
                                    </label>
                                </div>
                            </section>

                            <section class="print-config-section">
                                <h3>Espacios</h3>
                                <div class="print-config-row">
                                    <label>
                                        Antes codigo
                                        <input data-print-setting="codigo_margen_mm" type="number" min="0" max="18" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Entre datos
                                        <input data-print-setting="linea_margen_mm" type="number" min="0" max="14" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Antes fecha
                                        <input data-print-setting="fecha_margen_mm" type="number" min="0" max="18" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Mover derecha
                                        <input data-print-setting="offset_x_mm" type="number" min="-10" max="10" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        Mover arriba
                                        <input data-print-setting="offset_y_mm" type="number" min="-15" max="8" step="1">
                                        <small>mm</small>
                                    </label>
                                </div>
                            </section>

                            <section class="print-config-section">
                                <h3>Mover partes</h3>
                                <div class="print-position-grid">
                                    <span></span>
                                    <strong>Derecha / izquierda</strong>
                                    <strong>Abajo / arriba</strong>

                                    <span>Hospital</span>
                                    <label>
                                        <input data-print-setting="hospital_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="hospital_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>

                                    <span>Logo izquierdo</span>
                                    <label>
                                        <input data-print-setting="logo_left_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="logo_left_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>

                                    <span>Logo derecho</span>
                                    <label>
                                        <input data-print-setting="logo_right_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="logo_right_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>

                                    <span>Codigo</span>
                                    <label>
                                        <input data-print-setting="code_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="code_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>

                                    <span>Documento</span>
                                    <label>
                                        <input data-print-setting="doc_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="doc_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>

                                    <span>Especialidad</span>
                                    <label>
                                        <input data-print-setting="specialty_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="specialty_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>

                                    <span>Fecha</span>
                                    <label>
                                        <input data-print-setting="date_x_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                    <label>
                                        <input data-print-setting="date_y_mm" type="number" min="-20" max="20" step="1">
                                        <small>mm</small>
                                    </label>
                                </div>
                            </section>
                        </div>
                        <div class="step-actions config-actions">
                            <button type="button" id="savePrintLayoutButton">Guardar diseno</button>
                            <button type="button" class="light" id="resetPrintLayoutButton">Restaurar base</button>
                        </div>
                        <p class="message" id="printLayoutMessage"></p>
                    </article>

                    <article class="panel preview-panel">
                        <h2>Vista previa de impresion</h2>
                        <div class="ticket-preview print-layout-preview" id="printLayoutPreview">
                            <div class="ticket-header ticket-brand">
                                <img class="logo-placeholder ticket-logo visible" data-logo-left src="https://thumbs4.imagebam.com/a4/33/1a/ME1CROZK_t.png" alt="">
                                <strong data-ticket-title>HOSPITAL SAN
JOSE DEL CALLAO</strong>
                                <img class="logo-placeholder ticket-logo visible" data-logo-right src="https://thumbs4.imagebam.com/bd/2a/44/ME1CROVG_t.png" alt="">
                            </div>
                            <div class="ticket-code">N197</div>
                            <div class="ticket-doc">DNI: 25833097</div>
                            <div class="ticket-specialty">TRAUMATOLOGIA</div>
                            <div class="ticket-date">2026-05-27</div>
                            <div class="ticket-icon">
                                <img class="ticket-svg-icon" src="assets/icons/ticket-normal.svg" alt="Ticket">
                                <span class="ticket-icon-fallback">TICKET</span>
                            </div>
                        </div>
                    </article>
                </section>
            </section>
        </main>

    </section>

    <div id="loadingOverlay" class="loading hidden">
        <div>
            <span class="spinner"></span>
            <strong>Procesando</strong>
            <small>Generando ticket...</small>
        </div>
    </div>

    <div id="printArea" aria-hidden="true"></div>
</body>
</html>


