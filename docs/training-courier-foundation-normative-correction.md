# Corrección normativa de la base Courier para capacitación

## 1. Veredicto y alcance

**VECIAHORRA TRAINING COURIER FOUNDATION: IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

Este documento cierra las dependencias mínimas para una implementación posterior del MVP del repartidor. No implementa paneles, rutas, esquema, migraciones ni cambios productivos.

Autoridades auditadas:

- `CourierRepository`, que usa la tabla lógica `couriers` y actualmente admite tres representaciones incompatibles de aprobación;
- `DeliverySchema`, `Delivery`, `DeliveryRepository`, `DeliveryService`, `DeliveryRoutes` y la materialización durable de Delivery;
- `CheckoutRequest`, `CheckoutSchema`, la vista y el JavaScript públicos del checkout;
- `StoresTable` y el contrato administrativo de Store;
- `Installer`, `Schema`, `MigrationManager` y `Config::SCHEMA_VERSION`.

Las decisiones siguientes son normativas y únicas. Los nombres físicos usan `$wpdb->prefix . Config::TABLE_PREFIX`; con el prefijo WordPress usual, `couriers` es `wp_va_couriers`.

## 2. Courier schema canónico

La única definición futura será `VeciAhorra\Database\Tables\CouriersTable`, implementando `TableInterface`, con `name(): string` igual a `couriers`.

| Columna | Tipo de `TableBuilder` | Nulo | Default | Regla |
| --- | --- | --- | --- | --- |
| `id` | `id()` / BIGINT UNSIGNED autoincremental | no | ninguno | clave primaria |
| `display_name` | `string(150)` | no | ninguno | nombre operativo visible, sanitizado y no vacío |
| `phone` | `string(30)` | no | ninguno | teléfono operativo, sanitizado y no vacío |
| `email` | `string(150)` | sí | `NULL` | correo operativo válido cuando se informa |
| `status` | `string(20)` | no | `pending` | solo `pending`, `approved` o `inactive` |
| `approved_at` | `datetime()` | sí | `NULL` | auditoría de la aprobación vigente o más reciente |
| `created_at` | `datetime()` | no | ninguno | creación en UTC mediante `current_time('mysql', true)` |
| `updated_at` | `datetime()` | no | ninguno | última escritura en UTC |

Índices y unicidad:

- clave primaria de `id` creada por `id()`;
- índice `couriers_status_index` sobre `status`;
- índice `couriers_email_index` sobre `email`;
- no hay otra restricción UNIQUE: nombre, teléfono y correo no son la identidad de acceso y pueden ser compartidos o modificados;
- no existe columna `user_id` en Courier.

La asociación persistente única es:

`WordPress user ID → user meta _veciahorra_courier_id → va_couriers.id`.

El meta contiene un entero decimal positivo. Un Courier puede estar asociado como máximo a un usuario: la escritura administrativa debe buscar otros usuarios con el mismo meta y rechazar la asociación si existe. Como `wp_usermeta` no permite expresar esa unicidad, esta comprobación y la actualización del meta se realizan dentro del único caso de uso administrativo; nunca se acepta el ID enviado por el navegador del Courier como autoridad.

## 3. Lifecycle de Courier

El estado inicial es `pending`. Las transiciones permitidas son:

| Previo | Acción | Posterior | `approved_at` |
| --- | --- | --- | --- |
| `pending` | aprobar | `approved` | fecha UTC de la aprobación |
| `pending` | desactivar | `inactive` | permanece `NULL` |
| `approved` | desactivar | `inactive` | se conserva |
| `inactive` | aprobar/reactivar | `approved` | se reemplaza por la nueva fecha UTC |

Repetir una acción que ya dejó el registro en el estado pedido es idempotente: no cambia `approved_at` ni `updated_at`. Cualquier otra transición se rechaza como conflicto de estado.

Solo un usuario con `manage_options` puede aprobar, reactivar o desactivar. Un Courier `inactive` conserva su fila, asociación e historial; no puede listar disponibles, aceptar ni avanzar entregas. Sus entregas ya asignadas permanecen asociadas para auditoría y deben ser reasignadas o resueltas por administración.

`approved_at` se conserva únicamente como auditoría. La futura semántica de `CourierRepository::isApproved(array $courier): bool` será exactamente: retorna verdadero solo cuando la clave `status` existe y su valor string es `approved`. Se eliminan las ramas por `approved_at` e `is_approved`; no se crea columna `is_approved`.

## 4. Identidad y autorización WordPress

Se crea el role `veciahorra_courier` con la capability `veciahorra_manage_deliveries`. La activación añade/actualiza el role de forma idempotente; la desactivación del plugin no elimina usuarios, role, capabilities ni metas.

Para toda operación Courier deben cumplirse, en este orden:

1. usuario WordPress autenticado;
2. `current_user_can('veciahorra_manage_deliveries')`;
3. `_veciahorra_courier_id` presente y entero positivo;
4. fila Courier existente para ese ID;
5. `status === 'approved'`.

El contexto resuelto contiene el `WP_User` y la fila Courier; los servicios reciben el `courier_id` resuelto, no uno del payload. `manage_options` permite las operaciones administrativas, pero para actuar como repartidor también debe resolver una asociación Courier aprobada; no se atribuyen entregas a un administrador sin Courier.

## 5. Delivery elegible y listado de disponibles

Los estados productivos reales son `pending`, `assigned`, `picked_up`, `delivered` y `cancelled`. La asignación existente cambia `pending` a `assigned`; no se crea estado `accepted`.

Una Delivery aparece en **Entregas disponibles** si y solo si:

- existe en `va_deliveries`;
- `status = 'pending'`;
- `courier_id IS NULL`;
- su Order existe y pertenece al mismo `minimarket_id` guardado en Delivery;
- el Order está `paid`;
- el Checkout asociado al Order tiene `fulfillment_method = 'delivery'`;
- existen un snapshot de retiro utilizable y un snapshot de despacho completo según las secciones 8 y 9.

La consulta debe unir `deliveries → orders → checkout_orders → checkouts → stores`. Se excluyen `assigned`, `picked_up`, `delivered`, `cancelled`, filas ya asignadas, pickup, Order no pagada, identidades cruzadas y snapshots incompletos. La materialización productiva ya crea Delivery únicamente para `delivery` y Order `paid`; las uniones son defensa y autoridad de lectura, no estados nuevos.

## 6. Aceptación concurrente

El método futuro exacto del repositorio será:

```php
public function acceptAvailable(int $deliveryId, int $courierId, string $updatedAt): int
```

Ejecuta una sola sentencia preparada equivalente a:

```sql
UPDATE wp_va_deliveries
SET courier_id = :courier_id,
    status = 'assigned',
    updated_at = :updated_at
WHERE id = :delivery_id
  AND courier_id IS NULL
  AND status = 'pending'
```

Retorna estrictamente las filas afectadas: `1` significa ganador; `0` exige clasificar el resultado mediante una lectura posterior; `false` de `$wpdb->query()` lanza `PersistenceException`. El servicio valida primero Courier aprobado y snapshot elegible, ejecuta el CAS y solo después de ganar registra tracking `assigned`.

Clasificación de `0`:

- Delivery inexistente: `delivery_not_found`;
- misma Delivery ya `assigned` al mismo Courier: éxito idempotente, devuelve la fila actual y no duplica tracking;
- asignada a otro Courier: `delivery_assignment_conflict` (HTTP 409);
- sin Courier pero ya no `pending`: `delivery_not_available` (HTTP 409).

Dos Couriers concurrentes pueden obtener como máximo un `1`; el otro observa conflicto. Una repetición del ganador es idempotente. No se revierte ni reasigna por repetición. La operación pública de aceptación no admite `courier_id` en el body.

## 7. Transiciones operables por el repartidor

El Courier solo puede operar una Delivery cuyo `courier_id` coincide con su Courier resuelto y cuyo Courier continúa `approved`.

| Acción | Estado previo exacto | Estado posterior | Repetición |
| --- | --- | --- | --- |
| aceptar | `pending`, sin Courier | `assigned`, Courier actual | éxito idempotente si ya está `assigned` al mismo Courier |
| confirmar retiro | `assigned` | `picked_up` | éxito idempotente si ya está `picked_up` |
| confirmar entrega | `picked_up` | `delivered` | éxito idempotente si ya está `delivered` |

Una repetición idempotente devuelve la fila sin actualizar timestamps ni duplicar tracking. Saltos, retrocesos, `cancelled`, una Delivery de otro Courier o estados terminales diferentes al objetivo retornan conflicto. `delivered` conserva el comportamiento productivo de marcar la Order `delivered`; esa actualización y la transición de Delivery deben ejecutarse en una transacción para evitar divergencia. Cancelación y reasignación quedan reservadas a `manage_options` y fuera del panel Courier.

## 8. Fuente de retiro

La autoridad editable es la fila `va_stores` referida por `Delivery.minimarket_id`:

- nombre: `stores.business_name`;
- dirección: `stores.address`;
- comuna: `stores.commune`;
- teléfono: `COALESCE(NULLIF(stores.mobile, ''), stores.phone)`.

Para que una entrega sea elegible, nombre, dirección, comuna y teléfono resuelto deben ser no nulos y no vacíos. No se copian a Delivery. El panel proyecta estos valores desde Store, por lo que una corrección administrativa se refleja inmediatamente. El Courier no recibe `legal_name`, `owner_name`, RUT, email ni estados internos de Store.

## 9. Dirección de despacho y snapshot

No existe hoy una fuente persistida reutilizable: la vista contiene campos, pero `CheckoutRequest` solo acepta `fulfillment_method`, `CheckoutSchema` no tiene dirección y el JavaScript envía únicamente el método. El perfil WordPress no es autoridad del pedido.

La modificación futura mínima extiende `va_checkouts` con estas columnas nullable, porque pickup no requiere despacho:

| Columna | Tipo | Regla para `delivery` |
| --- | --- | --- |
| `delivery_recipient_name` | VARCHAR(200) | obligatoria; `first_name` + espacio + `last_name`, normalizado |
| `delivery_contact_phone` | VARCHAR(30) | obligatoria |
| `delivery_address_line1` | VARCHAR(255) | obligatoria |
| `delivery_commune` | VARCHAR(120) | obligatoria |
| `delivery_reference` | VARCHAR(255) NULL | opcional |
| `delivery_notes` | TEXT NULL | opcional |

Para pickup, las seis columnas deben persistirse como `NULL`. `CheckoutRequest` admitirá exactamente `fulfillment_method` y, cuando sea delivery, un objeto `delivery` con las seis claves anteriores; sanitiza texto, valida longitudes y teléfono, convierte strings opcionales vacíos a `NULL`, y rechaza datos de delivery en pickup. Validate y create usan el mismo DTO normalizado. El fingerprint de idempotencia incorpora el objeto `delivery`, evitando reutilizar una clave con otra dirección.

La creación persistente del Checkout guarda estos valores dentro de la misma transacción que fija el checkout y sus pedidos. Su ownership es el ownership ya vigente del Checkout (`owner_type`, `user_id`/`session_id`); las rutas públicas existentes nunca permiten consultar un checkout ajeno.

Para congelar un snapshot por pedido, `va_deliveries` incorpora las mismas seis columnas, con igual tipo y nullability física. Durante `DeliveryCompletionProcessor`, dentro de la transacción existente y antes de cerrar la completion, cada Delivery creada copia las seis columnas desde el Checkout asociado a su Order. Para una Delivery materializada por `fulfillment_method='delivery'`, los cuatro campos obligatorios no pueden quedar vacíos; si faltan, el resultado es `manual_review` y no se publica como disponible. Una Delivery existente se verifica contra el snapshot, no se sobrescribe silenciosamente.

El panel Courier lee el snapshot de `va_deliveries`, nunca HTML, JavaScript, perfil WordPress ni valores actuales del Checkout. Muestra destinatario, teléfono, línea principal, comuna, referencia y notas. Así, cada Order conserva su copia operativa aunque cambien datos del cliente o del Checkout.

## 10. Privacidad

El repartidor puede ver únicamente:

- ID público/operativo necesario para identificar la entrega;
- nombre del destinatario;
- teléfono de contacto;
- dirección, comuna, referencia y notas de entrega;
- datos de retiro definidos en la sección 8;
- estado y timestamps operativos de la Delivery.

No puede ver email del cliente, RUT, datos financieros, Payment/Payment Session/Webpay, totales, inventario, otras compras, IDs de sesión, claves de idempotencia ni datos de otros clientes o Couriers. Las consultas propias siempre filtran por el `courier_id` resuelto en servidor.

## 11. Esquema, versión y activación futura

La siguiente implementación realizará en una sola entrega:

1. crear `app/Database/Tables/CouriersTable.php` con la sección 2;
2. registrar `new CouriersTable()` en `Schema::tables()` y eliminar el comentario obsoleto `// new CouriersTable()`;
3. extender `CheckoutSchema` y `DeliverySchema` con las columnas de la sección 9;
4. extender los repositorios/modelos/DTO para escribir y leer únicamente esos nombres;
5. cambiar `Config::SCHEMA_VERSION` de `0.24.0` a `0.25.0`;
6. registrar idempotentemente role/capability en el mismo activation/install path;
7. adaptar checkout y materialización sin alterar el flujo durable existente.

No se requiere una clase de migration adicional: al detectar `0.24.0 < 0.25.0`, `Installer::install()` ejecuta `dbDelta` sobre todas las definiciones de `Schema`, y `MigrationManager::migrate()` vuelve a ejecutar idempotentemente `CreateCheckoutsTable` y `CreateDeliveriesTable`, cuyas definiciones extendidas agregan las columnas. Finalmente actualiza `veciahorra_db_version` a `0.25.0`. En una instalación nueva, el mismo camino crea todo; en una existente conserva filas y añade columnas nullable antes de aceptar nuevos checkouts delivery.

La implementación debe registrar `CouriersTable` solo en `Schema`; no debe además crear otra migration que duplique la misma tabla. No se ejecuta ninguna migración en esta tarea documental.

## 12. Administración mínima

Con `manage_options`, administración podrá:

- listar Couriers por `id`, nombre, contacto, estado y usuario asociado;
- crear/editar identidad y contacto;
- asociar o desasociar un usuario WordPress, aplicando role, capability y meta;
- aprobar/reactivar;
- desactivar.

Asociar valida usuario existente, ausencia de asociación a otro Courier y Courier existente. Desasociar elimina `_veciahorra_courier_id` y el role/capability Courier cuando corresponda, sin borrar la fila ni historial. Aprobar exige que nombre y teléfono sean válidos y que exista exactamente una asociación de usuario. Desactivar no altera deliveries históricas.

## 13. Contrato implementable y certificación requerida

El flujo queda determinado así:

`usuario autenticado → capability → meta → Courier approved → disponibles elegibles → CAS de aceptación → assigned → picked_up → delivered → proyección Cliente/Admin`.

La implementación posterior debe certificar, como mínimo:

- instalación nueva y actualización desde esquema `0.24.0` a `0.25.0` sin pérdida;
- rechazo de las representaciones `approved_at` e `is_approved` como autorización;
- resolución de identidad y aislamiento entre usuarios;
- exclusión de pickup, no pagadas, asignadas, canceladas y snapshots incompletos;
- dos Couriers concurrentes: exactamente un ganador CAS;
- repetición idempotente sin tracking duplicado;
- ownership en `picked_up` y `delivered`, y rechazo de saltos;
- dirección persistida en Checkout y congelada por Delivery;
- privacidad de las respuestas Courier;
- Courier inactive conserva historia pero no opera;
- reflejo de `assigned`, `picked_up` y `delivered` en las proyecciones existentes de Cliente/Admin.

No quedan alternativas normativas entre `status`, `approved_at` e `is_approved`; no queda una regla de elegibilidad abierta; no se introduce `accepted`; y ninguna dirección efímera del frontend actúa como autoridad.
