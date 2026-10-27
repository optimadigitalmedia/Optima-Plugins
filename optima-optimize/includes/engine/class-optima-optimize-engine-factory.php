<?php
/**
 * Engine selection.
 *
 * @package Optima_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects the optimization engine to use.
 *
 * Version 1 prefers the on-server Imagick engine and falls back to a null
 * engine when it is unavailable. This factory is the single seam where a future
 * GCP engine would be added, behind the same Optima_Optimize_Engine contract.
 */
final class Optima_Optimize_Engine_Factory {

	/**
	 * Build the best available engine.
	 *
	 * @return Optima_Optimize_Engine
	 */
	public static function make(): Optima_Optimize_Engine {
		$imagick = new Optima_Optimize_Imagick_Engine();
		if ( $imagick->is_available() ) {
			return $imagick;
		}
		return new Optima_Optimize_Null_Engine();
	}
}
