<?php
// ── 2026-10-13 · Remove o sistema de Células de Produção (abolido) ────────────

executarsql('DROP TABLE IF EXISTS `CELULA_CENTRO_TRABALHO`');
executarsql('DROP TABLE IF EXISTS `CELULA_PRODUCAO`');
executarsql("DELETE FROM `PARAMETROS` WHERE `PAR_CHAVE` = 'meta_diaria_celula'");
executarsql("DELETE FROM `PERMISSAO_ACESSO` WHERE `PAC_PAGINA` IN ('/pcp/bi-celulas', '/pcp/celulas')");
