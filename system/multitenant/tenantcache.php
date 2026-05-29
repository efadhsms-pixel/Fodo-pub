<?php
/**
 * Tenant-scoping cache decorator
 *
 * Wraps OpenCart's Cache object and namespaces every key with the current
 * tenant id, so cached catalog data (products, categories, currencies, etc.)
 * never bleeds between tenants regardless of the underlying cache driver
 * (file, redis, memcached, ...).
 */
class TenantCache {
	private $cache;
	private $prefix;

	/**
	 * @param object $cache     underlying Cache instance to delegate to
	 * @param int    $tenant_id current tenant id (>0)
	 */
	public function __construct($cache, $tenant_id) {
		$this->cache = $cache;
		$this->prefix = 't' . (int)$tenant_id . '.';
	}

	private function key($key) {
		return $this->prefix . $key;
	}

	public function get($key) {
		return $this->cache->get($this->key($key));
	}

	public function set($key, $value) {
		return $this->cache->set($this->key($key), $value);
	}

	public function delete($key) {
		return $this->cache->delete($this->key($key));
	}
}
