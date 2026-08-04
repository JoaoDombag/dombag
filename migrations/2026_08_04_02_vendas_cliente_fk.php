<?php
// ── 2026-08-04 · VENDAS passa a referenciar CLIENTES via FK ──────────────────
// Elimina a duplicação de nome/fantasia/CNPJ/UF/cidade gravados como texto
// livre em VENDAS a cada import — esses dados agora vivem só em CLIENTES.

executarsql('SET FOREIGN_KEY_CHECKS = 0');

// CLIENTES: relaxa CLI_CODIGO_YZ (não vem do import de vendas) e permite
// dedupe por CNPJ, que é o identificador disponível nesse fluxo.
executarsql('ALTER TABLE CLIENTES MODIFY COLUMN CLI_CODIGO_YZ VARCHAR(30) NULL DEFAULT NULL');
executarsql('ALTER TABLE CLIENTES MODIFY COLUMN CLI_CNPJ VARCHAR(20) NULL DEFAULT NULL');
executarsql('ALTER TABLE CLIENTES ADD UNIQUE KEY UQ_CLI_CNPJ (CLI_CNPJ)');

// VENDAS: troca os campos de texto livre por uma FK para CLIENTES.
adicionarcampotb('VENDAS', 'CLI_CODIGO', 'INT UNSIGNED NULL DEFAULT NULL');
executarsql('
    ALTER TABLE VENDAS
    ADD CONSTRAINT FK_VEN_CLIENTE
    FOREIGN KEY (CLI_CODIGO) REFERENCES CLIENTES (CLI_CODIGO)
    ON DELETE SET NULL ON UPDATE CASCADE
');

removercampotb('VENDAS', 'VEN_CLIENTE');
removercampotb('VENDAS', 'VEN_FANTASIA');
removercampotb('VENDAS', 'VEN_CNPJ');
removercampotb('VENDAS', 'VEN_UF');
removercampotb('VENDAS', 'VEN_CIDADE');

executarsql('SET FOREIGN_KEY_CHECKS = 1');
