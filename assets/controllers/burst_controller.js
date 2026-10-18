import { Controller } from '@hotwired/stimulus';

/*
 * Serienfotos auf- und zuklappen: Der Knopf am besten Foto zeigt die übrigen Fotos der Serie
 * (Elemente mit data-burst-member="<ID des besten Fotos>").
 */
export default class extends Controller {
    toggle(event) {
        event.preventDefault();
        event.stopPropagation();
        const button = event.currentTarget;
        const open = button.getAttribute('aria-expanded') !== 'true';
        this.element
            .querySelectorAll(`[data-burst-member="${event.params.id}"]`)
            .forEach((member) => member.classList.toggle('d-none', !open));
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.classList.toggle('active', open);
    }
}
