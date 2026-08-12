# Corrección normativa final A10 — wiring productivo directo

## 1. Autoridad y veredicto

Este documento corrige y reemplaza, exclusivamente para el wiring productivo
inicial de reconciliation, las reglas incompatibles de
`docs/durable-retry-production-wiring-a10-normative-correction.md`.

**A10 WIRING PRODUCTIVO IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

La arquitectura autoritativa es invocación directa. No existe action, hook ni
registrar intermedio para publicar la candidatura inicial.

Bloqueadores restantes para implementar A10: **0**.

## 2. Alcance estricto

A10 conecta el router A8 ya compuesto por A9 con los dos retornos no nulos del
materializer de reconciliation. También centraliza los callers productivos del
materializer y preserva el registro del callback de ejecución durable existente.

A10 no modifica decisiones A1–A9, schema, migraciones, repositorios, policies,
resultados, processors, porcentajes ni payloads durable certificados.

## 3. Modelo único de invocación

La única cadena permitida es:

```text
Application
→ DurableRetryProductionComposition (una instancia)
→ router() (una llamada exitosa)
→ DurableRetryInitialProductionRouter (una identidad)
→ WebpayReconciliationMaterializer (inyección directa)
→ publishRetryAuthorityCandidate()
→ routeReconciliation() (una llamada por publicación)
```

Quedan prohibidos para la publicación inicial:

- `do_action`;
- `add_action`;
- registrar o callback intermedio;
- container/service locator desde el materializer;
- reconstrucción manual de A2–A8;
- resolución de A9/A8 por hook;
- segunda invocación del router.

## 4. Prevalencia normativa

- Esta corrección deroga A10 original §§6, 14, 16 y 17 en cuanto prescriben
  `veciahorra_durable_retry_initial_reconciliation`, `do_action()` o
  `DurableRetryProductionHookRegistrar`.
- La corrección normativa A9 continúa siendo autoritativa para composición,
  lifecycle interno, fallos y presupuesto cero de A9.
- El hook `veciahorra_durable_retry_reconciliation` continúa autoritativo solo
  para ejecutar una acción durable ya programada.
- Las reglas A10 originales sobre A5–A8, convergencia, catálogo, presupuesto y
  ausencia de fallback se mantienen cuando no contradicen este documento.
- Ante cualquier contradicción sobre wiring inicial, prevalece este documento.

## 5. Registrar inicial obsoleto

`DurableRetryProductionHookRegistrar` nunca fue implementado y **no se crea**.

Quedan prohibidos:

- su FQCN y ruta futuros;
- sus harnesses exclusivos;
- el hook `veciahorra_durable_retry_initial_reconciliation`;
- cualquier `do_action()` equivalente;
- dejar un registrar inactivo o registrado sin función.

No existe compatibilidad dual ni periodo de convivencia entre modelos.

## 6. Ownership de A9

El único sitio productivo que crea A9 es el método existente:

```php
private function Application::registerDurableRetryGraph(): void
```

Se ejecuta una vez desde `Application::__construct()`, después de registrar las
dependencias generales/payment gateway y antes de construir controllers, routes,
recoveries o handlers durante `run()`.

Máximos por instancia de `Application`:

- `new DurableRetryProductionComposition`: `1`;
- llamadas exitosas a `router()`: `1`;
- routers iniciales conservados: `1`.

## 7. Propiedad exacta de Application

`Application` conserva el router, no la composición:

```php
private ?DurableRetryInitialProductionRouter
    $durableRetryInitialProductionRouter = null;
```

La propiedad es privada, de instancia y nullable solo antes del registro. No hay
getter público. No se expone A9, A8 ni la propiedad mediante `container()`.

La composición es una variable local de `registerDurableRetryGraph()` y termina
su lifecycle después de publicar el router. El grafo permanece vivo por las
referencias transitivas del router y por la propiedad de `Application`.

## 8. Firma y repetición de registerDurableRetryGraph

Firma inmutable:

```php
private function registerDurableRetryGraph(): void
```

La primera instrucción es:

```php
if ($this->durableRetryInitialProductionRouter !== null) {
    return;
}
```

Una segunda llamada sobre la misma `Application` es no-op y no vuelve a
registrar bindings, construir A9 ni llamar `router()`. El método no retorna el
router: asigna exclusivamente la propiedad. No acepta argumentos y no permite
una segunda configuración.

## 9. Dependencias hoja de A9 en Application

Dentro de la primera ejecución, después de registrar el singleton del scheduler
externo y antes de registrar el materializer, `Application` crea exactamente:

```php
$composition = new DurableRetryProductionComposition(
    $database,
    new WordPressOptionDurableRetryActivationConfigurationValueReader(),
    $this->container->make(DurableRetryExternalSchedulerInterface::class),
    new DurableCompletionScheduler(),
    $utcNow
);
$this->durableRetryInitialProductionRouter = $composition->router();
```

`$utcNow` es la misma `Closure` local ya definida como
`static fn (): string => gmdate('Y-m-d H:i:s')`.

## 10. Obtención exacta de wpdb

`Application` obtiene una vez la referencia canónica WordPress mediante
`global $wpdb`, exige `instanceof wpdb` y la asigna a `$database`. No muta el
global ni lo conserva en estado estático.

Si no existe una instancia válida, lanza:

```php
new RuntimeException('A WordPress database connection is required.')
```

El fallo ocurre antes de construir A9/materializer y se propaga. No hay fallback,
router parcial ni registro de hooks posterior.

## 11. Identidad del scheduler externo

A9 recibe exactamente el singleton existente de
`DurableRetryExternalSchedulerInterface`, cuya implementación productiva es
`ActionSchedulerDurableRetryAdapter`.

No se crea un segundo adapter para A9. El adapter se comparte por `===` con los
consumidores que resuelven el mismo contrato desde el `Container` de esa
`Application`.

La mera construcción no comprueba funciones `as_*` ni ejecuta scheduling.

## 12. Identidad del scheduler legacy

Se crea exactamente un `DurableCompletionScheduler` para A9 y se entrega como
`DurableRetryLegacySchedulerInterface`. Esa instancia llega transitivamente a
A8 y es la única autorizada para `scheduleReconciliation()` en la publicación
inicial.

No se conserva otro scheduler inicial en materializer, service o recovery. Los
métodos históricos `retry()` de workers/recovery no son aliases de
`scheduleReconciliation()` y quedan fuera de esta publicación inicial.

## 13. Binding exacto del materializer

Después de asignar el router, `registerDurableRetryGraph()` registra:

```php
$this->container->singleton(
    WebpayReconciliationMaterializer::class,
    fn (): WebpayReconciliationMaterializer =>
        new WebpayReconciliationMaterializer(
            new ValidatedFinancialResultRepository(),
            new PaymentReconciliationRepository(),
            $this->durableRetryInitialProductionRouter
        )
);
```

La factory no llama `router()` y no acepta null. Cada resolución del materializer
en esa `Application` retorna la misma instancia.

## 14. Constructor definitivo del materializer

Firma completa y orden exacto:

```php
public function __construct(
    private readonly ValidatedFinancialResultRepository $financialResults,
    private readonly PaymentReconciliationRepository $reconciliations,
    private readonly DurableRetryInitialProductionRouter $initialProductionRouter
) {
}
```

Los tres argumentos son obligatorios y no tienen defaults. El materializer no
recibe composición, container, registrar, callable, closure, mapa ni string de
clase. No existe dependencia nullable ni fallback interno.

## 15. API privada de publicación

Firma exacta:

```php
private function publishRetryAuthorityCandidate(
    MaterializedReconciliation $materializedReconciliation
): void
```

Solo `materialize()` y `resume()` pueden llamarla. Cada método la llama una vez
por retorno no nulo. No se hace pública ni se añade un resultado al DTO.

## 16. Bloque exacto en materialize

Después de crear:

```php
$materialized = new MaterializedReconciliation(...);
```

se elimina exactamente:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

y se inserta:

```php
$this->publishRetryAuthorityCandidate($materialized);
return $materialized;
```

La operación inmediatamente anterior es el constructor completo de
`MaterializedReconciliation`; la inmediatamente posterior es `return
$materialized`.

## 17. Bloque exacto en resume

En el único retorno no nulo de `resume()`, después de construir `$materialized`,
se elimina la segunda llamada literal:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

y se inserta la misma secuencia:

```php
$this->publishRetryAuthorityCandidate($materialized);
return $materialized;
```

El retorno temprano `return null` cuando no existe evidencia financiera no
publica candidatura ni captura instante.

## 18. Identidad exacta de reconciliation

La llamada usa exclusivamente:

```php
$materializedReconciliation->reconciliationId()
```

`MaterializedReconciliation` ya rechaza IDs menores que uno en su constructor.
El ID procede del retorno de `PaymentReconciliationRepository::create()` o de
`PaymentReconciliation::id()` al converger por fingerprint.

Quedan prohibidos webpay return ID, payment ID, order ID, origin ID, business
completion ID y cualquier consulta para reconstruir la identidad.

## 19. Captura autoritativa del instante

`publishRetryAuthorityCandidate()` realiza exactamente una captura:

```php
$scheduledForValue = gmdate('Y-m-d H:i:s');
```

No reutiliza `current_time`, no usa timezone local, `time()`, timestamp provider
ni `new DateTimeImmutable('now')`. A10 no introduce persistencia adicional que
requiera este valor; la misma variable se usa exclusivamente para construir el
objeto temporal entregado a A8.

## 20. Parse UTC exacto

La conversión es:

```php
$scheduledFor = DateTimeImmutable::createFromFormat(
    '!Y-m-d H:i:s',
    $scheduledForValue,
    new DateTimeZone('UTC')
);
$errors = DateTimeImmutable::getLastErrors();
```

Es válida solo si:

- `$scheduledFor !== false`;
- no existen warnings ni errores de parse;
- `format('Y-m-d H:i:s') === $scheduledForValue`;
- `getOffset() === 0`;
- `format('u') === '000000'`.

No hay segunda captura ni normalización posterior.

## 21. Fallo imposible de timestamp

Si alguna validación de §20 falla se lanza exactamente:

```php
new LogicException(
    'Durable Retry production timestamp could not be created.'
)
```

La excepción ocurre post-persistencia y se propaga sin envolver. No ejecuta A8,
legacy, fallback, rollback ni una segunda captura. El caller puede reintentar su
flujo; la reconciliation ya materializada convergerá por fingerprint.

## 22. Invocación exacta A8

La única llamada productiva es:

```php
$result = $this->initialProductionRouter->routeReconciliation(
    $materializedReconciliation->reconciliationId(),
    $scheduledFor
);
```

Ocurre una vez por llamada a `publishRetryAuthorityCandidate()`. No hay loop,
retry, callback, segunda rama ni consulta previa.

## 23. Catálogo exhaustivo A8

El materializer valida el catálogo mediante un `match` cerrado sobre
`$result->state()` que enumera exactamente:

1. `LEGACY_SCHEDULED`;
2. `LEGACY_UNAVAILABLE`;
3. `DURABLE_SYNCHRONIZED`;
4. `DURABLE_ALREADY_SYNCHRONIZED`;
5. `DURABLE_EXTERNAL_UNAVAILABLE`;
6. `DURABLE_COORDINATION_FAILED`;
7. `DURABLE_COORDINATION_UNCERTAIN`;
8. `AUTHORITY_CLOSED`;
9. `RESOLUTION_FAILED`;
10. `INVALID_INPUT`;
11. `DEPENDENCY_FAILURE`.

No existe arm `default`.

## 24. Consumo explícito del resultado

Los once arms del `match` consumen el resultado con valor `null`. Para cada
estado el materializer:

- retorna normalmente;
- no lanza por el estado;
- no registra logs/métricas;
- no persiste el resultado;
- no lo expone al caller;
- no cambia el DTO funcional;
- no agenda nada adicional;
- no remapea reason/state;
- no ejecuta fallback.

El descarte es deliberado: A8 completa la decisión de routing mediante efectos
tipados de A5–A7/legacy; el materializer solo verifica que el resultado pertenece
al catálogo cerrado. Un estado imposible genera `UnhandledMatchError`, se
propaga como violación estructural post-persistencia y nunca activa legacy.

## 25. Semántica individual de los once estados

| Estado | Routing completado | Legacy | Conducta materializer |
|---|---|---:|---|
| `legacy_scheduled` | legacy confirmado | exactamente 1 | retorno normal |
| `legacy_unavailable` | legacy no confirmado | intento máximo 1 | retorno normal |
| `durable_synchronized` | durable confirmado | 0 | retorno normal |
| `durable_already_synchronized` | durable convergente | 0 | retorno normal |
| `durable_external_unavailable` | durable, AS ausente | 0 | retorno normal |
| `durable_coordination_failed` | durable, fallo conocido | 0 | retorno normal |
| `durable_coordination_uncertain` | durable, intervención | 0 | retorno normal |
| `authority_closed` | cierre A5 | 0 | retorno normal |
| `resolution_failed` | cierre A6 | 0 | retorno normal |
| `invalid_input` | violación de entrada cerrada | 0 | retorno normal |
| `dependency_failure` | dependencia fallida | 0 | retorno normal |

Ningún estado revierte la materialización funcional.

## 26. Exclusión legacy definitiva

Después de A10 no existe `new DurableCompletionScheduler` en el materializer.
Solo A8 posee el puerto `DurableRetryLegacySchedulerInterface` y puede llamar:

```php
scheduleReconciliation(int): bool
```

Reglas:

- solo `legacy_allowed` alcanza el scheduler;
- máximo una llamada;
- ramas durable: cero legacy;
- `resolution_failed`: cero fallback;
- `dependency_failure`: cero fallback;
- excepción: cero fallback;
- no hay scheduling legacy antes o después de A8.

Los dos bloques eliminados son los descritos en §§16–17.

## 27. Frontera transaccional

La publicación ocurre después de obtener un ID definitivo y construir el DTO.
`WebpayReconciliationMaterializer` no abre una transacción envolvente ni mantiene
locks explícitos en ese punto. En `WebpayReturnService::finalize()`, `complete()`
ocurre antes de materializar.

Consecuencias:

- payment, return y reconciliation persistidos no se revierten por A8;
- ningún estado A8 abre rollback funcional;
- A10 no reabre transacciones;
- fallo de scheduling no borra/modifica reconciliation;
- el DTO funcional permanece construido;
- errores estructurales/timestamp/composición se propagan post-persistencia;
- la frontera superior puede reportar error, pero no encapsularlo como éxito ni
  activar legacy.

## 28. Excepciones de composición

A9 se construye durante `Application::__construct()`, antes de que exista una
publicación funcional. Un `Throwable` de composición se propaga y aborta la
construcción de esa `Application`; no registra hooks ni deja router parcial.

El materializer recibe un router ya construido, por lo que no puede lanzar un
error A9 durante publicación. A8 convierte excepciones de dependencias
operacionales en resultados tipados; solo una violación estructural imposible
se propaga después de persistencia.

## 29. Constructor definitivo de WebpayReturnService

Para eliminar su fallback, la firma reordena el materializer como cuarto
argumento obligatorio:

```php
public function __construct(
    private WebpayReturnGatewayInterface $gateway,
    private PaymentSessionRepository $sessions,
    private WebpayReturnRepository $returns,
    private WebpayReconciliationMaterializer $materializer,
    private ?WebpayReturnContextRepositoryInterface $contexts = null,
    private ?WebpayReturnGatewayResolverInterface $gatewayResolver = null,
    private ?PaymentOriginContextRepository $durableOrigins = null
) {
}
```

Se eliminan `?? new WebpayReconciliationMaterializer()` de `repeated()` y
`finalize()`. Ambos usan `$this->materializer` directamente.

## 30. Constructor definitivo de WebpayReturnRecovery

Firma exacta:

```php
public function __construct(
    private readonly WebpayReconciliationMaterializer $materializer,
    private readonly PaymentOriginContextRepository $origins = new PaymentOriginContextRepository()
) {
}
```

El materializer es obligatorio y primero. No existe default que lo reconstruya.
El repositorio conserva su default histórico.

## 31. Construcción de callers productivos

`WebpayReturnService` se autoconstruye mediante `Container`; su parámetro concreto
`WebpayReconciliationMaterializer` resuelve el singleton de §13.

`Application::run()` reemplaza:

```php
(new WebpayReturnRecovery())->register();
```

por:

```php
(new WebpayReturnRecovery(
    $this->container->make(WebpayReconciliationMaterializer::class)
))->register();
```

No existen otros callers productivos autorizados para construir materializer.

## 32. Callback durable existente

Se preserva sin cambios:

- hook: `veciahorra_durable_retry_reconciliation`;
- registrar: `DurableRetryActionHookRegistrar`;
- archivo:
  `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionHookRegistrar.php`;
- punto de registro:
  `Application::run()` mediante el singleton del container;
- prioridad: `10`;
- argumentos WordPress: `2` (`schedule_id`, `generation`);
- callback interno: añade hook cerrado y llama una vez
  `DurableRetryActionCallback::execute(hook, scheduleId, generation)`.

No decide durable/legacy, no invoca A8 y solo procesa acciones ya programadas.

## 33. Idempotencia global de hooks

Se conserva la regla A10 original:

```php
private static bool $registered = false;
```

Es estado exclusivo de registro de hooks, no ownership A9. `Application::run()`:

1. retorna si ya es `true`;
2. la cambia a `true` antes del primer `add_action`/`register()`;
3. ejecuta los registros existentes, incluido el registrar durable una vez;
4. ante `Throwable`, restaura `false` y propaga.

No usa `did_action`, option, transient o global mutable. Dos `Application` en un
proceso registran los hooks una sola vez; cada una puede tener su propio router,
pero solo la instancia cuyo `run()` gana registra handlers.

## 34. Orden completo del bootstrap

Orden autoritativo:

1. `Application::__construct()` crea container y bindings generales;
2. llama una vez `registerDurableRetryGraph()`;
3. registra dependencias durable base y `$utcNow`;
4. obtiene `$database` y crea una composición A9;
5. llama `router()` una vez y asigna la propiedad;
6. registra el materializer singleton con ese router;
7. termina bindings de executor/callback/registrar durable existentes;
8. callers/controllers se autoconstruyen después con el singleton;
9. `run()` aplica la guardia global y registra callbacks existentes;
10. bootstrap nunca invoca `routeReconciliation()`.

## 35. Idempotencia por nivel

| Nivel | Regla |
|---|---|
| A9 | una construcción exitosa y router `===` por composición |
| Application | una composición/router por instancia |
| materializer singleton | una instancia por container/Application |
| publicación | una llamada A8 por ejecución no nula |
| repetición | puede volver a llamar A8; converge A3–A7 |
| concurrencia | dos requests pueden llamar; autoridad/locks certificados convergen |
| callback | claim/executor durable certificado |

No hay flags por reconciliation, options, locks A10, consulta previa ni memoria
compartida entre procesos.

## 36. Presupuesto del bootstrap

| Operación | Máximo |
|---|---:|
| construcciones A9 | 1 por `Application` |
| llamadas A9 `router()` | 1 exitosa |
| invocaciones A8 | 0 |
| SQL derivado de A9 | 0 |
| scheduling | 0 |
| hooks iniciales de publicación | 0 |
| registro callback durable | 1 por proceso |
| lectura de configuración A2.1 | 0 |

## 37. Presupuesto de publicación

| Operación | Máximo |
|---|---:|
| captura temporal | 1 |
| llamada A8 | 1 |
| A5 | 1 |
| A6 | 1 solo si corresponde |
| A7 | 1 solo si corresponde |
| scheduler legacy | 1 solo `legacy_allowed` |
| fallback | 0 |
| loops/retries/sleeps | 0 |
| SQL nuevo A10 | 0 |
| hooks/actions iniciales | 0 |

El callback durable mantiene su presupuesto histórico sin duplicar lectura,
claim, processor o persistencia.

## 38. Allowlist productiva definitiva

Solo pueden modificarse estas cuatro rutas:

1. `app/Core/Application.php` — ownership A9/A8, binding materializer, caller
   recovery y guardia global de hooks.
2. `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`
   — inyección A8, publicación directa, tiempo/resultado y eliminación legacy.
3. `app/Modules/Payments/Service/WebpayReturnService.php` — dependencia
   materializer obligatoria y eliminación de dos fallbacks.
4. `app/Modules/Payments/Orchestration/WebpayReturnRecovery.php` — materializer
   obligatorio y eliminación de construcción default.

No se modifica registrar/callback durable porque su contrato ya coincide.

## 39. Harnesses históricos modificables

Los cuatro callers de prueba que deben adaptar argumentos, sin cambiar conducta
funcional, son:

5. `tests/manual/public-payment-session-backend-test.php`;
6. `tests/manual/webpay-return-foundation-test.php`;
7. `tests/manual/webpay-return-rest-route-test.php`;
8. `tests/manual/woocommerce-durable-payment-attempt-test.php`.

Cada fixture debe recibir un router double cerrado o resolver el materializer
singleton del `Application` de prueba. Ningún double puede añadir API productiva.

## 40. Harnesses A10 nuevos

9. `tests/manual/durable-retry-production-direct-wiring-test.php` — 11 estados,
   ID/UTC, una llamada, fallo y segunda publicación.
10. `tests/manual/durable-retry-production-direct-wiring-infrastructure-test.php`
    — firmas, allowlist, guardias y cero modelo action.
11. `tests/manual/durable-retry-production-direct-wiring-integration-test.php`
    — materializer real→A8/A5–A7/legacy con persistencia controlada.
12. `tests/manual/durable-retry-production-direct-bootstrap-test.php` — una A9,
    un router, singleton materializer y callback global una vez.
13. `tests/manual/durable-retry-production-direct-concurrency-test.php` — dos
    requests/publicaciones convergen sin doble autoridad.

La allowlist total contiene exactamente trece rutas: cuatro productivas, cuatro
harnesses históricos y cinco harnesses nuevos.

## 41. Rutas expresamente prohibidas

Permanecen fuera:

- A1–A9, incluida `DurableRetryProductionComposition`;
- `DurableCompletionScheduler` y su puerto ya certificados;
- registrar/callback/executor/coordinator/adapter durable;
- repositorios, policies y resultados A8;
- schema, migraciones y `Config`;
- documentos previos y este documento durante implementación;
- `artifacts/`;
- registrar/harnesses del modelo inicial `do_action` derogado.

Una necesidad de ruta 14 bloquea el hito y exige nueva auditoría.

## 42. Matriz normativa A10-D

| ID | Caso | Resultado obligatorio |
|---|---|---|
| A10-D-01 | `Application` construye | una A9 |
| A10-D-02 | registro durable | una llamada `router()` |
| A10-D-03 | materializer | recibe A8 concreto |
| A10-D-04 | frontera | materializer no recibe A9 |
| A10-D-05 | inventario | registrar inicial ausente |
| A10-D-06 | código | cero `do_action` inicial |
| A10-D-07 | publicación | una llamada A8 |
| A10-D-08 | identidad | reconciliation ID exacto/positivo |
| A10-D-09 | tiempo | una captura `gmdate` |
| A10-D-10 | tiempo | timezone/offset UTC |
| A10-D-11 | tiempo | microsegundos cero |
| A10-D-12 | legacy directo | dos llamadas eliminadas |
| A10-D-13 | `legacy_allowed` | una llamada legacy |
| A10-D-14 | estados durable | cero legacy |
| A10-D-15 | `resolution_failed` | cero fallback |
| A10-D-16 | `dependency_failure` | cero fallback |
| A10-D-17 | catálogo | once arms sin default |
| A10-D-18 | persistencia | ningún estado revierte función |
| A10-D-19 | repetición | converge A3–A7 |
| A10-D-20 | dos requests | una autoridad persistente |
| A10-D-21 | grafo | cero reconstrucción A5–A8 |
| A10-D-22 | ownership | cero segunda composición A9 |
| A10-D-23 | callback durable | un registro global |
| A10-D-24 | callback durable | dos argumentos WordPress |
| A10-D-25 | callback durable | una delegación executor |
| A10-D-26 | separación | callback no invoca A8 |
| A10-D-27 | bootstrap | cero invocación A8 |
| A10-D-28 | alcance | cero SQL nuevo |
| A10-D-29 | alcance | cero options nuevas |
| A10-D-30 | presupuesto | cero retry/sleep/loop local |
| A10-D-31 | regresión | suite histórica completa verde |

Casos normativos: **31**.

## 43. Consumo de los once estados en pruebas

El harness directo ejecuta once resultados doubles, uno por constante. En todos:

- el materializer retorna el mismo `MaterializedReconciliation`;
- A8 se llama una vez;
- no hay excepción por estado;
- no hay segunda operación;
- no hay log, hook o fallback.

Un double que entregue un estado fuera del catálogo no puede ser una instancia
válida del resultado final; la guardia estructural certifica `match` sin default
y el catálogo de once constantes.

## 44. Guardias estructurales

Los harnesses abortan ante:

- `veciahorra_durable_retry_initial_reconciliation` o `do_action` en las cuatro
  rutas productivas;
- `DurableRetryProductionHookRegistrar`;
- más de un `new DurableRetryProductionComposition`;
- más de una llamada bootstrap `->router()`;
- `new` A5–A8 fuera de A9;
- `new DurableCompletionScheduler` en materializer/service/recovery;
- más de una llamada `routeReconciliation` en el método privado;
- aliases, remapeo, `default` o catálogo distinto de once;
- fallback/catch silencioso;
- SQL, nombre de tabla, option o lock nuevo por A10;
- loop, retry, sleep/usleep;
- service locator/container dentro del materializer;
- Reflection o clase dinámica productiva;
- archivo fuera de las trece rutas.

## 45. Guardias Git y filesystem

Antes y después de implementar:

- staging inicialmente/finalmente vacío salvo fase selectiva posterior;
- cambios productivos/pruebas limitados a trece rutas;
- cero documentos modificados;
- cero temporales e índices temporales;
- cero repositorios temporales residuales;
- `artifacts/` conserva `504` archivos;
- `git diff --check` limpio;
- suite histórica + cinco harnesses A10 verde;
- cero diagnostics;
- sin commit/push salvo mandato explícito posterior.

## 46. Callers y compatibilidad

Los harnesses históricos de §39 deben actualizar sus construcciones posicionales
al nuevo cuarto argumento obligatorio de `WebpayReturnService` y al constructor
cerrado del materializer. Las expectativas de payment result, recovery y
persistencia no cambian.

`webpay-return-sandbox-test.php` no se modifica: resuelve
`WebpayReturnService` desde un `Application` real y recibe el singleton por
autowiring.

No se conserva una sobrecarga, factory alternativa ni default temporal para
compatibilidad.

## 47. Tratamiento de fallos resumido

| Fallo | Frontera | Conducta |
|---|---|---|
| wpdb ausente | bootstrap | excepción estable, cero A9/hooks |
| composición A9 | bootstrap | mismo `Throwable`, cero router parcial |
| timestamp imposible | post-persistencia | `LogicException`, cero A8/legacy |
| dependencia A5–A7/legacy | A8 | resultado `dependency_failure` |
| estado A8 ordinario | post-persistencia | retorno normal |
| estado imposible | post-persistencia | `UnhandledMatchError` |
| scheduling fallido | A8 | resultado tipado, cero rollback/fallback |

Ningún fallo devuelve autoridad a legacy.

## 48. Criterios de implementación

A10 queda implementado solo si:

1. las trece rutas son el inventario completo;
2. las firmas coinciden literalmente;
3. existen una composición y una llamada `router()` por `Application`;
4. materializer posee exactamente un A8;
5. ambas llamadas legacy directas desaparecen;
6. no existe publicación inicial por action;
7. los once estados se consumen exhaustivamente;
8. pasan A10-D-01–A10-D-31;
9. callback durable existente conserva su contrato;
10. suite completa queda verde sin diagnostics.

## 49. Ambigüedades cerradas

| Bloqueador auditoría | Cierre |
|---|---|
| B1 modelo contradictorio | invocación directa exclusiva §§3–5 |
| B2 dependencia materializer | A8 concreto obligatorio §14 |
| B3 sitio/lifecycle A9 | `Application` §§6–13 |
| B4 consumo 11 estados | `match` exhaustivo §§23–25 |
| B5 error post-persistencia | propagación cerrada §§21, 27–28 |
| B6 conversión UTC | captura/parse únicos §§19–21 |
| B7 bootstrap global | guardia estática §33 |
| B8 callers/legacy | firmas, eliminación y allowlist §§26, 29–41 |

Ambigüedades restantes: **0**.

## 50. Resumen definitivo

- owner A9/A8: `Application`;
- property: `$durableRetryInitialProductionRouter` privada nullable;
- registration: `private registerDurableRetryGraph(): void`, asignación y no-op;
- materializer dependency: A8 concreto, tercer argumento obligatorio;
- publication: método privado con DTO materializado;
- timestamp: una captura `gmdate`, parse UTC exacto, microsegundos cero;
- result: once estados enumerados, retorno normal y descarte explícito;
- legacy: únicamente A8, dos llamadas directas eliminadas;
- registrar inicial: no se crea;
- callback durable: hook existente, prioridad 10, dos args, una delegación;
- transaction: post-persistencia, cero rollback funcional;
- allowlist: trece rutas;
- matrix: 31 casos;
- blockers: cero.

## 51. Veredicto final

**A10 WIRING PRODUCTIVO IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**
