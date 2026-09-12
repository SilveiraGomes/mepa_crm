# Visão geral da arquitectura

Pessoa é a entidade central do MEPA CRM. Membro, familiar, obreiro, dirigente, participante de departamento, contacto evangelístico e aluno da MEPA Academia reutilizam a mesma identidade; a Academia admite também não membros, sem atribuir-lhes Número Único.

A estrutura institucional é uma árvore recursiva da Direcção Geral até à Congregação. Locais físicos, templos e propriedades são entidades distintas. Departamentos partilham Pessoa e associam-se à unidade apropriada, preservando históricos.

O financeiro integrado será baseado num ledger consistente com partidas dobradas. Transferências entre estruturas são relacionadas e conciliadas, sem duplicação de receita.

O frontend é uma PWA React/TypeScript/Vite mobile-first. O backend Laravel expõe uma API REST versionada em `/api/v1`. O offline futuro será selectivo; operações críticas permanecem online.

A autorização combina papel, unidade organizacional, departamento, tipo de dado e acção, sempre aplicada no backend. Dados pessoais, menores e finanças recebem protecção proporcional à sensibilidade.

A primeira produção visa shared hosting com Apache, PHP e MySQL/MariaDB. Configuração por ambiente, storage abstraído e separação entre frontend/backend permitem a evolução para VPS sem reescrever o domínio.

