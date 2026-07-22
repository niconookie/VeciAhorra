# Certificación final de la Serie 35 — Administración operativa de Stores

## Veredicto

**Serie 35 certificada con limitaciones ambientales no funcionales.** El flujo
Listado Store → detalle → edición → lifecycle → eliminación segura → Inventory
contextual → retorno canónico es coherente entre rutas, contratos, frontend,
REST, servicios y repositorios. No quedan defectos funcionales demostrados en
el alcance. Chrome headless no alcanzó los harnesses por un fallo de GPU y
Node.js no está instalado; ambas limitaciones fueron compensadas con contratos,
revisión estática, regresiones PHP y lint.

## Rango certificado

Base anterior a la serie: `f6dfacb`.

1. `0c8e591` — Store CRUD gobernado y contrato lifecycle.
2. `aad7590` — transiciones lifecycle atómicas.
3. `7965897` — eliminación referencial segura.
4. `dc0d9a1` — REST lifecycle administrativo.
5. `5a48084` — listado administrativo operativo.
6. `d5bfee8` — diseño del detalle operativo.
7. `ad8704a` — shell del detalle.
8. `65ef453` — lectura REST y render seguro.
9. `62275a0` — edición comercial integrada.
10. `3dcf71a` — acciones lifecycle inline.
11. `a454df6` — eliminación segura desde el detalle.
12. `15b520b` — navegación Store → Inventory.

Los doce commits forman una secuencia lineal en `main`, sin commits intermedios
ajenos. Antes de esta certificación, `main` estaba 12 commits por delante de
`origin/main` y cero por detrás.

## Lifecycle y CAS

Los estados contractuales son `draft`, `in_review`, `rejected`,
`approved_inactive`, `active` e `invalid`. Las únicas transiciones ejecutables
son draft → in_review, in_review → draft/approved_inactive/rejected, rejected →
draft, approved_inactive → active y active → approved_inactive. `invalid` no
produce acciones.

`StoreTransitionService` obtiene las autoridades persistidas y ejecuta CAS
sobre `status`, `onboarding_status` y `approved_at`. Sólo una fila afectada es
éxito. Cero filas se clasifica como modificación concurrente o recurso ausente,
sin retry automático. El frontend no predice el estado: después de mutar hace
una lectura autoritativa independiente.

Las invariantes se conservan: active exige onboarding complete; todo estado
aprobado exige `approved_at`; una Store aprobada no vuelve directamente a
draft; la edición comercial no escribe `status`, `onboarding_status` ni
`approved_at`.

## CRUD, listado y detalle

Creación y actualización usan allowlists de campos, validación específica y
mensajes locales. La edición sólo transporta los once campos comerciales y usa
el nonce heredado de Store, separado del nonce REST. Cancelar no genera red y
el guardado realiza como máximo un POST seguido de un GET autoritativo.

El listado conserva búsqueda, lifecycle, status, orden, paginación y retorno al
detalle. Descarta respuestas obsoletas y realiza un fetch por interacción. El
EXPLAIN contractual pasó sin degradación observada.

El detalle sólo acepta `action=view` e ID decimal canónico. Rechaza duplicados,
arrays y acciones desconocidas; el shell inválido no carga assets. El shell
válido inyecta configuración mínima y realiza un único GET inicial. El DTO de
veinte campos se valida antes del render. `invalid` se representa con HTTP 200,
cero acciones y sin navegación contextual a Inventory.

## Edición, lifecycle inline y estados frontend

Edición sólo aparece cuando `allowed_actions` contiene `save`. Lifecycle muestra
únicamente `submit_for_review`, `return_to_draft`, `approve`, `reject`,
`activate` y `deactivate` presentes en `allowed_actions`; excluye `save` y
`delete_if_unreferenced`. Las confirmaciones son inline, cancelar no genera
requests y cada operación emite como máximo un POST.

La máquina distingue loading, reading, editing, saving, confirming,
transitioning, confirming_delete, deleting, navigating, uncertain, error y
abandoned. Edición, lifecycle y eliminación son mutuamente excluyentes.
`uncertain` retira controles y no se recupera mediante load; `abandoned` invalida
secuencias, aborta transportes y no vuelve a renderizar; navigating retira la
instancia antes de abandonar la pantalla.

## Eliminación segura

El transporte es `DELETE /veciahorra/v1/stores/{id}`, same-origin, con
`Accept: application/json`, `X-WP-Nonce`, sin body ni `Content-Type`. Sólo se
ofrece con `delete_if_unreferenced` y exige confirmación literal del
`business_name`. Únicamente 204 es éxito; después navega inmediatamente al
retorno canónico y no ejecuta GET.

La eliminación inspecciona Inventory, cart items, reservations, orders y
deliveries dentro de la operación segura. Cada dominio bloquea individualmente;
no existe cascada, borrado parcial ni exposición de IDs. `store_referenced`
conserva el DTO; conflictos recargan una vez; 404 retira controles; red, 500 y
2xx inesperados entran en estado incierto.

## Navegación Store → Inventory

Contratos exactos:

```text
admin.php?page=veciahorra-inventory&minimarket_id={storeId}&return_store_id={storeId}
admin.php?page=veciahorra-inventory&action=create&minimarket_id={storeId}&return_store_id={storeId}
admin.php?page=veciahorra-stores&action=view&id={storeId}
```

Las URLs se generan con `admin_url()` y `add_query_arg()` y se revalidan en
JavaScript por protocolo, origen, ruta, página, acción, ID, credenciales,
fragmento, claves permitidas y duplicados. No se aceptan aliases, URL de retorno
arbitraria, referer ni History API.

`minimarket_id` exige decimal canónico positivo dentro del rango entero. Vacío,
cero, negativos, signo, ceros iniciales, decimal, exponencial, Unicode, CRLF,
overflow, duplicados y arrays se rechazan. El repositorio aplica el mismo filtro
preparado a filas y count, preserva paginación y no añade N+1.

Matriz certificada:

| Modo | Product | Store |
|---|---|---|
| Creación ordinaria | seleccionable | seleccionable |
| Contexto Product | bloqueado | seleccionable |
| Contexto Store | seleccionable | bloqueada |
| Edición | bloqueado | bloqueada |

El doble contexto se rechaza. En contexto Store no se construye ni inicializa
el selector Store y no se ejecuta su GET de búsqueda. La Store visible, el ID
durable y `minimarket_id` del payload comparten la misma autoridad de estado.
Product conserva selección explícita, teclado, debounce y control de respuestas
obsoletas. Duplicados e integridad referencial siguen bajo autoridad backend.

## Nonces, transporte y seguridad

Lectura, lifecycle y DELETE usan nonce REST mediante `X-WP-Nonce`. Edición
comercial usa `_wpnonce` y la acción Store existente. No se mezclan.

Cada aplicación centraliza su fetch real, limita métodos, valida JSON y separa
AbortController/secuencias. DELETE 204 no intenta parsear JSON. La auditoría de
los módulos involucrados no encontró `innerHTML`, `outerHTML`,
`insertAdjacentHTML`, eval, Function, `document.write`, storage, cookies, Cache
API, logs sensibles ni `document.referrer`. El render usa creación de nodos,
`textContent`, atributos validados y `replaceChildren`.

## Accesibilidad y responsive

Listado, detalle, formularios y confirmaciones conservan headings, labels,
regiones, alerts, `aria-busy`, `aria-invalid`, `aria-describedby`, foco de
entrada/retorno/error, botones reales y enlaces reales. Los selectores Product
y Store mantienen semántica combobox/listbox y teclado.

CSS y DOM no introducen anchuras rígidas nuevas; grids y acciones permiten
ajuste/apilado a 375 px. La comprobación visual automatizada no fue posible
porque Chrome abortó antes de cargar HTML, por lo que responsive queda
certificado estructuralmente y conserva como riesgo residual una pasada visual
humana.

## Pruebas y verificaciones

Pasaron 31 pruebas no-browser: las 25 regresiones obligatorias disponibles y
seis adicionales de Inventory (create/update requests, controller, repository,
migration y lock service). Cubren lifecycle, CAS, referencias, rutas, REST,
listado, EXPLAIN, detalle, edición, acciones, eliminación, contexto Inventory,
servicios y regresiones Catalog/Cart/Orders/Reservations/Delivery.

Los harnesses Store Detail Shell/Edit/Lifecycle/Delete, Product Selector, Store
Selector y Product Context se intentaron una vez y se reintentaron una sola vez.
Todos abortaron antes del HTML con `GPU process isn't usable`. No existe harness
browser separado para Store Context; su contrato ejecutable no-browser pasó.
No se modificaron GPU, caché, perfiles ni ambiente.

`checkout-validation-test.php` mantiene el bloqueo preexistente
`InventoryRepositoryInterface`, ajeno a Serie 35. Node.js no está instalado y
no se intentó instalar. Los 44 PHP modificados por el rango pasaron `php -l`.
`git diff --check` y `git diff --cached --check` estaban limpios.

## Defectos y riesgos residuales

No se encontraron defectos nuevos durante la certificación integral. Los
defectos del cierre 35.3.7 —retorno canónico, estados no autoritativos,
duplicados de URL y decimal canónico de `minimarket_id`— ya están corregidos en
`15b520b` y sus regresiones pasan.

Riesgos residuales no bloqueantes:

1. Falta validación visual headless/375 px por el fallo ambiental de Chrome.
2. Node.js ausente impide `node --check`; se compensó con revisión y harnesses.
3. Checkout Validation conserva un binding preexistente ajeno a la serie.
4. No existe un harness browser independiente para Store Context.

## Cierre

La Serie 35 satisface sus criterios de aprobación: lifecycle y CAS conservan
autoridad; edición no modifica lifecycle; acciones derivan de
`allowed_actions`; eliminación es referencial; uncertain es seguro; Inventory
usa rutas y filtros estrictos; nonces y transportes están separados; DOM y
accesibilidad permanecen seguros; y no quedan defectos funcionales pendientes.
