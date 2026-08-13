# Auditoría final de readiness A10 — wiring productivo de reconciliation

## 1. Veredicto

**A10 WIRING PRODUCTIVO BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

A9 está implementado, versionado y estructuralmente apto para entregar un único
router A8. Sin embargo, A10 no puede implementarse todavía sin elegir
creativamente entre dos modelos incompatibles de wiring:

1. el modelo A10 vigente, donde el materializer emite un action escalar y un
   registrar obtiene/invoca A8; y
2. el modelo exigido para esta auditoría, donde la invocación de A8 ocurre dentro
   de `WebpayReconciliationMaterializer::publishRetryAuthorityCandidate()` y el
   materializer recibe A9 o A8 por constructor.

La discrepancia afecta constructor, ownership, errores, resultado A8, allowlist
y pruebas. No es una incompatibilidad estructural: los componentes existentes
permiten ambos modelos. Es una ambigüedad normativa que debe resolverse antes de
editar producto.

## 2. Alcance y ausencia de implementación

Esta auditoría determina únicamente readiness del wiring restante. No:

- modifica producto o pruebas;
- registra hooks o callbacks;
- invoca A8;
- ejecuta scheduling o SQL;
- altera materializer, bootstrap, A1–A9, schema o migraciones;
- realiza commit o push.

## 3. Baseline certificado

| Control | Resultado |
|---|---|
| rama | `main` |
| HEAD | `ed033c0fdbf7ad6066b9ba4950b0dfcbc104cb8e` |
| parent | `bbe726a96cbf94cbb535c8d73b57a947a6bb615a` |
| divergencia inicial | `0 behind / 55 ahead` |
| staging inicial | vacío |
| cambios tracked iniciales | `0` |
| suite base | `71/71`, `5.958` assertions |
| diagnostics | `0` |
| artefactos | `504` |
| push | no realizado |

## 4. Autoridades inspeccionadas

Se conciliaron:

- `docs/durable-retry-production-wiring-a10-normative-correction.md`;
- auditoría y corrección normativa A9;
- implementación y tres harnesses A9;
- contratos, servicios y resultados A5–A8;
- `WebpayReconciliationMaterializer`;
- `WebpayReturnService` y `WebpayReturnRecovery`;
- `Application`, `Container` y composition root durable actual;
- `DurableCompletionScheduler`, workers, recovery y orchestration legacy;
- `DurableRetryActionHookRegistrar`, callback, executor, coordinator, adapter y
  catálogo externo;
- callers y harnesses históricos de payment return, materialización,
  reconciliation y scheduling.

## 5. Estado efectivo A9

A9 es la clase final:

`VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition`.

Su única API de acceso es:

```php
public function router(): DurableRetryInitialProductionRouter
```

Mantiene identidad `===` por instancia, compone perezosamente, publica solo al
terminar, limpia tras fallo y no produce efectos durante composición. No está
registrado todavía en `Application` ni es construido por código productivo.

## 6. Estado efectivo del materializer

Constructor actual exacto:

```php
public function __construct(
    private readonly ValidatedFinancialResultRepository $financialResults = new ValidatedFinancialResultRepository(),
    private readonly PaymentReconciliationRepository $reconciliations = new PaymentReconciliationRepository()
)
```

No recibe A9 ni A8. No existe `publishRetryAuthorityCandidate()` en HEAD.

`materialize()` y `resume()` crean un `MaterializedReconciliation` y ejecutan
directamente:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

en líneas productivas independientes. Esta es hoy la única publicación inicial
desde el materializer y debe desaparecer o quedar inaccesible cuando A8 sea la
autoridad única.

## 7. Callers reales del materializer

La construcción no está centralizada:

- `WebpayReturnService` acepta materializer nullable y crea uno por defecto en
  `repeated()` y `finalize()`;
- `WebpayReturnRecovery` tiene un parámetro promovido con
  `new WebpayReconciliationMaterializer()` por defecto;
- `Application::run()` construye `new WebpayReturnRecovery()` directamente;
- varios harnesses construyen materializer y return service directamente;
- el contenedor puede autoconstruir `WebpayReturnService`, pero no tiene binding
  productivo explícito para materializer.

Por tanto, añadir una dependencia obligatoria al materializer afecta más que su
archivo y exige cerrar los callers productivos y de prueba.

## 8. Composition root actual

`Application::__construct()` crea un `Container` y llama al método privado
`registerDurableRetryGraph()`.

El método ya registra como singletons repositorio durable, adapter Action
Scheduler, coordinator externo, registry, executor, callback y registrar de
cuatro hooks. No registra:

- reader de configuración A2.1;
- scheduler legacy como puerto;
- `DurableRetryProductionComposition` A9;
- router A8;
- materializer;
- registrar del action inicial.

`Application` es el único punto de vida superior existente capaz de conservar
una instancia A9 por instancia de aplicación, pero la corrección A9 dejó la
integración exacta para el wiring posterior.

## 9. Frontera 1 — obtención del router

La única obtención permitida está cerrada:

```php
$composition->router()
```

Quedan prohibidos `new DurableRetryInitialProductionRouter`, reconstrucción de
A2–A8, container público y resolución dinámica fuera de A9.

Lo que no está cerrado es quién conserva `$composition` y quién llama
`router()`: A10 original atribuye esa responsabilidad al registrar inicial; el
mandato de esta auditoría la coloca potencialmente en el materializer.

## 10. Frontera 2 — invocación del router

A10 §6, §16 y §17 prescribe:

```text
materializer
→ publishRetryAuthorityCandidate(int, string): void
→ do_action('veciahorra_durable_retry_initial_reconciliation', id, utcString)
→ DurableRetryProductionHookRegistrar
→ A8
```

El mandato actual exige que `routeReconciliation()` ocurra exclusivamente
dentro de `publishRetryAuthorityCandidate()` y solicita escoger una dependencia
A9/A8 para el constructor del materializer. Eso describe:

```text
materializer(A8 o A9)
→ publishRetryAuthorityCandidate(...)
→ routeReconciliation(...)
```

No pueden implementarse simultáneamente: hacerlo llamaría A8 dos veces o dejaría
un hook inicial redundante.

## 11. Frontera 3 — callback durable

El callback de ejecución durable ya existe y es distinto del action inicial:

- hook reconciliation: `veciahorra_durable_retry_reconciliation`;
- grupo: `veciahorra-durable-retry`;
- registrar: `DurableRetryActionHookRegistrar`;
- prioridad: `10`;
- argumentos WordPress: `2` (`schedule_id`, `generation`);
- closure del registrar añade el hook cerrado y llama
  `DurableRetryActionCallback::execute(hook, scheduleId, generation)`;
- el método callback recibe exactamente tres argumentos y delega una vez al
  executor tras normalizar el payload.

El registrar recorre cuatro hooks durables, no solo reconciliation. Esta parte
ya está compuesta y llamada desde `Application::run()`; no debe confundirse con
el action inicial ni reimplementarse en el materializer.

## 12. Idempotencia del registro durable existente

`DurableRetryActionHookRegistrar` tiene guardia booleana por instancia. Dos
llamadas a `register()` sobre el mismo objeto son idempotentes.

`Application` conserva el registrar como singleton por contenedor, pero
`Application::run()` no posee la guardia estática global exigida por A10 §15.
Dos instancias `Application` pueden registrar nuevamente los cuatro callbacks y
la orchestration legacy.

La corrección A9 separó composición de wiring, pero no reemplazó esta regla. La
idempotencia global del bootstrap sigue siendo trabajo A10 y debe normarse sin
atribuirla a A9.

## 13. Punto productivo actual y momento exacto

En ambos caminos no nulos del materializer la secuencia actual es:

1. validar/obtener evidencia financiera;
2. crear o converger la reconciliation;
3. obtener un ID estable de la fila creada o almacenada;
4. construir `MaterializedReconciliation` en memoria;
5. llamar scheduler legacy;
6. retornar el DTO.

El reemplazo normativo ocuparía exactamente el paso 5: inmediatamente después
del constructor `MaterializedReconciliation` y antes de su `return`.

No hay `START TRANSACTION`, `COMMIT`, `ROLLBACK` ni locks explícitos en el
materializer. Los repositorios completan sus operaciones antes de retornar el ID.
Así, el punto es post-persistencia de la reconciliation y no mantiene una
transacción explícita del materializer.

## 14. Frontera transaccional del caller

En `WebpayReturnService::finalize()`, el repositorio de returns ejecuta
`complete()` antes de invocar el materializer. Por tanto, el resultado funcional
principal ya se intenta confirmar antes del routing.

En `repeated()` y recovery, se trabaja sobre evidencia previamente persistida.
El routing es una obligación post-persistencia y no debe revertir pago, return o
reconciliation funcional.

No obstante, A10 afirma que una excepción del callback inicial no se propaga
porque A8 cierra dependencias. Esa afirmación no cubre una excepción al obtener
A9/A8 ni una reentrada/falla de composición antes de entrar a A8. La política
exacta para esa excepción sigue sin definirse.

## 15. Identidad de entrada A8

La firma certificada es:

```php
routeReconciliation(
    int $reconciliationId,
    DateTimeImmutable $scheduledForUtc
): DurableRetryInitialProductionRoutingResult
```

Origen correcto del ID:

- retorno positivo de `PaymentReconciliationRepository::create()`; o
- `PaymentReconciliation::id()` de la fila convergida por fingerprint.

No son válidos return ID, payment ID, order ID, origin ID o completion de otra
etapa. No se necesita ni permite una consulta adicional para recalcularlo.

## 16. Fuente del instante

A10 fija que el materializer capture una vez:

```php
gmdate('Y-m-d H:i:s')
```

Eso produce un string UTC, precisión de segundos y microsegundos implícitamente
cero. El registrar inicial normado parsearía exactamente con formato
`!Y-m-d H:i:s` y timezone UTC para crear `DateTimeImmutable`.

Si se adopta invocación directa desde el materializer, falta normar quién hace
ese parse y qué ocurre ante fallo. No puede reutilizarse sin más `$now` actual:
`materialize()` usa `current_time('mysql', true)`, pero `resume()` solo lo define
en la rama que crea una reconciliation nueva. La captura separada con `gmdate`
es la única fuente uniforme documentada.

## 17. Reejecución de materialize/resume

Una segunda publicación puede recuperar la misma reconciliation por fingerprint
y volver a alcanzar el punto inicial. El punto de wiring debe invocar A8 una vez
por publicación, no intentar deduplicar en memoria por reconciliation ID.

La convergencia persistente corresponde a:

- A3: autoridad durable/legacy/indeterminada;
- A4: creación o convergencia de generation 1;
- A6: resolución de schedule existente;
- A7: asociación/verificación de action existente;
- scheduler legacy: deduplicación de action pendiente.

A9 solo deduplica construcción por instancia. Reinicios de PHP, dos requests y
concurrencia no comparten A9; convergen mediante A3–A7 y providers externos.

## 18. Exclusión legacy actual

Las dos llamadas directas del materializer construyen un scheduler legacy antes
de cualquier decisión A5/A8. Si se añade A8 sin retirarlas, se producen estos
riesgos:

- legacy antes de durable;
- legacy antes y dentro de la rama `legacy_allowed`;
- doble action legacy;
- durable y legacy simultáneos;
- fallback implícito ante cierres A8.

La regla correcta es una sola: eliminar ambas llamadas directas y permitir que
solo A8 invoque `DurableRetryLegacySchedulerInterface`, máximo una vez, cuando
A5 retorna `legacy_allowed`. Todos los estados durable, cerrados o fallidos
producen cero legacy nuevo.

## 19. Guards legacy restantes

A10 también exige guards A3 en scheduler, workers y recovery legacy. HEAD no los
contiene:

- scheduler solo deduplica por Action Scheduler;
- workers procesan/reintentan sin clasificar A3;
- recovery reprograma sin clasificar A3;
- orchestration registra todos los hooks y recovery sin una guardia global.

Esta exclusión va más allá de sustituir las dos llamadas del materializer. A10
describe la política, pero no fija constructores exactos, ownership compartido
de A3 ni tratamiento de identidades batch para cada clase tras la separación
A9/A10. Implementarla ahora requeriría diseño adicional.

## 20. Catálogo A8 de once estados

| Estado | Clasificación certificada | Legacy nuevo | Resultado de wiring normado hoy |
|---|---|---:|---|
| `legacy_scheduled` | legacy confirmado | 1 | sin política de consumo cerrada |
| `legacy_unavailable` | legacy autorizado, no confirmado | 0 | sin política de consumo cerrada |
| `durable_synchronized` | durable confirmado | 0 | sin política de consumo cerrada |
| `durable_already_synchronized` | durable ya confirmado | 0 | sin política de consumo cerrada |
| `durable_external_unavailable` | durable, proveedor ausente | 0 | sin política de consumo cerrada |
| `durable_coordination_failed` | durable, fallo conocido | 0 | sin política de consumo cerrada |
| `durable_coordination_uncertain` | durable, intervención | 0 | sin política de consumo cerrada |
| `authority_closed` | cierre A5 | 0 | sin política de consumo cerrada |
| `resolution_failed` | cierre A6 | 0 | sin política de consumo cerrada |
| `invalid_input` | entrada inválida | 0 | sin política de consumo cerrada |
| `dependency_failure` | dependencia fallida | 0 | sin política de consumo cerrada |

A10 define significado, persistencia y scheduling, pero el registrar normado
retorna `void` y `do_action()` no entrega el resultado al materializer. No define
un `match` exhaustivo, persistencia del resultado, excepción, observación ni
notificación al caller. Declarar simplemente “retornar normalmente” para los
once sería ignorarlos indiscriminadamente, expresamente prohibido por el mandato.

## 21. Política que debe cerrar una corrección

La norma posterior debe escoger una política exhaustiva. Como mínimo debe fijar
para cada estado:

- owner del resultado (materializer o registrar);
- retorno normal o excepción post-commit;
- si el caller conoce el resultado;
- observabilidad permitida;
- si `requiresIntervention` genera una obligación externa;
- garantía de cero mutación funcional adicional;
- garantía de cero fallback.

No se autoriza inventar esa política en la implementación.

## 22. Errores de composición y routing

A8 captura excepciones de A5, A6, A7 y legacy y retorna
`dependency_failure`. Eso no cubre:

- error al construir hojas que el wiring entrega a A9;
- `Throwable` de `DurableRetryProductionComposition::router()`;
- reentrada A9;
- error al registrar el action inicial;
- error del mecanismo de observabilidad futuro.

Como el punto es post-persistencia, ninguna de esas fallas puede desconfirmar el
pago o borrar reconciliation. Falta definir si se propagan al caller, se
convierten en una obligación de recovery o se capturan por una frontera nueva.
Capturarlas silenciosamente o activar legacy está prohibido.

## 23. Callback durable y presupuesto existente

El callback durable valida hook cerrado, schedule ID y generation positivos,
después delega una vez al executor. El executor conserva sus presupuestos
certificados de lectura, claim CAS, processor y persistencia.

El wiring inicial no debe:

- registrar un callback alternativo;
- pasar reconciliation ID donde se espera schedule ID;
- incluir hook en el payload Action Scheduler;
- duplicar claims, lecturas o processors;
- llamar executor directamente desde materializer.

La integración callback→executor puede certificarse en un microhito separado o
reutilizar los harnesses existentes, pero no debe mezclarse con el callback
inicial A8.

## 24. Presupuesto de composición A9

Por constructor y llamadas a `router()`:

| Operación | Máximo |
|---|---:|
| SQL | 0 |
| hooks | 0 |
| scheduling | 0 |
| lecturas de configuración | 0 |
| llamadas funcionales A5–A8 | 0 |

Este presupuesto está implementado y no puede relajarse desde A10.

## 25. Presupuesto de una publicación A8

| Operación | Máximo |
|---|---:|
| llamadas al router | 1 |
| A5 | 1 |
| A6 | 1, solo rama durable continuable |
| A7 | 1, solo resolución continuable |
| scheduler legacy | 1, solo `legacy_allowed` |
| fallback | 0 |
| loops/retries locales | 0 |
| llamada legacy directa materializer | 0 |

A3/A2/A4 se alcanzan únicamente a través de A5. Adapter externo se alcanza
únicamente a través de A7/coordinator.

## 26. Observabilidad

No existe una autoridad productiva de logging A10. A10 dice que A8/A9 no crean
logs ni métricas. Por tanto, la implementación inicial no puede registrar estado,
ID, schedule, generation o reason hasta que una corrección defina canal, nivel,
redacción y presupuesto.

Queda prohibido incluir tokens, token hash, payload financiero, authorization
code u otros datos sensibles. La observabilidad nunca puede condicionar routing,
remapear estados o introducir hooks.

## 27. Modelo de wiring recomendado, no autorizado

La opción más fiel al A10 versionado es conservar el evento escalar:

1. `Application` conserva A9 como singleton;
2. un `DurableRetryProductionHookRegistrar` recibe A9, obtiene el mismo A8 y
   registra una vez el action inicial;
3. el materializer captura `gmdate`, emite exactamente un action y elimina el
   scheduler directo;
4. el registrar parsea UTC e invoca A8 una vez;
5. el registrar consume exhaustivamente el resultado según una política aún por
   corregir;
6. guards legacy se integran con A3 sin reevaluar A2.

Esta recomendación no resuelve por sí misma la contradicción con el requisito de
invocación directa dentro de `publishRetryAuthorityCandidate()` y no constituye
autorización de implementación.

## 28. Dependencia del materializer no cerrada

Si prevalece A10 evento, el materializer no recibe A9 ni A8; solo sus dos
repositorios actuales.

Si prevalece el mandato directo, la dependencia mínima correcta sería
`DurableRetryInitialProductionRouter`, no la composición, porque el materializer
no debe conocer el grafo. Eso exige:

- tercer parámetro obligatorio del constructor;
- eliminar defaults/fallbacks productivos que construyen materializer sin A8;
- binding singleton del router obtenido una sola vez desde A9;
- actualizar callers y harnesses.

La documentación no escoge entre “ninguna dependencia” y “router concreto”.

## 29. Sitio de construcción A9 no cerrado

El candidato estructural es `Application::registerDurableRetryGraph()`, usando
los singletons existentes para scheduler externo/coordinator y hojas nuevas
explícitas. `Application` conservaría A9 como singleton por contenedor.

Pero faltan reglas literales para:

- binding/FQCN exacto de reader, legacy y reloj;
- si A9 reutiliza el mismo repositorio/coordinator del executor o construye su
  grafo interno conforme a su constructor certificado;
- cuándo se llama `router()`;
- cómo llega al registrar o materializer;
- qué pasa con `WebpayReturnRecovery` construido fuera del contenedor;
- guardia entre dos instancias `Application`.

No existe hoy un único sitio productivo de construcción A9.

## 30. Allowlist candidata cerrada

Si una corrección confirma el **modelo de evento A10**, la allowlist candidata
exacta es:

### Producto

1. `app/Core/Application.php` — singleton A9, registrar inicial y guardia global.
2. `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php` — sustituir dos llamadas legacy por publicación única.
3. `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionHookRegistrar.php` — validar payload, obtener A8 una vez e invocarlo.
4. `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php` — guard A3 del scheduling legacy.
5. `app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php` — guard A3 antes de procesar/reintentar.
6. `app/Modules/Fulfillment/Orchestration/DurableCompletionRecovery.php` — guard A3 antes de reprogramar.
7. `app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php` — inyección compartida y registro idempotente.

### Harnesses

8. `tests/manual/durable-retry-production-hook-registrar-test.php`
9. `tests/manual/durable-retry-production-hook-registrar-infrastructure-test.php`
10. `tests/manual/durable-retry-production-wiring-integration-test.php`
11. `tests/manual/durable-retry-legacy-exclusion-integration-test.php`
12. `tests/manual/durable-retry-bootstrap-idempotency-test.php`

La lista coincide con la allowlist A10 vigente y no autoriza cambios. Si se
elige inyección directa, esta allowlist es incorrecta: desaparecería el
registrar inicial y deberían añadirse callers/materializer harnesses. Esa
dependencia del modelo es precisamente un bloqueador.

## 31. Archivos prohibidos

En ambos modelos permanecen fuera:

- A1–A9 y sus resultados/contratos certificados;
- adapter externo, coordinator, executor, callback y processors;
- `Config`, schema y migraciones;
- documentos previos;
- `artifacts/`;
- repositorios/policies certificados.

No se permite una ruta “por si acaso”.

## 32. Matriz funcional mínima A10

| ID | Caso | Cierre esperado |
|---|---|---|
| A10-F-01 | bootstrap obtiene A8 real desde A9 | misma identidad `===` |
| A10-F-02 | dos obtenciones | una composición exitosa |
| A10-F-03 | publicación no nula | una llamada A8 |
| A10-F-04 | identidad | reconciliation ID positivo exacto |
| A10-F-05 | fecha | UTC canónica, microsegundos cero |
| A10-F-06 | `legacy_allowed` | una llamada legacy |
| A10-F-07 | `legacy_allowed` | cero A6/A7 |
| A10-F-08 | estados durable | cero legacy |
| A10-F-09 | durable continuable | A6/A7 según A8 |
| A10-F-10 | `resolution_failed` | cero fallback |
| A10-F-11 | `dependency_failure` | cero fallback |
| A10-F-12 | falla composición | cero wiring parcial |
| A10-F-13 | once resultados | consumo exhaustivo normado |
| A10-F-14 | legacy pending | cero duplicado |
| A10-F-15 | durable pending | convergencia A7 |
| A10-F-16 | segunda publicación | convergencia persistente |
| A10-F-17 | dos requests | una autoridad persistente |
| A10-F-18 | routing falla | reconciliación funcional permanece |
| A10-F-19 | catálogo A8 | once estados intactos |
| A10-F-20 | A9 | cero hooks propios |
| A10-F-21 | hook durable | un registro por proceso |
| A10-F-22 | callback durable | execute recibe tres argumentos |
| A10-F-23 | callback | una delegación executor |
| A10-F-24 | inventario | cero callback alternativo |
| A10-F-25 | legacy | cero llamada directa fuera A8/guards |
| A10-F-26 | grafo | cero reconstrucción A5–A8 |
| A10-F-27 | arquitectura | cero service locator |
| A10-F-28 | cierre | cero fallback |
| A10-F-29 | presupuesto | cero loop/retry/sleep local |
| A10-F-30 | regresión | suite histórica completa verde |

## 33. Harnesses y responsabilidades

- registrar funcional: payload válido/inválido, UTC, una llamada A8 y política
  exhaustiva de resultados;
- infraestructura: allowlist, hooks literales, prioridades, accepted args,
  ausencia de SQL/fallback/service locator;
- integración materializer→evento→A9→A8: dos ramas no nulas, una publicación,
  ID/fecha, legacy/durable y post-persistencia;
- integración callback→executor: cuatro hooks existentes, payload de dos campos,
  execute de tres argumentos y una delegación;
- exclusión legacy: scheduler/workers/recovery, durable e indeterminate cerrados;
- bootstrap: doble `run`, dos `Application`, excepción y cinco contextos;
- concurrencia: dos publicaciones convergen A3–A7 sin doble autoridad.

## 34. Guardias estructurales requeridas

Los harnesses deben abortar ante:

- `DurableCompletionScheduler()->reconciliation` en materializer;
- llamada legacy inicial fuera de A8;
- `new` A5–A8 fuera de A9;
- más de un `new DurableRetryProductionComposition` productivo;
- más de un registro del hook inicial;
- más de una llamada `routeReconciliation()` por callback/publicación;
- alias o remapeo de estados A8;
- `default`, fallback o `catch` silencioso sobre resultado A8;
- SQL, tabla, option o porcentaje nuevo;
- loops, retries, sleeps;
- service locator, Reflection o clase dinámica;
- cambio fuera de allowlist;
- modificación de callback/executor certificados;
- staging contaminado, temporales o diagnostics.

## 35. Idempotencia por nivel

| Nivel | Alcance | Autoridad |
|---|---|---|
| A9 | una instancia/proceso | identidad de objetos `===` |
| bootstrap | proceso PHP | guardia global aún pendiente |
| publicación | un retorno no nulo | máximo una invocación A8 |
| A3/A4 | identidad reconciliation/generation | autoridad persistente y convergencia |
| A6/A7 | schedule/action | lectura, pending único y CAS |
| legacy | hook/args/group | deduplicación provider + guard A3 pendiente |
| callback | action durable | payload cerrado, claim/executor idempotente |

No se puede sustituir idempotencia persistente por memoria A9.

## 36. Riesgos si se implementa ahora

- llamar A8 directamente y también mediante action;
- construir A9 por publicación;
- dejar callers con materializer sin router;
- ignorar los once resultados detrás de `void`;
- propagar fallo de composición hacia un pago ya confirmado;
- ejecutar legacy antes y después de A8;
- registrar callbacks dos veces con dos `Application`;
- aplicar guards con instancias A3 no compartidas o identidades incorrectas;
- ampliar allowlist para reparar callers descubiertos durante implementación.

## 37. Bloqueadores restantes

| ID | Bloqueador | Evidencia | Corrección requerida |
|---|---|---|---|
| B1 | modelo de invocación contradictorio | A10 usa `do_action`; mandato exige A8 dentro del método | escoger evento o llamada directa |
| B2 | dependencia materializer no definida | constructor actual solo repositorios | fijar ninguna/A8 y actualizar callers |
| B3 | sitio/lifecycle A9 no literal | A9 ausente de `Application` | binding, owner y momento exactos |
| B4 | consumo de 11 estados ausente | registrar y publisher retornan `void` | política exhaustiva por estado |
| B5 | error de composición post-persistencia abierto | A8 no cubre fallo previo a router | política de propagación/recovery |
| B6 | conversión UTC abierta en modelo directo | A10 fija string+registrar | fijar owner del parse DateTime |
| B7 | guardia bootstrap global ausente | registrar solo por instancia | regla implementable tras separación A9 |
| B8 | guards legacy sin firmas/lifecycle exactos | scheduler/workers/recovery no consultan A3 | constructores e identidades literales |

Bloqueadores restantes: **8**.

## 38. Corrección documental mínima necesaria

Antes de implementar debe versionarse una corrección A10 que:

1. elija un único modelo de invocación;
2. fije constructor final del materializer y todos los callers productivos;
3. fije bindings/factories y lifecycle A9 en `Application`;
4. fije captura y conversión UTC;
5. defina consumo exhaustivo de los once estados;
6. defina errores post-persistencia, incluida falla A9;
7. adapte la guardia global de bootstrap a la separación A9/A10;
8. cierre constructores/ownership de guards legacy;
9. publique una allowlist consistente con el modelo escogido;
10. asigne A10-F-01–A10-F-30 a harnesses literales.

## 39. Conclusión

A9 resolvió completamente la composición, pero no decide cómo el materializer,
el action inicial y el bootstrap reparten el wiring. A10 conserva información
suficiente sobre hook, payload, fecha y ramas, aunque entra en contradicción con
la frontera de invocación exigida y no define consumo de resultados ni fallas de
composición post-persistencia.

Implementar ahora obligaría a escoger arquitectura y política operacional. La
acción válida siguiente es una corrección normativa A10 limitada a los diez
puntos de §38.

## 40. Veredicto final

**A10 WIRING PRODUCTIVO BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**
