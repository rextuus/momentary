import './bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap/dist/js/bootstrap.bundle.min';
import '@fortawesome/fontawesome-free/css/all.min.css'; // Load CSS
import '@fortawesome/fontawesome-free/js/all.min.js';

/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.scss';
import './bootstrap';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Modal logic person detail
document.addEventListener('DOMContentLoaded', function() {
    const imageModal = document.getElementById('imageModal');
    if (!imageModal) return;

    imageModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget; // Element, das das Modal ausgelöst hat

        const imgSrc = button.getAttribute('data-bs-img');
        const caption = button.getAttribute('data-bs-caption') || 'Vorschau';
        const boxStyles = button.getAttribute('data-bs-box');

        const modalImg = imageModal.querySelector('#modalImage');
        const modalTitle = imageModal.querySelector('#modalCaption');
        const modalBox = imageModal.querySelector('#modalBox');

        modalImg.src = imgSrc;
        modalTitle.textContent = caption;

        if (boxStyles) {
            modalBox.style.cssText = boxStyles;
            modalBox.style.display = 'block';
        } else {
            modalBox.style.display = 'none';
        }
    });
});