<?php

declare(strict_types=1);

/** Dado enviado pelo usuário é inválido (mensagem segura para exibir). */
final class ValidationException extends InvalidArgumentException
{
}

/** Falha ao ler ou gravar o armazenamento (detalhes só vão para o log). */
final class StorageException extends RuntimeException
{
}
