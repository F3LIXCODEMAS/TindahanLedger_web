<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProductRequest;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
        ]);
        $search = Str::squish((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? '';

        $products = $request->user()->store->products()
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->whereNameContains($search))
            ->orderBy('normalized_name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('products.create', ['product' => new Product]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        try {
            $request->user()->store->products()->create($request->safe()->only([
                'name',
                'unit_price',
                'description',
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateNameResponse();
        }

        return redirect()->route('products.index')->with('status', 'Product added successfully.');
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.edit', ['product' => $product]);
    }

    /**
     * Renaming or repricing keeps the same product record, so anything that
     * later references it by ID stays linked.
     */
    public function update(SaveProductRequest $request, Product $product): RedirectResponse
    {
        try {
            $product->update($request->safe()->only([
                'name',
                'unit_price',
                'description',
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateNameResponse();
        }

        return redirect()->route('products.index')->with('status', 'Product updated successfully.');
    }

    /**
     * A concurrent request saved the same name after validation passed; the
     * store-scoped unique index rejected this one.
     */
    private function duplicateNameResponse(): RedirectResponse
    {
        return back()->withInput()->withErrors(['name' => SaveProductRequest::DUPLICATE_NAME_MESSAGE]);
    }
}
