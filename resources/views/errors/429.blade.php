{{--
    429 — Too Many Requests.

    The one backstop for every rate limit that has no page of its own. The case that prompted it: the
    signing page's ID-number check (/sign/{token}/verify) allows 5 tries a minute per IP, and the 6th used to
    land on the framework's bare "429 TOO MANY REQUESTS". A person who mistyped an ID number must be told, in
    words, that nothing is wrong with their link and to wait a minute. Routes whose limit already renders its
    own page (the rental application gates) never reach this view.

    ONE standalone page for everyone, signed in or not — deliberately no conditional @extends (see the leak
    described in errors/404.blade.php). No agency branding: by the time a limiter trips there is no resolved record to read an agency from,
    and guessing could show one agency's name to another's client — same rule as 404-guest.blade.php.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Too many tries — please wait a minute</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f4f6fb; color: #111827; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1.5rem; }
        .box { text-align: center; max-width: 30rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 2.5rem 2rem; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.75rem; }
        p { font-size: 0.95rem; color: #4b5563; margin: 0 0 1.5rem; line-height: 1.6; }
        a.btn { font-size: 0.9rem; font-weight: 600; padding: 0.65rem 1.25rem; border-radius: 0.5rem; text-decoration: none; display: inline-block; background: #0b2a4a; color: #fff; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Too many tries — please wait a minute</h1>
        <p>
            You have tried that a few times in a row, so this page has paused for a minute to keep the document safe.
            Your link is fine and nothing was lost. Please wait about a minute, check the details you are typing, and try again.
        </p>
        <a class="btn" href="{{ url()->previous() }}">Go back and try again</a>
    </div>
</body>
</html>
