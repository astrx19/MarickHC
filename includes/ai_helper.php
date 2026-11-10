<?php
declare(strict_types=1);

if (!function_exists('mh_get_ai_config')) {
    function mh_get_ai_config(): array
    {
        $provider = '';
        if (function_exists('mh_ocr_live_setting')) {
            $provider = trim((string) mh_ocr_live_setting('ai_provider', ''));
        }
        if ($provider === '' && function_exists('mh_setting')) {
            $provider = trim((string) mh_setting('ai_provider', ''));
        }

        $groqKey = '';
        if (function_exists('mh_ocr_live_setting')) {
            $groqKey = trim((string) mh_ocr_live_setting('groq_api_key', ''));
        }
        if ($groqKey === '' && function_exists('mh_setting')) {
            $groqKey = trim((string) mh_setting('groq_api_key', ''));
        }

        $geminiKey = '';
        if (function_exists('mh_ocr_live_setting')) {
            $geminiKey = trim((string) mh_ocr_live_setting('gemini_api_key', ''));
        }
        if ($geminiKey === '' && function_exists('mh_setting')) {
            $geminiKey = trim((string) mh_setting('gemini_api_key', ''));
        }

        $openaiKey = '';
        if (function_exists('mh_ocr_live_setting')) {
            $openaiKey = trim((string) mh_ocr_live_setting('openai_api_key', ''));
        }
        if ($openaiKey === '' && function_exists('mh_setting')) {
            $openaiKey = trim((string) mh_setting('openai_api_key', ''));
        }

        if ($provider === '' || $provider === 'auto') {
            if ($groqKey !== '') {
                $provider = 'groq';
            } elseif ($geminiKey !== '') {
                $provider = 'gemini';
            } elseif ($openaiKey !== '') {
                $provider = 'openai';
            } else {
                $provider = 'groq';
            }
        }

        $apiKey = '';
        $model = '';
        $endpoint = '';

        if ($provider === 'groq') {
            $apiKey = $groqKey;
            $savedModel = function_exists('mh_setting') ? trim((string) mh_setting('ai_model', '')) : '';
            $model = $savedModel !== '' ? $savedModel : 'llama-3.3-70b-versatile';
            $endpoint = defined('GROQ_API_ENDPOINT') ? GROQ_API_ENDPOINT : 'https://api.groq.com/openai/v1/chat/completions';
        } elseif ($provider === 'gemini') {
            $apiKey = $geminiKey;
            $savedModel = function_exists('mh_setting') ? trim((string) mh_setting('ai_model', '')) : '';
            $model = $savedModel !== '' ? $savedModel : 'gemini-2.0-flash';
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        } elseif ($provider === 'openai') {
            $apiKey = $openaiKey;
            $savedModel = function_exists('mh_setting') ? trim((string) mh_setting('ai_model', '')) : '';
            $model = $savedModel !== '' ? $savedModel : 'gpt-4o-mini';
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }

        return [
            'provider' => $provider,
            'api_key'  => $apiKey,
            'model'    => $model,
            'endpoint' => $endpoint,
        ];
    }
}

if (!function_exists('callAiService')) {
    function callAiService(string $prompt, ?string $imageBase64 = null, array $options = []): array
    {
        $config = mh_get_ai_config();
        $provider = $options['provider'] ?? $config['provider'];
        $apiKey = $options['api_key'] ?? $config['api_key'];
        $model = $options['model'] ?? $config['model'];
        $endpoint = $options['endpoint'] ?? $config['endpoint'];
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 20;
        $systemPrompt = $options['system'] ?? 'You are a clinical intelligence and health analytics AI assistant.';
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.2;
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : 800;

        if (empty($apiKey)) {
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'AI provider API key is not configured.',
                'provider' => $provider,
                'model'    => $model,
                'raw'      => null,
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'PHP cURL extension is not installed.',
                'provider' => $provider,
                'model'    => $model,
                'raw'      => null,
            ];
        }

        if ($provider === 'gemini') {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
            $parts = [];
            $fullPrompt = ($systemPrompt !== '') ? ($systemPrompt . "\n\n" . $prompt) : $prompt;
            $parts[] = ['text' => $fullPrompt];

            if ($imageBase64 !== null && $imageBase64 !== '') {
                $rawB64 = $imageBase64;
                $mime = $options['mime'] ?? 'image/jpeg';
                if (strpos($rawB64, 'data:') === 0 && strpos($rawB64, ';base64,') !== false) {
                    $semi = strpos($rawB64, ';base64,');
                    $mime = substr($rawB64, 5, $semi - 5);
                    $rawB64 = substr($rawB64, $semi + 8);
                }
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data'      => $rawB64,
                    ],
                ];
            }

            $payload = [
                'contents' => [
                    ['parts' => $parts],
                ],
                'generationConfig' => [
                    'temperature'     => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ],
            ];

            if (!empty($options['json_mode'])) {
                $payload['generationConfig']['responseMimeType'] = 'application/json';
            }

            $jsonPayload = json_encode($payload);
            if ($jsonPayload === false) {
                return [
                    'success'  => false,
                    'content'  => '',
                    'text'     => '',
                    'error'    => 'Failed to encode request payload.',
                    'provider' => $provider,
                    'model'    => $model,
                    'raw'      => null,
                ];
            }

            $ch = curl_init($url);
            if (!$ch) {
                return [
                    'success'  => false,
                    'content'  => '',
                    'text'     => '',
                    'error'    => 'Failed to initialize cURL.',
                    'provider' => $provider,
                    'model'    => $model,
                    'raw'      => null,
                ];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $curlError !== '') {
                return [
                    'success'  => false,
                    'content'  => '',
                    'text'     => '',
                    'error'    => 'Network error connecting to Gemini: ' . ($curlError ?: 'Connection failed'),
                    'provider' => $provider,
                    'model'    => $model,
                    'raw'      => null,
                ];
            }

            $data = json_decode((string) $response, true);
            if (!is_array($data)) {
                return [
                    'success'  => false,
                    'content'  => '',
                    'text'     => '',
                    'error'    => 'Invalid JSON response from Gemini (HTTP ' . $httpCode . ').',
                    'provider' => $provider,
                    'model'    => $model,
                    'raw'      => null,
                ];
            }

            if ($httpCode >= 400 || !empty($data['error'])) {
                $msg = '';
                if (is_array($data['error'] ?? null)) {
                    $msg = (string) ($data['error']['message'] ?? 'Gemini API error');
                } elseif (!empty($data['error'])) {
                    $msg = (string) $data['error'];
                } else {
                    $msg = 'HTTP ' . $httpCode;
                }
                return [
                    'success'  => false,
                    'content'  => '',
                    'text'     => '',
                    'error'    => 'Gemini error: ' . $msg,
                    'provider' => $provider,
                    'model'    => $model,
                    'raw'      => $data,
                ];
            }

            $content = trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));

            return [
                'success'  => true,
                'content'  => $content,
                'text'     => $content,
                'error'    => null,
                'provider' => $provider,
                'model'    => $model,
                'raw'      => $data,
            ];
        }

        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = [
                'role'    => 'system',
                'content' => $systemPrompt,
            ];
        }

        if ($imageBase64 !== null && $imageBase64 !== '') {
            if ($provider === 'groq') {
                $model = !empty($options['vision_model']) ? $options['vision_model'] : 'qwen/qwen3.8-27b';
            } elseif ($provider === 'openai') {
                $model = !empty($options['vision_model']) ? $options['vision_model'] : 'gpt-4o-mini';
            }

            $mime = $options['mime'] ?? 'image/jpeg';
            if (strpos($imageBase64, 'data:') === 0) {
                $dataUrl = $imageBase64;
            } else {
                $dataUrl = 'data:' . $mime . ';base64,' . $imageBase64;
            }

            $messages[] = [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ];
        } else {
            $messages[] = [
                'role'    => 'user',
                'content' => $prompt,
            ];
        }

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        if (!empty($options['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'Failed to encode request payload.',
                'provider' => $provider,
                'model'    => $model,
                'raw'      => null,
            ];
        }

        $ch = curl_init($endpoint);
        if (!$ch) {
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'Failed to initialize cURL.',
                'provider' => $provider,
                'model'    => $model,
                'raw'      => null,
            ];
        }

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'Network error connecting to AI provider: ' . ($curlError ?: 'Connection failed'),
                'provider' => $provider,
                'model'    => $model,
                'raw'      => null,
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'Invalid JSON response from AI provider (HTTP ' . $httpCode . ').',
                'provider' => $provider,
                'model'    => $model,
                'raw'      => null,
            ];
        }

        if ($httpCode >= 400 || !empty($data['error'])) {
            $msg = '';
            if (is_array($data['error'] ?? null)) {
                $msg = (string) ($data['error']['message'] ?? 'API error');
            } elseif (!empty($data['error'])) {
                $msg = (string) $data['error'];
            } else {
                $msg = 'HTTP ' . $httpCode;
            }
            return [
                'success'  => false,
                'content'  => '',
                'text'     => '',
                'error'    => 'AI provider error: ' . $msg,
                'provider' => $provider,
                'model'    => $model,
                'raw'      => $data,
            ];
        }

        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        return [
            'success'  => true,
            'content'  => $content,
            'text'     => $content,
            'error'    => null,
            'provider' => $provider,
            'model'    => $model,
            'raw'      => $data,
        ];
    }
}

if (!function_exists('mh_call_ai_service')) {
    function mh_call_ai_service(string $prompt, ?string $imageBase64 = null, array $options = []): array
    {
        return callAiService($prompt, $imageBase64, $options);
    }
}
