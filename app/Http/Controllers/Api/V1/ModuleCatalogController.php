<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use Illuminate\Http\Request;
use Throwable;

class ModuleCatalogController extends Controller
{
    use ApiResponds;

    public function index(Request $request)
    {
        try {
            $modules = config('daruno_modules.modules', []);

            return $this->success([
                'count' => count($modules),
                'modules' => $modules,
                'ai_excluded' => true,
                'docs' => 'docs/MODULES.md',
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(string $key)
    {
        try {
            $modules = config('daruno_modules.modules', []);
            $module = collect($modules)->firstWhere('key', $key);

            if (! $module) {
                return $this->error('Module not found', 404);
            }

            return $this->success([
                'module' => $module,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
