<?php
/**
 * Stand-alone unit tests for the tenant SQL rewriter.
 *
 * Usage: php system/multitenant/tests/rewrite_test.php
 */
define('DB_PREFIX', 'oc_');
require __DIR__ . '/../tenantdb.php';

// Fake underlying DB that just records the SQL it receives.
class FakeDB {
	public $last;
	public function query($sql) { $this->last = $sql; return (object)array('num_rows' => 0, 'row' => array(), 'rows' => array()); }
	public function escape($v) { return addslashes($v); }
	public function countAffected() { return 0; }
	public function getLastId() { return 0; }
	public function connected() { return true; }
}

$tables = array('product', 'product_description', 'order', 'setting', 'customer', 'category', 'seo_url');
$fake = new FakeDB();
$db = new TenantDB($fake, 7, $tables, 'oc_', null);

function check($label, $got, $needle) {
	$ok = strpos($got, $needle) !== false;
	echo ($ok ? "PASS" : "FAIL") . "  $label\n";
	if (!$ok) echo "      got: $got\n      want substr: $needle\n";
	return $ok;
}

$all = true;

// INSERT ... SET (scoped)
$db->query("INSERT INTO oc_product SET name = 'x', price = '5'");
$all &= check("insert SET scoped", $fake->last, "`tenant_id` = 7,");

// INSERT into global table untouched
$db->query("INSERT INTO oc_country SET name = 'Egypt'");
$all &= check("insert global untouched", $fake->last, "INSERT INTO oc_country SET name = 'Egypt'");

// INSERT (cols) VALUES (vals)
$db->query("INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`) VALUES (0, 'config', 'name', 'shop')");
$all &= check("insert cols/values", $fake->last, "(`tenant_id`, `store_id`");
$all &= check("insert cols/values val", $fake->last, "VALUES (7, 0");

// UPDATE with WHERE + ORDER + LIMIT
$db->query("UPDATE oc_product SET status = 1 WHERE product_id = 42 ORDER BY product_id LIMIT 1");
$all &= check("update where preserved", $fake->last, "`oc_product`.`tenant_id` = 7 AND (product_id = 42)");
$all &= check("update limit kept", $fake->last, "ORDER BY product_id LIMIT 1");

// UPDATE no WHERE
$db->query("UPDATE oc_setting SET value = '1'");
$all &= check("update no where", $fake->last, "WHERE `oc_setting`.`tenant_id` = 7");

// DELETE
$db->query("DELETE FROM oc_product WHERE product_id = 9");
$all &= check("delete where", $fake->last, "`tenant_id` = 7 AND (product_id = 9)");

// SELECT with alias + join to global + WHERE + ORDER
$db->query("SELECT p.product_id, p.price FROM oc_product p LEFT JOIN oc_country c ON (c.id = p.cid) WHERE p.status = '1' ORDER BY p.price DESC LIMIT 10");
$all &= check("select alias scoped", $fake->last, "`p`.`tenant_id` = 7 AND (p.status = '1')");
$all &= check("select order kept", $fake->last, "ORDER BY p.price DESC LIMIT 10");

// SELECT no alias, no WHERE
$db->query("SELECT * FROM oc_setting");
$all &= check("select no alias no where", $fake->last, "WHERE `oc_setting`.`tenant_id` = 7");

// SELECT primary global table untouched
$db->query("SELECT * FROM oc_country WHERE status = 1");
if (strpos($fake->last, 'tenant_id') !== false) { echo "FAIL  select global untouched\n"; $all = false; } else echo "PASS  select global untouched\n";

// Sub-query in WHERE must not be split by inner LIMIT/paren
$db->query("SELECT * FROM oc_product p WHERE p.id IN (SELECT id FROM oc_product_description WHERE x=1 LIMIT 5) ORDER BY p.id");
$all &= check("subquery safe predicate", $fake->last, "`p`.`tenant_id` = 7 AND (p.id IN (SELECT id FROM oc_product_description WHERE x=1 LIMIT 5))");
$all &= check("subquery outer order kept", $fake->last, ") ORDER BY p.id");

// Platform context (tenant_id 0) => passthrough
$db0 = new TenantDB($fake, 0, $tables, 'oc_', null);
$db0->query("SELECT * FROM oc_product");
$all &= check("platform passthrough", $fake->last, "SELECT * FROM oc_product");

echo "\n" . ($all ? "ALL PASSED" : "SOME FAILED") . "\n";
exit($all ? 0 : 1);
