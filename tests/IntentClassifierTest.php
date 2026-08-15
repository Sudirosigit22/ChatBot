<?php
declare(strict_types=1);

/*
 * Test untuk komponen Machine Learning: classifier intent (Multinomial Naive
 * Bayes) yang dilatih dari includes/ml/dataset_intent.csv oleh
 * includes/ml/train_intent_classifier.php.
 *
 * Tujuan test ini BUKAN sekadar "kode tidak fatal error", tapi memverifikasi
 * bahwa model benar-benar sudah dilatih, dapat dimuat ulang, dan performanya
 * pada data uji (yang tidak dilihat saat training) berada di atas ambang
 * batas yang wajar -- sesuai praktik evaluasi model ML pada umumnya.
 *
 * Jalankan: php tests/IntentClassifierTest.php
 * (jalankan `php includes/ml/train_intent_classifier.php` dulu bila
 * includes/ml/intent_model.json belum ada)
 */

require __DIR__ . '/../includes/bootstrap.php';

$total = 0;
$failed = 0;

function it_assert(bool $condition, string $label): void {
    global $total, $failed;
    $total++;
    if (!$condition) {
        $failed++;
        echo "FAIL [{$label}]\n";
        return;
    }
    echo "PASS [{$label}]\n";
}

// -----------------------------------------------------------------------
// 1. Artefak model harus ada (hasil training sudah dijalankan & disimpan)
// -----------------------------------------------------------------------

it_assert(is_file(__DIR__ . '/../includes/ml/dataset_intent.csv'), 'dataset training tersedia');
it_assert(is_file(__DIR__ . '/../includes/ml/intent_model.json'), 'model hasil training tersimpan (intent_model.json)');

$model = intent_model_load();
it_assert(is_array($model), 'model dapat dimuat ulang tanpa error');
it_assert(is_array($model) && count($model['classes']) === 3, 'model memiliki 3 kelas (matematika/pencarian_web/umum)');
it_assert(is_array($model) && ($model['training_examples'] ?? 0) >= 150, 'model dilatih dari dataset berukuran memadai (>=150 contoh)');

// -----------------------------------------------------------------------
// 2. Evaluasi ulang pada split test (harus konsisten dengan training_report.md)
// -----------------------------------------------------------------------

$rows = nb_load_dataset(__DIR__ . '/../includes/ml/dataset_intent.csv');
[$trainRows, $testRows] = nb_stratified_split($rows, 0.2, 20260814);
$evalModel = nb_train($trainRows);
$eval = nb_evaluate($evalModel, $testRows);

echo "Akurasi pada data test (n={$eval['n_test']}): " . round($eval['accuracy'] * 100, 2) . "%\n";

// Ambang 80% dipilih longgar dengan sengaja: cukup untuk membuktikan model
// belajar pola sungguhan (bukan tebak-tebakan / selalu satu kelas dominan,
// yang untuk dataset seimbang 3-kelas ini hanya akan mencetak ~33%), tapi
// tidak begitu ketat sehingga rapuh terhadap perubahan kecil pada dataset.
it_assert($eval['accuracy'] >= 0.80, 'akurasi pada data test di atas ambang batas 80%');
it_assert($eval['n_test'] >= 40, 'ukuran data test cukup besar untuk evaluasi yang bermakna (>=40 contoh)');

// -----------------------------------------------------------------------
// 3. Spot-check kasus yang jelas per kelas, memakai model beku (production)
// -----------------------------------------------------------------------

$cases = [
    ['hitung 25 kali 4', 'matematika'],
    ['berapa hasil 100 dibagi 5', 'matematika'],
    ['hitung rasio gigi pinion 12 dan spur 48', 'matematika'],
    ['siapa presiden indonesia saat ini', 'pencarian_web'],
    ['berapa harga bitcoin hari ini', 'pencarian_web'],
    ['apa itu machine learning', 'umum'],
    ['ceritakan sejarah kerajaan majapahit', 'umum'],
];
foreach ($cases as [$text, $expected]) {
    $pred = classify_intent($text);
    it_assert(
        $pred['available'] && $pred['label'] === $expected,
        "klasifikasi benar untuk \"{$text}\" -> {$expected} (didapat: " . ($pred['label'] ?? '?') . ')'
    );
}

// -----------------------------------------------------------------------
// 4. Fallback aman ketika model tidak tersedia
// -----------------------------------------------------------------------

$fakeMissing = classify_intent('') ; // teks kosong tetap harus balikan struktur valid
it_assert(isset($fakeMissing['label'], $fakeMissing['probs'], $fakeMissing['available']), 'classify_intent selalu mengembalikan struktur lengkap, bahkan untuk input kosong');

// -----------------------------------------------------------------------
// 5. Integrasi dengan agent.php: hybrid ML + regex tidak mengurangi recall
// -----------------------------------------------------------------------

$hybrid = agent_classify('kalau saya punya 240 ribu dibagi ke 8 orang, masing-masing dapat berapa');
it_assert($hybrid['needs_math'] === true, 'agent_classify (hybrid ML+regex) menandai kebutuhan tool matematika pada kalimat naratif');

$hybridWeb = agent_classify('apakah ronaldo masih bermain sepak bola');
it_assert($hybridWeb['needs_web'] === true, 'agent_classify (hybrid ML+regex) menandai kebutuhan pencarian web tanpa kata kunci eksplisit "terkini/hari ini"');

echo "\n----------------------------------------\n";
echo "IntentClassifierTest: {$total} kasus uji, " . ($total - $failed) . " lulus, {$failed} gagal.\n";
if ($failed > 0) {
    echo "STATUS: FAIL\n";
    exit(1);
}
echo "STATUS: PASS\n";
