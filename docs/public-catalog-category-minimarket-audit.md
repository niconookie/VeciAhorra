# Hito 32.0 — Auditoría de categorías y minimarkets en el catálogo público

Fecha de auditoría: 2026-07-19. Alcance: diagnóstico de solo lectura; no se implementó ninguna solución.

## 1. Resumen ejecutivo

El filtro de categoría no está disponible para el visitante porque el catálogo montado por `[veciahorra_frontend]` no renderiza buscador, selector de categoría, marca, orden ni paginación, y `assets/frontend/js/veciahorra-catalog.js` siempre solicita `GET /catalog/products?per_page=100&order_by=name`. No hay un parámetro incorrecto ni CSS ocultando el selector: el control sencillamente no existe.

El backend de Catalog sí tiene contrato y comportamiento para filtrar: `GET /catalog/products` acepta `category` como **ID entero positivo de término WordPress**, y `CatalogService::list()` compara ese ID con `Product.category_id`. Ausente o vacío significa “sin filtro”; un valor no entero, cero o negativo produce 422; un ID entero inexistente es válido sintácticamente y devuelve cero resultados, porque no se comprueba su existencia. El filtro se combina con `brand`, `search`, `order_by`, `page` y `per_page` en una misma petición.

No existe, sin embargo, un endpoint **público** para poblar las categorías. `GET /categories` existe en ProductCatalogs, pero exige `manage_options`. La lista pública incluye en cada producto `category: {id,name}|null`, por lo que el navegador podría deducir opciones solo de la página recibida, pero el frontend actual no lo intenta y esa deducción sería incompleta por disponibilidad y paginación.

Los datos locales agravan la percepción: existen 13 categorías y 4 Products activos, pero 2 Products activos no tienen categoría. Solo 3 Products son públicamente visibles; de ellos, únicamente “Coca-Cola 350 cc” está categorizado (`Bebidas`, ID 36). Por tanto, `category=36` tiene un resultado público demostrable, mientras las otras 12 categorías tienen cero Products públicos. El otro Product categorizado, “Coca Cola”, no tiene Inventory y queda fuera.

Minimarket es actualmente la entidad `Store`, durable en `va_stores`. Inventory conserva una única fila por `(product_id,minimarket_id)` y aporta precio, stock y estado. Catalog deriva de esa fila una proyección llamada `offer`; no hay entidad, tabla, repositorio ni ciclo de vida durable `Publication` u `Offer`. La publicación pública resulta de combinar Product activo + Inventory activo + stock positivo + precio positivo + Store activo. El detalle devuelve **todas** las filas que cumplen esas condiciones, sin límite explícito, ordenadas por precio ascendente, stock descendente e ID de Inventory. El listado muestra el precio mínimo y la cantidad de minimarkets; el frontend además consulta cada detalle y muestra el primer minimarket/oferta.

Clasificación confirmada: **endpoint público de categorías ausente**, **configuración/selector frontend ausentes**, **datos incompletos** y **prueba contractual insuficiente**. No se confirmó implementación backend defectuosa, parámetro frontend incorrecto, respuesta REST incompatible ni CSS ocultando el control. Marketplace formal, Publication, Offer durable y filtro por minimarket son capacidades futuras, no sustitutos del filtro de categoría.

Recomendación: ejecutar primero la **Alternativa A**, conservando IDs y autoridades actuales, mediante microhitos acotados de contrato público de opciones, UI/estado de filtros y pruebas/datos. Mantener la **Alternativa C** como evolución posterior deliberada; no introducirla para corregir este defecto. La Alternativa B puede seguir después de A para enriquecer información de minimarket sin declarar Inventory como Offer durable.

## 2. Arquitectura actual

| Concepto | Representación y autoridad durable | Persistencia | Responsabilidad actual |
|---|---|---|---|
| Category | Término de la taxonomía WooCommerce `product_cat`; ProductCatalogs es adaptador de lectura/validación | tablas WordPress `terms`/`term_taxonomy` | catálogo auxiliar `{id,name}`; su ID se referencia desde Product |
| Product | `Product` + `ProductRepository`/`ProductService` | `va_products` | maestro de identidad, contenido, categoría, marca, unidad y estado |
| Inventory | fila administrada por `InventoryService`/`InventoryRepository` | `va_inventory` | relación Product–Minimarket, precio, stock y estado; autoridad operacional de inventario |
| Minimarket | `Store` + `StoreRepository`/`StoreService` | `va_stores` | identidad y estado administrativo del comercio |
| Catalog | `CatalogService` como read model sin tabla propia | lee Product, Inventory, Store y taxonomías | composición pública, disponibilidad, resumen de precio y proyección de ofertas |
| Offer | DTO dentro del detalle público | no existe persistencia propia | proyección de una fila Inventory elegible y su Store activo |
| Publication | no implementada | no existe | espacio futuro reservado |

### 2.1 Product y Category

`ProductsTable` define `category_id BIGINT UNSIGNED NULL` e índice, sin foreign key SQL. `ProductRequest` acepta un ID opcional; `CatalogValidator` consulta `CategoryRepository::exists()` antes de crear/actualizar mediante los casos de uso normales. `ProductService` y las operaciones masivas escriben `category_id`. La relación durable, por tanto, pertenece a **Product**; ProductCatalogs mantiene la autoridad del catálogo de términos, no una tabla de asociación.

Las categorías son entidades de catálogo respaldadas por términos WordPress, no filas auxiliares internas de VeciAhorra. `TaxonomyCatalogRepository::all()` consulta `product_cat` con `hide_empty=false`, ordena por nombre y expone solo ID/nombre. No expone slug, aunque WordPress sí lo conserva internamente.

### 2.2 Minimarket, estados, aprobación y ownership

`Store` es el modelo denominado “Minimarket” y `StoresTable` conserva nombre comercial/legal, propietario textual, contacto, ubicación, `status`, `onboarding_status`, `approved_at` y timestamps. La creación administrativa fija `status=pending` y `onboarding_status=draft`; los estados aceptados por formularios/servicio son `pending`, `active`, `inactive`, `rejected`.

La aprobación está representada parcialmente por `status` y `approved_at`, pero la consulta pública solo exige `status='active'`; no exige `onboarding_status='complete'` ni `approved_at IS NOT NULL`. Tampoco existe `user_id`/account owner en Store: `owner_name` es texto. Así, identidad durable sí existe, pero ownership WordPress, roles, suspensión multiusuario y gobernanza siguen sin modelar, de acuerdo con la Serie 31.

Inventory referencia `minimarket_id`; Checkout agrupa CartItems por ese ID y crea un Order por minimarket. Order y Delivery persisten también `minimarket_id`. No hay relación directa Product–Store fuera de Inventory, ni asociación durable Store–usuario.

## 3. Flujo de filtrado por categoría

```text
product_cat (Category, ID de término)
       ↓ validación de ProductRequest/CatalogValidator
va_products.category_id
       ↓ CatalogListRequest(category: ?int)
CatalogService::list() compara IDs
       ↓
REST {success,data[],meta}; item.category={id,name}|null
       ↓
catalog.php no renderiza filtros
       ↓
veciahorra-catalog.js no envía category
       ↓
se renderizan hasta 100 resultados sin filtro de categoría
```

| Etapa | Componente | Entrada → salida | ID/validación y categoría inexistente | Evidencia y ausencia |
|---|---|---|---|---|
| Category | `CategoryRepository`, `TaxonomyCatalogRepository` | taxonomía `product_cat` → lista `{id:int,name:string}` | ID de término; repositorio puede comprobar existencia | Es entidad de catálogo WordPress; el contrato omite slug |
| Asignación Product | `ProductRequest`, `CatalogValidator`, `ProductService`, `ProductRepository`, `ProductsTable` | `category_id` opcional → `va_products.category_id` | entero positivo; `null` permitido; casos de uso validan término existente | relación durable singular en Product; no FK SQL |
| Query pública | `CatalogListRequest` | query `category` → `?int` | ausente/`''` → `null`; entero 1..máximo → ID; inválido → excepción/422; ID inexistente no se consulta | contrato backend presente; diferencia vacío/inválido explícita |
| Aplicación | `CatalogService::list()` | Product activos candidatos + inventario público → summaries filtrados | comparación estricta `(int)$product->category_id === category` | el filtro se aplica realmente antes de ordenar/paginar |
| REST | `CatalogRoutes`, `CatalogController` | GET público → `{success,data,meta}` | filtro válido sin coincidencias → 200, lista vacía; inválido → 422 | no hay schema de args en registro, pero Request valida |
| Opciones | `GET /categories` de ProductCatalogs | GET → `{success,data:[{id,name}]}` | exige `manage_options` | endpoint existe solo para administración; no sirve al visitante |
| Vista | `Frontend/Views/catalog.php` | shortcode → hero, estados y grid | ninguno | no hay `<select>`, formulario, búsqueda, marca, orden ni paginación |
| JavaScript | `veciahorra-catalog.js` | carga fija → cards | no lee ni envía categoría | no deduce categorías; no conserva filtros porque no existe estado de filtros |
| CSS | `veciahorra-frontend.css` | estilos del mount | `[hidden]` solo oculta estados marcados por HTML/JS | no hay regla que oculte un selector existente |

### 3.1 Paginación y composición de parámetros

El backend admite `page` por defecto 1, `per_page` por defecto 20 y máximo 100; devuelve `meta.page`, `per_page`, `total`, `total_pages`. Filtra por categoría, marca y búsqueda antes de ordenar/paginar, por lo que esos parámetros son composables si el cliente los envía juntos. El frontend fuerza `per_page=100`, no implementa páginas posteriores y puede ocultar Products públicos si existen más de 100. También realiza una petición de detalle por cada item (N+1 HTTP) para mostrar el primer minimarket, aunque el listado ya aporta el precio mínimo.

## 4. Contratos REST relevantes

### `GET /wp-json/veciahorra/v1/catalog/products`

Público (`permission_callback=__return_true`). Parámetros validados:

| Parámetro | Contrato |
|---|---|
| `category` | ID entero positivo; no slug, nombre ni texto libre |
| `brand` | ID entero positivo |
| `search` | texto saneado, máximo 100 caracteres |
| `page` | entero 1..1.000.000, defecto 1 |
| `per_page` | entero 1..100, defecto 20 |
| `order_by` | `name`, `price` o `newest`; defecto `name` |

Respuesta 200: `{success:true,data:[summary...],meta:{page,per_page,total,total_pages}}`. Cada summary incluye `id,name,slug,short_description,image,category,brand,unit,min_price,available_minimarkets`. `category` es `{id,name}` o `null`. 422 corresponde a sintaxis inválida; 503 a catálogos no disponibles.

Un ID de categoría positivo pero inexistente no es 422/404: devuelve 200 con `data=[]`, pues Catalog no valida el término. Esto es coherente con un filtro de lectura tolerante, pero debe quedar probado y documentado.

### `GET /wp-json/veciahorra/v1/catalog/products/{id}`

Público y read-only. Añade descripción, `availability='in_stock'`, rango y conteo de precios, `offers`, relacionados (máximo seis) y metadata. Devuelve 404 si Product no es activo o no tiene al menos una oferta proyectable.

Cada oferta proyectada contiene exactamente `inventory_id`, `minimarket_id`, `minimarket` (nombre comercial), `price`, `stock`. Esta exposición contradice la frase histórica del README que dice que la API no expone IDs/stock por tienda; el párrafo posterior del mismo README y las pruebas de detalle reflejan el contrato real más nuevo.

### `GET /wp-json/veciahorra/v1/categories`

Devuelve `{id,name}`, pero `ProductCatalogs\Routes\CatalogRoutes::canManageProducts()` exige `manage_options`. Es un contrato administrativo, aunque use un path sin prefijo `/admin`. No existe alternativa pública de opciones, ni categorías embebidas en `meta` del listado.

No existe filtro público por minimarket en `CatalogListRequest`. Los contratos actuales no lo prometen; debe tratarse como capacidad futura o enriquecimiento B, no como defecto equivalente al filtro de categoría.

## 5. Flujo frontend

`FrontendModule` registra `[veciahorra_frontend]`. `FrontendController::renderPlaceholder()` interpreta `product_id`: con ID renderiza la ficha y carga `veciahorra-product-offers.js`; sin ID renderiza `catalog.php` y carga `veciahorra-catalog.js`.

La vista de catálogo solo contiene encabezado, loader, error/reintento, vacío, grid y estado accesible. El JavaScript:

1. pide `/catalog/products?per_page=100&order_by=name`;
2. pide el detalle de cada Product;
3. toma `detail.offers[0]`, que ya es la oferta de menor precio;
4. muestra nombre del minimarket, precio y stock;
5. enlaza solo si existe una página WordPress con `[veciahorra_frontend product_id="..."]`.

No existen estado/query string de filtros, eventos de selector ni envío de `category`. Tampoco existen controles de búsqueda, marca u orden aunque el backend los acepte. Por ello no hay parámetro que “se pierda” al combinar filtros: la capacidad cliente completa está ausente.

## 6. Modelo actual de ofertas por minimarket

Un minimarket **no publica Products** mediante una entidad de publicación. Mantiene filas Inventory. Una fila Inventory funciona actualmente como fuente técnica de una oferta proyectada porque combina Product, Store, precio, stock y estado, pero no debe declararse definitivamente `Offer`: carece de vigencia, términos comerciales, moderación, seller/ownership formal, estados y auditoría propios.

La restricción única `inventory_product_minimarket_unique` y `InventoryService::create()` impiden más de una fila por Product–Minimarket. En datos locales tampoco hay duplicados. El detalle devuelve todas las filas elegibles, sin máximo contractual; una por minimarket debido a esa unicidad.

Condiciones efectivas de exposición:

- Product `status='active'`;
- Inventory `status='active'`;
- `stock > 0`;
- precio numérico, finito y `> 0` (aunque Inventory permite guardar cero);
- Store `status='active'`.

No se exige onboarding completo ni `approved_at`. `CatalogService::publicInventory()` verifica Store activo mediante `StoreRepository::findActiveByIds()`. El nombre comercial llega como `offers[].minimarket`. El listado cuenta Stores distintos y calcula mínimo/máximo luego de ordenar las ofertas; la tarjeta pública usa la primera oferta del detalle.

Esta es una proyección agregada de Products, no un listado de publicaciones durables. `available_minimarkets` cuenta alternativas; la ficha permite elegir una. Order y Delivery heredan el `minimarket_id` seleccionado a través de Cart/Checkout, pero no convierten Inventory en autoridad comercial formal.

## 7. Verificación de datos locales (solo lectura)

Se consultaron directamente las tablas locales sin ejecutar escrituras. No se incluyen contactos, RUT, direcciones, propietarios ni secretos.

| Comprobación | Resultado |
|---|---|
| Categorías | 13 términos `product_cat` |
| Products | 4, todos activos |
| Products activos sin categoría | 2: IDs 111 y 112 |
| Products categorizados | 2, ambos en Bebidas (ID 36) |
| Minimarkets | 4, todos `active`; 1 `complete`/aprobado y 3 `draft` sin `approved_at` |
| Inventory | 5 filas, todas activas, con stock y precio positivos |
| Duplicados Product–Minimarket | 0 |
| Products públicos efectivos | 3 |
| Categorías con Products públicos | 1 de 13: Bebidas, con 1 Product |

Detalle funcional:

- ID 111 “Detergente Ariel liquido”: público por Product/Inventory/Store, pero sin categoría; queda excluido de cualquier filtro categorizado.
- ID 112 “Arroz Banquete”: público por Product/Inventory/Store, pero sin categoría; queda excluido de cualquier filtro categorizado.
- ID 999999994 “Coca Cola”: Product activo y categoría Bebidas, pero sin Inventory; no es público.
- ID 999999995 “Coca-Cola 350 cc”: Product activo, categoría Bebidas, tres Inventory elegibles en tres Stores activos; es público y responde a `category=36`, con precio mínimo 1.150.

Las 12 categorías distintas de Bebidas tienen cero Products públicos. Los contadores nativos `term_taxonomy.count` no representan esta relación con `va_products`: algunos reflejan Products WooCommerce ajenos a la tabla maestra VeciAhorra. Por ello no deben usarse para decidir opciones públicas sin una consulta/proyección explícita.

Hallazgo de gobernanza: tres Stores `active` conservan onboarding `draft` y `approved_at=NULL`, y sus Inventory sí pueden hacerse públicos porque Catalog solo mira `status`. Esto no prueba datos inválidos bajo el contrato actual, pero sí una discrepancia semántica que debe decidirse y probarse antes de llamar “aprobación” a la regla pública.

## 8. Defectos confirmados

| Clasificación | Tipo | Evidencia |
|---|---|---|
| Endpoint público de categorías ausente | funcionalidad faltante | `/categories` exige `manage_options`; Catalog no devuelve opciones globales |
| Configuración frontend ausente | defecto/capacidad incompleta respecto del catálogo filtrable | no existe modelo de filtros ni contrato de opciones en el mount |
| Selector frontend no renderizado | defecto confirmado | `catalog.php` no contiene control |
| Datos incompletos | datos de prueba/operación insuficientes | 2/4 Products activos sin categoría; solo una categoría con resultado público |
| Prueba contractual insuficiente | defecto de cobertura | se prueba rechazo de `category=anything`, pero no coincidencia, no coincidencia válida, vacío ni combinación/paginación |
| Regla de aprobación de Store ambigua | riesgo/contrato incompleto | Stores activos con onboarding draft son públicos; código solo exige status activo |
| README internamente desactualizado | documentación defectuosa | niega IDs/stock por tienda antes de documentar que el detalle sí los expone |

No confirmados: implementación backend defectuosa, parámetro frontend incorrecto, respuesta REST incompatible, CSS ocultando control o confusión técnica Category/Minimarket. La experiencia puede inducir confusión, pero ambos conceptos están separados en código.

## 9. Capacidades ausentes

- filtro público de categoría en UI y persistencia de sus parámetros;
- contrato público/autorizado de opciones de categoría;
- filtros frontend para búsqueda, marca, orden y paginación ya soportados parcialmente por backend;
- filtro backend/frontend por minimarket (no prometido actualmente);
- Publication durable;
- Offer durable;
- bounded context Marketplace formal con ownership y gobernanza;
- definición unificada de Availability que considere reservas, horario/capacidad de fulfillment y aprobación;
- más de una oferta comercial por Product–Minimarket;
- ranking/promoción (fuera de este hito).

## 10. Riesgos arquitectónicos

1. El contrato de categoría depende de IDs WordPress; son correctos localmente, pero menos portables que un identificador público estable. Cambiar ahora a slug rompería el contrato existente y requiere versión/compatibilidad.
2. No hay FK entre `va_products.category_id` y términos; borrados o escrituras fuera de ProductService pueden dejar referencias huérfanas.
3. Catalog carga Products e Inventory en lotes y agrega en memoria; filtra antes de paginar, pero escala con el universo completo y realiza N+1 HTTP desde frontend.
4. Deducir categorías desde Products visibles produciría opciones incompletas por disponibilidad, filtros y máximo 100 del cliente.
5. Llamar “oferta” a Inventory endurece deuda semántica: precio/stock no equivalen necesariamente a publicación comercial.
6. `status=active` de Store es hoy la única aprobación pública efectiva; `onboarding_status` y `approved_at` pueden divergir.
7. El detalle no limita ofertas; un Product con muchos minimarkets puede crecer sin cota.
8. Los Products sin categoría son válidos y públicos sin filtro, pero invisibles al elegir cualquier categoría; se necesita decisión explícita sobre obligatoriedad o categoría “sin asignar”.

## 11. Solución mínima recomendada — Alternativa A

Usar exclusivamente las autoridades existentes: `product_cat` como catálogo, `Product.category_id` como relación y `category=<term_id>` como filtro.

La corrección mínima debe: exponer opciones públicas seguras `{id,name}` mediante una fachada de Catalog (no abrir sin más el endpoint administrativo); renderizar selector; enviar `category` solo cuando tenga ID; conservar `search`, `brand`, `order_by`, `page` y `per_page`; volver a página 1 al cambiar filtro; distinguir vacío/ausente de inválido; y añadir pruebas backend/frontend con datos categorizados y públicamente disponibles. No requiere migración, Publication ni cambios en Inventory.

Debe decidirse si se muestran todas las categorías o solo las que tienen al menos un Product público. Para evitar filtros permanentemente vacíos, se recomienda que la fachada pública devuelva opciones con conteo derivado de las mismas reglas de Catalog, sin reutilizar `term_taxonomy.count`.

## 12. Solución objetivo recomendada y comparación

| Alternativa | Beneficio | Costo/riesgo | Veredicto |
|---|---|---|---|
| A — Corrección mínima | resuelve el defecto con contratos y autoridades vigentes | mantiene ID WordPress y read model en memoria | **recomendada ahora** |
| B — Catálogo enriquecido | puede exponer minimarket/conteos y evitar N+1 sin entidad nueva | amplía contrato y puede consolidar lenguaje “offer” prematuramente | posterior a A, acotada a proyecciones explícitas |
| C — Marketplace formal | separa Product de publicación/comercialización y habilita lifecycle real | nueva autoridad, migración, ownership y políticas; sobredimensionada para el defecto | solución objetivo de largo plazo, en hito de diseño e implementación propio |

La recomendación inmediata es A. La solución objetivo es C solo cuando existan requisitos reales de múltiples publicaciones, vigencia, moderación, condiciones comerciales, vendedores/ownership o promociones. B es un puente útil: optimizar el DTO para incluir una mejor proyección de minimarket y precio sin declarar entidad Offer.

## 13. Microhitos propuestos

1. **32.1 Contrato público de categorías:** decidir todas vs disponibles, forma `{id,name,count?}`, manejo de ID inexistente, cache y compatibilidad; pruebas de contrato primero.
2. **32.2 Filtro backend y datos:** reforzar casos category válido/con resultado, válido/sin resultado, vacío, inválido, combinado y paginado; auditar/asignar categorías mediante proceso separado y autorizado.
3. **32.3 Frontend de filtros:** selector accesible, estado/query string, combinación con búsqueda/marca/orden, reset de página, vacío/error y pruebas browser.
4. **32.4 Recertificación pública:** E2E con categorías reales, >100 Products, navegación y regresión de catálogo/ficha/carrito; resolver documentación contradictoria.
5. **32.5 Catálogo enriquecido opcional (B):** eliminar N+1 y definir proyección resumida de mejor alternativa/minimarkets sin autoridad Offer.
6. **Serie futura Marketplace (C):** discovery/diseño de Publication/Offer, ownership, lifecycle, moderación, disponibilidad y migración; no iniciarla como parte de la corrección 32.1–32.4.

## 14. Archivos productivos que probablemente requerirían cambios

Sin modificarlos en esta auditoría:

- `app/Modules/Catalog/Routes/CatalogRoutes.php`
- `app/Modules/Catalog/Controller/CatalogController.php`
- `app/Modules/Catalog/Service/CatalogService.php`
- posible Request/DTO nuevo bajo `app/Modules/Catalog/`
- `app/Modules/Frontend/Views/catalog.php`
- `assets/frontend/js/veciahorra-catalog.js`
- `assets/frontend/css/veciahorra-frontend.css` solo para presentar el control nuevo, no porque hoy lo oculte
- `app/Modules/Catalog/README.md`

No se recomienda convertir `ProductCatalogs/Routes/CategoryRoutes.php` administrativo en público sin una política explícita; la fachada pública debe pertenecer a Catalog.

## 15. Pruebas a agregar o reforzar

Backend/contrato:

- category ID válido con uno y varios resultados;
- ID válido sin resultados e ID positivo inexistente;
- parámetro ausente, `''`, cero, negativo, decimal, array, slug y nombre;
- combinación con `search`, `brand`, los tres órdenes y paginación;
- categoría existente pero Product inactivo, sin Inventory, Inventory inactivo/sin stock/precio inválido o Store inactivo;
- contrato de opciones públicas y conteos bajo las mismas reglas;
- Store activo con onboarding/aprobación divergente, una vez decidida la política;
- Products sin categoría y referencias huérfanas.

Frontend/browser:

- selector renderizado, accesible y poblado;
- envío exacto `category=<id>` y ausencia del parámetro al elegir “todas”;
- conservación de filtros y reset de página;
- estados loading/error/vacío y reintento;
- respuesta paginada, más de 100 resultados y deep link/query string;
- ausencia de deducción incompleta desde la página de Products;
- regresión de tarjetas, detalle, selección de minimarket y carrito.

Cobertura actual relevante: `catalog-public-api-test.php` prueba rutas, visibilidad, search, orden/paginación básicos y rechazo de `category=anything`, pero no el filtrado exitoso; `catalog-public-detail-test.php` prueba ofertas, Store inactivo, orden y relacionados por categoría; `product-catalog-routes-test.php` cubre catálogos administrativos; `frontend-foundation-test.php` y pruebas frontend/ofertas cubren montaje, no filtros de categoría.

## 16. Límites por bounded context y conclusión

| Contexto/capa | Responsabilidad del hallazgo |
|---|---|
| Products | guarda y valida la relación singular `category_id`; permite `null` |
| ProductCatalogs | adapta taxonomías y ofrece opciones administrativas; autoridad del término |
| Catalog | acepta/aplica filtro y compone disponibilidad; debe poseer la fachada pública de opciones |
| Inventory | autoridad de precio/stock/estado por Product–Minimarket; fuente de proyección, no Offer definitiva |
| Minimarket/Stores | identidad/estado del comercio; su estado activo condiciona exposición |
| Marketplace | no está formalizado como bounded context/autoridad de publicación; capacidad futura |
| Frontend | causa directa de que el usuario no pueda filtrar: no hay control ni parámetros |

Causa raíz: la capacidad backend fue construida sin completar su superficie pública: falta un contrato público de opciones y falta por entero la UI/gestión de filtros. Los datos incompletos reducen además el conjunto demostrable, pero no causan por sí solos la ausencia del selector. El defecto pertenece principalmente a **Frontend + fachada de Catalog**, con deuda de datos en Products y una decisión arquitectónica pendiente en Store/Marketplace; no pertenece al módulo Minimarket.
