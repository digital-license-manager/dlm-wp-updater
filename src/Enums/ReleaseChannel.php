<?php

namespace IdeoLogix\DigitalLicenseManagerUpdaterWP\Enums;

/**
 * Mirrors the server-side ReleaseChannel enum in digital-license-manager-pro.
 * The library is standalone and cannot import from the Pro plugin, so the
 * four constants live here too. The wire contract is stable.
 */
class ReleaseChannel {

	const STABLE = 'stable';
	const RC     = 'rc';
	const BETA   = 'beta';
	const ALPHA  = 'alpha';

	/**
	 * All channel values, in precedence order (most stable first).
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::STABLE,
			self::RC,
			self::BETA,
			self::ALPHA,
		);
	}

	/**
	 * @param mixed $channel
	 *
	 * @return bool
	 */
	public static function isValid( $channel ) {
		return is_string( $channel ) && in_array( $channel, self::all(), true );
	}

	/**
	 * @return string
	 */
	public static function getDefault() {
		return self::STABLE;
	}

	/**
	 * Default English labels.
	 *
	 * @return array<string,string>
	 */
	public static function getDefaultLabels() {
		return array(
			self::STABLE => 'Stable',
			self::RC     => 'Release Candidate',
			self::BETA   => 'Beta',
			self::ALPHA  => 'Alpha',
		);
	}
}
