<?php
/**
 * Calendário de atividade estilo GitHub.
 *
 * @var list<list<array{date: string, count: int, level: int}|null>> $grid
 * @var array{current: int, longest: int} $streaks
 * @var int $totalDone
 * @var int $doneToday
 * @var int $shiftTotal
 * @var string $todayKey
 */

$months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
$weekdayLabels = ['', 'seg', '', 'qua', '', 'sex', ''];

/** Rótulo do mês na primeira semana em que ele aparece. */
$monthLabel = static function (int $index) use ($grid, $months): string {
    $first = $grid[$index][0]['date'] ?? null;

    if ($first === null) {
        return '';
    }

    $month = (int) substr($first, 5, 2);
    $previous = $index > 0 ? (int) substr($grid[$index - 1][0]['date'] ?? $first, 5, 2) : 0;

    if ($index === 0) {
        $next = (int) substr($grid[1][0]['date'] ?? $first, 5, 2);

        return $next === $month ? $months[$month - 1] : '';
    }

    return $month !== $previous ? $months[$month - 1] : '';
};

$describe = static function (array $cell): string {
    [$y, $m, $d] = explode('-', $cell['date']);
    $label = $cell['count'] === 0
        ? 'Nenhum turno concluído'
        : $cell['count'] . ($cell['count'] === 1 ? ' turno concluído' : ' turnos concluídos');

    return $label . ' em ' . $d . '/' . $m . '/' . $y;
};
?>
<section aria-labelledby="activity-title" class="activity">
    <h2 id="activity-title">Sua constância</h2>

    <dl class="stats">
        <div>
            <dt>Hoje</dt>
            <dd><?= $doneToday ?> de <?= $shiftTotal ?></dd>
        </div>
        <div>
            <dt>Sequência atual</dt>
            <dd><?= $streaks['current'] ?> <?= $streaks['current'] === 1 ? 'dia' : 'dias' ?></dd>
        </div>
        <div>
            <dt>Maior sequência</dt>
            <dd><?= $streaks['longest'] ?> <?= $streaks['longest'] === 1 ? 'dia' : 'dias' ?></dd>
        </div>
        <div>
            <dt>No último ano</dt>
            <dd><?= $totalDone ?></dd>
        </div>
    </dl>

    <div class="heatmap-scroll">
        <div class="heatmap" role="img"
             aria-label="Calendário de turnos concluídos nas últimas <?= count($grid) ?> semanas">
            <div class="heatmap-col heatmap-weekdays" aria-hidden="true">
                <span class="heatmap-month"></span>
                <?php foreach ($weekdayLabels as $label): ?>
                    <span class="heatmap-weekday"><?= escape($label) ?></span>
                <?php endforeach; ?>
            </div>
            <?php foreach ($grid as $index => $week): ?>
                <div class="heatmap-col">
                    <span class="heatmap-month" aria-hidden="true"><?= escape($monthLabel($index)) ?></span>
                    <?php foreach ($week as $cell): ?>
                        <?php if ($cell === null): ?>
                            <span class="heatmap-cell is-future" aria-hidden="true"></span>
                        <?php else: ?>
                            <span
                                class="heatmap-cell lv<?= $cell['level'] ?><?= $cell['date'] === $todayKey ? ' is-today' : '' ?>"
                                title="<?= escape($describe($cell)) ?>"
                            ></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="heatmap-legend" aria-hidden="true">
        <span>Menos</span>
        <?php for ($level = 0; $level <= 4; $level++): ?>
            <span class="heatmap-cell lv<?= $level ?>"></span>
        <?php endfor; ?>
        <span>Mais</span>
    </div>
    <p class="hint">Conclua seus turnos de hoje para pintar o dia de verde. Quanto mais turnos, mais escuro.</p>
</section>
