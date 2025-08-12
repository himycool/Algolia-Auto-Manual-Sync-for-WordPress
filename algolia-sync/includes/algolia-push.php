<?php
if (!defined('ABSPATH')) exit;

// Function to generate index name based on post type
function algolia_sync_get_index_name($post_type) {
    // Manual mapping of post types to index names
    $index_mapping = [
        'blog' => 'Blog',
        'post' => 'Posts',
        'page' => 'Pages',
        // Add more mappings as needed
        // 'custom_post_type' => 'CustomIndexName',
    ];
    
    // Return mapped index name if exists, otherwise use post type as is
    return isset($index_mapping[$post_type]) ? $index_mapping[$post_type] : $post_type;
}

// Function to prepare comprehensive post data for Algolia
function algolia_sync_prepare_post_data($post_id, $post) {
    // Get all taxonomy terms
    $blog_category_terms = get_the_terms($post_id, 'blog-category');
    $blog_by_topic_terms = get_the_terms($post_id, 'blog-by-topic');
    
    // Get primary taxonomy terms from meta
    $selected_primary_terms = get_post_meta($post_id, 'selected_primary_terms', true);
    $primary_blog_category = null;
    if (!empty($selected_primary_terms['blog-category'][0])) {
        $primary_term_id = $selected_primary_terms['blog-category'][0];
        $term = get_term_by('term_id', $primary_term_id, 'blog-category');
        if ($term) {
            $primary_blog_category = [
                'name' => $term->name,
                'slug' => $term->slug
            ];
        }
    }
    $primary_blog_by_topic = null;
    if (!empty($selected_primary_terms['blog-by-topic'][0])) {
        $primary_term_id = $selected_primary_terms['blog-by-topic'][0];
        $term = get_term_by('term_id', $primary_term_id, 'blog-by-topic');
        if ($term) {
            $primary_blog_by_topic = [
                'name' => $term->name,
                'slug' => $term->slug
            ];
        }
    }
    
    // Get all custom meta fields
    $learn_more_type = get_post_meta($post_id, 'learn_more_type', true);
    $learn_more_link = get_post_meta($post_id, 'learn_more_link', true);
    $show_popup = get_post_meta($post_id, 'show_popup', true);
    $learn_more_link_file = get_post_meta($post_id, 'learn_more_link_file', true);
    $disable_iframe = get_post_meta($post_id, 'disable_iframe', true);
    $image_alt_text = get_post_meta($post_id, 'image_alt_text', true);
    $place_holder_image_url = get_post_meta($post_id, 'place_holder_image_url', true);
    $post_reading_time = get_post_meta($post_id, 'post_reading_time', true);
    $show_custom_date = get_post_meta($post_id, 'show_custom_date', true);
    $custom_date = get_post_meta($post_id, 'custom_date', true);
    $featured = get_post_meta($post_id, 'featured', true);
    $featured_page_list = get_post_meta($post_id, 'featured_page_list', true);
    $learn_more_label = get_post_meta($post_id, 'learn_more_label', true);
    $event_date = get_post_meta($post_id, 'event_date', true);
    $event_start_date = get_post_meta($post_id, 'event_start_date', true);
    $event_end_date = get_post_meta($post_id, 'event_end_date', true);
    $hide_from_list_view = get_post_meta($post_id, 'hide_from_list_view', true);

    // Prepare comprehensive data array
    $data = [
        'objectID' => $post_id,
        'title' => sanitize_text_field(get_the_title($post_id)),
        'content' => wp_strip_all_tags($post->post_content),
        'excerpt' => wp_strip_all_tags(get_the_excerpt($post_id)),
        'permalink' => esc_url_raw(get_permalink($post_id)),
        'date' => get_the_date('c', $post_id),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'image' => get_the_post_thumbnail_url($post_id, 'full'),
        'post_type' => $post->post_type,
        'status' => $post->post_status,
        // Blog-specific taxonomy fields
        'blog_category' => $blog_category_terms && !is_wp_error($blog_category_terms) ? array_map(function($t){return $t->name;}, $blog_category_terms) : [],
        'blog_category_slugs' => $blog_category_terms && !is_wp_error($blog_category_terms) ? array_map(function($t){return $t->slug;}, $blog_category_terms) : [],
        'blog_by_topic' => $blog_by_topic_terms && !is_wp_error($blog_by_topic_terms) ? array_map(function($t){return $t->name;}, $blog_by_topic_terms) : [],
        'blog_by_topic_slugs' => $blog_by_topic_terms && !is_wp_error($blog_by_topic_terms) ? array_map(function($t){return $t->slug;}, $blog_by_topic_terms) : [],
        'primary_blog_category' => $primary_blog_category,
        'primary_blog_by_topic' => $primary_blog_by_topic,
        // Custom meta fields
        'show_custom_date' => $show_custom_date,
        'custom_date' => $custom_date,
        'featured' => $featured,
        'featured_page_list' => $featured_page_list,
        'image_alt_text' => $image_alt_text,
        'learn_more_label' => $learn_more_label,
        'learn_more_type' => $learn_more_type,
        'learn_more_link' => $learn_more_link,
        'show_popup' => $show_popup,
        'learn_more_link_file' => $learn_more_link_file,
        'event_date' => $event_date,
        'event_start_date' => $event_start_date,
        'event_end_date' => $event_end_date,
        'place_holder_image_url' => $place_holder_image_url,
        'hide_from_list_view' => $hide_from_list_view,
        'disable_iframe' => $disable_iframe,
        'post_reading_time' => $post_reading_time,
    ];

    return $data;
}

function algolia_sync_on_save($post_id, $post, $update) {
    // Skip autosaves and revisions
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    // Check if post type is selected for sync
    $selected_types = get_option('algolia_sync_post_types', []);
    
    if (!in_array($post->post_type, $selected_types)) {
        return;
    }

    // Handle trashed posts - delete from Algolia
    if ($post->post_status === 'trash') {
        algolia_sync_delete_post($post_id);
        return;
    }

    // Only sync published posts
    if ($post->post_status !== 'publish') {
        return;
    }

    // Get API credentials from WordPress options
    $app_id = get_option('algolia_app_id');
    $api_key = get_option('algolia_api_key');
    
    // Generate index name based on post type
    $index_name = algolia_sync_get_index_name($post->post_type);

    // Validate credentials
    if (empty($app_id) || empty($api_key)) {
        error_log('Algolia Sync: Missing API credentials. Please configure in settings.');
        return;
    }

    // Validate post data - only require title
    if (empty($post->post_title)) {
        error_log("Algolia Sync: Post {$post_id} has empty title, skipping sync.");
        return;
    }

    // Prepare comprehensive data
    $data = algolia_sync_prepare_post_data($post_id, $post);

    // Send to Algolia
    $url = "https://{$app_id}-dsn.algolia.net/1/indexes/{$index_name}";

    $response = wp_remote_post($url, [
        'headers' => [
            'X-Algolia-API-Key' => $api_key,
            'X-Algolia-Application-Id' => $app_id,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30,
        'blocking' => false // Back to non-blocking for production
    ]);

    // Log the result
    if (is_wp_error($response)) {
        error_log("Algolia Sync: Failed to sync post {$post_id} - " . $response->get_error_message());
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200 || $status_code === 201) {
            error_log("Algolia Sync: Successfully synced post {$post_id} to Algolia");
        } else {
            $body = wp_remote_retrieve_body($response);
            error_log("Algolia Sync: Failed to sync post {$post_id} - Status: {$status_code}, Response: {$body}");
        }
    }
}
add_action('save_post', 'algolia_sync_on_save', 10, 3);

// Handle posts moved to trash
function algolia_sync_on_trash($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return;
    }

    // Check if post type is selected for sync
    $selected_types = get_option('algolia_sync_post_types', []);
    if (!in_array($post->post_type, $selected_types)) {
        return;
    }

    // Delete from Algolia when moved to trash
    algolia_sync_delete_post($post_id);
}
add_action('wp_trash_post', 'algolia_sync_on_trash');

// Function to manually sync a specific post
function algolia_sync_manual_sync($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('invalid_post', 'Post not found');
    }

    // Get API credentials
    $app_id = get_option('algolia_app_id');
    $api_key = get_option('algolia_api_key');
    $index_name = algolia_sync_get_index_name($post->post_type);

    if (empty($app_id) || empty($api_key)) {
        return new WP_Error('missing_credentials', 'Algolia credentials not configured');
    }

    // Prepare comprehensive data
    $data = algolia_sync_prepare_post_data($post_id, $post);

    $url = "https://{$app_id}-dsn.algolia.net/1/indexes/{$index_name}";

    $response = wp_remote_post($url, [
        'headers' => [
            'X-Algolia-API-Key' => $api_key,
            'X-Algolia-Application-Id' => $app_id,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([$data]),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code === 200) {
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        return new WP_Error('sync_failed', "Sync failed with status {$status_code}: {$body}");
    }
}

// Function to delete a post from Algolia
function algolia_sync_delete_post($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return;
    }
    
    $app_id = get_option('algolia_app_id');
    $api_key = get_option('algolia_api_key');
    $index_name = algolia_sync_get_index_name($post->post_type);

    if (empty($app_id) || empty($api_key)) {
        error_log('Algolia Sync: Missing API credentials for delete operation.');
        return;
    }

    $url = "https://{$app_id}-dsn.algolia.net/1/indexes/{$index_name}/{$post_id}";

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => [
            'X-Algolia-API-Key' => $api_key,
            'X-Algolia-Application-Id' => $app_id
        ],
        'timeout' => 15,
        'blocking' => false
    ]);

    if (is_wp_error($response)) {
        error_log("Algolia Sync: Failed to delete post {$post_id} - " . $response->get_error_message());
        return;
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            error_log("Algolia Sync: Successfully deleted post {$post_id} from Algolia");
        } else {
            error_log("Algolia Sync: Failed to delete post {$post_id} - Status: {$status_code}");
        }
        return;
    }
}
add_action('before_delete_post', 'algolia_sync_delete_post');

// Function to sync all posts of selected types
function algolia_sync_all_posts() {
    $selected_types = get_option('algolia_sync_post_types', []);
    if (empty($selected_types)) {
        return new WP_Error('no_types_selected', 'No post types selected for sync');
    }

    $app_id = get_option('algolia_app_id');
    $api_key = get_option('algolia_api_key');

    if (empty($app_id) || empty($api_key)) {
        return new WP_Error('missing_credentials', 'Algolia credentials not configured');
    }

    $total_synced = 0;
    $errors = [];

    // Sync each post type to its own index
    foreach ($selected_types as $post_type) {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'numberposts' => -1
        ]);

        if (empty($posts)) {
            continue;
        }

        $index_name = algolia_sync_get_index_name($post_type);
        $data_batch = [];
        
        foreach ($posts as $post) {
            $data_batch[] = algolia_sync_prepare_post_data($post->ID, $post);
        }

        $url = "https://{$app_id}-dsn.algolia.net/1/indexes/{$index_name}/batch";

        $response = wp_remote_post($url, [
            'headers' => [
                'X-Algolia-API-Key' => $api_key,
                'X-Algolia-Application-Id' => $app_id,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode(['requests' => array_map(function($data) {
                return ['action' => 'addObject', 'body' => $data];
            }, $data_batch)]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            $errors[] = "Failed to sync {$post_type}: " . $response->get_error_message();
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 200) {
                $total_synced += count($data_batch);
            } else {
                $body = wp_remote_retrieve_body($response);
                $errors[] = "Failed to sync {$post_type} (Status: {$status_code}): {$body}";
            }
        }
    }

    if (!empty($errors)) {
        return new WP_Error('partial_sync_failed', 'Some syncs failed: ' . implode('; ', $errors));
    }

    return $total_synced;
}
