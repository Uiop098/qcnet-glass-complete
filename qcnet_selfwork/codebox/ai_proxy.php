<?php
/**
 * ai_proxy.php — stateless per-request proxy to whichever AI provider the
 * person picked in Settings. The API key travels with every request from
 * the browser and is NEVER written to disk, logged, or reused across
 * requests — it lives only in the person's own browser (localStorage).
 *
 * REQUEST BODY
 *   provider      "anthropic" | "openai" | "groq" | "openrouter" | "gemini"
 *   api_key       the person's own key for that provider
 *   model         optional — falls back to a sensible per-provider default
 *   code          current editor content (sent fresh every request)
 *   filename, ext current file's name/extension (preloaded context)
 *   message       the person's chat message
 *   history       array of {role, content} — prior turns in this conversation
 *   attached_file optional {name, content} — extra context files the person
 *                 added to the AI panel, kept separate from the live code
 *
 * RESPONSE  {ok:true, reply, provider, model}
 * The reply is free text. If the model proposes a change it's instructed to
 * wrap the full new file in a fenced code block whose first line names the
 * target file — app.js parses that into an Apply/Reject diff card.
 */

require __DIR__ . '/common.php';

$in = cb_input();

$provider = strtolower(trim((string)($in['provider'] ?? '')));
$apiKey   = preg_replace('/[^\x21-\x7E]/', '', (string)($in['api_key'] ?? '')); // strip whitespace/invisible chars from copy-paste
$model    = trim((string)($in['model'] ?? ''));
$code     = (string)($in['code'] ?? '');
$filename = trim((string)($in['filename'] ?? ''));
$ext      = trim((string)($in['ext'] ?? ''));
$message  = trim(mb_substr((string)($in['message'] ?? ''), 0, 6000));
$historyIn = is_array($in['history'] ?? null) ? $in['history'] : [];
$attached  = is_array($in['attached_file'] ?? null) ? $in['attached_file'] : null;

if ($apiKey === '') cb_error('Missing API key. Add one in Settings — it stays on your device.');
if ($message === '') cb_error('Missing message.');

// Defaults used only when the client doesn't send a model (e.g. an old
// cached page before the model field existed). Provider lineups change
// over time — Groq's previous default here ("llama-3.3-70b-versatile")
// stopped working ("model does not exist"), which is exactly the
// failure mode a hardcoded default is prone to. The client's Settings
// panel now has a Model field with a few current picks plus free-text
// entry, so a future rename here shouldn't fully break things again.
$PROVIDERS = [
    'anthropic'  => 'claude-sonnet-4-6',
    'openai'     => 'gpt-4o-mini',
    'groq'       => 'llama-3.1-8b-instant',
    'openrouter' => 'openrouter/auto',
    'gemini'     => 'gemini-1.5-flash',
];
if (!isset($PROVIDERS[$provider])) cb_error('Unknown AI provider: ' . $provider);
if ($model === '') $model = $PROVIDERS[$provider];

/* ---------------------------- system prompt ---------------------------- */
$systemPrompt = "You are the AI coding assistant embedded in QuickChat Code Box, a browser-based IDE.\n"
    . 'The person is currently looking at ' . ($filename !== '' ? "\"$filename\"" . ($ext !== '' ? " ($ext)" : '') : 'no open file') . ".\n"
    . "When you propose a code change, ALWAYS put the FULL new file contents in a single fenced code "
    . "block (``` with a language tag), and make the very first line inside the fence a comment naming "
    . "the exact file path, e.g. `// FILE: src/App.tsx` (use that language's own comment syntax — `# FILE: ...` "
    . "for Python, `<!-- FILE: ... -->` for HTML/Markdown). Only touch files relevant to the request. "
    . "If you're just answering a question or explaining something, reply in plain text with no code fence.";
if ($code !== '') {
    $systemPrompt .= "\n\nCurrent contents of \"$filename\":\n```" . $ext . "\n" . mb_substr($code, 0, 6000) . "\n```";
}
if ($attached && !empty($attached['content'])) {
    $name = trim((string)($attached['name'] ?? 'attached context'));
    $systemPrompt .= "\n\nAdditional context (\"$name\"):\n" . mb_substr((string)$attached['content'], 0, 6000);
}

/* ---------------------------- message list ---------------------------- */
$messages = [];
foreach ($historyIn as $turn) {
    if (!is_array($turn)) continue;
    $role = (($turn['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
    $content = trim(mb_substr((string)($turn['content'] ?? ''), 0, 3000));
    if ($content !== '') $messages[] = ['role' => $role, 'content' => $content];
}
$messages[] = ['role' => 'user', 'content' => $message];

/* ---------------------------- dispatch ---------------------------- */
try {
    switch ($provider) {
        case 'anthropic':
            $reply = cb_call_anthropic($apiKey, $model, $systemPrompt, $messages);
            break;
        case 'gemini':
            $reply = cb_call_gemini($apiKey, $model, $systemPrompt, $messages);
            break;
        case 'openai':
            $reply = cb_call_openai_compatible('https://api.openai.com/v1/chat/completions', $apiKey, $model, $systemPrompt, $messages);
            break;
        case 'groq':
            $reply = cb_call_openai_compatible('https://api.groq.com/openai/v1/chat/completions', $apiKey, $model, $systemPrompt, $messages);
            break;
        case 'openrouter':
            $reply = cb_call_openai_compatible('https://openrouter.ai/api/v1/chat/completions', $apiKey, $model, $systemPrompt, $messages, [
                'HTTP-Referer: ' . cb_origin(),
                'X-Title: QuickChat Code Box',
            ]);
            break;
        default:
            cb_error('Unknown AI provider: ' . $provider);
    }
} catch (Throwable $e) {
    cb_error('AI request failed: ' . $e->getMessage(), 502);
}

cb_ok(['reply' => $reply, 'provider' => $provider, 'model' => $model]);

/* ============================================================================
   PROVIDER REQUEST BUILDERS
============================================================================ */

/** Shared by groq / openrouter / openai — all three speak "OpenAI chat completions". */
function cb_call_openai_compatible(string $url, string $apiKey, string $model, string $systemPrompt, array $messages, array $extraHeaders = []): string {
    $payload = [
        'model' => $model,
        'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages),
        'max_tokens' => 1400,
    ];
    $headers = array_merge(['Authorization: Bearer ' . $apiKey], $extraHeaders);
    $data = cb_http_post_json($url, $payload, $headers);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if ($text === null) throw new Exception($data['error']['message'] ?? 'No reply in provider response.');
    return $text;
}

/** Google's Gemini API: key in the query string, "contents"/"model" role naming, systemInstruction field. */
function cb_call_gemini(string $apiKey, string $model, string $systemPrompt, array $messages): string {
    $contents = [];
    foreach ($messages as $m) {
        $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
    }
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
    $payload = [
        'contents' => $contents,
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'generationConfig' => ['maxOutputTokens' => 1400],
    ];
    $data = cb_http_post_json($url, $payload, []);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) throw new Exception($data['error']['message'] ?? 'No reply in provider response.');
    return $text;
}

/** Anthropic's /v1/messages: x-api-key header, system prompt as its own top-level field. */
function cb_call_anthropic(string $apiKey, string $model, string $systemPrompt, array $messages): string {
    $payload = [
        'model' => $model,
        'system' => $systemPrompt,
        'messages' => $messages,
        'max_tokens' => 1400,
    ];
    $headers = ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'];
    $data = cb_http_post_json('https://api.anthropic.com/v1/messages', $payload, $headers);
    $parts = $data['content'] ?? null;
    if (!is_array($parts)) throw new Exception($data['error']['message'] ?? 'No reply in provider response.');
    $text = '';
    foreach ($parts as $p) { if (($p['type'] ?? '') === 'text') $text .= $p['text']; }
    if ($text === '') throw new Exception('Empty reply from Anthropic.');
    return $text;
}

/** Shared cURL wrapper — POSTs JSON, 25s timeout, returns the decoded response array. */
function cb_http_post_json(string $url, array $payload, array $headers): array {
    if (!function_exists('curl_init')) throw new Exception('The server is missing the PHP curl extension.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Network error contacting the AI provider: ' . $err);
    }
    curl_close($ch);
    $data = json_decode($body, true);
    if (!is_array($data)) throw new Exception('The AI provider returned an unreadable response.');
    return $data;
}

function cb_origin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
