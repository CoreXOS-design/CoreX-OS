<input type="hidden" name="property_name" value="{{ $input['property_name'] }}">
<input type="hidden" name="deposit_amount" value="{{ $input['deposit_amount'] }}">
<input type="hidden" name="invest_date" value="{{ $input['invest_date'] }}">
<input type="hidden" name="refund_date" value="{{ $input['refund_date'] }}">
@if(!empty($input['topups']))
    @foreach($input['topups'] as $i => $topup)
        <input type="hidden" name="topups[{{ $i }}][date]" value="{{ $topup['date'] }}">
        <input type="hidden" name="topups[{{ $i }}][amount]" value="{{ $topup['amount'] }}">
    @endforeach
@endif
