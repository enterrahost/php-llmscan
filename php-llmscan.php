<?php
/**
 * php-llmscan – LLM Documentation Generator for llmstxt.org compliance
 *
 * Parses a website’s sitemap and uses AI to:
 *   1. Identify pages that contain technical documentation (not marketing, legal, or blog content)
 *   2. Convert them into clean, neutral Markdown (.html.md) files
 *   3. Generate an llms.txt index file compliant with https://llmstxt.org
 *
 * Outputs:
 *   - One .html.md file per valid page in the configured output directory
 *   - A root-level llms.txt file listing all documentation with brief descriptions
 *
 * Designed to run on standard PHP hosting (PHP 8.0+, curl, json, PCRE) — no Python required.
 *
 * Author: Enterrahost
 * Project: https://github.com/enterrahost/php-llmscan
 * Specification: https://llmstxt.org
 */


set_time_limit(0);
error_reporting(E_ALL);
// Prevent execution via web browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    die("Access denied. This tool can only be run from the command line.\n");
}

$configFile = __DIR__ . '/llms-config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Error: Config file missing: $configFile\n");
    exit(1);
}
$config = include $configFile;

// Make config globally available for helper functions
global $config, $logFile;
$logFile = $config['log_file'];

$outputDir = rtrim($config['llms_output_dir'], '/');
$webRoot   = rtrim($config['web_root'], '/');

// Setup directories
foreach ([$outputDir, dirname($logFile)] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        logMessage("Error: Cannot create directory: $dir");
        exit(1);
    }
}

// Load API key
$apiKey = loadApiKey($config);
if (!$apiKey) exit(1);

// Freshness control: skip regenerating .html.md files newer than X days
$regenAfterDays = isset($config['regenerate_after_days']) ? (int)$config['regenerate_after_days'] : 0;
$skipRegen = ($regenAfterDays > 0);

// Trim config values to prevent hidden whitespace issues
$sitemapUrl = trim($config['sitemap_url']);
$userAgent  = trim($config['user_agent']);
$siteUrl    = rtrim(trim($config['site_url']), '/');

logMessage("Starting LLM documentation generation...");
logMessage("Project: " . $config['project_name']);
logMessage("Sitemap: " . $sitemapUrl);

// Fetch sitemap
$xml = fetchUrl($sitemapUrl, $userAgent);
if (!$xml) {
    logMessage("Error: Failed to fetch sitemap");
    exit(1);
}

$urls = [];
if (preg_match_all('#<loc>\s*(https?://[^\s<]+)\s*</loc>#i', $xml, $matches)) {
    $urls = array_unique(array_map('trim', $matches[1]));
    logMessage("Found " . count($urls) . " URLs in sitemap");
} else {
    logMessage("Warning: No <loc> tags found in sitemap");
    exit(1);
}

// Process each URL
// Process each URL
$pageMetadata = []; // [slug => description]

foreach ($urls as $url) {
    logMessage("Evaluating: $url");

    // Generate slug early to check existing file
    $path = parse_url($url, PHP_URL_PATH);
    $slug = trim($path, '/');
    if (empty($slug)) $slug = 'index';
    $slug = preg_replace('/[^a-z0-9\-_\.]/i', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    $filepath = "$outputDir/{$slug}.html.md";

    $techFileExists = file_exists($filepath);
    $notTechFile = "$outputDir/{$slug}.not_technical.html.md";
    $notTechFileExists = file_exists($notTechFile);
    
    if ($skipRegen && ($techFileExists || $notTechFileExists)) {
        // Determine which file to check age against
        $existingFile = $techFileExists ? $filepath : $notTechFile;
        $fileAge = time() - filemtime($existingFile);
        $maxAgeSeconds = $regenAfterDays * 24 * 60 * 60;
    
        if ($fileAge < $maxAgeSeconds) {
            if ($techFileExists) {
                logMessage("Skipping (fresh technical file): {$slug}.html.md (age: " . round($fileAge / 3600, 1) . "h)");
                $pageMetadata[$slug] = 'Technical documentation page (cached).';
            } else {
                logMessage("Skipping (recently marked non-technical): {$slug}.not_technical.html.md (age: " . round($fileAge / 3600, 1) . "h)");
                // Do NOT add to $pageMetadata — it's not documentation
            }
            continue;
        } else {
            logMessage("Cached decision outdated (age: " . round($fileAge / 86400, 1) . "d), re-evaluating: $url");
            // Optional: clean up old marker files (not required)
            if ($techFileExists) unlink($filepath);
            if ($notTechFileExists) unlink($notTechFile);
        }
    }

    // Fetch and process page content
    $html = fetchUrl($url, $userAgent);
    if (!$html) {
        logMessage("Skipping (fetch failed): $url");
        continue;
    }

    // Clean HTML
    $cleanHtml = cleanHtml($html);
    if (empty($cleanHtml)) {
        logMessage("Skipping (no body content): $url");
        continue;
    }

    // STEP 1: Relevance check
    $relevancePrompt = <<<PROMPT
Analyze the following webpage content. Determine if it contains **technical documentation, feature explanations, setup instructions, configuration details, or factual product behavior**.

Exclude pages that are:
- Marketing, sales, or promotional (e.g., "best", "free trial", "100% off", "download now")
- Pricing, testimonials, or calls to action
- Legal (terms, privacy policy, DMCA, refund policy)
- Contact pages, support forms, blog posts, changelogs, FAQs, or "about" pages
- Vague overviews without concrete technical details

Answer ONLY "YES" or "NO".
Do not explain.

Content:
"""
$cleanHtml
"""
PROMPT;

    $isRelevant = callAI($config['ai_engine'], $apiKey, $relevancePrompt, 10);
        if (trim(strtoupper($isRelevant)) !== 'YES') {
            logMessage("Page marked as non-technical: $url");
        
            // Optionally cache the decision
            $skipNonTechCache = $config['skip_non_technical_cache'] ?? true;
            if ($skipNonTechCache) {
                $notTechFile = "$outputDir/{$slug}.not_technical.html.md";
                // Create empty file as a marker
                file_put_contents($notTechFile, '');
                logMessage("Created marker: {$slug}.not_technical.html.md");
            }
        
            continue;
        }

    // STEP 2: Generate clean Markdown
    $markdownPrompt = <<<PROMPT
You are a technical documentation writer. Convert the following content into concise, neutral Markdown for LLM training.

Rules:
- Remove ALL marketing, pricing, CTAs, emotional language, testimonials, and promotional phrases.
- Keep only factual information: features, how it works, inputs/outputs, setup steps, configuration options, technical behavior.
- Use clear headings (#, ##, ###) if structure exists.
- Never invent capabilities or details not present.
- Output ONLY valid Markdown — no intro, no outro, no disclaimers.

Content:
"""
$cleanHtml
"""
PROMPT;

    $markdown = callAI($config['ai_engine'], $apiKey, $markdownPrompt, 2000);
    if (!$markdown) {
        logMessage("AI failed to generate Markdown: $url");
        continue;
    }

    // Clean possible code fences
    $markdown = trim($markdown);
    if (substr($markdown, 0, 3) === '```') {
        $markdown = preg_replace('/^```(?:markdown)?\s*/i', '', $markdown);
        $markdown = preg_replace('/\s*```$/i', '', $markdown);
    }

    // STEP 3: Generate short description
    $descPrompt = <<<PROMPT
Write a single-sentence, factual description of this technical page. Focus on what it does or explains. Avoid fluff, marketing, or subjective claims.

Example: "Adds Google Analytics via ID input without editing theme files."

Page content:
"""
$markdown
"""
PROMPT;

    $description = callAI($config['ai_engine'], $apiKey, $descPrompt, 60, 0.3);
    $description = trim(preg_replace('/[\r\n].*/', '', $description)); // first sentence only

    // Save Markdown (using filepath already computed above)
    file_put_contents($filepath, $markdown);
    logMessage("Saved: {$slug}.html.md");

    $pageMetadata[$slug] = $description ?: 'Technical documentation page.';
}

// === Generate llms.txt ===
$llmsTxt = "# " . $config['project_name'] . "\n";
$llmsTxt .= "> " . $config['project_summary'] . "\n\n";

if (!empty($pageMetadata)) {
    // Compute public path relative to web root
    $publicPath = '';
    if (strpos($outputDir, $webRoot) === 0) {
        $rel = substr($outputDir, strlen($webRoot));
        if ($rel !== '') {
            $publicPath = '/' . ltrim($rel, '/');
        }
    } else {
        $publicPath = '/' . basename($outputDir);
    }

    $useAbsolute = $config['use_absolute_urls'] ?? false;

    $llmsTxt .= "## Documentation\n";
    foreach ($pageMetadata as $slug => $desc) {
        if ($useAbsolute) {
            $link = $siteUrl . $publicPath . '/' . $slug . '.html.md';
        } else {
            $link = ($publicPath === '') 
                ? "/{$slug}.html.md" 
                : "{$publicPath}/{$slug}.html.md";
        }
        $llmsTxt .= "- [{$slug}]({$link}): {$desc}\n";
    }
} else {
    $llmsTxt .= "No technical documentation pages found.\n";
}

file_put_contents("$webRoot/llms.txt", $llmsTxt);
logMessage("llms.txt generated at $webRoot/llms.txt");
logMessage("Done! Processed " . count($pageMetadata) . " documentation pages.");

// ======================
// Helper Functions
// ======================

function loadApiKey($config) {
    $engine = $config['ai_engine'];
    $file = $engine === 'deepseek' ? $config['deepseek_api_key_file'] : $config['openai_api_key_file'];

    if (!file_exists($file)) {
        logMessage("Error: API key file not found: $file");
        return null;
    }

    $data = include $file;
    $key = $data['api_key'] ?? null;
    if (!$key) {
        logMessage("Error: API key missing in $file");
        return null;
    }
    return $key;
}

function fetchUrl($url, $userAgent) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER         => false,
    ]);
    $content = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($code === 200) ? $content : false;
}

function cleanHtml($html) {
    // Remove noisy elements
    $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);
    $html = preg_replace('#<header[^>]*>.*?</header>#is', '', $html);
    $html = preg_replace('#<nav[^>]*>.*?</nav>#is', '', $html);
    $html = preg_replace('#<footer[^>]*>.*?</footer>#is', '', $html);
    $html = preg_replace('#<!--.*?-->#s', '', $html);

    // Try to extract main content
    if (preg_match('#<article[^>]*>(.*?)</article>#is', $html, $m)) {
        $content = $m[1];
    } elseif (preg_match('#<div[^>]*class="[^"]*(?:entry-content|content|main)[^"]*"[^>]*>(.*?)</div>#is', $html, $m)) {
        $content = $m[1];
    } else {
        $content = $html;
    }

    // Allow safe tags for Markdown conversion
    return trim(strip_tags($content, '<p><h1><h2><h3><h4><h5><h6><ul><ol><li><pre><code><table><thead><tbody><tr><td><th><blockquote><strong><em><dl><dt><dd><figure><figcaption>'));
}

function callAI($engine, $apiKey, $prompt, $maxTokens = 1000, $temperature = 0.2) {
    if ($engine === 'deepseek') {
        $url = 'https://api.deepseek.com/chat/completions';
        $model = 'deepseek-coder';
    } elseif ($engine === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        $model = 'gpt-4o-mini';
    } else {
        logMessage("Error: Unsupported AI engine: $engine");
        return false;
    }

    $body = json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logMessage("AI API error ($engine): HTTP $httpCode – " . substr($response, 0, 150));
        return false;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? false;
}

function logMessage($msg) {
    global $logFile, $config;

    $mode = $config['log_mode'] ?? 'both';
    if ($mode === 'none') return;

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";

    if (in_array($mode, ['console', 'both'])) {
        echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        flush(); ob_flush();
    }

    if (in_array($mode, ['file', 'both']) && !empty($logFile)) {
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}