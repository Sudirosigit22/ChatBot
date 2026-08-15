<?php
declare(strict_types=1);

if (function_exists('set_time_limit')) {
    @set_time_limit(900);
}
ini_set('max_execution_time', '900');

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'answer' => '⚠️ Server sibuk atau butuh waktu terlalu lama untuk merespons. Coba lagi.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/tools.php';
require __DIR__ . '/agent.php';

$cookieParams = session_get_cookie_params();
session_name($config['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookieParams['path'] ?: '/',
    'domain' => $cookieParams['domain'] ?: '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function app_config(string $key) {
    global $config;
    return $config[$key] ?? null;
}

function ollama_available_models(): array {
    $models = app_config('available_models');
    return is_array($models) ? $models : [];
}

function ollama_model_meta(string $modelId): array {
    $defaults = [
        'label' => $modelId, 'description' => '', 'think_mode' => 'level', 'think_default' => 'medium',
        'supports_tools' => true, 'supports_vision' => false, 'context_window' => null, 'context_window_native' => null,
    ];
    $meta = ollama_available_models()[$modelId] ?? [];
    return array_merge($defaults, $meta);
}

function ollama_resolve_model(?string $requested): string {
    if ($requested !== null && $requested !== '' && array_key_exists($requested, ollama_available_models())) {
        return $requested;
    }
    return (string) app_config('ollama_model');
}

function ollama_models_for_frontend(): array {
    $default = (string) app_config('ollama_model');
    $out = [];
    foreach (ollama_available_models() as $id => $meta) {
        $out[] = [
            'id' => $id,
            'label' => $meta['label'] ?? $id,
            'description' => $meta['description'] ?? '',
            'think_mode' => in_array($meta['think_mode'] ?? 'level', ['level', 'boolean', 'none'], true) ? $meta['think_mode'] : 'level',
            'think_default' => $meta['think_default'] ?? null,
            'supports_tools' => (bool) ($meta['supports_tools'] ?? true),
            'supports_vision' => (bool) ($meta['supports_vision'] ?? false),
            'context_window' => isset($meta['context_window']) ? (int) $meta['context_window'] : (int) app_config('ollama_context_window'),
            'context_window_native' => isset($meta['context_window_native']) ? (int) $meta['context_window_native'] : null,
            'is_default' => ($id === $default),
        ];
    }
    return $out;
}

function ollama_dynamic_num_ctx(
    array $ollamaMessages,
    int $min,
    int $max,
    int $outputAndToolMargin = 16384,
    int $step = 32768
): int {
    if ($max < $min) {
        [$min, $max] = [$max, $min];
    }

    $totalChars = 0;
    foreach ($ollamaMessages as $msg) {
        if (isset($msg['content']) && is_string($msg['content'])) {
            $totalChars += mb_strlen($msg['content']);
        }
    }

    $estimatedPromptTokens = (int) ceil($totalChars / 4);
    $needed = $estimatedPromptTokens + max(0, $outputAndToolMargin);

    $step = max(1, $step);
    $rounded = (int) (ceil($needed / $step) * $step);

    return max($min, min($max, $rounded));
}

function ollama_is_rc_performance_request(string $text): bool {
    $hasRcEntity = preg_match('/\b(?:rc|buggy|truggy|crawler|speed[ -]?run|dragster|esc|brushless|pinion|spur|kv)\b/ui', $text) === 1;
    $hasPerformanceIntent = preg_match('/\b(?:analisis|bandingkan|performa|kecepatan|arus|torsi|daya|rasio|gear|motor)\b/ui', $text) === 1;
    return $hasRcEntity && $hasPerformanceIntent;
}

function is_logged_in(): bool {
    return !empty($_SESSION['logged_in']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function flash_error(?string $message = null): ?string {
    if ($message !== null) {
        $_SESSION['flash_error'] = $message;
        return null;
    }
    if (!empty($_SESSION['flash_error'])) {
        $msg = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
        return $msg;
    }
    return null;
}

function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function login_user(string $username): void {
    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = $username;
    csrf_token();
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function ollama_generate_title(string $userMessage, string $assistantAnswer, ?string $model = null): ?string {
    try {
        $baseUrl = rtrim((string) app_config('ollama_base_url'), '/');

        $model = ($model !== null && $model !== '') ? $model : (string) app_config('ollama_model');

        $q = mb_substr(trim($userMessage), 0, 800);
        $a = mb_substr(trim($assistantAnswer), 0, 800);
        if ($q === '') return null;

        $prompt = "Buat SATU judul percakapan yang ringkas (maksimal 6 kata, bahasa Indonesia) yang "
            . "meringkas TOPIK atau KESIMPULAN utama dari pertanyaan dan jawaban berikut - seperti judul "
            . "percakapan pada aplikasi chat pada umumnya, bukan kutipan kalimat pertama. Jangan pakai "
            . "tanda kutip, jangan diakhiri titik, jangan beri penjelasan tambahan apa pun, usahakan untuk judul dibuat"
            . " - balas HANYA dengan judulnya saja.\n\nPertanyaan: {$q}\n\nJawaban: {$a}";

        $messages = [
            ['role' => 'system', 'content' => 'Anda membuat judul percakapan yang singkat dan jelas. Balas hanya dengan judulnya, tanpa embel-embel apa pun.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        $noop = static function (string $t): void {};

        $titleOptions = ['temperature' => 0.7, 'seed' => mt_rand(1, mt_getrandmax())];
        foreach (['', 'low'] as $titleThink) {
            $r = ollama_stream_once($baseUrl, $model, $messages, [], $titleThink, $noop, 8192, null, true, $titleOptions);
            if (!$r['ok']) continue;
            $title = trim((string) $r['content']);
            $title = trim($title, "\"'` \t\n\r\0\x0B.");
            $title = preg_replace('/\s+/', ' ', $title) ?? $title;
            if ($title === '') continue;
            return mb_strlen($title) > 60 ? mb_substr($title, 0, 60) . '…' : $title;
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function ollama_requested_min_words(string $text): int {
    if (!preg_match('/\b(?:minimal|min\.?|setidaknya|sekurang-kurangnya|at\s+least)\s+([0-9][0-9.,]*)\s+(?:kata|words?)\b/ui', $text, $m)) {
        return 0;
    }
    $raw = str_replace(['.', ','], '', $m[1]);
    $value = (int) $raw;

    return max(0, min(30000, $value));
}

function ollama_response_profile(string $latestUserMessage, array $modelMeta, string $depth): array {
    $base = max(512, (int) ($modelMeta['num_predict'] ?? app_config('ollama_num_predict')));
    $cap = max($base, (int) ($modelMeta['num_predict_max'] ?? app_config('response_max_predict')));
    $cap = min($cap, max(512, (int) app_config('response_max_predict')));
    $minimum = max(512, (int) app_config('response_min_predict'));
    $targetWords = ollama_requested_min_words($latestUserMessage);
    $looksDetailed = preg_match('/\b(?:sangat\s+(?:detail|panjang|lengkap)|terperinci|komprehensif|mendalam|selengkapnya)\b/ui', $latestUserMessage) === 1;

    if ($depth === 'hemat') {
        $numPredict = min($base, max($minimum, 4096));
    } elseif ($targetWords > 0) {

        $numPredict = (int) ceil(($targetWords * 2.05) + 1024);
    } elseif ($depth === 'mendalam' || $looksDetailed) {
        $numPredict = max($base, 24576);
    } else {
        $numPredict = $base;
    }

    return [
        'depth' => $depth,
        'target_words' => $targetWords,
        'num_predict' => max($minimum, min($cap, $numPredict)),
    ];
}

function ollama_history_char_budget(int $contextTokens, int $outputTokens): int {

    $usableTokens = max(2048, $contextTokens - $outputTokens - 8192);
    $byContext = $usableTokens * 3;
    $configured = max(0, (int) app_config('history_max_chars'));
    return $configured > 0 ? min($configured, $byContext) : $byContext;
}

function ollama_chat_stream(array $messages, callable $onToken, ?string $thinkOverride = null, ?string $modelOverride = null, ?callable $onHeartbeat = null, string $responseDepth = 'adaptive'): array {
    $baseUrl = rtrim((string)app_config('ollama_base_url'), '/');
    $model = ollama_resolve_model($modelOverride);
    $modelMeta = ollama_model_meta($model);
    $today = date('Y-m-d');

    $systemInstruction = <<<PROMPT
Anda adalah Sigit AI, asisten yang teliti, jujur, dan berorientasi akurasi. Tanggal hari ini: {$today}.

BAHASA: Jawab dalam Bahasa Indonesia yang jelas. Gunakan bahasa lain hanya jika pengguna meminta.

PRINSIP UTAMA
1. Akurasi lebih penting daripada kecepatan atau panjang jawaban. Jangan mengarang fakta, angka, sumber, atau kutipan.
2. Salin data pengguna PERSIS (angka, satuan, nama, kode, batas). Jangan mengubah atau mengabaikan parameter yang diberikan. Jika suatu parameter sengaja tidak dipakai, jelaskan alasannya.
3. Bedakan dengan jelas: fakta dari pengguna / hasil tool / asumsi / perkiraan. Nyatakan ketidakpastian secara jujur.
4. Jangan pernah memberitahu model yang digunakan secara spefisik kepada pengguna, jawab dengan kalimat implisit dan profesional.
5. CAKUPAN JAWABAN: jawab HANYA pertanyaan pada pesan pengguna PALING BARU. Riwayat percakapan sebelumnya adalah KONTEKS untuk dipahami (mis. rujukan "seperti sebelumnya"), BUKAN materi yang harus dihitung ulang atau dianalisis ulang. Meski pertanyaan baru terlihat mirip strukturnya dengan pertanyaan sebelumnya (topik/daftar-item berbeda, format pertanyaan serupa), itu tetap TUGAS BARU YANG TERPISAH -- jangan menggabungkan, menggabungkan ulang, atau menyertakan item/tabel/setup dari pertanyaan sebelumnya ke dalam jawaban ini kecuali pengguna secara eksplisit meminta perbandingan lintas-pertanyaan pada pesan terbarunya.
6. KESIMPULAN/REKOMENDASI "terbaik"/"paling cocok"/"paling efisien"/"paling fleksibel": pertimbangkan SELURUH faktor praktis yang relevan secara bersamaan (mis. ukuran, bobot, kompleksitas, biaya, kemudahan pakai/rawat) -- jangan menjatuhkan pilihan hanya karena SATU angka/metrik teknis unggul (mis. torsi tertinggi, power-to-weight tertinggi, kecepatan tertinggi) sambil mengabaikan kekurangan praktis lain yang sudah Anda sebutkan sendiri di bagian lain jawaban yang sama. Jika data Anda sendiri menunjukkan opsi LAIN unggul pada metrik yang relevan dengan kriteria yang diminta pengguna, akui itu secara eksplisit dan jelaskan alasan trade-off bila Anda tetap memilih opsi berbeda -- jangan berkontradiksi diam-diam dengan angka yang sudah Anda paparkan sendiri. Contoh kesalahan nyata yang WAJIB dihindari: kecepatan maksimum tertinggi atau power-to-weight tertinggi BUKAN otomatis berarti "paling efisien/fleksibel untuk penggunaan harian" -- setup yang dioptimalkan murni untuk kecepatan puncak (mis. dragster/speed-run) justru BIASANYA yang PALING TIDAK cocok untuk harian (rentan getaran/benturan, torsi roda rendah, butuh lintasan lurus panjang, minim fleksibilitas medan); setup yang justru sering paling tepat untuk "efisien & fleksibel harian" adalah yang menyeimbangkan kecepatan menengah, torsi cukup, bobot wajar, dan ketahanan berbagai medan (mis. buggy/truggy) -- BUKTIKAN pilihan Anda dengan menimbang semua ini, jangan pilih otomatis dari satu baris tabel dengan angka terbesar/terkecil.

TOOL (WAJIB dipakai, jangan dihitung/ditebak sendiri)
- hitung_performansi_rc: WAJIB untuk SETIAP setup RC (buggy/truggy/crawler/speed-run/dragster/monster) yang diminta angka kecepatan/arus/torsi/daya/P-W. Satu panggilan = satu setup (atau satu mode high/low). Isi meshes sebagai daftar berurutan {pinion, spur} dari motor ke roda; jangan melewatkan tahap — COMPOUND GEAR (dua gear di satu as) = DUA entri mesh terpisah (sisi spur yang menerima, lalu sisi pinion yang meneruskan), bukan satu. Untuk dual-speed panggil dua kali (mode=high dan mode=low). Untuk bensin set sumber_tenaga="bensin" + hp. Salin digit hasil tool PERSIS ke tabel — ini sumber kebenaran angka RC, jangan hitung ulang manual. Arus/torsi motor/torsi roda (elektrik) = RENTANG 2 titik (rendah–tinggi) dari tool — salin sebagai rentang, jangan dirata-rata/diringkas jadi satu angka.
- hitung: untuk aritmetika non-trivial berdiri sendiri (kali/bagi/pangkat/akar/persentase/trig/log). Operasi sangat sederhana (2+2) boleh manual. Persentase di dalam ekspresi harus desimal (90% → 0.9).
- SATU TAHAP GEAR/PULLEY/SPROKET/RASIO TRANSMISI APA PUN (bila TIDAK memakai hitung_performansi_rc): WAJIB pakai hitung_batch atau hitung_berantai dengan field gigi_input (pinion) & gigi_output (spur/diferensial/gear besar). JANGAN menulis pecahan gigi manual di "ekspresi".
- GEARBOX BERTINGKAT/KOMPLEKS: pecah SETIAP mesh; compound = dua mesh; dual-speed = jalur high dan low terpisah.
- hitung_batch: untuk banyak angka/label independen.
- hitung_berantai: WAJIB bila langkah berikutnya butuh hasil langkah sebelumnya pada item yang sama.
- cari_web: untuk fakta yang bisa berubah. Cari sekali dengan kueri spesifik; jika gagal, katakan keterbatasannya.
- TIDAK ADA jawaban berisi tabel/angka teknis RC yang boleh ditulis tanpa memanggil hitung_performansi_rc (atau tool hitung*) lebih dulu.

PERHITUNGAN
- Setiap angka di tabel atau jawaban multi-item HARUS berasal dari tool, termasuk baris/item terakhir. Salin digit hasil tool PERSIS — jangan mengetik ulang dari ingatan.
- Untuk analisis RC: utamakan hitung_performansi_rc. Tool itu menerapkan model beban rendah/tinggi (bukan satu titik), rasio multi-mesh, Kt, volume stator, dan batas ESC (arus tinggi/peak tidak pernah melebihi rating ESC, dijamin tool). Jangan menimpa angkanya dengan perhitungan manual. Arus/torsi motor/torsi roda (elektrik) WAJIB ditulis sebagai rentang rendah–tinggi dari tool di jawaban/tabel — dilarang merata-ratakan atau memilih satu titik saja untuk "mewakili" hasil. WAJIB menyinggung ukuran/kode motor dan volume stator (atau kapasitas termal) saat membandingkan daya/arus antar setup. Arus crawler yang rendah meski torsi roda tinggi adalah NORMAL (rasio tinggi). Angka apa pun yang disebut di teks naratif untuk suatu setup HARUS sama persis dengan yang sudah ditulis di tabel untuk setup itu — jangan menyebut angka baru/berbeda di teks tanpa memanggil ulang tool.
- Definisi harus konsisten (rasio total = RPM sumber / RPM keluaran ≥1 untuk reduksi).
- Truggy dirancang untuk traksi dan medan campuran; rasio gear yang lebih rendah atau kecepatan lebih tinggi BUKAN bukti "traksi rendah" tanpa data ban, permukaan, atau distribusi bobot.
- Titik kerja fisik harus konsisten: jangan mengalikan torsi stall dengan RPM tanpa-beban. Daya = T·ω hanya pada SATU titik kerja yang sama.
- Rating ESC/baterai = BATAS ATAS, bukan nilai operasi.
- Sumber non-listrik (bensin/diesel): jangan mengarang arus/tegangan; tulis "tidak berlaku".

ESTIMASI & DATA TIDAK LENGKAP
- Tetap jawab pada giliran ini dengan asumsi eksplisit di dekat angka terkait. Jangan berhenti menunggu data tambahan.
- Sebutkan metodologi/rumus yang dipilih sebelum menyajikan angka, terutama bila ada lebih dari satu pendekatan masuk akal.
- Sanity check SEBELUM menjawab: satuan, orde besaran, tanda, batas fisik yang dinyatakan di soal, dan konsistensi antar kolom pada baris yang sama. Bila suatu angka meleset beberapa ORDE BESARAN dari yang wajar untuk konteks soal itu sendiri (mis. RPM motor kecil sampai jutaan, daya kW besar dari perangkat kecil bertenaga rendah, arus ribuan A dari sumber berating puluhan/ratusan A) -- itu tanda ada langkah/rumus yang keliru, bukan variasi yang sah. Jangan sajikan angka itu lalu menambahkan disclaimer/menebak angka pengganti yang tidak terhubung ke hitungan; kembali ke langkah hitung_berantai yang salah dan ulangi dari situ dengan rumus yang benar.

FORMAT
- Jawab inti pertanyaan dulu, lalu penjelasan seperlunya.
- Tabel markdown: jumlah kolom setiap baris (termasuk baris data, bukan hanya header) HARUS PERSIS SAMA dengan jumlah kolom header. JANGAN PERNAH menggabungkan dua nilai berbeda (mis. mode "high" dan "low" pada item yang sama) ke dalam SATU sel tabel jika keduanya punya kolom terpisah di header — ini menggeser semua nilai di baris itu ke kolom yang salah. Tulis di sel terpisah, satu per kolom, walau nilainya berkaitan.
- Rentang rendah–tinggi dari hitung_performansi_rc untuk arus/torsi motor/torsi roda tetap satu kolom per metrik (mis. kolom "Arus (A)" tunggal) — tulis rentang ringkas di SATU sel (mis. "140–210 A"), jangan dipecah jadi kolom terpisah, jangan diringkas jadi satu angka.
- Untuk nilai perkiraan/pembulatan gunakan simbol "≈" (mis. "≈210 A"). JANGAN memakai tanda tilde tunggal "~" untuk arti ini -- sebagian penampil Markdown merender tilde sebagai subscript atau coret/strikethrough, membuat angka terlihat dicoret/ambigu padahal bukan itu maksudnya.
- Jangan mengklaim "semua dihitung dengan tool" kecuali itu benar untuk setiap angka.
PROMPT;

    $supportsVision = (bool) ($modelMeta['supports_vision'] ?? false);

    $history = [];
    $lastUserIdx = -1;
    foreach ($messages as $idx => $m) {
        if (!is_array($m)) continue;
        $role = (string) ($m['role'] ?? '');
        $content = (string) ($m['content'] ?? '');
        $hasImages = $role === 'user' && !empty($m['images']) && is_array($m['images']);
        if (!in_array($role, ['user', 'assistant'], true)) continue;
        if ($content === '' && !$hasImages) continue;
        if ($content === '') $content = '(gambar terlampir)';
        $entry = ['role' => $role, 'content' => $content];
        if ($role === 'user') {
            $lastUserIdx = count($history);
            if ($supportsVision && $hasImages) {
                $imgs = [];
                foreach ($m['images'] as $img) {
                    if (!is_string($img) || $img === '') continue;

                    if (preg_match('#^data:image/[^;]+;base64,(.+)$#s', $img, $mm)) {
                        $imgs[] = $mm[1];
                    } else {
                        $imgs[] = $img;
                    }
                }
                if ($imgs) $entry['images'] = $imgs;
            }
        }
        $history[] = $entry;
    }

    $responseProfile = ollama_response_profile($latestUserMessage = (string) end($messages)['content'], $modelMeta, $responseDepth);
    $contextCeiling = isset($modelMeta['context_window_max']) && $modelMeta['context_window_max']
        ? (int) $modelMeta['context_window_max']
        : (int) ($modelMeta['context_window'] ?? app_config('ollama_context_window'));

    $history = ollama_limit_history(
        $history,
        (int) app_config('history_max_messages'),
        ollama_history_char_budget($contextCeiling, $responseProfile['num_predict'])
    );

    if ($supportsVision && $lastUserIdx >= 0 && isset($messages[$lastUserIdx]['images'])) {

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user') {
                $src = $messages;
                foreach (array_reverse($messages) as $orig) {
                    if (($orig['role'] ?? '') === 'user' && !empty($orig['images']) && is_array($orig['images'])) {
                        $imgs = [];
                        foreach ($orig['images'] as $img) {
                            if (!is_string($img) || $img === '') continue;
                            if (preg_match('#^data:image/[^;]+;base64,(.+)$#s', $img, $mm)) {
                                $imgs[] = $mm[1];
                            } else {
                                $imgs[] = $img;
                            }
                        }
                        if ($imgs) $history[$i]['images'] = $imgs;
                        break;
                    }
                }
                break;
            }
        }
    }

    $ollamaMessages = array_merge([
        ['role' => 'system', 'content' => $systemInstruction],
    ], $history);

    $latestUserMessage = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') { $latestUserMessage = (string) $messages[$i]['content']; break; }
    }
    tool_calc_set_reference_numbers($latestUserMessage);

    $toolsEnabled = (bool) app_config('enable_tools') && (bool) $modelMeta['supports_tools'];
    $isRcPerformanceTask = ollama_is_rc_performance_request($latestUserMessage);
    $tools = $toolsEnabled ? ollama_tool_definitions($isRcPerformanceTask) : [];
    // A deterministic plan makes tool use observable and independently testable.
    agent_start_run($latestUserMessage, $tools);
    $maxRounds = max(1, (int) app_config('tool_max_rounds'));

    $thinkMode = $modelMeta['think_mode'];
    if ($thinkMode === 'none') {

        $thinkChain = [''];
    } elseif ($thinkMode === 'boolean') {

        $configuredOn = (bool) ($modelMeta['think_default'] ?? true);
        if ($thinkOverride !== null) {
            if (in_array($thinkOverride, ['off', 'false', 'low'], true)) $configuredOn = false;
            elseif (in_array($thinkOverride, ['on', 'true', 'medium', 'high'], true)) $configuredOn = true;
        }
        $thinkChain = ollama_think_fallback_chain($configuredOn ? 'true' : 'false');
    } else {

        $configuredThink = (string) ($modelMeta['think_default'] ?? app_config('ollama_think'));
        if ($thinkOverride !== null && in_array($thinkOverride, ['low', 'medium', 'high'], true)) {
            $configuredThink = $thinkOverride;
        }
        $thinkChain = ollama_think_fallback_chain($configuredThink);
    }

    $deadline = microtime(true) + 840.0;

    $numCtx = isset($modelMeta['context_window']) && $modelMeta['context_window']
        ? (int) $modelMeta['context_window']
        : (int) app_config('ollama_context_window');

    if (!empty($modelMeta['context_window_dynamic'])) {
        $dynMin = isset($modelMeta['context_window_min']) && $modelMeta['context_window_min']
            ? (int) $modelMeta['context_window_min']
            : 131072;
        $dynMax = isset($modelMeta['context_window_max']) && $modelMeta['context_window_max']
            ? (int) $modelMeta['context_window_max']
            : max($dynMin, $numCtx);
        $numCtx = ollama_dynamic_num_ctx($ollamaMessages, $dynMin, $dynMax, $responseProfile['num_predict'] + 8192, 8192);
    }

    $diagnostics = [];
    foreach ($thinkChain as $thinkLevel) {
        if (microtime(true) >= $deadline) {
            $diagnostics[] = 'waktu habis sebelum sempat mencoba semua level reasoning';
            break;
        }
        $attempt = ollama_run_rounds($baseUrl, $model, $ollamaMessages, $tools, $thinkLevel, $onToken, $maxRounds, $deadline, $numCtx, $onHeartbeat, $responseProfile);
        if (!$attempt['ok']) {

            if (!empty($attempt['retryable_empty_timeout']) && $thinkLevel !== false) {
                $diagnostics[] = 'thinking timeout tanpa konten; mencoba tanpa thinking';
                continue;
            }
            return $attempt;
        }
        if (!empty($attempt['stopped'])) return $attempt;
        if (trim($attempt['answer']) !== '') return $attempt;

        $diagnostics[] = "think=" . (is_bool($thinkLevel) ? ($thinkLevel ? 'true' : 'false') : $thinkLevel);
    }

    return [
        'ok' => false,
        'error' => 'Model tidak menghasilkan jawaban (konten kosong) meski permintaan berhasil diproses Ollama, '
            . 'setelah dicoba ulang dengan beberapa level reasoning (' . implode(', ', $diagnostics) . '). '
            . 'Ini biasanya terjadi karena model reasoning menghabiskan seluruh anggaran token untuk "berpikir" '
            . 'pada pertanyaan yang panjang/kompleks atau riwayat percakapan yang sudah besar, sehingga tidak ada '
            . 'token tersisa untuk jawaban akhir. Saran: coba pertanyaan yang lebih ringkas/dipecah per bagian, '
            . 'naikkan "ollama_context_window" di config.php bila perangkat keras mendukung, atau pastikan Ollama '
            . 'sudah versi terbaru (bug serupa pernah dilaporkan pada beberapa versi Ollama dengan model gpt-oss).',
    ];
}

function ollama_limit_history(array $historyMessages, int $maxMessages, int $maxChars): array {
    if ($maxMessages > 0 && count($historyMessages) > $maxMessages) {
        $historyMessages = array_slice($historyMessages, -$maxMessages);
    }

    if ($maxChars > 0) {
        $total = 0;
        foreach ($historyMessages as $m) $total += mb_strlen((string) $m['content']);
        while ($total > $maxChars && count($historyMessages) > 1) {
            $removed = array_shift($historyMessages);
            $total -= mb_strlen((string) $removed['content']);
        }
    }

    return $historyMessages;
}

function ollama_think_fallback_chain(string $configured) {
    $levels = ['high', 'medium', 'low'];
    $idx = array_search(strtolower($configured), $levels, true);
    if ($idx !== false) {
        $chain = array_slice($levels, $idx);
        $chain[] = false;
        return $chain;
    }

    $bool = filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    return $bool ? [true, false] : [false];
}

function ollama_sanitize_answer_text(string $text): string {
    if ($text === '' || strpos($text, '~') === false) {
        return $text;
    }
    return preg_replace('/~(?=\s?\d)/u', '≈', $text) ?? $text;
}

function ollama_extend_to_min_words(array $ollamaMessages, string $answer, string $lastResponse, int $targetWords, string $baseUrl, string $model, string $thinkStr, callable $onToken, ?int $numCtx, int $numPredict, ?callable $onHeartbeat = null): array {
    if ($targetWords <= 0) return ['answer' => $answer, 'stopped' => false];
    $wordCount = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'-]*/u', $answer, $unused);
    if ($wordCount >= (int) floor($targetWords * 0.94)) return ['answer' => $answer, 'stopped' => false];

    $remaining = max(1, $targetWords - $wordCount);
    $continuationPredict = min($numPredict, max(1024, (int) ceil(($remaining * 2.05) + 512)));

    $ollamaMessages[] = ['role' => 'assistant', 'content' => $lastResponse];
    $ollamaMessages[] = [
        'role' => 'user',
        'content' => "Lanjutkan jawaban yang sama tanpa mengulang bagian sebelumnya. Tambahkan setidaknya {$remaining} kata substantif agar permintaan panjang minimum terpenuhi; pertahankan format dan fakta/angka yang sudah ada.",
    ];
    if ($answer !== '' && !preg_match('/\s$/', $answer)) {
        $onToken("\n\n");
        $answer .= "\n\n";
    }
    $continuation = ollama_stream_once($baseUrl, $model, $ollamaMessages, [], $thinkStr, $onToken, $numCtx, null, false, ['num_predict' => $continuationPredict], $onHeartbeat);
    if (!$continuation['ok']) return ['answer' => $answer, 'stopped' => false];
    return ['answer' => $answer . $continuation['content'], 'stopped' => !empty($continuation['stopped'])];
}

function ollama_run_rounds(string $baseUrl, string $model, array $ollamaMessages, array $tools, $think, callable $onToken, int $maxRounds, ?float $deadline = null, ?int $numCtx = null, ?callable $onHeartbeat = null, array $responseProfile = []): array {
    $thinkStr = is_bool($think) ? ($think ? 'true' : 'false') : (string) $think;
    $answer = '';
    $numPredict = max(512, (int) ($responseProfile['num_predict'] ?? app_config('ollama_num_predict')));
    $targetWords = max(0, (int) ($responseProfile['target_words'] ?? 0));

    $toolWarnings = [];
    $warningsPrompted = false;
    $numericAuditPrompted = false;

    $pendingSeparator = false;
    for ($round = 0; $round < $maxRounds; $round++) {

        if ($deadline !== null && microtime(true) >= $deadline) {
            if ($answer !== '') return ['ok' => true, 'answer' => $answer, 'stopped' => true];
            return [
                'ok' => false,
                'friendly' => true,
                'error' => '⏳ Model membutuhkan waktu terlalu lama untuk merespons pertanyaan sekompleks ini '
                    . '(sudah dicoba hingga mendekati batas waktu server). Coba pecah pertanyaan menjadi '
                    . 'beberapa bagian yang lebih kecil, turunkan level reasoning, atau coba lagi beberapa saat lagi.',
            ];
        }

        if ($pendingSeparator) {
            $pendingSeparator = false;
            if ($answer !== '' && !preg_match('/\s$/', $answer)) {
                $onToken("\n\n");
                $answer .= "\n\n";
            }
        }

        $attemptsAllowed = ($round === 0) ? 3 : 1;
        $r = null;
        for ($attempt = 1; $attempt <= $attemptsAllowed; $attempt++) {

            $toolChoice = ($round === 0 && $attempt === 1 && !empty($tools) && tool_calc_looks_calculation_heavy())
                ? 'required' : null;
            $r = ollama_stream_once($baseUrl, $model, $ollamaMessages, $tools, $thinkStr, $onToken, $numCtx, $toolChoice, false, ['num_predict' => $numPredict], $onHeartbeat);
            if ($r['ok']) break;

            if (!empty($r['retryable_empty_timeout'])) break;
            if ($attempt < $attemptsAllowed) usleep(700000 * $attempt);
        }
        if (!$r['ok']) return [
            'ok' => false,
            'error' => $r['error'],
            'friendly' => $r['friendly'] ?? false,
            'retryable_empty_timeout' => $r['retryable_empty_timeout'] ?? false,
        ];
        $answer .= $r['content'];
        if (!empty($r['stopped'])) return ['ok' => true, 'answer' => $answer, 'stopped' => true];

        $toolCalls = $r['tool_calls'];

        if (empty($toolCalls) && $round < $maxRounds - 1) {
            $leaked = ollama_extract_leaked_tool_call($r['content']);
            if ($leaked !== null) $toolCalls = $leaked;
        }

        if (empty($toolCalls)) {

            if (!empty($toolWarnings) && !$warningsPrompted) {
                $warningsPrompted = true;
                $pendingSeparator = true;
                $assistantMsg = ['role' => 'assistant', 'content' => $r['content']];
                if (!empty($r['thinking'])) $assistantMsg['thinking'] = $r['thinking'];
                $ollamaMessages[] = $assistantMsg;
                $warningList = implode("\n", array_map(static fn(string $w): string => "- {$w}", $toolWarnings));
                $ollamaMessages[] = [
                    'role' => 'user',
                    'content' => "Sebelum benar-benar selesai: hasil tool sebelumnya memuat peringatan berikut. "
                        . "Jika jawaban Anda barusan BELUM menegaskan poin ini secara eksplisit ke pengguna, "
                        . "tambahkan penegasannya sekarang secara ringkas (tidak perlu mengulang seluruh jawaban). "
                        . "Jika sudah tercakup, tidak perlu dijawab",
                ];
                continue;
            }
            if ($targetWords > 0 && ($deadline === null || microtime(true) < $deadline)) {
                $extended = ollama_extend_to_min_words($ollamaMessages, $answer, $r['content'], $targetWords, $baseUrl, $model, $thinkStr, $onToken, $numCtx, $numPredict, $onHeartbeat);
                return ['ok' => true, 'answer' => $extended['answer'], 'stopped' => $extended['stopped']];
            }
            return ['ok' => true, 'answer' => $answer];
        }

        $assistantMsg = ['role' => 'assistant', 'content' => $r['content']];
        if (!empty($r['thinking'])) $assistantMsg['thinking'] = $r['thinking'];
        $assistantMsg['tool_calls'] = $toolCalls;
        $ollamaMessages[] = $assistantMsg;

            foreach ($toolCalls as $call) {
            $fname = (string) ($call['function']['name'] ?? '');
            $rawArgs = $call['function']['arguments'] ?? [];
            if (is_string($rawArgs)) {
                $decodedArgs = json_decode($rawArgs, true);
                $args = is_array($decodedArgs) ? $decodedArgs : [];
            } else {
                $args = is_array($rawArgs) ? $rawArgs : [];
            }
            $validationError = $fname !== '' ? agent_validate_tool_call($fname, $args) : 'Tool tanpa nama tidak dapat dijalankan.';
            $toolStarted = microtime(true);
            $toolResult = $validationError === null
                ? ollama_tool_execute($fname, $args)
                : 'Tool gagal dijalankan: ' . $validationError;
            agent_record_tool_call($fname, $args, $toolResult, (microtime(true) - $toolStarted) * 1000);
            $ollamaMessages[] = ['role' => 'tool', 'tool_name' => $fname, 'content' => $toolResult];

            foreach (explode("\n", $toolResult) as $toolLine) {
                if (str_starts_with($toolLine, 'PERINGATAN:') && !in_array($toolLine, $toolWarnings, true)) {
                    $toolWarnings[] = $toolLine;
                }
            }

            if (!$numericAuditPrompted && tool_calc_looks_calculation_heavy()) {
                $numericAuditPrompted = true;
                $ollamaMessages[] = [
                    'role' => 'user',
                    'content' => 'Gunakan hasil tool sebagai ledger final: sebelum menyajikan jawaban, cocokkan setiap angka/tabel dengan hasil tool; jangan mengisi, membulatkan, atau menggabungkan nilai yang tidak ada di hasil tool.',
                ];
            }
        }
    }

    if ($deadline !== null && microtime(true) >= $deadline) {
        if ($answer !== '') return ['ok' => true, 'answer' => $answer, 'stopped' => true];
        return [
            'ok' => false,
            'friendly' => true,
            'error' => '⏳ Model membutuhkan waktu terlalu lama untuk merespons pertanyaan sekompleks ini. '
                . 'Coba pecah pertanyaan menjadi beberapa bagian yang lebih kecil, atau coba lagi beberapa saat lagi.',
        ];
    }

    if ($pendingSeparator && $answer !== '' && !preg_match('/\s$/', $answer)) {
        $onToken("\n\n");
        $answer .= "\n\n";
    }
    $final = ollama_stream_once($baseUrl, $model, $ollamaMessages, [], $thinkStr, $onToken, $numCtx, null, false, ['num_predict' => $numPredict], $onHeartbeat);
    if (!$final['ok']) return ['ok' => false, 'error' => $final['error'], 'friendly' => $final['friendly'] ?? false];
    $answer .= $final['content'];
    if (!empty($final['stopped'])) return ['ok' => true, 'answer' => $answer, 'stopped' => true];

    if ($targetWords > 0 && ($deadline === null || microtime(true) < $deadline)) {
        $extended = ollama_extend_to_min_words($ollamaMessages, $answer, $final['content'], $targetWords, $baseUrl, $model, $thinkStr, $onToken, $numCtx, $numPredict, $onHeartbeat);
        return ['ok' => true, 'answer' => $extended['answer'], 'stopped' => $extended['stopped']];
    }
    return ['ok' => true, 'answer' => $answer];
}

function ollama_extract_leaked_tool_call(string $content): ?array {
    $trimmed = trim($content);
    if ($trimmed === '') return null;

    if (preg_match_all('/<tool_call>\s*(.*?)\s*<\/tool_call>/is', $trimmed, $blocks)) {
        $calls = [];
        foreach ($blocks[1] as $block) {
            $call = ollama_parse_named_tool_call_json($block);
            if ($call !== null) $calls[] = $call;
        }
        if (!empty($calls)) return $calls;
    }

    if ($trimmed[0] === '{' && substr($trimmed, -1) === '}') {

        $named = ollama_parse_named_tool_call_json($trimmed);
        if ($named !== null) return [$named];

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            if (isset($decoded['ekspresi']) && is_string($decoded['ekspresi'])) {
                return [['function' => ['name' => 'hitung', 'arguments' => ['ekspresi' => $decoded['ekspresi']]]]];
            }
            if (isset($decoded['kueri']) && is_string($decoded['kueri'])) {
                $args = ['kueri' => $decoded['kueri']];
                if (isset($decoded['jumlah_hasil'])) $args['jumlah_hasil'] = (int) $decoded['jumlah_hasil'];
                return [['function' => ['name' => 'cari_web', 'arguments' => $args]]];
            }
        }
    }
    return null;
}

function ollama_parse_named_tool_call_json(string $block): ?array {
    $block = trim($block, "` \t\n\r\0\x0B");
    if (str_starts_with($block, 'json')) $block = trim(substr($block, 4));
    $decoded = json_decode($block, true);
    if (!is_array($decoded)) return null;

    if (isset($decoded['function']) && is_array($decoded['function'])) {
        $decoded = $decoded['function'];
    }
    $name = $decoded['name'] ?? null;
    $args = $decoded['arguments'] ?? $decoded['parameters'] ?? null;
    if (!is_string($name) || $name === '') return null;
    if (!is_array($args)) $args = [];

    $validNames = array_map(
        static fn(array $t): string => (string) ($t['function']['name'] ?? ''),
        ollama_tool_definitions()
    );
    if (!in_array($name, $validNames, true)) return null;

    return ['function' => ['name' => $name, 'arguments' => $args]];
}

function ollama_sanitize_replacement_chars(string $text): string {
    if ($text === '' || strpos($text, "\u{FFFD}") === false) {
        return $text;
    }

    $text = preg_replace('/\x{202F}?\x{FFFD}+\x{202F}?/u', ' ≈ ', $text) ?? $text;

    $text = str_replace("\u{FFFD}", '', $text);

    return preg_replace('/[ \x{202F}]{2,}/u', ' ', $text) ?? $text;
}

function ollama_stream_once(string $baseUrl, string $model, array $ollamaMessages, array $tools, string $think, callable $onToken, ?int $numCtx = null, ?string $toolChoice = null, bool $lightweight = false, ?array $optionsOverride = null, ?callable $onHeartbeat = null): array {

    if ($lightweight) {
        $numPredict = (int) ($optionsOverride['num_predict'] ?? 300);
    } else {
        $predictModelMeta = ollama_model_meta($model);
        $numPredict = isset($predictModelMeta['num_predict']) && $predictModelMeta['num_predict']
            ? (int) $predictModelMeta['num_predict']
            : (int) app_config('ollama_num_predict');
    }

    $payloadArr = [
        'model' => $model,
        'messages' => $ollamaMessages,
        'stream' => true,
        'options' => [

            'temperature' => (float)($optionsOverride['temperature'] ?? app_config('ollama_temperature')),
            'seed' => (int)($optionsOverride['seed'] ?? app_config('ollama_seed')),

            'num_ctx' => max(2048, $numCtx ?? (int)app_config('ollama_context_window')),

            'num_predict' => $numPredict,
        ],
    ];
    if ($think !== '') {
        $payloadArr['think'] = in_array($think, ['low', 'medium', 'high'], true)
            ? $think
            : filter_var($think, FILTER_VALIDATE_BOOLEAN);
    }
    if (!empty($tools)) {
        $payloadArr['tools'] = $tools;

        if ($toolChoice !== null) {
            $payloadArr['tool_choice'] = $toolChoice;
        }
    }

    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = $baseUrl . '/api/chat';
    if ($payload === false) return ['ok' => false, 'error' => 'Gagal menyiapkan permintaan Ollama'];

    $content = '';
    $pendingTail = '';

    $tailKeep = 4;
    $thinking = '';
    $toolCalls = [];
    $error = '';
    $handleLine = static function (string $line) use (&$content, &$pendingTail, $tailKeep, &$thinking, &$toolCalls, &$error, $onToken): bool {
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) return true;
        if (!empty($decoded['error'])) { $error = (string)$decoded['error']; return false; }
        $message = $decoded['message'] ?? [];
        $token = (string)($message['content'] ?? '');
        if ($token !== '') {
            $content .= $token;
            $combined = $pendingTail . $token;
            $chars = preg_split('//u', $combined, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($chars) > $tailKeep) {
                $flushChars = array_slice($chars, 0, count($chars) - $tailKeep);
                $pendingTail = implode('', array_slice($chars, count($chars) - $tailKeep));
                $flush = ollama_sanitize_replacement_chars(implode('', $flushChars));
                if ($flush !== '') $onToken($flush);
            } else {
                $pendingTail = $combined;
            }
        }

        $thinkTok = (string)($message['thinking'] ?? '');
        if ($thinkTok !== '') { $thinking .= $thinkTok; }
        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                if (is_array($tc)) $toolCalls[] = $tc;
            }
        }
        return true;
    };

    $flushPendingTail = static function () use (&$pendingTail, $onToken): void {
        if ($pendingTail === '') return;
        $flush = ollama_sanitize_replacement_chars($pendingTail);
        $pendingTail = '';
        if ($flush !== '') $onToken($flush);
    };

    $modelMeta = ollama_model_meta($model);
    $requestTimeout = 300;
    if ($think === 'true' && isset($modelMeta['thinking_timeout_seconds'])) {
        $requestTimeout = max(30, (int)$modelMeta['thinking_timeout_seconds']);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $buffer = '';
        $retryAfter = null;

        $requestHeaders = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ];
        $apiKey = trim((string) app_config('ollama_api_key'));
        if ($apiKey !== '') {
            $requestHeaders[] = 'Authorization: Bearer ' . $apiKey;
        }
        $lastHeartbeat = microtime(true);
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $requestTimeout,

            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$retryAfter): int {
                if (preg_match('/^retry-after\s*:\s*(.+)$/i', trim($headerLine), $m)) {
                    $retryAfter = trim($m[1]);
                }
                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, $handleLine): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    if (!$handleLine($line) || connection_aborted()) return 0;
                }
                return strlen($chunk);
            },
        ];

        if ($onHeartbeat !== null) {
            $curlOpts[CURLOPT_NOPROGRESS] = false;
            $curlOpts[CURLOPT_XFERINFOFUNCTION] = static function ($curl, $downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use (&$lastHeartbeat, $onHeartbeat): int {
                $now = microtime(true);
                if ($now - $lastHeartbeat >= 10.0) {
                    $lastHeartbeat = $now;
                    $onHeartbeat();
                }
                return connection_aborted() ? 1 : 0;
            };
        }

        curl_setopt_array($ch, $curlOpts);
        $ok = curl_exec($ch);
        $curlErr = curl_error($ch);
        $curlCode = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($buffer !== '') $handleLine($buffer);
        $flushPendingTail();

        $content = ollama_sanitize_replacement_chars($content);
        if ($ok === false && $error === '') {

            if ($content !== '') return ['ok' => true, 'content' => $content, 'thinking' => $thinking, 'tool_calls' => $toolCalls, 'stopped' => true];
            $result = ollama_build_error_result('Gagal menghubungi Ollama: ' . $curlErr, $httpCode, $retryAfter);

            if ($curlCode === 28 && $content === '') $result['retryable_empty_timeout'] = true;
            return $result;
        }
        if ($error !== '') return ollama_build_error_result($error, $httpCode, $retryAfter);
        if ($httpCode < 200 || $httpCode >= 300) return ollama_build_error_result('Ollama error HTTP ' . $httpCode, $httpCode, $retryAfter);
        return ['ok' => true, 'content' => $content, 'thinking' => $thinking, 'tool_calls' => $toolCalls];
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 300,
            'ignore_errors' => true,
        ],
    ];

    $context = stream_context_create($opts);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        return ['ok' => false, 'error' => 'Gagal menghubungi Ollama'];
    }
    while (!feof($stream)) {
        $line = fgets($stream);
        if ($line !== false && !$handleLine($line)) {
            fclose($stream);
            [$httpCode, $retryAfter] = ollama_parse_response_headers($http_response_header ?? []);
            return ollama_build_error_result($error, $httpCode, $retryAfter);
        }
        if (connection_aborted()) {
            fclose($stream);
            $flushPendingTail();
            $content = ollama_sanitize_replacement_chars($content);
            return ['ok' => true, 'content' => $content, 'thinking' => $thinking, 'tool_calls' => $toolCalls, 'stopped' => true];
        }
    }
    fclose($stream);
    $flushPendingTail();

    $content = ollama_sanitize_replacement_chars($content);
    return ['ok' => true, 'content' => $content, 'thinking' => $thinking, 'tool_calls' => $toolCalls];
}

function ollama_parse_response_headers(array $headers): array {
    $httpCode = 0;
    $retryAfter = null;
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $httpCode = (int) $m[1];
        if (preg_match('/^retry-after\s*:\s*(.+)$/i', trim($h), $m)) $retryAfter = trim($m[1]);
    }
    return [$httpCode, $retryAfter];
}

function ollama_build_error_result(string $rawError, int $httpCode, ?string $retryAfter): array {
    $lower = mb_strtolower($rawError);
    $quotaKeywords = [
        'quota', 'rate limit', 'rate-limit', 'usage limit', 'exceeded your',
        'insufficient', 'billing', 'out of credit', 'token limit', 'tokens limit',
        'too many requests', 'limit reached', 'usage cap', 'monthly limit',
    ];
    $isQuota = ($httpCode === 429);
    if (!$isQuota) {
        foreach ($quotaKeywords as $kw) {
            if (str_contains($lower, $kw)) { $isQuota = true; break; }
        }
    }
    if (!$isQuota) {
        return ['ok' => false, 'error' => $rawError, 'friendly' => false];
    }

    $waktu = ollama_estimate_reset_time($retryAfter, $rawError);
    $msg = '⏳ Kuota penggunaan AI cloud sedang habis untuk saat ini.';
    $msg .= $waktu !== null ? " Perkiraan tersedia lagi dalam {$waktu}." : ' Silakan coba lagi beberapa saat lagi.';
    return ['ok' => false, 'error' => $msg, 'friendly' => true];
}

function ollama_estimate_reset_time(?string $retryAfter, string $rawError): ?string {
    if ($retryAfter !== null) {
        $h = trim($retryAfter);
        if (ctype_digit($h)) return ollama_format_duration((int) $h);
        $ts = strtotime($h);
        if ($ts !== false) {
            $diff = $ts - time();
            if ($diff > 0) return ollama_format_duration($diff);
        }
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(detik|second|menit|minute|jam|hour|hari|day)/i', $rawError, $m)) {
        $n = (float) $m[1];
        $unit = mb_strtolower($m[2]);
        $seconds = null;
        if (str_starts_with($unit, 'detik') || str_starts_with($unit, 'second')) $seconds = $n;
        elseif (str_starts_with($unit, 'menit') || str_starts_with($unit, 'minute')) $seconds = $n * 60;
        elseif (str_starts_with($unit, 'jam') || str_starts_with($unit, 'hour')) $seconds = $n * 3600;
        elseif (str_starts_with($unit, 'hari') || str_starts_with($unit, 'day')) $seconds = $n * 86400;
        if ($seconds !== null) return ollama_format_duration((int) round($seconds));
    }
    return null;
}

function ollama_format_duration(int $seconds): string {
    if ($seconds <= 0) return 'beberapa saat';
    if ($seconds < 90) return $seconds . ' detik';
    $minutes = (int) round($seconds / 60);
    if ($minutes < 90) return $minutes . ' menit';
    $hours = (int) round($minutes / 60);
    if ($hours < 48) return $hours . ' jam';
    $days = (int) round($hours / 24);
    return $days . ' hari';
}
