-- ══════════════════════════════════════════════
-- AnestConsulta — Schema do Banco de Dados MySQL
-- ══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS anesconsulta
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE anesconsulta;

-- ── Tabela de médicos ──────────────────────────
CREATE TABLE IF NOT EXISTS medicos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(100) NOT NULL,
  crm         VARCHAR(30)  NOT NULL UNIQUE,
  especialidade VARCHAR(80) NOT NULL,
  email       VARCHAR(120) NOT NULL,
  ativo       TINYINT(1)   DEFAULT 1,
  criado_em   DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO medicos (nome, crm, especialidade, email) VALUES
  ('Dr. Ricardo Mendes',           'CRM-SP 123456', 'Anestesia Geral',      'ricardo@anestconsulta.com'),
  ('Dra. Beatriz Carvalho',        'CRM-SP 234567', 'Anestesia Pediátrica', 'beatriz@anestconsulta.com'),
  ('Dr. Paulo Santos',             'CRM-SP 345678', 'Anestesia Cardíaca',   'paulo@anestconsulta.com'),
  ('Dra. Ana Lima',                'CRM-SP 456789', 'Anestesia Obstétrica', 'ana@anestconsulta.com'),
  ('Dr. Fernando Xavier Ferreira', 'CRM-MG 30746',  'Anestesiologia',       'consulta@anestconsulta.com');

-- ── Tabela principal de agendamentos ──────────
CREATE TABLE IF NOT EXISTS agendamentos (
  id              INT AUTO_INCREMENT PRIMARY KEY,

  -- Dados do paciente
  nome            VARCHAR(120) NOT NULL,
  cpf             VARCHAR(14)  NOT NULL,
  email           VARCHAR(120) NOT NULL,
  telefone        VARCHAR(20)  NOT NULL,
  data_nascimento DATE         NOT NULL,
  plano_saude     VARCHAR(60)  DEFAULT NULL,

  -- Dados do agendamento
  medico_id       INT          NOT NULL,
  tipo_consulta   ENUM('online','presencial') NOT NULL,
  data_consulta   DATE         NOT NULL,
  horario         TIME         NOT NULL,
  cirurgia        VARCHAR(200) NOT NULL,
  observacoes     TEXT         DEFAULT NULL,

  -- Controle interno
  status          ENUM('pendente','confirmado','cancelado','realizado') DEFAULT 'pendente',
  token           VARCHAR(64)  NOT NULL UNIQUE,
  ip_origem       VARCHAR(45)  DEFAULT NULL,
  criado_em       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (medico_id) REFERENCES medicos(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Tabela de usuários admin ───────────────────
CREATE TABLE IF NOT EXISTS admin_usuarios (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(80)  NOT NULL,
  email       VARCHAR(120) NOT NULL UNIQUE,
  senha_hash  VARCHAR(255) NOT NULL,
  ativo       TINYINT(1)   DEFAULT 1,
  ultimo_login DATETIME    DEFAULT NULL,
  criado_em   DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Senha padrão: Admin@2025 (TROQUE após o primeiro login!)
INSERT INTO admin_usuarios (nome, email, senha_hash) VALUES
  ('Administrador', 'admin@anestconsulta.com',
   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

-- ── Tabela de log de e-mails ──────────────────
CREATE TABLE IF NOT EXISTS email_logs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  agendamento_id  INT         NOT NULL,
  tipo            ENUM('confirmacao_paciente','notificacao_medico','lembrete') NOT NULL,
  destinatario    VARCHAR(120) NOT NULL,
  enviado         TINYINT(1)  DEFAULT 0,
  erro            TEXT        DEFAULT NULL,
  enviado_em      DATETIME    DEFAULT NULL,
  criado_em       DATETIME    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (agendamento_id) REFERENCES agendamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Índices de performance ────────────────────
CREATE INDEX idx_agendamentos_data    ON agendamentos(data_consulta);
CREATE INDEX idx_agendamentos_medico  ON agendamentos(medico_id);
CREATE INDEX idx_agendamentos_status  ON agendamentos(status);
CREATE INDEX idx_agendamentos_cpf     ON agendamentos(cpf);
CREATE INDEX idx_agendamentos_token   ON agendamentos(token);
