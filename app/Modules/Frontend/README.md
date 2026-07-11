# Frontend Foundation

La fase 28.1 utiliza el shortcode técnico `[veciahorra_frontend]` como único
punto de montaje. El shortcode se agrega manualmente a una página elegida por el
administrador; el módulo no crea páginas, no registra rewrite rules y no ejecuta
`flush_rewrite_rules()`. De este modo, el tema conserva su header, footer y
plantilla, y VeciAhorra sólo renderiza el contenido interior.

Los assets `veciahorra-frontend` se registran en `wp_enqueue_scripts` y sólo se
encolan cuando el shortcode se renderiza fuera de `wp-admin`. Antes del script se
expone `window.VeciAhorra` con URL REST, namespace, nonce REST cuando corresponde,
identidad pública mínima, locale, moneda y un mapa de páginas todavía vacío.

El JavaScript define `window.VeciAhorra.api` y no ejecuta solicitudes por sí
mismo. `ViewRenderer` sólo admite vistas de una lista explícita; no acepta rutas
o nombres de plantilla procedentes de la URL. Las rutas `/productos`, `/carrito`,
`/checkout`, `/mi-cuenta` y `/mis-pedidos` quedan reservadas conceptualmente para
fases posteriores y no son registradas ni apropiadas en 28.1.

Componentes disponibles: Loader, Alert, Empty state, Button y Card. Badge, Modal,
Pagination, Price y StockBadge se incorporarán únicamente cuando una fase futura
los necesite y disponga de contrato backend.

## Selección pública de ofertas

`[veciahorra_frontend product_id="123"]` renderiza la ficha pública y consulta
únicamente `GET /catalog/products/123`. El script específico mantiene una
selección por producto con `selectedInventoryId` como fuente de verdad, la
conserva tras recargar mientras siga disponible y la limpia si desaparece.

La selección 28.4 no llama por sí misma a Cart. `getCartPayload()` prepara la
forma `{ inventory_id, quantity: 1 }`; precio y stock son datos de presentación
que el backend debe resolver nuevamente.

## Agregar al carrito

La ficha envía exclusivamente `POST /cart/items` con `inventory_id` y cantidad
fija `1`. Para usuarios autenticados se reutilizan cookie WordPress, credenciales
same-origin y nonce REST. Para invitados, `CartSession` conserva un identificador
opaco dentro de `Core\Session` y el cliente lo presenta mediante el encabezado
`X-Veciahorra-Cart-Session` ya aceptado por Cart; no se usa localStorage.

La integración bloquea envíos concurrentes y representa éxito/error sin limpiar
la selección, redirigir, modificar stock ni iniciar Checkout. Cart 22.x sólo
valida actualmente identidad, IDs positivos y existencia del inventario; las
validaciones de estado, stock y precio siguen siendo una dependencia backend y
no se simulan como autoridad en el navegador.

## Carrito público

`[veciahorra_cart]` renderiza el carrito y lo revalida con `GET /cart` cada vez
que se monta. La vista representa carga, carrito vacío, error recuperable,
contenido, total y controles accesibles de cantidad y eliminación. En escritorio
usa tabla y en pantallas pequeñas transforma cada fila en una tarjeta.

El flujo consume exclusivamente `GET /cart`, `POST /cart/items` desde la ficha
del producto, `PATCH /cart/items/{id}`, `DELETE /cart/items/{id}` y
`DELETE /cart`. Invitados usan el encabezado de sesión existente y usuarios
autenticados usan cookie, nonce e identidad WordPress. No se almacena el carrito
en el navegador.

Precio, subtotal y total se muestran exactamente desde la respuesta de Cart.
El frontend no los calcula ni consulta precios actuales de Inventory. Las
mutaciones vuelven a solicitar el carrito para representar el estado confirmado
por el servidor. Esta fase no inicia Checkout, no reserva stock y depende de los
mensajes y validaciones REST existentes.
