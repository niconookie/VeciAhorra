# Microhito 33.0 — Auditoría de visibilidad del Marketplace

Fecha de auditoría: 19 de julio de 2026.

## 1. Propósito y alcance

Este documento describe exclusivamente el estado actual de la información pública
que permite representar ofertas de un mismo producto entre minimarkets. La
auditoría es de lectura: no crea autoridades, endpoints, entidades ni cambios de
datos.

Fuentes revisadas:

- `CatalogService`, sus rutas, controlador y contrato documentado;
- repositorios y modelos de Product, Inventory y Store;
- vistas y JavaScript del catálogo y del detalle público;
- selección pública de oferta y agregado al carrito;
- pruebas manuales del catálogo, detalle, selección y carrito;
- inventarios arquitectónicos vigentes de la Serie 31.

## 2. Conclusión ejecutiva

El sistema ya posee el núcleo mínimo de un marketplace multi-minimarket. Un
Product activo se hace públicamente visible cuando existe al menos una fila de
Inventory activa, con precio y stock positivos, perteneciente a un Store activo.
Cada alternativa pública se deriva en tiempo de lectura de
`Product + Inventory + Store`; no existe ni hace falta todavía una entidad
durable `Offer`.

El detalle público sí entrega todas las alternativas válidas y el frontend sí
permite seleccionar una. El catálogo, en cambio, comunica débilmente ese hecho:
su DTO contiene `available_minimarkets`, pero la tarjeta no muestra esa cantidad;
además consulta cada detalle para obtener y presentar solamente la primera
alternativa ordenada. Por ello la experiencia puede percibirse como una tienda
convencional aunque el backend ya modele una selección entre comercios.

La mejora mínima recomendada es hacer visible la comparación que ya existe:
mostrar “Desde $…”, “Disponible en N minimarkets” y conducir a un detalle que
diga explícitamente “Compara y elige”. Esto no requiere `Publication`, `Offer`,
`Ranking`, `Promotion`, perfiles de vendedor ni una nueva autoridad. Como paso
posterior, conviene eliminar la lectura N+1 del catálogo mediante una evolución
compatible del read model, no mediante una entidad comercial nueva.

## 3. Flujo público actual

```text
Product activo
    + Inventory activo, stock > 0, precio > 0
    + Store activo
                |
                v
     proyección pública de oferta
     {inventory_id, minimarket_id, minimarket, price, stock}
                |
        +-------+-------+
        |               |
        v               v
 listado/summary    detalle/ofertas
 precio mínimo      todas, ordenadas
 nº minimarkets     selección única
                            |
                            v
                  carrito recibe solamente
                  {inventory_id, quantity}
```

Inventory conserva la autoridad sobre precio y stock. Store conserva la
identidad del comercio. Product conserva la identidad y descripción del bien.
Catalog es un read model; la palabra “oferta” designa su proyección y no una fila
autónoma.

## 4. Datos ya disponibles

### 4.1 En `CatalogService`

El listado público serializa por Product:

| Campo | Origen o derivación | Utilidad pública |
|---|---|---|
| `id`, `name`, `slug` | Product | identidad y navegación |
| `short_description`, `image` | Product, saneados/proyectados | presentación |
| `category`, `brand`, `unit` | catálogos maestros | clasificación |
| `min_price` | menor precio público válido | comunicar precio “desde” |
| `available_minimarkets` | cantidad de Store distintos con oferta válida | evidenciar competencia |

El listado admite categoría, marca, búsqueda, paginación y orden por nombre,
precio mínimo o novedad. No devuelve las ofertas individuales.

El servicio también dispone internamente, durante la agregación, de:

- precio mínimo y máximo por Product;
- conjunto de IDs de minimarket distintos;
- todas las filas públicas válidas agrupadas por Product;
- orden determinista de alternativas: precio ascendente, stock descendente e
  `inventory_id` ascendente como desempate.

### 4.2 Detalle público del producto

`GET /catalog/products/{id}` mantiene todos los campos de resumen y agrega:

| Campo | Contenido actual |
|---|---|
| `description` | descripción pública sin HTML |
| `availability` | `in_stock` para todo Product visible |
| `price.min` | menor precio elegible |
| `price.max` | mayor precio elegible |
| `price.offers` | número de alternativas elegibles |
| `offers` | lista completa de proyecciones públicas |
| `related_products` | hasta seis Products relacionados visibles |
| `meta.related_products` | cantidad de relacionados |

Cada elemento de `offers` contiene exactamente:

| Campo | Significado |
|---|---|
| `inventory_id` | identidad técnica de la selección comercial |
| `minimarket_id` | referencia al Store |
| `minimarket` | `business_name` del Store |
| `price` | precio vigente de Inventory |
| `stock` | stock vigente de Inventory |

Las pruebas de detalle verifican tanto las claves exactas como el orden, el
filtrado de precios inválidos, inventarios inactivos, stock no positivo y Stores
inactivos. También verifican que la lectura no cambie stock ni estado.

### 4.3 Materialización de las ofertas

No hay una tabla o modelo `Offer`. La oferta se materializa cuando
`CatalogService`:

1. lee Inventory;
2. descarta IDs inválidos, estado no activo, stock menor o igual a cero y precio
   no numérico, no finito o menor o igual a cero;
3. resuelve los Stores involucrados y conserva solo los activos;
4. proyecta nombre comercial, precio y stock;
5. agrupa por Product, ordena y calcula los resúmenes.

Inventory ya relaciona Product con minimarket y es la autoridad comercial
operativa. La unicidad funcional de esa relación implica una alternativa vigente
por Product y Store, no múltiples promociones o modalidades simultáneas del
mismo comercio.

En el frontend del detalle, las alternativas se normalizan y se representan como
un `radiogroup` accesible. Cada tarjeta muestra minimarket, precio y stock. La
selección conserva `inventory_id`; el alta al carrito envía únicamente
`{inventory_id, quantity: 1}` para que el servidor vuelva a resolver y validar la
información autoritativa. El cliente no envía precio, Product, Store ni stock.

### 4.4 Información de minimarket ya existente

Store contiene actualmente, entre otros datos:

- `business_name`, `legal_name`, `owner_name` y RUT;
- email, teléfono y móvil;
- dirección, comuna, ciudad y región;
- estado, estado de onboarding y fecha de aprobación.

Que un dato exista no lo convierte automáticamente en público. El contrato
vigente solo autoriza `minimarket_id` y `business_name` dentro de la oferta.

Sin introducir una nueva autoridad se puede seguir mostrando inmediatamente:

- nombre comercial, porque ya forma parte del DTO público;
- cantidad de minimarkets, porque ya forma parte del resumen;
- precio, stock y posición relativa de cada alternativa, porque ya forman parte
  del detalle;
- rango de precios y cantidad de ofertas, porque ya se calculan públicamente.

Comuna, ciudad o región podrían proyectarse desde Store sin crear una entidad,
pero no deben publicarse por mera disponibilidad técnica. Requieren primero una
decisión explícita de contrato y privacidad, además de reglas para valores vacíos
y consistencia. Dirección exacta, datos de contacto, razón social, propietario y
RUT no son necesarios para la comparación inicial y deben permanecer fuera del
DTO público.

## 5. Datos faltantes

### 5.1 Faltantes para una comparación comercial rica

- perfil o página pública estable del minimarket;
- localidad pública contractual y coordenadas para distancia;
- horario y condición “abierto ahora”;
- modalidades de retiro o despacho por Store/Product;
- costo y plazo de entrega;
- monto mínimo de compra;
- valoración, cantidad de reseñas y reputación;
- logo o imagen pública del comercio;
- precio por unidad comparable y normalización de formato/tamaño;
- vigencia, términos y trazabilidad de una promoción;
- impuestos, descuentos y precio anterior con semántica contractual;
- fecha de actualización y garantía de frescura visible;
- disponibilidad que descuente reservas o considere locks, horarios y
  fulfillment;
- política pública de ranking y explicación de resultados;
- moderación/publicación específica por comercio.

Estos datos no deben inferirse ni simularse en la UI. Varios requerirían ampliar
Store o Inventory; otros sí justificarían autoridades futuras, pero solo después
de definir su ciclo de vida y ownership.

### 5.2 Faltantes técnicos del read model actual

- el listado no entrega `max_price`, cantidad de ofertas ni una proyección
  explícita de la mejor alternativa;
- el listado entrega `available_minimarkets`, pero el frontend no lo representa;
- para mostrar el nombre del minimarket en cada tarjeta, el frontend realiza una
  solicitud de detalle por Product además de la solicitud del listado;
- no hay paginación o límite propio de ofertas en el detalle;
- no hay parámetro público de orden de ofertas: el orden por precio/stock/ID está
  fijado en el servicio;
- `availability = in_stock` resume las condiciones de elegibilidad actuales, no
  una promesa completa de fulfillment;
- Store se considera público por `status = active`; onboarding y aprobación no
  forman parte de la regla observada de Catalog.

## 6. Limitaciones de la comparación pública actual

1. **Comparación visible solo en el detalle.** El listado posee la señal de
   multiplicidad, pero no la muestra. La primera alternativa puede parecer “el
   vendedor” único.
2. **Comparación centrada solo en precio y stock.** No existe información
   contractual para comparar distancia, despacho, horario, reputación o costo
   total.
3. **Stock instantáneo, no disponibilidad integral.** Catalog lee Inventory sin
   expresar reservas, horario, capacidad de preparación o entrega.
4. **Una oferta por Product y Store.** El modelo actual no representa dos
   modalidades, packs o promociones simultáneas de un mismo minimarket.
5. **Orden implícito.** Precio, stock e ID forman un orden estable, pero no existe
   una autoridad de Ranking ni una explicación pública de esa política.
6. **Escalabilidad de lectura.** El patrón listado más un detalle por tarjeta es
   N+1. Funciona con el volumen actual, pero no es un buen contrato de crecimiento.
7. **Identidad pública mínima.** El nombre comercial distingue Stores; no existe
   todavía perfil, logo o ubicación pública contractual.
8. **Documentación contradictoria.** El README de Catalog aún afirma que la API
   no expone IDs de Inventory/Store ni stock por comercio, mientras su sección de
   detalle y las pruebas vigentes confirman que sí los expone. El código y las
   pruebas ejecutables representan el contrato observado; el texto histórico
   debe corregirse en un microhito documental, no reinterpretarse como prohibición.

## 7. Propuestas de UI compatibles con la arquitectura actual

### 7.1 Cambio mínimo recomendado, sin ampliar el contrato

En las tarjetas del catálogo:

- rotular el precio como `Desde $X` cuando corresponde al `min_price`;
- mostrar `Disponible en 1 minimarket` o `Disponible en N minimarkets` usando
  `available_minimarkets`;
- usar una llamada a la acción como `Comparar ofertas` cuando N sea mayor que uno
  y `Ver disponibilidad` cuando sea uno;
- evitar presentar el primer Store como si fuera el único vendedor.

En el detalle:

- cambiar el encuadre visual a `Compara y elige un minimarket`;
- mostrar arriba `N ofertas`, rango mínimo–máximo y, cuando sea útil, diferencia
  respecto del menor precio;
- conservar las tarjetas accesibles, su orden actual y la selección única;
- marcar descriptivamente la primera como `Menor precio`, sin llamarla
  “recomendada” ni inventar un ranking;
- mantener visibles nombre comercial, precio y stock, y recordar que el servidor
  valida nuevamente la selección.

Todo lo anterior se calcula con campos ya públicos. No exige nuevas escrituras,
autoridades ni datos personales.

### 7.2 Evolución mínima posterior del read model

Para retirar el N+1 sin introducir `Offer`, se puede estudiar una ampliación
compatible del resumen del listado con datos derivados, por ejemplo:

- `max_price`;
- `offers_count`;
- opcionalmente `best_offer` limitado a nombre comercial, precio y referencia
  técnica, si realmente se necesita mostrarlo en la tarjeta.

La alternativa preferible para el primer paso visual es no necesitar
`best_offer`: `min_price + available_minimarkets` ya comunica marketplace y evita
acoplar el listado a una alternativa que puede cambiar. Cualquier ampliación debe
mantener Inventory y Store como fuentes y Catalog como proyección de solo lectura.

### 7.3 Información geográfica, solo después de una decisión de contrato

Una segunda iteración podría mostrar comuna o ciudad para distinguir comercios
homónimos. Antes deben definirse:

- qué campos son realmente públicos;
- consentimiento y finalidad;
- saneamiento y valores faltantes;
- si se muestra ubicación aproximada o dirección exacta;
- efecto en privacidad y seguridad del comercio.

No se recomienda exponer contacto, propietario, RUT ni razón social como atajo a
un perfil de vendedor.

## 8. Riesgos de introducir entidades nuevas antes de tiempo

Crear `Offer`, `Publication`, `Marketplace`, `Ranking` o `Promotion` ahora
duplicaría conceptos que ya tienen autoridad y abriría preguntas no resueltas:

- cuál fila manda sobre precio y stock: Inventory u Offer;
- cómo se sincronizan y qué ocurre ante fallos parciales;
- quién crea, publica, suspende, versiona y expira una oferta;
- si la identidad de oferta sobrevive a cambios de Inventory;
- cómo interactúa con carrito, reservas, checkout y snapshots históricos;
- qué estado de Store habilita publicación y quién lo gobierna;
- cómo se audita un precio promocional o un ranking;
- cómo se migran datos sin romper `inventory_id`, hoy referencia transaccional.

La duplicación prematura aumentaría inconsistencias y podría permitir que una UI
publique información que Cart o Checkout rechazan. Una nueva entidad solo se
justifica cuando haya un requisito que Inventory no pueda representar —por
ejemplo, múltiples condiciones simultáneas, vigencias y términos propios— y
después de fijar autoridad, estados, writers e invariantes.

## 9. Recomendación incremental

### Etapa 33.1 — Hacer visible lo que ya existe

Objetivo: que el usuario entienda desde el listado que compara minimarkets.

- presentar precio “desde” y cantidad de minimarkets;
- reforzar el lenguaje de comparación y elección en catálogo y detalle;
- conservar la selección por `inventory_id` y la revalidación del servidor;
- no agregar datos de Store ni afirmar distancia, despacho, promoción o ranking.

Criterios de aceptación: singular/plural correctos; ausencia de datos internos;
misma accesibilidad del selector; mismo payload mínimo de carrito; comportamiento
correcto con una y varias alternativas.

### Etapa 33.2 — Optimizar el read model

Objetivo: eliminar solicitudes de detalle por tarjeta y explicitar resúmenes
comparables sin crear autoridades.

- medir primero el costo N+1;
- agregar solo resúmenes derivados imprescindibles al listado;
- mantener el detalle como fuente de la lista completa;
- reforzar pruebas de claves permitidas, orden, visibilidad y cero escrituras.

### Etapa 33.3 — Identidad pública mínima del comercio

Objetivo: añadir contexto geográfico únicamente si el producto lo necesita.

- aprobar política de campos públicos de Store;
- comenzar, como máximo, por comuna/ciudad saneadas;
- definir tratamiento de Stores activos pero no aprobados/onboarded;
- no crear perfil público hasta contar con contenido y lifecycle propios.

### Etapa futura condicionada — Autoridades comerciales nuevas

Evaluar `Publication`, `Offer`, `Ranking` o `Promotion` solo ante requisitos
concretos que incluyan lifecycle, ownership, moderación, vigencia y auditoría. No
son prerrequisitos para que la interfaz actual se perciba como marketplace.

## 10. Matriz de decisión

| Necesidad | ¿Disponible hoy? | Cambio mínimo |
|---|---:|---|
| mostrar varios minimarkets | sí | hacer visible el contador existente |
| comparar precios | sí | presentar rango y lista actual |
| elegir minimarket | sí | conservar selector actual |
| comprar una alternativa | sí | conservar `inventory_id` y validación servidor |
| identificar comercio | parcialmente | usar `business_name`; política antes de ubicación |
| evitar N+1 en catálogo | no | enriquecer resumen derivado, sin entidad nueva |
| comparar despacho/distancia | no | definir datos y autoridad antes de UI |
| promociones con vigencia | no | requisito y diseño futuro explícitos |
| ranking/reputación | no | no simular; autoridad futura condicionada |

## 11. Contrato recomendado para el siguiente microhito

El siguiente microhito debería ser estrictamente de visibilidad: reutilizar
`min_price`, `available_minimarkets`, `price` y `offers`, sin cambiar quién decide
precio, stock, elegibilidad o selección. Debe probar al menos:

- Product con una oferta y con varias ofertas;
- singular y plural de minimarket/oferta;
- consistencia entre resumen y detalle;
- orden determinista actual;
- ausencia de Stores/inventarios inactivos y precios/stocks no elegibles;
- ausencia de PII y de campos Store no aprobados para publicación;
- payload de carrito limitado a `inventory_id` y cantidad;
- cero escrituras durante listado y detalle;
- aislamiento de WooCommerce.

Esta secuencia hace reconocible el marketplace con el menor riesgo: primero
expone con claridad la competencia ya derivada; luego optimiza la lectura; solo
después considera nuevos datos o autoridades cuando exista una necesidad de
negocio verificable.
