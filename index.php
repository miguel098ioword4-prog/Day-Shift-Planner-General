<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

sendSecurityHeaders();
startSession();

$timezone = appTimezone();
$today = new DateTimeImmutable('today', $timezone);
$todayKey = $today->format('Y-m-d');

$error = null;
$status = 200;
$formName = '';
$formTime = '';
$shifts = [];
$counts = [];
$doneIds = [];

$messages = [
    'added' => 'Turno adicionado com sucesso!',
    'deleted' => 'Turno excluído com sucesso!',
    'done' => 'Turno concluído. Seu dia de hoje está mais verde!',
    'undone' => 'Conclusão desfeita.',
];

try {
    $directory = storageDirectory();
    $shiftService = new ShiftService(new JsonStore($directory . '/shifts.json'));
    $activity = new ActivityService(new JsonStore($directory . '/activity.json'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!csrfIsValid($_POST['csrf_token'] ?? null)) {
                $status = 403;
                throw new ValidationException('Sessão inválida. Atualize a página e tente novamente.');
            }

            $action = $_POST['action'] ?? '';
            $redirect = null;

            if ($action === 'add') {
                $formName = is_string($_POST['shift_name'] ?? null) ? $_POST['shift_name'] : '';
                $formTime = is_string($_POST['shift_time'] ?? null) ? $_POST['shift_time'] : '';
                $shiftService->add($_POST['shift_name'] ?? null, $_POST['shift_time'] ?? null);
                $redirect = 'added';
            } elseif ($action === 'delete') {
                $shiftService->delete($_POST['shift_id'] ?? null);
                $redirect = 'deleted';
            } elseif ($action === 'complete' || $action === 'undo') {
                $id = $_POST['shift_id'] ?? null;

                if (!is_string($id) || !$shiftService->exists($id)) {
                    throw new ValidationException('O turno solicitado não foi encontrado.');
                }

                $activity->setDone($id, $today, $action === 'complete');
                $redirect = $action === 'complete' ? 'done' : 'undone';
            } else {
                throw new ValidationException('Ação inválida.');
            }

            header('Location: index.php?success=' . $redirect, true, 303);
            exit;
        } catch (ValidationException $exception) {
            $error = $exception->getMessage();
            $status = $status === 403 ? 403 : 422;
        }
    }

    $shifts = $shiftService->all();
    $counts = $activity->counts();
    $doneIds = $activity->doneOn($today);
} catch (Throwable $exception) {
    error_log('[shiftly] ' . $exception->getMessage());
    $status = 500;
    $error = 'Ocorreu um erro ao acessar os dados. Tente novamente em instantes.';
}

http_response_code($status);

$successKey = $_GET['success'] ?? null;
$success = is_string($successKey) ? ($messages[$successKey] ?? null) : null;

$shiftIds = array_column($shifts, 'id');
$doneToday = count(array_intersect($doneIds, $shiftIds));
$grid = ActivityService::grid($counts, $today);
$streaks = ActivityService::streaks($counts, $today);
$totalDone = array_sum(array_map(
    static fn (array $week): int => array_sum(
        array_map(static fn (?array $cell): int => $cell['count'] ?? 0, $week)
    ),
    $grid
));
$shiftTotal = count($shifts);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Organize seus turnos e acompanhe sua constância.">
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
            <p class="message error" role="alert"><?= escape($error) ?></p>
        <?php endif; ?>

        <?php if ($success !== null && $error === null): ?>
            <p class="message success" role="status"><?= escape($success) ?></p>
        <?php endif; ?>

        <?php require __DIR__ . '/src/views/heatmap.php'; ?>

        <section aria-labelledby="form-title">
            <h2 id="form-title">Adicionar turno</h2>

            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add">

                <label for="shift-name">Nome do turno</label>
                <input
                    id="shift-name"
                    name="shift_name"
                    type="text"
                    maxlength="<?= ShiftService::MAX_NAME_LENGTH ?>"
                    placeholder="Ex.: Turno da manhã"
                    value="<?= escape($formName) ?>"
                    required
                >

                <label for="shift-time">Horário</label>
                <input
                    id="shift-time"
                    name="shift_time"
                    type="time"
                    value="<?= escape($formTime) ?>"
                    required
                >

                <button type="submit">Adicionar turno</button>
            </form>
        </section>

        <section aria-labelledby="shifts-title">
            <h2 id="shifts-title">Meus turnos (<?= $shiftTotal ?>)</h2>

            <?php if ($shifts === []): ?>
                <p class="empty-state">Você ainda não cadastrou nenhum turno.</p>
            <?php else: ?>
                <ul class="shift-list">
                    <?php foreach ($shifts as $shift): ?>
                        <?php $isDone = in_array($shift['id'], $doneIds, true); ?>
                        <li class="shift-item<?= $isDone ? ' is-done' : '' ?>">
                            <div class="shift-info">
                                <strong><?= escape($shift['name']) ?></strong>
                                <time datetime="<?= escape($shift['time']) ?>">
                                    <?= escape($shift['time']) ?>
                                </time>
                            </div>

                            <div class="shift-actions">
                                <form method="POST" action="index.php">
                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="<?= $isDone ? 'undo' : 'complete' ?>">
                                    <input type="hidden" name="shift_id" value="<?= escape($shift['id']) ?>">
                                    <button
                                        type="submit"
                                        class="done-button<?= $isDone ? ' is-done' : '' ?>"
                                        aria-label="<?= $isDone ? 'Desfazer conclusão de ' : 'Concluir hoje: ' ?><?= escape($shift['name']) ?>"
                                    >
                                        <?= $isDone ? '✓ Feito' : 'Concluir hoje' ?>
                                    </button>
                                </form>

                                <form method="POST" action="index.php">
                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="shift_id" value="<?= escape($shift['id']) ?>">
                                    <button
                                        type="submit"
                                        class="delete-button"
                                        aria-label="Excluir <?= escape($shift['name']) ?>"
                                    >
                                        Excluir
                                    </button>
                                </form>
                            </div>
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
