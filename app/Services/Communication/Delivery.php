<?php

namespace App\Services\Communication;

use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Outbound delivery for parent messages and announcements.
 *
 * Channels:
 *  - portal: always recorded (visible in the school portal / future parent app)
 *  - email:  sent through the application mailer (config/mail.php)
 *  - sms:    only when the school's SMS gateway is enabled with an API key.
 *            No SMS provider is integrated yet, so SMS deliveries are recorded
 *            as "queued" for a provider worker — never reported as "sent".
 */
class Delivery
{
    public const CHANNELS = ['portal', 'email', 'sms'];

    /**
     * @return array<string, array{available: bool, label: string, note: ?string}>
     */
    public static function channels(School $school): array
    {
        $settings = SystemSetting::query()
            ->where('school_id', $school->id)
            ->whereIn('key', ['sms_enabled', 'sms_api_key', 'sms_provider'])
            ->pluck('value', 'key');

        $smsReady = ($settings['sms_enabled'] ?? '0') === '1' && ! empty($settings['sms_api_key']);
        $mailer = (string) config('mail.default');

        return [
            'portal' => [
                'available' => true,
                'label' => 'Portal',
                'note' => 'Saved to the school record',
            ],
            'email' => [
                'available' => true,
                'label' => 'Email',
                'note' => in_array($mailer, ['log', 'array'], true)
                    ? 'Mail is in development mode — emails are written to the server log'
                    : null,
            ],
            'sms' => [
                'available' => $smsReady,
                'label' => 'SMS',
                'note' => $smsReady
                    ? 'Queued for the SMS gateway ('.($settings['sms_provider'] ?? 'provider').')'
                    : 'SMS gateway not connected — set it up in Administration › SMS gateway',
            ],
        ];
    }

    /**
     * Keep only known channels; portal is always included.
     *
     * @param  mixed  $channels
     * @return list<string>
     */
    public static function normalize(mixed $channels): array
    {
        $list = is_array($channels) ? $channels : [];
        $list = array_values(array_unique(array_filter(
            $list,
            fn ($c) => is_string($c) && in_array($c, self::CHANNELS, true)
        )));

        return array_values(array_unique(array_merge(['portal'], $list)));
    }

    /**
     * @return array{status: string, error: ?string}
     */
    public static function email(
        School $school,
        ?int $userId,
        string $to,
        string $subject,
        string $body,
        string $sourceType,
        int $sourceId,
    ): array {
        $status = 'sent';
        $error = null;

        try {
            $text = $body."\n\n—\n".$school->name."\nSent via Shengena school portal";
            Mail::raw($text, function ($m) use ($to, $subject, $school) {
                $m->to($to)
                    ->from((string) config('mail.from.address'), $school->name)
                    ->subject('['.$school->name.'] '.$subject);
            });
        } catch (Throwable $e) {
            $status = 'failed';
            $error = substr($e->getMessage(), 0, 250);
        }

        self::log($school, $userId, 'email', $to, $subject, $body, $status, $error, $sourceType, $sourceId);

        return ['status' => $status, 'error' => $error];
    }

    /**
     * @return array{status: string, error: ?string}
     */
    public static function sms(
        School $school,
        ?int $userId,
        string $to,
        string $title,
        string $body,
        string $sourceType,
        int $sourceId,
    ): array {
        // Gateway configured → queue for the provider worker; never claim "sent".
        self::log($school, $userId, 'sms', $to, $title, $body, 'queued', null, $sourceType, $sourceId);

        return ['status' => 'queued', 'error' => null];
    }

    protected static function log(
        School $school,
        ?int $userId,
        string $channel,
        string $recipient,
        string $title,
        string $body,
        string $status,
        ?string $error,
        string $sourceType,
        int $sourceId,
    ): void {
        try {
            NotificationLog::query()->create([
                'school_id' => $school->id,
                'user_id' => $userId,
                'channel' => $channel,
                'recipient' => substr($recipient, 0, 190),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'title' => substr($title, 0, 250),
                'body' => $body,
                'status' => $status,
                'error' => $error,
                'sent_at' => now(),
            ]);
        } catch (Throwable) {
            // logging must never break delivery
        }
    }
}
