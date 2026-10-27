<?php
/**
 * Plugin Name:       Optima CRM Sync
 * Description:       Keeps one customer record per person in FluentCRM, on your own site. Everyone who buys a ticket, signs up, volunteers, applies as a vendor or presenter, or joins the membership is added and tagged by what they did, the event and its region, ready for your own emails and segments. New people are added as transactional, never as marketing subscribers.
 * Version:           0.1.0.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-crm
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-crm
 *
 * @package Optima_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_CRM_VERSION', '0.1.0.2' );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-crm.php', 'optima-crm' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-crm.php';
require_once __DIR__ . '/includes/class-optima-crm-backfill.php';

// FluentCRM, Optima Events and Optima Members all load after this file.
add_action( 'plugins_loaded', array( 'Optima_CRM', 'boot' ), 20 );
add_action( 'plugins_loaded', array( 'Optima_CRM_Backfill', 'boot' ), 20 );
