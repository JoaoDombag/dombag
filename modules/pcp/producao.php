<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════
//  DOMBAG — Produção Diária
//  Suporta: inserção unitária, lote via AJAX e BI por máquina
// ══════════════════════════════════════════════════

$db_error = '';
$db_ok_msg = '';
$registros = [];
$maquinas = [];
$editando = null;
$relatorio_maq = [];
$por_maquina_hora = [];

try {
    $pdo = dbPDO();

    // ── Garante tabela ────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS producao_diaria (
            pd_codigo      INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            pd_data        DATE          NOT NULL,
            maq_codigo     INT UNSIGNED  NOT NULL,
            pd_funcionario VARCHAR(120)  NOT NULL DEFAULT '',
            pd_horario_ini TIME          NOT NULL,
            pd_horario_fim TIME          NOT NULL,
            pd_quantidade  DECIMAL(12,2) NOT NULL DEFAULT 0,
            pd_pedido      VARCHAR(30)   NOT NULL DEFAULT '',
            pd_comentario  TEXT,
            pd_criado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pd_maquina
                FOREIGN KEY (maq_codigo) REFERENCES maquinas(maq_codigo)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $filtro_data = $_GET['data'] ?? '';
    $filtro_maq_cod = intval($_GET['maq_codigo'] ?? 0);
    $data_bi = $filtro_data ?: date('Y-m-d');

    // ── AJAX ──────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // ── Atualizar horas/dia da máquina ────────────────────────────────────
        if (($body['acao'] ?? '') === 'atualizar_horas_dia') {
            $maqCod = intval($body['maq_codigo'] ?? 0);
            $horas  = (float) ($body['horas_dia'] ?? 0);
            if ($maqCod <= 0 || $horas <= 0 || $horas > 24) {
                echo json_encode(['success' => false, 'msg' => 'Dados inválidos.']);
            } else {
                $st = $pdo->prepare('UPDATE maquinas SET maq_horas_dia = :h WHERE maq_codigo = :c');
                $st->execute([':h' => $horas, ':c' => $maqCod]);
                echo json_encode(['success' => true]);
            }
            exit;
        }

        // ── Lançamento em lote ────────────────────────────────────────────────
        $linhas = $body['linhas'] ?? [];
        $salvos = 0;
        $erros = [];

        $stmt = $pdo->prepare('
            INSERT INTO producao_diaria
                (pd_data,maq_codigo,pd_funcionario,pd_horario_ini,pd_horario_fim,pd_quantidade,pd_pedido,pd_comentario)
            VALUES (:data,:maq,:func,:h_ini,:h_fim,:qtd,:ped,:com)
        ');

        $pdo->beginTransaction();
        foreach ($linhas as $i => $l) {
            $data = trim($l['data'] ?? '');
            $maq_cod = intval($l['maq'] ?? 0);
            $h_ini = trim($l['h_ini'] ?? '');
            $h_fim = trim($l['h_fim'] ?? '');
            $qtd = (float) str_replace(',', '.', $l['qtd'] ?? 0);
            $func = trim($l['func'] ?? '');
            $ped = trim($l['pedido'] ?? '');
            $com = trim($l['obs'] ?? '');

            $linha = $i + 1;
            if (!$data) {
                $erros[] = "Linha $linha: data em branco.";
                continue;
            }
            if (!$maq_cod) {
                $erros[] = "Linha $linha: máquina não selecionada.";
                continue;
            }
            if (!$h_ini) {
                $erros[] = "Linha $linha: horário inicial em branco.";
                continue;
            }
            if (!$h_fim) {
                $erros[] = "Linha $linha: horário final em branco.";
                continue;
            }
            // Permite overnight (ex: 23:00→00:00): só valida se ambos no mesmo dia
            if ($h_fim !== '00:00' && $h_fim <= $h_ini) {
                $erros[] = "Linha $linha: fim deve ser após início.";
                continue;
            }
            if ($qtd <= 0) {
                $erros[] = "Linha $linha: quantidade deve ser maior que zero.";
                continue;
            }

            $stmt->execute([
                ':data' => $data, ':maq' => $maq_cod, ':func' => $func,
                ':h_ini' => $h_ini, ':h_fim' => $h_fim, ':qtd' => $qtd,
                ':ped' => $ped, ':com' => $com,
            ]);
            $salvos++;
        }

        if ($erros && !$salvos) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'msg' => implode(' ', $erros)]);
        } else {
            $pdo->commit();

            // Auto-progressão: Pendente de produção → Produção
            $pedidosLancados = array_values(array_unique(array_filter(
                array_map(fn ($l) => trim($l['pedido'] ?? ''), $linhas)
            )));
            $progredidos = 0;
            if ($pedidosLancados) {
                $in = implode(',', array_fill(0, count($pedidosLancados), '?'));
                $stmtProg = $pdo->prepare("
                    UPDATE itens_vendas iv
                    JOIN vendas v ON v.ven_codigo = iv.ven_codigo
                    SET iv.iv_status = 'Produção', iv.iv_atualizado_em = NOW()
                    WHERE v.ven_codigo_yzidro IN ($in)
                      AND iv.iv_status = 'Pendente de produção'
                ");
                $stmtProg->execute($pedidosLancados);
                $progredidos = $stmtProg->rowCount();
            }

            $msg = "$salvos registro(s) salvo(s) com sucesso.";
            if ($progredidos) {
                $msg .= " $progredidos item(ns) avançado(s) para 'Produção'.";
            }
            if ($erros) {
                $msg .= ' Avisos: ' . implode(' ', $erros);
            }
            echo json_encode(['success' => true, 'msg' => $msg, 'salvos' => $salvos, 'progredidos' => $progredidos]);
        }
        exit;
    }

    // ── Ações POST normais ────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'salvar') {
            $cod = intval($_POST['pd_codigo'] ?? 0);
            $data = $_POST['pd_data'] ?? date('Y-m-d');
            $maq_cod = intval($_POST['maq_codigo'] ?? 0);
            $func = trim($_POST['pd_funcionario'] ?? '');
            $h_ini = $_POST['pd_horario_ini'] ?? '00:00';
            $h_fim = $_POST['pd_horario_fim'] ?? '00:00';
            $qtd = (float) str_replace(',', '.', $_POST['pd_quantidade'] ?? 0);
            $ped = trim($_POST['pd_pedido'] ?? '');
            $com = trim($_POST['pd_comentario'] ?? '');

            if (!$maq_cod || !$data) {
                $db_error = 'Data e Máquina são obrigatórios.';
            } elseif ($h_fim <= $h_ini) {
                $db_error = 'Horário de fim deve ser posterior ao horário de início.';
            } elseif ($cod > 0) {
                $pdo->prepare('
                    UPDATE producao_diaria SET
                        pd_data=:data,maq_codigo=:maq,pd_funcionario=:func,
                        pd_horario_ini=:h_ini,pd_horario_fim=:h_fim,
                        pd_quantidade=:qtd,pd_pedido=:ped,pd_comentario=:com
                    WHERE pd_codigo=:cod
                ')->execute([':data' => $data, ':maq' => $maq_cod, ':func' => $func,
                              ':h_ini' => $h_ini, ':h_fim' => $h_fim, ':qtd' => $qtd,
                              ':ped' => $ped, ':com' => $com, ':cod' => $cod]);
                $db_ok_msg = 'Registro atualizado com sucesso.';
            } else {
                $pdo->prepare('
                    INSERT INTO producao_diaria
                        (pd_data,maq_codigo,pd_funcionario,pd_horario_ini,pd_horario_fim,pd_quantidade,pd_pedido,pd_comentario)
                    VALUES (:data,:maq,:func,:h_ini,:h_fim,:qtd,:ped,:com)
                ')->execute([':data' => $data, ':maq' => $maq_cod, ':func' => $func,
                              ':h_ini' => $h_ini, ':h_fim' => $h_fim, ':qtd' => $qtd,
                              ':ped' => $ped, ':com' => $com]);
                $db_ok_msg = 'Produção registrada com sucesso.';
                // Auto-progressão: Pendente de produção → Produção
                if ($ped !== '') {
                    $stmtProg = $pdo->prepare("
                        UPDATE itens_vendas iv
                        JOIN vendas v ON v.ven_codigo = iv.ven_codigo
                        SET iv.iv_status = 'Produção', iv.iv_atualizado_em = NOW()
                        WHERE v.ven_codigo_yzidro = :ped
                          AND iv.iv_status = 'Pendente de produção'
                    ");
                    $stmtProg->execute([':ped' => $ped]);
                    if ($stmtProg->rowCount()) {
                        $db_ok_msg .= ' Status atualizado para "Produção".';
                    }
                }
            }
        }

        if ($acao === 'excluir') {
            $cod = intval($_POST['pd_codigo'] ?? 0);
            if ($cod > 0) {
                $pdo->prepare('DELETE FROM producao_diaria WHERE pd_codigo=:cod')->execute([':cod' => $cod]);
                $db_ok_msg = 'Registro excluído.';
            }
        }

        if ($acao === 'calcular_producao') {
            $rows_calc = $pdo->query("
                SELECT maq_codigo,
                    SUM(total_qtd)              AS total_qtd,
                    SUM(minutos_producao)       AS total_minutos,
                    AVG(minutos_span_dia)       AS media_minutos_dia
                FROM (
                    SELECT maq_codigo, pd_data,
                        SUM(pd_quantidade) AS total_qtd,
                        SUM(TIMESTAMPDIFF(MINUTE,
                            CONCAT('2000-01-01 ',pd_horario_ini),
                            CONCAT(IF(pd_horario_fim < pd_horario_ini,'2000-01-02','2000-01-01'),' ',pd_horario_fim))) AS minutos_producao,
                        TIMESTAMPDIFF(MINUTE,
                            MIN(pd_horario_ini),
                            MAX(pd_horario_fim)) AS minutos_span_dia
                    FROM producao_diaria
                    WHERE pd_quantidade > 0
                    GROUP BY maq_codigo, pd_data
                ) sub
                GROUP BY maq_codigo
            ")->fetchAll(PDO::FETCH_ASSOC);
            $atualizadas = 0;
            foreach ($rows_calc as $r) {
                $min = (float) $r['total_minutos'];
                if ($min > 0) {
                    $prod_min  = round($r['total_qtd'] / $min, 4);
                    $horas_dia = round((float) $r['media_minutos_dia'] / 60, 1);
                    $upd = $pdo->prepare('UPDATE maquinas SET maq_producao_min=:p, maq_horas_dia=:h WHERE maq_codigo=:c');
                    $upd->execute([':p' => $prod_min, ':h' => $horas_dia, ':c' => $r['maq_codigo']]);
                    if ($upd->rowCount() > 0) {
                        $atualizadas++;
                    }
                }
            }
            $db_ok_msg = "Prod./min e horas/dia recalculadas. $atualizadas máquina(s) atualizada(s).";
        }
    }

    // ── Edição ────────────────────────────────────────────────────────────────
    if (isset($_GET['editar'])) {
        $s = $pdo->prepare('SELECT * FROM producao_diaria WHERE pd_codigo=:cod');
        $s->execute([':cod' => intval($_GET['editar'])]);
        $editando = $s->fetch(PDO::FETCH_ASSOC);
    }

    // ── Lista de máquinas ─────────────────────────────────────────────────────
    $maquinas = $pdo->query('
        SELECT m.maq_codigo, m.maq_descricao, d.dp_descricao
        FROM maquinas m
        INNER JOIN departamentos d ON d.dp_codigo = m.dp_codigo
        ORDER BY d.dp_descricao, m.maq_descricao
    ')->fetchAll(PDO::FETCH_ASSOC);

    // ── Apontamentos (tabela) ─────────────────────────────────────────────────
    $where = 'WHERE 1=1';
    $params = [];
    if ($filtro_data) {
        $where .= ' AND pd.pd_data=:data';
        $params[':data'] = $filtro_data;
    }
    if ($filtro_maq_cod) {
        $where .= ' AND pd.maq_codigo=:maq_cod';
        $params[':maq_cod'] = $filtro_maq_cod;
    }

    $stmt = $pdo->prepare("
        SELECT pd.*, m.maq_descricao, d.dp_descricao,
               TIMESTAMPDIFF(MINUTE,
                   CONCAT('2000-01-01 ',pd.pd_horario_ini),
                   CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini,'2000-01-02','2000-01-01'),' ',pd.pd_horario_fim)) AS minutos_trabalhados
        FROM producao_diaria pd
        INNER JOIN maquinas m ON m.maq_codigo=pd.maq_codigo
        INNER JOIN departamentos d ON d.dp_codigo=m.dp_codigo
        $where
        ORDER BY pd.pd_data DESC, pd.pd_horario_ini DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── BI: resumo por máquina ────────────────────────────────────────────────
    $stmt_bi = $pdo->prepare("
        SELECT
            pd.maq_codigo,
            m.maq_descricao,
            d.dp_descricao,
            COALESCE(m.maq_qtde,1)         AS qtde_maquinas,
            COALESCE(m.maq_horas_dia,8)    AS horas_dia,
            COALESCE(m.maq_producao_min,0) AS prod_min_cadastro,
            SUM(pd.pd_quantidade)          AS total_und,
            COUNT(*)                       AS apontamentos,
            ROUND(SUM(pd.pd_quantidade) /
                NULLIF(SUM(TIMESTAMPDIFF(MINUTE,
                    CONCAT(pd.pd_data, ' ', pd.pd_horario_ini),
                    CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini, DATE_ADD(pd.pd_data, INTERVAL 1 DAY), pd.pd_data), ' ', pd.pd_horario_fim))),0),4) AS prod_min_real,
            MIN(DATE_FORMAT(pd.pd_horario_ini,'%H:%i')) AS hora_inicio,
            MAX(DATE_FORMAT(pd.pd_horario_fim,'%H:%i')) AS hora_fim,
            GROUP_CONCAT(DISTINCT pd.pd_funcionario ORDER BY pd.pd_funcionario SEPARATOR ', ') AS funcionarios,
            GROUP_CONCAT(DISTINCT pd.pd_pedido      ORDER BY pd.pd_pedido      SEPARATOR ', ') AS pedidos,
            SUM(TIMESTAMPDIFF(MINUTE,
                CONCAT(pd.pd_data, ' ', pd.pd_horario_ini),
                CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini, DATE_ADD(pd.pd_data, INTERVAL 1 DAY), pd.pd_data), ' ', pd.pd_horario_fim))) AS minutos_trabalhados
        FROM producao_diaria pd
        LEFT JOIN maquinas m ON m.maq_codigo=pd.maq_codigo
        LEFT JOIN departamentos d ON d.dp_codigo=m.dp_codigo
        WHERE pd.pd_data = :data_bi
        GROUP BY pd.maq_codigo,m.maq_descricao,d.dp_descricao,m.maq_qtde,m.maq_horas_dia,m.maq_producao_min
        ORDER BY total_und DESC
    ");
    $stmt_bi->execute([':data_bi' => $data_bi]);
    $relatorio_maq = $stmt_bi->fetchAll(PDO::FETCH_ASSOC);

    foreach ($relatorio_maq as &$rm) {
        $cap = (float) $rm['qtde_maquinas'] * (float) $rm['prod_min_cadastro'] * 60.0 * (float) $rm['horas_dia'];
        $rm['cap_dia_und'] = $cap;
        $rm['pct_cap'] = $cap > 0 ? min(100, round((float) $rm['total_und'] / $cap * 100, 1)) : null;
        $rm['horas_por_hora'] = [];
    }
    unset($rm);

    $stmt_h = $pdo->prepare("
        SELECT pd.maq_codigo,
               DATE_FORMAT(pd.pd_horario_ini,'%H:%i') AS hora,
               SUM(pd.pd_quantidade) AS qtd
        FROM producao_diaria pd
        WHERE pd.pd_data = :data_bi
        GROUP BY pd.maq_codigo, DATE_FORMAT(pd.pd_horario_ini,'%H:%i')
        ORDER BY pd.maq_codigo, hora
    ");
    $stmt_h->execute([':data_bi' => $data_bi]);
    foreach ($stmt_h->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $por_maquina_hora[$r['maq_codigo']][$r['hora']] = (float) $r['qtd'];
    }
    foreach ($relatorio_maq as &$rm) {
        $rm['horas_por_hora'] = $por_maquina_hora[$rm['maq_codigo']] ?? [];
    }
    unset($rm);

} catch (PDOException $e) {
    $db_error = 'Erro no banco de dados: ' . $e->getMessage();
}

// KPIs
$total_hoje = $total_geral = 0;
$maq_ativas = [];
foreach ($registros as $r) {
    $total_geral += $r['pd_quantidade'];
    if ($r['pd_data'] === date('Y-m-d')) {
        $total_hoje += $r['pd_quantidade'];
    }
    $maq_ativas[$r['maq_codigo']] = true;
}

$js_relatorio = json_encode($relatorio_maq, JSON_UNESCAPED_UNICODE);
$js_data_bi = json_encode(date('d/m/Y', strtotime($data_bi)));
$data_bi_label = date('d/m/Y', strtotime($data_bi));

// Opções de máquina para o JS (lote)
$maquinas_json = json_encode(array_map(fn ($m) => [
    'codigo' => $m['maq_codigo'],
    'nome' => $m['maq_descricao'],
    'depto' => $m['dp_descricao'],
], $maquinas), JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Produção Diária | DOMBAG</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
/* ─── Exclusivo desta página ─────────────────────────────────── */

/* Botões contextuais de tabela */
.btn-teal{background:rgba(0,201,167,.12);color:var(--teal);border:1px solid rgba(0,201,167,.25);border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn-teal:hover{background:rgba(0,201,167,.22);}
.btn-sm-edit{background:rgba(45,106,255,.1);border:1px solid rgba(45,106,255,.2);color:#7db3ff;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;text-decoration:none;}
.btn-sm-edit:hover{background:rgba(45,106,255,.2);}
.btn-sm-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:var(--red);padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;}
.btn-sm-danger:hover{background:rgba(239,68,68,.22);}

/* BI cards */
.bi-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.bi-section-title{font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;}
.bi-date-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:rgba(45,106,255,.12);color:#7db3ff;}
.maq-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:24px;}
.maq-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .2s,box-shadow .2s;}
.maq-card:hover{border-color:rgba(45,106,255,.3);box-shadow:0 4px 18px rgba(0,0,0,.18);}
.maq-card-head{padding:12px 16px 10px;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border);}
.maq-card-name{font-size:14px;font-weight:700;line-height:1.2;}
.maq-card-meta{display:flex;align-items:center;gap:5px;margin-top:5px;flex-wrap:wrap;}
.badge-depto{font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:rgba(167,139,250,.12);color:#a78bfa;}
.badge-qtde{font-size:10px;font-weight:600;color:var(--text-muted);}
.badge-horario{font-size:10.5px;font-weight:600;color:var(--text-muted);display:inline-flex;align-items:center;gap:3px;white-space:nowrap;}
.perf-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;flex-shrink:0;}
.perf-ok{background:rgba(0,201,167,.12);color:var(--teal);}
.perf-low{background:rgba(239,68,68,.1);color:var(--red);}
.perf-na{background:rgba(255,255,255,.06);color:var(--text-muted);}
.maq-card-body{padding:14px 16px 12px;}
.maq-und-big{font-size:30px;font-weight:800;letter-spacing:-1px;line-height:1;margin-bottom:14px;}
.maq-und-label{font-size:13px;font-weight:500;color:var(--text-muted);margin-left:5px;letter-spacing:0;}
.maq-cap-row{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.cap-bar-bg{flex:1;height:6px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden;}
.cap-bar-fill{height:100%;border-radius:3px;transition:width .6s ease;}
.cap-pct{font-size:12px;font-weight:700;white-space:nowrap;flex-shrink:0;min-width:36px;text-align:right;}
.cap-lbl-txt{font-size:10px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;}
.maq-prodmin{font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:6px;}
.maq-prodmin strong{color:var(--text-primary);}
.maq-chart-wrap{position:relative;height:72px;width:100%;margin-top:12px;padding-top:8px;border-top:1px solid var(--border);}
.maq-card-footer{padding:7px 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:1px solid var(--border);background:rgba(0,0,0,.06);font-size:11px;color:var(--text-muted);}
.maq-card-footer .ped-tag{background:rgba(45,106,255,.1);color:#7db3ff;padding:1px 6px;border-radius:4px;font-weight:700;font-size:10.5px;}
.bi-empty{text-align:center;padding:32px 20px;color:var(--text-muted);font-size:12.5px;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:24px;}

/* Layout form+tabela */
.page-grid{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start;}
@media(max-width:1100px){.page-grid{grid-template-columns:1fr;}.maq-cards-grid{grid-template-columns:repeat(auto-fill,minmax(260px,1fr));}}

/* Badges de tabela */
.qtd-val{font-weight:700;color:#7db3ff;}
.ped-badge{display:inline-block;background:rgba(45,106,255,.12);color:#7db3ff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;}
.depto-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(167,139,250,.1);color:#a78bfa;}
.min-badge{display:inline-block;background:rgba(0,201,167,.08);color:var(--teal);padding:2px 7px;border-radius:4px;font-size:11px;}
.badge-edit{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(245,158,11,.12);color:var(--amber);display:none;}
.badge-edit.visible{display:inline-flex;align-items:center;gap:4px;}
.td-actions{display:flex;align-items:center;gap:5px;}
.table-panel-head{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.table-panel-head h2{font-size:14px;font-weight:600;}
.table-panel-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

/* ─── Modal Lote ──────────────────────────────────────────────── */
.modal-lote{
  position:fixed;inset:0;
  background:rgba(0,0,0,.72);
  backdrop-filter:blur(6px);
  z-index:600;
  display:flex;align-items:flex-start;justify-content:center;
  padding:20px 16px;
  overflow-y:auto;
  opacity:0;pointer-events:none;transition:opacity .2s;
}
.modal-lote.open{opacity:1;pointer-events:all;}
.modal-lote-box{
  background:#0f1e35;
  border:1px solid rgba(255,255,255,.1);
  border-radius:16px;
  width:100%;max-width:880px;
  box-shadow:0 24px 72px rgba(0,0,0,.6);
  transform:translateY(14px) scale(.98);
  transition:transform .2s;
  overflow:hidden;
}
.modal-lote.open .modal-lote-box{transform:translateY(0) scale(1);}

.modal-lote-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;
  border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.025);
}
.modal-lote-head h2{font-size:15px;font-weight:700;}
.modal-lote-head p{font-size:12px;color:var(--text-muted);margin-top:3px;}
.modal-close{width:34px;height:34px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0;}
.modal-close:hover{background:var(--card-hover);color:var(--text-primary);}

/* Configurações do lote */
.lote-config{display:grid;grid-template-columns:1fr 2fr 1.5fr;gap:12px;align-items:end;padding:16px 20px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.1);}
.lote-config-field{display:flex;flex-direction:column;gap:5px;}
.lote-config-field label{font-size:10.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text-muted);}
.lote-config-field input,
.lote-config-field select{width:100%;height:38px;padding:0 10px;font-size:13px;}

/* Tabela do lote */
.lote-table-wrap{overflow-x:auto;padding:0 20px;}
.lote-table{width:100%;border-collapse:collapse;min-width:620px;}
.lote-table thead th{
  padding:8px 6px;font-size:10px;font-weight:700;letter-spacing:.6px;
  color:var(--text-muted);text-transform:uppercase;
  border-bottom:1px solid var(--border);background:rgba(0,0,0,.1);
  white-space:nowrap;text-align:left;
}
.lote-table tbody tr td{padding:4px 4px;border-bottom:1px solid rgba(255,255,255,.04);}
.lote-table tbody tr:last-child td{border-bottom:none;}
.lote-table tbody tr:hover td{background:rgba(255,255,255,.02);}
.lote-table tbody tr.row-split td{background:rgba(45,106,255,.04);}

/* Inputs dentro da tabela de lote */
.lote-input{
  width:100%;height:34px;padding:0 8px;
  background:rgba(255,255,255,.04);
  border:1px solid var(--border);border-radius:7px;
  color:var(--text-primary);font-family:inherit;font-size:12.5px;
  outline:none;transition:border-color .15s,background .15s;
}
.lote-input:focus{border-color:rgba(45,106,255,.5);background:rgba(45,106,255,.06);}
.lote-input.err{border-color:rgba(239,68,68,.5);background:rgba(239,68,68,.05);}
.lote-select{
  width:100%;height:34px;padding:0 6px;
  background:rgba(255,255,255,.04);
  border:1px solid var(--border);border-radius:7px;
  color:var(--text-primary);font-family:inherit;font-size:12.5px;
  cursor:pointer;outline:none;transition:border-color .15s;
}
.lote-select:focus{border-color:rgba(45,106,255,.5);}
.lote-select.err{border-color:rgba(239,68,68,.5);}

/* Inputs de tempo e quantidade */
.lote-time{width:74px;height:34px;padding:0 6px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:7px;color:var(--text-primary);font-family:inherit;font-size:13px;font-weight:600;text-align:center;outline:none;transition:border-color .15s,background .15s;}
.lote-time:focus{border-color:rgba(45,106,255,.5);background:rgba(45,106,255,.06);}
.lote-time.err{border-color:rgba(239,68,68,.5);background:rgba(239,68,68,.05);}
.lote-qtd-inp{width:84px;height:34px;padding:0 6px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:7px;color:var(--text-primary);font-family:inherit;font-size:14px;font-weight:700;text-align:center;outline:none;transition:border-color .15s,background .15s;}
.lote-qtd-inp:focus{border-color:rgba(45,106,255,.5);background:rgba(45,106,255,.06);}
.lote-qtd-inp.err{border-color:rgba(239,68,68,.5);}
.lote-obs-inp{width:100%;height:34px;padding:4px 8px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:7px;color:var(--text-primary);font-family:inherit;font-size:12px;outline:none;transition:border-color .15s,background .15s;resize:none;line-height:1.3;}
.lote-obs-inp:focus{border-color:rgba(45,106,255,.5);background:rgba(45,106,255,.06);}

/* Botões por linha */
.lote-row-num{font-size:11px;font-weight:700;color:var(--text-muted);text-align:center;width:24px;}
.btn-split{width:26px;height:26px;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;background:rgba(0,201,167,.1);color:var(--teal);flex-shrink:0;font-size:13px;font-weight:700;line-height:1;}
.btn-split:hover{background:rgba(0,201,167,.22);}
.btn-split[title]:hover::after{content:attr(title);position:absolute;background:#152845;border:1px solid var(--border);padding:3px 8px;border-radius:6px;font-size:10px;color:var(--text-primary);white-space:nowrap;z-index:10;pointer-events:none;}
.btn-lote-del{width:26px;height:26px;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;background:rgba(239,68,68,.1);color:var(--red);flex-shrink:0;}
.btn-lote-del:hover{background:rgba(239,68,68,.22);}
.lote-row-extra{background:rgba(45,106,255,.04) !important;}

/* Footer modal */
.modal-lote-foot{
  display:flex;align-items:center;justify-content:space-between;
  gap:12px;padding:14px 20px;
  border-top:1px solid var(--border);
  background:rgba(0,0,0,.1);
  flex-wrap:wrap;
}
.lote-status{font-size:12px;color:var(--text-muted);}
.lote-status strong{color:var(--text-primary);}

/* Turno toggle */
.btn-turno-toggle{padding:5px 12px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .14s;}
.btn-turno-toggle:hover{background:rgba(255,255,255,.07);}
.btn-turno-toggle.active{background:rgba(45,106,255,.15);border-color:rgba(45,106,255,.35);color:#7db3ff;}
.btn-turno-toggle.active.noturno{background:rgba(167,139,250,.14);border-color:rgba(167,139,250,.35);color:#a78bfa;}

/* Notice lote */
.lote-notice{display:none;padding:10px 16px;border-radius:8px;font-size:12.5px;margin:0 20px 14px;}
.lote-notice.show{display:block;}
.lote-notice.ok{background:rgba(0,201,167,.1);border:1px solid rgba(0,201,167,.2);color:var(--teal);}
.lote-notice.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}

/* Modal simples (exclusão/cálculo) */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#152845;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:26px 26px 20px;width:420px;max-width:calc(100vw - 32px);transform:translateY(10px) scale(.98);transition:transform .2s;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-box h3{font-size:15px;font-weight:600;margin-bottom:8px;}
.modal-box p{font-size:12.5px;color:var(--text-muted);line-height:1.55;}
.modal-actions{display:flex;gap:8px;margin-top:20px;justify-content:flex-end;}
@media(max-width:768px){.page-grid{grid-template-columns:1fr;}.maq-cards-grid{grid-template-columns:repeat(2,1fr);}.modal-lote-box{width:calc(100vw - 24px);max-width:none;}.lote-table{min-width:540px;}}
@media(max-width:480px){.maq-cards-grid{grid-template-columns:1fr;}.modal-actions{flex-wrap:wrap;}}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">

    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Produção Diária</h1>
          <p>Apontamentos por período · <?= date('d/m/Y') ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-teal" onclick="abrirModalCalculo()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83"/></svg>
          Calcular Prod./min
        </button>
        <button class="btn-secondary" onclick="abrirLote()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="17" width="18" height="4" rx="1"/></svg>
          Lançamento em Lote
        </button>
        <button class="btn-primary" onclick="novoRegistro()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Novo Registro
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($db_error): ?>
      <div class="alert alert-err">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($db_error) ?>
      </div>
      <?php endif; ?>
      <?php if ($db_ok_msg): ?>
      <div class="alert alert-ok" id="alertOk">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($db_ok_msg) ?>
      </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-strip">
        <div class="kpi-mini c-blue">
          <div class="kpi-mini-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
          <div><div class="kpi-mini-val"><?= count($registros) ?></div><div class="kpi-mini-lbl">Apontamentos</div></div>
        </div>
        <div class="kpi-mini c-teal">
          <div class="kpi-mini-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
          <div><div class="kpi-mini-val"><?= number_format($total_geral, 0, ',', '.') ?></div><div class="kpi-mini-lbl">Unidades (filtro)</div></div>
        </div>
        <div class="kpi-mini c-amber">
          <div class="kpi-mini-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div><div class="kpi-mini-val"><?= number_format($total_hoje, 0, ',', '.') ?></div><div class="kpi-mini-lbl">Unidades hoje</div></div>
        </div>
        <div class="kpi-mini c-red">
          <div class="kpi-mini-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
          <div><div class="kpi-mini-val"><?= count($maq_ativas) ?></div><div class="kpi-mini-lbl">Máquinas ativas</div></div>
        </div>
      </div>

      <!-- BI -->
      <div class="bi-section-head">
        <div class="bi-section-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Desempenho por Máquina
          <span class="bi-date-badge"><?= $data_bi_label ?></span>
        </div>
      </div>

      <?php if (empty($relatorio_maq)): ?>
      <div class="bi-empty">Nenhum apontamento em <?= $data_bi_label ?>. Use o filtro para selecionar outra data.</div>
      <?php endif; ?>

      <div class="maq-cards-grid" id="maqCardsGrid"></div>

      <!-- Form + Tabela -->
      <div class="page-grid">

        <!-- Formulário unitário -->
        <div class="form-panel" id="formPanel">
          <div class="form-panel-head">
            <h2 id="formTitle">Novo Apontamento</h2>
            <span class="badge-edit" id="badgeEdit">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Editando
            </span>
          </div>
          <form method="POST" id="frmProducao" onsubmit="return validarForm()">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="pd_codigo" id="f_codigo" value="<?= $editando ? intval($editando['pd_codigo']) : 0 ?>">
            <div class="form-body">
              <div class="field">
                <label>Data *</label>
                <input type="date" name="pd_data" id="f_data" value="<?= $editando ? htmlspecialchars($editando['pd_data']) : date('Y-m-d', strtotime('-1 day')) ?>" required>
              </div>
              <div class="field-row">
                <div class="field">
                  <label>Início *</label>
                  <input type="time" name="pd_horario_ini" id="f_h_ini" value="<?= $editando ? htmlspecialchars(substr($editando['pd_horario_ini'], 0, 5)) : date('H:00') ?>" required>
                </div>
                <div class="field">
                  <label>Fim *</label>
                  <input type="time" name="pd_horario_fim" id="f_h_fim" value="<?= $editando ? htmlspecialchars(substr($editando['pd_horario_fim'], 0, 5)) : date('H:00', strtotime('+1 hour')) ?>" required>
                </div>
              </div>
              <div class="field">
                <label>Máquina *</label>
                <select name="maq_codigo" id="f_maquina" required>
                  <option value="">— Selecione —</option>
                  <?php $depto_atual = '';
foreach ($maquinas as $m):
    if ($m['dp_descricao'] !== $depto_atual) {
        if ($depto_atual !== '') {
            echo '</optgroup>';
        }
        echo '<optgroup label="' . htmlspecialchars($m['dp_descricao']) . '">';
        $depto_atual = $m['dp_descricao'];
    }
    ?>
                  <option value="<?= $m['maq_codigo'] ?>" <?= ($editando && $editando['maq_codigo'] == $m['maq_codigo']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['maq_descricao']) ?>
                  </option>
                  <?php endforeach;
if ($depto_atual) {
    echo '</optgroup>';
} ?>
                  <?php if (empty($maquinas)): ?><option disabled>Nenhuma máquina cadastrada</option><?php endif; ?>
                </select>
              </div>
              <div class="field">
                <label>Funcionário</label>
                <input type="text" name="pd_funcionario" id="f_funcionario" placeholder="Nome do operador" value="<?= $editando ? htmlspecialchars($editando['pd_funcionario']) : '' ?>">
              </div>
              <div class="field-row">
                <div class="field">
                  <label>Quantidade *</label>
                  <input type="number" name="pd_quantidade" id="f_qtd" min="0" step="1" placeholder="0" value="<?= $editando ? htmlspecialchars($editando['pd_quantidade']) : '' ?>" required>
                </div>
                <div class="field">
                  <label>Pedido / OP</label>
                  <input type="text" name="pd_pedido" id="f_pedido" placeholder="Ex: 2807" value="<?= $editando ? htmlspecialchars($editando['pd_pedido']) : '' ?>">
                </div>
              </div>
              <div class="field">
                <label>Comentário</label>
                <textarea name="pd_comentario" id="f_comentario" placeholder="Paradas, ajustes, ocorrências…"><?= $editando ? htmlspecialchars($editando['pd_comentario']) : '' ?></textarea>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span id="btnSalvarTxt">Salvar</span>
              </button>
              <button type="button" class="btn-secondary" id="btnCancelar" onclick="novoRegistro()" style="display:<?= $editando ? 'inline-flex' : 'none' ?>;">Cancelar</button>
            </div>
          </form>
        </div>

        <!-- Tabela de apontamentos -->
        <div class="panel">
          <div class="table-panel-head">
            <h2>Apontamentos</h2>
            <div class="table-panel-filters">
              <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="date" name="data" class="filter-input" value="<?= htmlspecialchars($filtro_data) ?>" title="Filtrar por data">
                <select name="maq_codigo" class="filter-input" style="width:150px;">
                  <option value="">Todas as máquinas</option>
                  <?php foreach ($maquinas as $m): ?>
                  <option value="<?= $m['maq_codigo'] ?>" <?= $filtro_maq_cod == $m['maq_codigo'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['maq_descricao']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary" style="height:36px;padding:0 12px;font-size:12px;">Filtrar</button>
                <?php if ($filtro_data || $filtro_maq_cod): ?>
                <a href="/pcp/producao" class="btn-secondary" style="height:36px;padding:0 10px;font-size:12px;">✕</a>
                <?php endif; ?>
              </form>
              <span class="count-badge"><?= count($registros) ?></span>
            </div>
          </div>

          <?php if (empty($registros)): ?>
          <div class="empty-state">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
            <p>Nenhum apontamento encontrado.<br>Use o formulário ao lado para registrar.</p>
          </div>
          <?php else: ?>
          <div style="overflow-x:auto;">
            <table>
              <thead>
                <tr>
                  <th>Data</th><th>Período</th><th>Máquina</th><th>Depto</th>
                  <th>Funcionário</th><th class="td-right">Qtd</th>
                  <th class="td-center">Duração</th><th>Pedido</th><th>Obs.</th><th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($registros as $r):
                  $isEdit = ($editando && $editando['pd_codigo'] == $r['pd_codigo']); ?>
              <tr id="row-<?= $r['pd_codigo'] ?>" <?= $isEdit ? 'style="background:rgba(245,158,11,.06);"' : '' ?>>
                <td class="td-muted td-nowrap"><?= date('d/m/Y', strtotime($r['pd_data'])) ?></td>
                <td class="td-muted td-nowrap"><?= substr($r['pd_horario_ini'], 0, 5) ?> – <?= substr($r['pd_horario_fim'], 0, 5) ?></td>
                <td><strong><?= htmlspecialchars($r['maq_descricao']) ?></strong></td>
                <td><span class="depto-badge"><?= htmlspecialchars($r['dp_descricao']) ?></span></td>
                <td class="td-muted"><?= htmlspecialchars($r['pd_funcionario']) ?: '—' ?></td>
                <td class="td-right"><span class="qtd-val"><?= number_format($r['pd_quantidade'], 0, ',', '.') ?></span></td>
                <td class="td-center">
                  <?php if ($r['minutos_trabalhados'] > 0): ?>
                    <span class="min-badge"><?= $r['minutos_trabalhados'] ?>min</span>
                  <?php else: ?>
                    <span class="td-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $r['pd_pedido']
                      ? '<span class="ped-badge">' . htmlspecialchars($r['pd_pedido']) . '</span>'
                      : '<span class="td-muted">—</span>' ?>
                </td>
                <td class="td-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['pd_comentario']) ?>">
                  <?= $r['pd_comentario']
                      ? htmlspecialchars(mb_substr($r['pd_comentario'], 0, 28)) . (mb_strlen($r['pd_comentario']) > 28 ? '…' : '')
                      : '—' ?>
                </td>
                <td>
                  <div class="td-actions">
                    <a href="?editar=<?= $r['pd_codigo'] ?><?= $filtro_data ? "&data=$filtro_data" : '' ?><?= $filtro_maq_cod ? "&maq_codigo=$filtro_maq_cod" : '' ?>" class="btn-sm-edit">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      Editar
                    </a>
                    <button class="btn-sm-danger" onclick="confirmarExclusao(<?= $r['pd_codigo'] ?>,'<?= addslashes(date('d/m/Y', strtotime($r['pd_data']))) ?>',<?= htmlspecialchars(json_encode($r['maq_descricao'])) ?>)">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                      Excluir
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

      </div><!-- /page-grid -->
    </div><!-- /content -->
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL — LANÇAMENTO EM LOTE
══════════════════════════════════════════════════════════ -->
<div class="modal-lote" id="modalLote">
  <div class="modal-lote-box">

    <div class="modal-lote-head">
      <div>
        <h2>Lançamento em Lote</h2>
        <p>Selecione a data e a máquina uma vez, depois preencha apenas as quantidades de cada turno.</p>
      </div>
      <div style="display:flex;align-items:center;gap:6px;margin-right:10px;">
        <button id="btn-turno-dia" class="btn-turno-toggle active" onclick="setTurno('dia')">☀ Diurno</button>
        <button id="btn-turno-noc" class="btn-turno-toggle" onclick="setTurno('noturno')">🌙 Noturno</button>
      </div>
      <button class="modal-close" onclick="fecharLote()" title="Fechar">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- Cabeçalho: data + máquina + funcionário — selecionados UMA VEZ -->
    <div class="lote-config">
      <div class="lote-config-field">
        <label>Data *</label>
        <input type="date" id="lote_data_padrao" class="lote-input">
      </div>
      <div class="lote-config-field">
        <label>Máquina *</label>
        <select id="lote_maq_padrao" class="lote-select">
          <option value="">— Selecione a máquina —</option>
          <?php $dp = '';
foreach ($maquinas as $m):
    if ($m['dp_descricao'] !== $dp) {
        if ($dp) {
            echo '</optgroup>';
        }
        echo '<optgroup label="' . htmlspecialchars($m['dp_descricao']) . '">';
        $dp = $m['dp_descricao'];
    }
    ?>
          <option value="<?= $m['maq_codigo'] ?>"><?= htmlspecialchars($m['maq_descricao']) ?></option>
          <?php endforeach;
if ($dp) {
    echo '</optgroup>';
} ?>
        </select>
      </div>
      <div class="lote-config-field">
        <label>Funcionário *</label>
        <input type="text" id="lote_func_padrao" class="lote-input" placeholder="Nome do operador">
      </div>
    </div>

    <!-- Notice -->
    <div style="padding:10px 20px 0;">
      <div id="lote-notice" class="lote-notice"></div>
    </div>

    <!-- Tabela de turnos -->
    <div class="lote-table-wrap" style="padding-bottom:4px;">
      <table class="lote-table">
        <thead>
          <tr>
            <th style="width:26px;text-align:center;">#</th>
            <th style="width:78px;">Início *</th>
            <th style="width:78px;">Fim *</th>
            <th style="width:90px;">Quantidade *</th>
            <th style="width:110px;">Pedido / OP</th>
            <th style="min-width:160px;">Observação</th>
            <th style="width:58px;text-align:center;">Ações</th>
          </tr>
        </thead>
        <tbody id="loteTbody"></tbody>
      </table>
    </div>

    <div class="modal-lote-foot">
      <div class="lote-status" id="lote-status">
        <strong id="lote-count">0</strong> turno(s) com quantidade · <span id="lote-total-rows">12</span> linha(s) no total
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn-secondary" onclick="limparQuantidades()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-9"/></svg>
          Limpar quantidades
        </button>
        <button class="btn-secondary" onclick="fecharLote()">Cancelar</button>
        <button class="btn-primary" id="btnSalvarLote" onclick="salvarLote()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Salvar tudo
        </button>
      </div>
    </div>

  </div>
</div>

<!-- Modal exclusão -->
<div class="modal-overlay" id="modalExclusao">
  <div class="modal-box">
    <h3>Confirmar exclusão</h3>
    <p id="modalExclusaoTexto"></p>
    <form method="POST" id="frmExclusao">
      <input type="hidden" name="acao" value="excluir">
      <input type="hidden" name="pd_codigo" id="excluir_codigo" value="">
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal('modalExclusao')">Cancelar</button>
        <button type="submit" class="btn-sm-danger">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal cálculo -->
<div class="modal-overlay" id="modalCalculo">
  <div class="modal-box">
    <h3>Calcular Produção / min</h3>
    <p>Recalcula <code>maq_producao_min</code> para cada máquina usando os minutos reais entre início e fim de cada apontamento.</p>
    <p style="margin-top:8px;font-family:monospace;font-size:12px;color:#7db3ff;">prod/min = Σ quantidade ÷ Σ minutos trabalhados</p>
    <form method="POST" id="frmCalculo">
      <input type="hidden" name="acao" value="calcular_producao">
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal('modalCalculo')">Cancelar</button>
        <button type="submit" class="btn-teal">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83"/></svg>
          Calcular e Atualizar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Dados do PHP ──────────────────────────────────────────────────────────────
const RELATORIO  = <?= $js_relatorio ?>;
const DATA_BI    = <?= $js_data_bi ?>;
const MAQUINAS   = <?= $maquinas_json ?>;

// ═════════════════════════════════════════════════════════════════════════════
//  BI: Cards por máquina
// ═════════════════════════════════════════════════════════════════════════════
const PALETTE = ['#2d6aff','#00c9a7','#f59e0b','#ef4444','#a78bfa',
                 '#38bdf8','#fb7185','#34d399','#fbbf24','#60a5fa'];

(function buildCards() {
  const grid = document.getElementById('maqCardsGrid');
  if (!RELATORIO.length) return;

  RELATORIO.forEach((r, idx) => {
    const cor   = PALETTE[idx % PALETTE.length];
    const real     = parseFloat(r.prod_min_real)    || 0;
    const cad      = parseFloat(r.prod_min_cadastro) || 0;
    const cadTotal = cad * (parseFloat(r.qtde_maquinas) || 1);
    const delta    = cadTotal > 0 ? ((real - cadTotal) / cadTotal * 100) : null;
    const und   = parseFloat(r.total_und) || 0;
    const min   = parseInt(r.minutos_trabalhados) || 0;
    const id    = 'mc-' + r.maq_codigo;

    const perf = delta === null
      ? `<span class="perf-pill perf-na">Sem cad.</span>`
      : delta >= 0
        ? `<span class="perf-pill perf-ok">▲ +${delta.toFixed(1)}%</span>`
        : `<span class="perf-pill perf-low">▼ ${delta.toFixed(1)}%</span>`;

    const pct0 = r.pct_cap !== null ? parseFloat(r.pct_cap) : 0;
    const capColor = pct0 >= 90 ? 'var(--teal)' : pct0 >= 60 ? 'var(--amber)' : 'var(--red)';

    const capDia = parseFloat(r.cap_dia_und) || 0;
    const capDiaFmt = capDia > 0 ? capDia.toLocaleString('pt-BR', {maximumFractionDigits:0}) : '—';
    const capRow = cad > 0
      ? `<div class="maq-cap-row">
           <div class="cap-bar-bg"><div class="cap-bar-fill" id="capf-${r.maq_codigo}" style="width:${pct0}%;background:${capColor};"></div></div>
           <span class="cap-pct" id="capp-${r.maq_codigo}" style="color:${capColor};">${pct0}%</span>
         </div>
         <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px;">
           <span style="color:var(--text-primary);font-weight:600;">${und.toLocaleString('pt-BR')}</span>
           <span id="captxt-${r.maq_codigo}">de ${capDiaFmt} un/dia possíveis</span>
         </div>`
      : `<div class="maq-cap-row"><span style="font-size:11px;color:var(--amber);">⚠ Sem velocidade cadastrada</span></div>`;

    const realColor = delta !== null ? (delta >= 0 ? 'var(--teal)' : 'var(--red)') : 'var(--text-primary)';
    const prodMinRow = (real > 0 || cadTotal > 0)
      ? `<div class="maq-prodmin">
           <strong style="color:${realColor};">${real > 0 ? real.toFixed(2) : '—'}/min</strong>
           real
           ${cadTotal > 0 ? `<span style="margin-left:4px;">· meta <strong>${cadTotal.toFixed(2)}/min</strong></span>` : ''}
         </div>`
      : '';

    const footer = (r.funcionarios || r.pedidos || min > 0)
      ? `<div class="maq-card-footer">
           ${r.funcionarios ? `<span>${r.funcionarios}</span>` : ''}
           ${r.pedidos ? r.pedidos.split(',').map(p=>`<span class="ped-tag">${p.trim()}</span>`).join('') : ''}
           ${min > 0 ? `<span style="margin-left:auto;">${(min/60).toFixed(1)}h</span>` : ''}
         </div>`
      : '';

    const el = document.createElement('div');
    el.className = 'maq-card';
    el.innerHTML = `
      <div class="maq-card-head" style="border-top:3px solid ${cor};">
        <div>
          <div class="maq-card-name">${r.maq_descricao}</div>
          <div class="maq-card-meta">
            ${r.dp_descricao ? `<span class="badge-depto">${r.dp_descricao}</span>` : ''}
            <span class="badge-horario">
              <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              ${r.hora_inicio||'—'} – ${r.hora_fim||'—'}
            </span>
            <span class="badge-qtde"><input type="number" id="hd-${r.maq_codigo}" value="${r.horas_dia}" min="0.5" max="24" step="0.5" style="width:34px;background:transparent;border:none;border-bottom:1px dashed currentColor;color:inherit;font:inherit;font-size:inherit;text-align:center;padding:0;" oninput="recalcCap('${r.maq_codigo}',${und},${cad},${r.qtde_maquinas})">h/dia</span>
          </div>
        </div>
        ${perf}
      </div>
      <div class="maq-card-body">
        <div class="maq-und-big" style="color:${cor};">${und.toLocaleString('pt-BR')}<span class="maq-und-label">unidades</span></div>
        ${capRow}
        ${prodMinRow}
        <div class="maq-chart-wrap"><canvas id="${id}"></canvas></div>
      </div>
      ${footer}`;
    grid.appendChild(el);
    requestAnimationFrame(() => buildMiniChart(id, r.horas_por_hora, cor));
  });
})();

const _hdTimers = {};
function recalcCap(mqCod, und, cadPerMin, qtdMaq) {
  const hd = parseFloat(document.getElementById('hd-' + mqCod)?.value) || 0;
  const cap = qtdMaq * cadPerMin * 60.0 * hd;
  const pct = cap > 0 ? Math.min(100, Math.round(und / cap * 1000) / 10) : 0;
  const c = pct >= 90 ? 'var(--teal)' : pct >= 60 ? 'var(--amber)' : 'var(--red)';
  const fill = document.getElementById('capf-' + mqCod);
  const pctEl = document.getElementById('capp-' + mqCod);
  if (fill)  { fill.style.width = pct + '%'; fill.style.background = c; }
  if (pctEl) { pctEl.textContent = pct + '%'; pctEl.style.color = c; }
  const capTxtEl = document.getElementById('captxt-' + mqCod);
  if (capTxtEl) {
    const capDia = Math.round(qtdMaq * cadPerMin * 60.0 * hd);
    capTxtEl.textContent = 'de ' + capDia.toLocaleString('pt-BR') + ' un/dia possíveis';
  }

  if (hd > 0) {
    clearTimeout(_hdTimers[mqCod]);
    _hdTimers[mqCod] = setTimeout(() => {
      fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'atualizar_horas_dia', maq_codigo: mqCod, horas_dia: hd })
      });
    }, 800);
  }
}

function buildMiniChart(id, data, cor) {
  const c = document.getElementById(id);
  if (!c) return;
  const horas = Object.keys(data).sort();
  if (!horas.length) { c.parentElement.style.display='none'; return; }
  new Chart(c, {
    type:'bar',
    data:{ labels:horas, datasets:[{ data:horas.map(h=>data[h]||0),
      backgroundColor:cor+'55', borderColor:cor, borderWidth:1.5, borderRadius:2 }] },
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:300},
      plugins:{ legend:{display:false},
        tooltip:{ backgroundColor:'#1a3a6a', titleColor:'#e8edf5', bodyColor:'#b0c4de',
          callbacks:{ label:ctx=>' '+Number(ctx.raw).toLocaleString('pt-BR')+' un' } } },
      scales:{
        x:{ grid:{display:false}, ticks:{color:'#7a90b0',font:{size:9},maxRotation:0} },
        y:{ grid:{color:'rgba(255,255,255,.04)'}, ticks:{color:'#7a90b0',font:{size:9},
              callback:v=>v>=1000?(v/1000).toFixed(0)+'k':v} }
      }
    }
  });
}

// ═════════════════════════════════════════════════════════════════════════════
//  Formulário unitário
// ═════════════════════════════════════════════════════════════════════════════
function novoRegistro() {
  const n = new Date();
  const hh = String(n.getHours()).padStart(2,'0');
  const nh = String((n.getHours()+1)%24).padStart(2,'0');
  document.getElementById('f_codigo').value = '0';
  document.getElementById('f_data').value = n.toISOString().slice(0,10);
  document.getElementById('f_h_ini').value = hh+':00';
  document.getElementById('f_h_fim').value = nh+':00';
  document.getElementById('f_maquina').value = '';
  document.getElementById('f_funcionario').value = '';
  document.getElementById('f_qtd').value = '';
  document.getElementById('f_pedido').value = '';
  document.getElementById('f_comentario').value = '';
  document.getElementById('formTitle').textContent = 'Novo Apontamento';
  document.getElementById('btnSalvarTxt').textContent = 'Salvar';
  document.getElementById('badgeEdit').classList.remove('visible');
  document.getElementById('btnCancelar').style.display = 'none';
  document.querySelectorAll('[id^="row-"]').forEach(r => r.removeAttribute('style'));
  document.getElementById('f_data').focus();
}

<?php if ($editando): ?>
(function(){
  const row = document.getElementById('row-<?= intval($editando['pd_codigo']) ?>');
  if (row) row.style.background = 'rgba(245,158,11,.06)';
  document.getElementById('formTitle').textContent = 'Editar Apontamento';
  document.getElementById('btnSalvarTxt').textContent = 'Salvar Alterações';
  document.getElementById('badgeEdit').classList.add('visible');
  document.getElementById('btnCancelar').style.display = 'inline-flex';
  document.getElementById('formPanel').scrollIntoView({behavior:'smooth',block:'start'});
})();
<?php endif; ?>

function validarForm() {
  const maq = document.getElementById('f_maquina').value;
  const qtd = document.getElementById('f_qtd').value;
  const ini = document.getElementById('f_h_ini').value;
  const fim = document.getElementById('f_h_fim').value;
  if (!maq) { document.getElementById('f_maquina').focus(); return false; }
  if (!qtd || parseFloat(qtd) < 0) { document.getElementById('f_qtd').focus(); return false; }
  if (fim <= ini) { alert('Horário de fim deve ser posterior ao início.'); return false; }
  return true;
}

// ═════════════════════════════════════════════════════════════════════════════
//  Lançamento em lote — turnos editáveis + divisão
// ═════════════════════════════════════════════════════════════════════════════

// Turnos fixos do papel de produção (pré-carregados mas editáveis)
const TURNOS = [
  { ini: '05:00', fim: '06:00' },
  { ini: '06:00', fim: '07:00' },
  { ini: '07:00', fim: '08:00' },
  { ini: '08:00', fim: '09:00' },
  { ini: '09:00', fim: '10:00' },
  { ini: '10:00', fim: '11:00' },
  { ini: '11:00', fim: '11:30' },
  { ini: '13:00', fim: '14:00' },
  { ini: '14:00', fim: '15:00' },
  { ini: '15:00', fim: '16:00' },
  { ini: '16:00', fim: '17:00' },
  { ini: '17:00', fim: '18:00' },
];

// Turno noturno: 22:00–03:00 (intervalos de 1h) + 04:00–06:42
const TURNOS_NOTURNO = [
  { ini: '22:00', fim: '23:00' },
  { ini: '23:00', fim: '00:00' },
  { ini: '00:00', fim: '01:00' },
  { ini: '01:00', fim: '02:00' },
  { ini: '02:00', fim: '03:00' },
  { ini: '04:00', fim: '05:00' },
  { ini: '05:00', fim: '06:00' },
  { ini: '06:00', fim: '06:42' },
];

let turnoAtivo = 'dia';

function setTurno(tipo) {
  turnoAtivo = tipo;
  document.getElementById('btn-turno-dia').className = 'btn-turno-toggle' + (tipo === 'dia' ? ' active' : '');
  document.getElementById('btn-turno-noc').className = 'btn-turno-toggle' + (tipo === 'noturno' ? ' active noturno' : '');
  construirTurnos();
  // Ajusta data padrão: noturno → ontem; diurno → ontem (mantém)
  if (tipo === 'noturno') {
    const ontem = new Date();
    ontem.setDate(ontem.getDate() - 1);
    document.getElementById('lote_data_padrao').value = ontem.toISOString().slice(0, 10);
  }
}

function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g,
    m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])
  );
}

// Converte "HH:MM" → minutos totais
function toMin(t) {
  const p = String(t||'').split(':');
  return parseInt(p[0]||0)*60 + parseInt(p[1]||0);
}

// Converte minutos → "HH:MM"
function toTime(m) {
  const h = Math.floor(m/60)%24;
  const min = m%60;
  return String(h).padStart(2,'0')+':'+String(min).padStart(2,'0');
}

let loteRowSeq = 0;

function criarLinha(ini, fim, isExtra) {
  loteRowSeq++;
  const id = loteRowSeq;
  const tr = document.createElement('tr');
  tr.dataset.rowId = id;
  if (isExtra) tr.classList.add('lote-row-extra');

  tr.innerHTML = `
    <td class="lote-row-num" id="rn-${id}"></td>
    <td><input class="lote-time" type="time" data-field="h_ini" value="${ini}"></td>
    <td><input class="lote-time" type="time" data-field="h_fim" value="${fim}"></td>
    <td style="text-align:center;">
      <input class="lote-qtd-inp lote-qtd" type="number" min="0" step="1"
        data-field="qtd" placeholder="0">
    </td>
    <td>
      <input class="lote-input" type="text" data-field="pedido"
        placeholder="Ex: 2807"
        style="height:34px;">
    </td>
    <td>
      <textarea class="lote-obs-inp" data-field="obs" rows="1"
        placeholder="Observação do turno…"></textarea>
    </td>
    <td style="text-align:center;">
      <div style="display:flex;gap:3px;justify-content:center;align-items:center;">
        <button class="btn-split" title="Dividir em dois pedidos"
          onclick="dividirLinha(${id})">÷</button>
        <button class="btn-lote-del" title="Remover linha"
          onclick="removerLinha(${id})">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </td>
  `;

  // Navegação por teclado: Enter na qtd → pedido, Tab no obs → próxima qtd
  const qtd    = tr.querySelector('.lote-qtd');
  const pedido = tr.querySelector('[data-field="pedido"]');
  const obs    = tr.querySelector('[data-field="obs"]');

  qtd.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); pedido.focus(); }
  });
  pedido.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); obs.focus(); }
  });
  obs.addEventListener('keydown', e => {
    if (e.key === 'Tab' && !e.shiftKey) {
      const next = tr.nextElementSibling;
      if (next) { e.preventDefault(); next.querySelector('.lote-qtd')?.focus(); }
    }
  });

  return tr;
}

function construirTurnos() {
  const tbody = document.getElementById('loteTbody');
  tbody.innerHTML = '';
  loteRowSeq = 0;
  const lista = turnoAtivo === 'noturno' ? TURNOS_NOTURNO : TURNOS;
  lista.forEach(t => tbody.appendChild(criarLinha(t.ini, t.fim, false)));
  renumerar();
}

function renumerar() {
  document.getElementById('loteTbody').querySelectorAll('tr').forEach((tr, i) => {
    const n = tr.querySelector('.lote-row-num');
    if (n) n.textContent = i + 1;
  });
  const total = document.getElementById('loteTbody').querySelectorAll('tr').length;
  document.getElementById('lote-total-rows').textContent = total;
  atualizarContador();
}

function atualizarContador() {
  const n = Array.from(
    document.getElementById('loteTbody').querySelectorAll('[data-field="qtd"]')
  ).filter(i => i.value && parseFloat(i.value) > 0).length;
  document.getElementById('lote-count').textContent = n;
}

// Divide uma linha em dois: a primeira vai até o meio, a segunda da metade ao fim
function dividirLinha(rowId) {
  const tbody = document.getElementById('loteTbody');
  const tr = tbody.querySelector(`[data-row-id="${rowId}"]`);
  if (!tr) return;

  const ini = tr.querySelector('[data-field="h_ini"]').value;
  const fim = tr.querySelector('[data-field="h_fim"]').value;
  const iniMin = toMin(ini);
  const fimMin = toMin(fim);

  if (fimMin <= iniMin) {
    alert('Não é possível dividir: horário inválido.');
    return;
  }

  const mid = toTime(Math.round((iniMin + fimMin) / 2));

  // Atualiza linha original para ir até o meio
  tr.querySelector('[data-field="h_fim"]').value = mid;

  // Nova linha do meio ao fim
  const novaLinha = criarLinha(mid, fim, true);
  tr.after(novaLinha);
  renumerar();

  // Foca na quantidade da nova linha
  novaLinha.querySelector('.lote-qtd')?.focus();
}

function removerLinha(rowId) {
  const tbody = document.getElementById('loteTbody');
  const tr = tbody.querySelector(`[data-row-id="${rowId}"]`);
  if (!tr) return;
  // Não remove se só sobrar 1 linha
  if (tbody.querySelectorAll('tr').length <= 1) return;
  tr.remove();
  renumerar();
}

function abrirLote() {
  // Data padrão = ontem
  const ontem = new Date();
  ontem.setDate(ontem.getDate() - 1);
  document.getElementById('lote_data_padrao').value = ontem.toISOString().slice(0, 10);

  // Reset turno para diurno
  turnoAtivo = 'dia';
  document.getElementById('btn-turno-dia').className = 'btn-turno-toggle active';
  document.getElementById('btn-turno-noc').className = 'btn-turno-toggle';

  // Reconstrói sempre (limpa estado anterior)
  construirTurnos();

  document.getElementById('lote-notice').className = 'lote-notice';
  document.getElementById('modalLote').classList.add('open');

  setTimeout(() => {
    const maq = document.getElementById('lote_maq_padrao');
    if (!maq.value) maq.focus();
    else document.getElementById('lote_func_padrao').focus();
  }, 120);
}

function fecharLote() {
  document.getElementById('modalLote').classList.remove('open');
}

function limparQuantidades() {
  document.getElementById('loteTbody').querySelectorAll('[data-field="qtd"],[data-field="obs"],[data-field="pedido"]').forEach(i => {
    i.value = '';
    i.classList.remove('err');
  });
  document.getElementById('lote-notice').className = 'lote-notice';
  atualizarContador();
  document.getElementById('loteTbody').querySelector('.lote-qtd')?.focus();
}

async function salvarLote() {
  const notice = document.getElementById('lote-notice');
  notice.className = 'lote-notice';

  const data     = document.getElementById('lote_data_padrao').value;
  const maq      = document.getElementById('lote_maq_padrao').value;
  const func     = document.getElementById('lote_func_padrao').value.trim();

  // Valida cabeçalho
  document.getElementById('lote_data_padrao').classList.remove('err');
  document.getElementById('lote_maq_padrao').classList.remove('err');
  document.getElementById('lote_func_padrao').classList.remove('err');
  let erroH = false;
  if (!data) { document.getElementById('lote_data_padrao').classList.add('err'); erroH=true; }
  if (!maq)  { document.getElementById('lote_maq_padrao').classList.add('err');  erroH=true; }
  if (!func) { document.getElementById('lote_func_padrao').classList.add('err'); erroH=true; }
  if (erroH) {
    notice.className = 'lote-notice show err';
    notice.textContent = 'Preencha Data, Máquina e Funcionário antes de salvar.';
    return;
  }

  // Coleta linhas com quantidade > 0
  const linhas = [];
  let temErroLinha = false;

  document.getElementById('loteTbody').querySelectorAll('tr').forEach(tr => {
    const qtdEl = tr.querySelector('[data-field="qtd"]');
    const iniEl = tr.querySelector('[data-field="h_ini"]');
    const fimEl = tr.querySelector('[data-field="h_fim"]');

    [qtdEl, iniEl, fimEl].forEach(el => el?.classList.remove('err'));

    const qtd = qtdEl?.value ?? '';
    if (!qtd || parseFloat(qtd) <= 0) return; // ignora linha vazia

    const ini = iniEl?.value ?? '';
    const fim = fimEl?.value ?? '';

    let err = false;
    if (!ini) { iniEl?.classList.add('err'); err=true; }
    if (!fim) { fimEl?.classList.add('err'); err=true; }
    // Para turnos noturnos que cruzam meia-noite, fim < ini em string é válido
    if (ini && fim && fim <= ini && turnoAtivo !== 'noturno') { fimEl?.classList.add('err'); err=true; }
    if (err) { temErroLinha=true; return; }

    linhas.push({
      data,
      maq,
      h_ini: ini,
      h_fim: fim,
      qtd,
      func,
      pedido: tr.querySelector('[data-field="pedido"]')?.value.trim() || '',
      obs: tr.querySelector('[data-field="obs"]')?.value.trim() || '',
    });
  });

  if (!linhas.length && !temErroLinha) {
    notice.className = 'lote-notice show err';
    notice.textContent = 'Preencha pelo menos uma quantidade antes de salvar.';
    return;
  }
  if (temErroLinha) {
    notice.className = 'lote-notice show err';
    notice.textContent = 'Corrija os horários marcados em vermelho.';
    return;
  }

  const btn = document.getElementById('btnSalvarLote');
  const old = btn.innerHTML;
  btn.disabled = true;
  btn.textContent = 'Salvando…';

  try {
    const res  = await fetch(window.location.href, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body:    JSON.stringify({ linhas }),
    });
    const json = await res.json();
    if (!res.ok || !json.success) throw new Error(json.msg || 'Falha ao salvar.');
    notice.className = 'lote-notice show ok';
    notice.textContent = json.msg;
    setTimeout(() => { fecharLote(); location.reload(); }, 1500);
  } catch (err) {
    notice.className = 'lote-notice show err';
    notice.textContent = err.message || 'Erro ao salvar.';
  } finally {
    btn.disabled = false;
    btn.innerHTML = old;
  }
}

// Contador em tempo real
document.getElementById('loteTbody').addEventListener('input', atualizarContador);

// ═════════════════════════════════════════════════════════════════════════════
//  Modais simples
// ═════════════════════════════════════════════════════════════════════════════
function confirmarExclusao(cod, data, maq) {
  document.getElementById('excluir_codigo').value = cod;
  document.getElementById('modalExclusaoTexto').innerHTML =
    'Excluir o apontamento de <strong>'+data+'</strong> na máquina <strong>'+maq+'</strong>?';
  document.getElementById('modalExclusao').classList.add('open');
}
function abrirModalCalculo() { document.getElementById('modalCalculo').classList.add('open'); }
function fecharModal(id)     { document.getElementById(id).classList.remove('open'); }

['modalExclusao','modalCalculo'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target === e.currentTarget) fecharModal(id);
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    fecharModal('modalExclusao');
    fecharModal('modalCalculo');
    fecharLote();
  }
});

// Auto-oculta alert de sucesso
const alertOk = document.getElementById('alertOk');
if (alertOk) setTimeout(() => {
  alertOk.style.transition = 'opacity .5s';
  alertOk.style.opacity = '0';
  setTimeout(() => alertOk.style.display='none', 500);
}, 4000);
</script>
</body>
</html>