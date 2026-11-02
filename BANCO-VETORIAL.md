# Intellia: publicações e materiais de estudo

A Intellia usa o PostgreSQL do Docker com pgvector. Os textos são divididos
em trechos de até 2.000 caracteres com 200 de sobreposição; o Gemini gera um
vetor de 1.536 dimensões para cada trecho. A pergunta gera outro vetor, e o
banco recupera até cinco trechos próximos para o Gemini responder com fontes.

## Configuração local

No arquivo **api/.env**, que é ignorado pelo Git:

```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=sua_chave_local
GEMINI_CHAT_MODEL=gemini-3.6-flash
GEMINI_EMBEDDING_MODEL=gemini-embedding-001
GEMINI_EMBEDDING_DIMENSIONS=1536
AI_TIMEOUT=30
RAG_MAX_DISTANCE=0.45
```

Os modelos precisam estar disponíveis na sua conta. Não coloque a chave no
`.env` da raiz (esse arquivo é versionado). Nunca cole a chave em commits.

Se o PHP portátil retornar erro de certificado TLS, a variável opcional
`AI_CA_BUNDLE` aceita o caminho absoluto de um arquivo de autoridades
certificadoras confiável instalado no computador. Por exemplo, o Git para
Windows costuma fornecer `C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt`.
A verificação HTTPS permanece ativa; não use `verify=false`.

Com o Docker aberto e o banco preparado por `1-PREPARAR.bat`, execute os
comandos abaixo em um terminal na raiz do projeto. `IA.bat` usa o PHP incluído
no repositório; não precisa instalar PHP no PATH.

```powershell
.\IA.bat config:clear
.\IA.bat migrate
```

O site inicia por `2-INICIAR.bat` e abre em http://localhost:8080. A API fica
na porta 8000, tempo real na 8001 e PostgreSQL na 55432. Os dados existentes
ficam no volume `intellecta-feira-portatil_dados_portateis`.

## Alimentar a base

### Conversa básica no pgAdmin

A tabela `public.respostas_conversa` guarda uma pequena base de cumprimentos,
agradecimentos e despedidas. Ela é consultada antes do Gemini e da busca vetorial.
Não contém conteúdo de estudo. As 14 frases iniciais são criadas por migration;
depois disso, as respostas usadas vêm diretamente do banco, sem cache.

No pgAdmin, em `escola > Schemas > public > Tables > respostas_conversa`,
use **View/Edit Data > All Rows**. Edite `pergunta` e `resposta`, ou adicione uma
linha nova. Marque `ativo=false` para desativar uma resposta sem apagá-la.
Salve as alterações na grade. Não é preciso reiniciar o servidor.

A correspondência compara a frase inteira, ignorando acentos, maiúsculas,
pontuação e espaços repetidos: `OLÁ!!!` corresponde a `olá`. Variações com
outras palavras precisam de sua própria linha. Perguntas de estudo continuam
no fluxo de consulta da IA. Essa base não guarda histórico de conversa.

### Publicações e materiais

As importações fazem chamadas ao Gemini e consomem a cota da sua chave.
O texto dos conteúdos elegíveis é enviado ao provedor para gerar embeddings;
na conversa, a pergunta e os trechos recuperados são enviados para gerar a resposta.

Publicações públicas e aprovadas:

```powershell
.\IA.bat knowledge:index --posts
```

Material PDF, TXT UTF-8 ou Markdown UTF-8:

```powershell
.\IA.bat knowledge:index --file="C:\Materiais\biologia.pdf" --title="Biologia: células"
.\IA.bat knowledge:index --file="C:\Materiais\matematica.md" --title="Frações" --subject=1
```

`--subject` é opcional e precisa corresponder ao ID real de uma disciplina.
PDFs mantêm a página de origem; PDFs digitalizados sem texto precisam de OCR
antes da importação. Limites: 20 MB, 500 páginas e 500 trechos por material.
O arquivo original não é copiado para a pasta pública nem disponibilizado para download.
Nesta primeira etapa, materiais importados ficam disponíveis a todas as contas
autenticadas; importe apenas materiais que podem ser compartilhados na escola.
Não há tela de upload ou OCR embutido.

Execute novamente após publicar/editar posts ou alterar um material.
Conteúdo inalterado com o mesmo modelo não gera novos embeddings.
Use `--force` para regerar os vetores. Trocar modelo/provedor exige reindexar:
os vetores de modelos diferentes nunca são misturados na busca.
Ainda não há fila ou indexação automática ao publicar.

Uma importação só substitui os trechos de cada fonte depois de gerar todos
os novos vetores com sucesso. Se houver falha, as fontes já concluídas ficam
salvas e você pode executar novamente. O mesmo caminho de arquivo identifica
uma fonte; mover ou renomear o arquivo cria outra fonte, então remova a anterior.

## Conferir e remover

```powershell
.\IA.bat knowledge:status
.\IA.bat knowledge:forget document:CHAVE_EXIBIDA_NO_STATUS
```

O último comando remove só os trechos daquele material, preservando o arquivo.
Posts excluídos removem seus trechos por chave estrangeira. Posts com título ou texto editados,
reprovados, de perfis suspensos, privados para o leitor ou com bloqueios entre
as contas são excluídos da consulta imediatamente. Posts de perfis privados
ou suspensos não são enviados para indexação.

## Perguntar

Entre no site e abra a Intellia. A resposta mostra fontes numeradas; publicações
têm links e PDFs indicam título e página. O endpoint também aceita
`subject_id` para restringir a disciplina:

```http
POST /api/chat
Authorization: Bearer TOKEN_DA_CONTA
Content-Type: application/json

{"message":"O que é uma célula?","subject_id":1}
```

Sem trechos relacionados, a resposta informa que não encontrou material e
oferece orientações gerais de estudo sem inventar fontes. Falhas de serviço
retornam 503. O limite do endpoint é de dez perguntas por minuto por conta.
`RAG_MAX_DISTANCE` varia de 0 a 2; valores menores tornam o filtro mais exigente.
O padrão 0,45 é um ponto inicial, a ajustar com perguntas reais do acervo.
A busca inicial é exata, adequada à base pequena; não há índice aproximado HNSW.

## Testar sem usar a cota

Na pasta `api`, com as migrations aplicadas e o PostgreSQL ativo:

```powershell
& '..\runtime\php\php.exe' -c '..\runtime\php\php.ini' -d "extension_dir=$PWD\..\runtime\php\ext" tests/rag-postgres.php
```

O teste usa respostas simuladas do Gemini, PostgreSQL real e uma transação
revertida ao final. Cobre PDF, trechos, indexação incremental, fontes, distância,
edição, moderação, privacidade, bloqueios, troca de modelo e falhas do provedor.

Referências: [embeddings do Gemini](https://ai.google.dev/gemini-api/docs/embeddings),
[pgvector e distância de cosseno](https://github.com/pgvector/pgvector),
[níveis de raciocínio do Gemini](https://ai.google.dev/gemini-api/docs/thinking),
[extrator PDF](https://github.com/smalot/pdfparser).
