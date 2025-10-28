<?php
if (!defined('ABSPATH')) exit;

class Wordwise_Admin {
    public function __construct() {
        add_action('admin_menu', [$this,'add_menu']);
    }

    public function add_menu() {
        add_menu_page('WordWise AI', 'WordWise AI', 'manage_options', 'wordwise-ai', [$this,'render_page'], 'dashicons-admin-comments', 56);
        add_submenu_page('wordwise-ai', 'Settings', 'Settings', 'manage_options', 'wordwise-ai-settings', [$this,'render_settings']);
    }

    public function render_page() {
        $logo = esc_url(plugins_url('/assets/images/wordwise-ai-logo.png', dirname(__FILE__) . '/../'));
        ?>
        <div class="wrap">
            <div class="max-w-6xl mx-auto p-6">
                <div class="flex items-center space-x-4 mb-6">
                    <img src="<?php echo $logo; ?>" alt="WordWise AI" class="w-16 h-16 rounded"/>
                    <div>
                        <h1 class="text-2xl font-bold">WordWise AI</h1>
                        <p class="text-sm text-gray-600">Think Smarter. Write Better.</p>
                    </div>
                </div>
                <div id="wwai-app" class="bg-white rounded-lg shadow p-4">
                    <div class="flex space-x-4">
                        <div class="w-64 border-r pr-4">
                            <button id="wwai-new" class="w-full mb-2 px-3 py-2 bg-green-500 text-white rounded">New Chat</button>
                            <div id="wwai-history" class="space-y-2"></div>
                        </div>
                        <div class="flex-1">
                            <div id="wwai-messages" class="h-64 overflow-auto p-3 border rounded mb-3 bg-gray-50"></div>
                            <div class="flex space-x-2">
                                <input id="wwai-input" class="flex-1 border rounded px-3 py-2" placeholder="Type your prompt or paste content..."/>
                                <button id="wwai-send" class="px-4 py-2 bg-green-500 text-white rounded">Send</button>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-4">Built with ❤️ by <a href="https://maxugoski.github.io/Portfolio/" target="_blank">Ugochukwu Ogoke</a></p>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['wwai_save_key'])) {
            check_admin_referer('wwai_save_key');
            update_option('wordwise_ai_api_key', sanitize_text_field($_POST['wordwise_ai_api_key']));
            echo '<div class="updated"><p>Saved.</p></div>';
        }
        $key = esc_attr(get_option('wordwise_ai_api_key', ''));
        ?>
        <div class="wrap">
            <h1>WordWise AI Settings</h1>
            <form method="post">
                <?php wp_nonce_field('wwai_save_key'); ?>
                <table class="form-table">
                    <tr>
                        <th>Gemini API Key</th>
                        <td><input type="password" name="wordwise_ai_api_key" value="<?php echo $key; ?>" class="regular-text"/></td>
                    </tr>
                </table>
                <p><input type="submit" name="wwai_save_key" class="button button-primary" value="Save Key"/></p>
            </form>
        </div>
        <?php
    }
}

new Wordwise_Admin();
