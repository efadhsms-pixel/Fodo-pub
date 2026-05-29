<?php
/**
 * Tenant resolver
 *
 * Identifies the current tenant from the request host (sub-domain) and looks
 * it up in the `<prefix>tenant` table. The resolved tenant id is what the
 * tenant DB layer uses to scope every query.
 */
class Tenant {
	private $db;
	private $config;

	private $id = 0;
	private $subdomain = '';
	private $name = '';
	private $resolved = false;
	private $platform = false;

	/**
	 * @param DB    $db     a raw (unwrapped) database connection
	 * @param array $config the array returned by system/multitenant/config.php
	 */
	public function __construct($db, array $config) {
		$this->db = $db;
		$this->config = $config;

		$this->resolve();
	}

	/**
	 * Extract the sub-domain from the request host and resolve it to a tenant.
	 */
	private function resolve() {
		$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

		// Strip port and lowercase.
		$host = strtolower(preg_replace('/:\d+$/', '', trim($host)));

		$base = strtolower(trim($this->config['base_domain']));

		// Bare base domain (or empty host) => platform context, no tenant.
		if ($host === '' || $host === $base) {
			$this->platform = true;
			return;
		}

		// Must be a sub-domain of the configured base domain.
		$suffix = '.' . $base;
		if (substr($host, -strlen($suffix)) !== $suffix) {
			// Unknown host (e.g. raw IP, staging domain). Treat as platform
			// so the install/landing flow is not broken.
			$this->platform = true;
			return;
		}

		$subdomain = substr($host, 0, -strlen($suffix));

		// Only the left-most label is the tenant key (shop1 in shop1.base.com).
		// Anything deeper (a.shop1.base.com) is rejected.
		if ($subdomain === '' || strpos($subdomain, '.') !== false) {
			$this->platform = true;
			return;
		}

		// Reserved sub-domains belong to the platform, not a tenant.
		if (in_array($subdomain, $this->config['reserved_subdomains'], true)) {
			$this->platform = true;
			return;
		}

		$this->subdomain = $subdomain;
		$this->lookup($subdomain);
	}

	/**
	 * Look the sub-domain up in the tenant table.
	 */
	private function lookup($subdomain) {
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';

		try {
			$query = $this->db->query(
				"SELECT tenant_id, name, status FROM `" . $prefix . "tenant` " .
				"WHERE subdomain = '" . $this->db->escape($subdomain) . "' LIMIT 1"
			);
		} catch (\Exception $e) {
			// Tenant table missing (e.g. before migration) -> no tenant.
			return;
		}

		if ($query->num_rows && (int)$query->row['status'] === 1) {
			$this->id = (int)$query->row['tenant_id'];
			$this->name = $query->row['name'];
			$this->resolved = true;
		}
	}

	/**
	 * @return int tenant id, or 0 when no tenant is resolved
	 */
	public function getId() {
		return $this->id;
	}

	public function getSubdomain() {
		return $this->subdomain;
	}

	public function getName() {
		return $this->name;
	}

	/**
	 * @return bool true when a valid, active tenant was resolved
	 */
	public function isResolved() {
		return $this->resolved;
	}

	/**
	 * @return bool true for the platform context (bare base domain / unknown host)
	 */
	public function isPlatform() {
		return $this->platform;
	}
}
