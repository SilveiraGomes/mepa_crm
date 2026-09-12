# Pessoa como entidade central

**Status:** Accepted  
**Data:** 2026-09-05

## Contexto

Uma pessoa pode relacionar-se com a MEPA como visitante, contacto evangelístico, aluno externo, responsável de menor, candidato, membro, obreiro ou dirigente. Bases separadas criariam duplicação e históricos incompatíveis.

## Decisão

Pessoa será a identidade central. Membro, aluno, participante, dirigente e integrante de departamento serão relações ou estados da mesma Pessoa. Departamentos não manterão cadastros paralelos.

## Consequências

Uma pessoa terá um identificador técnico único e poderá acumular relações sem duplicação. Deduplicação, privacidade e autorização devem considerar o cadastro transversal.

## Referência

`mepa_crm_v1.1.1.md`, secções 4, 7, 10 e 56.


## Revisão documental P0.2 — 2026-09-12

Estado Accepted preservado. Aprofundamento técnico proposto em docs/database/01_database_principles.md e 04_database_constraints.md; não constitui nova aprovação. Alternativas, locks e limitações de infraestrutura/identidade constam desses documentos. Migrations de negócio continuam fora desta fase.
