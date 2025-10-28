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
                                <div class="flex items-center space-x-2">
                                    <button id="wwai-template-btn" class="px-3 py-2 bg-blue-500 text-white rounded">Templates</button>
                                    <button id="wwai-send" class="px-4 py-2 bg-green-500 text-white rounded">Send</button>
                                </div>
                            </div>
                            <div id="wwai-template-form" class="mt-3 p-3 bg-gray-50 border rounded" style="display:none;">
                                <h3 class="text-sm font-bold mb-2">Blog post template</h3>
                                <p class="mb-2"><label>Title<br/><input id="wwai-blog-title" class="w-full border rounded px-2 py-1" placeholder="Post title (required)"/></label></p>
                                <p class="mb-2"><label>Keywords (comma separated)<br/><input id="wwai-blog-keywords" class="w-full border rounded px-2 py-1" placeholder="e.g. developer experience, productivity"/></label></p>
                                <p class="mb-2"><label>Tone<br/><select id="wwai-blog-tone" class="w-full border rounded px-2 py-1"><option>Informative</option><option>Casual</option><option>Professional</option><option>Persuasive</option></select></label></p>
                                <p class="mb-2"><label>Length<br/><select id="wwai-blog-length" class="w-full border rounded px-2 py-1"><option value="short">Short (approx 600 words)</option><option value="medium" selected>Medium (approx 1200 words)</option><option value="long">Long (approx 2000+ words)</option></select></label></p>
                                <p><button id="wwai-blog-generate" class="button button-primary">Generate Blog Post</button> <button id="wwai-blog-cancel" class="button">Cancel</button></p>
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
                // Save max tokens and retries
                if (isset($_POST['wordwise_ai_max_output_tokens'])) {
                    update_option('wordwise_ai_max_output_tokens', intval($_POST['wordwise_ai_max_output_tokens']));
                }
                if (isset($_POST['wordwise_ai_retries'])) {
                    update_option('wordwise_ai_retries', intval($_POST['wordwise_ai_retries']));
                }
            // If Test button was clicked, perform a quick generation
            if (isset($_POST['wwai_test'])) {
                $test_key = sanitize_text_field($_POST['wordwise_ai_api_key']);
                $test_model = get_option('wordwise_ai_model', '');
                $test_max = intval(get_option('wordwise_ai_max_output_tokens', 1024));
                if (empty($test_key)) {
                    echo '<div class="error"><p>Please enter an API key to test.</p></div>';
                } else {
                    // Attempt to fetch models if model is empty
                    if (empty($test_model)) {
                        $list_models_url = "https://generativelanguage.googleapis.com/v1/models?key=" . rawurlencode($test_key);
                        $models_response = wp_remote_get($list_models_url, ['timeout'=>15]);
                        if (!is_wp_error($models_response)) {
                            $models_data = json_decode(wp_remote_retrieve_body($models_response), true);
                            if (!empty($models_data['models']) && is_array($models_data['models'])) {
                                $available = array_map(function($m){ return preg_replace('#^models/#','', $m['name']); }, $models_data['models']);
                                $preferred = [
                                    'gemini-pro-latest', 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-pro'
                                ];
                                $picked = null;
                                foreach ($preferred as $p) { if (in_array($p, $available, true)) { $picked = $p; break; } }
                                if (is_null($picked)) { $picked = reset($available); }
                                if (!empty($picked)) { $test_model = $picked; update_option('wordwise_ai_model', $picked); }
                            }
                        }
                    }
                    $url = "https://generativelanguage.googleapis.com/v1/models/" . rawurlencode($test_model) . ":generateContent?key=" . rawurlencode($test_key);
                    $body = json_encode([
                        'contents' => [[ 'parts' => [[ 'text' => 'Write a short 3-paragraph blog post about the importance of good developer experience.' ] ] ] ],
                        'generationConfig' => ['temperature'=>0.5,'maxOutputTokens'=>$test_max]
                    ]);
                    $resp = wp_remote_post($url, ['headers'=>['Content-Type'=>'application/json'],'body'=>$body,'timeout'=>30]);
                    if (is_wp_error($resp)) {
                        echo '<div class="error"><p>Test failed: ' . esc_html($resp->get_error_message()) . '</p></div>';
                    } else {
                        $raw = wp_remote_retrieve_body($resp);
                        $data = json_decode($raw, true);
                        $extracted = '';
                        if (!empty($data['candidates'][0]['content']['parts'][0]['text'])) $extracted = $data['candidates'][0]['content']['parts'][0]['text'];
                        elseif (!empty($data['candidates'][0]['content']['text'])) $extracted = $data['candidates'][0]['content']['text'];
                        elseif (!empty($data['candidates'][0]['text'])) $extracted = $data['candidates'][0]['text'];
                        if (!empty($extracted)) {
                            echo '<div class="updated"><p>Test succeeded — sample response:</p><div style="background:#fff;padding:10px;border:1px solid #ddd;white-space:pre-wrap;">' . esc_html($extracted) . '</div></div>';
                        } else {
                            echo '<div class="error"><p>Test completed but no text returned. Raw response: </p><pre style="max-height:200px;overflow:auto;background:#fff;border:1px solid #ddd;">' . esc_html($raw) . '</pre></div>';
                        }
                    }
                }
            }
            // If the user left model on Auto (empty) attempt to auto-detect best model from the API key
            $saved_model = get_option('wordwise_ai_model', '');
            if (empty($saved_model)) {
                $api_key_try = sanitize_text_field($_POST['wordwise_ai_api_key']);
                if (!empty($api_key_try)) {
                    $list_models_url = "https://generativelanguage.googleapis.com/v1/models?key=" . rawurlencode($api_key_try);
                    $models_response = wp_remote_get($list_models_url, ['timeout'=>15]);
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
        $saved_max = esc_attr(get_option('wordwise_ai_max_output_tokens', 1024));
        $saved_retries = esc_attr(get_option('wordwise_ai_retries', 1));
        ?>
        <div class="wrap">
            <h1>WordWise AI Settings</h1>
                        </td>
                    </tr>
                    <tr>
                        <th>Max output tokens</th>
                        <td>
                            <input type="number" name="wordwise_ai_max_output_tokens" value="<?php echo $saved_max; ?>" class="small-text" min="64" max="8192" />
                            <p class="description">Maximum tokens to allow the model to generate (increase for longer blog posts).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Retries on MAX_TOKENS</th>
                        <td>
                            <input type="number" name="wordwise_ai_retries" value="<?php echo $saved_retries; ?>" class="small-text" min="0" max="3" />
                            <p class="description">If generation hits the token limit, retry with a larger maxOutputTokens up to this many times.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Test API</th>
                        <td>
                            <p>
                                <button type="submit" name="wwai_test" class="button">Test API / Model</button>
                                <span class="description">Run a quick sample generation using the selected key and model.</span>
                            </p>
                        </td>
                    </tr>
            <form method="post">
                <?php wp_nonce_field('wwai_save_key'); ?>
                <p>
                    <input type="submit" name="wwai_save_key" class="button button-primary" value="Save Key"/>
                </p>
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
