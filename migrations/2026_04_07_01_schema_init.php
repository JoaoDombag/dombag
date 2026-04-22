<?php
// ── 2026-04-07 · Schema inicial ───────────────────────────────────────────────
// Flag para máquinas que contabilizam na produção total.

adicionarcampotb(
    'maquinas',
    'maq_conta_producao',
    "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=conta no total de producao; 0=processo intermediario'"
);
