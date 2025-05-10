<?php

namespace App\Services;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\BellHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class MqttService
{
    protected MqttClient $client;
    protected array $config;
    protected bool $isConnected = false;
    protected int $reconnectAttempts = 0;
    protected bool $connectionLock = false;
    protected array $subscriptions = [];
    protected const MAX_RECONNECT_ATTEMPTS = 5;
    protected const RECONNECT_DELAY = 5;

    // Constants for announcement topics
    protected const TOPIC_ANNOUNCEMENT_CONTROL = 'control/relay';
    protected const TOPIC_ANNOUNCEMENT_TTS = 'tts/play';
    protected const TOPIC_ANNOUNCEMENT_STATUS = 'announcement/status';
    protected const TOPIC_ANNOUNCEMENT_RESPONSE = 'announcement/response';

    public function __construct()
    {
        $this->config = config('mqtt');
        $this->initializeConnection();
    }

    protected function initializeConnection(): void
    {
        try {
            $connectionConfig = $this->getConnectionConfig();
            $this->client = new MqttClient(
                $connectionConfig['host'],
                $connectionConfig['port'],
                $this->generateClientId($connectionConfig)
            );

            $this->connect();
            $this->subscribeToBellTopics();
            $this->subscribeToAnnouncementTopics(); // Add announcement subscriptions
        } catch (\Exception $e) {
            Log::error('MQTT Initialization failed: ' . $e->getMessage());
            $this->scheduleReconnect();
        }
    }

    // In MqttService
    public function getConnectionStatus(): array
    {
        return [
            'connected' => $this->isConnected,
            'last_attempt' => Cache::get('mqtt_last_attempt'),
            'queued_messages' => count(Cache::get('mqtt_message_queue', []))
        ];
    }

    protected function getConnectionConfig(): array
    {
        return $this->config['connections'][$this->config['default_connection']];
    }

    protected function generateClientId(array $config): string
    {
        return $config['client_id'] . '_' . uniqid();
    }

    protected function subscribeToBellTopics(): void
    {
        $topics = [
            'bell_schedule' => fn($t, $m) => $this->handleBellNotification($m, 'bell_schedule'),
            'bell_manual' => fn($t, $m) => $this->handleBellNotification($m, 'bell_manual')
        ];

        foreach ($topics as $type => $callback) {
            $this->subscribe($this->config['topics']['events'][$type], $callback);
        }
    }

    /**
     * Subscribe to announcement-related topics
     */
    protected function subscribeToAnnouncementTopics(): void
    {
        $topics = [
            self::TOPIC_ANNOUNCEMENT_STATUS => fn($t, $m) => $this->handleAnnouncementStatus($m),
            self::TOPIC_ANNOUNCEMENT_RESPONSE => fn($t, $m) => $this->handleAnnouncementResponse($m)
        ];

        foreach ($topics as $topic => $callback) {
            $this->subscribe($topic, $callback);
        }
    }

    /**
     * Handle announcement status updates from devices
     */
    protected function handleAnnouncementStatus(string $message): void
    {
        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            
            Log::info('Announcement status update', [
                'status' => $data['status'] ?? 'unknown',
                'ruang' => $data['ruang'] ?? null,
                'mode' => $data['mode'] ?? null,
                'timestamp' => $data['timestamp'] ?? null
            ]);

            // Store status in cache for quick access
            if (isset($data['ruang'])) {
                Cache::put('announcement_status_'.$data['ruang'], $data['status'], 60);
            }

        } catch (\Exception $e) {
            Log::error('Failed to process announcement status', [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        }
    }

    /**
     * Handle announcement responses from devices
     */
    protected function handleAnnouncementResponse(string $message): void
    {
        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            
            Log::debug('Announcement response received', [
                'type' => $data['type'] ?? 'unknown',
                'status' => $data['status'] ?? null,
                'ruang' => $data['ruang'] ?? null,
                'message' => $data['message'] ?? null
            ]);

            // TODO: Add any specific response handling logic here

        } catch (\Exception $e) {
            Log::error('Failed to process announcement response', [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        }
    }

    public function sendRelayControl(string $action, array $ruangans, string $mode = 'manual'): bool
    {
        $payload = [
            'action' => $action, // 'activate' atau 'deactivate'
            'ruang' => $ruangans,
            'mode' => $mode,
            'timestamp' => now()->toDateTimeString()
        ];

        try {
            return $this->publish(self::TOPIC_ANNOUNCEMENT_CONTROL, json_encode($payload));
        } catch (\Exception $e) {
            Log::error('Failed to send relay control', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return false;
        }
    }

    /**
     * Mengirim pengumuman TTS
     */
    public function sendTTSAnnouncement(array $ruangans, string $message, string $language = 'id-id'): bool
    {
        $payload = [
            'ruang' => $ruangans,
            'teks' => $message,
            'hl' => $language,
            'timestamp' => now()->toDateTimeString()
        ];

        try {
            return $this->publish(self::TOPIC_ANNOUNCEMENT_TTS, json_encode($payload));
        } catch (\Exception $e) {
            Log::error('Failed to send TTS announcement', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return false;
        }
    }

    /**
     * Fungsi kompatibilitas backward (bisa dihapus setelah update controller)
     * @deprecated
     */
    public function sendAnnouncement(string $mode, array $ruangans, ?string $message = null): bool
    {
        if ($mode === 'tts' && $message) {
            return $this->sendTTSAnnouncement($ruangans, $message);
        }
        
        // Default action untuk mode manual
        return $this->sendRelayControl('activate', $ruangans, $mode);
    }


    /**
     * Get current announcement status for rooms
     */
    public function getAnnouncementStatus(array $ruanganIds): array
    {
        $statuses = [];
        
        foreach ($ruanganIds as $id) {
            $statuses[$id] = Cache::get('announcement_status_'.$id, 'unknown');
        }

        return $statuses;
    }

    protected function handleBellNotification(string $message, string $triggerType): void
    {
        Log::debug("Processing {$triggerType} bell event", compact('message', 'triggerType'));

        try {
            $data = $this->validateBellData($message, $triggerType);
            $history = $this->createBellHistory($data, $triggerType);
            
            $this->logBellEvent($history, $triggerType);

            Log::debug('Raw MQTT payload', [
                'message' => $message,
                'trigger_type' => $triggerType
            ]);
        } catch (\JsonException $e) {
            Log::error("Invalid JSON format in bell notification", [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        } catch (\InvalidArgumentException $e) {
            Log::error("Validation failed for bell event", [
                'error' => $e->getMessage(),
                'data' => $data ?? null
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to process bell notification", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'trigger_type' => $triggerType
            ]);
        }
    }

    protected function validateBellData(string $message, string $triggerType): array
    {
        $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        
        $rules = [
            'hari' => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu',
            'waktu' => 'required|date_format:H:i:s',
            'file_number' => 'required|string|size:4|regex:/^[0-9]{4}$/',
            'volume' => 'sometimes|integer|min:0|max:30',
            'repeat' => 'sometimes|integer|min:1|max:5'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Invalid bell data: ' . $validator->errors()->first()
            );
        }

        return $data;
    }

    protected function createBellHistory(array $data, string $triggerType): BellHistory
    {
        return BellHistory::create([
            'hari' => $data['hari'],
            'waktu' => $this->normalizeTime($data['waktu']),
            'file_number' => $data['file_number'],
            'trigger_type' => $triggerType === 'bell_schedule' ? 'schedule' : 'manual',
            'volume' => $data['volume'] ?? 15,
            'repeat' => $data['repeat'] ?? 1,
            'ring_time' => now()
        ]);
    }

    protected function logBellEvent(BellHistory $history, string $triggerType): void
    {
        Log::info("Bell event saved successfully", [
            'id' => $history->id,
            'type' => $triggerType,
            'hari' => $history->hari,
            'waktu' => $history->waktu,
            'file' => $history->file_number
        ]);
    }

    // protected function dispatchBellEvent(BellHistory $history): void
    // {
    //     event(new BellRingEvent([
    //         'id' => $history->id,
    //         'hari' => $history->hari,
    //         'waktu' => $history->waktu,
    //         'file_number' => $history->file_number,
    //         'trigger_type' => $history->trigger_type,
    //         'ring_time' => $history->ring_time->toDateTimeString()
    //     ]));
    // }

    private function normalizeTime(string $time): string
    {
        try {
            return Carbon::createFromFormat('H:i:s', $time)->format('H:i:s');
        } catch (\Exception $e) {
            $parts = explode(':', $time);
            return sprintf("%02d:%02d:%02d", $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0);
        }
    }

    public function connect(): bool
    {
        if ($this->connectionLock) {
            return false;
        }

        $this->connectionLock = true;

        try {
            if ($this->isConnected) {
                $this->connectionLock = false;
                return true;
            }

            $connectionSettings = $this->createConnectionSettings();
            $this->client->connect($connectionSettings, true);
            
            $this->handleSuccessfulConnection();
            return true;
        } catch (\Exception $e) {
            $this->handleConnectionFailure($e);
            return false;
        } finally {
            $this->connectionLock = false;
        }
    }

    protected function createConnectionSettings(): ConnectionSettings
    {
        $connectionConfig = $this->getConnectionConfig()['connection_settings'];
        $lastWill = $connectionConfig['last_will'];

        return (new ConnectionSettings)
            ->setUsername($connectionConfig['username'] ?? null)
            ->setPassword($connectionConfig['password'] ?? null)
            ->setKeepAliveInterval($connectionConfig['keep_alive_interval'])
            ->setConnectTimeout($connectionConfig['connect_timeout'])
            ->setLastWillTopic($lastWill['topic'])
            ->setLastWillMessage($lastWill['message'])
            ->setLastWillQualityOfService($lastWill['quality_of_service'])
            ->setRetainLastWill($lastWill['retain'])
            ->setUseTls(false);
    }

    protected function handleSuccessfulConnection(): void
    {
        $this->isConnected = true;
        $this->reconnectAttempts = 0;
        $this->resubscribeToTopics();
        
        Cache::put('mqtt_status', 'connected', 60);
        Log::info('MQTT Connected successfully');
    }

    protected function resubscribeToTopics(): void
    {
        foreach ($this->subscriptions as $topic => $callback) {
            $this->client->subscribe($topic, $callback);
        }
    }

    protected function handleConnectionFailure(\Exception $e): void
    {
        Log::error('MQTT Connection failed: ' . $e->getMessage());
        $this->handleDisconnection();
    }

    public function subscribe(string $topic, callable $callback, int $qos = 0): bool
    {
        $this->subscriptions[$topic] = $callback;
        
        if (!$this->isConnected) {
            return false;
        }

        try {
            $this->client->subscribe($topic, $callback, $qos);
            Log::debug("MQTT Subscribed to {$topic}");
            return true;
        } catch (\Exception $e) {
            Log::error("MQTT Subscribe failed to {$topic}: " . $e->getMessage());
            return false;
        }
    }

    public function publish(string $topic, string $message, int $qos = 1, bool $retain = false): bool
    {
        if (!$this->isConnected && !$this->connect()) {
            $this->queueMessage($topic, $message, $qos, $retain);
            return false;
        }

        try {
            $this->client->publish($topic, $message, $qos, $retain);
            Log::debug("MQTT Published to {$topic}");
            return true;
        } catch (\Exception $e) {
            $this->handlePublishFailure($e, $topic, $message, $qos, $retain);
            return false;
        }
    }

    protected function handlePublishFailure(\Exception $e, string $topic, string $message, int $qos, bool $retain): void
    {
        Log::error("MQTT Publish failed to {$topic}: " . $e->getMessage());
        $this->handleDisconnection();
        $this->queueMessage($topic, $message, $qos, $retain);
    }

    protected function queueMessage(string $topic, string $message, int $qos, bool $retain): void
    {
        $queue = Cache::get('mqtt_message_queue', []);
        $queue[] = compact('topic', 'message', 'qos', 'retain') + [
            'attempts' => 0,
            'timestamp' => now()->toDateTimeString(),
        ];
        Cache::put('mqtt_message_queue', $queue, 3600);
    }

    public function processMessageQueue(): void
    {
        if (!$this->isConnected) {
            return;
        }

        $queue = Cache::get('mqtt_message_queue', []);
        $remainingMessages = [];

        foreach ($queue as $message) {
            if ($message['attempts'] >= 3) {
                continue;
            }

            try {
                $this->publish(
                    $message['topic'],
                    $message['message'],
                    $message['qos'],
                    $message['retain']
                );
            } catch (\Exception $e) {
                $message['attempts']++;
                $remainingMessages[] = $message;
            }
        }

        Cache::put('mqtt_message_queue', $remainingMessages, 3600);
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    protected function handleDisconnection(): void
    {
        $this->isConnected = false;
        Cache::put('mqtt_status', 'disconnected', 60);
        $this->scheduleReconnect();
    }

    protected function scheduleReconnect(): void
    {
        $reconnectConfig = $this->getConnectionConfig()['connection_settings']['auto_reconnect'];
        
        if (!$reconnectConfig['enabled']) {
            return;
        }
        
        if ($this->reconnectAttempts < $reconnectConfig['max_reconnect_attempts']) {
            $this->reconnectAttempts++;
            $delay = $reconnectConfig['delay_between_reconnect_attempts'] * $this->reconnectAttempts;
            
            Log::info("MQTT Reconnect attempt {$this->reconnectAttempts} in {$delay} seconds");
            sleep($delay);
            $this->connect();
        } else {
            Log::error('MQTT Max reconnect attempts reached');
        }
    }

    public function __destruct()
    {
        if ($this->isConnected) {
            $this->client->disconnect();
        }
    }
}