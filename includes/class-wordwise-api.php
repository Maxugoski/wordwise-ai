<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_wwai_send_prompt', 'wwai_send_prompt');
function wwai_get_api_key() {
    if (defined('WORDWISE_GEMINI_KEY') && !empty(WORDWISE_GEMINI_KEY)) return WORDWISE_GEMINI_KEY;
    return get_option('wordwise_ai_api_key', '');
}
function wwai_send_prompt() {
    $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
    $prompt = sanitize_textarea_field($prompt);
    $api_key = wwai_get_api_key();
    if (empty($api_key)) {
        wp_send_json_error(['message'=>'API key not configured. Add it in Settings → WordWise AI.']);
    }
    // Fetch available models and pick a sensible default
    $list_models_url = "https://generativelanguage.googleapis.com/v1/models?key={$api_key}";
    $models_response = wp_remote_get($list_models_url);
    $model = null;
    if (!is_wp_error($models_response)) {
        $models_data = json_decode(wp_remote_retrieve_body($models_response), true);
        if (!empty($models_data['models']) && is_array($models_data['models'])) {
            $available = array_map(function($m){
                return preg_replace('#^models/#', '', $m['name']);
            }, $models_data['models']);
            // If the user selected a model in settings, prefer that (if available)
            $saved_model = get_option('wordwise_ai_model', '');
            if (!empty($saved_model)) {
                // normalize
                $norm = preg_replace('#^models/#', '', $saved_model);
                if (in_array($norm, $available, true)) {
                    $model = $norm;
                }
            }
            // Preferred order if no saved model or saved model not available
            if (is_null($model)) {
                $preferred = [
                    'gemini-pro-latest',
                    'gemini-2.5-pro',
                    'gemini-2.5-flash',
                    'gemini-flash-latest',
                    'gemini-pro'
                ];
                foreach ($preferred as $p) {
                    if (in_array($p, $available, true)) { $model = $p; break; }
                }
            }
            if (is_null($model)) {
                // fallback to first available model (strip any prefix)
                $model = reset($available);
            }
        }
    }

    if (empty($model)) {
        wp_send_json_error(['message' => 'No models available for your API key.']);
        return;
    }

    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$api_key}";
    $body = json_encode([
        'contents' => [
            [ 'parts' => [ [ 'text' => $prompt ] ] ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1024
        ]
    ]);

    $response = wp_remote_post($url, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body' => $body,
        'timeout' => 60
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message'=>$response->get_error_message()]);
    }
    $raw = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode($raw, true);
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        wp_send_json_success(['text'=>$data['candidates'][0]['content']['parts'][0]['text']]);
    } else {
        // Include HTTP status code and raw body to aid debugging
        wp_send_json_error(['message'=>'No valid response','status'=>$code,'raw'=>$raw]);
    }
}
