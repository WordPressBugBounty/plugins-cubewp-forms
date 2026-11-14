<?php

/**
 * CubeWp Custom Form rest api for Form Data.
 *
 * @version 1.0
 * @package cubewp-forms/cube/classes
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * CubeWp_Rest_API
 */
class CubeWp_Forms_Rest_API extends WP_REST_Controller
{

	//Query CWP_QUERY.
	const CWP_QUERY = 'cwp_query';

	public $namespace = '';

	/**
	 * 
	 * Constructor.
	 */
	public function __construct()
	{
		$this->namespace = 'cubewp-custom-form/v1';
		$this->register_routes();
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes()
	{
		register_rest_route(
			$this->namespace,
			'submit/',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'cubewp_update_forms' ),
					'permission_callback' => array( $this, 'get_without_permission_check' ),
					'args'                => $this->get_render_params(),
				),
			)
		);
	}
/**
	 * Checks if a given request has permission to access content.
	 *
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has search access, WP_Error object otherwise.
	 */
	public function get_permission_check( $request ) {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Resolve the visitor address for rate limiting.
	 *
	 * Sites behind a reverse proxy or CDN see the edge address in REMOTE_ADDR,
	 * which is shared by every visitor, so the proxy's own header is preferred
	 * when one is present. The value is used only as a rate limit bucket key and
	 * is never stored.
	 *
	 * @return string
	 */
	private static function get_request_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			// X-Forwarded-For may be a list; the first entry is the originating client.
			$value = trim( current( explode( ',', $value ) ) );
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '';
	}

	public function get_without_permission_check( $request ) {
		/*
		 * This route is public and creates a lead, so submissions are rate limited
		 * per visitor address to prevent automated flooding.
		 *
		 * WordPress may invoke a permission callback more than once for a single
		 * request, so the decision is memoised. Otherwise a later invocation can
		 * overwrite an earlier refusal, and the limit silently stops applying.
		 *
		 * The threshold is filterable so sites with unusual traffic patterns can
		 * adjust it without editing the plugin.
		 */
		static $decision = null;
		if ( null !== $decision ) {
			return $decision;
		}

		/**
		 * Filter the number of form submissions allowed per minute, per address.
		 *
		 * @param int $limit Default 30.
		 */
		$limit = (int) apply_filters( 'cubewp_forms_submission_rate_limit', 30 );

		$ip = self::get_request_ip();
		if ( '' !== $ip && $limit > 0 ) {
			$key   = 'cwp_forms_rl_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );
			$count = (int) get_transient( $key );
			if ( $count >= $limit ) {
				$decision = new WP_Error(
					'rate_limited',
					__( 'Too many submissions. Please try again in a moment.', 'cubewp-forms' ),
					array( 'status' => 429 )
				);

				return $decision;
			}
			set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		}

		$decision = true;

		return $decision;
	}

	/**
	 * Retrieves the query params for the search results.
	 *
	 * @return array Collection parameters.
	 */
	public function get_render_params()
	{
		$query_params  = parent::get_collection_params();

		$query_params[self::CWP_QUERY] = array(
			'description' => __('The Array with all data.', 'cubewp-forms'),
			'type'        => '',
		);
		return $query_params;
	}


	/**
	 * Retrieves cubewp Forms.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */

	public function cubewp_update_forms($request)
	{
		$CWP_QUERY = $request->get_param(self::CWP_QUERY);

		if (!empty($CWP_QUERY) && count($CWP_QUERY) > 0) {
			/*
			 * The submission handler reads from $_POST, so the request data is
			 * placed there for the duration of the call and the original value is
			 * restored immediately afterwards, leaving the superglobal unchanged
			 * for any later hook in the same request.
			 */
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Superglobal is only snapshotted here so it can be restored below; the request itself is authorised by the REST permission callback.
			$original_post = $_POST;
			$CWP_QUERY['security_nonce'] = wp_create_nonce('cubewp_forms_submit');
			// Simulate the $_POST array with the received $CWP_QUERY data
			$_POST = $CWP_QUERY;

			// Call the form submission function
			$frontend_form = new CubeWp_Forms_Frontend_Custom_Form();
			$frontend_form->cubewp_submit_custom_form();

			// Restore the original $_POST array
			$_POST = $original_post;
		}
	}
	 


	public static function init()
	{
		$CubeClass = __CLASS__;
		new $CubeClass;
	}
}
