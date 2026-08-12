# EA3 — Corrección normativa case-specific de `expected.actions`

## 1. Identificación

Autoridad normativa definitiva de los 372 counts de los 31 casos A11.

## 2. Veredicto

`A11 EXPECTED ACTIONS CASE-SPECIFIC IMPLEMENTABLE`.

## 3. Resumen ejecutivo

EA3 reemplaza las indeterminaciones de EA2 mediante selección normativa explícita.
Declara 31 objetos JSON cerrados, una conducta temporal por caso y los cambios
futuros necesarios. No afirma que toda conducta ya exista: las filas marcadas
`requires_productive_change=yes` son requisitos implementables futuros.

## 4. Alcance

Solo se cierran los doce enteros EA1 por caso. No se implementan observadores,
producto, fixtures, IDs, manifests ni harnesses.

## 5. Exclusiones

Outcomes, filas, SQL, comandos H1–H5, cleanup, captures, aliases e IDs quedan fuera.

## 6. Estado base

Base certificada: main, HEAD `847879d509864c6ad077bfecb6dfa05537fbb899`,
0 behind/61 ahead, staging vacío, 17 tracked preexistentes, EA1 y EA2 exactos,
8/8 protegidos, 13/13 R5, R2/R4 exactos y 504 artifacts.

## 7. Autoridades heredadas

Rigen EA1 para schema y conteo; correcciones principal/complementaria para objetivos
H1–H5; Runtime Capture para S0–S4; producto para símbolos; EA2 para el vacío que
esta corrección resuelve. EA3 prevalece exclusivamente sobre los 372 valores.

## 8. Problema demostrado por EA2

Las autoridades anteriores daban outcomes y límites, no enteros por fase. EA3
selecciona una conducta única sin reinterpretar outcomes como calls.

## 9. Regla normativa de cierre

Cada valor es vinculante. Producto y harness futuros deben converger a estos
eventos; una diferencia es incumplimiento, no alternativa permitida.

## 10. Definición de acción

Cuenta al cruzar uno de los seis puertos EA1. Excepción posterior conserva el
count; aborto anterior vale cero.

## 11. Definición de fases

`first_delivery` abarca desde S1 hasta el límite del crash. `replay` comienza
en recovery y termina en S3. Los dos catálogos son independientes.

## 12. Definición de crash

Crash es límite, no evento. Una delegación cuyo símbolo ya fue invocado cuenta en
first delivery; la siguiente no iniciada vale cero.

## 13. Definición de recovery

Recovery abre replay y aplica evidencia durable antes de decidir una nueva
delegación. No repite automáticamente el prefijo.

## 14. Definición de cleanup

Todo cleanup queda fuera. Una cancelación cuenta solo en CON-05, donde es rama
productiva concurrente; cancelaciones de teardown valen cero.

## 15. Catálogo de puertos

| Key | Símbolo productivo |
|---|---|
| `webpay.commit` | `WebpayReturnGatewayInterface::commit()` |
| `woocommerce.payment_complete` | `PaymentCompletionHandlerInterface::complete()` |
| `scheduler.action_schedule` | `DurableRetryExternalSchedulerInterface::schedule()` |
| `scheduler.action_cancel` | `DurableRetryExternalSchedulerInterface::cancel()` |
| `legacy.retry_schedule` | `DurableRetryLegacySchedulerInterface scheduling boundary` |
| `durable.worker_execute` | `DurableRetryExecutorInterface::execute()` |

## 16. Reglas de conteo

Cada cruce lógico suma uno. Retorno, outcome, persistencia, asociación y fila no
suman. Los JSON contienen enteros literales y ceros obligatorios.

## 17. Reglas de multiplicidad

Dos cruces en una fase suman dos. Esta matriz selecciona multiplicidad uno por
evento y evita cruces duplicados mediante autoridad durable; ningún count excede
uno en EA3.

## 18. Reglas de deduplicación

Token/evidencia Webpay evita segundo commit; durable identity y action asociada
evitan segundo schedule; terminal/generation evita segundo processor; A3 excluye
legacy frente durable; pago ya aplicado evita completion repetido.

## 19. Reglas de excepción

La excepción después del cruce cuenta. OP-03 cuenta schedule; WR-03 cuenta el
prefijo first delivery. Excepciones A3 anteriores al puerto, como EX-05, no cuentan.

## 20. Reglas por crash window

| Ventana | Última delegación iniciada | Primera no iniciada | Recovery |
|---|---|---|---|
| H1 operacional | último puerto literal de FD | todo replay | replay solo cuando la fila lo declara |
| H2 concurrencia | único ganador catalogado | duplicado perdedor | no redelega |
| H3 external-created | schedule | segundo schedule | asocia evidencia |
| H3 claimed/post-attempt/result/return | primer execute | efecto posterior no catalogado | redelivery ejecuta segundo execute |
| H4 Webpay | commit o schedule según fila | toda redelegación deduplicada | usa evidencia; WR-05 schedule en replay |
| H5 exclusión | puerto legacy o durable literal | ruta excluida | no fallback |

## 21. Contrato prioritario WR-06

El primer POST es first delivery y entra por `WebpayReturnService`; inicia un
`webpay.commit` y un `scheduler.action_schedule`. El crash normativo ocurre
después de persistir y asociar la autoridad durable y antes del segundo POST. El
segundo POST abre replay por el mismo entrypoint, relee evidencia compatible y no
redelega commit, schedule, completion, legacy ni worker.

`woocommerce.payment_complete` vale cero porque WR-06 certifica routing y single
application sin ejecutar worker; payload y gateway_result determinan outcomes,
no counts. HTTP, barreras y POST son external_actions. Cleanup no cuenta.

JSON definitivo WR-06 es el declarado en §23.21. Idempotencia: token y fingerprint
reutilizan el return. Deduplicación: durable identity y action asociada cierran
segunda programación.

## 22. Contrato por puerto

`webpay.commit` comienza en la llamada a `commit()`; evidencia compatible evita
replay. `woocommerce.payment_complete` comienza en `complete()`; ningún caso EA3
lo alcanza. Schedule comienza en `schedule()`; pending/asociado evita repetición.
Cancel comienza en `cancel()` productivo y solo CON-05 lo usa. Legacy comienza al
delegar scheduling histórico y es mutuamente excluyente con durable. Worker
comienza en `execute()`; el proceso que sufre crash cuenta y la redelivery H3
constituye una segunda delegación en replay.

## 23. Registros de los 31 casos

### 23.1 A11-OP-01

- Categoría: operational.
- Objetivo: approved nuevo con ruta durable completa.
- Estado inicial: retorno nuevo y durable habilitado.
- Entrada productiva: HTTP return.
- Crash window y evento: sin crash; cierre tras callback.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `OP_NEW_DURABLE`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `OP_NEW_DURABLE`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-OP-01 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-OP-01; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=op_new_durable; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_NEW_DURABLE; count_contribution=1.
- event: case_id=A11-OP-01; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=op_new_durable; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_NEW_DURABLE; count_contribution=1.
- event: case_id=A11-OP-01; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=op_new_durable; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_NEW_DURABLE; count_contribution=1.

### 23.2 A11-OP-02

- Categoría: operational.
- Objetivo: ruta legacy con durable deshabilitado.
- Estado inicial: retorno nuevo y durable off.
- Entrada productiva: HTTP return.
- Crash window y evento: sin crash; cierre tras scheduling legacy.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `OP_LEGACY_ONLY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `OP_LEGACY_ONLY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-OP-02 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-OP-02; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=op_legacy_only; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_LEGACY_ONLY; count_contribution=1.
- event: case_id=A11-OP-02; phase=first_delivery; port=legacy.retry_schedule; ordinal=1; logical_event=op_legacy_only; productive_symbol=DurableRetryLegacySchedulerInterface scheduling boundary; delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_LEGACY_ONLY; count_contribution=1.

### 23.3 A11-OP-03

- Categoría: operational.
- Objetivo: fallo cerrado por scheduler indisponible.
- Estado inicial: autoridad durable lista y scheduler indisponible.
- Entrada productiva: publish.
- Crash window y evento: sin crash; excepción después de iniciar schedule.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `OP_SCHEDULER_UNAVAILABLE`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `OP_SCHEDULER_UNAVAILABLE`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-OP-03 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-OP-03; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=op_scheduler_unavailable; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_SCHEDULER_UNAVAILABLE; count_contribution=1.

### 23.4 A11-OP-04

- Categoría: operational.
- Objetivo: redelivery terminal sin doble worker.
- Estado inicial: action terminal.
- Entrada productiva: callback dos veces.
- Crash window y evento: sin crash; segunda entrega después de terminal.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `OP_TERMINAL_REDELIVERY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `OP_TERMINAL_REDELIVERY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-OP-04 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-OP-04; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=op_terminal_redelivery; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_TERMINAL_REDELIVERY; count_contribution=1.

### 23.5 A11-OP-05

- Categoría: operational.
- Objetivo: retryable crea sucesor y lo ejecuta.
- Estado inicial: generation 1 scheduled.
- Entrada productiva: callback y recovery.
- Crash window y evento: límite lógico tras resultado retryable.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `OP_RETRY_SUCCESSOR`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `OP_RETRY_SUCCESSOR`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  },
  "replay": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  }
}
```

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-OP-05 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-OP-05; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=op_retry_successor; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=OP_RETRY_SUCCESSOR; count_contribution=1.
- event: case_id=A11-OP-05; phase=replay; port=scheduler.action_schedule; ordinal=1; logical_event=op_retry_successor; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=OP_RETRY_SUCCESSOR; count_contribution=1.
- event: case_id=A11-OP-05; phase=replay; port=durable.worker_execute; ordinal=1; logical_event=op_retry_successor; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=OP_RETRY_SUCCESSOR; count_contribution=1.

### 23.6 A11-CON-01

- Categoría: concurrency.
- Objetivo: dos publish convergen en un schedule.
- Estado inicial: reconciliation fresca.
- Entrada productiva: dos publish liberados juntos.
- Crash window y evento: barrera antes del primer schedule.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CON_SINGLE_SCHEDULE`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CON_SINGLE_SCHEDULE`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CON-01 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CON-01; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=con_single_schedule; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CON_SINGLE_SCHEDULE; count_contribution=1.

### 23.7 A11-CON-02

- Categoría: concurrency.
- Objetivo: dos callbacks producen un worker.
- Estado inicial: schedule exacta.
- Entrada productiva: dos callbacks liberados juntos.
- Crash window y evento: barrera antes de claim.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CON_SINGLE_EXECUTOR`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CON_SINGLE_EXECUTOR`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CON-02 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CON-02; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=con_single_executor; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CON_SINGLE_EXECUTOR; count_contribution=1.

### 23.8 A11-CON-03

- Categoría: concurrency.
- Objetivo: duplicate generation converge sin puertos EA1.
- Estado inicial: sin generation 1.
- Entrada productiva: dos producers.
- Crash window y evento: barrera antes del insert interno.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CON_INTERNAL_GENERATION`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CON_INTERNAL_GENERATION`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CON-03 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.9 A11-CON-04

- Categoría: concurrency.
- Objetivo: generation vieja queda stale y actual ejecuta.
- Estado inicial: generation vieja y actual.
- Entrada productiva: dos callbacks.
- Crash window y evento: barrera antes de selección.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CON_CURRENT_GENERATION_ONLY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CON_CURRENT_GENERATION_ONLY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CON-04 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CON-04; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=con_current_generation_only; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CON_CURRENT_GENERATION_ONLY; count_contribution=1.

### 23.10 A11-CON-05

- Categoría: concurrency.
- Objetivo: cancel productivo compite con ejecución.
- Estado inicial: schedule pending.
- Entrada productiva: recovery cancel y callback.
- Crash window y evento: barrera antes de ambos puertos.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CON_CANCEL_EXECUTE`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CON_CANCEL_EXECUTE`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 1,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CON-05 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CON-05; phase=first_delivery; port=scheduler.action_cancel; ordinal=1; logical_event=con_cancel_execute; productive_symbol=DurableRetryExternalSchedulerInterface::cancel(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CON_CANCEL_EXECUTE; count_contribution=1.
- event: case_id=A11-CON-05; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=con_cancel_execute; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CON_CANCEL_EXECUTE; count_contribution=1.

### 23.11 A11-CR-01

- Categoría: crash.
- Objetivo: recuperar action externa creada sin repetir schedule.
- Estado inicial: dispatching.
- Entrada productiva: publish child.
- Crash window y evento: después de schedule y antes de asociación.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CR_EXTERNAL_CREATED`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CR_EXTERNAL_CREATED`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CR-01 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CR-01; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=cr_external_created; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CR_EXTERNAL_CREATED; count_contribution=1.

### 23.12 A11-CR-02

- Categoría: crash.
- Objetivo: recuperar worker muerto después de claim.
- Estado inicial: scheduled.
- Entrada productiva: callback child.
- Crash window y evento: después de iniciar executor y antes del intento.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CR_CLAIMED_REEXECUTE`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CR_CLAIMED_REEXECUTE`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  },
  "replay": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  }
}
```

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CR-02 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CR-02; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=cr_claimed_reexecute; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CR_CLAIMED_REEXECUTE; count_contribution=1.
- event: case_id=A11-CR-02; phase=replay; port=durable.worker_execute; ordinal=1; logical_event=cr_claimed_reexecute; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=CR_CLAIMED_REEXECUTE; count_contribution=1.

### 23.13 A11-CR-03

- Categoría: crash.
- Objetivo: releer evidencia y converger después del intento.
- Estado inicial: scheduled.
- Entrada productiva: callback child.
- Crash window y evento: después del efecto funcional y antes del resultado.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CR_POST_ATTEMPT_REEXECUTE`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CR_POST_ATTEMPT_REEXECUTE`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  },
  "replay": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  }
}
```

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CR-03 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CR-03; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=cr_post_attempt_reexecute; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CR_POST_ATTEMPT_REEXECUTE; count_contribution=1.
- event: case_id=A11-CR-03; phase=replay; port=durable.worker_execute; ordinal=1; logical_event=cr_post_attempt_reexecute; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=CR_POST_ATTEMPT_REEXECUTE; count_contribution=1.

### 23.14 A11-CR-04

- Categoría: crash.
- Objetivo: redelivery terminal no repite processor.
- Estado inicial: scheduled.
- Entrada productiva: callback child.
- Crash window y evento: después de persistir resultado y antes de retorno.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CR_POST_RESULT_REDELIVERY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CR_POST_RESULT_REDELIVERY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  },
  "replay": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  }
}
```

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CR-04 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CR-04; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=cr_post_result_redelivery; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CR_POST_RESULT_REDELIVERY; count_contribution=1.
- event: case_id=A11-CR-04; phase=replay; port=durable.worker_execute; ordinal=1; logical_event=cr_post_result_redelivery; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=CR_POST_RESULT_REDELIVERY; count_contribution=1.

### 23.15 A11-CR-05

- Categoría: crash.
- Objetivo: respuesta ausente converge por redelivery.
- Estado inicial: scheduled.
- Entrada productiva: callback child.
- Crash window y evento: después de executor y antes del retorno callback.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `CR_PRE_RETURN_REDELIVERY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `CR_PRE_RETURN_REDELIVERY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  },
  "replay": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
  }
}
```

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-CR-05 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-CR-05; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=cr_pre_return_redelivery; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=CR_PRE_RETURN_REDELIVERY; count_contribution=1.
- event: case_id=A11-CR-05; phase=replay; port=durable.worker_execute; ordinal=1; logical_event=cr_pre_return_redelivery; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=CR_PRE_RETURN_REDELIVERY; count_contribution=1.

### 23.16 A11-WR-01

- Categoría: webpay.
- Objetivo: token nuevo crea evidencia y schedule durable.
- Estado inicial: token nuevo.
- Entrada productiva: primer POST.
- Crash window y evento: sin crash; cierre después de publish.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `WR_NEW_TOKEN`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `WR_NEW_TOKEN`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-WR-01 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-WR-01; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=wr_new_token; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_NEW_TOKEN; count_contribution=1.
- event: case_id=A11-WR-01; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=wr_new_token; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_NEW_TOKEN; count_contribution=1.

### 23.17 A11-WR-02

- Categoría: webpay.
- Objetivo: replay inmediato reutiliza evidencia.
- Estado inicial: fixture fresco para primera entrega.
- Entrada productiva: primer POST y segundo POST.
- Crash window y evento: límite entre POST después de S2.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `WR_IMMEDIATE_REPLAY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `WR_IMMEDIATE_REPLAY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-WR-02 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-WR-02; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=wr_immediate_replay; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_IMMEDIATE_REPLAY; count_contribution=1.
- event: case_id=A11-WR-02; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=wr_immediate_replay; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_IMMEDIATE_REPLAY; count_contribution=1.

### 23.18 A11-WR-03

- Categoría: webpay.
- Objetivo: replay posterior a excepción A8 converge.
- Estado inicial: token nuevo.
- Entrada productiva: POST fallido y POST recovery.
- Crash window y evento: después de schedule y antes del response.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `WR_POST_ROUTING_EXCEPTION`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `WR_POST_ROUTING_EXCEPTION`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-WR-03 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-WR-03; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=wr_post_routing_exception; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_POST_ROUTING_EXCEPTION; count_contribution=1.
- event: case_id=A11-WR-03; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=wr_post_routing_exception; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_POST_ROUTING_EXCEPTION; count_contribution=1.

### 23.19 A11-WR-04

- Categoría: webpay.
- Objetivo: dos POST concurrentes producen una ruta.
- Estado inicial: token nuevo.
- Entrada productiva: dos POST liberados juntos.
- Crash window y evento: barrera antes de commit.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `WR_CONCURRENT_POST`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `WR_CONCURRENT_POST`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-WR-04 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-WR-04; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=wr_concurrent_post; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_CONCURRENT_POST; count_contribution=1.
- event: case_id=A11-WR-04; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=wr_concurrent_post; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_CONCURRENT_POST; count_contribution=1.

### 23.20 A11-WR-05

- Categoría: webpay.
- Objetivo: return existente se publica por recovery.
- Estado inicial: return durable sin routing.
- Entrada productiva: POST de inspección y recovery.
- Crash window y evento: límite antes de publish.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `WR_FOUND_RETURN_RECOVERY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `WR_FOUND_RETURN_RECOVERY`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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
    "scheduler.action_schedule": 1,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 0
  }
}
```

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-WR-05 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-WR-05; phase=replay; port=scheduler.action_schedule; ordinal=1; logical_event=wr_found_return_recovery; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=después de recovery; recovery_relation=evento nuevo de replay; deduplication_rule=WR_FOUND_RETURN_RECOVERY; count_contribution=1.

### 23.21 A11-WR-06

- Categoría: webpay.
- Objetivo: pedido Woo A11 y replay idempotente sin worker.
- Estado inicial: pedido y token nuevos; worker prohibido.
- Entrada productiva: primer POST y segundo POST.
- Crash window y evento: límite entre POST después de persistir schedule.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `WR_WOO_SINGLE_APPLICATION`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `WR_WOO_SINGLE_APPLICATION`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 1,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-WR-06 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-WR-06; phase=first_delivery; port=webpay.commit; ordinal=1; logical_event=wr_woo_single_application; productive_symbol=WebpayReturnGatewayInterface::commit(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_WOO_SINGLE_APPLICATION; count_contribution=1.
- event: case_id=A11-WR-06; phase=first_delivery; port=scheduler.action_schedule; ordinal=1; logical_event=wr_woo_single_application; productive_symbol=DurableRetryExternalSchedulerInterface::schedule(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=WR_WOO_SINGLE_APPLICATION; count_contribution=1.

### 23.22 A11-EX-01

- Categoría: exclusion.
- Objetivo: autoridad legacy conserva scheduling legacy.
- Estado inicial: A3 legacy.
- Entrada productiva: legacy orchestration.
- Crash window y evento: sin crash; cierre tras schedule legacy.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_LEGACY_AUTHORITY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_LEGACY_AUTHORITY`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-01 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-EX-01; phase=first_delivery; port=legacy.retry_schedule; ordinal=1; logical_event=ex_legacy_authority; productive_symbol=DurableRetryLegacySchedulerInterface scheduling boundary; delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=EX_LEGACY_AUTHORITY; count_contribution=1.

### 23.23 A11-EX-02

- Categoría: exclusion.
- Objetivo: autoridad durable excluye legacy.
- Estado inicial: A3 durable.
- Entrada productiva: legacy child.
- Crash window y evento: antes de todo puerto por exclusión.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_DURABLE_NOOP`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_DURABLE_NOOP`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-02 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.24 A11-EX-03

- Categoría: exclusion.
- Objetivo: durable scheduled se reutiliza.
- Estado inicial: generation y action durable.
- Entrada productiva: legacy child.
- Crash window y evento: antes de todo puerto por evidencia durable.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_ALREADY_SCHEDULED`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_ALREADY_SCHEDULED`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-03 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.25 A11-EX-04

- Categoría: exclusion.
- Objetivo: indeterminate cierra sin efectos.
- Estado inicial: A3 indeterminate.
- Entrada productiva: legacy child.
- Crash window y evento: antes de todo puerto por cierre.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_INDETERMINATE_NOOP`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_INDETERMINATE_NOOP`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-04 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.26 A11-EX-05

- Categoría: exclusion.
- Objetivo: excepción A3 propaga sin fallback.
- Estado inicial: A3 throws.
- Entrada productiva: legacy child.
- Crash window y evento: excepción antes de todo puerto.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_READ_EXCEPTION`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_READ_EXCEPTION`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-05 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.27 A11-EX-06

- Categoría: exclusion.
- Objetivo: durable consumed queda inmutable.
- Estado inicial: durable consumed.
- Entrada productiva: legacy redelivery.
- Crash window y evento: antes de todo puerto por terminal.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_CONSUMED_NOOP`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_CONSUMED_NOOP`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-06 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.28 A11-EX-07

- Categoría: exclusion.
- Objetivo: callback legacy previo queda no-op tras transfer.
- Estado inicial: legacy action y luego durable.
- Entrada productiva: legacy callback.
- Crash window y evento: antes de executor por exclusión.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_STALE_LEGACY_CALLBACK`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_STALE_LEGACY_CALLBACK`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-07 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.29 A11-EX-08

- Categoría: exclusion.
- Objetivo: callbacks concurrentes dejan una ejecución durable.
- Estado inicial: legacy y durable callbacks.
- Entrada productiva: dos children liberados juntos.
- Crash window y evento: barrera antes de exclusión/execute.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_SINGLE_DURABLE_EXECUTOR`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_SINGLE_DURABLE_EXECUTOR`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 0,
    "durable.worker_execute": 1
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-08 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-EX-08; phase=first_delivery; port=durable.worker_execute; ordinal=1; logical_event=ex_single_durable_executor; productive_symbol=DurableRetryExecutorInterface::execute(); delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=EX_SINGLE_DURABLE_EXECUTOR; count_contribution=1.

### 23.30 A11-EX-09

- Categoría: exclusion.
- Objetivo: replay de decisión durable no degrada a legacy.
- Estado inicial: autoridad durable persistida.
- Entrada productiva: publish y legacy child.
- Crash window y evento: límite después de autoridad durable.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_DURABLE_REPLAY_NOOP`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_DURABLE_REPLAY_NOOP`.
- Conducta actual/requerida: coincide normativamente con el flujo; requiere instrumentación para observarlo.
- Cambio futuro: solo instrumentation y fixture.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-09 de las matrices principal/complementaria y a los símbolos §15.
- Eventos positivos: ninguno; el flujo cierra antes de los seis puertos en ambas fases.

### 23.31 A11-EX-10

- Categoría: exclusion.
- Objetivo: identity histórica sin gen1 continúa legacy.
- Estado inicial: A3 legacy sin generation 1.
- Entrada productiva: legacy child.
- Crash window y evento: sin crash; cierre tras schedule legacy.
- Prefijo first delivery: exactamente las delegaciones no cero del JSON.
- No iniciadas: todas las keys con cero en first delivery.
- Estado durable: evidencia necesaria para aplicar `EX_HISTORICAL_LEGACY`.
- Recovery: abre replay y ejecuta solo las delegaciones no cero de ese mapa.
- Prefijo replay: exactamente las delegaciones no cero del JSON.
- Deduplicadas/evitadas: todas las redelegaciones con cero.
- Excluidas: outcomes, comandos del harness y cleanup.
- Regla normativa: `EX_HISTORICAL_LEGACY`.
- Conducta actual/requerida: requiere cambio productivo futuro para fijar orden, guardia o fase.
- Cambio futuro: sí, limitado a la guardia o separación temporal indicada.
- Justificación de ceros: Las restantes keys valen cero por fase no alcanzada, puerto de otra ruta, evidencia durable, deduplicación o exclusión contractual; outcomes, external_actions y cleanup no sustituyen el puerto.

```json
{
  "first_delivery": {
    "webpay.commit": 0,
    "woocommerce.payment_complete": 0,
    "scheduler.action_schedule": 0,
    "scheduler.action_cancel": 0,
    "legacy.retry_schedule": 1,
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

Trazabilidad count por count: las doce posiciones se vinculan a EA1 §§8, 14 y 17,
a la fila A11-EX-10 de las matrices principal/complementaria y a los símbolos §15.
- event: case_id=A11-EX-10; phase=first_delivery; port=legacy.retry_schedule; ordinal=1; logical_event=ex_historical_legacy; productive_symbol=DurableRetryLegacySchedulerInterface scheduling boundary; delegation_start=al invocar el símbolo; crash_relation=antes o en el límite declarado; recovery_relation=evidencia se conserva; deduplication_rule=EX_HISTORICAL_LEGACY; count_contribution=1.

## 24. Objetos JSON case-specific

Los 31 bloques de §23 son los únicos objetos normativos. Cada uno contiene doce
counts, seis por fase, con orden EA1 exacto.

## 25. Tabla global de 372 counts

| case_id | FD.W | FD.Woo | FD.S | FD.C | FD.L | FD.D | R.W | R.Woo | R.S | R.C | R.L | R.D | total_actions | requires_productive_change | normative_rule |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|:---:|---|
| A11-OP-01 | 1 | 0 | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 3 | no | OP_NEW_DURABLE |
| A11-OP-02 | 1 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | no | OP_LEGACY_ONLY |
| A11-OP-03 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | no | OP_SCHEDULER_UNAVAILABLE |
| A11-OP-04 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | no | OP_TERMINAL_REDELIVERY |
| A11-OP-05 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 1 | 0 | 0 | 1 | 3 | yes | OP_RETRY_SUCCESSOR |
| A11-CON-01 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | no | CON_SINGLE_SCHEDULE |
| A11-CON-02 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | no | CON_SINGLE_EXECUTOR |
| A11-CON-03 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | CON_INTERNAL_GENERATION |
| A11-CON-04 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | no | CON_CURRENT_GENERATION_ONLY |
| A11-CON-05 | 0 | 0 | 0 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | yes | CON_CANCEL_EXECUTE |
| A11-CR-01 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | no | CR_EXTERNAL_CREATED |
| A11-CR-02 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 1 | 2 | yes | CR_CLAIMED_REEXECUTE |
| A11-CR-03 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 1 | 2 | yes | CR_POST_ATTEMPT_REEXECUTE |
| A11-CR-04 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 1 | 2 | no | CR_POST_RESULT_REDELIVERY |
| A11-CR-05 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 1 | 2 | no | CR_PRE_RETURN_REDELIVERY |
| A11-WR-01 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | no | WR_NEW_TOKEN |
| A11-WR-02 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | no | WR_IMMEDIATE_REPLAY |
| A11-WR-03 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | yes | WR_POST_ROUTING_EXCEPTION |
| A11-WR-04 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | yes | WR_CONCURRENT_POST |
| A11-WR-05 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 1 | no | WR_FOUND_RETURN_RECOVERY |
| A11-WR-06 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 | no | WR_WOO_SINGLE_APPLICATION |
| A11-EX-01 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | yes | EX_LEGACY_AUTHORITY |
| A11-EX-02 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_DURABLE_NOOP |
| A11-EX-03 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_ALREADY_SCHEDULED |
| A11-EX-04 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_INDETERMINATE_NOOP |
| A11-EX-05 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_READ_EXCEPTION |
| A11-EX-06 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_CONSUMED_NOOP |
| A11-EX-07 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_STALE_LEGACY_CALLBACK |
| A11-EX-08 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | yes | EX_SINGLE_DURABLE_EXECUTOR |
| A11-EX-09 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | no | EX_DURABLE_REPLAY_NOOP |
| A11-EX-10 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | yes | EX_HISTORICAL_LEGACY |

## 26. Justificación de ceros

Todo cero responde a una causa cerrada declarada en su registro: flujo no alcanza
el puerto, condición falsa, operación consumada, evidencia durable, deduplicación,
otra ruta, operación interna, outcome, external_action, cleanup, crash anterior o
fase sin esa action. No hay ceros por silencio.

## 27. Eventos con count mayor que cero

Cada evento positivo está registrado bajo su caso con los once campos requeridos.
No usa IDs runtime. Ordinal expresa solo orden lógico y toda contribución vale uno.

## 28. Cambios productivos futuros

Requieren cambio productivo 9 casos: A11-OP-05, A11-CON-05, A11-CR-02, A11-CR-03, A11-WR-03, A11-WR-04, A11-EX-01, A11-EX-08, A11-EX-10.
Los cambios son guardias idempotentes, ganador único concurrente, cancelación
productiva o separación first delivery/replay. Los demás requieren instrumentation,
no alteración funcional.

## 29. Cambios futuros en fixtures

Los 31 fixtures deberán copiar literalmente su JSON §23, declarar el crash boundary
y separar external_actions. No se editan durante EA3.

## 30. Allowlist futura propuesta

Producto potencial: `WebpayReturnService.php`, `WebpayReconciliationMaterializer.php`,
`DurableRetryExternalScheduleCoordinator.php`, `DurableRetryExecutor.php`,
`DurableCompletionOrchestration.php` y `DurableCompletionWorkers.php`, solo para
casos §28 y bajo autorización nueva. Instrumentation: capture contract, coordinator
y cuatro harnesses A11. Fixtures: únicamente los archivos exactos que una futura
matriz autorice. Esta lista no autoriza cambios ahora.

## 31. Validaciones aritméticas

- Casos únicos: 31.
- Objetos JSON: 31.
- Counts por caso: 12.
- Counts totales: 372.
- Counts indeterminados: 0.
- Keys omitidas, aliases, IDs runtime y proyecciones external_actions: 0.
- Suma total: 38.
- Suma first delivery: 31.
- Suma replay: 7.
- Sumas por puerto: webpay.commit=7, woocommerce.payment_complete=0, scheduler.action_schedule=11, scheduler.action_cancel=1, legacy.retry_schedule=3, durable.worker_execute=16.
- Casos cero actions: 8.
- Solo first delivery: 17.
- Solo replay: 1.
- Ambas fases: 5.
- Requieren cambio productivo: 9.

## 32. Validaciones JSON

Cada bloque debe parsear como objeto, tener fases y keys exactas en orden, doce
integers en rango EA1 y ninguna key adicional. La validación automática de cierre
es condición de vigencia.

## 33. Riesgos

Materialización divergente, instrumentation por debajo del puerto, doble conteo
de outcome, mezcla con cleanup, pérdida de prefijo en crash y aceptación de keys
futuras. Se mitigan con copia literal, decorators de puerto y comparación cerrada.

## 34. Condiciones para materialización

Validar 31/31 JSON, implementar observación EA1, aplicar cambios §28, certificar
crash/recovery, conservar hashes protegidos y ejecutar regresión total. Solo
después se editan fixtures.

## 35. Integridad final

EA3 crea exclusivamente este documento. No modifica producto, pruebas, harnesses,
fixtures, EA1, EA2, R2, R4, artifacts ni configuración; no crea IDs, manifest o
runtime; no hace staging, commit o push.

## 36. Veredicto definitivo

Los 31 casos tienen doce enteros literales, los 372 counts están cerrados, WR-06
es definitivo y las diferencias futuras están identificadas.

`A11 EXPECTED ACTIONS CASE-SPECIFIC IMPLEMENTABLE`
