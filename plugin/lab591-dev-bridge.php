<?php
/**
 * Plugin Name:       Lab591 Dev Bridge
 * Description:       Secure bridge between a local Claude Code workspace and this site: read-only exploration, controlled deploys with health check and rollback.
 * Version:           0.4.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Network:           true
 * Author:            Lab591
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       lab591-dev-bridge
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.4.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::instance()->boot();
