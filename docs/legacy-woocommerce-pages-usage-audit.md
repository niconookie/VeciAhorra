# Auditoría de uso y saneamiento de páginas WooCommerce heredadas

## 1. Resumen ejecutivo

Las páginas 177 y 356 pueden pasar a borrador sin afectar el funcionamiento
vigente de VeciAhorra, WordPress, WooCommerce, Blocksy o Elementor. Ninguna es
autoridad oficial, ruta canónica, portada, página de entradas, menú, widget,
plantilla reutilizada ni dependencia de código. Los únicos descubrimientos
públicos actuales son la búsqueda interna y el sitemap.

Clasificación individual: **Retiro seguro con redirección posterior** para
ambas. El borrador es reversible y conserva contenido, metadatos y revisiones.
Como el entorno es `localhost` y no existen Analytics/Search Console ni
telemetría externa, backlinks y tráfico real de producción son **no
verificables**; una redirección posterior al catálogo reduce ese riesgo.

## 2. Contexto

La recertificación cerró los accesos por páginas oficiales 143–146, pero
encontró ID 177 (categorías Elementor) e ID 356 (listado `[products]`). El
diseño híbrido posterior propuso clasificación de contenido. Esta auditoría
determina si esa complejidad es necesaria antes de sanear operacionalmente las
dos únicas páginas heredadas detectadas.

## 3. Objetivo

Identificar dependencias, referencias, tráfico disponible, contenido a
preservar y riesgo de retirar 177/356, sin modificar estado, contenido,
configuración, código o SEO.

## 4. Pregunta principal

**Sí.** Ambas pueden retirarse de publicación sin afectar funciones vigentes
comprobadas. La incertidumbre se limita a tráfico/backlinks externos no
observables en un host local; no existe incertidumbre técnica interna.

## 5. Metodología

Se consultaron APIs WordPress/WooCommerce, contenido y metadata, revisiones,
menús, post types reutilizables, sidebars, theme mods, cron, opciones y tablas
de solo lectura. Se inspeccionó código con búsqueda contextual, HTML/sitemap
HTTP real, logs Apache disponibles y ocho pruebas funcionales. Coincidencias
numéricas, revisiones, cachés y texto genérico se clasificaron por contexto.

## 6. Limitaciones

- El sitio usa `https://localhost/Minimarket/`; no representa un dominio
  públicamente resoluble.
- No hay Google Analytics, Search Console, Jetpack Statistics ni plugin SEO.
- Los logs locales no prueban tráfico de otro entorno o periodo no retenido.
- No se dispone de un índice comercial de backlinks.
- No se ejecutó pago real ni se modificó estado para observar el 404 posterior.

Por ello no se afirma ausencia global de tráfico o backlinks.

## 7. Estado de ID 177

| Campo | Valor efectivo |
|---|---|
| ID/título/slug | 177 / Categorías / `categorias` |
| URL | `https://localhost/Minimarket/categorias/` |
| Tipo/estado | `page` / `publish` |
| Creada | 2026-06-16 10:26:05 |
| Modificada | 2026-06-22 23:33:32 |
| Autor | usuario 1 |
| Plantilla/padre/orden | default / 0 / 0 |
| Contraseña/visibilidad | vacía / pública |
| Extracto | vacío |
| HTTP/meta robots | 200 / `max-image-preview:large` |
| Canonical | no detectado |
| Sitemap lastmod | `2026-06-22T23:33:32-03:00` |
| Revisiones | 16 |
| Imagen destacada | ninguna |

Elementor 4.1.2, modo builder, widget único
`premium-woo-categories`, layout even, hasta 15 categorías. No hay shortcode
ni bloque Gutenberg. El `post_content` contiene un snapshot generado con seis
categorías; la salida viva es producida dinámicamente desde `product_cat`.

Imágenes propias visibles: attachments 573, 574, 576, 487, 580 y 581
(VeciSed, Despensa, VeciAseo, Panadería, Bebidas y Agua Mineral), todos sin
parent. El logo 314 pertenece a Inicio/header y no a esta página.

## 8. Estado de ID 356

| Campo | Valor efectivo |
|---|---|
| ID/título/slug | 356 / Principal / `principal` |
| URL | `https://localhost/Minimarket/principal/` |
| Tipo/estado | `page` / `publish` |
| Creada | 2026-06-17 21:42:44 |
| Modificada | 2026-06-21 22:04:13 |
| Autor | usuario 1 |
| Plantilla/padre/orden | default / 0 / 0 |
| Contraseña/visibilidad | vacía / pública |
| Extracto | vacío |
| HTTP/meta robots | 200 / `max-image-preview:large` |
| Canonical | no detectado |
| Sitemap lastmod | `2026-06-21T22:04:13-03:00` |
| Revisiones | 50 |
| Imagen destacada | ninguna |

Elementor usa un widget HTML con formulario GET `post_type=product` y widgets
shortcode, uno con `[products limit="50" columns="5" paginate="true"]`.
WooCommerce genera diez productos y categorías asociadas (31 enlaces
comerciales). Las once imágenes de producto detectadas pertenecen a sus
productos WooCommerce, no a la página; no deben duplicarse para preservarla.

## 9. Autoridad WooCommerce

Valores efectivos:

```text
shop=143, cart=144, checkout=145, myaccount=146, terms=-1
```

Ni 177 ni 356 aparece en `wc_get_page_id()` ni en las opciones oficiales.
Pasarlas a borrador no cambia 143–146, endpoints, Store API, carrito, checkout,
cuenta, shop ni product-only. Son páginas editoriales independientes.

## 10. Dependencias VeciAhorra

`PublicRouteResolver` resuelve Inicio 88, Catálogo 702, Carrito 698, Checkout
695 y Mis compras 713. No conoce 177/356. No se hallaron referencias
productivas en `app/`, `assets/`, `tests/`, `veciahorra.php` o `composer.json`.
Las únicas menciones válidas están en documentos de auditoría/diseño. Números
coincidentes en hashes/pruebas se descartaron como falsos positivos.

Ningún shortcode, JavaScript, inline config, fallback, retorno Webpay, login,
Customer Panel, catálogo, ficha, carrito o checkout enlaza estas páginas.

## 11. Menús

Se revisaron todos los menús. No hay elemento post_type ni enlace personalizado
a `/categorias/` o `/principal/`, activo o inactivo. Menú ID 3 está asignado a
`menu_1` y `menu_mobile` y contiene solo Inicio, Catálogo, Carrito VeciAhorra,
Mis compras, Nosotros y Contacto. Footer/offcanvas no añaden referencias.

## 12. Elementor

Los datos Elementor de 177/356 son locales a cada página. Solo existe una
plantilla `elementor_library`: ID 7 “Kit por defecto”, sin widgets ni
referencias. No hay Theme Builder, popup, header/footer/archive/single template,
widget global o sección reutilizable que incluya las páginas. Tampoco hay
condiciones de visualización dependientes de sus IDs/slugs.

## 13. Blocksy

No existen `ct_content_block`, hooks condicionales o plantillas Blocksy. Theme
mods no contienen `categorias` ni `principal`. Header placements son logo,
menu/search desktop y trigger/mobile-menu móvil; footer sin widgets comerciales.
Coincidencias históricas en `custom_css`/changesets usan términos genéricos y no
constituyen dependencia de URL o ID.

## 14. Widgets

`sidebar-1` contiene búsqueda/blog; footer sidebars están vacíos.
`sidebar-woocommerce` contiene `woof_widget-2`, restringido por Blocksy al
archivo WooCommerce y sin referencia a 177/356. No hay widgets inactivos ni HTML
personalizado que enlace las páginas. Los widgets Elementor viven solo dentro
de sus respectivas páginas.

## 15. Contenido interno

La búsqueda estructurada cubrió pages, posts, products, revisions, comments,
term descriptions, reusable blocks, templates y Elementor Library. No existe
enlace persistente vigente desde otro contenido. ID 177 contiene enlaces
salientes a seis categorías WC; ID 356 genera enlaces salientes dinámicos.

Cinco revisiones antiguas de Inicio (395, 484–486 y 566, entre 19 y 22 de junio)
contienen `/principal/`. La versión publicada actual de Inicio ya no lo hace;
son historial recuperable, no navegación ni dependencia.

## 16. Base de datos y opciones

Coincidencias reales actuales en `wp_posts/wp_postmeta` corresponden a las dos
páginas y sus revisiones/metadata Elementor. No hay opción WooCommerce,
VeciAhorra, menú o Blocksy que almacene sus IDs/URLs. `widget_block` contiene la
palabra genérica categorías; `ptk_patterns` y transients contienen “principal”
en otro contexto. Changesets/custom CSS y revisiones son históricos.

No hay referencia en comentarios, term descriptions, cron ni theme mods. No se
justificó consultar tablas VeciAhorra por IDs numéricos sin relación semántica;
código/rutas prueban que no son autoridades del dominio.

## 17. Enlaces internos a ID 177

| Origen | Tipo | Estado |
|---|---|---|
| búsqueda tradicional “Categorías” | resultado dinámico | activo, visitante/autenticado desktop |
| live search “Categorías” | resultado REST Blocksy | activo desktop |
| sitemap core de páginas | descubrimiento SEO | activo |

No existen enlaces almacenados desde páginas, menús, widgets o templates. La
página misma enlaza seis categorías WooCommerce; no es enlace entrante.

## 18. Enlaces internos a ID 356

| Origen | Tipo | Estado |
|---|---|---|
| búsqueda tradicional “Principal” | resultado dinámico | activo, visitante/autenticado desktop |
| live search “Principal” | resultado REST Blocksy | activo desktop |
| sitemap core de páginas | descubrimiento SEO | activo |
| revisiones antiguas de Inicio | enlace histórico | inactivo/no renderizado |

No existe enlace persistente en contenido publicado actual, menú o widget.

## 19. Tráfico de ID 177

Apache `access.log` cubre del 21-10-2025 al 18-07-2026. Hay nueve solicitudes
200 a `/Minimarket/categorias/`, todas desde loopback `::1` el 18-07-2026,
coincidentes con auditorías locales. No se comprobó tráfico externo en este log.
El formato de estas líneas no aporta referer/usuario útil. Tráfico de otro
servidor o dominio: **no verificable**.

## 20. Tráfico de ID 356

Hay nueve solicitudes 200 a `/Minimarket/principal/`, también exclusivamente
desde `::1` el 18-07-2026 y coherentes con pruebas locales. No hay tráfico
externo comprobado en el log disponible. Tráfico real de producción, origen y
última visita externa: **no verificable**.

## 21. Backlinks

No hay Search Console, Analytics, Jetpack, plugin SEO ni base de backlinks. El
host `localhost` no es públicamente resoluble como identidad web global, por lo
que una búsqueda externa por estas URLs no aporta evidencia transferible. Las
revisiones antiguas prueban un enlace interno histórico a 356, no un backlink.
Conclusión para ambas: **no verificable**; no se infiere ausencia.

## 22. Sitemap

Ambas aparecen en `https://localhost/Minimarket/wp-sitemap-posts-page-1.xml`
HTTP 200 con los lastmod indicados. Pasar a borrador las retiraría del sitemap
core. Mantenerlas publicadas conserva descubrimiento; private/draft las excluye.
Papelera/eliminación también, con menor reversibilidad.

## 23. Indexación

Son publicadas, sin contraseña, robots permisivo y sin canonical detectado; por
ello son aparentemente indexables. No hay plugin SEO/noindex. Un borrador deja
de ser públicamente consultable y eventualmente debe desaparecer del índice,
pero puede causar 404 para URLs conocidas. `noindex` mantendría la fuga interna
y no sustituye el retiro. Una redirección 301 posterior consolida señales.

## 24. Dependencias indirectas

No participan en redirects de login/logout, mails, webhooks, automatizaciones,
cron, Action Scheduler, REST propio, precargas, caché de negocio ni pruebas E2E.
LiteSpeed puede tener caché derivada, no autoridad; debería purgarse al cambiar
estado en el microhito operativo. Duplicator es respaldo, no dependencia.
Documentos actuales las mencionan solo como evidencia/plan.

## 25. Configuración de portada

```text
show_on_front=page
page_on_front=88
page_for_posts=0
```

ID 356 no es portada pese a llamarse “Principal”; ID 177 tampoco tiene
asignación de lectura. Ambas tienen parent/order 0 sin función especial.

## 26. Flujos funcionales

Pasaron ocho pruebas: rutas públicas, API/detalle de catálogo, agregar al
carrito, carrito, checkout, Customer Panel y exclusión de páginas oficiales en
búsqueda. El recorrido canónico no usa 177/356. WooCommerce directo usa
143–146/product archives y tampoco depende de ellas. No se inició pago.

## 27. Contenido a preservar

Draft conserva `post_content`, `_elementor_data`, metadata y 16/50 revisiones;
no hace falta duplicar ni exportar para una transición reversible. Antes del
cambio conviene registrar una captura opcional y los seis assets de 177 si el
diseño pudiera reutilizarse. Los assets de 356 pertenecen a productos y no se
eliminan al cambiar la página. No se recomienda backup adicional más allá del
sistema normal y revisiones; tampoco eliminar attachments.

## 28. Opciones para ID 177

- **Mantener publicada:** conserva URL/diseño, pero mantiene fuga y sitemap; sin
  función vigente que lo justifique.
- **Borrador:** recomendado; reversible, elimina búsqueda/sitemap y deja 404 al
  acceso directo hasta redirección.
- **Privada:** sin ventaja frente a borrador; puede crear UX de autorización.
- **Papelera/eliminación:** prematuros; reducen reversibilidad.
- **301 futura:** al catálogo VeciAhorra, después de ponerla en borrador y
  verificar caché; no preservar rutas `product_cat` como destino.
- **Noindex temporal:** solo mitigación SEO; no cierra búsqueda interna.

## 29. Opciones para ID 356

- **Mantener publicada:** mantiene buscador/listado WC alternativo y fuga; no
  hay dependencia vigente.
- **Borrador:** recomendado; conserva formulario/shortcode/revisiones y elimina
  búsqueda/sitemap.
- **Privada:** no ofrece beneficio operativo.
- **Papelera/eliminación:** prematuros.
- **301 futura:** al catálogo VeciAhorra. No trasladar ciegamente
  `post_type=product`; mapear solo términos soportados o descartar parámetros WC.
- **Noindex temporal:** insuficiente para navegación interna.

## 30. Criterio de retiro seguro

Ambas cumplen: no oficiales WC, no rutas VeciAhorra, no portada, no menú, no
reutilización Elementor/Blocksy, sin enlaces persistentes vigentes, sin flujo o
dependencia técnica y contenido exclusivamente heredado. El tráfico externo es
no verificable pero gestionable con 301 posterior. Draft es reversible y
preserva todo el contenido.

## 31. Clasificación de ID 177

### Retiro seguro con redirección posterior

Puede pasar a borrador sin afectar funciones. Riesgo principal: URL ya incluida
en sitemap y posibles backlinks no observables. No necesita migración funcional;
solo decidir si las seis imágenes/diseño merecen documentación antes del cambio.

## 32. Clasificación de ID 356

### Retiro seguro con redirección posterior

Puede pasar a borrador sin afectar funciones. Las revisiones antiguas de Inicio
son históricas y no requieren migración. El formulario/listado no debe
preservarse en navegación pública; draft conserva su implementación por si se
necesita revisar.

## 33. Redirecciones futuras

Crear en un microhito independiente redirecciones 301 desde ambas URLs hacia
`PublicRouteResolver::catalog()`, después de cambiar estado y antes de una
ventana prolongada de 404. Preservar query strings solo si se mapean a filtros
canónicos válidos; retirar `post_type=product` y parámetros WooCommerce. Evitar
loops y no depender de slugs sin una autoridad de compatibilidad explícita.

## 34. Impacto SEO

Draft las retira del sitemap y evita nueva indexación, pero URLs ya conocidas
pueden quedar como 404 hasta recrawl. Una 301 consolida mejor señales y cubre
backlinks desconocidos. Productos/categorías WC y páginas 143–146 permanecen en
sitemaps: son deuda SEO separada. No usar robots como sustituto.

## 35. Necesidad del resolver híbrido

### Mantener diseñado, no implementar

El inventario completo encontró únicamente 177/356 y ambas pueden sanearse. No
hay página mixta que deba permanecer publicada pero oculta de búsqueda, ni un
patrón recurrente actual. Implementar índice, invalidación y adapters ahora
añadiría complejidad sin consumidor. Conservar el diseño como contingencia si
surgen nuevas páginas o una heredada debe mantenerse publicada.

## 36. Riesgos

- Backlinks/tráfico externo no verificables.
- Periodo de 404 si borrador y redirección no se coordinan.
- Caché LiteSpeed conservando HTML temporalmente.
- Eliminación accidental de attachments al confundir retiro de página con
  limpieza de medios.
- Restauración futura que reintroduzca búsqueda/sitemap.
- Redirección que preserve parámetros WC incompatibles.

## 37. Recomendaciones

1. Aprobar individualmente borrador para 177 y 356.
2. Registrar antes/después: estado, URL, sitemap, búsqueda y caché.
3. No borrar páginas, revisiones ni attachments.
4. Purgar caché mediante mecanismo oficial tras el cambio.
5. Implementar 301 al catálogo en microhito separado y probar query strings.
6. Recertificar búsqueda tradicional/live y acceso directo.
7. Tratar productos/categorías/sitemap en la serie SEO.

## 38. Plan futuro de saneamiento

- Microhito A: snapshot de solo lectura, aprobación y cambio de ambas a draft
  mediante API WordPress; purga de caché; verificación funcional/sitemap.
- Microhito B: redirecciones 301 compatibles con autoridad de rutas y pruebas.
- Microhito C: recertificación final.
- Serie SEO: noindex/sitemap/canonical/robots para rama WC según decisión.
- Activar el resolver híbrido solo si el inventario futuro encuentra contenido
  que debe permanecer publicado.

## 39. Alcance negativo

No se cambió estado, privacidad, papelera, contenido, Elementor, attachments,
menús, widgets, Blocksy, WooCommerce, VeciAhorra, pruebas, sitemap, noindex,
robots, caché ni redirecciones. No se creó backup, staging, commit o push.

## 40. Conclusión

La evidencia interna es suficiente para retirar ambas páginas sin regresión
funcional. La prudencia requerida no es técnica sino de continuidad de URL:
tráfico/backlinks externos son no verificables. Por eso se recomienda draft
reversible con redirección posterior al catálogo. El resolver híbrido queda
diseñado como contingencia, pero no es necesario actualmente.
