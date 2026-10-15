<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStoreEmailSettingsRequest;
use App\Mail\ReusableEmail;
use App\Models\StoreEmailSetting;
use App\Services\MailDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreEmailSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.email-notifications', [
            'settings' => $request->user()->storeEmailSetting,
        ]);
    }

    public function update(UpdateStoreEmailSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $settings = $request->user()->storeEmailSetting ?? new StoreEmailSetting;

        $settings->fill([
            'store_name' => $validated['store_name'],
            'sender_email' => $validated['sender_email'],
        ]);

        if (filled($validated['app_password'] ?? null)) {
            $settings->smtp_app_password = $validated['app_password'];
        }

        $request->user()->storeEmailSetting()->save($settings);

        return redirect()
            ->route('email-settings.edit')
            ->with('status', 'Your email settings have been saved.');
    }

    public function test(Request $request, MailDeliveryService $mailDeliveryService): RedirectResponse
    {
        $settings = $request->user()->storeEmailSetting;
        $appPassword = $settings?->appPassword();

        if ($appPassword === null) {
            return redirect()
                ->route('email-settings.edit')
                ->withErrors([
                    'email_test' => 'Save your Gmail address and App Password before sending a test email.',
                ]);
        }

        $sent = $mailDeliveryService->sendWithGmail(
            new ReusableEmail(
                'Your TindahanLedger email connection test',
                'Hello!',
                'Your store email settings are working. This test email was sent to confirm your Gmail connection.',
                'You can return to TindahanLedger and continue managing your store.',
                $settings->store_name,
            ),
            $settings->sender_email,
            $settings->sender_email,
            $settings->store_name,
            $appPassword,
            'settings_test',
        );

        if (! $sent) {
            return redirect()
                ->route('email-settings.edit')
                ->withErrors([
                    'email_test' => 'We could not send the test email. Check your Gmail address and App Password, then try again.',
                ]);
        }

        return redirect()
            ->route('email-settings.edit')
            ->with('status', 'Connected successfully! We sent a test email to your Gmail inbox. Check Spam if it is not there.');
    }
}
