# Diseño del wiring productivo del pipeline durable de Orders

## 1. Veredicto ejecutivo

El pipeline durable tiene completos sus mecanismos de persistencia, política,
coordinación externa y cuatro procesadores funcionales aislados, pero no está
compuesto ni invocado desde producción. `DurableRetryExecutor` sólo acepta hoy
un `DurableRetryStageProcessorInterface`; por tanto, cada harness construye un
executor especializado en una etapa y no existe una resolución productiva de
las cuatro etapas.

La solución mínima y cerrada es:

1. introducir un `DurableRetryStageProcessorResolverInterface`;
2. implementarlo mediante un `DurableRetryProcessorRegistry` inmutable,
   construido con los cuatro procesadores;
3. inyectar el resolver al executor en reemplazo del procesador individual;
4. resolver después de leer y validar el snapshot y antes de adquirir el claim;
5. componer el grafo mediante factories lazy en `Application`, sin resolverlo ni
   ejecutarlo durante bootstrap;
6. dejar callback, hooks, disparadores, recuperación y scheduling inicial para
   microhitos posteriores.

La configuración es total o inválida: exactamente una implementación por cada
valor de `DurableRetryStage::all()`. No se admite `null`, duplicados, claves
externas, descubrimiento dinámico ni wiring parcial. Un error de composición no
se transforma en retry funcional.

## 2. Estado actual auditado

Base auditada:

- rama `main`;
- HEAD `225725b59b89bedff10a1c3d16128242e41a1e23`;
- divergencia `origin/main...HEAD`: `0 17`;
- índice vacío y archivos tracked limpios;
- 11 documentos no rastreados protegidos;
- 504 archivos no rastreados bajo `artifacts/`.

El núcleo durable existente incluye:

- `DurableRetryExecutor`, que valida invocación, lee el retry, verifica
  generación/hook/estado, realiza claim CAS, crea el contexto, llama un
  procesador, persiste el cierre o crea la generación siguiente y pide su
  coordinación externa;
- `DurableRetryStage`, catálogo cerrado de cuatro etapas;
- `DurableRetryStageProcessorInterface`, con `stage()` y `process(context)`;
- `DurableRetryProcessingPolicy`, responsable de backoff, agotamiento y
  clasificación de la siguiente acción;
- `DurableRetryScheduleRepository`, autoridad de lectura, transiciones CAS y
  creación transaccional de la generación siguiente;
- `DurableRetryExternalScheduleCoordinator`, que coordina persistencia y
  scheduler;
- `ActionSchedulerDurableRetryAdapter`, único borde del núcleo nuevo que llama
  la API de Action Scheduler;
- DTOs cerrados para snapshots, contextos, resultados, fallos, decisiones,
  persistencia, coordinación y scheduling.

Hallazgo determinante: no existe en `app/` una construcción de
`DurableRetryExecutor`. Sólo se construye en harnesses manuales, siempre con un
procesador concreto. Tampoco existe callback productivo para los cuatro hooks de
`DurableRetryExternalScheduleCatalog`.

El pipeline legado (`DurableCompletionOrchestration`,
`DurableCompletionWorkers`, `DurableCompletionScheduler`) sí está activo. Es
independiente del nuevo repositorio durable y mezcla registro, construcción,
ejecución y reprogramación. Debe permanecer intacto hasta que exista un plan
explícito de convivencia o migración.

## 3. Mapa de componentes

```text
veciahorra.php
└─ Bootstrap::boot()
   └─ Application::__construct()       registra factories
      └─ Application::run()            registra módulos/hooks actuales

Futuro borde productivo (no implementado aquí)
callback Action Scheduler
└─ DurableRetryExecutor
   ├─ DurableRetryScheduleRepository   autoridad durable + CAS
   ├─ DurableRetryProcessingPolicy     backoff/clasificación
   ├─ DurableRetryExternalScheduleCoordinator
   │  ├─ DurableRetryScheduleRepository
   │  └─ ActionSchedulerDurableRetryAdapter
   └─ DurableRetryProcessorRegistry
      ├─ DurableRetryReconciliationProcessor
      ├─ DurableRetryBusinessCompletionProcessor
      ├─ DurableRetryDeliveryCompletionProcessor
      └─ DurableRetryFulfillmentProcessor
```

El registry selecciona; no es autoridad, no crea contextos y no llama
`process()`. El executor conserva la coordinación de una ejecución durable. Los
procesadores conservan el máximo de un intento funcional y su relectura
autoritativa.

## 4. Composition roots encontrados

### Root principal

El composition root productivo real es la cadena
`veciahorra.php → Core\Bootstrap::boot() → Core\Application`.
`Application::__construct()` configura `Container`; `Application::run()`
resuelve módulos y registra hooks. El contenedor combina bindings explícitos con
autowiring por reflexión.

### Roots parciales

Existen roots parciales en registradores y workers que usan `new`:

- `DurableCompletionOrchestration::register()` crea workers y recovery;
- `DurableCompletionWorkers` crea repositorios y procesadores por callback;
- orquestadores Webpay construyen y registran callbacks propios;
- varios módulos resueltos por `Application` construyen sus rutas/servicios.

No hay un root único en sentido estricto: `Application` es el root superior,
pero delega composición parcial. Para el nuevo pipeline debe evitarse un nuevo
root oculto en un callback o procesador.

### Decisión

El grafo durable debe declararse en `Application` mediante bindings singleton
lazy y explícitos. El executor debe construirse allí, no en:

- `veciahorra.php`;
- el registry;
- cada callback;
- un repositorio, procesador o scheduler;
- métodos estáticos/globales;
- `DurableCompletionWorkers`;
- el adaptador de Action Scheduler.

Las closures se registran durante construcción, pero ninguna se evalúa hasta que
el futuro callback pida el executor. Esto evita SQL y trabajo durable en
bootstrap. La construcción de repositorios captura conexiones/tabla, pero no
debe ejecutar consultas; aun así se difiere hasta ejecución para reducir
acoplamiento temporal con WordPress.

## 5. Alternativas de resolución evaluadas

| Alternativa | Tipado/catálogo | Duplicados y desconocidas | Testabilidad/auditoría | Riesgo |
|---|---|---|---|---|
| Mapa inyectado `stage => processor` | La clave sigue siendo `string`; puede estar incompleto | Requiere validación adicional | Simple, pero permite mismatch clave/`stage()` | Wiring parcial y claves erróneas |
| Registry tipado | Contrato único y validación contra `DurableRetryStage::all()` | Rechazo fail-fast de ausentes, extras y duplicados | Alta; API mínima y fuente inspeccionable | Bajo si es inmutable y puro |
| Factory cerrada | Puede cerrar el catálogo | Puede validar, pero tiende a construir objetos al resolver | Buena, aunque mezcla selección y creación | Instancias duplicadas/efectos tardíos |
| `match` explícito | Catálogo muy visible | Desconocidas cerradas; duplicados no aplican | Fácil de leer | Acopla executor o factory a concretos |
| Service locator global | Tipado débil e inventario mutable | Fallos tardíos | Difícil de aislar | Estado global, orden de bootstrap |
| Nombre de clase dinámico | Catálogo abierto e implícito | Fallos tardíos | Difícil de certificar | Carga arbitraria y wiring parcial |

Se elige el **registry tipado e inmutable**. Recibe objetos ya construidos, usa
`processor->stage()` como única clave y verifica igualdad exacta entre su
catálogo y `DurableRetryStage::all()`. Así no existe una clave externa que pueda
divergirse del procesador. Es preferible a un `match` dentro del executor porque
preserva la independencia del executor respecto de las cuatro clases concretas.

## 6. Decisión arquitectónica

- Un registry completo se inyecta al executor mediante interfaz.
- El caller continúa entregando `hook`, `scheduleId` y `generation`.
- El executor carga el snapshot y conserva todas sus validaciones actuales.
- Antes del claim, el executor solicita al resolver el único procesador para
  `snapshot->stage()`.
- La resolución se hace antes de mutar para que una configuración inválida no
  deje un retry claimed.
- El executor verifica además que `processor->stage()` coincide con el snapshot,
  como defensa redundante.
- El executor, no el callback, llama al procesador y conserva persistencia,
  política, CAS y coordinación de la siguiente generación.
- Registry, wiring, registro WordPress, callback y scheduling permanecen en
  componentes y microhitos distintos.

No se recomienda entregar un procesador resuelto desde el caller: obligaría al
callback a leer la autoridad durable o a confiar en una etapa no autoritativa.
Tampoco se recomienda que el registry reciba el executor o ejecute trabajo.

## 7. Contratos propuestos

### `DurableRetryStageProcessorResolverInterface`

- Namespace:
  `VeciAhorra\Modules\Orders\Contracts`.
- API:

```php
interface DurableRetryStageProcessorResolverInterface
{
    public function resolve(string $stage): DurableRetryStageProcessorInterface;
}
```

- Entrada: etapa autoritativa leída del snapshot.
- Salida: exactamente un procesador.
- Nunca devuelve `null`.
- No conoce el executor.
- No ejecuta, persiste, programa, reintenta ni accede a WordPress/SQL.
- Ante etapa desconocida o configuración imposible lanza
  `DurableRetryProcessorConfigurationException`.

### `DurableRetryProcessorRegistry`

- Namespace: `VeciAhorra\Modules\Orders\Services`.
- Constructor:
  `__construct(array $processors)`.
- Dependencia: colección de `DurableRetryStageProcessorInterface`.
- Invariantes de construcción:
  - cada elemento implementa el contrato;
  - `stage()` pertenece a `DurableRetryStage::all()`;
  - ninguna etapa se repite;
  - no falta ninguna etapa;
  - no sobra ninguna etapa;
  - el mapa interno no se modifica después del constructor.
- `resolve()` sólo consulta el mapa.
- Efecto permitido: validar objetos y construir un array privado.
- Efectos prohibidos: llamar `process()`, SQL, globals, hooks, scheduling,
  logging operativo, reloj, red o mutaciones externas.

Aunque PHP permita arrays heterogéneos, el constructor debe validar cada valor
antes de llamar `stage()`, para producir un error tipado y no un error incidental.

### `DurableRetryProcessorConfigurationException`

- Namespace: `VeciAhorra\Modules\Orders\Exceptions`.
- Extiende `RuntimeException`.
- Representa únicamente configuración inválida: tipo incorrecto, etapa
  desconocida, duplicada, ausente o catálogo inconsistente.
- No representa fallos funcionales ni de persistencia.
- No debe capturarse como retryable/outcome uncertain.

### Cambio acotado de `DurableRetryExecutor`

El constructor reemplaza
`DurableRetryStageProcessorInterface $processor` por
`DurableRetryStageProcessorResolverInterface $processors`.
`execute()` resuelve una vez, antes del claim. Si recibe la excepción tipada,
devuelve un resultado explícito de configuración con intervención y
`processed=false`, o la propaga al borde si todavía no se añade ese código. La
opción preferida es añadir `PROCESSOR_CONFIGURATION_ERROR` a
`DurableRetryExecutionResult`: hace observable el fallo sin disfrazarlo de
persistencia o retry funcional. Cualquier otro error de programación debe
propagarse; no debe capturarse con `Throwable` en la resolución.

## 8. Construcción de dependencias

Las instancias compartidas evitan que la tentativa y la relectura usen objetos
distintos sin necesidad. Construir estos objetos no debería hacer SQL; sus
métodos operativos sí.

| Etapa | Wrapper durable | Intento concreto | Relectura/autoridad | Compartición |
|---|---|---|---|---|
| Reconciliation | `DurableRetryReconciliationProcessor` | `PaymentReconciliationProcessor` | `PaymentReconciliationClaimRepository` y `PaymentReconciliationRepository` | compartir claims y reconciliations con el intento |
| Business Completion | `DurableRetryBusinessCompletionProcessor` | `BusinessCompletionProcessor` | `BusinessCompletionRepository` | compartir completions; el intento usa además reconciliation, checkout, session, payment y order repositories |
| Delivery Completion | `DurableRetryDeliveryCompletionProcessor` | `DeliveryCompletionProcessor` | `DeliveryCompletionRepository` | compartir completions; el intento usa `DeliveryRepository` y `OrderRepository` |
| Fulfillment Completion | `DurableRetryFulfillmentProcessor` | `FulfillmentCompletionProcessor` | `FulfillmentCompletionRepository` | compartir completions |

Para Reconciliation, `PaymentReconciliationProcessor` requiere además
`PaymentOriginContextRepository`, `ValidatedFinancialResultRepository`,
`PaymentReconciliationTechnicalEvaluator`, `SystemReconciliationClock`, los
parámetros de heartbeat existentes y `PaymentCompletionHandlerRegistry`. No se
deben alterar esos defaults ni sus políticas.

Pseudocódigo de composición (los nombres reflejan constructores reales):

```php
$scheduleRepository = new DurableRetryScheduleRepository();
$utcNow = static fn (): string => gmdate('Y-m-d H:i:s');
$coordinator = new DurableRetryExternalScheduleCoordinator(
    $scheduleRepository,
    new ActionSchedulerDurableRetryAdapter(),
    $utcNow(...)
);

$reconciliationClaims = new PaymentReconciliationClaimRepository();
$reconciliations = new PaymentReconciliationRepository();
$reconciliationAttempt = new PaymentReconciliationProcessor(
    $reconciliationClaims,
    $reconciliations
    // se conservan las restantes dependencias/defaults certificados
);

$businessCompletions = new BusinessCompletionRepository();
$businessAttempt = new BusinessCompletionProcessor(
    $businessCompletions,
    $reconciliations
    // restantes repositorios concretos certificados
);

$deliveryCompletions = new DeliveryCompletionRepository();
$deliveryAttempt = new DeliveryCompletionProcessor(
    $deliveryCompletions,
    new DeliveryRepository(),
    new OrderRepository()
);

$fulfillmentCompletions = new FulfillmentCompletionRepository();
$fulfillmentAttempt = new FulfillmentCompletionProcessor(
    $fulfillmentCompletions
);

$processors = new DurableRetryProcessorRegistry([
    new DurableRetryReconciliationProcessor(
        $reconciliationClaims,
        $reconciliationAttempt,
        $reconciliations
    ),
    new DurableRetryBusinessCompletionProcessor(
        $businessAttempt,
        $businessCompletions
    ),
    new DurableRetryDeliveryCompletionProcessor(
        $deliveryAttempt,
        $deliveryCompletions
    ),
    new DurableRetryFulfillmentProcessor(
        $fulfillmentAttempt,
        $fulfillmentCompletions
    ),
]);

$executor = new DurableRetryExecutor(
    $scheduleRepository,
    new DurableRetryProcessingPolicy(),
    $coordinator,
    $processors,
    $utcNow(...)
);
```

En código productivo no deben omitirse argumentos intermedios de un constructor
para “saltar” a defaults posteriores; la factory debe pasar el grafo completo o
usar factories auxiliares locales claramente nombradas. El pseudocódigo abrevia
sólo esas colas para legibilidad.

## 9. Flujo de ejecución

```text
callback explícito
→ valida hook/schedule_id/generation
→ executor carga retry durable por id
→ executor valida generación, hook, estado y acción
→ registry resuelve snapshot.stage
→ executor verifica stage() y adquiere claim CAS
→ executor construye DurableRetryExecutionContext
→ executor llama exactamente una vez a processor.process()
→ procesador hace como máximo un intento y su relectura autoritativa
→ executor valida DurableRetryProcessingResult
→ executor persiste cierre o pide decisión a la política
→ repositorio crea generación siguiente mediante CAS/transacción
→ coordinator solicita al scheduler la acción siguiente
→ coordinator persiste la identidad externa
```

El wiring sólo construye el grafo. El futuro callback identifica la ejecución y
delega. El executor ya realiza carga, validaciones, claim, llamada, persistencia,
decisión y coordinación. El único paso nuevo dentro del executor es resolución
estructural previa al claim. El registry no participa después de devolver el
procesador.

## 10. Errores de configuración

| Caso | Respuesta cerrada | Momento principal |
|---|---|---|
| Etapa desconocida | excepción tipada; nunca fallback | construcción/resolve y prueba estructural |
| Etapa conocida ausente | rechazo del constructor | construcción del registry |
| Procesador duplicado | rechazo del segundo | construcción |
| Clave distinta de `stage()` | no hay claves externas; si se admite mapa, rechazo | construcción |
| Objeto sin contrato | excepción tipada antes de `stage()` | construcción |
| Wiring parcial | comparación exacta con `Stage::all()` | construcción/bootstrap lazy |
| Dependencia concreta ausente | falla explícita al construir el grafo | resolución de factory |
| Catálogo y registry inconsistentes | comparación exacta y harness de cobertura | construcción/CI |

El bootstrap puede registrar factories sin evaluarlas. La primera resolución
productiva debe fallar de forma visible si el grafo es inválido, antes de leer o
mutar un retry. No se permite registrar sólo las etapas “disponibles”.

Los fallos de configuración no son retryables, no generan nueva generación y no
invocan scheduler. Los errores de programación no deben quedar absorbidos por
un `catch (Throwable)` agregado alrededor del resolver.

## 11. Bootstrap seguro y frontera con scheduling/hooks

Se distinguen cuatro fases:

1. **Construcción/configuración:** registrar closures lazy y, al solicitarlas,
   crear objetos sin métodos operativos.
2. **Registro:** el futuro registrar añade callbacks una sola vez; no ejecuta
   callbacks ni agenda acciones.
3. **Activación/recuperación:** futuros componentes explícitos crean semillas o
   recuperan retries; no pertenecen al registry ni al primer wiring.
4. **Ejecución:** Action Scheduler invoca un callback con identidad validada;
   recién entonces se resuelve el executor y se accede a autoridades.

Fronteras obligatorias:

- registry: selección pura;
- executor: una ejecución durable y su posible siguiente generación;
- coordinator: convergencia entre generación durable y acción externa;
- adapter: API concreta de Action Scheduler;
- callback: valida argumentos y llama executor;
- registrar: sólo `add_action`;
- disparadores iniciales/recovery: componentes posteriores e independientes.

Construir o registrar no puede leer retries, llamar procesadores, ejecutar SQL,
crear acciones, duplicar hooks ni mutar estado. El registry no depende de que
Action Scheduler esté inicializado. El adapter sólo comprueba sus funciones al
ejecutar `schedule()`.

## 12. Idempotencia, concurrencia y compatibilidad

El wiring no modifica ni reemplaza:

- leases funcionales ni owners;
- claim y transiciones CAS del retry durable;
- generación, attempt number o active slot;
- backoff, agotamiento o política;
- máximo de un intento funcional por `execute()`;
- relecturas autoritativas de cada procesador;
- clasificaciones succeeded/retryable/terminal/manual review/uncertain;
- persistencia de resultados y creación transaccional de sucesores;
- ownership/deduplicación del scheduler.

Compatibilidad esperada:

- `DurableRetryExecutor`: único cambio funcional, de procesador a resolver y
  resolución antes del claim;
- harnesses del executor: envolver dobles individuales en registries completos
  o usar un resolver double cerrado;
- integraciones por etapa: deben demostrar la misma conducta previa;
- procesadores y contratos funcionales: intactos;
- scheduler/coordinator: intactos;
- orquestación durable legada: intacta y aún registrada;
- bootstrap: sólo bindings lazy en el microhito C;
- schema y migraciones: sin cambios;
- pickup y delivery: mismos harnesses funcionales.

No debe eliminarse el chequeo de mismatch del executor. El registry lo garantiza
estáticamente y el executor lo verifica contra la autoridad leída.

## 13. Estrategia de implementación por microhitos

### A — Resolver puro

Crear interfaz, excepción y registry, con harness unitario y estructural. Sin
executor, bootstrap, WordPress, SQL, scheduler ni hooks.

### B — Integración con executor

Cambiar la dependencia del executor, resolver antes del claim, representar el
error de configuración y adaptar harnesses. Preservar byte-for-byte, salvo el
área necesaria, política, persistencia, CAS, backoff y coordinación.

### C — Composition root productivo lazy

Añadir bindings explícitos en `Application` para repositorio durable,
coordinator, cuatro grafos funcionales, registry y executor. Certificar que
registrar/construir `Application` no resuelve el grafo, no hace SQL, no agenda y
no ejecuta. Todavía sin callback ni `add_action` nuevo.

### D — Callback productivo

Crear un callback explícito que reciba el hook conocido más
`schedule_id/generation`, valide tipos/rangos y llame al executor. Probar cada
hook. Sin disparadores iniciales, recovery ni programación directa.

Como Action Scheduler no incluye por defecto el nombre del hook en sus
argumentos normalizados, el registro puede usar cuatro métodos/cierres pequeños
que fijan cada hook y delegan en un callback común; esa decisión se implementa
y prueba aquí, no en el registry.

### E — Registro y scheduling operativo

Registrar exactamente una vez los cuatro callbacks, definir activación,
continuidad/recovery y convivencia o retiro del pipeline legado. Conservar el
adapter/coordinator como único camino para reprogramar. Diseñar deduplicación y
despliegue antes de habilitar disparadores.

No se combinan C, D y E: construir, invocar y activar tienen riesgos distintos.

## 14. Matriz de pruebas futura

| Área | Certificación |
|---|---|
| Catálogo | las cuatro etapas resuelven exactamente su procesador |
| Aislamiento | cada doble registra llamadas sólo para su etapa |
| Desconocida/ausente | excepción tipada, no `null`, cero trabajo |
| Duplicados | constructor rechaza dos procesadores con igual `stage()` |
| Mismatch | mapa externo prohibido o mismatch rechazado |
| Pureza registry | sin `process(`, SQL, globals, hooks, scheduler, loops de retry |
| Construcción | spies aseguran cero SQL y cero métodos operativos |
| Bootstrap | cero reads, retries, acciones programadas y callbacks ejecutados |
| Executor | resolución una vez y antes del claim; proceso una vez máximo |
| Error config | no claim, no generación, no scheduling, intervención visible |
| Programación | `LogicError`/`TypeError` del resolver no se vuelve retryable |
| Concurrencia | harnesses actuales de claim/CAS/generation siguen pasando |
| Scheduling | permanece sólo en coordinator/adapter |
| Schema | diff/allowlist impide migrations y schemas |
| Regresión | los cuatro harnesses de procesador e integraciones pasan |
| Legado | durable completion orchestration y recovery siguen pasando |
| Funcional | pickup, delivery y cadena transaccional sin cambios |

Pruebas estructurales recomendadas:

- allowlist exacta por microhito;
- conteo exacto de cuatro entradas y comparación con `Stage::all()`;
- búsquedas prohibidas en registry: `$wpdb`, `SELECT`, `INSERT`, `UPDATE`,
  `DELETE`, `add_action`, `as_schedule_`, `do_action`, `process(`, `sleep`,
  `while`, `for`;
- búsqueda que prohíba `new DurableRetryExecutor` fuera de composition root y
  tests;
- búsqueda que prohíba `Container` o globals dentro del registry/executor;
- diff que confirme schemas, procesadores funcionales y adapter intactos cuando
  no correspondan al microhito.

## 15. Riesgos y mitigaciones

| Riesgo | Mitigación | Prueba |
|---|---|---|
| Service locator global | inyección por constructor | prohibir `Container`, globals y estáticos en registry |
| Autoridades duplicadas | compartir repositorio entre intento/relectura | doubles verifican identidad de instancia/factory |
| Resolver nullable | retorno no nullable + excepción tipada | análisis de firma y etapa ausente |
| Wiring parcial | igualdad exacta con `Stage::all()` | faltante y catálogo ampliado |
| Hooks duplicados | registrar sólo en componente E con guardas explícitas | registrar dos veces y contar callbacks |
| Ejecución en bootstrap | factories lazy; no resolver executor en `run()` | spies de SQL/proceso/schedule |
| Scheduling mezclado | coordinator/adapter exclusivos | búsqueda prohibida |
| Configuración ocultada como retry | resultado/config exception propio antes de claim | cero transición y cero scheduling |
| Circularidad | registry no conoce executor; processors no conocen registry | inspección de constructores |
| Executor sobredimensionado | reemplazar una dependencia y un punto de selección | diff/allowlist del microhito B |
| Factory dinámica | lista explícita de cuatro objetos | prohibir class-string/reflection |
| SQL/WordPress en registry | clase pura | búsquedas prohibidas y doubles |
| Orden incidental de bootstrap | factories lazy y callback resuelve al ejecutarse | prueba con AS no inicializado |
| Legado y nuevo activos a la vez | no habilitar disparadores hasta plan E | prueba de inventario de hooks |
| `Throwable` demasiado amplio | captura exclusiva de excepción configuracional | double lanza `LogicError` |

## 16. Archivos esperados

### Microhito A: lista exacta mínima

Crear:

- `app/Modules/Orders/Contracts/DurableRetryStageProcessorResolverInterface.php`
  — productivo, contrato;
- `app/Modules/Orders/Exceptions/DurableRetryProcessorConfigurationException.php`
  — productivo, error tipado;
- `app/Modules/Orders/Services/DurableRetryProcessorRegistry.php`
  — productivo, resolución pura;
- `tests/manual/durable-retry-processor-registry-test.php`
  — prueba;
- `tests/manual/durable-retry-processor-registry-infrastructure-test.php`
  — prueba estructural.

Modificar: ninguno. Permanecer intactos: executor, procesadores, repositorios,
schema, bootstrap, scheduler y orquestación legada.

### Microhito B probable

Modificar:

- `app/Modules/Orders/Services/DurableRetryExecutor.php` — productivo;
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryExecutionResult.php`
  — productivo, sólo si se adopta el código explícito recomendado;
- harnesses del executor e integraciones de las cuatro etapas — pruebas;
- allowlists estructurales que enumeran la firma del executor — pruebas.

El contrato público `DurableRetryExecutorInterface` no necesita cambiar:
`execute(hook, scheduleId, generation)` se conserva.

### Microhito C probable

Modificar:

- `app/Core/Application.php` — bootstrap/composition root.

Crear, sólo si el binding inline vuelve inauditable el grafo:

- `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionFactory.php`
  — infraestructura pura de composición;
- harness de composition root y bootstrap — pruebas.

La factory adicional no es la opción inicial: primero debe intentarse una
sección privada y explícita de bindings en `Application`.

### Microhitos D y E

Crear posteriormente callback y registrar productivos bajo Orders/Infrastructure
o Orders/Orchestration, con harnesses separados. Los nombres finales dependen de
la convención cerrada en D. Sólo E modifica `Application::run()` para registrar
hooks.

Permanecen intactos durante A–C: schemas/migraciones, cuatro procesadores
funcionales y durable wrappers ya certificados, contratos de intento/relectura,
política, repositorios funcionales, adapter de scheduler y pipeline legado.

Este documento es el único archivo del microhito documental actual.

## 17. Criterios de certificación

Un microhito de wiring sólo se aprueba si:

1. su allowlist es exacta y no incluye schema ni lógica funcional;
2. el catálogo tiene cuatro etapas, sin faltantes ni duplicados;
3. desconocidas y wiring parcial fallan cerradamente;
4. resolver nunca devuelve `null` ni ejecuta trabajo;
5. bootstrap no hace SQL, no procesa y no agenda;
6. executor resuelve una vez antes del claim y procesa una vez máximo;
7. configuración inválida no cambia estado ni se clasifica retryable;
8. leases, CAS, generaciones, backoff y coordinator conservan sus harnesses;
9. cuatro procesadores e integraciones siguen certificados;
10. pipeline legado, pickup, delivery y cadena end-to-end no regresan;
11. `git diff --check`, lint y búsquedas estructurales pasan;
12. activación productiva no ocurre antes del microhito E y su plan operativo.

## 18. Fuera de alcance

Este diseño no implementa código, tests, factories, callbacks, hooks,
scheduling, recovery, activación, migraciones ni configuración. Tampoco decide
todavía:

- estrategia de creación inicial de filas durable;
- migración o apagado del pipeline legado;
- despliegue gradual, feature flag u observabilidad operativa;
- recuperación histórica y barrido;
- detalles finales de nombres/prioridades de hooks;
- cambios de schema;
- nuevas políticas de backoff, leases o intentos;
- cambios en autoridades o clasificaciones funcionales.

Esos asuntos requieren microhitos explícitos. En particular, construir el grafo
no autoriza registrarlo, registrarlo no autoriza ejecutarlo y ejecutarlo no
autoriza crear nuevos retries.
