# API — Plataforma Escola

API Laravel 11+ com Sanctum, PostgreSQL e `pgvector`. O endpoint de relatos
(`POST /api/reports`) é público e não recebe identidade do autor.

## Execução

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
docker compose up -d postgres   # a partir da raiz do projeto
php artisan migrate --seed
php artisan serve
```

Defina `GEMINI_API_KEY` e mantenha `AI_PROVIDER=gemini` para moderação,
embeddings, tutor e filtro da ouvidoria. A API usa `gemini-3.6-flash` para
texto e `gemini-embedding-001` com vetores de 1536 dimensões. `OMBUDSMAN_FILTER_URL` é opcional; quando definido, a API envia
`{content}` via HTTP e persiste a classificação retornada. Falhas de provedores
retornam HTTP 503 de forma explícita.

## Rotas principais

- `POST /api/auth/register`, `POST /api/auth/login`, `GET /api/auth/me`
- `GET /api/posts`, `POST /api/posts`, `POST /api/posts/{post}/comments`
- `POST /api/chat` (Sanctum)
- `POST /api/reports` (anônimo); listagem/alteração somente `role=admin`

## Validação

Com PHP e dependências instalados: `php artisan test`. O teste de feature cobre
registro e rejeição de credenciais inválidas. O admin seed inicial é
`admin@escola.local` / `password`; altere em ambiente real.

## RAG do tutor

`POST /api/chat` consulta `trechos_conhecimento`, que combina publicações e
materiais PDF/TXT/Markdown. A busca usa distância de cosseno (`<=>`), filtra
modelo/provedor e recupera até cinco trechos dentro de `RAG_MAX_DISTANCE`.
A resposta retorna `rag.used`, `rag.sources` e citações `[Fonte 1]` etc.
O frontend encaminha a ação `assistant` para esse endpoint autenticado.

Veja [BANCO-VETORIAL.md](../BANCO-VETORIAL.md) para configurar, importar,
atualizar e remover materiais. A tabela antiga `vetores_publicacoes` foi
preservada, mas não é usada pela nova consulta: seus vetores não têm informação
confiável sobre modelo/provedor. Publicações devem ser reindexadas pelo comando.
