# MEPA Gestão

CRM/ChMS/ERP eclesiástico nacional da Missão Evangélica Pentecostal de Angola (MEPA). O projecto procura criar uma fonte única, auditável e escalável para pessoas, estrutura institucional, ministérios, departamentos, academia, finanças, comunicação e estatística.

## Estado

Fundação técnica P0. Esta etapa contém somente o monorepo, aplicações-base, endpoint técnico, PWA mínima, documentação arquitectural e regras de desenvolvimento. Não contém módulos de negócio nem dados reais.

> Antes de qualquer programação, ler integralmente [`mepa_crm_v1.1.1.md`](mepa_crm_v1.1.1.md). O documento é a fonte canónica deste repositório.

## Arquitectura e tecnologias

- `apps/api`: PHP 8.0 / Laravel 9, API REST versionada em `/api/v1`;
- `apps/web`: React, TypeScript, Vite e fundação PWA mobile-first;
- base de dados prevista: MySQL 8.x ou MariaDB compatível;
- ambiente inicial: Windows, XAMPP e Apache, com produção inicial em shared hosting;
- evolução prevista: VPS sem reescrita do domínio.

Pessoa é a entidade central. O backend aplicará autorização por papel, unidade organizacional, departamento, tipo de dado e acção. A hierarquia institucional será uma árvore recursiva e todos os departamentos reutilizarão o mesmo cadastro de Pessoa.

## Requisitos locais detectados

- PHP 8.0.30;
- Composer 2.8.9;
- Node.js 20.16.0 e npm 10.8.1;
- Apache 2.4.58 no XAMPP;
- Git 2.55.0.

## Iniciar o backend

```powershell
cd apps/api
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan serve
```

O endpoint técnico fica disponível em `GET /api/v1/health`. No XAMPP, pode também ser servido por `http://localhost/mepa-crm/apps/api/public`; para desenvolvimento regular, recomenda-se posteriormente um Virtual Host que aponte directamente para `apps/api/public`.

## Iniciar o frontend

```powershell
cd apps/web
Copy-Item .env.example .env
npm install
npm run dev
```

`VITE_API_URL` centraliza a base da API. A URL de XAMPP presente no exemplo é provisória e não define a arquitectura final.

## Graphify e skills

Graphify deve ser consultado antes de alterações estruturais e reconstruído depois delas. Quando o CLI estiver funcional, executar `graphify .` na raiz e não indexar dependências, builds, uploads ou secrets. As skills versionadas ficam em `.agents/skills/`; Humanizer só pode actuar em conteúdo textual destinado a pessoas e nunca em código, SQL, JSON, números, contratos, nomes oficiais, textos legais ou regras de negócio.

## Estrutura resumida

```text
apps/          API Laravel e frontend React/Vite
database/      dados de referência, importações e amostras não reais
docs/          ADRs, arquitectura, API, base de dados, deploy, segurança e UI
scripts/       automação futura de desenvolvimento, deploy, backup e migração
tests/         integração, E2E e fixtures transversais
.agents/       skills portáteis do projecto
.github/       integração contínua e templates de issues
storage-dev/   armazenamento local descartável
```

## Próximos passos

A proposta P0.2 de arquitectura de dados está em [docs/database](docs/database/README.md). A próxima etapa é a auditoria independente e o fecho das decisões abertas; matriz de permissões, fluxos, design system e contratos de API seguem o roadmap. Não iniciar módulos funcionais antes da aprovação dos artefactos aplicáveis.

