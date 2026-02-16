<?php
// ══════════════════════════════════════════════════════
// AnestConsulta — POST /api/agendamentos
// Stack: PHP 8.1+ | MySQL 8.0+ | PHPMailer 6.x
// ══════════════════════════════════════════════════════

declare(strict_types=1);

// ── Headers CORS e Content-Type ───────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = [APP_BASE_URL ?? 'https://anestconsulta.com'];
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

// ══════════════════════════════════════════════════════
// 1. RATE LIMIT POR IP
// ══════════════════════════════════════════════════════
$ip = filter_var(
    $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    FILTER_VALIDATE_IP
) ?: '0.0.0.0';

verificarRateLimit($ip);

// ══════════════════════════════════════════════════════
// 2. RECEBER E DECODIFICAR JSON
// ══════════════════════════════════════════════════════
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    jsonError(400, 'INVALID_JSON', 'Corpo da requisição inválido.');
}

// ══════════════════════════════════════════════════════
// 3. HONEYPOT ANTI-SPAM
// ══════════════════════════════════════════════════════
// Campo oculto no frontend — bots preenchem, humanos não
if (!empty($input['website']) || !empty($input['_gotcha'])) {
    // Fingir sucesso para não alertar bots
    jsonSuccess(['id' => rand(1000, 9999), 'message' => 'Agendamento recebido!']);
}

// ══════════════════════════════════════════════════════
// 4. SANITIZAR INPUTS
// ══════════════════════════════════════════════════════
function sanitize(mixed $val, int $maxLen = 255): string
{
    return mb_substr(trim(strip_tags((string)($val ?? ''))), 0, $maxLen);
}

$nome           = sanitize($input['nome'],        120);
$email          = sanitize($input['email'],        120);
$telefone       = sanitize($input['telefone'],      20);
$cpf            = preg_replace('/\D/', '', sanitize($input['cpf'] ?? '', 14));
$nascimento     = sanitize($input['nascimento'],    10);
$plano          = sanitize($input['plano']  ?? '',  60);
$medico_id      = (int)($input['medico_id']   ?? 0);
$tipo_consulta  = sanitize($input['tipo'],          20);
$data_consulta  = sanitize($input['data'],          10);
$horario        = sanitize($input['horario'],        5);
$cirurgia       = sanitize($input['cirurgia'],      200);
$observacoes    = sanitize($input['observacoes'] ?? '', 2000);

// ══════════════════════════════════════════════════════
// 5. VALIDAÇÕES
// ══════════════════════════════════════════════════════
$erros = [];

// Nome (>= 2 chars)
if (mb_strlen($nome) < 2) {
    $erros[] = ['campo' => 'nome', 'msg' => 'Nome deve ter ao menos 2 caracteres.'];
}

// E-mail — regex RFC 5322 simplificada + validação nativa PHP
if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $email)) {
    $erros[] = ['campo' => 'email', 'msg' => 'E-mail inválido.'];
}

// Telefone
$tel_nums = preg_replace('/\D/', '', $telefone);
if (strlen($tel_nums) < 10 || strlen($tel_nums) > 11) {
    $erros[] = ['campo' => 'telefone', 'msg' => 'Telefone inválido.'];
}

// CPF
if (strlen($cpf) !== 11 || !validarCPF($cpf)) {
    $erros[] = ['campo' => 'cpf', 'msg' => 'CPF inválido.'];
}

// Data de nascimento
if (!DateTime::createFromFormat('Y-m-d', $nascimento)) {
    $erros[] = ['campo' => 'nascimento', 'msg' => 'Data de nascimento inválida.'];
}

// Médico
if ($medico_id <= 0) {
    $erros[] = ['campo' => 'medico_id', 'msg' => 'Selecione um médico.'];
}

// Tipo de consulta
if (!in_array($tipo_consulta, ['online', 'presencial'], true)) {
    $erros[] = ['campo' => 'tipo', 'msg' => 'Tipo de consulta inválido.'];
}

// Data da consulta — timezone America/Sao_Paulo
$tz = new DateTimeZone(APP_TIMEZONE);

$dtConsulta = DateTime::createFromFormat('Y-m-d', $data_consulta, $tz);
if (!$dtConsulta) {
    $erros[] = ['campo' => 'data', 'msg' => 'Data da consulta inválida.'];
} else {
    $dtConsulta->setTime(0, 0, 0);
    $hoje = new DateTime('today', $tz);
    if ($dtConsulta < $hoje) {
        $erros[] = ['campo' => 'data', 'msg' => 'Data da consulta não pode ser no passado.'];
    }
    // Não aceitar fim de semana
    $diaSemana = (int)$dtConsulta->format('N'); // 1=seg ... 7=dom
    if ($diaSemana >= 6) {
        $erros[] = ['campo' => 'data', 'msg' => 'Agendamentos apenas em dias úteis (seg–sex).'];
    }
}

// Horário
$horarios_validos = ['08:00','09:00','10:00','11:00','14:00','15:00','16:00'];
if (!in_array($horario, $horarios_validos, true)) {
    $erros[] = ['campo' => 'horario', 'msg' => 'Horário inválido.'];
}

// Procedimento
if (mb_strlen($cirurgia) < 3) {
    $erros[] = ['campo' => 'cirurgia', 'msg' => 'Informe o procedimento cirúrgico.'];
}

if (!empty($erros)) {
    http_response_code(422);
    echo json_encode([
        'ok'     => false,
        'error'  => ['code' => 'VALIDATION_ERROR', 'message' => 'Dados inválidos.'],
        'campos' => $erros
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════
// 6. VERIFICAR MÉDICO E DISPONIBILIDADE
// ══════════════════════════════════════════════════════
$db = getDB();

$stmtMed = $db->prepare(
    'SELECT id, nome, email, especialidade FROM medicos WHERE id = ? AND ativo = 1'
);
$stmtMed->execute([$medico_id]);
$medico = $stmtMed->fetch();

if (!$medico) {
    jsonError(404, 'MEDICO_NOT_FOUND', 'Médico não encontrado ou inativo.');
}

// Verificar conflito de horário
$stmtConf = $db->prepare(
    'SELECT id FROM agendamentos
     WHERE medico_id = ? AND data_consulta = ? AND horario = ?
     AND status != "cancelado"'
);
$stmtConf->execute([$medico_id, $data_consulta, $horario . ':00']);
if ($stmtConf->fetch()) {
    jsonError(409, 'HORARIO_INDISPONIVEL', 'Horário indisponível. Escolha outro.');
}

// ══════════════════════════════════════════════════════
// 7. GRAVAR NO BANCO
// ══════════════════════════════════════════════════════
$token = bin2hex(random_bytes(32)); // 64 chars hex

try {
    $db->beginTransaction();

    $stmt = $db->prepare(
       'INSERT INTO agendamentos
        (nome, email, telefone, cpf, data_nascimento, plano_saude,
         medico_id, tipo_consulta, data_consulta, horario,
         cirurgia, observacoes, status, token, ip_origem, email_status)
        VALUES
        (:nome, :email, :telefone, :cpf, :nascimento, :plano,
         :medico_id, :tipo, :data, :horario,
         :cirurgia, :obs, "pendente", :token, :ip, "pendente")'
    );

    $stmt->execute([
        ':nome'      => $nome,
        ':email'     => $email,
        ':telefone'  => $tel_nums,
        ':cpf'       => $cpf,
        ':nascimento'=> $nascimento,
        ':plano'     => $plano ?: null,
        ':medico_id' => $medico_id,
        ':tipo'      => $tipo_consulta,
        ':data'      => $data_consulta,
        ':horario'   => $horario . ':00',
        ':cirurgia'  => $cirurgia,
        ':obs'       => $observacoes ?: null,
        ':token'     => $token,
        ':ip'        => $ip,
    ]);

    $agendamento_id = (int)$db->lastInsertId();

    // Incrementar rate limit
    incrementarRateLimit($ip);

    $db->commit();

} catch (PDOException $e) {
    $db->rollBack();
    logErro('DB_INSERT', $e->getMessage());
    jsonError(500, 'DB_ERROR', 'Erro ao salvar agendamento. Tente novamente.');
}

// ══════════════════════════════════════════════════════
// 8. FORMATAR DATA
// ══════════════════════════════════════════════════════
$meses = [
    1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',
    5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',
    9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'
];
$dtObj        = DateTime::createFromFormat('Y-m-d', $data_consulta, $tz);
$dataFormatada = $dtObj->format('d') . ' de ' .
                 $meses[(int)$dtObj->format('n')] . ' de ' .
                 $dtObj->format('Y');
$diaSemanas = ['','Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];
$diaSemNome = $diaSemanas[(int)$dtObj->format('N')];

// ══════════════════════════════════════════════════════
// 9. ENVIO DE E-MAILS
// ══════════════════════════════════════════════════════
$dadosEmail = [
    'agendamento_id' => $agendamento_id,
    'nome'           => $nome,
    'email'          => $email,
    'telefone'       => $tel_nums,
    'medico'         => $medico['nome'],
    'medico_email'   => $medico['email'],
    'especialidade'  => $medico['especialidade'],
    'tipo'           => $tipo_consulta === 'online' ? 'Telemedicina (online)' : 'Presencial',
    'data'           => $dataFormatada,
    'dia_semana'     => $diaSemNome,
    'horario'        => $horario,
    'cirurgia'       => $cirurgia,
    'token'          => $token,
];

// E-mail para o PACIENTE
$resultPaciente = enviarEmailPaciente($dadosEmail);

// E-mail para o MÉDICO
$resultMedico = enviarEmailMedico($dadosEmail);

// Atualizar status do e-mail no banco
$emailStatus = $resultPaciente['ok'] ? 'enviado' : 'falhou';
$db->prepare(
    'UPDATE agendamentos
     SET email_status = ?, email_tentativas = 1,
         email_enviado_em = ?, email_erro = ?
     WHERE id = ?'
)->execute([
    $emailStatus,
    $resultPaciente['ok'] ? date('Y-m-d H:i:s') : null,
    $resultPaciente['ok'] ? null : ($resultPaciente['erro'] ?? 'Erro desconhecido'),
    $agendamento_id
]);

// Log dos dois e-mails
$stmtLog = $db->prepare(
    'INSERT INTO email_logs
     (agendamento_id, tipo, destinatario, assunto, enviado, erro, enviado_em)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmtLog->execute([
    $agendamento_id, 'confirmacao_paciente', $email,
    'Confirmação da sua consulta — AnestConsulta',
    $resultPaciente['ok'] ? 1 : 0,
    $resultPaciente['ok'] ? null : $resultPaciente['erro'],
    $resultPaciente['ok'] ? date('Y-m-d H:i:s') : null,
]);
$stmtLog->execute([
    $agendamento_id, 'notificacao_medico', $medico['email'],
    'Novo agendamento — ' . $nome,
    $resultMedico['ok'] ? 1 : 0,
    $resultMedico['ok'] ? null : $resultMedico['erro'],
    $resultMedico['ok'] ? date('Y-m-d H:i:s') : null,
]);

// ══════════════════════════════════════════════════════
// 10. RESPOSTA DE SUCESSO
// ══════════════════════════════════════════════════════
jsonSuccess([
    'id'             => $agendamento_id,
    'message'        => 'Agendamento realizado com sucesso! Verifique seu e-mail.',
    'email_enviado'  => $resultPaciente['ok'],
    'medico'         => $medico['nome'],
    'data'           => $dataFormatada,
    'horario'        => $horario,
    'tipo'           => $tipo_consulta,
    'token'          => $token,
], 201);

// ══════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ══════════════════════════════════════════════════════

function validarCPF(string $cpf): bool
{
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) $soma += (int)$cpf[$i] * ($t + 1 - $i);
        $r = (($soma * 10) % 11) % 10;
        if ((int)$cpf[$t] !== $r) return false;
    }
    return true;
}

function verificarRateLimit(string $ip): void
{
    $db     = getDB();
    $janela = RATE_LIMIT_WINDOW; // minutos

    $stmt = $db->prepare(
        'SELECT tentativas, janela_ini FROM rate_limit
         WHERE ip = ? AND endpoint = "agendamento"'
    );
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if ($row) {
        $inicio   = new DateTime($row['janela_ini']);
        $agora    = new DateTime();
        $diffMin  = ($agora->getTimestamp() - $inicio->getTimestamp()) / 60;

        if ($diffMin > $janela) {
            // Janela expirou — resetar
            $db->prepare(
                'UPDATE rate_limit SET tentativas = 0, janela_ini = NOW()
                 WHERE ip = ? AND endpoint = "agendamento"'
            )->execute([$ip]);
        } elseif ((int)$row['tentativas'] >= RATE_LIMIT_MAX) {
            $restam = (int)ceil($janela - $diffMin);
            jsonError(429, 'RATE_LIMIT',
                "Muitas tentativas. Aguarde {$restam} minuto(s) e tente novamente.");
        }
    }
}

function incrementarRateLimit(string $ip): void
{
    $db = getDB();
    $db->prepare(
        'INSERT INTO rate_limit (ip, endpoint, tentativas, janela_ini)
         VALUES (?, "agendamento", 1, NOW())
         ON DUPLICATE KEY UPDATE tentativas = tentativas + 1'
    )->execute([$ip]);
}
