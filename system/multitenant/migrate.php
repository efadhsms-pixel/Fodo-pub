<?php
/**
 * Multi-Tenant migration (CLI)
 *
 * Prepares an installed OpenCart database for multi-tenancy:
 *   1. Creates the `<prefix>tenant` table.
 *   2. Adds a `tenant_id` column (+ index) to every tenant-scoped table.
 *   3. Assigns all pre-existing rows to a default tenant.
 *   4. Optionally seeds a first tenant.
 *
 * Safe to run multiple times (idempotent).
 *
 * Usage:
 *   php system/multitenant/migrate.php [--default-tenant=1] \
 *       [--seed-subdomain=shop1] [--seed-name="My Shop"]
 *
 * Run this AFTER OpenCart has been installed (config.php must exist).
 */

if (PHP_SAPI !== 'cli') {
	exit('This migration must be run from the command line.');
}

$root = realpath(__DIR__ . '/../../');

if (!is_file($root . '/config.php')) {
	fwrite(STDERR, "Error: config.php not found. Install OpenCart first.\n");
	exit(1);
}

require_once($root . '/config.php');

foreach (array('DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE') as $const) {
	if (!defined($const)) {
		fwrite(STDERR, "Error: $const is not defined in config.php\n");
		exit(1);
	}
}

$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
$port = defined('DB_PORT') ? DB_PORT : 3306;

// --- options ---------------------------------------------------------------
$options = getopt('', array('default-tenant::', 'seed-subdomain::', 'seed-name::'));
$default_tenant = isset($options['default-tenant']) ? (int)$options['default-tenant'] : 1;
$seed_subdomain = isset($options['seed-subdomain']) ? $options['seed-subdomain'] : '';
$seed_name = isset($options['seed-name']) ? $options['seed-name'] : '';

// --- multi-tenant table list ----------------------------------------------
$mt = require(__DIR__ . '/config.php');
$tables = $mt['tenant_tables'];

// --- connect ---------------------------------------------------------------
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, $port);
if ($db->connect_error) {
	fwrite(STDERR, 'Error: ' . $db->connect_error . "\n");
	exit(1);
}
$db->set_charset('utf8');

function column_exists($db, $database, $table, $column) {
	$stmt = $db->prepare(
		"SELECT 1 FROM information_schema.COLUMNS " .
		"WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
	);
	$stmt->bind_param('sss', $database, $table, $column);
	$stmt->execute();
	$stmt->store_result();
	$exists = $stmt->num_rows > 0;
	$stmt->close();
	return $exists;
}

function table_exists($db, $database, $table) {
	$stmt = $db->prepare(
		"SELECT 1 FROM information_schema.TABLES " .
		"WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1"
	);
	$stmt->bind_param('ss', $database, $table);
	$stmt->execute();
	$stmt->store_result();
	$exists = $stmt->num_rows > 0;
	$stmt->close();
	return $exists;
}

function index_exists($db, $database, $table, $index) {
	$stmt = $db->prepare(
		"SELECT 1 FROM information_schema.STATISTICS " .
		"WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
	);
	$stmt->bind_param('sss', $database, $table, $index);
	$stmt->execute();
	$stmt->store_result();
	$exists = $stmt->num_rows > 0;
	$stmt->close();
	return $exists;
}

function run($db, $sql) {
	if (!$db->query($sql)) {
		fwrite(STDERR, '  ! ' . $db->error . "\n    " . $sql . "\n");
		return false;
	}
	return true;
}

$database = DB_DATABASE;

echo "Multi-Tenant migration\n";
echo "  database : $database\n";
echo "  prefix   : $prefix\n";
echo "  tables   : " . count($tables) . "\n\n";

// 1. Tenant registry table -------------------------------------------------
$tenant_table = $prefix . 'tenant';
if (!table_exists($db, $database, $tenant_table)) {
	echo "Creating `$tenant_table`\n";
	run($db,
		"CREATE TABLE `$tenant_table` (" .
		" `tenant_id` INT(11) NOT NULL AUTO_INCREMENT," .
		" `subdomain` VARCHAR(64) NOT NULL," .
		" `name` VARCHAR(255) NOT NULL," .
		" `status` TINYINT(1) NOT NULL DEFAULT 1," .
		" `date_added` DATETIME NOT NULL," .
		" PRIMARY KEY (`tenant_id`)," .
		" UNIQUE KEY `subdomain` (`subdomain`)" .
		") ENGINE=InnoDB DEFAULT CHARSET=utf8;"
	);
} else {
	echo "`$tenant_table` already exists\n";
}

// 2. Add tenant_id to scoped tables ----------------------------------------
echo "\nAdding tenant_id columns:\n";
$added = 0; $skipped = 0; $missing = 0;
foreach ($tables as $name) {
	$table = $prefix . $name;

	if (!table_exists($db, $database, $table)) {
		echo "  - $table (table not found, skipped)\n";
		$missing++;
		continue;
	}

	if (column_exists($db, $database, $table, 'tenant_id')) {
		$skipped++;
		continue;
	}

	if (run($db, "ALTER TABLE `$table` ADD COLUMN `tenant_id` INT(11) NOT NULL DEFAULT " . $default_tenant . ";")) {
		if (!index_exists($db, $database, $table, 'tenant_id')) {
			run($db, "ALTER TABLE `$table` ADD INDEX `tenant_id` (`tenant_id`);");
		}
		echo "  + $table\n";
		$added++;
	}
}
echo "  ($added added, $skipped already present, $missing missing)\n";

// 3. Seed a first tenant ----------------------------------------------------
if ($seed_subdomain !== '') {
	$sub = $db->real_escape_string($seed_subdomain);
	$nm = $db->real_escape_string($seed_name !== '' ? $seed_name : $seed_subdomain);

	$res = $db->query("SELECT tenant_id FROM `$tenant_table` WHERE subdomain = '$sub' LIMIT 1");
	if ($res && $res->num_rows) {
		echo "\nTenant '$seed_subdomain' already exists.\n";
	} else {
		run($db, "INSERT INTO `$tenant_table` SET subdomain = '$sub', name = '$nm', status = 1, date_added = NOW();");
		echo "\nSeeded tenant '$seed_subdomain' (id " . $db->insert_id . ").\n";
	}
}

$db->close();
echo "\nDone.\n";
