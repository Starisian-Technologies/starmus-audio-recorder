<?php
/**
 * Summary of namespace Starmus\helpers
 */
namespace Starmus\helpers;

if (!defined('ABSPATH')) {
	exit;
}

class StarmusMimeHelper
{
	private static ?StarmusMimeHelper $instance = null;
	private function __construct()
	{
		$this->register_hooks();
	}

	/**
	 * Main singleton instance method.
	 *
	 * Intentionally empty - initialization happens in init().
	 * Ensures that only one instance of the plugin's main class exists.
	 *
	 * @since 0.1.0
	 * @return StarmusPlugin The single instance of the class.
	 */
	public static function get_instance(): StarmusMimeHelper
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks(): void
	{
		add_filter('wp_check_filetype_and_ext', array($this, 'filter_filetype_and_ext'), 10, 5);
		add_filter('upload_mimes', array($this, 'filter_upload_mimes'));
	}
	/**
	 * Expand allowable MIME types during uploads.
	 *
	 * @param array|false $types         Existing MIME check result from WordPress.
	 * @param string      $file          Current file path (unused).
	 * @param string      $filename      Original filename provided by the user.
	 * @param array       $mimes_allowed Allowed MIME types passed into the filter.
	 * @param string      $real_mime     MIME type detected by PHP.
	 *
	 * @return array Filtered MIME type data.
	 */
	public function filter_filetype_and_ext($types, $file, $filename, $mimes_allowed, $real_mime): array
	{
		unset($file, $mimes_allowed, $real_mime);
		$ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
		$whitelist = self::get_allowed_mimes();
		if (isset($whitelist[$ext])) {
			return array(
				'ext' => $ext,
				'type' => $whitelist[$ext],
				'proper_filename' => $filename,
			);
		}
		return is_array($types) ? $types : array();
	}

	/**
	 * Allow audio/video MIME uploads that WordPress blocks by default.
	 *
	 * @param array $mimes Existing MIME mapping keyed by extension.
	 *
	 * @return array Filtered MIME mapping.
	 */
	public function filter_upload_mimes(array $mimes): array
	{
		$whitelist = self::get_allowed_mimes();
		foreach ($whitelist as $ext => $mime) {
			$mimes[$ext] = $mime;
		}
		return $mimes;
	}

	public static function get_allowed_mimes(): array
	{
		return array(
			'mp3' => 'audio/mpeg',
			'wav' => 'audio/wav',
			'ogg' => 'audio/ogg',
			'oga' => 'audio/ogg',
			'opus' => 'audio/ogg; codecs=opus',
			'weba' => 'audio/webm',
			'aac' => 'audio/aac',
			'm4a' => 'audio/mp4',
			'flac' => 'audio/flac',
			'mp4' => 'video/mp4',
			'm4v' => 'video/x-m4v',
			'mov' => 'video/quicktime',
			'webm' => 'video/webm',
			'ogv' => 'video/ogg',
			'avi' => 'video/x-msvideo',
			'wmv' => 'video/x-ms-wmv',
			'3gp' => 'video/3gpp',
			'3g2' => 'video/3gpp2',
		);
	}
}
