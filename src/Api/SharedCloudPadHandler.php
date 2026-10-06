<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\CloudPad\CloudPadAccess;
use Dashboard\CloudPad\CloudPadService;
use Dashboard\CloudPad\CloudPadShareService;
use Dashboard\DB\Database;
use Dashboard\Share\ShareDeniedResponse;

final class SharedCloudPadHandler
{
    private CloudPadService $cloudpad;
    private CloudPadShareService $shares;

    public function __construct(Database $db)
    {
        $this->cloudpad = CloudPadService::fromDatabase($db);
        $this->shares   = new CloudPadShareService($db);
    }

    /**
     * @param array<string, string> $params
     */
    public function get(array $params): void
    {
        $token  = (string) ($params['token'] ?? '');
        $access = $this->cloudpad->resolveLinkAccess($token);

        if ($access === null) {
            ShareDeniedResponse::json();
        }

        $sinceVersion = isset($_GET['since_version']) ? (int) $_GET['since_version'] : null;
        $canvas       = $this->cloudpad->getCanvasForAccess($access, 0, $sinceVersion);

        $this->shares->touchLinkByToken($token);

        jsonResponse([
            'canvas'     => $canvas,
            'permission' => CloudPadAccess::PERM_READ,
            'can_write'  => false,
        ]);
    }
}
