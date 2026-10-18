    </div>
    <div class="footer">
        {{ $footer['name'] ?? $agencyName }}@if(!empty($footer['phone'])) · {{ $footer['phone'] }}@endif @if(!empty($footer['email']))· {{ $footer['email'] }}@endif
    </div>
</div>
</body>
</html>
