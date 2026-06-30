<?php

declare(strict_types=1);

namespace GsNeo\Controllers;

use GsNeo\Core\Auth;
use GsNeo\Core\Request;
use GsNeo\Core\Response;
use GsNeo\Models\UserAccount;
use GsNeo\Services\AuditService;

final class UserController
{
    public function __construct(
        private readonly UserAccount $users,
        private readonly Auth $auth,
        private readonly AuditService $audit
    ) {
    }

    public function handle(): void
    {
        $action = (string) Request::query('action', 'list');
        $readActions = ['summary', 'catalogs', 'personal', 'list'];
        $currentUser = in_array($action, $readActions, true)
            ? $this->auth->requirePolicy('usuarios.ver', 'sistema:gs')
            : $this->auth->requireSuperAdmin();

        try {
            match ($action) {
                'summary' => $this->summary((int) $currentUser['id']),
                'catalogs' => Response::success($this->users->catalogs()),
                'audit' => Response::success(['logs' => $this->users->auditLogs()]),
                'personal' => $this->personal(),
                'create' => $this->create((int) $currentUser['id']),
                'deactivate' => $this->deactivate((int) $currentUser['id']),
                'activate' => $this->activate((int) $currentUser['id']),
                'retire' => $this->retire((int) $currentUser['id']),
                'groups' => $this->groups((int) $currentUser['id']),
                'groups_overview' => Response::success(['grupos' => $this->users->groupsOverview()]),
                'group_members' => $this->groupMembers(),
                'group_search_users' => $this->groupSearchUsers(),
                'group_add_user' => $this->groupAddUser((int) $currentUser['id']),
                'group_remove_user' => $this->groupRemoveUser((int) $currentUser['id']),
                'password_reset_requests' => Response::success(['solicitudes' => $this->users->passwordResetRequests()]),
                'approve_password_reset' => $this->approvePasswordReset((int) $currentUser['id']),
                'notifications' => Response::success($this->users->notifications()),
                default => $this->list(),
            };
        } catch (\InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('No se pudo completar la operacion.', 500);
        }
    }

    private function summary(int $userId): void
    {
        $summary = $this->users->userAccessSummary($userId);
        $summary['permisos'] = [
            'admin' => $this->auth->canPolicy('sistema.gs.admin', 'sistema:gs'),
            'usuarios_ver' => $this->auth->canPolicy('usuarios.ver', 'sistema:gs'),
        ];

        Response::success($summary);
    }

    private function list(): void
    {
        $search = (string) Request::query('search', '');
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        Response::success($this->users->list($search, $page, $perPage));
    }

    private function personal(): void
    {
        $search = (string) Request::query('search', '');
        if (strlen($search) < 2) {
            Response::success(['personal' => []]);
        }

        Response::success(['personal' => $this->users->searchPersonal($search)]);
    }

    private function create(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $id = $this->users->create(Request::json());
        $this->audit->log($adminId, 'CREAR_USUARIO', 'Usuario creado desde gs_neo. ID: ' . $id);

        Response::success(['id' => $id], 'Usuario creado.', 201);
    }

    private function deactivate(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Usuario invalido.', 422);
        }

        $this->users->deactivate($id);
        $this->audit->log($adminId, 'DESACTIVAR_USUARIO', 'Usuario desactivado desde gs_neo. ID: ' . $id);

        Response::success([], 'Usuario desactivado.');
    }

    private function activate(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Usuario invalido.', 422);
        }

        $this->users->activate($id);
        $this->audit->log($adminId, 'ACTIVAR_USUARIO', 'Usuario activado desde gs_neo. ID: ' . $id);

        Response::success([], 'Usuario activado.');
    }

    private function retire(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Usuario invalido.', 422);
        }
        if ($id === $adminId) {
            Response::error('No puedes retirar tu propio usuario en esta sesion.', 422);
        }

        $this->users->retire($id);
        $this->audit->log($adminId, 'RETIRAR_USUARIO', 'Usuario retirado y correo liberado desde gs_neo. ID: ' . $id);

        Response::success([], 'Usuario retirado.');
    }

    private function groups(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Usuario invalido.', 422);
        }

        $this->users->updateGroups($id, $input['grupo_ids'] ?? [], $input['politica_ids'] ?? []);
        $this->audit->log($adminId, 'ACTUALIZAR_ACCESOS_USUARIO', 'Grupos y permisos directos actualizados desde gs_neo. ID: ' . $id);

        Response::success([], 'Accesos actualizados.');
    }

    private function groupMembers(): void
    {
        $groupId = (int) Request::query('group_id', 0);
        if ($groupId <= 0) {
            Response::error('Grupo invalido.', 422);
        }

        Response::success(['usuarios' => $this->users->groupMembers($groupId)]);
    }

    private function groupSearchUsers(): void
    {
        $search = (string) Request::query('search', '');
        if (strlen($search) < 2) {
            Response::success(['usuarios' => []]);
        }

        Response::success(['usuarios' => $this->users->searchActiveUsers($search, 8)]);
    }

    private function groupAddUser(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $groupId = (int) ($input['group_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        if ($groupId <= 0 || $userId <= 0) {
            Response::error('Grupo o usuario invalido.', 422);
        }

        $this->users->addUserToGroup($userId, $groupId);
        $this->audit->log($adminId, 'AGREGAR_USUARIO_GRUPO', "Usuario {$userId} agregado al grupo {$groupId}.");

        Response::success([], 'Usuario agregado al grupo.');
    }

    private function groupRemoveUser(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $groupId = (int) ($input['group_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        if ($groupId <= 0 || $userId <= 0) {
            Response::error('Grupo o usuario invalido.', 422);
        }

        $this->users->removeUserFromGroup($userId, $groupId);
        $this->audit->log($adminId, 'QUITAR_USUARIO_GRUPO', "Usuario {$userId} retirado del grupo {$groupId}.");

        Response::success([], 'Usuario retirado del grupo.');
    }

    private function approvePasswordReset(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Solicitud invalida.', 422);
        }

        $this->users->approvePasswordReset($id, $adminId);
        $this->audit->log($adminId, 'APROBAR_REINICIO_PASSWORD', 'Solicitud de reinicio aprobada. ID: ' . $id);
        Response::success([], 'Reinicio aprobado.');
    }
}
