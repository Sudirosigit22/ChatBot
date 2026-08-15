<?php
declare(strict_types=1);

/*
 * A small, inspectable agent layer around the LLM tool loop.  It deliberately
 * keeps planning and verification deterministic so that the agent can be
 * tested without an Ollama/cloud connection.
 */

if (is_file(__DIR__ . '/ml/intent_classifier.php')) {
    require_once __DIR__ . '/ml/intent_classifier.php';
}

/**
 * Klasifikasi niat pesan pengguna secara hybrid: model Naive Bayes yang
 * DILATIH dari includes/ml/dataset_intent.csv (lihat
 * includes/ml/train_intent_classifier.php) dipakai sebagai sinyal utama,
 * digabung (union secara OR, bukan menggantikan) dengan heuristik regex lama
 * sebagai jaring pengaman. Union dipilih secara sengaja: melewatkan
 * kebutuhan tool (false negative) lebih berbahaya bagi keandalan jawaban
 * (LLM bisa menghitung manual tanpa tool) dibanding kelebihan-tandai (false
 * positive, yang paling buruk hanya menambah satu langkah verifikasi).
 * Jika model belum dilatih/tidak ditemukan, sistem otomatis kembali ke
 * heuristik regex saja sehingga tidak ada kegagalan fatal.
 */
function agent_classify(string $goal): array {
    $lower = mb_strtolower($goal);
    $regexWeb = preg_match('/\b(terkini|hari ini|berita|harga|jadwal|presiden|undang|regulasi)\b/ui', $lower) === 1;
    $regexMath = preg_match('/[0-9]|\b(hitung|rasio|persen|kali|bagi|luas|kecepatan)\b/ui', $lower) === 1;

    $ml = function_exists('classify_intent') ? classify_intent($goal) : ['label' => 'umum', 'probs' => [], 'available' => false];
    $mlLabel = $ml['label'] ?? 'umum';
    $mlConfidence = (float) ($ml['probs'][$mlLabel] ?? 0.0);
    // Ambang batas 0.5 dipilih agar model hanya "menyuarakan pendapat" saat
    // cukup yakin; di bawah itu union tetap murni bergantung ke regex.
    $mlSuggestsMath = $ml['available'] && $mlLabel === 'matematika' && $mlConfidence >= 0.5;
    $mlSuggestsWeb = $ml['available'] && $mlLabel === 'pencarian_web' && $mlConfidence >= 0.5;

    return [
        'needs_math' => $regexMath || $mlSuggestsMath,
        'needs_web' => $regexWeb || $mlSuggestsWeb,
        'ml' => $ml,
        'regex' => ['math' => $regexMath, 'web' => $regexWeb],
    ];
}

function agent_start_run(string $goal, array $availableTools): array {
    $goal = trim($goal);
    $classification = agent_classify($goal);
    $needsWeb = $classification['needs_web'];
    $needsMath = $classification['needs_math'];
    $needsRc = function_exists('ollama_is_rc_performance_request') && ollama_is_rc_performance_request($goal);

    $allowed = array_values(array_filter(array_map(
        static fn(array $tool): string => (string) ($tool['function']['name'] ?? ''), $availableTools
    )));
    $steps = [[
        'id' => 'understand', 'action' => 'Memahami tujuan, batasan, dan data dari pengguna.', 'status' => 'pending',
    ]];
    if ($needsRc) {
        $steps[] = ['id' => 'calculate_rc', 'action' => 'Menghitung performansi RC secara deterministik.', 'status' => 'pending'];
    } elseif ($needsMath) {
        $steps[] = ['id' => 'calculate', 'action' => 'Memverifikasi angka dengan tool kalkulator.', 'status' => 'pending'];
    }
    if ($needsWeb) $steps[] = ['id' => 'research', 'action' => 'Mencari sumber fakta terkini.', 'status' => 'pending'];
    $steps[] = ['id' => 'verify', 'action' => 'Memeriksa hasil tool, batasan, dan jawaban akhir.', 'status' => 'pending'];

    $GLOBALS['AGENT_RUN'] = [
        'run_id' => bin2hex(random_bytes(8)), 'goal' => $goal, 'created_at' => gmdate('c'),
        'allowed_tools' => $allowed, 'plan' => $steps, 'tool_calls' => [], 'verdict' => 'running',
        'intent_classification' => $classification,
    ];
    return $GLOBALS['AGENT_RUN'];
}

function agent_validate_tool_call(string $name, array $args): ?string {
    $run = $GLOBALS['AGENT_RUN'] ?? null;
    if (!is_array($run) || !in_array($name, $run['allowed_tools'] ?? [], true)) return 'Tool tidak diizinkan oleh rencana agen: ' . $name;
    if ($name === 'hitung' && trim((string) ($args['ekspresi'] ?? '')) === '') return 'Parameter ekspresi wajib diisi.';
    if ($name === 'cari_web' && trim((string) ($args['kueri'] ?? '')) === '') return 'Parameter kueri wajib diisi.';
    if (in_array($name, ['hitung_batch', 'hitung_berantai'], true) && !is_array($args[$name === 'hitung_batch' ? 'perhitungan' : 'langkah'] ?? null)) return 'Daftar langkah perhitungan wajib berupa array.';
    return null;
}

function agent_record_tool_call(string $name, array $args, string $result, float $durationMs): void {
    if (!isset($GLOBALS['AGENT_RUN']) || !is_array($GLOBALS['AGENT_RUN'])) return;
    $status = str_starts_with($result, 'Tool gagal') || str_starts_with($result, 'ERROR') ? 'failed' : 'ok';
    $GLOBALS['AGENT_RUN']['tool_calls'][] = [
        'tool' => $name, 'args' => $args, 'status' => $status, 'duration_ms' => round($durationMs, 2),
        'result_sha256' => hash('sha256', $result), 'warning' => str_contains($result, 'PERINGATAN:'),
    ];
    foreach ($GLOBALS['AGENT_RUN']['plan'] as &$step) {
        if (($name === 'hitung_performansi_rc' && $step['id'] === 'calculate_rc') ||
            (str_starts_with($name, 'hitung') && $step['id'] === 'calculate') ||
            ($name === 'cari_web' && $step['id'] === 'research')) $step['status'] = $status === 'ok' ? 'done' : 'failed';
    }
    unset($step);
}

function agent_finish_run(string $answer): array {
    $run = $GLOBALS['AGENT_RUN'] ?? null;
    if (!is_array($run)) return [];
    $hasFailure = in_array('failed', array_column($run['tool_calls'], 'status'), true);
    $requiredWorkMissing = false;
    foreach ($run['plan'] as $step) {
        if (in_array($step['id'], ['calculate', 'calculate_rc', 'research'], true) && $step['status'] !== 'done') {
            $requiredWorkMissing = true;
        }
    }
    foreach ($run['plan'] as &$step) {
        if ($step['id'] === 'understand') $step['status'] = 'done';
        if ($step['id'] === 'verify') $step['status'] = ($answer !== '' && !$hasFailure && !$requiredWorkMissing) ? 'done' : 'needs_review';
    }
    unset($step);
    $run['plan'] = $GLOBALS['AGENT_RUN']['plan'];
    $run['verdict'] = ($answer !== '' && !$hasFailure && !$requiredWorkMissing) ? 'passed' : 'needs_review';
    $run['completed_at'] = gmdate('c');
    $GLOBALS['AGENT_RUN'] = $run;
    return $run;
}
