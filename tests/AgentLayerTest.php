<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$tools = ollama_tool_definitions(true);
$run = agent_start_run('Hitung 12 * 8 dan jelaskan hasilnya', $tools);
check(in_array('hitung', $run['allowed_tools'], true), 'Kalkulator harus tersedia dalam rencana.');
check(agent_validate_tool_call('hitung', ['ekspresi' => '12*8']) === null, 'Panggilan kalkulator valid ditolak.');
check(agent_validate_tool_call('hitung', []) !== null, 'Ekspresi kosong harus ditolak.');
check(agent_validate_tool_call('buka_shell', []) !== null, 'Tool di luar allowlist harus ditolak.');
agent_record_tool_call('hitung', ['ekspresi' => '12*8'], 'HASIL: 96', 1.2);
$finished = agent_finish_run('Hasilnya 96.');
check($finished['verdict'] === 'passed', 'Run valid harus lulus verifikasi.');
check($finished['tool_calls'][0]['result_sha256'] === hash('sha256', 'HASIL: 96'), 'Audit hash hasil salah.');

$incomplete = agent_start_run('Hitung 99 / 3', $tools);
$incomplete = agent_finish_run('33');
check($incomplete['verdict'] === 'needs_review', 'Verifier harus menolak tugas hitung yang tidak memakai tool.');

check(str_contains(tool_calculate('12*(5+3)'), '96'), 'Kalkulator deterministik gagal.');
echo "PASS: AgentLayerTest\n";
