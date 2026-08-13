# VeciAhorra A11 EA6: catálogo normativo de identidades concurrentes

Estado: contrato cerrado. Fecha: 2026-08-05.

## 1. Veredicto

`A11 EA6 CONCURRENT PARTICIPANT IDENTITY CATALOG IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`

El catálogo queda construido y congelado antes del spawn. No define winner, cardinalidad de propuestas, materialización ni topología general.

## 2. Autoridades y precedencia de lectura

Se reconciliaron complementary, fixture contract, expected actions caso-específicas, invocation ID catalog y plan, proposal materialization, crash arrival/control-plane/excepción, WR-04 relay/ownership, coordinator API, bundle transaction, matrices y símbolos productivos. Action Transport fija `operation=execute_phase`, el entrypoint controlado y los result envelopes; los roles distinguen el flujo productivo interno.

## 3. Inspección 62/62

Clasificación literal: `G` grupo concurrente; `C` crash excepcional; `L` dependencia loopback; `O` observer HTTP externo; `-` sin elemento catalogable. Cada fila fue inspeccionada con operation/entrypoint `execute_phase`; normal group member produce `phase_result`, crash `barrier_arrival`, loopback `loopback_result`, HTTP observer response controlada. Replay no hereda procesos first-delivery.

| # | Invocation | Caso/fase | Clase |
|---:|---|---|---|
| 1 | `a11_000000000001_fd` | CON-01/FD | G |
| 2 | `a11_000000000002_replay` | CON-01/R | - |
| 3 | `a11_000000000003_fd` | CON-02/FD | G |
| 4 | `a11_000000000004_replay` | CON-02/R | - |
| 5 | `a11_000000000005_fd` | CON-03/FD | G |
| 6 | `a11_000000000006_replay` | CON-03/R | - |
| 7 | `a11_000000000007_fd` | CON-04/FD | G |
| 8 | `a11_000000000008_replay` | CON-04/R | - |
| 9 | `a11_000000000009_fd` | CON-05/FD | G |
| 10 | `a11_000000000010_replay` | CON-05/R | - |
| 11 | `a11_000000000011_fd` | CR-01/FD | C |
| 12 | `a11_000000000012_replay` | CR-01/R | - |
| 13 | `a11_000000000013_fd` | CR-02/FD | C |
| 14 | `a11_000000000014_replay` | CR-02/R | - |
| 15 | `a11_000000000015_fd` | CR-03/FD | C |
| 16 | `a11_000000000016_replay` | CR-03/R | - |
| 17 | `a11_000000000017_fd` | CR-04/FD | C |
| 18 | `a11_000000000018_replay` | CR-04/R | - |
| 19 | `a11_000000000019_fd` | CR-05/FD | C |
| 20 | `a11_000000000020_replay` | CR-05/R | - |
| 21–34 | `a11_000000000021_fd`…`a11_000000000034_replay` | EX-01..07/FD+R | - |
| 35 | `a11_000000000035_fd` | EX-08/FD | G |
| 36 | `a11_000000000036_replay` | EX-08/R | - |
| 37–40 | `a11_000000000037_fd`…`a11_000000000040_replay` | EX-09..10/FD+R | - |
| 41 | `a11_000000000041_fd` | OP-01/FD | L |
| 42 | `a11_000000000042_replay` | OP-01/R | - |
| 43 | `a11_000000000043_fd` | OP-02/FD | L |
| 44–50 | `a11_000000000044_replay`…`a11_000000000050_replay` | OP-02/R, OP-03..05 | - |
| 51 | `a11_000000000051_fd` | WR-01/FD | L |
| 52 | `a11_000000000052_replay` | WR-01/R | - |
| 53 | `a11_000000000053_fd` | WR-02/FD | L |
| 54 | `a11_000000000054_replay` | WR-02/R | - |
| 55 | `a11_000000000055_fd` | WR-03/FD | L |
| 56 | `a11_000000000056_replay` | WR-03/R | - |
| 57 | `a11_000000000057_fd` | WR-04/FD | G+L+O |
| 58–60 | `a11_000000000058_replay`…`a11_000000000060_replay` | WR-04/R, WR-05 | - |
| 61 | `a11_000000000061_fd` | WR-06/FD | L |
| 62 | `a11_000000000062_replay` | WR-06/R | - |

Resultado: 62 inspeccionadas; 7 concurrentes, 55 sin grupo; 7 grupos, 14 miembros; 8 dependencias (7 loopback, 1 observer HTTP); 5 crash participants; 1 observer slot. EX-09 fue inspeccionada y excluida: `publish+legacy` no publica ready/release ni grupo normativo. Ninguna invocation tiene más de un grupo.

## 4. Dominios e IDs

Dominios exactos: `general_role_based` y `crash_exception`. Group regex `\Aa11g_A11-(?:CON-0[1-5]|WR-04|EX-08)_first_delivery_01\z`; participant general regex `\Aa11p_A11-(?:CON-0[1-5]|WR-04|EX-08)_first_delivery_[a-z0-9_]+_0[1-2]\z`. ASCII case-sensitive, sin normalización, aliases o derivación runtime. Group ordinal usa dos dígitos; participant ordinal distingue identidades y no es index.

Longitudes: group 32..33 bytes; participant general 43..50 bytes. Phase vocabulary aquí es solo `first_delivery`. Unicidad es global.

Crash consume literalmente los cinco IDs y el índice 1 de la excepción; no pasa por la regex general.

## 5. Catálogo exhaustivo de grupos y miembros

Cada grupo usa `barrier_simultaneous`, membership ordinals 0,1 e índices generales 0,1. Operation y entrypoint son `execute_phase`; kind `normal_child`; lifecycle `normal_phase_result`; result `phase_result`; dependency `group_member`.

| Invocation | Group ID | Index/ordinal → participant ID | Role | Eligibility | Relay |
|---|---|---|---|---|---|
| `a11_000000000001_fd` | `a11g_A11-CON-01_first_delivery_01` | 0/0 → `a11p_A11-CON-01_first_delivery_publish_01_01` | `publish_01` | `scheduler.action_schedule` | `[]` |
| igual | igual | 1/1 → `a11p_A11-CON-01_first_delivery_publish_02_02` | `publish_02` | `scheduler.action_schedule` | `[]` |
| `a11_000000000003_fd` | `a11g_A11-CON-02_first_delivery_01` | 0/0 → `a11p_A11-CON-02_first_delivery_callback_01_01` | `callback_01` | `durable.worker_execute` | `[]` |
| igual | igual | 1/1 → `a11p_A11-CON-02_first_delivery_callback_02_02` | `callback_02` | `durable.worker_execute` | `[]` |
| `a11_000000000005_fd` | `a11g_A11-CON-03_first_delivery_01` | 0/0 → `a11p_A11-CON-03_first_delivery_publish_01_01` | `publish_01` | `scheduler.action_schedule` | `[]` |
| igual | igual | 1/1 → `a11p_A11-CON-03_first_delivery_create_01_02` | `create_01` | `scheduler.action_schedule` | `[]` |
| `a11_000000000007_fd` | `a11g_A11-CON-04_first_delivery_01` | 0/0 → `a11p_A11-CON-04_first_delivery_callback_old_01` | `callback_old` | `durable.worker_execute` | `[]` |
| igual | igual | 1/1 → `a11p_A11-CON-04_first_delivery_callback_current_02` | `callback_current` | `durable.worker_execute` | `[]` |
| `a11_000000000009_fd` | `a11g_A11-CON-05_first_delivery_01` | 0/0 → `a11p_A11-CON-05_first_delivery_recovery_cancel_01` | `recovery_cancel` | `scheduler.action_cancel` | `[]` |
| igual | igual | 1/1 → `a11p_A11-CON-05_first_delivery_callback_execute_02` | `callback_execute` | `durable.worker_execute` | `[]` |
| `a11_000000000035_fd` | `a11g_A11-EX-08_first_delivery_01` | 0/0 → `a11p_A11-EX-08_first_delivery_callback_legacy_01` | `callback_legacy` | `legacy.retry_schedule` | `[]` |
| igual | igual | 1/1 → `a11p_A11-EX-08_first_delivery_callback_durable_02` | `callback_durable` | `durable.worker_execute` | `[]` |
| `a11_000000000057_fd` | `a11g_A11-WR-04_first_delivery_01` | 0/0 → `a11p_A11-WR-04_first_delivery_post_requester_01_01` | `post_requester_01` | `[]` | `[scheduler.action_schedule]` |
| igual | igual | 1/1 → `a11p_A11-WR-04_first_delivery_post_requester_02_02` | `post_requester_02` | `[]` | `[scheduler.action_schedule]` |

Roles cerrados son exactamente los 14 valores mostrados más los cinco crash de §7, `http_schedule_observer` y `webpay_loopback_stub`. Roles simétricos no expresan prioridad.

## 6. Dependencies y observer slots

| Invocation(s) | Role/kind | Lifecycle/result | Classification | Slot | Port |
|---|---|---|---|---|---|
| `041_fd`,`043_fd`,`051_fd`,`053_fd`,`055_fd`,`057_fd`,`061_fd` | `webpay_loopback_stub` / `loopback_stub` | `loopback_server_result` / `loopback_result` | `loopback_dependency` | null | `webpay.commit` |
| `a11_000000000057_fd` | `http_schedule_observer` / `http_observer` | `http_readiness_requests_shutdown` / `http_controlled_response` | `external_observer_dependency` | `a11o_A11-WR-04_first_delivery_http_schedule_server` | `scheduler.action_schedule` |

Cada loopback row es una dependencia individual vinculada a su invocation, con identity por `(invocation_id,role)` y sin participant ID, group, index u ordinal. Startup/readiness preceden child; shutdown→result→wait→EOF son obligatorios. El único observer slot es case/phase/role/kind/lifecycle/result/port unique. Dependencias no adquieren membership.

## 7. Crash exception 5/5

Cada crash queda fuera de grupo y usa `membership_ordinal=null`, `concurrent_group_id=null`, release `externally_triggered`, lifecycle `crash_barrier_external_kill`, kind `crash_child`, result `barrier_arrival`, observation `crash_observer`, external kill true.

| Invocation | ID/index | Role | Window | Port |
|---|---|---|---|---|
| `011_fd` | `a11p_A11-CR-01_first_delivery_01` / 1 | `external_scheduler` | `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | `scheduler.action_schedule` |
| `013_fd` | `a11p_A11-CR-02_first_delivery_01` / 1 | `stage_processor` | `CRASH_AFTER_LOCAL_CLAIM` | `durable.worker_execute` |
| `015_fd` | `a11p_A11-CR-03_first_delivery_01` / 1 | `reconciliation_attempt` | `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | `durable.worker_execute` |
| `017_fd` | `a11p_A11-CR-04_first_delivery_01` / 1 | `schedule_repository` | `CRASH_AFTER_RESULT_PERSISTED` | `durable.worker_execute` |
| `019_fd` | `a11p_A11-CR-05_first_delivery_01` / 1 | `executor` | `CRASH_BEFORE_CALLBACK_RETURN` | `durable.worker_execute` |

La secuencia local excepcional es `[1]`; proposal count existente permanece fuera del alcance de este catálogo.

## 8. Vocabularios cerrados

Identity domain: `general_role_based|crash_exception`. Participant kind: `normal_child|crash_child|loopback_stub|http_observer|infrastructure_process`. Lifecycle: `normal_phase_result|crash_barrier_external_kill|loopback_server_result|http_readiness_requests_shutdown|infrastructure_no_result`. Release: `barrier_simultaneous|immediate|dependency_gated|externally_triggered|not_applicable`. Result: `phase_result|barrier_arrival|loopback_result|http_controlled_response|none`. Observation: `direct_observer|relay_only|non_observer|loopback_observer|crash_observer`. Dependency: `group_member|external_observer_dependency|loopback_dependency|infrastructure_dependency|none`.

Los seis ports únicos son `webpay.commit`, `woocommerce.payment_complete`, `scheduler.action_schedule`, `scheduler.action_cancel`, `legacy.retry_schedule`, `durable.worker_execute`. Eligibility autoriza observación; relay capability autoriza transporte; ninguna fija emisión efectiva o cardinalidad.

## 9. A11-WR-04 cerrado

Dos requester members comparten group, usan IDs distintos, index/ordinal 0/0 y 1/1, simultaneous release, phase results, eligibility vacía y relay schedule. Server externo conserva el slot y eligibility schedule; stub externo conserva Webpay. Timing no altera catálogo, prioridad u ownership.

## 10. Descriptor y canonical projection

Path futuro `tests/manual/support/DurableRetryA11ConcurrentParticipantDescriptor.php`, namespace `VeciAhorra\Tests\Manual\A11`. Clase `final readonly`; no array libre primario. Campos exactos: `executionId`, `invocationId`, nullable `concurrentGroupId`, `caseId`, `phase`, `operation`, `entrypointId`, nullable `participantId`, nullable `participantIndex`, nullable `membershipOrdinal`, `role`, `identityDomain`, `participantKind`, `lifecycleKind`, `releaseKind`, `resultKind`, `observationKind`, `dependencyClassification`, ordered `portEligibility`, ordered `relayCapability`, nullable `observerSlot`, nullable `crashWindow`, `requiresExternalKill`.

Constructor recibe esos campos tipados; getters homónimos retornan los mismos tipos. `toCanonicalArray(): array` proyecta nombres snake_case en ese orden lógico y canonical JSON ordena keys por bytes. Validator impide nulls incompatibles, arrays no-list, ports repetidos o vocabulario abierto.

Proyección superior exacta:

```json
{"counts":{"dependencies":8,"groups":7,"invocations_inspected":62,"participants":19},"identity_domains":["general_role_based","crash_exception"],"kind":"concurrent_participant_identity_catalog","schema":"veciahorra-a11-concurrent-participant-identity-catalog/v1"}
```

## 11. Action invocation plan wiring

Referencia exacta nueva: `action_invocation_plan.concurrent_identity_catalog_fingerprint`, SHA-256 de la proyección canónica del catálogo. Owner: coordinator builder. Construcción ocurre tras validar las 62 entries, crash exceptions y authorities WR-04, antes de cualquier spawn. Freeze profundo precede binding/challenges.

Lookups readonly: invocation, group, participant ID, `(invocation,index)`, observer slot e identity domain. Child recibe descriptor ya elegido, repite identidad y nunca la crea, modifica o deriva.

## 12. API PHP exacta

```php
<?php
declare(strict_types=1);
namespace VeciAhorra\Tests\Manual\A11;

final class DurableRetryA11ConcurrentParticipantIdentityCatalog {
    public static function createForExecution(string $executionId, array $actionInvocationPlan, DurableRetryA11CrashParticipantIdentityExceptionCatalog $crashExceptions): self { throw new \LogicException('concurrent_identity_contract_only'); }
    /** @return list<DurableRetryA11ConcurrentGroupDescriptor> */ public function allGroups(): array { throw new \LogicException('concurrent_identity_contract_only'); }
    /** @return list<DurableRetryA11ConcurrentParticipantDescriptor> */ public function allParticipants(): array { throw new \LogicException('concurrent_identity_contract_only'); }
    /** @return list<DurableRetryA11ConcurrentDependencyDescriptor> */ public function allDependencies(): array { throw new \LogicException('concurrent_identity_contract_only'); }
    public function groupsForInvocation(string $invocationId): array { throw new \LogicException('concurrent_identity_contract_only'); }
    public function participantsForInvocation(string $invocationId): array { throw new \LogicException('concurrent_identity_contract_only'); }
    public function participantsForGroup(string $groupId): array { throw new \LogicException('concurrent_identity_contract_only'); }
    public function participantById(string $participantId): DurableRetryA11ConcurrentParticipantDescriptor { throw new \LogicException('concurrent_identity_contract_only'); }
    public function participantByIndex(string $invocationId, int $index): DurableRetryA11ConcurrentParticipantDescriptor { throw new \LogicException('concurrent_identity_contract_only'); }
    public function dependencyByObserverSlot(string $observerSlot): DurableRetryA11ConcurrentDependencyDescriptor { throw new \LogicException('concurrent_identity_contract_only'); }
    public function identityDomainForInvocation(string $invocationId): string { throw new \LogicException('concurrent_identity_contract_only'); }
    public function validate(): void { throw new \LogicException('concurrent_identity_contract_only'); }
    public function freeze(): void { throw new \LogicException('concurrent_identity_contract_only'); }
    public function isFrozen(): bool { throw new \LogicException('concurrent_identity_contract_only'); }
    public function toCanonicalArray(): array { throw new \LogicException('concurrent_identity_contract_only'); }
}
final class DurableRetryA11ConcurrentIdentityException extends \RuntimeException { public function __construct(public readonly string $reason) { parent::__construct($reason); } }
```

Group/participant/dependency DTOs son `final readonly`; validator y exception viven en archivos homónimos.

## 13. Invariantes

Orden: schema; 62 inspected; counts; invocation IDs; domain; crash lookup; group IDs; participant IDs; slots; indices by domain; ordinals; roles; case; phase; operation; entrypoint; kind; lifecycle; release; result; observation; dependency; ports; relay; window; external kill; expected actions; concurrency matrices; WR-04 ownership; crash exception; freeze; mutation prohibition.

Conteos exactos: 62 invocations, 7 groups, 19 participant descriptors (14 general+5 crash), 8 dependencies, 7 unique group IDs, 19 unique participant IDs, 1 unique slot. General index/ordinal sequences son siete veces `[0,1]`; crash index sequence es cinco veces `[1]` y ordinal null.

## 14. Reasons cerrados

| # | Reason | Condición/etapa | Efecto común |
|---:|---|---|---|
| 1 | `concurrent_identity_schema_invalid` | schema/key set | invalida catálogo/invocation; rollback stores; cleanup total |
| 2 | `concurrent_identity_invocation_catalog_invalid` | count/ID/case/phase | igual |
| 3 | `concurrent_identity_domain_invalid` | domain/selección | igual |
| 4 | `concurrent_identity_crash_exception_mismatch` | lookup/ID/index/window | igual |
| 5 | `concurrent_identity_group_invalid` | missing/extra/duplicate/grammar | igual |
| 6 | `concurrent_identity_participant_invalid` | missing/extra/duplicate/grammar | igual |
| 7 | `concurrent_identity_dependency_invalid` | missing/extra/duplicate | igual |
| 8 | `concurrent_identity_observer_slot_invalid` | slot missing/duplicate/mismatch | igual |
| 9 | `concurrent_identity_general_index_invalid` | no `[0,1]` | igual |
| 10 | `concurrent_identity_crash_index_invalid` | no `[1]` | igual |
| 11 | `concurrent_identity_membership_ordinal_invalid` | duplicate/gap/order | igual |
| 12 | `concurrent_identity_role_invalid` | vocabulario/mapping | igual |
| 13 | `concurrent_identity_kind_invalid` | participant kind | igual |
| 14 | `concurrent_identity_lifecycle_invalid` | lifecycle/release | igual |
| 15 | `concurrent_identity_result_invalid` | result kind | igual |
| 16 | `concurrent_identity_observation_invalid` | observation kind | igual |
| 17 | `concurrent_identity_dependency_classification_invalid` | classification | igual |
| 18 | `concurrent_identity_operation_invalid` | operation mismatch | igual |
| 19 | `concurrent_identity_entrypoint_invalid` | entrypoint mismatch | igual |
| 20 | `concurrent_identity_port_invalid` | port/vocabulary/eligibility | igual |
| 21 | `concurrent_identity_relay_invalid` | relay capability | igual |
| 22 | `concurrent_identity_crash_window_invalid` | window/kill mismatch | igual |
| 23 | `concurrent_identity_wr04_ownership_invalid` | owner/relay/source | igual |
| 24 | `concurrent_identity_catalog_not_frozen` | consumo pre-freeze | igual |
| 25 | `concurrent_identity_mutation_after_freeze` | bytes posteriores cambian | igual |

Precedencia es el número. Efecto común produce cero materialización/integración/hash advance, conserva state previo, termina/draina/wait/EOF y limpia bindings, stores, pipes, listeners y buffers.

## 15. Precedencia normativa

Autoridad exclusiva para group IDs, general IDs/indices, roles y membership. Consume crash exception y WR-04 relay/ownership. Conserva cinco crash IDs/indices. No cambia invocations, expected counts, windows, proposals, materialization o hashes; no define cardinalidad ni winner y no agrega canales. Es obligatoria para cardinality, multiprocess topology e implementación EA6.

## 16. Allowlist futura exacta

1. `tests/manual/support/DurableRetryA11ConcurrentParticipantDescriptor.php`.
2. `tests/manual/support/DurableRetryA11ConcurrentGroupDescriptor.php`.
3. `tests/manual/support/DurableRetryA11ConcurrentDependencyDescriptor.php`.
4. `tests/manual/support/DurableRetryA11ConcurrentParticipantIdentityCatalog.php`.
5. `tests/manual/support/DurableRetryA11ConcurrentParticipantIdentityValidator.php`.
6. `tests/manual/support/DurableRetryA11ConcurrentIdentityException.php`.
7. Wiring exacto de fingerprint en `tests/manual/support/durable-retry-a11-coordinator.php`.
8. Lectura de `DurableRetryA11CrashParticipantIdentityExceptionCatalog`.
9. Lectura de WR-04 relay/ownership catalogs.
10. `tests/manual/durable-retry-a11-concurrent-participant-identity-catalog-test.php`.

Se excluyen materializer, cardinality, topology general y suites EA6 completas. Sin comodines.

## 17. Matriz adversarial

| R | Escenario | Resultado |
|---:|---|---|
| 01–05 | catálogo, 62/62, groups, participants, dependencies completos | acepta |
| 06–07 | domain general/crash válido | acepta |
| 08–11 | regex/base0/ID/index general aplicados a crash | crash exception mismatch |
| 12–14 | general ID/index duplicado o gap | participant/general index invalid |
| 15–16 | ordinal duplicado o gap | membership ordinal invalid |
| 17 | slot duplicado | observer slot invalid |
| 18 | WR-04 correcto | acepta |
| 19–21 | requester/stub toma schedule o server toma Webpay | WR-04 ownership invalid |
| 22–23 | crash window/kill incorrecto | crash window invalid |
| 24 | loopback válido | acepta dependency |
| 25–31 | role/lifecycle/result/observation/dependency/port/relay inválido | reason correspondiente |
| 32–33 | timing inverso/PID distinto | catálogo idéntico |
| 34 | freeze | acepta |
| 35 | mutación post-freeze | mutation after freeze |
| 36 | ausencia de winner | acepta |
| 37 | canal adicional | rechaza relay/dependency |

## 18. Cierre

El catálogo pre-spawn queda completo: siete grupos, catorce miembros generales, cinco crash excepcionales y ocho dependencias. Identidad, index, ordinal, role, lifecycle, results, ownership y eligibility son independientes de timing, PID y resultado.
