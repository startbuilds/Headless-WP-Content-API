<?php
/**
 * Plugin Name: Custom Content API
 * Description: Exposes custom REST API endpoints for pages, posts, and custom post types with Gutenberg and ACF support
 * Version: 1.4.0
 * Author: Naveen Sharma
 * Author URI: https://naveenforhire.dev
 * License: GPLv2 or later
 * Text Domain: custom-content-api
 */

if (!defined('ABSPATH')) exit;

class Custom_Content_API {
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_api_routes'));
    }

    public function register_api_routes() {
        // Routes setup
        $routes = [
            ['all-content', 'get_all_content'],
            ['content/(?P<type>[a-zA-Z0-9-]+)', 'get_content_by_type'],
            ['content/id/(?P<id>\\d+)', 'get_content_by_id'],
            ['content/(?P<type>[a-zA-Z0-9-]+)/id/(?P<id>\\d+)', 'get_content_by_type_and_id'],
            ['taxonomies/(?P<type>[a-zA-Z0-9-]+)', 'get_taxonomies_by_post_type'],
            ['posts-by-taxonomy/(?P<taxonomy>[a-zA-Z0-9-]+)/(?P<term_id>\\d+)', 'get_posts_by_taxonomy'],
            ['content/(?P<type>[a-zA-Z0-9-]+)/slug/(?P<slug>[a-zA-Z0-9-]+)', 'get_content_by_type_and_slug'],
            ['content/slug/(?P<slug>[a-zA-Z0-9-]+)', 'get_content_by_slug']
        ];

        foreach ($routes as [$route, $callback]) {
            register_rest_route('custom-api/v1', '/' . $route, array(
                'methods' => 'GET',
                'callback' => array($this, $callback),
                'permission_callback' => '__return_true'
            ));
        }
    }

    // === Core Endpoints ===
    public function get_all_content() {
        $response = [];
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $type) {
            $posts = get_posts(['post_type' => $type, 'posts_per_page' => -1, 'post_status' => 'publish']);
            $response[$type] = array_map([$this, 'format_post_data'], $posts);
        }
        return rest_ensure_response($response);
    }

    public function get_content_by_type($req) {
        $type = $req['type'];
        if (!post_type_exists($type)) return $this->error('Invalid post type');

        $posts = get_posts(['post_type' => $type, 'posts_per_page' => -1, 'post_status' => 'publish']);
        return rest_ensure_response(array_map([$this, 'format_post_data'], $posts));
    }

    public function get_content_by_id($req) {
        $post = get_post($req['id']);
        if (!$post || $post->post_status !== 'publish') return $this->error('Post not found');
        return rest_ensure_response($this->format_post_data_with_custom_fields($post));
    }

    public function get_content_by_type_and_id($req) {
        $post = get_post($req['id']);
        if (!$post || $post->post_type !== $req['type'] || $post->post_status !== 'publish') return $this->error('Post mismatch');
        return rest_ensure_response($this->format_post_data($post));
    }

    public function get_content_by_slug($req) {
        return $this->fetch_by_args(['name' => $req['slug'], 'post_type' => 'any']);
    }

    public function get_content_by_type_and_slug($req) {
        return $this->fetch_by_args(['post_type' => $req['type'], 'name' => $req['slug']]);
    }

    public function get_taxonomies_by_post_type($req) {
        $tax = get_object_taxonomies($req['type'], 'objects');
        $out = [];

        foreach ($tax as $t) {
            if (!$t->public) continue;
            $terms = get_terms(['taxonomy' => $t->name, 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                $out[$t->name] = [
                    'name' => $t->label,
                    'terms' => array_map(function($term) {
                        return [
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'count' => $term->count,
                            'meta' => get_term_meta($term->term_id)
                        ];
                    }, $terms)
                ];
            }
        }
        return rest_ensure_response($out);
    }

    public function get_posts_by_taxonomy($req) {
        $taxonomy = $req['taxonomy'];
        $term_id = $req['term_id'];

        $post_types = array_keys(array_filter(get_post_types(['public' => true], 'objects'), function($pt) use ($taxonomy) {
            return in_array($taxonomy, get_object_taxonomies($pt->name));
        }));

        $query = new WP_Query([
            'post_type' => $post_types,
            'tax_query' => [[
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_id
            ]],
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        return rest_ensure_response(array_map([$this, 'format_post_data'], $query->posts));
    }

    // === Helpers ===
    private function fetch_by_args($args) {
        $args['post_status'] = 'publish';
        $posts = get_posts($args);
        if (empty($posts)) return $this->error('Post not found');
        return rest_ensure_response($this->format_post_data_with_custom_fields($posts[0]));
    }

    private function format_post_data($post) {
        $thumb = wp_get_attachment_image_src(get_post_thumbnail_id($post->ID), 'full');
        $blocks = parse_blocks($post->post_content);

        return [
            'id' => $post->ID,
            'type' => $post->post_type,
            'title' => get_the_title($post->ID),
            'slug' => $post->post_name,
            'date' => $post->post_date,
            'excerpt' => get_the_excerpt($post->ID),
            'featured_image' => $thumb ? $thumb[0] : null,
            'blocks' => $this->format_blocks($blocks),
            'permalink' => get_permalink($post->ID)
        ];
    }

    private function format_post_data_with_custom_fields($post) {
        $data = $this->format_post_data($post);
        $custom = [];
        foreach (get_post_custom($post->ID) as $key => $val) {
            if (strpos($key, '_') !== 0) {
                $acf = function_exists('get_field') ? get_field($key, $post->ID) : null;
                $custom[$key] = $acf !== false ? $acf : maybe_unserialize($val[0]);
            }
        }
        $data['custom_fields'] = $custom;
        return $data;
    }

    private function format_blocks($blocks) {
        return array_map(function($block) {
            return [
                'blockName' => $block['blockName'],
                'attrs' => $block['attrs'],
                'innerHTML' => $block['innerHTML'],
                'innerContent' => $block['innerContent'],
                'rendered' => $block['blockName'] ? render_block($block) : ''
            ];
        }, $blocks);
    }

    private function error($msg) {
        return new WP_Error('custom_api_error', $msg, ['status' => 404]);
    }
}

new Custom_Content_API();
