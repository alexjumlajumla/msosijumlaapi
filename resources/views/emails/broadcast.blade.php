<html>
<head>
    <meta charset="utf-8" />
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color:#f5f5f5; padding:20px;">
    <table align="center" width="600" style="background:#ffffff; padding:20px; border-radius:8px;">
        <tr>
            <td style="text-align:center;">
                <h2>{{ $title }}</h2>
                <p style="font-size:16px; line-height:1.5;">{!! nl2br(e($body)) !!}</p>
            </td>
        </tr>
        <tr>
            <td style="text-align:center; font-size:12px; color:#888; padding-top:30px;">
                © {{ date('Y') }} {{ config('app.name') }}
            </td>
        </tr>
    </table>
</body>
</html> 