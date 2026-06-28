# VeciAhorra

# Diccionario de Datos

Versión: 1.0

---

## Introducción

Este documento describe todas las tablas propias del Marketplace VeciAhorra.

WooCommerce continuará administrando:

- Productos
- Variaciones
- Categorías
- Clientes
- Carrito
- Pedidos WooCommerce
- Pagos

VeciAhorra administrará únicamente la lógica del Marketplace.

---

## Convenciones

Prefijo de tablas:

va_

Tipos de datos:

INT
BIGINT
VARCHAR
TEXT
DECIMAL
BOOLEAN
DATETIME
TIMESTAMP

Todos los registros consideran:

created_at
updated_at

Cuando corresponda:

deleted_at

(Soft Delete)

# Tabla

va_stores

## Descripción

Almacena la información de los minimarkets registrados en la plataforma.

## Relaciones

owner_user_id → wp_users.ID

## Campos

| Campo | Tipo | Nulo | Descripción |
|--------|------|------|-------------|
| id | BIGINT | No | Identificador |
| owner_user_id | BIGINT | No | Usuario propietario |
| business_name | VARCHAR(150) | No | Nombre comercial |
| legal_name | VARCHAR(150) | Sí | Razón social |
| rut | VARCHAR(20) | No | RUT |
| email | VARCHAR(150) | No | Correo |
| phone | VARCHAR(30) | Sí | Teléfono |
| address | VARCHAR(255) | No | Dirección |
| commune | VARCHAR(100) | No | Comuna |
| city | VARCHAR(100) | No | Ciudad |
| latitude | DECIMAL(10,8) | Sí | Latitud |
| longitude | DECIMAL(11,8) | Sí | Longitud |
| status | ENUM | No | pending / approved / suspended / rejected |
| approved_at | DATETIME | Sí | Fecha aprobación |
| created_at | DATETIME | No | Creación |
| updated_at | DATETIME | Sí | Actualización |

## Índices

PRIMARY KEY(id)

INDEX(owner_user_id)

INDEX(status)

INDEX(commune)

# Tabla

va_store_products

## Descripción

Relaciona productos (WooCommerce) con un minimarket.

## Relaciones

store_id → va_stores.id

product_id → WooCommerce Product

variation_id → WooCommerce Variation

## Campos

| Campo | Tipo |
|--------|------|
| id | BIGINT |
| store_id | BIGINT |
| product_id | BIGINT |
| variation_id | BIGINT |
| price | DECIMAL(10,2) |
| available | BOOLEAN |
| created_at | DATETIME |
| updated_at | DATETIME |

## Índices

(store_id)

(product_id)

(variation_id)

(store_id,product_id,variation_id)

# Tabla

va_inventory

## Descripción

Controla el stock por minimarket.

## Campos

| Campo | Tipo |
|--------|------|
| id | BIGINT |
| store_product_id | BIGINT |
| stock | INT |
| reserved_stock | INT |
| minimum_stock | INT |
| updated_at | DATETIME |

## Índices

(store_product_id)

# Tabla

va_inventory_movements

## Descripción

Historial completo de movimientos de inventario.

## Campos

movement_type

purchase

sale

adjustment

return

loss

reservation

release

quantity

reference

created_by

created_at

store_product_id

cart_token

quantity

expires_at

status

customer_id

purchase_mode

manual

best_price

single_store

status

subtotal

delivery

discount

total

master_order_id

store_id

status

subtotal

commission

delivery_status

store_order_id

product_id

variation_id

price

quantity

subtotal

user_id

vehicle_type

plate

phone

rating

status

master_order_id

driver_id

status

pickup_started

delivery_started

completed_at

delivery_id

store_order_id

pickup_order

status

store_product_id

old_price

new_price

changed_reason

changed_at

store_order_id

subtotal

commission_percentage

commission_amount

status

paid_at

user_id

channel

email

sms

push

whatsapp

title

message

status

user_id

profile_type

customer

store

driver

administrator

reference_id

setting_key

setting_value

autoload


---