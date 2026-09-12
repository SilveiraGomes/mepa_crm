# Ambiente local XAMPP

## Estado detectado em 2026-09-05

- Apache 2.4.58 disponível em `C:\xampp\apache`;
- PHP 8.0.30;
- projecto em `C:\xampp\htdocs\mepa-crm`.

O caminho directo provisório da API é:

```text
http://localhost/mepa-crm/apps/api/public/api/v1
```

Esta URL serve para arranque local e não é uma decisão arquitectural definitiva. Para reduzir exposição de ficheiros fora de `public`, a configuração recomendada é um Virtual Host, por exemplo `mepa-crm.local`, cujo `DocumentRoot` da API aponte para `apps/api/public`. Não alterar `httpd-vhosts.conf` nem o ficheiro `hosts` automaticamente; a equipa deverá aprovar e executar essa mudança no ambiente local.

O frontend em desenvolvimento é servido pelo Vite. Em produção, será compilado e publicado conforme o desenho de deploy aprovado para o provedor.

