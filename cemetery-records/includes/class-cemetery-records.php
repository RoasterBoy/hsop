<?php

class Cemetery_Records {

    public function init() {
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_post_meta'), 10, 2);
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function register_post_type() {
        $labels = array('name' => 'Cemetery Records', 'singular_name' => 'Cemetery Record');
        $args = array(
            'labels' => $labels,
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'has_archive' => true,
            'supports' => array('title', 'thumbnail', 'revisions'),
            'menu_icon' => 'dashicons-book',
            'rewrite' => array('slug' => 'cemetery-records'),
        );
        register_post_type('cemetery_record', $args);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=cemetery_record',
            'Import/Export',
            'Import/Export',
            'manage_options', // Using the standard administrator capability
            'cemetery-records-import-export',
            array($this, 'render_import_export_page')
        );
    }

    public function render_import_export_page() {
        if (class_exists('Cemetery_Records_Import_Export')) {
            $import_export = new Cemetery_Records_Import_Export();
            $import_export->render_page();
        } else {
            echo '<div class="wrap"><h1>Error</h1><p>The Import/Export class could not be found.</p></div>';
        }
    }

    public function add_meta_boxes() {
        add_meta_box('cemetery_record_details', 'Details', array($this, 'render_details_meta_box'), 'cemetery_record');
    }

    public function render_details_meta_box($post) {
        wp_nonce_field('cemetery_record_save', 'cemetery_nonce');
        $plot_number = get_post_meta($post->ID, '_plot_number', true);
        $plot_data = get_post_meta($post->ID, '_plot_data', true);
        ?>
        <p>
            <label for="_plot_number"><strong>Plot Number:</strong></label><br>
            <input type="text" id="_plot_number" name="_plot_number" value="<?php echo esc_attr($plot_number); ?>" style="width:100%;">
        </p>
        <p>
            <label for="_plot_data"><strong>Plot Data:</strong></label><br>
            <input type="text" id="_plot_data" name="_plot_data" value="<?php echo esc_attr($plot_data); ?>" style="width:100%;">
        </p>
        <?php
    }

    public function save_post_meta($post_id, $post) {
        if ('cemetery_record' !== $post->post_type || !isset($_POST['cemetery_nonce']) || !wp_verify_nonce($_POST['cemetery_nonce'], 'cemetery_record_save') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $fields = ['_plot_number', '_plot_data'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
}