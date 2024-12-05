<?php
// api/like_game.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

requireLogin();

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['game_id']) || !is_numeric($input['game_id'])) {
    sendResponse(['error' => 'Invalid or missing game ID'], 400);
}

$gameId = (int)$input['game_id'];
$userId = $_SESSION['user_id'];

// Check if game exists
$stmt = $pdo->prepare("SELECT game_id FROM games WHERE game_id = :game_id");
$stmt->execute(['game_id' => $gameId]);
if (!$stmt->fetch()) {
    sendResponse(['error' => 'Game not found'], 404);
}

// Check if already liked
$stmt = $pdo->prepare("SELECT * FROM favorite_games WHERE user_id = :user_id AND game_id = :game_id");
$stmt->execute(['user_id' => $userId, 'game_id' => $gameId]);
if ($stmt->fetch()) {
    sendResponse(['error' => 'Game already in favorites'], 409);
}

// Add to favorites
$stmt = $pdo->prepare("INSERT INTO favorite_games (user_id, game_id) VALUES (:user_id, :game_id)");
try {
    $stmt->execute(['user_id' => $userId, 'game_id' => $gameId]);
    sendResponse(['message' => 'Game added to favorites'], 201);
} catch (PDOException $e) {
    sendResponse(['error' => 'Failed to like game: ' . $e->getMessage()], 500);
}
?>
