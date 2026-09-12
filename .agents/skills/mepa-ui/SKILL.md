---
name: mepa-ui
description: Apply the approved MEPA CRM interface rules when creating or changing user-facing React, PWA, forms, feedback, accessibility, or localization.
---

# MEPA interface invariants

- Read `mepa_crm_v1.1.1.md` and applicable UI decisions before implementation.
- Design mobile-first and responsive, with accessible controls and consistent components.
- Use Portuguese of Angola (`pt-AO`), AOA/Kz, `DD/MM/AAAA` and support for `+244` where applicable.
- Use multi-step flows for long forms and hide steps that do not apply.
- Treat loading, empty, error, success and synchronization states explicitly.
- PWA offline behavior is selective; approvals, member-number generation, security, reconciliation and structural changes remain online.
- Humanizer may improve user-facing prose only. Never apply it to code, SQL, JSON, numbers, API contracts, official rubrics, legal text, institutional names or business rules.
- Do not invent screens or functional dashboards before their approved phase.

