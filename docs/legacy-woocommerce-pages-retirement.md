# Microhito 30.1.4.9 — Retiro operativo de páginas WooCommerce heredadas

## 1. Resumen ejecutivo

El 18 de julio de 2026 se retiraron reversiblemente de publicación las páginas
WordPress 177 (`Categorías`) y 356 (`Principal`). Ambas pasaron de `publish` a
`draft` mediante `wp_update_post()`. No se eliminó ningún objeto ni se
implementaron redirecciones. Después del cambio dejaron de aparecer en la
búsqueda tradicional, el live search de Blocksy y el sitemap de páginas; sus
URLs anteriores responden 404 a visitantes.

## 2. Autorización y fuente de verdad

La operación se limitó a los IDs autorizados por
`docs/legacy-woocommerce-pages-usage-audit.md`, cuya clasificación fue
“Retiro seguro con redirección posterior”. No se infirieron otros objetivos por
título, slug o contenido.

## 3. Estado previo de ID 177

| Campo | Valor previo |
|---|---|
| ID / tipo / estado | `177` / `page` / `publish` |
| Título / slug | `Categorías` / `categorias` |
| URL | `https://localhost/Minimarket/categorias/` |
| Modificación | `2026-06-22 23:33:32` (`2026-06-23 02:33:32` GMT) |
| Autor | ID 1, `NicolasAvila` |
| Plantilla / padre / orden | predeterminada / 0 / 0 |
| Contenido | lista de seis categorías comerciales generada por `premium-woo-categories` |
| Longitud / SHA-256 del contenido | 1512 / `b228bec9b2f51b5440bd7d8ad7780c48c9797cec411bda076aa70f3e038b97e3` |
| Elementor | builder, `wp-page`, versión 4.1.2 |
| Longitud / SHA-256 de `_elementor_data` | 703 / `b6f8083d48ee129f23f9bd60ca88a9c9aa4fc230725b7b1c8615d49ae139013e` |
| Revisiones | 16: 579, 578, 577, 572, 571, 570, 539, 538, 537, 507, 506, 505, 181, 180, 179 y 178 |
| Attachments referenciados directamente | 573, 574, 576 y 487; sin attachments hijos |
| Canonical/permalink público | URL anterior indicada arriba |
| Búsqueda tradicional / live search | presente para `Categorías` como ID 177 |
| Sitemap | presente en `wp-sitemap-posts-page-1.xml` |

El contenido completo se capturó antes de operar y se comparó después mediante
longitud y hash; incluía los destinos `product_cat` VeciSed, VeciDespensa,
VeciAseo, Panadería, Bebidas y Agua Mineral y sus imágenes disponibles.

## 4. Estado previo de ID 356

| Campo | Valor previo |
|---|---|
| ID / tipo / estado | `356` / `page` / `publish` |
| Título / slug | `Principal` / `principal` |
| URL | `https://localhost/Minimarket/principal/` |
| Modificación | `2026-06-21 22:04:13` (`2026-06-22 01:04:13` GMT) |
| Autor | ID 1, `NicolasAvila` |
| Plantilla / padre / orden | predeterminada / 0 / 0 |
| Contenido completo | formulario GET con `s`, `post_type=product` y `[products limit="50" columns="5" paginate="true"]` |
| Longitud / SHA-256 del contenido | 392 / `37817b10892a6e6ff0f4b2dcb653456e90f0917ea46d2525bfa109597dee6132` |
| Elementor | builder, `wp-page`, versión 4.1.2 |
| Longitud / SHA-256 de `_elementor_data` | 3166 / `977eaf3f6e9c9581e44a7a805790db65d6c547cd550f6824f1d57f111f0fcb3f` |
| Revisiones | 50 antes de operar, IDs 535 a 357 registrados en la captura |
| Attachments | ninguno hijo ni referenciado directamente |
| Canonical/permalink público | URL anterior indicada arriba |
| Búsqueda tradicional / live search | presente para `Principal` como ID 356 |
| Sitemap | presente en `wp-sitemap-posts-page-1.xml` |

## 5. Verificaciones previas

Inmediatamente antes del cambio se confirmó para ambos IDs: existencia,
`post_type=page`, estado `publish`, identidad y contenido esperados, ausencia de
referencias en menús, y ausencia en `page_on_front` (88) y `page_for_posts` (0).
WooCommerce informó como autoridades oficiales 143 (shop), 144 (cart), 145
(checkout) y 146 (myaccount), por lo que 177 y 356 no eran autoridades WC.

## 6. Método oficial utilizado

Cada ID se procesó explícita e independientemente con:

```php
wp_update_post(['ID' => $id, 'post_status' => 'draft'], true);
clean_post_cache($id);
```

Después de cada llamada se recargó el objeto y se verificó ID devuelto, tipo,
estado, slug, contenido y metadatos Elementor. No se ejecutó SQL directo.

## 7. Resultado de ID 177

`wp_update_post()` devolvió exactamente `177`; la recarga confirmó
`post_type=page` y `post_status=draft`. Título, slug, contenido, Elementor,
plantilla, padre, orden, revisiones y attachments conservaron sus valores.

## 8. Resultado de ID 356

`wp_update_post()` devolvió exactamente `356`; la recarga confirmó
`post_type=page` y `post_status=draft`.

La verificación inmediata detectó que la primera ejecución, hecha sin usuario
autenticado en CLI, aplicó KSES al formulario HTML del contenido. La operación
se detuvo y se corrigió mediante una segunda llamada oficial a
`wp_update_post()`, bajo el usuario autor/administrador ID 1, restaurando el
contenido completo capturado y manteniendo `draft`. El resultado fue `356` y la
comparación final volvió exactamente a longitud 392 y SHA-256
`37817b10892a6e6ff0f4b2dcb653456e90f0917ea46d2525bfa109597dee6132`.
WordPress creó las revisiones automáticas 718 y 719; ninguna revisión previa se
eliminó. Este efecto queda registrado para auditoría.

## 9. Cachés identificadas

Se identificaron la caché de objetos de WordPress y LiteSpeed Cache activo
(`litespeed-cache/litespeed-cache.php`). Elementor estaba activo. El hook
oficial `litespeed_purge_all` estaba registrado.

## 10. Cachés purgadas

Se ejecutó `clean_post_cache()` para 177 y 356 y, una vez verificados ambos
borradores, `do_action('litespeed_purge_all')`. No se borraron archivos,
directorios, transients ni opciones. No se regeneró Elementor CSS: un cambio de
estado no lo requiere y los datos/CSS Elementor permanecieron intactos.

## 11. Búsqueda tradicional

Consultas `WP_Query` públicas posteriores para `Categorías` y `Principal`
arrojaron `found_posts=0` y `max_num_pages=0`; 177 y 356 quedaron ausentes. La
consulta de control `VeciAhorra` conservó 8 resultados, una página y las rutas
legítimas Inicio, Catálogo, fichas, Carrito, Checkout y Mis compras.

## 12. Live search

El endpoint real `/wp-json/wp/v2/search` con `ct_live_search=true` respondió
HTTP 200 y `[]` tanto para `Categorías` como para `Principal`. La consulta de
control `VeciAhorra` conservó los 8 resultados legítimos, incluidos Catálogo,
Carrito y Mis compras. No hubo error REST. No se dispuso de automatización de
navegador en este entorno, por lo que no se afirma una inspección de consola
JavaScript; la ruta HTTP usada por la interfaz quedó validada directamente.

## 13. Acceso directo posterior

Como visitante, `/categorias/` y `/principal/` respondieron HTTP 404, sin
redirección. Para un administrador los objetos continúan disponibles en
WordPress y sus permalinks de edición/preview se resuelven como `?page_id=177`
y `?page_id=356`; no se alteraron permisos ni se creó acceso público alterno.

## 14. Sitemap

La lista producida por el proveedor oficial de sitemaps de WordPress para
`page`, página 1, contiene cero entradas para `/categorias/` y `/principal/`.
Permanecen las rutas canónicas VeciAhorra. El sitemap se genera dinámicamente;
no expone una fecha global de regeneración. LiteSpeed fue purgado después del
cambio. El canonical público anterior ya no es accesible al visitante porque
la respuesta es 404.

## 15. Preservación de contenido

Los hashes y longitudes finales coinciden exactamente con los previos para
ambas páginas. Títulos y slugs permanecen intactos. No se cambió extracto,
autor, plantilla, padre ni orden. Las fechas de modificación cambiaron de forma
natural por `wp_update_post()`; no se editaron manualmente.

## 16. Preservación de Elementor

ID 177 conservó `_elementor_data` en 703 bytes con SHA-256 `b6f808...9013e`.
ID 356 conservó 3166 bytes con SHA-256 `977eaf...0fcb3f`. También permanecen
`_elementor_edit_mode=builder`, `_elementor_template_type=wp-page`, versión
4.1.2 y las claves de assets/cache existentes.

## 17. Preservación de revisiones

ID 177 conserva sus 16 revisiones. ID 356 conserva las 50 revisiones previas y
añadió 718/719 por las llamadas oficiales descritas; total final 52. No se
borró, alteró ni envió a papelera ninguna revisión.

## 18. Preservación de attachments

Los attachments referenciados por ID 177 (573, 574, 576 y 487) permanecen; no
había attachments hijos. ID 356 no tenía attachments hijos o referenciados.
No se modificó ni eliminó ningún medio o taxonomía asociada.

## 19. Regresión VeciAhorra

Respondieron correctamente: Catálogo, ficha pública, Carrito, Checkout y Mis
compras (HTTP 200). Inicio conserva su redirección HTTP→HTTPS configurada. El
menú ID 3 continúa asignado a `menu_1` y `menu_mobile`, con Inicio (88),
Catálogo (702), Carrito (698), Mis compras (713), Nosotros y Contacto. Esto
cubre el mismo menú para escritorio y el offcanvas móvil sin dependencia de
177/356. La batería funcional cubrió navegación e infraestructura; no hubo una
sesión gráfica de navegador disponible para inspección manual responsive.

## 20. Regresión WooCommerce

Las asignaciones siguen siendo 143 Tienda, 144 Carrito, 145 Finalizar compra y
146 Mi cuenta, todas `publish`. Acceso público: Tienda 200, Carrito 200, Mi
cuenta 200; Checkout mantiene su comportamiento esperado de redirigir 302 al
carrito cuando no existe sesión/carrito apto. No se modificó WooCommerce.

## 21. Pruebas

Las diez pruebas requeridas terminaron con código 0:

- `public-search-commercial-pages-test.php`
- `public-search-isolation-test.php`
- `frontend-foundation-test.php`
- `public-route-link-test.php`
- `catalog-public-api-test.php`
- `catalog-public-detail-test.php`
- `public-add-to-cart-test.php`
- `public-cart-test.php`
- `public-checkout-test.php`
- `customer-panel-frontend-infrastructure-test.php`

## 22. Riesgos residuales

Persisten URLs antiguas potencialmente conocidas por buscadores o backlinks no
observables, productos/categorías WooCommerce en otros sitemaps y la necesidad
de una validación visual manual en navegadores reales. El borrador es
reversible y una restauración accidental reintroduciría búsqueda y sitemap.

## 23. Redirecciones pendientes

En un microhito posterior se recomienda redirigir 301 las URLs anteriores de
177 y 356 al catálogo canónico VeciAhorra. El destino debe provenir de la
autoridad `PublicRoute`, evitando loops y sin conservar ciegamente
`post_type=product`, filtros WC o paginación WC. No se implementaron
redirecciones aquí.

## 24. Riesgos SEO pendientes

Quedan fuera de alcance Analytics/Search Console, backlinks externos,
productos y `product_cat` WooCommerce en sitemap, `robots.txt`, meta robots y
SEO global. El retiro natural del sitemap de páginas quedó comprobado.

## 25. Conclusión

Los dos únicos IDs autorizados están en borrador, fuera de búsqueda, live
search y sitemap, con 404 público. Su contenido, datos Elementor y objetos
relacionados permanecen preservados. Las superficies canónicas VeciAhorra y
las autoridades WooCommerce no sufrieron regresiones. No se modificó código
productivo, configuración, menús, Blocksy, Elementor, WooCommerce ni pruebas;
no hubo staging, commit ni push.
