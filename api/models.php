<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (!is_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

json_response([
    'models' => ollama_models_for_frontend(),
    'default_model' => (string) app_config('ollama_model'),
]);
