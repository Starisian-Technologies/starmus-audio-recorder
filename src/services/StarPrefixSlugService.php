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
 * This ensures consistency across all environments (Dev/Prod) so that
 * Cloudflare rules (when active) can always rely on the URL pattern.
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @since   0.8.0
 * @version 0.9.3-ALWAYS-PREFIX
 */
final class StarPrefixSlugService
{
    /**
     * Slug prefix for restricted posts
     * @var string PREFIX
     */
    private const PREFIX = 'star-';

    /**
     * Post meta key to identify restricted posts
     * @var string META_KEY
     */
    private const META_KEY = '_star_restricted';

    /**
     * Boot the service by registering WordPress hooks
     */
    public function star_boot(): void
    {
        // Hook into slug generation (save/update)
        add_filter('wp_unique_post_slug', [$this, 'star_prefix_slug'], 10, 6);
    }

    /**
     * Apply star- prefix to post slugs for restricted content
     *
     * Logic:
     * 1. Check if post is restricted via meta key.
     * 2. If restricted, ensure slug starts with "star-".
     *
     * @param string $slug The unique post slug
     * @param int $post_id The post ID
     *
     * @return string The potentially modified slug
     */
    public function star_prefix_slug(string $slug, int $post_id): string 
    {
        // 1. Check restriction status
        $restricted = get_post_meta($post_id, self::META_KEY, true);

        // 2. Apply Prefix if needed
        // We use '1' as the standard boolean true for checkbox meta
        if ($restricted === '1' && !str_starts_with($slug, self::PREFIX)) {
            return self::PREFIX . $slug;
        }

        return $slug;
    }
}