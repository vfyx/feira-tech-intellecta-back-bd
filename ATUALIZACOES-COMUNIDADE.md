# Administração, feed, conversas e moderação

## Administração

- A opção **Administração** no menu lateral é exclusiva de administradores. Lista usuários, publicações e tópicos com busca e paginação.
- Permite editar nome e e-mail, redefinir senha, atribuir permissões, verificar professores e suspender ou reativar contas. Suspensão e troca de senha revogam as sessões existentes; contas suspensas não podem entrar. O administrador não pode suspender a própria conta nem retirar seu próprio papel administrativo.
- Publicações podem ter título, conteúdo e visibilidade alterados. Tópicos podem ser criados e renomeados. O painel oferece acesso direto às denúncias de perfis e posts.
- As rotas validam a autorização no servidor: alunos e moderadores não podem acessar os controles exclusivos de administração.
- A conta administrativa solicitada foi configurada somente no banco local. Credenciais e dados dessa conta não são incluídos neste commit; atualizar o código em outra instalação não cria essa conta automaticamente.

## Comunidade

- O início carrega 12 publicações por página, da mais recente para a mais antiga. Ao chegar ao fim, carrega a próxima página; em caso de erro, oferece nova tentativa.
- Fotos novas são redimensionadas para até 1600 pixels e comprimidas em WebP antes do envio. GIFs preservam a animação. Continuam valendo até 6 fotos, até 2 MB por imagem final e até 6 MB no conjunto.
- Conversas mostram a quantidade de mensagens não lidas e as indicações “Enviada” e “Lida”. A leitura é registrada quando a conversa está visível, a aba está em foco e o histórico está no final.
- Mensagens e confirmações de leitura chegam via SSE. O servidor dedicado atende várias abas sem bloquear o servidor HTTP da API. Ele consulta apenas contadores no banco e envia um evento quando há alterações; o navegador não consulta o estado inteiro a cada cinco segundos.
- A página **Moderação** aparece para administradores e moderadores. Permite filtrar denúncias de posts ou perfis, registrar uma decisão, atualizar o status e ocultar uma publicação denunciada.

## Iniciar a instalação local

Execute `1-PREPARAR.bat` uma vez após atualizar para aplicar as migrations. O script preserva os registros existentes. Depois execute `2-INICIAR.bat`.

O processo de tempo real inicia oculto usando o PHP incluído, na porta **8001**. A API usa 8000 e o site usa 8080. A implementação empacotada atende o site local em `localhost` ou `127.0.0.1`; uma hospedagem externa requer configurar um proxy HTTPS para o serviço SSE.

Os tickets SSE são assinados, vinculados ao token autenticado e expiram em cinco minutos. A conexão é renovada automaticamente e é encerrada quando o token é revogado. Se o serviço ficar indisponível, a interface informa a reconexão.

## Verificações

Com o banco e o serviço de tempo real iniciados, os testes locais `api/tests/community-updates-local.php` e `api/tests/realtime-local.php` verificam paginação, autorização, leitura e SSE. O primeiro usa rollback; o segundo cria contas temporárias para duas conexões independentes e as remove no bloco de limpeza.

O teste `api/tests/admin-local.php` verifica autorização administrativa, suspensão, revogação de sessões, troca de senha, proteção do próprio acesso, edição de posts e gerenciamento de tópicos. Todos os seus registros de teste são revertidos em transação.

Resultados da validação local: 26 verificações administrativas, 30 verificações da comunidade e 9 verificações de tempo real aprovadas. Os formulários administrativos também foram verificados no navegador, incluindo escape de conteúdo e layout de 320 a 1000 pixels.

O preparo agora cria as pastas necessárias de cache e armazenamento do Laravel antes de inicializá-lo, corrigindo a falha `bootstrap/cache directory must be present and writable` em pacotes extraídos.
