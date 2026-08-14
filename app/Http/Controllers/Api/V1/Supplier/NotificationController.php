<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Liste des notifications du fournisseur connecté.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->limit(50)->get();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'items' => $notifications->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? null,
                'title' => $n->data['title'] ?? '',
                'message' => $n->data['message'] ?? '',
                'days_remaining' => $n->data['days_remaining'] ?? null,
                'order_id' => $n->data['order_id'] ?? null,
                'product_id' => $n->data['product_id'] ?? null,
                'read' => !is_null($n->read_at),
                'created_at' => $n->created_at?->toISOString(),
            ]),
        ]);
    }

    /**
     * Compte des notifications non lues.
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marquer une notification comme lue.
     */
    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();
        if ($notification) {
            $notification->markAsRead();
        }
        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    /**
     * Marquer toutes les notifications comme lues.
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        return response()->json(['message' => 'Toutes les notifications sont lues.']);
    }
}
