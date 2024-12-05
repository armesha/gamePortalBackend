<?php
// api/upload_avatar.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';
require_once '../helpers/image.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

requireLogin();

// Check if a file was uploaded
if (!isset($_FILES['avatar'])) {
    sendResponse(['error' => 'No file uploaded'], 400);
}

// Process the uploaded image
$avatarResult = processAvatarImage($_FILES['avatar']);
if (isset($avatarResult['error'])) {
    sendResponse(['error' => $avatarResult['error']], 400);
}

$avatarFilename = $avatarResult['filename'];
$userId = $_SESSION['user_id'];

// Update user's avatar in the database
$stmt = $pdo->prepare("UPDATE users SET avatar_filename = :avatar_filename WHERE user_id = :user_id");
try {
    $stmt->execute(['avatar_filename' => $avatarFilename, 'user_id' => $userId]);
    sendResponse(['message' => 'Avatar updated successfully', 'avatar_url' => 'uploads/avatars/' . $avatarFilename], 200);
} catch (PDOException $e) {
    sendResponse(['error' => 'Failed to update avatar: ' . $e->getMessage()], 500);
}
?>
