@component('mail::message')
# Good morning, {{ $user->name }}

Here's where your deals stand this morning.

@if(!empty($sections['overdue']))
## ⛔ Overdue steps
@component('mail::table')
| Deal | Step | Due |
| :--- | :--- | :--- |
@foreach($sections['overdue'] as $r)
| {{ $r['deal'] }} | {{ $r['step'] }} | {{ $r['due'] ?? '—' }} |
@endforeach
@endcomponent
@endif

@if(!empty($sections['due_today']))
## 📅 Due today
@component('mail::table')
| Deal | Step |
| :--- | :--- |
@foreach($sections['due_today'] as $r)
| {{ $r['deal'] }} | {{ $r['step'] }} |
@endforeach
@endcomponent
@endif

@if(!empty($sections['amber_red']))
## 🟠 Turning amber / red
@component('mail::table')
| Deal | Step | Status |
| :--- | :--- | :--- |
@foreach($sections['amber_red'] as $r)
| {{ $r['deal'] }} | {{ $r['step'] }} | {{ ucfirst($r['rag']) }} |
@endforeach
@endcomponent
@endif

@if(!empty($sections['registered_yesterday']))
## ✅ Registered yesterday
@foreach($sections['registered_yesterday'] as $r)
- {{ $r['deal'] }}
@endforeach
@endif

@component('mail::button', ['url' => url('/deals-v2')])
Open Deal Register
@endcomponent

This is your automated CoreX morning digest.
@endcomponent
