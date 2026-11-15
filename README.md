# Shiftly — Day Shift Planner

Aplicação web para organizar turnos de trabalho, permitindo cadastrar, visualizar e remover turnos com seus respectivos horários.

## Funcionalidades

- Cadastro de turnos com nome e horário.
- Ordenação dos turnos por horário.
- Exclusão de turnos cadastrados.
- **Conclusão diária de turnos** e **calendário de constância** estilo GitHub:
  cada dia em que você conclui turnos fica verde (quanto mais turnos, mais
  escuro), com sequência atual, maior sequência e total do último ano.
- Validação dos dados enviados.
- Proteção das operações por token CSRF.
- Armazenamento em arquivos JSON com lock e gravação atômica.
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

## Calendário de constância

- Em "Meus turnos", use **Concluir hoje** para marcar um turno como feito; use **Feito** para desfazer.
- Só o dia de hoje pode ser alterado: o histórico não é editável.
- O calendário mostra as últimas 53 semanas (domingo a sábado). Os níveis de verde
  vão de 1 a 4 ou mais turnos concluídos no dia.
- A sequência atual continua contando se hoje ainda não tem atividade, mas o dia
  anterior teve (como no GitHub).
- Excluir um turno não apaga o histórico dele no calendário.
- O fuso horário padrão é `America/Fortaleza`; mude com a variável `SHIFTLY_TIMEZONE`.

## Armazenamento

Os dados ficam em `storage/` (criado automaticamente):

- `shifts.json`: turnos cadastrados.
- `activity.json`: turnos concluídos por dia.

Para guardar os dados em outro lugar (recomendado fora da pasta pública em
produção), defina `SHIFTLY_STORAGE_DIR`.

Todas as gravações são feitas sob lock e de forma atômica, então requisições
simultâneas não perdem dados. Se um arquivo estiver corrompido, a aplicação
mostra um erro genérico e **não o sobrescreve**, para você poder recuperá-lo.

O diretório `storage/` está listado no `.gitignore` para evitar o envio de dados locais ao repositório.

## Segurança

A aplicação utiliza token CSRF nas operações via formulário, valida os dados recebidos (inclusive formatos inesperados de parâmetros) e escapa valores antes de exibi-los no HTML. Também envia cabeçalhos de segurança (CSP, `X-Frame-Options`, `nosniff`), usa cookie de sessão `HttpOnly` com `SameSite=Lax` e nunca exibe detalhes internos de erros ao usuário.

Para uso em produção com múltiplos usuários, recomenda-se avaliar autenticação, autorização e persistência em banco de dados.

## Testes

Não há dependências: os testes rodam com o próprio PHP.

```bash
php tests/run.php
```

Eles cobrem armazenamento (inclusive gravações simultâneas e arquivo corrompido),
validação, regras do calendário e sequências, e a aplicação inteira via HTTP
(sessão, CSRF, XSS e erros). Os testes usam diretórios temporários e nunca tocam
em `storage/`.

## Contribuição

1. Crie uma branch para sua alteração.
2. Implemente a melhoria.
3. Faça os testes necessários.
4. Abra um Pull Request descrevendo as mudanças.

## Licença

Nenhuma licença foi declarada neste repositório. Consulte o proprietário antes de reutilizar ou distribuir o código.
