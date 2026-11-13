<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cores das Etapas de Produção (ETAPA_PRODUCAO.ETP_COR)
//  Usado no cadastro de etapas, na Reunião de Planejamento e no Kanban.
// ══════════════════════════════════════════════════════════════════════════════

// Cor de quem ainda não escolheu nenhuma (cinza do tema)
const ETP_COR_PADRAO = '#7a90b0';

// Sugestões no cadastro; o usuário pode escolher qualquer outra no seletor
const ETP_PALETA = [
    '#f59e0b', '#3b82f6', '#22c55e', '#ef4444', '#a855f7',
    '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b',
];

// Cores iniciais das etapas que já vêm cadastradas
const ETP_CORES_INICIAIS = [
    'Pendente de produção' => '#f59e0b',
    'Em produção'          => '#3b82f6',
    'Produção finalizada'  => '#22c55e',
];

function etpCorValida(?string $cor): ?string
{
    $cor = strtolower(trim((string) $cor));
    return preg_match('/^#[0-9a-f]{6}$/', $cor) ? $cor : null;
}

// Cria a coluna na primeira vez (mesmo padrão de PCP_PROGRAMACAO na Reunião) e
// dá cor às etapas padrão. A migration 2026_11_13_01 faz o mesmo.
function etpGarantirCor(PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $chk = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ETAPA_PRODUCAO' AND COLUMN_NAME = 'ETP_COR'"
        );
        $chk->execute();
        if (!(int) $chk->fetchColumn()) {
            $db->exec('ALTER TABLE ETAPA_PRODUCAO ADD COLUMN ETP_COR CHAR(7) NULL');
            $up = $db->prepare('UPDATE ETAPA_PRODUCAO SET ETP_COR = ? WHERE ETP_DESCRICAO = ? AND ETP_COR IS NULL');
            foreach (ETP_CORES_INICIAIS as $desc => $cor) {
                $up->execute([$cor, $desc]);
            }
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('[ETAPAS] ' . $e->getMessage());
        return $ok = false;
    }
}

// [['ETP_CODIGO', 'ETP_DESCRICAO', 'ETP_COR'], ...] com a cor sempre preenchida
function etpListar(PDO $db): array
{
    try {
        $temCor = etpGarantirCor($db);
        $rows = $db->query(
            'SELECT ETP_CODIGO, ETP_DESCRICAO' . ($temCor ? ', ETP_COR' : '') . ' FROM ETAPA_PRODUCAO ORDER BY ETP_CODIGO'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    foreach ($rows as &$r) {
        $r['ETP_COR'] = etpCorValida($r['ETP_COR'] ?? null) ?? ETP_COR_PADRAO;
    }
    return $rows;
}
