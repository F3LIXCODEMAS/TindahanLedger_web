<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccessCredential;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;

/**
 * Issues, replaces, revokes, and resolves customer portal QR credentials.
 *
 * A credential is a bearer secret: whoever holds the QR code can open that
 * customer's read-only ledger. It proves possession of the code, not the
 * identity of the person holding it.
 */
class CustomerAccessService
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    /**
     * 32 random bytes encoded as 43 URL-safe characters.
     */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Issue a new credential, revoking any active one in the same
     * transaction so the old QR code stops working immediately.
     */
    public function issue(Customer $customer, ?User $actor = null): CustomerAccessCredential
    {
        return DB::transaction(function () use ($customer, $actor): CustomerAccessCredential {
            Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
            $this->revokeActive($customer, $actor);

            $token = self::generateToken();
            $credential = new CustomerAccessCredential;
            $credential->forceFill([
                'customer_id' => $customer->getKey(),
                'active_customer_id' => $customer->getKey(),
                'token_hash' => CustomerAccessCredential::hashToken($token),
                'encrypted_token' => $token,
                'status' => CustomerAccessCredential::STATUS_ACTIVE,
                'created_by_user_id' => $actor?->getKey(),
            ])->save();

            $customer->setRelation('activeAccessCredential', $credential);

            return $credential;
        });
    }

    /**
     * Revoke the customer's active credential, if any. Customer records and
     * financial history are never touched.
     */
    public function revoke(Customer $customer, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($customer, $actor): bool {
            Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
            $revoked = $this->revokeActive($customer, $actor);
            $customer->setRelation('activeAccessCredential', null);

            return $revoked;
        });
    }

    /**
     * Find the active credential for a scanned token. Malformed, unknown, and
     * revoked tokens all return null so callers cannot tell them apart.
     */
    public function resolve(string $token): ?CustomerAccessCredential
    {
        if (! preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        return CustomerAccessCredential::query()
            ->with('customer.store')
            ->where('token_hash', CustomerAccessCredential::hashToken($token))
            ->where('status', CustomerAccessCredential::STATUS_ACTIVE)
            ->first();
    }

    /**
     * The URL encoded in the QR code. The token sits in the fragment, which
     * browsers never send to the server, so it cannot appear in web server
     * access logs or referrer headers.
     */
    public function portalUrl(CustomerAccessCredential $credential): ?string
    {
        $token = $credential->plainToken();

        return $token === null ? null : route('portal.entry').'#'.$token;
    }

    public function qrSvg(CustomerAccessCredential $credential, int $size = 320): ?string
    {
        $portalUrl = $this->portalUrl($credential);

        if ($portalUrl === null) {
            return null;
        }

        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd));

        return $writer->writeString($portalUrl);
    }

    private function revokeActive(Customer $customer, ?User $actor): bool
    {
        return CustomerAccessCredential::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', CustomerAccessCredential::STATUS_ACTIVE)
            ->update([
                'status' => CustomerAccessCredential::STATUS_REVOKED,
                'active_customer_id' => null,
                'encrypted_token' => null,
                'revoked_by_user_id' => $actor?->getKey(),
                'revoked_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }
}
