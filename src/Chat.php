<?php
namespace App;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use PDO;
use PDOException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Chat implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;
    protected $pdo;
    protected $logger;
    protected $maxReconnectAttempts = 10;
    protected $reconnectDelay = 5;

    public function __construct() {
        $this->logger = new Logger('chat_logger');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/chat.log', Logger::DEBUG));
        $this->clients = new \SplObjectStorage;
        $this->userConnections = [];
        $this->initializeDatabase();
    }

    protected function initializeDatabase() {
        require_once __DIR__ . '/../config/config.php';
        $attempt = 0;
        while ($attempt < $this->maxReconnectAttempts) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->pdo->query('SELECT 1');
                $this->logger->info("Database connection successful within Chat.php.");
                return;
            } catch (PDOException $e) {
                $attempt++;
                $this->logger->error("Database connection failed within Chat.php: {$e->getMessage()}. Attempt {$attempt} of {$this->maxReconnectAttempts}.");
                sleep($this->reconnectDelay);
            }
        }
        $this->logger->critical("Failed to connect to the database after {$this->maxReconnectAttempts} attempts. Shutting down.");
        exit(1);
    }

    protected function getPdo() {
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->logger->warning("Lost database connection: {$e->getMessage()}. Attempting to reconnect.");
            $this->initializeDatabase();
        }
        return $this->pdo;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $cookies = $conn->httpRequest->getHeader('Cookie');
        $sessionId = null;
        foreach ($cookies as $cookieHeader) {
            $cookiesArray = $this->parseCookies($cookieHeader);
            if (isset($cookiesArray['PHPSESSID'])) {
                $sessionId = $cookiesArray['PHPSESSID'];
                break;
            }
        }
        if ($sessionId) {
            $userId = $this->authenticateSession($sessionId);
            if ($userId !== false) {
                $conn->userId = (int)$userId;
                $this->userConnections[$conn->userId] = $conn;
                $this->logger->info("User {$conn->userId} connected with connection ID {$conn->resourceId}");
            } else {
                $this->logger->warning("Invalid session for connection ID {$conn->resourceId}");
                $conn->send(json_encode(['type' => 'error', 'message' => 'Authentication failed']));
                $conn->close();
            }
        } else {
            $this->logger->warning("No PHPSESSID provided for connection ID {$conn->resourceId}");
            $conn->send(json_encode(['type' => 'error', 'message' => 'Authentication token required']));
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $this->logger->info("Received message from user {$from->userId}: {$msg}");

        $data = json_decode($msg, true);
        if (!$data) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid message format']));
            $this->logger->warning("Invalid message format from user {$from->userId}");
            return;
        }
        switch ($data['type']) {
            case 'send':
                $this->handleSendMessage($from, $data);
                break;
            default:
                $from->send(json_encode(['type' => 'error', 'message' => 'Unknown message type']));
                $this->logger->warning("Unknown message type from user {$from->userId}: {$data['type']}");
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->userId)) {
            unset($this->userConnections[$conn->userId]);
            $this->logger->info("Connection {$conn->resourceId} for user {$conn->userId} has disconnected");
        } else {
            $this->logger->info("Connection {$conn->resourceId} has disconnected");
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->logger->error("An error has occurred: {$e->getMessage()}");
        $conn->close();
    }

    protected function authenticateSession($sessionId) {
        $sessionFile = __DIR__ . '/../sessions/sess_' . $sessionId;
        if (!file_exists($sessionFile)) {
            return false;
        }
        $sessionData = file_get_contents($sessionFile);
        if (!$sessionData) {
            return false;
        }
        $parsedData = $this->parseSessionData($sessionData);
        return isset($parsedData['user_id']) ? (int)$parsedData['user_id'] : false;
    }

    protected function parseSessionData($sessionData) {
        $returnData = [];
        $offset = 0;
        while ($offset < strlen($sessionData)) {
            if (!strstr(substr($sessionData, $offset), "|")) {
                break;
            }
            $pos = strpos($sessionData, "|", $offset);
            $varname = substr($sessionData, $offset, $pos - $offset);
            $offset = $pos + 1;
            $serializedData = substr($sessionData, $offset);
            $value = unserialize($serializedData);
            if ($value === false && $serializedData !== 'b:0;') {
                break;
            }
            $returnData[$varname] = $value;
            $offset += strlen(serialize($value));
        }
        return $returnData;
    }

    protected function parseCookies($cookieHeader) {
        $cookies = [];
        $pairs = explode(';', $cookieHeader);
        foreach ($pairs as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) == 2) {
                $cookies[$parts[0]] = urldecode($parts[1]);
            }
        }
        return $cookies;
    }

    protected function handleSendMessage($from, $data) {
        $content = trim($data['content']);
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("SELECT avatar_filename FROM users WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $from->userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $avatarUrl = $user['avatar_filename'] ? '/uploads/avatars/' . $user['avatar_filename'] : null;

            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, content, created_at) VALUES (:sender_id, :content, NOW())");
            $stmt->execute([
                'sender_id' => (int)$from->userId,
                'content' => $content,
            ]);

            foreach ($this->clients as $client) {
                $client->send(json_encode([
                    'type' => 'message',
                    'sender_id' => (int)$from->userId,
                    'content' => $content,
                    'avatar_url' => $avatarUrl,
                    'timestamp' => date('c'),
                ]));
            }
            $this->logger->info("Message sent from user {$from->userId}: {$content}");
        } catch (PDOException $e) {
            $this->logger->error("Database error while sending message: {$e->getMessage()}");
            $from->send(json_encode(['type' => 'error', 'message' => 'Failed to send message']));
        }
    }
}
?>