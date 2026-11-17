<?php
/**
 * Base for triggers, actions and connection types.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Integration base.
 *
 * Field schema (rendered generically by the admin app):
 *   key, type, label, help, placeholder, default, required, options [{value,label}],
 *   options_source (dynamic options via get_options), depends_on (keys), show_if {key: value|[values]},
 *   template (bool, shows the field picker), rows, secret, mono, columns_source (mapping),
 *   button_label (action_button), fixed_keys (mapping without load button).
 *
 * Types: text, url, email, number, password, textarea, code, select, toggle, choice, keyvalue,
 *        mapping, heading, info, endpoint, action_button.
 */
abstract class Integration {

	/**
	 * Unique id.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Short description.
	 *
	 * @return string
	 */
	public function description() {
		return '';
	}

	/**
	 * Group / app name.
	 *
	 * @return string
	 */
	public function group() {
		return '';
	}

	/**
	 * Icon key (see assets/js/lib.js APP_ICONS).
	 *
	 * @return string
	 */
	public function icon() {
		return $this->id();
	}

	/**
	 * Is the required plugin / PHP extension available?
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Why not available.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return '';
	}

	/**
	 * Config fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array();
	}

	/**
	 * Keys of fields holding secrets.
	 *
	 * @return string[]
	 */
	public function secret_keys() {
		$keys = array();
		foreach ( $this->fields() as $f ) {
			if ( ! empty( $f['secret'] ) && ! empty( $f['key'] ) ) {
				$keys[] = $f['key'];
			}
		}
		return $keys;
	}

	/**
	 * Dynamic options / helper calls from the UI.
	 *
	 * @param string     $source     Source name.
	 * @param array      $config     Current config.
	 * @param array|null $connection Decrypted connection.
	 * @return array|\WP_Error List of {value,label} or {message} / {columns}.
	 */
	public function get_options( $source, array $config, $connection = null ) {
		return array();
	}

	/**
	 * For the admin app.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'          => $this->id(),
			'label'       => $this->label(),
			'description' => $this->description(),
			'group'       => $this->group(),
			'icon'        => $this->icon(),
			'available'   => $this->is_available(),
			'reason'      => $this->is_available() ? '' : $this->unavailable_reason(),
			'fields'      => $this->fields(),
		);
	}

	/**
	 * Plain options list helper.
	 *
	 * @param array $map value => label.
	 * @return array
	 */
	protected static function options( array $map ) {
		$out = array();
		foreach ( $map as $v => $l ) {
			$out[] = array(
				'value' => (string) $v,
				'label' => (string) $l,
			);
		}
		return $out;
	}
}
