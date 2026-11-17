<?php
/**
 * Email action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Send an email with wp_mail (works with any SMTP plugin).
 */
class Email extends Action {

	/**
	 * Last wp_mail error.
	 *
	 * @var string
	 */
	private $mail_error = '';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'email';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Send email', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Email the data to your team or a customer confirmation to the lead.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Messages', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'to',
				'type'        => 'text',
				'label'       => __( 'To', 'brn-webhooks' ),
				'placeholder' => 'team@example.com, {{email}}',
				'template'    => true,
				'required'    => true,
			),
			array(
				'key'      => 'subject',
				'type'     => 'text',
				'label'    => __( 'Subject', 'brn-webhooks' ),
				'default'  => __( 'New submission: {{_meta.form_name}}', 'brn-webhooks' ),
				'template' => true,
			),
			array(
				'key'      => 'body',
				'type'     => 'textarea',
				'rows'     => 8,
				'label'    => __( 'Message', 'brn-webhooks' ),
				'default'  => "{{_all_table}}",
				'template' => true,
				'help'     => __( 'HTML is allowed. {{_all_table}} inserts every field as a table.', 'brn-webhooks' ),
			),
			array(
				'key'   => '_h_more',
				'type'  => 'heading',
				'label' => __( 'More options', 'brn-webhooks' ),
				'collapsible' => true,
			),
			array(
				'key'      => 'reply_to',
				'type'     => 'text',
				'label'    => __( 'Reply-To', 'brn-webhooks' ),
				'template' => true,
				'width'    => 'half',
			),
			array(
				'key'      => 'from_name',
				'type'     => 'text',
				'label'    => __( 'From name', 'brn-webhooks' ),
				'template' => true,
				'width'    => 'half',
			),
			array(
				'key'      => 'cc',
				'type'     => 'text',
				'label'    => 'CC',
				'template' => true,
				'width'    => 'half',
			),
			array(
				'key'      => 'bcc',
				'type'     => 'text',
				'label'    => 'BCC',
				'template' => true,
				'width'    => 'half',
			),
		);
	}

	/**
	 * Valid addresses from a comma list.
	 *
	 * @param string $list List.
	 * @return string[]
	 */
	private static function addresses( $list ) {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', (string) $list ) as $a ) {
			$a = sanitize_email( $a );
			if ( $a && is_email( $a ) ) {
				$out[] = $a;
			}
		}
		return array_values( array_unique( $out ) );
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
		$to = self::addresses( $ctx->render( self::cfg( $config, 'to' ) ) );
		if ( ! $to ) {
			throw new Action_Error( __( 'No valid recipient address.', 'brn-webhooks' ), false );
		}
		$subject = wp_strip_all_tags( $ctx->render( self::cfg( $config, 'subject' ) ) );
		$tpl = (string) self::cfg( $config, 'body', '{{_all_table}}' );
		// Values from the trigger are escaped so submitted data can't inject HTML / links.
		$body = false === strpos( preg_replace( \BRN\Webhooks\Template::PATTERN, '', $tpl ), '<' ) && false === strpos( $tpl, '_all_table' )
			? nl2br( $ctx->render( $tpl, 'html' ) )
			: $ctx->render( $tpl, 'html' );
		$body    = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.6;color:#1f2330">' . wp_kses_post( $body ) . '</div>';
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = self::addresses( $ctx->render( self::cfg( $config, 'reply_to' ) ) );
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply[0];
		}
		foreach ( self::addresses( $ctx->render( self::cfg( $config, 'cc' ) ) ) as $cc ) {
			$headers[] = 'Cc: ' . $cc;
		}
		foreach ( self::addresses( $ctx->render( self::cfg( $config, 'bcc' ) ) ) as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}
		$from_name = trim( str_replace( array( "\r", "\n", '<', '>', '"' ), '', $ctx->render( self::cfg( $config, 'from_name' ) ) ) );
		$name_cb   = null;
		if ( '' !== $from_name ) {
			$name_cb = static function () use ( $from_name ) {
				return $from_name;
			};
			add_filter( 'wp_mail_from_name', $name_cb, 999 );
		}

		$this->mail_error = '';
		$err_cb           = function ( $error ) {
			$this->mail_error = is_wp_error( $error ) ? $error->get_error_message() : 'wp_mail failed';
		};
		add_action( 'wp_mail_failed', $err_cb );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $err_cb );
		if ( $name_cb ) {
			remove_filter( 'wp_mail_from_name', $name_cb, 999 );
		}
		if ( ! $sent ) {
			/* translators: %s: error */
			throw new Action_Error( sprintf( __( 'Email could not be sent: %s', 'brn-webhooks' ), $this->mail_error ? $this->mail_error : __( 'check the site\'s mail / SMTP settings', 'brn-webhooks' ) ) );
		}
		return array(
			'sent'    => true,
			'to'      => $to,
			'subject' => $subject,
		);
	}
}
