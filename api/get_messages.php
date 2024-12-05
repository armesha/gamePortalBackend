<?php
// api/get_messages.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

requireLogin();

$userId = $_SESSION['user_id'];
$otherUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

try {
    if ($otherUserId) {
        $stmt = $pdo->prepare("
            SELECT m.message_id, m.sender_id, m.receiver_id, m.content, m.created_at, u.avatar_filename
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE (m.sender_id = :userId AND m.receiver_id = :otherUserId)
               OR (m.sender_id = :otherUserId AND m.receiver_id = :userId)
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $stmt->execute(['userId' => $userId, 'otherUserId' => $otherUserId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT m.message_id, m.sender_id, m.receiver_id, m.content, m.created_at, u.avatar_filename
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $stmt->execute();
    }

    $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    $formattedMessages = array_map(function($msg) {
        return [
            'message_id' => $msg['message_id'],
            'sender_id' => $msg['sender_id'],
            'receiver_id' => $msg['receiver_id'],
            'content' => $msg['content'],
            'avatar_url' => $msg['avatar_filename'] ? '/uploads/avatars/' . $msg['avatar_filename'] : null,
            'timestamp' => date('c', strtotime($msg['created_at']))
        ];
    }, $messages);

    sendResponse(['messages' => $formattedMessages], 200);

} catch (PDOException $e) {
    sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
}
?>