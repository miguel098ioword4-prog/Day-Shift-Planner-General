<?php

declare(strict_types=1);

require_once __DIR__ . '/JsonStore.php';

/**
 * Regras de negócio dos turnos: validação, ordenação e persistência.
 *
 * @phpstan-type Shift array{id: string, name: string, time: string, created_at: string}
 */
final class ShiftService
{
    public const MAX_NAME_LENGTH = 80;
    public const MAX_SHIFTS = 100;

    private const ID_PATTERN = '/^[a-f0-9]{16}\z/';
    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d\z/';

    public function __construct(private readonly JsonStore $store)
    {
    }

    /**
     * Turnos ordenados por horário. Registros malformados no arquivo são
     * ignorados em vez de derrubar a página.
     *
     * @return list<array{id: string, name: string, time: string, created_at: string}>
     */
    public function all(): array
    {
        return self::normalize($this->store->read());
    }

    /** @return array{id: string, name: string, time: string, created_at: string} */
    public function add(mixed $name, mixed $time, ?DateTimeImmutable $now = null): array
    {
        $cleanName = self::validateName($name);
        $cleanTime = self::validateTime($time);
        $now ??= new DateTimeImmutable();
        $created = null;

        $this->store->update(
            function (array $data) use ($cleanName, $cleanTime, $now, &$created): array {
                $shifts = self::normalize($data);

                if (count($shifts) >= self::MAX_SHIFTS) {
                    throw new ValidationException(
                        'Limite de ' . self::MAX_SHIFTS . ' turnos atingido.'
                    );
                }

                foreach ($shifts as $existing) {
                    if (
                        $existing['time'] === $cleanTime
                        && mb_strtolower($existing['name']) === mb_strtolower($cleanName)
                    ) {
                        throw new ValidationException(
                            'Já existe um turno com esse nome e horário.'
                        );
                    }
                }

                $created = [
                    'id' => bin2hex(random_bytes(8)),
                    'name' => $cleanName,
                    'time' => $cleanTime,
                    'created_at' => $now->format(DATE_ATOM),
                ];
                $shifts[] = $created;

                return self::sort($shifts);
            }
        );

        /** @var array{id: string, name: string, time: string, created_at: string} $created */
        return $created;
    }

    public function delete(mixed $id): void
    {
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new ValidationException('Turno inválido.');
        }

        $this->store->update(function (array $data) use ($id): array {
            $shifts = self::normalize($data);
            $remaining = array_values(
                array_filter($shifts, static fn (array $s): bool => $s['id'] !== $id)
            );

            if (count($remaining) === count($shifts)) {
                throw new ValidationException('O turno solicitado não foi encontrado.');
            }

            return $remaining;
        });
    }

    public function exists(mixed $id): bool
    {
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            return false;
        }

        foreach ($this->all() as $shift) {
            if ($shift['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    public static function validateName(mixed $name): string
    {
        if (!is_string($name)) {
            throw new ValidationException('Informe o nome do turno.');
        }

        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new ValidationException('O nome do turno contém caracteres inválidos.');
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            throw new ValidationException('Informe o nome do turno.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                'O nome do turno pode ter até ' . self::MAX_NAME_LENGTH . ' caracteres.'
            );
        }

        if (preg_match('/[\p{C}]/u', $name) === 1) {
            throw new ValidationException('O nome do turno contém caracteres inválidos.');
        }

        return $name;
    }

    public static function validateTime(mixed $time): string
    {
        if (!is_string($time) || preg_match(self::TIME_PATTERN, $time) !== 1) {
            throw new ValidationException('Informe um horário válido (HH:MM).');
        }

        return $time;
    }

    /**
     * @param array<mixed> $data
     * @return list<array{id: string, name: string, time: string, created_at: string}>
     */
    private static function normalize(array $data): array
    {
        $shifts = [];
        $seen = [];

        foreach ($data as $item) {
            if (
                !is_array($item)
                || !isset($item['id'], $item['name'], $item['time'])
                || !is_string($item['id'])
                || !is_string($item['name'])
                || !is_string($item['time'])
                || preg_match(self::ID_PATTERN, $item['id']) !== 1
                || preg_match(self::TIME_PATTERN, $item['time']) !== 1
                || $item['name'] === ''
                || isset($seen[$item['id']])
            ) {
                continue;
            }

            $seen[$item['id']] = true;
            $shifts[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'time' => $item['time'],
                'created_at' => is_string($item['created_at'] ?? null)
                    ? $item['created_at']
                    : '',
            ];
        }

        return self::sort($shifts);
    }

    /**
     * @param list<array{id: string, name: string, time: string, created_at: string}> $shifts
     * @return list<array{id: string, name: string, time: string, created_at: string}>
     */
    private static function sort(array $shifts): array
    {
        usort(
            $shifts,
            static fn (array $a, array $b): int => [$a['time'], mb_strtolower($a['name']), $a['id']]
                <=> [$b['time'], mb_strtolower($b['name']), $b['id']]
        );

        return $shifts;
    }
}
