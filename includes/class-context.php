<?php
/**
 * Run context.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the trigger data and the outputs of previous steps for template rendering.
 */
class Context {

	/**
	 * Trigger data.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Step outputs by 1-based position.
	 *
	 * @var array
	 */
	public $steps = array();

	/**
	 * Flow.
	 *
	 * @var array
	 */
	public $flow = array();

	/**
	 * Run (log) id.
	 *
	 * @var int
	 */
	public $run_id = 0;

	/**
	 * Is a test run.
	 *
	 * @var bool
	 */
	public $is_test = false;

	/**
	 * Cached vars.
	 *
	 * @var array|null
	 */
	private $vars = null;

	/**
	 * Constructor.
	 *
	 * @param array $data    Data.
	 * @param array $flow    Flow.
	 * @param int   $run_id  Run id.
	 * @param bool  $is_test Test.
	 */
	public function __construct( array $data, array $flow, $run_id = 0, $is_test = false ) {
		$this->data    = $data;
		$this->flow    = $flow;
		$this->run_id  = (int) $run_id;
		$this->is_test = (bool) $is_test;
	}

	/**
	 * Current loop item ({{_loop.*}}), or null outside loops.
	 *
	 * @var array|null
	 */
	public $loop = null;

	/**
	 * Store a step output. Available as {{_steps.<node id>.x}} and, for older flows, by position
	 * ({{_steps.2.x}}).
	 *
	 * @param int|null $position 1-based position in the node list (null = none).
	 * @param mixed    $output   Output.
	 * @param string   $id       Node id.
	 */
	public function set_step( $position, $output, $id = '' ) {
		if ( null !== $position && $position > 0 ) {
			$this->steps[ (int) $position ] = $output;
		}
		if ( '' !== (string) $id ) {
			$this->steps[ (string) $id ] = $output;
		}
		$this->vars = null;
	}

	/**
	 * Enter / leave a loop iteration.
	 *
	 * @param array|null $loop {item, index, count, first, last} or null.
	 */
	public function set_loop( $loop ) {
		$this->loop = $loop;
		$this->vars = null;
	}

	/**
	 * Replace trigger data (e.g. after enrichment).
	 *
	 * @param array $data Data.
	 */
	public function set_data( array $data ) {
		$this->data = $data;
		$this->vars = null;
	}

	/**
	 * Variables for templates.
	 *
	 * @return array
	 */
	public function vars() {
		if ( null === $this->vars ) {
			$this->vars = array_merge(
				$this->data,
				array(
					'_steps' => $this->steps,
					'_site'  => array(
						'name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
						'url'         => home_url( '/' ),
						'admin_email' => get_option( 'admin_email' ),
					),
					'_flow'  => array(
						'id'   => isset( $this->flow['id'] ) ? (int) $this->flow['id'] : 0,
						'name' => isset( $this->flow['name'] ) ? $this->flow['name'] : '',
					),
					'_run'   => array(
						'id'      => $this->run_id,
						'is_test' => $this->is_test,
					),
					'_now'   => wp_date( 'c' ),
					'_date'  => wp_date( 'Y-m-d' ),
					'_time'  => wp_date( 'H:i:s' ),
					'_ts'    => time(),
				)
			);
			if ( null !== $this->loop ) {
				$this->vars['_loop'] = $this->loop;
			}
		}
		return $this->vars;
	}

	/**
	 * Render a template string.
	 *
	 * @param mixed  $tpl    Template.
	 * @param string $escape Escape mode.
	 * @return string
	 */
	public function render( $tpl, $escape = '' ) {
		return Template::render( $tpl, $this->vars(), $escape );
	}

	/**
	 * Render keeping the type for single placeholders.
	 *
	 * @param mixed $tpl Template.
	 * @return mixed
	 */
	public function value( $tpl ) {
		return Template::render_value( $tpl, $this->vars() );
	}

	/**
	 * Render a key/value list [{key, value}] into an associative array (dot keys become nested).
	 *
	 * @param mixed $rows   Rows.
	 * @param bool  $typed  Keep native types.
	 * @param bool  $nested Expand dot keys.
	 * @return array
	 */
	public function render_pairs( $rows, $typed = false, $nested = false ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = isset( $row['key'] ) ? trim( $this->render( $row['key'] ) ) : '';
			if ( '' === $key ) {
				continue;
			}
			$val = isset( $row['value'] ) ? ( $typed ? $this->value( $row['value'] ) : $this->render( $row['value'] ) ) : '';
			if ( $nested && false !== strpos( $key, '.' ) ) {
				$ref = &$out;
				foreach ( explode( '.', $key ) as $seg ) {
					if ( ! isset( $ref[ $seg ] ) || ! is_array( $ref[ $seg ] ) ) {
						$ref[ $seg ] = array();
					}
					$ref = &$ref[ $seg ];
				}
				$ref = $val;
				unset( $ref );
			} else {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}
}
