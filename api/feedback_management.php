<?php
// File: api/feedback_management.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Determine the request method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Fetch feedbacks by game_id
        try {
            if (!isset($_GET['game_id']) || !is_numeric($_GET['game_id'])) {
                sendResponse(['error' => 'Invalid or missing game ID'], 400);
            }

            $gameId = (int)$_GET['game_id'];

            $stmt = $pdo->prepare("
                SELECT 
                    f.feedback_id, 
                    f.rating, 
                    f.comment, 
                    f.created_at, 
                    u.user_id, 
                    u.first_name, 
                    u.last_name, 
                    u.user_nickname, 
                    u.avatar_filename,
                    g.game_id,
                    g.game_name
                FROM feedbacks f
                JOIN users u ON f.user_id = u.user_id
                JOIN games g ON f.game_id = g.game_id
                WHERE g.game_id = :game_id
                ORDER BY f.created_at DESC
            ");
            $stmt->execute(['game_id' => $gameId]);
            $feedbacks = $stmt->fetchAll();

            // Append avatar URLs
            foreach ($feedbacks as &$feedback) {
                $feedback['avatar_url'] = $feedback['avatar_filename'] ? 'uploads/avatars/' . $feedback['avatar_filename'] : null;
                unset($feedback['avatar_filename']);
            }

            sendResponse(['feedbacks' => $feedbacks], 200);
        } catch (PDOException $e) {
            error_log("Feedback Fetch Error: " . $e->getMessage());
            sendResponse(['error' => 'Internal Server Error'], 500);
        }
        break;

    case 'DELETE':
        // Delete a feedback and update game rating
        requireLogin();

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['feedback_id']) || !is_numeric($input['feedback_id'])) {
            sendResponse(['error' => 'Invalid or missing feedback ID'], 400);
        }

        $feedbackId = (int)$input['feedback_id'];
        $userId = $_SESSION['user_id'];

        try {
            // Fetch feedback
            $stmt = $pdo->prepare("SELECT user_id, game_id, rating FROM feedbacks WHERE feedback_id = :feedback_id");
            $stmt->execute(['feedback_id' => $feedbackId]);
            $feedback = $stmt->fetch();

            if (!$feedback) {
                sendResponse(['error' => 'Feedback not found'], 404);
            }

            // Check permissions
            $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);
            $currentUser = $stmt->fetch();

            if ($currentUser['user_role'] !== 'admin' && $feedback['user_id'] != $userId) {
                sendResponse(['error' => 'Forbidden'], 403);
            }

            $gameId = $feedback['game_id'];
            $rating = $feedback['rating'];

            // Delete feedback
            $stmt = $pdo->prepare("DELETE FROM feedbacks WHERE feedback_id = :feedback_id");
            $stmt->execute(['feedback_id' => $feedbackId]);

            // Update game's positive or negative counts
            if ($rating > 2.5) {
                $stmt = $pdo->prepare("UPDATE games SET positive = GREATEST(positive - 1, 0) WHERE game_id = :game_id");
            } elseif ($rating < 2.5) {
                $stmt = $pdo->prepare("UPDATE games SET negative = GREATEST(negative - 1, 0) WHERE game_id = :game_id");
            }
            $stmt->execute(['game_id' => $gameId]);

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

            sendResponse(['message' => 'Feedback deleted successfully'], 200);

        } catch (PDOException $e) {
            sendResponse(['error' => 'Failed to delete feedback: ' . $e->getMessage()], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Invalid request method'], 405);
        break;
}
?>