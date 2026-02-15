<?php
// ══════════════════════════════════════════════
// AnesConsulta — API de Agendamento
// POST /agendamento.php
// ══════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

// ── 1. Receber dados ───────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST; // fallback para form tradicional
}

// ── 2. Sanitizar ──────────────────────────────
function sanitize(string $value): string {
    return trim(strip_tags($value));
}

$nome           = sanitize($input['nome']           ?? '');
$cpf            = sanitize($input['cpf']            ?? '');
$email          = sanitize($input['email']          ?? '');
$telefone       = sanitize($input['telefone']       ?? '');
$nascimento     = sanitize($input['nascimento']     ?? '');
$plano          = sanitize($input['plano']          ?? '');
$medico_id      = (int)($input['medico_id']         ?? 0);
$tipo_consulta  = sanitize($input['tipo']           ?? '');
$data_consulta  = sanitize($input['data']           ?? '');
$horario        = sanitize($input['horario']        ?? '');
$cirurgia       = sanitize($input['cirurgia']       ?? '');
$observacoes    = sanitize($input['observacoes']    ?? '');

// ── 3. Validar ────────────────────────────────
$erros = [];

if (strlen($nome) < 3)
    $erros[] = 'Nome inválido.';

// Valida CPF
$cpf_nums = preg_replace('/\D/', '', $cpf);
if (strlen($cpf_nums) !== 11 || !validarCPF($cpf_nums))
    $erros[] = 'CPF inválido.';

if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    $erros[] = 'E-mail inválido.';

if (strlen(preg_replace('/\D/', '', $telefone)) < 10)
    $erros[] = 'Telefone inválido.';

if (!DateTime::createFromFormat('Y-m-d', $nascimento))
    $erros[] = 'Data de nascimento inválida.';

if ($medico_id <= 0)
    $erros[] = 'Médico não selecionado.';

if (!in_array($tipo_consulta, ['online', 'presencial']))
    $erros[] = 'Tipo de consulta inválido.';

if (!DateTime::createFromFormat('Y-m-d', $data_consulta))
    $erros[] = 'Data da consulta inválida.';

// Data mínima = hoje
if (strtotime($data_consulta) < strtotime('today'))
    $erros[] = 'Data da consulta não pode ser no passado.';

$horarios_validos = ['08:00','09:00','10:00','11:00','14:00','15:00','16:00'];
if (!in_array($horario, $horarios_validos))
    $erros[] = 'Horário inválido.';

if (strlen($cirurgia) < 3)
    $erros[] = 'Informe o procedimento cirúrgico.';

if (!empty($erros)) {
    http_response_code(422);
    echo json_encode(['erro' => implode(' ', $erros)]);
    exit;
}

// ── 4. Verificar disponibilidade ──────────────
$db = getDB();

$stmtDisp = $db->prepare(
    'SELECT COUNT(*) FROM agendamentos
     WHERE medico_id = ? AND data_consulta = ? AND horario = ?
     AND status NOT IN ("cancelado")'
);
$stmtDisp->execute([$medico_id, $data_consulta, $horario . ':00']);
if ($stmtDisp->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['erro' => 'Horário indisponível. Escolha outro horário.']);
    exit;
}

// ── 5. Buscar dados do médico ─────────────────
$stmtMed = $db->prepare('SELECT * FROM medicos WHERE id = ? AND ativo = 1');
$stmtMed->execute([$medico_id]);
$medico = $stmtMed->fetch();
if (!$medico) {
    http_response_code(404);
    echo json_encode(['erro' => 'Médico não encontrado.']);
    exit;
}

// ── 6. Salvar agendamento ─────────────────────
$token = bin2hex(random_bytes(32));

$stmt = $db->prepare(
    'INSERT INTO agendamentos
     (nome, cpf, email, telefone, data_nascimento, plano_saude,
      medico_id, tipo_consulta, data_consulta, horario,
      cirurgia, observacoes, token, ip_origem)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$stmt->execute([
    $nome, $cpf_nums, $email, $telefone, $nascimento,
    $plano ?: null,
    $medico_id, $tipo_consulta, $data_consulta,
    $horario . ':00',
    $cirurgia, $observacoes ?: null,
    $token,
    $_SERVER['REMOTE_ADDR'] ?? null
]);

$agendamento_id = $db->lastInsertId();

// ── 7. Formatar data para exibição ────────────
$dataObj = DateTime::createFromFormat('Y-m-d', $data_consulta);
$meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
          'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$data_formatada = $dataObj->format('d') . ' de ' .
                  $meses[(int)$dataObj->format('m') - 1] . ' de ' .
                  $dataObj->format('Y');

// ── 8. Enviar e-mails ─────────────────────────
$dados_email = [
    'nome'           => $nome,
    'email'          => $email,
    'medico'         => $medico['nome'],
    'medico_email'   => $medico['email'],
    'tipo'           => $tipo_consulta === 'online' ? 'Telemedicina' : 'Presencial',
    'data'           => $data_formatada,
    'horario'        => $horario,
    'cirurgia'       => $cirurgia,
    'token'          => $token,
    'agendamento_id' => $agendamento_id,
];

$emailPaciente = enviarEmailPaciente($dados_email);
$emailMedico   = enviarEmailMedico($dados_email);

// Registrar log de e-mails
$stmtLog = $db->prepare(
    'INSERT INTO email_logs (agendamento_id, tipo, destinatario, enviado, erro, enviado_em)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmtLog->execute([
    $agendamento_id, 'confirmacao_paciente', $email,
    $emailPaciente['ok'] ? 1 : 0,
    $emailPaciente['erro'] ?? null,
    $emailPaciente['ok'] ? date('Y-m-d H:i:s') : null
]);
$stmtLog->execute([
    $agendamento_id, 'notificacao_medico', $medico['email'],
    $emailMedico['ok'] ? 1 : 0,
    $emailMedico['erro'] ?? null,
    $emailMedico['ok'] ? date('Y-m-d H:i:s') : null
]);

// ── 9. Resposta de sucesso ────────────────────
http_response_code(201);
echo json_encode([
    'sucesso'        => true,
    'agendamento_id' => $agendamento_id,
    'token'          => $token,
    'medico'         => $medico['nome'],
    'data'           => $data_formatada,
    'horario'        => $horario,
    'tipo'           => $tipo_consulta,
    'mensagem'       => 'Agendamento realizado com sucesso! Verifique seu e-mail.'
]);

// ── Função validação CPF ──────────────────────
function validarCPF(string $cpf): bool {
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += $cpf[$i] * ($t + 1 - $i);
        }
        $r = (($soma * 10) % 11) % 10;
        if ($cpf[$t] != $r) return false;
    }
    return true;
}
