<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
    <style>
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; font-size: 15px; color: #1e293b; line-height: 1.6; background: #f8fafc; }
        .wrapper { max-width: 560px; margin: 0 auto; background: #ffffff; }
        .header { background: #0f172a; padding: 22px 28px; color: #ffffff; font-size: 17px; font-weight: 700; }
        .body { padding: 28px; }
        .box { background: #f1f5f9; border-radius: 6px; padding: 14px 18px; margin: 16px 0; }
        .banner { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; border-radius: 6px; padding: 12px 16px; margin: 16px 0; font-weight: 600; }
        .term { color: #475569; font-size: 13px; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 20px; }
        .muted { color: #64748b; font-size: 13px; }
        table.sum { width: 100%; border-collapse: collapse; }
        table.sum td { padding: 3px 0; }
        table.sum td.r { text-align: right; white-space: nowrap; }
        .footer { background: #f1f5f9; padding: 16px 28px; font-size: 12px; color: #64748b; }
        a.btn { display: inline-block; background: #0ea5e9; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">{{ $agencyName }}</div>
    <div class="body">
