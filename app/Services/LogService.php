<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class LogService
{
    public static function log(
        Request $request,
        string  $action,
        string  $description,
        string  $entityType  = 'general',
        ?string $entityId    = null,
        array   $oldValues   = [],
        array   $newValues   = []
    ): void {
        try {
            $user = $request->user();

            ActivityLog::create([
                'restaurant_id' => $user?->restaurant_id,
                'user_id'       => $user?->id,
                'user_name'     => $user?->name,
                'action'        => $action,
                'description'   => $description,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'old_values'    => $oldValues,
                'new_values'    => $newValues,
                'ip_address'    => $request->ip(),
                'user_agent'    => substr($request->userAgent() ?? '', 0, 255),
            ]);
        } catch (\Exception $e) {
            // Ne pas bloquer l'app si le log échoue
        }
    }
}
