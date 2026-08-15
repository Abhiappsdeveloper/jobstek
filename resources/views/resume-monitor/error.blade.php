<!DOCTYPE html>
<html>
<head>
    <title>Error - Monitor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-box {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }
        .error-box h1 { color: #dc3545; }
        .error-box p { color: #666; margin: 10px 0; }
        .error-box a { color: #667eea; text-decoration: none; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>⚠️ Error</h1>
        <p>{{ $message ?? 'An error occurred' }}</p>
        <a href="/">Go Home</a>
    </div>
</body>
</html>
