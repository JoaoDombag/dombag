<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════
//  DOMBAG — Cadastro de Máquinas
//  Schema: maquinas + FK → departamentos
// ══════════════════════════════════════════════════

$db_error = '';
$db_ok_msg = '';
$maquinas = [];
$deptos = [];

try {
    $pdo = dbPDO();

    // ── Garante tabelas ──────────────────────────
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS departamentos (
            dp_codigo    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dp_descricao VARCHAR(25) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    // Insere departamentos padrão se a tabela estiver vazia
    $pdo->exec("
        INSERT IGNORE INTO departamentos (dp_descricao) VALUES ('BAG'), ('SACARIA')
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maquinas (
            maq_codigo       INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
            maq_descricao    VARCHAR(120)   NOT NULL UNIQUE,
            maq_qtde         NUMERIC(6,2)   NOT NULL DEFAULT 1,
            maq_producao_min NUMERIC(10,4)  NOT NULL DEFAULT 0
                             COMMENT 'Unidades por minuto',
            maq_horas_dia    NUMERIC(5,2)   NOT NULL DEFAULT 8,
            dp_codigo        INT UNSIGNED   NOT NULL
                             COMMENT 'Departamento da máquina',
            maq_conta_producao TINYINT(1)   NOT NULL DEFAULT 1
                             COMMENT '1=conta no total; 0=processo intermediário',
            CONSTRAINT fk_maq_depto
                FOREIGN KEY (dp_codigo)
                REFERENCES departamentos (dp_codigo)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Garante coluna em tabelas já existentes (compatível com MySQL 5.7+)
    $col = $pdo->query("SHOW COLUMNS FROM maquinas LIKE 'maq_conta_producao'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE maquinas ADD COLUMN maq_conta_producao TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=conta no total; 0=processo intermediario'");
    }

    // ── Ações POST ───────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'salvar') {
            $desc = trim($_POST['maq_descricao'] ?? '');
            $qtde = (float) str_replace(',', '.', $_POST['maq_qtde'] ?? 1);
            $prod = (float) str_replace(',', '.', $_POST['maq_producao_min'] ?? 0);
            $horas = (float) str_replace(',', '.', $_POST['maq_horas_dia'] ?? 8);
            $depto = intval($_POST['dp_codigo'] ?? 0);
            $cod = intval($_POST['maq_codigo'] ?? 0);
            $conta = isset($_POST['maq_conta_producao']) ? 1 : 0;

            if ($desc === '') {
                $db_error = 'A descrição da máquina é obrigatória.';
            } elseif ($depto === 0) {
                $db_error = 'Selecione o departamento.';
            } elseif ($cod > 0) {
                // UPDATE
                $stmt = $pdo->prepare('
                    UPDATE maquinas SET
                        maq_descricao      = :desc,
                        maq_qtde           = :qtde,
                        maq_producao_min   = :prod,
                        maq_horas_dia      = :horas,
                        dp_codigo          = :depto,
                        maq_conta_producao = :conta
                    WHERE maq_codigo = :cod
                ');
                $stmt->execute([
                    ':desc' => $desc,  ':qtde' => $qtde,
                    ':prod' => $prod,  ':horas' => $horas,
                    ':depto' => $depto, ':cod' => $cod,
                    ':conta' => $conta,
                ]);
                $db_ok_msg = 'Máquina atualizada com sucesso.';
            } else {
                // INSERT
                $stmt = $pdo->prepare('
                    INSERT INTO maquinas
                        (maq_descricao, maq_qtde, maq_producao_min, maq_horas_dia, dp_codigo, maq_conta_producao)
                    VALUES (:desc, :qtde, :prod, :horas, :depto, :conta)
                ');
                $stmt->execute([
                    ':desc' => $desc,  ':qtde' => $qtde,
                    ':prod' => $prod,  ':horas' => $horas,
                    ':depto' => $depto, ':conta' => $conta,
                ]);
                $db_ok_msg = 'Máquina cadastrada com sucesso.';
            }
        }

        if ($acao === 'excluir') {
            $cod = intval($_POST['maq_codigo'] ?? 0);
            if ($cod > 0) {
                try {
                    $pdo->prepare('DELETE FROM maquinas WHERE maq_codigo = :cod')
                        ->execute([':cod' => $cod]);
                    $db_ok_msg = 'Máquina excluída.';
                } catch (PDOException $ex) {
                    // FK violation: máquina está em uso
                    $db_error = 'Não é possível excluir: esta máquina possui registros vinculados.';
                }
            }
        }
    }

    // ── Busca departamentos para o select ────────
    $deptos = $pdo->query(
        'SELECT dp_codigo, dp_descricao FROM departamentos ORDER BY dp_descricao'
    )->fetchAll(PDO::FETCH_ASSOC);

    // ── Busca máquinas com nome do departamento ──
    $maquinas = $pdo->query('
        SELECT m.*, d.dp_descricao
        FROM maquinas m
        INNER JOIN departamentos d ON d.dp_codigo = m.dp_codigo
        ORDER BY m.maq_codigo
    ')->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = 'Erro no banco de dados: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cadastro de Máquinas | DOMBAG</title>
  <style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.toggle-btn{width:36px;height:36px;min-width:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;}
.toggle-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.btn-primary{background:var(--blue-accent);color:white;border:none;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:background .15s;display:flex;align-items:center;gap:6px;}
.btn-primary:hover{background:var(--blue-light);}
.btn-secondary{background:transparent;border:1px solid var(--border);color:var(--text-muted);padding:8px 16px;border-radius:7px;font-size:13px;font-weight:500;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:6px;}
.btn-secondary:hover{background:var(--card-hover);color:var(--text-primary);}
.btn-danger{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:var(--red);padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:5px;}
.btn-danger:hover{background:rgba(239,68,68,.22);}
.content{flex:1;overflow-y:auto;padding:24px;}
.content::-webkit-scrollbar{width:4px;}
.content::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}
.alert{border-radius:8px;padding:10px 16px;font-size:12.5px;display:flex;align-items:center;gap:8px;margin-bottom:18px;}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}
.alert-ok{background:rgba(0,201,167,.1);border:1px solid rgba(0,201,167,.2);color:var(--teal);}
.page-grid{display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;}
@media(max-width:1000px){.page-grid{grid-template-columns:1fr;}}
.form-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;position:sticky;top:0;}
.form-panel-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.form-panel-head h2{font-size:14px;font-weight:600;}
.badge-edit{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(245,158,11,.12);color:var(--amber);display:none;}
.badge-edit.visible{display:inline-flex;align-items:center;gap:4px;}
.form-body{padding:20px;display:flex;flex-direction:column;gap:16px;}
.field{display:flex;flex-direction:column;gap:6px;}
.field label{font-size:11px;font-weight:700;letter-spacing:.6px;color:var(--text-muted);text-transform:uppercase;}
.field input,.field select{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:9px 13px;font-size:13.5px;font-family:'Segoe UI',sans-serif;color:var(--text-primary);outline:none;transition:border-color .15s,background .15s;width:100%;}
.field input:focus,.field select:focus{border-color:rgba(45,106,255,.5);background:rgba(45,106,255,.05);}
.field input::placeholder{color:var(--text-muted);}
.field select option{background:#112240;color:var(--text-primary);}
.field-hint{font-size:11px;color:var(--text-muted);margin-top:2px;}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.calc-box{background:rgba(0,201,167,.07);border:1px solid rgba(0,201,167,.2);border-radius:8px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;}
.calc-box-label{font-size:11px;color:var(--teal);font-weight:600;letter-spacing:.4px;text-transform:uppercase;}
.calc-box-val{font-size:20px;font-weight:700;color:var(--teal);letter-spacing:-.5px;}
.calc-box-unit{font-size:11px;color:rgba(0,201,167,.6);margin-top:1px;}
.form-actions{padding:16px 20px;border-top:1px solid var(--border);display:flex;gap:8px;}
.form-actions .btn-primary{flex:1;justify-content:center;}
.table-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.table-panel-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.table-panel-head h2{font-size:14px;font-weight:600;}
.count-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:rgba(45,106,255,.12);color:#7db3ff;}
.maq-table{width:100%;border-collapse:collapse;}
.maq-table th{padding:9px 16px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:rgba(0,0,0,.1);}
.maq-table td{padding:12px 16px;font-size:13px;border-bottom:1px solid var(--border);color:var(--text-primary);vertical-align:middle;}
.maq-table tr:last-child td{border-bottom:none;}
.maq-table tbody tr{transition:background .12s;}
.maq-table tbody tr:hover td{background:rgba(255,255,255,.025);}
.maq-table tbody tr.row-editing td{background:rgba(245,158,11,.06);}
.cod-badge{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:20px;padding:0 6px;border-radius:5px;background:rgba(45,106,255,.12);color:#7db3ff;font-size:11px;font-weight:700;}
.depto-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:rgba(167,139,250,.1);color:#a78bfa;}
.desc-main{font-weight:600;}
.num-mono{font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--text-muted);}
.cap-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:600;}
.cap-pill.ok{background:rgba(0,201,167,.1);color:var(--teal);}
.cap-pill.zero{background:rgba(239,68,68,.1);color:var(--red);}
.td-actions{display:flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-edit{background:rgba(45,106,255,.1);border:1px solid rgba(45,106,255,.2);color:#7db3ff;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:5px;}
.btn-edit:hover{background:rgba(45,106,255,.2);}
.empty-state{text-align:center;padding:56px 20px;color:var(--text-muted);}
.empty-state svg{display:block;margin:0 auto 12px;opacity:.2;}
.empty-state p{font-size:13px;}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-confirm{background:#152845;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:28px 28px 22px;width:380px;max-width:calc(100vw - 40px);transform:translateY(10px) scale(.98);transition:transform .2s;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-overlay.open .modal-confirm{transform:translateY(0) scale(1);}
.modal-confirm h3{font-size:15px;font-weight:600;margin-bottom:8px;}
.modal-confirm p{font-size:12.5px;color:var(--text-muted);line-height:1.5;}
.modal-confirm-actions{display:flex;gap:8px;margin-top:22px;justify-content:flex-end;}
@media(max-width:768px){.page-grid{grid-template-columns:1fr;}.orders-panel{overflow-x:auto;}.maq-table{min-width:500px;}}
@media(max-width:480px){.field-row{grid-template-columns:1fr;}.modal-confirm-actions{flex-wrap:wrap;}}
  </style>

<link rel="stylesheet" href="/public/css/unified_admin.css">
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">

    <header class="topbar">
      <div class="topbar-left">
<div class="page-title">
          <h1>Cadastro de Máquinas</h1>
          <p>Gerencie as máquinas e suas capacidades de produção</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-primary" onclick="novoRegistro()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nova Máquina
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($db_error): ?>
      <div class="alert alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($db_error) ?>
      </div>
      <?php endif; ?>

      <?php if ($db_ok_msg): ?>
      <div class="alert alert-ok" id="alertOk">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($db_ok_msg) ?>
      </div>
      <?php endif; ?>

      <div class="page-grid">

        <!-- ── Formulário ── -->
        <div class="form-panel" id="formPanel">
          <div class="form-panel-head">
            <h2 id="formTitle">Nova Máquina</h2>
            <span class="badge-edit" id="badgeEdit">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Editando
            </span>
          </div>

          <form method="POST" id="frmMaquina" onsubmit="return validarForm()">
            <input type="hidden" name="acao"       value="salvar">
            <input type="hidden" name="maq_codigo" id="f_codigo" value="0">

            <div class="form-body">

              <div class="field">
                <label>Descrição *</label>
                <input type="text" name="maq_descricao" id="f_descricao"
                       placeholder="Ex: Corte Bag, Impressão Sacaria…" maxlength="120" required>
              </div>

              <div class="field">
                <label>Departamento *</label>
                <select name="dp_codigo" id="f_depto" required>
                  <option value="">— Selecione —</option>
                  <?php foreach ($deptos as $d): ?>
                  <option value="<?= $d['dp_codigo'] ?>">
                    <?= htmlspecialchars($d['dp_descricao']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field-row">
                <div class="field">
                  <label>Qtde de Máquinas</label>
                  <input type="number" name="maq_qtde" id="f_qtde"
                         value="1" min="0" step="1" oninput="calcCap()">
                  <span class="field-hint">Unidades físicas</span>
                </div>
                <div class="field">
                  <label>Horas / Dia</label>
                  <input type="number" name="maq_horas_dia" id="f_horas"
                         value="8" min="0" step="0.5" max="24" oninput="calcCap()">
                  <span class="field-hint">Horas trabalhadas</span>
                </div>
              </div>

              <div class="field">
                <label>Produção (un/min)</label>
                <input type="number" name="maq_producao_min" id="f_prod"
                       value="0" min="0" step="0.0001" oninput="calcCap()">
                <span class="field-hint">Velocidade em unidades por minuto</span>
              </div>

              <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;font-weight:500;color:var(--text-primary);">
                  <input type="checkbox" name="maq_conta_producao" id="f_conta_producao" value="1" checked
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
                <span id="btnSalvarTxt">Salvar Máquina</span>
              </button>
              <button type="button" class="btn-secondary" id="btnCancelar"
                      onclick="novoRegistro()" style="display:none;">
                Cancelar
              </button>
            </div>
          </form>
        </div>

        <!-- ── Tabela ── -->
        <div class="table-panel">
          <div class="table-panel-head">
            <h2>Máquinas Cadastradas</h2>
            <span class="count-badge"><?= count($maquinas) ?> máquina<?= count($maquinas) !== 1 ? 's' : '' ?></span>
          </div>

          <?php if (empty($maquinas)): ?>
          <div class="empty-state">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
              <circle cx="12" cy="12" r="3"/>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            <p>Nenhuma máquina cadastrada.<br>Use o formulário ao lado para adicionar.</p>
          </div>
          <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="maq-table" id="tblMaquinas">
              <thead>
                <tr>
                  <th style="width:44px;">#</th>
                  <th>Descrição</th>
                  <th>Depto</th>
                  <th style="width:55px;text-align:center;">Qtde</th>
                  <th style="width:95px;text-align:right;">Prod./min</th>
                  <th style="width:85px;text-align:right;">Horas/dia</th>
                  <th style="width:120px;text-align:center;">Cap. Diária</th>
                  <th style="width:90px;text-align:center;">Conta total</th>
                  <th style="width:110px;"></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($maquinas as $m):
                  $cap = $m['maq_qtde'] * $m['maq_producao_min'] * 60 * $m['maq_horas_dia'];
                  ?>
                <tr id="row-<?= $m['maq_codigo'] ?>">
                  <td><span class="cod-badge"><?= $m['maq_codigo'] ?></span></td>
                  <td><span class="desc-main"><?= htmlspecialchars($m['maq_descricao']) ?></span></td>
                  <td><span class="depto-badge"><?= htmlspecialchars($m['dp_descricao']) ?></span></td>
                  <td style="text-align:center;" class="num-mono"><?= number_format($m['maq_qtde'], 0, ',', '.') ?></td>
                  <td style="text-align:right;"  class="num-mono"><?= number_format($m['maq_producao_min'], 4, ',', '.') ?></td>
                  <td style="text-align:right;"  class="num-mono"><?= number_format($m['maq_horas_dia'], 1, ',', '.') ?></td>
                  <td style="text-align:center;">
                    <span class="cap-pill <?= $cap > 0 ? 'ok' : 'zero' ?>">
                      <?= $cap > 0 ? number_format($cap, 0, ',', '.') . ' un' : 'Indefinida' ?>
                    </span>
                  </td>
                  <td style="text-align:center;">
                    <?php if ($m['maq_conta_producao']): ?>
                      <span class="cap-pill ok" title="Conta no total de produção">Sim</span>
                    <?php else: ?>
                      <span class="cap-pill zero" title="Processo intermediário — não duplica contagem">Não</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="td-actions">
                      <button class="btn-edit" onclick="editarMaquina(
                        <?= $m['maq_codigo'] ?>,
                        <?= htmlspecialchars(json_encode($m['maq_descricao'])) ?>,
                        <?= $m['maq_qtde'] ?>,
                        <?= $m['maq_producao_min'] ?>,
                        <?= $m['maq_horas_dia'] ?>,
                        <?= $m['dp_codigo'] ?>,
                        <?= (int) $m['maq_conta_producao'] ?>
                      )">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Editar
                      </button>
                      <button class="btn-danger" onclick="confirmarExclusao(
                        <?= $m['maq_codigo'] ?>,
                        <?= htmlspecialchars(json_encode($m['maq_descricao'])) ?>
                      )">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        Excluir
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Modal confirmação exclusão -->
<div class="modal-overlay" id="modalExclusao">
  <div class="modal-confirm">
    <h3>Confirmar exclusão</h3>
    <p id="modalExclusaoTexto">Tem certeza que deseja excluir esta máquina?</p>
    <form method="POST" id="frmExclusao">
      <input type="hidden" name="acao"       value="excluir">
      <input type="hidden" name="maq_codigo" id="excluir_codigo" value="">
      <div class="modal-confirm-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal()">Cancelar</button>
        <button type="submit" class="btn-danger">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Capacidade calculada em tempo real ──────────
function calcCap() {
  const qtde  = parseFloat(document.getElementById('f_qtde').value)  || 0;
  const prod  = parseFloat(document.getElementById('f_prod').value)  || 0;
  const horas = parseFloat(document.getElementById('f_horas').value) || 0;
  const cap   = qtde * prod * 60 * horas;
  document.getElementById('capDiaria').textContent =
    cap > 0 ? cap.toLocaleString('pt-BR', {maximumFractionDigits:0}) : '0';
}
calcCap();

// ── Limpa formulário ────────────────────────────
function novoRegistro() {
  document.getElementById('f_codigo').value    = '0';
  document.getElementById('f_descricao').value = '';
  document.getElementById('f_depto').value     = '';
  document.getElementById('f_qtde').value      = '1';
  document.getElementById('f_prod').value      = '0';
  document.getElementById('f_horas').value     = '8';
  document.getElementById('f_conta_producao').checked = true;
  document.getElementById('formTitle').textContent    = 'Nova Máquina';
  document.getElementById('btnSalvarTxt').textContent = 'Salvar Máquina';
  document.getElementById('badgeEdit').classList.remove('visible');
  document.getElementById('btnCancelar').style.display = 'none';
  document.querySelectorAll('.row-editing').forEach(r => r.classList.remove('row-editing'));
  calcCap();
  document.getElementById('f_descricao').focus();
}

// ── Preenche formulário para edição ─────────────
function editarMaquina(cod, desc, qtde, prod, horas, depto, contaProducao) {
  document.getElementById('f_codigo').value    = cod;
  document.getElementById('f_descricao').value = desc;
  document.getElementById('f_depto').value     = depto;
  document.getElementById('f_qtde').value      = qtde;
  document.getElementById('f_prod').value      = prod;
  document.getElementById('f_horas').value     = horas;
  document.getElementById('f_conta_producao').checked = (contaProducao == 1);
  document.getElementById('formTitle').textContent    = 'Editar Máquina';
  document.getElementById('btnSalvarTxt').textContent = 'Salvar Alterações';
  document.getElementById('badgeEdit').classList.add('visible');
  document.getElementById('btnCancelar').style.display = '';
  document.querySelectorAll('.row-editing').forEach(r => r.classList.remove('row-editing'));
  const row = document.getElementById('row-' + cod);
  if (row) row.classList.add('row-editing');
  calcCap();
  document.getElementById('formPanel').scrollIntoView({behavior:'smooth', block:'start'});
  document.getElementById('f_descricao').focus();
}

// ── Validação ────────────────────────────────────
function validarForm() {
  const desc  = document.getElementById('f_descricao').value.trim();
  const depto = document.getElementById('f_depto').value;
  if (!desc)  { document.getElementById('f_descricao').focus(); return false; }
  if (!depto) { document.getElementById('f_depto').focus();     return false; }
  return true;
}

// ── Modal exclusão ───────────────────────────────
function confirmarExclusao(cod, desc) {
  document.getElementById('excluir_codigo').value = cod;
  document.getElementById('modalExclusaoTexto').innerHTML =
    'Tem certeza que deseja excluir <strong>' + desc + '</strong>?<br>' +
    '<span style="font-size:11.5px;color:var(--red);">Não será possível excluir se houver registros vinculados.</span>';
  document.getElementById('modalExclusao').classList.add('open');
}
function fecharModal() {
  document.getElementById('modalExclusao').classList.remove('open');
}
document.getElementById('modalExclusao').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') fecharModal();
});

// ── Auto-dismiss alerta de sucesso ───────────────
const alertOk = document.getElementById('alertOk');
if (alertOk) {
  setTimeout(() => {
    alertOk.style.transition = 'opacity .5s';
    alertOk.style.opacity    = '0';
    setTimeout(() => alertOk.style.display = 'none', 500);
  }, 4000);
}
</script>
</body>
</html>