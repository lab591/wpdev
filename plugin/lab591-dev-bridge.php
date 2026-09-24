<?php
/**
 * Plugin Name:       Lab591 Dev Bridge
 * Description:       Secure bridge between a local Claude Code workspace and this site: read-only exploration, controlled deploys with health check and rollback.
 * Version:           0.6.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Network:           true
 * Author:            Lab591
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Update URI:        https://github.com/lab591/wpdev
 * Text Domain:       lab591-dev-bridge
 *
 * Copyright (C) 2026 Lab591
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation, either version 2 of the
 * License, or (at your option) any later version. It is distributed WITHOUT ANY WARRANTY; see the
 * LICENSE file or <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html> for details.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.6.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::instance()->boot();
