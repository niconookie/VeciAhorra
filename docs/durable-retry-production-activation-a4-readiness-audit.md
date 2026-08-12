# Auditoría de readiness A4 — transferencia inicial hacia autoridad durable

## 1. Propósito y veredicto

Esta auditoría determina si puede implementarse A4 sin decisiones funcionales
discrecionales. A4 es la única autoridad que puede materializar
`generation = 1` para una identidad de conciliación que todavía conserva
autoridad legacy.

**Veredicto: A4 IMPLEMENTABLE.**

La implementación está habilitada únicamente bajo los contratos, matriz,
transacción, firmas, allowlist y exclusiones fijados en este documento. A4 no
es todavía integración productiva: no selecciona cohortes, no agenda trabajo y
no modifica ni conecta el worker legacy.

## 2. Preflight auditado

La auditoría comenzó sobre:

- rama `main`;
- HEAD `bad92a7b95a6fbaa858ab3a091a584a5e7b423e4`;
- divergencia `0` atrás / `37` adelante respecto de `origin/main`;
- staging vacío;
- cero cambios tracked;
- `Config::SCHEMA_VERSION = '0.24.0'`;
- 13 documentos protegidos intactos;
- 504 archivos bajo `artifacts/`;
- inventario no versionado preexistente preservado;
- inexistencia inicial de este documento.

No se ejecutaron transferencias ni escrituras de negocio durante la auditoría.

## 3. Contratos y autoridades inspeccionados

### 3.1 A1

- `DurableRetryAuthorityIdentity`
- `DurableRetryGenerationIdentity`
- `DurableRetryInitialTransferRequest`
- `DurableRetryInitialTransferResult`
- `DurableRetryInitialTransferReason`
- `DurableRetryInitialTransferAuthorityInterface`
- `DurableRetryActivationContractException`
- `docs/durable-retry-production-activation-a1-contracts-spec.md`

A1 ya cierra la solicitud, los siete estados de resultado y sus razones. A4 no
puede añadir estados o reason codes.

### 3.2 A2 y A2.1

- `DurableRetryActivationPolicyInterface`
- `DeterministicDurableRetryActivationPolicy`
- `DurableRetryActivationConfigurationSourceInterface`
- `WordPressDurableRetryActivationConfigurationSource`
- `DurableRetryActivationConfiguration`
- especificaciones A2, A2.1 y composición productiva.

A2 decide elegibilidad de una transferencia nueva. A2.1 entrega una snapshot
inmutable de configuración por invocación del productor.

### 3.3 A3

- `DurableRetryLegacyExclusionInterface`
- `DurableRetryLegacyAuthorityRepository`
- resultados `legacy`, `durable` e `indeterminate`;
- corrección normativa A3.

A3 es un lector previo y read-only. Su resultado no constituye por sí solo
permiso para escribir.

### 3.4 Persistencia y autoridad funcional

- `DurableRetryScheduleRepositoryInterface`
- `DurableRetryScheduleRepository`
- `DurableRetryScheduleSnapshot`
- `DurableRetryScheduleSchema`
- `PaymentReconciliationSchema`
- `PaymentReconciliationClaimRepository`
- `PaymentReconciliation`
- diseño y especificación de composición productiva.

La tabla funcional `payment_reconciliations` contiene estado, contador de
intentos y lease legacy. La tabla durable impone unicidad sobre
`(stage, subject_id, generation)` y sobre el slot activo.

## 4. Frontera normativa entre A3, A2/A2.1, A4 y el productor

La secuencia de composición futura es:

```text
identidad A1
  → clasificación batch A3
  → si y sólo si A3 = legacy: snapshot A2.1 + decisión A2
  → si y sólo si A2 = true: A4
  → solo TRANSFERRED habilita coordinación durable inicial posterior
```

A4 no recibe A3, A2, A2.1, scheduler, coordinator ni logger. Esta separación
evita que una autoridad ya durable sea reevaluada por porcentaje y evita que
una configuración defectuosa se convierta silenciosamente en legacy.

Aunque A3 haya devuelto `legacy`, A4 debe volver a comprobar bajo lock la fila
funcional y `generation = 1`. La lectura A3 puede quedar obsoleta entre la
clasificación y la transacción.

## 5. Matriz completa A3/A2

| Clasificación A3 | Decisión A2/A2.1 | Conducta de composición | Invocación A4 |
|---|---|---|---|
| `legacy` | habilitada | candidata a transferencia | exactamente una |
| `legacy` | deshabilitada | conservar legacy; cero escrituras durable | ninguna |
| `durable` | cualquiera | no-op de composición; autoridad durable permanente | ninguna |
| `indeterminate` | cualquiera | bloquear ambas ramas | ninguna |
| lectura/error A3 | no disponible | bloquear; nunca degradar | ninguna |
| `legacy` | error o configuración A2/A2.1 inválida | bloquear; nunca degradar | ninguna |

A2 se evalúa únicamente para `A3 = legacy` y únicamente en el flujo futuro que
la composición defina como recién materializado. Una identidad durable no se
somete a A2. Una identidad preexistente sin `generation = 1` no se convierte
en nueva por el solo hecho de ser leída.

“Conservar legacy por A2” y “configuración inválida” no son resultados A4:
ocurren antes de invocar A4. El catálogo A1 no debe ampliarse para representar
decisiones que A4 no tomó.

## 6. Solicitud e identidad persistida

La única solicitud permitida es:

```php
DurableRetryInitialTransferRequest::reconciliation(
    DurableRetryAuthorityIdentity $authority,
    int $completionId,
    DateTimeImmutable $scheduledForUtc
): DurableRetryInitialTransferRequest
```

Invariantes:

- `stage = reconciliation`;
- `subject_id = payment_reconciliations.id`;
- `completion_id = subject_id`;
- `generation = 1`;
- `attempt_number = 0`;
- `scheduled_for` proviene de la solicitud UTC, sin microsegundos;
- `reason_code = retryable_failure`.

La fila inicial completa debe ser:

| Campo | Valor |
|---|---|
| `public_id` | 64 caracteres hexadecimales criptográficamente aleatorios |
| `stage` | `reconciliation` |
| `subject_id` | identidad A1 |
| `completion_id` | igual a `subject_id` |
| `generation` | `1` |
| `attempt_number` | `0` |
| `scheduled_for` | `request->scheduledForDatabase()` |
| `scheduled_action_id` | `null` |
| `dispatch_token_hash` | SHA-256 hexadecimal de token aleatorio no persistido en claro |
| `status` | `dispatching` |
| `active_slot` | `1` |
| `version` | `1` |
| `reason_code` | `retryable_failure` |
| `dispatched_at` | `null` |
| `claimed_at` | `null` |
| `consumed_at` | `null` |
| `terminal_at` | `null` |
| `created_at` | el instante UTC único de transferencia |
| `updated_at` | igual a `created_at` |

Una fila `generation = 1` existente es compatible solo si su snapshot completo,
salvo el `id` autogenerado, equivale al snapshot inicial solicitado. Compatible
produce `ALREADY_TRANSFERRED`; cualquier diferencia produce
`DURABLE_INCONSISTENCY`. Nunca se sobrescribe, repara, supersede ni reemplaza
`generation = 1`.

## 7. Elegibilidad funcional y convivencia con el worker legacy

La exclusión mutua se obtiene bloqueando primero la fila de
`payment_reconciliations`:

```sql
SELECT *
FROM {$wpdb->prefix}va_payment_reconciliations
WHERE id = %d
LIMIT 1
FOR UPDATE
```

El prefijo físico se construye con `$wpdb->prefix . Config::TABLE_PREFIX`.

Bajo el lock:

- fila ausente → `FUNCTIONALLY_INELIGIBLE / FUNCTIONAL_RECORD_ABSENT`;
- estado recién materializado y procesable, sin lease/claim vigente → continuar;
- lease legacy activo o estado `processing` con claim vigente →
  `LEGACY_IN_FLIGHT / LEGACY_CLAIM_IN_FLIGHT`;
- estado terminal, intentos agotados o estado no procesable →
  `FUNCTIONALLY_INELIGIBLE / FUNCTIONAL_STATE_INELIGIBLE`.

La implementación debe reutilizar las constantes de `PaymentReconciliation` y
la semántica de lease persistida; no duplicar strings divergentes.

`PaymentReconciliationClaimRepository::acquireLease()` actualiza la misma fila.
InnoDB serializa su `UPDATE` con el `SELECT ... FOR UPDATE` de A4. Por tanto,
el ganador queda demostrado:

- si legacy adquirió el lease primero, A4 observa el claim y no inserta;
- si A4 tomó el lock primero, crea y confirma `generation = 1` antes de liberar
  la fila;
- el wiring futuro del worker debe consultar autoridad antes de adquirir o
  adelantar un intento. Esa conexión pertenece a hitos posteriores y queda
  prohibida en A4.

A4 no cancela un schedule legacy ya creado. La integración productiva no podrá
activarse hasta certificar la consulta de autoridad en el scheduler/worker
legacy; de otro modo podrían adelantarse intentos o existir trabajo duplicado.

## 8. Operación persistente autorizada

`DurableRetryScheduleRepository::create()` no es suficiente como autoridad A4:
crea una fila durable y converge duplicate keys, pero no bloquea ni valida la
fila funcional, no controla la transacción completa y no distingue todos los
resultados A1.

Se requiere un repositorio dedicado:

```php
namespace VeciAhorra\Modules\Orders\Contracts;

interface DurableRetryInitialTransferRepositoryInterface
{
    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult;
}
```

```php
namespace VeciAhorra\Modules\Orders\Repositories;

final class DurableRetryInitialTransferRepository
    implements DurableRetryInitialTransferRepositoryInterface
{
    public function __construct(private readonly wpdb $database);

    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult;
}
```

El repositorio dedicado es la única autoridad SQL de A4. Puede reutilizar
validación de dominio, pero no delegar una transacción parcial que cambie el
orden de locks.

El servicio es deliberadamente delgado:

```php
namespace VeciAhorra\Modules\Orders\Services;

final class DurableRetryInitialTransferAuthority
    implements DurableRetryInitialTransferAuthorityInterface
{
    public function __construct(
        private readonly DurableRetryInitialTransferRepositoryInterface $repository
    );

    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult;
}
```

## 9. Transacción, atomicidad e idempotencia

Orden obligatorio:

1. `START TRANSACTION`;
2. bloquear `payment_reconciliations.id` con `FOR UPDATE`;
3. validar existencia, elegibilidad y ausencia de claim legacy vigente;
4. leer `generation = 1` dentro de la transacción;
5. existente compatible: `COMMIT` y `ALREADY_TRANSFERRED`;
6. existente incompatible o duplicado imposible: `ROLLBACK` y
   `DURABLE_INCONSISTENCY`;
7. insertar como máximo una fila inicial;
8. releer la identidad durable y validar el snapshot completo;
9. `COMMIT`;
10. devolver `TRANSFERRED`.

Dos transferencias concurrentes se serializan sobre la fila funcional. El
índice unique durable es defensa adicional, no el mecanismo primario.

Ante duplicate key:

1. no reintentar el `INSERT`;
2. releer obligatoriamente `(reconciliation, subject_id, generation=1)`;
3. compatible → `ALREADY_TRANSFERRED`;
4. incompatible o múltiples evidencias → `DURABLE_INCONSISTENCY`;
5. lectura fallida o resultado no demostrable → `OUTCOME_UNCERTAIN`.

Ante escritura incierta, pérdida de conexión o commit de resultado no
demostrable, se prohíbe afirmar `PERSISTENCE_ERROR` sin certeza de rollback.
Debe intentarse la relectura autoritativa permitida por la conexión:

- creación demostrada y compatible → resultado convergente según evidencia;
- ausencia demostrada y rollback confirmado → `PERSISTENCE_ERROR`;
- cualquier duda → `OUTCOME_UNCERTAIN`.

`TRANSFERRED` significa “creado por esta invocación y commit demostrado”.
`ALREADY_TRANSFERRED` significa “la autoridad durable compatible ya existía o
una invocación concurrente convergió”. Solo el primero permite scheduling
inicial posterior.

No hay loops de retry, sleeps, reemplazo ni supersedencia de generación 1.

## 10. Catálogo contractual exacto

| Estado A1 | Razón permitida | Interpretación A4 |
|---|---|---|
| `TRANSFERRED` | `INITIAL_TRANSFER_CREATED` | generación 1 creada y confirmada por esta invocación |
| `ALREADY_TRANSFERRED` | `EQUIVALENT_TRANSFER_EXISTS` | generación 1 compatible ya durable |
| `LEGACY_IN_FLIGHT` | `LEGACY_CLAIM_IN_FLIGHT` | legacy ganó el claim funcional |
| `FUNCTIONALLY_INELIGIBLE` | `FUNCTIONAL_RECORD_ABSENT` | no existe la reconciliación |
| `FUNCTIONALLY_INELIGIBLE` | `FUNCTIONAL_STATE_INELIGIBLE` | estado funcional no transferible |
| `DURABLE_INCONSISTENCY` | `EXISTING_TRANSFER_INCOMPATIBLE` | generación 1 existe pero no equivale |
| `DURABLE_INCONSISTENCY` | `DUPLICATE_DURABLE_IDENTITY` | evidencia durable duplicada/contradictoria |
| `PERSISTENCE_ERROR` | `PERSISTENCE_WRITE_FAILED` | fallo conocido, rollback demostrado |
| `OUTCOME_UNCERTAIN` | `PERSISTENCE_OUTCOME_UNCERTAIN` | no se puede demostrar commit o rollback |

Correspondencia con las preguntas de readiness:

- éxito creado → `TRANSFERRED`;
- ya durable → `ALREADY_TRANSFERRED`;
- conservado como legacy por A2 → A4 no se invoca;
- bloqueado por autoridad indeterminada → A4 no se invoca;
- conflicto → `DURABLE_INCONSISTENCY`;
- resultado incierto → `OUTCOME_UNCERTAIN`;
- configuración inválida → A4 no se invoca;
- error de persistencia conocido → `PERSISTENCE_ERROR`.

No existe un octavo estado “configuración inválida”, “deshabilitado”,
“conflict” o “indeterminate” dentro de A4.

## 11. Presupuesto operacional verificable

### Composición previa futura

- una clasificación batch A3;
- A2/A2.1 solo si el resultado es `legacy`;
- una snapshot A2.1 por invocación del productor o una snapshot compartida por
  el lote completo, nunca una snapshot distinta por identidad dentro del lote;
- cero invocaciones A4 para `durable`, `indeterminate`, A2 deshabilitada o error
  A2/A2.1.

### Dentro de A4 por identidad elegible

- una transacción;
- un lock funcional;
- una lectura de generación 1;
- cero escrituras si hay claim legacy, ineligibilidad, fila compatible o
  inconsistencia;
- como máximo un `INSERT` durable;
- una relectura tras inserción, duplicate key u outcome incierto;
- un `COMMIT` o `ROLLBACK` terminal;
- cero `UPDATE` o `DELETE` sobre schedules;
- cero loops de retry y cero sleeps.

A4 no ejecuta A3 ni A2; por ello sus presupuestos no se suman dentro del
repositorio transaccional.

## 12. Allowlist exacta de implementación A4

Nuevos productivos:

```text
app/Modules/Orders/Contracts/DurableRetryInitialTransferRepositoryInterface.php
app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php
app/Modules/Orders/Services/DurableRetryInitialTransferAuthority.php
```

Harnesses nuevos:

```text
tests/manual/durable-retry-initial-transfer-authority-test.php
tests/manual/durable-retry-initial-transfer-authority-mysql-test.php
tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php
```

Total: seis archivos nuevos, cero modificados.

No se modifica `DurableRetryScheduleRepositoryInterface`: su método `create()`
permanece útil para su responsabilidad general, pero no sustituye el repositorio
transaccional A4.

## 13. Harnesses requeridos

### Funcional

- delegación exacta del servicio al repositorio;
- siete estados y nueve razones A1 sin extensiones;
- snapshot inicial completo y determinista salvo entropía;
- igualdad `completion_id = subject_id`;
- `generation=1`, `attempt_number=0`, `dispatching`, `active_slot=1`,
  `version=1`, `retryable_failure`;
- fila compatible → `ALREADY_TRANSFERRED`;
- incompatible/duplicada → `DURABLE_INCONSISTENCY`;
- ausencia/ineligible/claim legacy con cero escrituras;
- errores conocidos versus outcome incierto.

### MySQL real

- nombres físicos con prefijo WordPress personalizado;
- orden `START TRANSACTION`, lock funcional, lectura durable, escritura,
  relectura y `COMMIT`;
- rollback en cada rama fallida;
- dos conexiones concurrentes: una `TRANSFERRED` y la otra convergente;
- carrera real con `acquireLease()`;
- duplicate key compatible e incompatible;
- pérdida o fallo inyectado antes del insert, después del insert y alrededor de
  commit;
- exactamente una generación 1;
- ningún overwrite/supersede;
- cero scheduling y cero efectos persistentes del harness fuera de fixtures
  transaccionales limpiados.

### Infraestructura

- FQCN, firmas, constructores y dependencias exactas;
- una sola autoridad SQL A4;
- ausencia de A2, A2.1, A3, Action Scheduler, hooks, workers, callbacks,
  logger, métricas, sleeps y retries;
- tabla funcional bloqueada antes que la durable;
- SQL preparado;
- sin cambios en schema, migraciones ni contratos A1;
- allowlist exacta y staging vacío.

También deben repetirse las regresiones A1, A2, A2.1, A3 y el repositorio de
schedules.

## 14. Exclusiones

A4 no:

- evalúa porcentaje, cohorting ni configuración;
- clasifica autoridad mediante A3;
- agenda en Action Scheduler;
- coordina schedules;
- registra hooks o callbacks;
- ejecuta workers;
- adquiere, libera o renueva leases legacy;
- cancela trabajo legacy;
- modifica resultados funcionales;
- crea generaciones posteriores;
- hace backfill;
- emite logs, métricas, eventos o filtros;
- modifica schema, migraciones, `Config`, container o wiring;
- altera código A1, A2, A2.1 o A3.

## 15. Riesgos y bloqueos posteriores

No hay ambigüedad documental bloqueante para implementar A4 bajo esta
auditoría. Persisten riesgos que deben probarse:

1. carrera entre el lock A4 y el `UPDATE` de adquisición de lease legacy;
2. conexión perdida después del insert o durante commit;
3. duplicate key no atribuible a la identidad durable esperada;
4. snapshot existente parcialmente compatible;
5. entropía inválida o repetida para `public_id`/token;
6. uso de un motor sin semántica transaccional/row locks;
7. integración posterior que invoque legacy después de confirmar generación 1.

El bloqueo de integración productiva permanece: antes de conectar callbacks o
tráfico real debe certificarse que scheduler y worker legacy consultan la
autoridad y se serializan con A4. Ese trabajo no pertenece a la allowlist A4.

Si MySQL real no demuestra lock, rollback, concurrencia y outcome incierto, A4
no puede recertificarse aunque el harness funcional sea verde.

## 16. Secuencia de implementación

1. Repetir preflight sobre el commit documental que contenga esta auditoría.
2. Crear el contrato del repositorio A4.
3. Implementar el repositorio transaccional con el orden de locks fijado.
4. Implementar el servicio delegado.
5. Crear harness funcional e infraestructura.
6. Crear harness MySQL con dos conexiones y fallos inyectados.
7. Ejecutar regresiones A1/A2/A2.1/A3/schedules.
8. Verificar `php -l`, `git diff --check`, allowlist y staging vacío.
9. Entregar implementación local sin wiring, commit ni push.
10. Realizar una recertificación separada antes de cualquier commit selectivo.

## 17. Criterio final

A4 puede implementarse porque:

- la entrada A1 es cerrada;
- la matriz A3/A2 queda fuera de A4 y está completamente definida;
- la fila inicial está especificada campo por campo;
- existe una operación transaccional dedicada y una secuencia de locks exacta;
- concurrencia, duplicate key e incertidumbre tienen salida cerrada;
- el catálogo de siete resultados ya existe;
- la allowlist y el presupuesto operacional son verificables.

Esto no autoriza A5, wiring, callbacks, scheduling ni cambios al worker legacy.
