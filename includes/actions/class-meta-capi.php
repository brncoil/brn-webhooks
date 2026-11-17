<?php
/**
 * Meta Conversions API action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connections\Meta;
use BRN\Webhooks\Context;
use BRN\Webhooks\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Send a server-side event (Lead, Purchase…) to Meta. Personal data is normalised and SHA-256 hashed.
 */
class Meta_Capi extends Action {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'meta_capi';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Meta Conversions API — send event', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send Lead / Purchase / custom events to Meta server-side for better ad attribution.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'Meta';
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'meta';
	}

	/**
	 * Connection.
	 *
	 * @return string
	 */
	public function connection_type() {
		return 'meta';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		$user = array(
			'email'      => array( __( 'Email', 'brn-webhooks' ), '{{email}}' ),
			'phone'      => array( __( 'Phone', 'brn-webhooks' ), '{{phone}}' ),
			'first_name' => array( __( 'First name', 'brn-webhooks' ), '' ),
			'last_name'  => array( __( 'Last name', 'brn-webhooks' ), '' ),
			'city'       => array( __( 'City', 'brn-webhooks' ), '' ),
			'zip'        => array( __( 'ZIP / postal code', 'brn-webhooks' ), '' ),
			'country'    => array( __( 'Country (2 letters)', 'brn-webhooks' ), 'il' ),
			'external_id' => array( __( 'External ID', 'brn-webhooks' ), '' ),
			'client_ip_address' => array( __( 'Client IP', 'brn-webhooks' ), '{{_meta.ip}}' ),
			'client_user_agent' => array( __( 'User agent', 'brn-webhooks' ), '{{_meta.user_agent}}' ),
			'fbp'        => array( 'fbp (_fbp cookie)', '{{_meta.fbp}}' ),
			'fbc'        => array( 'fbc (_fbc cookie / fbclid)', '{{_meta.fbc}}' ),
			'lead_id'    => array( __( 'Meta lead ID (CRM events)', 'brn-webhooks' ), '' ),
		);
		$fields = array(
			array(
				'key'          => 'event_name',
				'type'         => 'select',
				'label'        => __( 'Event', 'brn-webhooks' ),
				'default'      => 'Lead',
				'allow_custom' => true,
				'options'      => self::options(
					array(
						'Lead'                 => 'Lead',
						'CompleteRegistration' => 'CompleteRegistration',
						'Contact'              => 'Contact',
						'SubmitApplication'    => 'SubmitApplication',
						'Schedule'             => 'Schedule',
						'Purchase'             => 'Purchase',
						'InitiateCheckout'     => 'InitiateCheckout',
						'AddToCart'            => 'AddToCart',
						'Subscribe'            => 'Subscribe',
						'StartTrial'           => 'StartTrial',
					)
				),
			),
			array(
				'key'     => 'action_source',
				'type'    => 'select',
				'label'   => __( 'Action source', 'brn-webhooks' ),
				'default' => 'website',
				'options' => self::options(
					array(
						'website'           => 'website',
						'system_generated'  => 'system_generated',
						'crm'               => 'crm',
						'phone_call'        => 'phone_call',
						'chat'              => 'chat',
						'email'             => 'email',
						'physical_store'    => 'physical_store',
						'other'             => 'other',
					)
				),
			),
			array(
				'key'      => 'event_source_url',
				'type'     => 'url',
				'label'    => __( 'Event source URL', 'brn-webhooks' ),
				'default'  => '{{_meta.page_url}}',
				'template' => true,
			),
			array(
				'key'      => 'event_id',
				'type'     => 'text',
				'label'    => __( 'Event ID (deduplication)', 'brn-webhooks' ),
				'default'  => 'brn-{{_run.id}}',
				'template' => true,
				'help'     => __( 'If the browser pixel sends the same event, use the same event ID in both so Meta counts it once.', 'brn-webhooks' ),
			),
			array(
				'key'   => '_h_user',
				'type'  => 'heading',
				'label' => __( 'Customer information (hashed automatically)', 'brn-webhooks' ),
			),
		);
		foreach ( $user as $k => $def ) {
			$fields[] = array(
				'key'      => 'u_' . $k,
				'type'     => 'text',
				'label'    => $def[0],
				'default'  => $def[1],
				'template' => true,
				'width'    => 'half',
			);
		}
		$fields[] = array(
			'key'     => 'phone_cc',
			'type'    => 'text',
			'label'   => __( 'Default phone country code', 'brn-webhooks' ),
			'default' => '972',
			'width'   => 'half',
			'help'    => __( 'Local numbers like 050-1234567 become 972501234567.', 'brn-webhooks' ),
		);
		$fields[] = array(
			'key'   => '_h_custom',
			'type'  => 'heading',
			'label' => __( 'Event data', 'brn-webhooks' ),
		);
		$fields[] = array(
			'key'      => 'value',
			'type'     => 'text',
			'label'    => __( 'Value', 'brn-webhooks' ),
			'template' => true,
			'width'    => 'half',
		);
		$fields[] = array(
			'key'      => 'currency',
			'type'     => 'text',
			'label'    => __( 'Currency', 'brn-webhooks' ),
			'default'  => 'ILS',
			'template' => true,
			'width'    => 'half',
		);
		$fields[] = array(
			'key'             => 'custom_data',
			'type'            => 'keyvalue',
			'label'           => __( 'More custom data', 'brn-webhooks' ),
			'key_placeholder' => 'content_name',
			'template'        => true,
		);
		$fields[] = array(
			'key'         => 'test_event_code',
			'type'        => 'text',
			'label'       => __( 'Test event code (optional)', 'brn-webhooks' ),
			'placeholder' => 'TEST12345',
			'help'        => __( 'From Events Manager → Test events. Remove it when you go live.', 'brn-webhooks' ),
		);
		$fields[] = array(
			'key'         => 'pixel_id',
			'type'        => 'text',
			'label'       => __( 'Pixel ID override (optional)', 'brn-webhooks' ),
			'placeholder' => __( 'Uses the connection\'s pixel', 'brn-webhooks' ),
		);
		return $fields;
	}

	/**
	 * Normalise + hash a value per Meta's rules.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Value.
	 * @param string $cc    Country code.
	 * @return string|null
	 */
	public static function normalize( $key, $value, $cc = '972' ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
		switch ( $key ) {
			case 'em':
				$v = $lower;
				break;
			case 'ph':
				$v = Template::phone_international( $v, $cc );
				break;
			case 'fn':
			case 'ln':
				$v = preg_replace( '/[\s\p{P}\d]+/u', '', $lower );
				break;
			case 'ct':
			case 'st':
				$v = preg_replace( '/[^\p{L}]+/u', '', $lower );
				break;
			case 'zp':
				$v = preg_replace( '/\s+/', '', $lower );
				break;
			case 'country':
				$v = substr( preg_replace( '/[^a-z]/', '', $lower ), 0, 2 );
				break;
			case 'external_id':
				$v = $lower;
				break;
		}
		if ( '' === $v ) {
			return null;
		}
		// Already hashed values (64 hex chars) are passed through.
		return preg_match( '/^[a-f0-9]{64}$/', $v ) ? $v : hash( 'sha256', $v );
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
		$creds = $this->creds( $connection );
		$pixel = trim( (string) self::cfg( $config, 'pixel_id' ) );
		$pixel = '' !== $pixel ? $pixel : ( isset( $creds['pixel_id'] ) ? trim( $creds['pixel_id'] ) : '' );
		if ( '' === $pixel ) {
			throw new Action_Error( __( 'No pixel ID: add it to the Meta connection or this step.', 'brn-webhooks' ), false );
		}
		$cc  = preg_replace( '/\D/', '', (string) self::cfg( $config, 'phone_cc', '972' ) );
		$r   = static function ( $k ) use ( $config, $ctx ) {
			return trim( $ctx->render( isset( $config[ $k ] ) ? $config[ $k ] : '' ) );
		};
		$map = array(
			'em'          => 'u_email',
			'ph'          => 'u_phone',
			'fn'          => 'u_first_name',
			'ln'          => 'u_last_name',
			'ct'          => 'u_city',
			'zp'          => 'u_zip',
			'country'     => 'u_country',
			'external_id' => 'u_external_id',
		);
		$user = array();
		foreach ( $map as $meta_key => $cfg_key ) {
			$h = self::normalize( $meta_key, $r( $cfg_key ), $cc );
			if ( null !== $h ) {
				$user[ $meta_key ] = array( $h );
			}
		}
		foreach ( array( 'client_ip_address', 'client_user_agent', 'fbp', 'fbc', 'lead_id' ) as $plain ) {
			$v = $r( 'u_' . $plain );
			if ( '' !== $v ) {
				$user[ $plain ] = 'lead_id' === $plain ? (int) $v : $v;
			}
		}
		if ( ! $user ) {
			throw new Action_Error( __( 'No customer information to send (Meta needs at least one identifier such as email or phone).', 'brn-webhooks' ), false );
		}

		$source = (string) self::cfg( $config, 'action_source', 'website' );
		$event  = array(
			'event_name'    => '' !== $r( 'event_name' ) ? $r( 'event_name' ) : 'Lead',
			'event_time'    => time(),
			'action_source' => $source,
			'user_data'     => $user,
		);
		$event_id = $r( 'event_id' );
		if ( '' !== $event_id ) {
			$event['event_id'] = $event_id;
		}
		$url = $r( 'event_source_url' );
		if ( '' !== $url ) {
			$event['event_source_url'] = $url;
		} elseif ( 'website' === $source ) {
			$event['event_source_url'] = home_url( '/' );
		}
		$custom = $ctx->render_pairs( self::cfg( $config, 'custom_data', array() ), true, true );
		$value  = $r( 'value' );
		if ( '' !== $value ) {
			$custom['value']    = (float) preg_replace( '/[^\d.\-]/', '', $value );
			$custom['currency'] = '' !== $r( 'currency' ) ? strtoupper( $r( 'currency' ) ) : 'ILS';
		}
		if ( $custom ) {
			$event['custom_data'] = $custom;
		}

		$payload = array( 'data' => array( $event ) );
		$test    = trim( (string) self::cfg( $config, 'test_event_code' ) );
		if ( '' !== $test ) {
			$payload['test_event_code'] = $test;
		}
		$res = Meta::graph( 'POST', $pixel . '/events', $creds['access_token'], $payload );
		return array(
			'events_received' => isset( $res['events_received'] ) ? $res['events_received'] : null,
			'fbtrace_id'      => isset( $res['fbtrace_id'] ) ? $res['fbtrace_id'] : null,
			'event_name'      => $event['event_name'],
			'event_id'        => $event_id,
		);
	}
}
