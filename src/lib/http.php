<?php
declare(strict_types=1);

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    // Security headers to mitigate XSS and clickjacking (audit #16)
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
