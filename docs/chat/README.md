# Assistente DOMBAG (chat com IA)

Chat flutuante (canto inferior direito, todas as páginas logadas) e página **Assistente IA** (`/assistente`, no menu lateral, com o histórico de conversas) que responde sobre vendas, pedidos,
faturamento e produção consultando o ERP Yzidro, e gera relatórios em PDF/Excel.

Dois provedores de IA, escolhidos por `CHAT_PROVEDOR` (só a camada da IA muda; consultas, escopo,
relatórios e widget são os mesmos):

| Provedor | Modelo padrão | Custo | Observação |
|---|---|---|---|
| **Google Gemini** (`gemini`) | `gemini-3.5-flash-lite` (reserva: `gemini-3.8-flash`) | **Plano gratuito** (com limite diário) | No plano gratuito o Google **pode usar o conteúdo para melhorar os produtos dele** — inclui nomes de clientes e valores enviados ao chat. No plano pago, não. |
| Anthropic Claude (`anthropic`) | `claude-sonnet-5-5` | Pago por uso | A Anthropic não usa dados da API para treino. |

Trocar de provedor não exige mudar código; conversas antigas continuam visíveis, e a próxima pergunta abre uma conversa nova.

| Peça | Arquivo |
|---|---|
| Endpoint do chat (`/api/chat`, streaming SSE) | `modules/chat/api.php` |
| Laço com a IA (Claude), limites, log, conversas | `modules/chat/chat_lib.php` |
| Laço com a IA (Gemini) | `modules/chat/chat_gemini.php` |
| Ferramentas de consulta (SQL fixo) e escopo | `modules/chat/chat_ferramentas.php` |
| Relatórios PDF/XLSX, gráficos, links | `modules/chat/chat_relatorio.php` |
| Download (`/api/chat/relatorio/<token>`) | `modules/chat/relatorio.php` |
| Widget flutuante e página `/assistente` | `public/js/chat-widget.js` (incluído por `shared/sidebar.php`), `modules/assistente/assistente.php` |
| Tabelas (MySQL) | `migrations/2026_10_09_01_chat_ia.php`, `..._02_chat_relatorios.php` |
| Testes / exemplos | `tests/chat/`, `docs/chat/exemplos/` |

## Configuração

**Pela tela (recomendado):** Parâmetros do Sistema → aba **Assistente IA** (só administradores).
Provedor, chaves de API, modelos, raciocínio, limites e usuário do ERP para o chat valem na hora.
As chaves ficam criptografadas no banco (AES-256-GCM); a chave de criptografia fica no arquivo
`dombag.chave`, criado automaticamente ao lado do `dombag.env.php` — se ele for apagado, redigite as
chaves de API na tela. O botão **Testar conexão** confere chave e modelo sem enviar dados da empresa.

Ordem de prioridade de cada valor: variável de ambiente do servidor → tela de Parâmetros →
`dombag.env.php` → padrão do sistema. Campo em branco na tela = usa o arquivo/padrão.

**Só no arquivo `dombag.env.php`** (um nível acima da raiz do site, fora do git e do rsync do deploy):
as credenciais dos bancos (`MYSQL*`, `PG_*`) — o sistema precisa delas para conectar ao banco antes
de conseguir ler a tela — e `CHAT_REPORTS_DIR`. Modelo: `config/dombag.env.example.php`.
As variáveis abaixo também podem ir no arquivo (útil no primeiro deploy, antes de abrir a tela).

| Variável | Padrão | Para quê |
|---|---|---|
| `CHAT_PROVEDOR` | `gemini` se houver `GEMINI_API_KEY` | `gemini` ou `anthropic`. |
| `GEMINI_API_KEY` | — | Chave do Google AI Studio (provedor Gemini). Sem chave do provedor, o botão do chat não aparece. |
| `GEMINI_MODEL` | `gemini-3.5-flash-lite` | Modelo principal (rápido, mais cota gratuita). |
| `GEMINI_MODEL_RESERVA` | `gemini-3.8-flash` | Usado automaticamente se o principal responder 503 (sobrecarga) ou 429 (cota). Vírgula separa vários. |
| `GEMINI_LIMITE_DIA` | `0` | Limite diário de requisições do plano gratuito (copie de aistudio.google.com/rate-limit). Com ele, a barrinha de uso mostra o saldo da empresa para admins; com 0, mostra só o total usado. |
| `GEMINI_THINKING` | `low` | Raciocínio (`low`/`medium`/`high`; vazio = padrão do modelo). |
| `ANTHROPIC_API_KEY` | — | Chave do Claude (provedor Anthropic). |
| `CLAUDE_MODEL` | `claude-sonnet-5-5` | Modelo usado. Mais barato: `claude-haiku-5-5`. |
| `CLAUDE_EFFORT` | `low` | Profundidade de raciocínio (`low`/`medium`/`high`). Mais alto = mais caro. |
| `CHAT_MSG_POR_HORA` | `20` | Mensagens por usuário por hora. |
| `CHAT_MSG_POR_HORA_IP` | `120` | Mensagens por IP por hora (a fábrica toda sai pelo mesmo IP). |
| `CHAT_RELATORIOS_POR_DIA` | `5` | Relatórios por usuário por dia. |
| `CHAT_MAX_CHARS` / `CHAT_MAX_TURNOS` | `2000` / `20` | Tamanho da mensagem / perguntas por conversa. |
| `CHAT_REPORTS_DIR` | `<acima da raiz>/dombag_storage/relatorios` | Onde os arquivos ficam (24h). |
| `CHAT_PG_USER` / `CHAT_PG_PASS` | usa `PG_USER` | Usuário Postgres somente leitura do chat (ver abaixo). |
| `MYSQL*`, `PG_*` | `config/db.*.php` | Opcional: credenciais dos bancos, para tirar `db.production.php` do git. |

## Criar a chave do Gemini (gratuito)

1. Entre em **aistudio.google.com** com uma conta Google da empresa.
2. **Get API key → Create API key** (cria um projeto no Google Cloud se ainda não houver). Copie a chave.
3. No `dombag.env.php`: `'CHAT_PROVEDOR' => 'gemini'` e `'GEMINI_API_KEY' => '...'`.
4. Os limites gratuitos (requisições por minuto/dia) aparecem em **aistudio.google.com/rate-limit**; são por
   projeto e zeram à meia-noite do Pacífico (~4h/5h de Brasília). Ao estourar, o chat avisa o usuário para tentar mais tarde.
5. Para os dados não serem usados pelo Google, ative o faturamento no projeto (plano pago: `gemini-3.8-flash`
   US$ 0,75 / US$ 3,75 por milhão de tokens de entrada/saída até 31/12/2026).

## Criar a chave no Claude Console

1. Entre em **platform.claude.com** (Claude Console) com a conta da empresa.
2. **Settings → Workspaces → Create workspace** "DOMBAG" e defina um **limite de gasto mensal** (ex.: US$ 50) — é o teto de custo.
3. **Settings → Billing**: cadastre o cartão / compre créditos.
4. **Settings → API Keys → Create Key** no workspace DOMBAG. Copie a chave (`sk-ant-...`) — ela só aparece uma vez.
5. Cole em `ANTHROPIC_API_KEY` no `dombag.env.php`. Nunca no código, nunca no git, nunca no navegador.

## Deploy na HostGator (cPanel)

1. **PHP 8.1+** (cPanel → MultiPHP Manager; hoje roda 8.2) com `curl`, `pgsql`, `mbstring`, `dom`. Não precisa de `zip` nem `gd`.
2. Commit/push, incluindo `vendor/` (o projeto versiona as dependências; entraram `anthropic-ai/sdk`, `guzzlehttp/guzzle`, `shuchkin/simplexlsxgen` e o dompdf foi para 3.1.6). O `.cpanel.yml` publica em `~/sistemadombag.com`.
3. No Gerenciador de Arquivos, crie **`/home/<usuario>/dombag.env.php`** (na home, *fora* de `sistemadombag.com`) a partir do modelo, com permissão **600**.
4. Abra o sistema logado: as tabelas `CHAT_*` são criadas automaticamente e o botão do chat aparece.
   A pasta `~/dombag_storage/relatorios` é criada na primeira geração de relatório.
5. Se o texto chegar "de uma vez" em vez de ir aparecendo, é buffer da hospedagem — o chat funciona igual.

**Credenciais que estão no git** (`config/db.production.php`, `config/secrets.php`): troque as senhas,
coloque as novas no `~/dombag.env.php` (`MYSQL*`, `PG_*`), faça um deploy, e **só então**
`git rm --cached config/db.production.php config/secrets.php`. Na ordem inversa o `rsync --delete`
apaga o arquivo do servidor antes de existir o substituto.

**Usuário Postgres somente leitura** — pedir ao suporte da Yzidro, depois preencher `CHAT_PG_USER/PASS`.
Até lá o chat usa `004703consulta`, que já não tem permissão de escrita, e ainda abre a conexão em
modo `read_only` com timeout de 15 s.

```sql
CREATE ROLE dombag_ia LOGIN PASSWORD '<gerar>' NOSUPERUSER NOCREATEDB NOCREATEROLE CONNECTION LIMIT 5;
ALTER ROLE dombag_ia SET default_transaction_read_only = on;
ALTER ROLE dombag_ia SET statement_timeout = '15s';
GRANT CONNECT ON DATABASE "004703_dom_bag_ltda" TO dombag_ia;
GRANT USAGE ON SCHEMA public TO dombag_ia;
GRANT SELECT ON vendas, representantes, vw_vendas, producao, itens_producao, estado_pedido TO dombag_ia;
GRANT SELECT (cli_codigo, cli_nome, cli_nome_fantasia, cli_cidade) ON clientes TO dombag_ia;
```

## Segurança (resumo)

- Só usuários logados; POST exige token CSRF; uma mensagem por vez por usuário.
- Sem SQL livre: 5 consultas fixas e parametrizadas. Parâmetros extras enviados pela IA são recusados.
- Escopo vem da sessão: admin vê tudo; representante vinculado (`REPRESENTANTES.USU_CODIGO`) só os
  próprios pedidos; demais, conforme as páginas liberadas ao grupo. Se o ERP falhar, não-admin fica sem consultas.
- Relatórios só usam números das consultas da própria conversa (fontes F1, F2…); a IA não digita valores.
- Arquivos com nome aleatório fora da pasta pública; link com token aleatório, só para quem gerou, por 24h.
- Log (`CHAT_LOG`) guarda tokens, tempo, ferramenta e nº de linhas — nunca texto de mensagem ou dado do ERP.

## Custos (estimativa)

**Gemini no plano gratuito: US$ 0**, dentro dos limites diários do AI Studio. A consulta abaixo continua
útil para ver volume (filtre por `CHL_MODELO`); os preços dela são os do Claude.

Preços do `claude-sonnet-5-5`: US$ 2 / milhão de tokens de entrada, US$ 10 / milhão de saída,
US$ 0,20 / milhão lidos do cache. Prompt fixo ≈ 2.400 tokens (sistema + ferramentas), em cache.

| Uso | Estimativa |
|---|---|
| Pergunta simples (1 consulta) | US$ 0,01 – 0,02 |
| Conversa típica (5 perguntas) | US$ 0,05 – 0,10 |
| Relatório (consulta + geração) | US$ 0,02 – 0,05 a mais |
| Ex.: 20 usuários × 10 perguntas/dia × 22 dias | ≈ US$ 60 – 90 / mês |

São estimativas — ainda não houve tráfego real. Para acompanhar o gasto de verdade:

```sql
SELECT DATE(CHL_CRIADO) AS dia, COUNT(*) AS chamadas,
       ROUND((SUM(CHL_IN) * 2 + SUM(CHL_OUT) * 10 + SUM(CHL_CACHE_READ) * 0.2 + SUM(CHL_CACHE_WRITE) * 2.5) / 1e6, 4) AS usd
  FROM CHAT_LOG WHERE CHL_TIPO = 'api'
 GROUP BY 1 ORDER BY 1 DESC;
```

## Testes

```bash
php tests/chat/run.php            # 259 verificações; usa o MySQL local e o ERP (só leitura); as APIs (Claude e Gemini) são simuladas
php tests/chat/gerar_exemplos.php # regera docs/chat/exemplos/ (dados fictícios)
```

Inclui: um usuário não acessa dados de outro (contra o ERP real), link não abre para outro usuário
nem depois de expirado, IA não consegue injetar números no relatório, XLSX sem fórmulas injetadas.

## Limitações conhecidas

- No Excel os gráficos não são desenhados (a biblioteca não suporta); os dados do gráfico viram uma aba.
- Conversas ficam guardadas sem prazo (fontes de dados: 7 dias; relatórios: 24h).
- Representante sem login vinculado é tratado como usuário comum (vê o que o grupo dele vê nas páginas).
