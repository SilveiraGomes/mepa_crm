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

`mepa_crm_v1.0.1.md`, secções 4, 7, 10 e 56.

