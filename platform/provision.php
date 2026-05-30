<?php
/**
 * Tenant provisioning
 *
 * Seeds the minimum a freshly registered tenant needs to be usable:
 *   - a full copy of the template tenant's store settings (re-pointed at the
 *     new shop's name / email / URL),
 *   - an Administrator user group (cloned from the template), and
 *   - an admin user the owner can log in with.
 *
 * Reference data (currencies, order/return statuses, length/weight classes,
 * geo zones, languages, countries) is shared platform-wide, so the cloned
 * settings resolve correctly without copying those tables.
 *
 * All writes set tenant_id explicitly and use the raw connection, so this is
 * safe to call from the platform (base-domain) context where the scoped DB
 * layer is not active.
 */

function mt_token($length = 9) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$max = strlen($chars) - 1;
	$out = '';
	for ($i = 0; $i < $length; $i++) {
		$out .= $chars[random_int(0, $max)];
	}
	return $out;
}

/**
 * @param mysqli $db
 * @param string $prefix    DB prefix
 * @param int    $tenant_id the new tenant's id
 * @param array  $opts      store_name, email, admin_user, admin_pass, base_url,
 *                          [template_tenant]
 * @return int the new admin user's id
 */
function provision_tenant($db, $prefix, $tenant_id, array $opts) {
	$template = isset($opts['template_tenant']) ? (int)$opts['template_tenant'] : 1;

	$t_setting = $prefix . 'setting';
	$t_group   = $prefix . 'user_group';
	$t_user    = $prefix . 'user';

	// 1. Clone store settings (store_id 0) from the template tenant.
	$res = $db->query("SELECT store_id, code, `key`, value, serialized FROM `$t_setting` WHERE tenant_id = " . $template . " AND store_id = 0");
	if ($res) {
		$ins = $db->prepare("INSERT INTO `$t_setting` SET tenant_id = ?, store_id = ?, code = ?, `key` = ?, value = ?, serialized = ?");
		while ($row = $res->fetch_assoc()) {
			$ins->bind_param('iisssi', $tenant_id, $row['store_id'], $row['code'], $row['key'], $row['value'], $row['serialized']);
			$ins->execute();
		}
	}

	// Re-point the key store-identity settings at the new shop.
	$overrides = array(
		'config_name'       => $opts['store_name'],
		'config_meta_title' => $opts['store_name'],
		'config_owner'      => $opts['store_name'],
		'config_email'      => $opts['email'],
		'config_url'        => $opts['base_url'],
	);
	$upd = $db->prepare("UPDATE `$t_setting` SET value = ? WHERE tenant_id = ? AND store_id = 0 AND `key` = ?");
	foreach ($overrides as $key => $value) {
		$upd->bind_param('sis', $value, $tenant_id, $key);
		$upd->execute();
	}

	// 2. Clone an Administrator group (full permissions) from the template.
	$group_id = 0;
	$res = $db->query("SELECT name, permission FROM `$t_group` WHERE tenant_id = " . $template . " ORDER BY user_group_id ASC LIMIT 1");
	$grp = $res ? $res->fetch_assoc() : null;

	if ($grp) {
		$ins = $db->prepare("INSERT INTO `$t_group` SET tenant_id = ?, name = ?, permission = ?");
		$ins->bind_param('iss', $tenant_id, $grp['name'], $grp['permission']);
		$ins->execute();
		$group_id = $db->insert_id;
	} else {
		$name = 'Administrator';
		$perm = json_encode(array('access' => array(), 'modify' => array()));
		$ins = $db->prepare("INSERT INTO `$t_group` SET tenant_id = ?, name = ?, permission = ?");
		$ins->bind_param('iss', $tenant_id, $name, $perm);
		$ins->execute();
		$group_id = $db->insert_id;
	}

	// 3. Create the admin user (OpenCart 3 password scheme).
	$salt = mt_token(9);
	$password = sha1($salt . sha1($salt . sha1($opts['admin_pass'])));
	$firstname = 'Store';
	$lastname = 'Owner';
	$image = '';

	$ins = $db->prepare("INSERT INTO `$t_user` SET tenant_id = ?, user_group_id = ?, username = ?, salt = ?, password = ?, firstname = ?, lastname = ?, email = ?, image = ?, code = '', ip = '', status = 1, date_added = NOW()");
	$ins->bind_param('iisssssss', $tenant_id, $group_id, $opts['admin_user'], $salt, $password, $firstname, $lastname, $opts['email'], $image);
	$ins->execute();

	return $db->insert_id;
}
