<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\ParentMessage;
use App\Models\ParentThread;
use App\Models\StudentGuardian;
use App\Services\Communication\Audience;
use App\Services\Communication\Delivery;
use App\Services\Communication\Publisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Communication: parent messaging, announcements and the notice board.
 */
class CommunicationController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public const CATEGORIES = ['general', 'academic', 'exams', 'fees', 'events', 'sports', 'health', 'transport'];

    // ─── Overview & channels ──────────────────────────────────────

    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            Publisher::publishDue($schoolId);

            $threads = ParentThread::query()->where('school_id', $schoolId);
            $ann = Announcement::query()->where('school_id', $schoolId);

            return $this->success([
                'summary' => [
                    'open_threads' => (clone $threads)->where('status', 'open')->count(),
                    'unread_replies' => (int) (clone $threads)->sum('unread_count'),
                    'messages_sent_30d' => ParentMessage::query()
                        ->where('school_id', $schoolId)
                        ->where('direction', 'outbound')
                        ->where('sent_at', '>=', now()->subDays(30))
                        ->count(),
                    'announcements_published' => (clone $ann)->where('kind', 'announcement')->where('status', 'published')->count(),
                    'announcements_scheduled' => (clone $ann)->where('status', 'scheduled')->count(),
                    'announcements_draft' => (clone $ann)->where('status', 'draft')->count(),
                    'notices_active' => $this->boardQuery($schoolId)->count(),
                ],
                'channels' => Delivery::channels($this->school($request)),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function channels(Request $request)
    {
        try {
            return $this->success(['channels' => Delivery::channels($this->school($request))]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Delivery attempts for a channel (email / sms), newest first. */
    public function deliveryLog(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'channel' => ['required', Rule::in(['email', 'sms'])],
                'status' => ['nullable', 'string', 'max:20'],
            ]);
            $q = \App\Models\NotificationLog::query()
                ->where('school_id', $schoolId)
                ->where('channel', $data['channel'])
                ->orderByDesc('id');
            if (! empty($data['status'])) {
                $q->where('status', $data['status']);
            }
            $base = \App\Models\NotificationLog::query()->where('school_id', $schoolId)->where('channel', $data['channel']);

            return $this->success([
                'logs' => $q->limit(200)->get(['id', 'recipient', 'source_type', 'source_id', 'title', 'status', 'error', 'sent_at']),
                'counts' => (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Announcements & notices ──────────────────────────────────

    public function announcementsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            Publisher::publishDue($schoolId);

            $kind = $request->string('kind')->toString() ?: 'announcement';
            $q = Announcement::query()
                ->where('school_id', $schoolId)
                ->where('kind', $kind)
                ->with('author:id,name')
                ->orderByRaw("CASE status WHEN 'scheduled' THEN 0 WHEN 'draft' THEN 1 WHEN 'published' THEN 2 ELSE 3 END")
                ->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            if (($status = $request->string('status')->toString()) !== '') {
                $q->where('status', $status);
            }
            if (($category = $request->string('category')->toString()) !== '') {
                $q->where('category', $category);
            }
            if (($term = trim($request->string('q')->toString())) !== '') {
                $q->where(fn ($b) => $b->where('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%"));
            }

            $items = $q->limit(200)->get()->map(fn (Announcement $a) => $this->transformAnnouncement($a));

            return $this->success(['items' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function announcementsShow(Request $request, int $id)
    {
        try {
            $a = Announcement::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['author:id,name', 'publisher:id,name'])
                ->findOrFail($id);

            return $this->success(['item' => $this->transformAnnouncement($a, true)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function announcementsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $this->validateAnnouncement($request);
            $this->assertChannelsAvailable($request, $data['channels'] ?? []);

            $action = $data['action'] ?? 'draft'; // draft | publish | schedule
            unset($data['action']);

            $a = Announcement::query()->create(array_merge($data, [
                'school_id' => $schoolId,
                'channels' => Delivery::normalize($data['channels'] ?? []),
                'status' => 'draft',
                'created_by' => $request->user()?->id,
            ]));

            $a = $this->applyAction($a, $action, $request);
            $this->audit($request, $a->kind.'.created', $a->id, ['action' => $action]);

            return $this->success(['item' => $this->transformAnnouncement($a->fresh(['author']), true)], $this->actionMessage($a), 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function announcementsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $a = Announcement::query()->where('school_id', $schoolId)->findOrFail($id);

            // Published broadcasts have been delivered — only board fields may change.
            if ($a->status === 'published' && $a->kind === 'announcement') {
                $data = $request->validate([
                    'is_pinned' => ['sometimes', 'boolean'],
                    'show_on_board' => ['sometimes', 'boolean'],
                    'expires_at' => ['sometimes', 'nullable', 'date'],
                ]);
                $a->fill($data)->save();
            } else {
                $data = $this->validateAnnouncement($request, true);
                if (array_key_exists('channels', $data)) {
                    $this->assertChannelsAvailable($request, $data['channels'] ?? []);
                    $data['channels'] = Delivery::normalize($data['channels']);
                }
                $action = $data['action'] ?? null;
                unset($data['action']);
                $a->fill($data)->save();
                if ($action) {
                    $a = $this->applyAction($a, $action, $request);
                }
            }

            $this->audit($request, $a->kind.'.updated', $a->id, array_keys($data));

            return $this->success(['item' => $this->transformAnnouncement($a->fresh(['author', 'publisher']), true)], $this->actionMessage($a));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function announcementsPublish(Request $request, int $id)
    {
        try {
            $a = Announcement::query()->where('school_id', $this->schoolId($request))->findOrFail($id);
            if (! in_array($a->status, ['draft', 'scheduled'], true)) {
                return $this->error('Only drafts or scheduled items can be published.', 422);
            }
            $this->assertChannelsAvailable($request, $a->channels ?? []);
            $a = Publisher::publish($a, $request->user()?->id);
            $this->audit($request, $a->kind.'.published', $a->id, ['recipients' => $a->recipients_count]);

            return $this->success(['item' => $this->transformAnnouncement($a->fresh(['author', 'publisher']), true)], $this->actionMessage($a));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function announcementsArchive(Request $request, int $id)
    {
        try {
            $a = Announcement::query()->where('school_id', $this->schoolId($request))->findOrFail($id);
            $a->status = 'archived';
            $a->is_pinned = false;
            $a->save();
            $this->audit($request, $a->kind.'.archived', $a->id, []);

            return $this->success(['item' => $this->transformAnnouncement($a)], 'Archived');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function announcementsDestroy(Request $request, int $id)
    {
        try {
            $a = Announcement::query()->where('school_id', $this->schoolId($request))->findOrFail($id);
            if (! in_array($a->status, ['draft', 'scheduled'], true)) {
                return $this->error('Published items cannot be deleted — archive them instead.', 422);
            }
            $a->delete();
            $this->audit($request, $a->kind.'.deleted', $id, []);

            return $this->success(['id' => $id], 'Deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function audiencePreview(Request $request)
    {
        try {
            $data = $request->validate([
                'audience' => ['required', Rule::in(Audience::TYPES)],
                'classroom_ids' => ['nullable', 'array'],
                'classroom_ids.*' => ['integer'],
            ]);
            $contacts = Audience::resolve($this->schoolId($request), $data['audience'], $data['classroom_ids'] ?? []);

            return $this->success(['summary' => Audience::summarize($contacts)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Live notice board: published notices + announcements flagged for the board, not expired. */
    public function noticeBoard(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            Publisher::publishDue($schoolId);

            $q = $this->boardQuery($schoolId)->with('author:id,name');
            if (($category = $request->string('category')->toString()) !== '') {
                $q->where('category', $category);
            }
            if (($term = trim($request->string('q')->toString())) !== '') {
                $q->where(fn ($b) => $b->where('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%"));
            }

            $items = $q->orderByDesc('is_pinned')
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'important' THEN 1 ELSE 2 END")
                ->orderByDesc('published_at')
                ->limit(100)
                ->get()
                ->map(fn (Announcement $a) => $this->transformAnnouncement($a));

            return $this->success(['items' => $items, 'categories' => self::CATEGORIES]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── Parent messaging ─────────────────────────────────────────

    public function recipients(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $term = trim($request->string('q')->toString());

            $q = StudentGuardian::query()
                ->where('school_id', $schoolId)
                ->with(['student:id,first_name,middle_name,last_name,admission_number,status', 'student.currentEnrollment.classroom:id,name'])
                ->orderBy('full_name')
                ->limit(25);

            if ($term !== '') {
                $q->where(function ($b) use ($term) {
                    $b->where('full_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhereHas('student', fn ($s) => $s
                            ->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('admission_number', 'like', "%{$term}%"));
                });
            }

            return $this->success([
                'recipients' => $q->get()->map(fn (StudentGuardian $g) => $this->transformGuardian($g))->values(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function threadsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = ParentThread::query()
                ->where('school_id', $schoolId)
                ->with(['guardian', 'student:id,first_name,middle_name,last_name,admission_number', 'student.currentEnrollment.classroom:id,name'])
                ->orderByDesc('last_message_at')
                ->orderByDesc('id');

            $status = $request->string('status')->toString();
            if ($status === 'unread') {
                $q->where('unread_count', '>', 0);
            } elseif (in_array($status, ['open', 'closed'], true)) {
                $q->where('status', $status);
            }
            if (($term = trim($request->string('q')->toString())) !== '') {
                $q->where(function ($b) use ($term) {
                    $b->where('subject', 'like', "%{$term}%")
                        ->orWhere('last_message_preview', 'like', "%{$term}%")
                        ->orWhereHas('guardian', fn ($g) => $g->where('full_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                        ->orWhereHas('student', fn ($s) => $s->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%")->orWhere('admission_number', 'like', "%{$term}%"));
                });
            }

            $threads = $q->limit(150)->get()->map(fn (ParentThread $t) => $this->transformThread($t));

            return $this->success([
                'threads' => $threads,
                'counts' => [
                    'open' => ParentThread::query()->where('school_id', $schoolId)->where('status', 'open')->count(),
                    'unread' => ParentThread::query()->where('school_id', $schoolId)->where('unread_count', '>', 0)->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function threadsShow(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $t = ParentThread::query()
                ->where('school_id', $schoolId)
                ->with(['guardian', 'student:id,first_name,middle_name,last_name,admission_number', 'student.currentEnrollment.classroom:id,name', 'messages.sender:id,name'])
                ->findOrFail($id);

            // Opening the thread marks inbound replies as read
            if ($t->unread_count > 0) {
                ParentMessage::query()
                    ->where('thread_id', $t->id)
                    ->where('direction', 'inbound')
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
                $t->unread_count = 0;
                $t->save();
            }

            return $this->success([
                'thread' => $this->transformThread($t),
                'messages' => $t->messages->sortBy('sent_at')->values()->map(fn (ParentMessage $m) => $this->transformMessage($m)),
                'channels' => Delivery::channels($this->school($request)),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function threadsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'guardian_id' => ['required', 'integer', $this->tenantExists($request, 'student_guardians')],
                'subject' => ['required', 'string', 'max:200'],
                'body' => ['required', 'string', 'max:5000'],
                'channels' => ['nullable', 'array'],
                'channels.*' => ['string', Rule::in(Delivery::CHANNELS)],
            ]);
            $this->assertChannelsAvailable($request, $data['channels'] ?? []);

            $guardian = StudentGuardian::query()->where('school_id', $schoolId)->findOrFail($data['guardian_id']);

            $thread = DB::transaction(function () use ($request, $schoolId, $guardian, $data) {
                $t = ParentThread::query()->create([
                    'school_id' => $schoolId,
                    'guardian_id' => $guardian->id,
                    'student_id' => $guardian->student_id,
                    'subject' => $data['subject'],
                    'status' => 'open',
                    'created_by' => $request->user()?->id,
                ]);
                $this->sendOutbound($request, $t, $guardian, $data['body'], $data['channels'] ?? []);

                return $t;
            });

            $this->audit($request, 'parent_thread.created', $thread->id, ['guardian_id' => $guardian->id]);

            return $this->threadsShow($request, $thread->id)->setStatusCode(201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function messagesStore(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $t = ParentThread::query()->where('school_id', $schoolId)->with('guardian')->findOrFail($id);
            $data = $request->validate([
                'body' => ['required', 'string', 'max:5000'],
                'channels' => ['nullable', 'array'],
                'channels.*' => ['string', Rule::in(Delivery::CHANNELS)],
            ]);
            $this->assertChannelsAvailable($request, $data['channels'] ?? []);

            if ($t->status === 'closed') {
                $t->status = 'open';
            }
            $this->sendOutbound($request, $t, $t->guardian, $data['body'], $data['channels'] ?? []);

            return $this->threadsShow($request, $t->id)->setStatusCode(201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Record a reply the parent sent outside the portal (phone call, SMS, visit, email). */
    public function messagesInbound(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $t = ParentThread::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'body' => ['required', 'string', 'max:5000'],
                'channel' => ['required', Rule::in(['phone', 'sms', 'email', 'in_person', 'whatsapp'])],
                'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            ]);

            $at = isset($data['received_at']) ? \Illuminate\Support\Carbon::parse($data['received_at']) : now();
            ParentMessage::query()->create([
                'school_id' => $schoolId,
                'thread_id' => $t->id,
                'direction' => 'inbound',
                'body' => $data['body'],
                'channels' => [$data['channel']],
                'delivery' => ['logged_by' => $request->user()?->name],
                'sender_user_id' => $request->user()?->id,
                'sent_at' => $at,
                'read_at' => now(), // logged by staff → already seen
            ]);

            $t->fill([
                'status' => 'open',
                'last_message_at' => $at->greaterThan($t->last_message_at ?? $at->copy()->subSecond()) ? $at : $t->last_message_at,
                'last_message_preview' => Str::limit($data['body'], 190),
                'last_direction' => 'inbound',
            ])->save();

            return $this->threadsShow($request, $t->id)->setStatusCode(201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function threadsUpdate(Request $request, int $id)
    {
        try {
            $t = ParentThread::query()->where('school_id', $this->schoolId($request))->findOrFail($id);
            $data = $request->validate([
                'status' => ['sometimes', Rule::in(['open', 'closed'])],
                'subject' => ['sometimes', 'string', 'max:200'],
            ]);
            $t->fill($data)->save();
            $this->audit($request, 'parent_thread.updated', $t->id, $data);

            return $this->success(['thread' => $this->transformThread($t->fresh(['guardian', 'student']))], 'Conversation updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function sendOutbound(Request $request, ParentThread $t, StudentGuardian $g, string $body, array $channels): ParentMessage
    {
        $school = $this->school($request);
        $channels = Delivery::normalize($channels);

        $msg = ParentMessage::query()->create([
            'school_id' => $t->school_id,
            'thread_id' => $t->id,
            'direction' => 'outbound',
            'body' => $body,
            'channels' => $channels,
            'sender_user_id' => $request->user()?->id,
            'sent_at' => now(),
        ]);

        $delivery = ['portal' => ['status' => 'recorded']];
        if (in_array('email', $channels, true)) {
            $delivery['email'] = $g->email
                ? Delivery::email($school, $request->user()?->id, $g->email, $t->subject, $body, 'parent_message', $msg->id)
                : ['status' => 'skipped', 'error' => 'No email address on file'];
        }
        if (in_array('sms', $channels, true)) {
            $delivery['sms'] = $g->phone
                ? Delivery::sms($school, $request->user()?->id, $g->phone, $t->subject, $body, 'parent_message', $msg->id)
                : ['status' => 'skipped', 'error' => 'No phone number on file'];
        }
        $msg->delivery = $delivery;
        $msg->save();

        $t->fill([
            'last_message_at' => $msg->sent_at,
            'last_message_preview' => Str::limit($body, 190),
            'last_direction' => 'outbound',
        ])->save();

        return $msg;
    }

    /** @throws \InvalidArgumentException */
    protected function assertChannelsAvailable(Request $request, array $channels): void
    {
        $available = Delivery::channels($this->school($request));
        foreach ($channels as $c) {
            if (isset($available[$c]) && ! $available[$c]['available']) {
                throw new \InvalidArgumentException($available[$c]['label'].' is not available: '.$available[$c]['note']);
            }
        }
    }

    protected function validateAnnouncement(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'kind' => [$partial ? 'prohibited' : 'nullable', Rule::in(['announcement', 'notice'])],
            'title' => [$req, 'string', 'max:200'],
            'body' => [$req, 'string', 'max:10000'],
            'category' => ['sometimes', Rule::in(self::CATEGORIES)],
            'audience' => ['sometimes', Rule::in(Audience::TYPES)],
            'classroom_ids' => ['nullable', 'array'],
            'classroom_ids.*' => ['integer'],
            'priority' => ['sometimes', Rule::in(['normal', 'important', 'urgent'])],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(Delivery::CHANNELS)],
            'is_pinned' => ['sometimes', 'boolean'],
            'show_on_board' => ['sometimes', 'boolean'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'action' => ['nullable', Rule::in(['draft', 'publish', 'schedule'])],
        ]);

        if (! $partial) {
            $data['kind'] = $data['kind'] ?? 'announcement';
        }
        if (($data['audience'] ?? null) === 'classes' && empty($data['classroom_ids'])) {
            throw new \InvalidArgumentException('Choose at least one class for a class audience.');
        }
        if (($data['audience'] ?? null) !== null && $data['audience'] !== 'classes') {
            $data['classroom_ids'] = null;
        }
        if (! empty($data['publish_at']) && ! empty($data['expires_at']) && $data['expires_at'] <= $data['publish_at']) {
            throw new \InvalidArgumentException('Expiry must be after the publish time.');
        }

        return $data;
    }

    protected function applyAction(Announcement $a, string $action, Request $request): Announcement
    {
        if ($action === 'publish') {
            return Publisher::publish($a, $request->user()?->id);
        }
        if ($action === 'schedule') {
            if (! $a->publish_at || $a->publish_at->isPast()) {
                throw new \InvalidArgumentException('Choose a future date and time to schedule.');
            }
            $a->status = 'scheduled';
            $a->save();
        } elseif ($action === 'draft') {
            $a->status = 'draft';
            $a->save();
        }

        return $a;
    }

    protected function actionMessage(Announcement $a): string
    {
        $label = $a->kind === 'notice' ? 'Notice' : 'Announcement';

        return match ($a->status) {
            'published' => $label.' published',
            'scheduled' => $label.' scheduled for '.$a->publish_at?->format('j M Y, H:i'),
            default => $label.' saved as draft',
        };
    }

    protected function boardQuery(int $schoolId)
    {
        return Announcement::query()
            ->where('school_id', $schoolId)
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('kind', 'notice')->orWhere('show_on_board', true))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    protected function audit(Request $request, string $action, ?int $entityId, mixed $changes): void
    {
        try {
            AuditLog::query()->create([
                'school_id' => $this->schoolId($request),
                'user_id' => $request->user()?->id,
                'action' => $action,
                'entity_type' => 'communication',
                'entity_id' => $entityId,
                'changes' => is_array($changes) ? $changes : ['detail' => $changes],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 250),
            ]);
        } catch (Throwable) {
        }
    }

    protected function transformAnnouncement(Announcement $a, bool $detailed = false): array
    {
        $out = [
            'id' => $a->id,
            'kind' => $a->kind,
            'title' => $a->title,
            'body' => $a->body,
            'category' => $a->category,
            'audience' => $a->audience,
            'classroom_ids' => $a->classroom_ids ?? [],
            'priority' => $a->priority,
            'channels' => $a->channels ?? ['portal'],
            'status' => $a->status,
            'is_pinned' => (bool) $a->is_pinned,
            'show_on_board' => (bool) $a->show_on_board,
            'publish_at' => $a->publish_at?->toIso8601String(),
            'published_at' => $a->published_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'is_expired' => $a->expires_at ? $a->expires_at->isPast() : false,
            'recipients_count' => (int) $a->recipients_count,
            'delivery_summary' => $a->delivery_summary,
            'author' => $a->relationLoaded('author') && $a->author ? ['id' => $a->author->id, 'name' => $a->author->name] : null,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
        if ($detailed) {
            $out['publisher'] = $a->relationLoaded('publisher') && $a->publisher ? ['id' => $a->publisher->id, 'name' => $a->publisher->name] : null;
        }

        return $out;
    }

    protected function transformGuardian(StudentGuardian $g): array
    {
        $s = $g->student;
        return [
            'id' => $g->id,
            'full_name' => $g->full_name,
            'relationship' => $g->relationship,
            'phone' => $g->phone,
            'email' => $g->email,
            'is_primary' => (bool) $g->is_primary,
            'student' => $s ? [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'admission_number' => $s->admission_number,
                'classroom' => $s->currentEnrollment?->classroom?->name,
            ] : null,
        ];
    }

    protected function transformThread(ParentThread $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'unread_count' => (int) $t->unread_count,
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'last_message_preview' => $t->last_message_preview,
            'last_direction' => $t->last_direction,
            'guardian' => $t->guardian ? $this->transformGuardian($t->guardian->setRelation('student', $t->student)) : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    protected function transformMessage(ParentMessage $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'body' => $m->body,
            'channels' => $m->channels ?? [],
            'delivery' => $m->delivery ?? [],
            'sender' => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
            'sent_at' => $m->sent_at?->toIso8601String(),
            'read_at' => $m->read_at?->toIso8601String(),
        ];
    }
}
