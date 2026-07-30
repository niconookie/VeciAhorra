# Auditoría de implementabilidad A6 — resolución durable inicial read-only

## 1. Veredicto ejecutivo

**A6 IMPLEMENTABLE**

A6 puede implementarse exactamente sobre la base actual como un adaptador
read-only entre una decisión durable A5 y el repositorio durable certificado.
Recibe el request inicial y el resultado A5, ejecuta una única llamada
`findByIdentity(reconciliation, subjectId, 1)`, valida el snapshot persistido y
devuelve un resultado cerrado para A7.

No se encontraron bloqueos. Las firmas, cinco rutas, estados, reason codes,
invariantes, presupuesto y 12 casos funcionales quedan cerrados en esta
auditoría. El siguiente microhito es implementar A6 bajo la allowlist de §21,
sin integración separada y sin commit hasta una recertificación posterior.

## 2. Estado base certificado

| Control | Resultado |
|---|---|
| Rama | `main` |
| HEAD | `46499c2400ab9893d24cbad8423637d6e6caeba0` |
| Divergencia | `0` atrás / `46` adelante |
| Staging | vacío |
| Cambios tracked | `0` |
| Suite Durable Retry | `60/60`, `4.560` assertions |
| Fallos / warnings / notices / deprecations | `0 / 0 / 0 / 0` |
| Integraciones de executor | cuatro verdes |
| `artifacts/` | `504` |
| Temporales / índices temporales | `0 / 0` |
| A6–A9 / wiring | no implementados |
| Push | no realizado |

A10 está versionado con SHA-256
`a0b28304715a4a0a4389de5743a425cbe5b0bb07939b5cef77ab81bc94db79bf`.
Los documentos A4, A5, A10 y el código A1–A5 permanecen intactos.

## 3. Autoridad normativa

Precedencia aplicable:

1. contratos PHP certificados A4/A5;
2. `docs/durable-retry-production-wiring-a10-normative-correction.md`;
3. correcciones normativas A5 y A4;
4. auditorías A5 y A4;
5. especificación histórica de composición y diseño histórico.

A10 reemplaza la regla histórica que atribuía a A5 creación y scheduling. La
regla vigente es: A4 materializa, A5 decide, A6 resuelve, A7 coordina.
Ninguna afirmación histórica puede autorizar un INSERT o scheduling en A6.

## 4. Responsabilidad exacta

A6 recibe directamente:

- `DurableRetryInitialTransferRequest`, que contiene la identidad canónica;
- `DurableRetryInitialAuthorityProductionResult`, que demuestra una rama
  durable continuable.

A6 no deriva autoridad desde hooks ni recibe un `schedule_id`. Verifica que A5
sea `durable_created`, `durable_converged` o `durable_existing`; para cualquier
otro estado devuelve incompatibilidad sin consultar.

A6 no llama A2, A3, A4, A5, A7, coordinator, adapter, executor, processors ni
legacy. Recupera como máximo una fila, valida compatibilidad y devuelve un
resultado tipado. No tiene efectos laterales.

## 5. Inventario real del repositorio

Interfaz:
`VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface`,
en `app/Modules/Orders/Contracts/DurableRetryScheduleRepositoryInterface.php`.

Implementación:
`VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository`, en
`app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php`.

Constructor real:

```php
public function __construct(
    ?wpdb $database = null,
    ?callable $duplicateKeyDetector = null
);
```

Método real:

```php
public function findByIdentity(
    string $stage,
    int $subjectId,
    int $generation
): DurableRetryPersistenceResult;
```

La interfaz lo declara en líneas 18–22; el repositorio lo implementa en líneas
162–178. Valida stage, subject positivo y generation positiva. Ejecuta:

```sql
SELECT *
FROM <wordpress-prefix>va_durable_retry_schedules
WHERE stage = %s AND subject_id = %d AND generation = %d
LIMIT 1
```

La consulta es preparada por `readOne()`. Ejecuta máximo una consulta física.
Ausencia retorna `DurableRetryPersistenceResult::NOT_FOUND` sin snapshot.
Error retorna `PERSISTENCE_ERROR`. Fila válida retorna
`EXISTING_COMPATIBLE` con `DurableRetryScheduleSnapshot`.

## 6. Snapshot persistido

Tipo exacto:
`VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot`.
Su forma cerrada tiene 20 columnas y es validada por `fromArray()`.

| Campo | Tipo / nullable | Validación | Uso A6 | Salida A7 |
|---|---|---|---|---|
| `id` | int no null | `>=1` | schedule id | sí |
| `public_id` | string no null | hex 64 | coherencia snapshot | no |
| `stage` | string no null | catálogo stage | igualdad request | no |
| `subject_id` | int no null | `>=1` | igualdad request | no |
| `completion_id` | `?int` | null o `>=1` | igual subject | no |
| `generation` | int no null | `>=1` | debe ser 1 | sí |
| `attempt_number` | int no null | `>=0` | debe ser 0 | no |
| `scheduled_for` | string no null | UTC SQL | compatibilidad/resultado | sí |
| `scheduled_action_id` | `?int` | null o positivo | matriz status | no |
| `dispatch_token_hash` | string | hex 64 | coherencia snapshot | no |
| `status` | string | catálogo cerrado | determina resultado | indirecto |
| `active_slot` | `?int` | 1 activo/null terminal | compatibilidad | no |
| `version` | int | `>=1` | compatibilidad | no |
| `reason_code` | string | matriz reason/status | coherencia | no |
| `dispatched_at` | `?string` | UTC/matriz | coherencia | no |
| `claimed_at` | `?string` | UTC/matriz | coherencia | no |
| `consumed_at` | `?string` | UTC/matriz | coherencia | no |
| `terminal_at` | `?string` | UTC/matriz | coherencia | no |
| `created_at` | string | UTC requerido | coherencia | no |
| `updated_at` | string | UTC requerido | coherencia | no |

A6 expone a A7 únicamente id, generación y `scheduled_for` en el resultado.
No expone public id, token, timestamps operativos ni reason persistido.

## 7. Identidad de búsqueda

La llamada exacta es:

```php
$repository->findByIdentity(
    DurableRetryStage::RECONCILIATION,
    $request->authority()->subjectId(),
    DurableRetryInitialTransferRequest::INITIAL_GENERATION
);
```

Stage procede de una constante de dominio. Subject procede del request A5 ya
validado. Generation procede de `INITIAL_GENERATION`, valor `1`.
`completion_id` no forma parte de la consulta; se valida en el snapshot.

Quedan prohibidos como parámetros de búsqueda: schedule id, completion id,
attempt, status, action id, hook, group y valores de payload externo.

## 8. Relación A5 → A6

| Estado A5 | Invoca A6 | Identidad | Máximo de lecturas | Cierre |
|---|---:|---|---:|---|
| `durable_created` | sí | request gen1 | 1 | resuelto/error A6 |
| `durable_converged` | sí | request gen1 | 1 | resuelto/error A6 |
| `durable_existing` | sí | request gen1 | 1 | resuelto/error A6 |
| `legacy_allowed` | no | ninguna | 0 | incompatibilidad de entrada si se invoca |
| `legacy_in_flight` | no | ninguna | 0 | incompatibilidad |
| `functionally_ineligible` | no | ninguna | 0 | incompatibilidad |
| `authority_indeterminate` | no | ninguna | 0 | incompatibilidad |
| `durable_inconsistency` | no | ninguna | 0 | incompatibilidad |
| `configuration_invalid` | no | ninguna | 0 | incompatibilidad |
| `persistence_error` | no | ninguna | 0 | incompatibilidad |
| `outcome_uncertain` | no | ninguna | 0 | incompatibilidad |
| `operational_failure` | no | ninguna | 0 | incompatibilidad |

A6 nunca transforma un estado no durable en continuable y nunca permite legacy.

## 9. Relación A4 → A6

A4 ejecuta la transacción, locks, duplicate-key handling, INSERT máximo uno,
commit y reconciliación de commit incierto. A5 traduce el resultado A4.

A6 se ejecuta después de A5. Observa el estado final mediante el repositorio
general; no recibe el repositorio A4, no llama
`transferReconciliation()`, no reabre transacción y no reproduce clasificación
de duplicate key. La dependencia única en constructor impide una segunda
transferencia inicial por construcción.

## 10. Firma definitiva

Ruta de interfaz:
`app/Modules/Orders/Contracts/DurableRetryInitialScheduleResolverInterface.php`.

```php
namespace VeciAhorra\Modules\Orders\Contracts;

interface DurableRetryInitialScheduleResolverInterface
{
    public function resolve(
        DurableRetryInitialTransferRequest $request,
        DurableRetryInitialAuthorityProductionResult $production
    ): DurableRetryInitialScheduleResolutionResult;
}
```

Ruta concreta:
`app/Modules/Orders/Services/DurableRetryInitialScheduleResolver.php`.

```php
namespace VeciAhorra\Modules\Orders\Services;

final class DurableRetryInitialScheduleResolver
    implements DurableRetryInitialScheduleResolverInterface
{
    public function __construct(
        private readonly DurableRetryScheduleRepositoryInterface $repository
    );

    public function resolve(
        DurableRetryInitialTransferRequest $request,
        DurableRetryInitialAuthorityProductionResult $production
    ): DurableRetryInitialScheduleResolutionResult;
}
```

El constructor tiene exactamente una dependencia. La clase posee un solo método
público propio. No usa `mixed`, arrays ni callbacks.

## 11. Resultado tipado A6

Ruta:
`app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialScheduleResolutionResult.php`.

Catálogo exacto:

```php
public const RESOLVED_DISPATCHING = 'resolved_dispatching';
public const RESOLVED_SCHEDULED = 'resolved_scheduled';
public const NOT_FOUND = 'not_found';
public const INCOMPATIBLE = 'incompatible';
public const READ_ERROR = 'read_error';
```

Reason codes exactos:

```php
public const INITIAL_DISPATCH_REQUIRED = 'initial_dispatch_required';
public const INITIAL_DISPATCH_CONFIRMED = 'initial_dispatch_confirmed';
public const INITIAL_SCHEDULE_NOT_FOUND = 'initial_schedule_not_found';
public const INITIAL_SCHEDULE_INCOMPATIBLE = 'initial_schedule_incompatible';
public const INITIAL_SCHEDULE_READ_ERROR = 'initial_schedule_read_error';
```

Factories:

```php
public static function resolvedDispatching(
    int $scheduleId,
    int $generation,
    DateTimeImmutable $scheduledForUtc
): self;

public static function resolvedScheduled(
    int $scheduleId,
    int $generation,
    DateTimeImmutable $scheduledForUtc
): self;

public static function notFound(): self;
public static function incompatible(): self;
public static function readError(): self;
```

Getters:

```php
public function state(): string;
public function reason(): string;
public function scheduleId(): ?int;
public function generation(): ?int;
public function scheduledForUtc(): ?DateTimeImmutable;
public function mayContinueToA7(): bool;
public function permitsLegacy(): bool;
```

Los estados resueltos exigen id positivo, generation exactamente 1 y fecha UTC
sin microsegundos. Los otros estados exigen los tres campos null.
`mayContinueToA7()` es true solo para los dos resueltos.
`permitsLegacy()` siempre devuelve false.

## 12. Validación del snapshot

Antes de devolver resuelto, A6 exige:

1. resultado repository `EXISTING_COMPATIBLE`;
2. snapshot no null;
3. id positivo;
4. stage `reconciliation`;
5. subject igual al request;
6. completion no null e igual al subject;
7. generation exactamente `1`;
8. attempt exactamente `0`;
9. version positiva;
10. status `dispatching` o `scheduled`;
11. active slot exactamente `1`;
12. fecha canónica UTC.

Para `durable_created` y `durable_converged`, `scheduled_for` debe coincidir con
el request. Para `durable_existing`, prevalece la fecha persistida.

A6 inicial no acepta generaciones superiores. Esas generaciones pertenecen al
executor/lifecycle, no al puente inicial.

## 13. Estados durable permitidos

| Estado persistido | Resultado A6 | Continúa A7 |
|---|---|---:|
| `dispatching` | `RESOLVED_DISPATCHING` | sí |
| `scheduled` | `RESOLVED_SCHEDULED` | sí |
| `claimed` | `INCOMPATIBLE` | no |
| `consumed` | `INCOMPATIBLE` | no |
| `superseded` | `INCOMPATIBLE` | no |
| `cancelled` | `INCOMPATIBLE` | no |
| `failed` | `INCOMPATIBLE` | no |
| `orphaned` | `INCOMPATIBLE` | no |

No se inventan estados persistidos. Un estado terminal o claimed confirma
autoridad durable pero no autoriza coordinación inicial.

## 14. Ausencia de fila

`DurableRetryPersistenceResult::NOT_FOUND` produce
`DurableRetryInitialScheduleResolutionResult::notFound()` con reason
`initial_schedule_not_found`.

No lanza excepción, no repite consulta, no llama A4/A7, no ejecuta scheduling,
no permite legacy y no hace retry. A8 lo cerrará como `resolution_failed`.

## 15. Incompatibilidad

Todos los siguientes producen `INCOMPATIBLE` y terminan A6:

- resultado A5 no durable;
- repository code distinto de `EXISTING_COMPATIBLE`, `NOT_FOUND` o
  `PERSISTENCE_ERROR`;
- snapshot ausente con code compatible;
- stage o subject divergentes;
- completion null o divergente;
- generation distinta de 1;
- attempt distinta de 0;
- status no continuable;
- active slot distinto de 1;
- version no positiva;
- fecha inválida o, en created/converged, distinta del request;
- cualquier contradicción entre code y snapshot.

Snapshots incompletos/no canónicos normalmente son rechazados por
`DurableRetryScheduleSnapshot::fromArray()` dentro del repositorio y llegan como
error de lectura. Un double que entregue una contradicción tipada se cierra
como incompatible.

## 16. Excepciones del repositorio

A6 captura `Throwable` exclusivamente alrededor de la única llamada repository
y devuelve `READ_ERROR` con `initial_schedule_read_error`.

No propaga, no reintenta, no registra logs, no agenda, no llama legacy y no
efectúa otra lectura. Excepciones previas causadas por parámetros incompatibles
se evitan validando request/production antes de llamar.

## 17. Presupuesto operacional

| Operación por `resolve()` | Máximo |
|---|---:|
| construcción del resultado | 1 |
| `findByIdentity()` | 1 |
| SELECT físico | 1 |
| INSERT | 0 |
| UPDATE | 0 |
| DELETE | 0 |
| transacciones | 0 |
| locks | 0 |
| COMMIT / ROLLBACK | 0 / 0 |
| Action Scheduler | 0 |
| llamadas A2/A3/A4/A5/A7 | 0 |
| llamadas legacy | 0 |
| logs/métricas | 0 |
| hooks | 0 |
| loops/sleeps/retries | 0 |

Si la entrada no es una rama A5 durable válida, consultas y resultados
persistidos construidos son cero.

## 18. Concurrencia

Dos procesos A6 pueden leer simultáneamente sin conflicto porque no escriben.
El snapshot representa evidencia en el instante de lectura.

Si la fila cambia después:

- A6 no relee ni adquiere locks;
- A7/coordinator vuelve a leer por schedule id y generation;
- el coordinator rechaza generation stale o estado inelegible;
- la asociación usa CAS/version;
- una transición a superseded o terminal se resuelve en A7.

A6 no promete frescura futura. Promete identidad persistida válida en su única
lectura. Esta separación evita duplicar el scheduler guard.

## 19. Seguridad y confianza

Confiables:

- snapshot creado por `DurableRetryScheduleSnapshot`;
- constantes `DurableRetryStage` y `INITIAL_GENERATION`;
- request validado por su factory;
- resultado A5 validado por su constructor.

Derivados: stage, subject y generation de búsqueda.

Prohibidos desde hooks/payload: schedule id, generation, status, completion id,
attempt, action id, version, reason, hook y group. A6 no acepta escalares
externos y nunca usa un schedule id suministrado por caller.

## 20. Compatibilidad con A7

A7 necesita:

- estado resuelto;
- schedule id positivo;
- generation exactamente 1;
- fecha persistida UTC;
- garantía de autoridad no legacy.

El resultado A6 entrega exactamente esos campos. A7 invocará
`DurableRetryExternalScheduleCoordinatorInterface::coordinate(scheduleId,
generation)`. El coordinator realizará su propia lectura de seguridad. Esa
lectura no es duplicación A6: protege el intervalo concurrente y pertenece al
scheduler guard.

## 21. Allowlist exacta A6

| Ruta | Tipo | Contenido permitido | Prohibido |
|---|---|---|---|
| `app/Modules/Orders/Contracts/DurableRetryInitialScheduleResolverInterface.php` | nuevo | interfaz y firma §10 | implementación |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialScheduleResolutionResult.php` | nuevo | catálogo/factories/getters §11 | SQL y servicios |
| `app/Modules/Orders/Services/DurableRetryInitialScheduleResolver.php` | nuevo | validación y una lectura | A4/A5/A7/scheduling |
| `tests/manual/durable-retry-initial-schedule-resolver-test.php` | nuevo | 12 casos funcionales | MySQL/WordPress real |
| `tests/manual/durable-retry-initial-schedule-resolver-infrastructure-test.php` | nuevo | guardias estáticas | inventario global rígido |

Los cinco son nuevos. Ningún archivo existente puede modificarse.

## 22. Harness funcional A6

Cada caso crea repository double y objetos nuevos:

| ID | Escenario | Resultado | Reads |
|---|---|---|---:|
| A6-01 | created + dispatching compatible | resolved dispatching | 1 |
| A6-02 | existing + scheduled, completion presente | resolved scheduled | 1 |
| A6-03 | completion null | incompatible | 1 |
| A6-04 | repository NOT_FOUND | not found | 1 |
| A6-05 | subject/stage divergente | incompatible | 1 |
| A6-06 | generation distinta de 1 | incompatible | 1 |
| A6-07 | estado claimed/terminal | incompatible | 1 |
| A6-08 | contradicción snapshot/code | incompatible | 1 |
| A6-09 | repository lanza | read error | 1 |
| A6-10 | A5 no durable | incompatible | 0 |
| A6-11 | converged y fecha coincidente | resolved dispatching | 1 |
| A6-12 | created y fecha divergente | incompatible | 1 |

Por caso se verifican 12 assertions: state, reason, id, generation, fecha,
continuación, legacy=false, calls exactas, stage, subject, generation consultada
y journal sin operaciones prohibidas. Total de casos: 12. Total base: 144
assertions.

Se añaden 24 guardias de matriz: 12 IDs únicos, 12 ejecutados, campos
obligatorios, cobertura de cinco estados, lectura máxima, cero write methods,
cero A4/A5/A7, cero scheduling/hooks/legacy y limpieza. Total funcional exacto:
**168 assertions**.

## 23. Harness de infraestructura A6

El harness realiza **52 assertions**:

- 10 de rutas, namespaces y existencia;
- 10 de FQCN, final/interface y strict types;
- 10 de constructor único y firma pública exacta;
- 8 del catálogo/factories/invariantes;
- 8 de una referencia `findByIdentity` y ausencia de métodos write;
- 6 de ausencia de SQL literal, hooks, Action Scheduler, legacy, loops y sleeps.

La allowlist se compara con cinco rutas literales. No usa conteos históricos ni
inventarios globales. Falla ante cualquier archivo A6 adicional.

## 24. Integración A6

A10 no autoriza un sexto archivo ni una integración separada A6. La integración
A5→A6 y A4 persistido→A6 está asignada al harness de integración A8.

A6 usa un double tipado de `DurableRetryScheduleRepositoryInterface`. El
repositorio real ya está cubierto por sus harnesses funcional, infraestructura
y MySQL. Repetir MySQL en A6 ampliaría la allowlist y duplicaría autoridad.

## 25. Assertions esperadas

| Harness | Assertions |
|---|---:|
| funcional A6 | 168 |
| infraestructura A6 | 52 |
| integración A6 separada | 0, no autorizada |
| total nuevo A6 | 220 |

El total funcional deriva de 12×12 más 24 guardias. Infraestructura deriva del
desglose 10+10+10+8+8+6.

## 26. Riesgos

| Riesgo | Control |
|---|---|
| segundo INSERT | única interfaz repository general; guardia textual |
| consulta duplicada | contador exacto `<=1` |
| reconstruir generation | constante búsqueda + snapshot como salida |
| confiar payload externo | firma acepta solo tipos de dominio |
| aceptar terminal | matriz §13 |
| snapshot obsoleto | A7 relee y usa CAS |
| duplicar A4 | A6 no depende de interfaz A4 |
| duplicar A7 | cero coordinator/adapter |
| fallback legacy | `permitsLegacy()` siempre false |
| SQL fuera repository | cero SQL literal en servicio |
| ampliar allowlist | comparación de cinco rutas |

## 27. Bloqueos documentales

No se detectaron ambigüedades residuales.

A10 identifica literalmente las cinco rutas, las dos entradas, la dependencia
repository, los cinco estados y la cardinalidad. La API real aporta
`findByIdentity()` y el snapshot cerrado requerido. La aparente falta de
schedule id en `DurableRetryGenerationIdentity` se resuelve de forma normativa
mediante la lectura A6; no requiere modificar A4/A5.

## 28. Secuencia de implementación

1. recertificar base exacta y staging vacío;
2. crear solo las cinco rutas §21;
3. implementar resultado cerrado;
4. implementar interfaz;
5. implementar resolver con una lectura;
6. ejecutar funcional: 168 assertions;
7. ejecutar infraestructura: 52 assertions;
8. ejecutar repositorio existente y suite 60+2;
9. revisar diagnostics, diff, allowlist y filesystem;
10. mantener staging vacío y solicitar recertificación separada;
11. solo entonces staging selectivo, commit y recertificación; sin push.

Detenerse ante archivo adicional, modificación existente, segunda lectura,
write, hook, scheduling, llamada A4/A5/A7/legacy, diagnostic o regresión.

## 29. Criterio de aceptación

A6 queda completado únicamente si:

- usa exactamente una llamada `findByIdentity()` en ramas durables;
- usa cero llamadas en entradas no durables;
- no ejecuta INSERT, UPDATE, transacción ni lock;
- no repite A4 ni A5;
- obtiene schedule id y generation del snapshot;
- devuelve solo el catálogo §11;
- solo dispatching/scheduled continúan;
- no llama A7, Action Scheduler o legacy;
- no registra hooks ni logs;
- los 12 casos y 220 assertions nuevas quedan verdes;
- la suite Durable Retry completa queda verde;
- el diff contiene exactamente los cinco archivos;
- el commit posterior contiene exclusivamente la allowlist;
- no se realiza push.

**A6 IMPLEMENTABLE**
