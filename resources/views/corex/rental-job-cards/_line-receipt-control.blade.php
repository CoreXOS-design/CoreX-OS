{{--
    §17.43 - the receipt for a PART line on the office job card: the same two choices the crew link has ("Take photo" / "Choose from
    phone"), the same field names (receipt_camera / receipt_file), the same storage (RentalJobCardService::attachLineReceipt - the crew
    path calls it too), shown as the same small thumbnail afterwards (_receipt-thumb). Hidden while the line's Type is Labour (labour has
    no receipt); the page script (show.blade.php) toggles it when the Type changes.

    Receives $labour (bool - the line is Labour right now).
--}}
<span class="jc-receipt" data-receipt-control @if($labour) hidden @endif>
    <span class="jc-foot-label">Receipt</span>
    <label class="corex-btn-outline text-xs jc-filebtn"><span>Take photo</span><input type="file" name="receipt_camera" class="jc-file-hidden" accept="image/*" capture="environment"></label>
    <label class="corex-btn-outline text-xs jc-filebtn"><span>Choose from phone</span><input type="file" name="receipt_file" class="jc-file-hidden" accept="image/*"></label>
</span>
