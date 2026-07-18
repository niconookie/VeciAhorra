# Aislamiento de productos WooCommerce en la búsqueda pública

## 1. Resumen ejecutivo

La instalación usa Blocksy 2.1.44 y tiene activa una búsqueda modal en la
cabecera de escritorio. Esa superficie ofrece dos caminos distintos:

1. al enviar el formulario ejecuta la búsqueda tradicional de WordPress con
   `GET /?s=...&ct_post_type=post:page:product`;
2. mientras se escribe ejecuta una búsqueda live mediante
   `GET /wp-json/wp/v2/search`, marcada con `ct_live_search=true`.

Ambos caminos incluyen actualmente `product`. Una prueba con `Coca` devolvió
la página VeciAhorra ID 715 y los productos WooCommerce 561, 560 y 420, con
enlaces públicos hacia sus respectivas fichas. La exclusión debe ocurrir
antes de ejecutar cada consulta para conservar totales y paginación.

`pre_get_posts` es apropiado para la consulta principal tradicional, pero no
es autoridad suficiente para el endpoint live. La solución futura recomendada
es un componente pequeño del módulo `Frontend` con dos límites explícitos:

- `pre_get_posts` para la consulta principal de la búsqueda pública general;
- `rest_post_search_query` únicamente para peticiones del endpoint core de
  búsqueda marcadas por Blocksy como live search.

En ambos casos se eliminará `product` del `post_type` antes de consultar. Se
preservarán las búsquedas deliberadamente restringidas a `post_type=product`,
los archivos comerciales y todos los contextos administrativos, internos y
transaccionales.

Este documento describe una implementación futura. El microhito 30.1.4.2 no
incluye código productivo ni cambios de configuración.

## 2. Método y clasificación de la evidencia

Se usaron tres fuentes:

- **Código comprobado:** WordPress, Blocksy, Blocksy Companion y WooCommerce
  instalados localmente.
- **Ejecución comprobada:** configuración viva, HTML público, consulta
  principal instrumentada, registro de hooks y endpoint REST real.
- **Inferencia de diseño:** condiciones y estructura propuestas para una
  implementación posterior.

No se modificaron tema, plugins externos, opciones, páginas ni menús. La
instrumentación se ejecutó en procesos PHP temporales y no quedó instalada.

## 3. Estado actual

### 3.1 Instalación relevante

- Tema activo: Blocksy 2.1.44.
- Plugins activos relevantes: Blocksy Companion, WooCommerce,
  WooCommerce Products Filter (WOOF), Elementor y VeciAhorra.
- Portada estática: página ID 88.
- El Header Builder contiene `search` en
  `type-1 > desktop > middle-row > end`.
- La cabecera móvil contiene `trigger` y el offcanvas contiene solamente
  `mobile-menu`; no hay componente `search` en placements móviles.
- No se encontraron bloques, widgets, páginas Elementor ni sidebars públicos
  que añadan otro formulario de búsqueda.
- `sidebar-woocommerce` contiene `woof_widget-2`. Es un filtro explícito de la
  rama WooCommerce y queda fuera de la búsqueda pública general.

Por tanto, la única superficie de búsqueda general activa es el modal de la
cabecera de escritorio. La infraestructura del tema permite además formularios
tradicionales y el bloque `blocksy/search`, pero no están montados actualmente.

### 3.2 Formulario efectivo

El HTML público contiene un único `form.ct-search-form` con:

```text
method=GET
action=https://localhost/Minimarket/
input name=s
input name=ct_post_type value=post:page:product
data-live-results=thumbs
```

No solicita precio ni estado de stock en la configuración actual. El atributo
`thumbs` activa miniaturas en los resultados live.

## 4. Flujo de búsqueda tradicional

### 4.1 Construcción y envío

El componente de cabecera se construye en:

- `themes/blocksy/inc/panel-builder/header/search/view.php`: trigger visible;
- `themes/blocksy/inc/components/builder/header-elements.php`, método
  `render_search_modal()`: modal `#search-modal` y argumentos del formulario;
- `themes/blocksy/inc/helpers/search.php`, función
  `blocksy_get_search_post_type()`: normaliza los tipos habilitados;
- `themes/blocksy/searchform.php`: formulario final.

`render_search_modal()` usa por defecto:

```php
[
    'post' => true,
    'page' => true,
    'product' => true,
]
```

Como hay más de un tipo, `searchform.php` imprime `ct_post_type` con los tipos
separados por `:`. El envío normal es un GET tradicional; no depende de
JavaScript.

### 4.2 Consulta principal

WordPress interpreta `s` como búsqueda y construye la consulta principal.
Blocksy registra `Blocksy\SearchModifications::pre_get_posts()` desde
`themes/blocksy/inc/components/search.php`. Para una búsqueda pública lee
`ct_post_type`, valida `post`, `page` y `product`, y asigna el array a
`WP_Query::post_type`.

Instrumentación posterior a todos los callbacks con el término `Coca`:

| Propiedad | Valor efectivo |
|---|---|
| `WP_Query::is_search()` | `true` |
| propiedad `is_search` | `true` |
| `is_main_query()` | `true` |
| `post_type` | `['post', 'page', 'product']` |
| `post_status` | vacío; WP aplica estados públicos legibles |
| `suppress_filters` | falso/vacío |
| `is_admin()` | `false` |
| `wp_doing_ajax()` | `false` |
| `REST_REQUEST` | `false` |
| `posts_per_page` | 10 |

La consulta produjo cuatro resultados:

| ID | Tipo | Título | Destino |
|---:|---|---|---|
| 715 | `page` | Coca-Cola 350 cc | ficha VeciAhorra |
| 561 | `product` | Coca Cola 1.5 litros | ficha WooCommerce |
| 560 | `product` | Coca Cola 1litro | ficha WooCommerce |
| 420 | `product` | Coca-Cola lata | ficha WooCommerce |

La SQL usa `SQL_CALC_FOUND_ROWS`, busca en título, extracto y contenido y
limita a los tipos anteriores. Blocksy y WooCommerce agregan exclusiones de
`product_visibility` para productos excluidos de búsqueda.

### 4.3 Hooks efectivos

La instalación registró:

| Hook | Callback | Prioridad |
|---|---|---:|
| `pre_get_posts` | `WC_Query::pre_get_posts` | 10 |
| `pre_get_posts` | `Blocksy\SearchModifications::pre_get_posts` | 10 |
| `posts_where` | `ACF::posts_where` | 10 |
| `posts_where` | `WOOF_EXT_BY_TEXT::posts_where` | 20 |
| `posts_join` | `WOOF_EXT_BY_TEXT::posts_join` | 20 |
| `posts_clauses` | WooCommerce Product Filters `QueryClauses` | 10 |
| `posts_clauses` | WooCommerce Product Collection `QueryBuilder` | 10 |
| `request` | `_post_format_request` | 10 |

No hay callbacks en `posts_search`. Los filtros de WOOF están registrados
globalmente, pero su código condiciona cuándo altera SQL; no debe reutilizarse
como autoridad para la búsqueda general.

WordPress dispara `pre_get_posts` antes de construir SQL en
`wp-includes/class-wp-query.php`. Después atraviesa `posts_search`,
`posts_where`, `posts_join`, `posts_clauses`, `posts_request` y
`posts_results`. Intervenir en `post_type` durante `pre_get_posts` evita el
filtrado tardío y conserva `found_posts` y paginación.

### 4.4 Render de resultados

No existe `search.php` específico en Blocksy. La jerarquía llega a:

```text
themes/blocksy/index.php
→ themes/blocksy/archive.php
→ themes/blocksy/template-parts/archive.php
→ blocksy_render_archive_cards()
```

Blocksy presenta la consulta como un archivo de tarjetas y genera enlaces con
el permalink de cada objeto. Una página VeciAhorra lleva a su página pública;
un `product` lleva a `/producto/.../`. WooCommerce no sustituye esta búsqueda
mixta por `archive-product.php`: su `WC_Template_Loader` reserva esa plantilla
para archivo de producto, tienda, taxonomía o singular comercial explícito.

## 5. Flujo de búsqueda live (REST)

### 5.1 Estado y cliente

La búsqueda live está **activa**. No usa `admin-ajax.php`. El formulario lleva
`data-live-results="thumbs"` y el bundle dinámico de Blocksy
`themes/blocksy/static/bundle/662.*.js` escucha `input` y `focus`, aplica un
debounce de 300 ms y usa `fetch()`.

El cliente lee `post_type` o `ct_post_type`. Para el modal actual genera:

```text
GET /wp-json/wp/v2/search
ct_live_search=true
type=post
subtype[]=post
subtype[]=page
subtype[]=product
per_page=5
search={término}
```

Visitantes no necesitan nonce porque el endpoint es público. Para usuarios
autenticados, `searchform.php` añade un nonce `wp_rest` en
`.ct-live-results-nonce`, que el cliente envía como `X-WP-Nonce`.

### 5.2 Servidor y consulta

El endpoint es el controlador core `WP_REST_Search_Controller` y el handler
`WP_REST_Post_Search_Handler`, ubicado en:

```text
wp-includes/rest-api/search/class-wp-rest-post-search-handler.php
```

`search_items()` convierte `subtype` en `post_type`, fuerza
`post_status=publish`, establece página y `posts_per_page`, aplica
`rest_post_search_query` y crea una consulta secundaria `WP_Query`.

Blocksy registra en `Blocksy\SearchModifications`:

- `rest_api_init`: añade `ct_featured_media` solamente si
  `ct_live_search=true`;
- `rest_post_search_query` a prioridad 999: aplica visibilidad y stock de
  WooCommerce si `post_type` incluye `product`, además de la taxonomía
  solicitada.

`themes/blocksy/inc/components/woocommerce/common/rest-api.php` añade
`placeholder_image` para resultados `product`.

La petición real con `Coca` respondió HTTP 200, `X-WP-Total: 4` y devolvió la
página ID 715 seguida de los tres productos WooCommerce. Cada objeto contiene
`id`, `title`, `url`, `type`, `subtype` y campos visuales de Blocksy.

Si se activa precio o stock, el JavaScript toma los productos resultantes y
consulta adicionalmente `/wc/store/products?include=...`. En la configuración
actual esos metadatos están desactivados. Al excluir `product` antes de la
consulta tampoco existiría la segunda petición Store API.

### 5.3 Render live

El bundle crea:

```html
<div class="ct-search-results" role="listbox">
    <a class="ct-search-item" role="option" href="...">...</a>
</div>
```

Puede incluir miniatura y, si se configuran, precio y stock de productos. El
enlace “mostrar más” envía el formulario tradicional, por lo que ambos caminos
deben aplicar la misma semántica.

`pre_get_posts` no es cobertura suficiente: esta búsqueda nace del endpoint
REST y de una consulta secundaria. Aunque todo `WP_Query` dispara el hook, las
condiciones correctas para la búsqueda principal (`is_main_query`) deben
excluirla deliberadamente. Su punto estable es `rest_post_search_query`.

## 6. Interacción con WordPress

La búsqueda general tradicional usa la consulta principal y la semántica
normal de `s`. El endpoint live usa la API REST core de búsqueda. No existe un
endpoint privado de Blocksy ni una acción AJAX de búsqueda.

La futura implementación no debe usar el condicional global `is_search()`
como única fuente dentro de `pre_get_posts`; el método del objeto
`$query->is_search()` es verificable en el momento correcto. Tampoco debe
alterar globales `$_GET`, SQL ni resultados ya materializados.

Para un `post_type` vacío o `any`, WordPress considera los tipos buscables. Si
la intervención necesita materializar un array, deberá obtener dinámicamente
los tipos públicos no excluidos de búsqueda y retirar solamente `product`, sin
hardcodear el conjunto completo. Para un array existente, debe preservar orden
y valores y aplicar `array_diff(..., ['product'])`.

## 7. Interacción con Blocksy

La propuesta depende solo de hooks de WordPress. El marcador
`ct_live_search=true` se usa exclusivamente para reconocer el transporte live
de Blocksy; no se importa ninguna clase del tema ni se editan sus archivos.

Blocksy puede configurarse para no buscar productos en el modal mediante
`search_through.product=false`. Esa configuración sería útil como primera
línea visual, pero no cubre una URL tradicional `?s=...`, otros formularios
presentes o una futura reconfiguración. No debe ser la única garantía.

El hook REST se ejecuta en prioridad 1000, después de la prioridad 999 de
Blocksy, para transformar el `post_type` definitivo y evitar que el tema vuelva
a añadir `product`. La consulta tradicional usa también prioridad 1000, después
de que Blocksy procese `ct_post_type`. Ambas prioridades quedan expresadas como
constantes y verificadas contra Blocksy 2.1.44.

Una actualización del tema podría cambiar `ct_live_search`, el endpoint o las
prioridades. La matriz incluye pruebas de contrato que detecten esa deriva.

## 8. Interacción con WooCommerce

WooCommerce registra `WC_Query::pre_get_posts` y filtros de cláusulas. Blocksy
añade reglas de stock y visibilidad cuando detecta `product`. La futura
solución no llamará APIs WooCommerce ni cambiará el registro del post type.

No se modificarán:

- archivo de tienda o `is_post_type_archive('product')`;
- taxonomías `product_cat`, `product_tag` o atributos;
- productos relacionados, upsells o widgets;
- formulario `woocommerce-product-search`, que envía deliberadamente
  `post_type=product`;
- Store API, REST de productos, administración o consultas internas;
- carrito, checkout, órdenes, Webpay, cron o Action Scheduler.

Excluir `product` de una búsqueda general no despublica productos ni impide
acceder directamente a sus URLs. Ese tratamiento corresponde a otros
microhitos.

## 9. Objetos VeciAhorra actualmente indexables

VeciAhorra no registra un CPT público para fichas. El catálogo y las fichas son
páginas WordPress publicadas con `[veciahorra_frontend]`:

| ID | Título | Shortcode | Función |
|---:|---|---|---|
| 700 | Producto VeciAhorra Retiro E2E | `product_id=111` | ficha |
| 701 | Producto VeciAhorra Despacho E2E | `product_id=112` | ficha |
| 702 | Catálogo VeciAhorra | sin `product_id` | catálogo canónico |
| 715 | Coca-Cola 350 cc | `product_id=999999995` | ficha |

No se detectaron `product_id` duplicados entre estas páginas. WordPress indexa
el título y el contenido almacenado, no los datos cargados después mediante
REST. Por ello:

- el título humano de la página sí es buscable;
- el shortcode y su número pueden ser indexables como texto técnico;
- marcas, minimarkets u ofertas cargadas dinámicamente no son conocidas por la
  búsqueda estándar salvo que también aparezcan en título/contenido;
- cada resultado enlaza al permalink de la página VeciAhorra;
- la página 702 enlaza al catálogo resuelto por `PublicRouteResolver`;
- no hay duplicados actuales, aunque crear varias páginas para el mismo
  `product_id` podría producirlos en el futuro.

La exclusión de `post_type=product` preserva estas fichas porque son `page`.

## 10. Contextos excluidos de la intervención

### 10.1 Condiciones negativas tradicionales

El componente futuro debe retornar sin cambios si se cumple cualquiera:

1. `is_admin()`;
2. `wp_doing_ajax()`;
3. `REST_REQUEST` verdadero;
4. `DOING_CRON` verdadero;
5. `WP_CLI` verdadero;
6. la consulta no es `is_main_query()`;
7. `$query->is_search()` es falso;
8. la consulta solicita de forma exclusiva y deliberada `product`;
9. el contexto es archivo, taxonomía, tienda o singular WooCommerce en vez de
   búsqueda general.

La regla de solicitud deliberada debe normalizar el valor y considerar
exclusiva solamente la forma `product` o `['product']`, idealmente respaldada
por el parámetro explícito `post_type=product`. Un conjunto mixto enviado por
Blocksy (`post:page:product`) sigue siendo búsqueda general y debe perder
`product`.

### 10.2 Condiciones live REST

`rest_post_search_query` ya limita el hook al handler de búsqueda de posts. El
callback futuro debe retornar sin cambios salvo que:

- `$request->get_param('ct_live_search') === 'true'`;
- el tipo REST sea `post`;
- el conjunto sea general o mixto, no exclusivamente `product`.

Este callback es una excepción estrechamente delimitada dentro de REST porque
la UI pública usa REST como transporte. Todo otro endpoint y toda búsqueda REST
sin el marcador de Blocksy permanece intacta.

## 11. Alternativas evaluadas

| Estrategia | Cobertura y ventajas | Riesgos y decisión |
|---|---|---|
| `pre_get_posts` en consulta principal | Excluye antes de SQL; conserva totales, orden y paginación; fácil de probar | No cubre por sí solo live REST. Recomendada para tradicional con guards estrictos |
| Cambiar `post_type` | Intervención semántica mínima; sin consultas por resultado | Debe preservar tipos existentes y búsquedas product-only. Mecanismo recomendado |
| `post__not_in` | Puede excluir IDs concretos | Requiere obtener todos los productos, escala mal y acopla datos. Rechazada |
| Filtros SQL (`posts_where`, `posts_clauses`) | Podrían cubrir consultas variadas | Frágiles, difíciles de aislar y probar; riesgo con WOOF/WC y SQL futuro. Rechazada |
| Filtrado posterior | Simple visualmente | Rompe conteos, páginas y “mostrar más”; consulta productos innecesariamente. Rechazada |
| Modificar formularios | Evita que la UI activa solicite productos | No protege URLs manuales, otros formularios ni futuras configuraciones. Solo defensa complementaria |
| Hooks específicos de Blocksy | Acceso cercano a la UI | Mayor dependencia del tema; no hay un filtro único que cubra ambos flujos. No como autoridad |
| Tradicional + REST separados | Cobertura exacta de los dos caminos comprobados | Dos callbacks, pero ambos sobre hooks core y con pruebas claras. Recomendada |
| Configurar Blocksy sin productos | Evita productos y cláusulas Woo en el modal activo | Configuración mutable y cobertura incompleta de `?s=`. Complementaria, no suficiente |
| Sustituir por buscador VeciAhorra | Control total | Duplica infraestructura, requiere UI/API/indexación nueva. Fuera de alcance |

La modificación previa a consulta mantiene rendimiento y paginación. El coste
añadido es constante: inspección de contexto y normalización de un array.

## 12. Solución recomendada

Crear en un microhito posterior un componente, por ejemplo
`Frontend\Search\PublicSearchIsolation`, registrado por `FrontendModule`.
No debe residir en catálogo, carrito, checkout ni pagos.

Contrato conceptual:

```php
final class PublicSearchIsolation
{
    public function register(): void;
    public function filterMainSearch(WP_Query $query): void;
    public function filterLiveSearch(array $args, WP_REST_Request $request): array;
}
```

`filterMainSearch()`:

1. aplica todas las condiciones negativas de 10.1;
2. obtiene el `post_type` efectivo después de Blocksy;
3. conserva una solicitud exclusivamente comercial;
4. elimina `product` del conjunto general antes de SQL;
5. no toca `post__not_in`, resultados ni HTML.

`filterLiveSearch()`:

1. valida `ct_live_search=true` y el handler de posts;
2. conserva una solicitud exclusivamente `product`;
3. elimina `product` de `$args['post_type']` antes de que el handler cree
   `WP_Query`;
4. no cambia ninguna otra petición REST.

REST y la búsqueda tradicional usan prioridad 1000, posterior a los callbacks
de Blocksy que materializan el conjunto de tipos. Las prioridades son
constantes documentadas y verificadas por prueba de integración.

No se deben hardcodear URLs. `PublicRouteResolver` no participa porque se
filtran tipos consultados, no se construyen destinos.

## 13. Semántica exacta de “excluir product”

La regla elimina `product` del conjunto consultado por la búsqueda pública
general. Conserva entradas, páginas y demás tipos ya permitidos. En particular:

- conserva catálogo y fichas VeciAhorra porque son páginas;
- impide resultados individuales WooCommerce y enlaces `/producto/.../`;
- no cambia archivos de tienda, categorías, etiquetas o atributos;
- no cambia productos relacionados, bloques, widgets ni consultas secundarias;
- permite búsquedas explícitas de producto dentro de la rama WooCommerce;
- no despublica ni redirige productos;
- no convierte el catálogo VeciAhorra en un motor de búsqueda nuevo.

## 14. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Cambio del endpoint o marcador de Blocksy | Prueba contractual del HTML y petición live; revisar en upgrades |
| Prioridad de hooks del tema | Constantes explícitas y prueba que inspeccione `post_type` antes de SQL |
| Argumentos Woo añadidos antes de retirar `product` | Verificar la consulta REST resultante y considerar `search_through.product=false` como defensa adicional si una actualización de Blocksy altera resultados no comerciales |
| Confundir búsqueda general con product-only | Normalización estricta y casos separados en pruebas |
| Incluir consultas secundarias | Exigir `is_main_query()` en tradicional; usar hook REST específico para live |
| Fichas VeciAhorra poco indexables | Mantener páginas; mejorar títulos/contenido en otro microhito, no en este filtro |
| Duplicados futuros de fichas | Auditoría/autoridad de página por `product_id` separada |
| Tipos públicos nuevos | Derivar el conjunto efectivo; retirar solo `product` |
| WOOF y filtros WC registrados | No usar ni modificar sus hooks; pruebas de tienda y SQL tradicional |

## 15. Matriz de pruebas futuras

### 15.1 Búsqueda pública tradicional

| Caso | Resultado esperado |
|---|---|
| Término que coincide solo con página normal | Página visible |
| Coincidencia con página/ficha VeciAhorra | Página visible y URL VeciAhorra |
| Coincidencia solo con producto WooCommerce | Sin resultados generales |
| Coincidencia simultánea | Solo contenido no `product` |
| Sin coincidencias | Estado vacío normal de Blocksy |
| Más de una página de resultados | Totales y paginación coherentes; ningún producto |
| GET directo `?s=...` | Misma exclusión |
| Formulario desktop | Misma exclusión |
| Usuario visitante/autenticado | Misma semántica |

### 15.2 Live REST

| Caso | Resultado esperado |
|---|---|
| `ct_live_search=true` mixto | Sin subtype `product` |
| Resultado VeciAhorra | URL de página canónica |
| Producto únicamente coincidente | Lista vacía |
| Coincidencia mixta | Solo página/entrada |
| Paginación/per_page | `X-WP-Total` y cantidad correctos |
| Sin marcador live | REST core intacto |
| `subtype=product` deliberado | Preservado según contrato autorizado |
| Visitante | Funciona sin nonce |
| Autenticado | Funciona con nonce REST |
| Precio/stock activados | No se solicita Store API si no hay productos |

### 15.3 Superficies y accesibilidad

- modal desktop;
- “mostrar más” hacia resultados tradicionales;
- teclado, foco, Escape y anuncios `aria-live`;
- tablet y móvil si el componente search se activa posteriormente;
- bloque `blocksy/search` si llega a publicarse;
- enlaces finales de cada resultado.

### 15.4 Contextos que deben permanecer intactos

- `/wp-admin/edit.php?post_type=product` y búsqueda administrativa;
- editor de producto;
- REST de posts/productos y Store API sin marcador live;
- cron, Action Scheduler y WP-CLI;
- tienda, archivo `product`, categoría, etiqueta y atributo;
- producto singular directo;
- widget WOOF en sidebar WooCommerce;
- productos relacionados, bloques y consultas internas;
- carrito y checkout WooCommerce;
- órdenes, gateway Webpay y conciliación;
- consultas internas VeciAhorra.

### 15.5 Invariantes técnicas

- ningún resultado general tiene `post_type=product`;
- ningún resultado general enlaza a una ficha WooCommerce;
- exclusión anterior a SQL;
- conteos y paginación sin filtrado tardío;
- cero consultas adicionales por resultado;
- consultas secundarias ajenas intactas;
- cero URLs hardcodeadas;
- catálogo ID 702 y `PublicRouteResolver::catalog()` intactos.

## 16. Alcance negativo

La solución futura no debe:

- modificar Blocksy, Blocksy Companion o WooCommerce;
- reemplazar el buscador ni crear un endpoint propio;
- modificar HTML, CSS o JavaScript;
- alterar productos, páginas, menús, cabecera o Elementor;
- aplicar redirecciones, `noindex` o políticas SEO;
- ocultar productos mediante CSS;
- filtrar resultados después de obtenerlos;
- intervenir en módulos transaccionales;
- cambiar el catálogo VeciAhorra o sus rutas.

## 17. Preguntas y bloqueos pendientes

No hay bloqueos para una implementación posterior. Deben confirmarse como
parte de sus pruebas, no como decisiones abiertas:

1. prioridad final frente a Blocksy 2.1.44;
2. SQL resultante sin cláusulas residuales innecesarias de visibilidad;
3. comportamiento product-only autorizado;
4. contrato live tras futuras actualizaciones del tema.
