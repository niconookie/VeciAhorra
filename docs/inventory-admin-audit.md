# Serie 36.5.0 — Auditoría arquitectónica de Inventory Admin

## 1. Resumen ejecutivo

Esta auditoría describe el estado del módulo administrativo de Inventory en el
commit `e724abf0a1d05adde0f4e377545fa64576cafbbb` del 23 de julio de 2026. Es una
revisión estática de backend, frontend, REST, persistencia, navegación y de los
consumidores de Inventory. No incluye cambios de implementación.

Inventory representa una **oferta comercial de un Product en un Store**. La
tabla `inventory` es autoridad sobre la asociación inmutable
`product_id + minimarket_id`, el precio vigente, el stock disponible y el estado
operativo de esa oferta. Product sigue siendo autoridad sobre la identidad y el
estado del producto; Store, sobre la identidad y el estado del minimarket.

La base transaccional es razonable:

- existe unicidad durable por Product y Store;
- la creación usa transacción, bloqueos de Product, Store y del rango de
  Inventory, además de traducir duplicados;
- el bloqueo y liberación de stock usan actualizaciones atómicas;
- Cart revalida conjuntamente Inventory, Product y Store;
- Reservations descuenta y devuelve stock;
- Orders conserva los identificadores de Product e Inventory y congela el
  precio unitario y el subtotal.

La administración, sin embargo, sigue siendo un CRUD técnico:

- el listado devuelve `SELECT *` de Inventory y muestra IDs, no nombres;
- la búsqueda solo opera sobre IDs y estado, aunque la UI no lo explica;
- no hay detalle administrativo propio ni diagnóstico de disponibilidad;
- no existe acción de eliminación en la UI aunque REST permite borrado físico;
- no hay inspección de referencias antes de eliminar;
- no hay CAS para precio, stock, estado ni eliminación;
- la UI cambia activación junto con precio/stock mediante el update genérico y
  no ofrece una acción de publicación separada;
- la visibilidad pública se calcula fuera de Inventory y no se expone en su
  read model;
- la navegación contextual funciona, pero Product y Store tienen contratos de
  URL distintos y los retornos son asimétricos.

La prioridad de la siguiente serie debería ser construir un **read model
administrativo agregado y explícito**, definir un lifecycle operativo con CAS y
hacer segura la eliminación. No se recomienda duplicar nombres o estados en la
tabla `inventory`, ni convertir Inventory en autoridad de Product o Store.

### Veredicto

| Área | Estado | Diagnóstico |
|---|---|---|
| Persistencia base | Sólida con brechas | Unicidad y mutación atómica de stock; los esquemas revisados no declaran claves foráneas ni existe CAS administrativo |
| REST | Completo como CRUD | Contratos redundantes y eliminación peligrosa |
| Listado | Funcional pero técnico | Dos consultas por carga, datos crudos y baja capacidad diagnóstica |
| Creación | Bien protegida ante duplicados | Selectores contextuales correctos; admite entidades no públicas deliberadamente |
| Edición | Funcional | Sin concurrencia optimista ni contexto humano |
| Eliminación | Incompleta y riesgosa | Solo REST, hard delete, sin inspección de Cart/Reservations/Orders |
| Publicación | Implícita | Surge de cinco autoridades/condiciones, no de un comando propio |
| Navegación | Parcialmente integrada | Buenos deep links; historial, retorno y detalle no están unificados |
| Accesibilidad/responsive | Base adecuada | Falta semántica y contexto en tabla; scroll horizontal obligatorio |
| Rendimiento | Aceptable a escala pequeña | Búsqueda no sargable, doble consulta y catálogo con agregación en PHP |

## 2. Alcance y método

Se inspeccionaron:

- `app/Modules/Inventory/**`;
- `assets/admin/js/modules/inventory/**`;
- `assets/admin/css/inventory.css`;
- `app/Database/Schemas/InventorySchema.php`;
- composición y registro en `app/Core/Application.php`;
- contratos consumidores en Products, Stores, Catalog, Cart, Reservations y
  Orders;
- navegación Product → Inventory y Store → Inventory;
- pruebas manuales que fijan los contratos de Inventory y sus contextos.

La auditoría distingue:

- **autoridad**: dato persistido que el módulo propietario puede decidir;
- **derivado**: cálculo reproducible desde autoridades;
- **decorativo**: dato presentado para orientación, sin valor decisorio.

## 3. Arquitectura actual

### 3.1 Capas

```text
WordPress Admin
  InventoryPage + Views/index.php
        │ configuración JSON
        ▼
app.js ── context.js
  │
  ├── store.js (estado de lista, contexto y formulario)
  ├── view.js (DOM, tabla, formulario, mensajes)
  ├── product-selector.js / store-selector.js
  └── api.js
        │ REST veciahorra/v1
        ▼
InventoryRoutes
        ▼
InventoryController
        ▼
InventoryService
  ├── InventoryReferenceValidator ── ProductService / StoreService
  ├── InventoryCreationCoordinator ── transacción y locks
  └── InventoryRepository
        ▼
tabla inventory

Reservations ── InventoryLockService ── InventoryLockRepository
Cart ── CartRepository ── JOIN inventory/products/stores
Catalog ── InventoryRepository + StoreRepository + ProductRepository
Products Admin ── ProductRepository.adminOffers()
Orders/Reservations/Cart ── referencias a Inventory y snapshots monetarios
```

### 3.2 Composición

`Application` enlaza `InventoryRepositoryInterface` con
`InventoryRepository`, construye `InventoryService` con el repositorio y
`InventoryReferenceValidator`, registra `InventoryPage` y monta
`InventoryRoutes` en `rest_api_init`.

La abstracción de repositorio solo cubre CRUD administrativo. Métodos usados por
el catálogo (`findActiveByProductIds`) y el servicio de locks tienen contratos
concretos separados. Esto deja una frontera incompleta: parte del módulo depende
de interfaz y parte de clases concretas.

### 3.3 Modelo persistido

`inventory` contiene:

| Campo | Semántica actual | Autoridad |
|---|---|---|
| `id` | Identidad durable de la oferta | Inventory |
| `product_id` | Product ofrecido; inmutable después de crear | Referencia a Product |
| `minimarket_id` | Store que ofrece; inmutable después de crear | Referencia a Store |
| `price` | Precio comercial vigente | Inventory |
| `stock` | Stock disponible persistido | Inventory |
| `status` | `active` o `inactive` | Inventory |
| `created_at` | Fecha de creación | Inventory |
| `updated_at` | Última mutación | Inventory |

Hay restricción única `(product_id, minimarket_id)` e índices individuales para
Product, Store y status. El esquema no declara claves foráneas.

## 4. Responsabilidades y autoridades

### 4.1 Inventory

Le pertenecen:

- identidad de la oferta;
- asociación Product–Store durante la creación;
- precio;
- stock disponible;
- activación/inactivación local de la oferta;
- unicidad de la asociación;
- operaciones atómicas de descuento y reposición de stock;
- timestamps de la oferta.

No le pertenecen:

- nombre, SKU, categorías, marca, unidad, descripción o estado de Product;
- nombre, dirección, onboarding, aprobación o lifecycle de Store;
- decisión final de visibilidad del Product o Store;
- snapshots históricos de Cart/Order;
- estado de Reservation u Order.

### 4.2 Product

Product es autoridad de:

- identidad, nombre, slug, SKU y contenido;
- taxonomías y media;
- lifecycle `draft | active | inactive`;
- posibilidad de participar públicamente.

Los conteos de ofertas, stock público acumulado, precio público mínimo y
`publicly_available` que expone Product Admin son derivados desde Inventory,
Store y Product. No deben persistirse como autoridad en Inventory.

### 4.3 Store

Store es autoridad de:

- identidad y `business_name`;
- localización y datos operacionales;
- lifecycle compuesto por `status`, `onboarding_status` y `approved_at`;
- estados persistidos `pending | active | inactive | rejected` y su
  interpretación canónica (`draft`, `in_review`, `rejected`,
  `approved_inactive`, `active` o `invalid`);
- condición de minimarket público.

El total de ofertas de un Store y sus diagnósticos de disponibilidad son
derivados. En la implementación pública observada, Catalog considera al Store
por `status = active`; no vuelve a exigir que la combinación completa de
`onboarding_status` y `approved_at` corresponda al lifecycle canónico `active`.

### 4.4 Clasificación de información

| Información | Tipo | Fuente |
|---|---|---|
| `price`, `stock`, `inventory.status` | Autoridad | Inventory |
| `product_id`, `minimarket_id` | Referencia autoritativa de la relación | Inventory, con identidad resuelta por Product/Store |
| Nombre/estado de Product | Autoridad externa | Product |
| Nombre/estado/lifecycle/ubicación de Store | Autoridad externa | Store |
| Oferta públicamente disponible | Derivada | Product.status + Inventory + Store.status + precio + stock |
| Razón de no disponibilidad | Derivada | Las mismas autoridades |
| Precio mínimo/máximo y cantidad de tiendas | Derivada | Conjunto de ofertas públicas |
| Stock público de Product | Derivada | Suma de ofertas públicas |
| Nombre en selector, badge y enlaces | Decorativa | Proyección de Product/Store |
| `unit_price_snapshot` de Cart | Snapshot operacional | Cart, capturado desde Inventory y revalidado |
| `unit_price` de Order | Snapshot histórico | Order |
| IDs copiados en Cart/Reservation/Order Item | Snapshot/referencia histórica | Módulo consumidor |

## 5. Flujo administrativo existente

### 5.1 Product → Inventory

1. Products entrega `inventoryUrl` a sus aplicaciones de lista y detalle.
2. `navigation.js` genera:
   - listado: `admin.php?page=veciahorra-inventory&product_id={id}`;
   - creación:
     `admin.php?page=veciahorra-inventory&product_id={id}&action=create`.
3. Inventory valida estrictamente duplicados, sintaxis array, ID canónico y
   acción.
4. Carga `GET /products/{id}` para resolver nombre y estado.
5. En listado fija el filtro `product_id`; en creación bloquea Product y permite
   elegir Store.
6. Cancelar una creación contextual vuelve al listado contextual, no al detalle
   de Product.

Fortalezas: deep link reproducible, contexto visible, Product bloqueado y
respuesta inválida rechazada.

Fricciones:

- se abandona el detalle de Product y el retorno no lo recupera;
- el listado solo vuelve a mostrar Product ID en cada fila;
- no existe enlace desde una fila a Product ni Store;
- el contexto se resuelve mediante una llamada adicional aunque un read model
  agregado podría devolver la decoración necesaria.

### 5.2 Store → Inventory

1. Store detail entrega URLs de listado y creación con:
   `minimarket_id={id}&return_store_id={id}`.
2. Inventory valida que ambos IDs coincidan.
3. Carga `GET /stores/{id}` y fija el filtro o Store del formulario.
4. Cancelar en contexto Store navega a
   `admin.php?page=veciahorra-stores&action=view&id={id}`.
5. El panel contextual ofrece “Volver al minimarket”.

Este flujo conserva mejor el origen que Product, pero usa un parámetro
`return_store_id` redundante: el retorno se reconstruye con el mismo
`minimarket_id`. El parámetro se usa como prueba de integridad de contexto, no
como URL de retorno real.

### 5.3 Inventory → creación

1. Sin contexto, “Nuevo inventario” abre el formulario dentro de la SPA sin
   cambiar la URL.
2. Product y Store se buscan con selectores remotos, desde dos caracteres.
3. El cliente valida IDs positivos, precio no negativo, stock entero no negativo
   y estado.
4. `POST /inventory` vuelve a validar y normalizar.
5. El servicio valida las referencias, bloquea Product, Store y rango, revalida,
   detecta duplicados y crea.
6. El frontend consulta inmediatamente `GET /inventory/{id}` y permanece en el
   formulario editado con mensaje de éxito.

Redundancias justificadas: validación cliente/servidor y preconsulta/índice
único. Redundancia mejorable: después de adquirir los locks, Product se valida
mediante servicio y además se reconsulta `FOR UPDATE`; Store se valida una vez
mediante su servicio dentro de la transacción.

### 5.4 Inventory → edición

1. “Editar” abre el formulario en la misma URL.
2. `GET /inventory/{id}` devuelve la fila cruda.
3. Product y Store quedan inmutables; solo se editan precio, stock y status.
4. Se envía un único `PATCH /inventory/{id}` con los tres valores.
5. El servicio vuelve a comprobar que Product y Store existan y tengan un estado
   reconocido.
6. Tras guardar se vuelve a consultar el detalle.

No se representa el formulario en URL, no hay deep link a edición y recargar
pierde el estado. Tampoco se compara `updated_at`: dos administradores o un
proceso de reservas pueden sobrescribirse sin advertencia.

### 5.5 Inventory → eliminación

El backend ofrece `DELETE /inventory/{id}` y ejecuta hard delete después de
comprobar que la fila existe. La UI no expone la acción.

No se inspeccionan Cart Items, Reservations ni Order Items. Como el esquema no
declara claves foráneas, el endpoint puede dejar referencias históricas u
operacionales huérfanas. Tampoco hay CAS, confirmación semántica, clasificación
de bloqueo ni una política que derive una eliminación insegura hacia la
inactivación ya disponible. Debe considerarse un contrato interno inseguro hasta
definir su política.

### 5.6 Inventory → publicación

No existe comando, endpoint ni estado “published”. Cambiar `status` a `active`
solo habilita una de las condiciones.

Para Catalog y para el diagnóstico de Product Admin, una oferta se considera
pública si:

```text
Product.status = active
AND Inventory.status = active
AND Store.status = active
AND Inventory.stock > 0
AND Inventory.price > 0
```

Por tanto:

- precio `0` y stock `0` son válidos administrativamente, pero no publicables;
- una oferta activa de Product draft/inactive o Store pending/inactive/rejected
  sigue siendo válida como dato administrativo, pero no pública;
- el cambio de Product o Store puede publicar/despublicar ofertas sin mutar
  Inventory;
- reservar stock puede retirar una oferta del catálogo cuando llega a cero;
- liberar una reserva puede hacerla reaparecer.

Esta regla usa el `status` crudo de Store. No verifica por sí sola que
`onboarding_status = complete` ni que `approved_at` sea una fecha aprobatoria
válida. Un Store inconsistente que conserve `status = active` puede ser tratado
como público por `StoreRepository::findActiveByIds`, Catalog y otros
consumidores basados solo en ese método. Es una brecha entre el lifecycle
canónico de Store y la regla comercial actualmente implementada.

### 5.7 Inventory → catálogo público

Catalog:

1. obtiene Products activos por lotes;
2. obtiene Inventory activo por lotes;
3. descarta IDs inválidos, stock no positivo y precio no positivo;
4. carga Stores activos por lotes;
5. agrupa en PHP por Product;
6. ordena ofertas por precio ascendente, stock descendente e ID;
7. deriva precio mínimo/máximo y cantidad de minimarkets.

El catálogo no usa el read model de Inventory Admin ni el diagnóstico de
Product Admin. Ambos reproducen la regla pública por separado.

## 6. Estados y disponibilidad

### 6.1 Matriz de estados

| Dimensión | Estados reconocidos | Efecto |
|---|---|---|
| Inventory | `active`, `inactive` | Solo `active` puede ser público |
| Product | `draft`, `active`, `inactive` | Solo `active` puede ser público |
| Store `status` | `pending`, `active`, `inactive`, `rejected` | La regla pública observada exige `active` |
| Store lifecycle canónico | `draft`, `in_review`, `rejected`, `approved_inactive`, `active`, `invalid` | Deriva de status + onboarding + aprobación; Inventory no lo evalúa |
| Reservation | `active`, `released`, `expired`, `consumed` | `active` ya redujo el stock persistido |
| Order | incluye `reserved` y lifecycle propio | Conserva IDs referenciales y snapshots monetarios; no gobierna visibilidad |
| Disponibilidad | derivada, no persistida | Exige estados activos, precio > 0 y stock > 0 |

`InventoryReferenceValidator` considera compatibles todos los estados
reconocidos de Product y todos los valores reconocidos de `Store.status`. No
evalúa `onboarding_status`, `approved_at` ni `lifecycle_state`. Esto permite
preparar ofertas no públicas, pero también significa que “compatible” no
equivale a lifecycle Store válido ni a entidad publicable.

### 6.2 Lifecycle real

Inventory no tiene una máquina de estados formal. `active ↔ inactive` se acepta
libremente y el update genérico también puede cambiar precio y stock en el mismo
request. Los endpoints especializados `/price`, `/stock` y `/status` no agregan
reglas diferentes.

No existen:

- transición explícita de publicación;
- motivo de inactivación;
- política para stock bajo reserva;
- versionado;
- soft delete;
- auditoría de quién cambió qué;
- protección frente a estados externos que cambian simultáneamente.

### 6.3 Semántica de stock

El nombre `stock` oculta una decisión importante: Reservations lo decrementa al
bloquear y lo incrementa al liberar. En la práctica es **stock actualmente
disponible**, no necesariamente stock físico total. La edición administrativa
escribe un valor absoluto sobre ese mismo campo mientras pueden existir reservas
activas.

Este acoplamiento crea el mayor riesgo de consistencia: una edición manual puede
ignorar unidades reservadas, y una liberación posterior puede incrementar una
base ya reemplazada. La siguiente serie debe fijar la semántica antes de diseñar
el formulario.

## 7. Read models y DTO

### 7.1 DTO actual de listado y detalle

`GET /inventory` y `GET /inventory/{id}` exponen directamente filas del
repositorio:

```text
id
product_id
minimarket_id
price
stock
status
created_at
updated_at
```

El cliente exige todos salvo `created_at`; normaliza nombres a camelCase solo en
su estado local. Listado y detalle comparten accidentalmente el mismo shape.

Problemas:

- acoplamiento de REST al esquema físico por `SELECT *`;
- ausencia de nombres, estados y existencia de Product/Store;
- ausencia de disponibilidad y motivo;
- ausencia de versión CAS;
- ausencia de referencias que condicionan eliminación;
- `created_at` viaja pero no se muestra;
- Product/Store IDs se repiten en cada fila sin decoración;
- el frontend acepta `price` como número o string, evidenciando contrato débil;
- el detalle no aporta más información que una fila del listado.

### 7.2 Otros DTO relacionados

Product Admin ya posee un read model mucho más rico por oferta:

- `store_name`, `store_status`;
- `publicly_available`;
- `availability_reason`;
- precio, stock y `updated_at`;
- agregados de ofertas;
- inspección de Inventory, Cart, Reservations y Order Items;
- lifecycle y `expected_updated_at` de Product.

Store detail expone enlaces contextuales, pero Inventory no consume un read
model de ofertas decorado por Store.

Catalog publica un DTO comercial deliberadamente reducido:

- `inventory_id`, `minimarket_id`, nombre del minimarket;
- precio y stock;
- agregados por Product.

Cart devuelve nombres de Product y Store junto con snapshots, pero revalida
autoridades antes de agregar o actualizar.

### 7.3 Campos propuestos para el read model administrativo

La recomendación no implica añadir columnas a `inventory`. Se propone un DTO de
consulta:

```text
identity
  id, created_at, updated_at

product
  id, name, sku, status, exists

store
  id, name, status, lifecycle_state, exists

offer
  price, available_stock, status

availability
  publicly_available
  reason_code
  blocking_dimensions[]

references
  cart_items_total
  active_reservations_total
  reservations_total
  order_items_total
  deletion_classification

lifecycle
  status
  allowed_transitions
  expected_updated_at
  deletion_allowed
```

Para listado bastan identidad, decoración, oferta, disponibilidad resumida y un
indicador de referencias. El detalle debe agregar conteos y diagnóstico. Los
nombres y estados son derivados en lectura, no copias persistidas.

### 7.4 Campos redundantes o que no deben agregarse

- No duplicar `product_name` ni `store_name` en `inventory`.
- No persistir `publicly_available`: cambia con tres módulos y con stock.
- No convertir `unit_price_snapshot` de Cart/Order en precio vigente.
- No enviar `created_at` en un DTO si no se usa; si es operacionalmente útil,
  mostrarlo.
- No mantener dos shapes implícitos que casualmente son iguales; versionar
  explícitamente list item y detail.

## 8. Contratos

### 8.1 REST de Inventory

Todos exigen `manage_options`.

| Método y ruta | Entrada | Salida exitosa | Observación |
|---|---|---|---|
| `GET /inventory` | `page`, `per_page`, `search`, `product_id`, `minimarket_id`, `status` | filas + meta | `per_page` 20 por defecto, máximo 100 |
| `POST /inventory` | Product, Store, price, stock, status | `{id}` con 201 | stock 0 y active por defecto |
| `GET /inventory/{id}` | ID positivo | fila cruda | no es detalle agregado |
| `PUT/PATCH /inventory/{id}` | subconjunto de price, stock, status | `{id, updated}` | Product/Store inmutables |
| `DELETE /inventory/{id}` | ID positivo | `{id, deleted}` | hard delete sin referencias/CAS |
| `PATCH /inventory/{id}/price` | price | `{id, price, updated}` | regla idéntica a update |
| `PATCH /inventory/{id}/stock` | stock | `{id, stock, updated}` | regla idéntica a update |
| `PATCH /inventory/{id}/status` | status | `{id, status, updated}` | no es lifecycle formal |

Errores:

- 400 para JSON inválido o content type incorrecto;
- 422 `validation_error`;
- 404 `inventory_not_found`;
- 500 `persistence_error` o `internal_error`.

La forma común es `{success, data}` o `{success:false, error}`. Algunos errores
de referencia añaden `details.field` y `details.reason`.

Observaciones de semántica HTTP y respuesta:

- `PUT` se procesa exactamente como un update parcial, no como reemplazo total;
- los tres endpoints de campo reutilizan `InventoryUpdateRequest`, pero luego
  solo aplican su campo objetivo: otros campos permitidos presentes en el mismo
  body se validan y se descartan;
- update y delete pueden responder éxito con `updated:false` o `deleted:false`
  si la escritura no afectó filas después de la lectura previa;
- el duplicado de creación se traduce al contrato
  `validation_error`/`inventory_duplicate`, no a 409;
- no hay `expected_updated_at`, ETag ni otra precondición de escritura.

### 8.2 Validaciones

Lista:

- página y tamaño enteros positivos;
- `per_page <= 100`;
- búsqueda textual no vacía tras trim;
- Product ID positivo;
- Store ID positivo y canónico;
- status conocido.

Hay una inconsistencia: `product_id` acepta representaciones con ceros a la
izquierda, mientras `minimarket_id` exige forma canónica.

Creación:

- IDs positivos;
- precio numérico finito y no negativo;
- stock entero no negativo;
- estado conocido;
- Product y Store existentes con un estado reconocido;
- unicidad Product–Store.

Update:

- rechaza Product y Store;
- requiere al menos un campo permitido;
- repite reglas de precio, stock y estado;
- revalida referencias ya inmutables.

Campos desconocidos no se rechazan de forma general: en create se ignoran y en
update se ignoran si además hay al menos un campo permitido. El contrato no es
estricto.

### 8.3 Servicios y repositorios

| Contrato | Responsabilidad |
|---|---|
| `InventoryService` | CRUD, reglas escalares, referencias y traducción parcial de persistencia |
| `InventoryReferenceValidator` | Existencia y estado reconocido de Product/Store |
| `InventoryCreationCoordinator` | Transacción, locks y revalidación durable de creación |
| `InventoryLockService` | Disponibilidad, decremento, incremento y confirmación nominal |
| `InventoryRepositoryInterface` | CRUD y búsqueda por asociación |
| `InventoryRepository` | CRUD, filtros y lectura activa para Catalog |
| `InventoryLockRepository` | Mutaciones atómicas del campo stock |

`commitStock()` solo verifica que Inventory exista: el descuento ya ocurrió al
reservar. El nombre sugiere una mutación que no existe y debe documentarse o
renombrarse en una futura implementación.

### 8.4 Lifecycle y CAS

Inventory no tiene contrato de lifecycle ni CAS. `updated_at` se actualiza, pero
no participa en `WHERE`. Product sí ofrece un precedente local con
`expected_updated_at`, inspección de referencias y repositorios de transición.

La creación sí controla concurrencia:

- lock de Product;
- lock de Store;
- lock del rango Product–Store;
- restricción única;
- transacción/savepoint.

Esa protección no se extiende a update, delete ni edición concurrente con
reservas.

### 8.5 Contratos externos consumidos por el frontend

Inventory Admin depende de:

- `GET /products/{id}` para contexto;
- `GET /products/search` para selector;
- `GET /stores/{id}` para contexto;
- `GET /stores` para selector.

Cada respuesta se valida estructuralmente en JavaScript. Esto es positivo, pero
crea conocimiento duplicado de estados y DTO de Product/Store dentro de
Inventory.

## 9. Navegación

### 9.1 URLs canónicas actuales

| Contexto | URL |
|---|---|
| Inventory global | `admin.php?page=veciahorra-inventory` |
| Por Product | `...&product_id={id}` |
| Crear por Product | `...&product_id={id}&action=create` |
| Por Store | `...&minimarket_id={id}&return_store_id={id}` |
| Crear por Store | `...&action=create&minimarket_id={id}&return_store_id={id}` |
| Retorno Store | `admin.php?page=veciahorra-stores&action=view&id={id}` |

### 9.2 Evaluación

Positivo:

- URLs de lista y creación son compartibles;
- se rechazan parámetros duplicados, arrays y contextos ambiguos;
- los builders de ambos contextos restringen el origen; el builder de Store
  además exige `/admin.php`, `page=veciahorra-inventory` y una base sin
  parámetros adicionales;
- contexto Product o Store fija el filtro y el selector correcto;
- Store conserva retorno explícito al detalle.

Brechas:

- crear/editar globalmente dentro de la SPA no modifica URL;
- no hay `inventory_id` deep link;
- back/forward del navegador no representa vistas ni filtros;
- filtros aplicados no se sincronizan con query string;
- Product no tiene retorno simétrico a su detalle;
- `return_store_id` duplica `minimarket_id`;
- los builders de Product en Products e Inventory no comprueban pathname,
  `page=veciahorra-inventory` ni el conjunto de parámetros de la URL base, a
  diferencia del builder de Store;
- el error de contexto Store se guarda como `invalid_product_context`;
- el mensaje de contexto inválido dice Product incluso para algunos fallos de
  Store;
- no hay enlaces por fila a Product, Store o detalle de Inventory;
- el botón global “Nuevo inventario” es un `<button>`, no un enlace recuperable.

### 9.3 Dirección recomendada

Adoptar una sola gramática:

```text
admin.php?page=veciahorra-inventory
  [&product_id={id} | &minimarket_id={id}]
  [&action=create | &action=view&inventory_id={id} | &action=edit&inventory_id={id}]
  [&return_to={token seguro o contexto tipado}]
```

La URL debe ser parseada por backend y entregada normalizada al frontend, o
compartir un contrato único. No conviene aceptar URLs arbitrarias de retorno.

## 10. Consultas

### 10.1 Inventory Admin

Cada carga de listado ejecuta:

1. `SELECT * ... WHERE ... ORDER BY id DESC LIMIT/OFFSET`;
2. `SELECT COUNT(*) ...` con los mismos filtros.

No hay JOIN ni N+1 en el listado porque no se resuelven Product o Store. Esa
economía produce un read model insuficiente. En contexto se suma un request REST
y una consulta a Product o Store.

Las consultas de filas y total no comparten transacción/snapshot: una escritura
concurrente puede producir temporalmente una página y un `meta.total` que no
correspondan entre sí. El frontend corrige una página fuera de rango con una
segunda carga, pero no resuelve otras diferencias de snapshot.

El detalle ejecuta una consulta `SELECT * WHERE id`. Crear ejecuta múltiples
lecturas y locks deliberados. Editar ejecuta:

1. lectura de Inventory;
2. lectura de Product;
3. lectura de Store;
4. update;
5. el frontend vuelve a consultar Inventory.

### 10.2 Búsqueda y filtros

La búsqueda usa:

```text
CAST(id AS CHAR) LIKE '%term%'
OR CAST(product_id AS CHAR) LIKE '%term%'
OR CAST(minimarket_id AS CHAR) LIKE '%term%'
OR status LIKE '%term%'
```

Es no sargable por casts y wildcard inicial. No busca nombres, SKU ni
`business_name`. El filtro `status` ya cubre el único campo textual buscado.

Los índices individuales ayudan a igualdad por Product, Store o status. La
unicidad `(product_id, minimarket_id)` sirve para contexto Product y asociación,
pero no necesariamente para `status + id`, contexto Store ordenado por ID o
paginación profunda. Cualquier índice nuevo debe justificarse con `EXPLAIN` y
volumen real; esta auditoría no propone modificar el esquema.

### 10.3 Consultas repetidas y agregados

- listado y count reconstruyen el mismo `WHERE`;
- Product Admin vuelve a consultar ofertas y Stores para su detalle;
- Catalog vuelve a implementar la disponibilidad;
- Cart tiene su propio JOIN autoritativo de Inventory, Product y Store;
- no existe un proyector compartido de disponibilidad/reason code;
- Product list agrega todas las ofertas por Product en una subconsulta;
- Catalog pagina candidatos e Inventory por lotes y agrupa/ordena en PHP.

No se observa N+1 por fila dentro de Inventory Admin. Sí hay repetición de reglas
y potenciales pasadas completas por lotes en Catalog. `READ_BATCH_SIZE` acota
cada query, no el total leído.

`InventoryRepository::paginate`, `count`, `find` y
`findActiveByProductIds` no comprueban `last_error`; una falla SQL puede
degradarse a lista vacía, total cero o no encontrado en vez de
`persistence_error`. Las lecturas `hasAvailableStock` y `exists` del repositorio
de locks presentan una ambigüedad similar al devolver `false`. En cambio,
`findByProductAndMinimarket` sí traduce `last_error`.

### 10.4 Cache

Inventory Admin no cachea, lo que evita invalidación obsoleta tras editar. Catalog
mantiene mapas de taxonomías solo durante la instancia/request; no hay cache
persistente de ofertas o disponibilidad. Antes de agregar cache debe existir una
política de invalidación para:

- cambio de precio/stock/status;
- transición de Product;
- transición de Store;
- lock/release de Reservation;
- eliminación.

## 11. Rendimiento

### 11.1 Riesgos

- OFFSET se degrada en páginas profundas.
- `COUNT(*)` exacto duplica trabajo en cada recarga.
- lista y count pueden observar estados distintos bajo escrituras concurrentes.
- búsqueda con casts y `%term%` fuerza scans.
- errores SQL de varias lecturas pueden quedar enmascarados como resultados
  vacíos, cero o ausencia.
- `SELECT *` transporta campos no utilizados y acopla al esquema.
- enriquecer ingenuamente cada fila con servicios causaría N+1.
- Catalog puede recorrer Products e Inventory activos completos antes de
  paginar el resultado público.
- Product list agrega Inventory/Store globalmente antes de aplicar la página de
  Products, según el plan elegido por MySQL.

### 11.2 Oportunidades de consulta

- construir listado con JOINs acotados a Product y Store en una única consulta;
- seleccionar columnas explícitas;
- calcular disponibilidad y razón con una proyección compartida;
- separar búsqueda libre por nombre/SKU/Store de filtros exactos por ID;
- considerar keyset pagination para grandes volúmenes;
- evitar count exacto cuando la UX solo necesite `has_next`, o cachearlo con una
  estrategia explícita;
- medir `EXPLAIN` para combinaciones Product/Store/status antes de índices;
- consultar agregados de referencias en batch o solo en detalle, nunca por fila.

## 12. UX administrativa

### 12.1 Listado

La tabla muestra ID, Product ID, Minimarket ID, Price, Stock, Status, Updated At
y Editar. Tiene paginación, tamaños 20/50/100, loading, empty, retry y scroll
horizontal.

Déficits operacionales:

- IDs obligan al administrador a conocer claves internas;
- no muestra nombres, SKU, estado de Product o Store;
- no muestra disponibilidad pública ni razón de bloqueo;
- no hay alertas por stock cero, precio cero, referencia ausente o entidad
  inactiva;
- no hay ordenamiento;
- no hay acciones rápidas seguras;
- no muestra fecha de creación;
- no indica referencias que bloquean eliminación;
- usa etiquetas inglesas mezcladas con español.

### 12.2 Filtros y búsqueda

Los filtros son útiles pero técnicos. “Buscar inventario” no informa que solo
busca IDs/status. Product y Store se filtran por input numérico, mientras en
creación sí existen selectores humanos. No hay filtros por disponibilidad,
problema operacional, stock, precio o estado externo.

### 12.3 Formulario

Fortalezas:

- Product y Store se seleccionan con autocompletado accesible;
- teclado, `aria-activedescendant`, estados live y cancelación de búsquedas;
- referencias inmutables al editar;
- validación cliente y servidor;
- feedback de carga, error y éxito;
- protección contra doble guardado.

Brechas:

- “Price”, “Status”, “Product ID” y “Updated At” no están localizados;
- no explica que precio/stock cero retiran la oferta pública;
- no muestra estado ni nombre de Product/Store al editar, solo IDs;
- no advierte que stock es afectado por Reservations;
- no hay detección de cambios externos;
- no hay resumen del efecto público antes de guardar;
- guardar los mismos valores puede responder `updated:false`, pero la UI muestra
  éxito indistinguible;
- después de crear permanece en edición sin URL durable.

### 12.4 Acciones y eliminación

Solo existe Editar. La ausencia de Delete en UI evita parcialmente un contrato
backend riesgoso, pero no lo corrige. “Publicar” tampoco existe como acción
explicada; el administrador debe inferir la regla.

### 12.5 Accesibilidad

Positivo:

- regiones `role=status`, `aria-live`, `role=alert`;
- headers con `scope=col`;
- estados busy;
- foco en errores y al abrir formulario;
- selectores operables por teclado;
- controles con label.

Mejoras:

- agregar `<caption>` o descripción útil a la tabla;
- asociar todos los errores con `aria-describedby` de forma consistente;
- anunciar cantidad de resultados y cambio de página;
- conservar foco lógico al volver del formulario;
- indicar texto completo, no solo color, para disponibilidad;
- revisar que notices de error de listado tengan `role=alert` y foco;
- eliminar mojibake visible en textos si se confirma en navegador;
- validar zoom 200 %, navegación solo teclado y lector de pantalla.

### 12.6 Responsive

Los filtros y formularios pasan a una columna a 782 px; opciones de selector se
apilan a 600 px. La tabla mantiene `min-width: 850px` y depende de scroll
horizontal. Es funcional, pero poco diagnóstica en móvil. Un patrón de celdas
priorizadas o tarjetas podría mostrar Product, Store, disponibilidad y acción sin
obligar a recorrer ocho columnas.

## 13. Integraciones

### 13.1 Product

- Inventory valida existencia y enum reconocido.
- Product Admin consulta ofertas directamente con LEFT JOIN a Store.
- Product deriva disponibilidad, precios y stock público.
- Product inspecciona referencias de Inventory, Cart, Reservations y Orders para
  su lifecycle/eliminación.
- Product ofrece enlaces contextuales a Inventory.

Riesgo: Product posee hoy el diagnóstico administrativo de oferta más rico que
Inventory. La lógica debería compartirse como proyección, sin trasladar la
autoridad.

### 13.2 Store

- Inventory valida existencia y un valor reconocido de `Store.status`, no el
  lifecycle compuesto;
- Store detail enlaza lista y creación contextual.
- Catalog y Cart exigen `Store.status = active` para disponibilidad; Checkout
  también obtiene el conjunto de Stores activos por esa proyección.

Riesgos:

- Inventory Admin no muestra cómo Store afecta una oferta;
- el contexto carga `lifecycle_state` en el DTO REST, pero Inventory lo valida y
  luego lo descarta al normalizar el Store contextual;
- la publicación no distingue un Store canónicamente activo de una fila
  inconsistente cuyo `status` crudo sea `active`.

### 13.3 Catálogo

- consume solo Inventory activo;
- descarta stock/precio no positivos;
- intersecta Stores activos y Products activos;
- deriva ofertas y agregados.

Riesgo: regla repetida con Product Admin y sin reason codes compartidos.

### 13.4 Cart

Cart consulta por Inventory ID con LEFT JOIN a Product y Store, y exige:

- Inventory activo;
- referencias existentes y coincidentes;
- Product activo;
- Store activo;
- precio positivo;
- stock suficiente.

Guarda los IDs de Product, Store e Inventory y un snapshot de precio. Revalida
la oferta al agregar y al cambiar cantidad; el checkout vuelve a validar
Inventory, Product, Store, stock y coincidencia del precio. La lectura pública
del carrito, en cambio, presenta el snapshot y la decoración disponible sin
revalidar por sí sola toda la regla pública. La frontera transaccional efectiva
está en las escrituras de Cart y, de nuevo, en Checkout.

### 13.5 Reservations

Reservations llama a InventoryLockService:

- comprueba stock;
- decrementa atómicamente;
- revierte locks previos si falla un lote;
- incrementa al liberar/expirar;
- al consumir, `commitStock` solo comprueba existencia.

Riesgos:

- check y decrement son dos consultas, aunque el decrement definitivo es
  atómico;
- lock no comprueba existencia/estado de Product o Store, estado de Inventory ni
  precio; su seguridad comercial depende de que el llamador haya ejecutado una
  validación previa, garantía que `InventoryLockService` no expresa en su
  contrato;
- delete/update administrativo puede competir con reservas;
- liberar después de una edición absoluta puede inflar stock.

### 13.6 Orders

Orders persiste `inventory_id`, `product_id`, cantidad, precio unitario y
subtotal. Los IDs siguen siendo referencias; el precio unitario y subtotal son
los snapshots históricos. Inventory delete puede romper la resolución y
trazabilidad de la oferta referenciada, aun cuando el snapshot monetario
permanezca.

## 14. Riesgos priorizados

| Prioridad | Riesgo | Impacto |
|---|---|---|
| Crítica | Hard delete sin inspección ni CAS | Huérfanos y pérdida de trazabilidad en Cart/Reservations/Orders |
| Crítica | Edición absoluta de stock concurrente con Reservations | Sobreventa, stock inflado o pérdida de unidades |
| Alta | Update/delete sin `expected_updated_at` | Last-write-wins silencioso |
| Alta | Disponibilidad duplicada en Catalog y Product Admin | Divergencia de reglas y diagnóstico |
| Alta | Publicación de Store basada solo en `status = active` | Un Store con lifecycle compuesto inválido puede ser tratado como público |
| Alta | Read model crudo sin nombres/estados/razones | Operación a ciegas y errores humanos |
| Alta | “active” se confunde con “publicado” | Expectativa administrativa incorrecta |
| Media | REST redundante genérico + tres endpoints de campo | Superficie mayor sin semántica adicional |
| Media | Reglas/DTO externos duplicados en JS | Acoplamiento a Product y Store |
| Media | Búsqueda no sargable y solo por IDs | Mala UX y degradación con volumen |
| Media | Lecturas sin traducción de `last_error` | Fallas SQL aparentan vacío, cero, ausencia o falta de stock |
| Media | Lista y count sin snapshot común | Metadatos transitoriamente inconsistentes bajo concurrencia |
| Media | Estado de formulario/filtros fuera de URL | Sin deep links, back/forward ni recuperación |
| Media | Repositorio abstracto incompleto | Consumidores dependen de implementación concreta |
| Baja | Campos desconocidos ignorados | Errores de cliente silenciosos |
| Baja | Inconsistencia canónica Product ID/Store ID | Contrato sorprendente |
| Baja | Terminología y localización mixtas | Menor claridad y consistencia |

## 15. Oportunidades y diseño recomendado

### 15.1 Prioridad 1 — Modelo de lectura operacional

Crear una consulta administrativa especializada que:

- seleccione columnas explícitas;
- haga JOIN acotado a Product y Store;
- entregue nombres, estados y existencia;
- calcule una razón de disponibilidad canónica;
- exponga `expected_updated_at`;
- reserve conteos costosos de referencias para el detalle.

La proyección de disponibilidad debe ser reutilizable por Inventory Admin,
Product Admin y Catalog, con una sola tabla de reason codes.

### 15.2 Prioridad 2 — Política de stock y concurrencia

Antes de tocar UX, decidir:

- si `stock` significa físico, vendible o disponible;
- cómo representar unidades reservadas;
- si el admin ajusta un valor absoluto o registra delta;
- qué lock/version protege update contra Reservation;
- qué ocurre al inactivar/eliminar con reservas activas.

Una opción conceptual es separar stock físico de reservado y derivar disponible;
otra es conservar el modelo actual pero obligar CAS y serialización con locks.
La elección requiere diseño transaccional y queda fuera de esta auditoría.

### 15.3 Prioridad 3 — Lifecycle y eliminación segura

Definir:

- transición `active ↔ inactive` como comando explícito;
- precondiciones y effect summary;
- CAS con `expected_updated_at`;
- inspector de referencias para Cart, Reservations y Order Items;
- clasificación `deletable | deactivate_only | historically_referenced |
  operationally_blocked`;
- soft delete o prohibición durable cuando exista historia;
- eliminación física solo para ofertas nunca usadas y sin referencias.

Product lifecycle ofrece un patrón local útil, pero Inventory necesita reglas
propias por su stock mutable.

### 15.4 Prioridad 4 — REST coherente

- separar DTO de lista y detalle;
- rechazar campos desconocidos;
- elegir entre PATCH genérico o comandos especializados, no ambos sin diferencia;
- devolver el recurso/version nueva tras update y evitar el GET adicional;
- distinguir `conflict`/CAS, duplicado, referencia bloqueante y no encontrado;
- documentar 409 para conflictos de concurrencia o unicidad;
- no exponer delete hasta que sea seguro.

### 15.5 Prioridad 5 — Navegación y UX

- detalle durable de Inventory;
- filtros y vistas en URL;
- retorno simétrico a Product y Store;
- enlaces por fila;
- nombres como contenido principal e IDs como metadato;
- badges de disponibilidad con reason code;
- filtros por Product/Store humanos y por problemas;
- acción “Activar oferta” acompañada de “pública/no pública” y explicación;
- warning explícito al editar stock con reservas;
- diseño responsive que priorice identidad, precio, stock, estado y diagnóstico.

### 15.6 Prioridad 6 — Rendimiento medido

- baseline con volumen representativo;
- `EXPLAIN` de listado global y contextual;
- batch aggregates;
- keyset pagination si el volumen lo exige;
- cache únicamente después de definir invalidación;
- pruebas de número de queries para impedir N+1.

## 16. Plan sugerido para la Serie 36.5

Este orden es una propuesta de diseño, no una implementación:

1. Fijar semántica de stock y política de concurrencia.
2. Definir matriz de autoridades y reason codes públicos.
3. Diseñar DTO list/detail y consulta agregada.
4. Diseñar inspector de referencias y política de eliminación.
5. Diseñar lifecycle/CAS y contratos REST.
6. Unificar gramática de navegación y retornos.
7. Diseñar listado, detalle, formulario y estados responsive/accesibles.
8. Definir métricas, planes de consulta y estrategia de cache.
9. Recién entonces dividir implementación y pruebas.

## 17. Alcance negativo

Esta auditoría no:

- implementa la Serie 36.5;
- modifica PHP, JavaScript o CSS;
- modifica pruebas;
- modifica endpoints ni su comportamiento;
- modifica esquemas o índices;
- crea migraciones;
- cambia estados de datos;
- ejecuta una migración;
- crea commits ni hace push;
- prescribe una solución definitiva de stock sin decisión de dominio;
- convierte Product o Store en subentidades de Inventory;
- propone persistir datos derivados o decorativos en `inventory`.

## 18. Restricciones y supuestos

- El análisis es estático sobre el commit indicado; no se realizaron escrituras
  ni pruebas contra una base de datos real.
- Los planes exactos de MySQL dependen de cardinalidad, distribución y versión;
  toda optimización de índice requiere `EXPLAIN` en el entorno objetivo.
- La tabla se interpreta según `InventorySchema`; no se presupone integridad
  referencial física no declarada.
- La disponibilidad pública documentada corresponde a la intersección observada
  en Catalog, Cart y Product Admin.
- `stock` se describe como disponible porque Reservations lo decrementa y
  libera; la intención de negocio debe confirmarse antes de implementar.
- Los archivos no versionados preexistentes del workspace no forman parte de
  este entregable.

## 19. Criterios de aceptación para el diseño siguiente

El diseño de la siguiente serie estará completo cuando responda sin ambigüedad:

- quién puede cambiar cada dato;
- qué significa stock durante una reserva;
- qué combinación determina visibilidad y con qué reason code;
- qué muestra lista y detalle sin N+1;
- qué versiona cada escritura;
- cuándo se puede activar, inactivar o eliminar;
- qué referencias bloquean cada acción;
- cuál es la URL durable de cada vista;
- cómo vuelve el usuario a Product o Store;
- cómo se invalida cualquier cache;
- qué prueba de concurrencia demuestra ausencia de sobreventa y lost updates.
