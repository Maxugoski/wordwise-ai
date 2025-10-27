<?php
/**
 * Plugin Name: WordWise AI
 * Description: WordWise AI — Your intelligent content companion for WordPress.
 * Version: 1.0.0
 * Author: Ugochukwu Ogoke
 * Author URI: https://maxugoski.github.io/Portfolio/
 * Text Domain: wordwise-ai
 */

if (!defined('ABSPATH')) exit;

define('WORDWISE_AI_DIR', plugin_dir_path(__FILE__));
define('WORDWISE_AI_URL', plugin_dir_url(__FILE__));

require_once WORDWISE_AI_DIR . 'includes/class-wordwise-admin.php';
require_once WORDWISE_AI_DIR . 'includes/class-wordwise-api.php';

add_action('admin_enqueue_scripts', function($hook){
    if (strpos($hook, 'toplevel_page_wordwise-ai') !== false || strpos($hook, 'post.php') !== false || strpos($hook, 'post-new.php') !== false) {
        wp_enqueue_style('wordwise-tailwind', 'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css', [], null);
        wp_enqueue_script('wordwise-admin-js', WORDWISE_AI_URL . 'assets/js/chat.js', ['jquery'], filemtime(WORDWISE_AI_DIR . 'assets/js/chat.js'), true);
        wp_localize_script('wordwise-admin-js', 'WordWiseAI', ['ajax_url' => admin_url('admin-ajax.php')]);
    }
});
