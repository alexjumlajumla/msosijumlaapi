<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #4CAF50;
            color: #ffffff;
            text-align: center;
            padding: 15px;
        }
        .content {
            padding: 20px;
        }
        .footer {
            text-align: center;
            padding: 10px;
            font-size: 12px;
            color: #777777;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Order Notification</h1>
        </div>
        <div class="content">
            <p>Dear {{ $order->user?->firstname }},</p>
            <p>{{ $message }}</p>
            <p><strong>Order ID:</strong> {{ $order->id }}</p>
            <p><strong>Total:</strong> ${{ $order->total_price }}</p>
            <p>Thank you for your business!</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Msosi JumlaJumla. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
