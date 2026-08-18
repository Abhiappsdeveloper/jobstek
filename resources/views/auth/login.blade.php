<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Client Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="data:image/x-icon;," type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600,700" rel="stylesheet">
    <style>
        :root {
            --accent-orange: #fc8213;
            --accent-blue: #46699d;
            --text-dark: #333;
            --input-bg: #f5f5f5;
            --input-border: #d3d3d3;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3.5em 1em;
            background: linear-gradient(0deg, rgb(255, 255, 255) 0%, rgb(176, 182, 252) 100%);
        }
        .login-main {
            width: 100%;
            max-width: 420px;
            background: #fff;
            box-shadow: 0px 0px 2px 1px rgba(0, 0, 0, 0.15);
        }
        .login-block {
            padding: 3em 2em;
            box-shadow: 0 0 10px rgba(15, 15, 15, 0.35);
            background: linear-gradient(302deg, rgba(8,9,56,1) 0%, rgba(152,48,137,1) 0%, rgba(10,0,126,0.9) 100%);
        }
        .login-block .colr {
            text-align: center;
            margin-bottom: 1.5em;
        }
        .login-block .colr img {
            max-width: 160px;
            margin-bottom: 1em;
        }
        .login-block h1 {
            color: #fff;
            font-size: 1.6em;
            letter-spacing: 1px;
            margin: 0;
            font-family: 'Poppins', sans-serif;
        }
        .login-block input[type="text"],
        .login-block input[type="email"],
        .login-block input[type="password"] {
            font-size: 0.9em;
            padding: 10px 20px;
            width: 100%;
            color: #000;
            outline: none;
            border: 1px solid var(--input-border);
            border-radius: 5px;
            background: var(--input-bg);
            margin: 0 0 1.2em 0;
            font-family: 'Poppins', sans-serif;
        }
        .login-block input[type="submit"] {
            border: none;
            outline: none;
            cursor: pointer;
            color: #fff;
            background: #ff5151;
            width: 100%;
            border-radius: 3px;
            padding: 0.7em 1em;
            font-size: 1em;
            display: block;
            font-family: 'Poppins', sans-serif;
            transition: 0.3s all;
        }
        .login-block input[type="submit"]:hover {
            background: var(--accent-blue);
        }
        .forgot-top-grids {
            margin: 0 0 1.2em 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .forgot-grid ul {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .forgot-grid ul li {
            list-style: none;
            display: inline-block;
        }
        .forgot-grid ul li input[type="checkbox"] {
            display: none;
        }
        .forgot-grid ul li input[type="checkbox"] + label {
            position: relative;
            padding-left: 22px;
            color: var(--accent-orange);
            display: inline-block;
            cursor: pointer;
            font-weight: 400;
            font-size: 0.9em;
        }
        .forgot-grid ul li input[type="checkbox"] + label span {
            width: 14px;
            height: 14px;
            display: inline-block;
            border: 2px solid var(--accent-orange);
            position: absolute;
            left: 0;
            bottom: 2px;
        }
        .forgot-grid ul li input[type="checkbox"]:checked + label span:before {
            content: "";
            position: absolute;
            left: 2px;
            top: -1px;
            width: 5px;
            height: 9px;
            border: solid var(--accent-orange);
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .forgot {
            float: right;
        }
        .forgot a {
            color: var(--accent-orange);
            font-size: 0.9em;
            display: block;
            text-decoration: none;
        }
        .forgot a:hover {
            color: var(--accent-blue);
        }
        .error-message {
            color: #ff8a8a;
            font-size: 0.85em;
            display: block;
            margin: -0.6em 0 1em 0;
        }
        .login-block h5 {
            font-size: 1em;
            text-align: right;
            margin-top: 1em;
            font-family: 'Poppins', sans-serif;
        }
        .login-block h5 a {
            color: #ededed;
            text-decoration: underline;
        }
        .login-block h5 a:hover {
            color: var(--accent-blue);
        }
        .status-message {
            background: #eafbea;
            color: #3c763d;
            padding: 0.7em 1em;
            font-size: 0.85em;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-main">
        @if (session('status'))
            <div class="status-message">{{ session('status') }}</div>
        @endif
        <div class="login-block">
            <div class="colr">
                <img src="{{ asset('logo-light.svg') }}" alt="logo">
                <h1>CLIENT LOGIN</h1>
            </div>
            <form id="loginForm" method="POST" action="{{ route('login.submit') }}" autocomplete="off">
                @csrf
                <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required autofocus>
                @error('email')
                    <span class="error-message">{{ $message }}</span>
                @enderror

                <input type="password" name="password" placeholder="Password" required>
                @error('password')
                    <span class="error-message">{{ $message }}</span>
                @enderror

                <div class="forgot-top-grids">
                    <div class="forgot-grid">
                        <ul>
                            <li>
                                <input type="checkbox" id="remember" name="remember">
                                <label for="remember"><span></span>Remember me</label>
                            </li>
                        </ul>
                    </div>
                    <div class="forgot">
                        <a href="#">Forgot password?</a>
                    </div>
                    <div style="clear:both;"></div>
                </div>

                <input type="submit" value="Login">
                <span id="err_login" style="color:#ff8a8a;display:block;margin-top:0.8em;text-align:center;"></span>
            </form>
            <h5><a href="#">Signup</a></h5>
            <h5><a href="{{ url('/') }}">Go Back to Home</a></h5>
        </div>
    </div>
</body>
</html>
