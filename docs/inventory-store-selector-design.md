# Diseño del selector administrativo buscable de Store en Inventory

## 1. Resumen ejecutivo

Este documento diseña, sin implementar, el reemplazo del ingreso manual de `minimarket_id` en la creación administrativa de Inventory por un selector buscable de Store.

Veredicto de factibilidad: **sí, el endpoint administrativo existente es suficiente sin cambios backend para la creación, con restricciones documentadas para rendimiento y representación histórica en edición**.

El endpoint utilizable es `GET /wp-json/veciahorra/v1/stores`. Ya ofrece permisos administrativos, paginación, límite máximo, búsqueda preparada, orden estable, DTO reducido y errores controlados. El selector futuro debe consultar páginas de 10 resultados, comenzar después de dos caracteres recortados y permitir los cuatro estados que hoy acepta `InventoryReferenceValidator`: `pending`, `active`, `inactive` y `rejected`.

La relación durable se mantiene en `inventory.minimarket_id`. En creación, solo una selección normalizada podrá escribirla. En edición no podrá cambiarse: el request, el servicio y el repositorio excluyen su actualización. El selector no sustituirá la validación de referencias, duplicados ni estados que realiza el backend.

Las restricciones relevantes son:

- No existe `GET /stores/{id}`; una Inventory histórica solo puede mostrar con certeza su `minimarket_id` después de recargar el detalle de Inventory. Mostrar además el nombre requeriría un transporte por ID o ampliar el DTO de detalle de Inventory.
- La búsqueda usa comodines iniciales en cuatro columnas y ejecuta además un `COUNT(*)`; está acotada en respuesta, pero puede degradarse al crecer la tabla.
- La búsqueda puede coincidir por propietario, correo o teléfono, aunque esos datos no se exponen en el DTO. Esto es seguro respecto de datos privados, pero puede resultar poco evidente para el administrador.
- Store se elimina físicamente y el esquema de Inventory no declara clave foránea. Una Inventory puede quedar huérfana; el backend conserva `DELETE /inventory/{id}`, aunque la SPA actual no renderiza esa acción. Su Store histórico debe representarse por ID, sin inventar un nombre.

## 2. Fuentes de código auditadas

### Store

- `app/Database/Tables/StoresTable.php` — tabla y columnas.
- `app/Modules/Stores/Models/Store.php` — modelo hidratado por `BaseRepository`.
- `app/Modules/Stores/Requests/StoreRequest.php` — creación y actualización administrativa clásica.
- `app/Modules/Stores/Requests/StoreListRequest.php` — contrato de query REST.
- `app/Modules/Stores/Routes/StoreRoutes.php` — ruta GET y permisos.
- `app/Modules/Stores/Controllers/StoreAdminReadController.php` — DTO y errores.
- `app/Modules/Stores/Services/StoreService.php` — CRUD, paginación, conteo y estados masivos.
- `app/Modules/Stores/Repositories/StoreRepository.php` — SQL real.
- `app/Admin/Tables/StoresTable.php` — listado, filtros, acciones y transiciones administrativas.
- `app/Modules/Stores/Controllers/StoresController.php` — crear, editar, cambiar estado y eliminar.
- `app/Modules/Stores/Views/form.php` — formulario administrativo actual.
- `app/Database/BaseRepository.php` y `app/Core/CrudService.php` — autoridad CRUD y borrado físico.
- `tests/manual/store-admin-routes-test.php` — contrato ejecutable del transporte.

### Inventory y catálogo

- `app/Database/Schemas/InventorySchema.php` — columnas, índices y UNIQUE Product–Store.
- `app/Modules/Inventory/Requests/InventoryCreateRequest.php` — payload de creación.
- `app/Modules/Inventory/Requests/InventoryUpdateRequest.php` — referencias inmutables.
- `app/Modules/Inventory/Services/InventoryReferenceValidator.php` — autoridad referencial.
- `app/Modules/Inventory/Services/InventoryService.php` — creación, edición, duplicados y borrado.
- `app/Modules/Inventory/Repositories/InventoryRepository.php` — persistencia durable.
- `app/Modules/Inventory/Controllers/InventoryController.php` y `Routes/InventoryRoutes.php` — errores y REST.
- `assets/admin/js/modules/inventory/api.js` — transporte SPA y `InventoryApiError`.
- `assets/admin/js/modules/inventory/store.js` — estado, payload y modos del formulario.
- `assets/admin/js/modules/inventory/view.js` — DOM actual.
- `assets/admin/js/modules/inventory/product-selector.js` — patrón accesible reutilizable como referencia, no como estado compartido.
- `assets/admin/js/modules/inventory/context.js` y `app.js` — Product contextual.
- `app/Modules/Catalog/Service/CatalogService.php` y `StoreRepository::findActiveByIds()` — regla pública.
- Pruebas manuales `inventory-*`, `product-inventory-context-test.*`, `public-catalog-marketplace-visibility-test.php` y `public-offer-selection-test.php`.

Todas las conclusiones siguientes derivan de estos archivos; no se utilizaron documentos previos como autoridad.

## 3. Arquitectura actual de Store

### 3.1 Autoridad durable y modelo

`StoresTable::name()` declara la tabla lógica `stores`, materializada con el prefijo configurado por el framework. `StoreRepository::$table` apunta a la misma tabla y `StoreRepository::model()` hidrata `VeciAhorra\Modules\Stores\Models\Store`.

El identificador contractual es `stores.id`, creado mediante `TableBuilder::id()`. El nombre administrativo es `business_name`.

Columnas relevantes del esquema:

| Grupo | Columnas |
|---|---|
| Identidad | `id`, `business_name`, `legal_name`, `owner_name`, `rut` |
| Contacto | `email`, `phone`, `mobile` |
| Ubicación | `address`, `commune`, `city`, `region` |
| Estado | `status`, `onboarding_status` |
| Auditoría | `approved_at`, `created_at`, `updated_at` |

`status` tiene valor inicial `pending`; `onboarding_status`, `draft`; `approved_at` es nullable.

### 3.2 Creación, actualización y borrado

`StoreRequest::validatedForCreate()` fuerza `status=pending` y `onboarding_status=draft`. Valida como obligatorios `business_name`, `owner_name` y `email`, valida el formato del correo y los límites de columnas.

`StoreRequest::validatedForUpdate()` admite `status` dentro de `pending`, `active`, `inactive` y `rejected`. El formulario clásico y las acciones masivas permiten asignar cualquiera de esos estados; no existe una máquina de transiciones que restrinja el paso entre pares de estados.

`StoresController::delete()` delega en `CrudService::delete()` y `BaseRepository::delete()`, que ejecuta un `DELETE` físico. No hay soft delete ni estado `deleted`.

### 3.3 Relación con Inventory

`InventorySchema` persiste `minimarket_id` como entero unsigned e índice `inventory_minimarket_id_index`. No declara foreign key. La pareja `product_id, minimarket_id` tiene el índice único `inventory_product_minimarket_unique`.

La ausencia de foreign key permite que el borrado físico de Store deje una Inventory huérfana. `InventoryReferenceValidator` impedirá crear o actualizar una oferta que no pueda volver a validar la referencia, pero `InventoryService::delete()` permite eliminar la Inventory huérfana porque no revalida Product ni Store.

## 4. Endpoint administrativo existente

### 4.1 Contrato HTTP

| Aspecto | Contrato verificado |
|---|---|
| Namespace | `veciahorra/v1` |
| Método | `GET` (`WP_REST_Server::READABLE`) |
| Ruta | `/stores` |
| URL completa | `/wp-json/veciahorra/v1/stores` |
| Registro | `StoreRoutes::register()` |
| Callback | `StoreRoutes::index()` |
| Controlador | `StoreAdminReadController::index()` |
| Permiso | `StoreRoutes::canManageStores()` → `current_user_can('manage_options')` |
| Autenticación SPA | Cookie de WordPress + nonce REST `X-WP-Nonce` |
| Cache | `Cache-Control: private, no-store` |

`store-admin-routes-test.php` prueba administrador 200, usuario sin capacidad 403, visitante 401, nonce válido, nonce inválido y nonce ausente bajo autenticación cookie.

### 4.2 Query aceptada

`StoreListRequest::validated()` acepta:

| Parámetro | Predeterminado | Validación |
|---|---:|---|
| `page` | 1 | entero 1–1.000.000 |
| `per_page` | 20 | entero 1–100 |
| `search` | null | string sanitizado, trim, máximo 100 caracteres |
| `status` | null | vacío o `pending`, `active`, `inactive`, `rejected` |
| `order_by` | `business_name` | `business_name`, `id`, `status` |
| `direction` | `ASC` | `ASC` o `DESC` |

Los enteros como string se normalizan de forma segura y se rechazan arrays, booleanos, decimales, negativos, overflow y valores fuera de rango. Los enums se recortan y normalizan en mayúsculas/minúsculas según su contrato.

Para el selector se propone fijar en frontend:

```text
search=<término recortado>
page=1
per_page=10
order_by=business_name
direction=ASC
```

No debe enviarse filtro de estado: la administración actual permite los cuatro estados y `InventoryReferenceValidator` los acepta.

### 4.3 Respuesta

`StoreAdminReadController::serialize()` devuelve exclusivamente:

```json
{
  "id": 123,
  "name": "Minimarket Centro",
  "status": "active",
  "onboarding_status": "complete",
  "approved_at": "2026-07-20 12:00:00",
  "location": {
    "commune": "Santiago",
    "city": "Santiago",
    "region": "Metropolitana"
  }
}
```

La metadata es:

```json
{
  "page": 1,
  "per_page": 10,
  "total": 37,
  "total_pages": 4,
  "has_next": true
}
```

El serializador rechaza internamente filas sin ID positivo, nombre, estado permitido u onboarding válido, y transforma el fallo completo en 503 `store_admin_unavailable`. No expone razón social, propietario, RUT, email, teléfonos, dirección completa, timestamps ni secretos.

El selector futuro necesita consumir solo `id`, `name`, `status` y opcionalmente `location` para desambiguar. No debe usar `onboarding_status` ni `approved_at` como autoridad de selección.

### 4.4 Errores

- Query inválida: HTTP 422, `validation_error`, `details.field`.
- Usuario sin permiso: 403 del sistema REST.
- Visitante o cookie sin nonce válido: 401/errores de autenticación WordPress.
- Fallo de servicio, SQL o serialización: HTTP 503, `store_admin_unavailable`, mensaje genérico.
- Sin resultados: HTTP 200, `data=[]`, `total=0`, `total_pages=0`, `has_next=false`.

### 4.5 SQL, orden y rendimiento

`StoreRepository::paginate()` ejecuta un SELECT preparado con:

```sql
WHERE (
  business_name LIKE %s
  OR owner_name LIKE %s
  OR email LIKE %s
  OR phone LIKE %s
)
ORDER BY business_name ASC, id ASC
LIMIT %d OFFSET %d
```

El término se escapa mediante `$wpdb->esc_like()` y se rodea con `%...%`. `count()` repite las mismas condiciones con `SELECT COUNT(*)`. Si se aporta `status`, agrega `status = %s`.

El orden es estable: para campos distintos de `id`, el repositorio agrega `id ASC` como desempate, incluso cuando la dirección primaria es DESC.

La respuesta nunca carga todos los Stores porque usa `LIMIT/OFFSET`; incluso sin término entrega solo una página. Sin embargo, los comodines iniciales impiden aprovechar un índice B-tree convencional para prefijo y el esquema no declara índices específicos sobre las cuatro columnas buscadas. SELECT y COUNT pueden hacer recorridos costosos al crecer la tabla. Esta es una restricción operativa, no un bloqueo funcional inmediato.

`StoreRepository::search()` no debe utilizarse en el selector: con término vacío llama `all()` y con término no vacío no aplica límite. El transporte REST correcto usa `paginate()` y `count()`.

### 4.6 Suficiencia

**Sí, suficiente con restricciones documentadas.** No se requiere backend previo para crear el selector de Store en altas de Inventory.

Restricciones:

1. Debe consumirse `/stores`, nunca `StoreService::search()`.
2. El frontend debe fijar `per_page=10` y no buscar bajo dos caracteres.
3. La edición histórica debe mostrar como mínimo `Minimarket ID #<id>` porque no hay detalle Store por ID.
4. Un nombre rico en edición requeriría un microhito backend opcional: `GET /stores/{id}` o enriquecer Inventory detail.
5. Si la cardinalidad crece y las métricas muestran lentitud, será necesario un microhito de índices/estrategia de búsqueda; no se propone modificarlo preventivamente aquí.

## 5. Estados administrativos de Store

Los estados declarados coherentemente por `StoreRequest`, `StoreListRequest`, `StoreService::bulkUpdateStatus()`, `StoreAdminReadController` y las tablas administrativas son:

| Estado | Persistencia/transición | Listado admin | Inventory nuevo | Catálogo público |
|---|---|---|---|---|
| `pending` | Valor inicial; asignable individual y masivamente | Sí | Sí | No |
| `active` | Asignable individual y masivamente | Sí | Sí | Sí, sujeto a las demás reglas |
| `inactive` | Asignable individual y masivamente | Sí | Sí | No |
| `rejected` | Asignable individual y masivamente | Sí | Sí | No |

La afirmación “Inventory nuevo: Sí” está respaldada por `InventoryReferenceValidator::STORE_STATUSES`, que contiene exactamente los cuatro estados. No hay regla basada en `onboarding_status` o `approved_at`.

Regla propuesta de seleccionabilidad administrativa: **mostrar y permitir seleccionar pending, active, inactive y rejected, mostrando siempre el estado como información**. Aplicar solo `active` sería introducir una regla nueva incompatible con el backend actual.

Regla pública independiente: `CatalogService` solicita Inventory `active` y luego `StoreRepository::findActiveByIds()` restringe Store a `status=active`. Continúan aplicando precio finito mayor que cero y stock mayor que cero. `onboarding_status`, `approved_at` y aprobación de Store no participan.

## 6. Flujo actual de Inventory

### 6.1 Creación ordinaria

`createInventoryForm()` en `view.js` crea actualmente un `<input type="number">` etiquetado `Minimarket ID`, mínimo 1. Su evento `input` llama `actions.onFormField('minimarketId', value)`, que delega en `store.setFormField()`.

`store.validate()` normaliza `minimarketId` como entero positivo y serializa:

```json
{
  "product_id": 123,
  "minimarket_id": 456,
  "price": 1000,
  "stock": 5,
  "status": "active"
}
```

`InventoryCreateRequest` vuelve a exigir un entero positivo. `InventoryService::create()` valida la referencia mediante `InventoryReferenceValidator`, que usa `StoreService::find(id)`. Store inexistente produce `validation_error`, `details.field=store_id`, `details.reason=inventory_store_not_found`; estado desconocido produce `inventory_store_incompatible`. Un ID ausente o inválido produce `inventory_invalid_store_id`.

### 6.2 Creación contextual desde Product

`context.js` valida `product_id`; `app.js` obtiene el detalle Product; `store.applyContext()` fija `form.values.productId`, `contextProduct` y `productLocked=true`.

Solo Product queda bloqueado. `minimarketId`, precio, stock y estado siguen editables. El payload combina el Product contextual validado con el Store elegido. El futuro selector Store debe comportarse igual en creación ordinaria y contextual; no debe leer ni modificar estado del selector Product ni alterar las URLs contextuales.

### 6.3 Edición

`store.openEditForm()` obtiene `/inventory/{id}` y `formFromItem()` carga `product_id` y `minimarket_id` durables. `view.js` deshabilita ambos controles en modo edit.

El backend refuerza la inmutabilidad dos veces:

- `InventoryUpdateRequest` rechaza la presencia de `product_id` o `minimarket_id`.
- `InventoryService::update()` vuelve a rechazarlos y construye el payload solo con `price`, `stock`, `status`, `updated_at`.
- `InventoryRepository::UPDATE_FIELDS` tampoco contiene referencias.

El frontend de edición envía únicamente precio, stock y estado. La relación Inventory–Store la determina el registro durable leído por ID; la URL contextual Product no la sustituye.

Regla exacta de diseño: **Store no puede cambiarse en edición. Debe mostrarse como dato de solo lectura y `minimarket_id` nunca debe incluirse en PATCH.**

## 7. Contrato del futuro selector Store

### 7.1 Componente y aislamiento

Crear un módulo acotado, por ejemplo `assets/admin/js/modules/inventory/store-selector.js`, inspirado en el contrato accesible de `product-selector.js` pero con estado completamente independiente.

API propuesta:

```js
createStoreSelector({
  searchStores,
  onStoreSelected,
  onStoreCleared,
})
```

No compartir con Product:

- término;
- resultados;
- timer;
- `requestSequence`;
- opción activa;
- IDs DOM;
- callbacks;
- estado seleccionado.

El prefijo DOM debe ser `veciahorra-inventory-store-*`; Product conserva `veciahorra-inventory-product-*`.

### 7.2 Búsqueda

- Mostrar el selector al abrir creación ordinaria o contextual.
- No ejecutar búsqueda inicial.
- Recortar con `trim()`.
- Umbral mínimo: 2 caracteres después de trim.
- Debounce: 300 ms.
- Ejecutar `GET /stores?search=<term>&page=1&per_page=10&order_by=business_name&direction=ASC`.
- No enviar filtro de estado.
- Máximo 10 resultados visibles.
- No cargar páginas adicionales automáticamente. Una primera implementación puede indicar que existen más resultados usando `has_next`; paginación incremental accesible puede añadirse dentro del mismo componente si se implementa y prueba.
- Limpiar resultados inmediatamente cuando cambia el término.
- Estados internos: idle, typing, loading, results, empty, error, selected y disabled.
- Reintentar repite solo el término vigente.

### 7.3 Concurrencia

Cada instancia mantendrá su propio:

```text
requestSequence + currentTerm + visible/currentMode
```

Al escribir, limpiar, seleccionar, cancelar, cambiar a edición, reiniciar el formulario o destruir la vista:

1. incrementar la secuencia;
2. cancelar el timer;
3. neutralizar resultados y opción activa;
4. impedir que una respuesta tardía modifique DOM, región viva o selección.

Puede utilizarse `AbortController`, pero no es obligatorio si el token está correctamente probado. Durante `form.isSaving`, input, opciones y botones de cambio/limpieza quedan bloqueados.

### 7.4 Resultados

Cada opción mostrará:

```text
<name>
ID <id> · <status>
<commune>, <city>, <region> (solo partes disponibles)
```

La ubicación ayuda a distinguir nombres iguales y ya forma parte del DTO autorizado. No mostrar onboarding, aprobación, propietario, email, teléfono, RUT ni dirección privada.

Normalización obligatoria:

- `id`: entero positivo seguro;
- `name`: string no vacío después de trim;
- `status`: pending, active, inactive o rejected;
- `location`: objeto; commune/city/region deben ser string no vacío o null;
- deduplicar por ID conservando la primera fila válida;
- rechazar respuesta completa si metadata o estructura superior es inválida;
- descartar de forma segura una fila inválida antes de crear una opción, según el contrato elegido y probado.

### 7.5 Selección, limpieza y cambio

Click o Enter sobre la opción activa confirma una selección explícita. Nunca seleccionar automáticamente por coincidencia exacta ni por ser primer resultado.

Estado normalizado propuesto en el formulario:

```js
selectedStore: {
  id,
  name,
  status,
  location,
}
values.minimarketId = String(id)
```

En creación se puede:

- quitar: elimina `minimarketId`, `selectedStore`, resumen, opción activa y error referencial anterior;
- cambiar: primero neutraliza la selección y luego permite una nueva búsqueda;
- cancelar: `initialForm()` elimina todo el estado Store;
- reabrir: no conserva término, resultados ni selección cancelada.

No puede limpiarse ni cambiarse durante guardado o edición.

### 7.6 Fuente única de verdad y payload

Los únicos escritores permitidos de `minimarketId` serán:

1. `selectStore(normalizedStore)` en creación ordinaria/contextual;
2. `formFromItem(item)` para referencia durable en edición;
3. limpieza controlada ante selección eliminada o Store desaparecido en creación.

`setFormField('minimarketId', ...)` deberá ignorarse, igual que hoy ocurre con `productId`. El input numérico manual debe ocultarse o retirarse de creación; no debe existir un segundo control con el mismo valor contractual.

Validación cliente antes de POST:

```text
Seleccione un minimarket.
```

El payload conserva exclusivamente `minimarket_id` entero. No serializa nombre, status, ubicación, término, índice visual ni DTO.

### 7.7 Modos

| Modo | Selector Product | Selector Store | Autoridad Store |
|---|---|---|---|
| Creación ordinaria | Buscable | Buscable | `selectStore()` |
| Creación Product contextual | Product visible/bloqueado | Buscable | `selectStore()` |
| Edición | Product durable readonly | Store durable readonly | `formFromItem()` |
| Detalle no disponible | Ocultos/bloqueados | Oculto/bloqueado | Ninguna escritura |
| Guardando | Bloqueado | Bloqueado | Estado ya validado |

En edición, mostrar `Minimarket ID #<id>` como representación mínima segura. Si en el futuro existe detalle por ID, se podrá mostrar nombre/estado sin habilitar cambios.

### 7.8 Errores

| Caso | Comportamiento diseñado |
|---|---|
| Store ID ausente/inválido | Limpiar selección; error `inventory_invalid_store_id`; conservar Product y demás campos |
| Store eliminado antes de POST | `inventory_store_not_found`; limpiar Store solo en creación; permitir buscar otro |
| Store eliminado en edición | Conservar ID durable readonly; no añadir selector; PATCH de precio/stock/status puede ser rechazado por revalidación; el DELETE backend sigue disponible |
| Estado incompatible | `inventory_store_incompatible`; limpiar Store en creación y explicar que debe elegirse otro |
| Duplicado Product–Store | Conservar ambos selectores y todos los valores; mostrar `inventory_duplicate`; no prevalidar autoridad en cliente |
| Query Store inválida | Error 422 asociado al buscador, sin alterar selección confirmada |
| Permiso/nonce | Mensaje de sesión/permisos controlado; no transformar en “Store inexistente” |
| Red/timeout/500/503 | Conservar selección y formulario; reintento sobre término vigente |
| DTO inválido | Error técnico; no crear opciones parciales inseguras |
| Respuesta obsoleta | Ignorar completamente |

`InventoryApiError.field` recibirá `store_id`, mientras el estado frontend usa `minimarketId`. La integración debe mapear explícitamente `store_id → minimarketId/selectedStore`; no debe depender de que ambos nombres coincidan.

### 7.9 Accesibilidad

Patrón requerido:

- `<label>` visible “Minimarket”.
- Input `role=combobox`, `aria-autocomplete=list`, `aria-expanded`, `aria-controls`, `aria-activedescendant`.
- Contenedor `role=listbox` con ID único de Store.
- Opciones `role=option`, IDs derivados solo del ID normalizado, `aria-selected` coherente.
- Región `aria-live=polite` para espera, carga, cantidad y vacío.
- Error `role=alert`.
- Resumen seleccionado `role=status`, `aria-live=polite`.
- Flechas con política wrap documentada; Enter selecciona solo opción activa; Escape cierra.
- Tab y Shift+Tab no se interceptan; `focusout` cierra cuando el foco sale del componente.
- Foco permanece en el input durante navegación por `aria-activedescendant`.
- Tras limpiar, devolver foco al input. Tras seleccionar, el resumen y sus botones quedan en el orden natural de tabulación.
- Control bloqueado mediante propiedades `disabled` reales y texto visible; no solo `aria-disabled`.
- Composición IME: no buscar entre `compositionstart` y `compositionend`.

### 7.10 DOM seguro y ciclo de vida

- Insertar nombre, estado y ubicación con `textContent` o nodos de texto.
- Prohibir `innerHTML`, `outerHTML` e `insertAdjacentHTML` con datos REST.
- No derivar IDs DOM del nombre; usar ID entero normalizado y prefijo Store.
- Crear una instancia por formulario y registrar listeners una vez.
- Reutilizar la instancia en render; resetearla al cambiar de modo.
- No retener resultados o closures de una apertura anterior.
- CSS bajo `.veciahorra-inventory-admin__store-*`, sin selectores globales.

## 8. Compatibilidad obligatoria

### 8.1 Product buscable

Product mantiene `selectedProduct` y `values.productId`; Store tendrá `selectedStore` y `values.minimarketId`. Ningún callback de un selector puede escribir el campo del otro. Las secuencias, términos, timers, opciones activas e IDs DOM serán independientes.

### 8.2 Product contextual

`contextProduct` y `productLocked` permanecen intactos. Store sigue buscable durante la creación porque el código actual no lo bloquea. Cancelar sigue navegando al listado filtrado por Product; el selector Store solo resetea su estado local antes de la navegación.

### 8.3 Edición

Ambas referencias son read-only. El PATCH no incluirá `product_id` ni `minimarket_id`. Un Store histórico que ya no aparece en búsqueda se muestra por ID durable; no se fuerza una búsqueda ni se sustituye.

### 8.4 Duplicado

El duplicado significa que ya existe la pareja durable Product–Store. Está protegido por:

- consulta `findByProductAndMinimarket()` antes del INSERT;
- índice UNIQUE `inventory_product_minimarket_unique` para la carrera concurrente;
- traducción a `InventoryValidationException` con `field=store_id`, `reason=inventory_duplicate`.

El frontend solo presenta el error y conserva estado; no replica la consulta de duplicado.

### 8.5 Inventory y Store eliminados

Inventory se elimina físicamente mediante `InventoryRepository::delete()`. Una Inventory inexistente produce `inventory_not_found`. Store también se elimina físicamente y puede dejar Inventory huérfana porque no hay FK.

En edición huérfana:

- mostrar Store ID durable;
- no presentar selector;
- no inventar nombre ni estado;
- no alterar `DELETE /inventory/{id}`; la SPA actual no muestra un control Delete y añadirlo queda fuera de alcance;
- mostrar el error referencial al intentar otras mutaciones.

## 9. Riesgos arquitectónicos y mitigaciones

| Riesgo | Causa/evidencia | Consecuencia | Mitigación | Responsable futuro |
|---|---|---|---|---|
| Mezcla Product/Store | Dos componentes en el mismo formulario | Un evento escribe el ID equivocado | Estado, callbacks, prefijos DOM y secuencias separados | Implementación selector Store |
| Doble escritura | Input `minimarketId` manual actual + selector | Valor visible distinto del payload | Bloquear `setFormField(minimarketId)` y ocultar/eliminar input en create | Integración store/view |
| Reasignación en edición | Selector aparentemente editable | Oferta cambia de minimarket | No renderizar selector; request/service/repository ya rechazan referencia | Integración edición |
| Store histórico ausente | Borrado físico y sin detalle por ID | No hay nombre fiable | Mostrar ID durable readonly; detalle por ID opcional | Microhito backend opcional |
| Huérfanos | Sin FK Inventory→Store | PATCH falla por revalidación | Conservar DELETE backend, no reasignar automáticamente | Saneamiento futuro fuera de alcance |
| Duplicado concurrente | Dos altas Product–Store | INSERT duplicado | UNIQUE + traducción backend; conservar formulario | Manejo de errores frontend |
| Carga completa | `StoreRepository::search()` puede usar `all()` | Memoria/latencia | Consumir solo REST paginado `/stores` | API frontend |
| LIKE costoso | `%term%` en cuatro columnas sin índices declarados | SELECT/COUNT degradan | Umbral, debounce, per_page=10, medir; optimizar backend solo con evidencia | Microhito rendimiento si procede |
| Coincidencia invisible | Search incluye owner/email/phone, DTO no | Resultado aparentemente inesperado | Texto de ayuda “nombre o datos administrativos”; no exponer privados | UX selector |
| Estados confundidos | Solo active es público, cuatro son admin | Reglas admin incorrectas | Mostrar cuatro con etiqueta de estado; separar regla pública | Selector Store |
| Nonce/permiso | Cliente REST mal integrado | 401/403 o exposición | Reusar `createInventoryApi.request()`, nonce y same-origin | API frontend |
| XSS | business_name/location no confiables | Ejecución DOM | `textContent`, ID numérico normalizado | Vista selector |
| Respuesta tardía | Requests concurrentes | Resultados/errores obsoletos | Secuencia + término + modo/visibilidad | Controlador selector |

## 10. Decisiones cerradas

1. **Endpoint:** suficiente con restricciones; no requiere ampliación previa.
2. **Estados buscables:** pending, active, inactive y rejected.
3. **Edición:** Store no puede cambiarse.
4. **Store histórico no seleccionable/inexistente:** mostrar ID durable readonly; no buscar, ocultar ni sustituir.
5. **Fuente de verdad:** `values.minimarketId`, escrito únicamente por `selectStore()` o `formFromItem()` y acompañado por `selectedStore` solo en creación.
6. **Sin carga total:** GET `/stores`, `per_page=10`, umbral 2, debounce 300; nunca `StoreService::search()`.
7. **Obsolescencia:** secuencia propia, término vigente y modo/visibilidad.
8. **Aislamiento:** módulos, estado, eventos, timers e IDs DOM separados de Product.
9. **Duplicados:** conservar formulario y presentar error backend; no prevalidar en JS.
10. **Backend exclusivo:** existencia, estado compatible definitivo, integridad referencial, duplicado, carrera UNIQUE, permisos y persistencia.

## 11. Brechas

### No bloqueantes para implementar creación

- Falta detalle administrativo Store por ID para nombre/estado en edición.
- Búsqueda por comodín inicial sin índices específicos; requiere observabilidad antes de optimizar.
- La coincidencia puede provenir de datos no mostrados.
- No existe timeout explícito en el cliente REST actual; la implementación puede mantener el patrón de errores de red existente o incorporar aborto controlado si se diseña de forma común.

### No hay microhito backend previo obligatorio

El selector de creación puede implementarse íntegramente con el endpoint actual. Si se exige nombre/estado del Store histórico en edición, crear primero un microhito mínimo para `GET /stores/{id}` o para enriquecer el detalle de Inventory, manteniendo `manage_options` y DTO reducido.

## 12. Alcance negativo

Este diseño no incorpora:

- implementación JavaScript ni CSS;
- cambios PHP;
- endpoints o contratos REST nuevos;
- cambios del DTO Store;
- repositorios, servicios, controladores, requests, esquemas o migraciones;
- cambios de estados o reglas públicas;
- eliminación, saneamiento o reasignación de Inventory;
- cambios al selector Product o navegación contextual;
- pruebas nuevas o modificadas;
- commit o push.

## 13. Plan incremental posterior

1. **Confirmación de brecha backend:** omitir por defecto; abrir microhito solo si edición debe mostrar nombre Store y no basta el ID durable.
2. **API frontend:** añadir `searchStores()` a `inventory/api.js`, validar DTO/meta y construir query con `URLSearchParams`.
3. **Componente:** crear `store-selector.js` con estado, normalización, deduplicación, IME, teclado, debounce y secuencia propios.
4. **Store SPA:** añadir `selectedStore`, `selectStore()` y `clearSelectedStore()`; bloquear escritura genérica de `minimarketId`.
5. **Creación ordinaria:** reemplazar visualmente el input manual y conservar payload `minimarket_id`.
6. **Product contextual:** mostrar el selector Store sin tocar `contextProduct`, bloqueo ni navegación.
7. **Edición:** renderizar Store ID durable readonly; nunca activar búsqueda ni enviar referencia.
8. **Errores:** mapear `store_id`, Store inexistente/incompatible, duplicado y errores técnicos por modo.
9. **Accesibilidad/CSS:** combobox independiente, región viva, foco, responsive y DOM seguro.
10. **Prueba estructural:** certificar endpoint, autoridad, payload, aislamiento y ausencia de backend nuevo.
11. **Prueba Chrome:** cubrir umbral, debounce, estados, ubicación, dedupe, IME, teclado, obsolescencia, doble selector, contexto, edición y errores.
12. **Certificación:** ejecutar regresiones Product, Inventory, Store y catálogo; auditar diff y crear commit aislado solo en un microhito de cierre posterior.

Archivos candidatos, solo como orientación:

- `assets/admin/js/modules/inventory/api.js`
- `assets/admin/js/modules/inventory/app.js`
- `assets/admin/js/modules/inventory/store.js`
- `assets/admin/js/modules/inventory/view.js`
- `assets/admin/js/modules/inventory/store-selector.js` (nuevo)
- `assets/admin/css/inventory.css`
- `tests/manual/inventory-store-selector-test.php` (nuevo)
- `tests/manual/inventory-store-selector-test.html` (nuevo)
- pruebas contextuales existentes, solo si requieren ampliar certificación.

## 14. Conclusión

El transporte administrativo Store ya permite un selector buscable, paginado y seguro sin modificar backend. El diseño debe usar el listado paginado, permitir los cuatro estados administrativos aceptados por la autoridad referencial y mantener `minimarket_id` inmutable en edición.

La implementación es factible como siguiente microhito frontend. Sus límites explícitos son la ausencia de detalle Store por ID y el costo potencial de búsquedas `%term%` a gran escala; ninguno impide el selector de creación si se conserva la representación histórica por ID y se limita cada consulta a 10 resultados con umbral y debounce.
