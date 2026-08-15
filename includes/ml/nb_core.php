<?php
declare(strict_types=1);

/*
 * ============================================================================
 * Pustaka inti Naive Bayes (tokenisasi, training, prediksi, evaluasi).
 * Dipakai bersama oleh train_intent_classifier.php (CLI, untuk melatih)
 * dan intent_classifier.php (runtime, untuk memakai model yang sudah dilatih).
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
 * ============================================================================
 */

function nb_tokenize(string $text): array {
    $text = mb_strtolower($text);
    $text = preg_replace('/[0-9]+(?:[.,][0-9]+)?/u', ' __num__ ', $text) ?? $text;
    $text = preg_replace('/[^\p{L}_\s]+/u', ' ', $text) ?? $text;
    $parts = preg_split('/\s+/u', trim($text));
    $tokens = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $tokens[] = $p;
    }
    return $tokens;
}

function nb_load_dataset(string $csvPath): array {
    $rows = [];
    $fh = fopen($csvPath, 'r');
    if ($fh === false) {
        throw new RuntimeException("Tidak bisa membuka dataset: {$csvPath}");
    }
    $header = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 2) continue;
        [$text, $label] = $row;
        $rows[] = ['text' => $text, 'label' => trim($label)];
    }
    fclose($fh);
    return $rows;
}

/** Stratified split: proporsi tiap kelas dijaga sama antara train & test. */
function nb_stratified_split(array $rows, float $testRatio, int $seed): array {
    $byLabel = [];
    foreach ($rows as $r) {
        $byLabel[$r['label']][] = $r;
    }
    $train = [];
    $test = [];
    foreach ($byLabel as $label => $items) {
        mt_srand($seed + crc32($label));
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        $nTest = (int) round(count($items) * $testRatio);
        $test = array_merge($test, array_slice($items, 0, $nTest));
        $train = array_merge($train, array_slice($items, $nTest));
    }
    return [$train, $test];
}

function nb_train(array $trainRows): array {
    $classCounts = [];
    $wordCounts = [];   // label => [word => count]
    $classTotalWords = []; // label => total token count
    $vocab = [];

    foreach ($trainRows as $row) {
        $label = $row['label'];
        $classCounts[$label] = ($classCounts[$label] ?? 0) + 1;
        $tokens = nb_tokenize($row['text']);
        foreach ($tokens as $tok) {
            $vocab[$tok] = true;
            $wordCounts[$label][$tok] = ($wordCounts[$label][$tok] ?? 0) + 1;
            $classTotalWords[$label] = ($classTotalWords[$label] ?? 0) + 1;
        }
    }

    $totalDocs = array_sum($classCounts);
    $vocabSize = count($vocab);

    $priors = [];
    $logLikelihoods = []; // label => [word => log P(word|label)]
    foreach ($classCounts as $label => $count) {
        $priors[$label] = $count / $totalDocs;
        $denom = ($classTotalWords[$label] ?? 0) + $vocabSize; // Laplace smoothing
        foreach (array_keys($vocab) as $word) {
            $wc = $wordCounts[$label][$word] ?? 0;
            $logLikelihoods[$label][$word] = log(($wc + 1) / $denom);
        }
        // simpan log-prob "kata tak dikenal" (unseen word) juga, untuk dipakai di inferensi
        $logLikelihoods[$label]['__unseen__'] = log(1 / $denom);
    }

    return [
        'classes' => array_keys($classCounts),
        'priors' => $priors,
        'log_likelihoods' => $logLikelihoods,
        'vocab_size' => $vocabSize,
        'trained_at' => gmdate('c'),
        'training_examples' => $totalDocs,
    ];
}

function nb_predict(array $model, string $text): array {
    $tokens = nb_tokenize($text);
    $scores = [];
    foreach ($model['classes'] as $label) {
        $score = log($model['priors'][$label]);
        foreach ($tokens as $tok) {
            $score += $model['log_likelihoods'][$label][$tok] ?? $model['log_likelihoods'][$label]['__unseen__'];
        }
        $scores[$label] = $score;
    }
    arsort($scores);
    $best = array_key_first($scores);

    // softmax-kan skor log agar dapat "probabilitas" yang mudah dibaca
    $max = max($scores);
    $expSum = 0.0;
    $probs = [];
    foreach ($scores as $label => $s) {
        $probs[$label] = exp($s - $max);
        $expSum += $probs[$label];
    }
    foreach ($probs as $label => $p) {
        $probs[$label] = round($p / $expSum, 4);
    }

    return ['label' => $best, 'probs' => $probs];
}

function nb_evaluate(array $model, array $testRows): array {
    $correct = 0;
    $confusion = []; // actual => [predicted => count]
    foreach ($testRows as $row) {
        $pred = nb_predict($model, $row['text'])['label'];
        $actual = $row['label'];
        $confusion[$actual][$pred] = ($confusion[$actual][$pred] ?? 0) + 1;
        if ($pred === $actual) $correct++;
    }
    $accuracy = count($testRows) > 0 ? $correct / count($testRows) : 0.0;
    return ['accuracy' => $accuracy, 'confusion' => $confusion, 'n_test' => count($testRows), 'n_correct' => $correct];
}

