<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TV Display — {{ config('app.name') }}</title>
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

.container {
    width: 100%;
    max-width: 480px;
    padding: 2rem;
}

.card {
    background: #fff;
    border-radius: 20px;
    padding: 3rem 2.5rem;
    text-align: center;
    box-shadow: 0 25px 50px rgba(0,0,0,0.3);
}

.logo {
    font-size: 1.5rem;
    font-weight: 900;
    color: #0b2a4a;
    margin-bottom: 0.5rem;
}

.logo-sub {
    font-size: 0.875rem;
    color: #64748b;
    margin-bottom: 2.5rem;
}

.label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #0b2a4a;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 1rem;
}

.code-inputs {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-bottom: 1.5rem;
}

.code-inputs input {
    width: 56px;
    height: 72px;
    text-align: center;
    font-size: 2rem;
    font-weight: 900;
    color: #0b2a4a;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    outline: none;
    transition: border-color 0.2s;
}

.code-inputs input:focus {
    border-color: #0b2a4a;
    background: #fff;
}

.code-inputs input.error {
    border-color: #dc2626;
    background: #fef2f2;
}

.btn {
    width: 100%;
    padding: 1rem;
    font-size: 1.125rem;
    font-weight: 700;
    color: #fff;
    background: #0b2a4a;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: background 0.2s;
}

.btn:hover { background: #164e7a; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.error-msg {
    color: #dc2626;
    font-size: 0.875rem;
    font-weight: 600;
    margin-top: 1rem;
}
</style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="logo">{{ config('app.name') }}</div>
        <div class="logo-sub">Branch TV Display</div>

        <div class="label">Enter 6-digit code</div>

        <form method="POST" action="{{ route('tv.verify') }}" id="codeForm">
            @csrf
            <input type="hidden" name="code" id="codeHidden" value="">

            <div class="code-inputs" id="codeInputs">
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" data-index="0">
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" data-index="1">
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" data-index="2">
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" data-index="3">
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" data-index="4">
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" data-index="5">
            </div>

            <button type="submit" class="btn" id="submitBtn" disabled>View Display</button>
        </form>

        @if($errors->has('code'))
            <div class="error-msg">{{ $errors->first('code') }}</div>
        @endif
    </div>
</div>

<script>
const inputs = document.querySelectorAll('#codeInputs input');
const hidden = document.getElementById('codeHidden');
const btn = document.getElementById('submitBtn');

function updateCode() {
    let code = '';
    inputs.forEach(i => code += i.value);
    hidden.value = code;
    btn.disabled = code.length !== 6;
}

inputs.forEach((input, idx) => {
    input.addEventListener('input', (e) => {
        // Only allow digits
        input.value = input.value.replace(/\D/g, '').slice(0, 1);
        if (input.value && idx < 5) {
            inputs[idx + 1].focus();
        }
        updateCode();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !input.value && idx > 0) {
            inputs[idx - 1].focus();
        }
    });

    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
        for (let i = 0; i < 6; i++) {
            inputs[i].value = pasted[i] || '';
        }
        if (pasted.length > 0) {
            inputs[Math.min(pasted.length, 5)].focus();
        }
        updateCode();
    });
});

// Auto-focus first input
inputs[0].focus();

// If there was an old code value, restore it
@if(old('code'))
    const old = '{{ old('code') }}';
    for (let i = 0; i < 6 && i < old.length; i++) {
        inputs[i].value = old[i];
        inputs[i].classList.add('error');
    }
    updateCode();
@endif
</script>

</body>
</html>
