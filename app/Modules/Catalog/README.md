# Public Catalog API Foundation

Public read-only endpoints:

- `GET /wp-json/veciahorra/v1/catalog/products`
- `GET /wp-json/veciahorra/v1/catalog/products/{id}`

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
