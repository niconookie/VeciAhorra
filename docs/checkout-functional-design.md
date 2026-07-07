# VeciAhorra 23.0 — Checkout Functional Design

## 1. Propósito del Checkout

Checkout transforma un carrito validado en uno o más pedidos reservados. Es el
orquestador que fija una intención de compra, verifica que las referencias del
carrito sigan siendo utilizables y coordina la creación atómica de pedidos sin
duplicar responsabilidades de otros módulos.

Conecta:

- Cart, como fuente de identidad, cantidades y precios congelados;
- Inventory, para validar existencia, estado y disponibilidad;
- Products y Minimarkets, para validar que la oferta siga habilitada;
- Orders, para crear un pedido por minimarket;
- Reservations, indirectamente a través de OrderService, para bloquear stock y
  crear reservas activas de 15 minutos.

La primera versión no procesa pagos, envíos, cupones, impuestos, propinas,
facturación electrónica, confirmación definitiva de stock, cancelaciones,
WooCommerce ni frontend. Tampoco fusiona carritos de invitado y usuario.

## 2. Principios del diseño

- Checkout orquesta; no persiste pedidos, reservas ni inventario directamente.
- El precio comercial proviene de `cart_items.unit_price_snapshot`.
- Inventory se consulta para existencia, relaciones, estado y stock, pero no
  para recalcular el precio del pedido.
- Un minimarket produce exactamente un Order.
- Un checkout con varios minimarkets produce varios Orders.
- La primera versión ejecutable es all-or-nothing: no hay éxito parcial.
- Validar no bloquea stock. Ejecutar sí lo bloquea mediante el flujo existente
  OrderService → ReservationService → InventoryLockService.
- El carrito se elimina sólo después de confirmar todas las escrituras.
- La API no acepta `user_id` desde el cliente; usa la identidad autenticada.

## 3. Flujo general

### 3.1 Validación sin efectos

1. La capa REST resuelve la identidad actual.
2. CheckoutService solicita a CartService los ítems de esa identidad.
3. CheckoutValidationService rechaza un carrito inexistente o vacío.
4. Para cada CartItem valida IDs, cantidad y snapshot de precio.
5. Consulta Inventory y comprueba existencia, estado, stock y correspondencia
   de `product_id` y `minimarket_id`.
6. Consulta Products y Minimarkets cuando sus repositorios ofrezcan contratos
   de lectura adecuados.
7. Agrupa los ítems válidos por `minimarket_id`.
8. Devuelve una vista previa con grupos, subtotales y total, sin crear pedidos,
   reservas ni bloqueos.

La validación es informativa. No garantiza que el stock siga disponible durante
la ejecución posterior.

### 3.2 Ejecución

1. Resuelve y valida nuevamente identidad y carrito.
2. Reclama una clave de idempotencia para impedir doble submit.
3. Inicia una transacción de base de datos.
4. Repite la validación final dentro de la ventana transaccional.
5. Construye un payload de Order por minimarket.
6. Invoca OrderService una vez por grupo. OrderService bloquea stock de forma
   atómica, crea Order/OrderItems y crea una Reservation activa por ítem.
7. Si todos los grupos resultan exitosos, limpia el carrito dentro de la misma
   transacción.
8. Confirma la transacción y marca el intento como completado.
9. Devuelve todos los pedidos y sus reservas.

Si cualquier paso falla, se revierte la transacción completa. El cliente recibe
un error y conserva el carrito para corregir o reintentar.

## 4. Relación con módulos existentes

### Cart

Cart es la fuente de `inventory_id`, `product_id`, `minimarket_id`, `quantity` y
`unit_price_snapshot`. Checkout nunca mezcla filas pertenecientes a otra
identidad. La limpieza usa `CartService::clearCart()` sólo tras completar todos
los pedidos.

Cart no bloquea stock y puede contener referencias que quedaron obsoletas. Por
eso Checkout debe revalidar todas las filas.

### Inventory

Inventory confirma que el inventario existe, está `active`, pertenece al
producto y minimarket guardados y tiene stock suficiente. La lectura previa no
reemplaza el `UPDATE ... WHERE stock >= quantity` de InventoryLockService.

Checkout no modifica `inventory.stock` directamente.

### Reservations

OrderService ya integra ReservationService e InventoryLockService. Checkout no
debe crear una reserva previa ni descontar stock por su cuenta, porque eso
produciría doble bloqueo. Cada Order creado queda inicialmente `reserved` y cada
ítem genera una Reservation `active` con expiración de 15 minutos.

ReservationExpirationService continúa liberando el stock de reservas vencidas.
Una reserva expirada antes de terminar una fase futura de pago invalida esa
intención y exige revalidar o reiniciar checkout.

### Orders

CheckoutOrderBuilder adapta cada grupo al contrato de OrderService:

- `customer_id`: usuario autenticado actual;
- `minimarket_id`: clave del grupo;
- `items[].inventory_id` y `items[].product_id`: CartItem validado;
- `items[].quantity`: cantidad del carrito;
- `items[].unit_price`: `unit_price_snapshot`, convertido sin perder precisión.

Checkout no usa OrderRepository directamente.

### Products

El producto referenciado debe existir. Según el modelo actual, el estado apto
para venta es `active`; cualquier otro estado (`draft`, `inactive` u otro no
publicable) bloquea el checkout. La validación debe comprobar también que el
producto de Inventory coincida con el snapshot relacional del CartItem.

### Minimarkets

`minimarket_id` corresponde actualmente a la tabla lógica Stores. Para vender,
el minimarket debe existir, tener `status = active` y, cuando esa política esté
formalizada, un onboarding aprobado. Los estados `pending`, `inactive` y
`rejected` no pueden generar pedidos.

### Frontend futuro

El frontend consumirá primero `/checkout/validate`, mostrará conflictos y sólo
habilitará la ejecución tras una validación exitosa. Aun así, `/checkout`
repite todas las comprobaciones para no confiar en datos ni tiempos del cliente.

## 5. Identidad del comprador

Cart soporta dos identidades excluyentes:

- autenticado: `user_id` obtenido de `get_current_user_id()`;
- invitado: `session_id` resuelto por el mecanismo REST de Cart.

La primera versión que crea pedidos aceptará sólo usuarios autenticados. La
razón es estructural: `orders.customer_id` es obligatorio y actualmente no
existe una entidad persistente de comprador invitado ni un contrato para sus
datos mínimos. No se debe inventar un ID de usuario ni reutilizar `session_id`
como `customer_id`.

`POST /checkout/validate` puede inspeccionar un carrito invitado y devolver que
requiere autenticación. `POST /checkout` responde 422 con
`guest_checkout_not_supported` hasta implementar un modelo explícito de cliente
invitado. Una fase futura podrá aceptar nombre, correo, teléfono y dirección,
crear una identidad de comprador y conservar `session_id` sólo como dueño del
carrito.

Si un usuario autenticado envía además una sesión, prevalece `user_id`; no se
mezclan ni fusionan carritos.

## 6. Validaciones previas

CheckoutValidationService debe producir una lista estable de errores por ítem y
errores globales. Como mínimo valida:

1. identidad presente y permitida para la operación;
2. carrito encontrado y no vacío;
3. cada CartItem tiene `id` e `inventory_id` positivos;
4. `quantity` es un entero mayor que cero;
5. `unit_price_snapshot` existe, es decimal válido y no es negativo;
6. Inventory existe;
7. Inventory tiene `status = active`;
8. Inventory corresponde a `product_id` y `minimarket_id` del CartItem;
9. Product existe y tiene estado vendible (`active` cuando aplique);
10. Minimarket existe y está habilitado (`active` y aprobado cuando aplique);
11. el stock actual es mayor o igual a la cantidad;
12. no hay dos filas inesperadas con el mismo `inventory_id` para el dueño;
13. todos los ítems agrupados bajo un minimarket realmente le pertenecen.

La validación de stock en esta fase mejora el mensaje al usuario, pero no
reserva. El bloqueo atómico durante OrderService es la autoridad final.

## 7. Snapshot de precio

El precio usado para OrderItem es exclusivamente
`cart_items.unit_price_snapshot`. Checkout no reemplaza ese valor con
`inventory.price`, aunque haya cambiado.

El snapshot queda congelado porque representa el precio que el usuario vio al
agregar el producto y evita cambios silenciosos entre carrito y pedido. También
permite auditar por qué un OrderItem recibió cierto precio.

Inventory sigue consultándose, pero sólo para existencia, relación, estado y
stock. Si el negocio decide más adelante expirar precios o exigir reconfirmación,
deberá introducir una regla explícita y versionada; no debe ocurrir como efecto
secundario de Checkout.

Los cálculos de subtotal usan aritmética decimal:

```text
subtotal_item = unit_price_snapshot × quantity
total_order = suma de subtotales del minimarket
total_checkout = suma de todos los orders
```

No se acumulan importes con `float` sin redondeo controlado a dos decimales.

## 8. Agrupación por minimarket

CheckoutOrderBuilder agrupa por `minimarket_id` después de validar cada fila.
Un grupo se transforma en un solo payload para OrderService y nunca contiene
ítems de otro minimarket.

Ejemplo:

```text
Carrito
├─ Minimarket 12 → Inventory 40, Inventory 41 → Order A
└─ Minimarket 18 → Inventory 75               → Order B
```

La respuesta exitosa devuelve Order A y Order B. El orden recomendado de los
grupos es por `minimarket_id` ascendente para obtener resultados deterministas.

## 9. Estrategia de reservas y stock

La estrategia inicial es reutilizar reservas creadas durante este mismo
checkout mediante OrderService. No se aceptan reservas anteriores del cliente
ni se intenta confirmarlas o consumirlas en esta fase.

Secuencia por grupo:

1. validación de disponibilidad sin efectos;
2. OrderService llama a ReservationService;
3. InventoryLockService realiza el descuento atómico condicionado;
4. se crea una Reservation `active` por ítem;
5. el Order queda `reserved` con vencimiento alineado a 15 minutos.

Esto evita doble venta porque dos compradores del último stock compiten en el
UPDATE atómico; sólo uno afecta la fila. El perdedor recibe un conflicto y toda
su transacción de checkout se revierte.

Checkout no llama a `commitStock()` todavía. El consumo o confirmación de una
reserva corresponde a una fase posterior asociada al pago o confirmación
definitiva.

## 10. Transacciones y atomicidad

La política inicial es all-or-nothing:

- si falla un ítem, no se crea ningún pedido;
- si falla uno de varios minimarkets, se eliminan/revierten todos los pedidos y
  reservas del checkout;
- si falla la limpieza del carrito, también se revierte el checkout;
- no se informa éxito antes del COMMIT.

Las compensaciones actuales de OrderService son útiles, pero no bastan para
garantizar atomicidad entre varios Orders. CheckoutTransactionManager debe
envolver todas las escrituras en una única transacción InnoDB sobre la misma
conexión de `$wpdb`. Services no ejecutarán `START TRANSACTION` directamente;
el gestor de transacción encapsulará begin, commit, rollback y propagación de
errores.

Los repositorios y servicios llamados dentro del callback no deben abrir ni
confirmar transacciones independientes. Las tablas `inventory`, `orders`,
`order_items`, `reservations` y `cart_items` usan InnoDB y participan en la
misma unidad de trabajo.

## 11. Concurrencia e idempotencia

### Riesgos

- doble clic o reintento de red crea pedidos duplicados;
- dos usuarios intentan comprar el último stock;
- Inventory cambia entre validate y execute;
- una ejecución lenta se acerca al vencimiento de sus reservas;
- dos peticiones usan simultáneamente el mismo carrito.

### Estrategia

- `/checkout` exige una `Idempotency-Key` opaca por intento;
- la clave se asocia a la identidad y al resultado del checkout;
- una repetición completada devuelve el mismo resultado, sin recrear Orders;
- una repetición mientras está procesando devuelve 409;
- la validación se repite dentro de la ejecución;
- InventoryLockService mantiene el descuento condicionado y nunca permite
  stock negativo;
- el carrito se reclama lógicamente durante la operación para impedir dos
  conversiones concurrentes;
- reservas vencidas nunca se reutilizan.

Una idempotencia resistente a procesos y reinicios necesita persistencia. Por
eso no se habilitará públicamente `POST /checkout` hasta que exista el registro
de intentos descrito en la sección 15.

## 12. Contratos REST propuestos

Namespace: `veciahorra/v1`.

### POST `/checkout/validate`

No produce efectos. Identidad resuelta por servidor; body inicial vacío o con
datos de comprador admitidos en fases futuras.

Respuesta 200:

```json
{
  "success": true,
  "data": {
    "valid": true,
    "groups": [
      {
        "minimarket_id": 12,
        "items": [
          {
            "cart_item_id": 81,
            "inventory_id": 44,
            "product_id": 9,
            "quantity": 2,
            "unit_price": "1290.00",
            "subtotal": "2580.00"
          }
        ],
        "total": "2580.00"
      }
    ],
    "total": "2580.00",
    "errors": []
  }
}
```

Una validación de negocio fallida puede responder 422 con `valid: false` y una
lista de errores accionables por `cart_item_id`.

### POST `/checkout`

Headers:

```text
Idempotency-Key: 3f65e327-...
Content-Type: application/json
```

Body inicial: `{}`. No acepta `user_id`, `session_id`, precios, totales ni ítems
enviados por el cliente.

Respuesta 201:

```json
{
  "success": true,
  "data": {
    "checkout_id": "3f65e327-...",
    "orders": [
      {
        "id": 501,
        "minimarket_id": 12,
        "status": "reserved",
        "total": "2580.00",
        "reservation_expires_at": "2026-07-07 17:15:00"
      },
      {
        "id": 502,
        "minimarket_id": 18,
        "status": "reserved",
        "total": "990.00",
        "reservation_expires_at": "2026-07-07 17:15:00"
      }
    ],
    "total": "3570.00"
  }
}
```

Endpoints futuros:

- `GET /checkout/{id}` para recuperar un intento idempotente;
- `POST /checkout/{id}/cancel` para cancelar pedidos reservados y liberar
  reservas según una política formal.

## 13. Estados y errores

| HTTP | Código sugerido | Uso |
|---|---|---|
| 200 | `checkout_valid` | Validación exitosa |
| 201 | `checkout_created` | Todos los pedidos fueron creados |
| 400 | `checkout_identity_required` | No hay identidad REST válida |
| 400 | `idempotency_key_required` | Falta clave en ejecución |
| 404 | `cart_not_found` | No existe carrito para la identidad |
| 404 | `inventory_not_found` | Inventory referenciado desapareció |
| 404 | `product_not_found` | Product referenciado desapareció |
| 404 | `minimarket_not_found` | Minimarket referenciado desapareció |
| 409 | `insufficient_stock` | Stock final insuficiente |
| 409 | `checkout_in_progress` | Misma clave o carrito en proceso |
| 409 | `reservation_expired` | Reserva requerida ya venció |
| 422 | `empty_cart` | Carrito existente sin ítems |
| 422 | `invalid_cart_item` | IDs, cantidad o snapshot inválidos |
| 422 | `inventory_inactive` | Inventory no vendible |
| 422 | `product_inactive` | Product no vendible |
| 422 | `minimarket_inactive` | Minimarket no habilitado |
| 422 | `guest_checkout_not_supported` | Invitado en primera versión |
| 500 | `checkout_failed` | Fallo interno con rollback |

Los errores no exponen SQL, prefijos físicos ni excepciones internas. Una
respuesta fallida incluye `success: false`, `error.code`, `error.message` y,
cuando corresponda, `error.details` con problemas por ítem.

## 14. Casos borde

| Caso | Resultado esperado |
|---|---|
| Carrito vacío | 422 `empty_cart`; sin efectos |
| CartItem sin Inventory | 422 si ID inválido; 404 si la fila de Inventory no existe |
| Inventory eliminado | 404; carrito se conserva |
| Inventory inactivo | 422; carrito se conserva |
| Product deshabilitado | 422; carrito se conserva |
| Minimarket deshabilitado/no aprobado | 422; carrito se conserva |
| Stock insuficiente | 409; rollback completo |
| Snapshot ausente o inválido | 422; nunca se usa precio actual como fallback |
| Ítem duplicado inesperado | 422; no se suman silenciosamente cantidades |
| Usuario autenticado envía session_id | Se usa sólo user_id; no hay mezcla |
| Doble submit con misma clave | Mismo resultado o 409 mientras procesa |
| Doble submit con claves distintas sobre mismo carrito | Reclamo del carrito; una sola ejecución puede continuar |
| Falla el segundo de varios Orders | Rollback de todos los Orders, reservas y stock |
| Falla limpiar el carrito | Rollback; pedidos no quedan publicados |
| Reserva vence después del éxito | ExpirationService libera stock; Order permanece reservado/vencido según política futura |
| Invitado ejecuta checkout | 422 hasta existir identidad de comprador invitado |
| Invitado sin datos mínimos | 422 con campos requeridos cuando se habilite esa fase |

## 15. Modelo de datos preliminar

No se necesita una tabla para implementar la validación pura de 23.1/23.2. Los
datos fuente ya están en Cart, Inventory, Products y Stores.

Antes de exponer la ejecución REST sí se recomienda una tabla
`checkout_attempts` (o `checkout_sessions`) para idempotencia y auditoría:

| Campo | Propósito |
|---|---|
| `id` | Identificador interno |
| `idempotency_key` | Clave única del cliente |
| `user_id` / identidad | Dueño del intento |
| `status` | `processing`, `completed`, `failed` |
| `cart_fingerprint` | Huella de ítems/cantidades/snapshots |
| `response_payload` | Resultado serializado recuperable |
| `created_at`, `updated_at` | Auditoría y recuperación |

La restricción única debe incluir identidad e `idempotency_key`. Esta tabla es
una recomendación para 23.5, no una migración de la fase de diseño actual.

No se recomienda una tabla adicional sólo para agrupar Orders: los IDs creados
pueden relacionarse con `checkout_attempts` cuando se implemente auditoría.

## 16. Servicios propuestos

```text
app/Modules/Checkout/
  Requests/
  Repository/
  Service/
    CheckoutService.php
    CheckoutValidationService.php
    CheckoutOrderBuilder.php
    CheckoutTransactionManager.php
  Controller/
  Routes/
```

### CheckoutService

Orquesta identidad, validación, agrupación, transacción, creación de Orders,
limpieza e idempotencia. No ejecuta SQL ni manipula stock.

### CheckoutValidationService

Construye un resultado de validación inmutable, con grupos normalizados y
errores. Usa lectores/repositorios de Cart, Inventory, Products y Stores.

### CheckoutOrderBuilder

Convierte un grupo validado al payload exacto de OrderService. Copia
`unit_price_snapshot` a `unit_price` y recalcula subtotales con precisión
decimal.

### CheckoutTransactionManager

Ejecuta un callback dentro de una transacción InnoDB, confirma sólo al final y
hace rollback ante cualquier `Throwable`. Centraliza la conexión y evita SQL
transaccional en CheckoutService.

### CheckoutAttemptRepository

Se añade cuando se implemente `checkout_attempts`. Sólo persiste reclamos,
estados y resultados idempotentes; no contiene reglas de negocio.

### Integración con ReservationService

Checkout no la invoca directamente en la estrategia inicial. La integración
ocurre a través de OrderService, preservando el flujo ya probado y evitando
doble descuento.

## 17. Secuencia propuesta de implementación

### 23.1 — Checkout Backend Foundation

- estructura del módulo, DTOs y contratos;
- sin endpoint público de ejecución;
- sin escrituras en Orders.

### 23.2 — Checkout Validation Service

- identidad y lectura de Cart;
- validación de referencias/estados;
- agrupación y totales con snapshot;
- pruebas sin efectos.

### 23.3 — Reservation/Stock Integration

- validar compatibilidad con OrderService/ReservationService;
- pruebas del bloqueo final y conflictos;
- ninguna duplicación de descuentos.

### 23.4 — Order Creation Engine

- CheckoutOrderBuilder;
- múltiples Orders por minimarket;
- respuesta agregada.

### 23.5 — Transaction and Rollback

- CheckoutTransactionManager;
- `checkout_attempts` e idempotencia;
- reclamo del carrito y rollback completo.

### 23.6 — REST Integration

- `POST /checkout/validate` y `POST /checkout`;
- códigos HTTP y errores estandarizados;
- autenticación, nonce y `Idempotency-Key`.

### 23.7 — Checkout Feature Complete

- regresión integral;
- observabilidad y recuperación de intentos;
- documentación operativa;
- habilitación controlada del endpoint.

## 18. Estrategia de pruebas

### Validación

- identidad ausente e invitado no soportado;
- carrito inexistente y vacío;
- cantidad, Inventory y snapshot inválidos;
- Inventory/Product/Minimarket inexistentes o inactivos;
- stock suficiente e insuficiente.

### Precio y agrupación

- un cambio en `inventory.price` no altera OrderItem;
- snapshot decimal produce subtotales correctos;
- ítems del mismo minimarket forman un Order;
- varios minimarkets producen varios Orders sin mezclar ítems.

### Reservas, stock y concurrencia

- una Reservation activa por OrderItem;
- último stock sólo puede ser adquirido por un checkout;
- stock nunca queda negativo;
- fallo de reserva revierte Order y stock;
- reserva vencida no se reutiliza.

### Atomicidad e idempotencia

- fallo del segundo Order revierte el primero;
- fallo de limpieza revierte todos los Orders;
- carrito se conserva tras rollback;
- doble submit con la misma clave no duplica pedidos;
- dos claves sobre el mismo carrito no crean dos checkouts;
- reintento completado devuelve la misma respuesta.

### REST y regresión

- contratos 200/201/400/404/409/422/500;
- payload no puede inyectar identidad, precio, total o ítems;
- regresión completa de Cart, Inventory, Reservations, Orders, Products y
  Minimarkets.

## 19. Decisiones explícitas

- Checkout usa el precio congelado de CartItem.
- Inventory no recalcula el precio durante checkout.
- Un minimarket genera un Order.
- Múltiples minimarkets generan múltiples Orders.
- La primera versión es all-or-nothing.
- No existe checkout parcial.
- OrderService conserva la responsabilidad de bloquear stock y crear reservas.
- Checkout no descuenta stock ni crea Reservation directamente.
- Las reservas iniciales son activas y vencen en 15 minutos.
- La ejecución inicial acepta sólo usuarios autenticados.
- Los carritos invitados no se fusionan; checkout invitado queda para una fase
  con identidad de comprador explícita.
- La ejecución pública requiere transacción e idempotencia persistente.
- El carrito se limpia sólo después de crear todos los pedidos.
- No hay pagos, frontend, cancelación ni consumo definitivo en esta fase.
- Este documento no modifica código, servicios, migraciones ni endpoints.
