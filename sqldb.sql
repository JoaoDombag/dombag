-- ══════════════════════════════════════════════════════════════════════════════
--  DOMBAG — Schema Completo do Banco de Dados
--  Banco: joaofr15_sistemadombag  |  Charset: utf8mb4
--  Convenção: nomes de tabelas e colunas em MAIÚSCULAS com underscores
--  Ordem respeitando chaves estrangeiras
-- ══════════════════════════════════════════════════════════════════════════════

USE `joaofr15_sistemadombag`;

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
--  1. GRUPO_USUARIO
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `GRUPO_USUARIO` (
  `GRU_CODIGO`    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `GRU_NOME`      VARCHAR(50)     NOT NULL,
  `GRU_DESCRICAO` VARCHAR(200)    NULL,
  PRIMARY KEY (`GRU_CODIGO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  2. USUARIOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `USUARIOS` (
  `USU_CODIGO` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `USU_LOGIN`  VARCHAR(25)   NOT NULL,
  `USU_SENHA`  VARCHAR(255)  NOT NULL,
  `USU_NOME`   VARCHAR(50)   NOT NULL,
  `USU_PERFIL` VARCHAR(20)   NOT NULL DEFAULT 'usuario',
  `USU_ATIVO`  TINYINT(1)    NOT NULL DEFAULT 1,
  `GRU_CODIGO` INT UNSIGNED  NULL,
  PRIMARY KEY (`USU_CODIGO`),
  UNIQUE KEY `uq_usu_login` (`USU_LOGIN`),
  KEY `fk_usu_grupo` (`GRU_CODIGO`),
  CONSTRAINT `fk_usu_grupo`
    FOREIGN KEY (`GRU_CODIGO`) REFERENCES `GRUPO_USUARIO` (`GRU_CODIGO`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  3. PERMISSAO_ACESSO
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `PERMISSAO_ACESSO` (
  `PAC_CODIGO`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `PAC_GRU_CODIGO` INT UNSIGNED NOT NULL,
  `PAC_PAGINA`     VARCHAR(150) NOT NULL,
  PRIMARY KEY (`PAC_CODIGO`),
  UNIQUE KEY `uk_gru_pag` (`PAC_GRU_CODIGO`, `PAC_PAGINA`),
  CONSTRAINT `fk_pac_grupo`
    FOREIGN KEY (`PAC_GRU_CODIGO`) REFERENCES `GRUPO_USUARIO` (`GRU_CODIGO`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  4. DEPARTAMENTOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `DEPARTAMENTOS` (
  `DP_CODIGO`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `DP_DESCRICAO` VARCHAR(25)  NOT NULL,
  PRIMARY KEY (`DP_CODIGO`),
  UNIQUE KEY `uq_dp_descricao` (`DP_DESCRICAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Departamentos / setores de produção';

-- Dados padrão de departamentos
INSERT IGNORE INTO `DEPARTAMENTOS` (`DP_DESCRICAO`) VALUES
  ('Impressão'),
  ('Corte e Solda'),
  ('Costura'),
  ('Acabamento'),
  ('Expedição');

-- ─────────────────────────────────────────────────────────────────────────────
--  5. MAQUINAS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `MAQUINAS` (
  `MAQ_CODIGO`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `MAQ_DESCRICAO`      VARCHAR(120)   NOT NULL,
  `MAQ_QTDE`           DECIMAL(6,2)   NOT NULL DEFAULT 1.00,
  `MAQ_PRODUCAO_MIN`   DECIMAL(10,4)  NOT NULL DEFAULT 0.0000 COMMENT 'Unidades por minuto',
  `MAQ_HORAS_DIA`      DECIMAL(5,2)   NOT NULL DEFAULT 8.00,
  `DP_CODIGO`          INT UNSIGNED   NOT NULL COMMENT 'Departamento ao qual a máquina pertence',
  `MAQ_GRUPO`          VARCHAR(80)    NULL DEFAULT NULL,
  `MAQ_CONTA_PRODUCAO` TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '1 = conta no total; 0 = processo intermediário (não contabiliza para evitar duplicidade)',
  PRIMARY KEY (`MAQ_CODIGO`),
  UNIQUE KEY `uq_maq_descricao` (`MAQ_DESCRICAO`),
  KEY `fk_maq_depto` (`DP_CODIGO`),
  CONSTRAINT `fk_maq_depto`
    FOREIGN KEY (`DP_CODIGO`) REFERENCES `DEPARTAMENTOS` (`DP_CODIGO`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cadastro de máquinas do chão de fábrica';

-- ─────────────────────────────────────────────────────────────────────────────
--  6. PRODUTOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `PRODUTOS` (
  `PRO_CODIGO`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `PRO_CODIGO_YZ`     VARCHAR(30)   NOT NULL,
  `PRO_DESCRICAO`     VARCHAR(200)  NOT NULL DEFAULT '',
  `PRO_TIPO`          ENUM('BAG','SACARIA','') NOT NULL DEFAULT '',
  `PRO_TRAVADO`       TINYINT(1)    NOT NULL DEFAULT 0,
  `PRO_IMPRESSAO`     TINYINT(1)    NOT NULL DEFAULT 0,
  `PRO_VALVULADO`     TINYINT(1)    NOT NULL DEFAULT 0,
  `PRO_COMPRIMENTO`   INT           NULL DEFAULT NULL,
  `PRO_MAQ_IMPRESSAO` VARCHAR(50)   NULL DEFAULT NULL,
  `PRO_FLUXO`         VARCHAR(300)  NOT NULL DEFAULT '',
  `PRO_CATEGORIA`     VARCHAR(60) GENERATED ALWAYS AS (
    CASE
      WHEN PRO_TIPO = 'BAG'     AND PRO_TRAVADO = 0 AND PRO_IMPRESSAO = 0 THEN 'Bag sem impressão'
      WHEN PRO_TIPO = 'BAG'     AND PRO_TRAVADO = 0 AND PRO_IMPRESSAO = 1 THEN 'Bag com impressão'
      WHEN PRO_TIPO = 'BAG'     AND PRO_TRAVADO = 1 AND PRO_IMPRESSAO = 0 THEN 'Bag travado sem impressão'
      WHEN PRO_TIPO = 'BAG'     AND PRO_TRAVADO = 1 AND PRO_IMPRESSAO = 1 THEN 'Bag travado com impressão'
      WHEN PRO_TIPO = 'SACARIA' AND PRO_IMPRESSAO = 0                     THEN 'Sacaria sem impressão'
      WHEN PRO_TIPO = 'SACARIA' AND PRO_IMPRESSAO = 1                     THEN 'Sacaria com impressão'
      ELSE 'Não classificado'
    END
  ) STORED,
  `PRO_CRIADO`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`PRO_CODIGO`),
  UNIQUE KEY `uq_pro_codigo_yz` (`PRO_CODIGO_YZ`),
  KEY `idx_pro_categoria` (`PRO_CATEGORIA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  7. CLIENTES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `CLIENTES` (
  `CLI_CODIGO`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `CLI_CODIGO_YZ` VARCHAR(30)   NOT NULL,
  `CLI_NOME`      VARCHAR(160)  NOT NULL DEFAULT '',
  `CLI_FANTASIA`  VARCHAR(160)  NOT NULL DEFAULT '',
  `CLI_CNPJ`      VARCHAR(20)   NOT NULL DEFAULT '',
  `CLI_UF`        CHAR(2)       NOT NULL DEFAULT '',
  `CLI_CIDADE`    VARCHAR(60)   NOT NULL DEFAULT '',
  `CLI_FONE`      VARCHAR(30)   NOT NULL DEFAULT '',
  `CLI_EMAIL`     VARCHAR(120)  NOT NULL DEFAULT '',
  `CLI_CRIADO`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CLI_CODIGO`),
  UNIQUE KEY `uq_cli_codigo_yz` (`CLI_CODIGO_YZ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  8. VENDAS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `VENDAS` (
  `VEN_CODIGO`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `VEN_CODIGO_YZIDRO`  VARCHAR(30)     NOT NULL,
  `VEN_CLIENTE`        VARCHAR(160)    NOT NULL DEFAULT '',
  `VEN_FANTASIA`       VARCHAR(160)    NOT NULL DEFAULT '',
  `VEN_CNPJ`           VARCHAR(20)     NOT NULL DEFAULT '',
  `VEN_REPRESENTANTE`  VARCHAR(100)    NOT NULL DEFAULT '',
  `VEN_UF`             CHAR(2)         NOT NULL DEFAULT '',
  `VEN_CIDADE`         VARCHAR(60)     NOT NULL DEFAULT '',
  `VEN_EMISSAO`        DATE            NULL DEFAULT NULL,
  `VEN_ENTREGA`        DATE            NULL DEFAULT NULL,
  `VEN_TOTAL`          DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  `VEN_SEGMENTO`       VARCHAR(64)     NOT NULL DEFAULT '',
  `VEN_GRUPO_CLIENTES` VARCHAR(64)     NOT NULL DEFAULT '',
  `VEN_OBS`            TEXT            NULL,
  `VEN_STATUS`         VARCHAR(50)     NULL DEFAULT NULL,
  `VEN_IMPORTADO_EM`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`VEN_CODIGO`),
  UNIQUE KEY `uq_ven_codigo_yzidro` (`VEN_CODIGO_YZIDRO`),
  KEY `idx_ven_entrega` (`VEN_ENTREGA`),
  KEY `idx_ven_cliente` (`VEN_CLIENTE`(60))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  9. ITENS_VENDAS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ITENS_VENDAS` (
  `IV_CODIGO`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `VEN_CODIGO`      INT UNSIGNED  NOT NULL,
  `PRO_CODIGO`      INT UNSIGNED  NOT NULL,
  `IV_QTDE`         DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `IV_VLR_UNIT`     DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `IV_TOTAL`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `IV_UNIDADE`      VARCHAR(10)   NOT NULL DEFAULT '',
  `IV_STATUS`       ENUM(
                      'Pendente de produção',
                      'Produção',
                      'Aguardando',
                      'Aguardando envio',
                      'Aguardando expedição',
                      'Finalizado'
                    ) NOT NULL DEFAULT 'Pendente de produção',
  `IV_PRIORIDADE`   INT           NOT NULL DEFAULT 0,
  `IV_OBS`          TEXT          NULL,
  `IV_ATUALIZADO_EM` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`IV_CODIGO`),
  UNIQUE KEY `uq_iv_venda_prod` (`VEN_CODIGO`, `PRO_CODIGO`),
  KEY `idx_iv_status` (`IV_STATUS`),
  KEY `idx_iv_prioridade` (`IV_PRIORIDADE`),
  KEY `fk_iv_produto` (`PRO_CODIGO`),
  CONSTRAINT `fk_iv_venda`
    FOREIGN KEY (`VEN_CODIGO`) REFERENCES `VENDAS` (`VEN_CODIGO`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_iv_produto`
    FOREIGN KEY (`PRO_CODIGO`) REFERENCES `PRODUTOS` (`PRO_CODIGO`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  10. ITENS_VENDAS_IMAGENS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ITENS_VENDAS_IMAGENS` (
  `IMG_CODIGO`    INT          NOT NULL AUTO_INCREMENT,
  `IV_CODIGO`     INT          NOT NULL,
  `IMG_ARQUIVO`   VARCHAR(255) NOT NULL,
  `IMG_NOME_ORIG` VARCHAR(255) NOT NULL,
  `IMG_CRIADO_EM` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`IMG_CODIGO`),
  KEY `idx_img_iv` (`IV_CODIGO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  11. PRODUCAO_DIARIA
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `PRODUCAO_DIARIA` (
  `PD_CODIGO`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `PD_DATA`        DATE          NOT NULL,
  `MAQ_CODIGO`     INT UNSIGNED  NOT NULL COMMENT 'Máquina que realizou a produção',
  `PD_FUNCIONARIO` VARCHAR(120)  NOT NULL DEFAULT '',
  `PD_HORARIO_INI` TIME          NOT NULL COMMENT 'Início do período',
  `PD_HORARIO_FIM` TIME          NOT NULL COMMENT 'Fim do período',
  `PD_QUANTIDADE`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `PD_PEDIDO`      VARCHAR(30)   NOT NULL DEFAULT '',
  `PD_COMENTARIO`  TEXT          NULL,
  `PD_CRIADO_EM`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`PD_CODIGO`),
  KEY `idx_pd_data`     (`PD_DATA`),
  KEY `idx_pd_maquina`  (`MAQ_CODIGO`),
  KEY `idx_pd_data_maq` (`PD_DATA`, `MAQ_CODIGO`),
  CONSTRAINT `fk_pd_maquina`
    FOREIGN KEY (`MAQ_CODIGO`) REFERENCES `MAQUINAS` (`MAQ_CODIGO`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Apontamentos de produção por hora';

-- ─────────────────────────────────────────────────────────────────────────────
--  12. LEADS_ADS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `LEADS_ADS` (
  `ID`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `LEAD_ID`             VARCHAR(120)  NULL DEFAULT NULL,
  `DATA_CRIACAO`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `NOME`                VARCHAR(160)  NULL,
  `TELEFONE`            VARCHAR(60)   NULL,
  `EMAIL`               VARCHAR(180)  NULL,
  `EMPRESA`             VARCHAR(180)  NULL,
  `PRODUTO_INTERESSE`   VARCHAR(180)  NULL,
  `ORIGEM_BOTAO`        VARCHAR(120)  NULL,
  `ORIGEM_LP`           VARCHAR(120)  NULL,
  `GCLID`               VARCHAR(255)  NULL,
  `GBRAID`              VARCHAR(255)  NULL,
  `WBRAID`              VARCHAR(255)  NULL,
  `UTM_SOURCE`          VARCHAR(120)  NULL,
  `UTM_MEDIUM`          VARCHAR(120)  NULL,
  `UTM_CAMPAIGN`        VARCHAR(180)  NULL,
  `STATUS_ATUAL`        VARCHAR(40)   NOT NULL DEFAULT 'NOVO',
  `DATA_QUALIFICACAO`   DATETIME      NULL DEFAULT NULL,
  `DATA_VENDA_FECHADA`  DATETIME      NULL DEFAULT NULL,
  `DATA_PERDA`          DATETIME      NULL DEFAULT NULL,
  `MOTIVO_PERDA`        TEXT          NULL,
  `VALOR_VENDA`         DECIMAL(15,2) NULL DEFAULT NULL,
  `VENDEDOR`            VARCHAR(120)  NULL,
  `REFERER_URL`         VARCHAR(255)  NULL,
  `LANDING_URL`         VARCHAR(255)  NULL,
  `IP_ORIGEM`           VARCHAR(64)   NULL,
  `USER_AGENT`          VARCHAR(255)  NULL,
  `PAYLOAD_JSON`        LONGTEXT      NULL,
  `DEDUPE_HASH`         CHAR(64)      NULL DEFAULT NULL,
  `CRIADO_EM`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ATUALIZADO_EM`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_leads_dedupe`  (`DEDUPE_HASH`),
  UNIQUE KEY `uq_leads_lead_id` (`LEAD_ID`),
  KEY `idx_leads_status`    (`STATUS_ATUAL`),
  KEY `idx_leads_data`      (`DATA_CRIACAO`),
  KEY `idx_leads_email`     (`EMAIL`),
  KEY `idx_leads_tel`       (`TELEFONE`),
  KEY `idx_leads_lp`        (`ORIGEM_LP`),
  KEY `idx_leads_vendedor`  (`VENDEDOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  13. LEADS_PROSPECTADOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `LEADS_PROSPECTADOS` (
  `ID`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `CNPJ`                  CHAR(18)      NOT NULL DEFAULT '',
  `NOME_EMPRESA`          VARCHAR(255)  NOT NULL,
  `SITE`                  VARCHAR(255)  NULL DEFAULT NULL,
  `TELEFONE`              VARCHAR(60)   NULL DEFAULT NULL,
  `EMAIL`                 VARCHAR(180)  NULL DEFAULT NULL,
  `CIDADE`                VARCHAR(120)  NULL DEFAULT NULL,
  `UF`                    CHAR(2)       NULL DEFAULT NULL,
  `SEGMENTO`              VARCHAR(180)  NULL DEFAULT NULL,
  `FONTE`                 VARCHAR(255)  NULL DEFAULT NULL,
  `MOTIVO_RELEVANCIA`     TEXT          NULL,
  `SCORE`                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `SEGMENTO_BUSCA`        VARCHAR(255)  NULL DEFAULT NULL,
  `DEDUPE_KEY`            CHAR(64)      NOT NULL,
  `STATUS_PROSP`          VARCHAR(40)   NOT NULL DEFAULT 'NOVO',
  `GRAVADO_EM`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `USUARIO_ID`            INT UNSIGNED  NULL DEFAULT NULL,
  `CONTATO_COMPRAS_NOME`  VARCHAR(255)  NULL DEFAULT NULL,
  `CONTATO_COMPRAS_MEIO`  VARCHAR(255)  NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_prosp_dedupe`   (`DEDUPE_KEY`),
  KEY `idx_prosp_uf`       (`UF`),
  KEY `idx_prosp_score`    (`SCORE`),
  KEY `idx_prosp_status`   (`STATUS_PROSP`),
  KEY `idx_prosp_segmento` (`SEGMENTO_BUSCA`(80))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  14. PARAMETROS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `PARAMETROS` (
  `PAR_CHAVE` VARCHAR(100) NOT NULL,
  `PAR_VALOR` TEXT         NULL,
  PRIMARY KEY (`PAR_CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  15. PCP_PLANEJAMENTO
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `PCP_PLANEJAMENTO` (
  `PP_CODIGO`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `PP_DATA`      DATE          NOT NULL,
  `MAQ_CODIGO`   INT UNSIGNED  NOT NULL,
  `IV_CODIGO`    INT UNSIGNED  NOT NULL,
  `PP_QTDE_PLAN` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `PP_QTDE_PROD` DECIMAL(12,2) NULL DEFAULT NULL,
  `PP_STATUS`    ENUM('Planejado','Registrado') NOT NULL DEFAULT 'Planejado',
  `PP_OBS`       TEXT          NULL,
  `PP_CRIADO_EM` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`PP_CODIGO`),
  KEY `idx_pp_data` (`PP_DATA`),
  KEY `idx_pp_maq`  (`MAQ_CODIGO`),
  KEY `idx_pp_iv`   (`IV_CODIGO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Planejamento diário do PCP por máquina e item de venda';

-- ─────────────────────────────────────────────────────────────────────────────
--  17. DB_MIGRATIONS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `DB_MIGRATIONS` (
  `MIG_ID`           VARCHAR(60)  NOT NULL,
  `MIG_TITULO`       VARCHAR(200) NOT NULL DEFAULT '',
  `MIG_EXECUTADO_EM` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`MIG_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Controle de migrations aplicados';

-- ─────────────────────────────────────────────────────────────────────────────
--  18. DB_MIGRATIONS_SQL
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `DB_MIGRATIONS_SQL` (
  `MIG_ID`            VARCHAR(60) NOT NULL,
  `MIG_SQL`           TEXT        NOT NULL,
  `MIG_ATUALIZADO_EM` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`MIG_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='SQL customizado por migration (sobrescreve o do arquivo)';

-- ─────────────────────────────────────────────────────────────────────────────
--  Usuário administrador padrão
--  Login: 1  |  Senha: 1  (troque imediatamente após o primeiro acesso)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `USUARIOS` (`USU_LOGIN`, `USU_SENHA`, `USU_NOME`, `USU_PERFIL`) VALUES
  ('1', '$2y$10$h5DXS9uJqrZalmwSf09Egu.m23CSYoIUUFEsEv3T8k4pwUlZNtTwK', 'Administrador', 'admin');

SET FOREIGN_KEY_CHECKS = 1;
