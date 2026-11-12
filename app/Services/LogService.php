<?php

namespace App\Services;

use App\Events\ActivityCreated;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogService
{
    /**
     * Actions diffusées en temps réel sur le flux d'activité du restaurant.
     * Les actions non listées sont enregistrées en base mais pas diffusées
     * (menu.item_updated, table.model_changed, restaurant.fiscal_settings_updated...).
     */
    private const BROADCAST_ACTIONS = [
        'order.created',
        'order.status_updated',
        'order.cancelled',
        'order.sent_to_kitchen',
        'payment.processed',
        'collection_request.created',
        'collection_request.validated',
        'collection_request.cancelled',
        'table.status_changed',
        'z_report.closed',
        'invoice.issued',
    ];

    public static function log(
        Request $request,
        string $action,
        string $description,
        string $entityType = 'general',
        ?string $entityId = null,
        array $oldValues = [],
        array $newValues = []
    ): void {
        try {
            $user = $request->user();

            $log = ActivityLog::create([
                'restaurant_id' => $user?->restaurant_id,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'action' => $action,
                'description' => $description,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            ]);

            if ($log->restaurant_id && in_array($action, self::BROADCAST_ACTIONS, true)) {
                self::broadcastActivity($log);
            }
        } catch (\Exception $e) {
            // Ne pas bloquer l'app si le log échoue
        }
    }

    /**
     * Diffuse l'entrée d'activité en temps réel (silencieux si Reverb absent).
     */
    private static function broadcastActivity(ActivityLog $log): void
    {
        $event = new ActivityCreated(
            restaurantId: $log->restaurant_id,
            logId: $log->id,
            action: $log->action,
            description: $log->description,
            userName: $log->user_name,
            entityType: $log->entity_type,
            entityId: $log->entity_id,
            createdAt: ($log->created_at ?? now())->toIso8601String(),
        );

        $dispatch = function () use ($event) {
            try {
                event($event);
            } catch (\Throwable) {
                // Reverb indisponible — le polling frontend compensera
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
