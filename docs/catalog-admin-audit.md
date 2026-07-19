# Microhito 34.0 — Auditoría integral del flujo de administración del catálogo

## 1. Resumen ejecutivo

VeciAhorra tiene dos autoridades comerciales separadas y funcionales: `Product`
describe el producto maestro y `Inventory` describe una oferta de ese producto en
un `Store`. La publicación pública no es un estado almacenado adicional: se
calcula al leer, combinando Product activo, Inventory activo, stock positivo,
precio estrictamente positivo y Store activo.

El flujo no está cerrado desde la interfaz administrativa. Products dispone de
una pantalla moderna para listar, buscar, crear, editar, activar y seleccionar
una imagen de WordPress. Inventory también tiene CRUD REST, pero su interfaz pide
`Product ID` y `Minimarket ID` numéricos, no muestra nombres ni ofrece búsqueda,
y no enlaza desde el producto recién creado. Categorías, marcas y unidades tienen
repositorios y endpoints de lectura, pero VeciAhorra no ofrece una interfaz ni
endpoints para crearlas o mantenerlas. Las operaciones masivas de Product y la
eliminación de Inventory existen en backend, pero no están conectadas a sus
pantallas.

La causa histórica exacta de la imposibilidad de seleccionar una imagen fue de
JavaScript: el selector llamaba `frame.state().get('selection')` antes de abrir
el frame de `wp.media`; en una Media Library real ese estado todavía podía ser
`undefined`, abortando el click antes de abrir el selector. El commit
`e67245b5b4bba5ff393fb5f8d005a49b69206a5f` (`fix(products): restore media
selector and unit catalog`, 2026-07-17) invirtió el orden —primero `frame.open()`—
y agregó acceso defensivo al estado. En el `HEAD` auditado (`2478657`) la cadena
selección → `image_id` → REST → validación → `wp_va_products.image_id` funciona y
está cubierta. Por tanto, el defecto observado corresponde al estado anterior a
ese commit (o a un módulo antiguo retenido por caché si se observó después); no
hay evidencia de un defecto vigente de persistencia o contrato REST.

El bloqueo vigente para poblar un catálogo con naturalidad ya no es la imagen:
es la desconexión entre catálogos, Product, Inventory y Store. El Hito 34.1 debe
priorizar un flujo encadenado y selectores por nombre, además de certificar en
navegador real que la corrección de Media Library llega sin caché antigua.

## 2. Alcance y metodología

Se inspeccionaron las clases, vistas, scripts, tablas, endpoints y consultas que
intervienen entre administración y catálogo público. Se revisó historial Git sólo
para fechar y explicar la diferencia del selector de medios. Se ejecutaron
pruebas existentes que crean fixtures y los eliminan en `finally`; no se alteró
manualmente ningún registro. Además se hicieron consultas agregadas de solo
lectura a la instalación local, sin registrar nombres, correos, direcciones ni
otros datos sensibles.

Estado local observado el 2026-07-19: 4 Products activos, 5 Inventory activos, 4
Stores activos, 13 categorías, 1 marca, 1 unidad y 86 attachments. Los 4 Products
tienen `image_id`, ninguno apunta a un attachment inexistente y las 5 ofertas
cumplen actualmente los criterios públicos. Tres Stores activos conservan
`onboarding_status=draft`; esto prueba que onboarding/aprobación no participa en
la consulta pública vigente.

La prueba visual headless de `tests/manual/product-media-selector-test.html` no
pudo ejecutarse: Chrome y Edge terminaron con un error fatal de proceso GPU antes
de cargar el harness. Esta es una limitación del navegador local, no un resultado
FAIL del test. La auditoría no elude esa diferencia: las conclusiones dinámicas se
apoyan en pruebas PHP aprobadas y la conclusión de UI en código, harness e
historial. La certificación interactiva real queda como criterio explícito del
siguiente microhito.

## 3. Arquitectura administrativa actual

| Área | Componentes VeciAhorra | Autoridad externa |
|---|---|---|
| Product | `app/Modules/Products/{Admin,Controllers,Models,Repositories,Requests,Routes,Services,Views}` | ninguna para el registro maestro; `woo_product_id` es sólo referencia opcional |
| Catálogos | `app/Modules/ProductCatalogs` | términos WordPress: `product_cat`, `product_brand`, `pa_unidad` |
| Imagen | `ProductsPage`, `assets/admin/products/view.js`, `CatalogValidator` | WordPress Media Library, posts `attachment` y metadatos de medios |
| Inventory | `app/Modules/Inventory`, `assets/admin/js/modules/inventory`, `assets/admin/css/inventory.css` | ninguna |
| Store | `app/Modules/Stores` y `app/Admin/Tables/StoresTable.php` | ninguna |
| Lectura pública | `app/Modules/Catalog`, `app/Modules/Frontend` | página/shortcode WordPress; no consulta el catálogo WooCommerce |

`app/Admin/Menu.php` registra VeciAhorra, Dashboard y Minimarkets. Products e
Inventory agregan sus propios submenús mediante `ProductsPage` e
`InventoryPage`. Todas estas pantallas requieren `manage_options`. Los clientes
REST reciben `rest_url('veciahorra/v1')` y un nonce `wp_rest`, envían
`X-WP-Nonce` y credenciales same-origin. Sus rutas repiten el control
`current_user_can('manage_options')`.

`ProductsPage::enqueueAssets()` está limitado a su hook, ejecuta
`wp_enqueue_media()`, carga `assets/admin/products/app.js` como módulo y
`products.css`. Inventory carga su módulo y CSS sólo en su pantalla. La carga de
Media Library y los límites de subida/MIME dependen de WordPress/PHP y de la
capability efectiva para subir archivos; VeciAhorra no implementa un uploader.

## 4. Mapa de pantallas

| Pantalla | Entrada | Capacidades reales |
|---|---|---|
| Dashboard | `admin.php?page=veciahorra` | texto de bienvenida; sin accesos de flujo |
| Products | `admin.php?page=veciahorra-products` | listado, búsqueda, paginación, crear, editar, activar/inactivar e imagen |
| Inventory | `admin.php?page=veciahorra-inventory` | listado, filtros por texto/IDs/estado, crear y editar |
| Minimarkets | `admin.php?page=veciahorra-stores` | listado, búsqueda, estado, alta, edición, eliminación y estado masivo |
| Alta/edición Store | subpáginas ocultas del menú | formularios PHP con nonce |
| Categorías | no existe en VeciAhorra | depende de una UI externa que registre `product_cat` |
| Marcas | no existe en VeciAhorra | depende de una UI externa que registre `product_brand` |
| Unidades | no existe | `UnitTaxonomy` registra `pa_unidad` con `show_ui=false` |
| Catálogo público | página canónica con shortcode | listado, filtros y ficha compuesta |

Products lista ID, nombre, SKU, estado y fecha de actualización. No muestra
thumbnail, categoría, marca, unidad, cantidad de ofertas ni estado público. La
búsqueda y paginación son utilizables; no hay filtro visual por estado/catálogo,
checkboxes, eliminación, duplicación ni acceso a Inventory.

Inventory lista exclusivamente IDs internos, precio, stock, estado y fecha. La
ruta DELETE existe, pero la vista sólo presenta “Editar”. Tampoco expone los
nombres de Product/Store ni una navegación contextual.

## 5. Mapa de endpoints y contratos

Todos los endpoints siguientes usan el namespace `/wp-json/veciahorra/v1`.

| Endpoint | Métodos | Contrato y acceso |
|---|---|---|
| `/products` | GET, POST | lista paginada / crea Product draft; administrador |
| `/products/search` | GET | búsqueda paginada; administrador |
| `/products/{id}` | GET, PATCH | detalle / actualización parcial; administrador |
| `/products/{id}/status` | PATCH | `active` o `inactive`; administrador |
| `/products/bulk/status` | PATCH | backend existente, sin UI |
| `/products/bulk/category` | PATCH | backend existente, sin UI |
| `/products/bulk/brand` | PATCH | backend existente, sin UI |
| `/products/bulk/unit` | PATCH | backend existente, sin UI |
| `/categories`, `/brands`, `/units` | GET | sólo `{id,name}`, orden por nombre; administrador |
| `/inventory` | GET, POST | lista/filtros / alta; administrador |
| `/inventory/{id}` | GET, PUT/PATCH, DELETE | detalle/edición/borrado; DELETE sin UI |
| `/inventory/{id}/price` | PATCH | backend específico, sin control dedicado |
| `/inventory/{id}/stock` | PATCH | backend específico, sin control dedicado |
| `/inventory/{id}/status` | PATCH | backend específico, sin control dedicado |
| `/catalog/products` | GET | público, sólo lectura |
| `/catalog/products/{id}` | GET | ficha y ofertas públicas, sólo lectura |
| `/catalog/categories` | GET | categorías con Products visibles, sólo lectura |

Ejemplo de creación Product:

```json
{
  "name": "Arroz grado 1",
  "sku": "ARROZ-001",
  "description": "Bolsa de un kilogramo",
  "category_id": 12,
  "brand_id": 34,
  "unit_id": 56,
  "image_id": 789,
  "woo_product_id": null
}
```

La respuesta crea un ID y el servicio impone estado `draft` y slug único. Ejemplo
Inventory: `{ "product_id": 1, "minimarket_id": 2, "price": 990,
"stock": 12, "status": "active" }`.

## 6. Persistencia y relaciones

`app/Database/Tables/ProductsTable.php` define `wp_va_products`: ID,
`woo_product_id`, nombre, slug, SKU, descripción, los IDs de categoría/marca/
unidad/imagen, estado y timestamps. Slug, SKU y `woo_product_id` tienen índices
únicos (los dos últimos admiten `NULL`). Los IDs de términos e imagen son
referencias lógicas, no foreign keys.

`app/Database/Schemas/InventorySchema.php` define `wp_va_inventory`: Product,
Store (`minimarket_id`), precio decimal(10,2), stock entero, estado y timestamps.
La única restricción comercial es UNIQUE (`product_id`, `minimarket_id`): un
Product admite muchas ofertas, una Store admite muchos Products, pero la misma
pareja no se duplica. Tampoco hay foreign keys.

`app/Database/Tables/StoresTable.php` define `wp_va_stores`, cuya identidad
pública es `business_name`. `status` y `onboarding_status` son columnas distintas;
`approved_at` existe, pero Catalog sólo consulta `status`.

Los catálogos se persisten en `wp_terms`/`wp_term_taxonomy` bajo taxonomías de
WordPress. No poseen estado activo/inactivo en el contrato de VeciAhorra. Borrar
un término usado deja un ID lógico en Product; el serializador público devuelve
`null` para ese atributo, pero el Product puede seguir publicado si conserva una
oferta válida.

## 7. Flujo real de creación de Product

1. Products carga la lista y, en paralelo, GET de categorías, marcas y unidades.
2. “Nuevo producto” abre un formulario generado en `assets/admin/products/view.js`.
3. Sólo nombre es obligatorio. SKU, descripción, referencia Woo, catálogos e
   imagen son opcionales. Estado inicial no se elige: siempre es `draft`.
4. El frontend limita nombre a 180 y SKU a 100, convierte selects/imagen a IDs
   positivos o `null`, evita doble guardado y muestra errores por campo.
5. `ProductRequest` aplica `sanitize_text_field`/`sanitize_textarea_field`, valida
   IDs positivos y conserva semántica PATCH.
6. `CatalogValidator` exige que términos y attachment existan. No valida MIME.
7. `ProductService` verifica SKU y `woo_product_id`, genera slug con
   `sanitize_title`, agrega `-2`, `-3`, etc. ante colisión y persiste draft.
8. Al cambiar el nombre posteriormente, regenera un slug único. El slug nunca se
   introduce manualmente.
9. El administrador debe activar el Product desde el formulario de edición.

La descripción es texto plano: se sanitiza como textarea. En público se eliminan
tags, se recorta a 30 palabras para el resumen y se entrega completa —también sin
HTML— en el detalle.

No hay borrado de Product. Las acciones bulk están probadas en REST, pero no hay
controles en la vista. La UI protege cambios sin guardar y respuestas antiguas de
detalle, y entrega mensajes de éxito/error; no ofrece previsualización pública.

## 8. Auditoría de imágenes

### Cadena vigente

```text
ProductsPage::wp_enqueue_media()
  → botón “Seleccionar imagen”
  → wp.media({library:{type:'image'}, multiple:false})
  → attachment.id en input oculto imageId
  → store normaliza a image_id entero/null
  → POST/PATCH /products
  → CatalogValidator comprueba post_type=attachment
  → wp_va_products.image_id
  → wp.media.attachment(id) al editar
  → CatalogService::image() usa wp_get_attachment_image_url(id,'medium')
  → DTO público image URL|null
  → ficha, carrito y panel aplican sus fallbacks
```

La ventana nativa incluye las pestañas de subir y biblioteca cuando WordPress las
habilita. WordPress determina extensión, MIME, cuota, tamaño máximo, dimensiones,
metadatos y permisos. VeciAhorra restringe la selección a `type=image`, pero el
backend sólo comprueba `post_type=attachment`: un attachment no-imagen enviado
manualmente por REST superaría `CatalogValidator`. Es una inconsistencia de
defensa, no la causa de la falla observada.

La preview prefiere el thumbnail y cae a URL original. Cambiar reutiliza el frame;
quitar limpia `image_id`; cancelar no emite `select` y mantiene el valor. Al editar,
se recupera el attachment desde la caché/modelo de Media Library. Si fue borrado,
la UI informa que no pudo cargar la vista previa pero conserva el ID hasta que el
administrador lo quite; la API pública devuelve `image:null`. La tabla Products no
muestra thumbnail, por lo que “mostrar thumbnail en administración” sólo existe
dentro del formulario.

La URL administrativa de preview no recibe una validación explícita de protocolo;
proviene del modelo WordPress. En el público, `wp_get_attachment_image_url()`
produce la URL o `null`, y los consumidores aplican fallback y validaciones de URL.
Cart expone `product_image_id` y thumbnail URL actuales. Customer Panel trata la
imagen como referencia actual, no snapshot histórico, y reemplaza URL ausente,
insegura o fallida con placeholder.

### Causa exacta y estado

Antes de `e67245b`, `createMediaPicker.open()` ejecutaba
`prepareSelection(control.input.value)` antes de `frame.open()`. Esa función
desreferenciaba inmediatamente `frame.state().get('selection')`. En WordPress el
estado puede no existir hasta que el frame se abre, produciendo una excepción JS y
evitando abrir/seleccionar la imagen. El commit movió `frame.open()` antes de la
preparación y agregó `currentSelection()` con comprobaciones defensivas. El harness
`tests/manual/product-media-selector-test.html` reproduce precisamente un estado
no disponible y exige que el frame abra sin excepción.

La validación `tests/manual/product-catalog-validation-test.php` aprobó la creación,
persistencia, edición y limpieza de un attachment válido, y rechazó IDs inexistentes
y posts no attachment. La base local muestra 4/4 Products con attachments válidos.
No se reprodujo una falla vigente de REST o persistencia. Si la misma excepción se
ve hoy, debe verificarse primero que el navegador sirva la versión actual del módulo
(caché/opcache/CDN); atribuirla a permisos o MIME sin el error de consola sería una
suposición.

## 9. Auditoría de categorías

- Autoridad: taxonomía `product_cat`, normalmente registrada por WooCommerce.
- Repositorio/servicio: `CategoryRepository` / `CategoryService`.
- Endpoint VeciAhorra: GET `/categories`, sólo lectura, `{id,name}`.
- Product valida existencia por `get_term(id,'product_cat')`.
- El administrador no puede crear, editar ni eliminar categorías desde la interfaz
  VeciAhorra. Puede hacerlo sólo si otra infraestructura ofrece la UI de esa
  taxonomía, abandonando el formulario.
- La lista del formulario se carga al abrir la pantalla; cambios externos requieren
  recargar/reintentar catálogos, no existe creación inline.
- WordPress maneja nombre, slug, unicidad y jerarquía; VeciAhorra no agrega reglas.
- No existe activación/inactivación. Una categoría vacía aparece en el selector
  administrativo, pero `/catalog/categories` sólo expone términos con Products
  públicamente visibles y cuenta Products distintos.
- Borrar una categoría usada no bloquea ni repara Product; el atributo público cae
  a `null` y el filtro ya no puede encontrarlo por esa categoría.

## 10. Auditoría de marcas

- Autoridad: taxonomía `product_brand`, dependiente de que WooCommerce u otro
  componente la registre.
- Repositorio/servicio: `BrandRepository` / `BrandService`.
- Endpoint: GET `/brands`, sólo lectura y ordenado por nombre.
- No hay UI VeciAhorra para listar, crear, editar, eliminar o activar marcas.
- No existe creación inline ni refresco reactivo desde el formulario Product.
- Duplicados, slugs y eliminación dependen por completo de WordPress/taxonomía.
- Una marca es opcional para publicar. Si falta o su término fue borrado, el DTO
  devuelve `brand:null`; el filtro por marca deja de incluir ese Product.

## 11. Auditoría de unidades

- Autoridad: `pa_unidad`.
- `app/Modules/ProductCatalogs/UnitTaxonomy.php` garantiza que exista, pero la
  registra con `public=false`, `show_ui=false`, `show_in_rest=false`.
- Repositorio/servicio: `UnitRepository` / `UnitService`; endpoint GET `/units`.
- No existe ninguna UI utilizable para crear, editar o borrar unidades. Ésta es la
  brecha más fuerte de los tres catálogos: incluso su taxonomía propia oculta la UI.
- La unidad es opcional para Product y para publicación. Términos duplicados/slugs
  dependen de la API WordPress usada fuera del flujo.
- Si se borra una unidad usada, Product conserva el ID lógico y el DTO entrega
  `unit:null`.

Respuesta expresa para los tres catálogos: desde VeciAhorra el administrador no
puede crearlos, editarlos ni eliminarlos; debe salir del formulario y, para unidad,
no dispone siquiera de una pantalla WordPress registrada por este plugin. Los
cambios no aparecen automáticamente. WordPress evita normalmente slugs duplicados
dentro de una taxonomía, pero nombres visualmente duplicados pueden existir. No hay
“elementos inactivos” en el contrato actual.

## 12. Auditoría de Inventory

La pantalla permite listar, buscar texto, filtrar por Product ID, Minimarket ID y
estado, paginar 20/50/100, crear y editar. Product y Store se introducen como IDs
positivos. Precio acepta cero o más, stock entero cero o más, y estado por defecto
es `active`. En edición Product/Store quedan inmutables; para cambiar la pareja hay
que borrar por REST y recrear, pero la UI ni siquiera conecta DELETE.

`InventoryService` evita duplicar la pareja y la base refuerza esa restricción.
No comprueba que Product o Store existan ni cuál sea su estado; por ello puede
crear huérfanos lógicos o una oferta que jamás se publique. El controlador traduce
la colisión detectada por servicio a validación, pero una carrera concurrente puede
llegar al índice único y convertirse en error genérico de persistencia. No hay
control optimista ni versión para ediciones simultáneas; el último PATCH gana.

Se permiten múltiples Inventory por Product, uno por cada Store, y múltiples por
Store, uno por cada Product. Precio cero es administrativamente válido, pero no es
público ni agregable al carrito. Stock cero conserva Inventory pero retira su
oferta pública. Inventory debe estar activo; Product y Store también deben estar
activos. Los cambios se reflejan en la siguiente petición pública: no hay caché,
job, botón publicar ni pantalla que guardar adicional.

Crear muchas ofertas es lento: una pantalla y guardado por pareja, IDs manuales,
sin duplicar, búsqueda nominal, edición inline, importación, bulk ni enlace desde
Product. La API posee operaciones específicas para precio/stock/estado, pero la UI
usa una edición completa de esos tres campos.

## 13. Dependencias de minimarkets

Store usa el ID de `wp_va_stores`. La UI de Inventory no contiene selector: no
muestra `business_name`, estado, onboarding ni ubicación, y permite equivocarse de
Store con facilidad. Tampoco ofrece búsqueda al crear; el administrador debe
consultar la pantalla Minimarkets y copiar el ID.

Para publicar, `StoreRepository::findActiveByIds()` exige exclusivamente
`status='active'` y Catalog expone sólo `business_name`. No requiere
`onboarding_status=complete` ni `approved_at`; los datos locales demuestran que
Stores active/draft producen ofertas elegibles. Un Store inactivo o inexistente
hace invisible la oferta. El borrado físico está disponible en la administración
Store sin una comprobación visible de Inventory en uso; al no haber foreign key,
puede dejar Inventory huérfano que el catálogo descarta silenciosamente.

## 14. Condiciones exactas de publicación

La autoridad está en `app/Modules/Catalog/Service/CatalogService.php`, que lee
Products activos en lotes de 200, Inventory activos, precios/stocks y Stores
activos. No existe caché de aplicación.

| Condición | Requerida | Resultado si falta |
|---|---:|---|
| Product existente y `active` | Sí | fuera de listado, búsqueda y ficha (404) |
| Inventory existente y `active` | Sí, al menos uno | Product invisible |
| Stock > 0 | Sí por oferta | oferta omitida; si era la última, Product invisible |
| Precio numérico finito > 0 | Sí por oferta | oferta omitida; cero es válido en admin pero no público |
| Store existente y `active` | Sí por oferta | oferta omitida |
| Store onboarding completo/aprobado | No | no afecta publicación vigente |
| Categoría existente | No | Product visible con `category:null`; no entra en filtro/categorías |
| Marca existente | No | Product visible con `brand:null`; no entra en filtro de marca |
| Unidad existente | No | Product visible con `unit:null` |
| Imagen existente | No | Product visible con `image:null` y placeholder |
| Descripción | No | resumen/detalle vacío |
| Guardado/recarga adicional | No | siguiente GET ve la escritura confirmada |

Listado y búsqueda comparten esas reglas. Categoría y marca comparan IDs guardados.
La ficha exige el mismo Product visible. Las ofertas se ordenan por precio ascendente,
stock descendente e Inventory ID; el mínimo público se deriva de ese orden. Los
relacionados requieren que el Product actual tenga categoría, buscan otros Products
de la misma categoría, aplican las mismas reglas públicas, ordenan por nombre/ID y
limitan a seis. La imagen no determina visibilidad.

## 15. Resultado de la prueba manual completa

No se escribieron datos para forzar un recorrido: el bloqueo de catálogos habría
hecho artificial una prueba “completa” y las restricciones privilegiaban no cambiar
datos. Se recorrió cada paso contra pantalla/contrato/persistencia y se validaron las
escrituras con fixtures autocontenidos.

| Paso | Pantalla/acción esperada | Resultado real y evidencia |
|---:|---|---|
| 1 | crear categoría | bloqueado: no hay pantalla ni POST VeciAhorra |
| 2 | crear marca | bloqueado: no hay pantalla ni POST VeciAhorra |
| 3 | crear unidad | bloqueado: no hay pantalla; `show_ui=false` |
| 4–8 | crear Product y asignar datos | disponible si los términos ya existen; POST `/products` |
| 5 | completar slug | no existe campo; se genera automáticamente, que es el contrato real |
| 9 | subir/seleccionar imagen | cadena vigente implementada; defecto histórico reparado en `e67245b` |
| 10 | guardar Product | validado con `product-catalog-validation-test.php`; persiste draft e imagen |
| 11–14 | crear Inventory, Store/precio/stock | disponible, pero obliga a copiar IDs entre pantallas |
| 15 | activar | Product requiere PATCH status; Inventory nace active por defecto |
| 16–20 | abrir, buscar, ficha, imagen, oferta | contratos aprobados por pruebas públicas; sin caché |
| 21 | nombre/precio/stock | ficha expone minimarket, precio y stock según contrato actual |

Solicitudes relevantes: GET catálogos antes del formulario; POST `/products`;
PATCH `/products/{id}/status`; POST `/inventory`; GET `/catalog/products?search=…`;
GET `/catalog/products/{id}`. Cada fixture PHP fue limpiado por la propia prueba.

La ejecución interactiva no quedó certificada porque el navegador headless local
terminó antes de cargar el harness. Por ello no se afirma que se haya subido un
archivo real durante esta auditoría. Sí se conoce el punto histórico exacto de
interrupción y se comprobó que backend/persistencia vigentes aceptan el attachment.

## 16. Problemas encontrados

| ID | Clase / severidad | Evidencia y componente | Impacto / causa | Recomendación / microhito |
|---|---|---|---|---|
| ADM-01 | Función faltante / crítica | ProductCatalogs sólo GET; unidad `show_ui=false` | no se puede iniciar un catálogo desde VeciAhorra | CRUD seguro de catálogos e inline create / 34.2 |
| ADM-02 | Problema de experiencia / alta | Inventory pide IDs en `view.js` | errores y cambio constante de pantalla | selects buscables con nombre/estado / 34.1 |
| ADM-03 | Función desconectada / alta | Product e Inventory no se enlazan | no existe flujo Product → oferta | acción “crear oferta” preseleccionada / 34.1 |
| ADM-04 | Inconsistencia contractual / alta | Inventory acepta precio 0; Catalog/Cart exigen >0 | “activo” no significa publicable | mensaje/estado de publicación y validación alineada / 34.1 |
| ADM-05 | Defecto funcional histórico / alta, reparado | diff `e67245b`; `frame.state()` antes de `open()` | selector no abría en Media Library real | certificar navegador y bust de caché / 34.1 |
| ADM-06 | Inconsistencia contractual / media | backend de imagen sólo exige attachment | REST manual puede asociar no-imagen | validar MIME/`wp_attachment_is_image` / 34.1 |
| ADM-07 | Función desconectada / media | Product bulk routes sin checkboxes/UI | backend sin valor operativo | conectar acciones masivas / 34.5 |
| ADM-08 | Función desconectada / media | Inventory DELETE/rutas parciales sin UI | corrección de pareja torpe | borrado/desactivación segura en UI / 34.3 |
| ADM-09 | Defecto funcional potencial / alta | Inventory no valida Product/Store; sin FK | huérfanos y ofertas invisibles | validación referencial y política de borrado / 34.1/34.3 |
| ADM-10 | Inconsistencia contractual / alta | Store publicable ignora onboarding/aprobación | Store draft puede ser pública | decidir y documentar autoridad; no cambiar en silencio / 34.1 |
| ADM-11 | Problema de experiencia / media | lista Product sin imagen/catálogos/ofertas | difícil saber si está completo/publicado | columnas y diagnóstico de publicación / 34.4 |
| ADM-12 | Riesgo de escalabilidad / alta | Catalog agrega Products/Inventory en memoria | costo creciente y múltiples lecturas | read query/caché contractual medido / 34.5 |
| ADM-13 | Riesgo de escalabilidad / alta | alta Inventory una por una | precios/stock frecuentes no escalan | edición masiva/importación con validación / 34.5 |
| ADM-14 | Problema de experiencia / media | attachment borrado conserva ID en edición | preview fallida poco reparable | acción clara para limpiar/reemplazar / 34.1 |
| ADM-15 | Deuda documental / media | no hay matriz administrativa de publicación | el admin activa registros invisibles | ayuda contextual y README / 34.4 |
| ADM-16 | Observación no bloqueante / baja | Products no tiene delete | evita pérdida, pero no se explica | documentar inactivación como retiro / 34.4 |

## 17. Fricciones administrativas

- Hay que conocer de antemano qué plugin/pantalla administra categorías y marcas.
- Unidad no tiene ninguna UI.
- Los catálogos no se crean inline ni se refrescan automáticamente.
- Product e Inventory son pantallas aisladas; Store es una tercera pantalla.
- Inventory muestra IDs internos en vez de identidades humanas.
- No hay indicador “publicable/no publicable” ni explicación de la condición fallida.
- Product no muestra su imagen en el listado ni un enlace de preview público.
- No hay duplicación de Product/oferta ni creación de varias ofertas consecutivas.
- Acciones bulk implementadas no aparecen en la UI.
- Errores de referencia Inventory sólo afloran después como invisibilidad pública.
- Cambiar la pareja Product–Store requiere una operación no conectada.
- Los rótulos Inventory mezclan inglés (`Product ID`, `Price`, `Status`) con español.

## 18. Riesgos para catálogos grandes

Con 10 Products, el flujo es tolerable si los catálogos ya existen. Con 100, copiar
IDs y abrir formularios separados genera errores y una carga operativa alta. Con
1.000, los selects simples de catálogos pueden seguir cargando todos los términos,
la creación uno-a-uno de ofertas deja de ser viable y el agregador público recorre
lotes completos en PHP por petición.

Products pagina y busca, e Inventory pagina hasta 100, pero este último busca IDs y
estado, no nombres. No existe búsqueda remota para selectores, edición en tabla,
bulk de precio/stock, importación con dry-run, exportación, reporte de errores ni
auditoría de cambios. Múltiples minimarkets multiplican la pareja Inventory y el
costo manual. Cambios frecuentes de precio/stock sufren último-escritor-gana y no
ofrecen versionado ni trazabilidad.

## 19. Mejoras recomendadas

1. Cerrar primero el recorrido Product → Inventory con selects remotos por nombre,
   sólo opciones elegibles y preselección del Product actual.
2. Añadir un diagnóstico de publicabilidad que enumere Product/Inventory/Store,
   stock y precio, sin crear una nueva autoridad persistente.
3. Certificar Media Library en navegador interactivo, validar MIME en backend,
   aclarar attachment eliminado y asegurar invalidación de caché de assets.
4. Proveer CRUD VeciAhorra de categorías, marcas y unidades con política explícita
   para términos usados; permitir creación inline y refresco del selector.
5. Decidir formalmente si onboarding/aprobación condiciona Store pública. Hasta
   entonces no cambiar la consulta, porque alteraría el contrato vigente.
6. Conectar las operaciones bulk existentes y diseñar importación idempotente con
   previsualización, validación por fila y rollback.
7. Optimizar el read model público sólo después de medir, conservando DTO y reglas.

No se recomienda fusionar Product e Inventory, crear Offer/Publication ni reutilizar
productos WooCommerce como nueva autoridad. La separación actual permite un Product
común con precios/stocks independientes por minimarket.

## 20. Propuesta de división del Hito 34.1

La propuesta inicial “34.1 sólo imágenes” queda reemplazada por esta secuencia:

1. **34.1 — Cierre mínimo del flujo Product → Inventory.** Certificación real de
   Media Library/caché, validación de imagen, selects buscables Product/Store,
   validación referencial y enlace “crear oferta”. Incluir decisión documentada —no
   implícita— sobre Store activa versus aprobada.
2. **34.2 — Administración de categorías, marcas y unidades.** CRUD, reglas al
   estar en uso, creación inline, refresco y permisos; mantener taxonomías actuales.
3. **34.3 — Operación completa de Inventory.** nombres en listas, corrección/borrado
   seguro, varias ofertas consecutivas y concurrencia básica.
4. **34.4 — Publicabilidad y previsualización.** diagnóstico por Product, thumbnail,
   conteo de ofertas, enlace público y documentación de estados.
5. **34.5 — Escala.** bulk conectado, importación/exportación con dry-run, edición
   masiva de precio/stock y optimización medida del read model.
6. **34.6 — Certificación integral.** recorrido interactivo con archivo real,
   permisos, fallbacks, responsive admin y regresión pública.

Esta secuencia desbloquea el flujo antes de ampliar CRUD, mantiene separadas las
autoridades y evita que una mejora de UI esconda inconsistencias de publicación.

## 21. Alcance negativo

Esta auditoría no modificó PHP, JavaScript, CSS, pruebas, migraciones ni datos. No
creó endpoints, tablas o entidades. No cambió contratos públicos, Store, Cart,
Checkout, Payments, WooCommerce ni la navegación. No propone guardar un estado
Publication derivado, inventar Offer ni transferir autoridad a WooCommerce. No se
realizó commit ni push.

## 22. Conclusión y veredicto

**Veredicto:** la arquitectura base es válida y los contratos centrales funcionan,
pero la administración todavía es una colección de capacidades parciales, no un
flujo de catálogo operativo de extremo a extremo. Product y la imagen ya pueden
persistirse; el defecto JavaScript que bloqueaba Media Library está identificado,
fechado y reparado en el estado auditado. La mayor deuda actual es conectar los
catálogos y la oferta Inventory con identidades humanas y validación referencial.

El sistema puede sostener el catálogo pequeño que ya existe, pero no permite a un
administrador construir desde cero ni mantener rápidamente 100–1.000 Products con
múltiples minimarkets. El cierre correcto no es reescribir el dominio: es hacer
utilizable lo ya construido, explicitar las reglas de publicación y luego agregar
operaciones masivas.

### Evidencia ejecutada

- `tests/manual/product-catalog-validation-test.php`: OK.
- `tests/manual/product-catalog-routes-test.php`: 5/5.
- `tests/manual/product-bulk-route-test.php`: 16/16.
- `tests/manual/inventory-service-test.php`: PASS.
- `tests/manual/catalog-public-api-test.php`: PASS.
- `tests/manual/catalog-public-detail-test.php`: PASS.
- `tests/manual/catalog-public-categories-test.php`: PASS.
- `tests/manual/public-offer-selection-test.php`: PASS.
- `tests/manual/public-cart-test.php`: PASS.
- `tests/manual/product-media-selector-test.html`: no ejecutado; Chrome y Edge
  abortaron antes de cargarlo por error fatal GPU del entorno.

Las pruebas PHP que usan fixtures incluyen limpieza; las consultas adicionales
fueron exclusivamente de lectura. El único artefacto de esta auditoría es este
documento.
