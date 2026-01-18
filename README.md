# php-llmscan

Generate `llms.txt` and LLM-friendly documentation from any website — using only standard PHP.

## Why It Was Created

Many websites now serve content to both humans and large language models (LLMs). The llmstxt.org standard proposes a simple way to help LLMs find clean, factual, technical documentation: a root-level `/llms.txt` file that links to machine-readable Markdown versions of key pages.

While Python-based tools exist, they often require complex dependencies, virtual environments, or elevated server access — making them impractical on shared hosting, which powers most WordPress and small-business sites.

`php-llmscan` was created to solve this: a lightweight, dependency-free tool that runs on standard PHP 8.0+ with only `curl`, `json`, and `PCRE` — all enabled by default on nearly every web host.

No Python. No pip. No hassle.

## What It Does

Given a sitemap URL, `php-llmscan`:

- Fetches all pages listed in your `sitemap.xml`
- Filters out non-technical content (marketing, legal, blog posts, contact pages, etc.)
- Uses AI (OpenAI or DeepSeek) to:
  - Convert valid pages into clean, neutral Markdown (`.html.md`)
  - Generate a one-sentence factual description for each
- Saves `.html.md` files to a configured public directory
- Generates a compliant `llms.txt` file in your site root, per the llmstxt.org specification

The result is a fully compliant, LLM-ready documentation layer — deployable on any PHP host.

## How It Works

1. **Configuration:** Define your sitemap, API keys, paths, and project metadata in `llms-config.php`.
2. **Discovery:** The script parses your `sitemap.xml` to find candidate URLs.
3. **Relevance Check:** Each page is sent to an AI model with a strict prompt:


Only “YES” pages proceed.

4. **Markdown Generation:** Clean, marketing-free Markdown is generated from the page body.
5. **Description Writing:** A short, factual summary is created for `llms.txt`.
6. **Output:**
- `.html.md` files are saved to your public `llms_output_dir`
- `llms.txt` is written to your `web_root`, with correct relative links (e.g., `/llms2/page.html.md`)
- All processing respects your server layout — no hardcoded paths.

## Requirements

- PHP 8.0 or higher
- `curl`, `json`, and `PCRE` extensions (enabled by default in most hosts)
- An OpenAI or DeepSeek API key
- A publicly accessible `sitemap.xml`

## Quick Start

These files should be outside of your web-accessible path (i.e., not in `public_html`).
1. Clone or download this repository.
2. Copy `llms-config.php.example` to `llms-config.php` and edit your settings.
3. Rename `llms-config.php.example` to `llms-config.php` and edit accordingly. See `config-readme.md` for details.
4. Place your API key in secure files (e.g., `/private/keys/oai-api.php`).  
Sample `oai-api.php.example` and `ds-api.php.example` files are included. Rename these files to remove the '.example' 

Run from CLI or a cron job:


php generate-llms.php

## Sample of Files Generated

/server/path/yoursite.com/llms.txt
/server/path/yoursite.com/llms/some-page.html.md
/server/path/yoursite.com/llms/some-other-page.html.md

## CRON 
+ Tip: Schedule via cron for automatic updates after content changes.

## Example Cron Schedule

Run every Sunday at 2:30 AM:
30 2 * * 0 /usr/bin/php /path/to/php-llmscan/generate-llms.php

- This tool strictly follows the llmstxt.org specification:
- Neutral, factual tone
- No marketing, pricing, or CTAs
- Machine-readable Markdown
- Proper llms.txt structure with H1, blockquote, and H2 sections

## License
This project is licensed under the MIT License — free to use, modify, and distribute, even commercially.
See LICENSE for full terms.

## Author
- Built by Enterrahost
- Website: https://enterrahost.com/php-llmscan [https://enterrahost.com/php-llmscan]
- GitHub: https://github.com/enterrahost/php-llmscan [https://github.com/enterrahost/php-llmscan]

We build tools that make WordPress, MyBB, and eCommerce smarter, faster, and more automated — without bloat.
