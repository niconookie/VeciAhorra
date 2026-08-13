# Corrección normativa de resultado y `single_application` para A11-WR-06

## 1. Propósito

Esta corrección alinea `A11-WR-06` con su call graph productivo real. Adopta como autoridad:

```text
WOOCOMMERCE PAYMENT COMPLETION WR-06 REQUIERE CAMBIO NORMATIVO DE OBSERVABLE
```

No autoriza ejecutar `PaymentReconciliationProcessor` durante `first_delivery`.

## 2. Alcance

Se cierran el límite temporal, el catálogo de `expected.actions`, el shape y valores de `expected.result`, el objeto directo de `single_application`, replay, concurrencia y relación con las cinco ventanas de crash.

No se materializan fixtures, PHP, JSON de fixture o manifest; no se auditan `fixture_ids` ni los otros 30 casos.

## 3. Autoridades

Autoridad productiva:

- `WebpayReturnService::process()` y `repeated()` para first delivery y replay.
- `WebpayReconciliationMaterializer::materialize()`, `resume()` y publicación privada.
- `DurableRetryInitialProductionRouter` y autoridades A5–A8.
- `DurableRetryExternalSchedulerInterface`, coordinador y repositorio de schedule.
- `DurableRetryActionCallback → DurableRetryExecutor → DurableRetryReconciliationProcessor → PaymentReconciliationProcessor → PaymentCompletionHandlerInterface::complete()` como autoridad exclusiva posterior.
- Persistencia de `webpay_returns`, evidencia financiera, `payment_reconciliations` y `durable_retry_schedules`.

Antecedente documental: shape cerrado de actions, auditoría WooCommerce, matriz A11 y sus cinco ventanas. Evidencia de tests solo acredita costuras y comportamiento probado; no crea contratos. Las conclusiones temporales son inferencias normativas respaldadas por el call graph citado.

## 4. Límite temporal de first delivery

`first_delivery` termina después de persistir la evidencia necesaria, publicar la autoridad durable, programar o resolver el action externo, asociar su ID cuando corresponde y producir la respuesta Webpay.

No incluye callback, claim, executor, reconciliation processor, `complete()`, transición funcional de pago/pedido, consumo o terminalización del schedule. Su ruta cerrada termina con A8=`durable_synchronized`, fila generation 1 `scheduled` y version 2.

## 5. Límite temporal de replay

`replay` termina después de releer/reconstruir evidencia, reutilizar la reconciliación, resolver la autoridad generation 1, verificar la programación persistida y producir `already_processed`.

No ejecuta worker. Su A8 es `durable_already_synchronized`; `findPending()` verifica el mismo action ID y no es una nueva programación.

## 6. Catálogo definitivo de `expected.actions`

El catálogo exacto, en orden funcional, es:

```php
'actions' => [
    'first_delivery' => [
        'webpay_gateway_commit' => 1,
        'woocommerce_payment_completion' => 0,
        'durable_external_schedule' => 1,
        'legacy_reconciliation_schedule' => 0,
        'durable_worker_execution' => 0,
    ],
    'replay' => [
        'webpay_gateway_commit' => 0,
        'woocommerce_payment_completion' => 0,
        'durable_external_schedule' => 0,
        'legacy_reconciliation_schedule' => 0,
        'durable_worker_execution' => 0,
    ],
],
```

Ambas fases tienen idéntico key set y orden. Los valores son invocaciones nuevas por fase, no acumulativas. Cero significa ausencia de nueva invocación dentro de la fase, no ausencia de autoridad futura.

## 7. Exclusiones de actions

La asociación del action ID es una mutación persistente, no action separada. `findPending()` es verificación/reutilización, no schedule nuevo. La publicación A10/A11 es privada, interna y no constituye puerto productivo observable.

A3 y resultados A4–A8, SQL, filas, fingerprints, estados y respuestas pertenecen a otras categorías.

## 8. Corrección del resultado anterior

El antiguo candidato de `expected.result` contenía `public_result`, estado de pago, date paid, transaction ID, fingerprint reconciliado y reconciliation status. Los tres observables positivos WooCommerce y el fingerprint reconciliado por completion presuponían worker; `reconciliation_status=completed` también lo presuponía.

Se retiran esos positivos temporales. El resultado ahora observa lo que first delivery y replay efectivamente dejan persistido y programado.

## 9. Clasificación de observables

| Observable | Categoría | Decisión |
|---|---|---|
| commit Webpay aceptado | A, first delivery | determinado por `approved` |
| evidencia financiera persistida | A/B | `true` en ambas |
| reconciliación persistida | A/B | status `pending` en ambas |
| candidato durable publicado | A | demostrado por A8, no action propia |
| schedule resuelto/programado | A/B | `scheduled` |
| action ID asociado/reutilizado | A/B | presente y estable |
| respuesta first delivery | A | `approved` |
| respuesta replay | B | `already_processed` |
| identidad durable estable | A/B | generation 1 reutilizada |
| nuevo gateway en replay | B | ausente; pertenece también a actions |
| nueva programación en replay | B | ausente; pertenece también a actions |
| pago funcional completado | E | prohibido como positivo |
| pedido funcional transitado | E | prohibido como positivo |
| schedule consumido | E | prohibido como positivo |
| worker ejecutado | E | prohibido como positivo |

## 10. Shape cerrado de `expected.result`

Para WR-06, `result` contiene exactamente dos fases. No se añade `post_phase_processing`: la matriz raíz no define una tercera fase y el procesamiento posterior queda fuera de alcance por contrato.

```php
'result' => [
    'first_delivery' => [
        'public_result' => 'approved',
        'financial_evidence_persisted' => true,
        'reconciliation_status' => 'pending',
        'durable_routing_result' => 'durable_synchronized',
        'durable_schedule_status' => 'scheduled',
        'scheduled_action_id_present' => true,
        'durable_identity_stable' => true,
        'woocommerce_payment_completion_observed' => false,
        'functional_payment_transition_observed' => false,
        'durable_worker_execution_observed' => false,
    ],
    'replay' => [
        'public_result' => 'already_processed',
        'financial_evidence_persisted' => true,
        'reconciliation_status' => 'pending',
        'durable_routing_result' => 'durable_already_synchronized',
        'durable_schedule_status' => 'scheduled',
        'scheduled_action_id_present' => true,
        'durable_identity_stable' => true,
        'woocommerce_payment_completion_observed' => false,
        'functional_payment_transition_observed' => false,
        'durable_worker_execution_observed' => false,
    ],
],
```

El key set y orden son idénticos. Los literales proceden de catálogos productivos: Webpay, reconciliation, A8 y durable status.

## 11. Semántica del resultado

`scheduled_action_id_present=true` no materializa el ID; solo exige entero positivo asociado. `durable_identity_stable=true` exige la misma identidad `(reconciliation, subject_id, completion_id, generation=1)` y no una segunda fila.

Los tres falsos finales son observables de alcance: no afirman que el procesamiento nunca ocurrirá, sino que no ocurrió dentro de la fase.

## 12. Nueva definición de `single_application`

Para WR-06, `single_application` significa exactamente:

> una sola aplicación de la autoridad durable inicial activa para la identidad de reconciliación, generation 1.

Se selecciona la dimensión D. El objeto directo es `initial_durable_authority`, no pago, pedido ni `complete()`.

## 13. Evidencia de `single_application`

El evento contado es la aceptación/materialización efectiva de una única identidad durable `(stage=reconciliation, subject_id, completion_id, generation=1, active_slot=1)`. A4 y el repositorio de transferencia la producen; la fila durable y sus restricciones de identidad/active slot son evidencia.

First delivery demuestra una aplicación nueva: A4=`transferred`, A5=`durable_created`. Replay demuestra cero nuevas: A5=`durable_existing`, A6 resuelve generation 1 y A8=`durable_already_synchronized`.

Dos entregas concurrentes convergen por duplicate key compatible; incompatible falla cerrado. Una excepción posterior no autoriza otra identidad. Intento es una operación que no confirmó la fila; aplicación es la autoridad persistida compatible; reutilización es observarla sin INSERT nuevo. Esto no invade la autoridad del worker.

## 14. Procesamiento funcional posterior

El grafo worker → reconciliation processor → `complete()` → transición funcional es consecuencia durable posterior, no resultado de WR-06.

Su éxito o fallo no modifica retroactivamente actions. No convierte replay en first delivery. Una ejecución posterior no pertenece a ninguna fase. Las cinco ventanas de crash siguen bajo el pipeline durable y otros casos pueden certificar worker y transición funcional.

## 15. Replay según estado externo del worker

WR-06 exige worker no ejecutado. Por tanto:

1. Worker no iniciado: replay admisible; retorna `already_processed`, A8 durable already synchronized.
2. Worker concurrente: caso incompatible con WR-06; se detiene fail-closed y se remite al catálogo productivo de claim/estado, sin inventar resultado.
3. Worker terminado exitosamente: fuera de precondición WR-06; corresponde a caso de procesamiento posterior.
4. Worker terminó incierto: fuera de precondición; corresponde a estados productivos de outcome uncertain/intervention.
5. Evidencia incompatible: fail-closed mediante autoridades de fingerprint/A3/A6/A7; no se normaliza como replay exitoso.

Así, replay no depende de que el worker haya ejecutado; depende de que no lo haya hecho dentro del fixture.

## 16. Cinco ventanas de crash

| Ventana | Evidencia persistida | Gateway reutilizable | Schedule durable | Action ID | Worker ejecutado | Resultado WR-06 |
|---|---|---|---|---|---|---|
| CR-01 external action | recon/dispatching posible | sí si return/evidencia confirmada | recovery converge | ausente o uno compatible | no exigido | fail-closed hasta A7 convergente |
| CR-02 local claim | evidencia+scheduled | sí | claimed/terminal según catálogo | asociado | sí/iniciado | fuera de WR-06 |
| CR-03 functional attempt | evidencia durable | sí | procesamiento según catálogo | asociado | sí | fuera de WR-06 |
| CR-04 result persisted | resultado processor persistido | sí | terminal/convergente | asociado | sí | fuera de WR-06 |
| CR-05 callback return | evidencia terminal | sí | terminal | asociado | sí | fuera de WR-06 |

Ninguna ventana obliga a WR-06 a observar pago. CR-02–05 pertenecen al pipeline posterior; CR-01 solo puede reingresar a WR-06 cuando recuperación confirma la misma autoridad y programación.

## 17. Concurrencia

- Dos first deliveries: una aceptación Webpay durable y una identidad generation 1; la otra reutiliza evidencia/autoridad.
- First delivery con replay: locks, fingerprints e identidad durable impiden segunda fila/programación compatible.
- Replay con worker: no es una ejecución válida de WR-06 y falla la precondición worker=false.
- Dos replays: ambos reutilizan evidencia, action ID e identidad; cero schedules nuevos.
- Worker terminado antes del replay: fuera del caso, no se reclasifica silenciosamente.
- Worker incierto antes del replay: fuera del caso y fail-closed.

La prevención de doble transición funcional pertenece al processor/handler posterior y sus fingerprints; WR-06 garantiza solo una aplicación de autoridad durable inicial.

## 18. Tabla de reemplazo

| Campo actual WR-06 | Problema | Mantener | Retirar | Sustituir por | Autoridad |
|---|---|---:|---:|---|---|
| actions Webpay | válido | sí | no | `webpay_gateway_commit=1/0` | gateway interface |
| payment completion | worker ausente | sí como cero | no | `woocommerce_payment_completion=0/0` | call graph |
| durable schedule | válido | sí | no | `durable_external_schedule=1/0` | scheduler interface |
| legacy schedule | fallback prohibido | sí como cero | no | `legacy_reconciliation_schedule=0/0` | A8 |
| worker | fuera de fases | sí como cero | no | `durable_worker_execution=0/0` | callback |
| `public_result` plano | no distingue fases | no | sí | phased approved/already_processed | Webpay service |
| order is paid=true | presupone worker | no | sí | transition observed=false | handler call graph |
| date paid present=true | presupone worker | no | sí | transition observed=false | WooCommerce handler |
| transaction ID esperado | presupone worker | no | sí | completion observed=false | WooCommerce handler |
| fingerprint reconciled=true | lo escribe completion | no | sí | financial evidence persisted=true | repositories |
| reconciliation completed | presupone processor | no | sí | reconciliation status=pending | materializer |
| schedule status | antes implícito | sí | no | scheduled por fase | A7/repository |
| action ID | no materializar ID | sí | no | present=true | A7/repository |
| first delivery result | plano/ambiguo | sí | no | approved + durable_synchronized | service/A8 |
| replay result | ausente | sí | no | already_processed + durable_already_synchronized | service/A8 |
| single_application | objeto pago incorrecto | no | sí | initial_durable_authority | A4/A5/A3 |

## 19. Decisiones cerradas

```text
expected.actions.catalog.woocommerce_payment_completion = 0/0
durable_worker_execution = 0/0
PaymentReconciliationProcessor no pertenece a las fases WR-06
PaymentCompletionHandlerInterface::complete() no pertenece a las fases WR-06
la transición funcional de pago/pedido queda fuera del resultado temporal WR-06
replay no depende de que el worker haya ejecutado
single_application se refiere a initial_durable_authority generation 1
```

## 20. Implementabilidad normativa

Quedan cerrados catálogo completo de actions, shape y valores de result, objeto de `single_application`, procesamiento posterior, crash windows, replay, concurrencia y ausencia WooCommerce temporal.

La materialización futura debe copiar literalmente estos contratos; este documento no la realiza.

## 21. Veredicto principal

```text
A11-WR-06 RESULTADO Y SINGLE_APPLICATION IMPLEMENTABLES TRAS CORRECCIÓN NORMATIVA
```

## 22. Integridad

Esta corrección crea únicamente este archivo. Preserva documentos, código, pruebas, matriz, fixtures, configuración, schema, artifacts y staging. No realiza implementación, commit ni push.
