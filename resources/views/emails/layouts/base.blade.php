<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }}</title>
</head>
<body style="margin: 0; padding: 24px 12px; background-color: #f4f7f5; color: #24312b; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; border-collapse: collapse; background-color: #ffffff; border: 1px solid #e0e8e3; border-radius: 8px;">
                    <tr>
                        <td style="padding: 24px 28px; background-color: #176b45; color: #ffffff; font-size: 20px; font-weight: bold;">
                            {{ $appName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px; font-size: 16px; line-height: 1.6;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px; border-top: 1px solid #e0e8e3; color: #637169; font-size: 12px; line-height: 1.5;">
                            This is an automated message from {{ $appName }}. Please do not reply to this email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
