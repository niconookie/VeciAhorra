# Validación del escenario demo de entregas Courier

Fecha normativa: 2026-08-14

Veredicto: **TRAINING COURIER DEMO DELIVERIES CERTIFICADO**.

## 1. Arquitectura

El escenario es aditivo y vive fuera del dataset base. Reutiliza el usuario cliente `va_demo_carolina`, Minimarket Los Vecinos, su inventory oficial de Coca-Cola Original 1,5 L y el Courier 16 asociado a `va_demo_diego`. Cada caso es un grafo 1:1 de Checkout, CheckoutOrder, Order, OrderItem y Delivery. No reserva ni descuenta stock.

Los marcadores exclusivos son `checkouts.public_id`: `training-courier-demo-cd-01-v1`, `training-courier-demo-cd-02-v1` y `training-courier-demo-cd-03-v1`. El campo tiene restricción unique y permite resolver ownership y cleanup sin columnas nuevas.

## 2. Invariantes

- Courier: WP user 209, Courier 16, Diego Morales, `approved`.
- Customer: usuario demo existente 205, Carolina Soto.
- Store: Minimarket Los Vecinos, activa, onboarding completo y datos de retiro completos.
- Order: Customer y Store coincidentes, total CLP 2.190, estado `paid`.
- OrderItem: producto e inventory oficiales, cantidad 1, precio/subtotal CLP 2.190.
- Checkout: owner user, `payment_completed`, fulfillment `delivery`, total CLP 2.190 y snapshot completo.
- CheckoutOrder: relación única hacia la Order.
- Delivery: Order, Customer y Store coincidentes; snapshot completo; status/courier según escenario.

La autoridad para una Order pagada sintética es `courier-mvp-integration-test.php`, que prueba R01–R14 con este patrón operacional. No se creó Payment, PaymentSession, reconciliación ni resultado financiero falso; no se ejecutó Webpay.

## 3. Recursos creados

Estado final después de probar cleanup y restaurar:

```text
TRAINING_COURIER_ORDER_IDS=[1309,1310,1311]
TRAINING_COURIER_DELIVERY_IDS=[460,461,462]
DEMO_ORDERS=3
DEMO_ORDER_ITEMS=3
DEMO_CHECKOUTS=3
DEMO_CHECKOUT_ORDERS=3
DEMO_DELIVERIES=3
```

Los IDs son runtime; el ownership estable reside en los tres `public_id`.

## 4. CD-01

Checkout `training-courier-demo-cd-01-v1`; Delivery 460, status `pending`, `courier_id=NULL`. La consulta productiva la expone en Entregas disponibles. Acción visible: **Aceptar entrega**.

## 5. CD-02

Checkout `training-courier-demo-cd-02-v1`; Delivery 461, status `assigned`, `courier_id=16`. La consulta productiva la expone en Mis entregas. Acción visible: **Marcar como retirada**.

## 6. CD-03

Checkout `training-courier-demo-cd-03-v1`; Delivery 462, status `picked_up`, `courier_id=16`. Este es el estado que el panel contabiliza como En curso. Acción visible: **Marcar como entregada**.

## 7. Idempotencia

Dos ejecuciones consecutivas iniciales conservaron Orders `[1304,1305,1306]` y Deliveries `[455,456,457]`, con los mismos tres grafos y estados. No aparecieron duplicados. Después se probó el cleanup y se restauró el fixture; los nuevos IDs runtime son los indicados en la sección 3.

```text
FIRST_RUN=PASS
SECOND_RUN=PASS
DUPLICATE_ORDERS=0
DUPLICATE_DELIVERIES=0
```

## 8. Isolation

El seed solo crea o restaura grafos encontrados por sus marcadores. Antes de restaurar valida Customer, Store, Order y Delivery; una colisión ajena detiene la transacción. El validador read-only confirma exactamente tres grafos, cero Payments sintéticos, `FOREIGN_DELIVERIES_MODIFIED=0` y `BASE_DATASET_CHANGED=0`.

Las cuatro ofertas oficiales conservaron precio/stock: Coca-Cola 2190/12, Tallarines 1050/17, Salsa de tomates 750/18 y Super 8 500/11.

## 9. Runtime panel

Chrome autenticado como Diego Morales renderizó simultáneamente una card disponible, una asignada y una en curso. El resumen visual muestra Entregas disponibles 1, Mis entregas 2 y En curso 1; la separación de las dos propias confirma assigned 1 y picked_up 1.

```text
COURIER_BROWSER_RENDER=PASS
COURIER_AVAILABLE_DEMO=1
COURIER_ASSIGNED_DEMO=1
COURIER_IN_PROGRESS_DEMO=1
COURIER_JS_ERRORS=0
HTTP_5XX_COUNT=0
```

No se ejecutaron acciones persistentes sobre las tres fixtures. Las transiciones fueron cubiertas por Courier MVP R01–R14 usando su fixture temporal con cleanup.

## 10. Cleanup

`tests/manual/training-courier-demo-scenario-cleanup.php` resuelve exclusivamente los tres marcadores, valida el grafo y elimina en orden: tracking, Deliveries, OrderItems, CheckoutOrders, Checkouts y Orders. La prueba dio `REMAINING=0`; posteriormente el seed restauró el escenario 1/1/1.

## 11. Regressions

```text
CUSTOMER=PASS
STORE=PASS
PROVIDER=PASS
ADMIN=PASS
BASE_DATASET=PASS
OFFICIAL_4_4=PASS
STORE_IMAGES=20/20
STORE_ISOLATION=PASS
COURIER_MVP_R01_R14=PASS
PROVIDER_MVP_S01_S18=PASS
```

El P0 runtime ahora muestra siete pedidos propios para Los Vecinos —los cuatro previos más los tres escenarios— sin romper aislamiento. El preflight integral pasó y eliminó su usuario temporal. La excepción global conocida de Bikrimart Delivery y recursos externos bloqueados por red permanecen separados del panel.

## 12. Git/delta

Archivos propios de esta tarea:

- `tests/manual/support/training-courier-demo-scenario-support.php`
- `tests/manual/training-courier-demo-scenario.php`
- `tests/manual/training-courier-demo-scenario-validation.php`
- `tests/manual/training-courier-demo-scenario-cleanup.php`
- `tests/manual/training-secondary-roles-ux-preflight.py`
- `tests/manual/courier-mvp-integration-test.php`
- `docs/training-courier-demo-deliveries-validation-2026-08-14.md`

No se modificaron los scripts del dataset base, Provider, Panel Minimarket, Customer, A11, Durable Retry, Webpay ni Payments. `COMMIT=NO`; `PUSH=NO`.
