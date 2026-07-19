# Recertificación final de navegación pública

## 1. Resumen ejecutivo

**Veredicto: No certificado.** El correctivo `1f272ae` cerró completamente las
dos fugas originales y excluye de búsqueda tradicional y live search las
cuatro páginas oficiales WooCommerce. Sin embargo, la segunda pasada encontró
dos rutas indirectas todavía accesibles desde el buscador público:

1. `Categorías` devuelve la página WordPress ID 177 (`/categorias/`), que
   contiene un enlace visible a `/categoria-producto/vecised/`.
2. `Principal` devuelve la página ID 356 (`/principal/`), cuyo shortcode
   `[products]` renderiza fichas y categorías WooCommerce.

Ambas páginas son navegación ordinaria: el usuario no necesita conocer ni
escribir una URL WooCommerce. No se modificó código, configuración ni datos.

## 2. Antecedente de la certificación anterior

`docs/public-navigation-certification.md` emitió **No certificado** porque
“Carrito” enlazaba ID 144 `/carrito/` y “Tienda” enlazaba ID 143 `/tienda/`.
También registró como importantes las páginas heredadas 177 y 356 y separó los
riesgos de sitemap/indexación.

## 3. Correctivo auditado

`WooCommercePublicPageResolver` obtiene shop, cart, checkout y myaccount con
`wc_get_page_id()`, o con opciones oficiales si la API no está disponible.
`PublicSearchIsolation` agrega esos IDs a `post__not_in` antes de ejecutar la
consulta, tanto en `pre_get_posts` como en `rest_post_search_query`, prioridad
1000. Product-only y REST sin marcador quedan fuera.

## 4. Commit y estado base

```text
1f272ae fix(frontend): hide legacy WooCommerce pages from public search
```

Rama `main`. Al comenzar solo `artifacts/` estaba sin seguimiento. Tema
Blocksy 2.1.44, portada ID 88, menú ID 3 y WooCommerce activo.

## 5. Objetivo

Determinar si, después del correctivo, un visitante puede entrar
accidentalmente en WooCommerce desde navegación ordinaria VeciAhorra, sin
considerar como defecto el acceso directo deliberado o la mera indexación.

## 6. Criterio de certificación

- **Navegación accidental:** enlace visible originado en una superficie
  VeciAhorra; impide certificar.
- **Acceso directo:** URL WooCommerce conocida; permitido.
- **SEO:** URL publicada en sitemap o buscador externo; riesgo separado.
- **Infraestructura:** REST, clases o assets sin enlace comercial visible; no
  constituye fuga.

## 7. Metodología

Primera pasada: ejecución de los once contratos manuales y revalidación HTTP y
REST de las cuatro autoridades oficiales. Segunda pasada: inventario vivo de
menús, placements, páginas, widgets y sitemap; búsqueda de patrones en código y
contenido; y búsquedas adicionales sobre páginas heredadas señaladas en la
certificación original.

La evidencia combina solicitudes HTTP reales, `rest_do_request`, API/options
WordPress, HTML renderizado y código. No se realizó pago. La interacción visual
de puntero, consola y foco no se automatizó; accesibilidad se comprobó por
contratos HTML, controles nativos y pruebas existentes.

## 8. Revalidación de bloqueantes

Los dos bloqueantes originales están **cerrados**:

| Término | Antes | Estado actual |
|---|---|---|
| Carrito | ID 144 `/carrito/` visible | ID 144 ausente; ID 698 VeciAhorra permanece |
| Tienda | ID 143 `/tienda/` visible | ID 143 ausente |

Checkout ID 145 y Mi cuenta ID 146 también están ausentes en ambos transportes.
La recertificación encontró dos bloqueantes distintos: IDs 177 y 356.

## 9. Búsqueda tradicional

Solicitud efectiva: `GET /Minimarket/?s={término}`. Resultados:

| Término | Página WC oficial | Resultado VeciAhorra/heredado |
|---|---|---|
| Carrito | ID 144 excluida | ID 698 disponible |
| Tienda | ID 143 excluida | sin enlace WC oficial |
| Finalizar compra | ID 145 excluida | sin enlace WC oficial |
| Mi cuenta | ID 146 excluida | sin enlace WC oficial |
| Categorías | no aplica | ID 177 visible → categoría WC |
| Principal | no aplica | ID 356 visible → productos WC |

Inicio, Catálogo, Carrito VeciAhorra, Checkout y Mis compras continúan
buscables por sus títulos. Conteo/paginación siguen a cargo de WordPress.

## 10. Live search

Transporte comprobado: `GET /wp/v2/search`, `type=post`, subtypes
`post,page,product` y `ct_live_search=true`.

- Las páginas 143, 144, 145 y 146 quedan excluidas.
- Carrito VeciAhorra ID 698 permanece disponible.
- `Categorías` responde HTTP 200 y devuelve exclusivamente ID 177.
- `Principal` responde HTTP 200 y devuelve exclusivamente ID 356.
- No hubo errores REST.

Los destinos 177/356 son páginas WordPress, por lo que no los cubre la
autoridad oficial de páginas WooCommerce implementada en `1f272ae`.

## 11. REST sin marcador

`/wp/v2/search` sin `ct_live_search=true` conserva su comportamiento original.
La prueba de integración confirma que una página comercial oficial puede
seguir apareciendo deliberadamente por ese transporte no Blocksy. No se
modificaron otros endpoints REST.

## 12. Product-only

Las formas `product` y arrays exclusivamente `product` quedan intactas,
incluidas exclusiones previas. La búsqueda explícita de producto y Store API
siguen operativas. No se aplica el aislamiento público general.

## 13. Menú y header

Ubicaciones `menu_1=3` y `menu_mobile=3`. Menú ID 3:

| Orden | Texto | Página | Destino |
|---:|---|---:|---|
| 1 | Inicio | 88 | `/` |
| 2 | Catálogo | 702 | `/catalogo-veciahorra/` |
| 3 | Carrito | 698 | `/carrito-veciahorra/` |
| 4 | Mis compras | 713 | `/mis-compras/` |
| 5 | Nosotros | 15 | `/nosotros/` |
| 6 | Contacto | 17 | `/contacto/` |

Desktop: `logo` y `menu,search`. Móvil: `logo,trigger`; offcanvas:
`mobile-menu`. Ningún placement activo contiene `cart`, cuenta o enlace WC.
No existe menú paralelo.

## 14. Inicio

Página ID 88, Elementor. Sus dos CTA son
`[veciahorra_public_route_link route="catalog" …]` y resuelven el catálogo
canónico. No contiene premium Woo Products, fichas WC ni URL heredada.

## 15. Catálogo

Página 702, `[veciahorra_frontend]`. Las pruebas de API y detalle pasan. Filtros,
orden, tarjetas, precios mínimos y estados usan contratos VeciAhorra. Las
tarjetas conducen a páginas públicas VeciAhorra y no presentan botones WC.

## 16. Fichas públicas

Páginas activas 700, 701 y 715. Seleccionan ofertas mediante `inventory_id`,
agregan a `/veciahorra/v1/cart/items` y resuelven el carrito canónico. No hay
destinos `/producto/`, `/shop/`, `product-category` o `product-tag`, ni
relacionados/breadcrumbs WooCommerce.

## 17. Carrito

Página 698, `[veciahorra_cart]`. Carga, cantidad, eliminación y limpieza usan
REST propio. Checkout se obtiene mediante `PublicRouteResolver`. Productos e
imágenes no enlazan fichas WC. `public-cart-test.php` pasó.

## 18. Checkout

Página 695, `[veciahorra_checkout]`. Resumen, entrega, RB-CHK-001, recuperación
y pago pertenecen al flujo propio. No se inició una transacción. No existe
enlace ordinario hacia el checkout WC ID 145.

## 19. Mis compras

Página 713, `/mis-compras/`, `[veciahorra_customer_panel]`. Visitante recibe
login con retorno canónico; autenticado usa listado/detalle y `?compra=` dentro
del panel. La prueba de infraestructura pasa y no se detectan Mi cuenta ni
pedidos WooCommerce.

## 20. Footer

No hay menú ni widgets comerciales de footer. El HTML conserva únicamente el
crédito `CreativeThemes`, sin destino WooCommerce. Es una observación de marca,
no una fuga comercial.

## 21. Widgets y placements

- `sidebar-1`: búsqueda, entradas, comentarios, archivos y categorías de blog.
- `sidebar-woocommerce`: `woof_widget-2`, activo condicionalmente en archivos
  WooCommerce y no montado en el flujo canónico.
- Sidebars de footer vacíos.
- Nodo de valores `cart` existente pero no colocado; no se renderiza.
- Elementor 177 y shortcode 356 sí están publicados y son el origen de las
  nuevas fugas comprobadas.

## 22. Breadcrumbs

No se renderizan breadcrumbs en Inicio, catálogo, fichas VeciAhorra, carrito,
checkout, Mis compras ni resultados. No hay una fuga por jerarquía “Tienda”.
Las páginas WooCommerce directas conservan sus propias jerarquías, fuera del
flujo canónico.

## 23. Enlaces internos

El código productivo no contiene URLs públicas WC hardcodeadas ni llamadas a
`wc_get_cart_url`, `wc_get_checkout_url` o `wc_get_page_permalink`. Las
coincidencias `/cart/` y `/checkout/` en JavaScript/README son endpoints REST
VeciAhorra, no rutas públicas WooCommerce.

Contenido almacenado relevante:

- 144: `[woocommerce_cart]`;
- 145: `[woocommerce_checkout]`;
- 146: `[woocommerce_my_account]`;
- 177: bloque Elementor que enlaza categorías de producto;
- 356: `[products limit="50" columns="5" paginate="true"]`.

Los tres primeros ya no reciben enlaces desde búsqueda general; los dos
últimos sí, y constituyen los bloqueantes actuales.

## 24. Páginas publicadas

| ID | Página | Autoridad/función | Estado en navegación |
|---:|---|---|---|
| 143 | Tienda | shop oficial WC | excluida de búsqueda |
| 144 | Carrito | cart oficial WC | excluida de búsqueda |
| 145 | Finalizar compra | checkout oficial WC | excluida de búsqueda |
| 146 | Mi cuenta | myaccount oficial WC | excluida de búsqueda |
| 177 | Categorías | Elementor heredado WC | buscable, bloqueante |
| 356 | Principal | listado `[products]` WC | buscable, bloqueante |
| 695 | Checkout VeciAhorra | checkout canónico | buscable |
| 698 | Carrito VeciAhorra | carrito canónico | menú/buscable |
| 700/701/715 | fichas VeciAhorra | detalle propio | publicadas |
| 702 | Catálogo VeciAhorra | catálogo canónico | menú/buscable |
| 713 | Mis compras | panel canónico | menú/buscable |

No aparecieron páginas paralelas nuevas desde la certificación anterior.

## 25. Acceso directo WooCommerce

Comportamiento preservado:

| Página | HTTP directo |
|---|---:|
| Tienda ID 143 | 200 |
| Carrito ID 144 | 200 |
| Checkout ID 145 | 302 a carrito con carrito vacío |
| Mi cuenta ID 146 | 200 |

Producto, categoría, product-only y Store API continúan disponibles. El acceso
directo no determina el veredicto.

## 26. Escritorio

El header desktop conserva logo, menú y modal de búsqueda. El submit
tradicional funciona mediante enlace/formulario real. Las rutas oficiales WC
no aparecen, pero los términos `Categorías` y `Principal` producen los dos
recorridos bloqueantes. No se capturó consola gráfica; REST respondió sin error.

## 27. Móvil

El mismo menú ID 3 se monta en `mobile-menu`; no hay search ni cart placement
móvil. Inicio → Catálogo → ficha → Carrito → Checkout permanece aislado. Las
fugas encontradas dependen del buscador desktop configurado. Apertura/foco del
offcanvas se verifican por estructura y contratos Blocksy, no mediante una
sesión GUI automatizada.

## 28. Visitante

Puede recorrer el flujo VeciAhorra y acceder al login de Mis compras. También
puede usar la búsqueda pública sin autenticación y alcanzar IDs 177/356, por lo
que existe fuga accidental para visitantes.

## 29. Usuario autenticado

Customer Panel utiliza rutas y endpoints propios; no enlaza Mi cuenta WC. La
búsqueda del header sigue disponible y mantiene las mismas fugas 177/356, por
lo que autenticarse no elimina el bloqueante.

## 30. Sitemap e indexación

Estado sin cambios:

- sitemap de productos: HTTP 200, 10 productos WooCommerce;
- sitemap `product_cat`: HTTP 200, 6 categorías WooCommerce;
- sitemap de páginas: HTTP 200, 16 páginas, incluidas 143–146, 177 y 356;
- `/robots.txt`: HTTP 404;
- sin plugin SEO activo ni noindex almacenado para estas páginas.

Son riesgos SEO separados. La presencia en sitemap no sería bloqueante por sí
sola; IDs 177/356 sí lo son porque además reciben enlaces internos del buscador.

## 31. Comparación antes/después

| Superficie | Antes de `1f272ae` | Después de `1f272ae` | Resultado |
|---|---|---|---|
| Búsqueda “Carrito” | WooCommerce visible | solo carrito VeciAhorra pertinente | corregido |
| Búsqueda “Tienda” | WooCommerce visible | página shop oficial ausente | corregido |
| Búsqueda “Finalizar compra” | página WC potencialmente visible | ID 145 ausente | corregido |
| Búsqueda “Mi cuenta” | página WC potencialmente visible | ID 146 ausente | corregido |
| Menú | canónico | canónico, sin cambios | correcto |
| Catálogo | VeciAhorra | VeciAhorra, pruebas pasan | correcto |
| Acceso directo WC | disponible | disponible, mismos HTTP | preservado |
| Sitemap | riesgo | mismo riesgo | pendiente SEO |
| Búsqueda “Categorías” | riesgo identificado | ID 177 enlazado | bloqueante actual |
| Búsqueda “Principal” | riesgo identificado | ID 356 enlazado | bloqueante actual |

## 32. Hallazgos clasificados

### Bloqueantes (2)

1. Búsqueda tradicional y live `Categorías` devuelven ID 177; su página enlaza
   una taxonomía WooCommerce.
2. Búsqueda tradicional y live `Principal` devuelven ID 356; su `[products]`
   enlaza fichas y categorías WooCommerce.

### Importantes (0)

No se encontraron exposiciones adicionales que ameriten esta categoría.

### Menores (1)

1. Footer conserva el crédito externo `CreativeThemes`.

### Riesgos futuros (2)

1. `woof_widget-2` permanece asignado al sidebar WooCommerce condicional.
2. El nodo inactivo `cart` podría reactivarse por una futura configuración.

### Riesgos SEO (4)

1. Diez productos WooCommerce en sitemap.
2. Seis categorías WooCommerce en sitemap.
3. Páginas 143–146, 177 y 356 en sitemap de páginas.
4. `/robots.txt` responde 404 y no define política diagnóstica.

## 33. Riesgo residual

El resolver oficial cubre correctamente solo páginas asignadas por
WooCommerce. IDs 177 y 356 son contenido WordPress heredado no asignado a una
función oficial, por lo que requieren una autoridad distinta y durable. Usar
títulos, slugs o IDs locales sería frágil. Hasta definir esa autoridad, el
buscador general sigue siendo una entrada accidental a WooCommerce.

## 34. Recomendaciones futuras

Crear un microhito correctivo independiente para definir cómo identificar y
excluir páginas heredadas no oficiales que renderizan navegación comercial WC.
Evaluar metadata/plantillas/shortcodes estructurados o una configuración
explícita administrable; no improvisar con IDs, slugs o títulos. Cubrir
tradicional y live search para 177/356 y preservar páginas canónicas.

En microhitos separados: decidir publicación/indexación de páginas heredadas,
productos y taxonomías; configurar sitemap/noindex/robots; mantener WOOF
confinado y vigilar placements de header.

## 35. Veredicto final

### No certificado

Los dos bloqueantes originales están corregidos, pero la respuesta verificable
a la pregunta de certificación continúa siendo **sí**: un usuario puede buscar
`Categorías` o `Principal`, seguir un resultado visible y entrar en categorías
o productos WooCommerce. La ruta es interna y ordinaria, no acceso directo ni
solo exposición SEO.
