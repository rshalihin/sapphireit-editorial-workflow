<?php
/**
 * Test fixture: a built entry's dependency file.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( 'wp-element', 'wp-i18n' ),
	'version'      => 'fixture-sidebar',
);
