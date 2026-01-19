# php-llmscan Configuration Guide

This file (`llms-config.php`) configures **php-llmscan**, a lightweight PHP tool that generates [llmstxt.org](https://llmstxt.org)-compliant documentation from your website’s sitemap using AI (OpenAI or DeepSeek). It runs on standard shared hosting with no external dependencies beyond PHP 8.0+ and common extensions.

Rename `llms-config.php.example` to `llms-config.php` and customize the values below.

---

## Configuration Options

### Input
| Key | Type | Description |
|-----|------|-------------|
| `sitemap_url` | string | The full URL to your site’s `sitemap.xml` (e.g., `https://example.com/sitemap.xml`). Must be publicly accessible. |

### API Keys
> **Security Tip**: Store API keys outside your web root (e.g., in `/private/keys/`) and never commit them to version control.

| Key | Type | Description |
|-----|------|-------------|
| `deepseek_api_key_file` | string | Path to a PHP file that returns `['api_key' => 'your-deepseek-key']`. Only used if `ai_engine` is `'deepseek'`. |
| `openai_api_key_file` | string | Path to a PHP file that returns `['api_key' => 'your-openai-key']`. Only used if `ai_engine` is `'openai'`. |

> ample key files (`ds-api.php`, `oai-api.php`) are included in the repository.

### AI Engine
| Key | Type | Description |
|-----|------|-------------|
| `ai_engine` | string | Choose `'openai'` or `'deepseek'`. Determines which API is used for content analysis and Markdown generation. |

### Output Paths
| Key | Type | Description |
|-----|------|-------------|
| `web_root` | string | Absolute path to your website’s document root. The generated `llms.txt` will be placed here. |
| `llms_output_dir` | string | Absolute path where cleaned `.html.md` files will be saved (e.g., `/var/www/example.com/llms`). This directory must be web-accessible. |

### Metadata (for `llms.txt`)
| Key | Type | Description |
|-----|------|-------------|
| `project_name` | string | A short, neutral name for your project (e.g., “My Plugin Docs”). Appears in the `llms.txt` header. |
| `project_summary` | string | A one-sentence factual summary of what your site provides. Avoid marketing language. |
| `site_url` | string | The base URL of your website (e.g., `https://example.com`). Used to construct links in `llms.txt`. |
| `use_absolute_urls` | bool | If `true`, `llms.txt` uses full URLs (e.g., `https://example.com/llms/page.html.md`). If `false`, it uses relative paths (e.g., `/llms/page.html.md`). |
| `regenerate_after_days` | string | Numrical value in days - Default is 90 |
| `skip_non_technical_cache` | bool |  If `true` non technical files marked as .not_technical.html.md will not be reprocessed - Default is True |

### HTTP & Logging
| Key | Type | Description |
|-----|------|-------------|
| `user_agent` | string | The User-Agent string sent when fetching pages. Helps identify your bot to servers. |
| `log_mode` | string | One of: `'none'`, `'console'`, `'file'`, or `'both'`. Controls where diagnostic messages appear. |
| `log_file` | string | Absolute path to the log file (used only if `log_mode` includes `'file'`). Ensure PHP has write permissions. |


### How to Run ### 

To generate your LLM-ready documentation:

From the Command Line (CLI)

Make sure you’re in the php-llmscan directory and run:

php generate-llms.php

Ensure the PHP CLI binary matches your web server version (e.g., php8.2 instead of just php on some systems).

###  Permissions ### 

Ensure the following directories are writable by the PHP process:

*   Your llms\_output\_dir (e.g., /var/www/example.com/llms)
    
*   Your web\_root (so llms.txt can be written)
    

### Scheduling with Cron ### 

To keep your LLM documentation up to date automatically, schedule generate-llms.php via cron.

Weekly (Every Sunday at 2:30 AM)

30 2 \* \* 0 /usr/bin/php /path/to/php-llmscan/generate-llms.php >> /var/log/php-llmscan-cron.log 2>&1

Monthly (First Day of Month at 3:00 AM)

0 3 1 \* \* /usr/bin/php /path/to/php-llmscan/generate-llms.php >> /var/log/php-llmscan-cron.log 2>&1

Replace /usr/bin/php with your actual PHP CLI path (find it with which php). 
Replace /path/to/php-llmscan/ with the absolute path to your tool.  
The >> ... 2>&1 part appends both output and errors to a log file for debugging.

