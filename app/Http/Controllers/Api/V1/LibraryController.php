<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\LibraryBook;
use App\Models\LibraryCategory;
use App\Models\LibraryLoan;
use App\Models\LibraryReservation;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public function categoriesIndex(Request $request)
    {
        try {
            $items = LibraryCategory::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount('books')
                ->orderBy('name')
                ->get();

            return $this->success(['categories' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function categoriesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string'],
            ]);
            $item = LibraryCategory::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
            ]);

            return $this->success(['category' => $item], 'Category created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function categoriesUpdate(Request $request, int $id)
    {
        try {
            $item = LibraryCategory::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success(['category' => $item->fresh()], 'Category updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function booksIndex(Request $request)
    {
        try {
            $query = LibraryBook::query()
                ->where('school_id', $this->schoolId($request))
                ->with('libraryCategory:id,name')
                ->orderBy('title');

            if ($request->integer('category_id')) {
                $query->where('category_id', $request->integer('category_id'));
            }
            $q = $request->string('q')->toString();
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('title', 'like', "%{$q}%")
                        ->orWhere('author', 'like', "%{$q}%")
                        ->orWhere('isbn', 'like', "%{$q}%");
                });
            }
            if ($request->string('isbn')->toString() !== '') {
                $query->where('isbn', 'like', '%'.$request->string('isbn')->toString().'%');
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'books' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function booksStore(Request $request)
    {
        try {
            $data = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'author' => ['nullable', 'string', 'max:160'],
                'isbn' => ['nullable', 'string', 'max:40'],
                'category_id' => ['nullable', 'integer'],
                'category' => ['nullable', 'string', 'max:80'],
                'shelf_location' => ['nullable', 'string', 'max:80'],
                'total_copies' => ['nullable', 'integer', 'min:0'],
                'publisher' => ['nullable', 'string', 'max:160'],
                'published_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            ]);
            $copies = (int) ($data['total_copies'] ?? 1);
            $item = LibraryBook::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'total_copies' => $copies,
                'available_copies' => $copies,
                'status' => 'active',
            ]);

            return $this->success(['book' => $item->load('libraryCategory')], 'Book added', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function booksUpdate(Request $request, int $id)
    {
        try {
            $item = LibraryBook::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'author' => ['nullable', 'string', 'max:160'],
                'isbn' => ['nullable', 'string', 'max:40'],
                'category_id' => ['nullable', 'integer'],
                'category' => ['nullable', 'string', 'max:80'],
                'shelf_location' => ['nullable', 'string', 'max:80'],
                'total_copies' => ['nullable', 'integer', 'min:0'],
                'publisher' => ['nullable', 'string', 'max:160'],
                'published_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
                'status' => ['nullable', 'in:active,retired,lost'],
            ]);

            if (isset($data['total_copies'])) {
                $onLoan = max(0, (int) $item->total_copies - (int) $item->available_copies);
                $data['available_copies'] = max(0, (int) $data['total_copies'] - $onLoan);
            }
            $item->fill($data)->save();

            return $this->success(['book' => $item->fresh()->load('libraryCategory')], 'Book updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function loansIndex(Request $request)
    {
        try {
            $status = $request->string('status')->toString();
            // Mark overdue
            LibraryLoan::query()
                ->where('school_id', $this->schoolId($request))
                ->where('status', 'issued')
                ->whereNotNull('due_at')
                ->whereDate('due_at', '<', now()->toDateString())
                ->update(['status' => 'overdue']);

            $query = LibraryLoan::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'book:id,title,isbn,author',
                    'student:id,first_name,last_name,admission_number',
                ])
                ->orderByDesc('id');

            if ($status !== '') {
                $query->where('status', $status);
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'loans' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function loansStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'book_id' => ['required', 'integer'],
                'student_id' => ['required', 'integer'],
                'issued_at' => ['nullable', 'date'],
                'due_at' => ['nullable', 'date'],
                'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            ]);

            $loan = DB::transaction(function () use ($schoolId, $data) {
                Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);
                $book = LibraryBook::query()
                    ->where('school_id', $schoolId)
                    ->lockForUpdate()
                    ->findOrFail($data['book_id']);

                if ((int) $book->available_copies < 1) {
                    throw new \InvalidArgumentException('No copies available for loan');
                }

                $issued = $data['issued_at'] ?? now()->toDateString();
                $due = $data['due_at'] ?? now()->addDays((int) ($data['days'] ?? 14))->toDateString();

                $book->available_copies = max(0, (int) $book->available_copies - 1);
                $book->save();

                return LibraryLoan::query()->create([
                    'school_id' => $schoolId,
                    'book_id' => $book->id,
                    'student_id' => $data['student_id'],
                    'issued_at' => $issued,
                    'due_at' => $due,
                    'status' => 'issued',
                    'fine_amount' => 0,
                ])->load(['book', 'student']);
            });

            return $this->success(['loan' => $loan], 'Book issued', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function loansReturn(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $finePerDay = (float) ($request->input('fine_per_day', 500));

            $loan = DB::transaction(function () use ($schoolId, $id, $finePerDay) {
                $loan = LibraryLoan::query()
                    ->where('school_id', $schoolId)
                    ->whereIn('status', ['issued', 'overdue'])
                    ->lockForUpdate()
                    ->findOrFail($id);

                $returned = now()->toDateString();
                $fine = 0.0;
                if ($loan->due_at && $loan->due_at->lt(now()->startOfDay())) {
                    $days = $loan->due_at->diffInDays(now()->startOfDay());
                    $fine = round($days * $finePerDay, 2);
                }

                $loan->returned_at = $returned;
                $loan->status = 'returned';
                $loan->fine_amount = $fine;
                $loan->save();

                $book = LibraryBook::query()->lockForUpdate()->find($loan->book_id);
                if ($book) {
                    $book->available_copies = min(
                        (int) $book->total_copies,
                        (int) $book->available_copies + 1
                    );
                    $book->save();
                }

                return $loan->load(['book', 'student']);
            });

            return $this->success(['loan' => $loan], 'Book returned');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reservationsIndex(Request $request)
    {
        try {
            $items = LibraryReservation::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'book:id,title,isbn',
                    'student:id,first_name,last_name,admission_number',
                ])
                ->orderByDesc('id')
                ->limit(100)
                ->get();

            return $this->success(['reservations' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reservationsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'book_id' => ['required', 'integer'],
                'student_id' => ['required', 'integer'],
                'reserved_on' => ['nullable', 'date'],
                'expires_on' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);
            LibraryBook::query()->where('school_id', $schoolId)->findOrFail($data['book_id']);
            Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);

            $item = LibraryReservation::query()->create([
                'school_id' => $schoolId,
                'book_id' => $data['book_id'],
                'student_id' => $data['student_id'],
                'reserved_on' => $data['reserved_on'] ?? now()->toDateString(),
                'expires_on' => $data['expires_on'] ?? now()->addDays(7)->toDateString(),
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
            ]);

            return $this->success([
                'reservation' => $item->load(['book', 'student']),
            ], 'Reservation created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reservationsUpdate(Request $request, int $id)
    {
        try {
            $item = LibraryReservation::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'status' => ['required', 'in:active,fulfilled,cancelled,expired'],
                'notes' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success(['reservation' => $item->fresh()], 'Reservation updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function overdueIndex(Request $request)
    {
        try {
            LibraryLoan::query()
                ->where('school_id', $this->schoolId($request))
                ->where('status', 'issued')
                ->whereNotNull('due_at')
                ->whereDate('due_at', '<', now()->toDateString())
                ->update(['status' => 'overdue']);

            $items = LibraryLoan::query()
                ->where('school_id', $this->schoolId($request))
                ->where('status', 'overdue')
                ->with(['book:id,title,isbn', 'student:id,first_name,last_name,admission_number'])
                ->orderBy('due_at')
                ->get();

            return $this->success(['loans' => $items, 'summary' => ['count' => $items->count()]]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function finesIndex(Request $request)
    {
        try {
            $items = LibraryLoan::query()
                ->where('school_id', $this->schoolId($request))
                ->where('fine_amount', '>', 0)
                ->with(['book:id,title', 'student:id,first_name,last_name,admission_number'])
                ->orderByDesc('returned_at')
                ->limit(100)
                ->get();

            $total = (float) LibraryLoan::query()
                ->where('school_id', $this->schoolId($request))
                ->sum('fine_amount');

            return $this->success([
                'fines' => $items,
                'summary' => ['total_fines' => round($total, 2), 'count' => $items->count()],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $books = LibraryBook::query()->where('school_id', $schoolId)->count();
            $copies = (int) LibraryBook::query()->where('school_id', $schoolId)->sum('total_copies');
            $available = (int) LibraryBook::query()->where('school_id', $schoolId)->sum('available_copies');
            $issued = LibraryLoan::query()->where('school_id', $schoolId)->whereIn('status', ['issued', 'overdue'])->count();
            $overdue = LibraryLoan::query()->where('school_id', $schoolId)->where('status', 'overdue')->count();
            $fines = (float) LibraryLoan::query()->where('school_id', $schoolId)->sum('fine_amount');
            $reservations = LibraryReservation::query()->where('school_id', $schoolId)->where('status', 'active')->count();

            return $this->success([
                'summary' => [
                    'titles' => $books,
                    'total_copies' => $copies,
                    'available_copies' => $available,
                    'on_loan' => $issued,
                    'overdue' => $overdue,
                    'total_fines' => round($fines, 2),
                    'active_reservations' => $reservations,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
