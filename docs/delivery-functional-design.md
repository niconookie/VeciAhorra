# VeciAhorra 26.5 — Delivery Functional Design

## 1. Objetivo

El módulo **Delivery** gestiona el despacho operativo de órdenes pagadas dentro
de VeciAhorra. Su alcance actual cubre creación de entregas, asignación de
repartidor, ciclo de estados, eventos básicos de seguimiento e integración final
con Orders cuando una entrega se completa.

Delivery no reemplaza a Orders, Payments, Reservations ni Checkout. Su rol es
representar el proceso logístico posterior al pago y mantenerlo separado del
flujo financiero y de inventario.

## 2. Arquitectura final

El módulo mantiene la misma arquitectura modular usada por Orders y Payments:

- **Models**
  - `Delivery`
  - `DeliveryTracking`
- **Repository**
  - `DeliveryRepository`
  - `DeliveryTrackingRepository`
- **Service**
  - `DeliveryService`
  - `DeliveryTrackingService`
- **Controller**
  - `DeliveryController`
- **Routes**
  - `DeliveryRoutes`
- **Database**
  - `CreateDeliveriesTable`
  - `CreateDeliveryTrackingTable`
  - `DeliverySchema`
  - `DeliveryTrackingSchema`

La integración con repartidores se limita a consultar `CourierRepository` para
validar existencia y aprobación. No crea paneles, autenticación ni gestión de
repartidores.

## 3. Flujo funcional completo

```text
Order paid
  ↓
Delivery created as pending
  ↓
Courier assigned
  ↓
Delivery assigned
  ↓
Delivery picked_up
  ↓
Tracking events
  ↓
Delivery delivered
  ↓
Order delivered
```

El flujo comienza sólo cuando Orders ya tiene una orden en estado `paid`. Al
marcar la entrega como `delivered`, Delivery actualiza únicamente el estado de la
orden relacionada a `delivered`.

## 4. Base de datos

### `wp_va_deliveries`

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Identificador interno. |
| `order_id` | BIGINT UNSIGNED | Orden pagada asociada. |
| `customer_id` | BIGINT UNSIGNED | Cliente derivado desde la orden. |
| `minimarket_id` | BIGINT UNSIGNED | Minimarket derivado desde la orden. |
| `courier_id` | BIGINT UNSIGNED NULL | Repartidor asignado. |
| `status` | VARCHAR(20) | Estado operativo de la entrega. |
| `created_at` | DATETIME | Fecha de creación. |
| `updated_at` | DATETIME | Fecha de última actualización. |

Índices:

- `PRIMARY KEY (id)`
- `order_id`
- `customer_id`
- `minimarket_id`
- `courier_id`
- `status`

### `wp_va_delivery_tracking`

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Identificador interno. |
| `delivery_id` | BIGINT UNSIGNED | Entrega asociada. |
| `latitude` | DECIMAL(10,7) NULL | Latitud opcional del evento. |
| `longitude` | DECIMAL(10,7) NULL | Longitud opcional del evento. |
| `event` | VARCHAR(30) | Tipo de evento registrado. |
| `created_at` | DATETIME | Fecha de registro. |

Índices:

- `PRIMARY KEY (id)`
- `delivery_id`
- `event`
- `created_at`

No se almacenan velocidad, precisión GPS, batería, dirección textual, ETA ni
datos de mapas.

## 5. Estados y transiciones

Estados permitidos:

- `pending`
- `assigned`
- `picked_up`
- `delivered`
- `cancelled`

Transiciones válidas:

| Desde | Hacia |
|---|---|
| `pending` | `assigned` |
| `pending` | `cancelled` |
| `assigned` | `picked_up` |
| `assigned` | `cancelled` |
| `picked_up` | `delivered` |

Transiciones rechazadas:

- `pending → picked_up`
- `pending → delivered`
- `assigned → delivered`
- `picked_up → pending`
- `delivered → pending`
- `cancelled → assigned`
- cualquier cambio desde `delivered`
- cualquier cambio desde `cancelled`

Las transiciones inválidas responden con `409` y el mensaje:

```text
Invalid delivery state transition.
```

## 6. Asignación de repartidor

La asignación se realiza mediante `assignCourier(int $deliveryId, int $courierId)`.

Reglas:

- Delivery debe existir.
- Courier debe existir.
- Courier debe estar aprobado.
- Delivery debe estar en `pending`.
- Delivery no debe tener `courier_id` previo.
- Al asignar correctamente:
  - `courier_id` queda persistido.
  - `status` cambia a `assigned`.
  - se registra un evento de tracking `assigned`.

Errores:

| Caso | HTTP | Mensaje |
|---|---:|---|
| Delivery inexistente | 404 | `Delivery not found.` |
| Courier inexistente | 404 | `Courier not found.` |
| Courier no aprobado | 422 | `Courier is not approved.` |
| Delivery ya asignado | 409 | `Delivery already assigned.` |
| Estado incompatible | 409 | `Delivery cannot be assigned in current state.` |

## 7. Tracking

Tracking permite registrar eventos operativos básicos asociados a una entrega.
No implementa mapas, GPS en tiempo real, ETA, polling, SSE ni interfaces de
usuario.

Eventos permitidos:

- `assigned`
- `picked_up`
- `location_update`
- `delivered`

Reglas:

- Delivery debe existir.
- Delivery no puede estar `cancelled`.
- El evento debe pertenecer al catálogo permitido.
- `latitude` y `longitude` son opcionales.

Errores:

| Caso | HTTP | Mensaje |
|---|---:|---|
| Delivery inexistente | 404 | `Delivery not found.` |
| Delivery cancelado | 409 | `Cannot track cancelled delivery.` |
| Evento inválido | 422 | `Invalid tracking event.` |

Eventos automáticos:

- Asignación de courier registra `assigned`.
- Cambio a `picked_up` registra `picked_up`.
- Cambio a `delivered` registra `delivered`.

## 8. API REST implementada

Namespace: `veciahorra/v1`

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/deliveries` | Lista entregas con filtros básicos. |
| `POST` | `/deliveries` | Crea una entrega para una orden pagada. |
| `GET` | `/deliveries/{id}` | Obtiene una entrega. |
| `PATCH` | `/deliveries/{id}/assign` | Asigna repartidor. |
| `PATCH` | `/deliveries/{id}/status` | Actualiza estado. |
| `GET` | `/deliveries/{id}/tracking` | Lista eventos de seguimiento. |
| `POST` | `/deliveries/{id}/tracking` | Registra un evento de seguimiento. |

No existen endpoints `DELETE`. No existen endpoints de mapas, ubicación en
tiempo real, panel de cliente, panel de vendedor ni panel de repartidor.

### Payloads principales

Crear Delivery:

```json
{
  "order_id": 15
}
```

Asignar repartidor:

```json
{
  "courier_id": 9
}
```

Actualizar estado:

```json
{
  "status": "picked_up"
}
```

Registrar tracking:

```json
{
  "event": "location_update",
  "latitude": -33.4488897,
  "longitude": -70.6692655
}
```

## 9. Integración con Orders, Payments, Reservations y Checkout

### Orders

- Delivery consulta Orders para crear entregas sólo desde órdenes `paid`.
- Delivery copia `customer_id` y `minimarket_id` desde la orden.
- Al completar una entrega, Delivery marca la orden como `delivered`.
- Delivery no modifica items, precios, totales ni datos de reserva.

### Payments

- Payments habilita indirectamente Delivery cuando la orden queda pagada.
- Delivery no modifica pagos.
- Fallos, cancelaciones o tracking no generan reembolsos automáticos.

### Reservations

- Delivery no bloquea ni libera stock.
- Delivery no modifica reservas.

### Checkout

- Checkout produce el flujo previo que termina en Orders y Payments.
- Delivery se ejecuta después del pago confirmado.

## 10. Idempotencia y robustez

- Crear Delivery para una misma orden más de una vez se rechaza con conflicto y
  no crea duplicados.
- Repetir una asignación sobre un Delivery ya asignado devuelve conflicto y no
  sobrescribe `courier_id`.
- Repetir una transición ya aplicada devuelve conflicto y no altera estados
  finales.
- Tracking sobre Delivery cancelado se rechaza.
- Tracking con evento inválido se rechaza.
- `recordTracking()` es append-only: repetir un evento válido puede crear un
  nuevo registro de auditoría, pero no altera la entrega ni la orden.

## 11. Limitaciones actuales

Fuera del alcance actual:

- mapas;
- GPS en tiempo real;
- WebSockets;
- SSE;
- polling;
- ETA;
- cálculo de rutas;
- geocodificación;
- historial visual;
- Customer Tracking UI;
- Courier Panel;
- Seller Panel;
- notificaciones;
- webhooks;
- pruebas automatizadas completas;
- control transaccional avanzado ante fallos parciales entre Delivery, Tracking
  y Orders.

## 12. Roadmap futuro

- Añadir pruebas automatizadas de integración para el flujo completo.
- Definir transacciones o compensaciones para fallos parciales.
- Formalizar el módulo Couriers con migración, modelo y administración propia.
- Incorporar panel operativo para seguimiento interno.
- Diseñar Customer Tracking UI sin exponer datos internos.
- Evaluar ETA, mapas y tracking en tiempo real como capacidades posteriores al
  MVP.

## 13. Criterio de cierre 26.5

Delivery se considera feature complete para el alcance actual cuando:

- crea entregas sólo para órdenes pagadas;
- evita duplicados por orden;
- asigna repartidor aprobado;
- aplica transiciones válidas;
- registra tracking básico;
- rechaza errores 404, 409 y 422 de forma consistente;
- marca la orden como `delivered` al completar la entrega;
- no modifica Payments, Reservations ni Checkout;
- mantiene lint y `git diff --check` sin errores.
