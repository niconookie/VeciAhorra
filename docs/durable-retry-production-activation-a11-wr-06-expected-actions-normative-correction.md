# Corrección normativa de definición y shape de `expected.actions` para A11-WR-06

## 1. Propósito

Esta corrección cierra la definición, exclusiones, separación de categorías, shape y semántica de conteo de `expected.actions` para `A11-WR-06`. Audita el catálogo en orden productivo y se detiene en el primer bloqueo material.

## 2. Alcance

Se inspeccionan el límite Webpay, WooCommerce, Durable Retry, scheduler legacy, callback y publicación interna solo hasta donde permite el criterio de detención. No se auditan `expected.result`, `expected.mutations`, `fixture_ids` ni `external_actions` como categoría completa.

No se implementa A11 ni se crean fixtures, validadores, decoradores, doubles, auxiliares o harnesses.

## 3. Antecedentes

`expected.rows` está cerrado. La ruta durable fija activación 100, A3 inicial `legacy`, A4 `transferred`, A5 `durable_created`, A8 inicial `durable_synchronized`, A8 replay `durable_already_synchronized`, generation 1, attempt 0, fila `scheduled` versión 2 y worker no ejecutado.

La auditoría antecedente concluyó correctamente que las normas previas no definían “action”. Esta corrección aporta esa autoridad, pero no puede alterar hechos productivos incompatibles.

## 4. Bloqueo antecedente

El bloqueo `expected.actions.definition` queda resuelto por las secciones 5–9. La definición ya no depende de interpretar la frase previa “enteros no negativos o catálogos”.

## 5. Definición

Una `expected action` es una invocación nueva, observable y contabilizable de un puerto productivo con efectos, realizada por el sistema durante una fase del caso.

Debe cruzar un límite productivo identificable, producir efectos externos o funcionales, ser observable determinísticamente, poder contarse sin inferencia desde filas, distinguir ejecución nueva de relectura/reutilización y pertenecer temporalmente a `first_delivery` o `replay`.

## 6. Exclusiones

No son actions: SQL individual, inserts, updates, deletes, commit, rollback, DTOs, serialización, hashes, fingerprints, normalización, validación pura, A3, resultados A4–A8, llamadas privadas, contenedor, publicación interna sin puerto, filas, estados finales, comandos del harness, procesos o PIDs.

Claim, stage processor, consume, generation 2 y backoff tampoco se convierten en claves solo por estar prohibidos.

## 7. Separación de categorías

`external_actions` describe intervenciones del harness: invocar una devolución o replay, preparar opciones, simular disponibilidad, iniciar procesos o ejecutar callbacks. `expected.actions` describe invocaciones productivas con efectos causadas por esas intervenciones.

`expected.rows` cuenta filas; `expected.result` estados funcionales; `expected.mutations` cambios concretos; `processes` topología. Una misma operación no puede duplicarse bajo `external_actions` y `expected.actions`.

## 8. Shape

El shape exacto es:

```php
'actions' => [
    'first_delivery' => [
        '<logical_action_name>' => <non-negative integer>,
    ],
    'replay' => [
        '<logical_action_name>' => <non-negative integer>,
    ],
],
```

`actions` es array asociativo PHP y objeto JSON. Contiene exactamente `first_delivery` y `replay`, en ese orden. Cada fase es un mapa cerrado con key set y orden idénticos.

## 9. Semántica de conteo

Cada valor es el número exacto de invocaciones nuevas dentro de la fase, no acumulativo. Es entero no negativo; se rechazan strings numéricos, booleanos, null, rangos y valores negativos. Una clave del catálogo que no ocurre vale `0` y no se omite. Relectura o reutilización sin nueva invocación vale `0`. El resultado de la action no forma parte del conteo.

## 10. Nombres

Las claves deben ser estables, `snake_case`, independientes del double, host y almacenamiento, y corresponder a un único puerto. Se prohíben aliases y nombres genéricos como `action`, `call`, `schedule`, `process`, `write` o `update`.

Los nombres candidatos solo se vuelven autoritativos cuando el catálogo completo puede cerrarse; esta corrección no publica un catálogo parcial.

## 11. Orden

El orden canónico sigue efectos observables: Webpay externo, efecto WooCommerce, schedule durable, scheduler legacy alternativo y worker durable. Solo los grupos finalmente incluidos pueden aparecer, con idéntico orden en ambas fases.

## 12. Autoridades inspeccionadas

| Área | FQCN/puerto | Método autoritativo |
|---|---|---|
| Webpay | `WebpayReturnGatewayInterface` | `commit(string $token): WebpayCommitResult` |
| retorno | `WebpayReturnService` | procesamiento de la devolución y `repeated(...)` |
| materialización | `WebpayReconciliationMaterializer` | `materialize(...)`, `resume(...)` |
| completion | `PaymentCompletionHandlerInterface` | `complete(...): PaymentCompletionOutcomeInterface` |
| WooCommerce | `WooCommercePaymentCompletionHandler` | `complete(...)`, llamada privada a `$order->payment_complete($reference)` |
| processor | `PaymentReconciliationProcessor` | `process(ReconciliationLease $lease)` |
| durable scheduler | `DurableRetryExternalSchedulerInterface` | `schedule(...)`, `findPending(...)` |
| adapter AS | `ActionSchedulerDurableRetryAdapter` | `schedule(...)`, `findPending(...)` |
| legacy scheduler | `DurableRetryLegacySchedulerInterface` | `scheduleReconciliation(int $reconciliationId): bool` |
| callback | `DurableRetryActionCallback` | `execute(mixed $hook, mixed $scheduleId, mixed $generation)` |
| router | `DurableRetryInitialProductionRouter` | `routeReconciliation(...)` |

## 13. Webpay

La primera devolución nueva atraviesa `WebpayReturnGatewayInterface::commit()` exactamente una vez. `WebpayReturnService` llama `commit($request->token)` antes de materializar el resultado.

El replay detecta el retorno ya procesado y entra en `repeated()`: reconstruye evidencia desde `result_json` o llama `resume()`, sin volver a `commit()`. Por tanto la candidata de puerto Webpay tiene conteos demostrados `1/0` y puede observarse mediante un double del interface ya inyectable.

No se adopta todavía un nombre manifest porque el catálogo se detiene posteriormente y debe materializarse de una sola vez.

## 14. WooCommerce

`PaymentCompletionHandlerInterface::complete()` es un puerto productivo observable. Su implementación WooCommerce acaba llamando `$order->payment_complete($reference)` y esta única operación causa pago, transaction ID y fecha pagada; los `save()` y metas auxiliares no son actions separadas.

Sin embargo, el puerto solo es llamado por `PaymentReconciliationProcessor::process()` después de adquirir y validar una lease. La devolución y `WebpayReconciliationMaterializer` únicamente persisten/publican la reconciliación y programan generation 1; no llaman al completion handler.

## 15. Scheduler durable

`DurableRetryExternalSchedulerInterface::schedule()` es el puerto concreto para solicitar programación. En la ruta cerrada se invoca una vez durante first delivery. La asociación posterior del action ID es mutación interna, no segunda action externa.

En replay la fila ya está `scheduled`; el coordinador usa `findPending()` para verificar el mismo ID. Esa verificación es una interacción con el puerto pero no es una nueva solicitud de programación. El conteo de schedule nuevo sería `1/0`.

No se materializa como clave hasta resolver el catálogo completo.

## 16. Scheduler legacy

`DurableRetryLegacySchedulerInterface::scheduleReconciliation()` es inyectable y observable. La ruta durable 100 nunca entra en la rama legacy: su conteo demostrado es `0/0`. Es una candidata fuerte porque la ausencia de fallback es una aserción central del router.

## 17. Worker

`DurableRetryActionCallback::execute()` ofrece un límite público observable y delega al executor. WR-06 fija `durable_retry_execute_worker=false`; por ello callback/worker es `0/0` y no se alcanzan claim, processor, consume, backoff o generation 2.

Este hecho produce el conflicto descrito en §30: sin ejecutar el worker tampoco se alcanza el completion WooCommerce requerido por el antecedente funcional WR-06.

## 18. Publicación interna

`WebpayReconciliationMaterializer::publishRetryAuthorityCandidate()` es privado y llama directamente a A8. No cruza un puerto independiente ni tiene observer contractual. Se excluye de `expected.actions`; su efecto externo ya queda representado por el scheduler durable.

Tampoco se cuentan `invoke_router`, creación de generation 1 o asociación SQL como actions.

## 19. Resultados A3–A8

Se preservan:

```text
A3 initial=legacy
A4=transferred
A4 reason=initial_transfer_created
A5 first_delivery=durable_created
A8 first_delivery=durable_synchronized
A5 replay=durable_existing
A8 replay=durable_already_synchronized
```

Explican los conteos de scheduling, pero no son claves serializables en `actions`.

## 20. Catálogo

Catálogo candidato, no materializable todavía:

| Candidata | Puerto | FD/R demostrable | Estado |
|---|---|---:|---|
| Webpay commit | `WebpayReturnGatewayInterface::commit` | 1/0 | determinada |
| WooCommerce completion | `PaymentCompletionHandlerInterface::complete` | conflicto | bloqueada |
| durable external schedule | `DurableRetryExternalSchedulerInterface::schedule` | 1/0 | determinada |
| legacy reconciliation schedule | `DurableRetryLegacySchedulerInterface::scheduleReconciliation` | 0/0 | determinada |
| durable worker execution | `DurableRetryActionCallback::execute` | 0/0 | determinada |

No se declara catálogo incluido final ni se publican nombres canónicos parciales.

## 21. Conteos

Webpay `1/0`, schedule durable `1/0`, scheduler legacy `0/0` y worker `0/0` están respaldados por puertos y ruta. WooCommerce no puede recibir un conteo normativo único: el flujo con worker excluido demuestra `0/0`, mientras el caso exige una aplicación funcional en first delivery, que requeriría alcanzar el processor y su puerto.

## 22. Observabilidad

- Webpay: double de `WebpayReturnGatewayInterface`.
- Completion: double de `PaymentCompletionHandlerInterface`, inyectable en `PaymentReconciliationProcessor`.
- Schedule durable: double de `DurableRetryExternalSchedulerInterface`.
- Legacy: double de `DurableRetryLegacySchedulerInterface`.
- Worker: invocación pública `DurableRetryActionCallback::execute()` o executor inyectado.

Estas costuras existen sin inferir acciones desde SQL, logs, timestamps o filas. La observabilidad no resuelve la contradicción temporal WooCommerce.

## 23. Primera entrega

La entrega llama una vez al gateway, materializa reconciliación, publica internamente, transfiere autoridad y solicita un schedule durable. No invoca legacy ni worker. En ese trayecto no existe llamada a `PaymentCompletionHandlerInterface::complete()` ni a `payment_complete()`.

## 24. Replay

El replay reconstruye/reutiliza evidencia sin gateway commit, publica internamente, encuentra generation 1, verifica el mismo action ID y retorna `durable_already_synchronized`. No solicita otra programación, no invoca legacy, worker o completion.

## 25. Idempotencia

Reutilizar `result_json`, reconciliación, fila durable y action ID no constituye una invocación nueva. No se duplican commit, schedule, fila, generation o worker. La ausencia de segunda completion es demostrable; la presencia de la primera no lo es bajo el contrato temporal vigente.

## 26. PHP

Definición estructural cerrada, pero manifest no materializable:

```php
'actions' => [
    'first_delivery' => [
        /* BLOQUEADO: catálogo WooCommerce contradictorio */
    ],
    'replay' => [
        /* BLOQUEADO: no se permite catálogo parcial */
    ],
],
```

Este fragmento documenta el shape; no es un fixture válido.

## 27. JSON

`actions` debe ser un objeto con objetos `first_delivery` y `replay`. No se presenta JSON canónico ejecutable porque `{}`, claves parciales o placeholders violarían el cierre integral.

## 28. Manifest

La forma elegida será una única entrada `expected.actions=<JSON canónico exacto>`, pero no se materializa hasta resolver §30. Se prohíben claves planas alternativas y aliases.

## 29. Matriz adversarial

| # | input | phase | operation | classification | observed/expected | accepted | reason |
|---:|---|---|---|---|---|:---:|---|
| 1 | definición correcta | ambas | puerto con efectos | action | exacto | sí | cumple seis criterios |
| 2 | fila como action | cualquiera | persistencia | rows/mutations | n/a | no | categoría incorrecta |
| 3 | mutación como action | cualquiera | update | mutations | n/a | no | categoría incorrecta |
| 4 | resultado como action | cualquiera | estado final | result | n/a | no | categoría incorrecta |
| 5 | comando harness | cualquiera | invocar fase | external_actions | n/a | no | intervención externa |
| 6 | shape correcto | ambas | dos mapas | shape | cerrado | sí | contrato estructural |
| 7 | actions null | raíz | nulo | shape | inválido | no | objeto requerido |
| 8 | fase ausente | raíz | una fase | shape | inválido | no | dos fases exactas |
| 9 | fase adicional | raíz | tercera fase | shape | inválido | no | catálogo cerrado |
| 10 | key set diferente | ambas | claves distintas | shape | inválido | no | identidad obligatoria |
| 11 | orden diferente | ambas | orden distinto | shape | inválido | no | orden contractual |
| 12 | acumulativo | replay | total | count | inválido | no | específico de fase |
| 13 | string numérico | cualquiera | "1" | count | inválido | no | entero requerido |
| 14 | booleano | cualquiera | true | count | inválido | no | entero requerido |
| 15 | negativo | cualquiera | -1 | count | inválido | no | no negativo |
| 16 | rango | cualquiera | 0..1 | count | inválido | no | literal exacto |
| 17 | discovery runtime | cualquiera | clave nueva | catalog | inválido | no | catálogo cerrado |
| 18 | operación pura | cualquiera | hash | excluded | n/a | no | sin puerto con efectos |
| 19 | commit inexistente | replay | gateway commit | candidate | 0 | sí | replay no contacta gateway |
| 20 | completion inicial | first_delivery | complete | conflict | 0 frente a 1 | no | worker excluido |
| 21 | completion repetida | replay | complete | candidate | 0 | sí | replay no procesa |
| 22 | durable schedule | first_delivery | schedule | candidate | 1 | sí | puerto invocado |
| 23 | segundo schedule | replay | schedule | candidate | 0 | sí | findPending reutiliza |
| 24 | legacy invocado | cualquiera | scheduleReconciliation | candidate | 0 esperado | no | fallback prohibido |
| 25 | worker invocado | cualquiera | callback execute | candidate | 0 esperado | no | fixture false |
| 26 | generation 2 action | cualquiera | fila | excluded | n/a | no | rows/mutations |
| 27 | resultado A8 action | cualquiera | state | excluded | n/a | no | resultado interno |
| 28 | asociación SQL action | first_delivery | update | mutations | n/a | no | no puerto externo |
| 29 | external duplicada | cualquiera | harness invocation | external_actions | n/a | no | solapamiento |
| 30 | replay correcto | replay | reutilización | candidates | 0 nuevos | sí | idempotencia |
| 31 | segundo replay | replay | reutilización | candidates | 0 nuevos | sí | idempotencia |
| 32 | activación cambia | replay | authority existing | internal | 0 schedules | sí | durable prevalece |
| 33 | scheduler indisponible | replay | findPending | incompatible | n/a | no | no satisface WR-06 |
| 34 | mismo action ID | replay | findPending | reuse | 0 schedules | sí | no invocación nueva |
| 35 | acción no observable | cualquiera | operación opaca | excluded | n/a | no | falta costura |
| 36 | inferencia desde fila | cualquiera | contar SQL | excluded | n/a | no | no prueba invocación |
| 37 | manifest parcial | raíz | claves conocidas | blocked | n/a | no | catálogo incompleto |
| 38 | alias | cualquiera | nombre alternativo | catalog | inválido | no | aliases prohibidos |
| 39 | clave adicional | cualquiera | no contractual | catalog | inválido | no | catálogo cerrado |
| 40 | clave omitida | cualquiera | contractual | catalog | inválido | no | ceros no se omiten |

## 30. Bloqueo siguiente

Primer bloqueo material después de cerrar definición y shape:

```text
case: A11-WR-06
category: expected
field: expected.actions.catalog.woocommerce_payment_completion
reason: PaymentCompletionHandlerInterface::complete() solo es alcanzado por
        PaymentReconciliationProcessor durante procesamiento; WR-06 prohíbe
        ejecutar el durable worker, por lo que el flujo cerrado demuestra 0/0,
        mientras el caso exige transición de pago/pedido y single_application,
        que requieren una primera invocación funcional
required_authority: decisión WR-06 que ubique una ejecución productiva del
                    reconciliation processor dentro de first_delivery, o que
                    retire/cambie el observable WooCommerce y su conteo esperado
```

No puede elegirse `1/0` ni `0/0` sin resolver esta contradicción.

## 31. Veredictos

```text
CONTRATO DEFINICIÓN Y SHAPE EXPECTED ACTIONS WR-06 CERRADO
A11-WR-06 CONTINÚA BLOQUEADO POR EXPECTED ACTIONS INDETERMINADO
A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA
```

Estado:

```text
expected.rows: cerrado
expected.actions.definition: cerrado
expected.actions.shape: cerrado
expected.actions.catalog: bloqueado
expected.result: no auditado
expected.mutations: pendiente
fixture_ids: no auditado
external_actions: pendiente
```

Los otros 30 casos permanecen sin avance.

## 32. Integridad

Esta corrección crea exclusivamente este documento. No modifica la auditoría antecedente, código, pruebas, configuración o artifacts; no implementa A11 y no autoriza staging, commit o push.
