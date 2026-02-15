<?php
// ══════════════════════════════════════════════
// AnesConsulta — Configuração do Banco de Dados
// ══════════════════════════════════════════════
// ⚠️  NUNCA suba este arquivo para o GitHub!
//     Adicione config.php ao .gitignore
// ══════════════════════════════════════════════

define('DB_HOST',     'localhost');
define('DB_NAME',     'anesconsulta');   // Nome do banco criado na Hostinger
define('DB_USER',     'SEU_USUARIO_MYSQL');    // Usuário MySQL da Hostinger
define('DB_PASS',     'SUA_SENHA_MYSQL');      // Senha MySQL da Hostinger
define('DB_CHARSET',  'utf8mb4');

// Configurações de e-mail (SMTP)
define('MAIL_HOST',     'smtp.hostinger.com');
define('MAIL_PORT',     587);
define('MAIL_USER',     'noreply@seudominio.com.br');
define('MAIL_PASS',     'SENHA_DO_EMAIL');
define('MAIL_FROM',     'noreply@seudominio.com.br');
define('MAIL_FROM_NAME','AnesConsulta');

// URL base do site
define('BASE_URL', 'https://SEU_DOMINIO.com.br');

// Ambiente: 'development' ou 'production'
define('APP_ENV', 'production');

// ── Conexão PDO ────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('Erro de conexão: ' . $e->getMessage());
            }
            http_response_code(500);
            die(json_encode(['erro' => 'Erro interno do servidor.']));
        }
    }
    return $pdo;
}
