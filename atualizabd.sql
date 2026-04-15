-- ══════════════════════════════════════════════════════════════════════
--  DOMBAG — Histórico de alterações no banco de dados
--  Adicione novos SQLs sempre no final, com data e descrição.
--  Execute pelo painel: Admin → Atualiza BD
-- ══════════════════════════════════════════════════════════════════════


-- ── 2026-04-07 ───────────────────────────────────────────────────────

-- Adiciona flag para máquinas que contabilizam na produção total
ALTER TABLE maquinas
    ADD COLUMN maq_conta_producao TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=conta no total de producao; 0=processo intermediario';

-- Tabela de controle de migrations aplicados
CREATE TABLE IF NOT EXISTS db_migrations (
    mig_id           VARCHAR(60)  NOT NULL,
    mig_titulo       VARCHAR(200) NOT NULL DEFAULT '',
    mig_executado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mig_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Controle de migrations aplicados';

-- Tabela de SQL customizado por migration
CREATE TABLE IF NOT EXISTS db_migrations_sql (
    mig_id            VARCHAR(60) NOT NULL,
    mig_sql           TEXT        NOT NULL,
    mig_atualizado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (mig_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='SQL customizado por migration (sobrescreve o do arquivo)';


-- ── Adicione novas alterações abaixo desta linha ──────────────────────
