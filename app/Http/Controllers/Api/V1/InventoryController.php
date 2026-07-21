<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class InventoryController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Categories ────────────────────────────────────────────────

    public function categoriesIndex(Request $request)
    {
        try {
            $items = AssetCategory::query()
                ->where('school_id', $this->schoolId($request))
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
            ]);
            $item = AssetCategory::query()->create([
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
            $item = AssetCategory::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
            ]);
            $item->fill($data)->save();

            return $this->success(['category' => $item->fresh()], 'Category updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Suppliers / vendors ───────────────────────────────────────

    public function suppliersIndex(Request $request)
    {
        try {
            $items = Vendor::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('name')
                ->get();

            return $this->success(['suppliers' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function suppliersStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:120'],
                'address' => ['nullable', 'string', 'max:255'],
            ]);
            $item = Vendor::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
            ]);

            return $this->success(['supplier' => $item], 'Supplier created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function suppliersUpdate(Request $request, int $id)
    {
        try {
            $item = Vendor::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:120'],
                'address' => ['nullable', 'string', 'max:255'],
            ]);
            $item->fill($data)->save();

            return $this->success(['supplier' => $item->fresh()], 'Supplier updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Assets ────────────────────────────────────────────────────

    public function assetsIndex(Request $request)
    {
        try {
            $query = Asset::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['category:id,name', 'vendor:id,name'])
                ->orderByDesc('id');

            if ($request->integer('category_id')) {
                $query->where('category_id', $request->integer('category_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }
            $q = $request->string('q')->toString();
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('serial_number', 'like', "%{$q}%");
                });
            }

            return $this->success(['assets' => $query->limit(200)->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function assetsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'serial_number' => ['nullable', 'string', 'max:80'],
                'category_id' => ['nullable', 'integer'],
                'vendor_id' => ['nullable', 'integer'],
                'purchase_date' => ['nullable', 'date'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'status' => ['nullable', 'in:active,retired,lost,maintenance'],
                'location' => ['nullable', 'string', 'max:120'],
                'condition' => ['nullable', 'string', 'max:40'],
                'notes' => ['nullable', 'string'],
            ]);

            $item = Asset::query()->create([
                'school_id' => $schoolId,
                ...$data,
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success(['asset' => $item->load(['category', 'vendor'])], 'Asset created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function assetsUpdate(Request $request, int $id)
    {
        try {
            $item = Asset::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'serial_number' => ['nullable', 'string', 'max:80'],
                'category_id' => ['nullable', 'integer'],
                'vendor_id' => ['nullable', 'integer'],
                'purchase_date' => ['nullable', 'date'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'status' => ['nullable', 'in:active,retired,lost,maintenance'],
                'location' => ['nullable', 'string', 'max:120'],
                'condition' => ['nullable', 'string', 'max:40'],
                'notes' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success(['asset' => $item->fresh()->load(['category', 'vendor'])], 'Asset updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Stock items ───────────────────────────────────────────────

    public function stockItemsIndex(Request $request)
    {
        try {
            $items = StockItem::query()
                ->where('school_id', $this->schoolId($request))
                ->with('category:id,name')
                ->orderBy('name')
                ->get()
                ->map(function (StockItem $i) {
                    $arr = $i->toArray();
                    $arr['is_low'] = (int) $i->quantity_on_hand <= (int) $i->reorder_level;

                    return $arr;
                });

            return $this->success(['stock_items' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function stockItemsStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'unit' => ['nullable', 'string', 'max:30'],
                'category_id' => ['nullable', 'integer'],
                'quantity_on_hand' => ['nullable', 'integer', 'min:0'],
                'reorder_level' => ['nullable', 'integer', 'min:0'],
                'unit_cost' => ['nullable', 'numeric', 'min:0'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);
            $item = StockItem::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'quantity_on_hand' => $data['quantity_on_hand'] ?? 0,
                'reorder_level' => $data['reorder_level'] ?? 0,
                'unit_cost' => $data['unit_cost'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success(['stock_item' => $item], 'Stock item created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function stockItemsUpdate(Request $request, int $id)
    {
        try {
            $item = StockItem::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'unit' => ['nullable', 'string', 'max:30'],
                'category_id' => ['nullable', 'integer'],
                'reorder_level' => ['nullable', 'integer', 'min:0'],
                'unit_cost' => ['nullable', 'numeric', 'min:0'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);
            $item->fill($data)->save();

            return $this->success(['stock_item' => $item->fresh()], 'Stock item updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Stock movements (in / out) ────────────────────────────────

    public function movementsIndex(Request $request)
    {
        try {
            $type = $request->string('type')->toString();
            $query = StockMovement::query()
                ->where('school_id', $this->schoolId($request))
                ->with('stockItem:id,name,code,unit')
                ->orderByDesc('occurred_on')
                ->orderByDesc('id');

            if (in_array($type, ['in', 'out', 'adjust'], true)) {
                $query->where('type', $type);
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'movements' => $page->items(),
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

    public function movementsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'stock_item_id' => ['required', 'integer'],
                'type' => ['required', 'in:in,out,adjust'],
                'quantity' => ['required', 'integer', 'min:1'],
                'occurred_on' => ['nullable', 'date'],
                'reference' => ['nullable', 'string', 'max:80'],
                'notes' => ['nullable', 'string'],
                'unit_cost' => ['nullable', 'numeric', 'min:0'],
            ]);

            $movement = DB::transaction(function () use ($schoolId, $data, $request) {
                $item = StockItem::query()
                    ->where('school_id', $schoolId)
                    ->lockForUpdate()
                    ->findOrFail($data['stock_item_id']);

                $qty = (int) $data['quantity'];
                if ($data['type'] === 'out') {
                    if ($item->quantity_on_hand < $qty) {
                        throw new \InvalidArgumentException('Insufficient stock on hand');
                    }
                    $item->quantity_on_hand -= $qty;
                } elseif ($data['type'] === 'in') {
                    $item->quantity_on_hand += $qty;
                    if (isset($data['unit_cost'])) {
                        $item->unit_cost = $data['unit_cost'];
                    }
                } else {
                    // adjust sets absolute? treat quantity as delta with sign via notes — industrial: absolute set
                    $item->quantity_on_hand = $qty;
                }
                $item->save();

                return StockMovement::query()->create([
                    'school_id' => $schoolId,
                    'stock_item_id' => $item->id,
                    'type' => $data['type'],
                    'quantity' => $qty,
                    'occurred_on' => $data['occurred_on'] ?? now()->toDateString(),
                    'reference' => $data['reference'] ?? strtoupper($data['type']).'-'.strtoupper(Str::random(6)),
                    'notes' => $data['notes'] ?? null,
                    'unit_cost' => $data['unit_cost'] ?? $item->unit_cost,
                    'created_by' => $request->user()?->id,
                ])->load('stockItem');
            });

            return $this->success(['movement' => $movement], 'Stock movement recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Purchase orders ───────────────────────────────────────────

    public function purchaseOrdersIndex(Request $request)
    {
        try {
            $items = PurchaseOrder::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['vendor:id,name', 'items.stockItem:id,name'])
                ->orderByDesc('id')
                ->get();

            return $this->success(['purchase_orders' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function purchaseOrdersStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'vendor_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'in:draft,ordered,received,cancelled'],
                'ordered_at' => ['nullable', 'date'],
                'expected_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.description' => ['required', 'string'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
                'items.*.stock_item_id' => ['nullable', 'integer'],
            ]);

            $po = DB::transaction(function () use ($schoolId, $data) {
                $total = 0;
                foreach ($data['items'] as $line) {
                    $total += ((int) $line['quantity']) * ((float) $line['unit_price']);
                }

                $po = PurchaseOrder::query()->create([
                    'school_id' => $schoolId,
                    'vendor_id' => $data['vendor_id'] ?? null,
                    'number' => 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)),
                    'total_amount' => $total,
                    'status' => $data['status'] ?? 'draft',
                    'ordered_at' => $data['ordered_at'] ?? null,
                    'expected_at' => $data['expected_at'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($data['items'] as $line) {
                    $lt = ((int) $line['quantity']) * ((float) $line['unit_price']);
                    PurchaseOrderItem::query()->create([
                        'purchase_order_id' => $po->id,
                        'stock_item_id' => $line['stock_item_id'] ?? null,
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'line_total' => $lt,
                    ]);
                }

                return $po->load(['vendor', 'items.stockItem']);
            });

            return $this->success(['purchase_order' => $po], 'Purchase order created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function purchaseOrdersUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $po = PurchaseOrder::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'status' => ['sometimes', 'in:draft,ordered,received,cancelled'],
                'vendor_id' => ['nullable', 'integer'],
                'ordered_at' => ['nullable', 'date'],
                'expected_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);

            DB::transaction(function () use ($po, $data, $schoolId, $request) {
                $prev = $po->status;
                $po->fill($data);
                if (($data['status'] ?? null) === 'received' && $prev !== 'received') {
                    $po->received_at = now()->toDateString();
                    foreach ($po->items as $line) {
                        if (! $line->stock_item_id) {
                            continue;
                        }
                        $item = StockItem::query()
                            ->where('school_id', $schoolId)
                            ->lockForUpdate()
                            ->find($line->stock_item_id);
                        if (! $item) {
                            continue;
                        }
                        $item->quantity_on_hand += (int) $line->quantity;
                        $item->unit_cost = $line->unit_price;
                        $item->save();
                        StockMovement::query()->create([
                            'school_id' => $schoolId,
                            'stock_item_id' => $item->id,
                            'type' => 'in',
                            'quantity' => $line->quantity,
                            'occurred_on' => now()->toDateString(),
                            'reference' => $po->number,
                            'notes' => 'PO receipt',
                            'unit_cost' => $line->unit_price,
                            'created_by' => $request->user()?->id,
                        ]);
                    }
                }
                $po->save();
            });

            return $this->success([
                'purchase_order' => $po->fresh()->load(['vendor', 'items.stockItem']),
            ], 'Purchase order updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Alerts & reports ──────────────────────────────────────────

    public function alertsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $items = StockItem::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                ->with('category:id,name')
                ->orderBy('quantity_on_hand')
                ->get();

            return $this->success([
                'alerts' => $items,
                'summary' => ['low_stock_count' => $items->count()],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $stockValue = (float) StockItem::query()
                ->where('school_id', $schoolId)
                ->selectRaw('COALESCE(SUM(quantity_on_hand * unit_cost),0) as v')
                ->value('v');
            $assetValue = (float) Asset::query()
                ->where('school_id', $schoolId)
                ->where('status', 'active')
                ->sum('purchase_price');
            $openPos = PurchaseOrder::query()
                ->where('school_id', $schoolId)
                ->whereIn('status', ['draft', 'ordered'])
                ->count();
            $lowStock = StockItem::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                ->count();
            $movementsIn = (int) StockMovement::query()
                ->where('school_id', $schoolId)->where('type', 'in')->sum('quantity');
            $movementsOut = (int) StockMovement::query()
                ->where('school_id', $schoolId)->where('type', 'out')->sum('quantity');

            return $this->success([
                'summary' => [
                    'stock_items' => StockItem::query()->where('school_id', $schoolId)->count(),
                    'assets' => Asset::query()->where('school_id', $schoolId)->count(),
                    'suppliers' => Vendor::query()->where('school_id', $schoolId)->count(),
                    'stock_value' => round($stockValue, 2),
                    'asset_value' => round($assetValue, 2),
                    'open_purchase_orders' => $openPos,
                    'low_stock_count' => $lowStock,
                    'total_stock_in' => $movementsIn,
                    'total_stock_out' => $movementsOut,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
