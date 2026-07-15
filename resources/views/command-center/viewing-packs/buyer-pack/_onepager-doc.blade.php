<!DOCTYPE html>
<html lang="en">
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    {{-- Viewing-pack per-property ONE-PAGER document wrapper. Mirrors property.blade
         but renders the agent-consensus one-pager (spaces + features, no description).
         To make this the LIVE pack layout, point buyer-pack/property.blade.php's
         brochure branch at ._onepager instead of corex.properties._brochure. --}}
    @include('command-center.viewing-packs.buyer-pack._onepager', ['b' => $b, 'vpNotes' => $vpNotes ?? null, 'mode' => $mode ?? 'buyer', 'featuresAll' => $featuresAll ?? null])
</body>
</html>
