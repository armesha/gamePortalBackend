<?php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

requireLogin();

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['content'])) {
    sendResponse(['error' => 'Message content is required'], 400);
}

$content = trim($input['content']);
$receiver_id = isset($input['receiver_id']) ? intval($input['receiver_id']) : null;

// Sanitize content
$content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

// Insert message into database
if ($receiver_id) {
    // Sending a private message
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at) VALUES (:sender_id, :receiver_id, :content, NOW())");
    $stmt->execute([
        'sender_id' => $_SESSION['user_id'],
        'receiver_id' => $receiver_id,
        'content' => $content,
    ]);
} else {
    // Sending a public message
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, content, created_at) VALUES (:sender_id, :content, NOW())");
    $stmt->execute([
        'sender_id' => $_SESSION['user_id'],
        'content' => $content,
    ]);
}

sendResponse(['message' => 'Message sent successfully'], 200);
?>