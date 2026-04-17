<?php

namespace IdeoLogix\DigitalLicenseManagerUpdaterWP\Core;

use IdeoLogix\DigitalLicenseManagerUpdaterWP\Enums\ReleaseChannel;

/**
 * Release-channel selector: owns the dropdown rendering and the
 * backend for AJAX get/set. Kept separate from Activator because
 * channel selection is orthogonal to license activation and the
 * Activator's monolithic form has no room for non-license concerns.
 */
class ChannelSelector {

	/**
	 * @var Configuration
	 */
	protected $configuration;

	/**
	 * @param Configuration $configuration
	 */
	public function __construct( $configuration ) {
		$this->configuration = $configuration;
	}

	/**
	 * Channels allowed in the UI, mapped to their display label.
	 *
	 * @return array<string,string>
	 */
	public function getOptions() {
		$labels    = $this->configuration->getChannelLabels();
		$available = $this->configuration->getAvailableChannels();
		$options   = array();
		foreach ( $available as $channel ) {
			$options[ $channel ] = isset( $labels[ $channel ] ) ? $labels[ $channel ] : ucfirst( $channel );
		}

		return $options;
	}

	/**
	 * Snapshot used by consumer-side AJAX handlers (or anyone who wants to
	 * render an up-to-date channel dropdown).
	 *
	 * @return array{stored:string|false,resolved:string,default:string,available:array<string,string>,locked:bool}
	 */
	public function getStatus() {
		$entity = $this->configuration->getEntity();

		return array(
			'stored'    => $entity ? $entity->getStoredChannel() : false,
			'resolved'  => $this->configuration->getChannel(),
			'default'   => $this->configuration->getChannelDefault(),
			'available' => $this->getOptions(),
			'locked'    => $this->configuration->isChannelLocked(),
		);
	}

	/**
	 * Persist a channel choice. Returns a structured result so callers can
	 * surface errors without guessing.
	 *
	 * @param string $channel
	 *
	 * @return array{success:bool,message:string,channel:string|null}
	 */
	public function setChannel( $channel ) {
		$channel = is_string( $channel ) ? trim( $channel ) : '';

		if ( ! ReleaseChannel::isValid( $channel ) ) {
			return array(
				'success' => false,
				'message' => 'Invalid release channel.',
				'channel' => null,
			);
		}

		$available = $this->configuration->getAvailableChannels();
		if ( ! in_array( $channel, $available, true ) ) {
			return array(
				'success' => false,
				'message' => 'Requested channel is not available for this install.',
				'channel' => null,
			);
		}

		if ( $this->configuration->isChannelLocked() ) {
			return array(
				'success' => false,
				'message' => 'Release channel is locked by the site configuration.',
				'channel' => null,
			);
		}

		$entity = $this->configuration->getEntity();
		$old    = $entity ? $entity->getStoredChannel() : false;

		$ok = $entity && $entity->setStoredChannel( $channel );
		if ( ! $ok ) {
			return array(
				'success' => false,
				'message' => 'Failed to persist channel preference.',
				'channel' => null,
			);
		}

		if ( $old !== $channel ) {
			$this->configuration->clearCache();
			/**
			 * Fires after the channel preference changes.
			 *
			 * @param string        $new           New channel value.
			 * @param string|false  $old           Previous stored value (false if unset).
			 * @param Configuration $configuration
			 */
			do_action( 'dlm_updater_channel_changed', $channel, $old, $this->configuration );
		}

		return array(
			'success' => true,
			'message' => 'Channel updated.',
			'channel' => $channel,
		);
	}

	/**
	 * Render a bare <select> (no form, no nonce, no submit). Consumers that
	 * have their own settings form/AJAX wiring embed this and bind their own
	 * change handler.
	 *
	 * @param array $args {
	 *     @type string $name       Form name. Default 'dlm_channel'.
	 *     @type string $id         DOM id. Default 'dlm-updater-channel'.
	 *     @type string $class      CSS class.
	 *     @type string $selected   Preselected value. Default = resolved channel.
	 *     @type array  $attrs      Associative array of extra HTML attributes.
	 *     @type bool   $echo       True echoes, false returns. Default true.
	 * }
	 *
	 * @return string|void
	 */
	public function renderField( $args = array() ) {
		$defaults = array(
			'name'     => 'dlm_channel',
			'id'       => 'dlm-updater-channel',
			'class'    => '',
			'selected' => null,
			'attrs'    => array(),
			'echo'     => true,
		);
		$args     = array_merge( $defaults, is_array( $args ) ? $args : array() );

		$selected = null !== $args['selected'] ? (string) $args['selected'] : $this->configuration->getChannel();
		$options  = $this->getOptions();
		$locked   = $this->configuration->isChannelLocked();

		$attrs = array(
			'name' => $args['name'],
			'id'   => $args['id'],
		);
		if ( ! empty( $args['class'] ) ) {
			$attrs['class'] = $args['class'];
		}
		if ( $locked ) {
			$attrs['disabled'] = 'disabled';
		}
		if ( is_array( $args['attrs'] ) ) {
			foreach ( $args['attrs'] as $k => $v ) {
				$attrs[ $k ] = $v;
			}
		}

		$attrHtml = '';
		foreach ( $attrs as $k => $v ) {
			$attrHtml .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}

		$html = '<select' . $attrHtml . '>';
		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
		$html .= '</select>';

		if ( $args['echo'] ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		return $html;
	}

	/**
	 * @param array $args
	 *
	 * @return string
	 */
	public function renderFieldHtml( $args = array() ) {
		$args         = is_array( $args ) ? $args : array();
		$args['echo'] = false;

		return $this->renderField( $args );
	}

	/**
	 * Default AJAX GET handler. Consumers that want their own nonce scheme
	 * should bypass this and call getStatus() directly from their own hook.
	 *
	 * @return void
	 */
	public function handleAjaxGet() {
		$nonceAction = sprintf( '%s_updater_channel', $this->configuration->getPrefix() );
		check_ajax_referer( $nonceAction );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		wp_send_json_success( $this->getStatus() );
	}

	/**
	 * Default AJAX SET handler. POST body must include `channel`.
	 *
	 * @return void
	 */
	public function handleAjaxSet() {
		$nonceAction = sprintf( '%s_updater_channel', $this->configuration->getPrefix() );
		check_ajax_referer( $nonceAction );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$channel = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '';
		$result  = $this->setChannel( $channel );
		if ( $result['success'] ) {
			wp_send_json_success( array(
				'message' => $result['message'],
				'status'  => $this->getStatus(),
			) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
}
