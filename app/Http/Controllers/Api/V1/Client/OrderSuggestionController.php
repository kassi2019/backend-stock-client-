<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\Order;
use App\Models\StockEntry;
use Illuminate\Http\Request;

class OrderSuggestionController extends Controller
{
    /**
     * Suggestion de quantité à commander, par produit, au moment de passer
     * une nouvelle commande.
     *
     * Fenêtre glissante : on calcule les consommations entre les dernières
     * commandes livrées, en incluant la période en cours (dernière commande
     * → aujourd'hui). Au plus 5 consommations.
     *
     * Quantité suggérée = (moyenne des consommations × 1,20) − stock actuel
     * (0 si négatif, arrondi à l'entier supérieur).
     */
    public function index(Request $request)
    {
        $customerId = $request->input('_tenant_customer_id');

        // Dernières commandes livrées (la nouvelle commande n'est pas encore passée)
        $orders = Order::where('customer_id', $customerId)
            ->where('status', 'delivered')
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get(['id', 'created_at']);

        // Bornes des périodes : [début, fin] de chacune (la plus récente d'abord)
        $periods = []; // [[start, end], ...]
        $now = now()->startOfDay()->toDateString();
        $dates = $orders->pluck('created_at')->map(fn ($d) => $d->toDateString())->all();

        if (count($dates) > 0) {
            // Période en cours : dernière commande → aujourd'hui
            $periods[] = [$dates[0], $now];
            // Périodes fermées entre commandes consécutives
            for ($i = 0; $i < count($dates) - 1; $i++) {
                $periods[] = [$dates[$i + 1], $dates[$i]];
            }
            // Fenêtre glissante : au plus 5 consommations (les plus récentes)
            $periods = array_slice($periods, 0, 5);
        }

        $oldestStart = $periods ? end($periods)[0] : null;

        // Toutes les ventes du client depuis la borne la plus ancienne
        $entries = $oldestStart
            ? StockEntry::where('customer_id', $customerId)
                ->where('source', 'client')
                ->where('entry_type', 'declared')
                ->whereDate('entry_date', '>=', $oldestStart)
                ->get(['customer_product_id', 'quantity', 'entry_date'])
            : collect();

        $products = CustomerProduct::with('product')
            ->forCustomer($customerId)
            ->where('is_active', true)
            ->get();

        $suggestions = $products->map(function ($cp) use ($entries, $periods) {
            $cpEntries = $entries->where('customer_product_id', $cp->id);

            $consumptions = [];
            foreach ($periods as [$start, $end]) {
                $consumptions[] = floatval($cpEntries->where('entry_date', '>', $start)
                    ->where('entry_date', '<=', $end)
                    ->sum('quantity'));
            }
            // Période en cours sans vente à zéro inclus (la période compte quand même) :
            // on garde toutes les consommations des périodes disponibles.

            $count = count($consumptions);
            $average = $count > 0 ? array_sum($consumptions) / $count : 0;
            $forecast = $average * 1.2;
            $current = floatval($cp->current_stock);
            $suggested = max(0, (int) ceil($forecast - $current));

            return [
                'customer_product_id' => $cp->id,
                'product_id' => $cp->product_id,
                'product_name' => $cp->product?->name ?? '—',
                'unit' => $cp->product?->unit ?? '',
                'current_stock' => $current,
                'average_consumption' => round($average, 2),
                'forecast' => round($forecast, 2),
                'consumption_count' => $count,
                'suggested_quantity' => $suggested,
                'stock_insufficient' => $count > 0 && $current < $forecast,
            ];
        })->values();

        return response()->json(['suggestions' => $suggestions]);
    }
}
