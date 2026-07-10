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
