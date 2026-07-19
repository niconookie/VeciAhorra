# Public Catalog API Foundation

Public read-only endpoints:

- `GET /wp-json/veciahorra/v1/catalog/products`
- `GET /wp-json/veciahorra/v1/catalog/products/{id}`
- `GET /wp-json/veciahorra/v1/catalog/categories`

The list accepts `category`, `brand`, `search`, `page`, `per_page` (maximum 100)
and `order_by` (`name`, `price`, `newest`). Only active products with at
least one active inventory row and positive stock are serialized.

`CatalogService` reuses ProductRepository, InventoryRepository and the existing
category, brand and unit services. Aggregation is currently performed in memory
in bounded read batches to avoid adding a repository or duplicating SQL. A future optimization may add a
cache or purpose-built read query while preserving this public response contract.

The API does not expose inventory IDs, minimarket IDs, per-store stock, internal
states or timestamps. It does not register write methods and has no frontend
consumer in this phase.

The product detail keeps the summary fields and adds the sanitized description,
commercial availability, minimum/maximum price, public offers, up to six related
products and non-sensitive response metadata. Offers require active inventory,
positive stock and price, and an active minimarket. They are ordered by price,
stock descending and inventory ID. The detail is read-only and uses the existing
`GET /catalog/products/{id}` route.

The public categories endpoint returns only categories that have at least one
publicly visible product under the same Product, Inventory and Store rules used
by the catalog. Each item contains `id`, `name`, `slug` and `products_count`;
the count is the number of distinct public Products, regardless of how many
minimarkets offer each Product. The endpoint is public and read-only.
