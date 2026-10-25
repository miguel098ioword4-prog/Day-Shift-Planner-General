# Shiftly — Day Shift Planner

Aplicação web para organizar turnos de trabalho, permitindo cadastrar, visualizar e remover turnos com seus respectivos horários.

## Funcionalidades

- Cadastro de turnos com nome e horário.
- Ordenação dos turnos por horário.
- Exclusão de turnos cadastrados.
- Validação dos dados enviados.
- Proteção das operações por token CSRF.
- Armazenamento dos turnos em arquivo JSON.
- Interface responsiva e identidade visual própria.

## Tecnologias

- PHP
- HTML5
- CSS3
- JSON

## Requisitos

- PHP 8.1 ou superior.
- Extensão `mbstring` habilitada.
- Permissão de escrita no diretório `storage/`.

## Como executar

Clone o repositório:

```bash
git clone https://github.com/miguel098ioword4-prog/Day-Shift-Planner-General.git
cd Day-Shift-Planner-General
```

Inicie o servidor de desenvolvimento:

```bash
php -S localhost:8000
```

Acesse `http://localhost:8000/index.php` no navegador.

## Armazenamento

Os turnos são armazenados localmente no arquivo `storage/shifts.json`, criado automaticamente quando necessário.

O diretório `storage/` está listado no `.gitignore` para evitar o envio de dados locais ao repositório.

## Segurança

A aplicação utiliza token CSRF nas operações via formulário, valida os dados recebidos e escapa valores antes de exibi-los no HTML.

Para uso em produção com múltiplos usuários, recomenda-se avaliar autenticação, autorização e persistência em banco de dados.

## Contribuição

1. Crie uma branch para sua alteração.
2. Implemente a melhoria.
3. Faça os testes necessários.
4. Abra um Pull Request descrevendo as mudanças.

## Licença

Nenhuma licença foi declarada neste repositório. Consulte o proprietário antes de reutilizar ou distribuir o código.
