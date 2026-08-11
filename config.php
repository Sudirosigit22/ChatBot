<?php
declare(strict_types=1);

return [
    'app_name' => 'SigitCloudChat',
    'login_title' => 'Login - Sigit Ai',
    'chat_title' => 'Chat - Sigit',
    'username' => 'admin',
    'password' => '123',
    'ollama_base_url' => getenv('OLLAMA_BASE_URL') ?: 'https://ollama.com',
    'ollama_api_key' => getenv('OLLAMA_API_KEY') ?: '4e9f365e8d394eea831006aa1d6bc6d3.Re7WiNShpI_wpxEkAYBZBsQw',
    'ollama_model' => getenv('OLLAMA_MODEL') ?: 'gpt-oss:120b-cloud',
    'available_models' => [
        'gpt-oss:120b-cloud' => [
            'label' => 'GPT-OSS 120b',
            'description' => 'Model Open Ai - Model Serbaguna dengan fleksibilitas dan akurasi yang tinggi, mendukung perhitungan & analisis kompleks. '
                . 'Cocok untuk pekerjaan sehari-hari dan tugas yang membutuhkan ketelitian',
            'think_mode' => 'level',
            'think_default' => 'medium',
            'supports_tools' => true,
            'context_window' => 131072,
        ],
        'gpt-oss:20b-cloud' => [
            'label' => 'GPT-OSS 20b',
            'description' => 'Versi ringan dari model GPT-OSS. Mendukung fitur yang sama dengan model 120b.'
                .' Lebih cepat dan lebih hemat token, cocok untuk chat harian & tugas ringan.',
            'think_mode' => 'level',
            'think_default' => 'medium',
            'supports_tools' => true,
            'context_window' => 131072
        ],
        'nemotron-3-super:cloud' => [
            'label' => 'Nemotron 3 Super',
            'description' => 'Model besar NVIDIA - Menggunakan Arsitektur Hibrida yang sangat kuat untuk reasoning umum & coding. '
                . 'Mendukung multi-bahasa dan mendukung konteks hingga 1 juta token, cocok untuk pertanyaan yang membutuhkan penalaran panjang dan kompleks',
            'think_mode' => 'boolean',
            'think_default' => true,
            'supports_tools' => true,
            'context_window' => 1048576, 
        ],
        'gemma4:cloud' => [
            'label' => 'Gemma 4',
            'description' => 'Model Google - Model yang Ringan, responsif dan sangat hemat token dengan ketelitian cukup tinggi, cocok untuk '
                . ' chat kasual dan ringkasan singkat.',
            'think_mode' => 'boolean',
            'think_default' => true,
            'supports_tools' => true,
            'context_window' => 262144,
        ],
    ],
    'ollama_context_window' => (int)(getenv('OLLAMA_CONTEXT_WINDOW') ?: 122880),
    'ollama_temperature' => (float)(getenv('OLLAMA_TEMPERATURE') ?: 0),
    'ollama_seed' => (int)(getenv('OLLAMA_SEED') ?: 42),
    'ollama_think' => getenv('OLLAMA_THINK') ?: 'medium',
    'enable_tools' => filter_var(getenv('OLLAMA_ENABLE_TOOLS') ?: '1', FILTER_VALIDATE_BOOLEAN),
    'web_search_enabled' => filter_var(getenv('OLLAMA_WEB_SEARCH') ?: '1', FILTER_VALIDATE_BOOLEAN),
    'tool_max_rounds' => (int)(getenv('OLLAMA_TOOL_MAX_ROUNDS') ?: 24),
    'history_max_messages' => (int)(getenv('OLLAMA_HISTORY_MAX_MESSAGES') ?: 16),
    'history_max_chars' => (int)(getenv('OLLAMA_HISTORY_MAX_CHARS') ?: 16000),
    'session_name' => 'ollama_cloud_chat',
    'db_path' => __DIR__ . '/data/chat.sqlite',
];
