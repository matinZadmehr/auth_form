<?php
/**
 * Webhook Handler for Telegram Authentication Form
 * Sends data to n8n webhook
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

// Log received data (for debugging)
logData('Received from form:', $data);

// CONFIGURATION - SET YOUR N8N WEBHOOK URL HERE
$n8n_webhook_url = 'https://your-n8n-domain.com/webhook/auth-form'; // ← CHANGE THIS!

// Validate webhook URL
if (empty($n8n_webhook_url) || strpos($n8n_webhook_url, 'your-n8n-domain') !== false) {
    $response = [
        'success' => false,
        'error' => 'N8N webhook URL not configured',
        'received_data' => $data
    ];
    echo json_encode($response);
    exit();
}

// Prepare data for n8n
$payload = prepareN8NPayload($data);

// Send to n8n webhook
$result = sendToN8N($n8n_webhook_url, $payload);

// Return response
if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Data sent to n8n successfully',
        'n8n_response' => $result['response'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send to n8n',
        'n8n_error' => $result['error'],
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Prepare payload for n8n
 */
function prepareN8NPayload($data) {
    $payload = [
        'event_type' => 'telegram_auth_form',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time(),
        'source' => 'telegram_web_app'
    ];
    
    // Add level information
    if (isset($data['level'])) {
        $payload['auth_level'] = $data['level'];
        $payload['action'] = $data['action'] ?? 'unknown';
    }
    
    // Add form data
    if (isset($data['form_data'])) {
        $payload['user_data'] = $data['form_data'];
        
        // Add additional metadata
        $payload['user_data']['processed_at'] = date('Y-m-d H:i:s');
        $payload['user_data']['ip_address'] = getUserIP();
        $payload['user_data']['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
    
    // Add Telegram user info
    if (isset($data['telegram_user'])) {
        $payload['telegram_user'] = $data['telegram_user'];
    }
    
    // Handle selfie photo (Level 2)
    if (isset($data['selfie_photo']) && !empty($data['selfie_photo'])) {
        // For large images, we might want to handle differently
        $imageData = $data['selfie_photo'];
        
        // Check if it's a data URL
        if (strpos($imageData, 'data:image') === 0) {
            $payload['has_selfie'] = true;
            $payload['selfie_format'] = 'base64';
            
            // Extract base64 data
            $base64Data = $imageData;
            
            // Option 1: Send base64 directly (for smaller images)
            if (strlen($base64Data) < 1000000) { // Less than 1MB
                $payload['selfie_image'] = $base64Data;
            } 
            // Option 2: Save locally and send URL
            else {
                $filename = saveSelfieImage($base64Data);
                if ($filename) {
                    $payload['selfie_url'] = getCurrentUrl() . '/uploads/' . $filename;
                    $payload['selfie_saved'] = true;
                }
            }
            
            $payload['selfie_size'] = strlen($imageData);
        }
    }
    
    return $payload;
}

/**
 * Send data to n8n webhook
 */
function sendToN8N($url, $payload) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Telegram-Auth-Webhook/1.0'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the request
    logData('Sent to n8n:', [
        'url' => $url,
        'payload_size' => strlen(json_encode($payload)),
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ]);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    // Consider 2xx and 3xx status codes as success
    if ($httpCode >= 200 && $httpCode < 400) {
        return [
            'success' => true,
            'response' => json_decode($response, true) ?: $response,
            'http_code' => $httpCode
        ];
    }
    
    return [
        'success' => false,
        'error' => "HTTP $httpCode",
        'response' => $response
    ];
}

/**
 * Save selfie image locally (optional)
 */
function saveSelfieImage($base64Data) {
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/uploads';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Extract image data from base64
    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
        $imageType = $matches[1];
        $imageData = base64_decode(substr($base64Data, strpos($base64Data, ',') + 1));
        
        if ($imageData === false) {
            return false;
        }
        
        // Generate unique filename
        $filename = 'selfie_' . time() . '_' . uniqid() . '.' . $imageType;
        $filepath = $uploadDir . '/' . $filename;
        
        // Save file
        if (file_put_contents($filepath, $imageData)) {
            return $filename;
        }
    }
    
    return false;
}

/**
 * Get user IP address
 */
function getUserIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Check for forwarded IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    
    return $ip;
}

/**
 * Get current URL
 */
function getCurrentUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host;
}

/**
 * Log data for debugging
 */
function logData($message, $data) {
    $logFile = __DIR__ . '/webhook_log.txt';
    $logEntry = date('Y-m-d H:i:s') . " - $message\n";
    $logEntry .= print_r($data, true) . "\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Create a simple HTML test page if accessed via browser
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Webhook Test Page</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            .container { max-width: 800px; margin: 0 auto; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            .test-form { background: #f8f9fa; padding: 20px; border-radius: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Webhook Test Page</h1>
            
            <div class="status success">
                <strong>Status:</strong> Webhook is running
            </div>
            
            <div class="test-form">
                <h2>Test the Webhook</h2>
                <p>Use this form to test the webhook manually:</p>
                
                <form id="testForm">
                    <div>
                        <label>Level:</label>
                        <select id="level">
                            <option value="1">Level 1</option>
                            <option value="2">Level 2</option>
                        </select>
                    </div>
                    
                    <div>
                        <label>First Name:</label>
                        <input type="text" id="firstName" value="Test User">
                    </div>
                    
                    <div>
                        <label>Last Name:</label>
                        <input type="text" id="lastName" value="Test Last">
                    </div>
                    
                    <div>
                        <label>Phone:</label>
                        <input type="text" id="phone" value="09123456789">
                    </div>
                    
                    <button type="button" onclick="testWebhook()">Test Webhook</button>
                </form>
                
                <div id="testResult"></div>
            </div>
            
            <script>
                async function testWebhook() {
                    const data = {
                        action: 'authentication_level_' + document.getElementById('level').value,
                        level: parseInt(document.getElementById('level').value),
                        form_data: {
                            first_name: document.getElementById('firstName').value,
                            last_name: document.getElementById('lastName').value,
                            phone: document.getElementById('phone').value,
                            submission_time: new Date().toISOString()
                        },
                        telegram_user: {
                            telegram_id: 123456789,
                            telegram_username: 'testuser'
                        },
                        source: 'web_test'
                    };
                    
                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(data)
                        });
                        
                        const result = await response.json();
                        document.getElementById('testResult').innerHTML = 
                            '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
                    } catch (error) {
                        document.getElementById('testResult').innerHTML = 
                            'Error: ' + error.message;
                    }
                }
            </script>
        </div>
    </body>
    </html>
    <?php
    exit();
}
?>