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
$apiUrl = $requestHost !== '' ? $scheme . '://' . $requestHost . '/ti_neo/api/v1/pantalla_publica.php' : '../api/v1/pantalla_publica.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pantalla Tickets HSJ</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/assets/css/app.css' : 'assets/css/app.css', ENT_QUOTES, 'UTF-8') ?>?v=20260623-nine-windows">
    <script>
        window.TI_NEO_PUBLIC_SCREEN_API = '<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>';
    </script>
    <script src="<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/assets/js/pantalla-publica.js' : 'assets/js/pantalla-publica.js', ENT_QUOTES, 'UTF-8') ?>?v=20260623-nine-windows" defer></script>
</head>
<body class="screen-kiosk">
    <section class="app" id="appShell">
        <main class="content">
            <section class="view active" data-view-panel="pantalla">
                <div class="page-head">
                    <div>
                        <h1>Pantalla de llamados</h1>
                        <p id="screenOperationalDate">Monitor del dia.</p>
                    </div>
                </div>
                <section class="screen-board" id="turneroScreen">
                    <article class="screen-media-panel">
                        <video class="screen-media-video" autoplay muted loop playsinline preload="metadata">
                            <source src="assets/videos/sala.mp4" type="video/mp4">
                        </video>
                        <div class="screen-media-fallback">
                            <strong>Hospital San José del Callao</strong>
                            <span>Video institucional</span>
                        </div>
                        <div class="screen-flow-guide" aria-label="Pasos para atención">
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
                                <svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="Acércate a ventanilla">
                                    <rect x="43" y="11" width="62" height="50" rx="6" fill="#eff6ff" stroke="#2563eb" stroke-width="4"/>
                                    <rect x="49" y="18" width="50" height="15" rx="3" fill="#bfdbfe"/>
                                    <path d="M48 48h52" stroke="#2563eb" stroke-width="4" stroke-linecap="round"/>
                                    <circle cx="26" cy="36" r="10" fill="#f8c6a6"/>
                                    <path d="M12 80c4-23 25-23 31 0z" fill="#2563eb"/>
                                    <rect x="35" y="48" width="20" height="10" rx="3" fill="#fff" stroke="#94a3b8" stroke-width="2"/>
                                    <rect x="55" y="58" width="24" height="13" rx="3" fill="#fff7ed" stroke="#f59e0b" stroke-width="2"/>
                                </svg>
                                <strong>Acércate a ventanilla</strong>
                                <small>Lleva ticket y DNI.</small>
                            </article>
                        </div>
                    </article>
                    <article class="panel screen-recent-panel">
                        <h2>Turno / Ventanilla</h2>
                        <div id="screenRecentList" class="screen-recent-list"></div>
                    </article>
                    <button id="turneroAudioButton" class="screen-audio-button" type="button">Activar audio</button>
                </section>
                <section class="appointments-screen" id="appointmentsScreen" hidden>
                    <header class="appointments-head">
                        <strong id="appointmentsTitle">Citas del mes</strong>
                    </header>
                    <section class="appointments-grid">
                        <article class="appointments-shift">
                            <h2>Turno Mañana</h2>
                            <div class="appointments-table-head">
                                <span>Especialidad</span>
                                <span>Médico</span>
                                <span>Cupos</span>
                            </div>
                            <div id="appointmentsMorningList" class="appointments-list"></div>
                        </article>
                        <article class="appointments-shift">
                            <h2>Turno Tarde</h2>
                            <div class="appointments-table-head">
                                <span>Especialidad</span>
                                <span>Médico</span>
                                <span>Cupos</span>
                            </div>
                            <div id="appointmentsAfternoonList" class="appointments-list"></div>
                        </article>
                    </section>
                    <div id="appointmentsFlowGuide" class="screen-flow-guide appointments-flow-guide" aria-label="Pasos para atención"></div>
                    <button id="screenAudioButton" class="screen-audio-button" type="button">Activar audio</button>
                </section>
                <section class="media-screen" id="mediaScreen" hidden>
                    <img id="mediaScreenImage" alt="Contenido de pantalla">
                    <div class="media-screen-empty" id="mediaScreenEmpty" hidden>Configura una URL de imagen para esta pantalla.</div>
                </section>
                <div class="screen-ticker">
                    <span id="screenTickerText">Hospital San José del Callao - Mantenga su DNI a la mano y acérquese cuando vea su turno en pantalla.</span>
                </div>
            </section>
        </main>
    </section>
</body>
</html>
