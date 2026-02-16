-- ══════════════════════════════════════════════════════
-- AnestConsulta — Schema MySQL v2
-- Stack: PHP 8.1+ | MySQL 8.0+
-- ══════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `anestconsulta`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `anestconsulta`;

-- ── Tabela de médicos ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `medicos` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `nome`          VARCHAR(120)     NOT NULL,
  `crm`           VARCHAR(30)      NOT NULL,
  `especialidade` VARCHAR(80)      NOT NULL,
  `email`         VARCHAR(120)     NOT NULL,
  `ativo`         TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_medicos_crm` (`crm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `medicos` (`nome`, `crm`, `especialidade`, `email`) VALUES
  ('Dr. Ricardo Mendes',           'CRM-SP 123456', 'Anestesia Geral',      'ricardo@anestconsulta.com'),
  ('Dra. Beatriz Carvalho',        'CRM-SP 234567', 'Anestesia Pediátrica', 'beatriz@anestconsulta.com'),
  ('Dr. Paulo Santos',             'CRM-SP 345678', 'Anestesia Cardíaca',   'paulo@anestconsulta.com'),
  ('Dra. Ana Lima',                'CRM-SP 456789', 'Anestesia Obstétrica', 'ana@anestconsulta.com'),
  ('Dr. Fernando Xavier Ferreira', 'CRM-MG 30746',  'Anestesiologia',       'consulta@anestconsulta.com');

-- ── Tabela principal de agendamentos ─────────────────
CREATE TABLE IF NOT EXISTS `agendamentos` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `nome`           VARCHAR(120)     NOT NULL,
  `email`          VARCHAR(120)     NOT NULL,
  `telefone`       VARCHAR(20)      NOT NULL,
  `cpf`            VARCHAR(11)      NOT NULL,
  `data_nascimento`DATE             NOT NULL,
  `plano_saude`    VARCHAR(60)          NULL DEFAULT NULL,
  `medico_id`      BIGINT UNSIGNED  NOT NULL,
  `tipo_consulta`  ENUM('online','presencial') NOT NULL,
  `data_consulta`  DATE             NOT NULL,
  `horario`        TIME             NOT NULL,
  `cirurgia`       VARCHAR(200)     NOT NULL,
  `observacoes`    TEXT                 NULL DEFAULT NULL,
  `status`         ENUM('pendente','confirmado','cancelado') NOT NULL DEFAULT 'pendente',
  -- Anti-spam
  `honeypot`       VARCHAR(50)          NULL DEFAULT NULL,
  `ip_origem`      VARCHAR(45)          NULL DEFAULT NULL,
  -- E-mail tracking
  `email_status`   ENUM('pendente','enviado','falhou') NOT NULL DEFAULT 'pendente',
  `email_tentativas` TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `email_enviado_em` TIMESTAMP            NULL DEFAULT NULL,
  `email_erro`     TEXT                 NULL DEFAULT NULL,
  -- Token para cancelamento/alteração
  `token`          CHAR(64)         NOT NULL,
  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agendamentos_token`   (`token`),
  INDEX `idx_agendamentos_email`       (`email`),
  INDEX `idx_agendamentos_data`        (`data_consulta`),
  INDEX `idx_agendamentos_status`      (`status`),
  INDEX `idx_agendamentos_medico`      (`medico_id`),
  INDEX `idx_agendamentos_ip`          (`ip_origem`),
  INDEX `idx_agendamentos_created`     (`created_at`),
  CONSTRAINT `fk_agendamentos_medico`
    FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rate limit por IP ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limit` (
  `ip`         VARCHAR(45)      NOT NULL,
  `endpoint`   VARCHAR(60)      NOT NULL DEFAULT 'agendamento',
  `tentativas` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `janela_ini` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Log de e-mails ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agendamento_id` BIGINT UNSIGNED NOT NULL,
  `tipo`           ENUM('confirmacao_paciente','notificacao_medico','lembrete') NOT NULL,
  `destinatario`   VARCHAR(120)    NOT NULL,
  `assunto`        VARCHAR(200)    NOT NULL,
  `enviado`        TINYINT(1)      NOT NULL DEFAULT 0,
  `erro`           TEXT                NULL DEFAULT NULL,
  `enviado_em`     TIMESTAMP           NULL DEFAULT NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email_logs_agendamento` (`agendamento_id`),
  CONSTRAINT `fk_email_logs_agendamento`
    FOREIGN KEY (`agendamento_id`) REFERENCES `agendamentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admin ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admin_usuarios` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`         VARCHAR(80)     NOT NULL,
  `email`        VARCHAR(120)    NOT NULL,
  `senha_hash`   VARCHAR(255)    NOT NULL,
  `ativo`        TINYINT(1)      NOT NULL DEFAULT 1,
  `ultimo_login` TIMESTAMP           NULL DEFAULT NULL,
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Senha padrão: Admin@2025 — TROQUE após primeiro login!
INSERT INTO `admin_usuarios` (`nome`, `email`, `senha_hash`) VALUES
  ('Administrador', 'consulta@anestconsulta.com',
   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

-- ── Event para limpar rate_limit antigo (MySQL 8+) ────
SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS `limpar_rate_limit`
  ON SCHEDULE EVERY 1 HOUR
  DO DELETE FROM `rate_limit`
     WHERE `janela_ini` < DATE_SUB(NOW(), INTERVAL 1 HOUR);
