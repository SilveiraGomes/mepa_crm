---
name: mepa-security
description: Apply MEPA CRM security invariants when work touches authorization, personal data, minors, finance, auditing, uploads, credentials, or public exposure.
---

# MEPA security invariants

- Read `mepa_crm_v1.0.1.md` and applicable ADRs before implementation.
- Enforce authorization in the backend using role, organizational scope, department, data type and action.
- Apply least privilege and financial segregation of duties.
- Treat personal data as sensitive; protect minors and financial information with stronger restrictions.
- Audit relevant operations with actor, time, origin, entity, action, before/after values and reason when applicable.
- Never version secrets, real uploads or production personal data.
- Public credential validation must expose only approved minimum data and use opaque tokens.
- Validate uploads and avoid storing heavy files as database BLOB/Base64 values.

