# Hito 37.4 — Detalle administrativo operacional read-only de Orders

## 0. Veredicto ejecutivo

El detalle ya está soportado en la capa de lectura: `OrderAdminReadService::getOrderDetail(int)` obtiene un `OrderAdminDetail`, y para construirlo delega los hechos a `OrderOperationalFactsAssembler` y la interpretación a `OrderOperationalStateResolver`. El contrato actual incluye identidad, Store, Checkout, relación Checkout–Order, líneas, reservas, pago, procesamiento, fulfillment, totales, navegación, estado operacional e inspector de hallazgos.

La infraestructura mínima que falta es:

1. exponer ese método mediante `GET /veciahorra/v1/orders/{id}/admin`;
2. despachar en `OrdersPage` entre listado y detalle con una ruta administrativa estricta;
3. crear el shell y los módulos frontend exclusivos del detalle;
4. activar `view` en el listado y transportar un contexto de retorno validado;
5. extender mínimamente el read model público para representar la relación con el comprador sin exponer PII y contraer un identificador de sesión de pago innecesario.

No se necesita una nueva consulta de dominio, un resolver paralelo, acciones mutables, CAS ni cambios en `POST /orders`.

Decisiones canónicas:

- página: `admin.php?page=veciahorra-orders&action=view&order_id={id}`;
- REST: `GET /veciahorra/v1/orders/{id}/admin`;
- entrada REST: solo el ID de ruta, sin query params ni body funcional;
- presupuesto del servicio ya certificado: exactamente 3 operaciones de consulta y 1 solicitud REST; la implementación SQL corresponde estructuralmente a 3 statements y debe certificarse en integración en 37.4;
- acciones: `allowed_actions = ["view"]`, `mutable_actions = []`;
- retorno: parámetros `return_*` individuales, cerrados y validados; nunca una URL de retorno opaca.

## 1. Evidencia auditada y fronteras

Se revisaron los contratos e implementaciones reales:

- `OrderAdminDetail` es un DTO readonly que devuelve exactamente el array recibido.
- `OrderAdminReadRepositoryInterface` ya declara `findBase(int)` y `loadFacts(array)`.
- `OrderAdminReadRepository::findBase()` hace la lectura base con Checkout y Store; `loadFacts()` ejecuta dos consultas `UNION ALL`, una comercial y otra operacional.
- `OrderAdminReadService::getOrderDetail()` resuelve el detalle, añade `operational` e `inspector` y traduce persistencia a códigos seguros.
- `OrderOperationalFactsAssembler::safeDetail()` define la superficie pública existente.
- `OrderOperationalStateResolver` es la única autoridad de estados derivados, dimensiones, consistencia, findings, timeline y versión operacional.
- `OrdersPage`, `OrdersAdminRoutes`, `admin-list.php` y los módulos `orders/{api,state,navigation,view,app}.js` fijan el patrón de la Serie 37.
- Las pruebas manuales de read model, assembler, resolver, presupuesto, seguridad, REST, infraestructura y navegador certifican equivalencia listado/detalle, determinismo, salida segura y ausencia de N+1.

La implementación actual ya soporta el detalle en backend, pero todavía no lo publica por REST ni lo presenta. No existe snapshot persistido del nombre del producto; `product_name_snapshot` es `null` y `snapshot_name_status` es `not_persisted`. Tampoco existe un grupo público `customer`: `customer_id` se usa internamente para invariantes y se excluye deliberadamente de las superficies certificadas.

### Extensión mínima del contrato

Para cumplir “revisar su relación con cliente o comprador” sin romper la frontera de privacidad, se propone añadir a `safeDetail()`:

```json
"customer": {
  "relationship_status": "linked"
}
```

`relationship_status` es un enum cerrado `linked|unknown`. `linked` significa que el `customer_id` no nullable de la fila de Order es un entero positivo; `unknown` cubre exclusivamente una lectura anómala que no permita verificar esa referencia. `unlinked` no se incluye: `OrderSchema` exige `customer_id` unsigned y no nullable, por lo que una Order válida no representa ese estado. La autoridad es la referencia persistida en Order, pero la salida es una proyección derivada que no expone su valor.

La justificación operacional es limitada: el administrador necesita saber si la Order conserva una relación durable con un comprador para interpretar inconsistencias de ownership, no conocer quién es. Se prohíbe exponer `customer_id`, `user_id`, nombre, username, `user_login`, correo, `user_email`, teléfono, billing, dirección, coordenadas o identificadores externos correlacionables. Tampoco se puede inferir identidad desde Checkout, metadata de pagos, logs o payloads. Si producto exige identificar nominalmente al comprador, eso es una decisión de privacidad bloqueante y queda fuera de 37.4 hasta definir finalidad, capability específica, retención y enmascaramiento. No se agrega una consulta a Customers.

La prueba futura debe envenenar base, Checkout y bundles con todos esos campos y valores únicos, afirmar por igualdad la forma exacta `customer.relationship_status`, recorrer recursivamente claves y valores serializados para demostrar que ningún dato prohibido aparece, y cubrir tanto `linked` como una base anómala `unknown`.

El contrato actual también expone `payment.session.public_id`. Aunque las pruebas lo denominan seguro, no es necesario para el objetivo operacional del detalle y puede correlacionar una sesión. La extensión mínima incluye retirarlo de la proyección pública de `safeDetail()` antes de publicar el endpoint. Esta es una **contracción futura explícita**, no comportamiento actual. Los IDs técnicos internos estrictamente necesarios para relacionar autoridades pueden permanecer; ningún ID, token o referencia del proveedor puede presentarse como credencial o enlace.

No se propone enriquecer productos o Inventory actuales: alteraría el presupuesto, confundiría snapshot con catálogo vigente y no recuperaría una identidad histórica que nunca se persistió.

## 2. Alcance funcional

La pantalla es un inspector operacional administrativo y exclusivamente read-only. Permite:

- identificar la Order por ID durable, relación con Checkout y Store;
- leer estado contractual persistido y estado operacional derivado;
- revisar modalidad, importes persistidos, líneas y referencias históricas disponibles;
- revisar pago saneado, reservas, procesamiento, fulfillment, delivery, timeline y consistencia;
- volver al listado conservando solo un contexto previamente validado.

Quedan prohibidos:

- cambios de estado, reintentos, confirmaciones de pago y reparaciones;
- liberar, recrear o consumir reservas;
- modificar fulfillment, delivery, comprador, Store o líneas;
- eliminar la Order;
- introducir lifecycle mutable, CAS, formularios de mutación o endpoints de escritura;
- exponer SQL, excepciones, rutas locales, leases como controles editables, secretos o payloads del proveedor.

La presencia de `operational_version` es informativa; no habilita concurrencia mutable.

## 3. Ruta administrativa y canonicalización

### 3.1 Gramática

Ruta canónica:

```text
admin.php?page=veciahorra-orders&action=view&order_id=37
```

El listado canónico es `admin.php?page=veciahorra-orders` más sus parámetros de listado válidos. `action` ausente selecciona listado. El único valor de `action` aceptado es el escalar exacto, sensible a mayúsculas, `view`. `order_id` debe coincidir con `^[1-9]\d*$`, caber en entero PHP positivo y su representación debe ser idéntica a `(string)(int)$raw`; se rechazan `0`, signo, espacios, decimal, exponencial, ceros iniciales, overflow, array y objeto.

### 3.2 Entradas anómalas

| Entrada | Resultado |
|---|---|
| `action=view` sin `order_id` | aviso administrativo seguro y enlace al listado; no REST |
| `order_id` sin `action=view` | se elimina mediante `replaceState`; se muestra listado |
| ID inválido/no canónico/array | estado de entrada inválida y retorno al listado; no REST |
| `action` desconocida, array o duplicada | retorno al listado canónico; no REST |
| `page` ausente/distinta | no corresponde a esta pantalla |
| parámetro funcional desconocido | se elimina de la URL; no se propaga |
| parámetro conocido duplicado | se descarta ese parámetro; para `action`, `order_id` o `page`, se invalida la vista |

PHP colapsa claves duplicadas en `$_GET`; por ello el futuro despachador debe analizar pares de `QUERY_STRING` con un helper pequeño y probado, sin usar `parse_str` como única autoridad. Debe decodificar una sola vez, limitar cantidad/longitud de pares y rechazar claves con sintaxis de array. El frontend repite la validación con `URLSearchParams`.

La canonicalización usa `history.replaceState`, nunca `pushState`, antes de iniciar la carga. Se construye desde `admin_url('admin.php')`/`config.adminUrl` y una allowlist local. No se redirige a una URL recibida del backend. Entradas inválidas no provocan una solicitud de detalle ni bucles de redirección.

### 3.3 Parámetros de retorno

Se permiten exclusivamente:

| Detalle | Listado | Validación |
|---|---|---|
| `return_search` | `search` | 1–100 caracteres, trim idéntico y reglas actuales de `readOrdersUrl()` |
| `return_store_id` | `store_id` | entero positivo canónico |
| `return_order_status` | `order_status` | `reserved`, `paid`, `delivered` |
| `return_fulfillment_mode` | `fulfillment_mode` | `pickup`, `delivery` |
| `return_date_from` | `date_from` | fecha real `Y-m-d` |
| `return_date_to` | `date_to` | fecha real `Y-m-d`; si el rango se invierte se omiten ambas |
| `return_sort` | `sort` | `newest`, `oldest`, `updated`, `total_desc`, `total_asc` |
| `return_paged` | `paged` | entero positivo canónico |
| `return_per_page` | `per_page` | `20`, `50`, `100` |

Todos se conservan porque juntos definen la posición observable en el listado certificado. Los defaults (`newest`, página 1, 20) pueden omitirse al serializar. Cada valor inválido o duplicado se omite individualmente, sin invalidar el ID válido. Si solo una fecha es válida se conserva de forma independiente; si ambas son válidas pero `return_date_from > return_date_to`, se omiten ambas y los otros siete valores sobreviven. Nunca se copian nonce, `_wpnonce`, tokens, `rest_route`, URLs, fragmentos, `return_url` ni claves desconocidas. Sin contexto válido, el retorno sigue siendo el listado canónico.

El listado crea el enlace solo si el item contiene `allowed_actions` con `view`, `mutable_actions` vacío e ID entero positivo. La URL se construye localmente desde el estado validado del listado, no desde campos `navigation` ni URLs del backend. El enlace reemplaza el texto “Detalle disponible…” únicamente cuando REST, shell y pruebas de 37.4 estén certificados.

## 4. Endpoint REST

Registrar, dentro de `OrdersAdminRoutes`, una segunda ruta:

```text
GET /veciahorra/v1/orders/(?P<id>[^/]+)/admin
```

El patrón deliberadamente amplio permite que el callback clasifique `0`, `01`, `-1`, `1.0` y overflow uniformemente como 422, en vez de convertirlos accidentalmente en 404 de routing. Barras adicionales no coinciden. El callback:

1. reutiliza la política y, preferentemente, el método `authorize()` existente: anónimo o nonce ausente 401; capability insuficiente o nonce inválido 403. El orden actual comprueba login, capability, presencia de nonce y validez del nonce; por tanto un usuario autenticado sin capability recibe 403 aunque además no envíe nonce, decisión que debe conservarse y probarse;
2. exige método GET;
3. rechaza cualquier query param, parámetro desconocido/array, body funcional o ID no canónico con 422;
4. llama exclusivamente `OrderAdminReadService::getOrderDetail($id)`;
5. devuelve el `OrderAdminDetail::toArray()` sin reconstruir hechos;
6. traduce `not_found` a 404; `read_failed` a 500; cualquier `Throwable` a `orders_admin_detail_read_failed`, 500;
7. aplica `Cache-Control: private, no-store` a toda respuesta de esta ruta.

Forma de error segura:

```json
{"error":{"code":"order_not_found"}}
```

Los códigos públicos son cerrados: `invalid_parameters`, `order_not_found`, `orders_admin_detail_read_failed`. No se devuelve el mensaje de excepción. Los errores de permission callback siguen el envelope de WordPress y sus códigos actuales (`rest_not_authenticated`, `rest_nonce_missing`, `rest_forbidden`, `rest_cookie_invalid_nonce`); no se deben confundir con el envelope propio del callback.

WordPress ejecuta `permission_callback` antes del callback, por lo que `response()` no puede añadir por sí solo el header a 401/403. La implementación futura debe añadir `Cache-Control` mediante un filtro `rest_post_dispatch` estrictamente acotado a esta ruta (y al listado existente si se comparte), retirarlo fuera de ese scope y probar el header en 401/403. No se registra un filtro global que altere otras APIs.

La ruta no contiene SQL ni conoce el repositorio: recibe, valida, autoriza, delega y traduce errores. La vista PHP y JavaScript tampoco consultan SQL. Ninguna capa REST/frontend reconstruye estados, findings o timeline.

No se añade parámetro de expansión, locale, fields, include, return ni navegación al endpoint. No se toca `POST /orders`, `OrderRoutes`, `OrderController` ni `OrderService`.

## 5. Contrato exacto de respuesta

`OrderAdminDetail` continúa siendo el único DTO; actualmente es un wrapper readonly de array y no valida forma por sí mismo. La forma pública la construyen `safeDetail()` y `OrderAdminReadService`. La siguiente es la forma actual, más la extensión mínima futura `customer` y la contracción explícita de sesión indicada abajo.

Convenciones: IDs son `int>0`; importes son strings decimales persistidos normalizados; timestamps válidos son strings UTC ISO-8601 o `null`; listas pueden estar vacías; relaciones singulares ausentes son `null`.

| Grupo | Campos y tipos | Autoridad / política |
|---|---|---|
| `identity` | `id:int`, `persisted_status:string`, `created_at:?string`, `updated_at:?string` | Order persistida; el status no se presenta como derivado |
| `customer` (extensión futura) | `relationship_status:"linked"|"unknown"` | proyección de la referencia durable no nullable de Order; sin ID ni PII |
| `store` | `id:int`, `exists:bool`, `business_name:?string`, `current_status:?string` | ID histórico en Order; nombre/status son enriquecimiento actual nullable |
| `checkout` | `null` o `id:int`, `public_id:?string`, `status:string`, `fulfillment_method:?string`, `total:string`, `currency:string`, `created_at:?string`, `updated_at:?string` | Checkout persistido; ausencia explícita |
| `checkout_order` | `null` o `id:int`, `checkout_id:int`, `order_id:int` | relación persistida |
| `lines[]` | `id:int`, `product_id:int`, `inventory_id:int`, `product_name_snapshot:null`, `snapshot_name_status:"not_persisted"`, `quantity:int`, `unit_price:string`, `subtotal:string` | `order_items`; precio/cantidad congelados; no usar catálogo vigente |
| `reservations[]` | `id`, `order_id`, `product_id`, `inventory_id`, `minimarket_id`, `quantity` ints; `status:string`; fechas `reserved_at`, `expires_at`, `released_at`, `updated_at` nullable | reservas persistidas saneadas |
| `payment.session` | actualmente nullable; `id`, `checkout_id`, `payment_id`, `create_version` ints cuando existen; `public_id`, `status`, importes/moneda y fechas permitidas. Contrato futuro: la misma forma **sin `public_id`** | sesión persistida saneada; contracción explícita del identificador correlacionable; sin token/provider payload |
| `payment.financial_evidence` | nullable; `id`, `payment_session_id`, `status`, `validated:bool`, `amount`, `currency`, fechas permitidas | evidencia financiera mínima, no payload completo |
| `payment.payment` | nullable; IDs relacionales, `amount`, `currency`, `status`, `paid_at`, `created_at`, `updated_at` | pago durable |
| `payment.reconciliation` | nullable; IDs, status, contadores/versiones y fechas permitidas | autoridad operacional, solo lectura |
| `processing` | `business_completion`, `delivery_completion`, `fulfillment_completion`, cada uno nullable con IDs relacionales, status, mode, attempts/lease/version y fechas permitidas | autoridades persistidas; leases informativos |
| `fulfillment` | `mode:?string`, `deliveries[]`, `tracking[]` | delivery/tracking saneado; tracking solo `assigned`, `picked_up`, `delivered` |
| `totals` | `line_count:int`, `unit_count:int`, `total:string`, `currency:string` | conteos SQL y total persistido |
| `navigation` | `order_id:int`, `store_id:int`, `checkout_id:?int`, `delivery_ids:int[]`, `product_ids:int[]`, `inventory_ids:int[]` | referencias, nunca URLs ni autorización |
| `operational` | `primary_state:string`, `dimensions:object`, `consistency:object`, `timeline:array`, `operational_version:string`, `allowed_actions:["view"]`, `mutable_actions:[]`, `requires_attention:bool` | exclusivamente resolver |
| `inspector` | `classification:string`, `finding_count:int`, `blocker_count:int`, `warning_count:int`, `by_dimension:object` | agrupación de los mismos findings, sin inferencia nueva |

`dimensions` tiene exactamente las claves actuales: `payment_session`, `financial`, `reservations`, `processing`, `delivery`, `fulfillment`, `commercial`. `consistency` contiene `classification`, `findings`, `blockers` y `warnings`. Cada finding conserva `code`, `severity`, `affected_dimension`, `blocker`, `historical_tolerance`, `title`, `description` y evidencia mínima segura definida por `ConsistencyFinding`/`InvariantCatalog`; la UI no inventa textos a partir de excepciones.

`allowed_actions=["view"]` se conserva por equivalencia contractual con el listado y significa que el recurso puede consultarse; dentro del propio detalle no crea una segunda acción ni un control útil. Debe reevaluarse solo si en el futuro el contrato distingue “acción de navegación” de “acción disponible en el recurso”. En 37.4 no se elimina, no se convierte en mutación y `mutable_actions` permanece exactamente vacío.

Fallbacks:

- Store borrada: conservar `store.id`, `exists=false`, nombre/status `null`, mostrar “Store actual no disponible”.
- Checkout o relación ausente: `null`, mostrar “Sin relación disponible” y dejar que el resolver clasifique.
- comprador: `relationship_status=unknown` únicamente si la referencia persistida no puede validarse; nunca intentar resolver identidad.
- producto/Inventory huérfano: conservar IDs históricos; mostrar “Producto histórico #ID; nombre no persistido”.
- valor/timestamp ausente: mostrar “No disponible”, nunca cero o fecha inventada.
- colección ausente: lista vacía; distinguir de error general mediante `consistency`.

Está prohibido ampliar el DTO con nombre, username, `user_login`, email, `user_email`, teléfono, billing, dirección, coordenadas, `customer_id`, `user_id`, SQL, stack traces, rutas locales, logs, mensajes del SDK, mensajes internos, tokens, códigos de autorización, `provider_reference`, fingerprints financieros, PAN o fragmentos de tarjeta y payloads/respuestas completas del proveedor.

## 6. Identidad y snapshot comercial

El ID de Order, `minimarket_id`, estado, total, timestamps y líneas son la memoria durable. Checkout aporta modalidad, moneda y total propios. Store `business_name/current_status` es solo enriquecimiento actual y debe etiquetarse como tal.

No existe subtotal separado de Order fuera de la suma de subtotales de líneas. El frontend puede mostrar cada `subtotal` persistido y el `total` persistido, pero no debe crear un campo contractual “subtotal” sumando líneas. Las comparaciones aritméticas ya pertenecen al resolver (`order_item_subtotal_mismatch`, `order_total_mismatch`, `checkout_total_mismatch`).

Los únicos precios autorizados son `lines[].unit_price` y `lines[].subtotal`; jamás se consulta Product o Inventory vigente para recalcular. El nombre histórico no existe: se declara honestamente como no persistido. Una futura migración de snapshot sería otro hito y no debe rellenar retrospectivamente nombres sin evidencia.

## 7. Estado operacional, consistencia y hallazgos

La presentación separa:

1. **contractual persistido:** `identity.persisted_status` y statuses de autoridades;
2. **hechos observados:** grupos comerciales/operacionales saneados;
3. **derivado:** `operational.primary_state` y `dimensions`;
4. **consistencia:** classification y findings;
5. **desconocido/no disponible:** null, lista vacía o estados `unknown/degraded`;
6. **advertencia/inconsistencia:** severidad y blocker del catálogo.

El detalle no contiene condicionales que infieran un estado a partir de pago, reserva o delivery. Solo mapea códigos conocidos a etiquetas y clases de una allowlist local. Un estado desconocido se muestra literalmente como “Desconocido”, no como éxito o error.

Presentación:

- sin findings: “No se observaron hallazgos de consistencia”;
- `info`/`warning`: “Información”/“Advertencia”, tono sobrio;
- `error`/`critical` o blocker: “Inconsistencia detectada”;
- `degraded`/`unknown`: “Información incompleta”, sin afirmar corrupción;
- relaciones ausentes: texto específico y el finding certificado, si existe.

Ningún finding se transforma en botón, enlace reparador o instrucción para mutar.

## 8. Timeline

El timeline es exactamente `operational.timeline`, construido en memoria por el resolver a partir de Checkout, Order, reservas, sesión/evidencia/pago/reconciliación, completions, deliveries y tracking permitido.

- orden: `occurred_at`, `source_rank`, `source_id` string, `type`, secuencia original;
- desempate final: `sequence` reasignada y `key` SHA-256 estable;
- campos por evento: `key`, `type`, `occurred_at`, `source`, `source_id`, `source_rank`, `sequence`, `label`, `tone`, `metadata`;
- etiqueta: usar `label` saneada por resolver; `type` puede mapearse mediante allowlist local accesible;
- una fuente sin ID, fecha ausente o fecha inválida no produce evento;
- eventos contradictorios se muestran juntos y se explican mediante findings, no se eliminan;
- no se inventan estados sin fecha ni hitos “esperados”.

No hay consulta por evento ni solicitud REST secundaria. Si se desea mostrar “sin fecha”, debe hacerse fuera del timeline, en la sección de autoridad correspondiente.

El resolver ya entrega el timeline ordenado y con desempate determinista. JavaScript conserva ese orden exacto: puede filtrar solo por una allowlist de estructura inválida para fallar el contrato completo, pero no reordena, agrupa semánticamente ni sintetiza eventos.

## 9. Líneas y reservas

Orden contractual: normalizar `lines` en backend por `created_at,id` durante el ensamblado, o como mínimo por `id ASC` si no se expone fecha; no confiar en el orden de filas de un `UNION ALL`. La recomendación mínima es ordenar por `id ASC` en `safeDetail()` y certificarlo.

Cada línea muestra ID, producto/Inventory históricos por ID, cantidad, precio unitario congelado y subtotal persistido. El total de línea es `subtotal`; no se vuelve a multiplicar para sustituirlo. Una divergencia se presenta mediante el finding del resolver.

Reservas se ordenan por `(reserved_at, id)`. Referencias huérfanas conservan sus IDs. Ausencia de nombre actual no borra la línea y no dispara enriquecimiento.

## 10. Arquitectura frontend

Reutilizar conceptos y utilidades pequeñas; crear módulos específicos evita acoplar detalle al ciclo mutable del listado:

```text
assets/admin/js/modules/orders/
  detail-api.js          transporte y validación estructural
  detail-state.js        loading/success/not_found/error, abort y secuencia
  detail-navigation.js   parse/canonical/return URL
  detail-view.js         render DOM seguro y accesible
  detail-app.js          inicialización y lifecycle
```

Mantener los cinco módulos de listado. Extraer a un módulo compartido solo validadores puros realmente idénticos (`positive`, fecha, enums, contexto de lista); no convertir `state.js` en una máquina con ramas list/detail.

`OrdersPage` encola `app.js` para listado o `detail-app.js` para detalle y entrega configuración mínima: `restUrlBase`, nonce y `adminUrl`. Crear `Views/admin-detail.php`. Mantener `orders.css`, con selectores bajo `.veciahorra-orders-admin`; separar `orders-detail.css` solo si el crecimiento vuelve difícil certificar el alcance.

## 11. Carga, concurrencia e History API

- exactamente una solicitud inicial `GET` al endpoint del ID;
- cero solicitudes por línea, timeline, finding o relación;
- canonicalizar con `replaceState` antes de `load()`;
- un `AbortController` por carga, abortando el anterior;
- secuencia monotónica y descarte de respuestas tardías;
- `AbortError` esperado es silencioso;
- estados: `loading`, `success`, `not_found` (solo HTTP 404) y `error`;
- 401: “La sesión expiró”; 403: “No tienes permisos”; 422: “La solicitud de detalle no es válida”; 500: “No fue posible cargar la Order”; red/JSON/contrato: mensajes genéricos locales. Nunca se muestra `error.message` del backend;
- 422 en una URL producida localmente se trata como error de contrato, no como `not_found`;
- al `pagehide`, abortar y destruir listeners.

El detalle no modifica su ID mediante controles internos. Back/forward normalmente navega entre documentos listado/detalle; si el navegador restaura BFCache, `pageshow` no debe duplicar la carga ya resuelta. `popstate` solo reevalúa si la misma instancia recibe un cambio de URL; compara ID canónico antes de cargar. Un cambio real de ID carga una vez; un cambio solo de `return_*` actualiza el enlace Volver sin recargar el DTO.

## 12. Render seguro, accesibilidad y responsive

Todo dato dinámico usa `textContent`; la estructura usa `createElement`, `replaceChildren` y atributos creados localmente. URLs se construyen con `URL`/`URLSearchParams` desde `adminUrl`. Clases/tone/status provienen de allowlists. Prohibidos `innerHTML` dinámico, `eval`, `Function`, `document.write`, handlers inline, HTML/clases/atributos/URLs del backend y DTOs completos en consola. No guardar PII, DTO ni secretos en `data-*`; solo IDs/páginas locales estrictamente necesarios.

Accesibilidad:

- un `h1` (“Order #ID”), secciones con `h2` y subsecciones con `h3`;
- región de estado `aria-live=polite`, `aria-busy` durante carga;
- error/`not_found` con `role=alert`, `tabindex=-1` y foco programático;
- tras éxito, foco al `h1` solo cuando la navegación sea SPA, no en carga documental normal;
- enlace “Volver a pedidos” primero y último, con nombre accesible;
- tablas con `caption`, `th scope`; a 375 px transformarlas en tarjetas/listas con etiquetas visibles, no scroll obligatorio;
- timeline como lista ordenada semántica con fecha en `<time datetime>`;
- iconos nunca son la única etiqueta; consistencia no depende solo del color;
- controles nativos, foco visible y teclado completo.

Certificar 1440, 1024, 768 y contenedor de 375 px, zoom 200 %, textos largos y cero overflow horizontal. Aplicar `min-width:0`, `overflow-wrap:anywhere`, grids `minmax(0,1fr)` y tablas/cards adaptables.

## 13. Presupuesto de consultas

El **contrato del servicio está certificado en exactamente 3 operaciones de consulta** por `tests/manual/order-admin-read-model-test.php` (`queryCount === 3`) y `tests/manual/order-admin-read-budget-security-test.php` (`afterDetail === 3`, sin consultas al acceder a timeline/inspector). Esas pruebas usan `InstrumentedOrderAdminReadRepository`: certifican la interacción del servicio y que `loadFacts()` representa dos lecturas, no ejecutan el SQL real.

La inspección de `OrderAdminReadRepository` confirma estructuralmente que esas operaciones son exactamente **3 statements SQL**:

| Consulta | Contenido |
|---|---|
| 1 `findBase` | Order + vínculo CheckoutOrder + Checkout + Store; dos subconsultas correlacionadas de conteos forman parte del mismo statement |
| 2 `commerceSql` | líneas y reservas en un `UNION ALL` agrupado por Order |
| 3 `operationsSql` | sesiones, evidencia financiera, reconciliaciones, pagos/vínculos, completions/vínculos, deliveries y tracking en un `UNION ALL` |

Timeline, findings, inspector, dimensiones y navigation se producen en memoria: 0 consultas. La extensión `customer` propuesta usa la base ya cargada: 0 consultas. Producto, imagen y enriquecimientos opcionales no se cargan: 0 consultas. Frontend: 1 solicitud REST y 0 secundarias. El número estructural no cambia con cero, una o muchas líneas ni con relaciones ausentes/huérfanas, porque las dos consultas agrupadas pueden devolver cero o muchas filas sin crear statements adicionales.

Clasificación final: **certificado a nivel del contrato del servicio; confirmado por inspección para el repositorio SQL actual; pendiente de certificación de integración SQL en 37.4**. La futura prueba de integración debe contar queries reales alrededor de `getOrderDetail()` con 0/1/muchas líneas y relaciones presentes/ausentes/huérfanas, y exigir exactamente 3.

No debe descomponerse la consulta operacional por autoridad ni añadirse consulta por línea. Si en el futuro se decide enriquecer nombres o imágenes, deberá redefinirse y certificarse un nuevo presupuesto; no pertenece a 37.4.

## 14. Integración con 37.3

Cambios futuros exactos:

1. `view.js` crea un `<a>` solo para `allowed_actions.includes("view")`, ID válido y `mutable_actions.length===0`;
2. `navigation.js` añade un builder de detalle que serializa los nueve `return_*`;
3. `app.js` entrega el estado ya validado al builder;
4. pruebas actualizan el texto futuro por enlace canónico.

Se conservan filtros, paginación, contrato REST de lista, una solicitud inicial, cero solicitudes por fila y History API actual. No se precargan detalles, no se hacen hover requests y el listado no consume `getOrderDetail()`.

## 15. Matriz de pruebas futura

| Área | Casos mínimos |
|---|---|
| Infraestructura | registro de ruta, DI del mismo service, assets solo en hook/vista correcta, no escrituras |
| REST | 200, 401, 403, 404, 422, 500; nonce/capability; `private, no-store` |
| ID/entrada | positivo; 0, signo, espacio, decimal, exponente, cero inicial, overflow, array, duplicado, query/body desconocido |
| DTO | claves y tipos exactos, null/listas vacías, enum `customer.relationship_status`, ausencia futura de `payment.session.public_id`, actions exactas |
| Seguridad | claves y valores únicos de PII/identidad/payment payload en fixtures envenenadas no aparecen; mensajes 500 seguros |
| Servicio | missing/read failure; equivalencia exacta listado/detalle; assembler/resolver únicos |
| Navegación | link local; nueve `return_*`; cada inválido/duplicado se omite; no nonce/token/desconocidos |
| Canonical | action/ID ausente o desconocido; params extra; `replaceState` sin doble request |
| History/concurrencia | back/forward, BFCache, abort silencioso, secuencia y respuesta tardía |
| Render | sin sinks inseguros; estados `loading/success/not_found/error`; 404 separado de 401/403/422/500; datos hostiles como texto |
| Timeline | orden/desempate, fecha inválida omitida, contradicciones, ausencia sin hitos inventados |
| Líneas | orden estable, congelados, huérfanas, sin precio/nombre vigente |
| Consistencia | sin findings, warning, degraded, unknown, inconsistent, 31 invariantes |
| UX | teclado, foco, live regions, headings, contraste, 1440/1024/768/375, zoom y overflow |
| Presupuesto | interacción instrumentada existente + integración futura: exactamente 3 SQL con 0/1/muchas líneas y relaciones ausentes/huérfanas; 0 extra por timeline/findings; 1 REST |
| Regresión | suites assembler/read models/resolver/listado 37.3; `POST /orders` byte/behavior intacto |
| Inmutabilidad | ausencia de botones/forms mutables, `mutable_actions=[]`, ningún método no-GET nuevo |

## 16. Riesgos y decisiones pendientes

| Riesgo | Impacto | Mitigación | Prueba |
|---|---|---|---|
| PII de comprador | fuga administrativa | enum mínimo sin identificador; prohibir IDs/PII/inferencias | fixture envenenada recursiva/DTO exacto |
| datos sensibles de pago | secreto o fraude | proyección cerrada y retirar `payment.session.public_id` | búsqueda de IDs correlacionables/token/payload/provider |
| relaciones borradas | pantalla engañosa | ID histórico + `exists/null` explícito | Store/Product/Checkout huérfanos |
| snapshot vs actualidad | total/nombre falso | etiquetar enriquecimiento; no recalcular | precio catálogo divergente |
| lógica operacional duplicada | lista y detalle discrepan | assembler + resolver exclusivos | equivalencia escenarios |
| derivado contradictorio | falso estado final | mostrar consistency/findings | matriz de invariantes |
| timeline incompleto | falsa certeza | solo eventos respaldados; ausencia explícita | timestamp ausente/inválido |
| N+1 | degradación | presupuesto 3 SQL | muchas líneas/autoridades |
| parámetros inseguros | open redirect/filtración | allowlists y builders locales | fuzz de URL/duplicados |
| acción prematura | mutación accidental | enlace solo tras certificación; sin botones | inspección DOM/rutas |
| regresión 37.3 | filtros/history rotos | cambios mínimos y suite completa | tests lista/browser |
| duplicados colapsados por PHP | bypass canónico | parser de pares crudos probado | `action=view&action=x` |
| nombre histórico inexistente | identificación ambigua | texto honesto + IDs; no inventar | `not_persisted` |

Decisión pendiente no bloqueante: si diseño visual requiere mostrar versiones/leases técnicas. Recomendación: ocultarlas en resumen y dejarlas solo en una sección “Diagnóstico operacional” para administradores, con etiquetas no accionables. Decisión bloqueante solo si se exige identificar nominalmente al comprador; requiere política de privacidad fuera de este hito.

## 17. Secuencia de microhitos certificables

| Microhito | Objetivo y archivos previsibles | Contratos/pruebas y criterio de cierre | Prohibiciones |
|---|---|---|---|
| 37.4.1 read model privado | ajustar `OrderOperationalFactsAssembler.php`; pruebas read model/security | enum customer, retirar session public ID, orden de líneas; DTO exacto y 3 operaciones pasan | sin REST/UI/PII/nueva consulta |
| 37.4.2 endpoint | `OrdersAdminRoutes.php`; pruebas REST/detail/integración SQL | 200/401/403/404/422/500, headers, entrada cerrada y 3 SQL reales; endpoint certificado | sin SQL en ruta, sin POST/mutaciones |
| 37.4.3 shell y ruta | `OrdersPage.php`, `admin-detail.php`; tests infraestructura/parser | action/ID/duplicados/canonical; shell sin solicitud inválida | sin render de datos ni enlace activo |
| 37.4.4 navegación | `navigation.js`, `view.js`, `app.js`; tests listado | nueve `return_*`, previous/next, 1 request lista y 0 por fila; ida/retorno exactos | sin preload ni cambiar contrato 37.3 |
| 37.4.5 transporte/estado | `detail-api.js`, `detail-state.js`, `detail-app.js` | cuatro estados, mensajes, abort/secuencia/history; exactamente 1 carga | sin render operacional ni estado derivado |
| 37.4.6 identidad/resumen | `detail-view.js`, CSS | identidad, snapshot, customer y operacional; render seguro/a11y básico | sin recalcular ni acciones |
| 37.4.7 líneas | `detail-view.js`, CSS, browser tests | orden, congelados, huérfanos; 0 REST/SQL por línea | sin catálogo vigente |
| 37.4.8 timeline/relaciones | `detail-view.js`, CSS, tests resolver/browser | timeline/findings/pago/reservas/fulfillment/delivery; orden exacto y 0 cargas | sin inferencias/reparaciones |
| 37.4.9 hardening/certificación | CSS y suites PHP/JS/browser | responsive, a11y, seguridad, BFCache y regresión Serie 37; matriz completa aprobada | sin ampliar alcance; commit selectivo posterior |

Cada microhito debe ejecutar primero las pruebas de su frontera y después las regresiones de read models, assembler, resolver y listado. Ninguno habilita mutaciones.

## 18. Arquitectura y archivos recomendados

Arquitectura final:

```text
OrdersPage (dispatch/canonical shell)
  └─ detail-app → detail-navigation + detail-state + detail-api + detail-view
                                      │
                                      └─ GET /orders/{id}/admin
                                           └─ OrderAdminReadService::getOrderDetail
                                                ├─ Repository (3 SELECT)
                                                ├─ FactsAssembler
                                                └─ StateResolver
```

Probables modificaciones futuras:

- `app/Modules/Orders/Admin/OrdersPage.php`
- `app/Modules/Orders/Routes/OrdersAdminRoutes.php`
- `app/Modules/Orders/Services/OrderOperationalFactsAssembler.php` (enum `customer`, retiro de `payment.session.public_id` y orden estable)
- `app/Modules/Orders/Views/admin-list.php`
- `assets/admin/js/modules/orders/{navigation,view,app}.js`
- `assets/admin/css/orders.css`

Probables archivos nuevos:

- `app/Modules/Orders/Views/admin-detail.php`
- los cinco módulos `detail-*.js`;
- pruebas manuales PHP/JS/browser específicas de 37.4.

No se modifica el repositorio ni su interfaz salvo que una prueba revele que el orden estable no puede resolverse en el assembler; no se prevé nueva consulta. Tampoco se modifica `OrderAdminDetail`, que ya envuelve el contrato adecuado.

El primer microhito recomendado es **37.4.1, read model privado**, porque fija y prueba la única extensión/contracción de privacidad antes de publicar el recurso. El endpoint 37.4.2 consume después un contrato exacto ya cerrado.
