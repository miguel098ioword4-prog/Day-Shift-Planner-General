<?php

declare(strict_types=1);

require_once __DIR__ . '/Exceptions.php';

/**
 * Armazenamento JSON com leitura/gravação segura entre requisições.
 *
 * - Todo ciclo "ler, alterar, gravar" acontece sob um único lock exclusivo,
 *   então duas requisições simultâneas não perdem alterações.
 * - A gravação é atômica (arquivo temporário + rename): um erro no meio nunca
 *   deixa um JSON pela metade.
 * - Um arquivo corrompido nunca é sobrescrito; a operação falha e o arquivo
 *   é preservado para inspeção.
 */
final class JsonStore
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array<mixed> */
    public function read(): array
    {
        return $this->withLock(LOCK_SH, fn (): array => $this->load());
    }

    /**
     * Aplica $mutator aos dados atuais e grava o resultado.
     * Se $mutator lançar exceção, nada é gravado.
     *
     * @param callable(array<mixed>): array<mixed> $mutator
     * @return array<mixed> dados gravados
     */
    public function update(callable $mutator): array
    {
        return $this->withLock(LOCK_EX, function () use ($mutator): array {
            $updated = $mutator($this->load());
            $this->write($updated);

            return $updated;
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(int $mode, callable $callback): mixed
    {
        $handle = @fopen($this->path . '.lock', 'c');

        if ($handle === false) {
            throw new StorageException('Não foi possível abrir o arquivo de lock.');
        }

        try {
            if (!flock($handle, $mode)) {
                throw new StorageException('Não foi possível obter o lock.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<mixed> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new StorageException('Não foi possível ler ' . $this->path);
        }

        if (trim($content) === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorageException(
                'JSON inválido em ' . $this->path . ': ' . $exception->getMessage()
            );
        }

        if (!is_array($data)) {
            throw new StorageException('Conteúdo inesperado em ' . $this->path);
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private function write(array $data): void
    {
        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new StorageException('Falha ao codificar JSON: ' . $exception->getMessage());
        }

        $temporary = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            @unlink($temporary);
            throw new StorageException('Falha ao gravar ' . $temporary);
        }

        @chmod($temporary, 0640);

        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new StorageException('Falha ao substituir ' . $this->path);
        }
    }
}
