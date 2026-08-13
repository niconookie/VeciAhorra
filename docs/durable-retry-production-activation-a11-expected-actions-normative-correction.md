# Corrección normativa global A11 de `expected.actions`

## 1. Portada y veredicto

Este documento cierra el contrato global de `expected.actions` para los fixtures
A11. Es autoridad complementaria y más específica que las reservas y
alternativas anteriores de esta clave. Conserva la forma ya fijada para WR-06 y
la hace aplicable a los 31 casos y cinco ventanas de crash.

Veredicto: `A11 EXPECTED ACTIONS IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`.

## 2. Estado base

La corrección parte de `main`, HEAD
`847879d509864c6ad077bfecb6dfa05537fbb899`, divergencia `0 behind / 61 ahead`,
staging vacío y `git diff --check` verde. La regresión certificada es 78/78,
6.080 assertions, sin fallos ni timeouts. Runtime Capture permanece 30/30 y
37 assertions, 25/25 y 44 assertions, e integración 1/1 y 8 assertions. El
scheduler real permanece verde en tres ejecuciones de 14 assertions.

No existen fixture WR-06 materializado, IDs de casos asignados, manifest runtime,
`.a11-runtime`, procesos PHP residuales, commit ni push de esta ejecución.

## 3. Alcance

Se define una sola representación canónica de todas las invocaciones productivas
con efecto observables durante `first_delivery` y `replay`. Se cierran definición,
catálogo, fases, orden, conteo, externalidad, snapshots, errores, proyección y
requisitos de implementación futura.

Esta corrección no materializa fixtures, no asigna valores, no ejecuta WR-06 y no
cambia producto, coordinador, contrato Runtime Capture ni harnesses.

## 4. Exclusiones del alcance

Quedan fuera los valores concretos de cada caso, `expected.rows`,
`expected.result`, `expected.mutations`, `fixture_ids`, payloads, secretos,
identidades runtime y presupuestos particulares. Esta corrección no convierte
una observación histórica accidental en contrato.

## 5. Autoridades

Se inspeccionaron la corrección principal A11, la complementaria, bootstrap,
contrato de fixtures, payload Webpay, auditoría y corrección WR-06 de actions,
Runtime Capture Transport, R2, R4, coordinator, capture contract, sus harnesses,
puertos productivos Durable Retry/Webpay/WooCommerce/Action Scheduler y los
harnesses históricos de scheduling, persistencia, replay y cleanup.

La precedencia es: esta corrección para shape y semántica global; correcciones
específicas para hechos del caso; contratos productivos para el punto observable;
evidencia de harness solo para certificar. Un double nunca crea autoridad.

## 6. Bloqueo vigente

El bloqueo antecedente `A11-WR-06 CONTINÚA BLOQUEADO POR EXPECTED ACTIONS
INDETERMINADO` se resuelve para definición, shape, catálogo, fases, conteo y
proyección. No resuelve conteos concretos de WR-06 ni otros campos pendientes.

Las alternativas anteriores “enteros no negativos o catálogos” quedan
reemplazadas por la única forma de §11.

## 7. Definición de acción

Una action es una invocación lógica nueva de un puerto productivo incluido en el
catálogo cerrado de §13, iniciada por VeciAhorra durante `first_delivery` o
`replay`, cuya delegación puede producir un efecto funcional o cruzar una frontera
productiva, y cuya ocurrencia puede observarse directamente en el límite del
puerto sin inferirla desde filas, estados o timing.

Cuenta la delegación aceptada por el puerto. No cuenta la intención anterior a
delegar, el resultado retornado, una mutación interna ni la evidencia posterior.

## 8. Catálogo de inclusiones

Califican exclusivamente estas invocaciones lógicas:

| Nombre canónico | Punto observable | Incremento |
|---|---|---|
| `webpay.commit` | `WebpayReturnGatewayInterface::commit()` | al delegar una vez al gateway |
| `woocommerce.payment_complete` | `PaymentCompletionHandlerInterface::complete()` | al delegar al handler |
| `scheduler.action_schedule` | `DurableRetryExternalSchedulerInterface::schedule()` | al delegar programación nueva |
| `scheduler.action_cancel` | cancelación por el puerto scheduler | al delegar cancelación |
| `legacy.retry_schedule` | `DurableRetryLegacySchedulerInterface` | al delegar scheduling legacy |
| `durable.worker_execute` | `DurableRetryExecutorInterface::execute()` desde callback | al aceptar ejecución del worker |

El catálogo es global, cerrado y tiene exactamente seis claves. Un caso declara
las seis en ambas fases, aunque sus valores sean cero.

## 9. Catálogo de exclusiones

No califican: lecturas o escrituras SQL individuales; begin, commit o rollback de
base de datos; filas afectadas; duplicate key; asociación SQL del action ID;
lecturas A3/A6; resultados A4–A8; resolución local; creación de DTOs; hooks
privados; procesamiento de etapa interno; persistencia Webpay como operación SQL;
creación o actualización de entidades; fingerprint; hashes; serialización;
assertions; captura y resolución de aliases; sellado de snapshots; stdin/stdout;
stderr; bootstrap; autoload; GC; logs; métricas; warnings CRLF y runner externo.

Tampoco califican setup, inspección, crash conceptual, cleanup conceptual,
cleanup transport, cleanup persistente del fixture ni operaciones internas del
coordinator.

## 10. Fuente de verdad

El fixture estático es la expectativa autoritativa. La observación autoritativa es
un spy/decorator colocado exactamente en el puerto productivo catalogado. El
coordinator integra observaciones validadas y compara, pero no inventa nombres ni
deriva actions desde filas.

Precedencia única: catálogo normativo → expectativa estática → evento del
decorator → acumulador del coordinator → comparación exacta. Adaptadores reales
y efectos persistidos corroboran, pero no sustituyen el evento de puerto.

## 11. Schema JSON

La forma única es un catálogo contado por fase:

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 0
  },
  "replay": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 0
  }
}
```

No se admite lista, lista tipada, mapa plano, catálogo parcial ni nesting extra.

## 12. Keys y tipos

La raíz es objeto JSON no nulo con exactamente `first_delivery` y `replay`, en
ese orden. Cada valor es objeto no nulo con exactamente las seis keys de §8, en
ese orden. Cada count es entero JSON entre 0 y 2.147.483.647 inclusive.

Se prohíben floats, strings numéricos, bool, null, arrays, keys adicionales,
keys faltantes y duplicados JSON. El schema es cerrado.

## 13. Catálogo de nombres

Los seis nombres de §8 son el catálogo completo. Son globales, estables,
case-sensitive, ASCII lowercase y no contienen IDs, stage, generation, attempt,
outcome, token, order ID, action ID ni implementation class.

No existe algoritmo abierto para formar nombres adicionales. Ampliar el catálogo
requiere otra corrección normativa, versión de schema y migración fail-closed.

## 14. Fases

El catálogo de actions tiene exactamente dos fases:

- `first_delivery`: desde la entrada productiva inicial, después de S1, hasta el
  retorno, error, excepción o crash que cierre esa ejecución antes de S2.
- `replay`: desde la entrada de recuperación/repetición basada en S2, hasta el
  retorno, error o excepción previo a S3.

Setup, precondition, assertion, cleanup y finalization no son fases de
`expected.actions`. Crash es un límite, no una fase. Recovery ejecuta la fase
`replay`.

## 15. Snapshots S0–S4

S0 contiene plan y business identifiers previos a setup; no contiene actions.
S1 sella setup; abre `first_delivery`. S2 sella capturas de first delivery y su
catálogo observado. S3 sella capturas y catálogo de replay. S4 sella assertions
finales sobre la igualdad de ambas fases. Cleanup ocurre después de S4 y no crea
otro snapshot.

Ante crash antes de S2, el último delta válido conserva el prefijo observado de
`first_delivery`; recovery compara ese prefijo y luego ejecuta `replay`. Actions
no se derivan del hash: el catálogo observado participa como proyección sellada.

## 16. Orden

El orden semántico de invocaciones no forma parte de la igualdad. La igualdad es
por catálogo contado dentro de cada fase. El orden de serialización sí es fijo:
fases según §12 y actions según §8.

No se usan timestamps, microsegundos, PID ni orden accidental. Dos eventos con
igual nombre y fase se agregan al mismo count.

## 17. Conteos

`count` es el número exacto de delegaciones lógicas nuevas al puerto durante esa
fase. Incrementa inmediatamente antes de llamar al colaborador real, una vez que
la validación local permitió delegar.

Una excepción, error retornado o outcome negativo cuenta porque la delegación
ocurrió. Un rechazo previo no cuenta. Retry y replay cuentan en la fase en que
delegan. Una operación idempotente delegada cuenta; una reutilización resuelta
sin delegar no cuenta. Duplicate key, filas y transacciones no cuentan por sí.

## 18. Conteos cero

Las seis acciones aparecen siempre. Ausencia se representa exclusivamente con
entero `0`; omitir una key está prohibido. Esta regla permite afirmar ausencia,
mantiene key set idéntico entre fases y rechaza acciones futuras desconocidas.

## 19. Repeticiones

La misma action repetida en una fase incrementa su count. En fases distintas se
registra separadamente. First delivery y replay comparten nombres. No existen
ordinales, eventos duplicados ni arrays de repeticiones.

Request y response no son dos actions: la delegación al puerto es una. Cleanup
repetido queda fuera del catálogo.

## 20. Outcomes

`expected.actions` no contiene outcomes. Success, failure, exception, uncertain,
noop, not_found, duplicate y rejected pertenecen a resultados, evidencia o
mutaciones. Dos delegaciones con outcomes diferentes incrementan la misma key.

Una excepción se demuestra mediante `expected.result`/protocolo de error, no
cambiando el nombre ni el shape de actions.

## 21. Parámetros e identidades

No se admiten parámetros. IDs runtime, externos, aliases, tokens, hashes, stage,
generation, attempt, Webpay token, order ID y completion ID permanecen en las
quince categorías Runtime Capture o business identifiers.

El decorator puede recibirlos para delegar, pero emite únicamente fase y nombre
canónico. Nunca los serializa en `expected.actions`.

## 22. Definición de externalidad

“Externa” significa cruzar desde el harness/coordinator hacia el sistema bajo
prueba o hacia infraestructura controlada por el harness. No significa “fuera de
la base de datos”, “remoto” ni “irreversible”.

Webpay y Action Scheduler son fronteras productivas; base local, tablas propias,
hooks internos, logs y métricas no son external actions. HTTP o procesos hijos
solo son external actions cuando el harness los invoca como mecanismo del caso.

## 23. Proyección a `external_actions`

`external_actions` describe comandos del harness, no efectos productivos. Es un
catálogo separado y disjunto. La proyección normativa es:

`project_to_external_actions(expected.actions) = {}`.

Y la fuente real es:

`external_actions = project_harness_commands(fixture.execution_plan)`.

Ambas funciones son deterministas. Ningún evento puede aparecer en los dos
catálogos. Configurar doubles, iniciar HTTP/hijo, invocar first delivery, provocar
crash, invocar recovery y solicitar cleanup pertenecen a `external_actions`.

## 24. Proyección desde efectos productivos

| Interacción observada | Action | Fase | External action | Evidencia adicional |
|---|---|---|---|---|
| gateway Webpay delegado | `webpay.commit` | actual | no | result/exception |
| completion delegado | `woocommerce.payment_complete` | actual | no | mutation/result |
| schedule durable delegado | `scheduler.action_schedule` | actual | no | action ID capture |
| cancel delegado | `scheduler.action_cancel` | actual | no | result |
| schedule legacy delegado | `legacy.retry_schedule` | actual | no | result |
| callback ejecuta worker | `durable.worker_execute` | actual | no | generation/result |
| SQL/read/transaction | ninguna | — | no | rows/mutations |
| harness invoca fase | ninguna | — | sí | process result |
| coordinator sella snapshot | ninguna | — | no | snapshot chain |
| cleanup elimina fixture | ninguna | — | sí | cleanup report |

La función es total: todo evento observado coincide exactamente con una fila; lo
desconocido falla y no se descarta.

## 25. Crash windows

Las cinco ventanas usan la misma regla:

| Ventana | First delivery | Acción interrumpida | Replay |
|---|---|---|---|
| antes del primer efecto | prefijo vacío | ninguna delegación | catálogo propio de replay |
| antes del efecto externo | delegaciones completas previas | siguiente no cuenta | reintenta si contrato lo permite |
| después de request externa | request cuenta | outcome puede ser incierto | consulta/redelegación según caso |
| después de persistencia, antes de publicación | delegaciones previas cuentan | SQL no es action | reutiliza evidencia |
| después de publicación, antes de retorno | catálogo first delivery completo | retorno no cuenta | idempotencia puede producir ceros |

Cada crash conserva el prefijo validado; nunca desincrementa. La especificación
del caso determina qué delegación puede repetirse, pero el conteo siempre sigue
§17. Efectos irrepetibles se prueban con replay en cero o con evidencia externa.

## 26. Replay

Replay tiene mapa propio y no modifica first delivery. Toda redelegación real
incrementa, incluso si es idempotente. Una verificación local o reutilización sin
puerto no incrementa. Una consulta de evidencia solo cuenta si fuera una action
del catálogo; el catálogo actual no contiene reads.

El nombre es idéntico al de first delivery. La comparación se efectúa por fase y
luego sobre la raíz completa.

## 27. Cleanup

Cleanup conceptual es la obligación de converger; cleanup transport descarta
estado en memoria; cleanup externo controla procesos/HTTP; cleanup persistente
elimina recursos owned; cleanup runner elimina temporales. Ninguno integra
`expected.actions` porque ocurre fuera de las dos fases productivas.

Las delegaciones de cleanup se declaran en `external_actions` y su resultado en
el reporte de cleanup. Esto permite verificarlo sin confundir infraestructura de
prueba con efecto productivo.

## 28. Errores

Catálogo cerrado de códigos:

- `actions_root_type_invalid`
- `actions_phase_missing`
- `actions_phase_unknown`
- `actions_phase_order_invalid`
- `actions_action_missing`
- `actions_action_unknown`
- `actions_action_order_invalid`
- `actions_count_type_invalid`
- `actions_count_negative`
- `actions_count_out_of_range`
- `actions_duplicate_key`
- `actions_field_unknown`
- `actions_observed_unexpected`
- `actions_expected_missing`
- `actions_count_mismatch`
- `actions_external_projection_mismatch`
- `actions_snapshot_projection_mismatch`
- `actions_wrong_phase`
- `actions_alias_forbidden`
- `actions_identity_forbidden`

No existe `sequence_mismatch`: secuencia no es parte de la igualdad; un intento
de suministrarla produce `actions_field_unknown`.

## 29. Validación fail-closed

El parser rechaza root alternativo, campo desconocido, action o phase desconocida,
coerción, float, null, bool, array inesperado, duplicado JSON, orden incorrecto,
alias, identidad, count extra o faltante. El observador rechaza evento desconocido
y fase no abierta. La comparación exige igualdad profunda exacta.

No se ignoran nuevas versiones, keys, eventos ni datos untracked.

## 30. Canonicalización y hash

Se usa UTF-8, JSON sin BOM, strings sin escaping innecesario, enteros decimales y
sin whitespace significativo. Orden de keys según §§12 y 8; no se ordena según
entrada. Unicode debe estar normalizado NFC antes de canonicalizar, aunque el
catálogo actual es ASCII.

El objeto exacto participa en el hash canónico del fixture. La proyección
observada usa el mismo encoder y participa en el snapshot S2/S3 correspondiente.
El coordinator recalcula y compara hash antes de sellar; nunca confía en un hash
recibido sin recomputar.

## 31. Compatibilidad con coordinator

El coordinator actual transporta captures, pero no actions. Requiere ampliación
futura conceptual:

```php
recordAction(string $executionId, string $phase, string $name): void
closeActionPhase(string $executionId, string $phase): array
observedActions(string $executionId): array
assertExpectedActions(string $executionId, array $expected): void
```

`recordAction` valida fase/nombre antes de incrementar; `closeActionPhase` impide
nuevos eventos; `observedActions` devuelve la forma §11; `assertExpectedActions`
compara canónicamente. No se implementan aquí.

## 32. Matriz de ejemplos

Los fragmentos siguientes sustituyen los counts indicados dentro del schema
completo de §11; las demás keys quedan explícitamente en cero.

| Caso | Fase/fragmento válido | Regla |
|---|---|---|
| cero actions | `"webpay.commit":0` | key no se omite |
| una exitosa | `first_delivery/webpay.commit:1` | outcome fuera |
| fallida | `first_delivery/webpay.commit:1` | delegación cuenta |
| excepción | `first_delivery/webpay.commit:1` | delegación cuenta |
| repetida | `replay/webpay.commit:2` | agrega por fase |
| dos fases | `first_delivery:1,replay:1` | mapas separados |
| request/response | `webpay.commit:1` | una delegación |
| SQL write | todas `0` | excluida |
| duplicate key | todas `0` | excluido |
| commit | todas `0` | excluido |
| rollback | todas `0` | excluido |
| commit incierto | todas `0` | resultado SQL excluido |
| scheduling | `scheduler.action_schedule:1` | puerto delegado |
| cancelación | `scheduler.action_cancel:1` | puerto delegado |
| replay | mapa replay independiente | no acumulativo |
| cleanup | todas `0` | external_actions |
| acción externa harness | todas `0` | catálogo disjunto |
| interna excluida | todas `0` | no es puerto catalogado |
| action inesperada | JSON rechazado | unknown |
| count faltante | JSON rechazado | missing |
| count adicional | JSON rechazado | unknown |

Ejemplo completo con una delegación:

```json
{"first_delivery":{"webpay.commit":1,"woocommerce.payment_complete":0,"scheduler.action_schedule":0,"scheduler.action_cancel":0,"legacy.retry_schedule":0,"durable.worker_execute":0},"replay":{"webpay.commit":0,"woocommerce.payment_complete":0,"scheduler.action_schedule":0,"scheduler.action_cancel":0,"legacy.retry_schedule":0,"durable.worker_execute":0}}
```

## 33. Aplicación a WR-06

WR-06 podrá declarar Webpay commit, completion WooCommerce, schedule/cancel
durable, schedule legacy alternativo y worker. Webpay y Action Scheduler cruzan
fronteras productivas, pero siguen en `expected.actions`; los comandos del
harness que los preparan permanecen en `external_actions`.

Los counts dependen de la ruta real, resultado Webpay, persistencia, ventana de
crash y replay; no se fijan aquí. Continúan pendientes los valores concretos del
fixture y cualquier otro contrato que la auditoría final marque. Generation,
association SQL, rows y outcomes no se inventan como actions.

## 34. Aplicación a los 31 casos

Todo caso usa el mismo schema de 12 counts: seis actions por dos fases. Casos sin
externalidad usan ceros; casos con replay usan el segundo mapa; cleanup se prueba
por su reporte; errores cuentan solo delegaciones ocurridas; repeticiones agregan.

Las cinco crash windows se expresan mediante el prefijo first delivery observado
y el mapa replay. No se asignan IDs, filas, values ni counts concretos.

## 35. Riesgos

Los riesgos son instrumentar por debajo del puerto y contar SQL físico, confundir
commands del harness con effects productivos, perder eventos por crash, contar
resultados como invocaciones, aceptar keys futuras y depender de orden temporal.

Se mitigan con decorators en puertos, acumulador del padre, schema cerrado,
snapshots sellados y comparación exacta.

## 36. Allowlist futura

La implementación futura mínima podrá modificar, tras autorización separada:

- capture contract: tipos/schema de catálogo observado;
- coordinator: acumulación, cierre, proyección y comparación;
- harness funcional Runtime Capture: casos de validación;
- harness infraestructura Runtime Capture: transporte/crash/fail-closed;
- decorators de los seis puertos: observación, sin cambiar comportamiento;
- harnesses H1–H5: certificación operacional;
- fixtures: solo después de cerrar valores por caso.

Producto no requiere cambio salvo decorators de observación explícitamente
autorizados; WR-06 no se incluye automáticamente.

## 37. Criterios de implementación

Debe existir un único catálogo constante, un único canonicalizer, un acumulador
propiedad del coordinator, decorators sin fallback, cierre monotónico por fase,
transporte sin filesystem persistente y tests de todos los errores §28.

No se acepta observación por logs, consultas posteriores, reflection accidental,
timing, nombres de doubles ni conteo de filas.

## 38. Criterios de certificación

Se requiere schema válido para 31 casos, cinco crash windows, ceros, repetición,
excepción y replay; matriz adversarial completa; igualdad canónica; rechazo de
unknown; integración multiproceso; cleanup sin residuos; regresión histórica
verde y hashes protegidos intactos.

La certificación debe demostrar que productor y verificador consumen el mismo
catálogo y que ningún evento se pierde o duplica al sellar S2/S3.

## 39. Condiciones de bloqueo

Se bloquea implementación si se requiere séptima action sin nueva autoridad, una
tercera fase, outcomes o IDs dentro del shape, derivación desde SQL, solapamiento
con `external_actions`, filesystem runtime, modificación de snapshots incompatible
o counts concretos sin autoridad de caso.

También bloquea cualquier contradicción futura con el contrato productivo real.

## 40. Integridad final

Esta ejecución crea únicamente este documento. No modifica producto, pruebas,
Runtime Capture, R2, R4, los 13 harnesses R5, fixtures, manifests, artifacts ni
configuración. No realiza staging, commit o push.

La recertificación física de rama, HEAD, divergencia, hashes, staging, diff,
procesos y residuos acompaña el informe de ejecución.

## 41. Veredicto final

Existe una definición única, forma JSON única, catálogo cerrado, fases, orden,
conteo, ceros, repetición, externalidad, proyecciones, snapshots, crash, replay,
cleanup, errores y canonicalización deterministas. El contrato representa los 31
casos sin materializarlos y permite construir WR-06 posteriormente sin
reinterpretar `expected.actions`.

`A11 EXPECTED ACTIONS IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`
