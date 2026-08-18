<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600,700" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(0deg, rgb(255, 255, 255) 0%, rgb(176, 182, 252) 100%);
        }
        .card {
            background: #fff;
            padding: 2.5em 3em;
            border-radius: 6px;
            box-shadow: 0 0 10px rgba(15, 15, 15, 0.15);
            text-align: center;
        }
        .card h1 { color: #46699d; margin-bottom: 0.3em; }
        .card p { color: #555; }
        .card form { margin-top: 1.5em; }
        .card button {
            border: none;
            outline: none;
            cursor: pointer;
            color: #fff;
            background: #fc8213;
            border-radius: 3px;
            padding: 0.6em 1.5em;
            font-size: 1em;
            font-family: 'Poppins', sans-serif;
        }
        .card button:hover { background: #46699d; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Welcome{{ auth()->user() ? ', ' . auth()->user()->name : '' }}!</h1>
        <p>You're logged in.</p>
        <form method="POST" action="{{ route('login.logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>
</body>
</html>
