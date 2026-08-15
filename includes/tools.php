<?php
declare(strict_types=1);

function ollama_tool_definitions(bool $includeRcPerformanceTool = true): array {
    $tools = [];

    $tools[] = [
        'type' => 'function',
        'function' => [
            'name' => 'hitung',
            'description' => 'Evaluasi ekspresi matematika secara presisi. Wajib dipakai untuk setiap aritmetika '
                . 'non-trivial (kali, bagi, pangkat, akar, persentase, trig, log, rangkaian langkah). '
                . 'Jangan menghitung manual. Persentase tulis sebagai desimal (0.9 bukan 90). '
                . 'Sudut trig dalam radian (derajat × pi/180).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'ekspresi' => [
                        'type' => 'string',
                        'description' => 'Ekspresi, mis. "2200*22.2", "(12/50)*(10/43)", "sqrt(2500)+ln(10)". '
                            . 'Desimal pakai titik (.), bukan koma.',
                    ],
                ],
                'required' => ['ekspresi'],
            ],
        ],
    ];

    $tools[] = [
        'type' => 'function',
        'function' => [
            'name' => 'hitung_batch',
            'description' => 'Hitung banyak perhitungan independen dalam SATU panggilan. Pakai untuk tabel, '
                . 'perbandingan multi-item, atau >1 angka yang TIDAK saling bergantung (kalau langkah berikutnya '
                . 'butuh hasil langkah lain, pakai hitung_berantai, bukan ini). Setiap item: label singkat, lalu '
                . '"ekspresi" (perhitungan umum) ATAU gigi_input+gigi_output (KHUSUS satu mesh gear/pulley/'
                . 'sproket/rasio transmisi -- WAJIB pakai pasangan field ini, DILARANG menulis pecahan gigi manual '
                . 'di "ekspresi" seperti "50/12", karena arah pembilang/penyebut mudah tertukar. ATURAN PASTI: '
                . '"pinion" SELALU masuk gigi_input, "spur"/"diferensial"/"gear besar" SELALU masuk gigi_output -- '
                . 'berlaku sama untuk tahap gearbox mana pun maupun final drive; sistem menghitung gigi_output/'
                . 'gigi_input secara pasti). Hasil dikembalikan berlabel. Maks 40 item per panggilan.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'perhitungan' => [
                        'type' => 'array',
                        'description' => 'Daftar 1–40 perhitungan independen.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Label singkat, mis. "rasio_buggy"'],
                                'ekspresi' => ['type' => 'string', 'description' => 'Ekspresi matematika lengkap untuk perhitungan UMUM (bukan mesh gigi tunggal). Kosongkan bila memakai gigi_input/gigi_output.'],
                                'gigi_input' => ['type' => 'number', 'description' => 'KHUSUS satu mesh gigi/pulley. ATURAN PASTI: jumlah gigi komponen yang di sebut "pinion" pada mesh ini (mis. "pinion motor 12t" -> isi 12; "final drive dengan pinion 12t dan diferensial 30t" -> isi 12; "compound gear dengan spur 50t dan pinion 20t" saat mesh masuk ke compound -> isi 20, sisi pinion-nya). "Pinion" SELALU di sini, tidak peduli itu di tahap gearbox mana pun.'],
                                'gigi_output' => ['type' => 'number', 'description' => 'KHUSUS satu mesh gigi/pulley, pasangan dari mesh yang SAMA dengan gigi_input. ATURAN PASTI: jumlah gigi komponen yang disebut "spur"/"diferensial"/"gear besar" pada mesh ini (mis. "pinion motor 12t dan spur 50t" -> isi 50; "final drive dengan pinion 12t dan diferensial 30t" -> isi 30). "Spur/diferensial" SELALU di sini. Sistem menghitung rasio = gigi_output/gigi_input otomatis -- pastikan angka tidak tertukar.'],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                ],
                'required' => ['perhitungan'],
            ],
        ],
    ];

    $tools[] = [
        'type' => 'function',
        'function' => [
            'name' => 'hitung_berantai',
            'description' => 'Hitung serangkaian langkah yang SALING BERGANTUNG dalam SATU panggilan (mis. rasio '
                . 'bertingkat -> RPM keluaran -> kecepatan; atau torsi -> arus -> daya; atau konversi satuan berlapis; '
                . 'berlaku untuk perhitungan apa pun, tidak spesifik ke satu domain). Langkah ke-N boleh memakai hasil '
                . 'langkah sebelumnya lewat variabel hasil1, hasil2, dst di dalam ekspresinya (mis. langkah 3 boleh '
                . 'menulis "hasil1 * hasil2"); nilainya disubstitusi persis oleh sistem, bukan diketik ulang, sehingga '
                . 'tidak ada risiko salah salin/lupa/salah pasang pembilang-penyebut/kesalahan magnitudo antar langkah. '
                . 'UNTUK SATU TAHAP/MESH TRANSMISI (gear, pulley, sproket, dsb): JANGAN menulis pecahan manual di '
                . '"ekspresi" (mis. "50/12") karena arah pembilang/penyebut mudah tertukar -- WAJIB isi field '
                . '"gigi_input" dan "gigi_output" pada langkah itu. ATURAN PASTI: "pinion" SELALU masuk gigi_input '
                . '(mis. "pinion motor 12t" -> gigi_input=12; "final drive dengan pinion 12t dan diferensial 30t" -> '
                . 'gigi_input=12), "spur"/"diferensial"/"gear besar" SELALU masuk gigi_output (mis. "spur 50t" -> '
                . 'gigi_output=50; "diferensial 30t" -> gigi_output=30) -- berlaku sama untuk tahap gearbox mana pun '
                . 'maupun final drive. Sistem menghitung rasionya (gigi_output/gigi_input) secara pasti, kosongkan '
                . '"ekspresi" untuk langkah semacam ini. '
                . 'Untuk gearbox bertingkat, buat satu langkah terpisah per mesh gigi berturut-turut, lalu satu langkah '
                . 'terakhir mengalikan seluruh hasil tahap itu (hasil1 * hasil2 * ...) menjadi rasio total. '
                . 'WAJIB dipakai (bukan hitung_batch) setiap kali langkah berikutnya butuh angka hasil langkah '
                . 'sebelumnya pada item yang sama -- hitung_batch hanya untuk angka-angka yang benar-benar independen.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'langkah' => [
                        'type' => 'array',
                        'description' => 'Daftar 1-40 langkah berurutan; langkah pertama = hasil1, kedua = hasil2, dst.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Nama besaran langkah ini, mis. "rasio_tahap1" atau "rasio_total"'],
                                'ekspresi' => ['type' => 'string', 'description' => 'Ekspresi matematika untuk langkah NON-mesh-gigi; boleh memuat hasil1, hasil2, ... dari langkah sebelumnya. Kosongkan bila memakai gigi_input/gigi_output.'],
                                'gigi_input' => ['type' => 'number', 'description' => 'Khusus satu mesh gigi/pulley. ATURAN PASTI: isi jumlah gigi komponen "pinion" pada mesh ini (berlaku di tahap mana pun, termasuk final drive). Isi bersama gigi_output; sistem menghitung rasio = gigi_output/gigi_input secara otomatis.'],
                                'gigi_output' => ['type' => 'number', 'description' => 'Khusus satu mesh gigi/pulley, pasangan gigi_input pada mesh yang SAMA. ATURAN PASTI: isi jumlah gigi komponen "spur"/"diferensial"/"gear besar" pada mesh ini.'],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                ],
                'required' => ['langkah'],
            ],
        ],
    ];

    if ((bool) app_config('web_search_enabled')) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'cari_web',
                'description' => 'Cari informasi terkini di internet (Wikipedia + web). Wajib untuk fakta yang '
                    . 'bisa berubah: jabatan, berita, harga, jadwal, hukum, produk, statistik. '
                    . 'Jangan jawab dari ingatan tanpa tool ini. Satu kueri spesifik per panggilan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'kueri' => [
                            'type' => 'string',
                            'description' => 'Kueri spesifik, mis. "Gubernur Jawa Barat 2026".',
                        ],
                        'jumlah_hasil' => [
                            'type' => 'integer',
                            'description' => 'Jumlah hasil (1–8). Default 5.',
                        ],
                    ],
                    'required' => ['kueri'],
                ],
            ],
        ];
    }

    $tools[] = [
        'type' => 'function',
        'function' => [
            'name' => 'hitung_performansi_rc',
            'description' => 'Hitung metrik performansi satu setup RC (buggy/truggy/crawler/speed-run/dragster/'
                . 'monster truck) secara deterministik: rasio total, kecepatan maks teoritis, torsi motor & roda, '
                . 'arus perkiraan di beberapa skenario beban, daya, power-to-weight. WAJIB dipakai untuk setiap '
                . 'setup RC yang diminta analisis angka (kecepatan/arus/torsi/daya/P-W). Satu panggilan = satu setup '
                . '(atau satu mode high/low). Untuk dual-speed, panggil dua kali (mode=high dan mode=low). '
                . 'Untuk mesin bensin, set sumber_tenaga="bensin" dan isi hp + rpm_max (jangan isi kv/s). '
                . 'Hasil tool adalah sumber kebenaran angka; salin digit persis ke tabel/jawaban.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'label' => [
                        'type' => 'string',
                        'description' => 'Nama setup, mis. "Buggy 1/5" atau "Crawler low".',
                    ],
                    'sumber_tenaga' => [
                        'type' => 'string',
                        'description' => '"elektrik" (default) atau "bensin".',
                    ],
                    'kv' => ['type' => 'number', 'description' => 'KV motor (hanya elektrik).'],
                    's' => ['type' => 'number', 'description' => 'Jumlah sel baterai (hanya elektrik), mis. 6, 8, 12.'],
                    'kode_motor' => [
                        'type' => 'string',
                        'description' => 'Kode ukuran stator, mis. "4274", "4885", "56113", "70125". Dipakai untuk estimasi kapasitas termal/daya.',
                    ],
                    'hp' => ['type' => 'number', 'description' => 'Tenaga maks mesin bensin (HP). Hanya untuk sumber_tenaga=bensin.'],
                    'rpm_max' => ['type' => 'number', 'description' => 'RPM maks mesin (bensin) atau batas kopling (dragster). Opsional.'],
                    'meshes' => [
                        'type' => 'array',
                        'description' => 'Daftar mesh gigi berurutan dari motor/mesin ke roda. Setiap item: {pinion: N, spur: M}. '
                            . 'Urutan: gearbox tahap 1, compound, spur output, final drive, dst. Untuk dual-speed, isi HANYA '
                            . 'jalur yang sedang dihitung (high ATAU low). Jangan melewatkan tahap.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'pinion' => ['type' => 'number', 'description' => 'Gigi pinion (driver) mesh ini.'],
                                'spur' => ['type' => 'number', 'description' => 'Gigi spur/diferensial/gear besar (driven) mesh ini.'],
                            ],
                            'required' => ['pinion', 'spur'],
                        ],
                    ],
                    'diameter_roda_mm' => ['type' => 'number', 'description' => 'Diameter roda/ban dalam mm (mis. 180).'],
                    'berat_bersih_kg' => ['type' => 'number', 'description' => 'Berat tanpa baterai/tangki (kg).'],
                    'berat_baterai_atau_bahan_bakar_kg' => [
                        'type' => 'number',
                        'description' => 'Perkiraan berat baterai atau bahan bakar (kg). Jika kosong, sistem menaksir ~0.25 kg per S untuk elektrik, ~3 kg untuk bensin.',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Untuk dual-speed: "high" atau "low". Opsional untuk setup biasa.',
                    ],
                    'penggunaan' => [
                        'type' => 'string',
                        'description' => 'Kelas penggunaan untuk memilih skenario beban: "buggy"|"truggy"|"crawler"|"speed_run"|"dragster"|"monster". Mempengaruhi target akselerasi & CdA.',
                    ],
                    'ada_kopling_sentrifugal' => [
                        'type' => 'boolean',
                        'description' => 'True jika ada kopling sentrifugal (dragster). RPM engagement diambil dari rpm_max jika diisi.',
                    ],
                    'esc_rating_a' => [
                        'type' => 'number',
                        'description' => 'Rating arus ESC (A) sebagai BATAS ATAS saja, bukan nilai operasi.',
                    ],
                ],
                'required' => ['label', 'meshes', 'diameter_roda_mm', 'berat_bersih_kg'],
            ],
        ],
    ];

    if (!$includeRcPerformanceTool) {
        $tools = array_values(array_filter($tools, static function (array $tool): bool {
            return (($tool['function']['name'] ?? '') !== 'hitung_performansi_rc');
        }));
    }

    return $tools;
}

function ollama_tool_execute(string $name, array $args): string {
    try {
        switch ($name) {
            case 'hitung':
                return tool_calculate((string)($args['ekspresi'] ?? ''));
            case 'hitung_batch':
                return tool_calculate_batch($args['perhitungan'] ?? []);
            case 'hitung_berantai':
                return tool_calculate_chain($args['langkah'] ?? []);
            case 'hitung_performansi_rc':
                return tool_hitung_performansi_rc($args);
            case 'cari_web':
                if (!(bool) app_config('web_search_enabled')) {
                    return 'Tool pencarian web dinonaktifkan pada konfigurasi server ini.';
                }
                $n = (int)($args['jumlah_hasil'] ?? 5);
                return tool_web_search((string)($args['kueri'] ?? ''), max(1, min(8, $n ?: 5)));
            default:
                return 'Tool tidak dikenal: ' . $name;
        }
    } catch (Throwable $e) {
        return 'Tool gagal dijalankan: ' . $e->getMessage();
    }
}

function tool_calc_set_reference_numbers(string $latestUserMessage): void {
    preg_match_all('/\d+(?:\.\d+)?/', $latestUserMessage, $m);
    $GLOBALS['TOOL_CALC_REFERENCE_NUMBERS'] = array_values(array_unique(array_map('floatval', $m[0] ?? [])));

    $GLOBALS['TOOL_RC_METHOD_NOTE_SHOWN'] = false;
}

function tool_calc_is_known_reference_number(float $n): bool {
    $ref = $GLOBALS['TOOL_CALC_REFERENCE_NUMBERS'] ?? null;
    if ($ref === null) return true;
    foreach ($ref as $r) {
        if (abs($r - $n) < 1e-9) return true;
    }
    return false;
}

function tool_calc_looks_calculation_heavy(): bool {
    return count($GLOBALS['TOOL_CALC_REFERENCE_NUMBERS'] ?? []) >= 5;
}

function tool_calc_resolve_step(array $step): array {
    $input_teeth = $step['gigi_input'] ?? null;
    $output_teeth = $step['gigi_output'] ?? null;
    if ($input_teeth !== null && $output_teeth !== null && is_numeric($input_teeth) && is_numeric($output_teeth)) {
        $input_teeth = (float) $input_teeth;
        $output_teeth = (float) $output_teeth;
        if ($input_teeth == 0.0) {
            return ['ok' => false, 'value' => null, 'text' => 'GAGAL: gigi_input tidak boleh nol.'];
        }
        if (!tool_calc_is_known_reference_number($input_teeth) || !tool_calc_is_known_reference_number($output_teeth)) {
            return ['ok' => false, 'value' => null, 'text' => "DITOLAK: gigi_input={$input_teeth} / gigi_output={$output_teeth} -- "
                . 'salah satu atau kedua angka ini TIDAK ditemukan pada teks pengguna. Jangan mengarang/menambah jumlah gigi '
                . 'yang tidak disebutkan -- periksa kembali data asli dan gunakan hanya angka yang benar-benar pengguna berikan.'];
        }
        $value = $output_teeth / $input_teeth;
        $formatted = tool_calc_format($value);
        return ['ok' => true, 'value' => $value, 'text' => "rasio tahap (gigi_output {$output_teeth} / gigi_input {$input_teeth}) = {$formatted}"];
    }
    $expr = (string)($step['ekspresi'] ?? '');
    if (tool_calc_looks_like_gear_fraction_chain($expr)) {
        return ['ok' => false, 'value' => null, 'text' => "DITOLAK: `{$expr}` terlihat seperti rasio mesh gigi/pulley/sproket "
            . 'ditulis manual di field ekspresi. Kosongkan "ekspresi" pada langkah/item ini dan isi field '
            . 'gigi_input (gigi "pinion") + gigi_output (gigi "spur"/"diferensial"/"gear besar") sebagai gantinya '
            . '-- satu langkah/item per mesh, jangan digabung.'];
    }
    try {
        $value = tool_calc_eval_expr($expr);
        $formatted = tool_calc_format($value);
        return ['ok' => true, 'value' => $value, 'text' => "`{$expr}` = {$formatted}"];
    } catch (Throwable $e) {
        return ['ok' => false, 'value' => null, 'text' => "GAGAL: `{$expr}` -> " . $e->getMessage()];
    }
}

function tool_calculate_batch($calculations): string {
    if (!is_array($calculations) || $calculations === []) return 'Daftar perhitungan kosong.';
    $calculations = array_slice($calculations, 0, 40);
    $lines = [];
    foreach ($calculations as $i => $calculation) {
        if (!is_array($calculation)) {
            $lines[] = 'Item ' . ($i + 1) . ': format tidak valid.';
            continue;
        }
        $label = trim((string)($calculation['label'] ?? ('item_' . ($i + 1))));
        $label = $label === '' ? 'item_' . ($i + 1) : mb_substr($label, 0, 80);
        $resolved = tool_calc_resolve_step($calculation);
        $lines[] = '[' . $label . '] ' . $resolved['text'];
    }
    return implode("\n", $lines);
}

function tool_calculate_chain($langkah): string {
    if (!is_array($langkah) || $langkah === []) return 'Daftar langkah kosong.';
    $langkah = array_slice($langkah, 0, 40);
    global $TOOL_CALC_CHAIN_VARS;
    $TOOL_CALC_CHAIN_VARS = [];
    $lines = [];
    foreach ($langkah as $i => $step) {
        $n = $i + 1;
        if (!is_array($step)) {
            $lines[] = "langkah{$n}: format tidak valid.";
            continue;
        }
        $label = trim((string)($step['label'] ?? ("langkah{$n}")));
        $label = $label === '' ? "langkah{$n}" : mb_substr($label, 0, 80);

        $resolved = tool_calc_resolve_step($step);
        if ($resolved['ok']) {
            $TOOL_CALC_CHAIN_VARS["hasil{$n}"] = $resolved['value'];
            $lines[] = "[hasil{$n} = {$label}] " . $resolved['text'];
        } else {

            $lines[] = "[hasil{$n} = {$label}] " . $resolved['text'];
            $lines[] = 'Perhitungan berantai dihentikan pada langkah ' . $n . ' karena error di atas; perbaiki langkah ini (variabel hasil1..hasil' . ($n - 1) . ' yang sudah dihitung tetap tersedia bila dipanggil ulang).';
            break;
        }
    }
    return implode("\n", $lines);
}

function tool_calc_looks_like_gear_fraction_chain(string $expr): bool {

    $stripped = preg_replace('/[\s()]+/', '', $expr) ?? $expr;

    // Perhatikan kuantifier '+' (bukan '*') pada grup berulang: pola ini
    // sengaja HANYA menandai RANGKAIAN >= 2 pecahan yang dikalikan
    // (mis. "12/50*15/45"), karena itulah pola khas seseorang menulis rasio
    // gigi bertingkat secara manual, tempat arah pembilang/penyebut paling
    // mudah tertukar dan sistem gigi_input/gigi_output memberi jaminan.
    // Sebelumnya kuantifier '*' membuat SATU pecahan biasa (mis. "10/0",
    // "5/2", "9/3") juga ikut cocok dan ditolak secara keliru -- padahal itu
    // pembagian dua angka yang benar-benar sah dan sama sekali bukan rasio
    // gigi. Pecahan tunggal sekarang dibiarkan lewat ke evaluator normal.
    return (bool) preg_match('/^\d{1,3}\/\d{1,3}([*x]\d{1,3}\/\d{1,3}){1,}$/i', $stripped);
}

function tool_calculate(string $expr): string {
    global $TOOL_CALC_CHAIN_VARS;
    $TOOL_CALC_CHAIN_VARS = [];
    $expr = trim($expr);
    if ($expr === '') return 'Ekspresi kosong.';
    if (strlen($expr) > 500) return 'Ekspresi terlalu panjang.';
    if (tool_calc_looks_like_gear_fraction_chain($expr)) {
        return 'DITOLAK: `' . $expr . '` terlihat seperti rasio mesh gigi/pulley/sproket yang ditulis manual. '
            . 'Tool "hitung" TIDAK boleh dipakai untuk ini -- arah pembilang/penyebut mudah tertukar. '
            . 'Gunakan hitung_batch atau hitung_berantai, dan isi field gigi_input (jumlah gigi "pinion") '
            . 'serta gigi_output (jumlah gigi "spur"/"diferensial"/"gear besar") untuk SETIAP tahap/mesh secara '
            . 'terpisah -- jangan menggabungkan beberapa tahap jadi satu pecahan/ekspresi. Sistem yang akan '
            . 'menghitung rasio (gigi_output/gigi_input) secara pasti, bukan Anda.';
    }

    try {
        $value = tool_calc_eval_expr($expr);
        $formatted = tool_calc_format($value);
        return "Hasil dari `{$expr}` = {$formatted}";
    } catch (Throwable $e) {
        return 'Ekspresi tidak valid: ' . $e->getMessage();
    }
}

function tool_calc_eval_expr(string $expr): float {
    $tokens = tool_calc_tokenize($expr);
    $pos = 0;
    $value = tool_calc_expr($tokens, $pos);
    if ($pos !== count($tokens)) {
        throw new RuntimeException('Karakter tak terduga dekat posisi token ' . $pos);
    }
    if (is_nan($value) || is_infinite($value)) {
        throw new RuntimeException('Hasil tidak terdefinisi (NaN/tak hingga)');
    }
    return $value;
}

function tool_calc_format(float $v): string {
    if ($v === 0.0) {
        return '0';
    }
    $absV = abs($v);
    // "Bilangan bulat rapi" hanya diperiksa pada rentang di mana float masih
    // presisi (< 1e15) DAN nilainya tidak sangat kecil. Sebelumnya toleransi
    // absolut 1e-9 dipakai untuk SEMUA magnitude, sehingga angka kecil yang
    // sah (mis. 1e-14 hasil perkalian dua bilangan sangat kecil) ikut
    // dibulatkan ke 0 hanya karena "dekat" dengan 0 secara absolut -- padahal
    // nilainya memang kecil, bukan nol. Sekarang pembulatan-ke-bulat hanya
    // berlaku untuk magnitude wajar (>= 1e-6) agar tidak menelan presisi.
    if ($absV >= 1e-6 && $absV < 1e15 && abs($v - round($v)) < 1e-9) {
        return (string) (int) round($v);
    }
    // Untuk magnitude sangat besar atau sangat kecil, format desimal tetap
    // (%.10F lalu rtrim '0') merusak angka: rtrim tidak berhenti di titik
    // desimal, sehingga nol-nol signifikan di bagian bilangan bulat (mis.
    // 170! yang berakhir dengan puluhan nol) ikut terpotong bersama nol
    // pecahan, menghasilkan "0". Notasi umum/ilmiah (%G) aman untuk kedua
    // arah magnitude dan tidak punya masalah rtrim ini.
    $formatted = sprintf('%.10G', $v);
    return str_replace('E', 'e', $formatted);
}

function tool_calc_tokenize(string $expr): array {
    $tokens = [];
    $len = strlen($expr);
    $i = 0;
    while ($i < $len) {
        $c = $expr[$i];
        if (ctype_space($c)) { $i++; continue; }
        if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
            $start = $i;
            $seenDot = false;
            while ($i < $len && (ctype_digit($expr[$i]) || ($expr[$i] === '.' && !$seenDot))) {
                if ($expr[$i] === '.') $seenDot = true;
                $i++;
            }
            $tokens[] = ['type' => 'num', 'value' => (float) substr($expr, $start, $i - $start)];
            continue;
        }
        if (ctype_alpha($c) || $c === '_') {
            $start = $i;
            while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) $i++;
            $tokens[] = ['type' => 'ident', 'value' => strtolower(substr($expr, $start, $i - $start))];
            continue;
        }
        if (strpos('+-*/%^(),!', $c) !== false) {
            $tokens[] = ['type' => 'op', 'value' => $c];
            $i++;
            continue;
        }
        throw new RuntimeException("Karakter tidak dikenali: '{$c}'");
    }
    return $tokens;
}

function tool_calc_peek(array $tokens, int $pos): ?array {
    return $tokens[$pos] ?? null;
}

function tool_calc_expr(array $tokens, int &$pos): float {
    $value = tool_calc_term($tokens, $pos);
    while (($t = tool_calc_peek($tokens, $pos)) !== null && $t['type'] === 'op' && in_array($t['value'], ['+', '-'], true)) {
        $pos++;
        $rhs = tool_calc_term($tokens, $pos);
        $value = $t['value'] === '+' ? $value + $rhs : $value - $rhs;
    }
    return $value;
}

function tool_calc_term(array $tokens, int &$pos): float {
    $value = tool_calc_power($tokens, $pos);
    while (($t = tool_calc_peek($tokens, $pos)) !== null && $t['type'] === 'op' && in_array($t['value'], ['*', '/', '%'], true)) {
        $pos++;
        $rhs = tool_calc_power($tokens, $pos);
        if ($t['value'] === '*') $value *= $rhs;
        elseif ($t['value'] === '/') {
            if ($rhs == 0.0) throw new RuntimeException('Pembagian dengan nol');
            $value /= $rhs;
        } else {
            if ($rhs == 0.0) throw new RuntimeException('Modulo dengan nol');
            $value = fmod($value, $rhs);
        }
    }
    return $value;
}

function tool_calc_power(array $tokens, int &$pos): float {
    $value = tool_calc_unary($tokens, $pos);
    if (($t = tool_calc_peek($tokens, $pos)) !== null && $t['type'] === 'op' && $t['value'] === '^') {
        $pos++;
        $rhs = tool_calc_power($tokens, $pos);
        $value = $value ** $rhs;
    }
    return $value;
}

function tool_calc_unary(array $tokens, int &$pos): float {
    $t = tool_calc_peek($tokens, $pos);
    if ($t !== null && $t['type'] === 'op' && ($t['value'] === '-' || $t['value'] === '+')) {
        $pos++;
        $val = tool_calc_unary($tokens, $pos);
        return $t['value'] === '-' ? -$val : $val;
    }
    return tool_calc_postfix($tokens, $pos);
}

function tool_calc_postfix(array $tokens, int &$pos): float {
    $value = tool_calc_primary($tokens, $pos);
    $t = tool_calc_peek($tokens, $pos);
    if ($t !== null && $t['type'] === 'op' && $t['value'] === '!') {
        $pos++;
        if ($value < 0 || abs($value - round($value)) > 1e-9 || $value > 170) {
            throw new RuntimeException('Faktorial hanya untuk bilangan bulat non-negatif <= 170');
        }
        $n = (int) round($value);
        $result = 1.0;
        for ($k = 2; $k <= $n; $k++) $result *= $k;
        $value = $result;
    }
    return $value;
}

const TOOL_CALC_CONSTANTS = ['pi' => M_PI, 'e' => M_E];
const TOOL_CALC_FUNCTIONS_1ARG = [
    'sqrt' => 'sqrt', 'abs' => 'abs', 'ln' => 'log', 'log10' => 'log10', 'log2' => 'log2',
    'sin' => 'sin', 'cos' => 'cos', 'tan' => 'tan', 'asin' => 'asin', 'acos' => 'acos', 'atan' => 'atan',
    'exp' => 'exp', 'floor' => 'floor', 'ceil' => 'ceil', 'round' => 'round',
];

function tool_calc_primary(array $tokens, int &$pos): float {
    $t = tool_calc_peek($tokens, $pos);
    if ($t === null) throw new RuntimeException('Ekspresi tidak lengkap');

    if ($t['type'] === 'num') { $pos++; return (float) $t['value']; }

    if ($t['type'] === 'op' && $t['value'] === '(') {
        $pos++;
        $value = tool_calc_expr($tokens, $pos);
        $close = tool_calc_peek($tokens, $pos);
        if ($close === null || $close['value'] !== ')') throw new RuntimeException('Kurung tutup hilang');
        $pos++;
        return $value;
    }

    if ($t['type'] === 'ident') {
        $name = $t['value'];
        $pos++;
        $next = tool_calc_peek($tokens, $pos);
        if ($next !== null && $next['type'] === 'op' && $next['value'] === '(') {
            $pos++;
            $args = [];
            $closeCheck = tool_calc_peek($tokens, $pos);
            if ($closeCheck === null || $closeCheck['value'] !== ')') {
                $args[] = tool_calc_expr($tokens, $pos);
                while (($c = tool_calc_peek($tokens, $pos)) !== null && $c['value'] === ',') {
                    $pos++;
                    $args[] = tool_calc_expr($tokens, $pos);
                }
            }
            $close = tool_calc_peek($tokens, $pos);
            if ($close === null || $close['value'] !== ')') throw new RuntimeException('Kurung tutup fungsi hilang');
            $pos++;

            if ($name === 'log') {
                if (count($args) === 1) return log($args[0]);
                if (count($args) === 2) return log($args[0], $args[1]);
                throw new RuntimeException('log() butuh 1 atau 2 argumen');
            }
            if (isset(TOOL_CALC_FUNCTIONS_1ARG[$name])) {
                if (count($args) !== 1) throw new RuntimeException("Fungsi {$name}() butuh 1 argumen");
                $fn = TOOL_CALC_FUNCTIONS_1ARG[$name];
                if ($fn === 'log2') return log($args[0], 2);
                if ($fn === 'log10') return log10($args[0]);
                return $fn($args[0]);
            }
            if (empty($args) && isset(TOOL_CALC_CONSTANTS[$name])) return TOOL_CALC_CONSTANTS[$name];
            throw new RuntimeException("Fungsi tidak dikenal: {$name}()");
        }
        if (isset(TOOL_CALC_CONSTANTS[$name])) return TOOL_CALC_CONSTANTS[$name];
        global $TOOL_CALC_CHAIN_VARS;
        if (is_array($TOOL_CALC_CHAIN_VARS) && array_key_exists($name, $TOOL_CALC_CHAIN_VARS)) {
            return (float) $TOOL_CALC_CHAIN_VARS[$name];
        }
        throw new RuntimeException("Identifier tidak dikenal: {$name}");
    }

    throw new RuntimeException('Token tidak terduga');
}

function tool_http_get(string $url, array $headers = [], int $timeout = 8): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge([
            'User-Agent: Mozilla/5.0 (compatible; SigitAI-Assistant/1.0; +internal-chat-tool)',
        ], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    return $body;
}

function tool_wikipedia_lookup(string $query): ?array {
    $searchUrl = 'https://id.wikipedia.org/w/api.php?action=query&list=search&format=json&srlimit=1&srsearch='
        . urlencode($query);
    $searchBody = tool_http_get($searchUrl);
    if ($searchBody === null) return null;
    $searchData = json_decode($searchBody, true);
    $title = $searchData['query']['search'][0]['title'] ?? null;
    if (!$title) return null;

    $summaryUrl = 'https://id.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $title));
    $summaryBody = tool_http_get($summaryUrl);
    if ($summaryBody === null) return null;
    $summaryData = json_decode($summaryBody, true);
    $extract = $summaryData['extract'] ?? null;
    if (!$extract) return null;

    return [
        'title' => $summaryData['title'] ?? $title,
        'snippet' => $extract,
        'url' => $summaryData['content_urls']['desktop']['page'] ?? ('https://id.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title))),
    ];
}

function tool_duckduckgo_search(string $query, int $maxResults): array {
    $url = 'https://html.duckduckgo.com/html/?kl=id-id&q=' . urlencode($query);
    $body = tool_http_get($url, ['Content-Type: application/x-www-form-urlencoded']);
    if ($body === null) return [];

    $results = [];
    if (preg_match_all(
        '/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>.*?<a[^>]+class="result__snippet"[^>]*>(.*?)<\/a>/is',
        $body,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            if (count($results) >= $maxResults) break;
            $link = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);

            if (preg_match('/[?&]uddg=([^&]+)/', $link, $lm)) {
                $link = urldecode($lm[1]);
            }
            $results[] = [
                'title' => trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5)),
                'snippet' => trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5)),
                'url' => $link,
            ];
        }
    }
    return $results;
}

function tool_web_search(string $query, int $maxResults = 5): string {
    $query = trim($query);
    if ($query === '') return 'Kueri pencarian kosong.';

    static $cache = [];
    $cacheKey = mb_strtolower($query) . ':' . $maxResults;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $maxResults = max(1, min(3, $maxResults));

    $lines = [];
    $wiki = null;
    try { $wiki = tool_wikipedia_lookup($query); } catch (Throwable $e) { $wiki = null; }
    if ($wiki !== null) {
        $lines[] = "[Wikipedia] {$wiki['title']}: " . mb_substr($wiki['snippet'], 0, 500) . " (Sumber: {$wiki['url']})";
    }

    $webResults = [];
    try { $webResults = tool_duckduckgo_search($query, $maxResults); } catch (Throwable $e) { $webResults = []; }
    foreach ($webResults as $r) {
        if ($r['title'] === '' && $r['snippet'] === '') continue;
        $lines[] = "[Web] {$r['title']}: " . mb_substr($r['snippet'], 0, 350) . " (Sumber: {$r['url']})";
    }

    if (empty($lines)) {
        return $cache[$cacheKey] = 'Pencarian tidak menghasilkan apa pun (kemungkinan server tidak memiliki akses internet keluar, '
            . 'atau kueri terlalu spesifik/tidak ditemukan). Sampaikan ke pengguna bahwa informasi ini tidak dapat '
            . 'diverifikasi saat ini, jangan mengarang jawaban.';
    }

    $today = date('Y-m-d');
    array_unshift($lines, "Hasil pencarian untuk \"{$query}\" (diambil {$today}). "
        . 'Gunakan hanya info di bawah ini, jangan gabungkan dengan asumsi/ingatan lama. '
        . 'Jika hasil dari beberapa sumber berbeda tanggal, prioritaskan yang paling baru dan sebutkan tanggalnya bila tersedia.');

    return $cache[$cacheKey] = implode("\n", $lines);
}

function tool_hitung_performansi_rc(array $args): string {
    $label = trim((string)($args['label'] ?? 'RC'));
    $sumber = strtolower(trim((string)($args['sumber_tenaga'] ?? 'elektrik')));
    $isBensin = ($sumber === 'bensin' || $sumber === 'gasoline' || $sumber === 'mesin');
    $kv = isset($args['kv']) ? (float)$args['kv'] : 0.0;
    $s = isset($args['s']) ? (float)$args['s'] : 0.0;
    $kodeMotor = trim((string)($args['kode_motor'] ?? ''));
    $hp = isset($args['hp']) ? (float)$args['hp'] : 0.0;
    $rpmMaxGiven = isset($args['rpm_max']) ? (float)$args['rpm_max'] : 0.0;
    $meshes = $args['meshes'] ?? [];
    $diaMm = (float)($args['diameter_roda_mm'] ?? 0);
    $beratBersih = (float)($args['berat_bersih_kg'] ?? 0);
    $beratBb = isset($args['berat_baterai_atau_bahan_bakar_kg'])
        ? (float)$args['berat_baterai_atau_bahan_bakar_kg']
        : null;
    $mode = trim((string)($args['mode'] ?? ''));
    $penggunaan = strtolower(trim((string)($args['penggunaan'] ?? 'buggy')));
    $adaKopling = !empty($args['ada_kopling_sentrifugal']);
    $escRating = isset($args['esc_rating_a']) ? (float)$args['esc_rating_a'] : 0.0;

    $lines = [];
    $lines[] = "=== HASIL hitung_performansi_rc: {$label}" . ($mode !== '' ? " [mode={$mode}]" : '') . " ===";

    if ($diaMm <= 0 || $beratBersih <= 0) {
        return "GAGAL: diameter_roda_mm dan berat_bersih_kg wajib > 0.";
    }
    if (!is_array($meshes) || count($meshes) === 0) {
        return "GAGAL: meshes wajib berisi minimal satu tahap {pinion, spur}.";
    }
    if (!$isBensin && ($kv <= 0 || $s <= 0)) {
        return "GAGAL: untuk sumber elektrik, kv dan s wajib > 0.";
    }
    if ($isBensin && $hp <= 0) {
        return "GAGAL: untuk sumber bensin, hp wajib > 0.";
    }

    $vNom = 3.7;
    $voltage = $isBensin ? 0.0 : ($s * $vNom);
    $rpmNoLoad = $isBensin
        ? ($rpmMaxGiven > 0 ? $rpmMaxGiven : 12000.0)
        : ($kv * $voltage);

    $rpmSumber = $rpmNoLoad;
    $clutchEngagementRpm = 0.0;
    if ($adaKopling && $rpmMaxGiven > 0) {
        $clutchEngagementRpm = $rpmMaxGiven;
        $lines[] = "Catatan: kopling sentrifugal engagement ≈ {$clutchEngagementRpm} rpm "
            . "(memengaruhi launch/transisi, BUKAN membatasi Vmax — Vmax memakai RPM no-load penuh).";
    }

    $rasioTahap = [];
    $rasioTotal = 1.0;
    $i = 1;
    foreach ($meshes as $m) {
        $pin = (float)($m['pinion'] ?? 0);
        $sp = (float)($m['spur'] ?? 0);
        if ($pin <= 0 || $sp <= 0) {
            return "GAGAL: mesh #{$i} pinion/spur tidak valid (harus > 0).";
        }
        $r = $sp / $pin;
        $rasioTahap[] = sprintf('tahap%d: %g/%g = %.4f', $i, $sp, $pin, $r);
        $rasioTotal *= $r;
        $i++;
    }

    $diaM = $diaMm / 1000.0;
    $radiusM = $diaM / 2.0;
    $kelilingM = M_PI * $diaM;

    $rpmRoda = $rpmSumber / $rasioTotal;
    $vMs = ($rpmRoda / 60.0) * $kelilingM;
    $vKmh = $vMs * 3.6;

    if ($beratBb === null) {
        if ($isBensin) {
            $beratBb = 3.0;
        } else {
            $beratBb = max(0.8, 0.22 * $s);
        }
    }
    $beratTotal = $beratBersih + $beratBb;
    $g = 9.81;

    $accelG = 0.6;
    $cda = 0.25;
    $cr = 0.02;
    switch ($penggunaan) {
        case 'crawler':
            $accelG = 0.35;
            $cda = 0.40;
            $cr = 0.04;
            break;
        case 'dragster':
            $accelG = 1.2;
            $cda = 0.18;
            $cr = 0.015;
            break;
        case 'speed_run':
        case 'speed-run':
        case 'speedrun':
            $accelG = 0.5;
            $cda = 0.12;
            $cr = 0.012;
            break;
        case 'monster':
        case 'monster_truck':
            $accelG = 0.7;
            $cda = 0.55;
            $cr = 0.035;
            break;
        case 'truggy':
            $accelG = 0.75;
            $cda = 0.30;
            $cr = 0.025;
            break;
        case 'buggy':
        default:
            $accelG = 0.85;
            $cda = 0.28;
            $cr = 0.022;
            break;
    }

    $isKelasCepat = in_array($penggunaan, ['dragster', 'speed_run', 'speed-run', 'speedrun'], true);
    if (!$isKelasCepat && ($rasioTotal < 3.0 || $vKmh > 180.0)) {
        $lines[] = "PERINGATAN VERIFIKASI: rasio total (" . tool_calc_format($rasioTotal) . ":1) atau "
            . "kecepatan hasil (" . tool_calc_format($vKmh) . " km/h) janggal untuk kelas '{$penggunaan}' "
            . "(biasanya rasio total ≥3:1 dan jauh di bawah kelas speed-run/dragster). Ini SERING disebabkan "
            . "compound gear salah dipecah jadi mesh -- compound gear adalah DUA gear sepasang di SATU as: "
            . "sisi spur (menerima dari pinion tahap sebelumnya) dan sisi pinion (meneruskan ke spur tahap "
            . "berikutnya) HARUS jadi DUA entri mesh {pinion,spur} terpisah, bukan satu. Sebelum "
            . "melaporkan angka ini, hitung ulang rasio tiap tahap secara manual dari data mentah pengguna "
            . "dan bandingkan -- bila beda, panggil ulang tool ini dengan meshes yang diperbaiki.";
    }
    if ($vKmh > 350.0) {
        $lines[] = "PERINGATAN VERIFIKASI: kecepatan " . tool_calc_format($vKmh) . " km/h melebihi batas "
            . "fisik wajar RC 1/5 mana pun -- ada kesalahan input (rasio meshes, diameter roda, atau "
            . "RPM sumber). Cek ulang sebelum melaporkan.";
    }

    $vCruiseMs = $vMs * 0.75;
    $fRoll = $cr * $beratTotal * $g;
    $rho = 1.2;
    $fAero = 0.5 * $rho * $cda * ($vCruiseMs * $vCruiseMs);
    $fCruise = $fRoll + $fAero;
    $torsiRodaCruise = $fCruise * $radiusM;

    $a = $accelG * $g;
    $fAccel = $beratTotal * $a + $fRoll;
    $torsiRodaAccel = $fAccel * $radiusM;

    $torsiRoda = max($torsiRodaCruise, $torsiRodaAccel);
    $skenarioDominan = ($torsiRodaAccel >= $torsiRodaCruise) ? 'akselerasi/launch' : 'cruise@~75%Vmax (roll+aero)';

    $effTrans = 0.85;

    $kt = 0.0;
    $arusCruise = 0.0; $arusAccel = 0.0; $arusPeak = 0.0;
    $torsiMotorCruise = 0.0; $torsiMotorAccel = 0.0; $torsiMotorPeak = 0.0;
    $torsiRodaCruiseFinal = 0.0; $torsiRodaAccelFinal = 0.0; $torsiRodaPeakFinal = 0.0;
    $torsiMesin = 0.0;
    $dayaMekanikW = 0.0;

    $volMm3 = tool_rc_motor_volume_mm3($kodeMotor);
    $volNote = ($volMm3 > 0)
        ? sprintf('volume stator kasar ≈ %s mm³ (dari kode %s)', tool_calc_format($volMm3), $kodeMotor)
        : 'kode motor tidak terbaca — volume tidak dipakai';

    $arusTermalKapasitas = 0.0;
    if ($volMm3 > 0) {
        $arusTermalKapasitas = ($volMm3 / 1000.0) * 0.95;
    }

    if ($isBensin) {
        $dayaMekanikW = $hp * 745.7;
        $omega = 2.0 * M_PI * ($rpmSumber * 0.7) / 60.0;
        $torsiMesin = ($omega > 0) ? ($dayaMekanikW / $omega) : 0.0;
        $lines[] = "Sumber: mesin bensin ≈ {$hp} HP → daya mekanik ≈ " . round($dayaMekanikW) . " W";
        $lines[] = "Torsi sumber (perkiraan di ~70% RPM maks) ≈ " . tool_calc_format($torsiMesin) . " Nm";
        $lines[] = "Arus: tidak berlaku (bukan elektrik)";
    } else {
        $kt = 60.0 / (2.0 * M_PI * max($kv, 1.0));

        $arusCruiseMentah = $torsiRodaCruise / ($rasioTotal * $effTrans * $kt);
        $arusAccelMentah  = $torsiRodaAccel  / ($rasioTotal * $effTrans * $kt);

        if ($escRating > 0) {
            $arusMaxUsable = $escRating * 0.95;
        } elseif ($arusTermalKapasitas > 0) {
            $arusMaxUsable = $arusTermalKapasitas * 1.3;
        } else {
            $arusMaxUsable = max($arusAccelMentah * 1.5, 50.0);
        }
        $arusPeak = max($arusAccelMentah, $arusMaxUsable);
        if ($escRating > 0) {
            $arusPeak = min($arusPeak, $escRating);
        }
        $arusAccel  = min($arusAccelMentah, $arusPeak);
        $arusCruise = min($arusCruiseMentah, $arusAccel);

        $torsiMotorCruise = $arusCruise * $kt;
        $torsiMotorAccel  = $arusAccel  * $kt;
        $torsiMotorPeak   = $arusPeak   * $kt;
        $torsiRodaCruiseFinal = $torsiMotorCruise * $rasioTotal * $effTrans;
        $torsiRodaAccelFinal  = $torsiMotorAccel  * $rasioTotal * $effTrans;
        $torsiRodaPeakFinal   = $torsiMotorPeak   * $rasioTotal * $effTrans;

        $dayaMekanikW = $voltage * $arusPeak * 0.82 * $effTrans;

        $lines[] = "Ukuran motor: {$volNote}";
        if ($arusTermalKapasitas > 0) {
            $lines[] = "Kapasitas arus termal kasar motor ≈ " . tool_calc_format($arusTermalKapasitas)
                . " A (dari volume stator -- HANYA untuk cek panas, tidak dipakai menghitung torsi/arus/daya)";
        }
        $lines[] = "Kt ≈ " . tool_calc_format($kt) . " Nm/A (∝ 1/KV)";

        if ($arusAccelMentah > $arusMaxUsable * 1.02) {
            $lines[] = "PERINGATAN: kebutuhan arus akselerasi target kelas {$penggunaan} (≈"
                . tool_calc_format($arusAccelMentah) . " A) melebihi batas aman ESC/motor (≈"
                . tool_calc_format($arusMaxUsable) . " A) -- akselerasi AKTUAL kemungkinan lebih "
                . "lambat dari target kelas ini, atau ESC/motor perlu diperbesar / rasio gear diperpendek.";
        }
        if ($arusTermalKapasitas > 0 && $arusAccel > $arusTermalKapasitas * 1.15) {
            $lines[] = "PERINGATAN: arus pada skenario agresif > kapasitas termal kasar motor -- risiko "
                . "panas berlebih bila akselerasi keras dilakukan berulang/lama (bukan sekali burst singkat).";
        }
    }

    $pw = ($beratTotal > 0) ? ($dayaMekanikW / 1000.0) / $beratTotal : 0.0;

    $lines[] = "--- Parameter input yang dipakai ---";
    $lines[] = "Sumber tenaga: " . ($isBensin ? "bensin" : "elektrik")
        . ($kodeMotor !== '' ? ", kode motor {$kodeMotor}" : '')
        . ($volMm3 > 0 ? ", volume≈" . round($volMm3) . " mm³" : '');
    if (!$isBensin) {
        $lines[] = "KV={$kv}, S={$s}, Vnom≈" . tool_calc_format($voltage) . " V, RPM no-load≈" . round($rpmNoLoad);
        if ($clutchEngagementRpm > 0) {
            $lines[] = "Kopling engagement≈{$clutchEngagementRpm} rpm (hanya launch; Vmax tetap dari RPM no-load)";
        }
    } else {
        $lines[] = "HP={$hp}, RPM maks≈" . round($rpmNoLoad);
    }
    $lines[] = "Meshes: " . implode(' × ', $rasioTahap);
    $lines[] = "Rasio total (RPM sumber / RPM roda) = " . tool_calc_format($rasioTotal) . " : 1";
    $lines[] = "Diameter roda={$diaMm} mm, keliling≈" . tool_calc_format($kelilingM) . " m";
    $lines[] = "Berat bersih={$beratBersih} kg + baterai/BB≈" . tool_calc_format($beratBb) . " kg → total≈" . tool_calc_format($beratTotal) . " kg";
    $lines[] = "Penggunaan/kelas: {$penggunaan} (target accel≈{$accelG} g, Cr≈{$cr}, CdA≈{$cda} m²)";

    $lines[] = "--- Hasil utama (arus/torsi/daya = RENTANG 2 titik rendah-tinggi -- SALIN SEBAGAI RENTANG, JANGAN diringkas jadi satu angka) ---";
    $lines[] = "kecepatan_maks_teoritis_kmh = " . tool_calc_format($vKmh);
    $lines[] = "rpm_roda = " . tool_calc_format($rpmRoda);
    if ($isBensin) {
        $lines[] = "torsi_sumber_Nm = " . tool_calc_format($torsiMesin);
        $lines[] = "torsi_roda_Nm = " . tool_calc_format($torsiMesin * $rasioTotal * $effTrans);
        $lines[] = "arus_perkiraan_A = tidak berlaku (bukan elektrik)";
        $lines[] = "daya_maks_perkiraan_kW = " . tool_calc_format($dayaMekanikW / 1000.0);
    } else {
        $lines[] = "arus_perkiraan_A = rendah≈" . tool_calc_format($arusCruise) . " – tinggi≈" . tool_calc_format($arusPeak)
            . " A" . ($escRating > 0 ? " | batas ESC {$escRating} A" : '');
        $lines[] = "torsi_sumber_Nm = rendah≈" . tool_calc_format($torsiMotorCruise) . " – tinggi≈" . tool_calc_format($torsiMotorPeak);
        $lines[] = "torsi_roda_Nm = rendah≈" . tool_calc_format($torsiRodaCruiseFinal) . " – tinggi≈" . tool_calc_format($torsiRodaPeakFinal);
        $lines[] = "daya_maks_perkiraan_kW (pada skenario peak) = " . tool_calc_format($dayaMekanikW / 1000.0);
        if ($arusTermalKapasitas > 0) {
            $lines[] = "arus_kapasitas_termal_motor_A = " . tool_calc_format($arusTermalKapasitas);
        }
    }
    $lines[] = "power_to_weight_kW_per_kg (pada skenario peak) = " . tool_calc_format($pw);
    if ($volMm3 > 0) {
        $lines[] = "volume_stator_mm3 = " . tool_calc_format($volMm3);
    }

    $showFullMethodNote = empty($GLOBALS['TOOL_RC_METHOD_NOTE_SHOWN']);
    $GLOBALS['TOOL_RC_METHOD_NOTE_SHOWN'] = true;

    $lines[] = "--- Catatan metodologi (Babak 22/25) ---";
    if ($showFullMethodNote) {
        $lines[] = "Vmax memakai RPM no-load penuh (kopling sentrifugal HANYA memengaruhi launch, bukan Vmax). "
            . "Rasio = ∏(spur/pinion). Arus/torsi/daya elektrik dilaporkan sebagai RENTANG 2 titik "
            . "(rendah=cruise / tinggi=peak) lewat SATU model listrik konsisten (I=T_roda/(rasio×eff×Kt), "
            . "T_motor=I×Kt, T_roda=T_motor×rasio×eff, P=V×I×0.82×eff) -- torsi roda SELALU naik seiring "
            . "rasio gear lebih panjang untuk arus motor yang sama (rasio panjang → torsi roda lebih tinggi), "
            . "dan arus tinggi/peak tidak pernah melebihi rating ESC bila diketahui (dijamin oleh kode, bukan cuma "
            . "instruksi). Volume stator (kode ukuran motor) HANYA dipakai untuk kapasitas termal kasar "
            . "(cek panas/peringatan), bukan lagi untuk menghitung torsi/arus/daya langsung -- jalur volume→daya "
            . "versi lama terbukti bisa menghasilkan arus FINAL yang melebihi rating ESC sendiri. "
            . "Crawler rasio tinggi → torsi/arus beban tinggi meski arus motor kecil adalah NORMAL. "
            . "Efisiensi transmisi 0.85, motor ~0.82. Perkiraan model dengan ketidakpastian nyata, bukan dyno -- "
            . "gunakan rentangnya, jangan jadikan satu titik sebagai fakta pasti.";
    } else {
        $lines[] = "RENTANG 2 titik rendah-tinggi -- WAJIB disalin utuh, JANGAN diringkas jadi satu "
            . "angka/dirata-rata; arus tinggi/peak tidak pernah melebihi rating ESC (dijamin sistem). "
            . "(Penjelasan lengkap: lihat panggilan pertama tool ini pada giliran jawaban ini.)";
    }

    return implode("\n", $lines);
}

function tool_rc_motor_volume_mm3(string $kode): float {
    $kode = preg_replace('/[^0-9]/', '', $kode);
    if ($kode === '' || strlen($kode) < 4) {
        return 0.0;
    }

    if (strlen($kode) === 4) {
        $d = (float)substr($kode, 0, 2);
        $l = (float)substr($kode, 2, 2);
    } elseif (strlen($kode) === 5) {

        $d = (float)substr($kode, 0, 2);
        $l = (float)substr($kode, 2, 3);
        if ($d < 20 || $d > 90) {
            $d = (float)substr($kode, 0, 3);
            $l = (float)substr($kode, 3, 2);
        }
    } else {
        $d = (float)substr($kode, 0, 2);
        $l = (float)substr($kode, 2);
    }
    if ($d <= 0 || $l <= 0) {
        return 0.0;
    }

    return M_PI * ($d / 2.0) * ($d / 2.0) * $l;
}
