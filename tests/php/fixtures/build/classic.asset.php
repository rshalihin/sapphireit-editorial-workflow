<?php
/**
 * Test fixture: the classic editor entry's dependency file.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( 'wp-dom-ready', 'wp-element', 'wp-i18n' ),
	'version'      => 'fixture-classic',
);
