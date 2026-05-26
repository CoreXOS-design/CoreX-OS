echo 'demo: ' . App\Models\CommandCenter\CalendarEvent::withoutGlobalScopes()->where('source_type', 'manual:demo')->count() . PHP_EOL;
foreach (['viewing','valuation','listing_presentation'] as $c) {
  $cfg = App\Models\CommandCenter\CalendarEventClassSetting::forAgencyAndClass(null, $c);
  echo $c . ': ' . ($cfg ? 'config active=' . ($cfg->is_active ? 'yes' : 'no') : 'NO CONFIG') . PHP_EOL;
}
