# Laporan Pelatihan Intent Classifier

- Algoritma: Multinomial Naive Bayes (bag-of-words, Laplace smoothing)
- Dataset: `dataset_intent.csv` (291 contoh, 3 kelas)
- Split: 233 train / 58 test (stratified 80/20, seed tetap)
- Ukuran vocabulary: 302 kata unik

## Hasil evaluasi pada data test (tidak dilihat saat training)

- **Akurasi: 94.83%** (55/58 benar)

### Confusion matrix (baris = label asli, kolom = prediksi)

| aktual \ prediksi | pencarian_web | matematika | umum |
|---|---|---|---|
| pencarian_web | 16 | 0 | 0 |
| matematika | 0 | 21 | 0 |
| umum | 2 | 1 | 18 |

Model disimpan di `intent_model.json` dan dipakai saat runtime oleh `intent_classifier.php` (fungsi `classify_intent()`), yang dipanggil dari `includes/agent.php` untuk membantu perencanaan tool secara berbasis data, melengkapi heuristik regex yang sudah ada sebagai lapisan keamanan (safety net).
