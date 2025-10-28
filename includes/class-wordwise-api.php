<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_wwai_send_prompt', 'wwai_send_prompt');
add_action('wp_ajax_wwai_generate_blog_outline', 'wwai_generate_blog_outline');
add_action('wp_ajax_wwai_generate_blog_section', 'wwai_generate_blog_section');
add_action('wp_ajax_wwai_generate_blog_meta', 'wwai_generate_blog_meta');
add_action('wp_ajax_wwai_save_draft', 'wwai_save_draft');

function wwai_detect_model_for_key($api_key) {
    $list_models_url = "https://generativelanguage.googleapis.com/v1/models?key=" . rawurlencode($api_key);
    $models_response = wp_remote_get($list_models_url, ['timeout'=>15]);
    $model = null;
    if (!is_wp_error($models_response)) {
        $body = wp_remote_retrieve_body($models_response);
        $models_data = json_decode($body, true);
        if (!empty($models_data['models']) && is_array($models_data['models'])) {
            $available = array_map(function($m){ return preg_replace('#^models/#', '', $m['name']); }, $models_data['models']);
            $saved_model = get_option('wordwise_ai_model', '');
            if (!empty($saved_model)) {
                $norm = preg_replace('#^models/#', '', $saved_model);
                if (in_array($norm, $available, true)) { $model = $norm; }
            }
            if (is_null($model)) {
                $preferred = ['gemini-pro-latest','gemini-2.5-pro','gemini-2.5-flash','gemini-flash-latest','gemini-pro'];
                foreach ($preferred as $p) { if (in_array($p, $available, true)) { $model = $p; break; } }
            }
            if (is_null($model)) { $model = reset($available); }
        }
    }
    return $model;
}

function wwai_generate_once($api_key, $model, $prompt, $max_tokens = 1024, $temperature = 0.7, $timeout = 60, $retries = 2) {
    $url = "https://generativelanguage.googleapis.com/v1/models/" . rawurlencode($model) . ":generateContent?key=" . rawurlencode($api_key);
    $body = json_encode([
        'contents' => [[ 'parts' => [[ 'text' => $prompt ] ] ]],
        'generationConfig' => [ 'temperature' => $temperature, 'maxOutputTokens' => $max_tokens ]
    ]);

    $attempt = 0;
    while (true) {
        $attempt++;
        $resp = wp_remote_post($url, [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $body, 'timeout' => $timeout ]);

        if (is_wp_error($resp)) {
            $err = $resp->get_error_message();
            if ($attempt <= $retries) {
                // small backoff before retrying
                $backoff = min(8, pow(2, $attempt));
                sleep($backoff);
                continue;
            }
            return ['error' => $err];
        }

        $raw = wp_remote_retrieve_body($resp);
        $code = wp_remote_retrieve_response_code($resp);
        $hdrs = wp_remote_retrieve_headers($resp);
        // normalize headers to simple array for returning (cap size)
        $headers_arr = [];
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) {
                $headers_arr[$k] = is_array($v) ? implode(', ', array_slice($v,0,3)) : $v;
            }
        }

        // 2xx => success
        if ($code >= 200 && $code <= 299) {
            $data = json_decode($raw, true);
            return ['raw'=>$raw,'code'=>$code,'data'=>$data];
        }

        // For 5xx (server) errors, attempt retries if available
        if ($code >= 500 && $code <= 599 && $attempt <= $retries) {
            // honor Retry-After header if present
            $wait = 0;
            if (!empty($headers_arr['retry-after'])) {
                $ra = $headers_arr['retry-after'];
                if (is_numeric($ra)) $wait = intval($ra);
                else {
                    // try to parse HTTP-date
                    $ts = strtotime($ra);
                    if ($ts !== false) $wait = max(0, $ts - time());
                }
            }
            if ($wait <= 0) {
                $wait = min(8, pow(2, $attempt));
            }
            sleep($wait);
            continue;
        }

        // Not retried or retries exhausted — return diagnostics
        $snippet = is_string($raw) ? substr($raw, 0, 1000) : '';
        return ['error' => "HTTP {$code} returned from API.", 'raw' => $raw, 'code' => $code, 'headers' => $headers_arr, 'snippet' => $snippet];
    }
}
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
    // Require user to be able to create/edit posts to use the AI features
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Unauthorized']);
        return;
    }
    // Determine the model to use (respect saved model or auto-detect)
    $model = wwai_detect_model_for_key($api_key);
    if (empty($model)) { wp_send_json_error(['message'=>'No models available for your API key.']); return; }

        // If a template request is present, handle template flows (e.g., blog post outline+expand)
        if (isset($_POST['template']) && $_POST['template'] === 'blog') {
            $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
            $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
            $tone = isset($_POST['tone']) ? sanitize_text_field($_POST['tone']) : 'Informative';
            $length = isset($_POST['length']) ? sanitize_text_field($_POST['length']) : 'medium';
            if (empty($title)) {
                wp_send_json_error(['message'=>'Blog template requires a title.']);
                return;
            }
            // Configure sections and token budgets based on length
            if ($length === 'short') { $sections = 4; $per_section_tokens = 600; $outline_tokens = 300; }
            elseif ($length === 'long') { $sections = 10; $per_section_tokens = 1200; $outline_tokens = 600; }
            else { $sections = 6; $per_section_tokens = 900; $outline_tokens = 400; }

            // Build outline prompt
            $outline_prompt = "Create a detailed numbered outline with {$sections} sections for a blog post titled \"{$title}\".";
            if (!empty($keywords)) $outline_prompt .= " Include the following keywords where appropriate: {$keywords}.";
            $outline_prompt .= " Use the tone: {$tone}. For each section include a short 1-line heading and 2-3 bullet points describing what to cover, and suggest a target word count for that section.";

            $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$api_key}";
            $body = json_encode([
                'contents' => [ [ 'parts' => [ [ 'text' => $outline_prompt ] ] ] ],
                'generationConfig' => [ 'temperature' => 0.3, 'maxOutputTokens' => $outline_tokens ]
            ]);
            $outline_resp = wp_remote_post($url, [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $body, 'timeout' => 30 ]);
            if (is_wp_error($outline_resp)) { wp_send_json_error(['message'=>$outline_resp->get_error_message()]); return; }
            $outline_raw = wp_remote_retrieve_body($outline_resp);
            $outline_data = json_decode($outline_raw, true);
            $outline_text = '';
            if (!empty($outline_data['candidates'][0])) {
                $c = $outline_data['candidates'][0];
                if (isset($c['content']['parts'][0]['text'])) $outline_text = $c['content']['parts'][0]['text'];
                elseif (isset($c['content']['text'])) $outline_text = $c['content']['text'];
                elseif (isset($c['text'])) $outline_text = $c['text'];
            }
            if (empty($outline_text)) {
                wp_send_json_error(['message'=>'Failed to generate outline','raw'=>$outline_raw]);
                return;
            }

            // Parse outline into headings (pick first N non-empty lines as headings)
            $lines = preg_split('/\r?\n/', $outline_text);
            $headings = [];
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                // strip leading numbering like '1.' or '1)'
                $ln = preg_replace('/^\d+\.?\)?\s*/', '', $ln);
                // strip bullets
                $ln = preg_replace('/^[\-\*\s]+/', '', $ln);
                // take up to first dash or ':' as the heading title
                $parts = preg_split('/[:\-–—]/', $ln);
                $h = trim($parts[0]);
                // ignore very short lines
                if (strlen($h) < 3) continue;
                $headings[] = $h;
                if (count($headings) >= $sections) break;
            }
            if (empty($headings)) {
                wp_send_json_error(['message'=>'Could not parse outline headings','outline'=>$outline_text]);
                return;
            }

            // Generate each section
            $sections_out = [];
            foreach ($headings as $idx => $heading) {
                $section_prompt = "Write a well-structured section for a blog post titled \"{$title}\", for the heading: \"{$heading}\".\nTone: {$tone}.";
                if (!empty($keywords)) $section_prompt .= " Include the keywords: {$keywords}.";
                $section_prompt .= " Refer to the overall outline: \n" . $outline_text . "\nWrite clean paragraphs suitable for a blog post. Do not include an outline or table of contents in the section.";

                $body = json_encode([
                    'contents' => [ [ 'parts' => [ [ 'text' => $section_prompt ] ] ] ],
                    'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => $per_section_tokens ]
                ]);
                $sec_resp = wp_remote_post($url, [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $body, 'timeout' => 60 ]);
                if (is_wp_error($sec_resp)) { wp_send_json_error(['message'=>$sec_resp->get_error_message()]); return; }
                $sec_raw = wp_remote_retrieve_body($sec_resp);
                $sec_data = json_decode($sec_raw, true);
                $sec_text = '';
                if (!empty($sec_data['candidates'][0])) {
                    $c = $sec_data['candidates'][0];
                    if (isset($c['content']['parts'][0]['text'])) $sec_text = $c['content']['parts'][0]['text'];
                    elseif (isset($c['content']['text'])) $sec_text = $c['content']['text'];
                    elseif (isset($c['text'])) $sec_text = $c['text'];
                }
                if (empty($sec_text)) {
                    // If a section failed, put a placeholder and continue
                    $sec_text = "[Failed to generate section: {$heading}]";
                }
                $sections_out[] = [ 'heading' => $heading, 'text' => $sec_text ];
            }

            // Assemble final post
            $post = "# " . $title . "\n\n";
            foreach ($sections_out as $s) {
                $post .= "## " . $s['heading'] . "\n\n" . $s['text'] . "\n\n";
            }
            wp_send_json_success(['text'=>$post]);
            return;
        }

    // For simple prompt sends, use a single generation attempt using configured max tokens
    $default_max = intval(get_option('wordwise_ai_max_output_tokens', 2048));
    $res = wwai_generate_once($api_key, $model, $prompt, max(128,$default_max), 0.7, 60);
    if (isset($res['error'])) { wp_send_json_error(['message'=>$res['error']]); return; }
    $raw = $res['raw'];
    $code = $res['code'];
    $data = $res['data'];
    // try to extract text
    $text = '';
    if (!empty($data['candidates'][0])) {
        $c = $data['candidates'][0];
        if (isset($c['content']['parts'][0]['text'])) $text = $c['content']['parts'][0]['text'];
        elseif (isset($c['content']['text'])) $text = $c['content']['text'];
        elseif (isset($c['text'])) $text = $c['text'];
    }
    if (!empty($text)) { wp_send_json_success(['text'=>$text]); return; }
    // fallback diagnostics
    $finish = isset($data['candidates'][0]['finishReason']) ? $data['candidates'][0]['finishReason'] : null;
    $usage = isset($data['usageMetadata']) ? $data['usageMetadata'] : null;
    $msg = 'No valid response from model.' . ($finish ? ' Finish reason: '.$finish.'.' : '');
    wp_send_json_error(['message'=>$msg,'status'=>$code,'raw'=>$raw,'finishReason'=>$finish,'usage'=>$usage]);
}

function wwai_generate_blog_outline() {
    if (!current_user_can('edit_posts')) { wp_send_json_error(['message'=>'Unauthorized']); return; }
    $api_key = wwai_get_api_key(); if (empty($api_key)) { wp_send_json_error(['message'=>'API key not configured']); return; }
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
    $tone = isset($_POST['tone']) ? sanitize_text_field($_POST['tone']) : 'Informative';
    $length = isset($_POST['length']) ? sanitize_text_field($_POST['length']) : 'medium';
    if (empty($title)) { wp_send_json_error(['message'=>'Title required']); return; }
    $sections = ($length==='short')?4:(($length==='long')?10:6);
    $prompt = "Create a detailed numbered outline with {$sections} sections for a blog post titled '{$title}'.";
    if (!empty($keywords)) $prompt .= " Include these keywords: {$keywords}.";
    $prompt .= " Use tone: {$tone}. For each section include a 1-line heading and 2-3 bullet points of what to cover.";
    $model = wwai_detect_model_for_key($api_key); if (empty($model)) { wp_send_json_error(['message'=>'No model']); return; }
    $max = 512; $res = wwai_generate_once($api_key,$model,$prompt,$max,0.3,30);
    if (isset($res['error'])) { wp_send_json_error(['message'=>$res['error']]); return; }
    $data = $res['data']; $outline_text='';
    if (!empty($data['candidates'][0])) { $c = $data['candidates'][0]; if (isset($c['content']['parts'][0]['text'])) $outline_text = $c['content']['parts'][0]['text']; elseif (isset($c['content']['text'])) $outline_text = $c['content']['text']; elseif (isset($c['text'])) $outline_text = $c['text']; }
    if (empty($outline_text)) { wp_send_json_error(['message'=>'No outline returned','raw'=>$res['raw']]); return; }
    // extract headings
    $lines = preg_split('/\r?\n/',$outline_text); $headings=[];
    foreach ($lines as $ln) { $ln=trim($ln); if ($ln==='') continue; $t=preg_replace('/^\d+\.?\)?\s*/','',$ln); $t=preg_replace('/^[\-\*\s]+/','',$t); $parts=preg_split('/[:\-–—]/',$t); $h=trim($parts[0]); if (strlen($h)<3) continue; $headings[]=$h; if (count($headings)>= $sections) break; }
    if (empty($headings)) { wp_send_json_error(['message'=>'Could not parse outline','outline'=>$outline_text]); return; }
    wp_send_json_success(['outline'=>$outline_text,'headings'=>$headings]);
}

function wwai_generate_blog_section() {
    if (!current_user_can('edit_posts')) { wp_send_json_error(['message'=>'Unauthorized']); return; }
    $api_key = wwai_get_api_key(); if (empty($api_key)) { wp_send_json_error(['message'=>'API key not configured']); return; }
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $heading = isset($_POST['heading']) ? sanitize_text_field($_POST['heading']) : '';
    $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
    $tone = isset($_POST['tone']) ? sanitize_text_field($_POST['tone']) : 'Informative';
    $max_tokens = intval(get_option('wordwise_ai_max_output_tokens',900));
    if (empty($title) || empty($heading)) { wp_send_json_error(['message'=>'Missing title or heading']); return; }
    $prompt = "Write a well-structured section for a blog post titled '{$title}' for the heading: '{$heading}'. Tone: {$tone}.";
    if (!empty($keywords)) $prompt .= " Include these keywords: {$keywords}.";
    $prompt .= " Write clean paragraphs suitable for a blog post. Keep it self-contained and do not repeat the heading title.";
    $model = wwai_detect_model_for_key($api_key); if (empty($model)) { wp_send_json_error(['message'=>'No model']); return; }
    $res = wwai_generate_once($api_key,$model,$prompt,$max_tokens,0.4,60);
    if (isset($res['error'])) { wp_send_json_error(['message'=>$res['error']]); return; }
    $data = $res['data']; $text=''; if (!empty($data['candidates'][0])) { $c=$data['candidates'][0]; if (isset($c['content']['parts'][0]['text'])) $text=$c['content']['parts'][0]['text']; elseif (isset($c['content']['text'])) $text=$c['content']['text']; elseif (isset($c['text'])) $text=$c['text']; }
    if (empty($text)) { wp_send_json_error(['message'=>'No section generated','raw'=>$res['raw']]); return; }
    wp_send_json_success(['heading'=>$heading,'text'=>$text]);
}

function wwai_generate_blog_meta() {
    if (!current_user_can('edit_posts')) { wp_send_json_error(['message'=>'Unauthorized']); return; }
    $api_key = wwai_get_api_key(); if (empty($api_key)) { wp_send_json_error(['message'=>'API key not configured']); return; }
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
    $tone = isset($_POST['tone']) ? sanitize_text_field($_POST['tone']) : 'Informative';
    $post_text = isset($_POST['post_text']) ? wp_kses_post($_POST['post_text']) : '';
    if (empty($title) || empty($post_text)) { wp_send_json_error(['message'=>'Missing title or post text']); return; }
    // Ask model to return structured JSON for reliable parsing
    $prompt = "Given the blog post titled '{$title}', produce ONLY a JSON object with these keys:\n" .
              "  - title_variations: an array of 3 short SEO-friendly title suggestions (strings),\n" .
              "  - meta_description: a single meta description ~155 characters (string),\n" .
              "  - excerpt: a short excerpt (40-80 words) (string).\nReturn strictly valid JSON and nothing else. Use tone: {$tone}.";
    if (!empty($keywords)) $prompt .= " Include keywords: {$keywords}.";
    $model = wwai_detect_model_for_key($api_key); if (empty($model)) { wp_send_json_error(['message'=>'No model']); return; }
    $res = wwai_generate_once($api_key,$model,$prompt,512,0.2,30);
    if (isset($res['error'])) { wp_send_json_error(['message'=>$res['error']]); return; }
    $data = $res['data'];
    $meta_text = '';
    if (!empty($data['candidates'][0])) {
        $c = $data['candidates'][0];
        if (isset($c['content']['parts'][0]['text'])) $meta_text = $c['content']['parts'][0]['text'];
        elseif (isset($c['content']['text'])) $meta_text = $c['content']['text'];
        elseif (isset($c['text'])) $meta_text = $c['text'];
    }
    if (empty($meta_text)) { wp_send_json_error(['message'=>'No meta generated','raw'=>$res['raw']]); return; }
    // Try to find and decode JSON in the response
    $meta_json = null;
    $first_brace = strpos($meta_text, '{');
    if ($first_brace !== false) {
        $possible = substr($meta_text, $first_brace);
        $decoded = json_decode($possible, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $meta_json = $decoded;
        }
    }
    if ($meta_json === null) {
        // Last resort: try to parse as lines (best-effort)
        wp_send_json_success(['meta_raw'=>$meta_text]);
        return;
    }
    wp_send_json_success(['meta'=>$meta_json]);
}

function wwai_save_draft() {
    if (!current_user_can('edit_posts')) { wp_send_json_error(['message'=>'Unauthorized']); return; }
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';
    $excerpt = isset($_POST['excerpt']) ? sanitize_text_field($_POST['excerpt']) : '';
    $meta_json = isset($_POST['meta_json']) ? wp_unslash($_POST['meta_json']) : '';
    if (empty($title) || empty($content)) { wp_send_json_error(['message'=>'Title and content required']); return; }
    // If meta JSON provided, try to decode and extract excerpt if not provided
    $meta_arr = null;
    if (!empty($meta_json)) {
        $decoded = json_decode($meta_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $meta_arr = $decoded;
            if (empty($excerpt) && isset($meta_arr['excerpt'])) {
                $excerpt = sanitize_text_field($meta_arr['excerpt']);
            }
        }
    }
    $postarr = ['post_title'=>$title,'post_content'=>$content,'post_excerpt'=>$excerpt,'post_status'=>'draft','post_type'=>'post','post_author'=>get_current_user_id()];
    $post_id = wp_insert_post($postarr);
    if (is_wp_error($post_id) || !$post_id) { wp_send_json_error(['message'=>'Failed to create draft']); return; }
    // Save meta JSON into post meta for later use (admin-only)
    if (!empty($meta_arr) && is_array($meta_arr)) {
        update_post_meta($post_id, 'wordwise_ai_meta', $meta_arr);
        // Also set Yoast meta if available
        if (!empty($meta_arr['meta_description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($meta_arr['meta_description']));
        }
    }
    $edit_link = get_edit_post_link($post_id, ''); wp_send_json_success(['post_id'=>$post_id,'edit_link'=>$edit_link]);
}
