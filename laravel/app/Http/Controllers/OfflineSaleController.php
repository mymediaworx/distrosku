<?php

namespace App\Http\Controllers;

use App\Models\OfflineSale;
use App\Pos\OfflineSaleSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Issue #26: a device sends its queued offline sales (oldest first) once it's back online. */
class OfflineSaleController extends Controller
{
    public const MAX_PER_REQUEST = 25;

    public function sync(Request $request, OfflineSaleSync $sync): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'nullable|string|max:64',
            'sales' => 'required|array|min:1|max:'.self::MAX_PER_REQUEST,
            'sales.*' => 'array',
        ]);

        $results = [];
        foreach ($data['sales'] as $sale) {
            try {
                $row = $sync->ingest($sale, $request->user(), $data['device_id'] ?? null, 'pos');
                $results[] = self::result($row);
            } catch (ValidationException $e) {
                // no usable UUID: the device keeps it queued and shows the error
                $results[] = ['uuid' => $sale['uuid'] ?? null, 'status' => 'rejected', 'problems' => $e->validator->errors()->all()];
            }
        }

        return response()->json(['results' => $results]);
    }

    /** What the device needs to clear its queue entry and show the outcome. */
    public static function result(OfflineSale $row): array
    {
        return [
            'uuid' => $row->uuid,
            'status' => $row->status,                          // completed | needs_review | resolved (all: stop resending)
            'number' => $row->salesOrder?->number,
            'problems' => $row->problems ?? [],
        ];
    }
}
