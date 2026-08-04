<?php
// ── 2026-08-04 · Remove tabelas órfãs do módulo CRM (excluído) ────────────────

executarsql('DROP TABLE IF EXISTS `LEADS_ADS`');
executarsql('DROP TABLE IF EXISTS `LEADS_PROSPECTADOS`');
