---
name: mepa-data
description: Apply approved MEPA CRM data rules to imports, deduplication, member numbering, migrations, statistics, history, and financial integrity.
---

# MEPA data invariants

- Read `mepa_crm_v1.1.1.md`, relevant ADRs and approved data contracts before changing data structures.
- Route imports through staging, normalization, deduplication, mapping, validation, preview, approval, import and reporting.
- Preserve legacy values and source provenance; never invent missing historical dates.
- The future member-number format is `MEPAAAMMSSSSSS`; its six-digit sequence is national and continuous and never resets by month or year.
- Generate member numbers only in a transaction with concurrency control; do not implement the generator until its scheduled phase.
- Statistics derive from the central data model; distinguish real-time views from approved immutable snapshots.
- Preserve relationship and assignment history rather than overwriting it.
- Financial data must support ledger integrity and prevent duplicate revenue on internal transfers.


- A arquitectura P0.2 em docs/database é proposta para auditoria; não implementar migrations de negócio antes da aprovação aplicável. Constraints de agregado requerem serviço/transacção com locks de âncoras preexistentes; não presumir UNIQUE parcial ou CHECK entre tabelas.
