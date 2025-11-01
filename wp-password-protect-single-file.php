<?php
/**
 * Plugin Name:       WP Password Protect (All-in-One)
 * Description:       Password protect posts and pages with full customization. All code is in this single file.
 * Version:           1.1.0
 * Author:            Copilot
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-password-protect-aio
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// --- Class: Admin Settings ---
class WPP_AIO_Admin_Settings {
    private $plugin_slug;

    public function __construct( $plugin_slug ) {
        $this->plugin_slug = $plugin_slug;
    }

    public function add_options_page() {
        add_options_page(
            'WP Password Protect Settings', 'WP Password Protect', 'manage_options',
            $this->plugin_slug, array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h2>WP Password Protect Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpp_aio_option_group' );
                do_settings_sections( $this->plugin_slug );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting( 'wpp_aio_option_group', 'wpp_aio_settings' );
        add_settings_section( 'wpp_aio_main_section', 'Customization', null, $this->plugin_slug );
        add_settings_field( 'form_background_color', 'Form Background Color', array( $this, 'color_picker_callback' ), $this->plugin_slug, 'wpp_aio_main_section', ['id' => 'form_background_color'] );
        add_settings_field( 'form_font_color', 'Form Font Color', array( $this, 'color_picker_callback' ), $this->plugin_slug, 'wpp_aio_main_section', ['id' => 'form_font_color'] );
        add_settings_section( 'wpp_aio_terms_section', 'Terms Popup', null, $this->plugin_slug );
        add_settings_field( 'terms_copy', 'Terms Popup Copy', array( $this, 'textarea_callback' ), $this->plugin_slug, 'wpp_aio_terms_section', ['id' => 'terms_copy'] );
        add_settings_section( 'wpp_aio_addons_section', 'Add-ons', null, $this->plugin_slug );
        add_settings_field( 'enable_terms_popup', 'Enable "View Terms" Popup', array( $this, 'checkbox_callback' ), $this->plugin_slug, 'wpp_aio_addons_section', ['id' => 'enable_terms_popup'] );
    }

    public function color_picker_callback($args) {
        $options = get_option( 'wpp_aio_settings' );
        $value = isset( $options[$args['id']] ) ? $options[$args['id']] : '';
        echo "<input type='text' name='wpp_aio_settings[{
        $args['id']}]' value='{$value}' class='wpp-color-picker' />";
    }

    public function textarea_callback($args) {
        $options = get_option( 'wpp_aio_settings' );
        $value = isset( $options[$args['id']] ) ? $options[$args['id']] : '';
        echo "<textarea name='wpp_aio_settings[{
        $args['id']}]' rows='5' cols='50'>" . esc_textarea($value) . "</textarea>";
    }

    public function checkbox_callback($args) {
        $options = get_option( 'wpp_aio_settings' );
        $checked = isset( $options[$args['id']] ) ? checked( 1, $options[$args['id']], false ) : '';
        echo "<input type='checkbox' name='wpp_aio_settings[{
        $args['id']}]' value='1' {$checked} />";
    }
}

// --- Class: Content Protection ---
class WPP_AIO_Content_Protection {
    public function add_meta_box() {
        add_meta_box( 'wpp_aio_password_protect_meta_box', 'Password Protection', array( $this, 'render_meta_box' ), ['post', 'page'], 'side', 'default' );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'wpp_aio_save_meta_box_data', 'wpp_aio_meta_box_nonce' );
        $password = get_post_meta( $post->ID, '_wpp_aio_password', true );
        $enabled = get_post_meta( $post->ID, '_wpp_aio_enabled', true );
        echo '<label for="wpp_aio_enabled">Enable Protection:</label> ';
        echo '<input type="checkbox" id="wpp_aio_enabled" name="wpp_aio_enabled" value="1"' . checked( 1, $enabled, false ) . ' /><br/><br/>';
        echo '<label for="wpp_aio_password">Password:</label> ';
        echo '<input type="text" id="wpp_aio_password" name="wpp_aio_password" value="' . esc_attr( $password ) . '" size="25" />';
    }

    public function save_meta_box( $post_id ) {
        if ( !isset( $_POST['wpp_aio_meta_box_nonce'] ) || !wp_verify_nonce( $_POST['wpp_aio_meta_box_nonce'], 'wpp_aio_save_meta_box_data' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( !current_user_can( 'edit_post', $post_id ) ) return;
        update_post_meta( $post_id, '_wpp_aio_enabled', isset( $_POST['wpp_aio_enabled'] ) ? 1 : 0 );
        update_post_meta( $post_id, '_wpp_aio_password', sanitize_text_field( $_POST['wpp_aio_password'] ) );
    }

    public function protect_content( $content ) {
        global $post;
        if ( !is_singular() || !in_the_loop() || !is_main_query() ) return $content;
        $is_protected = get_post_meta( $post->ID, '_wpp_aio_enabled', true );
        if ( !$is_protected ) return $content;
        if ( isset($_SESSION['wpp_aio_unlocked_' . $post->ID]) && $_SESSION['wpp_aio_unlocked_' . $post->ID] === true ) return $content;
        return $this->get_password_form();
    }

    private function get_password_form() {
        $options = get_option('wpp_aio_settings');
        $enable_terms = !empty($options['enable_terms_popup']);
        $terms_copy = !empty($options['terms_copy']) ? wpautop(esc_html($options['terms_copy'])) : 'Please read and agree to the terms.';
        
        $form_html = '<div class="wpp-aio-password-form-container">
            <p>This content is password protected. To view it please enter your password below:</p>
            <form action="#" method="post" id="wpp-aio-password-form">
                <label for="wpp_aio_password">Password:</label>
                <input name="wpp_aio_password" id="wpp_aio_password" type="password" size="20" />
                <input type="submit" name="Submit" value="Enter" />
                <div class="wpp-aio-error-message" style="display:none;"></div>
            </form>';
        
        if ($enable_terms) {
            $form_html .= '<div class="wpp-aio-terms-agreement">
                <p>
                    <input type="checkbox" id="wpp-aio-agree-terms" /> 
                    <label for="wpp-aio-agree-terms">I have read and agree to the terms.</label> 
                    <a href="#" id="wpp-aio-view-terms">View Terms</a>
                </p>
            </div>';
        }
        $form_html .= '</div>';

        if ($enable_terms) {
            $form_html .= '<div id="wpp-aio-terms-popup">
                <div class="wpp-aio-popup-content">
                    <h3>Terms and Conditions</h3>
                    <div class="wpp-aio-popup-body">' . $terms_copy . '</div>
                    <p>Please check the "I have read and agree to the terms" box to proceed.</p>
                </div>
            </div>';
        }
        return $form_html;
    }

    public function check_password_ajax() {
        if ( !isset($_POST['post_id']) || !isset($_POST['password']) ) wp_send_json_error( 'Invalid request.' );
        $post_id = intval($_POST['post_id']);
        $password = $_POST['password'];
        $custom_password = get_post_meta($post_id, '_wpp_aio_password', true);
        if ( $password === $custom_password && !empty($password) ) {
            $_SESSION['wpp_aio_unlocked_' . $post_id] = true;
            wp_send_json_success( 'Password correct.' );
        } else {
            wp_send_json_error( 'Incorrect password.' );
        }
    }
}

// --- Class: Main Plugin Orchestrator ---
class WP_Password_Protect_AIO {
    protected $plugin_slug = 'wp-password-protect-aio';

    public function __construct() {
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function define_admin_hooks() {
        $admin_settings = new WPP_AIO_Admin_Settings( $this->plugin_slug );
        add_action( 'admin_init', array( $admin_settings, 'register_settings' ) );
        add_action( 'admin_menu', array( $admin_settings, 'add_options_page' ) );
        $content_protection = new WPP_AIO_Content_Protection();
        add_action( 'add_meta_boxes', array( $content_protection, 'add_meta_box' ) );
        add_action( 'save_post', array( $content_protection, 'save_meta_box' ) );
    }

    private function define_public_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        $content_protection = new WPP_AIO_Content_Protection();
        add_filter( 'the_content', array( $content_protection, 'protect_content' ) );
        add_action( 'wp_ajax_wpp_aio_check_password', array( $content_protection, 'check_password_ajax' ) );
        add_action( 'wp_ajax_nopriv_wpp_aio_check_password', array( $content_protection, 'check_password_ajax' ) );
    }

    public function run() {
        if (!session_id()) {
            session_start();
        }
    }

    public function enqueue_assets() {
        // --- Inline CSS ---
        $css = "
        .wpp-aio-password-form-container{padding:20px;border:1px solid #ddd;margin-top:20px;border-radius:5px;text-align:center}
        .wpp-aio-password-form-container p{margin-bottom:15px}
        .wpp-aio-password-form-container input[type=\"password\"]{padding:8px;width:60%;margin-right:10px}
        .wpp-aio-password-form-container input[type=\"submit\"]{padding:8px 15px;cursor:pointer}
        .wpp-aio-error-message{color:#d9534f;margin-top:10px}
        #wpp-aio-terms-popup{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background-color:rgba(0,0,0,0.7);z-index:1000}
        #wpp-aio-terms-popup .wpp-aio-popup-content{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:80%;max-width:600px;padding:20px;border-radius:5px}
        #wpp-aio-terms-popup .wpp-aio-popup-body{max-height:300px;overflow-y:auto;border:1px solid #ccc;padding:15px;margin-bottom:15px}";
        
        $options = get_option('wpp_aio_settings');
        $bg_color = !empty($options['form_background_color']) ? $options['form_background_color'] : '#f1f1f1';
        $font_color = !empty($options['form_font_color']) ? $options['form_font_color'] : '#333';
        $custom_css = "
            .wpp-aio-password-form-container { background-color: {$bg_color}; color: {$font_color}; }
            .wpp-aio-password-form-container input[type='password'], .wpp-aio-password-form-container input[type='submit'] { color: {$font_color}; }
            #wpp-aio-terms-popup .wpp-aio-popup-content { background-color: {$bg_color}; color: {$font_color}; }
        ";
        wp_register_style('wpp-aio-style', false);
        wp_enqueue_style('wpp-aio-style');
        wp_add_inline_style('wpp-aio-style', $css . $custom_css);

        // --- Inline JS ---
        $js = "
        jQuery(document).ready(function($){
            $('#wpp-aio-password-form').on('submit',function(e){
                e.preventDefault();
                var form=$(this),password=form.find('input[name=\"wpp_aio_password\"]').val(),submitButton=form.find('input[type=\"submit\"]'),errorMessage=form.find('.wpp-aio-error-message');
                errorMessage.hide(); submitButton.prop('disabled',true);
                $.post(wpp_aio_ajax.ajax_url,{action:'wpp_aio_check_password',post_id:wpp_aio_ajax.post_id,password:password})
                .done(function(response){
                    if(response.success){location.reload();}
                    else{errorMessage.text(response.data).show();submitButton.prop('disabled',false);}
                })
                .fail(function(){errorMessage.text('An error occurred.').show();submitButton.prop('disabled',false);});
            });
            var termsPopup=$('#wpp-aio-terms-popup'),openTerms=$('#wpp-aio-view-terms'),agreeCheckbox=$('#wpp-aio-agree-terms'),passwordSubmit=$('#wpp-aio-password-form input[type=\"submit\"]');
            if(agreeCheckbox.length){passwordSubmit.prop('disabled',true);}
            openTerms.on('click',function(e){e.preventDefault();termsPopup.show();});
            agreeCheckbox.on('change',function(){passwordSubmit.prop('disabled',!$(this).is(':checked'));});
            termsPopup.on('click',function(e){if($(e.target).is(termsPopup)){termsPopup.hide();}});
        });";
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', $js);
        wp_localize_script('jquery', 'wpp_aio_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'post_id' => get_the_ID()]);
    }
}

// --- Begins execution of the plugin ---
function run_wp_password_protect_aio() {
    $plugin = new WP_Password_Protect_AIO();
    $plugin->run();
}
run_wp_password_protect_aio();
