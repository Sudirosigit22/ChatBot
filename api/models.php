<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (!is_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$availableKeys = app_config('ollama_api_keys_available');
if (!is_array($availableKeys)) {
    $availableKeys = [];
}

json_response([
    'models' => ollama_models_for_frontend(),
    'default_model' => (string) app_config('ollama_model'),
    'active_key_name' => (string) (app_config('ollama_api_key_name') ?? '(none)'),
    'available_keys' => array_values($availableKeys),
    'has_api_key' => trim((string) app_config('ollama_api_key')) !== '',
]);
