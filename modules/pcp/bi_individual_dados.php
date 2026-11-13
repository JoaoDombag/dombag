<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Dados da produção individual (por funcionário) do dia
//  Usado pelo BI Individual e pelo widget "Produção Individual" do BI da
//  Produção. Fonte: PostgreSQL ERP Yzidro (somente leitura) + meta local.
// ══════════════════════════════════════════════════════════════

require_once dirname(__DIR__) . '/pcp/producao_tipo.php';

// Meta diária TOTAL da equipe (bags prontos no dia, na Análise)
const BII_META_CHAVE = 'meta_diaria_total';

// ── Meta diária total (equipe inteira) ─────────────────────────────────────
function biiFetchMeta(PDO $pdo): float
{
    try {
        $v = $pdo->query('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = ' . $pdo->quote(BII_META_CHAVE))->fetchColumn();
        return $v !== false ? (float) $v : 0.0;
    } catch (Throwable) {
        return 0.0;
    }
}

function biiSaveMeta(PDO $pdo, float $valor): void
{
    $stmt = $pdo->prepare('
        INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)
    ');
    $stmt->execute([':k' => BII_META_CHAVE, ':v' => (string) $valor]);
}

// ── Apontamentos do dia, por funcionário x centro de trabalho x OP x etapa ───
//  EM_PRODUCAO = existe apontamento em aberto (sem data/hora fim).
//  Cada etapa conta na sua unidade (bags ou sacarias); PRONTO marca a etapa
//  final do tipo (producao_tipo.php), a única que entra no total da equipe.
function biiFetchApontamentos($pg, string $dia): array
{
    $diaLit = pg_escape_literal($pg, $dia);
    $sql = "
        SELECT IAA.FU_CODIGO
              ,F.FU_NOME
              ,IAA.CT_CODIGO
              ,CT.CT_DESCRICAO
              ,IAA.PROD_CODIGO
              ,IAA.PRO_CODIGO
              ,P.PRO_DESCRICAO
              ," . prodTipoGrupoSql('P') . " AS TIPO
              ,IAA.ATP_CODIGO
              ,TRIM(ATP.ATP_DESCRICAO) AS ETAPA
              ,BOOL_OR(" . prodProntoSql('IAA') . ") AS PRONTO
              ,COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '') AS CLIENTE
              ,BOOL_OR(IAA.OIAP_DATA_HORA_FIM IS NULL) AS EM_PRODUCAO
              ,COALESCE(SUM(IAA.OIAP_QTD_PRODUZIDA), 0) AS QTD_PRODUZIDA
          FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
          LEFT JOIN FUNCIONARIO        F   ON F.FU_CODIGO    = IAA.FU_CODIGO
          LEFT JOIN CENTRO_TRABALHO    CT  ON CT.CT_CODIGO   = IAA.CT_CODIGO
          LEFT JOIN PRODUTO            P   ON P.PRO_CODIGO   = IAA.PRO_CODIGO
          LEFT JOIN CLIENTES           C   ON C.CLI_CODIGO   = IAA.CLI_CODIGO
          LEFT JOIN ATIVIDADE_PRODUCAO ATP ON ATP.ATP_CODIGO = IAA.ATP_CODIGO
         WHERE NOT IAA.OIAP_EXCLUIDO
           AND TRIM(IAA.OIAP_STATUS) <> 'C'
           AND IAA.FU_CODIGO IS NOT NULL
           AND IAA.OIAP_DATA_HORA_INICIO::date = {$diaLit}::date
         GROUP BY IAA.FU_CODIGO, F.FU_NOME, IAA.CT_CODIGO, CT.CT_DESCRICAO,
                  IAA.PROD_CODIGO, IAA.PRO_CODIGO, P.PRO_DESCRICAO, P.GRP_CODIGO,
                  IAA.ATP_CODIGO, ATP.ATP_DESCRICAO,
                  C.CLI_NOME_FANTASIA, C.CLI_NOME
    ";
    $res = @pg_query($pg, $sql);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Produção do dia agrupada por funcionário (ranking) ───────────────────────
function biiFetchProducaoPorFuncionario($pg, string $dia): array
{
    $verdade = static fn ($v) => in_array($v, ['t', true, 1, '1'], true);
    $funcs = [];
    foreach (biiFetchApontamentos($pg, $dia) as $r) {
        $fu  = (int) $r['fu_codigo'];
        $ct  = (int) $r['ct_codigo'];
        $qtd = (float) $r['qtd_produzida'];
        $emProducao = $verdade($r['em_producao']);
        $tipo  = $r['tipo'] ?? null;
        $etapa = prodEtapaNome($r['etapa'] ?? '');

        if (!isset($funcs[$fu])) {
            $funcs[$fu] = [
                'nome'          => $r['fu_nome'] ?: ('#' . $fu),
                'qtd_produzida' => 0.0,
                'bag_pronto'    => 0.0,  // bags na etapa final: o que entra no total x meta
                'por_tipo'      => [],   // tipo => qtd, cada um na sua unidade
                'etapas'        => [],   // nome => qtd
                'em_producao'   => false,
                'centros'       => [],   // ct => ['nome','qtd']
                'ops'           => [],   // "prod|pro" => ['op','descricao','qtd','em_producao']
                'atividades'    => [],   // atp => produção da pessoa naquela atividade (slides do BI Individual)
            ];
        }
        $f = &$funcs[$fu];
        $atp = (int) ($r['atp_codigo'] ?? 0);
        if (!isset($f['atividades'][$atp])) {
            $f['atividades'][$atp] = [
                'atp' => $atp, 'etapa' => $etapa !== '' ? $etapa : 'Sem atividade', 'ordem' => prodAtpOrdem($atp),
                'tipo' => $tipo, 'unidade' => prodUnidade($tipo), 'qtd' => 0.0, 'em_producao' => false, 'ops' => [],
            ];
        }
        $at = &$f['atividades'][$atp];
        $at['qtd'] += $qtd;
        $at['em_producao'] = $at['em_producao'] || $emProducao;
        $kOp = (string) $r['prod_codigo'];
        $at['ops'][$kOp] ??= ['op' => (int) $r['prod_codigo'], 'cliente' => trim((string) $r['cliente']), 'qtd' => 0.0, 'em_producao' => false];
        $at['ops'][$kOp]['qtd'] += $qtd;
        $at['ops'][$kOp]['em_producao'] = $at['ops'][$kOp]['em_producao'] || $emProducao;
        unset($at);
        $f['qtd_produzida'] += $qtd;
        if ($tipo === PROD_TIPO_BAG && $verdade($r['pronto'] ?? false)) {
            $f['bag_pronto'] += $qtd;
        }
        $chaveTipo = $tipo ?? 'OUTRO';
        $f['por_tipo'][$chaveTipo] = ($f['por_tipo'][$chaveTipo] ?? 0.0) + $qtd;
        if ($etapa !== '') {
            $f['etapas'][$etapa] = ($f['etapas'][$etapa] ?? 0.0) + $qtd;
        }
        $f['em_producao'] = $f['em_producao'] || $emProducao;

        if (!isset($f['centros'][$ct])) {
            $f['centros'][$ct] = ['nome' => $r['ct_descricao'] ?: ('CT ' . $ct), 'qtd' => 0.0];
        }
        $f['centros'][$ct]['qtd'] += $qtd;

        $k = $r['prod_codigo'] . '|' . $r['pro_codigo'];
        if (!isset($f['ops'][$k])) {
            $f['ops'][$k] = [
                'op'          => (int) $r['prod_codigo'],
                'descricao'   => $r['pro_descricao'] ?: '',
                'cliente'     => trim((string) $r['cliente']),
                'qtd'         => 0.0,
                'em_producao' => false,
            ];
        }
        $f['ops'][$k]['qtd'] += $qtd;
        $f['ops'][$k]['em_producao'] = $f['ops'][$k]['em_producao'] || $emProducao;
        unset($f);
    }

    $resultado = [];
    foreach ($funcs as $fu => $f) {
        // Só entra no painel quem já produziu algo no dia
        if ($f['qtd_produzida'] <= 0) {
            continue;
        }
        $centros = array_values($f['centros']);
        usort($centros, static fn ($a, $b) => $b['qtd'] <=> $a['qtd']);
        $ops = array_values($f['ops']);
        // Em produção agora primeiro; depois por quantidade produzida no dia.
        usort($ops, static fn ($a, $b) => ($b['em_producao'] <=> $a['em_producao']) ?: ($b['qtd'] <=> $a['qtd']));
        arsort($f['etapas']);
        arsort($f['por_tipo']);
        $tipo = (string) array_key_first($f['por_tipo']);
        $atividades = [];
        foreach ($f['atividades'] as $at) {
            $opsAt = array_values($at['ops']);
            usort($opsAt, static fn ($a, $b) => ($b['em_producao'] <=> $a['em_producao']) ?: ($b['qtd'] <=> $a['qtd']));
            $atividades[] = ['ops_detalhe' => $opsAt] + array_diff_key($at, ['ops' => 0]);
        }
        usort($atividades, static fn ($a, $b) => $a['ordem'] <=> $b['ordem']);

        $resultado[] = [
            'fu_codigo'       => $fu,
            'nome'            => $f['nome'],
            'qtd_produzida'   => $f['por_tipo'][$tipo],       // só o tipo principal: unidades não se somam
            'bag_pronto'      => $f['bag_pronto'],
            'tipo'            => $tipo,                       // tipo principal da pessoa no dia
            'unidade'         => prodUnidade($tipo),
            'outros'          => array_sum($f['por_tipo']) - $f['por_tipo'][$tipo], // do outro tipo, se fez os dois
            'etapas'          => implode(', ', array_keys($f['etapas'])),
            'em_producao'     => $f['em_producao'],
            'centros_detalhe' => $centros,
            'ops_detalhe'     => $ops,
            'atividades'      => $atividades, // por atividade, na ordem da fábrica
        ];
    }
    // Ranking por tipo (bag primeiro): somar bags com sacarias não compara nada
    $ordemTipo = [PROD_TIPO_BAG => 0, PROD_TIPO_SACARIA => 1];
    usort($resultado, static fn ($a, $b) => (($ordemTipo[$a['tipo']] ?? 2) <=> ($ordemTipo[$b['tipo']] ?? 2))
        ?: ($b['qtd_produzida'] <=> $a['qtd_produzida']) ?: strcmp($a['nome'], $b['nome']));
    return $resultado;
}

