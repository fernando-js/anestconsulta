<?php
// ══════════════════════════════════════════════
// AnestConsulta — Painel Administrativo
// ══════════════════════════════════════════════

session_start();
require_once __DIR__ . '/config.php';

// ── Autenticação ──────────────────────────────
if (isset($_POST['login'])) {
    $db    = getDB();
    $stmt  = $db->prepare('SELECT * FROM admin_usuarios WHERE email = ? AND ativo = 1');
    $stmt->execute([trim($_POST['email'])]);
    $user  = $stmt->fetch();
    if ($user && password_verify($_POST['senha'], $user['senha_hash'])) {
        $_SESSION['admin_id']   = $user['id'];
        $_SESSION['admin_nome'] = $user['nome'];
        $db->prepare('UPDATE admin_usuarios SET ultimo_login = NOW() WHERE id = ?')
           ->execute([$user['id']]);
        header('Location: admin.php');
        exit;
    }
    $erro_login = 'E-mail ou senha incorretos.';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Redireciona se não logado
if (empty($_SESSION['admin_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin — AnestConsulta</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:'Sora',sans-serif;background:linear-gradient(135deg,#003d3b,#001a3a);min-height:100vh;display:flex;align-items:center;justify-content:center}
      .login-card{background:white;border-radius:20px;padding:48px 40px;width:100%;max-width:420px;box-shadow:0 32px 80px rgba(0,0,0,.3)}
      .login-logo{text-align:center;margin-bottom:32px}
      .login-logo-icon{width:56px;height:56px;background:linear-gradient(135deg,#008c87,#0050b3);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:26px;color:white;margin-bottom:12px}
      .login-logo h1{font-size:1.3rem;color:#0a2322;font-weight:700}
      .login-logo p{font-size:.8rem;color:#4a7f7e;margin-top:4px}
      label{display:block;font-size:.75rem;font-weight:600;color:#1e4e4d;margin-bottom:6px;letter-spacing:.03em}
      input{width:100%;padding:12px 14px;border:1.5px solid #d0e1e0;border-radius:8px;font-family:'Sora',sans-serif;font-size:.85rem;color:#0a2322;outline:none;transition:border-color .2s}
      input:focus{border-color:#008c87;box-shadow:0 0 0 3px rgba(0,140,135,.1)}
      .form-group{margin-bottom:18px}
      .btn{width:100%;padding:13px;background:linear-gradient(135deg,#008c87,#0050b3);color:white;border:none;border-radius:10px;font-family:'Sora',sans-serif;font-size:.9rem;font-weight:600;cursor:pointer;margin-top:8px;transition:opacity .2s}
      .btn:hover{opacity:.9}
      .erro{background:#fef2f2;color:#b91c1c;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:16px;border:1px solid #fee2e2}
    </style>
    </head>
    <body>
    <div class="login-card">
      <div class="login-logo">
        <div class="login-logo-icon">✚</div>
        <h1>AnestConsulta</h1>
        <p>Painel Administrativo</p>
      </div>
      <?php if (!empty($erro_login)): ?>
        <div class="erro"><?= htmlspecialchars($erro_login) ?></div>
      <?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <label>E-mail</label>
          <input type="email" name="email" placeholder="consulta@anestconsulta.com" required>
        </div>
        <div class="form-group">
          <label>Senha</label>
          <input type="password" name="senha" placeholder="••••••••" required>
        </div>
        <button type="submit" name="login" class="btn">Entrar no painel</button>
      </form>
    </div>
    </body></html>
    <?php
    exit;
}

// ══ PAINEL ADMIN (logado) ══════════════════════

$db = getDB();

// Filtros
$status_filtro = $_GET['status'] ?? 'todos';
$busca         = trim($_GET['busca'] ?? '');
$pagina        = max(1, (int)($_GET['p'] ?? 1));
$por_pagina    = 15;
$offset        = ($pagina - 1) * $por_pagina;

// Ação: atualizar status
if (isset($_POST['atualizar_status'])) {
    $stmt = $db->prepare('UPDATE agendamentos SET status = ? WHERE id = ?');
    $stmt->execute([$_POST['novo_status'], (int)$_POST['ag_id']]);
    header('Location: admin.php?status=' . $status_filtro);
    exit;
}

// Construir query
$where  = ['1=1'];
$params = [];

if ($status_filtro !== 'todos') {
    $where[]  = 'a.status = ?';
    $params[] = $status_filtro;
}
if ($busca) {
    $where[]  = '(a.nome LIKE ? OR a.cpf LIKE ? OR a.email LIKE ?)';
    $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%";
}

$whereStr = implode(' AND ', $where);

// Total
$stmtTotal = $db->prepare("SELECT COUNT(*) FROM agendamentos a WHERE $whereStr");
$stmtTotal->execute($params);
$total = $stmtTotal->fetchColumn();
$paginas_total = ceil($total / $por_pagina);

// Buscar agendamentos
$stmtAg = $db->prepare(
    "SELECT a.*, m.nome AS medico_nome, m.especialidade
     FROM agendamentos a
     JOIN medicos m ON a.medico_id = m.id
     WHERE $whereStr
     ORDER BY a.criado_em DESC
     LIMIT $por_pagina OFFSET $offset"
);
$stmtAg->execute($params);
$agendamentos = $stmtAg->fetchAll();

// Estatísticas
$stats = $db->query(
    "SELECT
       COUNT(*) AS total,
       SUM(status='pendente') AS pendentes,
       SUM(status='confirmado') AS confirmados,
       SUM(status='realizado') AS realizados,
       SUM(status='cancelado') AS cancelados,
       SUM(DATE(data_consulta) = CURDATE()) AS hoje
     FROM agendamentos"
)->fetch();

$statusLabels  = ['pendente'=>'Pendente','confirmado'=>'Confirmado','cancelado'=>'Cancelado','realizado'=>'Realizado'];
$statusColors  = ['pendente'=>'#f59e0b','confirmado'=>'#008c87','cancelado'=>'#ef4444','realizado'=>'#229b61'];
$statusBg      = ['pendente'=>'#fff8e1','confirmado'=>'#e6faf9','cancelado'=>'#fef2f2','realizado'=>'#f0fdf4'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Painel Admin — AnestConsulta</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Sora',sans-serif;background:#f4fbfb;color:#0a2322;min-height:100vh}

  /* Sidebar */
  .sidebar{position:fixed;top:0;left:0;bottom:0;width:240px;background:linear-gradient(180deg,#003d3b,#001a3a);padding:28px 0;display:flex;flex-direction:column}
  .sidebar-logo{padding:0 24px 28px;border-bottom:1px solid rgba(255,255,255,.08)}
  .sidebar-logo h2{color:white;font-size:1.1rem;font-weight:700}
  .sidebar-logo p{color:rgba(255,255,255,.4);font-size:.7rem;margin-top:2px}
  .sidebar-nav{flex:1;padding:20px 12px}
  .sidebar-nav a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;color:rgba(255,255,255,.6);text-decoration:none;font-size:.82rem;font-weight:500;margin-bottom:4px;transition:all .2s}
  .sidebar-nav a:hover,.sidebar-nav a.active{background:rgba(0,196,186,.12);color:white}
  .sidebar-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.08)}
  .sidebar-user{font-size:.75rem;color:rgba(255,255,255,.5);margin-bottom:8px}
  .sidebar-user strong{color:white;display:block}
  .logout-btn{font-size:.72rem;color:var(--teal-300,#33d0c7);text-decoration:none;opacity:.7}
  .logout-btn:hover{opacity:1}

  /* Main */
  .main{margin-left:240px;padding:32px}

  /* Header */
  .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
  .page-title{font-size:1.3rem;font-weight:700;color:#0a2322}
  .page-subtitle{font-size:.8rem;color:#4a7f7e;margin-top:2px}

  /* Stats cards */
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px}
  .stat-card{background:white;border-radius:14px;padding:20px;border:1px solid #e8f0ef;transition:box-shadow .2s}
  .stat-card:hover{box-shadow:0 6px 24px rgba(0,60,56,.1)}
  .stat-num{font-size:1.8rem;font-weight:700;color:#0a2322;line-height:1}
  .stat-label{font-size:.72rem;color:#4a7f7e;margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
  .stat-card.hoje .stat-num{color:#008c87}

  /* Filtros */
  .filters{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
  .filter-tabs{display:flex;gap:6px}
  .filter-tab{padding:7px 14px;border-radius:50px;font-size:.75rem;font-weight:600;cursor:pointer;text-decoration:none;border:1.5px solid #d0e1e0;color:#4a7f7e;transition:all .2s}
  .filter-tab:hover,.filter-tab.active{background:#008c87;border-color:#008c87;color:white}
  .search-input{padding:8px 14px;border:1.5px solid #d0e1e0;border-radius:50px;font-size:.78rem;font-family:'Sora',sans-serif;color:#0a2322;outline:none;width:220px}
  .search-input:focus{border-color:#008c87}

  /* Tabela */
  .table-card{background:white;border-radius:16px;border:1px solid #e8f0ef;overflow:hidden}
  .table-header{padding:18px 24px;border-bottom:1px solid #e8f0ef;display:flex;align-items:center;justify-content:space-between}
  .table-title{font-size:.85rem;font-weight:700;color:#0a2322}
  .table-count{font-size:.75rem;color:#4a7f7e}
  table{width:100%;border-collapse:collapse}
  th{padding:11px 16px;font-size:.7rem;font-weight:700;color:#4a7f7e;text-transform:uppercase;letter-spacing:.06em;text-align:left;background:#f4fbfb;border-bottom:1px solid #e8f0ef}
  td{padding:13px 16px;font-size:.8rem;color:#0a2322;border-bottom:1px solid #f0f5f5;vertical-align:middle}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:#f9fdfd}

  /* Status badge */
  .status-badge{display:inline-block;padding:4px 10px;border-radius:50px;font-size:.67rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}

  /* Select status */
  .status-select{padding:5px 8px;border-radius:6px;border:1px solid #d0e1e0;font-size:.72rem;font-family:'Sora',sans-serif;cursor:pointer;background:white;color:#0a2322}

  /* Tipo badge */
  .tipo-badge{font-size:.7rem;padding:3px 8px;border-radius:4px;background:#f4fbfb;color:#008c87;font-weight:600}

  /* Paginação */
  .pagination{display:flex;align-items:center;justify-content:center;gap:8px;padding:20px}
  .pag-btn{padding:7px 13px;border-radius:8px;border:1.5px solid #d0e1e0;color:#4a7f7e;text-decoration:none;font-size:.78rem;font-weight:500;transition:all .2s}
  .pag-btn:hover,.pag-btn.active{background:#008c87;border-color:#008c87;color:white}
  .pag-btn.disabled{opacity:.4;pointer-events:none}

  /* Responsive */
  @media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.stats-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-logo">
    <h2>✚ AnestConsulta</h2>
    <p>Painel Administrativo</p>
  </div>
  <nav class="sidebar-nav">
    <a href="admin.php" class="active">📋 Agendamentos</a>
    <a href="admin.php?view=medicos">👨‍⚕️ Médicos</a>
    <a href="index.html" target="_blank">🌐 Ver site</a>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      Logado como<strong><?= htmlspecialchars($_SESSION['admin_nome']) ?></strong>
    </div>
    <a href="admin.php?logout=1" class="logout-btn">→ Sair</a>
  </div>
</div>

<div class="main">
  <div class="page-header">
    <div>
      <div class="page-title">Agendamentos</div>
      <div class="page-subtitle">Gerencie todas as consultas pré-anestésicas</div>
    </div>
  </div>

  <!-- Estatísticas -->
  <div class="stats-grid">
    <div class="stat-card hoje">
      <div class="stat-num"><?= $stats['hoje'] ?></div>
      <div class="stat-label">Hoje</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#f59e0b"><?= $stats['pendentes'] ?></div>
      <div class="stat-label">Pendentes</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#008c87"><?= $stats['confirmados'] ?></div>
      <div class="stat-label">Confirmados</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#229b61"><?= $stats['realizados'] ?></div>
      <div class="stat-label">Realizados</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $stats['total'] ?></div>
      <div class="stat-label">Total</div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" style="margin-bottom:0">
    <div class="filters">
      <div class="filter-tabs">
        <?php foreach (['todos'=>'Todos','pendente'=>'Pendentes','confirmado'=>'Confirmados','realizado'=>'Realizados','cancelado'=>'Cancelados'] as $val=>$label): ?>
          <a href="admin.php?status=<?= $val ?>&busca=<?= urlencode($busca) ?>"
             class="filter-tab <?= $status_filtro===$val?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
      <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>"
             placeholder="🔍 Buscar nome, CPF ou e-mail..." class="search-input"
             onchange="this.form.submit()">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status_filtro) ?>">
    </div>
  </form>

  <!-- Tabela -->
  <div class="table-card">
    <div class="table-header">
      <span class="table-title">Lista de Agendamentos</span>
      <span class="table-count"><?= $total ?> registro(s)</span>
    </div>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Paciente</th>
          <th>Médico</th>
          <th>Data / Hora</th>
          <th>Tipo</th>
          <th>Procedimento</th>
          <th>Status</th>
          <th>Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($agendamentos)): ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;color:#4a7f7e">Nenhum agendamento encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($agendamentos as $ag):
          $cor  = $statusColors[$ag['status']] ?? '#888';
          $bgc  = $statusBg[$ag['status']] ?? '#f5f5f5';
          $data = DateTime::createFromFormat('Y-m-d', $ag['data_consulta']);
          $hor  = substr($ag['horario'], 0, 5);
        ?>
        <tr>
          <td style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:#4a7f7e">#<?= $ag['id'] ?></td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($ag['nome']) ?></div>
            <div style="font-size:.7rem;color:#4a7f7e"><?= htmlspecialchars($ag['email']) ?></div>
          </td>
          <td>
            <div style="font-size:.78rem;font-weight:500"><?= htmlspecialchars($ag['medico_nome']) ?></div>
            <div style="font-size:.68rem;color:#4a7f7e"><?= htmlspecialchars($ag['especialidade']) ?></div>
          </td>
          <td>
            <div style="font-weight:600"><?= $data ? $data->format('d/m/Y') : '' ?></div>
            <div style="font-size:.72rem;color:#4a7f7e"><?= $hor ?>h</div>
          </td>
          <td><span class="tipo-badge"><?= $ag['tipo_consulta'] === 'online' ? '💻 Online' : '🏥 Presencial' ?></span></td>
          <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
              title="<?= htmlspecialchars($ag['cirurgia']) ?>">
            <?= htmlspecialchars(substr($ag['cirurgia'], 0, 30)) . (strlen($ag['cirurgia'])>30?'...':'') ?>
          </td>
          <td>
            <span class="status-badge" style="color:<?= $cor ?>;background:<?= $bgc ?>">
              <?= $statusLabels[$ag['status']] ?? $ag['status'] ?>
            </span>
          </td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="ag_id" value="<?= $ag['id'] ?>">
              <select name="novo_status" class="status-select" onchange="this.form.submit()">
                <?php foreach ($statusLabels as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= $ag['status']===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="atualizar_status" value="1">
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Paginação -->
    <?php if ($paginas_total > 1): ?>
    <div class="pagination">
      <a href="?p=<?= $pagina-1 ?>&status=<?= $status_filtro ?>&busca=<?= urlencode($busca) ?>"
         class="pag-btn <?= $pagina<=1?'disabled':'' ?>">← Anterior</a>
      <?php for ($i=1; $i<=$paginas_total; $i++): ?>
        <a href="?p=<?= $i ?>&status=<?= $status_filtro ?>&busca=<?= urlencode($busca) ?>"
           class="pag-btn <?= $i===$pagina?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <a href="?p=<?= $pagina+1 ?>&status=<?= $status_filtro ?>&busca=<?= urlencode($busca) ?>"
         class="pag-btn <?= $pagina>=$paginas_total?'disabled':'' ?>">Próxima →</a>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
