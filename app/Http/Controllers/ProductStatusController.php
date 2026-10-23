<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProductStatusRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

class ProductStatusController extends Controller
{
    /**
     * Deactivation is reversible and never deletes the product, so any
     * record that references it stays intact.
     */
    public function update(UpdateProductStatusRequest $request, Product $product): RedirectResponse
    {
        $status = $request->validated('status');
        $product->forceFill(['status' => $status])->save();

        $message = $status === Product::STATUS_INACTIVE
            ? "{$product->name} deactivated. It stays in your records but cannot be chosen for new purchases."
            : "{$product->name} reactivated.";

        return redirect()->route('products.index')->with('status', $message);
    }
}
