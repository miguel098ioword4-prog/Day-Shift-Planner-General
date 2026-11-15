<?php

declare(strict_types=1);

/**
 * Testes de integração: sobe `php -S` de verdade, com armazenamento temporário,
 * e conversa com a aplicação por HTTP (sessão e CSRF incluídos).
 */

require_once __DIR__ . '/TestCase.php';

if (!extension_loaded('curl')) {
    fwrite(STDERR, "ext-curl ausente: testes HTTP ignorados\n");

    return;
}

final class Client
{
    private string $jar;

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @param array<string, mixed>|null $post @return array{status: int, headers: string, body: string} */
    public function request(string $path, ?array $post = null): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 10,
        ]);

        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $raw = (string) curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }

    public function csrf(): string
    {
        $page = $this->request('/index.php');
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $m);

        return $m[1] ?? '';
    }

    /** @param array<string, mixed> $fields @return array{status: int, headers: string, body: string} */
    public function post(array $fields): array
    {
        return $this->request('/index.php', ['csrf_token' => $this->csrf()] + $fields);
    }
}

function startServer(string $storage, string $tz): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $storage . '/server.log', 'w']],
        $pipes,
        null,
        ['SHIFTLY_STORAGE_DIR' => $storage . '/data', 'SHIFTLY_TIMEZONE' => $tz, 'PATH' => getenv('PATH') ?: '']
    );

    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket !== false) {
            fclose($socket);

            return [$process, 'http://127.0.0.1:' . $port];
        }
        usleep(50_000);
    }

    throw new RuntimeException('servidor de teste não subiu');
}

$storage = T::tempDir();
$tz = 'America/Fortaleza';
[$server, $base] = startServer($storage, $tz);
$todayKey = (new DateTimeImmutable('today', new DateTimeZone($tz)))->format('Y-m-d');
$web = new Client($base);

T::test('http: página inicial carrega com cabeçalhos de segurança', function () use ($web): void {
    $r = $web->request('/index.php');
    T::same(200, $r['status']);
    T::true(str_contains($r['body'], 'Sua constância'));
    foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Content-Security-Policy:'] as $h) {
        T::true(stripos($r['headers'], $h) !== false, $h);
    }
    T::true(stripos($r['headers'], 'HttpOnly') !== false, 'cookie HttpOnly');
    T::true(stripos($r['headers'], 'SameSite=Lax') !== false, 'cookie SameSite');
});

T::test('http: diretório de dados é protegido', function () use ($storage): void {
    T::true(is_file($storage . '/data/.htaccess'));
});

T::test('http: POST sem token CSRF é recusado e nada é criado', function () use ($web, $storage): void {
    $r = $web->request('/index.php', ['action' => 'add', 'shift_name' => 'X', 'shift_time' => '08:00']);
    T::same(403, $r['status']);
    T::true(!is_file($storage . '/data/shifts.json') || !str_contains((string) file_get_contents($storage . '/data/shifts.json'), '"X"'));
});

T::test('http: token CSRF errado é recusado', function () use ($web): void {
    $r = $web->request('/index.php', ['csrf_token' => str_repeat('0', 64), 'action' => 'add', 'shift_name' => 'X', 'shift_time' => '08:00']);
    T::same(403, $r['status']);
});

T::test('http: adiciona turno e mostra na lista (PRG com 303)', function () use ($web): void {
    $r = $web->post(['action' => 'add', 'shift_name' => 'Turno da manhã', 'shift_time' => '08:00']);
    T::same(303, $r['status']);
    $page = $web->request('/index.php?success=added');
    T::true(str_contains($page['body'], 'Turno da manhã'));
    T::true(str_contains($page['body'], 'Turno adicionado com sucesso'));
});

T::test('http: HTML do nome é escapado (XSS)', function () use ($web): void {
    $web->post(['action' => 'add', 'shift_name' => '<script>alert(1)</script>', 'shift_time' => '09:00']);
    $body = $web->request('/index.php')['body'];
    T::true(!str_contains($body, '<script>alert(1)</script>'));
    T::true(str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'));
});

T::test('http: entrada inválida dá 422 e preserva o que foi digitado', function () use ($web): void {
    $r = $web->post(['action' => 'add', 'shift_name' => 'Sem horário', 'shift_time' => '25:99']);
    T::same(422, $r['status']);
    T::true(str_contains($r['body'], 'Informe um horário válido'));
    T::true(str_contains($r['body'], 'value="Sem horário"'));
});

T::test('http: parâmetros em formato de array não derrubam a página (era erro 500)', function () use ($web): void {
    $r = $web->post(['action' => 'add', 'shift_name' => ['a'], 'shift_time' => ['b']]);
    T::same(422, $r['status']);
    $r = $web->post(['action' => ['x']]);
    T::same(422, $r['status']);
    $r = $web->post(['action' => 'delete', 'shift_id' => ['x']]);
    T::same(422, $r['status']);
});

T::test('http: ação desconhecida é recusada', function () use ($web): void {
    T::same(422, $web->post(['action' => 'hack'])['status']);
});

T::test('http: concluir turno pinta o dia de hoje e atualiza as estatísticas', function () use ($web, $todayKey): void {
    $body = $web->request('/index.php')['body'];
    preg_match('/name="action" value="complete">\s*<input type="hidden" name="shift_id" value="([a-f0-9]{16})"/', $body, $m);
    T::true(isset($m[1]), 'id do turno');

    $r = $web->post(['action' => 'complete', 'shift_id' => $m[1]]);
    T::same(303, $r['status']);

    $page = $web->request('/index.php?success=done')['body'];
    T::true(str_contains($page, 'lv1 is-today'), 'célula de hoje verde');
    T::true(str_contains($page, 'title="1 turno concluído em ' . implode('/', array_reverse(explode('-', $todayKey))) . '"'));
    T::true(str_contains($page, '✓ Feito'));
    T::true(str_contains($page, '<dd>1 de 3</dd>') || str_contains($page, '<dd>1 de 2</dd>'));
    T::true(str_contains($page, '<dd>1 dia</dd>'), 'sequência');

    $web->post(['action' => 'complete', 'shift_id' => $m[1]]); // idempotente
    T::true(str_contains($web->request('/index.php')['body'], 'lv1 is-today'));

    $web->post(['action' => 'undo', 'shift_id' => $m[1]]);
    $page = $web->request('/index.php')['body'];
    T::true(!str_contains($page, 'lv1 is-today'));
    T::true(str_contains($page, 'lv0 is-today'));
});

T::test('http: concluir turno inexistente é recusado', function () use ($web): void {
    $r = $web->post(['action' => 'complete', 'shift_id' => '0123456789abcdef']);
    T::same(422, $r['status']);
});

T::test('http: excluir turno concluído mantém o histórico no calendário', function () use ($web): void {
    $body = $web->request('/index.php')['body'];
    preg_match('/name="action" value="complete">\s*<input type="hidden" name="shift_id" value="([a-f0-9]{16})"/', $body, $m);
    $web->post(['action' => 'complete', 'shift_id' => $m[1]]);
    $r = $web->post(['action' => 'delete', 'shift_id' => $m[1]]);
    T::same(303, $r['status']);
    $page = $web->request('/index.php')['body'];
    T::true(str_contains($page, 'lv1 is-today'), 'histórico preservado');
    T::true(!str_contains($page, 'value="' . $m[1] . '"'));
});

T::test('http: mensagem de sucesso desconhecida na URL é ignorada', function () use ($web): void {
    $body = $web->request('/index.php?success=<b>oi</b>')['body'];
    T::true(!str_contains($body, '<b>oi</b>'));
    T::true(!str_contains($body, 'message success'));
});

T::test('http: arquivo de dados corrompido dá erro genérico sem vazar caminho', function () use ($storage): void {
    mkdir($storage . '/bad', 0750, true);
    $server2 = startServer($storage . '/bad', 'UTC');
    $client = new Client($server2[1]);
    @mkdir($storage . '/bad/data', 0750, true);
    file_put_contents($storage . '/bad/data/shifts.json', '{quebrado');
    $r = $client->request('/index.php');
    proc_terminate($server2[0]);
    proc_close($server2[0]);
    T::same(500, $r['status']);
    T::true(str_contains($r['body'], 'Ocorreu um erro'));
    T::true(!str_contains($r['body'], 'quebrado') && !str_contains($r['body'], $storage));
    T::same('{quebrado', file_get_contents($storage . '/bad/data/shifts.json'), 'arquivo preservado');
});

proc_terminate($server);
proc_close($server);
