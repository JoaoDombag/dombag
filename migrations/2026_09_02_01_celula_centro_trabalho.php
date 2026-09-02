<?php
// ── 2026-09-02 · Células passam a ser compostas por Centros de Trabalho ────────
//  Antes cada célula agrupava funcionários (CELULA_FUNCIONARIO). Agora agrupa
//  centros de trabalho do ERP (CENTRO_TRABALHO.CT_CODIGO).
executarsql("
    CREATE TABLE IF NOT EXISTS `CELULA_CENTRO_TRABALHO` (
      `CEL_CODIGO` INT UNSIGNED NOT NULL,
      `CT_CODIGO`  INT NOT NULL,
      PRIMARY KEY (`CEL_CODIGO`, `CT_CODIGO`),
      CONSTRAINT `FK_CELCT_CELULA` FOREIGN KEY (`CEL_CODIGO`)
        REFERENCES `CELULA_PRODUCAO` (`CEL_CODIGO`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

executarsql("DROP TABLE IF EXISTS `CELULA_FUNCIONARIO`");
