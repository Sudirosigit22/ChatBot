<?php
declare(strict_types=1);

/*
 * Test suite untuk fungsi kalkulasi deterministik (tool_calculate,
 * tool_calculate_batch, tool_calculate_chain) di includes/tools.php.
 *
 * Dibuat sebagai tindak lanjut catatan dosen: "Zero test - tidak ada
 * satupun test otomatis untuk memverifikasi kalkulasi presisi bekerja
 * benar". Berisi >= 20 kasus uji termasuk edge case (pembagian nol,
 * modulo nol, angka sangat besar/kecil, faktorial di luar batas, hasil
 * tak terdefinisi, ekspresi tidak valid, dan rangkaian bertahap).
 *
 * Jalankan: php tests/CalculatorTest.php
 */

require __DIR__ . '/../includes/bootstrap.php';

$total = 0;
$failed = 0;

/**
 * Membandingkan angka hasil kalkulator (string) terhadap nilai referensi
 * dengan toleransi mengambang, untuk menghindari kegagalan semu akibat
 * pembulatan representasi desimal.
 */
function assert_result_contains_number(string $resultText, float $expected, string $label, float $tolerance = 1e-6): void {
    global $total, $failed;
    $total++;
    if (!preg_match('/=\s*(-?[0-9]+(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?)\s*$/i', trim($resultText), $m)) {
        $failed++;
        echo "FAIL [{$label}]: tidak menemukan angka hasil pada: {$resultText}\n";
        return;
    }
    $actual = (float) $m[1];
    if (abs($actual - $expected) > $tolerance * max(1.0, abs($expected))) {
        $failed++;
        echo "FAIL [{$label}]: diharapkan ~{$expected}, didapat {$actual} (dari: {$resultText})\n";
        return;
    }
    echo "PASS [{$label}]\n";
}

function assert_true(bool $condition, string $label): void {
    global $total, $failed;
    $total++;
    if (!$condition) {
        $failed++;
        echo "FAIL [{$label}]\n";
        return;
    }
    echo "PASS [{$label}]\n";
}

function assert_contains(string $haystack, string $needle, string $label): void {
    assert_true(str_contains($haystack, $needle), $label . " (isi: {$haystack})");
}

// -----------------------------------------------------------------------
// 1-10: tool_calculate() -- aritmetika dasar & fungsi matematis
// -----------------------------------------------------------------------

assert_result_contains_number(tool_calculate('2+3*4'), 14.0, 'urutan operasi + dan *');
assert_result_contains_number(tool_calculate('(2+3)*4'), 20.0, 'kurung mengubah urutan operasi');
assert_result_contains_number(tool_calculate('2200*22.2'), 48840.0, 'perkalian desimal');
assert_result_contains_number(tool_calculate('sqrt(2500)+ln(1)'), 50.0, 'sqrt + ln gabungan');
assert_result_contains_number(tool_calculate('2^10'), 1024.0, 'pemangkatan');
assert_result_contains_number(tool_calculate('5!'), 120.0, 'faktorial 5!');
assert_result_contains_number(tool_calculate('10%3'), 1.0, 'modulo dasar');
assert_result_contains_number(tool_calculate('log(8,2)'), 3.0, 'log basis 2 dari 8');
assert_result_contains_number(tool_calculate('-5+3'), -2.0, 'unary minus');
assert_result_contains_number(tool_calculate('sin(0)+cos(0)'), 1.0, 'trig dasar (radian)');

// -----------------------------------------------------------------------
// 11-16: edge case wajib -- pembagian nol, angka sangat besar/kecil
// -----------------------------------------------------------------------

assert_contains(tool_calculate('10/0'), 'Pembagian dengan nol', 'pembagian dengan nol harus ditolak dengan pesan jelas');
assert_contains(tool_calculate('10%0'), 'Modulo dengan nol', 'modulo dengan nol harus ditolak dengan pesan jelas');
assert_result_contains_number(tool_calculate('1000000000*1000000000'), 1.0e18, 'perkalian angka sangat besar (1e9 x 1e9)');
assert_result_contains_number(tool_calculate('0.0000001*0.0000001'), 1.0e-14, 'perkalian angka sangat kecil (1e-7 x 1e-7)', 1e-6);
assert_result_contains_number(tool_calculate('0.1+0.2'), 0.3, 'akumulasi floating point 0.1 + 0.2');
assert_contains(tool_calculate('sqrt(-4)'), 'tidak terdefinisi', 'akar dari bilangan negatif harus ditandai NaN/tidak terdefinisi');

// --- Regresi bug yang DITEMUKAN oleh test suite ini sendiri (lihat CHANGELOG_PERBAIKAN.md) ---
// Bug #1: tool_calc_format() sebelumnya membulatkan SEMUA angka yang dekat
// nol secara absolut ke 0, sehingga hasil kecil yang sah (mis. 1e-14) tampil
// sebagai "0" dan kehilangan presisi total. Dites eksplisit di sini karena
// tolerance longgar pada kasus di atas tidak akan menangkapnya.
assert_true(
    str_starts_with(tool_calculate('0.0000001*0.0000001'), 'Hasil dari `0.0000001*0.0000001` = 1') &&
    !str_ends_with(trim(tool_calculate('0.0000001*0.0000001')), '= 0'),
    'REGRESI: hasil sangat kecil tidak boleh terpotong jadi "0"'
);
// Bug #2: tool_calc_format() memakai rtrim('0') pada string %.10F, yang
// merusak angka besar dengan banyak nol signifikan di akhir (mis. 170!).
assert_result_contains_number(tool_calculate('170!'), (float) array_product(range(1, 170)), 'REGRESI: faktorial besar tidak boleh terpotong jadi "0"', 1e-3);

// -----------------------------------------------------------------------
// 17-22: validasi input & batas sistem
// -----------------------------------------------------------------------

assert_contains(tool_calculate(''), 'kosong', 'ekspresi kosong ditolak');
assert_contains(tool_calculate(str_repeat('1+', 300)), 'terlalu panjang', 'ekspresi > 500 karakter ditolak');
assert_contains(tool_calculate('171!'), 'Faktorial hanya untuk', '171! melebihi batas harus ditolak');
assert_contains(tool_calculate('(-1)!'), 'Faktorial hanya untuk', 'faktorial bilangan negatif ditolak');
assert_contains(tool_calculate('5.5!'), 'Faktorial hanya untuk', 'faktorial bukan bilangan bulat ditolak');
assert_contains(tool_calculate('2+'), 'tidak valid', 'ekspresi tidak lengkap ditolak dengan pesan error');
// Pecahan TUNGGAL (satu a/b) adalah pembagian biasa yang sah dan harus
// dihitung normal oleh tool hitung() -- lihat REGRESI di atas untuk kasus
// yang sebelumnya salah ditolak (10/0, 5/2, dst).
assert_result_contains_number(tool_calculate('50/12'), 50 / 12, 'pecahan tunggal (50/12) dihitung normal, bukan ditolak');
// Rangkaian >= 2 pecahan yang dikalikan MASIH ditolak: ini pola khas rasio
// gigi bertingkat yang ditulis manual dan wajib lewat hitung_batch/berantai.
assert_contains(tool_calculate('12/50*15/45'), 'DITOLAK', 'rangkaian >=2 pecahan (pola rasio gigi bertingkat) tetap ditolak di tool hitung()');

// -----------------------------------------------------------------------
// 23-27: tool_calculate_batch() -- perhitungan independen & rasio gigi
// -----------------------------------------------------------------------

$batch = tool_calculate_batch([
    ['label' => 'a', 'ekspresi' => '10*10'],
    ['label' => 'b', 'ekspresi' => '5/2'],
    ['label' => 'rasio_final_drive', 'gigi_input' => 12, 'gigi_output' => 50],
]);
assert_contains($batch, '[a] `10*10` = 100', 'batch item 1: hasil perkalian benar');
assert_contains($batch, '[b] `5/2` = 2.5', 'batch item 2: hasil pembagian benar');
assert_contains($batch, 'gigi_output 50 / gigi_input 12', 'batch item 3: rasio gigi memakai gigi_output/gigi_input, bukan sebaliknya');
assert_result_contains_number(
    explode("\n", $batch)[2],
    50 / 12,
    'batch item 3: nilai numerik rasio gigi = gigi_output/gigi_input'
);

$batchZero = tool_calculate_batch([
    ['label' => 'gigi_nol', 'gigi_input' => 0, 'gigi_output' => 40],
]);
assert_contains($batchZero, 'gigi_input tidak boleh nol', 'batch dengan gigi_input=0 ditolak');

assert_contains(tool_calculate_batch([]), 'kosong', 'batch kosong ditangani tanpa error fatal');

// -----------------------------------------------------------------------
// 28-33: tool_calculate_chain() -- langkah saling bergantung
// -----------------------------------------------------------------------

$chain = tool_calculate_chain([
    ['label' => 'rasio_tahap1', 'gigi_input' => 12, 'gigi_output' => 48],
    ['label' => 'rasio_tahap2', 'gigi_input' => 15, 'gigi_output' => 45],
    ['label' => 'rasio_total', 'ekspresi' => 'hasil1*hasil2'],
]);
assert_result_contains_number(explode("\n", $chain)[0], 48 / 12, 'chain langkah 1: rasio tahap 1 benar');
assert_result_contains_number(explode("\n", $chain)[1], 45 / 15, 'chain langkah 2: rasio tahap 2 benar');
assert_result_contains_number(explode("\n", $chain)[2], (48 / 12) * (45 / 15), 'chain langkah 3: rasio total = hasil1*hasil2 disubstitusi sistem');

$chainStop = tool_calculate_chain([
    ['label' => 'langkah1', 'ekspresi' => '10/0'],
    ['label' => 'langkah2', 'ekspresi' => 'hasil1*2'],
]);
assert_contains($chainStop, 'Pembagian dengan nol', 'chain: error di langkah awal terekam');
assert_contains($chainStop, 'Perhitungan berantai dihentikan', 'chain: rangkaian dihentikan begitu satu langkah gagal');
assert_true(!str_contains($chainStop, 'langkah2'), 'chain: langkah setelah kegagalan tidak ikut dieksekusi');

$chainUndefinedVar = tool_calculate_chain([
    ['label' => 'a', 'ekspresi' => 'hasil99*2'],
]);
assert_contains($chainUndefinedVar, 'Identifier tidak dikenal', 'chain: variabel hasil yang belum ada ditolak, bukan dianggap 0');

assert_contains(tool_calculate_chain([]), 'kosong', 'chain kosong ditangani tanpa error fatal');

// -----------------------------------------------------------------------
// ringkasan
// -----------------------------------------------------------------------

echo "\n----------------------------------------\n";
echo "CalculatorTest: {$total} kasus uji, " . ($total - $failed) . " lulus, {$failed} gagal.\n";
if ($failed > 0) {
    echo "STATUS: FAIL\n";
    exit(1);
}
echo "STATUS: PASS\n";
