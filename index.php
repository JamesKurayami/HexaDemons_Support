<?php
require_once __DIR__ . '/vendor/autoload.php';

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Update;

// Load configuration
$bot_api_key  = getenv('TELEGRAM_BOT_TOKEN') ?: '8149005317:AAFTCtpaPKlUkpmcyhmIWQHVAbRxwn8dJWg';
$bot_username = getenv('TELEGRAM_BOT_USERNAME') ?: '@HexaDemons_bot';
$admin_id     = getenv('TELEGRAM_ADMIN_ID') ?: '1310095655';

// Initialize data files if not exists
if (!file_exists('users.json')) {
    file_put_contents('users.json', json_encode([
        'users' => [], 
        'chats' => [],
        'blocked' => [],
        'maintenance' => false
    ]));
    chmod('users.json', 0666);
}

try {
    // Create Telegram API object
    $telegram = new Telegram($bot_api_key, $bot_username);
    $telegram->handle();
    $update = $telegram->getUpdate();
    $message = $update->getMessage();
    $chat_id = $message->getChat()->getId();
    $text = $message->getText();

    // Load users data
    $users_data = json_decode(file_get_contents('users.json'), true);

    // Check if user is blocked
    if (in_array($chat_id, $users_data['blocked'])) {
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => "🚫 You are blocked from using this bot.",
        ]);
        exit;
    }

    // Check maintenance mode for non-admin users
    if ($users_data['maintenance'] && $chat_id != $admin_id) {
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => "🔧 The bot is currently under maintenance. Please try again later.",
        ]);
        exit;
    }

    // Handle /start command
    if (strpos($text, '/start') === 0) {
        $response_text = "👋 Hello! I'm a middleman bot. Send me a message and I'll forward it to the admin.";
        
        if (!isset($users_data['users'][$chat_id])) {
            $users_data['users'][$chat_id] = [
                'username' => $message->getChat()->getUsername(),
                'first_name' => $message->getChat()->getFirstName(),
                'last_name' => $message->getChat()->getLastName(),
                'chat_id' => $chat_id,
                'joined_at' => date('Y-m-d H:i:s')
            ];
            file_put_contents('users.json', json_encode($users_data));
        }
        
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => $response_text,
        ]);
    }
    
    // Handle admin commands (only if sender is admin)
    elseif ($chat_id == $admin_id) {
        // Maintenance commands
        if (strpos($text, '/maintenance') === 0) {
            $parts = explode(' ', $text);
            if (count($parts) > 1) {
                if ($parts[1] == 'on') {
                    $users_data['maintenance'] = true;
                    $response = "🛠 Maintenance mode ENABLED";
                } elseif ($parts[1] == 'off') {
                    $users_data['maintenance'] = false;
                    $response = "✅ Maintenance mode DISABLED";
                }
                file_put_contents('users.json', json_encode($users_data));
                Request::sendMessage([
                    'chat_id' => $admin_id,
                    'text'    => $response,
                ]);
            }
        }
        // Broadcast command
        elseif (strpos($text, '/broadcast') === 0) {
            $message_to_send = trim(substr($text, strlen('/broadcast')));
            if (!empty($message_to_send)) {
                $success = 0;
                $failed = 0;
                foreach ($users_data['users'] as $user) {
                    if (!in_array($user['chat_id'], $users_data['blocked'])) {
                        try {
                            Request::sendMessage([
                                'chat_id' => $user['chat_id'],
                                'text'    => "📢 Broadcast:\n" . $message_to_send,
                            ]);
                            $success++;
                        } catch (Exception $e) {
                            $failed++;
                        }
                    }
                }
                Request::sendMessage([
                    'chat_id' => $admin_id,
                    'text'    => "📢 Broadcast sent!\nSuccess: $success\nFailed: $failed",
                ]);
            }
        }
        // Info command
        elseif ($text == '/info') {
            $total_users = count($users_data['users']);
            $active_users = 0; // Would need tracking for actual active users
            $blocked_users = count($users_data['blocked']);
            $maintenance_status = $users_data['maintenance'] ? 'ON' : 'OFF';
            
            $response = "📊 Bot Statistics:\n";
            $response .= "👥 Total users: $total_users\n";
            $response .= "🚫 Blocked users: $blocked_users\n";
            $response .= "🛠 Maintenance: $maintenance_status\n";
            $response .= "📅 Last update: " . date('Y-m-d H:i:s');
            
            Request::sendMessage([
                'chat_id' => $admin_id,
                'text'    => $response,
            ]);
        }
        // Block/unblock commands
        elseif (strpos($text, '/block') === 0 || strpos($text, '/unblock') === 0) {
            $parts = explode(' ', $text);
            if (count($parts) > 1 && is_numeric($parts[1])) {
                $target_id = $parts[1];
                $action = strpos($text, '/block') === 0 ? 'block' : 'unblock';
                
                if ($action == 'block') {
                    if (!in_array($target_id, $users_data['blocked'])) {
                        $users_data['blocked'][] = $target_id;
                        $response = "🚫 User $target_id has been blocked.";
                    } else {
                        $response = "ℹ️ User $target_id is already blocked.";
                    }
                } else {
                    if (($key = array_search($target_id, $users_data['blocked'])) !== false) {
                        unset($users_data['blocked'][$key]);
                        $users_data['blocked'] = array_values($users_data['blocked']);
                        $response = "✅ User $target_id has been unblocked.";
                    } else {
                        $response = "ℹ️ User $target_id is not blocked.";
                    }
                }
                
                file_put_contents('users.json', json_encode($users_data));
                Request::sendMessage([
                    'chat_id' => $admin_id,
                    'text'    => $response,
                ]);
            }
        }
        // Check if this is a reply to a forwarded message
        elseif ($message->getReplyToMessage()) {
            $reply_to = $message->getReplyToMessage();
            $original_sender = null;
            
            foreach ($users_data['chats'] as $original_chat_id => $admin_chat_id) {
                if ($admin_chat_id == $reply_to->getMessageId()) {
                    $original_sender = $original_chat_id;
                    break;
                }
            }
            
            if ($original_sender) {
                Request::sendMessage([
                    'chat_id' => $original_sender,
                    'text'    => "💬 Admin reply:\n" . $text,
                ]);
                
                Request::sendMessage([
                    'chat_id' => $admin_id,
                    'text'    => "✅ Your reply has been sent to the user.",
                ]);
            }
        }
    }
    
    // Handle regular user messages
    else {
        if (!isset($users_data['users'][$chat_id])) {
            $users_data['users'][$chat_id] = [
                'username' => $message->getChat()->getUsername(),
                'first_name' => $message->getChat()->getFirstName(),
                'last_name' => $message->getChat()->getLastName(),
                'chat_id' => $chat_id,
                'joined_at' => date('Y-m-d H:i:s')
            ];
        }
        
        $user_info = $users_data['users'][$chat_id];
        $user_str = "👤 User: " . $user_info['first_name'] . " " . ($user_info['last_name'] ?? '');
        if (!empty($user_info['username'])) {
            $user_str .= " (@{$user_info['username']})";
        }
        
        $result = Request::sendMessage([
            'chat_id' => $admin_id,
            'text'    => "📩 New message from:\n{$user_str}\n\n{$text}",
        ]);
        
        if ($result->isOk()) {
            $forwarded_message_id = $result->getResult()->getMessageId();
            $users_data['chats'][$chat_id] = $forwarded_message_id;
            file_put_contents('users.json', json_encode($users_data));
        }
        
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => "✅ Your message has been forwarded to the admin.",
        ]);
    }

} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
}
