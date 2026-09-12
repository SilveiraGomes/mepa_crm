# Número Único de Membro v2

**Status:** Accepted  
**Data:** 2026-09-05

## Contexto

O número legado codificava cargo, localização, nível e sequência, tornando-o instável perante alterações institucionais.

## Decisão

Adoptar o formato `MEPAAAMMSSSSSS`:

- `MEPA`: instituição;
- `AA`: ano da emissão/admissão aprovada;
- `MM`: mês da emissão/admissão aprovada;
- `SSSSSS`: contador nacional contínuo com seis dígitos.

O contador não reinicia na mudança do mês nem do ano. Ano e mês identificam o período da emissão; a sequência é nacional e contínua. Exemplo conceptual:

```text
MEPA2609000001
MEPA2610000002
MEPA2610000003
```

O número não inclui cargo, província, município, nível ou congregação. O número anterior será preservado futuramente como legado e nunca sobrescrito. O gerador não é implementado nesta etapa.

## Consequências

O número permanece imutável em transferências, promoções e mudanças de estrutura. A geração futura deverá ocorrer numa transacção com controlo de concorrência e unicidade da combinação completa.

## Referência

`mepa_crm_v1.1.1.md`, secções 8, 42 e 56.


## Revisão documental P0.2 — 2026-09-12

Estado Accepted preservado. Aprofundamento técnico proposto em docs/database/01_database_principles.md e 04_database_constraints.md; não constitui nova aprovação. Alternativas, locks e limitações de infraestrutura/identidade constam desses documentos. Migrations de negócio continuam fora desta fase.
