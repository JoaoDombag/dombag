-- ══════════════════════════════════════════════════════════════════════
--  DOMBAG — Histórico de alterações no banco de dados
--  Adicione novos SQLs sempre no final, com data e descrição.
--  Execute pelo painel: Admin → Atualiza BD
-- ══════════════════════════════════════════════════════════════════════


-- ── 2026-04-07 ───────────────────────────────────────────────────────

-- Adiciona flag para máquinas que contabilizam na produção total
ALTER TABLE MAQUINAS
    ADD COLUMN MAQ_CONTA_PRODUCAO TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=conta no total de producao; 0=processo intermediario';

-- Tabela de controle de migrations aplicados
CREATE TABLE IF NOT EXISTS DB_MIGRATIONS (
    MIG_ID           VARCHAR(60)  NOT NULL,
    MIG_TITULO       VARCHAR(200) NOT NULL DEFAULT '',
    MIG_EXECUTADO_EM DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MIG_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Controle de migrations aplicados';

-- Tabela de SQL customizado por migration
CREATE TABLE IF NOT EXISTS DB_MIGRATIONS_SQL (
    MIG_ID            VARCHAR(60) NOT NULL,
    MIG_SQL           TEXT        NOT NULL,
    MIG_ATUALIZADO_EM DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (MIG_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='SQL customizado por migration (sobrescreve o do arquivo)';


-- ── Adicione novas alterações abaixo desta linha ──────────────────────
