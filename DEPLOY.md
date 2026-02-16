# ✅ AnestConsulta — Checklist de Deploy & Segurança

## 1. Banco de Dados
- [ ] Importar `sql/schema.sql` no phpMyAdmin da Hostinger
- [ ] Confirmar que todas as tabelas foram criadas:
      agendamentos, medicos, rate_limit, email_logs, admin_usuarios
- [ ] Trocar senha padrão do admin no painel (`Admin@2025`)

## 2. Arquivos no Servidor
- [ ] `index.html` em `public_html/`
- [ ] `php/agendamentos.php` em `public_html/php/`
- [ ] `php/config.php` em `public_html/php/` (upload MANUAL — não vai pelo Git!)
- [ ] `php/mailer.php` em `public_html/php/`
- [ ] `admin/admin.php` em `public_html/admin/`
- [ ] `vendor/` após rodar `composer install` localmente

## 3. Composer (PHPMailer)
```bash
composer install --no-dev --optimize-autoloader
```
Depois faça upload da pasta `vendor/` via FTP/Terminus.

## 4. Segurança
- [ ] `config.php` NÃO está no GitHub (verificar com `git status`)
- [ ] `.env` NÃO está no GitHub
- [ ] Pasta `logs/` criada com permissão de escrita: `chmod 775 logs/`
- [ ] Pasta `logs/` bloqueada no .htaccess:
      ```
      <Files "*.log">
        Order allow,deny
        Deny from all
      </Files>
      ```
- [ ] HTTPS ativo no domínio (hpanel → SSL)
- [ ] Senhas fortes em todos os serviços

## 5. Variáveis de Ambiente
| Variável          | Onde pegar                          |
|-------------------|-------------------------------------|
| DB_HOST           | Sempre `localhost` na Hostinger     |
| DB_NAME           | hpanel → Bancos de Dados → MySQL    |
| DB_USER           | hpanel → Bancos de Dados → MySQL    |
| DB_PASS           | hpanel → Bancos de Dados → MySQL    |
| SMTP_HOST         | `smtp.hostinger.com`                |
| SMTP_PORT         | `587`                               |
| SMTP_USER         | hpanel → E-mails → sua conta        |
| SMTP_PASS         | hpanel → E-mails → sua conta        |
| APP_BASE_URL      | Seu domínio com https://            |

## 6. Testar Localmente (XAMPP/MAMP)
```bash
# 1. Clone o repositório
git clone https://github.com/fernando-js/anestconsulta.git
cd anestconsulta

# 2. Instalar dependências
composer install

# 3. Criar banco local
mysql -u root -p < sql/schema.sql

# 4. Editar config.php com dados locais
# DB_HOST=localhost, DB_USER=root, DB_PASS=

# 5. Subir servidor
php -S localhost:8000

# 6. Acessar
# http://localhost:8000
# http://localhost:8000/admin/admin.php
```

## 7. Testar o Endpoint
```bash
curl -X POST https://anestconsulta.com/php/agendamentos.php \
  -H "Content-Type: application/json" \
  -d '{
    "nome": "Teste Silva",
    "email": "teste@teste.com",
    "telefone": "31999999999",
    "cpf": "529.982.247-25",
    "nascimento": "1990-01-15",
    "medico_id": 5,
    "tipo": "online",
    "data": "2025-12-20",
    "horario": "09:00",
    "cirurgia": "Colecistectomia laparoscópica"
  }'
```
Resposta esperada:
```json
{
  "ok": true,
  "id": 1,
  "message": "Agendamento realizado com sucesso! Verifique seu e-mail.",
  "email_enviado": true
}
```

## 8. Códigos de Erro da API
| HTTP | code                  | Causa                          |
|------|-----------------------|--------------------------------|
| 400  | INVALID_JSON          | Body não é JSON válido         |
| 405  | METHOD_NOT_ALLOWED    | Não é POST                     |
| 409  | HORARIO_INDISPONIVEL  | Horário já ocupado             |
| 422  | VALIDATION_ERROR      | Campos inválidos               |
| 429  | RATE_LIMIT            | Muitas tentativas do mesmo IP  |
| 500  | DB_ERROR              | Erro no banco de dados         |
