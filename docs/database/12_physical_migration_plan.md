# Plano físico por dependência — P0.3.1

Autorização: pedido P0.3.1 e Gate `APPROVED FOR PHYSICAL MIGRATION DESIGN`. Fontes: model_catalog.json, dicionário, ADRs e constraints P0.2. O catálogo vigente tem 199 tabelas, 1752 colunas e 439 FKs; 1708 era a contagem anterior a D-01.

## Sequência

| Onda | Composição | Justificação |
|---|---|---|
| 1 | Referências indispensáveis, Pessoa/contactos/documentos/família, território, árvore, storage documental mínimo e locais | Fundação solicitada. `properties` é alvo de unit_location_links; legal_documents fundamenta períodos; files suporta documentos pessoais. |
| 2 | Membership/ministério/departamentos; segurança, idempotência, importação e estrutura de workflows indispensáveis | Antecipar os alvos users, import_records e workflow_instances evita adiar FKs obrigatórias de memberships, legado e transfers. Implementação funcional de workflows permanece posterior. |
| 3 | Governança/eventos/credenciais, messages e suas dependências de comunicação | event_invitations exige messages; event_checkins exige devices e idempotência. |
| 4 | Crianças/evangelismo/presença | Pares temporais e autorizações reutilizam Pessoa e eventos. discipleship_steps exige courses, promovido da onda 5 como estrutura mínima. |
| 5 | Academia | As demais entidades académicas dependem de eventos/storage/users e courses. |
| 6 | Financeiro | Depende da fundação, departamentos, idempotência e workflow_instances. |
| 7 | Comunicação/notificações/workflows restantes | Completa os alvos antecipados pelas ondas 2/3. |
| 8 | Estatística/snapshots/auditoria/suporte final e património adicional | Templos, instalações e activos não são alvos indispensáveis da onda 1. |

O inventário completo de 199 tabelas e a ordem topológica dentro de cada onda estão em `physical/migration_waves.json` e no apêndice gerado abaixo. Uma tabela pertence a uma única onda. Ciclos entre ondas são tratados promovendo alvos necessários; ciclos dentro de uma onda requerem criar as tabelas antes de instalar as FKs. Isso não autoriza criar código funcional dos módulos antecipados.

## Limite da onda 1

Criar somente as 31 tabelas enumeradas em `physical/wave1_manifest.json`. Não criar countries/provinces/municipalities: o catálogo usa códigos de país e territorial_areas. Não criar Membership, número MEPA, Departamentos, credenciais, eventos, Academia, financeiro ou workflows nesta execução. education_types/employment_types/qualificações/emprego/fusões são extensão posterior do cadastro.

Dependência externa identificada: `files.owner_department_id -> department_instances.id`. A coluna nullable e o índice são preservados; a FK fica explicitamente pendente para a onda 2, conforme a estratégia expand/adicionar FKs de 08_migration_strategy. Não inventar tabela substituta nem remover a coluna do modelo. O validador deve denunciar esta diferença em modo estrito; a onda não satisfaz o Gate de paridade completa enquanto esta FK faltar. Não usar o schema parcial em produção nem escrever vínculos departamentais nesta fase. `files.created_by -> users.id` usa somente a PK do scaffolding existente; users completo permanece na onda 2 e exige adaptação deliberada, nunca recriação de banco ocupado.

Não promover physical_candidates: generated+UNIQUE/FK composta da árvore dependem de D-08/D-11. Self-parent, ciclo, compatibilidade de tipos/território, mínimo municipal e máximo de Centro Geral: `NOT YET EXECUTABLE — APPLICATION LAYER WAVE`. Sem triggers ou falsa prova de enforcement de DB.

## Execução e evidência

Antes de testes físicos executar scripts/check-database-capabilities.php. MySQL >=8.0.16, InnoDB, utf8mb4/utf8mb4_unicode_ci, strict, FK/CHECK activos e UTC. Sem MySQL autorizado compatível: `BLOCKED FOR EXECUTION`; gerar migrations e validar estaticamente, sem snapshot apresentado como extraído de BD. Rollback de fixtures somente numa base explicitamente isolada. DDL tem commits implícitos: falha de deploy exige diagnóstico, não promessa de transacção global.

Estado inicial: plano criado antes das migrations; execução física pendente. D-02..D-12 permanecem abertas; estrutura configurável pode ser criada, seed institucional permanece bloqueado. Nenhum seed institucional nesta execução.

<!-- GENERATED INVENTORY -->

## Inventário completo

### Wave 1 — 31 tabelas

1. person_statuses
2. sex_types
3. civil_status_types
4. people
5. identity_document_types
6. contact_types
7. person_contacts
8. household_role_types
9. relationship_types
10. territorial_area_types
11. territorial_areas
12. addresses
13. households
14. organizational_unit_types
15. unit_parent_rules
16. organizational_units
17. organizational_structure_lock
18. files
19. person_documents
20. person_files
21. legal_document_types
22. legal_documents
23. person_addresses
24. household_members
25. person_relationships
26. unit_parent_periods
27. document_versions
28. physical_locations
29. properties
30. occupation_types
31. unit_location_links


### Wave 2 — 43 tabelas

1. education_types
2. employment_types
3. person_qualifications
4. person_employment
5. membership_statuses
6. member_number_sequences
7. milestone_types
8. ecclesiastical_milestones
9. ministerial_classes
10. positions
11. functions
12. ministerial_class_periods
13. organizational_posts
14. ministerial_assignments
15. function_assignments
16. department_categories
17. departments
18. department_applicability
19. department_instances
20. department_posts
21. department_appointments
22. department_memberships
23. users
24. person_merges
25. memberships
26. membership_periods
27. member_numbers
28. devices
29. auth_sessions
30. roles
31. permissions
32. role_permissions
33. scopes
34. user_role_scopes
35. workflows
36. workflow_instances
37. transfers
38. idempotency_requests
39. import_batches
40. import_records
41. legacy_member_numbers
42. import_issues
43. import_identity_maps


### Wave 3 — 31 tabelas

1. credential_types
2. credential_classes
3. credential_templates
4. credential_class_styles
5. credentials
6. governance_body_types
7. governance_bodies
8. governance_body_memberships
9. event_types
10. events
11. department_activities
12. event_sessions
13. governance_sessions
14. governance_resolutions
15. event_organizers
16. event_locations
17. event_documents
18. event_invitation_lists
19. invitation_criteria
20. event_invitees
21. event_registrations
22. event_confirmations
23. event_credentials
24. event_checkins
25. event_attendance
26. event_counts
27. communication_templates
28. audiences
29. communication_campaigns
30. messages
31. event_invitations


### Wave 4 — 17 tabelas

1. child_profiles
2. guardian_authorizations
3. person_consents
4. child_emergency_contacts
5. child_custody_visits
6. age_band_rules
7. department_transition_recommendations
8. outreach_campaigns
9. outreach_contacts
10. followups
11. decisions
12. discipleship_tracks
13. discipleship_enrollments
14. integration_events
15. courses
16. discipleship_steps
17. discipleship_progress


### Wave 5 — 25 tabelas

1. academic_units
2. programs
3. curricula
4. curriculum_courses
5. course_versions
6. course_prerequisites
7. course_modules
8. lessons
9. resources
10. lesson_resources
11. cohorts
12. classes
13. instructors
14. class_instructors
15. enrollments
16. class_sessions
17. academic_attendance
18. assessments
19. assessment_attempts
20. grades
21. progress
22. resource_progress
23. certificates
24. transcripts
25. transcript_lines


### Wave 6 — 28 tabelas

1. currencies
2. accounting_periods
3. chart_of_accounts
4. accounts
5. bank_account_details
6. cash_registers
7. funds
8. financial_categories
9. journal_entries
10. journal_lines
11. financial_parties
12. contributions
13. obligation_rules
14. obligations
15. receivables
16. payables
17. settlements
18. settlement_allocations
19. contribution_allocations
20. budgets
21. budget_lines
22. internal_transfers
23. transfer_postings
24. bank_statements
25. bank_statement_lines
26. reconciliations
27. reconciliation_matches
28. financial_documents


### Wave 7 — 7 tabelas

1. delivery_logs
2. notifications
3. communication_preferences
4. workflow_steps
5. membership_workflows
6. workflow_tasks
7. workflow_decisions


### Wave 8 — 17 tabelas

1. property_documents
2. temples
3. facility_types
4. facilities
5. asset_types
6. assets
7. asset_custody_periods
8. metric_definitions
9. statistical_periods
10. dimension_types
11. dimension_values
12. statistical_snapshots
13. snapshot_values
14. snapshot_value_dimensions
15. outbox_events
16. audit_logs
17. system_settings

