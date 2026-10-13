<?php
// ── 2026-10-09 · Chat com IA (Claude) ────────────────────────────────────────
//  CHAT_CONVERSA : histórico de cada conversa (formato da API + versão para exibir)
//  CHAT_USO      : uma linha por mensagem/relatório — base do rate limit
//  CHAT_LOG      : chamadas à API e às ferramentas, com consumo de tokens.
//                  NÃO guarda texto de mensagens nem dados do ERP.

adicionartabela('CHAT_CONVERSA', "
    CCV_ID         CHAR(32)     NOT NULL,
    USU_CODIGO     INT UNSIGNED NOT NULL,
    CCV_HISTORICO  MEDIUMTEXT   NOT NULL,
    CCV_EXIBICAO   MEDIUMTEXT   NOT NULL,
    CCV_TURNOS     INT          NOT NULL DEFAULT 0,
    CCV_CRIADO     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CCV_ATUALIZADO DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (CCV_ID),
    KEY IX_CCV_USU (USU_CODIGO, CCV_ATUALIZADO)
");

adicionartabela('CHAT_USO', "
    CHU_CODIGO  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    USU_CODIGO  INT UNSIGNED    NOT NULL,
    CHU_IP      VARCHAR(45)     NOT NULL DEFAULT '',
    CHU_TIPO    VARCHAR(10)     NOT NULL DEFAULT 'msg',
    CHU_CRIADO  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (CHU_CODIGO),
    KEY IX_CHU_USU (USU_CODIGO, CHU_TIPO, CHU_CRIADO),
    KEY IX_CHU_IP  (CHU_IP, CHU_TIPO, CHU_CRIADO)
");

adicionartabela('CHAT_LOG', "
    CHL_CODIGO       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    USU_CODIGO       INT UNSIGNED    NOT NULL,
    CCV_ID           CHAR(32)        NOT NULL DEFAULT '',
    CHL_TIPO         VARCHAR(12)     NOT NULL DEFAULT 'api',
    CHL_FERRAMENTA   VARCHAR(40)     NOT NULL DEFAULT '',
    CHL_MODELO       VARCHAR(40)     NOT NULL DEFAULT '',
    CHL_IN           INT             NOT NULL DEFAULT 0,
    CHL_OUT          INT             NOT NULL DEFAULT 0,
    CHL_CACHE_READ   INT             NOT NULL DEFAULT 0,
    CHL_CACHE_WRITE  INT             NOT NULL DEFAULT 0,
    CHL_LINHAS       INT             NOT NULL DEFAULT 0,
    CHL_MS           INT             NOT NULL DEFAULT 0,
    CHL_STATUS       VARCHAR(20)     NOT NULL DEFAULT 'ok',
    CHL_CRIADO       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (CHL_CODIGO),
    KEY IX_CHL_DATA (CHL_CRIADO),
    KEY IX_CHL_USU  (USU_CODIGO, CHL_CRIADO)
");
