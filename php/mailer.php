<?php
// ══════════════════════════════════════════════════════
// AnestConsulta — Mailer (PHPMailer 6.x via Composer)
// Provedor: SMTP Hostinger (smtp.hostinger.com:587 TLS)
// Fallback: mail() nativo PHP
// ══════════════════════════════════════════════════════

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

$vendorPath = __DIR__ . '/../vendor/autoload.php';
$usePHPMailer = file_exists($vendorPath);
if ($usePHPMailer) require_once $vendorPath;

// ── Enviar para o PACIENTE ────────────────────────────
function enviarEmailPaciente(array $d): array
{
    $assunto  = '✅ Confirmação da sua consulta — AnestConsulta';
    $html     = templatePacienteHtml($d);
    $texto    = templatePacienteTexto($d);
    return enviarEmail($d['email'], $d['nome'], $assunto, $html, $texto);
}

// ── Enviar para o MÉDICO ──────────────────────────────
function enviarEmailMedico(array $d): array
{
    $assunto = '📋 Novo agendamento — ' . $d['nome'];
    $html    = templateMedicoHtml($d);
    $texto   = templateMedicoTexto($d);
    return enviarEmail($d['medico_email'], $d['medico'], $assunto, $html, $texto);
}

// ── Core de envio ─────────────────────────────────────
function enviarEmail(
    string $para, string $paraNome,
    string $assunto, string $html, string $texto
): array {
    global $usePHPMailer;
    if ($usePHPMailer) {
        return _enviarSMTP($para, $paraNome, $assunto, $html, $texto);
    }
    return _enviarNativo($para, $assunto, $html);
}

function _enviarSMTP(
    string $para, string $paraNome,
    string $assunto, string $html, string $texto
): array {
    $mail = new PHPMailer(true);
    try {
        // Servidor
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = SMTP_USER;
        $mail->Password    = SMTP_PASS;
        $mail->SMTPSecure  = SMTP_SECURE === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = (int)SMTP_PORT;
        $mail->CharSet     = PHPMailer::CHARSET_UTF8;
        $mail->Timeout     = 15;

        // Debug apenas em development
        if (APP_ENV === 'development') {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        }

        // Remetente e destinatário
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($para, $paraNome);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        // Conteúdo
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body    = $html;
        $mail->AltBody = $texto;

        $mail->send();
        return ['ok' => true];

    } catch (MailException $e) {
        logErro('MAILER_SMTP', $mail->ErrorInfo);
        return ['ok' => false, 'erro' => $mail->ErrorInfo];
    }
}

function _enviarNativo(string $para, string $assunto, string $html): array
{
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    $ok = @mail($para, $assunto, $html, $headers);
    return $ok
        ? ['ok' => true]
        : ['ok' => false, 'erro' => 'mail() retornou false'];
}

// ══════════════════════════════════════════════════════
// TEMPLATE HTML — PACIENTE
// ══════════════════════════════════════════════════════
function templatePacienteHtml(array $d): string
{
    $tipo_icon = str_contains($d['tipo'], 'online') ? '💻' : '🏥';
    $cancelUrl = APP_BASE_URL . '/cancelar.php?token=' . urlencode($d['token']);
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Confirmação de Consulta</title>
</head>
<body style="margin:0;padding:0;background:#f0f9f8;font-family:'Segoe UI',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f9f8">
<tr><td align="center" style="padding:40px 16px">

  <table role="presentation" width="600" cellpadding="0" cellspacing="0"
    style="background:#ffffff;border-radius:20px;overflow:hidden;
           box-shadow:0 8px 40px rgba(0,60,56,.14);max-width:600px;width:100%">

    <!-- Header -->
    <tr><td style="background:linear-gradient(135deg,#005451 0%,#003f9c 100%);
                   padding:40px 48px;text-align:center">
      <div style="display:inline-block;width:56px;height:56px;background:rgba(255,255,255,.15);
                  border-radius:14px;line-height:56px;font-size:28px;margin-bottom:14px">✚</div>
      <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:700;letter-spacing:-.3px">
        AnestConsulta</h1>
      <p style="color:rgba(255,255,255,.65);margin:6px 0 0;font-size:13px">
        Avaliação Pré-Anestésica</p>
    </td></tr>

    <!-- Ícone sucesso -->
    <tr><td style="padding:40px 48px 0;text-align:center">
      <div style="width:80px;height:80px;background:#e6faf9;border-radius:50%;
                  display:inline-block;line-height:80px;font-size:42px;
                  border:3px solid #b3eee9;margin-bottom:20px">✅</div>
      <h2 style="color:#003d3b;margin:0 0 10px;font-size:22px;font-weight:700">
        Consulta Confirmada!</h2>
      <p style="color:#4a7f7e;margin:0;font-size:15px;line-height:1.6">
        Olá, <strong style="color:#003d3b">{$d['nome']}</strong>!<br>
        Sua consulta pré-anestésica foi registrada com sucesso.
      </p>
    </td></tr>

    <!-- Detalhes -->
    <tr><td style="padding:28px 48px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background:#f4fbfb;border-radius:14px;border:1px solid #d4eeec;
               overflow:hidden">
        <tr><td colspan="2" style="padding:16px 24px;background:#e6faf9;
                   border-bottom:1px solid #d4eeec">
          <span style="font-size:11px;font-weight:700;color:#006e6a;
                       text-transform:uppercase;letter-spacing:.08em">
            Detalhes do agendamento
          </span>
        </td></tr>
        <tr>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#4a7f7e;width:40%;vertical-align:top">
            👨‍⚕️ Médico</td>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#003d3b;font-weight:600">
            {$d['medico']}<br>
            <span style="font-weight:400;color:#4a7f7e;font-size:12px">
              {$d['especialidade']}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#4a7f7e">📅 Data</td>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#003d3b;font-weight:600">
            {$d['dia_semana']}, {$d['data']}</td>
        </tr>
        <tr>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#4a7f7e">🕐 Horário</td>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#003d3b;font-weight:600">
            {$d['horario']}h</td>
        </tr>
        <tr>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#4a7f7e">{$tipo_icon} Modalidade</td>
          <td style="padding:14px 24px;border-bottom:1px solid #e8f5f4;
                     font-size:13px;color:#003d3b;font-weight:600">{$d['tipo']}</td>
        </tr>
        <tr>
          <td style="padding:14px 24px;font-size:13px;color:#4a7f7e;vertical-align:top">
            🔬 Procedimento</td>
          <td style="padding:14px 24px;font-size:13px;color:#003d3b">
            {$d['cirurgia']}</td>
        </tr>
      </table>
    </td></tr>

    <!-- Documentos -->
    <tr><td style="padding:0 48px 28px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background:#fffbeb;border-radius:12px;border-left:4px solid #f59e0b">
        <tr><td style="padding:18px 20px">
          <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#92400e">
            ⚠️ Documentos necessários</p>
          <p style="margin:0;font-size:13px;color:#78350f;line-height:1.65">
            Separe com antecedência:<br>
            • Documento de identidade com foto<br>
            • Pedido cirúrgico / solicitação do médico<br>
            • Exames recentes (até 6 meses)<br>
            • Lista de medicamentos em uso<br>
            • Cartão do plano de saúde (se houver)
          </p>
        </td></tr>
      </table>
    </td></tr>

    <!-- Precisa alterar? -->
    <tr><td style="padding:0 48px 36px;text-align:center">
      <p style="font-size:13px;color:#4a7f7e;margin:0 0 16px">
        Precisa alterar ou cancelar? Responda este e-mail ou entre em contato:<br>
        <strong style="color:#003d3b">📞 (XX) XXXXX-XXXX</strong> &nbsp;|&nbsp;
        <strong style="color:#003d3b">💬 WhatsApp disponível</strong>
      </p>
      <a href="{$cancelUrl}"
         style="display:inline-block;background:#fee2e2;color:#b91c1c;
                text-decoration:none;padding:10px 24px;border-radius:50px;
                font-size:12px;font-weight:600;border:1px solid #fca5a5">
        Cancelar agendamento
      </a>
    </td></tr>

    <!-- Footer -->
    <tr><td style="background:#f4fbfb;padding:24px 48px;
                   border-top:1px solid #e0eeec;text-align:center">
      <p style="margin:0 0 6px;font-size:12px;color:#4a7f7e">
        AnestConsulta — Avaliação Pré-Anestésica</p>
      <p style="margin:0;font-size:11px;color:#7fa9a8">
        CNPJ 29.168.494/0001-08 &nbsp;|&nbsp;
        <a href="mailto:consulta@anestconsulta.com"
           style="color:#008c87;text-decoration:none">consulta@anestconsulta.com</a>
      </p>
      <p style="margin:8px 0 0;font-size:10px;color:#a0b8b7">
        Token: {$d['token']}</p>
    </td></tr>

  </table>
</td></tr>
</table>
</body></html>
HTML;
}

// ══════════════════════════════════════════════════════
// TEMPLATE TEXTO — PACIENTE (fallback)
// ══════════════════════════════════════════════════════
function templatePacienteTexto(array $d): string
{
    return <<<TEXT
AnestConsulta — Confirmação de Consulta
========================================

Olá, {$d['nome']}!

Sua consulta pré-anestésica foi registrada com sucesso.

DETALHES:
- Médico:      {$d['medico']} ({$d['especialidade']})
- Data:        {$d['dia_semana']}, {$d['data']}
- Horário:     {$d['horario']}h
- Modalidade:  {$d['tipo']}
- Procedimento: {$d['cirurgia']}

DOCUMENTOS NECESSÁRIOS:
- Documento de identidade com foto
- Pedido cirúrgico
- Exames recentes (até 6 meses)
- Lista de medicamentos em uso
- Cartão do plano de saúde

Precisa alterar ou cancelar? Responda este e-mail ou ligue:
(XX) XXXXX-XXXX | WhatsApp disponível

--
AnestConsulta | CNPJ 29.168.494/0001-08
consulta@anestconsulta.com
TEXT;
}

// ══════════════════════════════════════════════════════
// TEMPLATE HTML — MÉDICO
// ══════════════════════════════════════════════════════
function templateMedicoHtml(array $d): string
{
    $adminUrl = APP_BASE_URL . '/admin/admin.php';
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f9f8;font-family:'Segoe UI',Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:40px 16px">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0"
    style="background:#fff;border-radius:20px;overflow:hidden;
           box-shadow:0 8px 40px rgba(0,60,56,.14);max-width:600px;width:100%">
    <tr><td style="background:linear-gradient(135deg,#003d3b,#001a3a);padding:32px 48px">
      <h1 style="color:#fff;margin:0;font-size:18px;font-weight:700">
        📋 Novo Agendamento</h1>
      <p style="color:rgba(255,255,255,.55);margin:5px 0 0;font-size:13px">
        AnestConsulta — Notificação ao Médico</p>
    </td></tr>
    <tr><td style="padding:36px 48px">
      <p style="font-size:14px;color:#003d3b;margin:0 0 24px">
        Olá, <strong>{$d['medico']}</strong>!<br>
        Você tem um novo agendamento de consulta pré-anestésica.</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background:#f4fbfb;border-radius:12px;border:1px solid #d4eeec">
        <tr><td style="padding:13px 20px;border-bottom:1px solid #e0eeec">
          <table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:38%">Paciente</td>
            <td style="font-size:13px;color:#003d3b;font-weight:600">{$d['nome']}</td>
          </tr></table>
        </td></tr>
        <tr><td style="padding:13px 20px;border-bottom:1px solid #e0eeec">
          <table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:38%">E-mail</td>
            <td style="font-size:13px;color:#003d3b">{$d['email']}</td>
          </tr></table>
        </td></tr>
        <tr><td style="padding:13px 20px;border-bottom:1px solid #e0eeec">
          <table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:38%">Telefone</td>
            <td style="font-size:13px;color:#003d3b">{$d['telefone']}</td>
          </tr></table>
        </td></tr>
        <tr><td style="padding:13px 20px;border-bottom:1px solid #e0eeec">
          <table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:38%">Data / Horário</td>
            <td style="font-size:13px;color:#003d3b;font-weight:600">
              {$d['dia_semana']}, {$d['data']} às {$d['horario']}h</td>
          </tr></table>
        </td></tr>
        <tr><td style="padding:13px 20px;border-bottom:1px solid #e0eeec">
          <table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:38%">Modalidade</td>
            <td style="font-size:13px;color:#003d3b">{$d['tipo']}</td>
          </tr></table>
        </td></tr>
        <tr><td style="padding:13px 20px">
          <table width="100%"><tr>
            <td style="font-size:13px;color:#4a7f7e;width:38%;vertical-align:top">
              Procedimento</td>
            <td style="font-size:13px;color:#003d3b">{$d['cirurgia']}</td>
          </tr></table>
        </td></tr>
      </table>
      <div style="margin-top:24px;text-align:center">
        <a href="{$adminUrl}"
           style="display:inline-block;background:linear-gradient(135deg,#008c87,#003f9c);
                  color:#fff;text-decoration:none;padding:13px 32px;border-radius:50px;
                  font-size:13px;font-weight:700;letter-spacing:.02em">
          Ver no painel admin →
        </a>
      </div>
    </td></tr>
    <tr><td style="background:#f4fbfb;padding:20px 48px;
                   border-top:1px solid #e0eeec;text-align:center">
      <p style="margin:0;font-size:11px;color:#7fa9a8">
        © 2025 AnestConsulta | CNPJ 29.168.494/0001-08</p>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
HTML;
}

function templateMedicoTexto(array $d): string
{
    return <<<TEXT
AnestConsulta — Novo Agendamento
==================================

Olá, {$d['medico']}!

Você tem um novo agendamento:

- Paciente:    {$d['nome']}
- E-mail:      {$d['email']}
- Telefone:    {$d['telefone']}
- Data:        {$d['dia_semana']}, {$d['data']} às {$d['horario']}h
- Modalidade:  {$d['tipo']}
- Procedimento: {$d['cirurgia']}

Acesse o painel admin para confirmar:
{APP_BASE_URL}/admin/admin.php

--
AnestConsulta | CNPJ 29.168.494/0001-08
TEXT;
}
