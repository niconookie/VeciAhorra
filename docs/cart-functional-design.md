# VeciAhorra 22.0 — Shopping Cart Functional Design

## 1. Objetivo y límites

El módulo Cart mantendrá una selección temporal de productos antes de crear
pedidos. Puede contener inventario de varios minimarkets, pero no descuenta ni
bloquea stock. El bloqueo seguirá ocurriendo únicamente cuando OrderService
cree el pedido y ReservationService genere las reservas.

22.1 implementará persistencia y operaciones CRUD del carrito. Checkout, pagos,
WP-Cron, sincronización con WooCommerce y conversión del carrito en pedidos
quedan fuera de 22.1.

## 2. Decisiones de diseño

- El ítem se identifica comercialmente por `inventory_id`, no sólo por
  `product_id`. Inventory determina producto, minimarket y precio.
- Un propietario sólo puede tener una fila por `inventory_id`. Agregar el mismo
  inventario incrementa la cantidad en vez de duplicar filas.
- El carrito admite múltiples minimarkets. La respuesta se agrupa por
  `minimarket_id` y mantiene subtotales por grupo y total general.
- El precio guardado es un snapshot informativo del momento en que se agregó o
  actualizó el ítem. Inventory conserva la autoridad sobre el precio vigente.
- Agregar o editar puede advertir sobre disponibilidad, pero no la garantiza.
  La validación obligatoria ocurre nuevamente antes de checkout.
- No se llama a InventoryLockService, ReservationService ni OrderService desde
  las operaciones CRUD del carrito.

## 3. Flujo del usuario

1. El usuario elige un producto y uno de sus inventarios/minimarkets.
2. El cliente envía `inventory_id` y `quantity` a `POST /cart/items`.
3. El servidor obtiene Inventory y deriva `product_id`, `minimarket_id` y
   `unit_price_snapshot`; no confía en esos datos si vienen del cliente.
4. Si el inventario ya está en el carrito, se suma la cantidad solicitada.
5. `GET /cart` devuelve ítems agrupados por minimarket y sus subtotales.
6. El usuario modifica cantidades con PATCH o elimina ítems con DELETE.
7. Al iniciar checkout, un futuro CheckoutService vuelve a consultar Inventory,
   presenta cambios de precio o disponibilidad y divide el carrito por
   minimarket.
8. Cada grupo aceptado crea un pedido independiente. OrderService activa el
   flujo existente de bloqueo y reservas de 15 minutos.

Seleccionar otro proveedor para el mismo producto equivale a eliminar el ítem
anterior y agregar el `inventory_id` del proveedor elegido. Ambos inventarios
pueden coexistir si el usuario los agrega expresamente.

## 4. Reglas de negocio

### Escritura

- `quantity` es un entero entre 1 y un máximo configurable; para 22.1 se
  recomienda 99.
- Inventory debe existir y estar `active` al agregar un ítem.
- El producto asociado también debe estar disponible según las reglas vigentes
  de Products.
- `product_id`, `minimarket_id` y precio se copian desde Inventory.
- No se bloquea stock. Una cantidad mayor al stock actual debería rechazarse en
  22.1 para feedback temprano, sin prometer disponibilidad futura.
- PATCH reemplaza la cantidad total, no aplica un delta.
- Los cálculos monetarios se realizan con decimales de dos posiciones; no con
  aritmética float acumulativa.

### Lectura y totales

- Subtotal de ítem: `unit_price_snapshot * quantity`.
- Subtotal de minimarket: suma de sus ítems.
- Total del carrito: suma de los grupos.
- La respuesta puede incluir `current_unit_price`, `price_changed`,
  `available_stock` e `availability` calculados desde Inventory. Estos campos no
  modifican silenciosamente el snapshot.
- El orden recomendado es minimarket ascendente e ítems por fecha de creación.

### Pre-checkout

Antes de crear pedidos se debe volver a validar, para cada ítem:

- existencia y estado de Inventory;
- correspondencia de producto y minimarket;
- precio vigente;
- stock suficiente para la cantidad total.

Si cambia el precio, checkout debe pedir confirmación usando el precio vigente.
Si falta stock o el inventario está inactivo, el grupo no se envía a Orders. La
validación previa reduce fallos, pero OrderService sigue siendo la protección
definitiva frente a carreras y sobreventa.

Cada minimarket genera un pedido independiente. Se recomienda que checkout
devuelva resultados por grupo: los grupos exitosos salen del carrito y los
fallidos permanecen para corrección. Una garantía atómica entre minimarkets
requeriría una fase posterior de cancelación coordinada y no forma parte de
22.1.

## 5. Persistencia recomendada

Sí se requiere una tabla propia `va_cart_items`. Un carrito sólo en sesión PHP o
en metadatos de WooCommerce impediría una API consistente para invitados y
acoplaría prematuramente el dominio a WooCommerce.

| Campo | Tipo lógico | Regla |
|---|---|---|
| `id` | bigint unsigned | PK autoincremental |
| `owner_key` | varchar(64) | Clave opaca derivada por el servidor |
| `user_id` | bigint unsigned nullable | Usuario WordPress autenticado |
| `inventory_id` | bigint unsigned | Inventario seleccionado |
| `product_id` | bigint unsigned | Snapshot relacional desde Inventory |
| `minimarket_id` | bigint unsigned | Snapshot relacional desde Inventory |
| `quantity` | int unsigned | Mayor que cero |
| `unit_price_snapshot` | decimal(10,2) | Precio visto al agregar/actualizar |
| `created_at` | datetime | Fecha de creación |
| `updated_at` | datetime | Último cambio |

Índices:

- unique (`owner_key`, `inventory_id`), para evitar duplicados;
- index (`user_id`);
- index (`minimarket_id`);
- index (`updated_at`), útil para limpieza futura.

`owner_key` evita las dificultades de índices únicos con columnas nullable:

- autenticado: hash estable de `user:{user_id}` con secreto del servidor;
- invitado: hash SHA-256 de un token aleatorio de alta entropía guardado en una
  cookie segura. Nunca se guarda el token crudo.

La cookie invitada debería ser `HttpOnly`, `Secure` en HTTPS y `SameSite=Lax`.
22.1 puede crearla al primer acceso al carrito. El merge invitado/autenticado se
reserva para una iteración posterior; hasta definirlo, iniciar sesión mantiene
separados ambos propietarios y evita fusiones sorpresivas.

No se recomienda una tabla `carts` adicional en 22.1: no hay todavía estado de
checkout, moneda, cupón ni ciclo de vida del encabezado. Puede añadirse cuando
aparezcan esas necesidades sin cambiar la identidad de los ítems.

## 6. Arquitectura de 22.1

```text
app/Modules/Cart/
  Requests/
    CartItemCreateRequest.php
    CartItemUpdateRequest.php
  Repository/
    CartRepository.php
  Service/
    CartService.php
    CartOwnerResolver.php
  Controller/
    CartController.php
  Routes/
    CartRoutes.php
```

Se conserva la convención singular usada por Reservations para Repository,
Service y Controller.

### Responsabilidades

`CartOwnerResolver`

- resuelve `owner_key` sin aceptar identidad desde el body o query string;
- usa el usuario WordPress actual o emite/lee el token invitado;
- será el único componente consciente de cookies e identidad.

`CartRepository`

- persiste, lista, actualiza y elimina filas del propietario;
- implementa el upsert por (`owner_key`, `inventory_id`);
- no calcula precios, stock, grupos ni permisos.

`CartService`

- valida Inventory y deriva sus snapshots;
- aplica límites de cantidad;
- calcula subtotales y agrupa por minimarket;
- garantiza que toda operación esté acotada al propietario resuelto;
- prepara en el futuro el DTO de pre-checkout, sin crear pedidos en 22.1.

`CartController`

- traduce excepciones a resultados de aplicación;
- no accede a `$wpdb`, cookies ni reglas monetarias.

`CartRoutes`

- valida JSON, IDs y métodos HTTP;
- registra las rutas una sola vez desde Application;
- delega identidad, negocio y persistencia.

## 7. Contrato REST propuesto

Namespace: `veciahorra/v1`.

### GET `/cart`

Devuelve el carrito del propietario actual.

```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "minimarket_id": 12,
        "items": [
          {
            "id": 81,
            "inventory_id": 44,
            "product_id": 9,
            "quantity": 2,
            "unit_price_snapshot": "1290.00",
            "subtotal": "2580.00",
            "availability": "available",
            "price_changed": false
          }
        ],
        "subtotal": "2580.00"
      }
    ],
    "items_count": 2,
    "total": "2580.00"
  }
}
```

`items_count` representa unidades totales; opcionalmente puede añadirse
`lines_count` para el número de filas.

### POST `/cart/items`

Body: `{ "inventory_id": 44, "quantity": 2 }`.

- 201 si crea una fila;
- 200 si incrementa una fila existente, o 201 en ambos casos si se prefiere un
  contrato de upsert uniforme. Se recomienda devolver 200/201 explícitamente;
- 404 si Inventory no existe;
- 409 si Inventory está inactivo o no hay disponibilidad actual;
- 422 para payload inválido.

### PATCH `/cart/items/{id}`

Body: `{ "quantity": 3 }`. Reemplaza la cantidad y refresca
`unit_price_snapshot` con el precio vigente, después de validar Inventory.
Devuelve el ítem actualizado o 404 si no pertenece al propietario.

### DELETE `/cart/items/{id}`

Elimina sólo un ítem perteneciente al propietario. Respuesta recomendada: 200
con `{ "deleted": true }`, siguiendo el estilo actual de respuestas JSON.

### DELETE `/cart`

Elimina todos los ítems del propietario. Es idempotente y devuelve la cantidad
de filas eliminadas.

Todas las respuestas conservan el envoltorio actual `success/data` o
`success/error`. Códigos de error sugeridos: `validation_error`,
`cart_item_not_found`, `inventory_not_found`, `inventory_unavailable`,
`price_changed` e `internal_error`.

## 8. Seguridad y permisos

- Un cliente nunca puede proporcionar `owner_key`, `user_id`, `product_id`,
  `minimarket_id` ni precio.
- Todas las consultas y mutaciones incluyen `owner_key` en el WHERE.
- Para usuarios autenticados se exige la protección nonce habitual de la REST
  API de WordPress.
- Para invitados, el token opaco funciona como credencial del carrito y debe
  combinarse con restricciones CORS, HTTPS y cookie SameSite.
- Los límites de cantidad, tamaño del carrito y frecuencia deben aplicarse en
  Service o infraestructura, nunca confiarse al frontend.
- No se deben exponer datos privados del minimarket o del usuario en GET.

## 9. Casos borde y respuesta esperada

| Caso | Comportamiento |
|---|---|
| Stock disminuye mientras está en carrito | GET marca disponibilidad; pre-checkout rechaza o solicita reducir cantidad |
| Precio cambia | Se conserva snapshot, se informa cambio y checkout exige confirmar precio vigente |
| Inventory queda inactivo | El ítem permanece visible como no disponible y no puede pasar a checkout |
| Producto queda inactivo | Igual que Inventory inactivo; se bloquea pre-checkout |
| Stock llega a cero | No se reserva; el ítem puede eliminarse o conservarse marcado |
| Mismo inventario agregado otra vez | Upsert e incremento de cantidad, sin fila duplicada |
| PATCH con cero | 422; para eliminar se usa DELETE explícito |
| Ítem de otro propietario | 404, sin revelar su existencia |
| Invitado inicia sesión | Sin merge automático en 22.1; política futura explícita |
| Cookie invitada perdida | Se crea un carrito nuevo; el anterior queda abandonado |
| Carrito abandonado | No afecta stock; limpieza por `updated_at` se diseñará después, sin cron en 22.1 |
| Dos pestañas actualizan a la vez | Upsert y PATCH deben ser atómicos; la última escritura válida prevalece |

## 10. Integraciones futuras

### Orders y Reservations

Un futuro CheckoutService recibirá el carrito revalidado, lo agrupará por
minimarket y llamará una vez a OrderService por grupo. Cart no llamará
directamente a ReservationRepository ni modificará stock. El flujo 21.x seguirá
siendo la barrera final contra sobreventa.

### WooCommerce

Cart será inicialmente dominio propio. Una integración posterior puede mapear
el carrito o los pedidos a sesiones/órdenes WooCommerce mediante un adaptador,
sin convertir objetos WC en dependencias de CartService.

### Usuarios invitados

La identidad opaca permite operar antes del login. Una versión futura podrá
fusionar carritos dentro de una transacción, sumando cantidades por
`inventory_id`, revalidando límites y eliminando el carrito invitado sólo tras
completar el merge.

## 11. Pruebas recomendadas para 22.1

### Request

- acepta IDs y cantidades positivas;
- rechaza cero, negativos, decimales, arrays y overflow;
- ignora o rechaza campos controlados por servidor.

### Repository

- crea y lista sólo por propietario;
- upsert evita duplicados;
- PATCH y DELETE no afectan otro propietario;
- limpiar carrito es idempotente;
- consultas preparadas y prefijo físico no hardcodeado.

### Service

- deriva producto, minimarket y precio desde Inventory;
- agrega e incrementa ítems;
- actualiza y elimina;
- agrupa por minimarket;
- calcula subtotales y total con precisión decimal;
- detecta cambios de precio, stock y estado;
- nunca invoca InventoryLockService al escribir el carrito.

### REST e integración

- registra cada método una sola vez;
- conserva el contrato `success/data/error`;
- aísla dos propietarios autenticados y dos invitados;
- valida stock antes del futuro checkout;
- un carrito multi-minimarket produce grupos aptos para pedidos separados;
- regresión de Products, Inventory, Orders y Reservations.

## 12. Secuencia propuesta de implementación 22.1

1. Añadir `CartItemSchema`, migración idempotente y nueva versión de esquema.
2. Implementar `CartOwnerResolver` y pruebas de aislamiento.
3. Implementar Requests y CartRepository.
4. Implementar CartService y serialización agrupada.
5. Implementar Controller y Routes con los cinco endpoints.
6. Registrar CartRoutes una sola vez en Application.
7. Añadir pruebas manuales/unitarias y ejecutar regresión completa.

## 13. Criterios de aceptación de 22.1

- Los cinco endpoints CRUD funcionan para el propietario actual.
- El servidor deriva toda información comercial desde Inventory.
- No existen duplicados por propietario e inventario.
- La respuesta se agrupa por minimarket y calcula totales correctos.
- Ninguna operación del carrito bloquea o descuenta stock.
- Carritos distintos están completamente aislados.
- No se crean pedidos ni reservas desde Cart.
- PHP lint, pruebas de Cart y regresiones existentes pasan.
