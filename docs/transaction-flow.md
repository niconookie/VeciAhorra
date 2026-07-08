# VeciAhorra — Flujo transaccional definitivo

## 1. Alcance

Este documento fija el contrato estabilizado del flujo:

```text
Cart → Checkout → Reservations → Orders → Payments
```

La unidad comercial comienza con un carrito validado, crea Orders reservadas,
mantiene stock temporalmente bloqueado y termina en pago `paid` con reservas
`consumed`, o en pago `failed` sin alterar Orders ni reservas.

## 2. Estados por módulo

| Módulo | Estados relevantes | Estado final exitoso |
|---|---|---|
| Cart | sin estado; existen o no filas | vacío tras Checkout exitoso |
| Reservation | `active`, `released`, `expired`, `consumed` | `consumed` |
| Order | `reserved`, `paid` | `paid` |
| Payment | `pending`, `paid`, `failed` | `paid` |

`released`, `expired`, `consumed`, `paid` y `failed` son terminales en el flujo
actual. No se reabren automáticamente.

## 3. Diagrama del flujo

```text
Cart items
   │ validate (sin escrituras)
   ▼
CheckoutValidationService
   │ válido
   ▼
ReservationService ── lockStock ──► Inventory.stock disminuye temporalmente
   │ active, 15 minutos
   ▼
OrderService ──► Order reserved + OrderItems
   │             reservas asociadas al Order
   └──► CartService.clearCart
                    │
                    ▼
PaymentService ──► Payment pending + payment_orders
                    │
                    ▼
PaymentSessionService ──► sesión Dummy idempotente
                    │
                    ▼
PaymentConfirmationService
   ├─ paid   ─► Reservations consumed ─► Orders paid ─► Payment paid
   └─ failed ─► Payment failed; Orders/Reservations/Inventory sin cambios
```

## 4. Responsabilidades

### Cart

- Mantiene selección, cantidad e identidad del comprador.
- No bloquea stock ni crea Orders.
- Se limpia únicamente después de crear y asociar todas las Orders y reservas.
- Un reintento de Checkout sobre el carrito ya consumido termina como carrito
  vacío y no duplica escrituras.

### Checkout

- Valida Cart, Inventory y Products antes de escribir.
- Orquesta reservas, Orders y limpieza del carrito.
- Agrupa un Order por minimarket.
- Compensa todas las Orders, reservas y bloqueos creados si falla un paso.
- No crea Payments ni confirma stock.

### Reservations

- Es la única capa que coordina InventoryLockService.
- `active` significa stock retirado temporalmente de disponibilidad.
- Expirar o liberar incrementa stock una sola vez.
- Confirmar llama `commitStock` y cambia a `consumed`; no resta stock otra vez.
- Sólo reservas `active` de Orders válidas pueden consumirse.

### Orders

- Persiste encabezado e ítems congelados.
- Checkout crea Orders `reserved` con Reservations asociadas.
- PaymentConfirmationService solicita el cambio `reserved → paid`.
- No conoce gateways ni decide resultados de pago.

### Payments

- Valida que todos los Order IDs existan, pertenezcan al cliente, estén
  `reserved`, tengan reservas `active` y sumen exactamente el monto solicitado.
- Un conjunto de Orders sólo produce un Payment; repetir la creación devuelve
  el existente si el contrato coincide.
- La sesión se genera detrás de PaymentGatewayInterface y es determinista para
  el mismo Payment.
- La confirmación bloquea el Payment, aplica transiciones en una transacción y
  es idempotente.

## 5. Transiciones permitidas

```text
Reservation: active → consumed
             active → expired
             active → released

Order:       reserved → paid

Payment:     pending → paid
             pending → failed
```

Condiciones:

- `Reservation active → consumed` requiere confirmación exitosa, Inventory
  existente y Order asociada.
- `Order reserved → paid` requiere que todas sus reservas sean consumibles en
  la misma transacción.
- `Payment pending → paid` ocurre al final de la transacción exitosa.
- `Payment pending → failed` sólo cambia Payment.
- Una transición con estado de origen distinto se rechaza o devuelve el estado
  terminal actual si es un reintento idempotente.

## 6. Límites transaccionales y compensaciones

### Checkout

Checkout usa compensación explícita porque todavía no existe un coordinador de
transacción compartido para toda su creación:

1. ReservationService libera los bloqueos y elimina reservas parciales.
2. OrderService elimina OrderItems y Orders parciales en orden inverso.
3. El carrito se conserva si la operación no termina.
4. El carrito se limpia sólo como último paso exitoso.

Fallo de validación o stock ocurre antes de publicar Orders. Un fallo creando
la segunda Order elimina también la primera y todas las reservas del intento.

### Creación de Payment

PaymentService valida todas las referencias antes de insertar. Si falla la
asociación `payment_orders`, elimina asociaciones parciales y Payment. La
restricción única de `order_id` impide que un Order pertenezca a dos Payments.

### Confirmación de Payment

PaymentRepository delimita `START TRANSACTION`, `COMMIT` y `ROLLBACK` sobre la
misma conexión `$wpdb`. Dentro del callback:

1. bloquea Payment con `FOR UPDATE`;
2. vuelve a comprobar proveedor y estado;
3. confirma reservas mediante ReservationService/InventoryLockService;
4. cambia todas las Orders de `reserved` a `paid`;
5. cambia Payment de `pending` a `paid` y registra `paid_at`;
6. confirma sólo si todas las escrituras tuvieron éxito.

Cualquier excepción revierte las tres familias de estados. Las operaciones
condicionan el estado de origen y exigen que el número de filas afectadas sea el
esperado, evitando éxitos parciales silenciosos.

## 7. Reglas de integridad

- Todo OrderItem debe tener una Order existente.
- Toda Reservation de checkout debe asociarse a una Order antes del éxito.
- Toda Order incluida en Payment debe existir, estar reservada y tener al menos
  una Reservation activa.
- Todo `payment_orders.order_id` es único.
- Una Order `paid` debe pertenecer a un Payment `paid`.
- Una Reservation `consumed` debe pertenecer a una Order `paid`.
- El monto de Payment debe ser igual a la suma decimal de sus Orders.
- El cliente del Payment debe coincidir con el de todas sus Orders.
- `commitStock` nunca vuelve a decrementar unidades ya bloqueadas.

Estas garantías se aplican en Services y actualizaciones condicionales. Las
tablas actuales mantienen relaciones lógicas sin foreign keys físicas; por eso
las pruebas de integración auditan explícitamente huérfanos.

## 8. Idempotencia

| Operación | Garantía |
|---|---|
| Agregar CartItem | clave funcional propietario + inventory; incrementa sin duplicar fila |
| Ejecutar Checkout | éxito limpia Cart; reintento no recrea Orders o reservas |
| Crear reservas/Orders | una por ítem dentro del intento; compensación elimina parciales |
| Crear Payment | mismos Orders y mismo contrato devuelven el Payment existente |
| Crear sesión | mismo Payment produce referencia, URL y expiración deterministas |
| Confirmar `paid` | segunda llamada devuelve `paid` con cero filas modificadas |
| Confirmar `failed` | segunda llamada devuelve `failed` sin tocar dominio |

Claves diferentes o payloads incompatibles para Orders ya vinculadas se
rechazan; nunca se reinterpretan silenciosamente.

## 9. Comportamiento ante fallos

| Fallo | Resultado |
|---|---|
| Carrito vacío | validación inválida, sin escrituras |
| Inventory inexistente/inactivo | validación inválida, sin escrituras |
| Product inactivo | validación inválida, sin escrituras |
| Stock insuficiente | sin Order ni reserva; stock no negativo |
| Persistencia de reserva | libera locks y elimina reservas parciales |
| Persistencia de Order/OrderItem | elimina Orders parciales y libera reservas |
| Limpieza de Cart | compensa Orders y reservas; no informa éxito |
| Order inválida al crear Payment | 422; no crea Payment |
| Asociación parcial de Payment | elimina Payment y asociaciones parciales |
| Payment inexistente | 404 |
| Estado/proveedor inválido | 422, sin cambios |
| Gateway devuelve fallo | Payment `failed`; Orders/reservas permanecen |
| Excepción confirmando | rollback; Payment continúa `pending` |
| Confirmación repetida | devuelve estado terminal, sin efectos adicionales |

## 10. Prohibiciones por módulo

- Cart no consulta gateways, no crea reservas y no modifica stock.
- Checkout no ejecuta SQL, no confirma pagos y no descuenta stock directamente.
- ReservationRepository no decide transiciones; sólo persiste condiciones
  solicitadas por ReservationService.
- OrderService no confirma gateways ni modifica Payments.
- PaymentRepository no decide reglas comerciales ni llama otros módulos.
- PaymentConfirmationService no escribe SQL ni implementa stock; coordina los
  Services propietarios.
- DummyPaymentGateway no modifica base de datos ni dominio.
- Controllers y Routes sólo validan/adaptan y traducen respuestas.

## 11. Evidencia de pruebas

`transactional-workflow-test.php` recorre el flujo completo y verifica estados,
stock estable, idempotencia y ausencia de huérfanos. Las suites específicas
cubren además stock insuficiente, inventario/producto inválido, fallos
intermedios de reservas y Orders, pago fallido, rollback de confirmación y
concurrencia simulada.
