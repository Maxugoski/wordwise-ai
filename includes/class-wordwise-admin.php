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
        $plugin_dir = plugin_dir_url(dirname(__FILE__));
        $logo = esc_url($plugin_dir . 'assets/images/wordwise-ai-logo.png');
        ?>
        <div class="wrap">
            <div class="max-w-6xl mx-auto p-6">
                <div class="flex items-center space-x-4 mb-6">
                    <?php
                    // Debug info
                    if (current_user_can('manage_options')) {
                        echo "<!-- Logo URL: " . $logo . " -->\n";
                        echo "<!-- Plugin Dir: " . plugin_dir_url(dirname(__FILE__)) . " -->\n";
                    }
                    ?>
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
            // Save model selection
            if (isset($_POST['wordwise_ai_model'])) {
                $model = sanitize_text_field($_POST['wordwise_ai_model']);
                if ($model === 'custom' && !empty($_POST['wordwise_ai_custom_model'])) {
                    $model = sanitize_text_field($_POST['wordwise_ai_custom_model']);
                }
                update_option('wordwise_ai_model', $model);
            }
            // If the user left model on Auto (empty) attempt to auto-detect best model from the API key
            $saved_model = get_option('wordwise_ai_model', '');
            if (empty($saved_model)) {
                $api_key_try = sanitize_text_field($_POST['wordwise_ai_api_key']);
                if (!empty($api_key_try)) {
                    $list_models_url = "https://generativelanguage.googleapis.com/v1/models?key={$api_key_try}";
                    $models_response = wp_remote_get($list_models_url);
                    if (!is_wp_error($models_response)) {
                        $models_data = json_decode(wp_remote_retrieve_body($models_response), true);
                        if (!empty($models_data['models']) && is_array($models_data['models'])) {
                            $available = array_map(function($m){ return preg_replace('#^models/#','', $m['name']); }, $models_data['models']);
                            $preferred = [
                                'gemini-pro-latest',
                                'gemini-2.5-pro',
                                'gemini-2.5-flash',
                                'gemini-flash-latest',
                                'gemini-pro'
                            ];
                            $picked = null;
                            foreach ($preferred as $p) {
                                if (in_array($p, $available, true)) { $picked = $p; break; }
                            }
                            if (is_null($picked)) { $picked = reset($available); }
                            if (!empty($picked)) {
                                update_option('wordwise_ai_model', $picked);
                                $saved_model = $picked;
                                echo '<div class="updated"><p>Saved. Auto-selected model: ' . esc_html($picked) . '.</p></div>';
                            }
                        }
                    }
                }
            } else {
                echo '<div class="updated"><p>Saved.</p></div>';
            }
        }
        $key = esc_attr(get_option('wordwise_ai_api_key', ''));
        $saved_model = esc_attr(get_option('wordwise_ai_model', ''));
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
                    <tr>
                        <th>Model</th>
                        <td>
                            <?php
                            $options = [
                                '' => 'Auto (detect best available)',
                                'gemini-pro-latest' => 'gemini-pro-latest',
                                'gemini-2.5-pro' => 'gemini-2.5-pro',
                                'gemini-2.5-flash' => 'gemini-2.5-flash',
                                'gemini-flash-latest' => 'gemini-flash-latest',
                                'gemini-2.0-pro-exp' => 'gemini-2.0-pro-exp',
                                'gemini-2.0-flash' => 'gemini-2.0-flash',
                                'custom' => 'Custom model (enter below)'
                            ];
                            ?>
                            <select name="wordwise_ai_model">
                                <?php foreach ($options as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($saved_model, $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose a model or leave on Auto to let the plugin pick the best available model for your key.</p>
                            <p class="description">Custom model name: <input type="text" name="wordwise_ai_custom_model" value="<?php echo $saved_model && $saved_model !== '' && !isset($options[$saved_model]) ? $saved_model : ''; ?>" class="regular-text" placeholder="e.g. gemini-2.5-pro"/></p>
                        </td>
                    </tr>
                </table>
                <p><input type="submit" name="wwai_save_key" class="button button-primary" value="Save Key"/></p>
            </form>
        </div>
        <?php
    }
}

new Wordwise_Admin();
