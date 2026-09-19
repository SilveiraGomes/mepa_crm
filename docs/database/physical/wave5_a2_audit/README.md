# P0.3.5-A2-R — evidência do auditor (probes independentes)

Fontes dos probes usados na auditoria independente de `7cbc299`. Guardadas com sufixo `.txt` para não serem carregadas pelo autoload nem por nenhuma suite; **não fazem parte do código auditado**.

| Ficheiro | Cobre |
|---|---|
| `AuditCase.php.txt` | fixtures, política sintética `AUD_*`, *runtime* — **próprios**, sem `PooledWaveFiveCase` do executor |
| `AuthorizationProbeTest.php.txt` | role+scope no mesmo grant, forja de permissões, C3, C9 em 6 serviços, override, A2-DEV-01, Pessoa/membership, **S1**, **S2** |
| `FunctionalProbeTest.php.txt` | enrollment, C10 com excepção não-domínio, rollback em 7 operações, notas/stale/audit, GRADES_VIEW, F-03, D-09/D-11, sessões/localização, presença + child safety, progresso, certificados, histórico, contrato de erros |
| `ConcurrencyProbeTest.php.txt` + `audit-worker.php.txt` | E1 (2/8), E2, E3 (6), A5, numeração de tentativas, E4 em 9 variantes (espera real de lock), lapso de autorização de menor, serialização da revogação de consentimento |
| `BypassProbeTest.php.txt` | **H1** — conclusão de matrícula por `ACADEMY_ENROLL` |
| `LockOrderProbeTest.php.txt` | inversão de ordem de locks (deadlock InnoDB 1213 reproduzido com os locks exactos dos serviços) |
| `drift_check.py.txt` | deriva contrato ↔ implementação (não usa o validador do executor) |
| `validator_probes.py.txt` | 9 violações injectadas no código auditado, com restauro, contra `validate-wave5-application-contracts.cjs` |

**Como reexecutar:** remover o sufixo `.txt`; ajustar as constantes de caminho absoluto (`AuditCase.php` → `require_once` do `WaveFiveCase.php`; `ConcurrencyProbeTest::SCRATCH`; `audit-worker.php` → `require`); levantar a instância V2 (`mysql_instance.py init-start --port auto`), criar as pools (`pool.py create-and-migrate --port <p>`), exportar `WAVE5_DSN/WAVE5_USER/WAVE5_PASSWORD/WAVE5_ALLOW_SYNTHETIC=1` e correr `php vendor/phpunit/phpunit/phpunit <ficheiro>` a partir de `apps/api`. O `WaveFourWorkerHarness` (A1) aceita uma raiz de scripts fora do repo, por isso o worker do auditor vive fora de `scripts/`.

Os probes `S1`, `S2` e `H1` **falham de propósito**: asseveram o comportamento seguro; a falha é a confirmação do achado. Os probes de mutação e do validador restauraram os ficheiros auditados byte-a-byte (`git diff` limpo após cada um).
