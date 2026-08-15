# Sigit AI / SigitCloudChat

Chatbot berbasis web yang terintegrasi dengan **Ollama Cloud** (atau instance Ollama lokal). Dibangun dengan **PHP murni** (tanpa framework berat), penyimpanan riwayat di **browser (localStorage)**, lapisan **agent** yang dapat diaudit, **classifier intent berbasis Machine Learning** (Naive Bayes terlatih), tool-calling nyata, streaming respons (SSE), serta dukungan lampiran dokumen dan vision.

Proyek ini dirancang agar dapat dijalankan di shared hosting PHP sekaligus mendukung fitur lanjutan: multi-model, mode thinking/reasoning, kontrol kedalaman jawaban, kalkulator matematika presisi, perhitungan teknis berantai, pencarian web, dan badge deteksi jenis pertanyaan di UI.

---

## Daftar Isi

1. [Latar Belakang & Tujuan](#1-latar-belakang--tujuan)
2. [Perkenalan Proyek](#2-perkenalan-proyek)
3. [Fungsi dan Kegunaan Utama](#3-fungsi-dan-kegunaan-utama)
4. [Keunggulan](#4-keunggulan)
5. [Fitur Lengkap](#5-fitur-lengkap)
6. [Struktur Proyek](#6-struktur-proyek)
7. [Alur Kerja (Workflow)](#7-alur-kerja-workflow)
8. [Persyaratan Sistem](#8-persyaratan-sistem)
9. [Instalasi dan Konfigurasi](#9-instalasi-dan-konfigurasi)
10. [Penggunaan](#10-penggunaan)
11. [Keamanan](#11-keamanan)
12. [Tool yang Tersedia](#12-tool-yang-tersedia)
13. [Lapisan Agent & Machine Learning](#13-lapisan-agent--machine-learning)
14. [Test Otomatis](#14-test-otomatis)
15. [Saran Peningkatan](#15-saran-peningkatan)
16. [Pemeliharaan & Troubleshooting](#16-pemeliharaan--troubleshooting)
17. [Catatan Teknis](#17-catatan-teknis)

---

## 1. Latar Belakang & Tujuan

### Latar belakang

Banyak chatbot LLM “sederhana” hanya meneruskan prompt ke API dan menampilkan teks. Untuk tugas yang membutuhkan **angka akurat** (rasio, konversi satuan berantai, perhitungan teknis bertahap), model sering menghitung manual dan menghasilkan kesalahan. Di sisi lain, menumpuk seluruh logika di prompt saja membuat sistem sulit diuji, diaudit, dan dikembangkan.

Proyek ini lahir dari kebutuhan praktis:

- Akses model cloud yang kuat (GPT-OSS, Nemotron, Gemma, dll.) **tanpa** harus mengelola GPU/server Ollama sendiri.
- Deployment ringan di **shared hosting PHP** (InfinityFree, Hostinger, VPS kecil, atau bahkan `php -S` lokal).
- Kebutuhan perhitungan matematika presisi dan **analisis teknis berantai** — konversi satuan, rasio, rantai perhitungan multi-langkah, serta skenario numerik yang menuntut determinisme.
- Keinginan membangun **agentic AI yang inspectable**: rencana langkah, validasi tool call, dan jejak eksekusi bisa diaudit, bukan kotak hitam.
- Menjawab kritik akademis/evaluasi bahwa sistem sebelumnya kurang memiliki **kontribusi Machine Learning riil** (training model sendiri) dan **test otomatis**.

### Tujuan proyek

1. **Akurasi numerik** — Model tidak “menebak” angka; ia memanggil tool PHP yang mengevaluasi ekspresi matematika secara deterministik (termasuk batch dan rantai langkah saling bergantung).
2. **Agent layer yang transparan** — Perencanaan tool, validasi argumen, dan verifikasi hasil dicatat dalam `agent_trace` yang bisa diinspeksi.
3. **Klasifikasi intent berbasis ML** — Multinomial Naive Bayes dilatih dari dataset berbahasa Indonesia (bukan sekadar regex) untuk membedakan pertanyaan matematika, pencarian web, dan umum; hasilnya digabung secara hybrid dengan heuristik sebagai jaring pengaman, dan ditampilkan ke pengguna sebagai badge di UI.
4. **Pengalaman chat modern** — Streaming token real-time (SSE), Markdown + KaTeX + syntax highlighting, multi-model, mode thinking, kontrol kedalaman jawaban, lampiran file (teks/PDF/DOCX/Excel) dan gambar (vision pada Gemma 4).
5. **Ringan & portabel** — PHP native + cURL; riwayat percakapan disimpan di browser (localStorage) sehingga tidak membutuhkan database server; kredensial sensitif dipisah ke `secrets.php`.
6. **Dapat diuji** — Suite test otomatis (kalkulator, intent classifier, agent layer) agar regresi tertangkap dini dan kualitas tool terjaga.

Singkatnya: **Sigit AI** bertujuan menjadi asisten chat pribadi/demo yang akurat untuk perhitungan, transparan dalam cara kerjanya sebagai agent, dan tetap praktis di-deploy di lingkungan PHP biasa.

---

## 2. Perkenalan Proyek

**SigitCloudChat** (ditampilkan di UI sebagai **Sigit AI**) adalah aplikasi chatbot full-stack ringan yang memungkinkan pengguna berinteraksi dengan model bahasa besar (LLM) yang dilayani oleh Ollama Cloud atau instance lokal.

Fokus utama:

- Akses mudah ke model cloud Ollama tanpa menjalankan binary Ollama di server sendiri.
- Pengalaman chat modern: streaming, rendering kaya, riwayat di browser, ekspor Markdown/PDF, kontrol reasoning dan kedalaman jawaban.
- Tool-calling nyata untuk matematika, rasio, perhitungan berantai, dan pencarian web.
- Lapisan agent + classifier intent ML yang membuat keputusan tool lebih terstruktur dan dapat diaudit.
- Siap shared hosting: hanya PHP + ekstensi standar + akses internet keluar.

Nama aplikasi, judul halaman, daftar model, dan parameter sampling dapat diubah lewat `config.php`. Kredensial login dan API key disimpan di `secrets.php` (tidak di-commit).

---

## 3. Fungsi dan Kegunaan Utama

| Fungsi | Penjelasan |
|--------|------------|
| **Chat interaktif** | Kirim pesan teks (dan lampiran), terima jawaban streaming dari model Ollama. |
| **Multi-model** | Pilih model dari dropdown (GPT-OSS 120b/20b, Nemotron 3 Super, Gemma 4, dll.). Setiap model punya metadata: label, deskripsi, mode thinking, context window, dukungan tool/vision. |
| **Mode Thinking / Reasoning** | low / medium / high, atau on/off (tergantung model). Level lebih tinggi = reasoning lebih dalam. |
| **Kedalaman jawaban** | Hemat / Adaptif / Mendalam — mengontrol anggaran `num_predict` secara dinamis. |
| **Tool-calling** | Kalkulator tunggal, batch, berantai; pencarian web; perhitungan teknis terstruktur. |
| **Klasifikasi intent (ML)** | Deteksi otomatis jenis pertanyaan (matematika / pencarian web / umum) dengan badge di UI. |
| **Manajemen percakapan** | Buat, ganti nama, hapus, cari — disimpan di localStorage browser per perangkat. |
| **Edit & Regenerate** | Edit pesan user sebelumnya atau regenerate jawaban terakhir. |
| **Lampiran file** | Teks, PDF, DOCX, Excel (diekstrak ke teks); gambar untuk model vision (Gemma 4). |
| **Ekspor** | Unduh percakapan sebagai Markdown atau PDF. |
| **Tema gelap/terang** | Toggle tema UI. |
| **Autentikasi sederhana** | Login username/password dari `secrets.php` / `config.php`. |
| **Streaming SSE** | Respons token-per-token tanpa menunggu jawaban selesai. |

**Kegunaan praktis:** asisten coding & penulisan, perhitungan matematis akurat (termasuk rantai perhitungan multi-langkah dan rasio), analisis teknis numerik, pencarian fakta terkini, dan chat pribadi dengan model cloud tanpa infrastruktur GPU lokal.

---

## 4. Keunggulan

1. **Tanpa dependensi berat** — PHP native, tanpa Composer/Node.js/database server.
2. **Siap shared hosting** — Cukup PHP + cURL + akses internet; Ollama Cloud diakses lewat API key.
3. **Tool-calling nyata** — Evaluasi ekspresi di PHP, bukan tebakan model.
4. **Streaming real-time (SSE)** — Pengalaman mirip ChatGPT.
5. **Agent layer inspectable** — Rencana langkah, validasi tool, dan jejak eksekusi tercatat (`agent_trace`).
6. **Machine Learning riil** — Classifier intent Naive Bayes dilatih sendiri (dataset ID, akurasi test ~94.8%), hybrid dengan regex.
7. **Test otomatis** — ~95 kasus uji untuk kalkulator, intent, dan agent layer.
8. **Riwayat di client** — Tidak perlu SQLite/MySQL; data percakapan di localStorage.
9. **Secrets terpisah** — API key & password di `secrets.php` (di-ignore git); dukungan multi-key.
10. **Tool terstruktur** — Perhitungan batch/berantai dan tool domain diaktifkan secara selektif sesuai intent agar tidak mengganggu chat umum.
11. **UI modern** — Sidebar, dark mode, Markdown + KaTeX + highlight.js, badge intent, lampiran file.
12. **Vision (opsional)** — Upload gambar saat model Gemma 4 dipilih.

---

## 5. Fitur Lengkap

### Frontend
- Layout dua kolom (sidebar percakapan + panel chat).
- Pencarian percakapan, Chat Baru, Regenerate, Clear, Stop, Export MD, Export PDF, Theme toggle, Logout.
- Info panel model, selector thinking & response depth.
- Edit pesan user, indikator typing, responsive (mobile menu).
- Badge “jenis pertanyaan terdeteksi” (🧮 Matematika / 🌐 Pencarian Web / dll.) di atas jawaban asisten.
- Upload lampiran: teks, PDF, DOCX, Excel; gambar (vision).

### Backend
- Autentikasi session-based + CSRF.
- Streaming chat via Server-Sent Events (`meta`, `token`, `agent_trace`, `error`, `done`).
- Generate judul percakapan otomatis oleh AI (opsional).
- Sanitasi karakter rusak dan tilde tunggal.
- Batas putaran tool (default 24).
- Dynamic `num_ctx` dan profil respons (hemat/adaptif/mendalam).
- Fallback & pesan error ramah (termasuk kuota Ollama).

### Tools (Function Calling)
- `hitung` — ekspresi matematika tunggal.
- `hitung_batch` — banyak perhitungan independen (maks 40), termasuk rasio.
- `hitung_berantai` — rangkaian langkah saling bergantung (`hasil1`, `hasil2`, …).
- `cari_web` — DuckDuckGo + Wikipedia.
- Tool perhitungan teknis tambahan (di-gate oleh deteksi intent bila relevan).

### Agent & ML
- `includes/agent.php` — perencanaan, validasi tool call, pencatatan jejak.
- `includes/ml/` — dataset, training Naive Bayes, model beku, inferensi runtime.

---

## 6. Struktur Proyek

```
ChatBot/
├── api/
│   ├── login.php                 # Proses login
│   ├── main.php                  # Endpoint chat utama (streaming SSE + intent preview)
│   └── models.php                # Daftar model + metadata untuk frontend
├── assets/
│   ├── css/
│   │   ├── login.css
│   │   └── main.css              # Termasuk style .intent-badge (light/dark)
│   └── js/
│       └── main.js               # SSE, localStorage, rendering, lampiran, badge intent
├── includes/
│   ├── bootstrap.php             # Session, CSRF, resolusi model, streaming Ollama, tool loop
│   ├── agent.php                 # Lapisan agent: classify, plan, validate, record, finish
│   ├── tools.php                 # Skema tool + implementasi kalkulator, web search, perhitungan teknis
│   └── ml/
│       ├── dataset_intent.csv    # Dataset berlabel (Bahasa Indonesia)
│       ├── nb_core.php           # Algoritma Multinomial Naive Bayes
│       ├── train_intent_classifier.php  # CLI pelatihan (jangan di-load web)
│       ├── intent_classifier.php # Inferensi runtime
│       ├── intent_model.json     # Model hasil training
│       └── training_report.md    # Laporan akurasi + confusion matrix
├── tests/
│   ├── CalculatorTest.php        # ~40 kasus uji kalkulator
│   ├── IntentClassifierTest.php  # Model + hybrid agent_classify
│   ├── AgentLayerTest.php
│   └── run_tests.php             # Runner seluruh suite (~95 kasus)
├── config.php                    # Konfigurasi aplikasi (boleh di-commit; tanpa secret)
├── secrets.php                   # API key(s), username/password (JANGAN di-commit)
├── index.php                     # Halaman chat (butuh login)
├── login.php
├── logout.php
├── .gitignore
├── CHANGELOG_REVISI.md           # Catatan revisi (test + ML)
└── README.md
```

**File kunci:**

- **`config.php`** — Model, sampling, batas history, flag tools/web search; banyak nilai bisa di-override lewat environment variable. Kredensial di-resolve dari `secrets.php` atau env.
- **`secrets.php`** — `ollama_api_keys` (multi-key), `active_key`, `username`, `password`.
- **`includes/bootstrap.php`** — Heart aplikasi: session, CSRF, streaming, tool loop, sanitasi, judul.
- **`includes/agent.php`** — Klasifikasi hybrid ML+regex, rencana langkah, validasi & jejak tool.
- **`includes/tools.php`** — Definisi & eksekusi tool OpenAI-style.
- **`api/main.php`** — Terima history dari client, kirim SSE (termasuk preview intent di `meta`).
- **`assets/js/main.js`** — State percakapan di localStorage, streaming, Markdown/KaTeX, lampiran, badge intent.

> **Catatan:** Revisi ini **tidak** memakai SQLite untuk percakapan. Riwayat disimpan di browser pengguna. Tidak ada `db.php`, `conversations.php`, atau `messages.php`.

---

## 7. Alur Kerja (Workflow)

### Login
1. User membuka `login.php`.
2. Form POST ke `api/login.php`.
3. Kredensial dicek (dari `secrets.php` / config) → session dibuat → redirect ke `index.php`.

### Chat
1. Frontend mengirim POST ke `api/main.php` (history JSON + conversation_id + model + think_level + response_depth + CSRF).
2. Backend memvalidasi login & CSRF.
3. Preview klasifikasi intent dihitung; event SSE `meta` dikirim (conversation_id, model, response_depth, intent).
4. `ollama_chat_stream()`:
   - History dibatasi (pesan/karakter).
   - System prompt + tools disiapkan (tool domain hanya jika query relevan).
   - `agent_start_run()` menyusun rencana (understand → calculate/research → verify).
   - Loop putaran tool (maks `tool_max_rounds`):
     - Stream request ke Ollama.
     - Jika model meminta tool → `agent_validate_tool_call` → eksekusi PHP → `agent_record_tool_call` → masukkan hasil ke konteks.
     - Jika jawaban final → stream token ke browser.
5. Jawaban disanitasi; `agent_finish_run()` menghasilkan verdict; event `agent_trace` + `done` (opsional judul AI) dikirim.
6. Frontend menampilkan badge intent, merender Markdown/KaTeX, dan menyimpan percakapan ke localStorage.

### Regenerate / Edit
- Regenerate: frontend menghapus jawaban terakhir dari history lalu stream ulang.
- Edit: potong history dari pesan yang diedit, kirim ulang.

### Manajemen percakapan
- Seluruh CRUD judul/list/hapus/cari dilakukan di client (localStorage). Server hanya menerima history yang dikirim per request.

### Streaming di browser
`fetch` + parsing SSE; token ditampilkan real-time; badge intent muncul dari event `meta` sebelum token pertama.

---

## 8. Persyaratan Sistem

- **PHP** ≥ 8.0 (disarankan 8.1+) dengan ekstensi:
  - `curl`
  - `json`
  - `mbstring` (sangat disarankan)
- **Akses internet keluar** dari server PHP (Ollama Cloud + web search).
- **Ollama Cloud account** + API key (atau Ollama lokal yang sudah login).
- Web server (Apache/Nginx) atau `php -S` untuk development.
- Browser modern dengan localStorage (untuk riwayat percakapan).

Tidak wajib: Composer, Node.js, SQLite, MySQL, atau ekstensi database.

---

## 9. Instalasi dan Konfigurasi

1. **Unggah / ekstrak** seluruh folder proyek ke document root atau subfolder.
2. **Buat `secrets.php`** (salin dari contoh di bawah atau sesuaikan yang ada). File ini **wajib** di-ignore git (sudah tercantum di `.gitignore`).

   Contoh minimal `secrets.php`:

   ```php
   <?php
   declare(strict_types=1);

   return [
       'ollama_api_keys' => [
           'default' => 'ISI_API_KEY_ANDA',
           // 'backup' => 'KEY_CADANGAN',
       ],
       'active_key' => 'default',
       'username' => 'admin',
       'password' => 'ganti_password_kuat',
   ];
   ```

3. **Edit `config.php`** bila perlu:
   - Sesuaikan `available_models`, `ollama_base_url` (default `https://ollama.com`).
   - Parameter sampling, batas history, flag tools/web search.
4. **Environment variables** yang didukung (override config):
   - `OLLAMA_BASE_URL`
   - `OLLAMA_API_KEY` (prioritas tertinggi untuk key)
   - `OLLAMA_ACTIVE_KEY`
   - `OLLAMA_MODEL`
   - `OLLAMA_CONTEXT_WINDOW`
   - `OLLAMA_NUM_PREDICT` / `OLLAMA_RESPONSE_MIN_PREDICT` / `OLLAMA_RESPONSE_MAX_PREDICT`
   - `OLLAMA_TEMPERATURE`
   - `OLLAMA_SEED`
   - `OLLAMA_THINK`
   - `OLLAMA_ENABLE_TOOLS`
   - `OLLAMA_WEB_SEARCH`
   - `OLLAMA_TOOL_MAX_ROUNDS`
   - `OLLAMA_HISTORY_MAX_MESSAGES`
   - `OLLAMA_HISTORY_MAX_CHARS`
5. Akses `https://domain-anda/login.php`.

**Keamanan:** Ganti password default dan jangan commit `secrets.php` yang berisi key asli ke repositori publik.

---

## 10. Penggunaan

1. Login dengan kredensial yang dikonfigurasi.
2. Pilih model dari dropdown 🤖.
3. (Opsional) Atur level thinking 🧠 dan kedalaman jawaban 📝 (Hemat / Adaptif / Mendalam).
4. Ketik pesan → Enter (Shift+Enter untuk baris baru). Bisa lampirkan file/gambar lewat tombol lampiran.
5. Jawaban muncul secara streaming; badge intent (jika model ML tersedia) muncul di atas bubble asisten.
6. Tombol:
   - **＋ Chat Baru** — percakapan kosong.
   - **🔁 Regenerate** — ulangi jawaban terakhir.
   - **🗑 Clear** — bersihkan tampilan chat saat ini.
   - **■ Stop** — hentikan generasi.
   - **⇩ Export MD / 🖨 Export PDF** — unduh percakapan.
   - **☾/☀️** — ganti tema.
7. Klik judul di sidebar untuk membuka kembali; gunakan search untuk mencari.

**Catatan penyimpanan:** Riwayat hanya ada di browser perangkat tersebut. Bersihkan data situs / ganti browser = riwayat hilang (kecuali sudah diekspor).

---

## 11. Keamanan

- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` (jika HTTPS).
- CSRF token wajib untuk aksi yang mengubah data / chat.
- Model dari frontend **hanya diterima** jika ada di `available_models`.
- Password disimpan plain di `secrets.php` (sederhana). Untuk production, pertimbangkan hashing atau auth eksternal.
- API key tidak di-commit; dukung multi-key + env override.
- Tool execution di server PHP — parser matematika sendiri membatasi ekspresi (bukan `eval` arbitrer).
- Upload gambar dibatasi ukuran dan jumlah; ekstraksi dokumen dibatasi halaman/karakter.

---

## 12. Tool yang Tersedia

### Kalkulator
- **`hitung`**: ekspresi tunggal (`sqrt(2500)+ln(10)`, `2200*22.2`, dll.).
- **`hitung_batch`**: banyak perhitungan independen (ideal untuk tabel perbandingan). Mendukung field rasio khusus bila diperlukan (mis. pasangan input/output).
- **`hitung_berantai`**: langkah berurutan yang saling bergantung (`hasil1 * hasil2`). Berguna untuk konversi satuan berlapis, perhitungan multi-tahap, atau skenario di mana hasil langkah sebelumnya dipakai di langkah berikutnya. Rangkaian berhenti jika satu langkah gagal.

### Web Search
- **`cari_web`**: DuckDuckGo Instant Answer + Wikipedia. Aktif jika `web_search_enabled = true`.

### Perhitungan teknis terstruktur
- Tool tambahan untuk skenario numerik yang lebih kompleks (mis. rantai parameter teknis) dapat diaktifkan secara selektif bila intent terdeteksi relevan, agar tidak mengganggu percakapan umum.

Semua tool dipanggil lewat format function-calling OpenAI-style yang didukung Ollama. Hasil dimasukkan kembali ke konteks agar model bisa melanjutkan reasoning.

---

## 13. Lapisan Agent & Machine Learning

### Agent layer (`includes/agent.php`)
- **`agent_classify()`** — hybrid ML + regex → `needs_math`, `needs_web`.
- **`agent_start_run()`** — menyusun rencana langkah (understand → calculate/research → verify).
- **`agent_validate_tool_call()`** — menolak tool di luar rencana atau argumen kosong/salah bentuk.
- **`agent_record_tool_call()`** — mencatat status, durasi, hash hasil.
- **`agent_finish_run()`** — verdict `passed` / `needs_review` berdasarkan keberhasilan tool dan kelengkapan rencana.

Jejak lengkap dikirim ke client sebagai event SSE `agent_trace`.

### Intent classifier (ML)
```
includes/ml/
├── dataset_intent.csv          # 291 contoh, 3 kelas (matematika, pencarian_web, umum)
├── nb_core.php                 # Multinomial Naive Bayes + Laplace smoothing
├── train_intent_classifier.php # CLI training (manual)
├── intent_classifier.php       # Inferensi runtime
├── intent_model.json           # Model beku
└── training_report.md          # Akurasi & confusion matrix
```

- Algoritma: Multinomial Naive Bayes, bag-of-words, dilatih dari nol di PHP murni.
- Evaluasi: stratified 80/20, seed tetap → **akurasi test ≈ 94.83%** (55/58).
- Integrasi: prediksi ML (confidence ≥ 0.5) digabung **OR** dengan regex; false negative tool dihindari karena lebih berbahaya daripada false positive.
- UI: badge real-time di chat (disembunyikan otomatis jika model belum tersedia).

**Melatih ulang model:**

```bash
php includes/ml/train_intent_classifier.php
```

Skrip ini hanya dijalankan manual dari CLI; tidak pernah di-load oleh request web.

---

## 14. Test Otomatis

```bash
php tests/run_tests.php
```

Menjalankan secara berurutan:

| File | Cakupan |
|------|---------|
| `AgentLayerTest.php` | Perencanaan, validasi, jejak agent |
| `CalculatorTest.php` | ~40 kasus: nol, magnitude ekstrem, NaN, faktorial, batch, berantai, rasio, regresi bug format |
| `IntentClassifierTest.php` | Load model, akurasi minimal, spot-check kelas, hybrid `agent_classify` |

Total sekitar **95 kasus uji**. Beberapa bug nyata di formatter kalkulator dan deteksi pola rasio berantai ditemukan dan diperbaiki berkat suite ini (lihat `CHANGELOG_REVISI.md`).

---

## 15. Saran Peningkatan

1. **Autentikasi multi-user & hashing password** — user di database, registrasi, reset password.
2. **Rate limiting & kuota per user**.
3. **Sinkronisasi riwayat opsional** — backend storage (SQLite/MySQL) sebagai pelengkap localStorage.
4. **Vision & dokumen lebih dalam** — ringkasan multi-halaman, OCR, multi-image reasoning.
5. **Voice input/output** — Web Speech API atau layanan TTS/STT.
6. **Plugin / tool tambahan** — unit converter, API eksternal (cuaca, saham, GitHub), code interpreter tersandbox.
7. **Admin panel** — kelola model, log usage, monitor error.
8. **Caching** — cache hasil web search, kompres history lebih agresif.
9. **Deployment container** — Dockerfile + docker-compose (PHP-FPM + Nginx).
10. **i18n** — UI multi-bahasa.
11. **Observability** — logging request (tanpa API key), metrik latensi & jumlah tool call.
12. **Perluasan dataset intent** — contoh ambigu tambahan untuk menekan sisa error kelas “umum”.

---

## 16. Pemeliharaan & Troubleshooting

### Test gagal setelah ubah tools
Jalankan `php tests/run_tests.php`. Perbaiki formatter/parser hingga semua kasus (termasuk REGRESI) hijau.

### Intent selalu “umum” / badge tidak muncul
- Pastikan `intent_model.json` ada dan terbaca.
- Latih ulang: `php includes/ml/train_intent_classifier.php`.
- Cek `intent.available` di event `meta` (DevTools → Network → EventStream).

### Timeout
Model besar + banyak tool round bisa melebihi batas PHP. Bootstrap menaikkan `set_time_limit`; pastikan hosting/proxy (php-fpm, Cloudflare) tidak memotong lebih awal. Nemotron punya `thinking_timeout_seconds` terpisah.

### Kuota Ollama Cloud
Pesan kuota sudah diformat ramah pengguna. Ganti key aktif di `secrets.php` atau set `OLLAMA_API_KEY` / `OLLAMA_ACTIVE_KEY`.

### Tool tidak terpanggil
- `'enable_tools' => true` dan model `supports_tools => true`.
- `web_search_enabled` untuk `cari_web`.
- Cek badge intent & `agent_trace` — apakah rencana memasukkan langkah calculate/research.
- Coba model lain atau turunkan temperature (default 0 + seed tetap untuk determinisme).

### Riwayat hilang
Normal: data di localStorage. Gunakan Export MD/PDF untuk cadangan. Hindari mode private/incognito jika ingin menyimpan riwayat.

### Streaming terputus di belakang proxy
Set `X-Accel-Buffering: no` (sudah dikirim) dan nonaktifkan buffering di nginx/Cloudflare untuk endpoint SSE.

---

## 17. Catatan Teknis

- **Streaming**: `text/event-stream` + flush. Event: `meta` (termasuk intent), `token`, `agent_trace`, `error`, `done`.
- **Context window**: per-model `num_ctx` (dynamic min/max); nilai global hanya fallback.
- **Temperature default 0** + seed tetap → jawaban lebih deterministik (baik untuk perhitungan).
- **History truncation**: hanya N pesan / M karakter terakhir yang dikirim ke model; UI tetap menampilkan seluruh riwayat di client.
- **Tool gating**: deteksi berbasis pola kata kunci + intent agar tool domain tidak mengganggu chat umum.
- **Sanitasi**: setelah streaming, jawaban dibersihkan dari pola tilde tunggal dan karakter pengganti sebelum ditampilkan/disimpan di client.
- **Response depth**: profil `hemat` / `adaptive` / `mendalam` mengatur anggaran `num_predict` berdasarkan kompleksitas pertanyaan dan metadata model.
- **Vision**: hanya model dengan `supports_vision` (Gemma 4); frontend menolak upload gambar jika model tidak mendukung.
- **Multi API key**: `secrets.php` mendukung beberapa key; pilih lewat `active_key` atau env.

---

## Lisensi & Kredit

Proyek ini dibuat untuk penggunaan pribadi / internal / tugas akademik. Sesuaikan lisensi sesuai kebutuhan Anda.

Model yang digunakan berasal dari Ollama Cloud (GPT-OSS, Nemotron, Gemma, dll.). Patuhi ketentuan layanan Ollama.

Revisi terkait test otomatis dan komponen ML didokumentasikan di `CHANGELOG_REVISI.md`.

---

**Selamat menggunakan Sigit AI!**  
Jika menemukan bug atau ingin berkontribusi, lakukan perbaikan langsung pada kode sumber dan pastikan suite test tetap lulus.
