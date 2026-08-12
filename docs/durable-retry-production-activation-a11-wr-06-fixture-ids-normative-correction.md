# Corrección normativa de `fixture_ids` para A11-WR-06

## 1. Propósito

Esta corrección adopta sin reapertura:

```text
A11-WR-06 MATERIALIZACIÓN BLOQUEADA POR FIXTURE_IDS INDETERMINADO
```

Resuelve la contradicción semántica y de key set entre §6 y el descriptor WooCommerce. Después audita si puede publicarse el objeto literal WR-06; se detiene en el primer valor sin autoridad.

## 2. Autoridades inspeccionadas

| Clasificación | Autoridad | Rango/responsabilidad |
|---|---|---|
| antecedente documental normativo | fixture contract §6 | líneas 78–93: quince claves y listas de enteros positivos |
| antecedente documental normativo | fixture contract §20.2–20.4 | líneas 706–768: `woocommerce_order_id`, creación post-save y descriptor contradictorio |
| antecedente documental normativo | fixture contract §4–§5 | líneas 60–76: manifest y `fixture_ids:object` |
| antecedente documental normativo | fixture contract §§10–13 | catálogo único de 31 casos |
| antecedente documental normativo | fixture contract §18 | líneas 261–279: 0/31 objetos completos y categorías no asignadas |
| autoridad productiva | repositorios VeciAhorra | PK autoincrement capturada después del INSERT |
| autoridad productiva | `WC_Order::get_id()` | ID WooCommerce positivo disponible después de `wc_create_order()`/save |
| autoridad productiva | A4 repository | schedule interno generation 1 creado durante first delivery |
| autoridad productiva | `DurableRetryExternalSchedulerInterface::schedule()` | action ID externo retornado durante first delivery |
| autoridad productiva | initial transfer request | generation `1`, attempt `0`; no son IDs |
| evidencia de prueba | doubles de scheduler/fixtures existentes | demuestran control posible, no asignan valores normativos WR-06 |

No existe loader A11 implementado que añada una autoridad superior. Nombres de variables y tests no se usan para inventar valores.

## 3. Definición final de `fixture_ids`

Se adopta la alternativa B:

> `fixture_ids` es el registro runtime de claves primarias persistentes usadas o creadas por el fixture, incluidas las capturadas durante setup o ejecución, agrupadas por recurso canónico.

Cada valor es exclusivamente `list<positive-int>`, ordenada por primera captura, sin repetidos. No contiene descriptores, placeholders, referencias simbólicas, valores de negocio, estados o resultados.

Quedan fuera:

```text
buy_order, session_id, token, transaction_id, authorization_code,
worker_id, scheduled_for, hook, group, status, result codes,
generation y attempt_number
```

## 4. Separación de dominios

- `fixture_ids`: quince listas runtime de IDs persistentes canónicos.
- `resource_references`: IDs de recursos API no pertenecientes al catálogo SQL/AS, incluido `woocommerce_order_id`.
- `runtime_captures`: enlaces tipados a valores inexistentes antes de una fase, como `schedule_id` y `scheduled_action_id`; no duplican ownership, apuntan a los valores también registrados en sus listas canónicas.
- `business_identifiers`: buy order, transaction reference, financial session y token/hash según sus contratos propios.
- `fixed_values`: generation `1` y attempt `0`.

El descriptor antiguo de `woocommerce_order_id` pertenece al schema declarativo de `resource_references`, no al valor runtime de `fixture_ids`.

## 5. Precedencia normativa

1. El objeto literal del caso prevalece para valores concretos preasignados.
2. El schema del manifest prevalece para ubicación y tipo.
3. Este contrato global prevalece para key set, orden y cardinalidad.
4. La autoridad productiva decide si un valor puede preasignarse o debe capturarse.

Se prohíben claves ad hoc, descriptores interpretados como IDs, duplicación sin enlace, IDs runtime inventados, placeholders `0`/`null`/vacíos/negativos y fórmulas derivadas del número de caso.

## 6. Key set definitivo

| Orden | Clave | Significado | Mantener | Renombrar | Retirar | Categoría final | Tipo | Cardinalidad WR-06 |
|---:|---|---|:---:|:---:|:---:|---|---|---|
| 1 | `orders` | orders VeciAhorra | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 2 | `checkouts` | checkout del caso | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |
| 3 | `checkout_orders` | puente interno | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 4 | `payment_sessions` | sesión de pago | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |
| 5 | `payment_origin_contexts` | origen durable | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |
| 6 | `webpay_returns` | evidencia Webpay | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |
| 7 | `payment_reconciliations` | reconciliación | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |
| 8 | `durable_retry_schedules` | schedule interno | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |
| 9 | `business_completions` | completion posterior | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 10 | `business_completion_orders` | puente posterior | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 11 | `payments` | pago funcional posterior | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 12 | `payment_orders` | puente pago/pedido | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 13 | `delivery_completions` | completion posterior | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 14 | `fulfillment_completions` | completion posterior | sí | no | no | fixture_ids | list<positive-int> | exactamente 0 |
| 15 | `action_scheduler_actions` | action ID externo AS | sí | no | no | fixture_ids | list<positive-int> | exactamente 1 |

El orden es vinculante. Todas las claves existen incluso cuando su lista es vacía.

## 7. Ubicación de `woocommerce_order_id`

Se selecciona C: pertenece a `resource_references`.

```text
resource_references.woocommerce_order_id
type=positive-int
owner=woocommerce_order
source=WC_Order::get_id() post-save
created_in=setup
cardinality=exactly 1
unique_within_run=true
shared_between_cases=false
```

No es alias de `orders` ni de un `order_id` VeciAhorra. Para WR-06:

```text
order_id interno != woocommerce_order_id
```

porque el primero no existe en este caso y el segundo identifica el recurso API WooCommerce.

## 8. Distinción de schedules

`schedule_id` es la PK interna de `durable_retry_schedules`, generada por la base durante A4 y capturada después de first delivery. Su owner canónico es `fixture_ids.durable_retry_schedules[0]`.

`scheduled_action_id` es el ID externo retornado por `DurableRetryExternalSchedulerInterface::schedule()`, controlable por un double futuro y persistido después en la fila durable. Su owner canónico es `fixture_ids.action_scheduler_actions[0]`.

`runtime_captures` solo enlaza:

```text
first_delivery.schedule_id -> fixture_ids.durable_retry_schedules[0]
first_delivery.scheduled_action_id -> fixture_ids.action_scheduler_actions[0]
replay.schedule_id == first_delivery.schedule_id
replay.scheduled_action_id == first_delivery.scheduled_action_id
```

Usar el mismo número por conveniencia queda prohibido.

## 9. Valores fijos y negocio

```text
fixed_values.generation = int(1)
fixed_values.attempt_number = int(0)
```

Persisten iguales en ambas fases y provienen del initial transfer request.

Buy order, financial session ID, token/hash y transaction reference permanecen en `payload`/`business_identifiers` bajo sus contratos ya cerrados. No son claves primarias y no integran `fixture_ids`.

## 10. Objeto literal WR-06: evaluación

El objeto runtime tendría el key set y cardinalidades de §6, pero sus seis enteros positivos no pueden publicarse literalmente antes de ejecución:

- checkout, payment session y origin context son PKs generadas durante setup;
- Webpay return, reconciliation y durable schedule son PKs generadas durante first delivery;
- WooCommerce order ID se obtiene post-save;
- action ID externo puede fijarse en un double futuro, que está prohibido implementar ahora y aún no tiene literal normativo.

No existe rango reservado, tabla global de asignación, secuencia controlada ni fórmula normativa por caso. Autoincrement no es determinista entre bases. Mostrar referencias, `AUTO`, `RUNTIME`, null o descriptores dentro de las listas violaría el tipo cerrado.

Por ello no puede publicarse honestamente el bloque literal exigido con enteros exactos.

## 11. IDs candidatos hasta la detención

| ID | Requerido | Preasignado | Creado setup | Creado first_delivery | Devuelto externamente | Capturado runtime |
|---|---:|---:|---:|---:|---:|---:|
| user/store/product/inventory/cart/cart_item | no demostrado | no | no | no | no | no |
| order interno/order_item/reservation | no | no | no | no | no | no |
| `woocommerce_order_id` | sí | no | sí | no | no | sí |
| `checkout_id` | sí | no | sí | no | no | sí |
| `checkout_order_id` | no | no | no | no | no | no |
| `payment_session_id` | sí | no | sí | no | no | sí |
| `payment_origin_context_id` | sí | no | sí | no | no | sí |
| `payment_reconciliation_id` | sí | no | no | sí | no | sí |
| `business_completion_id` | no | no | no | no | no | no |
| durable `schedule_id` | sí | no | no | sí | no | sí |
| external `scheduled_action_id` | sí | no | no | sí | sí | sí |

`webpay_return_id`, aunque no aparecía en la lista candidata de la solicitud, también es requerido, creado y capturado durante first delivery.

## 12. Primer bloqueo restante

```text
case: A11-WR-06
category: fixture_ids
field: fixture_ids.values
reason: el dominio, key set, orden y cardinalidades están cerrados, pero las PK
        de setup y first_delivery son autoincrement runtime y no existe rango,
        tabla de asignación o allocator determinista por caso; el action ID externo
        tampoco tiene un literal normativo asignado
required_authority: contrato global de asignación de valores que reserve enteros
                    positivos únicos para los 31 casos o autorice expresamente
                    que el objeto literal del fixture sea un plan tipado y que el
                    manifest runtime sea la única autoridad de valores capturados
```

## 13. Detención

Se detiene la corrección en el primer bloqueo de valores. No se auditan ni cierran después de este punto:

- fórmula de unicidad intercaso;
- representación definitiva en manifest y fixture;
- shape completo de runtime captures;
- identificadores de negocio completos;
- reconciliación integral de todas las ubicaciones;
- payload, clock, harness, manifest integral o materialización.

## 14. Estado de cierre

| Unidad | Estado |
|---|---|
| Definición de fixture_ids | cerrada |
| Key set y orden | cerrados |
| Tipos y cardinalidades | cerrados |
| `woocommerce_order_id` | cerrado como resource reference |
| `schedule_id` | dominio/fuente cerrados |
| `scheduled_action_id` | dominio/fuente cerrados |
| generation/attempt | ubicados fuera de IDs |
| Objeto literal WR-06 | bloqueado |
| Runtime captures completos | no cerrados tras detención |
| Business identifiers completos | no auditados tras detención |
| Unicidad intercaso | no cerrada |
| Manifest | no cerrado |
| Fixture | no cerrado |
| Precedencia normativa | cerrada |

## 15. Veredicto principal

```text
A11-WR-06 FIXTURE_IDS CONTINÚAN BLOQUEADOS POR VALORES NO ASIGNADOS
```

Respuesta binaria: no puede publicarse todavía un único objeto literal WR-06 sin placeholders o IDs inventados.

## 16. Integridad

Esta corrección crea únicamente este documento. No modifica producto, harnesses, fixtures, JSON, manifest, matriz, expected, schema, configuración o artifacts; no realiza staging, commit ni push.
