<?php
/*
Plugin Name: Algolia Sync for WordPress
Description: Sync selected post types to Algolia when saving posts.
Version: 1.0
Author: D.K. Himas Khan
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/algolia-push.php';

// Register setting
function algolia_sync_register_settings() {
    // Post types setting
    register_setting('algolia_sync_settings', 'algolia_sync_post_types', [
        'type' => 'array',
        'sanitize_callback' => function($input) {
                if (!is_array($input)) return [];
                return array_map('sanitize_text_field', $input);
            },
        'default' => []
    ]);
    
    // API credentials settings
    register_setting('algolia_sync_settings', 'algolia_app_id', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);
    
    register_setting('algolia_sync_settings', 'algolia_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);
    
}
add_action('admin_init', 'algolia_sync_register_settings');

// Add settings page
function algolia_sync_add_menu() {
    add_options_page(
        'Algolia Sync',
        'Algolia Sync',
        'manage_options',
        'algolia-sync',
        'algolia_sync_render_settings_page'
    );
}
add_action('admin_menu', 'algolia_sync_add_menu');

function algolia_sync_render_settings_page() {
    $post_types = get_post_types(['public' => true], 'objects');
    $selected = get_option('algolia_sync_post_types', []);
    $app_id = get_option('algolia_app_id', '');
    $api_key = get_option('algolia_api_key', '');
    ?>
    <div class="wrap">
        <h1>Algolia Sync Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('algolia_sync_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Algolia Application ID</th>
                    <td>
                        <input type="text" name="algolia_app_id" value="<?php echo esc_attr($app_id); ?>" class="regular-text" />
                        <p class="description">Your Algolia Application ID (found in your Algolia dashboard)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Algolia API Key</th>
                    <td>
                        <input type="password" name="algolia_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                        <p class="description">Your Algolia API Key (Admin API Key recommended for write operations)</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Select Post Types to Sync</th>
                    <td>
                        <?php foreach ($post_types as $type): ?>
                            <label>
                                <input type="checkbox" name="algolia_sync_post_types[]" value="<?php echo esc_attr($type->name); ?>"
                                    <?php checked(in_array($type->name, $selected)); ?>>
                                <?php echo esc_html($type->label); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">Choose which post types should be automatically synced to Algolia when saved. Each post type will be synced to its own separate index (e.g., 'post' → 'posts', 'page' → 'pages').</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <?php if (!empty($app_id) && !empty($api_key)): ?>
        <div class="card">
            <h2>Test Connection</h2>
            <p>Click the button below to test your Algolia connection:</p>
            <button type="button" id="test-algolia-connection" class="button button-secondary">Test Connection</button>
            <div id="test-result" style="margin-top: 10px;"></div>
        </div>
        
        <div class="card">
            <h2>Bulk Operations</h2>
            <p>Sync all existing posts of selected types to Algolia:</p>
            <button type="button" id="sync-all-posts" class="button button-primary">Sync All Posts</button>
            <div id="sync-result" style="margin-top: 10px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-algolia-connection').on('click', function() {
                var button = $(this);
                var resultDiv = $('#test-result');
                
                button.prop('disabled', true).text('Testing...');
                resultDiv.html('');
                
                $.post(ajaxurl, {
                    action: 'test_algolia_connection',
                    nonce: '<?php echo wp_create_nonce('test_algolia_connection'); ?>'
                }, function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                }).fail(function() {
                    resultDiv.html('<div class="notice notice-error"><p>Connection test failed. Please check your settings.</p></div>');
                }).always(function() {
                    button.prop('disabled', false).text('Test Connection');
                });
            });
            
            $('#sync-all-posts').on('click', function() {
                var button = $(this);
                var resultDiv = $('#sync-result');
                
                if (!confirm('This will sync all posts of selected types to Algolia. This may take some time. Continue?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Syncing...');
                resultDiv.html('');
                
                $.post(ajaxurl, {
                    action: 'sync_all_posts',
                    nonce: '<?php echo wp_create_nonce('sync_all_posts'); ?>'
                }, function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                }).fail(function() {
                    resultDiv.html('<div class="notice notice-error"><p>Sync failed. Please check your settings and try again.</p></div>');
                }).always(function() {
                    button.prop('disabled', false).text('Sync All Posts');
                });
            });
        });
        </script>
        <?php endif; ?>
    </div>
    <?php
}

// AJAX handler for testing Algolia connection
function algolia_sync_test_connection() {
    if (!wp_verify_nonce($_POST['nonce'], 'test_algolia_connection')) {
        wp_die('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $app_id = get_option('algolia_app_id');
    $api_key = get_option('algolia_api_key');
    
    if (empty($app_id) || empty($api_key)) {
        wp_send_json_error('Please configure your Algolia credentials first.');
        return;
    }
    
    // Test connection by listing indexes
    $url = "https://{$app_id}-dsn.algolia.net/1/indexes";
    
    $response = wp_remote_get($url, [
        'headers' => [
            'X-Algolia-API-Key' => $api_key,
            'X-Algolia-Application-Id' => $app_id
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json_error('Connection failed: ' . $response->get_error_message());
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            wp_send_json_success('Connection successful! Your Algolia credentials are working correctly.');
        } else {
            wp_send_json_error('Connection failed with status code: ' . $status_code);
        }
    }
}
add_action('wp_ajax_test_algolia_connection', 'algolia_sync_test_connection');

// AJAX handler for bulk sync
function algolia_sync_bulk_sync() {
    if (!wp_verify_nonce($_POST['nonce'], 'sync_all_posts')) {
        wp_die('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Include the algolia-push.php file to access the sync function
    require_once plugin_dir_path(__FILE__) . 'includes/algolia-push.php';
    
    $result = algolia_sync_all_posts();
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success("Successfully synced {$result} posts to Algolia.");
    }
}
add_action('wp_ajax_sync_all_posts', 'algolia_sync_bulk_sync');

// Add admin notices for sync status
function algolia_sync_admin_notices() {
    if (isset($_GET['page']) && $_GET['page'] === 'algolia-sync') {
        $app_id = get_option('algolia_app_id');
        $api_key = get_option('algolia_api_key');
        
        if (empty($app_id) || empty($api_key)) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Algolia Sync:</strong> Please configure your Algolia API credentials in the settings below.</p></div>';
        }
    }
}
add_action('admin_notices', 'algolia_sync_admin_notices');
