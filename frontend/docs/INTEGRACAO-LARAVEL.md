# Integração Intellecta / ETEC Hub — 13/10/2026

Frontend alterado exclusivamente na cópia do USBWebserver:
`C:\Users\edicc\Downloads\usbwebserver\root\Intellecta-main`.
API: `C:\Users\edicc\Downloads\levar-feira\feira_tech_test-main\api`.

## Preservação

Os arquivos anteriores estão em `backups/integracao-20261013-224922` em cada projeto.
Não foram modificados o `.env`, o `storage/state.json`, as migrations ou os estilos CSS.
Não foram executadas migrations, recriação de banco, exclusão de volumes ou importação do dump.
A outra cópia do frontend em Downloads não foi alterada.
Os backups e o armazenamento do frontend estão protegidos contra acesso HTTP.

O pacote `intellecta_integracao_etapa1.zip` foi encontrado e comparado com a instalação.
A instalação ainda usava o backend JSON. A base da etapa 1 foi aplicada e ampliada.

## Funcionamento

Cadastro, login e logout usam Laravel Sanctum. O PHP guarda o token em uma sessão
separada (`INTELLECTA_API`, armazenamento `storage/sessions-api`) e envia o cabeçalho
de autorização à API. O JavaScript recebe apenas os dados de interface e o CSRF.
As contas do JSON antigo não são importadas automaticamente.

`GET /api/intellecta/state` fornece os dados adaptados às telas.
`POST /api/intellecta/action` recebe as ações autenticadas da ponte PHP.
As ações de escrita não usam o JSON antigo.

Ações conectadas: perfil, preferências de interface e privacidade, alertas de acesso,
troca de senha, publicação de texto/imagem/enquete, comentário, curtida, salvamento,
republicação, ocultação, exclusão da própria publicação, voto, seguir, bloqueio,
assinatura de autor, denúncia de perfil, mensagem, limpeza individual de histórico
e leitura de notificações. A troca de senha encerra os outros tokens da conta.

Publicações novas aparecem **imediatamente no feed**, sem análise ou aprovação
prévia de IA, tanto pela ponte Intellecta quanto pelo endpoint `/api/posts`.
Privacidade de perfil e bloqueios continuam valendo na ponte Intellecta.
Registros antigos pendentes ou reprovados não foram alterados em massa.
As disciplinas do formulário vêm do PostgreSQL; “Geral” usa “Ideias”, quando disponível.
A indexação vetorial não é acionada na publicação e será integrada separadamente,
sem bloquear a postagem. Os serviços existentes de Gemini/pgvector foram preservados.

A leitura aplica bloqueios nos dois sentidos, privacidade de perfil e ocultação
individual de mensagens. As escritas verificam visibilidade e propriedade.
O limitador de requisições usa cache em arquivo, sem exigir tabela nova no banco.

## Validação

- Cadastro, logout e login realizados pelas telas no USBWebserver.
- Perfil editado e confirmado após redirecionamento; publicação criada com aviso
  de moderação; preferência de feed compacto alterada e confirmada após recarga.
- Token e hashes privados ausentes do estado HTML inspecionado no navegador.
- POST sem CSRF, acesso HTTP ao JSON e acesso HTTP a backup: todos retornaram 403.
- Sintaxe JavaScript e PHP validada nos arquivos de integração.
- `api/tests/intellecta-postgres.php`: 84 verificações contra PostgreSQL real,
  incluindo pgvector e as quatro migrations já existentes. O teste usa uma transação
  externa e a reverte ao final, preservando os dados anteriores. Verifica também
  as restrições adiadas das enquetes antes da reversão.

Ficaram no banco somente a conta identificada como **Validação Feira**, o perfil
e a publicação pendente criados pela interface, mais suas preferências/avisos.
Não foram removidos, para preservar o resultado visível da validação.

## Pendências e limites

Gemini e busca vetorial na interface; indexação do novo fluxo de postagem;
recuperação de acesso; chave adicional; envio de e-mail; OAuth Google/Microsoft;
exclusão de conta. As ações ainda não integradas retornam mensagem de indisponibilidade.
Indicadores de presença online ainda não têm rastreamento real.

A consulta de estado mantém os limites da etapa 1: 50 publicações, 100 notificações
e 300 mensagens. Paginação completa e contagens globais ficam para uma próxima etapa.
Publicações salvas fora da janela das 50 mais recentes não entram no estado atual.
Os endpoints antigos de posts/DM e o tutor vetorial continuam separados dessa ponte;
a filtragem de bloqueios/privacidade deles precisa ser alinhada antes de conectá-los
à interface ou usar o conteúdo privado como contexto de IA.

## Ajuste solicitado: publicação direta

A exigência de aprovação prévia foi removida dos dois fluxos de criação de posts.
Backups deste ajuste: `backups/publicacao-direta-20261013-230950` em cada projeto.
Após o ajuste passaram 85 verificações no PostgreSQL real, revertidas ao final,
incluindo visibilidade imediata para outra conta e postagem pelo endpoint antigo.
As referências a publicação pendente na validação anterior são históricas.
