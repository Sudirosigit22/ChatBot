<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$appName = app_config('app_name');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Chat - Sigit</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.9/katex.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.9/katex.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
      if (window.pdfjsLib) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      }
    </script>
    <script defer src="assets/js/main.js"></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <button type="button" id="newChatBtn" class="new-chat-btn">＋ Chat Baru</button>
            <label class="search-wrap" for="conversationSearch"><span>⌕</span><input id="conversationSearch" type="search" placeholder="Cari percakapan..." autocomplete="off"></label>
            <div class="conversation-list" id="conversationList">
                <div class="sidebar-empty" id="sidebarEmpty">Belum ada percakapan</div>
            </div>
        </aside>

        <div class="main-panel">
            <header>
                <div class="header-left">
                    <button type="button" id="sidebarToggle" class="icon-btn" aria-label="Toggle sidebar">☰</button>
                    <span class="app-title">☁️ Sigit AI</span>
                    <span class="status-tag">Online</span>
                    <span class="key-tag" id="activeKeyTag" title="API key yang sedang dipakai" hidden></span>
                </div>

                <button type="button" id="mobileMenuToggle" class="icon-btn mobile-menu-toggle" aria-label="Buka menu pengaturan">⋮</button>

                <div class="top-actions" id="topActions">
                    <div class="model-row">
                        <label class="model-select-wrap" for="modelSelect" title="Pilih model AI yang dipakai">
                            🤖
                            <select id="modelSelect"></select>
                        </label>
                        <button type="button" id="modelInfoBtn" class="icon-btn" aria-label="Info model" title="Lihat penjelasan model ini">ℹ️</button>
                    </div>
                    <div id="modelInfoPanel" class="model-info-panel" hidden></div>
                    <label class="think-select-wrap" for="thinkSelect" title="Level reasoning model - makin tinggi makin teliti tapi makin banyak token dipakai">
                        🧠
                        <select id="thinkSelect">
                            <option value="low">Low (hemat token)</option>
                            <option value="medium">Medium</option>
                            <option value="high">High (paling teliti)</option>
                        </select>
                    </label>
                    <label class="think-select-wrap" for="responseDepthSelect" title="Anggaran panjang jawaban. Adaptif hanya membesar saat pertanyaan memang memerlukannya.">
                        📝
                        <select id="responseDepthSelect">
                            <option value="hemat">Hemat</option>
                            <option value="adaptive" selected>Jawaban Adaptif</option>
                            <option value="mendalam">Jawaban Mendalam</option>
                        </select>
                    </label>
                    <button type="button" id="regenBtn" class="action-btn">🔁 Regenerate</button>
                    <button type="button" id="clearBtn" class="action-btn">🗑️ Clear</button>
                    <button type="button" id="stopBtn" class="action-btn stop-btn" hidden>■ Stop</button>
                    <button type="button" id="exportBtn" class="action-btn">⬇️ Export MD</button>
                    <button type="button" id="exportPdfBtn" class="action-btn">🖨️ Export PDF</button>
                    <button type="button" id="themeBtn" class="action-btn" aria-label="Ganti tema">☾</button>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </header>

            <main id="chatBox"></main>

            <div class="input-container">
                <div class="attachments-bar" id="attachmentsBar" hidden></div>
                <form method="post" id="chatForm">
                    <input type="file" id="fileInput" class="visually-hidden"
                           accept=".txt,.md,.markdown,.csv,.tsv,.json,.log,.xml,.html,.htm,.css,.js,.mjs,.ts,.py,.java,.c,.cpp,.h,.hpp,.cs,.php,.rb,.go,.rs,.sql,.yaml,.yml,.ini,.conf,.cfg,.sh,.bat,.srt,.vtt,.tex,.pdf,.docx,.doc,.xlsx,.xls,.xlsm,.ods"
                           multiple>
                    <button type="button" id="attachBtn" class="icon-btn attach-btn" title="Lampirkan dokumen / gambar" aria-label="Lampirkan dokumen atau gambar">📎</button>
                    <textarea name="Message" id="messageInput" placeholder="Ketik pesan di sini... (Enter untuk kirim, Shift+Enter untuk baris baru)" autocomplete="off" rows="1"></textarea>
                    <button type="submit">Kirim</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
