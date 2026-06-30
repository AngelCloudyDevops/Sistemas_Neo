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
    <title>Mini Turnero HSJ</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/assets/css/operador.css' : 'assets/css/operador.css', ENT_QUOTES, 'UTF-8') ?>?v=20260617-recall-lock">
    <script>
        window.TI_NEO_API_BASE = '<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/../api/v1' : '../api/v1', ENT_QUOTES, 'UTF-8') ?>';
    </script>
    <script src="<?= htmlspecialchars($baseUrl !== '' ? $baseUrl . '/assets/js/operador.js' : 'assets/js/operador.js', ENT_QUOTES, 'UTF-8') ?>?v=20260617-recall-lock" defer></script>
</head>
<body>
    <main class="operator-shell">
        <header class="operator-head">
            <span class="operator-avatar" id="operatorAvatar">TU</span>
            <div>
                <b>Perfil activo</b>
                <span id="operatorSession">Verificando sesion...</span>
                <small id="operatorRole">Equipo de atencion</small>
            </div>
            <button type="button" id="operatorRefreshButton" title="Actualizar">!</button>
        </header>

        <section class="window-card">
            <div>
                <span>Ventanilla</span>
                <strong id="operatorWindowTitle">Sin asignar</strong>
                <small id="operatorWindowMode">Selecciona ventanilla para iniciar</small>
            </div>
            <button type="button" id="operatorChangeWindowButton">Cambiar</button>
        </section>

        <section class="window-picker" id="operatorWindowPicker">
            <button type="button" data-window="1">V1</button>
            <button type="button" data-window="2">V2</button>
            <button type="button" data-window="3">V3</button>
            <button type="button" data-window="4">V4</button>
            <button type="button" data-window="5">V5</button>
            <button type="button" data-window="6">V6</button>
            <button type="button" data-window="7">V7</button>
            <button type="button" data-window="8">V8</button>
            <button type="button" data-window="9">V9</button>
        </section>

        <section class="current-card">
            <span>Turno actual</span>
            <strong id="operatorCurrentCode">---</strong>
            <small id="operatorCurrentDetail">Sin turno asignado</small>
            <div class="related-tickets" id="operatorRelatedTickets" hidden></div>
        </section>

        <section class="operator-actions">
            <button type="button" id="operatorCallButton">Llamar siguiente</button>
            <button type="button" id="operatorAttendButton">Atender</button>
            <button type="button" class="light" id="operatorRecallButton">Re-llamar</button>
            <button type="button" id="operatorFinishButton">Finalizar</button>
            <button type="button" class="danger" id="operatorAbsentButton">Ausente</button>
            <button type="button" class="light" id="operatorPauseAfterButton">Pausar al finalizar</button>
            <button type="button" class="light" id="operatorResumeButton">Reanudar</button>
        </section>

        <p class="operator-message" id="operatorMessage"></p>
    </main>
</body>
</html>
