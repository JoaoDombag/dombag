<?php
// ── 2026-11-05 · Agendamento: início da produção e saída de cada pedido ───────

// PCP_PROGRAMACAO nasce em planejamento2.php; em banco novo ela cria estas colunas.
if (_migCtx('pdo')->query("SHOW TABLES LIKE 'PCP_PROGRAMACAO'")->fetchColumn()) {
    adicionarcampotb('PCP_PROGRAMACAO', 'DATA_INICIO_PRODUCAO', 'DATE NULL');
    adicionarcampotb('PCP_PROGRAMACAO', 'DATA_SAIDA', 'DATE NULL');
}
