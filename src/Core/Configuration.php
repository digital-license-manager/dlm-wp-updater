<?php

namespace IdeoLogix\DigitalLicenseManagerUpdaterWP\Core;

use IdeoLogix\DigitalLicenseManagerUpdaterWP\Enums\ReleaseChannel;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Http\Client;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Models\Plugin;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Models\Theme;


class Configuration {

	/**
	 * The args backup
	 * @var array
	 */
	protected $_args = [];

	/**
	 * The HTTP Client
	 * @var Client
	 */
	protected $client;

	/**
	 * The Entity
	 * @var Plugin|Theme
	 */
	protected $entity;

	/**
	 * The app prefix
	 * @var string
	 */
	protected $prefix;

	/**
	 * The default labels
	 * @var array
	 */
	protected $labels;

	/**
	 * Whether to mask the license key input
	 * @var bool
	 */
	protected $mask_key_input;

	/**
	 * Library-level default channel when no stored preference exists.
	 * @var string
	 */
	protected $channelDefault = ReleaseChannel::STABLE;

	/**
	 * Whitelist of channels the consumer wants to expose.
	 * @var string[]
	 */
	protected $channelsAvailable;

	/**
	 * Display labels per channel.
	 * @var array<string,string>
	 */
	protected $channelLabels;

	/**
	 * When true, the channel is not user-editable in the UI.
	 * @var bool
	 */
	protected $channelLocked = false;

	/**
	 * Memoized resolution of Configuration::getChannel().
	 * @var string|null
	 */
	protected $resolvedChannel = null;

	/**
	 * Application constructor.
	 *
	 * @param $args
	 */
	public function __construct( $args ) {

		$this->_args = $args;

		// The context
		$context = ! empty( $args['context'] ) && in_array( $args['context'], array(
			'theme',
			'plugin'
		) ) ? $args['context'] : 'plugin';

		// The entity params
		$entityArgs = Utilities::arrayOnly( $args, array(
			'id',
			'name',
			'basename',
			'file',
			'version',
			'url_settings',
			'url_purchase',
		) );

		// The client main params.
		$clientArgs = Utilities::arrayOnly( $args, array(
			'consumer_key',
			'consumer_secret',
			'api_url',
		) );

		// The client cache params.
		foreach ( $args as $key => $value ) {
			if ( strpos( $key, 'cache_ttl_' ) === 0 ) {
				$clientArgs[ $key ] = $value;
			}
		}

		// The Prefix
		$this->prefix         = isset( $args['prefix'] ) ? $args['prefix'] : 'dlm';
		$entityArgs['prefix'] = $this->prefix;
		$clientArgs['prefix'] = $this->prefix;

		// The Entity Object
		if ( 'plugin' === $context ) {
			$this->entity = new Plugin( $entityArgs );
		} else if ( 'theme' === $context ) {
			$this->entity = new Theme( $entityArgs );
		}

		// The Client Object
		$this->client = new Client( $clientArgs );

		// Set-up the labels
		$this->labels = $this->getDefaultLabels(); // Defaults.
		add_action('init', [$this, 'setupLabels']); // Overrides.

		//Other...
		if ( isset( $args['mask_key_input'] ) ) {
			$this->mask_key_input = (bool) $args['mask_key_input'];
		}

		// Channel configuration
		if ( isset( $args['channel_default'] ) && ReleaseChannel::isValid( $args['channel_default'] ) ) {
			$this->channelDefault = $args['channel_default'];
		}
		if ( isset( $args['channels_available'] ) && is_array( $args['channels_available'] ) ) {
			$filtered = array_values( array_filter( $args['channels_available'], array( ReleaseChannel::class, 'isValid' ) ) );
			if ( ! empty( $filtered ) ) {
				$this->channelsAvailable = $filtered;
			}
		}
		if ( null === $this->channelsAvailable ) {
			$this->channelsAvailable = ReleaseChannel::all();
		}
		// Defensive: make sure the effective default is reachable from the whitelist.
		if ( ! in_array( $this->channelDefault, $this->channelsAvailable, true ) ) {
			array_unshift( $this->channelsAvailable, $this->channelDefault );
		}
		if ( isset( $args['channel_labels'] ) && is_array( $args['channel_labels'] ) ) {
			$this->channelLabels = array_merge( ReleaseChannel::getDefaultLabels(), $args['channel_labels'] );
		} else {
			$this->channelLabels = ReleaseChannel::getDefaultLabels();
		}
		if ( isset( $args['channel_locked'] ) ) {
			$this->channelLocked = (bool) $args['channel_locked'];
		}
	}


	/**
	 * Setup the labels
	 * @return void
	 */
	public function setupLabels() {
		if ( isset( $this->_args['labels'] ) && is_callable( $this->_args['labels'] ) ) {
			$newLabels = call_user_func( $this->_args['labels'] );
			if ( empty( $newLabels ) ) {
				return;
			}
			foreach ( $newLabels as $key => $label ) {
				if ( isset( $this->labels[ $key ] ) ) {
					$this->labels[ $key ] = $newLabels[ $key ];
				}
			}
		}
	}

	/**
	 * Returns the client
	 * @return Client
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * Return the entity
	 * @return Plugin|Theme
	 */
	public function getEntity() {
		return $this->entity;
	}

	/**
	 * Returns the prefix
	 * @return mixed|string
	 */
	public function getPrefix() {
		return $this->prefix;
	}

	/**
	 * Clears the cache
	 */
	public function clearCache() {
		$this->client->clearCache( $this->entity );
		$this->resolvedChannel = null;
	}

	/**
	 * Resolve the effective release channel for this install.
	 *
	 * Precedence (first non-empty wins), then intersected with the consumer's
	 * available whitelist, then coerced to the library default if the resolved
	 * value falls outside the whitelist:
	 *
	 *   1. `dlm_updater_channel` filter
	 *   2. Model::getStoredChannel() (user preference)
	 *   3. $channel_default constructor arg
	 *   4. ReleaseChannel::getDefault() (= 'stable')
	 *
	 * @return string
	 */
	public function getChannel() {
		if ( null !== $this->resolvedChannel ) {
			return $this->resolvedChannel;
		}

		$stored = $this->entity ? $this->entity->getStoredChannel() : false;

		$candidate = $stored && ReleaseChannel::isValid( $stored )
			? $stored
			: $this->channelDefault;

		/**
		 * Runtime override for the resolved channel.
		 *
		 * @param string        $channel       Candidate channel (stored or default).
		 * @param Configuration $configuration Current configuration instance.
		 */
		$candidate = apply_filters( 'dlm_updater_channel', $candidate, $this );

		if ( ! ReleaseChannel::isValid( $candidate ) ) {
			$candidate = $this->channelDefault;
		}

		$available = $this->getAvailableChannels();
		if ( ! in_array( $candidate, $available, true ) ) {
			$candidate = in_array( $this->channelDefault, $available, true )
				? $this->channelDefault
				: ReleaseChannel::getDefault();
		}

		$this->resolvedChannel = $candidate;

		return $this->resolvedChannel;
	}

	/**
	 * @return string
	 */
	public function getChannelDefault() {
		return $this->channelDefault;
	}

	/**
	 * @return string[]
	 */
	public function getAvailableChannels() {
		/**
		 * Allow runtime pruning of the dropdown options.
		 *
		 * @param string[]      $channels
		 * @param Configuration $configuration
		 */
		$channels = apply_filters( 'dlm_updater_available_channels', $this->channelsAvailable, $this );

		$channels = array_values( array_filter( (array) $channels, array( ReleaseChannel::class, 'isValid' ) ) );

		return empty( $channels ) ? array( ReleaseChannel::getDefault() ) : $channels;
	}

	/**
	 * @return array<string,string>
	 */
	public function getChannelLabels() {
		return $this->channelLabels;
	}

	/**
	 * @return bool
	 */
	public function isChannelLocked() {
		return (bool) $this->channelLocked;
	}

	/**
	 * Whether the license key input is masked
	 * @return bool
	 */
	public function isKeyInputMasked() {
		return $this->mask_key_input;
	}

	/**
	 * Return the default labels
	 * @return string[]
	 */
	public function getDefaultLabels() {
		return [
			'activator.no_permissions'                   => 'Sorry, you dont have enough permissions to manage those settings.',
			'activator.license_removed'                  => 'License removed.',
			'activator.invalid_action'                   => 'Invalid action.',
			'activator.invalid_license_key'              => 'Please provide valid product key.',
			'activator.license_activated'                => 'Congrats! Your key is valid and your product will receive future updates',
			'activator.license_deactivated'              => 'The license key is now deactivated.',
			'activator.activation_permanent'             => 'License :status. Activation permanent.',
			'activator.activation_expires'               => 'License :status. Expires on :expires_at (:days_remaining days remaining).',
			'activator.activation_deactivated_permanent' => 'License :status. Deactivated on :deactivated_at (Valid permanently)',
			'activator.activation_deactivated_expires'   => 'License :status. Deactivated on :deactivated_at (:days_remaining days remaining)',
			'activator.activation_expired_purchase'      => 'Your license is :status. To get regular updates and support, please <purchase_link>purchase the product</purchase_link>.',
			'activator.activation_purchase'              => 'To get regular updates and support, please <purchase_link>purchase the product</purchase_link>.',
			'activator.word_valid'                       => 'valid',
			'activator.word_expired'                     => 'expired',
			'activator.word_expired_or_invalid'          => 'expired or invalid',
			'activator.word_deactivate'                  => 'Deactivate',
			'activator.word_activate'                    => 'Activate',
			'activator.word_reactivate'                  => 'Reactivate',
			'activator.word_purchase'                    => 'Purchase',
			'activator.word_renew'                       => 'Renew',
			'activator.word_remove'                      => 'Remove',
			'activator.word_product_key'                 => 'Product Key',
			'activator.help_remove'                      => 'Remove the license key',
			'activator.help_product_key'                 => 'Enter your product key',
		];
	}

	/**
	 * Returns a label
	 *
	 * @param $key
	 *
	 * @return mixed|string
	 */
	public function label( $key ) {
		return isset( $this->labels[ $key ] ) ? $this->labels[ $key ] : $key;
	}
}
