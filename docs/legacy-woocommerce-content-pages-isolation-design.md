# Diseño de aislamiento de páginas heredadas con contenido WooCommerce

## 1. Resumen ejecutivo

La recomendación principal es una **solución híbrida**: detección automática
únicamente de contenido estructurado inequívoco que ocupa la finalidad completa
de una página, complementada por una declaración explícita y portable en
metadatos de página para widgets de terceros o casos ambiguos. La resolución se
haría fuera de la ruta crítica de búsqueda, con índice durable y memoización
por request; nunca mediante HTML renderizado, IDs, títulos, slugs o URLs.

ID 177 es una superficie comercial exclusiva generada por el widget Elementor
`premium-woo-categories`. ID 356 es una superficie comercial exclusiva: combina
un formulario `post_type=product` y el shortcode oficial WooCommerce
`[products limit="50" columns="5" paginate="true"]`. Ambas deben excluirse de
búsqueda. Ninguna es página oficial requerida por WooCommerce.

## 2. Antecedentes

La certificación original detectó páginas oficiales WooCommerce en búsqueda.
El commit `1f272ae` resolvió shop, cart, checkout y myaccount mediante
`WooCommercePublicPageResolver`. La recertificación confirmó el correctivo,
pero encontró IDs 177 y 356, páginas ordinarias no asignadas oficialmente que
actúan como entradas al comercio heredado.

## 3. Problema

La autoridad oficial cubre funciones WooCommerce, no contenido creado por
Elementor, shortcodes, bloques o enlaces. Excluir toda página que mencione un
shortcode/enlace comercial produciría falsos positivos; inspeccionar HTML en
cada búsqueda sería costoso, dependiente del tema y tardío.

## 4. Objetivo

Definir cómo identificar páginas heredadas cuyo propósito principal sea
comercial WooCommerce, sin acoplarse a datos locales ni excluir silenciosamente
contenido informativo o canónico VeciAhorra.

## 5. Alcance

Se auditaron todas las páginas WordPress en cualquier estado, shortcodes y
bloques registrados, contenido y metadatos Elementor, menús, sidebars, HTML
renderizado y sitemap. Esta etapa no implementa detectores, caché, UI,
metadatos, redirecciones ni cambios editoriales.

## 6. Estado del aislamiento existente

`PublicSearchIsolation` usa `pre_get_posts` y `rest_post_search_query`, ambos a
prioridad 1000. `WooCommercePublicPageResolver` resuelve cuatro páginas
oficiales y la política combina sus IDs con `post__not_in`. La intervención se
limita a búsqueda pública general; product-only y contextos negativos quedan
intactos. Esta autoridad no debe recibir heurísticas de contenido.

## 7. Auditoría de la página 177

| Campo | Evidencia efectiva |
|---|---|
| Tipo/estado | `page`, `publish` |
| Título/slug | `Categorías`, `categorias` (descriptivos, no autoridad) |
| Autor | usuario ID 1 |
| Plantilla | `default` |
| Editor | Elementor 4.1.2, modo `builder` |
| Widget | `premium-woo-categories` |
| Configuración | layout `even`, hasta 15 categorías, columnas 16.667% |
| Shortcodes/bloques | ninguno; no hay bloques Gutenberg |
| Menús/widgets externos | ninguna referencia de menú; no es sidebar |
| HTTP/robots | 200; `max-image-preview:large` |
| Canonical | no se detectó `<link rel="canonical">` |
| Sitemap | incluida en sitemap core de páginas |

`_pa_widget_elements` declara `premium-woo-categories`; `_elementor_data` tiene
un único widget funcional. Assets incluyen `woocommerce-general` y
`premium-woo-cats`. El widget consulta categorías WooCommerce y genera la
superficie completa; no hay texto informativo VeciAhorra.

El `post_content` contiene una representación indexable generada por Elementor
con seis enlaces almacenados en HTTP local:

- `/categoria-producto/vecised/`;
- `/categoria-producto/vecidespensa/`;
- `/categoria-producto/veciaseo/`;
- `/categoria-producto/panaderia/`;
- `/categoria-producto/vecised/bebidas/`;
- `/categoria-producto/vecised/agua/`.

El HTML público normaliza al host HTTPS actual. La salida observada agrupa las
seis categorías bajo navegación comercial. Los destinos se derivan del widget
y términos `product_cat`; no son una selección editorial durable en sus
settings. Clasificación: **página comercial heredada inequívoca**.

## 8. Auditoría de la página 356

| Campo | Evidencia efectiva |
|---|---|
| Tipo/estado | `page`, `publish` |
| Título/slug | `Principal`, `principal` (no autoridad) |
| Autor | usuario ID 1 |
| Plantilla | `default` |
| Editor | Elementor 4.1.2, modo `builder` |
| Widgets | `html` y dos `shortcode` |
| Contenido comercial | formulario product-only + `[products …]` |
| Bloques | ninguno |
| Menús/widgets externos | ninguna referencia de menú |
| HTTP/robots | 200; `max-image-preview:large` |
| Canonical | no detectado |
| Sitemap | incluida en sitemap core de páginas |

El formulario GET usa `name=s` y un hidden `post_type=product`. El widget
shortcode almacena `[products limit="50" columns="5" paginate="true"]`.
`products` es shortcode oficial, registrado por `WC_Shortcodes::products` en
`woocommerce/includes/class-wc-shortcodes.php`; admite atributos como limit,
columns, order/orderby, ids, skus, category, tag, visibility, pagination y
operadores, normalizados por la implementación WooCommerce.

El HTML produjo 31 enlaces comerciales: diez fichas de producto (imagen y
título) más categorías asociadas. No hay contenido legítimo independiente del
buscador/listado. Clasificación: **página comercial heredada inequívoca**.

## 9. Inventario de páginas similares

| ID | Estado | Señal | Clasificación |
|---:|---|---|---|
| 143 | publish | shop oficial | página oficial, ya cubierta |
| 144 | publish | `[woocommerce_cart]` | oficial, ya cubierta |
| 145 | publish | `[woocommerce_checkout]` | oficial, ya cubierta |
| 146 | publish | `[woocommerce_my_account]` | oficial, ya cubierta |
| 177 | publish | `premium-woo-categories` exclusivo | heredada inequívoca |
| 356 | publish | formulario product-only + `[products]` | heredada inequívoca |
| 147 | draft | texto de devoluciones de muestra | borrador informativo |

No se encontraron otras páginas publicadas con shortcodes, bloques, widgets o
enlaces comerciales WooCommerce. Las páginas 695, 698, 700, 701, 702, 713 y
715 contienen exclusivamente shortcodes VeciAhorra. Inicio ID 88 usa
`veciahorra_public_route_link`. La librería Elementor ID 7 (“Kit por defecto”)
no contiene widgets.

## 10. Shortcodes WooCommerce detectados

Registrados por `WC_Shortcodes`: `products`, `product`, `product_page`,
`product_category`, `product_categories`, `product_attribute`, `add_to_cart`,
`add_to_cart_url`, `shop_messages`, `woocommerce_messages`,
`woocommerce_cart`, `woocommerce_checkout`, `woocommerce_my_account`,
`woocommerce_order_tracking`, además de variantes recent/featured/sale/best
selling/top rated/related. También existen shortcodes HUSKY/WOOF y Brands.

Uso almacenado efectivo: 144–146 usan las autoridades oficiales y 356 usa
`products`. No se detectaron otros shortcodes WC en páginas publicadas. Los
tags registrados son catálogo de señales, no una denylist automática: por
ejemplo, `shop_messages` o un `add_to_cart` incidental no prueban por sí solos
que toda la página sea comercial.

## 11. Bloques WooCommerce detectados

La instalación registra 164 bloques `woocommerce/*`, incluidos product
collection/category/search/button, cart, checkout, mini-cart, customer-account
y legacy/classic-shortcode. Ninguna página auditada almacena un bloque con ese
namespace; 144–146 aparecen como `core/shortcode`. La futura detección debe usar
`parse_blocks()` y `blockName`, nunca búsqueda textual de comentarios HTML.

## 12. Widgets y Elementor

ID 177 usa `premium-woo-categories`; ID 356 usa widgets genéricos `html` y
`shortcode`, por lo que debe inspeccionarse su setting estructurado. No hay otro
widget comercial en páginas o Elementor Library. `sidebar-woocommerce` mantiene
`woof_widget-2`, condicional al archivo WC y no ligado a 177/356. Adaptadores de
widgets de terceros deben ser explícitos, versionados y conservadores.

## 13. Enlaces almacenados

ID 177 contiene seis destinos `product_cat` en el snapshot de `post_content`;
ID 356 no almacena permalinks de productos: el shortcode los genera en runtime.
No se hallaron enlaces WC directos en otras páginas publicadas. Un enlace
aislado es señal complementaria, no autoridad suficiente: puede ser una
referencia informativa, soporte o comparación legítima.

## 14. Autoridades evaluadas

- **IDs:** precisos localmente pero no portables; rechazados en producción.
- **Título/slug:** editables, traducibles y propensos a colisión; rechazados.
- **URL:** depende de dominio/permalinks; solo diagnóstico.
- **Shortcodes:** estructurados y parseables sin render; fuertes si dominan la
  página, insuficientes si son incidentales/escapados/mixtos.
- **Bloques:** namespace y atributos oficiales; señal fuerte, con la misma
  necesidad de evaluar composición total.
- **Metadata/plantilla:** metadatos Elementor identifican widgets; no existe una
  marca universal de “página comercial heredada”. Una marca VeciAhorra propia
  sí puede ser autoridad explícita.
- **Enlaces:** cobertura parcial y muchos falsos positivos; señal secundaria.
- **Registro explícito:** alta precisión y control, con riesgo de omisión y
  gestión; preferible como metadato portable por página.
- **Despublicación:** elimina búsqueda/sitemap de forma simple, pero es decisión
  editorial/operacional, no política general.

## 15. Clasificación semántica

1. **Oficial WooCommerce:** asignada por API/options; resolver actual.
2. **Heredada inequívoca:** finalidad principal/exclusiva de catálogo,
   categoría, compra, carrito, checkout o cuenta WC.
3. **Mixta:** contenido legítimo y componente comercial relevante.
4. **Informativa incidental:** referencia/enlace WC subordinado al contenido.
5. **Canónica VeciAhorra:** autoridad propia; siempre protegida.
6. **Prueba/borrador/inactiva:** no participa en búsqueda pública, aunque puede
   requerir saneamiento editorial.

## 16. Principio contra falsos positivos

Ante ambigüedad, no excluir. La evidencia automática mínima exige:

- objeto `page` publicado;
- no ser oficial WC ni ruta canónica VeciAhorra;
- árbol estructurado parseable;
- al menos un componente de alta confianza que produce una superficie
  comercial; y
- ausencia de contenido significativo no comercial fuera de contenedores,
  espaciadores, títulos auxiliares o un formulario product-only subordinado.

Shortcodes escapados, texto que contiene “products”, un enlace único o un
widget desconocido nunca bastan. Página mixta requiere declaración explícita.

## 17. Alternativa automática

Parsear `post_content`, bloques y metadata Elementor con un catálogo de
componentes de alta confianza. Precisión alta para `[products]` de página
completa; cobertura variable para addons. Es portable y reduce trabajo manual,
pero necesita adaptadores/versionado y puede fallar ante contenido mixto o
plugins nuevos. No debe ejecutarse durante cada búsqueda.

## 18. Alternativa explícita

Registrar en cada página un metadato, por ejemplo
`_veciahorra_public_search_classification=legacy_commerce_exclude`, con estados
`inherit`, `legacy_commerce_exclude` y `force_include`. La autoridad sería una
decisión editorial administrada, validada con capacidad específica y objeto
`page`. Viaja con exportaciones de contenido mejor que una lista central de
IDs. Alta precisión y reversibilidad; riesgo de olvido y necesidad futura de UI
o CLI controlada.

## 19. Alternativa operacional

Despublicar páginas obsoletas elimina búsqueda y sitemap, reduce superficie y
es reversible si se usa borrador. Requiere auditoría de tráfico/bookmarks,
preservación de contenido y posible redirección. No resuelve futuras páginas
similares ni sustituye una política; SEO y navegación cambian simultáneamente.

## 20. Alternativa híbrida

Combinar:

1. autoridades oficiales existentes;
2. detector automático conservador para composición inequívoca;
3. metadato explícito como decisión para widgets desconocidos/mixtos y como
   override `force_include`;
4. saneamiento operacional posterior cuando el contenido sea obsoleto.

Ofrece cobertura sin convertir heurísticas en autoridad absoluta. Es la
recomendación principal.

## 21. Comparación de alternativas

| Alternativa | Precisión/cobertura | Rendimiento | Portabilidad/mantenimiento | SEO/reversibilidad |
|---|---|---|---|---|
| Automática | alta en casos conocidos; falsos negativos en addons | buena con índice | adaptadores costosos | no cambia SEO; recalculable |
| Explícita | precisión editorial; cobertura depende de marcado | excelente | meta portable; requiere gobierno | reversible; noindex separado |
| Operacional | exacta para páginas retiradas | excelente | trabajo manual por entorno | afecta sitemap/acceso; borrador reversible |
| Híbrida | mejor equilibrio y override seguro | buena con índice | complejidad moderada, extensible | aislamiento separado de SEO |

## 22. Arquitectura recomendada

Mantener `WooCommercePublicPageResolver` sin cambios. Crear un
`LegacyWooCommerceContentPageResolver` separado que combine un
`StructuredCommercialContentDetector` conservador y un
`LegacyCommercePageClassificationRepository` basado en metadata. Un
`PublicSearchExcludedPageResolver` agregaría fuentes oficiales y heredadas,
deduplicaría/validaría y entregaría IDs a la política actual.

## 23. Componentes futuros

- `StructuredCommercialContentDetector`: valor semántico, sin WordPress query.
- `WooCommerceShortcodeClassifier`: catálogo explícito por impacto.
- `WooCommerceBlockClassifier`: analiza `parse_blocks()`.
- `ElementorCommercialWidgetClassifier`: adaptadores conocidos, incluido
  `premium-woo-categories`.
- `LegacyCommercePageClassificationRepository`: lee/escribe meta validada.
- `LegacyWooCommerceContentPageIndexer`: calcula índice fuera de búsquedas.
- `LegacyWooCommerceContentPageResolver`: lista memoizada de IDs.
- Compositor de fuentes para `PublicSearchIsolation`.

## 24. Integración con la política actual

El callback sigue autorizado exclusivamente por `allowsTraditionalSearch()` o
`allowsLiveSearch()`. Tras resolver tipos, el compositor obtiene IDs oficiales
y heredados; `mergesExcludedPostIds()` conserva exclusiones previas. No se
agregan filtros de resultados ni SQL manual. Product-only retorna antes de
resolver páginas. Las rutas canónicas obtenidas mediante `PublicRouteResolver`
se eliminan defensivamente del conjunto de exclusión.

## 25. Rendimiento y caché

No recorrer páginas ni parsear Elementor por búsqueda. El resolver lee un
índice durable precomputado, valida IDs en una consulta acotada y memoiza el
resultado por request. El cálculo se ejecuta al guardar candidatos o mediante
reconstrucción administrativa/CLI. No renderiza shortcodes, bloques dinámicos
ni HTML y no hace consultas por resultado.

## 26. Invalidación

Invalidar/reclasificar ante `save_post_page`, transición de estado,
trash/untrash/delete, actualización de `_elementor_data` y cambio de meta de
clasificación. Cambios de versión/registro de clasificadores invalidan el índice
completo. Evitar recursión de hooks y autosaves/revisiones. Si el índice falta,
usar solo clasificación explícita/autoridad oficial o reconstrucción controlada;
no escanear todo durante una búsqueda pública.

## 27. Compatibilidad

La propuesta no altera acceso directo, editor, preview, wp-admin, REST sin
marcador, Store API, cron, CLI, Action Scheduler, product-only, render WC,
catálogo/carrito/checkout/pagos/Customer Panel ni páginas canónicas. Si
WooCommerce/Elementor están inactivos, los parsers trabajan sobre estructura
almacenada y adaptadores ausentes fallan de forma cerrada: ambiguo significa no
excluir salvo meta explícita.

## 28. Tratamiento recomendado de ID 177

- Clasificación: heredada inequívoca, completamente comercial.
- Autoridad observada: `_elementor_data` y `_pa_widget_elements` con
  `premium-woo-categories`; no título/ID.
- Búsqueda: excluir mediante clasificación explícita inicialmente; un adaptador
  automático futuro puede reconocer el widget.
- Publicación: candidata fuerte a borrador porque no está en menú, no es
  requerida por WC y el catálogo VeciAhorra la reemplaza; decidir tras tráfico.
- Acceso directo: mantener temporalmente hasta decisión operacional.
- Redirección futura: si se retira, 301 al catálogo canónico, no a shop WC.
- Sitemap: retirar/noindex en microhito SEO o implícitamente al despublicar.
- Preservación: conservar imágenes/taxonomía visual solo si se migran a una
  futura navegación VeciAhorra.
- Riesgo al eliminar: bookmarks/índices y pérdida del diseño Elementor; usar
  borrador/backup, no eliminación inmediata.

## 29. Tratamiento recomendado de ID 356

- Clasificación: heredada inequívoca, completamente comercial.
- Autoridad observada: shortcode oficial `[products]` y formulario
  `post_type=product` estructurados.
- Búsqueda: excluir automáticamente por composición completa; permitir meta
  explícita como confirmación/override auditable.
- Publicación: candidata a borrador; no está en menú ni es requerida por WC.
- Acceso directo: mantener temporalmente hasta revisar tráfico.
- Redirección futura: 301 al catálogo VeciAhorra si se retira.
- Sitemap: retirar/noindex en fase SEO o mediante borrador.
- Preservación: documentar atributos de listado; el buscador product-only no
  debe migrarse al frontend canónico sin diseño propio.
- Riesgo al eliminar: enlaces externos/bookmarks y pérdida del layout; preferir
  borrador reversible.

## 30. Relación con SEO

El aislamiento interno impide resultados de búsqueda VeciAhorra; no cambia
sitemap, robots, canonical, acceso directo ni indexación externa. El saneamiento
SEO debe decidir noindex/sitemap para 177/356, las páginas oficiales, diez
productos y seis categorías, y resolver `/robots.txt` 404. `robots.txt` no
sustituye el filtro interno. Este diseño solo prepara clasificación reutilizable
por una futura política SEO, sin acoplarla.

## 31. Riesgos

- Falso positivo por shortcode incidental o página mixta.
- Falso negativo por widget/plugin desconocido.
- Índice obsoleto si Elementor guarda por una ruta no observada.
- Meta explícita mal administrada o divergente entre entornos.
- Override que excluya accidentalmente una ruta canónica.
- Desactivación de plugins que impida reconocer callbacks registrados.
- Cambios de formato Elementor/Woo Blocks.
- Saneamiento operacional sin auditoría de tráfico.

Mitigaciones: principio conservador, `force_include`, protección explícita de
rutas canónicas, adaptadores versionados, invalidación y pruebas de contrato.

## 32. Matriz de pruebas

| Caso | Resultado esperado |
|---|---|
| `[products]` simple/con atributos | comercial si domina la página |
| shortcode escapado | no comercial |
| shortcode dentro de contenido mixto | ambiguo; requiere meta |
| shortcode anidado | parseo estructural, sin render |
| bloque `woocommerce/*` principal | comercial según catálogo |
| bloque no WooCommerce | no comercial |
| widget `premium-woo-categories` | señal fuerte/adaptador |
| enlace único a categoría | incidental, no excluir |
| múltiples enlaces comerciales | señal, no autoridad aislada |
| referencia informativa | no excluir |
| página VeciAhorra con palabra products | no excluir |
| página oficial WC | solo resolver oficial |
| borrador/papelera/revisión/eliminada | fuera del índice público |
| cambio de contenido/estado | invalida y reclasifica |
| WooCommerce inactivo | sin fatal; meta explícita funciona |
| contenido malformado | ambiguo, no excluir |
| exclusión previa/duplicados | preservar orden y deduplicar |
| memoización | una resolución por request |
| tradicional/live | ambos excluyen mismo conjunto |
| REST sin marcador/product-only | intactos |
| admin/AJAX/cron/CLI/Scheduler/secundaria | intactos |
| ruta canónica con señal accidental | protegida/force include |

Añadir fixtures específicos equivalentes a 177/356 sin usar sus IDs, títulos o
slugs. Probar reconstrucción, invalidación y ausencia de consultas por resultado.

## 33. Plan de implementación futura

1. Formalizar enum de clasificación y catálogo de señales.
2. Implementar detectores puros para shortcodes/bloques y adapters Elementor.
3. Implementar repository de meta y protección de rutas canónicas.
4. Crear indexador durable e invalidación, con rebuild manual probado.
5. Crear resolver heredado memoizado y compositor con resolver oficial.
6. Integrar en los dos callbacks existentes sin hooks de búsqueda nuevos.
7. Probar fixtures, rendimiento y entorno vivo.
8. Marcar 177/356 explícitamente mediante un microhito autorizado.
9. Recertificar navegación.
10. Tratar publicación/redirecciones/SEO en microhitos separados.

## 34. Alcance negativo

No se implementó código, pruebas, meta, opción, caché, UI ni hooks. No se
modificaron páginas, estados, Elementor, Blocksy, WooCommerce, menús, sitemap,
robots, SEO o redirecciones. No se realizó staging, commit ni push.

## 35. Conclusión

La respuesta arquitectónica es separar autoridad oficial y clasificación de
contenido. La solución híbrida evita IDs locales y HTML runtime, automatiza
solo casos inequívocos y exige decisión explícita ante ambigüedad. ID 177 debe
marcarse explícitamente por su widget de tercero; ID 356 puede detectarse por
composición estructurada. Ambas deben salir de búsqueda y son candidatas a
borrador/redirección al catálogo tras una decisión operacional y SEO separada.
