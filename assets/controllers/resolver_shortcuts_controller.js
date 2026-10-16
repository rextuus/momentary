import { Controller } from '@hotwired/stimulus';

/*
 * Tastaturkürzel für den Identity Resolver.
 * Klickt die vorhandenen Buttons, damit die Live-Component-Logik unverändert bleibt.
 */
export default class extends Controller {
    connect() {
        this.handler = this.onKeydown.bind(this);
        this.lastTrigger = 0;
        window.addEventListener('keydown', this.handler);
    }

    disconnect() {
        window.removeEventListener('keydown', this.handler);
    }

    onKeydown(event) {
        if (event.defaultPrevented || event.isComposing) {
            return;
        }

        const target = event.target;
        const typing = this.isTyping(target);
        const mod = event.ctrlKey || event.metaKey;

        // Speichern: Strg/Cmd + Enter, auch beim Tippen
        if (mod && event.key === 'Enter') {
            event.preventDefault();
            this.click('#btn-process-identification');
            return;
        }

        if (typing) {
            if (event.key === 'Escape') {
                target.blur();
                return;
            }
            // Enter in den Textfeldern der neuen Identität = Speichern
            // (verhindert zugleich das native Formular-Submit)
            if (event.key === 'Enter' && !event.shiftKey && target.matches('input[data-model]')) {
                event.preventDefault();
                this.click('#btn-process-identification');
            }
            return;
        }

        if (mod || event.altKey) {
            return;
        }

        const key = event.key.toLowerCase();

        switch (key) {
            case 'u':
                event.preventDefault();
                this.click('#btn-mark-as-unknown');
                break;
            case 's':
                event.preventDefault();
                this.click('#btn-skip-person');
                break;
            case 'r':
                event.preventDefault();
                this.click('#btn-reassign-person');
                break;
            case 'n': {
                const input = this.element.querySelector('[data-model="newName"]');
                if (input) {
                    event.preventDefault();
                    input.focus();
                }
                break;
            }
            case 'arrowright':
                event.preventDefault();
                this.moveFace(1);
                break;
            case 'arrowleft':
                event.preventDefault();
                this.moveFace(-1);
                break;
            default:
                if (/^[1-9]$/.test(key)) {
                    const suggestions = this.element.querySelectorAll('[data-suggestion]');
                    const button = suggestions[parseInt(key, 10) - 1];
                    if (button) {
                        event.preventDefault();
                        this.safeClick(button);
                    }
                }
        }
    }

    moveFace(direction) {
        const thumbs = Array.from(this.element.querySelectorAll('[data-face-thumb]'));
        if (thumbs.length < 2) {
            return;
        }
        const current = thumbs.findIndex((el) => el.dataset.active === '1');
        const next = thumbs[(current + direction + thumbs.length) % thumbs.length];
        this.safeClick(next);
    }

    click(selector) {
        const el = this.element.querySelector(selector);
        if (el) {
            this.safeClick(el);
        }
    }

    safeClick(el) {
        if (el.disabled) {
            return;
        }

        // Nicht doppelt auslösen, solange ein Request läuft
        const live = this.element.closest('[data-controller~="live"]');
        if (live && live.hasAttribute('busy')) {
            return;
        }

        const now = Date.now();
        if (now - this.lastTrigger < 350) {
            return;
        }
        this.lastTrigger = now;

        el.click();
    }

    isTyping(el) {
        if (!el || !el.closest) {
            return false;
        }
        if (el.closest('.ts-wrapper, [contenteditable="true"]')) {
            return true;
        }
        if (el.matches('textarea, select')) {
            return true;
        }
        if (el.matches('input')) {
            return !['checkbox', 'radio', 'button', 'submit'].includes(el.type);
        }
        return false;
    }
}