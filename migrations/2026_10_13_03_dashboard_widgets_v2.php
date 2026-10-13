<?php
// ── 2026-10-13 · Dashboard refeito com KPIs do ERP (produção e saída) ──────────
//  Os widgets antigos liam tabelas locais que pararam de ser alimentadas. As
//  preferências novas ficam em "dashboard_widgets_v2_<usuário>"; as antigas
//  (lista de widgets que não existem mais) são removidas.
executarsql("DELETE FROM `PARAMETROS` WHERE `PAR_CHAVE` LIKE 'dashboard\\_widgets\\_%' AND `PAR_CHAVE` NOT LIKE 'dashboard\\_widgets\\_v2\\_%'");
