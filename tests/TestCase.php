<?php

declare(strict_types=1);

/** Mini framework de testes: sem dependências, roda com `php tests/run.php`. */
final class T
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failures = [];

    /** @var list<string> */
    private static array $dirs = [];

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$passed++;
        } catch (Throwable $e) {
            self::$failures[] = $name . ': ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
    }

    public static function same(mixed $expected, mixed $actual, string $note = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $note . ' esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true)
            );
        }
    }

    public static function true(mixed $value, string $note = ''): void
    {
        self::same(true, $value, $note);
    }

    /** @param class-string<Throwable> $class */
    public static function throws(string $class, callable $fn, ?string $contains = null): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new RuntimeException('esperava ' . $class . ', veio ' . $e::class . ': ' . $e->getMessage());
            }
            if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
                throw new RuntimeException('mensagem "' . $e->getMessage() . '" sem "' . $contains . '"');
            }

            return;
        }

        throw new RuntimeException('esperava exceção ' . $class);
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/shiftly-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0750, true);
        self::$dirs[] = $dir;

        return $dir;
    }

    public static function cleanup(): void
    {
        foreach (self::$dirs as $dir) {
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
}
