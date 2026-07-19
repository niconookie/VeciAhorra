# Certificación integral de navegación pública

## 1. Resumen ejecutivo

**Veredicto: No certificado.** El menú, la portada y el flujo transaccional
canónico están aislados, pero la búsqueda pública normal permite alcanzar
páginas WooCommerce publicadas. La evidencia bloqueante es reproducible:

1. buscar `Carrito` desde el formulario público;
2. el resultado tradicional incluye `Carrito` (página WooCommerce ID 144,
   `/carrito/`), además del enlace canónico del menú;
3. el live search devuelve las páginas ID 698 (`/carrito-veciahorra/`) e ID
   144 (`/carrito/`);
4. seleccionar ID 144 abre el carrito WooCommerce.

La búsqueda `Tienda` también ofrece la página WooCommerce ID 143 y abre
`/tienda/`, donde hay fichas y botones de compra WooCommerce. Por tanto, un
visitante sí puede abandonar accidentalmente VeciAhorra siguiendo navegación
visible y ordinaria. No se modificó la instalación.

## 2. Objetivo y criterio de certificación

Se evaluó si menús, header, búsqueda y enlaces visibles permiten entrar
accidentalmente en la rama comercial WooCommerce. Se separó:

- navegación ordinaria: enlaces descubiertos desde la interfaz;
- acceso directo: URL heredada escrita deliberadamente;
- infraestructura: carga de assets, clases o endpoints sin enlace visible;
- indexación: descubrimiento por sitemap, separado del recorrido de UI.

Una ruta ordinaria comprobada hacia WooCommerce obliga al veredicto **No
certificado**, aunque las URLs directas deban permanecer operativas.

## 3. Entorno auditado

| Dato | Valor efectivo |
|---|---|
| Sitio | `https://localhost/Minimarket/` |
| Fecha | 18-07-2026, zona `America/Santiago` |
| Tema | Blocksy 2.1.44 |
| Portada | página ID 88 |
| Menú | ID 3, `Menu principal` |
| Ubicaciones | `menu_1=3`, `menu_mobile=3` |
| WooCommerce | activo; páginas oficiales 143–146 publicadas |
| Último commit | `20d1227 feat(frontend): isolate public search from WooCommerce products` |
| SEO | sin plugin SEO activo; sitemap core WordPress activo |

Fuente de verdad leída: `docs/public-search-isolation-design.md`, código
productivo, opciones WordPress/Blocksy, contenido almacenado, HTML HTTP real y
respuestas REST reales.

## 4. Metodología

Primera pasada: inventario de páginas, menús, placements, widgets, shortcodes,
Elementor, rutas resueltas, HTML y enlaces. Segunda pasada: seguimiento de
enlaces servidos desde Inicio, menú, resultados tradicionales y live search;
pruebas deliberadas de URLs WooCommerce; y contratos automatizados de
catálogo, ficha, carrito, checkout y panel.

Etiquetas de evidencia:

- **Navegado:** solicitud HTTP real siguiendo un destino visible.
- **Ejecutado:** consulta WordPress o REST real.
- **Configuración:** dato leído mediante API WordPress.
- **Código:** contrato productivo inspeccionado.
- **No verificable:** interacción visual que requiere navegador humano.

No se inició pago real ni se alteraron datos, opciones o contenido.

## 5. Inventario de superficies

| Superficie | Origen | Destino/contrato | Resultado |
|---|---|---|---|
| Logo | Blocksy | Inicio | correcto |
| Menú desktop | WP menú 3 | seis páginas canónicas/informativas | correcto |
| Menú móvil | WP menú 3 | mismo conjunto | correcto |
| Search desktop | Blocksy | tradicional + `/wp/v2/search` | bloqueante por páginas WC |
| Inicio | Elementor ID 88 | dos enlaces `PublicRouteLink` a catálogo | correcto |
| Catálogo | `[veciahorra_frontend]` ID 702 | REST propio | correcto |
| Fichas | `[veciahorra_frontend product_id=…]` | ofertas/inventario propio | correcto |
| Carrito | `[veciahorra_cart]` ID 698 | REST propio | correcto |
| Checkout | `[veciahorra_checkout]` ID 695 | flujo propio | correcto |
| Mis compras | `[veciahorra_customer_panel]` ID 713 | login/panel propio | correcto |
| Footer | Blocksy | crédito `CreativeThemes` | sin WooCommerce |
| Sitemap | WordPress core | páginas, productos y categorías WC | riesgo SEO |

## 6. Recorrido de navegación de escritorio

**Navegado:** Inicio devolvió HTTP 200. El header ofrece Inicio, Catálogo,
Carrito, Mis compras, Nosotros y Contacto. Inicio → Catálogo → ficha
`/producto-coca-cola-350-cc/` permanece en páginas VeciAhorra. Ficha → Ver
carrito usa la URL resuelta `/carrito-veciahorra/`; Carrito → Checkout usa
`/checkout/`; Mis compras anónimo → `wp-login.php` con retorno a
`/mis-compras/`.

**Ruta de escape comprobada:** header → búsqueda `Carrito` → resultado
`https://localhost/Minimarket/carrito/` → carrito WooCommerce. Header →
búsqueda `Tienda` → `https://localhost/Minimarket/tienda/` → catálogo
WooCommerce. Esto invalida la certificación.

## 7. Recorrido móvil

**Configuración y HTML:** `middle-row/end` contiene `trigger`; el offcanvas
contiene solo `mobile-menu`; `menu_mobile` usa el mismo menú ID 3. No hay
placement `cart` ni buscador móvil. Las seis rutas del menú aparecen también
en el HTML responsive.

El recorrido esencial móvil Inicio → Catálogo → ficha → Carrito → Checkout es
el mismo. La fuga del buscador afecta al modal de escritorio; no se comprobó
otro buscador activo en el offcanvas. La apertura visual, foco y cierre del
offcanvas no se automatizaron en esta sesión; el contrato accesible nativo de
Blocksy no fue sustituido por el plugin.

## 8. Resultado para visitantes

- Inicio, catálogo, ficha, carrito y checkout responden HTTP 200.
- Mis compras responde 200 y ofrece `Iniciar sesión` hacia
  `wp-login.php?redirect_to=…/mis-compras/`.
- El buscador tradicional y live son públicos.
- Visitantes pueden alcanzar `/carrito/` y `/tienda/` desde resultados; existe
  exposición accidental.

## 9. Resultado para usuarios autenticados

**Código y ejecución HTTP:** el panel usa la identidad WordPress, omite el
CTA de login y consulta únicamente contratos de Customer Panel VeciAhorra. Los
enlaces de lista/detalle, `?compra=` y retorno se mantienen en `/mis-compras/`.
No se encontraron enlaces a `/mi-cuenta/` ni pedidos WooCommerce.

No se realizó una compra ni pago nuevo. El estado autenticado se verificó a
nivel de controlador/contratos y datos previamente validados; no se capturó
una sesión visual autenticada nueva.

## 10. Menú y header

Menú ID 3, sin duplicados ni elementos personalizados:

| Orden | Elemento/ID | Página | URL efectiva |
|---:|---|---:|---|
| 1 | Inicio / 91 | 88 | `/` |
| 2 | Catálogo / 711 | 702 | `/catalogo-veciahorra/` |
| 3 | Carrito / 712 | 698 | `/carrito-veciahorra/` |
| 4 | Mis compras / 716 | 713 | `/mis-compras/` |
| 5 | Nosotros / 22 | 15 | `/nosotros/` |
| 6 | Contacto / 20 | 17 | `/contacto/` |

`PublicRouteResolver` devolvió exactamente Inicio, catálogo ID 702, carrito ID
698, checkout ID 695 y compras ID 713. Desktop usa `logo | menu, search`;
móvil usa `logo | trigger` y `mobile-menu`. Aunque existe un nodo de valores
inactivo llamado `cart`, no está en ningún placement y no se renderiza
`.ct-header-cart`, contador, panel ni trigger de mini-cart. Las cadenas
`woocommerce`/`wc-block` presentes globalmente corresponden a body/assets de
infraestructura y no a un elemento visual del header.

## 11. Inicio

Página 88, plantilla `elementor_header_footer`, editada con Elementor. Conserva
hero, textos e imágenes. Los únicos CTA comerciales almacenados son:

```text
[veciahorra_public_route_link route="catalog" label="Ver productos"]
[veciahorra_public_route_link route="catalog" label="Explorar catálogo"]
```

Ambos renderizan enlaces reales al catálogo resuelto. HTTP 200, sin enlaces a
productos, tienda, carrito, checkout o cuenta WooCommerce. No aparece
`premium-woo-products` ni botón WooCommerce.

## 12. Búsqueda tradicional

| Prueba | HTTP | Resultado relevante |
|---|---:|---|
| `?s=Coca-Cola` | 200 | página VeciAhorra ID 715; cero enlaces WC |
| término inexistente | 200 | estado sin resultados |
| `?s=Carrito` | 200 | incluye ID 144 `/carrito/` **bloqueante** |
| `?s=Tienda` | 200 | incluye ID 143 `/tienda/` **bloqueante** |
| `?s=Coca-Cola&post_type=product` | 302 | singular WC `/producto/coca-cola/`; product-only preservado |

La exclusión de `product` funciona, pero no excluye páginas WordPress que son
autoridad WooCommerce. Conteos y paginación siguen siendo nativos porque la
intervención ocurre antes de consultar.

## 13. Live search

Petición real a `/wp/v2/search`, `type=post`,
`subtype[]=post&page&product`, `ct_live_search=true`:

- `Coca-Cola`: HTTP 200, solo ID 715, subtype `page`, URL VeciAhorra.
- `Carrito`: HTTP 200, ID 698 `/carrito-veciahorra/` **e ID 144
  `/carrito/`**. El segundo resultado es bloqueante.
- Sin marcador: HTTP 200 y conserva ID 420 subtype `product`, confirmando el
  aislamiento del endpoint ajeno.
- Product-only marcado: conserva ID 420 y su URL WooCommerce.

El modal usa formulario navegable sin JavaScript para el submit tradicional.
No se observó error de servidor; la consola JavaScript no fue capturada.

## 14. Catálogo

Página 702, HTTP 200, `[veciahorra_frontend]`, contrato `data-va-catalog`.
Consume `/veciahorra/v1/catalog`; tarjetas y detalle provienen de contratos
propios. Las pruebas de API/detalle validan filtros, orden, paginación, mínimo
de ofertas y enlaces a páginas VeciAhorra. El HTML inicial no contiene enlace
WooCommerce ni botón “Añadir al carrito” heredado.

## 15. Fichas públicas

Publicadas: ID 700 (`product_id=111`), 701 (`112`) y 715 (`999999995`). La
ficha 715 respondió 200 con `data-va-product-detail`; selección y agregado
operan por `inventory_id` contra `/veciahorra/v1/cart/items`. “Ver carrito” usa
el resolver. No hay `/producto/`, breadcrumbs WooCommerce, relacionados ni
botones WC en la ficha canónica. Estados sin oferta/error están definidos por
el frontend propio.

## 16. Carrito

Página 698, HTTP 200, `[veciahorra_cart]`, contrato `data-va-cart`. Lee,
actualiza, elimina y limpia mediante `/veciahorra/v1/cart`. Checkout se obtiene
de `PublicRouteResolver::checkout()`. Nombres e imágenes no son enlaces a
productos WC. No se encontró `/carrito/` heredado en esta superficie.

## 17. Checkout

Página 695, HTTP 200, `[veciahorra_checkout]`, contrato `data-va-checkout`.
Resumen, identidad, entrega, RB-CHK-001 y pago usan servicios VeciAhorra. La
recuperación Webpay vuelve al checkout resuelto. No se inició transacción real.
No aparecen formulario, bloques o enlaces WooCommerce.

La URL WC `/finalizar-compra/` permanece operativa y, con carrito WC vacío,
respondió 302 hacia `/carrito/`; no está enlazada desde el checkout canónico.

## 18. Mis compras

Página 713, HTTP 200, shortcode `[veciahorra_customer_panel]`. Visitante:
login con retorno exacto a `/mis-compras/`. Autenticado: listado/detalle propio,
deep link `?compra=`, botón volver y actualización dentro de la misma página.
Los identificadores expuestos son IDs públicos de checkout, no claves internas
de base de datos. No se encontraron `/mi-cuenta/` ni endpoints de pedidos WC.

Existe deuda de prueba histórica con `/mis-pedidos/`, pero la autoridad viva,
menú, resolver, login y página publicada usan `/mis-compras/`.
`customer-panel-frontend-infrastructure-test.php` falla actualmente en la
aserción de retorno porque todavía construye y espera `/mis-pedidos/`; no se
modificó por quedar fuera del alcance documental.

## 19. Footer

No hay `footer_placements` personalizado ni menú/footer widget comercial
asignado. El footer renderizado contiene únicamente el crédito externo
`CreativeThemes`; no enlaza a WooCommerce. Es una observación de branding, no
un defecto de aislamiento.

## 20. Breadcrumbs

No se renderizaron breadcrumbs en Inicio, catálogo, ficha VeciAhorra, carrito,
checkout, Mis compras ni búsqueda. Por tanto no hay jerarquía “Tienda” ni
archivo de productos en el flujo canónico. No se propone añadirlos.

## 21. Widgets y placements

- `sidebar-1`: búsqueda WordPress, entradas/comentarios recientes, archivos y
  categorías de blog; no se observó en las superficies canónicas.
- `sidebar-woocommerce`: `woof_widget-2` (“HUSKY Filter”), activo únicamente
  para archivos WooCommerce (`woo_categories_has_sidebar=yes`, posición left).
- Header: sin placement cart; search solo desktop; mobile-menu en offcanvas.
- No hay footer placement activo.
- El widget WOOF es infraestructura heredada activa en la rama directa, no un
  enlace desde VeciAhorra; es riesgo futuro si se reasigna a un sidebar general.

## 22. Páginas publicadas

| ID | Título / slug | Contenido principal | Menú | Autoridad/riesgo |
|---:|---|---|---|---|
| 88 | Inicio / `inicio` | Elementor + 2 PublicRouteLink | sí | Inicio canónico |
| 143 | Tienda / `tienda` | archivo WooCommerce | no | WC; buscable, bloqueante |
| 144 | Carrito / `carrito` | `[woocommerce_cart]` | no | WC; buscable, bloqueante |
| 145 | Finalizar compra / `finalizar-compra` | `[woocommerce_checkout]` | no | WC directo |
| 146 | Mi cuenta / `mi-cuenta` | `[woocommerce_my_account]` | no | WC directo |
| 177 | Categorías / `categorias` | Elementor categorías WC | no | enlaza taxonomía WC |
| 356 | Principal / `principal` | `[products limit="50"…]` | no | listado WC público |
| 695 | Checkout VeciAhorra / `checkout` | `[veciahorra_checkout]` | no | checkout canónico |
| 698 | Carrito VeciAhorra / `carrito-veciahorra` | `[veciahorra_cart]` | sí | carrito canónico |
| 700 | Producto Retiro E2E | ficha `product_id=111` | no | ficha VA/prueba publicada |
| 701 | Producto Despacho E2E | ficha `product_id=112` | no | ficha VA/prueba publicada |
| 702 | Catálogo VeciAhorra | `[veciahorra_frontend]` | sí | catálogo canónico |
| 713 | Mis compras | `[veciahorra_customer_panel]` | sí | panel canónico |
| 715 | Coca-Cola 350 cc | ficha `product_id=999999995` | no | ficha VA publicada |

Todas las filas indicadas están publicadas. La política de devoluciones ID 147
está en borrador y no es entrada pública.

## 23. Enlaces internos

El código productivo del frontend obtiene carrito, checkout, catálogo y compras
mediante `PublicRouteResolver`. No se encontraron hardcodeos productivos a
slugs WC, `wc_get_cart_url`, `wc_get_checkout_url` ni
`wc_get_page_permalink`. Elementor de Inicio no conserva
`premium-woo-products`.

Exposiciones almacenadas legítimas/heredadas: shortcodes WC en 144–146,
`[products]` en 356 y enlaces Elementor a categorías WC en 177. No salen del
menú, pero 143 y 144 sí obtienen enlaces entrantes desde búsqueda pública.

## 24. URLs directas WooCommerce

| URL deliberada | HTTP | Resultado |
|---|---:|---|
| `/tienda/` | 200 | 41 enlaces WC detectados |
| `/producto/spagetthi-trattoria/` | 200 | ficha, categoría, relacionado y agregar al carrito |
| `/categoria-producto/sin-categoria/` | 200 | archivo WC |
| `/carrito/` | 200 | carrito WC |
| `/finalizar-compra/` | 302 | redirige a `/carrito/` vacío |
| `/mi-cuenta/` | 200 | cuenta/login WC |
| product-only Coca-Cola | 302 | singular `/producto/coca-cola/` |

Su acceso directo no es por sí solo defecto. El defecto es que `/tienda/` y
`/carrito/` también aparecen como resultados de la búsqueda ordinaria.

## 25. Sitemap e indexación

`/wp-sitemap.xml` responde 200 e incluye:

- sitemap de páginas: 16 URLs, incluidas 143–146, 177 y 356, además de las
  canónicas VeciAhorra;
- sitemap de productos: 10 fichas `/producto/…/`;
- sitemap de `product_cat`: 6 archivos `/categoria-producto/…/`;
- sitemaps de posts, categorías y usuarios.

No hay plugin SEO activo. Las páginas auditadas muestran robots permisivo
(`max-image-preview:large`) y no tienen noindex almacenado. `/robots.txt`
respondió 404 en esta instalación. Este es un riesgo de indexación distinto de
la fuga de navegación, aunque buscadores externos pueden convertirlo en punto
de entrada. No se modificó SEO.

## 26. Redirecciones y errores

- HTTP → HTTPS responde 301; comportamiento esperado del sitio.
- `/finalizar-compra/` → `/carrito/` responde 302 con carrito WC vacío.
- búsqueda product-only con coincidencia única → ficha WC (302 canónico).
- páginas canónicas auditadas: HTTP 200, sin 404 ni redirección WC.
- `/robots.txt`: 404, observación SEO.
- login de Mis compras conserva `redirect_to=/mis-compras/`.
- no se crearon redirecciones ni se probaron slugs inválidos destructivamente.

## 27. Hallazgos clasificados

### Bloqueantes (2)

1. Búsqueda tradicional `Carrito` enlaza página WC ID 144 `/carrito/`; live
   search también devuelve ID 144 junto a ID 698.
2. Búsqueda tradicional `Tienda` enlaza página WC ID 143 `/tienda/`, desde la
   que se navega a productos y acciones de carrito WooCommerce.

### Importantes (2)

1. Páginas 177 y 356 siguen publicadas; renderizan categorías/productos WC y
   ofrecen enlaces comerciales heredados aunque no estén en el menú.
2. Sitemap de páginas publica las autoridades WC 143–146 y páginas heredadas,
   facilitando entradas externas a la rama secundaria.

### Menores (3)

1. Footer conserva crédito visible `CreativeThemes` (no WooCommerce).
2. `/robots.txt` devuelve 404 y no hay política diagnóstica explícita.
3. La prueba manual histórica del Customer Panel espera `/mis-pedidos/`, en
   contradicción con la autoridad efectiva `/mis-compras/`.

### Riesgos futuros (3)

1. Sitemap incluye 10 productos y 6 categorías WooCommerce indexables.
2. `woof_widget-2` permanece activo en `sidebar-woocommerce` y podría quedar
   expuesto si cambia la asignación de sidebar.
3. El nodo inactivo de valores `cart` permanece en Header Builder; no está en
   placements, pero una reconfiguración podría reactivarlo.

## 28. Riesgo residual

El flujo transaccional VeciAhorra aislado es estable, pero el aislamiento por
tipo de contenido no equivale a aislamiento por autoridad funcional: Tienda,
Carrito, Checkout y Mi cuenta WooCommerce son `page`, igual que las fichas
VeciAhorra. Mientras esas páginas sean elegibles en búsqueda general, el riesgo
es actual, no meramente futuro.

## 29. Recomendaciones futuras

Crear un microhito correctivo independiente para excluir de búsqueda pública
general las páginas WC por autoridad (IDs resueltos dinámicamente mediante
WooCommerce/WordPress, nunca slugs), preservando acceso directo, administración
y compatibilidad. Incluir 143–146, y decidir explícitamente el tratamiento de
177 y 356. Probar tradicional y live con `Carrito`, `Tienda`, `Mi cuenta` y
`Finalizar compra`.

En microhitos SEO separados: definir noindex/sitemap para páginas, productos y
taxonomías WC; revisar `robots.txt`; y decidir si las páginas E2E 700/701 deben
seguir indexables. En configuración futura: mantener WOOF confinado y vigilar
que `cart` no vuelva a placements.

## 30. Veredicto de certificación

### No certificado

Existe al menos una ruta pública ordinaria —de hecho dos términos comprobados
en el buscador visible— que conduce accidentalmente al flujo heredado. La
respuesta verificable a la pregunta de certificación es **sí**: un usuario
puede llegar a `/carrito/` o `/tienda/` sin conocer ni escribir esas URLs.

Este veredicto no cuestiona la operación interna de WooCommerce ni exige
bloquear sus URLs directas. Se limita a la exposición desde la navegación
pública normal de VeciAhorra.
