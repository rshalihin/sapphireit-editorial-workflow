<?php
/**
 * Test fixture: a dependency file with a non-array dependency list.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => 'wp-element',
	'version'      => 'fixture-malformed',
);
