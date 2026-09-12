# Classificação, privacidade e retenção

Estado: proposta técnica. Não presumir prazos legais/institucionais; aprovação em D-06. Exemplos sintéticos. Sensibilidade por coluna/tabela consta do dicionário.

| Classe | Dados | Tratamento |
|---|---|---|
| Público institucional | Unidade/local/contacto institucional explicitamente aprovados | Projecção pública própria, nunca publicar tabelas inteiras |
| Interno | Catálogos/organograma/metadados não pessoais | Utilizadores autorizados |
| Restrito | Classe/cargo, composição, presença, notas, credenciais | Competência+unidade/departamento |
| Confidencial | Nome/nascimento/contactos/morada/família/convidados/contribuições/bancos | Tipo de dado/acção, cifra conforme catálogo |
| Altamente sensível | BI, apoio infantil, consentimento/recolha, raw staging, autenticação | Equipa designada/MFA/export restrito/log mascarado |

Dízimos/quotas individuais são Confidencial com restrição financeira reforçada. Director sem competência financeira não vê doador/valor. Acesso a agregados não concede cadastro nacional/família. Convidado externo conserva protecção; ministerial não é automaticamente público.

| Política | Retenção proposta / decisão |
|---|---|
| R-PESSOA | Rever finalidade de visitante/contacto; proveniência de fusão preservada; prazo/fundamento D-06 |
| R-INSTITUCIONAL | Preservar estrutura/marcos/nomeações/documentos/fechos, archive lógico; critérios D-06 |
| R-EVENTO | Fecho preservado; nominal/dispositivo só pela finalidade aprovada; prazo D-06 |
| R-MENOR | Evidência consentimento/recolha; apoio minimizado e restrito; prazo D-06 |
| R-ACADEMIA | Certificados/transcripts preservados; tracking/respostas podem expirar separadamente; D-06 |
| R-FINANCEIRO | Ledger/comprovativos preservados, sem purga comum; regime/prazo D-04/D-06 |
| R-COMUNICACAO | Payload/delivery TTL aprovado; preferências/consentimentos separados; D-06 |
| R-SEGURANCA | Sessão/idempotência expiram, logs críticos arquivados com checksum; D-06/D-08 |
| R-IMPORTACAO | Raw cifrado purgado após aceite/reconciliação/janela; conservar mapas/manifestos; D-06 |

Preservar é orientação do projecto, não alegação legal de retenção eterna. Legal hold suspende purga; desenho de holds/pedido de titular aguarda D-06. Sem purga automática antes de decisão.

## RBAC e QR

user_role_scopes une user+papel+scope no mesmo vínculo vigente. Validar uma concessão com permissão, unidade/descendência explícita e departamento. Papel financeiro em A não combina com scope não financeiro em B. Scope nacional referencia Direcção Geral. NULL department significa scope_kind UNIT, permissões continuam limitando acção/dado/classificação. Scope departamento pertence à mesma unidade. Admissão não cria login. Backend restringe query/write/export/drill-down/storage, não só botões.

Segregar solicitante/aprovador/publicador/conciliador. Revogação concorrente de scope exige revalidar sob mesma âncora de concessão/epoch no commit; política física D-08. MFA perfis críticos. Secrets de provider/infraestrutura no ambiente, nunca system_settings/Pessoa. Senha somente hash forte. QR aleatório forte hashed, nunca PII/ULID previsível como secret; validar estado/expiração/revogação. Público mínimo D-05, inicialmente válido/inválido se necessário; nunca BI/morada/família/finanças/telefone privado.

## Storage e auditoria

Storage privado por adapter; metadados owner lógico/unidade/departamento/classificação/MIME/size/checksum/creator/disk/key/estado. Quarentena até validação; fotos sem EXIF. Download reautoriza, URL assinada curta quando aprovada. Checksum não autoriza nem permite deduplicação cross-scope de ficheiro infantil com ACL comum. Documento versionado mantém file_id; tombstone lógico antes da purga física, preservando referência e checksum.

Auditoria append-only: actor_kind explícito USER/SYSTEM/IMPORT, actor nullable só nos casos justificados, acção/entity/id/scope/before-after allowlist mascarada/instante/origem/motivo/correlação/sessão-IP hash quando proporcional. Não auditar passwords/tokens/OTP/secrets/BI claro/imagem/raw staging/ciphertext reutilizável. Indicador de alteração de campo privado basta. Logs não elimináveis por interface comum; acesso/export auditado. Checksum sozinho não prova inviolabilidade contra DBA; arquivo externo restrito/manifesto conforme threat model. Deltas/limite payload/arquivo reduzem volume sem omitir operações críticas.

Backups cifrados, chave separada e restore BD+media+chaves+manifestos testado isoladamente. Exports/backups seguem política aprovada; não prometer eliminação selectiva imediata dentro de backup histórico.

## P0.2-F — contrato de segurança

Arquivar user ou remover todas as concessões activas revoga auth_sessions na mesma transacção sob users.id; emissão/refresh bloqueiam a mesma âncora. Cada request verifica actor activo, sessão expirada/revogada e concessão actual; efeitos sensíveis revalidam dentro da transacção. CHECKIN_DENIED fica consultável em audit_logs sem token/PII; key_version resolve cofre/configuração externa, nunca material secreto na BD. Responsável e política de rotação permanecem D-06/D-08 BLOCKED. Protocolos completos: 04_database_constraints.md.