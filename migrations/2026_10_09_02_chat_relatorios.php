<?php
// ── 2026-10-09 · Chat com IA — fontes de dados e relatórios ──────────────────
//  CHAT_FONTE     : resultado de cada consulta feita pelo chat (F1, F2...). Os
//                   relatórios só podem usar dados daqui — a IA não digita números.
//  CHAT_RELATORIO : arquivos gerados; o token é o link de download (24h, só o dono).

adicionartabela('CHAT_FONTE', "
    CHF_CODIGO     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    CCV_ID         CHAR(32)        NOT NULL,
    USU_CODIGO     INT UNSIGNED    NOT NULL,
    CHF_ID         VARCHAR(6)      NOT NULL,
    CHF_FERRAMENTA VARCHAR(40)     NOT NULL,
    CHF_DADOS      MEDIUMTEXT      NOT NULL,
    CHF_CRIADO     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (CHF_CODIGO),
    UNIQUE KEY UQ_CHF (CCV_ID, USU_CODIGO, CHF_ID),
    KEY IX_CHF_CRIADO (CHF_CRIADO)
");

adicionartabela('CHAT_RELATORIO', "
    CHR_TOKEN    CHAR(32)     NOT NULL,
    USU_CODIGO   INT UNSIGNED NOT NULL,
    CCV_ID       CHAR(32)     NOT NULL DEFAULT '',
    CHR_ARQUIVO  VARCHAR(80)  NOT NULL,
    CHR_NOME     VARCHAR(150) NOT NULL,
    CHR_FORMATO  VARCHAR(4)   NOT NULL,
    CHR_EXPIRA   DATETIME     NOT NULL,
    CHR_CRIADO   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (CHR_TOKEN),
    KEY IX_CHR_USU (USU_CODIGO),
    KEY IX_CHR_EXPIRA (CHR_EXPIRA)
");
