<?php
// Bot configuration
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_ID', 'YOUR_TELEGRAM_USER_ID');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('DATA_DIR', __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.json');
define('CONVERSATIONS_FILE', DATA_DIR . 'conversations.json');
define('ERROR_LOG', DATA_DIR . 'error.log');

// Ensure data directory exists
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Initialize bot (clear webhook)
function initializeBot() {
    try {
        file_get_contents(API_URL . 'setWebhook?url=');
        return true;
    } catch (Exception $e) {
        logError("Initialization failed: " . $e->getMessage());
        return false;
    }
}

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Data management
function loadData($file) {
    try {
        if (!file_exists($file)) {
            file_put_contents($file, json_encode([]));
        }
        return json_decode(file_get_contents($file), true) ?: [];
    } catch (Exception $e) {
        logError("Load data failed ($file): " . $e->getMessage());
        return [];
    }
}

function saveData($file, $data) {
    try {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        logError("Save data failed ($file): " . $e->getMessage());
        return false;
    }
}

// Send message with optional keyboard
function sendMessage($chat_id, $text, $keyboard = null, $reply_to = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        
        if ($reply_to) {
            $params['reply_to_message_id'] = $reply_to;
        }
        
        $url = API_URL . 'sendMessage?' . http_build_query($params);
        file_get_contents($url);
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Get user info or create new
function getUser($chat_id) {
    $users = loadData(USERS_FILE);
    
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = [
            'id' => $chat_id,
            'username' => '',
            'first_name' => '',
            'last_name' => '',
            'is_admin' => ($chat_id == ADMIN_ID),
            'is_blocked' => false,
            'created_at' => time()
        ];
        saveData(USERS_FILE, $users);
    }
    
    return $users[$chat_id];
}

// Process incoming message
function processMessage($message) {
    $chat_id = $message['chat']['id'];
    $user = getUser($chat_id);
    $text = trim($message['text'] ?? '');
    
    // Update user info
    $users = loadData(USERS_FILE);
    $users[$chat_id]['username'] = $message['chat']['username'] ?? '';
    $users[$chat_id]['first_name'] = $message['chat']['first_name'] ?? '';
    $users[$chat_id]['last_name'] = $message['chat']['last_name'] ?? '';
    saveData(USERS_FILE, $users);
    
    // Check if user is blocked
    if ($user['is_blocked'] && $chat_id != ADMIN_ID) {
        sendMessage($chat_id, "⛔ You are blocked from using this bot.");
        return;
    }
    
    // Handle commands
    if (strpos($text, '/') === 0) {
        $command = explode(' ', $text)[0];
        
        switch ($command) {
            case '/start':
                $welcome = "👋 Welcome to Middleman Bot!\n\n";
                $welcome .= "This bot acts as a secure communication channel.\n";
                $welcome .= "Your messages will be forwarded to the admin who will respond to you here.\n\n";
                $welcome .= "Just type your message and send it!";
                sendMessage($chat_id, $welcome);
                break;
                
            case '/admin':
                if ($user['is_admin']) {
                    $admin_help = "🛠️ Admin Commands:\n";
                    $admin_help .= "/users - List all users\n";
                    $admin_help .= "/block [id] - Block a user\n";
                    $admin_help .= "/unblock [id] - Unblock a user\n";
                    $admin_help .= "/broadcast [msg] - Send message to all users\n";
                    $admin_help .= "/conversations - List active conversations";
                    sendMessage($chat_id, $admin_help);
                }
                break;
                
            default:
                if ($user['is_admin'] && strpos($text, '/reply ') === 0) {
                    handleAdminReply($text, $chat_id);
                } else {
                    forwardToAdmin($chat_id, $text);
                }
                break;
        }
    } else {
        // Regular message - forward to appropriate party
        if ($chat_id == ADMIN_ID) {
            sendMessage($chat_id, "Please use /reply [user_id] [message] to respond to a user.");
        } else {
            forwardToAdmin($chat_id, $text);
        }
    }
}

// Forward user message to admin
function forwardToAdmin($user_id, $message) {
    $users = loadData(USERS_FILE);
    $user = $users[$user_id] ?? null;
    
    if (!$user) return;
    
    $conversations = loadData(CONVERSATIONS_FILE);
    $conversation_id = $user_id;
    
    // Store message in conversation history
    $conversations[$conversation_id][] = [
        'from' => $user_id,
        'message' => $message,
        'timestamp' => time()
    ];
    saveData(CONVERSATIONS_FILE, $conversations);
    
    // Format message for admin
    $user_info = "User: " . ($user['first_name'] ?? '') . " " . ($user['last_name'] ?? '');
    $user_info .= " (@".($user['username'] ?? '') . ")\n";
    $user_info .= "ID: $user_id\n\n";
    $user_info .= "Message:\n$message\n\n";
    $user_info .= "Reply with: /reply $user_id [your message]";
    
    sendMessage(ADMIN_ID, $user_info);
    sendMessage($user_id, "✅ Your message has been forwarded to the admin. Please wait for a response.");
}

// Handle admin replies
function handleAdminReply($text, $admin_id) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        sendMessage($admin_id, "Usage: /reply [user_id] [message]");
        return;
    }
    
    $user_id = $parts[1];
    $message = $parts[2];
    
    $users = loadData(USERS_FILE);
    if (!isset($users[$user_id])) {
        sendMessage($admin_id, "❌ User not found.");
        return;
    }
    
    $conversations = loadData(CONVERSATIONS_FILE);
    $conversation_id = $user_id;
    
    // Store admin reply in conversation history
    $conversations[$conversation_id][] = [
        'from' => $admin_id,
        'message' => $message,
        'timestamp' => time()
    ];
    saveData(CONVERSATIONS_FILE, $conversations);
    
    // Send to user
    sendMessage($user_id, "💌 Admin Response:\n\n$message");
    sendMessage($admin_id, "✅ Your reply has been sent to user $user_id.");
}

// Process callback queries (for buttons)
function processCallbackQuery($callback_query) {
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];
    
    // Handle button presses if needed
    // You can add buttons for quick replies, etc.
}

// Main update processor
function processUpdate($update) {
    if (isset($update['message'])) {
        processMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        processCallbackQuery($update['callback_query']);
    }
}

// Webhook handler (for Render.com)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        processUpdate($update);
    }
    
    // Send 200 OK response
    http_response_code(200);
    exit;
}

// Polling mode (for testing)
function runBot() {
    $offset = 0;
    initializeBot();
    
    while (true) {
        try {
            $updates = file_get_contents(API_URL . "getUpdates?offset=$offset&timeout=30");
            $updates = json_decode($updates, true);
            
            if ($updates['ok'] && !empty($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $offset = $update['update_id'] + 1;
                    processUpdate($update);
                }
            }
            
            usleep(100000);
        } catch (Exception $e) {
            logError("Polling error: " . $e->getMessage());
            sleep(1);
        }
    }
}

// Uncomment for polling mode (not needed for webhook)
// runBot();
?>
