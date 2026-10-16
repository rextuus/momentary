import { Controller } from '@hotwired/stimulus';

/*
 * Datengetriebene Tastaturkürzel. Der Controller sitzt auf einem Wrapper;
 * alle Elemente darin steuern ihre Tasten selbst:
 *
 *   data-shortcut="u"                 Taste U klickt das Element
 *   data-shortcut="s space"           mehrere Tasten (Leerzeichen-getrennt)
 *   data-shortcut="ctrl+enter"        mit Modifier (funktioniert auch beim Tippen)
 *   data-shortcut="+ ="               Symboltasten
 *   data-shortcut-focus="n"           Taste N fokussiert das Element
 *   data-shortcut-submit              wird bei Enter in einem input[data-model] geklickt
 *   data-shortcut-immediate           ohne Busy-/Throttle-Schutz (z. B. Zoom-Buttons)
 *
 * Tasten: a-z, 0-9, enter, escape, space, arrowleft/right/up/down, sowie Symbole.
 */
export default class extends Controller {
    connect() {
        this.lastTrigger = 0;
        this.handler = (event) => this.onKeydown(event);
        window.addEventListener('keydown', this.handler);
    }

    disconnect() {
        window.removeEventListener('keydown', this.handler);
    }

    onKeydown(event) {
        if (event.defaultPrevented || event.isComposing || event.repeat) {
            return;
        }

        const main = this.normalizeKey(event);
        if (!main) {
            return;
        }

        const ctrl = event.ctrlKey || event.metaKey;
        const typing = this.isTyping(event.target);

        if (typing) {
            if (main === 'escape') {
                event.target.blur();
                return;
            }
            // Enter in den Textfeldern = Speichern (verhindert auch natives Form-Submit)
            if (main === 'enter' && !ctrl && event.target.matches('input[data-model]')) {
                const submit = this.element.querySelector('[data-shortcut-submit]');
                if (submit) {
                    event.preventDefault();
                    this.trigger(submit);
                }
                return;
            }
            // Beim Tippen nur Strg/Cmd-Kombinationen zulassen
            if (!ctrl) {
                return;
            }
        }

        if (event.altKey) {
            return;
        }

        for (const el of this.element.querySelectorAll('[data-shortcut]')) {
            if (!this.matches(el.getAttribute('data-shortcut'), event, main) || !this.isUsable(el)) {
                continue;
            }
            event.preventDefault();
            this.trigger(el);
            return;
        }

        if (!typing && !ctrl) {
            for (const el of this.element.querySelectorAll('[data-shortcut-focus]')) {
                if (this.matches(el.getAttribute('data-shortcut-focus'), event, main) && this.isUsable(el)) {
                    event.preventDefault();
                    el.focus();
                    return;
                }
            }
        }
    }

    matches(spec, event, main) {
        const ctrl = event.ctrlKey || event.metaKey;

        return spec
            .trim()
            .split(/\s+/)
            .some((token) => {
                const lower = token.toLowerCase();
                const parts = lower === '+' ? ['+'] : lower.split('+');
                const key = parts[parts.length - 1];
                const mods = parts.slice(0, -1);

                if (key !== main) {
                    return false;
                }
                if (mods.includes('ctrl') !== ctrl) {
                    return false;
                }
                // Shift nur bei Buchstaben/Enter/Pfeilen relevant (Symbole/Ziffern variieren je Layout)
                if (/^([a-z]|enter|escape|space|arrow\w+)$/.test(key) && mods.includes('shift') !== event.shiftKey) {
                    return false;
                }

                return true;
            });
    }

    normalizeKey(event) {
        const key = event.key;
        if (!key || ['Shift', 'Control', 'Alt', 'Meta', 'Dead', 'CapsLock'].includes(key)) {
            return null;
        }
        return key === ' ' ? 'space' : key.toLowerCase();
    }

    isUsable(el) {
        return !el.disabled && !el.hasAttribute('hidden') && el.getClientRects().length > 0;
    }

    trigger(el) {
        const immediate =
            el.hasAttribute('data-shortcut-immediate') || el.matches('input[type="checkbox"], input[type="radio"]');

        if (!immediate) {
            const live = this.element.closest('[data-controller~="live"]');
            if (live && live.hasAttribute('busy')) {
                return;
            }
            const now = Date.now();
            if (now - this.lastTrigger < 300) {
                return;
            }
            this.lastTrigger = now;
        }

        // Fokussierte Buttons/Links nicht zusätzlich nativ auslösen (Enter/Space)
        const active = document.activeElement;
        if (active && active !== document.body && active.matches('button, a')) {
            active.blur();
        }

        el.click();
    }

    isTyping(el) {
        if (!el || !el.closest) {
            return false;
        }
        if (el.closest('.ts-wrapper, [contenteditable="true"]') || el.matches('textarea, select')) {
            return true;
        }
        if (el.matches('input')) {
            return !['checkbox', 'radio', 'button', 'submit'].includes(el.type);
        }
        return false;
    }
}