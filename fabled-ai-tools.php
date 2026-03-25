<?php
/**
 * Plugin Name: Fabled AI Tools
 * Plugin URI: https://fabledsky.com/community-projects/
 * Description: Modular admin-managed AI tools for WordPress using server-side OpenAI Responses API calls.
 * Version: 1.1.0
 * Author: Fabled Sky Research
 * Author URI: https://fabledsky.com/community-projects/
 * Copyright: 2026 Fabled Sky Research
 * Text Domain: fabled-ai-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FAT_VERSION', '1.1.0' );
define( 'FAT_PLUGIN_FILE', __FILE__ );
define( 'FAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FAT_DB_VERSION', '1.1.3' );

require_once FAT_PLUGIN_DIR . 'includes/class-fat-helpers.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-settings.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-activator.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-tools-repository.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-runs-repository.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-tool-validator.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-prompt-engine.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-openai-client.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-media-service.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-entity-query-service.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-builtin-tools.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-featured-image-generator.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-uploaded-image-processor.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-attachment-metadata-assistant.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-usage-limiter.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-tool-runner.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-rest-controller.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-admin.php';
require_once FAT_PLUGIN_DIR . 'includes/class-fat-plugin.php';

register_activation_hook( __FILE__, array( 'FAT_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FAT_Activator', 'deactivate' ) );

function fat_plugin() {
    return FAT_Plugin::instance();
}


function fat_load_textdomain() {
    load_plugin_textdomain( 'fabled-ai-tools', false, dirname( plugin_basename( FAT_PLUGIN_FILE ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'fat_load_textdomain', 5 );

add_action( 'plugins_loaded', 'fat_plugin' );
