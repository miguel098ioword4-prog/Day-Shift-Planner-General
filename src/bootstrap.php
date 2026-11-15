<?php

declare(strict_types=1);

require_once __DIR__ . '/ShiftService.php';
require_once __DIR__ . '/ActivityService.php';

/**
 * Configuração da aplicação web: ambiente, sessão, cabeçalhos de segurança e
 * armazenamento. Só é carregado por index.php (os testes usam as classes direto).
 *
 * Variáveis de ambiente opcionais:
 *   SHIFTLY_STORAGE_DIR  diretório de dados (padrão: ./storage)
 *   SHIFTLY_TIMEZONE     fuso horário (padrão: America/Fortaleza)
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Shiftly requer PHP 8.1 ou superior.');
}

if (!extension_loaded('mbstring')) {
    http_response_code(500);
    exit('Shiftly requer a extensão mbstring.');
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function appTimezone(): DateTimeZone
{
    $name = getenv('SHIFTLY_TIMEZONE') ?: 'America/Fortaleza';

    return in_array($name, DateTimeZone::listIdentifiers(), true)
        ? new DateTimeZone($name)
        : new DateTimeZone('UTC');
}

function sendSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "style-src 'self' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; img-src 'self' data:; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
    );
}

function startSession(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function storageDirectory(): string
{
    $dir = getenv('SHIFTLY_STORAGE_DIR') ?: dirname(__DIR__) . '/storage';

    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new StorageException('Não foi possível criar o diretório de dados.');
    }

    // Defesa extra caso o diretório fique dentro da raiz pública (Apache).
    $htaccess = $dir . '/.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    return $dir;
}

function csrfIsValid(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
