# ADR 0012 — Wave 3: token, política e auditoria mínima

Estado: decisão técnica de implementação da Wave 3; sujeita à auditoria P0.3.3.

As regras institucionais D-02 a D-12 permanecem abertas. A camada mínima recebe uma política versionada explicitamente configurada no servidor. Versão desconhecida, configuração incompleta ou estado não autorizado falham fechados. Fixtures SYNTHETIC_V1 não são seeds nem políticas de produção.

O QR apresenta um token de 32 bytes de random_bytes, codificado base64url com prefixo técnico p_ ou e_. Só SHA-256 binário do token completo é persistido em token_hash. O segredo é devolvido uma vez na emissão; não entra em auditoria, Graphify ou relatórios. O token não contém Pessoa, evento, número ou PII e nunca concede autorização sozinho.

A transacção de check-in revalida actor, sessão autenticada, concessão conjunta papel/scope, dispositivo, credencial, inscrição, evento/sessão e convite congelado. Leituras partilhadas impedem revogação concorrente; os writers de revogação usam locks exclusivos nas mesmas âncoras. Idempotência usa idempotency_requests e UNIQUE(session_id,person_id), mantendo uma presença por sessão. Duplicados convergem para ALREADY_CHECKED_IN; chave reutilizada com conteúdo diferente é recusada. Attendance é criada no mesmo commit, sem reescrever confirmações.

O catálogo coloca audit_logs na Wave 8, mas F08 exige CHECKIN_DENIED durável agora. Cria-se antecipadamente apenas essa tabela aprovada, com nomes, campos e índices inalterados, como dependência física de suporte. A partição original de 199 tabelas não muda. A futura Wave 8 deve adoptar a tabela existente, sem a recriar. Não se implementa funcionalidade Wave 8. A migration é reversível; o rollback completo da Wave 3 elimina o seu próprio histórico e exige exportação/retenção aprovada antes de uso operacional. Os testes de rollback são exclusivamente sintéticos e provam preservação integral das Waves 1/2/M1.1.

Recusas são auditadas após rollback numa nova transacção, com actor real, sessão de evento, código e correlação. Sem token, QR, hash do token ou PII. Falha de auditoria impede sucesso. Emissão, substituição/revogação, snapshot e check-in são auditados no commit respectivo.

Convocação materializa event_invitees numa versão de event_invitation_lists; alterações posteriores de períodos não recalculam listas congeladas. Selecção manual/externa insere Pessoas existentes. Os critérios tipados são avaliados no instante selection_at e cada linha mantém evidência dos IDs/períodos. A relação órgão/evento utiliza governance_sessions, conforme ADR 0007.

Não há endpoint público, bypass manual, seed institucional nem envio de comunicação massiva nesta fase. As quatro tabelas de suporte de comunicação pertencem fisicamente à Wave 3 por catálogo, mas não têm comportamento de dispatch.
