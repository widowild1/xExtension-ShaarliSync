<?php

class ShaarliSyncExtension extends Minz_Extension {

    public function init() {
        $this->registerHook('entries_favorite', [$this, 'onEntriesFavorite']);
    }

    private function translate(string $key, string $fallback = ''): string {
        $s = _t('ext.shaarlisync.' . $key);
        return ($s !== 'ext.shaarlisync.' . $key && !empty($s)) ? $s : $fallback;
    }

    public function handleConfigureAction() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->setUserConfigurationValue('shaarli_url', rtrim((string)($_POST['shaarli_url'] ?? ''), '/'));
            $this->setUserConfigurationValue('shaarli_secret', (string)($_POST['shaarli_secret'] ?? ''));
            $this->setUserConfigurationValue('shaarli_tags', (string)($_POST['shaarli_tags'] ?? ''));
            $this->setUserConfigurationValue('shaarli_private', !empty($_POST['shaarli_private']));
            $this->setUserConfigurationValue('include_rss_tags', !empty($_POST['include_rss_tags']));
            $this->setUserConfigurationValue('archive_wayback', !empty($_POST['archive_wayback']));

            $this->setUserConfigurationValue('ai_provider', (string)($_POST['ai_provider'] ?? 'none'));
            $this->setUserConfigurationValue('ai_api_key', (string)($_POST['ai_api_key'] ?? ''));
            $this->setUserConfigurationValue('ai_model', trim((string)($_POST['ai_model'] ?? '')));
            $this->setUserConfigurationValue('ai_language', (string)($_POST['ai_language'] ?? 'français'));
            $this->setUserConfigurationValue('ai_tone', (string)($_POST['ai_tone'] ?? 'synthétique, factuel et direct (style TL;DR)'));
            $this->setUserConfigurationValue('ai_tag_count', max(1, min(15, (int)($_POST['ai_tag_count'] ?? 4))));
            $this->setUserConfigurationValue('ai_generate_summary', !empty($_POST['ai_generate_summary']));
            $this->setUserConfigurationValue('ai_generate_tags', !empty($_POST['ai_generate_tags']));
            $this->setUserConfigurationValue('fetch_full_content', !empty($_POST['fetch_full_content']));

            $this->setUserConfigurationValue('ntfy_enabled', !empty($_POST['ntfy_enabled']));
            $this->setUserConfigurationValue('ntfy_url', rtrim((string)($_POST['ntfy_url'] ?? 'https://ntfy.sh'), '/'));
            $this->setUserConfigurationValue('ntfy_topic', (string)($_POST['ntfy_topic'] ?? ''));
            $this->setUserConfigurationValue('ntfy_token', trim((string)($_POST['ntfy_token'] ?? '')));

            if (!empty($_POST['test_config'])) {
                $report = $this->runSelfDiagnostics();
                if (class_exists('Minz_Session')) {
                    Minz_Session::_param('shaarlisync_test_report', $report);
                }
            }
        }
    }

    public function onEntriesFavorite($ids, $is_favorite) {
        if (!$is_favorite || empty($ids)) {
            return;
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        if (function_exists('fastcgi_finish_request')) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        set_time_limit(120);

        $shaarli_url = $this->getUserConfigurationValue('shaarli_url');
        $shaarli_secret = $this->getUserConfigurationValue('shaarli_secret');

        if (empty($shaarli_url) || empty($shaarli_secret)) {
            Minz_Log::warning('ShaarliSync: Configuration Shaarli incomplète.');
            return;
        }

        $entryDao = FreshRSS_Factory::createEntryDao();

        $enableSummary = $this->getUserConfigurationValue('ai_generate_summary');
        $shouldSummary = ($enableSummary === null || $enableSummary === '' || $enableSummary);

        $enableTags = $this->getUserConfigurationValue('ai_generate_tags');
        $shouldTags = ($enableTags === null || $enableTags === '' || $enableTags);

        $includeRssTags = $this->getUserConfigurationValue('include_rss_tags');
        $shouldIncludeRssTags = ($includeRssTags === null || $includeRssTags === '' || $includeRssTags);

        $fetchFull = $this->getUserConfigurationValue('fetch_full_content');
        $archiveWayback = !empty($this->getUserConfigurationValue('archive_wayback'));

        $aiProvider = $this->getUserConfigurationValue('ai_provider') ?: 'none';
        $aiKey = $this->getUserConfigurationValue('ai_api_key');
        $hasAi = ($aiProvider !== 'none' && !empty($aiKey));

        foreach ($ids as $id) {
            $entry = $entryDao->searchById($id);
            if ($entry === null) {
                continue;
            }

            $url = $entry->link();
            $title = $entry->title();
            $contentHtml = $entry->content();

            $imageUrl = '';
            if (preg_match('/<img[^>]+src=["'](https?:\/\/[^"']+)["']/i', $contentHtml, $mImg)) {
                $imageUrl = $mImg[1];
            }

            $raw_text = trim(strip_tags($contentHtml));
            $raw_text = preg_replace('/\s+/', ' ', $raw_text);

            if ($hasAi && $fetchFull && mb_strlen($raw_text) < 400 && !empty($url)) {
                $scraped = $this->scrapePageText($url);
                if (mb_strlen($scraped) > mb_strlen($raw_text)) {
                    $raw_text = $scraped;
                }
            }
            $content_sample = mb_substr($raw_text, 0, 4500);

            $rawTags = $this->getUserConfigurationValue('shaarli_tags') ?? 'freshrss, favoris';
            $tags = array_filter(array_map('trim', explode(',', $rawTags)));

            if ($shouldIncludeRssTags && method_exists($entry, 'tags')) {
                $rssTags = $entry->tags();
                if (is_array($rssTags)) {
                    foreach ($rssTags as $rt) {
                        $c = $this->cleanTag((string)$rt);
                        if ($c !== '') {
                            $tags[] = $c;
                        }
                    }
                }
            }

            $aiSummaryText = '';
            if ($hasAi) {
                $aiData = $this->processWithAI($title, $content_sample);
                if ($aiData !== null) {
                    if ($shouldSummary && !empty($aiData['summary'])) {
                        $aiSummaryText = trim($aiData['summary']);
                    }
                    if ($shouldTags && !empty($aiData['tags'])) {
                        $rawAiTags = $aiData['tags'];
                        if (is_string($rawAiTags)) {
                            $rawAiTags = explode(',', $rawAiTags);
                        }
                        if (is_array($rawAiTags)) {
                            foreach ($rawAiTags as $t) {
                                $c = $this->cleanTag((string)$t);
                                if ($c !== '') {
                                    $tags[] = $c;
                                }
                            }
                        }
                    }
                }
            }

            $tags = array_values(array_unique($tags));

            $archiveFooter = '';
            if ($archiveWayback && !empty($url)) {
                $wayback = "https://web.archive.org/web/*/" . urlencode($url);
                $archivedLabel = $this->translate('archived_version', 'Version Archivée (Wayback Machine)');
                $archiveFooter = "\n\n---\n🌐 [{$archivedLabel}]({$wayback})";
            }

            if (!empty($aiSummaryText)) {
                $quotedSummary = preg_replace('/^/m', '> ', $aiSummaryText);
                $summaryLabel = $this->translate('ai_summary_prefix', 'Résumé IA :');
                $description = "> 🤖 **{$summaryLabel}**\n" . $quotedSummary . $archiveFooter;
            } else {
                $excerpt = mb_strlen($raw_text) > 350 ? mb_substr($raw_text, 0, 347) . '...' : $raw_text;
                $description = (!empty($excerpt) ? $excerpt : $title) . $archiveFooter;
            }

            $success = $this->postToShaarli($shaarli_url, $shaarli_secret, [
                'url' => $url,
                'title' => $title,
                'description' => $description,
                'tags' => $tags,
                'private' => (bool)$this->getUserConfigurationValue('shaarli_private'),
            ]);

            if ($success) {
                $notificationBody = !empty($aiSummaryText) ? $aiSummaryText : (mb_strlen($raw_text) > 200 ? mb_substr($raw_text, 0, 197) . '...' : $raw_text);
                if (empty($notificationBody)) {
                    $notificationBody = $this->translate('notif_default', 'Favori ajouté :') . ' ' . $title;
                }
                $this->sendNtfy($title, $url, $notificationBody, $shaarli_url, $imageUrl);
            }
        }
    }

    private function cleanTag(string $tag): string {
        $tag = trim(ltrim(trim($tag), '#'));
        $tag = preg_replace('/[^\p{L}\p{N}_-]/u', '', $tag);
        return mb_strtolower($tag);
    }

    private function scrapePageText(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            return '';
        }

        $clean = preg_replace('/<(script|style)[^>]*?>.*?<\/\\1>/si', '', $html);
        $text = trim(strip_tags($clean));
        return preg_replace('/\s+/', ' ', $text);
    }

    private function processWithAI(string $title, string $content): ?array {
        $provider = $this->getUserConfigurationValue('ai_provider');
        $apiKey = $this->getUserConfigurationValue('ai_api_key');

        if ($provider === 'none' || empty($apiKey)) {
            return null;
        }

        $contentToAnalyze = !empty($content) ? $content : $title;
        $rawLanguage = $this->getUserConfigurationValue('ai_language') ?: 'français';
        $rawTone = $this->getUserConfigurationValue('ai_tone') ?: 'synthétique, factuel et direct (style TL;DR)';
        $tagCount = (int)($this->getUserConfigurationValue('ai_tag_count') ?: 4);

        $langMap = [
            'français' => 'French',
            'anglais' => 'English',
            'allemand' => 'German',
            'espagnol' => 'Spanish',
            'chinois' => 'Simplified Chinese',
            'arabe' => 'Arabic',
            'italien' => 'Italian',
            'portugais' => 'Portuguese',
            "la langue d'origine de l'article" => 'the original language of the article',
        ];
        $targetLang = $langMap[$rawLanguage] ?? $rawLanguage;

        $toneMap = [
            'synthétique, factuel et direct (style TL;DR)' => 'concise, factual and direct (TL;DR style)',
            'structuré sous forme de 2 à 3 points clés avec tirets (-)' => 'structured as 2 to 3 concise bullet points (-)',
            'technique, précis et orienté experts' => 'technical, precise, and expert-oriented',
            'vulgarisé, clair et accessible' => 'simplified, clear, and educational',
        ];
        $targetTone = $toneMap[$rawTone] ?? $rawTone;

        $prompt = "You are an expert content curator and archivist.\n"
                . "Analyze the article and respond STRICTLY with a valid JSON object without Markdown ticks or surrounding commentary, having exactly two keys:\n"
                . "1. \"summary\" : a clear summary written strictly in {$targetLang}. The tone must be {$targetTone}.\n"
                . "2. \"tags\" : a JSON array of exactly {$tagCount} relevant thematic keywords in lowercase, without spaces or punctuation (e.g. [\"linux\", \"docker\"]).\n\n"
                . "Title: {$title}\n"
                . "Content: {$contentToAnalyze}";

        $rawResponse = null;

        if ($provider === 'openai') {
            $model = $this->getUserConfigurationValue('ai_model') ?: 'gpt-4o-mini';
            $rawResponse = $this->callOpenAI($apiKey, $model, $prompt);
        } elseif ($provider === 'gemini') {
            $model = $this->getUserConfigurationValue('ai_model');
            if (empty($model) || in_array($model, ['gemini-1.5-flash', 'gemini-2.5-flash', 'gemini-1.0-pro'])) {
                $model = 'gemini-3.5-flash-lite';
            }
            $rawResponse = $this->callGemini($apiKey, $model, $prompt);
        }

        if (!$rawResponse) {
            return null;
        }

        $cleanJson = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawResponse));
        $decoded = json_decode($cleanJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function callOpenAI(string $apiKey, string $model, string $prompt): ?string {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $res) {
            $data = json_decode($res, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }

        Minz_Log::warning("ShaarliSync [OpenAI $code] : " . $res);
        return null;
    }

    private function callGemini(string $apiKey, string $model, string $prompt): ?string {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
            ]
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $res) {
            $data = json_decode($res, true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        }

        Minz_Log::warning("ShaarliSync [Gemini $code] : " . $res);
        return null;
    }

    private function generateShaarliJwt(string $secret): string {
        $base64Url = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $header = $base64Url(json_encode(['typ' => 'JWT', 'alg' => 'HS512']));
        $payload = $base64Url(json_encode(['iat' => time()]));
        $signature = hash_hmac('sha512', "$header.$payload", $secret, true);
        $sigB64 = $base64Url($signature);

        return "$header.$payload.$sigB64";
    }

    private function postToShaarli(string $baseUrl, string $secret, array $payload): bool {
        $jwt = $this->generateShaarliJwt($secret);
        $url = $baseUrl . '/api/v1/links';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            return true;
        }

        if ($httpCode === 400 && stripos($response, 'already exists') !== false) {
            return true;
        }

        Minz_Log::error("ShaarliSync: Erreur HTTP $httpCode lors de l'envoi à Shaarli : " . $response);
        return false;
    }

    private function sendNtfy(string $title, string $clickUrl, string $body = '', string $shaarliUrl = '', string $imageUrl = ''): void {
        if (!$this->getUserConfigurationValue('ntfy_enabled')) {
            return;
        }

        $ntfyUrl = $this->getUserConfigurationValue('ntfy_url') ?: 'https://ntfy.sh';
        $topic = $this->getUserConfigurationValue('ntfy_topic');
        $token = $this->getUserConfigurationValue('ntfy_token');

        if (empty($ntfyUrl) || empty($topic)) {
            return;
        }

        $endpoint = rtrim($ntfyUrl, '/') . '/' . urlencode($topic);

        $headers = [
            'Title: ' . $title,
            'Click: ' . $clickUrl,
            'Tags: robot,star,bookmark',
            'Priority: 3',
        ];

        $btnRead = $this->translate('action_read', 'Lire l\'article');
        $btnShaarli = $this->translate('action_shaarli', 'Voir sur Shaarli');

        $actions = "view, {$btnRead}, " . $clickUrl;
        if (!empty($shaarliUrl)) {
            $actions .= "; view, {$btnShaarli}, " . rtrim($shaarliUrl, '/') . '/';
        }
        $headers[] = 'Actions: ' . $actions;

        if (!empty($imageUrl)) {
            $headers[] = 'Attach: ' . $imageUrl;
        }

        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => !empty($body) ? $body : ($this->translate('notif_default', 'Favori ajouté :') . " " . $title),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public function runSelfDiagnostics(): array {
        $logs = [];
        $allOk = true;

        $shaarliUrl = $this->getUserConfigurationValue('shaarli_url');
        $shaarliSecret = $this->getUserConfigurationValue('shaarli_secret');

        if (empty($shaarliUrl) || empty($shaarliSecret)) {
            $logs[] = "❌ Shaarli : URL / Secret missing.";
            $allOk = false;
        } else {
            $jwt = $this->generateShaarliJwt($shaarliSecret);
            $ch = curl_init($shaarliUrl . '/api/v1/info');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $jwt],
                CURLOPT_TIMEOUT => 5,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $logs[] = "✅ Shaarli : OK (HTTP 200 - JWT Validated).";
            } else {
                $logs[] = "❌ Shaarli : Connection failed (HTTP $code).";
                $allOk = false;
            }
        }

        $provider = $this->getUserConfigurationValue('ai_provider') ?: 'none';
        $apiKey = $this->getUserConfigurationValue('ai_api_key');

        if ($provider === 'none') {
            $logs[] = "ℹ️ AI : Disabled.";
        } elseif (empty($apiKey)) {
            $logs[] = "ℹ️ AI ($provider) : No API Key provided (ignored).";
        } else {
            $aiData = $this->processWithAI("Configuration test", "Testing AI connection and multilingual processing.");
            if ($aiData !== null && !empty($aiData['summary'])) {
                $tagsPreview = implode(', ', $aiData['tags'] ?? []);
                $logs[] = "✅ AI ($provider) : OK. Generated tags: [$tagsPreview].";
            } else {
                $logs[] = "⚠️ AI ($provider) : Call failed. Check API key or model name.";
            }
        }

        if ($this->getUserConfigurationValue('ntfy_enabled')) {
            $ntfyUrl = $this->getUserConfigurationValue('ntfy_url') ?: 'https://ntfy.sh';
            $topic = $this->getUserConfigurationValue('ntfy_topic');
            if (empty($topic)) {
                $logs[] = "⚠️ ntfy : Enabled but no topic configured.";
            } else {
                $this->sendNtfy("ShaarliSync Diagnostic", $shaarliUrl ?: $ntfyUrl, "Push test successful!", $shaarliUrl);
                $logs[] = "✅ ntfy : Test notification pushed to topic '$topic'.";
            }
        } else {
            $logs[] = "ℹ️ ntfy : Disabled.";
        }

        return ['ok' => $allOk, 'logs' => $logs];
    }
}
