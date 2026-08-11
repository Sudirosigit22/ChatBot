# Sigit AI / SigitCloudChat

Chatbot berbasis web yang terintegrasi dengan **Ollama Cloud** (atau instance Ollama lokal). Dibangun dengan PHP murni (tanpa framework berat), SQLite sebagai penyimpanan, dan frontend interaktif berbasis JavaScript modern. Proyek ini dirancang agar dapat dijalankan di shared hosting PHP sekaligus tetap mendukung fitur lanjutan seperti tool-calling, streaming respons (SSE), pemilihan model dinamis, mode thinking/reasoning, serta kalkulator matematika presisi dan pencarian web.

---

## Daftar Isi

1. [Perkenalan Proyek](#1-perkenalan-proyek)
2. [Fungsi dan Kegunaan Utama](#2-fungsi-dan-kegunaan-utama)
3. [Keunggulan](#3-keunggulan)
4. [Fitur Lengkap](#4-fitur-lengkap)
5. [Struktur Proyek](#5-struktur-proyek)
6. [Alur Kerja (Workflow)](#6-alur-kerja-workflow)
7. [Persyaratan Sistem](#7-persyaratan-sistem)
8. [Instalasi dan Konfigurasi](#8-instalasi-dan-konfigurasi)
9. [Penggunaan](#9-penggunaan)
10. [Keamanan](#10-keamanan)
11. [Tool yang Tersedia](#11-tool-yang-tersedia)
12. [Saran Peningkatan](#12-saran-peningkatan)
13. [Pemeliharaan & Troubleshooting](#13-pemeliharaan--troubleshooting)
14. [Catatan Teknis](#14-catatan-teknis)

---

## 1. Perkenalan Proyek

**SigitCloudChat** (ditampilkan di UI sebagai **Sigit AI**) adalah aplikasi chatbot full-stack ringan yang memungkinkan pengguna berinteraksi dengan model bahasa besar (LLM) yang dilayani oleh Ollama. Fokus utamanya adalah:

- Akses mudah ke model cloud Ollama (gpt-oss, Nemotron, Gemma, dll.) tanpa perlu menjalankan binary Ollama di server sendiri.
- Pengalaman chat modern: streaming token real-time, markdown + LaTeX + syntax highlighting, riwayat percakapan yang tersimpan, ekspor, dan kontrol reasoning.
- Akurasi perhitungan tinggi melalui **tool-calling** nyata (bukan sekadar instruksi prompt), terutama untuk matematika, rasio gigi, dan analisis performa kendaraan RC (Remote Control).
- Kemampuan mencari informasi terkini via web search (Wikipedia + DuckDuckGo).

Proyek ini cocok untuk penggunaan pribadi, demo internal, atau deployment di shared hosting (misalnya InfinityFree, Hostinger, dll.) karena hanya membutuhkan PHP + ekstensi PDO SQLite + cURL.

Nama aplikasi, judul halaman, kredensial default, dan daftar model dapat diubah seluruhnya lewat satu file konfigurasi (`config.php`).

---

## 2. Fungsi dan Kegunaan Utama

| Fungsi | Penjelasan |
|--------|------------|
| **Chat interaktif** | Kirim pesan teks, terima jawaban streaming dari model Ollama. |
| **Multi-model** | Pilih model dari dropdown (GPT-OSS 120b/20b, Nemotron 3 Super, Gemma 4, dll.). Setiap model punya metadata (label, deskripsi, mode thinking, context window, dukungan tool). |
| **Mode Thinking / Reasoning** | Untuk model yang mendukung: low / medium / high, atau on/off. Level lebih tinggi = reasoning lebih dalam (lebih banyak token). |
| **Tool-calling** | Model dapat memanggil kalkulator presisi, batch calculation, chained calculation, web search, dan tool khusus analisis performa RC. |
| **Manajemen percakapan** | Buat, ganti nama, hapus, cari, dan simpan riwayat percakapan per pengguna. |
| **Edit & Regenerate** | Edit pesan user sebelumnya (menghapus pesan setelahnya) atau regenerate jawaban terakhir. |
| **Ekspor** | Unduh percakapan sebagai Markdown atau PDF. |
| **Tema gelap/terang** | Toggle tema UI. |
| **Autentikasi sederhana** | Login dengan username/password yang dikonfigurasi di `config.php`. |
| **Streaming SSE** | Respons ditampilkan token-per-token di browser tanpa menunggu jawaban selesai. |

**Kegunaan praktis:**

- Asisten coding, penulisan, dan analisis teks.
- Perhitungan matematis akurat (termasuk rantai perhitungan kompleks dan rasio gigi).
- Analisis performa kendaraan RC (kecepatan, torsi, arus, gearing, dll.).
- Pencarian fakta terkini tanpa meninggalkan aplikasi.
- Chat pribadi dengan model cloud yang kuat tanpa biaya infrastruktur GPU lokal.

---

## 3. Keunggulan

1. **Tanpa dependensi berat** — Hanya PHP native + SQLite. Tidak perlu Composer, Node.js, atau database server terpisah.
2. **Siap shared hosting** — Bisa di-deploy di hosting yang hanya mendukung PHP + cURL + PDO SQLite. Ollama Cloud diakses lewat internet menggunakan API key.
3. **Tool-calling nyata** — Model tidak “menebak” angka; ia memanggil fungsi PHP yang mengevaluasi ekspresi matematika secara presisi.
4. **Streaming real-time (SSE)** — Pengalaman mirip ChatGPT: teks muncul secara bertahap.
5. **Kontrol model & thinking yang fleksibel** — Metadata model dikonfigurasi di satu tempat; frontend menyesuaikan UI secara otomatis.
6. **Context window per model** — Setiap model bisa punya `num_ctx` sendiri (hingga 1M token untuk Nemotron).
7. **Riwayat cerdas** — Hanya N pesan / M karakter terbaru yang dikirim ke model (menghemat token), sementara riwayat lengkap tetap tersimpan di database dan UI.
8. **Keamanan dasar** — CSRF token, session yang aman, validasi model, ownership check percakapan.
9. **UI modern & responsif** — Sidebar, dark mode, rendering Markdown + KaTeX + highlight.js.
10. **Domain-specific tools** — Tool khusus RC performance yang hanya diaktifkan saat query relevan, menghindari interferensi pada tugas umum.

---

## 4. Fitur Lengkap

### Frontend
- Layout dua kolom (sidebar percakapan + panel chat).
- Pencarian percakapan.
- Tombol Chat Baru, Regenerate, Clear, Stop, Export MD, Export PDF, Theme toggle, Logout.
- Info panel model (ⓘ).
- Edit pesan user.
- Indikator typing.
- Responsive (mobile menu).

### Backend
- Autentikasi session-based.
- CRUD percakapan & pesan (SQLite).
- Streaming chat via Server-Sent Events.
- Generate judul percakapan otomatis oleh AI.
- Sanitasi karakter rusak (U+FFFD) dan tilde tunggal.
- Batas putaran tool (default 24) untuk mencegah infinite loop.
- Fallback & error handling yang ramah pengguna (termasuk pesan kuota Ollama).

### Tools (Function Calling)
- `hitung` — evaluasi ekspresi matematika tunggal.
- `hitung_batch` — banyak perhitungan independen sekaligus (maks 40).
- `hitung_berantai` — rangkaian langkah yang saling bergantung (menggunakan hasil1, hasil2, …).
- `web_search` — pencarian via DuckDuckGo + Wikipedia.
- `hitung_performansi_rc` — analisis performa kendaraan RC (motor, gear, baterai, ESC, dll.).

---

## 5. Struktur Proyek

```
ChatBot/
├── api/                          # Endpoint API (JSON / SSE)
│   ├── conversations.php         # List, create, rename, delete percakapan
│   ├── login.php                 # Proses login
│   ├── main.php                  # Endpoint chat utama (streaming SSE)
│   ├── messages.php              # Ambil pesan suatu percakapan
│   └── models.php                # Daftar model + metadata untuk frontend
├── assets/
│   ├── css/
│   │   ├── login.css             # Gaya halaman login
│   │   └── main.css              # Gaya utama aplikasi chat
│   └── js/
│       └── main.js               # Logika frontend (SSE, rendering, UI)
├── includes/
│   ├── bootstrap.php             # Inisialisasi, auth, CSRF, fungsi Ollama core
│   ├── db.php                    # Lapisan database SQLite (PDO)
│   └── tools.php                 # Definisi & eksekusi tool-calling
├── data/                         # (otomatis dibuat) file chat.sqlite
├── config.php                    # Konfigurasi utama (kredensial, model, API key, dll.)
├── index.php                     # Halaman chat (butuh login)
├── login.php                     # Halaman login
├── logout.php                    # Logout
├── repair_replacement_chars.php  # Skrip sekali-jalan perbaikan karakter rusak di DB
└── README.md                     # Dokumentasi ini
```

**Penjelasan singkat file kunci:**

- **`config.php`** — Satu-satunya tempat mengubah username/password, API key Ollama, daftar model, parameter sampling, batas history, dll. Banyak nilai bisa di-override lewat environment variable.
- **`includes/bootstrap.php`** — Heart of the application: session, CSRF, resolusi model, streaming Ollama, tool loop, sanitasi jawaban, generate judul.
- **`includes/tools.php`** — Skema tool OpenAI-style + implementasi kalkulator (parser ekspresi sendiri), web search, dan tool RC.
- **`includes/db.php`** — Migrasi otomatis tabel `conversations` & `messages`, helper CRUD.
- **`api/main.php`** — Menerima pesan, menyimpan ke DB, memanggil `ollama_chat_stream()`, mengirim event SSE (`meta`, `token`, `error`, `done`).
- **`assets/js/main.js`** — Mengelola state percakapan, EventSource/fetch streaming, rendering Markdown+KaTeX+HLJS, kontrol model & thinking.

---

## 6. Alur Kerja (Workflow)

### Login
1. User membuka `login.php`.
2. Form POST ke `api/login.php`.
3. Kredensial dicek terhadap `config.php` → session dibuat → redirect ke `index.php`.

### Chat biasa
1. Frontend mengirim POST ke `api/main.php` (pesan + conversation_id + model + think_level + CSRF).
2. Backend memvalidasi login & CSRF.
3. Jika conversation baru → buat record di DB + judul sementara.
4. Simpan pesan user.
5. Header SSE dikirim; event `meta` (conversation_id, model) dikirim.
6. `ollama_chat_stream()` dipanggil:
   - History dibatasi.
   - System prompt + tools disiapkan (tool RC hanya jika query relevan).
   - Loop putaran tool (maks `tool_max_rounds`):
     - Stream request ke Ollama.
     - Jika model meminta tool call → eksekusi di PHP → masukkan hasil ke pesan → lanjut putaran.
     - Jika model menulis jawaban final → stream token ke browser via callback.
7. Jawaban disanitasi, disimpan ke DB, judul AI digenerate (jika percakapan baru), event `done` dikirim.

### Regenerate / Edit
- Regenerate: hapus pesan assistant terakhir, lalu stream ulang.
- Edit: hapus semua pesan mulai dari pesan yang diedit, simpan versi baru, stream ulang.

### Manajemen percakapan
- List/search → `api/conversations.php` (GET).
- Create/rename/delete → POST dengan action + CSRF.

### Streaming di browser
Frontend menggunakan `fetch` + `ReadableStream` (atau EventSource-like parsing) untuk menampilkan token secara real-time, lalu merender Markdown/KaTeX setelah selesai atau secara bertahap.

---

## 7. Persyaratan Sistem

- **PHP** ≥ 8.0 (direkomendasikan 8.1+) dengan ekstensi:
  - `pdo_sqlite`
  - `curl`
  - `json`
  - `mbstring` (sangat disarankan)
- **Akses internet keluar** dari server PHP (untuk Ollama Cloud + web search).
- **Ollama Cloud account** + API key (atau Ollama lokal yang sudah di-login).
- Web server (Apache/Nginx) atau `php -S` untuk development.
- Izin tulis pada folder `data/` (dibuat otomatis).

---

## 8. Instalasi dan Konfigurasi

1. **Unggah / ekstrak** seluruh folder proyek ke document root atau subfolder.
2. **Buat folder data** (opsional, akan dibuat otomatis):
   ```bash
   mkdir -p data && chmod 775 data
   ```
3. **Edit `config.php`**:
   - Ganti `'username'` dan `'password'`.
   - Isi `'ollama_api_key'` dengan key dari [ollama.com/settings/keys](https://ollama.com/settings/keys) **atau** set environment variable `OLLAMA_API_KEY`.
   - Sesuaikan `'available_models'` jika ingin menambah/menghapus model.
   - Atur `'ollama_base_url'` bila memakai instance Ollama sendiri (default `https://ollama.com`).
4. **Environment variables** yang didukung (override config):
   - `OLLAMA_BASE_URL`
   - `OLLAMA_API_KEY`
   - `OLLAMA_MODEL`
   - `OLLAMA_CONTEXT_WINDOW`
   - `OLLAMA_TEMPERATURE`
   - `OLLAMA_SEED`
   - `OLLAMA_THINK`
   - `OLLAMA_ENABLE_TOOLS`
   - `OLLAMA_WEB_SEARCH`
   - `OLLAMA_TOOL_MAX_ROUNDS`
   - `OLLAMA_HISTORY_MAX_MESSAGES`
   - `OLLAMA_HISTORY_MAX_CHARS`
5. Akses `https://domain-anda/login.php`.

**Catatan keamanan penting:**  
API key dan password default (`admin` / `123`) **harus diganti** sebelum production. Jangan commit `config.php` yang berisi key asli ke repositori publik.

---

## 9. Penggunaan

1. Login dengan kredensial yang dikonfigurasi.
2. Pilih model dari dropdown 🤖.
3. (Opsional) Atur level thinking 🧠.
4. Ketik pesan → Enter (atau Shift+Enter untuk baris baru).
5. Jawaban akan muncul secara streaming.
6. Gunakan tombol:
   - **＋ Chat Baru** — mulai percakapan kosong.
   - **🔁 Regenerate** — ulangi jawaban terakhir.
   - **🗑 Clear** — hapus tampilan chat saat ini (riwayat di DB tetap ada sampai dihapus).
   - **■ Stop** — hentikan generasi yang sedang berjalan.
   - **⇩ Export MD / 🖨 Export PDF** — unduh percakapan.
   - **☾** — ganti tema.
7. Klik judul percakapan di sidebar untuk membuka kembali, atau gunakan search.

---

## 10. Keamanan

- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` (jika HTTPS).
- CSRF token wajib untuk semua aksi yang mengubah data.
- Model yang dikirim dari frontend **hanya diterima** jika ada di `available_models` (mencegah injection model sembarangan).
- Setiap percakapan diikat ke username; tidak bisa dibaca/diubah user lain.
- Password disimpan plain di config (sederhana). Untuk production, pertimbangkan hashing atau integrasi auth eksternal.
- Tool execution berjalan di server PHP — pastikan ekspresi matematika tidak bisa dieksekusi sebagai kode arbitrer (parser sendiri sudah membatasi).

---

## 11. Tool yang Tersedia

### Kalkulator
- **`hitung`**: ekspresi tunggal (`sqrt(2500)+ln(10)`, `2200*22.2`, dll.).
- **`hitung_batch`**: banyak perhitungan independen (ideal untuk tabel perbandingan). Mendukung field khusus `gigi_input` / `gigi_output` untuk rasio gear (pinion selalu input, spur/diferensial selalu output).
- **`hitung_berantai`**: langkah berurutan yang saling bergantung (`hasil1 * hasil2`). Sangat berguna untuk gearbox multi-stage atau konversi satuan berlapis.

### Web Search
- **`web_search`**: menggabungkan DuckDuckGo Instant Answer + Wikipedia. Hanya aktif jika `web_search_enabled = true`.

### Analisis Performa RC
- **`hitung_performansi_rc`**: diaktifkan secara otomatis hanya jika pesan mengandung kata kunci RC + intent performa. Menghitung kecepatan, RPM, torsi, arus, gearing, volume motor, dll. berdasarkan parameter yang diberikan model.

Semua tool dipanggil oleh model melalui format function-calling OpenAI-style yang didukung Ollama. Hasil tool dimasukkan kembali ke konteks percakapan sehingga model bisa melanjutkan reasoning.

---

## 12. Saran Peningkatan

Berikut ide pengembangan lanjutan yang bisa dipertimbangkan:

1. **Autentikasi multi-user & hashing password**  
   Simpan user di database dengan password hashed (password_hash), registrasi, reset password.

2. **Rate limiting & kuota per user**  
   Batasi jumlah pesan / token per hari untuk mencegah abuse.

3. **Upload file / gambar**  
   Dukungan vision model (jika Ollama model mendukung) dan analisis dokumen.

4. **Voice input/output**  
   Integrasi Web Speech API atau layanan TTS/STT.

5. **Plugin / tool tambahan**  
   - Kalkulator unit converter yang lebih kaya.
   - Integrasi API eksternal (cuaca, saham, GitHub, dll.).
   - Code interpreter sederhana (sandbox).

6. **Admin panel**  
   Kelola model, lihat log usage, hapus percakapan user, monitor error.

7. **Caching & optimasi**  
   Cache hasil web search, kompres history lebih agresif, streaming chunk size yang lebih optimal.

8. **Deployment container**  
   Dockerfile + docker-compose (PHP-FPM + Nginx + volume untuk SQLite).

9. **Internasionalisasi (i18n)**  
   UI multi-bahasa.

10. **Tes otomatis**  
    Unit test untuk parser matematika, tool RC, dan sanitasi teks.

11. **Observability**  
    Logging request/response (tanpa menyimpan API key), metrik latensi, jumlah tool call.

12. **Offline / lokal murni**  
    Mode khusus yang hanya memakai Ollama lokal tanpa API key cloud.

---

## 13. Pemeliharaan & Troubleshooting

### Karakter rusak (U+FFFD / �)
Jika pesan lama di database mengandung karakter pengganti, jalankan:
```bash
php repair_replacement_chars.php          # dry-run
php repair_replacement_chars.php --apply  # tulis perubahan
```

### Timeout
Model besar + banyak tool round bisa melebihi batas default PHP. Bootstrap sudah menaikkan `set_time_limit(900)`. Pastikan hosting tidak memotong lebih awal (php-fpm, Cloudflare, dll.).

### Kuota Ollama Cloud
Jika muncul pesan kuota habis, tunggu reset atau upgrade plan. Error message sudah diformat ramah pengguna.

### Tool tidak terpanggil
- Pastikan `'enable_tools' => true` dan model punya `'supports_tools' => true`.
- Cek apakah `web_search_enabled` aktif jika ingin search.
- Model tertentu mungkin kurang “patuh” terhadap tool schema; coba model lain atau turunkan temperature.

### Database
File SQLite ada di `data/chat.sqlite`. Backup secara berkala. Mode WAL diaktifkan untuk konkurensi lebih baik.

---

## 14. Catatan Teknis

- **Streaming**: menggunakan `text/event-stream` + flush. Beberapa proxy (nginx, Cloudflare) perlu konfigurasi `X-Accel-Buffering: no` dan disable buffering.
- **Context window**: nilai `context_window` per model dikirim sebagai `num_ctx` ke Ollama. Nilai global hanya fallback.
- **Temperature default 0** + seed tetap → jawaban lebih deterministik (baik untuk perhitungan).
- **History truncation**: hanya N pesan / M karakter terakhir yang dikirim ke model; UI dan export tetap menampilkan seluruh riwayat.
- **RC tool gating**: deteksi berbasis regex kata kunci (`rc`, `buggy`, `pinion`, `performa`, dll.) agar tool domain-specific tidak mengganggu percakapan umum.
- **Sanitasi**: setelah streaming selesai, jawaban dibersihkan dari pola tilde tunggal dan karakter pengganti sebelum disimpan ke DB.

---

## Lisensi & Kredit

Proyek ini dibuat untuk penggunaan pribadi / internal. Sesuaikan lisensi sesuai kebutuhan Anda.  

Model yang digunakan berasal dari Ollama Cloud (GPT-OSS, Nemotron, Gemma, dll.). Pastikan mematuhi ketentuan layanan Ollama.

---

**Selamat menggunakan Sigit AI!**  
Jika menemukan bug atau ingin berkontribusi, silakan lakukan perbaikan langsung pada kode sumber.
