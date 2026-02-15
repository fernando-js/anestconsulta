# ✚ AnesConsulta — Site de Agendamento Pré-Anestésico

Sistema completo de agendamento de consultas pré-anestésicas com frontend em HTML/CSS/JS e backend em PHP + MySQL.

---

## 📁 Estrutura do Projeto

```
anesconsulta/
├── index.html          ← Frontend (site principal)
├── composer.json       ← Dependências PHP
├── .gitignore
├── php/
│   ├── config.php      ← ⚠️ Configurações (NÃO subir no GitHub)
│   ├── agendamento.php ← API que recebe o formulário
│   └── mailer.php      ← Envio de e-mails
├── admin/
│   └── admin.php       ← Painel administrativo
└── sql/
    └── schema.sql      ← Estrutura do banco de dados
```

---

## 🚀 Passo a Passo para Deploy na Hostinger

### 1. Banco de Dados MySQL

No **hpanel.hostinger.com**:
1. Vá em **Bancos de Dados → MySQL**
2. Clique em **Criar banco de dados**
3. Anote: nome do banco, usuário e senha
4. Abra o **phpMyAdmin** e execute o arquivo `sql/schema.sql`

### 2. Configurar o `config.php`

Edite o arquivo `php/config.php` com suas credenciais:

```php
define('DB_HOST',  'localhost');
define('DB_NAME',  'seu_banco');    // ← do hpanel
define('DB_USER',  'seu_usuario');  // ← do hpanel
define('DB_PASS',  'sua_senha');    // ← do hpanel

define('MAIL_HOST', 'smtp.hostinger.com');
define('MAIL_USER', 'noreply@seudominio.com.br');
define('MAIL_PASS', 'senha_do_email');
define('BASE_URL',  'https://seudominio.com.br');
```

> ⚠️ **IMPORTANTE:** `config.php` está no `.gitignore` e **nunca** deve ser enviado ao GitHub!

### 3. Instalar PHPMailer (via Composer)

Se tiver SSH na Hostinger:
```bash
cd public_html
composer install --no-dev --optimize-autoloader
```

Sem SSH: faça upload manual da pasta `vendor/` após rodar `composer install` localmente.

### 4. Subir os arquivos

**Estrutura final no `public_html`:**
```
public_html/
├── index.html
├── composer.json
├── vendor/             ← após composer install
├── php/
│   ├── config.php      ← preencher com suas credenciais
│   ├── agendamento.php
│   └── mailer.php
└── admin/
    └── admin.php
```

### 5. Conectar GitHub → Hostinger (deploy automático)

1. No hpanel → **Git** → **Criar repositório**
2. Conecte com seu repositório GitHub
3. Branch: `main`
4. Pasta de deploy: `public_html`
5. A cada `git push`, o Hostinger atualiza automaticamente ✅

---

## 🔐 Primeiro Acesso ao Painel Admin

URL: `https://seudominio.com.br/admin/admin.php`

| Campo | Valor padrão |
|-------|-------------|
| E-mail | `admin@anesconsulta.com.br` |
| Senha | `Admin@2025` |

> ⚠️ **Troque a senha imediatamente** após o primeiro login!

Para gerar um novo hash de senha:
```php
echo password_hash('NovaSenha@2025', PASSWORD_BCRYPT);
// Cole o resultado na coluna senha_hash do admin_usuarios
```

---

## 🔗 Integração Frontend → Backend

O `index.html` envia os dados para `php/agendamento.php` via `fetch()`:

```javascript
fetch('/php/agendamento.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ nome, email, cpf, ... })
})
```

---

## 📦 Dependências

| Pacote | Versão | Uso |
|--------|--------|-----|
| phpmailer/phpmailer | ^6.9 | Envio de e-mails via SMTP |

---

## 🛡️ Segurança

- ✅ Prepared statements (proteção contra SQL Injection)
- ✅ Validação e sanitização de todos os inputs
- ✅ Validação de CPF com algoritmo oficial
- ✅ Token único por agendamento (64 chars hex)
- ✅ `config.php` fora do Git
- ✅ Senha admin com `password_hash()` bcrypt
- ✅ Rate limiting via IP recomendado (adicionar com `.htaccess`)

---

## 📧 Configuração de E-mail na Hostinger

1. hpanel → **E-mails → Contas de E-mail**
2. Crie `noreply@seudominio.com.br`
3. Use as credenciais em `config.php`
4. SMTP: `smtp.hostinger.com` porta `587` (STARTTLS)

---

## 📄 Licença

Projeto privado — uso restrito ao proprietário.
