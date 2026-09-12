---
name: mepa-domain
description: Apply the approved MEPA CRM domain invariants when designing or changing identities, membership, organizational structure, departments, transfers, finance, or Academy behavior.
---

# MEPA domain invariants

- Read `mepa_crm_v1.1.1.md` before implementation; it is the canonical project baseline.
- Pessoa is the central identity. Membership and other participation are relations or states, not duplicate people records.
- Departments reuse Pessoa and never create parallel people databases.
- A member retains the approved unique member number across transfers, promotions and organizational changes; preserve any legacy number separately.
- Organizational units form a recursive national tree. Do not create a database per province or level.
- Preserve history for transfers, assignments, status and relationships; do not silently overwrite the past.
- Internal financial transfers are related movements and must not duplicate revenue.
- MEPA Academia accepts members and non-members; a non-member does not receive a member number.
- Do not invent business rules. Record approved structural decisions in ADRs and update affected project skills.


- Centro Geral é opcional (zero ou um por Município). Município activo tem um ou mais Centros; todos dependem do Centro Geral quando existe, ou directamente do Município quando não existe. Congregação depende de Centro. Ver ADR 0006.
- O modelo P0.2 é proposta para auditoria independente; decisões técnicas propostas não são regras institucionalmente aprovadas.

## P0.2-F: fonte da árvore e ocupação

Pares de tipos aprovados são mantidos em docs/database/unit_parent_rules.json; não hardcodar listas em vários serviços. DRAFT/ACTIVE/CLOSED já existentes: mínimo municipal aplica-se a ACTIVE; reorganização é atómica sob NATIONAL_TREE. Catálogo/raízes territoriais D-12 permanecem BLOCKED. occupancy_status é cache para interface; períodos de ministerial_assignments / department_appointments são fonte para relatório oficial/auditoria/autorização. Ver docs/database/04_database_constraints.md.
## D-01 Accepted

id e PK BIGINT UNSIGNED AUTO_INCREMENT; FKs normais referenciam id numerico. public_id ULID selectivo, CHAR(26) ascii/ascii_bin, imutavel, UNIQUE, aplicacao e nao reutilizavel; aplicabilidade por tabela no dicionario. Numero MEPA nao e PK/FK estrutural nem substitui public_id. ADR 0009 Accepted / D-01 RESOLVED; demais decisoes permanecem abertas.
