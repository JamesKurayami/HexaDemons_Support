<?php
// Bot configuration
define('BOT_TOKEN', '8149005317:AAFTCtpaPKlUkpmcyhmIWQHVAbRxwn8dJWg');
define('ADMIN_ID', '1310095655');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('DATA_DIR', __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.json');
define('CONVERSATIONS_FILE', DATA_DIR . 'conversations.json');
define('ERROR_LOG', DATA_DIR . 'error.log');
define('MAINTENANCE_FILE', DATA_DIR . 'maintenance.json');

// Ensure data directory exists
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Check maintenance mode
function isMaintenanceMode() {
    if (!file_exists(MAINTENANCE_FILE)) {
        return false;
    }
    $data = json_decode(file_get_contents(MAINTENANCE_FILE), true);
    return $data['active'] ?? false;
}

// Toggle maintenance mode
function setMaintenanceMode($status) {
    file_put_contents(MAINTENANCE_FILE, json_encode(['active' => $status]));
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

// Get admin keyboard
function getAdminKeyboard() {
    $maintenance_status = isMaintenanceMode() ? "🟢 ON" : "🔴 OFF";
    return [
        'inline_keyboard' => [
            [
                ['text' => '👥 Active Users', 'callback_data' => 'admin_users'],
                ['text' => '📊 Stats', 'callback_data' => 'admin_stats']
            ],
            [
                ['text' => '🚫 Block User', 'callback_data' => 'admin_block'],
                ['text' => '✅ Unblock User', 'callback_data' => 'admin_unblock']
            ],
            [
                ['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']
            ],
            [
                ['text' => "🔧 Maintenance ($maintenance_status)", 'callback_data' => 'admin_maintenance']
            ]
        ]
    ];
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
    
    // Check maintenance mode
    if (isMaintenanceMode() && $chat_id != ADMIN_ID) {
        sendMessage($chat_id, "🔧 Bot is currently under maintenance. Please try again later.");
        return;
    }
    
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
        $command = strtolower(explode(' ', $text)[0]);
        
        switch ($command) {
            case '/start':
                $welcome = "Welcome to HexaDemons Support!\n\n";
                $welcome .= "This bot acts as a secure communication channel.\n";
                $welcome .= "Just type your message and it will be forwarded to the admin.\n";
                sendMessage($chat_id, $welcome);
                break;
                
            case '/admin':
                if ($user['is_admin']) {
                    sendMessage($chat_id, "🛠️ Admin Panel", getAdminKeyboard());
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
        // Regular message
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
    sendMessage($user_id, "✓ Message received. Admin will respond shortly.");
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
    sendMessage($user_id, $message);
    sendMessage($admin_id, "✅ Reply sent to user $user_id");
}

// Process callback queries (for admin buttons)
function processCallbackQuery($callback_query) {
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];
    
    $user = getUser($chat_id);
    if (!$user['is_admin']) {
        sendMessage($chat_id, "⛔ Admin access required.");
        return;
    }
    
    switch ($data) {
        case 'admin_users':
            $users = loadData(USERS_FILE);
            $active_users = array_filter($users, fn($u) => !$u['is_blocked'] && $u['id'] != ADMIN_ID);
            $response = "👥 Active Users: " . count($active_users) . "\n\n";
            foreach ($active_users as $id => $user) {
                $response .= "🆔 $id | 👤 " . ($user['first_name'] ?? '') . " @" . ($user['username'] ?? '') . "\n";
            }
            sendMessage($chat_id, $response);
            break;
            
        case 'admin_stats':
            $users = loadData(USERS_FILE);
            $conversations = loadData(CONVERSATIONS_FILE);
            $stats = "📊 Bot Statistics\n\n";
            $stats .= "👥 Total Users: " . count($users) . "\n";
            $stats .= "📩 Active Conversations: " . count($conversations) . "\n";
            $stats .= "⛔ Blocked Users: " . count(array_filter($users, fn($u) => $u['is_blocked'])) . "\n";
            $stats .= "🔧 Maintenance: " . (isMaintenanceMode() ? "ON" : "OFF") . "\n";
            sendMessage($chat_id, $stats);
            break;
            
        case 'admin_block':
            sendMessage($chat_id, "Send the user ID to block (e.g., /block 12345)");
            break;
            
        case 'admin_unblock':
            sendMessage($chat_id, "Send the user ID to unblock (e.g., /unblock 12345)");
            break;
            
        case 'admin_broadcast':
            sendMessage($chat_id, "Type your broadcast message starting with /broadcast (e.g., /broadcast Hello everyone)");
            break;
            
        case 'admin_maintenance':
            $current_status = isMaintenanceMode();
            $new_status = !$current_status;
            setMaintenanceMode($new_status);
            $status_text = $new_status ? "ON" : "OFF";
            sendMessage($chat_id, "Maintenance mode is now $status_text", getAdminKeyboard());
            break;
            
        default:
            sendMessage($chat_id, "Unknown command: $data");
    }
}

// Main update processor
function processUpdate($update) {
    if (isset($update['message'])) {
        processMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        processCallbackQuery($update['callback_query']);
    }
}

// Webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        processUpdate($update);
    }
    
    http_response_code(200);
    exit;
}

// Initialize bot if accessed directly
initializeBot();
?>            file_put_contents($file, json_encode([]));
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

// Get admin keyboard
function getAdminKeyboard() {
    return [
        [
            ['text' => '👥 Active Users', 'callback_data' => 'admin_users'],
            ['text' => '📊 Stats', 'callback_data' => 'admin_stats']
        ],
        [
            ['text' => '🚫 Block User', 'callback_data' => 'admin_block'],
            ['text' => '✅ Unblock User', 'callback_data' => 'admin_unblock']
        ],
        [
            ['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']
        ]
    ];
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

// Block a user
function blockUser($admin_id, $user_id) {
    $users = loadData(USERS_FILE);
    if (!isset($users[$user_id])) {
        sendMessage($admin_id, "❌ User not found.");
        return false;
    }
    
    $users[$user_id]['is_blocked'] = true;
    saveData(USERS_FILE, $users);
    sendMessage($admin_id, "✅ User $user_id has been blocked.");
    return true;
}

// Unblock a user
function unblockUser($admin_id, $user_id) {
    $users = loadData(USERS_FILE);
    if (!isset($users[$user_id])) {
        sendMessage($admin_id, "❌ User not found.");
        return false;
    }
    
    $users[$user_id]['is_blocked'] = false;
    saveData(USERS_FILE, $users);
    sendMessage($admin_id, "✅ User $user_id has been unblocked.");
    return true;
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
        $command = strtolower(explode(' ', $text)[0]);
        
        switch ($command) {
            case '/start':
                $welcome = " Welcome to HexaDemons Support!\n\n";
                $welcome .= "This bot acts as a secure communication channel.\n";
                $welcome .= "Just type your message and it will be forwarded to the admin.\n";
                $welcome .= "You'll receive responses here\n\n";
                sendMessage($chat_id, $welcome);
                break;
                
            case '/admin':
                if ($user['is_admin']) {
                    sendMessage($chat_id, "🛠️ Admin Panel", getAdminKeyboard());
                }
                break;
                
            case '/users':
                if ($user['is_admin']) {
                    $users = loadData(USERS_FILE);
                    $active_users = array_filter($users, fn($u) => !$u['is_blocked'] && $u['id'] != ADMIN_ID);
                    $response = "👥 Active Users: " . count($active_users) . "\n\n";
                    foreach ($active_users as $id => $user) {
                        $response .= "🆔 $id | 👤 " . ($user['first_name'] ?? '') . " @" . ($user['username'] ?? '') . "\n";
                    }
                    sendMessage($chat_id, $response);
                }
                break;
                
            case '/block':
                if ($user['is_admin']) {
                    $parts = explode(' ', $text);
                    if (count($parts) < 2) {
                        sendMessage($chat_id, "Usage: /block [user_id]");
                    } else {
                        blockUser($chat_id, $parts[1]);
                    }
                }
                break;
                
            case '/unblock':
                if ($user['is_admin']) {
                    $parts = explode(' ', $text);
                    if (count($parts) < 2) {
                        sendMessage($chat_id, "Usage: /unblock [user_id]");
                    } else {
                        unblockUser($chat_id, $parts[1]);
                    }
                }
                break;
                
            case '/broadcast':
                if ($user['is_admin']) {
                    $message = trim(substr($text, strlen('/broadcast')));
                    if (empty($message)) {
                        sendMessage($chat_id, "Usage: /broadcast [message]");
                    } else {
                        $users = loadData(USERS_FILE);
                        foreach ($users as $id => $user) {
                            if (!$user['is_blocked'] && $id != ADMIN_ID) {
                                sendMessage($id, "📢 Announcement:\n\n$message");
                            }
                        }
                        sendMessage($chat_id, "✅ Broadcast sent to all users.");
                    }
                }
                break;
                
            case '/reply':
                if ($user['is_admin']) {
                    handleAdminReply($text, $chat_id);
                }
                break;
                
            default:
                forwardToAdmin($chat_id, $text);
                break;
        }
    } else {
        // Regular message
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
    sendMessage($user_id, "✓ Message received. Admin will respond shortly.");
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
    sendMessage($user_id, $message);
    sendMessage($admin_id, "✅ Reply sent to user $user_id");
}

// Process callback queries (for admin buttons)
function processCallbackQuery($callback_query) {
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];
    
    $user = getUser($chat_id);
    if (!$user['is_admin']) {
        sendMessage($chat_id, "⛔ Admin access required.");
        return;
    }
    
    switch ($data) {
        case 'admin_users':
            $users = loadData(USERS_FILE);
            $active_users = array_filter($users, fn($u) => !$u['is_blocked'] && $u['id'] != ADMIN_ID);
            $response = "👥 Active Users: " . count($active_users) . "\n\n";
            foreach ($active_users as $id => $user) {
                $response .= "🆔 $id | 👤 " . ($user['first_name'] ?? '') . " @" . ($user['username'] ?? '') . "\n";
            }
            sendMessage($chat_id, $response);
            break;
            
        case 'admin_stats':
            $users = loadData(USERS_FILE);
            $conversations = loadData(CONVERSATIONS_FILE);
            $stats = "📊 Bot Statistics\n\n";
            $stats .= "👥 Total Users: " . count($users) . "\n";
            $stats .= "📩 Active Conversations: " . count($conversations) . "\n";
            $stats .= "⛔ Blocked Users: " . count(array_filter($users, fn($u) => $u['is_blocked'])) . "\n";
            sendMessage($chat_id, $stats);
            break;
            
        case 'admin_block':
            sendMessage($chat_id, "Send the user ID to block (e.g., /block 12345)");
            break;
            
        case 'admin_unblock':
            sendMessage($chat_id, "Send the user ID to unblock (e.g., /unblock 12345)");
            break;
            
        case 'admin_broadcast':
            sendMessage($chat_id, "Type your broadcast message starting with /broadcast (e.g., /broadcast Hello everyone)");
            break;
            
        default:
            sendMessage($chat_id, "Unknown command: $data");
    }
}

// Main update processor
function processUpdate($update) {
    if (isset($update['message'])) {
        processMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        processCallbackQuery($update['callback_query']);
    }
}

// Webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        processUpdate($update);
    }
    
    http_response_code(200);
    exit;
}

// Initialize bot if accessed directly
initializeBot();
?>            file_put_contents($file, json_encode([]));
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

// Get admin keyboard
function getAdminKeyboard() {
    return [
        [
            ['text' => '👥 Active Users', 'callback_data' => 'admin_users'],
            ['text' => '📊 Stats', 'callback_data' => 'admin_stats']
        ],
        [
            ['text' => '🚫 Block User', 'callback_data' => 'admin_block'],
            ['text' => '✅ Unblock User', 'callback_data' => 'admin_unblock']
        ],
        [
            ['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']
        ]
    ];
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
                $welcome = " Welcome to HexaDemons Support!\n\n";
                $welcome .= "This bot acts as a secure communication channel.\n";
                $welcome .= "Just type your message and it will be sended to the admin.\n\n";
                $welcome .= "You'll receive responses here.";
                sendMessage($chat_id, $welcome);
                break;
                
            case '/admin':
                if ($user['is_admin']) {
                    sendMessage($chat_id, "🛠️ Admin Panel", getAdminKeyboard());
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
    sendMessage($user_id, "💌 Your message has been forwarded to the admin.\n\nAdmin will respond to you as soon as possible.");
}

// Handle admin replies - MODIFIED VERSION
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
    
    // Send to user - SIMPLIFIED OUTPUT
    sendMessage($user_id, $message); // Just the raw message
    sendMessage($admin_id, "💌 Your reply has been sent to user $user_id.");
}

// Process callback queries (for admin buttons)
function processCallbackQuery($callback_query) {
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];
    
    $user = getUser($chat_id);
    if (!$user['is_admin']) {
        sendMessage($chat_id, "⛔ Admin access required.");
        return;
    }
    
    switch ($data) {
        case 'admin_users':
            $users = loadData(USERS_FILE);
            $active_users = array_filter($users, fn($u) => !$u['is_blocked'] && $u['id'] != ADMIN_ID);
            $response = "👥 Active Users: " . count($active_users) . "\n\n";
            foreach ($active_users as $id => $user) {
                $response .= "🆔 $id | 👤 " . ($user['first_name'] ?? '') . " @" . ($user['username'] ?? '') . "\n";
            }
            sendMessage($chat_id, $response);
            break;
            
        case 'admin_block':
            sendMessage($chat_id, "Send the user ID to block (e.g., 'block 12345')");
            break;
            
        case 'admin_unblock':
            sendMessage($chat_id, "Send the user ID to unblock (e.g., 'unblock 12345')");
            break;
            
        case 'admin_broadcast':
            sendMessage($chat_id, "Type your broadcast message (it will be sent to all users):");
            break;
            
        case 'admin_stats':
            $users = loadData(USERS_FILE);
            $conversations = loadData(CONVERSATIONS_FILE);
            $stats = "📊 Bot Statistics\n\n";
            $stats .= "👥 Total Users: " . count($users) . "\n";
            $stats .= "📩 Active Conversations: " . count($conversations) . "\n";
            $stats .= "⛔ Blocked Users: " . count(array_filter($users, fn($u) => $u['is_blocked'])) . "\n";
            sendMessage($chat_id, $stats);
            break;
            
        default:
            sendMessage($chat_id, "Unknown command: $data");
    }
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
