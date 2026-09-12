# Regras de desenvolvimento

> Antes de qualquer programação consultar mepa_crm_v1.1.1.md.

> Antes de alteração estrutural consultar Graphify.

> Depois de alteração estrutural actualizar/reconstruir Graphify.

## Fluxo de decisão

1. Verificar documento canónico e ADRs.
2. Identificar domínios, dados, rotas, contratos e permissões afectados.
3. Consultar o grafo antes de refactorização transversal.
4. Implementar sem destruir histórico nem criar cadastros paralelos.
5. Testar e actualizar documentação, ADRs e skills afectadas.
6. Reconstruir o grafo após alterações estruturais.

Humanizer pode melhorar somente conteúdo textual destinado a pessoas. Não pode alterar código, SQL, JSON, números, contratos de API, rubricas oficiais, textos legais, nomes institucionais ou regras de negócio.

