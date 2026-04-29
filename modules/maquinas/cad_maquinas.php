<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════
//  DOMBAG — Cadastro / Edição de Máquina
//  Formulário único; lista em consulta_maquinas.php
// ══════════════════════════════════════════════════

$db_error = '';
$deptos   = [];

// Valores padrão do formulário
$f = [
    'maq_codigo'         => 0,
    'maq_descricao'      => '',
    'maq_grupo'          => '',
    'maq_qtde'           => 1,
    'maq_producao_min'   => 0,
    'maq_horas_dia'      => 8,
    'dp_codigo'          => 0,
    'maq_conta_producao' => 1,
];
$editando      = false;
$titulo_pagina = 'Nova Máquina';

try {
    $pdo = dbPDO();

    // ── DDL mínimo: garante que as tabelas e colunas existem ──
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS DEPARTAMENTOS (
            dp_codigo    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dp_descricao VARCHAR(25) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec("
        INSERT IGNORE INTO DEPARTAMENTOS (dp_descricao) VALUES ('BAG'), ('SACARIA')
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS MAQUINAS (
            maq_codigo         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            maq_descricao      VARCHAR(120)  NOT NULL UNIQUE,
            maq_qtde           NUMERIC(6,2)  NOT NULL DEFAULT 1,
            maq_producao_min   NUMERIC(10,4) NOT NULL DEFAULT 0
                               COMMENT 'Unidades por minuto',
            maq_horas_dia      NUMERIC(5,2)  NOT NULL DEFAULT 8,
            dp_codigo          INT UNSIGNED  NOT NULL
                               COMMENT 'Departamento da máquina',
            maq_conta_producao TINYINT(1)    NOT NULL DEFAULT 1
                               COMMENT '1=conta no total; 0=processo intermediário',
            CONSTRAINT fk_maq_depto
                FOREIGN KEY (dp_codigo)
                REFERENCES DEPARTAMENTOS (dp_codigo)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $actualCols = array_map(
        'strtolower',
        $pdo->query('SHOW COLUMNS FROM MAQUINAS')->fetchAll(PDO::FETCH_COLUMN)
    );
    $missingCols = [
        'maq_qtde'           => "ALTER TABLE MAQUINAS ADD COLUMN maq_qtde NUMERIC(6,2) NOT NULL DEFAULT 1",
        'maq_producao_min'   => "ALTER TABLE MAQUINAS ADD COLUMN maq_producao_min NUMERIC(10,4) NOT NULL DEFAULT 0 COMMENT 'Unidades por minuto'",
        'maq_horas_dia'      => "ALTER TABLE MAQUINAS ADD COLUMN maq_horas_dia NUMERIC(5,2) NOT NULL DEFAULT 8",
        'maq_conta_producao' => "ALTER TABLE MAQUINAS ADD COLUMN maq_conta_producao TINYINT(1) NOT NULL DEFAULT 1",
        'maq_grupo'          => "ALTER TABLE MAQUINAS ADD COLUMN maq_grupo VARCHAR(80) NULL DEFAULT NULL",
    ];
    foreach ($missingCols as $col => $ddl) {
        if (!in_array($col, $actualCols)) {
            $pdo->exec($ddl);
        }
    }

    // ── Busca departamentos ──────────────────────
    $deptos = array_map(
        fn($r) => array_change_key_case($r, CASE_LOWER),
        $pdo->query('SELECT dp_codigo, dp_descricao FROM DEPARTAMENTOS ORDER BY dp_descricao')
            ->fetchAll(PDO::FETCH_ASSOC)
    );

    // ── Modo edição: pré-carrega registro via GET ─
    $id_get = intval($_GET['id'] ?? 0);
    if ($id_get > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $stmt = $pdo->prepare('
            SELECT maq_codigo, maq_descricao, maq_grupo, maq_qtde,
                   maq_producao_min, maq_horas_dia, dp_codigo, maq_conta_producao
            FROM   MAQUINAS
            WHERE  maq_codigo = :id
        ');
        $stmt->execute([':id' => $id_get]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $f             = array_change_key_case($row, CASE_LOWER);
            $editando      = true;
            $titulo_pagina = 'Editando: ' . $f['maq_descricao'];
        } else {
            $db_error = 'Máquina não encontrada.';
        }
    }

    // ── Ação POST: salvar ────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
        $desc  = trim($_POST['maq_descricao'] ?? '');
        $grupo = trim($_POST['maq_grupo'] ?? '') ?: null;
        $qtde  = (float) str_replace(',', '.', $_POST['maq_qtde'] ?? 1);
        $prod  = (float) str_replace(',', '.', $_POST['maq_producao_min'] ?? 0);
        $horas = (float) str_replace(',', '.', $_POST['maq_horas_dia'] ?? 8);
        $depto = intval($_POST['dp_codigo'] ?? 0);
        $cod   = intval($_POST['maq_codigo'] ?? 0);
        $conta = isset($_POST['maq_conta_producao']) ? 1 : 0;

        $f = [
            'maq_codigo'         => $cod,
            'maq_descricao'      => $desc,
            'maq_grupo'          => $_POST['maq_grupo'] ?? '',
            'maq_qtde'           => $qtde,
            'maq_producao_min'   => $prod,
            'maq_horas_dia'      => $horas,
            'dp_codigo'          => $depto,
            'maq_conta_producao' => $conta,
        ];
        $editando      = $cod > 0;
        $titulo_pagina = $editando ? 'Editando: ' . $desc : 'Nova Máquina';

        if ($desc === '') {
            $db_error = 'A descrição da máquina é obrigatória.';
        } elseif ($depto === 0) {
            $db_error = 'Selecione o departamento.';
        } elseif ($cod > 0) {
            try {
                $stmt = $pdo->prepare('
                    UPDATE MAQUINAS SET
                        maq_descricao      = :desc,
                        maq_grupo          = :grupo,
                        maq_qtde           = :qtde,
                        maq_producao_min   = :prod,
                        maq_horas_dia      = :horas,
                        dp_codigo          = :depto,
                        maq_conta_producao = :conta
                    WHERE maq_codigo = :cod
                ');
                $stmt->execute([
                    ':desc'  => $desc,  ':grupo' => $grupo,
                    ':qtde'  => $qtde,  ':prod'  => $prod,
                    ':horas' => $horas, ':depto' => $depto,
                    ':conta' => $conta, ':cod'   => $cod,
                ]);
                header('Location: /maquinas');
                exit;
            } catch (PDOException $e) {
                $db_error = 'Erro ao atualizar: ' . $e->getMessage();
            }
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO MAQUINAS
                        (maq_descricao, maq_grupo, maq_qtde, maq_producao_min, maq_horas_dia, dp_codigo, maq_conta_producao)
                    VALUES (:desc, :grupo, :qtde, :prod, :horas, :depto, :conta)
                ');
                $stmt->execute([
                    ':desc'  => $desc,  ':grupo' => $grupo,
                    ':qtde'  => $qtde,  ':prod'  => $prod,
                    ':horas' => $horas, ':depto' => $depto,
                    ':conta' => $conta,
                ]);
                header('Location: /maquinas');
                exit;
            } catch (PDOException $e) {
                $db_error = 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    }

} catch (PDOException $e) {
    $db_error = 'Erro no banco de dados: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($titulo_pagina) ?> | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
  <link rel="icon" href="/public/css/icone.ico" type="image/png">
  <style>
  .content { flex: 1; overflow: hidden; display: flex; flex-direction: column; padding: 20px 24px; }

  .cad-alert { border-radius: 10px; padding: 11px 16px; font-size: 12.5px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .cad-alert-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #ef4444; }

  .cad-card { flex: 1; min-height: 0; background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; box-shadow: 0 4px 28px rgba(0,0,0,.22); display: flex; flex-direction: column; }

  .cad-head { padding: 18px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 14px; background: linear-gradient(120deg, rgba(30,79,201,.1) 0%, transparent 70%); border-top: 3px solid var(--blue-accent); flex-shrink: 0; }
  .cad-icon { width: 40px; height: 40px; border-radius: 10px; background: rgba(30,79,201,.15); border: 1px solid rgba(30,79,201,.25); color: #7db3ff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .cad-head-info { flex: 1; }
  .cad-head-info h2 { font-size: 15px; font-weight: 700; }
  .cad-head-info p  { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
  .badge-edit { font-size: 10.5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: rgba(245,158,11,.12); color: var(--amber); display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }

  .cad-body { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }
  .section-label { font-size: 10px; font-weight: 800; letter-spacing: 1.2px; color: var(--text-muted); text-transform: uppercase; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
  .cad-section { display: flex; flex-direction: column; gap: 14px; }

  .f-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .f-row.t3 { grid-template-columns: repeat(3, 1fr); }
  .f-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; line-height: 1.5; }

  .field select { text-transform: uppercase; }
  .field select option { text-transform: uppercase; background: var(--blue-mid); }

  .check-widget { display: flex; align-items: flex-start; gap: 10px; background: rgba(255,255,255,.025); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; cursor: pointer; }
  .check-widget input[type=checkbox] { width: 16px; height: 16px; min-width: 16px; margin-top: 2px; accent-color: var(--blue-accent); cursor: pointer; background: transparent; border: none; padding: 0; }
  .check-widget-info { display: flex; flex-direction: column; gap: 3px; }
  .check-widget-label { font-size: 13px; font-weight: 500; }
  .check-widget-hint { font-size: 11px; color: var(--text-muted); line-height: 1.5; margin-top: 2px; }

  .calc-box { background: rgba(0,201,167,.06); border: 1px solid rgba(0,201,167,.18); border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .calc-box-label { font-size: 10.5px; font-weight: 700; color: var(--teal); letter-spacing: .5px; text-transform: uppercase; }
  .calc-box-sub   { font-size: 11px; color: rgba(0,201,167,.55); margin-top: 2px; }
  .calc-box-val   { font-size: 26px; font-weight: 800; color: var(--teal); letter-spacing: -1px; line-height: 1; }
  .calc-box-unit  { font-size: 11px; color: rgba(0,201,167,.55); text-align: right; margin-top: 3px; }

  .cad-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 8px; background: rgba(0,0,0,.08); flex-shrink: 0; }
  .cad-footer .btn-primary { flex: 1; justify-content: center; font-size: 13.5px; padding: 10px 20px; }

  @media (max-width: 640px) {
    .content { padding: 12px; }
    .cad-head, .cad-body, .cad-footer { padding-left: 16px; padding-right: 16px; }
    .cad-body { padding-top: 16px; padding-bottom: 16px; }
    .cad-footer { flex-direction: column-reverse; }
    .cad-footer .btn-primary { flex: none; }
    .f-row, .f-row.t3 { grid-template-columns: 1fr; }
    .cad-icon { width: 34px; height: 34px; }
    .calc-box-val { font-size: 22px; }
  }
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">

    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1><?= htmlspecialchars($titulo_pagina) ?></h1>
          <p><?= $editando ? 'Altere os dados e salve para atualizar o registro.' : 'Preencha os campos e salve para cadastrar uma nova máquina.' ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/maquinas" class="btn-secondary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Ver Lista
        </a>
      </div>
    </header>

    <div class="content">
      <div class="cad-card">

        <div class="cad-head">
          <div class="cad-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M11 10.27 7 3.34"/><path d="m11 13.73-4 6.93"/><path d="M12 22v-2"/><path d="M12 2v2"/>
              <path d="M14 12h8"/><path d="m17 20.66-1-1.73"/><path d="m17 3.34-1 1.73"/>
              <path d="M2 12h2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="12" r="8"/>
            </svg>
          </div>
          <div class="cad-head-info">
            <h2><?= $editando ? 'Editar Máquina' : 'Nova Máquina' ?></h2>
            <p><?= $editando ? htmlspecialchars($f['maq_descricao']) : 'Configure capacidade e parâmetros de produção' ?></p>
          </div>
          <?php if ($editando): ?>
          <span class="badge-edit">
            <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Editando
          </span>
          <?php endif; ?>
        </div>

        <form method="POST">
          <input type="hidden" name="acao"       value="salvar">
          <input type="hidden" name="maq_codigo" value="<?= (int) $f['maq_codigo'] ?>">

          <div class="cad-body">

            <?php if ($db_error): ?>
            <div class="cad-alert cad-alert-err" id="alertEl">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <?= htmlspecialchars($db_error) ?>
            </div>
            <?php endif; ?>

            <!-- Identificação -->
            <div class="cad-section">
              <div class="section-label">Identificação</div>

            <div class="field">
              <label>Descrição *</label>
              <input type="text" name="maq_descricao"
                     value="<?= htmlspecialchars($f['maq_descricao']) ?>"
                     placeholder="Ex: Corte Bag 01, Impressão Sacaria 02…"
                     maxlength="120" required autofocus>
            </div>

            <div class="f-row">
              <div class="field">
                <label>Departamento *</label>
                <select name="dp_codigo" required>
                  <option value="">— Selecione —</option>
                  <?php foreach ($deptos as $d): ?>
                  <option value="<?= $d['dp_codigo'] ?>"
                    <?= (int) $f['dp_codigo'] === (int) $d['dp_codigo'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['dp_descricao']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Grupo PCP</label>
                <input type="text" name="maq_grupo"
                       value="<?= htmlspecialchars($f['maq_grupo'] ?? '') ?>"
                       placeholder="Ex: Corte Bag, Costura…"
                       maxlength="80">
                <span class="f-hint">Agrupa máquinas semelhantes no planejamento.</span>
              </div>
            </div>
          </div>

          <!-- Capacidade -->
          <div class="cad-section">
            <div class="section-label">Capacidade de Produção</div>

            <div class="f-row t3">
              <div class="field">
                <label>Qtde de Máquinas</label>
                <input type="number" name="maq_qtde" id="f_qtde"
                       value="<?= htmlspecialchars($f['maq_qtde']) ?>"
                       min="0" step="1" oninput="calcCap()">
                <span class="f-hint">Unidades físicas</span>
              </div>
              <div class="field">
                <label>Horas / Dia</label>
                <input type="number" name="maq_horas_dia" id="f_horas"
                       value="<?= htmlspecialchars($f['maq_horas_dia']) ?>"
                       min="0" step="0.5" max="24" oninput="calcCap()">
                <span class="f-hint">Horas trabalhadas</span>
              </div>
              <div class="field">
                <label>Produção (un/min)</label>
                <input type="number" name="maq_producao_min" id="f_prod"
                       value="<?= htmlspecialchars($f['maq_producao_min']) ?>"
                       min="0" step="0.0001" oninput="calcCap()">
                <span class="f-hint">Velocidade por minuto</span>
              </div>
            </div>

            <div class="calc-box">
              <div>
                <div class="calc-box-label">Capacidade Diária</div>
                <div class="calc-box-sub">Calculada automaticamente</div>
              </div>
              <div style="text-align:right;">
                <div class="calc-box-val" id="capDiaria">0</div>
                <div class="calc-box-unit">un / dia</div>
              </div>
            </div>
          </div>

            <!-- Configurações PCP -->
            <div class="cad-section">
              <div class="section-label">Configurações PCP</div>
              <label class="check-widget">
                <input type="checkbox" name="maq_conta_producao" value="1"
                       <?= $f['maq_conta_producao'] ? 'checked' : '' ?>>
                <div class="check-widget-info">
                  <span class="check-widget-label">Contabilizar no total de produção</span>
                  <span class="check-widget-hint">Desmarque para processos intermediários (ex: carimbadeira) que não devem duplicar a contagem de unidades.</span>
                </div>
              </label>
            </div>

          </div><!-- /cad-body -->

          <div class="cad-footer">
            <a href="/maquinas" class="btn-secondary">Cancelar</a>
            <button type="submit" class="btn-primary">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              <?= $editando ? 'Salvar Alterações' : 'Salvar Máquina' ?>
            </button>
          </div>
        </form>
      </div><!-- /cad-card -->
    </div>
  </div>
</div>

<script>
function calcCap() {
  const qtde  = parseFloat(document.getElementById('f_qtde').value)  || 0;
  const prod  = parseFloat(document.getElementById('f_prod').value)  || 0;
  const horas = parseFloat(document.getElementById('f_horas').value) || 0;
  const cap   = qtde * prod * 60 * horas;
  document.getElementById('capDiaria').textContent =
    cap > 0 ? cap.toLocaleString('pt-BR', { maximumFractionDigits: 0 }) : '0';
}
calcCap();

const alertEl = document.getElementById('alertEl');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.remove(), 400);
}, 6000);
</script>
</body>
</html>
