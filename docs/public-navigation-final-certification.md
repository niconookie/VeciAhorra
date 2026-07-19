# Certificación final de navegación pública

## 1. Resumen ejecutivo

**Veredicto: Certificado con observaciones.** La segunda pasada independiente
no encontró una vía reproducible desde la navegación pública ordinaria de
VeciAhorra hacia páginas, productos, categorías, carrito, checkout o cuenta de
WooCommerce. Los bloqueantes históricos están cerrados: las páginas oficiales
143–146 ya no aparecen en la búsqueda pública general y las páginas heredadas
177/356 permanecen en borrador, fuera de búsqueda y sitemap.

Los accesos directos WooCommerce siguen disponibles deliberadamente y existen
riesgos SEO y de prevención futura. Ninguno genera hoy un enlace interno
ordinario. Funcionalmente, la Serie 30 puede cerrarse respecto de su objetivo
de navegación pública, manteniendo los trabajos de redirección y SEO como
series posteriores separadas.

## 2. Veredicto

### Certificado con observaciones

La respuesta verificable a la pregunta principal es **no**: un visitante no
puede alcanzar accidentalmente el comercio heredado mediante las superficies
ordinarias auditadas. Las observaciones son deuda de pruebas, limitaciones de
automatización visual, branding del footer, redirecciones pendientes y riesgos
SEO; no son rutas comerciales internas.

## 3. Contexto

La certificación inicial falló porque las búsquedas “Tienda” y “Carrito”
exponían las páginas oficiales WooCommerce. El commit `1f272ae` excluyó las
autoridades 143–146. La recertificación posterior encontró las páginas
heredadas 177 y 356. La auditoría de uso autorizó su retiro reversible y el
microhito 30.1.4.9 las pasó de `publish` a `draft` preservando sus datos.

## 4. Objetivo

Determinar de manera final e independiente si la navegación ordinaria visible
de VeciAhorra permite abandonar accidentalmente el flujo canónico e ingresar a
la rama comercial WooCommerce.

## 5. Alcance

Se revisaron estado WordPress, menús desktop/móvil, Header Builder, Inicio,
footer, breadcrumbs, widgets, páginas informativas, búsqueda tradicional y
live, búsqueda product-only, catálogo, fichas, carrito, checkout, Mis compras,
acceso directo, sitemap, robots, código y atributos almacenados/renderizados.
No se cambió configuración, contenido ni código productivo.

## 6. Definición de navegación accidental

Es navegación accidental todo destino WooCommerce descubierto mediante menú,
header, footer, búsqueda, CTA, formulario, enlace, widget, breadcrumb o
superficie responsive ordinaria. No lo son una URL escrita manualmente,
endpoints deliberados, wp-admin, Store API o sitemap por sí solo.

## 7. Metodología

Se leyeron íntegramente los cinco documentos exigidos y se contrastaron con
APIs WordPress/WooCommerce, theme mods, menús, sidebars, páginas y metadatos
actuales. Se realizaron solicitudes HTTP reales a las superficies públicas y
búsquedas, peticiones REST equivalentes al live search, inventario de enlaces
`href`/`action`, revisión de contenido/Elementor, sitemaps y contratos manuales.
Se ejecutó una segunda búsqueda estática en `app/`, `assets/` y el bootstrap.

## 8. Limitaciones

El entorno es `localhost`, sin Analytics, Search Console ni evidencia de
backlinks externos. No se inició pago real. No hubo una sesión GUI completa
con puntero para desktop/tablet/móvil o usuario cliente autenticado. El intento
headless del Customer Panel falló antes del harness porque Chrome perdió su
proceso GPU; los contratos HTML, REST, menú y responsive almacenados sí fueron
verificados. Por ello no se afirma una captura visual de consola JavaScript,
aunque no hubo errores HTTP/REST reproducibles en las rutas auditadas.

## 9. Estado de ID 177

Existe como `page`, estado `draft`, título `Categorías`, slug `categorias`.
Conserva contenido de 1512 bytes, SHA-256
`b228bec9b2f51b5440bd7d8ad7780c48c9797cec411bda076aa70f3e038b97e3`,
datos Elementor de 703 bytes con SHA-256
`b6f8083d48ee129f23f9bd60ca88a9c9aa4fc230725b7b1c8615d49ae139013e`
y 16 revisiones. No está en menú, búsqueda ni sitemap. `/categorias/`
responde HTTP 404 sin redirección para visitantes.

## 10. Estado de ID 356

Existe como `page`, estado `draft`, título `Principal`, slug `principal`.
Conserva contenido de 392 bytes, SHA-256
`37817b10892a6e6ff0f4b2dcb653456e90f0917ea46d2525bfa109597dee6132`,
datos Elementor de 3166 bytes con SHA-256
`977eaf3f6e9c9581e44a7a805790db65d6c547cd550f6824f1d57f111f0fcb3f`
y 52 revisiones, incluidas las dos automáticas documentadas en el retiro. No
está en menú, búsqueda ni sitemap. `/principal/` responde HTTP 404 sin
redirección para visitantes.

## 11. Páginas oficiales WooCommerce

WooCommerce continúa resolviendo `shop=143`, `cart=144`, `checkout=145` y
`myaccount=146`; las cuatro páginas son `page`, `publish`:

| Rol | ID | URL | Acceso directo |
|---|---:|---|---:|
| Tienda | 143 | `/tienda/` | 200 |
| Carrito | 144 | `/carrito/` | 200 |
| Checkout | 145 | `/finalizar-compra/` | 302 a `/carrito/` con carrito vacío |
| Mi cuenta | 146 | `/mi-cuenta/` | 200 |

Están ausentes de búsqueda tradicional real y live search general. Las
consultas product-only y superficies WooCommerce deliberadas siguen intactas.

## 12. Menú de escritorio

`menu_1=3`, menú “Menu principal”. Todos son elementos `post_type/page`, sin
target, XFN, clases especiales, padres, dropdowns ni enlaces personalizados:

| Orden | Texto | Página | URL efectiva |
|---:|---|---:|---|
| 1 | Inicio | 88 | `/` |
| 2 | Catálogo | 702 | `/catalogo-veciahorra/` |
| 3 | Carrito | 698 | `/carrito-veciahorra/` |
| 4 | Mis compras | 713 | `/mis-compras/` |
| 5 | Nosotros | 15 | `/nosotros/` |
| 6 | Contacto | 17 | `/contacto/` |

El Header Builder desktop coloca `logo` en `middle-row/start` y exactamente
`menu, search` en `middle-row/end`. Ningún placement activo contiene `cart`.

## 13. Menú móvil

`menu_mobile=3`, por lo que usa exactamente los seis elementos anteriores.
Blocksy coloca `logo` y `trigger` en la fila media y únicamente `mobile-menu`
en el offcanvas. No hay búsqueda, carrito WooCommerce, submenú alternativo ni
elemento oculto adicional. La estructura nativa conserva enlaces reales y
funcionamiento sin depender de un menú paralelo.

## 14. Inicio

La página 88 respondió HTTP 200. El contenido Elementor conserva hero,
imágenes y los dos shortcodes `veciahorra_public_route_link` hacia catálogo.
El HTML renderizado no contiene enlaces a tienda, productos, categorías,
carrito, checkout o cuenta WooCommerce ni a 177/356. No existe
`premium-woo-products` ni botón WooCommerce almacenado.

## 15. Footer

Los seis sidebars `ct-footer-sidebar-*` están vacíos. No existe menú comercial
de footer ni enlaces WooCommerce. El footer renderizado conserva el crédito
externo `CreativeThemes`, observación de branding sin impacto comercial.

## 16. Breadcrumbs

No se renderizaron breadcrumbs en Inicio, catálogo, fichas, carrito, checkout,
Mis compras, Nosotros, Contacto ni resultados. No existe jerarquía hacia
“Tienda”, archivos de producto o categorías WooCommerce.

## 17. Widgets

`sidebar-1` conserva cinco bloques estándar de búsqueda/blog y no aparece en
las superficies canónicas. `sidebar-woocommerce` contiene `woof_widget-2` y
`sidebar-woof` contiene `woocommerce_price_filter`; permanecen confinados a
superficies WooCommerce deliberadas. Footer e inactivos están vacíos. Son
riesgo futuro si una configuración los reasigna, no navegación actual.

## 18. Páginas informativas

Inicio, Nosotros y Contacto respondieron 200. Su HTML no produjo enlaces
WooCommerce ni heredados. El inventario de contenido publicado solo encontró
señales comerciales en las páginas oficiales 144–146; Tienda 143 es autoridad
dinámica. No apareció otra página comercial heredada publicada.

## 19. Búsqueda tradicional

Transporte real: `GET /Minimarket/?s={término}`, HTTP 200 en todos los casos.
Los enlaces de artículos fueron:

| Consulta | Resultado comercial pertinente |
|---|---|
| Tienda | ninguno |
| Carrito | ID 698, Carrito VeciAhorra |
| Checkout | ID 695, Checkout VeciAhorra |
| Finalizar compra | ninguno |
| Mi cuenta | ninguno |
| Categorías | ninguno |
| Principal | ninguno |
| Producto | IDs 700 y 701 VeciAhorra; Inicio también coincide por texto |
| Productos | Inicio |
| Coca | ID 715, ficha VeciAhorra |

No aparecen 143–146, 177, 356 ni posts `product`. Los resultados tienen como
máximo una página; estados vacíos y conteos permanecen coherentes. Enlaces de
feed/autor generados por WordPress no conducen al comercio WooCommerce.

## 20. Live search

Endpoint `/wp-json/wp/v2/search`, `type=post`, subtypes `post,page,product`,
`ct_live_search=true`, `per_page=100`. Todas las consultas respondieron 200.
Tienda, Finalizar compra, Mi cuenta, Categorías y Principal devolvieron vacío;
Carrito devolvió solo 698; Checkout solo 695; Producto devolvió 700/701 e
Inicio; Productos devolvió Inicio; Coca devolvió solo 715. Todos los resultados
son subtype `page`. No hubo error REST ni enlace WooCommerce.

Los resultados son enlaces REST reales utilizables por teclado en el componente
nativo. La consola gráfica no pudo capturarse por la limitación indicada.

## 21. Búsqueda product-only

Una consulta deliberada `post_type=product` para “Coca” conservó tres productos
WooCommerce: IDs 561, 560 y 420, con URLs `/producto/...`. Esto confirma que el
aislamiento general no altera búsquedas product-only. Las pruebas de política
también preservan REST sin marcador, administración, exclusiones previas y
Store API.

## 22. Catálogo VeciAhorra

La autoridad devuelve `/catalogo-veciahorra/`; respondió 200 con
`[veciahorra_frontend]`. API, filtros, orden, paginación, tarjetas, precios,
imágenes y estados fueron cubiertos por pruebas. Los contratos enlazan fichas
VeciAhorra, no `/producto/`, `/categoria-producto/`, `/tienda/` ni carrito WC.
El HTML inicial tampoco contiene enlaces WooCommerce.

## 23. Fichas

Se auditaron ID 700 (retiro), 701 (despacho) y 715 (Coca); todas respondieron
200 y no renderizaron destinos WooCommerce. Ofertas, minimarket, precio, stock,
selección y agregado operan por inventario y REST VeciAhorra. “Ver carrito” y
regresos usan rutas canónicas. No hay relacionados ni breadcrumbs WC.

## 24. Carrito

La autoridad devuelve `/carrito-veciahorra/`; respondió 200. Menú y fichas
usan esa ruta. Lectura, cantidades, eliminación y vaciado usan REST propio;
checkout se resuelve como `/checkout/`. No hay enlace `/carrito/` ni productos
WooCommerce en el HTML o contratos auditados.

## 25. Checkout

La autoridad devuelve `/checkout/`; respondió 200. El acceso proviene del
carrito VeciAhorra. Formulario, identidad, entrega, recuperación y retorno son
propios. No se inició pago. No existe enlace o derivación a
`/finalizar-compra/`; esa página solo se alcanzó de forma directa deliberada.

## 26. Mis compras

La autoridad y menú usan `/mis-compras/`, página 713, shortcode
`[veciahorra_customer_panel]`, HTTP 200. Visitantes obtienen login WordPress con
retorno canónico; los contratos autenticados usan listado, detalle,
`?compra=`, retorno y endpoints propios. No hay `/mi-cuenta/`, pedidos ni
endpoints de cuenta WooCommerce.

## 27. Usuario visitante

Puede recorrer menú, búsqueda, catálogo, fichas, carrito, checkout, Mis compras
e informativas sin enlace WooCommerce. Mis compras cambia a login WordPress,
no a Mi cuenta WooCommerce. Las páginas 177/356 son 404 públicas.

## 28. Usuario autenticado

El controlador y contratos del Customer Panel usan identidad WordPress y rutas
VeciAhorra; no agregan enlaces WC ni sustituyen el menú. Las pruebas de
infraestructura y REST pasaron. No se abrió una sesión visual nueva de cliente;
el test headless de lista/detalle no alcanzó el harness por fallo GPU de Chrome,
por lo que esta parte queda certificada por configuración, código y contratos,
no por captura GUI nueva.

## 29. Responsive

Desktop usa menú/search; móvil/tablet usan trigger y el mismo menú 3 en
offcanvas. No existe placement `cart` en ninguna fila ni offcanvas. El nodo de
valores inactivo `cart` permanece almacenado, pero no se renderiza como
componente. Las superficies Elementor auditadas no contienen variantes
responsive con enlaces WooCommerce. Foco, gesto táctil y sticky visual no se
automatizaron; no existe diferencia de destinos entre breakpoints.

## 30. Acceso directo deliberado

| URL | HTTP / resultado |
|---|---|
| `/tienda/` | 200 |
| `/carrito/` | 200 |
| `/checkout/` | 200, checkout canónico VeciAhorra |
| `/finalizar-compra/` | 302 a `/carrito/` con carrito WC vacío |
| `/mi-cuenta/` | 200 |
| `/categorias/` | 404, sin redirección |
| `/principal/` | 404, sin redirección |

Las URLs WC funcionan deliberadamente, pero ninguna está enlazada desde una
superficie VeciAhorra ordinaria.

## 31. Sitemap de páginas

El proveedor core contiene 14 páginas. IDs 177 y 356 están ausentes. Están
presentes las rutas canónicas VeciAhorra y las cuatro páginas oficiales
WooCommerce. Esta última exposición es riesgo SEO, no ruta interna ordinaria.

## 32. Sitemap WooCommerce

Se registraron 10 URLs de productos y 6 URLs `product_cat`. Las páginas
oficiales 143–146 están en el sitemap de páginas. No se corrigió ningún archivo
ni taxonomía. Son riesgos exclusivamente SEO.

## 33. Robots

`/robots.txt` respondió HTTP 404 y no existe una política pública efectiva en
esta instalación. Se clasifica como riesgo SEO; no se creó ni modificó archivo.

## 34. Redirecciones pendientes

`/categorias/` y `/principal/` continúan en 404 y no tienen redirecciones. Un
microhito posterior debe dirigirlas al catálogo mediante la autoridad
`PublicRoute`, sin hardcodear destino ni preservar parámetros WC ciegamente.
Su ausencia no bloquea mientras no existan enlaces internos ordinarios.

## 35. Consola y red

Las rutas públicas y búsquedas respondieron 200; las únicas respuestas no 2xx
esperadas fueron checkout WC 302, páginas retiradas 404 y robots 404. Live
search respondió 200 sin errores REST. El fallo Chrome observado fue del
proceso GPU antes de cargar el harness, no un error JavaScript del sitio. No se
registraron 4xx/5xx originados siguiendo navegación canónica.

## 36. Código fuente y atributos

La inspección de `href`, `action`, contenido Elementor, shortcodes, formularios
y datos almacenados no encontró destinos WooCommerce en superficies canónicas.
La búsqueda en `app/`, `assets/` y `veciahorra.php` no encontró hardcodeos de
rutas WC ni llamadas constructoras alternativas. Los cinco valores efectivos
de `PublicRouteResolver` son Inicio, `/catalogo-veciahorra/`,
`/carrito-veciahorra/`, `/checkout/` y `/mis-compras/`.

El HTML carga assets/clases WooCommerce globales del tema/plugins y puede
contener selectores textuales de mini-cart; no existe un elemento renderizado
`.ct-header-cart`, trigger a `#woo-cart-panel` ni placement activo. Assets sin
enlace o componente visual no constituyen navegación accidental.

## 37. Pruebas

Pasaron 13 contratos ejecutados:

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
- `public-checkout-validation-test.php`
- `woocommerce-public-page-resolver-test.php`
- `customer-purchases-rest-test.php`

Observaciones:

- `customer-panel-purchases-list-test.php` no completó: Chrome headless terminó
  antes del harness por fallo GPU/archivo de caché. El mismo archivo contiene
  escenarios de lista **y detalle**, por lo que cubre conceptualmente el nombre
  solicitado `customer-panel-purchases-detail-test.php`; ese archivo separado
  no existe en el repositorio.
- `public-offer-selection-test.php` conserva la deuda conocida: falla porque un
  `product_id` inválido no devuelve el placeholder esperado. Catálogo, detalle
  público y fichas válidas pasaron; el fallo no abre una ruta WooCommerce.

No se modificó ninguna prueba.

## 38. Comparación histórica

| Etapa | Bloqueantes | Resultado |
|---|---:|---|
| Certificación inicial | Páginas oficiales WooCommerce visibles | No certificado |
| Primera corrección | IDs 143–146 excluidos | Corregido |
| Recertificación | IDs 177 y 356 visibles | No certificado |
| Saneamiento | IDs 177 y 356 a borrador | Corregido |
| Certificación final | 0 rutas accidentales comprobadas | Certificado con observaciones |

## 39. Bloqueantes

**Ninguno.** No se reprodujo navegación ordinaria hacia WooCommerce.

## 40. Hallazgos importantes

1. `public-offer-selection-test.php` mantiene una deuda funcional preexistente
   sobre el estado de `product_id` inválido. Requiere diagnóstico separado, no
   compromete el aislamiento comercial certificado.
2. La validación visual autenticada/responsive completa no pudo automatizarse
   por el fallo GPU de Chrome. Configuración, HTML, REST y contratos no muestran
   una fuga, pero conviene conservar una pasada visual humana de aceptación.

## 41. Hallazgos menores

1. El footer conserva el crédito externo `CreativeThemes`.
2. Existe un nodo de valores `cart` inactivo en Header Builder, sin placement
   ni renderizado.
3. No existe el archivo separado
   `customer-panel-purchases-detail-test.php`; los escenarios de detalle viven
   en el test combinado de lista.

## 42. Riesgos futuros

- Restaurar 177/356 a publicación reintroduciría búsqueda y sitemap.
- Reasignar `woof_widget-2`, `woocommerce_price_filter` o el nodo `cart` a una
  superficie general podría crear una exposición.
- Nuevas páginas comerciales heredadas no oficiales no quedarían cubiertas por
  el resolver de autoridades 143–146.
- El diseño del resolver híbrido se conserva como contingencia, sin necesidad
  actual de implementarlo.

## 43. Riesgos SEO

- Diez productos y seis categorías WooCommerce permanecen en sitemaps.
- Las cuatro páginas oficiales WC siguen en el sitemap de páginas.
- Productos, categorías y archivos comerciales continúan accesibles/indexables.
- `/robots.txt` responde 404.
- Faltan 301 para `/categorias/` y `/principal/` y no hay evidencia de
  backlinks/recrawl externo.

Estos riesgos no alteran el veredicto porque no producen enlaces internos
ordinarios.

## 44. Criterios aplicados

Se aplicó “Certificado con observaciones”: no hay rutas accidentales,
búsqueda tradicional/live están aisladas, menús y superficies usan rutas
canónicas y 177/356 están retiradas. Las deudas de prueba, visuales y SEO no
conducen al comercio heredado y se mantienen separadas.

## 45. Conclusión

La navegación pública final está funcionalmente aislada. Un visitante puede
recorrer Inicio → Catálogo → ficha → Carrito → Checkout y acceder a Mis compras
sin conocer URLs y sin ser derivado a WooCommerce. Las páginas WooCommerce solo
se alcanzan mediante acceso deliberado o descubrimiento externo/SEO.

## 46. Recomendación de cierre de la Serie 30

**La Serie 30 puede cerrarse funcionalmente respecto de navegación pública.**
Mantener fuera del cierre:

1. microhito de redirecciones 301 desde `/categorias/` y `/principal/` al
   catálogo resuelto;
2. serie SEO para sitemap, productos, categorías, noindex, canonical y robots;
3. diagnóstico independiente de `public-offer-selection-test.php` y mejora de
   ejecución headless del Customer Panel;
4. prevención futura: conservar el diseño híbrido sin implementarlo hasta que
   aparezca un consumidor real o nuevas páginas heredadas.
