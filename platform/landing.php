<?php
/**
 * Public landing page — free store sign-up
 *
 * Served on the platform base domain. Visitors pick a sub-domain and create a
 * free store; on submit a tenant is created and provisioned (admin user +
 * store settings) and the owner is shown their store and admin links.
 */

session_start();

$root_config = __DIR__ . '/../config.php';
if (!is_file($root_config)) {
	http_response_code(503);
	exit('Platform is not installed yet.');
}
require($root_config);
require(__DIR__ . '/provision.php');

$mt = require(__DIR__ . '/../system/multitenant/config.php');

$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
$tenant_table = $prefix . 'tenant';
$base_domain = $mt['base_domain'];

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token() {
	if (empty($_SESSION['landing_csrf'])) {
		$_SESSION['landing_csrf'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['landing_csrf'];
}

function valid_subdomain($s) {
	return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $s);
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

$errors = array();
$success = null;
$old = array('store_name' => '', 'subdomain' => '', 'email' => '', 'username' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = isset($_POST['csrf']) ? $_POST['csrf'] : '';
	if (empty($_SESSION['landing_csrf']) || !hash_equals($_SESSION['landing_csrf'], $token)) {
		$errors[] = 'Your session expired. Please try again.';
	}

	// Honeypot (bots fill hidden fields).
	if (!empty($_POST['website'])) {
		$errors[] = 'Spam detected.';
	}

	$store_name = trim(isset($_POST['store_name']) ? $_POST['store_name'] : '');
	$subdomain  = strtolower(trim(isset($_POST['subdomain']) ? $_POST['subdomain'] : ''));
	$email      = trim(isset($_POST['email']) ? $_POST['email'] : '');
	$username   = trim(isset($_POST['username']) ? $_POST['username'] : '');
	$password   = isset($_POST['password']) ? $_POST['password'] : '';

	$old = array('store_name' => $store_name, 'subdomain' => $subdomain, 'email' => $email, 'username' => $username);

	if ($store_name === '' || mb_strlen($store_name) > 64) {
		$errors[] = 'Please enter a store name (up to 64 characters).';
	}
	if (!valid_subdomain($subdomain)) {
		$errors[] = 'Sub-domain may use lowercase letters, digits and hyphens only.';
	} elseif (in_array($subdomain, $mt['reserved_subdomains'], true)) {
		$errors[] = 'That sub-domain is reserved. Please choose another.';
	}
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'Please enter a valid email address.';
	}
	if (strlen($username) < 3 || strlen($username) > 20) {
		$errors[] = 'Admin username must be 3–20 characters.';
	}
	if (strlen($password) < 6) {
		$errors[] = 'Password must be at least 6 characters.';
	}

	if (!$errors) {
		$db = db_connect();

		// Availability check.
		$stmt = $db->prepare("SELECT tenant_id FROM `$tenant_table` WHERE subdomain = ? LIMIT 1");
		$stmt->bind_param('s', $subdomain);
		$stmt->execute();
		$stmt->store_result();
		if ($stmt->num_rows > 0) {
			$errors[] = 'That sub-domain is already taken. Please choose another.';
		}
		$stmt->close();

		if (!$errors) {
			$stmt = $db->prepare("INSERT INTO `$tenant_table` (subdomain, name, status, date_added) VALUES (?, ?, 1, NOW())");
			$stmt->bind_param('ss', $subdomain, $store_name);

			if ($stmt->execute()) {
				$tenant_id = $db->insert_id;

				$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
				$scheme = $secure ? 'https://' : 'http://';
				$base_url = $scheme . $subdomain . '.' . $base_domain . '/';

				provision_tenant($db, $prefix, $tenant_id, array(
					'store_name' => $store_name,
					'email'      => $email,
					'admin_user' => $username,
					'admin_pass' => $password,
					'base_url'   => $base_url,
				));

				$success = array(
					'store_url' => $base_url,
					'admin_url' => $base_url . 'admin/',
					'name'      => $store_name,
				);

				// Reset the form token after a successful sign-up.
				unset($_SESSION['landing_csrf']);
			} else {
				$errors[] = ($db->errno === 1062) ? 'That sub-domain is already taken.' : 'Could not create the store. Please try again.';
			}
		}
	}
}

$csrf = e(csrf_token());
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Launch your free store — <?php echo e($base_domain); ?></title>
<style>
	*{box-sizing:border-box}
	body{margin:0;font:16px/1.6 system-ui,Segoe UI,Roboto,sans-serif;color:#0f172a;background:#f8fafc}
	.hero{background:linear-gradient(135deg,#1e3a8a,#2563eb 55%,#06b6d4);color:#fff;padding:64px 20px 96px}
	.wrap{max-width:1040px;margin:0 auto}
	.nav{display:flex;justify-content:space-between;align-items:center;color:#fff}
	.brand{font-weight:700;font-size:20px;letter-spacing:.02em}
	.nav a{color:#dbeafe;text-decoration:none;font-size:14px}
	.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:40px;margin-top:48px;align-items:start}
	@media(max-width:860px){.grid{grid-template-columns:1fr}}
	.hero h1{font-size:42px;line-height:1.15;margin:0 0 16px;font-weight:800}
	.hero p.lead{font-size:19px;color:#e0f2fe;margin:0 0 24px;max-width:30em}
	.badge{display:inline-block;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);padding:6px 14px;border-radius:999px;font-size:13px;margin-bottom:20px}
	ul.feat{list-style:none;padding:0;margin:24px 0 0}
	ul.feat li{padding:6px 0 6px 30px;position:relative;color:#eff6ff}
	ul.feat li::before{content:"\2713";position:absolute;left:0;color:#a7f3d0;font-weight:700}
	.card{background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(2,6,23,.28);padding:28px;color:#0f172a}
	.card h2{margin:0 0 4px;font-size:22px}
	.card .sub{color:#64748b;font-size:14px;margin:0 0 18px}
	label{display:block;font-size:13px;font-weight:600;color:#334155;margin:14px 0 5px}
	input{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px}
	input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
	.sub-row{display:flex;align-items:stretch}
	.sub-row input{border-top-right-radius:0;border-bottom-right-radius:0}
	.sub-row .suffix{display:flex;align-items:center;padding:0 12px;background:#f1f5f9;border:1px solid #cbd5e1;border-left:0;border-top-right-radius:10px;border-bottom-right-radius:10px;color:#475569;font-size:14px;white-space:nowrap}
	.hp{position:absolute;left:-9999px}
	.btn{margin-top:22px;width:100%;padding:13px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-size:16px;font-weight:700;cursor:pointer}
	.btn:hover{background:#1d4ed8}
	.fineprint{margin-top:12px;font-size:12px;color:#94a3b8;text-align:center}
	.note{padding:11px 14px;border-radius:10px;margin:0 0 16px;font-size:14px}
	.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
	.err ul{margin:6px 0 0;padding-left:18px}
	.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
	.ok a{color:#047857;font-weight:600}
	.steps{background:#fff;padding:56px 20px}
	.steps .wrap{display:grid;grid-template-columns:repeat(3,1fr);gap:28px}
	@media(max-width:860px){.steps .wrap{grid-template-columns:1fr}}
	.step{padding:8px}
	.step .n{width:38px;height:38px;border-radius:10px;background:#dbeafe;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-weight:700;margin-bottom:12px}
	.step h3{margin:0 0 6px;font-size:17px}
	.step p{margin:0;color:#64748b;font-size:14px}
	footer{padding:28px 20px;text-align:center;color:#94a3b8;font-size:13px}
</style>
</head>
<body>
<section class="hero">
	<div class="wrap">
		<div class="nav">
			<div class="brand"><?php echo e($base_domain); ?></div>
			<a href="/platform/">Platform admin</a>
		</div>
		<div class="grid">
			<div>
				<span class="badge">No fees for now — completely free</span>
				<h1>Launch your online store in minutes.</h1>
				<p class="lead">Pick a name, choose your address, and get a ready-to-use shop on your own sub-domain. No credit card, no setup cost.</p>
				<ul class="feat">
					<li>Your own store at <strong>yourname.<?php echo e($base_domain); ?></strong></li>
					<li>Full admin dashboard to manage products &amp; orders</li>
					<li>Built-in payments &amp; shipping options</li>
					<li>Completely isolated &amp; private from other stores</li>
				</ul>
			</div>
			<div class="card">
				<?php if ($success): ?>
					<h2>🎉 Your store is live!</h2>
					<p class="sub">“<?php echo e($success['name']); ?>” has been created.</p>
					<div class="note ok">
						<div>Storefront: <a href="<?php echo e($success['store_url']); ?>" target="_blank"><?php echo e($success['store_url']); ?></a></div>
						<div style="margin-top:6px">Admin: <a href="<?php echo e($success['admin_url']); ?>" target="_blank"><?php echo e($success['admin_url']); ?></a></div>
					</div>
					<p class="sub">Sign in to the admin with the username and password you just chose, then start adding products.</p>
					<a class="btn" style="display:block;text-align:center;text-decoration:none" href="<?php echo e($success['admin_url']); ?>">Go to my dashboard</a>
				<?php else: ?>
					<h2>Create your free store</h2>
					<p class="sub">Takes less than a minute.</p>
					<?php if ($errors): ?>
						<div class="note err">Please fix the following:
							<ul><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul>
						</div>
					<?php endif; ?>
					<form method="post" autocomplete="off">
						<input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
						<input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">

						<label>Store name</label>
						<input type="text" name="store_name" value="<?php echo e($old['store_name']); ?>" placeholder="Acme Store" maxlength="64" required>

						<label>Choose your address</label>
						<div class="sub-row">
							<input type="text" name="subdomain" value="<?php echo e($old['subdomain']); ?>" placeholder="acme" required>
							<span class="suffix">.<?php echo e($base_domain); ?></span>
						</div>

						<label>Email</label>
						<input type="email" name="email" value="<?php echo e($old['email']); ?>" placeholder="you@example.com" required>

						<label>Admin username</label>
						<input type="text" name="username" value="<?php echo e($old['username']); ?>" placeholder="admin" minlength="3" maxlength="20" required>

						<label>Admin password</label>
						<input type="password" name="password" placeholder="At least 6 characters" minlength="6" required>

						<button class="btn" type="submit">Create my free store →</button>
						<p class="fineprint">Free while in beta. No payment details required.</p>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>

<section class="steps">
	<div class="wrap">
		<div class="step"><div class="n">1</div><h3>Sign up</h3><p>Choose your store name and sub-domain. Your shop is created instantly.</p></div>
		<div class="step"><div class="n">2</div><h3>Set it up</h3><p>Log into your private dashboard, add products and configure payments &amp; shipping.</p></div>
		<div class="step"><div class="n">3</div><h3>Start selling</h3><p>Share your store link and take orders — your data stays fully isolated.</p></div>
	</div>
</section>

<footer>© <?php echo date('Y'); ?> <?php echo e($base_domain); ?> — free store hosting (beta).</footer>
</body>
</html>
