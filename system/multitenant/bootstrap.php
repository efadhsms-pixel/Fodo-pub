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

		$registry->set('db', new TenantDB(
			$db,
			$tenant->getId(),
			$config['tenant_tables'],
			$prefix,
			$registry->get('log')
		));
	}
}
