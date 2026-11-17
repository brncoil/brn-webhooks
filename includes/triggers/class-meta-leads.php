<?php
/**
 * Facebook / Instagram Lead Ads trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connections as Conns;
use BRN\Webhooks\Connections\Meta;
use BRN\Webhooks\Engine;
use BRN\Webhooks\Flows;
use BRN\Webhooks\Incoming;
use BRN\Webhooks\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * Receives Meta "leadgen" webhooks and fetches the full lead from the Graph API.
 */
class Meta_Leads extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'meta_leads';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Facebook Lead Ads — new lead', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs instantly when someone submits a Facebook / Instagram lead form.', 'brn-webhooks' );
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
	 * Endpoint.
	 *
	 * @return bool
	 */
	public function has_endpoint() {
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
				'key'             => 'connection_id',
				'type'            => 'connection',
				'connection_type' => 'meta',
				'label'           => __( 'Meta connection', 'brn-webhooks' ),
				'help'            => __( 'Needs a Page access token (or system user token) with leads_retrieval, pages_manage_metadata and pages_show_list permissions.', 'brn-webhooks' ),
			),
			array(
				'key'            => 'page_id',
				'type'           => 'select',
				'label'          => __( 'Facebook Page', 'brn-webhooks' ),
				'options_source' => 'pages',
				'depends_on'     => array( 'connection_id' ),
				'allow_custom'   => true,
				'options'        => array(
					array(
						'value' => '',
						'label' => __( 'Any page', 'brn-webhooks' ),
					),
				),
			),
			array(
				'key'            => 'form_id',
				'type'           => 'select',
				'label'          => __( 'Lead form', 'brn-webhooks' ),
				'options_source' => 'forms',
				'depends_on'     => array( 'connection_id', 'page_id' ),
				'allow_custom'   => true,
				'options'        => array(
					array(
						'value' => '',
						'label' => __( 'Any form', 'brn-webhooks' ),
					),
				),
			),
			array(
				'key'          => '_subscribe',
				'type'         => 'action_button',
				'source'       => 'subscribe',
				'button_label' => __( 'Subscribe page to lead events', 'brn-webhooks' ),
				'help'         => __( 'Connects the page to your Meta app so new leads are sent here. Run once per page.', 'brn-webhooks' ),
			),
			array(
				'key'   => '_h_webhook',
				'type'  => 'heading',
				'label' => __( 'Meta app webhook settings', 'brn-webhooks' ),
			),
			array(
				'key'   => '_endpoint',
				'type'  => 'endpoint',
				'label' => __( 'Callback URL', 'brn-webhooks' ),
				'help'  => __( 'In your Meta app → Webhooks → Page, paste this Callback URL and the Verify token below, then subscribe to the "leadgen" field.', 'brn-webhooks' ),
			),
			array(
				'key'      => 'verify_token',
				'type'     => 'password',
				'label'    => __( 'Verify token', 'brn-webhooks' ),
				'secret'   => true,
				'generate' => true,
				'reveal'   => true,
			),
		);
	}

	/**
	 * Matches is handled inside handle_request.
	 *
	 * @param array $config Config.
	 * @param array $event  Event.
	 * @return bool
	 */
	public function matches( array $config, array $event ) {
		return true;
	}

	/**
	 * Handle Meta's calls.
	 *
	 * @param array            $flow    Flow.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_request( array $flow, \WP_REST_Request $request ) {
		$c = $flow['trigger_config'];

		// Verification handshake (hub.mode → hub_mode in PHP).
		if ( 'GET' === $request->get_method() ) {
			$q        = $request->get_query_params();
			$mode     = isset( $q['hub_mode'] ) ? (string) $q['hub_mode'] : ( isset( $q['hub.mode'] ) ? (string) $q['hub.mode'] : '' );
			$token    = isset( $q['hub_verify_token'] ) ? (string) $q['hub_verify_token'] : ( isset( $q['hub.verify_token'] ) ? (string) $q['hub.verify_token'] : '' );
			$chall    = isset( $q['hub_challenge'] ) ? (string) $q['hub_challenge'] : ( isset( $q['hub.challenge'] ) ? (string) $q['hub.challenge'] : '' );
			$expected = isset( $c['verify_token'] ) ? (string) $c['verify_token'] : '';
			if ( 'subscribe' === $mode && '' !== $expected && hash_equals( $expected, $token ) ) {
				Incoming::raw( 200, preg_replace( '/[^A-Za-z0-9_\-]/', '', $chall ), 'text/plain' );
			}
			return Incoming::error( 403, 'forbidden', 'Verification failed.' );
		}
		if ( 'POST' !== $request->get_method() ) {
			return Incoming::error( 405, 'method_not_allowed', 'Method not allowed.' );
		}

		$conn = ! empty( $c['connection_id'] ) ? Conns::get( (int) $c['connection_id'] ) : null;
		$app_secret = $conn ? (string) ( isset( $conn['credentials']['app_secret'] ) ? $conn['credentials']['app_secret'] : '' ) : '';
		if ( '' === $app_secret ) {
			return Incoming::error( 401, 'unauthorized', 'Signature verification is not configured: add the App secret to the Meta connection.' );
		}
		$sig      = (string) $request->get_header( 'x_hub_signature_256' );
		$expected = 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $app_secret );
		if ( '' === $sig || ! hash_equals( $expected, $sig ) ) {
			return Incoming::error( 401, 'unauthorized', 'Invalid signature.' );
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) || empty( $body['entry'] ) ) {
			return new \WP_REST_Response( 'EVENT_RECEIVED', 200 );
		}
		$listening = Flows::is_listening( $flow['id'] );
		foreach ( (array) $body['entry'] as $entry ) {
			foreach ( isset( $entry['changes'] ) ? (array) $entry['changes'] : array() as $change ) {
				if ( ! isset( $change['field'] ) || 'leadgen' !== $change['field'] || empty( $change['value']['leadgen_id'] ) ) {
					continue;
				}
				$v = $change['value'];
				if ( ! ctype_digit( (string) $v['leadgen_id'] ) ) {
					continue;
				}
				if ( ! empty( $c['page_id'] ) && ( ! isset( $v['page_id'] ) || (string) $v['page_id'] !== (string) $c['page_id'] ) ) {
					continue;
				}
				if ( ! empty( $c['form_id'] ) && ( ! isset( $v['form_id'] ) || (string) $v['form_id'] !== (string) $c['form_id'] ) ) {
					continue;
				}
				$data = array(
					'leadgen_id'   => (string) $v['leadgen_id'],
					'page_id'      => isset( $v['page_id'] ) ? (string) $v['page_id'] : '',
					'form_id'      => isset( $v['form_id'] ) ? (string) $v['form_id'] : '',
					'ad_id'        => isset( $v['ad_id'] ) ? (string) $v['ad_id'] : '',
					'adgroup_id'   => isset( $v['adgroup_id'] ) ? (string) $v['adgroup_id'] : '',
					'created_time' => isset( $v['created_time'] ) ? gmdate( 'c', (int) $v['created_time'] ) : gmdate( 'c' ),
				);
				if ( $listening || empty( $flow['sample']['data'] ) ) {
					try {
						Flows::save_sample( $flow['id'], $this->prepare( $data, $flow ) );
					} catch ( \Throwable $e ) {
						Flows::save_sample( $flow['id'], $data + array( '_error' => $e->getMessage() ) );
					}
				}
				if ( 'active' === $flow['status'] ) {
					Engine::dispatch( $flow, $data, array( 'source' => 'meta_leads' ) );
				}
			}
		}
		return new \WP_REST_Response( 'EVENT_RECEIVED', 200 );
	}

	/**
	 * Fetch the lead details at run time.
	 *
	 * @param array $data Data.
	 * @param array $flow Flow.
	 * @return array
	 * @throws Action_Error When the lead cannot be fetched.
	 */
	public function prepare( array $data, array $flow ) {
		if ( empty( $data['leadgen_id'] ) || ! empty( $data['_lead_fetched'] ) ) {
			return $data;
		}
		if ( ! ctype_digit( (string) $data['leadgen_id'] ) ) {
			throw new Action_Error( 'Invalid lead id.', false );
		}
		$conn = ! empty( $flow['trigger_config']['connection_id'] ) ? Conns::get( (int) $flow['trigger_config']['connection_id'] ) : null;
		if ( ! $conn ) {
			throw new Action_Error( __( 'Select a Meta connection in the trigger to fetch lead details.', 'brn-webhooks' ), false );
		}
		$token = Meta::page_token( $conn['credentials'], isset( $data['page_id'] ) ? $data['page_id'] : '' );
		try {
			$lead = Meta::graph( 'GET', $data['leadgen_id'], $token, array( 'fields' => 'id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,is_organic,platform' ) );
		} catch ( Action_Error $e ) {
			// Ad fields need ads_read; fall back to the basics.
			$lead = Meta::graph( 'GET', $data['leadgen_id'], $token, array( 'fields' => 'id,created_time,field_data,ad_id,form_id,is_organic,platform' ) );
		}
		$fields = array();
		foreach ( isset( $lead['field_data'] ) ? (array) $lead['field_data'] : array() as $f ) {
			if ( empty( $f['name'] ) ) {
				continue;
			}
			$values              = isset( $f['values'] ) ? (array) $f['values'] : array();
			$fields[ $f['name'] ] = 1 === count( $values ) ? $values[0] : $values;
		}
		unset( $lead['field_data'] );
		$out                  = array_merge( $fields, $data );
		$out['_lead']         = $lead;
		$out['_lead_fetched'] = true;
		return $out;
	}

	/**
	 * Dynamic options and helpers.
	 *
	 * @param string     $source     Source.
	 * @param array      $config     Config.
	 * @param array|null $connection Connection.
	 * @return array|\WP_Error
	 */
	public function get_options( $source, array $config, $connection = null ) {
		if ( ! $connection && ! empty( $config['connection_id'] ) ) {
			$connection = Conns::get( (int) $config['connection_id'] );
		}
		if ( ! $connection ) {
			return 'subscribe' === $source ? new \WP_Error( 'brnwh_no_conn', __( 'Select a Meta connection first.', 'brn-webhooks' ) ) : array();
		}
		$creds = $connection['credentials'];
		try {
			switch ( $source ) {
				case 'pages':
					$out = array(
						array(
							'value' => '',
							'label' => __( 'Any page', 'brn-webhooks' ),
						),
					);
					try {
						$res = Meta::graph( 'GET', 'me/accounts', $creds['access_token'], array( 'fields' => 'id,name', 'limit' => 100 ) );
						foreach ( isset( $res['data'] ) ? $res['data'] : array() as $p ) {
							$out[] = array(
								'value' => (string) $p['id'],
								'label' => $p['name'] . ' (' . $p['id'] . ')',
							);
						}
					} catch ( Action_Error $e ) {
						// A Page token: /me is the page itself.
						$me    = Meta::graph( 'GET', 'me', $creds['access_token'], array( 'fields' => 'id,name' ) );
						$out[] = array(
							'value' => (string) $me['id'],
							'label' => $me['name'] . ' (' . $me['id'] . ')',
						);
					}
					return $out;

				case 'forms':
					$out = array(
						array(
							'value' => '',
							'label' => __( 'Any form', 'brn-webhooks' ),
						),
					);
					if ( empty( $config['page_id'] ) ) {
						return $out;
					}
					$token = Meta::page_token( $creds, $config['page_id'] );
					$res   = Meta::graph( 'GET', $config['page_id'] . '/leadgen_forms', $token, array( 'fields' => 'id,name,status', 'limit' => 200 ) );
					foreach ( isset( $res['data'] ) ? $res['data'] : array() as $f ) {
						$out[] = array(
							'value' => (string) $f['id'],
							'label' => $f['name'] . ( isset( $f['status'] ) && 'ACTIVE' !== $f['status'] ? ' · ' . strtolower( $f['status'] ) : '' ),
						);
					}
					return $out;

				case 'subscribe':
					if ( empty( $config['page_id'] ) ) {
						return new \WP_Error( 'brnwh_no_page', __( 'Choose a specific page first.', 'brn-webhooks' ) );
					}
					$token = Meta::page_token( $creds, $config['page_id'] );
					Meta::graph( 'POST', $config['page_id'] . '/subscribed_apps', $token, array( 'subscribed_fields' => 'leadgen' ) );
					return array( 'message' => __( 'The page is subscribed. New leads will be sent to this flow.', 'brn-webhooks' ) );
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'brnwh_meta', $e->getMessage() );
		}
		return array();
	}

	/**
	 * Known fields.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	public function known_fields( array $config ) {
		return array(
			'full_name'          => __( 'Full name', 'brn-webhooks' ),
			'email'              => __( 'Email', 'brn-webhooks' ),
			'phone_number'       => __( 'Phone', 'brn-webhooks' ),
			'leadgen_id'         => __( 'Lead ID', 'brn-webhooks' ),
			'form_id'            => __( 'Form ID', 'brn-webhooks' ),
			'_lead.ad_name'      => __( 'Ad name', 'brn-webhooks' ),
			'_lead.campaign_name' => __( 'Campaign name', 'brn-webhooks' ),
			'_lead.platform'     => __( 'Platform (fb / ig)', 'brn-webhooks' ),
		);
	}
}
