-- ══════════════════════════════════════════════════════
-- AnestConsulta — Tabelas do Painel do Paciente
-- Execute após o schema.sql principal
-- ══════════════════════════════════════════════════════

USE `anestconsulta`;

-- ── Tabela de pacientes (usuários do painel) ──────────
CREATE TABLE IF NOT EXISTS `pacientes` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `nome`            VARCHAR(120)     NOT NULL,
  `email`           VARCHAR(120)     NOT NULL,
  `senha_hash`      VARCHAR(255)     NOT NULL,
  `telefone`        VARCHAR(20)          NULL DEFAULT NULL,
  `cpf`             VARCHAR(11)          NULL DEFAULT NULL,
  `data_nascimento` DATE                 NULL DEFAULT NULL,
  `plano_saude`     VARCHAR(60)          NULL DEFAULT NULL,
  `avatar_inicial`  CHAR(2)          NOT NULL DEFAULT 'PA',
  -- Verificação de e-mail
  `email_verificado`    TINYINT(1)   NOT NULL DEFAULT 0,
  `token_verificacao`   VARCHAR(64)      NULL DEFAULT NULL,
  -- Recuperação de senha
  `token_reset`         VARCHAR(64)      NULL DEFAULT NULL,
  `token_reset_expira`  DATETIME         NULL DEFAULT NULL,
  -- Controle
  `ativo`           TINYINT(1)       NOT NULL DEFAULT 1,
  `ultimo_acesso`   DATETIME             NULL DEFAULT NULL,
  `created_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pacientes_email` (`email`),
  UNIQUE KEY `uq_pacientes_cpf`   (`cpf`),
  INDEX `idx_pacientes_token_reset`     (`token_reset`),
  INDEX `idx_pacientes_token_verif`     (`token_verificacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sessões do paciente ───────────────────────────────
CREATE TABLE IF NOT EXISTS `paciente_sessoes` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `paciente_id`  BIGINT UNSIGNED  NOT NULL,
  `token`        VARCHAR(64)      NOT NULL,
  `ip`           VARCHAR(45)          NULL,
  `user_agent`   VARCHAR(255)         NULL,
  `expira_em`    DATETIME         NOT NULL,
  `created_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessoes_token` (`token`),
  INDEX `idx_sessoes_paciente`   (`paciente_id`),
  CONSTRAINT `fk_sessoes_paciente`
    FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Adicionar paciente_id na tabela agendamentos ──────
ALTER TABLE `agendamentos`
  ADD COLUMN `paciente_id` BIGINT UNSIGNED NULL DEFAULT NULL
    AFTER `medico_id`,
  ADD CONSTRAINT `fk_agendamentos_paciente`
    FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`)
    ON DELETE SET NULL;
