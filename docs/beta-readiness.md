# VeciAhorra 27.0 — Core Stabilization & Beta Readiness

## 1. Objetivo

Esta fase estabiliza el backend existente y prepara el proyecto para una futura
beta controlada. No incorpora módulos nuevos, endpoints nuevos, UI ni expansión
funcional. La revisión se concentra en arquitectura, consistencia REST,
idempotencia, riesgos operativos y evidencia de pruebas.

## 2. Alcance auditado

Módulos revisados como backend crítico:

- Core y Container.
- Database y migraciones.
- Products e Inventory.
- Cart.
- Checkout.
- Reservations.
- Orders.
- Payments.
- Delivery.
- Customer Panel de sólo lectura.

## 3. Auditoría de arquitectura

### Resultado general

La arquitectura mantiene el patrón modular ya usado por el proyecto:

- `Routes` adaptan HTTP/REST.
- `Controller` traduce errores y delega.
- `Service` contiene casos de uso y reglas de negocio.
- `Repository` encapsula persistencia.
- `Schema` y `Migrations` versionan tablas propias.
- `Application` registra rutas y páginas administrativas mediante `Container`.

No se detectó necesidad de agregar endpoints ni módulos durante 27.0.

### Observaciones

| Área | Estado beta | Observación |
|---|---|---|
| Cart → Checkout → Reservations | Apto para beta interna | Flujo manual crítico aprobado. |
| Orders | Apto para beta interna | Estados `reserved`, `paid` y `delivered` integrados al flujo actual. |
| Payments | Apto para beta interna con gateway simulado | Falta gateway productivo antes de beta pública. |
| Delivery | Apto para beta interna | Crear, asignar, cambiar estado y tracking básico están integrados. |
| Couriers | Riesgo controlado | Delivery consulta `CourierRepository`, pero Couriers aún no tiene gestión funcional completa. |
| Customer Panel | Parcial | Base de lectura disponible; falta cierre de experiencia antes de piloto amplio. |
| CI | Pendiente | No existe workflow CI; la evidencia se mantiene con lint y pruebas manuales. |

## 4. Correcciones menores aplicadas

No se aplicaron cambios funcionales en 27.0.

El estado de entrada ya contenía la corrección de robustez relevante para
Delivery: la creación duplicada de una entrega para el mismo `order_id` se
rechaza con conflicto y no genera registros duplicados.

## 5. Flujo completo validado

Flujo objetivo para beta:

```text
Cart
  ↓
Checkout
  ↓
Reservations
  ↓
Orders reserved
  ↓
Payments pending
  ↓
Payment confirmed
  ↓
Orders paid
  ↓
Delivery created
  ↓
Courier assigned
  ↓
Delivery picked_up
  ↓
Tracking events
  ↓
Delivery delivered
  ↓
Order delivered
```

La parte transaccional hasta `Orders paid` fue validada con pruebas manuales
existentes. La parte Delivery fue validada mediante revisión estática específica
de estados, eventos, rutas y guardas de error porque todavía no existe una suite
manual dedicada para Delivery.

## 6. Consistencia REST

Los módulos críticos mantienen el estándar de respuesta:

- éxito con `success: true` y `data`;
- error con `success: false` y `error.code` / `error.message`;
- `404` para recursos inexistentes;
- `409` para conflictos de estado o duplicados;
- `422` para payloads o estados de dominio inválidos;
- `500` para errores internos o persistencia no recuperable.

Casos revisados en Delivery:

| Caso | HTTP esperado |
|---|---:|
| Delivery inexistente | 404 |
| Courier inexistente | 404 |
| Courier no aprobado | 422 |
| Delivery duplicado por orden | 409 |
| Delivery ya asignado | 409 |
| Transición inválida | 409 |
| Tracking sobre Delivery cancelado | 409 |
| Evento de tracking inválido | 422 |

## 7. Idempotencia y reintentos

| Operación | Comportamiento esperado |
|---|---|
| Checkout repetido sin carrito válido | No crea órdenes nuevas. |
| Payment repetido para las mismas órdenes | Devuelve el pago existente cuando el payload coincide. |
| Confirmación de pago repetida | No duplica consumo de reservas ni cambios de orden. |
| Crear Delivery duplicado | Devuelve conflicto y no crea otro registro. |
| Asignar Delivery ya asignado | Devuelve conflicto y no sobrescribe `courier_id`. |
| Repetir transición ya aplicada | Devuelve conflicto y no mueve el estado hacia atrás. |
| Registrar tracking válido repetido | Agrega eventos append-only sin alterar Delivery ni Orders. |

## 8. Optimización y datos

Consultas e índices relevantes:

- `orders`: búsqueda por `id`, `customer_id`, `minimarket_id`, `status`.
- `payment_orders`: asociación pago-orden para idempotencia de pagos.
- `reservations`: búsqueda por `order_id`, inventario y expiración.
- `deliveries`: índices por `order_id`, `customer_id`, `minimarket_id`,
  `courier_id` y `status`.
- `delivery_tracking`: índices por `delivery_id`, `event` y `created_at`.

No se agregaron búsquedas geográficas, agregaciones analíticas ni consultas fuera
del alcance de beta.

## 9. Riesgos antes de beta

| Riesgo | Severidad | Mitigación requerida |
|---|---|---|
| Gateway productivo no integrado | Alta | Definir proveedor real, callbacks autenticados e idempotencia productiva. |
| Couriers sin gestión operativa completa | Media | Formalizar alta/aprobación antes de piloto con repartidores reales. |
| Customer Panel incompleto | Media | Cerrar detalle de órdenes, pagos y entregas antes de piloto amplio. |
| Sin CI automatizado | Media | Crear workflow antes de beta pública o documentar ejecución local obligatoria. |
| Observabilidad limitada | Media | Definir logs sanitizados y señales mínimas para pagos y entregas. |
| Pruebas Delivery no automatizadas | Media | Crear pruebas manuales/automatizadas del flujo Delivery completo. |

## 10. Criterios de entrada a beta

Antes de declarar beta, el proyecto debe cumplir:

- todos los P0 del MVP cerrados o con excepción aprobada;
- smoke test de instalación y activación;
- flujo Cart → Checkout → Payment → Delivery validado con datos reales de
  staging;
- matriz mínima de compatibilidad revisada;
- respaldo, restauración y rollback ensayados;
- soporte operativo y responsables definidos;
- riesgos altos sin resolver documentados con aceptación explícita.

## 11. Evidencia de validación 27.0

Pruebas manuales ejecutadas:

- `checkout-reservation-integration-test.php`
- `payment-confirmation-test.php`
- `transactional-workflow-test.php`

Resultado esperado para cada una:

```text
PASS
```

Validación estática específica:

- estados Delivery permitidos;
- eventos Delivery Tracking permitidos;
- rutas Delivery existentes;
- guardas de errores 404, 409 y 422;
- ausencia de endpoints nuevos en 27.0.

## 12. Recomendación

**Estado:** preparado para una beta interna técnica, no todavía para beta pública.

La base transaccional está suficientemente estable para validación controlada por
el equipo. Para una beta con usuarios y comercios reales aún deben cerrarse los
riesgos de gateway productivo, gestión formal de Couriers, observabilidad, CI y
pruebas completas de Delivery.
