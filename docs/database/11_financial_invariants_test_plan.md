# Ledger — especificação executável de integridade P0.2-F

O protótipo [run.php](../../tests/architecture/run.php) é exclusivamente de teste, sem módulo financeiro, migrations finais ou ligação automática a .env. Usa tabelas proto_* e valores sintéticos. Guarda DSN exacto localhost:33079/p02f_architecture e exige ARCH_DB_ALLOW_SYNTHETIC=1. Recria só fixtures dessa base explicitamente isolada. Não apontar a porta para a BD de negócio. [README de execução](../../tests/architecture/README.md).

Dinheiro: DECIMAL(19,4) no motor; strings decimais validadas e inteiros escalados no PHP 64-bit, com limite pequeno deliberado para os fixtures. Nenhum FLOAT/DOUBLE monetário. A aritmética reduzida não é implementação de todas as moedas/FX, nem valida a precisão máxima do modelo.

Todos os writers de teste bloqueiam período OPEN → transferência/dívida se aplicável → contas por id → cabeçalho/chave de entry. O lock de período é conservador e serializa operações do mesmo período; benefício: prevenção simples de write skew e teste claro; custo: contenção. Optimização futura exige ensaio sem enfraquecer integridade. Replay de entry compara hash; etapa de transferência é única por transfer_id+posting_stage e devolve entry existente. Reutilização de chave com outro payload de entry é rejeitada. Uma API futura também deve comparar todo o payload/idempotência do pedido de transferência; o fixture mantém o valor/contas imutáveis e não representa essa API.

Entrada externa: Dr caixa Congregação / Cr receita externa 100000. SEND: Dr trânsito origem / Cr caixa origem 40000. RECEIVE: Dr caixa destino / Cr devido a origem 40000, Dr a receber do destino / Cr trânsito origem 40000. Repetir Centro→Município. Soma receita externa POSTED=100000; caixa nacional=100000; trânsito recebido=0; activo/passivo interunidades por transferência compensam-se na consolidação, sem criar receita de 180000. Nomes/contas/fundos e tratamento legal dependem do contabilista D-04; ensaio não é aprovação contabilística.

| Teste | Critério de aprovação |
|---|---|
| T01 | Entry externa equilibrada publicada uma vez |
| T02 | Desequilíbrio rejeitado antes de efeito; nenhum cabeçalho residual |
| T03 | Dois processos reais na barreira SEND: um efeito e mesmo entry_id |
| T04 | Dois processos reais na barreira RECEIVE: um efeito e mesmo entry_id |
| T05 | Replay pós-commit e receive repetido sem efeito adicional; hash diferente rejeitado |
| T06 | Duas transferências internas, receita 100000, caixa conservada, trânsito zero e pares de activo/passivo conferidos por transfer_id e líquidos zero |
| T07 | Falha injectada após inserir cabeçalho/linhas reverte ambos |
| T08 | Cancelamento SEND confirmado publica inversão com reversal_of original; recebido não cancelado isoladamente |
| T09 | Dívida 1000 menos pagamentos 300+250 deixa 450; 451 adicional rejeitado |
| T10 | Período CLOSED rejeita publicação e reabertura pelo serviço; reset directo é só isolamento do fixture |
| T11 | INSERT inválido realmente rejeitado por CHECK/FK/UNIQUE |
| T12 | Candidato generated+UNIQUE rejeita segundo Centro Geral; aceita Centros e substituição após CLOSED |
| T13 | Âncora de criança e rejeição de visita aberta duplicada |
| T14 | Arquivo de user revoga todas as sessões no mesmo commit; join de request recusa actor |
| T15 | Dois processos publicam entries independentes no mesmo período/contas, ambas equilibradas e uma vez; estornos preservam a receita do cenário |
| T16 | Manifesto valida pares/mínimo/ciclo, rollback de retirada do último Centro e reparentamento atómico |

T03/T04 usam dois subprocessos PHP com PIDs diferentes, readiness barrier antes das transacções e atraso deliberado de 350 ms dentro do lock de período. O segundo leva >=600 ms. Medir tempo, PIDs e IDs no JSON, além de contar a etapa. Não é simulação sequencial. O atraso é instrumentação do fixture, não requisito da produção. Falha ou timeout é falha do teste, sem aceitar contagem final isolada como prova.

Publicação futura e editores devem bloquear o mesmo cabeçalho: linha não pode entrar entre soma e POSTED. POSTED é imutável e correcciona por estorno; o fixture só expõe funções de publicação/cancelamento, não oferece CRUD de posted. Segurança de privilégios e todos os caminhos de edição continuam verificação antes do módulo. T15 prova concorrência de publicações independentes no mesmo período/contas; T16 usa uma âncora sintética para validar a árvore. Concorrência completa de mutações da árvore/refresh deve ser ensaiada no motor exacto além desta prova reduzida.

Evidência é armazenada em [P0.2F_test_results.json](../reviews/P0.2F_test_results.json). Execução em MariaDB 10.4.32 temporário prova a estratégia reduzida nesse motor; production_qualification=false. MySQL 8.x, FK composta do candidato, volumetria, deadlocks/retry limitado, fundos/FX e revisão D-04 não foram homologados. Reexecutar a suite e ampliar ensaios na versão exacta qualificada D-08 antes de produção. Não usar protótipo como biblioteca do produto.