# Ciclo normativo de procesamiento y sucesión durable de retries

## 1. Propósito, alcance y estado

Este documento cierra las decisiones normativas necesarias para implementar,
en microhitos posteriores, la policy durable de retry, la creación atómica de
la generación sucesora, el ejecutor de callbacks, los puertos de procesamiento
y el wiring con Action Scheduler.

Este microhito es exclusivamente de diseño. No modifica schema, repositorios,
estados, reason codes, callbacks ni procesadores.

Las expresiones normativas `DEBE`, `NO DEBE` y `SOLO` son vinculantes para los
microhitos posteriores.

## 2. Fuentes auditadas y clasificación

### 2.1 Implementación actualmente activa

- `DurableCompletionScheduler`:
  - recibe `attempt` desde la autoridad de etapa después del procesamiento;
  - no programa cuando `attempt >= 5`;
  - calcula `min(3600, 30 * 2^attempt)`;
  - envía únicamente `['authority_id' => $id]` al callback legado;
  - usa los mismos límites para Reconciliation, Business Completion, Delivery
    Completion y Fulfillment Completion.
- `DurableCompletionWorkers` contiene los cuatro call sites de `retry()`:
  - Reconciliation pasa `PaymentReconciliation::attemptCount()`;
  - Business Completion pasa `business_completions.attempt_count`;
  - Delivery Completion pasa `delivery_completions.attempt_count`;
  - Fulfillment Completion pasa `fulfillment_completions.attempt_count`.
- Los repositorios de etapa incrementan `attempt_count` al adquirir el lease.
  El valor observado después de procesar es, por ello, el ordinal del intento
  que acaba de ejecutarse, no el ordinal del próximo intento.
- `PaymentReconciliationClaimRepository` limita claims a cinco y transforma
  el agotamiento de su propia autoridad en `manual_review` con
  `attempts_exhausted`.
- `DurableCompletionRecovery` considera recuperables filas con
  `attempt_count < 5`.

### 2.2 Diseño durable aprobado

`order-admin-durable-retry-authority-design.md` establece:

- una fila por generación;
- identidad generacional `(stage, subject_id, generation)`;
- máximo un slot activo por `(stage, subject_id)`;
- `generation`, `attempt_number`, `scheduled_for` y la correlación externa como
  hechos históricos inmutables o write-once;
- `claimed(N) → superseded(N)` más inserción de `dispatching(N+1)` en una sola
  transacción local;
- Action Scheduler como transporte, nunca como autoridad de procesamiento;
- UTC `Y-m-d H:i:s` y reloj explícito;
- `attempt_number` como snapshot del intento que originó el retry, que no se
  incrementa autónomamente en lectura ni en el repositorio.

### 2.3 Comportamiento legado

El scheduler legado usa hooks y argumentos diferentes de los nuevos contratos:

```php
['authority_id' => $id]
```

También consulta `time()` y programa directamente Action Scheduler. Ese
comportamiento sirve como evidencia de límite y backoff, pero NO es la
arquitectura normativa del nuevo ciclo durable.

### 2.4 Propuesta nueva adoptada

Este documento conserva la semántica probada del contador de etapa, el límite
de cinco procesamientos y el backoff existente, pero mueve planificación,
identidad generacional, timestamps y publicación al contrato durable cerrado.

## 3. Identidad y generación

`generation` es el ordinal interno de una cadena durable de retry:

- cada fila representa exactamente una generación;
- comienza en `1`;
- es inmutable;
- nunca disminuye ni se reutiliza;
- el sucesor es exactamente `N + 1`;
- cada sucesor obtiene un nuevo `schedule_id`;
- el ID de fila no identifica la cadena completa.

Modelo obligatorio:

```text
schedule_id X, generation N, status claimed
    → schedule_id X, generation N, status superseded

schedule_id Y, generation N+1, status dispatching

X != Y
```

Queda prohibido:

```text
UPDATE X
SET generation = N+1,
    attempt_number = A+1,
    scheduled_action_id = NULL
```

## 4. Autoridad de `attempt_number`

### 4.1 Alternativas evaluadas

| Alternativa | Compatibilidad | Idempotencia | Migración | Riesgo |
| --- | --- | --- | --- | --- |
| A. Contador local del retry schedule | Duplica el contador de etapa y contradice el diseño vigente | Requiere reconciliar dos autoridades | No exige DDL, sí reinterpretación de datos | Alto |
| B. La etapa entrega siempre el próximo intento | Conserva una autoridad, pero acopla el procesador a planificación futura | Buena si el resultado está cerrado | No exige DDL | Medio |
| C. Generación y contador de etapa son conceptos separados | Coincide con datos y call sites actuales | La generación deduplica; la etapa cuenta claims | No exige DDL | Bajo |

### 4.2 Decisión normativa

Se adopta la **alternativa C**:

- `generation` cuenta generaciones del schedule;
- `attempt_number` es un snapshot del contador autoritativo de la etapa en el
  instante en que se decide el retry;
- no se exige `generation === attempt_number`;
- el repositorio de retry NO incrementa por sí mismo el intento;
- el procesador devuelve el `attempt_number` confirmado por su autoridad;
- para un fallo realmente procesado, ese valor DEBE ser exactamente
  `claimed.attempt_number + 1`;
- una redelivery técnica que no adquirió lease ni procesó no avanza el contador
  de etapa y no se presenta como nuevo fallo procesado.

La fila de generación `N+1` persiste el contador devuelto por la etapa. En la
secuencia normal de fallos procesados aumenta en uno, pero la autoridad del
incremento sigue siendo la etapa.

### 4.3 Convención real y normativa

Las filas de etapa nacen con `attempt_count = 0`. El claim exitoso incrementa
antes del procesamiento:

| Evento | Contador de etapa | Snapshot del schedule creado |
| --- | ---: | ---: |
| Antes del primer claim | 0 | no existe retry procesado |
| Primer claim y procesamiento | 1 | generación 1 guarda 1 si falla |
| Callback de generación 1 reclama etapa | 2 | generación 2 guarda 2 si falla |
| Callback de generación 2 reclama etapa | 3 | generación 3 guarda 3 si falla |
| Callback de generación 3 reclama etapa | 4 | generación 4 guarda 4 si falla |
| Callback de generación 4 reclama etapa | 5 | no hay sucesor si falla |

Una generación durable representa la espera posterior a un fallo procesado.
No representa el enqueue inicial de una etapa que todavía tiene contador cero.

## 5. Límite de intentos

Se permiten exactamente **cinco procesamientos totales por autoridad de
etapa**:

- un procesamiento inicial;
- hasta cuatro procesamientos de retry;
- el primer intento procesable es `1`;
- el último intento procesable es `5`;
- el agotamiento se declara cuando un resultado `retryable_failure` corresponde
  al intento confirmado `5`;
- nunca se crea una generación para un intento `6`.

| Intento actual confirmado | ¿Puede procesarse? | ¿Puede generarse otro retry? | Próximo intento procesable |
| ---: | --- | --- | ---: |
| 0 | no es intento ejecutado | no aplica | 1 |
| 1 | sí | sí | 2 |
| 2 | sí | sí | 3 |
| 3 | sí | sí | 4 |
| 4 | sí | sí | 5 |
| 5 | sí | no; queda agotado si falla | — |
| ≥ 6 | inválido | no | — |

El límite es común a las cuatro etapas. La autoridad de cada etapa conserva el
derecho a terminar antes por resultado terminal propio.

## 6. Backoff

La regla normativa conserva la regla existente:

```text
backoff_seconds = min(3600, 30 * 2^failed_attempt)
scheduled_for = failure_decided_at_utc + backoff_seconds
```

`failed_attempt` es el intento confirmado por la autoridad de etapa que acaba
de producir `retryable_failure`. No es la generación, el intento siguiente ni
un contador reconstruido.

| Intento que falló | Próximo intento | Demora | ¿Se crea generación? |
| ---: | ---: | ---: | --- |
| 0 | — | 30 s solo existe en legado para espera sin claim; no es fallo procesado | no por esta policy |
| 1 | 2 | 60 s | sí |
| 2 | 3 | 120 s | sí |
| 3 | 4 | 240 s | sí |
| 4 | 5 | 480 s | sí |
| 5 | — | no se calcula | no; agotado |

El límite de 3600 segundos se conserva como defensa aunque no se alcanza
dentro del presupuesto de cinco intentos.

La policy DEBE:

- validar primero `0 <= failed_attempt <= 5`;
- evitar evaluar exponenciación para valores fuera del rango cerrado;
- comprobar suma de segundos sin overflow;
- rechazar fechas fuera del rango representable por el contrato durable;
- parsear y producir `Y-m-d H:i:s` con `DateTimeZone('UTC')` explícito;
- verificar round-trip exacto del timestamp;
- ser independiente de `date_default_timezone_set()` y de WordPress.

No puede calcular desde `scheduled_for`, `claimed_at`, `created_at`, hora de
Action Scheduler ni un reloj interno.

## 7. Resultado común de procesamiento

Los cuatro procesadores implementarán un puerto común cuyo resultado cerrado
distingue:

- `succeeded`;
- `retryable_failure`;
- `terminal_failure`;
- `uncertain_failure`.

`uncertain_failure` es necesario porque una excepción técnica o resultado
ambiguo no demuestra reintentabilidad ni terminalidad.

Datos seguros permitidos:

| Resultado | Datos permitidos |
| --- | --- |
| `succeeded` | código cerrado y `stage_attempt_number` confirmado |
| `retryable_failure` | failure code allowlisted y `stage_attempt_number` confirmado |
| `terminal_failure` | failure code allowlisted y `stage_attempt_number` confirmado |
| `uncertain_failure` | código técnico sanitizado allowlisted; sin mensaje o excepción |

Los procesadores no eligen hook, grupo, generación, external action ID,
reason code durable, argumentos externos ni estado arbitrario del schedule.

## 8. Agotamiento, fallo terminal e incertidumbre

### 8.1 Agotamiento

Cuando el intento confirmado `5` devuelve `retryable_failure`:

```text
claimed → failed
reason_code = processing_attempts_exhausted
```

La regla es común para los cuatro retry schedules. Describe el ciclo de entrega
durable, no sustituye el estado funcional de la etapa.

### 8.2 Fallo terminal

Cuando el procesador confirma `terminal_failure`:

```text
claimed → failed
reason_code = processing_terminal_failure
```

No se crea sucesor ni se invoca el coordinador.

### 8.3 Resultado incierto

Una excepción o resultado cuya reintentabilidad no pueda demostrarse produce:

```text
claimed → orphaned
reason_code = processing_outcome_uncertain
```

Requiere intervención o reconciliación explícita. No genera retry automático.

Estos códigos son distintos de:

- `callback_rejected`: rechazo concluyente de identidad o contrato del callback;
- `scheduling_failed`: fallo concluyente al publicar una generación;
- `dispatch_recovery_exhausted`: agotamiento al recuperar publicación;
- `processing_attempts_exhausted`: presupuesto de procesamiento agotado;
- `processing_terminal_failure`: etapa confirmó un fallo no reintentable;
- `processing_outcome_uncertain`: no puede clasificarse de forma segura;
- razones de inconsistencia de correlación ya existentes.

## 9. Catálogo completo de reason codes

No se elimina ni redefine ningún código existente.

| Reason code | Estado válido | Transición/origen | Autoridad | ¿Otra generación? | Intervención | Recuperación automática |
| --- | --- | --- | --- | --- | --- | --- |
| `retryable_failure` | dispatching, scheduled, claimed | creación/entrega de retry vigente | scheduler/executor | sí, bajo policy | no | sí |
| `stage_became_terminal` | consumed | etapa ya era terminal | procesador + ejecutor | no | no | no |
| `retry_consumed` | consumed | procesamiento exitoso entregado | procesador + ejecutor | no | no | no |
| `superseded_generation` | superseded | generación sucesora creada | repositorio CAS | sucesor ya existe | no | sí |
| `cancelled_by_authority` | cancelled | cancelación autorizada | futura autoridad | no automática | según origen | no |
| `scheduling_failed` | failed | publicación concluyentemente fallida | dispatcher/recovery | solo comando futuro | sí | no |
| `dispatch_recovery_exhausted` | failed | recuperación de dispatch agotada | recovery | solo comando futuro | sí | no |
| `callback_rejected` | failed | callback inválido concluyente | ejecutor | no | según causa | no |
| `external_action_missing` | orphaned | correlación externa ausente | recovery | no | sí | no |
| `external_action_mismatch` | orphaned | correlación externa distinta | recovery | no | sí | no |
| `inconsistency_requires_remediation` | orphaned | contradicción durable | recovery | no | sí | no |
| `processing_attempts_exhausted` **nuevo** | failed | claimed → failed tras intento 5 retryable | policy + ejecutor | no | la etapa decide su escalamiento | no |
| `processing_terminal_failure` **nuevo** | failed | claimed → failed por fallo terminal | procesador + ejecutor | no | depende de etapa | no |
| `processing_outcome_uncertain` **nuevo** | orphaned | claimed → orphaned por ambigüedad | ejecutor | no | sí | no |

Los tres códigos nuevos requieren ampliar y recertificar
`DurableRetryReason`; no requieren DDL.

## 10. Timestamps UTC

### 10.1 Significado

- `claimed_at`: instante explícito en que se ganó el CAS `scheduled→claimed`.
- `failure_decided_at_utc`: instante explícito usado por la policy para
  clasificar el fallo y calcular el próximo schedule.
- `superseded_at_utc`: instante explícito de la transacción que reemplaza la
  generación; normalmente debe ser igual a `failure_decided_at_utc`.
- `terminal_at`: instante explícito del cambio a estado inactivo.
- `updated_at`: instante de la última transición durable confirmada.
- `scheduled_for`: `failure_decided_at_utc + backoff_seconds`.

### 10.2 Fila histórica

En `claimed→superseded`:

```text
terminal_at = supersededAtUtc
updated_at  = supersededAtUtc
```

### 10.3 Fila sucesora

En la nueva fila `dispatching`:

```text
scheduled_for = decision.nextScheduledForUtc
created_at    = supersededAtUtc
updated_at    = supersededAtUtc
dispatched_at = NULL
claimed_at    = NULL
consumed_at   = NULL
terminal_at   = NULL
```

Se exige:

```text
supersededAtUtc = decision.decidedAtUtc
supersededAtUtc <= scheduled_for
```

Todos los valores usan UTC `Y-m-d H:i:s`, precisión de segundos. El repositorio
NO consulta reloj alguno.

## 11. Policy pura

Se aprueba una falla tipada porque el contador procede de la autoridad de
etapa:

```php
public function decideNextAttempt(
    DurableRetryScheduleSnapshot $claimed,
    DurableRetryProcessingFailure $failure,
    string $decidedAtUtc
): DurableRetryNextAttemptDecision;
```

`DurableRetryProcessingFailure` contiene exclusivamente:

- clasificación cerrada `retryable_failure|terminal_failure|uncertain_failure`;
- failure code allowlisted;
- `stage_attempt_number` confirmado por la etapa.

La policy valida:

- snapshot `claimed`;
- etapa cerrada;
- `failure.stage_attempt_number === claimed.attempt_number + 1` para un
  procesamiento nuevo;
- generación sin overflow;
- timestamps UTC;
- presupuesto de cinco intentos.

La decisión contiene:

- código `retry|exhausted|terminal|uncertain`;
- `decidedAtUtc`;
- para retry: `next_generation`, snapshot `next_attempt_number` y
  `next_scheduled_for`;
- para cierre: estado y reason code cerrados.

No contiene hooks, grupos, argumentos externos, snapshots completos ni datos de
etapa.

## 12. Operación transaccional

Firma aprobada:

```php
public function supersedeAndCreateNextGeneration(
    DurableRetryScheduleSnapshot $claimed,
    DurableRetryNextAttemptDecision $decision,
    string $supersededAtUtc
): DurableRetryNextGenerationPersistenceResult;
```

Precondiciones:

- `claimed.status = claimed`;
- decisión `retry`;
- `decision.next_generation = claimed.generation + 1`;
- `decision.next_attempt_number = claimed.attempt_number + 1`;
- `decision.decidedAtUtc = supersededAtUtc`;
- identidad y timestamps válidos;
- external action ID histórico positivo y presente;
- versión CAS positiva.

Aunque el contador procede de la etapa, la igualdad `+1` es exigible aquí
porque esta operación sucede inmediatamente después de un procesamiento
confirmado. No autoriza al repositorio a inventar el valor.

### 12.1 Algoritmo

```text
BEGIN

UPDATE fila histórica
WHERE id = claimed.id
  AND public_id = claimed.public_id
  AND stage = claimed.stage
  AND subject_id = claimed.subject_id
  AND generation = claimed.generation
  AND attempt_number = claimed.attempt_number
  AND status = claimed
  AND active_slot = 1
  AND version = claimed.version
  AND scheduled_action_id = claimed.scheduled_action_id
  AND dispatch_token_hash = claimed.dispatch_token_hash
SET status = superseded,
    active_slot = NULL,
    version = version + 1,
    reason_code = superseded_generation,
    terminal_at = supersededAtUtc,
    updated_at = supersededAtUtc

Exigir exactamente una fila.

INSERT nueva fila dispatching:
  nueva public_id
  mismo stage
  mismo subject_id
  mismo completion_id
  generation = N+1
  attempt_number = decision.next_attempt_number
  scheduled_for = decision.next_scheduled_for
  scheduled_action_id = NULL
  nuevo dispatch_token_hash
  status = dispatching
  active_slot = 1
  version = 1
  reason_code = retryable_failure
  timestamps según sección 10.3

Releer ambas filas y validar snapshots exactos.

COMMIT
```

No cambian en la fila histórica:

- ID, public ID, stage, subject/completion;
- generación e intento;
- `scheduled_for`;
- `scheduled_action_id`;
- dispatch token hash;
- timestamps write-once previos.

La nueva fila NO copia:

- ID o public ID;
- external action ID;
- dispatch token hash;
- `dispatched_at`, `claimed_at`, `consumed_at`, `terminal_at`;
- estado o versión histórica.

### 12.2 Active slot, rollback y concurrencia

- El UPDATE libera el slot antes del INSERT dentro de la misma transacción.
- El índice único `(stage, subject_id, active_slot)` impide dos activos.
- Cualquier fallo posterior a `BEGIN` exige `ROLLBACK`.
- Un UPDATE de cero filas nunca inserta.
- Una colisión de identidad o slot se clasifica, se revierte y se relee fuera
  de la transacción.
- Solo hay convergencia si existe exactamente una generación `N+1` compatible
  en stage, subject, attempt, fecha y estado permitido.
- Una sucesora distinta es conflicto, no éxito.
- Ninguna llamada externa ocurre dentro de la transacción.

Resultado cerrado:

- `next_generation_created`;
- `concurrent_convergence`;
- `cas_conflict`;
- `incompatible_state`;
- `incompatible_decision`;
- `active_slot_conflict`;
- `not_found`;
- `durable_inconsistency`;
- `insert_failed`;
- `persistence_error`.

En éxito incluye los snapshots histórico y sucesor, nunca SQL ni mensajes.

## 13. Identidades

| Identidad | Campos |
| --- | --- |
| Fila | `schedule_id` |
| Generación | `stage + subject_id + generation` |
| Cadena lógica | `stage + subject_id` |
| Slot activo | `stage + subject_id + active_slot=1` |
| Relación materializada | `completion_id`, nullable y write-once |
| Acción externa | hook cerrado + `schedule_id` + generation + grupo; correlacionada por `scheduled_action_id` |

`subject_id` es la referencia canónica por etapa:

- Reconciliation: reconciliation/completion ID certificado;
- Business Completion: reconciliation ID;
- Delivery Completion: business completion ID;
- Fulfillment Completion: business completion ID.

Checkout, Order, Payment y Delivery no se agregan a la identidad del retry
schedule. Se resuelven mediante las relaciones canónicas de cada etapa.

## 14. Flujo futuro del ejecutor

```text
callback
→ validar hook, schedule_id y generation
→ leer snapshot durable
→ claim CAS scheduled→claimed
→ invocar procesador una vez
→ recibir resultado y attempt confirmado
→ aplicar policy
```

Resultados:

```text
succeeded:
  claimed → consumed / retry_consumed

terminal_failure:
  claimed → failed / processing_terminal_failure

retryable_failure en intento 5:
  claimed → failed / processing_attempts_exhausted

uncertain_failure:
  claimed → orphaned / processing_outcome_uncertain

retryable_failure en intentos 1..4:
  claimed(N, X)
  → supersedeAndCreateNextGeneration(...)
  → dispatching(N+1, Y)
  → coordinate(Y, N+1)
```

Queda prohibido:

```php
$coordinator->coordinate($oldScheduleId, $newGeneration);
```

## 15. Autoridades de etapa y límites de atomicidad

| Etapa | Autoridad funcional | Quién la actualiza | Dato de intento devuelto |
| --- | --- | --- | ---: |
| Reconciliation | `payment_reconciliations` | procesador/repositorio de Reconciliation | `attempt_count` posterior al claim |
| Business Completion | `business_completions` | BusinessCompletionProcessor y su repositorio | `attempt_count` posterior al claim |
| Delivery Completion | `delivery_completions` | DeliveryCompletionProcessor y su repositorio | `attempt_count` posterior al claim |
| Fulfillment Completion | `fulfillment_completions` | FulfillmentCompletionProcessor y su repositorio | `attempt_count` posterior al claim |

El procesador actualiza y confirma primero su autoridad funcional. El ejecutor
solo cambia el retry schedule después de recibir un resultado cerrado.

No existe transacción distribuida entre la tabla de etapa y
`durable_retry_schedules`. Por ello:

- éxito del schedule exige resultado funcional confirmado;
- fallo entre ambos commits se conserva como `claimed` recuperable;
- una redelivery no reprocesa ciegamente: relee ambas autoridades;
- la recuperación debe reconciliar el resultado cerrado/idempotente de etapa
  antes de terminar o suceder la generación;
- una excepción ambigua no se convierte automáticamente en retry.

## 16. Compatibilidad e impacto

| Componente | Impacto |
| --- | --- |
| Schema 0.24.0 | Sin migración: columnas e índices representan dos generaciones y slot transferido |
| Snapshot | Sin cambio estructural; mantiene inmutabilidad |
| Repositorio | Nuevo método transaccional y resultado de dos snapshots; recertificación obligatoria |
| Estados | Sin estado nuevo |
| Reason codes | Tres códigos nuevos; cambio de catálogo y pruebas |
| Policy durable | Contrato, failure DTO, decision DTO e implementación nuevos |
| Coordinador | Sin cambio; recibe el nuevo ID y generación |
| Adaptador Action Scheduler | Sin cambio |
| Callbacks futuros | Deben usar hook + schedule ID + generation exactos |
| Schedules existentes | Sin reinterpretación; cada fila conserva sus hechos |
| Pruebas | Nuevas suites de policy, transacción, ejecutor y regresión de catálogos |

Los nuevos reason codes son una ampliación compatible del catálogo interno,
pero requieren revisar cualquier prueba que afirme una lista exacta.

## 17. Recomendación normativa única

1. `generation` cuenta filas sucesoras y siempre avanza `N+1`.
2. `attempt_number` conserva el contador confirmado por la autoridad de etapa;
   no es una segunda autoridad.
3. Se procesan intentos 1 a 5: uno inicial y hasta cuatro retries.
4. El backoff usa el intento que falló: 60, 120, 240 y 480 segundos.
5. Agotamiento: `failed / processing_attempts_exhausted`.
6. Fallo terminal: `failed / processing_terminal_failure`.
7. Ambigüedad: `orphaned / processing_outcome_uncertain`.
8. La policy recibe `DurableRetryProcessingFailure` y `decidedAtUtc`.
9. La transacción recibe `supersededAtUtc` explícito.
10. La cadena se identifica por `stage + subject_id`; cada generación tiene
    nuevo ID.
11. La etapa actualiza su propia autoridad; el ejecutor actualiza únicamente el
    schedule durable.

Estas decisiones requieren aprobación humana antes de cambiar catálogos o
implementar writers. Los principales riesgos son:

- diferencias históricas entre códigos terminales de cada etapa;
- recuperación entre el commit funcional y el commit del schedule;
- coexistencia temporal con hooks legados;
- asegurar que todos los procesadores devuelvan el intento confirmado.

## 18. Secuencia recomendada de microhitos

1. Aprobar este contrato normativo.
2. Implementar catálogo de policy, failure/decision DTO y reason codes.
3. Implementar y certificar `supersedeAndCreateNextGeneration()`.
4. Implementar ejecutor y puerto de procesamiento con dobles.
5. Crear adaptadores de las cuatro autoridades de etapa, uno por microhito o
   con matriz explícita.
6. Registrar callbacks cerrados en un microhito separado.
7. Añadir recuperación acotada de `dispatching/claimed`.
8. Retirar o aislar el scheduling legado solo después de certificar paridad.
