# Contribuir para o MEPA CRM

## Antes de programar

1. Ler integralmente `mepa_crm_v1.1.1.md`.
2. Consultar as ADRs aplicáveis.
3. Consultar o Graphify para compreender estrutura e impacto.
4. Identificar o domínio e os dados afectados.
5. Criar ou alterar os testes adequados.
6. Actualizar a documentação relacionada.
7. Reconstruir ou actualizar o Graphify após mudanças estruturais.

Mudanças arquitecturais silenciosas são proibidas. Uma decisão nova ou incompatível deve ser validada institucionalmente, registada numa ADR e reflectida no documento canónico antes da implementação ser considerada concluída.

Não misturar código legado com a arquitectura nova sem decisão documentada. Não versionar secrets, dados pessoais reais, uploads reais, `.env`, `vendor`, `node_modules` ou builds.

## Qualidade

- manter API versionada e autorização no backend;
- preservar histórico em vez de sobrescrever relações anteriores;
- escrever testes proporcionais ao risco;
- manter documentação e skills internas coerentes com decisões aprovadas;
- usar português de Angola (`pt-AO`) nos textos funcionais.

