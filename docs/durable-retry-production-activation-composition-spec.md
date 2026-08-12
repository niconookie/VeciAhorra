# Especificación normativa de composición productiva de activación y transferencia inicial

## 1. Estado y veredicto

**IMPLEMENTABLE POR MICROHITOS SECUENCIALES**

Esta especificación cierra las decisiones pendientes para A3, A4, el productor
de selección inicial, su integración en el materializador y el wiring. Ninguno
de esos microhitos queda autorizado a recibir tráfico hasta completar las
guardias legacy A6-A8 y la certificación final.

## 2. Alcance

El documento define exclusivamente contratos, clases futuras, secuencias,
failure policy, observabilidad, transacciones, concurrencia, allowlists y
harnesses. No implementa código, SQL, hooks, scheduling ni wiring.

## 3. Base auditada

- Rama `main`.
- HEAD `3b2027ace55c4b862b4349e5105053c2c729756b`.
- Divergencia `0` atrás / `34` adelante.
- Schema `0.24.0` (`app/Core/Config.php:22`).
- Staging vacío y cero modificaciones tracked.
- A2 y A2.1 versionados.
- 13 documentos protegidos y 504 artefactos intactos.
- Auditoría de composición: 588 líneas, 26 secciones y veredicto
  `BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL`.

## 4. Autoridades

Son autoridades:

- `docs/durable-retry-production-activation-design.md`;
- `docs/durable-retry-production-activation-a1-contracts-spec.md`;
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`;
- `docs/durable-retry-production-activation-configuration-source-spec.md`;
- `docs/durable-retry-production-activation-composition-readiness-audit.md`;
- `docs/durable-retry-production-wiring-design.md`;
- `docs/durable-retry-processing-lifecycle-design.md`.

Las especificaciones A1, A2 y A2.1 prevalecen sobre firmas meramente propuestas
por el diseño original.

## 5. Decisiones heredadas

1. A2 y A2.1 se componen directamente.
2. Una decisión A2 realiza exactamente un `snapshot()`.
3. Cohorting usa sólo `(stage, subject_id)`.
4. Sólo `reconciliation` es admisible.
5. El algoritmo es `sha256-24bit-mod100-v1`.
6. A2 decide permiso para intentar una transferencia nueva, no autoridad.
7. A2.1 es la única fuente productiva del porcentaje.
8. A3 es lectura y clasificación read-only de generation 1.
9. A4 es la única autoridad de transición `legacy → durable`.
10. Generation 1 es marcador durable permanente.
11. A4 no agenda.
12. El coordinator durable agenda únicamente después del commit A4.
13. Las llamadas legacy actuales están en
    `WebpayReconciliationMaterializer.php:125` y `:212`.
14. El materializador no implementará cohorting, clasificación ni persistencia
    durable (`docs/durable-retry-production-activation-design.md:104-124`).

## 6. Secuencia normativa de microhitos

| ID | Nombre | Tráfico |
|---|---|---|
| A3 | Clasificación read-only de autoridad generation 1 | Ninguno |
| A4 | Autoridad transaccional de transferencia inicial | Ninguno |
| A5 | Productor aislado de selección inicial | No invocado |
| A6-A8 | Guardias legacy ya reservadas | Flag 0 |
| A9 | Recovery durable ya reservado | Flag 0 |
| A10 | Integración y wiring atómicos en materializador | Flag 0 |
| A11 | Certificación integral | Flag 0 |
| A12 | Activación canario | Aprobación operacional |

A10 no reutiliza A3 ni A4. Integración y wiring forman un solo microhito porque
el código real contiene construcciones directas del materializador en
`WebpayReturnService.php:263,319` y
`WebpayReturnRecovery.php:16-19`: separarlos dejaría un commit intermedio cuyo
grafo no puede construirse sin dependencias opcionales o fallback, ambos
prohibidos.

## 7. Arquitectura objetivo

```text
WebpayReconciliationMaterializer
→ DurableRetryReconciliationInitialScheduleProducerInterface
→ DurableRetryLegacyExclusionInterface                         (A3)
→ DurableRetryActivationPolicyInterface                       (A2+A2.1)
→ DurableRetryInitialTransferAuthorityInterface               (A4)
→ DurableRetryTransferredScheduleResolverInterface
→ DurableRetryExternalScheduleCoordinatorInterface
→ DurableCompletionScheduler                                  (sólo rama legacy)
```

El productor es el selector exclusivo. Ninguna capa intermedia adicional puede
elegir entre legacy y durable.

## 8. Contratos existentes preservados

```php
public function allowsInitialTransfer(
    DurableRetryAuthorityIdentity $identity
): bool;
```

(`app/Modules/Orders/Contracts/DurableRetryActivationPolicyInterface.php:9-14`).

```php
public function snapshot(): DurableRetryActivationConfiguration;
```

(`app/Modules/Orders/Contracts/DurableRetryActivationConfigurationSourceInterface.php:9-12`).

```php
public function transferReconciliation(
    DurableRetryInitialTransferRequest $request
): DurableRetryInitialTransferResult;
```

(`app/Modules/Orders/Contracts/DurableRetryInitialTransferAuthorityInterface.php:10-15`).

No se modifica ninguno.

## 9. Request del productor

Se crea:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\
DurableRetryReconciliationInitialScheduleRequest
```

Ruta:

```text
app/Modules/Orders/Domain/DurableRetry/
DurableRetryReconciliationInitialScheduleRequest.php
```

Clase `final`, constructor privado y API:

```php
public const NEWLY_MATERIALIZED = 'newly_materialized';
public const PREEXISTING = 'preexisting';

public static function newlyMaterialized(
    int $reconciliationId,
    DateTimeImmutable $scheduledForUtc
): self;

public static function preexisting(
    int $reconciliationId,
    DateTimeImmutable $scheduledForUtc
): self;

public function reconciliationId(): int;
public function materializationState(): string;
public function scheduledForUtc(): DateTimeImmutable;
public function authority(): DurableRetryAuthorityIdentity;
```

Invariantes:

- ID positivo;
- UTC sin microsegundos;
- `subject_id = completion_id = reconciliationId`;
- stage reconciliation;
- generation y attempt no son inputs;
- sólo el materializador determina si creó la reconciliación en esta invocación.

El timestamp procede de un clock inyectado al materializador. El productor no
lee reloj, SQL ni request global para reconstruirlo.

## 10. Productor definitivo

Interfaz:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryReconciliationInitialScheduleProducerInterface
```

Ruta:

```text
app/Modules/Orders/Contracts/
DurableRetryReconciliationInitialScheduleProducerInterface.php
```

Firma:

```php
public function produce(
    DurableRetryReconciliationInitialScheduleRequest $request
): DurableRetryReconciliationInitialScheduleResult;
```

Implementación:

```text
VeciAhorra\Modules\Orders\Services\
DurableRetryReconciliationInitialScheduleProducer
```

Ruta:

```text
app/Modules/Orders/Services/
DurableRetryReconciliationInitialScheduleProducer.php
```

Es clase nueva `final`, constructor público:

```php
public function __construct(
    private readonly DurableRetryLegacyExclusionInterface $authority,
    private readonly DurableRetryActivationPolicyInterface $activation,
    private readonly DurableRetryInitialTransferAuthorityInterface $transfer,
    private readonly DurableRetryTransferredScheduleResolverInterface $schedules,
    private readonly DurableRetryExternalScheduleCoordinatorInterface $coordinator,
    private readonly DurableRetryLegacyInitialSchedulerInterface $legacyScheduler
);
```

No recibe source A2.1 directamente: la policy es su único acceso a activación.

## 11. Resultado cerrado del productor

FQCN:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\
DurableRetryReconciliationInitialScheduleResult
```

Ruta homónima en `Domain/DurableRetry`. Clase `final`, constructor privado,
propiedades `readonly`.

Estados exactos:

```php
public const LEGACY_PREEXISTING = 'legacy_preexisting';
public const LEGACY_ACTIVATION_REJECTED = 'legacy_activation_rejected';
public const DURABLE_EXISTING = 'durable_existing';
public const DURABLE_CREATED = 'durable_created';
public const DURABLE_CONVERGED = 'durable_converged';
public const LEGACY_IN_FLIGHT = 'legacy_in_flight';
public const FUNCTIONALLY_INELIGIBLE = 'functionally_ineligible';
public const DURABLE_INCONSISTENCY = 'durable_inconsistency';
public const OUTCOME_UNCERTAIN = 'outcome_uncertain';
public const SCHEDULING_FAILED = 'scheduling_failed';
```

Campos:

```php
private readonly string $state;
private readonly string $reason;
private readonly ?DurableRetryInitialTransferResult $transferResult;
private readonly ?DurableRetryCoordinationResult $coordinationResult;
```

API:

```php
public function state(): string;
public function reason(): string;
public function transferResult(): ?DurableRetryInitialTransferResult;
public function coordinationResult(): ?DurableRetryCoordinationResult;
public function selectedLegacy(): bool;
public function selectedDurable(): bool;
public function requiresRecovery(): bool;
```

No contiene excepciones, porcentaje, bucket ni snapshot A2. Expone A4 sólo para
estados derivados de A4 y coordinación sólo cuando fue invocada.

Razones exactas:

```text
preexisting_reconciliation_remains_legacy
activation_policy_rejected
durable_authority_already_exists
initial_transfer_created
equivalent_transfer_converged
legacy_claim_in_flight
functional_state_ineligible
durable_authority_inconsistent
transfer_outcome_uncertain
external_scheduling_failed
```

## 12. Excepción del productor

FQCN:

```text
VeciAhorra\Modules\Orders\Exceptions\
DurableRetryReconciliationInitialScheduleProducerException
```

Extiende `RuntimeException`, es `final` y posee:

```php
public const AUTHORITY_UNAVAILABLE = 'initial_authority_unavailable';
public const ACTIVATION_UNAVAILABLE = 'initial_activation_unavailable';
public const INVALID_REQUEST = 'invalid_initial_schedule_request';
public const UNEXPECTED_FAILURE = 'initial_schedule_unexpected_failure';

public static function forCode(
    string $code,
    ?Throwable $previous = null
): self;
public function reasonCode(): string;
```

Mensajes:

```text
Initial durable retry authority is unavailable.
Durable retry activation decision is unavailable.
Invalid durable retry initial schedule request.
Unexpected durable retry initial schedule failure.
```

## 13. A3: implementación normativa

Implementación:

```text
VeciAhorra\Modules\Orders\Repositories\
DurableRetryLegacyAuthorityRepository
```

Ruta:

```text
app/Modules/Orders/Repositories/DurableRetryLegacyAuthorityRepository.php
```

Clase `final` que implementa el contrato existente
`DurableRetryLegacyExclusionInterface`
(`app/Modules/Orders/Contracts/DurableRetryLegacyExclusionInterface.php:12-21`).

Constructor:

```php
public function __construct(private readonly wpdb $database);
```

Métodos exactos son `classify(identity)` y `classifyBatch(collection)`.

## 14. A3: autoridad y clasificación

A3 lee exclusivamente `{$wpdb->prefix}veciahorra_durable_retry_schedules`,
mediante el índice de identidad existente. Busca generation `1` por
`(stage, subject_id)`.

Clasificaciones existentes:

- sin fila generation 1, lectura completa: `legacy()`;
- una fila compatible: `durable()`;
- duplicidad, identidad corrupta o datos incompatibles:
  `indeterminate(CORRUPT_IDENTITY)`;
- error de consulta: `indeterminate(QUERY_FAILED)`.

No existe marcador “legacy” persistido. “Legacy existente” significa ausencia
demostrada del marcador durable junto con request `PREEXISTING`.

Presupuesto:

- `classify`: máximo una consulta;
- `classifyBatch`: exactamente una consulta para colección no vacía;
- colección vacía: cero consultas.

A3 es read-only: no inserta, actualiza, elimina, repara, agenda, aplica hooks,
lee options ni emite logs.

## 15. A4: implementación normativa

FQCN:

```text
VeciAhorra\Modules\Orders\Services\
DurableRetryInitialTransferAuthority
```

Ruta:

```text
app/Modules/Orders/Services/DurableRetryInitialTransferAuthority.php
```

Clase `final` que implementa
`DurableRetryInitialTransferAuthorityInterface`.

Dependencias:

```php
public function __construct(
    private readonly DurableRetryInitialTransferRepositoryInterface $repository
);
```

El método es exactamente el contrato A1. A4 no recibe policy, A3, scheduler,
coordinator ni logger.

## 16. Repository transaccional A4

Contrato nuevo:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryInitialTransferRepositoryInterface
```

Implementación:

```text
VeciAhorra\Modules\Orders\Repositories\
DurableRetryInitialTransferRepository
```

Método único:

```php
public function transferReconciliation(
    DurableRetryInitialTransferRequest $request
): DurableRetryInitialTransferResult;
```

Constructor de implementación:

```php
public function __construct(private readonly wpdb $database);
```

El repository es la única autoridad SQL de la transición.

## 17. Transacción A4

Orden obligatorio:

1. `START TRANSACTION`;
2. bloquear la reconciliación funcional por ID mediante `SELECT ... FOR UPDATE`;
3. validar existencia, estado pendiente y ausencia de claim legacy vigente;
4. releer generation 1 con lock;
5. si existe compatible, `COMMIT` y `ALREADY_TRANSFERRED`;
6. si existe incompatible, `ROLLBACK` y `DURABLE_INCONSISTENCY`;
7. insertar exactamente una fila generation 1 `dispatching`;
8. releer y validar identidad creada;
9. `COMMIT`;
10. retornar `TRANSFERRED`.

El snapshot inicial conserva los campos normativos del diseño
(`docs/durable-retry-production-activation-design.md:127-154`) y usa request A1
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferRequest.php:10-94`).

No existe transacción global con la materialización: ésta ya terminó antes del
productor (`docs/durable-retry-production-activation-design.md:110-120`).

## 18. Resultados y fallos A4

- claim legacy vigente: `LEGACY_IN_FLIGHT`;
- estado funcional no elegible: `FUNCTIONALLY_INELIGIBLE`;
- duplicate compatible: `ALREADY_TRANSFERRED`;
- duplicate incompatible: `DURABLE_INCONSISTENCY`;
- fallo demostrado antes de insertar y rollback confirmado:
  `PERSISTENCE_ERROR`;
- commit, conexión o outcome no demostrable: `OUTCOME_UNCERTAIN`;
- excepción de contrato/programación: se propaga.

A4 sólo persiste autoridad. No agenda, no llama coordinator, no modifica el
resultado funcional, no cancela legacy y no crea generation posterior.

## 19. Resolver de schedule transferido

El resultado A1 expone identidad, no `schedule_id`
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferResult.php:121-143`).
Se añade:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryTransferredScheduleResolverInterface
```

```php
public function resolveInitial(
    DurableRetryGenerationIdentity $identity
): DurableRetryScheduleSnapshot;
```

Implementación:

```text
VeciAhorra\Modules\Orders\Repositories\
DurableRetryTransferredScheduleResolver
```

Depende de `DurableRetryScheduleRepositoryInterface` y exige snapshot exacto
generation 1. Ausencia, duplicidad o mismatch lanzan
`DurableRetryReconciliationInitialScheduleProducerException::AUTHORITY_UNAVAILABLE`.

## 20. Scheduler legacy tipado

Contrato:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryLegacyInitialSchedulerInterface
```

```php
public function scheduleReconciliation(int $reconciliationId): void;
```

Adapter:

```text
VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\
DurableCompletionLegacyInitialSchedulerAdapter
```

Envuelve exclusivamente
`DurableCompletionScheduler::reconciliation(int): void`
(`app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php:7-35`).
Una devolución normal significa solicitud legacy completada bajo la
deduplicación existente. Una excepción se propaga; el adapter no la convierte
en éxito.

## 21. Camino legacy

Para request `PREEXISTING`, A3 se consulta y:

- durable: retorna `DURABLE_EXISTING`, sin A2 ni scheduling;
- indeterminate: lanza `AUTHORITY_UNAVAILABLE`, sin scheduling;
- legacy: llama exactamente una vez al scheduler legacy y retorna
  `LEGACY_PREEXISTING`, sin A2.

Para `NEWLY_MATERIALIZED` con A3 legacy:

- A2 false, incluido porcentaje 0: scheduler legacy una vez y
  `LEGACY_ACTIVATION_REJECTED`;
- A2 true: no scheduler legacy; continúa A4.

La deduplicación legacy sigue en `DurableCompletionScheduler` mediante
`as_has_scheduled_action` (`DurableCompletionScheduler.php:27-35`).

## 22. Camino durable

Precondiciones A4:

- request `NEWLY_MATERIALIZED`;
- A3 `legacy`;
- A2 `true`;
- identidad reconciliation válida.

El productor crea:

```php
DurableRetryInitialTransferRequest::reconciliation(
    $request->authority(),
    $request->reconciliationId(),
    $request->scheduledForUtc()
);
```

`TRANSFERRED`: resuelve schedule, coordina una vez y retorna
`DURABLE_CREATED`. `ALREADY_TRANSFERRED`: resuelve schedule; no vuelve a
coordinar inicialmente y retorna `DURABLE_CONVERGED`. Recovery A9 repara
scheduling faltante.

Resultados no exitosos se mapean uno a uno a los estados cerrados del productor.

## 23. Failure policy externa

| Fallo | Conducta exacta |
|---|---|
| A2.1 inválida | envolver como `ACTIVATION_UNAVAILABLE`, abortar, cero scheduling |
| API WP ausente | igual, causa preservada |
| reader lanza | igual, causa preservada |
| identidad/request inválido | `INVALID_REQUEST`, cero scheduling |
| stage/algoritmo inválido | `ACTIVATION_UNAVAILABLE`, cero scheduling |
| A2 inesperada | `UNEXPECTED_FAILURE`, cero scheduling |
| A3 indeterminate | `AUTHORITY_UNAVAILABLE`, cero scheduling |
| A3 inconsistente | `AUTHORITY_UNAVAILABLE`, cero scheduling |
| A4 antes de persistir | resultado `PERSISTENCE_ERROR`, cero scheduling |
| A4 después de persistir incierto | `OUTCOME_UNCERTAIN`, cero scheduling, recovery |
| scheduling legacy lanza | excepción original propagada |
| scheduling durable falla cerrado | `SCHEDULING_FAILED`, autoridad durable conservada, recovery |
| productor inesperado | `UNEXPECTED_FAILURE`, causa preservada |

El materializador propaga excepciones del productor. No selecciona otra rama.

## 24. Prohibición de fallback silencioso

Nunca se convierten en porcentaje 0, false, legacy, durable o éxito:

- configuración inválida;
- source indisponible;
- A3 indeterminate;
- identidad inválida;
- excepción inesperada;
- outcome A4 incierto;
- scheduling fallido.

Una vez persistida generation 1, ningún fallo permite legacy.

## 25. Frontera transaccional y recovery

La reconciliación funcional puede quedar persistida sin transferencia. En ese
caso una reinvocación con `PREEXISTING` permanece legacy: no es una nueva
transferencia.

Si generation 1 quedó confirmada y scheduling falla, autoridad sigue durable y
A9 debe coordinar posteriormente. Si commit es incierto, no se agenda ninguna
rama hasta relectura autoritativa A3/A4 recovery.

## 26. Idempotencia y reinvocaciones

| Estado previo | Reinvocación |
|---|---|
| Nada transferido, mismo flujo nuevo antes de A4 | A4 serializa y converge |
| A3 legacy + request preexisting | legacy deduplicado; no A2 |
| Generation 1 existente | durable existing; no A2/A4 |
| Transfer incierto | bloquea ambos schedulers; recovery |
| Legacy ya agendado | scheduler legacy deduplica |
| Durable ya agendado | A3 durable; no agenda de nuevo |
| Generation posterior | generation 1 sigue marcando durable |

A2 nunca reclasifica autoridad persistida.

## 27. Concurrencia

Dos productores pueden leer A3 legacy. Ambos pueden evaluar A2, pero A4 adquiere
el mismo lock funcional:

```text
productor A ─┐
             ├→ A4 lock → uno TRANSFERRED
productor B ─┘           → otro ALREADY_TRANSFERRED o LEGACY_IN_FLIGHT
```

Sólo `TRANSFERRED` permite coordinación inicial. Una lectura A3 obsoleta no
otorga autoridad: A4 relee bajo lock. El unique de identidad evita dos
generation 1. Legacy y A4 se serializan mediante el lock funcional exigido por
el diseño (`docs/durable-retry-production-activation-design.md:163-198`).

## 28. Compatibilidad histórica

- reconciliaciones `PREEXISTING`: permanecen legacy si no tienen generation 1;
- generation 1 activa o terminal: durable permanente;
- filas parcialmente transferidas: durable/recovery;
- inconsistencias: indeterminate, ningún scheduling;
- filas sin marcador no se consideran nuevas sin request
  `NEWLY_MATERIALIZED`;
- no hay backfill.

## 29. Integración con materializer

Se sustituyen exactamente:

- `WebpayReconciliationMaterializer.php:125`;
- `WebpayReconciliationMaterializer.php:212`.

Constructor futuro añade:

```php
private readonly DurableRetryReconciliationInitialScheduleProducerInterface $initialSchedules,
private readonly DurableRetryInitialScheduleClockInterface $clock
```

Se elimina la instanciación directa de `DurableCompletionScheduler` del
materializador. Cada rama construye request `NEWLY_MATERIALIZED` sólo cuando
`create()` ganó en esa invocación; ramas que recuperan una fila usan
`PREEXISTING`. Invoca `produce()` una vez antes de retornar
`MaterializedReconciliation`.

Clock:

```text
VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleClockInterface
VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\SystemDurableRetryInitialScheduleClock
```

Firma `public function nowUtc(): DateTimeImmutable`.

## 30. Wiring A10

`Application::registerDurableRetryGraph()` es el único composition root
(`app/Core/Application.php:171-205`).

Orden de bindings lazy:

1. `DurableRetryActivationConfigurationValueReaderInterface`;
2. `DurableRetryActivationConfigurationSourceInterface`;
3. `DurableRetryActivationPolicyInterface`;
4. `DurableRetryLegacyExclusionInterface`;
5. `DurableRetryInitialTransferRepositoryInterface`;
6. `DurableRetryInitialTransferAuthorityInterface`;
7. `DurableRetryTransferredScheduleResolverInterface`;
8. `DurableRetryLegacyInitialSchedulerInterface`;
9. `DurableRetryReconciliationInitialScheduleProducerInterface`;
10. `DurableRetryInitialScheduleClockInterface`;
11. `WebpayReconciliationMaterializer`.

Todos son singleton salvo el request/result. Construir Application no lee SQL,
options, reloj ni agenda. Resolver el productor tampoco ejecuta métodos.

## 31. Observabilidad

A2.1 mantiene cero observabilidad
(`docs/durable-retry-production-activation-configuration-source-spec.md:781-792`).

A3 y A4 no emiten logs, métricas, hooks, filtros, eventos ni trazas. Exponen
resultados/reason codes cerrados; el productor tampoco emite directamente.

La observabilidad operacional pertenece a A11 mediante un observer separado:

```text
DurableRetryInitialScheduleObserverInterface
```

No se incluye en constructores A3-A5. A11 definirá contrato, niveles,
cardinalidad y sink antes del canario. Si observabilidad falla, nunca cambia
autoridad ni resultado. Datos permitidos: stage, subject_id, schedule_id,
generation, state y reason. Prohibidos: tokens, payload, datos financieros, PII,
stack y valor crudo de option.

## 32. Tabla de identidad

| Campo | Hash | A3 | A4 | Persistencia | Scheduling | Idempotencia | Observación |
|---|---:|---:|---:|---:|---:|---:|---:|
| stage | Sí | Sí | Sí | Sí | Hook derivado | Sí | Sí |
| subject_id | Sí | Sí | Sí | Sí | No directo | Sí | Sí |
| completion_id | No | No | Sí | Sí | Processor | Compatibilidad | No |
| generation | No | Fija 1 | Fija 1 | Sí | Sí | Sí | Sí |
| attempt_number | No | No | Fijo 0 | Sí | No inicial | No | No |
| schedule_id | No | No | Resultado persistido | Sí | Sí | Sí | Sí |
| Action Scheduler ID | No | No | No | Write-once posterior | Sí | Sí | No |

## 33. Responsabilidades

| Componente | Decide | Lee | Persiste | Agenda | Clasifica | Resultado |
|---|---|---|---|---|---|---|
| Materializer | creación/preexistencia | funcional | reconciliación | No | No | materialized |
| Productor | rama exclusiva | resultados | No | delega | delega | initial schedule |
| A2 | permiso | snapshot | No | No | cohorte | bool |
| A2.1 | porcentaje | option | No | No | config | snapshot |
| A3 | autoridad observada | durable SQL | No | No | sí | legacy authority |
| A4 | transición bajo lock | funcional+durable | generation 1 | No | elegibilidad | transfer |
| Scheduler legacy | No | cola | cola externa | sí | No | void |
| Coordinator durable | convergencia | durable+AS | asociación | sí | No | coordination |
| Repository durable | No | durable SQL | durable SQL | No | persistencia | persistence |
| Application | construcción | No operativa | No | No | No | grafo |

## 34. Secuencias obligatorias

Marcador legacy/preexistente:

```text
materializer → producer → A3 legacy + PREEXISTING
→ legacy scheduler una vez → LEGACY_PREEXISTING
```

Marcador durable:

```text
materializer → producer → A3 durable
→ sin A2 → sin A4 → sin scheduling → DURABLE_EXISTING
```

Sin marcador, nuevo, A2 desactivado:

```text
materializer → producer → A3 legacy + NEWLY_MATERIALIZED
→ A2 → A2.1 snapshot → false
→ legacy scheduler → LEGACY_ACTIVATION_REJECTED
```

Sin marcador, nuevo, A2 activado:

```text
materializer → producer → A3 legacy + NEWLY_MATERIALIZED
→ A2 → A2.1 snapshot → true → A4
→ commit generation 1 → resolver schedule → coordinator
→ DURABLE_CREATED
```

Configuración inválida:

```text
materializer → producer → A3 legacy → A2 → A2.1 exception
→ ACTIVATION_UNAVAILABLE con causa → cero scheduling → propagación
```

## 35. Allowlist A3

Nuevos productivos:

```text
app/Modules/Orders/Repositories/DurableRetryLegacyAuthorityRepository.php
```

Harnesses nuevos:

```text
tests/manual/durable-retry-legacy-authority-repository-test.php
tests/manual/durable-retry-legacy-authority-repository-mysql-test.php
tests/manual/durable-retry-legacy-authority-infrastructure-test.php
```

Modificados: cero. Total: 4.

## 36. Allowlist A4

Nuevos productivos:

```text
app/Modules/Orders/Contracts/DurableRetryInitialTransferRepositoryInterface.php
app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php
app/Modules/Orders/Services/DurableRetryInitialTransferAuthority.php
```

Harnesses:

```text
tests/manual/durable-retry-initial-transfer-authority-test.php
tests/manual/durable-retry-initial-transfer-authority-mysql-test.php
tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php
```

Total: 6. Schema/migraciones sólo podrán abrir un microhito separado si
`EXPLAIN` demuestra índice insuficiente.

## 37. Allowlist A5

Nuevos productivos:

```text
app/Modules/Orders/Contracts/DurableRetryReconciliationInitialScheduleProducerInterface.php
app/Modules/Orders/Contracts/DurableRetryTransferredScheduleResolverInterface.php
app/Modules/Orders/Contracts/DurableRetryLegacyInitialSchedulerInterface.php
app/Modules/Orders/Domain/DurableRetry/DurableRetryReconciliationInitialScheduleRequest.php
app/Modules/Orders/Domain/DurableRetry/DurableRetryReconciliationInitialScheduleResult.php
app/Modules/Orders/Exceptions/DurableRetryReconciliationInitialScheduleProducerException.php
app/Modules/Orders/Repositories/DurableRetryTransferredScheduleResolver.php
app/Modules/Orders/Infrastructure/DurableRetry/DurableCompletionLegacyInitialSchedulerAdapter.php
app/Modules/Orders/Services/DurableRetryReconciliationInitialScheduleProducer.php
```

Harnesses:

```text
tests/manual/durable-retry-reconciliation-initial-schedule-producer-test.php
tests/manual/durable-retry-reconciliation-initial-schedule-producer-infrastructure-test.php
```

Harness histórico modificado:

```text
tests/manual/durable-retry-schedule-infrastructure-test.php
```

Total: 12.

## 38. Allowlist A10

Nuevos:

```text
app/Modules/Orders/Contracts/DurableRetryInitialScheduleClockInterface.php
app/Modules/Orders/Infrastructure/DurableRetry/SystemDurableRetryInitialScheduleClock.php
tests/manual/webpay-reconciliation-durable-retry-activation-integration-test.php
tests/manual/webpay-reconciliation-durable-retry-activation-infrastructure-test.php
```

Productivos modificados:

```text
app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php
app/Modules/Payments/Service/WebpayReturnService.php
app/Modules/Payments/Orchestration/WebpayReturnRecovery.php
app/Core/Application.php
```

Harnesses existentes modificados:

```text
tests/manual/woocommerce-durable-payment-attempt-test.php
tests/manual/durable-retry-composition-test.php
tests/manual/durable-retry-composition-infrastructure-test.php
```

Harnesses nuevos adicionales:

```text
tests/manual/durable-retry-activation-production-composition-test.php
tests/manual/durable-retry-activation-production-composition-infrastructure-test.php
```

Total A10: 13 archivos: 6 nuevos y 7 modificados. No se permite constructor
opcional, fallback a `new WebpayReconciliationMaterializer()` ni estado
intermedio sin wiring.

## 39. Archivos prohibidos por allowlist

Para A3, A4, A5 y A10 quedan prohibidos salvo enumeración expresa:

```text
app/Core/Config.php
app/Core/Container.php
app/Database/Migrations/
app/Database/Schemas/
app/Modules/Orders/Services/DurableRetryExecutor.php
app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionCallback.php
app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionHookRegistrar.php
assets/
```

## 40. Matriz de pruebas

A3: ausencia, durable activo/terminal, duplicado, corrupto, query failure,
individual una consulta, batch una consulta, colección vacía cero, read-only.

A4: primera transferencia, repetida, compatible, incompatible, claim legacy,
ineligible, rollback, commit incierto, dos productores, generation 1, cero
scheduling.

A5: preexisting legacy/durable/indeterminate; nuevo con A2 false/true; A2.1
inválida/no disponible; A4 siete resultados; resolver; coordinator; scheduling
legacy/durable exclusivo; reinvocación y concurrencia.

A10: sustituir ambas líneas, request newly/preexisting correcto, retorno
materialized intacto, cero doble scheduling, 0/100/cohortes, históricos.

A10: grafo completo, singletons, dependencias exactas, sin ciclos, construcción
lazy, cero SQL/options/reloj/hooks/scheduling al construir.

## 41. Prohibiciones globales

- A3 no escribe.
- A4 no agenda.
- A5 no usa SQL, options o Action Scheduler directamente.
- Materializer no calcula cohortes ni persiste durable.
- A2 false no demuestra autoridad legacy.
- Ningún error se vuelve legacy silenciosamente.
- No se elimina historia generation 1.
- No se amplían stages.
- No hay backfill.
- No hay fallback a Config/env/constants.
- No se modifican schema o migraciones dentro de estas allowlists.

## 42. Riesgos y gates

Riesgos: carrera legacy/A4, commit incierto, fila creada sin acción, histórico
mal etiquetado, scheduler legacy silencioso y construcción eager.

Gates:

1. A3 verde antes de A4.
2. A4 verde con MySQL real antes de A5.
3. A6-A9 verdes antes de A10.
4. A10 se despliega completo con porcentaje 0.
5. A11 antes de cualquier porcentaje mayor a 0.

## 43. Criterio de aprobación

Cada microhito exige:

- allowlist exacta respetada;
- lint completo;
- harnesses nominales verdes;
- regresión durable aislada;
- `git diff --check`;
- staging selectivo;
- schema intacto;
- cero efectos fuera de responsabilidad.

La composición productiva sólo se declara lista tras A11. Este documento deja
A3, A4, A5 y A10 implementables sin decisiones funcionales discrecionales. La
activación de tráfico permanece prohibida hasta A12.
