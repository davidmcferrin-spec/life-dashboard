<?php

declare(strict_types=1);

namespace Dashboard\Share;

final class ShareDeniedResponse
{
    public static function html(): never
    {
        http_response_code(403);
        $pageTitle      = 'Access denied';
        $themePref      = 'system';
        $themeLocalOnly = true;
        require VIEWS_DIR . '/share/denied.php';
        exit;
    }

    public static function json(): never
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo '{}';
        exit;
    }
}
