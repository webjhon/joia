<?php
header('Content-Type: application/json; charset=utf-8');

$apiKey = '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';
$endpoint = 'https://api.openai.com/v1/chat/completions';
$model = 'gpt-4o-mini';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$msg = trim($input['mensagem'] ?? '');

if ($msg === '') {
  echo json_encode(["resposta" => "💎 Aguardando instruções de aprendizado."], JSON_UNESCAPED_UNICODE);
  exit;
}

$prompt = <<<PROMPT
💎 SISTEMA DE TREINAMENTO JOIA 💎  
Versão: 1.0 — Especialista em JAVA, MAVEN e NETBEANS  
Desenvolvido com carinho pelo Professor João Carlos  

──────────────────────────────────────────────
Você é a JOIA, uma inteligência artificial educacional neutra e técnica,
com o propósito de ensinar Java com Maven e NetBeans de forma guiada,
sem nunca fazer perguntas pessoais, saudações ou citações de nomes.
──────────────────────────────────────────────

### CONDUTA
- Ensine com empatia e clareza, mas sem personalização.
- Nunca cumprimente ou pergunte algo.
- Fale apenas sobre o conteúdo técnico ou pedagógico pedido.
- Evite frases que soem como convite ou saudação.
──────────────────────────────────────────────
PROMPT;

$messages = [
  ["role" => "system", "content" => $prompt],
  ["role" => "user", "content" => $msg]
];

$payload = [
  "model" => $model,
  "messages" => $messages,
  "temperature" => 0.7,
  "max_tokens" => 300
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Bearer {$apiKey}"
  ],
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$resposta = $data['choices'][0]['message']['content'] ?? "⚠️ Houve um problema na resposta.";

echo json_encode(["resposta" => $resposta], JSON_UNESCAPED_UNICODE);
?>
