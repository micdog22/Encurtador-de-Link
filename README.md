# MicDog Shorty (PHP + HTML/JS)

Encurtador de links minimalista e completo, pronto para produção pequena ou ambiente de desenvolvimento local.

Recursos:
- Criar links curtos com alias opcional
- Redirecionamento 302 em `GET /api/go/{code}`
- Contagem e auditoria de cliques (data/hora, IP, user-agent, referer)
- Estatísticas globais e por link (totais e série diária)
- Listagem, edição e remoção de links
- Exportação CSV de links e cliques
- Banco SQLite via PDO (zero dependências)
- API REST com proteção CSRF
- Frontend HTML/CSS/JS leve

## Requisitos
- PHP 8.1+ com PDO SQLite habilitado
- Navegador moderno

## Como rodar
Na raiz do projeto:

```bash
php -S localhost:8080 -t .
```

Abra o painel em:
```
http://localhost:8080/public/
```
O redirecionamento curto será:
```
http://localhost:8080/api/go/{code}
```

Observação: em produção você pode configurar o servidor (Nginx/Apache) para mapear `/r/{code}` para `/api/go/{code}` e ter uma URL mais curta.

## API (resumo)
Todas as requisições não-GET precisam do header `X-CSRF-Token` obtido em `GET /api/csrf`.

- `GET /api/csrf`
- `GET /api/links?q=...` — lista links
- `POST /api/links` — cria link `{url, title?, code?}`
- `PUT /api/links/{id}` — atualiza
- `DELETE /api/links/{id}` — remove
- `GET /api/stats` — estatísticas gerais
- `GET /api/stats/{id}` — estatísticas por link
- `GET /api/export/links` — CSV de links
- `GET /api/export/clicks?link_id=...` — CSV de cliques
- `GET /api/go/{code}` — redireciona e registra clique

## Banco de dados
Criado automaticamente no primeiro uso:
- `links(id, code, url, title, clicks_count, created_at, updated_at)`
- `clicks(id, link_id, at, ip, ua, ref)`

## Segurança
- CSRF por sessão para métodos mutáveis
- Prepared statements em todas as queries
- Nenhum dado sensível armazenado além de IP/UA/Referer para auditoria

## Estrutura
```
micdog-shorty-php/
├─ README.md
├─ .gitignore
├─ data/
├─ api/
│  └─ index.php
└─ public/
   ├─ index.html
   ├─ app.css
   └─ app.js
```

## Licença
MIT
