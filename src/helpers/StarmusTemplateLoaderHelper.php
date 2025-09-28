<?php
namespace Starmus\helpers;

use Starmus\helpers\StarmusLogger;

class StarmusTemplateLoaderHelper {

	/**
	 * Render a template file.
	 */
	public static function render_template( string $template_file, array $args = array() ): string {
		try {
			$template_name = basename( $template_file );

			$template_path = self::locate_template( $template_name );

			if ( ! $template_path ) {
				return '';
			}

			if ( is_array( $args ) ) {
				extract( $args, EXTR_SKIP );
			}

			ob_start();
			include $template_path;
			return (string) ob_get_clean();

		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'UI:render_template', $e );
			return '';
		}
	}


	/**
	 * Locates a template file, checking the theme/child theme first, then the plugin.
	 *
	 * @param string $template_name The name of the template file to find.
	 * @return string|null The full path to the template file, or null if not found.
	 */
	public static function locate_template( string $template_name ): ?string {
		$locations = array(
			trailingslashit( get_stylesheet_directory() ) . 'starmus/' . $template_name,
			trailingslashit( get_template_directory() ) . 'starmus/' . $template_name,
			trailingslashit( STARMUS_PATH ) . 'src/templates/' . $template_name,
		);

		$template_path = '';
		foreach ( $locations as $location ) {
			if ( file_exists( $location ) ) {
				$template_path = $location;
				break;
			}
		}

		return $template_path;
	}

	/**
	 * Admin edit screen URL.
	 */
	public static function get_edit_page_url_admin( ?string $cpt_slug = 'audio-recording' ): string {
		return admin_url( 'edit.php?post_type=' . $cpt_slug );
	}
}
