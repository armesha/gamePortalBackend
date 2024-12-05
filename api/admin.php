<?php
// api/admin.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Povolit hlášení chyb (pouze pro vývoj; vypnout v produkci)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ujistit se, že je uživatel přihlášen
requireLogin();

// Zkontrolovat, zda je uživatel admin
try {
    $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if (!$currentUser) {
        sendResponse(['error' => 'Uživatel nenalezen'], 404);
    }

    if ($currentUser['user_role'] !== 'admin') {
        sendResponse(['error' => 'Přístup odepřen'], 403);
    }
} catch (PDOException $e) {
    sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
}

// Zpracování admin akcí: úprava profilů, blokování uživatelů, mazání zpráv/komentářů
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['action'])) {
    sendResponse(['error' => 'Akce je povinná'], 400);
}

switch ($input['action']) {
    case 'block_user':
        if (empty($input['user_id'])) {
            sendResponse(['error' => 'ID uživatele je povinné'], 400);
        }

        // Zabránit blokování jiných adminů nebo sebe sama
        $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $input['user_id']]);
        $targetUser = $stmt->fetch();

        if ($targetUser['user_role'] === 'admin') {
            sendResponse(['error' => 'Nelze blokovat administrátory'], 403);
        }

        if ($input['user_id'] == $_SESSION['user_id']) {
            sendResponse(['error' => 'Nelze blokovat sám sebe'], 403);
        }

        // Blokovat nebo odblokovat uživatele
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_blocked = :is_blocked WHERE user_id = :user_id");
            $stmt->execute([
                'is_blocked' => isset($input['is_blocked']) && $input['is_blocked'] ? 1 : 0,
                'user_id' => $input['user_id'],
            ]);

            sendResponse(['message' => 'Stav blokování uživatele byl aktualizován'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_message':
        if (empty($input['message_id'])) {
            sendResponse(['error' => 'ID zprávy je povinné'], 400);
        }
        // Smazat zprávu z chatu
        try {
            $stmt = $pdo->prepare("DELETE FROM messages WHERE message_id = :message_id");
            $stmt->execute(['message_id' => $input['message_id']]);
            sendResponse(['message' => 'Zpráva byla smazána'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_comment':
        if (empty($input['comment_id'])) {
            sendResponse(['error' => 'ID komentáře je povinné'], 400);
        }
        // Smazat komentář
        try {
            $stmt = $pdo->prepare("DELETE FROM feedbacks WHERE feedback_id = :comment_id");
            $stmt->execute(['comment_id' => $input['comment_id']]);
            sendResponse(['message' => 'Komentář byl smazán'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'edit_profile':
        if (empty($input['user_id']) || empty($input['data'])) {
            sendResponse(['error' => 'ID uživatele a data jsou povinná'], 400);
        }
        // Aktualizovat profil uživatele
        try {
            $updates = [];
            $params = ['user_id' => $input['user_id']];
            $newEmail = null;
            $newUsername = null;

            foreach ($input['data'] as $key => $value) {
                // Omezit aktualizovatelná pole pro bezpečnost
                $allowedFields = ['first_name', 'last_name', 'user_nickname', 'email', 'phone_number'];
                if (in_array($key, $allowedFields)) {
                    $updates[] = "$key = :$key";
                    $params[$key] = htmlspecialchars(trim($value));
                    if ($key === 'email') {
                        $newEmail = trim($value);
                    }
                    if ($key === 'user_nickname') {
                        $newUsername = trim($value);
                    }
                }
            }
            if (empty($updates)) {
                sendResponse(['error' => 'Žádná platná data k aktualizaci'], 400);
            }

            // Zkontrolovat, zda nový email již neexistuje u jiného uživatele
            if ($newEmail) {
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :user_id");
                $stmt->execute(['email' => $newEmail, 'user_id' => $input['user_id']]);
                if ($stmt->fetch()) {
                    sendResponse(['error' => 'Email již existuje'], 409);
                }
            }

            // Zkontrolovat, zda nové uživatelské jméno již neexistuje u jiného uživatele
            if ($newUsername) {
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_nickname = :username AND user_id != :user_id");
                $stmt->execute(['username' => $newUsername, 'user_id' => $input['user_id']]);
                if ($stmt->fetch()) {
                    sendResponse(['error' => 'Uživatelské jméno již existuje'], 409);
                }
            }

            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = :user_id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            sendResponse(['message' => 'Profil uživatele byl aktualizován'], 200);
        } catch (PDOException $e) {
            // Zpracovat chybu duplicitního emailu nebo uživatelského jména
            if ($e->getCode() == 23000) { // Porušení integrity
                // Určit, které pole způsobilo duplicitu
                if (strpos($e->getMessage(), 'users.email') !== false) {
                    sendResponse(['error' => 'Email již existuje'], 409);
                } elseif (strpos($e->getMessage(), 'users.user_nickname') !== false) {
                    sendResponse(['error' => 'Uživatelské jméno již existuje'], 409);
                } else {
                    sendResponse(['error' => 'Byla zjištěna duplicita'], 409);
                }
            } else {
                sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
            }
        }
        break;

    case 'assign_admin':
        if (empty($input['user_id'])) {
            sendResponse(['error' => 'ID uživatele je povinné'], 400);
        }
        // Přiřadit roli admina
        try {
            $stmt = $pdo->prepare("UPDATE users SET user_role = 'admin' WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $input['user_id']]);
            sendResponse(['message' => 'Uživatel byl povýšen na admina'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'toggle_admin':
        if (empty($input['user_id'])) {
            sendResponse(['error' => 'ID uživatele je povinné'], 400);
        }

        // Zabránit odebrání admin statusu sobě samému
        if ($input['user_id'] == $_SESSION['user_id']) {
            sendResponse(['error' => 'Nelze upravit vlastní admin status'], 403);
        }

        try {
            $newRole = isset($input['set_admin']) && $input['set_admin'] ? 'admin' : 'user';
            $stmt = $pdo->prepare("UPDATE users SET user_role = :role WHERE user_id = :user_id");
            $stmt->execute([
                'role' => $newRole,
                'user_id' => $input['user_id']
            ]);

            sendResponse(['message' => 'Admin status uživatele byl úspěšně aktualizován'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Neplatná akce'], 400);
}
?>