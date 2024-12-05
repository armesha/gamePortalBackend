<?php
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

if (empty($input['comment']) || !isset($input['rating'])) {
    sendResponse(['error' => 'Comment and rating are required'], 400);
}

$gameId = (int)$input['game_id'];
$comment = trim($input['comment']);
$rating = floatval($input['rating']);

if ($rating < 0.5 || $rating > 5.0 || ($rating * 2) != floor($rating * 2)) {
    sendResponse(['error' => 'Invalid rating value'], 400);
}

$userId = $_SESSION['user_id'];

try {
    // Check if the user has already submitted feedback for this game
    $checkStmt = $pdo->prepare("SELECT * FROM feedbacks WHERE user_id = :user_id AND game_id = :game_id");
    $checkStmt->execute([
        'user_id' => $userId,
        'game_id' => $gameId
    ]);

    if ($checkStmt->fetch()) {
        sendResponse(['error' => 'You have already submitted feedback for this game.'], 409);
    }

    // Insert feedback
    $stmt = $pdo->prepare("INSERT INTO feedbacks (user_id, game_id, comment, rating) VALUES (:user_id, :game_id, :comment, :rating)");
    $stmt->execute([
        'user_id' => $userId,
        'game_id' => $gameId,
        'comment' => $comment,
        'rating' => $rating
    ]);
    
    $feedback_id = $pdo->lastInsertId();

    // Update game's positive or negative counts
    if ($rating > 2.5) {
        $stmt = $pdo->prepare("UPDATE games SET positive = positive + 1 WHERE game_id = :game_id");
        $stmt->execute(['game_id' => $gameId]);
    } elseif ($rating < 2.5) {
        $stmt = $pdo->prepare("UPDATE games SET negative = negative + 1 WHERE game_id = :game_id");
        $stmt->execute(['game_id' => $gameId]);
    }
    // If rating is 2.5, do not update positive or negative counts

    // Recalculate rating using the formula: (positive / (positive + negative)) * 100
    $stmt = $pdo->prepare("SELECT positive, negative FROM games WHERE game_id = :game_id");
    $stmt->execute(['game_id' => $gameId]);
    $game = $stmt->fetch();

    $positive = $game['positive'];
    $negative = $game['negative'];
    $totalFeedbacks = $positive + $negative;

    if ($totalFeedbacks > 0) {
        $newRating = ($positive / $totalFeedbacks) * 100;
    } else {
        $newRating = null;
    }

    // Update the game's rating
    $stmt = $pdo->prepare("UPDATE games SET rating = :rating WHERE game_id = :game_id");
    $stmt->execute([
        'rating' => $newRating,
        'game_id' => $gameId
    ]);

    sendResponse(['message' => 'Feedback submitted successfully', 'feedback_id' => $feedback_id], 201);
} catch (PDOException $e) {
    sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
}
?>