<?php

namespace WP_CLI\Init;

use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$wpcli_init_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpcli_init_autoloader ) ) {
	require_once $wpcli_init_autoloader;
}

WP_CLI::add_command( 'init', InitCommand::class );
