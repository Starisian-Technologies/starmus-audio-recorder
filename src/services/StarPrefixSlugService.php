<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Star Private Slug Prefix Service with CloudFlare Integration
 *
 * Automatically prefixes WordPress post slugs with "star-" for posts marked
 * as restricted content. Ensures URL pattern consistency across all environments
 * (Development/Production) for reliable CloudFlare caching rule application.
 *
 * Key Features:
 * - **Automatic Prefix Application**: Seamless slug modification
 * - **Environment Consistency**: Uniform URL patterns Dev/Prod
 * - **CloudFlare Rule Support**: Predictable URL structure
 * - **Restriction Meta Integration**: WordPress custom field detection
 * - **Hook-Based Architecture**: WordPress filter system integration
 *
 * Business Logic:
 * - Posts marked with _star_restricted meta get "star-" URL prefix
 * - Enables CloudFlare rules to identify restricted content reliably
 * - Maintains SEO-friendly URLs while supporting access controls
 * - Automatic application during post save/update operations
 *
 * URL Pattern Examples:
 * - Standard post: `/my-recording/`
 * - Restricted post: `/star-my-recording/`
 * - Enables CloudFlare rule: `/star-*` → special handling
 *
 * WordPress Integration:
 * - Hooks into `wp_unique_post_slug` filter
 * - Reads `_star_restricted` post meta field
 * - Preserves WordPress slug uniqueness algorithms
 * - Compatible with custom post types
 *
 * CloudFlare Use Cases:
 * - Page rules for restricted content caching
 * - Access control based on URL patterns
 * - Analytics tracking for restricted vs. public content
 * - CDN behavior customization
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @since   0.8.0
 *
 * @version 0.9.3-ALWAYS-PREFIX
 *
 * @see wp_unique_post_slug() WordPress slug generation
 * @see get_post_meta() WordPress metadata retrieval
 */
final class StarPrefixSlugService {

	/**
	 * URL slug prefix for restricted content identification.
	 *
	 * Applied to post slugs when _star_restricted meta is active.
	 * Enables CloudFlare rules to target restricted content URLs.
	 *
	 * @var string PREFIX
	 *
	 * @since 0.8.0
	 */
	private const PREFIX = 'star-';

	/**
	 * WordPress post meta key for restriction status detection.
	 *
	 * Custom field that indicates when a post should receive
	 * the star- URL prefix for restricted content handling.
	 *
	 * @var string META_KEY
	 *
	 * @since 0.8.0
	 */
	private const META_KEY = '_star_restricted';

	/**
	 * Initializes the service by registering WordPress filter hooks.
	 *
	 * Sets up automatic slug modification during WordPress post save/update
	 * operations through the wp_unique_post_slug filter integration.
	 *
	 * @since 0.8.0
	 *
	 * Registered Hooks:
	 * - `wp_unique_post_slug`: Modifies post slugs during generation
	 *
	 * Hook Priority: 10 (standard WordPress priority)
	 * Hook Arguments: 6 (full parameter access)
	 * @see star_prefix_slug() Filter callback implementation
	 */
	public function star_boot(): void {
		// Hook into slug generation (save/update)
		add_filter( 'wp_unique_post_slug', $this->star_prefix_slug( ... ), 10, 2 );
	}

	/**
	 * WordPress filter callback for automatic slug prefixing.
	 *
	 * Modifies post slugs during WordPress unique slug generation to add
	 * "star-" prefix for posts marked as restricted content.
	 *
	 * @param string $slug Current unique post slug candidate
	 * @param int    $post_id WordPress post ID being processed
	 *
	 * @return string Modified slug with prefix if restricted, or original slug
	 *
	 * @since 0.8.0
	 *
	 * Decision Logic:
	 * 1. **Meta Check**: Read `_star_restricted` post meta field
	 * 2. **Value Validation**: Check for boolean true ('1' string)
	 * 3. **Prefix Check**: Verify slug doesn't already have prefix
	 * 4. **Application**: Add "star-" prefix if conditions met
	 *
	 * Meta Field Values:
	 * - '1': Restricted content (triggers prefix)
	 * - '0' or empty: Public content (no prefix)
	 * - WordPress checkbox fields store '1' for checked state
	 *
	 * URL Impact Examples:
	 * - Input: "my-recording" + restricted → Output: "star-my-recording"
	 * - Input: "star-existing" + restricted → Output: "star-existing" (unchanged)
	 * - Input: "public-post" + not restricted → Output: "public-post" (unchanged)
	 *
	 * CloudFlare Integration:
	 * - Prefixed URLs match CloudFlare page rules: `/star-*`
	 * - Enables specialized caching for restricted content
	 * - Supports access control implementations
	 *
	 * Performance Considerations:
	 * - Single meta query per post save (minimal overhead)
	 * - String prefix check via str_starts_with() (PHP 8+ optimized)
	 * - No database writes (read-only operation)
	 *
	 * WordPress Filter Context:
	 * - Called during wp_unique_post_slug() execution
	 * - Runs on post save/update operations
	 * - Maintains WordPress slug uniqueness algorithms
	 * - Compatible with all post types
	 *
	 * @hook wp_unique_post_slug Filter integration point
	 *
	 * @see get_post_meta() WordPress metadata access
	 * @see str_starts_with() PHP 8 string function
	 */
	public function star_prefix_slug( string $slug, int $post_id ): string {
		// 1. Check restriction status
		$restricted = get_post_meta( $post_id, self::META_KEY, true );

		// 2. Apply Prefix if needed
		// We use '1' as the standard boolean true for checkbox meta
		if ( $restricted === '1' && ! str_starts_with( $slug, self::PREFIX ) ) {
			return self::PREFIX . $slug;
		}

		return $slug;
	}
}
