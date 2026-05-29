<?php
/**
 * Platform (super-admin) console
 *
 * A small, self-contained app for managing tenants from the platform base
 * domain. Lets the operator create, edit, enable/disable and delete shops.
 *
 * Security: session login (hashed password), CSRF tokens on every mutating
 * action, prepared statements, and an optional host restriction so the console
 * only answers on the base domain.
 */

session_start();

$platform_config = __DIR__ . '/config.php';
if (!is_file($platform_config)) {
	http_response_code(503);
	exit('Platform console is not set up. Run: php platform/setup.php <username> <password>');
}
require($platform_config);

$root_config = __DIR__ . '/../config.php';
if (!is_file($root_config)) {
	http_response_code(503);
	exit('OpenCart is not installed (config.php missing).');
}
require($root_config);

$mt = require(__DIR__ . '/../system/multitenant/config.php');

$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
$tenant_table = $prefix . 'tenant';

// --- Host restriction ------------------------------------------------------
if (defined('PLATFORM_RESTRICT_HOST') && PLATFORM_RESTRICT_HOST) {
	$host = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
	$base = strtolower($mt['base_domain']);
	if ($host !== $base && $host !== 'www.' . $base) {
		http_response_code(404);
		exit('Not found.');
	}
}

// --- Helpers ---------------------------------------------------------------
function e($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
	if (empty($_SESSION['platform_csrf'])) {
		$_SESSION['platform_csrf'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['platform_csrf'];
}

function csrf_check() {
	$token = isset($_POST['csrf']) ? $_POST['csrf'] : '';
	if (empty($_SESSION['platform_csrf']) || !hash_equals($_SESSION['platform_csrf'], $token)) {
		http_response_code(400);
		exit('Invalid CSRF token.');
	}
}

function is_logged_in() {
	return !empty($_SESSION['platform_auth']);
}

function redirect($query = '') {
	$self = strtok($_SERVER['REQUEST_URI'], '?');
	header('Location: ' . $self . ($query ? '?' . $query : ''));
	exit;
}

function db_connect() {
	$port = defined('DB_PORT') ? DB_PORT : 3306;
	$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, $port);
	if ($db->connect_error) {
		http_response_code(500);
		exit('Database error.');
	}
	$db->set_charset('utf8');
	return $db;
}

function valid_subdomain($s) {
	// DNS label: letters, digits, hyphen; not starting/ending with hyphen.
	return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $s);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$message = isset($_GET['m']) ? $_GET['m'] : '';
$error = '';

// --- Authentication --------------------------------------------------------
if ($action === 'logout') {
	$_SESSION = array();
	session_destroy();
	redirect();
}

if (!is_logged_in()) {
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
		csrf_check();
		$u = isset($_POST['username']) ? $_POST['username'] : '';
		$p = isset($_POST['password']) ? $_POST['password'] : '';

		if (hash_equals(PLATFORM_USERNAME, $u) && password_verify($p, PLATFORM_PASSWORD_HASH)) {
			session_regenerate_id(true);
			$_SESSION['platform_auth'] = true;
			redirect('m=welcome');
		} else {
			$error = 'Invalid username or password.';
		}
	}

	render_login($error);
	exit;
}

// --- Authenticated actions -------------------------------------------------
$db = db_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	csrf_check();

	if ($action === 'create') {
		$subdomain = strtolower(trim(isset($_POST['subdomain']) ? $_POST['subdomain'] : ''));
		$name = trim(isset($_POST['name']) ? $_POST['name'] : '');
		$status = !empty($_POST['status']) ? 1 : 0;

		if (!valid_subdomain($subdomain)) {
			$error = 'Invalid sub-domain. Use letters, digits and hyphens.';
		} elseif (in_array($subdomain, $mt['reserved_subdomains'], true)) {
			$error = 'That sub-domain is reserved.';
		} elseif ($name === '') {
			$error = 'Name is required.';
		} else {
			$stmt = $db->prepare("INSERT INTO `$tenant_table` (subdomain, name, status, date_added) VALUES (?, ?, ?, NOW())");
			$stmt->bind_param('ssi', $subdomain, $name, $status);
			if ($stmt->execute()) {
				redirect('m=created');
			}
			$error = ($db->errno === 1062) ? 'That sub-domain already exists.' : 'Could not create tenant.';
		}
	} elseif ($action === 'update') {
		$id = (int)$_POST['tenant_id'];
		$name = trim(isset($_POST['name']) ? $_POST['name'] : '');
		$status = !empty($_POST['status']) ? 1 : 0;

		if ($name === '') {
			$error = 'Name is required.';
		} else {
			$stmt = $db->prepare("UPDATE `$tenant_table` SET name = ?, status = ? WHERE tenant_id = ?");
			$stmt->bind_param('sii', $name, $status, $id);
			$stmt->execute();
			redirect('m=updated');
		}
	} elseif ($action === 'toggle') {
		$id = (int)$_POST['tenant_id'];
		$db->query("UPDATE `$tenant_table` SET status = 1 - status WHERE tenant_id = " . $id);
		redirect('m=updated');
	} elseif ($action === 'delete') {
		$id = (int)$_POST['tenant_id'];
		$stmt = $db->prepare("DELETE FROM `$tenant_table` WHERE tenant_id = ?");
		$stmt->bind_param('i', $id);
		$stmt->execute();
		redirect('m=deleted');
	}
}

// Edit form data.
$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
	$id = (int)$_GET['id'];
	$res = $db->query("SELECT * FROM `$tenant_table` WHERE tenant_id = " . $id);
	$edit = $res ? $res->fetch_assoc() : null;
}

// Tenant list.
$tenants = array();
$res = $db->query("SELECT * FROM `$tenant_table` ORDER BY tenant_id DESC");
if ($res) {
	while ($row = $res->fetch_assoc()) {
		$tenants[] = $row;
	}
}

render_console($tenants, $edit, $mt['base_domain'], $message, $error);

/* ========================================================================== */
/* Views                                                                      */
/* ========================================================================== */

function page_head($title) {
	$t = e($title);
	echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
	echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
	echo "<title>$t</title><style>";
	echo "*{box-sizing:border-box}body{font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;margin:0;background:#0f172a;color:#e2e8f0}";
	echo ".wrap{max-width:920px;margin:0 auto;padding:24px}";
	echo "h1{font-size:20px;margin:0 0 4px}.muted{color:#94a3b8;font-size:13px}";
	echo "a{color:#60a5fa;text-decoration:none}a:hover{text-decoration:underline}";
	echo ".card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px;margin:16px 0}";
	echo "table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;text-align:left;border-bottom:1px solid #334155}";
	echo "th{color:#94a3b8;font-size:12px;text-transform:uppercase;letter-spacing:.04em}";
	echo "input[type=text],input[type=password]{width:100%;padding:9px;border:1px solid #475569;border-radius:8px;background:#0f172a;color:#e2e8f0}";
	echo "label{display:block;margin:10px 0 4px;font-size:13px;color:#cbd5e1}";
	echo ".btn{display:inline-block;padding:8px 14px;border-radius:8px;border:0;background:#2563eb;color:#fff;cursor:pointer;font-size:14px}";
	echo ".btn.gray{background:#475569}.btn.red{background:#dc2626}.btn.sm{padding:5px 10px;font-size:13px}";
	echo ".row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}.row>div{flex:1;min-width:180px}";
	echo ".pill{padding:2px 9px;border-radius:999px;font-size:12px}.on{background:#065f46;color:#d1fae5}.off{background:#7f1d1d;color:#fee2e2}";
	echo ".note{padding:9px 12px;border-radius:8px;margin:12px 0}.ok{background:#064e3b;color:#d1fae5}.err{background:#7f1d1d;color:#fee2e2}";
	echo "form.inline{display:inline}";
	echo "</style></head><body><div class=\"wrap\">";
}

function page_foot() {
	echo "</div></body></html>";
}

function render_login($error) {
	page_head('Platform Console — Sign in');
	$csrf = e(csrf_token());
	echo "<h1>Platform Console</h1><p class=\"muted\">Multi-tenant administration</p>";
	if ($error) echo "<div class=\"note err\">" . e($error) . "</div>";
	echo "<div class=\"card\" style=\"max-width:380px\">";
	echo "<form method=\"post\" action=\"?action=login\">";
	echo "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\">";
	echo "<label>Username</label><input type=\"text\" name=\"username\" autofocus>";
	echo "<label>Password</label><input type=\"password\" name=\"password\">";
	echo "<div style=\"margin-top:16px\"><button class=\"btn\" type=\"submit\">Sign in</button></div>";
	echo "</form></div>";
	page_foot();
}

function render_console($tenants, $edit, $base_domain, $message, $error) {
	page_head('Platform Console — Tenants');
	$csrf = e(csrf_token());

	echo "<div style=\"display:flex;justify-content:space-between;align-items:center\">";
	echo "<div><h1>Tenants</h1><p class=\"muted\">Base domain: " . e($base_domain) . "</p></div>";
	echo "<a class=\"btn gray sm\" href=\"?action=logout\">Sign out</a></div>";

	$messages = array(
		'welcome' => 'Signed in.',
		'created' => 'Tenant created.',
		'updated' => 'Tenant updated.',
		'deleted' => 'Tenant deleted.',
	);
	if ($message && isset($messages[$message])) echo "<div class=\"note ok\">" . e($messages[$message]) . "</div>";
	if ($error) echo "<div class=\"note err\">" . e($error) . "</div>";

	// Create / edit form.
	if ($edit) {
		echo "<div class=\"card\"><h1 style=\"font-size:16px\">Edit tenant</h1>";
		echo "<form method=\"post\" action=\"?action=update\">";
		echo "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\">";
		echo "<input type=\"hidden\" name=\"tenant_id\" value=\"" . e($edit['tenant_id']) . "\">";
		echo "<div class=\"row\">";
		echo "<div><label>Sub-domain</label><input type=\"text\" value=\"" . e($edit['subdomain']) . "\" disabled></div>";
		echo "<div><label>Name</label><input type=\"text\" name=\"name\" value=\"" . e($edit['name']) . "\"></div>";
		echo "</div>";
		echo "<label style=\"margin-top:12px\"><input type=\"checkbox\" name=\"status\" value=\"1\"" . ($edit['status'] ? ' checked' : '') . "> Active</label>";
		echo "<div style=\"margin-top:14px\"><button class=\"btn\" type=\"submit\">Save</button> ";
		echo "<a class=\"btn gray\" href=\"?\">Cancel</a></div>";
		echo "</form></div>";
	} else {
		echo "<div class=\"card\"><h1 style=\"font-size:16px\">Add a tenant</h1>";
		echo "<form method=\"post\" action=\"?action=create\">";
		echo "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\">";
		echo "<div class=\"row\">";
		echo "<div><label>Sub-domain</label><input type=\"text\" name=\"subdomain\" placeholder=\"shop1\"></div>";
		echo "<div><label>Name</label><input type=\"text\" name=\"name\" placeholder=\"My Shop\"></div>";
		echo "</div>";
		echo "<label style=\"margin-top:12px\"><input type=\"checkbox\" name=\"status\" value=\"1\" checked> Active</label>";
		echo "<div style=\"margin-top:14px\"><button class=\"btn\" type=\"submit\">Create</button></div>";
		echo "</form></div>";
	}

	// Tenant list.
	echo "<div class=\"card\"><table><thead><tr><th>ID</th><th>Sub-domain</th><th>Name</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>";
	if (!$tenants) {
		echo "<tr><td colspan=\"6\" class=\"muted\">No tenants yet.</td></tr>";
	}
	foreach ($tenants as $t) {
		$url = e($t['subdomain'] . '.' . $base_domain);
		echo "<tr>";
		echo "<td>" . e($t['tenant_id']) . "</td>";
		echo "<td><a href=\"//" . $url . "\" target=\"_blank\">" . e($t['subdomain']) . "</a></td>";
		echo "<td>" . e($t['name']) . "</td>";
		echo "<td>" . ($t['status'] ? "<span class=\"pill on\">active</span>" : "<span class=\"pill off\">disabled</span>") . "</td>";
		echo "<td class=\"muted\">" . e($t['date_added']) . "</td>";
		echo "<td style=\"white-space:nowrap;text-align:right\">";
		echo "<a class=\"btn gray sm\" href=\"?action=edit&id=" . e($t['tenant_id']) . "\">Edit</a> ";
		echo "<form class=\"inline\" method=\"post\" action=\"?action=toggle\"><input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><input type=\"hidden\" name=\"tenant_id\" value=\"" . e($t['tenant_id']) . "\"><button class=\"btn gray sm\" type=\"submit\">" . ($t['status'] ? 'Disable' : 'Enable') . "</button></form> ";
		echo "<form class=\"inline\" method=\"post\" action=\"?action=delete\" onsubmit=\"return confirm('Delete this tenant? Its data rows are not removed.');\"><input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><input type=\"hidden\" name=\"tenant_id\" value=\"" . e($t['tenant_id']) . "\"><button class=\"btn red sm\" type=\"submit\">Delete</button></form>";
		echo "</td></tr>";
	}
	echo "</tbody></table></div>";
	echo "<p class=\"muted\">New tenants share the migrated schema. Run <code>php system/multitenant/migrate.php</code> once after install to prepare the database.</p>";
	page_foot();
}
