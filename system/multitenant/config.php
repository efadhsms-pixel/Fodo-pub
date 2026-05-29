<?php
/**
 * Multi-Tenant configuration
 *
 * Central place that defines:
 *   - how a tenant is identified from the request (sub-domain), and
 *   - which database tables are isolated per tenant.
 *
 * Isolation strategy: shared database / shared schema with a `tenant_id`
 * discriminator column on every tenant-scoped table. Tenants are told apart
 * by the sub-domain of the incoming request (e.g. `shop1.example.com`).
 *
 * NOTE: table names below are listed WITHOUT the DB prefix (e.g. `product`,
 * not `oc_product`). The prefix from config.php (DB_PREFIX) is applied
 * automatically by the tenant DB layer.
 */

return array(
	/*
	 * The platform's base domain. A request host of `<sub>.<base_domain>`
	 * resolves to the tenant whose sub-domain is `<sub>`. A request to the
	 * bare base domain (or `www.`) is treated as the platform context
	 * (no tenant) and is reserved for the super-admin / landing page.
	 *
	 * Override per environment via the MT_BASE_DOMAIN env var or by editing
	 * this value after install.
	 */
	'base_domain' => getenv('MT_BASE_DOMAIN') ?: 'example.com',

	/*
	 * Hosts that must never be treated as a tenant sub-domain.
	 * Useful for the marketing site, admin console, status pages, etc.
	 */
	'reserved_subdomains' => array('www', 'admin', 'api', 'static', 'cdn', 'mail'),

	/*
	 * When true, a request to a sub-domain with no matching (or inactive)
	 * tenant is rejected with a "store not found" response instead of
	 * silently falling back to the platform context. Keep this ON in
	 * production to avoid accidental cross-tenant data exposure.
	 */
	'strict_resolution' => true,

	/*
	 * Tenant-scoped tables. Each of these gets a `tenant_id` column and
	 * every read/write the application performs against them is constrained
	 * to the current tenant by the tenant DB layer.
	 *
	 * Anything NOT in this list (e.g. `country`, `zone`, `language`,
	 * `session`, `oc_tenant` itself) is treated as global/shared reference
	 * data and is never rewritten.
	 */
	'tenant_tables' => array(
		// Catalog
		'product', 'product_attribute', 'product_description', 'product_discount',
		'product_filter', 'product_image', 'product_option', 'product_option_value',
		'product_recurring', 'product_related', 'product_reward', 'product_special',
		'product_to_category', 'product_to_download', 'product_to_layout', 'product_to_store',
		'category', 'category_description', 'category_filter', 'category_path',
		'category_to_layout', 'category_to_store',
		'manufacturer', 'manufacturer_to_store',
		'attribute', 'attribute_description', 'attribute_group', 'attribute_group_description',
		'option', 'option_description', 'option_value', 'option_value_description',
		'filter', 'filter_description', 'filter_group', 'filter_group_description',
		'custom_field', 'custom_field_customer_group', 'custom_field_description',
		'custom_field_value', 'custom_field_value_description',
		'download', 'download_description',
		'review',
		'recurring', 'recurring_description',

		// Customers
		'customer', 'customer_activity', 'customer_affiliate', 'customer_approval',
		'customer_group', 'customer_group_description', 'customer_history', 'customer_ip',
		'customer_login', 'customer_online', 'customer_reward', 'customer_search',
		'customer_transaction', 'customer_wishlist',
		'address',

		// Sales
		'order', 'order_history', 'order_option', 'order_product', 'order_recurring',
		'order_recurring_transaction', 'order_shipment', 'order_status', 'order_total',
		'order_voucher',
		'return', 'return_action', 'return_history', 'return_reason', 'return_status',
		'coupon', 'coupon_category', 'coupon_history', 'coupon_product',
		'voucher', 'voucher_history', 'voucher_theme', 'voucher_theme_description',
		'cart',

		// Marketing / content
		'marketing',
		'banner', 'banner_image',
		'information', 'information_description', 'information_to_layout', 'information_to_store',

		// Design / layout
		'layout', 'layout_module', 'layout_route',
		'module', 'theme',
		'seo_url',

		// Localisation (per-shop configurable)
		'currency',
		'geo_zone', 'zone_to_geo_zone',
		'length_class', 'length_class_description',
		'weight_class', 'weight_class_description',
		'stock_status',
		'location',
		'tax_class', 'tax_rate', 'tax_rate_to_customer_group', 'tax_rule',

		// Store / system per-shop
		'store',
		'setting',
		'user', 'user_group',
		'api', 'api_ip', 'api_session',
		'extension', 'extension_install', 'extension_path',
		'modification',
		'event',
		'translation',
		'upload',
		'statistics',
		'shipping_courier',
	),
);
