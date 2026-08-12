# Auditoría de readiness A9 — composición productiva idempotente

## 1. Dictamen ejecutivo

**A9 BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

El repositorio está sano y A5–A8 están presentes, pero la norma vigente no
permite implementar de forma inequívoca un A9 limitado a composición pura. La
corrección A10 define A9 como un hito combinado de composition root, registro de
hooks y exclusión legacy. No define una unidad productiva separada que construya
y exponga el router A8 sin activar el flujo.

No existe incompatibilidad arquitectónica irreversible: el `Container` actual
soporta singletons y puede conservar identidad por instancia. El bloqueo es
documental porque faltan el contrato de acceso, la identidad de la unidad A9 y
la división normativa de la allowlist. Además, el grafo no puede cerrarse hoy
con implementaciones productivas porque `DurableCompletionScheduler` todavía no
implementa `DurableRetryLegacySchedulerInterface`, adaptación que A10 asigna al
A9 integrado.

Esta auditoría no activa wiring, hooks, callbacks, scheduling, SQL ni flujo
funcional.

## 2. Alcance estricto

Se auditó exclusivamente la implementabilidad futura de una composición que:

- construya el grafo productivo A5–A8;
- preserve una sola identidad por instancia de composición;
- exponga el `DurableRetryInitialProductionRouter` sin invocarlo;
- no registre hooks ni modifique el materializador;
- no ejecute consultas, scheduling, callbacks ni lógica funcional al componer;
- falle de forma visible y reintentable si la construcción no puede completarse.

Quedaron fuera implementación productiva, bootstrap activo, guards legacy,
recovery, workers, schema, migraciones, A1–A8, commit y push.

## 3. Baseline certificado

| Control | Resultado |
|---|---|
| rama | `main` |
| HEAD | `7f2a8cbcf318dbd24b0424bd61224920012acdd1` |
| padre A8 | `ba597227625b6918b63ec2dc220001d8cc777486` |
| divergencia upstream | `0 behind / 53 ahead` |
| staging inicial | `0` rutas |
| cambios tracked iniciales | `0` rutas |
| artefactos baseline | `504` |
| temporales / índices temporales | `0 / 0` |
| suite | `68/68` archivos, `5.812` assertions, `0` fallos, `0` diagnostics |
| A9 productivo | ausente |

Los documentos y artefactos untracked preexistentes se consideran baseline y no
se alteraron.

## 4. Fuentes normativas y precedencia

La fuente vigente inspeccionada es
`docs/durable-retry-production-wiring-a10-normative-correction.md`, junto con las
auditorías/correcciones A5–A8 y el código versionado en HEAD.

A10 prevalece sobre especificaciones históricas. El mandato de esta auditoría
restringe, a su vez, el trabajo actual a composición pura y prohíbe completar por
inferencia la parte de hooks o wiring. Esa restricción es segura para auditar,
pero no reemplaza por sí sola la definición normativa de una API productiva.

## 5. Definición A9 encontrada en A10

A10 §14 y §15 establece:

- `Application::registerDurableRetryGraph(): void` privado como único
  composition root;
- registro como singletons de A2.1–A8 y del pipeline durable ya existente;
- `Application::run()` como activador de registrar inicial, cuatro callbacks,
  orquestación legacy y módulos restantes;
- `DurableRetryProductionHookRegistrar` como clase A9 dedicada;
- guardia global `private static bool $registered = false` para `run()`;
- restauración de la guardia y propagación si falla el registro de hooks.

Por tanto, la idempotencia normada es idempotencia global de activación de
hooks, no identidad idempotente de una composición pura expuesta al caller.

## 6. Incompatibilidad entre el A9 normativo y el A9 solicitado

| Tema | A10 vigente | Composición pura auditada |
|---|---|---|
| unidad A9 | registrar + bootstrap + guards | constructor/proveedor de grafo |
| efecto externo | registra hooks y modifica legacy | ninguno |
| acceso | método privado `void` | debe devolver/exponer A8 |
| idempotencia | estática global de `run()` | misma identidad por composición |
| fallo | rollback de registro de hooks | construcción atómica y reintentable |
| allowlist | 12 rutas de producto/tests | todavía no normada |

No es seguro tratar ambas columnas como equivalentes.

## 7. Identidad exacta de A9

**No está cerrada por la documentación vigente.**

A10 nombra exactamente un `DurableRetryProductionHookRegistrar`, pero esa clase
registra el action inicial y delega a A8; por definición no es composición pura.
Tampoco define `DurableRetryProductionGraph`, `...Composition`, `...Provider` o
un contrato equivalente.

La corrección normativa previa debe escoger exactamente una de estas formas:

1. mantener `Application` como composition root y añadir un accessor exacto del
   router A8; o
2. crear una clase de composición dedicada, con FQCN, constructor y método de
   acceso literales, que luego sea consumida por `Application` en un subhito de
   wiring distinto.

Esta auditoría no elige nombre, namespace ni firma entre esas alternativas.

## 8. Semántica mínima que debe fijar la corrección

La unidad elegida deberá garantizar:

- primera resolución: construye el grafo completo y devuelve A8;
- resoluciones posteriores sobre la misma instancia: devuelven el mismo objeto
  A8 mediante identidad estricta (`===`);
- dos instancias de composición: no se exige compartir identidad global;
- construcción perezosa o eager: debe escogerse una sola estrategia;
- ninguna llamada a `routeReconciliation()` durante composición;
- ningún `add_action`, `do_action`, Action Scheduler o acceso SQL;
- ninguna instancia parcialmente publicable;
- ante `Throwable`, no cachear éxito ni objeto parcial, propagar y permitir un
  nuevo intento posterior sobre la misma instancia;
- ausencia de fallback a legacy durante un fallo de composición.

La guardia estática de `Application::run()` no satisface estos puntos: gobierna
activación global, no identidad del objeto A8.

## 9. Grafo A5–A8 reconstruido desde los constructores

El extremo público funcional es:

```text
DurableRetryInitialProductionRouter (A8)
├── DurableRetryInitialAuthorityProducer (A5)
│   ├── DurableRetryLegacyAuthorityRepository (A3)
│   ├── DurableRetryDeterministicActivationPolicy (A2)
│   │   └── DurableRetryProductionActivationConfigurationSource (A2.1)
│   │       └── DurableRetryActivationConfigurationValueReaderInterface
│   └── DurableRetryInitialTransferAuthority (A5)
│       └── DurableRetryInitialTransferRepository (A4)
├── DurableRetryInitialScheduleResolver (A6)
│   └── DurableRetryScheduleRepositoryInterface
├── DurableRetryInitialScheduleCoordinator (A7)
│   └── DurableRetryExternalScheduleCoordinatorInterface
│       ├── DurableRetryScheduleRepositoryInterface
│       └── DurableRetryExternalSchedulerInterface
└── DurableRetryLegacySchedulerInterface
```

Los repositorios A3 y A4 requieren una instancia explícita de `wpdb`. El
repositorio durable, el coordinator externo y el adapter de Action Scheduler ya
forman parte del composition root existente y deben reutilizarse por identidad,
no duplicarse.

## 10. Dependencias compartidas e identidades obligatorias

La composición corregida debe declarar como mínimo:

- una misma instancia de `DurableRetryScheduleRepositoryInterface` para A6 y el
  coordinator externo consumido por A7;
- una misma instancia del coordinator externo usada por el pipeline durable y
  A7, salvo que una norma posterior autorice lo contrario;
- una sola fuente de configuración y una sola policy dentro del grafo A5–A8;
- un solo repositorio A3, un solo repositorio A4 y una sola autoridad A5 por
  instancia de composición;
- una sola implementación del puerto legacy entregada al router A8;
- un solo router A8 publicado por instancia.

Duplicar repositorio durable/coordinator rompe la expectativa de singleton ya
establecida en `Application`; duplicar objetos stateless no rompe datos, pero sí
haría no verificable el contrato de identidad pedido.

## 11. Brecha productiva del puerto legacy

El puerto A8 existe con firma exacta:

```php
public function scheduleReconciliation(int $reconciliationId): bool;
```

No existe implementación productiva del puerto. El legacy
`DurableCompletionScheduler` ofrece `reconciliation(int): void`, no implementa
la interfaz y su método privado de scheduling tampoco informa éxito.

La corrección A8 asigna expresamente esa adaptación a A9. A10 autoriza modificar
`DurableCompletionScheduler`, pero dentro de un A9 que también activa hooks y
guards. Una composición pura no puede:

- inyectar directamente el scheduler actual por incompatibilidad de tipo;
- inventar un adapter no incluido en la allowlist;
- usar un double en producto;
- omitir la dependencia, porque el constructor A8 la exige.

Ésta es una ambigüedad resoluble mediante allowlist normativa, no una razón para
alterar A8.

## 12. Frontera exacta entre composición y wiring

Composición puede:

- registrar factories/singletons en un contenedor;
- resolver dependencias y devolver A8;
- validar tipos e identidad estructural;
- propagar errores de construcción.

Composición no puede:

- llamar `Application::run()`;
- registrar `DurableRetryProductionHookRegistrar`;
- ejecutar `add_action`, `do_action` o callbacks;
- modificar/invocar `WebpayReconciliationMaterializer`;
- invocar `routeReconciliation()`;
- ejecutar `scheduleReconciliation()` ni Action Scheduler;
- instalar guards de scheduler, workers, recovery u orchestration;
- completar negocio con `DurableRetryBusinessCompletionProcessor`.

El processor de business completion pertenece al pipeline de consumo durable y
no constituye A9 ni prueba de composición inicial A5–A8.

## 13. Punto de acceso productivo

El `Application` actual expone `container()`, `durableRetryExecutor()` y
`durableRetryCallback()`. No expone A8. Su `registerDurableRetryGraph()` actual
es privado, retorna `void` y compone solo el pipeline durable preexistente; en
HEAD no contiene bindings A5–A8.

Usar `container()->make(DurableRetryInitialProductionRouter::class)` no es una
API válida hoy: la autoconstrucción llega a interfaces sin bindings y falla.
Además, exponer el contenedor como service locator no cierra qué identidades son
normativas.

La corrección debe definir un único acceso literal y documentar si queda en
`Application` o en una nueva clase. No se autoriza inferirlo del accessor del
executor/callback.

## 14. Atomicidad y errores de construcción

La especificación implementable deberá ordenar bindings antes de publicación y
establecer esta conducta:

1. preparar todas las factories sin ejecutar lógica funcional;
2. resolver A8 dentro de un bloque de construcción;
3. comprobar que el resultado es del FQCN exacto;
4. publicar/cachear solo después del éxito completo;
5. ante cualquier `Throwable`, descartar la referencia local, no marcar la
   composición como completa y propagar el mismo error;
6. permitir reintento; si el reintento tiene dependencias válidas, devolver una
   instancia completa y estable.

Si se usa `Container::singleton`, debe probarse su comportamiento real: cachea
solo después de que la factory retorna, por lo que una factory que lanza no deja
la clave en `instances`. Esto es compatible con reintento.

## 15. Efectos operacionales permitidos al componer

Presupuesto objetivo exacto durante construcción/resolución de A8:

| Efecto | Máximo |
|---|---:|
| consultas SQL | 0 |
| INSERT/UPDATE/DELETE/transacciones | 0 |
| lecturas de configuración | 0 |
| `add_action` / `do_action` | 0 / 0 |
| llamadas Action Scheduler | 0 |
| scheduling legacy | 0 |
| invocaciones A5–A8 funcionales | 0 |
| logs / métricas | 0 |

Los constructores inspeccionados solo retienen dependencias o calculan nombres
de tabla. Las lecturas SQL/configuración y scheduling aparecen en métodos de
operación, por lo que el presupuesto cero es estructuralmente alcanzable.

## 16. Patrones idempotentes existentes

El patrón válido ya disponible es `Container::singleton`: mantiene una
instancia por abstract dentro de un `Container` y no la asigna si la factory
lanza. Los accessors `durableRetryExecutor()` y `durableRetryCallback()` muestran
un precedente de acceso tipado a singletons.

El patrón de A10 `Application::$registered` es pertinente al wiring global, pero
no debe reutilizarse para convertir el grafo en singleton estático entre dos
`Application`: mezclaría ciclo de vida de objetos con deduplicación de hooks y
dificultaría tests/reintentos.

## 17. Allowlist A9 vigente

A10 §26 permite crear:

- `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionHookRegistrar.php`
- `tests/manual/durable-retry-production-hook-registrar-test.php`
- `tests/manual/durable-retry-production-hook-registrar-infrastructure-test.php`
- `tests/manual/durable-retry-production-wiring-integration-test.php`
- `tests/manual/durable-retry-legacy-exclusion-integration-test.php`
- `tests/manual/durable-retry-bootstrap-idempotency-test.php`

Y modificar:

- `app/Core/Application.php`
- `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionRecovery.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php`

Esta allowlist es coherente con A9 integrado, pero excesiva y semánticamente
incorrecta para un subhito de composición pura.

## 18. Allowlist propuesta para una corrección A9-C

La norma previa debería publicar una allowlist cerrada con categorías, no rutas
opcionales:

- **producto de composición:** exactamente `app/Core/Application.php` o
  exactamente una nueva clase de composición con FQCN normado, nunca ambos sin
  justificar responsabilidades;
- **adaptación legacy:** `DurableCompletionScheduler.php` si implementará el
  puerto directamente, o una ruta nueva exacta de adapter si se decide preservar
  el scheduler; nunca las dos alternativas abiertas;
- **harness funcional:** una ruta exacta para identidad, reintento y grafo;
- **harness infraestructura:** una ruta exacta para factories, tipos y cero
  efectos;
- **harness integración de composición:** una ruta exacta para dependencias
  reales sin `run()` ni hooks;
- **documento eventual:** esta auditoría solo si la política del subhito permite
  actualizarla; A10 actual prohíbe documentos durante implementación.

Debe excluir expresamente materializer, hook registrar, workers, recovery,
orchestration, schema, migraciones y todos los archivos A1–A8. La propuesta no
es autorización de cambio hasta que se reemplacen las alternativas por rutas
literales.

## 19. Harness funcional requerido

Mínimo recomendado: 12 casos, todos sin WordPress real.

1. primera resolución devuelve A8;
2. segunda resolución devuelve el mismo A8 por `===`;
3. diez resoluciones conservan identidad;
4. dos composiciones separadas no comparten A8;
5. A8 recibe exactamente cuatro dependencias;
6. A5 comparte la policy esperada;
7. A6 comparte repositorio con coordinator externo;
8. A7 comparte coordinator externo;
9. puerto legacy tiene implementación productiva válida;
10. fallo de factory se propaga;
11. fallo no publica/cachea A8 parcial;
12. reintento posterior exitoso estabiliza identidad.

No debe ejecutar `routeReconciliation()` ni afirmar estados de negocio A8.

## 20. Harness de infraestructura requerido

Debe usar spies que fallen al detectar cualquier operación y demostrar:

- `0` SQL y `0` transacciones;
- `0` lecturas de opciones/configuración;
- `0` llamadas al adapter externo;
- `0` llamadas al scheduler legacy;
- `0` hooks registrados;
- `0` callbacks ejecutados;
- tipos concretos/puertos correctos de todo el grafo;
- una sola creación por singleton;
- journal vacío de efectos operacionales;
- limpieza en `finally`, cero diagnostics y salida no cero en primera desviación.

## 21. Harness de integración requerido

Debe arrancar el composition root corregido con `wpdb` controlado y funciones de
WordPress/Action Scheduler que lancen si son llamadas. Debe resolver A8 dos veces
y comprobar identidad, sin llamar `run()`.

La integración no debe usar el materializador ni disparar el action inicial. Su
objetivo es demostrar que las implementaciones reales encajan en constructores,
no certificar routing, persistencia o scheduling ya cubiertos por A5–A8.

## 22. Guardas estructurales requeridas

Cada harness A9-C deberá abortar si detecta:

- cambios Git fuera de la allowlist literal;
- aparición de `add_action`, `do_action`, `as_schedule_*` o invocaciones a
  `routeReconciliation` en la unidad de composición;
- modificación de A1–A8, schema o migraciones;
- dependencia del materializador, workers, recovery u orchestration;
- una segunda implementación productiva no autorizada del puerto legacy;
- service locator sin accessor tipado cuando la norma elija accessor;
- guardia estática usada para identidad del grafo;
- captura silenciosa de errores o fallback legacy;
- SQL/configuración durante composición;
- staging contaminado, diagnostics o temporales.

## 23. Criterios de aceptación completos

A9-C será implementable solo cuando una corrección normativa cierre todos estos
ítems sin alternativas:

- FQCN y ruta exactos de la unidad de composición;
- constructor y método público exactos;
- tipo exacto devuelto/expuesto;
- lifecycle e identidad por instancia;
- eager versus lazy;
- adaptación exacta del puerto legacy;
- reuse de repositorio/coordinator existentes;
- atomicidad, propagación y reintento;
- presupuesto de efectos cero;
- allowlist literal de producto, tres harnesses y documento;
- frontera explícita con A9-W (hooks/wiring/guards);
- orden posterior: A9-C certificado antes de A9-W.

## 24. Corrección documental mínima necesaria

Antes de implementar debe emitirse una corrección A10 que:

1. divida A9 en al menos `A9-C composición` y `A9-W wiring/exclusión`;
2. mantenga `DurableRetryProductionHookRegistrar` exclusivamente en A9-W;
3. asigne a A9-C un FQCN/API exactos para obtener A8;
4. defina si el scheduler implementa el puerto o existe un adapter dedicado;
5. establezca identidad por instancia y reintento tras error;
6. publique allowlists literales separadas;
7. reasigne los harnesses: identidad/grafo a A9-C y doble `run`, dos
   `Application`, contextos WordPress y excepción de hooks a A9-W;
8. preserve los límites cero SQL/hooks/scheduling para A9-C.

No hace falta rediseñar A5–A8 ni el `Container` para resolver el bloqueo.

## 25. Riesgos si se implementa sin corrección

- elegir arbitrariamente una clase/API que A10 no reconoce;
- confundir misma identidad A8 con deduplicación global de hooks;
- activar `run()` solo para poder obtener el router;
- introducir un adapter legacy fuera de allowlist;
- duplicar repositorio/coordinator y perder identidad compartida;
- cachear composición parcial tras un error;
- ampliar el hito hacia materializer, guards o scheduling;
- certificar el processor de business completion como supuesto A9.

Todos son evitables deteniendo implementación hasta corregir la norma.

## 26. Bloqueadores exactos

| ID | Bloqueador | Evidencia | Resolución |
|---|---|---|---|
| B1 | no existe identidad/API A9 de composición pura | A10 solo nombra registrar y método privado `void` | definir FQCN/ruta/firma/accessor |
| B2 | idempotencia pedida no está normada | A10 regula `Application::$registered` para hooks | definir identidad por instancia de A8 |
| B3 | puerto legacy sin implementación productiva | interfaz `bool`; scheduler actual `void` | escoger implementación o adapter literal |
| B4 | allowlist mezcla composición con activación | A10 §26 incluye materializer/hooks/legacy completo | separar A9-C y A9-W |
| B5 | harnesses actuales prueban wiring, no composición pura | A10 §27 exige contextos WordPress/doble `run` | añadir harnesses A9-C exactos |

## 27. Evidencia negativa de esta auditoría

- código productivo modificado: `0` archivos;
- wiring activado: no;
- hooks/callbacks registrados: `0`;
- scheduling/Action Scheduler ejecutado: `0`;
- SQL ejecutado por la auditoría: `0`;
- A1–A8 modificados: no;
- materializador modificado: no;
- processor de business completion modificado/invocado: no;
- artifacts baseline eliminados o alterados: no;
- commit: no;
- push: no.

## 28. Veredicto final

**A9 BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

La arquitectura permite una composición idempotente, atómica y sin efectos,
pero A10 no separa esa unidad del wiring ni determina su API, lifecycle,
adaptación legacy y allowlist. Implementar ahora exigiría decisiones creativas
fuera de mandato. La siguiente acción válida es únicamente una corrección
normativa con los ocho cierres de §24; después podrá auditarse nuevamente A9-C y
recién entonces implementarse.
