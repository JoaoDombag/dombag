<?php

/**
 * DOMBAG — PCP Planning Engine v2
 * Conexões centralizadas via config/config.php
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';

function pcpGetPDO(): PDO
{
    return dbPDO();
}

function pcpGetPG()
{
    return dbPG();
}

// ── Máquinas reais do MySQL (uma entrada por máquina física) ─────────────────
function pcpGetMaquinas(): array
{
    try {
        $db = pcpGetPDO();
        pcpEnsureMaqGrupo($db);
        $rows = $db->query(
            "SELECT
                maq_descricao                                           AS nome,
                COALESCE(maq_grupo, maq_descricao)                     AS grupo,
                COALESCE(maq_qtde, 1) * COALESCE(maq_producao_min, 0) AS velocidade,
                COALESCE(maq_horas_dia, 8)                             AS horas_dia
             FROM maquinas
             WHERE maq_grupo IS NOT NULL AND maq_grupo <> ''
             ORDER BY maq_grupo, maq_descricao"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    $maq = [];
    foreach ($rows as $r) {
        $vel = (float) $r['velocidade'];
        $hrs = (float) $r['horas_dia'];
        $maq[$r['nome']] = [
            'nome'           => $r['nome'],
            'grupo'          => $r['grupo'],
            'qtd'            => 1.0,
            'velocidade'     => $vel,
            'horas_dia'      => $hrs,
            'capacidade_dia' => $vel * 60.0 * $hrs,
        ];
    }
    return $maq;
}

// ── Agrupa máquinas individuais em grupos lógicos (para cálculo de horas) ────
function pcpBuildGrupos(array $maquinas): array
{
    $grupos = [];
    foreach ($maquinas as $m) {
        $g = $m['grupo'];
        if (!isset($grupos[$g])) {
            $grupos[$g] = ['nome' => $g, 'qtd' => 1.0, 'velocidade' => 0.0, 'horas_dia' => 0.0, 'capacidade_dia' => 0.0];
        }
        $grupos[$g]['velocidade'] += $m['velocidade'];
        $grupos[$g]['horas_dia']   = max($grupos[$g]['horas_dia'], $m['horas_dia']);
    }
    foreach ($grupos as &$g) {
        $g['capacidade_dia'] = $g['velocidade'] * 60.0 * $g['horas_dia'];
    }
    return $grupos;
}

// ── Pedidos do ERP (mesma query de pedidos_importados.php) ────────────────────
function pcpGetPedidosERP(string $dataIni, string $dataFim): array
{
    $pg = pcpGetPG();
    if (!$pg) {
        return [];
    }

    $sql = "
        SELECT
            VV.PEDIDO::TEXT                           AS num,
            TO_CHAR(VV.DATAFATURA,'DD/MM/YYYY')       AS entrega,
            TO_CHAR(VV.DATAFATURA,'YYYY/MM')          AS mes_fat,
            COALESCE(VV.VENDEDOR_VENDA::VARCHAR,'')   AS rep,
            COALESCE(VV.NOME_FANTASIA,'')             AS fantasia,
            COALESCE(VV.CLIENTE,'')                   AS cliente,
            COALESCE(VV.PRODUTO::VARCHAR,'')          AS produto,
            COALESCE(VV.GRUPO_CLIENTES::VARCHAR,'')   AS grupo,
            COALESCE(VV.QTD,0)                        AS qtd,
            COALESCE(VV.VALOR_UNIT::FLOAT,0)          AS vlr_unit,
            COALESCE(VV.TOTAL_PRODUTO,0)              AS total_ped
        FROM VW_VENDAS VV
        INNER JOIN PRODUTO P ON VV.COD_PRODUTO = P.PRO_CODIGO
        WHERE VV.COD_GRUPO IN (6)
          AND VV.OPERACAO_PEDIDO IN ('V')
          AND VV.COD_ESTADO IN ('9')
          AND VV.EMP_CODIGO IN (1)
          AND VV.DATAFATURA >= " . pg_escape_literal($pg, $dataIni) . '
          AND VV.DATAFATURA <= ' . pg_escape_literal($pg, $dataFim) . '
        ORDER BY VV.DATAFATURA, VV.PEDIDO, VV.PRODUTO
    ';

    $res = pg_query($pg, $sql);
    if (!$res) {
        pg_close($pg);
        return [];
    }

    $pedidos = [];
    while ($r = pg_fetch_assoc($res)) {
        $pedidos[] = [
            'num' => $r['num'],
            'entrega' => $r['entrega'],
            'mes_fat' => $r['mes_fat'],
            'rep' => $r['rep'],
            'fantasia' => $r['fantasia'],
            'cliente' => $r['cliente'],
            'produto' => $r['produto'],
            'grupo' => $r['grupo'],
            'qtd' => (float) $r['qtd'],
            'vlr_unit' => (float) $r['vlr_unit'],
            'total_ped' => (float) $r['total_ped'],
            'tipo' => '',
            'impressao' => '',
            'valvulado' => '',
            'maq_impressao' => '',
            'fluxo' => '',
            'pendencia' => '',
        ];
    }
    pg_close($pg);
    return $pedidos;
}

// ── Garante que maq_grupo existe em maquinas e popula mapeamento lógico ───────
function pcpEnsureMaqGrupo(PDO $db): void
{
    $exists = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $exists->execute(['maquinas', 'maq_grupo']);
    if (!(int) $exists->fetchColumn()) {
        $db->exec('ALTER TABLE maquinas ADD COLUMN maq_grupo VARCHAR(80) NULL DEFAULT NULL');
    }
    // Preenche apenas linhas ainda sem grupo
    $db->exec("
        UPDATE maquinas SET maq_grupo = CASE
            WHEN maq_descricao LIKE 'Corte Bag%'              THEN 'Corte Bag'
            WHEN maq_descricao LIKE 'Costura Bag%'            THEN 'Costura Bag'
            WHEN maq_descricao LIKE 'Analise Bag%'
              OR maq_descricao LIKE 'Análise Bag%'            THEN 'Analise Bag'
            WHEN maq_descricao LIKE 'Carimbadeira Bag%'
              OR maq_descricao LIKE 'Impressao Bag%'          THEN 'Impressao Bag'
            WHEN maq_descricao LIKE 'Flexo%'                  THEN 'Flexo'
            WHEN maq_descricao LIKE 'Corte+Costura Sacaria%'
              OR maq_descricao LIKE 'Corte Sacaria%'          THEN 'Corte+Costura Sacaria'
            WHEN maq_descricao LIKE 'Carimbadeira Sacaria%'   THEN 'Carimbadeira Sacaria'
            WHEN maq_descricao LIKE 'Valvuladeira%'           THEN 'Valvuladeira'
            ELSE maq_descricao
        END
        WHERE maq_grupo IS NULL
    ");
}

// ── Garante que iv_prioridade existe em itens_vendas ─────────────────────────
function pcpEnsurePrioridade(PDO $db): void
{
    $exists = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $exists->execute(['itens_vendas', 'iv_prioridade']);
    if (!(int) $exists->fetchColumn()) {
        $db->exec('ALTER TABLE itens_vendas ADD COLUMN iv_prioridade INT NULL');
    }
}

// ── Pedidos do MySQL local (importados do ERP, status pendente/produção) ─────
function pcpGetPedidosMySQL(): array
{
    try {
        $db = pcpGetPDO();
        pcpEnsurePrioridade($db);
        $stmt = $db->query("
            SELECT
                v.ven_codigo_yzidro                                      AS num,
                DATE_FORMAT(v.ven_entrega, '%d/%m/%Y')                   AS entrega,
                ''                                                        AS mes_fat,
                COALESCE(v.ven_representante, '')                        AS rep,
                COALESCE(NULLIF(v.ven_fantasia,''), v.ven_cliente, '')   AS fantasia,
                COALESCE(v.ven_cliente, '')                              AS cliente,
                p.pro_descricao                                          AS produto,
                p.pro_categoria                                          AS grupo,
                iv.iv_qtde                                               AS qtd,
                iv.iv_vlr_unit                                           AS vlr_unit,
                iv.iv_total                                              AS total_ped,
                COALESCE(iv.iv_prioridade, 0)                           AS iv_prioridade,
                COALESCE(iv.iv_obs, '')                                  AS obs
            FROM itens_vendas iv
            JOIN vendas   v ON v.ven_codigo = iv.ven_codigo
            JOIN produtos p ON p.pro_codigo = iv.pro_codigo
            WHERE iv.iv_status IN ('Pendente de produção','Produção')
            ORDER BY v.ven_entrega, v.ven_codigo_yzidro, p.pro_descricao
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    $pedidos = [];
    foreach ($rows as $r) {
        $pedidos[] = [
            'num' => $r['num'],
            'entrega' => $r['entrega'] ?? '',
            'mes_fat' => $r['mes_fat'],
            'rep' => $r['rep'],
            'fantasia' => $r['fantasia'],
            'cliente' => $r['cliente'],
            'produto' => $r['produto'],
            'grupo' => $r['grupo'],
            'qtd' => (float) $r['qtd'],
            'vlr_unit' => (float) $r['vlr_unit'],
            'total_ped' => (float) $r['total_ped'],
            'iv_prioridade' => (int) $r['iv_prioridade'],
            'obs' => $r['obs'],
            'tipo' => '',
            'impressao' => '',
            'valvulado' => '',
            'maq_impressao' => '',
            'frente_verso' => false,
            'fluxo' => '',
            'pendencia' => '',
        ];
    }
    return $pedidos;
}

// ── Infere tipo/impressão/valvulado/máq de impressão pelo nome do produto ─────
function pcpInfereProduto(array &$p): void
{
    $nome = strtoupper($p['produto']);
    $grupo = strtoupper($p['grupo']);

    // Tipo
    if (str_contains($grupo, 'SACARIA') || str_contains($nome, 'SACARIA')) {
        $tipo = 'SACARIA';
    } elseif (str_contains($grupo, 'BAG') || str_contains($nome, 'BIG BAG')) {
        $tipo = 'BAG';
    } else {
        $tipo = '';
    }

    // Impressão
    if (preg_match('/S[\/E]?M IMPRESSA/i', $nome) || str_contains($nome, 'S/ IMPRESSAO')) {
        $impressao = 'NAO';
    } elseif (preg_match('/C[\/O]?M IMPRESSA/i', $nome) || str_contains($nome, 'C/ IMPRESSAO')) {
        $impressao = 'SIM';
    } else {
        $impressao = '';
    }

    // Valvulado
    $valvulado = str_contains($nome, 'VALVULAD') ? 'SIM' : 'NAO';

    // Altura do saco (ex: "90X90X150" → 150, ou "90X150" → 150)
    $altura = 0;
    if (preg_match('/\d+X\d+X(\d+)/i', $nome, $m)) {
        $altura = (int) $m[1];
    } elseif (preg_match('/\d+X(\d+)/i', $nome, $m)) {
        $altura = (int) $m[1];
    }

    // Máquina de impressão
    // Sacaria com impressão:
    //   altura <  70          → Carimbadeira Sacaria
    //   altura >= 70 e <= 110 → Flexo (máx 2 pedidos/dia — setup longo)
    //   altura > 110          → Carimbadeira Sacaria
    //   altura não detectada  → Carimbadeira Sacaria (padrão seguro)
    if ($impressao === 'SIM') {
        if ($tipo === 'BAG') {
            $maq_impressao = 'Impressao Bag';
        } elseif ($tipo === 'SACARIA') {
            $maq_impressao = ($altura >= 70 && $altura <= 110)
                ? 'Flexo' : 'Carimbadeira Sacaria';
        } else {
            $maq_impressao = '';
        }
    } else {
        $maq_impressao = '';
    }

    $obs = strtoupper($p['obs'] ?? '');
    $p['frente_verso'] = str_contains($obs, 'FRENTE') || str_contains($obs, 'F/V') || str_contains($obs, 'F.V');

    $p['tipo'] = $tipo;
    $p['impressao'] = $impressao;
    $p['valvulado'] = $valvulado;
    $p['maq_impressao'] = $maq_impressao;
}

// ── Fluxo de máquinas ─────────────────────────────────────────────────────────
function pcpGetFluxo(array $p): array
{
    if ($p['tipo'] === 'BAG') {
        $f = ['Corte Bag'];
        if ($p['impressao'] === 'SIM' && $p['maq_impressao']) {
            $f[] = $p['maq_impressao'];
        }
        $f[] = 'Costura Bag';
        $f[] = 'Analise Bag';
        return $f;
    }
    if ($p['tipo'] === 'SACARIA') {
        $f = [];
        if ($p['impressao'] === 'SIM' && $p['maq_impressao']) {
            $f[] = $p['maq_impressao'];
        }
        $f[] = 'Corte+Costura Sacaria';
        if ($p['valvulado'] === 'SIM') {
            $f[] = 'Valvuladeira';
        }
        return $f;
    }
    return [];
}

// ── Calcula horas para uma qtd numa máquina ───────────────────────────────────
function pcpHoras(float $qtd, string $maq, array $maquinas): float
{
    $m = $maquinas[$maq] ?? null;
    if (!$m || $m['velocidade'] <= 0) {
        return 0.0;
    }
    return round($qtd / ($m['velocidade'] * 60.0), 4);
}

function pcpNextDay(DateTime $dt): DateTime
{
    $d = clone $dt;
    $d->modify('+1 day');
    return $d;
}

// ── Engine principal ──────────────────────────────────────────────────────────
function pcpCalcPlanejamento(array $pedidos, array $maquinas, string $dataBase = ''): array
{
    if (!$dataBase) {
        $dataBase = date('Y-m-d');
    }
    $hoje = new DateTime($dataBase);

    // Grupos lógicos (velocidade somada) — usados para estimar horas
    $grupos = pcpBuildGrupos($maquinas);

    // Mapeamento grupo → lista de máquinas reais
    $grupoMachines = []; // ['Corte Bag' => ['Corte Bag 1', 'Corte Bag 2'], ...]
    foreach ($maquinas as $nome => $m) {
        $grupoMachines[$m['grupo']][] = $nome;
    }

    // 1. Enriquece pedidos (usa grupos para estimar horas)
    foreach ($pedidos as &$p) {
        pcpInfereProduto($p);
        $fluxoArr = pcpGetFluxo($p);
        $hrsMaq = [];
        $hrsTot = 0.0;
        $pendencia = '';

        if (empty($fluxoArr)) {
            $pendencia = 'Preencher cadastro/capacidade';
        } else {
            foreach ($fluxoArr as $mq) {
                $effQtd = (float) $p['qtd'];
                if (!empty($p['frente_verso']) && $mq === ($p['maq_impressao'] ?? '')) {
                    $effQtd *= 2.0;
                }
                $h = pcpHoras($effQtd, $mq, $grupos);
                $hrsMaq[$mq] = $h;
                $hrsTot += $h;
                if (!isset($grupos[$mq]) || $grupos[$mq]['velocidade'] <= 0) {
                    $pendencia = 'Preencher cadastro/capacidade';
                }
            }
        }

        // Gargalo = máquina com mais horas
        $gargalo = '';
        $maxH = 0;
        foreach ($hrsMaq as $mq => $h) {
            if ($h > $maxH) {
                $maxH = $h;
                $gargalo = $mq;
            }
        }
        if (!$gargalo && !empty($fluxoArr)) {
            $gargalo = $fluxoArr[0];
        }

        $entDt = DateTime::createFromFormat('d/m/Y', $p['entrega']);
        $p += [
            'fluxo_arr' => $fluxoArr,
            'fluxo' => implode(' > ', $fluxoArr),
            'hrs_por_maq' => $hrsMaq,
            'hrs_totais' => round($hrsTot, 4),
            'pendencia' => $pendencia,
            'gargalo' => $gargalo,
            'entrega_dt' => $entDt,
            'dias_entrega' => $entDt ? max(0, (int) $hoje->diff($entDt)->format('%r%a')) : 0,
        ];
    }
    unset($p);

    // 2. Ordena: prioridade manual (> 0) → data entrega → num pedido
    usort($pedidos, function (array $a, array $b): int {
        $pa = ($a['iv_prioridade'] ?? 0) > 0 ? ($a['iv_prioridade'] ?? 0) : PHP_INT_MAX;
        $pb = ($b['iv_prioridade'] ?? 0) > 0 ? ($b['iv_prioridade'] ?? 0) : PHP_INT_MAX;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $da = $a['entrega_dt'] ?: new DateTime('2099-01-01');
        $db = $b['entrega_dt'] ?: new DateTime('2099-01-01');
        return $da <=> $db ?: strcmp($a['num'], $b['num']);
    });
    foreach ($pedidos as $i => &$p) {
        $p['prioridade'] = $i + 1;
    }
    unset($p);

    // 3. Agendamento por máquina real (escolhe a com maior disponibilidade no grupo)
    $maqSt = []; // [maqReal => [dt, usado, seq]]
    foreach (array_keys($maquinas) as $nomeMaq) {
        $maqSt[$nomeMaq] = ['dt' => clone $hoje, 'usado' => 0.0, 'seq' => 1];
    }

    // Restrição Flexo: máx 2 pedidos por dia (setup muito longo)
    $flexoPedidosDia = []; // ['maqReal|Y-m-d' => count]

    foreach ($pedidos as &$p) {
        $p['fila'] = [];
        $p['inicio_prev'] = '';
        $p['termino_prev'] = '';

        foreach ($p['fluxo_arr'] as $mq) {
            $hrs = $p['hrs_por_maq'][$mq] ?? 0.0;
            if ($hrs <= 0.0) {
                continue;
            }

            // Escolhe a máquina real com dt mais cedo dentro do grupo
            $candidates = $grupoMachines[$mq] ?? [];
            if (empty($candidates)) {
                continue;
            }
            $bestMaq = null;
            $bestDt  = null;
            foreach ($candidates as $nomeMaq) {
                $cdt = $maqSt[$nomeMaq]['dt'];
                if ($bestDt === null || $cdt < $bestDt) {
                    $bestDt  = $cdt;
                    $bestMaq = $nomeMaq;
                }
            }
            if (!$bestMaq) {
                continue;
            }

            $m   = $maquinas[$bestMaq];
            $cap = $m['horas_dia'] * $m['qtd'];
            if ($cap <= 0.0) {
                continue;
            }

            // Flexo: avança de dia até encontrar um dia com menos de 2 pedidos
            if ($mq === 'Flexo') {
                $dtKey = $bestMaq . '|' . $maqSt[$bestMaq]['dt']->format('Y-m-d');
                while (($flexoPedidosDia[$dtKey] ?? 0) >= 2) {
                    $maqSt[$bestMaq]['dt']    = pcpNextDay($maqSt[$bestMaq]['dt']);
                    $maqSt[$bestMaq]['usado'] = 0.0;
                    $dtKey = $bestMaq . '|' . $maqSt[$bestMaq]['dt']->format('Y-m-d');
                }
                $flexoPedidosDia[$dtKey] = ($flexoPedidosDia[$dtKey] ?? 0) + 1;
            }

            $st = &$maqSt[$bestMaq];
            $dtIni = clone $st['dt'];
            $hLeft = $hrs;
            $disp = max(0.0, $cap - $st['usado']);

            if ($hLeft <= $disp + 0.0001) {
                $dtFim = clone $st['dt'];
                $st['usado'] += $hLeft;
                if ($st['usado'] >= $cap - 0.0001) {
                    $st['usado'] = 0.0;
                    $st['dt'] = pcpNextDay($st['dt']);
                }
            } else {
                $hLeft -= $disp;
                $st['usado'] = 0.0;
                $st['dt'] = pcpNextDay($st['dt']);
                while ($hLeft > $cap - 0.0001) {
                    $hLeft -= $cap;
                    $st['dt'] = pcpNextDay($st['dt']);
                }
                $dtFim = clone $st['dt'];
                $st['usado'] = $hLeft;
                if ($st['usado'] >= $cap - 0.0001) {
                    $st['usado'] = 0.0;
                    $st['dt'] = pcpNextDay($st['dt']);
                }
            }
            unset($st);

            $entDt = $p['entrega_dt'];
            $atrasado = $entDt && $entDt < $hoje;
            $risco = $entDt && $dtFim > $entDt;
            $stat = ($atrasado || $risco) ? 'RISCO' : 'OK';

            $item = [
                'maquina' => $bestMaq,  // nome real da máquina
                'grupo'   => $mq,       // grupo lógico (para filtros)
                'seq' => $maqSt[$bestMaq]['seq']++,
                'pedido' => $p['num'],
                'produto' => $p['produto'],
                'cliente' => $p['cliente'],
                'entrega' => $p['entrega'],
                'qtd' => (int) $p['qtd'],
                'horas' => round($hrs, 2),
                'inicio' => $dtIni->format('d/m/Y'),
                'termino' => $dtFim->format('d/m/Y'),
                'status' => $stat,
            ];
            $p['fila'][] = $item;
            if (!$p['inicio_prev']) {
                $p['inicio_prev'] = $dtIni->format('d/m/Y');
            }
            $p['termino_prev'] = $dtFim->format('d/m/Y');
        }

        // Status prazo
        if ($p['pendencia']) {
            $p['status_prazo'] = 'PENDENTE';
        } elseif ($p['entrega_dt'] && $p['entrega_dt'] < $hoje) {
            $p['status_prazo'] = 'ATRASADO';
        } elseif (!empty($p['fila'])) {
            $hasR = false;
            foreach ($p['fila'] as $fi) {
                if ($fi['status'] === 'RISCO') {
                    $hasR = true;
                    break;
                }
            }
            $p['status_prazo'] = $hasR ? 'RISCO' : 'OK';
        } else {
            $p['status_prazo'] = 'PENDENTE';
        }
    }
    unset($p);

    // 4. Fila global
    $filaGlobal = [];
    foreach ($pedidos as $p) {
        foreach ($p['fila'] as $fi) {
            $filaGlobal[] = $fi;
        }
    }

    // 5. Programação diária granular (usa máquinas reais da fila)
    $prog = [];
    $dayUsed = [];
    foreach ($pedidos as $p) {
        foreach ($p['fila'] as $filaItem) {
            $maqReal  = $filaItem['maquina'];
            $grupoNome = $filaItem['grupo'] ?? '';
            $hrs = $p['hrs_por_maq'][$grupoNome] ?? 0.0;
            if ($hrs <= 0.0) {
                continue;
            }
            $m = $maquinas[$maqReal] ?? null;
            if (!$m) {
                continue;
            }
            $cap = $m['horas_dia'] * $m['qtd'];
            if ($cap <= 0.0) {
                continue;
            }

            $dtCur = DateTime::createFromFormat('d/m/Y', $filaItem['inicio']);
            if (!$dtCur) {
                continue;
            }
            $entDt = $p['entrega_dt'];
            $hLeft = $hrs;

            while ($hLeft > 0.0001) {
                $key = $maqReal . '|' . $dtCur->format('Y-m-d');
                $usado = $dayUsed[$key] ?? 0.0;
                $avail = $cap - $usado;
                if ($avail <= 0.0001) {
                    $dtCur = pcpNextDay($dtCur);
                    continue;
                }

                $hDia = min($hLeft, $avail);
                $dayUsed[$key] = $usado + $hDia;
                $hLeft = round($hLeft - $hDia, 4);

                $obs = !$entDt
                    ? 'Sem data'
                    : ($dtCur > $entDt ? 'Após prazo' : 'Prazo OK');

                $prog[] = [
                    'data'    => $dtCur->format('d/m/Y'),
                    'maquina' => $maqReal,
                    'pedido'  => $p['num'],
                    'produto' => $p['produto'],
                    'cliente' => $p['cliente'],
                    'entrega' => $p['entrega'],
                    'qtd'     => (int) $p['qtd'],
                    'horas'   => round($hDia, 2),
                    'obs'     => $obs,
                ];
                if ($hLeft > 0.0001) {
                    $dtCur = pcpNextDay($dtCur);
                }
            }
        }
    }

    // 6. Carga por máquina real (soma horas do agendamento na fila)
    $horasPorMaqReal = [];
    foreach ($pedidos as $p) {
        foreach ($p['fila'] as $fi) {
            $horasPorMaqReal[$fi['maquina']] = ($horasPorMaqReal[$fi['maquina']] ?? 0.0) + $fi['horas'];
        }
    }
    $carga = [];
    foreach ($maquinas as $nome => $m) {
        $totalH = $horasPorMaqReal[$nome] ?? 0.0;
        $capDia = $m['horas_dia'] * $m['qtd'];
        $dias = $capDia > 0 ? round($totalH / $capDia, 2) : 0;
        if ($capDia <= 0) {
            $obs = 'Preencher capacidade';
        } elseif ($dias > 20) {
            $obs = 'Gargalo forte';
        } elseif ($dias > 8) {
            $obs = 'Atencao';
        } else {
            $obs = 'OK';
        }
        $carga[] = array_merge($m, [
            'carga_total'  => round($totalH, 2),
            'dias_demanda' => $dias,
            'obs'          => $obs,
        ]);
    }

    return [
        'pedidos' => $pedidos,
        'fila' => $filaGlobal,
        'prog' => $prog,
        'maquinas' => $carga,
    ];
}

// ── Estima prazo de produção de um pedido (dias corridos brutos) ──────────────
// $itens: array de ['qtd' => float, 'tipo' => string, 'impressao' => 1|0|'SIM'|'NAO',
//                   'valvulado' => 1|0|'SIM'|'NAO', 'maq_impressao' => string]
function pcpEstimaPrazoPedido(array $itens): ?int
{
    $maquinas = pcpGetMaquinas();
    if (empty($maquinas)) {
        return null;
    }
    // Usa grupos (velocidade somada) para estimar horas por tipo de máquina
    $grupos = pcpBuildGrupos($maquinas);

    $horasPorMaq = [];
    foreach ($itens as $item) {
        $imp  = ($item['impressao']  === 'SIM' || $item['impressao']  == 1) ? 'SIM' : 'NAO';
        $valv = ($item['valvulado']  === 'SIM' || $item['valvulado']  == 1) ? 'SIM' : 'NAO';
        $p = [
            'tipo'          => strtoupper((string) ($item['tipo'] ?? '')),
            'impressao'     => $imp,
            'valvulado'     => $valv,
            'maq_impressao' => (string) ($item['maq_impressao'] ?? ''),
        ];
        $fluxo = pcpGetFluxo($p);
        foreach ($fluxo as $mq) {
            $h = pcpHoras((float) ($item['qtd'] ?? 0), $mq, $grupos);
            $horasPorMaq[$mq] = ($horasPorMaq[$mq] ?? 0.0) + $h;
        }
    }

    if (empty($horasPorMaq)) {
        return null;
    }

    // Gargalo: grupo que ocupa mais dias (usa capacidade do grupo)
    $maxDias = 0.0;
    foreach ($horasPorMaq as $mq => $horas) {
        $m = $grupos[$mq] ?? null;
        if (!$m || $m['horas_dia'] <= 0) {
            continue;
        }
        $dias = $horas / $m['horas_dia'];
        if ($dias > $maxDias) {
            $maxDias = $dias;
        }
    }

    return $maxDias > 0 ? max(1, (int) ceil($maxDias)) : null;
}
