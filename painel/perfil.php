<?php
// ══════════════════════════════════════════════════════
// AnestConsulta — API Perfil do Paciente
// GET  /painel/perfil.php         → ver perfil
// POST /painel/perfil.php         → atualizar dados
// POST /painel/perfil.php?action=senha → trocar senha
// ══════════════════════════════════════════════════════
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/auth.php';

$paciente = autenticarPaciente();
$method   = $_SERVER['REQUEST_METHOD'];
$action   = $_GET['action'] ?? '';

if ($method === 'GET') {
    actionVerPerfil($paciente);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action === 'senha'
        ? actionTrocarSenha($paciente, $input)
        : actionAtualizarPerfil($paciente, $input);
}

jsonError(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');

// ── Ver perfil ────────────────────────────────────────
function actionVerPerfil(array $paciente): never
{
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, nome, email, telefone, cpf, data_nascimento,
                plano_saude, avatar_inicial, email_verificado,
                ultimo_acesso, created_at
         FROM pacientes WHERE id = ?'
    );
    $stmt->execute([$paciente['id']]);
    $p = $stmt->fetch();

    // Estatísticas
    $stats = $db->prepare(
        "SELECT
           COUNT(*) AS total,
           SUM(status = 'pendente') AS pendentes,
           SUM(status = 'confirmado') AS confirmados,
           SUM(status = 'realizado') AS realizados,
           SUM(status = 'cancelado') AS cancelados
         FROM agendamentos WHERE email = ?"
    );
    $stats->execute([$paciente['email']]);

    jsonSuccess([
        'paciente'    => $p,
        'estatisticas'=> $stats->fetch(),
    ]);
}

// ── Atualizar perfil ──────────────────────────────────
function actionAtualizarPerfil(array $paciente, array $d): never
{
    $nome        = mb_substr(trim(strip_tags($d['nome']        ?? '')), 0, 120);
    $telefone    = preg_replace('/\D/', '', $d['telefone']     ?? '');
    $nascimento  = trim($d['data_nascimento'] ?? '');
    $plano       = mb_substr(trim($d['plano_saude'] ?? ''), 0, 60);

    $erros = [];
    if (mb_strlen($nome) < 2)
        $erros[] = ['campo' => 'nome', 'msg' => 'Nome muito curto.'];
    if ($telefone && (strlen($telefone) < 10 || strlen($telefone) > 11))
        $erros[] = ['campo' => 'telefone', 'msg' => 'Telefone inválido.'];
    if ($nascimento && !DateTime::createFromFormat('Y-m-d', $nascimento))
        $erros[] = ['campo' => 'data_nascimento', 'msg' => 'Data de nascimento inválida.'];

    if (!empty($erros)) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error'  => ['code' => 'VALIDATION_ERROR', 'message' => 'Dados inválidos.'],
            'campos' => $erros], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Atualizar iniciais do avatar
    $partes  = explode(' ', $nome);
    $inicial = strtoupper(substr($partes[0], 0, 1) . substr($partes[1] ?? $partes[0], 0, 1));

    getDB()->prepare(
        'UPDATE pacientes
         SET nome = ?, telefone = ?, data_nascimento = ?,
             plano_saude = ?, avatar_inicial = ?
         WHERE id = ?'
    )->execute([
        $nome,
        $telefone ?: null,
        $nascimento ?: null,
        $plano ?: null,
        $inicial,
        $paciente['id'],
    ]);

    jsonSuccess(['message' => 'Perfil atualizado com sucesso!']);
}

// ── Trocar senha ──────────────────────────────────────
function actionTrocarSenha(array $paciente, array $d): never
{
    $senhaAtual = $d['senha_atual'] ?? '';
    $novaSenha  = $d['nova_senha']  ?? '';

    if (!$senhaAtual || !$novaSenha)
        jsonError(400, 'CAMPOS_OBRIGATORIOS', 'Senha atual e nova senha obrigatórias.');
    if (strlen($novaSenha) < 8)
        jsonError(422, 'SENHA_FRACA', 'Nova senha deve ter ao menos 8 caracteres.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT senha_hash FROM pacientes WHERE id = ?');
    $stmt->execute([$paciente['id']]);
    $p = $stmt->fetch();

    if (!password_verify($senhaAtual, $p['senha_hash']))
        jsonError(401, 'SENHA_INCORRETA', 'Senha atual incorreta.');

    $hash = password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('UPDATE pacientes SET senha_hash = ? WHERE id = ?')
       ->execute([$hash, $paciente['id']]);

    // Invalidar outras sessões
    $db->prepare(
        'DELETE FROM paciente_sessoes WHERE paciente_id = ? AND token != ?'
    )->execute([$paciente['id'], getTokenHeader()]);

    jsonSuccess(['message' => 'Senha alterada com sucesso!']);
}
