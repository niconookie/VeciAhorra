# Validación final UX del Panel Minimarket

Fecha: 10 de agosto de 2026. Superficie: `https://localhost/Minimarket/panel-minimarket/`.

## 1. Objetivo

Hacer presentable el Panel Minimarket para una persona no técnica sin cambiar Product, Inventory, lifecycle, permisos, endpoints, datos ni aislamiento. Veredicto: **TRAINING MINIMARKET PANEL UX APROBADO**.

## 2. Baseline visual

El panel funcional mostraba `Panel listo.`, estado `active`, tabs con apariencia de enlaces, “Incorporar producto maestro”, inputs sin contexto y pedidos como una cadena de códigos (`reserved`, `delivery`, `Delivery: —`). La navegación global mostraba “Mis compras” al rol store y los accesos Mi panel/logout tenían poca separación.

## 3. Cambios

- Resumen visual con nombre, `Activo` y “20 ofertas activas”.
- Estado normal de carga oculto al terminar; feedback sólo tras acción o error.
- Tabs reales con estado activo, hover, focus y ARIA.
- Tarjetas alineadas, botones reconocibles, disabled durante request y responsive básico.
- Lenguaje de catálogo, pedidos, estados y fulfillment traducido.
- “Mis compras” filtrado sólo para el rol `veciahorra_minimarket`.
- Mi panel/Cerrar sesión separados visualmente.

## 4. Mis productos

Chrome renderizó 20 tarjetas con imagen, nombre, marca/unidad cuando existen, Precio ($), Stock, Estado Activo/Inactivo y Guardar cambios. El input mantiene decimal canónico compatible con la API; no se cambió parsing ni contrato. Guardar conserva PATCH original, deshabilita el botón y muestra “Cambios guardados correctamente” sólo tras éxito. No se ejecutó PATCH en esta validación.

```text
PANEL_READY_MESSAGE_REMOVED=PASS
STORE_STATUS_LOCALIZED=PASS
PRODUCT_ROWS=20
PRODUCT_IMAGES=20/20
BROKEN_PRODUCT_IMAGES=0
```

## 5. Agregar productos

La tab y título dicen “Agregar productos”. Incluye explicación breve, label “Buscar productos”, placeholder “Buscar por nombre o marca…”, botón Buscar y resultados con imagen, nombre, marca/unidad, Precio ($), Stock y Agregar producto. Chrome obtuvo cinco candidatos y cinco imágenes.

Los candidatos se filtran por `NOT EXISTS` para la pareja store/product; el endpoint vuelve a rechazar duplicados antes de insertar. Los inputs mantienen `required`, `min=0`, step compatible y validación nativa; backend sigue siendo autoridad. No se ejecutó POST.

```text
ADD_PRODUCT_IMAGES=5/5 candidates shown
ADD_PRODUCT_SEARCH_USABLE=PASS
ALREADY_IN_STORE_PRODUCTS_NOT_ADDABLE=PASS
DUPLICATE_PRODUCT_PROTECTION=PASS (query + backend)
```

Hallazgo de datos no corregido: “Coca Cola” (Product 999999994, SKU `VA153-QA-003`) y “Coca-Cola 350 cc” (999999995, sin SKU) son registros maestros distintos que comparten attachment 569; las variantes demo 1,5 L son otros Products. Puede existir ambigüedad semántica, pero no se eliminó ni alteró catálogo.

## 6. Mis pedidos

Chrome renderizó cuatro pedidos como cards con Pedido, Fecha chilena, Total CLP, Estado localizado, Tipo de entrega y Estado del despacho. Los estados conocidos se mapean; un valor desconocido usa “Estado no disponible”. Fulfillment muestra Despacho/Retiro en tienda. Si no existe delivery se muestra “Sin información”, que respeta el DTO: éste entrega estado de despacho, no nombre de repartidor.

Existe detalle read-only `GET /minimarket/orders/{id}`. Cada card ofrece “Ver pedido” y Chrome abrió el detalle con productos. El empty-state implementado es “Aún no tienes pedidos.”

```text
ORDER_ROWS=4
ORDER_LIST_PRESENTATION=PASS
ORDER_STATUS_LOCALIZED=PASS
ORDER_DELIVERY_LOCALIZED=PASS
ORDER_EMPTY_STATE=PASS by code
STORE_ORDER_DETAIL_EXISTS=yes
```

## 7. Navegación/header

```text
INTERNAL_TABS_STYLED=PASS
HEADER_PANEL_LOGOUT_SEPARATED=PASS
MIS_COMPRAS_FOR_STORE=hidden
MI_PANEL_FOR_STORE=visible
LOGOUT_FOR_STORE=visible
```

El filtro compara la URL real del Customer Panel y se activa exclusivamente si el usuario tiene el rol store; no afecta customer, courier, provider ni admin.

## 8. Isolation

Inventory list/detail y mutaciones siguen derivando store desde `_veciahorra_store_id`; no existe selector o parámetro de autoridad. La prueba HTTP obtuvo 20 inventories de Los Vecinos y cero ajenos.

Orders list filtra `o.minimarket_id`; detail requiere simultáneamente order ID y store ID. La prueba HTTP contrastó las cuatro filas con DB y abrió un detalle propio.

```text
STORE_ISOLATION=PASS
STORE_ORDER_LIST_ISOLATION=PASS
STORE_ORDER_DETAIL_ISOLATION=PASS
```

## 9. Los Vecinos

```text
STORE_NAME=Minimarket Los Vecinos
STORE_STATUS_DISPLAY=Activo
ACTIVE_OFFERS=20
INVENTORY_ROWS=20
PRODUCT_IMAGES=20/20
OFFICIAL_OFFERS_4_4=PASS
```

| Producto | Precio | Stock | Imagen | Estado |
|---|---:|---:|---|---|
| Coca-Cola Original 1,5 L | 2.190 | 12 | PASS | Activo |
| Tallarines Carozzi 400 g | 1.050 | 17 | PASS | Activo |
| Salsa de tomates Carozzi | 750 | 18 | PASS | Activo |
| Super 8 | 500 | 11 | PASS | Activo |

No se modificó ningún valor.

## 10. Browser validation

Chrome 151 headless, viewport 1440×1200 y certificado local aceptado:

```text
BROWSER_RENDER_PRODUCTS=PASS (20 rows, 20 images)
BROWSER_RENDER_ADD_PRODUCTS=PASS (5 candidates, 5 images)
BROWSER_RENDER_ORDERS=PASS (4 orders, detail PASS)
MINIMARKET_PANEL_JS_ERRORS=0
HTTP_5XX_COUNT=0
BROKEN_PRODUCT_IMAGES=0
```

Se verificaron DOM real, tabs, labels, estado activo, menú por rol, valores oficiales, detalle y consola. El error global conocido de `Bikrimart-Delivery.json` se excluyó porque pertenece a una animación de portada, no al panel.

## 11. Regressions

El preflight final completo volvió a pasar:

```text
CUSTOMER=PASS
STORE=PASS
COURIER=PASS
PROVIDER=PASS
ADMIN=PASS
REGISTRATION=/registro-cliente/ PASS; test user removed
STORE_IMAGES=PASS
STORE_ISOLATION=PASS
```

También pasaron `customer-panel-foundation-test.php`, `customer-panel-frontend-infrastructure-test.php` y el harness HTTP P0 extendido. No se ejecutó payment/Webpay.

## 12. Remaining cosmetic issues

- Condición global sin cambios: aceptar previamente el certificado XAMPP autofirmado/expirado.
- Animación decorativa `Bikrimart-Delivery.json` bloqueada por mixed-content/CORS en Inicio; no pertenece al panel.
- Los Products históricos “Coca Cola” y “Coca-Cola 350 cc” pueden ser semánticamente ambiguos; requiere decisión de catálogo, no cambio UX.
- El estado del despacho no identifica al repartidor porque el DTO actual no entrega esa identidad; la UI no inventa ese dato.

## 13. Git/delta

```text
DELTA_PROPIO_ESTA_TAREA=[
  app/Modules/Minimarket/Views/panel.php,
  app/Modules/CustomerAccess/CustomerAccessModule.php,
  assets/frontend/js/minimarket-panel.js,
  assets/frontend/css/minimarket-panel.css,
  tests/manual/training-p0-http-role-runtime-test.php,
  tests/manual/training-minimarket-panel-ux-preflight.py,
  docs/training-minimarket-panel-final-ux-validation-2026-08-14.md
]
DATASET_DELTA=[]
ORDERS_DELTA=[]
INVENTORY_DELTA=[]
COMMIT=NO
PUSH=NO
```
