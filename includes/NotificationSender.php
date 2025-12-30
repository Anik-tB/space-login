<?php
/**
 * Notification Sender Service
 * This class handles actual sending of SMS, Email, and other notifications
 *
 * TO ENABLE REAL NOTIFICATIONS:
 * 1. Configure SMS service (Twilio, Nexmo, etc.)
 * 2. Configure SMTP for emails
 * 3. Update the send methods below with your API credentials
 */

class NotificationSender {
    private $smsApiKey = null;
    private $smsApiSecret = null;
    private $smsFromNumber = null;
    private $smtpHost = null;
    private $smtpPort = 587;
    private $smtpUser = null;
    private $smtpPass = null;
    private $smtpFrom = null;
    private $smtpFromName = 'SafeSpace Emergency';
    private $config = null;

    public function __construct() {
        // Load configuration from JSON file
        $configFile = __DIR__ . '/../notification_config.json';
        if (file_exists($configFile)) {
            $this->config = json_decode(file_get_contents($configFile), true);

            // Load SMS config
            if (isset($this->config['sms']) && $this->config['sms']['enabled']) {
                $this->smsApiKey = $this->config['sms']['api_key'] ?? null;
                $this->smsApiSecret = $this->config['sms']['api_secret'] ?? null;
                $this->smsFromNumber = $this->config['sms']['from_number'] ?? null;
            }

            // Load Email config
            if (isset($this->config['email']) && $this->config['email']['enabled']) {
                $this->smtpHost = $this->config['email']['smtp_host'] ?? null;
                $this->smtpPort = $this->config['email']['smtp_port'] ?? 587;
                $this->smtpUser = $this->config['email']['smtp_user'] ?? null;
                $this->smtpPass = $this->config['email']['smtp_pass'] ?? null;
                $this->smtpFrom = $this->config['email']['from_email'] ?? null;
                $this->smtpFromName = $this->config['email']['from_name'] ?? 'SafeSpace Emergency';
            }
        }

        // Fallback to environment variables
        if (!$this->smsApiKey) {
            $this->smsApiKey = getenv('TWILIO_API_KEY') ?: getenv('SMS_API_KEY');
        }
        if (!$this->smtpHost) {
            $this->smtpHost = getenv('SMTP_HOST');
        }
    }

    /**
     * Send SMS notification
     * @param string $to Phone number
     * @param string $message Message content
     * @return array ['success' => bool, 'message_id' => string, 'error' => string]
     */
    public function sendSMS($to, $message) {
        // Check if SMS is enabled
        if (!isset($this->config['sms']['enabled']) || !$this->config['sms']['enabled']) {
            error_log("SMS not enabled in config. Would send to: $to");
            return ['success' => false, 'error' => 'SMS service not enabled in configuration'];
        }

        if (!$this->smsApiKey || !$this->smsApiSecret || !$this->smsFromNumber) {
            error_log("SMS credentials missing. Would send to: $to");
            return ['success' => false, 'error' => 'SMS service not configured - missing API credentials'];
        }

        $provider = $this->config['sms']['provider'] ?? 'twilio';

        try {
            if ($provider === 'twilio') {
                // Twilio implementation
                if (class_exists('\Twilio\Rest\Client')) {
                    $client = new \Twilio\Rest\Client($this->smsApiKey, $this->smsApiSecret);
                    $result = $client->messages->create(
                        $to,
                        [
                            'from' => $this->smsFromNumber,
                            'body' => $message
                        ]
                    );
                    return ['success' => true, 'message_id' => $result->sid];
                } else {
                    return ['success' => false, 'error' => 'Twilio SDK not installed. Run: composer require twilio/sdk'];
                }
            } elseif ($provider === 'custom') {
                // Custom SMS gateway via HTTP API
                $apiUrl = $this->config['sms']['api_url'] ?? '';
                if ($apiUrl) {
                    $response = $this->sendViaHTTP($apiUrl, [
                        'to' => $to,
                        'message' => $message,
                        'from' => $this->smsFromNumber
                    ]);
                    return $response;
                }
            }

            // Fallback: log for manual sending
            error_log("SMS would be sent to: $to\nMessage: $message");
            return ['success' => false, 'error' => 'SMS provider not properly configured'];
        } catch (Exception $e) {
            error_log("SMS sending error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendViaHTTP($url, $data) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'message_id' => uniqid()];
        }
        return ['success' => false, 'error' => "HTTP $httpCode: $response"];
    }

    /**
     * Send Email notification
     * @param string $to Email address
     * @param string $subject Email subject
     * @param string $message Email body
     * @return array ['success' => bool, 'message_id' => string, 'error' => string]
     */
    public function sendEmail($to, $subject, $message) {
        // Check if Email is enabled
        if (!isset($this->config['email']['enabled']) || !$this->config['email']['enabled']) {
            error_log("Email not enabled in config. Would send to: $to");
            return ['success' => false, 'error' => 'Email service not enabled in configuration'];
        }

        if (!$this->smtpHost || !$this->smtpUser || !$this->smtpFrom) {
            error_log("Email credentials missing. Would send to: $to");
            return ['success' => false, 'error' => 'Email service not configured - missing SMTP credentials'];
        }

        try {
            // Try PHPMailer first
            if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $this->smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $this->smtpUser;
                $mail->Password = $this->smtpPass;
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $this->smtpPort;
                $mail->setFrom($this->smtpFrom, $this->smtpFromName);
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body = $message;
                $mail->isHTML(false);
                $mail->send();
                return ['success' => true, 'message_id' => $mail->getLastMessageID()];
            } else {
                // Fallback to PHP mail() function
                $headers = "From: {$this->smtpFromName} <{$this->smtpFrom}>\r\n";
                $headers .= "Reply-To: {$this->smtpFrom}\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                if (mail($to, $subject, $message, $headers)) {
                    return ['success' => true, 'message_id' => uniqid()];
                } else {
                    return ['success' => false, 'error' => 'PHP mail() function failed'];
                }
            }
        } catch (Exception $e) {
            error_log("Email sending error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Make phone call (for emergency)
     * @param string $to Phone number
     * @param string $message Message to speak
     * @return array ['success' => bool, 'call_id' => string, 'error' => string]
     */
    public function makeCall($to, $message) {
        // Check if Call is enabled
        if (!isset($this->config['call']['enabled']) || !$this->config['call']['enabled']) {
            error_log("Call not enabled in config. Would call: $to");
            return ['success' => false, 'error' => 'Call service not enabled in configuration'];
        }

        if (!$this->smsApiKey || !$this->smsApiSecret || !$this->smsFromNumber) {
            error_log("Call credentials missing. Would call: $to");
            return ['success' => false, 'error' => 'Call service not configured - missing API credentials'];
        }

        try {
            if (class_exists('\Twilio\Rest\Client')) {
                $client = new \Twilio\Rest\Client($this->smsApiKey, $this->smsApiSecret);

                // Create TwiML for emergency message
                $twimlUrl = $this->config['call']['twiml_url'] ?? null;
                if (!$twimlUrl) {
                    // Use default TwiML generator
                    $twimlUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
                               $_SERVER['HTTP_HOST'] .
                               dirname($_SERVER['PHP_SELF']) .
                               '/api/twiml_emergency.php?message=' . urlencode($message);
                }

                $call = $client->calls->create(
                    $to,
                    $this->smsFromNumber,
                    [
                        'url' => $twimlUrl,
                        'method' => 'GET'
                    ]
                );
                return ['success' => true, 'call_id' => $call->sid];
            } else {
                return ['success' => false, 'error' => 'Twilio SDK not installed. Run: composer require twilio/sdk'];
            }
        } catch (Exception $e) {
            error_log("Call error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send WhatsApp message (if supported)
     * @param string $to Phone number
     * @param string $message Message content
     * @return array ['success' => bool, 'message_id' => string, 'error' => string]
     */
    public function sendWhatsApp($to, $message) {
        // TODO: Implement WhatsApp sending (Twilio WhatsApp API, etc.)
        error_log("WhatsApp would be sent to: $to\nMessage: $message");
        return ['success' => false, 'error' => 'WhatsApp service not configured'];
    }
}

