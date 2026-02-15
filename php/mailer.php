<?php
// ══════════════════════════════════════════════
// AnesConsulta — Envio de E-mails (PHPMailer)
// ══════════════════════════════════════════════

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Tenta usar PHPMailer via Composer, se não usa mail() nativo
$usePHPMailer = file_exists(__DIR__ . '/../vendor/autoload.php');
if ($usePHPMailer) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ── Enviar e-mail para o PACIENTE ─────────────
function enviarEmailPaciente(array $d): array {
    $assunto = '✅ Consulta Agendada — AnesConsulta';
    $corpo   = templateEmailPaciente($d);
    return enviarEmail($d['email'], $d['nome'], $assunto, $corpo);
}

// ── Enviar e-mail para o MÉDICO ───────────────
function enviarEmailMedico(array $d): array {
    $assunto = '📋 Novo Agendamento — ' . $d['nome'];
    $corpo   = templateEmailMedico($d);
    return enviarEmail($d['medico_email'], $d['medico'], $assunto, $corpo);
}

// ── Core de envio ─────────────────────────────
function enviarEmail(string $para, string $paraNome, string $assunto, string $corpo): array {
    global $usePHPMailer;

    if ($usePHPMailer) {
        return enviarComPHPMailer($para, $paraNome, $assunto, $corpo);
    }
    // Fallback: mail() nativo da Hostinger
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $ok = mail($para, $assunto, $corpo, $headers);
    return $ok ? ['ok' => true] : ['ok' => false, 'erro' => 'mail() retornou false'];
}

function enviarComPHPMailer(string $para, string $paraNome, string $assunto, string $corpo): array {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($para, $paraNome);
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body    = $corpo;
        $mail->send();
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'erro' => $mail->ErrorInfo];
    }
}

// ── Template e-mail PACIENTE ──────────────────
function templateEmailPaciente(array $d): string {
    $tipo_icon = $d['tipo'] === 'Telemedicina' ? '💻' : '🏥';
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f9f8;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:40px 20px">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,60,56,.12)">
      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#008c87,#0050b3);padding:36px 40px;text-align:center">
        <div style="font-size:32px;margin-bottom:8px">✚</div>
        <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:700;letter-spacing:-0.5px">AnesConsulta</h1>
        <p style="color:rgba(255,255,255,.7);margin:6px 0 0;font-size:13px">Avaliação Pré-Anestésica</p>
      </td></tr>
      <!-- Ícone de sucesso -->
      <tr><td style="padding:36px 40px 0;text-align:center">
        <div style="width:72px;height:72px;background:#e6faf9;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:36px;border:3px solid #b3eee9;margin-bottom:20px">✅</div>
        <h2 style="color:#0a2322;margin:0 0 8px;font-size:20px">Consulta Confirmada!</h2>
        <p style="color:#4a7f7e;margin:0;font-size:14px;line-height:1.6">Olá, <strong>{$d['nome']}</strong>! Seu agendamento foi realizado com sucesso.</p>
      </td></tr>
      <!-- Detalhes -->
      <tr><td style="padding:28px 40px">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4fbfb;border-radius:12px;border:1px solid #e6faf9">
          <tr><td style="padding:20px 24px;border-bottom:1px solid #e6faf9">
            <span style="font-size:12px;font-weight:700;color:#008c87;text-transform:uppercase;letter-spacing:.06em">Detalhes do Agendamento</span>
          </td></tr>
          <tr><td style="padding:16px 24px;border-bottom:1px solid #e6faf9">
            <table width="100%"><tr>
              <td style="font-size:13px;color:#4a7f7e;width:40%">👨‍⚕️ Médico</td>
              <td style="font-size:13px;color:#0a2322;font-weight:600">{$d['medico']}</td>
            </tr></table>
          </td></tr>
          <tr><td style="padding:16px 24px;border-bottom:1px solid #e6faf9">
            <table width="100%"><tr>
              <td style="font-size:13px;color:#4a7f7e;width:40%">📅 Data</td>
              <td style="font-size:13px;color:#0a2322;font-weight:600">{$d['data']}</td>
            </tr></table>
          </td></tr>
          <tr><td style="padding:16px 24px;border-bottom:1px solid #e6faf9">
            <table width="100%"><tr>
              <td style="font-size:13px;color:#4a7f7e;width:40%">🕐 Horário</td>
              <td style="font-size:13px;color:#0a2322;font-weight:600">{$d['horario']}h</td>
            </tr></table>
          </td></tr>
          <tr><td style="padding:16px 24px">
            <table width="100%"><tr>
              <td style="font-size:13px;color:#4a7f7e;width:40%">{$tipo_icon} Modalidade</td>
              <td style="font-size:13px;color:#0a2322;font-weight:600">{$d['tipo']}</td>
            </tr></table>
          </td></tr>
        </table>
      </td></tr>
      <!-- Orientações -->
      <tr><td style="padding:0 40px 28px">
        <div style="background:#fff8e1;border-radius:10px;padding:18px 20px;border-left:4px solid #f59e0b">
          <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#92400e">⚠️ Documentos necessários</p>
          <p style="margin:0;font-size:13px;color:#78350f;line-height:1.6">
            Separe: documento com foto, pedido cirúrgico, exames recentes (até 6 meses), lista de medicamentos e cartão do plano de saúde.
          </p>
        </div>
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f4fbfb;padding:24px 40px;text-align:center;border-top:1px solid #e8f0ef">
        <p style="margin:0 0 6px;font-size:12px;color:#4a7f7e">Dúvidas? Entre em contato conosco</p>
        <p style="margin:0;font-size:12px;color:#7fa9a8">© 2025 AnesConsulta — CNPJ 00.000.000/0001-00</p>
        <p style="margin:6px 0 0;font-size:11px;color:#aac8c7">Token de confirmação: {$d['token']}</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

// ── Template e-mail MÉDICO ────────────────────
function templateEmailMedico(array $d): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0f9f8;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:40px 20px">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,60,56,.12)">
      <tr><td style="background:linear-gradient(135deg,#003d3b,#002f6c);padding:28px 40px">
        <h1 style="color:#ffffff;margin:0;font-size:18px">📋 Novo Agendamento</h1>
        <p style="color:rgba(255,255,255,.6);margin:4px 0 0;font-size:13px">AnesConsulta — Painel Médico</p>
      </td></tr>
      <tr><td style="padding:32px 40px">
        <p style="color:#0a2322;font-size:14px;margin:0 0 20px">Olá, <strong>{$d['medico']}</strong>! Você tem um novo agendamento.</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4fbfb;border-radius:10px;border:1px solid #e6faf9">
          <tr><td style="padding:14px 20px;border-bottom:1px solid #e6faf9"><table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:35%">Paciente</td>
            <td style="font-size:13px;color:#0a2322;font-weight:600">{$d['nome']}</td>
          </tr></table></td></tr>
          <tr><td style="padding:14px 20px;border-bottom:1px solid #e6faf9"><table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:35%">E-mail</td>
            <td style="font-size:13px;color:#0a2322">{$d['email']}</td>
          </tr></table></td></tr>
          <tr><td style="padding:14px 20px;border-bottom:1px solid #e6faf9"><table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:35%">Data / Horário</td>
            <td style="font-size:13px;color:#0a2322;font-weight:600">{$d['data']} às {$d['horario']}h</td>
          </tr></table></td></tr>
          <tr><td style="padding:14px 20px;border-bottom:1px solid #e6faf9"><table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:35%">Modalidade</td>
            <td style="font-size:13px;color:#0a2322">{$d['tipo']}</td>
          </tr></table></td></tr>
          <tr><td style="padding:14px 20px"><table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:35%">Cirurgia</td>
            <td style="font-size:13px;color:#0a2322">{$d['cirurgia']}</td>
          </tr></table></td></tr>
        </table>
        <div style="margin-top:20px;text-align:center">
          <a href="{BASE_URL}/admin.php" style="background:linear-gradient(135deg,#008c87,#0050b3);color:white;text-decoration:none;padding:12px 28px;border-radius:50px;font-size:13px;font-weight:600;display:inline-block">
            Ver no painel admin →
          </a>
        </div>
      </td></tr>
      <tr><td style="background:#f4fbfb;padding:18px 40px;text-align:center;border-top:1px solid #e8f0ef">
        <p style="margin:0;font-size:12px;color:#7fa9a8">© 2025 AnesConsulta</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}
