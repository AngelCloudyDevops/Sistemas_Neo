<?php

declare(strict_types=1);

namespace TiNeo\Controllers;

use RuntimeException;
use TiNeo\Core\Request;
use TiNeo\Core\Response;
use TiNeo\Models\Specialty;
use TiNeo\Services\CitasDisponiblesService;
use TiNeo\Services\TicketService;

final class TicketController
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly Specialty $specialties,
        private readonly ?CitasDisponiblesService $citasDisponibles = null
    ) {
    }

    public function summary(): void
    {
        Response::success($this->tickets->summary());
    }

    public function specialties(): void
    {
        Response::success(['especialidades' => $this->specialties->active()]);
    }

    public function citasSigh(): void
    {
        if (!$this->citasDisponibles instanceof CitasDisponiblesService) {
            Response::error('La conexion con SIGH no esta configurada.', 503);
        }

        try {
            Response::success($this->citasDisponibles->disponiblesHoy());
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function emit(array $user, ?array $input = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            $ticket = $this->tickets->emit($input ?? Request::json(), $user);
            Response::success(['ticket' => $ticket], 'Ticket emitido.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function saveRange(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->tickets->saveRange(Request::json(), $user), 'Rango guardado.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function rangeCalendar(): void
    {
        try {
            Response::success($this->tickets->rangeCalendar([
                'anio' => Request::query('anio', date('Y')),
                'mes' => Request::query('mes', date('n')),
            ]));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function generateBatch(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->tickets->generateBatch(Request::json(), $user), 'Lote generado.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function pendingBatches(): void
    {
        try {
            Response::success(['lotes' => $this->tickets->pendingBatches([
                'fecha' => Request::query('fecha', ''),
            ])]);
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function batchForReprint(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->tickets->batchForReprint(Request::json(), $user));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function updateBatchProgress(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->tickets->updateBatchProgress(Request::json(), $user), 'Avance guardado.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function markBatchPrinted(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->tickets->markBatchPrinted(Request::json(), $user), 'Lote actualizado.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function history(): void
    {
        $filters = [
            'fecha' => Request::query('fecha', ''),
            'tipo_ticket' => Request::query('tipo_ticket', ''),
            'codigo' => Request::query('codigo', ''),
            'estado' => Request::query('estado', ''),
            'pagina' => Request::query('pagina', 1),
            'por_pagina' => Request::query('por_pagina', 25),
        ];

        try {
            Response::success($this->tickets->history($filters));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function dailyReprintSearch(): void
    {
        $filters = [
            'codigo' => Request::query('codigo', ''),
            'pagina' => 1,
            'por_pagina' => 10,
        ];

        try {
            Response::success($this->tickets->dailyReprintSearch($filters));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function report(): void
    {
        $filters = [
            'desde' => Request::query('desde', ''),
            'hasta' => Request::query('hasta', ''),
            'tipo_ticket' => Request::query('tipo_ticket', ''),
            'estado' => Request::query('estado', ''),
            'pagina' => Request::query('pagina', 1),
            'por_pagina' => Request::query('por_pagina', 25),
        ];

        try {
            Response::success($this->tickets->report($filters));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function queue(): void
    {
        $filters = [
            'fecha' => Request::query('fecha', date('Y-m-d')),
            'especialidad_id' => Request::query('especialidad_id', ''),
        ];

        try {
            Response::success($this->tickets->queue($filters));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function enqueue(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                ['ticket' => $this->tickets->enqueue(Request::json(), $user)],
                'Ticket enviado a cola.'
            );
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function callNext(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                ['ticket' => $this->tickets->callNext(Request::json(), $user)],
                'Ticket llamado.'
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'No hay tickets en cola para llamar.') {
                Response::success(['ticket' => null], 'No hay tickets en cola para llamar.');
            }

            Response::error($exception->getMessage(), 422);
        }
    }

    public function updateQueueState(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                ['ticket' => $this->tickets->updateQueueState(Request::json(), $user)],
                'Estado actualizado.'
            );
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function recall(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                ['ticket' => $this->tickets->recall(Request::json(), $user)],
                'Ticket re-llamado.'
            );
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function screen(): void
    {
        try {
            $screen = $this->tickets->screen([
                'fecha' => Request::query('fecha', date('Y-m-d')),
            ]);
            $etag = (string) ($screen['etag'] ?? '');
            $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

            header_remove('X-Powered-By');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache, must-revalidate');
            if ($etag !== '') {
                header('ETag: ' . $etag);
            }

            if ($etag !== '' && $clientEtag === $etag) {
                http_response_code(304);
                exit;
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Operacion realizada correctamente.',
                'data' => $screen['payload'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function myWindow(array $user): void
    {
        try {
            Response::success([
                'ventanilla' => $this->tickets->assignedWindowForUser((int) $user['id']),
            ]);
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function windowsAdmin(): void
    {
        try {
            Response::success($this->tickets->windowsAdmin());
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function assignWindow(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                $this->tickets->assignWindow(Request::json(), $user),
                'Ventanilla asignada.'
            );
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function releaseWindow(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                $this->tickets->releaseWindow(Request::json(), $user),
                'Ventanilla liberada.'
            );
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function updateWindowQueueMode(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success(
                $this->tickets->updateWindowQueueMode(Request::json(), $user),
                'Modo de cola actualizado.'
            );
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function reprint(array $user, string $scope = 'historial'): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            $input = Request::json();
            $ticket = $this->tickets->reprint((int) ($input['id'] ?? 0), $user, $scope);
            Response::success(['ticket' => $ticket], 'Ticket listo para reimprimir.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function cancel(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            $input = Request::json();
            $ticket = $this->tickets->cancel(
                (int) ($input['id'] ?? 0),
                (string) ($input['motivo'] ?? ''),
                $user
            );
            Response::success(['ticket' => $ticket], 'Ticket anulado.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function markPrinted(array $user, ?array $input = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            $input ??= Request::json();
            $ticket = $this->tickets->markPrinted(
                (int) ($input['id'] ?? 0),
                (string) ($input['codigo'] ?? ''),
                $user,
                (bool) ($input['solo_impresion'] ?? false)
            );
            Response::success(['ticket' => $ticket], 'Impresion confirmada.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function markPrintCanceled(array $user, ?array $input = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            $input ??= Request::json();
            $ticket = $this->tickets->markPrintCanceled(
                (int) ($input['id'] ?? 0),
                (string) ($input['codigo'] ?? ''),
                $user
            );
            Response::success(['ticket' => $ticket], 'Impresion cancelada.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function savePrintLayout(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->tickets->savePrintLayout(Request::json(), $user), 'Configuracion de impresion guardada.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }
}
