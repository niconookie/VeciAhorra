# Serie 37.4 — Diseño de acciones administrativas seguras para Orders

## 1. Estado actual certificado

Este documento parte de `main` en
`45a29c942a2f032ed1da13d91916f31899da73ec`. Es una auditoría y un diseño:
no implementa rutas, servicios, UI, pruebas productivas ni cambios de datos.

Las Series 37.1, 37.2 y 37.3 dejaron certificado:

- un lifecycle operacional agregado que no confunde `orders.status` con el
  estado completo de una compra;
- un listado administrativo y un detalle privado protegidos por
  `manage_options`, nonce REST y `Cache-Control: private, no-store`;
- la cadena
  `OrderAdminReadService → OrderAdminReadRepository →`
  `OrderOperationalFactsAssembler → OrderOperationalStateResolver`;
- un GET de detalle con exactamente tres statements SQL y una solicitud REST;
- `operational_version` calculada desde hechos de todas las autoridades;
- `allowed_actions = ["view"]` y `mutable_actions = []`;
- privacidad de comprador y pagos, sin tokens, payloads financieros, SQL,
  stack traces ni PII adicional.

`Order` conserva identidad, Store, total, líneas congeladas y los hitos
persistidos `reserved`, `paid` y `delivered`. No es autoridad de Reservations,
evidencia financiera, Delivery ni completions. Por ello la Serie 37.4 no debe
crear un lifecycle mutable nuevo dentro de Orders.

## 2. Principios y autoridades por módulo

| Dimensión | Autoridad durable | Servicio canónico | Regla para administración |
| --- | --- | --- | --- |
| Identidad, Store, total y líneas | Orders/OrderItems | `OrderService` durante creación | Inmutables desde la UI |
| Agrupación y fulfillment elegido | Checkout/CheckoutOrders | `CheckoutService` | No cambiar después de crear |
| Stock bloqueado | Reservations + Inventory | `ReservationService`, `ReservationExpirationService` | Nunca editar filas ni stock |
| Sesión financiera | PaymentSession | `PaymentSessionService`, recoveries Webpay | No recrear ni revelar token |
| Evidencia financiera | WebpayReturn/Reconciliation | `WebpayReturnService`, `PaymentReconciliationProcessor` | Solo procesamiento durable elegible |
| Resultado comercial pagado | Payment/BusinessCompletion | `BusinessCompletionProcessor` | Nunca marcar pagado manualmente |
| Materialización de rama delivery | DeliveryCompletion | `DeliveryCompletionProcessor` | Solo reanudar trabajo no terminal |
| Progreso físico | Delivery/Tracking | `DeliveryService` | Orders no marca delivered |
| Cierre durable de rama | FulfillmentCompletion | `FulfillmentCompletionProcessor` | No equivale a entrega física |
| Diagnóstico agregado | Resolver operacional | `OrderOperationalStateResolver` | Lectura; no corrige hechos |
| Ejecución/recovery | Orquestación durable | `DurableCompletionScheduler`, workers y recovery | Reusar leases, retries y límites |

Una acción administrativa es un comando dirigido a la autoridad real. Orders
solo puede actuar como fachada administrativa: valida el Order y el snapshot,
delega una acción allowlisted y vuelve a leer. No escribe tablas ajenas ni
interpreta una respuesta como un nuevo estado propio.

## 3. Inventario y clasificación de acciones candidatas

### 3.1 Resumen A/B/C/D

| Código conceptual | Categoría | Decisión |
| --- | --- | --- |
| `retry_durable_processing` | A, con fachada mínima | Primera acción mutable propuesta |
| `recover_uncertain_operation` | B | Requiere contrato por tipo de incertidumbre |
| `run_pending_work` | A, como alias semántico del retry | No crear segundo comando equivalente |
| `retry_delivery_materialization` | A si completion no terminal | Subcaso delegado del retry durable |
| `expire_overdue_reservation` | B para flujo normal; C tras pago | No implementar en 37.4 |
| `consume_paid_reservation` | C | Saneamiento histórico separado |
| `reconcile_financial_evidence` | B | No exponer hasta cerrar origen e incertidumbre |
| `refresh_operational_detail` | A informativa | Ya se resuelve con GET; no es mutación |
| `add_admin_note` | B | Falta autoridad, esquema, auditoría y privacidad |
| `cancel_order` | D | No existe cancelación comercial segura |
| `edit_order` | D | Viola snapshots y autoridades |
| `force_delivery_progress` | D | Viola autoridad de Delivery |

Solo las categorías A pueden entrar en la Serie 37.4. La disponibilidad final
de una categoría A sigue siendo cerrada y puede resultar vacía.

### 3.2 `retry_durable_processing` — A

**Autoridad.** La etapa concreta: Reconciliation, BusinessCompletion,
DeliveryCompletion o FulfillmentCompletion.

**Servicios.** Scheduler, workers y processors durables ya existentes. La
fachada administrativa no llama repositorios ni métodos `complete()` internos;
encola el hook canónico correspondiente.

**Precondiciones.**

- Order y relaciones cardinales válidas;
- etapa identificada por hechos ya cargados;
- estado `pending` o `retryable`, o `processing` con lease vencido;
- `attempt_count` bajo el límite vigente (actualmente 5 en recovery);
- ninguna completion terminal contradictoria;
- ningún blocker de identidad, cardinalidad, monto o evidencia financiera;
- `operational_version` coincide;
- acción todavía ofrecida por la política al momento de ejecutar.

**Idempotencia y concurrencia.** El enqueue puede repetirse, pero el processor
solo progresa tras adquirir su lease versionado. La futura fachada debe además
persistir/deduplicar una clave administrativa por Order, acción y versión, o
usar una garantía equivalente del scheduler. Un lease activo responde
`409 operation_busy`; no se fuerza ni se roba.

**Efectos.** Puede crear/materializar registros propios de la etapa y encadenar
la siguiente. Es irreversible en el sentido comercial, pero repetible de forma
idempotente. La respuesta HTTP solo confirma aceptación o resultado conocido;
el GET posterior es la autoridad.

**Riesgos.** Encolar una etapa equivocada, superar límites o reabrir una etapa
terminal. La política y el adaptador por autoridad deben impedirlo.

### 3.3 `retry_delivery_materialization` — A condicional

Es una presentación específica de `retry_durable_processing`, no una segunda
implementación. Solo procede si BusinessCompletion está completado y
DeliveryCompletion está ausente, `pending`, `retryable` o con lease vencido.
No procede si DeliveryCompletion está terminal ni para avanzar una Delivery
`pending`, `assigned` o `picked_up`: esos son hechos de operación física.

### 3.4 `refresh_operational_detail` — A informativa

No necesita endpoint mutable. Ejecuta el GET privado existente, conserva tres
statements SQL y recalcula resolver, blockers, acciones y
`operational_version`. Puede mostrarse como control “Actualizar”, pero permanece
en `allowed_actions`, no en `mutable_actions`.

### 3.5 `recover_uncertain_operation` — B

“Incierta” no es una acción suficientemente precisa. PaymentSession create,
WebpayReturn y una completion con timeout tienen autoridades y pruebas distintas.
Antes de exponerla se requiere:

- código cerrado por operación;
- prueba durable de si el efecto remoto ocurrió;
- idempotency key estable;
- resultado `accepted`, `already_completed`, `busy` o `uncertain`;
- auditoría sin payload financiero;
- recuperación que nunca repita una operación financiera no idempotente.

### 3.6 Reservations — B/C

`ReservationExpirationService` busca globalmente reservas activas vencidas,
marca `expired` y repone stock, con compensación a `active` si falla la
reposición. No ofrece comando focalizado por Order, CAS administrativo,
idempotency key ni registro de operador. Por ello expirar manualmente es B.

Una reserva activa después de Payment/BusinessCompletion no debe “expirarse”:
reponería stock de una venta pagada. Consumirla podría ser materialmente
correcto, pero el BusinessCompletion terminal no la reprocesará y falta probar
que el stock ya fue descontado exactamente una vez. Es categoría C y exige
política/migración histórica auditable.

### 3.7 Reconciliation — B

El processor tiene lease, intentos, estados terminales y evidencia durable. Sin
embargo, una UI no puede decidir solo por `Order` si debe reevaluar un retorno,
recuperar un create incierto o materializar evidencia. Debe existir primero un
adaptador administrativo de Payments que distinga `retryable`, `manual_review`,
operación remota incierta y terminal. Nunca se acepta un payload financiero
desde el navegador.

### 3.8 Anotación administrativa — B

No hay autoridad ni tabla de audit log para notas de Orders. Implementarla
requiere esquema append-only, autor, timestamp, política de retención,
sanitización, límites y prohibición de PII/secrets. No se debe guardar una nota
en `orders.status`, metadata financiera ni tablas de completions.

## 4. Acciones expresamente prohibidas

Son categoría D:

- editar directamente `orders.status`, total, Store, customer o líneas;
- cambiar Payment a `paid`, crear evidencia financiera o alterar
  Reconciliation manualmente;
- marcar Delivery `delivered` desde Orders;
- marcar Fulfillment `completed` sin completar su rama;
- cambiar pickup a delivery o viceversa;
- cancelar o eliminar una Order mediante `OrderRepository::delete()` o
  `cancelOrders()`, que son compensaciones de creación, no cancelación comercial;
- eliminar retornos, reconciliaciones, Payments, tracking o completions;
- inventar `approved_at`, timestamps, relaciones, tokens o evidencias;
- corregir blockers con `UPDATE`, `DELETE` o inserts manuales;
- reejecutar una llamada financiera no idempotente;
- ocultar findings, cambiar su severidad o alterar hechos solo para mejorar la
  clasificación global;
- avanzar una Delivery física sin el contrato operativo de Delivery.

## 5. Contrato de disponibilidad

### 5.1 Separación de responsabilidades

Se propone:

```text
facts ya cargados
  → OrderOperationalStateResolver (diagnóstico, sin mutar)
  → OrderAdminActionPolicy (disponibilidad agregada)
       → adaptadores de autoridad puros (Payments/Completions/etc.)
  → DTO privado
```

`OrderAdminActionPolicy` decide qué códigos puede ofrecer usando únicamente los
facts/resolution ya cargados. No ejecuta. Cada adaptador dueño valida de nuevo
contra estado durable al ejecutar. JavaScript solo presenta el contrato; no
reconstruye reglas desde textos o `orders.status`.

### 5.2 Forma cerrada

```json
{
  "allowed_actions": ["view", "refresh"],
  "mutable_actions": [
    {
      "code": "retry_durable_processing",
      "label": "Reintentar procesamiento pendiente",
      "risk": "medium",
      "requires_confirmation": true,
      "expected_operational_version": "orders-operational-v1:…",
      "idempotency_required": true,
      "retryable": true,
      "pending": false
    }
  ],
  "unavailable_actions": [
    {
      "code": "retry_durable_processing",
      "reason_not_allowed": "no_retryable_work"
    }
  ]
}
```

Enums iniciales:

- código mutable: solo `retry_durable_processing`;
- riesgo: `low | medium | high`;
- reason: allowlist segura, por ejemplo `no_retryable_work`,
  `active_lease`, `attempt_limit`, `blocking_inconsistency`,
  `terminal_stage`, `stale_snapshot`;
- `pending` es estado efímero de UI, no hecho durable del DTO. El backend puede
  exponer `operation_status` separado si se materializa un comando.

No se exponen nombres de hooks, clases, tablas, SQL, owners de leases,
idempotency keys almacenadas, tokens, stack traces ni PII.

## 6. Contrato REST propuesto

La ruta coherente es:

```text
POST /veciahorra/v1/orders/{id}/admin-actions
```

No reutiliza `POST /orders`, ni simula una transición de Order.

Payload cerrado:

```json
{
  "action": "retry_durable_processing",
  "expected_operational_version": "orders-operational-v1:…",
  "idempotency_key": "UUID generado por la aplicación"
}
```

Reglas:

- `id` decimal canónico positivo, sin signos, ceros iniciales ni arrays;
- body JSON objeto, sin campos adicionales;
- allowlist de acción exacta;
- versión exacta y con prefijo conocido;
- idempotency key opaca, acotada y validada;
- usuario autenticado, `manage_options` y `X-WP-Nonce`;
- `Content-Type: application/json`, `Accept: application/json`;
- `Cache-Control: private, no-store` en todo resultado, incluidos 401/403;
- no aceptar query params ni datos internos de etapa/lease desde el cliente.

### 6.1 Respuesta

Aceptada:

```json
{
  "data": {
    "order_id": 123,
    "action": "retry_durable_processing",
    "outcome": "accepted"
  }
}
```

Otros outcomes seguros: `already_completed` y `already_accepted`. Una respuesta
2xx no entrega un snapshot parcial ni promete que el procesamiento acabó. La UI
siempre realiza GET autoritativo.

### 6.2 Matriz de errores

| Estado | Código seguro | Uso |
| --- | --- | --- |
| 400 | `invalid_request` | JSON malformado/content type inválido |
| 403 | `forbidden` / nonce inválido | Sin revelar existencia del Order |
| 404 | `order_not_found` | ID válido inexistente |
| 409 | `stale_operational_version` | Facts cambiaron |
| 409 | `operation_busy` | Lease o comando equivalente activo |
| 409 | `idempotency_conflict` | Misma clave con otro comando/version |
| 422 | `action_not_allowed` | Código válido pero precondición ausente |
| 422 | `invalid_parameters` | Campo desconocido o enum inválido |
| 500 | `orders_admin_action_failed` | Fallo interno seguro |
| Cliente | `network_error` | Sin afirmar éxito o fracaso |
| Cliente | `invalid_response` | 2xx que no cumple contrato |

Ante timeout después de dispatch, el estado es `uncertain`: no se genera una
clave nueva. Se relee detalle y se consulta el comando por la misma clave si se
implementa un recurso durable; solo se reenvía si el backend garantiza
deduplicación.

## 7. Concurrencia, idempotencia y recuperación

1. El frontend genera una clave por confirmación, bloquea controles y emite un
   solo POST.
2. Backend relee/valida la versión y la política.
3. Una capa durable deduplica `(actor scope, order_id, action, key)` y conserva
   un fingerprint del payload, sin PII.
4. La autoridad adquiere su lease/CAS canónico; la fachada no inventa otro.
5. Dos administradores: uno obtiene aceptación; el otro recibe
   `already_accepted`, `operation_busy` o `stale_operational_version`.
6. Doble clic y retry del navegador reutilizan la misma promesa/clave.
7. Una etapa terminal devuelve `already_completed`, no se reabre.
8. Una etapa ya no elegible devuelve 409/422 y obliga a GET.
9. Workers y REST convergen en el mismo scheduler/processors, límites y leases.
10. Respuestas tardías llevan una secuencia local; una respuesta de una
    operación anterior no reemplaza el snapshot más reciente.

Los retries respetan `attempt_count < 5`, backoff y recovery existentes. La UI
no ofrece “reintentar para siempre” ni modifica contadores.

## 8. Operaciones inciertas

Se distingue:

- `accepted`: enqueue confirmado; procesamiento puede seguir pendiente;
- `completed`: solo lo demuestra el GET autoritativo;
- `rejected`: no hubo ejecución aceptada;
- `uncertain`: el cliente perdió la respuesta o una autoridad externa no tiene
  resultado concluyente.

En `uncertain`:

- mantener la misma idempotency key;
- informar sin afirmar fracaso;
- reconsultar de forma acotada y con backoff;
- no disparar operaciones financieras;
- no liberar leases;
- escalar a manual review si la autoridad lo exige;
- conservar blockers hasta que los hechos cambien realmente.

## 9. UX administrativa

Flujo obligatorio:

```text
acción → confirmación específica → pending → un POST
       → respuesta → GET autoritativo → rerender
```

- El botón deriva exclusivamente de `mutable_actions`.
- La confirmación nombra acción y Order, no detalles financieros.
- Cancelar cierra el panel, devuelve foco al disparador y no emite requests.
- Pending deshabilita todas las mutaciones relacionadas y muestra
  `aria-busy="true"`.
- 409 anuncia conflicto y hace GET; no reintenta automáticamente la mutación.
- `network_error` anuncia resultado incierto y hace relectura acotada.
- Se conserva `return_page`, filtros y demás `return_*` validados.
- Los mensajes son catálogos locales; no imprimen `error.message` remoto.
- AbortController y número de secuencia descartan GET/POST obsoletos.

## 10. Accesibilidad

- Éxito aceptado usa `role="status"`, hace GET y enfoca un resumen coherente.
- Error seguro usa `role="alert"` enfocable con `tabindex="-1"`.
- La confirmación se anuncia con título y descripción asociados.
- Pending comunica el cambio sin mover el foco innecesariamente.
- Cancelar restaura el foco al botón que abrió la confirmación.
- Tras el GET autoritativo, el foco solo se mueve a una región coherente con el
  resultado y nunca a un nodo desmontado.
- Los controles conservan nombre, estado disabled y orden de tabulación.

## 11. Seguridad, privacidad y trazabilidad

- Misma defensa de detalle: autenticación, `manage_options`, nonce y no-store.
- Autorización vuelve a comprobarse en cada POST.
- La respuesta no incluye customer PII, courier, coordenadas, tokens, payload
  Webpay, referencias sensibles ni ownership interno de leases.
- Logs/auditoría deben registrar actor ID, Order ID, código, versión esperada,
  fingerprint de clave, outcome y timestamps; nunca payload financiero.
- Rate limit por actor/Order y límites de tamaño.
- Errores no distinguen recursos antes de autorización.
- La política no confía en códigos/labels enviados por JavaScript.
- La acción nunca acepta IDs de reconciliation/completion/Delivery; el backend
  los resuelve desde el Order.

## 12. Presupuesto de consultas

La disponibilidad se calcula con facts ya cargados por los tres statements SQL
del GET. `OrderAdminActionPolicy` debe ser pura y realizar cero consultas. El
GET inicial y el GET posterior conservan exactamente:

- una consulta base;
- una consulta de comercio/Reservations;
- una consulta de autoridades operacionales.

No se permite N+1 ni una consulta “de acciones”. La mutación puede ejecutar las
consultas propias del ledger de comando, validación durable, enqueue y
processor, pero se instrumenta separadamente y con límite explícito por acción.
El POST no debe cargar listados ni ejecutar recovery global.

Presupuesto propuesto:

- GET detalle: exactamente 3 SQL, sin cambio;
- evaluación de política: 0 SQL;
- POST de aceptación: máximo a certificar en el microhito backend, separado por
  outcome (`accepted`, deduplicado, conflicto);
- GET posterior: exactamente 3 SQL;
- polling incierto: máximo 3 relecturas con backoff, nunca en paralelo.

## 13. Evidencia histórica #792 y #793

Las siguientes fueron lecturas mediante el read service, sin mutaciones.

### 12.1 Order #792

Observado:

- Order `paid`, checkout pickup;
- Payment y Reconciliation completados;
- BusinessCompletion y FulfillmentCompletion completados;
- DeliveryCompletion `not_required`;
- una Reservation vencida todavía `active`;
- blocker `reservations_active_after_payment`.

El flujo normal debía consumir la reserva dentro de BusinessCompletion antes
de marcarlo completado. El dueño de una reparación sería Reservations bajo una
política histórica coordinada con BusinessCompletion e Inventory. No existe hoy
una operación focalizada reutilizable que reabra de forma segura un completion
terminal y pruebe el estado exacto del stock.

No es seguro expirar: repondría stock ya vendido. Tampoco es seguro consumir
solo por ver Payment `paid`: deben verificarse conjunto congelado de Orders,
líneas, Inventory, descuento original, Payment único y ausencia de
compensaciones. Clasificación C.

### 12.2 Order #793

Observado:

- Order `paid`, checkout delivery;
- Payment, Reconciliation y BusinessCompletion completados;
- DeliveryCompletion y FulfillmentCompletion completados;
- Delivery existente `pending`;
- una Reservation vencida todavía `active`;
- blockers `fulfillment_completed_without_branch` y
  `reservations_active_after_payment`.

DeliveryCompletion debía materializar la Delivery; lo hizo. Una Delivery
`pending` es progreso físico todavía no ocurrido, no fallo de materialización.
FulfillmentCompletion terminal no significa `delivered`, pero el resolver exige
una rama comercial válida; esta contradicción requiere revisar el contrato
histórico/semántico, no marcarla delivered.

Reintentar DeliveryCompletion/FulfillmentCompletion terminales no es una
reparación segura. Avanzar Delivery inventaría un hecho físico. La reserva
requiere el mismo saneamiento C de #792. El blocker de rama es C para análisis
histórico y D si la “solución” propuesta es forzar Delivery/Fulfillment.

### 12.3 Política histórica separada

Antes de cualquier saneamiento se necesita:

- universo y fecha de corte reproducibles;
- backup y dry-run;
- comprobación de stock debitado, reservas y líneas;
- evidencia financiera única y completions relacionadas;
- reglas por fulfillment;
- script idempotente, transaccional cuando sea posible y append-only audit;
- revisión humana de casos ambiguos;
- rollback/compensación definidos;
- certificación de que los flujos actuales ya no generan el patrón.

No se codifican reglas para IDs concretos y estas acciones no aparecen en la UI
normal.

## 14. Matriz de pruebas futura

| Área | Casos mínimos |
| --- | --- |
| Política | acción permitida/no permitida; blockers; terminal; lease; límite |
| Contrato | enum manipulado, campo adicional, ID inválido/inexistente |
| Seguridad | 401, permiso 403, nonce ausente/inválido, no-store |
| Concurrencia | doble clic, dos admins, misma clave, clave conflictiva |
| CAS | versión vigente, obsoleta, facts cambiados entre GET y POST |
| Durable | pending, retryable, lease activo/vencido, attempt limit |
| Idempotencia | ya aceptado, ya completado, retry del navegador |
| Incertidumbre | timeout antes/después de aceptar, network error |
| Respuesta | 2xx válida, 2xx inválida, 409, 422, 500 seguro |
| Relectura | GET autoritativo, budget=3, blockers no relacionados conservados |
| Reservations | no expirar/consumir desde acción A; invariantes de stock |
| Payment | ninguna mutación manual; ninguna repetición financiera |
| Pickup | delivery not required; no inventar Delivery |
| Delivery | materialización retryable; progreso físico no alterado |
| DOM | abrir, confirmar, cancelar, un POST, pending, foco, status/alert |
| Carreras UI | respuesta tardía descartada, destroy/reinit sin listeners dobles |
| Privacidad | ausencia de PII, tokens, SQL, stack y payload financiero |

Las pruebas REST deben atravesar routing, autorización, parser, policy, fachada y
adaptador. Las pruebas DOM deben pulsar los botones reales; no basta invocar
callbacks o processors directamente.

## 15. Riesgos

- Tratar enqueue como completion.
- Duplicar reglas de elegibilidad entre resolver, policy y processors.
- Usar `orders.status` como única precondición.
- Convertir `operational_version` en sustituto de leases propios.
- Reabrir estados terminales o superar retries.
- Confundir Delivery `pending` con fallo técnico.
- Aplicar saneamiento histórico al flujo normal.
- Filtrar PII/evidencia en errores o auditoría.
- Aumentar silenciosamente las tres consultas del GET.
- Implementar un comando genérico que esconda qué autoridad actúa.

## 16. Secuencia de microhitos certificables

1. **37.4.1 — Policy privada read-only.** Añadir contrato cerrado y
   `OrderAdminActionPolicy` pura; mantener `mutable_actions=[]` salvo fixtures
   inequívocos; certificar cero SQL adicional.
2. **37.4.2 — Adaptador canónico de durable retry.** Servicio sin REST que
   resuelva etapa, revalide precondiciones y delegue al scheduler; pruebas de
   lease, terminalidad, límite e idempotencia.
3. **37.4.3 — Ledger/deduplicación administrativa.** Auditoría mínima y
   fingerprint de idempotencia, sin PII; contrato de outcomes e incertidumbre.
4. **37.4.4 — REST admin-actions.** Ruta, parser estricto, auth, nonce, no-store,
   errores seguros y pruebas integradas.
5. **37.4.5 — Transporte/estado frontend.** Un POST, secuencia, abort, GET
   autoritativo y normalización de errores.
6. **37.4.6 — Confirmación/render accesible.** Pending, doble clic, cancelación,
   `role=status/alert`, foco y contexto `return_*`.
7. **37.4.7 — Inicialización e integración.** Wiring sin listeners duplicados y
   harness browser completo.
8. **37.4.8 — Certificación integrada.** Presupuestos, seguridad, concurrencia,
   privacidad, invariantes y documentación final.

Cada microhito es seleccionable y no adelanta categorías B/C/D.

## 17. Decisión de primera implementación

La primera implementación debe ser **37.4.1: política privada read-only**, no el
endpoint. Debe modelar únicamente `refresh` informativo y la elegibilidad
teórica cerrada de `retry_durable_processing`, conservando la ejecución
deshabilitada hasta certificar el adaptador y la deduplicación.

El endpoint, la UI y cualquier mutación quedan expresamente posteriores a la
certificación independiente de policy, adaptador canónico, deduplicación e
idempotencia.

La primera mutación publicable, tras esos hardenings, será
`retry_durable_processing` para una etapa no terminal inequívocamente elegible.
No debe usarse para #792/#793 ni para cualquier saneamiento histórico.

## 18. Criterios de cierre de la Serie 37.4

- Una única acción mutable allowlisted y delegada a autoridad canónica.
- Ninguna escritura directa de Orders ni tablas ajenas.
- Revalidación backend por facts, versión, lease, terminalidad e intentos.
- Idempotencia demostrada ante doble clic, dos administradores y timeout.
- Operación incierta representada honestamente.
- GET antes/después conserva exactamente tres SQL.
- REST privado, nonce, no-store y errores seguros en todos los estados.
- UI accesible con confirmación, pending, foco y descarte de respuestas tardías.
- Invariantes de Reservations, Payment, pickup y delivery sin regresiones.
- Blockers no relacionados permanecen visibles.
- #792/#793 quedan fuera del flujo normal y sin mutaciones.
- Matriz REST, DOM, concurrencia y seguridad completa.
- Certificación final documental y staging selectivo separado.

## 19. Veredicto

Orders puede ofrecer una fachada administrativa muy estrecha para reactivar
trabajo durable que ya posee autoridad, idempotencia, lease y precondiciones.
No puede convertirse en editor de estados distribuidos. En el estado actual,
la única familia candidata A es el retry de una etapa durable no terminal; las
inconsistencias históricas de Reservations y Fulfillment requieren tratamiento
separado y no justifican acciones directas en la UI.
