---
name: mepa-security
description: Apply MEPA CRM security invariants when work touches authorization, personal data, minors, finance, auditing, uploads, credentials, or public exposure.
---

# MEPA security invariants

- Read `mepa_crm_v1.1.1.md` and applicable ADRs before implementation.
- Enforce authorization in the backend using role, organizational scope, department, data type and action.
- Apply least privilege and financial segregation of duties.
- Treat personal data as sensitive; protect minors and financial information with stronger restrictions.
- Audit relevant operations with actor, time, origin, entity, action, before/after values and reason when applicable.
- Never version secrets, real uploads or production personal data.
- Public credential validation must expose only approved minimum data and use opaque tokens.
- Validate uploads and avoid storing heavy files as database BLOB/Base64 values.


- Concessão papel+scope deve ser avaliada conjuntamente, sem combinar papel de uma unidade com scope de outra. Publicação de histórico/storage segue classificação e tombstone; retenção depende de política aprovada, não de cascade.

## P0.2-F: revogação e auditoria

Arquivo de user ou retirada da última concessão activa deve revogar auth_sessions no mesmo commit, sob lock users.id; emissão/refresh seguem essa âncora. Cada request verifica actor activo e sessão/concessão no servidor. Operações sensíveis revalidam sob lock antes do efeito. CHECKIN_DENIED é auditável com sessão, actor real e código de motivo, sem token/PII, persistindo após rollback da tentativa. key_version aponta para cofre/configuração externo; responsáveis/rotação dependem de D-06/D-08. Ver docs/database/04_database_constraints.md.