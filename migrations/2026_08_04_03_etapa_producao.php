<?php
// ── 2026-08-04 · Cadastro de Etapas de Produção ───────────────────────────────

executarsql("
    CREATE TABLE IF NOT EXISTS `ETAPA_PRODUCAO` (
      `ETP_CODIGO`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `ETP_DESCRICAO` VARCHAR(100) NOT NULL,
      PRIMARY KEY (`ETP_CODIGO`),
      UNIQUE KEY `UQ_ETP_DESCRICAO` (`ETP_DESCRICAO`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

executarsql("
    INSERT IGNORE INTO `ETAPA_PRODUCAO` (`ETP_DESCRICAO`) VALUES
      ('Pendente de produção'),
      ('Em produção'),
      ('Produção finalizada')
");
