<?php
namespace Starmus\includes;
/**
 * Class to handle plugin settings stored in WP options table.
 * 
 * This class provides methods to retrieve and manipulate plugin settings.
 * 
 * @package Starmus\includes;
 */
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle plugin settings stored in WP options table.
 * 
 */
final class StarmusSettings {
  public const KEY = 'starmus_options';
  public static function get(string $k, $default=null){ $o=get_option(self::KEY, []); return $o[$k] ?? $default; }
  public static function all(): array { return (array) get_option(self::KEY, []); }

  /**
	 * Helper function to safely get a setting value.
	 */
	public static function starmus_get_option( string $key, $default = '' ) {
		$options = get_option( self::OPTION_NAME );
		$defaults = [
			'cpt_slug'             => 'audio-recording',
			'file_size_limit'      => 10,
			'recording_time_limit' => 300,
                        'allowed_file_types'   => 'mp3,wav,webm,m4a,ogg,opus',
                        'consent_message'      => __( 'I consent to having this audio recording stored and used.', 'starmus_audio_recorder' ),
                        'collect_ip_ua'        => 0,
                        'data_policy_url'      => '',
                ];
		
		return $options[ $key ] ?? $defaults[ $key ] ?? $default;
	}
}
