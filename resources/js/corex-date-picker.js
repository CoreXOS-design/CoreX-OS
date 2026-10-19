// Clicking anywhere on a date / time input opens its picker, not just the little calendar icon.
//
// Browsers only open the native picker from the icon at the field's right edge; a click on the field itself just puts
// the cursor in a day/month/year segment, which reads as "the date picker doesn't work". One delegated listener fixes
// every such input in CoreX, including ones rendered later by Alpine. Opt a field out with data-no-autopicker.
const PICKER_TYPES = ['date', 'datetime-local', 'time', 'month', 'week'];

document.addEventListener('click', (event) => {
    const el = event.target;
    if (!(el instanceof HTMLInputElement) || !PICKER_TYPES.includes(el.type)) {
        return;
    }
    if (el.disabled || el.readOnly || el.hasAttribute('data-no-autopicker') || typeof el.showPicker !== 'function') {
        return;
    }
    try {
        el.showPicker();
    } catch (e) {
        // Picker already open, or the browser refused (no user activation, cross-origin frame) - the field still works by typing.
    }
});
