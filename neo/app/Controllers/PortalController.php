<?php

declare(strict_types=1);

namespace Neo\Controllers;

use Neo\Core\Response;
use Neo\Models\Portal;
use Neo\Services\AuditService;

final class PortalController
{
    public function __construct(
        private readonly Portal $portal,
        private readonly AuditService $audit,
        private readonly array $user
    ) {
    }

    public function dashboard(): void
    {
        $systems = $this->portal->systemsForUser($this->user);
        $this->audit->log((int) $this->user['id'], 'PORTAL_SISTEMAS', 'Listado de sistemas disponibles.');

        Response::success([
            'user' => $this->user,
            'stats' => [
                'sistemas_asignados' => count($systems),
                'sistemas_admin' => count(array_filter($systems, static fn (array $system): bool => !empty($system['puede_admin']))),
            ],
            'systems' => $systems,
        ], 'Portal cargado.');
    }
}
