# Corrección normativa A8 — orquestación inicial de rama única Durable Retry

## 1. Propósito y veredicto

Esta corrección elimina los dos bloqueos documentados por la auditoría A8:
define el puerto legacy booleano y descarta la interfaz propia del router para
preservar la allowlist de seis archivos. Desde su versionado, esta corrección es
autoridad sobre A10 y la auditoría A8 únicamente en esos puntos.

**A8 IMPLEMENTABLE**

## 2. Estado base certificado

| Control | Resultado |
|---|---|
| Rama | `main` |
| HEAD | `f5fd99440ea32766a270bc163bf690b69cce0540` |
| Divergencia | `0` atrás / `51` adelante |
| Staging / tracked | `0 / 0` |
| Suite | `65/65`, `5.162` assertions |
| Diagnostics | `0` fallos, warnings, notices y deprecations |
| Integraciones | cuatro históricas y A7 verdes |
| `artifacts/` | `504` |
| Temporales / índices | `0 / 0` |
| A8 / A9 / wiring | no implementados |
| Push | no realizado |

Integridad: A10 SHA-256
`a0b28304715a4a0a4389de5743a425cbe5b0bb07939b5cef77ab81bc94db79bf`;
auditoría A8 SHA-256
`5a8051e17dd8afade79097984980c492a6b357c71ac04e7896560b3c538766b8`.

## 3. Precedencia normativa

Orden vigente: (1) contratos PHP versionados A5–A7; (2) esta corrección A8;
(3) A10; (4) auditoría A8; (5) auditorías previas; (6) diseño histórico y
runtime legacy como evidencia. Esta corrección reemplaza la interfaz de router
propuesta en la auditoría y completa el puerto legacy omitido por A10. El resto
de A10 permanece intacto.

## 4. Decisión definitiva sobre la interfaz del router

Queda prohibido crear:

`app/Modules/Orders/Contracts/DurableRetryInitialProductionRouterInterface.php`.

El router es la clase concreta final
`VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter`,
interna al grafo productivo y consumida directamente por el registrar A9. A9 es
su único consumidor productivo, no existen implementaciones alternativas y no
se requiere polimorfismo adicional. Los tests sustituyen las cuatro
dependencias del constructor, no el router. Esta decisión preserva testabilidad
y evita un séptimo archivo.

## 5. FQCN definitivos

- Puerto legacy: `VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface`.
- Resultado: `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult`.
- Servicio: `VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter`.

No existe FQCN de interfaz propia A8.

## 6. Puerto legacy autoritativo

Ruta:
`app/Modules/Orders/Contracts/DurableRetryLegacySchedulerInterface.php`.
Contrato completo:

```php
<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

interface DurableRetryLegacySchedulerInterface
{
    public function scheduleReconciliation(
        int $reconciliationId
    ): bool;
}
```

No tiene métodos adicionales. A8 valida `reconciliationId >= 1` antes de
invocarlo.

## 7. Semántica cerrada del retorno legacy

`true` significa que el scheduler confirmó una acción legacy nueva o preservó
una única acción pending compatible para esa reconciliation. La evidencia es
un action ID positivo devuelto por `as_schedule_single_action` o un resultado
positivo de `as_has_scheduled_action` con hook, args y group exactos.

`false` significa API requerida ausente, retorno externo inválido, action ID no
positivo o imposibilidad confirmada de producir/preservar la acción. No significa
autoridad durable, A6/A7, retry, fallback, éxito parcial ni incertidumbre durable.

Una excepción representa fallo inesperado del provider o del adaptador legacy.
El puerto la propaga; A8 la captura y retorna `dependency_failure`. No hay
segunda llamada ni cambio de rama.

## 8. Adaptación del scheduler legacy real

El método actual es
`DurableCompletionScheduler::reconciliation(int $id): void` y no ofrece
evidencia. A9 modificará ese archivo, ya autorizado por la allowlist A9, para
implementar el puerto y añadir `scheduleReconciliation(int): bool`. El método
existente conservará firma/autoridad y delegará al nuevo método ignorando el
booleano para compatibilidad.

El nuevo método reproducirá exactamente la identidad legacy vigente:

```php
$hook = 'veciahorra_process_payment_reconciliation';
$args = ['authority_id' => $reconciliationId];
$group = 'veciahorra-completion';
```

Secuencia cerrada:

1. ID inválido: `false`; A8 nunca entrega este caso.
2. Falta `as_schedule_single_action`: `false` sin llamar provider.
3. Existe `as_has_scheduled_action` y devuelve int positivo: `true`.
4. Agenda una vez mediante `as_schedule_single_action(..., true)`.
5. Action ID entero positivo: `true`.
6. Retorno `0`, `null`, `false` u otro tipo: `false`.
7. `Throwable`: se propaga.

La deduplicación positiva cuenta como preservación confirmada. Si
`as_has_scheduled_action` no existe, se intenta el schedule unique una vez y su
ID es la única confirmación. A8 no inspecciona funciones AS, SQL o repositorios.

## 9. Presupuesto legacy

Por A8: puerto legacy máximo `1`; método real/adaptado máximo `1`; consulta
pending máxima `1`; schedule legacy máximo `1`; loops, sleeps, retry y fallback
durable `0`. Después de invocar legacy, A6 y A7 permanecen en `0`.

## 10. Constructor definitivo A8

```php
public function __construct(
    DurableRetryInitialAuthorityProducerInterface $authorityProducer,
    DurableRetryInitialScheduleResolverInterface $scheduleResolver,
    DurableRetryInitialScheduleCoordinatorInterface $scheduleCoordinator,
    DurableRetryLegacySchedulerInterface $legacyScheduler
);
```

Exactamente cuatro dependencias y ese orden. Quedan prohibidos A2, A3, A4,
configuración, repositorio, coordinador externo, adapter, scheduler concreto,
callback, executor, registry, processors, Application y Bootstrap.

## 11. Firma y validación definitiva A8

```php
public function routeReconciliation(
    int $reconciliationId,
    DateTimeImmutable $scheduledForUtc
): DurableRetryInitialProductionRoutingResult;
```

Es el único método público funcional además del constructor. Exige ID positivo,
offset UTC `0` y microsegundos `000000`. La forma persistida es
`Y-m-d H:i:s`. Construye stage `reconciliation`, subject = completion = ID,
generation `1` y attempt `0` mediante los tipos existentes. Entrada inválida
retorna `invalid_input` sin dependencias.

A8 no acepta schedule ID, generation, action ID, stage, subject/completion
alternativos ni payload externo.

## 12. Punto productivo y hook

Punto A9 futuro en `WebpayReconciliationMaterializer`:

```php
private function publishRetryAuthorityCandidate(
    int $reconciliationId,
    string $scheduledForUtc
): void;
```

No pertenece a A8. A9 captura/valida UTC, emite
`veciahorra_durable_retry_initial_reconciliation` y no llama directamente
A5–A8, legacy ni hook durable. A9 registra callback con prioridad `10`, accepted
args `2`, `(int reconciliationId, string scheduledForUtc)`, convierte la fecha a
`DateTimeImmutable` UTC y llama al router. A8 no registra ni dispara hooks.

## 13. Catálogo global definitivo

Exactamente once literales, sin aliases:

1. `legacy_scheduled`
2. `legacy_unavailable`
3. `durable_synchronized`
4. `durable_already_synchronized`
5. `durable_external_unavailable`
6. `durable_coordination_failed`
7. `durable_coordination_uncertain`
8. `authority_closed`
9. `resolution_failed`
10. `invalid_input`
11. `dependency_failure`

Campos exactos:

```php
private function __construct(
    string $state,
    string $reason,
    int $reconciliationId,
    ?int $scheduleId,
    ?int $generation,
    ?int $scheduledActionId,
    bool $legacyScheduled,
    bool $requiresIntervention
);
```

Accessors homónimos, `state()`, `reason()` y `permitsLegacy(): bool`; este último
solo es true en `legacy_scheduled` y `legacy_unavailable`, porque ambos proceden
de la autorización A5 ya consumida. `legacyScheduled()` solo es true en el
primer estado. Todo resultado termina la invocación; retry local siempre false.

## 14. Factories e invariantes

| Estado | Factory | IDs / flags / razón / recovery |
|---|---|---|
| `legacy_scheduled` | `legacyScheduled(int, string)` | reconciliation positivo; durable IDs null; legacy true; intervención false; razón A5 |
| `legacy_unavailable` | `legacyUnavailable(int, string)` | durable IDs null; scheduled false; intervención false; razón A5; recovery operativo |
| `durable_synchronized` | `durableSynchronized(int, DurableRetryInitialSchedulingResult)` | schedule/gen/action positivos; legacy false; razón A7; sin recovery |
| `durable_already_synchronized` | `durableAlreadySynchronized(int, DurableRetryInitialSchedulingResult)` | IDs positivos; legacy false; razón A7 |
| `durable_external_unavailable` | `durableExternalUnavailable(int, DurableRetryInitialSchedulingResult)` | schedule/gen positivos; action nullable; recovery |
| `durable_coordination_failed` | `durableCoordinationFailed(int, DurableRetryInitialSchedulingResult)` | identidad durable; reason A7; recovery según razón |
| `durable_coordination_uncertain` | `durableCoordinationUncertain(int, DurableRetryInitialSchedulingResult)` | intervención true; reason A7; recovery obligatorio |
| `authority_closed` | `authorityClosed(int, DurableRetryInitialAuthorityProductionResult)` | durable IDs null; reason A5; intervención = requiresRecovery |
| `resolution_failed` | `resolutionFailed(int, DurableRetryInitialScheduleResolutionResult)` | reason A6; action null; intervención true |
| `invalid_input` | `invalidInput()` | reconciliation `0`; todos IDs null; razón `invalid_input`; cero recovery local |
| `dependency_failure` | `dependencyFailure(int)` | IDs durable null; razón `dependency_failure`; intervención true |

Factories durable verifican el estado exacto del DTO A7. Factory resolution
acepta solo los tres estados no continuables. No se construye un resultado con
combinación ajena a la tabla.

## 15. Mapeo A5 definitivo

| A5 | Rama | Legacy/A6/A7 | A8 |
|---|---|---|---|
| `legacy_allowed` | legacy | `1/0/0` | por booleano/excepción |
| `durable_existing` | durable | `0/1/≤1` | por A6/A7 |
| `durable_created` | durable | `0/1/≤1` | por A6/A7 |
| `durable_converged` | durable | `0/1/≤1` | por A6/A7 |
| `legacy_in_flight` | cierre | `0/0/0` | `authority_closed` |
| `functionally_ineligible` | cierre | `0/0/0` | `authority_closed` |
| `authority_indeterminate` | cierre | `0/0/0` | `authority_closed` |
| `durable_inconsistency` | cierre | `0/0/0` | `authority_closed` |
| `configuration_invalid` | cierre | `0/0/0` | `authority_closed` |
| `persistence_error` | cierre | `0/0/0` | `authority_closed` |
| `outcome_uncertain` | cierre | `0/0/0` | `authority_closed` |
| `operational_failure` | cierre | `0/0/0` | `authority_closed` |

Solo `legacy_allowed` permite legacy. Ningún error, inconsistencia o
indeterminación cambia de rama.

## 16. Mapeo legacy definitivo

`true` → `legacy_scheduled`; `false` → `legacy_unavailable`; `Throwable` →
`dependency_failure`. Los dos primeros preservan reason
`activation_policy_rejected`; la excepción usa `dependency_failure`. Después:
legacy adicional, A6, A7, durable fallback y retry son `0`.

## 17. Mapeo A6 definitivo

- `resolved_dispatching` → A7 una vez.
- `resolved_scheduled` → A7 una vez.
- `not_found` → `resolution_failed`, reason `initial_schedule_not_found`.
- `incompatible` → `resolution_failed`, reason `initial_schedule_incompatible`.
- `read_error` → `resolution_failed`, reason `initial_schedule_read_error`.

Los tres cierres ejecutan A7/legacy/segunda lectura/segundo INSERT/segundo A5
`0`.

## 18. Mapeo A7 definitivo

| A7 | A8 |
|---|---|
| `synchronized` | `durable_synchronized` |
| `already_synchronized` | `durable_already_synchronized` |
| `external_unavailable` | `durable_external_unavailable` |
| `coordination_failed` | `durable_coordination_failed` |
| `coordination_uncertain` | `durable_coordination_uncertain` |

Se copian razón e IDs. No hay reinterpretación, segunda A7 ni legacy.

## 19. Algoritmo autoritativo de rama única

```text
validar entrada; inválida → invalid_input
construir identity/request una vez
try authority = A5.produceReconciliation(request) una vez
catch → dependency_failure

si authority == legacy_allowed:
    try accepted = legacy.scheduleReconciliation(id) una vez
    catch → dependency_failure
    true → legacy_scheduled
    false → legacy_unavailable

si authority confirma durable:
    try resolution = A6.resolve(request, authority) una vez
    catch → dependency_failure
    si no continúa → resolution_failed
    try coordination = A7.coordinate(resolution) una vez
    catch → dependency_failure
    retornar mapeo A7

retornar authority_closed
```

Todo retorno termina. A5, legacy, A6 y A7 tienen máximo uno; ramas mutuamente
excluyentes; reevaluación, híbrido y fallback `0`.

## 20. Excepciones y resultados inválidos

| Caso | Retorno/captura | Llamadas posteriores; scheduling/retry/logs A8 |
|---|---|---|
| input/request inválido | `invalid_input` | `0; 0/0/0` |
| A5 throws | `dependency_failure` | `0; 0/0/0` |
| legacy throws | `dependency_failure` | `0; ya pudo existir efecto/0/0` |
| A6 throws | `dependency_failure` | `0; 0 adicional/0/0` |
| A7 throws | `dependency_failure` | `0; 0 adicional/0/0` |
| resultado no reconocido | `dependency_failure` | `0; 0/0/0` |
| dependencia ausente al componer | A9 no construye/invoca A8; callback cierra | `0` |

A8 captura `Throwable` alrededor de cada dependencia. Una excepción nunca
habilita otra rama. A8 no emite logs; A9 observa/cierra el callback.

## 21. Presupuesto operacional cerrado

| Máximo | Legacy | Durable | Cierre |
|---|---:|---:|---:|
| A5 | 1 | 1 | 1 |
| legacy | 1 | 0 | 0 |
| A6 | 0 | 1 | 0 |
| A7 | 0 | 1 | 0 |
| coordinator externo | 0 | 1 indirecto | 0 |
| schedule | 1 legacy | 1 durable | 0 |
| asociación/cancelación | `0/0` | `1/1` indirectas | `0/0` |
| SQL/hooks/retries directos A8 | `0/0/0` | `0/0/0` | `0/0/0` |

No hay loops, sleeps, repository, adapter, AS functions o scheduler concreto en
los tres archivos productivos A8.

## 22. Allowlist definitiva A8

Exactamente seis archivos nuevos:

1. `app/Modules/Orders/Contracts/DurableRetryLegacySchedulerInterface.php`
2. `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialProductionRoutingResult.php`
3. `app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php`
4. `tests/manual/durable-retry-initial-production-router-test.php`
5. `tests/manual/durable-retry-initial-production-router-infrastructure-test.php`
6. `tests/manual/durable-retry-initial-production-router-integration-test.php`

No se crea interfaz del router ni séptimo archivo. A8 no modifica archivos
existentes. La adaptación de `DurableCompletionScheduler` pertenece a A9 y solo
se autoriza cuando se implemente ese microhito.

## 23. Harness funcional A8

Exactamente 24 casos, 15 assertions por caso, **360 assertions**:

1. legacy true → scheduled;
2. legacy false → unavailable;
3. legacy exception → dependency failure;
4. durable created/dispatching/synchronized;
5. durable existing/scheduled/already;
6. durable external unavailable;
7. coordination failed;
8. coordination uncertain;
9. A6 not found;
10. A6 incompatible;
11. A6 read error;
12. configuration invalid;
13. authority indeterminate;
14. durable inconsistency A5;
15. functionally ineligible;
16. invalid ID;
17. invalid timezone/microseconds;
18. A5 exception;
19. A6 exception;
20. A7 exception;
21. A5 exactamente una llamada;
22. legacy xor durable y máximo una llamada;
23. cuatro dependencias exactas, sin interfaz router;
24. presupuesto completo y cero fallback.

Cada caso usa doubles frescos, journal, resultado/razón/IDs/flags, orden,
terminalidad y side effects.

## 24. Harness de infraestructura A8

Exactamente **90 assertions**: seis rutas; ausencia de interfaz router; puerto
con un método booleano; servicio final; constructor de cuatro dependencias en
orden; firma pública; once estados/factories; máximo una llamada por dependencia;
rama única; cero A2–A4, repo, coordinator externo, adapter, scheduler concreto,
SQL, AS, hooks, callback, executor, processors, loops, sleeps, retries y
fallback; allowlist Git explícita. Analiza solo archivos A8 y baseline de callers,
sin inventario global rígido.

## 25. Integración A8

Exactamente 10 escenarios, 20 assertions cada uno, **200 assertions**:

1. legacy confirmado;
2. legacy no disponible;
3. excepción legacy;
4. durable synchronized;
5. durable already synchronized;
6. external unavailable;
7. coordination failed;
8. coordination uncertain;
9. A6 no continuable;
10. dos invocaciones sin doble productor.

Usa A5–A7 reales o doubles contractuales, puerto legacy fake booleano, DB/AS
fake solo bajo componentes dueños, journal, conteos y limpieza `finally`. No
implementa A9.

## 26. Totales y suite futura

| Nuevo A8 | Assertions |
|---|---:|
| funcional | 360 |
| infraestructura | 90 |
| integración | 200 |
| total | **650** |

Tres harnesses elevan la suite a **68 harnesses** y **5.812 assertions**.

## 27. Criterio de aceptación y orden

Orden: recertificar base; crear puerto; resultado; router; funcional;
infraestructura; integración; suite 68/5.812; stage seis rutas; commit separado;
recertificar; no push. Detener ante séptimo archivo, interfaz router, contrato
A5–A7 cambiado, acceso directo a infraestructura, segunda llamada, fallback,
diagnostic, A9 o wiring.

A8 se acepta solo con puerto booleano exacto; true/false/excepción certificados;
router concreto sin interfaz; seis rutas; cuatro dependencias; once estados; A5
una vez; legacy solo ante `legacy_allowed`; A6/A7 solo durable; cero decisiones
residuales.

## 28. Cierre normativo

Los bloqueos B1 y B2 de la auditoría A8 quedan resueltos: el puerto legacy tiene
FQCN, ruta, firma y semántica completa; el router no tiene interfaz propia; la
allowlist conserva seis rutas. La adaptación concreta queda asignada a A9 sin
otorgar infraestructura a A8. No resta ambigüedad arquitectónica para
implementar A8.

**A8 IMPLEMENTABLE**
