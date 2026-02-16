<?php
// ══════════════════════════════════════════════════════
// AnestConsulta — API Agendamentos do Paciente
// GET  /painel/meus-agendamentos.php       → listar
// GET  /painel/meus-agendamentos.php?id=X  → detalhe
// POST /painel/meus-agendamentos.php?action=cancelar
// POST /painel/meus-agendamentos.php?action=remarcar
// POST /painel/perfil.php?action=atualizar
// ══════════════════════════════════════════════════════
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/auth.php';

$paciente = autenticarPaciente();
$action   = $_GET['action'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $id > 0 ? actionDetalhe($paciente, $id) : actionListar($paciente);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    match($action) {
        'cancelar' => actionCancelar($paciente, $input),
        'remarcar' => actionRemarcar($paciente, $input),
        default    => jsonError(400, 'INVALID_ACTION', 'Ação inválida.')
    };
}

jsonError(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');

// ══════════════════════════════════════════════════════
// LISTAR AGENDAMENTOS
// ══════════════════════════════════════════════════════
function actionListar(array $paciente): never
{
    $db     = getDB();
    $status = $_GET['status'] ?? 'todos';
    $params = [$paciente['email']];
    $where  = 'a.email = ?';

    if ($status !== 'todos') {
        $where   .= ' AND a.status = ?';
        $params[] = $status;
    }

    $stmt = $db->prepare(
        "SELECT a.id, a.data_consulta, a.horario, a.tipo_consulta,
                a.cirurgia, a.status, a.email_status, a.created_at,
                a.token, a.observacoes,
                m.nome AS medico_nome, m.especialidade, m.crm
         FROM agendamentos a
         JOIN medicos m ON m.id = a.medico_id
         WHERE $where
         ORDER BY a.data_consulta DESC, a.horario DESC"
    );
    $stmt->execute($params);
    $agendamentos = $stmt->fetchAll();

    // Formatar datas
    $tz    = new DateTimeZone(APP_TIMEZONE);
    $meses = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
              7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

    foreach ($agendamentos as &$ag) {
        $dt = DateTime::createFromFormat('Y-m-d', $ag['data_consulta'], $tz);
        $ag['data_formatada']  = $dt->format('d') . ' ' . $meses[(int)$dt->format('n')]
                               . ' ' . $dt->format('Y');
        $ag['horario_fmt']     = substr($ag['horario'], 0, 5) . 'h';
        $ag['pode_cancelar']   = $ag['status'] === 'pendente' &&
                                 strtotime($ag['data_consulta']) > strtotime('+1 day');
        $ag['pode_remarcar']   = $ag['status'] === 'pendente' &&
                                 strtotime($ag['data_consulta']) > strtotime('+2 days');
    }

    jsonSuccess([
        'agendamentos' => $agendamentos,
        'total'        => count($agendamentos),
    ]);
}

// ══════════════════════════════════════════════════════
// DETALHE DO AGENDAMENTO
// ══════════════════════════════════════════════════════
function actionDetalhe(array $paciente, int $id): never
{
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT a.*, m.nome AS medico_nome, m.especialidade, m.crm
         FROM agendamentos a
         JOIN medicos m ON m.id = a.medico_id
         WHERE a.id = ? AND a.email = ?'
    );
    $stmt->execute([$id, $paciente['email']]);
    $ag = $stmt->fetch();

    if (!$ag) jsonError(404, 'NOT_FOUND', 'Agendamento não encontrado.');

    jsonSuccess(['agendamento' => $ag]);
}

// ══════════════════════════════════════════════════════
// CANCELAR AGENDAMENTO
// ══════════════════════════════════════════════════════
function actionCancelar(array $paciente, array $d): never
{
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError(400, 'ID_INVALIDO', 'ID do agendamento obrigatório.');

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, data_consulta, status FROM agendamentos
         WHERE id = ? AND email = ? AND status = 'pendente'"
    );
    $stmt->execute([$id, $paciente['email']]);
    $ag = $stmt->fetch();

    if (!$ag) jsonError(404, 'NOT_FOUND', 'Agendamento não encontrado ou não pode ser cancelado.');

    // Mínimo 24h de antecedência
    if (strtotime($ag['data_consulta']) <= strtotime('+1 day')) {
        jsonError(422, 'PRAZO_CANCELAMENTO',
            'Cancelamentos devem ser feitos com ao menos 24h de antecedência.');
    }

    $db->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ?")
       ->execute([$id]);

    jsonSuccess(['message' => 'Agendamento cancelado com sucesso.', 'id' => $id]);
}

// ══════════════════════════════════════════════════════
// REMARCAR AGENDAMENTO
// ══════════════════════════════════════════════════════
function actionRemarcar(array $paciente, array $d): never
{
    $id             = (int)($d['id']      ?? 0);
    $nova_data      = trim($d['data']     ?? '');
    $novo_horario   = trim($d['horario']  ?? '');

    if (!$id) jsonError(400, 'ID_INVALIDO', 'ID obrigatório.');

    // Validar nova data
    $tz = new DateTimeZone(APP_TIMEZONE);
    $dt = DateTime::createFromFormat('Y-m-d', $nova_data, $tz);
    if (!$dt || $dt <= new DateTime('tomorrow', $tz)) {
        jsonError(422, 'DATA_INVALIDA', 'Nova data deve ser pelo menos 2 dias à frente.');
    }

    $horarios_validos = ['08:00','09:00','10:00','11:00','14:00','15:00','16:00'];
    if (!in_array($novo_horario, $horarios_validos, true)) {
        jsonError(422, 'HORARIO_INVALIDO', 'Horário inválido.');
    }

    $db = getDB();

    // Verificar que é do paciente e está pendente
    $stmt = $db->prepare(
        "SELECT id, medico_id FROM agendamentos
         WHERE id = ? AND email = ? AND status = 'pendente'"
    );
    $stmt->execute([$id, $paciente['email']]);
    $ag = $stmt->fetch();
    if (!$ag) jsonError(404, 'NOT_FOUND', 'Agendamento não encontrado.');

    // Verificar disponibilidade do novo horário
    $stmtConf = $db->prepare(
        "SELECT id FROM agendamentos
         WHERE medico_id = ? AND data_consulta = ? AND horario = ?
         AND status != 'cancelado' AND id != ?"
    );
    $stmtConf->execute([$ag['medico_id'], $nova_data, $novo_horario . ':00', $id]);
    if ($stmtConf->fetch()) {
        jsonError(409, 'HORARIO_INDISPONIVEL', 'Horário indisponível. Escolha outro.');
    }

    $db->prepare(
        'UPDATE agendamentos SET data_consulta = ?, horario = ? WHERE id = ?'
    )->execute([$nova_data, $novo_horario . ':00', $id]);

    jsonSuccess(['message' => 'Consulta remarcada com sucesso!', 'id' => $id]);
}
