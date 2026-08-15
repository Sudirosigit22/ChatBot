<?php
declare(strict_types=1);

/*
 * ============================================================================
 * Skrip CLI pelatihan classifier intent (Multinomial Naive Bayes) dari nol.
 * ============================================================================
 *
 * Ini adalah kontribusi Machine Learning riil untuk proyek capstone:
 * sebuah model diLATIH (bukan aturan if/else statis) dari dataset berlabel
 * (includes/ml/dataset_intent.csv) untuk mengklasifikasikan niat pesan
 * pengguna ke salah satu dari 3 kelas:
 *
 *   - matematika     : butuh tool kalkulator (hitung / hitung_batch / hitung_berantai)
 *   - pencarian_web  : butuh tool pencarian web (cari_web), fakta yang berubah-ubah
 *   - umum           : bisa dijawab langsung tanpa tool
 *
 * Algoritma  : Multinomial Naive Bayes, bag-of-words, Laplace (add-1) smoothing.
 * Fitur      : token kata hasil tokenisasi + lowercasing + pembuangan angka
 *              (angka diganti token generik `__num__` supaya model belajar
 *              pola KATA di sekitar angka, bukan menghafal nilai angkanya).
 * Evaluasi   : train/test split 80/20 (stratified per kelas, seed tetap agar
 *              reproducible), dilaporkan akurasi + confusion matrix.
 * Output     : includes/ml/intent_model.json (prior kelas + log-likelihood
 *              tiap kata per kelas + vocabulary), dipakai saat inferensi oleh
 *              includes/ml/intent_classifier.php TANPA perlu PHP training
 *              lagi di runtime (model sudah "dibekukan").
 *
 * Jalankan   : php includes/ml/train_intent_classifier.php
 *
 * PENTING: file ini HANYA dijalankan manual dari command line saat melatih
 * ulang model (mis. setelah dataset diperbarui). Ia TIDAK pernah di-require
 * oleh aplikasi web (index.php/api/*), yang cukup memakai model beku lewat
 * intent_classifier.php -- supaya training tidak berjalan ulang di setiap
 * request. Fungsi inti (tokenisasi, training, prediksi, evaluasi) ada di
 * nb_core.php, dipakai bersama oleh skrip ini dan intent_classifier.php.
 * ============================================================================
 */

require_once __DIR__ . '/nb_core.php';

// ---------------------------------------------------------------------------
// Eksekusi pelatihan
// ---------------------------------------------------------------------------

$datasetPath = __DIR__ . '/dataset_intent.csv';
$modelPath = __DIR__ . '/intent_model.json';
$reportPath = __DIR__ . '/training_report.md';

$rows = nb_load_dataset($datasetPath);
[$trainRows, $testRows] = nb_stratified_split($rows, 0.2, 20260814);

$model = nb_train($trainRows);
$eval = nb_evaluate($model, $testRows);

file_put_contents($modelPath, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$report = "# Laporan Pelatihan Intent Classifier\n\n";
$report .= "- Algoritma: Multinomial Naive Bayes (bag-of-words, Laplace smoothing)\n";
$report .= "- Dataset: `dataset_intent.csv` (" . count($rows) . " contoh, 3 kelas)\n";
$report .= "- Split: " . count($trainRows) . " train / " . count($testRows) . " test (stratified 80/20, seed tetap)\n";
$report .= "- Ukuran vocabulary: {$model['vocab_size']} kata unik\n\n";
$report .= "## Hasil evaluasi pada data test (tidak dilihat saat training)\n\n";
$report .= "- **Akurasi: " . round($eval['accuracy'] * 100, 2) . "%** ({$eval['n_correct']}/{$eval['n_test']} benar)\n\n";
$report .= "### Confusion matrix (baris = label asli, kolom = prediksi)\n\n";
$labels = $model['classes'];
$report .= "| aktual \\ prediksi | " . implode(' | ', $labels) . " |\n";
$report .= "|---|" . str_repeat('---|', count($labels)) . "\n";
foreach ($labels as $actual) {
    $cells = [];
    foreach ($labels as $pred) {
        $cells[] = (string) ($eval['confusion'][$actual][$pred] ?? 0);
    }
    $report .= "| {$actual} | " . implode(' | ', $cells) . " |\n";
}
$report .= "\nModel disimpan di `intent_model.json` dan dipakai saat runtime oleh ";
$report .= "`intent_classifier.php` (fungsi `classify_intent()`), yang dipanggil dari ";
$report .= "`includes/agent.php` untuk membantu perencanaan tool secara berbasis data, ";
$report .= "melengkapi heuristik regex yang sudah ada sebagai lapisan keamanan (safety net).\n";

file_put_contents($reportPath, $report);

echo $report;
echo "\nModel tersimpan: {$modelPath}\n";
