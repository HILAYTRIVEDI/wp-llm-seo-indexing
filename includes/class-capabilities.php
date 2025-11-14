<?php
/**
 * Capabilities Management
 *
 * Centralized capability system for plugin permissions.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Capabilities
 *
 * Manages plugin capabilities and permissions.
 */
class WPLLMSEO_Capabilities {

	/**
	 * Plugin capability name
	 *
	 * @var string
	 */
	const MANAGE_CAPABILITY = 'manage_wpllmseo';

	/**
	 * Initialize capabilities
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_capabilities' ) );
	}

	/**
	 * Add plugin capabilities to administrator role
	 */
	public static function add_capabilities() {
		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( self::MANAGE_CAPABILITY ) ) {
			$role->add_cap( self::MANAGE_CAPABILITY );
		}
	}

	/**
	 * Remove plugin capabilities
	 */
	public static function remove_capabilities() {
		$role = get_role( 'administrator' );

		if ( $role && $role->has_cap( self::MANAGE_CAPABILITY ) ) {
			$role->remove_cap( self::MANAGE_CAPABILITY );
		}
	}

	/**
	 * Check if current user can manage plugin
	 *
	 * @return bool True if user has permission.
	 */
	public static function user_can_manage() {
		return current_user_can( self::MANAGE_CAPABILITY );
	}

	/**
	 * Check capability or die
	 *
	 * @param string $message Optional custom error message.
	 */
	public static function require_manage_capability( $message = '' ) {
		if ( ! self::user_can_manage() ) {
			$default_message = __( 'You do not have permission to access this feature.', 'wpllmseo' );
			wp_die( esc_html( $message ? $message : $default_message ) );
		}
	}

	/**
	 * Get capability name for REST permission callback
	 *
	 * @return string Capability name.
	 */
	public static function get_capability() {
		return self::MANAGE_CAPABILITY;
	}

	/**
	 * REST permission callback
	 *
	 * @return bool True if user has permission.
	 */
	public static function rest_permission_callback() {
		return self::user_can_manage();
	}
}
