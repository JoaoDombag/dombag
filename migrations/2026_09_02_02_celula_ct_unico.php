<?php
// ── 2026-09-02 · Um centro de trabalho pode pertencer a apenas uma célula ─────
//  Remove vínculos duplicados (mantém a célula de menor código) e cria a
//  chave única em CT_CODIGO.
executarsql("
    DELETE t1 FROM `CELULA_CENTRO_TRABALHO` t1
    INNER JOIN `CELULA_CENTRO_TRABALHO` t2
       ON t1.CT_CODIGO = t2.CT_CODIGO
      AND t1.CEL_CODIGO > t2.CEL_CODIGO
");

adicionarchaveunica('CELULA_CENTRO_TRABALHO', 'UQ_CELCT_CT', 'CT_CODIGO');
