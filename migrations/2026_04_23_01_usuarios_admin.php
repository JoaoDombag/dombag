<?php
// ── 2026-04-23 · Adiciona USU_ADMIN à tabela USUARIOS ────────────────────────

executarsql('SET FOREIGN_KEY_CHECKS = 0');
adicionarcampotb('USUARIOS', 'USU_ADMIN', 'TINYINT(1) NOT NULL DEFAULT 0');
executarsql('SET FOREIGN_KEY_CHECKS = 1');

// Marca como admin quem já tem USU_PERFIL = 'admin'
executarsql("UPDATE USUARIOS SET USU_ADMIN = 1 WHERE USU_PERFIL = 'admin'");
