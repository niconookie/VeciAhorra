# VeciAhorra Durable Retry A11 EA6: excepción normativa de identidad de participantes crash

Estado: contrato normativo cerrado. Fecha: 2026-08-05.

## 1. Veredicto y alcance

`A11 EA6 CRASH PARTICIPANT IDENTITY EXCEPTION IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`

Esta corrección resuelve exclusivamente la incompatibilidad entre la futura gramática general role-based/base 0 y los cinco bindings crash vigentes. No implementa EA6 ni modifica arrivals, proposals, ventanas, challenges, counts, hashes o materialización.

## 2. Autoridades reconciliadas

Se inspeccionaron conjuntamente participant action proposal materialization, crash arrival action proposal, intermediate barrier control plane, invocation ID catalog, action invocation plan/constructor, expected actions globales y caso-específicas, matrices CR-01..05 y las APIs de DTO, codec, parser, validator y temporary store.

Las fuentes coinciden literalmente para las cinco tuplas `(invocation_id,case_id,phase,participant_id,participant_index,window,port,proposal_count)`. La gramática y el índice existentes forman parte del frame y de su binding estricto; no son placeholders.

## 3. Dos dominios normativos

Dominio general futuro: participantes no crash podrán usar una gramática con role y ordinal e índices locales base 0 únicamente cuando la autoridad del catálogo general lo publique.

Dominio crash cerrado: CR-01..05 conservan `a11p_<case_id>_<phase>_<participant_index_2d>`, índice 1-based, suffix `_01` y role separado. No se inserta role, no se renumera, no hay alias ni migración runtime. El parser selecciona el dominio por invocation antes de validar el ID; la excepción es estructural, no tolerancia.

## 4. Catálogo literal 5/5

Lifecycle exacto común: spawn child→request/binding→acción observada→un `barrier_arrival` con propuesta→LF/flush→bloqueo→kill externo→wait de crash esperado→EOF→cleanup. Binding authority: crash arrival action proposal §§3, 5, 9 y intermediate barrier control plane §§3–11.

| Invocation | Caso | Fase | Participant ID | Index | Suffix | Role | Kind | Result | Observation | Crash window | Port | Propuestas | Kill | Lifecycle |
|---|---|---|---|---:|---|---|---|---|---|---|---|---:|---|---|
| `a11_000000000011_fd` | `A11-CR-01` | `first_delivery` | `a11p_A11-CR-01_first_delivery_01` | 1 | `01` | `external_scheduler` | `crash_child` | `barrier_arrival` | `crash_observer` | `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | `scheduler.action_schedule` | 1 | true | común cerrado |
| `a11_000000000013_fd` | `A11-CR-02` | `first_delivery` | `a11p_A11-CR-02_first_delivery_01` | 1 | `01` | `stage_processor` | `crash_child` | `barrier_arrival` | `crash_observer` | `CRASH_AFTER_LOCAL_CLAIM` | `durable.worker_execute` | 1 | true | común cerrado |
| `a11_000000000015_fd` | `A11-CR-03` | `first_delivery` | `a11p_A11-CR-03_first_delivery_01` | 1 | `01` | `reconciliation_attempt` | `crash_child` | `barrier_arrival` | `crash_observer` | `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | `durable.worker_execute` | 1 | true | común cerrado |
| `a11_000000000017_fd` | `A11-CR-04` | `first_delivery` | `a11p_A11-CR-04_first_delivery_01` | 1 | `01` | `schedule_repository` | `crash_child` | `barrier_arrival` | `crash_observer` | `CRASH_AFTER_RESULT_PERSISTED` | `durable.worker_execute` | 1 | true | común cerrado |
| `a11_000000000019_fd` | `A11-CR-05` | `first_delivery` | `a11p_A11-CR-05_first_delivery_01` | 1 | `01` | `executor` | `crash_child` | `barrier_arrival` | `crash_observer` | `CRASH_BEFORE_CALLBACK_RETURN` | `durable.worker_execute` | 1 | true | común cerrado |

No se deriva ninguna celda por analogía: son los valores literales publicados por las autoridades vigentes.

## 5. Schema de excepción

Schema: `veciahorra-a11-crash-participant-identity-exception/v1`. Objeto superior con key set exacto `schema`, `kind`, `entries`, `catalog_fingerprint`. `kind=crash_participant_identity_exception_catalog`; `entries` es lista de exactamente cinco objetos; fingerprint son 64 hex lowercase.

Cada entry tiene key set exacto y tipos: `invocation_id:string`, `case_id:string`, `phase:string`, `participant_id:string`, `participant_index:int`, `suffix:string`, `role:string`, `participant_kind:string`, `result_kind:string`, `observation_kind:string`, `crash_window:string`, `port:string`, `proposal_count:int`, `requires_external_kill:bool`, `lifecycle_kind:string`.

```json
{"catalog_fingerprint":"ee13ab744a135948fe2c0331b7adf3e3757ded1b7364077c052c43469e8f8436","entries":[{"case_id":"A11-CR-01","crash_window":"CRASH_AFTER_EXTERNAL_ACTION_CREATED","invocation_id":"a11_000000000011_fd","lifecycle_kind":"crash_barrier_external_kill","observation_kind":"crash_observer","participant_id":"a11p_A11-CR-01_first_delivery_01","participant_index":1,"participant_kind":"crash_child","phase":"first_delivery","port":"scheduler.action_schedule","proposal_count":1,"requires_external_kill":true,"result_kind":"barrier_arrival","role":"external_scheduler","suffix":"01"},{"case_id":"A11-CR-02","crash_window":"CRASH_AFTER_LOCAL_CLAIM","invocation_id":"a11_000000000013_fd","lifecycle_kind":"crash_barrier_external_kill","observation_kind":"crash_observer","participant_id":"a11p_A11-CR-02_first_delivery_01","participant_index":1,"participant_kind":"crash_child","phase":"first_delivery","port":"durable.worker_execute","proposal_count":1,"requires_external_kill":true,"result_kind":"barrier_arrival","role":"stage_processor","suffix":"01"},{"case_id":"A11-CR-03","crash_window":"CRASH_AFTER_FUNCTIONAL_ATTEMPT","invocation_id":"a11_000000000015_fd","lifecycle_kind":"crash_barrier_external_kill","observation_kind":"crash_observer","participant_id":"a11p_A11-CR-03_first_delivery_01","participant_index":1,"participant_kind":"crash_child","phase":"first_delivery","port":"durable.worker_execute","proposal_count":1,"requires_external_kill":true,"result_kind":"barrier_arrival","role":"reconciliation_attempt","suffix":"01"},{"case_id":"A11-CR-04","crash_window":"CRASH_AFTER_RESULT_PERSISTED","invocation_id":"a11_000000000017_fd","lifecycle_kind":"crash_barrier_external_kill","observation_kind":"crash_observer","participant_id":"a11p_A11-CR-04_first_delivery_01","participant_index":1,"participant_kind":"crash_child","phase":"first_delivery","port":"durable.worker_execute","proposal_count":1,"requires_external_kill":true,"result_kind":"barrier_arrival","role":"schedule_repository","suffix":"01"},{"case_id":"A11-CR-05","crash_window":"CRASH_BEFORE_CALLBACK_RETURN","invocation_id":"a11_000000000019_fd","lifecycle_kind":"crash_barrier_external_kill","observation_kind":"crash_observer","participant_id":"a11p_A11-CR-05_first_delivery_01","participant_index":1,"participant_kind":"crash_child","phase":"first_delivery","port":"durable.worker_execute","proposal_count":1,"requires_external_kill":true,"result_kind":"barrier_arrival","role":"executor","suffix":"01"}],"kind":"crash_participant_identity_exception_catalog","schema":"veciahorra-a11-crash-participant-identity-exception/v1"}
```

Canonical JSON usa claves ascendentes por bytes UTF-8, arrays en el orden CR-01..05, UTF-8 sin BOM y tipos estrictos. `catalog_fingerprint=sha256(canonical_json({schema,kind,entries}))`, excluyendo solo el propio fingerprint. El owner es el coordinator/catalog builder; construye de literales, valida, calcula fingerprint y congela antes del primer spawn. No existe setter ni reconstrucción desde runtime.

## 6. Gramática crash exacta

Regex exclusiva:

```text
\Aa11p_A11-CR-0[1-5]_first_delivery_01\z
```

Charset ASCII exacto `[A-Za-z0-9_-]`; longitud mínima=máxima=32 bytes; case-sensitive; sin whitespace, normalización Unicode, aliases o segmentos adicionales. El case debe concordar con la fila, fase es solo `first_delivery`, suffix son dos dígitos y `intval(suffix,10)===participant_index===1`.

No contiene role, no admite `_00`, renumeración ni variante role-based. No se aplica a participantes normales.

## 7. Excepción de índices 1-based

El scope es local a cada una de las cinco invocations. Cada una tiene un único crash participant catalogado, rango cerrado `{1}` y mapping `1→participant_id→01`. No existe índice 0, segundo crash participant ni rebasing. Contigüidad significa exactamente `[1]`, no `[0]`. Integración, lookup y orden usan el valor literal 1.

## 8. Role separado

`role` es campo obligatorio del descriptor, no forma parte del ID ni del suffix y no permite alias. Roles literales: CR-01 `external_scheduler`; CR-02 `stage_processor`; CR-03 `reconciliation_attempt`; CR-04 `schedule_repository`; CR-05 `executor`. Describen el capture point ya publicado, sin prioridad ni winner.

## 9. Consumo por descriptor futuro

Un descriptor crash acepta el ID legado exacto, index 1, role separado, `crash_child`, `barrier_arrival`, `crash_observer`, window/port exactos y external kill true. Debe rechazar normalización, regeneración con role, resta de uno, alias, cambio de arrival o cambio de proposal.

El catálogo general detecta primero la invocation excepcional, carga esta entry y evita sus reglas generales de gramática/base. No duplica entry ni identidad.

## 10. Binding con `barrier_arrival`

ID e index conservados deben coincidir literalmente en descriptor esperado, action invocation plan enriquecido, crash barrier, arrival DTO, canonical frame, proposal nested, temporary store, kill validation, wait, EOF y futura incorporación al participant set. Una desigualdad invalida la invocation completa.

El arrival conserva schema, challenge, PID, window, framing, LF/flush y lifecycle vigentes. Esta autoridad solo fija qué descriptor se espera.

## 11. Binding con `participant_action_proposal`

La proposal nested repite el mismo participant ID, case, phase y port. Invocation, index, window, PID y challenge pertenecen al binding del envelope/descriptor y deben concordar mediante el coordinator aunque no sean claves internas de la proposal. No hay traducción crash→general antes o después de materializar.

Proposal count es exactamente 1 por entry. Local ordinal, productive identity, payload y provenance permanecen bajo la autoridad de proposal vigente.

## 12. Lookup y almacenamiento

Catálogo esperado: mapas inmutables por `invocation_id`, `participant_id` y `(invocation_id,participant_index)`. Coordinator registry: `(invocation_id,participant_id)→descriptor`. Temporary crash proposal store: primera clave invocation, segunda participant ID. Observed state y participant set futuro conservan ID e index originales.

Lookups obligatorios: invocation, ID, index y tupla invocation/ID. Role nunca es clave sustitutiva.

## 13. Interacción con catálogo global

Orden obligatorio: detectar invocation crash; cargar descriptor literal; omitir gramática general; omitir base 0; conservar role separado; validar unicidad global del ID; validar index único local; exigir secuencia `[1]`; usar index existente para orden; prohibir segunda identidad.

Esta excepción no convierte replay CR en crash: solo las cinco first-delivery invocations de §4 pertenecen al dominio.

## 14. Comparador y orden total

`participant_index=1` entra directamente al comparador global. No cambia phase rank, operation rank, port rank, productive identity, local ordinal o proposal identity; tampoco crea offset. No se reenumera antes de comparar. El catálogo general debe ordenar dominios mediante sus descriptores congelados, no homogeneizando bases.

## 15. API PHP exacta

Path futuro: `tests/manual/support/DurableRetryA11CrashParticipantIdentityExceptionCatalog.php`. Namespace: `VeciAhorra\Tests\Manual\A11`.

```php
<?php
declare(strict_types=1);
namespace VeciAhorra\Tests\Manual\A11;

final readonly class DurableRetryA11CrashParticipantIdentityExceptionEntry {
    public function __construct(public string $invocationId, public string $caseId, public string $phase, public string $participantId, public int $participantIndex, public string $suffix, public string $role, public string $participantKind, public string $resultKind, public string $observationKind, public string $crashWindow, public string $port, public int $proposalCount, public bool $requiresExternalKill, public string $lifecycleKind) {}
    /** @return array<string,mixed> */
    public function toCanonicalArray(): array { throw new \LogicException('crash_identity_exception_contract_only'); }
}

final class DurableRetryA11CrashParticipantIdentityExceptionCatalog {
    /** @return self */
    public static function create(): self { throw new \LogicException('crash_identity_exception_contract_only'); }
    /** @return list<DurableRetryA11CrashParticipantIdentityExceptionEntry> */
    public function all(): array { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function isCrashExceptionInvocation(string $invocationId): bool { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function byInvocation(string $invocationId): DurableRetryA11CrashParticipantIdentityExceptionEntry { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function byParticipantId(string $participantId): DurableRetryA11CrashParticipantIdentityExceptionEntry { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function byParticipantIndex(string $invocationId, int $participantIndex): DurableRetryA11CrashParticipantIdentityExceptionEntry { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function validateSuffix(DurableRetryA11CrashParticipantIdentityExceptionEntry $entry): void { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function validate(): void { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function freeze(): void { throw new \LogicException('crash_identity_exception_contract_only'); }
    public function isFrozen(): bool { throw new \LogicException('crash_identity_exception_contract_only'); }
    /** @return array<string,mixed> */
    public function toCanonicalArray(): array { throw new \LogicException('crash_identity_exception_contract_only'); }
}

final class DurableRetryA11CrashParticipantIdentityException extends \RuntimeException {
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
```

Constructor interno del catálogo es privado; `create()` carga solo los cinco literales. Cada lookup desconocido lanza la excepción única con reason de §18. `freeze()` valida, calcula fingerprint, marca una vez y vuelve inmutable; segunda llamada es idempotente solo si bytes idénticos.

## 16. Invariantes y precedencia de validación

Orden cerrado: schema; cinco entries; cinco invocations; cinco cases; phase; IDs literales; índices; suffix 2d; suffix/index; IDs únicos; invocations únicas; index único por invocation; secuencia `[1]`; role separado; participant kind; result kind; observation kind; window; port; proposal count; external kill; arrival binding; proposal binding; freeze; prohibición de mutación.

El primer fallo por este orden determina reason. Cualquier fallo invalida invocation/catalog antes de spawn o, si se descubre durante ejecución, invalida toda invocation, produce cero integración, conserva states previos y ejecuta kill/drain/wait/EOF/cleanup conforme al control plane.

## 17. Precedencia normativa

Esta corrección prevalece sobre toda gramática general con role y regla base 0 para estas cinco invocations. Conserva las autoridades de materialization, crash arrival y barrier; no modifica IDs, índices, ventanas, proposals, challenges, framing, counts, hashes o materialización y no añade canales.

Es consumo obligatorio para concurrent participant identity catalog, concurrent group cardinality, multiprocess topology e implementación EA6. La reserva futura solo queda levantada para publicar esta excepción; ningún otro dominio obtiene ID o index por analogía.

## 18. Reasons cerrados

| # | Reason | Condición | Etapa | Efecto / rollback / cleanup |
|---:|---|---|---|---|
| 1 | `crash_identity_exception_schema_invalid` | schema/key set/tipo inválido | parse | invalida; cero integración; cleanup total |
| 2 | `crash_identity_exception_entry_count_mismatch` | count distinto de 5 | catalog | igual efecto común |
| 3 | `crash_identity_exception_invocation_unknown` | lookup ajeno | lookup | igual efecto común |
| 4 | `crash_identity_exception_invocation_duplicate` | invocation repetida | catalog | igual efecto común |
| 5 | `crash_identity_exception_participant_id_mismatch` | ID no literal | identity | igual efecto común |
| 6 | `crash_identity_exception_participant_id_duplicate` | ID repetido | identity | igual efecto común |
| 7 | `crash_identity_exception_participant_index_mismatch` | index distinto de 1 | identity | igual efecto común |
| 8 | `crash_identity_exception_participant_index_zero_forbidden` | index 0 | identity | igual efecto común |
| 9 | `crash_identity_exception_suffix_mismatch` | suffix no `01` o no iguala index | identity | igual efecto común |
| 10 | `crash_identity_exception_role_embedded_in_id` | segmento role dentro del ID | identity | igual efecto común |
| 11 | `crash_identity_exception_alias_forbidden` | alias del ID | identity | igual efecto común |
| 12 | `crash_identity_exception_rebasing_forbidden` | resta/offset/renumeración | integration | igual efecto común |
| 13 | `crash_identity_exception_window_mismatch` | window desigual | descriptor | igual efecto común |
| 14 | `crash_identity_exception_port_mismatch` | port desigual | descriptor | igual efecto común |
| 15 | `crash_identity_exception_proposal_count_mismatch` | count distinto de 1 | descriptor | igual efecto común |
| 16 | `crash_identity_exception_arrival_binding_mismatch` | arrival difiere | runtime binding | igual efecto común |
| 17 | `crash_identity_exception_proposal_binding_mismatch` | proposal difiere | runtime binding | igual efecto común |
| 18 | `crash_identity_exception_external_kill_mismatch` | kill no true/incorrecto | lifecycle | igual efecto común |
| 19 | `crash_identity_exception_entry_missing` | fila esperada ausente | catalog | igual efecto común |
| 20 | `crash_identity_exception_entry_unexpected` | fila adicional | catalog | igual efecto común |
| 21 | `crash_identity_exception_catalog_not_frozen` | uso antes de freeze | freeze | igual efecto común |
| 22 | `crash_identity_exception_mutation_after_freeze` | cambio posterior | freeze | igual efecto común |

Dentro de una etapa, la precedencia es el número de fila. Rollback descarta DTOs/stores temporales, no altera combined state ni hashes. Cleanup termina hijos vivos, drena/cierra pipes y verifica wait, EOF, challenges y buffers a cero.

## 19. Allowlist futura exacta

1. `tests/manual/support/DurableRetryA11CrashParticipantIdentityExceptionEntry.php`.
2. `tests/manual/support/DurableRetryA11CrashParticipantIdentityExceptionCatalog.php`.
3. `tests/manual/support/DurableRetryA11CrashParticipantIdentityExceptionValidator.php`.
4. `tests/manual/support/DurableRetryA11CrashParticipantIdentityException.php` y catálogo de reasons literal.
5. Wiring de lectura exclusivo en el futuro concurrent identity catalog.
6. `tests/manual/durable-retry-a11-crash-participant-identity-exception-test.php`.

No se autorizan cambios de IDs, arrivals, proposals, barrier, materializer, topology general, cardinalidad colectiva o harnesses generales EA6. No hay comodines.

## 20. Matriz adversarial

| Escenario | Resultado |
|---|---|
| R01 catálogo válido 5/5 | acepta y congela |
| R02 CR-01 válido | acepta literal |
| R03 CR-02 válido | acepta literal |
| R04 CR-03 válido | acepta literal |
| R05 CR-04 válido | acepta literal |
| R06 CR-05 válido | acepta literal |
| R07 falta CR-01 | entry missing |
| R08 falta CR-05 | entry missing |
| R09 entry adicional | entry unexpected |
| R10 ID role-based | role embedded in ID |
| R11 suffix `_00` | index zero forbidden |
| R12 index cambiado | participant index mismatch |
| R13 suffix/index desigual | suffix mismatch |
| R14 alias | alias forbidden |
| R15 invocation intercambiada | participant ID mismatch |
| R16 case intercambiado | participant ID mismatch |
| R17 phase distinta | participant ID mismatch |
| R18 window intercambiada | window mismatch |
| R19 port incorrecto | port mismatch |
| R20 proposal count distinto | proposal count mismatch |
| R21 external kill false | external kill mismatch |
| R22 arrival con ID distinto | arrival binding mismatch |
| R23 proposal con ID distinto | proposal binding mismatch |
| R24 arrival con index distinto | arrival binding mismatch |
| R25 proposal asociado a index distinto | proposal binding mismatch |
| R26 role separado válido | acepta |
| R27 role incrustado | role embedded in ID |
| R28 lookup por invocation | retorna entry única |
| R29 lookup por ID | retorna entry única |
| R30 lookup por index 1 | retorna entry única |
| R31 secuencia `[1]` | acepta |
| R32 validator base 0 general | no se aplica |
| R33 orden global usa index 1 | acepta sin offset |
| R34 freeze válido | acepta |
| R35 mutación post-freeze | mutation after freeze |
| R36 cinco counts preservados | acepta |
| R37 canal adicional | rechaza por autoridad de transport |

## 21. Cierre

La excepción permite retomar el catálogo global sin elegir entre contratos incompatibles: cinco IDs intactos, cinco índices 1-based intactos, role separado y secuencia excepcional `[1]`. Ningún valor se traduce, deriva o migra en runtime.
