<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use Illuminate\Http\Request;
use Throwable;

/**
 * Generic module resource endpoints.
 * Returns structured empty collections until domain services are filled in.
 */
class ModuleResourceController extends Controller
{
    use ApiResponds;

    public function index(Request $request, string $module)
    {
        try {
            $this->assertModule($module);
            $school = $request->attributes->get('school');

            return $this->success([
                'module' => $module,
                'school_id' => $school?->id,
                'items' => [],
                'meta' => [
                    'page' => 1,
                    'per_page' => 25,
                    'total' => 0,
                    'status' => 'scaffolded',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500);
        }
    }

    public function store(Request $request, string $module)
    {
        try {
            $this->assertModule($module);

            return $this->success([
                'module' => $module,
                'created' => false,
                'status' => 'scaffolded',
                'payload_received' => $request->all(),
            ], 'Endpoint ready — domain create not implemented yet', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500);
        }
    }

    public function show(Request $request, string $module, string $id)
    {
        try {
            $this->assertModule($module);

            return $this->success([
                'module' => $module,
                'id' => $id,
                'item' => null,
                'status' => 'scaffolded',
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500);
        }
    }

    public function update(Request $request, string $module, string $id)
    {
        try {
            $this->assertModule($module);

            return $this->success([
                'module' => $module,
                'id' => $id,
                'updated' => false,
                'status' => 'scaffolded',
                'payload_received' => $request->all(),
            ], 'Endpoint ready — domain update not implemented yet');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500);
        }
    }

    public function destroy(string $module, string $id)
    {
        try {
            $this->assertModule($module);

            return $this->success([
                'module' => $module,
                'id' => $id,
                'deleted' => false,
                'status' => 'scaffolded',
            ], 'Endpoint ready — domain delete not implemented yet');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500);
        }
    }

    public function feature(Request $request, string $module, string $feature)
    {
        try {
            $def = $this->assertModule($module);
            $features = $def['features'] ?? [];

            if (! in_array($feature, $features, true)) {
                return $this->error("Feature [{$feature}] not defined for module [{$module}]", 404);
            }

            return $this->success([
                'module' => $module,
                'feature' => $feature,
                'items' => [],
                'status' => 'scaffolded',
                'school_id' => $request->attributes->get('school')?->id,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500);
        }
    }

    protected function assertModule(string $module): array
    {
        $modules = config('daruno_modules.modules', []);
        $def = collect($modules)->firstWhere('key', $module);

        if (! $def) {
            abort(404, "Unknown module [{$module}]");
        }

        return $def;
    }
}
