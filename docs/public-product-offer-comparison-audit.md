# Microhito 33.2.0 — Auditoría de comparación y selección de ofertas

Fecha de auditoría: 19 de julio de 2026.

## 1. Resumen ejecutivo

La ficha pública ya dispone de contrato y comportamiento suficientes para que el
Microhito 33.2.1 se resuelva con vista, JavaScript, CSS y pruebas. No se requiere
modificar backend, REST, Cart ni el modelo de datos.

El detalle entrega todas las alternativas públicas de un Product. Cada
alternativa contiene la identidad técnica `inventory_id`, la identidad y nombre
comercial del Store, precio y stock. El frontend carga el detalle una sola vez,
normaliza las alternativas, las representa como un `radiogroup` y agrega al
carrito exclusivamente `{inventory_id, quantity: 1}`. Inventory sigue siendo la
autoridad de precio y stock; el servidor vuelve a validar Product, Store, precio
y stock al agregar.

La base técnica es correcta, pero la comparación se comunica de forma implícita.
La página dice “Elige un minimarket” y cada tarjeta completa funciona como radio,
sin una acción textual “Seleccionar”. No muestra cantidad o rango de ofertas, no
renderiza la imagen que ya entrega el contrato y repite el título de la página de
Blocksy. La confirmación separada de selección es precisa, aunque aumenta la
densidad y queda visualmente distante del botón de compra.

El riesgo funcional principal no está en el payload: este queda fijado al
`inventory_id` seleccionado al iniciar el POST. El riesgo está en el contexto
visual durante una petición. Las ofertas continúan seleccionables mientras se
agrega; si el usuario cambia de A a B antes de la respuesta, el servidor recibe A
pero el mensaje de éxito puede aparecer junto al resumen de B. En 33.2.1 debe
impedirse ese desacople bloqueando temporalmente la selección o correlacionando
la respuesta con el Inventory enviado.

## 2. Fuentes y método

Se revisaron:

- `CatalogService`, ruta y pruebas del detalle público;
- vista PHP `product-detail.php`;
- `veciahorra-product-offers.js` y CSS relacionado;
- pruebas PHP y browser de selección y Add to Cart;
- `CartService` como comprobación de revalidación autoritativa;
- restricción única de Inventory;
- `docs/marketplace-visible-audit.md` y
  `docs/public-catalog-category-minimarket-audit.md`;
- arquitectura v1 sobre Product, Inventory, Store y los conceptos reservados;
- ficha pública real de “Coca-Cola 350 cc”, con tres minimarkets, en Chrome
  headless dentro del breakpoint móvil.

La auditoría no efectuó escrituras de negocio ni modificó código productivo,
pruebas o contratos.

## 3. Contrato actual del detalle

Ruta pública de solo lectura:

```text
GET /wp-json/veciahorra/v1/catalog/products/{id}
```

Un ID no positivo es rechazado y un Product inexistente, no activo o sin oferta
pública devuelve ausencia. El endpoint no admite escrituras.

### 3.1 Campos de Product y resumen

| Campo | Contenido | Uso actual en la ficha | Clasificación |
|---|---|---|---|
| `id` | ID entero de Product | valida que la respuesta corresponda a la página | contractual y operativo |
| `name` | nombre de Product | título principal dinámico | contractual |
| `slug` | slug de Product | no usado por la ficha | contractual, presentacional/navegación futura |
| `short_description` | descripción saneada y truncada | descripción preferida por JS | contractual, derivado |
| `description` | descripción completa sin HTML | fallback si no hay resumen | contractual, derivado |
| `image` | URL mediana o `null` | no se renderiza | contractual, hoy sin consumidor |
| `category` | `{id,name}` o `null` | no se renderiza | contractual, clasificatorio |
| `brand` | `{id,name}` o `null` | no se renderiza | contractual, clasificatorio |
| `unit` | `{id,name}` o `null` | no se renderiza | contractual, clasificatorio |
| `min_price` | menor precio público como decimal string | no se renderiza | contractual, derivado |
| `available_minimarkets` | Stores públicas distintas | no se renderiza | contractual, derivado |
| `availability` | `in_stock` para un Product visible | no se renderiza | contractual, resumen derivado |

La URL de imagen y los textos descriptivos son contenido presentacional, pero sus
claves y tipos forman parte del contrato JSON comprobado por pruebas. No son
autoridades comerciales.

### 3.2 Precio y ofertas

| Campo | Contenido | Uso actual |
|---|---|---|
| `price.min` | mínimo del mismo conjunto de ofertas públicas | no se muestra |
| `price.max` | máximo del mismo conjunto | no se muestra |
| `price.offers` | cantidad de filas de oferta pública | no se muestra |
| `offers` | lista completa y ordenada | fuente del selector |

Cada `offers[]` contiene exactamente:

| Campo | Autoridad/origen | Función |
|---|---|---|
| `inventory_id` | Inventory | identidad real seleccionada y enviada a Cart |
| `minimarket_id` | Store relacionado desde Inventory | identidad técnica del vendedor; no se muestra |
| `minimarket` | `Store.business_name` | nombre visible en la alternativa y resumen |
| `price` | Inventory | precio visible de la alternativa |
| `stock` | Inventory | stock visible y validación de normalización cliente |

No existen en el DTO métodos de entrega, costo o tiempo de despacho, ubicación,
horarios, reputación, promociones ni ranking.

### 3.3 Relacionados y metadata

`related_products` contiene hasta seis resúmenes de Products activos, públicos y
de la misma categoría, excluyendo el Product actual. `meta.related_products`
informa la cantidad retornada. Ambos son contractuales porque las pruebas exigen
su presencia y límite; la ficha actual no los renderiza, por lo que hoy son
decorativos desde el punto de vista de esta UI.

## 4. Modelo actual de oferta pública

No existe entidad o tabla `Offer`. Una oferta es una proyección de lectura:

```text
Product activo
  + Inventory activo con stock > 0 y precio válido > 0
  + Store activo
  = offers[] {inventory_id, minimarket_id, minimarket, price, stock}
```

### 4.1 Criterio público

La exposición exige conjuntamente:

1. Product con `status = active` e ID positivo;
2. Inventory con `status = active`;
3. `stock > 0`;
4. precio numérico, finito y mayor que cero;
5. IDs positivos de Inventory, Product y Store;
6. Store con `status = active`.

Onboarding completo y `approved_at` no intervienen en el criterio vigente. Este
es un vacío de gobernanza ya identificado; 33.2.1 no debe cambiarlo.

`min_price`, `price.min`, `price.max`, `price.offers`, `offers` y la existencia
misma del Product usan el mismo conjunto filtrado. No hay divergencia entre el
precio mínimo y las alternativas seleccionables.

### 4.2 Duplicados

La tabla Inventory tiene la restricción única
`inventory_product_minimarket_unique(product_id, minimarket_id)`. Mediante las
escrituras soportadas, un Store no puede tener dos filas para el mismo Product.

`CatalogService` no deduplica el arreglo `offers` por Store. Si datos corruptos,
una migración defectuosa o una base antigua incumplieran la restricción, el
detalle mostraría el Store repetido. Solo el resumen `available_minimarkets`
deduplica por `minimarket_id`. No se recomienda agregar deduplicación visual: la
integridad debe permanecer en Inventory y la UI no debe decidir cuál fila manda.

### 4.3 Orden y límites

Las alternativas se ordenan de forma determinista:

1. precio ascendente;
2. ante igual precio, stock descendente;
3. ante nuevo empate, `inventory_id` ascendente.

No existe parámetro de orden ni entidad Ranking. Tampoco existe un máximo o
paginación contractual de ofertas. La ficha recibe y renderiza toda la lista. El
orden actual permite comparación por precio, pero no debe describirse como
“recomendación”, “mejor oferta” o ranking comercial.

## 5. Flujo actual de selección y carrito

### 5.1 Carga y normalización

1. El shortcode renderiza la vista solo para un `product_id` positivo.
2. El JS lee `data-product-id`.
3. Ejecuta exactamente un GET al detalle.
4. Verifica que el Product recibido tenga el mismo ID.
5. Normaliza cada alternativa: Product, Inventory y Store positivos; precio
   finito y positivo; stock entero positivo.
6. Conserva alternativas válidas y representa las inválidas como “Oferta no
   disponible”.

No hay llamadas REST por oferta ni consultas adicionales para enriquecer las
tarjetas.

### 5.2 Estado y elección

`createSelectionStore()` mantiene:

- `productId`;
- alternativas válidas e inválidas;
- `selectedInventoryId`;
- copia completa de `selection`;
- error de selección.

No existe selección por defecto. Al cargar, la primera tarjeta solo recibe
`tabIndex=0`; sigue con `aria-checked=false` y el botón de carrito continúa
deshabilitado. Esto evita una compra implícita.

Al elegir una tarjeta:

- el ID se toma de `data-inventory-id`;
- `select()` busca ese ID dentro de las alternativas normalizadas;
- solo una alternativa coincide con `selectedInventoryId`;
- se vuelve a renderizar la lista completa;
- la elegida obtiene clase `--selected`, `aria-checked=true` y el texto visual
  “Seleccionada” mediante CSS;
- el resumen muestra nombre, precio y stock de esa misma selección;
- el botón de carrito queda habilitado.

Elegir otra alternativa sustituye la selección anterior, limpia mensajes de
carrito y actualiza resumen, estilos y payload. Elegir el mismo ID es idempotente.
Un ID que no pertenezca a la lista limpia la selección y produce
`invalid_offer`.

Si se recarga el Product, se conserva la selección solamente si el mismo
`inventory_id` continúa presente. Si desapareció, se limpia y se informa
`offer_unavailable`.

### 5.3 Teclado y foco

La lista usa `role=radiogroup`; cada alternativa válida es un `<button
type=button role=radio>`. Implementa roving tabindex:

- flechas derecha/abajo: siguiente, con ciclo;
- flechas izquierda/arriba: anterior, con ciclo;
- Home/End: primera/última;
- espacio o Enter: activa la actual;
- al navegar con flechas se selecciona y se mueve el foco.

El foco visible está definido y `aria-checked` refleja la elección. Las tarjetas
inválidas son `div` con `aria-disabled=true`, no entran al conjunto de radios.
El enfoque es accesible y debe preservarse; sustituirlo por `div` clicables o
acciones separadas sin semántica equivalente sería una regresión.

### 5.4 Add to Cart

Antes del POST se comprueba nuevamente que:

- `selectedInventoryId` sea positivo;
- exista dentro de las alternativas actuales;
- el payload tenga exactamente dos claves;
- `payload.inventory_id` coincida con el ID seleccionado;
- `quantity` sea exactamente `1`.

La solicitud real es:

```json
{
  "inventory_id": 101,
  "quantity": 1
}
```

No envía Product, Store, precio, stock, subtotal, identidad ni total desde el
cliente. Para invitado, la identidad opaca viaja en el header contractual; para
usuario autenticado no se envía ese header.

`CartService` resuelve otra vez el contexto de Inventory y valida Inventory,
Product y Store activos, precio positivo y stock suficiente. Después guarda
Product, Store y snapshot de precio derivados del Inventory. Por tanto, manipular
el nombre, precio o `minimarket_id` del DOM no cambia la alternativa agregada.

### 5.5 Doble clic, respuestas y errores

`isAddingToCart` evita un segundo POST, deshabilita el botón y expone
`aria-busy=true` hasta `finally`. El botón se recupera tanto en éxito como en
error.

En éxito se muestra “Producto agregado al carrito” y se revela el enlace
contractual “Ver carrito”. En error se muestra el mensaje REST si existe o un
fallback genérico. Una respuesta sin `success=true` y `data` se trata como
inválida.

Limitaciones confirmadas:

- las tarjetas de oferta no se deshabilitan durante el POST;
- el Inventory enviado queda correctamente capturado al iniciar, pero la
  selección visual puede cambiar antes de la respuesta;
- el éxito no identifica qué minimarket se agregó, de modo que puede aparecer
  junto a otra selección;
- el frontend acepta y publica literalmente mensajes REST no vacíos, incluso en
  errores 5xx; la prueba actual confirma ese comportamiento. La sanitización y
  clasificación pública de errores debe seguir siendo responsabilidad del
  contrato REST, no resolverse con mensajes inventados en 33.2.1;
- `reload()` no tiene secuencia/AbortController: dos recargas concurrentes pueden
  aplicar la respuesta más antigua al final. El montaje normal llama una vez,
  pero la API expuesta permite recargas adicionales.

## 6. DOM y acoplamientos

### 6.1 Contratos de markup

La raíz es `data-va-product-detail` con `data-product-id`. El JS depende de la
presencia de:

- nombre, descripción, loader y error de Product;
- sección, lista y vacío de ofertas;
- resumen, estado y valores de selección;
- botón, labels de carga, éxito/error y enlace al carrito.

Los encabezados se relacionan mediante IDs generados desde `instanceId`; el
radiogroup usa `aria-labelledby`. El resumen es una región viva y los errores
usan `role=alert`.

### 6.2 Fragilidad observada

Los `querySelector()` iniciales no verifican `null`. Renombrar o retirar cualquier
`data-va-*` requerido causaría fallos en tiempo de ejecución. Las pruebas PHP
comprueban muchos contratos, pero los HTML browser usan markup reducido y no
verifican todos los IDs/labels de la vista real.

Las tarjetas se generan íntegramente en JS. CSS, listeners y pruebas dependen de
`role=radio`, `data-inventory-id`, clases `va-offer-card*` y del hecho de que el
evento delegado pueda hallar `closest('[role="radio"]')`. Un botón textual
“Seleccionar” puede añadirse como contenido de la misma tarjeta, pero no debe
crear una segunda fuente de selección ni anidar otro botón dentro del botón
actual.

El texto “Seleccionada” proviene de `::after`, no del DOM. La información
accesible real es `aria-checked`; ambos estados deben seguir sincronizados.

La función pública `root.vaOfferSelector` y `config.offerSelection` se usan en
pruebas browser. Cambiar sus métodos o forma excedería un refinamiento visual.

## 7. Presentación actual

### 7.1 Hallazgos visuales reales

En la ficha de “Coca-Cola 350 cc” con tres Stores:

- Blocksy muestra un título de página y la vista vuelve a mostrar el nombre del
  Product; el título queda duplicado;
- no se renderiza la imagen del Product pese a estar en el DTO;
- no se muestra un precio global que pueda confundirse con el seleccionado; el
  costo es que tampoco hay resumen “desde” o rango;
- “Elige un minimarket” expresa elección, pero no comparación;
- Store, precio y stock se leen claramente dentro de cada tarjeta;
- no hay texto “Seleccionar”; la affordance depende del borde, cursor y
  conocimiento de que toda la tarjeta es interactiva;
- antes de elegir, el bloque grande “Oferta seleccionada” dice que aún no hay
  selección y el botón aparece deshabilitado;
- después de elegir, borde, texto “Seleccionada”, resumen y habilitación del
  botón aportan confirmación redundante y correcta;
- en móvil las alternativas se apilan sin overflow; desde 48rem se distribuyen en
  dos columnas y desde 80rem en tres;
- las tarjetas tienen `min-height: 7rem`, no una altura rígida peligrosa;
- el stock tiene jerarquía secundaria, pero puede interpretarse como promesa
  comercial aunque es una lectura instantánea que el servidor revalida.

La ficha demuestra que hay varios minimarkets por repetición de tarjetas, pero no
lo enuncia como comparación ni resume cuántas alternativas existen. La percepción
de marketplace es menor que en el listado actualizado por 33.1.

### 7.2 Densidad y jerarquía

El orden actual es título/descripcion, alternativas, resumen y acción. La
separación entre selección y acción es semánticamente segura, pero en móvil suma
tres bloques verticales para una decisión simple. La descripción técnica sobre
revalidación es correcta, aunque tiene más prominencia que la instrucción de
comparar.

El botón deshabilitado usa el estilo global correspondiente y no acepta doble
clic. Conviene que la microcopia explique “Selecciona un minimarket para
continuar” cerca del botón; hoy esa relación depende del resumen anterior.

## 8. Inventario de datos disponibles

Disponibles sin cambios de backend:

- nombre, descripción e imagen de Product;
- categoría, marca y unidad, si existen;
- disponibilidad pública resumida;
- precio mínimo y máximo;
- cantidad de filas de oferta;
- cantidad de Stores distintos;
- nombre comercial e ID de Store por alternativa;
- `inventory_id`, precio y stock por alternativa;
- orden determinista recibido;
- hasta seis Products relacionados;
- estado de selección, error y petición en el cliente;
- URL contractual del carrito;
- respuesta pública de Add to Cart.

Para 33.2.1 bastan nombre, imagen, cantidad/rango, nombre comercial, precio,
stock, `inventory_id` y los estados ya existentes.

## 9. Inventario de datos ausentes

No existen como autoridad durable o contrato público y no deben mostrarse:

- distancia o geolocalización;
- tiempo estimado;
- costo o método de despacho por minimarket;
- horario o condición “abierto ahora”;
- cobertura geográfica;
- reputación, reseñas o estrellas;
- promociones, precio anterior o descuento;
- calificación “mejor oferta”, “más barato” o “recomendado”;
- ranking o posición patrocinada;
- logo/perfil público del Store;
- términos o vigencia de Offer;
- disponibilidad que incorpore reservas, capacidad de preparación o
  fulfillment.

El precio menor puede identificarse descriptivamente como el primero por el
orden contractual o compararse numéricamente, pero 33.2.1 no debería convertirlo
en una afirmación de superioridad comercial. “Precio más bajo entre estas
ofertas” sería técnicamente derivable; la opción más prudente es no agregar ese
sello todavía.

## 10. Problemas de UX actuales

1. **Comparación implícita:** el título habla de elegir, no de comparar.
2. **Acción poco explícita:** no hay microcopia “Seleccionar” dentro de las
   tarjetas.
3. **Título duplicado:** Blocksy y la vista muestran el mismo Product.
4. **Imagen ausente:** el frontend ignora `image`.
5. **Resumen comercial ausente:** no usa cantidad de ofertas ni rango.
6. **Densidad móvil:** lista, resumen amplio y bloque de carrito quedan separados.
7. **Stock ambiguo:** es útil para disponibilidad, pero puede parecer promesa en
   vez de dato sujeto a revalidación.
8. **Éxito sin contexto:** no nombra el Inventory/Store agregado.
9. **Cambio durante POST:** permite que selección visible y resultado pertenezcan
   a alternativas distintas.
10. **Relacionados sin consumidor:** aumentan el contrato y costo del detalle sin
    aportar hoy a esta pantalla; no corresponde resolverlo en 33.2.1.

## 11. Riesgos técnicos

| Riesgo | Estado actual | Mitigación recomendada |
|---|---|---|
| agregar Inventory distinta de la visible | protegido al iniciar el POST; payload validado | conservar una sola fuente `selectedInventoryId` |
| cambio visual durante POST | confirmado | bloquear radios o fijar/nombrar la selección enviada |
| selección implícita | no hay default, lo cual es seguro; affordance débil | texto “Seleccionar/Seleccionado” dentro del mismo botón-radio |
| confundir mínimo con seleccionado | hoy no se muestra mínimo | si se agrega resumen, rotular “Desde”; precio seleccionado debe dominar al comprar |
| stock como promesa | posible | jerarquía secundaria y texto de revalidación breve |
| Store duplicado | impedido por unique normal; no colapsado en lectura | no deduplicar en UI; conservar integridad Inventory |
| DOM reordenado rompe JS | alto si se cambian `data-*` | preservar contrato o reforzar guardas/pruebas primero |
| regresión de teclado/ARIA | posible al rediseñar tarjetas | mantener button+radio, roving tabindex y foco visible |
| respuesta obsoleta de reload | posible con recargas concurrentes | secuencia de request si 33.2.1 añade recarga, sin llamadas nuevas |
| REST adicional | innecesario | conservar un solo GET y un POST por acción |
| entidad Offer/Publication prematura | fuera de necesidad | mantener proyección Product+Inventory+Store |

## 12. Propuesta incremental

### 12.1 Propuesta visual mínima

Mantener una única tarjeta-button por alternativa, semánticamente radio:

```text
Ofertas disponibles
Compara 3 opciones y selecciona un minimarket.

Minimarket Norte
$1.150
Stock disponible: 20
Seleccionar

Minimarket Centro
$1.190
Stock disponible: 6
Seleccionar
```

Al elegir:

```text
Minimarket Norte
$1.150
Stock disponible: 20
Seleccionado
```

“Seleccionar” debe ser texto interno de la tarjeta existente, no un botón
anidado. `aria-checked`, clase seleccionada, borde, foco y roving tabindex deben
seguir siendo las fuentes accesibles del estado.

### 12.2 Encabezado de comparación

Con el DTO actual se puede mostrar:

- `Ofertas disponibles`;
- `Compara 3 ofertas de 3 minimarkets` o, para el caso normal con unicidad,
  `Disponible en 3 minimarkets`;
- rango `Desde $1.150 hasta $1.250` solo cuando aporte claridad;
- en una sola alternativa, `Disponible en 1 minimarket`, sin lenguaje de
  competencia artificial.

Debe usarse `price.offers` para filas y `available_minimarkets` para Stores; no
son necesariamente equivalentes ante corrupción. No contar tarjetas en JS.

### 12.3 Producto y acción

- renderizar la imagen contractual con fallback y `object-fit: contain`;
- mantener un solo `h1` semántico y ocultar solo visualmente el título duplicado
  de Blocksy en las páginas canónicas de Product, con un selector estrictamente
  acotado y pruebas de navegación;
- acercar el resumen seleccionado y Add to Cart o integrar una línea de
  confirmación junto al botón, sin eliminar la región viva;
- mantener el botón deshabilitado hasta selección explícita;
- durante el POST, bloquear también cambios de oferta o congelar claramente la
  alternativa enviada;
- en éxito, indicar el nombre del Store agregado usando la selección capturada,
  no el estado mutable posterior;
- conservar “Ver carrito” y todos los errores existentes.

## 13. Alcance recomendado para 33.2.1

### Archivos productivos máximos

- `app/Modules/Frontend/Views/product-detail.php`;
- `assets/frontend/js/veciahorra-product-offers.js`;
- `assets/frontend/css/veciahorra-frontend.css`.

### Pruebas a modificar o crear

- `tests/manual/public-offer-selection-test.php`;
- `tests/manual/public-offer-selection-browser-test.html`;
- `tests/manual/public-add-to-cart-test.php`;
- `tests/manual/public-add-to-cart-browser-test.html`;
- una prueba visual/contractual específica de comparación si la convención del
  proyecto lo favorece.

### Cambios recomendados

1. Renombrar el encabezado a “Ofertas disponibles” y añadir instrucción explícita
   de comparación/selección.
2. Renderizar contador/rango exclusivamente desde campos existentes del detalle.
3. Añadir estado textual “Seleccionar/Seleccionado” dentro del button-radio.
4. Renderizar imagen con fallback seguro.
5. Reducir la distancia entre selección confirmada y Add to Cart sin eliminar
   estados vivos.
6. Impedir cambio de radio durante Add to Cart o correlacionar respuesta con la
   selección capturada.
7. Preservar un GET de detalle y un POST por clic válido.
8. Reforzar casos con una y varias alternativas, teclado, doble clic, cambio
   durante petición, respuesta inválida, oferta desaparecida y error REST.

El ocultamiento del título duplicado de Blocksy debe incluirse solo si puede
acotarse inequívocamente a las páginas canónicas de detalle. Si no existe un
selector estable, debe separarse de 33.2.1 en vez de usar una regla global.

## 14. Restricciones negativas para 33.2.1

No debe:

- modificar CatalogService, rutas o DTO JSON;
- modificar Cart, Checkout, Payments, Customer Panel o reservas;
- agregar endpoints o llamadas REST;
- preseleccionar la primera alternativa;
- enviar precio, Product, Store o stock al carrito;
- recalcular ofertas válidas o disponibilidad comercial en el cliente;
- deduplicar o reordenar alternativas en la UI;
- introducir `Offer`, `Publication`, `Ranking` o `Promotion`;
- mostrar distancia, despacho, tiempos, reputación, estrellas, horarios,
  geografía, promociones o ranking;
- etiquetar una alternativa como “mejor”, “más barata” o “recomendada”;
- convertir tarjetas en `div` clicables sin semántica de radio;
- usar selección visual distinta de `inventory_id`;
- alterar Blocksy, Site Identity o navegación global.

## 15. Criterios de aceptación

### Contrato y datos

- cero cambios en la respuesta del detalle;
- Product, Inventory y Store conservan sus autoridades;
- contador, rango, Store, precio y stock proceden del DTO existente;
- `inventory_id` sigue siendo la única selección enviada;
- una sola carga GET por ficha y cero llamadas por alternativa.

### Selección

- no hay selección por defecto;
- cada alternativa comunica “Seleccionar” y solo una “Seleccionado”;
- click, Enter, espacio, flechas y Home/End conservan selección exclusiva;
- foco y `aria-checked` son correctos;
- cambiar selección actualiza Store, precio, stock y payload conjuntamente;
- un ID manipulado no puede habilitar Add to Cart;
- una oferta desaparecida limpia selección y deshabilita compra.

### Add to Cart

- sin selección no hay POST;
- doble clic produce un solo POST;
- durante el POST no puede cambiar silenciosamente el contexto agregado;
- payload exacto `{inventory_id, quantity: 1}`;
- éxito identifica de forma no ambigua lo agregado y revela “Ver carrito”;
- errores/reactivación mantienen accesibilidad y selección segura;
- Cart continúa revalidando Inventory, Product, Store, precio y stock.

### Visual y responsive

- nombre e imagen de Product con jerarquía clara;
- sin título duplicado, únicamente si el scope canónico es seguro;
- singular/plural correctos para una y varias alternativas;
- precio de cada alternativa más prominente que stock;
- seleccionada distinguible sin depender solo del color;
- desktop: hasta tres columnas actuales; tablet: dos; móvil: una;
- sin overflow horizontal ni alturas rígidas problemáticas;
- botón de compra próximo al contexto seleccionado y usable por teclado;
- sin información comercial inventada.

### Regresión

- pasan detalle público, selección, Add to Cart, frontend foundation,
  aislamiento de búsqueda y WooCommerce;
- pruebas browser cubren una/múltiples ofertas, navegación de teclado y cambio
  durante una petición pendiente;
- PHP lint, `git diff --check` y staging audit correctos.

## 16. Conclusión

El contrato actual es suficiente. 33.2.1 no necesita backend ni REST: ya recibe
identidad de Product, imagen, resumen de precios, cantidad de minimarkets y todas
las alternativas con el `inventory_id` necesario para comprar.

La intervención correcta es hacer explícita la comparación sin crear un dominio
nuevo: mejorar encabezado y microcopia, mostrar la imagen y datos resumen ya
existentes, reforzar el estado seleccionar/seleccionado y cerrar el desacople
entre una petición pendiente y una selección posterior. Todo debe conservar la
tarjeta como radio accesible, el payload mínimo y la revalidación autoritativa de
Cart.
