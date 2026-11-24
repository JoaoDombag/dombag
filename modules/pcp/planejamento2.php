<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/agendamento_dados.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/etapa_cores.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Reunião de Planejamento (Programação de Pedidos)
//  Grid local (MySQL) de pedidos/OPs adicionados à programação.
//  Candidatos importados do ERP PostgreSQL (tabela PRODUCAO), somente leitura.
//  "Marcar como finalizado" é um controle local — não altera o ERP.
// ══════════════════════════════════════════════════════════════

function p2Escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function p2SanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function p2FmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function p2FmtDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function p2EnsureTabela(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS PCP_PROGRAMACAO (
            PRG_CODIGO          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            PROD_CODIGO         INT UNSIGNED  NOT NULL,
            VENDA_REF           VARCHAR(40)   NULL,
            VEN_COD_PEDIDO      VARCHAR(40)   NULL,
            CLI_CODIGO          INT           NULL,
            CLI_NOME            VARCHAR(255)  NULL,
            PROD_DATA           DATE          NULL,
            DATA_ENTREGA        DATE          NULL,
            DATA_ENTREGA_VENDEDOR DATE        NULL,
            DATA_ENTREGA_ESPERADA DATE        NULL,
            PROD_TOTAL          DECIMAL(14,2) NULL,
            TIPO_PRODUCAO       VARCHAR(5)    NULL,
            PRG_ADICIONADO_EM   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRG_ADICIONADO_POR  VARCHAR(120)  NULL,
            PRG_FINALIZADO      TINYINT(1)    NOT NULL DEFAULT 0,
            PRG_FINALIZADO_EM   DATETIME      NULL,
            PRG_FINALIZADO_POR  VARCHAR(120)  NULL,
            INDEX idx_prod (PROD_CODIGO),
            INDEX idx_finalizado (PRG_FINALIZADO)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach (['DATA_ENTREGA_VENDEDOR', 'DATA_ENTREGA_ESPERADA', 'DATA_INICIO_PRODUCAO'] as $coluna) {
        $chk = $db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $chk->execute(['PCP_PROGRAMACAO', $coluna]);
        if (!(int) $chk->fetchColumn()) {
            $db->exec("ALTER TABLE PCP_PROGRAMACAO ADD COLUMN {$coluna} DATE NULL");
        }
    }

    $chkCol = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $chkCol->execute(['PCP_PROGRAMACAO', 'ETP_CODIGO']);
    if (!(int) $chkCol->fetchColumn()) {
        $db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN ETP_CODIGO INT UNSIGNED NULL DEFAULT NULL');
    }

    $chkFk = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $chkFk->execute(['PCP_PROGRAMACAO', 'FK_PRG_ETAPA']);
    if (!(int) $chkFk->fetchColumn()) {
        try {
            $db->exec('
                ALTER TABLE PCP_PROGRAMACAO
                ADD CONSTRAINT FK_PRG_ETAPA
                FOREIGN KEY (ETP_CODIGO) REFERENCES ETAPA_PRODUCAO (ETP_CODIGO)
                ON DELETE SET NULL ON UPDATE CASCADE
            ');
        } catch (Throwable) {
            // ETAPA_PRODUCAO pode ainda não existir num primeiro carregamento; a FK é adicionada
            // assim que a migration correspondente rodar.
        }
    }

    // Pedidos programados antes da coluna existir começam como "Pendente de produção"
    $etpPadrao = p2EtapaPadraoCodigo($db);
    if ($etpPadrao !== null) {
        $db->prepare('UPDATE PCP_PROGRAMACAO SET ETP_CODIGO = ? WHERE ETP_CODIGO IS NULL')
           ->execute([$etpPadrao]);
    }
}

// ── Etapa "Pendente de produção" (padrão para OPs recém-adicionadas) ─────────
function p2EtapaPadraoCodigo(PDO $db): ?int
{
    try {
        $cod = $db->query("SELECT ETP_CODIGO FROM ETAPA_PRODUCAO WHERE ETP_DESCRICAO = 'Pendente de produção' LIMIT 1")
                  ->fetchColumn();
        return $cod !== false ? (int) $cod : null;
    } catch (Throwable) {
        return null;
    }
}

// ── ERP: OPs candidatas para importar/programar (FINALIZADO = 'N') ───────────
function p2FetchOPsDisponiveis($pg, array $filtros, array $excluirCodigos): array
{
    $params = [];
    $extras = [];

    if ($filtros['cod_op'] !== '') {
        $params[] = (int) $filtros['cod_op'];
        $extras[] = 'V.PROD_CODIGO = $' . count($params);
    }
    if ($filtros['cod_venda'] !== '') {
        $params[] = '%' . $filtros['cod_venda'] . '%';
        $n = count($params);
        $extras[] = "(V.VENDA_REF ILIKE \${$n} OR V.VEN_COD_PEDIDO::text ILIKE \${$n})";
    }
    if ($filtros['cliente'] !== '') {
        $params[] = '%' . $filtros['cliente'] . '%';
        $extras[] = 'C.CLI_NOME ILIKE $' . count($params);
    }
    foreach ($excluirCodigos as $cod) {
        $extras[] = 'V.PROD_CODIGO <> ' . (int) $cod;
    }

    $where = 'V.EMP_CODIGO = 1 AND TRIM(V.FINALIZADO) = \'N\'';
    if ($extras) {
        $where .= "\n       AND " . implode("\n       AND ", $extras);
    }

    $sql = "
        SELECT V.PROD_CODIGO, V.PROD_DATA, V.PROD_TOTAL, V.PROD_OBS, V.PROD_TIPO, V.FINALIZADO
              ,V.DATA_ENTREGA, V.VEN_COD_PEDIDO, C.CLI_CODIGO, C.CLI_NOME, V.LOTE_CLIENTE
              ,V.VENDA_REF, V.TIPO_PRODUCAO
          FROM      PRODUCAO       V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO   = V.CLI_CODIGO
         WHERE {$where}
         ORDER BY V.PROD_CODIGO DESC
         LIMIT 200
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as OPs no ERP: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── ERP: dados atuais de uma única OP (usado ao adicionar/exibir na grid) ────
function p2FetchOPUnica($pg, int $prodCodigo): ?array
{
    $sql = "
        SELECT V.PROD_CODIGO, V.PROD_DATA, V.PROD_TOTAL, V.PROD_TIPO, V.FINALIZADO
              ,V.DATA_ENTREGA, V.VEN_COD_PEDIDO, C.CLI_CODIGO, C.CLI_NOME
              ,V.VENDA_REF, V.TIPO_PRODUCAO
          FROM      PRODUCAO       V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO   = V.CLI_CODIGO
         WHERE V.EMP_CODIGO = 1 AND V.PROD_CODIGO = $1
    ";
    $res = @pg_query_params($pg, $sql, [$prodCodigo]);
    if (!$res) {
        return null;
    }
    $row = pg_fetch_assoc($res) ?: null;
    pg_free_result($res);
    return $row ?: null;
}

// ── Grid local: pedidos programados (ativos), com dados atualizados do ERP ───
function p2FetchProgramacao(PDO $db, $pg): array
{
    $stmt = $db->query('
        SELECT p.PRG_CODIGO, p.PROD_CODIGO, p.VENDA_REF, p.VEN_COD_PEDIDO, p.CLI_CODIGO, p.CLI_NOME,
               p.PROD_DATA, p.DATA_ENTREGA, p.DATA_ENTREGA_ESPERADA,
               p.DATA_INICIO_PRODUCAO, p.PROD_TOTAL, p.TIPO_PRODUCAO,
               p.PRG_ADICIONADO_EM, p.PRG_ADICIONADO_POR,
               p.ETP_CODIGO, e.ETP_DESCRICAO
        FROM PCP_PROGRAMACAO p
        LEFT JOIN ETAPA_PRODUCAO e ON e.ETP_CODIGO = p.ETP_CODIGO
        WHERE p.PRG_FINALIZADO = 0
        ORDER BY p.DATA_ENTREGA_ESPERADA IS NULL, p.DATA_ENTREGA_ESPERADA, p.DATA_ENTREGA IS NULL, p.DATA_ENTREGA, p.PROD_CODIGO
    ');
    $locais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$locais || !$pg) {
        return array_map(static function (array $l): array {
            $l = array_change_key_case($l, CASE_LOWER);
            $l['finalizado_erp'] = null;
            return $l;
        }, $locais);
    }

    $codigos = array_map(static fn ($l) => (int) $l['PROD_CODIGO'], $locais);
    $codigosSql = implode(',', $codigos);

    $sql = "
        SELECT V.PROD_CODIGO, V.PROD_DATA, V.PROD_TOTAL, V.PROD_TIPO, V.FINALIZADO
              ,V.DATA_ENTREGA, V.VEN_COD_PEDIDO, C.CLI_CODIGO, C.CLI_NOME, V.VENDA_REF, V.TIPO_PRODUCAO
          FROM      PRODUCAO       V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO   = V.CLI_CODIGO
         WHERE V.PROD_CODIGO IN ({$codigosSql})
    ";
    $res = @pg_query($pg, $sql);
    $erpByCodigo = [];
    if ($res) {
        foreach (pg_fetch_all($res) ?: [] as $r) {
            $erpByCodigo[(int) $r['prod_codigo']] = $r;
        }
        pg_free_result($res);
    }

    $out = [];
    foreach ($locais as $l) {
        $l = array_change_key_case($l, CASE_LOWER);
        $erp = $erpByCodigo[(int) $l['prod_codigo']] ?? null;
        if ($erp) {
            $l['cli_nome'] = $erp['cli_nome'] ?? $l['cli_nome'];
            $l['cli_codigo'] = $erp['cli_codigo'] ?? $l['cli_codigo'];
            $l['data_entrega'] = $erp['data_entrega'] ?? $l['data_entrega'];
            $l['prod_total'] = $erp['prod_total'] ?? $l['prod_total'];
            $l['venda_ref'] = $erp['venda_ref'] ?? $l['venda_ref'];
            $l['ven_cod_pedido'] = $erp['ven_cod_pedido'] ?? $l['ven_cod_pedido'];
            $l['tipo_producao'] = $erp['tipo_producao'] ?? $l['tipo_producao'];
            $l['finalizado_erp'] = trim((string) $erp['finalizado']);
        } else {
            $l['finalizado_erp'] = null;
        }
        $out[] = $l;
    }
    return $out;
}

$pdo = dbPDO();
p2EnsureTabela($pdo);
$pg = dbPG();

// ── AJAX: buscar OPs candidatas (modal "Adicionar Pedido à Programação") ─────
if (($_GET['action'] ?? '') === 'buscar_ops') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    $filtros = [
        'cod_op'    => trim((string) ($_GET['cod_op'] ?? '')),
        'cod_venda' => trim((string) ($_GET['cod_venda'] ?? '')),
        'cliente'   => trim((string) ($_GET['cliente'] ?? '')),
    ];
    try {
        $stmt = $pdo->query('SELECT PROD_CODIGO FROM PCP_PROGRAMACAO WHERE PRG_FINALIZADO = 0');
        $jaAdicionados = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $rows = p2FetchOPsDisponiveis($pg, $filtros, $jaAdicionados);
        echo json_encode(['ops' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: adicionar OP à programação ──────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'adicionar') {
    header('Content-Type: application/json; charset=utf-8');
    $prodCodigo = (int) ($_POST['prod_codigo'] ?? 0);
    if ($prodCodigo <= 0) {
        echo json_encode(['error' => 'OP inválida.']);
        exit;
    }
    $dataEntregaEsperada = p2SanitizeDate($_POST['data_entrega_esperada'] ?? '');
    $dataInicio = p2SanitizeDate($_POST['data_inicio_producao'] ?? '');
    if ($dataEntregaEsperada === '') {
        echo json_encode(['error' => 'Informe a Data de entrega esperada.']);
        exit;
    }
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM PCP_PROGRAMACAO WHERE PROD_CODIGO = ? AND PRG_FINALIZADO = 0');
        $stmt->execute([$prodCodigo]);
        if ((int) $stmt->fetchColumn() > 0) {
            echo json_encode(['error' => 'Esta OP já está na programação.']);
            exit;
        }

        $op = p2FetchOPUnica($pg, $prodCodigo);
        if (!$op) {
            echo json_encode(['error' => 'OP não encontrada no ERP.']);
            exit;
        }
        if (trim((string) $op['finalizado']) !== 'N') {
            echo json_encode(['error' => 'Esta OP já está finalizada no ERP e não pode ser importada.']);
            exit;
        }

        $ins = $pdo->prepare('
            INSERT INTO PCP_PROGRAMACAO
                (PROD_CODIGO, VENDA_REF, VEN_COD_PEDIDO, CLI_CODIGO, CLI_NOME, PROD_DATA, DATA_ENTREGA,
                 DATA_ENTREGA_ESPERADA, DATA_INICIO_PRODUCAO, PROD_TOTAL, TIPO_PRODUCAO, ETP_CODIGO, PRG_ADICIONADO_POR)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ins->execute([
            $prodCodigo,
            $op['venda_ref'],
            $op['ven_cod_pedido'],
            $op['cli_codigo'],
            $op['cli_nome'],
            $op['prod_data'] ? substr((string) $op['prod_data'], 0, 10) : null,
            $op['data_entrega'] ? substr((string) $op['data_entrega'], 0, 10) : null,
            $dataEntregaEsperada,
            $dataInicio ?: null,
            null,
            $op['tipo_producao'],
            p2EtapaPadraoCodigo($pdo),
            usuNome(),
        ]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: marcar como finalizado (local, não altera o ERP) ───────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'finalizar') {
    header('Content-Type: application/json; charset=utf-8');
    $prgCodigo = (int) ($_POST['prg_codigo'] ?? 0);
    try {
        $stmt = $pdo->prepare('
            UPDATE PCP_PROGRAMACAO
               SET PRG_FINALIZADO = 1, PRG_FINALIZADO_EM = NOW(), PRG_FINALIZADO_POR = ?
             WHERE PRG_CODIGO = ? AND PRG_FINALIZADO = 0
        ');
        $stmt->execute([usuNome(), $prgCodigo]);
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: atualizar etapa de produção de um pedido programado ────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'atualizar_etapa') {
    header('Content-Type: application/json; charset=utf-8');
    $prgCodigo = (int) ($_POST['prg_codigo'] ?? 0);
    $etpCodigo = (int) ($_POST['etp_codigo'] ?? 0);
    try {
        if (!$prgCodigo || !$etpCodigo) {
            throw new RuntimeException('Dados inválidos.');
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM ETAPA_PRODUCAO WHERE ETP_CODIGO = ?');
        $chk->execute([$etpCodigo]);
        if (!(int) $chk->fetchColumn()) {
            throw new RuntimeException('Etapa de produção inválida.');
        }
        $stmt = $pdo->prepare('UPDATE PCP_PROGRAMACAO SET ETP_CODIGO = ? WHERE PRG_CODIGO = ?');
        $stmt->execute([$etpCodigo, $prgCodigo]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: datas do pedido, as mesmas do Agendamento ─────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'atualizar_data') {
    header('Content-Type: application/json; charset=utf-8');
    $prgCodigo = (int) ($_POST['prg_codigo'] ?? 0);
    try {
        $stmt = $pdo->prepare('SELECT DATA_INICIO_PRODUCAO FROM PCP_PROGRAMACAO WHERE PRG_CODIGO = ?');
        $stmt->execute([$prgCodigo]);
        $inicio = (string) $stmt->fetchColumn();
        $valor = (string) ($_POST['valor'] ?? '');
        match ((string) ($_POST['campo'] ?? '')) {
            'inicio'  => agMarcarDatas($pdo, $prgCodigo, $valor),
            'entrega' => agMarcarDatas($pdo, $prgCodigo, $inicio, $valor),
            default   => throw new RuntimeException('Data desconhecida.'),
        };
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Lista de etapas de produção (para o seletor da grid) ─────────────────────
$etapasProducao = etpListar($pdo);
$corEtapa = array_column($etapasProducao, 'ETP_COR', 'ETP_CODIGO');

// ── Carga inicial da página ────────────────────────────────────────────────────
$error = '';
$programacao = [];
$progresso = [];
try {
    $programacao = p2FetchProgramacao($pdo, $pg);
    $progresso = agProgresso($pdo, $pg, array_column($programacao, 'prod_codigo', 'prg_codigo'));
} catch (Throwable $e) {
    $error = $e->getMessage();
}
// Tipos de cada pedido (vêm dos itens da OP no ERP) e quantos há de cada
$tiposPorPrg = [];
$qtdPorTipo = [PROD_TIPO_BAG => 0, PROD_TIPO_SACARIA => 0];
foreach ($programacao as $r) {
    $t = array_keys($progresso[(int) $r['prg_codigo']]['tipos'] ?? []);
    $tiposPorPrg[(int) $r['prg_codigo']] = $t;
    foreach ($t as $tt) {
        $qtdPorTipo[$tt] = ($qtdPorTipo[$tt] ?? 0) + 1;
    }
}
$nomeTipo = [PROD_TIPO_BAG => 'Big Bag', PROD_TIPO_SACARIA => 'Sacaria'];

if (!$pg && $error === '') {
    $error = 'ERP indisponível — exibindo apenas dados salvos localmente (podem estar desatualizados).';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reunião de Planejamento | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/shared/app_icone.php'; appHeadTags(); ?>
<style>
  .p2-cod { color: var(--text-muted); font-size: 11px; }
  .p2-empty-row td { text-align: center; padding: 28px 16px; color: var(--text-muted); font-size: 12.5px; }

  .status-sel {
    font-size: 11px; padding: 3px 6px; border-radius: 6px; font-weight: 600;
    border: 1px solid var(--border); border-left: 4px solid var(--etp, var(--border));
    background: var(--card-bg);
    color: var(--text-primary); cursor: pointer; display: block;
  }
  /* a lista aberta é nativa e não aceita fundo transparente: usa cor sólida do tema */
  .status-sel { color-scheme: dark; }
  :root[data-theme="claro"] .status-sel { color-scheme: light; }
  .status-sel option { background: var(--modal-bg, #152845); color: var(--text-primary); }

  /* Big Bag e Sacaria: as mesmas cores do Agendamento */
  .tipo-BAG     { --tipo: 34,197,94;  --tipo-txt: #4ade80; }
  .tipo-SACARIA { --tipo: 168,85,247; --tipo-txt: #c084fc; }
  :root[data-theme="claro"] .tipo-BAG     { --tipo-txt: #15803d; }
  :root[data-theme="claro"] .tipo-SACARIA { --tipo-txt: #7e22ce; }
  .p2-tipos { display: flex; gap: 8px; align-items: center; margin-left: auto; }
  .p2-tipo-btn {
    display: inline-flex; align-items: center; gap: 7px; height: 28px; padding: 0 11px; border-radius: 8px; cursor: pointer;
    border: 1px solid rgba(var(--tipo), .45); background: rgba(var(--tipo), .16);
    color: var(--text-primary); font: 600 12px 'Segoe UI', sans-serif;
  }
  .p2-tipo-btn::before { content: ''; width: 10px; height: 10px; border-radius: 3px; background: rgb(var(--tipo)); }
  .p2-tipo-btn b { font-weight: 700; color: var(--text-muted); }
  .p2-tipo-btn[aria-pressed="false"] { background: none; border-color: var(--border); color: var(--text-muted); text-decoration: line-through; }
  .p2-tipo-btn[aria-pressed="false"]::before { opacity: .3; }
  .p2-tipo-btn:focus-visible { outline: 2px solid #7db3ff; outline-offset: 2px; }
  /* Tipo do produto: faixa na borda esquerda da linha (metade de cada cor quando a OP tem os dois) */
  #p2Tbody td.p2-cod { padding-left: 16px; background: linear-gradient(rgb(var(--t1)) 0 50%, rgb(var(--t2)) 50% 100%) left / 5px 100% no-repeat; }
  .p2-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .p2-ordem { font-size: 11.5px; color: var(--text-muted); font-weight: 400; margin-left: 8px; }

  /* ── Filtros ─────────────────────────────────────────── */
  .p2-filtros { display: flex; flex-wrap: wrap; gap: 10px 12px; align-items: flex-end; padding: 12px 18px; border-bottom: 1px solid var(--border); }
  .p2-filtro { display: flex; flex-direction: column; gap: 5px; }
  .p2-filtro label { font-size: 11px; font-weight: 600; color: var(--text-muted); }
  .p2-filtro select, .p2-filtro input { height: 32px; padding: 0 9px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; border-radius: 7px; color-scheme: dark; }
  :root[data-theme="claro"] .p2-filtro select, :root[data-theme="claro"] .p2-filtro input { color-scheme: light; }
  .p2-filtro select option { background: var(--modal-bg, #152845); color: var(--text-primary); }
  .p2-filtro select { min-width: 150px; }
  .p2-filtro input[type="search"] { width: 230px; }
  .p2-filtro select.ativo, .p2-filtro input.ativo { border-color: #7db3ff; box-shadow: 0 0 0 1px #7db3ff inset; }
  .p2-limpar { height: 32px; padding: 0 12px; border-radius: 7px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); font: 600 12px 'Segoe UI', sans-serif; cursor: pointer; }
  .p2-limpar:hover { color: var(--text-primary); border-color: #7db3ff; }
  .p2-limpar[hidden] { display: none; }
  .p2-filtro select:focus-visible, .p2-filtro input:focus-visible, .p2-limpar:focus-visible { outline: 2px solid #7db3ff; outline-offset: 2px; }
  .p2-nada td { text-align: center; padding: 28px 16px; color: var(--text-muted); font-size: 12.5px; }
  @media (max-width: 700px) { .p2-filtro, .p2-filtro select, .p2-filtro input[type="search"] { width: 100%; } }

  /* ── Modal grande: buscar/adicionar pedidos ──────────────────────────────── */
  .p2-modal-box { width: 1180px; max-width: calc(100vw - 32px); max-height: 85vh; display: flex; flex-direction: column; }
  .p2-modal-body { overflow-y: auto; margin-top: 14px; flex: 1; min-height: 0; }
  .p2-filter-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px; }
  .p2-filter-field { display: flex; flex-direction: column; gap: 6px; }
  .p2-filter-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
  .p2-filter-field input { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; width: 170px; }
  #p2ResultsBody td:nth-child(2) { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .p2-progresso { min-width: 190px; font-size: 12px; line-height: 1.5; }
  .p2-progresso a { white-space: nowrap; }
  #p2ResultsBody input[type="date"], #p2Tbody input[type="date"] { width: 130px; height: 30px; padding: 0 6px; font-size: 11.5px; }
  .p2-add-btn { padding: 5px 12px; height: 28px; border-radius: 7px; border: none; background: var(--blue-accent, #1e4fc9); color: #fff; font-family: 'Segoe UI', sans-serif; font-size: 11.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
  .p2-add-btn:hover { background: var(--blue-light); }
  .p2-add-btn:disabled { opacity: .5; cursor: default; }
  .p2-finalizar-btn { padding: 5px 12px; height: 28px; border-radius: 7px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); font-family: 'Segoe UI', sans-serif; font-size: 11.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
  .p2-finalizar-btn:hover { background: rgba(0,201,167,.12); color: var(--teal); border-color: rgba(0,201,167,.3); }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Reunião de Planejamento</h1>
          <p>Programação de pedidos — controle local</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/pcp/agendamento" class="btn-secondary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:5px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Agendamento de Produção
        </a>
        <a href="/pcp/etapas" class="btn-secondary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:5px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Etapas de Produção
        </a>
        <button type="button" class="btn-refresh" id="btnAbrirBusca">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:5px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Adicionar Pedido à Programação
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= p2Escape($error) ?>
        </div>
      <?php endif; ?>

      <div class="panel-table">
        <div class="panel-header">
          <span class="panel-title">Pedidos Programados <span class="p2-ordem">pela entrega esperada</span></span>
          <span class="filter-count-badge" id="p2CountBadge" style="background:rgba(45,106,255,.12);color:#7db3ff;"><?= count($programacao) ?></span>
          <div class="p2-tipos" role="group" aria-label="Mostrar Big Bag ou Sacaria">
            <?php foreach ($nomeTipo as $tipo => $nome): ?>
              <button type="button" class="p2-tipo-btn tipo-<?= $tipo ?>" data-tipo="<?= $tipo ?>" aria-pressed="true"><?= $nome ?> <b><?= (int) ($qtdPorTipo[$tipo] ?? 0) ?></b></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($programacao): ?>
        <div class="p2-filtros" id="p2Filtros">
          <div class="p2-filtro">
            <label for="p2FMes">Mês da entrega esperada</label>
            <select id="p2FMes"><option value="">Todos os meses</option></select>
          </div>
          <div class="p2-filtro">
            <label for="p2FEtapa">Etapa</label>
            <select id="p2FEtapa">
              <option value="">Todas as etapas</option>
              <?php foreach ($etapasProducao as $etp): ?>
                <option value="<?= (int) $etp['ETP_CODIGO'] ?>"><?= p2Escape($etp['ETP_DESCRICAO']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="p2-filtro">
            <label for="p2FSituacao">Situação</label>
            <select id="p2FSituacao">
              <option value="">Todas</option>
              <option value="atrasado">Entrega atrasada</option>
              <option value="semana">Entrega nos próximos 7 dias</option>
              <option value="saldo">Falta agendar</option>
              <option value="sem_inicio">Sem início da produção</option>
              <option value="concluido">Produção concluída</option>
            </select>
          </div>
          <div class="p2-filtro">
            <label for="p2FBusca">Buscar</label>
            <input type="search" id="p2FBusca" placeholder="OP, cliente ou pedido" autocomplete="off">
          </div>
          <button type="button" class="p2-limpar" id="p2FLimpar" hidden>Limpar filtros</button>
        </div>
        <?php endif; ?>
        <div class="table-wrap">
          <table class="tabela-cards">
            <thead>
              <tr>
                <th data-destaque>Cód. OP</th>
                <th data-destaque>Cliente</th>
                <th>Pedido / Venda Ref.</th>
                <th>Emissão</th>
                <th>Entrega</th>
                <th data-destaque>Entrega Esperada</th>
                <th>Início da Produção</th>
                <th data-destaque>Andamento</th>
                <th>Etapa de Produção</th>
                <th>Adicionado em</th>
                <th>Adicionado por</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="p2Tbody">
              <?php if (!$programacao): ?>
                <tr class="p2-empty-row"><td colspan="12">Nenhum pedido na programação. Clique em "Adicionar Pedido à Programação" para começar.</td></tr>
              <?php else: ?>
                <?php foreach ($programacao as $row): ?>
                  <?php
                    $tiposRow = $tiposPorPrg[(int) $row['prg_codigo']] ?? [];
                    $corRow = $corEtapa[(int) ($row['etp_codigo'] ?? 0)] ?? null;
                    $progRow = $progresso[(int) $row['prg_codigo']] ?? null;
                    $saldoRow = array_sum(array_column($progRow['tipos'] ?? [], 'saldo'));
                    $buscaRow = mb_strtolower(implode(' ', [$row['prod_codigo'], $row['cli_nome'] ?? '', $row['cli_codigo'] ?? '', $row['venda_ref'] ?? '', $row['ven_cod_pedido'] ?? '']));
                  ?>
                  <tr id="p2-row-<?= (int) $row['prg_codigo'] ?>" data-tipos="<?= p2Escape(implode(' ', $tiposRow)) ?>"
                      data-entrega="<?= p2Escape($row['data_entrega_esperada'] ?? '') ?>" data-entrega-erp="<?= p2Escape(substr((string) ($row['data_entrega'] ?? ''), 0, 10)) ?>" data-op="<?= (int) $row['prod_codigo'] ?>"
                      data-etp="<?= (int) ($row['etp_codigo'] ?? 0) ?>" data-inicio="<?= p2Escape($row['data_inicio_producao'] ?? '') ?>"
                      data-saldo="<?= $saldoRow > 0 ? 1 : 0 ?>" data-concluido="<?= !empty($progRow['concluido']) ? 1 : 0 ?>" data-busca="<?= p2Escape($buscaRow) ?>"
                      <?= $corRow ? 'style="--etp:' . p2Escape($corRow) . '"' : '' ?>>
                    <?php
                      $rgbTipo = [PROD_TIPO_BAG => '34,197,94', PROD_TIPO_SACARIA => '168,85,247'];
                      $nomesRow = implode(' e ', array_map(static fn ($t) => $nomeTipo[$t] ?? $t, $tiposRow));
                      $t1 = $rgbTipo[$tiposRow[0] ?? ''] ?? null;
                      $t2 = $rgbTipo[$tiposRow[1] ?? ''] ?? $t1;
                    ?>
                    <td class="p2-cod"<?= $t1 ? ' style="--t1:' . $t1 . ';--t2:' . $t2 . '" title="' . p2Escape($nomesRow) . '"' : '' ?>><?= p2Escape($row['prod_codigo']) ?><?php if ($nomesRow !== ''): ?><span class="p2-sr"> (<?= p2Escape($nomesRow) ?>)</span><?php endif; ?></td>
                    <td><?= p2Escape($row['cli_nome'] ?? '—') ?> <span class="p2-cod">(<?= p2Escape($row['cli_codigo'] ?? '—') ?>)</span></td>
                    <td><?= p2Escape($row['venda_ref'] ?: ($row['ven_cod_pedido'] ?: '—')) ?></td>
                    <td><?= p2Escape(p2FmtDate($row['prod_data'] ?? '')) ?></td>
                    <td><?= p2Escape(p2FmtDate($row['data_entrega'] ?? '')) ?></td>
                    <td>
                      <input type="date" aria-label="Entrega esperada" required
                        value="<?= p2Escape($row['data_entrega_esperada'] ?? '') ?>"
                        data-prg="<?= (int) $row['prg_codigo'] ?>"
                        data-prev="<?= p2Escape($row['data_entrega_esperada'] ?? '') ?>"
                        onchange="p2AtualizarData(this, 'entrega')">
                    </td>
                    <td>
                      <input type="date" aria-label="Início da produção"
                        value="<?= p2Escape($row['data_inicio_producao'] ?? '') ?>"
                        data-prg="<?= (int) $row['prg_codigo'] ?>"
                        data-prev="<?= p2Escape($row['data_inicio_producao'] ?? '') ?>"
                        onchange="p2AtualizarData(this, 'inicio')">
                    </td>
                    <td class="p2-progresso">
                      <?php $prog = $progresso[(int) $row['prg_codigo']] ?? null; ?>
                      <?php foreach (agProgressoLinhas($prog) as $linha): ?><div><?= p2Escape($linha) ?></div><?php endforeach; ?>
                      <a class="panel-action" href="<?= p2Escape(agLinkCalendario((int) $row['prod_codigo'], $prog)) ?>">Ver no calendário</a>
                    </td>
                    <td>
                      <select class="status-sel"
                        data-prg="<?= (int) $row['prg_codigo'] ?>"
                        data-prev="<?= (int) ($row['etp_codigo'] ?? 0) ?>"
                        onchange="p2AtualizarEtapa(this)">
                        <option value="">— Selecione —</option>
                        <?php foreach ($etapasProducao as $etp): ?>
                        <option value="<?= (int) $etp['ETP_CODIGO'] ?>" data-cor="<?= p2Escape($etp['ETP_COR']) ?>" <?= (int) ($row['etp_codigo'] ?? 0) === (int) $etp['ETP_CODIGO'] ? 'selected' : '' ?>>
                          <?= p2Escape($etp['ETP_DESCRICAO']) ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td class="td-nowrap"><?= p2Escape(p2FmtDateTime($row['prg_adicionado_em'] ?? '')) ?></td>
                    <td class="td-muted"><?= p2Escape($row['prg_adicionado_por'] ?? '—') ?></td>
                    <td class="td-right">
                      <button type="button" class="p2-finalizar-btn" onclick="p2Finalizar(<?= (int) $row['prg_codigo'] ?>)">Marcar como Finalizado</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- Modal: buscar/adicionar pedidos -->
<div class="modal-overlay" id="p2ModalOverlay">
  <div class="modal-box p2-modal-box">
    <h3>Adicionar Pedido à Programação</h3>
    <p>Somente OPs ainda não finalizadas no ERP (Finalizado = N) são exibidas.</p>
    <div class="p2-modal-body">
      <div class="p2-filter-row">
        <div class="p2-filter-field">
          <label for="p2CodOp">Código OP</label>
          <input type="number" id="p2CodOp" placeholder="Ex.: 3066">
        </div>
        <div class="p2-filter-field">
          <label for="p2CodVenda">Código Venda</label>
          <input type="text" id="p2CodVenda" placeholder="Ex.: 3663">
        </div>
        <div class="p2-filter-field">
          <label for="p2Cliente">Cliente</label>
          <input type="text" id="p2Cliente" placeholder="Nome do cliente">
        </div>
        <button type="button" class="btn-refresh" id="p2BtnBuscar">Buscar</button>
      </div>
      <div class="table-wrap" style="max-height:44vh;">
        <table class="tabela-cards">
          <thead>
            <tr>
              <th data-destaque>Cód. OP</th>
              <th data-destaque>Cliente</th>
              <th data-destaque>Pedido / Venda Ref.</th>
              <th>Emissão</th>
              <th>Entrega</th>
              <th data-destaque>Entrega Esperada</th>
              <th>Início da Produção</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="p2ResultsBody">
            <tr class="p2-empty-row"><td colspan="8">Informe um filtro e clique em Buscar, ou busque sem filtros para ver as OPs mais recentes.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="icon-btn" id="p2ModalCloseBtn" style="width:auto;padding:0 14px;font-size:13px;font-weight:600;">Fechar</button>
    </div>
  </div>
</div>

<script>
function p2Esc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function p2FmtDate(v) {
  if (!v) return '—';
  const d = new Date(String(v).replace(' ', 'T'));
  if (isNaN(d.getTime())) return v;
  return d.toLocaleDateString('pt-BR');
}

// ── Modal de busca ────────────────────────────────────────────────────────────
function p2AbrirModal() {
  document.getElementById('p2ModalOverlay').classList.add('open');
  p2Buscar();
}
function p2FecharModal() {
  document.getElementById('p2ModalOverlay').classList.remove('open');
}
document.getElementById('btnAbrirBusca').addEventListener('click', p2AbrirModal);
document.getElementById('p2ModalCloseBtn').addEventListener('click', p2FecharModal);
document.getElementById('p2ModalOverlay').addEventListener('click', e => { if (e.target.id === 'p2ModalOverlay') p2FecharModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') p2FecharModal(); });

function p2Buscar() {
  const params = new URLSearchParams({
    action: 'buscar_ops',
    cod_op: document.getElementById('p2CodOp').value.trim(),
    cod_venda: document.getElementById('p2CodVenda').value.trim(),
    cliente: document.getElementById('p2Cliente').value.trim(),
  });
  const tbody = document.getElementById('p2ResultsBody');
  tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="8">Buscando...</td></tr>';

  fetch('?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        tbody.innerHTML = `<tr class="p2-empty-row"><td colspan="8">${p2Esc(data.error)}</td></tr>`;
        return;
      }
      const ops = data.ops || [];
      if (!ops.length) {
        tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="8">Nenhuma OP encontrada.</td></tr>';
        return;
      }
      tbody.innerHTML = ops.map(op => `
        <tr>
          <td class="p2-cod">${p2Esc(op.prod_codigo)}</td>
          <td title="${p2Esc(op.cli_nome || '—')}">${p2Esc(op.cli_nome || '—')}</td>
          <td>${p2Esc(op.venda_ref || op.ven_cod_pedido || '—')}</td>
          <td>${p2FmtDate(op.prod_data)}</td>
          <td>${p2FmtDate(op.data_entrega)}</td>
          <td><input type="date" class="p2-data-esperada"></td>
          <td><input type="date" class="p2-data-inicio" aria-label="Início da produção (opcional)"></td>
          <td class="td-right"><button type="button" class="p2-add-btn" onclick="p2Adicionar(${op.prod_codigo}, this)">Adicionar</button></td>
        </tr>
      `).join('');
    })
    .catch(() => {
      tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="8">Erro ao buscar OPs.</td></tr>';
    });
}
document.getElementById('p2BtnBuscar').addEventListener('click', p2Buscar);
['p2CodOp', 'p2CodVenda', 'p2Cliente'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); p2Buscar(); } });
});

function p2Adicionar(prodCodigo, btn) {
  const row = btn.closest('tr');
  const dataEsperada = row.querySelector('.p2-data-esperada').value;
  const dataInicio = row.querySelector('.p2-data-inicio').value;
  if (!dataEsperada) {
    alert('Informe a Data de entrega esperada antes de adicionar.');
    return;
  }
  btn.disabled = true;
  btn.textContent = 'Adicionando...';
  const body = new URLSearchParams({
    action: 'adicionar',
    prod_codigo: prodCodigo,
    data_entrega_esperada: dataEsperada,
    data_inicio_producao: dataInicio,
  });
  fetch('?action=adicionar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: body.toString(),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        alert(data.error);
        btn.disabled = false;
        btn.textContent = 'Adicionar';
        return;
      }
      window.location.reload();
    })
    .catch(() => {
      alert('Erro ao adicionar OP à programação.');
      btn.disabled = false;
      btn.textContent = 'Adicionar';
    });
}

// ── Marcar como finalizado (grid principal) ───────────────────────────────────
function p2Finalizar(prgCodigo) {
  if (!confirm('Marcar este pedido como finalizado e removê-lo da programação?')) return;
  fetch('?action=finalizar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=finalizar&prg_codigo=' + encodeURIComponent(prgCodigo),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error || !data.ok) {
        alert(data.error || 'Não foi possível finalizar.');
        return;
      }
      const row = document.getElementById('p2-row-' + prgCodigo);
      if (row) row.remove();
      if (window.p2AplicarTipos) window.p2AplicarTipos();
      const tbody = document.getElementById('p2Tbody');
      if (tbody && !tbody.querySelector('tr:not(.p2-empty-row)')) {
        tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="12">Nenhum pedido na programação. Clique em "Adicionar Pedido à Programação" para começar.</td></tr>';
      }
    })
    .catch(() => alert('Erro ao finalizar o pedido.'));
}

// ── Início da produção e entrega esperada de um pedido programado ────────────
function p2AtualizarData(input, campo) {
  const anterior = input.dataset.prev;
  input.disabled = true;
  fetch('?action=atualizar_data', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams({ action: 'atualizar_data', prg_codigo: input.dataset.prg, campo, valor: input.value }).toString(),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error || !data.ok) {
        alert(data.error || 'Não foi possível salvar a data.');
        input.value = anterior;
        return;
      }
      input.dataset.prev = input.value;
      if (campo === 'entrega') {
        input.closest('tr').dataset.entrega = input.value;
        p2Ordenar();
      } else {
        input.closest('tr').dataset.inicio = input.value;
      }
      if (window.p2AplicarTipos) window.p2AplicarTipos();
    })
    .catch(() => {
      alert('Erro ao salvar a data.');
      input.value = anterior;
    })
    .finally(() => { input.disabled = false; });
}

// ── Atualizar etapa de produção de um pedido programado ───────────────────────
function p2AtualizarEtapa(sel) {
  const prgCodigo = sel.dataset.prg;
  const etpCodigo = sel.value;
  const anterior = sel.dataset.prev || '';
  if (!etpCodigo) return;
  sel.disabled = true;
  fetch('?action=atualizar_etapa', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=atualizar_etapa&prg_codigo=' + encodeURIComponent(prgCodigo) + '&etp_codigo=' + encodeURIComponent(etpCodigo),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error || !data.ok) {
        alert(data.error || 'Não foi possível atualizar a etapa.');
        sel.value = anterior;
        return;
      }
      sel.dataset.prev = etpCodigo;
      sel.closest('tr').dataset.etp = etpCodigo;
      p2PintarEtapa(sel);
      if (window.p2AplicarTipos) window.p2AplicarTipos();
    })
    .catch(() => {
      alert('Erro ao atualizar a etapa.');
      sel.value = anterior;
    })
    .finally(() => { sel.disabled = false; });
}

// ── Cor da etapa: pinta a linha com a cor cadastrada em Etapas de Produção ───
function p2PintarEtapa(sel) {
  const opt = sel.options[sel.selectedIndex];
  const cor = opt && opt.dataset.cor;
  const tr = sel.closest('tr');
  if (cor) tr.style.setProperty('--etp', cor); else tr.style.removeProperty('--etp');
}

// ── Ordem pela entrega esperada (sem data no fim), depois entrega do ERP e OP ─
function p2Ordenar() {
  const tbody = document.getElementById('p2Tbody');
  const chave = tr => [tr.dataset.entrega || '9999-99-99', tr.dataset.entregaErp || '9999-99-99', String(tr.dataset.op || '').padStart(10, '0')].join('|');
  Array.from(tbody.querySelectorAll('tr[data-op]'))
    .sort((a, b) => chave(a).localeCompare(chave(b)))
    .forEach(tr => tbody.appendChild(tr));
}

// ── Filtros: Big Bag / Sacaria, mês, etapa, situação e busca ─────────────────
// Tudo no navegador; a escolha fica salva para a próxima vez (?mes=2026-11 na URL também vale).
(function () {
  const CHAVE = 'p2_tipos_ocultos';
  const CHAVE_F = 'p2_filtros';
  const botoes = Array.from(document.querySelectorAll('.p2-tipo-btn'));
  const el = { mes: 'p2FMes', etapa: 'p2FEtapa', situacao: 'p2FSituacao', busca: 'p2FBusca' };
  Object.keys(el).forEach(k => { el[k] = document.getElementById(el[k]); });
  const limpar = document.getElementById('p2FLimpar');
  const MESES = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  // datas no fuso local (toISOString sozinho é UTC e vira o dia depois das 21h)
  const diaLocal = ms => { const d = new Date(ms); return new Date(ms - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
  const hoje = diaLocal(Date.now());
  const em7 = diaLocal(Date.now() + 7 * 86400000);
  let ocultos = [];
  try { ocultos = JSON.parse(localStorage.getItem(CHAVE) || '[]'); } catch (e) { ocultos = []; }
  let salvos = {};
  try { salvos = JSON.parse(localStorage.getItem(CHAVE_F) || '{}') || {}; } catch (e) { salvos = {}; }
  const daUrl = new URLSearchParams(location.search).get('mes');
  if (daUrl) salvos.mes = daUrl;
  const linhas = () => Array.from(document.querySelectorAll('#p2Tbody tr[data-op]'));

  // Meses que existem na lista, com a quantidade de pedidos de cada um
  function montarMeses() {
    if (!el.mes) return;
    const atual = el.mes.value || salvos.mes || '';
    const qtd = {};
    linhas().forEach(tr => { const m = (tr.dataset.entrega || '').slice(0, 7) || 'sem'; qtd[m] = (qtd[m] || 0) + 1; });
    const chaves = Object.keys(qtd).filter(m => m !== 'sem').sort();
    let html = '<option value="">Todos os meses</option>';
    chaves.forEach(m => {
      const nome = MESES[parseInt(m.slice(5, 7), 10) - 1] + ' de ' + m.slice(0, 4);
      html += '<option value="' + m + '">' + nome.charAt(0).toUpperCase() + nome.slice(1) + ' (' + qtd[m] + ')</option>';
    });
    if (qtd.sem) html += '<option value="sem">Sem entrega esperada (' + qtd.sem + ')</option>';
    el.mes.innerHTML = html;
    el.mes.value = (atual === 'sem' ? qtd.sem : qtd[atual]) ? atual : '';
  }

  function passa(tr, f) {
    const tipos = (tr.dataset.tipos || '').split(' ').filter(Boolean);
    // pedido sem tipo (ERP fora do ar) continua aparecendo
    if (tipos.length && !tipos.some(t => !ocultos.includes(t))) return false;
    const ent = tr.dataset.entrega || '';
    if (f.mes === 'sem' ? ent !== '' : (f.mes && ent.slice(0, 7) !== f.mes)) return false;
    if (f.etapa && tr.dataset.etp !== f.etapa) return false;
    const concluido = tr.dataset.concluido === '1';
    switch (f.situacao) {
      case 'atrasado':   if (!(ent && ent < hoje && !concluido)) return false; break;
      case 'semana':     if (!(ent && ent >= hoje && ent <= em7 && !concluido)) return false; break;
      case 'saldo':      if (tr.dataset.saldo !== '1') return false; break;
      case 'sem_inicio': if (tr.dataset.inicio) return false; break;
      case 'concluido':  if (!concluido) return false; break;
    }
    if (f.busca && !(tr.dataset.busca || '').includes(f.busca)) return false;
    return true;
  }

  function aplicar() {
    botoes.forEach(b => b.setAttribute('aria-pressed', ocultos.includes(b.dataset.tipo) ? 'false' : 'true'));
    montarMeses();
    const f = {
      mes: el.mes ? el.mes.value : '',
      etapa: el.etapa ? el.etapa.value : '',
      situacao: el.situacao ? el.situacao.value : '',
      busca: el.busca ? el.busca.value.trim().toLowerCase() : '',
    };
    let visiveis = 0;
    linhas().forEach(tr => { const ok = passa(tr, f); tr.hidden = !ok; if (ok) visiveis++; });
    ['mes', 'etapa', 'situacao', 'busca'].forEach(k => { if (el[k]) el[k].classList.toggle('ativo', !!f[k]); });
    if (limpar) limpar.hidden = !(f.mes || f.etapa || f.situacao || f.busca);
    const badge = document.getElementById('p2CountBadge');
    if (badge) badge.textContent = visiveis;
    // aviso quando o filtro esconde tudo
    const tbody = document.getElementById('p2Tbody');
    let nada = document.getElementById('p2Nada');
    if (linhas().length && !visiveis) {
      if (!nada) {
        nada = document.createElement('tr');
        nada.id = 'p2Nada';
        nada.className = 'p2-nada';
        nada.innerHTML = '<td colspan="12">Nenhum pedido com esses filtros. Use "Limpar filtros" para ver todos.</td>';
        tbody.appendChild(nada);
      }
    } else if (nada) {
      nada.remove();
    }
    try { localStorage.setItem(CHAVE_F, JSON.stringify({ mes: f.mes, etapa: f.etapa, situacao: f.situacao })); } catch (e) {}
  }

  if (el.etapa && salvos.etapa) el.etapa.value = salvos.etapa;
  if (el.situacao && salvos.situacao) el.situacao.value = salvos.situacao;
  ['mes', 'etapa', 'situacao'].forEach(k => { if (el[k]) el[k].addEventListener('change', aplicar); });
  if (el.busca) el.busca.addEventListener('input', aplicar);
  if (limpar) limpar.addEventListener('click', () => {
    ['mes', 'etapa', 'situacao', 'busca'].forEach(k => { if (el[k]) el[k].value = ''; });
    salvos = {};
    aplicar();
  });
  botoes.forEach(b => b.addEventListener('click', () => {
    const t = b.dataset.tipo;
    ocultos = ocultos.includes(t) ? ocultos.filter(x => x !== t) : ocultos.concat(t);
    // nunca deixa os dois desligados: desligar o último liga o outro
    if (ocultos.length >= botoes.length) ocultos = botoes.map(x => x.dataset.tipo).filter(x => x !== t);
    try { localStorage.setItem(CHAVE, JSON.stringify(ocultos)); } catch (e) {}
    aplicar();
  }));
  window.p2AplicarTipos = aplicar;
  aplicar();
})();
</script>
</body>
</html>
