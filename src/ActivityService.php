<?php

declare(strict_types=1);

require_once __DIR__ . '/JsonStore.php';

/**
 * Registro de atividade diária (o "calendário verde").
 *
 * Cada vez que você conclui um turno no dia, ele entra no registro daquele dia.
 * Só o dia de hoje pode ser alterado: o histórico não é editável.
 *
 * Formato do arquivo: { "2026-11-15": ["<id do turno>", ...], ... }
 */
final class ActivityService
{
    public const WEEKS = 53;

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}\z/';
    private const ID_PATTERN = '/^[a-f0-9]{16}\z/';

    public function __construct(private readonly JsonStore $store)
    {
    }

    /** Marca (ou desmarca) um turno como concluído em $today. */
    public function setDone(string $shiftId, DateTimeImmutable $today, bool $done): void
    {
        if (preg_match(self::ID_PATTERN, $shiftId) !== 1) {
            throw new ValidationException('Turno inválido.');
        }

        $key = $today->format('Y-m-d');

        $this->store->update(function (array $data) use ($shiftId, $key, $done): array {
            $days = self::normalize($data);
            $ids = $days[$key] ?? [];

            if ($done) {
                $ids[$shiftId] = true;
            } else {
                unset($ids[$shiftId]);
            }

            if ($ids === []) {
                unset($days[$key]);
            } else {
                $days[$key] = $ids;
            }

            return self::export($days);
        });
    }

    /** @return list<string> ids concluídos no dia */
    public function doneOn(DateTimeImmutable $day): array
    {
        $days = self::normalize($this->store->read());

        return array_map('strval', array_keys($days[$day->format('Y-m-d')] ?? []));
    }

    /** @return array<string, int> data (Y-m-d) => quantidade concluída */
    public function counts(): array
    {
        return array_map('count', self::normalize($this->store->read()));
    }

    /**
     * Grade estilo GitHub: colunas = semanas (domingo a sábado), terminando na
     * semana de $today. Dias futuros vêm como null.
     *
     * @param array<string, int> $counts
     * @return list<list<array{date: string, count: int, level: int}|null>>
     */
    public static function grid(array $counts, DateTimeImmutable $today, int $weeks = self::WEEKS): array
    {
        $today = $today->setTime(0, 0);
        $sundayOfThisWeek = $today->modify('-' . (int) $today->format('w') . ' days');
        $start = $sundayOfThisWeek->modify('-' . ($weeks - 1) . ' weeks');
        $grid = [];

        for ($week = 0; $week < $weeks; $week++) {
            $column = [];

            for ($weekday = 0; $weekday < 7; $weekday++) {
                $day = $start->modify('+' . ($week * 7 + $weekday) . ' days');

                if ($day > $today) {
                    $column[] = null;
                    continue;
                }

                $key = $day->format('Y-m-d');
                $count = $counts[$key] ?? 0;
                $column[] = ['date' => $key, 'count' => $count, 'level' => self::level($count)];
            }

            $grid[] = $column;
        }

        return $grid;
    }

    public static function level(int $count): int
    {
        return min(max($count, 0), 4);
    }

    /**
     * Sequência atual (como no GitHub, hoje ainda sem atividade não zera a
     * sequência: ela continua contando a partir de ontem) e a maior sequência.
     *
     * @param array<string, int> $counts
     * @return array{current: int, longest: int}
     */
    public static function streaks(array $counts, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $active = [];

        foreach ($counts as $date => $count) {
            if ($count > 0 && $date <= $today->format('Y-m-d')) {
                $active[$date] = true;
            }
        }

        $cursor = isset($active[$today->format('Y-m-d')]) ? $today : $today->modify('-1 day');
        $current = 0;

        while (isset($active[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor = $cursor->modify('-1 day');
        }

        $dates = array_keys($active);
        sort($dates);
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($dates as $date) {
            $day = new DateTimeImmutable($date);
            $run = ($previous !== null && $previous->modify('+1 day') == $day) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $day;
        }

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * Valida o arquivo: datas reais, ids no formato certo, sem repetição.
     *
     * @param array<mixed> $data
     * @return array<string, array<string, true>>
     */
    private static function normalize(array $data): array
    {
        $days = [];

        foreach ($data as $date => $ids) {
            $date = (string) $date;

            if (preg_match(self::DATE_PATTERN, $date) !== 1 || !is_array($ids)) {
                continue;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                continue;
            }

            foreach ($ids as $id) {
                if (is_string($id) && preg_match(self::ID_PATTERN, $id) === 1) {
                    $days[$date][$id] = true;
                }
            }
        }

        ksort($days);

        return $days;
    }

    /**
     * @param array<string, array<string, true>> $days
     * @return array<string, list<string>>
     */
    private static function export(array $days): array
    {
        $out = [];

        foreach ($days as $date => $ids) {
            $list = array_map('strval', array_keys($ids));
            sort($list);
            $out[$date] = $list;
        }

        return $out;
    }
}
