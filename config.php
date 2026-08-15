<?php
declare(strict_types=1);

$secrets = [];
$secretsFile = __DIR__ . '/secrets.php';
if (is_file($secretsFile) && is_readable($secretsFile)) {
    $loaded = require $secretsFile;
    if (is_array($loaded)) {
        $secrets = $loaded;
    }
}

$apiKeyFromEnv = getenv('OLLAMA_API_KEY');
$apiKeys = is_array($secrets['ollama_api_keys'] ?? null) ? $secrets['ollama_api_keys'] : [];

$activeKeyName = getenv('OLLAMA_ACTIVE_KEY')
    ?: ($secrets['active_key'] ?? 'default');

$resolvedApiKey = '';
$resolvedKeyName = '(none)';

if (is_string($apiKeyFromEnv) && $apiKeyFromEnv !== '') {
    $resolvedApiKey = $apiKeyFromEnv;
    $resolvedKeyName = '(env)';
} elseif (isset($apiKeys[$activeKeyName]) && is_string($apiKeys[$activeKeyName]) && $apiKeys[$activeKeyName] !== '') {
    $resolvedApiKey = $apiKeys[$activeKeyName];
    $resolvedKeyName = $activeKeyName;
} elseif (isset($apiKeys['default']) && is_string($apiKeys['default']) && $apiKeys['default'] !== '') {
    $resolvedApiKey = $apiKeys['default'];
    $resolvedKeyName = 'default';
}

// Kredensial login: prioritaskan secrets.php jika ada
$username = $secrets['username'] ?? 'admin';
$password = $secrets['password'] ?? '123';

return [
    'app_name' => 'SigitCloudChat',
    'login_title' => 'Login - Sigit Ai',
    'chat_title' => 'Chat - Sigit',
    'username' => $username,
    'password' => $password,
    'ollama_base_url' => getenv('OLLAMA_BASE_URL') ?: 'https://ollama.com',

    'ollama_api_key' => $resolvedApiKey,

    'ollama_api_key_name' => $resolvedKeyName,
    'ollama_api_keys_available' => array_keys($apiKeys),

    'ollama_model' => getenv('OLLAMA_MODEL') ?: 'gpt-oss:120b-cloud',

    'available_models' => [
        'gpt-oss:120b-cloud' => [
            'label' => 'GPT-OSS 120b',
            'description' => 'Model Open Ai - Model Serbaguna dengan fleksibilitas dan akurasi yang tinggi, mendukung perhitungan & analisis kompleks, '
                . 'cocok untuk pekerjaan sehari-hari dan tugas yang membutuhkan ketelitian',
            'think_mode' => 'level',
            'think_default' => 'medium',
            'supports_tools' => true,
            'context_window' => 131072,
            'context_window_dynamic' => true,
            'context_window_min' => 8192,
            'context_window_max' => 131072,
            'num_predict' => 16384,
            'num_predict_max' => 32768,
        ],
        'gpt-oss:20b-cloud' => [
            'label' => 'GPT-OSS 20b',
            'description' => 'Versi ringan dari model GPT-OSS. Mendukung fitur yang sama dengan model 120b.'
                .' Lebih cepat dan lebih hemat token, cocok untuk chat harian & tugas ringan.',
            'think_mode' => 'level',
            'think_default' => 'medium',
            'supports_tools' => true,
            'context_window' => 131072,
            'context_window_dynamic' => true,
            'context_window_min' => 8192,
            'context_window_max' => 131072,
            'num_predict' => 16384,
            'num_predict_max' => 24576,
        ],
        'nemotron-3-super:cloud' => [
            'label' => 'Nemotron 3 Super',
            'description' => 'Model besar NVIDIA - Model ini menggunakan Arsitektur Hibrida yang sangat kuat untuk reasoning umum & coding, '
                . 'mendukung multi-bahasa dan konteks hingga 1 juta token, cocok untuk pertanyaan yang sangat panjang dan kompleks',
            'think_mode' => 'boolean',
            'think_default' => true,
            'supports_tools' => true,
            'context_window' => 262144,
            'context_window_dynamic' => true,
            'context_window_min' => 8192,
            'context_window_max' => 1048576,
            'context_window_native' => 1048576,
            'num_predict' => 32768,
            'num_predict_max' => 65536,
            'thinking_timeout_seconds' => 600,
        ],
        'gemma4:cloud' => [
            'label' => 'Gemma 4',
            'description' => 'Model Google - Model yang Ringan, responsif dan sangat hemat token dengan ketelitian cukup tinggi, serta mendukung vision,'
                . ' cocok untuk ringkasan singkat dan analisis gambar.',
            'think_mode' => 'boolean',
            'think_default' => true,
            'supports_tools' => true,
            'supports_vision' => true,
            'context_window' => 262144,
            'context_window_dynamic' => true,
            'context_window_min' => 8192,
            'context_window_max' => 262144,
            'num_predict' => 16384,
            'num_predict_max' => 32768,
        ],
    ],

    'ollama_context_window' => (int)(getenv('OLLAMA_CONTEXT_WINDOW') ?: 131072),

    'ollama_num_predict' => (int)(getenv('OLLAMA_NUM_PREDICT') ?: 16384),

    'response_min_predict' => (int)(getenv('OLLAMA_RESPONSE_MIN_PREDICT') ?: 2048),
    'response_max_predict' => (int)(getenv('OLLAMA_RESPONSE_MAX_PREDICT') ?: 65536),
    'ollama_temperature' => (float)(getenv('OLLAMA_TEMPERATURE') ?: 0),
    'ollama_seed' => (int)(getenv('OLLAMA_SEED') ?: 42),

    'ollama_think' => getenv('OLLAMA_THINK') ?: 'medium',

    'enable_tools' => filter_var(getenv('OLLAMA_ENABLE_TOOLS') ?: '1', FILTER_VALIDATE_BOOLEAN),

    'web_search_enabled' => filter_var(getenv('OLLAMA_WEB_SEARCH') ?: '1', FILTER_VALIDATE_BOOLEAN),

    'tool_max_rounds' => (int)(getenv('OLLAMA_TOOL_MAX_ROUNDS') ?: 24),

    'history_max_messages' => (int)(getenv('OLLAMA_HISTORY_MAX_MESSAGES') ?: 200),
    'history_max_chars' => (int)(getenv('OLLAMA_HISTORY_MAX_CHARS') ?: 3000000),
    'session_name' => 'ollama_cloud_chat',
];
