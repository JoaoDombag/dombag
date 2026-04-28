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

        // Repopula o formulário em caso de erro de validação
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
            // UPDATE
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
            // INSERT
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
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--blue-deep); color: var(--text-primary); overflow: hidden; height: 100vh; }
    .app-wrapper { display: flex; height: 100vh; overflow: hidden; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

    /* Topbar */
    .topbar { padding: 14px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--blue-mid); flex-shrink: 0; gap: 12px; }
    .topbar-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .page-title h1 { font-size: 17px; font-weight: 600; letter-spacing: -.2px; }
    .page-title p  { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
    .topbar-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

    /* Botões */
    .btn-primary { background: var(--blue-accent); color: #fff; border: none; padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; font-family: 'Segoe UI', sans-serif; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background .15s; }
    .btn-primary:hover { background: var(--blue-light); }
    .btn-secondary { background: transparent; border: 1px solid var(--border); color: var(--text-muted); padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 500; font-family: 'Segoe UI', sans-serif; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
    .btn-secondary:hover { background: var(--card-hover); color: var(--text-primary); }

    /* Content */
    .content { flex: 1; overflow: auto; padding: 32px 24px; display: flex; flex-direction: column; align-items: center; }

    /* Alertas */
    .alert { border-radius: 8px; padding: 10px 16px; font-size: 12.5px; display: flex; align-items: center; gap: 8px; margin-bottom: 18px; width: 100%; max-width: 560px; }
    .alert-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: var(--red); }

    /* Form panel */
    .form-panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); width: 100%; max-width: 560px; overflow: hidden; }
    .form-panel-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .form-panel-head h2 { font-size: 14px; font-weight: 600; }
    .badge-edit { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 20px; background: rgba(245,158,11,.12); color: var(--amber); display: inline-flex; align-items: center; gap: 4px; }

    /* Campos */
    .form-body { padding: 20px; display: flex; flex-direction: column; gap: 16px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field label { font-size: 11px; font-weight: 700; letter-spacing: .6px; color: var(--text-muted); text-transform: uppercase; }
    .field input, .field select { background: rgba(255,255,255,.04); border: 1px solid var(--border); border-radius: 8px; padding: 9px 13px; font-size: 13.5px; font-family: 'Segoe UI', sans-serif; color: var(--text-primary); outline: none; transition: border-color .15s, background .15s; width: 100%; }
    .field input:focus, .field select:focus { border-color: rgba(45,106,255,.5); background: rgba(45,106,255,.05); }
    .field input::placeholder { color: var(--text-muted); }
    .field select { text-transform: uppercase; }
    .field select option { background: #112240; color: var(--text-primary); text-transform: uppercase; }
    .field-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    /* Calc box */
    .calc-box { background: rgba(0,201,167,.07); border: 1px solid rgba(0,201,167,.2); border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; }
    .calc-box-label { font-size: 11px; color: var(--teal); font-weight: 600; letter-spacing: .4px; text-transform: uppercase; }
    .calc-box-val  { font-size: 20px; font-weight: 700; color: var(--teal); letter-spacing: -.5px; }
    .calc-box-unit { font-size: 11px; color: rgba(0,201,167,.6); margin-top: 1px; }

    /* Form actions */
    .form-actions { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; }
    .form-actions .btn-primary { flex: 1; justify-content: center; }

    @media (max-width: 620px) {
      .content { padding: 20px 12px; }
      .field-row { grid-template-columns: 1fr; }
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

      <?php if ($db_error): ?>
      <div class="alert alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($db_error) ?>
      </div>
      <?php endif; ?>

      <div class="form-panel">
        <div class="form-panel-head">
          <h2><?= $editando ? 'Editar Máquina' : 'Nova Máquina' ?></h2>
          <?php if ($editando): ?>
          <span class="badge-edit">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Editando
          </span>
          <?php endif; ?>
        </div>

        <form method="POST">
          <input type="hidden" name="acao"       value="salvar">
          <input type="hidden" name="maq_codigo" value="<?= (int) $f['maq_codigo'] ?>">

          <div class="form-body">

            <div class="field">
              <label>Descrição *</label>
              <input type="text" name="maq_descricao"
                     value="<?= htmlspecialchars($f['maq_descricao']) ?>"
                     placeholder="Ex: Corte Bag 01, Impressão Sacaria 02…"
                     maxlength="120" required autofocus>
            </div>

            <div class="field">
              <label>Grupo PCP</label>
              <input type="text" name="maq_grupo"
                     value="<?= htmlspecialchars($f['maq_grupo'] ?? '') ?>"
                     placeholder="Ex: Corte Bag, Costura… (deixe em branco para usar a descrição)"
                     maxlength="80">
              <span class="field-hint">Agrupa máquinas semelhantes no planejamento PCP</span>
            </div>

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

            <div class="field-row">
              <div class="field">
                <label>Qtde de Máquinas</label>
                <input type="number" name="maq_qtde" id="f_qtde"
                       value="<?= htmlspecialchars($f['maq_qtde']) ?>"
                       min="0" step="1" oninput="calcCap()">
                <span class="field-hint">Unidades físicas</span>
              </div>
              <div class="field">
                <label>Horas / Dia</label>
                <input type="number" name="maq_horas_dia" id="f_horas"
                       value="<?= htmlspecialchars($f['maq_horas_dia']) ?>"
                       min="0" step="0.5" max="24" oninput="calcCap()">
                <span class="field-hint">Horas trabalhadas</span>
              </div>
            </div>

            <div class="field">
              <label>Produção (un/min)</label>
              <input type="number" name="maq_producao_min" id="f_prod"
                     value="<?= htmlspecialchars($f['maq_producao_min']) ?>"
                     min="0" step="0.0001" oninput="calcCap()">
              <span class="field-hint">Velocidade em unidades por minuto</span>
            </div>

            <div class="field">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;font-weight:500;color:var(--text-primary);">
                <input type="checkbox" name="maq_conta_producao" value="1"
                       <?= $f['maq_conta_producao'] ? 'checked' : '' ?>
                       style="width:16px;height:16px;accent-color:var(--blue-accent);cursor:pointer;flex-shrink:0;">
                Contabilizar no total de produção
              </label>
              <span class="field-hint">Desmarque para processos intermediários (ex: carimbadeira) que não devem duplicar a contagem de unidades.</span>
            </div>

            <div class="calc-box">
              <div>
                <div class="calc-box-label">Capacidade Diária</div>
                <div class="calc-box-unit">Calculada automaticamente</div>
              </div>
              <div style="text-align:right;">
                <div class="calc-box-val" id="capDiaria">0</div>
                <div class="calc-box-unit">un / dia</div>
              </div>
            </div>

          </div>

          <div class="form-actions">
            <button type="submit" class="btn-primary">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              <?= $editando ? 'Salvar Alterações' : 'Salvar Máquina' ?>
            </button>
            <a href="/maquinas" class="btn-secondary">Cancelar</a>
          </div>
        </form>
      </div>

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
</script>
</body>
</html>
