<?php

declare(strict_types=1);

session_start();

const STORAGE_DIR = __DIR__ . '/storage';
const SHIFTS_FILE = STORAGE_DIR . '/shifts.json';

if (!is_dir(STORAGE_DIR) && !mkdir(STORAGE_DIR, 0750, true) && !is_dir(STORAGE_DIR)) {
    throw new RuntimeException('Não foi possível criar o diretório de armazenamento.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Escapa valores antes de exibi-los no HTML.
 */
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Lê os turnos armazenados no arquivo JSON.
 */
function loadShifts(): array
{
    $handle = fopen(SHIFTS_FILE, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Não foi possível acessar os turnos.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Não foi possível bloquear o arquivo.');
        }

        $content = stream_get_contents($handle);
        $shifts = json_decode($content ?: '[]', true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($shifts)) {
            throw new RuntimeException('O arquivo de turnos contém dados inválidos.');
        }

        return $shifts;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Salva os turnos no arquivo JSON.
 */
function saveShifts(array $shifts): void
{
    $handle = fopen(SHIFTS_FILE, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Não foi possível salvar os turnos.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Não foi possível bloquear o arquivo.');
        }

        $json = json_encode(
            array_values($shifts),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        rewind($handle);

        if (!ftruncate($handle, 0) || fwrite($handle, $json) === false) {
            throw new RuntimeException('Falha ao gravar os turnos.');
        }

        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        $error = 'Sessão inválida. Atualize a página e tente novamente.';
    } else {
        try {
            $shifts = loadShifts();
            $action = $_POST['action'] ?? '';

            if ($action === 'add') {
                $name = trim($_POST['shift_name'] ?? '');
                $time = $_POST['shift_time'] ?? '';

                if (!is_string($name) || $name === '' || mb_strlen($name) > 80) {
                    throw new InvalidArgumentException(
                        'Informe um nome de turno com até 80 caracteres.'
                    );
                }

                if (
                    !is_string($time) ||
                    !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)
                ) {
                    throw new InvalidArgumentException(
                        'Informe um horário válido.'
                    );
                }

                $shifts[] = [
                    'id' => bin2hex(random_bytes(8)),
                    'name' => $name,
                    'time' => $time,
                    'created_at' => date(DATE_ATOM),
                ];

                usort(
                    $shifts,
                    fn (array $a, array $b): int => strcmp($a['time'], $b['time'])
                );

                saveShifts($shifts);

                header('Location: index.php?success=added');
                exit;
            }

            if ($action === 'delete') {
                $id = $_POST['shift_id'] ?? '';

                if (!is_string($id) || $id === '') {
                    throw new InvalidArgumentException('Turno inválido.');
                }

                $updatedShifts = array_values(
                    array_filter(
                        $shifts,
                        fn (array $shift): bool => $shift['id'] !== $id
                    )
                );

                if (count($updatedShifts) === count($shifts)) {
                    throw new InvalidArgumentException(
                        'O turno solicitado não foi encontrado.'
                    );
                }

                saveShifts($updatedShifts);

                header('Location: index.php?success=deleted');
                exit;
            }

            throw new InvalidArgumentException('Ação inválida.');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            http_response_code(500);
            $error = 'Ocorreu um erro ao processar a solicitação.';
        }
    }
}

try {
    $shifts = loadShifts();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    $shifts = [];
    $error = 'Não foi possível carregar os turnos.';
}

$success = $_GET['success'] ?? null;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Organize e gerencie seus turnos de trabalho.">
    <title>Day Shift Planner</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="container">
        <header>
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">S</span>
                <span>shiftly</span>
            </div>
            <p class="eyebrow">Organização de rotina</p>
            <h1>Seu dia, organizado.</h1>
            <p>Organize seus turnos e tenha uma visão mais clara da sua rotina.</p>
        </header>

        <?php if ($error !== null): ?>
            <p class="message error" role="alert">
                <?= escape($error) ?>
            </p>
        <?php endif; ?>

        <?php if ($success === 'added'): ?>
            <p class="message success" role="status">
                Turno adicionado com sucesso!
            </p>
        <?php elseif ($success === 'deleted'): ?>
            <p class="message success" role="status">
                Turno excluído com sucesso!
            </p>
        <?php endif; ?>

        <section aria-labelledby="form-title">
            <h2 id="form-title">Adicionar turno</h2>

            <form method="POST" action="index.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escape($_SESSION['csrf_token']) ?>"
                >

                <input type="hidden" name="action" value="add">

                <label for="shift-name">Nome do turno</label>
                <input
                    id="shift-name"
                    name="shift_name"
                    type="text"
                    maxlength="80"
                    placeholder="Ex.: Turno da manhã"
                    required
                >

                <label for="shift-time">Horário</label>
                <input
                    id="shift-time"
                    name="shift_time"
                    type="time"
                    required
                >

                <button type="submit">Adicionar turno</button>
            </form>
        </section>

        <section aria-labelledby="shifts-title">
            <h2 id="shifts-title">Meus turnos (<?= count($shifts) ?>)</h2>

            <?php if ($shifts === []): ?>
                <p class="empty-state">
                    Você ainda não cadastrou nenhum turno.
                </p>
            <?php else: ?>
                <ul class="shift-list">
                    <?php foreach ($shifts as $shift): ?>
                        <li class="shift-item">
                            <div class="shift-info">
                                <strong><?= escape($shift['name']) ?></strong>
                                <time datetime="<?= escape($shift['time']) ?>">
                                    <?= escape($shift['time']) ?>
                                </time>
                            </div>

                            <form method="POST" action="index.php">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= escape($_SESSION['csrf_token']) ?>"
                                >

                                <input type="hidden" name="action" value="delete">

                                <input
                                    type="hidden"
                                    name="shift_id"
                                    value="<?= escape($shift['id']) ?>"
                                >

                                <button
                                    type="submit"
                                    class="delete-button"
                                    aria-label="Excluir <?= escape($shift['name']) ?>"
                                >
                                    Excluir
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <footer>
            <p>Day Shift Planner &mdash; Organize sua rotina.</p>
        </footer>
    </main>
</body>
</html>
