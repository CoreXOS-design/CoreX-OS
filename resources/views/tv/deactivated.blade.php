<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TV Display — Deactivated</title>
<meta http-equiv="refresh" content="60">
<style>
html, body {
    margin: 0;
    height: 100%;
    background: #0b2a4a;
    color: #fff;
    font-family: Inter, system-ui, -apple-system, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
}
* { box-sizing: border-box; }

.card {
    background: #fff;
    border-radius: 20px;
    padding: 3rem 2.5rem;
    text-align: center;
    max-width: 520px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.3);
}

.logo {
    font-size: 1.5rem;
    font-weight: 900;
    color: #0b2a4a;
    margin-bottom: 2rem;
}

.icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

.title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0b2a4a;
    margin-bottom: 0.75rem;
}

.message {
    font-size: 1rem;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 2rem;
}

.btn {
    display: inline-block;
    padding: 0.875rem 2rem;
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
    background: #0b2a4a;
    border: none;
    border-radius: 12px;
    text-decoration: none;
    cursor: pointer;
}

.btn:hover { background: #164e7a; }
</style>
</head>
<body>

<div class="card">
    <div class="logo">{{ config('app.name') }}</div>
    <div class="icon">&#128274;</div>
    <div class="title">Display Deactivated</div>
    <div class="message">
        This TV display code has been revoked or expired.<br>
        Please contact your branch manager for a new code.
    </div>
    <a href="{{ route('tv.index') }}" class="btn">Enter New Code</a>
</div>

</body>
</html>
