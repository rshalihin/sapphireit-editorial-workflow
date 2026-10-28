<?php
/**
 * Request source detection.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the kind of request a publication or gate decision happened in, for
 * the `source` key of publication activity context (E7).
 *
 * @since 1.1.0
 * @internal
 */
final class RequestSource {

	/**
	 * WP-Cron, including cron run from WP-CLI (`wp cron event run`).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CRON = 'cron';

	/**
	 * WP-CLI.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CLI = 'cli';

	/**
	 * REST API request.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REST = 'rest';

	/**
	 * Admin AJAX request.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const AJAX = 'ajax';

	/**
	 * Any other wp-admin request (classic editor, Quick Edit, Bulk Edit).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const ADMIN = 'admin';

	/**
	 * Anything else, e.g. a front-end request.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OTHER = 'other';

	/**
	 * The current request's source.
	 *
	 * The checks run from the most specific context to the most general,
	 * because the contexts overlap: a cron event run from WP-CLI is both cron
	 * and CLI and is reported as cron, and AJAX requests run through
	 * `admin-ajax.php`, where `is_admin()` is true too.
	 *
	 * @since 1.1.0
	 *
	 * @return string One of the class constants.
	 */
	public static function detect(): string {
		if ( wp_doing_cron() ) {
			return self::CRON;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::CLI;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::REST;
		}

		if ( wp_doing_ajax() ) {
			return self::AJAX;
		}

		if ( is_admin() ) {
			return self::ADMIN;
		}

		return self::OTHER;
	}
}
