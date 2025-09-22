<?php
/**
 * Handles cache management for the Starmus plugin.
 *
 * @package Starmus\cli
 * @version 1.0.0
 */

namespace Starmus\cli;

use WP_CLI;
use WP_CLI_Command;
use Starmus\frontend\StarmusAudioRecorderUI;

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Manages Starmus caches.
 */
class StarmusCacheCommands extends WP_CLI_Command {

    /**
     * Flushes the plugin's taxonomy transients.
     *
     * ## EXAMPLES
     *
     *     $ wp starmus cache flush
     */
    public function flush() {
        WP_CLI::line( 'Flushing Starmus taxonomy caches...' );
        $recorder_ui = new StarmusAudioRecorderUI();
        // Assuming clear_taxonomy_transients is public
        $recorder_ui->clear_taxonomy_transients();
        WP_CLI::success( 'Starmus caches have been flushed.' );
    }
}
