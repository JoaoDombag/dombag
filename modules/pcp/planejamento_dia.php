<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ══════════════════════════════════════════════════════════════════════════
//  DOMBAG — Planejamento do Dia (PCP)
//  Fluxo: fim do dia → planejar amanhã por máquina real → registrar produção
// ══════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();

// Garante colunas adicionadas às MAQUINAS após a criação inicial da tabela
pcpEnsureMaquinasColumns($pdo);

// ── Garante tabela ─────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS PCP_PLANEJAMENTO (
        PP_CODIGO    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        PP_DATA      DATE          NOT NULL,
        MAQ_CODIGO   INT UNSIGNED  NOT NULL,
        IV_CODIGO    INT UNSIGNED  NOT NULL,
        PP_QTDE_PLAN DECIMAL(12,2) NOT NULL DEFAULT 0,
        PP_QTDE_PROD DECIMAL(12,2) DEFAULT NULL,
        PP_STATUS    ENUM('Planejado','Registrado') NOT NULL DEFAULT 'Planejado',
        PP_OBS       TEXT,
        PP_CRIADO_EM DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pp_data (PP_DATA),
        INDEX idx_pp_maq  (MAQ_CODIGO),
        INDEX idx_pp_iv   (IV_CODIGO)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── AJAX ───────────────────────────────────────────────────────────────────
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $acao = $body['acao'] ?? '';

        // ── Buscar pedidos pendentes ───────────────────────────────────────
        if ($acao === 'get_pedidos') {
            $rows = $pdo->query("
                SELECT
                    iv.IV_CODIGO,
                    ROUND(iv.IV_QTDE) AS iv_qtde,
                    iv.IV_STATUS,
                    v.VEN_CODIGO_YZIDRO AS pedido,
                    COALESCE(NULLIF(TRIM(v.VEN_FANTASIA),''), NULLIF(TRIM(v.VEN_CLIENTE),''), '') AS cliente,
                    DATE_FORMAT(v.VEN_ENTREGA,'%d/%m/%Y')  AS entrega,
                    v.VEN_ENTREGA AS entrega_iso,
                    p.PRO_DESCRICAO,
                    p.PRO_CATEGORIA,
                    COALESCE(ps.total_produzido, 0) AS total_produzido
                FROM ITENS_VENDAS iv
                INNER JOIN VENDAS   v  ON v.VEN_CODIGO  = iv.VEN_CODIGO
                INNER JOIN PRODUTOS p  ON p.PRO_CODIGO  = iv.PRO_CODIGO
                LEFT JOIN (
                    SELECT IV_CODIGO, SUM(PP_QTDE_PROD) AS total_produzido
                    FROM PCP_PLANEJAMENTO
                    WHERE PP_QTDE_PROD IS NOT NULL
                    GROUP BY IV_CODIGO
                ) ps ON ps.IV_CODIGO = iv.IV_CODIGO
                WHERE iv.IV_STATUS IN ('Pendente de produção','Produção')
                ORDER BY
                    CASE WHEN iv.IV_PRIORIDADE > 0 THEN iv.IV_PRIORIDADE ELSE 999999 END,
                    v.VEN_ENTREGA,
                    v.VEN_CODIGO_YZIDRO
            ")->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $rows);
            echo json_encode(['ok' => true, 'pedidos' => $rows]);

        // ── Adicionar ao plano ─────────────────────────────────────────────
        } elseif ($acao === 'add_plano') {
            $data    = (string)($body['data']       ?? '');
            $maq_cod = (int)   ($body['maq_codigo'] ?? 0);
            $iv_cod  = (int)   ($body['iv_codigo']  ?? 0);
            $qtde    = (float)str_replace(',', '.', (string)($body['qtde'] ?? 0));

            if (!$data || !$maq_cod || !$iv_cod || $qtde <= 0
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']); exit;
            }
            $pdo->prepare("
                INSERT INTO PCP_PLANEJAMENTO (PP_DATA, MAQ_CODIGO, IV_CODIGO, PP_QTDE_PLAN)
                VALUES (:data, :maq, :iv, :qtde)
            ")->execute([':data' => $data, ':maq' => $maq_cod, ':iv' => $iv_cod, ':qtde' => $qtde]);

            echo json_encode(['ok' => true]);

        // ── Remover do plano ───────────────────────────────────────────────
        } elseif ($acao === 'del_plano') {
            $pp_cod = (int)($body['pp_codigo'] ?? 0);
            if (!$pp_cod) { echo json_encode(['ok' => false, 'msg' => 'ID inválido.']); exit; }

            // Pega o iv_codigo antes de deletar
            $row = $pdo->prepare("SELECT IV_CODIGO FROM PCP_PLANEJAMENTO WHERE PP_CODIGO=:id AND PP_STATUS='Planejado'");
            $row->execute([':id' => $pp_cod]);
            $iv = $row->fetchColumn();

            $pdo->prepare("DELETE FROM PCP_PLANEJAMENTO WHERE PP_CODIGO=:id AND PP_STATUS='Planejado'")
                ->execute([':id' => $pp_cod]);

            echo json_encode(['ok' => true]);

        // ── Registrar produção ─────────────────────────────────────────────
        } elseif ($acao === 'registrar_producao') {
            $pp_cod    = (int)  ($body['pp_codigo']  ?? 0);
            $qtde_prod = (float)str_replace(',', '.', (string)($body['qtde_prod'] ?? 0));

            if (!$pp_cod || $qtde_prod < 0) {
                echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']); exit;
            }

            $pdo->prepare("
                UPDATE PCP_PLANEJAMENTO
                SET PP_QTDE_PROD=:q, PP_STATUS='Registrado'
                WHERE PP_CODIGO=:id
            ")->execute([':q' => $qtde_prod, ':id' => $pp_cod]);

            // Calcula progresso do pedido
            $stmt = $pdo->prepare("
                SELECT
                    iv.IV_CODIGO,
                    ROUND(iv.IV_QTDE) AS iv_qtde,
                    pp.MAQ_CODIGO,
                    COALESCE((
                        SELECT SUM(pp2.PP_QTDE_PROD)
                        FROM PCP_PLANEJAMENTO pp2
                        WHERE pp2.IV_CODIGO = pp.IV_CODIGO
                          AND pp2.PP_QTDE_PROD IS NOT NULL
                    ), 0) AS total_produzido
                FROM PCP_PLANEJAMENTO pp
                INNER JOIN ITENS_VENDAS iv ON iv.IV_CODIGO = pp.IV_CODIGO
                WHERE pp.PP_CODIGO = :id
            ");
            $stmt->execute([':id' => $pp_cod]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            $info = $info ? array_change_key_case($info, CASE_LOWER) : $info;

            $restante = max(0, (float)$info['iv_qtde'] - (float)$info['total_produzido']);
            echo json_encode([
                'ok'              => true,
                'iv_codigo'       => (int)  $info['iv_codigo'],
                'maq_codigo'      => (int)  $info['maq_codigo'],
                'iv_qtde'         => (float)$info['iv_qtde'],
                'total_produzido' => (float)$info['total_produzido'],
                'restante'        => $restante,
                'concluido'       => $restante <= 0,
            ]);

        // ── Continuar amanhã ───────────────────────────────────────────────
        } elseif ($acao === 'continuar_amanha') {
            $iv_cod  = (int)  ($body['iv_codigo']  ?? 0);
            $maq_cod = (int)  ($body['maq_codigo'] ?? 0);
            $qtde    = (float)str_replace(',', '.', (string)($body['qtde'] ?? 0));
            $data    = (string)($body['data'] ?? '');

            if (!$iv_cod || !$maq_cod || $qtde <= 0
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']); exit;
            }
            $pdo->prepare("
                INSERT INTO PCP_PLANEJAMENTO (PP_DATA, MAQ_CODIGO, IV_CODIGO, PP_QTDE_PLAN)
                VALUES (:data, :maq, :iv, :qtde)
            ")->execute([':data' => $data, ':maq' => $maq_cod, ':iv' => $iv_cod, ':qtde' => $qtde]);
            echo json_encode(['ok' => true]);

        // ── Finalizar pedido (status gerenciado manualmente no kanban) ────────
        } elseif ($acao === 'finalizar_pedido') {
            echo json_encode(['ok' => true]);

        // ── Próxima máquina ────────────────────────────────────────────────
        } elseif ($acao === 'proxima_maquina') {
            $iv_cod  = (int)  ($body['iv_codigo']  ?? 0);
            $maq_cod = (int)  ($body['maq_codigo'] ?? 0);
            $qtde    = (float)str_replace(',', '.', (string)($body['qtde'] ?? 0));
            $data    = (string)($body['data'] ?? '');

            if (!$iv_cod || !$maq_cod || $qtde <= 0
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']); exit;
            }
            $pdo->prepare("
                INSERT INTO PCP_PLANEJAMENTO (PP_DATA, MAQ_CODIGO, IV_CODIGO, PP_QTDE_PLAN)
                VALUES (:data, :maq, :iv, :qtde)
            ")->execute([':data' => $data, ':maq' => $maq_cod, ':iv' => $iv_cod, ':qtde' => $qtde]);
            echo json_encode(['ok' => true]);

        // ── Importar sugestões do PCP automático ──────────────────────
        } elseif ($acao === 'importar_sugestoes') {
            $data = (string)($body['data'] ?? '');
            if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['ok' => false, 'msg' => 'Data inválida.']); exit;
            }
            $data_fmt_pcp = date('d/m/Y', strtotime($data)); // dd/mm/yyyy para comparar com prog

            // Roda o motor PCP
            $maqsPcp  = pcpGetMaquinas();
            $pedsPcp  = pcpGetPedidosMySQL();
            $planPcp  = pcpCalcPlanejamento($pedsPcp, $maqsPcp);

            // Filtra apenas os itens do dia solicitado
            $progDia = array_filter($planPcp['prog'], fn($p) => $p['data'] === $data_fmt_pcp);

            if (empty($progDia)) {
                echo json_encode(['ok' => true, 'inseridos' => 0, 'pulados' => 0, 'msg' => 'Nenhuma sugestão encontrada para esta data.']); exit;
            }

            // Pré-carrega maq_codigo por maq_descricao
            $maqMap = [];
            $rmRows = $pdo->query("
                SELECT MAQ_CODIGO, MAQ_DESCRICAO,
                       COALESCE(MAQ_PRODUCAO_MIN,0) * COALESCE(MAQ_QTDE,1) AS vel
                FROM MAQUINAS
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rmRows as $rm) {
                $rm = array_change_key_case($rm, CASE_LOWER);
                $maqMap[$rm['maq_descricao']] = ['codigo' => (int)$rm['maq_codigo'], 'vel' => (float)$rm['vel']];
            }

            $inseridos = 0;
            $pulados   = 0;
            $stIv = $pdo->prepare("
                SELECT iv.IV_CODIGO
                FROM ITENS_VENDAS iv
                INNER JOIN VENDAS   v ON v.VEN_CODIGO  = iv.VEN_CODIGO
                INNER JOIN PRODUTOS p ON p.PRO_CODIGO  = iv.PRO_CODIGO
                WHERE v.VEN_CODIGO_YZIDRO = :ped
                  AND p.PRO_DESCRICAO     = :prod
                  AND iv.IV_STATUS IN ('Pendente de produção','Produção')
                LIMIT 1
            ");
            $stExiste = $pdo->prepare("
                SELECT COUNT(*) FROM PCP_PLANEJAMENTO
                WHERE PP_DATA=:data AND MAQ_CODIGO=:maq AND IV_CODIGO=:iv
            ");
            $stIns = $pdo->prepare("
                INSERT INTO PCP_PLANEJAMENTO (PP_DATA, MAQ_CODIGO, IV_CODIGO, PP_QTDE_PLAN)
                VALUES (:data, :maq, :iv, :qtde)
            ");

            foreach ($progDia as $pr) {
                $maqNome = $pr['maquina'];
                if (!isset($maqMap[$maqNome])) { $pulados++; continue; }
                $maqCod = $maqMap[$maqNome]['codigo'];
                $vel    = $maqMap[$maqNome]['vel']; // un/min

                // Lookup iv_codigo
                $stIv->execute([':ped' => $pr['pedido'], ':prod' => $pr['produto']]);
                $ivCod = $stIv->fetchColumn();
                if (!$ivCod) { $pulados++; continue; }

                // Verifica duplicata
                $stExiste->execute([':data' => $data, ':maq' => $maqCod, ':iv' => $ivCod]);
                if ((int)$stExiste->fetchColumn() > 0) { $pulados++; continue; }

                // Calcula quantidade planejada: horas × 60min/h × vel (un/min)
                $qtde = $vel > 0 ? round($pr['horas'] * 60.0 * $vel) : 1;
                if ($qtde <= 0) $qtde = 1;

                $stIns->execute([':data' => $data, ':maq' => $maqCod, ':iv' => $ivCod, ':qtde' => $qtde]);
                $inseridos++;
            }

            echo json_encode([
                'ok'       => true,
                'inseridos'=> $inseridos,
                'pulados'  => $pulados,
                'msg'      => $inseridos > 0
                    ? "{$inseridos} sugestão(ões) importada(s) com sucesso."
                    : 'Todas as sugestões já estavam no plano.',
            ]);

        // ── Buscar pedidos por cliente ─────────────────────────────────────
        } elseif ($acao === 'buscar_por_cliente') {
            $termo = trim((string)($body['cliente'] ?? ''));
            if (strlen($termo) < 2) {
                echo json_encode(['ok' => false, 'msg' => 'Termo muito curto.']); exit;
            }
            $rows = $pdo->prepare("
                SELECT
                    iv.IV_CODIGO,
                    ROUND(iv.IV_QTDE) AS iv_qtde,
                    iv.IV_STATUS,
                    v.VEN_CODIGO_YZIDRO AS pedido,
                    COALESCE(NULLIF(TRIM(v.VEN_FANTASIA),''), NULLIF(TRIM(v.VEN_CLIENTE),''), '') AS cliente,
                    DATE_FORMAT(v.VEN_ENTREGA,'%d/%m/%Y')  AS entrega,
                    v.VEN_ENTREGA AS entrega_iso,
                    p.PRO_DESCRICAO,
                    p.PRO_CATEGORIA,
                    COALESCE(ps.total_produzido, 0) AS total_produzido
                FROM ITENS_VENDAS iv
                INNER JOIN VENDAS   v  ON v.VEN_CODIGO  = iv.VEN_CODIGO
                INNER JOIN PRODUTOS p  ON p.PRO_CODIGO  = iv.PRO_CODIGO
                LEFT JOIN (
                    SELECT IV_CODIGO, SUM(PP_QTDE_PROD) AS total_produzido
                    FROM PCP_PLANEJAMENTO
                    WHERE PP_QTDE_PROD IS NOT NULL
                    GROUP BY IV_CODIGO
                ) ps ON ps.IV_CODIGO = iv.IV_CODIGO
                WHERE iv.IV_STATUS IN ('Pendente de produção','Produção')
                  AND (COALESCE(v.VEN_FANTASIA, v.VEN_CLIENTE) LIKE :termo)
                ORDER BY
                    CASE WHEN iv.IV_PRIORIDADE > 0 THEN iv.IV_PRIORIDADE ELSE 999999 END,
                    v.VEN_ENTREGA,
                    v.VEN_CODIGO_YZIDRO
            ");
            $rows->execute([':termo' => '%' . $termo . '%']);
            $pedidos = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $rows->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['ok' => true, 'pedidos' => $pedidos]);

        } else {
            echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Carrega dados da página ────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['planejar','apontar']) ? $_GET['tab'] : 'planejar';

$data_plano = $_GET['data'] ?? '';
if (!$data_plano || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_plano)) {
    $data_plano = ($tab === 'apontar')
        ? date('Y-m-d')
        : date('Y-m-d', strtotime('+1 day'));
}
$data_fmt   = date('d/m/Y', strtotime($data_plano));
$is_today   = ($data_plano === date('Y-m-d'));
$is_future  = ($data_plano > date('Y-m-d'));

// Máquinas reais (não grupos lógicos)
$maquinas = array_map(
    fn($r) => array_change_key_case($r, CASE_LOWER),
    $pdo->query("
        SELECT m.MAQ_CODIGO, m.MAQ_DESCRICAO, d.DP_DESCRICAO,
               COALESCE(m.MAQ_PRODUCAO_MIN, 0) AS maq_producao_min,
               COALESCE(m.MAQ_HORAS_DIA, 8)    AS maq_horas_dia,
               COALESCE(m.MAQ_QTDE, 1)         AS maq_qtde
        FROM MAQUINAS m
        INNER JOIN DEPARTAMENTOS d ON d.DP_CODIGO = m.DP_CODIGO
        ORDER BY d.DP_DESCRICAO, m.MAQ_DESCRICAO
    ")->fetchAll(PDO::FETCH_ASSOC)
);

// Plano do dia selecionado
$stmt = $pdo->prepare("
    SELECT
        pp.PP_CODIGO, pp.PP_DATA, pp.PP_QTDE_PLAN, pp.PP_QTDE_PROD, pp.PP_STATUS, pp.PP_OBS,
        pp.MAQ_CODIGO, pp.IV_CODIGO,
        m.MAQ_DESCRICAO, d.DP_DESCRICAO,
        ROUND(iv.IV_QTDE)                            AS iv_qtde,
        iv.IV_STATUS                                 AS iv_status,
        v.VEN_CODIGO_YZIDRO                          AS pedido,
        COALESCE(NULLIF(TRIM(v.VEN_FANTASIA),''), NULLIF(TRIM(v.VEN_CLIENTE),''), '') AS cliente,
        DATE_FORMAT(v.VEN_ENTREGA,'%d/%m/%Y')        AS entrega,
        p.PRO_DESCRICAO,
        COALESCE((
            SELECT SUM(pp2.PP_QTDE_PROD)
            FROM PCP_PLANEJAMENTO pp2
            WHERE pp2.IV_CODIGO = pp.IV_CODIGO
              AND pp2.PP_QTDE_PROD IS NOT NULL
        ), 0) AS total_produzido
    FROM PCP_PLANEJAMENTO pp
    INNER JOIN MAQUINAS     m  ON m.MAQ_CODIGO  = pp.MAQ_CODIGO
    INNER JOIN DEPARTAMENTOS d  ON d.DP_CODIGO   = m.DP_CODIGO
    INNER JOIN ITENS_VENDAS iv  ON iv.IV_CODIGO  = pp.IV_CODIGO
    INNER JOIN VENDAS        v  ON v.VEN_CODIGO  = iv.VEN_CODIGO
    INNER JOIN PRODUTOS      p  ON p.PRO_CODIGO  = iv.PRO_CODIGO
    WHERE pp.PP_DATA = :data
    ORDER BY d.DP_DESCRICAO, m.MAQ_DESCRICAO, pp.PP_CODIGO
");
$stmt->execute([':data' => $data_plano]);
$plano_rows = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));

$plano_por_maq = [];
$reg_por_maq   = [];
foreach ($plano_rows as $r) {
    $plano_por_maq[$r['maq_codigo']][] = $r;
    $reg_por_maq[$r['maq_codigo']][]   = $r;
}

$total_plano      = count($plano_rows);
$total_registrado = count(array_filter($plano_rows, fn($r) => $r['pp_status'] === 'Registrado'));
$maq_com_plano    = count($plano_por_maq);

$maquinas_json = json_encode($maquinas, JSON_UNESCAPED_UNICODE);
$data_plano_js = json_encode($data_plano);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reunião de Planejamento | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ══════════════════════════════════════════════════════════════
   REUNIÃO DE PLANEJAMENTO
   ══════════════════════════════════════════════════════════════ */

/* Habilita scroll na página inteira */
.content { overflow-y: auto !important; }

/* ── Step tabs ──────────────────────────────────────────────── */
.step-tabs{display:flex;align-items:center;gap:2px;}
.step-tab{display:inline-flex;align-items:center;gap:7px;padding:7px 16px;border:none;background:transparent;color:var(--text-muted);font-size:12.5px;font-weight:600;font-family:inherit;cursor:pointer;border-radius:8px;transition:all .15s;}
.step-tab:hover{background:rgba(255,255,255,.06);color:var(--text-primary);}
.step-tab.active{background:rgba(45,106,255,.14);color:#7db3ff;}
.step-num{width:20px;height:20px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid var(--border);font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s;}
.step-tab.active .step-num{background:rgba(45,106,255,.3);border-color:rgba(45,106,255,.5);color:#7db3ff;}

/* ── Date bar ───────────────────────────────────────────────── */
.date-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 0 18px;}
.date-nav{display:flex;gap:4px;}
.btn-nav{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text-muted);font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;transition:all .14s;}
.btn-nav:hover{background:rgba(255,255,255,.1);color:var(--text-primary);}
.date-cur{font-size:17px;font-weight:700;display:flex;align-items:center;gap:8px;}
.date-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;}
.badge-future{background:rgba(45,106,255,.14);color:#7db3ff;}
.badge-today{background:rgba(0,201,167,.14);color:var(--teal);}
.badge-past{background:rgba(239,68,68,.1);color:#f87171;}
.date-pick input[type=date]{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-primary);padding:5px 10px;border-radius:7px;font-size:12.5px;font-family:inherit;cursor:pointer;}
.date-spacer{flex:1;}

/* ── Summary strip ──────────────────────────────────────────── */
.summary-strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.sstat{background:var(--card-bg);border:1px solid var(--border);border-radius:9px;padding:7px 14px;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-muted);}
.sstat-val{font-size:15px;font-weight:800;color:var(--text-primary);}
.sstat-val.teal{color:var(--teal);}
.sstat-val.blue{color:#7db3ff;}

/* ── Machine grid ───────────────────────────────────────────── */
.maq-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;min-width:0;}

/* ── Machine card ───────────────────────────────────────────── */
.maq-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
.maq-card.has-items{border-color:rgba(45,106,255,.22);}
.maq-card-head{padding:8px 11px;display:flex;align-items:center;justify-content:space-between;gap:6px;border-bottom:1px solid var(--border);}
.maq-head-info{flex:1;min-width:0;}
.maq-name{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.maq-depto{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:6px;background:rgba(167,139,250,.1);color:#a78bfa;display:inline-block;margin-top:2px;}
.maq-counter{font-size:10px;color:var(--text-muted);white-space:nowrap;margin-top:2px;}
.maq-counter .hi{color:var(--teal);font-weight:700;}
.maq-card-body{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-width:0;max-height:220px;overflow-y:auto;}
.maq-card-body::-webkit-scrollbar{width:3px;}
.maq-card-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
.maq-card-foot{padding:6px 10px;border-top:1px solid var(--border);}
.maq-cap{font-size:10px;color:var(--text-muted);}
.maq-empty{color:var(--text-muted);font-size:11.5px;padding:8px 2px;text-align:center;font-style:italic;}

/* ── Plan item card ─────────────────────────────────────────── */
.pi{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:7px;padding:7px 9px;display:flex;flex-direction:column;gap:3px;min-width:0;}
.pi.is-done{opacity:.6;}
.pi-top{display:flex;align-items:flex-start;justify-content:space-between;gap:5px;}
.pi-ped{font-size:11px;font-weight:700;color:#7db3ff;}
.pi-ent{font-size:9.5px;font-weight:600;padding:1px 6px;border-radius:6px;background:rgba(255,255,255,.05);color:var(--text-muted);white-space:nowrap;}
.pi-cli{font-size:10px;color:var(--text-muted);}
.pi-prod{font-size:11px;font-weight:500;line-height:1.3;}
.pi-meta{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-top:1px;}
.pi-qtd{font-size:10px;font-weight:700;color:var(--text-muted);}
.pi-qtd .v{color:var(--text-primary);}
.pi-bar-wrap{height:2px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden;}
.pi-bar-fill{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--teal),#2de8a4);transition:width .4s;}
.pi-bar-pct{font-size:9px;color:var(--text-muted);margin-top:1px;}
.pi-actions{display:flex;align-items:center;gap:5px;margin-top:2px;}
.badge-plan{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:6px;background:rgba(45,106,255,.1);color:#7db3ff;}
.badge-reg{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:6px;background:rgba(0,201,167,.1);color:var(--teal);}
.pi-reg-done{display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;color:var(--teal);}

/* ── Buttons ─────────────────────────────────────────────────── */
.btn-add{background:rgba(45,106,255,.12);border:1px solid rgba(45,106,255,.2);color:#7db3ff;padding:5px 10px;border-radius:7px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .14s;white-space:nowrap;}
.btn-add:hover{background:rgba(45,106,255,.22);}
.btn-reg{background:rgba(0,201,167,.1);border:1px solid rgba(0,201,167,.2);color:var(--teal);padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .14s;}
.btn-reg:hover{background:rgba(0,201,167,.2);}
.btn-del{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);color:#f87171;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:3px;transition:all .14s;}
.btn-del:hover{background:rgba(239,68,68,.16);}
.btn-primary{background:rgba(45,106,255,.18);border:1px solid rgba(45,106,255,.3);color:#7db3ff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn-primary:hover{background:rgba(45,106,255,.28);}
.btn-teal{background:rgba(0,201,167,.12);border:1px solid rgba(0,201,167,.25);color:var(--teal);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn-teal:hover{background:rgba(0,201,167,.22);}
.btn-danger-sm{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#f87171;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;}
.btn-danger-sm:hover{background:rgba(239,68,68,.2);}
.btn-amber{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);color:var(--amber);padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;}
.btn-amber:hover{background:rgba(245,158,11,.22);}
.btn-secondary{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-muted);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn-secondary:hover{background:rgba(255,255,255,.1);color:var(--text-primary);}
.btn-new-plan{background:rgba(45,106,255,.18);border:1px solid rgba(45,106,255,.3);color:#7db3ff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;white-space:nowrap;}
.btn-new-plan:hover{background:rgba(45,106,255,.28);}

/* ── Progress header (registrar tab) ────────────────────────── */
.reg-progress-header{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;}
.reg-progress-main{flex:1;min-width:200px;}
.reg-progress-label{font-size:12px;color:var(--text-muted);margin-bottom:6px;}
.reg-progress-bar{height:8px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;}
.reg-progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--teal),#2de8a4);}
.reg-progress-nums{font-size:13px;font-weight:700;margin-top:5px;}
.reg-progress-nums span{color:var(--teal);}
.reg-done-badge{background:rgba(0,201,167,.1);border:1px solid rgba(0,201,167,.2);color:var(--teal);font-size:12px;font-weight:700;padding:6px 14px;border-radius:8px;white-space:nowrap;}

/* ── Empty state ─────────────────────────────────────────────── */
.empty-state{text-align:center;padding:52px 24px;color:var(--text-muted);}
.empty-state-icon{font-size:40px;margin-bottom:12px;opacity:.4;}
.empty-state h3{font-size:15px;font-weight:700;color:var(--text-primary);margin:0 0 6px;}
.empty-state p{font-size:12.5px;margin:0 0 16px;line-height:1.5;}

/* ── Modal ───────────────────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);z-index:700;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#0e1d33;border:1px solid rgba(255,255,255,.1);border-radius:16px;width:100%;max-width:700px;box-shadow:0 24px 64px rgba(0,0,0,.65);transform:translateY(10px) scale(.98);transition:transform .2s;}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
.modal-head h3{font-size:15px;font-weight:700;margin:0;}
.modal-close{background:rgba(255,255,255,.07);border:1px solid var(--border);color:var(--text-muted);width:28px;height:28px;border-radius:7px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-family:inherit;}
.modal-close:hover{background:rgba(255,255,255,.12);}
.modal-body{padding:18px 20px;max-height:76vh;overflow-y:auto;}
.modal-footer{padding:13px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:flex-end;gap:8px;}

/* ── Form ─────────────────────────────────────────────────────── */
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px;}
.form-control{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-primary);padding:9px 12px;border-radius:8px;font-size:13.5px;font-family:inherit;box-sizing:border-box;transition:border-color .15s;}
.form-control:focus{outline:none;border-color:rgba(45,106,255,.5);}
.form-control option{background:#0e1d33;}
.search-wrap{position:relative;}
.search-wrap .si{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;}
.search-wrap input{padding-left:32px;}
.pedido-list{height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;margin-top:6px;}
.pedido-opt{padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .12s;}
.pedido-opt:last-child{border-bottom:none;}
.pedido-opt:hover,.pedido-opt.sel{background:rgba(45,106,255,.12);}
.pedido-opt-num{font-size:12px;font-weight:700;color:#7db3ff;}
.pedido-opt-desc{font-size:11.5px;color:var(--text-primary);}
.pedido-opt-meta{font-size:10.5px;color:var(--text-muted);display:flex;gap:8px;flex-wrap:wrap;margin-top:2px;}

/* ── Client autocomplete ─────────────────────────────────────── */
.cli-wrap{position:relative;}
.cli-wrap .si{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;}
.cli-wrap input{padding-left:32px;}
.cli-wrap input.has-val{border-color:rgba(0,201,167,.4);}

/* ── Registration modal result ───────────────────────────────── */
.result-card{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;padding:15px;margin-bottom:14px;}
.result-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.result-lbl{font-size:12px;color:var(--text-muted);}
.result-val{font-size:20px;font-weight:800;}
.result-pbar{height:8px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;margin-bottom:6px;}
.result-pbar-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--teal),#2de8a4);transition:width .5s;}
.result-pct{font-size:11px;color:var(--text-muted);text-align:right;}
.action-list{display:flex;flex-direction:column;gap:8px;}
.action-btn{width:100%;padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:10px;border:1px solid;transition:all .15s;text-align:left;}
.action-btn.amber{background:rgba(245,158,11,.09);border-color:rgba(245,158,11,.22);color:var(--amber);}
.action-btn.amber:hover{background:rgba(245,158,11,.17);}
.action-btn.blue{background:rgba(45,106,255,.09);border-color:rgba(45,106,255,.22);color:#7db3ff;}
.action-btn.blue:hover{background:rgba(45,106,255,.17);}
.action-btn.teal{background:rgba(0,201,167,.09);border-color:rgba(0,201,167,.22);color:var(--teal);}
.action-btn.teal:hover{background:rgba(0,201,167,.17);}
.action-ico{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;}
.action-ico.amber{background:rgba(245,158,11,.14);}
.action-ico.blue{background:rgba(45,106,255,.14);}
.action-ico.teal{background:rgba(0,201,167,.14);}
.action-txt strong{display:block;font-size:12.5px;}
.action-txt span{font-size:10.5px;opacity:.65;}
.sub-panel{margin-top:12px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:9px;padding:14px;}
.sub-panel-title{font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.6px;}

/* ── Order info card (modal step 1) ─────────────────────────── */
.order-info-card{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;padding:12px;margin-bottom:14px;}
.order-info-ped{font-weight:700;color:#7db3ff;font-size:13px;}
.order-info-cli{color:var(--text-muted);margin-top:2px;font-size:11.5px;}
.order-info-prod{margin-top:4px;font-size:12.5px;}
.order-info-maq{margin-top:3px;font-size:11.5px;color:#a78bfa;}
.order-info-nums{display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);}
.order-info-num{font-size:10.5px;color:var(--text-muted);}
.order-info-num strong{display:block;font-size:14px;font-weight:800;color:var(--text-primary);}
.order-info-num strong.teal{color:var(--teal);}
.order-info-num strong.blue{color:#7db3ff;}


@media(max-width:640px){
  .maq-grid{grid-template-columns:1fr;}
  .reg-progress-header{flex-direction:column;gap:10px;}
  .summary-strip{gap:6px;}
}
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
      <p>Planejar a produção por máquina · <?= htmlspecialchars($data_fmt) ?></p>
    </div>
  </div>
  <div class="topbar-actions">
    <div class="step-tabs">
      <button class="step-tab <?= $tab === 'planejar' ? 'active' : '' ?>" onclick="switchTab('planejar')">
        <span class="step-num">1</span>Planejar
      </button>
      <button class="step-tab <?= $tab === 'apontar' ? 'active' : '' ?>" onclick="switchTab('apontar')">
        <span class="step-num">2</span>Registrar Produção
      </button>
    </div>
  </div>
</header>

<div class="content">

<!-- ── Date bar ──────────────────────────────────────────────────────── -->
<div class="date-bar">
  <div class="date-nav">
    <button class="btn-nav" onclick="navDate(-1)" title="Dia anterior">&#8249;</button>
    <button class="btn-nav" onclick="navDate(1)"  title="Próximo dia">&#8250;</button>
  </div>
  <div class="date-cur">
    <?= htmlspecialchars($data_fmt) ?>
    <?php if ($is_future): ?>
      <span class="date-badge badge-future">Futuro</span>
    <?php elseif ($is_today): ?>
      <span class="date-badge badge-today">Hoje</span>
    <?php else: ?>
      <span class="date-badge badge-past">Passado</span>
    <?php endif; ?>
  </div>
  <div class="date-pick">
    <input type="date" value="<?= htmlspecialchars($data_plano) ?>" onchange="goDate(this.value)">
  </div>
  <div class="date-spacer"></div>
  <div id="date-bar-action" style="display:flex;gap:8px;align-items:center;">
    <?php if ($tab === 'planejar'): ?>
    <button class="btn-new-plan" onclick="openAddModal(null)">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Adicionar ao plano
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- PASSO 1: PLANEJAR                                                     -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div id="tab-planejar" <?= $tab !== 'planejar' ? 'style="display:none"' : '' ?>>

  <?php if ($total_plano > 0): ?>
  <div class="summary-strip">
    <div class="sstat"><span class="sstat-val blue"><?= $maq_com_plano ?></span>máquina<?= $maq_com_plano !== 1 ? 's' : '' ?> planejada<?= $maq_com_plano !== 1 ? 's' : '' ?></div>
    <div class="sstat"><span class="sstat-val"><?= $total_plano ?></span>pedido<?= $total_plano !== 1 ? 's' : '' ?> no plano</div>
  </div>
  <?php endif; ?>

  <div class="maq-grid">
    <?php foreach ($maquinas as $maq):
      $items = $plano_por_maq[$maq['maq_codigo']] ?? [];
      $cap   = round((float)$maq['maq_producao_min'] * 60 * (float)$maq['maq_horas_dia']);
      $cap_s = $cap > 0 ? number_format($cap, 0, ',', '.') . ' un/dia' : '—';
    ?>
    <div class="maq-card <?= !empty($items) ? 'has-items' : '' ?>">

      <div class="maq-card-head">
        <div class="maq-head-info">
          <div class="maq-name"><?= htmlspecialchars($maq['maq_descricao']) ?></div>
          <span class="maq-depto"><?= htmlspecialchars($maq['dp_descricao']) ?></span>
          <?php if (!empty($items)): ?>
          <div class="maq-counter"><?= count($items) ?> pedido<?= count($items) !== 1 ? 's' : '' ?></div>
          <?php endif; ?>
        </div>
        <button class="btn-add" onclick="openAddModal(<?= $maq['maq_codigo'] ?>)">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Adicionar
        </button>
      </div>

      <div class="maq-card-body">
        <?php if (empty($items)): ?>
          <div class="maq-empty">Nenhum pedido planejado</div>
        <?php else: ?>
          <?php foreach ($items as $it):
            $tot_prod = (float)$it['total_produzido'];
            $iv_qtde  = (float)$it['iv_qtde'];
            $pct      = $iv_qtde > 0 ? min(100, round($tot_prod / $iv_qtde * 100)) : 0;
          ?>
          <div class="pi" id="plan-item-<?= $it['pp_codigo'] ?>">
            <div class="pi-top">
              <span class="pi-ped"><?= htmlspecialchars($it['pedido']) ?></span>
              <span class="pi-ent">📅 <?= htmlspecialchars($it['entrega']) ?></span>
            </div>
            <div class="pi-cli"><?= htmlspecialchars($it['cliente']) ?></div>
            <div class="pi-prod"><?= htmlspecialchars($it['pro_descricao']) ?></div>
            <div class="pi-meta">
              <span class="pi-qtd">Planejado: <span class="v"><?= number_format((float)$it['pp_qtde_plan'], 0, ',', '.') ?></span> un</span>
              <?php if ($it['pp_qtde_prod'] !== null): ?>
              <span class="pi-qtd">Produzido: <span class="v" style="color:var(--teal)"><?= number_format((float)$it['pp_qtde_prod'], 0, ',', '.') ?></span> un</span>
              <?php endif; ?>
              <span class="<?= $it['pp_status'] === 'Registrado' ? 'badge-reg' : 'badge-plan' ?>"><?= $it['pp_status'] ?></span>
            </div>
            <div class="pi-bar-wrap"><div class="pi-bar-fill" style="width:<?= $pct ?>%"></div></div>
            <div class="pi-bar-pct"><?= number_format($tot_prod, 0, ',', '.') ?> / <?= number_format($iv_qtde, 0, ',', '.') ?> un totais (<?= $pct ?>%)</div>
            <?php if ($it['pp_status'] === 'Planejado'): ?>
            <div class="pi-actions">
              <button class="btn-del" onclick="delPlano(<?= $it['pp_codigo'] ?>)">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                Remover
              </button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="maq-card-foot">
        <span class="maq-cap">Cap. estimada: <?= $cap_s ?></span>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

</div><!-- /tab-planejar -->

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- PASSO 2: REGISTRAR PRODUÇÃO                                          -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div id="tab-apontar" <?= $tab !== 'apontar' ? 'style="display:none"' : '' ?>>

  <?php if (empty($plano_rows)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">📋</div>
    <h3>Nenhum pedido planejado para <?= htmlspecialchars($data_fmt) ?></h3>
    <p>Vá para o <strong>Passo 1 — Planejar</strong> e adicione pedidos às máquinas antes de registrar a produção.</p>
    <button class="btn-primary" onclick="switchTabDirect('planejar')">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Ir para Planejar
    </button>
  </div>
  <?php else: ?>

  <?php $pct_reg = $total_plano > 0 ? round($total_registrado / $total_plano * 100) : 0; ?>
  <div class="reg-progress-header">
    <div class="reg-progress-main">
      <div class="reg-progress-label">Progresso do dia — <?= htmlspecialchars($data_fmt) ?></div>
      <div class="reg-progress-bar">
        <div class="reg-progress-fill" style="width:<?= $pct_reg ?>%"></div>
      </div>
      <div class="reg-progress-nums">
        <span><?= $total_registrado ?></span> / <?= $total_plano ?> registrados
      </div>
    </div>
    <?php if ($total_registrado === $total_plano && $total_plano > 0): ?>
    <div class="reg-done-badge">✓ Dia concluído</div>
    <?php endif; ?>
  </div>

  <div class="maq-grid">
    <?php foreach ($maquinas as $maq):
      $items = $reg_por_maq[$maq['maq_codigo']] ?? [];
      if (empty($items)) continue;
      $n_reg = count(array_filter($items, fn($i) => $i['pp_status'] === 'Registrado'));
      $n_tot = count($items);
    ?>
    <div class="maq-card has-items">

      <div class="maq-card-head">
        <div class="maq-head-info">
          <div class="maq-name"><?= htmlspecialchars($maq['maq_descricao']) ?></div>
          <span class="maq-depto"><?= htmlspecialchars($maq['dp_descricao']) ?></span>
          <div class="maq-counter"><span class="hi"><?= $n_reg ?></span> / <?= $n_tot ?> registrado<?= $n_tot !== 1 ? 's' : '' ?></div>
        </div>
      </div>

      <div class="maq-card-body">
        <?php foreach ($items as $it):
          $tot_prod = (float)$it['total_produzido'];
          $iv_qtde  = (float)$it['iv_qtde'];
          $pct      = $iv_qtde > 0 ? min(100, round($tot_prod / $iv_qtde * 100)) : 0;
          $restante = max(0, $iv_qtde - $tot_prod);
          $done     = $it['pp_status'] === 'Registrado';
        ?>
        <div class="pi <?= $done ? 'is-done' : '' ?>" id="ap-item-<?= $it['pp_codigo'] ?>">
          <div class="pi-top">
            <span class="pi-ped"><?= htmlspecialchars($it['pedido']) ?></span>
            <span class="pi-ent">📅 <?= htmlspecialchars($it['entrega']) ?></span>
          </div>
          <div class="pi-cli"><?= htmlspecialchars($it['cliente']) ?></div>
          <div class="pi-prod"><?= htmlspecialchars($it['pro_descricao']) ?></div>
          <div class="pi-meta">
            <span class="pi-qtd">Planejado: <span class="v"><?= number_format((float)$it['pp_qtde_plan'], 0, ',', '.') ?></span> un</span>
            <?php if ($restante > 0): ?>
            <span class="pi-qtd" style="color:var(--amber)">Falta: <span class="v"><?= number_format($restante, 0, ',', '.') ?></span> un</span>
            <?php else: ?>
            <span class="pi-qtd" style="color:var(--teal)">✓ Completo</span>
            <?php endif; ?>
          </div>
          <div class="pi-bar-wrap"><div class="pi-bar-fill" style="width:<?= $pct ?>%"></div></div>
          <div class="pi-bar-pct"><?= $pct ?>% do pedido produzido</div>
          <div class="pi-actions">
            <?php if (!$done): ?>
            <button class="btn-reg"
                    onclick="openRegModal(<?= htmlspecialchars(json_encode([
                      'pp_codigo'  => (int)$it['pp_codigo'],
                      'iv_codigo'  => (int)$it['iv_codigo'],
                      'maq_codigo' => (int)$it['maq_codigo'],
                      'maq_nome'   => $it['maq_descricao'],
                      'pedido'     => $it['pedido'],
                      'cliente'    => $it['cliente'],
                      'pro_desc'   => $it['pro_descricao'],
                      'qtde_plan'  => (float)$it['pp_qtde_plan'],
                      'iv_qtde'    => (float)$it['iv_qtde'],
                      'total_prod' => (float)$it['total_produzido'],
                    ]), ENT_QUOTES) ?>)">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              Registrar produção
            </button>
            <?php else: ?>
            <div class="pi-reg-done">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              Registrado: <?= number_format((float)$it['pp_qtde_prod'], 0, ',', '.') ?> un
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div><!-- /tab-apontar -->

</div><!-- /content -->
</div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Adicionar ao Plano                                            -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-add">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Adicionar Pedido ao Plano</h3>
      <button class="modal-close" onclick="closeModal('modal-add')">×</button>
    </div>
    <div class="modal-body">

      <div class="form-group">
        <label>Máquina</label>
        <select class="form-control" id="add-maq" onchange="updateCapacity()">
          <?php foreach ($maquinas as $m): ?>
          <option value="<?= $m['maq_codigo'] ?>"
                  data-cap="<?= round((float)$m['maq_producao_min'] * 60 * (float)$m['maq_horas_dia']) ?>"
                  data-depto="<?= htmlspecialchars(strtoupper($m['dp_descricao'])) ?>">
            <?= htmlspecialchars($m['maq_descricao']) ?> — <?= htmlspecialchars($m['dp_descricao']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div id="add-maq-tipo-badge" style="margin-top:5px;display:none;">
          <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:6px;background:rgba(167,139,250,.12);color:#a78bfa;border:1px solid rgba(167,139,250,.25);" id="add-maq-tipo-txt"></span>
        </div>
      </div>

      <div class="form-group">
        <label>Filtrar por Cliente <span style="font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;">(opcional)</span></label>
        <div class="cli-wrap">
          <span class="si"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
          <input type="text" class="form-control" id="add-cli-search"
                 placeholder="Digite o nome do cliente…"
                 oninput="filterCliente()" autocomplete="off">
        </div>
      </div>

      <div class="form-group">
        <label>Pedido / Produto</label>
        <div class="search-wrap">
          <span class="si"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
          <input type="text" class="form-control" id="add-search"
                 placeholder="Buscar por pedido ou produto…"
                 oninput="filterPedidos()" autocomplete="off">
        </div>
        <div class="pedido-list" id="pedido-list"></div>
        <input type="hidden" id="add-iv-codigo">
        <div id="add-sel-info" style="font-size:11.5px;color:var(--teal);margin-top:6px;display:none;"></div>
      </div>

      <div class="form-group" style="margin-bottom:0;">
        <label>Qtde planejada <span id="add-cap-hint" style="font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;"></span></label>
        <input type="number" class="form-control" id="add-qtde" min="1" placeholder="ex: 5000">
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn-danger-sm" onclick="closeModal('modal-add')">Cancelar</button>
      <button class="btn-primary" onclick="salvarPlano()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Salvar
      </button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Registrar Produção                                            -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-reg">
  <div class="modal-box" style="max-width:540px;">
    <div class="modal-head">
      <h3 id="reg-title">Registrar Produção</h3>
      <button class="modal-close" onclick="closeModal('modal-reg')">×</button>
    </div>
    <div class="modal-body">

      <!-- ── Passo 1: Informar quantidade ─────────────────────── -->
      <div id="reg-step1">
        <div class="order-info-card">
          <div class="order-info-ped" id="reg-info-ped"></div>
          <div class="order-info-cli" id="reg-info-cli"></div>
          <div class="order-info-prod" id="reg-info-prod"></div>
          <div class="order-info-maq" id="reg-info-maq"></div>
          <div class="order-info-nums">
            <div class="order-info-num">Qtde total<strong id="reg-qtde-total"></strong></div>
            <div class="order-info-num">Já produzido<strong id="reg-qtde-ja-prod" class="teal"></strong></div>
            <div class="order-info-num">Planejado hoje<strong id="reg-qtde-plan" class="blue"></strong></div>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Quantidade produzida hoje</label>
          <input type="number" class="form-control" id="reg-qtde-prod" min="0" placeholder="ex: 4800"
                 style="font-size:24px;font-weight:700;height:58px;text-align:center;">
        </div>
      </div>

      <!-- ── Passo 2: Resultado + próximo passo ───────────────── -->
      <div id="reg-step2" style="display:none;">
        <div class="result-card">
          <div class="result-row">
            <div><div class="result-lbl">Total produzido</div><div class="result-val" id="res-total-prod" style="color:var(--teal);"></div></div>
            <div style="text-align:right;"><div class="result-lbl">Total do pedido</div><div class="result-val" id="res-total-ped"></div></div>
          </div>
          <div class="result-pbar"><div class="result-pbar-fill" id="res-pbar-fill" style="width:0%"></div></div>
          <div class="result-pct" id="res-pct-txt"></div>
        </div>

        <!-- Caso: NÃO concluído -->
        <div id="res-nao-concluido" style="display:none;">
          <p style="font-size:13px;font-weight:600;margin:0 0 10px;">
            Ainda faltam <span id="res-falta" style="color:var(--amber);font-size:15px;font-weight:800;"></span> un. O que deseja fazer?
          </p>
          <div class="action-list">
            <button class="action-btn amber" onclick="showSub('substep-mesma-maq')">
              <div class="action-ico amber">🔄</div>
              <div class="action-txt"><strong>Continuar na mesma máquina</strong><span>Planejar outra data nesta máquina</span></div>
            </button>
            <button class="action-btn blue" onclick="showSub('substep-outra-maq-inc')">
              <div class="action-ico blue">⚙️</div>
              <div class="action-txt"><strong>Continuar em outra máquina</strong><span>Planejar outra máquina e data</span></div>
            </button>
          </div>

          <!-- Sub: mesma máquina, outra data -->
          <div id="substep-mesma-maq" class="sub-panel" style="display:none;">
            <div class="sub-panel-title">Mesma máquina · outra data</div>
            <div id="reg-maq-nome-cont" style="font-size:12px;color:#a78bfa;margin-bottom:10px;"></div>
            <div class="form-group"><label>Data</label><input type="date" class="form-control" id="cont-data"></div>
            <div class="form-group" style="margin-bottom:0;"><label>Qtde planejada</label><input type="number" class="form-control" id="cont-qtde" min="1"></div>
            <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
              <button class="btn-danger-sm" onclick="hideSub('substep-mesma-maq')">Voltar</button>
              <button class="btn-amber" onclick="confirmarContinuar()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Confirmar
              </button>
            </div>
          </div>

          <!-- Sub: outra máquina (incompleto) -->
          <div id="substep-outra-maq-inc" class="sub-panel" style="display:none;">
            <div class="sub-panel-title">Outra máquina</div>
            <div class="form-group"><label>Máquina</label>
              <select class="form-control" id="inc-maq-sel">
                <?php foreach ($maquinas as $m): ?>
                <option value="<?= $m['maq_codigo'] ?>"><?= htmlspecialchars($m['maq_descricao']) ?> — <?= htmlspecialchars($m['dp_descricao']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Data</label><input type="date" class="form-control" id="inc-maq-data"></div>
            <div class="form-group" style="margin-bottom:0;"><label>Qtde planejada</label><input type="number" class="form-control" id="inc-maq-qtde" min="1"></div>
            <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
              <button class="btn-danger-sm" onclick="hideSub('substep-outra-maq-inc')">Voltar</button>
              <button class="btn-primary" onclick="confirmarOutraMaqInc()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Confirmar
              </button>
            </div>
          </div>
        </div><!-- /res-nao-concluido -->

        <!-- Caso: CONCLUÍDO -->
        <div id="res-concluido" style="display:none;">
          <p style="font-size:13px;font-weight:600;margin:0 0 10px;color:var(--teal);">
            ✓ Produção desta etapa concluída! Qual o próximo passo?
          </p>
          <div class="action-list">
            <button class="action-btn blue" onclick="showSub('substep-prox-maq')">
              <div class="action-ico blue">⚙️</div>
              <div class="action-txt"><strong>Planejar próxima etapa</strong><span>Selecionar próxima máquina e data</span></div>
            </button>
            <button class="action-btn teal" onclick="finalizarPedido()">
              <div class="action-ico teal">✓</div>
              <div class="action-txt"><strong>Marcar pedido como finalizado</strong><span>Encerra completamente o pedido</span></div>
            </button>
          </div>

          <!-- Sub: próxima máquina (concluído) -->
          <div id="substep-prox-maq" class="sub-panel" style="display:none;">
            <div class="sub-panel-title">Próxima etapa</div>
            <div class="form-group"><label>Máquina</label>
              <select class="form-control" id="prox-maq-sel">
                <?php foreach ($maquinas as $m): ?>
                <option value="<?= $m['maq_codigo'] ?>"><?= htmlspecialchars($m['maq_descricao']) ?> — <?= htmlspecialchars($m['dp_descricao']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Data</label><input type="date" class="form-control" id="prox-maq-data"></div>
            <div class="form-group" style="margin-bottom:0;"><label>Qtde planejada</label><input type="number" class="form-control" id="prox-maq-qtde" min="1"></div>
            <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
              <button class="btn-danger-sm" onclick="hideSub('substep-prox-maq')">Voltar</button>
              <button class="btn-primary" onclick="confirmarProxMaq()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Confirmar
              </button>
            </div>
          </div>
        </div><!-- /res-concluido -->

      </div><!-- /reg-step2 -->
    </div><!-- /modal-body -->

    <div class="modal-footer" id="reg-footer-step1">
      <button class="btn-danger-sm" onclick="closeModal('modal-reg')">Cancelar</button>
      <button class="btn-teal" onclick="confirmarRegistro()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Registrar
      </button>
    </div>
    <div class="modal-footer" id="reg-footer-step2" style="display:none;">
      <button class="btn-secondary" onclick="closeModal('modal-reg')">Fechar</button>
    </div>
  </div>
</div>

<script>
// ── Globals ────────────────────────────────────────────────────────────────
const DATA_PLANO   = <?= $data_plano_js ?>;
const MAQUINAS     = <?= $maquinas_json ?>;

let allPedidos     = [];
let pedidosLoaded  = false;
let pedidosFiltred = [];
let selectedIv     = null;
let regCtx = { pp_codigo:0, iv_codigo:0, maq_codigo:0, iv_qtde:0, total_prod:0, qtde_plan:0 };

// ── Pré-carrega pedidos ao abrir a página ──────────────────────────────────
(async () => {
  try {
    const res = await api({ acao: 'get_pedidos' });
    if (res.ok) { allPedidos = res.pedidos; pedidosLoaded = true; }
  } catch(_) {}
})();

// ── Navigation ─────────────────────────────────────────────────────────────
function navDate(delta) {
  const d = new Date(DATA_PLANO + 'T12:00:00');
  d.setDate(d.getDate() + delta);
  goDate(d.toISOString().split('T')[0]);
}
function goDate(iso) {
  const t = document.getElementById('tab-planejar').style.display !== 'none' ? 'planejar' : 'apontar';
  window.location.href = '?tab=' + t + '&data=' + iso;
}

// ── Tab helpers ────────────────────────────────────────────────────────────
function addBtnHTML() {
  return `<button class="btn-new-plan" onclick="openAddModal(null)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Adicionar ao plano</button>`;
}

function switchTab(t) {
  document.getElementById('tab-planejar').style.display = t === 'planejar' ? '' : 'none';
  document.getElementById('tab-apontar' ).style.display = t === 'apontar'  ? '' : 'none';
  document.querySelectorAll('.step-tab').forEach(btn => btn.classList.remove('active'));
  event.target.closest('.step-tab').classList.add('active');
  document.getElementById('date-bar-action').innerHTML = t === 'planejar' ? addBtnHTML() : '';
  history.replaceState(null, '', '?tab=' + t + '&data=' + DATA_PLANO);
}
function switchTabDirect(t) {
  document.getElementById('tab-planejar').style.display = t === 'planejar' ? '' : 'none';
  document.getElementById('tab-apontar' ).style.display = t === 'apontar'  ? '' : 'none';
  const tabs = document.querySelectorAll('.step-tab');
  tabs[0].classList.toggle('active', t === 'planejar');
  tabs[1].classList.toggle('active', t === 'apontar');
  document.getElementById('date-bar-action').innerHTML = t === 'planejar' ? addBtnHTML() : '';
  history.replaceState(null, '', '?tab=' + t + '&data=' + DATA_PLANO);
}

// ── Modal ──────────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── API ────────────────────────────────────────────────────────────────────
async function api(body) {
  const r = await fetch(location.pathname, {
    method : 'POST',
    headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body   : JSON.stringify(body),
  });
  return r.json();
}

// ══════════════════════════════════════════════════════════════════════════
// ADD MODAL
// ══════════════════════════════════════════════════════════════════════════
async function openAddModal(maqCod) {
  selectedIv = null;
  selectedCliente = null;
  document.getElementById('add-search').value = '';
  document.getElementById('add-iv-codigo').value = '';
  document.getElementById('add-sel-info').style.display = 'none';
  document.getElementById('add-qtde').value = '';
  document.getElementById('add-cli-search').value = '';
  if (maqCod) { document.getElementById('add-maq').value = maqCod; }
  updateCapacity(); // atualiza badge de tipo e refiltra
  openModal('modal-add');

  if (!pedidosLoaded) {
    // Ainda carregando — mostra spinner e aguarda
    document.getElementById('pedido-list').innerHTML =
      '<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:12px;">Carregando pedidos…</div>';
    try {
      const res = await api({ acao: 'get_pedidos' });
      if (res.ok) { allPedidos = res.pedidos; pedidosLoaded = true; }
    } catch(_) {}
  }

  filterPedidos();
}

// ── Client filter (sem autocomplete) ──────────────────────────────────────
function filterCliente() {
  filterPedidos();
}

function getMaqTipo() {
  const sel   = document.getElementById('add-maq');
  const depto = (sel.options[sel.selectedIndex]?.dataset.depto || '').toUpperCase();
  if (depto.includes('SACARIA')) return 'sacaria';
  if (depto.includes('BAG'))     return 'bag';
  return '';
}

function updateCapacity() {
  const sel = document.getElementById('add-maq');
  const cap = parseInt(sel.options[sel.selectedIndex]?.dataset.cap || 0);
  document.getElementById('add-cap-hint').textContent =
    cap > 0 ? '(cap. estimada: ' + cap.toLocaleString('pt-BR') + ' un/dia)' : '';
  if (cap > 0 && !document.getElementById('add-qtde').value)
    document.getElementById('add-qtde').value = cap;

  // Mostra badge e refiltra lista pelo tipo da máquina
  const tipo  = getMaqTipo();
  const badge = document.getElementById('add-maq-tipo-badge');
  const txt   = document.getElementById('add-maq-tipo-txt');
  if (tipo) {
    const label = tipo === 'sacaria' ? 'Exibindo apenas pedidos de Sacaria' : 'Exibindo apenas pedidos de Bag';
    txt.textContent = '⚙ ' + label;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
  filterPedidos();
}

function filterPedidos() {
  const q    = document.getElementById('add-search').value.toLowerCase().trim();
  const cliQ = document.getElementById('add-cli-search').value.toLowerCase().trim();
  const tipo = getMaqTipo(); // 'sacaria' | 'bag' | ''

  let base = cliQ
    ? allPedidos.filter(p => p.cliente && p.cliente.toLowerCase().includes(cliQ))
    : allPedidos;

  // Filtra por categoria da máquina
  if (tipo) {
    base = base.filter(p =>
      p.pro_categoria && p.pro_categoria.toLowerCase().includes(tipo)
    );
  }

  pedidosFiltred = q
    ? base.filter(p =>
        p.pedido.toLowerCase().includes(q) ||
        p.pro_descricao.toLowerCase().includes(q))
    : base;
  renderPedidoList();
}

function renderPedidoList() {
  const list = document.getElementById('pedido-list');
  if (!pedidosFiltred.length) {
    list.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:12px;">Nenhum pedido encontrado</div>';
    return;
  }
  list.innerHTML = pedidosFiltred.slice(0,50).map(p => {
    const rest = Math.max(0, p.iv_qtde - p.total_produzido);
    const pct  = p.iv_qtde > 0 ? Math.round(p.total_produzido / p.iv_qtde * 100) : 0;
    return `<div class="pedido-opt ${selectedIv?.iv_codigo == p.iv_codigo ? 'sel' : ''}"
                 onclick="selectPedido(${JSON.stringify(p).replace(/"/g,'&quot;')})">
      <div class="pedido-opt-num">${p.pedido}</div>
      <div class="pedido-opt-desc">${p.pro_descricao}</div>
      <div class="pedido-opt-meta">
        <span style="${!p.cliente?'color:var(--text-muted);font-style:italic;':''}">${p.cliente || '—'}</span>
        <span>Entrega: ${p.entrega}</span>
        <span style="color:${rest>0?'var(--amber)':'var(--teal)'}">
          ${p.total_produzido.toLocaleString('pt-BR',{maximumFractionDigits:0})} / ${p.iv_qtde.toLocaleString('pt-BR',{maximumFractionDigits:0})} un (${pct}%)
        </span>
      </div>
    </div>`;
  }).join('');
}

function selectPedido(p) {
  selectedIv = p;
  document.getElementById('add-iv-codigo').value = p.iv_codigo;
  const rest = Math.max(0, p.iv_qtde - p.total_produzido);
  const info = document.getElementById('add-sel-info');
  info.textContent = '✓ ' + p.pedido + ' selecionado · Falta: ' + rest.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' un';
  info.style.display = 'block';
  if (rest > 0 && !document.getElementById('add-qtde').value) {
    const cap = parseInt(document.getElementById('add-maq').options[document.getElementById('add-maq').selectedIndex]?.dataset.cap || 0);
    document.getElementById('add-qtde').value = cap > 0 ? Math.min(rest, cap) : rest;
  }
  renderPedidoList();
}

async function salvarPlano() {
  const maqCod = parseInt(document.getElementById('add-maq').value);
  const ivCod  = parseInt(document.getElementById('add-iv-codigo').value);
  const qtde   = parseFloat(document.getElementById('add-qtde').value);
  if (!maqCod || !ivCod || !qtde || qtde <= 0) {
    alert('Selecione a máquina, o pedido e informe a quantidade.'); return;
  }
  const res = await api({acao:'add_plano', data:DATA_PLANO, maq_codigo:maqCod, iv_codigo:ivCod, qtde});
  if (res.ok) { closeModal('modal-add'); location.reload(); }
  else alert('Erro: ' + (res.msg || 'Falha ao salvar.'));
}

// ══════════════════════════════════════════════════════════════════════════
// DELETE
// ══════════════════════════════════════════════════════════════════════════
async function delPlano(ppCod) {
  if (!confirm('Remover este pedido do plano?')) return;
  const res = await api({acao:'del_plano', pp_codigo:ppCod});
  if (res.ok) { document.getElementById('plan-item-'+ppCod)?.remove(); }
  else alert('Erro: ' + (res.msg || 'Não foi possível remover.'));
}

// ══════════════════════════════════════════════════════════════════════════
// REGISTER MODAL
// ══════════════════════════════════════════════════════════════════════════
function showSub(id) {
  ['substep-mesma-maq','substep-outra-maq-inc','substep-prox-maq'].forEach(s => {
    document.getElementById(s).style.display = s === id ? '' : 'none';
  });
}
function hideSub(id) { document.getElementById(id).style.display = 'none'; }

function openRegModal(ctx) {
  regCtx = ctx;
  document.getElementById('reg-info-ped').textContent  = ctx.pedido;
  document.getElementById('reg-info-cli').textContent  = ctx.cliente;
  document.getElementById('reg-info-prod').textContent = ctx.pro_desc;
  document.getElementById('reg-info-maq').textContent  = '⚙ ' + ctx.maq_nome;
  document.getElementById('reg-qtde-total').textContent   = ctx.iv_qtde.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' un';
  document.getElementById('reg-qtde-ja-prod').textContent = ctx.total_prod.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' un';
  document.getElementById('reg-qtde-plan').textContent    = ctx.qtde_plan.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' un';
  document.getElementById('reg-qtde-prod').value = ctx.qtde_plan;
  // Reset
  document.getElementById('reg-step1').style.display        = '';
  document.getElementById('reg-step2').style.display        = 'none';
  document.getElementById('reg-footer-step1').style.display = '';
  document.getElementById('reg-footer-step2').style.display = 'none';
  ['res-nao-concluido','res-concluido','substep-mesma-maq','substep-outra-maq-inc','substep-prox-maq']
    .forEach(id => document.getElementById(id).style.display = 'none');
  openModal('modal-reg');
}

async function confirmarRegistro() {
  const qtde = parseFloat(document.getElementById('reg-qtde-prod').value);
  if (isNaN(qtde) || qtde < 0) { alert('Informe a quantidade produzida.'); return; }

  const res = await api({acao:'registrar_producao', pp_codigo:regCtx.pp_codigo, qtde_prod:qtde});
  if (!res.ok) { alert('Erro: ' + (res.msg || 'Falha ao registrar.')); return; }

  document.getElementById('ap-item-' + regCtx.pp_codigo)?.classList.add('is-done');

  // Avança para passo 2
  document.getElementById('reg-step1').style.display        = 'none';
  document.getElementById('reg-step2').style.display        = '';
  document.getElementById('reg-footer-step1').style.display = 'none';
  document.getElementById('reg-footer-step2').style.display = '';

  const pct = res.iv_qtde > 0 ? Math.min(100, Math.round(res.total_produzido / res.iv_qtde * 100)) : 0;
  document.getElementById('res-total-prod').textContent = res.total_produzido.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' un';
  document.getElementById('res-total-ped' ).textContent = res.iv_qtde.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' un';
  document.getElementById('res-pbar-fill').style.width  = pct + '%';
  document.getElementById('res-pct-txt').textContent    = pct + '% concluído';

  const amanha = nextDay(DATA_PLANO);
  if (res.concluido) {
    document.getElementById('res-concluido').style.display = '';
    document.getElementById('prox-maq-data').value = amanha;
    document.getElementById('prox-maq-qtde').value = Math.round(res.restante) || 1;
  } else {
    document.getElementById('res-nao-concluido').style.display = '';
    document.getElementById('res-falta').textContent = res.restante.toLocaleString('pt-BR',{maximumFractionDigits:0});
    document.getElementById('reg-maq-nome-cont').textContent = regCtx.maq_nome;
    document.getElementById('cont-data').value    = amanha;
    document.getElementById('cont-qtde').value    = Math.round(res.restante);
    document.getElementById('inc-maq-data').value = amanha;
    document.getElementById('inc-maq-qtde').value = Math.round(res.restante);
  }
}

async function confirmarContinuar() {
  const data = document.getElementById('cont-data').value;
  const qtde = parseFloat(document.getElementById('cont-qtde').value);
  if (!data || !qtde || qtde <= 0) { alert('Preencha data e quantidade.'); return; }
  const res = await api({acao:'continuar_amanha', iv_codigo:regCtx.iv_codigo, maq_codigo:regCtx.maq_codigo, qtde, data});
  if (res.ok) { closeModal('modal-reg'); location.reload(); }
  else alert('Erro: ' + (res.msg || 'Falha.'));
}

async function confirmarOutraMaqInc() {
  const maqCod = parseInt(document.getElementById('inc-maq-sel').value);
  const data   = document.getElementById('inc-maq-data').value;
  const qtde   = parseFloat(document.getElementById('inc-maq-qtde').value);
  if (!maqCod || !data || !qtde || qtde <= 0) { alert('Preencha todos os campos.'); return; }
  const res = await api({acao:'proxima_maquina', iv_codigo:regCtx.iv_codigo, maq_codigo:maqCod, qtde, data});
  if (res.ok) { closeModal('modal-reg'); location.reload(); }
  else alert('Erro: ' + (res.msg || 'Falha.'));
}

async function confirmarProxMaq() {
  const maqCod = parseInt(document.getElementById('prox-maq-sel').value);
  const data   = document.getElementById('prox-maq-data').value;
  const qtde   = parseFloat(document.getElementById('prox-maq-qtde').value);
  if (!maqCod || !data || !qtde || qtde <= 0) { alert('Preencha todos os campos.'); return; }
  const res = await api({acao:'proxima_maquina', iv_codigo:regCtx.iv_codigo, maq_codigo:maqCod, qtde, data});
  if (res.ok) { closeModal('modal-reg'); location.reload(); }
  else alert('Erro: ' + (res.msg || 'Falha.'));
}

async function finalizarPedido() {
  if (!confirm('Marcar este pedido como FINALIZADO?')) return;
  const res = await api({acao:'finalizar_pedido', iv_codigo:regCtx.iv_codigo});
  if (res.ok) { closeModal('modal-reg'); location.reload(); }
  else alert('Erro: ' + (res.msg || 'Falha.'));
}


// ── Helpers ────────────────────────────────────────────────────────────────
function nextDay(iso) {
  const d = new Date(iso + 'T12:00:00');
  d.setDate(d.getDate() + 1);
  return d.toISOString().split('T')[0];
}


document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
</script>
</body>
</html>
