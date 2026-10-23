<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('decides product access by store ownership', function (Closure $makeUser, bool $isAllowed) {
    $product = Product::factory()->create();

    $response = Gate::forUser($makeUser($product))->inspect('update', $product);

    expect($response->allowed())->toBe($isAllowed);
    expect($response->status())->toBe($isAllowed ? null : 404);
})->with([
    'owner of the product store' => [fn (Product $product) => $product->store->owner, true],
    'owner of another store' => [fn () => User::factory()->withStore()->create(), false],
    'owner without a store' => [fn () => User::factory()->create(), false],
    'super admin' => [fn () => User::factory()->superAdmin()->create(), false],
]);
