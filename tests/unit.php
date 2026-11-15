<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../src/ShiftService.php';
require_once __DIR__ . '/../src/ActivityService.php';

function shifts(?string &$dir = null): ShiftService
{
    $dir = T::tempDir();

    return new ShiftService(new JsonStore($dir . '/shifts.json'));
}

function day(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

// -- JsonStore -----------------------------------------------------------------

T::test('store: arquivo ausente ou vazio é lista vazia', function (): void {
    $dir = T::tempDir();
    $store = new JsonStore($dir . '/x.json');
    T::same([], $store->read());
    file_put_contents($dir . '/x.json', "  \n");
    T::same([], $store->read());
});

T::test('store: update grava e relê', function (): void {
    $dir = T::tempDir();
    $store = new JsonStore($dir . '/x.json');
    $store->update(fn (array $d): array => [...$d, 'a']);
    $store->update(fn (array $d): array => [...$d, 'b']);
    T::same(['a', 'b'], $store->read());
});

T::test('store: arquivo corrompido nunca é sobrescrito', function (): void {
    $dir = T::tempDir();
    file_put_contents($dir . '/x.json', '{quebrado');
    $store = new JsonStore($dir . '/x.json');
    T::throws(StorageException::class, fn () => $store->read());
    T::throws(StorageException::class, fn () => $store->update(fn (array $d): array => ['novo']));
    T::same('{quebrado', file_get_contents($dir . '/x.json'));
});

T::test('store: JSON que não é lista/objeto é rejeitado', function (): void {
    $dir = T::tempDir();
    file_put_contents($dir . '/x.json', '"texto"');
    T::throws(StorageException::class, fn () => (new JsonStore($dir . '/x.json'))->read());
});

T::test('store: exceção no mutator não grava nada', function (): void {
    $dir = T::tempDir();
    $store = new JsonStore($dir . '/x.json');
    $store->update(fn (array $d): array => ['ok']);
    T::throws(RuntimeException::class, fn () => $store->update(function (array $d): array {
        throw new RuntimeException('falhou');
    }));
    T::same(['ok'], $store->read());
});

T::test('store: não deixa arquivos temporários', function (): void {
    $dir = T::tempDir();
    $store = new JsonStore($dir . '/x.json');
    for ($i = 0; $i < 5; $i++) {
        $store->update(fn (array $d): array => [...$d, $i]);
    }
    T::same([], glob($dir . '/*.tmp'));
});

T::test('store: arquivo gravado com permissão restrita', function (): void {
    $dir = T::tempDir();
    (new JsonStore($dir . '/x.json'))->update(fn (array $d): array => ['a']);
    T::same('0640', substr(sprintf('%o', fileperms($dir . '/x.json')), -4));
});

T::test('store: processos simultâneos não perdem alterações', function (): void {
    $dir = T::tempDir();
    $worker = $dir . '/worker.php';
    file_put_contents($worker, '<?php require ' . var_export(__DIR__ . '/../src/JsonStore.php', true) . ';'
        . '$s = new JsonStore($argv[1]);'
        . 'for ($i = 0; $i < 25; $i++) { $s->update(fn (array $d): array => [...$d, $argv[2] . $i]); }');
    $procs = [];
    foreach (['a', 'b', 'c', 'd'] as $tag) {
        $procs[] = proc_open([PHP_BINARY, $worker, $dir . '/x.json', $tag], [], $pipes);
    }
    foreach ($procs as $p) {
        proc_close($p);
    }
    unlink($worker);
    T::same(100, count((new JsonStore($dir . '/x.json'))->read()));
});

// -- ShiftService --------------------------------------------------------------

T::test('turnos: adiciona, ordena por horário e persiste', function (): void {
    $svc = shifts();
    $svc->add('Tarde', '14:00');
    $svc->add('Manhã', '08:30');
    $svc->add('Noite', '22:15');
    T::same(['08:30', '14:00', '22:15'], array_column($svc->all(), 'time'));
    T::same(1, preg_match('/^[a-f0-9]{16}$/', $svc->all()[0]['id']));
});

T::test('turnos: nome é limpo (trim e espaços repetidos)', function (): void {
    T::same('Turno da manhã', ShiftService::validateName("  Turno   da\tmanhã  "));
});

T::test('turnos: nome inválido é recusado', function (): void {
    foreach ([null, [], ['a'], 123, '', '   ', str_repeat('a', 81), "a\x00b", "a\u{202E}b", "\xFF\xFE"] as $bad) {
        T::throws(ValidationException::class, fn () => ShiftService::validateName($bad));
    }
});

T::test('turnos: 80 caracteres multibyte é aceito, 81 não', function (): void {
    T::same(80, mb_strlen(ShiftService::validateName(str_repeat('é', 80))));
    T::throws(ValidationException::class, fn () => ShiftService::validateName(str_repeat('é', 81)));
});

T::test('turnos: horário inválido é recusado', function (): void {
    foreach ([null, [], '', '24:00', '7:30', '12:60', '12:5', '12:30:00', "12:30\n", 'ab:cd', 1230] as $bad) {
        T::throws(ValidationException::class, fn () => ShiftService::validateTime($bad), 'horário');
    }
    foreach (['00:00', '23:59', '09:05'] as $ok) {
        T::same($ok, ShiftService::validateTime($ok));
    }
});

T::test('turnos: duplicado (nome sem diferenciar maiúsculas + horário) é recusado', function (): void {
    $svc = shifts();
    $svc->add('Manhã', '08:00');
    T::throws(ValidationException::class, fn () => $svc->add('MANHÃ', '08:00'), 'Já existe');
    $svc->add('Manhã', '09:00');
    T::same(2, count($svc->all()));
});

T::test('turnos: limite máximo', function (): void {
    $svc = shifts();
    for ($i = 0; $i < ShiftService::MAX_SHIFTS; $i++) {
        $svc->add('Turno ' . $i, sprintf('%02d:%02d', intdiv($i, 60), $i % 60));
    }
    T::throws(ValidationException::class, fn () => $svc->add('Extra', '23:00'), 'Limite');
});

T::test('turnos: exclui, e id inválido ou inexistente é recusado', function (): void {
    $svc = shifts();
    $a = $svc->add('A', '08:00');
    $b = $svc->add('B', '09:00');
    $svc->delete($a['id']);
    T::same([$b['id']], array_column($svc->all(), 'id'));
    T::throws(ValidationException::class, fn () => $svc->delete($a['id']), 'não foi encontrado');
    foreach ([null, [], '', 'abc', '../../etc', str_repeat('g', 16)] as $bad) {
        T::throws(ValidationException::class, fn () => $svc->delete($bad));
    }
    T::same(1, count($svc->all()));
});

T::test('turnos: exists', function (): void {
    $svc = shifts();
    $a = $svc->add('A', '08:00');
    T::true($svc->exists($a['id']));
    T::same(false, $svc->exists('0000000000000000'));
    T::same(false, $svc->exists(['x']));
});

T::test('turnos: registros malformados no arquivo são ignorados', function (): void {
    $dir = T::tempDir();
    $good = ['id' => 'aaaaaaaaaaaaaaaa', 'name' => 'Ok', 'time' => '10:00', 'created_at' => 'x'];
    file_put_contents($dir . '/shifts.json', json_encode([
        $good,
        'texto',
        ['id' => 'curto', 'name' => 'x', 'time' => '10:00'],
        ['id' => 'bbbbbbbbbbbbbbbb', 'name' => 'x', 'time' => '99:99'],
        ['id' => 'cccccccccccccccc', 'name' => ['x'], 'time' => '10:00'],
        $good,
    ]));
    $svc = new ShiftService(new JsonStore($dir . '/shifts.json'));
    T::same(['aaaaaaaaaaaaaaaa'], array_column($svc->all(), 'id'));
});

T::test('turnos: mesma ordem para o mesmo horário (estável por nome)', function (): void {
    $svc = shifts();
    $svc->add('Zeta', '08:00');
    $svc->add('Alfa', '08:00');
    T::same(['Alfa', 'Zeta'], array_column($svc->all(), 'name'));
});

// -- ActivityService -----------------------------------------------------------

function activity(): ActivityService
{
    return new ActivityService(new JsonStore(T::tempDir() . '/activity.json'));
}

T::test('atividade: concluir é idempotente e desfazer limpa o dia', function (): void {
    $a = activity();
    $d = day('2026-11-15');
    $a->setDone('aaaaaaaaaaaaaaaa', $d, true);
    $a->setDone('aaaaaaaaaaaaaaaa', $d, true);
    $a->setDone('bbbbbbbbbbbbbbbb', $d, true);
    T::same(['2026-11-15' => 2], $a->counts());
    $a->setDone('aaaaaaaaaaaaaaaa', $d, false);
    T::same(['bbbbbbbbbbbbbbbb'], $a->doneOn($d));
    $a->setDone('bbbbbbbbbbbbbbbb', $d, false);
    T::same([], $a->counts());
});

T::test('atividade: ids só com dígitos continuam strings', function (): void {
    $a = activity();
    $a->setDone('1234567890123456', day('2026-11-15'), true);
    T::same(['1234567890123456'], $a->doneOn(day('2026-11-15')));
});

T::test('atividade: id inválido é recusado', function (): void {
    T::throws(ValidationException::class, fn () => activity()->setDone('x', day('2026-11-15'), true));
});

T::test('atividade: dias diferentes ficam separados', function (): void {
    $a = activity();
    $a->setDone('aaaaaaaaaaaaaaaa', day('2026-11-14'), true);
    $a->setDone('aaaaaaaaaaaaaaaa', day('2026-11-15'), true);
    T::same(['2026-11-14' => 1, '2026-11-15' => 1], $a->counts());
});

T::test('atividade: arquivo com lixo é filtrado', function (): void {
    $dir = T::tempDir();
    file_put_contents($dir . '/a.json', json_encode([
        '2026-02-31' => ['aaaaaaaaaaaaaaaa'],
        'ontem' => ['aaaaaaaaaaaaaaaa'],
        '2026-11-15' => ['aaaaaaaaaaaaaaaa', 'curto', 5, 'aaaaaaaaaaaaaaaa'],
        '2026-11-14' => 'x',
    ]));
    T::same(['2026-11-15' => 1], (new ActivityService(new JsonStore($dir . '/a.json')))->counts());
});

T::test('grade: 53 colunas x 7 dias, domingo primeiro, termina hoje', function (): void {
    $today = day('2026-11-15'); // domingo
    $grid = ActivityService::grid(['2026-11-15' => 2, '2026-11-14' => 1], $today);
    T::same(53, count($grid));
    foreach ($grid as $week) {
        T::same(7, count($week));
    }
    T::same('2026-11-15', $grid[52][0]['date']);
    T::same(2, $grid[52][0]['level']);
    T::same(null, $grid[52][1], 'amanhã é futuro');
    T::same('2026-11-14', $grid[51][6]['date']);
    T::same(1, $grid[51][6]['count']);
    T::same('2025-11-16', $grid[0][0]['date']);
});

T::test('grade: meio de semana deixa o resto da semana vazio', function (): void {
    $grid = ActivityService::grid([], day('2026-11-18')); // quarta
    $last = $grid[52];
    T::same('2026-11-15', $last[0]['date']);
    T::same('2026-11-18', $last[3]['date']);
    T::same([null, null, null], array_slice($last, 4));
});

T::test('grade: contagens fora do intervalo não aparecem', function (): void {
    $grid = ActivityService::grid(['2020-01-01' => 9], day('2026-11-15'));
    T::same(0, array_sum(array_map(fn ($w) => array_sum(array_map(fn ($c) => $c['count'] ?? 0, $w)), $grid)));
});

T::test('nível: 0 a 4, com teto', function (): void {
    T::same([0, 1, 2, 3, 4, 4, 4], array_map([ActivityService::class, 'level'], [0, 1, 2, 3, 4, 5, 99]));
    T::same(0, ActivityService::level(-3));
});

T::test('sequência: hoje feito', function (): void {
    $c = ['2026-11-15' => 1, '2026-11-14' => 3, '2026-11-13' => 1, '2026-11-10' => 1];
    T::same(['current' => 3, 'longest' => 3], ActivityService::streaks($c, day('2026-11-15')));
});

T::test('sequência: hoje ainda sem atividade não zera (conta de ontem)', function (): void {
    $c = ['2026-11-14' => 1, '2026-11-13' => 1];
    T::same(2, ActivityService::streaks($c, day('2026-11-15'))['current']);
});

T::test('sequência: um dia sem atividade quebra', function (): void {
    $c = ['2026-11-13' => 1, '2026-11-12' => 1];
    T::same(0, ActivityService::streaks($c, day('2026-11-15'))['current']);
    T::same(2, ActivityService::streaks($c, day('2026-11-15'))['longest']);
});

T::test('sequência: maior sequência atravessa mês e ano', function (): void {
    $c = ['2025-12-30' => 1, '2025-12-31' => 1, '2026-01-01' => 1, '2026-01-02' => 1, '2026-03-01' => 1];
    T::same(4, ActivityService::streaks($c, day('2026-11-15'))['longest']);
});

T::test('sequência: vazio e contagem zero', function (): void {
    T::same(['current' => 0, 'longest' => 0], ActivityService::streaks([], day('2026-11-15')));
    T::same(['current' => 0, 'longest' => 0], ActivityService::streaks(['2026-11-15' => 0], day('2026-11-15')));
});

T::test('sequência: datas futuras são ignoradas', function (): void {
    T::same(['current' => 0, 'longest' => 0], ActivityService::streaks(['2027-01-01' => 5], day('2026-11-15')));
});
