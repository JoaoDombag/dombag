<?php
declare(strict_types=1);

if (!function_exists('dbPDO')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
}

// ── Schema ────────────────────────────────────────────────────────────────────
function finEnsureSchema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS fin_contas_receber (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            descricao       VARCHAR(255)    NOT NULL,
            cliente_nome    VARCHAR(180)    NULL,
            categoria       VARCHAR(100)    NOT NULL DEFAULT 'Venda',
            valor           DECIMAL(15,2)   NOT NULL,
            valor_pago      DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            data_emissao    DATE            NOT NULL,
            data_vencimento DATE            NOT NULL,
            data_pagamento  DATE            NULL,
            status          ENUM('ABERTO','PAGO','PARCIAL','VENCIDO','CANCELADO') NOT NULL DEFAULT 'ABERTO',
            observacoes     TEXT            NULL,
            criado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fcr_status (status),
            INDEX idx_fcr_venc   (data_vencimento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS fin_contas_pagar (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            descricao       VARCHAR(255)    NOT NULL,
            fornecedor      VARCHAR(180)    NULL,
            categoria       VARCHAR(100)    NOT NULL DEFAULT 'Fornecedor',
            valor           DECIMAL(15,2)   NOT NULL,
            valor_pago      DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            data_emissao    DATE            NOT NULL,
            data_vencimento DATE            NOT NULL,
            data_pagamento  DATE            NULL,
            status          ENUM('ABERTO','PAGO','PARCIAL','VENCIDO','CANCELADO') NOT NULL DEFAULT 'ABERTO',
            observacoes     TEXT            NULL,
            criado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fcp_status (status),
            INDEX idx_fcp_venc   (data_vencimento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function finH(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function finMoney(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function finStatusLabel(string $s): string
{
    return match($s) {
        'ABERTO'    => 'Aberto',
        'PAGO'      => 'Pago',
        'PARCIAL'   => 'Parcial',
        'VENCIDO'   => 'Vencido',
        'CANCELADO' => 'Cancelado',
        default     => $s,
    };
}

function finStatusClass(string $s): string
{
    return match($s) {
        'PAGO'      => 'fs-pago',
        'PARCIAL'   => 'fs-parcial',
        'VENCIDO'   => 'fs-vencido',
        'CANCELADO' => 'fs-cancelado',
        default     => 'fs-aberto',
    };
}

/** Atualiza status automaticamente baseado em valor_pago e data_vencimento */
function finResolveStatus(float $valor, float $valorPago, string $vencimento): string
{
    if ($valorPago <= 0)              return date('Y-m-d') > $vencimento ? 'VENCIDO' : 'ABERTO';
    if ($valorPago >= $valor)         return 'PAGO';
    return date('Y-m-d') > $vencimento ? 'VENCIDO' : 'PARCIAL';
}

const FIN_CATEGORIAS_RECEBER = ['Venda', 'Serviço', 'Comissão', 'Reembolso', 'Outro'];
const FIN_CATEGORIAS_PAGAR   = ['Fornecedor', 'Folha de Pagamento', 'Impostos', 'Utilidades', 'Aluguel', 'Manutenção', 'Marketing', 'Outro'];
