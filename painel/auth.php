<?php
// ══════════════════════════════════════════════════════
// AnestConsulta — API Auth Paciente
// POST /painel/auth.php?action=registro|login|logout|reset
// ══════════════════════════════════════════════════════
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/../php/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

match($action) {
    'registro' => actionRegistro($input),
    'login'    => actionLogin($input),
    'logout'   => actionLogout($input),
    'reset_solicitar' => actionResetSolicitar($input),
    'reset_confirmar' => actionResetConfirmar($input),
    'verificar_email' => actionVerificarEmail($input),
    default    => jsonError(400, 'INVALID_ACTION', 'Ação inválida.')
};

// ══════════════════════════════════════════════════════
// REGISTRO
// ══════════════════════════════════════════════════════
function actionRegistro(array $d): never
{
    $nome  = mb_substr(trim(strip_tags($d['nome']  ?? '')), 0, 120);
    $email = trim(strtolower($d['email'] ?? ''));
    $senha = $d['senha'] ?? '';

    $erros = [];
    if (mb_strlen($nome) < 2)
        $erros[] = ['campo' => 'nome',  'msg' => 'Nome muito curto.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $erros[] = ['campo' => 'email', 'msg' => 'E-mail inválido.'];
    if (strlen($senha) < 8)
        $erros[] = ['campo' => 'senha', 'msg' => 'Senha deve ter ao menos 8 caracteres.'];
    if (!preg_match('/[A-Z]/', $senha) || !preg_match('/[0-9]/', $senha))
        $erros[] = ['campo' => 'senha', 'msg' => 'Senha deve conter letra maiúscula e número.'];

    if (!empty($erros)) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error'  => ['code' => 'VALIDATION_ERROR', 'message' => 'Dados inválidos.'],
            'campos' => $erros], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = getDB();

    // Verificar e-mail duplicado
    $stmt = $db->prepare('SELECT id FROM pacientes WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonError(409, 'EMAIL_EXISTE', 'E-mail já cadastrado.');
    }

    // Gerar iniciais para avatar
    $partes  = explode(' ', $nome);
    $inicial = strtoupper(substr($partes[0], 0, 1) . (substr($partes[1] ?? $partes[0], 0, 1)));

    $senhaHash       = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
    $tokenVerificacao = bin2hex(random_bytes(32));

    $db->prepare(
        'INSERT INTO pacientes (nome, email, senha_hash, avatar_inicial,
         token_verificacao, email_verificado)
         VALUES (?, ?, ?, ?, ?, 0)'
    )->execute([$nome, $email, $senhaHash, $inicial, $tokenVerificacao]);

    $pacienteId = (int)$db->lastInsertId();

    // Enviar e-mail de verificação
    enviarEmailVerificacao([
        'nome'   => $nome,
        'email'  => $email,
        'token'  => $tokenVerificacao,
    ]);

    jsonSuccess([
        'id'      => $pacienteId,
        'message' => 'Cadastro realizado! Verifique seu e-mail para ativar a conta.',
    ], 201);
}

// ══════════════════════════════════════════════════════
// LOGIN
// ══════════════════════════════════════════════════════
function actionLogin(array $d): never
{
    $email = trim(strtolower($d['email'] ?? ''));
    $senha = $d['senha'] ?? '';

    if (!$email || !$senha) {
        jsonError(400, 'CAMPOS_OBRIGATORIOS', 'E-mail e senha obrigatórios.');
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, nome, email, senha_hash, email_verificado,
                avatar_inicial, telefone, cpf, data_nascimento, plano_saude, ativo
         FROM pacientes WHERE email = ?'
    );
    $stmt->execute([$email]);
    $paciente = $stmt->fetch();

    if (!$paciente || !password_verify($senha, $paciente['senha_hash'])) {
        jsonError(401, 'CREDENCIAIS_INVALIDAS', 'E-mail ou senha incorretos.');
    }
    if (!$paciente['ativo']) {
        jsonError(403, 'CONTA_INATIVA', 'Conta desativada. Entre em contato.');
    }
    if (!$paciente['email_verificado']) {
        jsonError(403, 'EMAIL_NAO_VERIFICADO',
            'Verifique seu e-mail antes de entrar. Verifique sua caixa de entrada.');
    }

    // Criar sessão (expira em 7 dias)
    $token    = bin2hex(random_bytes(32));
    $expiraEm = date('Y-m-d H:i:s', strtotime('+7 days'));

    $db->prepare(
        'INSERT INTO paciente_sessoes (paciente_id, token, ip, user_agent, expira_em)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $paciente['id'], $token,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $expiraEm,
    ]);

    // Atualizar último acesso
    $db->prepare('UPDATE pacientes SET ultimo_acesso = NOW() WHERE id = ?')
       ->execute([$paciente['id']]);

    jsonSuccess([
        'token'    => $token,
        'expira_em'=> $expiraEm,
        'paciente' => [
            'id'             => $paciente['id'],
            'nome'           => $paciente['nome'],
            'email'          => $paciente['email'],
            'avatar_inicial' => $paciente['avatar_inicial'],
            'telefone'       => $paciente['telefone'],
        ],
        'message'  => 'Login realizado com sucesso!',
    ]);
}

// ══════════════════════════════════════════════════════
// LOGOUT
// ══════════════════════════════════════════════════════
function actionLogout(array $d): never
{
    $token = $d['token'] ?? getTokenHeader();
    if ($token) {
        getDB()->prepare('DELETE FROM paciente_sessoes WHERE token = ?')
               ->execute([$token]);
    }
    jsonSuccess(['message' => 'Logout realizado.']);
}

// ══════════════════════════════════════════════════════
// VERIFICAR E-MAIL
// ══════════════════════════════════════════════════════
function actionVerificarEmail(array $d): never
{
    $token = trim($d['token'] ?? '');
    if (!$token) jsonError(400, 'TOKEN_INVALIDO', 'Token inválido.');

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id FROM pacientes WHERE token_verificacao = ? AND email_verificado = 0'
    );
    $stmt->execute([$token]);
    $p = $stmt->fetch();

    if (!$p) jsonError(404, 'TOKEN_NAO_ENCONTRADO', 'Token inválido ou já utilizado.');

    $db->prepare(
        'UPDATE pacientes SET email_verificado = 1, token_verificacao = NULL WHERE id = ?'
    )->execute([$p['id']]);

    jsonSuccess(['message' => 'E-mail verificado com sucesso! Você já pode fazer login.']);
}

// ══════════════════════════════════════════════════════
// RESET DE SENHA — SOLICITAR
// ══════════════════════════════════════════════════════
function actionResetSolicitar(array $d): never
{
    $email = trim(strtolower($d['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError(400, 'EMAIL_INVALIDO', 'E-mail inválido.');
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, nome FROM pacientes WHERE email = ? AND ativo = 1');
    $stmt->execute([$email]);
    $p    = $stmt->fetch();

    // Sempre retornar sucesso (não revelar se e-mail existe)
    if ($p) {
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $db->prepare(
            'UPDATE pacientes SET token_reset = ?, token_reset_expira = ? WHERE id = ?'
        )->execute([$token, $expira, $p['id']]);

        enviarEmailReset(['nome' => $p['nome'], 'email' => $email, 'token' => $token]);
    }

    jsonSuccess(['message' => 'Se este e-mail estiver cadastrado, você receberá as instruções.']);
}

// ══════════════════════════════════════════════════════
// RESET DE SENHA — CONFIRMAR
// ══════════════════════════════════════════════════════
function actionResetConfirmar(array $d): never
{
    $token = trim($d['token'] ?? '');
    $senha = $d['senha'] ?? '';

    if (!$token) jsonError(400, 'TOKEN_INVALIDO', 'Token inválido.');
    if (strlen($senha) < 8)
        jsonError(422, 'SENHA_FRACA', 'Senha deve ter ao menos 8 caracteres.');

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id FROM pacientes
         WHERE token_reset = ? AND token_reset_expira > NOW() AND ativo = 1'
    );
    $stmt->execute([$token]);
    $p = $stmt->fetch();

    if (!$p) jsonError(400, 'TOKEN_EXPIRADO', 'Token inválido ou expirado.');

    $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare(
        'UPDATE pacientes SET senha_hash = ?, token_reset = NULL,
         token_reset_expira = NULL WHERE id = ?'
    )->execute([$hash, $p['id']]);

    // Invalidar todas as sessões
    $db->prepare('DELETE FROM paciente_sessoes WHERE paciente_id = ?')
       ->execute([$p['id']]);

    jsonSuccess(['message' => 'Senha alterada com sucesso! Faça login.']);
}

// ── Helpers ───────────────────────────────────────────
function getTokenHeader(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return $m[1];
    return '';
}

function autenticarPaciente(): array
{
    $token = getTokenHeader();
    if (!$token) jsonError(401, 'NAO_AUTENTICADO', 'Token de sessão necessário.');

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT p.id, p.nome, p.email, p.avatar_inicial, p.telefone,
                p.cpf, p.data_nascimento, p.plano_saude
         FROM paciente_sessoes s
         JOIN pacientes p ON p.id = s.paciente_id
         WHERE s.token = ? AND s.expira_em > NOW() AND p.ativo = 1'
    );
    $stmt->execute([$token]);
    $p = $stmt->fetch();
    if (!$p) jsonError(401, 'SESSAO_INVALIDA', 'Sessão expirada. Faça login novamente.');
    return $p;
}

// ── E-mails de auth ───────────────────────────────────
function enviarEmailVerificacao(array $d): void
{
    $url  = APP_BASE_URL . '/painel/?verificar=' . urlencode($d['token']);
    $html = <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f9f8;margin:0;padding:40px 16px">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="560" style="background:#fff;border-radius:20px;overflow:hidden;
  box-shadow:0 8px 40px rgba(0,60,56,.14)">
  <tr><td style="background:linear-gradient(135deg,#005451,#003f9c);padding:36px 48px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:20px">✚ AnestConsulta</h1>
    <p style="color:rgba(255,255,255,.6);margin:6px 0 0;font-size:13px">Verificação de E-mail</p>
  </td></tr>
  <tr><td style="padding:40px 48px;text-align:center">
    <div style="font-size:52px;margin-bottom:20px">📧</div>
    <h2 style="color:#003d3b;margin:0 0 12px;font-size:20px">Confirme seu e-mail</h2>
    <p style="color:#4a7f7e;font-size:14px;line-height:1.65;margin:0 0 28px">
      Olá, <strong style="color:#003d3b">{$d['nome']}</strong>!<br>
      Clique no botão abaixo para ativar sua conta no AnestConsulta.
    </p>
    <a href="{$url}" style="display:inline-block;background:linear-gradient(135deg,#008c87,#003f9c);
      color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;
      font-size:14px;font-weight:700">Verificar meu e-mail →</a>
    <p style="color:#7fa9a8;font-size:11px;margin:20px 0 0">
      Link válido por 24h. Se não foi você, ignore este e-mail.</p>
  </td></tr>
  <tr><td style="background:#f4fbfb;padding:20px 48px;text-align:center;
    border-top:1px solid #e0eeec">
    <p style="margin:0;font-size:11px;color:#7fa9a8">
      © 2025 AnestConsulta | CNPJ 29.168.494/0001-08</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    enviarEmail($d['email'], $d['nome'],
        '✉️ Confirme seu e-mail — AnestConsulta', $html,
        "Olá {$d['nome']}! Acesse para verificar: {$url}");
}

function enviarEmailReset(array $d): void
{
    $url  = APP_BASE_URL . '/painel/?reset=' . urlencode($d['token']);
    $html = <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f9f8;margin:0;padding:40px 16px">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="560" style="background:#fff;border-radius:20px;overflow:hidden;
  box-shadow:0 8px 40px rgba(0,60,56,.14)">
  <tr><td style="background:linear-gradient(135deg,#005451,#003f9c);padding:36px 48px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:20px">✚ AnestConsulta</h1>
    <p style="color:rgba(255,255,255,.6);margin:6px 0 0;font-size:13px">Recuperação de Senha</p>
  </td></tr>
  <tr><td style="padding:40px 48px;text-align:center">
    <div style="font-size:52px;margin-bottom:20px">🔐</div>
    <h2 style="color:#003d3b;margin:0 0 12px;font-size:20px">Redefinir senha</h2>
    <p style="color:#4a7f7e;font-size:14px;line-height:1.65;margin:0 0 28px">
      Olá, <strong style="color:#003d3b">{$d['nome']}</strong>!<br>
      Recebemos uma solicitação para redefinir sua senha.
    </p>
    <a href="{$url}" style="display:inline-block;background:linear-gradient(135deg,#008c87,#003f9c);
      color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;
      font-size:14px;font-weight:700">Redefinir minha senha →</a>
    <p style="color:#7fa9a8;font-size:11px;margin:20px 0 0">
      Link válido por 1 hora. Se não foi você, ignore este e-mail.</p>
  </td></tr>
  <tr><td style="background:#f4fbfb;padding:20px 48px;text-align:center;
    border-top:1px solid #e0eeec">
    <p style="margin:0;font-size:11px;color:#7fa9a8">
      © 2025 AnestConsulta | CNPJ 29.168.494/0001-08</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    enviarEmail($d['email'], $d['nome'],
        '🔐 Redefinir senha — AnestConsulta', $html,
        "Olá {$d['nome']}! Acesse para redefinir: {$url}");
}
