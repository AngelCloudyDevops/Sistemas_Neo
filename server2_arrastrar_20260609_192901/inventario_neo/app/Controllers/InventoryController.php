<?php

declare(strict_types=1);

namespace InventarioNeo\Controllers;

use InventarioNeo\Core\Auth;
use InventarioNeo\Core\Request;
use InventarioNeo\Core\Response;
use InventarioNeo\Models\Inventory;
use InventarioNeo\Services\AuditService;

final class InventoryController
{
    public function __construct(
        private readonly Inventory $inventory,
        private readonly Auth $auth,
        private readonly AuditService $audit
    ) {
    }

    public function handle(): void
    {
        $action = (string) ($_GET['action'] ?? 'summary');

        try {
            match ($action) {
                'summary' => $this->summary(),
                'personal' => $this->listPersonal(),
                'equipos' => $this->listEquipos(),
                'impresoras' => $this->listImpresoras(),
                'catalogs' => $this->catalogs(),
                'personal_search' => $this->personalSearch(),
                'save_personal' => $this->savePersonal(),
                'save_equipo' => $this->saveEquipo(),
                'save_impresora' => $this->saveImpresora(),
                default => Response::error('Accion no encontrada.', 404),
            };
        } catch (\InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('No se pudo completar la operacion.', 500);
        }
    }

    private function summary(): void
    {
        $this->auth->requirePolicy('sistema.entrar');
        Response::success($this->inventory->summary());
    }

    private function listPersonal(): void
    {
        $this->auth->requireAnyPolicy(['inventario.personal.ver', 'inventario.personal.editar']);
        Response::success($this->inventory->listPersonal($this->search(), $this->page(), $this->perPage()));
    }

    private function listEquipos(): void
    {
        $this->auth->requireAnyPolicy(['inventario.equipos.ver', 'inventario.equipos.editar']);
        Response::success($this->inventory->listEquipos($this->search(), $this->page(), $this->perPage()));
    }

    private function listImpresoras(): void
    {
        $this->auth->requireAnyPolicy(['inventario.impresoras.ver', 'inventario.impresoras.editar']);
        Response::success($this->inventory->listImpresoras($this->search(), $this->page(), $this->perPage()));
    }

    private function catalogs(): void
    {
        $this->auth->requirePolicy('sistema.entrar');
        Response::success($this->inventory->catalogs());
    }

    private function personalSearch(): void
    {
        $this->auth->requireAnyPolicy(['inventario.equipos.ver', 'inventario.equipos.editar']);
        Response::success(['rows' => $this->inventory->searchPersonal($this->search())]);
    }

    private function savePersonal(): void
    {
        $this->requirePost();
        $user = $this->auth->requirePolicy('inventario.personal.editar');
        $id = $this->inventory->savePersonal(Request::json());
        $this->audit->log((int) $user['id'], 'GUARDAR_PERSONAL', 'Personal guardado desde inventario_neo. ID: ' . $id);
        Response::success(['id' => $id], 'Personal guardado.');
    }

    private function saveEquipo(): void
    {
        $this->requirePost();
        $user = $this->auth->requirePolicy('inventario.equipos.editar');
        $id = $this->inventory->saveEquipo(Request::json());
        $this->audit->log((int) $user['id'], 'GUARDAR_EQUIPO', 'Equipo guardado desde inventario_neo. ID: ' . $id);
        Response::success(['id' => $id], 'Equipo guardado.');
    }

    private function saveImpresora(): void
    {
        $this->requirePost();
        $user = $this->auth->requirePolicy('inventario.impresoras.editar');
        $id = $this->inventory->saveImpresora(Request::json());
        $this->audit->log((int) $user['id'], 'GUARDAR_IMPRESORA', 'Impresora guardada desde inventario_neo. ID: ' . $id);
        Response::success(['id' => $id], 'Impresora guardada.');
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }
    }

    private function search(): string
    {
        return trim((string) ($_GET['search'] ?? ''));
    }

    private function page(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

    private function perPage(): int
    {
        return max(10, min(80, (int) ($_GET['per_page'] ?? 20)));
    }
}
