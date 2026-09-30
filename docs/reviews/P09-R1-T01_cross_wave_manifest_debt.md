# P09-R1-T01: dívida transversal dos manifests físicos

## Classificação

- Tipo: TEST_INFRA
- Severidade: LOW/WARN
- Estado: OPEN
- Relação com P0.9: NONBLOCKING
- Origem: P0.9-R1

## Problema

`wave1_manifest.json`, `wave3_manifest.json` e `wave4_manifest.json` podem fixar o hash histórico `237478eba6accf72c150f6268ab22c448f9937bf11b394c9e273b4c71157358e` do catálogo anterior ao commit `21f4175`. A remediação do manifest Wave 2 mostrou que o hash actual é `9da2b2f72bb74fd1b655961775a5647efce65368c4246c2ec9879cc53c757158`.

Esta dívida não afecta a evidência focal de Membership usada em P0.9-R2. Nenhum manifest Wave 1, 3 ou 4 foi alterado ou validado nesta re-auditoria.

## Trabalho posterior

Para cada wave afectada:

1. reproduzir o manifest com o gerador canónico da wave;
2. provar que migrations e stats permanecem byte-idênticos;
3. preservar somente campos históricos autorizados de execução;
4. executar o respectivo teste físico numa base MySQL descartável;
5. versionar a evidência numa fase transversal própria.

Critério de fecho: gerador reproduzível, diff limitado ao hash stale e suite física da wave com 0 FAIL e 0 SKIPPED.
