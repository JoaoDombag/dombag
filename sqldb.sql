-- ══════════════════════════════════════════════════════════════════════════════
--  DOMBAG — Schema Completo do Banco de Dados
--  Banco: dombag  |  Charset: utf8mb4
--  Ordem respeitando chaves estrangeiras
-- ══════════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `dombag`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dombag`;

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
--  1. GRUPO_USUARIO
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `grupo_usuario` (
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
    FOREIGN KEY (`GRU_CODIGO`) REFERENCES `grupo_usuario` (`GRU_CODIGO`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  3. PERMISSAO_ACESSO
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `permissao_acesso` (
  `PAC_CODIGO`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `PAC_GRU_CODIGO` INT UNSIGNED NOT NULL,
  `PAC_PAGINA`     VARCHAR(150) NOT NULL,
  PRIMARY KEY (`PAC_CODIGO`),
  UNIQUE KEY `uk_gru_pag` (`PAC_GRU_CODIGO`, `PAC_PAGINA`),
  CONSTRAINT `fk_pac_grupo`
    FOREIGN KEY (`PAC_GRU_CODIGO`) REFERENCES `grupo_usuario` (`GRU_CODIGO`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  4. DEPARTAMENTOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `departamentos` (
  `dp_codigo`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dp_descricao` VARCHAR(25)  NOT NULL,
  PRIMARY KEY (`dp_codigo`),
  UNIQUE KEY `uq_dp_descricao` (`dp_descricao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Departamentos / setores de produção';

-- Dados padrão de departamentos
INSERT IGNORE INTO `departamentos` (`dp_descricao`) VALUES
  ('Impressão'),
  ('Corte e Solda'),
  ('Costura'),
  ('Acabamento'),
  ('Expedição');

-- ─────────────────────────────────────────────────────────────────────────────
--  5. MAQUINAS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `maquinas` (
  `maq_codigo`       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `maq_descricao`    VARCHAR(120)   NOT NULL,
  `maq_qtde`         DECIMAL(6,2)   NOT NULL DEFAULT 1.00,
  `maq_producao_min` DECIMAL(10,4)  NOT NULL DEFAULT 0.0000 COMMENT 'Unidades por minuto',
  `maq_horas_dia`    DECIMAL(5,2)   NOT NULL DEFAULT 8.00,
  `dp_codigo`        INT UNSIGNED   NOT NULL COMMENT 'Departamento ao qual a máquina pertence',
  `maq_grupo`        VARCHAR(80)    NULL DEFAULT NULL,
  `maq_conta_producao` TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '1 = conta no total; 0 = processo intermediário (não contabiliza para evitar duplicidade)',
  PRIMARY KEY (`maq_codigo`),
  UNIQUE KEY `uq_maq_descricao` (`maq_descricao`),
  KEY `fk_maq_depto` (`dp_codigo`),
  CONSTRAINT `fk_maq_depto`
    FOREIGN KEY (`dp_codigo`) REFERENCES `departamentos` (`dp_codigo`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cadastro de máquinas do chão de fábrica';

-- ─────────────────────────────────────────────────────────────────────────────
--  6. PRODUTOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `produtos` (
  `pro_codigo`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `pro_codigo_yz`   VARCHAR(30)   NOT NULL,
  `pro_descricao`   VARCHAR(200)  NOT NULL DEFAULT '',
  `pro_tipo`        ENUM('BAG','SACARIA','') NOT NULL DEFAULT '',
  `pro_travado`     TINYINT(1)    NOT NULL DEFAULT 0,
  `pro_impressao`   TINYINT(1)    NOT NULL DEFAULT 0,
  `pro_valvulado`   TINYINT(1)    NOT NULL DEFAULT 0,
  `pro_comprimento` INT           NULL DEFAULT NULL,
  `pro_maq_impressao` VARCHAR(50) NULL DEFAULT NULL,
  `pro_fluxo`       VARCHAR(300)  NOT NULL DEFAULT '',
  `pro_categoria`   VARCHAR(60) GENERATED ALWAYS AS (
    CASE
      WHEN pro_tipo = 'BAG'     AND pro_travado = 0 AND pro_impressao = 0 THEN 'Bag sem impressão'
      WHEN pro_tipo = 'BAG'     AND pro_travado = 0 AND pro_impressao = 1 THEN 'Bag com impressão'
      WHEN pro_tipo = 'BAG'     AND pro_travado = 1 AND pro_impressao = 0 THEN 'Bag travado sem impressão'
      WHEN pro_tipo = 'BAG'     AND pro_travado = 1 AND pro_impressao = 1 THEN 'Bag travado com impressão'
      WHEN pro_tipo = 'SACARIA' AND pro_impressao = 0                     THEN 'Sacaria sem impressão'
      WHEN pro_tipo = 'SACARIA' AND pro_impressao = 1                     THEN 'Sacaria com impressão'
      ELSE 'Não classificado'
    END
  ) STORED,
  `pro_criado`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pro_codigo`),
  UNIQUE KEY `uq_pro_codigo_yz` (`pro_codigo_yz`),
  KEY `idx_pro_categoria` (`pro_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  7. CLIENTES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `clientes` (
  `cli_codigo`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `cli_codigo_yz` VARCHAR(30)   NOT NULL,
  `cli_nome`      VARCHAR(160)  NOT NULL DEFAULT '',
  `cli_fantasia`  VARCHAR(160)  NOT NULL DEFAULT '',
  `cli_cnpj`      VARCHAR(20)   NOT NULL DEFAULT '',
  `cli_uf`        CHAR(2)       NOT NULL DEFAULT '',
  `cli_cidade`    VARCHAR(60)   NOT NULL DEFAULT '',
  `cli_fone`      VARCHAR(30)   NOT NULL DEFAULT '',
  `cli_email`     VARCHAR(120)  NOT NULL DEFAULT '',
  `cli_criado`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cli_codigo`),
  UNIQUE KEY `uq_cli_codigo_yz` (`cli_codigo_yz`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  8. VENDAS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vendas` (
  `ven_codigo`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `ven_codigo_yzidro`   VARCHAR(30)     NOT NULL,
  `ven_cliente`         VARCHAR(160)    NOT NULL DEFAULT '',
  `ven_fantasia`        VARCHAR(160)    NOT NULL DEFAULT '',
  `ven_cnpj`            VARCHAR(20)     NOT NULL DEFAULT '',
  `ven_representante`   VARCHAR(100)    NOT NULL DEFAULT '',
  `ven_uf`              CHAR(2)         NOT NULL DEFAULT '',
  `ven_cidade`          VARCHAR(60)     NOT NULL DEFAULT '',
  `ven_emissao`         DATE            NULL DEFAULT NULL,
  `ven_entrega`         DATE            NULL DEFAULT NULL,
  `ven_total`           DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  `ven_segmento`        VARCHAR(64)     NOT NULL DEFAULT '',
  `ven_grupo_clientes`  VARCHAR(64)     NOT NULL DEFAULT '',
  `ven_obs`             TEXT            NULL,
  `ven_status`          VARCHAR(50)     NULL DEFAULT NULL,
  `ven_importado_em`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ven_codigo`),
  UNIQUE KEY `uq_ven_codigo_yzidro` (`ven_codigo_yzidro`),
  KEY `idx_ven_entrega` (`ven_entrega`),
  KEY `idx_ven_cliente` (`ven_cliente`(60))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  9. ITENS_VENDAS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itens_vendas` (
  `iv_codigo`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `ven_codigo`      INT UNSIGNED  NOT NULL,
  `pro_codigo`      INT UNSIGNED  NOT NULL,
  `iv_qtde`         DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `iv_vlr_unit`     DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `iv_total`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `iv_unidade`      VARCHAR(10)   NOT NULL DEFAULT '',
  `iv_status`       ENUM(
                      'Pendente de produção',
                      'Produção',
                      'Aguardando',
                      'Aguardando envio',
                      'Aguardando expedição',
                      'Finalizado'
                    ) NOT NULL DEFAULT 'Pendente de produção',
  `iv_prioridade`   INT           NOT NULL DEFAULT 0,
  `iv_obs`          TEXT          NULL,
  `iv_atualizado_em` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`iv_codigo`),
  UNIQUE KEY `uq_iv_venda_prod` (`ven_codigo`, `pro_codigo`),
  KEY `idx_iv_status` (`iv_status`),
  KEY `idx_iv_prioridade` (`iv_prioridade`),
  KEY `fk_iv_produto` (`pro_codigo`),
  CONSTRAINT `fk_iv_venda`
    FOREIGN KEY (`ven_codigo`) REFERENCES `vendas` (`ven_codigo`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_iv_produto`
    FOREIGN KEY (`pro_codigo`) REFERENCES `produtos` (`pro_codigo`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  10. ITENS_VENDAS_IMAGENS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itens_vendas_imagens` (
  `img_codigo`    INT          NOT NULL AUTO_INCREMENT,
  `iv_codigo`     INT          NOT NULL,
  `img_arquivo`   VARCHAR(255) NOT NULL,
  `img_nome_orig` VARCHAR(255) NOT NULL,
  `img_criado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`img_codigo`),
  KEY `idx_img_iv` (`iv_codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  11. PRODUCAO_DIARIA
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `producao_diaria` (
  `pd_codigo`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `pd_data`        DATE          NOT NULL,
  `maq_codigo`     INT UNSIGNED  NOT NULL COMMENT 'Máquina que realizou a produção',
  `pd_funcionario` VARCHAR(120)  NOT NULL DEFAULT '',
  `pd_horario_ini` TIME          NOT NULL COMMENT 'Início do período',
  `pd_horario_fim` TIME          NOT NULL COMMENT 'Fim do período',
  `pd_quantidade`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `pd_pedido`      VARCHAR(30)   NOT NULL DEFAULT '',
  `pd_comentario`  TEXT          NULL,
  `pd_criado_em`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pd_codigo`),
  KEY `idx_pd_data`     (`pd_data`),
  KEY `idx_pd_maquina`  (`maq_codigo`),
  KEY `idx_pd_data_maq` (`pd_data`, `maq_codigo`),
  CONSTRAINT `fk_pd_maquina`
    FOREIGN KEY (`maq_codigo`) REFERENCES `maquinas` (`maq_codigo`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Apontamentos de produção por hora';

-- ─────────────────────────────────────────────────────────────────────────────
--  12. LEADS_ADS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leads_ads` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `lead_id`             VARCHAR(120)  NULL DEFAULT NULL,
  `data_criacao`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nome`                VARCHAR(160)  NULL,
  `telefone`            VARCHAR(60)   NULL,
  `email`               VARCHAR(180)  NULL,
  `empresa`             VARCHAR(180)  NULL,
  `produto_interesse`   VARCHAR(180)  NULL,
  `origem_botao`        VARCHAR(120)  NULL,
  `origem_lp`           VARCHAR(120)  NULL,
  `gclid`               VARCHAR(255)  NULL,
  `gbraid`              VARCHAR(255)  NULL,
  `wbraid`              VARCHAR(255)  NULL,
  `utm_source`          VARCHAR(120)  NULL,
  `utm_medium`          VARCHAR(120)  NULL,
  `utm_campaign`        VARCHAR(180)  NULL,
  `status_atual`        VARCHAR(40)   NOT NULL DEFAULT 'NOVO',
  `data_qualificacao`   DATETIME      NULL DEFAULT NULL,
  `data_venda_fechada`  DATETIME      NULL DEFAULT NULL,
  `data_perda`          DATETIME      NULL DEFAULT NULL,
  `motivo_perda`        TEXT          NULL,
  `valor_venda`         DECIMAL(15,2) NULL DEFAULT NULL,
  `vendedor`            VARCHAR(120)  NULL,
  `referer_url`         VARCHAR(255)  NULL,
  `landing_url`         VARCHAR(255)  NULL,
  `ip_origem`           VARCHAR(64)   NULL,
  `user_agent`          VARCHAR(255)  NULL,
  `payload_json`        LONGTEXT      NULL,
  `dedupe_hash`         CHAR(64)      NULL DEFAULT NULL,
  `criado_em`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_leads_dedupe`  (`dedupe_hash`),
  UNIQUE KEY `uq_leads_lead_id` (`lead_id`),
  KEY `idx_leads_status`    (`status_atual`),
  KEY `idx_leads_data`      (`data_criacao`),
  KEY `idx_leads_email`     (`email`),
  KEY `idx_leads_tel`       (`telefone`),
  KEY `idx_leads_lp`        (`origem_lp`),
  KEY `idx_leads_vendedor`  (`vendedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  13. LEADS_PROSPECTADOS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leads_prospectados` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `cnpj`             CHAR(18)      NOT NULL DEFAULT '',
  `nome_empresa`     VARCHAR(255)  NOT NULL,
  `site`             VARCHAR(255)  NULL DEFAULT NULL,
  `telefone`         VARCHAR(60)   NULL DEFAULT NULL,
  `email`            VARCHAR(180)  NULL DEFAULT NULL,
  `cidade`           VARCHAR(120)  NULL DEFAULT NULL,
  `uf`               CHAR(2)       NULL DEFAULT NULL,
  `segmento`         VARCHAR(180)  NULL DEFAULT NULL,
  `fonte`            VARCHAR(255)  NULL DEFAULT NULL,
  `motivo_relevancia` TEXT         NULL,
  `score`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `segmento_busca`   VARCHAR(255)  NULL DEFAULT NULL,
  `dedupe_key`       CHAR(64)      NOT NULL,
  `status_prosp`     VARCHAR(40)   NOT NULL DEFAULT 'NOVO',
  `gravado_em`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prosp_dedupe`   (`dedupe_key`),
  KEY `idx_prosp_uf`       (`uf`),
  KEY `idx_prosp_score`    (`score`),
  KEY `idx_prosp_status`   (`status_prosp`),
  KEY `idx_prosp_segmento` (`segmento_busca`(80))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  14. PARAMETROS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `parametros` (
  `PAR_CHAVE` VARCHAR(100) NOT NULL,
  `PAR_VALOR` TEXT         NULL,
  PRIMARY KEY (`PAR_CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
--  Usuário administrador padrão
--  Login: 1  |  Senha: 1  (troque imediatamente após o primeiro acesso)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `USUARIOS` (`USU_LOGIN`, `USU_SENHA`, `USU_NOME`, `USU_PERFIL`) VALUES
  ('1', '$2y$10$h5DXS9uJqrZalmwSf09Egu.m23CSYoIUUFEsEv3T8k4pwUlZNtTwK', 'Administrador', 'admin');

SET FOREIGN_KEY_CHECKS = 1;
