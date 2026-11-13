<?php
// ── 2026-11-13 · Cor de cada etapa de produção (Reunião e Kanban) ─────────────

adicionarcampotb('ETAPA_PRODUCAO', 'ETP_COR', 'CHAR(7) NULL');

executarsql("UPDATE `ETAPA_PRODUCAO` SET `ETP_COR` = '#f59e0b' WHERE `ETP_DESCRICAO` = 'Pendente de produção' AND `ETP_COR` IS NULL");
executarsql("UPDATE `ETAPA_PRODUCAO` SET `ETP_COR` = '#3b82f6' WHERE `ETP_DESCRICAO` = 'Em produção' AND `ETP_COR` IS NULL");
executarsql("UPDATE `ETAPA_PRODUCAO` SET `ETP_COR` = '#22c55e' WHERE `ETP_DESCRICAO` = 'Produção finalizada' AND `ETP_COR` IS NULL");
