# VeciAhorra 26.0 — Delivery Functional Design

## 1. Objetivo y alcance

El módulo **Delivery** tiene como objetivo gestionar el ciclo operativo de despacho de pedidos ya pagados dentro de VeciAhorra, desde que un pedido queda disponible para entrega hasta que se marca como entregado o fallido.

Delivery no reemplaza a Orders ni a Payments. Su responsabilidad es representar y controlar el proceso logístico posterior al pago, manteniendo trazabilidad, estados consistentes y una base preparada para futuros paneles de vendedor y repartidor.

### Responsabilidades del módulo

- Crear un registro de despacho asociado a un pedido pagado.
- Mantener el estado operativo del despacho.
- Registrar asignación de repartidor cuando corresponda.
- Exponer información de seguimiento para cliente, vendedor y futuro courier.
- Controlar transiciones válidas del ciclo de entrega.
- Evitar duplicados, cierres inconsistentes y cambios fuera de orden.
- Servir como base para futuras notificaciones y tracking avanzado.

### Qué cubre el MVP

- Diseño de tabla futura `wp_va_deliveries`.
- Ciclo básico de estados de entrega.
- Creación de Delivery para pedidos pagados.
- Asignación manual o administrativa de repartidor.
- Actualización de estados operativos.
- Consulta de entregas por cliente, minimarket, pedido, estado y repartidor.
- Trazabilidad básica mediante timestamps.
- Reglas de consistencia con Orders y Payments.

### Qué queda fuera del MVP

- Geolocalización en tiempo real.
- Ruteo automático.
- Cálculo automático de tarifa de despacho.
- Integración con mapas externos.
- Optimización de rutas.
- Reasignación automática de repartidores.
- Chat cliente-repartidor.
- Evidencia fotográfica de entrega.
- Firma digital.
- Notificaciones push/SMS/WhatsApp.
- Panel completo de repartidor.
- Panel completo de minimarket.
- Métricas avanzadas de SLA.
- Liquidaciones o pagos a repartidores.

## 2. Flujo operativo

El flujo Delivery comienza únicamente después de que Payments confirma exitosamente un pago y Orders cambia sus pedidos asociados desde `reserved` a `paid`.

### Flujo normal

1. El cliente confirma el pago.
2. Payments valida el pago, las órdenes, reservas, cliente y monto.
3. Payments marca el pago como exitoso.
4. Orders cambia cada pedido asociado a estado `paid`.
5. El sistema queda habilitado para crear un Delivery por cada Order pagada.
6. Delivery se crea en estado `pending_assignment`.
7. Un administrador, vendedor o proceso futuro asigna un repartidor.
8. Delivery pasa a `assigned`.
9. El repartidor acepta o inicia el retiro.
10. Delivery pasa a `picking_up`.
11. El pedido es retirado desde el minimarket.
12. Delivery pasa a `in_transit`.
13. El pedido llega al cliente.
14. Delivery pasa a `delivered`.
15. El ciclo logístico queda cerrado.

### Flujo con excepción antes de asignación

1. Order está pagada.
2. Delivery se crea en `pending_assignment`.
3. Se detecta que el pedido no puede despacharse.
4. Delivery pasa a `failed` o `cancelled`, según la causa.
5. El pedido queda como pagado, pero requiere resolución operativa posterior.

### Flujo con excepción después de asignación

1. Delivery está `assigned`, `picking_up` o `in_transit`.
2. Ocurre una incidencia: repartidor no disponible, pedido no preparado, cliente no disponible, dirección problemática u otra causa.
3. Según el caso, Delivery puede pasar a:
   - `failed`, si el intento de entrega no se completa.
   - `cancelled`, si el despacho se anula administrativamente.
   - `assigned`, si se permite una reasignación futura desde una fase controlada.
4. El estado debe quedar trazable mediante timestamps y motivo de fallo/cancelación.

### Flujo de consulta para cliente

1. Customer Panel consulta las órdenes del cliente.
2. Para cada Order pagada con Delivery asociado, se muestra el estado de entrega.
3. El cliente puede ver información básica: estado, repartidor si existe, fechas relevantes y último hito.
4. El cliente no puede modificar el Delivery.

## 3. Modelo de datos

La tabla futura será `wp_va_deliveries`.

No se debe crear migración en 26.0. Este diseño sólo define la estructura esperada para fases posteriores.

### Tabla: `wp_va_deliveries`

| Campo | Tipo esperado | Requerido | Descripción |
|---|---:|---:|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Sí | Identificador interno del Delivery. |
| `order_id` | BIGINT UNSIGNED | Sí | Pedido asociado. Debe apuntar a `wp_va_orders.id`. |
| `payment_id` | BIGINT UNSIGNED NULL | No | Pago relacionado, útil para trazabilidad desde Payments. |
| `customer_id` | BIGINT UNSIGNED | Sí | Cliente dueño del pedido. Se replica desde Order para consultas y aislamiento. |
| `minimarket_id` | BIGINT UNSIGNED | Sí | Minimarket responsable del pedido. Se replica desde Order. |
| `courier_id` | BIGINT UNSIGNED NULL | No | Repartidor asignado. Futuro vínculo con módulo Couriers. |
| `status` | VARCHAR(30) | Sí | Estado operativo del Delivery. |
| `pickup_address` | TEXT NULL | No | Dirección de retiro del minimarket, cuando esté disponible. |
| `delivery_address` | TEXT NULL | No | Dirección de entrega del cliente, cuando esté disponible. |
| `notes` | TEXT NULL | No | Notas operativas internas. |
| `failure_reason` | TEXT NULL | No | Motivo de fallo o cancelación. |
| `assigned_at` | DATETIME NULL | No | Fecha de asignación de repartidor. |
| `pickup_started_at` | DATETIME NULL | No | Fecha en que inicia el retiro. |
| `picked_up_at` | DATETIME NULL | No | Fecha en que el pedido fue retirado del minimarket. |
| `delivered_at` | DATETIME NULL | No | Fecha de entrega final al cliente. |
| `failed_at` | DATETIME NULL | No | Fecha de fallo operativo. |
| `cancelled_at` | DATETIME NULL | No | Fecha de cancelación administrativa. |
| `created_at` | DATETIME | Sí | Fecha de creación. |
| `updated_at` | DATETIME | Sí | Fecha de última actualización. |

### Índices esperados

- `PRIMARY KEY (id)`
- `UNIQUE KEY order_id (order_id)`
- `KEY payment_id (payment_id)`
- `KEY customer_id (customer_id)`
- `KEY minimarket_id (minimarket_id)`
- `KEY courier_id (courier_id)`
- `KEY status (status)`
- `KEY created_at (created_at)`

### Relaciones funcionales

- Un `Order` pagado puede tener como máximo un `Delivery`.
- Un `Delivery` pertenece a un único `Order`.
- Un `Delivery` puede estar relacionado con un `Payment`.
- Un `Delivery` pertenece a un `customer_id` y a un `minimarket_id` derivados del pedido.
- Un `Delivery` puede tener o no `courier_id` en el MVP.

## 4. Máquina de estados

### Estados permitidos

| Estado | Descripción |
|---|---|
| `pending_assignment` | Delivery creado, aún sin repartidor asignado. |
| `assigned` | Delivery con repartidor asignado. |
| `picking_up` | Repartidor inició el proceso de retiro. |
| `picked_up` | Pedido retirado desde el minimarket. |
| `in_transit` | Pedido en camino al cliente. |
| `delivered` | Pedido entregado correctamente. Estado final exitoso. |
| `failed` | Entrega fallida. Estado final operativo. |
| `cancelled` | Delivery cancelado administrativamente. Estado final administrativo. |

### Transiciones válidas

| Desde | Hacia |
|---|---|
| `pending_assignment` | `assigned` |
| `pending_assignment` | `cancelled` |
| `assigned` | `picking_up` |
| `assigned` | `cancelled` |
| `assigned` | `failed` |
| `picking_up` | `picked_up` |
| `picking_up` | `failed` |
| `picking_up` | `cancelled` |
| `picked_up` | `in_transit` |
| `picked_up` | `failed` |
| `in_transit` | `delivered` |
| `in_transit` | `failed` |

### Transiciones inválidas

- Crear Delivery para Order no pagada.
- Crear más de un Delivery para la misma Order.
- Pasar de `pending_assignment` directamente a `delivered`.
- Pasar de `assigned` directamente a `delivered`.
- Pasar de `picking_up` directamente a `delivered`.
- Modificar un Delivery en estado final: `delivered`, `failed` o `cancelled`.
- Asignar courier a un Delivery ya entregado, fallido o cancelado.
- Marcar como `in_transit` sin retiro previo.
- Marcar como `delivered` sin `picked_up` o `in_transit` previo.

### Reglas de consistencia

- `courier_id` debe existir para pasar a `assigned`.
- `assigned_at` debe definirse al pasar a `assigned`.
- `pickup_started_at` debe definirse al pasar a `picking_up`.
- `picked_up_at` debe definirse al pasar a `picked_up`.
- `delivered_at` debe definirse al pasar a `delivered`.
- `failed_at` y `failure_reason` deben definirse al pasar a `failed`.
- `cancelled_at` debe definirse al pasar a `cancelled`.
- Los timestamps no deben retroceder cronológicamente.
- Los estados finales son inmutables salvo corrección administrativa futura explícitamente diseñada fuera del MVP.

## 5. Reglas de negocio

### Creación

- Sólo se puede crear Delivery para Orders en estado `paid`.
- No se puede crear Delivery para Orders `reserved`, `cancelled`, `expired`, `failed` o equivalentes futuros.
- No se puede crear Delivery si ya existe uno para el mismo `order_id`.
- `customer_id`, `minimarket_id` y `payment_id` deben derivarse desde Order/Payment, no confiarse ciegamente desde el payload público.
- El estado inicial siempre debe ser `pending_assignment`.
- La creación debe ser idempotente por `order_id`: si ya existe, puede devolver el registro existente o un conflicto controlado, según la fase de implementación.

### Asignación

- Sólo se puede asignar courier a Delivery en `pending_assignment`.
- El `courier_id` debe ser válido cuando exista módulo de Couriers.
- En MVP puede aceptarse `courier_id` como identificador externo o placeholder si todavía no existe tabla de repartidores formal.
- Una asignación exitosa cambia estado a `assigned`.
- No se permite asignar courier a estados finales.
- No se permite asignar courier a un Delivery que ya esté en tránsito.

### Actualización de estado

- Toda actualización debe validar transición origen-destino.
- Las transiciones deben ser atómicas.
- La repetición de una misma transición ya aplicada debe tratarse de forma idempotente cuando sea seguro.
- Los cambios deben conservar trazabilidad mediante timestamps.
- El cliente no puede actualizar estados.
- El vendedor futuro sólo podrá actualizar hitos relacionados con preparación/retiro si se define en Seller Panel.
- El courier futuro sólo podrá actualizar hitos propios de retiro, tránsito y entrega si se define en Courier Panel.

### Cierre

- `delivered`, `failed` y `cancelled` cierran el Delivery.
- Un Delivery cerrado no admite cambios normales.
- Un cierre exitoso no debe modificar Payments.
- Un fallo o cancelación no debe revertir automáticamente Payments ni Orders en el MVP.
- La resolución posterior de reembolsos, reclamos o soporte queda fuera del MVP.

## 6. API REST — diseño

Namespace previsto: `veciahorra/v1`.

Los endpoints se diseñan para implementación incremental. En 26.0 no se debe crear código.

### GET `/deliveries`

Lista Deliveries con filtros.

#### Query params previstos

- `page`
- `per_page`
- `status`
- `customer_id`
- `minimarket_id`
- `courier_id`
- `order_id`

#### Respuesta 200

```json
{
  "data": [
    {
      "id": 1,
      "order_id": 15,
      "payment_id": 7,
      "customer_id": 22,
      "minimarket_id": 3,
      "courier_id": null,
      "status": "pending_assignment",
      "created_at": "2026-07-09 12:00:00",
      "updated_at": "2026-07-09 12:00:00"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  }
}
```

### GET `/deliveries/{id}`

Obtiene detalle de un Delivery.

#### Respuesta 200

```json
{
  "data": {
    "id": 1,
    "order_id": 15,
    "payment_id": 7,
    "customer_id": 22,
    "minimarket_id": 3,
    "courier_id": 9,
    "status": "in_transit",
    "pickup_address": "Av. Ejemplo 123",
    "delivery_address": "Calle Cliente 456",
    "notes": null,
    "failure_reason": null,
    "assigned_at": "2026-07-09 12:05:00",
    "pickup_started_at": "2026-07-09 12:10:00",
    "picked_up_at": "2026-07-09 12:20:00",
    "delivered_at": null,
    "failed_at": null,
    "cancelled_at": null,
    "created_at": "2026-07-09 12:00:00",
    "updated_at": "2026-07-09 12:20:00"
  }
}
```

### POST `/deliveries`

Crea Delivery para un Order pagado.

#### Payload

```json
{
  "order_id": 15,
  "notes": "Entregar en conserjería si el cliente no contesta."
}
```

#### Respuesta 201

```json
{
  "data": {
    "id": 1,
    "order_id": 15,
    "status": "pending_assignment"
  }
}
```

#### Códigos esperados

- `201` creado.
- `200` si se decide idempotencia devolviendo Delivery existente.
- `400` payload inválido.
- `404` Order no existe.
- `409` Delivery ya existe para Order.
- `422` Order no está pagada.
- `500` error inesperado.

### PATCH `/deliveries/{id}/assign`

Asigna repartidor.

#### Payload

```json
{
  "courier_id": 9
}
```

#### Respuesta 200

```json
{
  "data": {
    "id": 1,
    "courier_id": 9,
    "status": "assigned",
    "assigned_at": "2026-07-09 12:05:00"
  }
}
```

#### Códigos esperados

- `200` asignado.
- `400` payload inválido.
- `404` Delivery no existe.
- `409` estado incompatible.
- `422` courier inválido.

### PATCH `/deliveries/{id}/status`

Actualiza estado respetando la máquina de estados.

#### Payload

```json
{
  "status": "picking_up"
}
```

#### Payload para fallo

```json
{
  "status": "failed",
  "failure_reason": "Cliente no disponible en domicilio."
}
```

#### Respuesta 200

```json
{
  "data": {
    "id": 1,
    "status": "picking_up",
    "updated_at": "2026-07-09 12:10:00"
  }
}
```

#### Códigos esperados

- `200` actualizado.
- `400` payload inválido.
- `404` Delivery no existe.
- `409` transición inválida.
- `422` faltan datos requeridos para el estado.

### PATCH `/deliveries/{id}/cancel`

Cancela Delivery administrativamente.

#### Payload

```json
{
  "failure_reason": "Cancelación solicitada por soporte."
}
```

#### Respuesta 200

```json
{
  "data": {
    "id": 1,
    "status": "cancelled",
    "cancelled_at": "2026-07-09 12:30:00"
  }
}
```

### GET `/orders/{order_id}/delivery`

Endpoint de conveniencia para obtener Delivery por Order.

#### Respuesta 200

```json
{
  "data": {
    "id": 1,
    "order_id": 15,
    "status": "assigned"
  }
}
```

#### Códigos esperados

- `200` encontrado.
- `404` Order o Delivery no existe.

## 7. Integración

### Orders

Delivery depende de Orders como fuente principal. La creación debe validar que la Order exista y esté en estado `paid`.

Delivery no debe cambiar el total, los items ni los precios congelados del pedido. Tampoco debe consumir reservas ni modificar stock. Esos efectos pertenecen al núcleo Orders/Reservations/Payments ya consolidado.

Cada Order puede tener como máximo un Delivery. Esto mantiene la regla actual de VeciAhorra: un pedido independiente por minimarket.

### Payments

Payments habilita la creación de Delivery al confirmar pagos exitosos. Delivery puede guardar `payment_id` para trazabilidad, pero no debe modificar estados de Payment.

Si un Delivery falla o se cancela, no debe revertir automáticamente el pago en el MVP. Reembolsos o reclamos quedan fuera de alcance.

### Customer Panel

Customer Panel debe consumir información Delivery en modo read-only.

El cliente puede ver:

- Estado actual del despacho.
- Fechas relevantes.
- Repartidor asignado si corresponde y si la política de privacidad lo permite.
- Mensaje simple de seguimiento.

El cliente no puede crear, asignar, cancelar ni cambiar estados de Delivery.

### Futuro Seller Panel

Seller Panel podrá consultar Deliveries asociados a su minimarket.

Posibles acciones futuras:

- Ver pedidos pagados pendientes de despacho.
- Confirmar que pedido está listo para retiro.
- Ver estado de retiro.
- Reportar incidencia antes del retiro.

Estas acciones quedan fuera del MVP, pero el diseño debe permitir filtros por `minimarket_id`.

### Futuro Courier Panel

Courier Panel podrá consultar Deliveries asignados al repartidor.

Posibles acciones futuras:

- Ver entregas asignadas.
- Iniciar retiro.
- Confirmar retiro.
- Marcar en tránsito.
- Marcar entregado.
- Reportar fallo.

Estas acciones se apoyan en `courier_id` y en la máquina de estados definida.

## 8. Casos límite

### Order no pagada

Intentar crear Delivery para Order en `reserved` o estado no pagado debe responder `422`.

### Delivery duplicado

Intentar crear dos Deliveries para la misma Order debe evitarse mediante validación de servicio y restricción única por `order_id`.

### Pago confirmado con múltiples Orders

Un Payment puede cubrir varias Orders. En ese caso debe existir un Delivery independiente por cada Order pagada, respetando la separación por minimarket.

### Reintento de creación

Si la creación se reintenta después de un timeout o error de red, el sistema debe evitar duplicados. La fase de implementación puede decidir entre devolver `200` con el Delivery existente o `409` conflicto controlado.

### Estado final repetido

Si un Delivery ya está `delivered`, repetir la misma solicitud puede devolver el estado actual sin cambios si se define como idempotente. Cambiarlo a otro estado debe rechazarse.

### Transición fuera de orden

Ejemplo: `assigned` a `delivered`. Debe responder `409`.

### Falta de motivo de fallo

Pasar a `failed` sin `failure_reason` debe responder `422`.

### Concurrencia en asignación

Si dos operadores intentan asignar repartidores simultáneamente, sólo una asignación debe ganar. La otra debe recibir estado actualizado o conflicto.

### Concurrencia en cierre

Si un proceso marca `delivered` y otro marca `failed` al mismo tiempo, sólo una transición final debe persistir.

### Repartidor inválido

En MVP puede validarse formato positivo de `courier_id`. Cuando exista módulo Couriers, debe validarse existencia y aprobación.

### Recuperación ante fallos

Si se crea Delivery pero falla una actualización posterior, el Delivery debe quedar en el último estado consistente. No debe haber cambios parciales contradictorios de timestamps/estado.

## 9. Estrategia de pruebas

| Área | Caso | Resultado esperado |
|---|---|---|
| Creación | Crear Delivery para Order pagada | `201`, estado `pending_assignment`. |
| Creación | Crear Delivery para Order inexistente | `404`. |
| Creación | Crear Delivery para Order no pagada | `422`. |
| Creación | Crear Delivery duplicado | `409` o respuesta idempotente documentada. |
| Listado | Filtrar por `customer_id` | Sólo Deliveries del cliente. |
| Listado | Filtrar por `minimarket_id` | Sólo Deliveries del minimarket. |
| Listado | Filtrar por `status` | Sólo estados solicitados. |
| Detalle | Buscar Delivery existente | `200` con payload completo. |
| Detalle | Buscar Delivery inexistente | `404`. |
| Asignación | Asignar courier en `pending_assignment` | `200`, estado `assigned`, `assigned_at` definido. |
| Asignación | Asignar courier en estado final | `409`. |
| Asignación | Asignar courier inválido | `422`. |
| Estados | `assigned` a `picking_up` | `200`, timestamp correcto. |
| Estados | `picking_up` a `picked_up` | `200`, timestamp correcto. |
| Estados | `picked_up` a `in_transit` | `200`. |
| Estados | `in_transit` a `delivered` | `200`, estado final. |
| Estados | Transición fuera de orden | `409`. |
| Estados | Modificar estado final | `409`. |
| Fallos | Marcar `failed` con motivo | `200`, `failed_at` definido. |
| Fallos | Marcar `failed` sin motivo | `422`. |
| Cancelación | Cancelar antes de retiro | `200`, `cancelled_at` definido. |
| Concurrencia | Doble asignación simultánea | Sólo una asignación efectiva. |
| Concurrencia | Cierre simultáneo incompatible | Sólo un estado final persistente. |
| Integración | Payment con varias Orders | Un Delivery por cada Order pagada. |
| Customer Panel | Cliente consulta Delivery propio | Acceso permitido read-only. |
| Customer Panel | Cliente consulta Delivery ajeno | Acceso denegado o no encontrado. |
| Calidad | `git diff --check` | Sin errores. |
| Alcance | Sólo documentación en 26.0 | Único archivo modificado. |

## 10. Roadmap de implementación

### 26.1 — Delivery Backend Foundation

Objetivo: crear la base del módulo Delivery sin ciclo completo.

Alcance previsto:

- Migración `wp_va_deliveries`.
- Modelo Delivery.
- Repository básico.
- Request de creación/listado.
- Service básico.
- Controller básico.
- Routes REST iniciales.
- Endpoints:
  - `GET /deliveries`
  - `GET /deliveries/{id}`
  - `POST /deliveries`
- Validar creación sólo para Orders pagadas.
- Evitar duplicados por `order_id`.

Sin incluir aún:

- Asignación real de courier.
- Máquina completa de estados.
- Tracking cliente.
- Paneles.

### 26.2 — Delivery Lifecycle

Objetivo: implementar la máquina de estados.

Alcance previsto:

- Estados permitidos.
- Transiciones válidas e inválidas.
- Endpoint `PATCH /deliveries/{id}/status`.
- Timestamps por hito.
- Fallo y cancelación.
- Reglas de cierre inmutable.
- Pruebas de transiciones.

### 26.3 — Courier Assignment

Objetivo: permitir asignación controlada de repartidor.

Alcance previsto:

- Endpoint `PATCH /deliveries/{id}/assign`.
- Campo `courier_id`.
- Validación inicial de courier.
- Estado `assigned`.
- Protección contra doble asignación.
- Base para futuro Courier Panel.

### 26.4 — Customer Tracking

Objetivo: exponer seguimiento read-only para cliente.

Alcance previsto:

- Consulta Delivery por Order.
- Integración read-only con Customer Panel.
- Serialización segura para cliente.
- Ocultar datos internos innecesarios.
- Validación de aislamiento por `customer_id`.

### 26.5 — Delivery Hardening

Objetivo: consolidar estabilidad, consistencia y cobertura.

Alcance previsto:

- Pruebas de concurrencia.
- Idempotencia en creación y transiciones seguras.
- Casos límite de pagos con múltiples Orders.
- Validación de estados finales.
- Revisión de regresión Orders/Payments/Customer Panel.
- Documentación operativa complementaria si corresponde.

## Criterios de aceptación de 26.0

- Existe `docs/delivery-functional-design.md`.
- El documento define objetivo, alcance, flujo, datos, estados, reglas, API, integración, casos límite, pruebas y roadmap.
- No se modifica PHP.
- No se modifica JavaScript.
- No se modifica CSS.
- No se modifica SQL.
- No se modifican tests.
- No se crean migraciones.
- No se crean commits.
- No se realiza push.
- `git diff --check` no reporta errores.
- `git status --short` muestra únicamente:

```bash
?? docs/delivery-functional-design.md
```
