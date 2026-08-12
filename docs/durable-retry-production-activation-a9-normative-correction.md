# Corrección normativa A9 — composición productiva idempotente

## 1. Autoridad, propósito y alcance

Este documento corrige y especializa la definición de A9 contenida en
`docs/durable-retry-production-wiring-a10-normative-correction.md`.

A9 queda definido exclusivamente como la composición productiva, cerrada,
idempotente y sin efectos del grafo que culmina en
`DurableRetryInitialProductionRouter` A8. Esta corrección resuelve los cinco
bloqueadores de
`docs/durable-retry-production-activation-a9-readiness-audit.md` y permite una
implementación posterior sin decisiones de diseño del implementador.

No forman parte de A9 la invocación del router, el registro de hooks o callbacks,
la edición del materializador, la exclusión funcional legacy, el bootstrap
principal ni la activación de scheduling.

## 2. Precedencia normativa

Para composición productiva A9, este documento prevalece sobre toda formulación
incompatible de A10 y sobre especificaciones históricas.

- Las reglas A10 sobre construcción del grafo se interpretan según esta
  corrección.
- Las reglas A10 sobre hooks, callbacks, materializador, exclusión legacy,
  `Application::run()` y activación se reservan a un wiring posterior.
- La expresión A10 «A9: composición, hooks y exclusión legacy» queda dividida:
  solo composición conserva el nombre A9; las otras responsabilidades no son A9.
- Ninguna regla A10 autoriza efectos dentro de A9.
- `Application::registerDurableRetryGraph()` deja de ser el composition root A9;
  su eventual modificación pertenece al wiring posterior.

## 3. Separación formal A9 / wiring posterior

### A9

A9 únicamente:

1. recibe cinco dependencias hoja productivas ya construidas;
2. construye los nodos internos A2.1–A8 en orden determinista;
3. conserva identidades compartidas dentro de su instancia;
4. publica un único router A8 solo después del éxito completo;
5. devuelve siempre ese mismo router en llamadas posteriores.

### Wiring productivo posterior

Otro hito, fuera de esta allowlist, deberá:

1. construir las cinco dependencias hoja;
2. construir una única instancia A9 y conservarla durante el bootstrap;
3. obtener el router mediante la API A9;
4. registrar hooks/callbacks;
5. invocar A8 desde el punto productivo autorizado;
6. conectar el materializador y Action Scheduler;
7. aplicar guards y exclusión funcional legacy;
8. activar el flujo.

Está prohibido atribuir cualquiera de esos ocho pasos de wiring a A9.

## 4. Identidad definitiva de la clase A9

| Propiedad | Norma definitiva |
|---|---|
| clase | `DurableRetryProductionComposition` |
| FQCN | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition` |
| namespace | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry` |
| ruta | `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionComposition.php` |
| declaración | `final class` |
| forma | instanciable; no estática |
| constructor | `public` |
| API pública propia | solo `router()` además del constructor |
| estado estático | prohibido |
| herencia | prohibida por `final` |

No se crea interfaz para la composición. No hay factory, provider, facade,
registro global ni alias alternativo.

## 5. Constructor exacto

La firma normativa, incluido el orden, es:

```php
public function __construct(
    private readonly wpdb $database,
    private readonly DurableRetryActivationConfigurationValueReaderInterface $configurationValueReader,
    private readonly DurableRetryExternalSchedulerInterface $externalScheduler,
    private readonly DurableRetryLegacySchedulerInterface $legacyScheduler,
    private readonly Closure $utcNow
) {
}
```

Imports exactos de tipos no nativos:

```php
use Closure;
use wpdb;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
```

No se permiten parámetros opcionales, valores por defecto, variádicos, arrays de
configuración, nombres de clase como strings, container ni factories genéricas.
La clausura `$utcNow` debe ser retenida, nunca ejecutada por A9.

## 6. API pública cerrada

La única API pública propia después del constructor es:

```php
public function router(): DurableRetryInitialProductionRouter
```

El tipo concreto exacto es:

`VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter`.

La API devuelve directamente A8. No devuelve contenedor, composición DTO, mapa,
array, iterable, callable ni interfaz genérica. No acepta argumentos. No expone
A2–A7, repositorios, adapters, reloj o estado interno.

## 7. Estado interno permitido

La clase contiene exclusivamente las cinco propiedades `readonly` del
constructor y estas propiedades de composición:

```php
private const UNINITIALIZED = 0;
private const BUILDING = 1;
private const COMPLETE = 2;

private int $state = self::UNINITIALIZED;
private ?DurableRetryInitialProductionRouter $composedRouter = null;
```

No se permite estado `static`, global, persistente, WordPress option, transient,
cache externo ni propiedad adicional que publique nodos parciales.

El fallo no es un estado persistente: durante el manejo de una excepción se
limpia el resultado y se vuelve atómicamente a `UNINITIALIZED` antes de propagar.

## 8. Máquina de estados normativa

| Estado actual | Evento | Estado siguiente | Resultado |
|---|---|---|---|
| `UNINITIALIZED` | `router()` | `BUILDING` | inicia construcción local |
| `BUILDING` | construcción completa | `COMPLETE` | publica A8 y retorna |
| `BUILDING` | `Throwable` | `UNINITIALIZED` | limpia y propaga |
| `BUILDING` | reentrada `router()` | `BUILDING` | lanza `LogicException` |
| `COMPLETE` | `router()` | `COMPLETE` | retorna misma instancia |

`composedRouter` solo puede ser no nulo en `COMPLETE`. El router se asigna a la
propiedad únicamente después de construir todos los nodos en variables locales.
No se guarda en propiedades ningún nodo A2–A7.

## 9. Algoritmo exacto de `router()`

La implementación futura debe seguir esta estructura semántica, sin alterar el
orden ni añadir efectos:

```php
public function router(): DurableRetryInitialProductionRouter
{
    if ($this->state === self::COMPLETE) {
        return $this->composedRouter;
    }
    if ($this->state === self::BUILDING) {
        throw new LogicException(
            'Durable Retry production composition re-entry is not allowed.'
        );
    }

    $this->state = self::BUILDING;
    try {
        // Construcción local exacta definida en §14.
        $router = /* DurableRetryInitialProductionRouter completo */;
        $this->composedRouter = $router;
        $this->state = self::COMPLETE;

        return $router;
    } catch (Throwable $error) {
        $this->composedRouter = null;
        $this->state = self::UNINITIALIZED;
        throw $error;
    }
}
```

El retorno en `COMPLETE` deberá estar respaldado por la invariante no-null. No se
autoriza reconstruir, validar externamente ni volver a recorrer el grafo.

## 10. Semántica formal de idempotencia

La unidad de lifecycle es **una instancia de
`DurableRetryProductionComposition` dentro de un bootstrap/request o proceso PHP**.
No existe singleton global entre procesos ni entre instancias A9.

Reglas verificables:

- una instancia A9 puede completar como máximo una construcción exitosa;
- toda llamada exitosa a `router()` sobre esa instancia retorna el mismo objeto
  por identidad estricta `===`;
- dos, diez, cien o más llamadas conservan exactamente la misma identidad;
- A5, A6, A7, sus dependencias compartidas y A8 se construyen una sola vez por
  construcción exitosa;
- si dos puntos del bootstrap requieren el router, deben recibir la misma
  instancia A9; no deben construir dos composiciones;
- dos instancias A9 son lifecycles independientes y pueden producir routers
  distintos; no comparten estado estático;
- no existe segunda configuración sobre una instancia porque `router()` no
  acepta argumentos y las dependencias son `readonly`;
- para usar dependencias distintas debe construirse otra instancia A9 antes del
  wiring; esto no reconfigura ni invalida la primera;
- intentar mutar una dependencia `readonly` queda sujeto al error nativo de PHP
  y no forma parte de una API A9.

La idempotencia es identidad estricta, no mera igualdad estructural.

## 11. Conducta ante reentrada

Una llamada recursiva a `router()` mientras el estado sea `BUILDING` se rechaza
de inmediato con:

```php
new LogicException(
    'Durable Retry production composition re-entry is not allowed.'
)
```

No se espera, duerme, reintenta, retorna null ni expone un nodo parcial. La
excepción atraviesa el `catch`, limpia el estado a `UNINITIALIZED` y puede ser
seguida por un nuevo intento explícito del caller.

No se introduce una excepción A9 nueva: `LogicException` es el único error
propio emitido directamente por la máquina de composición.

## 12. Política cerrada de errores

| Condición | Error | Estado posterior |
|---|---|---|
| reentrada | `LogicException` con mensaje literal de §11 | `UNINITIALIZED` al salir de la construcción exterior |
| tipo de dependencia incorrecto/ausente | `TypeError` nativo al construir A9 | no existe instancia A9 válida |
| constructor interno lanza | mismo `Throwable`, sin envolver | `UNINITIALIZED` |
| objeto `wpdb` inválido internamente | mismo `InvalidArgumentException`/`Throwable` | `UNINITIALIZED` |
| segunda llamada tras éxito | ningún error | `COMPLETE` |
| dependencias diferentes | nueva instancia A9 independiente | no altera la original |

Está prohibido:

- convertir un error de composición en `dependency_failure` A8;
- capturar y silenciar;
- devolver null, false o un router parcial;
- activar legacy o durable como fallback;
- cachear permanentemente el error;
- envolver errores ajenos en una excepción genérica.

A8 aún no fue invocado, por lo que ninguno de sus once estados representa un
error de composición.

## 13. Grafo productivo completo

```text
DurableRetryProductionComposition (A9)
└── DurableRetryInitialProductionRouter (A8)
    ├── DurableRetryInitialAuthorityProducer (A5)
    │   ├── DurableRetryLegacyAuthorityRepository (A3)
    │   │   └── wpdb [inyectado]
    │   ├── DurableRetryDeterministicActivationPolicy (A2)
    │   │   └── DurableRetryProductionActivationConfigurationSource (A2.1)
    │   │       └── ConfigurationValueReaderInterface [inyectado]
    │   └── DurableRetryInitialTransferAuthority (A4 authority)
    │       └── DurableRetryInitialTransferRepository (A4 repository)
    │           └── wpdb [misma instancia inyectada]
    ├── DurableRetryInitialScheduleResolver (A6)
    │   └── DurableRetryScheduleRepository
    │       └── wpdb [misma instancia inyectada]
    ├── DurableRetryInitialScheduleCoordinator (A7)
    │   └── DurableRetryExternalScheduleCoordinator
    │       ├── DurableRetryScheduleRepository [misma instancia de A6]
    │       ├── ExternalSchedulerInterface [inyectado]
    │       └── Closure utcNow [misma instancia inyectada]
    └── DurableRetryLegacySchedulerInterface [inyectado]
```

No se añade ninguna interfaz al grafo certificado.

## 14. Orden determinista de construcción

Después de cambiar a `BUILDING`, A9 crea exactamente en este orden:

1. `DurableRetryProductionActivationConfigurationSource($configurationValueReader)`;
2. `DurableRetryDeterministicActivationPolicy($configurationSource)`;
3. `DurableRetryLegacyAuthorityRepository($database)`;
4. `DurableRetryInitialTransferRepository($database)`;
5. `DurableRetryInitialTransferAuthority($initialTransferRepository)`;
6. `DurableRetryInitialAuthorityProducer($legacyAuthorityRepository, $activationPolicy, $initialTransferAuthority)`;
7. `DurableRetryScheduleRepository($database)`;
8. `DurableRetryInitialScheduleResolver($durableRetryScheduleRepository)`;
9. `DurableRetryExternalScheduleCoordinator($durableRetryScheduleRepository, $externalScheduler, $utcNow)`;
10. `DurableRetryInitialScheduleCoordinator($externalScheduleCoordinator)`;
11. `DurableRetryInitialProductionRouter($initialAuthorityProducer, $initialScheduleResolver, $initialScheduleCoordinator, $legacyScheduler)`;
12. publicar el objeto del paso 11 y cambiar a `COMPLETE`.

Los cinco objetos inyectados ya existen y no se recrean. A8 siempre es el último
nodo construido. No hay ciclos.

## 15. Catálogo de nodos y constructores

| Paso | FQCN | Constructor exacto | Lifecycle | Efectos al construir |
|---:|---|---|---|---:|
| 1 | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource` | `(DurableRetryActivationConfigurationValueReaderInterface $reader)` | exclusivo A9 | 0 |
| 2 | `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy` | `(DurableRetryActivationConfigurationSourceInterface $source)` | exclusivo A9 | 0 |
| 3 | `VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository` | `(wpdb $database)` | exclusivo A9 | 0 |
| 4 | `VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository` | `(wpdb $database)` | exclusivo A9 | 0 |
| 5 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialTransferAuthority` | `(DurableRetryInitialTransferRepositoryInterface $repository)` | exclusivo A9 | 0 |
| 6 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer` | `(DurableRetryLegacyExclusionInterface $authority, DurableRetryActivationPolicyInterface $activation, DurableRetryInitialTransferAuthorityInterface $transfer)` | exclusivo A9 | 0 |
| 7 | `VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository` | `(?wpdb $database = null, ?callable $duplicateKeyDetector = null)` invocado normativamente como `($database)` | compartido A6/coordinator externo | 0 |
| 8 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleResolver` | `(DurableRetryScheduleRepositoryInterface $repository)` | exclusivo A9 | 0 |
| 9 | `VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator` | `(DurableRetryScheduleRepositoryInterface $repository, DurableRetryExternalSchedulerInterface $scheduler, Closure $utcNow)` | compartido como dependencia A7 | 0 |
| 10 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator` | `(DurableRetryExternalScheduleCoordinatorInterface $coordinator)` | exclusivo A9 | 0 |
| 11 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter` | `(DurableRetryInitialAuthorityProducerInterface $authorityProducer, DurableRetryInitialScheduleResolverInterface $scheduleResolver, DurableRetryInitialScheduleCoordinatorInterface $scheduleCoordinator, DurableRetryLegacySchedulerInterface $legacyScheduler)` | publicado por A9 | 0 |

## 16. Dependencias compartidas e identidad

Las identidades obligatorias `===` dentro de una composición exitosa son:

- el mismo `$database` en los repositorios A3, A4 y durable;
- el mismo `DurableRetryScheduleRepository` en A6 y
  `DurableRetryExternalScheduleCoordinator`;
- el mismo `$externalScheduler` inyectado en el coordinator externo;
- la misma `$utcNow` inyectada en el coordinator externo;
- el mismo `$configurationValueReader` inyectado en la única fuente A2.1;
- el mismo `$legacyScheduler` inyectado directamente en A8;
- las mismas instancias A5, A6 y A7 recibidas por el único A8;
- el mismo A8 retornado por todas las llamadas exitosas.

A4 y A6 **no comparten repositorio**: A4 requiere
`DurableRetryInitialTransferRepository` y A6 requiere
`DurableRetryScheduleRepository`. Solo comparten la conexión `wpdb`.

A9 es propietario del lifecycle de todos los nodos creados en §14. El wiring
posterior es propietario de las cinco hojas inyectadas y debe mantenerlas vivas
al menos mientras viva A9.

## 17. Fuente de configuración y política

A9 recibe exactamente una instancia de
`DurableRetryActivationConfigurationValueReaderInterface`, crea una sola fuente
`DurableRetryProductionActivationConfigurationSource` y una sola policy
`DurableRetryDeterministicActivationPolicy`.

Durante composición no se llama `read()`, `snapshot()`,
`allowsInitialTransfer()` ni ninguna API WordPress. La configuración efectiva se
lee únicamente cuando A5 se ejecute posteriormente fuera de A9.

No existe snapshot de configuración A9 ni comparación de configuraciones entre
llamadas: las dependencias son inmutables por instancia.

## 18. Scheduler externo de A7

A9 recibe una implementación exacta de
`DurableRetryExternalSchedulerInterface`. El wiring posterior usará
`ActionSchedulerDurableRetryAdapter`, cuyo constructor implícito no tiene
argumentos ni efectos.

A9 no comprueba `function_exists`, no llama `schedule`, `findPending` o `cancel`
y no detecta disponibilidad de Action Scheduler. La ausencia del proveedor se
detecta cuando A7 opere posteriormente; el adapter certificado devolverá su
resultado tipado `UNAVAILABLE`.

El adapter inyectado se usa una sola vez y se comparte con el único coordinator
externo de esta composición. Compartirlo con otros grafos queda bajo lifecycle
del wiring y no modifica la identidad interna A9.

## 19. Scheduler legacy definitivo

La implementación productiva del puerto A8 será la clase existente:

- clase/FQCN:
  `VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionScheduler`;
- ruta:
  `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php`;
- declaración: `final class DurableCompletionScheduler implements DurableRetryLegacySchedulerInterface`;
- constructor: implícito, público, sin argumentos;
- método nuevo exacto:

```php
public function scheduleReconciliation(int $reconciliationId): bool
```

No se crea wrapper ni adapter adicional. La clase existente es la adaptación y
preserva sus métodos históricos.

Semántica futura exacta del método:

- ID menor que 1: `false`;
- `as_schedule_single_action` ausente: `false`;
- acción pendiente idéntica confirmada por `as_has_scheduled_action`: `true`;
- `as_has_scheduled_action` ausente: continúa al intento de agenda;
- retorno entero positivo de `as_schedule_single_action`: `true`;
- retorno distinto de entero positivo: `false`;
- cualquier `Throwable` de funciones Action Scheduler: `false`.

El método histórico `reconciliation(int): void` delegará en
`scheduleReconciliation($id)` e ignorará el booleano para preservar callers. El
método privado compartido podrá cambiar su retorno de `void` a `bool` sin
alterar los otros métodos públicos históricos, que continúan retornando `void`.

A9 recibe esta clase por el puerto; no la instancia ni invoca. Si el wiring no
puede suministrarla, la construcción de A9 no comienza. No hay fallback.

## 20. Punto de acceso para wiring posterior

El wiring obtiene A8 exclusivamente así:

```php
$router = $composition->router();
```

- método: `router`;
- visibilidad: `public`;
- argumentos: ninguno;
- retorno: `DurableRetryInitialProductionRouter` concreto;
- primera llamada: compone perezosamente;
- llamadas siguientes: reutilizan por `===`;
- WordPress aún no cargado: la composición sigue siendo válida porque no usa
  funciones WordPress;
- Action Scheduler ausente: la composición sigue siendo válida porque no se
  consulta durante construcción;
- la ausencia de APIs externas se detecta durante ejecución posterior, no en A9.

El wiring no puede obtener nodos internos ni acceder a un contenedor mediante A9.

## 21. Frontera con WordPress

Durante constructor y `router()`, A9:

- no llama funciones WordPress;
- no accede a `global $wpdb` ni a `$GLOBALS`;
- recibe `wpdb` explícitamente y solo entrega su referencia a constructores;
- no accede al contenedor global ni a `Application::container()`;
- no usa `function_exists`;
- no consulta hooks registrados;
- no lee opciones;
- no comprueba Action Scheduler;
- no registra autoloaders ni resuelve clases dinámicamente.

El uso tipado del objeto `wpdb` para construir repositorios no es una consulta ni
un acceso global. Los constructores solo calculan nombres de tabla; A9 no lee ni
publica esos nombres.

## 22. Presupuesto operacional absoluto

Por constructor A9 y por cualquier cantidad de llamadas a `router()`:

| Operación | Presupuesto |
|---|---:|
| SQL total | 0 |
| hooks registrados | 0 |
| hooks ejecutados | 0 |
| scheduling durable/legacy | 0 |
| cancelaciones | 0 |
| consultas Action Scheduler | 0 |
| lecturas de opciones | 0 |
| escrituras de opciones | 0 |
| llamadas funcionales A5 | 0 |
| llamadas funcionales A6 | 0 |
| llamadas funcionales A7 | 0 |
| llamadas funcionales A8 | 0 |
| logs | 0 |
| métricas | 0 |
| retries automáticos | 0 |
| `sleep` / `usleep` | 0 / 0 |
| mutaciones funcionales | 0 |
| red | 0 |

Solo se permiten `new`, asignación de referencias, comparación de estado,
retorno y manejo/propagación de `Throwable`.

## 23. Catálogo A8 protegido

A9 no modifica, aliasa, interpreta ni reduce los once estados certificados de
`DurableRetryInitialProductionRoutingResult`. No crea un DTO sustituto y no
invoca `routeReconciliation()`.

Solo puede existir un router productivo A8 dentro de una instancia A9 completa.
No se permite proxy, decorator, subclase, segundo router de fallback ni router
legacy paralelo.

## 24. Allowlist definitiva de implementación A9

La implementación posterior de A9 puede crear/modificar exclusivamente estas
cinco rutas:

### Producto nuevo

1. `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionComposition.php`

### Producto existente modificado

2. `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php`

### Harnesses nuevos

3. `tests/manual/durable-retry-production-composition-test.php`
4. `tests/manual/durable-retry-production-composition-infrastructure-test.php`
5. `tests/manual/durable-retry-production-composition-integration-test.php`

No se modifica este documento durante implementación. No se autoriza modificar
`Application.php`, materializador, registrar de hooks, callbacks, A1–A8,
repositorios, coordinator externo, adapter Action Scheduler, Config, schema,
migraciones, workers, recovery, orchestration ni processors históricos.

Si la adaptación de `DurableCompletionScheduler` requiere tocar callers, eso se
separa en otro microhito; no amplía esta allowlist.

## 25. Matriz normativa de pruebas

| ID | Harness principal | Aserción obligatoria |
|---|---|---|
| A9-01 | funcional | primera llamada compone y retorna A8 concreto |
| A9-02 | funcional | segunda llamada retorna el mismo A8 por `===` |
| A9-03 | funcional | diez llamadas retornan la misma instancia |
| A9-04 | funcional | identidad estable del router A8 |
| A9-05 | funcional | A5 se crea una vez y A8 conserva esa identidad |
| A9-06 | funcional | A6 se crea una vez y A8 conserva esa identidad |
| A9-07 | funcional | A7 se crea una vez y A8 conserva esa identidad |
| A9-08 | infraestructura | identidad `===` de database, reader, schedulers, reloj y repositorio durable compartido |
| A9-09 | infraestructura | journal confirma orden exacto de §14 |
| A9-10 | infraestructura | cero invocaciones funcionales A5–A8 |
| A9-11 | infraestructura | cero SQL/transacciones |
| A9-12 | infraestructura | cero hooks/filtros registrados o ejecutados |
| A9-13 | infraestructura | cero scheduling, búsqueda o cancelación |
| A9-14 | infraestructura | cero `read()`/`snapshot()`/opciones |
| A9-15 | guardia | unidad A9 no importa ni usa materializador |
| A9-16 | guardia Git | `Application.php` y bootstrap no modificados |
| A9-17 | infraestructura | grafo de constructores no contiene ciclos |
| A9-18 | funcional | excepción no publica router ni estado parcial |
| A9-19 | funcional | intento explícito posterior al fallo puede completar y estabilizar identidad |
| A9-20 | funcional | reentrada produce `LogicException` y mensaje literal |
| A9-21 | funcional | `router()` no admite configuración; otra configuración exige otra instancia independiente |
| A9-22 | integración | `DurableCompletionScheduler` satisface puerto y semántica booleana completa |
| A9-23 | infraestructura | constructor A8 recibe A5, A6, A7 y legacy en ese orden |
| A9-24 | guardia | un único `new DurableRetryInitialProductionRouter` en producto A9 |
| A9-25 | guardia | no existe service locator/API pública adicional |
| A9-26 | guardia | no aparecen aliases de estados A8 |
| A9-27 | integración | catálogo A8 conserva exactamente once estados certificados |
| A9-28 | suite | suite histórica completa permanece verde y sin diagnostics |

Los harnesses pueden usar Reflection solo para inspeccionar identidad/estado
privado sin mutar producto, especialmente A9-05–A9-09 y la simulación controlada
de reentrada A9-20. Reflexión en código productivo permanece prohibida.

## 26. Distribución mínima de casos

- harness funcional: mínimo 12 casos, incluyendo A9-01–A9-07 y A9-18–A9-21;
- harness infraestructura: mínimo 12 casos, incluyendo A9-08–A9-17, A9-23 y
  A9-25;
- harness integración: mínimo 8 escenarios, incluyendo API Action Scheduler
  presente/ausente, duplicado, retorno inválido, excepción, identidad del puerto,
  catálogo A8 y composición con implementaciones reales sin ejecutar el router.

Cada harness debe detenerse ante la primera desviación, usar limpieza `finally`,
emitir cero diagnostics y verificar su propia allowlist.

## 27. Guardias léxicas y estructurales

En `DurableRetryProductionComposition.php` quedan prohibidos:

- `add_action`, `add_filter`, `do_action`, `apply_filters`;
- cualquier llamada `as_*`;
- `global`, `$GLOBALS`, `$wpdb` como variable global;
- `SELECT`, `INSERT`, `UPDATE`, `DELETE`, transacciones o nombres literales de
  tablas;
- `get_option`, `update_option` y `snapshot(`;
- `routeReconciliation(`, `produceReconciliation(`, `resolve(` y `coordinate(`;
- `sleep`, `usleep` y loops de retry;
- `Reflection*`, nombres de clase recibidos como string y `new $class`;
- `Container`, `container()`, `make(`, `bind(` o `singleton(`;
- arrays/mapas de servicios;
- materializador, hook registrar, callback o processors;
- propiedades `static`, `public` o `protected` de estado;
- `catch` que no vuelva a lanzar el mismo `Throwable`.

En toda la allowlist se prohíben cambios a los once estados A8. Las llamadas
`as_*` solo se permiten dentro del archivo legacy ya existente y exclusivamente
para implementar la semántica de §19; nunca durante composición o tests de
identidad.

## 28. Guardias Git y de alcance

La implementación deberá verificar antes y después:

- rama y HEAD esperados del hito de implementación;
- staging inicialmente vacío;
- ninguna ruta tracked fuera de las cinco de §24;
- ningún archivo temporal o índice temporal;
- ningún cambio a docs durante el hito;
- cero cambios al materializador/bootstrap/A1–A8;
- suite completa verde;
- no commit ni push salvo mandato posterior explícito.

Los artefactos baseline no se eliminan, regeneran ni incorporan a la allowlist.

## 29. Integración posterior reservada

Después de certificar A9, un mandato separado podrá definir el wiring. Ese hito
deberá reutilizar la misma instancia A9 en todos sus puntos y será el único
autorizado para decidir ubicación en `Application`, hook inicial, prioridad,
accepted args, materializador, guards legacy y activación.

A9 no anticipa esas decisiones. La existencia de `router()` no autoriza su
invocación funcional ni el registro de un callback.

## 30. Compatibilidad estructural

Los constructores certificados permiten el grafo de §13 sin ciclos ni nuevas
interfaces. La única brecha concreta es que
`DurableCompletionScheduler` aún no implementa el puerto legacy; §19 cierra su
forma futura y §24 autoriza exactamente esa modificación.

El `Container` existente no se necesita dentro de A9. El wiring posterior podrá
conservar A9 como singleton, pero esa decisión no cambia la idempotencia interna
por instancia ni habilita un service locator.

## 31. Criterios de implementación completa

A9 se considera implementado únicamente si:

1. las cinco rutas de la allowlist son las únicas rutas del hito;
2. clase, constructor, API y estado coinciden literalmente con §§4–9;
3. orden y grafo coinciden con §§13–15;
4. identidades `===` cumplen §16;
5. scheduler legacy cumple §19 sin romper firmas históricas;
6. presupuesto §22 es cero en todos sus puntos;
7. pasan A9-01–A9-28;
8. suite histórica permanece completamente verde y sin diagnostics;
9. no existe wiring, hook, SQL, scheduling o llamada funcional durante
   composición;
10. no se realizó ampliación creativa de alcance.

## 32. Ambigüedades cerradas

| Bloqueador de auditoría | Cierre normativo |
|---|---|
| B1 identidad/API ausente | clase, FQCN, ruta, constructor y `router()` en §§4–6 |
| B2 idempotencia no normada | lifecycle, `===` y máquina de estados en §§7–11 |
| B3 puerto legacy sin implementación | adaptación directa exacta en §19 |
| B4 allowlist mezclada | cinco rutas cerradas en §24 |
| B5 harnesses de wiring | matriz A9-01–A9-28 y tres harnesses en §§25–26 |

Ambigüedades restantes para implementar A9: **0**.

## 33. Resumen normativo definitivo

- FQCN A9:
  `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition`.
- firma pública:
  `public function router(): DurableRetryInitialProductionRouter`.
- retorno: router A8 concreto.
- idempotencia: máximo una construcción exitosa por instancia A9 y mismo router
  `===` en todas las llamadas exitosas.
- fallo: limpieza a `UNINITIALIZED`, propagación sin envolver y reintento
  explícito permitido.
- reentrada: `LogicException` estable.
- estado estático: prohibido.
- efectos de composición: cero.
- scheduler legacy: `DurableCompletionScheduler` implementa directamente el
  puerto booleano.
- wiring posterior: totalmente fuera de A9.
- allowlist: una clase nueva, una clase legacy modificada y tres harnesses.

## 34. Veredicto

A9 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA
