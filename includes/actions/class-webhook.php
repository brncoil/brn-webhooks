<?php
/**
 * HTTP request / outgoing webhook action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connections\Http_Auth;
use BRN\Webhooks\Context;
use BRN\Webhooks\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Send data to any URL / API.
 */
class Webhook extends Action {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'http';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'HTTP request / webhook', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send the data to any URL or API — CRMs, Make, n8n, your own server.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Webhooks', 'brn-webhooks' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'webhook';
	}

	/**
	 * Connection (optional API credentials).
	 *
	 * @return string
	 */
	public function connection_type() {
		return 'http_auth';
	}

	/**
	 * Optional.
	 *
	 * @return bool
	 */
	public function connection_optional() {
		return true;
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'     => 'method',
				'type'    => 'select',
				'label'   => __( 'Method', 'brn-webhooks' ),
				'default' => 'POST',
				'options' => self::options(
					array(
						'POST'   => 'POST',
						'GET'    => 'GET',
						'PUT'    => 'PUT',
						'PATCH'  => 'PATCH',
						'DELETE' => 'DELETE',
					)
				),
				'width'   => 'third',
			),
			array(
				'key'         => 'url',
				'type'        => 'url',
				'label'       => __( 'URL', 'brn-webhooks' ),
				'placeholder' => 'https://api.example.com/leads',
				'template'    => true,
				'required'    => true,
				'width'       => 'two-thirds',
			),
			array(
				'key'     => 'body_type',
				'type'    => 'choice',
				'label'   => __( 'Body', 'brn-webhooks' ),
				'default' => 'json',
				'options' => self::options(
					array(
						'json' => 'JSON',
						'form' => __( 'Form (urlencoded)', 'brn-webhooks' ),
						'raw'  => __( 'Raw / custom', 'brn-webhooks' ),
						'all'  => __( 'All fields as JSON', 'brn-webhooks' ),
						'none' => __( 'None', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'              => 'body',
				'type'             => 'keyvalue',
				'label'            => __( 'Fields', 'brn-webhooks' ),
				'key_placeholder'  => __( 'name (dots create nesting: user.email)', 'brn-webhooks' ),
				'show_if'          => array( 'body_type' => array( 'json', 'form' ) ),
				'template'         => true,
			),
			array(
				'key'         => 'raw_body',
				'type'        => 'code',
				'rows'        => 7,
				'label'       => __( 'Raw body', 'brn-webhooks' ),
				'placeholder' => "{\n  \"name\": \"{{name}}\",\n  \"email\": \"{{email}}\"\n}",
				'template'    => true,
				'show_if'     => array( 'body_type' => 'raw' ),
				'help'        => __( 'When the content type is JSON, inserted values are escaped automatically.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'content_type',
				'type'    => 'text',
				'label'   => __( 'Content type', 'brn-webhooks' ),
				'default' => 'application/json',
				'show_if' => array( 'body_type' => 'raw' ),
			),
			array(
				'key'   => '_h_advanced',
				'type'  => 'heading',
				'label' => __( 'Headers & options', 'brn-webhooks' ),
				'collapsible' => true,
			),
			array(
				'key'             => 'headers',
				'type'            => 'keyvalue',
				'label'           => __( 'Headers', 'brn-webhooks' ),
				'key_placeholder' => 'X-Custom-Header',
				'template'        => true,
			),
			array(
				'key'      => 'query',
				'type'     => 'keyvalue',
				'label'    => __( 'Query parameters', 'brn-webhooks' ),
				'template' => true,
			),
			array(
				'key'     => 'timeout',
				'type'    => 'number',
				'label'   => __( 'Timeout (seconds)', 'brn-webhooks' ),
				'default' => 20,
				'width'   => 'half',
			),
			array(
				'key'     => 'fail_on_error',
				'type'    => 'toggle',
				'label'   => __( 'Treat non-2xx responses as failures', 'brn-webhooks' ),
				'default' => true,
			),
		);
	}

	/**
	 * Execute.
	 *
	 * @param array      $config     Config.
	 * @param Context    $ctx        Context.
	 * @param array|null $connection Connection.
	 * @return array
	 * @throws Action_Error On failure.
	 */
	public function execute( array $config, Context $ctx, $connection ) {
		$method = strtoupper( (string) self::cfg( $config, 'method', 'POST' ) );
		$tpl = trim( (string) self::cfg( $config, 'url' ) );
		$url = trim( $ctx->render( $tpl ) );
		if ( '' === $url ) {
			throw new Action_Error( __( 'URL is empty.', 'brn-webhooks' ), false );
		}
		// Placeholders inside the host part must not be able to change where credentials go.
		if ( preg_match( '#^([a-z]+://)?([^/?\#]*)#i', $tpl, $hm ) && '' !== $hm[1] && false !== strpos( $hm[2], '{{' ) ) {
			$host = $ctx->render( $hm[2] );
			if ( preg_match( '#[/?\#@\\\s]#', $host ) ) {
				throw new Action_Error( __( 'A value inserted into the URL host contains invalid characters.', 'brn-webhooks' ), false );
			}
		}
		$headers = $ctx->render_pairs( self::cfg( $config, 'headers', array() ) );
		$query   = $ctx->render_pairs( self::cfg( $config, 'query', array() ) );
		$redact  = array();
		if ( $connection && ! empty( $connection['credentials'] ) ) {
			$redact = Http_Auth::apply( $connection['credentials'], $headers, $query );
		}
		$opts = array(
			'headers'       => $headers,
			'query'         => $query,
			'timeout'       => (int) self::cfg( $config, 'timeout', 20 ),
			'fail_on_error' => (bool) self::cfg( $config, 'fail_on_error', true ),
			'redact'        => $redact,
		);

		$type = (string) self::cfg( $config, 'body_type', 'json' );
		if ( 'GET' !== $method ) {
			switch ( $type ) {
				case 'json':
					$opts['json'] = (object) $ctx->render_pairs( self::cfg( $config, 'body', array() ), true, true );
					break;
				case 'form':
					$opts['body'] = $ctx->render_pairs( self::cfg( $config, 'body', array() ), false, false );
					break;
				case 'all':
					$opts['json'] = (object) \BRN\Webhooks\Template::public_fields( $ctx->data );
					break;
				case 'raw':
					$ct           = trim( (string) self::cfg( $config, 'content_type', 'application/json' ) );
					$is_json      = false !== stripos( $ct, 'json' );
					$opts['body'] = $ctx->render( self::cfg( $config, 'raw_body' ), $is_json ? 'json' : '' );
					$opts['headers']['Content-Type'] = '' !== $ct ? $ct : 'text/plain';
					break;
			}
		} elseif ( in_array( $type, array( 'json', 'form' ), true ) ) {
			// GET: fields go to the query string.
			$opts['query'] = array_merge( $opts['query'], $ctx->render_pairs( self::cfg( $config, 'body', array() ) ) );
		}

		$res = Http::request( $method, $url, $opts );
		return array(
			'status'  => $res['code'],
			'headers' => $res['headers'],
			'body'    => null !== $res['json'] ? $res['json'] : $res['body'],
		);
	}
}
