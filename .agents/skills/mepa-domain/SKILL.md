---
name: mepa-domain
description: Apply the approved MEPA CRM domain invariants when designing or changing identities, membership, organizational structure, departments, transfers, finance, or Academy behavior.
---

# MEPA domain invariants

- Read `mepa_crm_v1.0.1.md` before implementation; it is the canonical project baseline.
- Pessoa is the central identity. Membership and other participation are relations or states, not duplicate people records.
- Departments reuse Pessoa and never create parallel people databases.
- A member retains the approved unique member number across transfers, promotions and organizational changes; preserve any legacy number separately.
- Organizational units form a recursive national tree. Do not create a database per province or level.
- Preserve history for transfers, assignments, status and relationships; do not silently overwrite the past.
- Internal financial transfers are related movements and must not duplicate revenue.
- MEPA Academia accepts members and non-members; a non-member does not receive a member number.
- Do not invent business rules. Record approved structural decisions in ADRs and update affected project skills.

