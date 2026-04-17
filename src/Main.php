<?php

namespace IdeoLogix\DigitalLicenseManagerUpdaterWP;

use IdeoLogix\DigitalLicenseManagerUpdaterWP\Core\Activator;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Core\ChannelSelector;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Core\Configuration;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Core\Updater;

class Main {

	/**
	 * The configuration
	 * @var Configuration
	 */
	protected $configuration;

	/**
	 * The Activator
	 * @var Activator
	 */
	protected $activator;

	/**
	 * The Updater
	 * @var Updater
	 */
	protected $updater;

	/**
	 * The channel selector (release channel UI + persistence)
	 * @var ChannelSelector
	 */
	protected $channelSelector;

	/**
	 * Constructor
	 *
	 * @param $args
	 * @param array $config
	 *
	 * @throws \Exception
	 */
	public function __construct( $args, $config = array() ) {
		$this->configuration   = new Configuration( $args );
		$this->activator       = new Activator( $this->configuration );
		$this->updater         = new Updater( $this->configuration );
		$this->channelSelector = new ChannelSelector( $this->configuration );

		// Library-default AJAX hooks for the channel selector. Consumers that
		// want their own nonce convention can register their own wrappers
		// against $this->channelSelector->getStatus() / ->setChannel().
		if ( function_exists( 'add_action' ) ) {
			$prefix = $this->configuration->getPrefix();
			add_action( 'wp_ajax_' . $prefix . '_updater_channel_get', array( $this->channelSelector, 'handleAjaxGet' ) );
			add_action( 'wp_ajax_' . $prefix . '_updater_channel_set', array( $this->channelSelector, 'handleAjaxSet' ) );
		}
	}

	/**
	 * Returns the Activator instance
	 * @return Activator
	 */
	public function getActivator() {
		return $this->activator;
	}

	/**
	 * Returns the Updater instance
	 * @return Updater
	 */
	public function getUpdater() {
		return $this->updater;
	}

	/**
	 * Returns the ChannelSelector instance
	 * @return ChannelSelector
	 */
	public function getChannelSelector() {
		return $this->channelSelector;
	}

	/**
	 * Returns the configuration instance
	 * @return Configuration
	 */
	public function getConfiguration() {
		return $this->configuration;
	}

	/**
	 * DX forwarder: resolved release channel for this install.
	 * @return string
	 */
	public function getChannel() {
		return $this->configuration->getChannel();
	}

	/**
	 * DX forwarder: persist channel + clear cache. Returns false if invalid.
	 *
	 * @param string $channel
	 *
	 * @return bool
	 */
	public function setChannel( $channel ) {
		$result = $this->channelSelector->setChannel( $channel );

		return ! empty( $result['success'] );
	}

	/**
	 * Set the activator
	 *
	 * @param $args
	 */
	public function setActivator( $args ) {
		if ( is_object( $args ) ) {
			$this->activator = $args;
		} elseif ( class_exists( $args ) ) {
			$this->activator = new $args( $this->configuration );
		}
	}

	/**
	 * Set the updater
	 *
	 * @param $args
	 */
	public function setUpdater( $args ) {
		if ( is_object( $args ) ) {
			$this->updater = $args;
		} elseif ( class_exists( $args ) ) {
			$this->updater = new $args( $this->configuration );
		}
	}

	/**
	 * Set the configuration instance
	 *
	 * @param $args
	 */
	public function setApplication( $args ) {
		$this->configuration = $args;
	}
}
