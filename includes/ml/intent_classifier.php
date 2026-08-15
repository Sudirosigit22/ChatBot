<?php
declare(strict_types=1);

/*
 * Inferensi untuk model Multinomial Naive Bayes yang dilatih oleh
 * train_intent_classifier.php. File ini HANYA melakukan prediksi memakai
 * model yang sudah dibekukan (intent_model.json) -- tidak melatih ulang
 * saat runtime, supaya cepat dan deterministik.
 *
 * Fungsi inti (nb_tokenize, nb_predict, dst) ada di nb_core.php dan dipakai
 * ulang di sini agar tokenisasi saat training & inferensi identik (syarat
 * wajib supaya model Naive Bayes valid). File ini TIDAK meng-require
 * train_intent_classifier.php (skrip CLI itu punya efek samping menulis
 * ulang model & laporan setiap dijalankan -- tidak boleh ikut ter-load saat
 * aplikasi web berjalan).
 */

require_once __DIR__ . '/nb_core.php';

function intent_model_path(): string {
    return __DIR__ . '/intent_model.json';
}

function intent_model_load(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $path = intent_model_path();
    if (!is_file($path)) return null;
    $json = file_get_contents($path);
    if ($json === false) return null;
    $model = json_decode($json, true);
    if (!is_array($model) || empty($model['classes'])) return null;
    $cached = $model;
    return $cached;
}

/**
 * Klasifikasikan niat sebuah pesan pengguna.
 *
 * @return array{label:string,probs:array<string,float>,available:bool}
 *   label     : kelas dengan probabilitas tertinggi ('matematika' | 'pencarian_web' | 'umum')
 *   probs     : distribusi probabilitas (softmax dari skor log-posterior) tiap kelas
 *   available : false jika model belum dilatih/tidak ditemukan (pemanggil harus fallback ke heuristik)
 */
function classify_intent(string $text): array {
    $model = intent_model_load();
    if ($model === null) {
        return ['label' => 'umum', 'probs' => [], 'available' => false];
    }
    $result = nb_predict($model, $text);
    $result['available'] = true;
    return $result;
}
