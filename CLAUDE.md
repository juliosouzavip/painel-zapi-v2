# painel-zapi-v2

Painel de gerenciamento de atendimento via WhatsApp usando a Z-API.

## Stack
- Node.js com ES Modules (`"type": "module"`)
- Express + body-parser + cors
- MongoDB via Mongoose
- axios para chamadas HTTP

## Estrutura
- `server.js` — servidor Express, rotas e webhook do WhatsApp
- `db.js` — conexão com MongoDB
- `database.js` — estado em memória (pendentes, motoristas, atribuições)
- `models/` — modelos Mongoose: Cliente, Motorista, Atribuicao, Mensagem

## Rotas principais
- `POST /webhook` — recebe mensagens do WhatsApp e encaminha
- `GET /pendentes` — clientes sem motorista atribuído
- `GET /motoristas` — lista de motoristas
- `POST /atribuir` — atribui cliente a motorista
- `GET /historico?cliente=&motorista=` — histórico de mensagens

## Convenções
- Iniciar com `node server.js` (porta 3000 por padrão)
- Variável de ambiente `PORT` para mudar a porta
- ZAPI_BASE está hardcoded em `server.js` — mover para `.env` se necessário

## Dicas para economizar créditos do Claude Code

### 1. CLAUDE.md (este arquivo)
Mantê-lo atualizado evita que o Claude explore o projeto do zero a cada sessão.

### 2. Prompts objetivos
Use prompts diretos e específicos:
- ❌ "Melhore o projeto"
- ✅ "Adicione validação do campo `phone` no endpoint `/atribuir`"

### 3. Compactar o histórico
Quando a conversa ficar longa, use `/compact` no Claude Code para comprimir o contexto sem perder o essencial.

### 4. Modelo mais barato para tarefas simples
No Claude Code, use `/config` → Model para escolher **claude-haiku-4-5** para tarefas simples (buscas, explicações) e Sonnet/Opus apenas para implementações complexas.

### 5. Limitar escopo das leituras
Peça ao Claude para ler apenas os arquivos relevantes, não o projeto inteiro.

### 6. Iniciar sessões novas para tarefas independentes
Cada tarefa nova em uma sessão limpa evita carregar contexto irrelevante.
