import { Controller } from '@hotwired/stimulus';

/*
 * Füllt das Bild-Modal mit den Daten des auslösenden Buttons.
 *
 * Erwartet ein Bootstrap-Modal-Element mit data-controller="image-modal".
 * Der auslösende Button liefert die Daten über data-bs-img, data-bs-caption
 * und data-bs-box.
 */
export default class extends Controller {
    static targets = ['image', 'caption', 'box'];

    connect() {
        this.onShow = this.onShow.bind(this);
        this.element.addEventListener('show.bs.modal', this.onShow);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.onShow);
    }

    onShow(event) {
        const button = event.relatedTarget;
        if (!button) {
            return;
        }

        const imgSrc = button.getAttribute('data-bs-img');
        const caption = button.getAttribute('data-bs-caption') || 'Vorschau';
        const boxStyles = button.getAttribute('data-bs-box');

        if (this.hasImageTarget) {
            this.imageTarget.src = imgSrc;
        }
        if (this.hasCaptionTarget) {
            this.captionTarget.textContent = caption;
        }

        if (this.hasBoxTarget) {
            if (boxStyles) {
                this.boxTarget.style.cssText = boxStyles;
                this.boxTarget.style.display = 'block';
            } else {
                this.boxTarget.style.display = 'none';
            }
        }
    }
}
