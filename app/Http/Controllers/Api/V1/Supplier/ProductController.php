<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Supplier;
use App\Notifications\ProductNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = Supplier::find($supplierId);

        // Total déjà attribué aux clients (rattachements + MAJ livraisons)
        $distributed = CustomerProduct::where('supplier_id', $supplierId)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(initial_stock), 0) as total')
            ->pluck('total', 'product_id');

        $products = Product::forSupplier($supplierId)
            ->orderBy('name')
            ->get()
            ->map(function ($p) use ($distributed) {
                // Reste distribuable : stock entrepôt − total déjà donné aux clients
                $remaining = floatval($p->stock_quantity ?? 0) - floatval($distributed[$p->id] ?? 0);
                $p->remaining_stock = max(0, $remaining);
                return $p;
            });

        return response()->json([
            'products' => $products,
            // Même devise que les forfaits (affichage des prix)
            'currency' => [
                'code' => $supplier?->plan?->currency ?? 'EUR',
                'symbol' => $supplier?->plan?->currency_symbol ?? '€',
                'position' => $supplier?->plan?->currency_position ?? 'after',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = \App\Models\Supplier::find($supplierId);

        if (!$supplier->canCreateProduct()) {
            $limit = $supplier->plan->max_products;
            return response()->json([
                'message' => "Quota atteint : maximum {$limit} produits (plan {$supplier->plan->name})."
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'unit' => 'required|string|max:30',
            'sku' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0|max:99999999.99',
            'stock_threshold' => 'nullable|numeric|min:0|max:99999999.99',
            'pack_size' => 'nullable|integer|min:2|max:10000',
            'image' => ['nullable', function ($attribute, $value, $fail) {
                if (is_array($value) || !($value instanceof \Illuminate\Http\UploadedFile)) {
                    $fail("L'image n'a pas été envoyée correctement. Rechargez l'application (touche R dans le terminal Expo, ou fermez et rouvrez l'app).");
                    return;
                }
                if ($value->getSize() > 5 * 1024 * 1024) {
                    $fail('La photo ne doit pas dépasser 5 Mo.');
                    return;
                }
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/bmp'];
                if (!in_array($value->getMimeType(), $allowedMimes, true)) {
                    $fail("Le fichier envoyé n'est pas une image valide (JPG/JPEG, PNG, WebP, AVIF, GIF ou BMP acceptés). Si votre fichier est un SVG ou un fichier renommé, convertissez-le d'abord en photo.");
                }
            }],
            'image_base64' => 'nullable|string',
        ]);

        $product = Product::create([
            'supplier_id' => $supplierId,
            'name' => $request->name,
            'unit' => $request->unit,
            'sku' => $request->sku,
            'category' => $request->category,
            'price' => $request->price,
            'stock_threshold' => $request->stock_threshold,
            'pack_size' => $request->pack_size,
        ]);

        // Photo du produit (optionnelle) : fichier multipart ou base64 (mobile)
        try {
            $this->storeProductImage($request, $product);
        } catch (ValidationException $e) {
            // Photo invalide : annuler aussi la création du produit
            $product->forceDelete();
            throw $e;
        }

        // Produit créé avec un seuil (stock 0) : alerte immédiate
        if ($product->stock_threshold !== null) {
            DB::transaction(function () use ($product) {
                $locked = Product::where('id', $product->id)->lockForUpdate()->first();
                StockAlert::syncWarehouseAlert($locked);
            });
        }

        // Nouveau produit au catalogue (avec prix) : notifier tous les clients
        if ($request->price !== null) {
            Supplier::notifyClients($supplier, new ProductNotification(
                'product_created',
                $product->id,
                'Nouveau produit au catalogue',
                "Le produit « {$product->name} » est disponible au prix de {$this->formatPrice($supplier, $product->price)}.",
            ));
        }

        return response()->json($product, 201);
    }

    public function show(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $product = Product::forSupplier($supplierId)->findOrFail($id);

        return response()->json($product);
    }

    /**
     * Import groupé de produits (fichier Excel/CSV analysé côté client).
     * Chaque ligne est validée indépendamment : les erreurs sont renvoyées
     * sans bloquer l'import des lignes valides.
     */
    public function bulkStore(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = \App\Models\Supplier::find($supplierId);

        $request->validate([
            'products' => 'required|array|min:1|max:500',
            // Le nom est validé ligne par ligne : une ligne sans nom devient
            // une erreur listée sans bloquer l'import des autres lignes.
            'products.*.name' => 'nullable|string|max:191',
            'products.*.unit' => 'nullable|string|max:30',
            'products.*.sku' => 'nullable|string|max:100',
            'products.*.category' => 'nullable|string|max:100',
            'products.*.price' => 'nullable|numeric|min:0|max:99999999.99',
            'products.*.stock_threshold' => 'nullable|numeric|min:0|max:99999999.99',
            'products.*.pack_size' => 'nullable|integer|min:2|max:10000',
        ]);

        $maxProducts = $supplier->plan?->max_products;
        $currentCount = Product::forSupplier($supplierId)->count();
        $remainingQuota = $maxProducts === null ? null : max(0, $maxProducts - $currentCount);

        if ($remainingQuota !== null && $remainingQuota <= 0) {
            return response()->json([
                'message' => "Quota atteint : maximum {$maxProducts} produits (plan {$supplier->plan->name}).",
            ], 403);
        }

        $imported = 0;
        $errors = [];
        $importedProducts = [];

        foreach ($request->products as $index => $row) {
            $line = $index + 1;
            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $errors[] = ['line' => $line, 'message' => 'Nom manquant.'];
                continue;
            }

            // Doublon dans la base : ignorer la ligne (pas bloquant)
            if (Product::forSupplier($supplierId)->where('name', $name)->exists()) {
                $errors[] = ['line' => $line, 'message' => "« {$name} » existe déjà — ligne ignorée."];
                continue;
            }

            // Quota : ne pas dépasser
            if ($remainingQuota !== null && $imported >= $remainingQuota) {
                $errors[] = ['line' => $line, 'message' => "Quota atteint : maximum {$maxProducts} produits."];
                continue;
            }

            $product = Product::create([
                'supplier_id' => $supplierId,
                'name' => $name,
                'unit' => trim($row['unit'] ?? '') ?: 'pièce',
                'sku' => $row['sku'] ?? null,
                'category' => $row['category'] ?? null,
                'price' => $row['price'] ?? null,
                'stock_threshold' => $row['stock_threshold'] ?? null,
                'pack_size' => $row['pack_size'] ?? null,
            ]);

            $imported++;
            $importedProducts[] = $product;

            // Seuil renseigné : alerte entrepôt immédiate (stock 0)
            if ($product->stock_threshold !== null) {
                DB::transaction(function () use ($product) {
                    $locked = Product::where('id', $product->id)->lockForUpdate()->first();
                    StockAlert::syncWarehouseAlert($locked);
                });
            }

            // Nouveau produit au catalogue (avec prix) : notifier les clients
            if ($product->price !== null) {
                Supplier::notifyClients($supplier, new ProductNotification(
                    'product_created',
                    $product->id,
                    'Nouveau produit au catalogue',
                    "Le produit « {$product->name} » est disponible au prix de {$this->formatPrice($supplier, $product->price)}.",
                ));
            }
        }

        return response()->json([
            'message' => "{$imported} produit(s) importé(s).",
            'imported' => $imported,
            'errors' => $errors,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = \App\Models\Supplier::find($supplierId);
        $product = Product::forSupplier($supplierId)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:191',
            'unit' => 'sometimes|string|max:30',
            'sku' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0|max:99999999.99',
            'stock_threshold' => 'nullable|numeric|min:0|max:99999999.99',
            'pack_size' => 'nullable|integer|min:2|max:10000',
            'is_active' => 'sometimes|boolean',
            'image' => ['nullable', function ($attribute, $value, $fail) {
                if (is_array($value) || !($value instanceof \Illuminate\Http\UploadedFile)) {
                    $fail("L'image n'a pas été envoyée correctement. Rechargez l'application (touche R dans le terminal Expo, ou fermez et rouvrez l'app).");
                    return;
                }
                if ($value->getSize() > 5 * 1024 * 1024) {
                    $fail('La photo ne doit pas dépasser 5 Mo.');
                    return;
                }
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/bmp'];
                if (!in_array($value->getMimeType(), $allowedMimes, true)) {
                    $fail("Le fichier envoyé n'est pas une image valide (JPG/JPEG, PNG, WebP, AVIF, GIF ou BMP acceptés). Si votre fichier est un SVG ou un fichier renommé, convertissez-le d'abord en photo.");
                }
            }],
            'image_base64' => 'nullable|string',
        ]);

        // Comparaison stricte : null distinct de 0, et 5 == 5.00 (floatval)
        $oldPrice = $product->price === null ? null : floatval($product->price);
        $newPrice = $request->input('price') === null ? null : floatval($request->input('price'));
        $priceChanged = $oldPrice !== $newPrice;

        $oldThreshold = $product->stock_threshold === null ? null : floatval($product->stock_threshold);
        $newThreshold = $request->input('stock_threshold') === null ? null : floatval($request->input('stock_threshold'));
        $thresholdChanged = $oldThreshold !== $newThreshold;

        $product->update($request->only(['name', 'unit', 'sku', 'category', 'price', 'stock_threshold', 'pack_size', 'is_active']));

        // Nouvelle photo : remplacer l'ancienne
        $this->storeProductImage($request, $product);

        // Seuil modifié : recalculer l'alerte entrepôt
        if ($thresholdChanged) {
            DB::transaction(function () use ($product) {
                $locked = Product::where('id', $product->id)->lockForUpdate()->first();
                StockAlert::syncWarehouseAlert($locked);
            });
        }

        // Changement de prix sur un produit visible : notifier tous les clients
        if ($priceChanged && $product->is_active) {
            $symbol = $supplier?->plan?->currency_symbol ?? '€';
            $position = $supplier?->plan?->currency_position ?? 'after';

            if ($newPrice === null) {
                $message = "Le prix de « {$product->name} » a été retiré du catalogue.";
            } elseif ($oldPrice === null) {
                $message = "Le prix de « {$product->name} » est maintenant de {$this->formatPrice($supplier, $newPrice)}.";
            } else {
                $message = "Le prix de « {$product->name} » passe de "
                    . ProductNotification::formatMoney($oldPrice, $symbol, $position)
                    . ' à ' . ProductNotification::formatMoney($newPrice, $symbol, $position) . '.';
            }

            Supplier::notifyClients($supplier, new ProductNotification(
                'price_updated',
                $product->id,
                "Prix mis à jour : {$product->name}",
                $message,
            ));
        }

        return response()->json($product);
    }

    /**
     * Stocke la photo du produit : fichier multipart (web) ou base64 (mobile).
     * Remplace l'ancienne photo si elle existe.
     */
    private function storeProductImage(Request $request, Product $product): void
    {
        // 1) Fichier multipart classique (site web)
        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('products')->delete($product->image_path);
            }
            $path = Storage::disk('products')->putFile('products', $request->file('image'));
            $product->update(['image_path' => $path]);
            return;
        }

        // 2) Base64 (application mobile) — évite les problèmes d'envoi multipart
        if (!$request->filled('image_base64')) {
            return;
        }

        $data = $request->input('image_base64');
        // Préfixe éventuel "data:image/jpeg;base64,"
        if (str_contains($data, ',')) {
            $data = explode(',', $data, 2)[1];
        }

        $bin = base64_decode($data, true);
        if ($bin === false || strlen($bin) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image_base64' => ['Image invalide ou trop volumineuse (max 5 Mo).'],
            ]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
        ];
        if (!isset($extensions[$mime])) {
            throw ValidationException::withMessages([
                'image_base64' => ["Le fichier envoyé n'est pas une image valide (JPG/JPEG, PNG, WebP, AVIF, GIF ou BMP acceptés). Si votre fichier est un SVG ou un fichier renommé, convertissez-le d'abord en photo."],
            ]);
        }

        if ($product->image_path) {
            Storage::disk('products')->delete($product->image_path);
        }
        $path = 'products/' . uniqid('', true) . '.' . $extensions[$mime];
        Storage::disk('products')->put($path, $bin);
        $product->update(['image_path' => $path]);
    }

    /**
     * Formatage du prix avec la monnaie du forfait fournisseur.
     */
    private function formatPrice(?Supplier $supplier, $price): string
    {
        return ProductNotification::formatMoney(
            $price === null ? null : floatval($price),
            $supplier?->plan?->currency_symbol ?? '€',
            $supplier?->plan?->currency_position ?? 'after',
        );
    }

    public function destroy(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $product = Product::forSupplier($supplierId)->findOrFail($id);

        // Résoudre les alertes entrepôt ouvertes pour éviter des alertes fantômes
        StockAlert::where('product_id', $product->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        // Supprimer la photo du produit
        if ($product->image_path) {
            Storage::disk('products')->delete($product->image_path);
        }

        $product->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }
}
