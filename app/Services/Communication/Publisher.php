<?php

namespace App\Services\Communication;

use App\Models\Announcement;
use App\Models\School;

/**
 * Publishes announcements / notices and runs their deliveries.
 *
 * Notices only appear on the notice board. Announcements are also delivered to
 * their audience on the selected channels. Email / SMS run after the HTTP
 * response so publishing stays fast; the delivery summary is updated when done.
 */
class Publisher
{
    public static function publish(Announcement $a, ?int $userId): Announcement
    {
        $school = School::query()->findOrFail($a->school_id);
        $contacts = Audience::resolve((int) $a->school_id, (string) $a->audience, $a->classroom_ids ?? []);
        $summary = Audience::summarize($contacts);
        $channels = Delivery::normalize($a->channels);

        $delivery = [
            'audience' => $summary,
            'portal' => ['recorded' => $summary['total']],
        ];

        $sendEmail = $a->kind === 'announcement' && in_array('email', $channels, true);
        $sendSms = $a->kind === 'announcement' && in_array('sms', $channels, true)
            && Delivery::channels($school)['sms']['available'];

        if ($sendEmail) {
            $delivery['email'] = ['pending' => $summary['with_email'], 'sent' => 0, 'failed' => 0, 'no_address' => $summary['total'] - $summary['with_email']];
        }
        if (in_array('sms', $channels, true)) {
            $delivery['sms'] = $sendSms
                ? ['queued' => 0, 'pending' => $summary['with_phone'], 'no_number' => $summary['total'] - $summary['with_phone']]
                : ['status' => 'not_connected'];
        }

        $a->fill([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $userId,
            'recipients_count' => $a->kind === 'announcement' ? $summary['total'] : 0,
            'delivery_summary' => $delivery,
            'channels' => $channels,
        ])->save();

        if ($sendEmail || $sendSms) {
            $id = (int) $a->id;
            dispatch(function () use ($id, $contacts, $sendEmail, $sendSms, $userId) {
                self::deliver($id, $contacts, $sendEmail, $sendSms, $userId);
            })->afterResponse();
        }

        return $a;
    }

    /**
     * @param  list<array{type: string, id: int, name: string, email: ?string, phone: ?string}>  $contacts
     */
    public static function deliver(int $announcementId, array $contacts, bool $sendEmail, bool $sendSms, ?int $userId): void
    {
        $a = Announcement::query()->find($announcementId);
        if (! $a) {
            return;
        }
        $school = School::query()->find($a->school_id);
        if (! $school) {
            return;
        }

        $summary = $a->delivery_summary ?? [];
        $emailSent = 0;
        $emailFailed = 0;
        $smsQueued = 0;

        foreach ($contacts as $c) {
            if ($sendEmail && $c['email']) {
                $r = Delivery::email($school, $userId, $c['email'], $a->title, $a->body, 'announcement', $a->id);
                $r['status'] === 'sent' ? $emailSent++ : $emailFailed++;
            }
            if ($sendSms && $c['phone']) {
                Delivery::sms($school, $userId, $c['phone'], $a->title, $a->title."\n".$a->body, 'announcement', $a->id);
                $smsQueued++;
            }
        }

        if ($sendEmail) {
            $summary['email'] = array_merge($summary['email'] ?? [], ['pending' => 0, 'sent' => $emailSent, 'failed' => $emailFailed]);
        }
        if ($sendSms) {
            $summary['sms'] = array_merge($summary['sms'] ?? [], ['pending' => 0, 'queued' => $smsQueued]);
        }
        $a->delivery_summary = $summary;
        $a->save();
    }

    /**
     * Publish scheduled items whose time has come (scheduler + lazy safety net).
     */
    public static function publishDue(?int $schoolId = null): int
    {
        $due = Announcement::query()
            ->where('status', 'scheduled')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->limit(50)
            ->get();

        foreach ($due as $a) {
            self::publish($a, $a->created_by);
        }

        return $due->count();
    }
}
