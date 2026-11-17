<?php
/**
 * Post published trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Flows;
use BRN\Webhooks\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * A post / page / custom post type was published.
 */
class Wp_Post extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wp_post';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Content published', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when a post, page or custom post type is published for the first time.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'WordPress';
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'wordpress';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		$types = array( '' => __( 'Any public type', 'brn-webhooks' ) );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$types[ $pt->name ] = $pt->labels->singular_name;
		}
		return array(
			array(
				'key'     => 'post_type',
				'type'    => 'select',
				'label'   => __( 'Content type', 'brn-webhooks' ),
				'default' => 'post',
				'options' => self::options( $types ),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
	}

	/**
	 * Matches type.
	 *
	 * @param array $config Config.
	 * @param array $event  Event.
	 * @return bool
	 */
	public function matches( array $config, array $event ) {
		return empty( $config['post_type'] ) || ( isset( $event['post_type'] ) && $event['post_type'] === $config['post_type'] );
	}

	/**
	 * Handler.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public function on_transition( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || ! ( $post instanceof \WP_Post ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || ! $pto->public ) {
			return;
		}
		if ( ! Flows::has_active( $this->id() ) && ! Flows::listening_ids() ) {
			return;
		}
		$thumb = get_the_post_thumbnail_url( $post, 'full' );
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$names = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && $names ) {
				$terms[ $tax ] = implode( ', ', $names );
			}
		}
		$data = array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'title'     => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'content'   => wp_strip_all_tags( $post->post_content ),
			'author'    => get_the_author_meta( 'display_name', $post->post_author ),
			'image'     => $thumb ? $thumb : '',
			'terms'     => $terms,
			'date'      => get_post_time( 'c', true, $post ),
		);
		$this->fire( $data, array( 'post_type' => $post->post_type ) );
	}
}
