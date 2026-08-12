# EA2 — Autoridad de materialización de `expected.actions`

## 1. Identificación del microhito

Auditoría documental de readiness EA2 para determinar si pueden materializarse
los 372 counts de `expected.actions` de los 31 casos A11. Esta auditoría no
implementa, no crea fixtures y no asigna IDs ni valores por intuición.

## 2. Veredicto

`A11 EXPECTED ACTIONS BLOQUEADO POR CONTEOS INDETERMINADOS`.

## 3. Resumen ejecutivo

EA1 cierra perfectamente el schema global, pero ninguna autoridad existente
publica una traza por caso que identifique conjuntamente puerto EA1, comienzo de
delegación, fase y multiplicidad. Las matrices A11 expresan resultados, filas,
máximos y comandos de harness. Esa evidencia no satisface la regla probatoria de
EA2 para convertir un campo en 0, 1 o un entero mayor.

Resultado conservador verificable: 0 casos totalmente determinados, 0
parcialmente determinados y 31 indeterminados; 0/372 counts determinados y
372/372 indeterminados. No puede editarse ningún fixture.

## 4. Alcance

Se auditan exclusivamente los doce counts de cada caso: seis puertos en
`first_delivery` y seis en `replay`. Se reconstruyen flujo productivo, ventanas,
reentrada y deduplicación hasta el límite que permiten las autoridades.

## 5. Exclusiones

No se evalúan values, IDs, rows, mutations, payloads ni outcomes salvo como
contexto. No se convierten SQL, snapshots, comandos, cleanup, resultados A4–A8,
filas ni estados terminales en actions.

## 6. Estado base

Base verificada: `main`, HEAD
`847879d509864c6ad077bfecb6dfa05537fbb899`, divergencia `0 behind / 61 ahead`,
staging vacío, 17 cambios tracked preexistentes, EA1 exacto, 8/8 hashes A11 y
13/13 hashes R5. R2 y R4 coinciden. `artifacts/` contiene 504 archivos; no hay
`.git/index.lock`, `.a11-runtime`, PHP residual, temporales ni manifest runtime.

## 7. Fuentes auditadas

- `docs/durable-retry-production-activation-a11-normative-correction.md`, matriz
  §§357–387: outcomes y máximos de OP/CON/CR/WR/EX.
- `docs/durable-retry-production-activation-a11-complementary-normative-correction.md`,
  §§391–421: entradas, procesos, invariantes y prohibiciones H1–H5.
- correcciones A11 de bootstrap, fixture contract y Runtime Capture Transport,
  especialmente el ciclo S0–S4 y fases setup/first_delivery/replay/assertions.
- EA1, §§7–31: definición, seis puertos, conteo y snapshots.
- auditoría/correcciones WR-06 de actions, routing, result, WooCommerce, rows,
  payload y materialización final.
- producto: `WebpayReturnService::handle()`/gateway `commit`,
  `WebpayReconciliationMaterializer::materialize()`/`resume()`,
  `DurableRetryExternalScheduleCoordinator`, `DurableRetryExecutor`, adapter
  Action Scheduler, router A9, orchestration y workers Durable Completion.
- harnesses Runtime Capture y R5 como evidencia, nunca como fuente de nuevos
  counts.

## 8. Contrato heredado desde EA1

Schema único:

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

Los ceros son datos probatorios, no defaults. Una key no puede materializarse en
cero solo porque la matriz histórica no mencione ese puerto.

## 9. Método de reconstrucción

Por caso se buscó: entrypoint → ramas productivas → punto exacto de cada puerto →
barrera/crash → recovery → replay → deduplicación. Para asignar un count debía
existir una cláusula case-specific que fijara fase y número de delegaciones.

Los términos “acción”, “schedule”, “commit”, “process” o “efecto” anteriores a
EA1 no se mapearon automáticamente a una key EA1.

## 10. Regla probatoria

Un count exige prueba conjunta de: puerto, delegación iniciada, fase,
multiplicidad, separación de outcome/interno/external/cleanup, pertenencia a esa
fase y tratamiento de reentrada. Falta cualquiera: `?`.

`≤1`, “máximo 1”, “total 1”, una fila, estado final o prohibición de duplicado no
prueban por sí solos un count exacto por fase.

## 11. Definición de fase

First delivery comienza después de S1 y termina al retorno/crash previo a S2.
Replay comienza en recovery con S2 y termina antes de S3. Una action iniciada
antes del crash pertenece a first delivery aunque su outcome sea incierto.

Las matrices H1–H5 no etiquetan sistemáticamente cada delegación con esta
partición; “dos POST”, “redelivery”, “recovery” o “dos callbacks” no bastan.

## 12. Definición de multiplicidad

Incrementa al cruzar el puerto catalogado. Excepción posterior cuenta; aborto
anterior no. Dos delegaciones en una fase suman dos. Relectura, deduplicación o
reutilización sin delegación no incrementan.

Máximos de filas o efectos no permiten reconstruir cuántas veces se cruzó el
puerto, especialmente bajo concurrencia o resultado incierto.

## 13. `external_actions`

HTTP, iniciar hijos, liberar barreras, provocar kill, invocar callback/recovery y
cleanup son comandos del harness. Por EA1:

`project_to_external_actions(expected.actions) = {}`.

Ninguno demuestra una delegación productiva. Las matrices que cuentan POST,
procesos o callbacks describen topología, no counts EA1.

## 14. Cleanup

Cancelación, eliminación o terminación ejecutada exclusivamente para limpiar el
fixture queda fuera. Solo una cancelación que una autoridad ubique dentro del
replay productivo podría contar como `scheduler.action_cancel`. Ningún caso
publica hoy esa clasificación con multiplicidad exacta.

## 15. Caso piloto WR-06

Punto de entrada: devolución Webpay HTTP por `WebpayReturnService`. Estado inicial:
pedido WooCommerce A11 y fixture Webpay controlado. S1 sería el snapshot previo a
first delivery; la documentación materializa conceptualmente primera devolución
y replay idempotente, pero no fija una crash window productiva única para WR-06.

El call graph prueba que `WebpayReturnService` puede delegar `gateway->commit()` y
que el materializer puede publicar routing durable. La auditoría WooCommerce
prueba que completion no ocurre sin ejecutar el processor/worker correspondiente.
Routing prueba generation 1 y programación única como efectos, no el número de
veces que `schedule()` fue iniciado por fase. “POST dos veces”, “transición única”
y “HTTP idempotente” son resultados, no trazas de puertos.

Prefijo antes del crash, delegaciones iniciadas/no iniciadas, estado post-crash y
punto exacto de recovery dependen del plan de crash aún no asignado al caso. El
payload, `gateway_result` y `expected.gateway_result` determinan ramas/outcomes,
pero no publican la traza EA1 completa.

WR-06 counts:

| Fase | wc | woo | sch | cancel | legacy | worker |
|---|---:|---:|---:|---:|---:|---:|
| first_delivery | ? | ? | ? | ? | ? | ? |
| replay | ? | ? | ? | ? | ? | ? |

Autoridades: matriz principal §377, complementaria §411, routing §§5–25,
WooCommerce payment completion audit y EA1. Bloqueador: falta un plan WR-06 que
enumere, por fase y orden de comienzo, cada puerto delegado. WR-06 no es
materializable.

## 16. Análisis por puerto

### 16.1 `webpay.commit`

`WebpayReturnService` lo invoca para una devolución que requiere commit. El código
prueba el punto de llamada; las matrices WR prueban evidencia/commit total o
máximo en algunos escenarios. No fijan, para cada caso y fase, si replay vuelve a
delegar o solo relee/reconcilia evidencia. Error/excepción posterior contaría,
pero outcome solo no demuestra la llamada.

### 16.2 `woocommerce.payment_complete`

Lo dispara el processor de completion, no la mera devolución ni la persistencia.
La protección idempotente puede evitar una segunda delegación, pero estado pagado
no prueba por sí mismo si hubo una primera. Excepción posterior no reduce count.
Las matrices no etiquetan la invocación por fase para cada caso.

### 16.3 `scheduler.action_schedule`

Cuenta al delegar `DurableRetryExternalSchedulerInterface::schedule()`. Asociación
del action ID, duplicate row y persistencia son internos. Pending existente puede
evitar una nueva llamada o resultar de una llamada convergente; “una action” o
“máximo 1” no distingue ambos. Falta traza case-specific.

### 16.4 `scheduler.action_cancel`

El coordinator puede delegar cancelación productiva; el harness también puede
cancelar por cleanup. Las autoridades no separan consistentemente cancelación de
replay y mantenimiento ni fijan multiplicidad por caso.

### 16.5 `legacy.retry_schedule`

A8 delega solo en autoridad legacy/ruta legacy y no debe coexistir con durable.
“legacy 0/1” anterior a EA1 suele ser efecto/action histórica, pero no siempre
identifica el puerto ni la fase. Fallback prohibido prueba una invariante, no los
doce counts completos.

### 16.6 `durable.worker_execute`

Cuenta una entrada lógica a `DurableRetryExecutorInterface::execute()`, incluso si
termina con excepción. Callback, claim, processor y efecto no son sinónimos del
puerto. Los casos de kill dicen “intento 0”, “proceso≤1” o terminal, pero no fijan
si el executor comenzó antes/después de la barrera ni la multiplicidad por fase.

## 17. Matriz de 31 casos

Notación de counts, en orden EA1: `W,Woo,S,C,L,D`. `?` significa que no existe
prueba conjunta suficiente. La misma notación se aplica a FD y R.

| Caso | Categoría | Entrada/estado/crash-recovery | FD | R | Estado y bloqueador |
|---|---|---|---|---|---|
| A11-OP-01 | operational | HTTP approved/on; publish→callback | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: outcome/filas, sin traza por fase |
| A11-OP-02 | operational | HTTP approved/off; legacy | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: “legacy action 1” no identifica fase/puerto EA1 completo |
| A11-OP-03 | operational | publish; AS unavailable | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: unavailable es outcome |
| A11-OP-04 | operational | dos callbacks; action terminal | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: callback no equivale a executor |
| A11-OP-05 | operational | retryable; recovery | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: generations/actions son efectos |
| A11-CON-01 | concurrency | dos publish simultáneos | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: máximo una action no fija llamadas schedule |
| A11-CON-02 | concurrency | dos callbacks mismo ID | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: máximo proceso, sin fases EA1 |
| A11-CON-03 | concurrency | dos producer/create | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: una fila no prueba delegaciones |
| A11-CON-04 | concurrency | callback vieja/actual | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: elegibilidad no prueba execute iniciado |
| A11-CON-05 | concurrency | cancel vs callback | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: cancel productivo/cleanup no separado |
| A11-CR-01 | crash | kill external-created; recovery | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: pending no prueba schedule count por fase |
| A11-CR-02 | crash | kill claimed; recovery | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: intento/proceso no identifica executor boundary |
| A11-CR-03 | crash | kill post-attempt; replay | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: efecto único no prueba delegaciones |
| A11-CR-04 | crash | kill post-result; redelivery | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: terminal/proceso 0 son outcomes |
| A11-CR-05 | crash | kill pre-callback-return | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: respuesta ausente no localiza puerto/fase |
| A11-WR-01 | Webpay | token nuevo; POST | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: recon/gen1 no son catálogo EA1 |
| A11-WR-02 | Webpay | return persistido; POST replay | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: commit total 1 no distribuye fases |
| A11-WR-03 | Webpay | excepción post-A8; segundo POST | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: publish converge no prueba schedule call |
| A11-WR-04 | Webpay | dos POST concurrentes | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: commit≤1 no determina fase ni inicio |
| A11-WR-05 | Webpay | return existente; recovery | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: resume publish≤1 es máximo |
| A11-WR-06 | Webpay/Woo | pedido A11; dos POST | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: ver §15 |
| A11-EX-01 | exclusion | A3 legacy; legacy child | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: process/retry≤1 no es traza EA1 |
| A11-EX-02 | exclusion | A3 durable; legacy child | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: effects 0 no etiqueta ambas fases/puertos |
| A11-EX-03 | exclusion | durable scheduled; legacy child | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: misma gen/action es estado |
| A11-EX-04 | exclusion | A3 indeterminate | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: effects 0 sin contrato de fase completa |
| A11-EX-05 | exclusion | A3 throws | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: excepción A3 no publica catálogo EA1 |
| A11-EX-06 | exclusion | durable consumed; redelivery | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: no mutation no prueba puertos |
| A11-EX-07 | exclusion | legacy action, luego durable callback | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: callback no-op/retry0 no distribuye counts |
| A11-EX-08 | exclusion | callbacks legacy/durable concurrentes | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: proceso≤1, frontera executor no trazada |
| A11-EX-09 | exclusion | replay durable decision | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: dos publish son comandos, no schedules |
| A11-EX-10 | exclusion | identity sin gen1; legacy child | `?,?,?,?,?,?` | `?,?,?,?,?,?` | indeterminado: conducta histórica≤1 no fija puerto/fase |

Cada fila se fundamenta en las matrices principal §§357–387 y complementaria
§§391–421. Sus bloqueadores se contrastan con los límites reales citados en §7.

## 18. Matriz equivalente de 372 counts

La tabla §17 contiene 31 vectores FD de seis posiciones y 31 vectores R de seis:
`31 × 2 × 6 = 372`. Todas las 372 celdas son `?`. No hay key omitida ni cero
implícito. Esta es la representación equivalente verificable solicitada.

## 19. Counts determinados

Determinados: `0/372`. No significa que ninguna delegación ocurra; significa que
ningún campo cumple conjuntamente los diez requisitos probatorios por caso/fase.

## 20. Counts indeterminados

Indeterminados: `372/372`. Se concentran en los seis puertos por igual: 62 campos
por puerto (31 casos × 2 fases). La causa común es ausencia de una traza normativa
case-specific, no ausencia de call sites productivos.

## 21. Conteos repetidos

Dos delegaciones reales en una fase contarían 2; varias generaciones o retries
solo cuentan cuando vuelven a cruzar el puerto; schedule y cancel son keys
separadas; worker repetido suma; fallo+replay se reparte por fase; abortado antes
del puerto vale 0; excepción posterior cuenta. Las autoridades de casos no fijan
estos hechos con precisión suficiente para aplicar la regla.

## 22. Contradicciones

No existe contradicción de schema después de EA1. Existe heterogeneidad semántica
antecedente: “action” puede significar fila Action Scheduler, efecto, callback o
invocación. EA1 resuelve el término hacia adelante, pero no autoriza reinterpretar
retroactivamente cada número de las matrices H1–H5.

## 23. Autoridades faltantes

Se requiere una corrección normativa de materialización con 31 registros. Cada
registro debe contener entrypoint, S1, crash boundary, lista ordenada de eventos
`{phase, ea1_action, delegation_started, multiplicity, authority_symbol}`,
deduplicaciones y punto de S2/S3. Debe declarar los doce enteros literalmente.

Para WR-06 debe además seleccionar crash plan y fijar si el segundo POST delega
Webpay, completion o scheduler, o solo reutiliza evidencia.

## 24. Riesgos de materialización prematura

Riesgos: convertir outcomes en calls; asumir cero por silencio; distribuir un
“total 1” entre fases; contar callback como executor; contar fila como schedule;
contar cleanup cancel; duplicar commands HTTP en actions; ocultar incertidumbre de
crash y cristalizar doubles accidentales.

## 25. Condiciones para continuar

No editar fixtures. Primero aprobar la autoridad §23; luego validar sus 372
literales contra call graphs y cinco ventanas; después implementar observadores
EA1; finalmente materializar fixtures y certificar fail-closed.

## 26. Allowlist futura propuesta

Para una corrección documental siguiente: un único documento nuevo de conteos por
caso. Solo después podrían autorizarse, separadamente, contrato/coordinator,
harnesses Runtime Capture, decorators de puertos y archivos de fixtures. Esta
auditoría no autoriza ninguno.

## 27. Validaciones realizadas

Se verificaron EA1 (507 líneas, 41 secciones, 22.810 bytes y hash esperado),
matriz exacta de 31 IDs, call sites productivos, ciclo S0–S4, R2/R4, 8 hashes A11,
13 hashes R5, Git, artifacts y residuos. Los bloques JSON de este documento deben
validarse antes del cierre.

## 28. Integridad final

La ejecución crea únicamente este documento. No modifica los 17 tracked, otros
untracked, producto, pruebas, fixtures, harnesses, EA1, R2, R4 ni artifacts. No
realiza staging, commit, push, cleanup destructivo, IDs o materialización.

## 29. Veredicto definitivo

Los 31 casos no son materializables; WR-06 tampoco. No existe subconjunto de
counts que satisfaga completamente la regla probatoria case/phase, por lo que una
materialización parcial también sería insegura.

`A11 EXPECTED ACTIONS BLOQUEADO POR CONTEOS INDETERMINADOS`
