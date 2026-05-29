<?php
/**
 * Platform (super-admin) console configuration.
 *
 * Do NOT edit this dist file directly. Generate platform/config.php with:
 *
 *   php platform/setup.php <username> <password>
 *
 * which writes the values below with a securely hashed password.
 */

// Super-admin login.
define('PLATFORM_USERNAME', '');
define('PLATFORM_PASSWORD_HASH', '');

// Restrict the console to the platform base domain (strongly recommended).
define('PLATFORM_RESTRICT_HOST', true);
