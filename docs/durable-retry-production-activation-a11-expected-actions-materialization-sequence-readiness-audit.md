# EA4 — Readiness y secuencia de materialización de `expected.actions`

## 1. Identificación

Auditoría documental y técnica que fija la secuencia mínima desde EA3 hasta
fixtures y validación runtime certificables.

## 2. Veredicto

`A11 EXPECTED ACTIONS MATERIALIZATION READY TRAS SECUENCIA OBLIGATORIA`.

## 3. Resumen ejecutivo

EA3 aporta autoridad suficiente: no falta semántica ni count. El árbol aún no
puede observar, ejecutar ni comparar esos values. Los cinco harnesses H1–H5 y sus
fixtures no existen; Runtime Capture transporta IDs/captures, no actions; nueve
casos requieren conducta productiva futura. Editar fixtures ahora produciría una
declaración imposible de certificar.

Orden obligatorio: captura, instrumentación, producto, harnesses, fixtures y
certificación. Cada fase es un microhito separado.

## 4. Alcance

Se comparan EA3, árbol físico, Runtime Capture, producto, harnesses previstos y
fixtures previstos. No se modifica ninguno.

## 5. Exclusiones

No se implementa, materializa, ejecuta estado, asigna IDs, crea runtime ni altera
counts. Esta auditoría no autoriza sus allowlists futuras.

## 6. Estado base

Verificado: main; HEAD `847879d509864c6ad077bfecb6dfa05537fbb899`;
0 behind/61 ahead; staging vacío; 17 tracked preexistentes; 547 untracked antes
de EA4; EA1/EA2/EA3 exactos; 8/8 protegidos; 13/13 R5; R2/R4 exactos; 504
artifacts; sin locks, runtime, PHP, temporales o manifest; accessor tipado uno.

## 7. Autoridades verificadas

EA1 cierra schema; EA2 documenta el vacío; EA3 cierra 372 values. También se
auditaron correcciones principal/complementaria/bootstrap/capture/fixtures/WR-06,
matriz 31, cinco crash decorators, A8–A10, Application, Webpay, materializer,
router, scheduler/coordinator/executor, orchestration/workers, procesadores,
cuatro archivos Runtime Capture y 13 R5.

## 8. Estado heredado de EA3

31 JSON, 372 integers, total 38; FD 31, replay 7. Puertos:
webpay 7, Woo 0, schedule 11, cancel 1, legacy 3, worker 16. Nueve casos exigen
cambio productivo; los otros 22 exigen observabilidad/certificación.

## 9. Método

Para cada caso: fixture físico → entrypoint/harness → captura → símbolo productivo
→ diferencia EA3 → dependencia y ruta exacta. Ausencia física no se interpreta
como fixture vacío certificable.

## 10. Definición de readiness

Un caso está ready solo si existen fixture, captura de seis puertos, flujo
compatible, harness que compara S0–S4 y prueba fail-closed. Autoridad documental
por sí sola no basta.

## 11. Regla para `editable_now`

`yes` exige escritura y certificación inmediata. Como captura/harnesses faltan,
los 31 son `no`, incluso cuando el JSON podría copiarse mecánicamente.

## 12. Inventario de fixtures

Fixtures A11 ejecutables actuales: 0. Forma EA1 actual: 0. Carecen de
`expected.actions`: 31 casos previstos. Incompatibles existentes: 0. Copiables
mecánicamente: 31; certificables ahora: 0; dependientes de producto: 9; de
captura y harness: 31; no editables ahora: 31.

La autoridad complementaria ubica los casos dentro de cinco archivos H1–H5; no
define un archivo fixture separado y prohíbe `DurableRetryA11Fixture.php`.

## 13. Inventario de harnesses

Existen cuatro componentes A11 Runtime Capture:
`tests/manual/support/durable-retry-a11-runtime-capture-contract.php`,
`tests/manual/support/durable-retry-a11-coordinator.php`,
`tests/manual/durable-retry-a11-runtime-capture-test.php` y
`tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php`.

No existen H1–H5, child worker ni HTTP stub. Por tanto no hay runner de 31 casos.

## 14. Inventario Runtime Capture

Actual: case ID, phase, capture aliases, stdin/stdout, timeout, process result,
S0–S4, hashes y fail-closed de capture values. Ausente: catálogo actions,
`recordAction`, ordinal, delegación iniciada, acumulación, fase cerrada,
deduplicación observada y comparación EA3.

## 15. Inventario productivo

Los seis símbolos existen como contratos/call sites, salvo que legacy scheduling
se observa mediante su interfaz histórica. No existe un observer común. Producto
actual no emite eventos EA1 y nueve flujos no garantizan todavía la secuencia EA3.

## 16. Comparación de 31 casos

Los 22 sin cambio productivo se clasifican `capture_required`: su primer bloqueo
es captura, seguida de harness/fixture. Los nueve con conducta futura se clasifican
`multiple_dependencies`: requieren captura estable y cambio productivo antes del
harness definitivo.

## 17. Matriz consolidada

| case_id | ea3_total_actions | fixture_has_expected_actions | fixture_values_match_ea3 | runtime_capture_supported | productive_flow_matches_ea3 | harness_supports_comparison | required_changes | dependency_order | readiness_category | editable_now |
|---|---:|:---:|:---:|:---:|:---:|:---:|---|---|---|:---:|
| A11-OP-01 | 3 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-OP-02 | 2 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-OP-03 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-OP-04 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-OP-05 | 3 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-CON-01 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-CON-02 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-CON-03 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-CON-04 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-CON-05 | 2 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-CR-01 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-CR-02 | 2 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-CR-03 | 2 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-CR-04 | 2 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-CR-05 | 2 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-WR-01 | 2 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-WR-02 | 2 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-WR-03 | 2 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-WR-04 | 2 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-WR-05 | 1 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-WR-06 | 2 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-01 | 1 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-EX-02 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-03 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-04 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-05 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-06 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-07 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-08 | 1 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |
| A11-EX-09 | 0 | no | no | no | yes | no | capture+harness+fixture | EA5→EA8→EA9 | capture_required | no |
| A11-EX-10 | 1 | no | no | no | no | no | capture+product+harness+fixture | EA5→EA8→EA9 with EA7 before EA8 | multiple_dependencies | no |

## 18. Auditoría de los seis puertos

| Puerto | Símbolo | Instrumentación | Captura actual | Brecha/riesgo | Fases |
|---|---|---|---|---|---|
| webpay.commit | `WebpayReturnGatewayInterface::commit()` | decorator inyectado en child/HTTP stub | ninguna | omisión o contar outcome | FD/R |
| woocommerce.payment_complete | `PaymentCompletionHandlerInterface::complete()` | decorator de puerto en child | ninguna | doble conteo con mutation | FD/R |
| scheduler.action_schedule | `DurableRetryExternalSchedulerInterface::schedule()` | decorator compartido | solo crash decorator futuro | contar fila/asociación | FD/R |
| scheduler.action_cancel | `DurableRetryExternalSchedulerInterface::cancel()` | mismo decorator | ninguna | confundir cleanup | FD/R |
| legacy.retry_schedule | `DurableRetryLegacySchedulerInterface` | decorator legacy | ninguna | confundir hook/callback | FD/R |
| durable.worker_execute | `DurableRetryExecutorInterface::execute()` | decorator executor | crash decorator previsto | contar processor/claim | FD/R |

Totalmente capturables ahora: 0. Parcialmente: 0. No capturables: 6.

## 19. Auditoría de Runtime Capture

Debe añadir catálogo exacto EA1, acumulador por execution/case/phase, registro
antes de delegar, cierre monotónico, integración en S2/S3, comparación canónica y
errores unknown/missing/count mismatch. Deduplicación y actions evitadas se
demuestran por counts cero, no mediante eventos inventados.

## 20. Auditoría de los cuatro componentes A11

| Archivo | Responsabilidad/entrada | S0–S4/stdin/stdout | Comparación actions | Cambio/allowlist |
|---|---|---|---|---|
| capture contract | schema, plan, store | sí | no | schema y acumulador; sí |
| coordinator | procesos y deltas | sí | no | record/close/assert; sí |
| functional test | 30 casos de contrato | indirecto | no | casos actions; sí |
| infrastructure test | child, timeout, 25 casos, integration-only | sí | no | transporte/actions/crash; sí |

Ninguno carga fixtures H1–H5. Los cuatro soportan fail-closed de captures, no los
doce counts.

## 21. Auditoría de fixtures

Los casos vivirán en H1–H5 conforme a la autoridad complementaria. Materialización
declarativa es mecánica después de EA8; certificación real depende de EA5–EA8.
No existe ruta separada de fixture autorizable. Crear una sería contradicción con
la prohibición del helper fixture.

## 22. Auditoría de los nueve casos productivos

| Caso | Diferencia | Símbolo/archivo mínimo | Tipo | Captura primero | Fixture antes |
|---|---|---|---|:---:|:---:|
| OP-05 | successor schedule y segundo execute en replay | coordinator/executor | fase+repetición | sí | no |
| CON-05 | cancel productivo y execute únicos | external coordinator/executor | concurrencia+cancel | sí | no |
| CR-02 | segundo execute en recovery | executor | fase+worker | sí | no |
| CR-03 | replay execute sin repetir efecto | executor/reconciliation processor | idempotencia+worker | sí | no |
| WR-03 | excepción post-routing no redelega | materializer/Webpay service | Webpay+dedup | sí | no |
| WR-04 | dos POST producen un commit/schedule | Webpay service/materializer | concurrencia+dedup | sí | no |
| EX-01 | exactamente un legacy schedule | orchestration/workers | legacy+fase | sí | no |
| EX-08 | callback durable gana exactamente una vez | orchestration/workers/executor | exclusión+worker | sí | no |
| EX-10 | identity histórica produce un legacy schedule | orchestration/workers | legacy+guardia | sí | no |

Instrumentar primero no consolida conducta errónea porque EA5 define contrato y
EA6 decorators neutrales; EA7 cambia conducta usando esa observación estable.

## 23. Auditoría específica WR-06

EA3 exige dos actions, ambas FD: commit=1 y schedule=1. El segundo POST abre replay
y todos sus counts son cero. El call graph puede producir commit y schedule, pero
no existe captura que demuestre comienzo/ausencia ni harness H4 que compare.
El fixture no se edita antes de EA5, EA6 y EA8. Primera dependencia: contrato y
coordinator Runtime Capture EA5. Payload/outcomes no reabren routing.

## 24. Dependencias

EA5 define el canal. EA6 instrumenta sin cambiar behavior. EA7 usa observación
estable para los nueve casos. EA8 crea ejecución y comparación. EA9 copia values
solo después de verdes técnicos. EA10 certifica. No hay ciclo ni autoridad faltante.

## 25. Orden de implementación

1. EA5 captura.
2. EA6 instrumentación neutral.
3. EA7 correcciones productivas.
4. EA8 harnesses.
5. EA9 fixtures embebidos.
6. EA10 certificación.

No se combinan: cada diff tiene responsabilidad y rollback verificables.

## 26. Microhitos propuestos

### EA5 — Action Capture Contract

Precondición EA3. Modifica cuatro archivos Runtime Capture. Pruebas functional,
infrastructure e integration-only. Acepta registro/cierre/comparación sin producto.
Veredicto: `A11 ACTION CAPTURE READY`.

### EA6 — Port Instrumentation

Precondición EA5. Crea child worker/HTTP stub y añade decorators neutrales al
coordinator. Pruebas seis puertos, exception-after-crossing y cero doble conteo.
Veredicto: `A11 ACTION PORTS OBSERVABLE`.

### EA7 — Productive Convergence

Precondición EA6. Corrige nueve casos sin fixtures. Pruebas unitarias/históricas
por símbolo y R5. Veredicto: `A11 PRODUCTIVE FLOWS CONVERGE TO EA3`.

### EA8 — H1–H5 Execution

Precondición EA7. Crea cinco harnesses, integra S0–S4/crash/recovery y usa
expectativas in-memory de EA3 aún no como fixture definitivo. Veredicto:
`A11 HARNESSES READY FOR FIXTURES`.

### EA9 — Fixture Materialization

Precondición EA8 verde. Inserta los 31 JSON literales en H1–H5, sin producto.
Pruebas 31/31 y aritmética 372. Veredicto: `A11 FIXTURES MATERIALIZED`.

### EA10 — Certification

Precondición EA9. No cambia archivos. Ejecuta H1–H5, 5/5 crash windows, Runtime
Capture, scheduler x3, 78 regressions e integridad. Veredicto:
`A11 EXPECTED ACTIONS CERTIFIED`.

## 27. Allowlists exactas

EA5:
- `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`
- `tests/manual/support/durable-retry-a11-coordinator.php`
- `tests/manual/durable-retry-a11-runtime-capture-test.php`
- `tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php`

EA6:
- `tests/manual/support/durable-retry-a11-coordinator.php`
- `tests/manual/support/durable-retry-a11-child-worker.php`
- `tests/manual/support/durable-retry-a11-http-webpay-stub.php`

EA7:
- `app/Modules/Payments/Service/WebpayReturnService.php`
- `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`
- `app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php`
- `app/Modules/Orders/Services/DurableRetryExecutor.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php`
- `app/Core/Application.php`

EA8:
- `tests/manual/durable-retry-a11-operational-acceptance-test.php`
- `tests/manual/durable-retry-a11-multiprocess-concurrency-test.php`
- `tests/manual/durable-retry-a11-crash-recovery-test.php`
- `tests/manual/durable-retry-a11-webpay-replay-test.php`
- `tests/manual/durable-retry-a11-legacy-exclusion-test.php`
- `tests/manual/support/durable-retry-a11-coordinator.php`
- `tests/manual/support/durable-retry-a11-child-worker.php`
- `tests/manual/support/durable-retry-a11-http-webpay-stub.php`

EA9:
- `tests/manual/durable-retry-a11-operational-acceptance-test.php`
- `tests/manual/durable-retry-a11-multiprocess-concurrency-test.php`
- `tests/manual/durable-retry-a11-crash-recovery-test.php`
- `tests/manual/durable-retry-a11-webpay-replay-test.php`
- `tests/manual/durable-retry-a11-legacy-exclusion-test.php`

EA10: allowlist vacía; solo lectura/ejecución.

## 28. Archivos protegidos

EA1–EA4, R2/R4, artifacts, vendor, schema/config, fixtures fuera de EA9, 13 R5 y
todo path no enumerado permanecen protegidos. En cada microhito, los paths de
otros microhitos también están protegidos.

## 29. Pruebas por microhito

EA5: lint 4, functional, infrastructure, integration-only. EA6: lint 3, seis
eventos, exceptions, no residues. EA7: lint 7, pruebas históricas de cada servicio,
allowlist y R5. EA8: lint 8, H1–H5 dry fixtures, 5 kills. EA9: JSON 31, 372 counts,
totales EA3 y H1–H5. EA10: suite conjunta, scheduler tres veces y 78/78.

## 30. Criterios de aceptación

Cada hito exige diff exacto, cero unknown event, counts canónicos, cleanup sin
residuos, hashes protegidos, staging vacío y ausencia de timeout. El siguiente no
comienza hasta veredicto verde del anterior.

## 31. Métricas

- fixture_ready: 0.
- capture_required: 22.
- productive_change_required: 0 como categoría primaria.
- harness_change_required: 0 como categoría primaria.
- multiple_dependencies: 9.
- blocked_by_missing_authority: 0.
- fixtures editables ahora: 0; no editables: 31.
- puertos capturables total/parcial/no: 0/0/6.
- archivos productivos potenciales: 7.
- archivos de harness/capture potenciales únicos: 11.
- archivos fixture potenciales: 5, embebidos H1–H5.
- microhitos posteriores: 6.

## 32. Riesgos

Editar fixture antes de observación, instrumentation que altere orden, decorators
duplicados, mezclar cleanup cancel, solapar EA7/EA8, aceptar conducta actual en vez
de EA3 y perder prefijo por crash. La separación de hitos reduce esos riesgos.

## 33. Condiciones para editar fixtures

EA5–EA8 verdes; seis puertos observables; nueve casos convergentes; H1–H5 capaces
de comparar S0–S4; allowlist EA9 exacta; hashes protegidos intactos.

## 34. Condiciones para certificación

EA9 31/31 y 372/372, H1–H5 completos, cinco ventanas reales, Runtime Capture
fail-closed, scheduler x3, 78/78, cero residuos y diff íntegro.

## 35. Integridad final

EA4 crea solo este documento. No modifica implementation, fixtures, harnesses,
EA1–EA3, tracked, R2/R4, R5, artifacts o configuración; no asigna IDs, runtime,
manifest, staging, commit ni push.

## 36. Veredicto definitivo

EA3 es autoridad suficiente y las rutas/secuencia son exactas. La materialización
no está bloqueada, pero no puede comenzar antes de completar EA5–EA8.

`A11 EXPECTED ACTIONS MATERIALIZATION READY TRAS SECUENCIA OBLIGATORIA`
