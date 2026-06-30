<?php

declare(strict_types=1);

namespace TiNeo\Controllers;

use TiNeo\Models\Portal;

final class PortalController
{
    public function __construct(private readonly Portal $portal, private readonly array $user)
    {
    }

    public function systems(): void
    {
        $systems = $this->portal->systemsForUser($this->user);
        $this->portal->logAccess(
            (int) ($this->user['id'] ?? 0),
            null,
            'LISTAR_SISTEMAS',
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        Response::success(['sistemas' => $systems], 'Sistemas disponibles.');
    }
}
