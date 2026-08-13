# Auditoría de readiness de WooCommerce payment completion para A11-WR-06

## 1. Propósito

Esta auditoría responde si `A11-WR-06` puede exigir actualmente una invocación de `PaymentCompletionHandlerInterface::complete()` durante `first_delivery`, sin ejecutar el durable worker ni vulnerar A8–A11.

## 2. Alcance y método

Se inspeccionaron autoridades productivas, separadas de tests, documentos e inferencias. No se implementa solución, fixture, manifest ni catálogo definitivo de `expected.actions`.

La pregunta binaria se responde **NO**: no existe una ruta productiva autorizada que ejecute `PaymentReconciliationProcessor` antes de retornar la primera devolución sin pasar por `DurableRetryActionCallback::execute()`.

## 3. Autoridades productivas

| Autoridad | Archivo y líneas | Responsabilidad |
|---|---|---|
| `PaymentCompletionHandlerInterface::complete()` | `app/Modules/Payments/Reconciliation/Contracts/PaymentCompletionHandlerInterface.php:12–22` | puerto de completion funcional |
| `PaymentCompletionHandlerRegistry::complete()` | `app/Modules/Payments/Reconciliation/Service/PaymentCompletionHandlerRegistry.php:17–86` | selecciona una implementación y delega |
| `WooCommercePaymentCompletionHandler::complete()` | `app/Modules/Payments/WooCommerce/WooCommercePaymentCompletionHandler.php:18–230` | única implementación productiva concreta encontrada; aplica/verifica WooCommerce |
| `PaymentReconciliationProcessor::process()` | `app/Modules/Payments/Reconciliation/Service/PaymentReconciliationProcessor.php:25–229` | único call site productivo de `completionHandler->complete()` en líneas 160–165 |
| `WebpayReturnService::process()` | `app/Modules/Payments/Service/WebpayReturnService.php:37–160` | procesa devolución y llama gateway `commit()` en 98–100 |
| `WebpayReturnService::repeated()` | mismo archivo, líneas 248–296 | replay sin nuevo gateway; usa materializer |
| `WebpayReturnService::finalize()` | mismo archivo, líneas 298–339 | persiste resultado e invoca materialización |
| `WebpayReconciliationMaterializer` | `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php:25–259` | materializa/reutiliza y publica candidato privado en 221–259 |
| `DurableRetryInitialProductionRouter::routeReconciliation()` | `app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php:17–105` | A8 transfiere/resuelve/coordina; no procesa |
| `DurableRetryExternalSchedulerInterface::schedule()` | `app/Modules/Orders/Contracts/DurableRetryExternalSchedulerInterface.php:9–23` | puerto de programación externa |
| `DurableRetryActionCallback::execute()` | `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionCallback.php:12–51` | entrada productiva al executor |
| `DurableRetryExecutor::execute()` | `app/Modules/Orders/Services/DurableRetryExecutor.php:26–177` | claim durable, resolución del processor y ejecución en 83–126 |
| `DurableRetryReconciliationProcessor::process()` | `app/Modules/Orders/Services/DurableRetryReconciliationProcessor.php:21–73` | adquiere lease y llama attempt processor en línea 56 |

No se encontró otra implementación productiva de `PaymentCompletionHandlerInterface`, aparte del registry delegante y `WooCommercePaymentCompletionHandler`, ni otro call site productivo de `complete()`.

## 4. Evidencia secundaria

Los tests que observan `woocommerce_pre_payment_complete`, doubles de interfaces y callbacks demuestran testabilidad, no autorizan un call graph nuevo. La corrección de fixtures §23.3 define documentalmente `single_application`; no es código de ejecución. Los nombres y comentarios no se usaron como autoridad suficiente.

## 5. Call graph real de first delivery

1. `WebpayReturnService::process()` recibe la devolución.
2. Resuelve `WebpayReturnGatewayInterface` y ejecuta `commit(token)`.
3. `finalize()` completa la persistencia de `webpay_returns`.
4. `WebpayReconciliationMaterializer::materialize()` persiste/reutiliza evidencia financiera y `payment_reconciliations`.
5. Su método privado `publishRetryAuthorityCandidate()` llama A8.
6. `DurableRetryInitialProductionRouter::routeReconciliation()` ejecuta A3–A6.
7. A7 llama `DurableRetryExternalSchedulerInterface::schedule()`.
8. El coordinador asocia el action ID a generation 1.
9. A8 retorna `durable_synchronized`.
10. El materializer, `finalize()` y `process()` retornan el resultado de `first_delivery`.

Este grafo **no alcanza** `PaymentReconciliationProcessor` ni `PaymentCompletionHandlerInterface::complete()`.

## 6. Call graph de procesamiento durable

1. Action Scheduler invoca el hook registrado.
2. `DurableRetryActionCallback::execute()` valida identidad y llama al executor.
3. `DurableRetryExecutor::execute()` lee generation, verifica estado `scheduled`, hace claim y resuelve stage processor.
4. `DurableRetryReconciliationProcessor::process()` adquiere lease de reconciliación.
5. Llama `PaymentReconciliationAttemptProcessorInterface::process()`.
6. La implementación `PaymentReconciliationProcessor::process()` valida evidencia y llama `PaymentCompletionHandlerInterface::complete()`.
7. El handler WooCommerce inspecciona, aplica o converge, y persiste fingerprint.
8. El processor hace CAS del estado de reconciliación y el executor consume o clasifica el schedule.

Esta es la única ruta productiva encontrada hacia completion.

## 7. Autoridad exclusiva de ejecución

La autoridad actual es **el durable worker**. First delivery solo materializa y programa. No existe método en Webpay return, materializer o A8 que invoque el processor.

No se acepta “ambos”: no hay regla productiva que asigne una lease única entre una ejecución síncrona hipotética y el callback, que cancele/consuma el schedule, o que impida carreras y doble invocación.

## 8. Compatibilidad A10/A11 de una ejecución síncrona hipotética

| Propiedad | Estado | Autoridad |
|---|---|---|
| publicación permanece privada | posible pero no autorizado | materializer 221–259 |
| una única programación durable | indeterminado | A8 programa, no conoce ejecución síncrona |
| impedir aplicación posterior | indeterminado | no existe handoff/consume síncrono |
| preservar schedule/generation/ownership | indeterminado | processor opera lease de reconciliación, executor posee schedule |
| cinco crash windows A11 | incompatible/no demostrado | ventanas presuponen callback/executor durable |
| replay sin gateway | preservado por flujo actual | `repeated()` 248–296 |
| replay sin nueva programación | preservado por autoridad durable actual | A8 existing/scheduled |
| `single_application` | no demostrado con dos ejecutores | falta exclusión entre síncrono y worker |

Autorizar una llamada síncrona no es un simple observable: introduciría una segunda autoridad de procesamiento.

## 9. Persistencia y replay

`WebpayReturnService::repeated()` relee `result_json`; `WebpayReconciliationMaterializer::resume()` relee resultado financiero y reconciliación. Esto evita un nuevo gateway commit y una nueva reconciliación.

La evidencia Webpay no demuestra por sí sola el efecto WooCommerce. El handler usa tres marcas: `COMPLETION_STARTED_META`, `COMPLETION_ENTERED_META` y `RECONCILED_FINGERPRINT_META`, además de `is_paid()`, transaction ID y date paid (`WooCommercePaymentCompletionHandler.php:334–401`). Estas marcas permiten clasificar efecto previo, intento entrado, resultado incompatible o resultado ya durable.

## 10. Autoridades de transición de pago y pedido

`WooCommercePaymentCompletionHandler::invokePaymentComplete()` (`404–471`) registra entrada mediante el hook `woocommerce_pre_payment_complete` y llama `$order->payment_complete($reference)` en línea 459. WooCommerce realiza pago, transaction ID y fecha pagada como efecto agregado.

Después, `persistVerifiedFingerprint()` (`473–529`) guarda y relee la marca reconciliada. Los `save()` auxiliares son persistencia de evidencia; no constituyen otra action contractual.

## 11. Semántica de `single_application`

La autoridad documental en `durable-retry-production-activation-a11-fixture-contract-normative-correction.md:375–378` define `single_application` como una aplicación funcional única en la primera entrega y convergencia sin segunda aplicación. Cuenta la **transición funcional persistida**, no una cantidad de invocaciones del puerto.

Consecuencias:

- Una invocación puede producir cero transiciones si `inspect()` encuentra `same` y converge.
- Una transición previa puede ser reconocida sin una nueva invocación a `payment_complete()`, aunque el puerto `complete()` sí haya sido llamado para inspeccionar/converger.
- Una excepción posterior a `payment_complete()` puede dejar efecto aplicado; el handler la captura y relee.
- Replay evita reaplicar mediante estado WooCommerce, transaction ID y fingerprints.
- `STARTED` demuestra preparación; `ENTERED` demuestra entrada al efecto; `RECONCILED` más estado pagado y referencia compatible demuestran aplicación verificada.

Por ello `single_application` no autoriza inferir `complete()` exactamente una vez.

## 12. Acción observable

El puerto real es `PaymentCompletionHandlerInterface::complete()`, no un interface llamado “WooCommerce payment completion”. Para el origen WooCommerce, el nombre lógico candidato `woocommerce_payment_completion` representa razonablemente esa invocación productiva porque el registry selecciona el handler por origen.

No se incorpora al fixture. Su conteo actual dentro del alcance temporal WR-06 sería `0/0`; el resultado funcional antecedente exige una transición que ocurriría después, mediante el worker.

## 13. Conteos candidatos

| Alternativa | first_delivery | replay | Compatible con flujo actual | Compatible con resultado WR-06 | Autoridad suficiente |
|---|---:|---:|---|---|---|
| Mantener observable WooCommerce | 0 | 0 | sí | no, si exige pago dentro de las fases | parcial |
| Ejecutar processor sincrónicamente | 1 esperada, no garantizada | 0 esperada | no | pretendidamente sí | no |
| Retirar observable y conservar transición | sin clave | sin clave | sí para actions | no resuelve cuándo ocurre transición | no |
| Sustituir observable por otro puerto | indeterminado | indeterminado | indeterminado | indeterminado | no |

No existe base productiva para `1/0`.

## 14. Pruebas adversariales conceptuales

`FD/R` cuenta invocaciones de `complete()` atribuibles a cada fase; `T` transiciones funcionales efectivas.

| # | Escenario | Invocaciones | T | FD/R | Riesgo doble | Evidencia resolutiva |
|---:|---|---:|---:|---:|---|---|
| 1 | complete termina; crash antes de responder | 1 | 1 | fuera de FD / 0 | bajo con worker único | paid+reference+fingerprints |
| 2 | pago persiste; transición de pedido falla | 1 | parcial/incierta | fuera de FD / 0 | medio | inspección WooCommerce |
| 3 | pedido persiste; fingerprint falla | 1 | 1 | fuera de FD / 0 | invocación repetible, efecto no | paid+reference; marker incompleto |
| 4 | schedule antes de complete | 0 antes del callback | 0 | 0/0 | normal | fila scheduled |
| 5 | complete antes de schedule | ruta inexistente | indeterminado | indeterminado | alto | ninguna autoridad |
| 6 | AS retorna ID; asociación falla | 0 | 0 | 0/0 | coordinación incierta | AS identity+fila dispatching |
| 7 | worker tras finalización síncrona | potencial 2 | 1 o incierta | 1/0 hipotético | alto | falta handoff durable |
| 8 | replay antes del worker | 0 | 0 | 0/0 | ninguno aún | evidencia Webpay+schedule pending |
| 9 | replay después del worker | 1 previa fuera de fase | 1 | 0/0 | bajo | completed+Woo fingerprint |
| 10 | dos devoluciones concurrentes | 0 antes del worker | 0 | 0/0 | schedule converge | fingerprint/recon/authority durable |
| 11 | complete llamado dos veces | 2 | 1 | fuera de fases | efecto converge, action duplica | inspect=`same` |
| 12 | processor éxito sin complete nuevo | posible por estado previo solo tras call graph previo | 0 nueva | 0/0 | bajo | reconciliation completed |
| 13 | outcome incierto tras efecto | 1 | 1 posible | fuera de FD | replay de invocación posible | ENTERED/paid/reference |
| 14 | evidencia financiera sin Woo effect | 0 | 0 | 0/0 | ninguno | ausencia paid/fingerprints |
| 15 | action pending tras pago síncrono | 1 hipotética | 1 | 1/0 hipotético | alto | schedule sigue scheduled |

Los escenarios 5, 7 y 15 demuestran que una ejecución síncrona futura no puede asumirse compatible con A11 sin contrato nuevo de autoridad y crash recovery.

## 15. Primer bloqueo

```text
case: A11-WR-06
category: expected
field: expected.actions.catalog.woocommerce_payment_completion
reason: el único call graph productivo hacia PaymentCompletionHandlerInterface::complete()
        pasa por DurableRetryActionCallback, executor y worker; first_delivery
        solo persiste y programa, pero WR-06 simultáneamente prohíbe ejecutar el
        worker y exige una aplicación funcional única dentro de la primera entrega
required_authority: decisión normativa que alinee el resultado temporal de WR-06
                    con procesamiento exclusivamente durable, eliminando la
                    exigencia de transición WooCommerce dentro de first_delivery
```

## 16. Recomendación normativa mínima

Se recomienda únicamente la opción **D. Cambiar el resultado esperado de WR-06 para que no dependa de una transición que el flujo del caso no ejecuta**.

Consecuencias:

- `expected.actions`: `woocommerce_payment_completion` puede fijarse `0/0` si permanece en el catálogo como prohibición temporal.
- `expected.result`: debe observar estado previo/no aplicado durante first delivery y replay, no pedido pagado.
- `single_application`: debe moverse fuera del alcance WR-06 o redefinirse como aplicación posterior por el único worker durable; no puede seguir exigiendo aplicación en first delivery.
- Cinco crash windows: permanecen bajo callback/executor durable, sin una sexta ventana síncrona.
- Replay: sigue sin gateway y sin nueva programación; puede llegar antes del procesamiento.
- Worker posterior: conserva autoridad exclusiva y puede aplicar una vez fuera de las dos fases WR-06.

No se recomienda A porque crea doble autoridad; B aislada contradice el resultado vigente; C no resuelve la transición exigida.

## 17. Veredicto principal

```text
WOOCOMMERCE PAYMENT COMPLETION WR-06 REQUIERE CAMBIO NORMATIVO DE OBSERVABLE
```

No se determina `1/0`. El `0/0` describe el call graph actual dentro de las fases, pero todavía contradice el resultado y `single_application` vigentes; por eso no puede cerrar el catálogo.

## 18. Integridad

Esta auditoría es probatoria y fail-closed. Crea únicamente este documento, no modifica autoridades, tests, fixtures, manifest, `expected.result`, `fixture_ids`, schema, configuración o artifacts. No realiza staging, commit ni push.
