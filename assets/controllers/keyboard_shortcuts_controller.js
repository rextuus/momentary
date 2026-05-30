import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['unknownBtn', 'container'];

    connect() {
    }

    onKeydown(event) {
        // Stimulus fängt das Event am Window ab. 
        const key = event.key.toLowerCase();

        // Ignoriere Shortcuts, wenn der Benutzer in einem Input-Feld tippt oder eines fokussiert ist
        if (
            event.target.tagName === 'INPUT' || 
            event.target.tagName === 'TEXTAREA' || 
            event.target.tagName === 'SELECT' ||
            event.target.isContentEditable ||
            event.target.closest('.ts-control') // TomSelect support
        ) {
            return;
        }

        if (key === 'u') {
            const btn = document.getElementById('btn-mark-as-unknown');
            if (btn) {
                event.preventDefault();
                btn.classList.add('active');
                setTimeout(() => btn.classList.remove('active'), 200);
                btn.click();
            }
        }

        if (key === 'enter' && (event.ctrlKey || event.metaKey)) {
            const btn = document.getElementById('btn-process-identification');
            if (btn && btn.offsetParent !== null) { // Prüfe ob sichtbar
                event.preventDefault();
                btn.classList.add('active');
                setTimeout(() => btn.classList.remove('active'), 200);
                btn.click();
            }
        }
    }
}
