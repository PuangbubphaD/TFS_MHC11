<?php
class NotificationService {
    /**
     * Send Markdown message to a Telegram Chat (individual or group) via Bot API
     * 
     * @param string $message
     * @param string $botToken
     * @param string $chatId
     * @return string|false
     */
    public static function sendToTelegram($message, $botToken, $chatId) {
        if (empty($botToken) || empty($chatId) || empty($message)) {
            return false;
        }
        $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
        
        // Clean up markdown formatting style to match Telegram's expectations if needed
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    /**
     * Send plain text or markdown message to a Discord channel via Webhook URL
     * 
     * @param string $message
     * @param string $webhookUrl
     * @return string|false
     */
    public static function sendToDiscord($message, $webhookUrl) {
        if (empty($webhookUrl) || empty($message)) {
            return false;
        }
        $data = json_encode([
            'content' => $message
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
