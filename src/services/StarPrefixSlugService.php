<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Star Private Slug Prefix Service
 *
 * Automatically prefixes post slugs with "star-" for posts marked as restricted.
 * This provides a naming convention to identify private/restricted content by URL slug.
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @since   0.8.0
 *
 * @version 1.0.0
 */
final class StarPrivateSlugPrefix
{
    /**
     * Slug prefix for restricted posts
     *
     * @var string PREFIX The prefix applied to restricted post slugs
     */
    private const PREFIX = 'star-';

    /**
     * Post meta key to identify restricted posts
     *
     * @var string META_KEY The meta key used to flag restricted posts
     */
    private const META_KEY = '_star_restricted';

    /**
     * Boot the service by registering WordPress hooks
     *
     * Registers the wp_unique_post_slug filter to automatically prefix
     * slugs for posts marked as restricted.
     *
     * @since 0.8.0
     */
    public function star_boot(): void
    {
        add_filter('wp_unique_post_slug', $this->star_prefix_slug(...), 10, 6);
    }

    /**
     * Apply star- prefix to post slugs for restricted content
     *
     * Filters the unique post slug generation to automatically prepend "star-"
     * to posts that have the _star_restricted meta key set to '1'.
     * Only applies the prefix if it's not already present.
     *
     * @param string $slug The unique post slug
     * @param int $post_id The post ID
     *
     * @return string The potentially modified slug with star- prefix
     *
     * @since 0.8.0
     */
    public function star_prefix_slug(
        string $slug,
        int $post_id
    ): string {
        $restricted = get_post_meta($post_id, self::META_KEY, true);

        if ($restricted === '1' && !str_starts_with($slug, self::PREFIX)) {
            return self::PREFIX . $slug;
        }

        return $slug;
    }
}
