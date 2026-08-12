# VeciAhorra A11 EA6: cardinalidad normativa de propuestas concurrentes

Estado: contrato cerrado. Fecha: 2026-08-05.

## 1. Veredicto

`A11 EA6 CONCURRENT PROPOSAL GROUP CARDINALITY IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`

Esta autoridad valida siete grupos después del cierre completo de miembros y dependencies. No materializa deltas ni avanza hashes.

## 2. Autoridades consumidas

Se reconciliaron expected actions globales/caso-específicas, complementary, fixture contract, invocation catalog/plan, proposal materialization, concurrent identity catalog, crash exception/arrival, WR-04 relay/ownership, coordinator API, bundle transaction y matrices first-delivery/replay/convergence.

Prevalece para límites individuales, sums colectivas y winner observado. Conserva IDs, membership, owners, transportes, counts y proposal shapes.

## 3. Catálogo colectivo 7/7

| Invocation / group | Puerto(s) exactos | Individual por miembro | Dependencies | Por puerto | Total exacto | Winner policy |
|---|---|---|---|---|---:|---|
| `001_fd` / `a11g_A11-CON-01_first_delivery_01` | `scheduler.action_schedule` | publish 01 `0..1`; publish 02 `0..1` | ninguna | schedule=1 | 1 | fuente de propuesta única |
| `003_fd` / `a11g_A11-CON-02_first_delivery_01` | `durable.worker_execute` | callback 01 `0..1`; callback 02 `0..1` | ninguna | execute=1 | 1 | fuente de propuesta única |
| `005_fd` / `a11g_A11-CON-03_first_delivery_01` | ninguno | publish `0`; create `0` | ninguna | seis ports=0 | 0 | no aplica |
| `007_fd` / `a11g_A11-CON-04_first_delivery_01` | `durable.worker_execute` | old `0..1`; current `0..1` | ninguna | execute=1 | 1 | fuente de propuesta única |
| `009_fd` / `a11g_A11-CON-05_first_delivery_01` | cancel + execute | recovery cancel `1`; callback execute `1` | ninguna | cancel=1; execute=1 | 2 | lista de sources, no winner único |
| `035_fd` / `a11g_A11-EX-08_first_delivery_01` | `durable.worker_execute` | legacy `0`; durable `1` | ninguna | execute=1 | 1 | source durable validada, no competencia simétrica |
| `057_fd` / `a11g_A11-WR-04_first_delivery_01` | schedule + Webpay | requester relay 01 `0..1`; requester relay 02 `0..1` | server schedule `1`; stub Webpay `1` | schedule=1; Webpay=1 | 2 | carrier schedule observado; owners preservados |

Mínimo colectivo=máximo colectivo=exacto de la tabla. Una lista vacía individual es válida donde el límite incluye cero; nunca sustituye result/lifecycle. Count menor o mayor invalida toda invocation.

## 4. Filas individuales y de dependency

| Group | Source | Classification | Port | Min | Max | Collective | Duplicate/missing/excess |
|---|---|---|---|---:|---:|---:|---|
| CON-01 | `…publish_01_01` | group_member | schedule | 0 | 1 | 1 | duplicate fail / group zero fail / >1 fail |
| CON-01 | `…publish_02_02` | group_member | schedule | 0 | 1 | 1 | igual |
| CON-02 | `…callback_01_01` | group_member | execute | 0 | 1 | 1 | igual |
| CON-02 | `…callback_02_02` | group_member | execute | 0 | 1 | 1 | igual |
| CON-03 | `…publish_01_01` | group_member | ninguno | 0 | 0 | 0 | cualquier proposal falla |
| CON-03 | `…create_01_02` | group_member | ninguno | 0 | 0 | 0 | cualquier proposal falla |
| CON-04 | `…callback_old_01` | group_member | execute | 0 | 1 | 1 | duplicate fail / group zero fail / >1 fail |
| CON-04 | `…callback_current_02` | group_member | execute | 0 | 1 | 1 | igual |
| CON-05 | `…recovery_cancel_01` | group_member | cancel | 1 | 1 | cancel=1 | missing/duplicate fail |
| CON-05 | `…callback_execute_02` | group_member | execute | 1 | 1 | execute=1 | missing/duplicate fail |
| EX-08 | `…callback_legacy_01` | group_member | ninguno | 0 | 0 | execute=1 | cualquier proposal falla |
| EX-08 | `…callback_durable_02` | group_member | execute | 1 | 1 | execute=1 | missing/duplicate fail |
| WR-04 | `…post_requester_01_01` | group_member relay | schedule envelope | 0 | 1 | schedule=1 | cross-relay duplicate fail |
| WR-04 | `…post_requester_02_02` | group_member relay | schedule envelope | 0 | 1 | schedule=1 | igual |
| WR-04 | `a11o_A11-WR-04_first_delivery_http_schedule_server` | external_observer_dependency | schedule | 1 | 1 | schedule=1 | owner proposal missing/duplicate fail |
| WR-04 | `webpay_loopback_stub@057_fd` | loopback_dependency | Webpay | 1 | 1 | Webpay=1 | missing/duplicate fail |

`…` expande al ID literal del identity catalog; el validator carga el ID completo y prohíbe este recurso tipográfico en datos runtime.

## 5. A11-CON-01

Dos publish admiten individualmente 0..1 schedule. Tras ambos results/lifecycles, la suma debe ser exactamente 1. La única proposal identifica al winner observado. Index, ordinal, timing y arrival order no lo deciden. Cero o dos proposals falla y produce cero deltas.

## 6. A11-CON-02

Callback 01 y 02 admiten 0..1 `durable.worker_execute`; collective exacto 1. Productive identity es `durable_schedule/<schedule_id,generation>` de la proposal. Dos observaciones, incluso iguales, fallan; cero falla. Winner es el source de la proposal única.

## 7. A11-CON-03

Publish/create exigen exactamente cero individual y colectivo en cada port. Cualquier proposal falla. Ambos results, waits, EOF y freeze siguen siendo obligatorios; una colección vacía no permite omitir miembros.

## 8. A11-CON-04

Old/current admiten 0..1 execute y el grupo exige exactamente 1. Productive identity conserva schedule ID/generation. La única proposal determina source; el literal `current` no es selector del validator. Dos o cero fallan.

## 9. A11-CON-05

Recovery cancel exige exactamente una `scheduler.action_cancel`; callback execute exige exactamente una `durable.worker_execute`. Total exacto 2, con ambos ports presentes. No son mutuamente excluyentes. Productive identities son scheduled action ID y durable schedule ID/generation. Falta, extra, source o port cruzado falla. No existe winner único; validation result contiene dos accepted sources ordenables.

## 10. A11-EX-08

Legacy exige cero proposals; durable exige exactamente una execute. Total 1. Una proposal legacy, cero durable o más de una falla. No es competencia simétrica ni se extrapola CON-01.

## 11. A11-WR-04

Cada requester transporta relay schedule 0..1; entre ambos debe haber exactamente una proposal owned por el server slot. Ambas listas vacías, ambas no vacías, duplicado exacto o proposals distintas fallan. Request ordinal identifica carrier, nunca owner.

Stub aporta exactamente una Webpay proposal; requesters/server aportan cero Webpay. Ausencia o duplicación falla. Arquitectura única: `DurableRetryA11ConcurrentProposalInvocationValidator` agrega group schedule y dependency Webpay bajo una invocation transaction, ejecuta un único validation/freeze point y produce total 2. No hay freeze separado aceptable.

## 12. Dependencies observadoras

External observer y loopback dependency se abren junto al expected invocation state, conservan slot o `(invocation,role)`, no reciben membership ordinal y se cierran tras lifecycle/wait/EOF. Sus proposals entran al invocation validator por source tipado. Relay no transfiere ownership.

Solo WR-04 incorpora dependencies a uno de los siete validation aggregates. Los loopbacks de otras invocations no pertenecen a estos grupos.

## 13. Winner observado

`DurableRetryA11ObservedProposalWinner` contiene group ID, invocation, port, source kind, nullable participant ID/index, nullable observer slot, proposal DTO, ordered zero-member IDs y validated cardinality. No existe antes de group close ni cambia tras freeze.

Groups exact-one competitivos CON-01/02/04 y carrier schedule WR-04 producen un winner observado. CON-03 no tiene winner. CON-05 produce accepted source list de dos, no winners. EX-08 valida source literal durable y no crea winner competitivo. Winner no cambia membership o indices.

## 14. Productive identity y deduplicación

Tuple semántica exacta: `(case_id,phase,operation,port,productive_identity.type,productive_identity.value,payload.shape,business_identity_values)`. Business values son el key set normativo del payload: schedule action ID/hook/group; cancel action ID; durable schedule ID/generation; Webpay request fingerprint.

Participant ID, observer slot, relay ordinal y challenge se excluyen de igualdad semántica y se usan solo para source/binding. Proposal byte-identical repetida, distinta con misma tuple, cross-relay o direct+relay duplicada son `duplicate_productive_identity`. Proposal identity igual con bytes/tuple distintos es collision. No se comparan arrays libres: DTO canonicalizado y comparador tipado.

## 15. Proposal identity

Debe ser única dentro de source, group e invocation. Se valida después de schema/source binding y antes de cardinalidad. Local ordinal participa en orden, no salva duplicados. WR-04 request ordinal/challenge enlazan envelope, no proposal semantic identity. Dos IDs distintos para la misma acción siguen siendo duplicado.

## 16. Observed group state

Expected state: descriptor, expected members/dependencies, per-source/port limits. Observed state: observed/pending/closed members, proposal lists incluso vacías, dependency proposals, lifecycle proofs, individual/collective calculations, provisional/validated winner, status, freeze y failure reason.

Estados: `pending→collecting→members_closed→validating_cardinality→valid→frozen→cleaned`; cualquier estado pre-frozen puede pasar a `failed→cleaned`. No hay transiciones desde frozen salvo cleaned, ni desde failed a valid.

## 17. Cierre y freeze

Secuencia única: cargar descriptor; abrir state; activar sources; recibir results; validar proposals; registrar listas vacías; completar lifecycles; cerrar members; incorporar/cerrar dependencies; validar individual; validar ports; validar total; resolver winner; deduplicar; freeze; entregar accepted proposals al participant set.

Se prohíben winner/freeze/materialization prematuros, cancelar segundo miembro al ver una proposal, ignorar resultados tardíos o desempatar por timing.

## 18. Output al participant set

`DurableRetryA11ConcurrentProposalGroupValidationResult` congelado contiene group/invocation, ordered accepted proposal DTOs, owner bindings, zero members, nullable winner o accepted sources, cardinality fingerprint, lifecycle proof y freeze proof. Global set acepta solo valid+frozen, cero pending, dependencies closed y cero duplicates. No materializa.

## 19. Orden total

Se conserva `(phase_rank,operation_rank,participant_index,port_rank,type,value,local_ordinal,proposal_identity)`. Dependency usa `participant_index=PHP_INT_MAX` solo como comparison projection y desempata después por `dependency_rank` (`external_observer_dependency=0`, `loopback_dependency=1`) y observer slot/identity bytes. El sentinel no se almacena como participant index ni colisiona con members. WR-04 queda determinista. Ordenar no decide winner, corrige cardinalidad ni deduplica.

## 20. Crash participants

CR-01..05 no pertenecen a los siete groups. Conservan count exacto 1 bajo crash contract, IDs/index `[1]`, windows y proposals. No reciben límites 0..1 ni winner observado.

## 21. API PHP exacta

Namespace `VeciAhorra\Tests\Manual\A11`; paths homónimos bajo `tests/manual/support/`.

```php
<?php
declare(strict_types=1);
namespace VeciAhorra\Tests\Manual\A11;
final readonly class DurableRetryA11ConcurrentProposalGroupCardinalityDescriptor { public function __construct(public string $groupId, public string $invocationId, public array $sourceLimits, public array $portExactCounts, public int $collectiveExactCount, public string $winnerPolicy) {} }
final class DurableRetryA11ConcurrentProposalGroupExpectedState { public function descriptor(): DurableRetryA11ConcurrentProposalGroupCardinalityDescriptor { throw new \LogicException('cardinality_contract_only'); } }
final class DurableRetryA11ConcurrentProposalGroupObservedState { public function status(): string { throw new \LogicException('cardinality_contract_only'); } }
final readonly class DurableRetryA11ObservedProposalWinner { public function __construct(public string $groupId, public string $invocationId, public string $port, public string $sourceKind, public ?string $participantId, public ?int $participantIndex, public ?string $observerSlot, public object $proposal, public array $zeroMembers, public int $validatedCardinality) {} }
final readonly class DurableRetryA11ConcurrentProposalGroupValidationResult { public function acceptedProposals(): array { throw new \LogicException('cardinality_contract_only'); } public function toCanonicalArray(): array { throw new \LogicException('cardinality_contract_only'); } }
interface DurableRetryA11ConcurrentProposalGroupValidator { public function open(DurableRetryA11ConcurrentProposalGroupCardinalityDescriptor $descriptor): DurableRetryA11ConcurrentProposalGroupObservedState; public function registerParticipantResult(string $groupId, string $participantId, object $result): void; public function registerDependencyProposal(string $groupId, string $source, object $proposal): void; public function closeMember(string $groupId, string $participantId): void; public function closeDependency(string $groupId, string $source): void; public function validateIndividual(string $groupId): void; public function validateCollective(string $groupId): void; public function resolveWinner(string $groupId): ?DurableRetryA11ObservedProposalWinner; public function freeze(string $groupId): DurableRetryA11ConcurrentProposalGroupValidationResult; public function acceptedProposals(string $groupId): array; public function cleanup(string $groupId): void; public function state(string $groupId): DurableRetryA11ConcurrentProposalGroupObservedState; }
final class DurableRetryA11ConcurrentProposalGroupCardinalityCatalog { public static function fromIdentityCatalog(DurableRetryA11ConcurrentParticipantIdentityCatalog $identities): self { throw new \LogicException('cardinality_contract_only'); } public function all(): array { throw new \LogicException('cardinality_contract_only'); } }
final class DurableRetryA11ConcurrentProposalGroupException extends \RuntimeException { public function __construct(public readonly string $reason) { parent::__construct($reason); } }
```

Invocation validator WR-04 recibe este validator y loopback result; su `validateAndFreeze(string $invocationId): DurableRetryA11ConcurrentProposalGroupValidationResult` es el único freeze.

## 22. Atomicidad y rollback

Cualquier group failure invalida toda su invocation. Se descartan proposals, relays, dependencies, winner provisional, calculations, dedup state, accepted list, fingerprints, partial participant set, materialization candidates y candidate hashes. Catalog, base snapshots, coordinator combined state, integrated hashes y expected counts no cambian. Cleanup cierra processes/listeners/pipes/buffers/stores.

## 23. Failure reasons

| # | Reason | Condición/etapa | Efecto |
|---:|---|---|---|
| 1 | `proposal_group_unknown` | group lookup | invalidación/rollback/cleanup §22 |
| 2 | `proposal_group_descriptor_mismatch` | descriptor | igual |
| 3 | `proposal_group_member_missing` | expected state | igual |
| 4 | `proposal_group_member_unexpected` | collection | igual |
| 5 | `proposal_group_member_duplicate` | collection | igual |
| 6 | `proposal_group_dependency_missing` | dependency close | igual |
| 7 | `proposal_group_dependency_unexpected` | collection | igual |
| 8 | `proposal_group_dependency_duplicate` | collection | igual |
| 9 | `proposal_group_member_not_closed` | close | igual |
| 10 | `proposal_group_dependency_not_closed` | close | igual |
| 11 | `proposal_group_individual_below_minimum` | individual | igual |
| 12 | `proposal_group_individual_above_maximum` | individual | igual |
| 13 | `proposal_group_collective_below_minimum` | collective | igual |
| 14 | `proposal_group_collective_above_maximum` | collective | igual |
| 15 | `proposal_group_exact_count_mismatch` | collective | igual |
| 16 | `proposal_group_port_cardinality_mismatch` | per-port | igual |
| 17 | `proposal_group_winner_missing` | winner | igual |
| 18 | `proposal_group_multiple_winners` | winner | igual |
| 19 | `proposal_group_winner_premature` | state | igual |
| 20 | `proposal_group_duplicate_proposal` | dedup bytes | igual |
| 21 | `proposal_group_duplicate_productive_identity` | semantic dedup | igual |
| 22 | `proposal_group_proposal_identity_collision` | identity | igual |
| 23 | `proposal_group_source_forbidden` | ownership | igual |
| 24 | `proposal_group_relay_ownership_mismatch` | WR-04 binding | igual |
| 25 | `proposal_group_freeze_premature` | freeze | igual |
| 26 | `proposal_group_result_after_freeze` | frozen state | igual |
| 27 | `proposal_group_materialization_before_freeze` | integration | igual |
| 28 | `proposal_group_cleanup_incomplete` | cleanup | invocation remains failed; report residual |

Precedencia es el número; solo el primero aplicable se publica.

## 24. Precedencia

Consume identity catalog, crash exception y WR-04 relay/ownership. Sustituye cardinalidad individual fija cuando expected pertenece al grupo y publica límites/collective exactos sin predeterminar winners. No cambia counts, IDs, indices, crash contracts, materialization o hashes ni añade canales. Obliga topology, global participant set, materialization e implementación EA6.

## 25. Allowlist exacta

1. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupCardinalityDescriptor.php`.
2. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupExpectedState.php`.
3. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupObservedState.php`.
4. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupValidator.php`.
5. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupValidationResult.php`.
6. `tests/manual/support/DurableRetryA11ObservedProposalWinner.php`.
7. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupCardinalityCatalog.php`.
8. `tests/manual/support/DurableRetryA11ConcurrentProposalGroupException.php`.
9. Coordinator group-state wiring específico.
10. `tests/manual/durable-retry-a11-concurrent-proposal-group-cardinality-test.php`.

Se excluyen materializer, general parser, general topology, productive bundle integration y suites completas.

## 26. Matriz adversarial

| R | Escenario | Resultado |
|---:|---|---|
| 01–07 | cada group válido | acepta/freeze |
| 08–09 | winner member 0/1 | acepta source observada |
| 10 | reverse results | mismo resultado |
| 11–13 | ambos cero, ambos uno, member produce dos | reason cardinality |
| 14–15 | zero group con cero/una | acepta / above max |
| 16–18 | duplicate bytes/productive identity/identity collision | reason dedup |
| 19–23 | member/dependency missing/unexpected/lifecycle incomplete | reason correspondiente |
| 24–26 | winner/freeze prematuro/result post-freeze | reason state |
| 27–28 | WR-04 requester 1/2 relay | acepta carrier observado |
| 29–32 | relays ambos vacíos/ambos schedule/Webpay missing/duplicate | falla |
| 33–34 | owner server/stub | preservado |
| 35 | crash contracts | no afectados |
| 36 | materialization pre-freeze | prohibida |
| 37–38 | rollback/cleanup total | cero residuos |
| 39–41 | counts preservados/cero hash/channel adicional | preserva/preserva/rechaza |

## 27. Cierre

Quedan cerrados siete groups, catorce members y las dos dependencies WR-04 aplicables, con individual limits, per-port counts, collective exact counts, source observation, dedup, lifecycle, freeze y rollback deterministas.
