=== WordWise AI ===
Contributors: maxugoski
Tags: ai, generative, content, blog, seo
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordWise AI integrates Google's Generative Language (Gemini) into WordPress to help authors and editors
generate high-quality content, outlines, SEO meta, and draft posts from the admin UI.

== Description ==

WordWise AI provides a lightweight admin interface for generating content using your Gemini API key.
It includes:

- A chat-like prompt box for ad-hoc content generation.
- A built-in Blog Post template: outline → section expansion → SEO meta → Save to Draft.
- Automatic model detection (uses ListModels) with a manual model override in settings.
- Configurable max output tokens and retry behavior for more reliable long-form generation.
- Safe handling and diagnostics for API errors (including 5xx responses and Retry-After support).

This plugin is intended to speed up drafting and ideation workflows for content teams. It stores AI-generated
metadata in post meta (`wordwise_ai_meta`) and can populate Yoast meta description where applicable.

== Installation ==

1. Upload the `wordwise-ai` folder to the `/wp-content/plugins/` directory, or install via the WordPress plugin installer if packaged.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Visit WordPress admin → WordWise AI → Settings and enter your Gemini API key.
4. Optionally select a model or leave on Auto to let the plugin pick the best available model for your key.
5. Use the main WordWise AI page to prompt the model or use the Blog template to generate drafts.

== Usage ==

- Quick prompts: Type any prompt in the main input and press Send. Results appear in the conversation pane.
- Blog template: Open Templates → Blog post, enter a title, keywords, tone and length, then Generate.
	The plugin will create an outline, expand sections sequentially, produce SEO meta suggestions, and let you
	review and save the result as a draft.
- Save to Draft: After review, click Save to Draft to create a WordPress draft. The plugin stores structured
	meta in `wordwise_ai_meta` and sets `_yoast_wpseo_metadesc` if a meta_description is present.

== Settings ==

- Gemini API Key: Your Google Generative Language API key (keeps your key in the WP options table).
- Model: Auto-detect or choose a specific Gemini model (e.g. `gemini-2.5-pro`).
- Max output tokens: Controls how many tokens the model may return (increase for longer content).
- Retries on MAX_TOKENS / 5xx: Number of automatic retries for server errors or token-limit issues.
- Test API: Run a quick sample generation to verify the key and model configuration.

== Frequently Asked Questions ==

= Why am I getting "No valid response" or HTTP 503? =
The plugin now implements retries and diagnostics. A transient 503 will be retried automatically. If retries
are exhausted the admin UI will show the HTTP status, a short raw response snippet and headers (including
Retry-After when available). Check your Google Cloud quota, billing, and API region if errors persist.

= Who can use the generation features? =
Users with the `edit_posts` capability (authors, editors, admins) can generate content and save drafts. Only
users with `manage_options` can change plugin settings.

= Is my API key safe? =
The key is stored in the WordPress options table. Treat it like a secret and rotate it if compromised. You may
also define the constant `WORDWISE_GEMINI_KEY` in `wp-config.php` to avoid storing the key in the database.

== Screenshots ==

1. Main admin UI with chat and templates (see `assets/images/wordwise-ai-logo.png`).
2. Settings page with API key, model selector and Test API button.

== Changelog ==

= 0.1.0 =
* Initial release
* Features: quick prompts, blog template, model auto-detect, settings and Save to Draft

== Troubleshooting ==

- "The link you followed has expired." when saving settings: re-login and try again — the plugin adds a nonce
	to the settings form. If the problem persists, check server `post_max_size`, `upload_max_filesize`, or security
	modules (mod_security) that may block POSTs.
- If a generation frequently hits token limits, increase Max output tokens or use the Blog template outline/section
	approach which reduces per-call size.

== Developers ==

Source: https://github.com/Maxugoski/wordwise-ai

Contributions and issues are welcome. When contributing, please follow the repository's contribution guide.
