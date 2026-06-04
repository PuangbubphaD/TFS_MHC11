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
            'parse_mode' => 'HTML'
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

    /**
     * Send message to a Discord User via DM (requires Bot Token)
     * 
     * @param string $message
     * @param string $botToken
     * @param string $userId
     * @return string|false JSON response from discord
     */
    public static function sendToDiscordDM($message, $botToken, $userId) {
        if (empty($botToken) || empty($userId) || empty($message)) {
            return false;
        }

        // Step 1: Create DM Channel
        $urlCreateDM = "https://discord.com/api/v10/users/@me/channels";
        $dataCreateDM = json_encode(['recipient_id' => $userId]);

        $ch1 = curl_init($urlCreateDM);
        curl_setopt($ch1, CURLOPT_POST, true);
        curl_setopt($ch1, CURLOPT_POSTFIELDS, $dataCreateDM);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
        $resCreateDM = curl_exec($ch1);
        curl_close($ch1);

        $dmData = json_decode($resCreateDM, true);
        if (!$dmData || !isset($dmData['id'])) {
            return json_encode(['message' => 'Failed to open DM channel with user']);
        }

        $channelId = $dmData['id'];

        // Step 2: Send Message to that DM Channel
        $urlSendMsg = "https://discord.com/api/v10/channels/" . $channelId . "/messages";
        $dataSendMsg = json_encode(['content' => $message]);

        $ch2 = curl_init($urlSendMsg);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, $dataSendMsg);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch2);
        curl_close($ch2);

        return $response;
    }
}
