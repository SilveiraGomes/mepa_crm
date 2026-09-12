# Prototipo de arquitectura P0.2-F

Este directorio nao e codigo de producao. Nao carrega .env e nao contem migrations finais. PHP 64-bit com PDO MySQL e proc_open e necessario. Fixtures pequenas, contas e pessoas sinteticas.

Execucao reproduzivel no Windows/XAMPP:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/architecture/run-isolated.ps1
```

O wrapper exige porta 33079 livre, cria datadir novo aleatorio em TEMP, inicia mysqld sem defaults/servico numa janela oculta, cria apenas p02f_architecture, executa e termina apenas o processo que iniciou. Nao actualiza MySQL, nao interrompe o XAMPP existente e nao apaga directorios recursivamente. Logs/datadir ficam no TEMP para diagnostico. root sem password e exclusivo do fixture efemero ligado a 127.0.0.1; nao e configuracao de producao. O wrapper nao usa mysqladmin shutdown contra porta sem verificar ownership do processo.

Alternativa com instancia sintetica ja criada e explicitamente verificada:

```powershell
$env:ARCH_DB_ALLOW_SYNTHETIC='1'
$env:ARCH_DB_DSN='mysql:host=127.0.0.1;port=33079;dbname=p02f_architecture;charset=utf8mb4'
& C:/xampp/php/php.exe tests/architecture/run.php
```

O runner recusa qualquer outro DSN e exige opt-in. Recria as tabelas proto_* nessa base; nunca usar o nome/porta guardados para um servidor de negocio. Exit 0 so com todos os testes PASS; exit 1 falha de teste; exit 2 falha de setup/guarda. JSON inclui PIDs, tempos de espera e IDs iguais em SEND/RECEIVE, IDs diferentes nas publicacoes independentes e production_qualification=false.

Executar tambem o diagnostico separado: `php scripts/check-database-capabilities.php --self-test` (8 casos de versao) e preflight com DSN explicito. MariaDB 10.4.32 local deve obter DEPLOYMENT_BLOCKER; passar fixtures nao homologa esse motor para producao.