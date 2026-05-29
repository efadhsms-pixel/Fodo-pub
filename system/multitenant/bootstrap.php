<?php
/**
 * Multi-Tenant bootstrap
 *
 * Wires tenant resolution into the OpenCart framework. Called from
 * system/framework.php immediately after the core DB connection is created.
 *
 *   1. Resolves the current tenant from the request sub-domain.
 *   2. Publishes the Tenant object (registry 'tenant') and TENANT_ID constant.
 *   3. Enforces strict resolution (unknown sub-domain -> "store not found").
 *   4. Replaces registry 'db' with a tenant-scoping decorator so that all
 *      models transparently read/write only the current tenant's data.
 *
 * The platform context (bare base domain) is left with the raw, unscoped DB
 * and is reserved for the super-admin / landing experience.
 */
function multitenant_bootstrap($registry, $application) {
	$db = $registry->get('db');

	// No DB (e.g. installer) -> nothing to scope.
	if (!$db) {
		return;
	}

	$config = require(DIR_SYSTEM . 'multitenant/config.php');

	require_once(DIR_SYSTEM . 'multitenant/tenant.php');
	require_once(DIR_SYSTEM . 'multitenant/tenantdb.php');

	$tenant = new Tenant($db, $config);
	$registry->set('tenant', $tenant);

	if (!defined('TENANT_ID')) {
		define('TENANT_ID', $tenant->getId());
	}

	// Per-tenant image root (under image/catalog). Platform keeps 'catalog'.
	if (!defined('MT_IMAGE_BASE')) {
		define('MT_IMAGE_BASE', $tenant->isResolved() ? 'catalog/t' . $tenant->getId() : 'catalog');
	}

	// Ensure the tenant's image directory exists.
	if ($tenant->isResolved() && defined('DIR_IMAGE')) {
		$tenant_image_dir = DIR_IMAGE . MT_IMAGE_BASE;
		if (!is_dir($tenant_image_dir)) {
			@mkdir($tenant_image_dir, 0777, true);
		}
	}

	// A sub-domain was supplied but no active tenant matched it.
	if ($config['strict_resolution'] && !$tenant->isResolved() && !$tenant->isPlatform()) {
		header('HTTP/1.1 404 Not Found');
		header('Content-Type: text/html; charset=utf-8');
		echo 'Store not found.';
		exit;
	}

	// The storefront must always belong to a tenant; never serve merged data.
	if ($application === 'catalog' && !$tenant->isResolved()) {
		header('HTTP/1.1 404 Not Found');
		header('Content-Type: text/html; charset=utf-8');
		echo 'No store is configured for this address.';
		exit;
	}

	// Scope the DB for resolved tenants. Platform context keeps the raw DB.
	if ($tenant->isResolved()) {
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';

		$scoped_tables = $config['tenant_tables'];
		if (!empty($config['extension_tables'])) {
			$scoped_tables = array_merge($scoped_tables, $config['extension_tables']);
		}

		$auto = !empty($config['auto_isolate_new_tables']);
		$registry_table = $prefix . 'tenant_scoped_table';

		if ($auto) {
			// Registry of auto-isolated tables, plus any already recorded.
			$scoped_tables = array_merge(
				$scoped_tables,
				multitenant_load_registry($db, $registry_table)
			);

			// Honour the shared-table opt-out list.
			if (!empty($config['global_extension_tables'])) {
				$globals = array_flip($config['global_extension_tables']);
				$scoped_tables = array_values(array_filter($scoped_tables, function ($t) use ($globals) {
					return !isset($globals[$t]);
				}));
			}
		}

		$registry->set('db', new TenantDB(
			$db,
			$tenant->getId(),
			$scoped_tables,
			$prefix,
			$registry->get('log'),
			array(
				'core_tables'    => isset($config['core_tables']) ? $config['core_tables'] : array(),
				'auto'           => $auto,
				'registry_table' => $auto ? $registry_table : '',
			)
		));

		// Make every generated URL use the tenant's host instead of the shared
		// HTTP_SERVER constant. This drives storefront/admin links AND payment
		// return/cancel/callback URLs (built via $this->url->link()), which
		// would otherwise all point at the base domain. The Url library is
		// constructed later in framework.php from these config values.
		multitenant_scope_url($registry);
	}
}

/**
 * Ensure the auto-isolation registry table exists and return the table names
 * (without prefix) recorded in it. Runs against the raw connection so it is
 * never itself scoped. Failures are non-fatal.
 *
 * @return string[] table names previously auto-isolated
 */
function multitenant_load_registry($db, $registry_table) {
	try {
		$db->query(
			"CREATE TABLE IF NOT EXISTS `" . $registry_table . "` (" .
			" `name` VARCHAR(191) NOT NULL," .
			" PRIMARY KEY (`name`)" .
			") ENGINE=InnoDB DEFAULT CHARSET=utf8;"
		);

		$query = $db->query("SELECT `name` FROM `" . $registry_table . "`");
	} catch (\Exception $e) {
		return array();
	}

	$names = array();
	foreach ($query->rows as $row) {
		$names[] = $row['name'];
	}
	return $names;
}

/**
 * Override site_url / site_ssl with a URL based on the current request host,
 * preserving any sub-directory path from the configured HTTP_SERVER.
 */
function multitenant_scope_url($registry) {
	$config = $registry->get('config');
	if (!$config || !defined('HTTP_SERVER')) {
		return;
	}

	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
	if ($host === '') {
		return;
	}

	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	$scheme = $secure ? 'https://' : 'http://';

	$path = parse_url(HTTP_SERVER, PHP_URL_PATH);
	if (!$path) {
		$path = '/';
	}

	$base = $scheme . $host . $path;

	$config->set('site_url', $base);
	$config->set('site_ssl', $base);
}

/**
 * Namespace the cache per tenant. Called from system/framework.php AFTER the
 * Cache object is created (the cache is built later than the DB in framework).
 */
function multitenant_scope_cache($registry) {
	$tenant = $registry->get('tenant');
	$cache = $registry->get('cache');

	if (!$tenant || !$cache || !$tenant->isResolved()) {
		return;
	}

	require_once(DIR_SYSTEM . 'multitenant/tenantcache.php');

	$registry->set('cache', new TenantCache($cache, $tenant->getId()));
}
