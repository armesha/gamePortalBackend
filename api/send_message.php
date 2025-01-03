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
$userId = (int)$_SESSION['user_id'];

// Sanitize content
$content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

// Insert public message into database
$stmt = $pdo->prepare("INSERT INTO messages (sender_id, content, created_at) VALUES (:sender_id, :content, NOW())");
$stmt->bindValue(':sender_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':content', $content, PDO::PARAM_STR);
$stmt->execute();

sendResponse(['message' => 'Message sent successfully'], 200);
?>